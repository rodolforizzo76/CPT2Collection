<?php
/** Exact originating native operation DATA. Construction is never admission. */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class CommittedOperation {
	private function __construct(
		public readonly ProviderClaimRecord $claim,
		public readonly ProviderDiscoveryCandidate $candidate,
		public readonly ?PaymentEventReceipt $receipt,
		public readonly string $source
	) {}

	/** Internal precondition: capture the native claim at the originating stage. */
	public static function native_request( ProviderClaimRecord $claim, string $observed_at ): self {
		self::supported( $claim );
		if ( $observed_at < $claim->created_at() ) { throw new \InvalidArgumentException( 'Observation precedes original request.' ); }
		$input = ProviderDiscoveryCandidate::correlated( $claim->provider(), $claim->environment(), ProviderClaimLocator::id( $claim->id() ), [], $observed_at, $claim->installation_id(), $claim->epoch_identity(), $claim->epoch_id(), $claim );
		return new self( $claim, $input, null, 'native_request' );
	}

	/** Precondition: the existing authentication and normalization already succeeded. */
	public static function authenticated_receipt( ProviderClaimRecord $claim, PaymentEventReceipt $receipt, string $observed_at ): self {
		self::native_request( $claim, $observed_at );
		$input = ProviderDiscoveryCandidate::committed_receipt( $claim, $receipt, $observed_at );
		if ( $input->scope !== 'managed' || $input->provider !== $claim->provider() || $input->environment !== $claim->environment()
			|| $input->installation !== $claim->installation_id() || $input->epoch_identity !== $claim->epoch_identity()
			|| $receipt->local_reference() !== $claim->reference() || $receipt->provider_session_id() === null
			|| ! in_array( $receipt->event_type(), ['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.expired'], true ) ) {
			throw new \InvalidArgumentException( 'Contradictory or unsupported original receipt.' );
		}
		return new self( $claim, $input, $receipt, 'authenticated_receipt' );
	}

	private static function supported( ProviderClaimRecord $claim ): void {
		if ( $claim->provider() !== 'stripe_checkout' ) { throw new \InvalidArgumentException( 'Unsupported native operation.' ); }
	}

	/** Expected scope is only a constraint on fresh normal admission. */
	public function expected_context(): CommerceContext {
		return new CommerceContext( $this->claim->installation_id(), $this->claim->epoch_id(), $this->claim->epoch_identity() );
	}

	/** A0 may locate by Event/reference; independently constrain every original coordinate. */
	public function constrain( ProviderDiscoveryResult $truth ): ProviderDiscoveryResult {
		$o = $truth->owner; $c = $this->claim;
		if ( $o === null ) { return $truth; }
		if ( $o->claim_id === $c->id() && $o->reference === $c->reference() && $o->provider === $c->provider()
			&& $o->installation_id === $c->installation_id() && $o->epoch_id === $c->epoch_id() && $o->epoch_identity === $c->epoch_identity()
			&& $o->order_id === $c->order_id() && $o->payment_session_id === $c->payment_session_id() && $o->environment === $c->environment()
			&& $o->creation_key_hash === $c->creation_key_hash() && $o->idempotency_key === $c->idempotency_key()
			&& $o->request_sha256 === hash( 'sha256', $c->request_snapshot() ) ) { return $truth; }
		return new ProviderDiscoveryResult( 'conflict', 'original_operation_mismatch', identities: $truth->identities );
	}
}
