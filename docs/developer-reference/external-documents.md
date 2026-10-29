---
title: External Documents
parent: Developer Reference
nav_order: 6
---

## 22. Providing External Posts

TermConnect contributes related posts from configured peer sites and feeds to any
same-site consumer that asks HyperTaxonomy for a term's external posts — for
example, [WP-SKOS](https://wordpress.org/plugins/wp-skos/)'s document-search
endpoint. No configuration is required, and **TermConnect does not depend on or
reference any specific consumer**.

The contract is HyperTaxonomy's generic term API, the same pattern TermConnect
already uses for term URIs:

- **Provider (TermConnect)** hooks HyperTaxonomy's `htax_term_external_posts`
  filter (`TC_HT_Integration::provide_term_external_posts`,
  `includes/integration/class-hypertaxonomy-integration.php`).
- **Consumer** reads `htax_get_term_external_posts( $term_id, $args )`. The consumer
  never references TermConnect; TermConnect never references the consumer.
- **HyperTaxonomy** owns visibility: the getter returns `[]` for a term hidden
  from the current user and does not even fire the provider filter. (When
  HyperTaxonomy is not active, there is no external-posts API and this integration
  is inert.)

For a given term, TermConnect runs its full collection (the same core behind
`[tc_peer_posts]`, see [Peer Fetch API](peer-fetch-api.html)) **scoped to that one
concept term**: explicit per-term peer links (`_tc_link_{slug}`), shared-identifier
and synonym **auto-matching**, RSS/Atom **feeds**, and — when requested — **FOAF**
discovery. It returns **plain normalized post arrays** (the
`tc_external_normalize_post` shape: `url`, `title`, `excerpt`, `date_gmt`,
`modified_gmt`, `thumbnail_url`, `origin`, `score`, …). Converting those into a
consumer-specific shape (e.g. `foaf:Document` JSON-LD) is the consumer's job.

### Scope hints (`$args`)

HyperTaxonomy forwards an opaque `$args` bag to the provider; TermConnect honors:

| Key | Default | Meaning |
|-----|---------|---------|
| `platforms` | all supported (`wordpress`, `feed`) | Peer platform types to include. |
| `foaf_depth` | `0` (direct peers only) | FOAF traversal depth; `>0` opts into FOAF. |
| `peers` | *(all)* | Restrict to specific peer link-type slugs. Consumers that should not expose the peer topology (e.g. WP-SKOS) simply leave this unset. |
| `per_page` | `20` | Fetch-size hint. |

Auto-matching (shared identifier + synonyms) is always applied for peers; it is not
a separate toggle. Origin validation and the site blacklist apply throughout.
