#!/usr/bin/env bash
# Regenerate the translation template languages/termconnect.pot from source.
#
# Scans PHP, (source) JS, and block.json for translatable strings and writes a fresh .pot.
# Run this after adding or changing translatable strings, then:
#   msgmerge --update --backup=none languages/termconnect-ru_RU.po languages/termconnect.pot
#
# Usage: bash utils/make-pot.sh
# Requires GNU gettext (xgettext, msgcat) on PATH.

set -e

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_NAME="termconnect"
POT="${PLUGIN_ROOT}/languages/${PLUGIN_NAME}.pot"

cd "$PLUGIN_ROOT"

KW="--keyword=__ --keyword=_e --keyword=esc_html__ --keyword=esc_html_e \
--keyword=esc_attr__ --keyword=esc_attr_e --keyword=_x:1,2c --keyword=_ex:1,2c \
--keyword=esc_html_x:1,2c --keyword=esc_attr_x:1,2c --keyword=_n:1,2 \
--keyword=_nx:1,2,4c --keyword=_n_noop:1,2 --keyword=_nx_noop:1,2,3c"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# PHP strings.
find . -name '*.php' -not -path '*/node_modules/*' -not -path '*/vendor/*' \
  | sort | sed 's|^\./||' > "$TMP/php.list"
xgettext $KW -L PHP --from-code=UTF-8 --force-po -f "$TMP/php.list" \
  --package-name="$PLUGIN_NAME" --package-version="1.0.0" -o "$TMP/php.pot"

# JS strings — source only, never build/ output or minified files.
find . -name '*.js' -not -path '*/node_modules/*' -not -path '*/vendor/*' \
  -not -path '*/build/*' -not -name '*.min.js' | sort | sed 's|^\./||' > "$TMP/js.list"
xgettext $KW -L JavaScript --from-code=UTF-8 --force-po -f "$TMP/js.list" -o "$TMP/js.pot"

# block.json strings (title, description, keywords), with the contexts WordPress
# uses when translating block metadata. xgettext can't read block.json.
if python3 -c '' 2>/dev/null; then PYTHON=python3; else PYTHON=python; fi
"$PYTHON" - "$TMP/blocks.pot" <<'PY'
import io, json, pathlib, sys
entries = {}
for path in sorted(p.as_posix() for p in pathlib.Path('.').glob('blocks/*/block.json')):
    meta = json.load(io.open(path, encoding='utf-8'))
    items = [('block title', meta.get('title')), ('block description', meta.get('description'))]
    items += [('block keyword', kw) for kw in meta.get('keywords', [])]
    for ctx, text in items:
        if text:
            refs = entries.setdefault((ctx, text), [])
            if path not in refs:
                refs.append(path)
q = lambda s: json.dumps(s, ensure_ascii=False)
out = ['msgid ""', 'msgstr ""', '"Content-Type: text/plain; charset=UTF-8' + chr(92) + 'n"', '']
for (ctx, text), refs in entries.items():
    out += ['#: ' + ' '.join(refs), 'msgctxt ' + q(ctx), 'msgid ' + q(text), 'msgstr ""', '']
io.open(sys.argv[1], 'w', encoding='utf-8', newline='\n').write('\n'.join(out))
PY

# Merge (PHP wins on duplicate references) and fix the charset placeholder.
msgcat --use-first "$TMP/php.pot" "$TMP/js.pot" "$TMP/blocks.pot" -o "$POT"
sed -i 's/charset=CHARSET/charset=UTF-8/' "$POT"

echo "Wrote $POT"
