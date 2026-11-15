/* TermConnect — Site Health: delegated identity rename check and recovery actions
 *
 * Handles "Check for rename" buttons on not_found delegation entries.
 * Fires tc_di_check_rename AJAX, renders outcome inline, and provides
 * action buttons for each recovery path.
 */
/* global jQuery, tcDiHealth */

( function ( $ ) {
    'use strict';

    var cfg  = ( typeof tcDiHealth !== 'undefined' ) ? tcDiHealth : {};
    var ajax = cfg.ajaxurl || ( typeof ajaxurl !== 'undefined' ? ajaxurl : '' );
    var nonce = cfg.nonce || '';
    var i18n  = cfg.i18n || {};

    // -------------------------------------------------------------------------
    // "Check for rename" button
    // -------------------------------------------------------------------------

    $( document ).on( 'click', '.tc-di-check-rename', function ( e ) {
        e.preventDefault();
        var $btn    = $( this ).prop( 'disabled', true );
        var termId  = $btn.data( 'term-id' );
        var $result = $btn.siblings( '.tc-di-rename-result' );

        $result.text( i18n.checking || '' );

        $.post( ajax, {
            action:  'tc_di_check_rename',
            nonce:   nonce,
            term_id: termId,
        } ).done( function ( resp ) {
            $btn.hide();
            $result.empty();
            if ( ! resp.success ) {
                $result.text( ( resp.data && resp.data.message ) || ( i18n.error || 'Error.' ) );
                return;
            }
            renderOutcome( $result, termId, resp.data );
        } ).fail( function () {
            $btn.prop( 'disabled', false );
            $result.text( i18n.error || 'Request failed.' );
        } );
    } );

    // -------------------------------------------------------------------------
    // Pending redirects: follow / keep / end
    // -------------------------------------------------------------------------

    // Site Health loads test results asynchronously, so render whenever the
    // placeholders appear.
    function renderRedirectActions() {
        $( '.tc-di-redirect-actions' ).not( '.tc-rendered' ).each( function () {
            var $wrap  = $( this ).addClass( 'tc-rendered' );
            var termId = $wrap.data( 'term-id' );
            if ( '1' === String( $wrap.data( 'can-follow' ) ) ) {
                addActionBtn( $wrap, i18n.followRedirect || 'Follow redirect', function ( $btn ) {
                    fireAction( $btn, { action: 'tc_di_follow_redirect', nonce: nonce, term_id: termId } );
                } );
            }
            addActionBtn( $wrap, i18n.keepCurrent || 'Keep current delegation', function ( $btn ) {
                fireAction( $btn, { action: 'tc_di_decline_redirect', nonce: nonce, term_id: termId } );
            } );
            addActionBtn( $wrap, i18n.removeOnly || 'End delegation', function ( $btn ) {
                fireAction( $btn, { action: 'tc_di_remove', nonce: nonce, term_id: termId } );
            } );
        } );
    }
    $( renderRedirectActions );
    if ( window.MutationObserver ) {
        new MutationObserver( renderRedirectActions ).observe( document.body, { childList: true, subtree: true } );
    }

    // -------------------------------------------------------------------------
    // Outcome rendering
    // -------------------------------------------------------------------------

    function renderOutcome( $container, termId, data ) {
        var $wrap = $( '<div style="margin-top:6px">' );

        if ( 'A' === data.outcome ) {
            $wrap.append( $( '<span>' ).text( data.message || '' ) );
            addActionBtn( $wrap, i18n.removeOnly || 'Remove delegation', function ( $btn ) {
                fireAction( $btn, { action: 'tc_di_remove', nonce: nonce, term_id: termId } );
            } );

        } else if ( 'B' === data.outcome ) {
            var msgB = ( i18n.renamedTo || 'Remote term renamed to "%s".' ).replace( '%s', data.new_slug );
            $wrap.append( $( '<em style="display:block;margin-bottom:4px">' ).text( msgB ) );

            addActionBtn( $wrap, ( i18n.relinkRename || 'Re-link and rename local slug to "%s"' ).replace( '%s', data.new_slug ), function ( $btn ) {
                fireAction( $btn, { action: 'tc_di_relink_rename', nonce: nonce, term_id: termId, new_slug: data.new_slug } );
            } );
            addActionBtn( $wrap, i18n.addLinkOnThis || 'Add as external link on this term', function ( $btn ) {
                fireAction( $btn, {
                    action: 'tc_di_add_link', nonce: nonce,
                    remove_term_id: termId, link_term_id: termId,
                    peer_site_slug: data.peer_site_slug, new_slug: data.new_slug, new_name: data.new_name,
                } );
            } );
            addActionBtn( $wrap, i18n.removeOnly || 'Remove delegation', function ( $btn ) {
                fireAction( $btn, { action: 'tc_di_remove', nonce: nonce, term_id: termId } );
            } );

        } else if ( 'C' === data.outcome ) {
            var msgC = ( i18n.renamedToCollision || 'Remote term renamed to "%1$s", which already exists locally as "%2$s".' )
                .replace( '%1$s', data.new_slug )
                .replace( '%2$s', data.other_term_name );
            $wrap.append( $( '<em style="display:block;margin-bottom:4px">' ).text( msgC ) );

            addActionBtn( $wrap, ( i18n.delegateOther || 'Delegate "%s" instead' ).replace( '%s', data.other_term_name ), function ( $btn ) {
                fireAction( $btn, {
                    action: 'tc_di_redelegate', nonce: nonce,
                    remove_term_id: termId, target_term_id: data.other_term_id,
                    peer_site_slug: data.peer_site_slug, new_slug: data.new_slug,
                } );
            } );
            addActionBtn( $wrap, i18n.addLinkOnThis || 'Add as external link on this term', function ( $btn ) {
                fireAction( $btn, {
                    action: 'tc_di_add_link', nonce: nonce,
                    remove_term_id: termId, link_term_id: termId,
                    peer_site_slug: data.peer_site_slug, new_slug: data.new_slug, new_name: data.new_name,
                } );
            } );
            addActionBtn( $wrap, ( i18n.addLinkOnOther || 'Add as external link on "%s"' ).replace( '%s', data.other_term_name ), function ( $btn ) {
                fireAction( $btn, {
                    action: 'tc_di_add_link', nonce: nonce,
                    remove_term_id: termId, link_term_id: data.other_term_id,
                    peer_site_slug: data.peer_site_slug, new_slug: data.new_slug, new_name: data.new_name,
                } );
            } );
            addActionBtn( $wrap, i18n.removeOnly || 'Remove delegation', function ( $btn ) {
                fireAction( $btn, { action: 'tc_di_remove', nonce: nonce, term_id: termId } );
            } );
        }

        $container.append( $wrap );
    }

    function addActionBtn( $container, label, handler ) {
        var $btn = $( '<button type="button" class="button button-small">' )
            .text( label )
            .css( { margin: '4px 4px 0 0' } );
        $btn.on( 'click', function () { handler( $btn ); } );
        $container.append( $btn );
    }

    function fireAction( $btn, payload ) {
        var origText = $btn.text();
        $btn.prop( 'disabled', true ).text( '…' );

        $.post( ajax, payload ).done( function ( resp ) {
            if ( resp.success ) {
                window.location.reload();
            } else {
                $btn.prop( 'disabled', false ).text( origText );
                var $status = $btn.closest( 'div' ).find( '.tc-di-action-err' );
                if ( ! $status.length ) {
                    $status = $( '<span class="tc-di-action-err" style="color:#d63638;margin-left:6px">' ).appendTo( $btn.parent() );
                }
                $status.text( ( resp.data && resp.data.message ) || ( i18n.error || 'Error.' ) );
            }
        } ).fail( function () {
            $btn.prop( 'disabled', false ).text( origText );
        } );
    }

} )( jQuery );
