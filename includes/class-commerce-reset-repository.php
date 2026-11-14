<?php
/** Durable reset start, locked inspection, maintenance progression and normal history. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-commerce-reset-history.php';

final class CommerceResetRepository {
		private string $error = '';
	public function error(): string { return $this->error; }
	private function fail( string $code ): false { TransactionManager::fail_reset_finalization_on( $this->db ); $this->error = $code; return false; }
	/**
	 * Locked inspection after control -> current epoch. A missing row is meaningful
	 * only here, after the control lock has drained an earlier start transaction.
	 * Completed history is inspection-only and requires the durable epoch-switch evidence.
	 * @return CommerceResetRecord|false|null
	 */
	public function resolve( string $identity ): CommerceResetRecord|false|null {
		$this->error = '';
		$foundation = TransactionManager::reset_control_on( $this->db ) ?? TransactionManager::reset_inspection_on( $this->db );
		if ( null === $foundation || ! CommerceResetLifecycle::identity_valid( $identity ) ) { return $this->fail( 'inconsistent_state' ); }
		$state = TransactionManager::reset_control_state_on( $this->db ) ?? TransactionManager::reset_inspection_state_on( $this->db );
		$this->db->last_error = '';
		$rows = $this->db->get_results( 'SELECT * FROM ' . $this->tables->commerce_resets() . ' FORCE INDEX(active_slot) WHERE active_slot IS NOT NULL LIMIT 2 FOR UPDATE' );
		if ( '' !== $this->db->last_error || ! is_array( $rows ) ) { return $this->fail( 'database_error' ); }
		if ( count( $rows ) > 1 || ( 'ready' === $state && array() !== $rows ) || ( 'resetting' === $state && 1 !== count( $rows ) ) ) { return $this->fail( 'inconsistent_state' ); }
		$active = null;
		if ( $rows ) {
			try { $active = CommerceResetRecord::from_row( $rows[0] ); } catch ( \Throwable $error ) { return $this->fail( 'inconsistent_state' ); }
			if ( ! CommerceResetLifecycle::active( $active ) || $active->installation_id() !== $foundation->installation_id() || $active->source_epoch_id() !== $foundation->epoch_id() || $active->source_epoch_identity() !== $foundation->epoch_identity() ) { return $this->fail( 'inconsistent_state' ); }
			// Discovery only, under the exclusive control fence: no epoch lock after operation.
			$this->db->last_error = '';
			$targets = $this->db->get_results( $this->db->prepare( 'SELECT id FROM ' . $this->tables->commerce_epochs() . ' WHERE identity=%s', $active->target_epoch_identity() ) );
			if ( '' !== $this->db->last_error || ! is_array( $targets ) ) { return $this->fail( 'database_error' ); }
			if ( $targets ) { return $this->fail( 'inconsistent_state' ); }
		}
		$this->db->last_error = '';
		$found = $this->db->get_results( $this->db->prepare( 'SELECT * FROM ' . $this->tables->commerce_resets() . ' WHERE operation_identity=%s LIMIT 2 FOR UPDATE', $identity ) );
		if ( '' !== $this->db->last_error || ! is_array( $found ) ) { return $this->fail( 'database_error' ); }
		if ( count( $found ) > 1 ) { return $this->fail( 'inconsistent_state' ); }
		if ( ! $found ) { return null; }
		try { $record = CommerceResetRecord::from_row( $found[0] ); } catch ( \Throwable $error ) { return $this->fail( 'inconsistent_state' ); }
		if ( null !== $active && $active->equals( $record ) ) { return $record; }
		return $this->completed_history( $record, $foundation ) ? $record : false;
	}


	/** Canonical completed history; later resets may retire its target. Inspection only. */
	private function completed_history( CommerceResetRecord $record, CommerceContext $current ): bool {
		if ( ! CommerceResetHistory::completed_record( $record, $current->installation_id(), $current->epoch_id() ) ) { return $this->fail( 'inconsistent_state' ); }
		// Nonlocking epoch evidence under the already-held exclusive control fence.
		$this->db->last_error = '';
		$epochs = $this->db->get_results( $this->db->prepare( 'SELECT id,identity,created_at,retired_at FROM ' . $this->tables->commerce_epochs() . ' WHERE id IN (%d,%d) ORDER BY id', $record->source_epoch_id(), $record->target_epoch_id() ?? 0 ) );
		if ( '' !== $this->db->last_error || ! is_array( $epochs ) ) { return $this->fail( 'database_error' ); }
		return 2 === count( $epochs ) && CommerceResetHistory::completed( $record, $current->installation_id(), $current->epoch_id(), $epochs[0], $epochs[1] ) ? true : $this->fail( 'inconsistent_state' );
	}

	/** Only the exact connection-owned finalizer may mutate epoch/control/completion state. */
	public function finalize( CommerceResetRecord $expected, string $now ): CommerceResetRecord|false {
		$this->error = '';
		$owned = TransactionManager::reset_finalization_on( $this->db );
		if ( null === $owned || ! ( $owned->equals( $expected ) || $owned->completion_of( $expected ) ) ) { return $this->fail( 'stale' ); }
		$actual = $this->resolve( $owned->operation_identity() );
		if ( ! $actual instanceof CommerceResetRecord || ! $actual->equals( $owned ) ) { return $this->fail( '' !== $this->db->last_error ? 'database_error' : 'stale' ); }
		if ( 'completed' === $actual->status() ) { return $actual; }
		if ( ! CommerceResetLifecycle::final_entry( $actual ) || $actual->revision() === PHP_INT_MAX || ! self::timestamp_valid( $now ) || $now < $actual->updated_at() ) { return $this->fail( 'invalid_request' ); }
		$this->db->last_error = '';
		$source = $this->db->get_row( $this->db->prepare( 'SELECT id,identity,created_at,retired_at FROM ' . $this->tables->commerce_epochs() . ' WHERE id=%d LIMIT 1', $actual->source_epoch_id() ) );
		if ( '' !== $this->db->last_error ) { return $this->fail( 'database_error' ); }
		if ( ! is_object( $source ) || $source->identity !== $actual->source_epoch_identity() || null !== $source->retired_at || ! self::timestamp_valid( $source->created_at ) || $source->created_at > $actual->started_at() ) { return $this->fail( 'inconsistent_state' ); }
		foreach ( CommerceResetDeletion::STAGES as $method ) {
			$this->db->last_error = '';
			$rows = $this->db->get_results( 'SELECT id FROM ' . $this->tables->$method() . ' FORCE INDEX(PRIMARY) ORDER BY id LIMIT 1 FOR UPDATE' );
			if ( '' !== $this->db->last_error || ! is_array( $rows ) ) { return $this->fail( 'database_error' ); }
			if ( $rows ) { return $this->write_final_state( $actual, array( 'status'=>'blocked', 'error_code'=>'deletion_residual_' . $method, 'revision'=>$actual->revision()+1, 'updated_at'=>$now ) ); }
		}
		// No extra epoch locks after reset. Exclusive singleton control serializes ALL
		// foundation writers; PRIMARY reverse discovery is bounded by one retained row.
		$this->db->last_error = '';
		$high = $this->db->get_row( 'SELECT id,identity,created_at,retired_at FROM ' . $this->tables->commerce_epochs() . ' FORCE INDEX(PRIMARY) ORDER BY id DESC LIMIT 1' );
		if ( '' !== $this->db->last_error || ! is_object( $high ) ) { return $this->fail( 'database_error' ); }
		$id = filter_var( $high->id, FILTER_VALIDATE_INT, array( 'options'=>array( 'min_range'=>1 ) ) );
		if ( false === $id || $id === PHP_INT_MAX ) { return $this->fail( 'epoch_id_exhausted' ); }
		if ( (string) $id !== (string) $high->id || $id < $actual->source_epoch_id() || ! self::identity_valid( $high->identity ) || ! self::timestamp_valid( $high->created_at ) || $high->created_at > $now || ( $id === $actual->source_epoch_id() ? $high->identity !== $actual->source_epoch_identity() || null !== $high->retired_at : ! self::timestamp_valid( $high->retired_at ) || $high->retired_at < $high->created_at || $high->retired_at > $now ) ) { return $this->fail( 'inconsistent_state' ); }
		$target = $id + 1; // Safe only after integer overflow check; no float or AUTO_INCREMENT.
		if ( 1 !== $this->db->query( $this->db->prepare( 'INSERT INTO ' . $this->tables->commerce_epochs() . ' (id,identity,created_at,retired_at) VALUES(%d,%s,%s,NULL)', $target, $actual->target_epoch_identity(), $now ) ) ) { return $this->fail( 'database_error' ); }
		if ( 1 !== $this->db->query( $this->db->prepare( 'UPDATE ' . $this->tables->commerce_epochs() . ' SET retired_at=%s WHERE id=%d AND identity=%s AND retired_at IS NULL', $now, $actual->source_epoch_id(), $actual->source_epoch_identity() ) ) ) { return $this->fail( 'database_error' ); }
		if ( 1 !== $this->db->query( $this->db->prepare( 'UPDATE ' . $this->tables->commerce_control() . " SET current_epoch_id=%d,state='ready',updated_at=%s WHERE id=1 AND state='resetting' AND installation_id=%s AND current_epoch_id=%d", $target, $now, $actual->installation_id(), $actual->source_epoch_id() ) ) ) { return $this->fail( 'database_error' ); }
		return $this->write_final_state( $actual, array( 'target_epoch_id'=>$target, 'status'=>'completed', 'phase'=>'completed', 'active_slot'=>null, 'completed_at'=>$now, 'updated_at'=>$now, 'revision'=>$actual->revision()+1 ) );
	}
	/** Completion or residual block preserves checkpoint/cursor/generations and identities. */
	private function write_final_state( CommerceResetRecord $actual, array $changes ): CommerceResetRecord|false {
		$this->db->last_error = '';
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM ' . $this->tables->commerce_resets() . ' WHERE id=%d FOR UPDATE', $actual->id() ) );
		if ( '' !== $this->db->last_error || ! is_object( $row ) || ! CommerceResetRecord::from_row( $row )->equals( $actual ) ) { return $this->fail( 'database_error' ); }
		$next = CommerceResetRecord::from_row( (object) array_merge( get_object_vars( $row ), $changes ) );
		if ( 1 !== $this->db->update( $this->tables->commerce_resets(), $changes, array( 'id'=>$actual->id(), 'revision'=>$actual->revision() ), array_fill( 0, count( $changes ), '%s' ), array( '%d', '%d' ) ) ) { return $this->fail( 'database_error' ); }
		return $next;
	}

	private static function timestamp_valid( mixed $value ): bool {
		if ( ! is_string( $value ) ) { return false; }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value && '0000' !== substr( $value, 0, 4 );
	}

	/** Insert intent and fence atomically; the caller must commit through its owner. */
	public function start( string $identity, string $source, ?int $requested_by, string $now ): CommerceResetRecord|false {
		$context = TransactionManager::reset_control_on( $this->db );
		if ( null === $context || 'ready' !== TransactionManager::reset_control_state_on( $this->db ) || ! CommerceResetLifecycle::identity_valid( $identity ) || $source !== $context->epoch_identity() || ( null !== $requested_by && $requested_by < 1 ) ) { return $this->fail( 'conflict' ); }
		$existing = $this->resolve( $identity );
		if ( false === $existing ) { return false; }
		if ( null !== $existing ) { return $this->fail( 'conflict' ); }
		$this->db->last_error = '';
		$previous = $this->db->get_results( $this->db->prepare( 'SELECT id FROM ' . $this->tables->commerce_resets() . ' WHERE installation_id=%s AND source_epoch_id=%d FOR UPDATE', $context->installation_id(), $context->epoch_id() ) );
		if ( '' !== $this->db->last_error || ! is_array( $previous ) ) { return $this->fail( 'database_error' ); }
		if ( $previous ) { return $this->fail( 'inconsistent_state' ); }
		$target = bin2hex( random_bytes( 16 ) );
		if ( $target === $source ) { return $this->fail( 'database_error' ); }
		foreach ( array( $this->tables->commerce_epochs() => 'identity', $this->tables->commerce_resets() => 'target_epoch_identity' ) as $table => $column ) {
			$this->db->last_error = '';
			$used = $this->db->get_results( $this->db->prepare( "SELECT id FROM $table WHERE $column=%s", $target ) );
			if ( '' !== $this->db->last_error || ! is_array( $used ) || $used ) { return $this->fail( 'database_error' ); }
		}
		$data = array( 'operation_identity'=>$identity, 'installation_id'=>$context->installation_id(), 'source_epoch_id'=>$context->epoch_id(), 'source_epoch_identity'=>$source, 'target_epoch_identity'=>$target, 'target_epoch_id'=>null, 'active_slot'=>1, 'status'=>'running', 'phase'=>'validating', 'checkpoint'=>null, 'cursor'=>0, 'revision'=>0, 'evidence_generation'=>0, 'validation_generation'=>null, 'requested_by'=>$requested_by, 'error_code'=>null, 'started_at'=>$now, 'updated_at'=>$now, 'completed_at'=>null );
		// Validate before SQL, including timestamps and all nullable fields.
		CommerceResetRecord::from_row( (object) array_merge( array( 'id'=>1 ), $data ) );
		if ( false === $this->db->insert( $this->tables->commerce_resets(), $data, array_fill( 0, count( $data ), '%s' ) ) ) { return $this->fail( 'database_error' ); }
		$id = (int) $this->db->insert_id;
		if ( 1 !== $this->db->query( $this->db->prepare( 'UPDATE ' . $this->tables->commerce_control() . " SET state='resetting',updated_at=%s WHERE id=1 AND state='ready' AND installation_id=%s AND current_epoch_id=%d", $now, $context->installation_id(), $context->epoch_id() ) ) ) { return $this->fail( 'database_error' ); }
		return CommerceResetRecord::from_row( (object) array_merge( array( 'id'=>$id ), $data ) );
	}

	/**
	 * Maintenance-only batch primitive. Effects and checkpoint may share the owner's
	 * transaction. One mutation consumes its snapshot; reacquire admission next time.
	 * A phase change clears checkpoint/cursor; blocking retains both. No completion.
	 */
	public function progress( CommerceResetRecord $expected, string $phase, ?string $checkpoint, int $cursor, ?string $error_code, string $now ): CommerceResetRecord|false {
		// Only the semantic validator may create/certify a validating pass. Manual
		// blocking remains available; pre-pass orchestration checkpoints grant no proof.
		if ( 'validating' === $expected->phase() && null === $error_code && ( $phase !== 'validating' || null !== $expected->validation_generation() || ( null !== $checkpoint && str_starts_with( $checkpoint, 'v_' ) ) ) ) { return $this->fail( 'invalid_request' ); }
		if ( in_array( $expected->phase(), array( 'retiring_claims', 'terminalizing_events', 'deleting', 'finalizing' ), true ) && null === $error_code ) { return $this->fail( 'invalid_request' ); }
		return $this->write_progress( $expected, $phase, $checkpoint, $cursor, $error_code, $now, $expected->validation_generation() );
	}

	/** One exact, owned batch; restart and certification use the same durable mutation. */
	public function validate( CommerceResetRecord $expected, int $batch, string $now ): CommerceResetRecord|false {
		$this->error = '';
		$owned = TransactionManager::reset_context_on( $this->db );
		if ( null === $owned || ! $owned->operation()->equals( $expected ) || false === $this->find_for_maintenance( $owned ) ) { return $this->fail( '' !== $this->db->last_error ? 'database_error' : 'stale' ); }
		if ( 'running' !== $expected->status() || 'validating' !== $expected->phase() || $batch < 1 || $batch > 1000 ) { return $this->fail( 'invalid_request' ); }
		$generation = $expected->evidence_generation();
		if ( $expected->validation_generation() !== $generation ) {
			return $this->write_progress( $expected, 'validating', 'v_carts', 0, null, $now, $generation );
		}
		try {
			$next = ( new CommerceResetValidation( $this->db, $this->tables, $expected ) )->batch( $batch );
		} catch ( \InvalidArgumentException $contradiction ) {
			return $this->write_progress( $expected, 'validating', $expected->checkpoint(), $expected->cursor(), $contradiction->getMessage(), $now, $generation );
		}
		return $this->write_progress( $expected, $next[0], $next[1], $next[2], null, $now, $generation );
	}

	/** B preserves its phase while reusing every A1 semantic check and nested position. */
	public function retire( CommerceResetRecord $expected, int $batch, string $now ): CommerceResetRecord|false {
		$owned = TransactionManager::reset_context_on( $this->db );
		if ( null === $owned || ! $owned->operation()->equals( $expected ) || false === $this->find_for_maintenance( $owned ) ) { return $this->fail( '' !== $this->db->last_error ? 'database_error' : 'stale' ); }
		if ( ! in_array( $expected->phase(), array( 'retiring_claims', 'terminalizing_events' ), true ) || $batch < 1 || $batch > 1000 ) { return $this->fail( 'invalid_request' ); }
		if ( is_string( $expected->checkpoint() ) && str_starts_with( $expected->checkpoint(), 's1_' ) ) { return $this->source_seal( $expected, $batch, $now ); }
		try {
			[ $stage, $operational, $validation ] = CommerceResetRetirement::position( $expected );
			if ( ( 'retiring_claims' === $expected->phase() ) !== ( 'c' === $stage ) ) { throw new \InvalidArgumentException( 'retirement_position' ); }
			if ( $expected->validation_generation() !== $expected->evidence_generation() ) {
				return $this->write_progress( $expected, $expected->phase(), CommerceResetRetirement::revalidation( $stage, $operational, 'v_carts' ), 0, null, $now, $expected->evidence_generation() );
			}
			if ( null !== $validation ) {
				if ( 'v_complete' === $validation && 0 === $expected->cursor() ) {
					// Explicit bounded catch-up from the beginning; committed terminal states never reopen.
					return $this->write_progress( $expected, $expected->phase(), 'b_' . ( 'retiring_claims' === $expected->phase() ? 'c' : 'e' ), 0, null, $now, $expected->evidence_generation() );
				}
				$next = ( new CommerceResetValidation( $this->db, $this->tables, $expected ) )->batch( $batch, $validation, $expected->cursor() );
				return $this->write_progress( $expected, $expected->phase(), CommerceResetRetirement::revalidation( $stage, $operational, $next[1] ), $next[2], null, $now, $expected->evidence_generation() );
			}
			$next = ( new CommerceResetRetirement( $this->db, $this->tables, $expected ) )->batch( $stage, $operational, $batch, $now );
		} catch ( \InvalidArgumentException $contradiction ) {
			return $this->write_progress( $expected, $expected->phase(), $expected->checkpoint(), $expected->cursor(), $contradiction->getMessage(), $now, $expected->validation_generation() );
		}
		return $this->write_progress( $expected, $next[0], $next[1], $next[2], null, $now, $expected->validation_generation() );
	}

	/** Coverage, whole A1 before sealing, then retained predicates and terminal catch-up. */
	private function source_seal( CommerceResetRecord $expected, int $batch, string $now ): CommerceResetRecord|false {
		if ( 'terminalizing_events' !== $expected->phase() ) { return $this->fail( 'invalid_request' ); }
		if ( $expected->validation_generation() !== $expected->evidence_generation() ) {
			return $this->write_progress( $expected, $expected->phase(), 's1_s', 0, null, $now, $expected->evidence_generation() );
		}
		try {
			$cp = $expected->checkpoint(); $cursor = 0;
			if ( in_array( $cp, array( 's1_s', 's1_e' ), true ) ) {
				[ $cp, $cursor ] = ( new CommerceResetProviderCoverage( $this->db, $this->tables, $expected ) )->batch( $batch, $now );
			} elseif ( str_starts_with( $cp, 's1_v_' ) ) {
				$position = substr( $cp, 5 );
				if ( 'v_complete' === $position && 0 === $expected->cursor() ) { $cp = 's1_c'; }
				else { $next = ( new CommerceResetValidation( $this->db, $this->tables, $expected ) )->batch( $batch, $position, $expected->cursor() ); $cp = 's1_v_' . $next[1]; $cursor = $next[2]; }
			} elseif ( 's1_done' === $cp && 0 === $expected->cursor() ) {
				return $this->write_progress( $expected, 'deleting', 'z1_e_0_c', 0, null, $now, $expected->evidence_generation() );
			} else { [ $cp, $cursor ] = ( new CommerceResetRetainedEvidence( $this->db, $this->tables, $expected ) )->batch( $batch, 's1_' ); }
		} catch ( \InvalidArgumentException $contradiction ) {
			$live = TransactionManager::reset_context_on( $this->db )->operation();
			return $this->write_progress( $live, $live->phase(), $live->checkpoint(), $live->cursor(), $contradiction->getMessage(), $now, $expected->validation_generation() );
		}
		$live = TransactionManager::reset_context_on( $this->db )->operation();
		return $this->write_progress( $live, $live->phase(), $cp, $cursor, null, $now, $live->evidence_generation() );
	}
	/** The versioned seal marker survives every retained restart; legacy deleting/null is not a seal. */
	public function certify_retained( CommerceResetRecord $expected, int $batch, string $now ): CommerceResetRecord|false {
		$owned = TransactionManager::reset_context_on( $this->db );
		if ( null === $owned || ! $owned->operation()->equals( $expected ) || false === $this->find_for_maintenance( $owned ) ) { return $this->fail( '' !== $this->db->last_error ? 'database_error' : 'stale' ); }
		if ( 'running' !== $expected->status() || ! in_array( $expected->phase(), array( 'deleting', 'finalizing' ), true ) || $batch < 1 || $batch > 1000 || null === CommerceResetDeletion::position( $expected ) ) { return $this->fail( 'invalid_request' ); }
		[ $stage, $physical, $retained ] = CommerceResetDeletion::position( $expected );
		$prefix = CommerceResetDeletion::prefix( $stage, $physical );
		if ( $expected->validation_generation() !== $expected->evidence_generation() ) { return $this->write_progress( $expected, $expected->phase(), $prefix . 'c', 0, null, $now, $expected->evidence_generation() ); }
		if ( 'done' === $retained && 0 === $expected->cursor() ) { return $expected; }
		try { [ $cp, $cursor ] = ( new CommerceResetRetainedEvidence( $this->db, $this->tables, $expected ) )->batch( $batch, $prefix ); }
		catch ( \InvalidArgumentException $contradiction ) { return $this->write_progress( $expected, $expected->phase(), $expected->checkpoint(), $expected->cursor(), $contradiction->getMessage(), $now, $expected->validation_generation() ); }
		return $this->write_progress( $expected, $expected->phase(), $cp, $cursor, null, $now, $expected->evidence_generation() );
	}
	/** One stock-neutral erasure batch OR one retained catch-up step; never finalizes. */
	public function delete_operational( CommerceResetRecord $expected, int $batch, string $now ): CommerceResetRecord|false {
		$owned = TransactionManager::reset_deletion_on( $this->db );
		if ( null === $owned || ! $owned->operation()->equals( $expected ) || false === $this->find_for_maintenance( $owned ) ) { return $this->fail( '' !== $this->db->last_error ? 'database_error' : 'stale' ); }
		$position = CommerceResetDeletion::position( $expected );
		if ( 'running' !== $expected->status() || 'deleting' !== $expected->phase() || null === $position || $batch < 1 || $batch > 1000 ) { return $this->fail( 'invalid_request' ); }
		if ( $expected->validation_generation() !== $expected->evidence_generation() || 'done' !== $position[2] || 0 !== $expected->cursor() ) { return $this->certify_retained( $expected, $batch, $now ); }
		try { $checkpoint = ( new CommerceResetDeletion( $this->db, $this->tables, $expected ) )->batch( $batch ); }
		catch ( \InvalidArgumentException $contradiction ) { return $this->write_progress( $expected, $expected->phase(), $expected->checkpoint(), $expected->cursor(), $contradiction->getMessage(), $now, $expected->validation_generation() ); }
		if ( $checkpoint === $expected->checkpoint() ) { return $expected; }
		return $this->write_progress( $expected, 'deleting', $checkpoint, 0, null, $now, $expected->validation_generation() );
	}
	/** Narrow maintenance counterpart of the historical generation reservation. */
	public function increment_coverage_generation( CommerceResetContext $expected ): CommerceResetRecord|false {
		if ( ! TransactionManager::reset_coverage_identity_mutation_on( $this->db ) || false === $this->find_for_maintenance( $expected ) ) { return $this->fail( 'stale' ); }
		$operation = $expected->operation();
		if ( 'terminalizing_events' !== $operation->phase() || ! in_array( $operation->checkpoint(), array( 's1_s', 's1_e' ), true ) || PHP_INT_MAX === $operation->evidence_generation() ) { return $this->fail( 'invalid_request' ); }
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM ' . $this->tables->commerce_resets() . ' WHERE id=%d FOR UPDATE', $operation->id() ) );
		if ( ! is_object( $row ) || '' !== $this->db->last_error ) { return $this->fail( 'database_error' ); }
		$row->evidence_generation = $operation->evidence_generation() + 1;
		$next = CommerceResetRecord::from_row( $row );
		if ( 1 !== $this->db->query( $this->db->prepare( 'UPDATE ' . $this->tables->commerce_resets() . ' SET evidence_generation=%d WHERE id=%d AND evidence_generation=%d', $next->evidence_generation(), $operation->id(), $operation->evidence_generation() ) ) ) { return $this->fail( 'database_error' ); }
		return $next;
	}

	private function write_progress( CommerceResetRecord $expected, string $phase, ?string $checkpoint, int $cursor, ?string $error_code, string $now, ?int $generation ): CommerceResetRecord|false {
		$this->error = '';
		$owned = TransactionManager::reset_context_on( $this->db );
		if ( null === $owned || ! $owned->operation()->equals( $expected ) ) { return $this->fail( 'stale' ); }
		if ( false === $this->find_for_maintenance( $owned ) ) { return $this->fail( '' !== $this->db->last_error ? 'database_error' : 'stale' ); }
		if ( 'running' !== $expected->status() || $expected->revision() === PHP_INT_MAX || $now < $expected->updated_at() || $cursor < 0 || ( null !== $checkpoint && ! CommerceResetLifecycle::token_valid( $checkpoint ) ) || ( null !== $error_code && ! CommerceResetLifecycle::token_valid( $error_code ) ) ) { return $this->fail( 'invalid_request' ); }
		$seal = 'terminalizing_events' === $expected->phase() && 's1_done' === $expected->checkpoint() && 0 === $expected->cursor() && 'deleting' === $phase && 'z1_e_0_c' === $checkpoint && $generation === $expected->evidence_generation() && $expected->validation_generation() === $generation;
		if ( $phase !== $expected->phase() && ! $seal && ( ! CommerceResetLifecycle::can_advance( $expected->phase(), $phase ) || null !== $checkpoint || 0 !== $cursor || null !== $error_code ) ) { return $this->fail( 'invalid_request' ); }
		if ( null !== $error_code && ( $phase !== $expected->phase() || $checkpoint !== $expected->checkpoint() || $cursor !== $expected->cursor() ) ) { return $this->fail( 'invalid_request' ); }
		$this->db->last_error = '';
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM ' . $this->tables->commerce_resets() . ' WHERE id=%d FOR UPDATE', $expected->id() ) );
		if ( '' !== $this->db->last_error || ! is_object( $row ) ) { return $this->fail( 'database_error' ); }
		$data = array( 'status'=>null === $error_code ? 'running' : 'blocked', 'phase'=>$phase, 'checkpoint'=>$checkpoint, 'cursor'=>$cursor, 'error_code'=>$error_code, 'revision'=>$expected->revision()+1, 'updated_at'=>$now, 'validation_generation'=>$generation );
		$next = CommerceResetRecord::from_row( (object) array_merge( get_object_vars( $row ), $data ) );
		if ( 1 !== $this->db->update( $this->tables->commerce_resets(), $data, array( 'id'=>$expected->id(), 'revision'=>$expected->revision() ), array_fill( 0, count( $data ), '%s' ), array( '%d', '%d' ) ) ) { return $this->fail( 'database_error' ); }
		return $next;
	}

	/** Only the historical binding unit may reserve a generation. The reset lock was acquired before claim/identity locks. */
	public function increment_evidence_generation( CommerceEvidenceContext $expected ): CommerceResetRecord|false {
		$owned = TransactionManager::evidence_context_on( $this->db );
		if ( ! TransactionManager::historical_identity_mutation_on( $this->db ) || null === $owned || ! $owned->equals( $expected ) ) { return $this->fail( 'stale' ); }
		$operation = $owned->operation();
		if ( PHP_INT_MAX === $operation->evidence_generation() ) { return $this->fail( 'evidence_generation_exhausted' ); }
		$this->db->last_error = '';
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM ' . $this->tables->commerce_resets() . ' WHERE id=%d FOR UPDATE', $operation->id() ) );
		if ( '' !== $this->db->last_error || ! is_object( $row ) ) { return $this->fail( 'database_error' ); }
		if ( ! CommerceResetRecord::from_row( $row )->equals( $operation ) ) { return $this->fail( 'stale' ); }
		$row->evidence_generation = $operation->evidence_generation() + 1;
		$next = CommerceResetRecord::from_row( $row );
		if ( 1 !== $this->db->query( $this->db->prepare( 'UPDATE ' . $this->tables->commerce_resets() . ' SET evidence_generation=%d WHERE id=%d AND evidence_generation=%d', $next->evidence_generation(), $operation->id(), $operation->evidence_generation() ) ) ) { return $this->fail( 'database_error' ); }
		return $next;
	}

	public function __construct( private \wpdb $db, private TableNames $tables ) {}

	/**
	 * Exact maintenance-only read. The admission owner already holds control,
	 * source epoch and operation locks; never discover or lock another operation.
	 * Only the exact still-current snapshot is accepted.
	 */
	public function find_for_maintenance( CommerceResetContext $expected ): CommerceResetRecord|false {
		$owned = TransactionManager::reset_context_on( $this->db );
		if ( null === $owned || ! $owned->equals( $expected ) ) { return false; }
		$this->db->last_error = '';
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM ' . $this->tables->commerce_resets() . ' WHERE id=%d FOR UPDATE', $owned->operation()->id() ) );
		if ( '' !== $this->db->last_error || ! is_array( $rows ) || 1 !== count( $rows ) ) { return false; }
		try { $record = CommerceResetRecord::from_row( $rows[0] ); } catch ( \Throwable $error ) { return false; }
		return $owned->operation()->equals( $record ) ? $record : false;
	}

	/** @return CommerceResetRecord|false|null */
	public function find_by_operation_identity( string $identity ) {
		if ( ! self::identity_valid( $identity ) ) { return false; }
		return $this->one( $this->db->prepare( 'operation_identity=%s', $identity ) );
	}
	/** An explicitly owned admitted transaction must retain the lock. @return CommerceResetRecord|false|null */
	public function find_active( bool $for_update = false ) {
		return $this->one( 'active_slot=1', $for_update );
	}
	/** Includes historical source epochs of this installation. @return CommerceResetRecord|false|null */
	public function find_by_source_epoch( int $epoch_id ) {
		$context = TransactionManager::context_on( $this->db );
		if ( null === $context || $epoch_id < 1 ) { return false; }
		return $this->one( $this->db->prepare( 'installation_id=%s AND source_epoch_id=%d', $context->installation_id(), $epoch_id ) );
	}
	/** @return CommerceResetRecord|false|null */
	public function find_by_target_identity( string $identity ) {
		if ( ! self::identity_valid( $identity ) ) { return false; }
		return $this->one( $this->db->prepare( 'target_epoch_identity=%s', $identity ) );
	}
	/** @return CommerceResetRecord|false|null */
	public function find_by_target_epoch( int $epoch_id ) {
		if ( $epoch_id < 1 ) { return false; }
		return $this->one( $this->db->prepare( 'target_epoch_id=%d', $epoch_id ) );
	}

	/**
	 * All reads require current admission, including nonlocking history reads.
	 * No bare transaction, stale context, or runtime resetting admission is accepted.
	 * Orchestration and maintenance use separate guarded methods above.
	 * @return CommerceResetRecord|false|null
	 */
	private function one( string $where, bool $for_update = false ) {
		$context = TransactionManager::context_on( $this->db );
		if ( null === $context ) { return false; }
		$this->db->last_error = '';
		$rows = $this->db->get_results( 'SELECT * FROM ' . $this->tables->commerce_resets() . ' WHERE ' . $where . ' LIMIT 2' . ( $for_update ? ' FOR UPDATE' : '' ) );
		if ( '' !== $this->db->last_error || ! is_array( $rows ) || count( $rows ) > 1 ) { return false; }
		if ( array() === $rows ) { return null; }
		try { $record = CommerceResetRecord::from_row( $rows[0] ); } catch ( \Throwable $error ) { return false; }
		return $record->installation_id() === $context->installation_id() ? $record : false;
	}
	private static function identity_valid( string $identity ): bool { return 1 === preg_match( '/\A[0-9a-f]{32}\z/', $identity ); }
}
