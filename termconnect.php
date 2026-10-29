<?php
/**
 * Plugin Name: TermConnect
 * Plugin URI:  https://github.com/HyperTaxonomy/termconnect
 * Description: Peer site federation, RSS/Atom feed aggregation, and external knowledge-base linking for WordPress taxonomy terms.
 * Version:     1.0.0
 * Author:      Eugene Weinstein
 * Author URI:  https://hypertaxonomy.org
 * Requires at least: 5.8
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: termconnect
 * Domain Path: /languages
 *
 * @package TermConnect
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TC_PLUGIN_DIR',      plugin_dir_path( __FILE__ ) );
define( 'TC_PLUGIN_URL',      plugin_dir_url( __FILE__ ) );
define( 'TC_VERSION',         '1.0.0' );
define( 'TC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

register_activation_hook( __FILE__, 'tc_plugin_activate' );
register_deactivation_hook( __FILE__, 'tc_plugin_deactivate' );

// Load translations. Hooked on init (not plugins_loaded) per WordPress 6.7+ guidance.
add_action( 'init', static function () {
    load_plugin_textdomain( 'termconnect', false, dirname( TC_PLUGIN_BASENAME ) . '/languages' );
} );

function tc_plugin_activate(): void {
    if ( ! wp_next_scheduled( 'tc_refresh_identity_caches' ) ) {
        wp_schedule_event( time(), 'twicedaily', 'tc_refresh_identity_caches' );
    }
    if ( ! wp_next_scheduled( 'tc_sync_shared_vocabularies' ) ) {
        wp_schedule_event( time(), 'daily', 'tc_sync_shared_vocabularies' );
    }
    tc_activation_purge_stale_sv_queues();
    tc_add_indexes();
}

function tc_add_indexes(): void {
    global $wpdb;
    $wpdb->suppress_errors();
    // Reverse lookup: find all terms linked to a given external identifier.
    $wpdb->query( "ALTER TABLE {$wpdb->termmeta} ADD INDEX tc_link_reverse (meta_key(60), meta_value(64))" );
    $wpdb->show_errors();
}

/**
 * Remove stale entries from SV-related option queues on plugin activation.
 *
 * tc_merge_proposals and tc_sv_misplaced_queue store local term IDs. If terms
 * were deleted while TC was inactive, those queues can contain entries that no
 * longer correspond to real terms.
 *
 * tc_shared_vocabularies 'terms' and 'roots' scope sources store a roots array
 * whose entries each represent one local mirror term. If a mirror term was
 * deleted while TC was inactive, its roots entry must be removed here so the
 * next sync does not recreate it. 'full' and 'group' scopes are dynamic and
 * require no cleanup.
 */
function tc_activation_purge_stale_sv_queues(): void {
    global $wpdb;

    $proposals = get_option( 'tc_merge_proposals', array() );
    if ( ! empty( $proposals ) ) {
        $filtered = array_filter(
            $proposals,
            static function( $p ): bool {
                $orphan_id = (int) ( $p['orphan_term_id']    ?? 0 );
                $cand_id   = (int) ( $p['candidate_term_id'] ?? 0 );
                return ( $orphan_id > 0 && get_term( $orphan_id ) instanceof WP_Term )
                    && ( $cand_id   > 0 && get_term( $cand_id )   instanceof WP_Term );
            }
        );
        if ( count( $filtered ) !== count( $proposals ) ) {
            update_option( 'tc_merge_proposals', $filtered, false );
        }
    }

    $misplaced = get_option( 'tc_sv_misplaced_queue', array() );
    if ( ! empty( $misplaced ) ) {
        $filtered = array_filter(
            $misplaced,
            static function( $entry ): bool {
                $term_id = (int) ( $entry['term_id'] ?? 0 );
                return $term_id > 0 && get_term( $term_id ) instanceof WP_Term;
            }
        );
        if ( count( $filtered ) !== count( $misplaced ) ) {
            update_option( 'tc_sv_misplaced_queue', $filtered, false );
        }
    }

    // Build an index of live delegations from term meta.
    // Uses the literal meta key (_tc_identity_source = TC_META_IDENTITY_SOURCE)
    // because bootstrap.php is not loaded during activation.
    $all_meta   = $wpdb->get_results( $wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s",
        '_tc_identity_source'
    ) );
    $live_index = array(); // [sv_source_id][remote_slug] => true
    foreach ( $all_meta as $row ) {
        $meta = maybe_unserialize( $row->meta_value );
        if ( is_array( $meta ) && ! empty( $meta['sv_source_id'] ) && ! empty( $meta['remote_slug'] ) ) {
            $live_index[ $meta['sv_source_id'] ][ $meta['remote_slug'] ] = true;
        }
    }

    $sources = get_option( 'tc_shared_vocabularies', array() );
    if ( ! empty( $sources ) ) {
        $changed = false;
        foreach ( $sources as &$src ) {
            $scope = $src['scope'] ?? 'full';
            if ( ! in_array( $scope, array( 'terms', 'roots' ), true ) ) {
                continue;
            }
            $sv_id    = $src['id'] ?? '';
            $roots    = $src['roots'] ?? array();
            $filtered = array_values( array_filter(
                $roots,
                static fn( $r ) => isset( $live_index[ $sv_id ][ $r['remote_slug'] ?? '' ] )
            ) );
            if ( count( $filtered ) !== count( $roots ) ) {
                $src['roots'] = $filtered;
                $changed      = true;
            }
        }
        unset( $src );
        if ( $changed ) {
            // Remove 'terms' sources that have become empty.
            $sources = array_values( array_filter(
                $sources,
                static fn( $s ) => ! ( ( $s['scope'] ?? '' ) === 'terms' && empty( $s['roots'] ) )
            ) );
            update_option( 'tc_shared_vocabularies', $sources );
        }
    }
}

function tc_plugin_deactivate(): void {
    $timestamp = wp_next_scheduled( 'tc_refresh_identity_caches' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'tc_refresh_identity_caches' );
    }
    $timestamp = wp_next_scheduled( 'tc_sync_shared_vocabularies' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'tc_sync_shared_vocabularies' );
    }
    // Per-feed-link-type tc_feed_poll events (one per slug, scheduled by
    // tc_feed_schedule()) are not covered by the two unschedules above —
    // without this, they linger in the cron option across a
    // deactivate/reactivate cycle. Matches the same call already in
    // uninstall.php.
    wp_unschedule_hook( 'tc_feed_poll' );
}

add_action( 'plugins_loaded', static function () {
    require_once TC_PLUGIN_DIR . 'includes/bootstrap.php';
}, 20 );
