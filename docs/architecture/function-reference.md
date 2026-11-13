---
title: Function Reference
parent: Architecture
nav_order: 2
---

# Function Reference
{: .no_toc }

TermConnect's functions and public and protected methods, by file, with each one's docblock summary. Private methods aren't listed. This page is generated from the code. For what other plugins and themes should call, see the [Developer Reference](../developer-reference/); for hook registrations, see the [Hook Reference](hook-reference.html).

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Plugin root

### `termconnect.php`

Main plugin file.

- `tc_plugin_activate()` — Schedule the cron events, add the term meta index, and mark feeds for rescheduling.
- `tc_add_indexes()` — Add TermConnect's term meta indexes, used for reverse identifier lookups.
- `tc_activation_purge_stale_sv_queues()` — Remove stale entries from SV-related option queues on plugin activation.
- `tc_plugin_deactivate()` — Unschedule TermConnect's cron events.

### `uninstall.php`

Uninstall script for TermConnect.

- `tc_uninstall_cleanup()` — Remove TermConnect's options, caches, cron events and index, and its data when Delete data on uninstall is on.

## Core

### `includes/core/delegated-identity.php`

Delegated Identity

- `tc_get_identity_source( int $term_id )` — Return the stored identity source for a term, or null if none.
- `tc_set_identity_source( int $term_id, string $peer_site_slug, string $remote_slug, array $remote, bool $sync_descendants = false, string $sv_source_id = '', bool $sync_name = true, bool $sync_description = true )` — Save the identity source for a term. Replaces the term's description with the remote description and saves the original locally so it can be restored if delegation is removed.
- `tc_remove_identity_source( int $term_id, array $options = array() )` — Remove the identity source from a term, optionally restoring the original name/description.
- `tc_absorb_delegated_links( int $term_id )` — Absorb inherited delegated links as local links for locally enabled link types.
- `tc_update_identity_sync_flags( int $term_id, bool $sync_name, bool $sync_description )` — Update the sync_name and sync_description flags for a delegated term.
- `tc_term_restore_mismatch( int $term_id, string $taxonomy, array $restores )` — Check that a wp_update_term() restore actually reached the database.
- `tc_sv_remove_delegations_by_source( string $sv_source_id )` — Remove all delegation meta for every term managed by a given shared vocabulary source, restoring original names and descriptions where they were backed up before syncing.
- `tc_save_delegated_links( int $term_id, array $links )` — Save delegated links data and rebuild the URI index for a term.
- `tc_fetch_remote_identity( string $peer_site_slug, string $taxonomy, string $remote_slug )` — Fetch identity data for a term from a peer WordPress site via WP REST API.
- `tc_refresh_identity_cache( int $term_id )` — Refresh cached identity data for one term. Called by cron and inline from term edit AJAX.
- `tc_acquire_mysql_lock( string $name, int $timeout = 2 )` — Acquire a cross-process/cross-request mutex using a MySQL advisory lock (GET_LOCK). Unlike a get-then-set transient check, this is atomic: MySQL itself serializes concurrent GET_LOCK() calls for the same name, so two overlapping requests (e.g. a cron sync racing a manual AJAX sync) cannot both believe they hold the lock. The lock is also automatically released if the holding connection dies, so a crashed request can never leave it stuck (unlike a transient, which only expires after its TTL).
- `tc_release_mysql_lock( string $name )` — Release a lock previously acquired with tc_acquire_mysql_lock().
- `tc_mysql_lock_name( string $name )` — Scope an advisory lock name to this site.
- `tc_sv_update_root_term_id( string $sv_source_id, string $remote_slug, int $new_term_id )` — Update remote_term_id on a shared vocabulary roots entry matched by slug.
- `tc_sv_update_root_slug( string $sv_source_id, string $old_slug, string $new_slug )` — Update remote_slug on a shared vocabulary roots entry (and clear any stale remote_name).
- `tc_fetch_remote_term_by_id( string $peer_site_slug, string $taxonomy, int $remote_term_id )` — Fetch a single remote term by its ID via the WP REST API single-resource endpoint.
- `tc_identity_redirect_queue_add( int $term_id, array $remote )` — Add a redirect to the pending review queue.
- `tc_identity_redirect_queue_get()` — Return all pending redirect review items.
- `tc_identity_redirect_queue_remove( int $term_id )` — Remove a term from the pending-redirect review queue.
- `tc_identity_follow_redirect( int $term_id, string $remote_slug, array $remote )` — Point a delegated term at the remote term its old source redirects to.
- `tc_identity_decline_redirect( int $term_id )` — Keep a term's current delegation despite a pending redirect.
- `tc_get_delegated_term_ids()` — Return all term IDs that have a delegated identity source.
- `tc_flush_delegated_term_ids()` — Invalidate the delegated term IDs cache. Call whenever a term gains or loses TC_META_IDENTITY_SOURCE.
- `tc_get_stale_rel_term_ids()` — Return all term IDs that have at least one stale relationship sync issue.
- `tc_flush_stale_rel_term_ids()` — Invalidate the stale relationship term IDs cache. Call after any run of tc_sv_flag_stale_relationship_rows().
- `tc_cron_refresh_identity_caches()` — Cron callback: refresh all stale delegated identity caches.

### `includes/core/display.php`

Frontend display — "See also" section on term archives and Gutenberg block render callbacks.

- `tc_get_term_links_see_also_html( WP_Term $term )` — Build the "See also" HTML for a term's external links.
- `tc_rest_build_links_data( WP_Term $term )` — Build tc_links REST field data for a term.
- `tc_render_peer_posts_block( $attributes, $content, $block )` — Block render callback for termconnect/peer-posts.
- `tc_render_peer_browse_block( $attributes )` — Block render callback for termconnect/peer-browse.

### `includes/core/external-posts.php`

External Posts Platform

- `tc_external_json_decode( string $body )` — JSON-decode a REST API response body, stripping any UTF-8 BOM first.
- `tc_external_validate_origin( string $origin )` — Validate that an origin URL is safe to request.
- `tc_is_self_origin( string $origin )` — Return true when the origin matches the current site's own URL.
- `tc_external_is_blacklisted( string $origin )` — Return true when the origin's host is a blocked domain or one of its subdomains.
- `tc_external_is_ht_enabled( string $origin )` — Return true when the origin runs HyperTaxonomy.
- `tc_external_is_tc_enabled( string $origin )` — Return true when the origin runs TermConnect.
- `tc_external_detect_plugin_header( string $origin, string $cache_prefix, string $header )` — Check a peer's REST index for a plugin's response header. Result cached per origin.
- `tc_external_get_taxonomy_rest_base( string $taxonomy )` — Map a WordPress taxonomy slug to its REST API base.
- `tc_external_resolve_term_id( string $origin, string $taxonomy, string $slug )` — Resolve a remote term slug to its numeric ID on the peer site.
- `tc_external_search_terms( string $origin, string $taxonomy, string $search, int $per_page = 5 )` — Search for terms on a peer site by keyword.
- `tc_external_search_users( string $origin, string $search, int $per_page = 8 )` — Search a peer WordPress site's authors via its public /wp/v2/users endpoint.
- `tc_external_get_term_data( string $origin, string $taxonomy, int $term_id )` — Fetch htax_includes and tc_links for a specific term on a peer TC site.
- `tc_external_get_term_data_batch( string $origin, string $taxonomy, array $term_ids )` — Batched counterpart to tc_external_get_term_data() for FOAF discovery: fetches htax_includes/tc_links for many terms in the same taxonomy on one peer with one HTTP request per up-to-100 term IDs, instead of one request per term.
- `tc_external_serialize_term_id_map( array $term_id_map )` — Produce a deterministic string from a term ID map for use in cache keys.
- `tc_normalize_gmt_date( $value )` — Coerce a GMT datetime string to ISO-8601 'Y-m-d\TH:i:s' (the WordPress REST format).
- `tc_normalize_post_record( array $data )` — Build the canonical normalized post record consumed by the peer-post sorter and renderer.
- `tc_image_size_ratio( string $size )` — Aspect ratio (as a CSS "w / h" value) for a registered image size.
- `tc_post_list_items_style( int $columns, string $image_size )` — Build the inline style for a .tc-post-list-items container: column count and uniform thumbnail aspect ratio. Shared by [tc_peer_posts] and [tc_peer_browse] so both render multi-column grids with consistent thumbnails.
- `tc_dedupe_posts_by_url( array $records )` — Deduplicate normalized post records by URL, preserving order (first wins).
- `tc_filter_hidden_terms( array $tax_terms )` — Drop queried terms hidden from the current user, so the local blend never surfaces posts via a restricted term.
- `tc_local_term_expansions( array $tax_terms )` — Build the term => expanded-IDs map for local selection, using HyperTaxonomy's relationship expansion when available.
- `tc_collect_local_posts( array $tax_terms, string $match, int $limit, bool $show_thumb, string $image_size, array $exclude_ids = array(), array $expansions = array(), string $modified_after = '' )` — Collect local posts matching selected terms, normalized to the shared record shape (is_local => true). Uses a plain WP_Query — no HyperTaxonomy dependency.
- `tc_external_normalize_post( array $raw, string $origin, bool $show_thumbnail )` — Normalize a raw WordPress REST post object into the standard HT external post structure.
- `tc_external_post_fields( bool $show_thumbnail, bool $ht_scored )` — Build the standard _fields string for a post fetch request.
- `tc_external_request_args( int $timeout = 8 )` — Common wp_remote_get args for all peer fetches.
- `tc_external_get_link_type_cache_version( string $peer_site_slug )` — Return the current cache version for a link type slug.
- `tc_external_clear_site_cache( string $peer_site_slug )` — Invalidate all cached post lists for a link type by incrementing its version counter.
- `tc_external_record_fetch( string $peer_site_slug, int $count )` — Record a fresh fetch result for a link type so the settings page can show the cached post count and last-fetched time without scanning all transients.
- `tc_external_fetch_posts( string $origin, string $taxonomy, array $all_term_ids, bool $show_thumbnail, int $fetch_size, int $ttl, string $peer_site_slug = '', string $modified_after = '' )` — Fetch posts from a standard (non-HT) WordPress peer by taxonomy term IDs.
- `tc_external_fetch_scored_posts( string $origin, string $taxonomy, array $term_id_map, string $match, array $term_weights, bool $show_thumbnail, int $fetch_size, int $ttl, string $peer_site_slug = '', bool $expand = false, string $modified_after = '' )` — Fetch scored posts from an HT-enabled peer using htax_* query params.
- `tc_external_fetch_relevance_posts( string $origin, string $taxonomy, array $all_term_ids, string $search_keyword, bool $show_thumbnail, int $fetch_size, int $ttl, string $peer_site_slug = '', string $modified_after = '' )` — Fetch posts from a peer site ordered by WP text-match relevance.
- `tc_external_score_posts( array $posts, array $term_id_map, array $id_to_slug, array $term_weights )` — Apply weighted relevance scores to a set of posts from a non-HT peer.
- `tc_external_cache_term_peers( string $remote_origin, array $raw_posts )` — Extract and cache WordPress peer connections from htax_term_peers in post responses.
- `tc_external_get_foaf_candidates( string $origin, array $term_taxonomy_map, array $visited )` — Collect FOAF peer candidates for a set of remote term IDs on a given origin.

### `includes/core/feed-platform.php`

Feed Platform

- `tc_validate_uri_prefix( string $uri_prefix )` — Validate that a URI prefix uses an allowed scheme and does not point to a loopback, link-local, or RFC-1918 private address. Returns true when valid, false when blocked.
- `tc_feed_parse_identifier( string $raw )` — Parse a stored feed category identifier into slug and label.
- `tc_feed_display_identifier( string $raw )` — Return the human-readable display label for a stored feed category identifier.
- `tc_feed_normalize_item( $item )` — Normalize a SimplePie_Item into the standard external post structure.
- `tc_feed_fetch_and_accumulate( string $peer_site_slug, string $feed_url, int $max_posts = TC_FEED_MAX_POSTS_DEFAULT )` — Fetch the feed and merge new items and categories into the accumulated caches.
- `tc_feed_get_categories( string $peer_site_slug )` — Return the accumulated category vocabulary for a feed link type.
- `tc_feed_get_cached_posts( string $peer_site_slug )` — Return the accumulated cached posts for a feed link type as an indexed array.
- `tc_feed_clear_cache( string $peer_site_slug )` — Delete both the category and post caches for a feed link type.
- `tc_feed_score_posts( array $posts, array $category_slugs, float $weight_direct, string $origin )` — Score accumulated feed posts against a set of expected category slugs.
- `tc_feed_do_poll( string $peer_site_slug )` — Cron callback: poll a single feed link type and update its caches.
- `tc_feed_schedule( string $peer_site_slug, int $interval )` — Schedule or reschedule the cron event for a feed link type.
- `tc_feed_reschedule_after_activation()` — Schedule every enabled feed on the first load after the plugin is activated.
- `tc_feed_unschedule( string $peer_site_slug )` — Remove the cron event for a feed link type.
- `tc_feed_cron_schedules( array $schedules )` — Register custom cron schedules for all configured feed intervals.

### `includes/core/peer-site-health.php`

Peer Site Health Tracking

- `tc_peer_site_health_get_all()` — Return the full health state map keyed by link-type slug.
- `tc_peer_site_health_get( string $slug )` — Return the health state for a single peer site by link-type slug.
- `tc_peer_site_health_save( string $slug, array $state )` — Persist updated health state for one peer site.
- `tc_peer_health_tally_increment( string $slug )` — Atomically increment the lightweight per-slug failure tally.
- `tc_peer_health_tally_reset( string $slug )` — Clear a per-slug failure tally.
- `tc_peer_site_health_record_success( string $slug )` — Record a successful fetch from a peer site.
- `tc_peer_site_health_record_failure( string $slug, string $error, int $normal_ttl = 3600 )` — Record a failed fetch from a peer site and apply backoff.
- `tc_peer_site_health_is_blocked( string $slug )` — Return true when the peer site is in a backoff window and should not be fetched.
- `tc_peer_site_health_get_unhealthy()` — Return health state for all peer sites that are not fully healthy.
- `tc_peer_site_health_check()` — WordPress Site Health test callback for peer site reachability.

### `includes/core/rest-api.php`

TermConnect REST API.

- `tc_rest_field_object_type( string $taxonomy )` — Map a taxonomy slug to the REST "object type" WP_REST_Terms_Controller actually advertises for register_rest_field() purposes.
- `tc_register_tc_links_field()` — Register a tc_links field on all TermConnect-enabled taxonomy term REST endpoints.
- `tc_rest_get_tc_links_field( array $term_data, string $field_name, WP_REST_Request $request )` — Populate the tc_links REST field for a term.
- `tc_rest_link_filter_collection_params( array $params )` — Add htax_term_uri as a valid collection param on TermConnect-enabled taxonomy term endpoints.
- `tc_rest_link_filter_query( array $args, WP_REST_Request $request )` — Apply htax_term_uri filter to term queries via the _tc_uris index.
- `tc_rest_group_members( WP_REST_Request $request )` — Return the member terms of a local HyperTaxonomy group, for use by subscribing TC peers.

### `includes/core/suggested-links.php`

External Identifier Suggested Links

- `tc_suggested_link_term_id( $comment )` — Term a link suggestion was made for.
- `tc_pending_suggested_link_ids( int $term_id, string $url = '' )` — IDs of pending link suggestions for a term, optionally only those for one URL.
- `tc_ajax_suggested_link_submit()` — Accept an anonymous or logged-in identifier suggestion for a term. Always returns {success:true} to avoid enumeration.
- `tc_suggested_link_notify_admin( int $comment_id, WP_Term $term )` — Send a TC-specific admin notification for a new suggested link. Suppresses the default WP "new comment" email via the notify_moderator filter. Follows the same format as WP's native comment moderation email.
- `tc_append_suggested_link_form( string $description )` — Inject the "Suggest a related concept" trigger into the term archive description.
- `tc_build_suggested_link_append_html( string $description, WP_Term $term )` — Build the trigger button, floating pane, and (if present) wrap an existing See Also block into a side-by-side flex row.

### `includes/core/term-links.php`

Term Links

- `tc_is_redirect( int $term_id )` — Check whether a term is a redirect term (HyperTaxonomy concept).
- `tc_get_all_redirect_term_ids()` — Return IDs of all terms that have an outgoing HT redirect. Used to suppress external link data for redirect stubs.
- `tc_get_builtin_link_types()` — Returns hardcoded definitions for all built-in link types.
- `tc_get_all_link_types()` — Returns all link type definitions, keyed by slug.
- `tc_get_manageable_link_types()` — Returns link types manageable in the Peer Sites admin screen, keyed by slug.
- `tc_get_browsable_peers()` — Return the site's configured peers for the block editor's peer-picker UI: enabled WordPress + feed link types, as a list of { slug, label, platform }, sorted by label.
- `tc_get_enabled_link_types()` — Returns all enabled link type definitions, keyed by slug.
- `tc_get_enabled_link_types_for_taxonomy( string $taxonomy )` — Returns enabled link type definitions allowed for a specific taxonomy, keyed by slug.
- `tc_get_platform_link_types( string $platform )` — Returns all enabled link type definitions with the given platform value, keyed by slug.
- `tc_external_get_site_origin( string $uri_prefix )` — Extracts the site origin (scheme + host) from a link type uri_prefix.
- `tc_get_link_type( string $slug )` — Returns a single link type definition, or null if not found.
- `tc_set_link_types( array $types )` — Persists the tc_link_types option from the keyed-by-slug array.
- `tc_is_companion_taxonomy( string $taxonomy )` — Whether a taxonomy is enabled in TermConnect by a companion plugin (through the tc_enabled_taxonomies filter) rather than by the saved option.
- `tc_wp_peer_remote_taxonomy( array $type, string $local_taxonomy )` — The peer taxonomy a WordPress peer link type maps to for a given local taxonomy.
- `tc_taxonomy_peer_authors( string $taxonomy )` — Whether a taxonomy's WordPress-peer links map to peer authors (WP users) rather than peer terms.
- `tc_wp_link_type_archive_path( array $type, string $taxonomy = '' )` — Derive the taxonomy archive path segment for a WordPress peer link type.
- `tc_get_link_uri( string $slug, string $id, string $taxonomy = '' )` — Constructs the full URI for a link type + ID pair.
- `tc_link_type_display_prefix( array $type, string $taxonomy = '' )` — Return the URL prefix used for URI preview and pill links in the term-edit screen.
- `tc_get_suggest_config( string $slug, string $taxonomy = '' )` — Returns the suggest configuration for a known external identifier type slug.
- `tc_parse_suggest_response( string $body, string $parser )` — Parse a raw JSON response body from an external suggest API.
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

### `includes/admin/class-admin.php`

TermConnect admin — menu registration and admin-level hooks.


**`TC_Admin`**

- `TC_Admin::register()` — Hook the admin menus and the plugin's Settings link.
- `TC_Admin::add_settings_link( array $links )` — Add a Settings link to the plugin's row on the Plugins screen.
- `TC_Admin::get_terms_menu_parent()` — Detect the existing Terms top-level menu (from HyperTaxonomy or ATCF) or create one. Safe to call only during admin_menu at priority ≥ 15.
- `TC_Admin::register_menus()` — Add the Peer Sites and Shared Vocabularies pages under the Terms menu, and Settings → TermConnect.

### `includes/admin/delegated-identity-ajax.php`

Delegated Identity — AJAX handlers.

- `tc_ajax_di_preview_term()` — Fetch a preview of a remote term's identity data before commit.
- `tc_ajax_di_set_source()` — Commit delegation: fetch remote data, save identity source, cache delegated links.
- `tc_ajax_di_remove()` — End delegation, restoring the original description and local links.
- `tc_ajax_di_resync_all()` — Re-sync all delegated terms, ignoring the cache TTL.
- `tc_ajax_di_update_settings()` — Update sync_name and sync_description flags for one delegated term.
- `tc_ajax_di_refresh()` — Manually trigger a cache refresh for one term's delegated identity.
- `tc_ajax_di_check_rename()` — Probe a not_found delegation by remote term ID to check whether the remote term was renamed rather than deleted.
- `tc_ajax_di_relink_rename()` — Re-link a not_found delegation to the remote term's new slug, and rename the local term's slug to match.
- `tc_ajax_di_add_link()` — Remove a not_found delegation and add the remote term as a local external link on a specified local term (which may or may not be the same term).
- `tc_ajax_di_redelegate()` — Transfer a not_found delegation from the current term to another local term whose slug matches the remote term's new slug.
- `tc_ajax_di_follow_redirect()` — AJAX: follow a pending redirect — point the delegation at the remote term the old source now redirects to (Site Health review action).
- `tc_ajax_di_decline_redirect()` — AJAX: keep the current delegation despite a pending redirect (Site Health review action).

### `includes/admin/delegated-identity-edit.php`

Delegated Identity — term edit/add screen field.

- `tc_add_delegated_identity_field( $term )` — Render the Delegated Identity field on term add/edit screens.
- `tc_render_delegated_identity_empty( int $term_id, $term, array $peer_sites )` — Render the field when NO identity source is set.
- `tc_render_delegated_identity_active( int $term_id, array $source )` — Render the field when an identity source IS set.
- `tc_render_di_panels_once()` — Output the remove-confirmation and sync-settings floating panels once per page.

### `includes/admin/delegated-identity-health.php`

Delegated Identity — WordPress Site Health integration.

- `tc_health_check_delegated_identity()` — WordPress Site Health test callback for delegated identity status.
- `tc_health_check_shared_vocabularies()` — WordPress Site Health test callback for shared vocabulary mirror issues.

### `includes/admin/export-import.php`

TermConnect Export / Import handlers.

- `tc_handle_export_links()` — Download every link type, term link and delegation as a TermConnect export file.
- `tc_import_sanitize_link_type( $type )` — Sanitize a single imported link type definition.
- `tc_handle_import_links()` — Import a TermConnect export file uploaded on the settings page.

### `includes/admin/peer-sites-admin.php`

Peer Sites Admin — Terms → Peer Sites

- `tc_render_taxonomy_checkboxes( string $name, array $selected, array $options )` — Render a group of taxonomy checkboxes for link type forms.
- `tc_ajax_peer_site_count_ineligible()` — Count term meta rows that would be deleted if the given taxonomy restrictions are applied.
- `tc_ajax_clear_peer_cache()` — AJAX: clear the cached post list for a configured WordPress peer site.
- `tc_ajax_clear_all_peer_cache()` — AJAX: clear all external peer caches (post lists, FOAF data, term IDs, HyperTaxonomy status).
- `tc_ajax_feed_categories()` — AJAX: Return the accumulated category vocabulary for a feed link type.
- `tc_ajax_peer_site_identifier_suggest()` — AJAX: Suggest identifiers for an external identifier type using its public API.
- `tc_ajax_wp_peer_suggest()` — AJAX: Suggest remote term slugs for a WordPress peer link type.
- `tc_ajax_wp_peer_author_suggest()` — AJAX: Suggest authors on a WordPress peer link type (for "people" taxonomies).
- `tc_ajax_poll_feed_now()` — AJAX: Immediately poll a feed link type and return updated stats.
- `tc_ajax_clear_foaf_cache()` — AJAX: clear all FOAF-discovered peer data (tpeer and tdata transients).
- `tc_handle_peer_site_action()` — Handle all link type form submissions (add, edit, delete, toggle).
- `tc_enqueue_peer_sites_assets( string $hook )` — Enqueue jQuery UI Sortable and the inline reorder script on the Peer Sites page.
- `tc_ajax_peer_sites_reorder()` — AJAX handler: save the new link type order.
- `tc_handle_peer_site_add()` — Add a link type (peer site, feed or other namespace) from the Peer Sites form.
- `tc_handle_peer_site_edit()` — Save changes to a link type from the Peer Sites form.
- `tc_handle_peer_site_delete()` — Delete a link type and every term link stored for it.
- `tc_handle_peer_site_toggle()` — Enable or disable a link type.
- `tc_handle_peer_site_toggle_archives()` — Turn a link type's "See also" display on term archives on or off.
- `tc_render_peer_sites_page()` — Render the Terms → Peer Sites admin page.

### `includes/admin/settings.php`

TermConnect Settings page — Settings → TermConnect.


**`TC_Settings`**

- `TC_Settings::get_enabled_taxonomies()` — Taxonomies TermConnect is enabled for, after the tc_enabled_taxonomies filter.
- `TC_Settings::handle_save()` — Registered on admin_init so wp_safe_redirect() fires before any page output. */
- `TC_Settings::render()` — Render the Settings → TermConnect page.

### `includes/admin/shared-vocabularies-ajax.php`

Shared Vocabularies — AJAX handlers.

- `tc_sv_ajax_save()` — Save (create or update) a vocabulary source.
- `tc_sv_ajax_delete()` — Delete a vocabulary source (keeps existing delegations).
- `tc_sv_ajax_sync()` — Manually trigger a sync for one source.
- `tc_sv_ajax_reorder()` — Reorder sources within a scope tab after drag-to-reorder.
- `tc_sv_ajax_merge_proposal()` — Execute a merge proposal: merge orphaned term into its candidate using HyperTaxonomy.
- `tc_sv_ajax_fetch_local_groups()` — Fetch all local HyperTaxonomy groups as a flat list with id, name, parent. Used to build the hierarchical group picker in Shared Vocabularies.
- `tc_sv_ajax_fetch_remote_taxonomies()` — Fetch public taxonomies from a peer WordPress site.
- `tc_sv_ajax_fetch_remote_groups()` — Fetch all HyperTaxonomy groups from a peer site for the Remote Groups dropdown.
- `tc_sv_ajax_search_remote_terms()` — Search terms on a peer site by keyword (for root/individual term autocomplete).
- `tc_sv_ajax_dismiss_merge()` — Dismiss a merge proposal without merging.

### `includes/admin/shared-vocabularies.php`

Shared Vocabularies — admin page (Terms → Shared Vocabularies).

- `tc_sv_render_page()` — Render the Shared Vocabularies admin page.

### `includes/admin/shared-vocabulary-sync.php`

Shared Vocabulary Sync — sync a remote WordPress taxonomy into the local site.

- `tc_sv_sort_sources( array $sources )` — Sort vocabulary sources into fixed scope order: full → group → roots → terms. Relative order within each scope is preserved (determines first-wins priority).
- `tc_sv_format_sync_result( array $result )` — Build a human-readable one-line summary from a sync result array.
- `tc_sv_sync_source( array $source )` — Sync a single vocabulary source against its remote peer.
- `tc_sv_get_sync_flags( array $config )` — Read the name and description sync settings from a vocabulary source or a term's identity source. Both are on unless explicitly turned off.
- `tc_sv_process_term( array $rt, string $peer_site_slug, string $taxonomy, string $origin, array &$delegations, array $remote_by_id, string $misplace, bool $sync_desc = true, string $sv_source_id = '', bool $sync_name = true, bool $sync_description = true )` — Process a single remote term: match by slug → link or create; detect renames.
- `tc_sv_sync_remote_group_source( array $source, array $result )` — Sync a remote-group-scoped source: fetch a remote HT group's member list via the peer's tc/v1/group-members endpoint, then create or update local terms and assign them to a local HT group (matched by slug or auto-created).
- `tc_sv_remove_remote_group_memberships( int $local_group_id, string $sv_source_id )` — Remove all local group memberships for terms owned by a remote_group source. Called when the source is deleted so terms are cleanly unlinked from the local group.
- `tc_sv_sync_local_group_source( array $source, array $result )` — Sync a local_group-scoped source: use a local HyperTaxonomy group as the term list, then delegate each member to the remote peer by slug.
- `tc_sv_apply_orphan_action( int $local_term_id, string $taxonomy, string $action )` — Apply the configured action to a delegated term confirmed missing on its remote site.
- `tc_sv_fetch_taxonomy( string $origin, string $taxonomy, array $extra_fields = array() )` — Fetch all terms for a taxonomy from the remote peer (paginated flat list).
- `tc_sv_build_delegation_map( string $peer_site_slug )` — Build a remote_term_id → local_term_id map for all delegated terms pointing at $peer_site_slug.
- `tc_sv_subtree_ids( array $roots, array $remote_by_id )` — Return all remote term IDs that are in any root's subtree.
- `tc_sv_walk_subtree( int $id, array &$children_of, array &$ids, array &$visited = array() )` — Recursively collect IDs for a subtree. */
- `tc_sv_build_remote_payload( array $rt, string $origin )` — Build the $remote payload array expected by tc_set_identity_source from a raw REST term.
- `tc_sv_detect_merge_proposals( array $orphaned_ids, array $delegations, array $remote_by_id, string $peer_site_slug )` — Detect merge proposals: orphaned terms that share a KB identifier with an active delegation from the same peer, suggesting a concept rename.
- `tc_sv_resolve_merge_proposal( array $proposal )` — Resolve a merge proposal: merge the orphan into its candidate via HyperTaxonomy. Shared by the manual "Merge" AJAX action and the auto-merge path in tc_sv_sync_source(), so both go through the same call.
- `tc_sv_flag_misplaced( int $local_term_id )` — Store a term in the misplacement review queue (Site Health).
- `tc_sv_conflict_check( array $source )` — Pre-sync conflict check: return local terms whose slugs match remote terms in scope but have no existing _tc_identity_source delegation to this peer_site_slug.
- `tc_sv_sync_relationship_types( string $origin )` — Fetch and register storage-only custom relationship type definitions from a peer HT site.
- `tc_sv_sync_relationship_rows( array $remote_terms, array $delegations, string $taxonomy, bool $cross_taxonomy, string $peer_site_slug )` — Write local relationship rows from htax_includes and htax_relationships fields bundled in the peer collection response.
- `tc_sv_flag_stale_relationship_rows( array $remote_terms, array $delegations, string $peer_site_slug, string $origin, string $taxonomy )` — Detect local relationship rows (using origin-owned custom types) that no longer appear in the remote htax_relationships data for this sync run, and record them in _tc_rel_sync_issues term meta.
- `tc_cron_sync_shared_vocabularies()` — Cron callback: sync each active shared vocabulary source whose interval has passed.

### `includes/admin/suggested-links-admin.php`

Suggested Links — admin UI

- `tc_suggested_link_match_url( string $url )` — Match a URL against configured link type URI prefixes. Returns [ peer_site_slug, label, value, enabled ] or null.
- `tc_suggested_link_exists( int $term_id, string $peer_site_slug, string $value )` — Check whether a specific link already exists for a term.
- `tc_suggested_link_resolve_local_term( string $url )` — Attempt to resolve a local URL to a WP_Term by extracting the slug from the path.

### `includes/admin/term-edit.php`

Term edit screen — External Links field.

- `tc_enqueue_term_edit_css( string $hook )` — Enqueue the TermConnect term-edit stylesheet on taxonomy term add/edit screens for TermConnect-enabled taxonomies. Works whether or not HyperTaxonomy is active.
- `tc_quick_edit_delegation_script()` — Output Quick Edit read-only behavior for delegated terms.
- `tc_add_external_links_field( $term )` — Render the "External Links" section on HT-enabled term edit screens.
- `tc_render_suggested_links_panel( WP_Term $term )` — Render the collapsible "Suggested identifiers" panel on the term edit screen.

### `includes/admin/term-list.php`

Term list column, sortable, and filter for External Links.

- `tc_add_ext_links_column( array $columns )` — Add the Links column to term lists, after Visibility when HyperTaxonomy is active.
- `tc_make_ext_links_column_sortable( array $sortable )` — Make the External Links column sortable on term list screens.
- `tc_make_di_issues_column_sortable( array $sortable )` — Make the Sync column sortable on term list screens.
- `tc_add_di_issues_column( array $columns )` — Add the Sync column, which flags delegated identity sync issues, to term lists.
- `tc_render_di_issues_column( string $output, string $column, int $term_id )` — Render a term's sync issues in the Sync column.
- `tc_render_ext_links_column( string $output, string $column, int $term_id )` — Render a term's external link count in the External Links column.
- `tc_count_ext_links_for_term( int $term_id )` — Count the number of external link types set for a term.
- `tc_sort_terms_by_ext_links( array $clauses, array $taxonomies, array $args )` — Order term lists by external link count.
- `tc_sort_terms_by_di_issues( array $clauses, array $taxonomies, array $args )` — Order term lists by sync issues.
- `tc_apply_ext_links_filter( WP_Term_Query $query )` — Filter term lists by whether terms have external links, or by link type.
- `tc_apply_di_issues_filter( WP_Term_Query $query )` — Filter term lists by sync issue.
- `tc_ext_links_filter_sync_script()` — Print the script that places the External Links filter beside the term list search form.

## Integrations

### `includes/integration/class-hypertaxonomy-integration.php`

HyperTaxonomy integration for TermConnect.


**`TC_HT_Integration`**

- `TC_HT_Integration::init()` — Hook TermConnect into HyperTaxonomy's filters, actions and admin screens.
- `TC_HT_Integration::provide_term_uris( array $uris, int $term_id )` — Add a term's TermConnect identifier URIs to HyperTaxonomy's htax_term_uris filter.
- `TC_HT_Integration::provide_term_external_posts( array $posts, int $term_id, array $args = array() )` — Provide related posts from configured peer sites and feeds for a term.
- `TC_HT_Integration::provide_peer_sites( array $sites )` — Expose TermConnect's configured peers (WordPress + feed link types) to HyperTaxonomy's read-only peer-site registry.
- `TC_HT_Integration::register_rest_fields()` — Register the htax_term_peers REST field on posts.
- `TC_HT_Integration::rest_get_htax_term_peers( array $post )` — REST field callback: peer site connections for a post's terms, in HyperTaxonomy's scored post responses.
- `TC_HT_Integration::render_rel_type_discovery()` — Render a "Discover from peer site" section below the relationship type tables. Only shown when TC has at least one WordPress peer configured.
- `TC_HT_Integration::sync_remote_relationship_types( array $result, string $source_site )` — Handle the htax_sync_remote_relationship_types filter fired from the HT admin when the Sync action is clicked on a remote relationship type row.
- `TC_HT_Integration::ajax_discover_rel_types()` — AJAX handler: discover and import relationship types from a peer site.
- `TC_HT_Integration::snapshot_links_before_merge( array $source_ids, int $target_id, string $taxonomy, bool $create_redirect )` — Snapshot external links from each source term before the merge transaction runs.
- `TC_HT_Integration::apply_links_after_merge( array $source_ids, int $target_id, string $taxonomy, array $result )` — Copy external links from source terms to the merge target.
- `TC_HT_Integration::clean_sv_queues_on_deleted( int $term_id, int $tt_id, string $taxonomy )` — Clean up TC state when a term is permanently deleted.
- `TC_HT_Integration::clean_sv_queues_on_bulk_deleted( int $tt_id )` — Flush caches and SV queues for bulk taxonomy operations where deleted_term does not fire.
- `TC_HT_Integration::clean_sv_source_on_delete( int $tt_id )` — Remove a deleted term from 'terms' or 'roots' scope Shared Vocabulary sources.

## Shortcodes

### `includes/shortcodes/class-external-post-list.php`

External Post List Shortcodes

- `tc_process_external_post_placeholders( string $text, array $post, array $origin_labels )` — Replace template tokens in a string using normalized external post data.


**`TC_Shortcode_PeerPosts`**

- `TC_Shortcode_PeerPosts->__construct()` — Register the [tc_peer_posts] shortcode and its load-more AJAX handlers.
- `TC_Shortcode_PeerPosts::instance()` — Shared singleton instance.
- `TC_Shortcode_PeerPosts->get_defaults()` *(protected)* — Default attribute values shared between shortcode and block contexts.
- `TC_Shortcode_PeerPosts->shortcode_handler( $atts )` — Shortcode handler — guards singular context then delegates to fetch_and_render.
- `TC_Shortcode_PeerPosts->fetch_and_render( int $post_id, array $atts )` — Core fetch-and-render logic, usable without the is_singular() guard.
- `TC_Shortcode_PeerPosts->collect_posts_for_terms( array $term_ids, array $atts )` — Fetch peer/feed posts as normalized data for an explicit set of term IDs (rather than a post's assigned terms), without rendering.
- `TC_Shortcode_PeerPosts->enqueue_styles()` *(protected)* — Enqueue the shared post-list base stylesheet (grid layout + uniform thumbnails). Shared by [tc_peer_posts] and [tc_peer_browse].
- `TC_Shortcode_PeerPosts->enqueue_scripts()` *(protected)* — Enqueue the load-more JS on the frontend.
- `TC_Shortcode_PeerPosts->ajax_load_more()` — AJAX handler: load the next page of external posts from the cache.
- `TC_Shortcode_PeerPosts->collect_tuples( int $post_id, string $link_type_filter, string $tax_filter, ?array $explicit_term_ids = null )` — Collect (origin, taxonomy, peer_site_slug, term_id, remote_slug) tuples from the current post's terms and their link type meta.
- `TC_Shortcode_PeerPosts->resolve_term_ids( array $group_tuples, string $origin, string $taxonomy, bool $include_synonyms = true, bool $include_related = false, array $source_strengths = array() )` — Resolve a group of tuples to a term_id_map, id_to_slug index, and AND-mode groups.
- `TC_Shortcode_PeerPosts->filter_and_match( array $posts, array $and_groups )` — Client-side AND filter for non-HT peers.
- `TC_Shortcode_PeerPosts->fetch_foaf( string $origin, array $term_taxonomy_map, string $match, string $taxonomy, bool $show_thumbnail, int $fetch_size, int $cache_ttl, int $depth, int $max_depth, array $visited, bool $expand = false, string $modified_after = '' )` — Recursively fetch posts from FOAF-discovered peer sites.
- `TC_Shortcode_PeerPosts->sort_posts( array $posts, string $orderby )` — Sort a flat normalized post array by a multi-key orderby string.
- `TC_Shortcode_PeerPosts->render_items( array $posts, bool $show_thumbnail, bool $show_excerpt, string $meta_tpl = '{date} · {label}', string $prepend_tpl = '', string $append_tpl = '', string $format = 'div', string $template = 'default' )` — Render individual post items as HTML article elements (no wrapper).
- `TC_Shortcode_PeerPosts->render( array $posts, string $extra_class, bool $show_thumbnail, bool $show_excerpt, string $meta_tpl = '{date} · {label}', string $prepend_tpl = '', string $append_tpl = '', int $next_offset = -1, int $post_id = 0, array $atts = array() )` — Render the full post list wrapper, items, and optional load-more button.

### `includes/shortcodes/class-peer-browse.php`

Interactive cross-network topic browser.


**`TC_Shortcode_PeerBrowse`**

- `TC_Shortcode_PeerBrowse->__construct()` — Register the [tc_peer_browse] shortcode and its AJAX handlers.
- `TC_Shortcode_PeerBrowse::instance()` — Shared singleton instance (so the block render callback can reuse it without re-registering the shortcode and AJAX hooks).
- `TC_Shortcode_PeerBrowse->get_defaults()` — Default attribute values shared between shortcode and block contexts.
- `TC_Shortcode_PeerBrowse->shortcode_handler( $atts )` — Shortcode handler — renders the facet controls plus the initial results.
- `TC_Shortcode_PeerBrowse->ajax_browse()` — AJAX: return a page of combined results for the current facet state.
- `TC_Shortcode_PeerBrowse->ajax_term_suggest()` — AJAX: term autocomplete suggestions for the configured taxonomy.
