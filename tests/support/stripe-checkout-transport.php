<?php
/** Captures actual SDK requests without opening a network connection. */
declare( strict_types=1 );

final class StripeCheckoutTestTransport implements CheckoutTestTransportInterface {
	private array $requests = array();

	public function request( $method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null ) {
		$this->requests[] = array( $method, $absUrl, $headers, $params );

		return array(
			json_encode(
				array(
					'id'         => 'cs_test_sdk_boundary',
					'url'        => 'https://checkout.stripe.com/c/pay/sdk-boundary',
					'expires_at' => $params['expires_at'] ?? 2000000000,
					'ignored'    => 'not returned',
				),
				JSON_THROW_ON_ERROR
			),
			200,
			array(),
		);
	}

	public function assert_checkout_request( string $idempotency_key, array $expected_parameters ): void {
		if ( 1 !== count( $this->requests ) ) {
			throw new RuntimeException( 'Expected one Checkout Session request.' );
		}

		list( $method, $url, $headers, $parameters ) = $this->requests[0];
		if ( 'post' !== $method || 'https://api.stripe.com/v1/checkout/sessions' !== $url || $expected_parameters !== $parameters ) {
			throw new RuntimeException( 'Checkout method, URL or parameters changed.' );
		}
		if ( array( 'Stripe-Version: 2026-07-29.dahlia' ) !== array_values( preg_grep( '/^Stripe-Version:/i', $headers ) ) ) {
			throw new RuntimeException( 'Expected exactly one pinned Checkout Stripe-Version header.' );
		}
		if ( array( 'Idempotency-Key: ' . $idempotency_key ) !== array_values( preg_grep( '/^Idempotency-Key:/i', $headers ) ) ) {
			throw new RuntimeException( 'Checkout idempotency header changed.' );
		}
	}
}
