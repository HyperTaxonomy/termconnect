---
title: Displaying Peer Posts
parent: User Guide
nav_order: 6
---

## 11. [tc_peer_posts] Shortcode

Fetches and displays related posts from configured WordPress peer sites and RSS/Atom feeds. Only renders on singular pages.

**Prerequisites:** At least one peer or feed link type must be enabled, and one or more of the current post's terms must have an identifier set for that link type on their term edit screen.

**How it works:**

1. Reads the current post's terms and their peer identifiers.
2. Resolves each peer slug to a numeric term ID on the peer site (cached for the configured **Peer Site Status** TTL, default 24 hours). If no explicit remote slug is configured for a term but the term has an external identifier (e.g. Wikidata Q28865), TermConnect automatically queries the peer with `htax_term_uri=https://www.wikidata.org/wiki/Q28865` to find the matching remote term — no manual slug mapping required. If `include_synonyms="true"` and HyperTaxonomy is active, synonym terms are also resolved and promoted to primary terms at the same weight. If `include_related="true"`, locally included terms are resolved and sent at reduced weight.
3. **HyperTaxonomy peers** — scored request via `htax_includes`, `htax_match`, and `htax_term_weights` (a per-term weight map TermConnect derives from your local per-taxonomy relevance settings — direct weight for primary/synonym terms, container weight for included terms). When `peer_expand="true"`, `htax_expand=1` is also sent so the peer matches through its own relationship graph. The peer scores using its own search index and returns `htax_score`.
4. **Standard WordPress peers** — standard `?tags=` request; scoring computed client-side with the same per-term weighting.
5. **Feed types** — cached posts scored by category match.
6. **Local posts** (when `scope="all"`) — matched by shared terms and, when HyperTaxonomy is active, relevance-ranked via its search index (normalized 0–1 within the local set), summed across taxonomies. With HyperTaxonomy active the local match also follows its relationship expansion (inclusions, term groups, hierarchy, and terms that redirect to the queried term), so a post related to the topic only through a relationship — not directly tagged — is included, matching the recall of `[ht_related_posts]` and the WP-SKOS document endpoint. Without HyperTaxonomy, the match is by directly-assigned terms (plus taxonomy children).
7. All results merged and sorted by `orderby`. Sorting never triggers a new request — the full cached set is sorted locally.

**Source-post weighting.** Each of the current post's terms is weighted by how central it is to that post (its HyperTaxonomy association strength). Local results, HT-peer results (folded into `htax_term_weights`), and standard-peer/feed results are all ranked so content sharing the post's *main* topics outranks content sharing only an incidental tag.

**Multiple taxonomies on one peer.** When the current post has terms in more than one taxonomy that map to a peer (e.g. tags *and* categories), that peer is queried once per taxonomy (the standard taxonomy filter is single-taxonomy, and OR semantics across taxonomies can't be expressed in one request). A post returned for several of the peer's taxonomies is scored as a single bag of query terms — `combined = Σ(sᵢ × Wᵢ) / Σ Wᵢ`, where `sᵢ` is the per-taxonomy normalized score and `Wᵢ` the total query-term weight for taxonomy *i*. The result stays in 0–1 and an extra-taxonomy match raises the score just like an extra-term match within one taxonomy, mirroring the local side's cross-taxonomy behavior.

**Ordering across sites.** Relevance scores are computed *per source* and are not comparable across independent sites (each site uses its own corpus). To present a relevance-ranked blend, order by `peer:asc,relevance:desc`: results are grouped by source (local first, then peers in Peer Sites admin order) and each source's posts are ranked by its own relevance. A plain `relevance` / `score` sort still works but interleaves sources by their independent scores, which is rarely meaningful for a blended set.

**Attributes:**

| Attribute | Description | Default |
|-----------|-------------|---------|
| `link_types` | CSV of link type slugs to query. Empty = all enabled peer and feed types. (In the block, choose these from a peer checklist instead of typing slugs.) | *(all)* |
| `taxonomy` | Restrict to one taxonomy. | *(all enabled)* |
| `match` | `any` (OR) or `all` (AND) across primary term groups. | `any` |
| `scope` | `peers` (peer/feed posts only) or `all` (also blend in this site's own matching posts, with the local site treated as a pseudo-peer). | `peers` |
| `orderby` | Comma-separated list of `field` or `field:dir` keys, applied in order. Fields: `peer`, `score`, `relevance`, `date`, `modified`, `title`, `foaf_depth`. Default directions: `title`/`peer` ascending, the rest descending. Use `peer:asc,relevance:desc` to group results by source and rank each by its own relevance. | `score` |
| `per_page` | Results per page. | `5` |
| `max_results` | Maximum total results. A **Load more** button fetches the next page from the local cache without a new remote request (local posts are re-queried each page under `scope="all"`). | `20` |
| `foaf_depth` | FOAF hops. Capped at *Max FOAF Depth* in Settings. | `0` |
| `require_ht` | When `true`, skip non-HyperTaxonomy peers. | `false` |
| `include_synonyms` | Resolve synonym terms (requires HyperTaxonomy) and send at direct-match weight. | `true` |
| `include_related` | Resolve locally included terms (requires HyperTaxonomy) and send at reduced weight. | `false` |
| `peer_expand` | When `true`, HyperTaxonomy peers also match the queried terms through their *own* relationship graph (not just direct assignments), surfacing more of their content. Off by default for predictable results. | `false` |
| `show_thumbnail` | Request featured image via `_embed` and render it. | `false` |
| `show_excerpt` | Show post excerpt. | `true` |
| `image_size` | Registered image size; sets the uniform thumbnail aspect (peer images can't be regenerated, but are cropped to a uniform box via CSS). | `medium` |
| `columns` | Number of grid columns for the results. | `1` |
| `format` | Output markup: `div` (full cards with thumbnail/excerpt/meta), `ul`, or `ol` (linked-title list items). | `div` |
| `template` | Style-variant slug appended as the CSS class `tc-result-{template}` for theme styling. | `default` |
| `meta` | Meta line template. Tokens: `{title}`, `{url}`, `{date}`, `{modified}`, `{excerpt}`, `{label}`, `{hostname}`, `{origin}`, `{author}`. | `{date} · {label}` |
| `prepend` | Template inserted before each item. Tokens: `{title}`, `{url}`, `{date}`, `{excerpt}`, `{label}`, `{hostname}`, `{origin}`. | *(empty)* |
| `append` | Template inserted after each item. Same tokens as `prepend`. | *(empty)* |
| `cache_ttl` | Override Post List Cache TTL for this instance. | *(setting)* |
| `no_results_message` | Message shown when no results. | *(empty)* |
| `class` | Extra CSS class on the wrapper. | *(empty)* |

When `foaf_depth > 0`, TermConnect reads peer connection data embedded in HyperTaxonomy post responses and queues discovered peers for fetching. FOAF-sourced results carry `tc-foaf-result` and `tc-foaf-depth-{N}` CSS classes.

**Examples:**

```
[tc_peer_posts]

[tc_peer_posts orderby="date" per_page="3"]

[tc_peer_posts foaf_depth="1" show_thumbnail="true"]

[tc_peer_posts link_types="my-blog,other-blog" taxonomy="post_tag"]

[tc_peer_posts match="all" require_ht="true"]

[tc_peer_posts scope="all" orderby="modified" columns="2"]

[tc_peer_posts scope="all" orderby="peer:asc,relevance:desc"]

[tc_peer_posts format="ul" orderby="date"]

[tc_peer_posts peer_expand="true" template="compact"]
```

---

## 12. Blending local and peer posts together

To show this site's own related posts **and** peer/feed results together in one block, set `scope="all"` on `[tc_peer_posts]` (or the Peer Posts block's **Source = Local + peers**). The local site is treated as another source; when HyperTaxonomy is active, local posts are selected via its relationship expansion (so relationship-related posts are included, not just directly-tagged ones), relevance-ranked by its search index, and `peer:asc,relevance:desc` groups the blend by source. TermConnect alone still handles term matching — without HyperTaxonomy the local side matches directly-assigned terms and isn't relevance-scored; HyperTaxonomy, when present, widens local recall, ranks the local side, and enforces term visibility.

```
[tc_peer_posts scope="all"]

[tc_peer_posts scope="all" orderby="peer:asc,relevance:desc" columns="2"]
```

---

## 13. [tc_peer_browse] Shortcode — interactive topic browser

The interactive, visitor-driven counterpart to `[tc_peer_posts]`. Instead of deriving topics from the current post, it renders a **term-pills control** the visitor fills in, then shows matching posts from this site **and** configured peer sites and feeds, updating live via AJAX with a **Load more** button. Works anywhere (not just singular pages).

```
[tc_peer_browse taxonomy="post_tag"]

[tc_peer_browse taxonomy="post_tag" terms="javascript,react" match="all"]

[tc_peer_browse taxonomy="post_tag" columns="3" show_scope="true" show_sort="true"]
```

**How topics combine** (set by the Match control / `match` attribute):

- **Any (OR, default):** posts matching *any* selected topic.
- **All (AND):** only posts matching *every* selected topic.

Both modes use the same scoring pipeline as `[tc_peer_posts]`: the selected topics are sent to each peer as one request (not one per topic), so a result that matches **more** of the selected topics ranks higher, and AND is enforced server-side on HyperTaxonomy peers (`htax_match=all`) or by filtering the returned set on standard peers. Local posts use a `tax_query` (OR for *any*, AND for *all*).

**Source** (`scope`): `all` (local + peers, default) or `peers` (peers only) — the local site is treated as just another source. By default the browser targets all configured peers, but you can narrow it to specific peers via the peer picker / `peers` attribute (and further by `platforms` / `foaf_depth`).

**Sort** is by `modified` (default), `date`, or `title` — all interleave local and peer results cleanly — or `peer:asc,relevance:desc`, which groups results by source and ranks each source's posts by its own relevance (relevance is not comparable across independent sites, so it is never interleaved across sources).

**Attributes**

| Attribute | Description | Default |
|-----------|-------------|---------|
| `taxonomy` | Taxonomy the topic pills draw from. | `post_tag` |
| `terms` | Optional seed: comma-separated term IDs or slugs to pre-select. | *(none)* |
| `match` | `any` (OR) or `all` (AND) — the default match mode. | `any` |
| `scope` | `all` (local + peers) or `peers`. | `all` |
| `orderby` | `modified`, `date`, `title`, or `peer:asc,relevance:desc` (group by source, then relevance). | `modified` |
| `peers` | CSV of peer slugs to query. Empty = all configured peers. (In the block, pick these from a peer checklist.) | *(all)* |
| `platforms` | CSV platform filter (e.g. `wordpress,feed`). Empty = all supported. | *(all)* |
| `foaf_depth` | Max FOAF discovery depth. `0` = direct peers only. | `0` |
| `peer_expand` | When `true`, HyperTaxonomy peers also match the selected topics through their *own* relationship graph (not just direct assignments), surfacing more of their content. Same meaning as in `[tc_peer_posts]`. Off by default for predictable results. | `false` |
| `per_page` | Results per Load-more page. | `10` |
| `max_results` | Cap on the total number of results the visitor can page through. | `100` |
| `columns` | Grid columns for the results. | `1` |
| `show_thumbnail` / `show_excerpt` / `image_size` | Card display. | `true` / `true` / `medium` |
| `meta` | Meta line template (`{date}`, `{modified}`, `{label}`, `{hostname}`, `{author}`). | `{date} · {label}` |
| `prepend` / `append` / `no_results_message` / `class` | As in `[tc_peer_posts]`. | *(empty)* |
| `show_match` | Show the Any/All control to visitors. | `true` |
| `show_scope` | Show the Source (local/peers) control to visitors. | `false` |
| `show_sort` | Show the Sort control to visitors. | `false` |

By default only the topic pills and the Match toggle are shown; enable `show_scope` / `show_sort` to expose those too, or set `match` / `scope` / `orderby` to fix an axis without a control.

**HyperTaxonomy is optional.** The browser works on TermConnect alone (local posts via a plain query, peers via TermConnect). When HyperTaxonomy *is* active, it is consulted only to hide terms — and their posts — that the current user is not permitted to see.

---

## 14. Blocks

| Block | Shortcode equivalent | Description |
|-------|---------------------|-------------|
| `termconnect/peer-posts` | `[tc_peer_posts]` | Peer site and feed posts for the current post (singular pages only) |
| `termconnect/peer-browse` | `[tc_peer_browse]` | Interactive topic browser across local + peers |

Find them in the block inserter under **TermConnect**.

Both blocks present a **peer picker** — a checklist of your actually-configured peers — instead of asking you to type link-type slugs. Leave all unchecked to query every configured peer. Only genuine TermConnect peers (WordPress peers + feeds) appear; companion-plugin link types such as Social Hashtags & Mentions' social networks are excluded.

**Peer Posts** inspector panels: **Source** (peer checklist, taxonomy, Source = peers / local+peers), **Query** (match, order by, per page, max results), **Peer Network** (FOAF depth, require HyperTaxonomy, include synonyms, include related, let peers expand via their own relationships), **Display** (columns, thumbnail, excerpt, image size, format, template, meta, prepend/append, no-results, class). Live server-side preview using the edited post as context.

**Peer Browse** inspector panels: **Query** (taxonomy, initial topics, default match/source/sort, peer checklist, platforms, FOAF depth, per page, max results), **Visitor controls** (show Match / Source / Sort), **Display** (columns, thumbnail, excerpt, image size, meta, prepend/append, no-results, class).
