# Configuration

In the CPT2Collection settings, select one registered custom post type, set
currency and optional minimum quantity, link a cart page, and choose a
reservation duration. Use `[cpt2collection_cart]` on the cart page and
`[cpt2collection_add_to_cart]` in the catalog. Optional shortcodes include
`[cpt2collection_mini_cart]`, `[cpt2collection_cart_count]`,
`[cpt2collection_price]`, and `[cpt2collection_rating]`.

Create success and cancel pages using
`[cpt2collection_checkout_success]` and `[cpt2collection_checkout_cancel]`.
Set their HTTPS URLs in the plugin settings. Configure Stripe test mode with a
test secret key and the signing secret assigned to your webhook endpoint.
You can also supply these secrets through server-side WordPress configuration
using `CPT2COLLECTION_STRIPE_SECRET_KEY` and
`CPT2COLLECTION_STRIPE_WEBHOOK_SECRET`. Protect configuration and database
backups, and keep credentials out of source control and public issue reports.

Register the site's WordPress REST URL for `cpt2collection/v1/stripe/webhook`
in Stripe. On a site using pretty permalinks it normally ends in
`/wp-json/cpt2collection/v1/stripe/webhook`. Use the actual WordPress REST URL
for your installation. The handled events are:

- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.expired`

Verify a test checkout, signed webhook, paid Order, stock movement and mail
before selecting live mode. Use live credentials and the matching live
endpoint secret when switching. A return to the success page can precede
payment confirmation.
