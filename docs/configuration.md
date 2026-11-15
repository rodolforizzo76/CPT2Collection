# Configuration

## Getting started

1. Install and activate the attached `cpt2collection.zip` from the GitHub
   Release. Follow the [installation guide](installation.md) if you have not
   installed the plugin yet. Check the hosting requirements in the
   [README](../README.md#requirements-and-installation), including HTTPS.
2. Make sure your catalog already exists as a WordPress Custom Post Type: a
   content type registered by your theme or another plugin. CPT2Collection
   adds commerce to one existing type. Have at least one published catalog
   item and access to a Stripe account for testing.
3. Sign in as a WordPress administrator and open **CPT2Collection → Settings**.
   The page is titled **CPT2Collection Settings**. Use **Save Changes** at the
   bottom of the page after updating settings.

Use a separate HTTPS test site if possible. Stripe test mode does not charge
real cards, but a successful test checkout creates Orders, deducts stock, and
can send real email on the WordPress site where you run it.

## Catalog settings

1. Under **Collection settings**, choose your catalog in **Managed post type**.
   The dropdown shows each type's display name and slug (its internal name).
   If your type is missing, check that its theme or plugin is active and that
   the type is registered as public with a WordPress admin interface. Built-in
   types such as Posts and Pages do not appear.
2. Set **Currency** to **EUR** (the default) or **USD**. Choose it before
   entering prices; changing the currency does not convert existing amounts.
3. Set **Minimum order quantity** to the total number of units required across
   the cart, not a number of distinct products or a minimum spend. Leave it
   at `1` for no additional minimum. If the cart contains an eligible item
   marked **Can be purchased without reaching the minimum order quantity**,
   the entire cart is exempt from the quantity minimum. Other checkout rules
   still apply.
4. Leave **Reservation duration** at **35 minutes** to start. The setting
   accepts 5 to 120 minutes, but opening Stripe Checkout requires at least
   30 minutes remaining on the reservation. The default allows some time for
   checkout setup.
5. Save your settings. Edit a published catalog item and find its
   **CPT2Collection** panel. Enter a positive **Price** and a positive whole
   number for **Stock**, set **Commercial status** to **Available**, and
   update the post. You can also set an optional **Rating** or enable the
   minimum order exemption here.

**Stock** is the number of units physically on hand, including units reserved
for checkout. Adding an item to the cart does not reserve it. Checkout holds
stock temporarily, reducing the quantity available to other shoppers without
changing physical stock. A confirmed sale deducts the purchased units. An
expired hold no longer counts against availability. Stock reaching zero does
not automatically change **Commercial status** to **Sold**. See
[Catalog, cart and stock](catalog-and-stock.md) for stock editing and
reservation limits on stock changes.

## Cart and checkout pages

1. Create three public WordPress pages: one for the cart, one for the return
   after checkout, and one for the return when a shopper leaves Stripe
   Checkout. You can choose their titles. In each page, add a WordPress
   **Shortcode** block and paste the matching shortcode from the table below.
   Publish all three pages.
2. Go to **CPT2Collection → Settings**. Under **Collection settings**, select
   the published cart page in **Cart page** and save. This gives the mini cart
   and checkout return pages a link back to the cart.
3. Add `[cpt2collection_add_to_cart]` to a published catalog item using a
   **Shortcode** block. If you place the control on another page, specify the
   item's post ID, for example `[cpt2collection_add_to_cart post_id="123"]`
   with `123` replaced by the actual ID. Provide a visible link to the cart,
   such as a navigation link or the optional mini cart.
4. Open the catalog and cart as a logged-out visitor. Use a browser with
   JavaScript and cookies enabled. The full cart contains **Proceed to
   checkout**, which opens Stripe's hosted payment page when the cart is
   eligible and Stripe is configured. No separate WordPress payment form or
   checkout page is needed.

| Shortcode | Where to use it and what it does | Optional attributes |
| --- | --- | --- |
| `[cpt2collection_cart]` | Cart page. Shows items and totals, lets shoppers change quantities or clear the cart, and starts checkout. | None. |
| `[cpt2collection_add_to_cart]` | Catalog item or another page. Shows a quantity field and **Add to cart** control. | `post_id`: item ID; defaults to the current post. |
| `[cpt2collection_mini_cart]` | Optional compact cart with a count, item summary, and a link to the configured cart page. | None. |
| `[cpt2collection_cart_count]` | Optional total number of units in the cart. | `hide_when_empty="yes"`: hides the count when empty. Default: `"no"`. |
| `[cpt2collection_price]` | Optional formatted price for a catalog item. | `post_id`: item ID; defaults to the current post. |
| `[cpt2collection_rating]` | Optional saved rating for a catalog item. | `post_id`: item ID; defaults to the current post. |
| `[cpt2collection_checkout_success]` | Success page. Looks up payment status and shows an Order summary when confirmed. | None. |
| `[cpt2collection_checkout_cancel]` | Cancellation page. Explains the return and links back to the cart. | None. |

## Stripe configuration

Stripe Checkout handles one-time card payments on a page hosted by Stripe.
A *webhook* is a message Stripe sends to your WordPress site about a checkout
event. Its signing secret lets the plugin verify that Stripe sent it.

1. In **CPT2Collection → Settings**, find **Stripe Checkout** and leave
   **Environment** set to **Test** (the default).
2. In your Stripe test environment, register a webhook endpoint using the
   public HTTPS URL for the WordPress REST route
   `/cpt2collection/v1/stripe/webhook`. Use the form that matches your site:
   - Pretty permalinks: `https://example.com/wp-json/cpt2collection/v1/stripe/webhook`
   - Plain permalinks: `https://example.com/?rest_route=/cpt2collection/v1/stripe/webhook`

   Replace the example site address with yours and use the actual REST base
   URL if WordPress is installed in a subdirectory or uses a different REST
   base. Stripe must be able to send HTTPS POST requests to this route without
   signing in to WordPress. If your test site is protected, allow requests to
   this route while keeping the plugin's signature verification in place.
3. Subscribe the endpoint to these events:
   - `checkout.session.completed`
   - `checkout.session.async_payment_succeeded`
   - `checkout.session.expired`
4. Enter the Stripe test API secret key (prefix `sk_test_`) in **Secret key**.
   Enter the signing secret for the test endpoint you just registered (prefix
   `whsec_`) in **Webhook signing secret**. The API key opens Checkout
   Sessions; the signing secret verifies incoming events. Stripe assigns a
   separate signing secret to each endpoint, so use the one for this site and
   environment.
5. Fill in **Success URL** and **Cancel URL** as described below, then save.
   Both must be full HTTPS URLs on the same site as the cart. Keep the API
   key and webhook signing secret matched to the selected **Environment**;
   switching the dropdown alone does not switch the credentials.

### Advanced: store credentials in server-side configuration

If you enter the secrets in the dashboard, WordPress stores them in its
database. The fields do not display saved values, and leaving a field blank
when saving keeps its existing value. Protect database and configuration
backups. Never commit credentials to source control or include them in public
issues or shared logs.

If your host manages secrets outside the dashboard, have the server-side
WordPress configuration define `CPT2COLLECTION_STRIPE_SECRET_KEY` and
`CPT2COLLECTION_STRIPE_WEBHOOK_SECRET` from protected values. Each defined
constant takes precedence over the corresponding dashboard value. Changing
the dashboard field will not replace that override.

## Payment confirmation pages

1. Set **Success URL** to the public HTTPS permalink of the page with
   `[cpt2collection_checkout_success]`. Append
   `?session_id={CHECKOUT_SESSION_ID}`, or use
   `&session_id={CHECKOUT_SESSION_ID}` if the permalink already contains a
   query string. For example:
   `https://example.com/checkout-success/?session_id={CHECKOUT_SESSION_ID}`.
   Keep the placeholder and braces exactly as shown. Stripe replaces it with
   the Checkout Session ID when sending the shopper back.
2. Set **Cancel URL** to the public HTTPS permalink of the page with
   `[cpt2collection_checkout_cancel]`. It does not need a session ID.
3. Save the settings.

Starting checkout can create a **Pending** Order in WordPress before any
payment is made. After Stripe Checkout opens, the Order can show **Awaiting
payment**. The plugin marks the Order **Paid** and deducts stock when it
processes a verified webhook confirming payment for that checkout. A
`checkout.session.completed` event without paid status does not confirm
payment.

Returning to the success page does not prove payment. The page checks the
Order status for the shopper's session and may show that confirmation is
pending. Use **Check status** if offered, and verify **Paid** in
**CPT2Collection → Orders** before fulfilling. Visiting the cancellation page
does not cancel a payment or Order, or release a reservation; it provides a
way back to the cart.

## Email notifications

1. In **Settings → General**, check the WordPress **Administration Email
   Address**. This is the address CPT2Collection uses for the **New paid
   order** notification. The **Payment confirmed** email goes to the customer
   when Stripe supplies a valid customer email. The two notifications are
   handled separately after payment is confirmed.
2. Make sure WordPress scheduled tasks (WP-Cron) run on your site. The plugin
   schedules a notification check after payment confirmation and runs an
   hourly check for pending notifications. If automatic WP-Cron is disabled
   or unreliable on your site, arrange for your host's scheduler to run due
   WordPress cron events.
3. Verify that WordPress mail works with your host's mail setup or configured
   mail provider. An Order can be **Paid** even if mail is delayed or fails.
   The plugin allows at most one submission attempt per notification. Even
   if WordPress accepts the message for sending, that does not guarantee
   inbox delivery. If mail is missing, check the cron runner, the recipient
   addresses, the mail provider's logs if available, and the spam folders.

See [Orders and notifications](orders.md) for more on paid Orders and mail.

## Test your configuration

1. Confirm that **Environment** is **Test**, both secrets come from the
   test environment, and the test webhook points to this site's REST route.
   Use a test product, record its starting **Stock**, and choose customer and
   administrator inboxes you can check. For a simple reservation test, use a
   product with two units in stock and check out with both units. If your
   minimum is higher, use enough units to meet it.
2. In a logged-out browser, add items to the cart. Check the price, currency,
   and quantities. If you configured a minimum above `1`, check that the
   cart blocks checkout below the minimum and allows it when met. If you use
   an exempt item, test that case too.
3. Click **Proceed to checkout** and confirm that Stripe opens a test
   Checkout Session with the expected items and total. Before paying, check
   **CPT2Collection → Orders** in a separate admin browser session. The
   Order should exist and should not be **Paid**. Physical stock should be
   unchanged. To verify the hold, try to check out for the same product in
   a separate shopper session. It should not be able to reserve the units
   held by the first checkout.
4. Complete the sandbox payment with Stripe test card details and an email
   address you control. Keep the original shopper session for the return to
   the success page. Check the test webhook delivery in Stripe and verify
   that the page shows confirmation once the webhook has been processed.
5. In **CPT2Collection → Orders**, open the Order and verify **Paid**, the
   line items, quantities, total and currency, and the customer name and
   email where supplied by Stripe. Reopen the product editor and verify that
   physical **Stock** fell by exactly the purchased quantity.
6. After cron runs, check that the customer and administrator received their
   respective emails for the same Order reference.
7. Use another product with stock or replenish the test product. Start a
   separate sandbox checkout and use Stripe's return link without paying.
   Check that the cancellation page links back to the cart, the Order is not
   **Paid**, and physical stock is unchanged. Verify that the held units
   become available again after the reservation expires.

## Going live

1. Complete the sandbox checks before opening the shop. Finish or let open
   test Checkout Sessions expire before switching credentials, and review
   any test stock changes if you tested on the site that will go live.
2. In Stripe's live environment, register the production site's public HTTPS
   webhook URL with the same supported events. Even when the URL is the same
   as in Test, use the signing secret issued for the live endpoint.
3. In **Stripe Checkout**, select **Live** under **Environment**. Set
   **Secret key** to the live API secret key (prefix `sk_live_`) and
   **Webhook signing secret** to the live endpoint's signing secret. If
   server-side constants supply the secrets, update those values instead.
   Confirm that **Success URL** and **Cancel URL** point to the production
   pages, then save.
4. Verify the live endpoint's URL and event subscriptions in Stripe. Confirm
   that production visitors can open the catalog and cart, that both return
   pages are published, and that cron and mail are ready. A live payment
   charges a real payment method; run one only if the site operator
   intentionally authorizes it.
