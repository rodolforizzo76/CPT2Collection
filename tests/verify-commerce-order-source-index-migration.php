<?php
/** v16 -> v17 structural/recovery coverage; only disposable tables are changed. */
declare( strict_types=1 );
require __DIR__ . '/support/modern-baseline.php';
use RodolfoRizzo\Cpt2Collection\{CommerceBaselineMigration,CommerceBaselinePurgeMigration,CommerceBaselinePurgeRecord,CommerceOrderSourceIndexMigration as Migration,TableNames,Database};

class SourceIndexFaultDb extends wpdb {
	public string $fault = ''; public int $commits = 0; public int $data_writes = 0;
	public function get_row( string $q ): ?object {
		if ( 'rollback' === $this->fault && str_contains( $q, 'SELECT baseline_purge' ) ) { $this->last_error = 'injected read failure'; return null; }
		return parent::get_row( $q );
	}
	public function query( string $q ): int|false {
		if ( preg_match( '/\A\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\b/i', $q ) ) { ++$this->data_writes; }
		if ( str_starts_with( $q, 'ALTER TABLE' ) && str_contains( $q, 'order_source' ) && in_array( $this->fault, array( 'ddl_before', 'ddl_after' ), true ) ) {
			if ( 'ddl_after' === $this->fault ) { parent::query( $q ); }
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
foreach ( array( 'ready', 'pending', 'purge_running', 'purge_completed', 'resetting', 'blocked', 'ddl_before', 'ddl_after', 'begin', 'rollback', 'commit_1', 'commit_2', 'commit_before_1', 'commit_before_2', 'ack_loss', 'wrong_order', 'unique', 'extra_column', 'prefix', 'ignored' ) as $case ) {
	$prefix = 'c2c_source_mig_' . bin2hex( random_bytes( 5 ) ) . '_'; $db = new SourceIndexFaultDb( $prefix ); $t = new TableNames( $db );
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
		$before = baseline_snapshot( $db, true ); $db->commits = 0; $db->data_writes = 0;
		$counter_sql = $db->prepare( 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $t->order_items() );
		$counter = $db->get_var( $counter_sql );
		$product_rows = $db->get_results( 'SELECT * FROM ' . $db->posts . ' ORDER BY ID' );
		if ( ! is_array( $product_rows ) ) { throw new RuntimeException( 'Product preservation fixture unavailable.' ); }
		$products = serialize( $product_rows );
		$malformed = array( 'wrong_order'=>'(source_cart_item_id,order_id)', 'unique'=>'(order_id,source_cart_item_id)', 'extra_column'=>'(order_id,source_cart_item_id,post_id)', 'prefix'=>'(order_id,item_name(4))', 'ignored'=>'(order_id,source_cart_item_id) IGNORED' );
		if ( isset( $malformed[$case] ) ) {
			sql( $db, 'ALTER TABLE ' . $t->order_items() . ' ADD ' . ( 'unique' === $case ? 'UNIQUE ' : '' ) . 'KEY order_source ' . $malformed[$case] );
			check( ! Migration::run( $db ) && ! Migration::verify_structure( $db, $t ), $case . ': same-name malformed index rejected' );
			check( $before === baseline_snapshot( $db, true ), 'malformed rejection preserves all durable rows' ); continue;
		}
		$db->fault = $case;
		if ( 'ack_loss' === $case ) {
			$GLOBALS['wpdb'] = $db; $GLOBALS['baseline_options'] = array( Database::OPTION_SCHEMA_VERSION => '0.16.0' ); $GLOBALS['baseline_lose_ack'] = true;
			Database::maybe_upgrade(); check( '0.16.0' === get_option( Database::OPTION_SCHEMA_VERSION ), 'lost schema acknowledgement retains v16' ); $GLOBALS['baseline_lose_ack'] = false;
		} else { check( Migration::run( $db ) === in_array( $case, array( 'ready', 'pending', 'purge_running', 'purge_completed', 'resetting', 'blocked' ), true ), $case . ': correct success/failure result' ); }
		check( 0 === $db->data_writes, 'migration attempts zero data rewrites or purge writes' );
		if ( in_array( $case, array( 'begin', 'rollback', 'commit_1', 'commit_2', 'commit_before_1', 'commit_before_2' ), true ) ) { check( ! $db->transaction_connection_usable(), 'uncertainty poisons connection' ); }
		$db->close(); $db = new SourceIndexFaultDb( $prefix ); $t = new TableNames( $db );
		if ( 'ack_loss' === $case ) { $GLOBALS['wpdb'] = $db; Database::maybe_upgrade(); check( '0.21.0' === get_option( Database::OPTION_SCHEMA_VERSION ), 'fresh v16 upgrade acknowledges verified current schema' ); }
		check( Migration::run( $db ) && Migration::verify_structure( $db, $t ), $case . ': fresh retry verifies exact nonunique index' );
		check( $before === baseline_snapshot( $db, true ), 'migration preserves baseline/purge/reset/epoch/provider/deletion/operational/stock rows' );
		check( $counter === $db->get_var( $counter_sql ) && $products === serialize( $db->get_results( 'SELECT * FROM ' . $db->posts . ' ORDER BY ID' ) ), 'order-item allocation counter and products preserved' );
		check( Migration::run( $db ) && $before === baseline_snapshot( $db, true ), 'repeated migration performs no data rewrite' );
	} finally { baseline_cleanup( $db ); }
}
echo "All order-source index migration checks passed.\n";
