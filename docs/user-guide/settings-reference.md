---
title: Settings Reference
parent: User Guide
nav_order: 3
---

# Settings Reference

Every setting at **Settings → TermConnect**, and the cache tools on its Cache tab. Peer sites and feeds themselves are set up at **Terms → Peer Sites**; see [Peer Sites & Feeds](peer-sites-feeds.html).

**General**

- **Enabled Taxonomies** — see [Getting started](../#enabled-taxonomies).
- **See Also** and **Identifier Suggestions** — see [Archives & Suggestions](archives-suggestions.html).

**Peer Sites**

| Setting | Description | Default |
|---------|-------------|---------|
| **Max FOAF Depth** | Number of hops beyond directly configured peer sites. `0` = configured sites only. Each shortcode's `foaf_depth` is capped at this value. | `1` |
| **Blocked Sites** | Domains never to query, one per line. A domain also blocks its subdomains. | *(empty)* |

**Shared Vocabularies**

| Setting | Description | Default |
|---------|-------------|---------|
| **Auto-accept redirects** | Follow a remote term's redirect automatically instead of queuing it for review. See [Delegated Identity](delegated-identity.html). | Off |
| **Auto-follow chain redirects** | Also follow a redirect whose target is itself a redirect. | Off |
| **Max chain depth** | How many redirects to follow in a chain, from 1 to 5. | `3` |

**Cache**

| Setting | Description | Default |
|---------|-------------|---------|
| **Peer Site Status** | How long to cache whether each peer runs HyperTaxonomy, and FOAF peer data, in seconds. | `86400` |
| **External Post Lists** | How long to cache post lists fetched from peer sites, in seconds. | `3600` |
| **Shared Vocabularies** | How often delegated term data is refreshed from source sites, in seconds. | `86400` |

The Cache tab also has these tools:

- **Clear All Peer Cache** purges all peer-related cached data: post lists, term ID lookups, HyperTaxonomy status detection, and FOAF discovery data. Individual peer site and feed caches can be cleared from **Terms → Peer Sites**.
- **Clear FOAF Cache** purges only FOAF discovery data. FOAF connections are rediscovered automatically on the next peer post fetch.
- **Re-sync All** immediately refreshes names, descriptions and external links for all delegated terms, ignoring the cache setting.

**Advanced**

| Setting | Description | Default |
|---------|-------------|---------|
| **Share external links** | Include each term's external identifiers and peer site links in REST responses for requests from other TermConnect sites. | On |
| **Delete all data** | See [Deactivation & Uninstall](deactivation-uninstall.html#deleting-the-plugin). | Off |
