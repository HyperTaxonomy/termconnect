<?php
/**
 * Suggested Links — admin UI
 *
 * Manages the tc_suggested_link comment type in the WordPress admin:
 * - Excludes suggestions from default comment queries and badge counts.
 * - Registers the Comments → Link Suggestions submenu.
 * - Customizes the comments list table for tc_suggested_link type.
 * - Handles the Approve transition: resolves the URL, creates the link, auto-trashes.
 * - Adds a per-term collapsible panel in the External Links section.
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================================
// CAPABILITY: allow edit_comment on tc_suggested_link comments
// ============================================================================

// WP's edit_comment capability check requires editing the post attached to the comment.
// Suggestions belong to a term and have no post (comment_post_ID = 0), so the default check
// always fails and WP_Comments_List_Table hides all row actions. Map to manage_options instead.
add_filter( 'map_meta_cap', static function ( array $caps, string $cap, int $user_id, array $args ): array {
    if ( 'edit_comment' !== $cap ) {
        return $caps;
    }
    $comment = get_comment( $args[0] ?? 0 );
    if ( $comment && 'tc_suggested_link' === $comment->comment_type ) {
        return array( 'manage_options' );
    }
    return $caps;
}, 10, 4 );

// WP_Comments_List_Table::get_default_primary_column_name() always returns 'comment'.
// When 'comment' is absent from our column set, WP falls back to the same default and
// no column gets the 'has-row-actions' class, so row actions are never rendered.
// Force the primary column to our first content column on the suggestions page.
add_filter( 'list_table_primary_column', static function ( string $column, string $screen_id ): string {
    if ( 'edit-comments' === $screen_id
        && 'tc_suggested_link' === sanitize_key( wp_unslash( $_GET['type'] ?? '' ) ) ) {
        return 'tc_proposed_url';
    }
    return $column;
}, 10, 2 );

// ============================================================================
// EXCLUDE FROM DEFAULT QUERIES + BADGE COUNT
// ============================================================================

add_filter( 'pre_get_comments', static function ( WP_Comment_Query $q ): void {
    if ( 'tc_suggested_link' === ( $q->query_vars['type'] ?? '' ) ) {
        return;
    }
    if ( 'tc_suggested_link' === sanitize_key( wp_unslash( $_GET['type'] ?? '' ) ) ) {
        return;
    }
    $not_in   = (array) ( $q->query_vars['type__not_in'] ?? array() );
    $not_in[] = 'tc_suggested_link';
    $q->set( 'type__not_in', $not_in );
} );

// WP ignores unknown custom comment types in its built-in filtering; inject the WHERE directly.
add_filter( 'comments_clauses', static function ( array $clauses, WP_Comment_Query $q ): array {
    $type = sanitize_key( wp_unslash( $_GET['type'] ?? '' ) );
    global $wpdb;
    if ( 'tc_suggested_link' === $type ) {
        // Inclusion: force only tc_suggested_link rows.
        if ( false === strpos( $clauses['where'], "comment_type = 'tc_suggested_link'" ) ) {
            $clauses['where'] .= $wpdb->prepare( ' AND comment_type = %s', 'tc_suggested_link' );
        }
    } elseif ( is_admin() ) {
        // Exclusion: keep tc_suggested_link out of every other admin view.
        if ( false === strpos( $clauses['where'], 'tc_suggested_link' ) ) {
            $clauses['where'] .= $wpdb->prepare( ' AND comment_type <> %s', 'tc_suggested_link' );
        }
    }
    return $clauses;
}, 10, 2 );

// ============================================================================
// VIEWS: rewrite filter-links to preserve ?type=tc_suggested_link
// ============================================================================

add_filter( 'views_edit-comments', static function ( array $views ): array {
    if ( 'tc_suggested_link' !== sanitize_key( wp_unslash( $_GET['type'] ?? '' ) ) ) {
        return $views;
    }
    foreach ( $views as $status => $link ) {
        $views[ $status ] = preg_replace_callback(
            '/href="([^"]+)"/',
            static function ( array $m ): string {
                return 'href="' . esc_url( add_query_arg( 'type', 'tc_suggested_link', $m[1] ) ) . '"';
            },
            $link
        );
    }
    return $views;
} );

// ============================================================================
// BULK ACTIONS
// ============================================================================

add_filter( 'bulk_actions-edit-comments', static function ( array $actions ): array {
    if ( 'tc_suggested_link' !== sanitize_key( wp_unslash( $_GET['type'] ?? '' ) ) ) {
        return $actions;
    }
    unset( $actions['approve'], $actions['unapprove'] );
    return $actions;
} );

// ============================================================================
// SUBMENU under Comments with per-item badges
// ============================================================================

add_action( 'admin_menu', static function (): void {
    global $wpdb;

    $suggestion_pending = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->comments}
         WHERE comment_type = 'tc_suggested_link' AND comment_approved = '0'"
    );

    $badge = static function ( int $n ): string {
        if ( $n < 1 ) {
            return '';
        }
        return ' <span class="awaiting-mod count-' . $n . '">'
            . '<span class="pending-count">' . number_format_i18n( $n ) . '</span></span>';
    };

    add_submenu_page(
        'edit-comments.php',
        __( 'Link Suggestions', 'termconnect' ),
        __( 'Link Suggestions', 'termconnect' ) . $badge( $suggestion_pending ),
        'manage_options',
        'edit-comments.php?type=tc_suggested_link',
        ''
    );
}, 20 );

// Add a per-item pending badge to the "All Comments" submenu entry.
add_action( 'admin_menu', static function (): void {
    global $submenu, $wpdb;

    $native_pending = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->comments}
         WHERE comment_approved = '0' AND comment_type <> 'tc_suggested_link'"
    );

    if ( $native_pending < 1 || empty( $submenu['edit-comments.php'] ) ) {
        return;
    }
    foreach ( $submenu['edit-comments.php'] as $idx => $item ) {
        if ( isset( $item[2] ) && 'edit-comments.php' === $item[2] ) {
            $submenu['edit-comments.php'][ $idx ][0] .=
                ' <span class="awaiting-mod count-' . $native_pending . '">'
                . '<span class="pending-count">' . number_format_i18n( $native_pending ) . '</span></span>';
            break;
        }
    }
}, 30 );

// ============================================================================
// COLUMNS: rename "response" to show Term, customize Comment column
// ============================================================================

add_filter( 'manage_edit-comments_columns', static function ( array $cols ): array {
    if ( sanitize_key( wp_unslash( $_GET['type'] ?? '' ) ) !== 'tc_suggested_link' ) {
        return $cols;
    }
    // Replace built-in columns entirely to avoid WP's native rendering of 'response'/'comment'.
    $new = array();
    if ( isset( $cols['cb'] ) ) {
        $new['cb'] = $cols['cb'];
    }
    $new['tc_sender']       = __( 'Sender', 'termconnect' );
    $new['tc_proposed_url'] = __( 'Proposed URL', 'termconnect' );
    $new['tc_comment']      = __( 'Note', 'termconnect' );
    $new['tc_term']         = __( 'Term', 'termconnect' );
    $new['date']            = $cols['date'] ?? __( 'Submitted on', 'termconnect' );
    return $new;
} );

add_action( 'manage_comments_custom_column', static function ( string $col, int $comment_id ): void {
    if ( sanitize_key( wp_unslash( $_GET['type'] ?? '' ) ) !== 'tc_suggested_link' ) {
        return;
    }
    $comment = get_comment( $comment_id );
    if ( ! $comment || 'tc_suggested_link' !== $comment->comment_type ) {
        return;
    }

    if ( 'tc_sender' === $col ) {
        // Avatar — native comments pass the comment object; WP falls back to email.
        echo get_avatar( $comment, 32, 'mystery', '', array( 'class' => 'avatar' ) );

        // Author name: prefer WP user display name if submitted while logged in.
        if ( $comment->user_id ) {
            $user = get_userdata( (int) $comment->user_id );
        } else {
            $user = false;
        }

        echo '<strong>';
        if ( $user ) {
            $profile_url = esc_url( add_query_arg( 'user_id', $comment->user_id, admin_url( 'user-edit.php' ) ) );
            echo '<a href="' . $profile_url . '">' . esc_html( $user->display_name ) . '</a>';
        } elseif ( $comment->comment_author ) {
            echo esc_html( $comment->comment_author );
        } else {
            echo '<em style="color:#888">' . esc_html__( '(anonymous)', 'termconnect' ) . '</em>';
        }
        echo '</strong>';

        // IP address — linked to filter suggestions by that IP, same pattern as native comments.
        if ( $comment->comment_author_IP ) {
            $ip_url = esc_url( add_query_arg(
                array( 's' => $comment->comment_author_IP, 'comment_status' => 'all', 'type' => 'tc_suggested_link' ),
                admin_url( 'edit-comments.php' )
            ) );
            echo '<br><a href="' . $ip_url . '">' . esc_html( $comment->comment_author_IP ) . '</a>';
        }
    }

    if ( 'tc_term' === $col ) {
        $term = get_term( tc_suggested_link_term_id( $comment ) );
        if ( $term && ! is_wp_error( $term ) ) {
            $edit_url = get_edit_term_link( $term->term_id, $term->taxonomy );
            echo '<strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $term->name ) . '</a></strong>';
            echo '<br><span style="color:#888">' . esc_html( $term->taxonomy ) . '</span>';
        } else {
            echo '<em style="color:#888">' . esc_html__( '(deleted term)', 'termconnect' ) . '</em>';
        }
    }

    if ( 'tc_proposed_url' === $col ) {
        $url = $comment->comment_author_url;
        echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a>';

        // Auto-match against configured link types.
        $match = tc_suggested_link_match_url( $url );
        if ( $match ) {
            echo '<br><span style="color:#2271b1">' . esc_html( $match['label'] . ': ' . $match['value'] ) . '</span>';
        } else {
            echo '<br><span style="color:#888"><em>' . esc_html__( '— unrecognized —', 'termconnect' ) . '</em></span>';
        }
    }

    if ( 'tc_comment' === $col ) {
        if ( $comment->comment_content ) {
            echo '<span style="color:#555">' . esc_html( $comment->comment_content ) . '</span>';
        }
        if ( $comment->comment_author_email ) {
            echo ( $comment->comment_content ? '<br>' : '' )
                . '<span style="color:#888;font-size:11px">' . esc_html( $comment->comment_author_email ) . '</span>';
        }
    }
}, 10, 2 );

// ============================================================================
// ROW ACTIONS
// ============================================================================

add_filter( 'comment_row_actions', static function ( array $actions, WP_Comment $comment ): array {
    if ( 'tc_suggested_link' !== $comment->comment_type ) {
        return $actions;
    }

    // Remove Reply — not meaningful for suggestions.
    unset( $actions['reply'] );

    $url   = $comment->comment_author_url;
    $term  = get_term( tc_suggested_link_term_id( $comment ) );

    // Determine primary action. tc_is_self_origin() expects a bare scheme://host origin,
    // so extract just that portion before comparing.
    $url_origin = parse_url( $url, PHP_URL_SCHEME ) . '://' . parse_url( $url, PHP_URL_HOST );
    if ( '://' !== $url_origin && tc_is_self_origin( $url_origin ) ) {
        // Local URL — link to term edit if resolvable.
        $local_term = tc_suggested_link_resolve_local_term( $url );
        if ( $local_term ) {
            $edit_url = get_edit_term_link( $local_term->term_id, $local_term->taxonomy );
            // If HT is active, pass the receiving term's ID so its pill is highlighted
            // on the suggested term's edit page when the two are already related.
            if ( $term && ! is_wp_error( $term ) && function_exists( 'htax_get_included_terms' ) ) {
                $edit_url = add_query_arg( 'context_term_id', $term->term_id, $edit_url );
            }
            $actions['tc_edit_term'] = '<a href="' . esc_url( $edit_url ) . '">'
                . esc_html__( 'Edit term', 'termconnect' ) . '</a>';
        } else {
            $actions['tc_edit_term'] = '<a href="' . esc_url( admin_url( 'edit-tags.php' ) ) . '">'
                . esc_html__( 'Edit term', 'termconnect' ) . '</a>';
        }
        unset( $actions['approve'] );
    } elseif ( $term && ! is_wp_error( $term ) ) {
        $match     = tc_suggested_link_match_url( $url );
        $link_exists = $match && tc_suggested_link_exists( $term->term_id, $match['peer_site_slug'], $match['value'] );

        if ( $link_exists ) {
            unset( $actions['approve'] );
        } elseif ( $match && ! empty( $match['enabled'] ) ) {
            $accept_url = add_query_arg( array(
                'taxonomy'  => $term->taxonomy,
                'tag_ID'    => $term->term_id,
                'tc_lt'     => $match['peer_site_slug'],
                'tc_value'  => $match['value'],
            ), admin_url( 'term.php' ) );
            $actions['tc_accept'] = '<a href="' . esc_url( $accept_url ) . '">'
                . esc_html__( 'Accept', 'termconnect' ) . '</a>';
            unset( $actions['approve'] );
        } elseif ( $match ) {
            // Known link type but not yet enabled — prompt admin to enable it.
            $enable_url = admin_url( 'admin.php?page=tc-peer-sites' );
            $actions['tc_enable_lt'] = '<a href="' . esc_url( $enable_url ) . '">'
                . esc_html__( 'Enable link type', 'termconnect' ) . '</a>';
            unset( $actions['approve'] );
        } else {
            $label = ( function_exists( 'tc_external_is_ht_enabled' ) && tc_external_is_ht_enabled( rtrim( parse_url( $url, PHP_URL_SCHEME ) . '://' . parse_url( $url, PHP_URL_HOST ), '/' ) ) )
                ? __( 'Add WordPress peer', 'termconnect' )
                : __( 'Add link type', 'termconnect' );
            $add_url = add_query_arg( 'tc_suggest_url', rawurlencode( $url ), admin_url( 'admin.php?page=tc-peer-sites' ) );
            $actions['tc_add_lt'] = '<a href="' . esc_url( $add_url ) . '">' . esc_html( $label ) . '</a>';
            unset( $actions['approve'] );
        }
    }

    return $actions;
}, 10, 2 );

// ============================================================================
// APPROVE TRANSITION: create link, auto-trash on success
// ============================================================================

add_action( 'comment_unapproved_to_approved', static function ( WP_Comment $comment ): void {
    if ( 'tc_suggested_link' !== $comment->comment_type ) {
        return;
    }

    $term = get_term( tc_suggested_link_term_id( $comment ) );
    if ( ! $term || is_wp_error( $term ) ) {
        wp_trash_comment( $comment->comment_ID );
        return;
    }

    $url   = $comment->comment_author_url;
    $match = tc_suggested_link_match_url( $url );

    if ( ! $match ) {
        // No match — revert to pending and leave a note.
        wp_set_comment_status( $comment->comment_ID, 'hold' );
        wp_insert_comment( array(
            'comment_post_ID'  => 0,
            'comment_meta'     => array( TC_META_SUGGESTION_TERM_ID => $term->term_id ),
            'comment_type'     => 'tc_suggested_link',
            'comment_content'  => __( 'No matching link type found. Create one at Terms → Peer Sites and try again.', 'termconnect' ),
            'comment_approved' => '0',
            'comment_parent'   => $comment->comment_ID,
        ) );
        return;
    }

    if ( tc_suggested_link_exists( $term->term_id, $match['peer_site_slug'], $match['value'] ) ) {
        // Link already exists — task is done, trash the suggestion.
        wp_trash_comment( $comment->comment_ID );
        return;
    }

    tc_set_term_link( $term->term_id, $match['peer_site_slug'], $match['value'] );

    wp_trash_comment( $comment->comment_ID );
} );

// ============================================================================
// HELPERS
// ============================================================================

/**
 * Match a URL against configured link type URI prefixes.
 * Returns [ peer_site_slug, label, value, enabled ] or null.
 *
 * @since 1.0.0
 * @param string $url
 * @return array{peer_site_slug:string,label:string,value:string,enabled:bool}|null
 */
function tc_suggested_link_match_url( string $url ): ?array {
    $types = tc_get_all_link_types();
    foreach ( $types as $peer_site_slug => $type ) {
        $prefix = $type['uri_prefix'] ?? '';
        if ( $prefix && strpos( $url, $prefix ) === 0 ) {
            $value = substr( $url, strlen( $prefix ) );
            if ( '' !== $value ) {
                return array(
                    'peer_site_slug' => $peer_site_slug,
                    'label'          => $type['label'] ?? $peer_site_slug,
                    'value'          => $value,
                    'enabled'        => ! empty( $type['enabled'] ),
                );
            }
        }
    }
    return null;
}

/**
 * Check whether a specific link already exists for a term.
 *
 * @since 1.0.0
 */
function tc_suggested_link_exists( int $term_id, string $peer_site_slug, string $value ): bool {
    $existing = tc_get_term_links( $term_id, false );
    return isset( $existing[ $peer_site_slug ] ) && $existing[ $peer_site_slug ] === $value;
}

/**
 * Attempt to resolve a local URL to a WP_Term by extracting the slug from the path.
 *
 * @since 1.0.0
 * @param string $url
 * @return WP_Term|null
 */
function tc_suggested_link_resolve_local_term( string $url ): ?WP_Term {
    $path = trim( (string) parse_url( $url, PHP_URL_PATH ), '/' );
    if ( ! $path ) {
        return null;
    }
    $slug = basename( $path );
    foreach ( TC_Settings::get_enabled_taxonomies() as $taxonomy ) {
        $term = get_term_by( 'slug', $slug, $taxonomy );
        if ( $term && ! is_wp_error( $term ) ) {
            return $term;
        }
    }
    return null;
}

// ============================================================================
// SORTABLE COLUMNS: Sender (comment_author), Term (term name), Date
// ============================================================================

add_filter( 'manage_edit-comments_sortable_columns', static function ( array $cols ): array {
    if ( 'tc_suggested_link' !== sanitize_key( wp_unslash( $_GET['type'] ?? '' ) ) ) {
        return $cols;
    }
    $cols['tc_sender'] = 'comment_author';
    $cols['tc_term']   = 'tc_term_name'; // Custom; requires JOIN added in comments_clauses.
    $cols['date']      = array( 'comment_date', false );
    return $cols;
} );

// Store tc_term_name intent before WP_Comment_Query rejects the unknown orderby value.
add_action( 'pre_get_comments', static function ( WP_Comment_Query $q ): void {
    if ( 'tc_suggested_link' !== sanitize_key( wp_unslash( $_GET['type'] ?? '' ) ) ) {
        return;
    }
    $orderby = sanitize_key( wp_unslash( $_GET['orderby'] ?? '' ) );
    if ( 'tc_term_name' === $orderby ) {
        $q->query_vars['_tc_term_name_order'] = sanitize_key( wp_unslash( $_GET['order'] ?? 'asc' ) );
        $q->query_vars['orderby']             = 'comment_date'; // Valid placeholder so WP won't reject it.
    }
}, 1 );

// Inject LEFT JOIN and override ORDER BY for term-name sorting.
add_filter( 'comments_clauses', static function ( array $clauses, WP_Comment_Query $q ): array {
    if ( empty( $q->query_vars['_tc_term_name_order'] ) ) {
        return $clauses;
    }
    global $wpdb;
    if ( false === strpos( $clauses['join'], 'tc_sort_terms' ) ) {
        $clauses['join'] .= $wpdb->prepare(
            " LEFT JOIN {$wpdb->commentmeta} AS tc_sort_meta ON tc_sort_meta.comment_id = {$wpdb->comments}.comment_ID AND tc_sort_meta.meta_key = %s"
            . " LEFT JOIN {$wpdb->terms} AS tc_sort_terms ON tc_sort_terms.term_id = CAST( tc_sort_meta.meta_value AS UNSIGNED )",
            TC_META_SUGGESTION_TERM_ID
        );
    }
    $order              = 'desc' === $q->query_vars['_tc_term_name_order'] ? 'DESC' : 'ASC';
    $clauses['orderby'] = "tc_sort_terms.name {$order}";
    return $clauses;
}, 20, 2 );

// ============================================================================
// ALTERNATING ROW COLORS: match native WP admin list table style
// ============================================================================

add_action( 'admin_head', static function (): void {
    if ( 'tc_suggested_link' !== sanitize_key( wp_unslash( $_GET['type'] ?? '' ) ) ) {
        return;
    }
    ?>
    <style>
    /* Alternating row colors at cell level — overrides WP's flat .unapproved td background. */
    #the-comment-list tr:nth-child(odd) td,
    #the-comment-list tr:nth-child(odd) th { background-color: #f6f7f7; }
    #the-comment-list tr:nth-child(even) td,
    #the-comment-list tr:nth-child(even) th { background-color: #fff; }
    /* Remove the pending status indicator border — suggestions are always pending on arrival,
       so the solid red left stripe carries no information and looks wrong. */
    #the-comment-list th.check-column { border-left: none !important; }
    </style>
    <?php
} );
