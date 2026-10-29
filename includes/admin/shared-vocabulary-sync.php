<?php
/**
 * Shared Vocabulary Sync — sync a remote WordPress taxonomy into the local site.
 *
 * Implements the Shared Vocabularies feature. Each sync run:
 *   1. Fetches the full remote taxonomy via paginated WP REST (flat, with parent IDs).
 *   2. Reconstructs the remote tree locally.
 *   3. For each remote term in scope: match by slug → link or create local term.
 *   4. Detects renames (same remote_term_id, new slug) and renames local term.
 *   5. Detects orphans (previously delegated remote terms no longer in fetch).
 *
 * Slug is the canonical matching key (WordPress slug uniqueness per taxonomy
 * guarantees unambiguous matching). remote_term_id is stored as a secondary
 * reference for rename detection only.
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==============================================================================
// PUBLIC API
// ==============================================================================

/**
 * Sort vocabulary sources into fixed scope order: full → group → roots → terms.
 * Relative order within each scope is preserved (determines first-wins priority).
 *
 * @param array[] $sources Raw tc_shared_vocabularies array.
 * @return array[] Sorted copy.
 */
function tc_sv_sort_sources( array $sources ): array {
    $rank    = array( 'full' => 0, 'remote_group' => 1, 'local_group' => 2, 'roots' => 3, 'terms' => 4 );
    $buckets = array( 0 => array(), 1 => array(), 2 => array(), 3 => array(), 4 => array() );
    foreach ( $sources as $src ) {
        $r = $rank[ $src['scope'] ?? 'full' ] ?? 4;
        $buckets[ $r ][] = $src;
    }
    return array_merge( $buckets[0], $buckets[1], $buckets[2], $buckets[3], $buckets[4] );
}

/**
 * Build a human-readable one-line summary from a sync result array.
 *
 * @param array $result  Return value of tc_sv_sync_source().
 * @return string
 */
function tc_sv_format_sync_result( array $result ): string {
    $msg = sprintf(
        /* translators: 1: synced count, 2: created count, 3: renamed count */
        __( '%1$d synced, %2$d created, %3$d renamed', 'termconnect' ),
        (int) ( $result['synced'] ?? 0 ),
        (int) ( $result['created'] ?? 0 ),
        (int) ( $result['renamed'] ?? 0 )
    );
    if ( ! empty( $result['skipped'] ) ) {
        $msg .= ', ' . sprintf( __( '%d skipped', 'termconnect' ), (int) $result['skipped'] );
    }
    if ( ! empty( $result['orphaned'] ) ) {
        $msg .= ', ' . sprintf( __( '%d orphaned', 'termconnect' ), (int) $result['orphaned'] );
    }
    if ( ! empty( $result['rel_rows'] ) ) {
        $msg .= ', ' . sprintf( _n( '%d relationship row written', '%d relationship rows written', (int) $result['rel_rows'], 'termconnect' ), (int) $result['rel_rows'] );
    }
    if ( ! empty( $result['merge_proposals'] ) ) {
        $msg .= ', ' . sprintf( _n( '%d merge proposal', '%d merge proposals', (int) $result['merge_proposals'], 'termconnect' ), (int) $result['merge_proposals'] );
    }
    if ( ! empty( $result['errors'] ) ) {
        $msg .= ' — ' . $result['errors'][0];
    }
    return $msg;
}

/**
 * Sync a single vocabulary source against its remote peer.
 *
 * @param array $source  Source config from tc_shared_vocabularies option.
 * @return array { synced: int, created: int, renamed: int, misplaced: int, orphaned: int, errors: string[] }
 */
function tc_sv_sync_source( array $source ): array {
    $result = array(
        'synced'           => 0,
        'created'          => 0,
        'renamed'          => 0,
        'misplaced'        => 0,
        'orphaned'         => 0,
        'scope_exit'       => 0,
        'skipped'          => 0,
        'merge_proposals'  => 0,
        'rel_rows'         => 0,
        'stale_rel_terms'  => 0,
        'errors'           => array(),
    );

    $peer_site_slug   = $source['peer_site_slug'] ?? '';
    $taxonomy  = $source['taxonomy'] ?? '';
    $scope     = $source['scope'] ?? 'full';
    $roots     = $source['roots'] ?? array();
    $misplace  = $source['misplacement'] ?? 'flag';

    if ( ! $peer_site_slug ) {
        $result['errors'][] = 'Missing peer_site_slug.';
        return $result;
    }

    // Remote group scope: fetch a remote HT group's members and mirror them locally.
    if ( 'remote_group' === $scope ) {
        return tc_sv_sync_remote_group_source( $source, $result );
    }

    // Local group scope: use local HyperTaxonomy group members as the term list,
    // then delegate each member to the remote peer by slug.
    if ( 'local_group' === $scope ) {
        return tc_sv_sync_local_group_source( $source, $result );
    }

    if ( ! $taxonomy ) {
        $result['errors'][] = 'Missing taxonomy.';
        return $result;
    }

    $link_type = tc_get_link_type( $peer_site_slug );
    if ( ! $link_type ) {
        $result['errors'][] = 'Link type not found: ' . $peer_site_slug;
        return $result;
    }

    $origin = tc_external_get_site_origin( $link_type['uri_prefix'] ?? '' );
    if ( ! $origin ) {
        $result['errors'][] = 'Cannot resolve peer site URL.';
        return $result;
    }

    $rel_delegation = 'full' === $scope && ! empty( $source['relationship_delegation'] );
    $cross_taxonomy = $rel_delegation && ! empty( $source['cross_taxonomy_relationships'] );

    // Relationship delegation requires HT on the remote peer.
    if ( $rel_delegation && ! tc_external_is_ht_enabled( $origin ) ) {
        $rel_delegation = false;
        $result['errors'][] = __( 'Relationship delegation skipped — peer site does not have HyperTaxonomy active.', 'termconnect' );
    }

    // 1. Sync relationship type definitions before fetching terms, so types are
    //    registered locally before any relationship rows reference them.
    if ( $rel_delegation ) {
        $type_result = tc_sv_sync_relationship_types( $origin );
        if ( ! empty( $type_result['errors'] ) ) {
            foreach ( $type_result['errors'] as $e ) { $result['errors'][] = $e; }
        }
    }

    // 2. Fetch full remote taxonomy (paginated flat list).
    $extra_fields = $rel_delegation ? array( 'htax_includes', 'htax_relationships' ) : array();
    $remote_terms = tc_sv_fetch_taxonomy( $origin, $taxonomy, $extra_fields );
    if ( is_wp_error( $remote_terms ) ) {
        $result['errors'][] = $remote_terms->get_error_message();
        return $result;
    }

    // 3. Build remote lookup maps.
    $remote_by_id   = array(); // remote_term_id => remote_term_data
    $remote_by_slug = array(); // slug => remote_term_data
    foreach ( $remote_terms as $rt ) {
        $remote_by_id[ (int) $rt['id'] ]   = $rt;
        $remote_by_slug[ $rt['slug'] ]      = $rt;
    }

    // 4. Build existing-delegations map: remote_term_id => local_term_id (for this peer_site_slug).
    $delegations = tc_sv_build_delegation_map( $peer_site_slug );

    // 5. Determine scope — collect remote term IDs to process.
    // 'full' = entire taxonomy; 'roots' = subtrees; 'terms' = specific terms only (no descendants).
    if ( 'full' === $scope ) {
        $scope_ids = array_keys( $remote_by_id );
    } elseif ( 'terms' === $scope ) {
        $scope_ids = array_map( fn( $r ) => (int) ( $r['remote_term_id'] ?? 0 ), $roots );
        $scope_ids = array_filter( $scope_ids );
    } else {
        $scope_ids = tc_sv_subtree_ids( $roots, $remote_by_id );
    }

    // 6. Process each remote term in scope.
    foreach ( $scope_ids as $remote_id ) {
        $rt = $remote_by_id[ $remote_id ] ?? null;
        if ( ! $rt ) { continue; }

        $sync_desc = ( 'terms' !== $scope );
        $r = tc_sv_process_term( $rt, $peer_site_slug, $taxonomy, $origin, $delegations, $remote_by_id, $misplace, $sync_desc, $source['id'] ?? '' );
        $result['synced']    += $r['synced'];
        $result['created']   += $r['created'];
        $result['renamed']   += $r['renamed'];
        $result['misplaced'] += $r['misplaced'];
        $result['skipped']   += $r['skipped'];
        if ( ! empty( $r['error'] ) ) {
            $result['errors'][] = $r['error'];
        }
    }

    // 7. Scope-exit detection (roots and terms scopes only): delegated terms owned by
    //    this source that still exist on remote but have moved outside the configured
    //    scope. The remote term is fine — we simply stop mirroring it. Clear identity.
    if ( 'full' !== $scope ) {
        $scope_ids_index = array_flip( $scope_ids );
        $sv_source_id    = $source['id'] ?? '';
        foreach ( $delegations as $remote_id => $local_term_id ) {
            if ( isset( $scope_ids_index[ $remote_id ] ) ) {
                continue; // Still in scope — was processed in step 5.
            }
            if ( ! isset( $remote_by_id[ $remote_id ] ) ) {
                continue; // Not on remote — handled as orphan in step 6.
            }
            $src = tc_get_identity_source( $local_term_id );
            if ( ! $src || ( $src['sv_source_id'] ?? '' ) !== $sv_source_id ) {
                continue; // Not owned by this source.
            }
            tc_remove_identity_source( $local_term_id );
            $result['scope_exit']++;
        }
    }

    // 8. Detect orphans: delegated terms (for this peer_site_slug) whose remote_term_id
    //    is no longer present in the fetched taxonomy.
    //    Before marking as orphaned, attempt an individual slug fetch — the term
    //    may have been missed due to a race during pagination or moved to another
    //    taxonomy. Only apply the configured orphan action when confirmed gone.
    $orphaned_ids  = array();
    $orphan_action = $source['orphan_action'] ?? 'flag';
    $sv_source_id  = $source['id'] ?? '';
    foreach ( $delegations as $remote_id => $local_term_id ) {
        if ( isset( $remote_by_id[ $remote_id ] ) ) {
            continue;
        }
        $src = tc_get_identity_source( $local_term_id );
        if ( ! $src ) {
            continue;
        }
        if ( ( $src['sv_source_id'] ?? '' ) !== $sv_source_id ) {
            continue; // Owned by a different SV source on this peer — not an orphan of THIS source.
        }
        $remote_slug = $src['remote_slug'] ?? '';
        if ( $remote_slug ) {
            $refreshed = tc_fetch_remote_identity( $peer_site_slug, $taxonomy, $remote_slug );
            if ( is_wp_error( $refreshed ) ) {
                if ( 'tc_not_found' !== $refreshed->get_error_code() ) {
                    // Network error or other transient failure — leave status unchanged.
                    continue;
                }
                // tc_not_found: fall through to mark as orphaned below.
            } else {
                if ( ( $refreshed['type'] ?? '' ) !== 'term' ) {
                    // Redirect or unknown response type — skip, do not corrupt delegation.
                    continue;
                }
                // Still on remote — individual fetch recovered it; re-sync and move on.
                tc_set_identity_source( $local_term_id, $peer_site_slug, $remote_slug, $refreshed, (bool) ( $src['sync_descendants'] ?? false ), $src['sv_source_id'] ?? '' );
                $result['synced']++;
                continue;
            }
        }
        // Confirmed gone from remote — update status and apply configured action.
        if ( 'not_found' !== ( $src['status'] ?? '' ) ) {
            $src['status'] = 'not_found';
            update_term_meta( $local_term_id, TC_META_IDENTITY_SOURCE, $src );
        }
        $result['orphaned']++;
        $local_term = get_term( $local_term_id );
        if ( $local_term && ! is_wp_error( $local_term ) ) {
            $deleted = tc_sv_apply_orphan_action( $local_term_id, $local_term->taxonomy, $orphan_action );
            if ( ! $deleted ) {
                $orphaned_ids[] = $local_term_id;
            }
        }
    }

    // 9. Detect merge proposals: orphaned terms that share a KB identifier
    //    with an active delegation from the same peer.
    if ( ! empty( $orphaned_ids ) ) {
        $proposals = tc_sv_detect_merge_proposals( $orphaned_ids, $delegations, $remote_by_id, $peer_site_slug );
        if ( ! empty( $proposals ) ) {
            $existing_proposals = get_option( 'tc_merge_proposals', array() );
            $tc_settings        = get_option( 'tc_settings', array() );
            $auto_merge         = ! empty( $tc_settings['auto_accept_redirects'] ) && ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) );

            foreach ( $proposals as $proposal_id => $proposal ) {
                if ( $auto_merge && function_exists( 'htax_merge_terms' ) ) {
                    $merge_result = tc_sv_resolve_merge_proposal( $proposal );
                    if ( ! is_wp_error( $merge_result ) ) {
                        continue; // Merged — no proposal needed.
                    }
                }
                if ( ! isset( $existing_proposals[ $proposal_id ] ) ) {
                    $existing_proposals[ $proposal_id ] = $proposal;
                    $result['merge_proposals']++;
                }
            }
            update_option( 'tc_merge_proposals', $existing_proposals, false );
        }
    }

    // 10. Sync relationship rows (only for full-taxonomy scope with delegation enabled).
    if ( $rel_delegation ) {
        $row_result = tc_sv_sync_relationship_rows( $remote_terms, $delegations, $taxonomy, $cross_taxonomy );
        $result['rel_rows'] += $row_result['rows_written'];
        foreach ( $row_result['errors'] as $e ) {
            $result['errors'][] = $e;
        }
    }

    // 11. Flag stale relationship rows: custom-type rows that exist locally but were
    //     not present in the remote htax_relationships data for this sync.
    if ( $rel_delegation ) {
        $result['stale_rel_terms'] += tc_sv_flag_stale_relationship_rows( $remote_terms, $delegations, $peer_site_slug, $origin, $taxonomy );
    }

    // 12. Update last_sync timestamp and result summary.
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
    if ( $lock_acquired ) {
        try {
            $sources = get_option( 'tc_shared_vocabularies', array() );
            foreach ( $sources as &$s ) {
                if ( ( $s['id'] ?? '' ) === ( $source['id'] ?? '' ) ) {
                    $s['last_sync']        = time();
                    $s['last_sync_result'] = tc_sv_format_sync_result( $result );
                    break;
                }
            }
            unset( $s );
            update_option( 'tc_shared_vocabularies', $sources );
        } finally {
            delete_transient( 'tc_sv_write_lock' );
        }
    } else {
        error_log( 'TermConnect: could not acquire write lock to save sync result for source ' . ( $source['id'] ?? '' ) );
    }

    return $result;
}

/**
 * Process a single remote term: match by slug → link or create; detect renames.
 *
 * @param array  $rt           Remote term data.
 * @param string $peer_site_slug      Peer link type slug.
 * @param string $taxonomy     Local taxonomy slug.
 * @param string $origin       Peer site base URL.
 * @param array  $delegations  remote_term_id => local_term_id map (passed by reference to update on create).
 * @param array  $remote_by_id Full remote id => term_data map (for parent resolution).
 * @param string $misplace     Misplacement handling: 'flag' | 'link-only' | 're-parent'.
 * @return array { synced, created, renamed, misplaced, error }
 */
function tc_sv_process_term( array $rt, string $peer_site_slug, string $taxonomy, string $origin, array &$delegations, array $remote_by_id, string $misplace, bool $sync_desc = true, string $sv_source_id = '' ): array {
    $r = array( 'synced' => 0, 'created' => 0, 'renamed' => 0, 'misplaced' => 0, 'skipped' => 0, 'error' => '' );

    $remote_id   = (int) $rt['id'];
    $remote_slug = $rt['slug'];

    // Determine correct local parent: find the local term corresponding to remote parent.
    $local_parent_id = 0;
    $remote_parent   = (int) ( $rt['parent'] ?? 0 );
    if ( $remote_parent ) {
        $local_parent_id = $delegations[ $remote_parent ] ?? 0;
        if ( ! $local_parent_id ) {
            // Parent not yet delegated — try slug match.
            $parent_rt   = $remote_by_id[ $remote_parent ] ?? null;
            $parent_slug = $parent_rt['slug'] ?? '';
            if ( $parent_slug ) {
                $local_parent = get_term_by( 'slug', $parent_slug, $taxonomy );
                $local_parent_id = $local_parent ? (int) $local_parent->term_id : 0;
            }
        }
    }

    // --- Rename detection via remote_term_id ---
    if ( isset( $delegations[ $remote_id ] ) ) {
        $local_term_id = $delegations[ $remote_id ];
        $local_term    = get_term( $local_term_id );

        if ( ! $local_term || is_wp_error( $local_term ) ) {
            // Stale entry; remove and fall through.
            unset( $delegations[ $remote_id ] );
        } else {
            // First-wins: tc_sv_build_delegation_map() matches by peer_site_slug alone,
            // so this remote_term_id can show up here only because a *different*,
            // higher-priority source already claimed it (same peer, different source).
            // That source is the sole authority for this term's rename/reparent/identity
            // — skip entirely rather than have two sources fight over it.
            $existing_src = tc_get_identity_source( $local_term_id );
            if ( $existing_src && ! empty( $existing_src['sv_source_id'] ) && $existing_src['sv_source_id'] !== $sv_source_id ) {
                $r['skipped']++;
                return $r;
            }

            // Check for slug change → rename local term.
            if ( $local_term->slug !== $remote_slug ) {
                $rename_result = wp_update_term( $local_term_id, $taxonomy, array( 'slug' => $remote_slug, 'name' => $rt['name'] ) );
                if ( ! is_wp_error( $rename_result ) ) {
                    $r['renamed']++;
                } else {
                    error_log( 'TermConnect: sv rename failed for term ' . $local_term_id . ': ' . $rename_result->get_error_message() );
                }
            }

            // Check parent position → misplacement handling.
            if ( $local_parent_id && (int) $local_term->parent !== $local_parent_id ) {
                if ( 're-parent' === $misplace ) {
                    $reparent_result = wp_update_term( $local_term_id, $taxonomy, array( 'parent' => $local_parent_id ) );
                    if ( is_wp_error( $reparent_result ) ) {
                        error_log( 'TermConnect: sv re-parent failed for term ' . $local_term_id . ': ' . $reparent_result->get_error_message() );
                    }
                } elseif ( 'flag' === $misplace ) {
                    tc_sv_flag_misplaced( $local_term_id );
                    $r['misplaced']++;
                }
                // 'link-only': leave parent unchanged.
            }

            // Update identity data using already-fetched remote term payload.
            $set_result = tc_set_identity_source( $local_term_id, $peer_site_slug, $remote_slug, tc_sv_build_remote_payload( $rt, $origin ), $sync_desc, $sv_source_id );
            if ( is_wp_error( $set_result ) ) {
                $r['error'] = $set_result->get_error_message();
                return $r;
            }
            $r['synced']++;
            return $r;
        }
    }

    // --- Slug match ---
    $local_term = get_term_by( 'slug', $remote_slug, $taxonomy );

    if ( $local_term && ! is_wp_error( $local_term ) ) {
        $local_term_id = (int) $local_term->term_id;

        // First-wins: if already claimed by a different vocabulary source, skip. Compared
        // by sv_source_id, not peer_site_slug — two sources can target the same peer (e.g.
        // a Taxonomies-scope source and a Term Hierarchies source both pointing at "My Peer
        // Blog"), and the earlier-checked one must still win.
        $existing_src = tc_get_identity_source( $local_term_id );
        if ( $existing_src && ! empty( $existing_src['sv_source_id'] ) && $existing_src['sv_source_id'] !== $sv_source_id ) {
            $r['skipped']++;
            return $r;
        }

        // Check parent position.
        if ( $local_parent_id && (int) $local_term->parent !== $local_parent_id ) {
            if ( 're-parent' === $misplace ) {
                $reparent_result = wp_update_term( $local_term_id, $taxonomy, array( 'parent' => $local_parent_id ) );
                if ( is_wp_error( $reparent_result ) ) {
                    error_log( 'TermConnect: sv re-parent (new term) failed for term ' . $local_term_id . ': ' . $reparent_result->get_error_message() );
                }
            } elseif ( 'flag' === $misplace ) {
                tc_sv_flag_misplaced( $local_term_id );
                $r['misplaced']++;
            }
        }

        // Link: set or update identity source.
        $set_result = tc_set_identity_source( $local_term_id, $peer_site_slug, $remote_slug, tc_sv_build_remote_payload( $rt, $origin ), $sync_desc, $sv_source_id );
        if ( is_wp_error( $set_result ) ) {
            $r['error'] = $set_result->get_error_message();
            return $r;
        }
        $delegations[ $remote_id ] = $local_term_id;
        $r['synced']++;
        return $r;
    }

    // --- Create new local term ---
    // Decode HTML entities from the remote name (REST API may return encoded strings).
    $insert_name = html_entity_decode( $rt['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $inserted = wp_insert_term( $insert_name, $taxonomy, array(
        'slug'   => $remote_slug,
        'parent' => $local_parent_id,
    ) );

    if ( is_wp_error( $inserted ) ) {
        $r['error'] = 'Failed to create term "' . $remote_slug . '": ' . $inserted->get_error_message();
        return $r;
    }

    $local_term_id = (int) $inserted['term_id'];
    $set_result = tc_set_identity_source( $local_term_id, $peer_site_slug, $remote_slug, tc_sv_build_remote_payload( $rt, $origin ), $sync_desc, $sv_source_id );
    if ( is_wp_error( $set_result ) ) {
        $r['error'] = $set_result->get_error_message();
        return $r;
    }
    $delegations[ $remote_id ] = $local_term_id;
    $r['created']++;
    return $r;
}

// ==============================================================================
// REMOTE GROUP SCOPE
// ==============================================================================

/**
 * Sync a remote-group-scoped source: fetch a remote HT group's member list via
 * the peer's tc/v1/group-members endpoint, then create or update local terms and
 * assign them to a local HT group (matched by slug or auto-created).
 *
 * @param array $source  Source config with scope='remote_group'.
 * @param array $result  Partial result array (passed from tc_sv_sync_source).
 * @return array Updated result array.
 */
function tc_sv_sync_remote_group_source( array $source, array $result ): array {
    if ( ! function_exists( 'htax_get_term_group_ids' ) || ! function_exists( 'htax_set_term_groups' ) ) {
        $result['errors'][] = __( 'Remote group scope requires HyperTaxonomy to be active.', 'termconnect' );
        return $result;
    }

    $peer_site_slug    = $source['peer_site_slug'] ?? '';
    $remote_group_slug = $source['remote_group_slug'] ?? '';
    $source_id         = $source['id'] ?? '';
    $orphan_action     = $source['orphan_action'] ?? 'flag';
    $sync_desc         = (bool) ( $source['sync_descendants'] ?? false );

    $link_type = $peer_site_slug ? tc_get_link_type( $peer_site_slug ) : null;
    $origin    = $link_type ? tc_external_get_site_origin( $link_type['uri_prefix'] ?? '' ) : '';

    if ( ! $origin || ! $remote_group_slug ) {
        $result['errors'][] = __( 'Missing peer site or remote group slug.', 'termconnect' );
        return $result;
    }

    // 1. Fetch remote group members via tc/v1/group-members endpoint.
    $url = trailingslashit( $origin ) . 'wp-json/tc/v1/group-members?' . http_build_query( array( 'group_slug' => $remote_group_slug ) );
    $response = wp_remote_get( $url, array(
        'timeout'    => 20,
        'user-agent' => 'TermConnect/' . TC_VERSION,
        'headers'    => array( 'X-TermConnect' => TC_VERSION ),
    ) );

    if ( is_wp_error( $response ) ) {
        $result['errors'][] = $response->get_error_message();
        return $result;
    }
    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( 200 !== $code ) {
        $result['errors'][] = sprintf(
            /* translators: HTTP status code */
            __( 'Remote group endpoint returned HTTP %d.', 'termconnect' ),
            $code
        );
        return $result;
    }
    $body = tc_external_json_decode( wp_remote_retrieve_body( $response ) );
    if ( ! is_array( $body ) || ! isset( $body['members'] ) ) {
        $result['errors'][] = __( 'Invalid response from remote group endpoint.', 'termconnect' );
        return $result;
    }

    $remote_group_name = sanitize_text_field( html_entity_decode( $body['group_name'] ?? $remote_group_slug, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    $remote_group_id   = absint( $body['group_id'] ?? 0 );

    // 2. Ensure local group exists: slug-match, else auto-create.
    $local_group_id = absint( $source['local_group_id'] ?? 0 );
    if ( ! $local_group_id || ! get_term( $local_group_id ) ) {
        $local_group = get_term_by( 'slug', $remote_group_slug, 'group' );
        if ( $local_group instanceof WP_Term ) {
            $local_group_id = $local_group->term_id;
        } else {
            $inserted = wp_insert_term( $remote_group_name, 'group', array( 'slug' => $remote_group_slug ) );
            if ( is_wp_error( $inserted ) ) {
                $result['errors'][] = __( 'Could not create local group: ', 'termconnect' ) . $inserted->get_error_message();
                return $result;
            }
            $local_group_id = (int) $inserted['term_id'];
        }
    }

    // 3. Build per-taxonomy remote term maps.
    $to_process   = array(); // taxonomy => [ remote_id => rt_data ]
    $remote_by_id = array(); // taxonomy => [ remote_id => rt_data ] (includes parent stubs)

    foreach ( (array) $body['members'] as $member ) {
        $taxonomy  = sanitize_key( $member['taxonomy'] ?? '' );
        $remote_id = absint( $member['id'] ?? 0 );
        if ( ! $taxonomy || ! $remote_id ) { continue; }

        $rt = array(
            'id'          => $remote_id,
            'slug'        => sanitize_title( $member['slug'] ?? '' ),
            'name'        => html_entity_decode( sanitize_text_field( $member['name'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
            'description' => wp_kses_post( $member['description'] ?? '' ),
            'parent'      => absint( $member['parent'] ?? 0 ),
            'parent_slug' => sanitize_title( $member['parent_slug'] ?? '' ),
            'link'        => '',
            'tc_links'    => is_array( $member['tc_links'] ?? null ) ? $member['tc_links'] : array(),
        );

        $to_process[ $taxonomy ][ $remote_id ]   = $rt;
        $remote_by_id[ $taxonomy ][ $remote_id ] = $rt;
    }

    // Add parent stubs so tc_sv_process_term can resolve parent slugs for non-member parents.
    foreach ( $to_process as $taxonomy => $members ) {
        foreach ( $members as $rt ) {
            $parent_id   = (int) ( $rt['parent'] ?? 0 );
            $parent_slug = $rt['parent_slug'] ?? '';
            if ( $parent_id && $parent_slug && ! isset( $remote_by_id[ $taxonomy ][ $parent_id ] ) ) {
                $remote_by_id[ $taxonomy ][ $parent_id ] = array(
                    'id'   => $parent_id,
                    'slug' => $parent_slug,
                    'name' => $parent_slug,
                );
            }
        }
    }

    // 4. Build delegation map (snapshot before processing for scope-exit detection).
    $delegations      = tc_sv_build_delegation_map( $peer_site_slug );
    $prior_delegations = $delegations;

    // 5. Process each remote member term.
    $processed_local_ids = array();

    foreach ( $to_process as $taxonomy => $members ) {
        foreach ( $members as $remote_id => $rt ) {
            $r = tc_sv_process_term(
                $rt,
                $peer_site_slug,
                $taxonomy,
                $origin,
                $delegations,
                $remote_by_id[ $taxonomy ],
                'flag',
                $sync_desc,
                $source_id
            );
            $result['synced']  += $r['synced'];
            $result['created'] += $r['created'];
            $result['renamed'] += $r['renamed'];
            if ( ! empty( $r['error'] ) ) {
                $result['errors'][] = $r['error'];
            }

            // Add synced/created term to local group.
            $local_term_id = $delegations[ $remote_id ] ?? 0;
            if ( $local_term_id && $local_group_id ) {
                $current_groups = htax_get_term_group_ids( $local_term_id );
                if ( ! in_array( $local_group_id, $current_groups, true ) ) {
                    htax_set_term_groups(
                        $local_term_id,
                        array_values( array_unique( array_merge( $current_groups, array( $local_group_id ) ) ) )
                    );
                }
                $processed_local_ids[] = $local_term_id;
            }
        }
    }

    // 6. Scope-exit: terms previously owned by this source no longer in the remote group.
    foreach ( $prior_delegations as $remote_id => $local_term_id ) {
        if ( in_array( $local_term_id, $processed_local_ids, true ) ) { continue; }
        $src = tc_get_identity_source( $local_term_id );
        if ( ! $src || ( $src['sv_source_id'] ?? '' ) !== $source_id ) { continue; }

        if ( $local_group_id ) {
            $local_term = get_term( $local_term_id );
            if ( $local_term instanceof WP_Term ) {
                $current_groups = htax_get_term_group_ids( $local_term_id );
                $updated        = array_values( array_filter( $current_groups, fn( $id ) => $id !== $local_group_id ) );
                if ( count( $updated ) !== count( $current_groups ) ) {
                    htax_set_term_groups( $local_term_id, $updated );
                }
            }
        }

        tc_remove_identity_source( $local_term_id );
        $result['scope_exit'] = ( $result['scope_exit'] ?? 0 ) + 1;
    }

    // 7. Update last_sync timestamp and cache local_group_id + remote metadata.
    $lock_acquired = false;
    for ( $i = 0; $i < 3; $i++ ) {
        if ( false === get_transient( 'tc_sv_write_lock' ) ) {
            set_transient( 'tc_sv_write_lock', 1, 10 );
            $lock_acquired = true;
            break;
        }
        usleep( 200000 );
    }
    if ( $lock_acquired ) {
        try {
            $sources = get_option( 'tc_shared_vocabularies', array() );
            foreach ( $sources as &$s ) {
                if ( ( $s['id'] ?? '' ) === $source_id ) {
                    $s['local_group_id']    = $local_group_id;
                    $s['remote_group_id']   = $remote_group_id;
                    $s['remote_group_name'] = $remote_group_name;
                    $s['last_sync']         = time();
                    $s['last_sync_result']  = tc_sv_format_sync_result( $result );
                    break;
                }
            }
            unset( $s );
            update_option( 'tc_shared_vocabularies', $sources );
        } finally {
            delete_transient( 'tc_sv_write_lock' );
        }
    } else {
        error_log( 'TermConnect: could not acquire write lock to save sync result for source ' . $source_id );
    }

    return $result;
}

/**
 * Remove all local group memberships for terms owned by a remote_group source.
 * Called when the source is deleted so terms are cleanly unlinked from the local group.
 *
 * @since 1.0.0
 * @param int    $local_group_id Local group term ID to remove members from.
 * @param string $sv_source_id   SV source ID whose owned terms should be removed.
 */
function tc_sv_remove_remote_group_memberships( int $local_group_id, string $sv_source_id ): void {
    if ( ! $local_group_id || ! $sv_source_id ) { return; }
    if ( ! function_exists( 'htax_get_term_group_ids' ) || ! function_exists( 'htax_set_term_groups' ) ) { return; }

    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s",
        TC_META_IDENTITY_SOURCE
    ) );
    foreach ( $rows as $row ) {
        $src = maybe_unserialize( $row->meta_value );
        if ( ! is_array( $src ) || ( $src['sv_source_id'] ?? '' ) !== $sv_source_id ) { continue; }
        $term_id = (int) $row->term_id;
        $term    = get_term( $term_id );
        if ( ! $term instanceof WP_Term ) { continue; }
        $current = htax_get_term_group_ids( $term_id );
        $updated = array_values( array_filter( $current, fn( $id ) => $id !== $local_group_id ) );
        if ( count( $updated ) !== count( $current ) ) {
            htax_set_term_groups( $term_id, $updated );
        }
    }
}

// ==============================================================================
// LOCAL_GROUP SCOPE
// ==============================================================================

/**
 * Sync a local_group-scoped source: use a local HyperTaxonomy group as the term list,
 * then delegate each member to the remote peer by slug.
 *
 * @param array $source  Source config with scope='local_group'.
 * @param array $result  Partial result array (passed from tc_sv_sync_source).
 * @return array Updated result array.
 */
function tc_sv_sync_local_group_source( array $source, array $result ): array {
    if ( ! function_exists( 'htax_get_terms_in_groups' ) ) {
        $result['errors'][] = 'Group scope requires HyperTaxonomy to be active.';
        return $result;
    }

    $peer_site_slug      = $source['peer_site_slug'];
    $group_id     = (int) ( $source['group_id'] ?? 0 );
    $sync_desc    = (bool) ( $source['sync_descendants'] ?? false );
    $enabled_taxa = TC_Settings::get_enabled_taxonomies();

    if ( ! $group_id ) {
        $result['errors'][] = 'Group scope: no group_id configured.';
        return $result;
    }

    // Build delegation map to identify previously-delegated terms for scope-exit detection.
    $existing_delegations = tc_sv_build_delegation_map( $peer_site_slug );
    $source_id            = $source['id'] ?? '';
    $orphan_action        = $source['orphan_action'] ?? 'flag';

    $processed_local_ids = array();

    foreach ( $enabled_taxa as $taxonomy ) {
        $members = htax_get_terms_in_groups( array( $group_id ), $taxonomy );
        if ( empty( $members ) ) { continue; }

        foreach ( $members as $local_term ) {
            $local_term_id = (int) $local_term->term_id;

            $existing_src = tc_get_identity_source( $local_term_id );
            if ( $existing_src ) {
                // Owned by this source: same sv_source_id, or (for a manual, non-SV
                // delegation with no sv_source_id at all) the same peer — adopt it. Owned
                // by a *different* SV source, even one pointing at the same peer, means a
                // higher-priority source already claimed this term: skip, don't overwrite.
                $owned_by_this_source = empty( $existing_src['sv_source_id'] )
                    ? ( ( $existing_src['peer_site_slug'] ?? '' ) === $peer_site_slug )
                    : ( $existing_src['sv_source_id'] === $source_id );
                if ( $owned_by_this_source ) {
                    // Mark as visited before refresh so scope-exit block ignores this term
                    // regardless of whether the refresh succeeds or fails.
                    $processed_local_ids[] = $local_term_id;
                    $refresh = tc_refresh_identity_cache( $local_term_id );
                    if ( is_wp_error( $refresh ) ) {
                        if ( 'tc_not_found' === $refresh->get_error_code() ) {
                            // Remote term gone while local term is still a group member.
                            $result['orphaned']++;
                            tc_sv_apply_orphan_action( $local_term_id, $taxonomy, $orphan_action );
                        }
                        // 'unreachable': transient error — leave status, don't count as synced.
                        continue;
                    }
                    $result['synced']++;
                } else {
                    $result['skipped'] = ( $result['skipped'] ?? 0 ) + 1;
                }
                continue;
            }

            // Delegate by slug — remote must have a term with the same slug.
            $remote = tc_fetch_remote_identity( $peer_site_slug, $taxonomy, $local_term->slug );
            if ( is_wp_error( $remote ) ) {
                if ( 'tc_not_found' !== $remote->get_error_code() ) {
                    $result['errors'][] = $local_term->slug . ': ' . $remote->get_error_message();
                }
                continue;
            }

            tc_set_identity_source( $local_term_id, $peer_site_slug, $local_term->slug, $remote, $sync_desc, $source_id );
            $result['synced']++;
            $processed_local_ids[] = $local_term_id;
        }
    }

    // Scope-exit: terms previously delegated by this source that are no longer group members.
    // Group scope is dynamic — membership defines delegation. Terms that left the group
    // simply have their identity cleared; no remote fetch needed.
    foreach ( $existing_delegations as $local_term_id ) {
        if ( in_array( $local_term_id, $processed_local_ids, true ) ) {
            continue;
        }
        $src = tc_get_identity_source( $local_term_id );
        if ( ! $src || ( $src['sv_source_id'] ?? '' ) !== $source_id ) {
            continue; // Not owned by this source.
        }
        tc_remove_identity_source( $local_term_id );
        $result['scope_exit'] = ( $result['scope_exit'] ?? 0 ) + 1;
    }

    // Update last_sync timestamp and result summary.
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
    if ( $lock_acquired ) {
        try {
            $sources = get_option( 'tc_shared_vocabularies', array() );
            foreach ( $sources as &$s ) {
                if ( ( $s['id'] ?? '' ) === ( $source['id'] ?? '' ) ) {
                    $s['last_sync']        = time();
                    $s['last_sync_result'] = tc_sv_format_sync_result( $result );
                    break;
                }
            }
            unset( $s );
            update_option( 'tc_shared_vocabularies', $sources );
        } finally {
            delete_transient( 'tc_sv_write_lock' );
        }
    } else {
        error_log( 'TermConnect: could not acquire write lock to save sync result for source ' . ( $source['id'] ?? '' ) );
    }

    return $result;
}

// ==============================================================================
// HELPERS
// ==============================================================================

/**
 * Apply the configured action to a delegated term confirmed missing on its remote site.
 *
 * The caller is responsible for setting status='not_found' on the identity source
 * before calling this function. Returns true if the term was deleted.
 *
 * @param int    $local_term_id Local term ID.
 * @param string $taxonomy      Term taxonomy slug.
 * @param string $action        'flag' | 'delete_clean' | 'delete'.
 * @return bool True if the term was deleted.
 */
function tc_sv_apply_orphan_action( int $local_term_id, string $taxonomy, string $action ): bool {
    if ( 'flag' === $action ) {
        return false;
    }

    if ( 'delete_clean' === $action ) {
        global $wpdb;
        $tt_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s",
            $local_term_id,
            $taxonomy
        ) );
        if ( ! $tt_id ) {
            return false;
        }
        $post_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
            $tt_id
        ) );
        if ( $post_count > 0 ) {
            return false;
        }
        if ( function_exists( 'htax_get_included_terms' ) ) {
            $ht_table  = $wpdb->prefix . 'htax_term_relationships';
            $rel_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$ht_table} WHERE source_term_id = %d OR target_term_id = %d",
                $local_term_id,
                $local_term_id
            ) );
            if ( $rel_count > 0 ) {
                return false;
            }
        }
    }

    $deleted = wp_delete_term( $local_term_id, $taxonomy );
    return ! is_wp_error( $deleted ) && false !== $deleted;
}

/**
 * Fetch all terms for a taxonomy from the remote peer (paginated flat list).
 *
 * @return array[]|WP_Error
 */
function tc_sv_fetch_taxonomy( string $origin, string $taxonomy, array $extra_fields = array() ) {
    $rest_base = tc_external_get_taxonomy_rest_base( $taxonomy );
    $terms     = array();
    $page      = 1;
    $base_fields = array( 'id', 'slug', 'name', 'parent', 'description', 'link', 'tc_links', 'htax_redirect' );
    $fields      = implode( ',', array_unique( array_merge( $base_fields, $extra_fields ) ) );

    // HT's collection endpoint only includes htax_includes/htax_relationships (even when
    // requested via _fields) for requests that identify themselves as a peer via this
    // header — plain _fields filtering isn't enough on its own.
    $headers = array( 'X-TermConnect' => TC_VERSION );
    if ( ! empty( $extra_fields ) ) {
        $headers['X-HyperTaxonomy'] = defined( 'HTAX_VERSION' ) ? HTAX_VERSION : '1.0.0';
    }

    $max_pages = 500;
    do {
        $url = trailingslashit( $origin ) . 'wp-json/wp/v2/' . $rest_base . '?' . http_build_query( array(
            'per_page' => 100,
            '_fields'  => $fields,
            'page'     => $page,
        ) );

        $response = wp_remote_get( $url, array(
            'timeout'    => 15,
            'user-agent' => 'TermConnect/' . TC_VERSION,
            'headers'    => $headers,
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'tc_unreachable', $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            if ( 400 === $code || 404 === $code ) { break; } // no more pages
            return new WP_Error( 'tc_remote_error', sprintf( __( 'Remote site returned HTTP %d.', 'termconnect' ), $code ) );
        }

        $body = tc_external_json_decode( wp_remote_retrieve_body( $response ) );
        if ( ! is_array( $body ) || empty( $body ) ) { break; }

        foreach ( $body as $term ) {
            if ( ! empty( $term['id'] ) ) {
                $terms[] = $term;
            }
        }

        $total_pages = (int) wp_remote_retrieve_header( $response, 'x-wp-totalpages' );
        $page++;
    } while ( $page <= $total_pages && $page <= $max_pages );

    return $terms;
}

/**
 * Build a remote_term_id → local_term_id map for all delegated terms pointing at $peer_site_slug.
 *
 * @return array<int, int>
 */
function tc_sv_build_delegation_map( string $peer_site_slug ): array {
    global $wpdb;
    $map  = array();
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s",
        TC_META_IDENTITY_SOURCE
    ) );
    foreach ( $rows as $row ) {
        $src = maybe_unserialize( $row->meta_value );
        if ( is_array( $src ) && ( $src['peer_site_slug'] ?? '' ) === $peer_site_slug && ! empty( $src['remote_term_id'] ) ) {
            $map[ (int) $src['remote_term_id'] ] = (int) $row->term_id;
        }
    }
    return $map;
}

/**
 * Return all remote term IDs that are in any root's subtree.
 *
 * @param array[] $roots       Source roots: [ ['remote_term_id' => int, 'remote_slug' => string], ... ]
 * @param array   $remote_by_id  Full id => term_data map.
 * @return int[]
 */
function tc_sv_subtree_ids( array $roots, array $remote_by_id ): array {
    // Build parent → children index.
    $children_of = array();
    foreach ( $remote_by_id as $id => $rt ) {
        $parent = (int) ( $rt['parent'] ?? 0 );
        $children_of[ $parent ][] = $id;
    }

    $ids = array();
    foreach ( $roots as $root ) {
        $root_id = (int) ( $root['remote_term_id'] ?? 0 );
        if ( ! $root_id || ! isset( $remote_by_id[ $root_id ] ) ) { continue; }
        tc_sv_walk_subtree( $root_id, $children_of, $ids );
    }
    return array_unique( $ids );
}

/** Recursively collect IDs for a subtree. */
function tc_sv_walk_subtree( int $id, array &$children_of, array &$ids, array &$visited = array() ): void {
    if ( isset( $visited[ $id ] ) ) {
        return;
    }
    $visited[ $id ] = true;
    $ids[] = $id;
    foreach ( ( $children_of[ $id ] ?? array() ) as $child_id ) {
        tc_sv_walk_subtree( $child_id, $children_of, $ids, $visited );
    }
}

/**
 * Build the $remote payload array expected by tc_set_identity_source from a raw REST term.
 */
function tc_sv_build_remote_payload( array $rt, string $origin ): array {
    $tc_links       = $rt['tc_links'] ?? array();
    $delegated_links = array();
    if ( is_array( $tc_links ) ) {
        foreach ( $tc_links as $entry ) {
            if ( ! empty( $entry['url'] ) ) {
                $delegated_links[] = array(
                    'url'        => esc_url_raw( (string) $entry['url'] ),
                    'platform'   => sanitize_key( (string) ( $entry['platform'] ?? '' ) ),
                    'label'      => sanitize_text_field( (string) ( $entry['label'] ?? '' ) ),
                    'fetched_at' => (int) ( $entry['fetched_at'] ?? 0 ),
                );
            }
        }
    }
    return array(
        'term_id'         => (int) ( $rt['id'] ?? 0 ),
        'slug'            => sanitize_title( $rt['slug'] ?? '' ),
        'name'            => sanitize_text_field( html_entity_decode( $rt['name'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
        'description'     => wp_kses_post( $rt['description'] ?? '' ),
        'link'            => esc_url_raw( $rt['link'] ?? '' ),
        'delegated_links' => $delegated_links,
    );
}

/**
 * Detect merge proposals: orphaned terms that share a KB identifier with an
 * active delegation from the same peer, suggesting a concept rename.
 *
 * A remote term that replaces an orphan (e.g. renamed on the peer, same KB
 * identifier) only needs to be checked against already-active delegations, not
 * matched directly against the raw remote fetch: within a single sync pass, any
 * in-scope remote term gets created and added to $delegations (by reference) in
 * the "process remote terms" step that runs before orphan/merge detection, so by
 * the time this runs it's already an active delegation like any other.
 *
 * @param int[]  $orphaned_ids  Local term IDs just marked as orphaned in this run.
 * @param array  $delegations   remote_term_id => local_term_id map (full, including orphans).
 * @param array  $remote_by_id  Full remote id => term_data map from this sync.
 * @param string $peer_site_slug       Peer link type slug.
 * @return array Keyed by proposal ID (md5 hash). Each entry: orphan/candidate term data.
 */
function tc_sv_detect_merge_proposals( array $orphaned_ids, array $delegations, array $remote_by_id, string $peer_site_slug ): array {
    // Collect local IDs of currently-active delegations (remote still present).
    $active_local_ids = array();
    foreach ( $delegations as $remote_id => $local_term_id ) {
        if ( isset( $remote_by_id[ $remote_id ] ) ) {
            $active_local_ids[] = $local_term_id;
        }
    }

    if ( empty( $active_local_ids ) ) {
        return array();
    }

    $proposals = array();

    foreach ( $orphaned_ids as $orphan_id ) {
        $orphan_uris = array_values( array_filter( (array) get_term_meta( $orphan_id, '_tc_uris' ), 'is_string' ) );
        if ( empty( $orphan_uris ) ) {
            continue;
        }
        $orphan_term = get_term( $orphan_id );
        if ( ! $orphan_term || is_wp_error( $orphan_term ) ) {
            continue;
        }

        foreach ( $active_local_ids as $cand_id ) {
            $cand_uris = array_values( array_filter( (array) get_term_meta( $cand_id, '_tc_uris' ), 'is_string' ) );
            if ( empty( $cand_uris ) ) {
                continue;
            }
            $common = array_intersect( $orphan_uris, $cand_uris );
            if ( empty( $common ) ) {
                continue;
            }
            $cand_term = get_term( $cand_id );
            if ( ! $cand_term || is_wp_error( $cand_term ) ) {
                continue;
            }
            $proposal_id               = md5( $orphan_id . '_' . $cand_id );
            $proposals[ $proposal_id ] = array(
                'orphan_term_id'    => $orphan_id,
                'orphan_name'       => $orphan_term->name,
                'orphan_slug'       => $orphan_term->slug,
                'candidate_term_id' => $cand_id,
                'candidate_name'    => $cand_term->name,
                'candidate_slug'    => $cand_term->slug,
                'taxonomy'          => $orphan_term->taxonomy,
                'detected_at'       => time(),
                'match_reason'      => reset( $common ),
                'peer_site_slug'    => $peer_site_slug,
            );
            break; // One proposal per orphan.
        }
    }

    return $proposals;
}

/**
 * Resolve a merge proposal: merge the orphan into its candidate via HyperTaxonomy.
 * Shared by the manual "Merge" AJAX action and the auto-merge path in
 * tc_sv_sync_source(), so both go through the same call.
 *
 * @param array $proposal One entry from the tc_merge_proposals option.
 * @return array|WP_Error htax_merge_terms() result, or WP_Error on failure.
 */
function tc_sv_resolve_merge_proposal( array $proposal ) {
    if ( ! function_exists( 'htax_merge_terms' ) ) {
        return new WP_Error( 'tc_no_ht', __( 'HyperTaxonomy is required to merge terms.', 'termconnect' ) );
    }

    return htax_merge_terms(
        array( (int) $proposal['orphan_term_id'] ),
        (int) $proposal['candidate_term_id'],
        sanitize_key( $proposal['taxonomy'] ),
        true
    );
}

/**
 * Store a term in the misplacement review queue (Site Health).
 */
function tc_sv_flag_misplaced( int $local_term_id ): void {
    $queue   = get_option( 'tc_sv_misplaced_queue', array() );
    $key     = (string) $local_term_id;
    if ( ! isset( $queue[ $key ] ) ) {
        $queue[ $key ] = array( 'term_id' => $local_term_id, 'detected_at' => time() );
        update_option( 'tc_sv_misplaced_queue', $queue, false );
    }
}

/**
 * Pre-sync conflict check: return local terms whose slugs match remote terms in scope
 * but have no existing _tc_identity_source delegation to this peer_site_slug.
 *
 * @return array[] [ ['local_term_id' => int, 'slug' => string, 'name' => string], ... ]
 */
function tc_sv_conflict_check( array $source ): array {
    $peer_site_slug  = $source['peer_site_slug'] ?? '';
    $taxonomy = $source['taxonomy'] ?? '';
    $scope    = $source['scope'] ?? 'full';
    $roots    = $source['roots'] ?? array();

    $link_type = tc_get_link_type( $peer_site_slug );
    if ( ! $link_type ) { return array(); }

    $origin = tc_external_get_site_origin( $link_type['uri_prefix'] ?? '' );
    if ( ! $origin ) { return array(); }

    $remote_terms = tc_sv_fetch_taxonomy( $origin, $taxonomy );
    if ( is_wp_error( $remote_terms ) ) { return array(); }

    $remote_by_id = array();
    foreach ( $remote_terms as $rt ) { $remote_by_id[ (int) $rt['id'] ] = $rt; }

    if ( 'full' === $scope ) {
        $scope_terms = $remote_terms;
    } else {
        $scope_ids   = tc_sv_subtree_ids( $roots, $remote_by_id );
        $scope_terms = array_filter( $remote_terms, function ( $rt ) use ( $scope_ids ) {
            return in_array( (int) $rt['id'], $scope_ids, true );
        } );
    }

    $delegations = tc_sv_build_delegation_map( $peer_site_slug );
    $conflicts   = array();

    foreach ( $scope_terms as $rt ) {
        $remote_id = (int) $rt['id'];
        if ( isset( $delegations[ $remote_id ] ) ) { continue; } // Already linked.

        $local = get_term_by( 'slug', $rt['slug'], $taxonomy );
        if ( $local && ! is_wp_error( $local ) ) {
            $existing_src = tc_get_identity_source( (int) $local->term_id );
            // Only flag if not already delegated to this peer at all.
            if ( ! $existing_src || $existing_src['peer_site_slug'] !== $peer_site_slug ) {
                $conflicts[] = array(
                    'local_term_id' => (int) $local->term_id,
                    'slug'          => $rt['slug'],
                    'name'          => $local->name,
                );
            }
        }
    }

    return $conflicts;
}

// ==============================================================================
// RELATIONSHIP DELEGATION HELPERS
// ==============================================================================

/**
 * Fetch and register storage-only custom relationship type definitions from a peer HT site.
 *
 * Built-in types and inclusion-based types are skipped — the former are universal,
 * the latter travel via htax_includes. Locally-created types (no source_site) are
 * never overwritten.
 *
 * @since 1.0.0
 * @param string $origin Peer site base URL.
 * @return array { synced: int, created: int, errors: string[] }
 */
function tc_sv_sync_relationship_types( string $origin ): array {
    $result = array( 'synced' => 0, 'created' => 0, 'errors' => array() );

    if ( ! function_exists( 'htax_set_custom_relationship_type' )
         || ! function_exists( 'htax_get_relationship_type' ) ) {
        $result['errors'][] = __( 'HyperTaxonomy not active — cannot register remote relationship types.', 'termconnect' );
        return $result;
    }

    $url = trailingslashit( $origin ) . 'wp-json/htax/v1/relationship-types';
    $response = wp_remote_get( $url, array(
        'timeout'    => 15,
        'user-agent' => 'TermConnect/' . TC_VERSION,
        'headers'    => array( 'X-TermConnect' => TC_VERSION ),
    ) );

    if ( is_wp_error( $response ) ) {
        $result['errors'][] = $response->get_error_message();
        return $result;
    }
    $status = wp_remote_retrieve_response_code( $response );
    if ( 200 !== $status ) {
        $result['errors'][] = 404 === $status
            ? __( 'Peer site does not have HyperTaxonomy active.', 'termconnect' )
            : __( 'Relationship types endpoint returned an unexpected status.', 'termconnect' );
        return $result;
    }

    $types = tc_external_json_decode( wp_remote_retrieve_body( $response ) );
    if ( ! is_array( $types ) ) {
        return $result;
    }

    foreach ( $types as $type ) {
        $slug = sanitize_key( $type['slug'] ?? '' );
        if ( ! $slug ) {
            continue;
        }
        // Built-in types are universal — skip.
        if ( 'https://hypertaxonomy.org' === ( $type['namespace'] ?? '' ) ) {
            continue;
        }
        // Inclusion-based types travel via htax_includes — skip.
        if ( 'includes' === ( $type['core_type'] ?? null ) ) {
            continue;
        }

        $existing = htax_get_relationship_type( $slug );
        $is_new   = ! $existing;

        // Never overwrite locally-owned types (no source_site set).
        if ( $existing && empty( $existing['source_site'] ) ) {
            continue;
        }

        $r = htax_set_custom_relationship_type( $slug, array(
            'label'             => sanitize_text_field( $type['label'] ?? $slug ),
            'inverse_label'     => sanitize_text_field( $type['inverse_label'] ?? '' ),
            'description'       => sanitize_textarea_field( $type['description'] ?? '' ),
            'core_type'         => null,
            'symmetric'         => (bool) ( $type['symmetric'] ?? false ),
            'show_in_ui'        => (bool) ( $type['show_in_ui'] ?? true ),
            'source_taxonomies' => array_map( 'sanitize_key', (array) ( $type['source_taxonomies'] ?? array() ) ),
            'target_taxonomies' => array_map( 'sanitize_key', (array) ( $type['target_taxonomies'] ?? array() ) ),
            'source_site'       => esc_url_raw( $origin ),
        ), $is_new );

        if ( is_wp_error( $r ) && 'slug_exists' !== $r->get_error_code() ) {
            $result['errors'][] = $slug . ': ' . $r->get_error_message();
        } elseif ( $is_new ) {
            $result['created']++;
        } else {
            $result['synced']++;
        }
    }

    return $result;
}

/**
 * Write local relationship rows from htax_includes and htax_relationships fields
 * bundled in the peer collection response.
 *
 * Inclusion data (htax_includes) is written as generic `includes` rows.
 * Storage-only custom type data (htax_relationships) is written using the specific
 * type slug. Cross-taxonomy targets are only processed when $cross_taxonomy is true,
 * and only when the local target term is already delegated to the same peer.
 *
 * @since 1.0.0
 * @param array[] $remote_terms  Raw flat fetch results (with htax_includes + htax_relationships).
 * @param array   $delegations   remote_term_id => local_term_id for this sync run.
 * @param string  $taxonomy      Local taxonomy slug.
 * @param bool    $cross_taxonomy Whether to process cross-taxonomy relationships.
 * @return array { rows_written: int, errors: string[] }
 */
function tc_sv_sync_relationship_rows( array $remote_terms, array $delegations, string $taxonomy, bool $cross_taxonomy ): array {
    $result = array( 'rows_written' => 0, 'errors' => array() );

    if ( ! function_exists( 'htax_add_term_relationship' )
         || ! function_exists( 'htax_get_relationship_type' ) ) {
        return $result;
    }

    foreach ( $remote_terms as $rt ) {
        $remote_id = (int) ( $rt['id'] ?? 0 );
        $local_id  = $delegations[ $remote_id ] ?? 0;
        if ( ! $local_id ) {
            continue;
        }

        // htax_includes: write as generic `includes` rows (same-taxonomy only).
        foreach ( (array) ( $rt['htax_includes'] ?? array() ) as $stub ) {
            $target_slug = $stub['slug'] ?? '';
            $target_tax  = $stub['taxonomy'] ?? $taxonomy;
            if ( ! $target_slug || $target_tax !== $taxonomy ) {
                continue;
            }
            $target = get_term_by( 'slug', $target_slug, $target_tax );
            if ( ! $target || is_wp_error( $target ) ) {
                continue;
            }
            $r = htax_add_term_relationship( $local_id, $target->term_id, 'includes' );
            if ( ! is_wp_error( $r ) ) {
                $result['rows_written']++;
            }
        }

        // htax_relationships: write storage-only custom type rows.
        foreach ( (array) ( $rt['htax_relationships'] ?? array() ) as $rel ) {
            $type_slug   = sanitize_key( $rel['relationship_type'] ?? '' );
            $target_slug = $rel['target_slug'] ?? '';
            $target_tax  = sanitize_key( $rel['target_taxonomy'] ?? $taxonomy );
            if ( ! $type_slug || ! $target_slug ) {
                continue;
            }
            if ( ! htax_get_relationship_type( $type_slug ) ) {
                continue; // type not registered locally yet
            }

            $is_cross = ( $target_tax !== $taxonomy );
            if ( $is_cross && ! $cross_taxonomy ) {
                continue;
            }

            $target = get_term_by( 'slug', $target_slug, $target_tax );
            if ( ! $target || is_wp_error( $target ) ) {
                continue;
            }

            // Cross-taxonomy: require the local target to be delegated to the same peer.
            if ( $is_cross ) {
                $target_src = tc_get_identity_source( $target->term_id );
                if ( ! $target_src || ( $target_src['remote_slug'] ?? '' ) !== $target_slug ) {
                    continue;
                }
            }

            $r = htax_add_term_relationship( $local_id, $target->term_id, $type_slug );
            if ( ! is_wp_error( $r ) ) {
                $result['rows_written']++;
            }
        }
    }

    return $result;
}

/**
 * Detect local relationship rows (using origin-owned custom types) that no longer
 * appear in the remote htax_relationships data for this sync run, and record them
 * in _tc_rel_sync_issues term meta.
 *
 * Meta is keyed by "{peer_site_slug}:{rel_type}:{target_term_id}". On each call all
 * entries for $peer_site_slug are replaced — resolved rows vanish automatically.
 * detected_at is preserved from prior entries; last_checked is updated each run.
 *
 * @since 1.0.0
 * @param array[] $remote_terms  Flat remote term list (with htax_relationships field).
 * @param array   $delegations   remote_term_id => local_term_id for this sync run.
 * @param string  $peer_site_slug       Peer link type slug.
 * @param string  $origin        Peer site base URL.
 * @param string  $taxonomy      Local taxonomy slug (default target taxonomy).
 * @return int Number of terms that have at least one stale row after this run.
 */
function tc_sv_flag_stale_relationship_rows( array $remote_terms, array $delegations, string $peer_site_slug, string $origin, string $taxonomy ): int {
    if ( ! function_exists( 'htax_get_registered_relationship_types' ) || empty( $delegations ) ) {
        return 0;
    }

    // 1. Collect type slugs owned by the origin peer.
    $origin_type_slugs = array();
    foreach ( htax_get_registered_relationship_types() as $slug => $def ) {
        if ( isset( $def['source_site'] ) && rtrim( $def['source_site'], '/' ) === rtrim( $origin, '/' ) ) {
            $origin_type_slugs[] = $slug;
        }
    }
    if ( empty( $origin_type_slugs ) ) {
        return 0;
    }

    global $wpdb;
    $local_ids_list    = array_unique( array_values( $delegations ) );
    $id_placeholders   = implode( ',', array_fill( 0, count( $local_ids_list ), '%d' ) );
    $type_placeholders = implode( ',', array_fill( 0, count( $origin_type_slugs ), '%s' ) );
    $origin_type_index = array_flip( $origin_type_slugs );

    // 2. Build expected set: rows that *should* exist based on remote htax_relationships data.
    $expected = array(); // [ local_id ][ type_slug ][ target_local_id ] = true
    foreach ( $remote_terms as $rt ) {
        $remote_id = (int) ( $rt['id'] ?? 0 );
        $local_id  = $delegations[ $remote_id ] ?? 0;
        if ( ! $local_id ) {
            continue;
        }
        foreach ( (array) ( $rt['htax_relationships'] ?? array() ) as $rel ) {
            $type_slug   = sanitize_key( $rel['relationship_type'] ?? '' );
            $target_slug = $rel['target_slug'] ?? '';
            $target_tax  = sanitize_key( $rel['target_taxonomy'] ?? $taxonomy );
            if ( ! $type_slug || ! $target_slug || ! isset( $origin_type_index[ $type_slug ] ) ) {
                continue;
            }
            $target = get_term_by( 'slug', $target_slug, $target_tax );
            if ( ! $target || is_wp_error( $target ) ) {
                continue;
            }
            $expected[ $local_id ][ $type_slug ][ (int) $target->term_id ] = true;
        }
    }

    // 3. Query local rows for delegated terms using origin-owned types.
    $ht_table = $wpdb->prefix . 'htax_term_relationships';
    $rows     = $wpdb->get_results( $wpdb->prepare(
        "SELECT source_term_id, relationship_type, target_term_id
         FROM {$ht_table}
         WHERE source_term_id IN ({$id_placeholders})
           AND relationship_type IN ({$type_placeholders})",
        ...array_merge( $local_ids_list, $origin_type_slugs )
    ) );

    // 4. Find stale rows: exist locally but absent from expected set.
    $stale_by_term = array(); // local_term_id => [ ['type' => slug, 'target' => id], ... ]
    foreach ( $rows as $row ) {
        $lid  = (int) $row->source_term_id;
        $type = $row->relationship_type;
        $tid  = (int) $row->target_term_id;
        if ( ! isset( $expected[ $lid ][ $type ][ $tid ] ) ) {
            $stale_by_term[ $lid ][] = array( 'type' => $type, 'target' => $tid );
        }
    }

    // 5. Load existing _tc_rel_sync_issues for all delegated terms in one query.
    $existing_by_term = array();
    $meta_rows        = $wpdb->get_results( $wpdb->prepare(
        "SELECT term_id, meta_value FROM {$wpdb->termmeta}
         WHERE meta_key = %s AND term_id IN ({$id_placeholders})",
        ...array_merge( array( TC_META_REL_SYNC_ISSUES ), $local_ids_list )
    ) );
    foreach ( $meta_rows as $mr ) {
        $val = maybe_unserialize( $mr->meta_value );
        if ( is_array( $val ) ) {
            $existing_by_term[ (int) $mr->term_id ] = $val;
        }
    }

    // Determine which terms need updating: those with new stale rows or prior peer_site_slug entries.
    $peer_prefix               = $peer_site_slug . ':';
    $terms_with_prior_issues = array();
    foreach ( $existing_by_term as $lid => $issues ) {
        foreach ( array_keys( $issues ) as $k ) {
            if ( 0 === strpos( $k, $peer_prefix ) ) {
                $terms_with_prior_issues[ $lid ] = true;
                break;
            }
        }
    }
    $terms_to_update = array_unique( array_merge(
        array_keys( $stale_by_term ),
        array_keys( $terms_with_prior_issues )
    ) );

    // 6. Write updated meta for each affected term.
    $now           = time();
    $affected_count = 0;

    foreach ( $terms_to_update as $lid ) {
        $existing = $existing_by_term[ $lid ] ?? array();

        // Keep entries from other sources; replace all entries for this peer_site_slug.
        $new_issues = array();
        foreach ( $existing as $k => $v ) {
            if ( 0 !== strpos( $k, $peer_prefix ) ) {
                $new_issues[ $k ] = $v;
            }
        }

        foreach ( $stale_by_term[ $lid ] ?? array() as $stale ) {
            $key            = $peer_site_slug . ':' . $stale['type'] . ':' . $stale['target'];
            $new_issues[ $key ] = array(
                'peer_site_slug'           => $peer_site_slug,
                'relationship_type' => $stale['type'],
                'target_term_id'    => $stale['target'],
                'detected_at'       => $existing[ $key ]['detected_at'] ?? $now,
                'last_checked'      => $now,
            );
        }

        if ( empty( $new_issues ) ) {
            delete_term_meta( $lid, TC_META_REL_SYNC_ISSUES );
        } else {
            update_term_meta( $lid, TC_META_REL_SYNC_ISSUES, $new_issues );
            $affected_count++;
        }
    }

    if ( ! empty( $terms_to_update ) ) {
        tc_flush_stale_rel_term_ids();
    }

    return $affected_count;
}

// ==============================================================================
// CRON — sync all sources on their configured schedule
// ==============================================================================

add_action( 'tc_sync_shared_vocabularies', 'tc_cron_sync_shared_vocabularies' );

function tc_cron_sync_shared_vocabularies(): void {
    $sources = tc_sv_sort_sources( get_option( 'tc_shared_vocabularies', array() ) );
    if ( empty( $sources ) ) { return; }

    foreach ( $sources as $source ) {
        if ( ( $source['status'] ?? 'active' ) !== 'active' ) { continue; }

        $interval    = $source['sync_interval'] ?? 'daily';
        $last_sync   = (int) ( $source['last_sync'] ?? 0 );
        $interval_sec = 'twicedaily' === $interval ? 43200 : ( 'weekly' === $interval ? 604800 : 86400 );

        if ( time() - $last_sync < $interval_sec ) { continue; }

        tc_sv_sync_source( $source );
    }
}
