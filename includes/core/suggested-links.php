<?php
/**
 * External Identifier Suggested Links
 *
 * Stores anonymous suggestions as WordPress comments (comment_type='tc_suggested_link').
 * A suggestion belongs to a term, not a post: comment_post_ID is 0 and the term ID is in
 * comment meta TC_META_SUGGESTION_TERM_ID. (Term and post IDs are separate sequences, so
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

define( 'TC_META_SUGGESTION_TERM_ID', '_tc_term_id' );

/**
 * Term a link suggestion was made for.
 *
 * @since 1.0.0
 * @param WP_Comment|int $comment Suggestion comment or its ID.
 * @return int Term ID, or 0 when missing.
 */
function tc_suggested_link_term_id( $comment ): int {
    $comment_id = $comment instanceof WP_Comment ? (int) $comment->comment_ID : (int) $comment;
    return (int) get_comment_meta( $comment_id, TC_META_SUGGESTION_TERM_ID, true );
}

/**
 * IDs of pending link suggestions for a term, optionally only those for one URL.
 *
 * @since 1.0.0
 * @param int    $term_id Term ID.
 * @param string $url     Submitted URL to match exactly ('' = any).
 * @return int[]
 */
function tc_pending_suggested_link_ids( int $term_id, string $url = '' ): array {
    global $wpdb;
    $sql  = "SELECT c.comment_ID FROM {$wpdb->comments} c
             JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = %s
             WHERE c.comment_type = 'tc_suggested_link' AND c.comment_approved = '0' AND m.meta_value = %s";
    $args = array( TC_META_SUGGESTION_TERM_ID, (string) $term_id );
    if ( '' !== $url ) {
        $sql   .= ' AND c.comment_author_url = %s';
        $args[] = $url;
    }
    return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) );
}

// ============================================================================
// SUBMISSION
// ============================================================================

add_action( 'wp_ajax_nopriv_tc_suggested_link_submit', 'tc_ajax_suggested_link_submit' );
add_action( 'wp_ajax_tc_suggested_link_submit',        'tc_ajax_suggested_link_submit' );

/**
 * Accept an anonymous or logged-in identifier suggestion for a term.
 * Always returns {success:true} to avoid enumeration.
 *
 * @since 1.0.0
 */
function tc_ajax_suggested_link_submit(): void {
    // Nonce check — works for both logged-in and anonymous users.
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ?? '' ) ), 'tc_suggested_link' ) ) {
        wp_send_json_success(); // Always return success to avoid enumeration.
    }

    // Honeypot — bots fill this field; humans leave it empty.
    if ( ! empty( $_POST['tc_honeypot'] ) ) {
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
    if ( ! in_array( $term->taxonomy, TC_Settings::get_enabled_taxonomies(), true ) ) {
        wp_send_json_success();
    }

    // Rate limit: max 3 per IP per term per day; max 10 per IP globally per hour (skipped for logged-in users).
    $rate_key = null;
    $rate     = 0;
    if ( ! is_user_logged_in() ) {
        $ip_hash  = md5( $_SERVER['REMOTE_ADDR'] ?? '' );
        $rate_key = 'tc_sugg_rate_' . $ip_hash . '_' . $term_id;
        $rate     = (int) get_transient( $rate_key );
        if ( $rate >= 3 ) {
            wp_send_json_success();
        }
        $global_key   = 'tc_suggest_global_' . $ip_hash;
        $global_count = (int) get_transient( $global_key );
        if ( $global_count >= 10 ) {
            // Respond exactly as the per-term limit does, so the response never
            // reveals which rate limit was hit (no enumeration signal).
            wp_send_json_success();
        }
        set_transient( $global_key, $global_count + 1, HOUR_IN_SECONDS );
    }

    // Deduplicate: same URL already pending for this term.
    if ( tc_pending_suggested_link_ids( $term_id, $url ) ) {
        wp_send_json_success();
    }

    // Check if URL is already an active external link for this term.
    $existing_links = tc_get_term_links( $term_id, false );
    foreach ( $existing_links as $peer_site_slug => $value ) {
        $link_type = tc_get_link_type( $peer_site_slug );
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
        'comment_meta'         => array( TC_META_SUGGESTION_TERM_ID => $term_id ),
        'comment_type'         => 'tc_suggested_link',
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
        tc_suggested_link_notify_admin( $comment_id, $term );
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
function tc_suggested_link_notify_admin( int $comment_id, WP_Term $term ): void {
    $options = get_option( 'tc_settings', array() );
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

    $match = tc_suggested_link_match_url( $comment->comment_author_url );
    if ( $match ) {
        /* translators: 1: link type label, 2: matched identifier value */
        $msg .= sprintf( __( 'Recognized as: %1$s → %2$s', 'termconnect' ), $match['label'], $match['value'] ) . "\r\n";
    }

    // Check whether the URL comes from a configured HyperTaxonomy peer site.
    $url_scheme  = (string) parse_url( $comment->comment_author_url, PHP_URL_SCHEME );
    $url_host    = (string) parse_url( $comment->comment_author_url, PHP_URL_HOST );
    $url_origin  = rtrim( $url_scheme . '://' . $url_host, '/' );
    $is_peer_site = $url_origin !== '://' && function_exists( 'tc_external_is_ht_enabled' ) && tc_external_is_ht_enabled( $url_origin );

    if ( $comment->comment_content ) {
        /* translators: %s: submitter note */
        $msg .= "\r\n" . sprintf( __( 'Note: %s', 'termconnect' ), $comment->comment_content ) . "\r\n";
    }

    $msg .= "\r\n";

    if ( $match ) {
        $accept_url = add_query_arg( array(
            'taxonomy' => $term->taxonomy,
            'tag_ID'   => $term->term_id,
            'tc_lt'    => $match['peer_site_slug'],
            'tc_value' => $match['value'],
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
        $add_type_url = add_query_arg( 'tc_suggest_url', rawurlencode( $comment->comment_author_url ), admin_url( 'admin.php?page=tc-peer-sites' ) );
        /* translators: %s: add link type URL */
        $msg .= sprintf( __( 'Add link type or peer site: %s', 'termconnect' ), $add_type_url ) . "\r\n";
        $msg .= __( '(Not a configured peer site — identity delegation requires adding it as a peer site first)', 'termconnect' ) . "\r\n";
    }

    /* translators: %s: trash URL */
    $msg .= sprintf( __( 'Trash it: %s', 'termconnect' ), admin_url( "comment.php?action=trash&c={$comment_id}#wpbody-content" ) ) . "\r\n";

    global $wpdb;
    $pending = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = 'tc_suggested_link' AND comment_approved = '0'"
    );

    $msg .= "\r\n" . sprintf(
        _n(
            'Currently %s suggestion is waiting for review.',
            'Currently %s suggestions are waiting for review.',
            $pending,
            'termconnect'
        ),
        number_format_i18n( $pending )
    ) . "\r\n";
    /* translators: %s: suggested identifiers admin URL */
    $msg .= sprintf( __( 'Review all suggestions: %s', 'termconnect' ), admin_url( 'edit-comments.php?type=tc_suggested_link' ) ) . "\r\n";

    /* translators: 1: site name, 2: term name */
    $subject = sprintf( __( '[%1$s] Please review: identifier suggestion for "%2$s"', 'termconnect' ), $site_name, $term_name );

    wp_mail( $admin_email, $subject, $msg );
}

// Suppress the default WP moderator notification for tc_suggested_link comments.
add_filter( 'notify_moderator', static function ( bool $notify, int $comment_id ): bool {
    $c = get_comment( $comment_id );
    return ( $c && 'tc_suggested_link' === $c->comment_type ) ? false : $notify;
}, 10, 2 );

// Trash pending suggestions when their target term is permanently deleted.
add_action( 'deleted_term', static function ( int $term_id ): void {
    foreach ( tc_pending_suggested_link_ids( $term_id ) as $comment_id ) {
        wp_trash_comment( $comment_id );
    }
}, 10, 1 );

// deleted_term does not fire during bulk taxonomy operations; delete_term_taxonomy does.
add_action( 'delete_term_taxonomy', static function ( int $tt_id ): void {
    $term = get_term_by( 'term_taxonomy_id', $tt_id );
    if ( ! ( $term instanceof WP_Term ) ) {
        return;
    }
    foreach ( tc_pending_suggested_link_ids( $term->term_id ) as $comment_id ) {
        wp_trash_comment( $comment_id );
    }
}, 10, 1 );

// ============================================================================
// FRONTEND FORM ON TERM ARCHIVES
// ============================================================================

add_filter( 'get_the_archive_description', 'tc_append_suggested_link_form', 20 );

// When HyperTaxonomy handles the description block, inject the suggest form inside
// .htax-archive-description via HT's append filter (runs after see-also at priority 10).
add_filter( 'htax_archive_description_append', static function ( string $append, WP_Term $term ): string {
    $options = get_option( 'tc_settings', array() );
    if ( empty( $options['allow_suggested_links'] ) ) {
        return $append;
    }
    if ( ! in_array( $term->taxonomy, TC_Settings::get_enabled_taxonomies(), true ) ) {
        return $append;
    }
    if ( null !== tc_get_identity_source( $term->term_id ) ) {
        return $append;
    }
    return tc_build_suggested_link_append_html( $append, $term );
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
function tc_append_suggested_link_form( string $description ): string {
    global $htax_handling_archive_description;
    if ( ! empty( $htax_handling_archive_description ) ) {
        return $description;
    }

    $options = get_option( 'tc_settings', array() );
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
    if ( ! in_array( $term->taxonomy, TC_Settings::get_enabled_taxonomies(), true ) ) {
        return $description;
    }

    // Skip delegated-identity terms — they already have authoritative identifiers.
    if ( null !== tc_get_identity_source( $term->term_id ) ) {
        return $description;
    }

    return tc_build_suggested_link_append_html( $description, $term );
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
function tc_build_suggested_link_append_html( string $description, WP_Term $term ): string {
    $options  = get_option( 'tc_settings', array() );
    $nonce    = wp_create_nonce( 'tc_suggested_link' );
    $term_id  = $term->term_id;
    $ajax_url = admin_url( 'admin-ajax.php' );

    $label = sanitize_text_field( $options['suggested_link_label'] ?? '' );
    if ( '' === $label ) {
        $label = __( 'Suggest a related concept', 'termconnect' );
    }

    // Ensure Dashicons are available on the frontend for the trigger icon.
    wp_enqueue_style( 'dashicons' );

    // Build the trigger button (styled as a link via CSS).
    $trigger = '<span class="dashicons dashicons-admin-links" aria-hidden="true" style="vertical-align:middle;margin-right:4px;font-size:1em;width:1em;height:1em"></span>'
        . '<button type="button" class="tc-suggest-trigger">'
        . esc_html( $label )
        . '</button>'
        . '<span class="tc-suggest-thankyou" style="display:none;color:#3a7c3a;margin-top:4px">'
        . esc_html__( 'Thank you!', 'termconnect' )
        . '</span>';

    // Build the floating pane with the mini form.
    ob_start();
    ?>
<div class="tc-suggest-pane" style="display:none;position:fixed;z-index:99999;background:#fff;border:1px solid #ddd;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,.15);padding:12px;max-width:380px;box-sizing:border-box">
    <form class="tc-suggested-link-form" style="display:flex;flex-direction:column;gap:6px">
        <input type="hidden" name="tc_honeypot" value="">
        <input type="hidden" name="term_id" value="<?php echo esc_attr( $term_id ); ?>">
        <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
        <input type="text" name="url" class="tc-suggested-link-url" autocomplete="off"
               value="https://"
               style="width:100%;height:28px;padding:3px 8px;box-sizing:border-box">
        <div style="display:flex;gap:6px;align-items:flex-start">
            <button type="submit" class="button tc-suggested-link-submit">
                <?php esc_html_e( 'Suggest', 'termconnect' ); ?>
            </button>
            <textarea name="comment" class="tc-suggested-link-comment"
                      placeholder="<?php esc_attr_e( 'Optional note', 'termconnect' ); ?>"
                      style="flex:1;font-size:0.82em;padding:3px 8px;box-sizing:border-box;resize:vertical"></textarea>
        </div>
    </form>
</div>
<script>
(function(){
    var trigger = document.querySelector('.tc-suggest-trigger');
    var pane    = document.querySelector('.tc-suggest-pane');
    var form    = pane ? pane.querySelector('.tc-suggested-link-form') : null;
    var thanks  = document.querySelector('.tc-suggest-thankyou');
    if (!trigger || !pane || !form) return;

    function openPane() {
        if (thanks) thanks.style.display = 'none';
        form.reset();
        var rect = trigger.getBoundingClientRect();
        pane.style.top  = (rect.bottom + 4) + 'px';
        pane.style.left = Math.max(4, Math.min(rect.left, window.innerWidth - 388)) + 'px';
        pane.style.display = '';
        var btn  = form.querySelector('.tc-suggested-link-submit');
        var note = form.querySelector('.tc-suggested-link-comment');
        if (btn && note) { note.style.height = (btn.offsetHeight * 2) + 'px'; }
        var urlInp = form.querySelector('.tc-suggested-link-url');
        if (urlInp) {
            urlInp.focus();
            urlInp.setSelectionRange(urlInp.value.length, urlInp.value.length);
        }
    }

    trigger.addEventListener('click', function(e) {
        e.stopPropagation();
        if (pane.style.display === 'none') { openPane(); } else { pane.style.display = 'none'; }
    });

    document.addEventListener('click', function(e) {
        if (pane.style.display === 'none') return;
        if (e.target.closest && (e.target.closest('.tc-suggest-pane') || e.target.closest('.tc-suggest-trigger'))) return;
        pane.style.display = 'none';
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var urlInp = form.querySelector('.tc-suggested-link-url');
        var urlVal = urlInp ? urlInp.value.trim() : '';
        var validUrl = false;
        if (urlVal) {
            try {
                var parsed = new URL(urlVal);
                validUrl = (parsed.protocol === 'http:' || parsed.protocol === 'https:') && parsed.hostname.indexOf('.') !== -1;
            } catch(err) {}
        }
        if (!validUrl) {
            if (urlInp) {
                urlInp.setCustomValidity(<?php echo wp_json_encode( __( 'Please enter a valid URL (e.g. https://example.com/page)', 'termconnect' ) ); ?>);
                urlInp.reportValidity();
                urlInp.addEventListener('input', function clear() {
                    urlInp.setCustomValidity('');
                    urlInp.removeEventListener('input', clear);
                });
            }
            return;
        }
        if (urlInp) { urlInp.setCustomValidity(''); }
        var data = new FormData(form);
        data.append('action', 'tc_suggested_link_submit');
        pane.style.display = 'none';
        if (thanks) thanks.style.display = 'block';
        fetch(<?php echo wp_json_encode( $ajax_url ); ?>, {method:'POST', body:data, credentials:'same-origin'});
    });
})();
</script>
    <?php
    $pane_html = ob_get_clean();

    // Find the See Also block in the description (already added at priority 10).
    // Wrap it and the trigger side-by-side in a flex row.
    $see_also_pos = strpos( $description, 'class="tc-term-links-see-also"' );
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

                $row = '<div class="tc-term-links-row" style="display:flex;align-items:flex-start;gap:1.5em;margin-top:1em">'
                    . '<div class="tc-term-links-see-also-col" style="flex:1;min-width:0">' . $see_also . '</div>'
                    . '<div class="tc-term-links-suggest-col" style="flex-shrink:0">' . $trigger . '</div>'
                    . '</div>'
                    . $pane_html;

                return $before . $row . $after;
            }
        }
    }

    // No See Also block present — append trigger standalone.
    return $description
        . '<div class="tc-term-links-row" style="display:flex;margin-top:1.5em">'
        . '<div class="tc-term-links-suggest-col">' . $trigger . '</div>'
        . '</div>'
        . $pane_html;
}
