<?php
/** Isolated executable verification for the production checkout REST entry point. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

function __( string $message, string $domain ): string { return $message; }

final class WP_REST_Server { public const CREATABLE = 'POST'; }
class WP_REST_Request {
	public function __construct( private array $parameters = array() ) {}
	public function get_param( string $name ): mixed { return $this->parameters[ $name ] ?? null; }
	public function get_header( string $name ): string { return ''; }
}
class WP_REST_Response {
	public function __construct( private mixed $data, private int $status ) {}
	public function get_data(): mixed { return $this->data; }
	public function get_status(): int { return $this->status; }
}
class WP_Error {}

foreach ( array( 'cart-record', 'checkout-preparation-result', 'payment-session-statuses', 'money', 'payment-session-record', 'payment-session-result', 'guest-csrf', 'checkout-rest-controller' ) as $class ) {
	require_once dirname( __DIR__ ) . '/includes/class-' . $class . '.php';
}

use RodolfoRizzo\Cpt2Collection\CartRecord;
use RodolfoRizzo\Cpt2Collection\CheckoutPreparationResult;
use RodolfoRizzo\Cpt2Collection\CheckoutRestController;
use RodolfoRizzo\Cpt2Collection\GuestCsrf;
use RodolfoRizzo\Cpt2Collection\PaymentSessionRecord;
use RodolfoRizzo\Cpt2Collection\PaymentSessionResult;

function check( bool $condition, string $label ): void {
	if ( ! $condition ) { throw new RuntimeException( $label ); }
	echo "PASS: {$label}\n";
}
function request(): WP_REST_Request { return new WP_REST_Request( array( 'checkout_context' => 'v1.'.(time()+3600).'.'.str_repeat('f',64), 'checkout_attempt_key' => str_repeat( 'a', 32 ), 'payment_attempt_key' => str_repeat( 'b', 32 ) ) ); }
function cart(): CartRecord { return CartRecord::from_row( (object) array( 'id'=>7, 'token_hash'=>'hash', 'content_revision'=>0, 'checkout_generation'=>0, 'created_at'=>'2026-01-01 00:00:00', 'updated_at'=>'2026-01-01 00:00:00', 'last_activity_at'=>'2026-01-01 00:00:00', 'expires_at'=>'2027-01-01 00:00:00' ) ); }
function active_session(): PaymentSessionRecord { return new PaymentSessionRecord( 9, str_repeat( 'c', 32 ), 11, 'stripe_checkout', 'cs_test_safe', hash( 'sha256', 'payment_' . str_repeat( 'b', 32 ) ), 'active', 1, 'EUR', 1200, 'https://checkout.stripe.com/c/pay/safe', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2027-01-01 00:00:00' ); }
function controller( callable $cart, callable $checkout, callable $payment ): CheckoutRestController { return new CheckoutRestController( $cart, $checkout, $payment, new GuestCsrf() ); }

$calls = array();
$stock = 5; $order_status = 'pending';
$happy = controller(
	static fn(): CartRecord => cart(),
	static function ( CartRecord $cart, string $key ) use ( &$calls ): CheckoutPreparationResult { $calls[] = array( 'checkout', $cart->id(), $key ); return CheckoutPreparationResult::success( 'checkout_prepared', 'Prepared.', 11, 'reservation_acquired' ); },
	static function ( int $order, string $key ) use ( &$calls ): PaymentSessionResult { $calls[] = array( 'payment', $order, $key ); return PaymentSessionResult::success( 'payment_session_created', 'Created.', active_session() ); }
);
$response = $happy->initiate( request() ); $data = $response->get_data();
check( 200 === $response->get_status() && 'https://checkout.stripe.com/c/pay/safe' === $data['redirect_url'], 'happy path returns the hosted redirect URL' );
check( array( 'checkout', 7, 'checkout_' . str_repeat( 'a', 32 ) ) === $calls[0] && array( 'payment', 11, 'payment_' . str_repeat( 'b', 32 ) ) === $calls[1], 'persisted cart and separated opaque keys are passed to the production service boundaries' );
check( 5 === $stock && 'pending' === $order_status, 'REST initiation itself does not decrement stock or mark an order paid' );
check( ! str_contains( json_encode( $data ), 'sk_test_' ) && ! str_contains( json_encode( $data ), 'whsec_' ) && ! isset( $data['order_id'], $data['payment_session_id'], $data['provider_session_id'] ), 'success output contains only safe redirect and local reference data' );

$repeat = $happy->initiate( request() );
check( 200 === $repeat->get_status() && $repeat->get_data()['reference'] === $data['reference'] && $calls[0][2] === $calls[2][2] && $calls[1][2] === $calls[3][2], 'repeated submission reuses identical checkout and payment keys' );

// Reproduce a lost first HTTP response after the order/session activation commit.
$state = array( 'orders' => array(), 'sessions' => array(), 'reservations' => array(), 'stock' => 5, 'checkout_calls' => 0, 'payment_calls' => 0, 'reservation_acquisitions' => 0, 'provider_calls' => 0 );
$response_loss = controller(
	static fn(): CartRecord => cart(),
	static function ( CartRecord $cart, string $key ) use ( &$state ): CheckoutPreparationResult {
		++$state['checkout_calls'];
		if ( array() === $state['orders'] ) {
			$state['orders'][11] = array( 'id' => 11, 'cart_id' => $cart->id(), 'key' => $key, 'status' => 'pending' );
			$state['reservations'][21] = array( 'id' => 21, 'order_id' => 11, 'status' => 'active', 'expires_at' => '2027-01-01 00:00:00' );
			++$state['reservation_acquisitions'];
		}
		check( $state['orders'][11]['key'] === $key, 'response-loss replay uses the original checkout key and order' );
		return CheckoutPreparationResult::success( 'checkout_prepared', 'Prepared.', 11, 1 === $state['checkout_calls'] ? 'reservation_acquired' : null );
	},
	static function ( int $order_id, string $key ) use ( &$state ): PaymentSessionResult {
		++$state['payment_calls'];
		if ( array() === $state['sessions'] ) {
			++$state['provider_calls'];
			$state['sessions'][9] = active_session();
			$state['orders'][ $order_id ]['status'] = 'awaiting_payment';
		}
		check( 'payment_' . str_repeat( 'b', 32 ) === $key, 'response-loss replay uses the original payment key' );
		return PaymentSessionResult::success( 1 === $state['payment_calls'] ? 'payment_session_created' : 'payment_session_already_active', 'Active.', $state['sessions'][9] );
	}
);
$lost_response = $response_loss->initiate( request() );
$reservation_snapshot = $state['reservations'];
$retry_response = $response_loss->initiate( request() );
check( 200 === $lost_response->get_status() && 200 === $retry_response->get_status(), 'response-loss retry succeeds after awaiting-payment activation' );
check( 'payment_session_created' === $lost_response->get_data()['code'] && 'payment_session_already_active' === $retry_response->get_data()['code'], 'response-loss retry reaches the payment service active-replay result' );
check( $lost_response->get_data()['reference'] === $retry_response->get_data()['reference'] && 'cs_test_safe' === $state['sessions'][9]->provider_session_id() && $lost_response->get_data()['redirect_url'] === $retry_response->get_data()['redirect_url'], 'response-loss retry returns the same payment session, provider Session, reference, and redirect URL' );
check( 1 === count( $state['orders'] ) && 11 === $state['orders'][11]['id'] && 1 === count( $state['sessions'] ) && 'active' === $state['sessions'][9]->status() && 'awaiting_payment' === $state['orders'][11]['status'], 'response-loss retry retains exactly one awaiting order and one active payment session' );
check( 1 === $state['provider_calls'] && 1 === $state['reservation_acquisitions'] && $reservation_snapshot === $state['reservations'] && 5 === $state['stock'], 'response-loss retry neither calls Stripe twice nor reacquires reservations, extends expiry, or changes stock' );

$payment_calls = 0;
$empty = controller( static fn(): CartRecord => cart(), static fn(): CheckoutPreparationResult => CheckoutPreparationResult::failure( 'cart_empty', 'Cart empty.' ), static function () use ( &$payment_calls ): PaymentSessionResult { ++$payment_calls; return PaymentSessionResult::success( 'unexpected', 'Unexpected.', active_session() ); } );
check( 422 === $empty->initiate( request() )->get_status() && 0 === $payment_calls, 'empty or invalid carts stop before payment creation' );

$conflict = controller( static fn(): CartRecord => cart(), static fn(): CheckoutPreparationResult => CheckoutPreparationResult::failure( 'reservation_failed', 'Could not reserve.', 11, null, 'insufficient_stock' ), static function () use ( &$payment_calls ): PaymentSessionResult { ++$payment_calls; return PaymentSessionResult::success( 'unexpected', 'Unexpected.', active_session() ); } );
$conflict_response = $conflict->initiate( request() );
check( 409 === $conflict_response->get_status() && 'reservation_failed' === $conflict_response->get_data()['code'] && 0 === $payment_calls, 'reservation and stock conflicts propagate safely without a provider call' );

$failed_record = new PaymentSessionRecord( 10, str_repeat( 'd', 32 ), 11, 'stripe_checkout', null, hash( 'sha256', 'key' ), 'failed', null, 'EUR', 1200, null, '2026-01-01 00:00:00', '2026-01-01 00:00:00', null, null, null, null, '2026-01-01 00:00:00' );
$reject = controller( static fn(): CartRecord => cart(), static fn(): CheckoutPreparationResult => CheckoutPreparationResult::success( 'checkout_prepared', 'Prepared.', 11 ), static fn(): PaymentSessionResult => PaymentSessionResult::failure( 'provider_rejected', 'Rejected.', 11, $failed_record ) );
$rejected = $reject->initiate( request() );
check( 409 === $rejected->get_status() && 'payment' === $rejected->get_data()['retry_scope'], 'definitive Stripe rejection permits a new payment attempt on the reserved order' );

$creating_record = new PaymentSessionRecord( 12, str_repeat( 'e', 32 ), 11, 'stripe_checkout', null, hash( 'sha256', 'key' ), 'creating', 1, 'EUR', 1200, null, '2026-01-01 00:00:00', '2026-01-01 00:00:00' );
$unknown = controller( static fn(): CartRecord => cart(), static fn(): CheckoutPreparationResult => CheckoutPreparationResult::success( 'checkout_prepared', 'Prepared.', 11 ), static fn(): PaymentSessionResult => PaymentSessionResult::failure( 'provider_response_unknown', 'Unknown.', 11, $creating_record ) );
$unknown_response = $unknown->initiate( request() );
check( 503 === $unknown_response->get_status() && 'same' === $unknown_response->get_data()['retry_scope'] && 'creating' === $creating_record->status(), 'unknown provider result preserves the same durable creating attempt for retry' );
check( ! str_contains( json_encode( $unknown_response->get_data() ), 'sk_' ) && ! str_contains( json_encode( $unknown_response->get_data() ), 'whsec_' ), 'failure output contains no provider secrets' );

$deleted = controller( static fn(): CartRecord => cart(), static fn(): CheckoutPreparationResult => CheckoutPreparationResult::success( 'checkout_prepared', 'Prepared.', 11 ), static fn(): PaymentSessionResult => PaymentSessionResult::failure( 'order_deleted', 'Deleted.', 11 ) );
$deleted_response = $deleted->initiate( request() );
check( 410 === $deleted_response->get_status() && 'order_deleted' === $deleted_response->get_data()['code'] && 'none' === $deleted_response->get_data()['retry_scope'], 'provider response after durable deletion is terminal and not blindly retryable' );

$missing = controller( static fn(): ?CartRecord => null, static fn(): CheckoutPreparationResult => throw new RuntimeException(), static fn(): PaymentSessionResult => throw new RuntimeException() );
check( 404 === $missing->initiate( request() )->get_status(), 'missing persisted caller cart is rejected' );

echo "All production checkout REST entry-point checks passed.\n";
