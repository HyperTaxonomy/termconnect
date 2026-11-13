<?php
/**
 * Shared utilities: remote request arguments, JSON decoding, peer origin parsing and named MySQL locks.
 *
 * @package TermConnect
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Common wp_remote_get args for all requests TermConnect sends to other sites.
 *
 * @since 1.0.0
 * @param int   $timeout Request timeout in seconds.
 * @param array $headers Extra request headers, e.g. X-TermConnect.
 * @return array
 */
function tc_external_request_args( int $timeout = 8, array $headers = array() ): array {
    $args = array(
        'timeout'    => $timeout,
        'user-agent' => 'TermConnect/' . TC_VERSION,
        'sslverify'  => true,
    );
    if ( $headers ) {
        $args['headers'] = $headers;
    }
    return $args;
}

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

/**
 * Extracts the site origin (scheme + host) from a link type uri_prefix.
 *
 * @since 1.0.0
 * @param string $uri_prefix URI prefix, e.g. 'https://example.com/tag/'.
 * @return string scheme://host, or empty string on failure.
 */
function tc_external_get_site_origin( string $uri_prefix ): string {
    $parsed = wp_parse_url( $uri_prefix );
    if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
        return '';
    }
    return $parsed['scheme'] . '://' . $parsed['host'];
}

/**
 * Acquire a cross-process/cross-request mutex using a MySQL advisory lock
 * (GET_LOCK). Unlike a get-then-set transient check, this is atomic: MySQL
 * itself serializes concurrent GET_LOCK() calls for the same name, so two
 * overlapping requests (e.g. a cron sync racing a manual AJAX sync) cannot
 * both believe they hold the lock. The lock is also automatically released
 * if the holding connection dies, so a crashed request can never leave it
 * stuck (unlike a transient, which only expires after its TTL).
 *
 * Requires MySQL 5.7.5+ / MariaDB 10.0+ for multiple simultaneous named locks
 * per connection; earlier versions silently release any lock already held by
 * the same connection when a second is acquired. None of this plugin's lock
 * sections nest, so that constraint does not apply here.
 *
 * The default timeout is short on purpose: these critical sections are all
 * brief, so failing fast (and, in AJAX contexts, letting the user retry) is
 * better than blocking a cron tick or page load on a long wait.
 *
 * @param string $name    Lock name — must match exactly between every caller
 *                         sharing the same critical section.
 * @param int    $timeout Seconds to wait for the lock before giving up.
 * @return bool True if the lock was acquired.
 */
function tc_acquire_mysql_lock( string $name, int $timeout = 2 ): bool {
    global $wpdb;
    return '1' === $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', tc_mysql_lock_name( $name ), $timeout ) );
}

/**
 * Release a lock previously acquired with tc_acquire_mysql_lock().
 *
 * @param string $name Lock name passed to tc_acquire_mysql_lock().
 */
function tc_release_mysql_lock( string $name ): void {
    global $wpdb;
    $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', tc_mysql_lock_name( $name ) ) );
}

/**
 * Headers that identify TermConnect to a peer site.
 *
 * X-TermConnect asks a TermConnect peer for its tc_links field and its peer-only
 * endpoints. X-HyperTaxonomy asks a HyperTaxonomy peer for the term fields it shares
 * only with peers (htax_includes, htax_redirect, htax_relationships on collections).
 * Both plugins check only that a header is present. X-TermConnect carries this
 * TermConnect version; X-HyperTaxonomy carries the local HyperTaxonomy version, or
 * 1.0.0 (the peer protocol version) when HyperTaxonomy isn't active here.
 *
 * @since 1.0.0
 * @param bool $hypertaxonomy Include X-HyperTaxonomy.
 * @param bool $termconnect   Include X-TermConnect.
 * @return array<string, string>
 */
function tc_external_peer_headers( bool $hypertaxonomy = false, bool $termconnect = true ): array {
    $headers = array();
    if ( $termconnect ) {
        $headers['X-TermConnect'] = TC_VERSION;
    }
    if ( $hypertaxonomy ) {
        $headers['X-HyperTaxonomy'] = defined( 'HTAX_VERSION' ) ? HTAX_VERSION : '1.0.0';
    }
    return $headers;
}

/**
 * GET a URL and decode its JSON body.
 *
 * For callers that treat every failure the same way: a request error, a status
 * other than 200 and a body that isn't a JSON object or array all return null.
 *
 * @since 1.0.0
 * @param string $url  URL to fetch.
 * @param array  $args wp_remote_get() arguments, usually from tc_external_request_args().
 * @return array|null Decoded body, or null on any failure.
 */
function tc_external_get_json( string $url, array $args ): ?array {
    $response = wp_remote_get( $url, $args );
    if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        return null;
    }
    $body = tc_external_json_decode( wp_remote_retrieve_body( $response ) );
    return is_array( $body ) ? $body : null;
}

/**
 * A date window with both keys present, in a fixed order.
 *
 * A post is in the window when it was published or last modified inside it:
 * after `modified_after` and no later than `published_before`. Either may be ''
 * for no limit on that side. The key names are the bounds peers are queried with:
 * modified after the start, published by the end; with both set, callers also drop
 * posts published before the start and modified after the end.
 *
 * @since 1.0.0
 * @param array $window 'modified_after' and 'published_before' ISO 8601 dates, each optional.
 * @return array{modified_after: string, published_before: string}
 */
function tc_normalize_date_window( array $window ): array {
    return array(
        'modified_after'   => (string) ( $window['modified_after'] ?? '' ),
        'published_before' => (string) ( $window['published_before'] ?? '' ),
    );
}
