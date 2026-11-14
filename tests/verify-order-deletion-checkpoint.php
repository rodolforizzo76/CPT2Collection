<?php
/** Focused real-database verification for Milestone 8 Phase 3 Checkpoint 1. */
declare( strict_types=1 );
if ( ! extension_loaded( 'mysqli' ) || false === getenv( 'C2C_TEST_DB_NAME' ) ) { echo "SKIP: mysqli and C2C_TEST_DB_NAME are required.\n"; exit( 0 ); }
function __( string $message, string $domain ): string { return $message; }
require __DIR__ . '/support/phase-two-schema.php';
use RodolfoRizzo\Cpt2Collection\{CartRepository,CommercePhaseThreeCheckpointOneMigration,OrderDeletionRecord,OrderDeletionRegistry,OrderRecord,OrderRepository,TableNames,TransactionManager};

final class InterruptedCheckpointDb extends wpdb {
	public bool $interrupt_ddl = false;
	public function query( string $query ): int|false {
		$result = parent::query( $query );
		if ( $this->interrupt_ddl && str_starts_with( $query, 'CREATE TABLE IF NOT EXISTS' ) && str_contains( $query, 'c2c_order_deletions' ) ) { $this->interrupt_ddl = false; $this->last_error = 'injected result loss after DDL'; return false; }
		return $result;
	}
}
function cp1_snapshot( wpdb $db, TableNames $tables ): string {
	$data = array(); foreach ( array( 'commerce_control', 'commerce_epochs', 'provider_claims', 'provider_identities', 'carts', 'orders', 'reservations', 'payment_sessions', 'payment_events' ) as $method ) { $data[$method] = $db->get_results( 'SELECT * FROM ' . $tables->$method() . ' ORDER BY id' ); }
	$data['stock'] = $db->get_results( 'SELECT * FROM ' . $db->postmeta . ' ORDER BY meta_id' ); return serialize( $data );
}

$prefix = 'c2c_cp1_' . bin2hex( random_bytes( 5 ) ) . '_'; $db = new InterruptedCheckpointDb( $prefix ); $tables = new TableNames( $db ); $created = phase_two_schema( $db );
try {
	$at = '2026-09-28 12:00:00';
	sql( $db, "CREATE TABLE {$db->postmeta}(meta_id BIGINT PRIMARY KEY,post_id BIGINT,meta_key VARCHAR(255),meta_value LONGTEXT) ENGINE=InnoDB" );
	sql( $db, "INSERT INTO {$db->postmeta} VALUES(1,10,'_cpt2collection_stock_quantity','7')" );
	sql( $db, "INSERT INTO {$tables->carts()}(epoch_id,id,token_hash,content_revision,checkout_generation,created_at,updated_at,last_activity_at,expires_at) VALUES(1,1,REPEAT('c',64),4,2,'$at','$at','$at','2030-01-01')" );
	sql( $db, "INSERT INTO {$tables->orders()}(epoch_id,id,cart_id,cart_revision,checkout_generation,checkout_attempt_hash,status,currency,subtotal_minor,total_minor,created_at,updated_at) VALUES(1,1,1,4,2,REPEAT('d',64),'pending','EUR',100,100,'$at','$at')" );
	sql( $db, "INSERT INTO {$tables->order_items()}(id,order_id,post_id,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at) VALUES(1,1,10,'Snapshot',100,1,100,100,'EUR','$at')" );
	$before = cp1_snapshot( $db, $tables );
	$db->interrupt_ddl = true; check( ! CommercePhaseThreeCheckpointOneMigration::run( $db ), 'interrupted migration reports failure after committed fence' );
	$db->close(); $db = new wpdb( $prefix ); $tables = new TableNames( $db ); $tx = new TransactionManager( $db, $tables );
	check( ! $tx->begin_commerce_shared() && 'baseline_unavailable' === $tx->admission_error(), 'interrupted migration keeps commerce fail-closed' );
	check( CommercePhaseThreeCheckpointOneMigration::run( $db ), '0.11.0 to 0.12.0 migration resumes successfully' );
	check( CommercePhaseThreeCheckpointOneMigration::verify_structure( $db, $tables ), 'deletion table structure and indexes verify' );
	check( 0 === (int) $db->get_var( 'SELECT COUNT(*) FROM ' . $tables->order_deletions() ), 'migration fabricates no historical deletion records' );
	check( $before === cp1_snapshot( $db, $tables ), 'migration preserves ownership, provider, operational, payment, and stock evidence' );
	check( CommercePhaseThreeCheckpointOneMigration::run( $db ) && $before === cp1_snapshot( $db, $tables ), 'repeated migration initialization is idempotent' );

	attest_modern_runtime_fixture($db);
	$transactions = new TransactionManager( $db, $tables ); $carts = new CartRepository( $db, $tables ); $orders = new OrderRepository( $db, $tables ); $registry = new OrderDeletionRegistry( $db, $tables );
	check( $transactions->begin_commerce_shared(), 'registry fixture enters admitted transaction' );
	$cart = $carts->find_for_update( 1 ); $order = $orders->find_for_update( 1 );
	$record = $order ? $registry->persist( $order, $at ) : false;
	check( $record instanceof OrderDeletionRecord && 1 === $record->original_order_id() && 'revision' === $record->checkout_disposition(), 'production registry persists durable modern deletion identity' );
	check( $transactions->commit(), 'durable identity transaction commits' );
	check( $transactions->begin_commerce_shared(), 'duplicate fixture enters admitted transaction' ); $carts->find_for_update( 1 ); $order = $orders->find_for_update( 1 );
	$duplicate = $order ? $registry->persist( $order, $at ) : false;
	check( $duplicate instanceof OrderDeletionRecord && $duplicate->id() === $record->id() && 1 === (int) $db->get_var( 'SELECT COUNT(*) FROM ' . $tables->order_deletions() ), 'duplicate deletion identity converges deterministically' );
	check( $transactions->commit(), 'duplicate convergence transaction commits' );

	check( $transactions->begin_commerce_shared(), 'wrong-epoch fixture enters admitted transaction' ); $carts->find_for_update( 1 );
	$wrong_epoch = OrderRecord::from_row( (object) array( 'epoch_id' => 2, 'id' => 1, 'cart_id' => 1, 'cart_revision' => 4, 'checkout_generation' => 2, 'checkout_attempt_hash' => str_repeat( 'd', 64 ), 'status' => 'pending', 'currency' => 'EUR', 'subtotal_minor' => 100, 'total_minor' => 100, 'created_at' => $at, 'updated_at' => $at, 'paid_at' => null, 'cancelled_at' => null, 'expired_at' => null, 'failed_at' => null ) );
	check( false === $registry->persist( $wrong_epoch, $at ), 'wrong epoch ownership fails closed' ); $transactions->rollback();
	sql( $db, 'UPDATE ' . $tables->order_deletions() . " SET installation_id=REPEAT('f',32) WHERE id=" . $record->id() );
	check( $transactions->begin_commerce_shared(), 'ownership contradiction fixture enters admitted transaction' );
	check( false === $registry->find_owned_order( 1, true ), 'wrong installation ownership fails closed' ); $transactions->rollback();
} finally {
	$db->close(); $cleanup = new wpdb( $prefix );
	foreach ( array_unique( array_merge( $created, array( $tables->order_deletions(), $cleanup->postmeta ) ) ) as $table ) { $cleanup->query( 'DROP TABLE IF EXISTS ' . $table ); }
	$cleanup->close();
}
echo "All Phase 3 Checkpoint 1 migration and registry checks passed.\n";
