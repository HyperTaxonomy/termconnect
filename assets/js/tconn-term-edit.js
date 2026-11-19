/* TermConnect — Term Edit: Delegated Identity inline UI
 *
 * Flow (empty state):
 *   "+" → dropdown (peer site select)
 *   select peer → immediate lookup for local slug on that peer
 *   found → confirmation pane (description/delegated links preview + warning)
 *   not found → error shown in dropdown, pick different peer
 *   Delegate → AJAX tconn_di_set_source → reload
 *
 * Slug matching is enforced: only a remote term with the exact same slug
 * as the local term can be delegated. No cross-slug mapping.
 *
 * Flow (active state):
 *   pill shows "[Site: remote-slug ↗]  ×"
 *   Refresh → AJAX tconn_di_refresh → reload
 *   × → confirm → AJAX tconn_di_remove → reload (empty state shown on reload)
 */
/* global jQuery, ajaxurl, tconnDiI18n */

( function ( $ ) {
    'use strict';

    var i18n = ( typeof tconnDiI18n !== 'undefined' ) ? tconnDiI18n : {};

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function positionDropdown( $dropdown, $anchor ) {
        var r = $anchor[0].getBoundingClientRect();
        $dropdown.css( {
            position: 'fixed',
            top:  ( r.bottom + 4 ) + 'px',
            left: Math.max( 4, r.left ) + 'px',
        } );
    }

    function closeDropdown( $container ) {
        $container.find( '.tconn-di-dropdown' ).hide();
        $( document ).off( 'click.tconnDiDrop keydown.tconnDiDrop' );
    }

    function resetContainer( $container ) {
        $container.find( '.tconn-di-site-select' ).val( '' );
        $container.find( '.tconn-di-lookup-status' ).hide().text( '' );
    }

    // -------------------------------------------------------------------------
    // "+" button — open/close dropdown
    // -------------------------------------------------------------------------

    $( document ).on( 'click', '.tconn-di-add-btn', function ( e ) {
        e.stopPropagation();
        var $container = $( this ).closest( '.tconn-di-container' );
        var $dropdown  = $container.find( '.tconn-di-dropdown' );

        if ( $dropdown.is( ':visible' ) ) {
            closeDropdown( $container );
            return;
        }

        resetContainer( $container );
        positionDropdown( $dropdown, $( this ) );
        $dropdown.show();

        $( document ).off( 'click.tconnDiDrop keydown.tconnDiDrop' ).on( 'click.tconnDiDrop', function ( ev ) {
            if ( ! $( ev.target ).closest( '.tconn-di-container' ).length ) {
                closeDropdown( $container );
            }
        } ).on( 'keydown.tconnDiDrop', function ( ev ) {
            if ( 27 === ev.which ) {
                closeDropdown( $container );
            }
        } );
    } );

    // -------------------------------------------------------------------------
    // Peer site selected → immediately look up the local slug on that peer
    // -------------------------------------------------------------------------

    $( document ).on( 'change', '.tconn-di-site-select', function () {
        var $container   = $( this ).closest( '.tconn-di-container' );
        var $field       = $container.closest( '.tconn-external-links-field' );
        var $confirm     = $field.find( '.tconn-di-confirm' );
        var $lookupStatus = $container.find( '.tconn-di-lookup-status' );
        var peerSiteSlug       = $( this ).val();
        var localSlug    = $container.data( 'local-slug' );
        var termId       = $container.data( 'term-id' );
        var nonce        = $container.data( 'nonce' );
        var hierarchical = $container.data( 'hierarchical' ) === 1 || $container.data( 'hierarchical' ) === '1';

        if ( ! peerSiteSlug ) {
            $lookupStatus.hide();
            $confirm.hide();
            return;
        }

        $lookupStatus.text( i18n.loading || '' ).show();
        $confirm.hide();

        $.post( ajaxurl, {
            action:      'tconn_di_preview_term',
            nonce:       nonce,
            term_id:     termId,
            peer_site_slug:     peerSiteSlug,
            remote_slug: localSlug,
        } ).done( function ( resp ) {
            if ( resp.success ) {
                var d        = resp.data;
                var siteLabel = $container.find( '.tconn-di-site-select option:selected' ).text();
                var pillLabel = siteLabel + ': ' + localSlug;

                $lookupStatus.hide();
                closeDropdown( $container );

                // Pending pill.
                $container.find( '.tconn-di-add-btn' ).hide();
                $container.find( '.tconn-di-pending-pill' ).remove();
                var $pendingPill = $( '<span class="tconn-link-pill tconn-di-pending-pill">' );
                var $pillText    = $( '<span class="tconn-di-pill-text">' ).text( pillLabel );
                $pendingPill.append( $pillText );
                $( '<button type="button" class="tconn-link-pill-remove tconn-di-cancel-pending">&times;</button>' )
                    .appendTo( $pendingPill );
                $pendingPill.appendTo( $container );

                // Store selection on confirm button.
                $confirm.find( '.tconn-di-confirm-btn' )
                    .data( 'peer-site-slug', peerSiteSlug )
                    .data( 'remote-slug', localSlug )
                    .data( 'term-id', termId )
                    .data( 'nonce', nonce )
                    .prop( 'disabled', false );

                // Show sync_descendants checkbox only for hierarchical taxonomies.
                $confirm.find( '.tconn-di-sync-descendants-row' ).toggle( hierarchical );

                // Preview.
                var hasDesc    = !! d.description;
                var links      = d.delegated_links || [];
                $confirm.find( '.tconn-di-preview-desc' ).text( d.description || '' ).toggle( hasDesc );
                var maps = '';
                $.each( links, function ( i, entry ) {
                    var label = entry.label || entry.platform || entry.url;
                    maps += '<span class="tconn-link-pill" style="opacity:0.85">' +
                        $( '<span>' ).text( label ).html() + '</span> ';
                } );
                $confirm.find( '.tconn-di-preview-mappings' ).html( maps );
                $confirm.find( '.tconn-di-preview-box' ).toggle( hasDesc || links.length > 0 );
                $confirm.find( '.tconn-di-confirm-status' ).text( '' );

                // Upgrade pending pill with link.
                if ( d.link ) {
                    $pendingPill.find( '.tconn-di-pill-text' ).replaceWith(
                        $( '<a>' ).attr( { href: d.link, target: '_blank', rel: 'noopener noreferrer' } )
                            .css( { color: 'inherit', 'text-decoration': 'none' } )
                            .text( pillLabel + ' ↗' )
                    );
                }

                $confirm.show();

            } else {
                // Term with this slug not found on the selected peer.
                var msg = ( resp.data && resp.data.message )
                    ? resp.data.message
                    : ( i18n.notFound || 'No term with this slug found on the selected peer site.' );
                $lookupStatus.html( '<span style="color:#d63638">' + $( '<span>' ).text( msg ).html() + '</span>' );
            }
        } ).fail( function () {
            $lookupStatus.html( '<span style="color:#d63638">' + $( '<span>' ).text( i18n.error || 'Request failed.' ).html() + '</span>' );
        } );
    } );

    // -------------------------------------------------------------------------
    // Confirm delegation
    // -------------------------------------------------------------------------

    $( document ).on( 'click', '.tconn-di-confirm-btn', function () {
        var $btn           = $( this ).prop( 'disabled', true );
        var termId         = $btn.data( 'term-id' );
        var peerSiteSlug         = $btn.data( 'peer-site-slug' );
        var remoteSlug     = $btn.data( 'remote-slug' );
        var nonce          = $btn.data( 'nonce' );
        var $confirm       = $btn.closest( '.tconn-di-confirm' );
        var $status        = $confirm.find( '.tconn-di-confirm-status' );
        var syncDesc     = $confirm.find( '.tconn-di-sync-descendants' ).is( ':checked' ) ? 1 : 0;
        var syncName     = $confirm.find( '.tconn-di-sync-name' ).is( ':checked' ) ? 1 : 0;
        var syncDescFlag = $confirm.find( '.tconn-di-sync-description' ).is( ':checked' ) ? 1 : 0;

        $status.text( i18n.delegating || '' );

        var baseParams = {
            action:           'tconn_di_set_source',
            nonce:            nonce,
            term_id:          termId,
            peer_site_slug:   peerSiteSlug,
            remote_slug:      remoteSlug,
            sync_descendants: syncDesc,
            sync_name:        syncName,
            sync_description: syncDescFlag,
        };

        $.post( ajaxurl, baseParams ).done( function ( resp ) {
            if ( resp.success ) {
                window.location.reload();
            } else if ( resp.data && resp.data.code === 'sv_conflict' ) {
                renderSvConflict( $status, $btn, resp.data, baseParams );
            } else {
                $status.text( ( resp.data && resp.data.message ) || ( i18n.error || 'Error.' ) );
                $btn.prop( 'disabled', false );
            }
        } ).fail( function () {
            $status.text( i18n.error || 'Request failed.' );
            $btn.prop( 'disabled', false );
        } );
    } );

    // -------------------------------------------------------------------------
    // SV conflict prompt
    // -------------------------------------------------------------------------

    function renderSvConflict( $status, $confirmBtn, conflict, baseParams ) {
        var yesStr  = i18n.yes || 'yes';
        var noStr   = i18n.no  || 'no';
        var nameVal = conflict.existing_sync_name        ? yesStr : noStr;
        var descVal = conflict.existing_sync_description ? yesStr : noStr;
        var msg = ( i18n.svConflict || 'A Shared Vocabulary for this peer site already exists with: sync name: %1$s, sync description: %2$s.' )
            .replace( '%1$s', nameVal )
            .replace( '%2$s', descVal );

        $status.empty().append( $( '<span style="display:block;margin-bottom:6px">' ).text( msg ) );

        function fireChoice( extraParams ) {
            $confirmBtn.prop( 'disabled', true );
            $status.text( i18n.delegating || '' );
            $.post( ajaxurl, $.extend( {}, baseParams, extraParams ) ).done( function ( r ) {
                if ( r.success ) {
                    window.location.reload();
                } else {
                    $status.text( ( r.data && r.data.message ) || ( i18n.error || 'Error.' ) );
                    $confirmBtn.prop( 'disabled', false );
                }
            } ).fail( function () {
                $status.text( i18n.error || 'Request failed.' );
                $confirmBtn.prop( 'disabled', false );
            } );
        }

        $( '<button type="button" class="button button-small" style="margin-right:6px">' )
            .text( i18n.joinExistingSv || 'Add to existing Shared Vocabulary' )
            .on( 'click', function () { fireChoice( { existing_sv_id: conflict.existing_sv_id } ); } )
            .appendTo( $status );

        $( '<button type="button" class="button button-small">' )
            .text( i18n.createNewSv || 'Create new Shared Vocabulary' )
            .on( 'click', function () { fireChoice( { force_new_sv: 1 } ); } )
            .appendTo( $status );
    }

    // -------------------------------------------------------------------------
    // Cancel (button or pending pill ×)
    // -------------------------------------------------------------------------

    function tconnDiCancelConfirm( $field ) {
        $field.find( '.tconn-di-confirm' ).hide();
        $field.find( '.tconn-di-pending-pill' ).remove();
        $field.find( '.tconn-di-add-btn' ).show();
    }

    $( document ).on( 'click', '.tconn-di-cancel-confirm, .tconn-di-cancel-pending', function () {
        tconnDiCancelConfirm( $( this ).closest( '.tconn-external-links-field' ) );
    } );

    // -------------------------------------------------------------------------
    // Panel helpers
    // -------------------------------------------------------------------------

    function openPanel( $panel ) {
        $( '#tconn-di-backdrop' ).show();
        $panel.show();
    }

    function closePanel( $panel ) {
        $panel.hide();
        $( '#tconn-di-backdrop' ).hide();
    }

    $( document ).on( 'click', '#tconn-di-backdrop', function () {
        closePanel( $( '#tconn-di-remove-panel, #tconn-di-settings-panel' ) );
    } );

    // -------------------------------------------------------------------------
    // Remove delegation (pill ×) — opens confirmation panel
    // -------------------------------------------------------------------------

    var $removeBtn = null;

    $( document ).on( 'click', '.tconn-di-remove-btn', function () {
        $removeBtn = $( this );
        var syncName = $removeBtn.data( 'sync-name' );
        var syncDesc = $removeBtn.data( 'sync-description' );

        $( '#tconn-di-restore-name' ).prop( 'checked', true );
        $( '#tconn-di-restore-description' ).prop( 'checked', true );
        $( '#tconn-di-keep-peer-links' ).prop( 'checked', false );
        $( '#tconn-di-remove-status' ).text( '' );
        $( '#tconn-di-remove-confirm-btn' ).prop( 'disabled', false );

        $( '#tconn-di-remove-restore-name-row' ).toggle( '1' === String( syncName ) );
        $( '#tconn-di-remove-restore-desc-row' ).toggle( '1' === String( syncDesc ) );

        openPanel( $( '#tconn-di-remove-panel' ) );
    } );

    $( document ).on( 'click', '#tconn-di-remove-cancel-btn', function () {
        closePanel( $( '#tconn-di-remove-panel' ) );
        $removeBtn = null;
    } );

    $( document ).on( 'click', '#tconn-di-remove-confirm-btn', function () {
        if ( ! $removeBtn ) { return; }
        var $btn        = $( this ).prop( 'disabled', true );
        var termId      = $removeBtn.data( 'term-id' );
        var nonce       = $removeBtn.data( 'nonce' );
        var restoreName = $( '#tconn-di-restore-name' ).is( ':checked' ) ? 1 : 0;
        var restoreDesc = $( '#tconn-di-restore-description' ).is( ':checked' ) ? 1 : 0;
        var keepLinks   = $( '#tconn-di-keep-peer-links' ).is( ':checked' ) ? 1 : 0;
        var $status     = $( '#tconn-di-remove-status' );

        $.post( ajaxurl, {
            action:              'tconn_di_remove',
            nonce:               nonce,
            term_id:             termId,
            restore_name:        restoreName,
            restore_description: restoreDesc,
            keep_peer_links:     keepLinks,
        } ).done( function ( resp ) {
            if ( resp.success ) {
                window.location.reload();
            } else {
                $status.text( ( resp.data && resp.data.message ) || ( i18n.error || 'Error.' ) );
                $btn.prop( 'disabled', false );
            }
        } ).fail( function () {
            $status.text( i18n.error || 'Request failed.' );
            $btn.prop( 'disabled', false );
        } );
    } );

    // -------------------------------------------------------------------------
    // Sync settings
    // -------------------------------------------------------------------------

    $( document ).on( 'click', '.tconn-di-settings-btn', function () {
        var $btn = $( this );
        $( '#tconn-di-settings-term-id' ).val( $btn.data( 'term-id' ) );
        $( '#tconn-di-settings-nonce' ).val( $btn.data( 'nonce' ) );
        $( '#tconn-di-settings-sync-name' ).prop( 'checked', '1' === String( $btn.data( 'sync-name' ) ) );
        $( '#tconn-di-settings-sync-description' ).prop( 'checked', '1' === String( $btn.data( 'sync-description' ) ) );
        $( '#tconn-di-settings-status' ).text( '' );
        $( '#tconn-di-settings-save-btn' ).prop( 'disabled', false );
        openPanel( $( '#tconn-di-settings-panel' ) );
    } );

    $( document ).on( 'click', '#tconn-di-settings-cancel-btn', function () {
        closePanel( $( '#tconn-di-settings-panel' ) );
    } );

    $( document ).on( 'click', '#tconn-di-settings-save-btn', function () {
        var $btn  = $( this ).prop( 'disabled', true );
        var $status = $( '#tconn-di-settings-status' );
        $.post( ajaxurl, {
            action:           'tconn_di_update_settings',
            nonce:            $( '#tconn-di-settings-nonce' ).val(),
            term_id:          $( '#tconn-di-settings-term-id' ).val(),
            sync_name:        $( '#tconn-di-settings-sync-name' ).is( ':checked' ) ? 1 : 0,
            sync_description: $( '#tconn-di-settings-sync-description' ).is( ':checked' ) ? 1 : 0,
        } ).done( function ( resp ) {
            if ( resp.success ) {
                window.location.reload();
            } else {
                $status.text( ( resp.data && resp.data.message ) || ( i18n.error || 'Error.' ) );
                $btn.prop( 'disabled', false );
            }
        } ).fail( function () {
            $status.text( i18n.error || 'Request failed.' );
            $btn.prop( 'disabled', false );
        } );
    } );

    // -------------------------------------------------------------------------
    // Refresh cache
    // -------------------------------------------------------------------------

    $( document ).on( 'click', '.tconn-di-refresh-btn', function () {
        var $btn    = $( this ).prop( 'disabled', true );
        var termId  = $btn.data( 'term-id' );
        var nonce   = $btn.data( 'nonce' );
        var $status = $btn.siblings( '.tconn-di-action-status' );

        $status.text( i18n.refreshing || '' );

        $.post( ajaxurl, { action: 'tconn_di_refresh', nonce: nonce, term_id: termId } )
            .done( function ( resp ) {
                if ( resp.success ) {
                    window.location.reload();
                } else {
                    $status.text( ( resp.data && resp.data.message ) || ( i18n.error || 'Error.' ) );
                    $btn.prop( 'disabled', false );
                }
            } )
            .fail( function () {
                $status.text( i18n.error || 'Request failed.' );
                $btn.prop( 'disabled', false );
            } );
    } );

} )( jQuery );
