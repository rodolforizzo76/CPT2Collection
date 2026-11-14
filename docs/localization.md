# Localization

The text domain is `cpt2collection`. English messages live in PHP and
JavaScript source. The POT is the template and `cpt2collection-it_IT.po` is
the Italian translation source. The tracked MO and Jed JSON files are compiled
catalogs consumed by the release build.

Use WP-CLI i18n, GNU gettext and Python 3 to verify extraction and regenerate
catalogs. Run `bash tests/verify-i18n-extraction.sh` and
`bash tests/verify-italian-catalogs.sh`. Both accept a WP-CLI command as
arguments. Consult the scripts for the exact extraction options and keep
`tools/wordpress-script-map.json` synchronized with the JavaScript files.
