<?php
/**
 * TermConnect REST API.
 *
 * - tc_links field on WP taxonomy term endpoints: flat array of { url, platform,
 *   label, fetched_at } covering local and delegated links. Gated by X-TermConnect
 *   request header and the expose_links_api setting.
 *
 * - htax_term_uri query parameter: lets peer sites look up terms by any external
 *   link URI (e.g. ?htax_term_uri=https://www.wikidata.org/wiki/Q28865). Queries
 *   the _tc_uris index which covers all link types for all platforms.
 *
 * - X-TermConnect response header: advertises TC presence to peer sites on every
 *   REST response.
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', 'tc_register_tc_links_field' );

add_filter( 'rest_prepare_taxonomy', static function ( $response, $taxonomy ) {
    $data            = $response->get_data();
    $data['show_ui'] = (bool) $taxonomy->show_ui;

    // tc_content: mirrors the taxonomy's own 'public' flag. Public taxonomies are
    // content-facing (category, post_tag, custom content taxonomies, Term Groups).
    // Non-public ones are utility taxonomies (nav_menu, wp_pattern_category, mlo-category, etc.).
    $data['tc_content'] = (bool) $taxonomy->public;

    $response->set_data( $data );
    return $response;
}, 10, 2 );

/**
 * Map a taxonomy slug to the REST "object type" WP_REST_Terms_Controller actually
 * advertises for register_rest_field() purposes.
 *
 * WP_REST_Terms_Controller::get_item_schema() hardcodes the schema title for
 * post_tag to 'tag' (a holdover from the REST API's early naming) instead of the
 * taxonomy slug. register_rest_field()'s additional-fields lookup keys off that
 * schema title, not the slug passed to register_taxonomy(), so register_rest_field(
 * 'post_tag', ... ) is silently never applied to real responses. Every other
 * built-in and custom taxonomy's title matches its own slug.
 *
 * @since 1.0.0
 * @param string $taxonomy Taxonomy slug.
 * @return string REST object type to pass to register_rest_field().
 */
function tc_rest_field_object_type( string $taxonomy ): string {
    return 'post_tag' === $taxonomy ? 'tag' : $taxonomy;
}

/**
 * Register a tc_links field on all TermConnect-enabled taxonomy term REST endpoints.
 */
function tc_register_tc_links_field(): void {
    foreach ( TC_Settings::get_enabled_taxonomies() as $taxonomy ) {
        register_rest_field(
            tc_rest_field_object_type( $taxonomy ),
            'tc_links',
            array(
                'get_callback'    => 'tc_rest_get_tc_links_field',
                'update_callback' => null,
                'schema'          => array(
                    'description' => 'External links managed by TermConnect. Flat array combining local and delegated links with override logic applied.',
                    'type'        => array( 'array', 'null' ),
                    'context'     => array( 'view', 'embed' ),
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'url'        => array( 'type' => 'string' ),
                            'platform'   => array( 'type' => 'string' ),
                            'label'      => array( 'type' => 'string' ),
                            'fetched_at' => array( 'type' => 'integer' ),
                        ),
                    ),
                ),
            )
        );
    }
}

/**
 * Populate the tc_links REST field for a term.
 *
 * Returns null when the request lacks X-TermConnect, expose_links_api is disabled,
 * or the term has no links.
 *
 * @param array           $term_data  REST term data array.
 * @param string          $field_name Field name (unused).
 * @param WP_REST_Request $request    Current REST request.
 * @return array[]|null
 */
function tc_rest_get_tc_links_field( array $term_data, string $field_name, WP_REST_Request $request ): ?array {
    $options = get_option( 'tc_settings', array() );
    if ( empty( $options['expose_links_api'] ) ) {
        return null;
    }

    if ( ! $request->get_header( 'X-TermConnect' ) ) {
        return null;
    }

    $term_id = $term_data['id'] ?? 0;
    if ( ! $term_id ) {
        return null;
    }

    $term = get_term( (int) $term_id );
    return $term instanceof WP_Term ? tc_rest_build_links_data( $term ) : null;
}

// ==============================================================================
// htax_term_uri QUERY PARAMETER
// ==============================================================================

add_action( 'rest_api_init', static function () {
    foreach ( TC_Settings::get_enabled_taxonomies() as $taxonomy ) {
        add_filter( "rest_{$taxonomy}_query",             'tc_rest_link_filter_query',            10, 2 );
        add_filter( "rest_{$taxonomy}_collection_params", 'tc_rest_link_filter_collection_params' );
    }
} );

/**
 * Add htax_term_uri as a valid collection param on TermConnect-enabled taxonomy term endpoints.
 *
 * @param array $params Registered collection params.
 * @return array
 */
function tc_rest_link_filter_collection_params( array $params ): array {
    $params['htax_term_uri'] = array(
        'description' => 'Filter terms by full external link URI. TermConnect matches the URI against the _tc_uris index, which covers all link types (local and delegated).',
        'type'        => 'string',
        'default'     => '',
    );
    return $params;
}

/**
 * Apply htax_term_uri filter to term queries via the _tc_uris index.
 *
 * @param array           $args    WP_Term_Query args.
 * @param WP_REST_Request $request REST request.
 * @return array
 */
function tc_rest_link_filter_query( array $args, WP_REST_Request $request ): array {
    $uri = sanitize_url( rawurldecode( (string) ( $request->get_param( 'htax_term_uri' ) ?? '' ) ) );
    if ( '' === $uri ) {
        return $args;
    }
    $args['meta_query'][] = array(
        'key'   => '_tc_uris',
        'value' => $uri,
    );
    return $args;
}

// ==============================================================================
// X-TermConnect RESPONSE HEADER
// ==============================================================================

// Advertise TermConnect presence via a response header so peer sites can detect it.
add_filter( 'rest_post_dispatch', static function ( $result, $server ) {
    $server->send_header( 'X-TermConnect', TC_VERSION );
    return $result;
}, 10, 2 );

// ==============================================================================
// GROUP MEMBERS ENDPOINT — /wp-json/tc/v1/group-members
// ==============================================================================

add_action( 'rest_api_init', static function () {
    register_rest_route(
        'tc/v1',
        '/group-members',
        array(
            'methods'             => 'GET',
            'callback'            => 'tc_rest_group_members',
            'permission_callback' => '__return_true',
            'args'                => array(
                'group_slug' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_title',
                ),
            ),
        )
    );
} );

/**
 * Return the member terms of a local HyperTaxonomy group, for use by subscribing TC peers.
 *
 * Gated by X-TermConnect header. Returns 404 when HT is not active or the group does not exist.
 * Each member includes taxonomy, id, slug, name, description, parent (remote term ID), and
 * optionally tc_links when expose_links_api is enabled.
 *
 * @since 1.0.0
 */
function tc_rest_group_members( WP_REST_Request $request ): WP_REST_Response|WP_Error {
    if ( ! $request->get_header( 'X-TermConnect' ) ) {
        return new WP_Error( 'tc_forbidden', __( 'X-TermConnect header required.', 'termconnect' ), array( 'status' => 403 ) );
    }

    if ( ! function_exists( 'htax_get_terms_in_groups' ) ) {
        return new WP_Error( 'tc_ht_required', __( 'HyperTaxonomy not active.', 'termconnect' ), array( 'status' => 404 ) );
    }

    $group_slug = $request->get_param( 'group_slug' );
    $group      = get_term_by( 'slug', $group_slug, 'group' );
    if ( ! $group || is_wp_error( $group ) ) {
        return new WP_Error( 'tc_not_found', __( 'Group not found.', 'termconnect' ), array( 'status' => 404 ) );
    }

    if ( function_exists( 'htax_is_term_hidden' ) && htax_is_term_hidden( $group->term_id ) && ! htax_can_see_hidden_terms( 'group' ) ) {
        return new WP_Error( 'tc_forbidden', __( 'Group not available.', 'termconnect' ), array( 'status' => 403 ) );
    }

    $options      = get_option( 'tc_settings', array() );
    $expose_links = ! empty( $options['expose_links_api'] );
    $members      = array();

    foreach ( TC_Settings::get_enabled_taxonomies() as $taxonomy ) {
        $terms = htax_get_terms_in_groups( array( $group->term_id ), $taxonomy );
        if ( empty( $terms ) ) {
            continue;
        }
        // Hidden member terms are withheld from callers HyperTaxonomy doesn't let see them,
        // matching its REST term endpoints.
        $skip_hidden = function_exists( 'htax_is_term_hidden' ) && ! htax_can_see_hidden_terms( $taxonomy );
        foreach ( $terms as $term ) {
            if ( $skip_hidden && htax_is_term_hidden( $term->term_id ) ) {
                continue;
            }
            $parent_slug = '';
            if ( $term->parent ) {
                $parent_term = get_term( $term->parent, $taxonomy );
                if ( $parent_term instanceof WP_Term ) {
                    $parent_slug = $parent_term->slug;
                }
            }
            $member = array(
                'taxonomy'    => $taxonomy,
                'id'          => $term->term_id,
                'slug'        => $term->slug,
                'name'        => $term->name,
                'description' => $term->description,
                'parent'      => $term->parent,
                'parent_slug' => $parent_slug,
            );
            if ( $expose_links ) {
                $links = tc_rest_build_links_data( $term );
                if ( null !== $links ) {
                    $member['tc_links'] = $links;
                }
            }
            $members[] = $member;
        }
    }

    return new WP_REST_Response( array(
        'group_id'   => $group->term_id,
        'group_slug' => $group->slug,
        'group_name' => $group->name,
        'members'    => $members,
    ) );
}
