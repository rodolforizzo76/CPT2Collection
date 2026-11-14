<?php
/** Snapshot information ONLY. No connection, owner token or mutable/admitted context. */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
final class ProviderDiscoveryResult {
	public function __construct(
		public readonly string $status, public readonly string $reason,
		public readonly ?ProviderOwnerDiscovery $owner=null, public readonly ?string $fence=null,
		public readonly ?CommerceResetRecord $active_reset=null, public readonly ?CommerceResetRecord $completed_reset=null,
		public readonly ?OrderDeletionRecord $deletion=null, public readonly array $identities=[],
		public readonly bool $duplicate=false
	) {}
	public function attributed():bool { return $this->status==='attributed'; }
	/** Candidate for later fresh normal admission, never permission to mutate. */
	public function continuation():string {
		if(!$this->attributed())return 'stop';
		return $this->fence==='current_ready'&&$this->owner?->lifecycle==='open'?'normal_candidate':'fenced';
	}
}
