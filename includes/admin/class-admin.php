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

class TC_Admin {

    public static function register(): void {
        add_action( 'admin_menu', array( __CLASS__, 'register_menus' ), 15 );
        add_filter( 'plugin_action_links_' . TC_PLUGIN_BASENAME, array( __CLASS__, 'add_settings_link' ) );
    }

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
            'tc-terms',
            '__return_empty_string',
            'dashicons-tag',
            6
        );
        remove_submenu_page( 'tc-terms', 'tc-terms' );
        return 'tc-terms';
    }

    public static function register_menus(): void {
        $parent = self::get_terms_menu_parent();

        // Operational page: term link type definitions live under the Terms menu.
        add_submenu_page(
            $parent,
            __( 'Peer Sites', 'termconnect' ),
            __( 'TermConnect Peer Sites', 'termconnect' ),
            'manage_options',
            'tc-peer-sites',
            'tc_render_peer_sites_page'
        );

        add_submenu_page(
            $parent,
            __( 'Shared Vocabularies', 'termconnect' ),
            __( 'Shared Vocabularies', 'termconnect' ),
            'manage_options',
            'tc-shared-vocabularies',
            'tc_sv_render_page'
        );

        // Plugin settings live under Settings — the standard WP convention.
        add_options_page(
            __( 'TermConnect', 'termconnect' ),
            __( 'TermConnect', 'termconnect' ),
            'manage_options',
            'termconnect-settings',
            array( 'TC_Settings', 'render' )
        );
    }
}
