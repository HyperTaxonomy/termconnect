---
title: File Structure & Loading
parent: Architecture
nav_order: 1
---

# File Structure & Loading

## File Structure

```
termconnect/
├── .distignore                                 # Files left out of the WordPress.org deploy
├── .gitignore                                  # Files left out of the repository
├── LICENSE                                     # GPLv2
├── package-lock.json                           # npm lockfile
├── package.json                                # npm dependencies for the block build
├── README.md                                   # Overview and documentation links
├── readme.txt                                  # WordPress.org readme
├── termconnect.php                             # Main plugin file: header, constants and bootstrap
├── uninstall.php                               # Uninstall handler
├── webpack.config.js                           # Webpack config for the blocks
├── .wordpress-org/                             # WordPress.org listing assets: icons, banners, screenshots (not in the zip)
├── assets/                                     
│   ├── css/                                    
│   │   ├── tc-peer-browse.css                  # Styles for [tc_peer_browse] — the interactive topic browser.
│   │   ├── tc-post-list.css                    # Shared base layout for TermConnect post lists.
│   │   └── tc-term-edit.css                    # TermConnect — Term Edit: External Links component
│   └── js/                                     
│       ├── tc-di-health.js                     # TermConnect — Site Health: delegated identity rename check and recovery actions
│       ├── tc-external-posts.js                # Load more button for [tc_peer_posts]
│       ├── tc-peer-browse.js                   # Interactive topic browser for [tc_peer_browse].
│       └── tc-term-edit.js                     # TermConnect — Term Edit: Delegated Identity inline UI
├── blocks/                                     
│   ├── peer-browse/                            
│   │   ├── block.json                          # Block metadata: attributes, scripts and styles
│   │   ├── build/                              # Compiled block assets (committed; the plugin works without npm)
│   │   └── src/                                
│   │       └── index.js                        # Block editor script (source, compiled into build/)
│   ├── peer-posts/                             
│   │   ├── block.json                          # Block metadata: attributes, scripts and styles
│   │   ├── build/                              # Compiled block assets (committed; the plugin works without npm)
│   │   └── src/                                
│   │       └── index.js                        # Block editor script (source, compiled into build/)
│   └── shared/                                 
│       ├── editor.css                          # TermConnect shared block editor styles
│       └── peer-checklist.js                   # Shared peer-picker for TermConnect blocks.
├── docs/                                       # This documentation (Just-the-Docs)
├── includes/                                   
│   ├── bootstrap.php                           # TermConnect bootstrap — loads all plugin files.
│   ├── admin/                                  
│   │   ├── class-admin.php                     # TermConnect admin — menu registration and admin-level hooks.
│   │   ├── delegated-identity-ajax.php         # Delegated Identity — AJAX handlers.
│   │   ├── delegated-identity-edit.php         # Delegated Identity — term edit/add screen field.
│   │   ├── delegated-identity-health.php       # Delegated Identity — WordPress Site Health integration.
│   │   ├── export-import.php                   # TermConnect Export / Import handlers.
│   │   ├── peer-sites-admin.php                # Peer Sites Admin — Terms → Peer Sites
│   │   ├── settings.php                        # TermConnect Settings page — Settings → TermConnect.
│   │   ├── shared-vocabularies-ajax.php        # Shared Vocabularies — AJAX handlers.
│   │   ├── shared-vocabularies.php             # Shared Vocabularies — admin page (Terms → Shared Vocabularies).
│   │   ├── shared-vocabulary-sync.php          # Shared Vocabulary Sync — sync a remote WordPress taxonomy into the local site.
│   │   ├── suggested-links-admin.php           # Suggested Links — admin UI
│   │   ├── term-edit.php                       # Term edit screen — External Links field.
│   │   └── term-list.php                       # Term list column, sortable, and filter for External Links.
│   ├── core/                                   
│   │   ├── delegated-identity.php              # Delegated Identity
│   │   ├── display.php                         # Frontend display — "See also" section on term archives and Gutenberg block render callbacks.
│   │   ├── external-posts.php                  # External Posts Platform
│   │   ├── feed-platform.php                   # Feed Platform
│   │   ├── peer-site-health.php                # Peer Site Health Tracking
│   │   ├── rest-api.php                        # TermConnect REST API.
│   │   ├── suggested-links.php                 # External Identifier Suggested Links
│   │   └── term-links.php                      # Term Links
│   ├── integration/                            
│   │   └── class-hypertaxonomy-integration.php # HyperTaxonomy integration for TermConnect.
│   └── shortcodes/                             
│       ├── class-external-post-list.php        # External Post List Shortcodes
│       └── class-peer-browse.php               # Interactive cross-network topic browser.
├── languages/                                  # Translation template and translations; .mo and .json are build output
└── utils/                                      # Packaging, translation and local deploy scripts (not in the zip)
```

## Loading Sequence

### 1. Main file (`termconnect.php`)

1. Define `TC_PLUGIN_DIR`, `TC_PLUGIN_URL`, `TC_VERSION` and `TC_PLUGIN_BASENAME`.
2. Register the activation and deactivation hooks.
3. Load translations on `init`.
4. Require `includes/bootstrap.php` on `plugins_loaded` at priority 20, so other plugins, including HyperTaxonomy, have loaded first.

### 2. Bootstrap (`includes/bootstrap.php`)

1. **Core, always:** `term-links.php`, `feed-platform.php`, `peer-site-health.php`, `external-posts.php`, `display.php`, `rest-api.php`, `delegated-identity.php` and `suggested-links.php`, from `includes/core/`.
2. **`includes/admin/settings.php`, always:** `TC_Settings::get_enabled_taxonomies()` is needed on the front end, in cron and in REST requests.
3. **`includes/admin/shared-vocabulary-sync.php`:** in admin, AJAX and cron requests only.
4. **The other admin files:** in admin and AJAX requests only, followed by `TC_Admin::register()`.
5. **`includes/integration/class-hypertaxonomy-integration.php`, always.** It hooks HyperTaxonomy's filters and actions, which do nothing while HyperTaxonomy is inactive.
6. **The shortcode classes:** `[tc_peer_posts]` and `[tc_peer_browse]`.

### Activation and deactivation

- **Activation** (`tc_plugin_activate()`) schedules `tc_refresh_identity_caches` (twice daily) and `tc_sync_shared_vocabularies` (hourly), removes stale entries from the shared vocabulary review queues, adds the `tc_link_reverse` index to the term meta table, and sets `tc_feed_reschedule` so feed polling is scheduled again on the next load.
- **Deactivation** (`tc_plugin_deactivate()`) unschedules both events and every `tc_feed_poll` event.
