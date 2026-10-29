<?php
/**
 * TermConnect Export / Import handlers.
 *
 * Export: downloads a JSON file containing:
 *   - link type definitions (tc_link_types option)
 *   - per-term external identifier values (_tc_link_{slug} term meta)
 *   - delegated identity sources (_tc_identity_source term meta)
 *   - original descriptions for delegated terms (_tc_original_description)
 *
 * Import: restores all of the above. Link type definitions are merged (existing
 * custom types are preserved unless the file redefines them). Per-term values
 * are upserted — existing values are overwritten.
 *
 * Delegated links data (_tc_delegated_links_data) and the URI index (_tc_uris)
 * are not included — they are caches rebuilt automatically after import.
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_post_tc_export_links', 'tc_handle_export_links' );
add_action( 'admin_post_tc_import_links', 'tc_handle_import_links' );

function tc_handle_export_links(): void {
    check_admin_referer( 'tc_export_links' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'termconnect' ) );
    }

    global $wpdb;

    // Collect all _tc_link_* term meta rows.
    $rows = $wpdb->get_results(
        "SELECT term_id, meta_key, meta_value
         FROM {$wpdb->termmeta}
         WHERE meta_key LIKE '\_tc\_link\_%'
         ORDER BY term_id, meta_key"
    );

    $term_links = array();
    foreach ( $rows as $row ) {
        $slug = substr( $row->meta_key, strlen( '_tc_link_' ) );
        $term = get_term( (int) $row->term_id );
        if ( ! $term || is_wp_error( $term ) ) {
            continue;
        }
        $meta = maybe_unserialize( $row->meta_value );
        if ( is_array( $meta ) ) {
            $id         = (string) ( $meta['id'] ?? '' );
            $label      = (string) ( $meta['label'] ?? '' );
            $fetched_at = (int) ( $meta['fetched_at'] ?? 0 );
        } else {
            $id         = (string) $meta;
            $label      = '';
            $fetched_at = 0;
        }
        if ( '' === $id ) {
            continue;
        }
        $term_links[] = array(
            'taxonomy'   => $term->taxonomy,
            'term_slug'  => $term->slug,
            'link_slug'  => $slug,
            'identifier' => $id,
            'label'      => $label,
            'fetched_at' => $fetched_at,
        );
    }

    // Collect delegated identity sources.
    $identity_rows = $wpdb->get_results(
        "SELECT i.term_id, i.meta_value AS source, o.meta_value AS original_description
         FROM {$wpdb->termmeta} i
         LEFT JOIN {$wpdb->termmeta} o
           ON o.term_id = i.term_id AND o.meta_key = '_tc_original_description'
         WHERE i.meta_key = '_tc_identity_source'
         ORDER BY i.term_id"
    );

    $delegations = array();
    foreach ( $identity_rows as $row ) {
        $source = maybe_unserialize( $row->source );
        if ( ! is_array( $source ) || empty( $source['site_url'] ) ) {
            continue;
        }
        $term = get_term( (int) $row->term_id );
        if ( ! $term || is_wp_error( $term ) ) {
            continue;
        }
        $delegations[] = array(
            'taxonomy'             => $term->taxonomy,
            'term_slug'            => $term->slug,
            'source'               => $source,
            'original_description' => $row->original_description ?? '',
        );
    }

    $export = array(
        'version'              => '1.0',
        'exported'             => gmdate( 'Y-m-d\TH:i:s\Z' ),
        'link_types'           => get_option( 'tc_link_types', array() ),
        'term_links'           => $term_links,
        'delegations'          => $delegations,
        'shared_vocabularies'  => get_option( 'tc_shared_vocabularies', array() ),
    );

    $filename = 'termconnect-' . gmdate( 'Y-m-d' ) . '.json';
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Cache-Control: no-cache, must-revalidate' );
    echo wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
    exit;
}

/**
 * Sanitize a single imported link type definition.
 *
 * Mirrors the field handling in tc_handle_peer_site_add() so imported
 * definitions are validated the same way as ones created through the UI,
 * rather than trusting raw input from the uploaded file.
 *
 * @since 1.0.0
 * @param mixed $type Raw link type definition from the import file.
 * @return array|null Sanitized definition, or null when invalid.
 */
function tc_import_sanitize_link_type( $type ): ?array {
    if ( ! is_array( $type ) ) {
        return null;
    }

    $slug = sanitize_title( $type['slug'] ?? '' );
    if ( '' === $slug ) {
        return null;
    }

    $platform = sanitize_key( $type['platform'] ?? 'other' );
    if ( ! in_array( $platform, array( 'wordpress', 'feed', 'other', 'mediawiki' ), true ) ) {
        $platform = 'other';
    }

    $taxonomies = array();
    if ( ! empty( $type['taxonomies'] ) && is_array( $type['taxonomies'] ) ) {
        $taxonomies = array_map( 'sanitize_key', $type['taxonomies'] );
    }

    return array(
        'slug'             => $slug,
        'label'            => sanitize_text_field( $type['label'] ?? '' ),
        'uri_prefix'       => esc_url_raw( $type['uri_prefix'] ?? '' ),
        'description'      => sanitize_text_field( $type['description'] ?? '' ),
        'platform'         => $platform,
        'remote_taxonomy'  => 'wordpress' === $platform ? sanitize_key( $type['remote_taxonomy'] ?? '' ) : '',
        'poll_interval'    => 'feed' === $platform ? max( 300, min( 86400, (int) ( $type['poll_interval'] ?? 3600 ) ) ) : 0,
        'max_cached_posts' => 'feed' === $platform ? max( 50, min( 5000, (int) ( $type['max_cached_posts'] ?? 500 ) ) ) : 0,
        'enabled'          => ! empty( $type['enabled'] ),
        'show_on_archives' => ! empty( $type['show_on_archives'] ),
        'taxonomies'       => $taxonomies,
        'built_in'         => ! empty( $type['built_in'] ),
    );
}

function tc_handle_import_links(): void {
    check_admin_referer( 'tc_import_links', 'tc_import_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'termconnect' ) );
    }

    if ( empty( $_FILES['tc_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['tc_import_file']['tmp_name'] ) ) {
        wp_safe_redirect( add_query_arg( 'tc-import', 'no-file', admin_url( 'options-general.php?page=termconnect-settings' ) ) );
        exit;
    }

    $raw  = file_get_contents( $_FILES['tc_import_file']['tmp_name'] );
    if ( false === $raw ) {
        wp_safe_redirect( add_query_arg( 'tc-import', 'invalid', admin_url( 'options-general.php?page=termconnect-settings' ) ) );
        exit;
    }
    $data = tc_external_json_decode( $raw );

    if ( ! is_array( $data ) || empty( $data['version'] ) ) {
        wp_safe_redirect( add_query_arg( 'tc-import', 'invalid', admin_url( 'options-general.php?page=termconnect-settings' ) ) );
        exit;
    }

    $terms_updated = 0;
    $types_updated  = false;

    // Restore link type definitions — merge by slug so existing custom types
    // survive and re-imports overwrite (rather than duplicate) the same slug.
    if ( ! empty( $data['link_types'] ) && is_array( $data['link_types'] ) ) {
        // Build a slug-keyed map of existing types.
        $by_slug = array();
        foreach ( get_option( 'tc_link_types', array() ) as $type ) {
            if ( is_array( $type ) && ! empty( $type['slug'] ) ) {
                $by_slug[ $type['slug'] ] = $type;
            }
        }

        // Overlay imported types, sanitizing each field.
        foreach ( $data['link_types'] as $type ) {
            $sanitized = tc_import_sanitize_link_type( $type );
            if ( null !== $sanitized ) {
                $by_slug[ $sanitized['slug'] ] = $sanitized;
            }
        }

        update_option( 'tc_link_types', array_values( $by_slug ) );
        $types_updated = true;
    }

    // Restore per-term link values.
    if ( ! empty( $data['term_links'] ) && is_array( $data['term_links'] ) ) {
        foreach ( $data['term_links'] as $entry ) {
            $taxonomy   = sanitize_key( $entry['taxonomy']   ?? '' );
            $term_slug  = sanitize_text_field( $entry['term_slug']  ?? '' );
            $link_slug  = sanitize_key( $entry['link_slug']  ?? '' );
            $identifier = sanitize_text_field( $entry['identifier'] ?? '' );
            $label      = sanitize_text_field( $entry['label']      ?? '' );
            $fetched_at = (int) ( $entry['fetched_at'] ?? 0 );

            if ( '' === $taxonomy || '' === $term_slug || '' === $link_slug || '' === $identifier ) {
                continue;
            }

            $term = get_term_by( 'slug', $term_slug, $taxonomy );
            if ( ! $term || is_wp_error( $term ) ) {
                continue;
            }

            tc_set_term_link( $term->term_id, $link_slug, $identifier, $label, $fetched_at );
            $terms_updated++;
        }
    }

    // Restore delegated identity sources.
    if ( ! empty( $data['delegations'] ) && is_array( $data['delegations'] ) ) {
        foreach ( $data['delegations'] as $entry ) {
            $taxonomy  = sanitize_key( $entry['taxonomy']  ?? '' );
            $term_slug = sanitize_text_field( $entry['term_slug'] ?? '' );
            $source    = $entry['source'] ?? array();

            if ( '' === $taxonomy || '' === $term_slug || empty( $source['peer_site_slug'] ) ) {
                continue;
            }

            $term = get_term_by( 'slug', $term_slug, $taxonomy );
            if ( ! $term || is_wp_error( $term ) ) {
                continue;
            }

            // Reconstruct the _tc_identity_source meta array with all required fields.
            $clean_source = array(
                'peer_site_slug'   => sanitize_key( $source['peer_site_slug'] ),
                'site_url'         => esc_url_raw( $source['site_url'] ?? '' ),
                'remote_slug'      => sanitize_title( $source['remote_slug'] ?? '' ),
                'remote_term_id'   => absint( $source['remote_term_id'] ?? 0 ),
                'remote_link'      => esc_url_raw( $source['remote_link'] ?? '' ),
                'status'           => sanitize_key( $source['status'] ?? 'active' ),
                'last_fetched'     => absint( $source['last_fetched'] ?? 0 ),
                'sync_descendants' => (bool) ( $source['sync_descendants'] ?? false ),
                'sync_name'        => isset( $source['sync_name'] ) ? (bool) $source['sync_name'] : true,
                'sync_description' => isset( $source['sync_description'] ) ? (bool) $source['sync_description'] : true,
            );
            if ( ! empty( $source['sv_source_id'] ) ) {
                $clean_source['sv_source_id'] = sanitize_key( $source['sv_source_id'] );
            }

            update_term_meta( $term->term_id, TC_META_IDENTITY_SOURCE, $clean_source );

            if ( isset( $entry['original_description'] ) ) {
                update_term_meta( $term->term_id, TC_META_ORIGINAL_DESCRIPTION, wp_kses_post( $entry['original_description'] ) );
            }

            $terms_updated++;
        }
    }

    // Restore shared vocabulary source configs.
    if ( ! empty( $data['shared_vocabularies'] ) && is_array( $data['shared_vocabularies'] ) ) {
        $clean_sources = array();
        foreach ( $data['shared_vocabularies'] as $src ) {
            if ( empty( $src['peer_site_slug'] ) ) { continue; }
            $scope = in_array( $src['scope'] ?? '', array( 'full', 'remote_group', 'roots', 'terms', 'local_group' ), true ) ? $src['scope'] : 'full';
            $taxonomy_optional = in_array( $scope, array( 'local_group', 'remote_group' ), true );
            if ( ! $taxonomy_optional && empty( $src['taxonomy'] ) ) { continue; }
            $roots = array();
            if ( in_array( $scope, array( 'roots', 'terms' ), true ) && ! empty( $src['roots'] ) ) {
                foreach ( (array) $src['roots'] as $r ) {
                    if ( ! empty( $r['remote_term_id'] ) && ! empty( $r['remote_slug'] ) ) {
                        $roots[] = array(
                            'remote_term_id' => absint( $r['remote_term_id'] ),
                            'remote_slug'    => sanitize_title( $r['remote_slug'] ),
                            'remote_name'    => sanitize_text_field( $r['remote_name'] ?? '' ),
                        );
                    }
                }
            }
            $clean_src = array(
                'id'               => sanitize_key( $src['id'] ?? wp_generate_uuid4() ),
                'sv_name'          => sanitize_text_field( $src['sv_name'] ?? '' ),
                'sv_description'   => sanitize_textarea_field( $src['sv_description'] ?? '' ),
                'peer_site_slug'   => sanitize_key( $src['peer_site_slug'] ),
                'taxonomy'         => sanitize_key( $src['taxonomy'] ?? '' ),
                'scope'            => $scope,
                'group_id'         => absint( $src['group_id'] ?? 0 ),
                'group_name'       => sanitize_text_field( $src['group_name'] ?? '' ),
                'roots'            => $roots,
                'misplacement'     => in_array( $src['misplacement'] ?? '', array( 'flag', 'link-only', 're-parent' ), true ) ? $src['misplacement'] : 'flag',
                'orphan_action'    => in_array( $src['orphan_action'] ?? '', array( 'flag', 'delete_clean', 'delete' ), true ) ? $src['orphan_action'] : 'flag',
                'sync_interval'    => in_array( $src['sync_interval'] ?? '', array( 'twicedaily', 'daily', 'weekly' ), true ) ? $src['sync_interval'] : 'daily',
                // Sync flags — same default semantics as the SV save handler
                // (sync_name/sync_description default true; the rest default false).
                'sync_descendants'             => ! empty( $src['sync_descendants'] ),
                'sync_name'                    => ! isset( $src['sync_name'] ) || ! empty( $src['sync_name'] ),
                'sync_description'             => ! isset( $src['sync_description'] ) || ! empty( $src['sync_description'] ),
                'relationship_delegation'      => ! empty( $src['relationship_delegation'] ),
                'cross_taxonomy_relationships' => ! empty( $src['cross_taxonomy_relationships'] ),
                'last_sync'        => 0,
                'status'           => 'active',
            );
            if ( 'remote_group' === $scope ) {
                $clean_src['remote_group_slug'] = sanitize_title( $src['remote_group_slug'] ?? '' );
                $clean_src['remote_group_name'] = sanitize_text_field( $src['remote_group_name'] ?? '' );
                $clean_src['remote_group_id']   = absint( $src['remote_group_id'] ?? 0 );
                $clean_src['local_group_id']    = absint( $src['local_group_id'] ?? 0 );
            }
            $clean_sources[] = $clean_src;
        }
        if ( ! empty( $clean_sources ) ) {
            // Merge with existing sources — match on id to avoid duplicates.
            $existing_svs  = get_option( 'tc_shared_vocabularies', array() );
            $existing_by_id = array();
            foreach ( $existing_svs as $sv ) {
                if ( ! empty( $sv['id'] ) ) {
                    $existing_by_id[ $sv['id'] ] = $sv;
                }
            }
            foreach ( $clean_sources as $src ) {
                $existing_by_id[ $src['id'] ] = $src;
            }
            // Sort by scope priority before saving, matching every other writer —
            // scope order determines first-wins delegation priority.
            $merged = array_values( $existing_by_id );
            update_option( 'tc_shared_vocabularies', function_exists( 'tc_sv_sort_sources' ) ? tc_sv_sort_sources( $merged ) : $merged );
        }
    }

    if ( ! empty( $data['delegations'] ) ) {
        tc_flush_delegated_term_ids();
    }

    wp_safe_redirect( add_query_arg(
        array( 'tc-import' => 'ok', 'tc-import-count' => $terms_updated ),
        admin_url( 'options-general.php?page=termconnect-settings' )
    ) );
    exit;
}
