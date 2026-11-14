<?php
/** Foundation reads. No runtime initialization or epoch transitions. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-commerce-context.php';
require_once __DIR__ . '/class-commerce-reset-lifecycle.php';
require_once __DIR__ . '/class-commerce-reset-context.php';
require_once __DIR__ . '/class-commerce-evidence-context.php';
final class CommerceControlRepository {
	private string $error = '';
	private string $state = '';
	public function state(): string { return $this->state; }
	/** Control-only orchestration read; grants neither normal nor maintenance authority. */
	public function read_reset_control(): ?CommerceContext { return $this->read_foundation( 'FOR UPDATE', 'reset_control' ); }
	public function __construct( private \wpdb $wpdb, private TableNames $tables ) {}
	public function error(): string { return $this->error; }
	/** Call only inside the owning transaction; empty lock is migration verification only. */
	public function read( string $lock = '' ): ?CommerceContext {
		return $this->read_foundation( $lock, 'ready' );
	}
	private function read_foundation( string $lock, string $state ): ?CommerceContext {
		if ( ! in_array( $lock, array( '', 'LOCK IN SHARE MODE', 'FOR UPDATE' ), true ) ) { throw new \InvalidArgumentException( 'Invalid foundation lock' ); }
		$this->error = '';
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( 'SELECT id, installation_id, current_epoch_id, state, updated_at FROM ' . $this->tables->commerce_control() . ' FORCE INDEX (PRIMARY) ORDER BY id LIMIT 2 ' . $lock );
		if ( '' !== $this->wpdb->last_error || ! is_array( $rows ) ) { return $this->reject( 'database_error' ); }
		if ( 1 !== count( $rows ) || '1' !== (string) $rows[0]->id ) { return $this->reject( 'commerce_control_invalid' ); }
		$row = $rows[0];
		$this->state = (string) $row->state;
		if ( 'reset_control' === $state ? ! in_array( $row->state, array( 'ready', 'resetting' ), true ) : $state !== $row->state ) { return $this->reject( 'commerce_not_ready' ); }
		if ( ! ctype_digit( (string) $row->current_epoch_id ) || (int) $row->current_epoch_id < 1 || ! self::identity_valid( $row->installation_id ) || ! self::date_valid( $row->updated_at ) ) { return $this->reject( 'commerce_control_invalid' ); }
		$this->wpdb->last_error = '';
		$epochs = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT id, identity, created_at, retired_at FROM ' . $this->tables->commerce_epochs() . ' WHERE id = %d ' . $lock, (int) $row->current_epoch_id ) );
		if ( '' !== $this->wpdb->last_error || ! is_array( $epochs ) ) { return $this->reject( 'database_error' ); }
		if ( 1 !== count( $epochs ) || (string) $row->current_epoch_id !== (string) $epochs[0]->id || null !== $epochs[0]->retired_at || ! self::identity_valid( $epochs[0]->identity ) || ! self::date_valid( $epochs[0]->created_at ) ) { return $this->reject( 'commerce_epoch_invalid' ); }
		return new CommerceContext( $row->installation_id, (int) $row->current_epoch_id, $epochs[0]->identity );
	}
	/**
	 * Owner-only admission read. Lock order: control, source epoch, reset operation.
	 * Running phases admit maintenance; running and blocked phases admit evidence.
	 * All require a reserved, unallocated target and exact durable snapshot.
	 */
	public function read_reset( CommerceResetRecord $expected ): ?CommerceResetContext {
		$boundary = $this->read_reset_boundary( $expected, 'FOR UPDATE' );
		if ( null !== $boundary && 'running' !== $boundary->operation()->status() ) { $this->reject( 'reset_blocked' ); return null; }
		return $boundary;
	}
	private function read_reset_boundary( CommerceResetRecord $expected, string $lock, ?string $operation_lock = null ): ?CommerceResetContext {
		$foundation = $this->read_foundation( $lock, 'resetting' );
		if ( null === $foundation ) { return null; }
		$this->wpdb->last_error = '';
		// Include all occupied slots to fail closed on a noncanonical/second active row.
		$rows = $this->wpdb->get_results( 'SELECT * FROM ' . $this->tables->commerce_resets() . ' FORCE INDEX(active_slot) WHERE active_slot IS NOT NULL LIMIT 2 ' . ( $operation_lock ?? $lock ) );
		if ( '' !== $this->wpdb->last_error || ! is_array( $rows ) ) { $this->reject( 'database_error' ); return null; }
		if ( 1 !== count( $rows ) ) { $this->reject( 'reset_operation_invalid' ); return null; }
		try { $record = CommerceResetRecord::from_row( $rows[0] ); }
		catch ( \Throwable $error ) { $this->reject( 'reset_operation_invalid' ); return null; }
		if ( $record->installation_id() !== $foundation->installation_id() ||
			$record->source_epoch_id() !== $foundation->epoch_id() ||
			$record->source_epoch_identity() !== $foundation->epoch_identity() ||
			1 !== $record->active_slot() || null !== $record->completed_at() ||
			! CommerceResetLifecycle::active( $record ) ||
			null !== $record->target_epoch_id()
		) { $this->reject( 'reset_operation_invalid' ); return null; }
		if ( ! $record->equals( $expected ) ) { $this->reject( 'reset_snapshot_stale' ); return null; }
		$this->wpdb->last_error = '';
		// Discover only after the operation lock: an earlier consistent read can pin
		// a stale snapshot before an evidence-generation waiter acquires that lock.
		// No target row may exist in this checkpoint. Nonlocking discovery avoids
		// acquiring an epoch lock after the operation; the exclusive control fence
		// serializes foundation writers; a shared fence also excludes those writers.
		// Only the source epoch is referenced/locked.
		$targets = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT id FROM ' . $this->tables->commerce_epochs() . ' WHERE identity=%s', $expected->target_epoch_identity() ) );
		if ( '' !== $this->wpdb->last_error || ! is_array( $targets ) ) { $this->reject( 'database_error' ); return null; }
		if ( array() !== $targets || null !== $expected->target_epoch_id() ) { $this->reject( 'reset_target_invalid' ); return null; }
		return new CommerceResetContext( $record );
	}
	/** Shared control/source, exclusive reset from the first read, then claim; no lock upgrade. */
	public function read_evidence( CommerceEvidenceContext $expected ): ?CommerceEvidenceContext {
		$boundary = $this->read_reset_boundary( $expected->operation(), 'LOCK IN SHARE MODE', 'FOR UPDATE' );
		if ( null === $boundary ) { return null; }
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->tables->provider_claims() . ' WHERE id=%d FOR UPDATE', $expected->claim()->id() ) );
		if ( '' !== $this->wpdb->last_error || ! is_array( $rows ) ) { $this->reject( 'database_error' ); return null; }
		if ( 1 !== count( $rows ) ) { $this->reject( 'historical_claim_invalid' ); return null; }
		try { $claim = ProviderClaimRecord::from_row( $rows[0] ); }
		catch ( \Throwable $error ) { $this->reject( 'historical_claim_invalid' ); return null; }
		$operation = $boundary->operation();
		if ( ! $claim->equals( $expected->claim() ) || $claim->installation_id() !== $operation->installation_id() || $claim->epoch_id() !== $operation->source_epoch_id() || $claim->epoch_identity() !== $operation->source_epoch_identity() || $claim->created_at() > $operation->started_at() ) {
			$this->reject( 'historical_claim_invalid' ); return null;
		}
		return new CommerceEvidenceContext( $operation, $claim );
	}
	private function reject( string $code ): ?CommerceContext { $this->error = $code; return null; }
	private static function identity_valid( mixed $value ): bool { return is_string( $value ) && 1 === preg_match( '/\A[0-9a-f]{32}\z/', $value ); }
	private static function date_valid( mixed $value ): bool {
		if ( ! is_string( $value ) ) { return false; }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value && '0000' !== substr( $value, 0, 4 );
	}
}
