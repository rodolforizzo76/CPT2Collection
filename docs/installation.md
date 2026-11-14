# Installation and upgrades

Use the attached `cpt2collection.zip` from the matching GitHub Release. Install
it through WordPress and activate the plugin. GitHub's generated source
archives need a build before installation.

CPT2Collection is for site owners who already manage a catalog as a WordPress
custom post type and want to take orders for its items through Stripe Checkout.
After activation, open **CPT2Collection → Settings**, select the catalog post
type, and follow the [configuration guide](configuration.md) to set up a cart
and confirm a test payment.

Before upgrading, make a verified database backup and arrange a maintenance
window. Test the upgrade against a representative copy. Verify the selected
post type, commerce availability and schema, cart, sandbox checkout, Orders,
and notifications before restoring traffic. A populated installation from
before the modern commerce baseline may need a separately authorized
maintenance operation. Seek review before enabling commerce on such an
installation. Full Commerce Reset is a separate destructive operation.
