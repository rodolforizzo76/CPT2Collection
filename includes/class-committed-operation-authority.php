<?php
/** D1 positive classification and fresh exact normal foundation; no business work. */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-committed-operation.php';

final class CommittedOperationAuthority {
	public function __construct( private \wpdb $db, private TableNames $tables ) {}

	/** Read-only A0 DATA, preserving its vocabulary, history and deletion precedence. */
	public function classify( CommittedOperation $operation ): ProviderDiscoveryResult {
		return $operation->constrain( (new ProviderRetainedDiscovery($this->db,$this->tables))->discover($operation->candidate) );
	}

	/** On admitted return ONLY, the supplied manager owns a fresh normal transaction.
	 * Caller must preserve its service lock order, revalidate stage facts and end it.
	 * The result is DATA and cannot be reused as a capability. No admission fallback.
	 */
	public function begin( CommittedOperation $operation, TransactionManager $tx ): ProviderDiscoveryResult {
		if (!$tx->uses_connection($this->db)) return new ProviderDiscoveryResult('admission_denied','manager_connection_mismatch');
		if (!$this->db instanceof CommerceConnection || !$this->db->transaction_connection_usable()) return new ProviderDiscoveryResult('transaction_outcome_unknown','connection_unusable');
		if (!$tx->begin_commerce_shared($operation->expected_context())) {
			return new ProviderDiscoveryResult($tx->is_outcome_uncertain()?'transaction_outcome_unknown':'admission_denied',$tx->admission_error());
		}
		try {
			$truth = $operation->constrain((new ProviderRetainedDiscovery($this->db,$this->tables))->read_for_continuation($operation->candidate));
			if ($truth->continuation()==='normal_candidate' && TransactionManager::context_on($this->db)?->equals($operation->expected_context())) {
				return new ProviderDiscoveryResult('admitted','fresh_exact_normal_foundation',$truth->owner,$truth->fence,identities:$truth->identities);
			}
		} catch (\Throwable $error) { $truth = new ProviderDiscoveryResult('database_error','exact_owner_read_failed'); }
		return $tx->rollback() ? $truth : new ProviderDiscoveryResult('transaction_outcome_unknown','admission_rollback_unknown');
	}
}
