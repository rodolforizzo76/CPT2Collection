<?php
/** Internal stage-specific read contract. D2 supplies Event predicates, D3 financial predicates and D4 cart postconditions. */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
interface OperationalStageInspection {
	/** Called only under a fresh inspection fence and exact current native owner.
	 * Read-only: no DML, transaction bookkeeping, provider HTTP or other side effects.
	 * Read and validate the COMPLETE stage tuple, including immutable operation facts.
	 * Return verification_required for absent/insufficient/stale proof; conflict for
	 * contradictory proof. Equality proves logical state, never transaction causation.
	 * Reentry eligibility is DATA; any later writer needs new exact normal admission.
	 * No generic table/state inference is supplied by the shared inspector.
	 */
	public function inspect( CommittedOperation $operation, \wpdb $db, TableNames $tables ): OperationalInspectionResult;
}
