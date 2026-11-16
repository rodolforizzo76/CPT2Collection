# Build and test

## Overview

Use this guide to build the CPT2Collection 1.0.0 release from source and
choose checks for the code you changed. Run the commands from the repository
root unless a command specifies another directory.

For a normal WordPress installation, use the attached `cpt2collection.zip`
from the matching release. It already includes the runtime dependencies.
GitHub's generated source archives need a build to produce that package.
See [Installation and upgrades](installation.md).

## Requirements

| Task | Requirements |
| --- | --- |
| Run the plugin | WordPress 6.4 or later and PHP 8.2 or later are the declared minimums. Checkout requires HTTPS, MySQL or MariaDB with InnoDB, and the Stripe SDK's PHP extensions `curl`, `json`, and `mbstring`. |
| Build the ZIP | Use Linux with Bash, PHP CLI, Composer 2, rsync, and `zip`. Make ZIP extraction available to Composer through `unzip` or PHP's ZIP extension. |
| Verify artifact synchronization | The shell check also uses `rg`, GNU core utilities, and `sha256sum`. |
| Run source tests | Use PHP CLI for the PHP checks and Node.js for the JavaScript checks. Some PHP tests also launch Node.js. The repository does not pin a Node.js version. |
| Run database or native tests | Use a disposable MariaDB/InnoDB instance. Install PHP `mysqli` for database tests and `pcntl` for tests that fork. The native drivers use Python 3, the `mariadb` client, and PHP CLI. |

The build was exercised with PHP 8.5.12. PHP-Scoper itself declares PHP
`^8.2`, but the locked tools include `symfony/string`, which requires PHP
8.4.1 or later. The locked build tools require PHP 8.4.1 or later. PHP 8.5.12 
was used for the verified release build, so PHP 8.5 is recommended for 
reproducing that environment. The tools also require PHP extensions such 
as `tokenizer`, `ctype`, and `iconv`. The package locks are the reference 
for dependency requirements.

## Build the release ZIP

```sh
composer install --working-dir=tools
composer build:release
```

The first command installs PHP-Scoper and its locked dependencies under
`tools/vendor/`. The second runs `tools/build-release.sh`. It stages the
production files and installs the locked runtime dependencies without
development packages in `build/release/`.

PHP-Scoper moves the bundled Stripe SDK under `CPT2CollectionVendor\Stripe`
and updates the plugin's references. This isolates the SDK from other copies
of Stripe loaded by WordPress plugins. The build then regenerates Composer's
autoload files for the scoped namespace and adds the release loader that
the plugin entry point uses.

The script verifies the scoped files, synchronizes them to
`dist/cpt2collection/`, and verifies the resulting directory before writing
`dist/cpt2collection.zip`. The archive has one top-level `cpt2collection/`
directory. The build replaces its scratch directory and the previous ZIP.
Tests and build tools stay in the repository. The generated `vendor/`,
`tools/vendor/`, `build/`, and `dist/` directories and ZIP archives are
ignored by Git.

## Verify the release

```sh
composer verify:release
composer verify:release-sync
```

- `verify:release` runs the artifact checker on `dist/cpt2collection`.
  It checks the staged plugin directory for expected release headers,
  schema version, and runtime files. It verifies bootstrap wiring, the
  generated autoloader, and Stripe namespace isolation, then exercises
  Stripe adapter behavior with fixtures.
- `verify:release-sync` tests the build's synchronization command in a
  temporary directory. It checks that changed files are replaced, obsolete
  files are removed, and unchanged files keep their timestamps. It also
  checks that the build verifies the scoped tree before syncing it.

The synchronization test checks the staging behavior. To verify the contents
of a particular ZIP, extract it into a disposable directory and pass its
`cpt2collection/` directory to the artifact checker.

```sh
php tests/verify-release-artifact.php /path/to/extracted/cpt2collection
```

To compare two builds, compare sorted file paths and SHA-256 digests of the
extracted payloads. ZIP metadata can differ even when both payloads match.

## Run the tests

The repository provides individual entry points under `tests/`. Choose the
scripts that cover your change and read their prerequisite checks before
running them. There is no Composer script that runs every suite.

```sh
php tests/verify-commerce-rules.php
node tests/verify-stock-quick-edit.js
node tests/verify-commerce-reset-admin-frontend.js
php tests/verify-post-checkout-frontend.php
```

These examples cover cart eligibility rules, the Stock Quick Edit interface,
the reset interface, and the post-checkout frontend. The post-checkout PHP
entry point generates the shortcode fixture and runs its JavaScript check
with Node.js.

For source checks that load the Stripe SDK, install the ordinary Composer
dependencies in the repository root. For example, the provider check uses
a fixture transport.

```sh
composer install
php tests/verify-stripe-checkout-provider.php
```

## Database-dependent tests

Use an empty, disposable database for each test on a local-only
MariaDB/InnoDB instance. Give the test account access to create and remove
its fixtures there. Integration tests can create, change, and delete tables.
Use synthetic data and dedicated local credentials. Never run these scripts
against a production database.

| Environment variable | Purpose |
| --- | --- |
| `C2C_TEST_DB_NAME` | Name of the disposable database. Set it before running a database suite. |
| `C2C_TEST_DB_HOST` | Database host. The shared harness defaults to `127.0.0.1`. |
| `C2C_TEST_DB_PORT` | Database port. The shared harness defaults to `3306`. |
| `C2C_TEST_DB_USER` | Database user. The shared harness defaults to `root`. Supply your disposable test account as appropriate. |
| `C2C_TEST_DB_PASSWORD` | Database password if required. The shared harness defaults to an empty value. |
| `C2C_TEST_WORDPRESS_PATH` | Path to a clean WordPress source tree for the database adapter and connection-discard checks. These checks load the WordPress database class without booting the site. |

For example, after setting the database connection variables, run the admin
stock suite with `mysqli` and `pcntl` enabled.

```sh
php tests/verify-admin-stock.php
```

## Native WordPress tests

The Python drivers run against disposable WordPress installations and start
PHP's built-in server on loopback. Supply a disposable MariaDB socket. Both
drivers connect as `root` over that socket and create a test database.
They expect local socket access without a supplied password. Keep the
database instance dedicated to testing.

```sh
python3 tests/verify-commerce-reset-native-wordpress.py --help
python3 tests/verify-payment-notification-native.py --help
```

- **Reset and order deletion.** Pass a fresh, unconfigured WordPress tree
  under `/tmp/` as `--wordpress-root`, the extracted package directory as
  `--plugin-root`, and the database socket as `--socket`. Run the full check
  once with `--permalinks pretty` and once with `--permalinks plain`. Supply a
  fresh WordPress tree for each run. The driver writes a configuration into
  that tree and leaves its generated database for cleanup. `--schema` sets
  the expected schema version. `--benchmark-reset` runs a narrower reset
  loop.
- **Payment notifications.** Pass an unconfigured local WordPress tar
  archive as `--wordpress-archive` and the database socket as `--socket`.
  The archive should unpack to a `wordpress/` directory. Use
  `--plugin-dir` to test the extracted package. With that option omitted,
  the driver uses the source checkout, so install its Composer dependencies
  first. The driver needs Node.js for its localized UI check and a Python 3
  build that supports the `tarfile` extraction filter. It submits locally
  signed fixture events and captures WordPress mail for inspection.

## Compatibility and known limitations

The plugin declares WordPress 6.4 and PHP 8.2 as its runtime minimums.
The recorded native package checks used WordPress 6.9.4 and PHP 8.5.12.
The minimum WordPress and PHP combination has yet to be verified in a
native run.

With the locked tools installed, run the static compatibility checker on
the staged or extracted plugin directory.

```sh
php tests/verify-release-php-compatibility.php dist/cpt2collection
```

The checker uses the PHP parser from `tools/vendor/` for best-effort PHP
8.2 and 8.3 syntax checks. The release had a separate static PHP 8.2 parsing
check. Parsing and artifact verification do not exercise WordPress running
the plugin on those PHP versions.

Some older standalone suites model pre-baseline schemas and have known
fixture or assertion failures. Report the failing suite and its result.
For current commerce behavior, use the current-schema suites and native
drivers. Treat skipped checks and missing prerequisites as coverage gaps.
Some scripts exit successfully after printing `SKIP`, so read the output
as well as the exit status.

## Related documentation

- [Architecture](architecture.md)
- [Installation and upgrades](installation.md)
- [Localization](localization.md)
- [Contributing](../CONTRIBUTING.md)
