<?php
/** Real-InnoDB endpoint-to-OrderDeletionService integration verification. */
declare( strict_types=1 );
if ( ! extension_loaded( 'mysqli' ) || false === getenv( 'C2C_TEST_DB_NAME' ) ) { echo "SKIP: mysqli and C2C_TEST_DB_NAME are required.\n"; exit( 0 ); }

function __( string $message, string $domain = '' ): string { return $message; }
require __DIR__ . '/support/reset-rest.php';
final class WP_REST_Server { public const DELETABLE = 'DELETE'; }
require __DIR__ . '/support/phase-two-schema.php';

use RodolfoRizzo\Cpt2Collection\{CommercePhaseThreeCheckpointOneMigration,OrderDeletionRegistry,OrderDeletionRepository,OrderDeletionRestController,OrderDeletionService,TableNames,TransactionManager,UtcClock};

$prefix = 'c2c_delete_endpoint_' . bin2hex( random_bytes( 4 ) ) . '_'; $db = new wpdb( $prefix ); $tables = new TableNames( $db ); $created = phase_two_schema( $db );
try {
	check( CommercePhaseThreeCheckpointOneMigration::run( $db ), 'endpoint integration deletion schema is available' ); $created[] = $tables->order_deletions();
	attest_modern_runtime_fixture($db); provider_response_schema($db); $created[]=$tables->commerce_resets();
	sql( $db, "CREATE TABLE {$db->postmeta}(meta_id BIGINT PRIMARY KEY,post_id BIGINT,meta_key VARCHAR(255),meta_value LONGTEXT) ENGINE=InnoDB" ); $created[] = $db->postmeta;
	$at = '2026-09-28 12:00:00';
	sql( $db, "INSERT INTO {$db->postmeta} VALUES(1,88,'_cpt2collection_stock_quantity','6')" );
	sql( $db, "INSERT INTO {$tables->carts()}(epoch_id,id,token_hash,content_revision,checkout_generation,created_at,updated_at,last_activity_at,expires_at) VALUES(1,1,REPEAT('c',64),3,1,'$at','$at','$at','2030-01-01')" );
	sql( $db, "INSERT INTO {$tables->orders()}(epoch_id,id,cart_id,cart_revision,checkout_generation,checkout_attempt_hash,cart_reconciliation_status,status,currency,subtotal_minor,total_minor,created_at,updated_at) VALUES(1,1,1,3,1,REPEAT('d',64),'pending','pending','EUR',100,100,'$at','$at')" );
	sql( $db, "INSERT INTO {$tables->order_items()}(id,order_id,post_id,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at) VALUES(1,1,88,'Endpoint item',100,1,100,100,'EUR','$at')" );
	$cart_before = serialize( $db->get_row( "SELECT * FROM {$tables->carts()} WHERE id=1" ) ); $stock_before = serialize( $db->get_results( "SELECT * FROM {$db->postmeta}" ) );
	$service = new OrderDeletionService( new OrderDeletionRepository( $db, $tables ), new OrderDeletionRegistry( $db, $tables ), new TransactionManager( $db, $tables ), new UtcClock() );
	$calls = 0; $controller = new OrderDeletionRestController( static function ( int $order_id, \RodolfoRizzo\Cpt2Collection\CommerceContext $expected ) use ( $service, &$calls ) { ++$calls; return $service->delete( $order_id, $expected ); } );
	$GLOBALS['reset_capable'] = false; $permission = $controller->authorize();
	check( $permission instanceof WP_Error && 0 === $calls && 1 === (int) $db->get_var( "SELECT COUNT(*) FROM {$tables->orders()} WHERE id=1" ), 'unauthorized endpoint request causes zero commerce writes' );
	$GLOBALS['reset_capable'] = true; check( true === $controller->authorize(), 'administrator permission callback succeeds' );
	$before = serialize($db->get_results('SELECT * FROM '.$tables->orders()));
	$stale = new WP_REST_Request(['order_id'=>'1'], ['confirmation'=>'DELETE ORDER C2C-1','expected_context'=>['installation_id'=>str_repeat('a',32),'epoch_id'=>'1','epoch_identity'=>str_repeat('f',32)]]);
	check($controller->delete($stale)->get_status()===500 && serialize($db->get_results('SELECT * FROM '.$tables->orders()))===$before, 'exact-context admission rejects stale identity without mutation');
	$first = $controller->delete( new WP_REST_Request( array( 'order_id' => '1' ), array( 'confirmation' => 'DELETE ORDER C2C-1', 'expected_context' => array( 'installation_id' => str_repeat('a',32), 'epoch_id' => '1', 'epoch_identity' => str_repeat('b',32) ) ) ) );
	check( 200 === $first->get_status() && 'deleted' === $first->get_data()['code'] && 0 === (int) $db->get_var( "SELECT COUNT(*) FROM {$tables->orders()} WHERE id=1" ), 'administrator endpoint reaches the real atomic deletion service' );
	$second = $controller->delete( new WP_REST_Request( array( 'order_id' => '1' ), array( 'confirmation' => 'DELETE ORDER C2C-1', 'expected_context' => array( 'installation_id' => str_repeat('a',32), 'epoch_id' => '1', 'epoch_identity' => str_repeat('b',32) ) ) ) );
	check( 200 === $second->get_status() && 'already_deleted' === $second->get_data()['code'], 'real endpoint replay is idempotent' );
	check( $cart_before === serialize( $db->get_row( "SELECT * FROM {$tables->carts()} WHERE id=1" ) ) && $stock_before === serialize( $db->get_results( "SELECT * FROM {$db->postmeta}" ) ), 'endpoint does not mutate cart or stock' );
} finally {
	foreach ( array_unique( $created ) as $table ) { $db->query( 'DROP TABLE IF EXISTS ' . $table ); } $db->close();
}
echo "All real endpoint integration checks passed.\n";
