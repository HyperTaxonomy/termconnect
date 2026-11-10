---
title: Home
nav_order: 1
---

# TermConnect

*Vocabulary without borders*
{: .fs-6 .fw-300 }

TermConnect connects WordPress taxonomy terms to the global web of knowledge. Attach external links — knowledge-base identifiers (Wikidata, GeoNames, LCSH), peer WordPress site URLs, RSS/Atom feeds, or any custom link type — to anchor terms to shared reference points. Delegate term definitions to more authoritative sources. Mirror shared vocabularies from peer sites so your editors work with the same terms as the wider community. Pull related content from a network of peers using those shared terms as the matching key.

**A term is a handle for a concept.** What identifies the concept is its external links, not its name: two sites whose terms share a Wikidata ID are talking about the same thing, even if they use different names or slugs. Peer sites running TermConnect can be matched on that basis.

A term delegated to a peer can still keep its own name or description, because syncing each one is a separate choice.

The result is a decentralized vocabulary layer — sites that independently curate their taxonomies but stay connected to each other and to the global knowledge graph. No central authority, no crawler, no coordination required beyond linking the sites you trust.

Works with any WordPress site. HyperTaxonomy adds relevance scoring and synonym expansion when both are active, and proximity search when As the Crow Flies is active too; TermConnect contributes its identifier data to HyperTaxonomy's abstraction layer to make it accessible to integrations like WP-SKOS.

Part of the [HyperTaxonomy Suite](https://hypertaxonomy.org) of interoperable WordPress taxonomy plugins.

## Documentation map

- **[User Guide](user-guide/)** — for site admins: concepts, peer sites and feeds, See Also and link suggestions, delegated identity, shared vocabularies, settings, export/import, the peer-post shortcodes and blocks, and modeling decisions.
- **[Developer Reference](developer-reference/)** — for theme and plugin developers: the Term Links API, the Peer Fetch API, the External Documents API, REST API fields, filters, and the settings reference.

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

## Requirements

| Item | Version |
|------|---------|
| WordPress | 6.6+ |
| PHP | 7.4+ |

## License

GPLv2 or later — https://www.gnu.org/licenses/gpl-2.0.html
