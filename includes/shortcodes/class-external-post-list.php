<?php
/**
 * External Post List Shortcodes
 *
 * [tc_peer_posts] — Fetches related posts from configured WordPress peer
 * sites. HT-enabled peers receive scored requests (htax_includes/htax_term_weights,
 * plus htax_expand when peer_expand is on) and return htax_score in post responses;
 * standard WordPress peers receive standard taxonomy params and are scored
 * client-side using the same per-term weighting. When no
 * explicit remote slug is configured for a peer link type, TC auto-resolves
 * the remote term via shared external identifiers (htax_link REST param).
 * Results from all peers are merged into a single flat list ordered by orderby.
 *
 * TC_Shortcode_PeerPosts also exposes collect_posts_for_terms() so HyperTaxonomy's
 * generic external-posts API (htax_get_term_external_posts) can source peer/feed
 * content for [ht_related_posts] external mode.
 *
 * @package TermConnect
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Replace template tokens in a string using normalized external post data.
 *
 * Tokens: {title}, {url}, {date}, {modified}, {excerpt}, {label}, {hostname}, {origin}.
 * All output is HTML-escaped. {label} falls back to {hostname} when no link-type label is found.
 *
 * @param string $text          Template string containing {token} placeholders.
 * @param array  $post          Normalized external post array.
 * @param array  $origin_labels Map of origin URL → link-type label (may be empty).
 * @return string
 */
function tc_process_external_post_placeholders( string $text, array $post, array $origin_labels ): string {
    if ( '' === $text ) {
        return '';
    }

    $hostname = ! empty( $post['origin'] ) ? (string) wp_parse_url( $post['origin'], PHP_URL_HOST ) : '';
    $label    = ! empty( $post['origin'] ) && isset( $origin_labels[ $post['origin'] ] )
        ? $origin_labels[ $post['origin'] ]
        : $hostname;

    $date     = '';
    $modified = '';
    if ( ! empty( $post['date_gmt'] ) ) {
        $ts = strtotime( $post['date_gmt'] );
        if ( $ts ) {
            $date = date_i18n( get_option( 'date_format' ), $ts );
        }
    }
    if ( ! empty( $post['modified_gmt'] ) ) {
        $ts = strtotime( $post['modified_gmt'] );
        if ( $ts ) {
            $modified = date_i18n( get_option( 'date_format' ), $ts );
        }
    }

    $replacements = array(
        '{title}'    => esc_html( $post['title']   ?? '' ),
        '{url}'      => esc_url( $post['url']       ?? '' ),
        '{date}'     => esc_html( $date ),
        '{modified}' => esc_html( $modified ),
        '{excerpt}'  => esc_html( $post['excerpt'] ?? '' ),
        '{label}'    => esc_html( $label ),
        '{hostname}' => esc_html( $hostname ),
        '{origin}'   => esc_url( $post['origin']   ?? '' ),
        '{author}'   => esc_html( $post['author']  ?? '' ),
    );

    return str_replace( array_keys( $replacements ), array_values( $replacements ), $text );
}

// ===========================================================================
// [tc_peer_posts]
// ===========================================================================

class TC_Shortcode_PeerPosts {

    public function __construct() {
        add_shortcode( 'tc_peer_posts', array( $this, 'shortcode_handler' ) );
        add_action( 'wp_ajax_tc_external_load_more', array( $this, 'ajax_load_more' ) );
        add_action( 'wp_ajax_nopriv_tc_external_load_more', array( $this, 'ajax_load_more' ) );
    }

    /**
     * Shared singleton instance.
     *
     * Lets other components (e.g. TC_Shortcode_PeerBrowse) reuse the public
     * collect/sort/render methods without constructing a second instance, which
     * would double-register the shortcode and AJAX hooks.
     *
     * @return self
     */
    public static function instance(): self {
        static $instance = null;
        if ( null === $instance ) {
            $instance = new self();
        }
        return $instance;
    }

    /**
     * Default attribute values shared between shortcode and block contexts.
     *
     * @return array
     */
    protected function get_defaults(): array {
        return array(
            'link_types'             => '',
            'platforms'              => '',
            'taxonomy'               => '',
            'match'                  => 'any',
            'scope'                  => 'peers',
            'orderby'                => 'score',
            'per_page'               => '5',
            'max_results'            => '20',
            'foaf_depth'             => '0',
            'require_ht'             => 'false',
            'include_synonyms'       => 'true',
            'include_related'        => 'false',
            'peer_expand'            => 'false',
            'show_thumbnail'         => 'false',
            'show_excerpt'           => 'true',
            'image_size'             => 'medium',
            'columns'                => '1',
            'format'                 => 'div',
            'template'               => 'default',
            'meta'                   => '{date} · {label}',
            'prepend'                => '',
            'append'                 => '',
            'cache_ttl'              => '',
            'no_results_message'     => '',
            'class'                  => '',
        );
    }

    /**
     * Shortcode handler — guards singular context then delegates to fetch_and_render.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string Rendered HTML or empty string.
     */
    public function shortcode_handler( $atts ) {
        if ( ! is_singular() || ! get_the_ID() ) {
            return '';
        }
        $atts = shortcode_atts( $this->get_defaults(), $atts, 'tc_peer_posts' );
        return $this->fetch_and_render( get_the_ID(), $atts );
    }

    /**
     * Core fetch-and-render logic, usable without the is_singular() guard.
     *
     * Called by shortcode_handler (after the guard) and by the Gutenberg block
     * render callback (which sets up the post context itself).
     *
     * @param int   $post_id Current post ID.
     * @param array $atts    Shortcode/block attributes (already merged with defaults).
     * @return string Rendered HTML.
     */
    public function fetch_and_render( int $post_id, array $atts ): string {
        $atts   = wp_parse_args( $atts, $this->get_defaults() );
        $p      = $this->parse_atts( $atts );
        $offset = max( 0, (int) ( $atts['offset'] ?? 0 ) );

        $all_posts = $this->collect_blended( $post_id, $p );

        if ( empty( $all_posts ) ) {
            return $p['no_results_message'] ? '<p>' . esc_html( $p['no_results_message'] ) . '</p>' : '';
        }

        $all_posts = $this->sort_posts( $all_posts, $p['orderby'] );
        if ( $p['max_results'] > 0 ) {
            $all_posts = array_slice( $all_posts, 0, $p['max_results'] );
        }
        $has_more   = count( $all_posts ) > $offset + $p['per_page'];
        $page_posts = array_slice( $all_posts, $offset, $p['per_page'] );

        if ( empty( $page_posts ) ) {
            return $p['no_results_message'] ? '<p>' . esc_html( $p['no_results_message'] ) . '</p>' : '';
        }

        if ( $has_more ) {
            $this->enqueue_scripts();
        }

        return $this->render( $page_posts, $p['class'], $p['show_thumbnail'], $p['show_excerpt'], $p['meta'], $p['prepend'], $p['append'], $has_more ? $offset + $p['per_page'] : -1, $post_id, $atts );
    }

    /**
     * Fetch peer/feed posts as normalized data for an explicit set of term IDs
     * (rather than a post's assigned terms), without rendering.
     *
     * Used by the HyperTaxonomy `htax_term_external_posts` provider so a single
     * concept term gets the full collection — explicit peer links, shared-identifier
     * and synonym auto-matching, feeds, and (when requested) FOAF — honoring the
     * same `$atts` (notably `platforms`, `foaf_depth`, `link_types`).
     *
     * @param int[] $term_ids Term IDs to collect for.
     * @param array $atts     Shortcode/block attributes.
     * @return array Normalized posts (unsorted).
     */
    public function collect_posts_for_terms( array $term_ids, array $atts ): array {
        $atts = wp_parse_args( $atts, $this->get_defaults() );
        $p    = $this->parse_atts( $atts );
        return $this->fetch_posts_for_atts( 0, $p, array_map( 'intval', $term_ids ) );
    }

    /**
     * Collect the post's peer results, optionally blended with local posts
     * (scope="all"), deduplicated by URL.
     *
     * Local blending happens only here (the user-facing render path), never in
     * collect_posts_for_terms(), which feeds HyperTaxonomy's
     * external-posts provider, which must stay peers-only so [ht_related_posts]
     * doesn't double-count local results.
     *
     * @param int   $post_id
     * @param array $p Parsed attrs from parse_atts().
     * @return array Normalized records (unsorted).
     */
    private function collect_blended( int $post_id, array $p ): array {
        $peers = $this->fetch_posts_for_atts( $post_id, $p );
        $local = ( 'all' === ( $p['scope'] ?? 'peers' ) ) ? $this->collect_local_for_post( $post_id, $p ) : array();
        return tc_dedupe_posts_by_url( array_merge( $local, $peers ) );
    }

    /**
     * Collect local posts that share the current post's terms (excluding it),
     * normalized to the shared record shape. Used for scope="all".
     *
     * @param int   $post_id
     * @param array $p
     * @return array
     */
    private function collect_local_for_post( int $post_id, array $p ): array {
        if ( ! $post_id ) {
            return array();
        }

        $taxonomies = ( '' !== $p['taxonomy'] ) ? array( $p['taxonomy'] ) : TC_Settings::get_enabled_taxonomies();

        $tax_terms = array();
        foreach ( $taxonomies as $tax ) {
            if ( ! taxonomy_exists( $tax ) ) {
                continue;
            }
            $ids = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $ids ) && ! empty( $ids ) ) {
                $tax_terms[ $tax ] = array_map( 'intval', $ids );
            }
        }

        // Drop terms hidden from the current user before selecting or scoring.
        $tax_terms = tc_filter_hidden_terms( $tax_terms );
        if ( empty( $tax_terms ) ) {
            return array();
        }

        $limit = $p['max_results'] > 0 ? $p['max_results'] : max( 20, $p['per_page'] * 10 );

        // With HyperTaxonomy active, select via relationship expansion so local
        // recall matches its archives / [ht_related_posts] (inclusion-, group-, and
        // hierarchy-related posts, not just directly-tagged ones).
        $expansions = tc_local_term_expansions( $tax_terms );

        $records = tc_collect_local_posts(
            $tax_terms,
            $p['match'],
            $limit,
            $p['show_thumbnail'],
            $p['image_size'] ?? 'medium',
            array( $post_id ),
            $expansions
        );

        // Relevance-score local results via HyperTaxonomy when available, so a
        // peer-first sort (e.g. peer:asc,relevance:desc) ranks the local group by
        // HT relevance. Normalized to 0–1 within the local set. Without HT, local
        // scores stay 0 and the local group falls back to the secondary sort key.
        if ( ! empty( $records ) && function_exists( 'htax_get_local_post_scores' ) ) {
            $term_ids = array();
            foreach ( $tax_terms as $ids ) {
                $term_ids = array_merge( $term_ids, $ids );
            }
            // Weight each of the source post's terms by how central it is to the post,
            // so local results sharing its main topics rank above incidental matches.
            $source_weights = function_exists( 'htax_get_post_term_strengths' )
                ? htax_get_post_term_strengths( $post_id, $term_ids )
                : array();
            $scores = htax_get_local_post_scores( $term_ids, array(
                'exclude'      => array( $post_id ),
                'term_weights' => $source_weights,
            ) );
            if ( ! empty( $scores ) ) {
                $max = max( $scores );
                $max = $max > 0 ? $max : 1.0;
                foreach ( $records as &$r ) {
                    $r['score'] = round( (float) ( $scores[ (int) $r['id'] ] ?? 0.0 ) / $max, 4 );
                }
                unset( $r );
            }
        }

        return $records;
    }

    /**
     * Parse merged shortcode attributes into a typed array for internal use.
     *
     * Called by both fetch_and_render() and ajax_load_more() to avoid duplicating
     * the attr-to-scalar conversion and settings reads.
     *
     * @param array $atts Already wp_parse_args'd against get_defaults().
     * @return array Typed scalars keyed by short names.
     */
    private function parse_atts( array $atts ): array {
        $settings       = get_option( 'tc_settings', array() );
        $foaf_depth_req = max( 0, (int) $atts['foaf_depth'] );

        // Peer platform types to include. Empty = all supported (currently WordPress + feeds).
        $platforms = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $atts['platforms'] ?? '' ) ) ) ) );
        if ( empty( $platforms ) ) {
            $platforms = array( 'wordpress', 'feed' );
        }

        return array(
            'link_types'         => $atts['link_types'],
            'platforms'          => $platforms,
            'taxonomy'           => $atts['taxonomy'],
            'per_page'           => max( 1, (int) $atts['per_page'] ),
            'max_results'        => max( 0, (int) $atts['max_results'] ),
            'match'              => in_array( $atts['match'], array( 'any', 'all' ), true ) ? $atts['match'] : 'any',
            'scope'              => in_array( $atts['scope'] ?? 'peers', array( 'peers', 'all' ), true ) ? $atts['scope'] : 'peers',
            'orderby'            => $atts['orderby'],
            'image_size'         => sanitize_key( $atts['image_size'] ?? 'medium' ),
            'format'             => in_array( ( $atts['format'] ?? 'div' ), array( 'ul', 'ol', 'div' ), true ) ? $atts['format'] : 'div',
            'template'           => sanitize_html_class( $atts['template'] ?? 'default' ),
            'show_thumbnail'     => 'true' === $atts['show_thumbnail'],
            'show_excerpt'       => 'true' === $atts['show_excerpt'],
            'meta'               => sanitize_text_field( $atts['meta'] ),
            'prepend'            => $atts['prepend'],
            'append'             => $atts['append'],
            'require_ht'         => 'true' === $atts['require_ht'],
            'include_synonyms'   => 'false' !== $atts['include_synonyms'],
            'include_related'    => 'true' === $atts['include_related'],
            'expand'             => 'true' === ( $atts['peer_expand'] ?? 'false' ),
            'cache_ttl'          => '' !== $atts['cache_ttl']
                ? max( 60, (int) $atts['cache_ttl'] )
                : (int) ( $settings['external_posts_cache_ttl'] ?? 3600 ),
            'max_foaf'           => min( $foaf_depth_req, (int) ( $settings['max_foaf_depth'] ?? 1 ) ),
            'no_results_message' => $atts['no_results_message'],
            'class'              => $atts['class'],
        );
    }

    /**
     * Run the per-origin fetch loop and return all collected posts, unsorted.
     *
     * This is the shared core of both fetch_and_render() and ajax_load_more().
     * Returns an empty array when no tuples are configured or all fetches fail.
     *
     * @param int        $post_id Current post ID (used only when $explicit_term_ids is null).
     * @param array      $p       Parsed attrs from parse_atts().
     * @param int[]|null $explicit_term_ids Collect for these term IDs instead of the post's terms.
     * @return array Normalized posts from all origins.
     */
    private function fetch_posts_for_atts( int $post_id, array $p, ?array $explicit_term_ids = null ): array {
        $tuples = $this->collect_tuples( $post_id, $p['link_types'], $p['taxonomy'], $explicit_term_ids );

        // Restrict to the requested peer platform types (default: all supported).
        $tuples = array_filter( $tuples, static fn( $t ) => in_array( $t['platform'] ?? '', $p['platforms'], true ) );

        if ( empty( $tuples ) ) {
            return array();
        }

        // Separate feed tuples — they use cached posts, not the REST API loop below.
        $feed_tuples = array_filter( $tuples, static fn( $t ) => ( $t['platform'] ?? '' ) === 'feed' );
        $tuples      = array_filter( $tuples, static fn( $t ) => ( $t['platform'] ?? '' ) !== 'feed' );

        $all_posts = $this->fetch_feed_posts( $feed_tuples );

        // Group by origin AND remote taxonomy: each peer is queried once per remote
        // taxonomy (the standard ?tags=/?categories= filter param is single-taxonomy,
        // and OR semantics across taxonomies can't be expressed in one request).
        $origin_groups = array();
        foreach ( $tuples as $tuple ) {
            $origin_groups[ $tuple['origin'] . '|' . $tuple['remote_taxonomy'] ][] = $tuple;
        }

        $visited    = array(); // origins already queried — seeds FOAF dedup.
        $fetch_size = $p['max_results'] > 0 ? $p['max_results'] : max( 20, $p['per_page'] * 10 );

        // Source-post term centrality (post-context only): weight each of the post's
        // terms by how central it is, so peers rank content sharing the post's main
        // topics higher. Keyed by local term ID; folded into htax_term_weights per
        // term in resolve_term_ids(). No source post for the explicit-term-IDs path
        // (peer browse / external-posts provider) → equal weights.
        $source_strengths = ( null === $explicit_term_ids && $post_id > 0 && function_exists( 'htax_get_post_term_strengths' ) )
            ? htax_get_post_term_strengths( $post_id )
            : array();

        // Per-taxonomy fetch results, collected for the weighted cross-taxonomy
        // combine in combine_peer_fetches(): a post matching several of a peer's
        // taxonomies is scored as one bag of query terms.
        $peer_fetches = array(); // list of [ origin => string, w => float, posts => array ]

        foreach ( $origin_groups as $group_tuples ) {
            $origin   = $group_tuples[0]['origin'];
            $taxonomy = $group_tuples[0]['remote_taxonomy'];
            $peer_site_slug  = $group_tuples[0]['peer_site_slug'];

            if ( tc_external_is_blacklisted( $origin ) || ! tc_external_validate_origin( $origin ) ) {
                continue;
            }

            [ $term_id_map, $id_to_slug, $and_groups, $term_weights ] = $this->resolve_term_ids( $group_tuples, $origin, $taxonomy, $p['include_synonyms'], $p['include_related'], $source_strengths );
            if ( empty( $term_id_map ) ) {
                continue;
            }

            // W_i: total query-term weight for this taxonomy = sum of primary-term
            // weights (default 1.0 each) — the same denominator the peer scores by.
            $w_i = 0.0;
            foreach ( array_keys( $term_id_map ) as $primary_id ) {
                $w_i += isset( $term_weights[ $primary_id ] ) ? (float) $term_weights[ $primary_id ] : 1.0;
            }
            if ( $w_i <= 0 ) {
                $w_i = 1.0;
            }

            $include_ids = array_merge( ...array_values( $term_id_map ) );
            $all_ids     = array_unique( array_merge( array_keys( $term_id_map ), $include_ids ) );
            $is_ht       = tc_external_is_ht_enabled( $origin );

            if ( $is_ht ) {
                $posts = tc_external_fetch_scored_posts( $origin, $taxonomy, $term_id_map, $p['match'], $term_weights, $p['show_thumbnail'], $fetch_size, $p['cache_ttl'], $peer_site_slug, $p['expand'] );
            } elseif ( ! $p['require_ht'] ) {
                if ( 'relevance' === $this->primary_orderby_field( $p['orderby'] ) ) {
                    $keyword = (string) get_the_title( $post_id );
                    $posts   = tc_external_fetch_relevance_posts( $origin, $taxonomy, $all_ids, $keyword, $p['show_thumbnail'], $fetch_size, $p['cache_ttl'], $peer_site_slug );
                } else {
                    $posts = tc_external_fetch_posts( $origin, $taxonomy, $all_ids, $p['show_thumbnail'], $fetch_size, $p['cache_ttl'], $peer_site_slug );
                }
                if ( 'all' === $p['match'] ) {
                    $posts = $this->filter_and_match( $posts, $and_groups );
                }
                $posts = tc_external_score_posts( $posts, $term_id_map, $id_to_slug, $term_weights );
            } else {
                continue;
            }

            // Hold this taxonomy's results for the per-peer weighted combine below.
            $peer_fetches[] = array( 'origin' => $origin, 'w' => $w_i, 'posts' => $posts );

            // FOAF expansion runs once per origin (on first encounter), seeded with
            // the origin already marked visited so it isn't rediscovered.
            $first_seen = ! in_array( $origin, $visited, true );
            if ( $first_seen ) {
                $visited[] = $origin;
            }
            if ( $first_seen && $p['max_foaf'] > 0 && $is_ht ) {
                $term_taxonomy_map = array();
                foreach ( array_keys( $term_id_map ) as $rid ) {
                    $term_taxonomy_map[ $rid ] = $taxonomy;
                }
                $foaf_posts = $this->fetch_foaf( $origin, $term_taxonomy_map, $p['match'], $taxonomy, $p['show_thumbnail'], $fetch_size, $p['cache_ttl'], 1, $p['max_foaf'], $visited, $p['expand'] );
                array_push( $all_posts, ...$foaf_posts );
            }
        }

        array_push( $all_posts, ...$this->combine_peer_fetches( $peer_fetches ) );

        return $all_posts;
    }

    /**
     * Combine a peer's per-taxonomy fetch results into one score per post.
     *
     * Each peer is queried once per remote taxonomy (the standard taxonomy filter
     * is single-taxonomy). A post returned for several of a peer's taxonomies is
     * scored as one bag of query terms:
     *
     *     combined = Σ( s_i × W_i ) / Σ W_i   (clamped to [0,1])
     *
     * where s_i is the post's normalized score in taxonomy i and W_i the total
     * query-term weight sent for that taxonomy. A post absent from a taxonomy
     * contributes 0 to the numerator while that taxonomy's W_i still counts in the
     * denominator, so matching in more taxonomies raises the score exactly like
     * matching more terms within one taxonomy. Records are deduplicated by URL
     * within each peer (origin); the first record seen for a URL is the
     * representative, with only its score replaced by the combined value.
     *
     * @param array $fetches List of [ 'origin' => string, 'w' => float, 'posts' => array ].
     * @return array Combined, scored records (one per origin + URL).
     */
    private function combine_peer_fetches( array $fetches ): array {
        $acc = array(); // origin => [ total_w => float, num => [url=>float], rec => [url=>record] ]

        foreach ( $fetches as $fetch ) {
            $origin = (string) ( $fetch['origin'] ?? '' );
            $w      = (float) ( $fetch['w'] ?? 1.0 );
            if ( $w <= 0 ) {
                $w = 1.0;
            }
            if ( ! isset( $acc[ $origin ] ) ) {
                $acc[ $origin ] = array( 'total_w' => 0.0, 'num' => array(), 'rec' => array() );
            }
            $acc[ $origin ]['total_w'] += $w;

            foreach ( (array) ( $fetch['posts'] ?? array() ) as $post ) {
                $url = $post['url'] ?? '';
                if ( '' === $url ) {
                    continue;
                }
                $s_i = (float) ( $post['score'] ?? 0.0 );
                $acc[ $origin ]['num'][ $url ] = ( $acc[ $origin ]['num'][ $url ] ?? 0.0 ) + ( $s_i * $w );
                if ( ! isset( $acc[ $origin ]['rec'][ $url ] ) ) {
                    $acc[ $origin ]['rec'][ $url ] = $post;
                }
            }
        }

        $out = array();
        foreach ( $acc as $peer ) {
            $total_w = $peer['total_w'] > 0 ? $peer['total_w'] : 1.0;
            foreach ( $peer['rec'] as $url => $rec ) {
                $rec['score'] = round( min( 1.0, ( $peer['num'][ $url ] ?? 0.0 ) / $total_w ), 4 );
                $out[]        = $rec;
            }
        }

        return $out;
    }

    /**
     * Enqueue the shared post-list base stylesheet (grid layout + uniform
     * thumbnails). Shared by [tc_peer_posts] and [tc_peer_browse].
     */
    protected function enqueue_styles(): void {
        if ( ! wp_style_is( 'tc-post-list', 'enqueued' ) ) {
            wp_enqueue_style(
                'tc-post-list',
                TC_PLUGIN_URL . 'assets/css/tc-post-list.css',
                array(),
                TC_VERSION
            );
        }
    }

    /**
     * Enqueue the load-more JS on the frontend.
     */
    protected function enqueue_scripts(): void {
        if ( wp_script_is( 'tc-external-posts', 'enqueued' ) || wp_script_is( 'tc-external-posts', 'done' ) ) {
            return;
        }

        wp_enqueue_script(
            'tc-external-posts',
            TC_PLUGIN_URL . 'assets/js/tc-external-posts.js',
            array( 'jquery' ),
            TC_VERSION,
            true
        );

        wp_localize_script( 'tc-external-posts', 'TC_ExtPosts', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'tc_external_load_more' ),
        ) );
    }

    /**
     * AJAX handler: load the next page of external posts from the cache.
     *
     * Expects POST fields: post_id, offset, atts (JSON-encoded shortcode attrs).
     */
    public function ajax_load_more(): void {
        check_ajax_referer( 'tc_external_load_more', 'nonce' );

        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        $offset  = max( 0, (int) ( $_POST['offset'] ?? 0 ) );

        if ( ! $post_id || ! get_post( $post_id ) ) {
            wp_send_json_error( array( 'message' => 'Invalid post.' ) );
            return;
        }

        $raw_atts = ( isset( $_POST['atts'] ) && is_string( $_POST['atts'] ) )
            ? tc_external_json_decode( wp_unslash( $_POST['atts'] ) )
            : array();
        if ( ! is_array( $raw_atts ) ) {
            $raw_atts = array();
        }

        $defaults    = $this->get_defaults();
        $html_keys   = array( 'prepend', 'append', 'meta' );
        $atts        = array();
        foreach ( $defaults as $key => $default ) {
            if ( ! isset( $raw_atts[ $key ] ) ) {
                $atts[ $key ] = $default;
            } elseif ( in_array( $key, $html_keys, true ) ) {
                $atts[ $key ] = wp_kses_post( (string) $raw_atts[ $key ] );
            } else {
                $atts[ $key ] = sanitize_text_field( (string) $raw_atts[ $key ] );
            }
        }

        $p         = $this->parse_atts( $atts );
        $all_posts = $this->collect_blended( $post_id, $p );

        if ( empty( $all_posts ) ) {
            wp_send_json_success( array( 'html' => '', 'has_more' => false ) );
            return;
        }

        $all_posts = $this->sort_posts( $all_posts, $p['orderby'] );
        if ( $p['max_results'] > 0 ) {
            $all_posts = array_slice( $all_posts, 0, $p['max_results'] );
        }
        $has_more   = count( $all_posts ) > $offset + $p['per_page'];
        $page_posts = array_slice( $all_posts, $offset, $p['per_page'] );

        wp_send_json_success( array(
            'html'        => $this->render_items( $page_posts, $p['show_thumbnail'], $p['show_excerpt'], $p['meta'], $p['prepend'], $p['append'], $p['format'], $p['template'] ),
            'has_more'    => $has_more,
            'next_offset' => $offset + $p['per_page'],
        ) );
    }

    /**
     * Fetch and score cached posts from all feed link types referenced in $feed_tuples.
     *
     * Groups tuples by peer_site_slug, extracts the category slug from each tuple's
     * remote_slug identifier, reads the accumulated post cache, and scores using
     * tc_feed_score_posts(). Returns an empty array when no cached posts exist.
     *
     * @param array[] $feed_tuples Tuples with platform='feed' from collect_tuples().
     * @return array Normalized scored posts with origin set.
     */
    private function fetch_feed_posts( array $feed_tuples ): array {
        if ( empty( $feed_tuples ) ) {
            return array();
        }

        // Group by peer_site_slug; each peer_site_slug corresponds to one feed URL.
        $by_peer_site = array();
        foreach ( $feed_tuples as $tuple ) {
            $by_peer_site[ $tuple['peer_site_slug'] ][] = $tuple;
        }

        $all_posts = array();
        foreach ( $by_peer_site as $peer_site_slug => $link_type_tuples ) {
            $posts = tc_feed_get_cached_posts( $peer_site_slug );
            if ( empty( $posts ) ) {
                continue;
            }

            // Build the set of category slugs this post's terms are mapped to.
            $category_slugs = array();
            foreach ( $link_type_tuples as $tuple ) {
                $parsed = tc_feed_parse_identifier( $tuple['remote_slug'] );
                if ( '' !== $parsed['slug'] ) {
                    $category_slugs[] = $parsed['slug'];
                }
            }
            $category_slugs = array_unique( $category_slugs );

            $type      = tc_get_link_type( $peer_site_slug );
            $feed_url  = $type ? ( $type['uri_prefix'] ?? '' ) : '';
            $scored    = tc_feed_score_posts( $posts, $category_slugs, 1.0, $feed_url );
            $all_posts = array_merge( $all_posts, $scored );
        }

        return $all_posts;
    }

    /**
     * Collect (origin, taxonomy, peer_site_slug, term_id, remote_slug) tuples from
     * the current post's terms and their link type meta.
     *
     * @param int        $post_id   Current post ID (used only when $explicit_term_ids is null).
     * @param string     $link_type_filter CSV of link type slugs to restrict to; empty = all.
     * @param string     $tax_filter Restrict to one taxonomy; empty = all.
     * @param int[]|null $explicit_term_ids When provided, build tuples from these term IDs
     *                   (filtered per taxonomy) instead of the post's assigned terms. Lets the
     *                   same collection run for an arbitrary concept term, not just a post.
     * @return array[]
     */
    public function collect_tuples( int $post_id, string $link_type_filter, string $tax_filter, ?array $explicit_term_ids = null ): array {
        $peer_types = tc_get_platform_link_types( 'wordpress' );
        $feed_types = tc_get_platform_link_types( 'feed' );

        if ( '' !== $link_type_filter ) {
            $allowed    = array_map( 'trim', explode( ',', $link_type_filter ) );
            $peer_types = array_intersect_key( $peer_types, array_flip( $allowed ) );
            $feed_types = array_intersect_key( $feed_types, array_flip( $allowed ) );
        }

        $tuples = array();

        // WordPress peer tuples.
        foreach ( $peer_types as $peer_site_slug => $link_type ) {
            $origin = tc_external_get_site_origin( $link_type['uri_prefix'] );
            if ( '' === $origin ) {
                continue;
            }

            $taxes = ! empty( $link_type['taxonomies'] ) ? $link_type['taxonomies'] : TC_Settings::get_enabled_taxonomies();
            if ( '' !== $tax_filter ) {
                $taxes = in_array( $tax_filter, $taxes, true ) ? array( $tax_filter ) : array();
            }

            foreach ( $taxes as $taxonomy ) {
                $post_terms = $this->terms_in_taxonomy( $post_id, $taxonomy, $explicit_term_ids );
                foreach ( $post_terms as $term_id ) {
                    $raw_peer_meta = get_term_meta( $term_id, '_tc_link_' . $peer_site_slug, true );
                    $remote_slug   = is_array( $raw_peer_meta )
                        ? (string) ( $raw_peer_meta['id'] ?? '' )
                        : (string) $raw_peer_meta;
                    if ( '' === $remote_slug ) {
                        continue;
                    }
                    $remote_taxonomy = tc_wp_peer_remote_taxonomy( $link_type, $taxonomy );
                    $tuples[]        = array(
                        'origin'          => $origin,
                        'taxonomy'        => $taxonomy,
                        'remote_taxonomy' => $remote_taxonomy,
                        'peer_site_slug'  => $peer_site_slug,
                        'term_id'         => (int) $term_id,
                        'remote_slug'     => $remote_slug,
                        'platform'        => 'wordpress',
                    );
                }
            }
        }

        // Feed tuples: each tuple carries the category identifier directly (no remote ID resolution needed).
        foreach ( $feed_types as $peer_site_slug => $link_type ) {
            $feed_url = $link_type['uri_prefix'];
            if ( '' === $feed_url ) {
                continue;
            }

            $taxes = ! empty( $link_type['taxonomies'] ) ? $link_type['taxonomies'] : TC_Settings::get_enabled_taxonomies();
            if ( '' !== $tax_filter ) {
                $taxes = in_array( $tax_filter, $taxes, true ) ? array( $tax_filter ) : array();
            }

            foreach ( $taxes as $taxonomy ) {
                $post_terms = $this->terms_in_taxonomy( $post_id, $taxonomy, $explicit_term_ids );
                foreach ( $post_terms as $term_id ) {
                    $raw_feed_meta = get_term_meta( $term_id, '_tc_link_' . $peer_site_slug, true );
                    $raw_id        = is_array( $raw_feed_meta )
                        ? (string) ( $raw_feed_meta['id'] ?? '' )
                        : (string) $raw_feed_meta;
                    if ( '' === $raw_id ) {
                        continue;
                    }
                    $tuples[] = array(
                        'origin'          => $feed_url,
                        'taxonomy'        => $taxonomy,
                        'remote_taxonomy' => '',
                        'peer_site_slug'         => $peer_site_slug,
                        'term_id'         => (int) $term_id,
                        'remote_slug'     => (string) $raw_id,
                        'platform'        => 'feed',
                    );
                }
            }
        }

        // KB-based auto-matching: for WordPress peer link types where the local term has
        // no explicit remote slug configured, try to match via shared external identifier.
        // This lets two sites share content automatically when both have the same Wikidata QID,
        // GeoNames ID, etc. on equivalent terms — without manual per-peer slug configuration.
        foreach ( $peer_types as $peer_site_slug => $link_type ) {
            $origin = tc_external_get_site_origin( $link_type['uri_prefix'] );
            if ( '' === $origin ) {
                continue;
            }

            $taxes = ! empty( $link_type['taxonomies'] ) ? $link_type['taxonomies'] : TC_Settings::get_enabled_taxonomies();
            if ( '' !== $tax_filter ) {
                $taxes = in_array( $tax_filter, $taxes, true ) ? array( $tax_filter ) : array();
            }

            foreach ( $taxes as $taxonomy ) {
                $post_terms = $this->terms_in_taxonomy( $post_id, $taxonomy, $explicit_term_ids );
                foreach ( $post_terms as $term_id ) {
                    // Skip if this term already has an explicit remote slug for this peer.
                    $remote_slug = get_term_meta( $term_id, '_tc_link_' . $peer_site_slug, true );
                    if ( '' !== $remote_slug && false !== $remote_slug ) {
                        continue;
                    }
                    // Collect URI index for this term (covers local and delegated links).
                    $cached = get_transient( 'tc_uris_' . $term_id );
                    $uris   = is_array( $cached ) ? $cached : get_term_meta( $term_id, '_tc_uris' );
                    $uris   = array_values( array_filter( (array) $uris, 'is_string' ) );
                    if ( empty( $uris ) ) {
                        continue;
                    }
                    $remote_taxonomy = tc_wp_peer_remote_taxonomy( $link_type, $taxonomy );
                    $tuples[]        = array(
                        'origin'          => $origin,
                        'taxonomy'        => $taxonomy,
                        'remote_taxonomy' => $remote_taxonomy,
                        'peer_site_slug'  => $peer_site_slug,
                        'term_id'         => (int) $term_id,
                        'remote_slug'     => '',
                        'uris'            => $uris,
                        'platform'        => 'wordpress',
                    );
                }
            }
        }

        return $tuples;
    }

    /**
     * Term IDs to build tuples from, for a given taxonomy.
     *
     * When an explicit term-ID set is supplied (per-term collection), it is filtered
     * to the ones in $taxonomy. Otherwise the current post's assigned terms in
     * $taxonomy are used (the original post-driven behavior).
     *
     * @param int        $post_id
     * @param string     $taxonomy
     * @param int[]|null $explicit_term_ids
     * @return int[]
     */
    private function terms_in_taxonomy( int $post_id, string $taxonomy, ?array $explicit_term_ids ): array {
        if ( null !== $explicit_term_ids ) {
            $out = array();
            foreach ( $explicit_term_ids as $tid ) {
                $term = get_term( (int) $tid );
                if ( $term instanceof WP_Term && $term->taxonomy === $taxonomy ) {
                    $out[] = (int) $tid;
                }
            }
            return $out;
        }

        $ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
        return is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
    }

    /**
     * Resolve a group of tuples to a term_id_map, id_to_slug index, and AND-mode groups.
     *
     * When include_synonyms is true, local synonyms are looked up via their link type meta
     * and promoted to additional primary entries in term_id_map (scored at weight_direct).
     * When include_related is true, non-synonym local includes are added as htax_includes
     * values under their original primary (scored at weight_included_direct).
     *
     * The returned $and_groups is a list of remote-ID arrays, one per original query term.
     * Each array contains the original primary plus any promoted synonym IDs. filter_and_match()
     * uses this so that AND mode requires one match per original term, not per expanded ID.
     *
     * @param array[] $group_tuples      Tuples sharing the same origin + remote_taxonomy.
     * @param string  $origin
     * @param string  $taxonomy          Remote taxonomy REST base.
     * @param bool    $include_synonyms  Promote local synonyms to additional primary entries.
     * @param bool    $include_related   Add non-synonym local includes as tc_includes entries.
     * @param array   $source_strengths  Optional map of local term_id => source-post centrality
     *                                   (0–1). Each term's weight is multiplied by its source
     *                                   strength so the peer ranks the post's central topics
     *                                   higher; synonyms/includes inherit their primary's factor.
     *                                   Empty = no source weighting (e.g. peer browse / provider).
     * @return array [ term_id_map, id_to_slug, and_groups, term_weights ]
     */
    public function resolve_term_ids(
        array $group_tuples,
        string $origin,
        string $taxonomy,
        bool $include_synonyms = true,
        bool $include_related = false,
        array $source_strengths = array()
    ): array {
        $term_id_map  = array();
        $id_to_slug   = array();
        $and_groups   = array();
        $term_weights = array(); // remote_id => weight (from local HT relationship weights)
        $weight_cache = array(); // local taxonomy => [ direct, container ]

        foreach ( $group_tuples as $tuple ) {
            if ( '' !== $tuple['remote_slug'] ) {
                $primary_id = tc_external_resolve_term_id( $origin, $taxonomy, $tuple['remote_slug'] );
            } elseif ( ! empty( $tuple['uris'] ) ) {
                $primary_id = $this->resolve_term_id_by_identifier( $origin, $taxonomy, $tuple['uris'] );
            } else {
                $primary_id = null;
            }
            if ( null === $primary_id ) {
                continue;
            }

            $id_to_slug[ $primary_id ]  = $tuple['remote_slug'];
            $term_id_map[ $primary_id ] = array();

            // Per-term weights from this term's local taxonomy HT relationship
            // weights (explicit tier): primaries + synonyms at the direct weight,
            // includes at the container weight. Defaults to flat 1.0 without HT.
            $local_tax = '';
            $lt        = get_term( (int) $tuple['term_id'] );
            if ( $lt instanceof WP_Term ) {
                $local_tax = $lt->taxonomy;
            }
            if ( ! isset( $weight_cache[ $local_tax ] ) ) {
                $w = ( '' !== $local_tax && function_exists( 'htax_get_relevance_weights' ) )
                    ? htax_get_relevance_weights( $local_tax )
                    : array();
                $direct    = isset( $w['direct']['explicit'] ) ? (float) $w['direct']['explicit'] : 1.0;
                $container = isset( $w['container']['explicit'] ) ? (float) $w['container']['explicit'] : 0.7;
                $weight_cache[ $local_tax ] = array(
                    'direct'    => $direct > 0 ? $direct : 1.0,
                    'container' => $container > 0 ? $container : 0.7,
                );
            }
            $w_direct    = $weight_cache[ $local_tax ]['direct'];
            $w_container = $weight_cache[ $local_tax ]['container'];

            // Source-post centrality factor for this term (1.0 when not provided).
            // Synonyms and includes of this term inherit the same factor.
            $src_factor = isset( $source_strengths[ (int) $tuple['term_id'] ] )
                ? (float) $source_strengths[ (int) $tuple['term_id'] ]
                : 1.0;

            $term_weights[ $primary_id ] = $w_direct * $src_factor;

            // Detect synonyms once — needed for both promotion and exclusion from related.
            $synonym_terms    = ( $include_synonyms || $include_related )
                ? ( function_exists( 'htax_get_synonym_terms' ) ? htax_get_synonym_terms( $tuple['term_id'] ) : array() )
                : array();
            $synonym_term_ids = array_keys( $synonym_terms );

            // Track all remote IDs that are equivalent to this original primary for AND mode.
            $group = array( $primary_id );

            // Promote local synonyms to additional primary entries (scored at weight_direct).
            if ( $include_synonyms ) {
                foreach ( $synonym_terms as $syn_term_id => $syn_term ) {
                    $syn_slug = get_term_meta( (int) $syn_term_id, '_tc_link_' . $tuple['peer_site_slug'], true );
                    if ( '' === $syn_slug || false === $syn_slug ) {
                        continue;
                    }
                    $syn_id = tc_external_resolve_term_id( $origin, $taxonomy, (string) $syn_slug );
                    if ( null === $syn_id || isset( $term_id_map[ $syn_id ] ) ) {
                        continue;
                    }
                    $id_to_slug[ $syn_id ]   = (string) $syn_slug;
                    $term_id_map[ $syn_id ]  = array();
                    $term_weights[ $syn_id ] = $w_direct * $src_factor;
                    $group[]                 = $syn_id;
                }
            }

            $and_groups[] = $group;

            // Add non-synonym local includes as tc_includes (scored at weight_included_direct).
            if ( $include_related ) {
                $included = function_exists( 'htax_get_included_terms' ) ? htax_get_included_terms( $tuple['term_id'] ) : array();
                foreach ( $included as $inc_term_id => $inc_term_obj ) {
                    if ( in_array( (int) $inc_term_id, $synonym_term_ids, true ) ) {
                        continue; // Synonyms are handled above.
                    }
                    $inc_slug = get_term_meta( (int) $inc_term_id, '_tc_link_' . $tuple['peer_site_slug'], true );
                    if ( '' === $inc_slug || false === $inc_slug ) {
                        continue;
                    }
                    $inc_id = tc_external_resolve_term_id( $origin, $taxonomy, (string) $inc_slug );
                    if ( null === $inc_id ) {
                        continue;
                    }
                    $id_to_slug[ $inc_id ]        = (string) $inc_slug;
                    $term_id_map[ $primary_id ][] = $inc_id;
                    if ( ! isset( $term_weights[ $inc_id ] ) ) {
                        $term_weights[ $inc_id ] = $w_container * $src_factor;
                    }
                }
            }
        }

        return array( $term_id_map, $id_to_slug, $and_groups, $term_weights );
    }

    /**
     * Resolve a remote term ID by querying a peer site for a matching identifier URI.
     *
     * Queries /wp-json/wp/v2/{taxonomy}?htax_term_uri={uri} on the peer site for each URI
     * in the local term's _tc_uris index (Wikidata, VIAF, custom link types, etc.).
     * Returns the first match found, or null if none.
     *
     * htax_term_uri is a TC-registered query param; a peer without TC silently ignores
     * it and returns its default, unfiltered collection instead of an error, so the
     * response can't be told apart from a real one-result match without first confirming
     * the peer runs TC.
     *
     * @param string   $origin   Peer site origin (scheme://host).
     * @param string   $taxonomy Remote taxonomy REST base.
     * @param string[] $uris     Identifier URIs from the local term's _tc_uris index.
     * @return int|null Remote term ID, or null if not found.
     */
    private function resolve_term_id_by_identifier( string $origin, string $taxonomy, array $uris ): ?int {
        if ( ! tc_external_is_tc_enabled( $origin ) ) {
            return null;
        }
        $rest_base = tc_external_get_taxonomy_rest_base( $taxonomy );
        foreach ( $uris as $uri ) {
            if ( ! is_string( $uri ) || '' === $uri ) {
                continue;
            }
            $url = $origin . '/wp-json/wp/v2/' . $rest_base . '?' . http_build_query( array(
                'htax_term_uri' => $uri,
                '_fields'       => 'id',
                'per_page'      => 1,
            ) );
            $args = tc_external_request_args( 5 );
            if ( tc_external_is_ht_enabled( $origin ) ) {
                $args['headers']['X-HyperTaxonomy'] = defined( 'HTAX_VERSION' ) ? HTAX_VERSION : '1.0.0';
            }
            $response = wp_remote_get( $url, $args );
            if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
                continue;
            }
            $body = tc_external_json_decode( wp_remote_retrieve_body( $response ) );
            if ( is_array( $body ) && ! empty( $body[0]['id'] ) ) {
                return (int) $body[0]['id'];
            }
        }
        return null;
    }

    /**
     * Client-side AND filter for non-HT peers.
     *
     * Each element of $and_groups is an array of remote term IDs that are considered
     * equivalent (an original primary plus its promoted synonyms). A post satisfies
     * one group if it has at least one member of that group in its tag_ids ∪ cat_ids.
     * A post passes the filter only when it satisfies every group.
     *
     * @param array   $posts      Normalized posts.
     * @param int[][] $and_groups Groups of equivalent remote term IDs from resolve_term_ids().
     * @return array
     */
    public function filter_and_match( array $posts, array $and_groups ): array {
        return array_values( array_filter( $posts, function ( $post ) use ( $and_groups ) {
            $post_term_ids = array_merge( $post['tag_ids'], $post['cat_ids'] );
            foreach ( $and_groups as $group ) {
                $satisfied = false;
                foreach ( $group as $gid ) {
                    if ( in_array( (int) $gid, $post_term_ids, true ) ) {
                        $satisfied = true;
                        break;
                    }
                }
                if ( ! $satisfied ) {
                    return false;
                }
            }
            return true;
        } ) );
    }

    /**
     * Recursively fetch posts from FOAF-discovered peer sites.
     *
     * @param string $origin            Site whose terms to inspect for FOAF candidates.
     * @param array  $term_taxonomy_map [ remote_term_id => taxonomy ]
     * @param string $match
     * @param string $taxonomy          Taxonomy slug used when resolving FOAF term slugs.
     * @param bool   $show_thumbnail
     * @param int    $fetch_size
     * @param int    $cache_ttl
     * @param int    $depth             Current recursion depth (starts at 1).
     * @param int    $max_depth
     * @param array  $visited           Origins already fetched this traversal.
     * @param bool   $expand            Pass htax_expand to discovered HT peers.
     * @return array Normalized posts with foaf=true.
     *
     * Transitively-discovered peers are scored flat (each matched term = 1.0):
     * the requester's per-term weights are keyed to the originating peer's term
     * IDs and don't transfer to a different discovered peer.
     */
    public function fetch_foaf(
        string $origin,
        array $term_taxonomy_map,
        string $match,
        string $taxonomy,
        bool $show_thumbnail,
        int $fetch_size,
        int $cache_ttl,
        int $depth,
        int $max_depth,
        array $visited,
        bool $expand = false
    ): array {
        if ( $depth > $max_depth ) {
            return array();
        }

        $candidates = tc_external_get_foaf_candidates( $origin, $term_taxonomy_map, $visited );
        $foaf_posts = array();

        foreach ( $candidates as $candidate ) {
            $peer_origin = $candidate['origin'];
            $peer_slug   = $candidate['slug'];

            if ( in_array( $peer_origin, $visited, true ) ) {
                continue;
            }

            $peer_id = tc_external_resolve_term_id( $peer_origin, $taxonomy, $peer_slug );
            if ( null === $peer_id ) {
                $visited[] = $peer_origin;
                continue;
            }

            $visited[]        = $peer_origin;
            $peer_map         = array( $peer_id => array() );
            $peer_id_to_slug  = array( $peer_id => $peer_slug );

            $is_ht = tc_external_is_ht_enabled( $peer_origin );
            if ( $is_ht ) {
                $posts = tc_external_fetch_scored_posts( $peer_origin, $taxonomy, $peer_map, $match, array(), $show_thumbnail, $fetch_size, $cache_ttl, '', $expand );
            } else {
                $posts = tc_external_fetch_posts( $peer_origin, $taxonomy, array( $peer_id ), $show_thumbnail, $fetch_size, $cache_ttl );
                $posts = tc_external_score_posts( $posts, $peer_map, $peer_id_to_slug, array() );
            }

            foreach ( $posts as &$post ) {
                $post['foaf_depth'] = $depth;
            }
            unset( $post );

            $foaf_posts = array_merge( $foaf_posts, $posts );

            if ( $depth < $max_depth && $is_ht ) {
                $next_map   = array( $peer_id => $taxonomy );
                $foaf_posts = array_merge( $foaf_posts, $this->fetch_foaf( $peer_origin, $next_map, $match, $taxonomy, $show_thumbnail, $fetch_size, $cache_ttl, $depth + 1, $max_depth, $visited, $expand ) );
            }
        }

        return $foaf_posts;
    }

    /**
     * Sort a flat normalized post array by a multi-key orderby string.
     *
     * Accepts "field:dir,field:dir,…" (mirroring HyperTaxonomy's orderby syntax).
     * Keys are applied in order until one breaks the tie. Supported fields:
     * peer, score (alias relevance), date, modified, title, foaf_depth. Omitted
     * directions default per field (date/modified/score/foaf_depth → desc;
     * title/peer → asc).
     *
     * `peer` groups results by source — the local site first, then peers in the
     * Peer Sites admin order — so e.g. "peer:asc,relevance:desc" ranks each
     * source's own results by its (locally-meaningful) relevance.
     *
     * @param array  $posts
     * @param string $orderby
     * @return array
     */
    public function sort_posts( array $posts, string $orderby ): array {
        $keys = $this->parse_orderby_keys( $orderby );
        if ( empty( $keys ) ) {
            $keys = array( array( 'score', 'desc' ) );
        }

        $peer_rank = null; // Built lazily only if a 'peer' key is present.

        usort( $posts, function ( $a, $b ) use ( $keys, &$peer_rank ) {
            foreach ( $keys as $k ) {
                list( $field, $dir ) = $k;
                $cmp = 0;
                switch ( $field ) {
                    case 'date':
                        $cmp = strcmp( (string) ( $a['date_gmt'] ?? '' ), (string) ( $b['date_gmt'] ?? '' ) );
                        break;
                    case 'modified':
                        // Fall back to publish date when a source has no modified date.
                        $am  = ( '' !== ( $a['modified_gmt'] ?? '' ) ) ? $a['modified_gmt'] : ( $a['date_gmt'] ?? '' );
                        $bm  = ( '' !== ( $b['modified_gmt'] ?? '' ) ) ? $b['modified_gmt'] : ( $b['date_gmt'] ?? '' );
                        $cmp = strcmp( (string) $am, (string) $bm );
                        break;
                    case 'title':
                        $cmp = strcasecmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) );
                        break;
                    case 'foaf_depth':
                        $cmp = ( (int) ( $a['foaf_depth'] ?? 0 ) ) <=> ( (int) ( $b['foaf_depth'] ?? 0 ) );
                        break;
                    case 'peer':
                        if ( null === $peer_rank ) {
                            $peer_rank = $this->peer_rank_map();
                        }
                        $cmp = $this->peer_rank_of( $a, $peer_rank ) <=> $this->peer_rank_of( $b, $peer_rank );
                        break;
                    case 'score':
                    case 'relevance':
                    default:
                        $sa  = (float) ( $a['score'] ?? 0 );
                        $sb  = (float) ( $b['score'] ?? 0 );
                        $cmp = ( abs( $sa - $sb ) < 0.0001 ) ? 0 : ( $sa < $sb ? -1 : 1 );
                        break;
                }
                if ( 0 !== $cmp ) {
                    return ( 'desc' === $dir ) ? -$cmp : $cmp;
                }
            }

            // Final stable tiebreak: score desc, then date desc.
            $sa = (float) ( $a['score'] ?? 0 );
            $sb = (float) ( $b['score'] ?? 0 );
            if ( abs( $sa - $sb ) >= 0.0001 ) {
                return $sa < $sb ? 1 : -1;
            }
            return strcmp( (string) ( $b['date_gmt'] ?? '' ), (string) ( $a['date_gmt'] ?? '' ) );
        } );

        return $posts;
    }

    /**
     * Parse a "field:dir,field:dir" orderby string into an ordered list of
     * [ field, 'asc'|'desc' ] pairs, applying per-field default directions.
     *
     * @param string $orderby
     * @return array<int,array{0:string,1:string}>
     */
    private function parse_orderby_keys( string $orderby ): array {
        $valid    = array( 'peer', 'score', 'relevance', 'date', 'modified', 'title', 'foaf_depth' );
        $asc_default = array( 'title', 'peer' );
        $keys     = array();

        foreach ( explode( ',', $orderby ) as $part ) {
            $part = trim( $part );
            if ( '' === $part ) {
                continue;
            }
            if ( false !== strpos( $part, ':' ) ) {
                list( $field, $dir ) = explode( ':', $part, 2 );
                $field = strtolower( trim( $field ) );
                $dir   = strtolower( trim( $dir ) );
                $dir   = ( 'asc' === $dir ) ? 'asc' : 'desc';
            } else {
                $field = strtolower( $part );
                $dir   = in_array( $field, $asc_default, true ) ? 'asc' : 'desc';
            }
            if ( in_array( $field, $valid, true ) ) {
                $keys[] = array( $field, $dir );
            }
        }
        return $keys;
    }

    /**
     * The primary (first) field of a multi-key orderby string, lowercased.
     *
     * @param string $orderby
     * @return string Field name, or '' if none.
     */
    private function primary_orderby_field( string $orderby ): string {
        $keys = $this->parse_orderby_keys( $orderby );
        return $keys[0][0] ?? '';
    }

    /**
     * Build an origin → rank map for `peer` ordering: peers in the Peer Sites
     * admin order (WordPress peers first, then feeds). Local posts rank ahead of
     * all peers (see peer_rank_of()).
     *
     * @return array<string,int>
     */
    private function peer_rank_map(): array {
        $map = array();
        $i   = 0;
        foreach ( array( 'wordpress', 'feed' ) as $platform ) {
            foreach ( tc_get_platform_link_types( $platform ) as $type ) {
                $origin = tc_external_get_site_origin( $type['uri_prefix'] ?? '' );
                if ( '' !== $origin && ! isset( $map[ $origin ] ) ) {
                    $map[ $origin ] = $i++;
                }
            }
        }
        return $map;
    }

    /**
     * Rank a record for `peer` ordering: local first (-1), then by the origin→rank
     * map, with unknown origins last.
     *
     * @param array              $rec
     * @param array<string,int>  $peer_rank
     * @return int
     */
    private function peer_rank_of( array $rec, array $peer_rank ): int {
        if ( ! empty( $rec['is_local'] ) ) {
            return -1;
        }
        $origin = (string) ( $rec['origin'] ?? '' );
        return $peer_rank[ $origin ] ?? PHP_INT_MAX;
    }

    /**
     * Render individual post items as HTML article elements (no wrapper).
     *
     * Used by both the full render() and the AJAX load-more handler so the
     * outer wrapper is only added on initial page load.
     *
     * @param array  $posts
     * @param bool   $show_thumbnail
     * @param bool   $show_excerpt
     * @param string $meta_tpl      Meta line template; empty = hide.
     * @param string $prepend_tpl   Template injected before each item; empty = skip.
     * @param string $append_tpl    Template injected after each item; empty = skip.
     * @param string $format        'div' (article cards), 'ul'/'ol' (linked-title list items).
     * @param string $template      Style variant class suffix (tc-result-{template}).
     * @return string
     */
    public function render_items(
        array $posts,
        bool $show_thumbnail,
        bool $show_excerpt,
        string $meta_tpl = '{date} · {label}',
        string $prepend_tpl = '',
        string $append_tpl = '',
        string $format = 'div',
        string $template = 'default'
    ): string {
        $template = sanitize_html_class( '' !== $template ? $template : 'default' );

        // ul/ol formats render compact linked-title list items (no thumbnail/
        // excerpt/meta), matching HyperTaxonomy's list layouts.
        if ( 'ul' === $format || 'ol' === $format ) {
            $html = '';
            foreach ( $posts as $post ) {
                $is_local = ! empty( $post['is_local'] );
                $rel      = $is_local ? '' : ' target="_blank" rel="noopener noreferrer"';
                $cls      = 'tc-result-item tc-result-' . $template . ' ' . ( $is_local ? 'tc-result-local' : 'tc-result-external' );
                if ( ! empty( $post['foaf_depth'] ) ) {
                    $cls .= ' tc-foaf-result tc-foaf-depth-' . (int) $post['foaf_depth'];
                }
                $html .= '<li class="' . esc_attr( $cls ) . '"><a href="' . esc_url( $post['url'] ?? '' ) . '"' . $rel . '>'
                       . esc_html( $post['title'] ?? '' ) . '</a></li>';
            }
            return $html;
        }
        // Build origin → link-type label map lazily — only when a label/hostname/origin
        // token appears in one of the three templates.
        $origin_labels = array();
        $needs_labels  = ( '' !== $meta_tpl || '' !== $prepend_tpl || '' !== $append_tpl )
            && (
                strpos( $meta_tpl . $prepend_tpl . $append_tpl, '{label}' )    !== false
                || strpos( $meta_tpl . $prepend_tpl . $append_tpl, '{hostname}' ) !== false
                || strpos( $meta_tpl . $prepend_tpl . $append_tpl, '{origin}' )  !== false
            );
        if ( $needs_labels ) {
            foreach ( tc_get_platform_link_types( 'wordpress' ) as $link_type ) {
                $origin = tc_external_get_site_origin( $link_type['uri_prefix'] );
                if ( '' !== $origin ) {
                    $origin_labels[ $origin ] = $link_type['label'];
                }
            }
        }

        $html = '';

        foreach ( $posts as $post ) {
            $is_local   = ! empty( $post['is_local'] );
            $item_class = 'tc-result-item tc-result-' . $template . ' ' . ( $is_local ? 'tc-result-local' : 'tc-result-external' );
            if ( $post['foaf_depth'] > 0 ) {
                $item_class .= ' tc-foaf-result tc-foaf-depth-' . (int) $post['foaf_depth'];
            }

            $html .= '<article class="' . esc_attr( $item_class ) . '">';

            if ( '' !== $prepend_tpl ) {
                $html .= tc_process_external_post_placeholders( $prepend_tpl, $post, $origin_labels );
            }

            if ( $show_thumbnail && ! empty( $post['thumbnail_url'] ) ) {
                $html .= '<div class="tc-result-thumbnail">';
                $html .= '<img src="' . esc_url( $post['thumbnail_url'] ) . '"';
                if ( $post['thumbnail_width'] ) {
                    $html .= ' width="' . (int) $post['thumbnail_width'] . '"';
                }
                if ( $post['thumbnail_height'] ) {
                    $html .= ' height="' . (int) $post['thumbnail_height'] . '"';
                }
                $html .= ' alt="' . esc_attr( $post['title'] ) . '" loading="lazy">';
                $html .= '</div>';
            }

            $html .= '<div class="tc-result-content">';
            $html .= '<h3 class="tc-result-title">';
            if ( $is_local ) {
                $html .= '<a href="' . esc_url( $post['url'] ) . '">';
            } else {
                $html .= '<a href="' . esc_url( $post['url'] ) . '" target="_blank" rel="noopener noreferrer">';
            }
            $html .= esc_html( $post['title'] );
            $html .= '</a></h3>';

            if ( '' !== $meta_tpl ) {
                $html .= '<div class="tc-result-meta">' . tc_process_external_post_placeholders( $meta_tpl, $post, $origin_labels ) . '</div>';
            }

            if ( $show_excerpt && ! empty( $post['excerpt'] ) ) {
                $html .= '<div class="tc-result-excerpt">' . esc_html( $post['excerpt'] ) . '</div>';
            }

            $html .= '</div>';

            if ( '' !== $append_tpl ) {
                $html .= tc_process_external_post_placeholders( $append_tpl, $post, $origin_labels );
            }

            $html .= '</article>';
        }

        return $html;
    }

    /**
     * Render the full post list wrapper, items, and optional load-more button.
     *
     * @param array  $posts
     * @param string $extra_class   Extra CSS class on the wrapper.
     * @param bool   $show_thumbnail
     * @param bool   $show_excerpt
     * @param string $meta_tpl      Meta line template; empty = hide.
     * @param string $prepend_tpl   Template injected before each item; empty = skip.
     * @param string $append_tpl    Template injected after each item; empty = skip.
     * @param int    $next_offset   Next page offset, or -1 when no more pages.
     * @param int    $post_id       Current post ID (for load-more AJAX).
     * @param array  $atts          Full shortcode atts (for load-more AJAX).
     * @return string
     */
    public function render(
        array $posts,
        string $extra_class,
        bool $show_thumbnail,
        bool $show_excerpt,
        string $meta_tpl = '{date} · {label}',
        string $prepend_tpl = '',
        string $append_tpl = '',
        int $next_offset = -1,
        int $post_id = 0,
        array $atts = array()
    ): string {
        $this->enqueue_styles();

        $wrapper_class = 'tc-post-list tc-external-posts';
        if ( '' !== $extra_class ) {
            $wrapper_class .= ' ' . $extra_class;
        }

        $columns     = max( 1, (int) ( $atts['columns'] ?? 1 ) );
        $image_size  = sanitize_key( $atts['image_size'] ?? 'medium' );
        $items_style = tc_post_list_items_style( $columns, $image_size );
        $format      = in_array( ( $atts['format'] ?? 'div' ), array( 'ul', 'ol', 'div' ), true ) ? $atts['format'] : 'div';
        $template    = sanitize_html_class( $atts['template'] ?? 'default' );
        $items_tag   = ( 'ul' === $format || 'ol' === $format ) ? $format : 'div';

        $html  = '<div class="' . esc_attr( $wrapper_class ) . '">';
        $html .= '<' . $items_tag . ' class="tc-post-list-items"' . ( '' !== $items_style ? ' style="' . esc_attr( $items_style ) . '"' : '' ) . '>';
        $html .= $this->render_items( $posts, $show_thumbnail, $show_excerpt, $meta_tpl, $prepend_tpl, $append_tpl, $format, $template );
        $html .= '</' . $items_tag . '>';

        if ( $next_offset >= 0 && $post_id > 0 ) {
            $serializable = $atts;
            unset( $serializable['offset'] );
            $html .= '<div class="tc-pagination">';
            $html .= '<button type="button" class="tc-load-more"'
                . ' data-post-id="' . (int) $post_id . '"'
                . ' data-offset="' . (int) $next_offset . '"'
                . ' data-atts="' . esc_attr( wp_json_encode( $serializable ) ) . '">';
            $html .= esc_html__( 'Load more', 'termconnect' );
            $html .= '</button>';
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }
}

TC_Shortcode_PeerPosts::instance();
