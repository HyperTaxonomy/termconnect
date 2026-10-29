<?php
/**
 * Shared Vocabularies — AJAX handlers.
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_ajax_tc_sv_save',                    'tc_sv_ajax_save' );
add_action( 'wp_ajax_tc_sv_delete',                  'tc_sv_ajax_delete' );
add_action( 'wp_ajax_tc_sv_sync',                    'tc_sv_ajax_sync' );
add_action( 'wp_ajax_tc_sv_lookup_root',             'tc_sv_ajax_lookup_root' );
add_action( 'wp_ajax_tc_sv_reorder',                 'tc_sv_ajax_reorder' );
add_action( 'wp_ajax_tc_sv_merge_proposal',          'tc_sv_ajax_merge_proposal' );
add_action( 'wp_ajax_tc_sv_dismiss_merge',           'tc_sv_ajax_dismiss_merge' );
add_action( 'wp_ajax_tc_sv_fetch_remote_taxonomies', 'tc_sv_ajax_fetch_remote_taxonomies' );
add_action( 'wp_ajax_tc_sv_fetch_remote_groups',     'tc_sv_ajax_fetch_remote_groups' );
add_action( 'wp_ajax_tc_sv_search_remote_terms',     'tc_sv_ajax_search_remote_terms' );
add_action( 'wp_ajax_tc_sv_fetch_local_groups',      'tc_sv_ajax_fetch_local_groups' );

/**
 * Save (create or update) a vocabulary source.
 *
 * Runs a conflict check on first save. If conflicts exist and the user hasn't
 * confirmed them, returns the conflict list instead of saving.
 */
function tc_sv_ajax_save(): void {
    check_ajax_referer( 'tc_sv_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $id                  = sanitize_key( wp_unslash( $_POST['id'] ?? '' ) );
    $sv_name             = sanitize_text_field( wp_unslash( $_POST['sv_name'] ?? '' ) );
    $sv_description      = sanitize_textarea_field( wp_unslash( $_POST['sv_description'] ?? '' ) );
    $peer_site_slug             = sanitize_key( wp_unslash( $_POST['peer_site_slug'] ?? '' ) );
    $taxonomy            = sanitize_key( wp_unslash( $_POST['taxonomy'] ?? '' ) );
    $scope_raw           = wp_unslash( $_POST['scope'] ?? '' );
    $scope               = in_array( $scope_raw, array( 'full', 'remote_group', 'roots', 'terms', 'local_group' ), true ) ? $scope_raw : 'full';
    $remote_group_slug   = sanitize_title( wp_unslash( $_POST['remote_group_slug'] ?? '' ) );
    $remote_group_id     = absint( $_POST['remote_group_id'] ?? 0 );
    $remote_group_name   = sanitize_text_field( wp_unslash( $_POST['remote_group_name'] ?? '' ) );
    $misplacement_raw    = wp_unslash( $_POST['misplacement'] ?? '' );
    $misplacement        = in_array( $misplacement_raw, array( 'flag', 'link-only', 're-parent' ), true ) ? $misplacement_raw : 'flag';
    $orphan_action_raw   = wp_unslash( $_POST['orphan_action'] ?? '' );
    $orphan_action       = in_array( $orphan_action_raw, array( 'flag', 'delete_clean', 'delete' ), true ) ? $orphan_action_raw : 'flag';
    $sync_interval_raw   = wp_unslash( $_POST['sync_interval'] ?? '' );
    $sync_interval       = in_array( $sync_interval_raw, array( 'twicedaily', 'daily', 'weekly' ), true ) ? $sync_interval_raw : 'daily';
    $conflict_confirmed  = ! empty( $_POST['conflict_confirmed'] );
    $group_id            = absint( $_POST['group_id'] ?? 0 );
    $group_name          = sanitize_text_field( wp_unslash( $_POST['group_name'] ?? '' ) );
    $sync_descendants             = ! empty( $_POST['sync_descendants'] );
    $sync_name                    = ! isset( $_POST['sync_name'] ) || ! empty( $_POST['sync_name'] );
    $sync_description             = ! isset( $_POST['sync_description'] ) || ! empty( $_POST['sync_description'] );
    $relationship_delegation      = ! empty( $_POST['relationship_delegation'] );
    $cross_taxonomy_relationships = ! empty( $_POST['cross_taxonomy_relationships'] );
    $roots_json          = wp_unslash( $_POST['roots'] ?? '[]' );
    $roots_raw           = json_decode( $roots_json, true );
    if ( ! is_array( $roots_raw ) ) {
        wp_send_json_error( array( 'message' => __( 'Invalid data.', 'termconnect' ) ) );
    }
    $roots               = array();

    if ( ! $peer_site_slug ) {
        wp_send_json_error( array( 'message' => __( 'Peer site is required.', 'termconnect' ) ) );
    }
    $taxonomy_optional = in_array( $scope, array( 'local_group', 'remote_group' ), true );
    if ( ! $taxonomy_optional && ! $taxonomy ) {
        wp_send_json_error( array( 'message' => __( 'Taxonomy is required.', 'termconnect' ) ) );
    }
    if ( 'local_group' === $scope && ! $group_id ) {
        wp_send_json_error( array( 'message' => __( 'Select a HyperTaxonomy group.', 'termconnect' ) ) );
    }
    if ( 'remote_group' === $scope && ! $remote_group_slug ) {
        wp_send_json_error( array( 'message' => __( 'Select a remote group.', 'termconnect' ) ) );
    }

    if ( in_array( $scope, array( 'roots', 'terms' ), true ) && is_array( $roots_raw ) ) {
        foreach ( $roots_raw as $r ) {
            if ( ! empty( $r['remote_term_id'] ) && ! empty( $r['remote_slug'] ) ) {
                $roots[] = array(
                    'remote_term_id' => absint( $r['remote_term_id'] ),
                    'remote_slug'    => sanitize_title( $r['remote_slug'] ),
                    'remote_name'    => sanitize_text_field( $r['remote_name'] ?? '' ),
                );
            }
        }
        if ( empty( $roots ) ) {
            wp_send_json_error( array( 'message' => __( 'Add at least one term.', 'termconnect' ) ) );
        }
    }

    $is_new = empty( $id );
    $source = array(
        'id'                           => $is_new ? wp_generate_uuid4() : $id,
        'sv_name'                      => $sv_name,
        'sv_description'               => $sv_description,
        'peer_site_slug'               => $peer_site_slug,
        'taxonomy'                     => $taxonomy,
        'scope'                        => $scope,
        'roots'                        => $roots,
        'group_id'                     => $group_id,
        'group_name'                   => $group_name,
        'sync_descendants'             => $sync_descendants,
        'sync_name'                    => $sync_name,
        'sync_description'             => $sync_description,
        'misplacement'                 => $misplacement,
        'orphan_action'                => $orphan_action,
        'sync_interval'                => $sync_interval,
        'relationship_delegation'      => $relationship_delegation,
        'cross_taxonomy_relationships' => $cross_taxonomy_relationships,
        'last_sync'                    => 0,
        'status'                       => 'active',
    );
    if ( 'remote_group' === $scope ) {
        $source['remote_group_slug'] = $remote_group_slug;
        $source['remote_group_id']   = $remote_group_id;
        $source['remote_group_name'] = $remote_group_name;
        // local_group_id populated by sync after group is resolved.
    }

    // Scope-switching warnings for existing sources.
    if ( ! $is_new && ! $conflict_confirmed ) {
        $sources_existing = get_option( 'tc_shared_vocabularies', array() );
        foreach ( $sources_existing as $ex ) {
            if ( ( $ex['id'] ?? '' ) !== $id ) { continue; }
            $old_scope = $ex['scope'] ?? 'full';
            $warning   = '';
            if ( 'full' !== $old_scope && 'full' === $scope ) {
                $warning = __( 'Switching to full taxonomy will consolidate all individually configured terms. Existing delegations will be kept.', 'termconnect' );
            } elseif ( 'full' === $old_scope && 'full' !== $scope ) {
                $warning = __( 'Switching to individual terms will stop tracking terms that are not explicitly listed. Existing delegations will be kept until manually removed.', 'termconnect' );
            } elseif ( 'roots' === $old_scope && 'terms' === $scope ) {
                $warning = __( 'Switching to individual terms (no descendants) will stop syncing descendants of configured roots. Existing descendant delegations will be kept until manually removed.', 'termconnect' );
            }
            if ( $warning ) {
                wp_send_json_success( array( 'scope_warning' => $warning ) );
            }
            break;
        }
    }

    // Conflict check on new sources (or if not yet confirmed).
    if ( $is_new && ! $conflict_confirmed ) {
        $conflicts = tc_sv_conflict_check( $source );
        if ( ! empty( $conflicts ) ) {
            wp_send_json_success( array( 'conflicts' => $conflicts ) );
        }
    }

    if ( ! tc_acquire_mysql_lock( 'tc_sv_write_lock' ) ) {
        wp_send_json_error( array( 'message' => __( 'Another update is in progress. Please try again.', 'termconnect' ) ) );
        return;
    }
    try {
        $sources = get_option( 'tc_shared_vocabularies', array() );

        // Check for duplicate source (excluding this source when editing).
        $check_group_id = (int) ( $source['group_id'] ?? 0 );
        foreach ( $sources as $existing ) {
            if ( $existing['id'] === $source['id'] ) {
                continue;
            }
            if ( $existing['peer_site_slug'] !== $peer_site_slug ) {
                continue;
            }
            $ex_scope = $existing['scope'] ?? '';
            $is_duplicate = false;
            if ( 'local_group' === $scope ) {
                $is_duplicate = 'local_group' === $ex_scope && (int) ( $existing['group_id'] ?? 0 ) === $check_group_id;
            } elseif ( 'remote_group' === $scope ) {
                $is_duplicate = 'remote_group' === $ex_scope && ( $existing['remote_group_slug'] ?? '' ) === $remote_group_slug;
            } elseif ( 'full' === $scope ) {
                $is_duplicate = 'full' === $ex_scope && ( $existing['taxonomy'] ?? '' ) === $taxonomy;
            }
            if ( $is_duplicate ) {
                wp_send_json_error( array( 'message' => __( 'A source for this peer site and group (or taxonomy) already exists.', 'termconnect' ) ) );
                return;
            }
        }

        if ( $is_new ) {
            $sources[] = $source;
        } else {
            $old_sync_name = true;
            $old_sync_desc = true;
            foreach ( $sources as &$s ) {
                if ( $s['id'] === $id ) {
                    $old_sync_name       = ! isset( $s['sync_name'] ) || ! empty( $s['sync_name'] );
                    $old_sync_desc       = ! isset( $s['sync_description'] ) || ! empty( $s['sync_description'] );
                    $source['last_sync']        = $s['last_sync'] ?? 0;
                    $source['last_sync_result'] = $s['last_sync_result'] ?? '';
                    $s = $source;
                    break;
                }
            }
            unset( $s );

            // Propagate sync flag changes to all delegated terms owned by this source.
            if ( $old_sync_name !== $sync_name || $old_sync_desc !== $sync_description ) {
                global $wpdb;
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s",
                    TC_META_IDENTITY_SOURCE
                ) );
                foreach ( $rows as $row ) {
                    $src_meta = maybe_unserialize( $row->meta_value );
                    if ( is_array( $src_meta ) && ( $src_meta['sv_source_id'] ?? '' ) === $id ) {
                        tc_update_identity_sync_flags( (int) $row->term_id, $sync_name, $sync_description );
                    }
                }
            }
        }

        update_option( 'tc_shared_vocabularies', tc_sv_sort_sources( $sources ) );
    } finally {
        tc_release_mysql_lock( 'tc_sv_write_lock' );
    }
    wp_send_json_success( array( 'id' => $source['id'] ) );
}

/**
 * Delete a vocabulary source (keeps existing delegations).
 */
function tc_sv_ajax_delete(): void {
    check_ajax_referer( 'tc_sv_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $id = sanitize_key( wp_unslash( $_POST['id'] ?? '' ) );

    // Capture the source config before removing it (needed for cleanup).
    $deleted_source = null;

    if ( ! tc_acquire_mysql_lock( 'tc_sv_write_lock' ) ) {
        wp_send_json_error( array( 'message' => __( 'Another update is in progress. Please try again.', 'termconnect' ) ) );
        return;
    }
    try {
        $sources = get_option( 'tc_shared_vocabularies', array() );
        foreach ( $sources as $s ) {
            if ( $s['id'] === $id ) { $deleted_source = $s; break; }
        }
        $sources = array_values( array_filter( $sources, static fn( $s ) => $s['id'] !== $id ) );
        update_option( 'tc_shared_vocabularies', $sources );
    } finally {
        tc_release_mysql_lock( 'tc_sv_write_lock' );
    }

    // For remote_group sources: remove synced terms from the local group before clearing identities.
    if ( $deleted_source && ( $deleted_source['scope'] ?? '' ) === 'remote_group' ) {
        $local_group_id = absint( $deleted_source['local_group_id'] ?? 0 );
        if ( $local_group_id && function_exists( 'tc_sv_remove_remote_group_memberships' ) ) {
            tc_sv_remove_remote_group_memberships( $local_group_id, $id );
        }
    }

    tc_sv_remove_delegations_by_source( $id );

    wp_send_json_success();
}

/**
 * Manually trigger a sync for one source.
 */
function tc_sv_ajax_sync(): void {
    check_ajax_referer( 'tc_sv_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $id      = sanitize_key( wp_unslash( $_POST['id'] ?? '' ) );
    $sources = get_option( 'tc_shared_vocabularies', array() );
    $source  = null;
    foreach ( $sources as $s ) {
        if ( $s['id'] === $id ) { $source = $s; break; }
    }

    if ( ! $source ) {
        wp_send_json_error( array( 'message' => __( 'Source not found.', 'termconnect' ) ) );
    }

    $result = tc_sv_sync_source( $source );
    wp_send_json_success( $result );
}

/**
 * Look up a root term by slug on a peer site (used in the Add Source form).
 */
function tc_sv_ajax_lookup_root(): void {
    check_ajax_referer( 'tc_sv_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $peer_site_slug  = sanitize_key( wp_unslash( $_POST['peer_site_slug'] ?? '' ) );
    $taxonomy = sanitize_key( wp_unslash( $_POST['taxonomy'] ?? '' ) );
    $slug     = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );

    if ( ! $peer_site_slug || ! $taxonomy || ! $slug ) {
        wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'termconnect' ) ) );
    }

    $remote = tc_fetch_remote_identity( $peer_site_slug, $taxonomy, $slug );
    if ( is_wp_error( $remote ) ) {
        wp_send_json_error( array( 'message' => $remote->get_error_message() ) );
    }

    if ( ( $remote['type'] ?? '' ) === 'redirect' ) {
        wp_send_json_error( array( 'message' => __( 'Remote term has moved. Try the new slug.', 'termconnect' ) ) );
    }

    wp_send_json_success( array(
        'term_id' => $remote['term_id'],
        'slug'    => $remote['slug'],
        'name'    => $remote['name'],
    ) );
}

/**
 * Reorder sources within a scope tab after drag-to-reorder.
 *
 * Accepts an ordered list of source IDs for a given scope and updates the stored
 * option so that position within the scope reflects the new order.
 */
function tc_sv_ajax_reorder(): void {
    check_ajax_referer( 'tc_sv_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $scope = in_array( wp_unslash( $_POST['scope'] ?? '' ), array( 'full', 'remote_group', 'local_group', 'roots', 'terms' ), true )
        ? sanitize_key( wp_unslash( $_POST['scope'] ) )
        : '';
    $ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] )
        ? array_map( 'sanitize_key', array_map( 'wp_unslash', $_POST['ids'] ) )
        : array();

    if ( ! $scope || empty( $ids ) ) {
        wp_send_json_success(); // Nothing to do.
    }

    if ( ! tc_acquire_mysql_lock( 'tc_sv_write_lock' ) ) {
        wp_send_json_error( array( 'message' => __( 'Another update is in progress. Please try again.', 'termconnect' ) ) );
        return;
    }
    try {
        $sources  = get_option( 'tc_shared_vocabularies', array() );
        $in_scope = array_filter( $sources, static fn( $s ) => ( $s['scope'] ?? '' ) === $scope );
        $other    = array_filter( $sources, static fn( $s ) => ( $s['scope'] ?? '' ) !== $scope );

        // Re-order in_scope according to the submitted ID list.
        $indexed  = array();
        foreach ( $in_scope as $s ) {
            $indexed[ $s['id'] ] = $s;
        }
        $reordered = array();
        foreach ( $ids as $id ) {
            if ( isset( $indexed[ $id ] ) ) {
                $reordered[] = $indexed[ $id ];
                unset( $indexed[ $id ] );
            }
        }
        // Append any that weren't in the submitted list (shouldn't happen, but be safe).
        foreach ( $indexed as $s ) {
            $reordered[] = $s;
        }

        update_option( 'tc_shared_vocabularies', tc_sv_sort_sources( array_merge( array_values( $other ), $reordered ) ) );
    } finally {
        tc_release_mysql_lock( 'tc_sv_write_lock' );
    }
    wp_send_json_success();
}

/**
 * Execute a merge proposal: merge orphaned term into its candidate using HyperTaxonomy.
 *
 * Requires HyperTaxonomy to be active (htax_merge_terms function).
 */
function tc_sv_ajax_merge_proposal(): void {
    check_ajax_referer( 'tc_sv_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    if ( ! function_exists( 'htax_merge_terms' ) ) {
        wp_send_json_error( array( 'message' => __( 'HyperTaxonomy is required to merge terms.', 'termconnect' ) ) );
    }

    $proposal_id = sanitize_key( wp_unslash( $_POST['proposal_id'] ?? '' ) );

    if ( ! tc_acquire_mysql_lock( 'tc_sv_write_lock' ) ) {
        wp_send_json_error( array( 'message' => __( 'Another update is in progress. Please try again.', 'termconnect' ) ) );
        return;
    }

    try {
        $proposals = get_option( 'tc_merge_proposals', array() );

        if ( ! isset( $proposals[ $proposal_id ] ) ) {
            tc_release_mysql_lock( 'tc_sv_write_lock' );
            wp_send_json_error( array( 'message' => __( 'Proposal not found.', 'termconnect' ) ) );
        }

        $proposal = $proposals[ $proposal_id ];
        $result   = tc_sv_resolve_merge_proposal( $proposal );

        if ( is_wp_error( $result ) ) {
            tc_release_mysql_lock( 'tc_sv_write_lock' );
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        unset( $proposals[ $proposal_id ] );
        update_option( 'tc_merge_proposals', $proposals, false );
    } finally {
        // Covers the normal completion and any exception from htax_merge_terms().
        // The wp_send_json_error() exits above release the lock manually because
        // die() bypasses finally.
        tc_release_mysql_lock( 'tc_sv_write_lock' );
    }

    wp_send_json_success( array(
        'posts_reassigned'  => $result['posts_reassigned'],
        'redirects_created' => $result['redirects_created'],
    ) );
}

/**
 * Fetch all local HyperTaxonomy groups as a flat list with id, name, parent.
 * Used to build the hierarchical group picker in Shared Vocabularies.
 */
function tc_sv_ajax_fetch_local_groups(): void {
    check_ajax_referer( 'tc_sv_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $groups = get_terms( array(
        'taxonomy'   => 'group',
        'hide_empty' => false,
        'orderby'    => 'term_id',
        'order'      => 'ASC',
        'number'     => 0,
    ) );

    if ( is_wp_error( $groups ) || empty( $groups ) ) {
        wp_send_json_success( array() );
    }

    $result = array_map( static fn( $g ) => array(
        'id'     => $g->term_id,
        'name'   => html_entity_decode( $g->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
        'parent' => $g->parent,
    ), $groups );

    wp_send_json_success( $result );
}

/**
 * Fetch public taxonomies from a peer WordPress site.
 *
 * Returns [{slug, name, hierarchical}] sorted by name.
 */
function tc_sv_ajax_fetch_remote_taxonomies(): void {
    check_ajax_referer( 'tc_sv_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $peer_site_slug   = sanitize_key( wp_unslash( $_POST['peer_site_slug'] ?? '' ) );
    $link_type = $peer_site_slug ? tc_get_link_type( $peer_site_slug ) : null;
    $origin    = $link_type ? tc_external_get_site_origin( $link_type['uri_prefix'] ?? '' ) : '';

    if ( ! $origin ) {
        wp_send_json_error( array( 'message' => __( 'Invalid peer site.', 'termconnect' ) ) );
    }

    $url = trailingslashit( $origin ) . 'wp-json/wp/v2/taxonomies';

    $response = wp_remote_get( $url, array(
        'timeout'    => 10,
        'user-agent' => 'TermConnect/' . TC_VERSION,
    ) );

    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        wp_send_json_error( array( 'message' => __( 'Could not fetch taxonomies from peer.', 'termconnect' ) ) );
    }

    $body = tc_external_json_decode( wp_remote_retrieve_body( $response ) );
    if ( ! is_array( $body ) ) {
        wp_send_json_error( array( 'message' => __( 'Invalid response from peer.', 'termconnect' ) ) );
    }

    // WP REST /taxonomies returns an object keyed by slug.
    $taxonomies = array();
    foreach ( $body as $slug => $data ) {
        $taxonomies[] = array(
            'slug'         => sanitize_key( $data['slug'] ?? $slug ),
            'name'         => sanitize_text_field( $data['name'] ?? $slug ),
            'hierarchical' => (bool) ( $data['hierarchical'] ?? false ),
            'show_ui'      => isset( $data['show_ui'] ) ? (bool) $data['show_ui'] : null,
            'tc_content'   => isset( $data['tc_content'] ) ? (bool) $data['tc_content'] : null,
        );
    }
    usort( $taxonomies, static fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );

    wp_send_json_success( $taxonomies );
}

/**
 * Fetch all HyperTaxonomy groups from a peer site for the Remote Groups dropdown.
 *
 * Returns [{id, slug, name, parent}] — up to 100 groups. Parent ID 0 means top-level.
 * The client builds the tree and renders indented options from this flat list.
 */
function tc_sv_ajax_fetch_remote_groups(): void {
    check_ajax_referer( 'tc_sv_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $peer_site_slug = sanitize_key( wp_unslash( $_POST['peer_site_slug'] ?? '' ) );
    $link_type      = $peer_site_slug ? tc_get_link_type( $peer_site_slug ) : null;
    $origin         = $link_type ? tc_external_get_site_origin( $link_type['uri_prefix'] ?? '' ) : '';

    if ( ! $origin ) {
        wp_send_json_error( array( 'message' => __( 'Invalid peer site.', 'termconnect' ) ) );
    }

    $rest_base = tc_external_get_taxonomy_rest_base( 'group' );
    $url = trailingslashit( $origin ) . 'wp-json/wp/v2/' . $rest_base . '?' . http_build_query( array(
        'per_page' => 100,
        '_fields'  => 'id,slug,name,parent',
    ) );

    $response = wp_remote_get( $url, array(
        'timeout'    => 10,
        'user-agent' => 'TermConnect/' . TC_VERSION,
        'headers'    => array( 'X-TermConnect' => TC_VERSION ),
    ) );

    if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        wp_send_json_error( array( 'message' => __( 'Could not fetch groups from peer.', 'termconnect' ) ) );
    }

    $body = tc_external_json_decode( wp_remote_retrieve_body( $response ) );
    if ( ! is_array( $body ) ) {
        wp_send_json_error( array( 'message' => __( 'Invalid response from peer.', 'termconnect' ) ) );
    }

    $groups = array_map( static fn( $g ) => array(
        'id'     => absint( $g['id'] ?? 0 ),
        'slug'   => sanitize_title( $g['slug'] ?? '' ),
        'name'   => sanitize_text_field( html_entity_decode( $g['name'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
        'parent' => absint( $g['parent'] ?? 0 ),
    ), $body );

    wp_send_json_success( $groups );
}

/**
 * Search terms on a peer site by keyword (for root/individual term autocomplete).
 *
 * Returns [{id, slug, name}] capped at 10 results.
 */
function tc_sv_ajax_search_remote_terms(): void {
    check_ajax_referer( 'tc_sv_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $peer_site_slug  = sanitize_key( wp_unslash( $_POST['peer_site_slug'] ?? '' ) );
    $taxonomy = sanitize_key( wp_unslash( $_POST['taxonomy'] ?? '' ) );
    $q        = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );

    if ( ! $peer_site_slug || ! $taxonomy || strlen( $q ) < 2 ) {
        wp_send_json_success( array() );
    }

    $link_type = tc_get_link_type( $peer_site_slug );
    if ( ! $link_type ) {
        wp_send_json_error( array( 'message' => __( 'Link type not found.', 'termconnect' ) ) );
    }

    $origin = tc_external_get_site_origin( $link_type['uri_prefix'] ?? '' );
    if ( ! $origin ) {
        wp_send_json_error( array( 'message' => __( 'Cannot resolve peer URL.', 'termconnect' ) ) );
    }

    $rest_base = tc_external_get_taxonomy_rest_base( $taxonomy );
    $url = trailingslashit( $origin ) . 'wp-json/wp/v2/' . $rest_base . '?' . http_build_query( array(
        'search'   => $q,
        'per_page' => 10,
        '_fields'  => 'id,slug,name',
    ) );

    $response = wp_remote_get( $url, array(
        'timeout'    => 10,
        'user-agent' => 'TermConnect/' . TC_VERSION,
    ) );

    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        wp_send_json_success( array() );
    }

    $body = tc_external_json_decode( wp_remote_retrieve_body( $response ) );
    if ( ! is_array( $body ) ) {
        wp_send_json_success( array() );
    }

    $results = array_map( static fn( $t ) => array(
        'id'   => absint( $t['id'] ),
        'slug' => sanitize_title( $t['slug'] ?? '' ),
        'name' => sanitize_text_field( html_entity_decode( $t['name'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
    ), $body );

    wp_send_json_success( $results );
}

/**
 * Dismiss a merge proposal without merging.
 */
function tc_sv_ajax_dismiss_merge(): void {
    check_ajax_referer( 'tc_sv_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $proposal_id = sanitize_key( wp_unslash( $_POST['proposal_id'] ?? '' ) );

    if ( ! tc_acquire_mysql_lock( 'tc_sv_write_lock' ) ) {
        wp_send_json_error( array( 'message' => __( 'Another update is in progress. Please try again.', 'termconnect' ) ) );
        return;
    }
    try {
        $proposals = get_option( 'tc_merge_proposals', array() );

        if ( ! isset( $proposals[ $proposal_id ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Proposal not found.', 'termconnect' ) ) );
            return;
        }

        unset( $proposals[ $proposal_id ] );
        update_option( 'tc_merge_proposals', $proposals, false );
    } finally {
        tc_release_mysql_lock( 'tc_sv_write_lock' );
    }
    wp_send_json_success();
}
