---
title: Modeling Decisions
parent: User Guide
nav_order: 7
---

## 14. When Peer Networks Make Sense

Peer connections are most valuable when:

- Two sites share a substantive vocabulary overlap — the same terms are used by both editorial teams.
- You want readers to discover posts on peer sites not yet indexed by search engines, or that search engines systematically underweight.
- You want to surface a collaborating site's content without manual curation — any post they publish with a matching term appears automatically.

Peer connections work best when both sides maintain a careful, intentional taxonomy. Loose or inconsistent tagging produces low-relevance results; precise taxonomy compounds in value across sites the same way it does within a single site.

---

## 15. Term Equivalence and External Identifiers

When you link a local term to a peer term by its slug, you are asserting equivalence. That assertion is fragile: a slug like `python` means the programming language on a technology site and the reptile on a wildlife site. Slug similarity is not semantic equivalence.

External identifiers provide a stronger foundation. Many concepts are precisely defined in authoritative third-party vocabularies:

- **Wikidata** (e.g. Q28865 for Python the programming language)
- **GeoNames** (e.g. 2968815 for Paris, France)
- **Library of Congress Subject Headings** (e.g. `sh85107984` for Python programming)
- **Domain-specific identifiers** — MeSH, legal thesauri, scientific classifications

When Site A maps its "Python" tag to Wikidata Q28865, and Site B maps its "Python programming language" tag to the same identifier, the equivalence is grounded regardless of how each site names the term locally. The value compounds across a network: if three sites all anchor equivalent terms to the same QID, any site can match across all three without maintaining manual per-pair mappings.

---

## 16. Max FOAF Depth

`foaf_depth="0"` (the default) queries only explicitly configured peers. Increasing to 1 or 2 discovers posts from peers-of-peers automatically — useful for a wider community but only as reliable as your direct peers' own peer configurations.

Use depth 1 for a small group of editorially disciplined sites. Stay at 0 for a loosely curated network and map peers explicitly instead.
