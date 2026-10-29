# TermConnect

*Vocabulary without borders*

TermConnect connects WordPress taxonomy terms to the global web of knowledge. Attach identifiers from Wikidata, Wikipedia or any MediaWiki-powered site, GeoNames, LCSH, and 21 built-in reference sources in all, or to terms from any peer WordPress site, RSS or Atom feed. Delegate term definitions to authoritative sources. Mirror shared vocabularies from peer sites. Pull related content from a network of peers and FOAF-style beyond, using shared terms as the matching key.

The result is a decentralized web of WordPress sites that share vocabulary, surface related content, and defer to authoritative sources — without any central coordination.

Works with any WordPress site. When [HyperTaxonomy](https://wordpress.org/plugins/hypertaxonomy/) is also active, scoring gains relevance weighting, synonym expansion, and proximity search; TermConnect contributes its identifier data to HyperTaxonomy's abstraction layer so integrations like WP-SKOS work without depending on TermConnect directly.

## Features

- **External Identifiers** — Attach Wikidata IDs, GeoNames codes, LCSH headings, and 18 more built-in types (21 in all) to any term. Generates "See also" links on archives and powers cross-site term matching.
- **Delegated Identity** — Delegate a term's description to an authoritative term on any peer WordPress site, matched by slug. Synced automatically; if the peer runs HyperTaxonomy with external link sharing enabled, identifier mappings sync too.
- **Shared Vocabularies** — Mirror individual terms, hierarchy branches, or full taxonomies from peer sites into your local vocabulary. When HyperTaxonomy is active, mirror all members of a local group in one step. Terms are created as needed, renamed when the remote renames, and kept in sync on a schedule.
- **Peer Posts** — Fetch related posts from WordPress peer sites and RSS/Atom feeds using shared terms as the matching key. No plugin required on the peer.
- **Topic Browser** — An interactive `[tc_peer_browse]` facet: visitors pick terms and see matching posts from this site and peers, with Any/All matching and Load-more.
- **FOAF Discovery** — Passively discover peer sites from HyperTaxonomy-enabled post responses; follow discovered connections without explicit configuration.
- **See Also on Archives** — "See also: Wikidata · GeoNames" section appended to term archive descriptions for configured identifier types.
- **Export / Import** — JSON backup and restore of link type definitions, per-term identifiers, and delegated identity sources.

## Requirements

| Item | Version |
|------|---------|
| WordPress | 5.8+ |
| PHP | 7.4+ |

## Optional

- [HyperTaxonomy](https://wordpress.org/plugins/hypertaxonomy/) — semantic term relationships, relevance scoring, synonym expansion, and proximity search; TermConnect contributes its identifier data to HyperTaxonomy's abstraction layer when both are active
- [As the Crow Flies](https://wordpress.org/plugins/as-the-crow-flies/) — adds location-based peer term matching, bridged in by HyperTaxonomy (requires HyperTaxonomy also active)

## Shortcodes & Blocks

| Shortcode | Block | Purpose |
|-----------|-------|---------|
| `[tc_peer_posts]` | `termconnect/peer-posts` | Related posts from configured peer sites and feeds for the current post (optionally blend in local via `scope="all"`) |
| `[tc_peer_browse]` | `termconnect/peer-browse` | Interactive topic browser: visitors pick terms, see matching posts from this site + peers |

To blend peer/feed posts together with local related posts in one block, use HyperTaxonomy's `[ht_related_posts external="true"]` — TermConnect contributes the external content via HyperTaxonomy's generic external-posts API.

## Documentation

Full documentation is published at [hypertaxonomy.org/termconnect](https://hypertaxonomy.org/termconnect) (source in [`docs/`](docs/)):

- [User Guide](docs/user-guide/index.md) — enabled taxonomies, peer sites and feeds, See Also and link suggestions, delegated identity, shared vocabularies, settings, export/import, the peer-post shortcodes and blocks, and modeling decisions.
- [Developer Reference](docs/developer-reference/index.md) — filters, the Term Links API, the Peer Fetch API, REST API fields, the settings reference, and External Documents (contributing external posts via HyperTaxonomy's generic API).

## Translations

TermConnect is translation-ready and ships with a complete translation template: `languages/termconnect.pot`.

To contribute a translation: copy the template to `languages/termconnect-<locale>.po`, translate it, and open a pull request — the build compiles it (and, for block-editor strings, generates the required JSON) automatically. See `utils/make-pot.sh` and `utils/make-json.py`.

## License

GPLv2 or later — https://www.gnu.org/licenses/gpl-2.0.html
