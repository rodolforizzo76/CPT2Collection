# Changelog

## 1.0.0

- First public source snapshot of the stable commerce implementation.
- Includes a catalog for an existing WordPress custom post type, guest carts,
  reservations, Stripe Checkout, payment reconciliation, and Orders.
- Includes protected stock edits, paid Order customer snapshots and email
  notifications, permanent Order deletion, and Full Commerce Reset.
- Includes English source strings and Italian PHP and JavaScript catalogs.
- Commerce schema is 0.21.0. Stripe PHP is 21.3.3 and Checkout Session creation
  uses API version `2026-07-29.dahlia`.
- Reset duration depends on the volume of commerce and retained payment history.
