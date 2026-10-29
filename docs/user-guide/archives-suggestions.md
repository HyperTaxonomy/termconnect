---
title: Archives & Suggestions
parent: User Guide
nav_order: 2
---

## 5. See Also on Archives

When a term has identifiers set and the link type has **Show on archives** enabled, a "See also" section appears on the term's archive page.

**Controls:**

- **Global toggle:** Settings → TermConnect → See Also — "Show 'See also' section on term archive pages"
- **Per-type toggle:** **Show on archives** checkbox in the link type's edit form
- **Display format:** *Inline* ("See also: Wikidata · GeoNames") or *List* (unordered list)
- **Inline label:** Customizable text prepended in inline format (default "See also: "). Leave blank to omit.

Both locally-set links and delegated links from peer sites contribute to the "See also" display — `tc_build_merged_links()` combines them, applying the same override logic used in the External Links panel (delegated wins when both reference the same site).

When HyperTaxonomy's **Show Description** feature is enabled for a term, the "See also" block appears at the end of the description block. Developers can override the full HTML via the `tc_term_links_see_also_html` filter.

---

## 6. Link Suggestions

When enabled, a short form appears on term archive pages (below "See also") inviting visitors to suggest an external link for that term. Suggestions are stored for admin review and are never applied automatically.

**Enabling:**

Settings → TermConnect → General → **Allow suggestions**. Check the box; optionally customize the form label (default "Suggest a related concept"). The form is automatically suppressed on terms with delegated identity — those already have authoritative external links from a peer site.

**How suggestions are stored:**

Each submission is stored as a WordPress comment with `comment_type = 'tc_suggested_link'`, attached to the term (not to any post). Standard WP infrastructure handles IP capture, user agent, spam filter hooks (Akismet etc.), and the moderation workflow. Suggestions are never publicly visible, don't appear in post comment counts or pending-comment bubbles, and stay in place when posts are deleted; a term's pending suggestions are moved to the trash when the term is deleted.

**Reviewing suggestions:**

Go to **Comments → Link Suggestions**. This opens the standard WordPress comments admin filtered to `tc_suggested_link` type, with three customised columns:

- **Proposed URL** — the submitted URL (clickable) with auto-match result shown below it.
- **Note** — the submitter's optional note and email address.
- **Term** — the term the suggestion is for, linked to its edit screen.

Each row shows a context-sensitive primary action based on the submitted URL:

| Condition | Row action |
|---|---|
| Matches an **enabled** link type, link does not already exist | **Accept** — opens term edit with the link type pre-selected and identifier pre-filled; admin saves to apply |
| Matches a **disabled built-in** link type | **Enable link type** — opens Peer Sites admin so the admin can enable the matching type and then return to accept the suggestion |
| Does not match any link type | **Add link type** (or **Add WordPress peer** if the domain runs HyperTaxonomy) — opens Peer Sites admin with the URL pre-filled |
| URL belongs to this site, resolves to a local term | **Edit term** — opens that term's edit screen for manual relationship setup. When HyperTaxonomy is active, the link includes `context_term_id` pointing to the receiving term; if the two terms are already related (directly or indirectly), the receiving term's pill is highlighted on arrival. |
| Link already exists | *(no primary action — suggestion is stale; admin can Trash it)* |

Standard WP actions (Quick Edit, Edit, Spam, Trash) remain available on all rows.

**Per-term view:**

A collapsible "Suggested identifiers" section appears at the bottom of the External Links panel on any term edit screen that has pending suggestions. It shows the same Accept / Dismiss actions inline without navigating to the full queue.

**Notifications:**

Settings → TermConnect → General → **Notify admin** — sends an email when each suggestion is submitted (default on). The email subject identifies the term; the body includes the proposed URL and a direct link to the Link Suggestions admin page.

**Settings reference:**

| Key | Default | Description |
|---|---|---|
| `allow_suggested_links` | `false` | Show suggestion form on term archives |
| `suggested_link_label` | `"Suggest a related concept"` | Label shown above the form |
| `suggested_link_notifications` | `true` | Email admin on new suggestion |
