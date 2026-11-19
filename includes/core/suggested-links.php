<?php
/**
 * External Identifier Suggested Links
 *
 * Stores anonymous suggestions as WordPress comments (comment_type='tconn_suggested_link').
 * A suggestion belongs to a term, not a post: comment_post_ID is 0 and the term ID is in
 * comment meta TCONN_META_SUGGESTION_TERM_ID. (Term and post IDs are separate sequences, so
 * using comment_post_ID for the term would attach the suggestion to whichever post has
 * that ID — counted in its pending-comments bubble, and deleted along with it.)
 * comment_author_url holds the submitted URL; taxonomy and term name are derived from
 * the term at render time.
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TCONN_META_SUGGESTION_TERM_ID', '_tconn_term_id' );

/**
 * Term a link suggestion was made for.
 *
 * @since 1.0.0
 * @param WP_Comment|int $comment Suggestion comment or its ID.
 * @return int Term ID, or 0 when missing.
 */
function tconn_suggested_link_term_id( $comment ): int {
    $comment_id = $comment instanceof WP_Comment ? (int) $comment->comment_ID : (int) $comment;
    return (int) get_comment_meta( $comment_id, TCONN_META_SUGGESTION_TERM_ID, true );
}

/**
 * IDs of pending link suggestions for a term, optionally only those for one URL.
 *
 * @since 1.0.0
 * @param int    $term_id Term ID.
 * @param string $url     Submitted URL to match exactly ('' = any).
 * @return int[]
 */
function tconn_pending_suggested_link_ids( int $term_id, string $url = '' ): array {
    global $wpdb;
    if ( '' === $url ) {
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT c.comment_ID FROM {$wpdb->comments} c
             JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = %s
             WHERE c.comment_type = 'tconn_suggested_link' AND c.comment_approved = '0' AND m.meta_value = %s",
            TCONN_META_SUGGESTION_TERM_ID,
            (string) $term_id
        ) );
    } else {
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT c.comment_ID FROM {$wpdb->comments} c
             JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = %s
             WHERE c.comment_type = 'tconn_suggested_link' AND c.comment_approved = '0' AND m.meta_value = %s
               AND c.comment_author_url = %s",
            TCONN_META_SUGGESTION_TERM_ID,
            (string) $term_id,
            $url
        ) );
    }
    return array_map( 'intval', (array) $ids );
}

// ============================================================================
// SUBMISSION
// ============================================================================

add_action( 'wp_ajax_nopriv_tconn_suggested_link_submit', 'tconn_ajax_suggested_link_submit' );
add_action( 'wp_ajax_tconn_suggested_link_submit',        'tconn_ajax_suggested_link_submit' );

/**
 * Accept an anonymous or logged-in identifier suggestion for a term.
 * Always returns {success:true} to avoid enumeration.
 *
 * @since 1.0.0
 */
function tconn_ajax_suggested_link_submit(): void {
    // Nonce check — works for both logged-in and anonymous users.
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'tconn_suggested_link' ) ) {
        wp_send_json_success(); // Always return success to avoid enumeration.
    }

    // Honeypot — bots fill this field; humans leave it empty.
    if ( ! empty( $_POST['tconn_honeypot'] ) ) {
        wp_send_json_success();
    }

    $term_id = absint( $_POST['term_id'] ?? 0 );
    $url     = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
    $comment = sanitize_textarea_field( wp_unslash( $_POST['comment'] ?? '' ) );

    if ( ! $term_id || ! $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
        wp_send_json_success();
    }

    $term = get_term( $term_id );
    if ( ! $term || is_wp_error( $term ) ) {
        wp_send_json_success();
    }
    if ( ! in_array( $term->taxonomy, TConn_Settings::get_enabled_taxonomies(), true ) ) {
        wp_send_json_success();
    }

    // Rate limit: max 3 per IP per term per day; max 10 per IP globally per hour (skipped for logged-in users).
    $rate_key = null;
    $rate     = 0;
    if ( ! is_user_logged_in() ) {
        $ip_hash  = md5( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
        $rate_key = 'tconn_sugg_rate_' . $ip_hash . '_' . $term_id;
        $rate     = (int) get_transient( $rate_key );
        if ( $rate >= 3 ) {
            wp_send_json_success();
        }
        $global_key   = 'tconn_suggest_global_' . $ip_hash;
        $global_count = (int) get_transient( $global_key );
        if ( $global_count >= 10 ) {
            // Respond exactly as the per-term limit does, so the response never
            // reveals which rate limit was hit (no enumeration signal).
            wp_send_json_success();
        }
        set_transient( $global_key, $global_count + 1, HOUR_IN_SECONDS );
    }

    // Deduplicate: same URL already pending for this term.
    if ( tconn_pending_suggested_link_ids( $term_id, $url ) ) {
        wp_send_json_success();
    }

    // Check if URL is already an active external link for this term.
    $existing_links = tconn_get_term_links( $term_id, false );
    foreach ( $existing_links as $peer_site_slug => $value ) {
        $link_type = tconn_get_link_type( $peer_site_slug );
        if ( $link_type && rtrim( ( $link_type['uri_prefix'] ?? '' ), '/' ) . '/' === rtrim( $url, '/' ) . '/' ) {
            wp_send_json_success();
        }
        if ( $link_type && ( $link_type['uri_prefix'] ?? '' ) && strpos( $url, $link_type['uri_prefix'] ) === 0 ) {
            $extracted = substr( $url, strlen( $link_type['uri_prefix'] ) );
            if ( '' !== $extracted && $value === $extracted ) {
                wp_send_json_success();
            }
        }
    }

    $current_user_id = get_current_user_id();
    $comment_data    = array(
        'comment_post_ID'      => 0,
        'comment_meta'         => array( TCONN_META_SUGGESTION_TERM_ID => $term_id ),
        'comment_type'         => 'tconn_suggested_link',
        'comment_author_url'   => $url,
        'comment_content'      => $comment,
        'comment_author_email' => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
        'comment_author'       => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
        'comment_author_IP'    => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
        'comment_agent'        => substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 254 ),
        'comment_approved'     => '0',
    );
    if ( $current_user_id ) {
        $comment_data['user_id'] = $current_user_id;
    }
    $comment_id = wp_insert_comment( $comment_data );

    if ( $comment_id ) {
        if ( $rate_key ) {
            set_transient( $rate_key, $rate + 1, DAY_IN_SECONDS );
        }
        tconn_suggested_link_notify_admin( $comment_id, $term );
    }

    wp_send_json_success();
}

/**
 * Send a TC-specific admin notification for a new suggested link.
 * Suppresses the default WP "new comment" email via the notify_moderator filter.
 * Follows the same format as WP's native comment moderation email.
 *
 * @since 1.0.0
 * @param int     $comment_id Comment ID.
 * @param WP_Term $term       The term the suggestion is for.
 */
function tconn_suggested_link_notify_admin( int $comment_id, WP_Term $term ): void {
    $options = get_option( 'tconn_settings', array() );
    if ( isset( $options['suggested_link_notifications'] ) && empty( $options['suggested_link_notifications'] ) ) {
        return;
    }

    $comment = get_comment( $comment_id );
    if ( ! $comment ) {
        return;
    }

    $admin_email = get_option( 'admin_email' );
    $site_name   = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
    $term_name   = html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

    $author_ip = $comment->comment_author_IP;

    /* translators: %s: term name */
    $msg  = sprintf( __( 'A new identifier suggestion for the term "%s" is waiting for your review', 'termconnect' ), $term_name ) . "\r\n";
    $term_link = get_term_link( $term );
    $msg .= ( is_string( $term_link ) ? $term_link : '' ) . "\r\n\r\n";

    if ( $comment->comment_author ) {
        /* translators: 1: submitter name, 2: IP address */
        $msg .= sprintf( __( 'Author: %1$s (IP address: %2$s)', 'termconnect' ), $comment->comment_author, $author_ip ) . "\r\n";
    } else {
        /* translators: %s: IP address */
        $msg .= sprintf( __( 'Author: (IP address: %s)', 'termconnect' ), $author_ip ) . "\r\n";
    }

    if ( $comment->comment_author_email ) {
        /* translators: %s: email address */
        $msg .= sprintf( __( 'Email: %s', 'termconnect' ), $comment->comment_author_email ) . "\r\n";
    }

    /* translators: %s: proposed URL */
    $msg .= sprintf( __( 'Proposed URL: %s', 'termconnect' ), $comment->comment_author_url ) . "\r\n";

    $match = tconn_suggested_link_match_url( $comment->comment_author_url );
    if ( $match ) {
        /* translators: 1: link type label, 2: matched identifier value */
        $msg .= sprintf( __( 'Recognized as: %1$s → %2$s', 'termconnect' ), $match['label'], $match['value'] ) . "\r\n";
    }

    // Check whether the URL comes from a configured HyperTaxonomy peer site.
    $url_scheme  = (string) wp_parse_url( $comment->comment_author_url, PHP_URL_SCHEME );
    $url_host    = (string) wp_parse_url( $comment->comment_author_url, PHP_URL_HOST );
    $url_origin  = rtrim( $url_scheme . '://' . $url_host, '/' );
    $is_peer_site = $url_origin !== '://' && function_exists( 'tconn_external_is_ht_enabled' ) && tconn_external_is_ht_enabled( $url_origin );

    if ( $comment->comment_content ) {
        /* translators: %s: submitter note */
        $msg .= "\r\n" . sprintf( __( 'Note: %s', 'termconnect' ), $comment->comment_content ) . "\r\n";
    }

    $msg .= "\r\n";

    if ( $match ) {
        $accept_url = add_query_arg( array(
            'taxonomy' => $term->taxonomy,
            'tag_ID'   => $term->term_id,
            'tconn_lt'    => $match['peer_site_slug'],
            'tconn_value' => $match['value'],
        ), admin_url( 'term.php' ) );
        /* translators: %s: accept URL */
        $msg .= sprintf( __( 'Add as external link: %s', 'termconnect' ), $accept_url ) . "\r\n";
    }

    if ( $is_peer_site ) {
        $term_edit_url = add_query_arg( array(
            'taxonomy' => $term->taxonomy,
            'tag_ID'   => $term->term_id,
        ), admin_url( 'term.php' ) );
        /* translators: %s: term edit URL */
        $msg .= sprintf( __( 'Delegate identity to this peer site: %s', 'termconnect' ), $term_edit_url ) . "\r\n";
    } elseif ( ! $match ) {
        $add_type_url = add_query_arg( 'tconn_suggest_url', rawurlencode( $comment->comment_author_url ), admin_url( 'admin.php?page=tconn-peer-sites' ) );
        /* translators: %s: add link type URL */
        $msg .= sprintf( __( 'Add link type or peer site: %s', 'termconnect' ), $add_type_url ) . "\r\n";
        $msg .= __( '(Not a configured peer site — identity delegation requires adding it as a peer site first)', 'termconnect' ) . "\r\n";
    }

    /* translators: %s: trash URL */
    $msg .= sprintf( __( 'Trash it: %s', 'termconnect' ), admin_url( "comment.php?action=trash&c={$comment_id}#wpbody-content" ) ) . "\r\n";

    global $wpdb;
    $pending = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = 'tconn_suggested_link' AND comment_approved = '0'"
    );

    $msg .= "\r\n" . sprintf(
        /* translators: %s: number of suggestions */
        _n(
            'Currently %s suggestion is waiting for review.',
            'Currently %s suggestions are waiting for review.',
            $pending,
            'termconnect'
        ),
        number_format_i18n( $pending )
    ) . "\r\n";
    /* translators: %s: suggested identifiers admin URL */
    $msg .= sprintf( __( 'Review all suggestions: %s', 'termconnect' ), admin_url( 'edit-comments.php?type=tconn_suggested_link' ) ) . "\r\n";

    /* translators: 1: site name, 2: term name */
    $subject = sprintf( __( '[%1$s] Please review: identifier suggestion for "%2$s"', 'termconnect' ), $site_name, $term_name );

    wp_mail( $admin_email, $subject, $msg );
}

// Suppress the default WP moderator notification for tconn_suggested_link comments.
add_filter( 'notify_moderator', static function ( bool $notify, int $comment_id ): bool {
    $c = get_comment( $comment_id );
    return ( $c && 'tconn_suggested_link' === $c->comment_type ) ? false : $notify;
}, 10, 2 );

// Trash pending suggestions when their target term is permanently deleted.
add_action( 'deleted_term', static function ( int $term_id ): void {
    foreach ( tconn_pending_suggested_link_ids( $term_id ) as $comment_id ) {
        wp_trash_comment( $comment_id );
    }
}, 10, 1 );

// deleted_term does not fire during bulk taxonomy operations; delete_term_taxonomy does.
add_action( 'delete_term_taxonomy', static function ( int $tt_id ): void {
    $term = get_term_by( 'term_taxonomy_id', $tt_id );
    if ( ! ( $term instanceof WP_Term ) ) {
        return;
    }
    foreach ( tconn_pending_suggested_link_ids( $term->term_id ) as $comment_id ) {
        wp_trash_comment( $comment_id );
    }
}, 10, 1 );

// ============================================================================
// FRONTEND FORM ON TERM ARCHIVES
// ============================================================================

add_filter( 'get_the_archive_description', 'tconn_append_suggested_link_form', 20 );

// When HyperTaxonomy handles the description block, inject the suggest form inside
// .htax-archive-description via HT's append filter (runs after see-also at priority 10).
add_filter( 'htax_archive_description_append', static function ( string $append, WP_Term $term ): string {
    $options = get_option( 'tconn_settings', array() );
    if ( empty( $options['allow_suggested_links'] ) ) {
        return $append;
    }
    if ( ! in_array( $term->taxonomy, TConn_Settings::get_enabled_taxonomies(), true ) ) {
        return $append;
    }
    if ( null !== tconn_get_identity_source( $term->term_id ) ) {
        return $append;
    }
    return tconn_build_suggested_link_append_html( $append, $term );
}, 20, 2 );

/**
 * Inject the "Suggest a related concept" trigger into the term archive description.
 *
 * When a See Also block is already present (added at priority 10 by display.php),
 * this function rewraps it and the trigger in a side-by-side flex row. The mini
 * form lives in a floating pane that opens on click.
 *
 * @since 1.0.0
 * @param string $description Current archive description HTML.
 * @return string
 */
function tconn_append_suggested_link_form( string $description ): string {
    global $htax_handling_archive_description;
    if ( ! empty( $htax_handling_archive_description ) ) {
        return $description;
    }

    $options = get_option( 'tconn_settings', array() );
    if ( empty( $options['allow_suggested_links'] ) ) {
        return $description;
    }

    if ( ! is_tax() && ! is_category() && ! is_tag() ) {
        return $description;
    }

    $term = get_queried_object();
    if ( ! ( $term instanceof WP_Term ) ) {
        return $description;
    }
    if ( ! in_array( $term->taxonomy, TConn_Settings::get_enabled_taxonomies(), true ) ) {
        return $description;
    }

    // Skip delegated-identity terms — they already have authoritative identifiers.
    if ( null !== tconn_get_identity_source( $term->term_id ) ) {
        return $description;
    }

    return tconn_build_suggested_link_append_html( $description, $term );
}

/**
 * Build the trigger button, floating pane, and (if present) wrap an existing See Also
 * block into a side-by-side flex row.
 *
 * @since 1.0.0
 * @param string  $description Accumulated description/append HTML to modify.
 * @param WP_Term $term        The queried term.
 * @return string
 */
function tconn_build_suggested_link_append_html( string $description, WP_Term $term ): string {
    $options  = get_option( 'tconn_settings', array() );
    $nonce    = wp_create_nonce( 'tconn_suggested_link' );
    $term_id  = $term->term_id;
    $ajax_url = admin_url( 'admin-ajax.php' );

    $label = sanitize_text_field( $options['suggested_link_label'] ?? '' );
    if ( '' === $label ) {
        $label = __( 'Suggest a related concept', 'termconnect' );
    }

    // Dashicons for the trigger icon, the form's styles, and its script. Enqueued while
    // the archive renders, so they print in the footer. The markup is printed through
    // wp_kses() by HyperTaxonomy, which removes script elements and some CSS properties,
    // so neither lives inline.
    wp_enqueue_style( 'dashicons' );
    wp_enqueue_style( 'tconn-suggest-link', TCONN_PLUGIN_URL . 'assets/css/tconn-suggest-link.css', array( 'dashicons' ), TCONN_VERSION );
    if ( ! wp_script_is( 'tconn-suggest-link', 'enqueued' ) ) {
        wp_enqueue_script( 'tconn-suggest-link', TCONN_PLUGIN_URL . 'assets/js/tconn-suggest-link.js', array(), TCONN_VERSION, true );
        wp_localize_script( 'tconn-suggest-link', 'TConn_SuggestLink', array(
            'ajaxUrl'    => $ajax_url,
            'invalidUrl' => __( 'Please enter a valid URL (e.g. https://example.com/page)', 'termconnect' ),
        ) );
    }

    // Build the trigger button (styled as a link via CSS).
    $trigger = '<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>'
        . '<button type="button" class="tconn-suggest-trigger">'
        . esc_html( $label )
        . '</button>'
        . '<span class="tconn-suggest-thankyou" style="display:none">'
        . esc_html__( 'Thank you!', 'termconnect' )
        . '</span>';

    // Build the floating pane with the mini form.
    ob_start();
    ?>
<div class="tconn-suggest-pane" style="display:none">
    <form class="tconn-suggested-link-form">
        <input type="hidden" name="tconn_honeypot" value="">
        <input type="hidden" name="term_id" value="<?php echo esc_attr( $term_id ); ?>">
        <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
        <input type="text" name="url" class="tconn-suggested-link-url" autocomplete="off" value="https://">
        <div class="tconn-suggested-link-actions">
            <button type="submit" class="button tconn-suggested-link-submit">
                <?php esc_html_e( 'Suggest', 'termconnect' ); ?>
            </button>
            <textarea name="comment" class="tconn-suggested-link-comment"
                      placeholder="<?php esc_attr_e( 'Optional note', 'termconnect' ); ?>"></textarea>
        </div>
    </form>
</div>
    <?php
    $pane_html = ob_get_clean();

    // Find the See Also block in the description (already added at priority 10).
    // Wrap it and the trigger side-by-side in a flex row.
    $see_also_pos = strpos( $description, 'class="tconn-term-links-see-also"' );
    if ( false !== $see_also_pos ) {
        // Walk back to the opening tag delimiter.
        $tag_start = strrpos( substr( $description, 0, $see_also_pos ), '<' );
        if ( false !== $tag_start ) {
            // Determine the element tag (p or ul) to find the matching close tag.
            $is_ul    = ( substr( $description, $tag_start + 1, 2 ) === 'ul' );
            $close    = $is_ul ? '</ul>' : '</p>';
            $close_at = strpos( $description, $close, $see_also_pos );
            if ( false !== $close_at ) {
                $before   = substr( $description, 0, $tag_start );
                $see_also = substr( $description, $tag_start, $close_at + strlen( $close ) - $tag_start );
                $after    = substr( $description, $close_at + strlen( $close ) );

                $row = '<div class="tconn-term-links-row">'
                    . '<div class="tconn-term-links-see-also-col">' . $see_also . '</div>'
                    . '<div class="tconn-term-links-suggest-col">' . $trigger . '</div>'
                    . '</div>'
                    . $pane_html;

                return $before . $row . $after;
            }
        }
    }

    // No See Also block present — append trigger standalone.
    return $description
        . '<div class="tconn-term-links-row">'
        . '<div class="tconn-term-links-suggest-col">' . $trigger . '</div>'
        . '</div>'
        . $pane_html;
}

/**
 * Match a URL against configured link type URI prefixes.
 * Returns [ peer_site_slug, label, value, enabled ] or null.
 *
 * @since 1.0.0
 * @param string $url
 * @return array{peer_site_slug:string,label:string,value:string,enabled:bool}|null
 */
function tconn_suggested_link_match_url( string $url ): ?array {
    $types = tconn_get_all_link_types();
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
