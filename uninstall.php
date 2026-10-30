<?php
/**
 * Uninstall script for TermConnect.
 *
 * Plugin settings, cron jobs, and transients are always removed.
 * Term link meta and visitor link suggestions are only removed when the user has
 * explicitly enabled "Delete all data on uninstall" in Settings → TermConnect.
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function tc_uninstall_cleanup() {
	global $wpdb;

	$settings    = get_option( 'tc_settings', array() );
	$delete_data = ! empty( $settings['delete_on_uninstall'] );

	// Always remove plugin options.
	delete_option( 'tc_settings' );
	delete_option( 'tc_enabled_taxonomies' );
	delete_option( 'tc_link_types' );
	delete_option( 'tc_peer_site_health' );

	// Per-link-type options stored with dynamic suffixes.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tc\_ext\_ltfetch\_%'" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tc\_ext\_ltver\_%'" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tc\_feed\_poll\_stat\_%'" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tc\_ph\_tally\_%'" );

	// Always clear scheduled events.
	wp_unschedule_hook( 'tc_feed_poll' );
	wp_unschedule_hook( 'tc_refresh_identity_caches' );

	// Always remove the delegated identity redirect review queue.
	delete_option( 'tc_identity_redirect_queue' );

	// Always remove shared vocabulary source configs and mirror queues.
	delete_option( 'tc_shared_vocabularies' );
	delete_option( 'tc_sv_misplaced_queue' );
	delete_option( 'tc_merge_proposals' );
	wp_unschedule_hook( 'tc_sync_shared_vocabularies' );

	// Always remove all TC transients (external post cache, peer lookups, feed categories,
	// per-user admin error notices tc_lt_error_{user}, suggestion rate limits).
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\_transient\_tc\_%'
		    OR option_name LIKE '\_transient\_timeout\_tc\_%'"
	);

	// Always remove indexes added to WordPress core tables.
	$exists = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
			 WHERE table_schema = DATABASE()
			 AND table_name = %s AND index_name = %s',
			$wpdb->termmeta,
			'tc_link_reverse'
		)
	);
	if ( $exists ) {
		$wpdb->query( 'ALTER TABLE `' . esc_sql( $wpdb->termmeta ) . '` DROP INDEX `tc_link_reverse`' );
	}

	// Only remove term data if the user opted in.
	if ( $delete_data ) {
		$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE '\_tc\_link\_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = '_tc_identity_source'" );
		$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = '_tc_delegated_links_data'" );
		$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = '_tc_uris'" );
		$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = '_tc_original_description'" );
		$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = '_tc_original_name'" );
		$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = '_tc_rel_sync_issues'" );

		// Visitor link suggestions, stored as comments (comment_type tc_suggested_link),
		// with their comment meta (the _tc_term_id term reference, plus any meta WordPress
		// added, e.g. trash status).
		$wpdb->query(
			"DELETE cm FROM {$wpdb->commentmeta} cm
			 JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
			 WHERE c.comment_type = 'tc_suggested_link'"
		);
		$wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_type = 'tc_suggested_link'" );
	}
}

if ( is_multisite() ) {
	$sites = get_sites( array( 'number' => 0 ) );
	foreach ( $sites as $site ) {
		switch_to_blog( $site->blog_id );
		tc_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	tc_uninstall_cleanup();
}

wp_cache_flush();
