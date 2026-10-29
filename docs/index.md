---
title: Home
nav_order: 1
---

# TermConnect

*Vocabulary without borders*
{: .fs-6 .fw-300 }

TermConnect connects WordPress taxonomy terms to the global web of knowledge. Attach external links — knowledge-base identifiers (Wikidata, GeoNames, LCSH), peer WordPress site URLs, RSS/Atom feeds, or any custom link type — to anchor terms to shared reference points. Delegate term definitions to more authoritative sources. Mirror shared vocabularies from peer sites so your editors work with the same terms as the wider community. Pull related content from a network of peers using those shared terms as the matching key.

The result is a decentralized vocabulary layer — sites that independently curate their taxonomies but stay connected to each other and to the global knowledge graph. No central authority, no crawler, no coordination required beyond linking the sites you trust.

Works with any WordPress site. HyperTaxonomy adds relevance scoring, synonym expansion, and proximity search when both are active; TermConnect contributes its identifier data to HyperTaxonomy's abstraction layer to make it accessible to integrations like WP-SKOS.

## Part of the HyperTaxonomy suite

One of five interoperable WordPress plugins for richer, connected taxonomies. Each works on its own; together they share a common term model. Learn more at [hypertaxonomy.org](https://hypertaxonomy.org).

- **[HyperTaxonomy](https://hypertaxonomy.org/hypertaxonomy/)** — the core: term groups, inclusions, synonyms, redirects, icons, relevance-scored search, and enhanced archives.
- **TermConnect** (this plugin) — link terms to knowledge-base identifiers (Wikidata, GeoNames, LCSH…) and peer sites; delegated identity, shared vocabularies, and cross-site related content.
- **[Social Hashtags & Mentions](https://hypertaxonomy.org/social-hashtags-mentions/)** — inline #hashtags and @mentions as taxonomy terms, linked to the social networks you enable (built on TermConnect).
- **[As the Crow Flies](https://hypertaxonomy.org/as-the-crow-flies/)** — attach physical locations to terms and filter content by distance/proximity.
- **[WP-SKOS](https://hypertaxonomy.org/wp-skos/)** — expose your taxonomies as SKOS linked data via a Skosmos-compatible REST API.

## Documentation map

- **[User Guide](user-guide/)** — for site admins: enabled taxonomies, peer sites and feeds, See Also and link suggestions, delegated identity, shared vocabularies, settings, export/import, the peer-post shortcodes and blocks, and modeling decisions.
- **[Developer Reference](developer-reference/)** — for theme and plugin developers: filters, the Term Links API, the Peer Fetch API, REST API fields, the settings reference, and the WP-SKOS bridge.
- **[Vision](vision.html)** — a forward-looking sketch (not a roadmap) of where TermConnect's federation model could go: a decentralized, serverless concept registry. Published for the community to build on, should it choose to.

## Requirements

| Item | Version |
|------|---------|
| WordPress | 5.8+ |
| PHP | 7.4+ |

## License

GPLv2 or later — https://www.gnu.org/licenses/gpl-2.0.html
