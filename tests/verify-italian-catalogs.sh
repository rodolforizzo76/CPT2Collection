#!/usr/bin/env bash
# Verify coverage, format safety and reproducibility of the shipped Italian catalogs.
set -euo pipefail

cd "$(dirname "$0")/.."
if (($# == 0)); then
	set -- wp
fi
temp="$(mktemp -d)"
trap 'rm -rf "$temp"' EXIT

po=languages/cpt2collection-it_IT.po
pot=languages/cpt2collection.pot
msgcmp "$po" "$pot"
msgcmp --use-untranslated "$pot" "$po"
msgfmt --check --check-format --statistics -o "$temp/checked.mo" "$po"
test -z "$(msgattrib --only-fuzzy --no-obsolete "$po")"
test -z "$(msgattrib --untranslated --no-obsolete "$po")"
test -z "$(msgattrib --only-obsolete "$po")"

cp "$po" "$temp/"
"$@" i18n make-mo "$temp"
"$@" i18n make-json "$temp" --domain=cpt2collection --no-purge \
	--use-map=tools/wordpress-script-map.json
cmp "$temp/cpt2collection-it_IT.mo" languages/cpt2collection-it_IT.mo
python3 - "$temp" << 'PY'
from collections import Counter
from gettext import GNUTranslations
from hashlib import md5
from pathlib import Path
import json
import re
import sys

scratch = Path(sys.argv[1])
shipped = Path('languages')
scripts = {path.as_posix() for path in Path('assets/js').glob('*.js')}
compiled = set()
for artifact in scratch.glob('*.json'):
    catalog = json.loads(artifact.read_bytes())
    # Shared PHP/JS strings can move in extraction order without changing Jed content.
    assert catalog == json.loads((shipped / artifact.name).read_bytes()), artifact.name
    source = catalog['source']
    digest = md5(source.encode()).hexdigest()
    assert artifact.name == f'cpt2collection-it_IT-{digest}.json'
    assert catalog['locale_data']['messages']['']['lang'] == 'it_IT'
    compiled.add(source)
assert compiled == scripts
assert {p.name for p in shipped.glob('cpt2collection-it_IT-*.json')} == {p.name for p in scratch.glob('*.json')}

with (scratch / 'checked.mo').open('rb') as stream:
    mo = GNUTranslations(stream)
keys = set()
for entry, translation in mo._catalog.items():
    if not entry:
        continue
    key = entry[0] if isinstance(entry, tuple) else entry
    keys.add(key)
    source = key.rsplit('\x04', 1)[-1]
    placeholders = r'%(?:[1-9][0-9]*\$)?[ds]'
    assert Counter(re.findall(placeholders, source)) == Counter(re.findall(placeholders, translation)), key
    assert not re.search(r'<[^>]*>|&(?:#\d+|#x[\da-f]+|lt|gt);', translation, re.I), key
    for token in ('post_id', 'order_id', 'sk_test_', 'sk_live_', 'whsec_', '{CHECKOUT_SESSION_ID}',
                  '[cpt2collection_cart]', 'YYYY-MM-DD', 'FULL COMMERCE RESET', 'DELETE'):
        if token in source:
            assert token in translation, (key, token)
assert len(keys) == 503, len(keys)
print('PASS: 503 Italian entries, complete and safe formats, reproducible MO and six per-source Jed catalogs')
PY
