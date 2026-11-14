# Build and test

Use Linux with Bash, PHP 8.5, Composer, rsync, and ZIP utilities to build.
The current tools lock includes a dependency that requires PHP 8.4.1 or later.
PHP 8.5.12 was used to build and test the ZIP. The plugin declares WordPress 6.4
and PHP 8.2 as runtime minimums. Native package checks used WordPress 6.9.4
and PHP 8.5.12. Static PHP 8.2 parsing was checked separately. The declared
minimum WordPress and PHP combination has yet to be verified in a native run.
Install the locked tools, then build from the source root.

```sh
composer install --working-dir=tools
composer build:release
composer verify:release
composer verify:release-sync
```

The build installs locked runtime dependencies in a scratch tree, scopes
Stripe, regenerates its Composer loader, and writes `dist/cpt2collection.zip`.
The ZIP contains one `cpt2collection/` directory. Compare sorted payload paths
and SHA-256 digests to verify repeated builds. Archive metadata can vary.
Generated vendor, build and distribution directories are local outputs.

Use `composer install` for source checks requiring Stripe. Run individual
`tests/verify-*.php` scripts with PHP and the frontend checks with Node.js
(for example `node tests/verify-stock-quick-edit.js`). The post-checkout JS
test takes its fixture from `php tests/verify-post-checkout-frontend.php`.
Some PHP suites need an empty disposable MariaDB/InnoDB database and
`mysqli`; forked suites also need `pcntl`. Set `C2C_TEST_DB_NAME`,
`C2C_TEST_DB_HOST`, `C2C_TEST_DB_PORT`, `C2C_TEST_DB_USER`, and optionally
`C2C_TEST_DB_PASSWORD`. Use separate databases per test, with a local-only
server and a fresh unconfigured WordPress copy for tests using
`C2C_TEST_WORDPRESS_PATH`. Never supply production data or credentials.

For native coverage, use the Python 3 drivers
`tests/verify-commerce-reset-native-wordpress.py` and
`tests/verify-payment-notification-native.py`. See each driver's `--help`
for its disposable database socket, clean WordPress core fixture, and
extracted package inputs. Run the reset REST driver for both pretty and
plain permalinks. The notification driver captures mail. The PHP compatibility
checker takes the extracted plugin path and uses the parser from the tools
install. See [localization](localization.md) for translation checks.

Some older standalone suites model pre-baseline schemas and still have
known fixture or assertion failures. Treat skipped checks and missing
prerequisites as coverage gaps. Use the current-schema tests and native
drivers to verify commerce behavior.
