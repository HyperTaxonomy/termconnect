---
title: Settings, Cache & Export
parent: User Guide
nav_order: 5
---

## 9. Settings & Cache

Go to **Settings → TermConnect** to configure global peer site behavior.

| Setting | Description | Default |
|---------|-------------|---------|
| **Max FOAF Depth** | Maximum hops to follow when discovering peer sites via FOAF traversal. `0` = explicitly configured peers only. Per-shortcode `foaf_depth` is capped at this value. | `1` |
| **Site Blacklist** | Origins never to query, one per line. Only scheme and host are matched. | *(empty)* |
| **External Post Lists** | How long to cache fetched post lists from peer sites, in seconds. | `3600` |
| **Peer Site Status** | How long to cache HyperTaxonomy detection and FOAF peer data, in seconds. | `86400` |

**Cache tools** (Settings → TermConnect → Cache):

- **Peer Sites — Clear All Peer Cache** — purges all peer-related transients: post lists, term ID resolutions, HyperTaxonomy status detection, and FOAF discovery data. Individual peer site and feed caches can be cleared per-entry from **Terms → Peer Sites**.
- **Peer Sites — Clear FOAF Cache** — purges only the FOAF discovery transients (`tpeer_*`, `tdata_*`). FOAF connections are rediscovered automatically on the next peer post fetch.
- **Delegated Identity — Re-sync All** — immediately refreshes descriptions and external links from all source sites, ignoring the configured cache TTL.

---

## 10. Export / Import

Go to **Settings → TermConnect → Export / Import** to back up and restore configuration.

**Export** downloads a JSON file with all link type definitions, every external link stored across all terms and taxonomies, and delegated identity sources (excluding cached mappings, which are refreshed automatically after import).

**Import** accepts a previously exported file. Link type definitions are merged (existing slugs updated; new slugs added). Shared Vocabulary sources are merged by source ID (existing IDs updated; new IDs added). Term links and identity sources are restored for any terms whose slugs match between the export and the current site.
