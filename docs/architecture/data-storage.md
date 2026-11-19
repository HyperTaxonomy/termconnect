---
title: Data Storage
parent: Architecture
nav_order: 4
---

# Data Storage
{: .no_toc }

TermConnect has no tables of its own; the functions that read and write its term meta are in the [Term Links API](../developer-reference/term-links-api.html). It stores data in term meta, comments, options and transients, and adds one index to the term meta table. For the keys inside `tconn_settings`, see [Stored Settings](../developer-reference/stored-settings.html). For what uninstalling removes, see [Deactivation & Uninstall](../user-guide/deactivation-uninstall.html#deleting-the-plugin).

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Term meta

| Key | Value |
|---|---|
| `_tconn_link_{slug}` | The term's identifier for link type `{slug}`, with its label and fetch time. |
| `_tconn_uris` | One row per full identifier URI, from the term's own and delegated links. This is the index used to find a term by identifier, for example by `?htax_term_uri=`. |
| `_tconn_identity_source` | The delegation target, its cache timestamps and status, and the sync flags. |
| `_tconn_delegated_links_data` | The external links fetched from the delegation source. |
| `_tconn_original_name` | The term's name before delegation, restored when delegation ends. |
| `_tconn_original_description` | The term's description before delegation, restored when delegation ends. |
| `_tconn_rel_sync_issues` | Relationship sync problems found by shared vocabulary sync, shown in the Sync column. |

### Index

`tconn_link_reverse` on `{prefix}termmeta (meta_key(60), meta_value(64))` speeds up finding terms by identifier. Activation adds it, and uninstalling drops it.

## Comments

Link suggestions from visitors are stored as unapproved comments with `comment_type` `tconn_suggested_link`. Comment meta `_tconn_term_id` holds the term the suggestion is for.

## Options

| Option | Value |
|---|---|
| `tconn_settings` | Global settings. See [Stored Settings](../developer-reference/stored-settings.html). |
| `tconn_enabled_taxonomies` | Taxonomies TermConnect is enabled for. |
| `tconn_link_types` | Link type definitions: peer sites, feeds and identifier namespaces. |
| `tconn_shared_vocabularies` | Shared vocabulary sources and their scope, sync fields and schedule. |
| `tconn_peer_site_health` | Health state for each peer, keyed by link type. |
| `tconn_ph_tally_{slug}` | A peer's failure count, kept separate so counting doesn't rewrite the full health state. |
| `tconn_ext_ltfetch_{slug}` | Fetch statistics for a peer, shown on the Peer Sites screen. |
| `tconn_ext_ltver_{slug}` | Cache version for a peer. Raising it retires that peer's cached responses. |
| `tconn_feed_poll_stat_{slug}` | The result of a feed's last poll. |
| `tconn_feed_reschedule` | `1` after activation, so feeds are scheduled again on the next load; `0` otherwise. Kept (autoloaded) rather than deleted, so reading it costs no query. |
| `tconn_identity_redirect_queue` | Delegated terms whose source now redirects, waiting for review in Site Health. |
| `tconn_merge_proposals` | Shared vocabulary merge proposals waiting for review. |
| `tconn_sv_misplaced_queue` | Mirrored terms in the wrong place, waiting for review in Site Health. |

## Transients

| Key | Holds |
|---|---|
| `tconn_links_{term_id}` | A term's merged external links. |
| `tconn_uris_{term_id}` | A term's identifier URIs. |
| `tconn_term_ids_tconn_identity_source` | IDs of all terms with a delegated identity. |
| `tconn_term_ids_tconn_rel_sync_issues` | IDs of terms with stale relationship sync issues. |
| `tconn_ext_tid_*`, `tconn_ext_tsrch_*`, `tconn_ext_usrch_*`, `tconn_ext_tdata_*`, `tconn_ext_tpeer_*` | Responses from peers: term lookups, term and user searches, term data, and a term's peer connections. |
| `tconn_ext_posts_*`, `tconn_ext_scored_*`, `tconn_ext_rel_*` | Peer post lists, scored post lists and related posts. |
| `tconn_feed_cats_{slug}` | A feed's categories, kept for 90 days. |
| `tconn_feed_posts_{slug}` | A feed's posts, kept for 30 days. |
| `tconn_lt_error_{user_id}` | A Peer Sites form error, shown after the redirect. |
| `tconn_sugg_rate_*`, `tconn_suggest_global_*` | Rate limits for link suggestions from logged-out visitors, by IP address: 3 per term per day, and 10 in all per hour. |

## Cron events

| Event | Schedule | Runs |
|---|---|---|
| `tconn_refresh_identity_caches` | Twice daily | Refreshes cached data for delegated terms. |
| `tconn_sync_shared_vocabularies` | Hourly | Syncs each shared vocabulary source whose interval has passed. |
| `tconn_feed_poll` | Per feed, from 5 minutes to 1 day | Polls one feed. Each feed has its own event, with the feed's slug as its argument, on a `tconn_{seconds}s` schedule. |
