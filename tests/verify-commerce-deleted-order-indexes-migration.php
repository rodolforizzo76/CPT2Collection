<?php
/** v18 -> v19 structural/recovery coverage; only disposable tables are changed. */
declare( strict_types=1 );
require __DIR__ . '/support/modern-baseline.php';
use RodolfoRizzo\Cpt2Collection\{CommerceBaselineMigration,CommerceBaselinePurgeMigration,CommerceBaselinePurgeRecord,CommerceOrderSourceIndexMigration,CommerceClaimSessionIndexMigration,CommerceDeletedOrderIndexesMigration as Migration,TableNames,Database};

class DeletedIndexesFaultDb extends wpdb {
	public string $fault = ''; public int $commits = 0; public int $data_writes = 0;
	public function get_row( string $q ): ?object {
		if ( 'rollback' === $this->fault && str_contains( $q, 'SELECT baseline_purge' ) ) { $this->last_error = 'injected read failure'; return null; }
		return parent::get_row( $q );
	}
	public function query( string $q ): int|false {
		if ( preg_match( '/\A\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\b/i', $q ) ) { ++$this->data_writes; }
		if ( str_starts_with( $q, 'ALTER TABLE' ) && str_contains( $q, 'ADD KEY' ) && in_array( $this->fault, array( 'ddl_before', 'ddl_after', 'ddl_second_before', 'ddl_second_after' ), true ) && ( ! str_starts_with( $this->fault, 'ddl_second' ) || str_contains( $q, 'ADD KEY order_id' ) ) ) {
			if ( in_array( $this->fault, array( 'ddl_after','ddl_second_after' ), true ) ) { parent::query( $q ); }
			$this->last_error = 'DDL result lost'; return false;
		}
		if ( 'START TRANSACTION' === $q && 'begin' === $this->fault ) { parent::query( $q ); $this->last_error = 'BEGIN result lost'; return false; }
		if ( 'ROLLBACK' === $q && 'rollback' === $this->fault ) { parent::query( $q ); $this->last_error = 'ROLLBACK result lost'; return false; }
		if ( 'COMMIT' === $q ) {
			++$this->commits;
			if ( $this->fault === 'commit_before_' . $this->commits ) { $this->last_error = 'COMMIT not executed'; return false; }
			if ( $this->fault === 'commit_' . $this->commits ) { parent::query( $q ); $this->last_error = 'COMMIT result lost'; return false; }
		}
		return parent::query( $q );
	}
}
foreach ( array( 'ready', 'pending', 'purge_running', 'purge_completed', 'resetting', 'blocked', 'ddl_before', 'ddl_after', 'ddl_second_before', 'ddl_second_after', 'partial_deletion', 'partial_claim', 'begin', 'rollback', 'commit_1', 'commit_2', 'commit_before_1', 'commit_before_2', 'ack_loss', 'wrong_column', 'unique', 'extra_column', 'prefix', 'ignored', 'del_wrong_column', 'del_unique', 'del_extra_column', 'del_prefix', 'del_ignored', 'partial_bad_claim', 'partial_bad_deletion' ) as $case ) {
	$prefix = 'c2c_deleted_indexes_' . bin2hex( random_bytes( 5 ) ) . '_'; $db = new DeletedIndexesFaultDb( $prefix ); $t = new TableNames( $db );
	try {
		if ( in_array( $case, array( 'pending', 'purge_running', 'resetting', 'blocked' ), true ) ) {
			modern_maintenance_schema( $db );
			sql( $db, "CREATE TABLE {$db->posts}(ID BIGINT PRIMARY KEY,post_type VARCHAR(32)) ENGINE=InnoDB" );
			sql( $db, "INSERT INTO {$db->posts} VALUES(10,'collection_item')" );
			if ( in_array( $case, array( 'pending', 'purge_running' ), true ) ) {
				// Test-only preparation preserves populated disposable history.
				$b = json_decode( $db->get_var( 'SELECT baseline_contract FROM ' . $t->commerce_control() ), true );
				$b['status'] = 'prepared'; $b['first_epoch_id'] = 2; $b['first_epoch_identity'] = str_repeat( 'a', 32 );
				sql( $db, $db->prepare( 'UPDATE ' . $t->commerce_control() . " SET state='baseline_pending',baseline_contract=%s", json_encode( $b ) ) );
			} else {
				maintenance_fixture( $db ); sql( $db, "UPDATE {$t->commerce_control()} SET state='resetting'" );
				if ( 'blocked' === $case ) { sql( $db, "UPDATE {$t->commerce_resets()} SET status='blocked',error_code='fixture_integrity'" ); }
			}
		} else { baseline_schema( $db ); check( CommerceBaselineMigration::run( $db ), $case . ': completed pristine v15 baseline' ); }
		check( CommerceBaselinePurgeMigration::run( $db ), $case . ': v16 fixture' );
		if ( 'purge_running' === $case ) {
			$source = $db->get_row( 'SELECT * FROM ' . $t->commerce_epochs() . ' WHERE id=1' );
			$record = CommerceBaselinePurgeRecord::initial( $b, $source );
			sql( $db, $db->prepare( 'UPDATE ' . $t->commerce_control() . ' SET baseline_purge=%s', $record->json() ) );
		}
		if ( 'purge_completed' === $case ) {
			// Test-only durable completion history; no purge is executed by this suite.
			$b = json_decode( $db->get_var( 'SELECT baseline_contract FROM ' . $t->commerce_control() ), true );
			$b['status'] = 'prepared'; $b['first_epoch_id'] = 2; $b['first_epoch_identity'] = str_repeat( 'd', 32 );
			$record = CommerceBaselinePurgeRecord::initial( $b, $db->get_row( 'SELECT * FROM ' . $t->commerce_epochs() . ' WHERE id=1' ) )->next( 10, 0, true );
			$now = gmdate( 'Y-m-d H:i:s' ); $b['status'] = 'completed';
			sql( $db, $db->prepare( 'UPDATE ' . $t->commerce_epochs() . ' SET retired_at=%s WHERE id=1', $now ) );
			sql( $db, $db->prepare( 'INSERT INTO ' . $t->commerce_epochs() . ' VALUES(2,%s,%s,NULL)', $b['first_epoch_identity'], $now ) );
			sql( $db, $db->prepare( 'UPDATE ' . $t->commerce_control() . ' SET current_epoch_id=2,baseline_contract=%s,baseline_purge=%s', json_encode( $b ), $record->json() ) );
		}
		check( CommerceOrderSourceIndexMigration::run( $db ) && CommerceClaimSessionIndexMigration::run( $db ), $case . ': verified v17/v18 prerequisites' );
		$before = baseline_snapshot( $db, true ); $db->commits = 0; $db->data_writes = 0;
		$counter_sql = $db->prepare( 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $t->provider_claims() );
		$counter = $db->get_var( $counter_sql );
		$deletion_counter_sql = $db->prepare( 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $t->order_deletions() );
		$deletion_counter = $db->get_var( $deletion_counter_sql );
		$product_rows = $db->get_results( 'SELECT * FROM ' . $db->posts . ' ORDER BY ID' );
		if ( ! is_array( $product_rows ) ) { throw new RuntimeException( 'Product preservation fixture unavailable.' ); }
		$products = serialize( $product_rows );
		$bad_case = str_starts_with( $case, 'del_' ) ? substr( $case, 4 ) : $case;
		$target = str_starts_with( $case, 'del_' ) || 'partial_bad_deletion' === $case ? 'order_deletions' : 'provider_claims';
		$column = Migration::INDEXES[$target];
		$malformed = array( 'wrong_column'=>'(id)', 'unique'=>'(' . $column . ')', 'extra_column'=>'(' . $column . ',epoch_id)', 'prefix'=> 'provider_claims' === $target ? '(reference(4))' : '(checkout_attempt_hash(4))', 'ignored'=>'(' . $column . ') IGNORED' );
		if ( str_starts_with( $case, 'partial_bad_' ) ) {
			$other = 'provider_claims' === $target ? 'order_deletions' : 'provider_claims'; $other_column = Migration::INDEXES[$other];
			sql( $db, 'ALTER TABLE ' . $t->$other() . ' ADD KEY ' . $other_column . ' (' . $other_column . ')' ); $bad_case = 'wrong_column';
		}
		if ( isset( $malformed[$bad_case] ) ) {
			sql( $db, 'ALTER TABLE ' . $t->$target() . ' ADD ' . ( 'unique' === $bad_case ? 'UNIQUE ' : '' ) . 'KEY ' . $column . ' ' . $malformed[$bad_case] );
			$structure_before = serialize( array( $db->get_results( 'SHOW INDEX FROM ' . $t->order_deletions() ), $db->get_results( 'SHOW INDEX FROM ' . $t->provider_claims() ) ) );
			check( ! Migration::run( $db ) && ! Migration::verify_structure( $db, $t ), $case . ': malformed key rejected independently before other DDL' );
			check( $structure_before === serialize( array( $db->get_results( 'SHOW INDEX FROM ' . $t->order_deletions() ), $db->get_results( 'SHOW INDEX FROM ' . $t->provider_claims() ) ) ), 'malformed preflight performs no other index installation' );
			check( $before === baseline_snapshot( $db, true ), 'malformed rejection preserves all durable rows' ); continue;
		}
		if ( in_array( $case, array( 'partial_deletion','partial_claim' ), true ) ) {
			$target = 'partial_deletion' === $case ? 'order_deletions' : 'provider_claims'; $column = Migration::INDEXES[$target];
			sql( $db, 'ALTER TABLE ' . $t->$target() . ' ADD KEY ' . $column . ' (' . $column . ')' );
		}
		$db->fault = $case;
		if ( 'ready' === $case ) {
			$GLOBALS['wpdb'] = $db; $GLOBALS['baseline_options'] = array( Database::OPTION_SCHEMA_VERSION => '0.18.0' );
			Database::maybe_upgrade(); check( '0.21.0' === get_option( Database::OPTION_SCHEMA_VERSION ) && Migration::verify_structure( $db, $t ), 'normal Database v18 upgrade reaches v21 with exact v19 structure' );
		}
		if ( 'ack_loss' === $case ) {
			$GLOBALS['wpdb'] = $db; $GLOBALS['baseline_options'] = array( Database::OPTION_SCHEMA_VERSION => '0.18.0' ); $GLOBALS['baseline_lose_ack'] = true;
			Database::maybe_upgrade(); check( '0.18.0' === get_option( Database::OPTION_SCHEMA_VERSION ), 'lost schema acknowledgement retains v18' ); $GLOBALS['baseline_lose_ack'] = false;
		} else { check( Migration::run( $db ) === in_array( $case, array( 'ready', 'pending', 'purge_running', 'purge_completed', 'resetting', 'blocked', 'partial_deletion', 'partial_claim' ), true ), $case . ': correct success/failure result' ); }
		check( 0 === $db->data_writes, 'migration attempts zero data rewrites or purge writes' );
		if ( in_array( $case, array( 'begin', 'rollback', 'commit_1', 'commit_2', 'commit_before_1', 'commit_before_2' ), true ) ) { check( ! $db->transaction_connection_usable(), 'uncertainty poisons connection' ); }
		$db->close(); $db = new DeletedIndexesFaultDb( $prefix ); $t = new TableNames( $db );
		if ( 'ack_loss' === $case ) { $GLOBALS['wpdb'] = $db; Database::maybe_upgrade(); check( '0.21.0' === get_option( Database::OPTION_SCHEMA_VERSION ), 'fresh v18 upgrade reaches v21' ); }
		check( Migration::run( $db ) && Migration::verify_structure( $db, $t ), $case . ': fresh retry verifies exact nonunique index' );
		check( $before === baseline_snapshot( $db, true ), 'migration preserves baseline/purge/reset/epoch/provider/deletion/operational/stock rows' );
		check( $counter === $db->get_var( $counter_sql ) && $deletion_counter === $db->get_var( $deletion_counter_sql ) && $products === serialize( $db->get_results( 'SELECT * FROM ' . $db->posts . ' ORDER BY ID' ) ), 'both allocation counters and products preserved' );
		check( Migration::run( $db ) && $before === baseline_snapshot( $db, true ), 'repeated migration performs no data rewrite' );
	} finally { baseline_cleanup( $db ); }
}
echo "All deleted-order indexes migration checks passed.\n";
