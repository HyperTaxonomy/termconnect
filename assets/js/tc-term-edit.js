/* TermConnect — Term Edit: Delegated Identity inline UI
 *
 * Flow (empty state):
 *   "+" → dropdown (peer site select)
 *   select peer → immediate lookup for local slug on that peer
 *   found → confirmation pane (description/delegated links preview + warning)
 *   not found → error shown in dropdown, pick different peer
 *   Delegate → AJAX tc_di_set_source → reload
 *
 * Slug matching is enforced: only a remote term with the exact same slug
 * as the local term can be delegated. No cross-slug mapping.
 *
 * Flow (active state):
 *   pill shows "[Site: remote-slug ↗]  ×"
 *   Refresh → AJAX tc_di_refresh → reload
 *   × → confirm → AJAX tc_di_remove → reload (empty state shown on reload)
 */
/* global jQuery, ajaxurl, tcDiI18n */

( function ( $ ) {
    'use strict';

    var i18n = ( typeof tcDiI18n !== 'undefined' ) ? tcDiI18n : {};

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
        $container.find( '.tc-di-dropdown' ).hide();
        $( document ).off( 'click.tcDiDrop keydown.tcDiDrop' );
    }

    function resetContainer( $container ) {
        $container.find( '.tc-di-site-select' ).val( '' );
        $container.find( '.tc-di-lookup-status' ).hide().text( '' );
    }

    // -------------------------------------------------------------------------
    // "+" button — open/close dropdown
    // -------------------------------------------------------------------------

    $( document ).on( 'click', '.tc-di-add-btn', function ( e ) {
        e.stopPropagation();
        var $container = $( this ).closest( '.tc-di-container' );
        var $dropdown  = $container.find( '.tc-di-dropdown' );

        if ( $dropdown.is( ':visible' ) ) {
            closeDropdown( $container );
            return;
        }

        resetContainer( $container );
        positionDropdown( $dropdown, $( this ) );
        $dropdown.show();

        $( document ).off( 'click.tcDiDrop keydown.tcDiDrop' ).on( 'click.tcDiDrop', function ( ev ) {
            if ( ! $( ev.target ).closest( '.tc-di-container' ).length ) {
                closeDropdown( $container );
            }
        } ).on( 'keydown.tcDiDrop', function ( ev ) {
            if ( 27 === ev.which ) {
                closeDropdown( $container );
            }
        } );
    } );

    // -------------------------------------------------------------------------
    // Peer site selected → immediately look up the local slug on that peer
    // -------------------------------------------------------------------------

    $( document ).on( 'change', '.tc-di-site-select', function () {
        var $container   = $( this ).closest( '.tc-di-container' );
        var $field       = $container.closest( '.tc-external-links-field' );
        var $confirm     = $field.find( '.tc-di-confirm' );
        var $lookupStatus = $container.find( '.tc-di-lookup-status' );
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
            action:      'tc_di_preview_term',
            nonce:       nonce,
            term_id:     termId,
            peer_site_slug:     peerSiteSlug,
            remote_slug: localSlug,
        } ).done( function ( resp ) {
            if ( resp.success ) {
                var d        = resp.data;
                var siteLabel = $container.find( '.tc-di-site-select option:selected' ).text();
                var pillLabel = siteLabel + ': ' + localSlug;

                $lookupStatus.hide();
                closeDropdown( $container );

                // Pending pill.
                $container.find( '.tc-di-add-btn' ).hide();
                $container.find( '.tc-di-pending-pill' ).remove();
                var $pendingPill = $( '<span class="tc-link-pill tc-di-pending-pill">' );
                var $pillText    = $( '<span class="tc-di-pill-text">' ).text( pillLabel );
                $pendingPill.append( $pillText );
                $( '<button type="button" class="tc-link-pill-remove tc-di-cancel-pending">&times;</button>' )
                    .appendTo( $pendingPill );
                $pendingPill.appendTo( $container );

                // Store selection on confirm button.
                $confirm.find( '.tc-di-confirm-btn' )
                    .data( 'peer-site-slug', peerSiteSlug )
                    .data( 'remote-slug', localSlug )
                    .data( 'term-id', termId )
                    .data( 'nonce', nonce )
                    .prop( 'disabled', false );

                // Show sync_descendants checkbox only for hierarchical taxonomies.
                $confirm.find( '.tc-di-sync-descendants-row' ).toggle( hierarchical );

                // Preview.
                var hasDesc    = !! d.description;
                var links      = d.delegated_links || [];
                $confirm.find( '.tc-di-preview-desc' ).text( d.description || '' ).toggle( hasDesc );
                var maps = '';
                $.each( links, function ( i, entry ) {
                    var label = entry.label || entry.platform || entry.url;
                    maps += '<span class="tc-link-pill" style="opacity:0.85">' +
                        $( '<span>' ).text( label ).html() + '</span> ';
                } );
                $confirm.find( '.tc-di-preview-mappings' ).html( maps );
                $confirm.find( '.tc-di-preview-box' ).toggle( hasDesc || links.length > 0 );
                $confirm.find( '.tc-di-confirm-status' ).text( '' );

                // Upgrade pending pill with link.
                if ( d.link ) {
                    $pendingPill.find( '.tc-di-pill-text' ).replaceWith(
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

    $( document ).on( 'click', '.tc-di-confirm-btn', function () {
        var $btn           = $( this ).prop( 'disabled', true );
        var termId         = $btn.data( 'term-id' );
        var peerSiteSlug         = $btn.data( 'peer-site-slug' );
        var remoteSlug     = $btn.data( 'remote-slug' );
        var nonce          = $btn.data( 'nonce' );
        var $confirm       = $btn.closest( '.tc-di-confirm' );
        var $status        = $confirm.find( '.tc-di-confirm-status' );
        var syncDesc     = $confirm.find( '.tc-di-sync-descendants' ).is( ':checked' ) ? 1 : 0;
        var syncName     = $confirm.find( '.tc-di-sync-name' ).is( ':checked' ) ? 1 : 0;
        var syncDescFlag = $confirm.find( '.tc-di-sync-description' ).is( ':checked' ) ? 1 : 0;

        $status.text( i18n.delegating || '' );

        var baseParams = {
            action:           'tc_di_set_source',
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
            .text( i18n.joinExistingSv || 'Add to existing SV' )
            .on( 'click', function () { fireChoice( { existing_sv_id: conflict.existing_sv_id } ); } )
            .appendTo( $status );

        $( '<button type="button" class="button button-small">' )
            .text( i18n.createNewSv || 'Create new SV' )
            .on( 'click', function () { fireChoice( { force_new_sv: 1 } ); } )
            .appendTo( $status );
    }

    // -------------------------------------------------------------------------
    // Cancel (button or pending pill ×)
    // -------------------------------------------------------------------------

    function tcDiCancelConfirm( $field ) {
        $field.find( '.tc-di-confirm' ).hide();
        $field.find( '.tc-di-pending-pill' ).remove();
        $field.find( '.tc-di-add-btn' ).show();
    }

    $( document ).on( 'click', '.tc-di-cancel-confirm, .tc-di-cancel-pending', function () {
        tcDiCancelConfirm( $( this ).closest( '.tc-external-links-field' ) );
    } );

    // -------------------------------------------------------------------------
    // Panel helpers
    // -------------------------------------------------------------------------

    function openPanel( $panel ) {
        $( '#tc-di-backdrop' ).show();
        $panel.show();
    }

    function closePanel( $panel ) {
        $panel.hide();
        $( '#tc-di-backdrop' ).hide();
    }

    $( document ).on( 'click', '#tc-di-backdrop', function () {
        closePanel( $( '#tc-di-remove-panel, #tc-di-settings-panel' ) );
    } );

    // -------------------------------------------------------------------------
    // Remove delegation (pill ×) — opens confirmation panel
    // -------------------------------------------------------------------------

    var $removeBtn = null;

    $( document ).on( 'click', '.tc-di-remove-btn', function () {
        $removeBtn = $( this );
        var syncName = $removeBtn.data( 'sync-name' );
        var syncDesc = $removeBtn.data( 'sync-description' );

        $( '#tc-di-restore-name' ).prop( 'checked', true );
        $( '#tc-di-restore-description' ).prop( 'checked', true );
        $( '#tc-di-keep-peer-links' ).prop( 'checked', false );
        $( '#tc-di-remove-status' ).text( '' );
        $( '#tc-di-remove-confirm-btn' ).prop( 'disabled', false );

        $( '#tc-di-remove-restore-name-row' ).toggle( '1' === String( syncName ) );
        $( '#tc-di-remove-restore-desc-row' ).toggle( '1' === String( syncDesc ) );

        openPanel( $( '#tc-di-remove-panel' ) );
    } );

    $( document ).on( 'click', '#tc-di-remove-cancel-btn', function () {
        closePanel( $( '#tc-di-remove-panel' ) );
        $removeBtn = null;
    } );

    $( document ).on( 'click', '#tc-di-remove-confirm-btn', function () {
        if ( ! $removeBtn ) { return; }
        var $btn        = $( this ).prop( 'disabled', true );
        var termId      = $removeBtn.data( 'term-id' );
        var nonce       = $removeBtn.data( 'nonce' );
        var restoreName = $( '#tc-di-restore-name' ).is( ':checked' ) ? 1 : 0;
        var restoreDesc = $( '#tc-di-restore-description' ).is( ':checked' ) ? 1 : 0;
        var keepLinks   = $( '#tc-di-keep-peer-links' ).is( ':checked' ) ? 1 : 0;
        var $status     = $( '#tc-di-remove-status' );

        $.post( ajaxurl, {
            action:              'tc_di_remove',
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

    $( document ).on( 'click', '.tc-di-settings-btn', function () {
        var $btn = $( this );
        $( '#tc-di-settings-term-id' ).val( $btn.data( 'term-id' ) );
        $( '#tc-di-settings-nonce' ).val( $btn.data( 'nonce' ) );
        $( '#tc-di-settings-sync-name' ).prop( 'checked', '1' === String( $btn.data( 'sync-name' ) ) );
        $( '#tc-di-settings-sync-description' ).prop( 'checked', '1' === String( $btn.data( 'sync-description' ) ) );
        $( '#tc-di-settings-status' ).text( '' );
        $( '#tc-di-settings-save-btn' ).prop( 'disabled', false );
        openPanel( $( '#tc-di-settings-panel' ) );
    } );

    $( document ).on( 'click', '#tc-di-settings-cancel-btn', function () {
        closePanel( $( '#tc-di-settings-panel' ) );
    } );

    $( document ).on( 'click', '#tc-di-settings-save-btn', function () {
        var $btn  = $( this ).prop( 'disabled', true );
        var $status = $( '#tc-di-settings-status' );
        $.post( ajaxurl, {
            action:           'tc_di_update_settings',
            nonce:            $( '#tc-di-settings-nonce' ).val(),
            term_id:          $( '#tc-di-settings-term-id' ).val(),
            sync_name:        $( '#tc-di-settings-sync-name' ).is( ':checked' ) ? 1 : 0,
            sync_description: $( '#tc-di-settings-sync-description' ).is( ':checked' ) ? 1 : 0,
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

    $( document ).on( 'click', '.tc-di-refresh-btn', function () {
        var $btn    = $( this ).prop( 'disabled', true );
        var termId  = $btn.data( 'term-id' );
        var nonce   = $btn.data( 'nonce' );
        var $status = $btn.siblings( '.tc-di-action-status' );

        $status.text( i18n.refreshing || '' );

        $.post( ajaxurl, { action: 'tc_di_refresh', nonce: nonce, term_id: termId } )
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
