# -*- coding: utf-8 -*-
# WP-CLI-free equivalent of `wp i18n make-json` for bundled block-editor translations.
# Generates languages/<domain>-<locale>-<handle>.json (Jed 1.x) for each block editor
# script handle, containing the translations of every JS-referenced string in the .po.
#
# Usage: python make_json.py <plugin_root> <domain> [locale]
import io, re, os, json, sys, glob, datetime

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

root, domain = sys.argv[1], sys.argv[2]
locale = sys.argv[3] if len(sys.argv) > 3 else 'ru_RU'
po = os.path.join(root, 'languages', '%s-%s.po' % (domain, locale))
entries = parse_po(po)

# Plural-Forms from header.
plural_forms = 'nplurals=2; plural=(n != 1);'
for e in entries:
    if e['id'] == '':
        m = re.search(r'Plural-Forms:\s*([^\n]+)', unescape(e['strs'].get(0, '')))
        if m: plural_forms = m.group(1).strip()
        break

# Collect JS-referenced translated entries into a Jed messages dict.
messages = {'': {'domain': 'messages', 'lang': locale, 'plural-forms': plural_forms}}
for e in entries:
    if not e['id']:
        continue
    if e['fuzzy']:
        continue
    if not any(r.split(':')[0].endswith('.js') for r in e['refs']):
        continue
    key = (unescape(e['ctx']) + '' + unescape(e['id'])) if e['ctx'] else unescape(e['id'])
    if e['plural'] is not None:
        forms = [unescape(e['strs'].get(i, '')) for i in sorted(e['strs'])]
        if not any(forms): continue
        messages[key] = forms
    else:
        tr = unescape(e['strs'].get(0, ''))
        if tr == '': continue
        messages[key] = [tr]

data = {
    'translation-revision-date': datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%d %H:%M:%S+0000'),
    'generator': 'make-json.py',
    'domain': 'messages',
    'locale_data': {'messages': messages},
}

# Discover block editor-script handles from blocks/*/block.json.
handles = []
for bj in glob.glob(os.path.join(root, 'blocks', '*', 'block.json')):
    meta = json.load(io.open(bj, encoding='utf-8'))
    if meta.get('editorScript') and meta.get('name'):
        handles.append(meta['name'].replace('/', '-') + '-editor-script')

# Allow extra non-block handles via env (comma-separated).
extra = os.environ.get('EXTRA_HANDLES', '')
handles += [h for h in extra.split(',') if h]

n_strings = len(messages) - 1
written = []
for h in handles:
    fn = os.path.join(root, 'languages', '%s-%s-%s.json' % (domain, locale, h))
    io.open(fn, 'w', encoding='utf-8').write(json.dumps(data, ensure_ascii=False))
    written.append(os.path.basename(fn))

print('JS strings: %d | handles: %d' % (n_strings, len(handles)))
for w in written:
    print('  ' + w)
