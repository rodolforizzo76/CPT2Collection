<p align="center">
  <img src="docs/images/cpt2collection-logo.png"
       alt="CPT2Collection"
       width="100%">
</p>

# CPT2Collection

Turn your WordPress Custom Post Type into a shop. CPT2Collection adds e-commerce
to one WordPress Custom Post Type, so you can sell from the posts you already
manage without rebuilding your catalog.

## Features

- **Catalog** — Set prices, ratings, sales status, stock quantities, and
  minimum-order rules for existing posts.
- **Guest cart** — Let visitors add items and change quantities before checkout.
- **Inventory** — Reserve stock during checkout and deduct it after payment
  confirmation. Update quantities in the post editor or through Stock Quick Edit.
- **Stripe Checkout** — Send customers to Stripe's hosted payment page.
- **Orders** — Review order items, payment status, and available customer details
  in WordPress. Send payment confirmation emails to customers and administrators.
- **Languages** — Use the English interface or the included Italian translations.

## How it works

1. **Your posts become products.** Choose the Custom Post Type you already use
   for your catalog and give the items you want to sell a price and stock quantity.
2. **Visitors shop on your site.** Add cart controls to your catalog pages so
   visitors can choose items and review their cart.
3. **Customers pay through Stripe.** CPT2Collection reserves the items for a
   limited time and sends customers to Stripe Checkout.
4. **You manage Orders in WordPress.** Once payment is confirmed, check the
   Order and its items in the dashboard and handle fulfilment separately.

## Requirements and installation

The plugin declares WordPress 6.4 and PHP 8.2 as its minimum versions. Checkout
requires HTTPS, a MySQL or MariaDB database using InnoDB, a Stripe account, and
the PHP curl, JSON, and mbstring extensions. Email notifications require working
WordPress cron and mail delivery.

Download the attached **`cpt2collection.zip`** from
[GitHub Releases](https://github.com/rodolforizzo76/CPT2Collection/releases).
In WordPress, open **Plugins → Add New → Upload Plugin**, upload the ZIP, and
activate it. The attached ZIP includes the runtime dependencies. GitHub's
automatic **Source code** archives require a build before installation.

For an existing plugin installation, see the [upgrade guide](docs/installation.md).

## Getting started

1. Open **CPT2Collection → Settings** and select your catalog post type.
2. Set prices and stock for a few items, then add the cart and checkout pages.
3. Connect Stripe in test mode and place a test order. Check the payment, Order
   status, stock change, and email before switching to live mode.

The [configuration guide](docs/configuration.md) walks through the pages,
shortcodes, and Stripe setup.

## Documentation

- [Catalog, cart, and stock](docs/catalog-and-stock.md)
- [Orders and notifications](docs/orders.md)
- [Localization](docs/localization.md)
- [Architecture](docs/architecture.md) and [build and test](docs/development.md)

**Full Commerce Reset permanently deletes local commerce records.** Use it only
for exceptional maintenance and verify a database backup before starting. See
the [Full Commerce Reset guide](docs/commerce-reset.md).

If you're reading this from the installed ZIP, open the
[CPT2Collection repository](https://github.com/rodolforizzo76/CPT2Collection)
for the full guides.

## Contributing and license

CPT2Collection is by Rodolfo Rizzo and is licensed under
[GPL-2.0-or-later](LICENSE). See the [changelog](CHANGELOG.md),
[contribution guide](CONTRIBUTING.md), and [third-party notices](THIRD_PARTY_NOTICES.md).
Report vulnerabilities through the [private reporting process](SECURITY.md).
