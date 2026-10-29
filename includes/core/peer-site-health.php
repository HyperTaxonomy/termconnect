<?php
/**
 * Peer Site Health Tracking
 *
 * Tracks reachability of WordPress peer sites (platform=wordpress link types).
 * Failed fetches are counted per link-type slug; sustained failures back off
 * polling frequency. State is stored in a single WordPress option so it survives
 * object-cache flushes.
 *
 * Status tiers:
 *   ok          — zero to (DEGRADED_THRESHOLD - 1) consecutive failures; normal TTL.
 *   degraded    — DEGRADED_THRESHOLD+ failures; DEGRADED_MULTIPLIER × normal TTL backoff.
 *   unreachable — UNREACHABLE_THRESHOLD+ failures; UNREACHABLE_TTL fixed backoff.
 *
 * @package TermConnect
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Failure count at which backoff begins.
const TC_PEER_DEGRADED_THRESHOLD    = 3;
// Failure count at which the site is considered unreachable.
const TC_PEER_UNREACHABLE_THRESHOLD = 10;
// TTL multiplier applied when degraded.
const TC_PEER_DEGRADED_MULTIPLIER   = 4;
// Fixed backoff TTL (seconds) when unreachable.
const TC_PEER_UNREACHABLE_TTL       = 86400;
// Minimum seconds between persisted option writes for failures that don't
// change status (see tc_peer_site_health_record_failure()).
const TC_PEER_HEALTH_MIN_WRITE_INTERVAL = 60;

// ==============================================================================
// STATE ACCESSORS
// ==============================================================================

/**
 * Return the full health state map keyed by link-type slug.
 *
 * @since 1.0.0
 * @return array
 */
function tc_peer_site_health_get_all(): array {
    return (array) get_option( 'tc_peer_site_health', array() );
}

/**
 * Return the health state for a single peer site by link-type slug.
 *
 * @since 1.0.0
 * @param string $slug Link-type slug.
 * @return array { status, consecutive_failures, last_failure, last_success, last_error, next_allowed }
 */
function tc_peer_site_health_get( string $slug ): array {
    $all = tc_peer_site_health_get_all();
    return $all[ $slug ] ?? array(
        'status'               => 'ok',
        'consecutive_failures' => 0,
        'last_failure'         => 0,
        'last_success'         => 0,
        'last_error'           => '',
        'next_allowed'         => 0,
    );
}

/**
 * Persist updated health state for one peer site.
 *
 * @since 1.0.0
 * @param string $slug  Link-type slug.
 * @param array  $state Updated state array.
 */
function tc_peer_site_health_save( string $slug, array $state ): void {
    $all          = tc_peer_site_health_get_all();
    $all[ $slug ] = $state;
    update_option( 'tc_peer_site_health', $all, false );
}

// ==============================================================================
// RECORDING
// ==============================================================================

/**
 * Record a successful fetch from a peer site.
 *
 * Resets consecutive failure count and removes backoff. No-op when the site
 * is already healthy, to avoid unnecessary option writes on every request.
 *
 * @since 1.0.0
 * @param string $slug Link-type slug.
 */
function tc_peer_site_health_record_success( string $slug ): void {
    if ( '' === $slug ) {
        return;
    }
    $state = tc_peer_site_health_get( $slug );
    if ( 'ok' === $state['status'] && 0 === $state['consecutive_failures'] ) {
        return;
    }
    tc_peer_site_health_save( $slug, array(
        'status'               => 'ok',
        'consecutive_failures' => 0,
        'last_failure'         => $state['last_failure'],
        'last_success'         => time(),
        'last_error'           => '',
        'next_allowed'         => 0,
    ) );
}

/**
 * Record a failed fetch from a peer site and apply backoff.
 *
 * Below DEGRADED_THRESHOLD the failure is tracked silently (status stays 'ok',
 * no backoff) so single transient errors do not disrupt fetching. At
 * DEGRADED_THRESHOLD the backoff window grows to DEGRADED_MULTIPLIER × normal_ttl.
 * At UNREACHABLE_THRESHOLD it switches to a fixed UNREACHABLE_TTL.
 *
 * The option write itself is throttled while status stays 'ok': nothing
 * functionally depends on persisting every single failure immediately in that
 * case (no backoff change happens), so writes below the degraded threshold are
 * skipped unless at least TC_PEER_HEALTH_MIN_WRITE_INTERVAL seconds have passed
 * since the last persisted failure — avoiding a DB write on every failed
 * request from a chronically flaky peer under regular traffic. A throttled
 * call is skipped entirely rather than deferred: the next failure's
 * read-then-increment against the still-stale option naturally catches up the
 * count, so a flaky peer still reaches DEGRADED/UNREACHABLE at roughly the
 * same time — just with a slightly coarser count during the throttled window,
 * and always written immediately on any actual status change.
 *
 * @since 1.0.0
 * @param string $slug       Link-type slug.
 * @param string $error      Short error description (HTTP status or WP_Error message).
 * @param int    $normal_ttl Normal cache TTL in seconds, used to compute backoff window.
 */
function tc_peer_site_health_record_failure( string $slug, string $error, int $normal_ttl = 3600 ): void {
    if ( '' === $slug ) {
        return;
    }
    $state             = tc_peer_site_health_get( $slug );
    $prev_status       = $state['status'];
    $prev_last_failure = $state['last_failure'];

    $state['consecutive_failures'] += 1;
    $state['last_failure']          = time();
    $state['last_error']            = $error;

    if ( $state['consecutive_failures'] >= TC_PEER_UNREACHABLE_THRESHOLD ) {
        $state['status']       = 'unreachable';
        $state['next_allowed'] = time() + TC_PEER_UNREACHABLE_TTL;
    } elseif ( $state['consecutive_failures'] >= TC_PEER_DEGRADED_THRESHOLD ) {
        $state['status']       = 'degraded';
        $state['next_allowed'] = time() + ( $normal_ttl * TC_PEER_DEGRADED_MULTIPLIER );
    } else {
        // Transient failure — track count, no backoff yet.
        $state['status']       = 'ok';
        $state['next_allowed'] = 0;
    }

    $should_write = $state['status'] !== $prev_status
        || 'ok' !== $state['status']
        || ( time() - (int) $prev_last_failure ) >= TC_PEER_HEALTH_MIN_WRITE_INTERVAL;

    if ( $should_write ) {
        tc_peer_site_health_save( $slug, $state );
    }
}

// ==============================================================================
// BACKOFF CHECK
// ==============================================================================

/**
 * Return true when the peer site is in a backoff window and should not be fetched.
 *
 * @since 1.0.0
 * @param string $slug Link-type slug.
 * @return bool
 */
function tc_peer_site_health_is_blocked( string $slug ): bool {
    if ( '' === $slug ) {
        return false;
    }
    $state = tc_peer_site_health_get( $slug );
    return $state['next_allowed'] > 0 && time() < $state['next_allowed'];
}

// ==============================================================================
// REPORTING
// ==============================================================================

/**
 * Return health state for all peer sites that are not fully healthy.
 *
 * Each entry is the state array augmented with a 'label' key from the link type
 * definition. Keyed by slug.
 *
 * @since 1.0.0
 * @return array
 */
function tc_peer_site_health_get_unhealthy(): array {
    $unhealthy = array();
    foreach ( tc_peer_site_health_get_all() as $slug => $state ) {
        if ( 'ok' !== ( $state['status'] ?? 'ok' ) ) {
            $type               = tc_get_link_type( $slug );
            $state['label']     = $type ? ( $type['label'] ?? $slug ) : $slug;
            $unhealthy[ $slug ] = $state;
        }
    }
    return $unhealthy;
}

// ==============================================================================
// WORDPRESS SITE HEALTH INTEGRATION
// ==============================================================================

add_filter( 'site_status_tests', function ( array $tests ): array {
    $tests['direct']['tc_peer_sites'] = array(
        'label' => __( 'TermConnect peer sites', 'termconnect' ),
        'test'  => 'tc_peer_site_health_check',
    );
    return $tests;
} );

/**
 * WordPress Site Health test callback for peer site reachability.
 *
 * @since 1.0.0
 * @return array Site Health result array.
 */
function tc_peer_site_health_check(): array {
    $unhealthy = tc_peer_site_health_get_unhealthy();

    if ( empty( $unhealthy ) ) {
        return array(
            'label'       => __( 'All TermConnect peer sites are reachable', 'termconnect' ),
            'status'      => 'good',
            'badge'       => array( 'label' => __( 'TermConnect', 'termconnect' ), 'color' => 'blue' ),
            'description' => '',
            'test'        => 'tc_peer_sites',
        );
    }

    $rows = array();
    foreach ( $unhealthy as $slug => $state ) {
        $since = $state['last_failure'] ? human_time_diff( $state['last_failure'] ) . ' ago' : 'unknown';
        $rows[] = sprintf(
            '<li><strong>%s</strong> — %s (%s, last error: %s)</li>',
            esc_html( $state['label'] ),
            esc_html( $state['status'] ),
            esc_html( $since ),
            esc_html( $state['last_error'] ?: 'unknown' )
        );
    }

    return array(
        'label'       => sprintf(
            _n(
                '%d TermConnect peer site is unreachable or degraded',
                '%d TermConnect peer sites are unreachable or degraded',
                count( $unhealthy ),
                'termconnect'
            ),
            count( $unhealthy )
        ),
        'status'      => 'recommended',
        'badge'       => array( 'label' => __( 'TermConnect', 'termconnect' ), 'color' => 'orange' ),
        'description' => '<ul>' . implode( '', $rows ) . '</ul>',
        'test'        => 'tc_peer_sites',
    );
}
