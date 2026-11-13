---
title: Hook Reference
parent: Architecture
nav_order: 3
---

# Hook Reference
{: .no_toc }

Where TermConnect registers its callbacks, and the hooks it fires for other code. This page is generated from the code. Hook names that are built at runtime are shown as the PHP expression that builds them. For how to use the hooks TermConnect fires, see the [Developer Reference](../developer-reference/).

## Table of contents
{: .no_toc .text-delta }

1. TOC
{:toc}

---

## Callbacks registered

### Plugin root

**`termconnect.php`**

| Hook | Callback | Priority |
|---|---|---|
| `init` | `*(anonymous function)*` | 10 |
| `plugins_loaded` | `*(anonymous function)*` | 20 |

### Core

**`includes/core/delegated-identity.php`**

| Hook | Callback | Priority |
|---|---|---|
| `delete_term` | `*(anonymous function)*` | 10 |
| `tc_refresh_identity_caches` | `tc_cron_refresh_identity_caches` | 10 |

**`includes/core/display.php`**

| Hook | Callback | Priority |
|---|---|---|
| `htax_archive_description_append` | `*(anonymous function)*` | 10 |
| `get_the_archive_description` | `*(anonymous function)*` | 10 |
| `init` | `*(anonymous function)*` | 10 |
| `block_categories_all` | `*(anonymous function)*` | 10 |

**`includes/core/external-posts.php`**

| Hook | Callback | Priority |
|---|---|---|
| `http_request_host_is_external` | `*(anonymous function)*` | 10 |

**`includes/core/feed-platform.php`**

| Hook | Callback | Priority |
|---|---|---|
| `tc_feed_poll` | `tc_feed_do_poll` | 10 |
| `init` | `tc_feed_reschedule_after_activation` | 10 |
| `cron_schedules` | `tc_feed_cron_schedules` | 10 |

**`includes/core/peer-site-health.php`**

| Hook | Callback | Priority |
|---|---|---|
| `site_status_tests` | `*(anonymous function)*` | 10 |

**`includes/core/rest-api.php`**

| Hook | Callback | Priority |
|---|---|---|
| `rest_api_init` | `tc_register_tc_links_field` | 10 |
| `rest_prepare_taxonomy` | `*(anonymous function)*` | 10 |
| `rest_api_init` | `*(anonymous function)*` | 10 |
| `"rest_{$taxonomy}_query"` | `tc_rest_link_filter_query` | 10 |
| `"rest_{$taxonomy}_collection_params"` | `tc_rest_link_filter_collection_params` | 10 |
| `rest_post_dispatch` | `*(anonymous function)*` | 10 |
| `rest_api_init` | `*(anonymous function)*` | 10 |

**`includes/core/suggested-links.php`**

| Hook | Callback | Priority |
|---|---|---|
| `wp_ajax_nopriv_tc_suggested_link_submit` | `tc_ajax_suggested_link_submit` | 10 |
| `wp_ajax_tc_suggested_link_submit` | `tc_ajax_suggested_link_submit` | 10 |
| `notify_moderator` | `*(anonymous function)*` | 10 |
| `deleted_term` | `*(anonymous function)*` | 10 |
| `delete_term_taxonomy` | `*(anonymous function)*` | 10 |
| `get_the_archive_description` | `tc_append_suggested_link_form` | 20 |
| `htax_archive_description_append` | `*(anonymous function)*` | 20 |

**`includes/core/term-links.php`**

| Hook | Callback | Priority |
|---|---|---|
| `saved_term` | `*(anonymous function)*` | 20 |

### Admin

**`includes/admin/class-admin.php`**

| Hook | Callback | Priority |
|---|---|---|
| `admin_menu` | `TC_Admin::register_menus` | 15 |
| `'plugin_action_links_' . TC_PLUGIN_BASENAME` | `TC_Admin::add_settings_link` | 10 |

**`includes/admin/delegated-identity-ajax.php`**

| Hook | Callback | Priority |
|---|---|---|
| `wp_ajax_tc_di_preview_term` | `tc_ajax_di_preview_term` | 10 |
| `wp_ajax_tc_di_set_source` | `tc_ajax_di_set_source` | 10 |
| `wp_ajax_tc_di_remove` | `tc_ajax_di_remove` | 10 |
| `wp_ajax_tc_di_refresh` | `tc_ajax_di_refresh` | 10 |
| `wp_ajax_tc_di_resync_all` | `tc_ajax_di_resync_all` | 10 |
| `wp_ajax_tc_di_update_settings` | `tc_ajax_di_update_settings` | 10 |
| `wp_ajax_tc_di_check_rename` | `tc_ajax_di_check_rename` | 10 |
| `wp_ajax_tc_di_relink_rename` | `tc_ajax_di_relink_rename` | 10 |
| `wp_ajax_tc_di_add_link` | `tc_ajax_di_add_link` | 10 |
| `wp_ajax_tc_di_redelegate` | `tc_ajax_di_redelegate` | 10 |
| `wp_ajax_tc_di_follow_redirect` | `tc_ajax_di_follow_redirect` | 10 |
| `wp_ajax_tc_di_decline_redirect` | `tc_ajax_di_decline_redirect` | 10 |

**`includes/admin/delegated-identity-edit.php`**

| Hook | Callback | Priority |
|---|---|---|
| `wp_update_term_data` | `*(anonymous function)*` | 10 |

**`includes/admin/delegated-identity-health.php`**

| Hook | Callback | Priority |
|---|---|---|
| `admin_enqueue_scripts` | `*(anonymous function)*` | 10 |
| `site_status_tests` | `*(anonymous function)*` | 10 |

**`includes/admin/export-import.php`**

| Hook | Callback | Priority |
|---|---|---|
| `admin_post_tc_export_links` | `tc_handle_export_links` | 10 |
| `admin_post_tc_import_links` | `tc_handle_import_links` | 10 |

**`includes/admin/peer-sites-admin.php`**

| Hook | Callback | Priority |
|---|---|---|
| `wp_ajax_tc_peer_site_count_ineligible` | `tc_ajax_peer_site_count_ineligible` | 10 |
| `wp_ajax_tc_clear_peer_cache` | `tc_ajax_clear_peer_cache` | 10 |
| `wp_ajax_tc_clear_all_peer_cache` | `tc_ajax_clear_all_peer_cache` | 10 |
| `wp_ajax_tc_feed_categories` | `tc_ajax_feed_categories` | 10 |
| `wp_ajax_tc_peer_site_identifier_suggest` | `tc_ajax_peer_site_identifier_suggest` | 10 |
| `wp_ajax_tc_wp_peer_suggest` | `tc_ajax_wp_peer_suggest` | 10 |
| `wp_ajax_tc_wp_peer_author_suggest` | `tc_ajax_wp_peer_author_suggest` | 10 |
| `wp_ajax_tc_poll_feed_now` | `tc_ajax_poll_feed_now` | 10 |
| `wp_ajax_tc_clear_foaf_cache` | `tc_ajax_clear_foaf_cache` | 10 |
| `admin_post_tc_peer_site_action` | `tc_handle_peer_site_action` | 10 |
| `admin_enqueue_scripts` | `tc_enqueue_peer_sites_assets` | 10 |
| `wp_ajax_tc_peer_sites_reorder` | `tc_ajax_peer_sites_reorder` | 10 |

**`includes/admin/settings.php`**

| Hook | Callback | Priority |
|---|---|---|
| `admin_init` | `TC_Settings::handle_save` | 10 |

**`includes/admin/shared-vocabularies-ajax.php`**

| Hook | Callback | Priority |
|---|---|---|
| `wp_ajax_tc_sv_save` | `tc_sv_ajax_save` | 10 |
| `wp_ajax_tc_sv_delete` | `tc_sv_ajax_delete` | 10 |
| `wp_ajax_tc_sv_sync` | `tc_sv_ajax_sync` | 10 |
| `wp_ajax_tc_sv_reorder` | `tc_sv_ajax_reorder` | 10 |
| `wp_ajax_tc_sv_merge_proposal` | `tc_sv_ajax_merge_proposal` | 10 |
| `wp_ajax_tc_sv_dismiss_merge` | `tc_sv_ajax_dismiss_merge` | 10 |
| `wp_ajax_tc_sv_fetch_remote_taxonomies` | `tc_sv_ajax_fetch_remote_taxonomies` | 10 |
| `wp_ajax_tc_sv_fetch_remote_groups` | `tc_sv_ajax_fetch_remote_groups` | 10 |
| `wp_ajax_tc_sv_search_remote_terms` | `tc_sv_ajax_search_remote_terms` | 10 |
| `wp_ajax_tc_sv_fetch_local_groups` | `tc_sv_ajax_fetch_local_groups` | 10 |

**`includes/admin/shared-vocabulary-sync.php`**

| Hook | Callback | Priority |
|---|---|---|
| `tc_sync_shared_vocabularies` | `tc_cron_sync_shared_vocabularies` | 10 |

**`includes/admin/suggested-links-admin.php`**

| Hook | Callback | Priority |
|---|---|---|
| `map_meta_cap` | `*(anonymous function)*` | 10 |
| `list_table_primary_column` | `*(anonymous function)*` | 10 |
| `pre_get_comments` | `*(anonymous function)*` | 10 |
| `comments_clauses` | `*(anonymous function)*` | 10 |
| `views_edit-comments` | `*(anonymous function)*` | 10 |
| `bulk_actions-edit-comments` | `*(anonymous function)*` | 10 |
| `admin_menu` | `*(anonymous function)*` | 20 |
| `admin_menu` | `*(anonymous function)*` | 30 |
| `manage_edit-comments_columns` | `*(anonymous function)*` | 10 |
| `manage_comments_custom_column` | `*(anonymous function)*` | 10 |
| `comment_row_actions` | `*(anonymous function)*` | 10 |
| `comment_unapproved_to_approved` | `*(anonymous function)*` | 10 |
| `manage_edit-comments_sortable_columns` | `*(anonymous function)*` | 10 |
| `pre_get_comments` | `*(anonymous function)*` | 1 |
| `comments_clauses` | `*(anonymous function)*` | 20 |
| `admin_head` | `*(anonymous function)*` | 10 |

**`includes/admin/term-edit.php`**

| Hook | Callback | Priority |
|---|---|---|
| `admin_enqueue_scripts` | `tc_enqueue_term_edit_css` | 10 |
| `init` | `*(anonymous function)*` | 15 |
| `"{$taxonomy}_edit_form_fields"` | `tc_add_delegated_identity_field` | 9 |
| `"{$taxonomy}_add_form_fields"` | `tc_add_external_links_field` | 9 |
| `"{$taxonomy}_edit_form_fields"` | `tc_add_external_links_field` | 9 |
| `admin_footer-edit-tags.php` | `tc_quick_edit_delegation_script` | 10 |

**`includes/admin/term-list.php`**

| Hook | Callback | Priority |
|---|---|---|
| `current_screen` | `*(anonymous function)*` | 10 |
| `"manage_edit-{$taxonomy}_columns"` | `tc_add_ext_links_column` | 99 |
| `"manage_edit-{$taxonomy}_sortable_columns"` | `tc_make_ext_links_column_sortable` | 99 |
| `"manage_edit-{$taxonomy}_columns"` | `tc_add_di_issues_column` | 100 |
| `"manage_edit-{$taxonomy}_sortable_columns"` | `tc_make_di_issues_column_sortable` | 100 |
| `default_hidden_columns` | `*(anonymous function)*` | 10 |
| `"manage_{$taxonomy}_custom_column"` | `tc_render_ext_links_column` | 99 |
| `"manage_{$taxonomy}_custom_column"` | `tc_render_di_issues_column` | 100 |
| `terms_clauses` | `tc_sort_terms_by_ext_links` | 10 |
| `terms_clauses` | `tc_sort_terms_by_di_issues` | 10 |
| `parse_term_query` | `tc_apply_ext_links_filter` | 10 |
| `parse_term_query` | `tc_apply_di_issues_filter` | 10 |
| `admin_footer` | `tc_ext_links_filter_sync_script` | 10 |
| `admin_head` | `*(anonymous function)*` | 10 |

### Integrations

**`includes/integration/class-hypertaxonomy-integration.php`**

| Hook | Callback | Priority |
|---|---|---|
| `htax_term_uris` | `TC_HT_Integration::provide_term_uris` | 10 |
| `htax_term_external_posts` | `TC_HT_Integration::provide_term_external_posts` | 10 |
| `htax_peer_sites` | `TC_HT_Integration::provide_peer_sites` | 10 |
| `rest_api_init` | `TC_HT_Integration::register_rest_fields` | 10 |
| `htax_relationship_types_admin_footer` | `TC_HT_Integration::render_rel_type_discovery` | 10 |
| `wp_ajax_tc_discover_rel_types` | `TC_HT_Integration::ajax_discover_rel_types` | 10 |
| `htax_sync_remote_relationship_types` | `TC_HT_Integration::sync_remote_relationship_types` | 10 |
| `htax_before_merge_terms` | `TC_HT_Integration::snapshot_links_before_merge` | 10 |
| `htax_after_merge_terms` | `TC_HT_Integration::apply_links_after_merge` | 10 |
| `deleted_term` | `TC_HT_Integration::clean_sv_queues_on_deleted` | 10 |
| `delete_term_taxonomy` | `TC_HT_Integration::clean_sv_source_on_delete` | 5 |
| `delete_term_taxonomy` | `TC_HT_Integration::clean_sv_queues_on_bulk_deleted` | 10 |

### Shortcodes

**`includes/shortcodes/class-external-post-list.php`**

| Hook | Callback | Priority |
|---|---|---|
| `wp_ajax_tc_external_load_more` | `TC_Shortcode_PeerPosts->ajax_load_more` | 10 |
| `wp_ajax_nopriv_tc_external_load_more` | `TC_Shortcode_PeerPosts->ajax_load_more` | 10 |

**`includes/shortcodes/class-peer-browse.php`**

| Hook | Callback | Priority |
|---|---|---|
| `wp_ajax_tc_peer_browse` | `TC_Shortcode_PeerBrowse->ajax_browse` | 10 |
| `wp_ajax_nopriv_tc_peer_browse` | `TC_Shortcode_PeerBrowse->ajax_browse` | 10 |
| `wp_ajax_tc_peer_browse_terms` | `TC_Shortcode_PeerBrowse->ajax_term_suggest` | 10 |
| `wp_ajax_nopriv_tc_peer_browse_terms` | `TC_Shortcode_PeerBrowse->ajax_term_suggest` | 10 |

## Hooks fired

Actions and filters TermConnect fires for other code to use.

| Hook | Type | File | Description |
|---|---|---|---|
| `tc_enabled_taxonomies` | filter | `includes/admin/settings.php` | Filters the list of TermConnect-enabled taxonomies. |
| `tc_term_links_see_also_html` | filter | `includes/core/display.php` | — |
| `tc_link_types` | filter | `includes/core/term-links.php` | — |
| `tc_taxonomy_peer_authors` | filter | `includes/core/term-links.php` | Filters whether a taxonomy maps WordPress-peer links to authors. |
| `tc_link_uri` | filter | `includes/core/term-links.php` | Filters the constructed URI for a link type + identifier pair. |
| `tc_suggest_config` | filter | `includes/core/term-links.php` | Filters the suggest configuration for a link type with no built-in config. |
| `tc_parse_suggest_response` | filter | `includes/core/term-links.php` | Filters parsed suggest results, enabling custom parser ids. |
| `tc_term_uris_changed` | action | `includes/core/term-links.php` | — |
| `tc_link_value` | filter | `includes/core/term-links.php` | Filters a link identifier before it is stored, for normalization/validation. |
| `tc_index_extra_uris` | filter | `includes/core/term-links.php` | Filters the URIs indexed for a term before they are written. |
