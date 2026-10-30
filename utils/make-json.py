# -*- coding: utf-8 -*-
# WP-CLI-free equivalent of `wp i18n make-json` for bundled block-editor translations.
# Generates languages/<domain>-<locale>-<handle>.json (Jed 1.x) for each editor script
# handle, containing the translations of the strings that script actually uses: those
# referenced from the source files its bundle includes (its entry file plus everything
# it imports through relative paths, e.g. shared components).
#
# Block scripts are discovered from blocks/*/block.json (`editorScript`); the entry is the
# block's src/index.js, or index.js when there is no src/ directory. Scripts that aren't
# blocks are passed as handle=path pairs in EXTRA_HANDLES (comma-separated), e.g.
#   EXTRA_HANDLES='sh-editor=assets/js/sh-editor.js'
#
# Usage: python make-json.py <plugin_root> <domain> [locale]
import io, re, os, json, sys, glob, datetime

CONTEXT_SEPARATOR = '\x04'  # Jed joins msgctxt and msgid with EOT.


def q(l):
    m = re.search(r'"(.*)"\s*$', l)
    return m.group(1) if m else ''


def unescape(s):
    # Single left-to-right pass: a sequential chain of .replace() calls would
    # consume a literal "\\n" (escaped backslash followed by a literal letter
    # n) as if it were "\n" (an escaped newline), corrupting the backslash.
    return re.sub(r'\\(.)', lambda m: {'n': '\n', 't': '\t', '"': '"', '\\': '\\'}.get(m.group(1), m.group(1)), s)


def parse_po(path):
    text = io.open(path, encoding='utf-8').read()
    entries = []
    for b in re.split(r'\n\n+', text):
        e = {'ctx': None, 'id': None, 'plural': None, 'strs': {}, 'refs': [], 'fuzzy': False}
        cur = None
        for l in b.split('\n'):
            if l.startswith('#:'):
                e['refs'] += l[2:].split()
            elif l.startswith('#,'):
                if 'fuzzy' in [f.strip() for f in l[2:].split(',')]:
                    e['fuzzy'] = True
            elif l.startswith('msgctxt '):
                e['ctx'] = q(l); cur = ('ctx',)
            elif l.startswith('msgid_plural '):
                e['plural'] = q(l); cur = ('plural',)
            elif l.startswith('msgid '):
                e['id'] = q(l); cur = ('id',)
            elif l.startswith('msgstr['):
                m = re.match(r'msgstr\[(\d+)\]\s+"(.*)"', l)
                if m:
                    idx = int(m.group(1)); e['strs'][idx] = m.group(2); cur = ('str', idx)
            elif l.startswith('msgstr '):
                e['strs'][0] = q(l); cur = ('str', 0)
            elif l.startswith('"') and cur:
                v = q(l)
                if cur[0] == 'ctx': e['ctx'] += v
                elif cur[0] == 'plural': e['plural'] += v
                elif cur[0] == 'id': e['id'] += v
                elif cur[0] == 'str': e['strs'][cur[1]] += v
        if e['id'] is not None:
            entries.append(e)
    return entries


IMPORT_RE = re.compile(
    r'''(?:import\s[^'"]*?from\s*|import\s*|export\s[^'"]*?from\s*|require\s*\(\s*)['"](\.{1,2}/[^'"]+)['"]''')


def resolve(base_dir, spec):
    """Resolve a relative import to a file path, or None."""
    path = os.path.normpath(os.path.join(base_dir, spec))
    for candidate in (path, path + '.js', path + '.jsx', os.path.join(path, 'index.js')):
        if os.path.isfile(candidate):
            return candidate
    return None


def bundle_sources(root, entry):
    """Plugin-relative paths of the entry file and every file it imports, transitively."""
    seen = set()
    stack = [entry]
    while stack:
        f = stack.pop()
        if f in seen:
            continue
        seen.add(f)
        src = io.open(f, encoding='utf-8').read()
        for spec in IMPORT_RE.findall(src):
            target = resolve(os.path.dirname(f), spec)
            if target and target.endswith(('.js', '.jsx')):
                stack.append(target)
    return {os.path.relpath(f, root).replace(os.sep, '/') for f in seen}


root, domain = sys.argv[1], sys.argv[2]
locale = sys.argv[3] if len(sys.argv) > 3 else 'ru_RU'
po = os.path.join(root, 'languages', '%s-%s.po' % (domain, locale))
entries = parse_po(po)

# Plural-Forms and PO-Revision-Date from the header.
plural_forms = 'nplurals=2; plural=(n != 1);'
po_revision = None
for e in entries:
    if e['id'] == '':
        header = unescape(e['strs'].get(0, ''))
        m = re.search(r'Plural-Forms:\s*([^\n]+)', header)
        if m: plural_forms = m.group(1).strip()
        m = re.search(r'PO-Revision-Date:\s*(\d{4}-\d{2}-\d{2} \d{2}:\d{2})\s*([+-]\d{4})', header)
        if m:
            po_revision = datetime.datetime.strptime(m.group(1) + m.group(2), '%Y-%m-%d %H:%M%z')
        break

# The JSON's translation-revision-date comes from the .po (not the current time), so
# regenerating unchanged translations produces byte-identical files. Falls back to the
# .po file's modification time when the header is missing or still the template placeholder.
if po_revision is None:
    po_revision = datetime.datetime.fromtimestamp(os.path.getmtime(po), datetime.timezone.utc)
revision = po_revision.astimezone(datetime.timezone.utc).strftime('%Y-%m-%d %H:%M:%S+0000')

# Reviewed, translated JS strings, each with the set of source files referencing it.
js_entries = []
for e in entries:
    if not e['id'] or e['fuzzy']:
        continue
    files = {r.rsplit(':', 1)[0] for r in e['refs'] if r.rsplit(':', 1)[0].endswith(('.js', '.jsx'))}
    if not files:
        continue
    key = (unescape(e['ctx']) + CONTEXT_SEPARATOR + unescape(e['id'])) if e['ctx'] else unescape(e['id'])
    if e['plural'] is not None:
        forms = [unescape(e['strs'].get(i, '')) for i in sorted(e['strs'])]
        if not any(forms): continue
        js_entries.append((files, key, forms))
    else:
        tr = unescape(e['strs'].get(0, ''))
        if tr == '': continue
        js_entries.append((files, key, [tr]))

# Editor script handles and the source files each one bundles.
scripts = []  # (handle, entry file)
for bj in sorted(glob.glob(os.path.join(root, 'blocks', '*', 'block.json'))):
    meta = json.load(io.open(bj, encoding='utf-8'))
    if not (meta.get('editorScript') and meta.get('name')):
        continue
    block_dir = os.path.dirname(bj)
    entry = os.path.join(block_dir, 'src', 'index.js')
    if not os.path.isfile(entry):
        entry = os.path.join(block_dir, 'index.js')
    if not os.path.isfile(entry):
        sys.exit('no entry file for %s' % bj)
    scripts.append((meta['name'].replace('/', '-') + '-editor-script', entry))

for pair in [p for p in os.environ.get('EXTRA_HANDLES', '').split(',') if p]:
    if '=' not in pair:
        sys.exit('EXTRA_HANDLES entries must be handle=path, got %r' % pair)
    handle, rel = pair.split('=', 1)
    entry = os.path.join(root, rel)
    if not os.path.isfile(entry):
        sys.exit('EXTRA_HANDLES file not found: %s' % rel)
    scripts.append((handle, entry))

for handle, entry in scripts:
    sources = bundle_sources(root, entry)
    messages = {'': {'domain': 'messages', 'lang': locale, 'plural-forms': plural_forms}}
    for files, key, value in js_entries:
        if files & sources:
            messages[key] = value
    data = {
        'translation-revision-date': revision,
        'generator': 'make-json.py',
        'domain': 'messages',
        'locale_data': {'messages': messages},
    }
    fn = os.path.join(root, 'languages', '%s-%s-%s.json' % (domain, locale, handle))
    io.open(fn, 'w', encoding='utf-8').write(json.dumps(data, ensure_ascii=False))
    print('  %s: %d strings from %d source file(s)' % (os.path.basename(fn), len(messages) - 1, len(sources)))
