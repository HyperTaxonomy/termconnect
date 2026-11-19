---
title: Developer Reference
nav_order: 4
has_children: true
has_toc: false
---

# TermConnect Developer Reference

For theme developers and plugin authors who integrate with TermConnect via its PHP API, hooks, and REST endpoints.

For end-user configuration and feature guidance, see the [User Guide](../user-guide/).

This reference is split into the following pages:

- **[Term Links API](term-links-api.html)** — the link type registry, per-term link functions, the `tconn_term_uris_changed` action, the database index, link suggestions, delegated identity functions, term meta keys, scheduled events, and cache management.
- **[Peer Fetch API](peer-fetch-api.html)** — validation, term resolution, post fetching, scoring, and FOAF discovery.
- **[External Documents API](external-documents.html)** — contributing peer-site posts to consumers via HyperTaxonomy's `htax_term_external_posts` hub.
- **[Actions & Filters](actions-filters.html)** — the `tconn_term_uris_changed` action, and filters for link types and URLs (`tconn_link_types`, `tconn_link_uri`, `tconn_link_value`), identifier suggestions (`tconn_suggest_config`, `tconn_parse_suggest_response`), taxonomies (`tconn_enabled_taxonomies`, `tconn_taxonomy_peer_authors`), `tconn_term_links_see_also_html`, `tconn_index_extra_uris`, and HyperTaxonomy's `htax_term_uris` and `htax_term_external_posts`.
- **[REST API](rest-api.html)** — the `X-TermConnect` header, term endpoint fields, and the `htax_term_uri` query filter.
- **[Stored Settings](stored-settings.html)** — every key in the `tconn_settings` option.
