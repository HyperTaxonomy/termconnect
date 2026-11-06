---
title: Developer Reference
nav_order: 3
has_children: true
---

# TermConnect Developer Reference

For theme developers and plugin authors who integrate with TermConnect via its PHP API, hooks, and REST endpoints.

For end-user configuration and feature guidance, see the [User Guide](../user-guide/).

This reference is split into the following pages:

- **[Filters](filters.html)** — link types and URLs (`tc_link_types`, `tc_link_uri`, `tc_link_value`), identifier suggestions (`tc_suggest_config`, `tc_parse_suggest_response`), taxonomies (`tc_enabled_taxonomies`, `tc_taxonomy_peer_authors`), `tc_term_links_see_also_html`, `tc_index_extra_uris`, and HyperTaxonomy's `htax_term_uris` and `htax_term_external_posts`.
- **[Term Links API](term-links-api.html)** — the link type registry, per-term link functions, the `tc_term_uris_changed` action, the database index, link suggestions, delegated identity functions, term meta keys, scheduled events, and cache management.
- **[Peer Fetch API](peer-fetch-api.html)** — validation, term resolution, post fetching, scoring, and FOAF discovery.
- **[REST API](rest-api.html)** — the `X-TermConnect` header, term endpoint fields, and the `htax_term_uri` query filter.
- **[Settings Reference](settings-reference.html)** — every key in the `tc_settings` option.
- **[External Documents](external-documents.html)** — contributing peer-site posts to consumers via HyperTaxonomy's `htax_term_external_posts` hub.
