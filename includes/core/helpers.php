<?php
/**
 * Shared utilities: remote requests and peer headers, JSON fetching, origin parsing, MySQL locks, form nonces.
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
function tconn_external_request_args( int $timeout = 8, array $headers = array() ): array {
    $args = array(
        'timeout'    => $timeout,
        'user-agent' => 'TermConnect/' . TCONN_VERSION,
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
function tconn_external_json_decode( string $body ) {
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
function tconn_external_get_site_origin( string $uri_prefix ): string {
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
function tconn_acquire_mysql_lock( string $name, int $timeout = 2 ): bool {
    global $wpdb;
    return '1' === $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', tconn_mysql_lock_name( $name ), $timeout ) );
}

/**
 * Release a lock previously acquired with tconn_acquire_mysql_lock().
 *
 * @param string $name Lock name passed to tconn_acquire_mysql_lock().
 */
function tconn_release_mysql_lock( string $name ): void {
    global $wpdb;
    $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', tconn_mysql_lock_name( $name ) ) );
}

/**
 * Headers that identify TermConnect to a peer site.
 *
 * X-TermConnect asks a TermConnect peer for its tconn_links field and its peer-only
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
function tconn_external_peer_headers( bool $hypertaxonomy = false, bool $termconnect = true ): array {
    $headers = array();
    if ( $termconnect ) {
        $headers['X-TermConnect'] = TCONN_VERSION;
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
 * @param array  $args wp_remote_get() arguments, usually from tconn_external_request_args().
 * @return array|null Decoded body, or null on any failure.
 */
function tconn_external_get_json( string $url, array $args ): ?array {
    $response = wp_remote_get( $url, $args );
    if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        return null;
    }
    $body = tconn_external_json_decode( wp_remote_retrieve_body( $response ) );
    return is_array( $body ) ? $body : null;
}

/**
 * A date window with both keys present, in a fixed order.
 *
 * A post is in the window when its last update (its publication date if it was
 * never updated) is after `modified_after` and no later than `modified_before`.
 * Either may be '' for no limit on that side.
 *
 * @since 1.0.0
 * @param array $window 'modified_after' and 'modified_before' ISO 8601 dates, each optional.
 * @return array{modified_after: string, modified_before: string}
 */
function tconn_normalize_date_window( array $window ): array {
    return array(
        'modified_after'  => (string) ( $window['modified_after'] ?? '' ),
        'modified_before' => (string) ( $window['modified_before'] ?? '' ),
    );
}

/**
 * Whether the POST carries a valid core term edit or add-term form nonce. Guards
 * tconn_save_term_links(), which runs on saved_term.
 *
 * Term save hooks also fire for programmatic and REST term updates; this
 * confirms the POST data being read came from one of the core term forms.
 *
 * @since 1.0.0
 * @param int $term_id Term being saved (for the edit form's per-term nonce).
 * @return bool True if either form's nonce verifies.
 */
function tconn_verify_term_nonce( int $term_id ): bool {
    if ( isset( $_POST['_wpnonce'] )
        && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-tag_' . $term_id ) ) {
        return true;
    }
    return isset( $_POST['_wpnonce_add-tag'] )
        && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce_add-tag'] ) ), 'add-tag' );
}

/**
 * Add a page-specific inline script through the script API.
 *
 * While the footer scripts are still to come, registers a source-less footer handle
 * 'tconn-{name}-{n}' and attaches $js to it (each call its own handle, so each script
 * prints as its own element). Once they have been printed, e.g. from an
 * admin_footer-{$hook_suffix} callback, prints the script at once with
 * wp_print_inline_script_tag().
 *
 * @since 1.0.0
 *
 * @param string   $name Short name for the handle.
 * @param string   $js   JavaScript code, without the script element tags.
 * @param string[] $deps Script dependencies.
 */
function tconn_add_inline_script( string $name, string $js, array $deps = array( 'jquery' ) ): void {
    static $count = 0;
    if ( did_action( 'admin_print_footer_scripts' ) || did_action( 'wp_print_footer_scripts' ) ) {
        wp_print_inline_script_tag( $js );
        return;
    }
    $handle = 'tconn-' . $name . '-' . ( ++$count );
    wp_register_script( $handle, false, $deps, TCONN_VERSION, array( 'in_footer' => true ) );
    wp_enqueue_script( $handle );
    wp_add_inline_script( $handle, $js );
}

/**
 * Print an inline stylesheet through the style API: a source-less handle,
 * wp_add_inline_style() and wp_print_styles(), at once.
 *
 * @since 1.0.0
 *
 * @param string $handle Style handle.
 * @param string $css    CSS, without the style element tags.
 */
function tconn_print_inline_style( string $handle, string $css ): void {
    wp_register_style( $handle, false, array(), TCONN_VERSION );
    wp_add_inline_style( $handle, wp_strip_all_tags( $css ) );
    wp_print_styles( $handle );
}

/**
 * Decode JSON from a request and sanitize everything in it.
 *
 * String values go through $sanitize_string (default sanitize_text_field()), keys
 * through sanitize_text_field(); numbers, booleans and nulls keep their types. Pass
 * the value through wp_unslash() first. Callers still validate the shape.
 *
 * @since 1.0.0
 *
 * @param mixed         $json            Unslashed JSON text.
 * @param callable|null $sanitize_string Sanitizer for string values: fn( string $value, string $key ): string.
 * @return array|null The sanitized data, or null when $json isn't a JSON object or array.
 */
function tconn_decode_json_request( $json, ?callable $sanitize_string = null ): ?array {
    $data = is_string( $json ) ? tconn_external_json_decode( $json ) : null;
    if ( ! is_array( $data ) ) {
        return null;
    }
    $sanitize_string = $sanitize_string ?? static function ( string $value ): string {
        return sanitize_text_field( $value );
    };
    $walk = static function ( array $data ) use ( &$walk, $sanitize_string ): array {
        $clean = array();
        foreach ( $data as $key => $value ) {
            $key = is_int( $key ) ? $key : sanitize_text_field( (string) $key );
            if ( is_array( $value ) ) {
                $clean[ $key ] = $walk( $value );
            } elseif ( is_string( $value ) ) {
                $clean[ $key ] = $sanitize_string( $value, (string) $key );
            } elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
                $clean[ $key ] = $value;
            }
        }
        return $clean;
    };
    return $walk( $data );
}
