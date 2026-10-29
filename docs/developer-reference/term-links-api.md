---
title: Term Links API
parent: Developer Reference
nav_order: 2
---

## 18. Term Links API

### Registry

| Function | Returns | Description |
|----------|---------|-------------|
| `tc_get_all_link_types()` | `array` | All registered types (built-in + custom), merged with the `tc_link_types` filter. Keyed by slug. |
| `tc_get_enabled_link_types()` | `array` | Enabled types only. |
| `tc_get_enabled_link_types_for_taxonomy( string $taxonomy )` | `array` | Enabled types available for the given taxonomy (respects per-type restrictions). |
| `tc_get_manageable_link_types()` | `array` | Types editable in the Peer Sites admin screen — excludes plugin-managed types (those with a non-empty `provider`). |
| `tc_get_link_type( string $slug )` | `?array` | Single type definition, or `null` if not found. |

### Per-Term

| Function | Returns | Description |
|----------|---------|-------------|
| `tc_get_term_links( int $term_id, bool $include_redirect_data = false )` | `array` | `[slug => id]` map for all enabled link types that have a value set on the term. Redirect terms return `[]` unless `$include_redirect_data` is true. Delegates to `tc_get_term_links_data()` and extracts the `id` field from each entry. |
| `tc_get_term_links_data( int $term_id, bool $reset = false )` | `array` | `[slug => {id, label, fetched_at}]` for enabled types with values. The canonical read function: has two-layer cache (static per-request + `tc_links_{term_id}` transient, TTL=0, explicit invalidation). Raw data stored in the transient includes all link slugs regardless of which types are currently enabled, so enabling/disabling a type does not stale the cache. |
| `tc_get_link_uri( string $slug, string $id, string $taxonomy = '' )` | `?string` | Full URI for a given link type slug and identifier. |
| `tc_get_terms_by_uri( string $uri )` | `int[]` | Reverse lookup via `_tc_uris` meta index: all local term IDs that have this URI stored (exact match). Works for both local and delegated links. Faster than scanning `_tc_link_*` meta directly. |
| `tc_set_term_link( int $term_id, string $slug, string $id, string $label = '', int $fetched_at = 0 )` | `bool` | Set a single identifier on a term, storing `{id, label, fetched_at}` as structured meta. Calls `tc_rebuild_uri_index()` and `tc_flush_term_link_cache()` after writing. |
| `tc_remove_term_link( int $term_id, string $slug )` | `bool` | Remove a single identifier from a term. Calls `tc_rebuild_uri_index()` and `tc_flush_term_link_cache()` after writing. |
| `tc_save_term_links( int $term_id )` | `void` | Save identifiers from `$_POST`. Called automatically on `saved_term`. |
| `tc_flush_term_link_cache( int $term_id )` | `void` | Delete the `tc_links_{term_id}` transient. Called automatically by all write paths; call explicitly when writing `_tc_link_*` meta directly. |
| `tc_rebuild_uri_index( int $term_id )` | `void` | Delete all `_tc_uris` meta rows for the term and rebuild from scratch by combining local identifiers and delegated links, applying override logic (delegated wins on same site). Sets `tc_uris_{term_id}` transient. Called automatically by all write paths; call explicitly when writing link or delegated meta directly. |
| `tc_build_merged_links( int $term_id, bool $reset = false )` | `array` | Returns a flat array of `{url, platform, label, fetched_at}` objects combining all local and delegated links. Applies override logic: when a delegated link and a local link share the same URI prefix (same site), the delegated link wins and the local link is omitted. Used internally by the REST `tc_links` field and the See Also display. |

### Actions

| Action | Arguments | Fired when |
|--------|-----------|------------|
| `tc_term_uris_changed` | `int $term_id` | Fired by `tc_flush_term_link_cache()` whenever any external link for a term is saved or deleted. Use this to invalidate caches that depend on a term's external URI set. |

HyperTaxonomy hooks this action and re-fires it as `htax_term_uris_changed`. Plugins that integrate through HyperTaxonomy should hook the HT-namespaced action instead of this one.

### Database Index

TermConnect adds one index to `wp_termmeta` at activation:

- `tc_link_reverse (meta_key(60), meta_value(64))` — reverse lookup of terms by external link identifier. Used by functions like `tc_get_terms_by_uri()` that need to find all terms sharing a given identifier.

### Link Suggestions

Visitor link suggestions (see the user guide's *Archives & Suggestions*) are WordPress comments with `comment_type = 'tc_suggested_link'` and `comment_approved = '0'` while pending. A suggestion belongs to a term, not a post: `comment_post_ID` is `0` and the term ID is in comment meta `_tc_term_id` (`TC_META_SUGGESTION_TERM_ID`). Term and post IDs are separate sequences, so storing the term ID in `comment_post_ID` would attach the suggestion to whichever post happens to have that ID — showing in that post's pending-comments bubble and being deleted along with it. `comment_author_url` holds the submitted URL.

| Function | Returns | Description |
|----------|---------|-------------|
| `tc_suggested_link_term_id( WP_Comment\|int $comment )` | `int` | The term a suggestion was made for (0 when missing). |
| `tc_pending_suggested_link_ids( int $term_id, string $url = '' )` | `int[]` | IDs of a term's pending suggestions, optionally only those for one exact URL. Used for duplicate detection, trashing a term's suggestions when the term is deleted, and trashing the matching suggestion when its link is saved. |

To list a term's suggestions with `get_comments()`, query by meta: `'type' => 'tc_suggested_link', 'meta_key' => '_tc_term_id', 'meta_value' => (string) $term_id`.

### Cache Management

The following functions manage site-wide caches for delegated identity metadata. They are internal helpers but relevant for developers who write to identity source or relationship sync meta directly.

| Function | Returns | Description |
|----------|---------|-------------|
| `tc_get_delegated_term_ids()` | `int[]` | All term IDs with `_tc_identity_source` meta. Cached as `tc_delegated_term_ids` transient (TTL=0). Flushed when a term's identity source is set or removed, and on import when delegations are included. |
| `tc_flush_delegated_term_ids()` | `void` | Delete the `tc_delegated_term_ids` transient. |
| `tc_get_stale_rel_term_ids()` | `int[]` | All term IDs with `_tc_rel_sync_issues` meta. Cached as `tc_stale_rel_term_ids` transient (TTL=0). Flushed after `tc_sv_flag_stale_relationship_rows()` writes rows. |
| `tc_flush_stale_rel_term_ids()` | `void` | Delete the `tc_stale_rel_term_ids` transient. |
