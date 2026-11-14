<?php
/** Public persisted-cart checkout initiation. @package CPT2Collection */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

use Throwable;

defined( 'ABSPATH' ) || exit;

final class CheckoutRestController {
	public const REST_NAMESPACE = 'cpt2collection/v1';
	public const ROUTE = '/checkout';

	/** @var callable():?CartRecord */
	private $cart;
	/** @var callable(CartRecord,string,string):CheckoutPreparationResult */
	private $checkout;
	/** @var callable(int,string,?CommerceContext,string):PaymentSessionResult */
	private $payments;
	private GuestCsrf $csrf;
 private ?array $response_context=null;

	public function __construct( callable $cart, callable $checkout, callable $payments, GuestCsrf $csrf ) {
		$this->cart     = $cart;
		$this->checkout = $checkout;
		$this->payments = $payments;
		$this->csrf     = $csrf;
	}

	public function register(): void {
		register_rest_route( self::REST_NAMESPACE, self::ROUTE, array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'initiate' ),
			'permission_callback' => array( $this, 'authorize' ),
		) );
	}

	/** @return true|\WP_Error */
	public function authorize( \WP_REST_Request $request ) {
		return $this->csrf->verify_request( $request ) ? true : new \WP_Error( 'cpt2collection_csrf_failed', __( 'The checkout request failed same-origin CSRF validation.', 'cpt2collection' ), array( 'status' => 403 ) );
	}

	public function initiate( \WP_REST_Request $request ): \WP_REST_Response {
  $this->response_context=null;
		$checkout_key = $request->get_param( 'checkout_attempt_key' );
		$payment_key  = $request->get_param( 'payment_attempt_key' );
		if ( ! $this->valid_attempt_key( $checkout_key ) || ! $this->valid_attempt_key( $payment_key ) ) {
			return $this->failure( 'invalid_checkout_attempt', __( 'The checkout attempt is invalid. Please try again.', 'cpt2collection' ), 400, 'checkout' );
		}

  $browser_context=$request->get_param('checkout_context');
  if(!is_string($browser_context)||!preg_match('/\Av1\.[1-9][0-9]{0,10}\.[0-9a-f]{64}\z/',$browser_context))return $this->failure('stale_checkout_context',__('Refresh the cart before checking out.','cpt2collection'),409,'checkout');
		$cart = ( $this->cart )();
		if ( ! $cart instanceof CartRecord ) {
			return $this->failure( 'cart_not_found', __( 'No active cart was found.', 'cpt2collection' ), 404, 'checkout' );
		}

		$prepared = ( $this->checkout )( $cart, 'checkout_' . $checkout_key, $browser_context );
  $this->response_context=$prepared->browser_context();
		if ( ! $prepared->is_success() ) {
			$status = in_array( $prepared->get_code(), array( 'cart_empty', 'checkout_not_allowed', 'invalid_checkout_attempt' ), true ) ? 422 : 409;
			$scope  = in_array( $prepared->get_code(), array( 'transaction_outcome_unknown', 'checkout_already_paid', 'checkout_renewal_blocked' ), true ) ? 'same' : 'checkout';
			return $this->failure( $prepared->get_code(), $prepared->get_message(), $status, $scope );
		}

		try {
			$payment = ( $this->payments )( (int) $prepared->get_order_id(), 'payment_' . $payment_key, $prepared->commerce_context(), $prepared->browser_context()['token']??$browser_context );
		} catch ( Throwable $error ) {
			return $this->failure( 'stripe_configuration_unavailable', __( 'Checkout is temporarily unavailable. Please try again later.', 'cpt2collection' ), 503, 'same' );
		}
		if ( ! $payment->is_success() ) {
			$retry_scope = 'stale_checkout_context' === $payment->get_code() ? 'checkout' : ('order_deleted' === $payment->get_code() ? 'none' : ( 'provider_rejected' === $payment->get_code() ? 'payment' : 'same' ));
			$status      = 'order_deleted' === $payment->get_code() ? 410 : ( in_array( $payment->get_code(), array( 'provider_unavailable', 'provider_response_unknown', 'transaction_outcome_unknown' ), true ) ? 503 : 409 );
			return $this->failure( $payment->get_code(), $payment->get_message(), $status, $retry_scope );
		}

		return new \WP_REST_Response( array(
			'checkout_context' => $this->response_context,
			'code'         => $payment->get_code(),
			'message'      => $payment->get_message(),
			'reference'    => $payment->get_reference(),
			'redirect_url' => $payment->get_redirect_url(),
			'expires_at'   => $payment->get_expires_at(),
		), 200 );
	}

	private function valid_attempt_key( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[0-9a-f]{32}\z/', $value );
	}

	private function failure( string $code, string $message, int $status, string $retry_scope ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'checkout_context'=>$this->response_context, 'code' => $code, 'message' => $message, 'retry_scope' => $retry_scope ), $status );
	}
}
