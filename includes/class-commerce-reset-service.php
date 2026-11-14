<?php
/** Durable reset orchestration and local validation: bounded erasure and atomic epoch completion. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class CommerceResetService {
	public function __construct( private CommerceResetRepository $repository, private TransactionManager $transactions, private UtcClock $clock ) {}

	/** Client identity must survive response loss; requester is part of the immutable request. */
	public function start( string $identity, string $expected_epoch, ?int $requested_by = null ): CommerceResetResult {
		if ( ! CommerceResetLifecycle::identity_valid( $identity ) || ! CommerceResetLifecycle::identity_valid( $expected_epoch ) || ( null !== $requested_by && $requested_by < 1 ) ) { return new CommerceResetResult( 'invalid_request' ); }
		if ( ! $this->transactions->begin_reset_control() ) { return $this->admission_failure(); }
		try {
			$record = $this->repository->resolve( $identity );
			if ( false === $record ) { return $this->rollback_failure( $this->repository->error() ); }
			if ( null !== $record ) {
				if ( $record->source_epoch_identity() !== $expected_epoch || $record->requested_by() !== $requested_by ) { return $this->rollback_failure( 'conflict' ); }
				return $this->finish( 'existing', $record );
			}
			$record = $this->repository->start( $identity, $expected_epoch, $requested_by, $this->clock->now_mysql() );
			if ( false === $record ) { return $this->rollback_failure( $this->repository->error() ); }
			return $this->finish( 'started', $record );
		} catch ( \Throwable $error ) { return $this->rollback_failure( 'database_error' ); }
	}

	/**
	 * Locked durable inspection/resume, including recovery on a FRESH connection.
	 * Does not advance, unblock or grant authority. A locked not_found permits retry
	 * with the SAME identity; an unlocked absence is never used as rollback proof.
	 */
	public function resume( string $identity, ?string $expected_epoch = null ): CommerceResetResult {
		if ( ! CommerceResetLifecycle::identity_valid( $identity ) || ( null !== $expected_epoch && ! CommerceResetLifecycle::identity_valid( $expected_epoch ) ) ) { return new CommerceResetResult( 'invalid_request' ); }
		if ( ! $this->transactions->begin_reset_control() ) { return $this->admission_failure(); }
		try {
			$record = $this->repository->resolve( $identity );
			if ( false === $record ) { return $this->rollback_failure( $this->repository->error() ); }
			if ( null === $record ) { return $this->finish( 'not_found', null ); }
			if ( null !== $expected_epoch && $expected_epoch !== $record->source_epoch_identity() ) { return $this->rollback_failure( 'conflict' ); }
			return $this->finish( $record->status(), $record );
		} catch ( \Throwable $error ) { return $this->rollback_failure( 'database_error' ); }
	}
	/** Administrative read: locked coherent state, no writer authority, confirmed ROLLBACK. */
	public function inspect( string $identity ): CommerceResetResult {
		if ( ! CommerceResetLifecycle::identity_valid( $identity ) ) { return new CommerceResetResult( 'invalid_request' ); }
		if ( ! $this->transactions->begin_reset_inspection() ) { return $this->admission_failure(); }
		try {
			$record = $this->repository->resolve( $identity );
			$code = false === $record ? $this->repository->error() : ( null === $record ? 'not_found' : $record->status() );
			return $this->transactions->rollback() ? new CommerceResetResult( $code, $record instanceof CommerceResetRecord ? $record : null ) : new CommerceResetResult( 'transaction_outcome_unknown' );
		} catch ( \Throwable $error ) { return $this->rollback_failure( 'database_error' ); }
	}
	/** One exact requested revision, one bounded semantic step; never loops or unblocks. */
	public function continue_operation( string $identity, string $source, int $revision ): CommerceResetResult {
		if ( $revision < 0 ) { return new CommerceResetResult( 'invalid_request' ); }
		$read = $this->resume( $identity, $source );
		if ( 'running' !== $read->code() ) { return $read; }
		$record = $read->operation();
		if ( null === $record || $record->revision() !== $revision ) { return new CommerceResetResult( 'stale' ); }
		return match ( $record->phase() ) {
			'validating' => $this->validate( $record ),
			'retiring_claims', 'terminalizing_events' => $this->retire( $record ),
			'deleting' => CommerceResetDeletion::certified_empty( $record ) ? $this->finalize( $record ) : $this->delete_operational( $record ),
			default => new CommerceResetResult( 'inconsistent_state' ),
		};
	}
	public function advance( CommerceResetRecord $expected, string $phase ): CommerceResetResult {
		if ( ! CommerceResetLifecycle::can_advance( $expected->phase(), $phase ) ) { return new CommerceResetResult( 'invalid_request' ); }
		return $this->mutate( $expected, $phase, null, 0, null );
	}
	/** Local historical validation only, under exclusive maintenance admission. */
	public function validate( CommerceResetRecord $expected, int $batch = 100 ): CommerceResetResult {
		if ( $batch < 1 || $batch > 1000 || 'validating' !== $expected->phase() ) { return new CommerceResetResult( 'invalid_request' ); }
		if ( ! $this->transactions->begin_reset_maintenance( $expected ) ) { return $this->admission_failure( true ); }
		try {
			$record = $this->repository->validate( $expected, $batch, $this->clock->now_mysql() );
			if ( false === $record ) { return $this->rollback_failure( $this->repository->error() ); }
			return $this->finish( 'blocked' === $record->status() ? 'blocked' : 'progressed', $record );
		} catch ( \Throwable $error ) { return $this->rollback_failure( 'database_error' ); }
	}
	/** Exactly one bounded B batch, revalidation step or certified phase transition. */
	public function retire( CommerceResetRecord $expected, int $batch = 100 ): CommerceResetResult {
		if ( $batch < 1 || $batch > 1000 || ! in_array( $expected->phase(), array( 'retiring_claims', 'terminalizing_events' ), true ) ) { return new CommerceResetResult( 'invalid_request' ); }
		if ( ! $this->transactions->begin_reset_maintenance( $expected ) ) { return $this->admission_failure( true ); }
		try {
			$record = $this->repository->retire( $expected, $batch, $this->clock->now_mysql() );
			if ( false === $record ) { return $this->rollback_failure( $this->repository->error() ); }
			return $this->finish( 'blocked' === $record->status() ? 'blocked' : 'progressed', $record );
		} catch ( \Throwable $error ) { return $this->rollback_failure( 'database_error' ); }
	}
	/** Post-seal generations certify retained evidence only. */
	public function certify_retained( CommerceResetRecord $expected, int $batch = 100 ): CommerceResetResult {
		if ( $batch < 1 || $batch > 1000 || ! in_array( $expected->phase(), array( 'deleting', 'finalizing' ), true ) ) { return new CommerceResetResult( 'invalid_request' ); }
		if ( ! $this->transactions->begin_reset_maintenance( $expected ) ) { return $this->admission_failure( true ); }
		try {
			$record = $this->repository->certify_retained( $expected, $batch, $this->clock->now_mysql() );
			if ( false === $record ) { return $this->rollback_failure( $this->repository->error() ); }
			return $this->finish( 'blocked' === $record->status() ? 'blocked' : 'progressed', $record );
		} catch ( \Throwable $error ) { return $this->rollback_failure( 'database_error' ); }
	}
	/** Exactly one atomic operational batch, transition, empty certificate or retained step. */
	public function delete_operational( CommerceResetRecord $expected, int $batch = 100 ): CommerceResetResult {
		if ( $batch < 1 || $batch > 1000 || 'deleting' !== $expected->phase() || null === CommerceResetDeletion::position( $expected ) ) { return new CommerceResetResult( 'invalid_request' ); }
		if ( ! $this->transactions->begin_reset_deletion( $expected ) ) { return $this->admission_failure( true ); }
		try {
			$record = $this->repository->delete_operational( $expected, $batch, $this->clock->now_mysql() );
			if ( false === $record ) { return $this->rollback_failure( $this->repository->error() ); }
			return $this->finish( 'blocked' === $record->status() ? 'blocked' : 'progressed', $record );
		} catch ( \Throwable $error ) { return $this->rollback_failure( 'database_error' ); }
	}
	/** Atomic switch or read-only recognition of this exact already-committed switch. */
	public function finalize( CommerceResetRecord $expected ): CommerceResetResult {
		if ( ! $this->transactions->begin_reset_finalization( $expected ) ) { return $this->admission_failure( true ); }
		try {
			$record = $this->repository->finalize( $expected, $this->clock->now_mysql() );
			if ( false === $record ) { return $this->rollback_failure( $this->repository->error() ); }
			return $this->finish( 'blocked' === $record->status() ? 'blocked' : 'completed', $record );
		} catch ( \Throwable $error ) { return $this->rollback_failure( 'database_error' ); }
	}
	public function checkpoint( CommerceResetRecord $expected, ?string $checkpoint, int $cursor ): CommerceResetResult {
		return $this->mutate( $expected, $expected->phase(), $checkpoint, $cursor, null );
	}
	public function block( CommerceResetRecord $expected, string $error_code ): CommerceResetResult {
		return $this->mutate( $expected, $expected->phase(), $expected->checkpoint(), $expected->cursor(), $error_code );
	}
	private function mutate( CommerceResetRecord $expected, string $phase, ?string $checkpoint, int $cursor, ?string $error_code ): CommerceResetResult {
		if ( ! $this->transactions->begin_reset_maintenance( $expected ) ) { return $this->admission_failure( true ); }
		try {
			$record = $this->repository->progress( $expected, $phase, $checkpoint, $cursor, $error_code, $this->clock->now_mysql() );
			if ( false === $record ) { return $this->rollback_failure( $this->repository->error() ); }
			return $this->finish( null === $error_code ? 'progressed' : 'blocked', $record );
		} catch ( \Throwable $error ) { return $this->rollback_failure( 'database_error' ); }
	}
	private function finish( string $code, ?CommerceResetRecord $record ): CommerceResetResult {
		return $this->transactions->commit() ? new CommerceResetResult( $code, $record ) : new CommerceResetResult( 'transaction_outcome_unknown' );
	}
	private function rollback_failure( string $code ): CommerceResetResult {
		if ( 'database_error' === $code && ! $this->transactions->is_outcome_uncertain() && in_array( $this->transactions->last_error_number(), array( 1205, 1213 ), true ) ) { $code = 'temporarily_unavailable'; }
		return new CommerceResetResult( $this->transactions->rollback() ? $code : 'transaction_outcome_unknown' );
	}
	private function admission_failure( bool $progression = false ): CommerceResetResult {
		if ( $this->transactions->is_outcome_uncertain() ) { return new CommerceResetResult( 'transaction_outcome_unknown' ); }
		$error = $this->transactions->admission_error();
		if ( 'database_error' === $error && in_array( $this->transactions->last_error_number(), array( 1205, 1213 ), true ) ) { return new CommerceResetResult( 'temporarily_unavailable' ); }
		if ( 'reset_blocked' === $error ) { return new CommerceResetResult( 'conflict' ); }
		return new CommerceResetResult( in_array( $error, array( 'database_error', 'transactions_not_supported', 'transaction_already_active' ), true ) ? 'database_error' : ( $progression && 'reset_snapshot_stale' === $error ? 'stale' : 'inconsistent_state' ) );
	}
}
