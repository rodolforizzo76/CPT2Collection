<?php
/** Durable cart reconciliation outcome. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class CartReconciliationResult {
	public function __construct( private string $status, private string $code, private bool $already_terminal = false, public readonly ?OperationalInspectionResult $inspection = null, private bool $confirmed_rollback = false ) {}
	public function has_confirmed_rollback(): bool { return $this->confirmed_rollback; }
	/** completed, attention, or retryable_failure. */
	public function status(): string { return $this->status; }
	public function code(): string { return $this->code; }
	public function is_completed(): bool { return 'completed' === $this->status; }
	public function is_already_terminal(): bool { return $this->already_terminal; }
	public function is_retryable(): bool { return 'retryable_failure' === $this->status; }
}
