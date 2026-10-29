/* global TC_ExtPosts */
( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '.tc-load-more', function () {
		var btn    = $( this );
		var wrap   = btn.closest( '.tc-post-list' );
		var items  = wrap.find( '.tc-post-list-items' );
		var pager  = btn.closest( '.tc-pagination' );

		if ( btn.data( 'loading' ) ) {
			return;
		}

		btn.data( 'loading', true ).prop( 'disabled', true );

		$.post(
			TC_ExtPosts.ajax_url,
			{
				action:  'tc_external_load_more',
				nonce:   TC_ExtPosts.nonce,
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
