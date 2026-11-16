# Localization

## Overview

CPT2Collection uses WordPress's gettext-based translation system. Gettext
catalogs pair the English messages in the source code with translations.
The plugin's text domain is `cpt2collection`. WordPress uses that domain to
look up the plugin's messages separately from other plugins' messages.

English strings live in the plugin headers, PHP code, and scripts under
`assets/js/`. On `init`, the plugin registers its `languages/` directory with
`load_plugin_textdomain()`. WordPress can then load the compiled PHP catalog
for the active locale. JavaScript translations use separate JSON catalogs.

## Translation files

| Format | Location | Purpose |
| --- | --- | --- |
| POT | `languages/cpt2collection.pot` | Generated template of the English messages and source references. |
| PO | `languages/cpt2collection-it_IT.po` | Editable Italian catalog with translations, plural forms, and translator notes. |
| MO | `languages/cpt2collection-it_IT.mo` | Compiled catalog that WordPress uses for PHP translations. |
| Jed JSON | `languages/cpt2collection-it_IT-<hash>.json` | Generated WordPress JavaScript catalogs. There is one per translated script. |

Git tracks the template, Italian PO and MO, and all six Italian JSON
catalogs. The release build includes the template and Italian PO and copies
the matching `cpt2collection-*.mo` and `cpt2collection-*.json` files from
`languages/`. It packages those files as supplied. Catalog generation is a
separate step.

## Adding or updating translations

For Italian, edit `languages/cpt2collection-it_IT.po`. If the English
messages changed, regenerate the POT first and update the PO from that
template in a gettext editor. The `msgid` fields come from the source. Edit the
`msgstr` fields and review plural forms, context, and translator comments.

Keep placeholders such as `%s`, `%d`, and `%1$s` compatible with the source.
Preserve literal values that users must type or copy, such as shortcodes,
`{CHECKOUT_SESSION_ID}`, and `FULL COMMERCE RESET`. Review entries flagged as
fuzzy, which means the translation needs confirmation, and complete missing
translations before compiling. The Italian check also rejects obsolete
entries and HTML markup in translations.

To propose another locale, use the POT to create a new PO with the WordPress
locale in its name, following the Italian filename pattern. Set the language
and plural rules for that locale, then use the same compilation steps with
the new PO. Italian is the only translation currently shipped. The release
copy rules explicitly include the Italian PO and allow compiled catalogs
that match the patterns above. Including another locale's editable PO in the
ZIP would require a packaging change. The Italian verification script does
not validate other locales.

## Translating JavaScript

JavaScript uses `wp.i18n` with the same text domain. The plugin registers its
translated scripts with the `wp-i18n` dependency and calls
`wp_set_script_translations()` for each script handle, pointing WordPress to
`languages/`. The MO file does not supply these browser translations.

WP-CLI uses PO source references and `tools/wordpress-script-map.json` when
generating the per-script catalogs. The mapping is passed to `make-json` so
the generated catalogs can match the paths WordPress uses. The Italian
check verifies that each filename hash matches the script path in its JSON
`source` field and that the set covers the shipped scripts.

If you add, move, or rename a translated script, review its source references,
script registration, and map together. Regenerate the JSON catalogs so their
filenames match the paths. Edit the PO to change translations.

## Regenerating translation files

Work from the repository root. Use WP-CLI with the `i18n` commands, GNU
gettext for catalog checks, and Python 3 for the Italian verification
script. The repository has no single regeneration command.

When source messages change, extract them using the same options as the
template verification script.

```sh
wp i18n make-pot . languages/cpt2collection.pot \
  --domain=cpt2collection --slug=cpt2collection \
  --include=cpt2collection.php,uninstall.php,includes,assets/js \
  --exclude=build,dist,tests,docs,tools,cpt2collection-7b-harness \
  '--headers={"POT-Creation-Date":""}'
```

Update the PO against the refreshed template and review the translations.
Then generate the MO file for PHP translations and the JSON files for 
JavaScript translations in a temporary directory. This mirrors the
Italian verifier and keeps the generated files separate for review.

```sh
catalog_work="$(mktemp -d)"
cp languages/cpt2collection-it_IT.po "$catalog_work/"
wp i18n make-mo "$catalog_work"
wp i18n make-json "$catalog_work" --domain=cpt2collection --no-purge \
  --use-map=tools/wordpress-script-map.json
```

`--no-purge` keeps the JavaScript entries in the PO. Review the generated
MO and JSON files and copy them into `languages/`. If a script path changed,
check whether its old JSON catalog should be removed. Run the verification
scripts after updating the tracked files.

## Verifying translations

```sh
bash tests/verify-i18n-extraction.sh
bash tests/verify-italian-catalogs.sh
```

Both scripts default to `wp`. Supply the WP-CLI executable and any launcher
arguments as separate arguments if needed.

```sh
bash tests/verify-i18n-extraction.sh php /path/to/wp-cli.phar
bash tests/verify-italian-catalogs.sh php /path/to/wp-cli.phar
```

The extraction check compares the tracked POT with fresh extraction. It
also compares domain-filtered extraction with extraction that ignores the
domain, which helps catch strings using the wrong domain.

The Italian check uses GNU gettext's `msgcmp`, `msgfmt`, and `msgattrib` to
check coverage, formatting, and catalog status. It compares a freshly
compiled MO with the tracked MO and uses Python 3 to compare JSON content,
script paths, placeholders, and required literal tokens. It also checks the
expected message count and script coverage. Review those expectations when
the source messages or scripts change.

Both checks generate temporary files and compare them with the repository
catalogs. They leave the tracked files untouched.


## Using Loco Translate (optional)

You can also manage CPT2Collection translations directly from your WordPress dashboard using [Loco Translate](https://wordpress.org/plugins/loco-translate/).

Loco Translate provides a graphical editor, so you don't need to work with PO files or command-line tools.

To edit a translation:

1. Install and activate **Loco Translate**.
2. Open **Loco Translate → Plugins → CPT2Collection**.
3. Select an existing language or create a new translation.
4. Choose the **Custom** location to save your translation outside the CPT2Collection plugin directory.
5. Edit the messages and save your changes.

**Important:** Use the **Custom** location for site-specific translations. Files saved inside the CPT2Collection plugin directory may be replaced during plugin updates.

Loco Translate also supports JavaScript translations. After saving, check that your translated messages appear correctly in the relevant storefront and administration screens.

Compatibility with CPT2Collection's bundled JavaScript translations has been checked against the source files, but custom translation overrides have not yet been tested in a running WordPress installation.

If you're contributing translations to the CPT2Collection project, use the WP-CLI workflow described in this guide so the repository's PO, MO, and JSON files remain synchronized.

## Contributing translations

Include the updated PO and its generated MO and JSON catalogs in the pull
request. Include the regenerated POT when the source messages changed. If the
contribution changes script paths, include the corresponding map and catalog
changes. Report the verification checks you ran and their results.

See [Contributing](../CONTRIBUTING.md) for pull request guidance and
[Build and test](development.md) for package checks and native WordPress tests.
