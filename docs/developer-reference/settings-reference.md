---
title: Settings Reference
parent: Developer Reference
nav_order: 5
---

## 21. Settings Reference

TermConnect stores its settings in the `tc_settings` WordPress option.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `expose_links_api` | bool | `true` | When `true`, external links are included in REST responses when the request carries `X-TermConnect`. The consuming site may connect terms based on overlapping external links. When `false`, suppresses `tc_links` even for TC-aware requests. |
| `link_types_show_see_also` | bool | `true` | Show "See also" section on term archives |
| `see_also_format` | string | `'inline'` | `'inline'` or `'list'` |
| `see_also_label` | string | `'See also: '` | Label prepended in inline format |
| `max_foaf_depth` | int | `1` | Maximum FOAF hops (0–5) |
| `external_posts_cache_ttl` | int | `3600` | Post list cache TTL in seconds |
| `ht_status_cache_ttl` | int | `86400` | Peer plugin detection cache TTL in seconds (HyperTaxonomy and TermConnect presence checks) |
| `identity_cache_ttl` | int | `86400` | Delegated identity refresh interval in seconds |
| `auto_accept_redirects` | bool | `false` | Automatically accept simple identity source redirects without queuing for review |
| `auto_accept_chain_redirects` | bool | `false` | Automatically follow chain redirects when refreshing delegated identity |
| `identity_chain_max_depth` | int | `3` | Maximum chain depth when following identity redirects (1–5) |
| `allow_suggested_links` | bool | `false` | Show suggestion form on term archive pages |
| `suggested_link_label` | string | `'Suggest a related concept'` | Label shown above the suggestion form |
| `suggested_link_notifications` | bool | `true` | Email admin when a suggestion is submitted |
| `delete_on_uninstall` | bool | `false` | Delete all term link and delegated identity data, and visitor link suggestions, when the plugin is uninstalled (settings, scheduled tasks and caches are always removed) |
| `external_site_blacklist` | string[] | `[]` | Blocked domains |

Enabled taxonomies are stored separately in `tc_enabled_taxonomies` as a flat array of taxonomy slugs. Link type definitions are stored in `tc_link_types`. Shared Vocabularies source configs are stored in `tc_shared_vocabularies`. The delegated identity redirect review queue is stored in `tc_identity_redirect_queue`.
