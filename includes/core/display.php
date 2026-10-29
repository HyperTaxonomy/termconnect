<?php
/**
 * Frontend display — "See also" section on term archives and Gutenberg block render callbacks.
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// When HyperTaxonomy is active and handling the description block, append see-also
// links inside .htax-archive-description via HT's dedicated filter.
add_filter( 'htax_archive_description_append', static function ( string $append, WP_Term $term ): string {
    if ( ! in_array( $term->taxonomy, TC_Settings::get_enabled_taxonomies(), true ) ) {
        return $append;
    }
    $html = tc_get_term_links_see_also_html( $term );
    return '' === $html ? $append : $append . $html;
}, 10, 2 );

// Append "See also" links to the native WordPress archive description for sites
// that don't use HyperTaxonomy's description block.
add_filter( 'get_the_archive_description', static function ( $description ) {
    global $htax_handling_archive_description;
    if ( ! empty( $htax_handling_archive_description ) ) {
        return $description;
    }
    if ( ! is_tax() && ! is_category() && ! is_tag() ) {
        return $description;
    }
    $term = get_queried_object();
    if ( ! ( $term instanceof WP_Term ) ) {
        return $description;
    }
    if ( ! in_array( $term->taxonomy, TC_Settings::get_enabled_taxonomies(), true ) ) {
        return $description;
    }
    $html = tc_get_term_links_see_also_html( $term );
    return '' === $html ? $description : $description . $html;
} );

// Register Gutenberg block types; render callbacks are defined below.
add_action( 'init', static function () {
    $blocks = array(
        'peer-posts'  => 'tc_render_peer_posts_block',
        'peer-browse' => 'tc_render_peer_browse_block',
    );

    // The peer-picker checklist reads the configured peers from window.TCPeers,
    // localized onto each block's editor script — no runtime REST round-trip.
    $peers = tc_get_browsable_peers();

    foreach ( $blocks as $slug => $callback ) {
        $dir = TC_PLUGIN_DIR . 'blocks/' . $slug;
        if ( ! file_exists( $dir . '/block.json' ) ) {
            continue;
        }
        $type = register_block_type( $dir, array( 'render_callback' => $callback ) );
        if ( $type instanceof WP_Block_Type ) {
            foreach ( (array) $type->editor_script_handles as $handle ) {
                wp_localize_script( $handle, 'TCPeers', $peers );
            }
        }
    }

    // Point each block's editor-script translations at the plugin's bundled
    // languages/ directory. Because block.json declares a textdomain, core
    // already calls wp_set_script_translations() without a path — which only
    // resolves wp-content/languages/. Re-registering with the explicit path
    // makes the bundled <domain>-<locale>-<handle>.json files load.
    $registry = WP_Block_Type_Registry::get_instance();
    foreach ( $registry->get_all_registered() as $block_type ) {
        if ( strpos( (string) $block_type->name, 'termconnect/' ) !== 0 ) {
            continue;
        }
        foreach ( (array) $block_type->editor_script_handles as $handle ) {
            wp_set_script_translations( $handle, 'termconnect', TC_PLUGIN_DIR . 'languages' );
        }
    }
} );

add_filter( 'block_categories_all', static function ( array $categories ): array {
    return array_merge(
        $categories,
        array(
            array(
                'slug'  => 'termconnect',
                'title' => __( 'TermConnect', 'termconnect' ),
            ),
        )
    );
} );

/**
 * Build the "See also" HTML for a term's external links.
 *
 * Includes both locally-configured links and delegated links, with override logic
 * applied via tc_build_merged_links(). Local link types with show_on_archives = false
 * are excluded. Delegated-only links (no matching local type) are included unless
 * their platform is 'feed'.
 *
 * Returns empty string when:
 *  - "Show See also" is disabled in TermConnect settings
 *  - HyperTaxonomy is active but "Customize archives" is not enabled for this taxonomy
 *  - No eligible links are found for this term
 *
 * @since 1.0.0
 * @param WP_Term $term The queried term.
 * @return string HTML fragment or empty string.
 */
function tc_get_term_links_see_also_html( WP_Term $term ): string {
    $options = get_option( 'tc_settings', array() );

    if ( isset( $options['link_types_show_see_also'] ) && ! $options['link_types_show_see_also'] ) {
        return '';
    }

    // When HyperTaxonomy is active, respect its "Customize archives" feature flag.
    if ( function_exists( 'htax_taxonomy_has_feature' )
        && ! htax_taxonomy_has_feature( $term->taxonomy, 'customize_archives' )
    ) {
        return '';
    }

    $merged     = tc_build_merged_links( $term->term_id );
    $link_types = tc_get_enabled_link_types();
    $items      = array();

    foreach ( $merged as $entry ) {
        $url = $entry['url'];

        // Find a local link type whose uri_prefix matches this URL.
        $matched_type = null;
        foreach ( $link_types as $slug => $type ) {
            $prefix = $type['uri_prefix'] ?? '';
            if ( '' !== $prefix && 0 === strpos( $url, $prefix ) ) {
                $matched_type = $type;
                break;
            }
        }

        if ( null !== $matched_type ) {
            // Known local type: respect show_on_archives flag.
            if ( empty( $matched_type['show_on_archives'] ) ) {
                continue;
            }
        } else {
            // Delegated-only entry (remote type not configured locally): exclude feed platform.
            if ( 'feed' === ( $entry['platform'] ?? '' ) ) {
                continue;
            }
        }

        $type_label    = $matched_type ? (string) ( $matched_type['label'] ?? '' ) : '';
        $display_label = $entry['label'];

        if ( $display_label === $url ) {
            // No human label stored — use identifier extracted from URL.
            if ( null !== $matched_type ) {
                $prefix = $matched_type['uri_prefix'] ?? '';
                if ( '' !== $prefix && 0 === strpos( $url, $prefix ) ) {
                    $display_label = substr( $url, strlen( $prefix ) );
                }
            }
        } else {
            // Stored format is "TypeLabel: HumanName (slug)" — strip the type prefix
            // when the local type is known, then always strip the parenthesised slug suffix.
            if ( '' !== $type_label ) {
                $type_prefix = $type_label . ': ';
                if ( 0 === strpos( $display_label, $type_prefix ) ) {
                    $display_label = substr( $display_label, strlen( $type_prefix ) );
                }
            }
            $display_label = trim( (string) preg_replace( '/\s*\([^)]+\)$/', '', $display_label ) );
        }

        $items[] = array(
            'url'        => $url,
            'label'      => $display_label,
            'type_label' => $type_label,
        );
    }

    if ( empty( $items ) ) {
        return '';
    }

    $format = $options['see_also_format'] ?? 'inline';

    if ( 'list' === $format ) {
        $li_html = '';
        foreach ( $items as $item ) {
            $text     = '' !== $item['type_label'] ? $item['type_label'] . ': ' . $item['label'] : $item['label'];
            $li_html .= '<li><a href="' . esc_url( $item['url'] ) . '" rel="noopener noreferrer" target="_blank">'
                . esc_html( $text ) . '</a></li>';
        }
        $see_also_html = '<ul class="tc-term-links-see-also">' . $li_html . '</ul>';
    } else {
        $anchors = array();
        foreach ( $items as $item ) {
            $text      = '' !== $item['type_label'] ? $item['type_label'] . ': ' . $item['label'] : $item['label'];
            $anchors[] = '<a href="' . esc_url( $item['url'] ) . '" rel="noopener noreferrer" target="_blank">'
                . esc_html( $text ) . '</a>';
        }
        $label = $options['see_also_label'] ?? __( 'See also:', 'termconnect' );
        $see_also_html = '<p class="tc-term-links-see-also">'
            . ( '' !== $label ? esc_html( trim( $label ) ) . ' ' : '' )
            . implode( ' &middot; ', $anchors )
            . '</p>';
    }

    return apply_filters( 'tc_term_links_see_also_html', $see_also_html, $term, $items );
}

/**
 * Build tc_links REST field data for a term.
 *
 * Returns a flat array of { url, platform, label, fetched_at } objects covering local
 * and delegated links with override logic applied, or null when the term has no links.
 * Used by the tc_term_peers and tc_term_data REST fields on post endpoints.
 *
 * @since 1.0.0
 * @param WP_Term $term Term object.
 * @return array[]|null
 */
function tc_rest_build_links_data( WP_Term $term ): ?array {
    $options = get_option( 'tc_settings', array() );
    if ( isset( $options['expose_links_api'] ) && ! $options['expose_links_api'] ) {
        return null;
    }

    $links = tc_build_merged_links( $term->term_id );

    return empty( $links ) ? null : $links;
}

/**
 * Block render callback for termconnect/peer-posts.
 *
 * @param array    $attributes Block attributes.
 * @param string   $content    Inner block content (unused).
 * @param WP_Block $block      Block instance providing postId context.
 * @return string Rendered HTML.
 */
function tc_render_peer_posts_block( $attributes, $content, $block ): string {
    $post_id = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : get_the_ID();
    if ( ! $post_id ) {
        return '';
    }

    global $post;
    $saved_post = $post;
    $post       = get_post( $post_id );
    if ( ! $post ) {
        $post = $saved_post;
        return '';
    }
    setup_postdata( $post );

    $instance = TC_Shortcode_PeerPosts::instance();
    $output   = $instance->fetch_and_render( $post_id, $attributes );

    wp_reset_postdata();
    $post = $saved_post;

    return $output;
}

/**
 * Block render callback for termconnect/peer-browse.
 *
 * The topic browser is not post-context dependent — it renders the facet
 * controls and initial results from its own attributes.
 *
 * @param array $attributes Block attributes.
 * @return string Rendered HTML.
 */
function tc_render_peer_browse_block( $attributes ): string {
    return TC_Shortcode_PeerBrowse::instance()->shortcode_handler( $attributes );
}
