---
title: Deactivation & Uninstall
parent: User Guide
nav_order: 10
---

# Deactivation & Uninstall

What changes while TermConnect is deactivated, what reactivating it restores, and what deleting it removes. Your terms and their assignments to posts are never removed.

## What happens when you deactivate

Deactivating TermConnect deletes nothing: link types, external links, delegated identity, Shared Vocabulary sources, link suggestions and settings are all kept. TermConnect simply stops running. While it's deactivated:

- **Scheduled tasks stop.** Delegated terms are no longer refreshed from their source sites, Shared Vocabularies no longer sync, and feeds are no longer polled. Delegated terms keep the names and descriptions they last synced.
- **Term archives** no longer show "See also" links or the form for suggesting a link.
- **The `[tc_peer_posts]` and `[tc_peer_browse]` shortcodes** appear as plain text in your content, and the **Peer Posts** and **Peer Browse** blocks show nothing.
- **Other sites** no longer see this site as a TermConnect site: its REST responses lose the `tc_links` field and the `X-TermConnect` header, so peers can't match its terms by external identifier. Its posts can still be fetched as from any WordPress site.
- **HyperTaxonomy**, if active, no longer gets external identifiers or peer posts from TermConnect: peer posts drop out of related posts, and term identifier URIs (and WP-SKOS `skos:closeMatch` links) are empty.
- **Social Hashtags & Mentions**, which requires TermConnect, stops working and shows a notice.
- **Pending link suggestions** are stored as comments. TermConnect normally keeps them out of the **Comments** screen; while it's deactivated, they appear there, attached to no post, and count toward the pending-comments number.

## Reactivation

Reactivating TermConnect schedules its tasks again, so delegated terms, Shared Vocabularies and feeds catch up on their next run. Everything else works again at once: nothing was deleted, and cached data is rebuilt as it's needed.

## Deleting the plugin

Deleting it from the **Plugins** screen always removes TermConnect's settings, scheduled tasks, cached data, review queues and its database index.

Everything else stays, so a reinstall works as before, unless **Delete all data** is on (**Settings → TermConnect → Advanced**). With it on, deleting the plugin also removes link types, enabled taxonomies, Shared Vocabulary sources, per-term identifiers, delegated identity, and visitor link suggestions.
