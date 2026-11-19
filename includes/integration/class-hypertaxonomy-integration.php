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
 *  - htax_after_merge_terms      — put snapshotted links on the stubs and/or the target, as chosen
 *  - deleted_term (WordPress)    — remove deleted term from SV queues; flush link/identity caches
 *  - pre_delete_term (WordPress) — remove deleted term from 'terms'/'roots' SV source roots
 *                                  (runs before meta deletion, so identity is readable)
 *  - delete_term_taxonomy (WP)   — flushes identity cache for bulk operations where
 *                                  deleted_term does not fire (priority 10)
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TConn_HT_Integration {

    /**
     * External link data snapshotted from source terms before they are deleted
     * by an HT merge. Keyed by source term ID.
     *
     * htax_before_merge_terms populates this; htax_after_merge_terms consumes
     * and clears it. WordPress deletes all term meta (including _tconn_link_* keys)
     * inside wp_delete_term(), so the snapshot must be taken before the merge
     * transaction runs.
     *
     * @var array<int, array<string, array{id: string, label: string, fetched_at: int}>>
     */
    private static array $link_snapshots = array();

    /**
     * The merge's options (for one started outside the Merge Terms page, only what its caller
     * passed, such as HyperTaxonomy's operation-wide preference).
     *
     * @var array
     */
    private static array $merge_options = array();

    /**
     * Hook TermConnect into HyperTaxonomy's filters, actions and admin screens.
     *
     * @since 1.0.0
     */
    public static function init(): void {
        add_filter( 'htax_term_uris',             array( __CLASS__, 'provide_term_uris' ),        10, 2 );
        add_filter( 'htax_term_external_posts', array( __CLASS__, 'provide_term_external_posts' ), 10, 3 );
        add_filter( 'htax_peer_sites', array( __CLASS__, 'provide_peer_sites' ) );
        add_action( 'rest_api_init',              array( __CLASS__, 'register_rest_fields' ) );
        add_action( 'htax_relationship_types_admin_footer', array( __CLASS__, 'render_rel_type_discovery' ) );
        add_action( 'wp_ajax_tconn_discover_rel_types', array( __CLASS__, 'ajax_discover_rel_types' ) );
        add_filter( 'htax_sync_remote_relationship_types', array( __CLASS__, 'sync_remote_relationship_types' ), 10, 2 );
        add_action( 'htax_before_merge_terms', array( __CLASS__, 'snapshot_links_before_merge' ), 10, 5 );
        add_action( 'htax_after_merge_terms',  array( __CLASS__, 'apply_links_after_merge' ),     10, 4 );
        add_action( 'deleted_term',             array( __CLASS__, 'clean_sv_queues_on_deleted' ),      10, 3 );
        add_action( 'pre_delete_term',          array( __CLASS__, 'clean_sv_source_on_delete' ),       10, 2 );
        add_action( 'delete_term_taxonomy',     array( __CLASS__, 'clean_sv_queues_on_bulk_deleted' ), 10, 1 );
    }

    // --------------------------------------------------------------------------
    // htax_term_uris
    // --------------------------------------------------------------------------

    /**
     * Add a term's TermConnect identifier URIs to HyperTaxonomy's htax_term_uris filter.
     *
     * @since 1.0.0
     */
    public static function provide_term_uris( array $uris, int $term_id ): array {
        $cached  = get_transient( 'tconn_uris_' . $term_id );
        $tconn_uris = is_array( $cached ) ? $cached : (array) get_term_meta( $term_id, '_tconn_uris' );
        return array_values( array_unique( array_merge( $uris, $tconn_uris ) ) );
    }

    // --------------------------------------------------------------------------
    // htax_term_external_posts
    // --------------------------------------------------------------------------

    /**
     * Provide related posts from configured peer sites and feeds for a term.
     *
     * Runs the full peer-post collection for a single concept term via
     * `TConn_Shortcode_PeerPosts::collect_posts_for_terms()` — explicit per-term peer
     * links, shared-identifier and synonym auto-matching, feeds, and (when
     * requested) FOAF — and returns plain normalized post arrays
     * (`tconn_external_normalize_post` shape). TermConnect does not know or care which
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
        if ( ! class_exists( 'TConn_Shortcode_PeerPosts' ) ) {
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
            $peer = new TConn_Shortcode_PeerPosts();
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
        if ( ! function_exists( 'tconn_get_platform_link_types' ) ) {
            return $sites;
        }

        foreach ( array( 'wordpress', 'feed' ) as $platform ) {
            foreach ( tconn_get_platform_link_types( $platform ) as $slug => $type ) {
                $uri    = (string) ( $type['uri_prefix'] ?? '' );
                $origin = ( 'wordpress' === $platform && function_exists( 'tconn_external_get_site_origin' ) )
                    ? tconn_external_get_site_origin( $uri )
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

    /**
     * Register the htax_term_peers REST field on posts.
     *
     * @since 1.0.0
     */
    public static function register_rest_fields(): void {
        register_rest_field(
            'post',
            'htax_term_peers',
            array(
                'get_callback'    => array( __CLASS__, 'rest_get_htax_term_peers' ),
                'update_callback' => null,
                'schema'          => array(
                    'description' => 'WordPress peer connections for terms on this post. Keyed by term ID; each value has a "peers" array of {origin, slug} objects and an optional "tconn_links" array of {url, platform, label, fetched_at} objects. Present only on HT-scored responses.',
                    'type'        => array( 'object', 'null' ),
                    'context'     => array( 'view' ),
                    'readonly'    => true,
                ),
            )
        );
    }

    /**
     * REST field callback: peer site connections for a post's terms, in HyperTaxonomy's scored post responses.
     *
     * @since 1.0.0
     */
    public static function rest_get_htax_term_peers( array $post ): ?array {
        global $htax_rest_post_term_map, $htax_rest_scoring_active;
        if ( empty( $htax_rest_scoring_active ) ) {
            return null;
        }

        $term_ids = $htax_rest_post_term_map[ $post['id'] ] ?? array();
        if ( empty( $term_ids ) ) {
            return null;
        }

        $peer_types = tconn_get_platform_link_types( 'wordpress' );
        if ( empty( $peer_types ) ) {
            return null;
        }

        update_meta_cache( 'term', $term_ids );

        $peers = array();
        foreach ( $term_ids as $term_id ) {
            foreach ( $peer_types as $slug => $type ) {
                $identifier = tconn_get_term_link_id( (int) $term_id, $slug );
                if ( '' === $identifier ) {
                    continue;
                }
                $origin = tconn_external_get_site_origin( $type['uri_prefix'] );
                if ( '' === $origin ) {
                    continue;
                }
                $key = (string) $term_id;
                if ( ! isset( $peers[ $key ] ) ) {
                    $peers[ $key ] = array( 'peers' => array() );
                    $term_obj      = get_term( $term_id );
                    if ( $term_obj instanceof WP_Term ) {
                        $links = tconn_rest_build_links_data( $term_obj );
                        if ( null !== $links ) {
                            $peers[ $key ]['tconn_links'] = $links;
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
            tconn_get_enabled_link_types(),
            static fn( $peer_site ) => 'wordpress' === ( $peer_site['platform'] ?? '' )
        );

        if ( empty( $peer_sites ) ) {
            return;
        }

        $nonce = wp_create_nonce( 'tconn_discover_rel_types' );
        ?>
        <hr style="margin:30px 0">
        <h2><?php esc_html_e( 'Discover Relationship Types from Peer', 'termconnect' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Fetch storage-only custom relationship type definitions from a peer site running HyperTaxonomy and import them as delegated types.', 'termconnect' ); ?></p>
        <div style="display:flex;gap:8px;align-items:center;margin-top:8px">
            <select id="tconn-rel-discovery-peer" style="min-width:220px">
                <option value="">&mdash; <?php esc_html_e( 'Select peer site', 'termconnect' ); ?> &mdash;</option>
                <?php foreach ( $peer_sites as $peer_site_slug => $peer_site ) : ?>
                <option value="<?php echo esc_attr( $peer_site_slug ); ?>"><?php echo esc_html( $peer_site['label'] ); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="button" id="tconn-rel-discovery-btn" disabled>
                <?php esc_html_e( 'Discover types', 'termconnect' ); ?>
            </button>
            <span id="tconn-rel-discovery-status" class="description"></span>
        </div>
        <div id="tconn-rel-discovery-results" style="display:none;margin-top:12px"></div>
        <?php ob_start(); ?>
        jQuery(function($){
            var nonce = <?php echo wp_json_encode( $nonce ); ?>;
            $('#tconn-rel-discovery-peer').on('change', function(){
                $('#tconn-rel-discovery-btn').prop('disabled', !$(this).val());
            });
            $('#tconn-rel-discovery-btn').on('click', function(){
                var peerSiteSlug = $('#tconn-rel-discovery-peer').val();
                if (!peerSiteSlug) return;
                var $btn = $(this).prop('disabled', true);
                var $st  = $('#tconn-rel-discovery-status').text(<?php echo wp_json_encode( __( 'Discovering…', 'termconnect' ) ); ?>);
                $.post(ajaxurl, { action: 'tconn_discover_rel_types', nonce: nonce, peer_site_slug: peerSiteSlug })
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
        <?php tconn_add_inline_script( 'rel-type-discovery', ob_get_clean() ); ?>
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
        $sync = tconn_sv_sync_relationship_types( $source_site );
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
        check_ajax_referer( 'tconn_discover_rel_types', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
        }

        $peer_site_slug   = sanitize_key( wp_unslash( $_POST['peer_site_slug'] ?? '' ) );
        $origin           = tconn_peer_origin( $peer_site_slug );

        if ( ! $origin ) {
            wp_send_json_error( array( 'message' => __( 'Invalid peer site.', 'termconnect' ) ) );
        }

        $result = tconn_sv_sync_relationship_types( $origin );
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
     * delete all term meta (including _tconn_link_* keys) inside wp_delete_term(),
     * which runs during the transaction, so the snapshot must happen here.
     *
     * Delegated identity meta (_tconn_identity_source, _tconn_delegated_links_data, etc.)
     * is handled by TConn_Merge_Identity: kept on the source's redirect stub, moved to
     * the target, or dropped, as chosen on the Merge Terms page.
     *
     * @since 1.0.0
     * @param int[]  $source_ids      Source term IDs being merged.
     * @param int    $target_id       Target term ID (unused here).
     * @param string $taxonomy        Taxonomy slug (unused here).
     * @param bool   $create_redirect Whether redirect stubs will be created (unused here).
     * @param array  $options         Merge options; the links choice is under
     *                                TConn_Operation_Identity::SECTION, 'links' (see
     *                                htax_carry_choice()).
     */
    public static function snapshot_links_before_merge( array $source_ids, int $target_id, string $taxonomy, bool $create_redirect, array $options = array() ): void {
        self::$link_snapshots = array();
        self::$merge_options  = $options;
        foreach ( $source_ids as $source_id ) {
            self::$link_snapshots[ (int) $source_id ] = tconn_get_term_links_data( (int) $source_id );
        }
    }

    /**
     * Put the merged terms' external links where the merge options say: on their redirect
     * stubs (the default with stubs), moved or copied to the target (moving is the default
     * without stubs), or nowhere.
     *
     * htax_after_merge_terms fires after HT's COMMIT. Term meta for source terms has been
     * deleted by WordPress at this point; we use the snapshot taken in
     * snapshot_links_before_merge() to recover the link data.
     *
     * The target only gets link types it doesn't have (the first source's link wins when
     * several have one). Moving leaves on the stub, when there is one, the links the target
     * couldn't take because it has another link of their type.
     *
     * SV queue cleanup is handled separately: deleted_term fires for each source
     * term during the merge, which triggers clean_sv_queues_on_deleted() naturally.
     *
     * @since 1.0.0
     * @param int[]  $source_ids Source term IDs that were merged.
     * @param int    $target_id  Target term ID.
     * @param string $taxonomy   Taxonomy slug (unused here).
     * @param array  $result     HT merge result, with redirect_stubs.
     */
    public static function apply_links_after_merge( array $source_ids, int $target_id, string $taxonomy, array $result ): void {
        $stubs = (array) ( $result['redirect_stubs'] ?? array() );
        foreach ( $source_ids as $source_id ) {
            $source_id = (int) $source_id;
            $stub_id   = (int) ( $stubs[ $source_id ] ?? 0 );
            $choice    = htax_carry_choice( self::$merge_options, TConn_Operation_Identity::SECTION, 'links', $stub_id > 0 );
            self::carry_links( self::$link_snapshots[ $source_id ] ?? array(), $stub_id, false, $target_id, $choice, null );
            unset( self::$link_snapshots[ $source_id ] );
        }
        self::$merge_options = array();
    }

    /**
     * Put a term's external links on its stub and/or another term.
     *
     * @since 1.0.0
     * @param array    $links          The term's links, by link type.
     * @param int      $stub_id        Redirect stub term ID, or 0 for none.
     * @param bool     $stub_has_links Whether the stub already has the links (it is the old
     *                                 term, as in a conversion) or gets them only from here.
     * @param int      $to_id          The term that can take them (merge target, new term).
     * @param string   $choice         'stub', 'move', 'copy' or 'drop', as htax_carry_choice()
     *                                 resolves it for this term.
     * @param array|null $enabled      Link types the receiving term's taxonomy allows, or null
     *                                 for no limit.
     */
    public static function carry_links( array $links, int $stub_id, bool $stub_has_links, int $to_id, string $choice, ?array $enabled ): void {
        if ( ! $links ) {
            return;
        }

        // Links the receiving term now has: moved, or the same link it already had.
        $moved = array();
        if ( in_array( $choice, array( 'move', 'copy' ), true ) ) {
            $current = tconn_get_term_links_data( $to_id );
            foreach ( $links as $slug => $link ) {
                if ( null !== $enabled && ! isset( $enabled[ $slug ] ) ) {
                    continue;
                }
                if ( isset( $current[ $slug ] ) ) {
                    if ( (string) $current[ $slug ]['id'] === (string) $link['id'] ) {
                        $moved[ $slug ] = true;
                    }
                } elseif ( tconn_set_term_link( $to_id, $slug, $link['id'], $link['label'], $link['fetched_at'] ) ) {
                    $moved[ $slug ] = true;
                }
            }
        }
        if ( ! $stub_id ) {
            return;
        }

        foreach ( $links as $slug => $link ) {
            $keep = 'stub' === $choice || 'copy' === $choice || ( 'move' === $choice && ! isset( $moved[ $slug ] ) );
            if ( $stub_has_links && ! $keep ) {
                tconn_remove_term_link( $stub_id, $slug );
            } elseif ( ! $stub_has_links && $keep ) {
                tconn_set_term_link( $stub_id, $slug, $link['id'], $link['label'], $link['fetched_at'] );
            }
        }
    }

    /**
     * The check step's choice for the external links of terms being merged or converted
     * (htax_carry_field()).
     *
     * A link type the receiving term already has with the same link isn't moved or kept on a
     * stub; with another link, or not allowed in the new taxonomy, the merged or converted
     * term's link stays on its stub (or is deleted without one).
     *
     * @since 1.0.0
     * @param array      $links_by_term Each term's links, by term ID.
     * @param array      $to_links      Links the receiving term has (empty for new terms).
     * @param string     $to_name       Merge target name, or '' for a conversion's new terms.
     * @param array|null $enabled       Link types the new taxonomy allows (conversion), or null.
     * @param string     $to_taxonomy   New taxonomy's name (conversion).
     * @return array|null Field, or null when the terms have no links.
     */
    public static function links_field( array $links_by_term, array $to_links, string $to_name, ?array $enabled = null, string $to_taxonomy = '' ): ?array {
        $all_types = tconn_get_all_link_types();
        $labels    = array();
        $movable   = array();
        $kept      = array();
        $planned   = $to_links; // The receiving term's links, with those moved to it so far.
        foreach ( $links_by_term as $links ) {
            foreach ( (array) $links as $slug => $link ) {
                $label           = (string) ( $all_types[ $slug ]['label'] ?? $slug );
                $labels[ $slug ] = $label;
                if ( null !== $enabled && ! isset( $enabled[ $slug ] ) ) {
                    $kept[ $slug ] = $label;
                } elseif ( ! isset( $planned[ $slug ] ) ) {
                    $movable[ $slug ] = $label;
                    $planned[ $slug ] = $link;
                } elseif ( (string) ( $planned[ $slug ]['id'] ?? '' ) !== (string) ( $link['id'] ?? '' ) ) {
                    $kept[ $slug ] = $label;
                }
            }
        }
        if ( ! $labels ) {
            return null;
        }

        $merge = '' !== $to_name;
        $gets  = '';
        $left  = '';
        if ( $movable ) {
            $gets = $merge
                /* translators: 1: target term name, 2: comma-separated link type names */
                ? sprintf( __( '%1$s gets the link types it doesn\'t have: %2$s.', 'termconnect' ), $to_name, implode( ', ', $movable ) )
                /* translators: %s: comma-separated link type names */
                : sprintf( __( 'The new terms get these link types: %s.', 'termconnect' ), implode( ', ', $movable ) );
        }
        if ( $movable && $kept ) {
            $left = ' ' . ( $merge
                /* translators: 1: target term name, 2: comma-separated link type names */
                ? sprintf( __( '%1$s keeps its own %2$s links; the merged terms\' stay on the redirect stubs, or are deleted without stubs.', 'termconnect' ), $to_name, implode( ', ', $kept ) )
                /* translators: 1: taxonomy name, 2: comma-separated link type names */
                : sprintf( __( 'Link types that %1$s doesn\'t allow (%2$s) stay on the redirect stubs, or are deleted without stubs.', 'termconnect' ), $to_taxonomy, implode( ', ', $kept ) ) );
        }

        return htax_carry_field( array(
            'name'         => 'links',
            /* translators: %s: comma-separated link type names */
            'label'        => sprintf( __( 'External links: %s', 'termconnect' ), implode( ', ', $labels ) ),
            'target_name'  => $to_name,
            'movable'      => (bool) $movable,
            'descriptions' => array(
                'stub' => __( 'Each stub keeps the links of the term it replaces.', 'termconnect' ),
                'move' => $gets . $left,
                'copy' => $gets,
            ),
        ) );
    }

    // --------------------------------------------------------------------------
    // Term lifecycle — delete
    // --------------------------------------------------------------------------

    /**
     * Clean up TC state when a term is permanently deleted.
     *
     * WordPress deletes term meta automatically, so _tconn_link_* and delegated
     * identity meta need no explicit cleanup here, and the deletion flushes the
     * cached delegated and stale term ID sets (tconn_flush_term_ids_on_meta_change()).
     * The tconn_links_{term_id} transient is NOT term meta and must be flushed
     * manually. SV option queues (tconn_merge_proposals, tconn_sv_misplaced_queue) are
     * also NOT term meta.
     *
     * tconn_shared_vocabularies 'terms'/'roots' cleanup is handled by
     * clean_sv_source_on_delete() on pre_delete_term, which runs before term meta is
     * deleted while identity source is still readable.
     *
     * @since 1.0.0
     * @param int    $term_id  Deleted term ID.
     * @param int    $tt_id    Term taxonomy ID (unused here).
     * @param string $taxonomy Taxonomy slug (unused here).
     */
    public static function clean_sv_queues_on_deleted( int $term_id, int $tt_id, string $taxonomy ): void {
        tconn_flush_term_link_cache( $term_id );
        self::prune_sv_queues( array( $term_id ) );
    }

    /**
     * Flush caches and SV queues for bulk taxonomy operations where deleted_term
     * does not fire.
     *
     * delete_term_taxonomy fires before the term_taxonomy row is removed, so the
     * term still exists and can be resolved from $tt_id. Its meta is already gone,
     * which is why clean_sv_source_on_delete() runs earlier, on pre_delete_term.
     *
     * @since 1.0.0
     * @param int $tt_id Term taxonomy ID.
     */
    public static function clean_sv_queues_on_bulk_deleted( int $tt_id ): void {
        $term = get_term_by( 'term_taxonomy_id', $tt_id );
        if ( $term instanceof WP_Term ) {
            tconn_flush_term_link_cache( $term->term_id );
            self::prune_sv_queues( array( $term->term_id ) );
        }
    }

    /**
     * Remove a deleted term from 'terms' or 'roots' scope Shared Vocabulary sources.
     *
     * Hooked on pre_delete_term, the one action wp_delete_term() fires before it deletes
     * the term's meta, so TCONN_META_IDENTITY_SOURCE is still readable here
     * (delete_term_taxonomy fires after the meta is gone).
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
     * @param int    $term_id  Term ID being deleted.
     * @param string $taxonomy Taxonomy.
     */
    public static function clean_sv_source_on_delete( $term_id, $taxonomy ): void {
        $term = get_term( (int) $term_id, (string) $taxonomy );
        if ( ! ( $term instanceof WP_Term ) ) {
            return;
        }

        // A term merged away whose identity moves to its redirect stub or the target stays
        // in its vocabulary.
        if ( TConn_Merge_Identity::is_kept( (int) $term->term_id ) ) {
            return;
        }

        $identity = tconn_get_identity_source( $term->term_id );
        if ( null === $identity ) {
            return;
        }

        tconn_sv_remove_root( (string) ( $identity['sv_source_id'] ?? '' ), (string) ( $identity['remote_slug'] ?? '' ) );
    }

    /**
     * Remove the given term IDs from tconn_merge_proposals and tconn_sv_misplaced_queue.
     *
     * tconn_merge_proposals: each entry has orphan_term_id and candidate_term_id (both
     * local term IDs). The proposal is removed if either term is in the pruned set.
     *
     * tconn_sv_misplaced_queue: each entry has term_id (a local term ID). Entries whose
     * term_id is in the pruned set are removed.
     *
     * tconn_shared_vocabularies 'terms'/'roots' roots cleanup is handled separately by
     * clean_sv_source_on_delete() (pre_delete_term), before term meta is deleted.
     *
     * @since 1.0.0
     * @param int[] $term_ids Local term IDs to remove from the queues.
     */
    private static function prune_sv_queues( array $term_ids ): void {
        if ( empty( $term_ids ) ) {
            return;
        }

        $id_set = array_flip( $term_ids );

        // Only when there may be something to prune: this runs for every deleted term.
        if ( get_option( 'tconn_merge_proposals', array() ) ) {
            tconn_update_option_locked( 'tconn_merge_proposals', static function ( $proposals ) use ( $id_set ): ?array {
                $proposals = (array) $proposals;
                $filtered  = array_filter(
                    $proposals,
                    static function( $p ) use ( $id_set ): bool {
                        return ! isset( $id_set[ (int) ( $p['orphan_term_id']    ?? 0 ) ] )
                            && ! isset( $id_set[ (int) ( $p['candidate_term_id'] ?? 0 ) ] );
                    }
                );
                return count( $filtered ) !== count( $proposals ) ? $filtered : null;
            }, array(), false );
        }

        if ( get_option( 'tconn_sv_misplaced_queue', array() ) ) {
            tconn_update_option_locked( 'tconn_sv_misplaced_queue', static function ( $misplaced ) use ( $id_set ): ?array {
                $misplaced = (array) $misplaced;
                $filtered  = array_filter(
                    $misplaced,
                    static function( $entry ) use ( $id_set ): bool {
                        return ! isset( $id_set[ (int) ( $entry['term_id'] ?? 0 ) ] );
                    }
                );
                return count( $filtered ) !== count( $misplaced ) ? $filtered : null;
            }, array(), false );
        }
    }
}
