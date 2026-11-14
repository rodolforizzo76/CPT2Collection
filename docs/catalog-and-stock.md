# Catalog, cart and stock

Set an item's price, commercial status, rating, physical stock and minimum
order exemption in its WordPress editor. Available items need a positive price
and stock when saved. Guest carts stay on the server. Checkout rechecks
eligibility and reserves stock for a limited time.

Subtract active, unexpired reservations from physical stock to get the quantity
available to reserve. A confirmed sale consumes physical stock. Quantity
changes never automatically switch the item's commercial status to Sold.

The product editor and Stock Quick Edit reject stale edits and enforce the
reservation floor. Reload and review the latest stock after a conflict or an
uncertain save. Quick Edit changes stock only. Bulk Edit and arbitrary external
metadata writes are outside these protections. Ask for technical review if an
existing item has missing or malformed stock metadata.
