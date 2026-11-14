<?php
/** Safe next-action DATA, deliberately never an exact transaction COMMIT receipt. */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class OperationalInspectionResult {
	public readonly string $transaction_provenance;
	public function __construct(
		public readonly string $status,
		public readonly string $reason,
		public readonly ?ProviderDiscoveryResult $owner = null
	) {
		if (!in_array($status,['logically_completed','reentry_eligible','fenced','permanently_deleted','conflict','verification_required','database_error','transaction_outcome_unknown'],true)) {
			throw new \InvalidArgumentException('Invalid operational inspection result.');
		}
		$this->transaction_provenance = 'not_provable';
	}
}
