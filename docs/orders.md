# Orders and notifications

Use the administrator Orders screen to inspect payment status, items, and
available customer details. Paid records a confirmed payment. Fulfilment and
refunds require separate handling. The Order detail shows a reconciliation
warning when an Order needs attention.

When available, the customer name and email are recorded on the paid Order.
Customer and administrator emails have separate claims. Each consumed claim
allows at most one submission attempt to the WordPress mail transport. Set up
WP-Cron or host cron and monitor mail delivery independently.

Permanent Order deletion is a protected, destructive local operation. It
removes affected local operational data, including the customer snapshot.
Physical stock and payment replay evidence are preserved. Check fulfilment,
refunds, and provider-side retention separately before deleting an Order.
