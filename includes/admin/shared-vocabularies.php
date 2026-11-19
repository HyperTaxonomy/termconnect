<?php
/**
 * Shared Vocabularies — admin page (Terms → Shared Vocabularies).
 *
 * Sources are grouped into four fixed-priority scope tabs:
 *   Taxonomies → Term Groups (HT only) → Term Hierarchies → Individual Terms
 * Within each tab the order is user-controlled via drag-to-reorder and determines
 * first-wins priority when multiple sources could claim the same term.
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render the Shared Vocabularies admin page.
 */
function tconn_sv_render_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'termconnect' ) );
    }

    $sources   = tconn_sv_sort_sources( get_option( 'tconn_shared_vocabularies', array() ) );
    $nonce     = wp_create_nonce( 'tconn_sv_admin' );
    $ht_active = function_exists( 'htax_get_terms_in_groups' );

    $peer_sites = array_filter(
        tconn_get_enabled_link_types(),
        static fn( $peer_site ) => 'wordpress' === ( $peer_site['platform'] ?? '' )
    );

    $by_scope = array( 'full' => array(), 'remote_group' => array(), 'local_group' => array(), 'roots' => array(), 'terms' => array() );
    foreach ( $sources as $src ) {
        $scope = $src['scope'] ?? 'full';
        if ( isset( $by_scope[ $scope ] ) ) {
            $by_scope[ $scope ][] = $src;
        }
    }

    $scope_meta = array(
        'full'         => array(
            'label' => __( 'Taxonomies', 'termconnect' ),
            'desc'  => __( 'Mirror an entire taxonomy from a peer site. All terms are created or linked locally, matched by slug.', 'termconnect' ),
        ),
        'remote_group' => array(
            'label' => __( 'Remote Groups', 'termconnect' ),
            'desc'  => __( 'Mirror a remote HyperTaxonomy group from a peer site. Members are fetched and created locally, then assigned to a matching local group.', 'termconnect' ),
        ),
        'local_group'  => array(
            'label' => __( 'Local Groups', 'termconnect' ),
            'desc'  => __( 'Delegate all members of a local HyperTaxonomy group to a peer site, matched by slug.', 'termconnect' ),
        ),
        'roots'        => array(
            'label' => __( 'Term Hierarchies', 'termconnect' ),
            'desc'  => __( 'Mirror specific hierarchy branches — select one or more root terms; their full subtrees are synced.', 'termconnect' ),
        ),
        'terms'        => array(
            'label' => __( 'Individual Terms', 'termconnect' ),
            'desc'  => __( 'Mirror specific individual terms from a peer site, matched by slug. Descendants are not included.', 'termconnect' ),
        ),
    );

    $tab_scopes = $ht_active
        ? array( 'full', 'remote_group', 'local_group', 'roots', 'terms' )
        : array( 'full', 'roots', 'terms' );

    wp_enqueue_script( 'jquery-ui-sortable' );
    wp_enqueue_style( 'tconn-term-edit', TCONN_PLUGIN_URL . 'assets/css/tconn-term-edit.css', array(), TCONN_VERSION );

    $merge_proposals = get_option( 'tconn_merge_proposals', array() );
    ?>
    <?php ob_start(); ?>
        .tconn-sv-drag-handle { cursor:move; color:#bbb; padding:0 8px; font-size:18px; line-height:1; user-select:none; }
        .tconn-sv-drag-handle:hover { color:#555; }
        .tconn-sv-drag-placeholder td { background:#e7f3ff !important; }
        @keyframes tconn-spin { to { transform:rotate(360deg); } }
        .tconn-sv-syncing .dashicons { display:inline-block; animation:tconn-spin .8s linear infinite; }
        #tconn-sv-roots-list:has(input:disabled) { background:#f6f7f7; border-color:#dcdcde; }
        #tconn-sv-roots-list:has(input:disabled) input { color:#a7aaad; cursor:not-allowed; }
        #tconn-sv-roots-list:has(input.tconn-sv-term-searching)::after { content:''; display:inline-block; width:14px; height:14px; border:2px solid #c3c4c7; border-top-color:#2271b1; border-radius:50%; animation:tconn-spin .7s linear infinite; vertical-align:middle; margin-left:4px; flex-shrink:0; }
        #tconn-sv-term-search { height:22px !important; min-height:0 !important; }
        #tconn-sv-roots-list .tconn-link-pill.selected { background:#2271b1; color:#fff; border-color:#2271b1; }
        #tconn-sv-roots-list .tconn-link-pill.selected .tconn-link-pill-remove { color:#fff; }
        .tconn-sv-pill-warn { font-size:11px; margin-right:3px; color:#dba617; }
    <?php tconn_print_inline_style( 'tconn-shared-vocabularies', ob_get_clean() ); ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Shared Vocabularies', 'termconnect' ); ?></h1>
        <hr class="wp-header-end">

        <?php if ( empty( $peer_sites ) ) : ?>
        <div class="notice notice-warning inline">
            <p><?php printf(
                /* translators: %s: Peer Sites admin URL */
                wp_kses( __( 'No WordPress peer sites configured. Add a WordPress peer in <a href="%s">Peer Sites</a> first.', 'termconnect' ), array( 'a' => array( 'href' => array() ) ) ),
                esc_url( admin_url( 'admin.php?page=tconn-peer-sites' ) )
            ); ?></p>
        </div>
        <?php endif; ?>

        <!-- Backdrop for floating form -->
        <div id="tconn-sv-form-backdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:160000"></div>

        <!-- Shared add/edit form (scope-driven by active tab) -->
        <div id="tconn-sv-form" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:20px;width:min(700px,90vw);max-height:90vh;overflow-y:auto;z-index:160001;box-shadow:0 8px 32px rgba(0,0,0,.18)">
            <h3 style="margin:0 0 16px" id="tconn-sv-form-title"><?php esc_html_e( 'Add Vocabulary Source', 'termconnect' ); ?></h3>
            <input type="hidden" id="tconn-sv-edit-id" value="">
            <input type="hidden" id="tconn-sv-active-scope" value="full">
            <table class="form-table" style="margin:0">
                <tr>
                    <th><label for="tconn-sv-name"><?php esc_html_e( 'Name', 'termconnect' ); ?></label></th>
                    <td>
                        <input type="text" id="tconn-sv-name" class="regular-text"
                               placeholder="<?php esc_attr_e( 'Optional — helps you distinguish this source', 'termconnect' ); ?>">
                    </td>
                </tr>
                <tr>
                    <th><label for="tconn-sv-description"><?php esc_html_e( 'Description', 'termconnect' ); ?></label></th>
                    <td>
                        <textarea id="tconn-sv-description" class="regular-text" rows="2"
                                  placeholder="<?php esc_attr_e( 'Optional', 'termconnect' ); ?>"></textarea>
                    </td>
                </tr>
                <tr id="tconn-sv-row-peer">
                    <th><label for="tconn-sv-peer"><?php esc_html_e( 'Peer site', 'termconnect' ); ?></label></th>
                    <td>
                        <select id="tconn-sv-peer" style="min-width:220px">
                            <option value="">&mdash; <?php esc_html_e( 'Select peer site', 'termconnect' ); ?> &mdash;</option>
                            <?php foreach ( $peer_sites as $peer_site_slug => $peer_site ) : ?>
                            <option value="<?php echo esc_attr( $peer_site_slug ); ?>"><?php echo esc_html( $peer_site['label'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr id="tconn-sv-row-taxonomy">
                    <th><label for="tconn-sv-taxonomy"><?php esc_html_e( 'Remote taxonomy', 'termconnect' ); ?></label></th>
                    <td>
                        <select id="tconn-sv-taxonomy" style="min-width:220px" disabled>
                            <option value="">&mdash; <?php esc_html_e( 'Select peer site first', 'termconnect' ); ?> &mdash;</option>
                        </select>
                        <span id="tconn-sv-taxonomy-loading" style="display:none;margin-left:8px;color:#888"><?php esc_html_e( 'Loading…', 'termconnect' ); ?></span>
                    </td>
                </tr>
                <tr id="tconn-sv-row-roots" style="display:none">
                    <th id="tconn-sv-roots-label"><?php esc_html_e( 'Root terms', 'termconnect' ); ?></th>
                    <td>
                        <div style="position:relative">
                            <div id="tconn-sv-roots-list" class="tconn-links-container" style="cursor:text;min-height:0">
                                <input type="text" id="tconn-sv-term-search"
                                       placeholder="<?php esc_attr_e( 'Search terms on peer…', 'termconnect' ); ?>"
                                       disabled autocomplete="off"
                                       style="border:none;outline:none;box-shadow:none;background:transparent;padding:2px 4px;flex:1 1 auto;min-width:120px;font-size:13px;height:22px;margin:0">
                            </div>
                            <ul id="tconn-sv-term-suggestions" style="display:none;position:absolute;top:100%;left:0;background:#fff;border:1px solid #ddd;border-top:none;z-index:9999;list-style:none;margin:-1px 0 0;padding:0;min-width:200px;max-height:200px;box-shadow:0 1px 2px rgba(0,0,0,.07)"></ul>
                        </div>
                        <p id="tconn-sv-coverage-warning" class="description" style="display:none;margin-top:6px;color:#996800"><?php esc_html_e( 'One or more terms are already covered by a higher-priority source and will be skipped during sync.', 'termconnect' ); ?></p>
                    </td>
                </tr>
                <tr id="tconn-sv-row-remote-group" style="display:none">
                    <th><label for="tconn-sv-remote-group"><?php esc_html_e( 'Remote group', 'termconnect' ); ?></label></th>
                    <td>
                        <select id="tconn-sv-remote-group" style="min-width:220px" disabled>
                            <option value="">&mdash; <?php esc_html_e( 'Select peer site first', 'termconnect' ); ?> &mdash;</option>
                        </select>
                        <span id="tconn-sv-remote-group-loading" style="display:none;margin-left:8px;color:#888"><?php esc_html_e( 'Loading…', 'termconnect' ); ?></span>
                        <p class="description" style="margin-top:6px"><?php esc_html_e( 'Members of the remote group will be fetched and mirrored locally, then assigned to a local group with the same slug (created if missing).', 'termconnect' ); ?></p>
                    </td>
                </tr>
                <tr id="tconn-sv-row-group" style="display:none">
                    <th><label for="tconn-sv-group-id"><?php esc_html_e( 'Local group', 'termconnect' ); ?></label></th>
                    <td>
                        <select id="tconn-sv-group-id" style="min-width:220px">
                            <option value="">&mdash; <?php esc_html_e( 'Loading groups…', 'termconnect' ); ?> &mdash;</option>
                        </select>
                        <p class="description"><?php esc_html_e( 'All group members will be delegated to the remote peer by slug.', 'termconnect' ); ?></p>
                        <label style="display:block;margin-top:6px">
                            <input type="checkbox" id="tconn-sv-group-sync-desc" value="1">
                            <?php esc_html_e( 'Sync descendants of hierarchical group members', 'termconnect' ); ?>
                        </label>
                    </td>
                </tr>
                <tr id="tconn-sv-row-rel-delegation" style="display:none">
                    <th><?php esc_html_e( 'Relationship delegation', 'termconnect' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="tconn-sv-rel-delegation" value="1">
                            <?php esc_html_e( 'Sync relationship types and relationships from this peer', 'termconnect' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Fetches custom relationship type definitions and term relationships with each sync, and writes each relationship. This adds time on large taxonomies, so it is disabled by default.', 'termconnect' ); ?></p>
                        <div id="tconn-sv-cross-taxonomy-row" style="display:none;margin-top:8px">
                            <label>
                                <input type="checkbox" id="tconn-sv-cross-taxonomy" value="1">
                                <?php esc_html_e( 'Include cross-taxonomy relationships', 'termconnect' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Write relationship rows whose target term is in a different taxonomy. Only attempted when the target is also fully delegated to this peer. Requires at least one prior full sync of each involved taxonomy.', 'termconnect' ); ?></p>
                        </div>
                    </td>
                </tr>
                <tr id="tconn-sv-row-orphan">
                    <th><?php esc_html_e( 'Missing terms', 'termconnect' ); ?></th>
                    <td>
                        <label style="display:block;margin-bottom:2px"><input type="radio" name="tconn-sv-orphan-action" value="flag" checked> <?php esc_html_e( 'Flag for review (default)', 'termconnect' ); ?></label>
                        <label style="display:block;margin-bottom:2px"><input type="radio" name="tconn-sv-orphan-action" value="delete_clean"> <?php esc_html_e( 'Delete if no local posts or relationships', 'termconnect' ); ?></label>
                        <label><input type="radio" name="tconn-sv-orphan-action" value="delete"> <?php esc_html_e( 'Delete unconditionally', 'termconnect' ); ?></label>
                        <p class="description"><?php esc_html_e( 'What to do when a delegated term is confirmed missing on this peer site (deleted, hidden, or moved to another taxonomy). Flagged terms appear in the Site Health issue count with status "not found".', 'termconnect' ); ?></p>
                    </td>
                </tr>
                <tr id="tconn-sv-row-misplace">
                    <th><?php esc_html_e( 'Parent mismatch', 'termconnect' ); ?></th>
                    <td>
                        <label style="display:block;margin-bottom:2px"><input type="radio" name="tconn-sv-misplace" value="flag" checked> <?php esc_html_e( 'Flag for review (default)', 'termconnect' ); ?></label>
                        <label style="display:block;margin-bottom:2px"><input type="radio" name="tconn-sv-misplace" value="link-only"> <?php esc_html_e( 'Link only — keep local parent', 'termconnect' ); ?></label>
                        <label><input type="radio" name="tconn-sv-misplace" value="re-parent"> <?php esc_html_e( 'Re-parent to match remote hierarchy', 'termconnect' ); ?></label>
                        <p class="description"><?php esc_html_e( 'What to do when a synced term has a different parent locally than on the peer site. Applies to hierarchical taxonomies only.', 'termconnect' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="tconn-sv-interval"><?php esc_html_e( 'Sync interval', 'termconnect' ); ?></label></th>
                    <td>
                        <select id="tconn-sv-interval">
                            <option value="twicedaily"><?php esc_html_e( 'Twice daily', 'termconnect' ); ?></option>
                            <option value="daily" selected><?php esc_html_e( 'Daily', 'termconnect' ); ?></option>
                            <option value="weekly"><?php esc_html_e( 'Weekly', 'termconnect' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Sync fields', 'termconnect' ); ?></th>
                    <td>
                        <label style="display:block;margin-bottom:4px">
                            <input type="checkbox" id="tconn-sv-sync-name" value="1" checked>
                            <?php esc_html_e( 'Sync name from peer site', 'termconnect' ); ?>
                        </label>
                        <label style="display:block;margin-bottom:4px">
                            <input type="checkbox" id="tconn-sv-sync-description" value="1" checked>
                            <?php esc_html_e( 'Sync description from peer site', 'termconnect' ); ?>
                        </label>
                        <label id="tconn-sv-sync-links-label" style="display:none">
                            <input type="checkbox" checked disabled>
                            <?php esc_html_e( 'Sync external links from peer site', 'termconnect' ); ?>
                        </label>
                    </td>
                </tr>
            </table>
            <div id="tconn-sv-conflict-area" style="display:none;margin-top:12px;padding:10px 12px;background:#fff8e5;border:1px solid #d6a700;border-radius:3px">
                <strong><?php esc_html_e( 'Review before continuing:', 'termconnect' ); ?></strong>
                <p style="margin:6px 0 0"><?php esc_html_e( 'The selected terms will be linked to the remote peer. Depending on the Sync fields settings above, their local name and/or description will be replaced with the remote values — the originals are backed up and restored automatically if delegation ends. Inherited external links can optionally be kept as local links when that happens.', 'termconnect' ); ?></p>
                <label style="display:block;margin-top:8px">
                    <input type="checkbox" id="tconn-sv-conflict-confirm">
                    <?php esc_html_e( 'I understand and want to proceed.', 'termconnect' ); ?>
                </label>
            </div>
            <div style="margin-top:16px;display:flex;gap:8px;align-items:center">
                <button type="button" class="button button-primary tconn-sv-save-btn"><?php esc_html_e( 'Save source', 'termconnect' ); ?></button>
                <button type="button" class="button tconn-sv-cancel-btn"><?php esc_html_e( 'Cancel', 'termconnect' ); ?></button>
                <span class="tconn-sv-form-status description"></span>
            </div>
        </div>

        <!-- Merge proposals -->
        <?php if ( ! empty( $merge_proposals ) ) : ?>
        <div id="tconn-sv-merge-section" style="margin:16px 0 24px">
            <h2 style="font-size:1.1em;margin-bottom:6px"><?php esc_html_e( 'Merge Proposals', 'termconnect' ); ?></h2>
            <p class="description" style="margin-bottom:10px"><?php esc_html_e( 'These orphaned terms appear to be the same concept as a currently mirrored term, matched by a shared external identifier. Merging reassigns posts to the new term and creates an archive redirect from the old slug. Requires HyperTaxonomy.', 'termconnect' ); ?></p>
            <table class="wp-list-table widefat fixed striped" id="tconn-sv-merge-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Orphaned term', 'termconnect' ); ?></th>
                        <th><?php esc_html_e( 'Replace with', 'termconnect' ); ?></th>
                        <th><?php esc_html_e( 'Matched by', 'termconnect' ); ?></th>
                        <th><?php esc_html_e( 'Detected', 'termconnect' ); ?></th>
                        <th style="width:160px"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $merge_proposals as $proposal_id => $proposal ) :
                    $orphan    = get_term( (int) $proposal['orphan_term_id'] );
                    $candidate = get_term( (int) $proposal['candidate_term_id'] );
                    if ( ! $orphan || is_wp_error( $orphan ) || ! $candidate || is_wp_error( $candidate ) ) { continue; }
                ?>
                    <tr data-proposal-id="<?php echo esc_attr( $proposal_id ); ?>">
                        <td><strong><?php echo esc_html( $proposal['orphan_name'] ); ?></strong><br><code><?php echo esc_html( $proposal['orphan_slug'] ); ?></code></td>
                        <td><strong><?php echo esc_html( $proposal['candidate_name'] ); ?></strong><br><code><?php echo esc_html( $proposal['candidate_slug'] ); ?></code></td>
                        <td><code><?php echo esc_html( $proposal['match_reason'] ); ?></code></td>
                        <td><?php echo esc_html( human_time_diff( (int) $proposal['detected_at'] ) . ' ' . __( 'ago', 'termconnect' ) ); ?></td>
                        <td style="white-space:nowrap">
                            <button type="button" class="button button-small tconn-sv-merge-btn"
                                    data-proposal-id="<?php echo esc_attr( $proposal_id ); ?>"
                                    data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Merge', 'termconnect' ); ?></button>
                            <button type="button" class="button button-small tconn-sv-dismiss-merge"
                                    data-proposal-id="<?php echo esc_attr( $proposal_id ); ?>"
                                    data-nonce="<?php echo esc_attr( $nonce ); ?>"
                                    style="margin-left:4px"><?php esc_html_e( 'Dismiss', 'termconnect' ); ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Priority explanation -->
        <p class="description" style="margin:12px 0 4px">
            <?php
            $priority_note = __( 'Tabs are ordered by priority — if the same term is covered by rows in multiple tabs, the earlier (broader-scoped) tab wins. Within a tab, rows are checked top-to-bottom — the first matching row wins. Drag rows up or down to change their order.', 'termconnect' );
            if ( $ht_active ) {
                echo wp_kses(
                    sprintf(
                        /* translators: each %s is a bold tab name — 1: Taxonomies, 2: Remote Groups, 3: Local Groups, 4: Term Hierarchies, 5: Individual Terms */
                        __( 'Each row defines a remote site to mirror terms from. Rows in the %1$s tab mirror a full remote taxonomy; %2$s rows fetch a remote HyperTaxonomy group and mirror its members locally; %3$s rows delegate local group members to a peer site by slug; %4$s rows mirror selected terms and branches of their descendants; %5$s rows mirror just selected terms.', 'termconnect' ),
                        '<strong>' . esc_html__( 'Taxonomies', 'termconnect' ) . '</strong>',
                        '<strong>' . esc_html__( 'Remote Groups', 'termconnect' ) . '</strong>',
                        '<strong>' . esc_html__( 'Local Groups', 'termconnect' ) . '</strong>',
                        '<strong>' . esc_html__( 'Term Hierarchies', 'termconnect' ) . '</strong>',
                        '<strong>' . esc_html__( 'Individual Terms', 'termconnect' ) . '</strong>'
                    ),
                    array( 'strong' => array() )
                );
                echo ' ' . esc_html( $priority_note );
            } else {
                echo wp_kses(
                    sprintf(
                        /* translators: each %s is a bold tab name — 1: Taxonomies, 2: Term Hierarchies, 3: Individual Terms */
                        __( 'Each row defines a remote site and taxonomy to mirror terms from. Rows in the %1$s tab mirror a full remote taxonomy; %2$s rows mirror selected terms and branches of their descendants; %3$s rows mirror just selected terms.', 'termconnect' ),
                        '<strong>' . esc_html__( 'Taxonomies', 'termconnect' ) . '</strong>',
                        '<strong>' . esc_html__( 'Term Hierarchies', 'termconnect' ) . '</strong>',
                        '<strong>' . esc_html__( 'Individual Terms', 'termconnect' ) . '</strong>'
                    ),
                    array( 'strong' => array() )
                );
                echo ' ' . esc_html( $priority_note );
            }
            ?>
        </p>

        <!-- Scope tabs -->
        <nav class="nav-tab-wrapper" id="tconn-sv-tabs" style="margin-top:8px">
            <?php
            $first_tab = true;
            foreach ( $tab_scopes as $scope_key ) :
                $count = count( $by_scope[ $scope_key ] );
            ?>
            <a href="#" class="nav-tab<?php echo $first_tab ? ' nav-tab-active' : ''; ?>"
               data-scope="<?php echo esc_attr( $scope_key ); ?>">
                <?php echo esc_html( $scope_meta[ $scope_key ]['label'] ); ?>
                <?php if ( $count ) : ?>
                <span style="margin-left:4px;background:#72aee6;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px"><?php echo esc_html( (string) $count ); ?></span>
                <?php endif; ?>
            </a>
            <?php $first_tab = false; endforeach; ?>
        </nav>

        <!-- Scope panels -->
        <?php
        $first_panel = true;
        foreach ( $tab_scopes as $scope_key ) :
            $panel_sources = $by_scope[ $scope_key ];
        ?>
        <div id="tconn-sv-panel-<?php echo esc_attr( $scope_key ); ?>"
             class="tconn-sv-panel"
             style="background:transparent;border:1px solid #c3c4c7;border-top:none;border-radius:0 0 4px 4px;padding:16px;<?php echo $first_panel ? '' : 'display:none'; ?>">
            <p class="description" style="margin:0 0 12px"><?php echo esc_html( $scope_meta[ $scope_key ]['desc'] ); ?></p>
            <button type="button" class="button tconn-sv-add-btn"
                    data-scope="<?php echo esc_attr( $scope_key ); ?>"
                    style="margin-bottom:12px">
                <?php esc_html_e( '+ Add source', 'termconnect' ); ?>
            </button>
            <?php if ( ! empty( $panel_sources ) ) : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:26px"></th>
                        <th><?php esc_html_e( 'Source', 'termconnect' ); ?></th>
                        <th><?php esc_html_e( 'Detail', 'termconnect' ); ?></th>
                        <th><?php esc_html_e( 'Last synced', 'termconnect' ); ?></th>
                        <th style="width:220px"><?php esc_html_e( 'Actions', 'termconnect' ); ?></th>
                    </tr>
                </thead>
                <tbody id="tconn-sv-tbody-<?php echo esc_attr( $scope_key ); ?>">
                <?php foreach ( $panel_sources as $src ) :
                    $peer_site       = tconn_get_link_type( $src['peer_site_slug'] );
                    $peer_site_label = $peer_site ? $peer_site['label'] : $src['peer_site_slug'];
                    $last_sync = $src['last_sync']
                        ? human_time_diff( (int) $src['last_sync'] ) . ' ' . __( 'ago', 'termconnect' )
                        : __( 'Never', 'termconnect' );

                    if ( 'remote_group' === $src['scope'] ) {
                        $detail = esc_html( $src['remote_group_name'] ?? ( $src['remote_group_slug'] ?? '' ) );
                    } elseif ( 'local_group' === $src['scope'] ) {
                        $detail = esc_html( $src['group_name'] ?? '' );
                    } elseif ( 'full' === $src['scope'] ) {
                        $detail = esc_html( $src['taxonomy'] );
                    } else {
                        $term_count = count( $src['roots'] ?? array() );
                        $detail     = esc_html( $src['taxonomy'] )
                            . ' <span class="description">('
                            /* translators: %d: number of terms */
                            . esc_html( sprintf( _n( '%d term', '%d terms', $term_count, 'termconnect' ), $term_count ) )
                            . ')</span>';
                    }
                ?>
                <tr data-id="<?php echo esc_attr( $src['id'] ); ?>">
                    <td><span class="tconn-sv-drag-handle" title="<?php esc_attr_e( 'Drag to reorder', 'termconnect' ); ?>">&#8942;</span></td>
                    <td>
                        <?php if ( ! empty( $src['sv_name'] ) ) : ?>
                        <strong><?php echo esc_html( $src['sv_name'] ); ?></strong>
                        <div class="description" style="font-size:12px"><?php echo esc_html( $peer_site_label ); ?></div>
                        <?php else : ?>
                        <strong><?php echo esc_html( $peer_site_label ); ?></strong>
                        <?php endif; ?>
                    </td>
                    <td><?php echo wp_kses( $detail, array( 'span' => array( 'class' => array() ) ) ); ?></td>
                    <td class="description" style="font-size:12px"><?php echo esc_html( $last_sync ); ?></td>
                    <td style="white-space:nowrap">
                        <button type="button" class="button button-small tconn-sv-sync-now"
                                data-id="<?php echo esc_attr( $src['id'] ); ?>"
                                data-nonce="<?php echo esc_attr( $nonce ); ?>"><?php esc_html_e( 'Sync now', 'termconnect' ); ?></button>
                        <button type="button" class="button button-small tconn-sv-edit-btn"
                                data-source="<?php echo esc_attr( wp_json_encode( $src ) ); ?>"><?php esc_html_e( 'Edit', 'termconnect' ); ?></button>
                        <button type="button" class="button button-small tconn-sv-delete-btn"
                                data-id="<?php echo esc_attr( $src['id'] ); ?>"
                                data-nonce="<?php echo esc_attr( $nonce ); ?>"
                                style="color:#d63638"><?php esc_html_e( 'Remove', 'termconnect' ); ?></button>
                        <?php if ( ! empty( $src['last_sync_result'] ) ) : ?>
                        <div class="tconn-sv-sync-result description" style="white-space:normal;font-size:11px;margin-top:3px">&#10003; <?php echo esc_html( $src['last_sync_result'] ); ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
            <p class="description"><?php esc_html_e( 'No sources configured for this scope yet.', 'termconnect' ); ?></p>
            <?php endif; ?>
        </div>
        <?php $first_panel = false; endforeach; ?>
    </div>

    <?php ob_start(); ?>
    jQuery(function($){
        var nonce = <?php echo wp_json_encode( $nonce ); ?>;
        var existingSources = <?php echo wp_json_encode( array_values( $sources ) ); ?>;
        var tconnPeerSites     = <?php echo wp_json_encode( $peer_sites ); ?>;
        var tconnSvI18n           = {
            coverageWarning:          <?php /* translators: Keep %scope% and %peer% as they are: they are replaced with the source type and the peer site name */ echo wp_json_encode( __( 'This term is already covered by a broader %scope% source from %peer%, so it will be skipped during sync.', 'termconnect' ) ); ?>,
            coverageWarningSameScope: <?php /* translators: Keep %scope% and %peer% as they are: they are replaced with the source type and the peer site name */ echo wp_json_encode( __( 'This term is already in another %scope% source from %peer%, so it will be skipped during sync.', 'termconnect' ) ); ?>,
            scopeLabels: <?php echo wp_json_encode( array(
                'full'         => __( 'Taxonomies', 'termconnect' ),
                'remote_group' => __( 'Remote Groups', 'termconnect' ),
                'local_group'  => __( 'Local Groups', 'termconnect' ),
                'roots'        => __( 'Term Hierarchies', 'termconnect' ),
                'terms'        => __( 'Individual Terms', 'termconnect' ),
            ) ); ?>,
        };
        var tconnSvEditOriginalRoots = null;
        var $form = $('#tconn-sv-form');
        var hierarchicalTaxonomies = <?php echo wp_json_encode( array_values( get_taxonomies( array( 'hierarchical' => true ) ) ) ); ?>;
        var formDirty = false;

        // Track any user edits inside the form.
        $form.on('change input', 'input, select, textarea', function(){
            formDirty = true;
        });

        // ---- TABS ----
        function activateTab(scope) {
            var $tab = $('#tconn-sv-tabs a[data-scope="' + scope + '"]');
            if (!$tab.length) return;
            $('#tconn-sv-tabs .nav-tab').removeClass('nav-tab-active');
            $tab.addClass('nav-tab-active');
            $('.tconn-sv-panel').hide();
            $('#tconn-sv-panel-' + scope).show();
        }
        function activeTabScope() {
            return $('#tconn-sv-tabs .nav-tab-active').data('scope') || '';
        }
        function reloadToCurrentTab() {
            var params = new URLSearchParams(window.location.search);
            params.set('scope', activeTabScope());
            location.href = location.pathname + '?' + params.toString();
        }

        $('#tconn-sv-tabs').on('click', 'a.nav-tab', function(e){
            e.preventDefault();
            var scope = $(this).data('scope');
            activateTab(scope);
            var params = new URLSearchParams(window.location.search);
            params.set('scope', scope);
            history.replaceState(null, '', location.pathname + '?' + params.toString());
        });

        // Activate tab from URL param (e.g. from term edit "Manage in Shared Vocabularies" link).
        (function(){
            var params = new URLSearchParams(window.location.search);
            var tab    = params.get('scope');
            if (tab) { activateTab(tab); }
        })();

        // ---- DRAG-DROP REORDER (per tab) ----
        <?php foreach ( $tab_scopes as $sortable_scope ) : ?>
        (function(){
            var $tbody = $('#tconn-sv-tbody-<?php echo esc_attr( $sortable_scope ); ?>');
            if (!$tbody.length) return;
            $tbody.sortable({
                handle: '.tconn-sv-drag-handle',
                placeholder: 'tconn-sv-drag-placeholder',
                forcePlaceholderSize: true,
                helper: function(e, ui) {
                    ui.children().each(function(){ $(this).width($(this).width()); });
                    return ui;
                },
                stop: function(){
                    var ids = [];
                    $tbody.find('tr').each(function(){ ids.push($(this).data('id')); });
                    $.post(ajaxurl, { action:'tconn_sv_reorder', nonce:nonce, scope:<?php echo wp_json_encode( $sortable_scope ); ?>, ids:ids });
                }
            });
        })();
        <?php endforeach; ?>

        // ---- FORM CONFIG PER SCOPE ----
        function configureFormForScope(scope, skipTaxLoad) {
            $('#tconn-sv-active-scope').val(scope);
            var isGroup       = (scope === 'local_group');
            var isRemoteGroup = (scope === 'remote_group');
            var isRoots       = (scope === 'roots');
            var isTerms       = (scope === 'terms');
            var isFull        = (scope === 'full');
            var taxHidden     = isGroup || isRemoteGroup;
            $('#tconn-sv-row-taxonomy').toggle(!taxHidden);
            $('#tconn-sv-row-roots').toggle(isRoots || isTerms);
            $('#tconn-sv-row-group').toggle(isGroup);
            $('#tconn-sv-row-remote-group').toggle(isRemoteGroup);
            $('#tconn-sv-row-misplace').toggle(isFull || isRoots || isRemoteGroup);
            $('#tconn-sv-row-rel-delegation').toggle(isFull);
            if (!isFull) {
                $('#tconn-sv-rel-delegation').prop('checked', false);
                $('#tconn-sv-cross-taxonomy').prop('checked', false);
                $('#tconn-sv-cross-taxonomy-row').hide();
            }
            $('#tconn-sv-roots-label').text(isTerms
                ? <?php echo wp_json_encode( __( 'Individual terms', 'termconnect' ) ); ?>
                : <?php echo wp_json_encode( __( 'Root terms', 'termconnect' ) ); ?>);
            // Load groups hierarchy when local group scope selected.
            if (isGroup) { loadLocalGroups(); }
            // Reload taxonomy/group dropdowns when scope changes and peer already selected (unless caller handles it).
            if (!skipTaxLoad) {
                var peerSiteSlug = $('#tconn-sv-peer').val();
                if (!taxHidden && peerSiteSlug) {
                    loadTaxonomiesForPeer(peerSiteSlug, isRoots);
                }
                if (isRemoteGroup && peerSiteSlug) {
                    loadRemoteGroupsForPeer(peerSiteSlug);
                }
            }
        }

        $(document).on('change', '#tconn-sv-rel-delegation', function(){
            $('#tconn-sv-cross-taxonomy-row').toggle($(this).is(':checked'));
            if (!$(this).is(':checked')) { $('#tconn-sv-cross-taxonomy').prop('checked', false); }
        });

        function openForm(scope) {
            $('#tconn-sv-form-title').text(<?php echo wp_json_encode( __( 'Add Vocabulary Source', 'termconnect' ) ); ?>);
            $('#tconn-sv-edit-id').val('');
            $('#tconn-sv-peer').val('');
            $form.find('input[type=text]').val('');
            $('#tconn-sv-description').val('');
            $form.find('input[name=tconn-sv-misplace][value=flag]').prop('checked', true);
            $form.find('input[name=tconn-sv-orphan-action][value=flag]').prop('checked', true);
            $('#tconn-sv-interval').val('daily');
            $('#tconn-sv-sync-name').prop('checked', true);
            $('#tconn-sv-sync-description').prop('checked', true);
            $('#tconn-sv-sync-links-label').hide();
            $('#tconn-sv-roots-list .tconn-link-pill').remove();
            $('#tconn-sv-coverage-warning').hide();
            $('#tconn-sv-term-search').val('').prop('disabled', true);
            $('#tconn-sv-remote-group').val('').prop('disabled', true).data('loaded-for', '');
            $('#tconn-sv-term-suggestions').hide().empty();
            $('#tconn-sv-taxonomy').empty()
                .append($('<option>').val('').text(<?php echo wp_json_encode( __( 'Select peer site first', 'termconnect' ) ); ?>))
                .prop('disabled', true).data('loaded-for', '');
            $('#tconn-sv-conflict-area').hide();
            configureFormForScope(scope);
            $('#tconn-sv-form-backdrop').show();
            $form.show();
            formDirty = false;
        }

        function closeForm() {
            if (formDirty && !window.confirm(<?php echo wp_json_encode( __( 'You have unsaved changes. Leave without saving?', 'termconnect' ) ); ?>)) {
                return;
            }
            formDirty = false;
            $form.hide();
            $('#tconn-sv-form-backdrop').hide();
        }

        // ---- ADD SOURCE (scope from tab button) ----
        $(document).on('click', '.tconn-sv-add-btn', function(){
            openForm($(this).data('scope') || 'full');
        });

        $('.tconn-sv-cancel-btn').on('click', closeForm);
        $('#tconn-sv-form-backdrop').on('click', closeForm);

        // Esc key dismisses the form (with dirty check).
        $(document).on('keydown', function(e){
            if (27 === e.which && $form.is(':visible')) {
                closeForm();
            }
        });

        // ---- TAXONOMY DROPDOWN: populate from peer ----
        var tconnSvTaxCache   = {};
        var tconnSvTaxLoading = {}; // cacheKey -> pending selectAfterLoad (deduplicates concurrent calls)
        function loadTaxonomiesForPeer(peerSiteSlug, filterHierarchical, selectAfterLoad) {
            var $sel = $('#tconn-sv-taxonomy');
            var placeholder = filterHierarchical
                ? <?php echo wp_json_encode( __( '— Select hierarchical taxonomy —', 'termconnect' ) ); ?>
                : <?php echo wp_json_encode( __( '— Select taxonomy —', 'termconnect' ) ); ?>;
            if (!peerSiteSlug) {
                $sel.empty().append($('<option>').val('').text(placeholder)).prop('disabled', true).data('loaded-for', '');
                $('#tconn-sv-term-search').val('').prop('disabled', true);
                return;
            }
            var cacheKey = peerSiteSlug + (filterHierarchical ? ':h' : ':a');
            // If an AJAX request for this key is already in-flight, update the pending
            // selectAfterLoad (last truthy value wins) and bail — one request is enough.
            if (tconnSvTaxLoading.hasOwnProperty(cacheKey)) {
                if (selectAfterLoad) { tconnSvTaxLoading[cacheKey] = selectAfterLoad; }
                return;
            }
            $sel.empty().append($('<option>').val('').text(placeholder)).prop('disabled', true);
            $('#tconn-sv-term-search').val('').prop('disabled', true);
            if (tconnSvTaxCache[cacheKey]) {
                fillTaxSelect($sel, tconnSvTaxCache[cacheKey], filterHierarchical, placeholder, peerSiteSlug, selectAfterLoad);
                return;
            }
            tconnSvTaxLoading[cacheKey] = selectAfterLoad;
            $('#tconn-sv-taxonomy-loading').show();
            $('.tconn-sv-save-btn').prop('disabled', true);
            $.post(ajaxurl, {action:'tconn_sv_fetch_remote_taxonomies', nonce:nonce, peer_site_slug:peerSiteSlug})
             .done(function(resp){
                var pendingSelect = tconnSvTaxLoading[cacheKey];
                delete tconnSvTaxLoading[cacheKey];
                if (resp.success) {
                    tconnSvTaxCache[cacheKey] = resp.data;
                    fillTaxSelect($sel, resp.data, filterHierarchical, placeholder, peerSiteSlug, pendingSelect);
                } else {
                    $sel.empty().append($('<option>').val('').text(<?php echo wp_json_encode( __( 'Could not load taxonomies', 'termconnect' ) ); ?>));
                }
             })
             .fail(function(){
                delete tconnSvTaxLoading[cacheKey];
                $sel.empty().append($('<option>').val('').text(<?php echo wp_json_encode( __( 'Could not load taxonomies', 'termconnect' ) ); ?>));
             })
             .always(function(){ $('#tconn-sv-taxonomy-loading').hide(); $('.tconn-sv-save-btn').prop('disabled', false); });
        }
        var tconnSvUtilityTaxonomies = {nav_menu:1, wp_pattern_category:1, link_category:1, post_format:1};
        function fillTaxSelect($sel, taxonomies, filterHierarchical, placeholder, peerSiteSlug, selectAfterLoad) {
            $sel.empty().append($('<option>').val('').text(placeholder));
            var peerRunsTC = taxonomies.some(function(t){ return t.show_ui !== null; });
            taxonomies.forEach(function(t){
                if (t.show_ui === false) return;
                if (t.tconn_content === false) return;                           // TC peer: non-content menu
                if (t.tconn_content === null && tconnSvUtilityTaxonomies[t.slug]) return; // non-TC fallback
                if (filterHierarchical && !t.hierarchical) return;
                $sel.append($('<option>').val(t.slug).text(t.name));
            });
            $('#tconn-sv-sync-links-label').toggle(peerRunsTC);
            $sel.prop('disabled', false).data('loaded-for', peerSiteSlug || '');
            if (selectAfterLoad) {
                $sel.val(selectAfterLoad);
                $('#tconn-sv-term-search').prop('disabled', !$sel.val());
            }
            recheckAllPillWarnings();
        }
        $('#tconn-sv-peer').on('change', function(){
            var scope = $('#tconn-sv-active-scope').val();
            if (scope !== 'local_group' && scope !== 'remote_group') {
                loadTaxonomiesForPeer($(this).val(), scope === 'roots');
            }
            if (scope === 'remote_group') {
                loadRemoteGroupsForPeer($(this).val());
            }
        });
        $('#tconn-sv-taxonomy').on('change', function(){
            var hasTax = !!$(this).val();
            $('#tconn-sv-term-search').prop('disabled', !hasTax);
            if (!hasTax) { $('#tconn-sv-term-suggestions').hide().empty(); }
        });

        // ---- GROUP DROPDOWN: populate with local hierarchy ----
        var tconnSvGroupsLoaded = false;
        function loadLocalGroups() {
            if (tconnSvGroupsLoaded) return;
            var $sel = $('#tconn-sv-group-id');
            $.post(ajaxurl, {action:'tconn_sv_fetch_local_groups', nonce:nonce})
             .done(function(resp){
                if (!resp.success || !resp.data.length) { $('#tconn-sv-group-id').empty().append($('<option>').val('').text('— No groups found —')); return; }
                tconnSvGroupsLoaded = true;
                var groups = resp.data;
                $sel.empty().append($('<option>').val('').text(<?php echo wp_json_encode( __( '— Select group —', 'termconnect' ) ); ?>));
                // Build tree.
                var byId = {}, roots = [];
                groups.forEach(function(g){ byId[g.id] = g; g.children = []; });
                groups.forEach(function(g){
                    if (g.parent && byId[g.parent]) { byId[g.parent].children.push(g); }
                    else { roots.push(g); }
                });
                function addOptions(nodes, depth) {
                    nodes.forEach(function(g){
                        var prefix = depth > 0 ? Array(depth * 2 + 1).join(' ') + '└ ' : '';
                        $sel.append($('<option>').val(g.id).text(prefix + g.name));
                        if (g.children.length) addOptions(g.children, depth + 1);
                    });
                }
                addOptions(roots, 0);
             });
        }

        // ---- REMOTE GROUP DROPDOWN: populate from peer (mirrors loadTaxonomiesForPeer pattern) ----
        var tconnSvRemoteGroupCache   = {};
        var tconnSvRemoteGroupLoading = {}; // peerSiteSlug -> pending selectAfterLoad
        function loadRemoteGroupsForPeer(peerSiteSlug, selectAfterLoad) {
            var $sel         = $('#tconn-sv-remote-group');
            var placeholder  = <?php echo wp_json_encode( __( '— Select group —', 'termconnect' ) ); ?>;
            var placeholderFirst = <?php echo wp_json_encode( __( '— Select peer site first —', 'termconnect' ) ); ?>;
            if (!peerSiteSlug) {
                $sel.empty().append($('<option>').val('').text(placeholderFirst))
                    .prop('disabled', true).data('loaded-for', '');
                return;
            }
            if (tconnSvRemoteGroupLoading.hasOwnProperty(peerSiteSlug)) {
                if (selectAfterLoad) { tconnSvRemoteGroupLoading[peerSiteSlug] = selectAfterLoad; }
                return;
            }
            $sel.empty().append($('<option>').val('').text(placeholder)).prop('disabled', true);
            if (tconnSvRemoteGroupCache[peerSiteSlug] && $sel.data('loaded-for') === peerSiteSlug) {
                fillRemoteGroupSelect($sel, tconnSvRemoteGroupCache[peerSiteSlug], placeholder, peerSiteSlug, selectAfterLoad);
                return;
            }
            tconnSvRemoteGroupLoading[peerSiteSlug] = selectAfterLoad || '';
            $('#tconn-sv-remote-group-loading').show();
            $('.tconn-sv-save-btn').prop('disabled', true);
            $.post(ajaxurl, {action:'tconn_sv_fetch_remote_groups', nonce:nonce, peer_site_slug:peerSiteSlug})
             .done(function(resp){
                var pendingSelect = tconnSvRemoteGroupLoading[peerSiteSlug];
                delete tconnSvRemoteGroupLoading[peerSiteSlug];
                if (resp.success) {
                    tconnSvRemoteGroupCache[peerSiteSlug] = resp.data;
                    fillRemoteGroupSelect($sel, resp.data, placeholder, peerSiteSlug, pendingSelect);
                } else {
                    $sel.empty().append($('<option>').val('').text(<?php echo wp_json_encode( __( 'Could not load groups', 'termconnect' ) ); ?>));
                }
             })
             .fail(function(){
                delete tconnSvRemoteGroupLoading[peerSiteSlug];
                $sel.empty().append($('<option>').val('').text(<?php echo wp_json_encode( __( 'Could not load groups', 'termconnect' ) ); ?>));
             })
             .always(function(){ $('#tconn-sv-remote-group-loading').hide(); $('.tconn-sv-save-btn').prop('disabled', false); });
        }
        function fillRemoteGroupSelect($sel, groups, placeholder, peerSiteSlug, selectAfterLoad) {
            $sel.empty().append($('<option>').val('').text(placeholder));
            var byId = {}, roots = [];
            groups.forEach(function(g){ byId[g.id] = g; g.children = []; });
            groups.forEach(function(g){
                if (g.parent && byId[g.parent]) { byId[g.parent].children.push(g); }
                else { roots.push(g); }
            });
            function addOptions(nodes, depth) {
                nodes.forEach(function(g){
                    var prefix = depth > 0 ? Array(depth * 2 + 1).join(' ') + '└ ' : '';
                    $sel.append(
                        $('<option>').val(g.slug)
                            .attr('data-group-id',   g.id)
                            .attr('data-group-name', g.name)
                            .text(prefix + g.name)
                    );
                    if (g.children.length) { addOptions(g.children, depth + 1); }
                });
            }
            addOptions(roots, 0);
            $sel.prop('disabled', false).data('loaded-for', peerSiteSlug || '');
            if (selectAfterLoad) { $sel.val(selectAfterLoad); }
        }

        // ---- TERM SEARCH AUTOCOMPLETE (roots/terms scope) ----
        var tconnSvTermTimer = null;
        $(document).on('input', '#tconn-sv-term-search', function(){
            var q = $(this).val().trim();
            var $ul = $('#tconn-sv-term-suggestions').hide().empty();
            clearTimeout(tconnSvTermTimer);
            if (q.length < 2) return;
            var peerSiteSlug  = $('#tconn-sv-peer').val();
            var taxonomy = $('#tconn-sv-taxonomy').val();
            if (!peerSiteSlug || !taxonomy) return;
            tconnSvTermTimer = setTimeout(function(){
                $('#tconn-sv-term-search').addClass('tconn-sv-term-searching');
                $.post(ajaxurl, {action:'tconn_sv_search_remote_terms', nonce:nonce, peer_site_slug:peerSiteSlug, taxonomy:taxonomy, q:q})
                 .always(function(){ $('#tconn-sv-term-search').removeClass('tconn-sv-term-searching'); })
                 .done(function(resp){
                    if (!resp.success || !resp.data.length) return;
                    var seen = {};
                    var selected = {};
                    $('#tconn-sv-roots-list .tconn-link-pill').each(function(){ selected[$(this).data('id')] = true; });
                    resp.data.forEach(function(t){
                        if (seen[t.id] || selected[t.id]) return;
                        seen[t.id] = true;
                        $('<li>').text(t.name)
                            .css({display:'block', margin:0, padding:'6px 10px', cursor:'pointer', listStyle:'none', fontSize:'13px', lineHeight:'18px', color:'#32373c'})
                            .hover(function(){ $(this).css({background:'#0073aa', color:'#fff'}); }, function(){ $(this).css({background:'', color:'#32373c'}); })
                            .on('click', function(){
                                addRootPill(t.id, t.slug, t.name);
                                $('#tconn-sv-term-search').val('');
                                $ul.hide().empty();
                            })
                            .appendTo($ul);
                    });
                    $ul.show();
                    $ul.css('overflowY', $ul[0].scrollHeight > 200 ? 'auto' : 'hidden');
                 });
            }, 300);
        });
        $(document).on('keydown', '#tconn-sv-term-search', function(e){
            var $container = $('#tconn-sv-roots-list');
            var $pills     = $container.find('.tconn-link-pill');
            var val        = $(this).val();
            if (e.which === 8 && val === '' && $pills.length) {          // Backspace
                var $last = $pills.last();
                if ($last.hasClass('selected')) { $last.find('.tconn-link-pill-remove').trigger('click'); }
                else { $pills.removeClass('selected'); $last.addClass('selected'); }
                e.preventDefault();
            } else if (e.which === 37 && val === '' && $pills.length) {  // Left
                var $sel = $container.find('.tconn-link-pill.selected');
                if (!$sel.length) { $pills.last().addClass('selected'); }
                else {
                    var $prev = $sel.prev('.tconn-link-pill');
                    if ($prev.length) { $sel.removeClass('selected'); $prev.addClass('selected'); }
                }
                e.preventDefault();
            } else if (e.which === 39) {                                  // Right
                var $selR = $container.find('.tconn-link-pill.selected');
                if ($selR.length) {
                    var $next = $selR.next('.tconn-link-pill');
                    if ($next.length) { $selR.removeClass('selected'); $next.addClass('selected'); }
                    else { $selR.removeClass('selected'); }
                    e.preventDefault();
                }
            } else if (e.which === 46) {                                  // Delete
                var $selD = $container.find('.tconn-link-pill.selected');
                if ($selD.length) { $selD.find('.tconn-link-pill-remove').trigger('click'); e.preventDefault(); }
            } else {
                $pills.removeClass('selected');
            }
        });
        $(document).on('click', function(e){
            if (!$(e.target).closest('#tconn-sv-row-roots').length) { $('#tconn-sv-term-suggestions').hide(); }
        });

        function updateCoverageWarning() {
            $('#tconn-sv-coverage-warning').toggle(!!$('#tconn-sv-roots-list .tconn-sv-pill-warn').length);
        }

        function applyPillWarning($pill, termId) {
            $pill.find('.tconn-sv-pill-warn').remove();
            var scope  = $('#tconn-sv-active-scope').val();
            var editId = $('#tconn-sv-edit-id').val();
            if (scope !== 'roots' && scope !== 'terms') return;
            var peer = $('#tconn-sv-peer').val();
            var tax  = $('#tconn-sv-taxonomy').val();
            if (!peer || !tax) return;
            // For same-scope checks, only sources that appear before the current one have
            // higher priority. New sources are appended at the end, so all existing qualify.
            var currentIndex = editId
                ? (function(){ for (var i=0;i<existingSources.length;i++){ if (String(existingSources[i].id)===String(editId)) return i; } return existingSources.length; }())
                : existingSources.length;
            var coveringSource = existingSources.filter(function(s){
                return s.scope === 'full' && s.peer_site_slug === peer && s.taxonomy === tax && String(s.id) !== String(editId);
            })[0];
            if (!coveringSource) {
                coveringSource = existingSources.filter(function(s, i){
                    return i < currentIndex
                        && s.scope === scope && s.peer_site_slug === peer && s.taxonomy === tax
                        && s.roots && s.roots.some(function(r){ return String(r.remote_term_id) === String(termId); });
                })[0];
            }
            if (coveringSource) {
                var peerLabel  = (tconnPeerSites[coveringSource.peer_site_slug] || {}).label || coveringSource.peer_site_slug;
                var scopeLabel = tconnSvI18n.scopeLabels[coveringSource.scope] || coveringSource.scope;
                var tip = (coveringSource.scope === 'full' ? tconnSvI18n.coverageWarning : tconnSvI18n.coverageWarningSameScope)
                    .replace('%scope%', scopeLabel).replace('%peer%', peerLabel);
                $('<span class="tconn-sv-pill-warn" title="'+tip+'">&#9888;</span>').prependTo($pill);
            }
        }

        function recheckAllPillWarnings() {
            $('#tconn-sv-roots-list .tconn-link-pill').each(function(){
                applyPillWarning($(this), $(this).data('id'));
            });
            updateCoverageWarning();
        }

        function addRootPill(termId, slug, name){
            if ($('#tconn-sv-roots-list [data-id="'+termId+'"]').length) return;
            var $pill = $('<span class="tconn-link-pill" style="cursor:default">').text(name || slug);
            $pill.attr({'data-id':termId, 'data-slug':slug, 'data-name':name});
            applyPillWarning($pill, termId);
            $('<button type="button" class="tconn-link-pill-remove">&times;</button>').appendTo($pill).on('click', function(){
                $pill.remove();
                updateCoverageWarning();
            });
            $pill.insertBefore($('#tconn-sv-term-search'));
            updateCoverageWarning();
        }

        // ---- SAVE ----
        $('.tconn-sv-save-btn').on('click', function(){
            var scope = $('#tconn-sv-active-scope').val();
            var roots = [];
            if ('roots' === scope || 'terms' === scope){
                $('#tconn-sv-roots-list .tconn-link-pill').each(function(){
                    roots.push({remote_term_id:parseInt($(this).data('id')), remote_slug:$(this).data('slug'), remote_name:$(this).data('name')||''});
                });
                if (!roots.length){ alert(<?php echo wp_json_encode( __( 'Add at least one term.', 'termconnect' ) ); ?>); return; }
            }
            if ('local_group' === scope && !$('#tconn-sv-group-id').val()){
                alert(<?php echo wp_json_encode( __( 'Select a HyperTaxonomy group.', 'termconnect' ) ); ?>); return;
            }
            if ('remote_group' === scope && !$('#tconn-sv-remote-group').val()){
                alert(<?php echo wp_json_encode( __( 'Select a remote group.', 'termconnect' ) ); ?>); return;
            }
            var taxonomyOptional = (scope === 'local_group' || scope === 'remote_group');
            var payload = {
                action:             'tconn_sv_save',
                nonce:              nonce,
                id:                 $('#tconn-sv-edit-id').val(),
                sv_name:            $('#tconn-sv-name').val().trim(),
                sv_description:     $('#tconn-sv-description').val().trim(),
                peer_site_slug:            $('#tconn-sv-peer').val(),
                taxonomy:           taxonomyOptional ? '' : $('#tconn-sv-taxonomy').val().trim(),
                scope:              scope,
                roots:              JSON.stringify(roots),
                group_id:           ('local_group' === scope) ? parseInt($('#tconn-sv-group-id').val()||0) : 0,
                group_name:         ('local_group' === scope) ? $('#tconn-sv-group-id option:selected').text().replace(/^[\s└─]+/, '') : '',
                remote_group_slug:  ('remote_group' === scope) ? $('#tconn-sv-remote-group').val() : '',
                remote_group_id:    ('remote_group' === scope) ? parseInt($('#tconn-sv-remote-group option:selected').attr('data-group-id')||0) : 0,
                remote_group_name:  ('remote_group' === scope) ? ($('#tconn-sv-remote-group option:selected').attr('data-group-name') || '') : '',
                sync_descendants:   ('roots' === scope) ? 1 : ('local_group' === scope && $('#tconn-sv-group-sync-desc').is(':checked')) ? 1 : 0,
                misplacement:              $('input[name=tconn-sv-misplace]:checked').val(),
                orphan_action:             $('input[name=tconn-sv-orphan-action]:checked').val(),
                sync_interval:             $('#tconn-sv-interval').val(),
                relationship_delegation:   ('full' === scope && $('#tconn-sv-rel-delegation').is(':checked')) ? 1 : 0,
                cross_taxonomy_relationships: ('full' === scope && $('#tconn-sv-cross-taxonomy').is(':checked')) ? 1 : 0,
                sync_name:                 $('#tconn-sv-sync-name').is(':checked') ? 1 : 0,
                sync_description:          $('#tconn-sv-sync-description').is(':checked') ? 1 : 0,
                conflict_confirmed:        $('#tconn-sv-conflict-confirm').is(':checked') ? 1 : 0,
            };
            if (!payload.peer_site_slug){ alert(<?php echo wp_json_encode( __( 'Select a peer site.', 'termconnect' ) ); ?>); return; }
            if (!taxonomyOptional && !payload.taxonomy){ alert(<?php echo wp_json_encode( __( 'Enter a taxonomy.', 'termconnect' ) ); ?>); return; }

            var $btn = $(this).prop('disabled', true);
            var $st  = $form.find('.tconn-sv-form-status').text(<?php echo wp_json_encode( __( 'Saving…', 'termconnect' ) ); ?>);

            function afterSave(id) {
                formDirty = false;
                closeForm();
                var triggerSync = !payload.id;
                if (!triggerSync && tconnSvEditOriginalRoots !== null) {
                    var currentRoots = JSON.stringify(
                        $('#tconn-sv-roots-list .tconn-link-pill').map(function(){ return parseInt($(this).data('id')); }).get().sort()
                    );
                    triggerSync = (currentRoots !== tconnSvEditOriginalRoots);
                }
                if (triggerSync) {
                    var params = new URLSearchParams(window.location.search);
                    params.set('scope', activeTabScope());
                    params.set('sync', id);
                    location.href = location.pathname + '?' + params.toString();
                } else {
                    reloadToCurrentTab();
                }
            }

            $.post(ajaxurl, payload).done(function(resp){
                if (resp.success){
                    if (resp.data && resp.data.scope_warning){
                        if (window.confirm(resp.data.scope_warning + '\n\n' + <?php echo wp_json_encode( __( 'Continue?', 'termconnect' ) ); ?>)){
                            payload.conflict_confirmed = 1;
                            $.post(ajaxurl, payload).done(function(r2){ if (r2.success){ afterSave(r2.data.id); } else { $st.text((r2.data && r2.data.message)||'Error.'); $btn.prop('disabled',false); } });
                        } else { $st.text(''); $btn.prop('disabled',false); }
                    } else if (resp.data && resp.data.conflicts && resp.data.conflicts.length){
                        $('#tconn-sv-conflict-area').show();
                        $st.text(<?php echo wp_json_encode( __( 'Review conflicts above, then save again.', 'termconnect' ) ); ?>);
                        $btn.prop('disabled',false);
                    } else { afterSave(resp.data.id); }
                } else {
                    $st.text((resp.data && resp.data.message) || <?php echo wp_json_encode( __( 'Error.', 'termconnect' ) ); ?>);
                    $btn.prop('disabled',false);
                }
            }).fail(function(){ $st.text(<?php echo wp_json_encode( __( 'Request failed.', 'termconnect' ) ); ?>); $btn.prop('disabled',false); });
        });

        // ---- EDIT ----
        $(document).on('click', '.tconn-sv-edit-btn', function(){
            var src = $(this).data('source');
            if (typeof src === 'string') src = JSON.parse(src);
            $('#tconn-sv-form-title').text(<?php echo wp_json_encode( __( 'Edit Vocabulary Source', 'termconnect' ) ); ?>);
            $('#tconn-sv-edit-id').val(src.id);
            $('#tconn-sv-name').val(src.sv_name || '');
            $('#tconn-sv-description').val(src.sv_description || '');
            $('#tconn-sv-active-scope').val(src.scope);
            $('#tconn-sv-peer').val(src.peer_site_slug);
            $('input[name=tconn-sv-misplace][value="'+(src.misplacement||'flag')+'"]').prop('checked',true);
            $('input[name=tconn-sv-orphan-action][value="'+(src.orphan_action||'flag')+'"]').prop('checked',true);
            $('#tconn-sv-interval').val(src.sync_interval || 'daily');
            $('#tconn-sv-sync-name').prop('checked', src.sync_name !== false && src.sync_name !== 0);
            $('#tconn-sv-sync-description').prop('checked', src.sync_description !== false && src.sync_description !== 0);
            $('#tconn-sv-roots-list .tconn-link-pill').remove();
            if (('roots' === src.scope || 'terms' === src.scope) && src.roots){
                src.roots.forEach(function(r){ addRootPill(r.remote_term_id, r.remote_slug, r.remote_name || r.remote_slug); });
            }
            if ('local_group' === src.scope){
                loadLocalGroups();
                // Select group after a short delay to let the dropdown populate.
                setTimeout(function(){ $('#tconn-sv-group-id').val(src.group_id || ''); }, 400);
                $('#tconn-sv-group-sync-desc').prop('checked', !!src.sync_descendants);
            }
            if ('remote_group' === src.scope && src.remote_group_slug && src.peer_site_slug) {
                loadRemoteGroupsForPeer(src.peer_site_slug, src.remote_group_slug);
            }
            if ('full' === src.scope) {
                $('#tconn-sv-rel-delegation').prop('checked', !!src.relationship_delegation);
                $('#tconn-sv-cross-taxonomy').prop('checked', !!src.cross_taxonomy_relationships);
                $('#tconn-sv-cross-taxonomy-row').toggle(!!src.relationship_delegation);
            }
            configureFormForScope(src.scope, true);
            // Load taxonomy dropdown and select saved value once it arrives.
            var taxOptional = (src.scope === 'local_group' || src.scope === 'remote_group');
            if (!taxOptional && src.peer_site_slug) {
                loadTaxonomiesForPeer(src.peer_site_slug, src.scope === 'roots', src.taxonomy || '');
            }
            $('#tconn-sv-conflict-area').hide();
            $('#tconn-sv-form-backdrop').show();
            $form.show();
            formDirty = false;
            tconnSvEditOriginalRoots = ('roots' === src.scope || 'terms' === src.scope)
                ? JSON.stringify((src.roots || []).map(function(r){ return r.remote_term_id; }).sort())
                : null;
        });

        // ---- DELETE ----
        $(document).on('click', '.tconn-sv-delete-btn', function(){
            if (!window.confirm(<?php echo wp_json_encode( __( 'Remove this source? All delegation to terms from this source will end, regardless of scope.', 'termconnect' ) ); ?>)) return;
            var $row = $(this).closest('tr');
            $.post(ajaxurl, {action:'tconn_sv_delete', nonce:$(this).data('nonce'), id:$(this).data('id')})
             .done(function(resp){
                if (resp.success){
                    var warning = resp.data && resp.data.warning;
                    var $tbody = $row.closest('tbody');
                    $row.fadeOut(200, function(){
                        $(this).remove();
                        var scope   = $tbody.attr('id').replace('tconn-sv-tbody-', '');
                        var $tab    = $('#tconn-sv-tabs a[data-scope="' + scope + '"]');
                        var newCount = $tbody.find('tr').length;
                        $tab.find('span').remove();
                        if (newCount) { $tab.append($('<span style="margin-left:4px;background:#72aee6;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px">').text(newCount)); }
                        if (warning) { alert(warning); }
                    });
                } else { alert((resp.data && resp.data.message) || 'Error.'); }
             });
        });

        // ---- SYNC NOW ----
        function tconnSvRunSync($btn) {
            var origText  = $btn.text();
            var $row      = $btn.closest('tr');
            var $siblings = $row.find('.tconn-sv-edit-btn, .tconn-sv-delete-btn');
            $btn.prop('disabled', true)
                .addClass('tconn-sv-syncing')
                .html('<span class="dashicons dashicons-update"></span> <?php echo esc_js( __( 'Syncing…', 'termconnect' ) ); ?>');
            $siblings.prop('disabled', true);
            $.ajax({type:'POST', url:ajaxurl, data:{action:'tconn_sv_sync', nonce:$btn.data('nonce'), id:$btn.data('id')}, timeout:120000})
             .done(function(resp){
                var msg, style;
                if (resp.success){
                    var d = resp.data;
                    msg = '✓ '+d.synced+' synced, '+d.created+' created, '+d.renamed+' renamed';
                    if (d.skipped)         msg += ', '+d.skipped+' skipped';
                    if (d.orphaned)        msg += ', '+d.orphaned+' orphaned';
                    if (d.rel_rows)        msg += ', '+d.rel_rows+' relationship row(s) written';
                    if (d.merge_proposals) msg += ', '+d.merge_proposals+' merge proposal(s) — reload to review';
                    if (d.errors && d.errors.length) msg += ' — '+d.errors[0];
                    $row.find('td').eq(3).text('just now');
                    style = 'font-size:11px;margin-top:3px';
                } else {
                    msg   = (resp.data && resp.data.message) || 'Error.';
                    style = 'font-size:11px;margin-top:3px;color:#d63638';
                }
                $btn.closest('td').find('.tconn-sv-sync-result').remove();
                $btn.closest('td').append($('<div class="tconn-sv-sync-result description">').attr('style', style).text(msg));
                $btn.prop('disabled', false).removeClass('tconn-sv-syncing').text(origText);
                $siblings.prop('disabled', false);
             })
             .fail(function(jqXHR, textStatus){
                var msg = 'timeout' === textStatus
                    ? <?php echo wp_json_encode( __( 'Sync timed out — the remote site may be slow or unreachable.', 'termconnect' ) ); ?>
                    : <?php echo wp_json_encode( __( 'Request failed.', 'termconnect' ) ); ?>;
                $btn.closest('td').find('.tconn-sv-sync-result').remove();
                $btn.closest('td').append($('<div class="tconn-sv-sync-result description">').attr('style','font-size:11px;margin-top:3px;color:#d63638').text(msg));
                $btn.prop('disabled', false).removeClass('tconn-sv-syncing').text(origText);
                $siblings.prop('disabled', false);
             });
        }
        $(document).on('click', '.tconn-sv-sync-now', function(){ tconnSvRunSync($(this)); });

        // Auto-sync after save: ?sync=<id> param triggers the matching row's sync.
        (function(){
            var params = new URLSearchParams(window.location.search);
            var syncId = params.get('sync');
            if (syncId) {
                params.delete('sync');
                history.replaceState(null, '', location.pathname + '?' + params.toString());
                var $syncBtn = $('.tconn-sv-sync-now[data-id="' + syncId + '"]');
                if ($syncBtn.length) { tconnSvRunSync($syncBtn); }
            }
        })();

        // ---- MERGE PROPOSAL ----
        $(document).on('click', '.tconn-sv-merge-btn', function(){
            if (!window.confirm(<?php echo wp_json_encode( __( 'Merge this orphaned term into the active term? Posts will be reassigned and an archive redirect created. This cannot be undone.', 'termconnect' ) ); ?>)) return;
            var $btn = $(this).prop('disabled', true);
            var $row = $btn.closest('tr');
            $.post(ajaxurl, {action:'tconn_sv_merge_proposal', nonce:$btn.data('nonce'), proposal_id:$btn.data('proposal-id')})
             .done(function(resp){
                if (resp.success){ $row.fadeOut(200, function(){ $(this).remove(); if (!$('#tconn-sv-merge-table tbody tr').length) { $('#tconn-sv-merge-section').remove(); } }); }
                else { alert((resp.data && resp.data.message)||'Error.'); $btn.prop('disabled',false); }
             }).fail(function(){ alert('Request failed.'); $btn.prop('disabled',false); });
        });

        // ---- DISMISS MERGE ----
        $(document).on('click', '.tconn-sv-dismiss-merge', function(){
            var $btn = $(this).prop('disabled', true);
            var $row = $btn.closest('tr');
            $.post(ajaxurl, {action:'tconn_sv_dismiss_merge', nonce:$btn.data('nonce'), proposal_id:$btn.data('proposal-id')})
             .done(function(resp){
                if (resp.success){ $row.fadeOut(200, function(){ $(this).remove(); if (!$('#tconn-sv-merge-table tbody tr').length) { $('#tconn-sv-merge-section').remove(); } }); }
                else { alert((resp.data && resp.data.message)||'Error.'); $btn.prop('disabled',false); }
             }).fail(function(){ alert('Request failed.'); $btn.prop('disabled',false); });
        });
    });
    <?php tconn_add_inline_script( 'shared-vocabularies', ob_get_clean() ); ?>
    <?php
}

