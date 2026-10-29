<?php
/**
 * Feed Platform
 *
 * Handles RSS/Atom feed fetching, category accumulation, post caching,
 * and relevance scoring for link types with platform='feed'. Posts are
 * polled on a WP cron schedule and stored in transients; the
 * [tc_peer_posts] shortcode reads from cache and scores against the
 * local term's mapped category identifiers.
 *
 * Identifier format stored in _tc_link_{slug} term meta for feed types:
 *   - When slug equals label: plain slug string
 *   - Otherwise: JSON {"s":"slug","l":"Human Label"}
 *
 * @package TermConnect
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TC_FEED_CATS_KEY', 'tc_feed_cats_' );
define( 'TC_FEED_POSTS_KEY', 'tc_feed_posts_' );
define( 'TC_FEED_MAX_POSTS_DEFAULT', 500 );
define( 'TC_FEED_POST_TTL', 30 * DAY_IN_SECONDS );
define( 'TC_FEED_CATS_TTL', 90 * DAY_IN_SECONDS );

// =============================================================================
// SSRF VALIDATION
// =============================================================================

/**
 * Validate that a URI prefix uses an allowed scheme and does not point to a loopback,
 * link-local, or RFC-1918 private address. Returns true when valid, false when blocked.
 *
 * Delegates to WordPress's own wp_http_validate_url(), which resolves the host via
 * DNS and checks the *resolved* IP against loopback/link-local/private ranges — not
 * just a textual prefix match on the hostname as written. A hostname that is
 * syntactically public but currently resolves to a private/loopback address (DNS
 * rebinding, or a DNS change made after this URL was approved) is caught on every
 * call, including the recurring cron poll in tc_feed_fetch_and_accumulate(), not
 * just at the moment an admin first saves the URL. This matches the strength of
 * tc_external_validate_origin() (used for live peer-post fetches), which performs
 * the same real-IP-resolution check via the same WordPress function — so both of
 * this plugin's SSRF guards are equally strong. The http_request_host_is_external
 * filter registered in includes/core/external-posts.php still applies here too,
 * preserving the existing local-dev allowance for registered WordPress peer hosts.
 *
 * Defined here (not in admin) so it is available during WP cron feed fetches.
 *
 * @since 1.0.0
 * @param string $uri_prefix URI prefix to validate.
 * @return bool
 */
function tc_validate_uri_prefix( string $uri_prefix ): bool {
	$parsed = wp_parse_url( $uri_prefix );
	$scheme = strtolower( $parsed['scheme'] ?? '' );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return false;
	}

	$host = strtolower( $parsed['host'] ?? '' );
	if ( '' === $host ) {
		return false;
	}

	return false !== wp_http_validate_url( $uri_prefix );
}

// =============================================================================
// IDENTIFIER FORMAT
// =============================================================================

/**
 * Parse a stored feed category identifier into slug and label.
 *
 * @since 1.0.0
 * @param string $raw Stored meta value.
 * @return array { slug: string, label: string }
 */
function tc_feed_parse_identifier( string $raw ): array {
	if ( '' !== $raw && '{' === $raw[0] ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) && isset( $decoded['s'] ) ) {
			return array(
				'slug'  => (string) $decoded['s'],
				'label' => (string) ( $decoded['l'] ?? $decoded['s'] ),
			);
		}
	}
	return array(
		'slug'  => $raw,
		'label' => $raw,
	);
}

/**
 * Return the human-readable display label for a stored feed category identifier.
 *
 * @since 1.0.0
 * @param string $raw Stored meta value.
 * @return string
 */
function tc_feed_display_identifier( string $raw ): string {
	return tc_feed_parse_identifier( $raw )['label'];
}

// =============================================================================
// FEED FETCHING + NORMALIZATION
// =============================================================================

/**
 * Normalize a SimplePie_Item into the standard external post structure.
 *
 * @since 1.0.0
 * @param object $item SimplePie_Item instance.
 * @return array
 */
function tc_feed_normalize_item( $item ): array {
	$categories = array();
	$raw_cats   = $item->get_categories();
	if ( ! is_array( $raw_cats ) ) {
		$raw_cats = array();
	}
	foreach ( $raw_cats as $cat ) {
		$slug  = (string) $cat->get_term();
		$label = (string) $cat->get_label();
		if ( '' === $slug ) {
			continue;
		}
		$categories[] = array(
			'slug'  => $slug,
			'label' => '' !== $label ? $label : $slug,
		);
	}

	$excerpt = (string) $item->get_description();
	if ( '' === $excerpt ) {
		$excerpt = (string) $item->get_content();
	}
	$excerpt = wp_strip_all_tags( $excerpt );
	if ( mb_strlen( $excerpt ) > 300 ) {
		$excerpt = mb_substr( $excerpt, 0, 297 ) . '…';
	}

	return tc_normalize_post_record( array(
		'title'      => wp_strip_all_tags( (string) $item->get_title() ),
		'url'        => (string) $item->get_permalink(),
		'date_gmt'   => (string) $item->get_gmdate( 'Y-m-d\TH:i:s' ),
		'excerpt'    => $excerpt,
		'categories' => $categories, // feed-specific extra; preserved by the normalizer
	) );
}

/**
 * Fetch the feed and merge new items and categories into the accumulated caches.
 *
 * Uses WordPress's fetch_feed() (SimplePie) so feed discovery, HTTP auth,
 * and caching are handled by WordPress. The accumulated caches persist across
 * polls so items that scroll off the feed are not lost.
 *
 * @since 1.0.0
 * @param string $peer_site_slug   Link type slug (used as cache key).
 * @param string $feed_url  Full feed URL.
 * @param int    $max_posts Maximum number of posts to retain; oldest are pruned.
 */
function tc_feed_fetch_and_accumulate( string $peer_site_slug, string $feed_url, int $max_posts = TC_FEED_MAX_POSTS_DEFAULT ): void {
	if ( ! function_exists( 'fetch_feed' ) ) {
		require_once ABSPATH . WPINC . '/feed.php';
	}

	// Validate the feed URL before fetching to prevent SSRF against internal hosts.
	if ( ! function_exists( 'tc_validate_uri_prefix' ) || ! tc_validate_uri_prefix( $feed_url ) ) {
		error_log( 'TermConnect: feed URL blocked by SSRF validation: ' . $feed_url );
		return;
	}

	$feed = fetch_feed( $feed_url );
	if ( is_wp_error( $feed ) ) {
		return;
	}

	$items = $feed->get_items();
	if ( empty( $items ) ) {
		return;
	}

	$cats_key  = TC_FEED_CATS_KEY . $peer_site_slug;
	$posts_key = TC_FEED_POSTS_KEY . $peer_site_slug;

	$cats = get_transient( $cats_key );
	$cats = is_array( $cats ) ? $cats : array();

	$posts_map = get_transient( $posts_key );
	$posts_map = is_array( $posts_map ) ? $posts_map : array();

	foreach ( $items as $item ) {
		$normalized = tc_feed_normalize_item( $item );
		$url        = $normalized['url'];
		if ( '' === $url ) {
			continue;
		}

		foreach ( $normalized['categories'] as $cat ) {
			if ( '' !== $cat['slug'] && ! isset( $cats[ $cat['slug'] ] ) ) {
				$cats[ $cat['slug'] ] = $cat['label'];
			}
		}

		$posts_map[ $url ] = $normalized;
	}

	// Trim to max_posts, keeping the most recent by date.
	if ( count( $posts_map ) > $max_posts ) {
		uasort(
			$posts_map,
			static function ( array $a, array $b ): int {
				return strcmp( $b['date_gmt'], $a['date_gmt'] );
			}
		);
		$posts_map = array_slice( $posts_map, 0, $max_posts, true );
	}

	set_transient( $cats_key, $cats, TC_FEED_CATS_TTL );
	set_transient( $posts_key, $posts_map, TC_FEED_POST_TTL );

	update_option(
		'tc_feed_poll_stat_' . $peer_site_slug,
		array(
			'post_count' => count( $posts_map ),
			'cat_count'  => count( $cats ),
			'time'       => time(),
		),
		false
	);
}

/**
 * Return the accumulated category vocabulary for a feed link type.
 *
 * @since 1.0.0
 * @param string $peer_site_slug Link type slug.
 * @return array [ slug => label ]
 */
function tc_feed_get_categories( string $peer_site_slug ): array {
	$cached = get_transient( TC_FEED_CATS_KEY . $peer_site_slug );
	return is_array( $cached ) ? $cached : array();
}

/**
 * Return the accumulated cached posts for a feed link type as an indexed array.
 *
 * @since 1.0.0
 * @param string $peer_site_slug Link type slug.
 * @return array
 */
function tc_feed_get_cached_posts( string $peer_site_slug ): array {
	$cached = get_transient( TC_FEED_POSTS_KEY . $peer_site_slug );
	return is_array( $cached ) ? array_values( $cached ) : array();
}

/**
 * Delete both the category and post caches for a feed link type.
 *
 * @since 1.0.0
 * @param string $peer_site_slug Link type slug.
 */
function tc_feed_clear_cache( string $peer_site_slug ): void {
	delete_transient( TC_FEED_CATS_KEY . $peer_site_slug );
	delete_transient( TC_FEED_POSTS_KEY . $peer_site_slug );
	delete_option( 'tc_feed_poll_stat_' . $peer_site_slug );
}

// =============================================================================
// SCORING
// =============================================================================

/**
 * Score accumulated feed posts against a set of expected category slugs.
 *
 * Matching is case-insensitive and checks both the slug and label of each
 * feed post category. Posts with no matching category are excluded.
 *
 * @since 1.0.0
 * @param array  $posts           Normalized posts from tc_feed_get_cached_posts().
 * @param array  $category_slugs  Slugs to match (extracted from term meta identifiers).
 * @param float  $weight_direct   Score weight per matched slug.
 * @param string $origin          Feed URL; set as 'origin' on each returned post.
 * @return array Scored posts (score > 0 only), with tc_score and origin set.
 */
function tc_feed_score_posts( array $posts, array $category_slugs, float $weight_direct, string $origin ): array {
	if ( empty( $posts ) || empty( $category_slugs ) ) {
		return array();
	}

	$max_score   = count( $category_slugs ) * $weight_direct;
	$lower_slugs = array_map( 'strtolower', $category_slugs );
	$result      = array();

	foreach ( $posts as $post ) {
		$cats_in_post   = $post['categories'] ?? array();
		$post_slug_set  = array_map( static fn( $c ) => strtolower( $c['slug'] ), $cats_in_post );
		$post_label_set = array_map( static fn( $c ) => strtolower( $c['label'] ), $cats_in_post );

		$raw_score = 0.0;
		foreach ( $lower_slugs as $needle ) {
			if ( in_array( $needle, $post_slug_set, true ) || in_array( $needle, $post_label_set, true ) ) {
				$raw_score += $weight_direct;
			}
		}

		if ( $raw_score <= 0.0 ) {
			continue;
		}

		$post['score'] = $max_score > 0.0 ? min( $raw_score / $max_score, 1.0 ) : 0.0;
		$post['ht_scored']  = true;
		$post['origin']     = $origin;
		$result[]           = $post;
	}

	return $result;
}

// =============================================================================
// WP CRON
// =============================================================================

/**
 * Cron callback: poll a single feed link type and update its caches.
 *
 * @since 1.0.0
 * @param string $peer_site_slug Link type slug.
 */
function tc_feed_do_poll( string $peer_site_slug ): void {
	$type = tc_get_link_type( $peer_site_slug );
	if ( null === $type || ( $type['platform'] ?? '' ) !== 'feed' || empty( $type['enabled'] ) ) {
		return;
	}

	$max_posts = (int) ( $type['max_cached_posts'] ?? TC_FEED_MAX_POSTS_DEFAULT );
	tc_feed_fetch_and_accumulate( $peer_site_slug, $type['uri_prefix'], $max_posts );
}
add_action( 'tc_feed_poll', 'tc_feed_do_poll' );

/**
 * Schedule or reschedule the cron event for a feed link type.
 *
 * Unschedules any existing event first so changing the interval takes effect
 * without leaving stale events behind.
 *
 * @since 1.0.0
 * @param string $peer_site_slug  Link type slug.
 * @param int    $interval Poll interval in seconds (minimum 300).
 */
function tc_feed_schedule( string $peer_site_slug, int $interval ): void {
	$interval = max( 300, min( 86400, $interval ) );
	$hook     = 'tc_feed_poll';
	$args     = array( $peer_site_slug );
	$existing = wp_next_scheduled( $hook, $args );

	if ( $existing ) {
		wp_unschedule_event( $existing, $hook, $args );
	}

	wp_schedule_event( time(), 'tc_' . $interval . 's', $hook, $args );
}

/**
 * Remove the cron event for a feed link type.
 *
 * @since 1.0.0
 * @param string $peer_site_slug Link type slug.
 */
function tc_feed_unschedule( string $peer_site_slug ): void {
	$hook      = 'tc_feed_poll';
	$args      = array( $peer_site_slug );
	$timestamp = wp_next_scheduled( $hook, $args );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook, $args );
	}
}

/**
 * Register custom cron schedules for all configured feed intervals.
 *
 * @since 1.0.0
 * @param array $schedules Existing schedules.
 * @return array
 */
function tc_feed_cron_schedules( array $schedules ): array {
	foreach ( tc_get_all_link_types() as $type ) {
		if ( ( $type['platform'] ?? '' ) !== 'feed' ) {
			continue;
		}
		$interval = max( 300, min( 86400, (int) ( $type['poll_interval'] ?? 3600 ) ) );
		$key      = 'tc_' . $interval . 's';
		if ( ! isset( $schedules[ $key ] ) ) {
			$schedules[ $key ] = array(
				'interval' => $interval,
				/* translators: %d: number of seconds */
				'display'  => sprintf( __( 'Every %d seconds (TermConnect feed)', 'termconnect' ), $interval ),
			);
		}
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'tc_feed_cron_schedules' );
