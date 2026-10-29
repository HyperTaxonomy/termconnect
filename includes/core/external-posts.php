<?php
/**
 * External Posts Platform
 *
 * Public API for fetching posts from peer WordPress sites. Provides origin
 * validation, HT-enabled detection, term resolution, post fetching (standard
 * and scored), client-side scoring, and FOAF peer discovery via two independent
 * mechanisms: explicit term data fetches and passive extraction from post
 * responses.
 *
 * All functions are public — TermConnect and other plugins may call them.
 *
 * @package TermConnect
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==============================================================================
// HELPERS
// ==============================================================================

/**
 * JSON-decode a REST API response body, stripping any UTF-8 BOM first.
 *
 * Some WordPress installations output a UTF-8 BOM (EF BB BF) at the start
 * of PHP output files. json_decode() rejects input that starts with a BOM,
 * returning null. This wrapper strips the BOM before decoding.
 *
 * @since 1.0.0
 * @param string $body Raw HTTP response body.
 * @return mixed Decoded value or null on failure.
 */
function tc_external_json_decode( string $body ) {
    if ( 0 === strpos( $body, "\xef\xbb\xbf" ) ) {
        $body = substr( $body, 3 );
    }
    return json_decode( $body, true );
}

// ==============================================================================
// VALIDATION AND BLACKLIST
// ==============================================================================

/**
 * Validate that an origin URL is safe to request.
 *
 * Delegates to wp_http_validate_url(), which blocks private IPs and loopback
 * addresses. The http_request_host_is_external filter (registered at the bottom
 * of this file) lifts that restriction for hosts declared in registered WordPress
 * peer link types, allowing development setups where peer sites share a loopback
 * address (e.g. Local by Flywheel .local domains).
 *
 * @since 1.0.0
 * @param string $origin scheme://host to validate.
 * @return bool True when the origin is a publicly reachable HTTP(S) host.
 */
function tc_external_validate_origin( string $origin ): bool {
    if ( '' === $origin ) {
        return false;
    }
    $parsed = wp_parse_url( $origin );
    if ( empty( $parsed['scheme'] ) || ! in_array( $parsed['scheme'], array( 'http', 'https' ), true ) ) {
        return false;
    }
    return false !== wp_http_validate_url( $origin . '/' );
}

/**
 * Return true when the origin matches the current site's own URL.
 *
 * Compares scheme-stripped, slash-trimmed, lowercased forms of $origin against
 * both home_url() and site_url(). Domain aliases can bypass this check; there
 * is no additional danger from aliases beyond the same inefficiency and duplicate-
 * data problems as the direct self-referential case, so no further guard is needed.
 *
 * @since 1.0.0
 * @param string $origin scheme://host to check.
 * @return bool
 */
function tc_is_self_origin( string $origin ): bool {
    $normalize = static function ( string $url ): string {
        return rtrim( strtolower( preg_replace( '#^https?://#i', '', $url ) ), '/' );
    };
    $n = $normalize( $origin );
    return $n === $normalize( home_url() ) || $n === $normalize( site_url() );
}

/**
 * Return true when the origin appears in the configured site blacklist.
 *
 * @since 1.0.0
 * @param string $origin scheme://host to check.
 * @return bool
 */
function tc_external_is_blacklisted( string $origin ): bool {
    $settings  = get_option( 'tc_settings', array() );
    $blacklist = $settings['external_site_blacklist'] ?? array();
    foreach ( (array) $blacklist as $entry ) {
        if ( rtrim( (string) $entry, '/' ) === rtrim( $origin, '/' ) ) {
            return true;
        }
    }
    return false;
}

// ==============================================================================
// HT-ENABLED DETECTION
// ==============================================================================

/**
 * Return true when the origin runs HyperTaxonomy.
 *
 * Sends a HEAD request to {origin}/wp-json/ and checks for the
 * X-HyperTaxonomy response header. Result cached per origin.
 *
 * @since 1.0.0
 * @param string $origin scheme://host.
 * @return bool
 */
function tc_external_is_ht_enabled( string $origin ): bool {
    $cache_key = 'tc_ext_ht_' . md5( $origin );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return (bool) $cached;
    }

    $settings = get_option( 'tc_settings', array() );
    $ttl      = (int) ( $settings['ht_status_cache_ttl'] ?? 86400 );

    $response = wp_remote_head(
        $origin . '/wp-json/',
        array(
            'timeout'    => 3,
            'user-agent' => 'TermConnect/' . TC_VERSION,
            'sslverify'  => true,
        )
    );

    if ( is_wp_error( $response ) ) {
        set_transient( $cache_key, 0, 300 );
        return false;
    }

    $enabled = '' !== wp_remote_retrieve_header( $response, 'X-HyperTaxonomy' );
    set_transient( $cache_key, $enabled ? 1 : 0, $enabled ? $ttl : 300 );
    return $enabled;
}

/**
 * Return true when the origin runs TermConnect.
 *
 * Sends a HEAD request to {origin}/wp-json/ and checks for the
 * X-TermConnect response header. Result cached per origin.
 *
 * @since 1.0.0
 * @param string $origin scheme://host.
 * @return bool
 */
function tc_external_is_tc_enabled( string $origin ): bool {
    $cache_key = 'tc_ext_tc_' . md5( $origin );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return (bool) $cached;
    }

    $settings = get_option( 'tc_settings', array() );
    $ttl      = (int) ( $settings['ht_status_cache_ttl'] ?? 86400 );

    $response = wp_remote_head(
        $origin . '/wp-json/',
        array(
            'timeout'    => 3,
            'user-agent' => 'TermConnect/' . TC_VERSION,
            'sslverify'  => true,
        )
    );

    if ( is_wp_error( $response ) ) {
        set_transient( $cache_key, 0, 300 );
        return false;
    }

    $enabled = '' !== wp_remote_retrieve_header( $response, 'X-TermConnect' );
    set_transient( $cache_key, $enabled ? 1 : 0, $enabled ? $ttl : 300 );
    return $enabled;
}

// ==============================================================================
// TAXONOMY REST BASE
// ==============================================================================

/**
 * Map a WordPress taxonomy slug to its REST API base.
 *
 * WordPress built-ins use non-obvious bases (post_tag → tags). Custom
 * taxonomies default to their slug.
 *
 * @since 1.0.0
 * @param string $taxonomy Taxonomy slug.
 * @return string REST base used in /wp-json/wp/v2/{base}.
 */
function tc_external_get_taxonomy_rest_base( string $taxonomy ): string {
    $map = array(
        'post_tag' => 'tags',
        'category' => 'categories',
        'group'    => 'groups',
    );
    return $map[ $taxonomy ] ?? $taxonomy;
}

// ==============================================================================
// TERM RESOLUTION
// ==============================================================================

/**
 * Resolve a remote term slug to its numeric ID on the peer site.
 *
 * Result cached 24 hours. A failure or missing term is cached with a -1
 * sentinel for 5 minutes to avoid hammering an unavailable endpoint.
 *
 * @since 1.0.0
 * @param string $origin   Peer site origin (scheme://host).
 * @param string $taxonomy Taxonomy slug on the remote site.
 * @param string $slug     Term slug to resolve.
 * @return int|null Remote term ID, or null if not found.
 */
function tc_external_resolve_term_id( string $origin, string $taxonomy, string $slug ): ?int {
    $cache_key = 'tc_ext_tid_' . md5( "$origin|$taxonomy|$slug" );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return -1 === (int) $cached ? null : (int) $cached;
    }

    $is_ht = tc_external_is_ht_enabled( $origin );

    $rest_base = tc_external_get_taxonomy_rest_base( $taxonomy );
    $url       = $origin . '/wp-json/wp/v2/' . $rest_base . '?' . http_build_query( array(
        'slug'    => $slug,
        '_fields' => 'id',
    ) );

    $headers  = $is_ht ? array( 'X-HyperTaxonomy' => defined( 'HTAX_VERSION' ) ? HTAX_VERSION : '1.0.0' ) : array();
    $response = wp_remote_get( $url, array(
        'timeout'    => 5,
        'user-agent' => 'TermConnect/' . TC_VERSION,
        'headers'    => $headers,
        'sslverify'  => true,
    ) );

    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        set_transient( $cache_key, -1, 300 );
        return null;
    }

    $body = tc_external_json_decode( wp_remote_retrieve_body( $response ) );
    if ( ! is_array( $body ) || empty( $body[0]['id'] ) ) {
        set_transient( $cache_key, -1, 300 );
        return null;
    }

    $term_id = (int) $body[0]['id'];
    set_transient( $cache_key, $term_id, DAY_IN_SECONDS );

    return $term_id;
}

/**
 * Search for terms on a peer site by keyword.
 *
 * Used by TermConnect for autocomplete when a user is setting up a cross-site
 * term link suggestion.
 *
 * @since 1.0.0
 * @param string $origin   Peer site origin.
 * @param string $taxonomy Taxonomy slug.
 * @param string $search   Search keyword.
 * @param int    $per_page Maximum results.
 * @return array[] Each entry: { id, name, slug }.
 */
function tc_external_search_terms( string $origin, string $taxonomy, string $search, int $per_page = 5 ): array {
    if ( tc_is_self_origin( $origin ) ) {
        return array();
    }

    $cache_key = 'tc_ext_tsrch_' . md5( "$origin|$taxonomy|$search|$per_page" );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return (array) $cached;
    }

    // WordPress stores term names HTML-encoded in the DB (e.g. "H&amp;M").
    // WP REST API passes the search string directly into a LIKE clause, so
    // "h&m" never matches "H&amp;M".  Decode any entities the caller typed,
    // then re-encode to match DB storage before sending to the remote site.
    $search_decoded = html_entity_decode( $search, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $search_for_api = htmlspecialchars( $search_decoded, ENT_QUOTES, 'UTF-8' );

    $rest_base = tc_external_get_taxonomy_rest_base( $taxonomy );
    $url       = $origin . '/wp-json/wp/v2/' . $rest_base . '?' . http_build_query( array(
        'search'   => $search_for_api,
        'per_page' => $per_page,
        '_fields'  => 'id,name,slug',
    ) );

    $response = wp_remote_get( $url, array(
        'timeout'    => 5,
        'user-agent' => 'TermConnect/' . TC_VERSION,
        'sslverify'  => true,
    ) );

    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        set_transient( $cache_key, array(), 300 );
        return array();
    }

    $body = tc_external_json_decode( wp_remote_retrieve_body( $response ) );
    if ( ! is_array( $body ) ) {
        set_transient( $cache_key, array(), 300 );
        return array();
    }

    $terms = array();
    foreach ( $body as $item ) {
        if ( isset( $item['id'], $item['name'], $item['slug'] ) ) {
            $terms[] = array(
                'id'   => (int) $item['id'],
                'name' => (string) $item['name'],
                'slug' => (string) $item['slug'],
            );
        }
    }

    set_transient( $cache_key, $terms, 5 * MINUTE_IN_SECONDS );
    return $terms;
}

/**
 * Search a peer WordPress site's authors via its public /wp/v2/users endpoint.
 *
 * WordPress core exposes only users with published posts (authors) to unauthenticated
 * REST requests; non-authors require authentication, and a peer may disable the
 * endpoint entirely — so results are best-effort. Runs server-side (CORS-safe) and is
 * cached. Used by the WordPress-peer author autocomplete on people taxonomies (see
 * tc_taxonomy_peer_authors()).
 *
 * @since 1.0.0
 * @param string $origin   Peer site origin (scheme://host).
 * @param string $search   Search keyword.
 * @param int    $per_page Maximum results.
 * @return array[] Each entry: { id, name, slug, url }.
 */
function tc_external_search_users( string $origin, string $search, int $per_page = 8 ): array {
    if ( '' === $search || tc_is_self_origin( $origin ) ) {
        return array();
    }

    $cache_key = 'tc_ext_usrch_' . md5( "$origin|$search|$per_page" );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return (array) $cached;
    }

    $url = $origin . '/wp-json/wp/v2/users?' . http_build_query( array(
        'search'   => $search,
        'per_page' => $per_page,
        '_fields'  => 'id,name,slug,link',
    ) );

    $response = wp_remote_get( $url, array(
        'timeout'    => 5,
        'user-agent' => 'TermConnect/' . TC_VERSION,
        'sslverify'  => true,
    ) );

    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        set_transient( $cache_key, array(), 300 );
        return array();
    }

    $body = tc_external_json_decode( wp_remote_retrieve_body( $response ) );
    if ( ! is_array( $body ) ) {
        set_transient( $cache_key, array(), 300 );
        return array();
    }

    $users = array();
    foreach ( $body as $item ) {
        if ( isset( $item['id'], $item['name'], $item['slug'] ) ) {
            $users[] = array(
                'id'   => (int) $item['id'],
                'name' => (string) $item['name'],
                'slug' => (string) $item['slug'],
                'url'  => isset( $item['link'] ) ? esc_url_raw( (string) $item['link'] ) : '',
            );
        }
    }

    set_transient( $cache_key, $users, 5 * MINUTE_IN_SECONDS );
    return $users;
}

/**
 * Fetch htax_includes and tc_links for a specific term on a peer TC site.
 *
 * Used by FOAF mechanism A: targeted pull of peer connections for a known term.
 *
 * @since 1.0.0
 * @param string $origin   Peer site origin.
 * @param string $taxonomy Taxonomy slug.
 * @param int    $term_id  Remote term ID.
 * @return array { htax_includes: array, tc_links: array<int, array{url:string, platform:string, label:string, fetched_at:int}> }
 */
function tc_external_get_term_data( string $origin, string $taxonomy, int $term_id ): array {
    $cache_key = 'tc_ext_tdata_' . md5( "$origin|$taxonomy|$term_id" );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        return (array) $cached;
    }

    $settings = get_option( 'tc_settings', array() );
    $ttl      = (int) ( $settings['ht_status_cache_ttl'] ?? 86400 );

    $rest_base = tc_external_get_taxonomy_rest_base( $taxonomy );
    $url       = $origin . '/wp-json/wp/v2/' . $rest_base . '/' . $term_id . '?' . http_build_query( array(
        '_fields' => 'htax_includes,tc_links',
    ) );

    $response = wp_remote_get( $url, array(
        'timeout'    => 5,
        'user-agent' => 'TermConnect/' . TC_VERSION,
        'headers'    => array( 'X-TermConnect' => TC_VERSION ),
        'sslverify'  => true,
    ) );

    $empty = array( 'htax_includes' => array(), 'tc_links' => array() );

    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        set_transient( $cache_key, $empty, 300 );
        return $empty;
    }

    $body = tc_external_json_decode( wp_remote_retrieve_body( $response ) );
    if ( ! is_array( $body ) ) {
        set_transient( $cache_key, $empty, 300 );
        return $empty;
    }

    $data = array(
        'htax_includes' => is_array( $body['htax_includes'] ?? null ) ? $body['htax_includes'] : array(),
        'tc_links'      => is_array( $body['tc_links'] ?? null )      ? $body['tc_links']      : array(),
    );

    set_transient( $cache_key, $data, $ttl );
    return $data;
}

/**
 * Batched counterpart to tc_external_get_term_data() for FOAF discovery: fetches
 * htax_includes/tc_links for many terms in the same taxonomy on one peer with one
 * HTTP request per up-to-100 term IDs, instead of one request per term.
 *
 * Uses the REST collection endpoint's `include` param (WP core since 4.7) rather
 * than the single-term endpoint. Shares the exact same per-term transient cache
 * keys as tc_external_get_term_data(), so: (a) any term already cached from a
 * prior single or batched fetch is served from cache and never requested again,
 * and (b) a term fetched here is cached under the same key a later single-term
 * tc_external_get_term_data() call for it would also read.
 *
 * @since 1.0.0
 * @param string $origin   Peer site origin.
 * @param string $taxonomy Taxonomy slug (same for every ID in $term_ids).
 * @param int[]  $term_ids Remote term IDs to fetch.
 * @return array<int, array{htax_includes: array, tc_links: array}> term_id => data,
 *         one entry for every requested ID (empty defaults for any not resolved).
 */
function tc_external_get_term_data_batch( string $origin, string $taxonomy, array $term_ids ): array {
    $empty  = array( 'htax_includes' => array(), 'tc_links' => array() );
    $result = array();

    $missing = array();
    foreach ( array_unique( array_map( 'intval', $term_ids ) ) as $term_id ) {
        $cache_key = 'tc_ext_tdata_' . md5( "$origin|$taxonomy|$term_id" );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            $result[ $term_id ] = (array) $cached;
        } else {
            $missing[] = $term_id;
        }
    }

    if ( empty( $missing ) ) {
        return $result;
    }

    $settings = get_option( 'tc_settings', array() );
    $ttl      = (int) ( $settings['ht_status_cache_ttl'] ?? 86400 );
    $rest_base = tc_external_get_taxonomy_rest_base( $taxonomy );

    // REST collections cap per_page at 100 by default; chunk accordingly.
    foreach ( array_chunk( $missing, 100 ) as $chunk ) {
        $url = $origin . '/wp-json/wp/v2/' . $rest_base . '?' . http_build_query( array(
            'include'  => $chunk,
            '_fields'  => 'id,htax_includes,tc_links',
            'per_page' => count( $chunk ),
        ) );

        $response = wp_remote_get( $url, array(
            'timeout'    => 5,
            'user-agent' => 'TermConnect/' . TC_VERSION,
            'headers'    => array( 'X-TermConnect' => TC_VERSION ),
            'sslverify'  => true,
        ) );

        $by_id = array();
        if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
            $body = tc_external_json_decode( wp_remote_retrieve_body( $response ) );
            if ( is_array( $body ) ) {
                foreach ( $body as $item ) {
                    if ( ! is_array( $item ) || empty( $item['id'] ) ) {
                        continue;
                    }
                    $by_id[ (int) $item['id'] ] = array(
                        'htax_includes' => is_array( $item['htax_includes'] ?? null ) ? $item['htax_includes'] : array(),
                        'tc_links'      => is_array( $item['tc_links'] ?? null )      ? $item['tc_links']      : array(),
                    );
                }
            }
        }
        // $by_id stays empty on any request-level failure — every ID in this
        // chunk falls through to the negative-cache branch below, same as a
        // failed single-term tc_external_get_term_data() call.

        foreach ( $chunk as $term_id ) {
            $cache_key = 'tc_ext_tdata_' . md5( "$origin|$taxonomy|$term_id" );
            $data      = $by_id[ $term_id ] ?? $empty;
            set_transient( $cache_key, $data, isset( $by_id[ $term_id ] ) ? $ttl : 300 );
            $result[ $term_id ] = $data;
        }
    }

    return $result;
}

// ==============================================================================
// POST FETCHING — INTERNAL HELPERS
// ==============================================================================

/**
 * Produce a deterministic string from a term ID map for use in cache keys.
 *
 * Sorts primary IDs and their include ID lists so the key is the same regardless
 * of the order the caller built the map.
 *
 * @since 1.0.0
 * @param array $term_id_map [ primary_id => [include_id, ...], ... ]
 * @return string
 */
function tc_external_serialize_term_id_map( array $term_id_map ): string {
    $sorted = array();
    foreach ( $term_id_map as $primary_id => $include_ids ) {
        $inc = array_map( 'intval', (array) $include_ids );
        sort( $inc );
        $sorted[ (int) $primary_id ] = $inc;
    }
    ksort( $sorted );
    return serialize( $sorted );
}

/**
 * Coerce a GMT datetime string to ISO-8601 'Y-m-d\TH:i:s' (the WordPress REST format).
 *
 * Every post-record producer (REST peers, RSS/Atom feeds, local posts) routes its
 * date fields through this so that values from different sources compare lexically
 * as chronological in tc_*::sort_posts(). WP stores dates as 'Y-m-d H:i:s' (space
 * separator); the REST API emits 'Y-m-d\TH:i:s' ('T'). Mixing the two breaks
 * strcmp ordering because 'T' (0x54) sorts after ' ' (0x20).
 *
 * Accepts MySQL or ISO format; returns '' for empty/zero/unparseable input.
 *
 * @since 1.0.0
 * @param mixed $value Raw GMT datetime string.
 * @return string ISO-8601 GMT datetime, or '' when unknown.
 */
function tc_normalize_gmt_date( $value ): string {
    $value = trim( (string) $value );
    if ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) {
        return '';
    }
    // MySQL 'Y-m-d H:i:s' (space, no 'T') → convert to the REST ISO format.
    if ( false !== strpos( $value, ' ' ) && false === strpos( $value, 'T' ) ) {
        $iso = mysql_to_rfc3339( $value );
        return $iso ? $iso : '';
    }
    // Already ISO (or some other already-T-separated form) → leave as-is.
    return $value;
}

/**
 * Build the canonical normalized post record consumed by the peer-post sorter and renderer.
 *
 * This is the single source of truth for the record shape. Every producer — REST peers
 * (tc_external_normalize_post) and feeds (tc_feed_normalize_item) — MUST build its record
 * through this function so all sources agree on the exact key set, value types, and date
 * format. HyperTaxonomy's [ht_related_posts] external mode consumes these records and
 * normalizes its own local posts to the same shape independently.
 * Pass any subset of keys; missing keys get safe defaults. Extra producer-specific keys
 * (e.g. a feed's 'categories') are preserved.
 *
 * @since 1.0.0
 * @param array $data Raw field values.
 * @return array Fully normalized record.
 */
function tc_normalize_post_record( array $data ): array {
    $defaults = array(
        'id'                 => 0,
        'url'                => '',
        'title'              => '',
        'excerpt'            => '',
        'date_gmt'           => '',
        'modified_gmt'       => '',
        'tag_ids'            => array(),
        'cat_ids'            => array(),
        'sticky'             => false,
        'thumbnail_url'      => '',
        'thumbnail_width'    => null,
        'thumbnail_height'   => null,
        'origin'             => '',
        'foaf_depth'         => 0,
        'score'              => 0.0,
        'matched_term_slugs' => array(),
        'ht_scored'          => false,
        'author'             => '',
        'is_local'           => false,
    );

    $rec = array_merge( $defaults, $data );

    $rec['id']                 = (int) $rec['id'];
    $rec['url']                = (string) $rec['url'];
    $rec['title']              = (string) $rec['title'];
    $rec['excerpt']            = (string) $rec['excerpt'];
    $rec['date_gmt']           = tc_normalize_gmt_date( $rec['date_gmt'] );
    $rec['modified_gmt']       = tc_normalize_gmt_date( $rec['modified_gmt'] );
    $rec['tag_ids']            = array_map( 'intval', (array) $rec['tag_ids'] );
    $rec['cat_ids']            = array_map( 'intval', (array) $rec['cat_ids'] );
    $rec['sticky']             = (bool) $rec['sticky'];
    $rec['thumbnail_url']      = (string) $rec['thumbnail_url'];
    $rec['thumbnail_width']    = ( null === $rec['thumbnail_width'] )  ? null : (int) $rec['thumbnail_width'];
    $rec['thumbnail_height']   = ( null === $rec['thumbnail_height'] ) ? null : (int) $rec['thumbnail_height'];
    $rec['origin']             = (string) $rec['origin'];
    $rec['foaf_depth']         = (int) $rec['foaf_depth'];
    $rec['score']              = (float) $rec['score'];
    $rec['matched_term_slugs'] = (array) $rec['matched_term_slugs'];
    $rec['ht_scored']          = (bool) $rec['ht_scored'];
    $rec['author']             = (string) $rec['author'];
    $rec['is_local']           = (bool) $rec['is_local'];

    return $rec;
}

/**
 * Aspect ratio (as a CSS "w / h" value) for a registered image size.
 *
 * Used to render thumbnails at a uniform aspect across local and peer sources:
 * peer images can't be regenerated server-side, but a uniform CSS box with
 * object-fit:cover makes a mixed grid line up. Returns '' when the size has no
 * fixed height (uncropped/unbounded), so callers fall back to natural aspect.
 *
 * @since 1.0.0
 * @param string $size Registered image size slug.
 * @return string e.g. "300 / 300", or '' when unknown/unbounded.
 */
function tc_image_size_ratio( string $size ): string {
    if ( '' === $size ) {
        return '';
    }
    $sizes = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : array();
    if ( isset( $sizes[ $size ] ) ) {
        $w = (int) $sizes[ $size ]['width'];
        $h = (int) $sizes[ $size ]['height'];
        if ( $w > 0 && $h > 0 ) {
            return $w . ' / ' . $h;
        }
    }
    return '';
}

/**
 * Build the inline style for a .tc-post-list-items container: column count and
 * uniform thumbnail aspect ratio. Shared by [tc_peer_posts] and [tc_peer_browse]
 * so both render multi-column grids with consistent thumbnails.
 *
 * @since 1.0.0
 * @param int    $columns    Grid column count (>= 1).
 * @param string $image_size Registered image size driving the thumbnail aspect.
 * @return string Inline style declarations (no leading/trailing space), or ''.
 */
function tc_post_list_items_style( int $columns, string $image_size ): string {
    $style = '';
    if ( $columns > 1 ) {
        $style .= '--tc-columns:' . (int) $columns . ';';
    }
    $ratio = tc_image_size_ratio( $image_size );
    if ( '' !== $ratio ) {
        $style .= '--tc-thumb-ratio:' . $ratio . ';';
    }
    return $style;
}

/**
 * Deduplicate normalized post records by URL, preserving order (first wins).
 *
 * @since 1.0.0
 * @param array $records
 * @return array
 */
function tc_dedupe_posts_by_url( array $records ): array {
    $seen = array();
    $out  = array();
    foreach ( $records as $rec ) {
        $url = $rec['url'] ?? '';
        if ( '' === $url || isset( $seen[ $url ] ) ) {
            continue;
        }
        $seen[ $url ] = true;
        $out[]        = $rec;
    }
    return $out;
}

/**
 * Drop queried terms hidden from the current user, so the local blend never
 * surfaces posts via a restricted term.
 *
 * HyperTaxonomy owns term visibility; this mirrors the gate the peer side already
 * applies through `htax_get_term_external_posts()` and the WP-SKOS endpoint applies
 * to its concept terms. A no-op when HyperTaxonomy is not active.
 *
 * @since 1.0.0
 * @param array $tax_terms Map of taxonomy slug => term IDs.
 * @return array Same map with hidden-and-unviewable terms removed (and empty taxonomies dropped).
 */
function tc_filter_hidden_terms( array $tax_terms ): array {
    if ( ! function_exists( 'htax_is_term_hidden' ) ) {
        return $tax_terms;
    }

    $out = array();
    foreach ( $tax_terms as $tax => $ids ) {
        $visible = array();
        foreach ( (array) $ids as $tid ) {
            $tid = (int) $tid;
            if ( htax_is_term_hidden( $tid )
                && ! ( function_exists( 'htax_can_see_hidden_terms' ) && htax_can_see_hidden_terms( $tax ) ) ) {
                continue;
            }
            $visible[] = $tid;
        }
        if ( ! empty( $visible ) ) {
            $out[ $tax ] = $visible;
        }
    }

    return $out;
}

/**
 * Build the term => expanded-IDs map for local selection, using HyperTaxonomy's
 * relationship expansion when available.
 *
 * For each queried term, returns its HyperTaxonomy archive expansion (term +
 * visible descendants + inclusions + group members) so the local query selects
 * relationship-related posts, matching the recall of HyperTaxonomy archives,
 * [ht_related_posts], and the WP-SKOS document endpoint. Returns an empty array
 * when HyperTaxonomy is not active — callers then fall back to literal term IDs.
 *
 * @since 1.0.0
 * @param array $tax_terms Map of taxonomy slug => term IDs.
 * @return array Map of term_id => expanded term IDs (empty when HyperTaxonomy is inactive).
 */
function tc_local_term_expansions( array $tax_terms ): array {
    if ( ! function_exists( 'htax_get_expanded_term_ids' ) ) {
        return array();
    }

    $expansions = array();
    foreach ( $tax_terms as $ids ) {
        foreach ( (array) $ids as $tid ) {
            $tid = (int) $tid;
            if ( $tid <= 0 || isset( $expansions[ $tid ] ) ) {
                continue;
            }
            // Include redirect sources so a target term also surfaces posts tagged
            // with terms that redirect to it, matching the HyperTaxonomy search index.
            $expanded            = htax_get_expanded_term_ids( $tid, true );
            $expansions[ $tid ]  = ! empty( $expanded ) ? array_map( 'intval', $expanded ) : array( $tid );
        }
    }

    return $expansions;
}

/**
 * Collect local posts matching selected terms, normalized to the shared record
 * shape (is_local => true). Uses a plain WP_Query — no HyperTaxonomy dependency.
 *
 * Shared by [tc_peer_browse] (visitor-selected terms) and [tc_peer_posts]
 * scope="all" (current post's terms), so the local side of a blended list is
 * built identically in both.
 *
 * @since 1.0.0
 * @param array  $tax_terms   Map of taxonomy slug => term IDs.
 * @param string $match       'any' (OR) or 'all' (AND).
 * @param int    $limit       Max posts to fetch.
 * @param bool   $show_thumb  Whether to resolve thumbnails.
 * @param string $image_size  Registered image size for thumbnails.
 * @param int[]  $exclude_ids Post IDs to exclude (e.g. the current post).
 * @param array  $expansions  Optional map of queried term_id => expanded term IDs
 *                            (term + descendants + inclusions + group members), as
 *                            produced by HyperTaxonomy. When supplied, each term
 *                            matches via its expanded set so relationship-related
 *                            posts are selected, not just directly-tagged ones.
 *                            Empty = literal term IDs only (no HyperTaxonomy).
 * @return array Normalized local post records.
 */
function tc_collect_local_posts( array $tax_terms, string $match, int $limit, bool $show_thumb, string $image_size, array $exclude_ids = array(), array $expansions = array() ): array {
    // Normalize the taxonomy => IDs map, dropping empties.
    $clean = array();
    foreach ( $tax_terms as $tax => $ids ) {
        $tax = sanitize_key( $tax );
        $ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
        if ( '' !== $tax && taxonomy_exists( $tax ) && ! empty( $ids ) ) {
            $clean[ $tax ] = $ids;
        }
    }
    if ( empty( $clean ) ) {
        return array();
    }

    // When HyperTaxonomy supplies relationship expansions, each queried term is
    // matched via its own ID plus its expanded set (descendants, inclusions, group
    // members); those sets already include hierarchy, so include_children is turned
    // off. Without expansions the query uses literal term IDs and WP's default child
    // handling, preserving the no-HyperTaxonomy behavior.
    $expanded = ! empty( $expansions );
    $ids_for  = static function ( int $tid ) use ( $expansions ) {
        $set = array_values( array_filter( array_map( 'intval', (array) ( $expansions[ $tid ] ?? array( $tid ) ) ) ) );
        return ! empty( $set ) ? $set : array( $tid );
    };

    if ( 'all' === $match ) {
        // Every selected term must be present (via its own ID or its expansion):
        // one clause per original term, AND across them.
        $tax_query = array( 'relation' => 'AND' );
        foreach ( $clean as $tax => $ids ) {
            foreach ( $ids as $tid ) {
                $clause = array( 'taxonomy' => $tax, 'field' => 'term_id', 'terms' => $ids_for( $tid ), 'operator' => 'IN' );
                if ( $expanded ) {
                    $clause['include_children'] = false;
                }
                $tax_query[] = $clause;
            }
        }
    } else {
        // Any selected term: one IN clause per taxonomy (union of each term's
        // expansion), OR across taxonomies.
        $clauses = array();
        foreach ( $clean as $tax => $ids ) {
            $union = array();
            foreach ( $ids as $tid ) {
                $union = array_merge( $union, $ids_for( $tid ) );
            }
            $clause = array( 'taxonomy' => $tax, 'field' => 'term_id', 'terms' => array_values( array_unique( $union ) ), 'operator' => 'IN' );
            if ( $expanded ) {
                $clause['include_children'] = false;
            }
            $clauses[] = $clause;
        }
        $tax_query = ( count( $clauses ) > 1 ) ? array_merge( array( 'relation' => 'OR' ), $clauses ) : $clauses;
    }

    $args = array(
        'post_type'              => 'post',
        'post_status'            => 'publish',
        'posts_per_page'         => max( 1, $limit ),
        'no_found_rows'          => true,
        'ignore_sticky_posts'    => true,
        'update_post_term_cache' => false,
        'tax_query'              => $tax_query,
    );
    $exclude_ids = array_values( array_filter( array_map( 'intval', $exclude_ids ) ) );
    if ( ! empty( $exclude_ids ) ) {
        $args['post__not_in'] = $exclude_ids;
    }

    $query = new WP_Query( $args );
    if ( empty( $query->posts ) ) {
        return array();
    }

    $origin     = tc_external_get_site_origin( home_url( '/' ) );
    $image_size = '' !== $image_size ? $image_size : 'medium';

    $out = array();
    foreach ( $query->posts as $p ) {
        $thumb_url = '';
        $thumb_w   = 0;
        $thumb_h   = 0;
        if ( $show_thumb ) {
            $thumb_id = get_post_thumbnail_id( $p );
            if ( $thumb_id ) {
                $img = wp_get_attachment_image_src( $thumb_id, $image_size );
                if ( $img ) {
                    $thumb_url = $img[0];
                    $thumb_w   = (int) $img[1];
                    $thumb_h   = (int) $img[2];
                }
            }
        }

        $out[] = tc_normalize_post_record( array(
            'id'               => $p->ID,
            'url'              => get_permalink( $p ),
            'title'            => get_the_title( $p ),
            'excerpt'          => wp_strip_all_tags( get_the_excerpt( $p ) ),
            'date_gmt'         => $p->post_date_gmt,
            'modified_gmt'     => $p->post_modified_gmt,
            'thumbnail_url'    => $thumb_url,
            'thumbnail_width'  => $thumb_w,
            'thumbnail_height' => $thumb_h,
            'origin'           => $origin,
            'author'           => get_the_author_meta( 'display_name', (int) $p->post_author ),
            'is_local'         => true,
        ) );
    }
    return $out;
}

/**
 * Normalize a raw WordPress REST post object into the standard HT external post structure.
 *
 * Both the standard and scored fetch paths produce this structure so the
 * shortcode can merge and sort results uniformly.
 *
 * @since 1.0.0
 * @param array  $raw            Raw post object from the REST API.
 * @param string $origin         Peer site origin (scheme://host).
 * @param bool   $show_thumbnail Whether thumbnail data was requested.
 * @return array Normalized post.
 */
function tc_external_normalize_post( array $raw, string $origin, bool $show_thumbnail ): array {
    $title   = isset( $raw['title']['rendered'] )
        ? wp_strip_all_tags( $raw['title']['rendered'] )
        : '';
    $excerpt = isset( $raw['excerpt']['rendered'] )
        ? wp_strip_all_tags( $raw['excerpt']['rendered'] )
        : '';

    $thumbnail_url    = null;
    $thumbnail_width  = null;
    $thumbnail_height = null;

    if ( $show_thumbnail ) {
        $media = $raw['_embedded']['wp:featuredmedia'][0] ?? null;
        if ( is_array( $media ) ) {
            $medium = $media['media_details']['sizes']['medium'] ?? null;
            if ( is_array( $medium ) && ! empty( $medium['source_url'] ) ) {
                $thumbnail_url    = $medium['source_url'];
                $thumbnail_width  = isset( $medium['width'] )  ? (int) $medium['width']  : null;
                $thumbnail_height = isset( $medium['height'] ) ? (int) $medium['height'] : null;
            } elseif ( ! empty( $media['source_url'] ) ) {
                $thumbnail_url    = $media['source_url'];
                $thumbnail_width  = isset( $media['media_details']['width'] )  ? (int) $media['media_details']['width']  : null;
                $thumbnail_height = isset( $media['media_details']['height'] ) ? (int) $media['media_details']['height'] : null;
            }
        }
    }

    return tc_normalize_post_record( array(
        'id'               => $raw['id'] ?? 0,
        'url'              => $raw['link'] ?? '',
        'title'            => $title,
        'excerpt'          => $excerpt,
        'date_gmt'         => $raw['date_gmt'] ?? '',
        'modified_gmt'     => $raw['modified_gmt'] ?? '',
        'tag_ids'          => (array) ( $raw['tags'] ?? array() ),
        'cat_ids'          => (array) ( $raw['categories'] ?? array() ),
        'sticky'           => ! empty( $raw['sticky'] ),
        'thumbnail_url'    => $thumbnail_url,
        'thumbnail_width'  => $thumbnail_width,
        'thumbnail_height' => $thumbnail_height,
        'origin'           => $origin,
    ) );
}

/**
 * Build the standard _fields string for a post fetch request.
 *
 * @since 1.0.0
 * @param bool   $show_thumbnail Whether to include featured_media.
 * @param bool   $ht_scored      Whether to include htax_* response fields.
 * @return string
 */
function tc_external_post_fields( bool $show_thumbnail, bool $ht_scored ): string {
    // When thumbnails are requested, WordPress REST API strips _embedded from
    // the response when _fields is set (even if _embedded is listed) because
    // _embedded is not a schema-registered field — it is dynamically added
    // by the embed pipeline after the _fields filter runs. Returning an empty
    // string signals callers to omit the _fields param entirely, letting
    // WordPress return the full response (including _embedded) for that request.
    if ( $show_thumbnail ) {
        return '';
    }
    $fields = 'id,date_gmt,modified_gmt,link,title,excerpt,tags,categories,sticky';
    if ( $ht_scored ) {
        $fields .= ',htax_score,htax_matched_term_slugs,htax_term_peers';
    }
    return $fields;
}

/**
 * Common wp_remote_get args for all peer fetches.
 *
 * @since 1.0.0
 * @param int $timeout Request timeout in seconds.
 * @return array
 */
function tc_external_request_args( int $timeout = 8 ): array {
    return array(
        'timeout'    => $timeout,
        'user-agent' => 'TermConnect/' . TC_VERSION,
        'sslverify'  => true,
    );
}

// ==============================================================================
// CACHE VERSIONING FOR PER-LINK-TYPE CACHE CLEARING
// ==============================================================================

/**
 * Return the current cache version for a link type slug.
 *
 * The version is stored in a non-autoloaded option and starts at 1. Incrementing
 * it via tc_external_clear_site_cache() invalidates all cached post lists for
 * that link type without needing pattern-based transient deletion.
 *
 * @since 1.0.0
 * @param string $peer_site_slug Link type slug, or empty string for FOAF/ad-hoc peers.
 * @return int Version counter (1 when not yet set).
 */
function tc_external_get_link_type_cache_version( string $peer_site_slug ): int {
    if ( '' === $peer_site_slug ) {
        return 0;
    }
    return (int) get_option( 'tc_ext_ltver_' . $peer_site_slug, 1 );
}

/**
 * Invalidate all cached post lists for a link type by incrementing its version counter.
 *
 * Existing transient entries are abandoned (they expire at their original TTL) and
 * new fetches use the incremented version in the cache key. This approach works with
 * any object cache plugin since it requires no pattern-based deletion.
 *
 * @since 1.0.0
 * @param string $peer_site_slug Link type slug.
 */
function tc_external_clear_site_cache( string $peer_site_slug ): void {
    if ( '' === $peer_site_slug ) {
        return;
    }
    $current = tc_external_get_link_type_cache_version( $peer_site_slug );
    update_option( 'tc_ext_ltver_' . $peer_site_slug, $current + 1, false );
    delete_option( 'tc_ext_ltfetch_' . $peer_site_slug );
}

/**
 * Record a fresh fetch result for a link type so the settings page can show
 * the cached post count and last-fetched time without scanning all transients.
 *
 * Called only when a transient is freshly written (not on a cache hit).
 *
 * @since 1.0.0
 * @param string $peer_site_slug Link type slug.
 * @param int    $count   Number of posts stored in the transient.
 */
function tc_external_record_fetch( string $peer_site_slug, int $count ): void {
    if ( '' === $peer_site_slug ) {
        return;
    }
    update_option( 'tc_ext_ltfetch_' . $peer_site_slug, array(
        'count' => $count,
        'time'  => time(),
    ), false );
}

// ==============================================================================
// POST FETCHING — PUBLIC
// ==============================================================================

/**
 * Fetch posts from a standard (non-HT) WordPress peer by taxonomy term IDs.
 *
 * Always fetches date-ordered; the caller sorts from the cached set. Does not
 * send any htax_* params, so this works on any WordPress site.
 *
 * @since 1.0.0
 * @param string $origin         Peer site origin.
 * @param string $taxonomy       Taxonomy slug (determines the ?tags= / ?categories= param name).
 * @param int[]  $all_term_ids   All remote term IDs to include (primary + includes combined for OR).
 * @param bool   $show_thumbnail Whether to request the featured image.
 * @param int    $fetch_size     Number of posts to request from the remote site.
 * @param int    $ttl            Cache TTL in seconds.
 * @param string $peer_site_slug        Link type slug; used to scope the cache so per-site clearing works.
 * @return array Normalized posts (score=0.0, ht_scored=false).
 */
function tc_external_fetch_posts(
    string $origin,
    string $taxonomy,
    array $all_term_ids,
    bool $show_thumbnail,
    int $fetch_size,
    int $ttl,
    string $peer_site_slug = ''
): array {
    if ( empty( $all_term_ids ) ) {
        return array();
    }

    $sorted_ids = array_unique( array_map( 'intval', $all_term_ids ) );
    sort( $sorted_ids );

    $version   = tc_external_get_link_type_cache_version( $peer_site_slug );
    $cache_key = 'tc_ext_posts_' . md5(
        "$peer_site_slug|$version|$origin|$taxonomy|" . implode( ',', $sorted_ids ) . "|$fetch_size|" . ( $show_thumbnail ? '1' : '0' )
    );

    $cached = get_transient( $cache_key );
    if ( false !== $cached ) {
        return (array) $cached;
    }

    if ( tc_peer_site_health_is_blocked( $peer_site_slug ) ) {
        return array();
    }

    $rest_base   = tc_external_get_taxonomy_rest_base( $taxonomy );
    $fields_str  = tc_external_post_fields( $show_thumbnail, false );
    $params      = array(
        $rest_base => implode( ',', $sorted_ids ),
        'per_page' => $fetch_size,
        'orderby'  => 'date',
        'order'    => 'desc',
    );
    if ( '' !== $fields_str ) {
        $params['_fields'] = $fields_str;
    }
    if ( $show_thumbnail ) {
        $params['_embed'] = 'wp:featuredmedia';
    }

    $url      = $origin . '/wp-json/wp/v2/posts?' . http_build_query( $params );
    $response = wp_remote_get( $url, tc_external_request_args() );

    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        $error = is_wp_error( $response )
            ? $response->get_error_message()
            : 'HTTP ' . wp_remote_retrieve_response_code( $response );
        tc_peer_site_health_record_failure( $peer_site_slug, $error, $ttl );
        set_transient( $cache_key, array(), 300 );
        return array();
    }

    $body = tc_external_json_decode( wp_remote_retrieve_body( $response ) );
    if ( ! is_array( $body ) ) {
        tc_peer_site_health_record_failure( $peer_site_slug, 'invalid JSON', $ttl );
        set_transient( $cache_key, array(), 300 );
        return array();
    }

    tc_peer_site_health_record_success( $peer_site_slug );

    $posts = array();
    foreach ( $body as $raw ) {
        if ( is_array( $raw ) && ! empty( $raw['id'] ) ) {
            $posts[] = tc_external_normalize_post( $raw, $origin, $show_thumbnail );
        }
    }

    tc_external_record_fetch( $peer_site_slug, count( $posts ) );
    set_transient( $cache_key, $posts, $ttl );
    return $posts;
}

/**
 * Fetch scored posts from an HT-enabled peer using htax_* query params.
 *
 * Sends the full tc_includes / tc_weights / tc_match scoring params.
 * Extracts htax_term_peers from the response and caches them for FOAF discovery.
 * Always fetches score-ordered; the caller re-sorts from the cached set.
 *
 * @since 1.0.0
 * @param string  $origin         Peer site origin.
 * @param string  $taxonomy       Taxonomy slug.
 * @param array   $term_id_map    [ primary_remote_id => [include_remote_id, ...], ... ]
 * @param string  $match          'any' or 'all'.
 * @param array   $term_weights   [ remote_term_id => weight ] sent as htax_term_weights.
 * @param bool    $show_thumbnail Whether to request the featured image.
 * @param int    $fetch_size     Number of posts to request.
 * @param int    $ttl            Cache TTL in seconds.
 * @param string $peer_site_slug        Link type slug; used to scope the cache so per-site clearing works.
 * @param bool   $expand         Ask the peer to re-expand primaries via its own relationships (htax_expand).
 * @return array Normalized posts with score and matched_term_slugs from HT.
 */
function tc_external_fetch_scored_posts(
    string $origin,
    string $taxonomy,
    array $term_id_map,
    string $match,
    array $term_weights,
    bool $show_thumbnail,
    int $fetch_size,
    int $ttl,
    string $peer_site_slug = '',
    bool $expand = false
): array {
    if ( empty( $term_id_map ) ) {
        return array();
    }

    $version   = tc_external_get_link_type_cache_version( $peer_site_slug );
    $cache_key = 'tc_ext_scored_' . md5(
        "$peer_site_slug|$version|$origin|$taxonomy|" . tc_external_serialize_term_id_map( $term_id_map ) . "|$match|$fetch_size|" . ( $show_thumbnail ? '1' : '0' ) . '|' . wp_json_encode( $term_weights ) . '|' . ( $expand ? '1' : '0' )
    );

    $cached = get_transient( $cache_key );
    if ( false !== $cached ) {
        return (array) $cached;
    }

    if ( tc_peer_site_health_is_blocked( $peer_site_slug ) ) {
        return array();
    }

    $rest_base = tc_external_get_taxonomy_rest_base( $taxonomy );

    // Collect all IDs for the standard taxonomy filter param.
    $all_ids = array();
    foreach ( $term_id_map as $primary_id => $include_ids ) {
        $all_ids[] = (int) $primary_id;
        foreach ( (array) $include_ids as $inc_id ) {
            $all_ids[] = (int) $inc_id;
        }
    }
    $all_ids = array_unique( $all_ids );

    $fields_str = tc_external_post_fields( $show_thumbnail, true );
    $params     = array(
        $rest_base   => implode( ',', $all_ids ),
        'htax_match' => $match,
        'per_page'   => $fetch_size,
        'orderby'    => 'score',
    );
    if ( '' !== $fields_str ) {
        $params['_fields'] = $fields_str;
    }

    // htax_includes: primary_id => csv of include_ids.
    foreach ( $term_id_map as $primary_id => $include_ids ) {
        $key            = 'htax_includes[' . (int) $primary_id . ']';
        $params[ $key ] = implode( ',', array_map( 'intval', (array) $include_ids ) );
    }

    // Per-term scoring weights (the peer applies these verbatim). Any matched
    // term not listed scores 1.0 on the peer.
    foreach ( $term_weights as $tid => $wt ) {
        $params[ 'htax_term_weights[' . (int) $tid . ']' ] = (float) $wt;
    }
    // Ask the peer to also match each primary via its own relationship graph.
    if ( $expand ) {
        $params['htax_expand'] = '1';
    }
    if ( $show_thumbnail ) {
        $params['_embed'] = 'wp:featuredmedia';
    }

    $url      = $origin . '/wp-json/wp/v2/posts?' . http_build_query( $params );
    $response = wp_remote_get( $url, tc_external_request_args( 10 ) );

    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        $error = is_wp_error( $response )
            ? $response->get_error_message()
            : 'HTTP ' . wp_remote_retrieve_response_code( $response );
        tc_peer_site_health_record_failure( $peer_site_slug, $error, $ttl );
        set_transient( $cache_key, array(), 300 );
        return array();
    }

    $is_ht = '' !== wp_remote_retrieve_header( $response, 'X-HyperTaxonomy' );
    $body  = tc_external_json_decode( wp_remote_retrieve_body( $response ) );

    if ( ! is_array( $body ) ) {
        tc_peer_site_health_record_failure( $peer_site_slug, 'invalid JSON', $ttl );
        set_transient( $cache_key, array(), 300 );
        return array();
    }

    tc_peer_site_health_record_success( $peer_site_slug );

    // Cache peer discovery data from htax_term_peers before normalizing.
    if ( $is_ht ) {
        tc_external_cache_term_peers( $origin, $body );
    }

    $posts = array();
    foreach ( $body as $raw ) {
        if ( ! is_array( $raw ) || empty( $raw['id'] ) ) {
            continue;
        }
        $post = tc_external_normalize_post( $raw, $origin, $show_thumbnail );
        if ( $is_ht && isset( $raw['htax_score'] ) && null !== $raw['htax_score'] ) {
            $post['score']              = (float) $raw['htax_score'];
            $post['matched_term_slugs'] = (array) ( $raw['htax_matched_term_slugs'] ?? array() );
            $post['ht_scored']          = true;
        }
        $posts[] = $post;
    }

    tc_external_record_fetch( $peer_site_slug, count( $posts ) );
    set_transient( $cache_key, $posts, $ttl );
    return $posts;
}

/**
 * Fetch posts from a peer site ordered by WP text-match relevance.
 *
 * Sends ?search={keyword}&orderby=relevance so the remote site returns its most
 * text-relevant posts on page 1. The search keyword is included in the cache key
 * because it determines which posts come back.
 *
 * @since 1.0.0
 * @param string $origin         Peer site origin.
 * @param string $taxonomy       Taxonomy slug.
 * @param int[]  $all_term_ids   All remote term IDs to include.
 * @param string $search_keyword Keyword to send as ?search=. Typically the current post title.
 * @param bool   $show_thumbnail Whether to request the featured image.
 * @param int    $fetch_size     Number of posts to request.
 * @param int    $ttl            Cache TTL in seconds.
 * @param string $peer_site_slug        Link type slug; used to scope the cache so per-site clearing works.
 * @return array Normalized posts (score=0.0; caller applies client-side scoring after).
 */
function tc_external_fetch_relevance_posts(
    string $origin,
    string $taxonomy,
    array $all_term_ids,
    string $search_keyword,
    bool $show_thumbnail,
    int $fetch_size,
    int $ttl,
    string $peer_site_slug = ''
): array {
    if ( empty( $all_term_ids ) || '' === $search_keyword ) {
        return array();
    }

    $sorted_ids = array_unique( array_map( 'intval', $all_term_ids ) );
    sort( $sorted_ids );

    $version   = tc_external_get_link_type_cache_version( $peer_site_slug );
    $cache_key = 'tc_ext_rel_' . md5(
        "$peer_site_slug|$version|$origin|$taxonomy|" . implode( ',', $sorted_ids ) . '|' . md5( $search_keyword ) . "|$fetch_size|" . ( $show_thumbnail ? '1' : '0' )
    );

    $cached = get_transient( $cache_key );
    if ( false !== $cached ) {
        return (array) $cached;
    }

    if ( tc_peer_site_health_is_blocked( $peer_site_slug ) ) {
        return array();
    }

    $rest_base  = tc_external_get_taxonomy_rest_base( $taxonomy );
    $fields_str = tc_external_post_fields( $show_thumbnail, false );
    $params     = array(
        $rest_base => implode( ',', $sorted_ids ),
        'search'   => $search_keyword,
        'per_page' => $fetch_size,
        'orderby'  => 'relevance',
    );
    if ( '' !== $fields_str ) {
        $params['_fields'] = $fields_str;
    }
    if ( $show_thumbnail ) {
        $params['_embed'] = 'wp:featuredmedia';
    }

    $url      = $origin . '/wp-json/wp/v2/posts?' . http_build_query( $params );
    $response = wp_remote_get( $url, tc_external_request_args() );

    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        $error = is_wp_error( $response )
            ? $response->get_error_message()
            : 'HTTP ' . wp_remote_retrieve_response_code( $response );
        tc_peer_site_health_record_failure( $peer_site_slug, $error, $ttl );
        set_transient( $cache_key, array(), 300 );
        return array();
    }

    $body = tc_external_json_decode( wp_remote_retrieve_body( $response ) );
    if ( ! is_array( $body ) ) {
        tc_peer_site_health_record_failure( $peer_site_slug, 'invalid JSON', $ttl );
        set_transient( $cache_key, array(), 300 );
        return array();
    }

    tc_peer_site_health_record_success( $peer_site_slug );

    $posts = array();
    foreach ( $body as $raw ) {
        if ( is_array( $raw ) && ! empty( $raw['id'] ) ) {
            $posts[] = tc_external_normalize_post( $raw, $origin, $show_thumbnail );
        }
    }

    tc_external_record_fetch( $peer_site_slug, count( $posts ) );
    set_transient( $cache_key, $posts, $ttl );
    return $posts;
}

// ==============================================================================
// CLIENT-SIDE SCORING
// ==============================================================================

/**
 * Apply weighted relevance scores to a set of posts from a non-HT peer.
 *
 * Checks each post's tag_ids ∪ cat_ids against the term ID map and sums the
 * per-term weights of matched terms (the same weights sent to HT peers as
 * htax_term_weights), so HT and non-HT peers score on the same scale. Scores are
 * normalized to [0, 1] against the sum of the primary weights.
 *
 * Also populates matched_term_slugs from the id_to_slug reverse map.
 *
 * @since 1.0.0
 * @param array $posts        Normalized posts (from tc_external_fetch_posts or relevance fetch).
 * @param array $term_id_map  [ primary_id => [include_id, ...], ... ]
 * @param array $id_to_slug   [ remote_term_id => slug ] — inverted from the resolution step.
 * @param array $term_weights [ remote_term_id => weight ]; any matched term not listed counts 1.0.
 * @return array Posts with score and matched_term_slugs set; ht_scored remains false.
 */
function tc_external_score_posts(
    array $posts,
    array $term_id_map,
    array $id_to_slug,
    array $term_weights
): array {
    $weight_of = static function ( $tid ) use ( $term_weights ) {
        return isset( $term_weights[ $tid ] ) ? (float) $term_weights[ $tid ] : 1.0;
    };

    // Max possible = sum of each group's primary weight.
    $max_possible = 0.0;
    foreach ( array_keys( $term_id_map ) as $pid ) {
        $max_possible += $weight_of( (int) $pid );
    }
    $max_possible = max( $max_possible, 0.001 );

    foreach ( $posts as &$post ) {
        $all_post_term_ids = array_merge( $post['tag_ids'], $post['cat_ids'] );
        $slug_weights      = array(); // slug => best weight (one count per term, max weight wins)

        foreach ( $term_id_map as $primary_id => $include_ids ) {
            $candidates = array_merge( array( (int) $primary_id ), array_map( 'intval', (array) $include_ids ) );
            foreach ( $candidates as $cid ) {
                if ( in_array( $cid, $all_post_term_ids, true ) ) {
                    $slug = $id_to_slug[ $cid ] ?? (string) $cid;
                    $w    = $weight_of( $cid );
                    if ( ! isset( $slug_weights[ $slug ] ) || $w > $slug_weights[ $slug ] ) {
                        $slug_weights[ $slug ] = $w;
                    }
                }
            }
        }

        $post['score']              = min( round( array_sum( $slug_weights ) / $max_possible, 4 ), 1.0 );
        $post['matched_term_slugs'] = array_keys( $slug_weights );
    }
    unset( $post );

    return $posts;
}

// ==============================================================================
// FOAF PEER DISCOVERY
// ==============================================================================

/**
 * Extract and cache WordPress peer connections from htax_term_peers in post responses.
 *
 * This is FOAF discovery mechanism B: passive extraction from post fetch results.
 * Covers all terms on returned posts, not only the queried terms — revealing peer
 * connections through terms the requesting site never asked about and has no direct
 * link type mapping for.
 *
 * Called automatically by tc_external_fetch_scored_posts() after each successful
 * HT response. The peer cache lives longer than the post cache so connections
 * persist across post cache expirations.
 *
 * @since 1.0.0
 * @param string  $remote_origin Origin of the site that returned these posts.
 * @param array[] $raw_posts     Raw post objects from the REST response (before normalization).
 */
function tc_external_cache_term_peers( string $remote_origin, array $raw_posts ): void {
    $settings = get_option( 'tc_settings', array() );
    $ttl      = (int) ( $settings['ht_status_cache_ttl'] ?? 86400 );

    // Collect all peer entries per remote term_id from every post in the response.
    $peer_map = array();
    foreach ( $raw_posts as $raw ) {
        if ( ! is_array( $raw ) || empty( $raw['htax_term_peers'] ) || ! is_array( $raw['htax_term_peers'] ) ) {
            continue;
        }
        foreach ( $raw['htax_term_peers'] as $term_id_str => $term_data ) {
            if ( ! is_array( $term_data ) || empty( $term_data['peers'] ) || ! is_array( $term_data['peers'] ) ) {
                continue;
            }
            $term_id = (int) $term_id_str;
            if ( ! isset( $peer_map[ $term_id ] ) ) {
                $peer_map[ $term_id ] = array();
            }
            foreach ( $term_data['peers'] as $peer ) {
                if ( ! is_array( $peer ) || empty( $peer['origin'] ) || empty( $peer['slug'] ) ) {
                    continue;
                }
                $entry = array( 'origin' => (string) $peer['origin'], 'slug' => (string) $peer['slug'] );
                if ( ! in_array( $entry, $peer_map[ $term_id ], true ) ) {
                    $peer_map[ $term_id ][] = $entry;
                }
            }
        }
    }

    foreach ( $peer_map as $term_id => $peers ) {
        set_transient( 'tc_ext_tpeer_' . md5( "$remote_origin|$term_id" ), $peers, $ttl );
    }
}

/**
 * Collect FOAF peer candidates for a set of remote term IDs on a given origin.
 *
 * Uses two independent discovery mechanisms and merges the results:
 *
 * Mechanism A (targeted): fetch each queried term's tc_links from the peer TC
 * term endpoint. Finds wordpress-platform entries; origin and slug are extracted
 * from the URL field of each [ { url, platform, ... } ] entry.
 *
 * Mechanism B (opportunistic): read the local peer cache populated from
 * htax_term_peers in earlier post responses. Finds sites connected through any
 * term that co-appeared on returned posts, including terms the requesting site
 * never queried.
 *
 * Filters out blacklisted origins and origins already in $visited. Does not add
 * to $visited — the caller does that when queuing discovered peers for fetching.
 *
 * @since 1.0.0
 * @param string $origin             Origin whose term data to inspect.
 * @param array  $term_taxonomy_map  [ remote_term_id => taxonomy ] for the queried terms.
 * @param array  $visited            Origins already fetched this traversal (passed by reference for read).
 * @return array[] Each entry: { origin: string, slug: string }
 */
function tc_external_get_foaf_candidates(
    string $origin,
    array $term_taxonomy_map,
    array $visited
): array {
    $candidates = array();
    $seen       = array(); // "peer_origin|slug" dedup keys

    // Mechanism A data, fetched up front: one request per up-to-100 terms per
    // taxonomy (via tc_external_get_term_data_batch()) rather than one request
    // per term — $term_taxonomy_map can hold many terms for a single FOAF
    // discovery call, and the single-term REST endpoint used previously meant
    // one HTTP round-trip per term every time.
    $tc_enabled = tc_external_is_tc_enabled( $origin );
    $term_data_by_id = array();
    if ( $tc_enabled ) {
        $ids_by_taxonomy = array();
        foreach ( $term_taxonomy_map as $term_id => $taxonomy ) {
            $ids_by_taxonomy[ (string) $taxonomy ][] = (int) $term_id;
        }
        foreach ( $ids_by_taxonomy as $taxonomy => $ids ) {
            $term_data_by_id += tc_external_get_term_data_batch( $origin, $taxonomy, $ids );
        }
    }

    foreach ( $term_taxonomy_map as $term_id => $taxonomy ) {
        $term_id = (int) $term_id;

        // Mechanism B: local peer cache (passive, populated from post responses).
        $cache_key = 'tc_ext_tpeer_' . md5( "$origin|$term_id" );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            foreach ( $cached as $peer ) {
                if ( empty( $peer['origin'] ) || empty( $peer['slug'] ) ) {
                    continue;
                }
                $key = $peer['origin'] . '|' . $peer['slug'];
                if ( isset( $seen[ $key ] ) || in_array( $peer['origin'], $visited, true ) ) {
                    continue;
                }
                if ( tc_external_is_blacklisted( $peer['origin'] ) ) {
                    continue;
                }
                $candidates[]  = $peer;
                $seen[ $key ]  = true;
            }
        }

        // Mechanism A: targeted term data fetch (TC-enabled sites only).
        if ( ! $tc_enabled ) {
            continue;
        }
        $term_data = $term_data_by_id[ $term_id ] ?? array( 'htax_includes' => array(), 'tc_links' => array() );
        foreach ( $term_data['tc_links'] as $link ) {
            if ( ! is_array( $link ) || ( $link['platform'] ?? '' ) !== 'wordpress' ) {
                continue;
            }
            $link_url    = (string) ( $link['url'] ?? '' );
            $peer_origin = tc_external_get_site_origin( $link_url );
            $peer_slug   = $link_url ? basename( rtrim( $link_url, '/' ) ) : '';
            if ( '' === $peer_origin || '' === $peer_slug ) {
                continue;
            }
            $key = $peer_origin . '|' . $peer_slug;
            if ( isset( $seen[ $key ] ) || in_array( $peer_origin, $visited, true ) ) {
                continue;
            }
            if ( tc_external_is_blacklisted( $peer_origin ) ) {
                continue;
            }
            $candidates[] = array( 'origin' => $peer_origin, 'slug' => $peer_slug );
            $seen[ $key ] = true;
        }
    }

    return $candidates;
}

// ==============================================================================
// WORDPRESS HTTP ALLOW-LIST FOR PEER ORIGINS
// ==============================================================================

/**
 * Tell WordPress's HTTP layer that registered WordPress peer link type hosts
 * are intentionally external, even when they resolve to loopback/private IPs.
 *
 * wp_http_validate_url() blocks requests to private IP ranges (including
 * 127.0.0.1) to prevent SSRF. On local development setups (e.g. Local by
 * Flywheel), peer sites share the same loopback address — this filter lifts
 * the block specifically for hosts declared in enabled WordPress peer link
 * types, preserving SSRF protection for all other hosts.
 *
 * @since 1.0.0
 */
add_filter(
    'http_request_host_is_external',
    function ( bool $is_external, string $host ): bool {
        if ( $is_external ) {
            return true;
        }
        static $peer_hosts = null;
        if ( null === $peer_hosts ) {
            $peer_hosts = array();
            foreach ( tc_get_platform_link_types( 'wordpress' ) as $peer_site ) {
                if ( ! empty( $peer_site['uri_prefix'] ) ) {
                    $parsed = wp_parse_url( $peer_site['uri_prefix'] );
                    if ( ! empty( $parsed['host'] ) ) {
                        $peer_hosts[] = strtolower( $parsed['host'] );
                    }
                }
            }
        }
        return in_array( strtolower( $host ), $peer_hosts, true );
    },
    10,
    2
);
