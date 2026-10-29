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
import '../../shared/editor.css';

const toBool   = ( str ) => 'true' === str;
const fromBool = ( b )   => ( b ? 'true' : 'false' );

function Edit( { attributes, setAttributes } ) {
	const {
		link_types,
		taxonomy,
		match,
		scope,
		orderby,
		per_page,
		max_results,
		foaf_depth,
		require_ht,
		include_synonyms,
		include_related,
		peer_expand,
		show_thumbnail,
		show_excerpt,
		image_size,
		columns,
		format,
		template,
		meta,
		prepend,
		append,
		cache_ttl,
		no_results_message,
		class: cssClass,
	} = attributes;

	const { taxonomies } = useSelect(
		( select ) => ( {
			taxonomies:
				select( coreStore ).getTaxonomies( { per_page: -1, show_in_rest: true } ) ?? [],
		} ),
		[]
	);

	const taxonomyOptions = [
		{ label: __( '— All enabled taxonomies —', 'termconnect' ), value: '' },
		...taxonomies.map( ( t ) => ( { label: t.name, value: t.slug } ) ),
	];

	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>

				{ /* ── Source ─────────────────────────────────────────── */ }
				<PanelBody
					title={ __( 'Source', 'termconnect' ) }
					initialOpen={ true }
				>
					<PeerChecklist
						label={ __( 'Peers', 'termconnect' ) }
						help={ __( 'Select which peers to query. Leave all unchecked to use every configured peer.', 'termconnect' ) }
						value={ link_types }
						onChange={ ( val ) => setAttributes( { link_types: val } ) }
					/>
					<SelectControl
						label={ __( 'Taxonomy', 'termconnect' ) }
						help={ __( 'Restrict to one taxonomy. Leave empty to use all.', 'termconnect' ) }
						value={ taxonomy }
						options={ taxonomyOptions }
						onChange={ ( val ) => setAttributes( { taxonomy: val } ) }
					/>
				</PanelBody>

				{ /* ── Query ─────────────────────────────────────────── */ }
				<PanelBody
					title={ __( 'Query', 'termconnect' ) }
					initialOpen={ false }
				>
					<SelectControl
						label={ __( 'Match mode', 'termconnect' ) }
						help={ __(
							'Any: return posts matching at least one term. All: posts must match all primary terms.',
							'termconnect'
						) }
						value={ match }
						options={ [
							{ label: __( 'Any (OR)', 'termconnect' ), value: 'any' },
							{ label: __( 'All (AND)', 'termconnect' ), value: 'all' },
						] }
						onChange={ ( val ) => setAttributes( { match: val } ) }
					/>
					<SelectControl
						label={ __( 'Source', 'termconnect' ) }
						help={ __(
							'Peers only, or blend in this site’s own matching posts (local relevance-ranked via HyperTaxonomy when active).',
							'termconnect'
						) }
						value={ scope }
						options={ [
							{ label: __( 'Peers only', 'termconnect' ), value: 'peers' },
							{ label: __( 'Local + peers', 'termconnect' ), value: 'all' },
						] }
						onChange={ ( val ) => setAttributes( { scope: val } ) }
					/>
					<SelectControl
						label={ __( 'Order by', 'termconnect' ) }
						value={ orderby }
						options={ [
							{ label: __( 'Score (relevance)', 'termconnect' ),           value: 'score' },
							{ label: __( 'Group by peer, then relevance', 'termconnect' ), value: 'peer:asc,relevance:desc' },
							{ label: __( 'Date published (newest first)', 'termconnect' ),         value: 'date' },
							{ label: __( 'Modified date', 'termconnect' ),               value: 'modified' },
							{ label: __( 'Title (A–Z)', 'termconnect' ),                 value: 'title' },
							{ label: __( 'Relevance (post title search)', 'termconnect' ), value: 'relevance' },
						] }
						onChange={ ( val ) => setAttributes( { orderby: val } ) }
					/>
					<TextControl
						label={ __( 'Results per page', 'termconnect' ) }
						type="number"
						min="1"
						max="50"
						value={ per_page }
						onChange={ ( val ) => setAttributes( { per_page: val } ) }
					/>
					<TextControl
						label={ __( 'Max results (0 = use default)', 'termconnect' ) }
						help={ __(
							'Total pages to fetch and cache (fetch size = max pages × per page). Extra pages are served via load-more without a new request to the peer site.',
							'termconnect'
						) }
						type="number"
						min="1"
						max="20"
						value={ max_results }
						onChange={ ( val ) => setAttributes( { max_results: val } ) }
					/>
				</PanelBody>

				{ /* ── Peer Network ───────────────────────────────────── */ }
				<PanelBody
					title={ __( 'Peer Network', 'termconnect' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __( 'FOAF depth', 'termconnect' ) }
						help={ __(
							'Follow peer connections discovered from post responses. 0 = disabled. Capped at the global Max FOAF Depth setting.',
							'termconnect'
						) }
						type="number"
						min="0"
						max="3"
						value={ foaf_depth }
						onChange={ ( val ) => setAttributes( { foaf_depth: val } ) }
					/>
					<ToggleControl
						label={ __( 'Require HyperTaxonomy peers only', 'termconnect' ) }
						help={ __(
							'Skip standard WordPress peers. Only query sites running HyperTaxonomy, which provide server-side relevance scoring.',
							'termconnect'
						) }
						checked={ toBool( require_ht ) }
						onChange={ ( val ) => setAttributes( { require_ht: fromBool( val ) } ) }
					/>
					<ToggleControl
						label={ __( 'Include local synonyms', 'termconnect' ) }
						help={ __(
							'Resolve local synonym terms to their remote equivalents and send them as additional primary terms. Synonyms receive the same direct-match score as the primary queried term.',
							'termconnect'
						) }
						checked={ toBool( include_synonyms ) }
						onChange={ ( val ) => setAttributes( { include_synonyms: fromBool( val ) } ) }
					/>
					<ToggleControl
						label={ __( 'Include related terms', 'termconnect' ) }
						help={ __(
							'Resolve locally included (non-synonym) terms to their remote equivalents and send them alongside the primary terms. Matched at a lower weight than direct or synonym matches.',
							'termconnect'
						) }
						checked={ toBool( include_related ) }
						onChange={ ( val ) => setAttributes( { include_related: fromBool( val ) } ) }
					/>
					<ToggleControl
						label={ __( 'Let peers expand via their own relationships', 'termconnect' ) }
						help={ __(
							'When on, each peer also matches the queried terms through its own relationship graph (not just direct assignments), surfacing more of its content. Off by default for predictable results.',
							'termconnect'
						) }
						checked={ toBool( peer_expand ) }
						onChange={ ( val ) => setAttributes( { peer_expand: fromBool( val ) } ) }
					/>
				</PanelBody>

				{ /* ── Display ─────────────────────────────────────────── */ }
				<PanelBody
					title={ __( 'Display', 'termconnect' ) }
					initialOpen={ false }
				>
					<ToggleControl
						label={ __( 'Show thumbnail', 'termconnect' ) }
						checked={ toBool( show_thumbnail ) }
						onChange={ ( val ) => setAttributes( { show_thumbnail: fromBool( val ) } ) }
					/>
					<ToggleControl
						label={ __( 'Show excerpt', 'termconnect' ) }
						checked={ toBool( show_excerpt ) }
						onChange={ ( val ) => setAttributes( { show_excerpt: fromBool( val ) } ) }
					/>
					<TextControl
						type="number"
						min="1"
						label={ __( 'Columns', 'termconnect' ) }
						value={ columns }
						onChange={ ( val ) => setAttributes( { columns: val } ) }
					/>
					<TextControl
						label={ __( 'Image size', 'termconnect' ) }
						help={ __( 'Registered image size; sets the uniform thumbnail aspect.', 'termconnect' ) }
						value={ image_size }
						onChange={ ( val ) => setAttributes( { image_size: val } ) }
					/>
					<SelectControl
						label={ __( 'Format', 'termconnect' ) }
						value={ format }
						options={ [
							{ label: __( 'Cards (div)', 'termconnect' ), value: 'div' },
							{ label: __( 'Bulleted list (ul)', 'termconnect' ), value: 'ul' },
							{ label: __( 'Numbered list (ol)', 'termconnect' ), value: 'ol' },
						] }
						onChange={ ( val ) => setAttributes( { format: val } ) }
					/>
					<TextControl
						label={ __( 'Template (style variant)', 'termconnect' ) }
						help={ __( 'CSS class suffix added as tc-result-{template}.', 'termconnect' ) }
						value={ template }
						onChange={ ( val ) => setAttributes( { template: val } ) }
					/>
					<TextControl
						label={ __( 'Meta line', 'termconnect' ) }
						help={ __(
							'Template for the meta line below each title. Tokens: {date}, {label}, {hostname}, {origin}. Leave empty to hide.',
							'termconnect'
						) }
						value={ meta }
						onChange={ ( val ) => setAttributes( { meta: val } ) }
					/>
					<TextControl
						label={ __( 'Prepend HTML (per item)', 'termconnect' ) }
						help={ __(
							'HTML inserted before each result item. Tokens: {title}, {url}, {date}, {excerpt}, {label}, {hostname}, {origin}.',
							'termconnect'
						) }
						value={ prepend }
						onChange={ ( val ) => setAttributes( { prepend: val } ) }
					/>
					<TextControl
						label={ __( 'Append HTML (per item)', 'termconnect' ) }
						help={ __(
							'HTML inserted after each result item. Same tokens as prepend.',
							'termconnect'
						) }
						value={ append }
						onChange={ ( val ) => setAttributes( { append: val } ) }
					/>
					<TextControl
						label={ __( 'No-results message', 'termconnect' ) }
						help={ __( 'Shown when no peer posts are found. Leave empty to render nothing.', 'termconnect' ) }
						value={ no_results_message }
						onChange={ ( val ) => setAttributes( { no_results_message: val } ) }
					/>
					<TextControl
						label={ __( 'Additional CSS class', 'termconnect' ) }
						value={ cssClass }
						onChange={ ( val ) => setAttributes( { class: val } ) }
					/>
				</PanelBody>

				{ /* ── Advanced ─────────────────────────────────────────── */ }
				<PanelBody
					title={ __( 'Advanced', 'termconnect' ) }
					initialOpen={ false }
				>
					<TextControl
						label={ __( 'Cache TTL (seconds)', 'termconnect' ) }
						help={ __(
							'Leave empty to use the global External Posts Cache TTL from Settings → External Posts.',
							'termconnect'
						) }
						type="number"
						min="60"
						value={ cache_ttl }
						onChange={ ( val ) => setAttributes( { cache_ttl: val } ) }
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
