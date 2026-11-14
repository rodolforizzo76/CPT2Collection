<?php
/** Immutable per-key retained facts. Missing and unresolved are not creation permission. */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
final class ProviderIdentityDiscovery {
	public function __construct(
		public readonly string $kind, public readonly string $value, public readonly string $classification,
		public readonly ?int $id=null, public readonly ?int $claim_id=null,
		public readonly ?string $binding_state=null, public readonly ?string $evidence_state=null,
		public readonly ?int $observed_epoch_id=null, public readonly ?string $first_seen_at=null,
		public readonly ?string $environment=null, public readonly ?string $payload_sha256=null,
		public readonly ?string $event_type=null, public readonly ?string $disposition=null,
		public readonly ?string $conflict_code=null
	) {}
}
