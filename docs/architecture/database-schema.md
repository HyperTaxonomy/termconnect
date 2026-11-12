---
title: Database Schema
parent: Architecture
nav_order: 4
---

# Database Schema

TermConnect has no tables of its own. It stores data in term meta, comments, options and transients, and adds one index to the term meta table. For the keys inside `tc_settings`, see the [Settings Reference](../developer-reference/settings-reference.html). For what uninstalling removes, see [Settings, Cache & Export](../user-guide/settings-export.html#uninstalling).

## Term meta

| Key | Value |
|---|---|
| `_tc_link_{slug}` | The term's identifier for link type `{slug}`, with its label and fetch time. |
| `_tc_uris` | One row per full identifier URI, from the term's own and delegated links. This is the index used to find a term by identifier, for example by `?htax_term_uri=`. |
| `_tc_identity_source` | The delegation target, its cache timestamps and status, and the sync flags. |
| `_tc_delegated_links_data` | The external links fetched from the delegation source. |
| `_tc_original_name` | The term's name before delegation, restored when delegation ends. |
| `_tc_original_description` | The term's description before delegation, restored when delegation ends. |
| `_tc_rel_sync_issues` | Relationship sync problems found by shared vocabulary sync, shown in the Sync column. |

### Index

`tc_link_reverse` on `{prefix}termmeta (meta_key(60), meta_value(64))` speeds up finding terms by identifier. Activation adds it, and uninstalling drops it.

## Comments

Link suggestions from visitors are stored as unapproved comments with `comment_type` `tc_suggested_link`. Comment meta `_tc_term_id` holds the term the suggestion is for.

## Options

| Option | Value |
|---|---|
| `tc_settings` | Global settings. See the [Settings Reference](../developer-reference/settings-reference.html). |
| `tc_enabled_taxonomies` | Taxonomies TermConnect is enabled for. |
| `tc_link_types` | Link type definitions: peer sites, feeds and identifier namespaces. |
| `tc_shared_vocabularies` | Shared vocabulary sources and their scope, sync fields and schedule. |
| `tc_peer_site_health` | Health state for each peer, keyed by link type. |
| `tc_ph_tally_{slug}` | A peer's failure count, kept separate so counting doesn't rewrite the full health state. |
| `tc_ext_ltfetch_{slug}` | Fetch statistics for a peer, shown on the Peer Sites screen. |
| `tc_ext_ltver_{slug}` | Cache version for a peer. Raising it retires that peer's cached responses. |
| `tc_feed_poll_stat_{slug}` | The result of a feed's last poll. |
| `tc_feed_reschedule` | Set on activation so feeds are scheduled again on the next load. |
| `tc_identity_redirect_queue` | Delegated terms whose source now redirects, waiting for review in Site Health. |
| `tc_merge_proposals` | Shared vocabulary merge proposals waiting for review. |
| `tc_sv_misplaced_queue` | Mirrored terms in the wrong place, waiting for review in Site Health. |

## Transients

| Key | Holds |
|---|---|
| `tc_links_{term_id}` | A term's merged external links. |
| `tc_uris_{term_id}` | A term's identifier URIs. |
| `tc_delegated_term_ids` | IDs of all terms with a delegated identity. |
| `tc_stale_rel_term_ids` | IDs of terms with stale relationship sync issues. |
| `tc_ext_tid_*`, `tc_ext_tsrch_*`, `tc_ext_usrch_*`, `tc_ext_tdata_*`, `tc_ext_tpeer_*` | Responses from peers: term lookups, term and user searches, term data, and a term's peer connections. |
| `tc_ext_posts_*`, `tc_ext_scored_*`, `tc_ext_rel_*` | Peer post lists, scored post lists and related posts. |
| `tc_feed_cats_{slug}` | A feed's categories, kept for 90 days. |
| `tc_feed_posts_{slug}` | A feed's posts, kept for 30 days. |
| `tc_lt_error_{user_id}` | A Peer Sites form error, shown after the redirect. |
| `tc_sugg_rate_*`, `tc_suggest_global_*` | Rate limits for link suggestions, per term and overall. |

## Cron events

| Event | Schedule | Runs |
|---|---|---|
| `tc_refresh_identity_caches` | Twice daily | Refreshes cached data for delegated terms. |
| `tc_sync_shared_vocabularies` | Hourly | Syncs each shared vocabulary source whose interval has passed. |
| `tc_feed_poll` | Per feed, from 5 minutes to 1 day | Polls one feed. Each feed has its own event, with the feed's slug as its argument, on a `tc_{seconds}s` schedule. |
