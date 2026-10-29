---
title: Peer Fetch API
parent: Developer Reference
nav_order: 3
---

## 19. Peer Fetch API

Public functions for fetching and scoring posts from peer sites and feeds. All caching uses WordPress transients.

### Validation

```php
tc_external_validate_origin( string $origin ): bool
// Returns true when the origin is a safe, publicly reachable HTTP(S) host.
// Blocks private IPs and loopback addresses. Call before any outbound request.

tc_external_is_blacklisted( string $origin ): bool
// Returns true when the origin is in the site blacklist setting.

tc_external_is_ht_enabled( string $origin ): bool
// Returns true when the peer runs HyperTaxonomy (checks X-HyperTaxonomy header).
// Cached per origin; failures cached 5 minutes.
```

### Term Resolution

```php
tc_external_resolve_term_id( string $origin, string $taxonomy, string $slug ): ?int
// Resolves a remote term slug to its numeric ID. Cached 24 hours.

tc_external_search_terms( string $origin, string $taxonomy, string $search, int $per_page = 5 ): array
// Searches for terms on a peer site by keyword. Returns [{id, name, slug}, ...]. Cached 5 minutes.

tc_external_get_term_data( string $origin, string $taxonomy, int $term_id ): array
// Fetches tc_links and htax_includes for a remote term (for FOAF discovery).
```

### Author Search (peer users)

```php
tc_external_search_users( string $origin, string $search, int $per_page = 8 ): array
// Searches a peer site's authors via its public /wp/v2/users endpoint.
// Returns [{id, name, slug, url}, ...]. Cached 5 minutes. Authors-only and
// best-effort: WP core exposes only users with published posts to unauthenticated
// REST, and a peer may disable the endpoint.
```

This powers the term-edit author autocomplete for "people" taxonomies (see the [`tc_taxonomy_peer_authors`](filters.html) filter): on such a taxonomy a WordPress-peer link searches the peer's authors instead of its terms, and the built-in non-configurable **"Local site"** peer (which points at this site's own origin) lists **local** authors via `get_users()`. The peer is always identified by a configured link-type slug, never a raw URL, so it is SSRF-safe.

### Post Fetching

```php
tc_external_fetch_posts( string $origin, string $taxonomy, array $all_term_ids,
    bool $show_thumbnail, int $fetch_size, int $ttl, string $peer_site_slug = '' ): array
// Standard WordPress fetch (no HyperTaxonomy scoring). Works on any WordPress site.
// Pass $peer_site_slug to scope the cache to a specific link type.

tc_external_fetch_scored_posts( string $origin, string $taxonomy, array $term_id_map,
    string $match, array $term_weights, bool $show_thumbnail, int $fetch_size, int $ttl,
    string $peer_site_slug = '', bool $expand = false ): array
// HyperTaxonomy-scored fetch. Automatically caches htax_term_peers for FOAF discovery.
// $term_id_map:   [ primary_id => [include_id, ...], ... ]
// $term_weights:  [ term_id => weight, ... ] (per-term; sent as htax_term_weights, default 1.0)
// $expand:        true sends htax_expand=1 so the peer matches via its own relationship graph

tc_external_fetch_relevance_posts( string $origin, string $taxonomy, array $all_term_ids,
    string $search_keyword, bool $show_thumbnail, int $fetch_size, int $ttl,
    string $peer_site_slug = '' ): array
// Like tc_external_fetch_posts but sends ?search={keyword}&orderby=relevance.
```

### Scoring & Cache

```php
tc_external_score_posts( array $posts, array $term_id_map, array $id_to_slug, array $term_weights ): array
// Client-side per-term weighted scoring for non-HyperTaxonomy peers ($term_weights: [ term_id => weight ]).
// Same formula as HyperTaxonomy server-side scoring.

tc_external_clear_site_cache( string $peer_site_slug ): void
// Invalidates all cached post lists for a link type. Called by the admin Clear Cache button.
// tc_external_clear_site_cache( 'my-wp-blog' );
```

### FOAF Discovery

```php
tc_external_cache_term_peers( string $remote_origin, array $raw_posts ): void
// Extracts htax_term_peers from post responses and writes to local FOAF cache.
// Called automatically by tc_external_fetch_scored_posts.

tc_external_get_foaf_candidates( string $origin, array $term_taxonomy_map, array $visited ): array
// Returns peer candidates via two mechanisms:
// A (targeted): fetches tc_links from remote term endpoints
// B (opportunistic): reads the local FOAF cache from prior responses
// Returns [{origin: string, slug: string}, ...]. Filters blacklisted and already-visited origins.
```
