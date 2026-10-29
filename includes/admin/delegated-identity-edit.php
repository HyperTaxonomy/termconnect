<?php
/**
 * Delegated Identity — term edit/add screen field.
 *
 * Renders a pill+dropdown UI matching the External Links component:
 *   Active:  pill showing "[Peer label] / remote-slug ↗ ×"  +  status line
 *   Empty:   container with "+" button opening an inline dropdown with a
 *            peer site <select> and a term-slug autocomplete input.
 *            After the user picks a suggestion a confirmation pane appears
 *            showing a description/mappings preview and a warning before commit.
 *
 * Sits at priority 9 on {taxonomy}_edit_form_fields / {taxonomy}_add_form_fields:
 * after Term Groups (HyperTaxonomy priority 8) and before HyperTaxonomy's collapsible start (also
 * priority 9 but registered later, because TermConnect's init:15 fires before HyperTaxonomy's
 * admin_init:999).
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Prevent Quick Edit from overwriting name/slug on delegated terms.
 *
 * The Quick Edit form locks those fields client-side, but a server-side
 * guard is needed in case JS fails or is bypassed. TC's own delegation
 * sync uses wp_update_term() outside the inline-save-tax AJAX action, so
 * this filter does not interfere with it.
 */
add_filter( 'wp_update_term_data', static function ( $data, $term_id, $taxonomy, $args ) {
    if ( ! wp_doing_ajax() || ( sanitize_key( wp_unslash( $_POST['action'] ?? '' ) ) !== 'inline-save-tax' ) ) {
        return $data;
    }
    $source = tc_get_identity_source( $term_id );
    if ( ! $source ) {
        return $data;
    }
    $sync_name = ! isset( $source['sync_name'] ) || ! empty( $source['sync_name'] );
    if ( $sync_name ) {
        $current = get_term( $term_id );
        if ( ! is_wp_error( $current ) ) {
            $data['name'] = $current->name;
            $data['slug'] = $current->slug;
        }
    }
    return $data;
}, 10, 4 );

/**
 * Render the Delegated Identity field on term add/edit screens.
 *
 * @param WP_Term|string $term  WP_Term on edit screens, taxonomy slug on add screens.
 */
function tc_add_delegated_identity_field( $term ): void {
    $taxonomy = is_object( $term ) ? $term->taxonomy : $term;
    if ( ! in_array( $taxonomy, TC_Settings::get_enabled_taxonomies(), true ) ) {
        return;
    }
    if ( 'group' === $taxonomy ) {
        return;
    }

    $term_id = is_object( $term ) ? $term->term_id : 0;
    $source  = $term_id ? tc_get_identity_source( $term_id ) : null;
    $is_edit = $term_id > 0;

    // Peer sites: enabled WordPress peer link types available on this taxonomy only
    // (a type scoped to another taxonomy via its own 'taxonomies' restriction — e.g.
    // Social Hashtags & Mentions' author-profile peer, which only applies to 'mention'
    // — isn't a meaningful delegation target here and must not count toward "peers exist").
    $peer_sites = array_filter(
        tc_get_enabled_link_types_for_taxonomy( $taxonomy ),
        static fn( $peer_site ) => 'wordpress' === ( $peer_site['platform'] ?? '' )
    );

    if ( $is_edit ) {
        echo '<tr class="form-field tc-delegated-identity-field" id="tc-delegated-identity-row">';
        echo '<th scope="row" valign="top"><label>' . esc_html__( 'Delegated Identity', 'termconnect' ) . '</label></th>';
        echo '<td>';
    } else {
        echo '<div class="form-field tc-delegated-identity-field" id="tc-delegated-identity-row">';
        echo '<label>' . esc_html__( 'Delegated Identity', 'termconnect' ) . '</label>';
    }

    if ( $source ) {
        tc_render_delegated_identity_active( $term_id, $source );
        tc_render_di_panels_once();
        $lock_name = ! empty( $source['sync_name'] ) || ! isset( $source['sync_name'] );
        $lock_desc = ! empty( $source['sync_description'] ) || ! isset( $source['sync_description'] );
        ?>
        <script>
        jQuery(function($){
            function tcReadOnly( $el ) {
                $el.prop( 'readonly', true ).css({ background: '#f0f0f1', cursor: 'not-allowed' });
            }
            // Slug is always locked — canonical matching key.
            tcReadOnly( $( '#slug, #tag-slug' ) );
            <?php if ( $lock_name ) : ?>
            tcReadOnly( $( '#name, #tag-name' ) );
            <?php endif; ?>
            <?php if ( $lock_desc ) : ?>
            tcReadOnly( $( '#description, #tag-description' ) );
            <?php endif; ?>
        });
        </script>
        <?php
    } else {
        tc_render_delegated_identity_empty( $term_id, $term, $peer_sites );
    }

    if ( $is_edit ) {
        echo '</td></tr>';
    } else {
        echo '</div>';
    }
}

/**
 * Render the field when NO identity source is set.
 *
 * @param int              $term_id    0 on add form.
 * @param WP_Term|string   $term
 * @param array            $peer_sites Enabled WordPress peer link types, keyed by slug.
 */
function tc_render_delegated_identity_empty( int $term_id, $term, array $peer_sites ): void {
    $local_slug = is_object( $term ) ? $term->slug : '';
    $taxonomy   = is_object( $term ) ? $term->taxonomy : (string) $term;
    $nonce      = $term_id ? wp_create_nonce( 'tc_admin' ) : '';
    ?>
    <div class="tc-external-links-field">
        <?php if ( empty( $peer_sites ) ) : ?>
        <p class="description">
            <?php
            printf(
                /* translators: %s: Peer Sites admin URL */
                wp_kses(
                    __( 'No WordPress peer sites configured. Add a WordPress peer in <a href="%s">Peer Sites</a> first.', 'termconnect' ),
                    array( 'a' => array( 'href' => array() ) )
                ),
                esc_url( admin_url( 'admin.php?page=tc-peer-sites' ) )
            );
            ?>
        </p>
        <?php else : ?>
        <div class="tc-links-container tc-di-container"
             data-term-id="<?php echo esc_attr( $term_id ); ?>"
             data-nonce="<?php echo esc_attr( $nonce ); ?>"
             data-local-slug="<?php echo esc_attr( $local_slug ); ?>"
             data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>"
             data-hierarchical="<?php echo esc_attr( is_taxonomy_hierarchical( $taxonomy ) ? '1' : '0' ); ?>">

            <button type="button" class="tc-links-add-btn tc-di-add-btn"
                    title="<?php esc_attr_e( 'Delegate identity', 'termconnect' ); ?>">
                <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                <span class="screen-reader-text"><?php esc_html_e( 'Delegate identity', 'termconnect' ); ?></span>
            </button>

            <div class="tc-links-dropdown tc-di-dropdown" style="display:none">
                <div class="tc-links-dd-row tc-di-site-row">
                    <label style="font-size:11px;color:#646970;padding:6px 10px 2px;display:block">
                        <?php esc_html_e( 'Peer site', 'termconnect' ); ?>
                    </label>
                    <select class="tc-di-site-select" style="width:100%;margin:0 10px 6px;box-sizing:border-box;max-width:calc(100% - 20px)">
                        <option value="">&mdash; <?php esc_html_e( 'Select peer site', 'termconnect' ); ?> &mdash;</option>
                        <?php foreach ( $peer_sites as $peer_site_slug => $peer_site ) : ?>
                        <option value="<?php echo esc_attr( $peer_site_slug ); ?>">
                            <?php echo esc_html( $peer_site['label'] ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Lookup status: shown after peer selected while fetching the same-slug remote term -->
                <div class="tc-di-lookup-status" style="display:none;padding:4px 10px 8px;font-size:13px"></div>
            </div>
        </div>

        <!-- Confirmation pane: shown after remote term found, before commit -->
        <div class="tc-di-confirm" style="display:none;margin-top:8px">
            <div class="tc-di-preview-box" style="display:none;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px;padding:10px 12px;margin-bottom:8px">
                <div class="tc-di-preview-desc description" style="margin:0 0 6px"></div>
                <div class="tc-di-preview-mappings"></div>
            </div>
            <div style="background:#fff8e5;border:1px solid #d6a700;border-radius:3px;padding:8px 12px;margin-bottom:8px">
                <strong><?php esc_html_e( 'Before you continue:', 'termconnect' ); ?></strong>
                <ul style="margin:4px 0 0 16px;list-style:disc">
                    <li><?php esc_html_e( 'The slug will be locked to match the remote term and kept in sync.', 'termconnect' ); ?></li>
                    <li><?php esc_html_e( 'External Links for this term will be replaced by the remote term\'s identifiers while delegation is active.', 'termconnect' ); ?></li>
                </ul>
            </div>
            <div style="margin-bottom:8px">
                <label style="display:block;margin-bottom:4px">
                    <input type="checkbox" class="tc-di-sync-name" value="1" checked />
                    <?php esc_html_e( 'Sync name from remote (makes field read-only while delegated)', 'termconnect' ); ?>
                </label>
                <label style="display:block">
                    <input type="checkbox" class="tc-di-sync-description" value="1" checked />
                    <?php esc_html_e( 'Sync description from remote (makes field read-only while delegated)', 'termconnect' ); ?>
                </label>
            </div>
            <div class="tc-di-sync-descendants-row" style="display:none;margin-bottom:8px">
                <label>
                    <input type="checkbox" class="tc-di-sync-descendants" value="1" checked />
                    <?php esc_html_e( 'Track and sync descendant terms, including future ones added remotely', 'termconnect' ); ?>
                </label>
            </div>
            <button type="button" class="button button-primary tc-di-confirm-btn"
                    data-term-id="<?php echo esc_attr( $term_id ); ?>"
                    data-nonce="<?php echo esc_attr( $nonce ); ?>">
                <?php esc_html_e( 'Delegate', 'termconnect' ); ?>
            </button>
            <button type="button" class="button tc-di-cancel-confirm" style="margin-left:4px">
                <?php esc_html_e( 'Cancel', 'termconnect' ); ?>
            </button>
            <span class="tc-di-confirm-status description" style="margin-left:8px"></span>
        </div>
        <?php endif; ?>

        <p class="description" style="margin-top:6px">
            <?php
            if ( function_exists( 'htax_get_included_terms' ) ) {
                esc_html_e( 'Terms can delegate their title, description, external links and/or relationships to an externally defined term on a peer WordPress site, matched by slug.', 'termconnect' );
            } else {
                esc_html_e( 'Terms can delegate their title and/or description to an externally defined term on a peer WordPress site, matched by slug.', 'termconnect' );
            }
            ?>
        </p>
    </div>
    <?php
}

/**
 * Render the field when an identity source IS set.
 *
 * @param int   $term_id
 * @param array $source  Identity source meta.
 */
function tc_render_delegated_identity_active( int $term_id, array $source ): void {
    $peer_site_slug      = $source['peer_site_slug'];
    $remote_slug  = $source['remote_slug'];
    $remote_link  = $source['remote_link'] ?? '';
    $site_url     = $source['site_url'];
    $status       = $source['status'] ?? 'active';
    $last_fetched = $source['last_fetched'] ?? 0;
    $nonce        = wp_create_nonce( 'tc_admin' );

    $link_type  = tc_get_link_type( $peer_site_slug );
    $site_label = $link_type ? $link_type['label'] : ( wp_parse_url( $site_url, PHP_URL_HOST ) ?: $site_url );
    $pill_url   = $remote_link ?: $site_url;

    switch ( $status ) {
        case 'active':
            $status_label = __( 'Active', 'termconnect' );
            break;
        case 'unreachable':
            $status_label = __( 'Source unreachable (using cached data)', 'termconnect' );
            break;
        case 'not_found':
            $status_label = __( 'Remote term not found', 'termconnect' );
            break;
        case 'redirect_pending':
            $status_label = __( 'Remote term redirected — review required', 'termconnect' );
            break;
        case 'redirect_declined':
            $status_label = __( 'Remote term redirected — current delegation kept', 'termconnect' );
            break;
        default:
            $status_label = $status;
            break;
    }
    $status_class = 'active' === $status ? 'tc-di-status-ok' : 'tc-di-status-warning';

    // Determine whether this delegation can be removed in place from this screen.
    // Manual delegations (no sv_source_id) and 'terms' scope are always removable.
    // For 'roots' scope, only the listed root terms are removable; descendants are not.
    // 'full', 'local_group', and 'remote_group' scope terms are managed via the Shared Vocabularies page.
    $sv_source_id  = $source['sv_source_id'] ?? '';
    $removable     = true;
    $sv_scope      = '';
    $sv_scope_tab  = '';
    $sv_scope_label = '';
    $sv_scope_type  = '';
    $sv_name        = '';

    if ( $sv_source_id ) {
        $all_sources = get_option( 'tc_shared_vocabularies', array() );
        $sv_config   = null;
        foreach ( $all_sources as $sv ) {
            if ( ( $sv['id'] ?? '' ) === $sv_source_id ) {
                $sv_config = $sv;
                break;
            }
        }

        if ( $sv_config ) {
            $sv_name  = $sv_config['sv_name'] ?? '';
            $sv_scope = $sv_config['scope'] ?? '';
            $scope_tabs = array(
                'full'         => array( 'tab' => 'full',         'label' => __( 'Taxonomies', 'termconnect' ) ),
                'remote_group' => array( 'tab' => 'remote_group', 'label' => __( 'Remote Groups', 'termconnect' ) ),
                'local_group'  => array( 'tab' => 'local_group',  'label' => __( 'Local Groups', 'termconnect' ) ),
                'roots'        => array( 'tab' => 'roots',        'label' => __( 'Term Hierarchies', 'termconnect' ) ),
                'terms'        => array( 'tab' => 'terms',        'label' => __( 'Individual Terms', 'termconnect' ) ),
            );
            $sv_scope_tab  = $scope_tabs[ $sv_scope ]['tab'] ?? 'full';
            $sv_scope_type = array(
                'full'         => __( 'taxonomy', 'termconnect' ),
                'remote_group' => __( 'group', 'termconnect' ),
                'local_group'  => __( 'group', 'termconnect' ),
                'roots'        => __( 'hierarchy', 'termconnect' ),
                'terms'        => __( 'individual terms', 'termconnect' ),
            )[ $sv_scope ] ?? $sv_scope;

            if ( 'remote_group' === $sv_scope && ! empty( $sv_config['remote_group_name'] ) ) {
                $sv_scope_label = $sv_config['remote_group_name'];
            } elseif ( 'local_group' === $sv_scope && ! empty( $sv_config['group_name'] ) ) {
                $sv_scope_label = $sv_config['group_name'];
            } elseif ( in_array( $sv_scope, array( 'full', 'roots', 'terms' ), true ) && ! empty( $sv_config['taxonomy'] ) ) {
                $tax_obj   = get_taxonomy( $sv_config['taxonomy'] );
                $tax_label = $tax_obj ? $tax_obj->label : $sv_config['taxonomy'];
                if ( 'roots' === $sv_scope && ! empty( $sv_config['roots'] ) ) {
                    // Find which configured root this term (or one of its ancestors) falls under.
                    $root_slug_names  = array_column( $sv_config['roots'], 'remote_name', 'remote_slug' );
                    $term_remote_slug = $source['remote_slug'] ?? '';
                    $root_name        = $root_slug_names[ $term_remote_slug ] ?? '';
                    if ( ! $root_name ) {
                        foreach ( get_ancestors( $term_id, $sv_config['taxonomy'], 'taxonomy' ) as $ancestor_id ) {
                            $ancestor_src = get_term_meta( $ancestor_id, TC_META_IDENTITY_SOURCE, true );
                            if ( is_array( $ancestor_src ) ) {
                                $root_name = $root_slug_names[ $ancestor_src['remote_slug'] ?? '' ] ?? '';
                                if ( $root_name ) { break; }
                            }
                        }
                    }
                    $sv_scope_label = $root_name ? $tax_label . ': ' . $root_name : $tax_label;
                } else {
                    $sv_scope_label = $tax_label;
                }
            } else {
                $sv_scope_label = $scope_tabs[ $sv_scope ]['label'] ?? $sv_scope;
            }

            if ( 'full' === $sv_scope || 'local_group' === $sv_scope || 'remote_group' === $sv_scope ) {
                $removable = false;
            } elseif ( 'roots' === $sv_scope ) {
                // Only root terms (listed in the source) are removable; descendants are not.
                $remote_term_id = (int) ( $source['remote_term_id'] ?? 0 );
                $is_root        = false;
                foreach ( $sv_config['roots'] ?? array() as $root ) {
                    if ( (int) ( $root['remote_term_id'] ?? 0 ) === $remote_term_id ) {
                        $is_root = true;
                        break;
                    }
                }
                $removable = $is_root;
            }
            // 'terms' scope: always removable.
        }
    }

    $sv_page_url = $sv_source_id
        ? add_query_arg( 'scope', $sv_scope_tab, admin_url( 'admin.php?page=tc-shared-vocabularies' ) )
        : '';

    ?>
    <div class="tc-external-links-field">
        <div class="tc-links-container tc-di-container-active">
            <span class="tc-link-pill">
                <?php if ( $pill_url ) : ?>
                <a href="<?php echo esc_url( $pill_url ); ?>" target="_blank" rel="noopener noreferrer"
                   style="color:inherit;text-decoration:none">
                    <?php echo esc_html( $site_label ); ?>: <?php echo esc_html( $remote_slug ); ?>&nbsp;&#x2197;
                </a>
                <?php else : ?>
                <?php echo esc_html( $site_label ); ?>: <?php echo esc_html( $remote_slug ); ?>
                <?php endif; ?>
                <?php if ( $removable ) : ?>
                <button type="button" class="tc-link-pill-remove tc-di-remove-btn"
                        data-term-id="<?php echo esc_attr( $term_id ); ?>"
                        data-nonce="<?php echo esc_attr( $nonce ); ?>"
                        data-sync-name="<?php echo esc_attr( ! empty( $source['sync_name'] ) || ! isset( $source['sync_name'] ) ? '1' : '0' ); ?>"
                        data-sync-description="<?php echo esc_attr( ! empty( $source['sync_description'] ) || ! isset( $source['sync_description'] ) ? '1' : '0' ); ?>"
                        title="<?php esc_attr_e( 'End delegation', 'termconnect' ); ?>">
                    &times;
                </button>
                <?php endif; ?>
            </span>
        </div>

        <p class="description" style="margin-top:4px">
            <span class="tc-di-status <?php echo esc_attr( $status_class ); ?>">
                <?php echo esc_html( $status_label ); ?>
            </span>
            <?php if ( $last_fetched ) : ?>
            &mdash;
            <?php
            printf(
                /* translators: %s: relative time string */
                esc_html__( 'cached %s ago', 'termconnect' ),
                esc_html( human_time_diff( $last_fetched ) )
            );
            ?>
            <?php endif; ?>
            <?php if ( ! $sv_source_id ) : ?>
            &mdash;
            <button type="button" class="button-link tc-di-refresh-btn"
                    data-term-id="<?php echo esc_attr( $term_id ); ?>"
                    data-nonce="<?php echo esc_attr( $nonce ); ?>">
                <?php esc_html_e( 'Refresh now', 'termconnect' ); ?>
            </button>
            <?php endif; ?>
            <?php if ( $removable ) : ?>
            &mdash;
            <button type="button" class="button-link tc-di-settings-btn"
                    data-term-id="<?php echo esc_attr( $term_id ); ?>"
                    data-nonce="<?php echo esc_attr( $nonce ); ?>"
                    data-sync-name="<?php echo esc_attr( ! empty( $source['sync_name'] ) || ! isset( $source['sync_name'] ) ? '1' : '0' ); ?>"
                    data-sync-description="<?php echo esc_attr( ! empty( $source['sync_description'] ) || ! isset( $source['sync_description'] ) ? '1' : '0' ); ?>">
                <?php esc_html_e( 'Sync settings', 'termconnect' ); ?>
            </button>
            <?php endif; ?>
            <?php if ( ! $sv_source_id || $removable ) : ?>
            <span class="tc-di-action-status" style="margin-left:4px"></span>
            <?php endif; ?>
        </p>

        <?php if ( $sv_source_id && $sv_page_url ) : ?>
        <p class="description" style="margin-top:4px">
            <?php
            if ( $sv_name ) {
                printf(
                    /* translators: 1: vocabulary name, 2: scope type (e.g. "taxonomy"), 3: peer site label, 4: page URL */
                    wp_kses(
                        __( 'Shared vocabulary: %1$s (%2$s &middot; %3$s) &nbsp;&mdash;&nbsp; <a href="%4$s">Manage</a>', 'termconnect' ),
                        array( 'a' => array( 'href' => array() ) )
                    ),
                    esc_html( $sv_name ),
                    esc_html( $sv_scope_type ),
                    esc_html( $site_label ),
                    esc_url( $sv_page_url )
                );
            } else {
                printf(
                    /* translators: 1: scope type (e.g. "full"), 2: specific label (e.g. "Tags"), 3: peer site label, 4: page URL */
                    wp_kses(
                        __( 'Shared vocabulary (%1$s): %2$s &middot; %3$s &nbsp;&mdash;&nbsp; <a href="%4$s">Manage</a>', 'termconnect' ),
                        array( 'a' => array( 'href' => array() ) )
                    ),
                    esc_html( $sv_scope_type ),
                    esc_html( $sv_scope_label ),
                    esc_html( $site_label ),
                    esc_url( $sv_page_url )
                );
            }
            ?>
        </p>
        <?php endif; ?>

        <?php
        $rel_sync_issues = get_term_meta( $term_id, TC_META_REL_SYNC_ISSUES, true );
        $peer_sync_issues       = array();
        if ( ! empty( $rel_sync_issues ) && is_array( $rel_sync_issues ) ) :
            // Filter to issues for this peer link type.
            $peer_sync_issues = array_filter(
                $rel_sync_issues,
                static fn( $v ) => is_array( $v ) && ( $v['peer_site_slug'] ?? '' ) === $peer_site_slug
            );
        endif;
        if ( ! empty( $peer_sync_issues ) ) :
        ?>
        <div style="margin-top:8px;background:#fff8e5;border:1px solid #d6a700;border-radius:3px;padding:8px 12px">
            <strong><?php esc_html_e( 'Stale relationship rows', 'termconnect' ); ?></strong>
            <p style="margin:4px 0">
                <?php
                printf(
                    /* translators: %d: number of stale rows */
                    esc_html( _n(
                        '%d relationship row exists locally but was not present on the peer site during the last sync.',
                        '%d relationship rows exist locally but were not present on the peer site during the last sync.',
                        count( $peer_sync_issues ),
                        'termconnect'
                    ) ),
                    count( $peer_sync_issues )
                );
                ?>
            </p>
            <ul style="margin:4px 0 0 16px;list-style:disc">
                <?php foreach ( $peer_sync_issues as $issue ) :
                    $rel_type      = $issue['relationship_type'] ?? '';
                    $target_id     = (int) ( $issue['target_term_id'] ?? 0 );
                    $target_term   = $target_id ? get_term( $target_id ) : null;
                    $target_label  = ( $target_term && ! is_wp_error( $target_term ) ) ? $target_term->name : '#' . $target_id;
                    $detected_at   = (int) ( $issue['detected_at'] ?? 0 );
                ?>
                <li>
                    <strong><?php echo esc_html( $rel_type ); ?></strong>
                    &rarr; <?php echo esc_html( $target_label ); ?>
                    <?php if ( $detected_at ) : ?>
                    <span class="description" style="font-size:11px">
                        (<?php
                        printf(
                            /* translators: %s: relative time string */
                            esc_html__( 'first detected %s ago', 'termconnect' ),
                            esc_html( human_time_diff( $detected_at ) )
                        );
                        ?>)
                    </span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

    </div>
    <?php
}

/**
 * Output the remove-confirmation and sync-settings floating panels once per page.
 *
 * A shared backdrop is included. Both panels are populated and shown by tc-term-edit.js.
 */
function tc_render_di_panels_once(): void {
    static $rendered = false;
    if ( $rendered ) {
        return;
    }
    $rendered = true;
    ?>
    <div id="tc-di-backdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:160000"></div>

    <div id="tc-di-remove-panel" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;width:min(480px,90vw);z-index:160001;box-shadow:0 8px 32px rgba(0,0,0,.18)">
        <h3 style="margin:0 0 10px"><?php esc_html_e( 'End delegation?', 'termconnect' ); ?></h3>
        <p style="margin:0 0 12px"><?php esc_html_e( 'Delegation to the remote term will end. Remote-synced name and description will be replaced with local values.', 'termconnect' ); ?></p>
        <div id="tc-di-remove-restore-name-row" style="margin-bottom:6px">
            <label>
                <input type="checkbox" id="tc-di-restore-name" value="1" checked>
                <?php esc_html_e( 'Restore local name', 'termconnect' ); ?>
            </label>
        </div>
        <div id="tc-di-remove-restore-desc-row" style="margin-bottom:6px">
            <label>
                <input type="checkbox" id="tc-di-restore-description" value="1" checked>
                <?php esc_html_e( 'Restore local description', 'termconnect' ); ?>
            </label>
        </div>
        <div style="margin-bottom:16px">
            <label>
                <input type="checkbox" id="tc-di-keep-peer-links" value="1">
                <?php esc_html_e( 'Keep external links for existing peer sites', 'termconnect' ); ?>
            </label>
            <p class="description" style="margin:3px 0 0 20px"><?php esc_html_e( 'Delegated links for locally-configured platforms become local links. Any existing local link for the same platform will be replaced.', 'termconnect' ); ?></p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <button type="button" id="tc-di-remove-confirm-btn" class="button button-primary" style="background:#d63638;border-color:#b32d2e;box-shadow:0 1px 0 #b32d2e">
                <?php esc_html_e( 'End delegation', 'termconnect' ); ?>
            </button>
            <button type="button" id="tc-di-remove-cancel-btn" class="button">
                <?php esc_html_e( 'Cancel', 'termconnect' ); ?>
            </button>
            <span id="tc-di-remove-status" class="description" style="margin-left:4px"></span>
        </div>
    </div>

    <div id="tc-di-settings-panel" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;width:min(420px,90vw);z-index:160001;box-shadow:0 8px 32px rgba(0,0,0,.18)">
        <h3 style="margin:0 0 14px"><?php esc_html_e( 'Sync settings', 'termconnect' ); ?></h3>
        <input type="hidden" id="tc-di-settings-term-id" value="">
        <input type="hidden" id="tc-di-settings-nonce" value="">
        <div style="margin-bottom:8px">
            <label>
                <input type="checkbox" id="tc-di-settings-sync-name" value="1">
                <?php esc_html_e( 'Sync name from remote (makes field read-only while delegated)', 'termconnect' ); ?>
            </label>
        </div>
        <div style="margin-bottom:16px">
            <label>
                <input type="checkbox" id="tc-di-settings-sync-description" value="1">
                <?php esc_html_e( 'Sync description from remote (makes field read-only while delegated)', 'termconnect' ); ?>
            </label>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <button type="button" id="tc-di-settings-save-btn" class="button button-primary">
                <?php esc_html_e( 'Save', 'termconnect' ); ?>
            </button>
            <button type="button" id="tc-di-settings-cancel-btn" class="button">
                <?php esc_html_e( 'Cancel', 'termconnect' ); ?>
            </button>
            <span id="tc-di-settings-status" class="description" style="margin-left:4px"></span>
        </div>
    </div>
    <?php
}
