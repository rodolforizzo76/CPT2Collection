<?php
/** Fresh, logically read-only operational inspection. Never replays business work. */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-committed-operation-authority.php';
require_once __DIR__ . '/class-operational-stage-inspection.php';
require_once __DIR__ . '/class-operational-inspection-result.php';

final class OperationalRecoveryInspector {
	private \Closure $connections;
	private \WeakMap $used;
	/** Must return a NEW private CommerceConnection for this installation/table prefix. */
	public function __construct( callable $connections ) {
		$this->connections = \Closure::fromCallable($connections);
		$this->used = new \WeakMap();
	}

	/** Discard the prior transaction connection without querying it, even if poisoned.
	 * The fresh exclusive control fence drains all cooperating prior operational work.
	 * It grants NO capability and takes NO retained/operational row locks afterwards.
	 */
	public function inspect( CommittedOperation $operation, OperationalStageInspection $stage, \wpdb $prior ): OperationalInspectionResult {
		$db = null; $tx = null;
		try {
			$prior->close();
			$fresh = ($this->connections)();
			if (!$fresh instanceof \wpdb || $fresh === $prior || isset($this->used[$fresh])) return new OperationalInspectionResult('verification_required','fresh_connection_required');
			$db = $fresh; $this->used[$db] = true;
			if (!$db instanceof CommerceConnection || !$db->transaction_connection_usable()) return new OperationalInspectionResult('transaction_outcome_unknown','fresh_connection_unusable');
			$tables = new TableNames($db); $tx = new TransactionManager($db,$tables);
			$reader = new ProviderRetainedDiscovery($db,$tables);
			if (!$tx->supports_retained_discovery() || !$reader->indexes_valid()) return new OperationalInspectionResult('database_error','retained_storage_unavailable');
			if (!$tx->begin_continuation_inspection()) return new OperationalInspectionResult($tx->is_outcome_uncertain()?'transaction_outcome_unknown':'database_error','inspection_begin_failed');
			$truth = $operation->constrain($reader->read_for_continuation($operation->candidate));
			if ($truth->status !== 'attributed') {
				$status = match($truth->status) {'conflict','ambiguous'=>'conflict','database_error'=>'database_error','transaction_outcome_unknown'=>'transaction_outcome_unknown',default=>'verification_required'};
				$out = new OperationalInspectionResult($status,$truth->status.':'.$truth->reason,$truth);
			} elseif ($truth->owner->lifecycle === 'permanently_deleted') {
				$out = new OperationalInspectionResult('permanently_deleted','positive_original_deletion',$truth);
			} elseif ($truth->continuation() !== 'normal_candidate') {
				$out = new OperationalInspectionResult('fenced',$truth->fence,$truth);
			} else {
				$result = $stage->inspect($operation,$db,$tables);
				// Boundary/ownership errors belong to shared classification, not stage guesses.
				if (!in_array($result->status,['logically_completed','reentry_eligible','conflict','verification_required','database_error'],true)) throw new \LogicException('Stage returned a boundary outcome.');
				$out = new OperationalInspectionResult($result->status,$result->reason,$truth);
			}
			return $tx->rollback() ? $out : new OperationalInspectionResult('transaction_outcome_unknown','inspection_rollback_unknown');
		} catch (\Throwable $error) {
			if ($tx !== null && !$tx->rollback()) return new OperationalInspectionResult('transaction_outcome_unknown','inspection_end_unknown');
			return new OperationalInspectionResult('database_error','inspection_failed');
		} finally {
			if ($db instanceof \wpdb) $db->close();
		}
	}
}
