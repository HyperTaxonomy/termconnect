/**
 * Shared peer-picker for TermConnect blocks.
 *
 * Renders a checkbox per configured peer instead of asking the author to type
 * link-type slugs. The peer list is localized into window.TCPeers by PHP
 * (display.php → tc_get_browsable_peers()) when the block editor loads, so there
 * is no runtime REST call. The selection is stored as a comma-separated slug
 * string in a block attribute; an empty value means "all configured peers".
 */

import { __ } from '@wordpress/i18n';
import { CheckboxControl } from '@wordpress/components';

export function PeerChecklist( { value, onChange, label, help } ) {
	const peers =
		typeof window !== 'undefined' && Array.isArray( window.TCPeers )
			? window.TCPeers
			: [];

	const selected = ( value || '' )
		.split( ',' )
		.map( ( s ) => s.trim() )
		.filter( Boolean );

	const toggle = ( slug, on ) => {
		const set = new Set( selected );
		if ( on ) {
			set.add( slug );
		} else {
			set.delete( slug );
		}
		onChange( Array.from( set ).join( ',' ) );
	};

	return (
		<div className="tc-peer-checklist">
			{ label && (
				<p className="tc-peer-checklist__label" style={ { marginBottom: '4px', fontWeight: 600 } }>
					{ label }
				</p>
			) }
			{ help && (
				<p className="tc-peer-checklist__help" style={ { margin: '0 0 8px', fontSize: '12px', fontStyle: 'italic', opacity: 0.7 } }>
					{ help }
				</p>
			) }
			{ peers.length === 0 && (
				<p style={ { fontStyle: 'italic', opacity: 0.7 } }>
					{ __( 'No peers configured yet.', 'termconnect' ) }
				</p>
			) }
			{ peers.map( ( p ) => (
				<CheckboxControl
					key={ p.slug }
					label={ 'feed' === p.platform ? p.label + ' ' + __( '(feed)', 'termconnect' ) : p.label }
					checked={ selected.includes( p.slug ) }
					onChange={ ( on ) => toggle( p.slug, on ) }
				/>
			) ) }
		</div>
	);
}
