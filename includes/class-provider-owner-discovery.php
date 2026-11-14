<?php
/** Original native owner DATA, deliberately distinct from CommerceContext/capabilities. */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
final class ProviderOwnerDiscovery {
	public function __construct(
		public readonly int $claim_id, public readonly string $reference, public readonly string $provider,
		public readonly string $installation_id, public readonly int $epoch_id, public readonly string $epoch_identity,
		public readonly int $order_id, public readonly int $payment_session_id, public readonly string $environment,
		public readonly string $lifecycle, public readonly string $claim_disposition, public readonly ?string $tombstoned_at,
		public readonly string $creation_key_hash, public readonly string $idempotency_key, public readonly string $request_sha256
	) {}
}
