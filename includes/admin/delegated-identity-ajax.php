<?php
/**
 * Delegated Identity — AJAX handlers.
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_ajax_tc_di_preview_term',    'tc_ajax_di_preview_term' );
add_action( 'wp_ajax_tc_di_set_source',      'tc_ajax_di_set_source' );
add_action( 'wp_ajax_tc_di_remove',          'tc_ajax_di_remove' );
add_action( 'wp_ajax_tc_di_refresh',         'tc_ajax_di_refresh' );
add_action( 'wp_ajax_tc_di_resync_all',      'tc_ajax_di_resync_all' );
add_action( 'wp_ajax_tc_di_update_settings', 'tc_ajax_di_update_settings' );
add_action( 'wp_ajax_tc_di_check_rename',    'tc_ajax_di_check_rename' );
add_action( 'wp_ajax_tc_di_relink_rename',   'tc_ajax_di_relink_rename' );
add_action( 'wp_ajax_tc_di_add_link',        'tc_ajax_di_add_link' );
add_action( 'wp_ajax_tc_di_redelegate',      'tc_ajax_di_redelegate' );
add_action( 'wp_ajax_tc_di_follow_redirect', 'tc_ajax_di_follow_redirect' );
add_action( 'wp_ajax_tc_di_decline_redirect', 'tc_ajax_di_decline_redirect' );

/**
 * Fetch a preview of a remote term's identity data before commit.
 *
 * POST params: term_id, peer_site_slug, remote_slug, nonce (tc_admin).
 */
function tc_ajax_di_preview_term(): void {
    check_ajax_referer( 'tc_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $term_id     = absint( $_POST['term_id'] ?? 0 );
    $peer_site_slug     = sanitize_key( wp_unslash( $_POST['peer_site_slug'] ?? '' ) );
    $remote_slug = sanitize_title( wp_unslash( $_POST['remote_slug'] ?? '' ) );

    if ( ! $term_id || ! $peer_site_slug || ! $remote_slug ) {
        wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'termconnect' ) ) );
    }

    $term = get_term( $term_id );
    if ( ! $term || is_wp_error( $term ) ) {
        wp_send_json_error( array( 'message' => __( 'Term not found.', 'termconnect' ) ) );
    }

    $remote = tc_fetch_remote_identity( $peer_site_slug, $term->taxonomy, $remote_slug );

    if ( is_wp_error( $remote ) ) {
        wp_send_json_error( array( 'message' => $remote->get_error_message() ) );
    }

    if ( ( $remote['type'] ?? '' ) === 'redirect' ) {
        wp_send_json_error( array( 'message' => __( 'Remote term has moved. Refresh and try the new slug.', 'termconnect' ) ) );
    }

    wp_send_json_success( array(
        'description'     => wp_kses_post( $remote['description'] ),
        'delegated_links' => $remote['delegated_links'],
        'link'            => $remote['link'] ?? '',
    ) );
}

/**
 * Commit delegation: fetch remote data, save identity source, cache delegated links.
 *
 * POST params: term_id, peer_site_slug, remote_slug, nonce (tc_admin).
 */
function tc_ajax_di_set_source(): void {
    check_ajax_referer( 'tc_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $term_id          = absint( $_POST['term_id'] ?? 0 );
    $peer_site_slug          = sanitize_key( wp_unslash( $_POST['peer_site_slug'] ?? '' ) );
    $remote_slug      = sanitize_title( wp_unslash( $_POST['remote_slug'] ?? '' ) );
    $sync_descendants = ! empty( $_POST['sync_descendants'] );
    $sync_name        = isset( $_POST['sync_name'] ) ? (bool) wp_unslash( $_POST['sync_name'] ) : true;
    $sync_description = isset( $_POST['sync_description'] ) ? (bool) wp_unslash( $_POST['sync_description'] ) : true;

    if ( ! $term_id || ! $peer_site_slug || ! $remote_slug ) {
        wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'termconnect' ) ) );
    }

    $term = get_term( $term_id );
    if ( ! $term || is_wp_error( $term ) ) {
        wp_send_json_error( array( 'message' => __( 'Term not found.', 'termconnect' ) ) );
    }

    // Final fetch before saving.
    $remote = tc_fetch_remote_identity( $peer_site_slug, $term->taxonomy, $remote_slug );
    if ( is_wp_error( $remote ) ) {
        wp_send_json_error( array( 'message' => $remote->get_error_message() ) );
    }

    if ( ( $remote['type'] ?? '' ) === 'redirect' ) {
        wp_send_json_error( array( 'message' => __( 'Remote term has moved. Refresh and try the new slug.', 'termconnect' ) ) );
    }

    // Determine the Shared Vocabularies source ID for this delegation.
    // When possible, reuse an existing SV so that delegating multiple terms to the same
    // peer builds a single SV rather than one per term. If an SV exists but has different
    // sync settings the client is asked to choose; it re-submits with either
    // existing_sv_id (join existing) or force_new_sv (create separate SV).
    // The SV entry is only persisted after tc_set_identity_source() succeeds.
    $target_scope   = ( $sync_descendants && is_taxonomy_hierarchical( $term->taxonomy ) ) ? 'roots' : 'terms';
    $remote_term_id = absint( $remote['term_id'] ?? 0 );

    if ( ! empty( $_POST['existing_sv_id'] ) ) {
        // User explicitly chose to join an existing SV (second pass after sv_conflict prompt).
        $sv_source_id = sanitize_text_field( wp_unslash( $_POST['existing_sv_id'] ) );
    } elseif ( ! empty( $_POST['force_new_sv'] ) ) {
        // User explicitly chose to create a new SV despite the conflict.
        $sv_source_id = wp_generate_uuid4();
    } else {
        $existing_sv_id = null;
        $conflicting_sv = null;
        foreach ( get_option( 'tc_shared_vocabularies', array() ) as $src ) {
            if ( ( $src['peer_site_slug'] ?? '' ) !== $peer_site_slug ) { continue; }
            if ( ( $src['taxonomy'] ?? '' ) !== $term->taxonomy ) { continue; }
            if ( ( $src['scope'] ?? '' ) !== $target_scope ) { continue; }

            if ( (bool) ( $src['sync_name'] ?? true ) === (bool) $sync_name
                && (bool) ( $src['sync_description'] ?? true ) === (bool) $sync_description ) {
                $existing_sv_id = $src['id'];
                break; // Exact match — proceed without prompting.
            }

            if ( null === $conflicting_sv ) {
                $conflicting_sv = $src; // Remember first mismatch; keep scanning for exact match.
            }
        }

        if ( null !== $existing_sv_id ) {
            $sv_source_id = $existing_sv_id;
        } elseif ( null !== $conflicting_sv ) {
            wp_send_json_error( array(
                'code'                       => 'sv_conflict',
                'existing_sv_id'             => $conflicting_sv['id'],
                'existing_sync_name'         => (bool) ( $conflicting_sv['sync_name'] ?? true ),
                'existing_sync_description'  => (bool) ( $conflicting_sv['sync_description'] ?? true ),
            ) );
            return;
        } else {
            $sv_source_id = wp_generate_uuid4();
        }
    }

    $result = tc_set_identity_source( $term_id, $peer_site_slug, $remote_slug, $remote, $sync_descendants, $sv_source_id, $sync_name, $sync_description );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    // Identity saved — now create the vocabulary source entry.
    // Use a transient mutex to prevent concurrent requests from overwriting each other's changes.
    $lock_acquired = false;
    for ( $i = 0; $i < 3; $i++ ) {
        if ( false === get_transient( 'tc_sv_write_lock' ) ) {
            set_transient( 'tc_sv_write_lock', 1, 10 );
            $lock_acquired = true;
            break;
        }
        usleep( 200000 ); // 0.2s
    }
    if ( ! $lock_acquired ) {
        wp_send_json_error( array( 'message' => __( 'Another update is in progress. Please try again.', 'termconnect' ) ) );
        return;
    }
    try {
        $sources   = get_option( 'tc_shared_vocabularies', array() );
        $found_idx = null;
        foreach ( $sources as $idx => $src ) {
            if ( ( $src['id'] ?? '' ) === $sv_source_id ) {
                $found_idx = $idx;
                break;
            }
        }

        if ( null !== $found_idx ) {
            // Add this term's root to the existing SV if it isn't already tracked.
            if ( $remote_term_id ) {
                $tracked = array_column( $sources[ $found_idx ]['roots'], 'remote_term_id' );
                if ( ! in_array( $remote_term_id, $tracked, true ) ) {
                    $sources[ $found_idx ]['roots'][] = array(
                        'remote_term_id' => $remote_term_id,
                        'remote_slug'    => sanitize_title( $remote_slug ),
                        'remote_name'    => $remote['name'] ?? '',
                    );
                }
            }
        } else {
            $sources[] = array(
                'id'                           => $sv_source_id,
                'peer_site_slug'               => $peer_site_slug,
                'taxonomy'                     => $term->taxonomy,
                'scope'                        => $target_scope,
                'roots'                        => $remote_term_id ? array( array( 'remote_term_id' => $remote_term_id, 'remote_slug' => sanitize_title( $remote_slug ), 'remote_name' => $remote['name'] ?? '' ) ) : array(),
                'group_id'                     => 0,
                'group_name'                   => '',
                'misplacement'                 => 'flag',
                'orphan_action'                => 'flag',
                'relationship_delegation'      => false,
                'cross_taxonomy_relationships' => false,
                'sync_interval'                => 'daily',
                'sync_name'                    => (bool) $sync_name,
                'sync_description'             => (bool) $sync_description,
                'last_sync'                    => time(),
                'last_sync_result'             => tc_sv_format_sync_result( array( 'synced' => 1, 'created' => 0, 'renamed' => 0 ) ),
                'status'                       => 'active',
            );
        }
        update_option( 'tc_shared_vocabularies', tc_sv_sort_sources( $sources ) );
    } finally {
        delete_transient( 'tc_sv_write_lock' );
    }

    wp_send_json_success( array( 'message' => __( 'Identity delegated successfully.', 'termconnect' ) ) );
}

/**
 * End delegation, restoring the original description and local links.
 *
 * POST params: term_id, nonce (tc_admin).
 */
function tc_ajax_di_remove(): void {
    check_ajax_referer( 'tc_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $term_id             = absint( $_POST['term_id'] ?? 0 );
    $restore_name        = (bool) absint( $_POST['restore_name'] ?? 1 );
    $restore_description = (bool) absint( $_POST['restore_description'] ?? 1 );
    $keep_peer_links     = ! empty( $_POST['keep_peer_links'] );

    if ( ! $term_id ) {
        wp_send_json_error( array( 'message' => __( 'Invalid term ID.', 'termconnect' ) ) );
    }

    // Prevent removing identity that is locked by a full- or group-scope SV source.
    // Those delegations must be removed by editing the Shared Vocabularies source.
    $identity = tc_get_identity_source( $term_id );
    $sources  = get_option( 'tc_shared_vocabularies', array() );
    if ( $identity && ! empty( $identity['sv_source_id'] ) ) {
        foreach ( $sources as $src ) {
            if ( ( $src['id'] ?? '' ) !== $identity['sv_source_id'] ) {
                continue;
            }
            $scope = $src['scope'] ?? '';
            if ( in_array( $scope, array( 'full', 'local_group', 'remote_group' ), true ) ) {
                wp_send_json_error( array(
                    'message' => __( 'This delegation is managed by a Shared Vocabularies source and cannot be removed from the term edit screen. Edit or delete the Shared Vocabularies source instead.', 'termconnect' ),
                ), 403 );
            }
            break;
        }
    }

    // When the delegation came from a 'terms' or 'roots' vocabulary source,
    // also remove the term's entry from that source's list so the next sync
    // does not re-claim it.
    if ( $identity && ! empty( $identity['sv_source_id'] ) ) {
        $sv_source_id = $identity['sv_source_id'];
        foreach ( $sources as &$src ) {
            if ( ( $src['id'] ?? '' ) !== $sv_source_id ) {
                continue;
            }
            $scope            = $src['scope'] ?? '';
            $remote_slug_key  = $identity['remote_slug'] ?? '';
            $roots            = $src['roots'] ?? array();
            $is_listed        = false;
            foreach ( $roots as $r ) {
                if ( ( $r['remote_slug'] ?? '' ) === $remote_slug_key ) {
                    $is_listed = true;
                    break;
                }
            }
            if ( 'terms' === $scope || ( 'roots' === $scope && $is_listed ) ) {
                $src['roots'] = array_values( array_filter(
                    $roots,
                    static fn( $r ) => ( $r['remote_slug'] ?? '' ) !== $remote_slug_key
                ) );
                // Delete the source entirely if it has no terms left.
                if ( empty( $src['roots'] ) ) {
                    $sources = array_values( array_filter( $sources, static fn( $s ) => $s['id'] !== $sv_source_id ) );
                }
                update_option( 'tc_shared_vocabularies', $sources );

                // For roots scope, clear sv_source_id from all local descendants so they
                // revert to unattributed (still delegated, but no longer shown as locked).
                if ( 'roots' === $scope ) {
                    $term_obj = get_term( $term_id );
                    if ( $term_obj && ! is_wp_error( $term_obj ) ) {
                        $desc_ids = get_term_children( $term_id, $term_obj->taxonomy );
                        foreach ( (array) $desc_ids as $desc_id ) {
                            $desc_meta = get_term_meta( (int) $desc_id, TC_META_IDENTITY_SOURCE, true );
                            if ( is_array( $desc_meta ) && ( $desc_meta['sv_source_id'] ?? '' ) === $sv_source_id ) {
                                unset( $desc_meta['sv_source_id'] );
                                update_term_meta( (int) $desc_id, TC_META_IDENTITY_SOURCE, $desc_meta );
                            }
                        }
                    }
                }
            }
            break;
        }
        unset( $src );
    }

    tc_remove_identity_source( $term_id, array(
        'restore_name'        => $restore_name,
        'restore_description' => $restore_description,
        'keep_peer_links'     => $keep_peer_links,
    ) );

    wp_send_json_success( array(
        'message' => __( 'Delegation ended.', 'termconnect' ),
    ) );
}

/**
 * Re-sync all delegated terms, ignoring the cache TTL.
 *
 * POST params: nonce (tc_admin).
 */
function tc_ajax_di_resync_all(): void {
    check_ajax_referer( 'tc_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    global $wpdb;
    $term_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s",
        TC_META_IDENTITY_SOURCE
    ) );

    $total     = count( $term_ids );
    $refreshed = 0;
    $errors    = 0;
    $start     = microtime( true );
    foreach ( $term_ids as $term_id ) {
        if ( microtime( true ) - $start > 25.0 ) {
            wp_send_json_success( array(
                'refreshed'  => $refreshed,
                'total'      => $total,
                'incomplete' => true,
            ) );
        }
        $result = tc_refresh_identity_cache( (int) $term_id );
        if ( is_wp_error( $result ) ) {
            $errors++;
        } else {
            $refreshed++;
        }
    }

    wp_send_json_success( array(
        'message' => sprintf(
            /* translators: 1: refreshed count, 2: error count */
            __( '%1$d term(s) refreshed, %2$d error(s).', 'termconnect' ),
            $refreshed,
            $errors
        ),
    ) );
}

/**
 * Update sync_name and sync_description flags for one delegated term.
 *
 * POST params: term_id, sync_name, sync_description, nonce (tc_admin).
 */
function tc_ajax_di_update_settings(): void {
    check_ajax_referer( 'tc_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $term_id          = absint( $_POST['term_id'] ?? 0 );
    $sync_name        = ! empty( $_POST['sync_name'] );
    $sync_description = ! empty( $_POST['sync_description'] );

    if ( ! $term_id ) {
        wp_send_json_error( array( 'message' => __( 'Invalid term ID.', 'termconnect' ) ) );
    }

    if ( ! tc_update_identity_sync_flags( $term_id, $sync_name, $sync_description ) ) {
        wp_send_json_error( array( 'message' => __( 'No delegation found for this term.', 'termconnect' ) ) );
    }

    wp_send_json_success( array( 'message' => __( 'Settings updated.', 'termconnect' ) ) );
}

/**
 * Manually trigger a cache refresh for one term's delegated identity.
 *
 * POST params: term_id, nonce (tc_admin).
 */
function tc_ajax_di_refresh(): void {
    check_ajax_referer( 'tc_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $term_id = absint( $_POST['term_id'] ?? 0 );
    if ( ! $term_id ) {
        wp_send_json_error( array( 'message' => __( 'Invalid term ID.', 'termconnect' ) ) );
    }

    $result = tc_refresh_identity_cache( $term_id );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    wp_send_json_success( array( 'message' => __( 'Identity data refreshed.', 'termconnect' ) ) );
}

/**
 * Probe a not_found delegation by remote term ID to check whether the remote term
 * was renamed rather than deleted.
 *
 * Returns outcome A (not found by ID), B (renamed, new slug free locally), or
 * C (renamed, new slug already taken by another local term).
 *
 * POST params: term_id, nonce (tc_admin).
 */
function tc_ajax_di_check_rename(): void {
    check_ajax_referer( 'tc_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $term_id = absint( $_POST['term_id'] ?? 0 );
    if ( ! $term_id ) {
        wp_send_json_error( array( 'message' => __( 'Invalid term ID.', 'termconnect' ) ) );
    }

    $source = tc_get_identity_source( $term_id );
    if ( ! $source ) {
        wp_send_json_error( array( 'message' => __( 'No delegation found for this term.', 'termconnect' ) ) );
    }

    $remote_term_id = (int) ( $source['remote_term_id'] ?? 0 );
    if ( ! $remote_term_id ) {
        wp_send_json_error( array( 'message' => __( 'No remote term ID stored — cannot probe by ID.', 'termconnect' ) ) );
    }

    $term = get_term( $term_id );
    if ( ! $term || is_wp_error( $term ) ) {
        wp_send_json_error( array( 'message' => __( 'Local term not found.', 'termconnect' ) ) );
    }

    $remote = tc_fetch_remote_term_by_id( $source['peer_site_slug'], $term->taxonomy, $remote_term_id );

    // Outcome A: remote term not found by ID either — likely deleted.
    if ( is_wp_error( $remote ) ) {
        wp_send_json_success( array(
            'outcome' => 'A',
            'message' => __( 'Remote term not found by ID — it may have been deleted.', 'termconnect' ),
        ) );
        return;
    }

    $new_slug = $remote['slug'];
    $new_name = $remote['name'];
    $new_link = $remote['link'];

    // Check whether a local term already occupies the new slug.
    $other = get_term_by( 'slug', $new_slug, $term->taxonomy );

    if ( ! $other || is_wp_error( $other ) || (int) $other->term_id === $term_id ) {
        // Outcome B: slug is free locally.
        wp_send_json_success( array(
            'outcome'        => 'B',
            'new_slug'       => $new_slug,
            'new_name'       => $new_name,
            'new_link'       => $new_link,
            'peer_site_slug' => $source['peer_site_slug'],
        ) );
        return;
    }

    // Outcome C: another local term already has the new slug.
    wp_send_json_success( array(
        'outcome'         => 'C',
        'new_slug'        => $new_slug,
        'new_name'        => $new_name,
        'new_link'        => $new_link,
        'peer_site_slug'  => $source['peer_site_slug'],
        'other_term_id'   => (int) $other->term_id,
        'other_term_name' => $other->name,
    ) );
}

/**
 * Re-link a not_found delegation to the remote term's new slug, and rename the
 * local term's slug to match.
 *
 * POST params: term_id, new_slug, nonce (tc_admin).
 */
function tc_ajax_di_relink_rename(): void {
    check_ajax_referer( 'tc_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $term_id  = absint( $_POST['term_id'] ?? 0 );
    $new_slug = sanitize_title( wp_unslash( $_POST['new_slug'] ?? '' ) );

    if ( ! $term_id || ! $new_slug ) {
        wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'termconnect' ) ) );
    }

    $source = tc_get_identity_source( $term_id );
    if ( ! $source ) {
        wp_send_json_error( array( 'message' => __( 'No delegation found for this term.', 'termconnect' ) ) );
    }

    $term = get_term( $term_id );
    if ( ! $term || is_wp_error( $term ) ) {
        wp_send_json_error( array( 'message' => __( 'Term not found.', 'termconnect' ) ) );
    }

    // Rename local term slug to match the remote's new slug.
    $slug_result = wp_update_term( $term_id, $term->taxonomy, array( 'slug' => $new_slug ) );
    if ( is_wp_error( $slug_result ) ) {
        wp_send_json_error( array( 'message' => $slug_result->get_error_message() ) );
    }

    // Update stored remote_slug and clear the not_found status so refresh proceeds.
    $old_slug              = $source['remote_slug'];
    $source['remote_slug'] = $new_slug;
    $source['status']      = 'active';
    update_term_meta( $term_id, TC_META_IDENTITY_SOURCE, $source );

    if ( ! empty( $source['sv_source_id'] ) ) {
        tc_sv_update_root_slug( $source['sv_source_id'], $old_slug, $new_slug );
    }

    // Full refresh to pick up name, description, and links from the new slug.
    tc_refresh_identity_cache( $term_id );

    wp_send_json_success( array( 'message' => __( 'Term re-linked and renamed.', 'termconnect' ) ) );
}

/**
 * Remove a not_found delegation and add the remote term as a local external link
 * on a specified local term (which may or may not be the same term).
 *
 * POST params: remove_term_id, link_term_id, peer_site_slug, new_slug, new_name, nonce (tc_admin).
 */
function tc_ajax_di_add_link(): void {
    check_ajax_referer( 'tc_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $remove_term_id = absint( $_POST['remove_term_id'] ?? 0 );
    $link_term_id   = absint( $_POST['link_term_id'] ?? 0 );
    $peer_site_slug = sanitize_key( wp_unslash( $_POST['peer_site_slug'] ?? '' ) );
    $new_slug       = sanitize_title( wp_unslash( $_POST['new_slug'] ?? '' ) );
    $new_name       = sanitize_text_field( wp_unslash( $_POST['new_name'] ?? '' ) );

    if ( ! $remove_term_id || ! $link_term_id || ! $peer_site_slug || ! $new_slug ) {
        wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'termconnect' ) ) );
    }

    // Validate the link type exists before touching the delegation, so a failure
    // does not leave the term in a worse state.
    if ( null === tc_get_link_type( $peer_site_slug ) ) {
        wp_send_json_error( array( 'message' => __( 'Unknown link type.', 'termconnect' ) ) );
    }

    // Add the remote term as a local external link.
    if ( ! tc_set_term_link( $link_term_id, $peer_site_slug, $new_slug, $new_name, time() ) ) {
        wp_send_json_error( array( 'message' => __( 'Failed to add link.', 'termconnect' ) ) );
    }

    // Link added — now remove the not_found delegation, restoring original name and description.
    tc_remove_identity_source( $remove_term_id );

    wp_send_json_success( array( 'message' => __( 'Link added.', 'termconnect' ) ) );
}

/**
 * Transfer a not_found delegation from the current term to another local term
 * whose slug matches the remote term's new slug.
 *
 * POST params: remove_term_id, target_term_id, peer_site_slug, new_slug, nonce (tc_admin).
 */
function tc_ajax_di_redelegate(): void {
    check_ajax_referer( 'tc_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $remove_term_id = absint( $_POST['remove_term_id'] ?? 0 );
    $target_term_id = absint( $_POST['target_term_id'] ?? 0 );
    $peer_site_slug = sanitize_key( wp_unslash( $_POST['peer_site_slug'] ?? '' ) );
    $new_slug       = sanitize_title( wp_unslash( $_POST['new_slug'] ?? '' ) );

    if ( ! $remove_term_id || ! $target_term_id || ! $peer_site_slug || ! $new_slug ) {
        wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'termconnect' ) ) );
    }

    $target_term = get_term( $target_term_id );
    if ( ! $target_term || is_wp_error( $target_term ) ) {
        wp_send_json_error( array( 'message' => __( 'Target term not found.', 'termconnect' ) ) );
    }

    // Fetch remote data before making any changes.
    $remote = tc_fetch_remote_identity( $peer_site_slug, $target_term->taxonomy, $new_slug );
    if ( is_wp_error( $remote ) ) {
        wp_send_json_error( array( 'message' => $remote->get_error_message() ) );
    }
    if ( ( $remote['type'] ?? '' ) === 'redirect' ) {
        wp_send_json_error( array( 'message' => __( 'Remote term has moved again. Retry from Site Health.', 'termconnect' ) ) );
    }

    // Delegate the target term first; only remove the old delegation once that
    // succeeds, so a failed redelegation leaves the original delegation intact.
    $result = tc_set_identity_source( $target_term_id, $peer_site_slug, $new_slug, $remote );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    // When redelegating a term to a new remote slug on itself (remove_term_id ===
    // target_term_id), tc_set_identity_source() above already fully overwrote this
    // term's delegation — there is nothing stale to clean up. Removing it here would
    // delete the delegation just written and restore the term's pre-delegation name/
    // description, discarding the redelegation entirely.
    if ( $remove_term_id !== $target_term_id ) {
        tc_remove_identity_source( $remove_term_id );
    }

    wp_send_json_success( array( 'message' => __( 'Delegation transferred.', 'termconnect' ) ) );
}

/**
 * AJAX: follow a pending redirect — point the delegation at the remote term the
 * old source now redirects to (Site Health review action).
 *
 * Shared Vocabularies–owned delegations can't be followed here: the vocabulary
 * source decides which remote slug they track.
 *
 * @since 1.0.0
 */
function tc_ajax_di_follow_redirect(): void {
    check_ajax_referer( 'tc_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $term_id = absint( $_POST['term_id'] ?? 0 );
    $term    = $term_id ? get_term( $term_id ) : null;
    $source  = $term_id ? tc_get_identity_source( $term_id ) : null;
    $entry   = tc_identity_redirect_queue_get()[ (string) $term_id ] ?? null;
    if ( ! ( $term instanceof WP_Term ) || null === $source || null === $entry || '' === ( $entry['target_slug'] ?? '' ) ) {
        wp_send_json_error( array( 'message' => __( 'There is no pending redirect for this term.', 'termconnect' ) ) );
    }
    if ( ! empty( $source['sv_source_id'] ) ) {
        wp_send_json_error( array( 'message' => __( 'This term is managed by a shared vocabulary; update the vocabulary source instead.', 'termconnect' ) ) );
    }

    $remote = tc_fetch_remote_identity( $source['peer_site_slug'], $term->taxonomy, $entry['target_slug'] );
    if ( is_wp_error( $remote ) ) {
        wp_send_json_error( array( 'message' => $remote->get_error_message() ) );
    }
    if ( 'redirect' === ( $remote['type'] ?? '' ) ) {
        wp_send_json_error( array( 'message' => __( 'Remote term has moved again. Retry from Site Health.', 'termconnect' ) ) );
    }

    $result = tc_identity_follow_redirect( $term_id, $entry['target_slug'], $remote );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }
    wp_send_json_success( array( 'message' => __( 'Delegation now follows the new remote term.', 'termconnect' ) ) );
}

/**
 * AJAX: keep the current delegation despite a pending redirect (Site Health review action).
 *
 * @since 1.0.0
 */
function tc_ajax_di_decline_redirect(): void {
    check_ajax_referer( 'tc_admin', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'termconnect' ) ), 403 );
    }

    $result = tc_identity_decline_redirect( absint( $_POST['term_id'] ?? 0 ) );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }
    wp_send_json_success( array( 'message' => __( 'Redirect declined; the current delegation is kept.', 'termconnect' ) ) );
}
