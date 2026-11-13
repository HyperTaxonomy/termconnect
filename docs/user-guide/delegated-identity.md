---
title: Delegated Identity
parent: User Guide
nav_order: 6
---

# Delegated Identity
{: .no_toc }

Delegated identity lets a term defer its authoritative definition to a matching term on a peer WordPress site. The peer becomes the canonical source for the term's slug, name, description, and external links; changes on the remote are reflected locally on the next sync.

The remote site only needs to run standard WordPress (REST API). No TermConnect installation is required on the remote. If the remote site runs TermConnect with **Share external links** on, external links are also pulled in and cached locally.

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Setting up from term edit

1. Edit a term and find the **Delegated Identity** panel (above External Links).
2. Click **+** and select a peer site. Only WordPress peer sites are listed.
3. TermConnect looks for a term with the same slug in the same taxonomy on that peer. If there isn't one, an error appears, and this term can't be delegated to that peer.
4. A preview shows the remote description and any external links.
5. Choose sync options (all on by default):
   - **Sync name from remote** replaces the local name with the remote name, and makes the name field read-only while delegation is active.
   - **Sync description from remote** replaces the local description with the remote description, and makes the description field read-only while delegation is active.
6. In a hierarchical taxonomy, **Track and sync descendant terms, including future ones added remotely** also mirrors the term's subtree. It's on by default.
7. Click **Delegate**. The selected fields are updated immediately, and later syncs keep them up to date.

## Changing sync options after delegation is active

Click the **Sync settings** button next to the active delegation pill to open a floating panel where you can toggle **Sync name** and **Sync description** independently. Changes take effect immediately; the page reloads to reflect the new read-only state of the affected fields. Turning one off restores the value the term had before delegation. These settings belong to the term, and syncs keep them. Editing a Shared Vocabulary source's own sync settings changes them for all of its terms.

Setting up delegation from term edit automatically creates or updates a vocabulary source entry visible in **Terms → Shared Vocabularies**. Without descendant tracking, it appears under the **Individual Terms** tab (individual term, no subtree). With descendant tracking on, it appears under the **Term Hierarchies** tab (term as a branch root; descendants are synced).

If you delegate multiple terms to the same peer site in the same taxonomy with the same sync settings (Sync name and Sync description), they are collected into a single Shared Vocabulary entry — not one entry per term. If an SV already exists for that peer site and scope but with different sync settings, a prompt appears below the Delegate button:

> *A Shared Vocabulary for this peer site already exists with: sync name: yes, sync description: no.*

Two buttons are offered:

- **Add to existing SV** — the term joins the existing SV and inherits its sync settings; your selected settings are superseded.
- **Create new SV** — a separate SV is created with your selected settings.

## Setting up via Shared Vocabularies

Delegation for many terms at once — entire taxonomies, hierarchy branches, or HyperTaxonomy groups — is configured on the **Terms → Shared Vocabularies** page. See [Shared Vocabularies](shared-vocabularies.html).

## What changes when delegation is active

- **Slug** is always locked to match the remote term. The slug field is read-only in the term edit screen and Quick Edit.
- **Name** (when Sync name is on): replaced with the remote name; field is read-only.
- **Description** (when Sync description is on): replaced with the remote description; field is read-only.
- **External links** — the External Links panel shows a merged view: locally-set pills (blue) and delegated-only pills (green, dashed border) appear together, sorted alphabetically. Two overlap cases: (1) **Same URL** — a single blue pill is shown with an ×; clicking × clears the local value and the pill turns green without disappearing, since the delegated link is still present. (2) **Same site, different URL** — the local pill is shown at reduced opacity with a "Overridden by delegated link" tooltip, and the delegated URL appears as a separate green pill. Delegated-only pills (no local equivalent) have no × button.
- **Remote term ID tracking** — after each successful sync, if the remote term's slug matched but its integer ID differs from the stored value (e.g. after a peer database migration), TermConnect updates the stored ID in both the term meta and the Shared Vocabularies roots entry. The remote term ID isn't shown in the admin screens. Shared Vocabulary syncs use it to recognize a renamed term, and **Check for rename** uses it too (see [below](#site-health-source-term-not-found)).
- The **Delegated Identity** panel shows the source as a pill (`[Peer site]: [remote-slug] ↗`) with the last sync time. A **Refresh now** link appears only for terms with no Shared Vocabulary source. Delegating from the term edit screen always creates a source, so for those terms, use **Sync now** on the Shared Vocabularies page.

All originals (name, description) are saved privately and restored when delegation is removed.

**Sync enforcement note:** The read-only fields are enforced in the standard admin UI. Changes made via the REST API, imports, or programmatic writes are not blocked — but will be overwritten back to the remote values on the next sync.

## Remote taxonomy

The remote term is always looked up in the same taxonomy as the local term. Cross-taxonomy delegation is not supported.

## Ending delegation

Click the × button on the active delegation pill to open a confirmation panel. The panel lets you:

- **Restore local name** (shown only when Sync name is on) — reverts to the name the term had before delegation. On by default.
- **Restore local description** (shown only when Sync description is on) — reverts to the original description. On by default.
- **Keep external links for existing peer sites** — when checked, any external links that were pulled from the remote term are kept as local links after delegation ends. If a local link for the same site was previously suppressed by the delegated link, it is replaced by the delegated link (not restored); the delegated link wins and becomes the new local link for that platform. When unchecked, delegated links are discarded and any previously suppressed local links automatically become active again.

Whether delegation can be removed from the term edit screen depends on the vocabulary source that manages it:

| Source | × shown | How to remove |
|---|---|---|
| Individual Terms tab or Term Hierarchies root | ✓ | Click × — removes delegation and source entry. If other sources of lower priority cover this term, the next one claims it on its next sync. |
| Taxonomies tab | — | Notice: "Shared vocabulary (taxonomy): *Tags* · Site B — Manage" |
| Remote Groups tab | — | Notice: "Shared vocabulary (group): *Group name* · Site B — Manage" |
| Local Groups tab | — | Notice: "Shared vocabulary (group): *Group name* · Site B — Manage" |
| Term Hierarchies descendant | — | Notice: "Shared vocabulary (hierarchy): *Tags: Root name* · Site B — Manage" |

When the source has a name, the notice shows it instead: "Shared vocabulary: *Name* (taxonomy · Site B) — Manage".

For terms whose × is hidden, go to the linked Shared Vocabularies tab and remove the source or narrow its scope. After removing, the next priority source (by tab order: Taxonomies → Remote Groups → Local Groups → Term Hierarchies → Individual Terms) may claim the term on its next sync; repeat as needed.

## External links from the remote term

When the remote site runs TermConnect with **Share external links** on, its external links are cached locally as delegated links. They appear in the merged External Links panel and in the `tc_links` REST field, which lets other peer sites match this term by shared external links without configuring a slug.

A peer running HyperTaxonomy also reports in the same response whether the term redirects elsewhere, so redirects and external links are checked together.

## Redirect detection

When a remote term is detected as a HyperTaxonomy redirect (via `htax_redirect` in the REST response), it is queued for admin review in **Site Health → TermConnect delegated identity**, which shows where the source moved and offers three actions:

- **Follow redirect** — point the delegation at the new remote term, keeping the delegation's settings (name/description syncing, descendants) and the backed-up original name and description.
- **Keep current delegation** — dismiss this redirect. TermConnect stops raising it; if the remote term later redirects somewhere *else*, the new target is queued again. The term edit screen shows the status as *Remote term redirected — current delegation kept*.
- **End delegation** — remove the delegation and restore the term's original name and description.

The review entry also clears on its own when the remote term stops redirecting, when the term is re-delegated or its delegation ends, or when the term is deleted. With **Auto-accept redirects** on (**Settings → TermConnect → Shared Vocabularies**), the delegation follows the redirect automatically instead, within the same taxonomy. It follows chains of redirects only with **Auto-follow chain redirects** on. Delegations managed by a Shared Vocabulary are not followed from Site Health — the vocabulary source decides which remote term they track, so update it on **Terms → Shared Vocabularies**.

## Site Health: source term not found

When a delegated term's remote source returns a not-found response, the Site Health **TermConnect delegated identity** test lists it under **Source term not found**. A renamed remote term can show up here because term refreshes look it up by slug. The next Shared Vocabulary sync finds it by ID, and renames the local term to match. To check sooner, click **Check for rename** next to the entry, which looks up the remote term by its stored ID. No remote call is made until you click. The probe returns one of three outcomes:

| Outcome | Condition | Actions offered |
|---------|-----------|-----------------|
| **A — term gone** | Remote term not found by ID either | End delegation |
| **B — renamed, slug free** | Remote term found; new slug is not taken locally | Re-link and rename local slug · Add as external link · End delegation |
| **C — renamed, slug taken** | Remote term found; new slug already belongs to another local term | Delegate that local term instead · Add as external link on this term · Add as external link on the other term · End delegation |

**Re-link and rename local slug** (outcome B): renames the local term's slug to the remote's new slug and resumes normal sync. The full sync runs immediately.

**Delegate that local term instead** (outcome C): removes delegation from the not-found term and sets up fresh delegation on the local term that already holds the new slug. Fetches remote data first; reports an error if the remote term moved again before the action completed.

**Add as external link** (outcomes B and C): removes delegation from the not-found term and stores the remote term as a peer link on the chosen local term. The remote slug does not need to match the local term's slug — the link is indexed by site, not slug.

## Scheduled refresh

TermConnect checks delegated terms twice daily, and refreshes those older than the **Shared Vocabularies** cache setting (one day by default). Use the **Re-sync All** button in **Settings → TermConnect → Cache** to refresh all delegated terms immediately. It updates terms that are already delegated, and doesn't add terms or change the hierarchy, which only a Shared Vocabulary sync does. On a large site it can stop at a time limit; click it again to continue.
