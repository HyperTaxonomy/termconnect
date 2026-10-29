<?php
/**
 * Interactive cross-network topic browser.
 *
 * [tc_peer_browse] — renders a term-pills facet with optional Match (Any/All),
 * Source (local + peers / peers only), and Sort controls. The visitor selects
 * topics and results (local posts plus peer/feed posts) load and update via AJAX
 * with a Load-more button.
 *
 * This is the interactive, visitor-driven counterpart to [tc_peer_posts] (which
 * derives its terms automatically from the current post). Term selection drives
 * the query:
 *   - Any (OR):  union of posts matching any selected term.
 *   - All (AND): only posts that match every selected term. Local results use a
 *                tax_query AND; peer results are intersected by URL across the
 *                per-term result sets, so correctness is enforced locally and no
 *                remote multi-term search support is required.
 *
 * TermConnect owns this feature end to end. HyperTaxonomy is optional: when
 * active it is consulted only to hide terms (and their posts) the current user
 * is not allowed to see. Local posts are queried with a plain WP_Query — no HT
 * search index is needed.
 *
 * @package TermConnect
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TC_Shortcode_PeerBrowse {

    /**
     * Valid sort options: orderby value => label. Date/modified/title interleave
     * across all sources; "relevance" groups by source (peer) first and ranks each
     * source's own results by relevance, since cross-site relevance isn't comparable.
     */
    private const SORT_OPTIONS = array(
        'modified'                => 'Recently updated',
        'date'                    => 'Newest',
        'title'                   => 'Title',
        'peer:asc,relevance:desc' => 'Relevance (grouped by site)',
    );

    public function __construct() {
        add_shortcode( 'tc_peer_browse', array( $this, 'shortcode_handler' ) );
        add_action( 'wp_ajax_tc_peer_browse',              array( $this, 'ajax_browse' ) );
        add_action( 'wp_ajax_nopriv_tc_peer_browse',       array( $this, 'ajax_browse' ) );
        add_action( 'wp_ajax_tc_peer_browse_terms',        array( $this, 'ajax_term_suggest' ) );
        add_action( 'wp_ajax_nopriv_tc_peer_browse_terms', array( $this, 'ajax_term_suggest' ) );
    }

    /**
     * Shared singleton instance (so the block render callback can reuse it
     * without re-registering the shortcode and AJAX hooks).
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
    public function get_defaults(): array {
        return array(
            // Query
            'taxonomy'           => 'post_tag',
            'terms'              => '',          // optional seed: CSV of term IDs or slugs
            'match'              => 'any',       // any | all
            'scope'              => 'all',       // all | peers
            'orderby'            => 'modified',  // modified | date | title
            'peers'              => '',          // CSV peer slugs; empty = all configured peers
            'platforms'          => '',          // CSV; empty = all supported
            'foaf_depth'         => '0',
            'peer_expand'        => 'false',     // re-expand primaries through each peer's own relationship graph
            'per_page'           => '10',
            'max_results'        => '100',       // cap on the merged set

            // Display
            'columns'            => '1',
            'show_thumbnail'     => 'true',
            'show_excerpt'       => 'true',
            'image_size'         => 'medium',
            'meta'               => '{date} · {label}',
            'prepend'            => '',
            'append'             => '',
            'no_results_message' => '',
            'class'              => '',

            // Visitor control visibility
            'show_match'         => 'true',
            'show_scope'         => 'false',
            'show_sort'          => 'false',
        );
    }

    // =======================================================================
    // Rendering
    // =======================================================================

    /**
     * Shortcode handler — renders the facet controls plus the initial results.
     *
     * Unlike [tc_peer_posts] there is no is_singular() guard: the browser is a
     * standalone control that works anywhere.
     *
     * @param array|string $atts
     * @return string
     */
    public function shortcode_handler( $atts ) {
        $atts = shortcode_atts( $this->get_defaults(), $atts, 'tc_peer_browse' );
        $this->enqueue_assets();

        $taxonomy   = sanitize_key( $atts['taxonomy'] );
        $seed_terms = $this->resolve_seed_terms( $taxonomy, (string) $atts['terms'] );

        $uid = 'tcpb-' . wp_generate_uuid4();

        // Author display config carried to the AJAX endpoint (sanitized on receipt).
        $config = array(
            'taxonomy'           => $taxonomy,
            'peers'              => (string) $atts['peers'],
            'platforms'          => (string) $atts['platforms'],
            'foaf_depth'         => (int) $atts['foaf_depth'],
            'peer_expand'        => (string) $atts['peer_expand'],
            'per_page'           => max( 1, (int) $atts['per_page'] ),
            'max_results'        => max( 1, (int) $atts['max_results'] ),
            'columns'            => max( 1, (int) $atts['columns'] ),
            'show_thumbnail'     => $atts['show_thumbnail'],
            'show_excerpt'       => $atts['show_excerpt'],
            'image_size'         => sanitize_key( $atts['image_size'] ),
            'meta'               => (string) $atts['meta'],
            'prepend'            => (string) $atts['prepend'],
            'append'             => (string) $atts['append'],
            'no_results_message' => (string) $atts['no_results_message'],
        );

        $match   = in_array( $atts['match'], array( 'any', 'all' ), true ) ? $atts['match'] : 'any';
        $scope   = in_array( $atts['scope'], array( 'all', 'peers' ), true ) ? $atts['scope'] : 'all';
        $orderby = isset( self::SORT_OPTIONS[ $atts['orderby'] ] ) ? $atts['orderby'] : 'modified';

        $wrapper_class = 'tc-peer-browse';
        if ( ! empty( $atts['class'] ) ) {
            $wrapper_class .= ' ' . sanitize_html_class( $atts['class'] );
        }

        $html  = '<div class="' . esc_attr( $wrapper_class ) . '"'
               . ' data-nonce="' . esc_attr( wp_create_nonce( 'tc_peer_browse' ) ) . '"'
               . ' data-taxonomy="' . esc_attr( $taxonomy ) . '"'
               . ' data-match="' . esc_attr( $match ) . '"'
               . ' data-scope="' . esc_attr( $scope ) . '"'
               . ' data-orderby="' . esc_attr( $orderby ) . '"'
               . ' data-columns="' . (int) $config['columns'] . '"'
               . ' data-thumb-ratio="' . esc_attr( tc_image_size_ratio( $config['image_size'] ) ) . '"'
               . ' data-atts="' . esc_attr( wp_json_encode( $config ) ) . '">';

        $html .= $this->render_controls( $uid, $taxonomy, $match, $scope, $orderby, $seed_terms, $atts );

        // Initial results (server-rendered when seeded; otherwise a prompt).
        $html .= '<div class="tc-pb-results">';
        if ( ! empty( $seed_terms ) ) {
            $result = $this->build_results( array(
                'taxonomy'    => $taxonomy,
                'term_ids'    => $seed_terms,
                'match'       => $match,
                'scope'       => $scope,
                'orderby'     => $orderby,
                'offset'      => 0,
            ) + $config );
            $html .= $this->wrap_results( $result, tc_post_list_items_style( (int) $config['columns'], $config['image_size'] ) );
        } else {
            $html .= '<p class="tc-pb-empty">' . esc_html__( 'Choose one or more topics to see related posts.', 'termconnect' ) . '</p>';
        }
        $html .= '</div>'; // .tc-pb-results

        $html .= '</div>'; // .tc-peer-browse
        return $html;
    }

    /**
     * Render the facet controls (pills + optional match/scope/sort).
     *
     * @param string $uid        Unique instance id (for control name grouping).
     * @param string $taxonomy
     * @param string $match
     * @param string $scope
     * @param string $orderby
     * @param int[]  $seed_terms Pre-selected term IDs.
     * @param array  $atts       Merged attributes (for show_* flags).
     * @return string
     */
    private function render_controls( string $uid, string $taxonomy, string $match, string $scope, string $orderby, array $seed_terms, array $atts ): string {
        $html  = '<form class="tc-pb-controls" onsubmit="return false">';

        // Topic pills
        $html .= '<div class="tc-pb-field tc-pb-pills-field">';
        $html .= '<label class="tc-pb-label" for="' . esc_attr( $uid ) . '-input">' . esc_html__( 'Topics', 'termconnect' ) . '</label>';
        $html .= '<div class="tc-pb-pillbox">';
        foreach ( $seed_terms as $tid ) {
            $term = get_term( $tid );
            if ( $term instanceof WP_Term ) {
                $html .= $this->pill_html( (int) $tid, $term->name );
            }
        }
        $html .= '<input type="text" id="' . esc_attr( $uid ) . '-input" class="tc-pb-input"'
               . ' placeholder="' . esc_attr__( 'Add a topic…', 'termconnect' ) . '" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list">';
        $html .= '</div>'; // .tc-pb-pillbox
        $html .= '<ul class="tc-pb-suggest" role="listbox" hidden></ul>';
        $html .= '</div>'; // .tc-pb-pills-field

        // Match (Any/All)
        if ( 'false' !== $atts['show_match'] ) {
            $name  = esc_attr( $uid . '-match' );
            $html .= '<div class="tc-pb-field tc-pb-match-field" role="radiogroup" aria-label="' . esc_attr__( 'Match', 'termconnect' ) . '">';
            $html .= '<span class="tc-pb-label">' . esc_html__( 'Match', 'termconnect' ) . '</span>';
            $html .= '<label class="tc-pb-radio"><input type="radio" name="' . $name . '" class="tc-pb-match" value="any" ' . checked( 'any', $match, false ) . '> ' . esc_html__( 'Any', 'termconnect' ) . '</label>';
            $html .= '<label class="tc-pb-radio"><input type="radio" name="' . $name . '" class="tc-pb-match" value="all" ' . checked( 'all', $match, false ) . '> ' . esc_html__( 'All', 'termconnect' ) . '</label>';
            $html .= '</div>';
        }

        // Source (local + peers / peers only)
        if ( 'false' !== $atts['show_scope'] ) {
            $html .= '<div class="tc-pb-field tc-pb-scope-field">';
            $html .= '<label class="tc-pb-label" for="' . esc_attr( $uid ) . '-scope">' . esc_html__( 'Source', 'termconnect' ) . '</label>';
            $html .= '<select id="' . esc_attr( $uid ) . '-scope" class="tc-pb-scope">';
            $html .= '<option value="all" ' . selected( 'all', $scope, false ) . '>' . esc_html__( 'Local + peers', 'termconnect' ) . '</option>';
            $html .= '<option value="peers" ' . selected( 'peers', $scope, false ) . '>' . esc_html__( 'Peers only', 'termconnect' ) . '</option>';
            $html .= '</select>';
            $html .= '</div>';
        }

        // Sort
        if ( 'false' !== $atts['show_sort'] ) {
            $html .= '<div class="tc-pb-field tc-pb-sort-field">';
            $html .= '<label class="tc-pb-label" for="' . esc_attr( $uid ) . '-sort">' . esc_html__( 'Sort', 'termconnect' ) . '</label>';
            $html .= '<select id="' . esc_attr( $uid ) . '-sort" class="tc-pb-sort">';
            foreach ( self::SORT_OPTIONS as $value => $label ) {
                $html .= '<option value="' . esc_attr( $value ) . '" ' . selected( $value, $orderby, false ) . '>'
                       . esc_html( __( $label, 'termconnect' ) ) . '</option>';
            }
            $html .= '</select>';
            $html .= '</div>';
        }

        $html .= '</form>';
        return $html;
    }

    /**
     * Markup for a single selected-topic pill.
     *
     * @param int    $term_id
     * @param string $name
     * @return string
     */
    private function pill_html( int $term_id, string $name ): string {
        return '<span class="tc-pb-pill" data-id="' . (int) $term_id . '">'
             . esc_html( $name )
             . '<button type="button" class="tc-pb-remove" aria-label="' . esc_attr__( 'Remove topic', 'termconnect' ) . '">&times;</button>'
             . '</span>';
    }

    /**
     * Wrap items HTML in the list container + load-more pager (initial render).
     *
     * @param array  $result      From build_results().
     * @param string $items_style Inline style for the items container (columns + thumb ratio).
     * @return string
     */
    private function wrap_results( array $result, string $items_style ): string {
        if ( '' === $result['items_html'] ) {
            return $result['no_results_html'];
        }

        $style = '' !== $items_style ? ' style="' . esc_attr( $items_style ) . '"' : '';
        $html  = '<div class="tc-post-list tc-peer-browse-list">';
        $html .= '<div class="tc-post-list-items"' . $style . '>' . $result['items_html'] . '</div>';
        $html .= '<div class="tc-pb-pager">';
        if ( $result['has_more'] ) {
            $html .= '<button type="button" class="tc-pb-more" data-offset="' . (int) $result['next_offset'] . '">'
                   . esc_html__( 'Load more', 'termconnect' ) . '</button>';
        }
        $html .= '</div>';
        $html .= '</div>';
        return $html;
    }

    // =======================================================================
    // AJAX
    // =======================================================================

    /**
     * AJAX: return a page of combined results for the current facet state.
     */
    public function ajax_browse(): void {
        check_ajax_referer( 'tc_peer_browse', 'nonce' );

        $args = $this->parse_request();
        if ( empty( $args['term_ids'] ) ) {
            wp_send_json_success( array( 'html' => '', 'has_more' => false, 'next_offset' => 0, 'total' => 0, 'no_terms' => true ) );
            return;
        }

        $result = $this->build_results( $args );

        wp_send_json_success( array(
            'html'        => '' !== $result['items_html'] ? $result['items_html'] : '',
            'no_results'  => '' === $result['items_html'] ? $result['no_results_html'] : '',
            'has_more'    => $result['has_more'],
            'next_offset' => $result['next_offset'],
            'total'       => $result['total'],
        ) );
    }

    /**
     * AJAX: term autocomplete suggestions for the configured taxonomy.
     *
     * Returns only terms the current user may see (hidden terms are excluded
     * when HyperTaxonomy is active and the user lacks access).
     */
    public function ajax_term_suggest(): void {
        check_ajax_referer( 'tc_peer_browse', 'nonce' );

        $taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
        $search   = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';

        if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
            wp_send_json_success( array() );
            return;
        }

        $terms = get_terms( array(
            'taxonomy'   => $taxonomy,
            'search'     => $search,
            'number'     => 10,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );
        if ( is_wp_error( $terms ) ) {
            wp_send_json_success( array() );
            return;
        }

        $can_see_hidden = function_exists( 'htax_can_see_hidden_terms' ) ? htax_can_see_hidden_terms( $taxonomy ) : true;

        $out = array();
        foreach ( $terms as $term ) {
            if ( function_exists( 'htax_is_term_hidden' ) && htax_is_term_hidden( $term->term_id ) && ! $can_see_hidden ) {
                continue;
            }
            $out[] = array(
                'id'   => (int) $term->term_id,
                'name' => $term->name,
            );
        }

        wp_send_json_success( $out );
    }

    /**
     * Parse and sanitize the AJAX request into a build_results() args array.
     *
     * Interactive state (terms, match, scope, orderby, offset) arrives as discrete
     * fields; author display config arrives as the JSON `atts` blob and is
     * sanitized against get_defaults().
     *
     * @return array
     */
    private function parse_request(): array {
        $raw_atts = ( isset( $_POST['atts'] ) && is_string( $_POST['atts'] ) )
            ? json_decode( wp_unslash( $_POST['atts'] ), true )
            : array();
        if ( ! is_array( $raw_atts ) ) {
            $raw_atts = array();
        }

        $taxonomy = isset( $raw_atts['taxonomy'] ) ? sanitize_key( $raw_atts['taxonomy'] ) : 'post_tag';

        $raw_terms = isset( $_POST['terms'] ) ? (array) wp_unslash( $_POST['terms'] ) : array();
        if ( 1 === count( $raw_terms ) && is_string( reset( $raw_terms ) ) && false !== strpos( (string) reset( $raw_terms ), ',' ) ) {
            $raw_terms = explode( ',', (string) reset( $raw_terms ) );
        }
        $term_ids = $this->sanitize_term_ids( $taxonomy, $raw_terms );

        $match   = ( isset( $_POST['match'] ) && 'all' === $_POST['match'] ) ? 'all' : 'any';
        $scope   = ( isset( $_POST['scope'] ) && 'peers' === $_POST['scope'] ) ? 'peers' : 'all';
        $orderby = isset( $_POST['orderby'] ) ? sanitize_text_field( wp_unslash( $_POST['orderby'] ) ) : 'modified';
        if ( ! isset( self::SORT_OPTIONS[ $orderby ] ) ) {
            $orderby = 'modified';
        }
        $offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );

        return array(
            'taxonomy'           => $taxonomy,
            'term_ids'           => $term_ids,
            'match'              => $match,
            'scope'              => $scope,
            'orderby'            => $orderby,
            'offset'             => $offset,
            'peers'              => isset( $raw_atts['peers'] ) ? sanitize_text_field( $raw_atts['peers'] ) : '',
            'platforms'          => isset( $raw_atts['platforms'] ) ? sanitize_text_field( $raw_atts['platforms'] ) : '',
            'foaf_depth'         => max( 0, (int) ( $raw_atts['foaf_depth'] ?? 0 ) ),
            'peer_expand'        => isset( $raw_atts['peer_expand'] ) ? sanitize_text_field( $raw_atts['peer_expand'] ) : 'false',
            'per_page'           => max( 1, (int) ( $raw_atts['per_page'] ?? 10 ) ),
            'max_results'        => max( 1, (int) ( $raw_atts['max_results'] ?? 100 ) ),
            'columns'            => max( 1, (int) ( $raw_atts['columns'] ?? 1 ) ),
            'show_thumbnail'     => isset( $raw_atts['show_thumbnail'] ) ? sanitize_text_field( $raw_atts['show_thumbnail'] ) : 'true',
            'show_excerpt'       => isset( $raw_atts['show_excerpt'] ) ? sanitize_text_field( $raw_atts['show_excerpt'] ) : 'true',
            'image_size'         => isset( $raw_atts['image_size'] ) ? sanitize_key( $raw_atts['image_size'] ) : 'medium',
            'meta'               => isset( $raw_atts['meta'] ) ? sanitize_text_field( $raw_atts['meta'] ) : '{date} · {label}',
            'prepend'            => isset( $raw_atts['prepend'] ) ? wp_kses_post( $raw_atts['prepend'] ) : '',
            'append'             => isset( $raw_atts['append'] ) ? wp_kses_post( $raw_atts['append'] ) : '',
            'no_results_message' => isset( $raw_atts['no_results_message'] ) ? sanitize_text_field( $raw_atts['no_results_message'] ) : '',
        );
    }

    // =======================================================================
    // Query
    // =======================================================================

    /**
     * Build one page of merged results.
     *
     * @param array $args Parsed args (see parse_request()/shortcode_handler()).
     * @return array { items_html, no_results_html, has_more, next_offset, total }
     */
    private function build_results( array $args ): array {
        $no_results_html = ! empty( $args['no_results_message'] )
            ? '<p class="tc-pb-no-results">' . esc_html( $args['no_results_message'] ) . '</p>'
            : '';

        $all = $this->get_combined_records( $args );
        $total = count( $all );

        if ( 0 === $total ) {
            return array(
                'items_html'      => '',
                'no_results_html' => $no_results_html,
                'has_more'        => false,
                'next_offset'     => 0,
                'total'           => 0,
            );
        }

        $offset   = $args['offset'];
        $per_page = $args['per_page'];
        $page     = array_slice( $all, $offset, $per_page );
        $has_more = $total > ( $offset + $per_page );

        $peer = TC_Shortcode_PeerPosts::instance();
        $items_html = $peer->render_items(
            $page,
            'true' === $args['show_thumbnail'],
            'true' === $args['show_excerpt'],
            (string) $args['meta'],
            (string) $args['prepend'],
            (string) $args['append']
        );

        return array(
            'items_html'      => $items_html,
            'no_results_html' => $no_results_html,
            'has_more'        => $has_more,
            'next_offset'     => $offset + $per_page,
            'total'           => $total,
        );
    }

    /**
     * Collect, merge, dedupe, sort, and cap the full combined record set.
     *
     * @param array $args
     * @return array Normalized records (sorted, capped to max_results).
     */
    private function get_combined_records( array $args ): array {
        $peers = $this->collect_peers( $args );
        $local = ( 'all' === $args['scope'] ) ? $this->collect_local( $args ) : array();

        // Merge + dedupe by URL (local first, so it wins on a coincident URL).
        $all = tc_dedupe_posts_by_url( array_merge( $local, $peers ) );

        $all = TC_Shortcode_PeerPosts::instance()->sort_posts( $all, $args['orderby'] );

        if ( count( $all ) > $args['max_results'] ) {
            $all = array_slice( $all, 0, $args['max_results'] );
        }
        return $all;
    }

    /**
     * Collect peer/feed records for the selected terms, scored per match mode.
     *
     * Delegates to the shared [tc_peer_posts] pipeline with the full term set as
     * primaries, so AND/OR is handled by the match mode (htax_match=all /
     * filter_and_match), relevance reflects how many of the selected terms a post
     * matches (a post matching several ranks above one matching a single term),
     * and each peer is queried once rather than once per term.
     *
     * @param array $args
     * @return array
     */
    private function collect_peers( array $args ): array {
        $term_ids = $args['term_ids'];
        if ( empty( $term_ids ) ) {
            return array();
        }

        $peer_atts = array(
            'taxonomy'         => $args['taxonomy'],
            'platforms'        => $args['platforms'],
            'foaf_depth'       => (string) $args['foaf_depth'],
            'per_page'         => (string) $args['max_results'],
            'max_results'      => (string) $args['max_results'],
            'match'            => ( 'all' === $args['match'] ) ? 'all' : 'any',
            'include_synonyms' => 'true',
            'link_types'       => (string) ( $args['peers'] ?? '' ),
            'peer_expand'      => (string) ( $args['peer_expand'] ?? 'false' ),
        );

        return TC_Shortcode_PeerPosts::instance()->collect_posts_for_terms(
            array_map( 'intval', $term_ids ),
            $peer_atts
        );
    }

    /**
     * Collect local posts matching the selected terms, normalized to the shared
     * record shape. Uses a plain WP_Query — no HyperTaxonomy dependency.
     *
     * @param array $args
     * @return array
     */
    private function collect_local( array $args ): array {
        if ( empty( $args['term_ids'] ) ) {
            return array();
        }
        $tax_terms = tc_filter_hidden_terms( array( $args['taxonomy'] => $args['term_ids'] ) );
        if ( empty( $tax_terms ) ) {
            return array();
        }

        // With HyperTaxonomy active, select via relationship expansion so local
        // recall matches its archives / [ht_related_posts] rather than just
        // directly-tagged posts.
        $expansions = tc_local_term_expansions( $tax_terms );

        $records = tc_collect_local_posts(
            $tax_terms,
            $args['match'],
            $args['max_results'],
            'true' === $args['show_thumbnail'],
            $args['image_size'],
            array(),
            $expansions
        );

        // Relevance-score local results via HyperTaxonomy when available, so the
        // "Relevance (grouped by site)" sort ranks the local group meaningfully.
        if ( ! empty( $records ) && function_exists( 'htax_get_local_post_scores' ) ) {
            $scores = htax_get_local_post_scores( $args['term_ids'] );
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

    // =======================================================================
    // Helpers
    // =======================================================================

    /**
     * Resolve a CSV seed (term IDs or slugs) into validated term IDs.
     *
     * @param string $taxonomy
     * @param string $csv
     * @return int[]
     */
    private function resolve_seed_terms( string $taxonomy, string $csv ): array {
        $ids = array();
        foreach ( array_filter( array_map( 'trim', explode( ',', $csv ) ) ) as $token ) {
            if ( ctype_digit( $token ) ) {
                $ids[] = (int) $token;
            } else {
                $term = get_term_by( 'slug', $token, $taxonomy );
                if ( $term instanceof WP_Term ) {
                    $ids[] = (int) $term->term_id;
                }
            }
        }
        return $this->sanitize_term_ids( $taxonomy, $ids );
    }

    /**
     * Keep only valid, visible term IDs that belong to the taxonomy.
     *
     * Hidden terms are dropped when HyperTaxonomy is active and the current user
     * cannot see them — the sole HyperTaxonomy touchpoint of this feature.
     *
     * @param string $taxonomy
     * @param array  $raw_ids
     * @return int[]
     */
    private function sanitize_term_ids( string $taxonomy, array $raw_ids ): array {
        $can_see_hidden = function_exists( 'htax_can_see_hidden_terms' ) ? htax_can_see_hidden_terms( $taxonomy ) : true;

        $out = array();
        foreach ( $raw_ids as $rid ) {
            $tid = (int) $rid;
            if ( $tid <= 0 ) {
                continue;
            }
            $term = get_term( $tid );
            if ( ! ( $term instanceof WP_Term ) || $term->taxonomy !== $taxonomy ) {
                continue;
            }
            if ( function_exists( 'htax_is_term_hidden' ) && htax_is_term_hidden( $tid ) && ! $can_see_hidden ) {
                continue;
            }
            $out[] = $tid;
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * Register and enqueue the browser's frontend assets.
     */
    private function enqueue_assets(): void {
        if ( ! wp_style_is( 'tc-post-list', 'enqueued' ) ) {
            wp_enqueue_style(
                'tc-post-list',
                TC_PLUGIN_URL . 'assets/css/tc-post-list.css',
                array(),
                TC_VERSION
            );
        }
        if ( ! wp_style_is( 'tc-peer-browse', 'enqueued' ) ) {
            wp_enqueue_style(
                'tc-peer-browse',
                TC_PLUGIN_URL . 'assets/css/tc-peer-browse.css',
                array( 'tc-post-list' ),
                TC_VERSION
            );
        }

        if ( wp_script_is( 'tc-peer-browse', 'enqueued' ) || wp_script_is( 'tc-peer-browse', 'done' ) ) {
            return;
        }

        wp_enqueue_script(
            'tc-peer-browse',
            TC_PLUGIN_URL . 'assets/js/tc-peer-browse.js',
            array(),
            TC_VERSION,
            true
        );

        wp_localize_script( 'tc-peer-browse', 'TC_PeerBrowse', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'i18n'     => array(
                'remove'    => __( 'Remove topic', 'termconnect' ),
                'load_more' => __( 'Load more', 'termconnect' ),
                'empty'     => __( 'Choose one or more topics to see related posts.', 'termconnect' ),
                'none'      => __( 'No matching posts found.', 'termconnect' ),
                'loading'   => __( 'Loading…', 'termconnect' ),
            ),
        ) );
    }
}

TC_Shortcode_PeerBrowse::instance();
