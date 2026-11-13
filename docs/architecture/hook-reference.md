---
title: Hook Reference
parent: Architecture
nav_order: 3
---

# Hook Reference
{: .no_toc }

The hook registrations in TermConnect that you can't guess from a callback's name: load order, priorities chosen to run before or after other code, hooks registered per taxonomy, and the HyperTaxonomy hooks TermConnect uses. Routine registrations (menus, scripts, AJAX and REST handlers, shortcodes, notices) are left out; search the code for `add_action( 'hook_name'` to find any of them.

The hooks TermConnect fires for other code, and the HyperTaxonomy hooks it answers, are documented in the Developer Reference: [Filters](../developer-reference/filters.html) and the actions in the [Term Links API](../developer-reference/term-links-api.html#actions). Cron events are listed in [Data Storage](data-storage.html).

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Loading

`termconnect.php` loads `includes/bootstrap.php` on `plugins_loaded` at priority 20. Every plugin's main file has run by then, and HyperTaxonomy loads directly from its main file, so its functions are available. The HyperTaxonomy integration (`TC_HT_Integration::init()`) always registers its hooks; without HyperTaxonomy its `htax_*` hooks simply never fire.

## Term edit and add forms

Fields are registered for each enabled taxonomy on `init` at priority 15:

| Hook | Callback | Priority |
|---|---|---|
| `{$taxonomy}_edit_form_fields` | `tc_add_delegated_identity_field` | 9 |
| `{$taxonomy}_edit_form_fields`, `{$taxonomy}_add_form_fields` | `tc_add_external_links_field` | 9 |

HyperTaxonomy puts its Term Groups field at priority 8 and opens its collapsible section at priority 9. HyperTaxonomy registers its fields later than TermConnect (on `init` at priority 999), and callbacks at the same priority run in the order they were added, so TermConnect's panels come after Term Groups and before the collapsible.

## Term list columns

Registered for each enabled taxonomy, on both the term list and the term edit screen (they share Screen Options):

| Hook | Callback | Priority |
|---|---|---|
| `manage_edit-{$taxonomy}_columns`, `…_sortable_columns`, `manage_{$taxonomy}_custom_column` | External Links column | 99 |
| the same three | Identity Issues column | 100 |

The high priorities put both columns after the columns of HyperTaxonomy and other plugins, with External Links first. Sorting and filtering by these columns use `terms_clauses` and `parse_term_query` at the default priority.

## Archive descriptions

| Hook | Callback | Priority | Why |
|---|---|---|---|
| `htax_archive_description_append` | "See also" links | 10 | Inside HyperTaxonomy's archive description block |
| `htax_archive_description_append` | Suggest-a-link form | 20 | After "See also" |
| `get_the_archive_description` | "See also" links | 10 | Without HyperTaxonomy |
| `get_the_archive_description` | `tc_append_suggested_link_form` | 20 | Without HyperTaxonomy; after "See also" |

## Suggested links in the Comments screens

Suggested links are stored as comments of type `tc_suggested_link`.

| Hook | Callback | Priority | Why |
|---|---|---|---|
| `pre_get_comments` | keep suggestions out of the normal comment lists | 10 | |
| `comments_clauses` | the same, by `WHERE` | 10 | WordPress ignores unknown comment types in its own type filtering |
| `pre_get_comments` | remember a sort by term name | 1 | Runs before `WP_Comment_Query` rejects the unknown `orderby` value |
| `comments_clauses` | join terms and sort by name | 20 | Runs after the `WHERE` change above |
| `admin_menu` | Suggested Links submenu under Comments | 20 | |
| `admin_menu` | pending-count badge on **All Comments** | 30 | After the submenu exists |

## Admin menu

`TC_Admin::register_menus` runs on `admin_menu` at priority 15, after HyperTaxonomy adds its **Terms** menu at the default priority, so **Peer Sites** can go under it. Without HyperTaxonomy, it uses a menu of its own.

## Term saves and deletes

| Hook | Callback | Priority | Why |
|---|---|---|---|
| `saved_term` | `tc_save_term_links()` | 20 | Saves the External Links field for any term create or update |
| `delete_term_taxonomy` | `TC_HT_Integration::clean_sv_source_on_delete` | 5 | Runs inside `wp_delete_term()` before term meta is deleted, so the term's Shared Vocabulary source is still readable; also covers bulk deletes |
| `delete_term_taxonomy`, `deleted_term` | Shared Vocabulary queue cleanup | 10 | |
| `delete_term_taxonomy`, `deleted_term` | Link Suggestions cleanup | 10 | |

## HyperTaxonomy hooks used

Registered by `TC_HT_Integration::init()`. Each is described in the Developer Reference [Filters](../developer-reference/filters.html) page.

| Hook | What TermConnect does |
|---|---|
| `htax_term_uris` | Provides each term's external identifier URIs |
| `htax_term_external_posts` | Provides peer posts for a term |
| `htax_peer_sites` | Provides the peer-site registry |
| `htax_before_merge_terms`, `htax_after_merge_terms` | Carries external links over to the surviving term in a merge |
| `htax_sync_remote_relationship_types` | Fetches relationship types from peer sites |
| `htax_relationship_types_admin_footer` | Adds peer relationship type discovery to **Terms > Term Relationship Types** |
| `htax_archive_description_append` | See [Archive descriptions](#archive-descriptions) |
