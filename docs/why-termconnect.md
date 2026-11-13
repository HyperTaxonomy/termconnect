---
title: Why TermConnect
nav_order: 2
---

# Why TermConnect

A WordPress term is a label, and it means something only on its own site. HyperTaxonomy connects terms within a site. TermConnect connects them across sites.

## Labels don't cross sites

Your *Dog* tag and a veterinary site's *Canis familiaris* category are about the same animal, but WordPress has no way to say so. Even two sites' *Dog* tags are just two strings that happen to match. And a matching name proves nothing: *Python* is a programming language on one site and a snake on another.

So every site's vocabulary is an island. Its archives can't show what other sites publish on the same subject, and nothing outside the site can tell which of its terms mean the same as yours.

## Identity: the same concept under any name

TermConnect gives a term an identity: links to the identifiers the wider web already uses for its concept, such as its Wikidata item, a GeoNames place, or a Library of Congress subject heading. Two terms with the same identifier are the same concept, whatever each site calls it and in whatever language. Everything else rests on that:

- Your site can list posts from peer sites whose terms share that identity, next to a post or on a term's archive, with no term-by-term mapping. A post about dogs that a peer published today can appear next to yours, even though nobody links to it yet.
- Visitors get "See also" links to the reference sources.
- With [WP-SKOS](https://hypertaxonomy.org/wp-skos/), the identifiers are published as linked data, so other systems and AI agents can see the match too.

## Delegated identity: defined elsewhere

Sometimes another site knows a concept better than you do: a breed club knows its breed, and a veterinary association knows its vaccines. Instead of writing your own definition and keeping it current, delegate the term to that site. Its name, description and external links then follow the source as it changes, and you choose separately whether to keep your own name or description.

Delegation works at larger scale too. A [Shared Vocabulary](user-guide/shared-vocabularies.html) mirrors a whole branch or taxonomy from a peer, and, when both sites run HyperTaxonomy, the relationships between its terms. The source only needs to be a standard WordPress site.

## No central authority

There is no registry, crawler or central server. Each site decides which peers to trust and which sources to delegate to, and keeps full control of its own terms. For the ideas behind this, see "What a concept means" and "Authority without a center" in [A web of concepts](https://hypertaxonomy.org/vision.html).

## Where to next

- **[Getting started](./#getting-started)** — install the plugin and add your first identifier.
- **[Concepts](user-guide/concepts.html)** — external identifiers, and when a peer network pays off.
- **[Delegated Identity](user-guide/delegated-identity.html)** and **[Shared Vocabularies](user-guide/shared-vocabularies.html)** — defer terms to a peer, one at a time or in bulk.
