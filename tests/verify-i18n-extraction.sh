#!/usr/bin/env bash
# Compare the shipped source template and the complete gettext extraction.
set -euo pipefail

cd "$(dirname "$0")/.."
if (($# == 0)); then
	set -- wp
fi
grep -Eq '^[[:space:]]*\* Text Domain: cpt2collection[[:space:]]*$' cpt2collection.php
grep -Eq '^[[:space:]]*\* Domain Path: /languages[[:space:]]*$' cpt2collection.php
temp="$(mktemp -d)"
trap 'rm -rf "$temp"' EXIT

options=(
	--slug=cpt2collection
	--include=cpt2collection.php,uninstall.php,includes,assets/js
	--exclude=build,dist,tests,docs,tools,cpt2collection-7b-harness
	'--headers={"POT-Creation-Date":""}'
)
"$@" i18n make-pot . "$temp/domain.pot" --domain=cpt2collection "${options[@]}"
"$@" i18n make-pot . "$temp/all.pot" --ignore-domain "${options[@]}"

entries() { sed '/^"X-Generator:/d; /^"X-Domain:/d' "$1"; }
diff -u <(entries languages/cpt2collection.pot) <(entries "$temp/domain.pot")
diff -u <(entries "$temp/domain.pot") <(entries "$temp/all.pot")
echo 'PASS: source POT is current and full PHP/JavaScript extraction matches the plugin text domain'
