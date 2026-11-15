---
title: Home
nav_order: 1
---

# TermConnect

*Vocabulary without borders*
{: .fs-6 .fw-300 }

TermConnect connects WordPress taxonomy terms to the global web of knowledge. Attach external links — knowledge-base identifiers (Wikidata, GeoNames, LCSH), peer WordPress site URLs, RSS/Atom feeds, or any custom link type — to anchor terms to shared reference points. Delegate term definitions to more authoritative sources. Mirror shared vocabularies from peer sites so your editors work with the same terms as the wider community. Pull related content from a network of peers using those shared terms as the matching key.

**A term is a handle for a concept.** What identifies the concept is its external links, not its name: two sites whose terms share a Wikidata ID are talking about the same thing, even if they use different names or slugs. Peer sites running TermConnect can be matched on that basis.

Delegating a term to a peer doesn't force you to take its name or description: each is synced only if you choose.

The result is a decentralized vocabulary layer — sites that independently curate their taxonomies but stay connected to each other and to the global knowledge graph. No central authority, no crawler, no coordination required beyond linking the sites you trust.

Works with any WordPress site. [HyperTaxonomy](https://hypertaxonomy.org/hypertaxonomy/) adds relevance scoring and synonym expansion when both are active, and proximity search when [As the Crow Flies](https://hypertaxonomy.org/as-the-crow-flies/) is active too; TermConnect contributes its identifier data to HyperTaxonomy's abstraction layer to make it accessible to integrations like [WP-SKOS](https://hypertaxonomy.org/wp-skos/).

Part of the [HyperTaxonomy Suite](https://hypertaxonomy.org) of interoperable WordPress taxonomy plugins.

## Documentation map

- **[Why TermConnect](why-termconnect.html)** — why terms need an identity to connect across sites, and what delegating a definition means.
- **[User Guide](user-guide/)** — for site owners and editors: concepts, peer sites and feeds, settings, the peer-post shortcodes and blocks, See Also and link suggestions, delegated identity, shared vocabularies, export/import, and deactivation.
- **[Developer Reference](developer-reference/)** — for theme and plugin developers: the Term Links API, the Peer Fetch API, the External Documents API, REST API fields, actions and filters, and the stored settings.
- **[Architecture](architecture/)** — for contributors: file structure and loading, function and hook references, and data storage.

## Requirements

| Item | Version |
|------|---------|
| WordPress | 6.6+ |
| PHP | 7.4+ |

## Getting started

1. Install from the [WordPress.org plugin directory](https://wordpress.org/plugins/termconnect/), or download the zip from [GitHub Releases](https://github.com/HyperTaxonomy/termconnect/releases) and upload via **Plugins → Add New → Upload Plugin**, then activate it.
2. Enable taxonomies (below).
3. Edit a term in an enabled taxonomy and add an identifier, such as its Wikidata ID, in the **External Links** panel.
4. To connect other sites, go to **Terms → Peer Sites**. See [Peer Sites & Feeds](user-guide/peer-sites-feeds.html).

### Enabled taxonomies

Go to **Settings → TermConnect → General** and, under **Enabled Taxonomies**, check each taxonomy where you want TermConnect active. Enabling a taxonomy:

- Shows the **Delegated Identity** and **External Links** panels on its term add/edit screens.
- Includes it in "See also" display on term archive pages.
- Turns on peer site term matching when fetching cross-site posts.

Built-in taxonomies (Tags, Categories) and any public custom taxonomies appear in the list.

## External services

TermConnect contacts outside services only for features the site owner sets up. Requests are sent from the site's server and identify themselves as `TermConnect/{version}`. No visitor data is sent, except as noted below for link suggestions.

**Identifier autocomplete.** When an editor types in a term's External Links field, the typed text is sent to the search service of that link type, to suggest identifiers. This happens only for link types the site owner has enabled, and only while an editor is typing.

- Wikidata, Wikipedia, Wikiquote, Wiktionary, Wikivoyage and Wikispecies, run by the Wikimedia Foundation: [Terms of Use](https://foundation.wikimedia.org/wiki/Policy:Terms_of_Use), [Privacy Policy](https://foundation.wikimedia.org/wiki/Policy:Privacy_policy)
- OpenStreetMap Nominatim (nominatim.openstreetmap.org): [Usage Policy](https://operations.osmfoundation.org/policies/nominatim/), [Privacy Policy](https://osmfoundation.org/wiki/Privacy_Policy)
- iNaturalist (api.inaturalist.org): [Terms of Use](https://www.inaturalist.org/pages/terms), [Privacy Policy](https://www.inaturalist.org/pages/privacy)
- GBIF (api.gbif.org): [Terms of Use](https://www.gbif.org/terms), [Privacy Policy](https://www.gbif.org/terms/privacy-policy)
- Library of Congress Subject Headings (id.loc.gov): [Legal](https://www.loc.gov/legal/), [Privacy Policy](https://www.loc.gov/legal/privacy-policy/)
- OpenAlex (api.openalex.org): [Terms of Service](https://openalex.org/terms), [Privacy Policy](https://openalex.org/OpenAlex_privacy_policy.pdf)
- A MediaWiki site the site owner adds as a custom link type: that wiki's own terms and privacy policy apply.

Other built-in link types (Grokipedia, Merriam-Webster, GeoNames, ORCID, VIAF, MeSH, Getty AAT and TGN, schema.org, GS1) have no autocomplete and are never contacted; TermConnect only builds links to them.

**Peer sites and feeds.** TermConnect requests the public REST API of each WordPress site, and the RSS or Atom feed of each feed, that the site owner adds at Terms → Peer Sites. It does so to show their related posts, keep delegated terms and Shared Vocabularies in sync, suggest their terms and authors while an editor types, and detect whether they run TermConnect or HyperTaxonomy. Requests contain the term slugs, external identifiers or search text being looked up. With FOAF discovery turned on (a `foaf_depth` above 0, capped by Max FOAF Depth), TermConnect also queries peers of those peers, as reported by the peers themselves; sites listed under Blocked Sites are never contacted. These are sites the site owner chooses, so their own terms and privacy policies apply.

**Link suggestions.** When a visitor suggests a link for a term and admin notifications are on, and when an administrator reviews suggestions, TermConnect sends a request to the suggested URL's site to check whether it runs HyperTaxonomy. Only that site's address is used; nothing about the visitor is sent.

## License

GPLv2 or later — https://www.gnu.org/licenses/gpl-2.0.html
