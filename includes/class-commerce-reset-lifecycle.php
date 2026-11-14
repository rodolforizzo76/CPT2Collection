<?php
/** Explicit pre-finalization lifecycle; completion belongs to the atomic epoch switch. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class CommerceResetLifecycle {
	public const PHASES = array( 'validating', 'retiring_claims', 'terminalizing_events', 'deleting', 'finalizing' );
	public static function identity_valid( string $identity ): bool { return 1 === preg_match( '/\A[0-9a-f]{32}\z/', $identity ); }
	public static function token_valid( string $token ): bool { return strlen( $token ) <= 64 && 1 === preg_match( '/\A[a-z][a-z0-9_]*\z/', $token ); }
	/** Evidence survives blocking; maintenance admission separately requires running. */
	public static function active( CommerceResetRecord $record ): bool {
		return in_array( $record->phase(), self::PHASES, true ) &&
			( ( 'running' === $record->status() && null === $record->error_code() ) ||
			( 'blocked' === $record->status() && null !== $record->error_code() ) ) &&
			1 === $record->active_slot() && null === $record->target_epoch_id() && null === $record->completed_at() &&
			$record->updated_at() >= $record->started_at();
	}
	/** State predicate only; the owning finalization capability is separately required. */
	public static function final_entry( CommerceResetRecord $record ): bool {
		return self::active( $record ) && 'running' === $record->status() && 'deleting' === $record->phase() && 'z1_n_0_done' === $record->checkpoint() && 0 === $record->cursor() && $record->validation_generation() === $record->evidence_generation();
	}
	public static function can_advance( string $from, string $to ): bool {
		$index = array_search( $from, self::PHASES, true );
		return false !== $index && ( self::PHASES[$index + 1] ?? null ) === $to;
	}
}
