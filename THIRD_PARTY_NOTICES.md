# Third-party notices

CPT2Collection is GPL-2.0-or-later. The plugin and translation files retain
their existing attribution to Rodolfo Rizzo and the translation contributors.

- **Stripe PHP 21.3.3** is MIT licensed and carries Stripe, Inc.'s copyright
  notice. The release ZIP retains `vendor/stripe/stripe-php/LICENSE` and the
  upstream public TLS root certificate bundle. `composer.lock` identifies
  the exact upstream source revision. The build changes the Stripe PHP
  namespace to `CPT2CollectionVendor\Stripe` and regenerates the autoloader.
- **Composer runtime** files are generated or included by Composer.
  The release ZIP retains `vendor/composer/LICENSE` and its copyright notice.
- **PHP-Scoper and build dependencies** are development tools, with MIT,
  BSD-3-Clause, and Apache-2.0 packages listed in `tools/composer.lock`.
  The build-only vendor tree is excluded from the release ZIP.
- **WordPress** supplies the host runtime and is licensed under GPL version
  2 or later. The plugin ZIP contains no WordPress core files.

Distribute the release alongside matching source and build instructions.
Include the exact upstream Stripe source and its notices with the
corresponding source.
