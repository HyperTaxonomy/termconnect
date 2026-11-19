<?php
/**
 * TermConnect admin — menu registration and admin-level hooks.
 *
 * Runs at admin_menu priority 15 so it fires after HyperTaxonomy registers htax-terms
 * (priority 10) and alongside ATCF's menu registration (also priority 15,
 * registered earlier in alphabetical plugin load order).
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TConn_Admin {

    /**
     * Hook the admin menus and the plugin's Settings link.
     *
     * @since 1.0.0
     */
    public static function register(): void {
        add_action( 'admin_menu', array( __CLASS__, 'register_menus' ), 15 );
        add_filter( 'plugin_action_links_' . TCONN_PLUGIN_BASENAME, array( __CLASS__, 'add_settings_link' ) );
    }

    /**
     * Add a Settings link to the plugin's row on the Plugins screen.
     *
     * @since 1.0.0
     */
    public static function add_settings_link( array $links ): array {
        $settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=termconnect-settings' ) ) . '">'
            . esc_html__( 'Settings', 'termconnect' )
            . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Detect the existing Terms top-level menu (from HyperTaxonomy or ATCF) or create one.
     * Safe to call only during admin_menu at priority ≥ 15.
     *
     * @return string Parent menu slug.
     */
    public static function get_terms_menu_parent(): string {
        global $menu;
        foreach ( (array) $menu as $item ) {
            if ( isset( $item[2] ) && in_array( $item[2], array( 'htax-terms', 'atcf-terms' ), true ) ) {
                return $item[2];
            }
        }
        // Shared "Terms" parent capability is standardized across the suite (HT, TC,
        // ATCF) at manage_options; each plugin's own submenus keep their own caps, and
        // WordPress still surfaces the parent for any user who can reach a submenu.
        add_menu_page(
            __( 'Terms', 'termconnect' ),
            __( 'Terms', 'termconnect' ),
            'manage_options',
            'tconn-terms',
            '__return_empty_string',
            'dashicons-tag',
            6
        );
        remove_submenu_page( 'tconn-terms', 'tconn-terms' );
        return 'tconn-terms';
    }

    /**
     * Add the Peer Sites and Shared Vocabularies pages under the Terms menu, and Settings → TermConnect.
     *
     * @since 1.0.0
     */
    public static function register_menus(): void {
        $parent = self::get_terms_menu_parent();

        // Operational page: term link type definitions live under the Terms menu.
        add_submenu_page(
            $parent,
            __( 'Peer Sites', 'termconnect' ),
            __( 'TermConnect Peer Sites', 'termconnect' ),
            'manage_options',
            'tconn-peer-sites',
            'tconn_render_peer_sites_page'
        );

        add_submenu_page(
            $parent,
            __( 'Shared Vocabularies', 'termconnect' ),
            __( 'Shared Vocabularies', 'termconnect' ),
            'manage_options',
            'tconn-shared-vocabularies',
            'tconn_sv_render_page'
        );

        // Plugin settings live under Settings — the standard WP convention.
        add_options_page(
            __( 'TermConnect', 'termconnect' ),
            __( 'TermConnect', 'termconnect' ),
            'manage_options',
            'termconnect-settings',
            array( 'TConn_Settings_Page', 'render' )
        );
    }
}

/**
 * Justify the help text on this plugin's admin pages, such as the paragraphs above tables and
 * forms (including those in a table cell directly above a nested table), without splitting words.
 * Other field descriptions in table cells, and notices, are left as they are.
 *
 * @since 1.0.0
 */
function tconn_admin_justify_help_text() {
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( in_array( $page, array( 'tconn-peer-sites', 'tconn-shared-vocabularies', 'termconnect-settings' ), true ) ) {
        tconn_print_inline_style( 'tconn-admin-help-text', '.wrap p:not(td p):not(th p):not(.notice p), .wrap p:has(+ table) { text-align: justify; hyphens: none; }' );
    }
}
add_action( 'admin_head', 'tconn_admin_justify_help_text' );
