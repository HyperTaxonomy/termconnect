<?php
/**
 * TermConnect settings needed in every context (front end, cron, REST).
 *
 * The settings screen is TC_Settings_Page in includes/admin/settings.php.
 *
 * @package TermConnect
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Read access to TermConnect settings.
 */
class TC_Settings {

    /**
     * Taxonomies TermConnect is enabled for, after the tc_enabled_taxonomies filter.
     *
     * @since 1.0.0
     */
    public static function get_enabled_taxonomies(): array {
        $taxonomies = (array) get_option( 'tc_enabled_taxonomies', array() );

        /**
         * Filters the list of TermConnect-enabled taxonomies.
         *
         * Lets a companion plugin opt its taxonomies into TermConnect behavior
         * (the tc_links REST field, the htax_term_uri matching parameter, peer
         * term resolution by shared identifier, and "See also") without writing
         * to the tc_enabled_taxonomies option. The result is normalized to a
         * unique list of taxonomy slug strings.
         *
         * @since 1.0.0
         * @param string[] $taxonomies Enabled taxonomy slugs.
         */
        $taxonomies = (array) apply_filters( 'tc_enabled_taxonomies', $taxonomies );

        return array_values( array_unique( array_filter( array_map( 'strval', $taxonomies ) ) ) );
    }
}
