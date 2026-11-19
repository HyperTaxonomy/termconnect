<?php
/**
 * Shared parts of TermConnect's handling of HyperTaxonomy term merges and conversions
 * (TConn_Merge_Identity, TConn_Convert_Identity): what a term's delegation is, what ending it
 * leads to, which choice applies, and the check step's fields, notices and result messages.
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class TConn_Operation_Identity {

    /** Section ID of TermConnect's choices in merge and conversion options. */
    const SECTION = 'termconnect';

    /**
     * Messages about the last operation, for its result page. Each subclass declares its own.
     *
     * @var string[]
     */
    protected static array $messages = array();

    /**
     * Describe a term's delegated identity for a merge or conversion.
     *
     * @param WP_Term $term         Term.
     * @param string  $remote_state 'exists', 'gone', 'unreachable' or '' (not checked:
     *                              taken from the identity's last known status).
     * @return array|null Null when the term has no identity. Otherwise: {
     *     identity array  Identity source meta.
     *     kind     string 'manual' | 'remote' (peer-driven vocabulary) | 'local' (local group).
     *     scope    string Vocabulary scope, '' for manual.
     *     is_root  bool   A root of a term hierarchies source.
     *     source   array  Vocabulary source config, empty for manual.
     *     peer     string Peer name.
     *     remote   string Remote term slug.
     *     state    string Remote term state, as above.
     *     slug     string The term's slug.
     *     taxonomy string The term's taxonomy.
     * }
     */
    public static function describe( WP_Term $term, string $remote_state = '' ): ?array {
        $identity = tconn_get_identity_source( $term->term_id );
        if ( null === $identity ) {
            return null;
        }

        $source = tconn_sv_get_source( (string) ( $identity['sv_source_id'] ?? '' ) ) ?? array();
        $scope = (string) ( $source['scope'] ?? '' );
        $kind  = '' === $scope ? 'manual' : ( 'local_group' === $scope ? 'local' : 'remote' );

        $is_root = 'roots' === $scope && in_array(
            $identity['remote_slug'],
            array_map( static fn( $root ) => (string) ( $root['remote_slug'] ?? '' ), (array) ( $source['roots'] ?? array() ) ),
            true
        );

        if ( '' === $remote_state ) {
            $remote_state = 'not_found' === $identity['status'] ? 'gone' : 'exists';
        }

        $link_type = tconn_get_link_type( $identity['peer_site_slug'] );

        return array(
            'identity' => $identity,
            'kind'     => $kind,
            'scope'    => $scope,
            'is_root'  => $is_root,
            'source'   => $source,
            'peer'     => (string) ( $link_type['label'] ?? $identity['peer_site_slug'] ),
            'remote'   => (string) $identity['remote_slug'],
            'state'    => $remote_state,
            'slug'     => $term->slug,
            'taxonomy' => $term->taxonomy,
        );
    }

    /**
     * The vocabulary row that ending this delegation removes: a Term Hierarchies root or an
     * Individual Terms entry, as source ID => remote slug.
     *
     * @since 1.0.0
     * @param array $info describe() result.
     * @return array
     */
    public static function own_row( array $info ): array {
        $source_id = (string) ( $info['identity']['sv_source_id'] ?? '' );
        if ( '' === $source_id || ! ( $info['is_root'] || 'terms' === $info['scope'] ) ) {
            return array();
        }
        return array( $source_id => $info['remote'] );
    }

    /**
     * The vocabulary sources that would claim the term (or a stub with its slug) once its
     * delegation ends, in priority order.
     *
     * @since 1.0.0
     * @param array $info describe() result.
     * @return array{claimants: array, unreachable: string[]}
     */
    public static function claim_after_end( array $info ): array {
        return TConn_Vocabulary_Coverage::claimants( $info['taxonomy'], $info['slug'], self::own_row( $info ) );
    }

    /**
     * Ask the peer whether a delegated term's remote term still exists.
     *
     * @param array  $identity Identity source meta.
     * @param string $taxonomy Taxonomy to look in on the peer.
     * @param string $slug     Remote slug, if not the identity's.
     * @return string 'exists', 'gone' or 'unreachable'.
     */
    public static function check_remote( array $identity, string $taxonomy, string $slug = '' ): string {
        $remote = tconn_fetch_remote_identity( $identity['peer_site_slug'], $taxonomy, '' !== $slug ? $slug : $identity['remote_slug'] );
        if ( ! is_wp_error( $remote ) ) {
            return 'exists';
        }
        return 'tconn_not_found' === $remote->get_error_code() ? 'gone' : 'unreachable';
    }

    /**
     * The choice to apply: the one made if it is possible with this stub setting, else the
     * default, else the first possible one.
     *
     * @since 1.0.0
     * @param array  $plan            Choices and defaults, as choices() returns them.
     * @param string $choice          Choice made, or ''.
     * @param bool   $create_redirect Whether redirect stubs are created.
     * @return string
     */
    public static function usable_choice( array $plan, string $choice, bool $create_redirect ): string {
        $candidates = array_merge(
            array( $choice, $create_redirect ? $plan['default'] : $plan['default_no_stub'] ),
            array_map( 'strval', array_keys( $plan['choices'] ) )
        );
        foreach ( $candidates as $value ) {
            $stub = $plan['choices'][ $value ]['stub'] ?? null;
            if ( null !== $stub && ( $create_redirect ? 'none' !== $stub : 'required' !== $stub ) ) {
                return $value;
            }
        }
        return '';
    }

    /**
     * What happens when a term's delegation ends because the term is deleted.
     *
     * @since 1.0.0
     * @param array      $info  describe() result.
     * @param array|null $claim First source that would claim the term afterwards (from
     *                          claim_after_end()), or null.
     * @return string
     */
    public static function ending_description( array $info, ?array $claim = null ): string {
        $sv_name = (string) ( $info['source']['sv_name'] ?? '' );
        if ( null !== $claim ) {
            /* translators: 1: Shared Vocabulary name, 2: remote term slug, 3: peer site name */
            return sprintf( __( 'The next sync of %1$s creates the term again (with no posts) and delegates it to %2$s on %3$s.', 'termconnect' ), (string) ( $claim['source']['sv_name'] ?? '' ), $info['slug'], $claim['peer'] );
        }
        if ( 'gone' === $info['state'] ) {
            /* translators: 1: remote term slug, 2: peer site name */
            return sprintf( __( '%1$s no longer exists on %2$s, so nothing brings it back.', 'termconnect' ), $info['remote'], $info['peer'] );
        }
        if ( $info['is_root'] ) {
            /* translators: %s: Shared Vocabulary name */
            return sprintf( __( '%s stops mirroring this hierarchy: on its next sync, the terms under it lose their delegation, unless another source covers them.', 'termconnect' ), $sv_name );
        }
        if ( 'terms' === $info['scope'] ) {
            /* translators: %s: Shared Vocabulary name */
            return sprintf( __( 'It is removed from the terms %s mirrors.', 'termconnect' ), $sv_name );
        }
        return __( 'The delegation ends with the term.', 'termconnect' );
    }

    /**
     * Choices keyed by value, as the list the check step takes.
     *
     * @since 1.0.0
     * @param array $choices Choices.
     * @return array
     */
    protected static function as_list( array $choices ): array {
        $list = array();
        foreach ( $choices as $value => $choice ) {
            $list[] = array( 'value' => $value ) + $choice;
        }
        return $list;
    }

    /**
     * Label of a delegated term's field on the check step.
     *
     * @since 1.0.0
     * @param WP_Term $term Term.
     * @param array   $info describe() result.
     * @return string
     */
    protected static function field_label( WP_Term $term, array $info ): string {
        return '' === (string) ( $info['source']['sv_name'] ?? '' )
            /* translators: 1: term name, 2: remote term slug, 3: peer site name */
            ? sprintf( __( '%1$s is delegated to %2$s on %3$s', 'termconnect' ), $term->name, $info['remote'], $info['peer'] )
            /* translators: 1: term name, 2: remote term slug, 3: peer site name, 4: Shared Vocabulary name */
            : sprintf( __( '%1$s mirrors %2$s on %3$s (%4$s)', 'termconnect' ), $term->name, $info['remote'], $info['peer'], $info['source']['sv_name'] );
    }

    /**
     * The check step's warning when a delegated term's peer couldn't be asked about it.
     *
     * @since 1.0.0
     * @param array $info describe() result.
     * @return array|null Notice, or null when the peer answered.
     */
    protected static function unreachable_peer_notice( array $info ): ?array {
        if ( 'unreachable' !== $info['state'] ) {
            return null;
        }
        return array(
            'type' => 'warning',
            /* translators: 1: peer site name, 2: remote term slug */
            'text' => sprintf( __( '%1$s could not be reached to check whether %2$s still exists. The choices below assume it does.', 'termconnect' ), $info['peer'], $info['remote'] ),
        );
    }

    /**
     * The "let it end" description's note when a vocabulary would delegate a stub again.
     *
     * @since 1.0.0
     * @param array|null $claim First source that would claim the stub, or null.
     * @return string
     */
    protected static function reclaim_note( ?array $claim ): string {
        return null !== $claim ? ' ' . __( 'A redirect stub would be delegated again, so this needs no stub.', 'termconnect' ) : '';
    }

    /**
     * Add TermConnect's section to the check step, when it has anything to show.
     *
     * @since 1.0.0
     * @param array $sections Sections so far.
     * @param array $notices  Notices.
     * @param array $fields   Fields.
     * @return array
     */
    protected static function add_section( array $sections, array $notices, array $fields ): array {
        if ( $fields || $notices ) {
            $sections[] = array(
                'id'      => self::SECTION,
                'title'   => __( 'TermConnect', 'termconnect' ),
                'notices' => $notices,
                'fields'  => $fields,
            );
        }
        return $sections;
    }

    /**
     * Filter htax_merge_terms_messages / htax_convert_terms_messages: report what happened
     * to delegations.
     *
     * @since 1.0.0
     * @param string[] $messages Messages.
     * @return string[]
     */
    public static function messages( $messages ) {
        $messages         = array_merge( (array) $messages, static::$messages );
        static::$messages = array();
        return $messages;
    }
}
