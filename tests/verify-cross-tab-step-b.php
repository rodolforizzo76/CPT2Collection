<?php
/** Focused structural verification for cross-tab checkout identity Step B. */
declare( strict_types=1 );
$root = dirname( __DIR__ );
$checkout = file_get_contents( $root . '/includes/class-checkout-service.php' );
$orders = file_get_contents( $root . '/includes/class-order-repository.php' );
$carts = file_get_contents( $root . '/includes/class-cart-repository.php' );
$sessions = file_get_contents( $root . '/includes/class-payment-session-repository.php' );
$reservations = file_get_contents( $root . '/includes/class-reservation-repository.php' );
$events = file_get_contents( $root . '/includes/class-payment-event-repository.php' );
$rest = file_get_contents( $root . '/includes/class-checkout-rest-controller.php' );
function step_b_check( bool $condition, string $label ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } echo "PASS: {$label}\n"; }
step_b_check( str_contains( $checkout, 'find_for_update( $cart->id() )' ), 'checkout claims identity under the cart row lock' );
step_b_check( str_contains( $checkout, 'find_revision_generation_for_update' ) && str_contains( $orders, 'cart_revision = %d AND checkout_generation = %d' ), 'revision/generation is the primary order lookup' );
step_b_check( str_contains( $checkout, "'replay' === \$decision" ) && str_contains( $checkout, 'OrderStatuses::PENDING, OrderStatuses::AWAITING_PAYMENT' ), 'pending and awaiting-payment converge on the current order independent of attempt key' );
step_b_check( str_contains( $checkout, "'checkout_already_paid'" ), 'paid current generations use checkout_already_paid' );
step_b_check( str_contains( $checkout, "'checkout_renewal_blocked'" ), 'unsafe terminal generations use checkout_renewal_blocked' );
step_b_check( str_contains( $checkout, 'advance_checkout_generation' ) && str_contains( $checkout, '++$generation' ) && str_contains( $checkout, '$this->orders->create' ), 'renewal advances generation before creating its order in one transaction' );
step_b_check( str_contains( $carts, "'content_revision' => \$expected_revision, 'checkout_generation' => \$expected_generation" ), 'generation advance is revision and generation guarded' );
step_b_check( str_contains( $checkout, 'OrderStatuses::FAILED' ) && str_contains( $checkout, 'array() === $sessions' ), 'failed renewal requires no payment session' );
step_b_check( str_contains( $checkout, 'ReservationStatuses::ACTIVE, ReservationStatuses::CONVERTED' ), 'active and converted reservations prevent renewal' );
step_b_check( str_contains( $checkout, '1 !== count( $sessions )' ) && str_contains( $checkout, 'PaymentSessionStatuses::EXPIRED' ), 'expired renewal requires exactly one expired owning session' );
step_b_check( str_contains( $checkout, 'provider_payment_id()' ) && str_contains( $checkout, 'payment_confirmed_at()' ), 'expired renewal rejects payment confirmation' );
step_b_check( str_contains( $checkout, 'reconciliation_required_at()' ) && str_contains( $checkout, 'reconciliation_event_id()' ), 'expired renewal rejects reconciliation uncertainty' );
step_b_check( str_contains( $events, "event_type = %s AND processing_status = %s") && str_contains( $events, 'checkout.session.expired' ), 'authoritative processed provider expiration evidence is required' );
step_b_check( str_contains( $checkout, 'expires_at() <= $this->clock->now_mysql()' ), 'authoritative expiration must also have elapsed in UTC' );
step_b_check( str_contains( $checkout, 'OrderStatuses::CANCELLED' ) && str_contains( $checkout, 'array() === $sessions' ), 'cancelled renewal permits no payment history' );
step_b_check( str_contains( $checkout, 'find_by_checkout_attempt' ) && str_contains( $checkout, "'checkout_attempt_conflict'" ), 'cross-revision attempt-key reuse fails deterministically' );
step_b_check( str_contains( $sessions, 'get_for_order_for_update' ) && str_contains( $reservations, 'get_for_order_for_update' ), 'payment and reservation lifecycle inspection uses explicit locking repository helpers' );
foreach ( array( 'checkout_already_paid', 'checkout_renewal_blocked' ) as $code ) { step_b_check( str_contains( $rest, "'{$code}'" ), "REST maps {$code} with retry_scope same" ); }
step_b_check( ! str_contains( $checkout, 'BroadcastChannel' ) && ! str_contains( $checkout, 'localStorage' ), 'Step C and Step D coordination is not introduced' );
echo "All cross-tab Step B structural checks passed.\n";
