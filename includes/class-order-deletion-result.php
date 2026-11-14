<?php
/** Stable result returned by the atomic order-deletion backend. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class OrderDeletionResult {
	public const DELETED = 'deleted';
	public const ALREADY_DELETED = 'already_deleted';
	public const ABSENCE_UNPROVABLE = 'absence_unprovable';
	public const ALREADY_ABSENT = 'already_absent';
	public const EVIDENCE_CONFLICT = 'evidence_conflict';
	public const INCONSISTENT_STATE = 'inconsistent_state';
	public const DATABASE_ERROR = 'database_error';
	public const TRANSACTION_OUTCOME_UNKNOWN = 'transaction_outcome_unknown';

	private function __construct( private string $code, private int $order_id, private bool $success ) {}
	public static function success( string $code, int $order_id ): self { return new self( $code, $order_id, true ); }
	public static function failure( string $code, int $order_id ): self { return new self( $code, $order_id, false ); }
	public function code(): string { return $this->code; }
	public function order_id(): int { return $this->order_id; }
	public function is_success(): bool { return $this->success; }
}
