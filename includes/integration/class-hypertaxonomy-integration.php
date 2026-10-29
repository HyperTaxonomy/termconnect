<?php
/**
 * HyperTaxonomy integration for TermConnect.
 *
 * Hooks handled here:
 *  - htax_term_uris              — provide term link URIs via HT's abstraction filter
 *  - htax_term_external_posts   — provide related peer-site/feed posts via HT's abstraction filter
 *  - htax_peer_sites            — expose configured peers (WordPress + feeds) to HT's registry
 *  - htax_term_peers REST field  — expose TC peer connections on HT-scored responses
 *  - htax_before_merge_terms     — snapshot external links from source terms before merge
 *  - htax_after_merge_terms      — copy snapshotted links to target term
 *  - deleted_term (WordPress)    — remove deleted term from SV queues; flush link/identity caches
 *  - delete_term_taxonomy (WP)   — remove deleted term from 'terms'/'roots' SV source roots
 *                                  (priority 5, runs before meta deletion so identity is readable);
 *                                  also flushes identity cache for bulk operations where
 *                                  deleted_term does not fire (priority 10)
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TC_HT_Integration {

    /**
     * External link data snapshotted from source terms before they are deleted
     * by an HT merge. Keyed by source term ID.
     *
     * htax_before_merge_terms populates this; htax_after_merge_terms consumes
     * and clears it. WordPress deletes all term meta (including _tc_link_* keys)
     * inside wp_delete_term(), so the snapshot must be taken before the merge
     * transaction runs.
     *
     * @var array<int, array<string, array{id: string, label: string, fetched_at: int}>>
     */
    private static array $link_snapshots = array();

    public static function init(): void {
        add_filter( 'htax_term_uris',             array( __CLASS__, 'provide_term_uris' ),        10, 2 );
        add_filter( 'htax_term_external_posts', array( __CLASS__, 'provide_term_external_posts' ), 10, 3 );
        add_filter( 'htax_peer_sites', array( __CLASS__, 'provide_peer_sites' ) );
        add_action( 'rest_api_init',              array( __CLASS__, 'register_rest_fields' ) );
        add_action( 'htax_relationship_types_admin_footer', array( __CLASS__, 'render_rel_type_discovery' ) );
        add_action( 'wp_ajax_tc_discover_rel_types', array( __CLASS__, 'ajax_discover_rel_types' ) );
        add_filter( 'htax_sync_remote_relationship_types', array( __CLASS__, 'sync_remote_relationship_types' ), 10, 2 );
        add_action( 'htax_before_merge_terms', array( __CLASS__, 'snapshot_links_before_merge' ), 10, 4 );
        add_action( 'htax_after_merge_terms',  array( __CLASS__, 'apply_links_after_merge' ),     10, 4 );
        add_action( 'deleted_term',             array( __CLASS__, 'clean_sv_queues_on_deleted' ),      10, 3 );
        add_action( 'delete_term_taxonomy',     array( __CLASS__, 'clean_sv_source_on_delete' ),        5, 1 );
        add_action( 'delete_term_taxonomy',     array( __CLASS__, 'clean_sv_queues_on_bulk_deleted' ), 10, 1 );
    }

    // --------------------------------------------------------------------------
    // htax_term_uris
    // --------------------------------------------------------------------------

    public static function provide_term_uris( array $uris, int $term_id ): array {
        $cached  = get_transient( 'tc_uris_' . $term_id );
        $tc_uris = is_array( $cached ) ? $cached : (array) get_term_meta( $term_id, '_tc_uris' );
        return array_values( array_unique( array_merge( $uris, $tc_uris ) ) );
    }

    // --------------------------------------------------------------------------
    // htax_term_external_posts
    // --------------------------------------------------------------------------

    /**
     * Provide related posts from configured peer sites and feeds for a term.
     *
     * Runs the full peer-post collection for a single concept term via
     * `TC_Shortcode_PeerPosts::collect_posts_for_terms()` — explicit per-term peer
     * links, shared-identifier and synonym auto-matching, feeds, and (when
     * requested) FOAF — and returns plain normalized post arrays
     * (`tc_external_normalize_post` shape). TermConnect does not know or care which
     * consumer reads them; HyperTaxonomy enforces term visibility before this filter
     * fires.
     *
     * Scope hints come from the opaque `$args` HyperTaxonomy forwards:
     *   - 'platforms'  Peer platform types to include (default: all supported).
     *   - 'foaf_depth' FOAF traversal depth (default 0 = direct peers only).
     *   - 'peers'      Restrict to specific peer link-type slugs (default: all).
     *   - 'per_page'   Fetch-size hint.
     *
     * @since 1.0.0
     * @param array $posts   Posts accumulated so far.
     * @param int   $term_id Term ID.
     * @param array $args    Opaque scope hints from htax_get_term_external_posts().
     * @return array
     */
    public static function provide_term_external_posts( array $posts, int $term_id, array $args = array() ): array {
        if ( ! class_exists( 'TC_Shortcode_PeerPosts' ) ) {
            return $posts;
        }

        $term = get_term( $term_id );
        if ( ! ( $term instanceof WP_Term ) ) {
            return $posts;
        }

        $platforms = $args['platforms'] ?? '';
        if ( is_array( $platforms ) ) {
            $platforms = implode( ',', $platforms );
        }
        $peers = $args['peers'] ?? '';
        if ( is_array( $peers ) ) {
            $peers = implode( ',', $peers );
        }
        $per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );

        $atts = array(
            'taxonomy'         => $term->taxonomy,
            'link_types'       => (string) $peers,
            'platforms'        => (string) $platforms,
            'foaf_depth'       => (string) max( 0, (int) ( $args['foaf_depth'] ?? 0 ) ),
            'match'            => 'any',
            'include_synonyms' => 'true',
            'per_page'         => (string) $per_page,
            'max_results'      => (string) $per_page,
        );

        static $peer = null;
        if ( null === $peer ) {
            $peer = new TC_Shortcode_PeerPosts();
        }

        foreach ( $peer->collect_posts_for_terms( array( $term_id ), $atts ) as $post ) {
            if ( ! empty( $post['url'] ) ) {
                $posts[] = $post;
            }
        }

        return $posts;
    }

    // --------------------------------------------------------------------------
    // htax_peer_sites
    // --------------------------------------------------------------------------

    /**
     * Expose TermConnect's configured peers (WordPress + feed link types) to
     * HyperTaxonomy's read-only peer-site registry.
     *
     * HyperTaxonomy stores nothing; it just aggregates what providers contribute.
     * TermConnect remains the system of record for peer configuration.
     *
     * @since 1.0.0
     * @param array $sites Peer-site descriptors accumulated so far, keyed by slug.
     * @return array
     */
    public static function provide_peer_sites( array $sites ): array {
        if ( ! function_exists( 'tc_get_platform_link_types' ) ) {
            return $sites;
        }

        foreach ( array( 'wordpress', 'feed' ) as $platform ) {
            foreach ( tc_get_platform_link_types( $platform ) as $slug => $type ) {
                $uri    = (string) ( $type['uri_prefix'] ?? '' );
                $origin = ( 'wordpress' === $platform && function_exists( 'tc_external_get_site_origin' ) )
                    ? tc_external_get_site_origin( $uri )
                    : $uri;

                $sites[ $slug ] = array(
                    'slug'     => (string) $slug,
                    'label'    => (string) ( $type['label'] ?? $slug ),
                    'platform' => $platform,
                    'origin'   => $origin,
                );
            }
        }

        return $sites;
    }

    // --------------------------------------------------------------------------
    // htax_term_peers REST field
    // --------------------------------------------------------------------------

    public static function register_rest_fields(): void {
        register_rest_field(
            'post',
            'htax_term_peers',
            array(
                'get_callback'    => array( __CLASS__, 'rest_get_htax_term_peers' ),
                'update_callback' => null,
                'schema'          => array(
                    'description' => 'WordPress peer connections for terms on this post. Keyed by term ID; each value has a "peers" array of {origin, slug} objects and an optional "tc_links" array of {url, platform, label, fetched_at} objects. Present only on HT-scored responses.',
                    'type'        => array( 'object', 'null' ),
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
            )
        );
    }

    public static function rest_get_htax_term_peers( array $post ): ?array {
        global $_htax_rest_post_term_map, $_htax_rest_scoring_active;
        if ( empty( $_htax_rest_scoring_active ) ) {
            return null;
        }

        $term_ids = $_htax_rest_post_term_map[ $post['id'] ] ?? array();
        if ( empty( $term_ids ) ) {
            return null;
        }

        $peer_types = tc_get_platform_link_types( 'wordpress' );
        if ( empty( $peer_types ) ) {
            return null;
        }

        update_meta_cache( 'term', $term_ids );

        $peers = array();
        foreach ( $term_ids as $term_id ) {
            foreach ( $peer_types as $slug => $type ) {
                $raw_meta   = get_term_meta( $term_id, '_tc_link_' . $slug, true );
                $identifier = is_array( $raw_meta ) ? (string) ( $raw_meta['id'] ?? '' ) : (string) $raw_meta;
                if ( '' === $identifier ) {
                    continue;
                }
                $origin = tc_external_get_site_origin( $type['uri_prefix'] );
                if ( '' === $origin ) {
                    continue;
                }
                $key = (string) $term_id;
                if ( ! isset( $peers[ $key ] ) ) {
                    $peers[ $key ] = array( 'peers' => array() );
                    $term_obj      = get_term( $term_id );
                    if ( $term_obj instanceof WP_Term ) {
                        $links = tc_rest_build_links_data( $term_obj );
                        if ( null !== $links ) {
                            $peers[ $key ]['tc_links'] = $links;
                        }
                    }
                }
                $peers[ $key ]['peers'][] = array(
                    'origin' => $origin,
                    'slug'   => $identifier,
                );
            }
        }

        return empty( $peers ) ? null : $peers;
    }


    // --------------------------------------------------------------------------
    // Relationship type discovery (htax_relationship_types_admin_footer)
    // --------------------------------------------------------------------------

    /**
     * Render a "Discover from peer site" section below the relationship type tables.
     * Only shown when TC has at least one WordPress peer configured.
     *
     * @since 1.0.0
     */
    public static function render_rel_type_discovery(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $peer_sites = array_filter(
            tc_get_enabled_link_types(),
            static fn( $peer_site ) => 'wordpress' === ( $peer_site['platform'] ?? '' )
        );

        if ( empty( $peer_sites ) ) {
            return;
        }

        $nonce = wp_create_nonce( 'tc_discover_rel_types' );
        ?>
        <hr style="margin:30px 0">
        <h2><?php esc_html_e( 'Discover Relationship Types from Peer', 'termconnect' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Fetch storage-only custom relationship type definitions from a peer site running HyperTaxonomy and import them as delegated types.', 'termconnect' ); ?></p>
        <div style="display:flex;gap:8px;align-items:center;margin-top:8px">
            <select id="tc-rel-discovery-peer" style="min-width:220px">
                <option value="">&mdash; <?php esc_html_e( 'Select peer site', 'termconnect' ); ?> &mdash;</option>
                <?php foreach ( $peer_sites as $peer_site_slug => $peer_site ) : ?>
                <option value="<?php echo esc_attr( $peer_site_slug ); ?>"><?php echo esc_html( $peer_site['label'] ); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="button" id="tc-rel-discovery-btn" disabled>
                <?php esc_html_e( 'Discover types', 'termconnect' ); ?>
            </button>
            <span id="tc-rel-discovery-status" class="description"></span>
        </div>
        <div id="tc-rel-discovery-results" style="display:none;margin-top:12px"></div>
        <script>
        jQuery(function($){
            var nonce = <?php echo wp_json_encode( $nonce ); ?>;
            $('#tc-rel-discovery-peer').on('change', function(){
                $('#tc-rel-discovery-btn').prop('disabled', !$(this).val());
            });
            $('#tc-rel-discovery-btn').on('click', function(){
                var peerSiteSlug = $('#tc-rel-discovery-peer').val();
                if (!peerSiteSlug) return;
                var $btn = $(this).prop('disabled', true);
                var $st  = $('#tc-rel-discovery-status').text(<?php echo wp_json_encode( __( 'Discovering…', 'termconnect' ) ); ?>);
                $.post(ajaxurl, { action: 'tc_discover_rel_types', nonce: nonce, peer_site_slug: peerSiteSlug })
                 .done(function(resp){
                    if (resp.success) {
                        var d = resp.data;
                        if (!d.created && !d.synced && !(d.errors && d.errors.length)) {
                            $st.text('✓ <?php echo esc_js( __( 'Peer site has HyperTaxonomy but no custom relationship types.', 'termconnect' ) ); ?>');
                        } else {
                            $st.text('✓ ' + d.created + ' <?php echo esc_js( __( 'created', 'termconnect' ) ); ?>, ' + d.synced + ' <?php echo esc_js( __( 'updated', 'termconnect' ) ); ?>');
                            if (d.errors && d.errors.length) { $st.append(' — ' + d.errors[0]); }
                        }
                        if (d.created || d.synced) { location.reload(); }
                    } else {
                        $st.text((resp.data && resp.data.message) || <?php echo wp_json_encode( __( 'Error.', 'termconnect' ) ); ?>);
                    }
                 })
                 .fail(function(){ $st.text(<?php echo wp_json_encode( __( 'Request failed.', 'termconnect' ) ); ?>); })
                 .always(function(){ $btn.prop('disabled', false); });
            });
        });
        </script>
        <?php
    }

    // --------------------------------------------------------------------------
    // htax_sync_remote_relationship_types filter
    // --------------------------------------------------------------------------

    /**
     * Handle the htax_sync_remote_relationship_types filter fired from the HT admin
     * when the Sync action is clicked on a remote relationship type row.
     *
     * @since 1.0.0
     * @param array  $result      Default result array { success: false, message: string }.
     * @param string $source_site Peer site base URL.
     * @return array { success: bool, message: string, synced?: int, created?: int }
     */
    public static function sync_remote_relationship_types( array $result, string $source_site ): array {
        $sync = tc_sv_sync_relationship_types( $source_site );
        if ( ! empty( $sync['errors'] ) ) {
            return array(
                'success' => false,
                'message' => implode( ' ', $sync['errors'] ),
            );
        }
        return array(
            'success' => true,
            'message' => sprintf(
                /* translators: 1: created count, 2: updated count */
                __( 'Sync complete: %1$d created, %2$d updated.', 'termconnect' ),
                (int) $sync['created'],
                (int) $sync['synced']
            ),
            'created' => $sync['created'],
            'synced'  => $sync['synced'],
        );
    }

    /**
     * AJAX handler: discover and import relationship types from a peer site.
     *
     * @since 1.0.0
     */
    public static function ajax_discover_rel_types(): void {
        check_ajax_referer( 'tc_discover_rel_types', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
        }

        $peer_site_slug   = sanitize_key( wp_unslash( $_POST['peer_site_slug'] ?? '' ) );
        $link_type = $peer_site_slug ? tc_get_link_type( $peer_site_slug ) : null;
        $origin    = $link_type ? tc_external_get_site_origin( $link_type['uri_prefix'] ?? '' ) : '';

        if ( ! $origin ) {
            wp_send_json_error( array( 'message' => __( 'Invalid peer site.', 'termconnect' ) ) );
        }

        $result = tc_sv_sync_relationship_types( $origin );
        if ( ! empty( $result['errors'] ) && 0 === $result['created'] && 0 === $result['synced'] ) {
            wp_send_json_error( array( 'message' => $result['errors'][0] ) );
        }
        wp_send_json_success( $result );
    }

    // --------------------------------------------------------------------------
    // Term lifecycle — merge
    // --------------------------------------------------------------------------

    /**
     * Snapshot external links from each source term before the merge transaction runs.
     *
     * htax_before_merge_terms fires before HT's START TRANSACTION. WordPress will
     * delete all term meta (including _tc_link_* keys) inside wp_delete_term(),
     * which runs during the transaction, so the snapshot must happen here.
     *
     * Delegated identity meta (_tc_identity_source, _tc_delegated_links_data, etc.)
     * is intentionally NOT copied: if the source had delegated identity, that
     * identity is not transferred to the target term.
     *
     * @since 1.0.0
     * @param int[]  $source_ids      Source term IDs being merged.
     * @param int    $target_id       Target term ID (unused here).
     * @param string $taxonomy        Taxonomy slug (unused here).
     * @param bool   $create_redirect Whether redirect stubs will be created (unused here).
     */
    public static function snapshot_links_before_merge( array $source_ids, int $target_id, string $taxonomy, bool $create_redirect ): void {
        foreach ( $source_ids as $source_id ) {
            self::$link_snapshots[ (int) $source_id ] = tc_get_term_links_data( (int) $source_id );
        }
    }

    /**
     * Copy external links from source terms to the merge target.
     *
     * htax_after_merge_terms fires after HT's COMMIT. Term meta for source terms
     * has been deleted by WordPress at this point; we use the snapshot taken in
     * snapshot_links_before_merge() to recover the link data.
     *
     * For each link on a source term: if the target already has that link type
     * (same slug), the target's value is kept unchanged. If the target does not
     * have it, the source value is written to the target via tc_set_term_link().
     *
     * SV queue cleanup is handled separately: deleted_term fires for each source
     * term during the merge, which triggers clean_sv_queues_on_deleted() naturally.
     *
     * @since 1.0.0
     * @param int[]  $source_ids Source term IDs that were merged.
     * @param int    $target_id  Target term ID.
     * @param string $taxonomy   Taxonomy slug (unused here).
     * @param array  $result     HT merge result data (unused here).
     */
    public static function apply_links_after_merge( array $source_ids, int $target_id, string $taxonomy, array $result ): void {
        $target_links = tc_get_term_links_data( $target_id );

        foreach ( $source_ids as $source_id ) {
            $source_id    = (int) $source_id;
            $source_links = self::$link_snapshots[ $source_id ] ?? array();

            foreach ( $source_links as $slug => $link ) {
                if ( isset( $target_links[ $slug ] ) ) {
                    continue; // Target already has this link type; keep the target value.
                }
                if ( tc_set_term_link( $target_id, $slug, $link['id'], $link['label'], $link['fetched_at'] ) ) {
                    $target_links[ $slug ] = $link; // Prevent double-copy across multiple sources.
                }
            }

            unset( self::$link_snapshots[ $source_id ] );
        }
    }

    // --------------------------------------------------------------------------
    // Term lifecycle — delete
    // --------------------------------------------------------------------------

    /**
     * Clean up TC state when a term is permanently deleted.
     *
     * WordPress deletes term meta automatically, so _tc_link_* and delegated
     * identity meta need no explicit cleanup here. The tc_links_{term_id}
     * transient and tc_delegated_term_ids transient are NOT term meta and must
     * be flushed manually. SV option queues (tc_merge_proposals,
     * tc_sv_misplaced_queue) are also NOT term meta.
     *
     * tc_shared_vocabularies 'terms'/'roots' cleanup is handled by
     * clean_sv_source_on_delete() on delete_term_taxonomy (priority 5), which
     * runs before term meta is deleted while identity source is still readable.
     *
     * @since 1.0.0
     * @param int    $term_id  Deleted term ID.
     * @param int    $tt_id    Term taxonomy ID (unused here).
     * @param string $taxonomy Taxonomy slug (unused here).
     */
    public static function clean_sv_queues_on_deleted( int $term_id, int $tt_id, string $taxonomy ): void {
        tc_flush_term_link_cache( $term_id );
        tc_flush_delegated_term_ids();
        delete_transient( 'tc_stale_rel_term_ids' );
        self::prune_sv_queues( array( $term_id ) );
    }

    /**
     * Flush caches and SV queues for bulk taxonomy operations where deleted_term
     * does not fire.
     *
     * delete_term_taxonomy fires before the term_taxonomy row is removed, so the
     * term still exists and can be resolved from $tt_id. Runs at priority 10,
     * after clean_sv_source_on_delete (priority 5) which needs term meta.
     *
     * @since 1.0.0
     * @param int $tt_id Term taxonomy ID.
     */
    public static function clean_sv_queues_on_bulk_deleted( int $tt_id ): void {
        $term = get_term_by( 'term_taxonomy_id', $tt_id );
        if ( $term instanceof WP_Term ) {
            tc_flush_term_link_cache( $term->term_id );
            tc_flush_delegated_term_ids();
            delete_transient( 'tc_stale_rel_term_ids' );
            self::prune_sv_queues( array( $term->term_id ) );
        }
    }

    /**
     * Remove a deleted term from 'terms' or 'roots' scope Shared Vocabulary sources.
     *
     * Hooked on delete_term_taxonomy at priority 5, which fires inside wp_delete_term()
     * before term meta is deleted — so TC_META_IDENTITY_SOURCE is still readable here.
     * This hook also fires for bulk taxonomy operations, so it covers both paths.
     *
     * For 'terms' scope: each roots entry represents one specific remote term to mirror
     * locally. Removing it prevents the next sync from recreating the deleted mirror.
     * An empty 'terms' source is deleted entirely.
     *
     * For 'roots' scope: removing a root entry stops future hierarchy syncs for that
     * subtree. Remaining local descendants still carry identity meta; the next sync's
     * scope-exit logic will clear their sv_source_id.
     *
     * 'full', 'local_group', and 'remote_group' scopes are dynamic — membership is
     * computed at sync time from the remote source, so no roots entries are stored
     * and this method is a no-op for them.
     *
     * @since 1.0.0
     * @param int $tt_id Term taxonomy ID being deleted.
     */
    public static function clean_sv_source_on_delete( int $tt_id ): void {
        $term = get_term_by( 'term_taxonomy_id', $tt_id );
        if ( ! ( $term instanceof WP_Term ) ) {
            return;
        }

        $identity = tc_get_identity_source( $term->term_id );
        if ( null === $identity ) {
            return;
        }

        $sv_source_id = $identity['sv_source_id'] ?? '';
        $remote_slug  = $identity['remote_slug']  ?? '';
        if ( '' === $sv_source_id || '' === $remote_slug ) {
            return;
        }

        $sources = get_option( 'tc_shared_vocabularies', array() );
        $changed  = false;

        foreach ( $sources as &$src ) {
            if ( ( $src['id'] ?? '' ) !== $sv_source_id ) {
                continue;
            }
            $scope = $src['scope'] ?? '';
            if ( ! in_array( $scope, array( 'terms', 'roots' ), true ) ) {
                break;
            }
            $before       = count( $src['roots'] ?? array() );
            $src['roots'] = array_values( array_filter(
                $src['roots'] ?? array(),
                static fn( $r ) => ( $r['remote_slug'] ?? '' ) !== $remote_slug
            ) );
            if ( count( $src['roots'] ) !== $before ) {
                $changed = true;
            }
            break;
        }
        unset( $src );

        if ( ! $changed ) {
            return;
        }

        // A 'terms' source with no remaining roots has no purpose.
        $sources = array_values( array_filter(
            $sources,
            static fn( $s ) => ! ( ( $s['scope'] ?? '' ) === 'terms' && empty( $s['roots'] ) )
        ) );
        update_option( 'tc_shared_vocabularies', $sources );
    }

    /**
     * Remove the given term IDs from tc_merge_proposals and tc_sv_misplaced_queue.
     *
     * tc_merge_proposals: each entry has orphan_term_id and candidate_term_id (both
     * local term IDs). The proposal is removed if either term is in the pruned set.
     *
     * tc_sv_misplaced_queue: each entry has term_id (a local term ID). Entries whose
     * term_id is in the pruned set are removed.
     *
     * tc_shared_vocabularies 'terms'/'roots' roots cleanup is handled separately by
     * clean_sv_source_on_delete() before term meta is deleted.
     *
     * @since 1.0.0
     * @param int[] $term_ids Local term IDs to remove from the queues.
     */
    private static function prune_sv_queues( array $term_ids ): void {
        if ( empty( $term_ids ) ) {
            return;
        }

        $id_set = array_flip( $term_ids );

        $proposals = get_option( 'tc_merge_proposals', array() );
        if ( ! empty( $proposals ) ) {
            $filtered = array_filter(
                $proposals,
                static function( $p ) use ( $id_set ): bool {
                    return ! isset( $id_set[ (int) ( $p['orphan_term_id']    ?? 0 ) ] )
                        && ! isset( $id_set[ (int) ( $p['candidate_term_id'] ?? 0 ) ] );
                }
            );
            if ( count( $filtered ) !== count( $proposals ) ) {
                update_option( 'tc_merge_proposals', $filtered, false );
            }
        }

        $misplaced = get_option( 'tc_sv_misplaced_queue', array() );
        if ( ! empty( $misplaced ) ) {
            $filtered = array_filter(
                $misplaced,
                static function( $entry ) use ( $id_set ): bool {
                    return ! isset( $id_set[ (int) ( $entry['term_id'] ?? 0 ) ] );
                }
            );
            if ( count( $filtered ) !== count( $misplaced ) ) {
                update_option( 'tc_sv_misplaced_queue', $filtered, false );
            }
        }
    }
}
