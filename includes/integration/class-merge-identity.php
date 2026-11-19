<?php
/**
 * Delegated identity in HyperTaxonomy term merges.
 *
 * A source term's delegated identity is term meta, so a merge would delete it with the term.
 * For each delegated source this offers up to three choices on the Merge Terms page (through
 * htax_merge_terms_check) and applies the one made, or a default, around the merge:
 *
 *  - stub:   keep the identity on the source's redirect stub, which keeps its slug and place,
 *            so a Shared Vocabulary keeps mirroring it there instead of recreating the term.
 *            The default with a stub.
 *  - target: move the identity to the target, when the target has none. A peer-driven
 *            Shared Vocabulary renames a delegated term's slug to the remote one, so for those
 *            this is only possible without a stub (which would keep the slug). The default
 *            without a stub, unless another vocabulary would take the target over.
 *  - drop:   let it end with the term. Not with a stub when another vocabulary source covers
 *            the slug (TConn_Vocabulary_Coverage), since its next sync would delegate the stub.
 *
 * It also offers what happens to the sources' external links (leave them on the stubs, move
 * or copy them to the target, or delete them), which TConn_HT_Integration applies.
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TConn_Merge_Identity extends TConn_Operation_Identity {

    /**
     * The merge in progress: what to do with each delegated source, decided before the
     * source terms are deleted. Keyed by source term ID.
     *
     * @var array<int, array>
     */
    private static array $plans = array();

    /**
     * Messages about the last merge, for the Merge Terms page.
     *
     * @var string[]
     */
    protected static array $messages = array();

    /**
     * Hook into HyperTaxonomy's merge.
     *
     * @since 1.0.0
     */
    public static function init(): void {
        add_filter( 'htax_merge_terms_check', array( __CLASS__, 'check' ), 10, 5 );
        add_action( 'htax_before_merge_terms', array( __CLASS__, 'before_merge' ), 10, 5 );
        add_action( 'htax_after_merge_terms', array( __CLASS__, 'after_merge' ), 10, 5 );
        add_filter( 'htax_merge_terms_messages', array( __CLASS__, 'messages' ) );
    }

    /**
     * Whether a term being merged away keeps its identity (on its stub or the target), so
     * its Shared Vocabulary entry must stay. Read by the term deletion cleanup.
     *
     * @since 1.0.0
     * @param int $term_id Term ID.
     * @return bool
     */
    public static function is_kept( int $term_id ): bool {
        return in_array( self::$plans[ $term_id ]['choice'] ?? '', array( 'stub', 'target' ), true );
    }

    // --------------------------------------------------------------------------
    // Choices
    // --------------------------------------------------------------------------

    /**
     * The choices for a delegated source, and which is the default with and without stubs.
     *
     * @param array      $info         describe() result.
     * @param WP_Term    $target       Target term.
     * @param bool       $target_free  Whether the target can take this identity (it has none,
     *                                 and no earlier source takes it).
     * @param array|null $claim        First source that would claim the term once its delegation
     *                                 ends (from claim_after_end()), or null when none or not checked.
     * @param array|null $target_claim First source that would claim the target by its slug, or
     *                                 null when none or not checked.
     * @return array { choices: array<string, array{label: string, description: string, stub: string}>, default: string, default_no_stub: string }
     */
    private static function choices( array $info, WP_Term $target, bool $target_free, ?array $claim = null, ?array $target_claim = null ): array {
        $remote  = $info['remote'];
        $peer    = $info['peer'];
        $sv_name = (string) ( $info['source']['sv_name'] ?? '' );
        $syncs   = ! empty( $info['identity']['sync_name'] ) || ! empty( $info['identity']['sync_description'] );
        $choices = array();

        if ( 'local' !== $info['kind'] ) {
            $choices['stub'] = array(
                'label'       => __( 'Keep it on the redirect stub', 'termconnect' ),
                'description' => 'remote' === $info['kind']
                    /* translators: 1: Shared Vocabulary name, 2: remote term slug */
                    ? sprintf( __( 'The stub keeps its place in %1$s, mirroring %2$s, and redirects visitors to the target. Nothing is recreated.', 'termconnect' ), $sv_name, $remote )
                    /* translators: 1: remote term slug, 2: peer site name */
                    : sprintf( __( 'The stub stays delegated to %1$s on %2$s and redirects visitors to the target.', 'termconnect' ), $remote, $peer ),
                'stub'        => 'required',
            );
        }

        // A vocabulary that covers the target's slug overwrites a manual delegation there.
        $taken_over = 'manual' === $info['kind'] && null !== $target_claim
            && ( ( $target_claim['source']['peer_site_slug'] ?? '' ) !== $info['identity']['peer_site_slug'] || $target->slug !== $remote );

        if ( $target_free ) {
            if ( 'remote' === $info['kind'] ) {
                /* translators: 1: target term name, 2: remote term slug, 3: target's new slug */
                $description = sprintf( __( '%1$s mirrors %2$s instead, so its slug becomes %3$s (its archive address changes).', 'termconnect' ), $target->name, $remote, $remote );
            } else {
                /* translators: 1: target term name, 2: remote term slug, 3: peer site name */
                $description = sprintf( __( '%1$s is delegated to %2$s on %3$s instead.', 'termconnect' ), $target->name, $remote, $peer );
            }
            if ( $syncs ) {
                $description .= ' ' . __( 'Its name and description follow the remote term; its own are kept, to restore if the delegation ends.', 'termconnect' );
            }
            if ( $taken_over ) {
                /* translators: 1: Shared Vocabulary name, 2: remote term slug, 3: peer site name */
                $description .= ' ' . sprintf( __( 'Then the next sync of %1$s delegates it to %2$s on %3$s instead.', 'termconnect' ), (string) ( $target_claim['source']['sv_name'] ?? '' ), $target->slug, $target_claim['peer'] );
            }
            $choices['target'] = array(
                'label'       => __( 'Move it to the target', 'termconnect' ),
                'description' => $description,
                // A stub keeps the source's slug, which the vocabulary would give the target.
                'stub'        => 'remote' === $info['kind'] ? 'none' : '',
            );
        }

        if ( 'local' === $info['kind'] && 'gone' !== $info['state'] ) {
            $ending = __( 'The delegation ends with the term. Its group membership moves to the target.', 'termconnect' );
        } else {
            $ending = self::ending_description( $info, $claim );
        }
        $ending .= self::reclaim_note( $claim );
        $choices['drop'] = array(
            'label'       => __( 'Let it end', 'termconnect' ),
            'description' => $ending,
            'stub'        => null !== $claim ? 'none' : '',
        );

        $default_no_stub = isset( $choices['target'] ) && 'gone' !== $info['state'] && ! $taken_over ? 'target' : 'drop';
        $default         = isset( $choices['stub'] ) && ! ( 'gone' === $info['state'] && null === $claim ) ? 'stub' : $default_no_stub;

        return array( 'choices' => $choices, 'default' => $default, 'default_no_stub' => $default_no_stub );
    }

    // --------------------------------------------------------------------------
    // Merge Terms page, step 2
    // --------------------------------------------------------------------------

    /**
     * Filter htax_merge_terms_check: TermConnect's notices and choices for a merge.
     *
     * @since 1.0.0
     * @param array  $sections        Sections so far.
     * @param int[]  $source_ids      Source term IDs.
     * @param int    $target_id       Target term ID.
     * @param string $taxonomy        Taxonomy.
     * @param bool   $create_redirect Whether redirect stubs are selected.
     * @return array
     */
    public static function check( $sections, $source_ids, $target_id, $taxonomy, $create_redirect ) {
        $target = get_term( (int) $target_id, (string) $taxonomy );
        if ( ! $target instanceof WP_Term ) {
            return $sections;
        }

        $notices         = array();
        $fields          = array();
        $unreachable     = array();
        $source_links    = array();
        $target_identity = tconn_get_identity_source( $target->term_id );
        $target_free     = null === $target_identity;
        $target_claim    = null;
        $target_checked  = false;

        if ( $target_identity ) {
            $target_peer = tconn_get_link_type( $target_identity['peer_site_slug'] );
            $notices[]   = array(
                'type' => 'info',
                /* translators: 1: target term name, 2: remote term slug, 3: peer site name */
                'text' => sprintf( __( '%1$s keeps its own delegation, to %2$s on %3$s.', 'termconnect' ), $target->name, $target_identity['remote_slug'], $target_peer['label'] ?? $target_identity['peer_site_slug'] ),
            );
        }

        foreach ( (array) $source_ids as $source_id ) {
            $term = get_term( (int) $source_id, (string) $taxonomy );
            if ( ! $term instanceof WP_Term ) {
                continue;
            }
            $source_links[ $term->term_id ] = tconn_get_term_links_data( $term->term_id );

            $identity = tconn_get_identity_source( $term->term_id );
            if ( null === $identity ) {
                continue;
            }
            $info = self::describe( $term, self::check_remote( $identity, $term->taxonomy ) );
            $notice = self::unreachable_peer_notice( $info );
            if ( $notice ) {
                $notices[] = $notice;
            }
            if ( 'local' === $info['kind'] && $target_free ) {
                $notices[] = array(
                    'type' => 'info',
                    /* translators: 1: target term name, 2: group name, 3: Shared Vocabulary name, 4: target slug, 5: peer site name */
                    'text' => sprintf( __( '%1$s joins the group %2$s, which %3$s mirrors. Unless it takes this delegation, the next sync delegates it to the term with the slug %4$s on %5$s, if there is one, and replaces its name and description.', 'termconnect' ), $target->name, (string) ( $info['source']['group_name'] ?? '' ), (string) ( $info['source']['sv_name'] ?? '' ), $target->slug, $info['peer'] ),
                );
            }

            $claimed     = self::claim_after_end( $info );
            $unreachable = array_merge( $unreachable, $claimed['unreachable'] );
            if ( 'manual' === $info['kind'] && $target_free && ! $target_checked ) {
                $target_checked = true;
                $covering       = TConn_Vocabulary_Coverage::claimants( $target->taxonomy, $target->slug );
                $target_claim   = $covering['claimants'][0] ?? null;
                $unreachable    = array_merge( $unreachable, $covering['unreachable'] );
            }

            $plan = self::choices( $info, $target, $target_free, $claimed['claimants'][0] ?? null, $target_claim );
            if ( isset( $plan['choices']['target'] ) && 'target' === $plan['default_no_stub'] ) {
                $target_free = false; // Only one source can move its identity to the target.
            }

            $fields[] = array(
                'name'            => 'identity_' . $term->term_id,
                'label'           => self::field_label( $term, $info ),
                'choices'         => self::as_list( $plan['choices'] ),
                'default'         => $plan['default'],
                'default_no_stub' => $plan['default_no_stub'],
            );
        }

        $links = TConn_HT_Integration::links_field( $source_links, tconn_get_term_links_data( $target->term_id ), $target->name );
        if ( $links ) {
            $fields[] = $links;
        }
        $notice = TConn_Vocabulary_Coverage::unreachable_notice( $unreachable );
        if ( $notice ) {
            $notices[] = $notice;
        }

        return self::add_section( (array) $sections, $notices, $fields );
    }

    // --------------------------------------------------------------------------
    // The merge
    // --------------------------------------------------------------------------

    /**
     * Action htax_before_merge_terms: decide what happens to each source's identity, and
     * keep what's needed to apply it, before the source terms are deleted.
     *
     * Choices from the Merge Terms page are checked again; anything missing or no longer
     * possible gets the default. Letting a delegation end with a stub is checked against the
     * other vocabulary sources only when it is chosen, so merges that don't choose it make no
     * requests to peers.
     *
     * @since 1.0.0
     * @param int[]  $source_ids      Source term IDs.
     * @param int    $target_id       Target term ID.
     * @param string $taxonomy        Taxonomy.
     * @param bool   $create_redirect Whether redirect stubs will be created.
     * @param array  $options         Merge options; TermConnect's are under self::SECTION.
     */
    public static function before_merge( $source_ids, $target_id, $taxonomy, $create_redirect, $options = array() ): void {
        self::$plans    = array();
        self::$messages = array();

        $target = get_term( (int) $target_id, (string) $taxonomy );
        if ( ! $target instanceof WP_Term ) {
            return;
        }
        $chosen      = (array) ( $options[ self::SECTION ] ?? array() );
        $target_free = null === tconn_get_identity_source( $target->term_id );

        foreach ( (array) $source_ids as $source_id ) {
            $term = get_term( (int) $source_id, (string) $taxonomy );
            $info = $term instanceof WP_Term ? self::describe( $term ) : null;
            if ( null === $info ) {
                continue;
            }

            $made   = (string) ( $chosen[ 'identity_' . $term->term_id ] ?? '' );
            $plan   = self::choices( $info, $target, $target_free );
            $choice = self::usable_choice( $plan, $made, (bool) $create_redirect );
            if ( 'drop' === $choice && $create_redirect ) {
                $claim = self::claim_after_end( $info )['claimants'][0] ?? null;
                if ( null !== $claim ) {
                    $choice = self::usable_choice( self::choices( $info, $target, $target_free, $claim ), $made, true );
                }
            }
            if ( 'target' === $choice ) {
                $target_free = false;
            }

            self::$plans[ $term->term_id ] = array(
                'choice'   => $choice,
                // If the stub can't be created (a term with its name exists): as without stubs.
                'fallback' => self::usable_choice( $plan, '', false ),
                'info'     => $info,
                'name'     => $term->name,
                'desc'     => $term->description,
                'links'    => get_term_meta( $term->term_id, TCONN_META_DELEGATED_LINKS_DATA, true ),
                'original' => array(
                    'name'        => metadata_exists( 'term', $term->term_id, TCONN_META_ORIGINAL_NAME ) ? get_term_meta( $term->term_id, TCONN_META_ORIGINAL_NAME, true ) : null,
                    'description' => metadata_exists( 'term', $term->term_id, TCONN_META_ORIGINAL_DESCRIPTION ) ? get_term_meta( $term->term_id, TCONN_META_ORIGINAL_DESCRIPTION, true ) : null,
                ),
            );
        }
    }

    /**
     * Action htax_after_merge_terms: apply what before_merge() decided.
     *
     * @since 1.0.0
     * @param int[]  $source_ids Source term IDs (now deleted).
     * @param int    $target_id  Target term ID.
     * @param string $taxonomy   Taxonomy.
     * @param array  $result     Merge result, with redirect_stubs.
     * @param array  $options    Merge options (already read by before_merge()).
     */
    public static function after_merge( $source_ids, $target_id, $taxonomy, $result, $options = array() ): void {
        $stubs = (array) ( $result['redirect_stubs'] ?? array() );

        foreach ( self::$plans as $source_id => $plan ) {
            $info   = $plan['info'];
            $choice = $plan['choice'];

            if ( 'stub' === $choice && empty( $stubs[ $source_id ] ) ) {
                // The stub couldn't be created (a term with its name exists): as without stubs.
                $choice = 'target' === $plan['fallback'] && null === tconn_get_identity_source( (int) $target_id ) ? 'target' : 'drop';
                if ( 'drop' === $choice ) {
                    foreach ( self::own_row( $info ) as $sv_source_id => $remote_slug ) {
                        tconn_sv_remove_root( (string) $sv_source_id, (string) $remote_slug );
                    }
                }
            }

            if ( 'stub' === $choice ) {
                self::keep_on_stub( (int) $stubs[ $source_id ], $plan );
                self::$messages[] = sprintf(
                    /* translators: 1: source term name, 2: remote term slug */
                    __( '%1$s: its redirect stub keeps the delegation to %2$s.', 'termconnect' ),
                    $plan['name'],
                    $info['remote']
                );
            } elseif ( 'target' === $choice ) {
                $error = self::move_to_target( (int) $target_id, (string) $taxonomy, $plan );
                self::$messages[] = '' === $error
                    /* translators: 1: source term name, 2: remote term slug */
                    ? sprintf( __( '%1$s: the target is now delegated to %2$s.', 'termconnect' ), $plan['name'], $info['remote'] )
                    /* translators: 1: source term name, 2: remote term slug, 3: error message */
                    : sprintf( __( '%1$s: the delegation to %2$s could not be moved to the target: %3$s', 'termconnect' ), $plan['name'], $info['remote'], $error );
            } else {
                self::$messages[] = sprintf(
                    /* translators: 1: source term name, 2: remote term slug */
                    __( '%1$s: the delegation to %2$s ended.', 'termconnect' ),
                    $plan['name'],
                    $info['remote']
                );
            }
        }

        self::$plans = array();
    }

    /**
     * Put a source's identity on its redirect stub, as it was on the source.
     *
     * @param int   $stub_id Stub term ID.
     * @param array $plan    The source's plan.
     */
    private static function keep_on_stub( int $stub_id, array $plan ): void {
        $identity = $plan['info']['identity'];
        if ( 'redirect_pending' === $identity['status'] ) {
            $identity['status'] = 'active'; // The pending redirect was the deleted term's; the next refresh checks again.
        }
        foreach ( $plan['original'] as $field => $value ) {
            if ( null !== $value ) {
                update_term_meta( $stub_id, 'name' === $field ? TCONN_META_ORIGINAL_NAME : TCONN_META_ORIGINAL_DESCRIPTION, $value );
            }
        }
        update_term_meta( $stub_id, TCONN_META_IDENTITY_SOURCE, $identity );
        tconn_save_delegated_links( $stub_id, is_array( $plan['links'] ) ? $plan['links'] : array() );
    }

    /**
     * Delegate the target to a source's remote term, as tconn_set_identity_source() does
     * (the target's own name and description are backed up). For a peer-driven vocabulary
     * the target also takes the remote slug, as that vocabulary's next sync would.
     *
     * @param int    $target_id Target term ID.
     * @param string $taxonomy  Taxonomy.
     * @param array  $plan      The source's plan.
     * @return string Error message, '' on success.
     */
    private static function move_to_target( int $target_id, string $taxonomy, array $plan ): string {
        $identity = $plan['info']['identity'];
        $result   = tconn_set_identity_source(
            $target_id,
            $identity['peer_site_slug'],
            $identity['remote_slug'],
            array(
                'name'            => $plan['name'],
                'description'     => $plan['desc'],
                'delegated_links' => is_array( $plan['links'] ) ? $plan['links'] : array(),
                'term_id'         => (int) $identity['remote_term_id'],
                'link'            => (string) $identity['remote_link'],
            ),
            ! empty( $identity['sync_descendants'] ),
            (string) ( $identity['sv_source_id'] ?? '' ),
            ! empty( $identity['sync_name'] ),
            ! empty( $identity['sync_description'] )
        );
        if ( is_wp_error( $result ) ) {
            return $result->get_error_message();
        }
        if ( 'remote' === $plan['info']['kind'] ) {
            $renamed = wp_update_term( $target_id, $taxonomy, array( 'slug' => $identity['remote_slug'] ) );
            if ( is_wp_error( $renamed ) ) {
                return $renamed->get_error_message();
            }
        }
        return '';
    }
}
