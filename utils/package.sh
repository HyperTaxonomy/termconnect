#!/usr/bin/env bash
# Package TermConnect as a distribution zip.
# Rebuilds blocks if --build is passed; otherwise uses existing build output.
#
# Usage:
#   bash utils/package.sh           # use existing build
#   bash utils/package.sh --build   # rebuild blocks first

set -e

PLUGIN_SLUG="termconnect"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -W)"
DIST="$SRC/dist"
ZIP="$DIST/$PLUGIN_SLUG.zip"

if [ "${1}" = "--build" ]; then
    echo "Building blocks..."
    cd "$SRC"
    node_modules/.bin/wp-scripts build
    echo ""
fi

# ---------------------------------------------------------------------------
# Translations: compile .mo from every .po, and (re)generate the block-editor
# JSON (<domain>-<locale>-<handle>.json) so bundled translations stay in sync.
# Requires GNU gettext (msgfmt). No-op if there are no .po files.
# ---------------------------------------------------------------------------

echo "Compiling translations..."
for po in "$SRC"/languages/*-*.po; do
    [ -e "$po" ] || continue
    msgfmt "$po" -o "${po%.po}.mo"
    base="$(basename "$po" .po)"        # e.g. termconnect-ru_RU
    locale="${base#${PLUGIN_SLUG}-}"    # e.g. ru_RU
    python "$SRC/utils/make-json.py" "$SRC" "$PLUGIN_SLUG" "$locale"
done
echo ""

mkdir -p "$DIST"
rm -f "$ZIP"

echo "Creating zip..."

python -c "
import os, sys, zipfile, fnmatch

def parse_gitignore(path):
    rules = []
    try:
        with open(path, encoding='utf-8') as f:
            for line in f:
                line = line.rstrip('\n').rstrip('\r')
                if not line or line.startswith('#') or line.startswith('!'):
                    continue
                dir_only = line.endswith('/')
                if dir_only:
                    line = line[:-1]
                anchored = '/' in line
                rules.append((line, dir_only, anchored))
    except FileNotFoundError:
        pass
    return rules

def is_ignored(rel_path, is_dir, rules):
    name = rel_path.split('/')[-1]
    for pattern, dir_only, anchored in rules:
        if dir_only and not is_dir:
            continue
        target = rel_path if anchored else name
        if fnmatch.fnmatch(target, pattern):
            return True
    return False

src  = sys.argv[1]
dst  = sys.argv[2]
name = sys.argv[3]

rules = parse_gitignore(os.path.join(src, '.gitignore'))

# blocks/*/build/ is gitignored (builds not committed) but must be in the zip.
rules = [(p, d, a) for (p, d, a) in rules if p != 'blocks/*/build/']

# Package-only exclusions: committed to git but not for distribution.
rules += [
    ('webpack.config.js', False, False),
    ('package.json',      False, False),
    ('package-lock.json', False, False),
    ('src',               True,  False),
    ('shared',            True,  False),
    ('utils',             True,  False),
    ('.git',              True,  False),
    ('.github',           True,  False),
    ('.distignore',       False, False),
    ('.gitignore',        False, False),
    ('.wordpress-org',    True,  False),
]

count = 0
with zipfile.ZipFile(dst, 'w', zipfile.ZIP_DEFLATED) as zf:
    for root, dirs, files in os.walk(src):
        rel_dir = os.path.relpath(root, src).replace(os.sep, '/')
        if rel_dir == '.':
            rel_dir = ''
        dirs[:] = sorted(
            d for d in dirs
            if not is_ignored((rel_dir + '/' + d).lstrip('/'), True, rules)
        )
        for f in sorted(files):
            rel_file = (rel_dir + '/' + f).lstrip('/')
            if is_ignored(rel_file, False, rules):
                continue
            zf.write(os.path.join(root, f), name + '/' + rel_file)
            count += 1

print('%d files packaged.' % count)
" "$SRC" "$ZIP" "$PLUGIN_SLUG"

echo ""
echo "Done: $ZIP ($(du -sh "$ZIP" | cut -f1))"
echo ""
echo "Install: WordPress Admin → Plugins → Add New → Upload Plugin"
