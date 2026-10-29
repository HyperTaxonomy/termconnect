---
title: Vision
nav_order: 4
---

# Vision: A Distributed Concept Registry

{: .note }
> This is a **vision document, not a roadmap or a commitment.** Nothing here is built, scheduled, or required to use TermConnect. It sketches a direction TermConnect *could* evolve toward — and is published with the plugin so that, if the developer community decides this is worth pursuing, the thinking is already on the table. TermConnect today works fully without any of it.

TermConnect's shared vocabularies currently delegate a term's identity to a **specific peer site**. This document explores a longer-term possibility: shared vocabularies backed by a **decentralized, serverless concept registry** with no single controlling site.

---

## The core idea

A term is a **handle for a concept**. What defines a concept is its collection of external links (Wikidata, Wikipedia, peer sites, and so on) — which is already how TermConnect's delegated identity works. Today TC points a term at *a particular site's* definition. The idea here is to point it at a *concept itself*, with no specific controlling entity behind it.

Think of a cross-platform hashtag: `#Python` is globally understood without any authoritative URL behind it. The vision makes that explicit and structured — the external links that fingerprint the concept live at a persistent, resolvable address rather than being inferred from social use.

---

## What it would require

A way to resolve a **stable slug** (a "serverless term ID") into its current definition:

- **label** — human-readable name
- **description** — short definition
- **external links** — the concept fingerprint (Wikidata Q-number, Wikipedia article, peer TC instances, …)

The slug would be the global stable identifier; the definition would be mutable, advanced by consensus.

---

## How it might work

### Git-like semantics

- Each version of a concept definition would be **content-addressed and immutable** (like a git commit hash).
- The stable slug would be a **mutable pointer** to the latest consensus-approved version (like a git branch).
- **Consensus advances the pointer.**

Full history comes for free, any past state is verifiable, and rollback is trivial. Resolution (reading where the pointer sits) and governance (moving it) become the same mechanism seen from two angles.

### No blockchain required

"Blockchain" is a tempting framing, but the actual requirement is narrower: a stable ID + distributed resolution + mutable content. Existing mechanisms fit:

- **DIDs** (W3C Decentralized Identifiers) — designed for exactly "stable identifier, no controlling server, resolvable to structured data." DID Documents map almost directly onto the concept-definition structure. The closest match.
- **IPFS/IPNS** — content-addressed storage, with IPNS providing the mutable-pointer layer.
- **DHT** (BitTorrent-style) — the slug hashes to a location in the table; any participating node can answer.

### TC sites as the distributed store

TC sites subscribing to a concept behave like git remotes — the TC network *is* the distributed store, and popular concepts stay alive because many sites mirror them. A gossip protocol is essentially `git fetch` semantics over HTTP, which TC sites already speak. No dedicated chain infrastructure would be needed to start.

### Light participants, not full nodes

Full decentralized-ledger nodes are too heavy for shared/managed WordPress hosting (no persistent daemons, unreliable WP-Cron, provider restrictions). A realistic model:

- a TC site **stores** records for the concepts it subscribes to,
- **serves** those records to other sites on request (it already speaks HTTP),
- its affiliated **AI agent votes** on consensus decisions for subscribed concepts,
- and a small set of dedicated **anchor nodes** (run by maintainers, heavy users, or a foundation) hold the backbone.

Running an anchor node would be a separate undertaking from installing the plugin.

---

## Governance: AI-agent consensus

Evaluating proposed changes to concept definitions is too much volume for human authors. Site-affiliated agents could carry that load.

**What an agent would evaluate:** Do these external links point to the same real-world concept (semantic equivalence — something LLMs handle well)? Is this a duplicate of an existing record? Is the label/description accurate? Does a proposed link point to the right referent?

**Structure:** each TC site runs its own affiliated agent; consensus of those agents is governance; sites already subscribed to a concept have standing to vote on changes to it; accountability flows through sites, so bad actors lose standing.

**A real risk — correlated failure:** if every agent runs a similar model, they share systematic biases and "consensus" becomes identical outputs from identical systems rather than genuine agreement. Deliberate diversity in the agent population (different models, different emphases) would be necessary.

---

## Identity vs. enrichment

A concept record would have two layers with different consensus thresholds:

- **Identity core** (conservative, high threshold) — the minimal set of external links sufficient to establish "this handle means *this* concept and no other" (e.g. a Wikidata Q-number plus one Wikipedia article). Hard to pollute. This is what makes two sites' terms interoperable: share the same identity core and you are, provably, talking about the same thing.
- **Enrichment** (permissive, lower threshold) — the richer, more opinionated collection: extra site links, descriptions, synonyms, peer instances. Easier to add to, more contested.

The separation matters because a dissenting site could say *"I share this concept's identity but reject specific enrichment links"* — staying interoperable with the network (still the same Python) while suppressing or overriding objectionable links locally, **without forking**. The identity core should stay small; the more that goes into it, the more authors are forced into accept-everything-or-abandon-identity.

---

## Forking

When a disagreement is about *identity* rather than enrichment — "the concept as I understand it is not the concept consensus is defining" — forking would be a first-class operation, not an implicit abandonment. A forked slug would declare lineage: *derived from X at version Y, diverging on these grounds.* SKOS already provides the vocabulary (`skos:narrower`, `skos:related`, `skos:broadMatch`) to preserve the semantic relationship even as identity diverges. The goal would be for forking to stay rare — reserved for genuine concept splits, not the default response to any disagreement.

---

## A plausible bootstrapping path

Organic, no big-bang launch:

1. HyperTaxonomy/TermConnect gain traction with the existing peer-site shared-vocabulary model.
2. TC gains an option to point a shared vocabulary at a distributed concept address rather than a peer site.
3. Sites whose terms have no obvious authoritative peer opt in first.
4. Network effects build the distributed vocabulary over time.
5. As more sites subscribe to a concept, more agents participate in its governance.

The distributed layer would not need to be perfect before anyone uses it — only good enough that sites prefer it over pointing at a peer site for concepts where no obvious authoritative peer exists.

{: .note }
> **The honest caveat.** There is no bootstrap path today, and it depends on adoption that may or may not happen. This is recorded as a direction, not a plan — a starting point should the community decide to take TermConnect's federation model further.

---

## How it builds on what TermConnect already does

| Existing TermConnect feature | How it would evolve |
|---|---|
| **Shared Vocabularies** | Currently point at a specific peer site; a distributed concept address would be the next step — same UI, different target. |
| **Delegated identity** | Currently delegates to a site's definition; a concept address would delegate to the concept itself. |
| **External links as concept fingerprint** | Already the semantic foundation; the distributed layer would make that fingerprint persistent and serverless. |
| **`htax_term_uris` / `_tc_uris` index** | The local URI index becomes the caching layer for concept resolution. |
| **Link types (Wikidata, Wikipedia, …)** | The identity core of a concept record would be drawn from these same link types. |

---

## Open questions

1. **Which DID method?** `did:web` still needs a server; `did:key` is serverless but immutable; `did:ipfs` or a custom method may be required.
2. **Agent bootstrapping** — who runs the first agents before the network is large enough to sustain consensus?
3. **Disambiguation** — "Python" the language vs. the snake vs. Monty Python: agents would need to look outward at a subscribing site's content, not just at the concept record.
4. **Concept drift** — how does an identity core update as a concept's meaning evolves, without breaking interoperability for sites that subscribed to the older definition?
5. **Niche coverage** — the most interesting territory is concepts Wikidata covers poorly (niche, emerging, or local vocabulary), where an identity core may have no Q-number at launch.
