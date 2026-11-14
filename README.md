# CPT2Collection

CPT2Collection adds a commercial catalog, guest cart, stock reservations,
Orders, and Stripe hosted Checkout to one existing WordPress custom post type.
The plugin uses your existing posts as products. It provides price, rating,
commercial status, stock and minimum-order settings, cart shortcodes, an
administrator Orders screen, and email notifications for confirmed payments.

## Requirements and installation

- WordPress 6.4 or later and PHP 8.2 or later with curl, JSON and mbstring.
- HTTPS, a MySQL or MariaDB database with InnoDB tables, and PHP access to Stripe.
- A registered custom post type and a Stripe account for checkout.
- Working WordPress cron and mail transport for notifications.

Download the attached **cpt2collection.zip** from the GitHub release. In
WordPress, open **Plugins → Add New → Upload Plugin**, upload the ZIP, then
activate it. Keep the plugin directory named `cpt2collection`. GitHub's
automatic **Source code** archives contain the build inputs. The attached ZIP
contains the scoped Stripe SDK and generated loader needed at runtime.

For an upgrade, back up the database first. A populated installation that
predates the modern commerce baseline needs a separately planned baseline
operation. See [installation and upgrades](https://github.com/rodolforizzo76/CPT2Collection/blob/v1.0.0/docs/installation.md).

## Set up commerce

Select the managed post type and cart page in the plugin settings. Configure
currency, the optional minimum order, and the reservation duration. Start Stripe
in test mode, configure the API secret and webhook signing secret, and set the
success and cancel pages. Follow the [configuration guide](https://github.com/rodolforizzo76/CPT2Collection/blob/v1.0.0/docs/configuration.md)
for the shortcodes, webhook events, and checkout verification.

Physical stock includes units held by active reservations. Confirmed sales
consume those units. The stock editor and Stock Quick Edit check for conflicting
edits and active reservations. See [catalog and stock](https://github.com/rodolforizzo76/CPT2Collection/blob/v1.0.0/docs/catalog-and-stock.md)
and [Orders and notifications](https://github.com/rodolforizzo76/CPT2Collection/blob/v1.0.0/docs/orders.md).

English strings and Italian translations are included, including frontend
JavaScript strings. See [localization](https://github.com/rodolforizzo76/CPT2Collection/blob/v1.0.0/docs/localization.md) to regenerate the
catalogs or contribute a translation.

**Full Commerce Reset permanently deletes local commerce data.** Verify a
database backup and schedule a maintenance window before using it. The reset
preserves physical stock and retained payment replay evidence. Normal commerce
stays fenced until the reset completes. Consult the
[maintenance guide](https://github.com/rodolforizzo76/CPT2Collection/blob/v1.0.0/docs/commerce-reset.md) before starting.

## Build and contribute

See the [architecture](https://github.com/rodolforizzo76/CPT2Collection/blob/v1.0.0/docs/architecture.md) for the lifecycle of a cart,
reservation, payment, and Order. The [development guide](https://github.com/rodolforizzo76/CPT2Collection/blob/v1.0.0/docs/development.md)
covers the locked Composer toolchain, release packaging, and test fixtures.
See the [changelog](https://github.com/rodolforizzo76/CPT2Collection/blob/v1.0.0/CHANGELOG.md), [contribution guide](https://github.com/rodolforizzo76/CPT2Collection/blob/v1.0.0/CONTRIBUTING.md), and
[private security reporting instructions](https://github.com/rodolforizzo76/CPT2Collection/blob/v1.0.0/SECURITY.md).

CPT2Collection is licensed under [GPL-2.0-or-later](LICENSE).
See [third-party notices](https://github.com/rodolforizzo76/CPT2Collection/blob/v1.0.0/THIRD_PARTY_NOTICES.md) for bundled dependencies.
