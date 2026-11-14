# Architecture

`cpt2collection.php` loads WordPress integration and the commerce services.
Catalog metadata lives on the selected custom post type. Dedicated InnoDB
tables store cart contents, Orders and immutable line snapshots, reservations,
payment sessions, and provider evidence. Database migrations are versioned
separately from plugin releases.

A guest cookie carries an opaque cart token. Cart mutations use same-origin
and signed guest-token checks. Checkout revalidates prices and eligibility,
creates Order snapshots and reserves stock before requesting a Stripe
Checkout Session. The signed Stripe webhook is the payment authority.
Financial processing converts reservations and updates physical stock within
the commerce transaction. Payment identity and replay evidence guard repeat
delivery. The post-checkout page reads the local result.

Admin stock writes use the same per-product lock used by reservations and
paid conversions. Reset fences new commerce, retires the old epoch, and
retains evidence needed for old provider events. Unknown transaction or
provider outcomes require recovery and verification before further writes.

The release build transforms the Stripe SDK namespace and generates the
autoload files. WordPress supplies its own classes and functions at runtime.
