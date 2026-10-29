<?php
/**
 * Term edit screen — External Links field.
 *
 * Renders the External Links section on taxonomy term add/edit screens at
 * priority 20: outside HyperTaxonomy's collapsible div (which closes at priority 19)
 * and before ATCF's Locations section (priority 99).
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================================
// ASSETS
// ============================================================================

/**
 * Enqueue the TermConnect term-edit stylesheet on taxonomy term add/edit screens for
 * TermConnect-enabled taxonomies. Works whether or not HyperTaxonomy is active.
 *
 * @param string $hook Current admin page hookname.
 */
function tc_enqueue_term_edit_css( string $hook ): void {
    if ( ! in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) ) {
        return;
    }
    $taxonomy = sanitize_key( wp_unslash( $_GET['taxonomy'] ?? '' ) );
    if ( $taxonomy && ! in_array( $taxonomy, TC_Settings::get_enabled_taxonomies(), true ) ) {
        return;
    }
    wp_enqueue_style(
        'tc-term-edit',
        TC_PLUGIN_URL . 'assets/css/tc-term-edit.css',
        array(),
        TC_VERSION
    );
    wp_enqueue_script(
        'tc-term-edit',
        TC_PLUGIN_URL . 'assets/js/tc-term-edit.js',
        array( 'jquery' ),
        TC_VERSION,
        true
    );
    wp_localize_script( 'tc-term-edit', 'tcDiI18n', array(
        'suggestNonce'  => wp_create_nonce( 'tc-link-suggest' ),
        'loading'       => __( 'Looking up…', 'termconnect' ),
        'delegating'    => __( 'Delegating…', 'termconnect' ),
        'refreshing'    => __( 'Refreshing…', 'termconnect' ),
        'error'         => __( 'Request failed.', 'termconnect' ),
        'notFound'      => __( 'No term with this slug found on the selected peer site.', 'termconnect' ),
        'removeConfirm' => __( 'End delegation? The original description and local links will be restored.', 'termconnect' ),
        'svConflict'    => __( 'A Shared Vocabulary for this peer site already exists with: sync name: %1$s, sync description: %2$s.', 'termconnect' ),
        'joinExistingSv' => __( 'Add to existing SV', 'termconnect' ),
        'createNewSv'   => __( 'Create new SV', 'termconnect' ),
        'yes'           => __( 'yes', 'termconnect' ),
        'no'            => __( 'no', 'termconnect' ),
    ) );
}
add_action( 'admin_enqueue_scripts', 'tc_enqueue_term_edit_css' );

// Register Delegated Identity and External Links fields on term add/edit screens.
// Priority 9 fires before HyperTaxonomy's collapsible start (also priority 9) because
// TC's init:15 runs before HT's admin_init:999 — same-priority hooks execute FIFO.
// This places both panels after Term Groups (HT priority 8) and before the collapsible.
add_action( 'init', static function () {
    foreach ( TC_Settings::get_enabled_taxonomies() as $taxonomy ) {
        add_action( "{$taxonomy}_edit_form_fields", 'tc_add_delegated_identity_field', 9 );
        add_action( "{$taxonomy}_add_form_fields",  'tc_add_external_links_field', 9 );
        add_action( "{$taxonomy}_edit_form_fields", 'tc_add_external_links_field', 9 );
    }
}, 15 );

/**
 * Output Quick Edit read-only behavior for delegated terms.
 *
 * Runs in admin_footer on the term list page (edit-tags.php). Queries the
 * delegation status of all terms visible in the current taxonomy and emits a
 * small JS snippet that locks slug/name/description in the Quick Edit form
 * when the edited term is delegated with the corresponding sync flag set.
 *
 * @since 1.0.0
 */
function tc_quick_edit_delegation_script(): void {
    $taxonomy = sanitize_key( wp_unslash( $_GET['taxonomy'] ?? 'post_tag' ) );
    if ( ! in_array( $taxonomy, TC_Settings::get_enabled_taxonomies(), true ) ) {
        return;
    }

    global $wpdb;
    // Join with wp_term_taxonomy to filter by taxonomy at the SQL level, avoiding
    // a per-row get_term() call for each delegated term across all taxonomies.
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT tm.term_id, tm.meta_value
         FROM {$wpdb->termmeta} tm
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
         WHERE tm.meta_key = %s AND tt.taxonomy = %s",
        TC_META_IDENTITY_SOURCE,
        $taxonomy
    ) );

    $data = array();
    foreach ( $rows as $row ) {
        $src = maybe_unserialize( $row->meta_value );
        if ( ! is_array( $src ) ) {
            continue;
        }
        $data[ (int) $row->term_id ] = array(
            'sync_name'        => ! isset( $src['sync_name'] ) || ! empty( $src['sync_name'] ),
            'sync_description' => ! isset( $src['sync_description'] ) || ! empty( $src['sync_description'] ),
        );
    }

    if ( empty( $data ) ) {
        return;
    }
    ?>
    <script>
    (function(){
        var tcDelegated = <?php echo wp_json_encode( $data ); ?>;
        // Use a delegated click handler + setTimeout(0) rather than patching
        // inlineEditTax.edit, because the first-click open triggers a focus() call
        // synchronously inside inlineEditTax.edit that can race with prop('readonly').
        // setTimeout(0) defers our lock until after the entire click-handler stack
        // (including focus events) settles, guaranteeing consistent first-click behavior.
        jQuery( document ).on( 'click', '.editinline', function () {
            if ( typeof inlineEditTax === 'undefined' ) { return; }
            var termId = parseInt( inlineEditTax.getId( this ), 10 );
            var info   = tcDelegated[ termId ];
            if ( ! info ) { return; }
            setTimeout( function () {
                // Target the live clone (#edit-{id}), not the hidden template (#inline-edit).
                // WordPress clones the template and renames it before showing it, so locking
                // the template only takes effect on the *next* clone (explaining the one-click lag).
                var $form  = jQuery( '#edit-' + termId );
                var locked = { background: '#f0f0f1', cursor: 'not-allowed' };
                $form.find( '[name="slug"]' ).prop( 'readonly', true ).css( locked );
                if ( info.sync_name ) {
                    $form.find( '[name="name"]' ).prop( 'readonly', true ).css( locked );
                }
                if ( info.sync_description ) {
                    $form.find( '[name="description"]' ).prop( 'readonly', true ).css( locked );
                }
            }, 0 );
        } );
    })();
    </script>
    <?php
}
add_action( 'admin_footer-edit-tags.php', 'tc_quick_edit_delegation_script' );

// ============================================================================

/**
 * Render the "External Links" section on HT-enabled term edit screens.
 *
 * Shows existing links as editable rows with a clear (×) button.
 * A dropdown-based "Add external link" row lets the user add types one at a time;
 * the ID field is required when a type is chosen, preventing blank saves.
 * The "Add" button is hidden when all enabled types already have values.
 *
 * @since 1.0.0
 * @param WP_Term|string $term Term object (edit form) or taxonomy slug (add form).
 */
function tc_add_external_links_field( $term ): void {
    $taxonomy = is_object( $term ) ? $term->taxonomy : $term;
    if ( 'group' === $taxonomy ) {
        return;
    }
    $enabled_types = tc_get_enabled_link_types_for_taxonomy( $taxonomy );
    // Alphabetical by label, but link types flagged 'sort_last' (e.g. the "Local
    // site" pseudo-peer) are kept at the bottom of the list.
    uasort(
        $enabled_types,
        static function ( $a, $b ) {
            $a_last = ! empty( $a['sort_last'] );
            $b_last = ! empty( $b['sort_last'] );
            if ( $a_last !== $b_last ) {
                return $a_last ? 1 : -1;
            }
            return strcmp( $a['label'] ?? '', $b['label'] ?? '' );
        }
    );
    if ( empty( $enabled_types ) ) {
        // TermConnect is enabled for this taxonomy but no link types are active yet.
        // Show a prompt to admins so they know what to do; skip for non-admins.
        if ( current_user_can( 'manage_options' ) && is_object( $term ) ) {
            $url = admin_url( 'admin.php?page=tc-peer-sites' );
            ?>
            <tr class="form-field">
                <th scope="row"><?php esc_html_e( 'External Links', 'termconnect' ); ?></th>
                <td>
                    <p class="description">
                        <?php esc_html_e( 'External Links connect this term to entries in external services (Wikidata, Wikipedia, etc.) and peer WordPress sites. These identifiers enable cross-site post discovery, synonym matching, and "See also" links on term archives.', 'termconnect' ); ?>
                    </p>
                    <p class="description" style="margin-top:4px;">
                        <?php
                        printf(
                            /* translators: %s: link to Peer Sites admin page */
                            esc_html__( 'No link types are enabled yet. %s to enable Wikidata, Wikipedia, or other external identifier types.', 'termconnect' ),
                            '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Configure Peer Sites', 'termconnect' ) . '</a>'
                        );
                        ?>
                    </p>
                </td>
            </tr>
            <?php
        }
        return;
    }

    $term_id       = is_object( $term ) ? $term->term_id : 0;
    $is_edit       = $term_id > 0;
    $existing_data = $is_edit ? tc_get_term_links_data( $term_id ) : array();
    $delegated     = $is_edit && null !== tc_get_identity_source( $term_id );

    // Collect delegated links for the read-only section and suppression check.
    $delegated_links_data = array();
    $suppressed_slugs     = array();
    if ( $delegated && $is_edit ) {
        $raw_delegated = get_term_meta( $term_id, TC_META_DELEGATED_LINKS_DATA, true );
        if ( is_array( $raw_delegated ) ) {
            $delegated_links_data = $raw_delegated;
        }
        // Determine which local links are suppressed: delegated entry on same site but different URL.
        foreach ( $existing_data as $slug => $meta ) {
            $type       = tc_get_link_type( $slug );
            $prefix     = $type ? ( $type['uri_prefix'] ?? '' ) : '';
            $local_url  = tc_get_link_uri( $slug, $meta['id'], $taxonomy );
            if ( null === $local_url || '' === $prefix ) {
                continue;
            }
            foreach ( $delegated_links_data as $dentry ) {
                if ( empty( $dentry['url'] ) ) {
                    continue;
                }
                if ( 0 === strpos( $dentry['url'], $prefix ) && $dentry['url'] !== $local_url ) {
                    $suppressed_slugs[ $slug ] = true;
                    break;
                }
            }
        }
    }

    // Split into existing (have a value) and unused (no value yet).
    $set_types    = array_filter( $enabled_types, fn( $slug ) => ! empty( $existing_data[ $slug ] ), ARRAY_FILTER_USE_KEY );
    $unused_types = array_filter( $enabled_types, fn( $slug ) => empty( $existing_data[ $slug ] ), ARRAY_FILTER_USE_KEY );

    // Skip rendering if the add form has no enabled types.
    if ( ! $is_edit && empty( $enabled_types ) ) {
        return;
    }

    if ( $is_edit ) {
        ?>
        <tr class="form-field tc-external-links-field">
            <th scope="row" valign="top">
                <label><?php esc_html_e( 'External Links', 'termconnect' ); ?></label>
            </th>
            <td>
        <?php
    } else {
        ?>
        <div class="form-field tc-external-links-field">
            <label><?php esc_html_e( 'External Links', 'termconnect' ); ?></label>
        <?php
    }
    ?>
                <input type="hidden" name="tc_links_present" value="1">
                <?php
                $tc_links_dd_id = 'tc-links-dd';
                ?>
                <div class="tc-links-container">
                <?php
                // Build combined pill array (local + delegated-only), sorted alphabetically.
                $local_pill_urls = array();
                foreach ( $set_types as $slug => $type ) {
                    $meta = $existing_data[ $slug ] ?? array();
                    $u    = tc_get_link_uri( $slug, $meta['id'] ?? '', $taxonomy );
                    if ( $u ) {
                        $local_pill_urls[ $u ] = true;
                    }
                }
                // URL set for exact-match overlap detection (local URL == delegated URL).
                $delegated_url_set = array();
                foreach ( $delegated_links_data as $dentry ) {
                    if ( ! empty( $dentry['url'] ) ) {
                        $delegated_url_set[ $dentry['url'] ] = true;
                    }
                }
                $all_pills = array();
                foreach ( $set_types as $slug => $type ) {
                    $meta            = $existing_data[ $slug ] ?? array();
                    $current_id      = $meta['id'] ?? '';
                    $current_label   = $meta['label'] ?? '';
                    $current_fetched = (int) ( $meta['fetched_at'] ?? 0 );
                    $is_feed         = ( $type['platform'] ?? '' ) === 'feed';
                    $display_id      = $is_feed ? tc_feed_display_identifier( $current_id ) : $current_id;
                    $preview_url     = tc_get_link_uri( $slug, $current_id, $taxonomy )
                        ?? ( $is_feed ? ( tc_external_get_site_origin( $type['uri_prefix'] ?? '' ) ?: null ) : null );
                    $pill_prefix     = tc_link_type_display_prefix( $type, $taxonomy );
                    $is_suppressed   = isset( $suppressed_slugs[ $slug ] );
                    $all_pills[]     = array(
                        'kind'                => 'local',
                        'sort_key'            => strtolower( $type['label'] ),
                        'slug'                => $slug,
                        'type'                => $type,
                        'current_id'          => $current_id,
                        'current_label'       => $current_label,
                        'current_fetched'     => $current_fetched,
                        'display_id'          => $display_id,
                        'preview_url'         => $preview_url,
                        'pill_prefix'         => $pill_prefix,
                        'is_suppressed'       => $is_suppressed,
                        'has_delegated_match' => $preview_url && isset( $delegated_url_set[ $preview_url ] ),
                    );
                }
                foreach ( $delegated_links_data as $dentry ) {
                    $durl   = (string) ( $dentry['url'] ?? '' );
                    $dlabel = (string) ( $dentry['label'] ?? '' );
                    if ( '' === $durl || isset( $local_pill_urls[ $durl ] ) ) {
                        continue;
                    }
                    $display_label = '' !== $dlabel ? $dlabel : $durl;
                    $all_pills[]   = array(
                        'kind'          => 'delegated',
                        'sort_key'      => strtolower( $display_label ),
                        'durl'          => $durl,
                        'display_label' => $display_label,
                    );
                }
                usort( $all_pills, fn( $a, $b ) => strcmp( $a['sort_key'], $b['sort_key'] ) );
                foreach ( $all_pills as $pill_data ) :
                    if ( 'local' === $pill_data['kind'] ) :
                        $slug                = $pill_data['slug'];
                        $type                = $pill_data['type'];
                        $current_id          = $pill_data['current_id'];
                        $current_label       = $pill_data['current_label'];
                        $current_fetched     = $pill_data['current_fetched'];
                        $display_id          = $pill_data['display_id'];
                        $preview_url         = $pill_data['preview_url'];
                        $pill_prefix         = $pill_data['pill_prefix'];
                        $is_suppressed       = $pill_data['is_suppressed'];
                        $has_delegated_match = $pill_data['has_delegated_match'];
                        ?>
                <span class="tc-link-pill tc-link-pill-local<?php echo $is_suppressed ? ' tc-link-pill-suppressed' : ''; ?>"
                      data-slug="<?php echo esc_attr( $slug ); ?>"
                      data-label="<?php echo esc_attr( $type['label'] ); ?>"
                      data-platform="<?php echo esc_attr( $type['platform'] ?? 'other' ); ?>"
                      data-prefix="<?php echo esc_attr( $pill_prefix ); ?>"
                      <?php echo $has_delegated_match ? 'data-has-delegated="1"' : ''; ?>
                      <?php echo $is_suppressed ? 'title="' . esc_attr( __( 'Overridden by delegated link', 'termconnect' ) ) . '"' : ''; ?>>
                    <?php if ( $preview_url ) : ?>
                    <a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener noreferrer"
                       style="color:inherit;text-decoration:none" title="<?php echo esc_attr( $preview_url ); ?>">
                        <?php echo esc_html( $type['label'] ); ?>: <?php echo esc_html( $display_id ); ?>&nbsp;&#x2197;
                    </a>
                    <?php else : ?>
                    <?php echo esc_html( $type['label'] ); ?>: <?php echo esc_html( $display_id ); ?>&nbsp;&#x2197;
                    <?php endif; ?>
                    <button type="button" class="tc-link-pill-remove"
                            aria-label="<?php echo esc_attr( sprintf( __( 'Remove %s link', 'termconnect' ), $type['label'] ) ); ?>"
                            tabindex="-1">&times;</button>
                    <input type="hidden" name="tc_links[<?php echo esc_attr( $slug ); ?>]"
                           value="<?php echo esc_attr( $current_id ); ?>">
                    <input type="hidden" name="tc_link_labels[<?php echo esc_attr( $slug ); ?>]"
                           value="<?php echo esc_attr( $current_label ); ?>">
                    <input type="hidden" name="tc_link_fetched[<?php echo esc_attr( $slug ); ?>]"
                           value="<?php echo esc_attr( (string) $current_fetched ); ?>">
                </span>
                    <?php else :
                        $durl          = $pill_data['durl'];
                        $display_label = $pill_data['display_label'];
                        ?>
                <span class="tc-link-pill tc-link-pill-delegated"
                      title="<?php echo esc_attr( __( 'Delegated (read-only)', 'termconnect' ) . ' — ' . $durl ); ?>">
                    <a href="<?php echo esc_url( $durl ); ?>" target="_blank" rel="noopener noreferrer"
                       style="color:inherit;text-decoration:none">
                        <?php echo esc_html( $display_label ); ?>&nbsp;&#x2197;
                    </a>
                </span>
                    <?php endif;
                endforeach; ?>
                <button type="button"
                        class="tc-links-add-btn"
                        data-dropdown="<?php echo esc_attr( $tc_links_dd_id ); ?>"
                        title="<?php esc_attr_e( 'Add external link', 'termconnect' ); ?>"
                        <?php echo empty( $unused_types ) ? 'style="display:none"' : ''; ?>>
                    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                    <span class="screen-reader-text"><?php esc_html_e( 'Add external link', 'termconnect' ); ?></span>
                </button>
                </div><!-- /.tc-links-container -->
                <p class="description" style="margin:4px 0 0;"><?php
                $msg = __( 'Link this term to external services (Wikidata, Wikipedia, etc.) and peer WordPress sites. These identifiers power cross-site post discovery, synonym matching, and "See also" links on term archives.', 'termconnect' );
                echo esc_html( $msg );
                ?></p>
                <!-- Single-select dropdown: click a type, then enter its ID -->
                <div id="<?php echo esc_attr( $tc_links_dd_id ); ?>" class="tc-links-dropdown" style="display:none;min-width:280px">
                    <div class="tc-links-dd-header" style="display:none;padding:3px 6px 3px;border-bottom:1px solid #f0f0f1">
                        <button type="button" class="button-link tc-links-dd-back-btn" style="padding:3px 6px;font-size:12px">
                            &larr; <?php esc_html_e( 'Back', 'termconnect' ); ?>
                        </button>
                    </div>
                    <?php foreach ( $enabled_types as $slug => $type ) :
                        $is_set        = isset( $set_types[ $slug ] );
                        $platform      = $type['platform'] ?? 'other';
                        $suggest_action = '';
                        $suggest_free   = false;
                        if ( 'wordpress' === $platform ) {
                            $suggest_action = tc_taxonomy_peer_authors( $taxonomy )
                                ? 'tc_wp_peer_author_suggest'
                                : 'tc_wp_peer_suggest';
                        } elseif ( 'other' === $platform ) {
                            $suggest_cfg = tc_get_suggest_config( $slug, $taxonomy );
                            if ( null !== $suggest_cfg ) {
                                $suggest_action = 'tc_peer_site_identifier_suggest';
                                $suggest_free   = ! empty( $suggest_cfg['free_text'] );
                            }
                        }
                        ?>
                    <div class="tc-links-dd-row"
                         data-slug="<?php echo esc_attr( $slug ); ?>"
                         data-label="<?php echo esc_attr( $type['label'] ); ?>"
                         data-platform="<?php echo esc_attr( $platform ); ?>"
                         data-prefix="<?php echo esc_attr( tc_link_type_display_prefix( $type, $taxonomy ) ); ?>"
                         <?php if ( 'feed' === $platform ) : ?>data-site-url="<?php echo esc_attr( tc_external_get_site_origin( $type['uri_prefix'] ?? '' ) ); ?>"<?php endif; ?>
                         <?php echo $is_set ? 'style="display:none"' : ''; ?>>
                        <div class="tc-links-dd-typerow-label" style="display:flex;align-items:center;padding:5px 10px;cursor:pointer">
                            <span style="flex:1;white-space:nowrap"><?php echo esc_html( $type['label'] ); ?></span>
                            <span style="color:#8c8f94;font-size:14px">&#x203A;</span>
                        </div>
                        <div class="tc-links-dd-input-area" style="display:none;padding:4px 10px 8px">
                            <?php if ( 'feed' === $platform ) : ?>
                            <button type="button" class="tc-feed-pick-btn button button-small"
                                    data-peer-site-slug="<?php echo esc_attr( $slug ); ?>">
                                <?php esc_html_e( 'Pick category…', 'termconnect' ); ?>
                            </button>
                            <span class="tc-feed-selected-label" style="display:none;font-size:12px;color:#555;margin-left:6px"></span>
                            <input type="hidden" class="tc-links-dd-input" name="" disabled>
                            <div class="tc-feed-picker-panel" style="display:none;margin-top:6px">
                                <input type="search" class="tc-feed-filter" placeholder="<?php esc_attr_e( 'Filter categories…', 'termconnect' ); ?>"
                                       style="width:100%;margin-bottom:4px">
                                <div class="tc-feed-category-list" style="max-height:180px;overflow-y:auto;border:1px solid #ddd;background:#fff;padding:4px 0"></div>
                                <button type="button" class="tc-feed-refresh button-link" data-peer-site-slug="<?php echo esc_attr( $slug ); ?>"
                                        style="margin-top:5px;font-size:11px">
                                    &#x21bb; <?php esc_html_e( 'Refresh from feed', 'termconnect' ); ?>
                                </button>
                            </div>
                            <?php else : ?>
                            <input type="text"
                                   class="tc-links-dd-input"
                                   name=""
                                   placeholder="<?php esc_attr_e( 'ID', 'termconnect' ); ?>"
                                   data-suggest-action="<?php echo esc_attr( $suggest_action ); ?>"
                                   data-suggest-free="<?php echo $suggest_free ? '1' : '0'; ?>"
                                   data-peer-site-slug="<?php echo esc_attr( $slug ); ?>"
                                   style="width:100%;box-sizing:border-box"
                                   data-ac-selected="0"
                                   disabled>
                            <div class="tc-links-dd-preview" style="font-size:11px;color:#666;margin-top:2px;display:none"></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <script>
                (function(){
                    var dd           = document.getElementById('<?php echo esc_js( $tc_links_dd_id ); ?>');
                    var addBtn       = dd ? document.querySelector('[data-dropdown="<?php echo esc_js( $tc_links_dd_id ); ?>"]') : null;
                    var ajaxUrl = typeof ajaxurl !== 'undefined' ? ajaxurl : '';
                    // TermConnect-specific nonce for suggest AJAX (always available regardless of HyperTaxonomy).
                    var tcSuggestNonce = <?php echo wp_json_encode(wp_create_nonce('tc-link-suggest')); ?>;
                    <?php $tc_tax = is_object($term) ? $term->taxonomy : (is_string($term) ? $term : ''); ?>
                    var tcTaxonomy = <?php echo wp_json_encode($tc_tax); ?>;
                    function getSuggestNonce() { return tcSuggestNonce; }
                    function getLocalTax()     {
                        return tcTaxonomy || ( typeof HTAX_Ajax !== 'undefined' ? ( HTAX_Ajax.taxonomy || '' ) : '' );
                    }

                    function hasVisibleRows() {
                        if (!dd) return false;
                        var rows = dd.querySelectorAll('.tc-links-dd-row');
                        for (var i = 0; i < rows.length; i++) {
                            if (rows[i].style.display !== 'none') return true;
                        }
                        return false;
                    }
                    function syncAddBtn() {
                        if (!addBtn) return;
                        addBtn.style.display = hasVisibleRows() ? '' : 'none';
                    }

                    // Open/close the dropdown when the + button is clicked.
                    // Uses a data attribute as open/closed state to avoid relying on
                    // computed vs inline display (HyperTaxonomy's CSS sets display:none via stylesheet,
                    // so dd.style.display starts empty even when visually hidden).
                    if (addBtn && dd) {
                        addBtn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            if (dd.dataset.tcOpen === '1') {
                                dd.style.display  = 'none';
                                dd.dataset.tcOpen = '';
                                return;
                            }
                            var rect = addBtn.getBoundingClientRect();
                            dd.style.position = 'fixed';
                            dd.style.zIndex   = '99999';
                            dd.style.top      = (rect.bottom + 4) + 'px';
                            dd.style.left     = Math.max(4, rect.left) + 'px';
                            dd.style.display  = 'block';
                            dd.dataset.tcOpen = '1';
                        });

                        // Close on outside click or Escape.
                        document.addEventListener('click', function(e) {
                            if (dd.dataset.tcOpen !== '1') return;
                            var sel = '.tc-links-add-btn';
                            if (e.target.closest && (e.target.closest('[data-dropdown="' + dd.id + '"]') || e.target.closest('#' + dd.id))) return;
                            dd.style.display  = 'none';
                            dd.dataset.tcOpen = '';
                        });
                        document.addEventListener('keydown', function(e) {
                            if ((e.key === 'Escape' || e.keyCode === 27) && dd.dataset.tcOpen === '1') {
                                dd.style.display  = 'none';
                                dd.dataset.tcOpen = '';
                                addBtn.focus();
                            }
                        });
                    }
                    function escAttr(s) { return s.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
                    function escHtml(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

                    function resetRow(row) {
                        var inputArea   = row.querySelector('.tc-links-dd-input-area');
                        var inp         = row.querySelector('.tc-links-dd-input');
                        var pre         = row.querySelector('.tc-links-dd-preview');
                        var selLabel    = row.querySelector('.tc-feed-selected-label');
                        var pickerPanel = row.querySelector('.tc-feed-picker-panel');
                        if (inputArea)   { inputArea.style.display = 'none'; }
                        if (inp)         { inp.name = ''; inp.required = false; inp.disabled = true; inp.value = ''; inp.dataset.acSelected = '0'; inp.dataset.acLabel = ''; }
                        if (pre)         { pre.textContent = ''; pre.style.display = 'none'; }
                        if (selLabel)    { selLabel.textContent = ''; selLabel.style.display = 'none'; }
                        if (pickerPanel) { pickerPanel.style.display = 'none'; }
                        row.style.display = 'none';
                    }

                    function showTypeList() {
                        var header    = dd ? dd.querySelector('.tc-links-dd-header') : null;
                        var container = dd ? dd.closest('td, .form-field').querySelector('.tc-links-container') : null;
                        if (header) header.style.display = 'none';
                        if (!dd) return;
                        dd.querySelectorAll('.tc-links-dd-row').forEach(function(r) {
                            // Delegated-only pills don't fill the slot — user can still add a local value.
                            var hasPill = container && container.querySelector('.tc-link-pill:not(.tc-link-pill-delegated)[data-slug="' + r.dataset.slug + '"]');
                            r.style.display = hasPill ? 'none' : '';
                            var ia = r.querySelector('.tc-links-dd-input-area');
                            if (ia) ia.style.display = 'none';
                        });
                    }

                    // Creates a pill in the container and hides the dropdown row.
                    // displayLabel: human-readable label for feed categories (overrides stored id for display).
                    function commitRow(row, displayLabel) {
                        var slug     = row.dataset.slug;
                        var label    = row.dataset.label;
                        var prefix   = row.dataset.prefix || '';
                        var platform = row.dataset.platform || 'other';
                        var inp      = row.querySelector('.tc-links-dd-input');
                        var id       = inp ? inp.value.trim() : '';
                        if (!id) return;
                        // Block free-form text for link types that require autocomplete selection
                        // (those whose suggestions are advisory, data-suggest-free="1", keep typed input).
                        if (inp && inp.dataset.suggestAction && inp.dataset.suggestFree !== '1' && inp.dataset.acSelected !== '1') { return; }

                        var shown     = displayLabel || id;
                        var container = dd ? dd.closest('td, .form-field').querySelector('.tc-links-container') : null;
                        var btn       = addBtn;
                        var url       = (platform !== 'feed' && prefix) ? prefix + id
                                      : (platform === 'feed' && row.dataset.siteUrl) ? row.dataset.siteUrl
                                      : '';

                        var pill = document.createElement('span');
                        pill.className        = 'tc-link-pill tc-link-pill-local';
                        pill.dataset.slug     = slug;
                        pill.dataset.label    = label;
                        pill.dataset.platform = platform;
                        pill.dataset.prefix   = prefix;

                        if (url) {
                            var a = document.createElement('a');
                            a.href = url; a.target = '_blank'; a.rel = 'noopener noreferrer';
                            a.style.cssText = 'color:inherit;text-decoration:none';
                            a.title = url;
                            a.textContent = label + ': ' + shown + ' ↗';
                            pill.appendChild(a);
                        } else {
                            pill.appendChild(document.createTextNode(label + ': ' + shown + ' ↗'));
                        }
                        var rm = document.createElement('button');
                        rm.type = 'button'; rm.className = 'tc-link-pill-remove';
                        rm.tabIndex = -1; rm.innerHTML = '&times;';
                        rm.setAttribute('aria-label', 'Remove ' + label + ' link');
                        pill.appendChild(rm);

                        var hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'tc_links[' + slug + ']';
                        hidden.value = id;
                        pill.appendChild(hidden);

                        var acLabel = inp ? (inp.dataset.acLabel || '') : '';
                        var hiddenLabel = document.createElement('input');
                        hiddenLabel.type = 'hidden';
                        hiddenLabel.name = 'tc_link_labels[' + slug + ']';
                        hiddenLabel.value = acLabel ? label + ': ' + acLabel : '';
                        pill.appendChild(hiddenLabel);

                        var hiddenFetched = document.createElement('input');
                        hiddenFetched.type = 'hidden';
                        hiddenFetched.name = 'tc_link_fetched[' + slug + ']';
                        hiddenFetched.value = '0';
                        pill.appendChild(hiddenFetched);

                        // Remove any existing delegated-only pill for this slug before inserting the new local pill.
                        if (container) {
                            var prevDelegated = container.querySelector('.tc-link-pill-delegated[data-slug="' + escAttr(slug) + '"]');
                            if (prevDelegated) prevDelegated.remove();
                        }

                        if (btn) { container.insertBefore(pill, btn); }
                        else     { container.appendChild(pill); }

                        resetRow(row);
                        showTypeList();
                        syncAddBtn();
                        // Close dropdown after adding one link.
                        dd.style.display  = 'none';
                        dd.dataset.tcOpen = '';
                    }

                    // ---- Feed category picker ----
                    function loadFeedCategories(peerSiteSlug, listEl, force) {
                        listEl.innerHTML = '<em style="padding:4px 8px;display:block;color:#888"><?php echo esc_js( __( 'Loading…', 'termconnect' ) ); ?></em>';
                        var params = 'action=tc_feed_categories&nonce=' + encodeURIComponent(getSuggestNonce())
                            + '&peer_site_slug=' + encodeURIComponent(peerSiteSlug) + (force ? '&force=1' : '');
                        fetch(ajaxUrl,{method:'POST',body:params,headers:{'Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin'})
                            .then(function(r){return r.json();})
                            .then(function(json){
                                if (!json.success || !json.data.length) {
                                    listEl.innerHTML = '<em style="padding:4px 8px;display:block;color:#888"><?php echo esc_js( __( 'No categories found. Check the feed URL and try refreshing.', 'termconnect' ) ); ?></em>';
                                    return;
                                }
                                listEl.dataset.allCats = JSON.stringify(json.data);
                                renderFeedCatList(listEl, json.data, '');
                            })
                            .catch(function(){
                                listEl.innerHTML = '<em style="padding:4px 8px;display:block;color:#888"><?php echo esc_js( __( 'Failed to load categories.', 'termconnect' ) ); ?></em>';
                            });
                    }
                    function renderFeedCatList(listEl, cats, filter) {
                        var lf = filter.toLowerCase(), html = '';
                        cats.forEach(function(cat){
                            if (lf && cat.label.toLowerCase().indexOf(lf) === -1 && cat.slug.toLowerCase().indexOf(lf) === -1) { return; }
                            var rid = 'tc_fcat_' + Math.random().toString(36).slice(2);
                            html += '<label style="display:flex;align-items:center;gap:6px;padding:3px 8px;cursor:pointer">'
                                + '<input type="radio" name="tc_feed_cat_pick" value="' + escAttr(cat.slug) + '" data-label="' + escAttr(cat.label) + '" id="' + rid + '">'
                                + '<span>' + escHtml(cat.label) + '</span></label>';
                        });
                        listEl.innerHTML = html || '<em style="padding:4px 8px;display:block;color:#888"><?php echo esc_js( __( 'No matches.', 'termconnect' ) ); ?></em>';
                    }

                    if (dd) {
                        // Click on a type row label: show its input, hide all other rows.
                        dd.addEventListener('click', function(e) {
                            // Back button: restore type list.
                            if (e.target.closest('.tc-links-dd-back-btn')) {
                                showTypeList();
                                syncAddBtn();
                                return;
                            }
                            var typeLabel = e.target.closest('.tc-links-dd-typerow-label');
                            if (!typeLabel) return;
                            var row      = typeLabel.closest('.tc-links-dd-row');
                            if (!row) return;
                            var platform  = row.dataset.platform || 'other';
                            var slug      = row.dataset.slug;
                            var inp       = row.querySelector('.tc-links-dd-input');
                            var inputArea = row.querySelector('.tc-links-dd-input-area');
                            var prev      = inputArea ? inputArea.querySelector('.tc-links-dd-preview') : null;

                            // Hide all other rows and show back header.
                            dd.querySelectorAll('.tc-links-dd-row').forEach(function(r) {
                                if (r !== row) r.style.display = 'none';
                            });
                            var header = dd.querySelector('.tc-links-dd-header');
                            if (header) header.style.display = '';

                            if (inputArea) inputArea.style.display = '';

                            if (platform !== 'feed') {
                                if (inp) {
                                    inp.name = 'tc_links[' + slug + ']';
                                    inp.required = true; inp.disabled = false; inp.focus();
                                    var action = inp.dataset.suggestAction;
                                    if (action && !inp.dataset.acWired && typeof jQuery !== 'undefined') {
                                        inp.dataset.acWired = '1';
                                        (function(theInp, theRow, theSlug, thePrev){
                                            jQuery(theInp).autocomplete({
                                                source: function(req, resp) {
                                                    var p = 'action=' + encodeURIComponent(action)
                                                        + '&nonce=' + encodeURIComponent(getSuggestNonce())
                                                        + '&peer_site_slug=' + encodeURIComponent(theInp.dataset.peerSiteSlug || theSlug)
                                                        + '&taxonomy=' + encodeURIComponent(getLocalTax())
                                                        + '&q=' + encodeURIComponent(req.term);
                                                    fetch(ajaxUrl,{method:'POST',body:p,headers:{'Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin'})
                                                        .then(function(r){return r.json();})
                                                        .then(function(j){resp(j.success ? j.data : []);})
                                                        .catch(function(){resp([]);});
                                                },
                                                minLength: 2,
                                                select: function(ev, ui) {
                                                    theInp.value = ui.item.value;
                                                    theInp.dataset.acSelected = '1';
                                                    theInp.dataset.acLabel = ui.item.label || '';
                                                    if (thePrev) {
                                                        var pfx = theRow.dataset.prefix || '';
                                                        thePrev.textContent   = (pfx && ui.item.value) ? pfx + ui.item.value : '';
                                                        thePrev.style.display = thePrev.textContent ? '' : 'none';
                                                    }
                                                }
                                            });
                                        })(inp, row, slug, prev);
                                    }
                                }
                            }
                        });

                        // Radio selected in feed picker: encode identifier and commit as pill.
                        dd.addEventListener('change', function(e) {
                            if (!e.target.name || e.target.name !== 'tc_feed_cat_pick') return;
                            var row         = e.target.closest('.tc-links-dd-row');
                            var inp         = row.querySelector('.tc-links-dd-input');
                            var selLabel    = row.querySelector('.tc-feed-selected-label');
                            var pickerPanel = row.querySelector('.tc-feed-picker-panel');
                            var pickedSlug  = e.target.value;
                            var pickedLabel = e.target.dataset.label || pickedSlug;
                            var stored = (pickedSlug === pickedLabel) ? pickedSlug : JSON.stringify({s: pickedSlug, l: pickedLabel});
                            if (inp) { inp.value = stored; }
                            if (selLabel)    { selLabel.textContent = pickedLabel; selLabel.style.display = ''; }
                            if (pickerPanel) { pickerPanel.style.display = 'none'; }
                            commitRow(row, pickedLabel);
                        });

                        // Text input: update URI preview and clear autocomplete-selected marker.
                        dd.addEventListener('input', function(e) {
                            if (!e.target.classList.contains('tc-links-dd-input')) return;
                            e.target.dataset.acSelected = '';
                            e.target.dataset.acLabel = '';
                            var row  = e.target.closest('.tc-links-dd-row');
                            var prev = row.querySelector('.tc-links-dd-preview');
                            if (!prev) return;
                            var prefix = row.dataset.prefix || '';
                            prev.textContent   = (e.target.value && prefix) ? prefix + e.target.value : '';
                            prev.style.display = prev.textContent ? '' : 'none';
                        });

                        // Feed filter: re-render category list.
                        dd.addEventListener('input', function(e) {
                            if (!e.target.classList.contains('tc-feed-filter')) return;
                            var panel  = e.target.closest('.tc-feed-picker-panel');
                            var listEl = panel ? panel.querySelector('.tc-feed-category-list') : null;
                            if (!listEl || !listEl.dataset.allCats) return;
                            renderFeedCatList(listEl, JSON.parse(listEl.dataset.allCats), e.target.value);
                        });

                        // Enter key in text input: commit immediately.
                        dd.addEventListener('keydown', function(e) {
                            if (e.key !== 'Enter') return;
                            if (!e.target.classList.contains('tc-links-dd-input')) return;
                            var row = e.target.closest('.tc-links-dd-row');
                            if (!row || row.dataset.platform === 'feed') return;
                            e.preventDefault();
                            if (e.target.value.trim()) { commitRow(row); }
                        });

                        // "Pick category" button toggles the picker panel.
                        dd.addEventListener('click', function(e) {
                            var pickBtn = e.target.closest('.tc-feed-pick-btn');
                            if (!pickBtn) return;
                            var row         = pickBtn.closest('.tc-links-dd-row');
                            var pickerPanel = row.querySelector('.tc-feed-picker-panel');
                            var listEl      = row.querySelector('.tc-feed-category-list');
                            var peerSiteSlug      = pickBtn.dataset.peerSiteSlug;
                            if (pickerPanel.style.display === 'none') {
                                pickerPanel.style.display = '';
                                if (listEl && !listEl.dataset.loaded) { listEl.dataset.loaded = '1'; loadFeedCategories(peerSiteSlug, listEl, false); }
                            } else {
                                pickerPanel.style.display = 'none';
                            }
                        });

                        // "Refresh from feed" button.
                        dd.addEventListener('click', function(e) {
                            var refreshBtn = e.target.closest('.tc-feed-refresh');
                            if (!refreshBtn) return;
                            var row    = refreshBtn.closest('.tc-links-dd-row');
                            var listEl = row.querySelector('.tc-feed-category-list');
                            var peerSiteSlug = refreshBtn.dataset.peerSiteSlug;
                            if (listEl) { delete listEl.dataset.loaded; loadFeedCategories(peerSiteSlug, listEl, true); }
                        });

                        // When dropdown closes: commit an active free-text row; discard suggest rows.
                        new MutationObserver(function(muts) {
                            muts.forEach(function(m) {
                                var closing = m.attributeName === 'style' && dd.dataset.tcOpen !== '1';
                                if (!closing) return;
                                dd.querySelectorAll('.tc-links-dd-row').forEach(function(row) {
                                    if (row.dataset.platform === 'feed') return;
                                    var inputArea = row.querySelector('.tc-links-dd-input-area');
                                    var inp       = row.querySelector('.tc-links-dd-input');
                                    if (!inputArea || inputArea.style.display === 'none') return;
                                    if (!inp || !inp.value.trim()) { resetRow(row); showTypeList(); syncAddBtn(); return; }
                                    if (inp.dataset.suggestAction && inp.dataset.suggestFree !== '1' && inp.dataset.acSelected !== '1') {
                                        resetRow(row); showTypeList(); syncAddBtn(); return;
                                    }
                                    commitRow(row);
                                });
                            });
                        }).observe(dd, {attributes: true, attributeFilter: ['style', 'data-tc-open']});
                    }

                    // x on existing pill: clear hidden inputs, hide pill (or transform to delegated if a delegated match exists).
                    document.addEventListener('click', function(e) {
                        var btn = e.target.closest('.tc-link-pill-remove');
                        if (!btn) return;
                        var pill = btn.closest('.tc-link-pill');
                        if (!pill) return;
                        var slug = pill.dataset.slug;

                        // If the local value has a matching delegated link, keep the pill visible but switch it to delegated style.
                        if (pill.dataset.hasDelegated) {
                            pill.querySelectorAll('input[type=hidden]').forEach(function(inp) { inp.value = ''; });
                            pill.classList.remove('tc-link-pill-local', 'tc-link-pill-suppressed');
                            pill.classList.add('tc-link-pill-delegated');
                            delete pill.dataset.hasDelegated;
                            btn.remove();
                            showTypeList();
                            syncAddBtn();
                            return;
                        }

                        pill.querySelectorAll('input[type=hidden]').forEach(function(inp) { inp.value = ''; });
                        pill.style.display = 'none';
                        if (dd) {
                            var row = dd.querySelector('.tc-links-dd-row[data-slug="' + slug + '"]');
                            if (row) { resetRow(row); row.style.display = ''; }
                        }
                        syncAddBtn();
                    });

                    // Pre-fill from tc_lt / tc_value URL params (Accept link from suggestions queue).
                    (function(){
                        var params  = new URLSearchParams(window.location.search);
                        var peerSiteSlug  = params.get('tc_lt');
                        var tcValue = params.get('tc_value');
                        if (!peerSiteSlug || !tcValue || !dd) return;
                        var row = dd.querySelector('.tc-links-dd-row[data-slug="' + peerSiteSlug + '"]');
                        if (!row) return;
                        var inp = row.querySelector('.tc-links-dd-input');
                        if (!inp) return;
                        // Show the row and open its input area.
                        row.style.display = '';
                        var area = row.querySelector('.tc-links-dd-input-area');
                        if (area) area.style.display = '';
                        inp.value = tcValue;
                        // Mark as accepted so commitRow does not block on autocomplete guard.
                        inp.dataset.acSelected = '1';
                        commitRow(row);
                        // Clean the params from the URL without a page reload.
                        var clean = new URL(window.location.href);
                        clean.searchParams.delete('tc_lt');
                        clean.searchParams.delete('tc_value');
                        history.replaceState(null, '', clean.toString());
                    })();
                })();
                </script>
    <?php if ( $is_edit ) : ?>
            </td>
        </tr>
        <?php tc_render_suggested_links_panel( $term ); ?>
    <?php else : ?>
        </div>
    <?php endif; ?>
    <?php
}

/**
 * Render the collapsible "Suggested identifiers" panel on the term edit screen.
 *
 * Shows pending tc_suggested_link comments for this term and Accept/Dismiss actions.
 * Only shown to admins on existing terms that are in an enabled taxonomy.
 *
 * @since 1.0.0
 * @param WP_Term $term
 */
function tc_render_suggested_links_panel( WP_Term $term ): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $suggestions = get_comments( array(
        'type'       => 'tc_suggested_link',
        'meta_key'   => TC_META_SUGGESTION_TERM_ID,
        'meta_value' => (string) $term->term_id,
        'status'     => 'hold',
        'orderby' => 'comment_date',
        'order'   => 'DESC',
        'number'  => 20,
    ) );

    if ( empty( $suggestions ) ) {
        return;
    }
    ?>
    <tr class="form-field">
        <th scope="row"><?php esc_html_e( 'Suggested identifiers', 'termconnect' ); ?></th>
        <td>
            <details>
                <summary style="cursor:pointer;user-select:none">
                    <?php
                    printf(
                        /* translators: %d: number of pending suggestions */
                        esc_html( _n( '%d pending suggestion', '%d pending suggestions', count( $suggestions ), 'termconnect' ) ),
                        count( $suggestions )
                    );
                    ?>
                </summary>
                <ul style="margin:.5em 0 0;padding:0;list-style:none">
                    <?php foreach ( $suggestions as $sug ) :
                        $url   = $sug->comment_author_url;
                        $match = function_exists( 'tc_suggested_link_match_url' ) ? tc_suggested_link_match_url( $url ) : null;
                        $link_exists = $match && function_exists( 'tc_suggested_link_exists' ) && tc_suggested_link_exists( $term->term_id, $match['peer_site_slug'], $match['value'] );
                    ?>
                    <li style="margin:.4em 0;padding:.4em 0;border-bottom:1px solid #f0f0f1">
                        <a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $url ); ?></a>
                        <?php if ( $match ) : ?>
                            <span style="color:#2271b1;margin-left:6px"><?php echo esc_html( $match['label'] . ': ' . $match['value'] ); ?></span>
                        <?php endif; ?>
                        <?php if ( $sug->comment_content ) : ?>
                            <span style="color:#555;margin-left:6px;font-style:italic"><?php echo esc_html( $sug->comment_content ); ?></span>
                        <?php endif; ?>
                        &nbsp;
                        <?php if ( ! $link_exists && $match ) :
                            $accept_url = add_query_arg( array( 'taxonomy' => $term->taxonomy, 'tag_ID' => $term->term_id, 'tc_lt' => $match['peer_site_slug'], 'tc_value' => $match['value'] ), admin_url( 'term.php' ) );
                        ?>
                        <a href="<?php echo esc_url( $accept_url ); ?>" class="button button-small"><?php esc_html_e( 'Accept', 'termconnect' ); ?></a>
                        <?php endif; ?>
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'comment.php?action=trash&c=' . $sug->comment_ID ), "trash-comment_$sug->comment_ID" ) ); ?>"
                           class="button button-small" style="margin-left:4px"><?php esc_html_e( 'Dismiss', 'termconnect' ); ?></a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </details>
        </td>
    </tr>
    <?php
}
