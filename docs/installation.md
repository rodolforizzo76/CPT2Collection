# Installation and upgrades

## Overview

CPT2Collection 1.0.0 adds product settings, a guest cart, and Stripe Checkout
to one existing WordPress Custom Post Type. It is intended for site owners
who already use a custom post type for their catalog, such as artworks or
collectibles. The plugin uses the posts you already have.

If this is your first install, start with the requirements below. If the site
already has CPT2Collection and commerce data, read
[Updating CPT2Collection](#updating-cpt2collection) before uploading the ZIP.

## Requirements

| What you need | Requirement |
| --- | --- |
| WordPress and PHP | WordPress **6.4** or later and PHP **8.2** or later are the declared minimums. |
| PHP extensions | Enable `curl`, `json`, and `mbstring` for the bundled Stripe library. |
| Database | Use MySQL or MariaDB with InnoDB. The WordPress posts and post metadata tables used for stock must also use InnoDB. Your WordPress database account must be able to create and update the plugin's tables. |
| Website and payments | Use HTTPS for checkout and the payment return pages. Stripe needs to reach the site's webhook URL. You will need a Stripe account when setting up payments. |
| Email | Payment notifications require working WordPress scheduled tasks and outgoing mail. |
| Dashboard access | Use a WordPress account that can install and activate plugins and manage site settings. |

You can activate CPT2Collection before registering a Custom Post Type. To
configure the catalog, the theme or plugin that provides your post type must
be active. Use a public custom post type that has a WordPress admin screen.

The attached release ZIP includes the runtime dependencies. Composer and
other build tools are for developers building from source. See
[Build and test](development.md) for those requirements and tested versions.

## Download the plugin

1. Open [GitHub Releases](https://github.com/rodolforizzo76/CPT2Collection/releases)
   and select the release for the version you intend to install.
2. Under **Assets**, download the attached **`cpt2collection.zip`**.
3. Keep the ZIP intact for uploading in WordPress.

The release ZIP contains one `cpt2collection/` plugin folder with the runtime
files, bundled Stripe library, and generated loader. GitHub's **Source code**
ZIP and tar archive contain the repository sources and require a build.

## Install CPT2Collection

1. Sign in to your WordPress dashboard. Open **Plugins → Add New**. Some
   WordPress versions label this screen **Add New Plugin**.
2. Click **Upload Plugin** and select the downloaded `cpt2collection.zip`.
3. Click **Install Now**.
4. When installation finishes, click **Activate Plugin**.
5. Open **CPT2Collection → Settings**.

Activation starts database setup. CPT2Collection creates its commerce tables
and applies the database changes that are needed. It also checks for missing
or older schema versions when WordPress loads the active plugin, so setup
may continue on later requests. Seeing the plugin listed as active confirms
that WordPress enabled it. This does not necessarily mean that database setup 
has completed successfully. Check the cart and a test order before opening
the shop.

For the dashboard upload steps, see
[Manage Plugins](https://wordpress.org/documentation/article/manage-plugins/).

## First-time setup

1. In **CPT2Collection → Settings**, choose your **Managed post type** and
   click **Save Changes**.
2. Open a published catalog item. In the **CPT2Collection** panel, enter a
   positive **Price** and **Stock** and set **Commercial status** to
   **Available**.
3. Add purchase controls to your catalog and set up the cart and checkout
   return pages.
4. Configure Stripe in **Test** mode and place a test order. Verify that 
   the order becomes Paid, stock decreases by the purchased quantity, and 
   the expected email notifications are delivered when valid recipients 
   are available.

Follow the [configuration guide](configuration.md) for the page shortcodes,
Stripe settings, and the checks to make before going live.

## Updating CPT2Collection

### Prepare the update

1. Read the release notes for the version you plan to install. If your copy
   predates the current commerce foundation or you are unsure what it runs,
   read [Existing installations and migrations](#existing-installations-and-migrations).
2. Make and verify a current database backup. Keep a copy of the currently
   installed plugin files as part of your recovery plan.
3. Try the update on a representative staging copy. Isolate it from live
   payment traffic and use test credentials and a separate test webhook
   for checkout checks. Review mail delivery before testing on a copy with
   customer data.
4. Schedule a maintenance window for the live site. Arrange a maintenance 
   window and prevent customers from starting new checkouts using your 
   site's maintenance procedures. Review any payments already in progress 
   and confirm their outcomes before updating. Let them finish or expire 
   and check their outcomes before replacing the files.

### Replace the installed ZIP

1. In WordPress, open **Plugins → Add New → Upload Plugin**, select the new
   release ZIP, and click **Install Now**.
2. When WordPress shows the installed and uploaded versions, check that both
   are CPT2Collection and that the uploaded version is the one you intend
   to install. Click **Replace current with uploaded**.
3. Check that CPT2Collection is active under **Plugins**, then open
   **CPT2Collection → Settings** and **Orders**. Loading WordPress with the
   updated plugin active allows its startup upgrade checks to run.

Use the replacement step for a copy installed in the normal
`cpt2collection/` folder. If WordPress offers a separate installation
instead of a replacement, stop and ask your site maintainer to check the
installed folder and package. The replacement option is provided by the
[WordPress plugin installer](https://developer.wordpress.org/reference/classes/plugin_installer_skin/).

### Check before reopening

- Confirm that **Managed post type**, catalog prices, physical stock, and cart
  and return page settings match what you expect.
- Check that existing orders you expect to keep are still visible and that
  their status and details make sense. Verify that the guest cart works.
- Verify on staging that a test checkout reaches **Paid**, deducts stock
  correctly, and sends emails. Review live settings and payment records on
  the live site before restoring traffic.
- If you need to recover from a failed update, review any payments and
  orders created since the backup with your maintainer. Restoring files
  alone does not undo database changes, and restoring WordPress cannot
  restore Stripe to an earlier state.

## Existing installations and migrations

A *schema* is the structure of the database tables. CPT2Collection tracks its
schema version separately from its plugin version. Activation calls the setup
routine. During normal startup, the plugin calls the same routine when the
recorded schema version is missing or older. It records a newer schema
version as migration steps succeed. You do not need to run a migration from
the dashboard for a normal update.

A fresh installation and an established installation that already meets the
current commerce foundation can use the built-in setup and upgrade checks.
Older copies may have local carts, orders, or payment history that predate
that foundation. The transition to database schema `0.15.0` checks for old
commerce records and earlier reset history. If it finds them, it can leave
normal commerce blocked pending separate, authorized maintenance. A newer
schema version alone does not resolve that state.

If setup cannot complete a step, it does not record that step as complete.
Later loads can check the upgrade again, but an active plugin or repeated
reloads do not confirm recovery. Keep the shop closed if commerce is
unavailable and ask your maintainer to verify the stored schema and commerce
state, especially on a site with existing payment records.

**Do not use Full Commerce Reset to finish an upgrade or repair a failed
migration.** It permanently removes local commerce records and leaves Stripe
payments in place. See [Commerce maintenance](commerce-reset.md).

## Troubleshooting

| What you see | What to check |
| --- | --- |
| WordPress rejects the upload or activation fails. | Confirm that you uploaded the attached release ZIP and that your hosting meets the WordPress and PHP requirements. If the upload fails before installation, ask your host about upload limits or file permissions. |
| **CPT2Collection → Settings** is missing. | Check that the plugin is active and your account can manage site settings. |
| Your catalog is missing from **Managed post type**. | Confirm that the theme or plugin providing it is active and that the custom post type is public and has an admin screen. Built-in Posts and Pages cannot be selected. |
| **Orders** says it is temporarily unavailable, or the cart stopped working after an upgrade. | Keep checkout closed. Record what you see, the plugin and hosting versions, and whether the site already had commerce data. Ask your maintainer to check database access, InnoDB, and migration progress. The message does not identify a specific failed migration. |
| The Stripe fields say **Configured**, but checkout or payment confirmation fails. | **Configured** means a value is present. Check the environment, credentials, return URLs, and webhook setup using the [configuration guide](configuration.md#stripe-configuration). Review an existing payment before attempting another one. |
