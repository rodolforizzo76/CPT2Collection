<?php
/** Real nullable uniqueness and immutable parsing/admitted reads; all inserted rows are test fixtures. */
declare( strict_types=1 );
require __DIR__ . '/support/phase-two-schema.php';
use RodolfoRizzo\Cpt2Collection\{CommercePhaseThreeCheckpointOneMigration,CommercePhaseFourCheckpointOneMigration as Migration,CommerceResetRecord,CommerceResetRepository,TableNames,TransactionManager};
final class ResetReadFailureDb extends wpdb {
	public bool $fail_read = false;
	public function get_results( string $query ): ?array {
		if ( $this->fail_read && str_contains( $query, 'SELECT * FROM ' . $this->prefix . 'c2c_commerce_resets' ) ) { $this->last_error = 'injected read failure'; return null; }
		return parent::get_results( $query );
	}
}
function reset_row( int $number, string $installation, ?int $slot = null ): array {
	return array( 'operation_identity' => str_pad( dechex( $number ), 32, '0', STR_PAD_LEFT ), 'installation_id' => $installation, 'source_epoch_id' => $number, 'source_epoch_identity' => str_repeat( 'a', 31 ) . dechex( $number ), 'target_epoch_identity' => str_pad( dechex( $number + 100 ), 32, '0', STR_PAD_LEFT ), 'target_epoch_id' => null, 'active_slot' => $slot, 'status' => 'validating', 'phase' => 'validating', 'checkpoint' => null, 'cursor' => 0, 'revision' => 0, 'evidence_generation'=>0, 'validation_generation'=>null, 'requested_by' => 7, 'error_code' => null, 'started_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00', 'completed_at' => null );
}
function insert_reset_fixture( wpdb $db, TableNames $t, array $row ): int|false { return $db->insert( $t->commerce_resets(), $row, array_map( static fn( $v ) => is_int( $v ) ? '%d' : '%s', array_values( $row ) ) ); }
$prefix = 'c2c_p41repo_' . bin2hex( random_bytes( 5 ) ) . '_'; $db = new ResetReadFailureDb( $prefix ); $t = new TableNames( $db ); $created = phase_two_schema( $db );
try {
	check( CommercePhaseThreeCheckpointOneMigration::run( $db ) && Migration::run( $db ) && \RodolfoRizzo\Cpt2Collection\CommerceEvidenceGenerationMigration::run( $db ), 'repository fixture migrates through production schema' );
	$installation = $db->get_var( 'SELECT installation_id FROM ' . $t->commerce_control() );
	$row = reset_row( 1, $installation, 1 );
	check( 1 === insert_reset_fixture( $db, $t, $row ), 'canonical active slot 1 accepts first operation fixture' ); $id = $db->insert_id;
	$second = reset_row( 2, $installation, 1 );
	check( false === insert_reset_fixture( $db, $t, $second ) && 1062 === $db->last_errno, 'nullable UNIQUE active slot rejects a second active operation' );
	$second['active_slot'] = null;
	check( 1 === insert_reset_fixture( $db, $t, $second ) && 1 === insert_reset_fixture( $db, $t, reset_row( 3, $installation ) ), 'multiple NULL active slots and target IDs coexist on target MariaDB' );
	foreach ( array( 'operation_identity', 'target_epoch_identity', 'source_epoch_id' ) as $field ) {
		$duplicate = reset_row( 4, $installation ); $duplicate[$field] = $row[$field];
		check( false === insert_reset_fixture( $db, $t, $duplicate ) && 1062 === $db->last_errno, "$field: independent duplicate rejected by complete unique constraint" );
	}
	sql( $db, 'UPDATE ' . $t->commerce_resets() . ' SET target_epoch_id=50 WHERE id=' . $id );
	$duplicate = reset_row( 4, $installation ); $duplicate['target_epoch_id'] = 50;
	check( false === insert_reset_fixture( $db, $t, $duplicate ) && 1062 === $db->last_errno, 'nonnull target epoch ID is globally unique' );
	$record = CommerceResetRecord::from_row( $db->get_row( 'SELECT * FROM ' . $t->commerce_resets() . ' WHERE id=' . $id ) );
	check( $record->id() === $id && 1 === $record->active_slot() && 50 === $record->target_epoch_id() && 0 === $record->revision() && 0 === $record->cursor() && 7 === $record->requested_by(), 'record parses real SQL integer strings and nullable progress fields' );
	$parsed = (object) array_merge( array( 'id' => $id ), $row );
	foreach ( array( 'id' => '18446744073709551615', 'source_epoch_id' => '01', 'target_epoch_id' => 0, 'active_slot' => 2, 'requested_by' => -1, 'cursor' => -1, 'revision' => 1.5, 'operation_identity' => str_repeat( 'A', 32 ), 'installation_id' => 'wrong', 'target_epoch_identity' => $row['source_epoch_identity'], 'status' => '', 'phase' => 'bad phase', 'checkpoint' => str_repeat( 'a', 65 ), 'error_code' => 'SQL exception: secret', 'started_at' => '2026-02-30 00:00:00', 'updated_at' => '0000-01-01 00:00:00', 'completed_at' => 'yesterday' ) as $field => $value ) {
		$bad = clone $parsed; $bad->$field = $value; $rejected = false;
		try { CommerceResetRecord::from_row( $bad ); } catch ( InvalidArgumentException $error ) { $rejected = true; }
		check( $rejected, "$field: malformed record rejected" );
	}
	$bad = clone $parsed; unset( $bad->checkpoint ); $rejected = false;
	try { CommerceResetRecord::from_row( $bad ); } catch ( InvalidArgumentException $error ) { $rejected = true; }
	check( $rejected, 'missing nullable column is not silently treated as NULL' );
	$immutable = CommerceResetRecord::from_row( $parsed ); $parsed->revision = 99;
	check( 0 === $immutable->revision(), 'record snapshot cannot be mutated through source object' );
	$repo = new CommerceResetRepository( $db, $t ); attest_modern_runtime_fixture($db);
	$tx = new TransactionManager( $db, $t );
	check( false === $repo->find_active( true ) && false === $repo->find_by_operation_identity( $row['operation_identity'] ), 'unowned reads and locks rejected' );
	check( $tx->begin() && false === $repo->find_active( true ), 'bare transaction is not admission' ); $tx->rollback();
	check( $tx->begin_commerce_shared(), 'repository reads acquire real commerce admission' );
	check( $repo->find_by_operation_identity( $row['operation_identity'] )?->id() === $id && $repo->find_active( true )?->id() === $id && $repo->find_by_source_epoch( 1 )?->id() === $id && $repo->find_by_target_identity( $row['target_epoch_identity'] )?->id() === $id && $repo->find_by_target_epoch( 50 )?->id() === $id, 'all indexed read paths and active lock return same immutable record' );
	check( 2 === $repo->find_by_source_epoch( 2 )?->source_epoch_id(), 'history reads do not require source epoch to equal current epoch' );
	$other = new wpdb( $prefix ); sql( $other, 'SET SESSION innodb_lock_wait_timeout=1' );
	check( false === $other->query( 'UPDATE ' . $t->commerce_resets() . ' SET revision=revision+1 WHERE id=' . $id ) && 1205 === $other->last_errno, 'active FOR UPDATE lock is held by the admitted owner across calls' ); $other->close();
	check( null === $repo->find_by_target_epoch( 999 ) && false === $repo->find_by_target_epoch( 0 ) && false === $repo->find_by_operation_identity( 'invalid' ), 'absence remains distinct from invalid input' );
	$db->fail_read = true; check( false === $repo->find_active(), 'database failure is distinct from absence' ); $db->fail_read = false;
	check( $tx->commit() && false === $repo->find_active( true ), 'completed admission cannot authorize a surviving lock' );
	sql( $db, 'UPDATE ' . $t->commerce_resets() . " SET installation_id=REPEAT('f',32) WHERE id=" . $id );
	check( $tx->begin_commerce_shared() && false === $repo->find_active( true ), 'foreign installation record fails closed' ); $tx->rollback();
	sql( $db, 'UPDATE ' . $t->commerce_resets() . " SET installation_id='$installation',error_code='raw exception text' WHERE id=" . $id );
	check( $tx->begin_commerce_shared() && false === $repo->find_active(), 'malformed stored record fails closed' ); $tx->rollback();
	check( $tx->begin_commerce_shared(), 'uncertain connection fixture obtains admission' ); $db->poison_transaction();
	check( false === $repo->find_active( true ), 'poisoned connection cannot retain repository admission' );
	$public = array_map( static fn( ReflectionMethod $m ) => $m->name, ( new ReflectionClass( CommerceResetRepository::class ) )->getMethods( ReflectionMethod::IS_PUBLIC ) ); sort( $public );
	$expected = array( '__construct', 'increment_evidence_generation', 'increment_coverage_generation', 'certify_retained', 'delete_operational', 'finalize', 'error', 'resolve', 'start', 'progress', 'validate', 'retire', 'find_for_maintenance', 'find_active', 'find_by_operation_identity', 'find_by_source_epoch', 'find_by_target_epoch', 'find_by_target_identity' ); sort( $expected );
	check( $expected === $public, 'repository exposes only the explicit history and guarded lifecycle contract' );
} finally { $db->close(); $cleanup = new wpdb( $prefix ); foreach ( array_merge( $created, array( $t->order_deletions(), $t->commerce_resets() ) ) as $table ) { $cleanup->query( 'DROP TABLE IF EXISTS ' . $table ); } $cleanup->close(); }
echo "All Phase 4.1 reset-record, repository and uniqueness checks passed.\n";
