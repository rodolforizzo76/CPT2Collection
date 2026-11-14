#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="$ROOT/build/release"
INPUT="$BUILD/input"
SCOPED="$BUILD/scoped"
ARTIFACT="$ROOT/dist/cpt2collection"
SCOPER="$ROOT/tools/vendor/bin/php-scoper"

if [[ ! -x "$SCOPER" ]]; then
	echo "Missing PHP-Scoper. Run 'composer install --working-dir=tools' first." >&2
	exit 1
fi

rm -rf "$BUILD" "$ROOT/dist/cpt2collection.zip"
mkdir -p "$INPUT" "$SCOPED" "$ROOT/dist"

# Copy only the production inputs. Local root files and tooling never enter the build.
rsync -a --delete \
	--exclude='.*' --exclude='*.log' --exclude='*.zip' \
	--include='/LICENSE' --include='/README.md' \
	--include='/cpt2collection.php' --include='/uninstall.php' \
	--include='/composer.json' --include='/composer.lock' \
	--include='/includes/***' --include='/assets/***' \
	--include='/languages/' --include='/languages/cpt2collection.pot' --include='/languages/cpt2collection-it_IT.po' \
	--include='/languages/cpt2collection-*.mo' --include='/languages/cpt2collection-*.json' --exclude='*' \
	"$ROOT/" "$INPUT/"

COMPOSER_ALLOW_SUPERUSER=1 composer install \
	--working-dir="$INPUT" --no-dev --prefer-dist --no-interaction --no-progress \
	--classmap-authoritative

# The SDK runtime, CA bundle, version metadata and license remain intact.
SDK="$INPUT/vendor/stripe/stripe-php"
rm -f "$SDK/CHANGELOG.md" "$SDK/CONTRIBUTING.md" "$SDK/README.md" "$SDK/justfile"

CPT2COLLECTION_SCOPER_INPUT="$INPUT" "$SCOPER" add-prefix \
	--config="$ROOT/scoper.inc.php" --output-dir="$SCOPED" --force --no-interaction

# PHP-Scoper deliberately does not process Composer's generated bootstrap.
# Do not restore the input installation metadata: it advertises Stripe\\ and
# therefore cannot describe the transformed dependency. Instead, make the
# scoped tree a small release-only Composer project whose root PSR-4 mapping
# describes the namespace which is actually present on disk. Composer then
# owns and regenerates every bootstrap and metadata file in vendor/composer.
COMPOSER_RUNTIME="$INPUT/vendor/composer/InstalledVersions.php"
rm -rf "$SCOPED/vendor/composer"
rm -f "$SCOPED/composer.lock"
cat > "$SCOPED/composer.json" << 'JSON'
{
    "name": "rodolforizzo/cpt2collection-scoped-release",
    "description": "Generated metadata for the CPT2Collection scoped release artifact.",
    "license": "GPL-2.0-or-later",
    "autoload": {
        "psr-4": {
            "CPT2CollectionVendor\\Stripe\\": "vendor/stripe/stripe-php/lib/"
        }
    }
}
JSON
COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload \
	--working-dir="$SCOPED" --no-dev --no-interaction --no-scripts \
	--classmap-authoritative

# dump-autoload may register Composer\InstalledVersions even for this synthetic root.
# Keep Composer's own implementation, rather than shipping a dangling generated map.
if [[ -f "$COMPOSER_RUNTIME" ]]; then
	cp "$COMPOSER_RUNTIME" "$SCOPED/vendor/composer/InstalledVersions.php"
fi
rm -f "$SCOPED/composer.json" "$SCOPED/composer.lock"
cat > "$SCOPED/cpt2collection-release-autoload.php" << 'PHP'
<?php
/** Generated release-only dependency loader. */
declare( strict_types=1 );
$loader = __DIR__ . '/vendor/autoload.php';
if ( ! is_readable( $loader ) ) {
	throw new RuntimeException( 'CPT2Collection release dependencies are missing. Reinstall the complete plugin release package.' );
}
require_once $loader;
PHP

# The disposable scoped tree is authoritative. Do not expose it to staging until
# it has passed the same artifact checks required of the completed release.
php "$ROOT/tests/verify-release-artifact.php" "$SCOPED"

mkdir -p "$ARTIFACT"
# --no-times is intentional: -a otherwise applies the freshly generated source
# mtime even when --checksum finds identical content. Changed files are still
# replaced and receive the transfer time, while unchanged files retain theirs.
rsync -a --checksum --delete --no-times "$SCOPED/" "$ARTIFACT/"

php "$ROOT/tests/verify-release-artifact.php" "$ARTIFACT"

(
	cd "$ROOT/dist"
	zip -qr cpt2collection.zip cpt2collection
)

echo "Release artifact: $ARTIFACT"
echo "Release ZIP:      $ROOT/dist/cpt2collection.zip"
