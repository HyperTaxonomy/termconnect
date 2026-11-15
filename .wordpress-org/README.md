# wp.org assets

Icon, banner, and screenshot images for the WordPress.org plugin listing page.
These are **not** part of the plugin itself — they live in SVN's `assets/`
directory (a sibling of `trunk/`), never inside the shipped zip. The
`10up/action-wordpress-plugin-deploy` GitHub Action reads this folder directly
and publishes its contents to that SVN path; nothing here needs to be listed
in `.distignore` beyond the folder itself.

## Expected files

| File | Purpose |
|------|---------|
| `icon-128x128.png` | Plugin icon, standard resolution |
| `icon-256x256.png` | Plugin icon, retina |
| `icon.svg` | Plugin icon, scalable (optional; used in place of the PNGs when present) |
| `banner-772x250.png` | Plugin page banner, standard resolution |
| `banner-1544x500.png` | Plugin page banner, retina |
| `screenshot-1.png`, `screenshot-2.png`, ... | Screenshots, numbered to match the `== Screenshots ==` section in `readme.txt` (caption N in the readme corresponds to `screenshot-N.png`) |

No assets have been added yet for this plugin.
