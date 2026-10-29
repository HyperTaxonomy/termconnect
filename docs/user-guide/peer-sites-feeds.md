---
title: Peer Sites & Feeds
parent: User Guide
nav_order: 1
---

## 1. Enabled Taxonomies

Go to **Settings → TermConnect → Enabled Taxonomies** and check each taxonomy where you want TermConnect active. Enabling a taxonomy:

- Shows the **Delegated Identity** and **External Links** panels on its term add/edit screens.
- Includes it in "See also" display on term archive pages.
- Turns on peer site term matching when fetching cross-site posts.

Built-in taxonomies (Tags, Categories) and any public custom taxonomies appear in the list.

---

## 2. Peer Sites

**Terms → Peer Sites** is where you manage external sites, feeds and services where your terms can have identifiers. A namespace defines what kind of identifier it holds and how to construct a URL from it: Wikidata Q-numbers, GeoNames IDs, Wikipedia article slugs, a peer WordPress site, an RSS feed, or any custom service. Once a namespace is enabled, editors can attach identifiers to individual terms from the **External Links** panel on each term's edit screen.

> **Namespaces vs. link types.** A *namespace* is the identifier space plus its URL-construction rule (e.g. Wikidata Q-numbers → `wikidata.org/wiki/`). A *link type* — the configured entries shown on the **Peer Sites** screen — is an entry that uses a namespace. Peer sites and feeds are link types whose namespace is a remote WordPress or feed endpoint.

When a local term shares an identifier with a term on a peer WordPress site, TermConnect can delegate its identity to the peer — keeping the name, description, and external links in sync automatically. A collection of terms whose identity is delegated to a peer site is called a **Shared Vocabulary** (see [Shared Vocabularies](shared-vocabularies.html)).

TermConnect also uses shared identifiers to auto-match equivalent terms on peer sites and generates "See also" links on term archives.

**Setting up:**

1. Go to **Terms → Peer Sites**. Twenty-one built-in namespaces are available: **General reference** (Wikidata, Wikipedia, Grokipedia, Wikiquote); **Language** (Wiktionary, Merriam-Webster); **Geography** (GeoNames, OpenStreetMap, Wikivoyage); **Biology/Nature** (Wikispecies, iNaturalist, GBIF); **People/Organizations** (VIAF, ORCID); **Library/Subject headings** (LCSH, MeSH, Getty AAT, Getty TGN); **Academic/Structured data** (OpenAlex, schema.org, GS1).
2. Built-in namespaces start disabled — enable the ones relevant to your content by clicking **Enable**. Custom namespaces you add are enabled automatically. Drag rows up or down to change their display order; enabled namespaces appear in this order in the "See also" section on term archive pages (if that feature is enabled in Settings and the namespace has "Show on archives" checked).
3. Once a namespace is enabled, edit any term and fill in its identifier in the **External Links** panel. Enter just the identifier, not the full URL — `Q42` for Wikidata, `Douglas_Adams` for Wikipedia. TermConnect constructs the full URL from the namespace's URI prefix. A preview link appears once saved.

**Restricting a namespace to specific taxonomies:**

By default a namespace is available for any TermConnect-enabled taxonomy. To restrict it — for example, making ORCID available only for an Author taxonomy — edit the namespace and check taxonomies under **Restrict to taxonomies**. Leave all unchecked to keep it available everywhere. When you narrow the restriction, TermConnect counts affected identifiers and prompts for confirmation before removing them. Disabling a namespace never deletes stored values; data is only removed when a restriction is applied or the namespace is deleted.

**Autocomplete:**

Supported external services (Wikidata, Wikipedia, iNaturalist, VIAF, LCSH, and others) offer live autocomplete suggestions as you type. WordPress peer namespaces autocomplete by searching the peer site's REST API. MediaWiki namespaces autocomplete via the site's OpenSearch API. Other namespaces accept free-form text.

**Adding a custom namespace:**

At **Terms → Peer Sites**, fill in the **Add New** form:

- **Label** — shown to users
- **Platform** — *WordPress peer (REST API)*, *RSS / Atom feed*, *MediaWiki (identifier + autocomplete)*, or *Custom identifier*. Peer and feed namespaces participate in `[tc_peer_posts]`; MediaWiki and custom identifier namespaces generate "See also" links.
- **Peer site URL** *(WordPress peer)* — site root only, e.g. `https://example.com`
- **Feed URL** *(feed)* — full RSS/Atom URL, e.g. `https://example.com/feed/`
- **Wiki URL** *(MediaWiki)* — wiki article URL prefix, e.g. `https://wiki.example.com/wiki/`. Autocomplete is provided automatically via the site's OpenSearch API (`/w/api.php?action=opensearch`).
- **URI Prefix** *(custom identifier)* — fixed URL prepended to the identifier, e.g. `https://vocab.example.com/concepts/`
- **Restrict to taxonomies** — optional; leave unchecked to allow any taxonomy

Deleting a custom namespace also removes all stored identifiers for that namespace.

**Term list columns:** The term list table gains a **Links** column (number of external links per term, sortable) and a **Sync** column (delegated-identity sync status). Both are hidden by default; enable them via **Screen Options** (top-right of the term list screen). Their filter dropdowns are also hidden while the columns are off.

---

## 3. WordPress Peer Sites

When a link type's **Platform** is *WordPress peer (REST API)*, the identifier stored per term is the **slug of the matching term on the peer site** (e.g. `javascript` for `https://blog.example.com/tag/javascript/`). Multiple WordPress peer link types can point to different sites; the same term can have identifiers for several peers simultaneously.

The **Remote taxonomy REST base** field handles cases where the peer site uses a different taxonomy. If your local terms are in `post_tag` (REST base: `tags`) but the peer uses a custom taxonomy `topics`, enter `topics` here. Leave blank when the peer uses the same taxonomy slug.

**To set up a connection:** create a WordPress peer link type for the peer site, then edit any local term and enter the corresponding slug on the peer in the External Links panel.

**Cache controls** appear in **Terms → Peer Sites** next to each peer site: **Clear Cache** purges that site's cached post lists; **Poll Now** forces an immediate fetch.

---

## 4. RSS / Atom Feeds

A feed link type polls an RSS or Atom feed on a configurable schedule and accumulates posts into a local cache. Local terms are mapped to feed categories; `[tc_peer_posts]` scores the cached posts by category match.

**Creating a feed link type:**

1. Go to **Terms → Peer Sites → Add New**
2. Set **Platform** to *RSS / Atom feed*, enter the feed URL, set poll interval (minimum 5 minutes, maximum 24 hours; default 1 hour) and **Max cached posts** (default 500)
3. Click **Add** — the type is enabled and scheduled immediately

**Mapping a term to a feed category:**

1. Edit the term → **External Links** → click **+** → choose the feed type
2. Click **Pick category…** — a filterable list of all categories seen in recent polls
3. Select the matching category (e.g. "Machine Learning")

**Category accumulation:** Each poll merges new items into the cache. Categories never disappear once seen, even if they scroll off the feed. Click **Refresh from feed** in the picker panel to load categories from the latest poll.
