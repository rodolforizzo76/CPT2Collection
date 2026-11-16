# Orders and notifications

## Overview

Use **CPT2Collection → Orders** to review customer orders and check their payment 
status. The screen shows saved WordPress records. For the current state of a 
charge or refund, check Stripe separately.

An order marked **Paid** is ready for you to review for fulfilment. You still
need to arrange delivery, track fulfilment, and handle any refunds.

## Viewing orders

Sign in with a WordPress account that can manage site settings
(`manage_options`). This permission is required to view order details and
request permanent deletion.

1. Open **CPT2Collection → Orders**. The list shows **Order reference**,
   **Order status**, **Created (UTC)**, **Paid (UTC)**, **Total**,
   **Payment**, and **Cart update**.
2. Enter an exact **Order reference**, such as `C2C-123` or `123`, to find
   one order. You can also use **Order status**, **Created from**,
   **Created to**, or **Payment review**. The date range uses UTC and
   includes the whole final day.
3. Click **Filter** to apply your choices or **Clear filters** to show the
   full list. Use **Previous** and **Next** where available.
4. Click an **Order reference** to open the details. Use **Back to Orders**
   to return to the list.

**Order summary** shows the saved status, dates, currency, subtotal, and
total. **Order items** shows the saved item names, quantities, unit prices,
and line totals. Later changes to product names or prices do not rewrite
the order.

**Customer** shows the name and email Stripe supplied when payment was
recorded, when those details were available and could be saved. Missing
details display as **Not available**. Keep customer information private
when sharing order or payment diagnostics.

## Understanding order statuses

| Order status | What it means in WordPress |
| --- | --- |
| **Pending** | CPT2Collection has created the order during checkout. A ready payment session has yet to be recorded. |
| **Awaiting payment** | A Stripe Checkout Session was saved. CPT2Collection has yet to record a successful payment. |
| **Paid** | CPT2Collection verified the payment and recorded the sale. |
| **Cancelled** | The local order is recorded as cancelled. Returning through the checkout cancellation page does not set this status. |
| **Expired** | CPT2Collection processed the expiration of an unpaid Checkout Session. A stock hold can reach its deadline before the order shows this status. |
| **Failed** | The local order is recorded as failed. For example, checkout preparation can fail when it cannot reserve stock. This status does not establish that Stripe declined a charge. |

A new order starts as **Pending** before the stock reservation and payment
session are ready. It becomes **Awaiting payment** when the Stripe session is
saved and ready for redirect.

To record payment, CPT2Collection verifies the Stripe webhook signature and
checks the payment against the session, environment, amount, and currency
recorded for the order. It marks the order **Paid** and deducts stock when it
records the sale. A visit to the success page or a completed Checkout
Session alone does not confirm payment in WordPress.

Treat **Paid** as confirmation that CPT2Collection recorded a payment.
Other statuses do not prove that no charge exists in Stripe.

## Payment issues and reconciliation

The **Payment** column summarizes local confirmation and recorded attempts.
To find orders with payment issues, set **Payment review** to
**Requires review**. A **Paid** order can also show **Payment review required**.

Open the order and expand **Technical Payment Details**. **Payment
information** shows the recorded confirmation and whether review is needed.
**Payment attempts** shows recorded session statuses, amounts, dates, and
expiry. A session recorded as **Active** may already be unusable.

**Payment confirmed. Cart reconciliation needs attention.** means that the
payment is recorded and the follow-up cart update needs review. The **Cart
Reconciliation** section explains the saved result. Even a **Completed**
cart update can leave items in the cart, for example if the customer added
items after checkout began.

When payment needs review, or a customer reports paying while WordPress
still shows an unconfirmed order, compare the saved order with the relevant
Stripe payment and webhook delivery. Check the amount, currency, and Test or
Live environment. If the records disagree or the result is unclear, involve
your site maintainer before asking the customer to pay again, fulfilling the
order, or deleting it. The Orders screen has no manual payment confirmation
or reconciliation action.

## Customer and administrator notifications

When an order becomes **Paid**, CPT2Collection can queue the following
emails. Each needs a valid recipient address.

| Email | Recipient |
| --- | --- |
| **Payment confirmed** | The customer email address supplied by Stripe. |
| **New paid order** | The WordPress **Administration Email Address** under **Settings → General**. |

A missing or invalid address can prevent its email from being sent. If the
administration address changes before the send, the administrator email can
be skipped.

WordPress scheduled tasks (WP-Cron) process queued messages. The plugin
requests a notification check after payment and runs an hourly check for
messages still pending. A site's scheduled tasks and outgoing mail must
both be working.

CPT2Collection makes at most one submission attempt per email. It does not
automatically retry failed sends. WordPress accepting an email for sending
does not guarantee delivery. Use **Orders** as the payment record even when
an email is delayed or missing. For setup and mail troubleshooting, see
[Email notifications](configuration.md#email-notifications).

## Fulfilment and refunds

Use the saved items and available customer details to arrange fulfilment
outside CPT2Collection. **Paid** records payment confirmation. It does not
record whether you shipped or delivered the order.

Handle refunds and payment cancellations separately in Stripe. Before
taking action on an order, compare its local status with the current
payment record. Deleting a WordPress order leaves the external payment and
Checkout Session alone. Review inventory separately if returned items need
to be put back into stock. See [Catalog, cart and stock](catalog-and-stock.md).

## Permanently deleting an order

**Permanent deletion has no Trash or Restore.** Before deleting, review
fulfilment, any pending or completed payment, and any refund. Keep any order
information you still need in a protected location. 

**Important:** Deleting an order in WordPress does not cancel an active Stripe Checkout Session. A payment may still complete after the local order has been deleted.

1. Open the order from **CPT2Collection → Orders**. The confirmation workflow
   requires JavaScript.
2. Under **Permanent Deletion**, click **Review Permanent Deletion** when
   available. Review the reference, creation time, total, and status shown.
3. In **Deletion confirmation**, type the exact phrase on screen, including
   case and spaces. For order `C2C-123`, type `DELETE ORDER C2C-123`.
4. Click **Permanently delete Order**. Use **Cancel** before submitting if
   you decide to keep the order.

The service checks the site’s current commerce state and the related records
before allowing deletion. The displayed status or presence of the button
does not establish eligibility.

A confirmed deletion removes the local order, including the customer
details, items, and queued notification records. It also removes associated
stock reservations, payment session records, and operational webhook event
records. The guest cart and physical stock are preserved. Stock already
deducted for a paid order stays deducted.

CPT2Collection retains evidence of the deletion and payment identities so
it can recognize old payment activity. This evidence cannot restore the
order. Backups and Stripe records are separate. For maintenance affecting
the whole shop, see [Commerce maintenance](commerce-reset.md).

### If deletion is interrupted or rejected

Use **Inspect Order (read-only)** if the page reports an uncertain result,
a database problem, or that the displayed commerce state has changed. Sign
in again first if needed. A timeout or closed page can leave the result
unknown. **Order not found.** alone does not prove that this deletion
succeeded.

Keep the order reference and the message. If the outcome is still unverified
or the plugin reports conflicting records, ask your site maintainer to
investigate. Do not repeat an uncertain deletion or try to bypass the
browser's recovery safeguards.

## Troubleshooting

| What you see | What to check |
| --- | --- |
| **No orders found.** | Use **Clear filters** and check the exact reference. For an interrupted deletion, use the read-only inspection instead of relying on the list. |
| Payment still shows **Awaiting confirmation** after checkout. | Check the relevant Stripe payment and webhook delivery before requesting a new payment. |
| A **Paid** order still has a cart warning. | Review **Technical Payment Details → Cart Reconciliation**. The cart issue leaves payment confirmation unchanged. |
| An order is **Paid**, but an email is missing. | Check the recipient, spam folder, scheduled tasks, and mail logs. Use the order record to continue reviewing payment. |
| Deletion was rejected or could not be confirmed. | Use **Inspect Order (read-only)** and follow the displayed message. Seek technical review if the outcome stays unclear. |
