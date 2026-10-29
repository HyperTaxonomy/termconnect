=== TermConnect – External links for your taxonomy terms - used for peer posts, RSS/Atom feed aggregation, FOAF-style related content discovery ===
Tags: taxonomy, peer sites, external links, external service, Wikidata, RSS, feeds, related posts
Contributors: eugenevv
Author: Eugene Weinstein
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enrich your posts with related content on any topic from a decentralized network of sites and feeds sharing your vocabulary — curated, not crawled.

== Description ==

TermConnect connects WordPress taxonomy terms to the global web of knowledge. Attach external identifiers, delegate term definitions to authoritative sources, mirror shared vocabularies from peer sites, and pull related content from a network of peers. No central coordination required — each site connects directly to the others it trusts.

Works with any WordPress site. HyperTaxonomy adds relevance scoring, synonym expansion, and proximity search when both are active.

= Core Features =

**External Identifiers**
* 21 built-in external identifier types: Wikidata, Wikipedia, GeoNames, OpenStreetMap, VIAF, ORCID, LCSH, MeSH, Getty AAT, Getty TGN, OpenAlex, schema.org, and more
* External Links panel on the term edit screen with identifier input and live autocomplete
* "See also" section on term archive pages (inline or list format) for enabled link types
* Restrict any link type to specific taxonomies
* Export/import all link type definitions, term identifiers, and delegated identity sources as JSON

**Link Suggestions**
* Visitor-facing form on term archive pages inviting a suggestion for an external link (shown below "See also"); automatically suppressed on terms with delegated identity
* Submissions stored as moderated WordPress comments — standard spam filtering and moderation workflow apply
* Review and accept at **Comments → Link Suggestions**, with context-aware row actions (accept, enable a matching link type, add a new link type, or edit the term)
* Per-term "Suggested identifiers" panel on the term edit screen for inline review without leaving the page
* Optional email notification to admins on each new submission

**Delegated Identity**
* Delegate a term's description to an authoritative term on any peer WordPress site, matched by slug
* Remote description replaces the local one on delegation; original is saved and restored on removal
* Synced automatically via background cron; manual refresh available on the term edit screen
* If the peer runs HyperTaxonomy with external link sharing enabled, identifier mappings also sync
* Redirect detection: when the remote term moves, review it in Site Health (follow the redirect, keep the current delegation, or end it) or auto-follow (configurable)
* Site Health integration surfaces pending redirects, unreachable sources, and not-found terms

**Shared Vocabularies**
* Mirror individual terms, hierarchy branches, or full taxonomies from any peer WordPress site
* When HyperTaxonomy is active locally, mirror all members of a local group in one step
* Terms are matched by slug, created locally when missing, renamed when the remote renames
* Kept in sync on a configurable schedule (twice daily, daily, or weekly)
* Manage sources at **Terms → Shared Vocabularies** — grouped by peer site and taxonomy
* Misplacement handling when the remote hierarchy changes: flag for review, link only, or re-parent

**WordPress Peer Post Fetching**
* Configure any WordPress site as a peer by creating a link type with Platform = *WordPress peer*
* Map local terms to peer terms by slug; TermConnect fetches posts from matching peer terms
* When the peer also runs HyperTaxonomy: scored requests; peer scores with its own search index
* When the peer is standard WordPress: client-side scoring using the same weighted formula
* Results from all peers merged into a single list, sorted by score, date, title, or relevance

**RSS/Atom Feed Aggregation**
* Create a feed link type with a configurable poll interval and post retention limit
* Map local terms to feed categories via a live category picker
* Posts accumulate across polls; categories never disappear once seen
* Feed posts are scored by category match alongside WordPress peer posts

**FOAF Peer Discovery**
* When a HyperTaxonomy-enabled peer returns `htax_term_peers` in post responses, TermConnect caches those peer connections
* `foaf_depth` attribute on `[tc_peer_posts]` follows discovered connections up to a configurable global cap
* Passively builds a richer peer network from a single configured starting point

**External Posts for HyperTaxonomy Consumers**
* TermConnect contributes external peer-site and feed posts to HyperTaxonomy's generic external-posts API (the `htax_term_external_posts` filter)
* Any consumer reads them via `htax_get_term_external_posts()` — for example WP-SKOS surfaces them as `foaf:Document` results in its document-search endpoint
* No configuration required, and TermConnect does not depend on or reference any specific consumer plugin

**Admin**
* **Terms → Peer Sites** — enable/disable/reorder/add/edit/delete link types; per-site cache controls (Clear Cache, Poll Now)
* **Terms → Shared Vocabularies** — configure vocabulary mirror sources by peer site and taxonomy
* **Links column** on taxonomy term list tables — sortable link count per term, with filter dropdown
* **Settings → TermConnect** — enabled taxonomies, FOAF depth cap, site blacklist, cache TTLs, See Also format

= Shortcodes & Blocks =

**`[tc_peer_posts]`** — Fetch and display related posts from configured peer WordPress sites and RSS/Atom feeds. Only renders on singular pages. Set `scope="all"` to also blend in this site's own matching posts (local site as a pseudo-peer); when HyperTaxonomy is active the local side is relevance-ranked via its search index.

```
[tc_peer_posts]

[tc_peer_posts orderby="date" per_page="3"]

[tc_peer_posts foaf_depth="1" show_thumbnail="true"]

[tc_peer_posts link_types="my-blog" taxonomy="post_tag"]
```

**`[tc_peer_browse]`** — An interactive topic browser: visitors pick terms and see matching posts from this site and configured peers/feeds, updating live with a Load-more button. Match Any (OR) or All (AND); local site is just another source. Works on any page.

```
[tc_peer_browse taxonomy="post_tag"]

[tc_peer_browse taxonomy="post_tag" terms="javascript,react" match="all"]

[tc_peer_browse columns="3" show_scope="true" show_sort="true"]
```

To blend peer/feed posts together with this site's own related posts in one block, set `scope="all"` on `[tc_peer_posts]` (or the Peer Posts block's Source = Local + peers). When HyperTaxonomy is active the local side is relevance-ranked via its search index.

The **Peer Posts** and **Peer Browse** Gutenberg blocks are the block-editor equivalents of `[tc_peer_posts]` and `[tc_peer_browse]`, available in the block inserter under **TermConnect**.

= Key Attributes for [tc_peer_posts] =

Key attributes: `link_types`, `taxonomy`, `match`, `scope` (`peers` or `all` to also blend in this site's own matching posts), `orderby` (including `peer:asc,relevance:desc` to group results by source), `foaf_depth`, `require_ht`, `peer_expand`, plus standard display attributes (`format`, `columns`, `show_thumbnail`, `meta`, etc.).

For the complete attribute reference, see [User Guide → Displaying Peer Posts](https://hypertaxonomy.org/termconnect/user-guide/displaying-peer-posts.html).

= See Also on Archives =

When a term has external identifiers set and the link type has **Show on archives** enabled, a "See also" section appears on the term's archive page.

Configure in **Settings → TermConnect → See Also**: global enable/disable, inline vs. list format, inline label text.

== Installation ==

= Automatic Installation =

1. Log into your WordPress admin
2. Go to **Plugins > Add New**
3. Search for "TermConnect"
4. Click **Install Now** and then **Activate**

= Manual Installation =

1. Upload plugin files to `/wp-content/plugins/termconnect/`
2. Activate through the Plugins screen
3. Go to **Settings → TermConnect** to select which taxonomies to enable
4. Go to **Terms → Peer Sites** to enable link types and add peer site connections

= Optional =

Install **HyperTaxonomy** for enhanced scoring: synonym expansion, relevance-weighted term matching, and PowerSearch `near:` proximity operators when combined with As the Crow Flies.

== Developer Reference ==

For the complete PHP API, filters, REST API fields, and settings keys, see the Developer Reference at https://hypertaxonomy.org/termconnect/developer-reference/ (source in `docs/developer-reference/` included in the plugin).

== Frequently Asked Questions ==

= Does this require HyperTaxonomy? =

No. All features work standalone: link type configuration, See Also on archives, peer post fetching, feed aggregation, shared vocabulary mirroring, and delegated identity. No plugin is required on any peer site for these features either.

HyperTaxonomy adds scored requests to peer sites, synonym expansion (`include_synonyms`), included-term expansion (`include_related`), and proximity search.

= How are peer posts scored without HyperTaxonomy? =

Client-side scoring: TermConnect checks each returned post's tag and category IDs against the resolved remote term IDs and ranks by a per-term weighted match (direct/synonym terms weighted higher than related ones, derived from your local relevance settings; when on a singular post, also weighted by how central each term is to that post). The formula matches what HyperTaxonomy uses server-side, so results from both types of peer are comparable within each source.

= How does FOAF work? =

When a HyperTaxonomy-enabled peer returns post results, each post's response includes `htax_term_peers` — a list of WordPress peer connections declared by that peer site's terms. TermConnect caches these connections and uses them when `foaf_depth > 0` to discover and query peer sites that were never manually configured. This passively builds an expanding network from a small number of explicitly configured starting points.

= Can I use feed types without WordPress peers? =

Yes. Create a feed link type (Platform = RSS / Atom feed), map your terms to feed categories, and use `[tc_peer_posts]` — it queries both WordPress peers and feed caches in the same request and merges results.

== Troubleshooting ==

**No peer posts appearing:** Verify (1) at least one link type with Platform = WordPress peer is enabled, (2) the current post's terms have peer slugs set on the term edit screen, (3) the peer site is reachable. Check **Terms → Peer Sites** for per-site cache status.

**Feed posts not showing:** Verify the feed URL is correct and the poll has run (click Poll Now on the link types page). Check that the term's feed category is selected on the term edit screen.

**See also not appearing:** Verify (1) the link type has Show on archives enabled, (2) the term has an identifier set, (3) the taxonomy's Archive Customization feature is enabled in HyperTaxonomy (if HyperTaxonomy is active).

**Autocomplete not working:** Supported external services (Wikidata, Wikipedia, VIAF, LCSH, iNaturalist, and others) offer live autocomplete. For link types with no suggest API, the field accepts free-form text only.

== Screenshots ==

1. Term edit screen — the External Links panel with knowledge-base identifiers (Wikidata, GeoNames, LCSH, etc.)
2. Terms → Peer Sites — configuring WordPress peers, RSS/Atom feeds, and link types
3. Terms → Shared Vocabularies — mirroring terms from a peer, with coverage warnings
4. Related content from peer sites shown under a term archive ("See also")
5. [tc_peer_browse] in action on the frontend — picking terms and browsing matching posts from this site and configured peers/feeds
6. Comments → Link Suggestions — reviewing and accepting external-link suggestions
7. Settings → General tab, showing per-taxonomy feature toggles

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
