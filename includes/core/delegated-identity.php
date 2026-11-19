<?php
/**
 * Delegated Identity
 *
 * When a term's identity is delegated to a peer WordPress site, TermConnect fetches the
 * remote term's description and external links via the standard WP REST API and caches
 * them locally. The term's native description is replaced with the remote one on delegation.
 *
 * Remote terms are identified by a configured WordPress peer link type slug
 * (platform='wordpress') plus the remote term slug. The remote taxonomy is
 * always the same as the local taxonomy.
 *
 * External links are read from the tconn_links field in the remote WP REST response
 * (format: [ { url, platform, label, fetched_at } ]) and cached in _tconn_delegated_links_data.
 * TermConnect sends X-TermConnect: {TCONN_VERSION} in outbound requests to identify itself.
 *
 * Meta keys used per term:
 *   _tconn_identity_source        — array: delegation target + cache timestamps + status
 *   _tconn_delegated_links_data   — array: cached [ { url, platform, label, fetched_at } ] from remote
 *   _tconn_uris                   — multi-value: URI index rebuilt from local + delegated links
 *   _tconn_original_description   — the term's description before delegation was set
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TCONN_META_IDENTITY_SOURCE',      '_tconn_identity_source' );
define( 'TCONN_META_DELEGATED_LINKS_DATA', '_tconn_delegated_links_data' );
define( 'TCONN_META_ORIGINAL_DESCRIPTION', '_tconn_original_description' );
define( 'TCONN_META_ORIGINAL_NAME',        '_tconn_original_name' );
define( 'TCONN_META_REL_SYNC_ISSUES',      '_tconn_rel_sync_issues' );
define( 'TCONN_IDENTITY_CACHE_TTL',        DAY_IN_SECONDS );

/**
 * Wall-clock seconds a single cron tick may spend making synchronous remote
 * requests (e.g. tconn_cron_refresh_identity_caches()) before bailing out and
 * leaving the remainder for the next scheduled run.
 */
define( 'TCONN_CRON_TIME_BUDGET', 20 );

// ==============================================================================
// IDENTITY SOURCE CRUD
// ==============================================================================

/**
 * Return the stored identity source for a term, or null if none.
 *
 * @param int $term_id
 * @return array|null {
 *     @type string $peer_site_slug        Link type slug (platform='wordpress') identifying the peer.
 *     @type string $site_url       Peer site base URL (cached from link type for display).
 *     @type string $remote_slug    Remote term slug.
 *     @type int    $remote_term_id Remote term ID (0 if not yet resolved).
 *     @type string $status         'active' | 'unreachable' | 'not_found' | 'redirect_pending'
 *                                  (remote moved; awaiting review) | 'redirect_declined' (admin kept
 *                                  the current delegation; see $declined_redirect_slug)
 *     @type string $declined_redirect_slug Redirect target the admin chose not to follow; that
 *                                  target is not queued again (a different target is).
 *     @type int    $last_fetched   Unix timestamp of last successful fetch.
 * }
 */
function tconn_get_identity_source( int $term_id ): ?array {
    $raw = get_term_meta( $term_id, TCONN_META_IDENTITY_SOURCE, true );
    if ( ! is_array( $raw ) || empty( $raw['peer_site_slug'] ) ) {
        return null;
    }
    return wp_parse_args( $raw, array(
        'peer_site_slug'          => '',
        'site_url'         => '',
        'remote_slug'      => '',
        'remote_term_id'    => 0,
        'remote_link'       => '',
        'status'            => 'active',
        'last_fetched'      => 0,
        'sync_descendants'  => false,
        'sync_name'         => true,
        'sync_description'  => true,
    ) );
}

/**
 * Save the identity source for a term.
 * Replaces the term's description with the remote description and saves the
 * original locally so it can be restored if delegation is removed.
 *
 * @param int    $term_id
 * @param string $peer_site_slug           Peer link type slug (platform='wordpress').
 * @param string $remote_slug       Remote term slug (same taxonomy as local term).
 * @param array  $remote            Fetched remote data: name, description, delegated_links, term_id, link.
 * @param bool   $sync_descendants  Track and sync descendant terms on future cron runs.
 * @param string $sv_source_id      Shared Vocabularies source ID that created this delegation.
 * @param bool   $sync_name         Replace local name with remote name and keep in sync. Default true.
 * @param bool   $sync_description  Replace local description with remote description and keep in sync. Default true.
 * @return true|WP_Error
 */
function tconn_set_identity_source( int $term_id, string $peer_site_slug, string $remote_slug, array $remote, bool $sync_descendants = false, string $sv_source_id = '', bool $sync_name = true, bool $sync_description = true ) {
    $term = get_term( $term_id );
    if ( ! $term || is_wp_error( $term ) ) {
        return new WP_Error( 'tconn_invalid_term', __( 'Term not found.', 'termconnect' ) );
    }

    $link_type = tconn_get_link_type( $peer_site_slug );
    if ( ! $link_type || ( $link_type['platform'] ?? '' ) !== 'wordpress' ) {
        return new WP_Error( 'tconn_invalid_lt', __( 'Not a WordPress peer link type.', 'termconnect' ) );
    }

    $site_url = tconn_external_get_site_origin( $link_type['uri_prefix'] ?? '' );
    if ( ! $site_url ) {
        return new WP_Error( 'tconn_invalid_origin', __( 'Could not determine peer site URL.', 'termconnect' ) );
    }

    // Guard: prevent delegating to this site itself.
    $local_host  = wp_parse_url( home_url(), PHP_URL_HOST );
    $remote_host = wp_parse_url( $site_url, PHP_URL_HOST );
    if ( $local_host && $remote_host && rtrim( $local_host, '.' ) === rtrim( $remote_host, '.' ) ) {
        return new WP_Error( 'tconn_self_delegation', __( 'Cannot delegate a term\'s identity to local site.', 'termconnect' ) );
    }

    $term_updates = array();

    // Preserve and optionally sync name. The original is backed up only once: when the
    // term is already delegated, its current name is the synced remote value, and
    // re-delegating must not overwrite the true original with it.
    if ( $sync_name ) {
        if ( ! metadata_exists( 'term', $term_id, TCONN_META_ORIGINAL_NAME ) ) {
            update_term_meta( $term_id, TCONN_META_ORIGINAL_NAME, $term->name );
        }
        $remote_name = sanitize_text_field( html_entity_decode( $remote['name'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( '' !== $remote_name ) {
            $term_updates['name'] = $remote_name;
        }
    }

    // Preserve and optionally sync description.
    if ( $sync_description ) {
        if ( ! metadata_exists( 'term', $term_id, TCONN_META_ORIGINAL_DESCRIPTION ) ) {
            update_term_meta( $term_id, TCONN_META_ORIGINAL_DESCRIPTION, $term->description );
        }
        $term_updates['description'] = $remote['description'] ?? '';
    }

    if ( ! empty( $term_updates ) ) {
        $update_result = wp_update_term( $term_id, $term->taxonomy, $term_updates );
        if ( is_wp_error( $update_result ) ) {
            return $update_result;
        }
    }

    // Store source metadata.
    $meta = array(
        'peer_site_slug'          => sanitize_key( $peer_site_slug ),
        'site_url'         => esc_url_raw( $site_url ),
        'remote_slug'      => sanitize_title( $remote_slug ),
        'remote_term_id'   => absint( $remote['term_id'] ?? 0 ),
        'remote_link'      => esc_url_raw( $remote['link'] ?? '' ),
        'status'           => 'active',
        'last_fetched'     => time(),
        'sync_descendants' => (bool) $sync_descendants,
        'sync_name'        => (bool) $sync_name,
        'sync_description' => (bool) $sync_description,
    );
    if ( '' !== $sv_source_id ) {
        $meta['sv_source_id'] = sanitize_key( $sv_source_id );
    }
    update_term_meta( $term_id, TCONN_META_IDENTITY_SOURCE, $meta );

    // Cache the remote delegated links.
    tconn_save_delegated_links( $term_id, $remote['delegated_links'] ?? array() );

    // A (re)delegation replaces whatever redirect was pending for this term.
    tconn_identity_redirect_queue_remove( $term_id );

    return true;
}

/**
 * Remove the identity source from a term, optionally restoring the original name/description.
 *
 * @param int   $term_id
 * @param array $options {
 *     @type bool $restore_name        Restore the backed-up original name. Default true.
 *     @type bool $restore_description Restore the backed-up original description. Default true.
 *     @type bool $keep_peer_links     Copy inherited delegated links as local links for
 *                                     locally enabled link types that have no existing local link. Default false.
 * }
 */
function tconn_remove_identity_source( int $term_id, array $options = array() ): void {
    $restore_name        = (bool) ( $options['restore_name'] ?? true );
    $restore_description = (bool) ( $options['restore_description'] ?? true );
    $keep_peer_links     = (bool) ( $options['keep_peer_links'] ?? false );

    $term = get_term( $term_id );
    if ( ! $term || is_wp_error( $term ) ) {
        return;
    }

    if ( $keep_peer_links ) {
        tconn_absorb_delegated_links( $term_id );
    }

    $restores = array();

    $original_name = get_term_meta( $term_id, TCONN_META_ORIGINAL_NAME, true );
    if ( $restore_name && false !== $original_name && '' !== $original_name ) {
        $restores['name'] = $original_name;
    }
    delete_term_meta( $term_id, TCONN_META_ORIGINAL_NAME );

    $original_desc = get_term_meta( $term_id, TCONN_META_ORIGINAL_DESCRIPTION, true );
    if ( $restore_description && false !== $original_desc ) {
        $restores['description'] = $original_desc;
    }
    delete_term_meta( $term_id, TCONN_META_ORIGINAL_DESCRIPTION );

    if ( ! empty( $restores ) ) {
        $restore_result = wp_update_term( $term_id, $term->taxonomy, $restores );
        if ( is_wp_error( $restore_result ) ) {
            error_log( 'TermConnect: delegated identity restore failed for term ' . $term_id . ': ' . $restore_result->get_error_message() );
        }
    }

    delete_term_meta( $term_id, TCONN_META_IDENTITY_SOURCE );
    delete_term_meta( $term_id, TCONN_META_DELEGATED_LINKS_DATA );
    tconn_identity_redirect_queue_remove( $term_id );
    tconn_rebuild_uri_index( $term_id );
}

/**
 * Absorb inherited delegated links as local links for locally enabled link types.
 *
 * If a local link already exists for the same platform, it is overwritten by the
 * delegated identifier — the two cannot coexist once delegation ends.
 *
 * Called when delegation is removed with the keep_peer_links option.
 *
 * @param int $term_id
 */
function tconn_absorb_delegated_links( int $term_id ): void {
    $delegated = get_term_meta( $term_id, TCONN_META_DELEGATED_LINKS_DATA, true );
    if ( ! is_array( $delegated ) || empty( $delegated ) ) {
        return;
    }

    $enabled_types = tconn_get_enabled_link_types();

    foreach ( $enabled_types as $slug => $type ) {
        $prefix = $type['uri_prefix'] ?? '';
        if ( '' === $prefix ) {
            continue;
        }
        foreach ( $delegated as $entry ) {
            $url = $entry['url'] ?? '';
            if ( '' === $url ) {
                continue;
            }
            if ( 0 === strpos( $url, $prefix ) ) {
                $id = substr( $url, strlen( $prefix ) );
                if ( '' !== $id ) {
                    tconn_set_term_link( $term_id, $slug, $id, $entry['label'] ?? '', (int) ( $entry['fetched_at'] ?? 0 ) );
                    break;
                }
            }
        }
    }
}

/**
 * Update the sync_name and sync_description flags for a delegated term.
 *
 * When a flag is turned off, the original backed-up value is immediately restored.
 * When a flag is turned on, the remote value will be picked up on the next cron run.
 *
 * @param int  $term_id
 * @param bool $sync_name
 * @param bool $sync_description
 * @return bool False if the term has no identity source.
 */
function tconn_update_identity_sync_flags( int $term_id, bool $sync_name, bool $sync_description ): bool {
    $source = tconn_get_identity_source( $term_id );
    if ( null === $source ) {
        return false;
    }

    $term = get_term( $term_id );
    if ( ! $term || is_wp_error( $term ) ) {
        return false;
    }

    $old_sync_name = ! isset( $source['sync_name'] ) || ! empty( $source['sync_name'] );
    $old_sync_desc = ! isset( $source['sync_description'] ) || ! empty( $source['sync_description'] );

    $restores = array();

    if ( $old_sync_name && ! $sync_name ) {
        $original_name = get_term_meta( $term_id, TCONN_META_ORIGINAL_NAME, true );
        if ( false !== $original_name && '' !== $original_name ) {
            $restores['name'] = $original_name;
        }
    }

    if ( $old_sync_desc && ! $sync_description ) {
        $original_desc = get_term_meta( $term_id, TCONN_META_ORIGINAL_DESCRIPTION, true );
        if ( false !== $original_desc ) {
            $restores['description'] = $original_desc;
        }
    }

    if ( ! empty( $restores ) ) {
        $result = wp_update_term( $term_id, $term->taxonomy, $restores );
        if ( is_wp_error( $result ) ) {
            error_log( 'TermConnect: sync flag restore failed for term ' . $term_id . ': ' . $result->get_error_message() );
        }
    }

    $source['sync_name']        = $sync_name;
    $source['sync_description'] = $sync_description;
    update_term_meta( $term_id, TCONN_META_IDENTITY_SOURCE, $source );
    return true;
}

/**
 * Check that a wp_update_term() restore actually reached the database.
 *
 * wp_update_term() does not check the result of its own UPDATE, so a failed write
 * still returns success. Re-read the term and compare each restored field with the
 * value WordPress would store for it (the same 'db' sanitization wp_update_term()
 * applies, e.g. '&' becomes '&amp;' in a name).
 *
 * @since 1.0.0
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Term taxonomy.
 * @param array  $restores Fields passed to wp_update_term(): 'name' and/or 'description'.
 * @return string Empty when every field matches; otherwise a message naming the fields.
 */
function tconn_term_restore_mismatch( int $term_id, string $taxonomy, array $restores ): string {
    clean_term_cache( $term_id, $taxonomy );
    $fresh = get_term( $term_id, $taxonomy, OBJECT, 'raw' );
    if ( ! $fresh || is_wp_error( $fresh ) ) {
        return __( 'Term could not be re-read after restoring.', 'termconnect' );
    }

    $mismatched = array();
    foreach ( $restores as $field => $value ) {
        $expected = wp_unslash( sanitize_term_field( $field, $value, $term_id, $taxonomy, 'db' ) );
        if ( (string) $fresh->$field !== (string) $expected ) {
            $mismatched[] = $field;
        }
    }

    return $mismatched
        /* translators: %s: comma-separated field names (name, description) */
        ? sprintf( __( 'Restored value was not saved (%s).', 'termconnect' ), implode( ', ', $mismatched ) )
        : '';
}

/**
 * Remove all delegation meta for every term managed by a given shared vocabulary source,
 * restoring original names and descriptions where they were backed up before syncing.
 *
 * @param string $sv_source_id Shared vocabulary source UUID.
 * @return array[] One entry per term whose name/description restore failed:
 *                 { term_id: int, message: string }. The original-name/description
 *                 backup meta is kept for these terms (every other delegation meta
 *                 key is still removed), so the data isn't lost — just left for
 *                 manual follow-up, since there's no automated retry for a source
 *                 that's already been deleted.
 */
function tconn_sv_remove_delegations_by_source( string $sv_source_id ): array {
    global $wpdb;

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s",
        TCONN_META_IDENTITY_SOURCE
    ) );

    $term_ids = array();
    foreach ( $rows as $row ) {
        $source = maybe_unserialize( $row->meta_value );
        if ( is_array( $source ) && ( $source['sv_source_id'] ?? '' ) === $sv_source_id ) {
            $term_ids[] = (int) $row->term_id;
        }
    }

    if ( empty( $term_ids ) ) {
        return array();
    }

    // Separate which terms opted out of name/description sync so locally-edited
    // values are not clobbered with stale original backups.
    $name_restore_ids = array();
    $desc_restore_ids = array();
    foreach ( $rows as $row ) {
        $source = maybe_unserialize( $row->meta_value );
        if ( is_array( $source ) && ( $source['sv_source_id'] ?? '' ) === $sv_source_id ) {
            $tid = (int) $row->term_id;
            // Only restore name when sync_name was enabled (default true).
            if ( ! isset( $source['sync_name'] ) || ! empty( $source['sync_name'] ) ) {
                $name_restore_ids[] = $tid;
            }
            // Only restore description when sync_description was enabled (default true).
            if ( ! isset( $source['sync_description'] ) || ! empty( $source['sync_description'] ) ) {
                $desc_restore_ids[] = $tid;
            }
        }
    }

    $placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );

    // Restore original names/descriptions via wp_update_term() — one call per term,
    // combining both restores where a term needs both — so edit_term/edited_term fire
    // as they do for every other restore path in this file (tconn_remove_identity_source(),
    // tconn_update_identity_sync_flags()), keeping HyperTaxonomy's search index (and any
    // other edited_term listener) current after a bulk source removal.
    // A restore that fails for one term (wp_update_term() returns an error, or the
    // re-read in tconn_term_restore_mismatch() shows the write didn't land) is reported in
    // the return value and does not abort the rest of the cleanup below — but the term's original-name/description backup meta is kept out
    // of the deletion pass further down, so the data needed to retry the restore by hand
    // isn't destroyed along with it.
    $failed_term_ids = array();
    $failures        = array();
    foreach ( $term_ids as $term_id ) {
        $term = get_term( $term_id );
        if ( ! $term || is_wp_error( $term ) ) {
            continue;
        }
        $restores = array();
        if ( in_array( $term_id, $name_restore_ids, true ) ) {
            $original_name = get_term_meta( $term_id, TCONN_META_ORIGINAL_NAME, true );
            if ( false !== $original_name && '' !== $original_name ) {
                $restores['name'] = $original_name;
            }
        }
        if ( in_array( $term_id, $desc_restore_ids, true ) ) {
            $original_desc = get_term_meta( $term_id, TCONN_META_ORIGINAL_DESCRIPTION, true );
            if ( false !== $original_desc ) {
                $restores['description'] = $original_desc;
            }
        }
        if ( ! empty( $restores ) ) {
            $result  = wp_update_term( $term_id, $term->taxonomy, $restores );
            $message = is_wp_error( $result )
                ? $result->get_error_message()
                : tconn_term_restore_mismatch( $term_id, $term->taxonomy, $restores );
            if ( '' !== $message ) {
                $failed_term_ids[]  = $term_id;
                $failures[]         = array( 'term_id' => $term_id, 'message' => $message );
                error_log( 'TermConnect: bulk delegation restore failed for term ' . $term_id . ': ' . $message );
            }
        }
    }

    $wpdb->query( 'START TRANSACTION' );

    // TCONN_META_ORIGINAL_NAME/DESCRIPTION are skipped for terms whose restore failed above,
    // so a future manual fix (e.g. renaming the colliding term out of the way) has the
    // backup value to restore from — every other delegation meta key is cleared for all
    // affected terms regardless, since the source itself is gone either way.
    $backup_term_ids = array_values( array_diff( $term_ids, $failed_term_ids ) );
    foreach ( array(
        TCONN_META_IDENTITY_SOURCE     => $term_ids,
        TCONN_META_DELEGATED_LINKS_DATA => $term_ids,
        TCONN_META_ORIGINAL_NAME       => $backup_term_ids,
        TCONN_META_ORIGINAL_DESCRIPTION => $backup_term_ids,
        TCONN_META_REL_SYNC_ISSUES     => $term_ids,
    ) as $meta_key => $ids_for_key ) {
        if ( empty( $ids_for_key ) ) {
            continue;
        }
        $key_placeholders = implode( ',', array_fill( 0, count( $ids_for_key ), '%d' ) );
        $result            = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->termmeta} WHERE meta_key = %s AND term_id IN ({$key_placeholders})",
            array_merge( array( $meta_key ), $ids_for_key )
        ) );
        if ( false === $result ) {
            $wpdb->query( 'ROLLBACK' );
            return $failures;
        }
    }

    // Also clear the _tconn_uris index for each affected term.
    $result = $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->termmeta} WHERE meta_key = '_tconn_uris' AND term_id IN ({$placeholders})",
        $term_ids
    ) );
    if ( false === $result ) {
        $wpdb->query( 'ROLLBACK' );
        return $failures;
    }

    $wpdb->query( 'COMMIT' );

    clean_term_cache( $term_ids );
    foreach ( $term_ids as $term_id ) {
        wp_cache_delete( $term_id, 'term_meta' );
        delete_transient( 'tconn_uris_' . $term_id );
        tconn_get_term_links_data( (int) $term_id, true );
    }

    // Rebuild URI index for each term using only remaining local links.
    foreach ( $term_ids as $term_id ) {
        tconn_rebuild_uri_index( (int) $term_id );
    }

    // The meta rows were deleted with direct SQL, which fires no meta hooks.
    tconn_flush_term_ids_with_meta( TCONN_META_IDENTITY_SOURCE );
    tconn_flush_term_ids_with_meta( TCONN_META_REL_SYNC_ISSUES );

    return $failures;
}

/**
 * Save delegated links data and rebuild the URI index for a term.
 *
 * @param int   $term_id
 * @param array $links   Flat array of { url, platform, label, fetched_at } from remote tconn_links.
 */
function tconn_save_delegated_links( int $term_id, array $links ): void {
    update_term_meta( $term_id, TCONN_META_DELEGATED_LINKS_DATA, $links );
    tconn_rebuild_uri_index( $term_id );
}

// ==============================================================================
// REMOTE FETCH
// ==============================================================================

/**
 * Fetch identity data for a term from a peer WordPress site via WP REST API.
 *
 * Uses GET /wp/v2/{taxonomy}?slug={slug}&_fields=id,slug,name,description,tconn_links,htax_redirect.
 * External identifiers are read from tconn_links. TermConnect sends X-TermConnect: {TCONN_VERSION}
 * to identify itself to the remote site.
 *
 * @param string $peer_site_slug      Peer link type slug (platform='wordpress').
 * @param string $taxonomy     Taxonomy slug (same on both sites).
 * @param string $remote_slug  Remote term slug.
 * @return array|WP_Error Array with keys: term_id, slug, name, description, mappings.
 */
function tconn_fetch_remote_identity( string $peer_site_slug, string $taxonomy, string $remote_slug ) {
    $link_type = tconn_get_link_type( $peer_site_slug );
    if ( ! $link_type || ( $link_type['platform'] ?? '' ) !== 'wordpress' ) {
        return new WP_Error( 'tconn_invalid_lt', __( 'Not a WordPress peer link type.', 'termconnect' ) );
    }

    $origin = tconn_external_get_site_origin( $link_type['uri_prefix'] ?? '' );
    if ( ! $origin ) {
        return new WP_Error( 'tconn_invalid_origin', __( 'Could not determine peer site URL from link type.', 'termconnect' ) );
    }

    $rest_base = tconn_external_get_taxonomy_rest_base( $taxonomy );
    $url = trailingslashit( $origin ) . 'wp-json/wp/v2/' . $rest_base
         . '?' . http_build_query( array(
             'slug'    => $remote_slug,
             '_fields' => 'id,slug,name,description,link,tconn_links,htax_redirect',
         ) );

    // HT's collection endpoint only includes htax_redirect (even when requested via
    // _fields) for requests that identify themselves as a peer via this header — plain
    // _fields filtering isn't enough on its own. Without it, a remote term that redirects
    // is silently reported as a normal term, and this function can never detect a move.
    $response = wp_remote_get( $url, tconn_external_request_args( 10, tconn_external_peer_headers( true ) ) );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'tconn_unreachable', $response->get_error_message() );
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( 200 !== $code ) {
        if ( 404 === $code ) {
            return new WP_Error( 'tconn_not_found', __( 'Remote term not found.', 'termconnect' ) );
        }
        return new WP_Error(
            'tconn_remote_error',
            /* translators: %d: HTTP status code */
            sprintf( __( 'Remote site returned HTTP %d.', 'termconnect' ), $code )
        );
    }

    $body = tconn_external_json_decode( wp_remote_retrieve_body( $response ) );
    if ( ! is_array( $body ) ) {
        return new WP_Error( 'tconn_invalid_response', __( 'Invalid response from remote site.', 'termconnect' ) );
    }

    // WP REST collection endpoint returns an array of matching terms.
    $term_data = isset( $body[0] ) ? $body[0] : $body;
    if ( empty( $term_data['id'] ) ) {
        return new WP_Error( 'tconn_not_found', __( 'Remote term not found.', 'termconnect' ) );
    }

    // htax_redirect is present when the remote runs HyperTaxonomy — indicates this term
    // redirects elsewhere; return a redirect type so the caller can auto-follow or queue for review.
    if ( ! empty( $term_data['htax_redirect'] ) && is_array( $term_data['htax_redirect'] ) ) {
        $target = $term_data['htax_redirect'];
        return array(
            'type'             => 'redirect',
            'target_slug'      => sanitize_title( $target['slug'] ?? '' ),
            'target_taxonomy'  => sanitize_key( $target['taxonomy'] ?? '' ),
            'target_name'      => sanitize_text_field( html_entity_decode( $target['name'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
            'target_link'      => esc_url_raw( $target['link'] ?? '' ),
        );
    }

    // Extract delegated links from tconn_links. Format: [ { url, platform, label, type_label, fetched_at } ].
    $tconn_links        = $term_data['tconn_links'] ?? array();
    $delegated_links = array();
    if ( is_array( $tconn_links ) ) {
        foreach ( $tconn_links as $entry ) {
            if ( ! empty( $entry['url'] ) ) {
                $delegated_links[] = array(
                    'url'        => esc_url_raw( (string) $entry['url'] ),
                    'platform'   => sanitize_key( (string) ( $entry['platform'] ?? '' ) ),
                    'label'      => sanitize_text_field( (string) ( $entry['label'] ?? '' ) ),
                    'type_label' => sanitize_text_field( (string) ( $entry['type_label'] ?? '' ) ),
                    'fetched_at' => (int) ( $entry['fetched_at'] ?? 0 ),
                );
            }
        }
    }

    return array(
        'type'            => 'term',
        'term_id'         => (int) $term_data['id'],
        'slug'            => sanitize_title( $term_data['slug'] ?? $remote_slug ),
        'name'            => sanitize_text_field( html_entity_decode( $term_data['name'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
        'description'     => wp_kses_post( $term_data['description'] ?? '' ),
        'link'            => esc_url_raw( $term_data['link'] ?? '' ),
        'delegated_links' => $delegated_links,
    );
}

// ==============================================================================
// CACHE REFRESH
// ==============================================================================

/**
 * Refresh cached identity data for one term.
 * Called by cron and inline from term edit AJAX.
 *
 * @param int $term_id
 * @return true|WP_Error
 */
function tconn_refresh_identity_cache( int $term_id ) {
    $source = tconn_get_identity_source( $term_id );
    if ( null === $source ) {
        return new WP_Error( 'tconn_no_source', __( 'No identity source set for this term.', 'termconnect' ) );
    }

    $term = get_term( $term_id );
    if ( ! $term || is_wp_error( $term ) ) {
        return new WP_Error( 'tconn_invalid_term', __( 'Term not found.', 'termconnect' ) );
    }

    $remote = tconn_fetch_remote_identity( $source['peer_site_slug'], $term->taxonomy, $source['remote_slug'] );

    if ( is_wp_error( $remote ) ) {
        $new_status       = 'tconn_not_found' === $remote->get_error_code() ? 'not_found' : 'unreachable';
        $source['status'] = $new_status;
        update_term_meta( $term_id, TCONN_META_IDENTITY_SOURCE, $source );
        return $remote;
    }

    // Handle redirect: the remote term is itself a redirect on the peer site.
    if ( 'redirect' === ( $remote['type'] ?? '' ) ) {
        // SV-owned terms: inform admin via queue but leave the delegation pointer
        // untouched. SV sync is the authority on which remote slug this term tracks;
        // auto-following here would be undone by SV sync on its next pass anyway.
        if ( ! empty( $source['sv_source_id'] ) ) {
            tconn_identity_redirect_queue_add( $term_id, $remote );
            return true;
        }

        // The admin chose to keep the current delegation for this exact target —
        // don't raise it again (a different target is queued as usual).
        if ( '' !== ( $remote['target_slug'] ?? '' )
            && ( $source['declined_redirect_slug'] ?? '' ) === $remote['target_slug'] ) {
            $source['status'] = 'redirect_declined';
            update_term_meta( $term_id, TCONN_META_IDENTITY_SOURCE, $source );
            tconn_identity_redirect_queue_remove( $term_id );
            return true;
        }

        $settings    = get_option( 'tconn_settings', array() );
        $auto_single = ! empty( $settings['auto_accept_redirects'] );
        $auto_chain  = ! empty( $settings['auto_accept_chain_redirects'] );
        $max_depth   = max( 1, min( 5, (int) ( $settings['identity_chain_max_depth'] ?? 3 ) ) );

        if ( $auto_single ) {
            $current_slug  = $remote['target_slug'] ?? '';
            $current_tax   = $remote['target_taxonomy'] ?? $term->taxonomy;
            $resolved      = null;
            $hops          = 0;

            while ( $current_slug && $hops < $max_depth ) {
                $hops++;

                // Cannot auto-follow a redirect that crosses into a different taxonomy —
                // the delegation is locked to the local taxonomy and the target would
                // be unreachable via the configured peer link type.
                if ( $current_tax && $current_tax !== $term->taxonomy ) {
                    break;
                }

                $fetched = tconn_fetch_remote_identity( $source['peer_site_slug'], $term->taxonomy, $current_slug );

                if ( is_wp_error( $fetched ) ) {
                    break; // Target unreachable or not found — queue for review.
                }

                if ( 'term' === ( $fetched['type'] ?? 'term' ) ) {
                    $resolved             = $fetched;
                    $resolved['_slug']    = $current_slug;
                    break;
                }

                if ( 'redirect' === $fetched['type'] ) {
                    // Another hop in the chain — only continue if auto_chain is enabled.
                    if ( ! $auto_chain ) {
                        break;
                    }
                    $current_slug = $fetched['target_slug'] ?? '';
                    $current_tax  = $fetched['target_taxonomy'] ?? $term->taxonomy;
                }
            }

            if ( null !== $resolved ) {
                tconn_identity_follow_redirect( $term_id, $resolved['_slug'], $resolved );
                return true;
            }
        }

        // Queue for admin review (auto-accept off, cross-taxonomy redirect, chain
        // disabled with more hops remaining, or target unreachable).
        tconn_identity_redirect_queue_add( $term_id, $remote );
        $source['status'] = 'redirect_pending';
        update_term_meta( $term_id, TCONN_META_IDENTITY_SOURCE, $source );
        return true;
    }

    // Update synced fields and external identifiers.
    $term_updates = array();
    if ( ! empty( $source['sync_name'] ) || ! isset( $source['sync_name'] ) ) {
        $remote_name = sanitize_text_field( html_entity_decode( $remote['name'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( '' !== $remote_name ) {
            $term_updates['name'] = $remote_name;
        }
    }
    if ( ! empty( $source['sync_description'] ) || ! isset( $source['sync_description'] ) ) {
        $term_updates['description'] = $remote['description'] ?? '';
    }
    if ( ! empty( $term_updates ) ) {
        $sync_result = wp_update_term( $term_id, $term->taxonomy, $term_updates );
        if ( is_wp_error( $sync_result ) ) {
            error_log( 'TermConnect: delegated sync term update failed for term ' . $term_id . ': ' . $sync_result->get_error_message() );
            return $sync_result;
        }
    }
    tconn_save_delegated_links( $term_id, $remote['delegated_links'] ?? array() );

    // The source resolves to a normal term again — nothing is pending any more.
    $source['status']       = 'active';
    $source['last_fetched'] = time();
    unset( $source['declined_redirect_slug'] );
    tconn_identity_redirect_queue_remove( $term_id );
    $old_remote_term_id     = (int) ( $source['remote_term_id'] ?? 0 );
    $new_remote_term_id     = absint( $remote['term_id'] ?? $old_remote_term_id );
    $source['remote_term_id'] = $new_remote_term_id;
    $source['remote_link']    = esc_url_raw( $remote['link'] ?? $source['remote_link'] ?? '' );
    update_term_meta( $term_id, TCONN_META_IDENTITY_SOURCE, $source );

    // Slug match wins — if the remote returned a different ID for the same slug, keep SV roots in sync.
    if ( $new_remote_term_id && $new_remote_term_id !== $old_remote_term_id && ! empty( $source['sv_source_id'] ) ) {
        tconn_sv_update_root_term_id( $source['sv_source_id'], $source['remote_slug'], $new_remote_term_id );
    }

    return true;
}

// ==============================================================================
// SV CONFIG HELPERS
// ==============================================================================

/**
 * Scope an advisory lock name to this site.
 *
 * GET_LOCK() names are global to the whole MySQL server, not per database, so
 * unscoped names would make separate WordPress installs sharing a server (and
 * the subsites of a multisite network) block each other's syncs. Prefixing a
 * hash of the database name and table prefix keeps each site's locks
 * independent while staying under MySQL's 64-character lock-name limit.
 *
 * @param string $name Lock name used by callers.
 * @return string Site-scoped lock name.
 */
function tconn_mysql_lock_name( string $name ): string {
    global $wpdb;
    return substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 12 ) . '_' . $name;
}

/**
 * Update remote_term_id on a shared vocabulary roots entry matched by slug.
 *
 * Called after a successful slug-based fetch returns a different ID — slug
 * match wins, so the stored ID is updated to stay current.
 *
 * @param string $sv_source_id Source UUID.
 * @param string $remote_slug  Slug used to identify the root entry.
 * @param int    $new_term_id  Newly discovered remote term ID.
 */
function tconn_sv_update_root_term_id( string $sv_source_id, string $remote_slug, int $new_term_id ): void {
    if ( ! tconn_acquire_mysql_lock( 'tconn_sv_write_lock' ) ) {
        return;
    }
    try {
        $sources = get_option( 'tconn_shared_vocabularies', array() );
        $changed = false;
        foreach ( $sources as &$src ) {
            if ( ( $src['id'] ?? '' ) !== $sv_source_id ) {
                continue;
            }
            foreach ( $src['roots'] as &$root ) {
                if ( ( $root['remote_slug'] ?? '' ) === $remote_slug
                     && (int) ( $root['remote_term_id'] ?? 0 ) !== $new_term_id ) {
                    $root['remote_term_id'] = $new_term_id;
                    $changed = true;
                }
            }
            unset( $root );
            break;
        }
        unset( $src );
        if ( $changed ) {
            update_option( 'tconn_shared_vocabularies', $sources );
        }
    } finally {
        tconn_release_mysql_lock( 'tconn_sv_write_lock' );
    }
}

/**
 * Update remote_slug on a shared vocabulary roots entry (and clear any stale remote_name).
 *
 * Called when a term is re-linked to the remote term's new slug after an admin
 * confirms the rename in Site Health.
 *
 * @param string $sv_source_id Source UUID.
 * @param string $old_slug     Existing slug to locate the entry.
 * @param string $new_slug     New slug to store.
 */
function tconn_sv_update_root_slug( string $sv_source_id, string $old_slug, string $new_slug ): void {
    if ( ! tconn_acquire_mysql_lock( 'tconn_sv_write_lock' ) ) {
        return;
    }
    try {
        $sources = get_option( 'tconn_shared_vocabularies', array() );
        $changed = false;
        foreach ( $sources as &$src ) {
            if ( ( $src['id'] ?? '' ) !== $sv_source_id ) {
                continue;
            }
            foreach ( $src['roots'] as &$root ) {
                if ( ( $root['remote_slug'] ?? '' ) === $old_slug ) {
                    $root['remote_slug'] = $new_slug;
                    $changed = true;
                }
            }
            unset( $root );
            break;
        }
        unset( $src );
        if ( $changed ) {
            update_option( 'tconn_shared_vocabularies', $sources );
        }
    } finally {
        tconn_release_mysql_lock( 'tconn_sv_write_lock' );
    }
}

/**
 * Fetch a single remote term by its ID via the WP REST API single-resource endpoint.
 *
 * Used by the Site Health rename-check flow to probe whether a not_found remote
 * term was renamed rather than deleted.
 *
 * @param string $peer_site_slug Peer link type slug (platform='wordpress').
 * @param string $taxonomy       Taxonomy slug (same on both sites).
 * @param int    $remote_term_id Remote term ID.
 * @return array|WP_Error { term_id, slug, name, link } or WP_Error on failure.
 */
function tconn_fetch_remote_term_by_id( string $peer_site_slug, string $taxonomy, int $remote_term_id ) {
    $link_type = tconn_get_link_type( $peer_site_slug );
    if ( ! $link_type || ( $link_type['platform'] ?? '' ) !== 'wordpress' ) {
        return new WP_Error( 'tconn_invalid_lt', __( 'Not a WordPress peer link type.', 'termconnect' ) );
    }

    $origin = tconn_external_get_site_origin( $link_type['uri_prefix'] ?? '' );
    if ( ! $origin ) {
        return new WP_Error( 'tconn_invalid_origin', __( 'Could not determine peer site URL.', 'termconnect' ) );
    }

    $rest_base = tconn_external_get_taxonomy_rest_base( $taxonomy );
    $url       = trailingslashit( $origin ) . 'wp-json/wp/v2/' . $rest_base . '/' . $remote_term_id
               . '?' . http_build_query( array( '_fields' => 'id,slug,name,link' ) );

    $response = wp_remote_get( $url, tconn_external_request_args( 10, tconn_external_peer_headers() ) );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'tconn_unreachable', $response->get_error_message() );
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( 404 === $code ) {
        return new WP_Error( 'tconn_not_found', __( 'Remote term not found.', 'termconnect' ) );
    }
    if ( 200 !== $code ) {
        return new WP_Error( 'tconn_remote_error',
            /* translators: %d: HTTP status code */
            sprintf( __( 'Remote site returned HTTP %d.', 'termconnect' ), $code )
        );
    }

    $body = tconn_external_json_decode( wp_remote_retrieve_body( $response ) );
    if ( ! is_array( $body ) || empty( $body['id'] ) ) {
        return new WP_Error( 'tconn_invalid_response', __( 'Invalid response from remote site.', 'termconnect' ) );
    }

    return array(
        'term_id' => (int) $body['id'],
        'slug'    => sanitize_title( $body['slug'] ?? '' ),
        'name'    => sanitize_text_field( html_entity_decode( $body['name'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
        'link'    => esc_url_raw( $body['link'] ?? '' ),
    );
}

// ==============================================================================
// REDIRECT REVIEW QUEUE
// Populated by tconn_refresh_identity_cache when a remote term carries htax_redirect
// (HyperTaxonomy peer payload). Admin reviews via Site Health or Settings → TermConnect → Delegated Identity.
// ==============================================================================

/**
 * Add a redirect to the pending review queue.
 *
 * @param int   $term_id
 * @param array $remote  Remote identity response with type='redirect'.
 */
function tconn_identity_redirect_queue_add( int $term_id, array $remote ): void {
    if ( ! tconn_acquire_mysql_lock( 'tconn_redirect_queue_lock' ) ) {
        return;
    }
    try {
        $queue = get_option( 'tconn_identity_redirect_queue', array() );
        $queue[ (string) $term_id ] = array(
            'term_id'     => $term_id,
            'detected_at' => time(),
            'target_slug' => $remote['target_slug'] ?? '',
            'target_name' => $remote['target_name'] ?? '',
            'target_link' => $remote['target_link'] ?? '',
        );
        update_option( 'tconn_identity_redirect_queue', $queue, false );
    } finally {
        tconn_release_mysql_lock( 'tconn_redirect_queue_lock' );
    }
}

/**
 * Return all pending redirect review items.
 *
 * @return array
 */
function tconn_identity_redirect_queue_get(): array {
    return (array) get_option( 'tconn_identity_redirect_queue', array() );
}

/**
 * Remove a term from the pending-redirect review queue.
 *
 * Called whenever a pending redirect stops being pending: the term is (re)delegated,
 * the redirect is followed or declined, a refresh finds the source is a normal term
 * again, delegation ends, or the term is deleted.
 *
 * @since 1.0.0
 * @param int $term_id Local term ID.
 */
function tconn_identity_redirect_queue_remove( int $term_id ): void {
    $queue = tconn_identity_redirect_queue_get();
    if ( ! isset( $queue[ (string) $term_id ] ) ) {
        return;
    }

    // Same lock as tconn_identity_redirect_queue_add().
    if ( ! tconn_acquire_mysql_lock( 'tconn_redirect_queue_lock' ) ) {
        return;
    }
    try {
        $queue = (array) get_option( 'tconn_identity_redirect_queue', array() );
        unset( $queue[ (string) $term_id ] );
        update_option( 'tconn_identity_redirect_queue', $queue, false );
    } finally {
        tconn_release_mysql_lock( 'tconn_redirect_queue_lock' );
    }
}

/**
 * Point a delegated term at the remote term its old source redirects to.
 *
 * Keeps the delegation's settings (sync flags, descendants, Shared Vocabularies
 * ownership) and the backed-up original name/description; refreshes the synced
 * name/description and delegated links from the new remote term. Used by
 * auto-accept during refresh and by the "Follow redirect" review action.
 *
 * @since 1.0.0
 * @param int    $term_id     Local term ID (must already be delegated).
 * @param string $remote_slug Slug of the redirect's target term on the peer.
 * @param array  $remote      Fetched identity of the target (type 'term').
 * @return true|WP_Error
 */
function tconn_identity_follow_redirect( int $term_id, string $remote_slug, array $remote ) {
    $term   = get_term( $term_id );
    $source = tconn_get_identity_source( $term_id );
    if ( ! ( $term instanceof WP_Term ) || null === $source ) {
        return new WP_Error( 'tconn_not_delegated', __( 'This term has no delegated identity.', 'termconnect' ) );
    }

    $source['remote_slug']    = sanitize_title( $remote_slug );
    $source['remote_term_id'] = absint( $remote['term_id'] ?? 0 );
    $source['remote_link']    = esc_url_raw( $remote['link'] ?? '' );
    $source['status']         = 'active';
    $source['last_fetched']   = time();
    unset( $source['declined_redirect_slug'] );
    update_term_meta( $term_id, TCONN_META_IDENTITY_SOURCE, $source );

    $updates = array();
    if ( ! empty( $source['sync_name'] ) || ! isset( $source['sync_name'] ) ) {
        $name = sanitize_text_field( html_entity_decode( $remote['name'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( '' !== $name ) {
            $updates['name'] = $name;
        }
    }
    if ( ! empty( $source['sync_description'] ) || ! isset( $source['sync_description'] ) ) {
        $updates['description'] = $remote['description'] ?? '';
    }
    if ( ! empty( $updates ) ) {
        $result = wp_update_term( $term_id, $term->taxonomy, $updates );
        if ( is_wp_error( $result ) ) {
            error_log( 'TermConnect: redirect follow term update failed for term ' . $term_id . ': ' . $result->get_error_message() );
        }
    }
    tconn_save_delegated_links( $term_id, $remote['delegated_links'] ?? array() );
    tconn_identity_redirect_queue_remove( $term_id );

    return true;
}

/**
 * Keep a term's current delegation despite a pending redirect.
 *
 * Records the redirect target as declined so refreshes stop raising it; if the
 * remote later redirects somewhere else, that new target is queued as usual.
 *
 * @since 1.0.0
 * @param int $term_id Local term ID.
 * @return true|WP_Error
 */
function tconn_identity_decline_redirect( int $term_id ) {
    $source = tconn_get_identity_source( $term_id );
    $queue  = tconn_identity_redirect_queue_get();
    $entry  = $queue[ (string) $term_id ] ?? null;
    if ( null === $source || null === $entry ) {
        return new WP_Error( 'tconn_no_pending_redirect', __( 'There is no pending redirect for this term.', 'termconnect' ) );
    }

    $source['declined_redirect_slug'] = sanitize_title( $entry['target_slug'] ?? '' );
    $source['status']                 = 'redirect_declined';
    update_term_meta( $term_id, TCONN_META_IDENTITY_SOURCE, $source );
    tconn_identity_redirect_queue_remove( $term_id );

    return true;
}

// A deleted term can't have a pending redirect.
add_action( 'delete_term', static function ( $term_id ): void {
    tconn_identity_redirect_queue_remove( (int) $term_id );
} );

// ==============================================================================
// TERM ID LIST CACHES
// Site-wide sets of the term IDs that carry TCONN_META_IDENTITY_SOURCE (delegated terms)
// and TCONN_META_REL_SYNC_ISSUES (terms with stale relationship rows). Both sets change
// rarely, so they are cached with no expiry and flushed whenever a term gains or loses
// one of the keys.
// ==============================================================================

/**
 * Meta keys whose term ID sets tconn_get_term_ids_with_meta() caches.
 */
const TCONN_TERM_ID_CACHED_META_KEYS = array( TCONN_META_IDENTITY_SOURCE, TCONN_META_REL_SYNC_ISSUES );

/**
 * Return the IDs of all terms that have a meta key.
 *
 * Cached as the transient `tconn_term_ids{$meta_key}` (no expiry) for the keys in
 * TCONN_TERM_ID_CACHED_META_KEYS; other keys are queried each time.
 *
 * @since 1.0.0
 * @param string $meta_key Term meta key, e.g. TCONN_META_IDENTITY_SOURCE.
 * @return int[]
 */
function tconn_get_term_ids_with_meta( string $meta_key ): array {
    $cached_key = in_array( $meta_key, TCONN_TERM_ID_CACHED_META_KEYS, true );
    if ( $cached_key ) {
        $cached = get_transient( 'tconn_term_ids' . $meta_key );
        if ( false !== $cached ) {
            return (array) $cached;
        }
    }
    global $wpdb;
    $ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s",
        $meta_key
    ) ) );
    if ( $cached_key ) {
        set_transient( 'tconn_term_ids' . $meta_key, $ids, 0 );
    }
    return $ids;
}

/**
 * Flush the cached term ID set of a meta key.
 *
 * Runs automatically when a term gains or loses one of the cached keys through the
 * metadata API; call it after writing those keys with direct SQL.
 *
 * @since 1.0.0
 * @param string $meta_key Term meta key.
 */
function tconn_flush_term_ids_with_meta( string $meta_key ): void {
    delete_transient( 'tconn_term_ids' . $meta_key );
}

/**
 * Flush a cached term ID set when a term gains or loses its meta key.
 *
 * Hooked to added_term_meta and deleted_term_meta (which also fire when WordPress
 * deletes a term's meta with the term). Updates of an existing value don't change
 * the set.
 *
 * @since 1.0.0
 * @param int|int[] $meta_ids  Meta ID(s), unused.
 * @param int       $object_id Term ID, unused.
 * @param string    $meta_key  Meta key.
 */
function tconn_flush_term_ids_on_meta_change( $meta_ids, $object_id, $meta_key ): void {
    if ( in_array( $meta_key, TCONN_TERM_ID_CACHED_META_KEYS, true ) ) {
        tconn_flush_term_ids_with_meta( $meta_key );
    }
}
add_action( 'added_term_meta', 'tconn_flush_term_ids_on_meta_change', 10, 3 );
add_action( 'deleted_term_meta', 'tconn_flush_term_ids_on_meta_change', 10, 3 );

// ==============================================================================
// CRON
// ==============================================================================

add_action( 'tconn_refresh_identity_caches', 'tconn_cron_refresh_identity_caches' );

/**
 * Cron callback: refresh all stale delegated identity caches.
 *
 * Bails out after TCONN_CRON_TIME_BUDGET wall-clock seconds rather than
 * refreshing every stale term in one tick: on a site with hundreds of
 * delegated terms, each refresh is a synchronous wp_remote_get(), and an
 * unbounded loop risks hitting max_execution_time mid-run. Terms not reached
 * this tick stay stale (last_fetched unchanged) and are simply picked up on
 * the next scheduled run (twicedaily, per termconnect.php) — nothing here is
 * a queue that loses items by being skipped.
 *
 * Stale terms are refreshed oldest-first. A fixed order would let the same
 * leading terms (which go stale again after the cache setting's interval) consume
 * the budget every run, so terms near the end of the list would never refresh.
 *
 * Terms are refreshed partway through a run, so one refreshed a full interval ago
 * can look a few seconds too young. The time budget is allowed for here, or a
 * 24-hour setting would wait for the following run.
 */
function tconn_cron_refresh_identity_caches(): void {
    $deadline = time() + TCONN_CRON_TIME_BUDGET;
    $settings = get_option( 'tconn_settings', array() );
    $ttl      = max( 60, (int) ( $settings['identity_cache_ttl'] ?? TCONN_IDENTITY_CACHE_TTL ) );

    $stale = array();
    foreach ( tconn_get_term_ids_with_meta( TCONN_META_IDENTITY_SOURCE ) as $term_id ) {
        $source = tconn_get_identity_source( (int) $term_id );
        if ( null === $source ) {
            continue;
        }
        $last_fetched = (int) $source['last_fetched'];
        if ( time() - $last_fetched + TCONN_CRON_TIME_BUDGET >= $ttl ) {
            $stale[ (int) $term_id ] = $last_fetched;
        }
    }
    asort( $stale );

    foreach ( array_keys( $stale ) as $term_id ) {
        if ( time() >= $deadline ) {
            break;
        }
        tconn_refresh_identity_cache( $term_id );
    }
}
