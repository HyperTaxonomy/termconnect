---
title: Working with HyperTaxonomy
parent: User Guide
nav_order: 7
---

# Working with HyperTaxonomy

TermConnect works on any WordPress site. When HyperTaxonomy is active too, the features below become available.

- **Better-ranked peer posts.** Peers running HyperTaxonomy score posts with their own search index, using term weights that TermConnect derives from your local relevance settings, and with `scope="all"`, local posts are ranked by relevance too. See [Displaying Peer Posts](displaying-peer-posts.html#tc_peer_posts-shortcode).
- **Relationship delegation and Remote Groups.** A shared vocabulary can also bring in the source's term relationships, and the Remote Groups tab mirrors a HyperTaxonomy term group from a peer, which needs HyperTaxonomy on both sites. See [Relationship delegation](shared-vocabularies.html#relationship-delegation) and [Scope tabs](shared-vocabularies.html#scope-tabs).
- **Relationship types from peers.** **Terms → Term Relationship Types** gets a **Discover Relationship Types from Peer** section when you have a WordPress peer, for importing a peer's custom relationship types.
- **Redirect detection.** A peer running HyperTaxonomy reports when a delegated term now redirects elsewhere, and the change waits for your review in Site Health. See [Redirect detection](delegated-identity.html#redirect-detection).
- **"See also" inside descriptions.** When HyperTaxonomy shows a term's description on its archive, the "See also" section appears at the end of it. See [Archives & Suggestions](archives-suggestions.html#see-also-on-archives).
- **Merges keep external links.** When HyperTaxonomy merges terms, the remaining term gets each merged-away term's external links for link types it doesn't already have. Delegated identity isn't carried over.

For developers, the HyperTaxonomy hooks TermConnect uses are described in the Developer Reference: [Actions & Filters](../developer-reference/actions-filters.html#term-uris-for-other-plugins) covers `htax_term_uris`, `htax_term_external_posts`, `htax_peer_sites`, the merge actions and `htax_sync_remote_relationship_types`; the [External Documents API](../developer-reference/external-documents.html) covers how peer posts reach other plugins; and the [Peer Fetch API](../developer-reference/peer-fetch-api.html) and [REST API](../developer-reference/rest-api.html) cover the `X-HyperTaxonomy` header and the `htax_term_peers` field.
