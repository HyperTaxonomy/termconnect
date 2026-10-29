<?php
/**
 * Delegated Identity — WordPress Site Health integration.
 *
 * Registers a single Site Health test that surfaces all actionable issues
 * with delegated identity: terms pending redirect review, unreachable sources,
 * and not-found remote terms.
 *
 * @package TermConnect
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_enqueue_scripts', function ( string $hook ): void {
    if ( 'site-health.php' !== $hook ) {
        return;
    }
    wp_enqueue_script(
        'tc-di-health',
        TC_PLUGIN_URL . 'assets/js/tc-di-health.js',
        array( 'jquery' ),
        TC_VERSION,
        true
    );
    wp_localize_script( 'tc-di-health', 'tcDiHealth', array(
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'tc_admin' ),
        'i18n'    => array(
            'checking'           => __( 'Checking…', 'termconnect' ),
            'error'              => __( 'Request failed.', 'termconnect' ),
            'removeOnly'         => __( 'End delegation', 'termconnect' ),
            'relinkRename'       => __( 'Re-link and rename local slug to "%s"', 'termconnect' ),
            'addLinkOnThis'      => __( 'Add as external link on this term', 'termconnect' ),
            'addLinkOnOther'     => __( 'Add as external link on "%s"', 'termconnect' ),
            'delegateOther'      => __( 'Delegate "%s" instead', 'termconnect' ),
            'renamedTo'          => __( 'Remote term renamed to "%s".', 'termconnect' ),
            'renamedToCollision' => __( 'Remote term renamed to "%1$s", which already exists locally as "%2$s".', 'termconnect' ),
            'followRedirect'     => __( 'Follow redirect', 'termconnect' ),
            'keepCurrent'        => __( 'Keep current delegation', 'termconnect' ),
        ),
    ) );
} );

add_filter( 'site_status_tests', function ( array $tests ): array {
    $tests['direct']['tc_delegated_identity'] = array(
        'label' => __( 'TermConnect delegated identity', 'termconnect' ),
        'test'  => 'tc_health_check_delegated_identity',
    );
    $tests['direct']['tc_shared_vocabularies'] = array(
        'label' => __( 'TermConnect shared vocabularies', 'termconnect' ),
        'test'  => 'tc_health_check_shared_vocabularies',
    );
    return $tests;
} );

/**
 * WordPress Site Health test callback for delegated identity status.
 *
 * @since 1.0.0
 * @return array Site Health result array.
 */
function tc_health_check_delegated_identity(): array {
    global $wpdb;

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s",
        TC_META_IDENTITY_SOURCE
    ) );

    if ( empty( $rows ) ) {
        return array(
            'label'       => __( 'No delegated identity terms configured', 'termconnect' ),
            'status'      => 'good',
            'badge'       => array( 'label' => __( 'TermConnect', 'termconnect' ), 'color' => 'blue' ),
            'description' => '',
            'test'        => 'tc_delegated_identity',
        );
    }

    _prime_term_caches( array_column( $rows, 'term_id' ) );

    $redirect_pending = array();
    $unreachable      = array();
    $not_found        = array();
    $queue            = tc_identity_redirect_queue_get();

    foreach ( $rows as $row ) {
        $source = maybe_unserialize( $row->meta_value );
        if ( ! is_array( $source ) ) {
            continue;
        }
        $term   = get_term( (int) $row->term_id );
        $label  = ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $row->term_id;
        $target = esc_html( trailingslashit( $source['site_url'] ?? '' ) . ( $source['remote_slug'] ?? '' ) );

        switch ( $source['status'] ?? '' ) {
            case 'redirect_pending':
                $entry              = $queue[ (string) $row->term_id ] ?? array();
                $redirect_pending[] = array(
                    'term_id'     => (int) $row->term_id,
                    'label'       => $label,
                    'target'      => $target,
                    'new_slug'    => (string) ( $entry['target_slug'] ?? '' ),
                    'new_name'    => (string) ( $entry['target_name'] ?? '' ),
                    'sv_managed'  => ! empty( $source['sv_source_id'] ),
                );
                break;
            case 'unreachable':
                $unreachable[] = array( 'label' => $label, 'target' => $target );
                break;
            case 'not_found':
                $not_found[] = array(
                    'term_id'        => (int) $row->term_id,
                    'label'          => $label,
                    'target'         => $target,
                    'remote_term_id' => (int) ( $source['remote_term_id'] ?? 0 ),
                );
                break;
        }
    }

    $issues = count( $redirect_pending ) + count( $unreachable ) + count( $not_found );

    if ( 0 === $issues ) {
        return array(
            'label'       => sprintf(
                _n(
                    '%d delegated identity term is active',
                    '%d delegated identity terms are active',
                    count( $rows ),
                    'termconnect'
                ),
                count( $rows )
            ),
            'status'      => 'good',
            'badge'       => array( 'label' => __( 'TermConnect', 'termconnect' ), 'color' => 'blue' ),
            'description' => '',
            'test'        => 'tc_delegated_identity',
        );
    }

    $description = '';

    if ( ! empty( $redirect_pending ) ) {
        $description .= '<p><strong>' . esc_html__( 'Redirect review pending', 'termconnect' ) . '</strong> — ';
        $description .= esc_html__( 'The remote terms these delegations point to now redirect elsewhere on the peer site. Follow the redirect, keep the current delegation, or end it.', 'termconnect' );
        $description .= '</p><ul>';
        foreach ( $redirect_pending as $item ) {
            $description .= '<li><strong>' . esc_html( $item['label'] ) . '</strong> — ' . esc_html( $item['target'] );
            if ( '' !== $item['new_slug'] ) {
                $new_label    = ( '' !== $item['new_name'] && $item['new_name'] !== $item['new_slug'] )
                    ? $item['new_name'] . ' (' . $item['new_slug'] . ')'
                    : $item['new_slug'];
                $description .= ' → ' . esc_html( $new_label );
            }
            if ( $item['sv_managed'] ) {
                $description .= ' &nbsp;<a href="' . esc_url( admin_url( 'admin.php?page=tc-shared-vocabularies' ) ) . '">'
                    . esc_html__( 'Managed by a shared vocabulary — update the source', 'termconnect' ) . '</a>';
            } else {
                $description .= '<div class="tc-di-redirect-actions" data-term-id="' . absint( $item['term_id'] ) . '"'
                    . ' data-can-follow="' . ( '' !== $item['new_slug'] ? '1' : '0' ) . '"></div>';
            }
            $description .= '</li>';
        }
        $description .= '</ul>';
    }

    if ( ! empty( $unreachable ) ) {
        $description .= '<p><strong>' . esc_html__( 'Unreachable sources', 'termconnect' ) . '</strong> — ';
        $description .= esc_html__( 'These delegated sources could not be reached on the last sync.', 'termconnect' );
        $description .= '</p><ul>';
        foreach ( $unreachable as $item ) {
            $description .= '<li><strong>' . esc_html( $item['label'] ) . '</strong> — ' . esc_html( $item['target'] ) . '</li>';
        }
        $description .= '</ul>';
    }

    if ( ! empty( $not_found ) ) {
        $description .= '<p><strong>' . esc_html__( 'Source term not found', 'termconnect' ) . '</strong> — ';
        $description .= esc_html__( 'These delegated sources returned a not-found response. The remote term may have been deleted or renamed.', 'termconnect' );
        $description .= '</p><ul>';
        foreach ( $not_found as $item ) {
            $description .= '<li><strong>' . esc_html( $item['label'] ) . '</strong> — ' . esc_html( $item['target'] );
            if ( ! empty( $item['remote_term_id'] ) ) {
                $description .= ' &nbsp;<button type="button" class="button-link tc-di-check-rename"'
                    . ' data-term-id="' . absint( $item['term_id'] ) . '">'
                    . esc_html__( 'Check for rename', 'termconnect' )
                    . '</button>'
                    . '<span class="tc-di-rename-result" style="margin-left:6px"></span>';
            }
            $description .= '</li>';
        }
        $description .= '</ul>';
    }

    return array(
        'label'       => sprintf(
            _n(
                '%d delegated identity issue requires attention',
                '%d delegated identity issues require attention',
                $issues,
                'termconnect'
            ),
            $issues
        ),
        'status'      => 'recommended',
        'badge'       => array( 'label' => __( 'TermConnect', 'termconnect' ), 'color' => 'orange' ),
        'description' => $description,
        'test'        => 'tc_delegated_identity',
    );
}

/**
 * WordPress Site Health test callback for shared vocabulary mirror issues.
 *
 * @since 1.0.0
 * @return array Site Health result array.
 */
function tc_health_check_shared_vocabularies(): array {
    $sources         = get_option( 'tc_shared_vocabularies', array() );
    $misplaced       = (array) get_option( 'tc_sv_misplaced_queue', array() );
    $merge_proposals = (array) get_option( 'tc_merge_proposals', array() );
    $issues          = array();
    $description     = '';

    // Orphaned mirrored terms — status = 'not_found' and have a source.
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s",
        TC_META_IDENTITY_SOURCE
    ) );
    _prime_term_caches( array_column( $rows, 'term_id' ) );
    $orphaned = array();
    foreach ( $rows as $row ) {
        $source = maybe_unserialize( $row->meta_value );
        if ( ! is_array( $source ) || empty( $source['peer_site_slug'] ) ) { continue; }
        if ( ( $source['status'] ?? '' ) === 'not_found' ) {
            $term  = get_term( (int) $row->term_id );
            $label = ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $row->term_id;
            $orphaned[] = array( 'label' => $label, 'target' => esc_html( $source['remote_slug'] ?? '' ) );
        }
    }

    if ( ! empty( $merge_proposals ) ) {
        $proposal_count = count( $merge_proposals );
        $issues[]       = $proposal_count;
        $sv_url         = admin_url( 'admin.php?page=tc-shared-vocabularies' );
        $description   .= '<p><strong>' . esc_html__( 'Merge proposals pending', 'termconnect' ) . '</strong> — ';
        $description   .= sprintf(
            /* translators: 1: count, 2: URL */
            wp_kses( _n( '%1$d orphaned term appears to be the same concept as a currently mirrored term. <a href="%2$s">Review merge proposals</a>.', '%1$d orphaned terms appear to be the same concept as a currently mirrored term. <a href="%2$s">Review merge proposals</a>.', $proposal_count, 'termconnect' ), array( 'a' => array( 'href' => array() ) ) ),
            $proposal_count,
            esc_url( $sv_url )
        );
        $description .= '</p>';
    }

    if ( ! empty( $misplaced ) ) {
        $issues[] = count( $misplaced );
        $sv_url   = admin_url( 'admin.php?page=tc-shared-vocabularies' );
        $description .= '<p><strong>' . esc_html__( 'Terms in wrong position', 'termconnect' ) . '</strong> — ';
        $description .= sprintf(
            wp_kses( _n( '%1$d mirrored term is not in the expected position in the local hierarchy. <a href="%2$s">Review in Shared Vocabularies</a>.', '%1$d mirrored terms are not in the expected position in the local hierarchy. <a href="%2$s">Review in Shared Vocabularies</a>.', count( $misplaced ), 'termconnect' ), array( 'a' => array( 'href' => array() ) ) ),
            count( $misplaced ),
            esc_url( $sv_url )
        );
        $description .= '</p>';
    }

    if ( ! empty( $orphaned ) ) {
        $issues[] = count( $orphaned );
        $description .= '<p><strong>' . esc_html__( 'Orphaned mirrored terms', 'termconnect' ) . '</strong> — ';
        $description .= esc_html__( 'These terms were mirrored from a peer but the remote term no longer exists. Review and end delegation or delete the local term if it is no longer needed.', 'termconnect' );
        $description .= '</p><ul>';
        foreach ( $orphaned as $item ) {
            $description .= '<li><strong>' . esc_html( $item['label'] ) . '</strong> — ' . esc_html( $item['target'] ) . '</li>';
        }
        $description .= '</ul>';
    }

    // Terms delegated to a shared vocabulary source that no longer exists.
    $valid_sv_ids   = array_column( $sources, 'id' );
    $dangling_count = 0;
    foreach ( $rows as $row ) {
        $source = maybe_unserialize( $row->meta_value );
        if ( ! is_array( $source ) ) {
            continue;
        }
        $sv_id = $source['sv_source_id'] ?? '';
        if ( $sv_id && ! in_array( $sv_id, $valid_sv_ids, true ) ) {
            $dangling_count++;
        }
    }
    if ( $dangling_count > 0 ) {
        $issues[]     = $dangling_count;
        $description .= '<p><strong>' . esc_html__( 'Delegation from deleted sources', 'termconnect' ) . '</strong> — ';
        $description .= sprintf(
            esc_html( _n(
                '%d term is delegated to a shared vocabulary source that no longer exists and will not be synced.',
                '%d terms are delegated to shared vocabulary sources that no longer exist and will not be synced.',
                $dangling_count,
                'termconnect'
            ) ),
            $dangling_count
        );
        $description .= ' ' . esc_html__( 'Re-add the source in Shared Vocabularies, or end delegation from each affected term.', 'termconnect' );
        $description .= '</p>';
    }

    // Stale relationship rows: delegated terms with local custom-type rows no longer on remote.
    $stale_rel_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s",
        TC_META_REL_SYNC_ISSUES
    ) );
    if ( ! empty( $stale_rel_rows ) ) {
        _prime_term_caches( array_column( $stale_rel_rows, 'term_id' ) );
        $stale_rel_count = count( $stale_rel_rows );
        $issues[]        = $stale_rel_count;
        $description    .= '<p><strong>' . esc_html__( 'Stale relationship rows', 'termconnect' ) . '</strong> — ';
        $description    .= sprintf(
            /* translators: %d: number of terms */
            esc_html( _n(
                '%d delegated term has local relationship rows that are no longer present on the peer site.',
                '%d delegated terms have local relationship rows that are no longer present on the peer site.',
                $stale_rel_count,
                'termconnect'
            ) ),
            $stale_rel_count
        );
        $description .= ' ' . esc_html__( 'These rows will be removed on the next sync if the peer restores them, or can be reviewed on each term\'s edit screen.', 'termconnect' );
        $description .= '</p>';
    }

    $total = array_sum( $issues );

    if ( 0 === $total ) {
        $source_count = count( $sources );
        return array(
            'label'       => $source_count
                ? sprintf( _n( '%d shared vocabulary source is healthy', '%d shared vocabulary sources are healthy', $source_count, 'termconnect' ), $source_count )
                : __( 'No shared vocabulary sources configured', 'termconnect' ),
            'status'      => 'good',
            'badge'       => array( 'label' => __( 'TermConnect', 'termconnect' ), 'color' => 'blue' ),
            'description' => '',
            'test'        => 'tc_shared_vocabularies',
        );
    }

    return array(
        'label'       => sprintf(
            _n( '%d shared vocabulary issue requires attention', '%d shared vocabulary issues require attention', $total, 'termconnect' ),
            $total
        ),
        'status'      => 'recommended',
        'badge'       => array( 'label' => __( 'TermConnect', 'termconnect' ), 'color' => 'orange' ),
        'description' => $description,
        'actions'     => '<a href="' . esc_url( admin_url( 'admin.php?page=tc-shared-vocabularies' ) ) . '">'
                         . esc_html__( 'Open Shared Vocabularies', 'termconnect' ) . '</a>',
        'test'        => 'tc_shared_vocabularies',
    );
}
