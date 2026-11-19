/* global TConn_ExtPosts */
( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '.tconn-load-more', function () {
		var btn    = $( this );
		var wrap   = btn.closest( '.tconn-post-list' );
		var items  = wrap.find( '.tconn-post-list-items' );
		var pager  = btn.closest( '.tconn-pagination' );

		if ( btn.data( 'loading' ) ) {
			return;
		}

		btn.data( 'loading', true ).prop( 'disabled', true );

		$.post(
			TConn_ExtPosts.ajax_url,
			{
				action:  'tconn_external_load_more',
				nonce:   TConn_ExtPosts.nonce,
				post_id: btn.data( 'post-id' ),
				offset:  btn.data( 'offset' ),
				atts:    JSON.stringify( btn.data( 'atts' ) ),
			},
			function ( response ) {
				btn.data( 'loading', false ).prop( 'disabled', false );

				if ( ! response.success ) {
					return;
				}

				if ( response.data.html ) {
					items.append( response.data.html );
				}

				if ( response.data.has_more ) {
					btn.data( 'offset', response.data.next_offset );
				} else {
					pager.remove();
				}
			}
		);
	} );
} )( jQuery );
