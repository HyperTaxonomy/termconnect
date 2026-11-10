---
title: Settings, Cache & Export
parent: User Guide
nav_order: 7
---

# Settings, Cache & Export

## Settings

Go to **Settings → TermConnect** to configure global peer site behavior.

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
| **Delete all data** | See [Uninstalling](#uninstalling). | Off |

---

## Export / Import

Go to **Settings → TermConnect → Export / Import** to back up and restore configuration.

**Export** downloads a JSON file with all link type definitions, every external link stored across all terms and taxonomies, delegated identity sources with each term's original name and description, and Shared Vocabulary sources. Cached delegated links aren't included, and are refreshed automatically after import. Settings and enabled taxonomies aren't included either, so set those up again when restoring to a new site.

**Import** accepts a previously exported file. Link type definitions are merged (existing slugs updated; new slugs added). Shared Vocabulary sources are merged by source ID (existing IDs updated; new IDs added). Term links and identity sources are restored for any terms whose slugs match between the export and the current site.

---

## Uninstalling

Deactivating the plugin never deletes data. Deleting it from the **Plugins** screen always removes TermConnect's settings, scheduled tasks, cached data, review queues and its database index.

Everything else stays, so a reinstall works as before, unless **Delete all data** is on (**Settings → TermConnect → Advanced**). With it on, deleting the plugin also removes link types, enabled taxonomies, Shared Vocabulary sources, per-term identifiers, delegated identity, and visitor link suggestions.
