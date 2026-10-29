<?php
/**
 * TermConnect bootstrap — loads all plugin files.
 *
 * @package TermConnect
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Core
require_once TC_PLUGIN_DIR . 'includes/core/term-links.php';
require_once TC_PLUGIN_DIR . 'includes/core/feed-platform.php';
require_once TC_PLUGIN_DIR . 'includes/core/peer-site-health.php';
require_once TC_PLUGIN_DIR . 'includes/core/external-posts.php';
require_once TC_PLUGIN_DIR . 'includes/core/display.php';
require_once TC_PLUGIN_DIR . 'includes/core/rest-api.php';
require_once TC_PLUGIN_DIR . 'includes/core/delegated-identity.php';
require_once TC_PLUGIN_DIR . 'includes/core/suggested-links.php';

// Settings class needed in all contexts (frontend, cron, REST) for get_enabled_taxonomies().
require_once TC_PLUGIN_DIR . 'includes/admin/settings.php';

// Vocabulary sync — needed in admin, AJAX, and cron contexts (not on frontend page requests).
if ( is_admin() || wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
    require_once TC_PLUGIN_DIR . 'includes/admin/shared-vocabulary-sync.php';
}

// Admin
if ( is_admin() || wp_doing_ajax() ) {
    require_once TC_PLUGIN_DIR . 'includes/admin/class-admin.php';
    require_once TC_PLUGIN_DIR . 'includes/admin/peer-sites-admin.php';
    require_once TC_PLUGIN_DIR . 'includes/admin/term-edit.php';
    require_once TC_PLUGIN_DIR . 'includes/admin/delegated-identity-edit.php';
    require_once TC_PLUGIN_DIR . 'includes/admin/delegated-identity-ajax.php';
    require_once TC_PLUGIN_DIR . 'includes/admin/delegated-identity-health.php';
    require_once TC_PLUGIN_DIR . 'includes/admin/shared-vocabularies.php';
    require_once TC_PLUGIN_DIR . 'includes/admin/shared-vocabularies-ajax.php';
    require_once TC_PLUGIN_DIR . 'includes/admin/term-list.php';
    require_once TC_PLUGIN_DIR . 'includes/admin/export-import.php';
    require_once TC_PLUGIN_DIR . 'includes/admin/suggested-links-admin.php';
    TC_Admin::register();
}

// Integrations
require_once TC_PLUGIN_DIR . 'includes/integration/class-hypertaxonomy-integration.php';
TC_HT_Integration::init();

// Shortcodes
require_once TC_PLUGIN_DIR . 'includes/shortcodes/class-external-post-list.php';
require_once TC_PLUGIN_DIR . 'includes/shortcodes/class-peer-browse.php';
