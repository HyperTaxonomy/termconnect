/* global TC_PeerBrowse */
/**
 * Interactive topic browser for [tc_peer_browse].
 *
 * Vanilla JS (no jQuery). For each .tc-peer-browse widget: term-pills with
 * autocomplete, Any/All + source + sort controls, and a Load-more button. Every
 * facet change re-queries from offset 0; Load-more appends the next page.
 */
( function () {
	'use strict';

	var i18n = ( TC_PeerBrowse && TC_PeerBrowse.i18n ) || {};

	function post( params ) {
		var body = new URLSearchParams();
		Object.keys( params ).forEach( function ( k ) {
			body.append( k, params[ k ] );
		} );
		return fetch( TC_PeerBrowse.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function debounce( fn, wait ) {
		var t;
		return function () {
			var ctx = this;
			var a = arguments;
			clearTimeout( t );
			t = setTimeout( function () {
				fn.apply( ctx, a );
			}, wait );
		};
	}

	function Browser( root ) {
		this.root     = root;
		this.nonce    = root.getAttribute( 'data-nonce' );
		this.taxonomy = root.getAttribute( 'data-taxonomy' );
		this.atts     = root.getAttribute( 'data-atts' ) || '{}';
		this.columns  = parseInt( root.getAttribute( 'data-columns' ), 10 ) || 1;
		this.thumbRatio = root.getAttribute( 'data-thumb-ratio' ) || '';

		this.pillbox  = root.querySelector( '.tc-pb-pillbox' );
		this.input    = root.querySelector( '.tc-pb-input' );
		this.suggest  = root.querySelector( '.tc-pb-suggest' );
		this.results  = root.querySelector( '.tc-pb-results' );

		this.match    = root.getAttribute( 'data-match' ) || 'any';
		this.scope    = root.getAttribute( 'data-scope' ) || 'all';
		this.orderby  = root.getAttribute( 'data-orderby' ) || 'modified';
		this.offset   = 0;
		this.active   = -1; // highlighted suggestion index

		this.bind();
	}

	Browser.prototype.selectedIds = function () {
		return Array.prototype.map.call(
			this.pillbox.querySelectorAll( '.tc-pb-pill' ),
			function ( p ) {
				return p.getAttribute( 'data-id' );
			}
		);
	};

	Browser.prototype.hasPill = function ( id ) {
		return !! this.pillbox.querySelector( '.tc-pb-pill[data-id="' + id + '"]' );
	};

	Browser.prototype.addPill = function ( id, name ) {
		if ( this.hasPill( id ) ) {
			return;
		}
		var pill = document.createElement( 'span' );
		pill.className = 'tc-pb-pill';
		pill.setAttribute( 'data-id', id );
		pill.appendChild( document.createTextNode( name ) );
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'tc-pb-remove';
		btn.setAttribute( 'aria-label', i18n.remove || 'Remove' );
		btn.innerHTML = '&times;';
		pill.appendChild( btn );
		this.pillbox.insertBefore( pill, this.input );
	};

	Browser.prototype.closeSuggest = function () {
		this.suggest.hidden = true;
		this.suggest.innerHTML = '';
		this.active = -1;
		this.input.setAttribute( 'aria-expanded', 'false' );
	};

	Browser.prototype.renderSuggest = function ( items ) {
		var self = this;
		this.suggest.innerHTML = '';
		this.active = -1;
		if ( ! items.length ) {
			this.closeSuggest();
			return;
		}
		items.forEach( function ( it ) {
			if ( self.hasPill( String( it.id ) ) ) {
				return;
			}
			var li = document.createElement( 'li' );
			li.className = 'tc-pb-suggest-item';
			li.setAttribute( 'role', 'option' );
			li.setAttribute( 'data-id', it.id );
			li.textContent = it.name;
			li.addEventListener( 'mousedown', function ( e ) {
				e.preventDefault();
				self.choose( it.id, it.name );
			} );
			self.suggest.appendChild( li );
		} );
		if ( this.suggest.children.length ) {
			this.suggest.hidden = false;
			this.input.setAttribute( 'aria-expanded', 'true' );
		} else {
			this.closeSuggest();
		}
	};

	Browser.prototype.choose = function ( id, name ) {
		this.addPill( String( id ), name );
		this.input.value = '';
		this.closeSuggest();
		this.query( true );
	};

	Browser.prototype.fetchSuggest = function () {
		var self = this;
		var q = this.input.value.trim();
		post( {
			action: 'tc_peer_browse_terms',
			nonce: this.nonce,
			taxonomy: this.taxonomy,
			q: q,
		} ).then( function ( res ) {
			if ( res && res.success ) {
				self.renderSuggest( res.data || [] );
			}
		} ).catch( function () {} );
	};

	Browser.prototype.ensureList = function () {
		var list = this.results.querySelector( '.tc-peer-browse-list' );
		if ( ! list ) {
			this.results.innerHTML = '';
			list = document.createElement( 'div' );
			list.className = 'tc-post-list tc-peer-browse-list';
			var items = document.createElement( 'div' );
			items.className = 'tc-post-list-items';
			if ( this.columns > 1 ) {
				items.style.setProperty( '--tc-columns', this.columns );
			}
			if ( this.thumbRatio ) {
				items.style.setProperty( '--tc-thumb-ratio', this.thumbRatio );
			}
			var pager = document.createElement( 'div' );
			pager.className = 'tc-pb-pager';
			list.appendChild( items );
			list.appendChild( pager );
			this.results.appendChild( list );
		}
		return {
			items: list.querySelector( '.tc-post-list-items' ),
			pager: list.querySelector( '.tc-pb-pager' ),
		};
	};

	Browser.prototype.setPrompt = function ( text ) {
		this.results.innerHTML = '<p class="tc-pb-empty">' + text + '</p>';
	};

	Browser.prototype.setPager = function ( pager, hasMore, nextOffset ) {
		pager.innerHTML = '';
		if ( hasMore ) {
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'tc-pb-more';
			btn.setAttribute( 'data-offset', nextOffset );
			btn.textContent = i18n.load_more || 'Load more';
			pager.appendChild( btn );
		}
	};

	Browser.prototype.query = function ( reset ) {
		var self = this;
		if ( reset ) {
			this.offset = 0;
		}

		var ids = this.selectedIds();
		if ( ! ids.length ) {
			this.setPrompt( i18n.empty || '' );
			return;
		}

		this.root.classList.add( 'tc-pb-loading' );

		post( {
			action: 'tc_peer_browse',
			nonce: this.nonce,
			terms: ids.join( ',' ),
			match: this.match,
			scope: this.scope,
			orderby: this.orderby,
			offset: this.offset,
			atts: this.atts,
		} ).then( function ( res ) {
			self.root.classList.remove( 'tc-pb-loading' );
			if ( ! res || ! res.success ) {
				return;
			}
			var data = res.data || {};

			if ( data.no_terms ) {
				self.setPrompt( i18n.empty || '' );
				return;
			}

			if ( reset && ! data.html ) {
				self.results.innerHTML = data.no_results
					? data.no_results
					: '<p class="tc-pb-empty">' + ( i18n.none || '' ) + '</p>';
				return;
			}

			var nodes = self.ensureList();
			if ( reset ) {
				nodes.items.innerHTML = data.html || '';
			} else if ( data.html ) {
				nodes.items.insertAdjacentHTML( 'beforeend', data.html );
			}
			self.offset = data.next_offset;
			self.setPager( nodes.pager, !! data.has_more, data.next_offset );
		} ).catch( function () {
			self.root.classList.remove( 'tc-pb-loading' );
		} );
	};

	Browser.prototype.moveActive = function ( delta ) {
		var opts = this.suggest.querySelectorAll( '.tc-pb-suggest-item' );
		if ( ! opts.length ) {
			return;
		}
		if ( this.active >= 0 && opts[ this.active ] ) {
			opts[ this.active ].classList.remove( 'is-active' );
		}
		this.active += delta;
		if ( this.active < 0 ) {
			this.active = opts.length - 1;
		}
		if ( this.active >= opts.length ) {
			this.active = 0;
		}
		opts[ this.active ].classList.add( 'is-active' );
	};

	Browser.prototype.bind = function () {
		var self = this;

		var debounced = debounce( function () {
			self.fetchSuggest();
		}, 220 );

		this.input.addEventListener( 'input', debounced );
		this.input.addEventListener( 'focus', function () {
			if ( self.input.value.trim() ) {
				debounced();
			}
		} );

		this.input.addEventListener( 'keydown', function ( e ) {
			if ( self.suggest.hidden ) {
				return;
			}
			if ( 'ArrowDown' === e.key ) {
				e.preventDefault();
				self.moveActive( 1 );
			} else if ( 'ArrowUp' === e.key ) {
				e.preventDefault();
				self.moveActive( -1 );
			} else if ( 'Enter' === e.key ) {
				var opts = self.suggest.querySelectorAll( '.tc-pb-suggest-item' );
				if ( self.active >= 0 && opts[ self.active ] ) {
					e.preventDefault();
					self.choose( opts[ self.active ].getAttribute( 'data-id' ), opts[ self.active ].textContent );
				}
			} else if ( 'Escape' === e.key ) {
				self.closeSuggest();
			}
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( ! self.root.contains( e.target ) ) {
				self.closeSuggest();
			}
		} );

		// Remove pill (delegated).
		this.pillbox.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.tc-pb-remove' );
			if ( ! btn ) {
				return;
			}
			var pill = btn.closest( '.tc-pb-pill' );
			if ( pill ) {
				pill.parentNode.removeChild( pill );
				self.query( true );
			}
		} );

		// Match radios.
		Array.prototype.forEach.call( this.root.querySelectorAll( '.tc-pb-match' ), function ( radio ) {
			radio.addEventListener( 'change', function () {
				if ( radio.checked ) {
					self.match = radio.value;
					self.query( true );
				}
			} );
		} );

		// Scope select.
		var scopeSel = this.root.querySelector( '.tc-pb-scope' );
		if ( scopeSel ) {
			scopeSel.addEventListener( 'change', function () {
				self.scope = scopeSel.value;
				self.query( true );
			} );
		}

		// Sort select.
		var sortSel = this.root.querySelector( '.tc-pb-sort' );
		if ( sortSel ) {
			sortSel.addEventListener( 'change', function () {
				self.orderby = sortSel.value;
				self.query( true );
			} );
		}

		// Load more (delegated to results).
		this.results.addEventListener( 'click', function ( e ) {
			var more = e.target.closest( '.tc-pb-more' );
			if ( ! more ) {
				return;
			}
			self.offset = parseInt( more.getAttribute( 'data-offset' ), 10 ) || self.offset;
			self.query( false );
		} );
	};

	function init() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '.tc-peer-browse' ),
			function ( root ) {
				if ( ! root.tcPeerBrowse ) {
					root.tcPeerBrowse = new Browser( root );
				}
			}
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
