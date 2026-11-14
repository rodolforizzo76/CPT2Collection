# Commerce maintenance

Full Commerce Reset is an exceptional destructive operation. A verified
database backup is required before starting. Review active payments and Orders
and arrange a maintenance window.

The reset erases local operational data such as carts, Orders, reservations,
and payment operations. It retires the current commerce epoch and preserves
physical inventory and retained evidence needed to recognize old payment
replays. Handle Stripe payments and provider records separately. The reset leaves
Stripe payments untouched, so arrange any cancellations or refunds separately.

Normal commerce remains fenced until completion. Duration depends on active
commerce and retained payment history. Follow the administrator screen's
continuation and recovery controls. If a provider or database outcome is
uncertain, reconcile it before retrying. Keep backups and external records
under your own retention policy.
