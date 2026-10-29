<?php
/**
 * Peer Sites Admin — Terms → Peer Sites
 *
 * List table, add/edit form, and action handlers for managing link type
 * definitions (namespaces). Built-in types can be enabled/disabled but not
 * deleted; custom types can be added, edited, and deleted.
 *
 * @package TermConnect
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render a group of taxonomy checkboxes for link type forms.
 *
 * @param string   $name     Input name (array, e.g. 'tc_lt_taxonomies').
 * @param string[] $selected Currently selected taxonomy slugs.
 * @param string[] $options  Available taxonomies: slug → label.
 */
function tc_render_taxonomy_checkboxes( string $name, array $selected, array $options ): void {
    foreach ( $options as $slug => $label ) {
        printf(
            '<label style="display:inline-block;margin-right:12px;">'
            . '<input type="checkbox" name="%s[]" value="%s"%s> %s'
            . '</label>',
            esc_attr( $name ),
            esc_attr( $slug ),
            in_array( $slug, $selected, true ) ? ' checked' : '',
            esc_html( $label )
        );
    }
}

// ==============================================================================
// MENU REGISTRATION
// ==============================================================================

// Menu registration is handled by TC_HT_Integration::register_menu() — no duplicate add_action here.
// parent_file / submenu_file / current_screen are handled automatically since the page is
// registered under the detected Terms parent menu slug.

// ==============================================================================
// AJAX HANDLERS
// ==============================================================================

/**
 * Count term meta rows that would be deleted if the given taxonomy restrictions are applied.
 *
 * Expects POST: tc_peer_site_slug, tc_lt_taxonomies[], nonce.
 * Returns JSON: { count: N }
 *
 * @since 1.0.0
 */
function tc_ajax_peer_site_count_ineligible(): void {
    check_ajax_referer( 'tc_peer_site_edit', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
    }

    global $wpdb;

    $slug = isset( $_POST['tc_peer_site_slug'] ) ? sanitize_key( wp_unslash( $_POST['tc_peer_site_slug'] ) ) : '';
    if ( ! $slug ) {
        wp_send_json_success( array( 'count' => 0 ) );
        return;
    }

    $new_taxonomies = isset( $_POST['tc_lt_taxonomies'] ) && is_array( $_POST['tc_lt_taxonomies'] )
        ? array_map( 'sanitize_key', wp_unslash( $_POST['tc_lt_taxonomies'] ) )
        : array();

    if ( empty( $new_taxonomies ) ) {
        wp_send_json_success( array( 'count' => 0 ) );
        return;
    }

    $meta_key     = '_tc_link_' . $slug;
    $placeholders = implode( ', ', array_fill( 0, count( $new_taxonomies ), '%s' ) );

    $count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta} tm
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
             WHERE tm.meta_key = %s
             AND tt.taxonomy NOT IN ($placeholders)",
            array_merge( array( $meta_key ), $new_taxonomies )
        )
    );

    wp_send_json_success( array(
        'count'   => $count,
        'message' => sprintf(
            /* translators: %d: number of existing link values that will be deleted */
            _n(
                '%d term in a now-excluded taxonomy has a saved identifier for this link type. It will be deleted. Proceed?',
                '%d terms in now-excluded taxonomies have a saved identifier for this link type. They will be deleted. Proceed?',
                $count,
                'termconnect'
            ),
            $count
        ),
    ) );
}
add_action( 'wp_ajax_tc_peer_site_count_ineligible', 'tc_ajax_peer_site_count_ineligible' );

/**
 * AJAX: clear the cached post list for a configured WordPress peer site.
 *
 * @since 1.0.0
 */
function tc_ajax_clear_peer_cache(): void {
    check_ajax_referer( 'tc_clear_peer_cache', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
    }

    $peer_site_slug = sanitize_key( wp_unslash( $_POST['peer_site_slug'] ?? '' ) );
    if ( '' === $peer_site_slug ) {
        wp_send_json_error( array( 'message' => 'Missing link type slug.' ) );
    }

    $type = tc_get_link_type( $peer_site_slug );
    if ( null !== $type && ( $type['platform'] ?? '' ) === 'feed' ) {
        tc_feed_clear_cache( $peer_site_slug );
    } else {
        tc_external_clear_site_cache( $peer_site_slug );
    }

    wp_send_json_success();
}
add_action( 'wp_ajax_tc_clear_peer_cache', 'tc_ajax_clear_peer_cache' );

/**
 * AJAX: clear all external peer caches (post lists, FOAF data, term IDs, HyperTaxonomy status).
 *
 * Deletes every _transient_htax_ext_* row and resets per-site fetch metadata so
 * the Peer Sites table shows 0 cached posts until the next fetch.
 *
 * @since 1.0.0
 */
function tc_ajax_clear_all_peer_cache(): void {
    check_ajax_referer( 'tc_clear_all_peer_cache', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
    }

    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_tc_ext_%'
            OR option_name LIKE '_transient_timeout_tc_ext_%'"
    );
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE 'tc_ext_ltfetch_%'"
    );

    wp_send_json_success();
}
add_action( 'wp_ajax_tc_clear_all_peer_cache', 'tc_ajax_clear_all_peer_cache' );

/**
 * AJAX: Return the accumulated category vocabulary for a feed link type.
 *
 * POST params: peer_site_slug, nonce (tc-link-suggest).
 * Optional POST param: force=1 to re-fetch even when cache exists.
 *
 * @since 1.0.0
 */
function tc_ajax_feed_categories(): void {
    check_ajax_referer( 'tc-link-suggest', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
    }

    $peer_site_slug = sanitize_key( wp_unslash( $_POST['peer_site_slug'] ?? '' ) );
    if ( '' === $peer_site_slug ) {
        wp_send_json_error( array( 'message' => 'Missing peer_site_slug.' ) );
    }

    $type = tc_get_link_type( $peer_site_slug );
    if ( null === $type || ( $type['platform'] ?? '' ) !== 'feed' ) {
        wp_send_json_error( array( 'message' => 'Not a feed link type.' ) );
    }

    $force = ! empty( $_POST['force'] );
    $cats  = tc_feed_get_categories( $peer_site_slug );

    if ( $force || empty( $cats ) ) {
        $max = (int) ( $type['max_cached_posts'] ?? TC_FEED_MAX_POSTS_DEFAULT );
        tc_feed_fetch_and_accumulate( $peer_site_slug, $type['uri_prefix'], $max );
        $cats = tc_feed_get_categories( $peer_site_slug );
    }

    $output = array();
    foreach ( $cats as $slug => $label ) {
        $output[] = array(
            'slug'  => $slug,
            'label' => $label,
        );
    }
    usort( $output, static fn( $a, $b ) => strcmp( $a['label'], $b['label'] ) );
    wp_send_json_success( $output );
}
add_action( 'wp_ajax_tc_feed_categories', 'tc_ajax_feed_categories' );

/**
 * AJAX: Suggest identifiers for an external identifier type using its public API.
 *
 * Proxies the query to the pre-approved suggest endpoint for the given link type.
 * Only endpoints defined in tc_get_suggest_config() are contacted.
 *
 * POST params: peer_site_slug, q (search query), nonce (tc-link-suggest).
 *
 * @since 1.0.0
 */
function tc_ajax_peer_site_identifier_suggest(): void {
    check_ajax_referer( 'tc-link-suggest', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
    }

    $peer_site_slug = sanitize_key( wp_unslash( $_POST['peer_site_slug'] ?? '' ) );
    $query    = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
    $taxonomy = sanitize_key( wp_unslash( $_POST['taxonomy'] ?? '' ) );

    if ( '' === $peer_site_slug || mb_strlen( $query ) < 2 ) {
        wp_send_json_success( array() );
    }

    $config = tc_get_suggest_config( $peer_site_slug, $taxonomy );
    if ( null === $config ) {
        wp_send_json_success( array() );
    }

    $url      = str_replace( '{query}', rawurlencode( $query ), $config['url'] );
    $response = wp_remote_get(
        $url,
        array(
            'timeout'    => 5,
            'user-agent' => 'TermConnect/' . TC_VERSION,
            'sslverify'  => true,
        )
    );

    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        wp_send_json_success( array() );
    }

    wp_send_json_success( tc_parse_suggest_response( wp_remote_retrieve_body( $response ), $config['parser'] ) );
}
add_action( 'wp_ajax_tc_peer_site_identifier_suggest', 'tc_ajax_peer_site_identifier_suggest' );

/**
 * AJAX: Suggest remote term slugs for a WordPress peer link type.
 *
 * POST params: peer_site_slug, q (search query), taxonomy, nonce (tc-link-suggest).
 *
 * @since 1.0.0
 */
function tc_ajax_wp_peer_suggest(): void {
    check_ajax_referer( 'tc-link-suggest', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
    }

    $peer_site_slug  = sanitize_key( wp_unslash( $_POST['peer_site_slug'] ?? '' ) );
    $query    = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
    $local_tx = sanitize_key( wp_unslash( $_POST['taxonomy'] ?? '' ) );

    if ( '' === $peer_site_slug || mb_strlen( $query ) < 2 ) {
        wp_send_json_success( array() );
    }

    $type = tc_get_link_type( $peer_site_slug );
    if ( null === $type || ( $type['platform'] ?? '' ) !== 'wordpress' ) {
        wp_send_json_success( array() );
    }

    $origin   = tc_external_get_site_origin( $type['uri_prefix'] );
    $taxonomy = tc_wp_peer_remote_taxonomy( $type, $local_tx );

    if ( '' === $origin || '' === $taxonomy ) {
        wp_send_json_success( array() );
    }

    $terms  = tc_external_search_terms( $origin, $taxonomy, $query, 8 );
    $output = array();
    foreach ( $terms as $term ) {
        $name     = html_entity_decode( $term['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $output[] = array(
            'value' => $term['slug'],
            'label' => $name . ' (' . $term['slug'] . ')',
        );
    }
    wp_send_json_success( $output );
}
add_action( 'wp_ajax_tc_wp_peer_suggest', 'tc_ajax_wp_peer_suggest' );

/**
 * AJAX: Suggest authors on a WordPress peer link type (for "people" taxonomies).
 *
 * Used when the local taxonomy maps peer links to authors rather than terms
 * (tc_taxonomy_peer_authors()). The stored value is the author's nicename; the
 * link resolves to the peer's /author/{nicename}/ archive. SSRF-safe: the peer is
 * identified by a configured WordPress link-type slug, never a raw URL.
 *
 * POST params: peer_site_slug, q (search query), nonce (tc-link-suggest).
 *
 * @since 1.0.0
 */
function tc_ajax_wp_peer_author_suggest(): void {
    check_ajax_referer( 'tc-link-suggest', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
    }

    $peer_site_slug = sanitize_key( wp_unslash( $_POST['peer_site_slug'] ?? '' ) );
    $query          = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );

    if ( '' === $peer_site_slug || mb_strlen( $query ) < 2 ) {
        wp_send_json_success( array() );
    }

    $type = tc_get_link_type( $peer_site_slug );
    if ( null === $type || ( $type['platform'] ?? '' ) !== 'wordpress' ) {
        wp_send_json_success( array() );
    }

    $origin = tc_external_get_site_origin( $type['uri_prefix'] );
    if ( '' === $origin ) {
        wp_send_json_success( array() );
    }

    // The "Local site" pseudo-peer points at this site's own origin. The peer-users
    // proxy skips self, so list local authors directly (gated by the same capability
    // that controls access to the user list).
    if ( tc_is_self_origin( $origin ) ) {
        if ( ! current_user_can( 'list_users' ) ) {
            wp_send_json_success( array() );
        }
        $output = array();
        $users  = get_users( array(
            'search'         => '*' . $query . '*',
            'search_columns' => array( 'display_name', 'user_nicename', 'user_login' ),
            'number'         => 8,
            'orderby'        => 'display_name',
            'order'          => 'ASC',
        ) );
        foreach ( $users as $user ) {
            $output[] = array(
                'value' => $user->user_nicename,
                'label' => $user->display_name . ' (' . $user->user_nicename . ')',
            );
        }
        wp_send_json_success( $output );
    }

    $output = array();
    foreach ( tc_external_search_users( $origin, $query, 8 ) as $user ) {
        $slug = (string) ( $user['slug'] ?? '' );
        if ( '' === $slug ) {
            continue;
        }
        $name     = html_entity_decode( (string) ( $user['name'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $output[] = array(
            'value' => $slug,
            'label' => '' !== $name ? $name . ' (' . $slug . ')' : $slug,
        );
    }
    wp_send_json_success( $output );
}
add_action( 'wp_ajax_tc_wp_peer_author_suggest', 'tc_ajax_wp_peer_author_suggest' );

/**
 * AJAX: Immediately poll a feed link type and return updated stats.
 *
 * POST params: peer_site_slug, nonce (tc_clear_peer_cache).
 *
 * @since 1.0.0
 */
function tc_ajax_poll_feed_now(): void {
    check_ajax_referer( 'tc_clear_peer_cache', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
    }

    $peer_site_slug = sanitize_key( wp_unslash( $_POST['peer_site_slug'] ?? '' ) );
    if ( '' === $peer_site_slug ) {
        wp_send_json_error( array( 'message' => 'Missing link type slug.' ) );
    }

    $type = tc_get_link_type( $peer_site_slug );
    if ( null === $type || ( $type['platform'] ?? '' ) !== 'feed' ) {
        wp_send_json_error( array( 'message' => 'Not a feed link type.' ) );
    }

    tc_feed_do_poll( $peer_site_slug );

    $stat = get_option( 'tc_feed_poll_stat_' . $peer_site_slug, array() );
    wp_send_json_success(
        array(
            'post_count' => (int) ( $stat['post_count'] ?? 0 ),
            'cat_count'  => (int) ( $stat['cat_count'] ?? 0 ),
        )
    );
}
add_action( 'wp_ajax_tc_poll_feed_now', 'tc_ajax_poll_feed_now' );

/**
 * AJAX: clear all FOAF-discovered peer data (tpeer and tdata transients).
 *
 * Exposed on the Settings → TermConnect → Cache tab.
 *
 * @since 1.0.0
 */
function tc_ajax_clear_foaf_cache(): void {
    check_ajax_referer( 'tc_clear_foaf_cache', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
    }

    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_tc_ext_tpeer_%'
            OR option_name LIKE '_transient_timeout_tc_ext_tpeer_%'
            OR option_name LIKE '_transient_tc_ext_tdata_%'
            OR option_name LIKE '_transient_timeout_tc_ext_tdata_%'"
    );

    wp_send_json_success();
}
add_action( 'wp_ajax_tc_clear_foaf_cache', 'tc_ajax_clear_foaf_cache' );

// ==============================================================================
// ACTION HANDLERS
// ==============================================================================

/**
 * Handle all link type form submissions (add, edit, delete, toggle).
 *
 * @since 1.0.0
 */
function tc_handle_peer_site_action(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to manage link types.', 'termconnect' ) );
    }

    $action = isset( $_POST['tc_lt_action'] ) ? sanitize_key( wp_unslash( $_POST['tc_lt_action'] ) ) : '';

    if ( ! check_admin_referer( 'tc_peer_site_' . $action ) ) {
        wp_die( esc_html__( 'Security check failed.', 'termconnect' ) );
    }

    switch ( $action ) {
        case 'add':
            tc_handle_peer_site_add();
            break;
        case 'edit':
            tc_handle_peer_site_edit();
            break;
        case 'delete':
            tc_handle_peer_site_delete();
            break;
        case 'toggle':
            tc_handle_peer_site_toggle();
            break;
        case 'toggle_archives':
            tc_handle_peer_site_toggle_archives();
            break;
    }

    wp_safe_redirect( admin_url( 'admin.php?page=tc-peer-sites' ) );
    exit;
}
add_action( 'admin_post_tc_peer_site_action', 'tc_handle_peer_site_action' );

/**
 * Enqueue jQuery UI Sortable and the inline reorder script on the Peer Sites page.
 *
 * @since 1.0.0
 * @param string $hook Current admin page hook.
 */
function tc_enqueue_peer_sites_assets( string $hook ): void {
    if ( false === strpos( $hook, 'tc-peer-sites' ) ) {
        return;
    }
    wp_enqueue_script( 'jquery-ui-sortable' );
    $nonce = wp_create_nonce( 'tc_peer_sites_reorder' );
    wp_add_inline_script(
        'jquery-ui-sortable',
        'jQuery(function($){
            var $tbody = $("#tc-peer-sites-enabled tbody");
            if ( ! $tbody.length ) { return; }
            $tbody.sortable({
                handle: ".tc-drag-handle",
                placeholder: "tc-drag-placeholder",
                forcePlaceholderSize: true,
                stop: function() {
                    var slugs = [];
                    $tbody.find("tr").each(function(){
                        slugs.push($(this).data("slug"));
                    });
                    $.post(ajaxurl, {
                        action: "tc_peer_sites_reorder",
                        slugs: slugs,
                        _ajax_nonce: ' . wp_json_encode( $nonce ) . '
                    });
                }
            });
            $tbody.disableSelection();
        });'
    );
}
add_action( 'admin_enqueue_scripts', 'tc_enqueue_peer_sites_assets' );

/**
 * AJAX handler: save the new link type order.
 *
 * Reads a slugs[] array in the new display order and re-saves tc_link_types
 * with entries ordered accordingly.
 *
 * @since 1.0.0
 */
function tc_ajax_peer_sites_reorder(): void {
    check_ajax_referer( 'tc_peer_sites_reorder' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }

    $slugs = isset( $_POST['slugs'] ) && is_array( $_POST['slugs'] )
        ? array_map( 'sanitize_key', wp_unslash( $_POST['slugs'] ) )
        : array();

    if ( empty( $slugs ) ) {
        wp_send_json_error( 'No slugs provided', 400 );
    }

    $types    = tc_get_all_link_types();
    $enabled  = array_filter( $types, static fn( $t ) => ! empty( $t['enabled'] ) );
    $disabled = array_filter( $types, static fn( $t ) => empty( $t['enabled'] ) );

    // Reorder enabled types according to the submitted slug list.
    $reordered = array();
    foreach ( $slugs as $slug ) {
        if ( isset( $enabled[ $slug ] ) ) {
            $reordered[ $slug ] = $enabled[ $slug ];
        }
    }
    // Preserve any enabled type not included in the submitted list.
    foreach ( $enabled as $slug => $type ) {
        if ( ! isset( $reordered[ $slug ] ) ) {
            $reordered[ $slug ] = $type;
        }
    }

    tc_set_link_types( array_merge( $reordered, $disabled ) );
    wp_send_json_success();
}
add_action( 'wp_ajax_tc_peer_sites_reorder', 'tc_ajax_peer_sites_reorder' );

/**
 * @since 1.0.0
 */
function tc_handle_peer_site_add(): void {
    $label       = sanitize_text_field( wp_unslash( $_POST['tc_lt_label'] ?? '' ) );
    $uri_prefix  = esc_url_raw( wp_unslash( $_POST['tc_lt_uri_prefix'] ?? '' ) );
    $description = sanitize_text_field( wp_unslash( $_POST['tc_lt_description'] ?? '' ) );
    $platform    = sanitize_key( wp_unslash( $_POST['tc_lt_platform'] ?? 'other' ) );
    if ( ! in_array( $platform, array( 'wordpress', 'feed', 'other', 'mediawiki' ), true ) ) {
        $platform = 'other';
    }

    if ( '' === $label || '' === $uri_prefix ) {
        return;
    }

    if ( ! tc_validate_uri_prefix( $uri_prefix ) ) {
        set_transient(
            'tc_lt_error_' . get_current_user_id(),
            __( 'Invalid URI prefix. Only http and https are allowed, and loopback or link-local addresses are not permitted.', 'termconnect' ),
            60
        );
        return;
    }

    // WordPress peer: store only the site origin — the path is derived at URI-construction time.
    if ( 'wordpress' === $platform ) {
        $origin = tc_external_get_site_origin( $uri_prefix );
        if ( '' !== $origin ) {
            $uri_prefix = $origin;
        }
        if ( tc_is_self_origin( $uri_prefix ) ) {
            set_transient(
                'tc_lt_error_' . get_current_user_id(),
                __( 'Cannot add this site as a WordPress peer. Linking to yourself produces redundant HTTP self-requests and duplicate post results.', 'termconnect' ),
                60
            );
            return;
        }
    }

    $slug = sanitize_title( $label );
    if ( '' === $slug ) {
        return;
    }

    $types = tc_get_all_link_types();

    // Slug must be unique.
    if ( isset( $types[ $slug ] ) ) {
        return;
    }

    $taxonomies      = isset( $_POST['tc_lt_taxonomies'] ) && is_array( $_POST['tc_lt_taxonomies'] )
        ? array_map( 'sanitize_key', wp_unslash( $_POST['tc_lt_taxonomies'] ) )
        : array();
    $remote_taxonomy = 'wordpress' === $platform
        ? sanitize_key( wp_unslash( $_POST['tc_lt_remote_taxonomy'] ?? '' ) )
        : '';
    $poll_interval   = 'feed' === $platform
        ? max( 300, min( 86400, (int) ( $_POST['tc_lt_poll_interval'] ?? 3600 ) ) )
        : 0;
    $max_cached_posts = 'feed' === $platform
        ? max( 50, min( 5000, (int) ( $_POST['tc_lt_max_cached_posts'] ?? 500 ) ) )
        : 0;

    $new_type = array(
        'slug'             => $slug,
        'label'            => $label,
        'uri_prefix'       => $uri_prefix,
        'description'      => $description,
        'platform'         => $platform,
        'remote_taxonomy'  => $remote_taxonomy,
        'poll_interval'    => $poll_interval,
        'max_cached_posts' => $max_cached_posts,
        'enabled'          => true,
        'show_on_archives' => ! empty( $_POST['tc_lt_show_on_archives'] ),
        'taxonomies'       => $taxonomies,
        'built_in'         => false,
    );

    // Insert at end of enabled group so it appears at the bottom of the enabled table.
    $enabled  = array_filter( $types, static fn( $t ) => ! empty( $t['enabled'] ) );
    $disabled = array_filter( $types, static fn( $t ) => empty( $t['enabled'] ) );
    $types    = array_merge( $enabled, array( $slug => $new_type ), $disabled );

    tc_set_link_types( $types );

    if ( 'feed' === $platform ) {
        tc_feed_schedule( $slug, $poll_interval );
    }
}

/**
 * @since 1.0.0
 */
function tc_handle_peer_site_edit(): void {
    $slug = sanitize_key( wp_unslash( $_POST['tc_peer_site_slug'] ?? '' ) );
    if ( '' === $slug ) {
        return;
    }

    $types = tc_get_all_link_types();
    if ( ! isset( $types[ $slug ] ) ) {
        return;
    }

    $type = $types[ $slug ];

    $is_built_in = ! empty( $type['built_in'] );

    if ( ! $is_built_in ) {
        // User-defined types: allow editing all fields.
        $label      = sanitize_text_field( wp_unslash( $_POST['tc_lt_label'] ?? '' ) );
        $uri_prefix = esc_url_raw( wp_unslash( $_POST['tc_lt_uri_prefix'] ?? '' ) );
        if ( '' !== $label ) {
            $type['label'] = $label;
        }
        if ( '' !== $uri_prefix ) {
            if ( ! tc_validate_uri_prefix( $uri_prefix ) ) {
                set_transient(
                    'tc_lt_error_' . get_current_user_id(),
                    __( 'Invalid URI prefix. Only http and https are allowed, and loopback or link-local addresses are not permitted.', 'termconnect' ),
                    60
                );
                return;
            }
            $type['uri_prefix'] = $uri_prefix;
        }

        $platform = sanitize_key( wp_unslash( $_POST['tc_lt_platform'] ?? 'other' ) );
        if ( ! in_array( $platform, array( 'wordpress', 'feed', 'other', 'mediawiki' ), true ) ) {
            $platform = 'other';
        }

        // WordPress peer: store only the site origin.
        if ( 'wordpress' === $platform ) {
            $origin = tc_external_get_site_origin( $type['uri_prefix'] );
            if ( '' !== $origin ) {
                $type['uri_prefix'] = $origin;
            }
            if ( tc_is_self_origin( $type['uri_prefix'] ) ) {
                set_transient(
                    'tc_lt_error_' . get_current_user_id(),
                    __( 'Cannot set this site as a WordPress peer. Linking to yourself produces redundant HTTP self-requests and duplicate post results.', 'termconnect' ),
                    60
                );
                return;
            }
        }

        $type['description']      = sanitize_text_field( wp_unslash( $_POST['tc_lt_description'] ?? '' ) );
        $type['platform']         = $platform;
        $type['remote_taxonomy']  = 'wordpress' === $platform
            ? sanitize_key( wp_unslash( $_POST['tc_lt_remote_taxonomy'] ?? '' ) )
            : '';
        $type['poll_interval']    = 'feed' === $platform
            ? max( 300, min( 86400, (int) ( $_POST['tc_lt_poll_interval'] ?? 3600 ) ) )
            : 0;
        $type['max_cached_posts'] = 'feed' === $platform
            ? max( 50, min( 5000, (int) ( $_POST['tc_lt_max_cached_posts'] ?? 500 ) ) )
            : 0;
    }

    // Both built-in and user-defined: allow configuring display and taxonomy restriction.
    $type['show_on_archives'] = ! empty( $_POST['tc_lt_show_on_archives'] );
    $type['taxonomies']       = isset( $_POST['tc_lt_taxonomies'] ) && is_array( $_POST['tc_lt_taxonomies'] )
        ? array_map( 'sanitize_key', wp_unslash( $_POST['tc_lt_taxonomies'] ) )
        : array();

    $types[ $slug ] = $type;
    tc_set_link_types( $types );

    // Reschedule feed poll cron when feed settings change (idempotent).
    if ( ! $is_built_in && ( $type['platform'] ?? '' ) === 'feed' && ! empty( $type['enabled'] ) ) {
        tc_feed_schedule( $slug, (int) ( $type['poll_interval'] ?? 3600 ) );
    }

    // Remove meta for terms whose taxonomy no longer matches the restriction.
    tc_remove_invalid_term_links( $slug );
}

/**
 * @since 1.0.0
 */
function tc_handle_peer_site_delete(): void {
    global $wpdb;

    $slug = sanitize_key( wp_unslash( $_POST['tc_peer_site_slug'] ?? '' ) );
    if ( '' === $slug ) {
        return;
    }

    $types = tc_get_all_link_types();
    if ( ! isset( $types[ $slug ] ) || ! empty( $types[ $slug ]['built_in'] ) ) {
        return;
    }

    // Bulk-remove all term meta for this link type.
    $affected_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s",
        '_tc_link_' . $slug
    ) );
    $wpdb->delete(
        $wpdb->termmeta,
        array( 'meta_key' => '_tc_link_' . $slug ),
        array( '%s' )
    );
    foreach ( array_map( 'intval', $affected_ids ) as $tid ) {
        tc_flush_term_link_cache( $tid );
    }

    if ( ( $types[ $slug ]['platform'] ?? '' ) === 'feed' ) {
        tc_feed_unschedule( $slug );
        tc_feed_clear_cache( $slug );
    }

    // Remove the peer site's reachability state so Site Health no longer reports it.
    // Use a transient mutex to prevent concurrent requests from overwriting each other's changes.
    // Health data loss is acceptable if the lock cannot be acquired; do not abort the delete.
    $health_lock_acquired = false;
    for ( $i = 0; $i < 3; $i++ ) {
        if ( false === get_transient( 'tc_peer_health_lock' ) ) {
            set_transient( 'tc_peer_health_lock', 1, 10 );
            $health_lock_acquired = true;
            break;
        }
        usleep( 200000 ); // 0.2s
    }
    if ( $health_lock_acquired ) {
        try {
            $health = (array) get_option( 'tc_peer_site_health', array() );
            if ( isset( $health[ $slug ] ) ) {
                unset( $health[ $slug ] );
                update_option( 'tc_peer_site_health', $health, false );
            }
        } finally {
            delete_transient( 'tc_peer_health_lock' );
        }
    } else {
        error_log( 'TermConnect: could not acquire tc_peer_health_lock to remove health entry for peer site "' . $slug . '".' );
    }

    unset( $types[ $slug ] );
    tc_set_link_types( $types );
}

/**
 * @since 1.0.0
 */
function tc_handle_peer_site_toggle(): void {
    $slug = sanitize_key( wp_unslash( $_POST['tc_peer_site_slug'] ?? '' ) );
    if ( '' === $slug ) {
        return;
    }

    $types = tc_get_all_link_types();
    if ( ! isset( $types[ $slug ] ) ) {
        return;
    }

    $enabling                  = empty( $types[ $slug ]['enabled'] );
    $types[ $slug ]['enabled'] = $enabling;

    if ( $enabling ) {
        // Move newly-enabled type to the end of the enabled group so it surfaces at the top of the page.
        $type_def = $types[ $slug ];
        unset( $types[ $slug ] );
        $enabled  = array_filter( $types, static fn( $t ) => ! empty( $t['enabled'] ) );
        $disabled = array_filter( $types, static fn( $t ) => empty( $t['enabled'] ) );
        $types    = array_merge( $enabled, array( $slug => $type_def ), $disabled );
    }

    tc_set_link_types( $types );

    if ( ( $types[ $slug ]['platform'] ?? '' ) === 'feed' ) {
        if ( $types[ $slug ]['enabled'] ) {
            tc_feed_schedule( $slug, (int) ( $types[ $slug ]['poll_interval'] ?? 3600 ) );
        } else {
            tc_feed_unschedule( $slug );
        }
    }
}

/**
 * @since 1.0.0
 */
function tc_handle_peer_site_toggle_archives(): void {
    $slug = sanitize_key( wp_unslash( $_POST['tc_peer_site_slug'] ?? '' ) );
    if ( '' === $slug ) {
        return;
    }

    $types = tc_get_all_link_types();
    if ( ! isset( $types[ $slug ] ) || empty( $types[ $slug ]['enabled'] ) ) {
        return;
    }

    $types[ $slug ]['show_on_archives'] = empty( $types[ $slug ]['show_on_archives'] );
    tc_set_link_types( $types );
}

// ==============================================================================
// PAGE RENDERER
// ==============================================================================

/**
 * Render the Terms → Peer Sites admin page.
 *
 * @since 1.0.0
 */
function tc_render_peer_sites_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Exclude plugin-managed (provider) link types — they are configured in their
    // own plugin's settings, not here. They remain active for term editing/REST/matching.
    $types      = tc_get_manageable_link_types();
    $action_url = admin_url( 'admin-post.php' );

    // Build taxonomy options: all enabled taxonomies except the internal 'group' taxonomy.
    $taxonomy_options = array();
    foreach ( TC_Settings::get_enabled_taxonomies() as $tax_slug ) {
        if ( 'group' === $tax_slug ) {
            continue;
        }
        $tax_obj                       = get_taxonomy( $tax_slug );
        $taxonomy_options[ $tax_slug ] = $tax_obj ? $tax_obj->label : $tax_slug;
    }
    ?>
    <style>
        .tc-drag-handle { cursor: move; color: #bbb; padding: 0 6px; font-size: 18px; line-height: 1; user-select: none; }
        .tc-drag-handle:hover { color: #555; }
        .tc-drag-placeholder td { background: #e7f3ff !important; }
    </style>
    <div class="wrap">
        <h1><?php esc_html_e( 'Peer Sites', 'termconnect' ); ?></h1>
        <p><?php
            esc_html_e( 'Manage external sites, feeds and services where your terms can have identifiers. Enable the namespaces relevant to your content; once enabled, you can add identifiers to individual terms from their edit screens.', 'termconnect' );
            echo ' ';
            esc_html_e( 'When a local term shares an identifier with a term on a peer WordPress site, TermConnect can delegate its identity to the peer — keeping the name, description, and external links in sync automatically.', 'termconnect' );
            echo ' ';
            printf(
                /* translators: %s: link to Shared Vocabularies page */
                esc_html__( 'A collection of terms whose identity is delegated to a peer site is called a %s.', 'termconnect' ),
                '<a href="' . esc_url( admin_url( 'admin.php?page=tc-shared-vocabularies' ) ) . '">' . esc_html__( 'Shared Vocabulary', 'termconnect' ) . '</a>'
            );
        ?></p>

        <?php
        $error_key = 'tc_lt_error_' . get_current_user_id();
        $error_msg = get_transient( $error_key );
        if ( false !== $error_msg ) {
            delete_transient( $error_key );
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error_msg ) . '</p></div>';
        }
        ?>

        <?php
        $enabled_types  = array_filter( $types, static fn( $t ) => ! empty( $t['enabled'] ) );
        $disabled_types = array_filter( $types, static fn( $t ) => empty( $t['enabled'] ) );
        ?>
        <!-- ====== ENABLED TABLE ====== -->
        <h2 style="margin:1em 0 .5em"><?php esc_html_e( 'Enabled', 'termconnect' ); ?></h2>
        <?php if ( ! empty( $enabled_types ) ) : ?>
        <p class="description" style="margin-bottom:.5em"><?php esc_html_e( 'Drag rows up or down to change their display order. Links to the identifiers from enabled namespaces will appear in this order in the "See also" section on term archive pages — if that feature is enabled in Settings and the namespace has "Show on archives" checked.', 'termconnect' ); ?></p>
        <?php
        $cache_op_nonce = wp_create_nonce( 'tc_clear_peer_cache' );

        // Pre-load mapped term counts for WP peer and feed types in one query.
        global $wpdb;
        $active_slugs = array_keys( array_filter( $enabled_types, static function ( $t ) {
            $plat = $t['platform'] ?? 'other';
            return 'wordpress' === $plat || 'feed' === $plat;
        } ) );
        $mapped_counts = array();
        if ( ! empty( $active_slugs ) ) {
            $meta_keys    = array_map( static fn( $s ) => '_tc_link_' . $s, $active_slugs );
            $placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT meta_key, COUNT(*) AS cnt FROM {$wpdb->termmeta}
                 WHERE meta_key IN ($placeholders) AND meta_value != ''
                 GROUP BY meta_key",
                ...$meta_keys
            ) );
            foreach ( $rows as $row ) {
                $mapped_counts[ substr( $row->meta_key, 9 ) ] = (int) $row->cnt;
            }
            foreach ( $active_slugs as $as ) {
                $mapped_counts[ $as ] = $mapped_counts[ $as ] ?? 0;
            }
        }
        ?>
        <table id="tc-peer-sites-enabled" class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" style="width:30px"></th>
                    <th scope="col" style="width:120px"><?php esc_html_e( 'Label', 'termconnect' ); ?></th>
                    <th scope="col" style="width:240px"><?php esc_html_e( 'URI Prefix', 'termconnect' ); ?></th>
                    <th scope="col" style="width:85px"><?php esc_html_e( 'Platform', 'termconnect' ); ?></th>
                    <th scope="col" style="width:95px"><?php esc_html_e( 'Taxonomies', 'termconnect' ); ?></th>
                    <th scope="col" style="width:70px"><?php esc_html_e( 'Archives', 'termconnect' ); ?></th>
                    <th scope="col" style="width:155px"><?php esc_html_e( 'Status', 'termconnect' ); ?></th>
                    <th scope="col" style="width:205px"><?php esc_html_e( 'Actions', 'termconnect' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $enabled_types as $slug => $type ) :
                $plat = $type['platform'] ?? 'other';
            ?>
                <tr data-slug="<?php echo esc_attr( $slug ); ?>">
                    <td><span class="tc-drag-handle" title="<?php esc_attr_e( 'Drag to reorder', 'termconnect' ); ?>">&#8942;</span></td>
                    <td><strong><?php echo esc_html( $type['label'] ); ?></strong>
                        <span style="color:#888;font-size:11px;display:block"><?php echo esc_html( '(' . $slug . ')' ); ?>
                        <?php if ( ! empty( $type['built_in'] ) ) : ?>
                            &middot; <?php esc_html_e( 'Built-in', 'termconnect' ); ?>
                        <?php endif; ?></span>
                    </td>
                    <td style="word-break:break-all;font-size:12px"><?php echo esc_html( $type['uri_prefix'] ); ?></td>
                    <td style="font-size:12px">
                        <?php
                        if ( 'wordpress' === $plat ) {
                            esc_html_e( 'WordPress', 'termconnect' );
                        } elseif ( 'feed' === $plat ) {
                            esc_html_e( 'Feed', 'termconnect' );
                        } else {
                            echo '&#8212;';
                        }
                        ?>
                    </td>
                    <td style="font-size:12px">
                        <?php
                        $type_taxes = $type['taxonomies'] ?? array();
                        if ( empty( $type_taxes ) ) {
                            echo '&#8212;';
                        } else {
                            $tax_labels = array();
                            foreach ( $type_taxes as $ts ) {
                                $tax_obj      = get_taxonomy( $ts );
                                $tax_labels[] = $tax_obj ? $tax_obj->label : $ts;
                            }
                            echo esc_html( implode( ', ', $tax_labels ) );
                        }
                        ?>
                    </td>
                    <td>
                        <form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin:0">
                            <?php wp_nonce_field( 'tc_peer_site_toggle_archives' ); ?>
                            <input type="hidden" name="action" value="tc_peer_site_action">
                            <input type="hidden" name="tc_lt_action" value="toggle_archives">
                            <input type="hidden" name="tc_peer_site_slug" value="<?php echo esc_attr( $slug ); ?>">
                            <input type="checkbox" onchange="this.form.submit()"
                                   <?php checked( ! empty( $type['show_on_archives'] ) ); ?>>
                        </form>
                    </td>
                    <td style="font-size:12px" class="tc-peer-site-status-cell" data-peer-site-slug="<?php echo esc_attr( $slug ); ?>">
                    <?php if ( 'wordpress' === $plat ) :
                        $health = tc_peer_site_health_get( $slug );
                        $stats  = get_option( 'tc_ext_ltfetch_' . $slug, array() );
                        $count  = (int) ( $stats['count'] ?? 0 );
                        $time   = (int) ( $stats['time']  ?? 0 );
                        switch ( $health['status'] ) {
                            case 'degraded':
                                $dot        = '<span style="color:#dba617" aria-hidden="true">&#9679;</span>';
                                $hlabel     = esc_html__( 'Degraded', 'termconnect' );
                                $hdetail    = sprintf(
                                    esc_html( _n( '%d failure', '%d failures', (int) $health['consecutive_failures'], 'termconnect' ) ),
                                    (int) $health['consecutive_failures']
                                );
                                if ( $health['last_error'] ) {
                                    $hdetail .= ' &middot; ' . esc_html( $health['last_error'] );
                                }
                                break;
                            default:
                                $dot     = '<span style="color:#46b450" aria-hidden="true">&#9679;</span>';
                                $hlabel  = esc_html__( 'OK', 'termconnect' );
                                $hdetail = '';
                        }
                        ?>
                        <?php echo $dot; ?> <?php echo $hlabel; ?>
                        <?php if ( $hdetail ) : ?><br><small style="color:#888"><?php echo wp_kses_post( $hdetail ); ?></small><?php endif; ?>
                        <span class="tc-peer-stats-counts" style="display:block;margin-top:2px">
                            <?php $mc = $mapped_counts[ $slug ] ?? 0; ?>
                            <small style="color:#888"><?php echo esc_html( sprintf( _n( '%d term mapped', '%d terms mapped', $mc, 'termconnect' ), $mc ) ); ?></small>
                            <br><small class="tc-peer-stats-count" style="color:#aaa"><?php echo esc_html( sprintf( _n( '%d post cached', '%d posts cached', $count, 'termconnect' ), $count ) ); ?></small>
                            <?php if ( $time > 0 ) : ?>
                                <br><small class="tc-peer-stats-time" style="color:#aaa"><?php echo esc_html( sprintf( __( 'Fetched %s ago', 'termconnect' ), human_time_diff( $time ) ) ); ?></small>
                            <?php endif; ?>
                        </span>
                    <?php elseif ( 'feed' === $plat ) :
                        $stat       = get_option( 'tc_feed_poll_stat_' . $slug, array() );
                        $post_count = (int) ( $stat['post_count'] ?? 0 );
                        $cat_count  = (int) ( $stat['cat_count']  ?? 0 );
                        $last_time  = (int) ( $stat['time']       ?? 0 );
                        $next_ts    = wp_next_scheduled( 'tc_feed_poll', array( $slug ) );
                        ?>
                        <?php $mc = $mapped_counts[ $slug ] ?? 0; ?>
                        <small style="color:#888"><?php echo esc_html( sprintf( _n( '%d term mapped', '%d terms mapped', $mc, 'termconnect' ), $mc ) ); ?></small>
                        <br><span class="tc-feed-stat-posts">
                            <small style="color:#aaa"><?php echo esc_html( sprintf(
                                /* translators: 1: post count 2: category count */
                                _n( '%1$d post, %2$d categories', '%1$d posts, %2$d categories', $post_count, 'termconnect' ),
                                $post_count,
                                $cat_count
                            ) ); ?></small>
                        </span>
                        <?php if ( $last_time > 0 ) : ?>
                            <br><small class="tc-feed-stat-time" style="color:#aaa"><?php echo esc_html( sprintf( __( 'Last poll performed %s ago', 'termconnect' ), human_time_diff( $last_time ) ) ); ?></small>
                        <?php endif; ?>
                        <?php if ( $next_ts ) : ?>
                            <br><small style="color:#aaa"><?php echo esc_html( sprintf( __( 'Next poll in %s', 'termconnect' ), human_time_diff( time(), $next_ts ) ) ); ?></small>
                        <?php else : ?>
                            <br><small style="color:#d63638"><?php esc_html_e( 'Not scheduled', 'termconnect' ); ?></small>
                        <?php endif; ?>
                        <div style="margin-top:4px">
                            <button type="button" class="button button-small tc-poll-feed-now"
                                    data-nonce="<?php echo esc_attr( $cache_op_nonce ); ?>"
                                    data-peer-site-slug="<?php echo esc_attr( $slug ); ?>">
                                <?php esc_html_e( 'Poll Now', 'termconnect' ); ?>
                            </button>
                        </div>
                    <?php else : ?>
                        &#8212;
                    <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <button type="button" class="button button-small tc-lt-edit-btn"
                                data-type="<?php echo esc_attr( wp_json_encode( array(
                                    'slug'             => $slug,
                                    'label'            => $type['label'],
                                    'uri_prefix'       => $type['uri_prefix'],
                                    'description'      => $type['description'] ?? '',
                                    'platform'         => $type['platform'] ?? 'other',
                                    'remote_taxonomy'  => $type['remote_taxonomy'] ?? '',
                                    'poll_interval'    => (int) ( $type['poll_interval'] ?? 3600 ),
                                    'max_cached_posts' => (int) ( $type['max_cached_posts'] ?? 500 ),
                                    'show_on_archives' => ! empty( $type['show_on_archives'] ),
                                    'taxonomies'       => $type['taxonomies'] ?? array(),
                                    'built_in'         => ! empty( $type['built_in'] ),
                                ) ) ); ?>">
                            <?php echo ! empty( $type['built_in'] ) ? esc_html__( 'Restrict', 'termconnect' ) : esc_html__( 'Edit', 'termconnect' ); ?>
                        </button>
                        &nbsp;
                        <?php if ( 'wordpress' === $plat ) : ?>
                        <button type="button" class="button button-small tc-clear-peer-cache"
                                data-nonce="<?php echo esc_attr( $cache_op_nonce ); ?>"
                                data-peer-site-slug="<?php echo esc_attr( $slug ); ?>">
                            <?php esc_html_e( 'Clear Cache', 'termconnect' ); ?>
                        </button>
                        <span class="tc-clear-status" style="display:none;margin-left:2px;color:#46b450">&#10003;</span>
                        &nbsp;
                        <?php elseif ( 'feed' === $plat ) : ?>
                        <button type="button" class="button button-small tc-clear-feed-cache"
                                data-nonce="<?php echo esc_attr( $cache_op_nonce ); ?>"
                                data-peer-site-slug="<?php echo esc_attr( $slug ); ?>">
                            <?php esc_html_e( 'Clear Cache', 'termconnect' ); ?>
                        </button>
                        <span class="tc-clear-status" style="display:none;margin-left:2px;color:#46b450">&#10003;</span>
                        &nbsp;
                        <?php endif; ?>
                        <form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display:inline">
                            <?php wp_nonce_field( 'tc_peer_site_toggle' ); ?>
                            <input type="hidden" name="action" value="tc_peer_site_action">
                            <input type="hidden" name="tc_lt_action" value="toggle">
                            <input type="hidden" name="tc_peer_site_slug" value="<?php echo esc_attr( $slug ); ?>">
                            <button type="submit" class="button button-small"><?php esc_html_e( 'Disable', 'termconnect' ); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <script>
        (function() {
            document.querySelectorAll('.tc-clear-peer-cache').forEach(function( btn ) {
                btn.addEventListener('click', function() {
                    btn.disabled = true;
                    var status = btn.nextElementSibling;
                    var data   = new FormData();
                    data.append('action', 'tc_clear_peer_cache');
                    data.append('nonce', btn.dataset.nonce);
                    data.append('peer_site_slug', btn.dataset.peerSiteSlug);
                    fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                        .then(function( r ) { return r.json(); })
                        .then(function( json ) {
                            btn.disabled = false;
                            if ( json.success ) {
                                if ( status ) { status.style.display = 'inline'; setTimeout(function() { status.style.display = 'none'; }, 4000); }
                                var cell = document.querySelector('.tc-peer-site-status-cell[data-peer-site-slug="' + btn.dataset.peerSiteSlug + '"]');
                                if ( cell ) {
                                    var counts = cell.querySelector('.tc-peer-stats-counts');
                                    if ( counts ) { counts.innerHTML = '<small style="color:#888">0 posts cached</small>'; }
                                }
                            }
                        })
                        .catch(function() { btn.disabled = false; });
                });
            });

            document.querySelectorAll('.tc-poll-feed-now').forEach(function( btn ) {
                btn.addEventListener('click', function() {
                    btn.disabled = true;
                    btn.textContent = '<?php echo esc_js( __( 'Polling…', 'termconnect' ) ); ?>';
                    var data = new FormData();
                    data.append('action', 'tc_poll_feed_now');
                    data.append('nonce', btn.dataset.nonce);
                    data.append('peer_site_slug', btn.dataset.peerSiteSlug);
                    fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                        .then(function( r ) { return r.json(); })
                        .then(function( json ) {
                            btn.disabled = false;
                            btn.textContent = '<?php echo esc_js( __( 'Poll Now', 'termconnect' ) ); ?>';
                            if ( json.success ) {
                                var cell = document.querySelector('.tc-peer-site-status-cell[data-peer-site-slug="' + btn.dataset.peerSiteSlug + '"]');
                                if ( cell ) {
                                    var postsEl = cell.querySelector('.tc-feed-stat-posts small');
                                    if ( postsEl ) { postsEl.textContent = json.data.post_count + ' <?php echo esc_js( __( 'posts', 'termconnect' ) ); ?>, ' + json.data.cat_count + ' <?php echo esc_js( __( 'categories', 'termconnect' ) ); ?>'; }
                                    var timeEl = cell.querySelector('.tc-feed-stat-time');
                                    if ( timeEl ) { timeEl.textContent = '<?php echo esc_js( __( 'Last poll performed just now', 'termconnect' ) ); ?>'; }
                                }
                            }
                        })
                        .catch(function() { btn.disabled = false; btn.textContent = '<?php echo esc_js( __( 'Poll Now', 'termconnect' ) ); ?>'; });
                });
            });

            document.querySelectorAll('.tc-clear-feed-cache').forEach(function( btn ) {
                btn.addEventListener('click', function() {
                    btn.disabled = true;
                    var status = btn.nextElementSibling;
                    var data   = new FormData();
                    data.append('action', 'tc_clear_peer_cache');
                    data.append('nonce', btn.dataset.nonce);
                    data.append('peer_site_slug', btn.dataset.peerSiteSlug);
                    fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                        .then(function( r ) { return r.json(); })
                        .then(function( json ) {
                            btn.disabled = false;
                            if ( json.success ) {
                                if ( status ) { status.style.display = 'inline'; setTimeout(function() { status.style.display = 'none'; }, 4000); }
                                var cell = document.querySelector('.tc-peer-site-status-cell[data-peer-site-slug="' + btn.dataset.peerSiteSlug + '"]');
                                if ( cell ) {
                                    var postsEl = cell.querySelector('.tc-feed-stat-posts small');
                                    if ( postsEl ) { postsEl.textContent = '0 <?php echo esc_js( __( 'posts', 'termconnect' ) ); ?>, 0 <?php echo esc_js( __( 'categories', 'termconnect' ) ); ?>'; }
                                }
                            }
                        })
                        .catch(function() { btn.disabled = false; });
                });
            });
        })();
        </script>
        <?php else : ?>
        <p style="color:#888"><?php esc_html_e( 'No peer sites enabled yet. Enable peer sites from the list below.', 'termconnect' ); ?></p>
        <?php endif; ?>

        <p style="margin-top:1em">
            <button type="button" id="tc-lt-add-btn" class="button button-primary"><?php esc_html_e( '+ Add peer site', 'termconnect' ); ?></button>
        </p>

        <!-- ====== AVAILABLE TABLE (previously Disabled) ====== -->
        <h2 style="margin:2em 0 .5em"><?php esc_html_e( 'Available', 'termconnect' ); ?></h2>
        <?php if ( ! empty( $disabled_types ) ) : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" style="width:160px"><?php esc_html_e( 'Label', 'termconnect' ); ?></th>
                    <th scope="col" style="width:120px"><?php esc_html_e( 'Slug', 'termconnect' ); ?></th>
                    <th scope="col" style="width:150px"><?php esc_html_e( 'Description', 'termconnect' ); ?></th>
                    <th scope="col" style="width:100px"><?php esc_html_e( 'Platform', 'termconnect' ); ?></th>
                    <th scope="col" style="width:210px"><?php esc_html_e( 'Actions', 'termconnect' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $disabled_types as $slug => $type ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $type['label'] ); ?></strong>
                        <?php if ( ! empty( $type['built_in'] ) ) : ?>
                            <span style="color:#888;font-size:11px;display:block"><?php esc_html_e( 'Built-in', 'termconnect' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo esc_html( $slug ); ?></code></td>
                    <td style="font-size:12px"><?php echo esc_html( $type['description'] ); ?></td>
                    <td style="font-size:12px">
                        <?php
                        $plat = $type['platform'] ?? 'other';
                        if ( 'wordpress' === $plat ) {
                            esc_html_e( 'WordPress', 'termconnect' );
                        } elseif ( 'feed' === $plat ) {
                            esc_html_e( 'Feed', 'termconnect' );
                        } else {
                            echo '&#8212;';
                        }
                        ?>
                    </td>
                    <td>
                        <form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display:inline">
                            <?php wp_nonce_field( 'tc_peer_site_toggle' ); ?>
                            <input type="hidden" name="action" value="tc_peer_site_action">
                            <input type="hidden" name="tc_lt_action" value="toggle">
                            <input type="hidden" name="tc_peer_site_slug" value="<?php echo esc_attr( $slug ); ?>">
                            <button type="submit" class="button button-small"><?php esc_html_e( 'Enable', 'termconnect' ); ?></button>
                        </form>
                        <?php if ( empty( $type['built_in'] ) ) : ?>
                        &nbsp;
                        <button type="button" class="button button-small tc-lt-edit-btn"
                                data-type="<?php echo esc_attr( wp_json_encode( array(
                                    'slug'             => $slug,
                                    'label'            => $type['label'],
                                    'uri_prefix'       => $type['uri_prefix'],
                                    'description'      => $type['description'] ?? '',
                                    'platform'         => $type['platform'] ?? 'other',
                                    'remote_taxonomy'  => $type['remote_taxonomy'] ?? '',
                                    'poll_interval'    => (int) ( $type['poll_interval'] ?? 3600 ),
                                    'max_cached_posts' => (int) ( $type['max_cached_posts'] ?? 500 ),
                                    'show_on_archives' => ! empty( $type['show_on_archives'] ),
                                    'taxonomies'       => $type['taxonomies'] ?? array(),
                                ) ) ); ?>">
                            <?php esc_html_e( 'Edit', 'termconnect' ); ?>
                        </button>
                        &nbsp;
                        <form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display:inline"
                              onsubmit="return confirm('<?php esc_attr_e( 'Delete this peer site and remove all its term meta values?', 'termconnect' ); ?>')">
                            <?php wp_nonce_field( 'tc_peer_site_delete' ); ?>
                            <input type="hidden" name="action" value="tc_peer_site_action">
                            <input type="hidden" name="tc_lt_action" value="delete">
                            <input type="hidden" name="tc_peer_site_slug" value="<?php echo esc_attr( $slug ); ?>">
                            <button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'termconnect' ); ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- ====== ADD / EDIT PANE (floating) ====== -->
        <script>
        var tcLtPaneNonces = {
            add:  <?php echo wp_json_encode( wp_create_nonce( 'tc_peer_site_add' ) ); ?>,
            edit: <?php echo wp_json_encode( wp_create_nonce( 'tc_peer_site_edit' ) ); ?>
        };
        </script>
        <div id="tc-lt-pane-backdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:160000"></div>
        <div id="tc-lt-pane" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;width:min(680px,90vw);max-height:90vh;overflow-y:auto;z-index:160001;box-shadow:0 8px 32px rgba(0,0,0,.18)">
        <h2 id="tc-lt-pane-title" style="margin:0 0 16px"><?php esc_html_e( 'Add Peer Site', 'termconnect' ); ?></h2>
        <form id="tc-lt-pane-form" method="post" action="<?php echo esc_url( $action_url ); ?>">
            <input type="hidden" name="action" value="tc_peer_site_action">
            <input type="hidden" name="tc_lt_action" id="tc-lt-pane-action" value="add">
            <input type="hidden" name="_wpnonce" id="tc-lt-pane-nonce" value="">
            <input type="hidden" name="tc_peer_site_slug" id="tc-lt-pane-slug-val" value="">

            <table class="form-table" role="presentation">
                <tr id="tc-lt-pane-slug-row" style="display:none">
                    <th scope="row"><?php esc_html_e( 'Slug', 'termconnect' ); ?></th>
                    <td><code id="tc-lt-pane-slug-display"></code></td>
                </tr>
                <tr>
                    <th scope="row"><label for="tc-lt-pane-label"><?php esc_html_e( 'Label', 'termconnect' ); ?> <span class="required">*</span></label></th>
                    <td>
                        <input type="text" id="tc-lt-pane-label" name="tc_lt_label" class="regular-text" required>
                        <p class="description" id="tc-lt-pane-label-desc"><?php esc_html_e( 'Display name. Slug is auto-derived and cannot be changed later.', 'termconnect' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tc-lt-pane-uri" id="tc-lt-pane-uri-label"><?php esc_html_e( 'URI Prefix', 'termconnect' ); ?> <span class="required">*</span></label></th>
                    <td>
                        <input type="url" id="tc-lt-pane-uri" name="tc_lt_uri_prefix" class="regular-text" required
                               placeholder="https://example.com/concepts/">
                        <p class="description" id="tc-lt-pane-uri-desc"><?php esc_html_e( 'URL prefix prepended to the identifier to form the full URI.', 'termconnect' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="tc-lt-pane-description"><?php esc_html_e( 'Description', 'termconnect' ); ?></label></th>
                    <td>
                        <input type="text" id="tc-lt-pane-description" name="tc_lt_description" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Show on Archives', 'termconnect' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="tc_lt_show_on_archives" id="tc-lt-pane-show-on-archives" value="1" checked>
                            <?php esc_html_e( 'Include in "See also" section on term archive pages', 'termconnect' ); ?>
                        </label>
                    </td>
                </tr>
                <?php if ( ! empty( $taxonomy_options ) ) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Restrict to taxonomies', 'termconnect' ); ?></th>
                    <td>
                        <div id="tc-lt-pane-taxonomies">
                        <?php tc_render_taxonomy_checkboxes( 'tc_lt_taxonomies', array(), $taxonomy_options ); ?>
                        </div>
                        <p class="description"><?php esc_html_e( 'Leave all unchecked to allow any taxonomy.', 'termconnect' ); ?></p>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Platform', 'termconnect' ); ?> <span class="required">*</span></th>
                    <td>
                        <label style="margin-right:1.5em">
                            <input type="radio" name="tc_lt_platform" value="wordpress" id="tc-lt-pane-platform-wp" checked>
                            <?php esc_html_e( 'WordPress peer (REST API)', 'termconnect' ); ?>
                        </label>
                        <label style="margin-right:1.5em">
                            <input type="radio" name="tc_lt_platform" value="feed" id="tc-lt-pane-platform-feed">
                            <?php esc_html_e( 'RSS / Atom feed', 'termconnect' ); ?>
                        </label>
                        <label style="margin-right:1.5em">
                            <input type="radio" name="tc_lt_platform" value="mediawiki" id="tc-lt-pane-platform-mediawiki">
                            <?php esc_html_e( 'MediaWiki (identifier + autocomplete)', 'termconnect' ); ?>
                        </label>
                        <label>
                            <input type="radio" name="tc_lt_platform" value="other" id="tc-lt-pane-platform-other">
                            <?php esc_html_e( 'Custom identifier', 'termconnect' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'WordPress peers fetch related posts via REST API. Feed types poll an RSS/Atom feed URL. MediaWiki sites provide autocomplete via OpenSearch. Custom identifier stores external identifiers only.', 'termconnect' ); ?></p>
                    </td>
                </tr>
                <tr id="tc-lt-pane-remote-tax-row" style="display:none">
                    <th scope="row"><label for="tc-lt-pane-remote-tax"><?php esc_html_e( 'Remote taxonomy REST base', 'termconnect' ); ?></label></th>
                    <td>
                        <input type="text" id="tc-lt-pane-remote-tax" name="tc_lt_remote_taxonomy" class="regular-text"
                               placeholder="<?php esc_attr_e( 'e.g. topics (leave blank to use remote taxonomies with same slug as local ones)', 'termconnect' ); ?>">
                        <p class="description"><?php esc_html_e( 'REST base of the taxonomy on the peer site. Leave blank when the peer uses the same taxonomy slug as your site.', 'termconnect' ); ?></p>
                    </td>
                </tr>
                <tr id="tc-lt-pane-poll-row" style="display:none">
                    <th scope="row"><label for="tc-lt-pane-poll-interval"><?php esc_html_e( 'Poll interval (seconds)', 'termconnect' ); ?></label></th>
                    <td>
                        <input type="number" id="tc-lt-pane-poll-interval" name="tc_lt_poll_interval" class="small-text" min="300" max="86400" step="1" value="3600" disabled>
                        <p class="description"><?php esc_html_e( 'How often to poll the feed. Minimum 300 seconds, maximum 86400 (24 hours).', 'termconnect' ); ?></p>
                    </td>
                </tr>
                <tr id="tc-lt-pane-maxposts-row" style="display:none">
                    <th scope="row"><label for="tc-lt-pane-max-posts"><?php esc_html_e( 'Max cached posts', 'termconnect' ); ?></label></th>
                    <td>
                        <input type="number" id="tc-lt-pane-max-posts" name="tc_lt_max_cached_posts" class="small-text" min="50" max="5000" step="1" value="500" disabled>
                        <p class="description"><?php esc_html_e( 'Maximum posts to accumulate across polls. Oldest are pruned when exceeded.', 'termconnect' ); ?></p>
                    </td>
                </tr>
            </table>

            <div style="display:flex;gap:8px;align-items:center;margin-top:8px">
                <button type="submit" id="tc-lt-pane-submit" class="button button-primary"><?php esc_html_e( 'Add Peer Site', 'termconnect' ); ?></button>
                <button type="button" id="tc-lt-pane-cancel" class="button"><?php esc_html_e( 'Cancel', 'termconnect' ); ?></button>
            </div>
        </form>
        </div><!-- #tc-lt-pane -->
        <script>
        (function() {
            var radios      = document.querySelectorAll('[name="tc_lt_platform"]');
            var rowWP       = document.getElementById('tc-lt-pane-remote-tax-row');
            var rowPoll     = document.getElementById('tc-lt-pane-poll-row');
            var rowMaxPosts = document.getElementById('tc-lt-pane-maxposts-row');
            var uriInput    = document.getElementById('tc-lt-pane-uri');
            var uriLabel    = document.getElementById('tc-lt-pane-uri-label');
            var uriDesc     = document.getElementById('tc-lt-pane-uri-desc');
            var labelDesc   = document.getElementById('tc-lt-pane-label-desc');
            var uriLabels = {
                wordpress: '<?php echo esc_js( __( 'Peer site URL', 'termconnect' ) ); ?> <span class="required">*</span>',
                feed:      '<?php echo esc_js( __( 'Feed URL', 'termconnect' ) ); ?> <span class="required">*</span>',
                mediawiki: '<?php echo esc_js( __( 'Wiki URL', 'termconnect' ) ); ?> <span class="required">*</span>',
                other:     '<?php echo esc_js( __( 'URI Prefix', 'termconnect' ) ); ?> <span class="required">*</span>'
            };
            var uriPlaceholders = {
                wordpress: 'https://example.com',
                feed:      'https://example.com/feed/',
                mediawiki: 'https://wiki.example.com/wiki/',
                other:     'https://example.com/concepts/'
            };
            var uriDescs = {
                wordpress: '<?php echo esc_js( __( 'The peer site root URL. No path needed — the taxonomy archive path is derived automatically from the Remote taxonomy REST base.', 'termconnect' ) ); ?>',
                feed:      '<?php echo esc_js( __( 'The full RSS or Atom feed URL.', 'termconnect' ) ); ?>',
                mediawiki: '<?php echo esc_js( __( 'The wiki article URL prefix (e.g. https://wiki.example.com/wiki/). Autocomplete is provided automatically via the OpenSearch API.', 'termconnect' ) ); ?>',
                other:     '<?php echo esc_js( __( 'URL prefix prepended to the identifier to form the full URI.', 'termconnect' ) ); ?>'
            };

            function updatePlatform() {
                var sel    = document.querySelector('[name="tc_lt_platform"]:checked');
                var val    = sel ? sel.value : 'other';
                var isFeed = ( val === 'feed' );
                if ( rowWP ) { rowWP.style.display = ( val === 'wordpress' ) ? '' : 'none'; }
                if ( rowPoll ) {
                    rowPoll.style.display = isFeed ? '' : 'none';
                    rowPoll.querySelectorAll('input').forEach(function( inp ) { inp.disabled = ! isFeed; });
                }
                if ( rowMaxPosts ) {
                    rowMaxPosts.style.display = isFeed ? '' : 'none';
                    rowMaxPosts.querySelectorAll('input').forEach(function( inp ) { inp.disabled = ! isFeed; });
                }
                if ( uriLabel ) { uriLabel.innerHTML  = uriLabels[ val ] || uriLabels.other; }
                if ( uriInput ) { uriInput.placeholder = uriPlaceholders[ val ] || uriPlaceholders.other; }
                if ( uriDesc )  { uriDesc.textContent  = uriDescs[ val ] || uriDescs.other; }
            }
            radios.forEach(function( r ) { r.addEventListener('change', updatePlatform); });
            updatePlatform();

            // ---- Pane open / close ----
            var backdrop   = document.getElementById('tc-lt-pane-backdrop');
            var pane       = document.getElementById('tc-lt-pane');
            var addBtn     = document.getElementById('tc-lt-add-btn');
            var cancelBtn  = document.getElementById('tc-lt-pane-cancel');
            var paneIsEdit = false;

            function openPane() {
                if ( backdrop ) { backdrop.style.display = ''; }
                if ( pane )     { pane.style.display     = ''; }
            }
            function closePane() {
                if ( backdrop ) { backdrop.style.display = 'none'; }
                if ( pane )     { pane.style.display     = 'none'; }
            }

            // Built-in link types: only the taxonomy restriction and "show on
            // archives" are editable. Hide and disable the immutable fields (label,
            // URI, description, platform) so they neither show nor block submit.
            function setBuiltInMode( builtIn ) {
                [ 'tc-lt-pane-label', 'tc-lt-pane-uri', 'tc-lt-pane-description' ].forEach( function( id ) {
                    var el = document.getElementById( id );
                    if ( ! el ) { return; }
                    el.disabled = builtIn;
                    var tr = el.closest('tr');
                    if ( tr ) { tr.style.display = builtIn ? 'none' : ''; }
                } );
                var pwp = document.getElementById('tc-lt-pane-platform-wp');
                if ( pwp ) { var ptr = pwp.closest('tr'); if ( ptr ) { ptr.style.display = builtIn ? 'none' : ''; } }
                radios.forEach( function( r ) { r.disabled = builtIn; } );
            }

            function openPaneAdd() {
                paneIsEdit = false;
                document.getElementById('tc-lt-pane-nonce').value    = tcLtPaneNonces.add;
                document.getElementById('tc-lt-pane-action').value   = 'add';
                document.getElementById('tc-lt-pane-slug-val').value = '';
                document.getElementById('tc-lt-pane-slug-row').style.display = 'none';
                document.getElementById('tc-lt-pane-label').value       = '';
                document.getElementById('tc-lt-pane-uri').value         = '';
                document.getElementById('tc-lt-pane-description').value = '';
                var remTax = document.getElementById('tc-lt-pane-remote-tax');
                if ( remTax ) { remTax.value = ''; }
                var pollEl = document.getElementById('tc-lt-pane-poll-interval');
                if ( pollEl ) { pollEl.value = '3600'; }
                var maxEl = document.getElementById('tc-lt-pane-max-posts');
                if ( maxEl ) { maxEl.value = '500'; }
                document.getElementById('tc-lt-pane-show-on-archives').checked = true;
                document.getElementById('tc-lt-pane-platform-wp').checked = true;
                updatePlatform();
                setBuiltInMode( false );
                document.querySelectorAll('#tc-lt-pane-taxonomies input[type="checkbox"]').forEach(function( cb ) { cb.checked = false; });
                if ( labelDesc ) { labelDesc.style.display = ''; }
                document.getElementById('tc-lt-pane-title').textContent  = '<?php echo esc_js( __( 'Add Peer Site', 'termconnect' ) ); ?>';
                document.getElementById('tc-lt-pane-submit').textContent = '<?php echo esc_js( __( 'Add Peer Site', 'termconnect' ) ); ?>';
                openPane();
                if ( uriInput ) { uriInput.focus(); }
            }

            function openPaneEdit( d ) {
                paneIsEdit = true;
                document.getElementById('tc-lt-pane-nonce').value    = tcLtPaneNonces.edit;
                document.getElementById('tc-lt-pane-action').value   = 'edit';
                document.getElementById('tc-lt-pane-slug-val').value = d.slug;
                document.getElementById('tc-lt-pane-slug-row').style.display = '';
                document.getElementById('tc-lt-pane-slug-display').textContent = d.slug;
                document.getElementById('tc-lt-pane-label').value       = d.label || '';
                document.getElementById('tc-lt-pane-uri').value         = d.uri_prefix || '';
                document.getElementById('tc-lt-pane-description').value = d.description || '';
                var remTax = document.getElementById('tc-lt-pane-remote-tax');
                if ( remTax ) { remTax.value = d.remote_taxonomy || ''; }
                var pollEl = document.getElementById('tc-lt-pane-poll-interval');
                if ( pollEl ) { pollEl.value = d.poll_interval || 3600; }
                var maxEl = document.getElementById('tc-lt-pane-max-posts');
                if ( maxEl ) { maxEl.value = d.max_cached_posts || 500; }
                document.getElementById('tc-lt-pane-show-on-archives').checked = !! d.show_on_archives;
                radios.forEach(function( r ) { r.checked = ( r.value === d.platform ); });
                updatePlatform();
                document.querySelectorAll('#tc-lt-pane-taxonomies input[type="checkbox"]').forEach(function( cb ) {
                    cb.checked = ( d.taxonomies && d.taxonomies.indexOf( cb.value ) !== -1 );
                });
                if ( labelDesc ) { labelDesc.style.display = 'none'; }
                var builtIn = !! d.built_in;
                setBuiltInMode( builtIn );
                document.getElementById('tc-lt-pane-title').textContent  = builtIn
                    ? '<?php echo esc_js( __( 'Restrict to Taxonomies', 'termconnect' ) ); ?>'
                    : '<?php echo esc_js( __( 'Edit Peer Site Settings', 'termconnect' ) ); ?>';
                document.getElementById('tc-lt-pane-submit').textContent = '<?php echo esc_js( __( 'Save Changes', 'termconnect' ) ); ?>';
                openPane();
                if ( builtIn ) {
                    var firstTax = document.querySelector('#tc-lt-pane-taxonomies input[type="checkbox"]');
                    if ( firstTax ) { firstTax.focus(); }
                } else {
                    document.getElementById('tc-lt-pane-label').focus();
                }
            }

            if ( addBtn )   { addBtn.addEventListener('click',    openPaneAdd); }
            if ( cancelBtn ){ cancelBtn.addEventListener('click', closePane); }
            if ( backdrop ) { backdrop.addEventListener('click',  closePane); }
            document.addEventListener('keydown', function( e ) {
                if ( ( e.key === 'Escape' || e.keyCode === 27 ) && pane && pane.style.display !== 'none' ) { closePane(); }
            });
            document.addEventListener('click', function( e ) {
                var btn = e.target.closest('.tc-lt-edit-btn');
                if ( ! btn ) { return; }
                try { openPaneEdit( JSON.parse( btn.dataset.type ) ); } catch ( ex ) {}
            });

            // Pre-fill URI prefix from tc_suggest_url param and open the add pane.
            (function() {
                var params  = new URLSearchParams( window.location.search );
                var suggest = params.get('tc_suggest_url');
                if ( ! suggest || ! uriInput ) { return; }
                openPaneAdd();
                uriInput.value = suggest;
                uriInput.focus();
                var clean = new URL( window.location.href );
                clean.searchParams.delete('tc_suggest_url');
                history.replaceState( null, '', clean.toString() );
            })();

            // Ineligible links AJAX check (edit mode only).
            var paneForm  = document.getElementById('tc-lt-pane-form');
            var confirmed = false;
            if ( paneForm ) {
                paneForm.addEventListener('submit', function( e ) {
                    if ( confirmed || ! paneIsEdit ) { confirmed = false; return; }

                    var slugEl = document.getElementById('tc-lt-pane-slug-val');
                    if ( ! slugEl || ! slugEl.value ) { return; }

                    var taxes = Array.from(
                        paneForm.querySelectorAll('[name="tc_lt_taxonomies[]"]:checked')
                    ).map(function( el ) { return el.value; });

                    if ( taxes.length === 0 ) { return; }

                    e.preventDefault();
                    var submitBtn = document.getElementById('tc-lt-pane-submit');
                    if ( submitBtn ) { submitBtn.disabled = true; }

                    var data = new FormData();
                    data.append('action', 'tc_peer_site_count_ineligible');
                    data.append('nonce', tcLtPaneNonces.edit);
                    data.append('tc_peer_site_slug', slugEl.value);
                    taxes.forEach(function( t ) { data.append('tc_lt_taxonomies[]', t); });

                    fetch( ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
                        .then(function( r ) { return r.json(); })
                        .then(function( json ) {
                            if ( json.success && json.data.count > 0 ) {
                                var msg = json.data.message;
                                if ( ! window.confirm( msg ) ) {
                                    if ( submitBtn ) { submitBtn.disabled = false; }
                                    return;
                                }
                            }
                            confirmed = true;
                            paneForm.submit();
                        })
                        .catch(function() {
                            confirmed = true;
                            if ( submitBtn ) { submitBtn.disabled = false; }
                            paneForm.submit();
                        });
                });
            }
        })();
        </script>
    </div>
    <?php
}
