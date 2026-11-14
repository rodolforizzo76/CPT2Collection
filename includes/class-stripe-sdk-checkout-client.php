<?php
/** Production Stripe PHP SDK boundary. @package CPT2Collection */
declare( strict_types=1 ); namespace RodolfoRizzo\Cpt2Collection; use Stripe\StripeClient; defined( 'ABSPATH' ) || exit;
final class StripeSdkCheckoutClient implements StripeCheckoutClientInterface {
	private StripeClient $client;

	public function __construct( string $secret_key ) { $this->client = new StripeClient( $secret_key ); }

	public function create_checkout_session( array $parameters, array $options ): array {
		$session = $this->client->checkout->sessions->create( $parameters, $options )->toArray();

		return array(
			'payment_intent' => $session['payment_intent'] ?? null,
			'id'         => $session['id'] ?? null,
			'url'        => $session['url'] ?? null,
			'expires_at' => $session['expires_at'] ?? null,
		);
	}
}
