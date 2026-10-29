import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Disabled,
	PanelBody,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import metadata from '../block.json';
import { PeerChecklist } from '../../shared/peer-checklist.js';

const toBool   = ( str ) => 'true' === str;
const fromBool = ( b )   => ( b ? 'true' : 'false' );

function Edit( { attributes, setAttributes } ) {
	const {
		taxonomy,
		terms,
		match,
		scope,
		orderby,
		peers,
		peer_expand,
		platforms,
		foaf_depth,
		per_page,
		max_results,
		columns,
		show_thumbnail,
		show_excerpt,
		image_size,
		meta,
		prepend,
		append,
		no_results_message,
		class: cssClass,
		show_match,
		show_scope,
		show_sort,
	} = attributes;

	const { taxonomies } = useSelect(
		( select ) => ( {
			taxonomies:
				select( coreStore ).getTaxonomies( { per_page: -1, show_in_rest: true } ) ?? [],
		} ),
		[]
	);

	const taxonomyOptions = taxonomies.length
		? taxonomies.map( ( t ) => ( { label: t.name, value: t.slug } ) )
		: [ { label: 'post_tag', value: 'post_tag' } ];

	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Query', 'termconnect' ) }>
					<SelectControl
						label={ __( 'Taxonomy', 'termconnect' ) }
						value={ taxonomy }
						options={ taxonomyOptions }
						onChange={ ( v ) => setAttributes( { taxonomy: v } ) }
						help={ __( 'Peers resolve topics for TermConnect-enabled taxonomies; any public taxonomy works for local results.', 'termconnect' ) }
					/>
					<TextControl
						label={ __( 'Initial topics', 'termconnect' ) }
						value={ terms }
						onChange={ ( v ) => setAttributes( { terms: v } ) }
						help={ __( 'Optional. Comma-separated term IDs or slugs to pre-select.', 'termconnect' ) }
					/>
					<SelectControl
						label={ __( 'Default match', 'termconnect' ) }
						value={ match }
						options={ [
							{ label: __( 'Any (OR)', 'termconnect' ), value: 'any' },
							{ label: __( 'All (AND)', 'termconnect' ), value: 'all' },
						] }
						onChange={ ( v ) => setAttributes( { match: v } ) }
					/>
					<SelectControl
						label={ __( 'Default source', 'termconnect' ) }
						value={ scope }
						options={ [
							{ label: __( 'Local + peers', 'termconnect' ), value: 'all' },
							{ label: __( 'Peers only', 'termconnect' ), value: 'peers' },
						] }
						onChange={ ( v ) => setAttributes( { scope: v } ) }
					/>
					<SelectControl
						label={ __( 'Default sort', 'termconnect' ) }
						value={ orderby }
						options={ [
							{ label: __( 'Recently updated', 'termconnect' ), value: 'modified' },
							{ label: __( 'Newest', 'termconnect' ), value: 'date' },
							{ label: __( 'Title', 'termconnect' ), value: 'title' },
								{ label: __( 'Relevance (grouped by site)', 'termconnect' ), value: 'peer:asc,relevance:desc' },
						] }
						onChange={ ( v ) => setAttributes( { orderby: v } ) }
					/>
					<PeerChecklist
						label={ __( 'Peers', 'termconnect' ) }
						help={ __( 'Restrict to specific peers. Leave all unchecked to query every configured peer.', 'termconnect' ) }
						value={ peers }
						onChange={ ( v ) => setAttributes( { peers: v } ) }
					/>
					<ToggleControl
						label={ __( 'Let peers expand via their own relationships', 'termconnect' ) }
						help={ __( 'Each peer also matches via its own relationship graph, surfacing more content. Off by default.', 'termconnect' ) }
						checked={ toBool( peer_expand ) }
						onChange={ ( b ) => setAttributes( { peer_expand: fromBool( b ) } ) }
					/>
					<TextControl
						label={ __( 'Platforms', 'termconnect' ) }
						value={ platforms }
						onChange={ ( v ) => setAttributes( { platforms: v } ) }
						help={ __( 'Comma-separated (e.g. wordpress,feed). Empty = all supported.', 'termconnect' ) }
					/>
					<TextControl
						type="number"
						min="0"
						label={ __( 'Max FOAF depth', 'termconnect' ) }
						value={ foaf_depth }
						onChange={ ( v ) => setAttributes( { foaf_depth: v } ) }
					/>
					<TextControl
						type="number"
						min="1"
						label={ __( 'Posts per page', 'termconnect' ) }
						value={ per_page }
						onChange={ ( v ) => setAttributes( { per_page: v } ) }
					/>
					<TextControl
						type="number"
						min="1"
						label={ __( 'Max results', 'termconnect' ) }
						value={ max_results }
						onChange={ ( v ) => setAttributes( { max_results: v } ) }
						help={ __( 'Cap on the total number of results the visitor can page through.', 'termconnect' ) }
					/>
				</PanelBody>

				<PanelBody title={ __( 'Visitor controls', 'termconnect' ) } initialOpen={ false }>
					<ToggleControl
						label={ __( 'Show Match (Any/All)', 'termconnect' ) }
						checked={ toBool( show_match ) }
						onChange={ ( b ) => setAttributes( { show_match: fromBool( b ) } ) }
					/>
					<ToggleControl
						label={ __( 'Show Source (local/peers)', 'termconnect' ) }
						checked={ toBool( show_scope ) }
						onChange={ ( b ) => setAttributes( { show_scope: fromBool( b ) } ) }
					/>
					<ToggleControl
						label={ __( 'Show Sort', 'termconnect' ) }
						checked={ toBool( show_sort ) }
						onChange={ ( b ) => setAttributes( { show_sort: fromBool( b ) } ) }
					/>
				</PanelBody>

				<PanelBody title={ __( 'Display', 'termconnect' ) } initialOpen={ false }>
					<TextControl
						type="number"
						min="1"
						label={ __( 'Columns', 'termconnect' ) }
						value={ columns }
						onChange={ ( v ) => setAttributes( { columns: v } ) }
					/>
					<ToggleControl
						label={ __( 'Show thumbnail', 'termconnect' ) }
						checked={ toBool( show_thumbnail ) }
						onChange={ ( b ) => setAttributes( { show_thumbnail: fromBool( b ) } ) }
					/>
					<ToggleControl
						label={ __( 'Show excerpt', 'termconnect' ) }
						checked={ toBool( show_excerpt ) }
						onChange={ ( b ) => setAttributes( { show_excerpt: fromBool( b ) } ) }
					/>
					<TextControl
						label={ __( 'Image size', 'termconnect' ) }
						value={ image_size }
						onChange={ ( v ) => setAttributes( { image_size: v } ) }
					/>
					<TextControl
						label={ __( 'Meta template', 'termconnect' ) }
						value={ meta }
						onChange={ ( v ) => setAttributes( { meta: v } ) }
						help={ __( 'Tokens: {date} {modified} {label} {hostname} {author}.', 'termconnect' ) }
					/>
					<TextControl
						label={ __( 'Prepend', 'termconnect' ) }
						value={ prepend }
						onChange={ ( v ) => setAttributes( { prepend: v } ) }
					/>
					<TextControl
						label={ __( 'Append', 'termconnect' ) }
						value={ append }
						onChange={ ( v ) => setAttributes( { append: v } ) }
					/>
					<TextControl
						label={ __( 'No-results message', 'termconnect' ) }
						value={ no_results_message }
						onChange={ ( v ) => setAttributes( { no_results_message: v } ) }
					/>
					<TextControl
						label={ __( 'CSS class', 'termconnect' ) }
						value={ cssClass }
						onChange={ ( v ) => setAttributes( { class: v } ) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<Disabled>
					<ServerSideRender
						block={ metadata.name }
						attributes={ attributes }
					/>
				</Disabled>
			</div>
		</>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
