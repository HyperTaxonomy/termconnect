<?php
/**
 * TermConnect Settings page — Settings → TermConnect.
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TC_Settings {

    /** @return string[] */
    public static function get_enabled_taxonomies(): array {
        $taxonomies = (array) get_option( 'tc_enabled_taxonomies', array() );

        /**
         * Filters the list of TermConnect-enabled taxonomies.
         *
         * Lets a companion plugin opt its taxonomies into TermConnect behavior
         * (the tc_links REST field, the htax_term_uri matching parameter, peer
         * term resolution by shared identifier, and "See also") without writing
         * to the tc_enabled_taxonomies option. The result is normalized to a
         * unique list of taxonomy slug strings.
         *
         * @since 1.0.0
         * @param string[] $taxonomies Enabled taxonomy slugs.
         */
        $taxonomies = (array) apply_filters( 'tc_enabled_taxonomies', $taxonomies );

        return array_values( array_unique( array_filter( array_map( 'strval', $taxonomies ) ) ) );
    }

    /** Registered on admin_init so wp_safe_redirect() fires before any page output. */
    public static function handle_save(): void {
        if ( ! isset( $_POST['tc_settings_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['tc_settings_nonce'] ) ), 'tc_settings_save' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'termconnect' ) );
        }

        self::save( get_option( 'tc_settings', array() ) );

        $tab      = sanitize_key( wp_unslash( $_POST['tc_current_tab'] ?? 'general' ) );
        $redirect = add_query_arg(
            array( 'page' => 'termconnect-settings', 'tab' => $tab, 'tc-settings-saved' => '1' ),
            admin_url( 'options-general.php' )
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    // =========================================================================
    // RENDER
    // =========================================================================

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'termconnect' ) );
        }

        $options = get_option( 'tc_settings', array() );

        // Notices.
        if ( isset( $_GET['tc-settings-saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'termconnect' ) . '</p></div>';
        }
        $import_result = sanitize_key( wp_unslash( $_GET['tc-import'] ?? '' ) );
        if ( 'ok' === $import_result ) {
            $count = absint( wp_unslash( $_GET['tc-import-count'] ?? 0 ) );
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html( sprintf( __( 'Import complete — %d records restored.', 'termconnect' ), $count ) )
            );
        } elseif ( in_array( $import_result, array( 'invalid', 'no-file' ), true ) ) {
            $msg = 'no-file' === $import_result
                ? __( 'Import failed — no file was uploaded.', 'termconnect' )
                : __( 'Import failed — the file could not be parsed.', 'termconnect' );
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
        }

        $valid_tabs  = array( 'general', 'peer-sites', 'shared-vocabularies', 'cache', 'export-import', 'advanced' );
        $requested   = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) );
        $active_tab  = in_array( $requested, $valid_tabs, true ) ? $requested : 'general';
        $base_url    = admin_url( 'options-general.php?page=termconnect-settings' );

        $tabs = array(
            'general'            => __( 'General', 'termconnect' ),
            'peer-sites'         => __( 'Peer Sites', 'termconnect' ),
            'shared-vocabularies' => __( 'Shared Vocabularies', 'termconnect' ),
            'cache'              => __( 'Cache', 'termconnect' ),
            'export-import'      => __( 'Export / Import', 'termconnect' ),
            'advanced'           => __( 'Advanced', 'termconnect' ),
        );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?php echo esc_url( add_query_arg( 'tab', $key, $base_url ) ); ?>"
                   class="nav-tab <?php echo $active_tab === $key ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html( $label ); ?>
                </a>
                <?php endforeach; ?>
            </nav>

            <?php if ( 'export-import' !== $active_tab ) : ?>
            <form method="post">
                <?php wp_nonce_field( 'tc_settings_save', 'tc_settings_nonce' ); ?>
                <input type="hidden" name="tc_current_tab" value="<?php echo esc_attr( $active_tab ); ?>" />
            <?php endif; ?>

                <?php
                switch ( $active_tab ) {
                    case 'general':
                        self::render_general_tab( $options );
                        break;
                    case 'shared-vocabularies':
                        self::render_shared_vocabularies_tab( $options );
                        break;
                    case 'peer-sites':
                        self::render_peer_sites_tab( $options );
                        break;
                    case 'cache':
                        self::render_cache_tab( $options );
                        break;
                    case 'export-import':
                        self::render_export_import_tab();
                        break;
                    case 'advanced':
                        self::render_advanced_tab( $options );
                        break;
                }
                ?>

            <?php if ( 'export-import' !== $active_tab ) : ?>
                <?php submit_button( __( 'Save Settings', 'termconnect' ) ); ?>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // TABS
    // =========================================================================

    private static function render_general_tab( array $options ): void {
        $enabled  = self::get_enabled_taxonomies();
        $all_taxa = get_taxonomies( array( 'public' => true, 'show_ui' => true ), 'objects' );

        // Taxonomies forced on by a companion plugin via the tc_enabled_taxonomies
        // filter (present in the effective list but not the stored option). Their
        // checkboxes are shown checked but disabled — toggling them here would have
        // no effect, since the filter re-adds them every request.
        $stored = (array) get_option( 'tc_enabled_taxonomies', array() );
        $forced = array_diff( $enabled, $stored );

        $show_see_also              = (bool) ( $options['link_types_show_see_also'] ?? true );
        $see_also_format            = $options['see_also_format'] ?? 'inline';
        $see_also_label             = $options['see_also_label'] ?? __( 'See also:', 'termconnect' );
        $allow_suggestions          = (bool) ( $options['allow_suggested_links'] ?? false );
        $suggested_link_label           = $options['suggested_link_label'] ?? __( 'Suggest a related concept', 'termconnect' );
        $suggested_link_notifications   = ! isset( $options['suggested_link_notifications'] ) || ! empty( $options['suggested_link_notifications'] );
        ?>
        <h2><?php esc_html_e( 'Enabled Taxonomies', 'termconnect' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><?php esc_html_e( 'Taxonomies', 'termconnect' ); ?></th>
                <td>
                    <p class="description" style="margin-top:0;margin-bottom:8px;">
                        <?php esc_html_e( 'Enabling a taxonomy shows the Delegated Identity and External Links panels on its term edit screens, and turns on peer site term matching.', 'termconnect' ); ?>
                    </p>
                    <fieldset>
                        <?php foreach ( $all_taxa as $slug => $tax_obj ) : ?>
                        <?php $is_forced = in_array( $slug, $forced, true ); ?>
                        <label style="display:block;margin-bottom:4px;">
                            <input type="checkbox" name="tc_enabled_taxonomies[]"
                                   value="<?php echo esc_attr( $slug ); ?>"
                                   <?php checked( in_array( $slug, $enabled, true ) ); ?>
                                   <?php disabled( $is_forced ); ?> />
                            <?php echo esc_html( $tax_obj->labels->name ); ?>
                            <small style="color:#757575;">(<?php echo esc_html( $slug ); ?>)</small>
                            <?php if ( $is_forced ) : ?>
                            <small style="color:#757575;">&mdash; <?php esc_html_e( 'enabled by a companion plugin', 'termconnect' ); ?></small>
                            <?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    </fieldset>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e( 'See Also', 'termconnect' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><?php esc_html_e( 'Enable', 'termconnect' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" id="tc_see_also_enable" name="tc_settings[link_types_show_see_also]"
                               value="1" <?php checked( $show_see_also ); ?> />
                        <?php esc_html_e( 'Show "See also" section on term archive pages', 'termconnect' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Appends external links to term archive descriptions for enabled taxonomies.', 'termconnect' ); ?>
                    </p>
                </td>
            </tr>
            <tr id="tc_see_also_format_row"<?php echo $show_see_also ? '' : ' style="display:none"'; ?>>
                <th><?php esc_html_e( 'Format', 'termconnect' ); ?></th>
                <td>
                    <label style="display:block;margin-bottom:4px;">
                        <input type="radio" name="tc_settings[see_also_format]" id="tc_see_also_inline"
                               value="inline" <?php checked( $see_also_format, 'inline' ); ?> />
                        <?php esc_html_e( 'Inline — "See also: Wikidata · GeoNames"', 'termconnect' ); ?>
                    </label>
                    <label style="display:block;">
                        <input type="radio" name="tc_settings[see_also_format]" id="tc_see_also_list"
                               value="list" <?php checked( $see_also_format, 'list' ); ?> />
                        <?php esc_html_e( 'List — unordered list of links', 'termconnect' ); ?>
                    </label>
                    <div id="tc_see_also_label_row" style="margin-top:8px;<?php echo 'inline' !== $see_also_format ? 'display:none;' : ''; ?>">
                        <label for="tc_see_also_label"><?php esc_html_e( 'Inline label', 'termconnect' ); ?></label>
                        <input type="text" id="tc_see_also_label" name="tc_settings[see_also_label]"
                               value="<?php echo esc_attr( $see_also_label ); ?>" style="width:200px;margin-left:8px;" />
                        <p class="description"><?php esc_html_e( 'Text prepended to links in inline format.', 'termconnect' ); ?></p>
                    </div>
                    <script>
                    ( function() {
                        var enableCb  = document.getElementById( 'tc_see_also_enable' );
                        var formatRow = document.getElementById( 'tc_see_also_format_row' );
                        var labelRow  = document.getElementById( 'tc_see_also_label_row' );
                        var inl = document.getElementById( 'tc_see_also_inline' );
                        var lst = document.getElementById( 'tc_see_also_list' );
                        function syncLabel() { if (labelRow) labelRow.style.display = inl.checked ? '' : 'none'; }
                        function syncFormat() { if (formatRow) formatRow.style.display = enableCb.checked ? '' : 'none'; }
                        if ( inl ) inl.addEventListener( 'change', syncLabel );
                        if ( lst ) lst.addEventListener( 'change', syncLabel );
                        if ( enableCb ) enableCb.addEventListener( 'change', syncFormat );
                    } )();
                    </script>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e( 'Identifier Suggestions', 'termconnect' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Allow suggestions', 'termconnect' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" id="tc_allow_suggested_links" name="tc_settings[allow_suggested_links]" value="1"
                               <?php checked( $allow_suggestions ); ?> />
                        <?php esc_html_e( 'Show a suggestion form on term archive pages (below "See also"). Skipped on terms with delegated identity.', 'termconnect' ); ?>
                    </label>
                    <div id="tc_suggested_link_label_row" style="margin-top:8px;<?php echo $allow_suggestions ? '' : 'display:none;'; ?>">
                        <label for="tc_suggested_link_label"><?php esc_html_e( 'Form label', 'termconnect' ); ?></label>
                        <input type="text" id="tc_suggested_link_label" name="tc_settings[suggested_link_label]"
                               value="<?php echo esc_attr( $suggested_link_label ); ?>" style="width:400px;margin-left:8px;" />
                    </div>
                    <script>
                    ( function() {
                        var cb  = document.getElementById( 'tc_allow_suggested_links' );
                        var row = document.getElementById( 'tc_suggested_link_label_row' );
                        if ( cb && row ) {
                            cb.addEventListener( 'change', function() {
                                row.style.display = this.checked ? '' : 'none';
                            } );
                        }
                    } )();
                    </script>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Notify admin', 'termconnect' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="tc_settings[suggested_link_notifications]" value="1"
                               <?php checked( $suggested_link_notifications ); ?> />
                        <?php esc_html_e( 'Send admin email when a suggestion is submitted.', 'termconnect' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    private static function render_peer_sites_tab( array $options ): void {
        $max_foaf  = (int) ( $options['max_foaf_depth'] ?? 1 );
        $blacklist = (array) ( $options['external_site_blacklist'] ?? array() );
        ?>
        <p class="description" style="margin-top:16px;">
            <?php printf(
                /* translators: %s: link to Peer Sites */
                esc_html__( 'Configure peer WordPress sites and RSS/Atom feeds in %s.', 'termconnect' ),
                '<a href="' . esc_url( admin_url( 'admin.php?page=tc-peer-sites' ) ) . '">' . esc_html__( 'Terms → Peer Sites', 'termconnect' ) . '</a>'
            ); ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="tc_foaf_depth"><?php esc_html_e( 'Max FOAF Depth', 'termconnect' ); ?></label></th>
                <td>
                    <select id="tc_foaf_depth" name="tc_settings[max_foaf_depth]">
                        <?php foreach ( range( 0, 5 ) as $d ) : ?>
                        <option value="<?php echo esc_attr( $d ); ?>" <?php selected( $max_foaf, $d ); ?>>
                            <?php echo esc_html( $d ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e( 'Number of hops beyond directly configured peer sites. 0 = configured sites only. Individual shortcodes can request fewer hops but never more than this cap.', 'termconnect' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="tc_blacklist"><?php esc_html_e( 'Blocked Sites', 'termconnect' ); ?></label></th>
                <td>
                    <textarea id="tc_blacklist" name="tc_settings[external_site_blacklist]"
                              rows="5" style="width:400px;"
                              placeholder="example.com"><?php echo esc_textarea( implode( "\n", $blacklist ) ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'One domain per line. Blocks the site and all its subdomains.', 'termconnect' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    private static function render_shared_vocabularies_tab( array $options ): void {
        $health_url          = admin_url( 'site-health.php' );
        $sv_url              = admin_url( 'admin.php?page=tc-shared-vocabularies' );
        $auto_redirects      = (bool) ( $options['auto_accept_redirects'] ?? false );
        $auto_chain_redirect = (bool) ( $options['auto_accept_chain_redirects'] ?? false );
        $max_depth           = (int) ( $options['identity_chain_max_depth'] ?? 3 );
        $queue_count         = count( tc_identity_redirect_queue_get() );
        $misplaced_count     = count( (array) get_option( 'tc_sv_misplaced_queue', array() ) );

        // Count orphaned mirrored terms.
        global $wpdb;
        $orphaned_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->termmeta}
                 WHERE meta_key = %s
                   AND meta_value LIKE %s",
                TC_META_IDENTITY_SOURCE,
                '%"status";s:9:"not_found"%'
            )
        );
        $sv_issues = $misplaced_count + $orphaned_count;
        ?>
        <?php if ( $queue_count > 0 ) : ?>
        <div class="notice notice-warning inline" style="margin:16px 0;">
            <p>
                <?php printf(
                    /* translators: 1: count, 2: health check URL */
                    esc_html( _n(
                        '%1$d delegated identity source has a pending redirect that needs review.',
                        '%1$d delegated identity sources have pending redirects that need review.',
                        $queue_count,
                        'termconnect'
                    ) ),
                    $queue_count
                ); ?>
                <a href="<?php echo esc_url( $health_url ); ?>"><?php esc_html_e( 'Review in Site Health →', 'termconnect' ); ?></a>
            </p>
        </div>
        <?php endif; ?>

        <?php if ( $sv_issues > 0 ) : ?>
        <div class="notice notice-warning inline" style="margin:16px 0;">
            <p>
                <?php printf(
                    /* translators: 1: count, 2: shared vocabularies URL, 3: health check URL */
                    wp_kses(
                        _n(
                            '%1$d shared vocabulary issue needs attention. <a href="%2$s">Open Shared Vocabularies</a> or <a href="%3$s">check Site Health</a>.',
                            '%1$d shared vocabulary issues need attention. <a href="%2$s">Open Shared Vocabularies</a> or <a href="%3$s">check Site Health</a>.',
                            $sv_issues,
                            'termconnect'
                        ),
                        array( 'a' => array( 'href' => array() ) )
                    ),
                    $sv_issues,
                    esc_url( $sv_url ),
                    esc_url( $health_url )
                ); ?>
            </p>
        </div>
        <?php endif; ?>

        <p class="description" style="margin-top:16px">
            <?php
            if ( function_exists( 'htax_get_included_terms' ) ) {
                esc_html_e( 'Terms can delegate their title, description, external links and/or relationships to an externally defined term on a peer WordPress site, matched by slug.', 'termconnect' );
            } else {
                esc_html_e( 'Terms can delegate their title and/or description to an externally defined term on a peer WordPress site, matched by slug.', 'termconnect' );
            }
            ?>
        </p>
        <p class="description">
            <?php printf(
                /* translators: %s: link to Shared Vocabularies */
                function_exists( 'htax_get_included_terms' )
                    ? esc_html__( 'Mirror individual terms, groups, hierarchy branches or full taxonomies from peer sites in %s.', 'termconnect' )
                    : esc_html__( 'Mirror individual terms, hierarchy branches or full taxonomies from peer sites in %s.', 'termconnect' ),
                '<a href="' . esc_url( admin_url( 'admin.php?page=tc-shared-vocabularies' ) ) . '">' . esc_html__( 'Terms → Shared Vocabularies', 'termconnect' ) . '</a>'
            ); ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th><?php esc_html_e( 'Redirect review queue', 'termconnect' ); ?></th>
                <td>
                    <p>
                        <?php printf(
                            /* translators: %s: link to Site Health */
                            esc_html__( 'When a remote identity source becomes a redirect, it is added to a review queue. %s', 'termconnect' ),
                            '<a href="' . esc_url( $health_url ) . '">' . esc_html__( 'Review in Site Health', 'termconnect' ) . '</a>'
                        ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Auto-accept redirects', 'termconnect' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" id="tc_auto_accept_redirects" name="tc_settings[auto_accept_redirects]"
                               value="1" <?php checked( $auto_redirects ); ?> />
                        <?php esc_html_e( 'Automatically update delegated identity when the remote term becomes a redirect (skips the review queue).', 'termconnect' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Only resolves one hop. If the redirect target is itself a redirect, enable Auto-follow chain redirects below.', 'termconnect' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Auto-follow chain redirects', 'termconnect' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" id="tc_auto_chain_redirects" name="tc_settings[auto_accept_chain_redirects]"
                               value="1" <?php checked( $auto_chain_redirect ); disabled( ! $auto_redirects ); ?> />
                        <?php esc_html_e( 'When your direct identity source has itself been redirected (chain scenario), automatically update your delegation to point to the new ultimate target.', 'termconnect' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'When the redirect target is itself a redirect on the same peer site, follow the chain up to the max depth. If any hop crosses to a different taxonomy or is unreachable, the chain stops and the redirect is queued for review.', 'termconnect' ); ?>
                    </p>
                    <script>
                    ( function() {
                        var parent = document.getElementById( 'tc_auto_accept_redirects' );
                        var child  = document.getElementById( 'tc_auto_chain_redirects' );
                        if ( ! parent || ! child ) { return; }
                        parent.addEventListener( 'change', function() {
                            child.disabled = ! parent.checked;
                            if ( ! parent.checked ) { child.checked = false; }
                        } );
                    } )();
                    </script>
                </td>
            </tr>
            <tr>
                <th><label for="tc_chain_depth"><?php esc_html_e( 'Max chain depth', 'termconnect' ); ?></label></th>
                <td>
                    <select id="tc_chain_depth" name="tc_settings[identity_chain_max_depth]">
                        <?php foreach ( range( 1, 5 ) as $d ) : ?>
                        <option value="<?php echo esc_attr( $d ); ?>" <?php selected( $max_depth, $d ); ?>>
                            <?php echo esc_html( $d ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e( 'Maximum number of delegation hops to follow before stopping. Prevents infinite loops.', 'termconnect' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    private static function render_cache_tab( array $options ): void {
        $posts_ttl    = (int) ( $options['external_posts_cache_ttl'] ?? 3600 );
        $status_ttl   = (int) ( $options['ht_status_cache_ttl'] ?? 86400 );
        $identity_ttl = (int) ( $options['identity_cache_ttl'] ?? TC_IDENTITY_CACHE_TTL );
        ?>
        <p class="description" style="margin-top:16px;margin-bottom:.5em">
            <?php
            printf(
                /* translators: %s: link to Peer Sites page */
                wp_kses(
                    __( 'Individual peer site and feed post caches are cleared per-entry from <a href="%s">Terms &rarr; Peer Sites</a>.', 'termconnect' ),
                    array( 'a' => array( 'href' => array() ) )
                ),
                esc_url( admin_url( 'admin.php?page=tc-peer-sites' ) )
            );
            ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="tc_status_ttl"><?php esc_html_e( 'Peer Site Status', 'termconnect' ); ?></label></th>
                <td>
                    <input type="number" id="tc_status_ttl" name="tc_settings[ht_status_cache_ttl]"
                           value="<?php echo esc_attr( $status_ttl ); ?>" min="60" step="60" class="small-text" style="width:80px" />
                    <?php esc_html_e( 'seconds', 'termconnect' ); ?>
                    &nbsp;&nbsp;
                    <button type="button" class="button tc-clear-all-peer-cache"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'tc_clear_all_peer_cache' ) ); ?>">
                        <?php esc_html_e( 'Clear All Peer Cache', 'termconnect' ); ?>
                    </button>
                    <span class="tc-all-peer-clear-status" style="display:none;margin-left:6px;color:#46b450">&#10003;</span>
                    &nbsp;&nbsp;
                    <button type="button" class="button tc-clear-foaf-cache"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'tc_clear_foaf_cache' ) ); ?>">
                        <?php esc_html_e( 'Clear FOAF Cache', 'termconnect' ); ?>
                    </button>
                    <span class="tc-foaf-clear-status" style="display:none;margin-left:6px;color:#46b450">&#10003;</span>
                    <p class="description" style="margin-top:4px">
                        <?php esc_html_e( 'Whether each peer site has HyperTaxonomy active. Used for scored post fetching, FOAF discovery, proximity term matching, and relationship delegation.', 'termconnect' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="tc_posts_ttl"><?php esc_html_e( 'External Post Lists', 'termconnect' ); ?></label></th>
                <td>
                    <input type="number" id="tc_posts_ttl" name="tc_settings[external_posts_cache_ttl]"
                           value="<?php echo esc_attr( $posts_ttl ); ?>" min="60" step="60" class="small-text" style="width:80px" />
                    <?php esc_html_e( 'seconds', 'termconnect' ); ?>
                    <p class="description" style="margin-top:4px">
                        <?php esc_html_e( 'Post lists fetched from peer sites for [tc_peer_posts] and HyperTaxonomy related posts.', 'termconnect' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="tc_identity_ttl"><?php esc_html_e( 'Shared Vocabularies', 'termconnect' ); ?></label></th>
                <td>
                    <input type="number" id="tc_identity_ttl" name="tc_settings[identity_cache_ttl]"
                           value="<?php echo esc_attr( $identity_ttl ); ?>" min="60" step="60" class="small-text" style="width:80px" />
                    <?php esc_html_e( 'seconds', 'termconnect' ); ?>
                    &nbsp;&nbsp;
                    <button type="button" class="button tc-resync-delegated"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'tc_admin' ) ); ?>">
                        <?php esc_html_e( 'Re-sync All', 'termconnect' ); ?>
                    </button>
                    <span class="tc-resync-status" style="margin-left:8px;"></span>
                    <p class="description" style="margin-top:6px">
                        <?php esc_html_e( 'How often delegated term data is refreshed from source sites. Re-sync All ignores the TTL and fetches immediately.', 'termconnect' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <script>
        jQuery( function( $ ) {
            function tcCacheBtn( btnSel, statusSel, action, nonceKey ) {
                $( btnSel ).on( 'click', function() {
                    var $btn = $( this ).prop( 'disabled', true );
                    var $st  = $( statusSel ).hide();
                    $.post( ajaxurl, { action: action, nonce: $( this ).data( nonceKey ) } )
                        .done( function( r ) {
                            if ( r.success ) { $st.show(); setTimeout( function() { $st.hide(); }, 3000 ); }
                        } )
                        .always( function() { $btn.prop( 'disabled', false ); } );
                } );
            }
            tcCacheBtn( '.tc-clear-all-peer-cache', '.tc-all-peer-clear-status', 'tc_clear_all_peer_cache', 'nonce' );
            tcCacheBtn( '.tc-clear-foaf-cache',     '.tc-foaf-clear-status',     'tc_clear_foaf_cache',     'nonce' );
            $( '.tc-resync-delegated' ).on( 'click', function() {
                var $btn = $( this ).prop( 'disabled', true );
                var $st  = $( '.tc-resync-status' ).text( '<?php echo esc_js( __( 'Syncing…', 'termconnect' ) ); ?>' );
                $.post( ajaxurl, { action: 'tc_di_resync_all', nonce: $btn.data( 'nonce' ) } )
                    .done( function( r ) { $st.text( r.success ? ( r.data && r.data.message ) || '<?php echo esc_js( __( 'Done.', 'termconnect' ) ); ?>' : '<?php echo esc_js( __( 'Error.', 'termconnect' ) ); ?>' ); } )
                    .fail( function() { $st.text( '<?php echo esc_js( __( 'Request failed.', 'termconnect' ) ); ?>' ); } )
                    .always( function() { $btn.prop( 'disabled', false ); } );
            } );
        } );
        </script>
        <?php
    }

    private static function render_export_import_tab(): void {
        $export_url = wp_nonce_url( admin_url( 'admin-post.php?action=tc_export_links' ), 'tc_export_links' );
        ?>
        <h2><?php esc_html_e( 'Export', 'termconnect' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><?php esc_html_e( 'Export data', 'termconnect' ); ?></th>
                <td>
                    <a href="<?php echo esc_url( $export_url ); ?>" class="button">
                        <?php esc_html_e( 'Export Link Data (JSON)', 'termconnect' ); ?>
                    </a>
                    <p class="description" style="margin-top:6px;">
                        <?php esc_html_e( 'Includes link type definitions, per-term external identifier values, delegated identity sources, and Shared Vocabularies source configurations.', 'termconnect' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <h2><?php echo esc_html( _x( 'Import', 'settings section heading', 'termconnect' ) ); ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field( 'tc_import_links', 'tc_import_nonce' ); ?>
            <input type="hidden" name="action" value="tc_import_links" />
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="tc_import_file"><?php esc_html_e( 'Import file', 'termconnect' ); ?></label></th>
                    <td>
                        <input type="file" id="tc_import_file" name="tc_import_file" accept=".json" required />
                        &nbsp;
                        <input type="submit" class="button" value="<?php echo esc_attr( _x( 'Import', 'submit button verb', 'termconnect' ) ); ?>" />
                    </td>
                </tr>
            </table>
        </form>
        <?php
    }

    private static function render_advanced_tab( array $options ): void {
        $expose_links        = (bool) ( $options['expose_links_api'] ?? true );
        $delete_on_uninstall = (bool) ( $options['delete_on_uninstall'] ?? false );
        ?>
        <h2><?php esc_html_e( 'REST API', 'termconnect' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><?php esc_html_e( 'Share external links', 'termconnect' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="tc_settings[expose_links_api]"
                               value="1" <?php checked( $expose_links ); ?> />
                        <?php esc_html_e( 'Enable', 'termconnect' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'When enabled, each term\'s external identifiers and peer site links are included in REST responses for requests carrying an X-TermConnect header. Peer sites use this to inherit identifiers via delegated identity and vocabulary sync, and to discover further peer connections for FOAF traversal. Disable to stop exposing this data to all peer TermConnect sites.', 'termconnect' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e( 'Uninstall', 'termconnect' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><?php esc_html_e( 'Delete all data', 'termconnect' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="tc_settings[delete_on_uninstall]"
                               value="1" <?php checked( $delete_on_uninstall ); ?> />
                        <?php esc_html_e( 'Delete all TermConnect data (link types, per-term identifiers, delegated identity, settings) when the plugin is deleted.', 'termconnect' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Deactivating the plugin never deletes data. Deletion only occurs when you use WordPress "Delete" on the Plugins screen.', 'termconnect' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // =========================================================================
    // SAVE
    // =========================================================================

    private static function save( array $existing ): void {
        $raw_taxonomies = wp_unslash( $_POST['tc_enabled_taxonomies'] ?? array() );
        update_option( 'tc_enabled_taxonomies', array_map( 'sanitize_key', (array) $raw_taxonomies ) );

        $input = wp_unslash( $_POST['tc_settings'] ?? array() );

        // General
        $existing['allow_suggested_links']  = ! empty( $input['allow_suggested_links'] );
        $existing['suggested_link_label']              = sanitize_text_field( $input['suggested_link_label'] ?? '' );
        $existing['suggested_link_notifications']      = ! empty( $input['suggested_link_notifications'] );
        $existing['link_types_show_see_also']     = ! empty( $input['link_types_show_see_also'] );
        $existing['see_also_format']              = in_array( $input['see_also_format'] ?? 'inline', array( 'inline', 'list' ), true )
            ? $input['see_also_format'] : 'inline';
        $existing['see_also_label']               = sanitize_text_field( $input['see_also_label'] ?? '' );

        // Peer Sites
        $existing['max_foaf_depth']               = min( 5, max( 0, (int) ( $input['max_foaf_depth'] ?? 1 ) ) );
        $raw_blacklist = sanitize_textarea_field( $input['external_site_blacklist'] ?? '' );
        $existing['external_site_blacklist']      = array_filter(
            array_map( 'trim', explode( "\n", $raw_blacklist ) )
        );

        // Shared Vocabularies
        $existing['auto_accept_redirects']        = ! empty( $input['auto_accept_redirects'] );
        $existing['auto_accept_chain_redirects']  = $existing['auto_accept_redirects'] && ! empty( $input['auto_accept_chain_redirects'] );
        $existing['identity_chain_max_depth']     = min( 5, max( 1, (int) ( $input['identity_chain_max_depth'] ?? 3 ) ) );

        // Cache
        $existing['identity_cache_ttl']           = max( 60, (int) ( $input['identity_cache_ttl'] ?? TC_IDENTITY_CACHE_TTL ) );
        $existing['external_posts_cache_ttl']     = max( 60, (int) ( $input['external_posts_cache_ttl'] ?? 3600 ) );
        $existing['ht_status_cache_ttl']          = max( 60, (int) ( $input['ht_status_cache_ttl'] ?? 86400 ) );

        // Advanced
        $existing['expose_links_api']             = ! empty( $input['expose_links_api'] );
        $existing['delete_on_uninstall']          = ! empty( $input['delete_on_uninstall'] );

        update_option( 'tc_settings', $existing );
    }
}

// Process settings form submissions before any output.
add_action( 'admin_init', array( 'TC_Settings', 'handle_save' ) );
