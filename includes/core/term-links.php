<?php
/**
 * Term Links
 *
 * Registry, storage, and public API for external links on terms.
 * Link types (namespaces such as Wikidata, GeoNames) are stored in the
 * tc_link_types option as a flat array of definition arrays.
 * Per-term values are stored as _tc_link_{slug} term meta.
 *
 * @package TermConnect
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==============================================================================
// HT INTEGRATION HELPERS
// Thin wrappers that delegate to HyperTaxonomy when active; safe no-ops otherwise.
// ==============================================================================

/**
 * Check whether a term is a redirect term (HyperTaxonomy concept).
 *
 * @param int $term_id
 * @return bool True if HT is active and the term has an outgoing redirect.
 */
function tc_is_redirect(int $term_id): bool
{
    return function_exists('htax_is_redirect') && htax_is_redirect($term_id);
}

/**
 * Return IDs of all terms that have an outgoing HT redirect.
 * Used to suppress external link data for redirect stubs.
 *
 * @return int[]
 */
function tc_get_all_redirect_term_ids(): array
{
    if ( ! function_exists( 'htax_is_redirect' ) ) {
        return array();
    }
    global $wpdb;
    $table = $wpdb->prefix . 'htax_term_relationships';
    if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) ) {
        return array();
    }
    return array_map(
        'intval',
        $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT source_term_id FROM {$table} WHERE relationship_type = %s",
            'redirect'
        ) )
    );
}

// ==============================================================================
// BUILT-IN TYPE DEFINITIONS
// ==============================================================================

/**
 * Returns hardcoded definitions for all built-in link types.
 *
 * Used to seed tc_link_types on activation and to back-fill any types
 * added in later versions that are not yet in the stored option.
 * All built-in types ship disabled; admins enable the ones relevant to their content.
 *
 * @since 1.0.0
 * @return array[] Indexed array of link type definition arrays.
 */
function tc_get_builtin_link_types(): array {
    return array(

        // --- General reference ---
        array(
            'slug'             => 'wikidata',
            'label'            => 'Wikidata',
            'uri_prefix'       => 'https://www.wikidata.org/wiki/',
            'description'      => 'General external service',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => false,
            'built_in'         => true,
        ),
        array(
            'slug'             => 'wikipedia',
            'label'            => 'Wikipedia',
            'uri_prefix'       => 'https://en.wikipedia.org/wiki/',
            'description'      => 'General encyclopedia',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => true,
            'built_in'         => true,
        ),
        array(
            'slug'             => 'grokipedia',
            'label'            => 'Grokipedia',
            'uri_prefix'       => 'https://grokipedia.com/page/',
            'description'      => 'AI-powered general encyclopedia (xAI)',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => true,
            'built_in'         => true,
        ),
        array(
            'slug'             => 'wikiquote',
            'label'            => 'Wikiquote',
            'uri_prefix'       => 'https://en.wikiquote.org/wiki/',
            'description'      => 'Quotations by people and works',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => true,
            'built_in'         => true,
        ),

        // --- Language ---
        array(
            'slug'             => 'wiktionary',
            'label'            => 'Wiktionary',
            'uri_prefix'       => 'https://en.wiktionary.org/wiki/',
            'description'      => 'Multilingual dictionary and thesaurus',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => true,
            'built_in'         => true,
        ),
        array(
            'slug'             => 'merriam-webster',
            'label'            => 'Merriam-Webster',
            'uri_prefix'       => 'https://www.merriam-webster.com/dictionary/',
            'description'      => 'English dictionary',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => true,
            'built_in'         => true,
        ),

        // --- Geography ---
        array(
            'slug'             => 'geonames',
            'label'            => 'GeoNames',
            'uri_prefix'       => 'https://www.geonames.org/',
            'description'      => 'Geographic names and locations',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => false,
            'built_in'         => true,
        ),
        array(
            'slug'             => 'openstreetmap',
            'label'            => 'OpenStreetMap',
            'uri_prefix'       => 'https://www.openstreetmap.org/',
            'description'      => 'Open collaborative map',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => true,
            'built_in'         => true,
        ),
        array(
            'slug'             => 'wikivoyage',
            'label'            => 'Wikivoyage',
            'uri_prefix'       => 'https://en.wikivoyage.org/wiki/',
            'description'      => 'Travel guide for destinations and places',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => true,
            'built_in'         => true,
        ),

        // --- Biology / Nature ---
        array(
            'slug'             => 'wikispecies',
            'label'            => 'Wikispecies',
            'uri_prefix'       => 'https://species.wikimedia.org/wiki/',
            'description'      => 'Taxonomic directory of all life',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => true,
            'built_in'         => true,
        ),
        array(
            'slug'             => 'inaturalist',
            'label'            => 'iNaturalist',
            'uri_prefix'       => 'https://www.inaturalist.org/taxa/',
            'description'      => 'Biodiversity observations and species identification',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => true,
            'built_in'         => true,
        ),
        array(
            'slug'             => 'gbif',
            'label'            => 'GBIF',
            'uri_prefix'       => 'https://www.gbif.org/species/',
            'description'      => 'Global biodiversity occurrence database',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => false,
            'built_in'         => true,
        ),

        // --- People / Organizations ---
        array(
            'slug'             => 'viaf',
            'label'            => 'VIAF',
            'uri_prefix'       => 'https://viaf.org/viaf/',
            'description'      => 'Virtual International Authority File — persons & organizations',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => false,
            'built_in'         => true,
        ),
        array(
            'slug'             => 'orcid',
            'label'            => 'ORCID',
            'uri_prefix'       => 'https://orcid.org/',
            'description'      => 'Open Researcher and Contributor ID',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => false,
            'built_in'         => true,
        ),

        // --- Library / Subject headings ---
        array(
            'slug'             => 'lcsh',
            'label'            => 'LCSH',
            'uri_prefix'       => 'https://id.loc.gov/authorities/subjects/',
            'description'      => 'Library of Congress Subject Headings',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => false,
            'built_in'         => true,
        ),
        array(
            'slug'             => 'mesh',
            'label'            => 'MeSH',
            'uri_prefix'       => 'https://id.nlm.nih.gov/mesh/',
            'description'      => 'Medical Subject Headings — biomedical terminology',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => false,
            'built_in'         => true,
        ),
        array(
            'slug'             => 'getty-aat',
            'label'            => 'Getty AAT',
            'uri_prefix'       => 'https://vocab.getty.edu/aat/',
            'description'      => 'Art & Architecture Thesaurus (Getty)',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => false,
            'built_in'         => true,
        ),
        array(
            'slug'             => 'getty-tgn',
            'label'            => 'Getty TGN',
            'uri_prefix'       => 'https://vocab.getty.edu/tgn/',
            'description'      => 'Thesaurus of Geographic Names (Getty)',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => false,
            'built_in'         => true,
        ),

        // --- Academic / Structured data ---
        array(
            'slug'             => 'openalex',
            'label'            => 'OpenAlex',
            'uri_prefix'       => 'https://openalex.org/',
            'description'      => 'Open catalog of academic papers and concepts',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => false,
            'built_in'         => true,
        ),
        array(
            'slug'             => 'schema-org',
            'label'            => 'schema.org',
            'uri_prefix'       => 'https://schema.org/',
            'description'      => 'Schema.org structured data types',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => false,
            'built_in'         => true,
        ),
        array(
            'slug'             => 'gs1',
            'label'            => 'GS1',
            'uri_prefix'       => 'https://www.gs1.org/voc/',
            'description'      => 'GS1 product and business vocabulary',
            'platform'         => 'other',
            'enabled'          => false,
            'show_on_archives' => false,
            'built_in'         => true,
        ),
    );
}

// ==============================================================================
// REGISTRY
// ==============================================================================

/**
 * Returns all link type definitions, keyed by slug.
 *
 * Reads from the tc_link_types option. Built-in types not yet present in the
 * stored option (added in a later version of HT) are merged in as disabled
 * defaults so they appear in the admin without requiring a re-activation.
 *
 * Third-party plugins may add custom link types programmatically via the
 * tc_link_types filter, which receives the full keyed array.
 *
 * @since 1.0.0
 * @return array[] [ slug => definition ]
 */
function tc_get_all_link_types(): array {
    $stored = get_option( 'tc_link_types', array() );
    if ( ! is_array( $stored ) ) {
        $stored = array();
    }

    $by_slug = array();
    foreach ( $stored as $type ) {
        if ( ! empty( $type['slug'] ) ) {
            $by_slug[ $type['slug'] ] = $type;
        }
    }

    // Back-fill built-in types not yet in the stored option.
    foreach ( tc_get_builtin_link_types() as $default ) {
        if ( ! isset( $by_slug[ $default['slug'] ] ) ) {
            $by_slug[ $default['slug'] ] = $default;
        }
    }

    return apply_filters( 'tc_link_types', $by_slug );
}

/**
 * Returns link types manageable in the Peer Sites admin screen, keyed by slug.
 *
 * Excludes types registered by companion plugins (a non-empty `provider`), which are
 * configured in their own plugin's settings — not TC's Peer Sites screen. Such types
 * remain fully active everywhere else (term-edit External Links panel, REST, peer
 * matching); they are only hidden from TC's namespace-management UI.
 *
 * @since 1.0.0
 * @return array[]
 */
function tc_get_manageable_link_types(): array {
    return array_filter(
        tc_get_all_link_types(),
        static function ( $type ) {
            return empty( $type['provider'] );
        }
    );
}

/**
 * Return the site's configured peers for the block editor's peer-picker UI:
 * enabled WordPress + feed link types, as a list of { slug, label, platform },
 * sorted by label.
 *
 * Only genuine TermConnect peers are listed. Provider-registered link types are
 * excluded via tc_get_manageable_link_types() — this keeps companion-plugin
 * entries (e.g. Social Hashtags & Mentions' social networks and its "Local site"
 * type) out of the picker — and the platform filter limits the result to
 * WordPress peers and feeds.
 *
 * @since 1.0.0
 * @return array[] List of array{ slug: string, label: string, platform: string }.
 */
function tc_get_browsable_peers(): array {
    $out = array();
    foreach ( tc_get_manageable_link_types() as $slug => $type ) {
        if ( empty( $type['enabled'] ) ) {
            continue;
        }
        $platform = $type['platform'] ?? '';
        if ( ! in_array( $platform, array( 'wordpress', 'feed' ), true ) ) {
            continue;
        }
        $out[] = array(
            'slug'     => (string) $slug,
            'label'    => (string) ( $type['label'] ?? $slug ),
            'platform' => (string) $platform,
        );
    }

    usort( $out, static function ( $a, $b ) {
        return strcasecmp( $a['label'], $b['label'] );
    } );

    return $out;
}

/**
 * Returns all enabled link type definitions, keyed by slug.
 *
 * @since 1.0.0
 * @return array[]
 */
function tc_get_enabled_link_types(): array {
    return array_filter(
        tc_get_all_link_types(),
        static function ( $type ) {
            return ! empty( $type['enabled'] );
        }
    );
}

/**
 * Returns enabled link type definitions allowed for a specific taxonomy, keyed by slug.
 *
 * Types with an empty 'taxonomies' array (no restriction) are returned for any taxonomy.
 * Types with a non-empty 'taxonomies' array are returned only when the given taxonomy
 * appears in that list.
 *
 * @since 1.0.0
 * @param string $taxonomy Taxonomy slug.
 * @return array[]
 */
function tc_get_enabled_link_types_for_taxonomy( string $taxonomy ): array {
    return array_filter(
        tc_get_all_link_types(),
        static function ( $type ) use ( $taxonomy ) {
            if ( empty( $type['enabled'] ) ) {
                return false;
            }
            if ( empty( $type['taxonomies'] ) ) {
                return true;
            }
            return in_array( $taxonomy, $type['taxonomies'], true );
        }
    );
}

/**
 * Returns all enabled link type definitions with the given platform value, keyed by slug.
 *
 * @since 1.0.0
 * @param string $platform Platform slug: 'wordpress', 'feed', or 'other'.
 * @return array[]
 */
function tc_get_platform_link_types( string $platform ): array {
    return array_filter(
        tc_get_all_link_types(),
        static function ( $type ) use ( $platform ) {
            return ! empty( $type['enabled'] ) && isset( $type['platform'] ) && $type['platform'] === $platform;
        }
    );
}

/**
 * Extracts the site origin (scheme + host) from a link type uri_prefix.
 *
 * @since 1.0.0
 * @param string $uri_prefix URI prefix, e.g. 'https://example.com/tag/'.
 * @return string scheme://host, or empty string on failure.
 */
function tc_external_get_site_origin( string $uri_prefix ): string {
    $parsed = wp_parse_url( $uri_prefix );
    if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
        return '';
    }
    return $parsed['scheme'] . '://' . $parsed['host'];
}

/**
 * Returns a single link type definition, or null if not found.
 *
 * @since 1.0.0
 * @param string $slug Link type slug.
 * @return array|null
 */
function tc_get_link_type( string $slug ): ?array {
    $types = tc_get_all_link_types();
    return isset( $types[ $slug ] ) ? $types[ $slug ] : null;
}

/**
 * Persists the tc_link_types option from the keyed-by-slug array.
 *
 * Converts the associative array back to an indexed array before saving so
 * the option format stays consistent (array of definitions, not a map).
 *
 * @since 1.0.0
 * @param array[] $types [ slug => definition ]
 * @return bool True if the option was updated.
 */
function tc_set_link_types( array $types ): bool {
    // Never persist plugin-managed (provider) types — they are always supplied fresh
    // via the tc_link_types filter, so storing them would create orphans on uninstall.
    $types = array_filter(
        $types,
        static function ( $t ) {
            return empty( $t['provider'] );
        }
    );
    return (bool) update_option( 'tc_link_types', array_values( $types ) );
}

// ==============================================================================
// URI CONSTRUCTION
// ==============================================================================

/**
 * Whether a taxonomy is enabled in TermConnect by a companion plugin (through the
 * tc_enabled_taxonomies filter) rather than by the saved option.
 *
 * Such taxonomies are treated as parallel across the network — mapped 1:1 to the
 * identically-named taxonomy on each peer — because mapping them that way is the
 * reason a companion enables them (e.g. Social Hashtags & Mentions maps mention→
 * mention and hashtag→hashtag).
 *
 * @since 1.0.0
 * @param string $taxonomy Taxonomy slug.
 * @return bool
 */
function tc_is_companion_taxonomy( string $taxonomy ): bool {
	if ( '' === $taxonomy || ! class_exists( 'TC_Settings' ) ) {
		return false;
	}
	$stored = (array) get_option( 'tc_enabled_taxonomies', array() );
	if ( in_array( $taxonomy, $stored, true ) ) {
		return false;
	}
	return in_array( $taxonomy, TC_Settings::get_enabled_taxonomies(), true );
}

/**
 * The peer taxonomy a WordPress peer link type maps to for a given local taxonomy.
 *
 * A WordPress peer link type stores a single remote_taxonomy, so it cannot, on its
 * own, map more than one local taxonomy. Companion taxonomies (see
 * tc_is_companion_taxonomy()) therefore map 1:1 by identical name, ignoring the
 * configured remote_taxonomy; for taxonomies the admin enabled explicitly the
 * configured remote_taxonomy wins, falling back to the local taxonomy when blank.
 *
 * @since 1.0.0
 * @param array  $type           WordPress peer link type definition.
 * @param string $local_taxonomy Taxonomy of the term being linked.
 * @return string Peer taxonomy slug (may be '').
 */
function tc_wp_peer_remote_taxonomy( array $type, string $local_taxonomy ): string {
	if ( '' !== $local_taxonomy && tc_is_companion_taxonomy( $local_taxonomy ) ) {
		return $local_taxonomy;
	}
	$remote = (string) ( $type['remote_taxonomy'] ?? '' );
	return '' !== $remote ? $remote : $local_taxonomy;
}

/**
 * Whether a taxonomy's WordPress-peer links map to peer authors (WP users) rather
 * than peer terms.
 *
 * A "people" taxonomy (e.g. Social Hashtags & Mentions' `mention`) represents a
 * person, who on a peer site is an author, not a taxonomy term. When true, the
 * term-edit autocomplete searches the peer's authors (via the SSRF-safe users
 * proxy) and the link resolves to the peer's `/author/{nicename}/` archive.
 *
 * @since 1.0.0
 * @param string $taxonomy Taxonomy slug.
 * @return bool
 */
function tc_taxonomy_peer_authors( string $taxonomy ): bool {
	/**
	 * Filters whether a taxonomy maps WordPress-peer links to authors.
	 *
	 * @since 1.0.0
	 * @param bool   $is_author Default false.
	 * @param string $taxonomy  Taxonomy slug.
	 */
	return (bool) apply_filters( 'tc_taxonomy_peer_authors', false, $taxonomy );
}

/**
 * Derive the taxonomy archive path segment for a WordPress peer link type.
 *
 * For a "people" taxonomy (see tc_taxonomy_peer_authors()) this is the author base
 * ('author'). Otherwise it resolves the effective peer taxonomy (see
 * tc_wp_peer_remote_taxonomy()), then maps it to a URL path: a known REST base
 * (e.g. 'tags' → 'tag') first, otherwise the taxonomy's own rewrite slug (correct
 * for shared/companion taxonomies and for built-ins, e.g. 'post_tag' → 'tag'),
 * otherwise the slug as-is.
 *
 * Returns an empty string when the path cannot be determined.
 *
 * @since 1.0.0
 * @param array  $type     Link type definition.
 * @param string $taxonomy Local taxonomy of the term being linked.
 * @return string
 */
function tc_wp_link_type_archive_path( array $type, string $taxonomy = '' ): string {
	static $rest_to_path = array(
		'tags'       => 'tag',
		'categories' => 'category',
	);
	static $slug_to_path = array(
		'post_tag' => 'tag',
		'category' => 'category',
	);

	if ( '' !== $taxonomy && tc_taxonomy_peer_authors( $taxonomy ) ) {
		return 'author';
	}

	$remote = tc_wp_peer_remote_taxonomy( $type, $taxonomy );
	if ( '' === $remote ) {
		return '';
	}

	if ( isset( $rest_to_path[ $remote ] ) ) {
		return $rest_to_path[ $remote ];
	}

	$tax_obj = get_taxonomy( $remote );
	if ( $tax_obj && ! empty( $tax_obj->rewrite['slug'] ) ) {
		return (string) $tax_obj->rewrite['slug'];
	}

	return $slug_to_path[ $remote ] ?? $remote;
}

/**
 * Constructs the full URI for a link type + ID pair.
 *
 * For WordPress peer link types the URI is derived from the peer site origin plus
 * the taxonomy archive path (from remote_taxonomy or the local taxonomy rewrite slug)
 * rather than relying on any path stored in uri_prefix.
 *
 * Returns null for feed types (no per-item URI exists) and for WordPress peer types
 * when the archive path cannot be determined.
 *
 * @since 1.0.0
 * @param string $slug     Link type slug.
 * @param string $id       Identifier within the namespace.
 * @param string $taxonomy Local taxonomy slug — required for WordPress peer types when
 *                         remote_taxonomy is blank; ignored for other platforms.
 * @return string|null
 */
function tc_get_link_uri( string $slug, string $id, string $taxonomy = '' ): ?string {
	$type = tc_get_link_type( $slug );
	if ( null === $type || '' === $id ) {
		return null;
	}

	$platform = $type['platform'] ?? 'other';

	if ( 'feed' === $platform ) {
		return null;
	}

	$uri = null;
	if ( 'wordpress' === $platform ) {
		$origin = tc_external_get_site_origin( $type['uri_prefix'] );
		$path   = '' !== $origin ? tc_wp_link_type_archive_path( $type, $taxonomy ) : '';
		if ( '' !== $origin && '' !== $path ) {
			$uri = $origin . '/' . $path . '/' . rawurlencode( $id );
		}
	} else {
		$uri = $type['uri_prefix'] . $id;
	}

	/**
	 * Filters the constructed URI for a link type + identifier pair.
	 *
	 * Lets companion plugins build per-network, per-taxonomy URLs that the default
	 * `uri_prefix . id` concatenation cannot express — e.g. a social-network plugin
	 * that emits a hashtag URL for one taxonomy and a profile URL for another, or
	 * that needs a transform (Mastodon `@user@instance` → `https://instance/@user`).
	 *
	 * @since 1.0.0
	 * @param string|null $uri      Constructed URI, or null when none could be derived.
	 * @param string      $slug     Link type slug.
	 * @param string      $id       Identifier within the namespace.
	 * @param array       $type     Link type definition.
	 * @param string      $taxonomy Local taxonomy slug for context (may be empty).
	 */
	return apply_filters( 'tc_link_uri', $uri, $slug, $id, $type, $taxonomy );
}

/**
 * Return the URL prefix used for URI preview and pill links in the term-edit screen.
 *
 * For WordPress peer types this is origin + archive path (derived from remote_taxonomy or
 * local taxonomy rewrite slug). For other types it is uri_prefix. For feed types it is empty.
 *
 * @since 1.0.0
 * @param array  $type     Link type definition.
 * @param string $taxonomy Local taxonomy slug of the term being edited.
 * @return string URL prefix ending with '/', or empty string when a link cannot be derived.
 */
function tc_link_type_display_prefix( array $type, string $taxonomy = '' ): string {
	$platform = $type['platform'] ?? 'other';

	if ( 'feed' === $platform ) {
		return '';
	}

	if ( 'wordpress' === $platform ) {
		$origin = tc_external_get_site_origin( $type['uri_prefix'] );
		$path   = tc_wp_link_type_archive_path( $type, $taxonomy );
		if ( '' !== $origin && '' !== $path ) {
			return $origin . '/' . $path . '/';
		}
		return '';
	}

	return $type['uri_prefix'] ?? '';
}

// ==============================================================================
// KNOWLEDGE BASE SUGGEST
// ==============================================================================

/**
 * Returns the suggest configuration for a known external identifier type slug.
 *
 * Returns null for types that have no public suggest API or require credentials.
 * The 'url' template uses {query} as a placeholder for the URL-encoded search term.
 * The 'parser' key identifies the response format handled by tc_parse_suggest_response().
 *
 * @since 1.0.0
 * @param string $slug     Link type slug.
 * @param string $taxonomy Taxonomy of the term being edited, if known. Passed to the
 *                         'tc_suggest_config' filter so companions can offer suggestions
 *                         only where they make sense (e.g. profiles on a mention taxonomy).
 * @return array|null { url: string, parser: string } or null.
 */
function tc_get_suggest_config( string $slug, string $taxonomy = '' ): ?array {
	static $configs = null;
	if ( null === $configs ) {
		$configs = array(
			'wikidata'      => array(
				'url'    => 'https://www.wikidata.org/w/api.php?action=wbsearchentities&search={query}&language=en&type=item&format=json&limit=8',
				'parser' => 'wikidata',
			),
			'wikipedia'     => array(
				'url'    => 'https://en.wikipedia.org/w/api.php?action=opensearch&search={query}&limit=8&format=json&redirects=resolve',
				'parser' => 'mediawiki',
			),
			'wikiquote'     => array(
				'url'    => 'https://en.wikiquote.org/w/api.php?action=opensearch&search={query}&limit=8&format=json',
				'parser' => 'mediawiki',
			),
			'wiktionary'    => array(
				'url'    => 'https://en.wiktionary.org/w/api.php?action=opensearch&search={query}&limit=8&format=json',
				'parser' => 'mediawiki',
			),
			'wikivoyage'    => array(
				'url'    => 'https://en.wikivoyage.org/w/api.php?action=opensearch&search={query}&limit=8&format=json',
				'parser' => 'mediawiki',
			),
			'wikispecies'   => array(
				'url'    => 'https://species.wikimedia.org/w/api.php?action=opensearch&search={query}&limit=8&format=json',
				'parser' => 'mediawiki',
			),
			'openstreetmap' => array(
				'url'    => 'https://nominatim.openstreetmap.org/search?q={query}&format=json&limit=8&addressdetails=0',
				'parser' => 'nominatim',
			),
			'inaturalist'   => array(
				'url'    => 'https://api.inaturalist.org/v1/taxa?q={query}&per_page=8',
				'parser' => 'inaturalist',
			),
			'gbif'          => array(
				'url'    => 'https://api.gbif.org/v1/species/suggest?q={query}&limit=8',
				'parser' => 'gbif',
			),
			// viaf: AutoSuggest endpoint now returns Cloudflare challenge HTML for server-side
			// requests; no server-accessible alternative exists. Plain ID field only.
			'lcsh'          => array(
				'url'    => 'https://id.loc.gov/suggest/?q={query}&rdftype=Topic&count=8',
				'parser' => 'lcsh',
			),
			'openalex'      => array(
				'url'    => 'https://api.openalex.org/autocomplete/concepts?q={query}',
				'parser' => 'openalex',
			),
			// Types with no public suggest API: grokipedia, merriam-webster (key required),
			// geonames (username required), orcid (OAuth), mesh, getty-aat, getty-tgn (SPARQL),
			// schema-org (finite vocabulary), gs1.
		);
	}
	if ( isset( $configs[ $slug ] ) ) {
		return $configs[ $slug ];
	}

	$type = tc_get_link_type( $slug );

	// Fallback: if the link type declares platform=mediawiki, derive the API URL from its uri_prefix.
	if ( $type && 'mediawiki' === ( $type['platform'] ?? '' ) && ! empty( $type['uri_prefix'] ) ) {
		$scheme = parse_url( $type['uri_prefix'], PHP_URL_SCHEME );
		$host   = parse_url( $type['uri_prefix'], PHP_URL_HOST );
		if ( $scheme && $host ) {
			return array(
				'url'    => $scheme . '://' . $host . '/w/api.php?action=opensearch&search={query}&limit=8&format=json',
				'parser' => 'mediawiki',
			);
		}
	}

	/**
	 * Filters the suggest configuration for a link type with no built-in config.
	 *
	 * Companion plugins that register their own external link types can return
	 * [ 'url' => <template>, 'parser' => <id>, 'free_text' => bool ] to enable
	 * autocomplete on the term-edit identifier field. {query} in the URL template
	 * is replaced with the URL-encoded search term and the request is made
	 * server-side to that fixed endpoint only (the term is the sole interpolated
	 * value), so arbitrary-URL SSRF is not possible. Set 'free_text' => true to let
	 * the user keep typed input that does not match a suggestion (suggestions are
	 * advisory rather than required — appropriate for handles the user may know
	 * exactly). Pair with the 'tc_parse_suggest_response' filter to turn the
	 * response into { value, label } suggestions for a custom parser id.
	 *
	 * @since 1.0.0
	 * @param array|null $config   Suggest config, or null for none.
	 * @param string     $slug     Link type slug.
	 * @param array|null $type     Link type definition, if known.
	 * @param string     $taxonomy Taxonomy of the term being edited, if known.
	 */
	return apply_filters( 'tc_suggest_config', null, $slug, $type, $taxonomy );
}

/**
 * Parse a raw JSON response body from an external suggest API.
 *
 * Returns an array of { value: string, label: string } items for use
 * as autocomplete suggestions in the admin mapping field.
 *
 * @since 1.0.0
 * @param string $body   Raw HTTP response body.
 * @param string $parser Parser identifier matching tc_get_suggest_config()['parser'].
 * @return array[]
 */
function tc_parse_suggest_response( string $body, string $parser ): array {
	$data    = tc_external_json_decode( $body );
	$results = array();

	switch ( $parser ) {

		case 'wikidata':
			if ( ! isset( $data['search'] ) ) {
				break;
			}
			foreach ( $data['search'] as $item ) {
				$id   = (string) ( $item['id'] ?? '' );
				$name = (string) ( $item['label'] ?? $id );
				$desc = (string) ( $item['description'] ?? '' );
				if ( '' !== $id ) {
					$results[] = array(
						'value' => $id,
						'label' => $desc ? "$name ($desc)" : $name,
					);
				}
			}
			break;

		case 'mediawiki':
			// OpenSearch response: four-element array — query string, titles, descriptions, URLs.
			if ( ! is_array( $data ) || ! isset( $data[1] ) ) {
				break;
			}
			$titles = (array) $data[1];
			$descs  = (array) ( $data[2] ?? array() );
			foreach ( $titles as $i => $title ) {
				$slug = str_replace( ' ', '_', (string) $title );
				$desc = (string) ( $descs[ $i ] ?? '' );

				$results[] = array(
					'value' => $slug,
					'label' => $desc ? "$title — $desc" : (string) $title,
				);
			}
			break;

		case 'nominatim':
			// Nominatim returns osm_type (N/W/R) and numeric osm_id separately.
			// The stored value must be "{type}/{id}" (e.g. "way/5013364") so the
			// uri_prefix "https://www.openstreetmap.org/" produces a valid URL.
			if ( ! is_array( $data ) ) {
				break;
			}
			$osm_type_map = array( 'N' => 'node', 'W' => 'way', 'R' => 'relation' );
			foreach ( $data as $item ) {
				$raw_type = strtoupper( substr( (string) ( $item['osm_type'] ?? '' ), 0, 1 ) );
				$type     = $osm_type_map[ $raw_type ] ?? strtolower( (string) ( $item['osm_type'] ?? '' ) );
				$osm_id   = (string) ( $item['osm_id'] ?? '' );
				$label    = (string) ( $item['display_name'] ?? '' );
				if ( '' !== $type && '' !== $osm_id && '' !== $label ) {
					$results[] = array(
						'value' => $type . '/' . $osm_id,
						'label' => $label,
					);
				}
			}
			break;

		case 'inaturalist':
			if ( ! isset( $data['results'] ) ) {
				break;
			}
			foreach ( $data['results'] as $item ) {
				$id     = (string) ( $item['id'] ?? '' );
				$name   = (string) ( $item['name'] ?? '' );
				$common = (string) ( $item['preferred_common_name'] ?? '' );
				if ( '' !== $id ) {
					$results[] = array(
						'value' => $id,
						'label' => $common ? "$name ($common)" : $name,
					);
				}
			}
			break;

		case 'gbif':
			if ( ! is_array( $data ) ) {
				break;
			}
			foreach ( $data as $item ) {
				$id     = (string) ( $item['key'] ?? '' );   // suggest endpoint uses 'key', not 'usageKey'
				$name   = (string) ( $item['canonicalName'] ?? $item['scientificName'] ?? '' );
				$common = (string) ( $item['vernacularName'] ?? '' );
				if ( '' !== $id ) {
					$results[] = array(
						'value' => $id,
						'label' => $common ? "$name ($common)" : $name,
					);
				}
			}
			break;

case 'lcsh':
			// Same OpenSearch format as mediawiki but identifier = last URI path segment.
			if ( ! is_array( $data ) || ! isset( $data[1] ) ) {
				break;
			}
			$labels = (array) $data[1];
			$uris   = (array) ( $data[3] ?? array() );
			foreach ( $labels as $i => $label ) {
				$uri = (string) ( $uris[ $i ] ?? '' );
				$id  = $uri ? basename( rtrim( $uri, '/' ) ) : '';
				if ( '' !== $id ) {
					$results[] = array( 'value' => $id, 'label' => (string) $label );
				}
			}
			break;

		case 'openalex':
			if ( ! isset( $data['results'] ) ) {
				break;
			}
			foreach ( $data['results'] as $item ) {
				$id_url = (string) ( $item['id'] ?? '' );
				$id     = $id_url ? basename( $id_url ) : '';
				$label  = (string) ( $item['display_name'] ?? '' );
				if ( '' !== $id ) {
					$results[] = array( 'value' => $id, 'label' => $label );
				}
			}
			break;
	}

	/**
	 * Filters parsed suggest results, enabling custom parser ids.
	 *
	 * Companion plugins handling a custom parser id (declared through the
	 * 'tc_suggest_config' filter) return an array of
	 * [ 'value' => string, 'label' => string ] items.
	 *
	 * @since 1.0.0
	 * @param array[] $results Parsed results (empty for an unrecognized parser).
	 * @param string  $body    Raw response body.
	 * @param string  $parser  Parser id.
	 */
	$results = apply_filters( 'tc_parse_suggest_response', $results, $body, $parser );

	// Decode HTML entities in labels — some APIs return encoded strings (e.g. "&amp;") and
	// jQuery UI Autocomplete renders labels as plain text, not HTML.
	foreach ( $results as &$result ) {
		$result['label'] = html_entity_decode( $result['label'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
	unset( $result );

	return $results;
}

// ==============================================================================
// TERM META API
// ==============================================================================

/**
 * Returns all external link values set on a term, keyed by slug.
 *
 * Only returns types that are currently enabled and allowed for the term's taxonomy.
 * Disabled types and types restricted to other taxonomies may still have stored meta
 * values (preserved when a type is disabled or its taxonomy restriction changes).
 *
 * Returns an empty array when the term is a redirect unless $include_redirect_data is true.
 *
 * @since 1.0.0
 * @param int  $term_id              Term ID.
 * @param bool $include_redirect_data When true, return data even if the term is a redirect.
 * @return array [ slug => id ]
 */
function tc_get_term_links( int $term_id, bool $include_redirect_data = false ): array {
    if ( ! $include_redirect_data ) {
        $term = get_term( $term_id );
        if ( $term instanceof WP_Term
             && tc_is_redirect( $term_id )
             && function_exists( 'htax_taxonomy_has_feature' )
             && htax_taxonomy_has_feature( $term->taxonomy, 'redirects' ) ) {
            return array();
        }
    }

    return array_map( fn( $entry ) => $entry['id'], tc_get_term_links_data( $term_id ) );
}

/**
 * Returns full structured link data for enabled types on a term, keyed by slug.
 *
 * Unlike tc_get_term_links() which returns [ slug => id ], this returns the full
 * { id, label, fetched_at } arrays for use in REST output and term-edit display.
 *
 * When $reset is true, clears the per-request static cache entry for the term
 * without returning data. Pass true after bulk meta operations that bypass the
 * normal tc_set_term_link() / tc_remove_term_link() write path.
 *
 * @since 1.0.0
 * @param int  $term_id Term ID.
 * @param bool $reset   When true, evict the term from the static cache and return an empty array.
 * @return array[] [ slug => { id, label, fetched_at } ]
 */
function tc_get_term_links_data( int $term_id, bool $reset = false ): array {
    static $cache = array();
    if ( $reset ) {
        unset( $cache[ $term_id ] );
        return array();
    }
    if ( array_key_exists( $term_id, $cache ) ) {
        return $cache[ $term_id ];
    }
    // Bound memory during bulk operations (import/merge touch many terms).
    if ( count( $cache ) >= 500 ) {
        $cache = array();
    }

    $term    = get_term( $term_id );
    $enabled = $term instanceof WP_Term
        ? tc_get_enabled_link_types_for_taxonomy( $term->taxonomy )
        : tc_get_enabled_link_types();

    if ( empty( $enabled ) ) {
        return $cache[ $term_id ] = array();
    }

    // Persistent per-term cache. Stores { slug => {id, label, fetched_at} } for all non-empty
    // _tc_link_* meta, regardless of which link types are currently enabled, so enabled-type
    // changes don't stale it. Keyed as tc_links_{term_id}; invalidated by tc_flush_term_link_cache().
    $raw = get_transient( 'tc_links_' . $term_id );
    if ( false === $raw ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->termmeta}
             WHERE term_id = %d AND meta_key LIKE %s AND meta_value != ''",
            $term_id,
            $wpdb->esc_like( '_tc_link_' ) . '%'
        ) );
        $raw = array();
        foreach ( (array) $rows as $row ) {
            $slug_key = substr( $row->meta_key, 9 );
            $meta     = maybe_unserialize( $row->meta_value );
            if ( is_array( $meta ) && isset( $meta['id'] ) && '' !== (string) $meta['id'] ) {
                $raw[ $slug_key ] = array(
                    'id'         => (string) $meta['id'],
                    'label'      => (string) ( $meta['label'] ?? '' ),
                    'fetched_at' => (int) ( $meta['fetched_at'] ?? 0 ),
                );
            } elseif ( is_string( $meta ) && '' !== $meta ) {
                $raw[ $slug_key ] = array( 'id' => $meta, 'label' => '', 'fetched_at' => 0 );
            }
        }
        set_transient( 'tc_links_' . $term_id, $raw, 0 );
    }

    $data = array();
    foreach ( $enabled as $slug => $type ) {
        $entry = $raw[ $slug ] ?? null;
        if ( is_array( $entry ) && isset( $entry['id'] ) && '' !== (string) $entry['id'] ) {
            $data[ $slug ] = $entry;
        }
    }
    return $cache[ $term_id ] = $data;
}

/**
 * Invalidate the external link cache for a single term.
 * Call whenever any _tc_link_* meta changes for this term.
 *
 * @param int $term_id Term ID.
 */
function tc_flush_term_link_cache( int $term_id ): void {
    delete_transient( 'tc_links_' . $term_id );
    delete_transient( 'tc_uris_' . $term_id );
    tc_get_term_links_data( $term_id, true );
    tc_build_merged_links( $term_id, true );
    do_action( 'tc_term_uris_changed', $term_id );
}

/**
 * Sets (creates or updates) an external link value on a term.
 *
 * Stores a structured { id, label, fetched_at } array and rebuilds the _tc_uris index.
 * The label should be pre-formatted as "TypeName: Item name" (e.g. "Wikidata: Python").
 * When fetched_at is 0 the current time is used.
 *
 * @since 1.0.0
 * @param int    $term_id    Term ID.
 * @param string $slug       Link type slug.
 * @param string $id         Identifier value to store.
 * @param string $label      Optional pre-formatted display label.
 * @param int    $fetched_at Optional Unix timestamp when the label was fetched; 0 = now.
 * @return bool True on success.
 */
function tc_set_term_link( int $term_id, string $slug, string $id, string $label = '', int $fetched_at = 0 ): bool {
    $type = tc_get_link_type( $slug );
    if ( null === $type ) {
        return false;
    }

    /**
     * Filters a link identifier before it is stored, for normalization/validation.
     *
     * Lets the owner of a link type clean up free-form input (e.g. strip an "@"
     * prefix or reduce a pasted profile URL to a handle). Return an empty string to
     * reject the value, in which case the link is removed rather than stored.
     *
     * @since 1.0.0
     * @param string $id   Raw identifier.
     * @param string $slug Link type slug.
     * @param array  $type Link type definition.
     */
    $id = (string) apply_filters( 'tc_link_value', $id, $slug, $type );
    if ( '' === $id ) {
        return tc_remove_term_link( $term_id, $slug );
    }

    $meta   = array(
        'id'         => $id,
        'label'      => $label,
        'fetched_at' => $fetched_at > 0 ? $fetched_at : time(),
    );
    $result = false !== update_term_meta( $term_id, '_tc_link_' . $slug, $meta );
    tc_flush_term_link_cache( $term_id );
    tc_rebuild_uri_index( $term_id );
    return $result;
}

/**
 * Removes an external link value from a term.
 *
 * @since 1.0.0
 * @param int    $term_id Term ID.
 * @param string $slug    Link type slug.
 * @return bool True on success.
 */
function tc_remove_term_link( int $term_id, string $slug ): bool {
    $result = (bool) delete_term_meta( $term_id, '_tc_link_' . $slug );
    tc_flush_term_link_cache( $term_id );
    tc_rebuild_uri_index( $term_id );
    return $result;
}

/**
 * Returns all local term IDs whose _tc_uris index contains the given URI.
 *
 * Queries the _tc_uris multi-value meta which is rebuilt by tc_rebuild_uri_index()
 * on every link change and covers both local and delegated links.
 *
 * @since 1.0.0
 * @param string $uri Full URI to match.
 * @return int[]
 */
function tc_get_terms_by_uri( string $uri ): array {
    global $wpdb;
    $results = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = '_tc_uris' AND meta_value = %s",
            $uri
        )
    );
    $ids = array_map( 'intval', $results );

    $redirect_ids = tc_get_all_redirect_term_ids();
    if ( ! empty( $redirect_ids ) ) {
        _prime_term_caches( $ids );
        $ids = array_values( array_filter( $ids, function ( $tid ) use ( $redirect_ids ) {
            if ( ! in_array( $tid, $redirect_ids, true ) ) {
                return true;
            }
            $t = get_term( $tid );
            return ! ( $t instanceof WP_Term ) || ! function_exists( 'htax_taxonomy_has_feature' ) || ! htax_taxonomy_has_feature( $t->taxonomy, 'redirects' );
        } ) );
    }

    return $ids;
}

// ==============================================================================
// URI INDEX
// ==============================================================================

/**
 * Rebuilds the _tc_uris multi-value meta and its transient for a term.
 *
 * The URI index is a flat list of all URLs associated with the term, derived
 * from both local _tc_link_{slug} meta and _tc_delegated_links_data. It is
 * used for term matching queries (htax_term_uri REST filter) and shared with
 * HyperTaxonomy and WP-SKOS via the htax_term_uris filter.
 *
 * Override logic applied during rebuild:
 * - Same URL in both local and delegated → delegated wins (label with newer fetched_at).
 * - Delegated URL shares the same uri_prefix as a local URL → local is suppressed.
 * - Different uri_prefix → both are included.
 *
 * @since 1.0.0
 * @param int $term_id Term ID.
 */
function tc_rebuild_uri_index( int $term_id ): void {
    $term     = get_term( $term_id );
    $taxonomy = $term instanceof WP_Term ? $term->taxonomy : '';

    // Collect local links: slug => { url, uri_prefix }
    // Single batch query to avoid one query per link type.
    global $wpdb;
    $meta_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT meta_key, meta_value FROM {$wpdb->termmeta}
         WHERE term_id = %d AND meta_key LIKE %s AND meta_value != ''",
        $term_id,
        $wpdb->esc_like( '_tc_link_' ) . '%'
    ) );
    $stored_ids = array();
    foreach ( (array) $meta_rows as $row ) {
        $slug_key = substr( $row->meta_key, 9 );
        $meta     = maybe_unserialize( $row->meta_value );
        if ( is_array( $meta ) && isset( $meta['id'] ) && '' !== (string) $meta['id'] ) {
            $stored_ids[ $slug_key ] = (string) $meta['id'];
        } elseif ( is_string( $meta ) && '' !== $meta ) {
            $stored_ids[ $slug_key ] = $meta;
        }
    }

    $local_entries = array();
    foreach ( tc_get_all_link_types() as $slug => $type ) {
        if ( ! isset( $stored_ids[ $slug ] ) ) {
            continue;
        }
        $id  = $stored_ids[ $slug ];
        $url = tc_get_link_uri( $slug, $id, $taxonomy );
        if ( null === $url || '' === $url ) {
            continue;
        }
        $local_entries[ $slug ] = array(
            'url'        => $url,
            'uri_prefix' => (string) ( $type['uri_prefix'] ?? '' ),
        );
    }

    // Collect delegated links indexed by URL.
    $delegated_data   = get_term_meta( $term_id, '_tc_delegated_links_data', true );
    $delegated_by_url = array();
    if ( is_array( $delegated_data ) ) {
        foreach ( $delegated_data as $entry ) {
            if ( ! empty( $entry['url'] ) ) {
                $delegated_by_url[ $entry['url'] ] = $entry;
            }
        }
    }

    // Build final URL list, applying override logic.
    $final_urls = array();

    foreach ( $local_entries as $slug => $local ) {
        $prefix               = $local['uri_prefix'];
        $overridden_different = false;
        foreach ( $delegated_by_url as $durl => $dentry ) {
            if ( $durl === $local['url'] ) {
                // Same URL — delegated wins; local is still included (same URI, no conflict).
                continue;
            }
            if ( '' !== $prefix && 0 === strpos( $durl, $prefix ) ) {
                // Delegated points to a different item on the same site — suppress local.
                $overridden_different = true;
                break;
            }
        }
        if ( ! $overridden_different ) {
            $final_urls[] = $local['url'];
        }
    }

    foreach ( array_keys( $delegated_by_url ) as $durl ) {
        $final_urls[] = $durl;
    }

    $final_urls = array_values( array_unique( $final_urls ) );

    /**
     * Filters the URIs indexed for a term before they are written.
     *
     * Lets other plugins contribute external URIs for cross-site identifier
     * matching without owning a TermConnect link type. For example, HyperTaxonomy
     * injects canonical-address URIs derived from As the Crow Flies so peer and
     * FOAF terms with different slugs match by shared physical location.
     *
     * @since 1.0.0
     * @param string[] $final_urls URIs to index for the term.
     * @param int      $term_id    Term ID.
     */
    $final_urls = (array) apply_filters( 'tc_index_extra_uris', $final_urls, $term_id );
    $final_urls = array_values( array_unique( array_filter( array_map( 'strval', $final_urls ) ) ) );

    // Write multi-value meta (delete all rows first, then re-add).
    delete_term_meta( $term_id, '_tc_uris' );
    foreach ( $final_urls as $url ) {
        add_term_meta( $term_id, '_tc_uris', $url );
    }

    // Update transient cache. TTL=0: explicit invalidation only (rebuilt on every link change).
    set_transient( 'tc_uris_' . $term_id, $final_urls, 0 );
}

/**
 * Builds the merged external links list for a term, combining local and delegated data.
 *
 * Returns a flat array of { url, platform, label, fetched_at } objects, with the same
 * override logic used by tc_rebuild_uri_index():
 *  - Same URL in local and delegated → output once; use the label with the newer fetched_at.
 *  - Delegated URL shares the same uri_prefix as a local URL → local is suppressed.
 *  - Different sites → both included.
 *
 * When a label is empty, the URL is used as the label fallback.
 *
 * When $reset is true, evict the term from the static cache and return an empty array.
 * Pass true after any operation that changes a term's links outside the normal write path.
 *
 * Used by the tc_links REST field, tc_rest_build_links_data() (HT integration), and the
 * "See also" display on term archives.
 *
 * @since 1.0.0
 * @param int  $term_id Term ID.
 * @param bool $reset   When true, evict the term from the static cache and return an empty array.
 * @return array[] [ { url, platform, label, fetched_at } ]
 */
function tc_build_merged_links( int $term_id, bool $reset = false ): array {
    static $cache = array();
    if ( $reset ) {
        unset( $cache[ $term_id ] );
        return array();
    }
    if ( array_key_exists( $term_id, $cache ) ) {
        return $cache[ $term_id ];
    }
    // Bound memory during bulk operations (import/merge touch many terms).
    if ( count( $cache ) >= 500 ) {
        $cache = array();
    }

    $term     = get_term( $term_id );
    $taxonomy = $term instanceof WP_Term ? $term->taxonomy : '';

    // Local links indexed by URL.
    $local_by_url = array();
    foreach ( tc_get_term_links_data( $term_id ) as $slug => $meta ) {
        $type = tc_get_link_type( $slug );
        $url  = tc_get_link_uri( $slug, $meta['id'], $taxonomy );
        if ( null === $url || '' === $url ) {
            continue;
        }
        $local_by_url[ $url ] = array(
            'url'        => $url,
            'platform'   => (string) ( $type['platform'] ?? 'other' ),
            'label'      => $meta['label'],
            'fetched_at' => $meta['fetched_at'],
            'uri_prefix' => (string) ( $type['uri_prefix'] ?? '' ),
        );
    }

    // Delegated links indexed by URL.
    $delegated_data   = get_term_meta( $term_id, '_tc_delegated_links_data', true );
    $delegated_by_url = array();
    if ( is_array( $delegated_data ) ) {
        foreach ( $delegated_data as $entry ) {
            if ( ! empty( $entry['url'] ) ) {
                $delegated_by_url[ $entry['url'] ] = $entry;
            }
        }
    }

    $out              = array();
    $delegated_merged = array();

    foreach ( $local_by_url as $url => $local ) {
        $prefix               = $local['uri_prefix'];
        $overridden_different = false;
        foreach ( $delegated_by_url as $durl => $dentry ) {
            if ( $durl === $url ) {
                continue; // same URL handled below
            }
            if ( '' !== $prefix && 0 === strpos( $durl, $prefix ) ) {
                $overridden_different = true;
                break;
            }
        }
        if ( $overridden_different ) {
            continue; // suppressed by delegated link pointing to same site
        }
        $label      = $local['label'];
        $fetched_at = $local['fetched_at'];
        if ( isset( $delegated_by_url[ $url ] ) ) {
            $delegated_merged[ $url ] = true;
            $d = $delegated_by_url[ $url ];
            if ( (int) ( $d['fetched_at'] ?? 0 ) >= $fetched_at ) {
                $label      = (string) ( $d['label'] ?? '' );
                $fetched_at = (int) $d['fetched_at'];
            }
        }
        $out[] = array(
            'url'        => $url,
            'platform'   => $local['platform'],
            'label'      => '' !== $label ? $label : $url,
            'fetched_at' => $fetched_at,
        );
    }

    // Add delegated entries not already merged into a local entry.
    foreach ( $delegated_by_url as $durl => $dentry ) {
        if ( isset( $delegated_merged[ $durl ] ) ) {
            continue;
        }
        $label = (string) ( $dentry['label'] ?? '' );
        $out[] = array(
            'url'        => $durl,
            'platform'   => (string) ( $dentry['platform'] ?? '' ),
            'label'      => '' !== $label ? $label : $durl,
            'fetched_at' => (int) ( $dentry['fetched_at'] ?? 0 ),
        );
    }

    return $cache[ $term_id ] = $out;
}

// ==============================================================================
// SAVE HANDLER (called by tc_save_term_data orchestrator)
// ==============================================================================

/**
 * Saves external link values submitted from the term edit form.
 *
 * Processes tc_links[{slug}] POST values for all enabled link types.
 * Empty value = remove existing meta. Non-empty = create or update.
 * Skips types whose taxonomy restriction excludes the term's taxonomy.
 * Only runs when the tc_links_present sentinel is in the POST data.
 *
 * Called by tc_save_term_data() after nonce and permission checks.
 *
 * @since 1.0.0
 * @param int $term_id Term ID.
 */
function tc_save_term_links( int $term_id ): void {
    if ( ! isset( $_POST['tc_links_present'] ) ) {
        return;
    }

    $submitted = isset( $_POST['tc_links'] ) && is_array( $_POST['tc_links'] )
        ? wp_unslash( $_POST['tc_links'] )
        : array();

    $term     = get_term( $term_id );
    $taxonomy = $term instanceof WP_Term ? $term->taxonomy : '';

    foreach ( tc_get_all_link_types() as $slug => $type ) {
        if ( ! array_key_exists( $slug, $submitted ) ) {
            continue;
        }
        // Silently ignore types not allowed for this term's taxonomy.
        if ( ! empty( $type['taxonomies'] ) && $taxonomy && ! in_array( $taxonomy, $type['taxonomies'], true ) ) {
            continue;
        }
        $id = sanitize_text_field( $submitted[ $slug ] );
        if ( '' === $id ) {
            tc_remove_term_link( $term_id, $slug );
        } else {
            $labels     = isset( $_POST['tc_link_labels'] ) && is_array( $_POST['tc_link_labels'] )
                ? wp_unslash( $_POST['tc_link_labels'] )
                : array();
            $timestamps = isset( $_POST['tc_link_fetched'] ) && is_array( $_POST['tc_link_fetched'] )
                ? wp_unslash( $_POST['tc_link_fetched'] )
                : array();
            $label      = sanitize_text_field( $labels[ $slug ] ?? '' );
            $fetched_at = (int) ( $timestamps[ $slug ] ?? 0 );
            tc_set_term_link( $term_id, $slug, $id, $label, $fetched_at );

            // Trash pending suggestions for this term whose URL matches the link just saved.
            $prefix = $type['uri_prefix'] ?? '';
            if ( $prefix && function_exists( 'tc_pending_suggested_link_ids' ) ) {
                foreach ( tc_pending_suggested_link_ids( $term_id, $prefix . $id ) as $cid ) {
                    wp_trash_comment( $cid );
                }
            }
        }
    }
}

// ==============================================================================
// CLEANUP
// ==============================================================================

/**
 * Deletes _tc_link_{slug} term meta for terms whose taxonomy violates the
 * type's 'taxonomies' restriction. A no-op when the restriction list is empty.
 *
 * @since 1.0.0
 * @param string $slug Link type slug.
 * @return int Number of term meta rows deleted.
 */
function tc_remove_invalid_term_links( string $slug ): int {
    $type = tc_get_link_type( $slug );
    if ( ! $type || empty( $type['taxonomies'] ) ) {
        return 0;
    }

    global $wpdb;

    $allowed      = $type['taxonomies'];
    $meta_key     = '_tc_link_' . $slug;
    $placeholders = implode( ', ', array_fill( 0, count( $allowed ), '%s' ) );

    $affected_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT tm.term_id FROM {$wpdb->termmeta} tm
         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
         WHERE tm.meta_key = %s
         AND tt.taxonomy NOT IN ($placeholders)",
        array_merge( array( $meta_key ), $allowed )
    ) );

    $affected = (int) $wpdb->query(
        $wpdb->prepare(
            "DELETE tm FROM {$wpdb->termmeta} tm
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
             WHERE tm.meta_key = %s
             AND tt.taxonomy NOT IN ($placeholders)",
            array_merge( array( $meta_key ), $allowed )
        )
    );

    foreach ( array_unique( array_map( 'intval', $affected_ids ) ) as $tid ) {
        tc_flush_term_link_cache( $tid );
        tc_rebuild_uri_index( $tid );
    }

    return $affected;
}

// Save term link data whenever any term is created or updated.
add_action( 'saved_term', static function ( $term_id ) {
    tc_save_term_links( (int) $term_id );
}, 20 );
