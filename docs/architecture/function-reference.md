---
title: Function Reference
parent: Architecture
nav_order: 2
---

# Function Reference
{: .no_toc }

Functions and methods of TermConnect by file, with each one's docblock summary, chosen by one rule used across the suite's plugins. Listed: the public API (documented in the [Developer Reference](../developer-reference/)) and anything called from another file, which is what contributors reuse. Not listed: hook callbacks, which the [Hook Reference](hook-reference.html) names next to their hooks; screen wiring (rendering, enqueueing, menu and column registration, constructors); overrides of a base class's method; and anything used only inside its own file or class, which is best read in the code. This page is generated from the code.

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Core

### Delegated identity (`includes/core/delegated-identity.php`)

Delegated Identity

- `tc_get_identity_source( int $term_id )` — Return the stored identity source for a term, or null if none.
- `tc_set_identity_source( int $term_id, string $peer_site_slug, string $remote_slug, array $remote, bool $sync_descendants = false, string $sv_source_id = '', bool $sync_name = true, bool $sync_description = true )` — Save the identity source for a term. Replaces the term's description with the remote description and saves the original locally so it can be restored if delegation is removed.
- `tc_remove_identity_source( int $term_id, array $options = array() )` — Remove the identity source from a term, optionally restoring the original name/description.
- `tc_update_identity_sync_flags( int $term_id, bool $sync_name, bool $sync_description )` — Update the sync_name and sync_description flags for a delegated term.
- `tc_sv_remove_delegations_by_source( string $sv_source_id )` — Remove all delegation meta for every term managed by a given shared vocabulary source, restoring original names and descriptions where they were backed up before syncing.
- `tc_fetch_remote_identity( string $peer_site_slug, string $taxonomy, string $remote_slug )` — Fetch identity data for a term from a peer WordPress site via WP REST API.
- `tc_refresh_identity_cache( int $term_id )` — Refresh cached identity data for one term. Called by cron and inline from term edit AJAX.
- `tc_mysql_lock_name( string $name )` — Scope an advisory lock name to this site.
- `tc_sv_update_root_slug( string $sv_source_id, string $old_slug, string $new_slug )` — Update remote_slug on a shared vocabulary roots entry (and clear any stale remote_name).
- `tc_fetch_remote_term_by_id( string $peer_site_slug, string $taxonomy, int $remote_term_id )` — Fetch a single remote term by its ID via the WP REST API single-resource endpoint.
- `tc_identity_redirect_queue_get()` — Return all pending redirect review items.
- `tc_identity_follow_redirect( int $term_id, string $remote_slug, array $remote )` — Point a delegated term at the remote term its old source redirects to.
- `tc_identity_decline_redirect( int $term_id )` — Keep a term's current delegation despite a pending redirect.
- `tc_get_delegated_term_ids()` — Return all term IDs that have a delegated identity source.
- `tc_flush_delegated_term_ids()` — Invalidate the delegated term IDs cache. Call whenever a term gains or loses TC_META_IDENTITY_SOURCE.
- `tc_get_stale_rel_term_ids()` — Return all term IDs that have at least one stale relationship sync issue.
- `tc_flush_stale_rel_term_ids()` — Invalidate the stale relationship term IDs cache. Call after any run of tc_sv_flag_stale_relationship_rows().

### Peer post fetching (`includes/core/external-posts.php`)

External Posts Platform

- `tc_external_validate_origin( string $origin )` — Validate that an origin URL is safe to request.
- `tc_is_self_origin( string $origin )` — Return true when the origin matches the current site's own URL.
- `tc_external_is_blacklisted( string $origin )` — Return true when the origin's host is a blocked domain or one of its subdomains.
- `tc_external_is_ht_enabled( string $origin )` — Return true when the origin runs HyperTaxonomy.
- `tc_external_is_tc_enabled( string $origin )` — Return true when the origin runs TermConnect.
- `tc_external_get_taxonomy_rest_base( string $taxonomy )` — Map a WordPress taxonomy slug to its REST API base.
- `tc_external_resolve_term_id( string $origin, string $taxonomy, string $slug )` — Resolve a remote term slug to its numeric ID on the peer site.
- `tc_external_search_terms( string $origin, string $taxonomy, string $search, int $per_page = 5 )` — Search for terms on a peer site by keyword.
- `tc_external_search_users( string $origin, string $search, int $per_page = 8 )` — Search a peer WordPress site's authors via its public /wp/v2/users endpoint.
- `tc_normalize_post_record( array $data )` — Build the canonical normalized post record consumed by the peer-post sorter and renderer.
- `tc_image_size_ratio( string $size )` — Aspect ratio (as a CSS "w / h" value) for a registered image size.
- `tc_post_list_items_style( int $columns, string $image_size )` — Build the inline style for a .tc-post-list-items container: column count and uniform thumbnail aspect ratio. Shared by [tc_peer_posts] and [tc_peer_browse] so both render multi-column grids with consistent thumbnails.
- `tc_dedupe_posts_by_url( array $records )` — Deduplicate normalized post records by URL, preserving order (first wins).
- `tc_filter_hidden_terms( array $tax_terms )` — Drop queried terms hidden from the current user, so the local blend never surfaces posts via a restricted term.
- `tc_local_term_expansions( array $tax_terms )` — Build the term => expanded-IDs map for local selection, using HyperTaxonomy's relationship expansion when available.
- `tc_collect_local_posts( array $tax_terms, string $match, int $limit, bool $show_thumb, string $image_size, array $exclude_ids = array(), array $expansions = array(), array $window = array() )` — Collect local posts matching selected terms, normalized to the shared record shape (is_local => true). Uses a plain WP_Query — no HyperTaxonomy dependency.
- `tc_external_normalize_post( array $raw, string $origin, bool $show_thumbnail )` — Normalize a raw WordPress REST post object into the standard HT external post structure.
- `tc_external_clear_site_cache( string $peer_site_slug )` — Invalidate all cached post lists for a link type by incrementing its version counter.
- `tc_external_fetch_posts( string $origin, string $taxonomy, array $all_term_ids, bool $show_thumbnail, int $fetch_size, int $ttl, string $peer_site_slug = '', array $window = array() )` — Fetch posts from a standard (non-HT) WordPress peer by taxonomy term IDs.
- `tc_external_fetch_scored_posts( string $origin, string $taxonomy, array $term_id_map, string $match, array $term_weights, bool $show_thumbnail, int $fetch_size, int $ttl, string $peer_site_slug = '', bool $expand = false, array $window = array() )` — Fetch scored posts from an HT-enabled peer using htax_* query params.
- `tc_external_fetch_relevance_posts( string $origin, string $taxonomy, array $all_term_ids, string $search_keyword, bool $show_thumbnail, int $fetch_size, int $ttl, string $peer_site_slug = '', array $window = array() )` — Fetch posts from a peer site ordered by WP text-match relevance.
- `tc_external_score_posts( array $posts, array $term_id_map, array $id_to_slug, array $term_weights )` — Apply weighted relevance scores to a set of posts from a non-HT peer.
- `tc_external_get_foaf_candidates( string $origin, array $term_taxonomy_map, array $visited )` — Collect FOAF peer candidates for a set of remote term IDs on a given origin.

### RSS and Atom feeds (`includes/core/feed-platform.php`)

Feed Platform

- `tc_validate_uri_prefix( string $uri_prefix )` — Validate that a URI prefix uses an allowed scheme and does not point to a loopback, link-local, or RFC-1918 private address. Returns true when valid, false when blocked.
- `tc_feed_parse_identifier( string $raw )` — Parse a stored feed category identifier into slug and label.
- `tc_feed_display_identifier( string $raw )` — Return the human-readable display label for a stored feed category identifier.
- `tc_feed_fetch_and_accumulate( string $peer_site_slug, string $feed_url, int $max_posts = TC_FEED_MAX_POSTS_DEFAULT )` — Fetch the feed and merge new items and categories into the accumulated caches.
- `tc_feed_get_categories( string $peer_site_slug )` — Return the accumulated category vocabulary for a feed link type.
- `tc_feed_get_cached_posts( string $peer_site_slug )` — Return the accumulated cached posts for a feed link type as an indexed array.
- `tc_feed_clear_cache( string $peer_site_slug )` — Delete both the category and post caches for a feed link type.
- `tc_feed_score_posts( array $posts, array $category_slugs, float $weight_direct, string $origin )` — Score accumulated feed posts against a set of expected category slugs.
- `tc_feed_do_poll( string $peer_site_slug )` — Cron callback: poll a single feed link type and update its caches.
- `tc_feed_schedule( string $peer_site_slug, int $interval )` — Schedule or reschedule the cron event for a feed link type.
- `tc_feed_reschedule_after_activation()` — Schedule every enabled feed on the first load after the plugin is activated.
- `tc_feed_unschedule( string $peer_site_slug )` — Remove the cron event for a feed link type.

### `includes/core/helpers.php`

Shared utilities: remote request arguments, JSON decoding, peer origin parsing and named MySQL locks.

- `tc_external_request_args( int $timeout = 8, array $headers = array() )` — Common wp_remote_get args for all requests TermConnect sends to other sites.
- `tc_external_json_decode( string $body )` — JSON-decode a REST API response body, stripping any UTF-8 BOM first.
- `tc_external_get_site_origin( string $uri_prefix )` — Extracts the site origin (scheme + host) from a link type uri_prefix.
- `tc_acquire_mysql_lock( string $name, int $timeout = 2 )` — Acquire a cross-process/cross-request mutex using a MySQL advisory lock (GET_LOCK). Unlike a get-then-set transient check, this is atomic: MySQL itself serializes concurrent GET_LOCK() calls for the same name, so two overlapping requests (e.g. a cron sync racing a manual AJAX sync) cannot both believe they hold the lock. The lock is also automatically released if the holding connection dies, so a crashed request can never leave it stuck (unlike a transient, which only expires after its TTL).
- `tc_release_mysql_lock( string $name )` — Release a lock previously acquired with tc_acquire_mysql_lock().
- `tc_external_peer_headers( bool $hypertaxonomy = false, bool $termconnect = true )` — Headers that identify TermConnect to a peer site.
- `tc_external_get_json( string $url, array $args )` — GET a URL and decode its JSON body.
- `tc_normalize_date_window( array $window )` — A date window with both keys present, in a fixed order.

### Peer site health (`includes/core/peer-site-health.php`)

Peer Site Health Tracking

- `tc_peer_site_health_get( string $slug )` — Return the health state for a single peer site by link-type slug.
- `tc_peer_health_tally_reset( string $slug )` — Clear a per-slug failure tally.
- `tc_peer_site_health_record_success( string $slug )` — Record a successful fetch from a peer site.
- `tc_peer_site_health_record_failure( string $slug, string $error, int $normal_ttl = 3600 )` — Record a failed fetch from a peer site and apply backoff.
- `tc_peer_site_health_is_blocked( string $slug )` — Return true when the peer site is in a backoff window and should not be fetched.

### REST API (`includes/core/rest-api.php`)

TermConnect REST API.

- `tc_rest_field_object_type( string $taxonomy )` — Map a taxonomy slug to the REST "object type" WP_REST_Terms_Controller actually advertises for register_rest_field() purposes.
- `tc_register_tc_links_field()` — Register a tc_links field on all TermConnect-enabled taxonomy term REST endpoints.
- `tc_rest_build_links_data( WP_Term $term )` — Build tc_links REST field data for a term.

### `includes/core/settings.php`

TermConnect settings needed in every context (front end, cron, REST).


**`TC_Settings`** — Read access to TermConnect settings.

- `TC_Settings::get_enabled_taxonomies()` — Taxonomies TermConnect is enabled for, after the tc_enabled_taxonomies filter.

### Identifier suggestions (`includes/core/suggested-links.php`)

External Identifier Suggested Links

- `tc_suggested_link_term_id( $comment )` — Term a link suggestion was made for.
- `tc_pending_suggested_link_ids( int $term_id, string $url = '' )` — IDs of pending link suggestions for a term, optionally only those for one URL.
- `tc_suggested_link_match_url( string $url )` — Match a URL against configured link type URI prefixes. Returns [ peer_site_slug, label, value, enabled ] or null.

### Term links (`includes/core/term-links.php`)

Term Links

- `tc_get_all_link_types()` — Returns all link type definitions, keyed by slug.
- `tc_get_manageable_link_types()` — Returns link types manageable in the Peer Sites admin screen, keyed by slug.
- `tc_get_browsable_peers()` — Return the site's configured peers for the block editor's peer-picker UI: enabled WordPress + feed link types, as a list of { slug, label, platform }, sorted by label.
- `tc_get_enabled_link_types()` — Returns all enabled link type definitions, keyed by slug.
- `tc_get_enabled_link_types_for_taxonomy( string $taxonomy )` — Returns enabled link type definitions allowed for a specific taxonomy, keyed by slug.
- `tc_get_platform_link_types( string $platform )` — Returns all enabled link type definitions with the given platform value, keyed by slug.
- `tc_get_link_type( string $slug )` — Returns a single link type definition, or null if not found.
- `tc_set_link_types( array $types )` — Persists the tc_link_types option from the keyed-by-slug array.
- `tc_wp_peer_remote_taxonomy( array $type, string $local_taxonomy )` — The peer taxonomy a WordPress peer link type maps to for a given local taxonomy.
- `tc_taxonomy_peer_authors( string $taxonomy )` — Whether a taxonomy's WordPress-peer links map to peer authors (WP users) rather than peer terms.
- `tc_get_link_uri( string $slug, string $id, string $taxonomy = '' )` — Constructs the full URI for a link type + ID pair.
- `tc_link_type_display_prefix( array $type, string $taxonomy = '' )` — Return the URL prefix used for URI preview and pill links in the term-edit screen.
- `tc_get_suggest_config( string $slug, string $taxonomy = '' )` — Returns the suggest configuration for a known external identifier type slug.
- `tc_parse_suggest_response( string $body, string $parser )` — Parse a raw JSON response body from an external suggest API.
- `tc_get_term_link_id( int $term_id, string $slug )` — The identifier stored for one link type on a term, whether or not the type is enabled.
- `tc_get_term_links( int $term_id, bool $include_redirect_data = false )` — Returns all external link values set on a term, keyed by slug.
- `tc_get_term_links_data( int $term_id, bool $reset = false )` — Returns full structured link data for enabled types on a term, keyed by slug.
- `tc_flush_term_link_cache( int $term_id )` — Invalidate the external link cache for a single term. Call whenever any _tc_link_* meta changes for this term.
- `tc_set_term_link( int $term_id, string $slug, string $id, string $label = '', int $fetched_at = 0 )` — Sets (creates or updates) an external link value on a term.
- `tc_remove_term_link( int $term_id, string $slug )` — Removes an external link value from a term.
- `tc_get_terms_by_uri( string $uri )` — Returns all local term IDs whose _tc_uris index contains the given URI.
- `tc_rebuild_uri_index( int $term_id )` — Rebuilds the _tc_uris multi-value meta and its transient for a term.
- `tc_build_merged_links( int $term_id, bool $reset = false )` — Builds the merged external links list for a term, combining local and delegated data.
- `tc_save_term_links( int $term_id )` — Saves external link values submitted from the term edit form.
- `tc_remove_invalid_term_links( string $slug )` — Deletes _tc_link_{slug} term meta for terms whose taxonomy violates the type's 'taxonomies' restriction. A no-op when the restriction list is empty.

## Admin

### Peer Sites page (`includes/admin/peer-sites-admin.php`)

Peer Sites Admin — Terms → Peer Sites

- `tc_handle_peer_site_add()` — Add a link type (peer site, feed or other namespace) from the Peer Sites form.

### Shared vocabulary sync (`includes/admin/shared-vocabulary-sync.php`)

Shared Vocabulary Sync — sync a remote WordPress taxonomy into the local site.

- `tc_sv_sort_sources( array $sources )` — Sort vocabulary sources into fixed scope order: full → group → roots → terms. Relative order within each scope is preserved (determines first-wins priority).
- `tc_sv_format_sync_result( array $result )` — Build a human-readable one-line summary from a sync result array.
- `tc_sv_sync_source( array $source )` — Sync a single vocabulary source against its remote peer.
- `tc_sv_remove_remote_group_memberships( int $local_group_id, string $sv_source_id )` — Remove all local group memberships for terms owned by a remote_group source. Called when the source is deleted so terms are cleanly unlinked from the local group.
- `tc_sv_fetch_taxonomy( string $origin, string $taxonomy, array $extra_fields = array() )` — Fetch all terms for a taxonomy from the remote peer (paginated flat list).
- `tc_sv_resolve_merge_proposal( array $proposal )` — Resolve a merge proposal: merge the orphan into its candidate via HyperTaxonomy. Shared by the manual "Merge" AJAX action and the auto-merge path in tc_sv_sync_source(), so both go through the same call.
- `tc_sv_conflict_check( array $source )` — Pre-sync conflict check: return local terms whose slugs match remote terms in scope but have no existing _tc_identity_source delegation to this peer_site_slug.
- `tc_sv_sync_relationship_types( string $origin )` — Fetch and register storage-only custom relationship type definitions from a peer HT site.
- `tc_sv_flag_stale_relationship_rows( array $remote_terms, array $delegations, string $peer_site_slug, string $origin, string $taxonomy )` — Detect local relationship rows (using origin-owned custom types) that no longer appear in the remote htax_relationships data for this sync run, and record them in _tc_rel_sync_issues term meta.

### Suggested Links page (`includes/admin/suggested-links-admin.php`)

Suggested Links — admin UI

- `tc_suggested_link_exists( int $term_id, string $peer_site_slug, string $value )` — Check whether a specific link already exists for a term.

## Integrations

### HyperTaxonomy integration (`includes/integration/class-hypertaxonomy-integration.php`)

HyperTaxonomy integration for TermConnect.


**`TC_HT_Integration`**

- `TC_HT_Integration::provide_term_external_posts( array $posts, int $term_id, array $args = array() )` — Provide related posts from configured peer sites and feeds for a term.

## Shortcodes

### Peer Posts shortcode and block (`includes/shortcodes/class-external-post-list.php`)

External Post List Shortcodes



**`TC_Shortcode_PeerPosts`**

- `TC_Shortcode_PeerPosts->collect_posts_for_terms( array $term_ids, array $atts )` — Fetch peer/feed posts as normalized data for an explicit set of term IDs (rather than a post's assigned terms), without rendering.
- `TC_Shortcode_PeerPosts->sort_posts( array $posts, string $orderby )` — Sort a flat normalized post array by a multi-key orderby string.
