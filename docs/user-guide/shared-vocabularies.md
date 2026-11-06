---
title: Shared Vocabularies
parent: User Guide
nav_order: 4
---

# Shared Vocabularies

Shared Vocabularies lets you mirror a remote site's term structure into your local taxonomy — creating local terms that stay in sync with a peer, so your editors work with the same vocabulary as the wider community. Each mirrored term gets per-term delegated identity set up automatically, so descriptions and external links sync on the same schedule.

The remote site only needs to be a standard WordPress site for most of this feature. A few options need plugins on the peer:

- **Remote Groups** needs HyperTaxonomy and TermConnect.
- **Relationship delegation** needs HyperTaxonomy.
- **External links** need TermConnect, with **Share external links** on.

## Setting up a vocabulary source

1. Go to **Terms → Shared Vocabularies**
2. Choose a scope tab and click **+ Add source** — the form opens as a floating panel over the table.
3. Optionally enter a **Name** and **Description** for the source — these help you distinguish sources in the table, especially when multiple sources reference the same peer site or taxonomy.
4. Select a peer site. The **Remote taxonomy** dropdown populates automatically from the peer's available taxonomies.
5. Fill in the scope-specific fields and click **Save source** — the panel closes and an initial sync runs automatically in the row. The **Actions** column shows a spinner while the sync is in progress; Edit and Remove are disabled until it completes.

The **Source** column in the table shows the name as the primary label when one is set, with the peer site below it; unnamed sources show the peer site directly. On the term edit screen, the "Shared vocabulary" attribution line shows the name when one is set.

The active tab is preserved in the URL (`?scope=<tab>`), so the page returns to the same tab after saving or reloading.

## Scope tabs

The page is divided into five tabs (four without HyperTaxonomy) processed in a fixed priority order:

| Tab | Taxonomy | Term selection | Descendants |
|---|---|---|---|
| **Taxonomies** | Dropdown — all taxonomies on peer | All terms | All |
| **Remote Groups** *(HT only)* | — | Dropdown — all non-hidden groups on peer (hierarchical, with tree indentation) | — |
| **Local Groups** *(HT only)* | — | Local group dropdown (hierarchical, with tree indentation) | Optional checkbox |
| **Term Hierarchies** | Dropdown — hierarchical only | Keyword search autocomplete (add multiple roots) | Always synced |
| **Individual Terms** | Dropdown — all taxonomies on peer | Keyword search autocomplete (add individual terms) | Never |

**Taxonomies tab** — mirrors an entire remote taxonomy. The taxonomy is selected from a dropdown populated from the peer; available taxonomies are fetched when a peer is selected.

**Remote Groups tab** *(requires HyperTaxonomy on both sites, and TermConnect on the peer)* — fetches a HyperTaxonomy group from the peer and mirrors its member terms locally. Select a peer site; the group dropdown populates automatically with all groups from the peer, indented to reflect the group hierarchy. Select one group per source. On first sync (or any sync where no matching local group exists), a local group with the same slug is created automatically if one does not already exist. All member terms are created or linked locally and assigned to that group, across all enabled taxonomies.

Hidden groups on the remote peer are not listed in the dropdown and cannot be selected as a sync source. If the remote peer uses HyperTaxonomy's group visibility feature, any group marked hidden there will be absent from this list regardless of your role on this site.

When a term is removed from the remote group between syncs, its local group membership is cleared and its delegated identity is released. The local term itself is kept; the normal **Missing terms** handling applies only if the term is fully removed from the peer — not just from the group.

**Local Groups tab** *(requires HyperTaxonomy)* — delegates all members of a local HyperTaxonomy group to the remote peer by slug. The group is selected from a dropdown that shows the full group hierarchy with indentation. An optional checkbox also syncs descendants of hierarchical group members. The local group decides which terms are delegated, and nothing is written to the peer. Members with no term of the same slug on the peer are skipped.

**Term Hierarchies tab** — mirrors specific hierarchy branches. Select a hierarchical remote taxonomy from the dropdown, then search for root terms by keyword. All descendants of each selected root are synced automatically; no separate checkbox is needed.

**Individual Terms tab** — delegates specific terms without their descendants. Select a taxonomy, then search for terms by keyword. Each selected term is mirrored independently. Group terms (`group` taxonomy) are not shown in this search — use Remote Groups for that.

## Source priority and ordering

Sources are processed in fixed tab order — Taxonomies first, then Remote Groups, then Local Groups, then Term Hierarchies, then Individual Terms (all when HyperTaxonomy is active). This is first-wins priority: if a Taxonomies source claims a term, subsequent sources skip it. Within each tab, drag rows up or down to set priority among sources of the same scope; the topmost matching row wins.

A brief explanation of this priority model is shown above the tabs on the Shared Vocabularies page.

When adding root or individual term pills in the edit form, any pill that will be skipped because a higher-priority source already covers it is marked with a yellow **⚠** icon. Hovering the icon shows which source takes precedence. Pills loaded from an existing source are checked when the taxonomy dropdown finishes loading.

A term that's already delegated by one source isn't claimed by any other source. To reassign a term to a different source, remove its delegation on the term edit screen first.

## Sync fields

Each source has two independent sync toggles, both on by default:

- **Sync name from remote** — remote term name is written to the local term; the Name field is read-only on the term edit screen while this is on.
- **Sync description from remote** — remote term description is written locally; the Description field is read-only while this is on.

These can be changed at any time by clicking **Edit** on a source row, which updates all of the source's terms. Terms it adds later start with these settings. A term delegated from its own edit screen can also have its settings changed there, and syncs keep them.

When the peer runs TermConnect, the form also shows **Sync external links from peer site**, which is always on.

## Term matching

New terms are matched by slug. WordPress keeps slugs unique per taxonomy, so the match is always unambiguous. When a remote term is processed:

1. A local term already delegated to that remote term → update it, renaming its slug if the remote slug changed
2. A local term with the same slug and no existing delegation → link it, set up delegation
3. A local term with the same slug already claimed by another source → skip (first-wins)
4. No local term with that slug → create one, set up delegation

TermConnect stores each remote term's integer ID, which is how a sync recognizes a renamed term. If a remote term keeps its slug but gets a different ID (for example, after a peer database migration), a term refresh updates the stored ID. Term refreshes between syncs look terms up by slug, so a renamed term can briefly show as not found. See [Delegated Identity](delegated-identity.html#site-health-source-term-not-found).

## Sync scenarios

| Remote event | Local result |
|---|---|
| Term renamed (same ID, new slug) | Local slug changed to match, and the name too if **Sync name** is on; posts unaffected |
| New term added | Local term created under correct parent |
| Term deleted | Local term orphaned; action depends on each source's **Missing terms** setting (see below) |
| Term moved to different parent | Depends on each source's **Parent mismatch** setting (see below) |
| Term concept replaced (new slug, same KB identifier) | Merge proposal created (see below) |
| Term moved outside configured sync scope (left remote group, left hierarchy root's subtree, removed from Individual Terms list) | Delegation cleared; local term kept; for a Remote Groups source, group membership also cleared |

## Handling orphaned mirrored terms

When a delegated term's remote counterpart is not found in the sync fetch, TermConnect first attempts an individual slug-based fetch to rule out pagination gaps or a race condition. Only after that confirmation is the term treated as orphaned. The **Missing terms** action configured per source then applies:

| Setting | Action |
|---|---|
| **Flag for review** (default) | Term marked as not found and kept; Site Health notice raised |
| **Delete if no local posts or relationships** | Term deleted only when it has no tagged posts, no HyperTaxonomy relationship rows, and no direct child term that is itself a delegated mirror; otherwise flagged |
| **Delete unconditionally** | Term deleted regardless of usage, including when it has delegated children (which are reparented to the grandparent, as normal for `wp_delete_term()`) |

Configure per source in **Terms → Shared Vocabularies → Edit source → Missing terms**.

## Parent mismatch handling

When a synced term has a different parent locally than on the peer site (hierarchical taxonomies only):

- **Flag for review** (default): surfaces in Site Health; no structural change
- **Link only**: sets up delegation but leaves the local parent unchanged
- **Re-parent**: moves the local term to match the remote hierarchy

This applies when the term has a parent on the peer. A term moved to the top level on the peer isn't treated as a mismatch, and stays where it is locally.

## Relationship delegation

Requires a **Taxonomies** source, and HyperTaxonomy on both sites.

When a Taxonomies source is configured and the peer runs HyperTaxonomy, two additional options appear:

- **Sync relationship types and relationships from this peer** — imports custom relationship type definitions from the peer and writes relationship rows (term inclusions and custom-type relationships) alongside term sync. Off by default because it adds time to each sync on large taxonomies: terms are still fetched in pages of 100, but each relationship is written separately.
- **Include cross-taxonomy relationships** — additionally writes relationships whose target term is in a different taxonomy. Only processes targets that are themselves delegated to the same peer site. Requires a prior full sync of each involved taxonomy.

Relationship type definitions can also be imported on demand from **Terms → Term Relationship Types** (when HyperTaxonomy is active), using the **Discover Relationship Types from Peer** section at the bottom of the page.

## Stale relationship rows

After each full-taxonomy sync with relationship delegation enabled, TermConnect compares local relationship rows (for custom types owned by the peer) against the relationships the peer reported in the current sync. Any local row no longer present on the peer is flagged and kept. TermConnect tracks when each one was first detected and most recently confirmed.

Stale rows surface in three places:
- **Site Health** — the shared vocabularies test reports a count of affected terms
- **Term edit screen** — a warning panel lists each stale row with its type, target term, and when it was first detected
- **Term list** — a warning icon in the **Sync** column; a **Sync Status** filter lets you display only affected terms

Stale warnings clear automatically when the peer restores the relationship and a new sync runs.

## Merge proposals

When a sync run orphans a local term and a newly-active term from the same peer shares the same external identifier (Wikidata ID, GeoNames code, etc.), TermConnect detects that the concept was likely renamed or replaced. A merge proposal appears on the Shared Vocabularies page:

- **Merge**: reassigns all posts from the orphaned term to the replacement, transfers its HyperTaxonomy relationships, custom relationships and sticky posts, deletes the orphan, and creates an archive redirect from the old slug. Requires HyperTaxonomy.
- **Dismiss**: removes the proposal without merging; the orphaned term remains.

If **Auto-accept redirects** is on (**Settings → TermConnect → Shared Vocabularies**) and the proposal comes from a sync you run with **Sync now**, the merge runs automatically without review. Scheduled syncs always queue it for review.

## Delegation from the term edit screen

When delegating a term directly from the term edit screen, the confirmation pane includes a **Track and sync descendant terms** checkbox (shown for hierarchical taxonomies). Checking it creates a Term Hierarchies source for that term, so descendants are mirrored automatically. Without the checkbox, an Individual Terms source is created instead.

## Removing a source

Click **Remove** on any row to delete a vocabulary source. All delegation by that source ends immediately: local terms stop being delegated, and any backed-up original names and descriptions are restored. Local terms themselves are not deleted.

## Site Health

The **TermConnect shared vocabularies** Site Health test reports:
- **Merge proposals pending** — orphaned terms matched to a replacement by shared KB identifier; review on the Shared Vocabularies page
- **Terms in wrong position** — misplaced terms flagged for manual review
- **Orphaned mirrored terms** — remote term confirmed gone; local term still exists and is marked as not found. The **TermConnect delegated identity** test lists the same terms with a **Check for rename** link. See [Delegated Identity](delegated-identity.html#site-health-source-term-not-found).
- **Stale relationship rows** — delegated terms with local relationship rows for peer-owned custom types that were absent from the peer in the most recent sync
- **Delegation from deleted sources** — terms still delegated by a source that no longer exists; these terms are not synced. Re-add the source or end delegation from each affected term.

## Syncing

Each source has a configurable **Sync interval** (twice daily, daily, weekly). TermConnect checks hourly for sources that are due, so a sync can run up to an hour after its interval is up. Syncs also run:

- Automatically after saving a **new** source, or after saving an edit where the root/individual term pill list changed — the **Actions** column shows a spinner while in progress; the result (e.g. "✓ 12 synced, 3 created, 0 renamed") appears below the action buttons and persists across page reloads. Saving an edit without changing the pill list reloads the page without triggering a sync, so existing stats are preserved.
- On demand via **Sync now** in the Actions column. Edit and Remove are disabled for that row while a sync is running.

There's no single "sync all sources" button, so use **Sync now** on each source row for an immediate sync. **Re-sync All** in **Settings → TermConnect → Cache** is different: it refreshes the data of terms that are already delegated, without adding terms or changing the hierarchy.

## Export / Import

Shared Vocabularies source configurations are included in the TermConnect export and restored on import. Per-term delegations are also exported separately, and cover all mirrored terms.
