<?php
/** Typed reset service outcome, including an explicit fresh-connection verification requirement. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class CommerceResetResult {
	public const STARTED = 'started';
	public const EXISTING = 'existing';
	public const COMPLETED = 'completed';
	public const RUNNING = 'running';
	public const BLOCKED = 'blocked';
	public const PROGRESSED = 'progressed';
	public const NOT_FOUND = 'not_found';
	public const CONFLICT = 'conflict';
	public const STALE = 'stale';
	public const INVALID_REQUEST = 'invalid_request';
	public const INCONSISTENT_STATE = 'inconsistent_state';
	public const DATABASE_ERROR = 'database_error';
	public const TEMPORARILY_UNAVAILABLE = 'temporarily_unavailable';
	public const EPOCH_ID_EXHAUSTED = 'epoch_id_exhausted';
	public const TRANSACTION_OUTCOME_UNKNOWN = 'transaction_outcome_unknown';
	public function __construct( private string $code, private ?CommerceResetRecord $operation = null ) {}
	public function code(): string { return $this->code; }
	public function operation(): ?CommerceResetRecord { return $this->operation; }
	public function is_success(): bool { return in_array( $this->code, array( self::STARTED, self::EXISTING, self::RUNNING, self::COMPLETED, self::BLOCKED, self::PROGRESSED ), true ); }
	public function requires_verification(): bool { return self::TRANSACTION_OUTCOME_UNKNOWN === $this->code; }
}
