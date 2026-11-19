<?php
/**
 * Which Shared Vocabulary sources would claim a term, for merges and conversions.
 *
 * A term's delegation records the one source that claimed it (first wins, in tab order and
 * then row order), and other sources skip it while it has one. When that delegation ends
 * (the term is deleted, or a stub loses it), or a term gets a manual delegation, any other
 * source covering the slug claims the term on its next sync: it creates the term again, or
 * delegates the term that has the slug, overwriting a manual delegation. This finds those
 * sources, in the order they'd get to it.
 *
 * Local Groups sources are left out: they delegate only members of a local group, don't
 * create terms, and don't overwrite another peer's manual delegation.
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TConn_Vocabulary_Coverage {

    /**
     * Peer data fetched in this request, by key.
     *
     * @var array<string, mixed>
     */
    private static array $fetched = array();

    /**
     * Sources that would claim a term with this slug in this taxonomy, in priority order.
     *
     * @since 1.0.0
     * @param string $taxonomy Local taxonomy.
     * @param string $slug     Local term slug (a vocabulary gives its terms the remote slug).
     * @param array  $skip     Rows to leave out, as source ID => remote slug: a root or term
     *                         row that the operation removes.
     * @return array{claimants: array<int, array{source: array, peer: string}>, unreachable: string[]}
     *               Unreachable: names of peers that couldn't be asked (their sources are
     *               left out of the claimants).
     */
    public static function claimants( string $taxonomy, string $slug, array $skip = array() ): array {
        $claimants   = array();
        $unreachable = array();
        if ( '' === $slug ) {
            return array( 'claimants' => $claimants, 'unreachable' => $unreachable );
        }
        if ( ! function_exists( 'tconn_sv_sort_sources' ) ) {
            require_once TCONN_PLUGIN_DIR . 'includes/admin/shared-vocabulary-sync.php';
        }

        foreach ( tconn_sv_sort_sources( (array) get_option( 'tconn_shared_vocabularies', array() ) ) as $source ) {
            $scope = (string) ( $source['scope'] ?? 'full' );
            if ( 'local_group' === $scope ) {
                continue;
            }
            if ( 'remote_group' !== $scope && $taxonomy !== ( $source['taxonomy'] ?? '' ) ) {
                continue;
            }
            $peer_slug = (string) ( $source['peer_site_slug'] ?? '' );
            $link_type = tconn_get_link_type( $peer_slug );
            $peer      = (string) ( $link_type['label'] ?? $peer_slug );
            $covers    = self::covers( $source, $taxonomy, $slug, (string) ( $skip[ $source['id'] ?? '' ] ?? '' ) );
            if ( null === $covers ) {
                $unreachable[ $peer ] = $peer;
            } elseif ( $covers ) {
                $claimants[] = array( 'source' => $source, 'peer' => $peer );
            }
        }
        return array( 'claimants' => $claimants, 'unreachable' => array_values( $unreachable ) );
    }

    /**
     * A notice for peers that couldn't be asked.
     *
     * @since 1.0.0
     * @param string[] $peers Peer names.
     * @return array|null Notice, or null when there are none.
     */
    public static function unreachable_notice( array $peers ): ?array {
        $peers = array_values( array_unique( $peers ) );
        if ( ! $peers ) {
            return null;
        }
        return array(
            'type' => 'warning',
            /* translators: %s: comma-separated peer site names */
            'text' => sprintf( __( 'Could not reach %s to check what its Shared Vocabularies cover. The choices below leave those out.', 'termconnect' ), implode( ', ', $peers ) ),
        );
    }

    /**
     * Whether one source covers a slug.
     *
     * @param array  $source    Source config.
     * @param string $taxonomy  Local taxonomy.
     * @param string $slug      Slug.
     * @param string $skip_slug Remote slug of a row of this source to leave out, or ''.
     * @return bool|null Null when the peer couldn't be asked.
     */
    private static function covers( array $source, string $taxonomy, string $slug, string $skip_slug ): ?bool {
        $scope = (string) ( $source['scope'] ?? 'full' );
        $rows  = array_values( array_filter(
            (array) ( $source['roots'] ?? array() ),
            static fn( $row ) => '' === $skip_slug || ( $row['remote_slug'] ?? '' ) !== $skip_slug
        ) );

        if ( 'terms' === $scope ) {
            return in_array( $slug, array_map( static fn( $row ) => (string) ( $row['remote_slug'] ?? '' ), $rows ), true );
        }

        $origin = tconn_peer_origin( (string) ( $source['peer_site_slug'] ?? '' ) );
        if ( '' === $origin ) {
            return false;
        }

        if ( 'remote_group' === $scope ) {
            $members = self::group_members( $origin, (string) ( $source['remote_group_slug'] ?? '' ) );
            if ( null === $members ) {
                return null;
            }
            foreach ( $members as $member ) {
                if ( ( $member['taxonomy'] ?? '' ) === $taxonomy && ( $member['slug'] ?? '' ) === $slug ) {
                    return true;
                }
            }
            return false;
        }

        if ( 'roots' === $scope && ! $rows ) {
            return false;
        }
        $tree = self::taxonomy_tree( $origin, $taxonomy );
        if ( null === $tree ) {
            return null;
        }
        $by_id = array();
        foreach ( $tree as $term ) {
            $by_id[ (int) $term['id'] ] = $term;
        }
        if ( 'full' === $scope ) {
            return in_array( $slug, array_map( static fn( $term ) => (string) ( $term['slug'] ?? '' ), $by_id ), true );
        }

        // Term Hierarchies: the slug is one of the roots or under one.
        return in_array(
            $slug,
            array_map( static fn( $id ) => (string) ( $by_id[ $id ]['slug'] ?? '' ), tconn_sv_subtree_ids( $rows, $by_id ) ),
            true
        );
    }

    /**
     * A peer's terms in a taxonomy, once per request.
     *
     * @param string $origin   Peer origin.
     * @param string $taxonomy Taxonomy.
     * @return array|null Null when the peer couldn't be asked.
     */
    private static function taxonomy_tree( string $origin, string $taxonomy ): ?array {
        $key = 'tree|' . $origin . '|' . $taxonomy;
        if ( ! array_key_exists( $key, self::$fetched ) ) {
            $terms                 = tconn_sv_fetch_taxonomy( $origin, $taxonomy );
            self::$fetched[ $key ] = is_wp_error( $terms ) ? null : $terms;
        }
        return self::$fetched[ $key ];
    }

    /**
     * A remote group's members, once per request.
     *
     * @param string $origin     Peer origin.
     * @param string $group_slug Remote group slug.
     * @return array|null Members ({ id, slug, taxonomy, ... }), or null when the peer couldn't be asked.
     */
    private static function group_members( string $origin, string $group_slug ): ?array {
        $key = 'group|' . $origin . '|' . $group_slug;
        if ( ! array_key_exists( $key, self::$fetched ) ) {
            $body                  = tconn_sv_fetch_group_members( $origin, $group_slug );
            self::$fetched[ $key ] = is_wp_error( $body ) ? null : (array) $body['members'];
        }
        return self::$fetched[ $key ];
    }
}
