<?php
/**
 * Uninstall script for TermConnect.
 *
 * Plugin settings, cron jobs, and transients are always removed.
 * Link types, enabled taxonomies, Shared Vocabulary sources, term link meta and visitor
 * link suggestions are only removed when the user has explicitly enabled "Delete all data"
 * in Settings → TermConnect. Term data depends on those definitions, so they stay together
 * and a reinstall works as before.
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove TermConnect's options, caches, cron events and index, and its data when Delete data on uninstall is on.
 *
 * @since 1.0.0
 */
function tconn_uninstall_cleanup() {
	global $wpdb;

	$settings    = get_option( 'tconn_settings', array() );
	$delete_data = ! empty( $settings['delete_on_uninstall'] );

	// Always remove plugin options.
	delete_option( 'tconn_settings' );
	delete_option( 'tconn_peer_site_health' );
	delete_option( 'tconn_feed_reschedule' );

	// Per-link-type options stored with dynamic suffixes.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tc\_ext\_ltfetch\_%'" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tc\_ext\_ltver\_%'" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tc\_feed\_poll\_stat\_%'" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tc\_ph\_tally\_%'" );

	// Always clear scheduled events.
	wp_unschedule_hook( 'tconn_feed_poll' );
	wp_unschedule_hook( 'tconn_refresh_identity_caches' );

	// Always remove the delegated identity redirect review queue.
	delete_option( 'tconn_identity_redirect_queue' );

	// Always remove shared vocabulary review queues.
	delete_option( 'tconn_sv_misplaced_queue' );
	delete_option( 'tconn_merge_proposals' );
	wp_unschedule_hook( 'tconn_sync_shared_vocabularies' );

	// Always remove all TC transients (external post cache, peer lookups, feed categories,
	// per-user admin error notices tconn_lt_error_{user}, suggestion rate limits).
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\_transient\_tconn\_%'
		    OR option_name LIKE '\_transient\_timeout\_tconn\_%'"
	);

	// Always remove indexes added to WordPress core tables.
	$exists = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
			 WHERE table_schema = DATABASE()
			 AND table_name = %s AND index_name = %s',
			$wpdb->termmeta,
			'tconn_link_reverse'
		)
	);
	if ( $exists ) {
		$wpdb->query( 'ALTER TABLE `' . esc_sql( $wpdb->termmeta ) . '` DROP INDEX `tconn_link_reverse`' );
	}

	// Only remove term data if the user opted in.
	if ( $delete_data ) {
		delete_option( 'tconn_enabled_taxonomies' );
		delete_option( 'tconn_link_types' );
		delete_option( 'tconn_shared_vocabularies' );

		$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE '\_tconn\_link\_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = '_tconn_identity_source'" );
		$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = '_tconn_delegated_links_data'" );
		$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = '_tconn_uris'" );
		$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = '_tconn_original_description'" );
		$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = '_tconn_original_name'" );
		$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = '_tconn_rel_sync_issues'" );

		// Visitor link suggestions, stored as comments (comment_type tconn_suggested_link),
		// with their comment meta (the _tconn_term_id term reference, plus any meta WordPress
		// added, e.g. trash status).
		$wpdb->query(
			"DELETE cm FROM {$wpdb->commentmeta} cm
			 JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
			 WHERE c.comment_type = 'tconn_suggested_link'"
		);
		$wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_type = 'tconn_suggested_link'" );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'number' => 0, 'fields' => 'ids' ) ) as $tconn_site_id ) {
		switch_to_blog( $tconn_site_id );
		tconn_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	tconn_uninstall_cleanup();
}

wp_cache_flush();
