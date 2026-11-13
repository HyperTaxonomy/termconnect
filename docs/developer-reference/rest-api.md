---
title: REST API
parent: Developer Reference
nav_order: 3
---

# REST API

When TermConnect is active, it adds fields to standard WordPress term endpoints for TermConnect-enabled taxonomies.

## `X-TermConnect` request header

TermConnect sends `X-TermConnect: {version}` on outbound requests to peer sites (delegated identity fetches, peer post requests). On a peer running TermConnect, this header adds `tc_links` to term responses, and it's required by the `tc/v1/group-members` endpoint.

## `X-HyperTaxonomy` request header

A separate, HyperTaxonomy-owned gate: on *collection* term responses, HT only includes `htax_redirect`, `htax_includes` and `htax_relationships` when the request carries an `X-HyperTaxonomy: {version}` header and asks for them in `_fields` — `_fields` alone is not enough, and there is no error or empty-but-present field to signal the omission, the keys are simply absent. `X-TermConnect` does **not** satisfy this gate; the two headers are independent. Any TC code fetching a peer's full taxonomy collection with relationship delegation enabled must send both headers. `tc_sv_fetch_taxonomy()` (Shared Vocabularies full-taxonomy sync) sends `X-HyperTaxonomy` whenever it requests those fields.

## Term endpoints

Individual (`/wp/v2/{taxonomy}/{id}`) and collection (`?slug=…`) term responses carry:

- `htax_redirect` — stub of the redirect target (`{ id, name, slug, taxonomy, link }`) when the term is an HyperTaxonomy redirect. The field itself is registered by HyperTaxonomy. On collection endpoints, it's only present when the request carries `X-HyperTaxonomy`, as TermConnect's delegated identity fetches do.
- `tc_links` — TermConnect external links: a flat array of `{ url, platform, label, fetched_at }` objects. Built by `tc_build_merged_links()` which combines local `_tc_link_*` identifiers and delegated links from `_tc_delegated_links_data`, applying override logic (when a delegated link and a local link share the same site, the delegated link wins). Present for all TermConnect-enabled taxonomies on both individual and collection endpoints. Gated by the **Share external links** setting. Peer sites use this field for FOAF discovery and identity sync — a peer site only needs TermConnect active, not HyperTaxonomy.

## Post endpoints

With HyperTaxonomy active, its scored post collections (`/wp/v2/posts` with `orderby=score`, which peers running TermConnect request) carry one more field:

- `htax_term_peers` — the WordPress peers each term on the post is linked to, keyed by term ID. Each value has a `peers` array of `{ origin, slug }` objects and, when the term has external links, a `tc_links` array in the same shape as the term field above. It is `null` on other post responses. A site fetching peer posts caches it to discover its peers' peers without an extra term request; see [FOAF Discovery](peer-fetch-api.html#foaf-discovery).

## Query filter: `htax_term_uri`

TermConnect registers `htax_term_uri` as a query parameter on term collection endpoints for TermConnect-enabled taxonomies. It accepts a full URI and matches it against the `_tc_uris` term meta index — a multi-value meta key (one row per URI) rebuilt from all local and delegated links whenever a term's links change:

```
GET /wp-json/wp/v2/tags?htax_term_uri=https://www.wikidata.org/wiki/Q28865&_fields=id
```

Returns terms that have that URI stored in the `_tc_uris` index. Used internally by `[tc_peer_posts]` to auto-resolve remote term IDs when no explicit peer slug is configured. Requires TermConnect to be installed and the taxonomy to be TermConnect-enabled on the queried site.

The `tc_links` field is only included in term responses when the request carries an `X-TermConnect` header — other consumers get `null` for it, or don't see it at all if `_fields` leaves it out. TermConnect's **Share external links** setting (`expose_links_api`) is a second gate: disabling it suppresses `tc_links` even for TC-aware requests, letting you run TermConnect for internal term delegation only.

HyperTaxonomy also adds `htax_locations` to term REST responses when ATCF is active and **Share list of locations** is enabled for the taxonomy. Cross-site term matching by location flows through the generic URI index (`tc_index_extra_uris` → `_tc_uris`), so location-bearing terms are matched the same way as any other shared identifier — there is no separate location score-boost.

## Other registered surfaces

- `tc_content` and `show_ui` (boolean fields on the `/wp/v2/taxonomies` endpoint) — `tc_content` mirrors the taxonomy's `public` flag, and `show_ui` its `show_ui` flag. The Shared Vocabularies form uses them to leave utility taxonomies out of its list.
- `GET /wp-json/tc/v1/group-members?group_slug={slug}` — returns the member terms of a HyperTaxonomy term group on the queried site, across TermConnect-enabled taxonomies (`taxonomy`, `id`, `slug`, `name`, `description`, `parent`, `parent_slug`, plus `tc_links` when **Share external links** is on). Gated by the `X-TermConnect` header; used for cross-site shared-vocabulary resolution. A hidden group returns 403, and hidden member terms are omitted, unless HyperTaxonomy lets the caller see hidden terms (peer sites call it anonymously, so for them hidden groups and members are always withheld).

The block editor's peer-picker checklist does **not** use REST: the configured peers (`tc_get_browsable_peers()`) are localized into `window.TCPeers` on each block's editor script at registration time, avoiding a runtime round-trip.

## Gotcha: `register_rest_field()` on `post_tag`

WP core's `WP_REST_Terms_Controller::get_item_schema()` hardcodes the schema `title` for the `post_tag` taxonomy to `'tag'` instead of the taxonomy slug (a REST-API-era naming holdover; `category`'s title equals its own slug, unaffected). `register_rest_field()`'s field lookup keys off that schema title, not the slug passed to `register_taxonomy()` — so `register_rest_field( 'post_tag', ... )` registers without error but the field **silently never appears** in any real `post_tag` REST response, even when explicitly requested via `_fields`. `tc_links` is registered through `tc_rest_field_object_type( $taxonomy )` (above `tc_register_tc_links_field()`) specifically to route around this — any future `register_rest_field()` call on a taxonomy must go through it (or replicate the `post_tag` → `tag` mapping) to work correctly. HT itself avoids the whole class of bug by using `rest_prepare_{taxonomy}` filters, which are keyed by the real taxonomy slug — prefer that pattern for new taxonomy-REST-enrichment code.
