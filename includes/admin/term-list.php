<?php
/**
 * Term list column, sortable, and filter for External Links.
 *
 * Adds a "Links" column to taxonomy term list tables for TermConnect-enabled taxonomies,
 * showing the number of external identifiers attached to each term. The column
 * is sortable and a filter dropdown lets admins narrow by presence or specific
 * link type.
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---------------------------------------------------------------------------
// Bootstrap — register per-taxonomy hooks.
// ---------------------------------------------------------------------------

add_action( 'current_screen', static function ( WP_Screen $screen ): void {
    $taxonomy = $screen->taxonomy;
    if ( ! $taxonomy ) {
        return;
    }
    if ( ! in_array( $taxonomy, TC_Settings::get_enabled_taxonomies(), true ) ) {
        return;
    }

    // Register the column header on both edit-tags and term screens — Screen Options
    // for a taxonomy is shared between the list and edit pages, so the column must be
    // registered on both to appear in Screen Options on the individual term edit page.
    add_filter( "manage_edit-{$taxonomy}_columns",          'tc_add_ext_links_column',           99 );
    add_filter( "manage_edit-{$taxonomy}_sortable_columns", 'tc_make_ext_links_column_sortable',  99 );
    add_filter( "manage_edit-{$taxonomy}_columns",          'tc_add_di_issues_column',           100 );
    add_filter( "manage_edit-{$taxonomy}_sortable_columns", 'tc_make_di_issues_column_sortable', 100 );

    // Hide Links and Sync columns by default.
    add_filter( 'default_hidden_columns', static function ( $hidden, $screen ) use ( $taxonomy ) {
        if ( $screen && "edit-{$taxonomy}" === $screen->id ) {
            $hidden[] = 'tc_ext_links';
            $hidden[] = 'tc_di_issues';
        }
        return $hidden;
    }, 10, 2 );

    if ( 'edit-tags' !== $screen->base ) {
        return;
    }

    add_filter( "manage_{$taxonomy}_custom_column",         'tc_render_ext_links_column',        99, 3 );
    add_filter( "manage_{$taxonomy}_custom_column",         'tc_render_di_issues_column',       100, 3 );
    add_filter( 'terms_clauses',                            'tc_sort_terms_by_ext_links',        10, 3 );
    add_filter( 'terms_clauses',                            'tc_sort_terms_by_di_issues',        10, 3 );
    add_action( 'parse_term_query',                         'tc_apply_ext_links_filter' );
    add_action( 'parse_term_query',                         'tc_apply_di_issues_filter' );
    add_action( 'admin_footer',                             'tc_ext_links_filter_sync_script' );

    // Build link-type options for the JS-injected filter (WP fires restrict_manage_terms,
    // not restrict_manage_{taxonomy}, so PHP-only output is unreliable).
    $link_types = function_exists( 'tc_get_enabled_link_types_for_taxonomy' )
        ? tc_get_enabled_link_types_for_taxonomy( $taxonomy )
        : array();
    $link_type_options = array();
    foreach ( $link_types as $slug => $type ) {
        $link_type_options[] = array( 'slug' => $slug, 'label' => $type['label'] );
    }

    $filter_config = array(
        'current'  => sanitize_text_field( wp_unslash( $_GET['tc_ext_links_filter'] ?? '' ) ),
        'all'      => __( 'All External Links', 'termconnect' ),
        'yes'      => __( 'With External Links', 'termconnect' ),
        'no'       => __( 'No External Links', 'termconnect' ),
        'byType'   => __( 'By Link Type', 'termconnect' ),
        'linkTypes' => $link_type_options,
    );

    $di_filter_config = array(
        'current'       => sanitize_key( wp_unslash( $_GET['tc_di_issues_filter'] ?? '' ) ),
        'all'           => __( 'All Sync Status', 'termconnect' ),
        'not_delegated' => __( 'Not Delegated', 'termconnect' ),
        'synced'        => __( 'Synced Successfully', 'termconnect' ),
        'stale'         => __( 'With Stale Rows', 'termconnect' ),
    );

    add_action( 'admin_head', static function () use ( $filter_config, $di_filter_config ): void {
        ?>
        <style>
        .column-tc_ext_links { width: 60px; text-align: center; }
        .wp-list-table th.column-tc_ext_links { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .wp-list-table th.column-tc_ext_links a { display: flex; flex-wrap: nowrap; align-items: center; gap: 2px; }
        .wp-list-table th.column-tc_ext_links a > span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; flex: 0 1 auto; }
        .tc-add-link { color: #a7aaad; text-decoration: none; font-size: 18px; line-height: 1; }
        .tc-add-link:hover { color: #2271b1; }
        .tc-ext-links-filter select { height: 32px !important; min-height: 0 !important; line-height: 30px !important; }
        .column-tc_di_issues { width: 32px; text-align: center; }
        .wp-list-table th.column-tc_di_issues { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .wp-list-table th.column-tc_di_issues a { display: flex; flex-wrap: nowrap; align-items: center; gap: 2px; }
        .wp-list-table th.column-tc_di_issues a > span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; flex: 0 1 auto; }
        .tc-di-issue-icon { color: #d63638; text-decoration: none; }
        .tc-di-issue-icon:hover { color: #b32d2e; }
        </style>
        <script>
        window.tcExtLinksFilter = <?php echo wp_json_encode( $filter_config ); ?>;
        window.tcDiIssuesFilter = <?php echo wp_json_encode( $di_filter_config ); ?>;
        </script>
        <?php
    } );
} );

// ---------------------------------------------------------------------------
// Column registration.
// ---------------------------------------------------------------------------

function tc_add_ext_links_column( array $columns ): array {
    // Insert after Visibility (htax_hidden) when HyperTaxonomy is active, otherwise append.
    if ( function_exists( 'htax_insert_column_after' ) ) {
        return htax_insert_column_after( $columns, 'htax_hidden', 'tc_ext_links', __( 'Links', 'termconnect' ) );
    }
    $columns['tc_ext_links'] = __( 'Links', 'termconnect' );
    return $columns;
}

function tc_make_ext_links_column_sortable( array $sortable ): array {
    $sortable['tc_ext_links'] = 'tc_ext_links';
    return $sortable;
}

function tc_make_di_issues_column_sortable( array $sortable ): array {
    $sortable['tc_di_issues'] = 'tc_di_issues';
    return $sortable;
}

function tc_add_di_issues_column( array $columns ): array {
    if ( function_exists( 'htax_insert_column_after' ) ) {
        return htax_insert_column_after( $columns, 'tc_ext_links', 'tc_di_issues', __( 'Sync', 'termconnect' ) );
    }
    $columns['tc_di_issues'] = __( 'Sync', 'termconnect' );
    return $columns;
}

function tc_render_di_issues_column( string $output, string $column, int $term_id ): string {
    if ( 'tc_di_issues' !== $column ) {
        return $output;
    }
    $issues = get_term_meta( $term_id, TC_META_REL_SYNC_ISSUES, true );
    if ( empty( $issues ) || ! is_array( $issues ) ) {
        return $output;
    }
    $count    = count( $issues );
    $edit_url = add_query_arg( 'tc_scroll', 'tc-delegated-identity-row', get_edit_term_link( $term_id ) );
    return '<a href="' . esc_url( $edit_url ) . '" class="tc-di-issue-icon" title="'
        . esc_attr( sprintf(
            /* translators: %d: number of stale rows */
            _n( '%d stale relationship row', '%d stale relationship rows', $count, 'termconnect' ),
            $count
        ) ) . '">'
        . '<span class="dashicons dashicons-warning" aria-hidden="true"></span>'
        . '<span class="screen-reader-text">' . esc_html( sprintf(
            _n( '%d stale relationship row', '%d stale relationship rows', $count, 'termconnect' ),
            $count
        ) ) . '</span>'
        . '</a>';
}

// ---------------------------------------------------------------------------
// Column cell render.
// ---------------------------------------------------------------------------

function tc_render_ext_links_column( string $output, string $column, int $term_id ): string {
    if ( 'tc_ext_links' !== $column ) {
        return $output;
    }

    $count = tc_count_ext_links_for_term( $term_id );
    $edit_url = get_edit_term_link( $term_id );

    if ( $count > 0 ) {
        return '<a href="' . esc_url( $edit_url ) . '#tc-external-links">'
            . esc_html( number_format_i18n( $count ) ) . '</a>';
    }

    return '<a href="' . esc_url( $edit_url ) . '#tc-external-links" class="tc-add-link" aria-label="'
        . esc_attr__( 'Add external link', 'termconnect' ) . '">+</a>';
}

/**
 * Count the number of external link types set for a term.
 *
 * @param int $term_id Term ID.
 * @return int
 */
function tc_count_ext_links_for_term( int $term_id ): int {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->termmeta}
         WHERE term_id = %d
           AND meta_key LIKE '\_tc\_link\_%'
           AND meta_value != ''",
        $term_id
    ) );
}

// ---------------------------------------------------------------------------
// Sorting.
// ---------------------------------------------------------------------------

function tc_sort_terms_by_ext_links( array $clauses, array $taxonomies, array $args ): array {
    if ( ! is_admin() || ( $args['orderby'] ?? '' ) !== 'tc_ext_links' ) {
        return $clauses;
    }

    $order = strtoupper( $args['order'] ?? 'ASC' );
    if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
        $order = 'ASC';
    }

    global $wpdb;
    $clauses['orderby'] = "ORDER BY (
        SELECT COUNT(*)
        FROM {$wpdb->termmeta} tm
        WHERE tm.term_id = t.term_id
          AND tm.meta_key LIKE '\_tc\_link\_%'
          AND tm.meta_value != ''
    ) {$order}";

    return $clauses;
}

function tc_sort_terms_by_di_issues( array $clauses, array $taxonomies, array $args ): array {
    if ( ! is_admin() || ( $args['orderby'] ?? '' ) !== 'tc_di_issues' ) {
        return $clauses;
    }

    $order = strtoupper( $args['order'] ?? 'ASC' );
    if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
        $order = 'ASC';
    }

    global $wpdb;
    $clauses['orderby'] = $wpdb->prepare(
        "ORDER BY (
            SELECT COUNT(*)
            FROM {$wpdb->termmeta} tm
            WHERE tm.term_id = t.term_id
              AND tm.meta_key = %s
        ) {$order}",
        TC_META_REL_SYNC_ISSUES
    );
    $clauses['order'] = '';

    return $clauses;
}

// ---------------------------------------------------------------------------
// Filter application.
// ---------------------------------------------------------------------------

function tc_apply_ext_links_filter( WP_Term_Query $query ): void {
    if ( ! is_admin() ) {
        return;
    }

    // Not sanitize_key(): the "By Link Type" value is `lt:<slug>` and sanitize_key
    // would strip the colon. The slug portion is sanitize_key'd below before use.
    $filter = sanitize_text_field( wp_unslash( $_GET['tc_ext_links_filter'] ?? '' ) );
    if ( '' === $filter ) {
        return;
    }

    global $wpdb;

    if ( 'yes' === $filter ) {
        $ids = $wpdb->get_col(
            "SELECT DISTINCT term_id FROM {$wpdb->termmeta}
             WHERE meta_key LIKE '\_tc\_link\_%' AND meta_value != ''"
        );
        $query->query_vars['include'] = array_map( 'intval', $ids ) ?: array( 0 );

    } elseif ( 'no' === $filter ) {
        $ids = $wpdb->get_col(
            "SELECT DISTINCT term_id FROM {$wpdb->termmeta}
             WHERE meta_key LIKE '\_tc\_link\_%' AND meta_value != ''"
        );
        $query->query_vars['exclude'] = array_map( 'intval', $ids ) ?: array( 0 );

    } elseif ( 0 === strpos( $filter, 'lt:' ) ) {
        $slug = sanitize_key( substr( $filter, 3 ) );
        if ( '' !== $slug ) {
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT term_id FROM {$wpdb->termmeta}
                 WHERE meta_key = %s AND meta_value != ''",
                '_tc_link_' . $slug
            ) );
            $query->query_vars['include'] = array_map( 'intval', $ids ) ?: array( 0 );
        }
    }
}

function tc_apply_di_issues_filter( WP_Term_Query $query ): void {
    if ( ! is_admin() ) {
        return;
    }
    $filter = sanitize_key( wp_unslash( $_GET['tc_di_issues_filter'] ?? '' ) );
    if ( '' === $filter ) {
        return;
    }

    if ( 'not_delegated' === $filter ) {
        $ids = tc_get_delegated_term_ids();
        $query->query_vars['exclude'] = $ids ?: array( 0 );

    } elseif ( 'synced' === $filter ) {
        $delegated = tc_get_delegated_term_ids();
        $stale     = tc_get_stale_rel_term_ids();
        $synced    = array_values( array_diff( $delegated, $stale ) );
        $query->query_vars['include'] = $synced ?: array( 0 );

    } elseif ( 'stale' === $filter ) {
        $ids = tc_get_stale_rel_term_ids();
        $query->query_vars['include'] = $ids ?: array( 0 );
    }
}

// ---------------------------------------------------------------------------
// Filter injection + MutationObserver sync.
// ---------------------------------------------------------------------------

function tc_ext_links_filter_sync_script(): void {
    ?>
    <script>
    // Wrap in ready() so this runs after HyperTaxonomy's $(document).ready() handler has
    // registered its atcf:termFilterInserted listener.
    jQuery( document ).ready( function ( $ ) {
        var $searchForm = $( '#col-right .search-form' ).first();
        if ( ! $searchForm.length ) {
            $searchForm = $( '.wrap .search-form' ).first();
        }
        if ( ! $searchForm.length ) { return; }

        var $tableHead = $( '.wp-list-table thead' );

        function tcMakeObserver( syncFn ) {
            if ( ! $tableHead.length || ! window.MutationObserver ) { return; }
            var obs = new MutationObserver( function ( mutations ) {
                var needsSync = false;
                mutations.forEach( function ( m ) {
                    if ( m.type === 'attributes' && m.attributeName === 'class' ) { needsSync = true; }
                } );
                if ( needsSync ) { syncFn(); }
            } );
            $tableHead.find( 'th' ).each( function () { obs.observe( this, { attributes: true } ); } );
        }

        function tcInjectFilter( $container ) {
            $searchForm.prepend( $container );
            $( document ).trigger( 'atcf:termFilterInserted', [ $container ] );
            // Fallback positioning when HyperTaxonomy is not active.
            setTimeout( function () {
                if ( ! $.contains( document, $container[0] ) ) { return; }
                var $bulkActions = $( '.tablenav.top .bulkactions' ).first();
                if ( $bulkActions.length ) {
                    var leftOffset = $bulkActions.offset().left - $searchForm.offset().left;
                    $container.css( {
                        display:    'inline-block',
                        float:      'left',
                        position:   'relative',
                        left:       leftOffset + 'px',
                        margin:     '0 10px 0 0',
                        paddingTop: '11px',
                        lineHeight: '28px',
                    } );
                }
            }, 100 );
        }

        // --- External Links filter — injected first so it appears first (leftmost) ---
        var cfg = window.tcExtLinksFilter;
        if ( cfg ) {
            var $sel = $( '<select id="tc_ext_links_filter" name="tc_ext_links_filter"></select>' );
            $sel.css( 'max-width', '150px' );

            $sel.append( $( '<option>' ).val( '' ).text( cfg.all ) );
            $sel.append( $( '<option>' ).val( 'yes' ).text( cfg.yes ).prop( 'selected', cfg.current === 'yes' ) );
            $sel.append( $( '<option>' ).val( 'no' ).text( cfg.no ).prop( 'selected', cfg.current === 'no' ) );

            if ( cfg.linkTypes && cfg.linkTypes.length ) {
                var $grp = $( '<optgroup>' ).attr( 'label', cfg.byType );
                $.each( cfg.linkTypes, function ( i, lt ) {
                    var $o = $( '<option>' ).val( 'lt:' + lt.slug ).text( lt.label );
                    if ( cfg.current === 'lt:' + lt.slug ) { $o.prop( 'selected', true ); }
                    $grp.append( $o );
                } );
                $sel.append( $grp );
            }

            // Use atcf-term-filters as a second class so HyperTaxonomy's proactive setTimeout(0)
            // absorption also finds this container, regardless of event timing.
            var $container = $( '<div class="tc-ext-links-filter atcf-term-filters"></div>' ).append( $sel );
            tcInjectFilter( $container );

            function syncTcExtLinksFilter() {
                var $col = $( '.wp-list-table th.column-tc_ext_links' );
                var isHidden = $col.length && ( $col.hasClass( 'hidden' ) || $col.css( 'display' ) === 'none' );
                $( '#tc_ext_links_filter' )[ isHidden ? 'hide' : 'show' ]();
            }
            syncTcExtLinksFilter();
            tcMakeObserver( syncTcExtLinksFilter );
        }

        // --- Sync Status (di issues) filter — inserted AFTER External Links filter ---
        var diCfg = window.tcDiIssuesFilter;
        if ( diCfg ) {
            var $diSel = $( '<select id="tc_di_issues_filter" name="tc_di_issues_filter"></select>' );
            $diSel.css( 'max-width', '150px' );
            $diSel.append( $( '<option>' ).val( '' ).text( diCfg.all ) );
            $diSel.append( $( '<option>' ).val( 'not_delegated' ).text( diCfg.not_delegated ).prop( 'selected', diCfg.current === 'not_delegated' ) );
            $diSel.append( $( '<option>' ).val( 'synced' ).text( diCfg.synced ).prop( 'selected', diCfg.current === 'synced' ) );
            $diSel.append( $( '<option>' ).val( 'stale' ).text( diCfg.stale ).prop( 'selected', diCfg.current === 'stale' ) );

            var $diContainer = $( '<div class="tc-di-issues-filter atcf-term-filters"></div>' ).append( $diSel );
            // Insert immediately after External Links container when present so order is
            // [Ext Links] [Sync Status]; fall back to tcInjectFilter if Ext Links is absent.
            if ( cfg && $container && $container.length ) {
                $container.after( $diContainer );
                $( document ).trigger( 'atcf:termFilterInserted', [ $diContainer ] );
            } else {
                tcInjectFilter( $diContainer );
            }

            function syncTcDiIssuesFilter() {
                var $col = $( '.wp-list-table th.column-tc_di_issues' );
                var isHidden = $col.length && ( $col.hasClass( 'hidden' ) || $col.css( 'display' ) === 'none' );
                $( '#tc_di_issues_filter' )[ isHidden ? 'hide' : 'show' ]();
            }
            syncTcDiIssuesFilter();
            tcMakeObserver( syncTcDiIssuesFilter );
        }

    } );
    </script>
    <script>
    ( function ( $ ) {
        // Navigate on change (resets to page 1), same as ATCF's location filter.
        $( document ).on( 'change', '#tc_ext_links_filter', function () {
            var url = new URL( window.location.href );
            var val = $( this ).val();
            if ( val ) {
                url.searchParams.set( 'tc_ext_links_filter', val );
            } else {
                url.searchParams.delete( 'tc_ext_links_filter' );
            }
            url.searchParams.delete( 'paged' );
            window.location.href = url.toString();
        } );
        $( document ).on( 'change', '#tc_di_issues_filter', function () {
            var url = new URL( window.location.href );
            var val = $( this ).val();
            if ( val ) {
                url.searchParams.set( 'tc_di_issues_filter', val );
            } else {
                url.searchParams.delete( 'tc_di_issues_filter' );
            }
            url.searchParams.delete( 'paged' );
            window.location.href = url.toString();
        } );
    } )( jQuery );
    </script>
    <?php
}
