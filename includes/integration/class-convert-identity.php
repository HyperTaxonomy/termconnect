<?php
/**
 * Delegated identity and external links in HyperTaxonomy term conversions.
 *
 * A delegation belongs to its taxonomy (the remote term is looked up in the same taxonomy on
 * the peer), so a converted term's identity can't move to the new term:
 *
 *  - Old identity: with a redirect stub, the old term stays in the old taxonomy as the stub and
 *    keeps its identity (the default), so its Shared Vocabulary keeps mirroring it there.
 *    Without a stub it ends with the term. It can also be ended on the stub, unless a
 *    vocabulary source covers the slug (TConn_Vocabulary_Coverage): its next sync would
 *    delegate the stub again.
 *  - New term: can be delegated to the peer's term with the same remote slug in the new
 *    taxonomy, when the peer has one (for example after converting its own) and no vocabulary
 *    source in the new taxonomy covers the slug (it would take the term over).
 *  - External links: left on the stub (the default with a stub), moved or copied to the new
 *    term (moving is the default without a stub), or deleted. The new term only gets link
 *    types enabled in its taxonomy.
 *
 * The Convert Terms page offers these through htax_convert_terms_check. Conversions started
 * outside it, such as by external converters like Categories to Tags, get the defaults; for
 * external links, unless whatever started them passed HyperTaxonomy's operation-wide preference.
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TConn_Convert_Identity extends TConn_Operation_Identity {

    /**
     * The conversion in progress, by old term ID: { old: keep|drop, new: delegate|none,
     * reverse: bool, info: ?array }.
     *
     * @var array<int, array>
     */
    private static array $plans = array();

    /**
     * Identity, links and term of each term being converted, by term ID, kept before the
     * conversion, for one that deletes the term (without a stub, or by another tool).
     *
     * @var array<int, array{identity: ?array, links: array, term: ?WP_Term}>
     */
    private static array $snapshots = array();

    /**
     * Messages about the last conversion, for the Convert Terms page.
     *
     * @var string[]
     */
    protected static array $messages = array();

    /**
     * The conversion's options (for one started outside the Convert Terms page, only what its
     * caller passed, often nothing).
     *
     * @var array
     */
    private static array $options = array();

    /**
     * Hook into HyperTaxonomy's conversion.
     *
     * @since 1.0.0
     */
    public static function init(): void {
        add_filter( 'htax_convert_terms_check', array( __CLASS__, 'check' ), 10, 5 );
        add_action( 'htax_before_convert_terms', array( __CLASS__, 'before_convert' ), 10, 5 );
        add_action( 'htax_before_convert_term', array( __CLASS__, 'before_convert_term' ), 10, 3 );
        add_action( 'htax_after_convert_term', array( __CLASS__, 'after_convert_term' ), 10, 4 );
        add_action( 'htax_after_convert_terms', array( __CLASS__, 'after_convert' ) );
        add_filter( 'htax_convert_terms_messages', array( __CLASS__, 'messages' ) );
    }

    /**
     * Plural label of a taxonomy.
     *
     * @param string $taxonomy Taxonomy.
     * @return string
     */
    private static function taxonomy_label( string $taxonomy ): string {
        $object = get_taxonomy( $taxonomy );
        return $object ? (string) $object->labels->name : $taxonomy;
    }

    /**
     * The choices for a converted term's old identity.
     *
     * @param array      $info          self::describe() result.
     * @param string     $from_taxonomy Old taxonomy.
     * @param array|null $claim         First source that would claim the stub once its
     *                                  delegation ends, or null when none or not checked.
     * @return array { choices: array, default: string, default_no_stub: string }
     */
    private static function old_choices( array $info, string $from_taxonomy, ?array $claim = null ): array {
        $sv_name = (string) ( $info['source']['sv_name'] ?? '' );
        $keep    = '' === $sv_name
            /* translators: 1: taxonomy name, 2: remote term slug, 3: peer site name */
            ? sprintf( __( 'The stub in %1$s stays delegated to %2$s on %3$s.', 'termconnect' ), self::taxonomy_label( $from_taxonomy ), $info['remote'], $info['peer'] )
            /* translators: 1: taxonomy name, 2: Shared Vocabulary name, 3: remote term slug */
            : sprintf( __( 'The stub in %1$s keeps its place in %2$s, mirroring %3$s. Nothing is recreated.', 'termconnect' ), self::taxonomy_label( $from_taxonomy ), $sv_name, $info['remote'] );

        if ( 'manual' === $info['kind'] && null === $claim && 'gone' !== $info['state'] ) {
            $ending = __( 'The delegation ends.', 'termconnect' );
        } else {
            $ending = self::ending_description( $info, $claim );
        }
        $ending .= self::reclaim_note( $claim );

        return array(
            'choices'         => array(
                'keep' => array(
                    'label'       => __( 'Keep it on the redirect stub', 'termconnect' ),
                    'description' => $keep,
                    'stub'        => 'required',
                ),
                'drop' => array(
                    'label'       => __( 'Let it end', 'termconnect' ),
                    'description' => $ending,
                    'stub'        => null !== $claim ? 'none' : '',
                ),
            ),
            'default'         => 'gone' === $info['state'] && null === $claim ? 'drop' : 'keep',
            'default_no_stub' => 'drop',
        );
    }

    // --------------------------------------------------------------------------
    // Convert Terms page, check step
    // --------------------------------------------------------------------------

    /**
     * Filter htax_convert_terms_check: TermConnect's notices and choices for a conversion.
     *
     * @since 1.0.0
     * @param array  $sections        Sections so far.
     * @param int[]  $term_ids        Terms to convert.
     * @param string $from_taxonomy   Their taxonomy.
     * @param string $to_taxonomy     Taxonomy to convert them to.
     * @param bool   $create_redirect Whether redirect stubs are selected.
     * @return array
     */
    public static function check( $sections, $term_ids, $from_taxonomy, $to_taxonomy, $create_redirect ) {
        $notices     = array();
        $fields      = array();
        $unreachable = array();
        $links       = array();
        $taken       = array(); // Vocabulary sources that take new terms over, by source ID.
        $to_label    = self::taxonomy_label( (string) $to_taxonomy );

        foreach ( (array) $term_ids as $term_id ) {
            $term = get_term( (int) $term_id, (string) $from_taxonomy );
            if ( ! $term instanceof WP_Term || ( function_exists( 'htax_find_auto_redirect_to_term' ) && htax_find_auto_redirect_to_term( $term->term_id, (string) $to_taxonomy ) ) ) {
                continue; // Converted back to its stub: nothing of TermConnect's changes.
            }
            $links[ $term->term_id ] = tconn_get_term_links_data( $term->term_id );

            // A vocabulary in the new taxonomy that covers the slug delegates the new term.
            $covering    = TConn_Vocabulary_Coverage::claimants( (string) $to_taxonomy, $term->slug );
            $unreachable = array_merge( $unreachable, $covering['unreachable'] );
            $new_claim   = $covering['claimants'][0] ?? null;
            if ( null !== $new_claim ) {
                $source_id                      = (string) ( $new_claim['source']['id'] ?? '' );
                $taken[ $source_id ]            = $taken[ $source_id ] ?? array(
                    'claim' => $new_claim,
                    'terms' => array(),
                );
                $taken[ $source_id ]['terms'][] = $term->name;
            }

            $identity = tconn_get_identity_source( $term->term_id );
            if ( null === $identity ) {
                continue;
            }
            $info   = self::describe( $term, self::check_remote( $identity, $term->taxonomy ) );
            $notice = self::unreachable_peer_notice( $info );
            if ( $notice ) {
                $notices[] = $notice;
            }
            $claimed     = self::claim_after_end( $info );
            $unreachable = array_merge( $unreachable, $claimed['unreachable'] );

            $old      = self::old_choices( $info, $term->taxonomy, $claimed['claimants'][0] ?? null );
            $fields[] = array(
                'name'            => 'old_' . $term->term_id,
                'label'           => self::field_label( $term, $info ),
                'choices'         => self::as_list( $old['choices'] ),
                'default'         => $old['default'],
                'default_no_stub' => $old['default_no_stub'],
            );

            // The new term: the peer's term with the same slug in the new taxonomy, if any.
            if ( null === $new_claim && 'exists' === self::check_remote( $identity, (string) $to_taxonomy ) ) {
                $syncs    = ! empty( $identity['sync_name'] ) || ! empty( $identity['sync_description'] );
                $fields[] = array(
                    /* translators: 1: term name, 2: taxonomy name */
                    'label'           => sprintf( __( '%1$s in %2$s', 'termconnect' ), $term->name, $to_label ),
                    'name'            => 'new_' . $term->term_id,
                    'choices'         => self::as_list( array(
                        'delegate' => array(
                            /* translators: 1: remote term slug, 2: taxonomy name, 3: peer site name */
                            'label'       => sprintf( __( 'Delegate it to %1$s in %2$s on %3$s', 'termconnect' ), $info['remote'], $to_label, $info['peer'] ),
                            'description' => $syncs ? __( 'Its name and description follow the remote term; its own are kept, to restore if the delegation ends.', 'termconnect' ) : '',
                            'stub'        => '',
                        ),
                        'none'     => array(
                            'label'       => __( 'Leave it undelegated', 'termconnect' ),
                            'description' => '',
                            'stub'        => '',
                        ),
                    ) ),
                    'default'         => 'delegate',
                    'default_no_stub' => 'delegate',
                );
            }
        }

        $field = TConn_HT_Integration::links_field( $links, array(), '', tconn_get_enabled_link_types_for_taxonomy( (string) $to_taxonomy ), $to_label );
        if ( $field ) {
            $fields[] = $field;
        }
        foreach ( $taken as $taker ) {
            $notices[] = array(
                'type' => 'info',
                /* translators: 1: Shared Vocabulary name, 2: taxonomy name, 3: peer site name, 4: comma-separated term names */
                'text' => sprintf( __( 'The next sync of %1$s delegates these new terms in %2$s to the terms with the same slugs on %3$s, and replaces their names and descriptions: %4$s.', 'termconnect' ), (string) ( $taker['claim']['source']['sv_name'] ?? '' ), $to_label, $taker['claim']['peer'], implode( ', ', $taker['terms'] ) ),
            );
        }
        $notice = TConn_Vocabulary_Coverage::unreachable_notice( $unreachable );
        if ( $notice ) {
            $notices[] = $notice;
        }

        return self::add_section( (array) $sections, $notices, $fields );
    }

    // --------------------------------------------------------------------------
    // The conversion
    // --------------------------------------------------------------------------

    /**
     * Action htax_before_convert_terms: decide what happens to each term's identity.
     * Choices from the Convert Terms page are checked again; anything missing or no longer
     * possible gets the default.
     *
     * @since 1.0.0
     * @param int[]  $term_ids        Terms to convert.
     * @param string $from_taxonomy   Their taxonomy.
     * @param string $to_taxonomy     Taxonomy to convert them to.
     * @param bool   $create_redirect Whether redirect stubs will be left.
     * @param array  $options         Conversion options; TermConnect's are under self::SECTION.
     */
    public static function before_convert( $term_ids, $from_taxonomy, $to_taxonomy, $create_redirect, $options = array() ): void {
        self::$plans    = array();
        self::$messages = array();
        self::$options  = (array) $options;
        $chosen         = (array) ( $options[ self::SECTION ] ?? array() );

        foreach ( (array) $term_ids as $term_id ) {
            $term = get_term( (int) $term_id, (string) $from_taxonomy );
            if ( ! $term instanceof WP_Term ) {
                continue;
            }
            $info = self::describe( $term );
            $plan = array(
                'reverse' => function_exists( 'htax_find_auto_redirect_to_term' ) && (bool) htax_find_auto_redirect_to_term( $term->term_id, (string) $to_taxonomy ),
                'old'     => $create_redirect ? 'keep' : 'drop',
                'new'     => 'delegate' === ( $chosen[ 'new_' . $term->term_id ] ?? '' ) ? 'delegate' : 'none',
                'info'    => $info,
            );
            if ( $info ) {
                $made        = (string) ( $chosen[ 'old_' . $term->term_id ] ?? '' );
                $plan['old'] = self::usable_choice( self::old_choices( $info, $term->taxonomy ), $made, (bool) $create_redirect );
                if ( 'drop' === $plan['old'] && $create_redirect ) {
                    $claim = self::claim_after_end( $info )['claimants'][0] ?? null;
                    if ( null !== $claim ) {
                        $plan['old'] = self::usable_choice( self::old_choices( $info, $term->taxonomy, $claim ), $made, true );
                    }
                }
            }
            self::$plans[ $term->term_id ] = $plan;
        }
    }

    /**
     * Action htax_before_convert_term: keep a term's identity and links, which WordPress
     * deletes with the term when the conversion leaves no stub.
     *
     * @since 1.0.0
     * @param int    $term_id       Term being converted.
     * @param string $from_taxonomy Its taxonomy.
     */
    public static function before_convert_term( $term_id, $from_taxonomy ): void {
        $term_id  = (int) $term_id;
        $links    = tconn_get_term_links_data( $term_id );
        $identity = tconn_get_identity_source( $term_id );
        if ( $links || $identity ) {
            $term                        = get_term( $term_id, (string) $from_taxonomy );
            self::$snapshots[ $term_id ] = array(
                'identity' => $identity,
                'links'    => $links,
                'term'     => $term instanceof WP_Term ? $term : null,
            );
        }
    }

    /**
     * Action htax_after_convert_term: put the external links where they were chosen to go, and
     * apply what was decided for the term's identity.
     *
     * @since 1.0.0
     * @param int    $new_term_id   New term ID.
     * @param string $old_taxonomy  Old taxonomy.
     * @param string $new_taxonomy  New taxonomy.
     * @param int    $old_term_id   Old term ID (the stub, or deleted).
     */
    public static function after_convert_term( $new_term_id, $old_taxonomy, $new_taxonomy, $old_term_id ): void {
        $new_term_id = (int) $new_term_id;
        $old_term_id = (int) $old_term_id;
        $plan        = self::$plans[ $old_term_id ] ?? array(
            'reverse' => false,
            'old' => 'keep',
            'new' => 'none',
            'info' => null,
        );
        unset( self::$plans[ $old_term_id ] );

        $old_term = get_term( $old_term_id, (string) $old_taxonomy );
        $snapshot = self::$snapshots[ $old_term_id ] ?? array(
            'identity' => null,
            'links' => array(),
            'term' => null,
        );
        unset( self::$snapshots[ $old_term_id ] );
        $alive    = $old_term instanceof WP_Term;
        $identity = $alive ? tconn_get_identity_source( $old_term_id ) : $snapshot['identity'];
        $name     = $alive ? $old_term->name : ( $snapshot['term'] ? $snapshot['term']->name : '' );

        // External links: a term converted back to its stub gives them back to the stub.
        TConn_HT_Integration::carry_links(
            $alive ? tconn_get_term_links_data( $old_term_id ) : (array) $snapshot['links'],
            $alive ? $old_term_id : 0,
            true,
            $new_term_id,
            $plan['reverse'] ? 'move' : htax_carry_choice( self::$options, self::SECTION, 'links', $alive ),
            tconn_get_enabled_link_types_for_taxonomy( (string) $new_taxonomy )
        );

        if ( $plan['reverse'] || null === $identity ) {
            return;
        }

        if ( $alive && 'drop' === $plan['old'] ) {
            // The stub keeps its name: it is what visitors' old links point to.
            tconn_remove_identity_source( $old_term_id, array(
                'restore_name' => false,
                'restore_description' => false,
            ) );
            foreach ( $plan['info'] ? self::own_row( $plan['info'] ) : array() as $source_id => $remote_slug ) {
                tconn_sv_remove_root( (string) $source_id, (string) $remote_slug );
            }
            /* translators: 1: term name, 2: remote term slug */
            self::$messages[] = sprintf( __( '%1$s: the delegation to %2$s ended.', 'termconnect' ), $name, $identity['remote_slug'] );
        } elseif ( $alive ) {
            /* translators: 1: term name, 2: remote term slug */
            self::$messages[] = sprintf( __( '%1$s: its redirect stub keeps the delegation to %2$s.', 'termconnect' ), $name, $identity['remote_slug'] );
        } else {
            /* translators: 1: term name, 2: remote term slug */
            self::$messages[] = sprintf( __( '%1$s: the delegation to %2$s ended with the old term.', 'termconnect' ), $name, $identity['remote_slug'] );
        }

        if ( 'delegate' === $plan['new'] ) {
            $remote           = tconn_fetch_remote_identity( $identity['peer_site_slug'], (string) $new_taxonomy, $identity['remote_slug'] );
            $result           = is_wp_error( $remote ) ? $remote : tconn_set_identity_source(
                $new_term_id,
                $identity['peer_site_slug'],
                $identity['remote_slug'],
                $remote,
                false,
                '',
                ! empty( $identity['sync_name'] ),
                ! empty( $identity['sync_description'] )
            );
            self::$messages[] = is_wp_error( $result )
                /* translators: 1: term name, 2: error message */
                ? sprintf( __( '%1$s: the new term could not be delegated: %2$s', 'termconnect' ), $name, $result->get_error_message() )
                /* translators: 1: term name, 2: remote term slug, 3: taxonomy name */
                : sprintf( __( '%1$s: the new term is delegated to %2$s in %3$s.', 'termconnect' ), $name, $identity['remote_slug'], self::taxonomy_label( (string) $new_taxonomy ) );
        }
    }

    /**
     * Action htax_after_convert_terms: forget the conversion's options.
     *
     * @since 1.0.0
     */
    public static function after_convert(): void {
        self::$options = array();
    }
}
