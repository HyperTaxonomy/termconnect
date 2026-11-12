---
title: HyperTaxonomy Integration
nav_order: 4
---

# HyperTaxonomy Integration

TermConnect works on any WordPress site. When HyperTaxonomy is active too, the features below become available. Each one links to where it's described in full.

## For site owners

- **Better-ranked peer posts.** Peers running HyperTaxonomy score posts with their own search index, using term weights that TermConnect derives from your local relevance settings, and with `scope="all"`, local posts are ranked by relevance too. See [Displaying Peer Posts](user-guide/displaying-peer-posts.html#tc_peer_posts-shortcode).
- **Relationship delegation and Remote Groups.** A shared vocabulary can also bring in the source's term relationships, and the Remote Groups tab mirrors a HyperTaxonomy term group from a peer, which needs HyperTaxonomy on both sites. See [Relationship delegation](user-guide/shared-vocabularies.html#relationship-delegation) and [Scope tabs](user-guide/shared-vocabularies.html#scope-tabs).
- **Relationship types from peers.** **Terms → Term Relationship Types** gets a **Discover Relationship Types from Peer** section when you have a WordPress peer, for importing a peer's custom relationship types.
- **Redirect detection.** A peer running HyperTaxonomy reports when a delegated term now redirects elsewhere, and the change waits for your review in Site Health. See [Redirect detection](user-guide/delegated-identity.html#redirect-detection).
- **"See also" inside descriptions.** When HyperTaxonomy shows a term's description on its archive, the "See also" section appears at the end of it. See [Archives & Suggestions](user-guide/archives-suggestions.html#see-also-on-archives).
- **Merges keep external links.** When HyperTaxonomy merges terms, the merged-away terms' external links are copied to the term that remains.

## For developers

| HyperTaxonomy hook or API | What TermConnect does |
|---|---|
| `htax_term_uris` filter | Adds each term's identifier URIs, so `htax_get_term_uris()` returns them. WP-SKOS publishes them as `skos:closeMatch`. See [Filters](developer-reference/filters.html#htax_term_uris-hypertaxonomy-defined). |
| `htax_term_external_posts` filter | Provides related posts from your peers and feeds, read through `htax_get_term_external_posts()`. See the [External Documents API](developer-reference/external-documents.html). |
| `htax_peer_sites` filter | Lists TermConnect's WordPress peers and feeds in HyperTaxonomy's read-only peer-site registry. |
| `htax_term_uris_changed` action | HyperTaxonomy re-fires TermConnect's `tc_term_uris_changed` under this name; plugins that integrate through HyperTaxonomy should hook it. See the [Term Links API](developer-reference/term-links-api.html). |
| `htax_before_merge_terms`, `htax_after_merge_terms` actions | Copy external links from merged-away terms to the remaining term. |
| `htax_sync_remote_relationship_types` filter | Syncs a relationship type imported from a peer. |
| `X-HyperTaxonomy` request header | Sent on peer collection requests, so a peer running HyperTaxonomy includes its redirect and relationship fields. See the [REST API](developer-reference/rest-api.html). |
| `htax_term_peers` REST field | Added to HyperTaxonomy's scored post responses: each term's peer site connections. |

HyperTaxonomy also decides which terms are hidden: `htax_get_term_external_posts()` returns nothing for a term the visitor can't see.
