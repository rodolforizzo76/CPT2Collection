<?php
/** A1 prerequisite reproduction, not a validator or a production schema change. */
declare( strict_types=1 );
require __DIR__ . '/support/modern-baseline.php';

use RodolfoRizzo\Cpt2Collection\{CommerceBaselineMigration,CommerceBaselinePurgeMigration,CommerceOrderSourceIndexMigration,CartRepository,OrderRepository,CartReconciliationService,TransactionManager,TableNames,UtcClock};

/** Actual rows examined by the tested table, rather than LIMIT's returned rows. */
function examined_rows( array $plan, string $table ): int {
	$total = 0;
	foreach ( $plan as $key => $value ) {
		if ( 'table' === $key && is_array( $value ) && ( $value['table_name'] ?? null ) === $table ) {
			$total += (int) ( ( $value['r_rows'] ?? 0 ) * ( $value['r_loops'] ?? 0 ) );
		} elseif ( is_array( $value ) ) { $total += examined_rows( $value, $table ); }
	}
	return $total;
}

$db = new wpdb( 'c2c_a1_access_' . bin2hex( random_bytes( 5 ) ) . '_' );
$t = new TableNames( $db );
$tx = new TransactionManager( $db, $t );
$now = gmdate( 'Y-m-d H:i:s' );
$size = 257;
try {
	baseline_schema( $db );
	check( CommerceBaselineMigration::run( $db ) && CommerceBaselinePurgeMigration::run( $db ), 'pristine fixture reaches completed modern schema 0.16' );
	$stock = serialize( $db->get_results( 'SELECT * FROM ' . $db->postmeta . ' ORDER BY meta_id' ) );
	check( $tx->begin_commerce_shared(), 'modern fixture owns normal authority' );
	$carts = new CartRepository( $db, $t ); $orders = new OrderRepository( $db, $t );
	$cart = $carts->create( hash( 'sha256', 'A1 access path' ), $now, $now, $now, '2030-01-01 00:00:00' );
	$order = $orders->create( $cart->id(), 'pending', 'EUR', $size * 100, $size * 100, $now, $now, hash( 'sha256', 'A1 order' ), 0, 0 );
	check( false !== $order, 'order has modern cart/revision/generation/attempt identity' );
	$first_source = 0; $last_item = 0; $created = true;
	for ( $n = 1; $n <= $size; ++$n ) {
		$source = $carts->add_item_quantity_locked( $cart->id(), $n, 1 );
		if ( false === $source ) { $created = false; break; }
		$item = $orders->insert_item( $order->id(), $n, 'Snapshot ' . $n, 100, 1, 100, 100, 'EUR', $now, $source->id(), $source->content_version() );
		if ( false === $item ) { $created = false; break; }
		if ( 1 === $n ) { $first_source = $source->id(); }
		$last_item = $item->id();
	}
	check( $created && $last_item > 0, '257 distinct modern sources/snapshots created through guarded repositories' );
	check( $carts->delete_items_locked( $cart->id() ) === $size, 'supported cart clear removes every mutable source' );
	check( $orders->update_status( $order->id(), 'pending', 'paid', $now, $now ) && $tx->commit(), 'fixture commits snapshot and sale state without stock conversion' );
	check( '0' === $db->get_var( 'SELECT COUNT(*) FROM ' . $t->cart_items() ), 'source absence is real, not a reconstructed historical source' );
	// Explicit corruption: not a supported writer or a legacy fixture. Different
	// post_id means order_post cannot prove source identity uniqueness either.
	sql( $db, $db->prepare( 'UPDATE ' . $t->order_items() . ' SET source_cart_item_id=%d WHERE id=%d', $first_source, $last_item ) );
	$probe = $db->prepare( 'SELECT id FROM ' . $t->order_items() . ' WHERE order_id=%d AND source_cart_item_id=%d ORDER BY id LIMIT 2', $order->id(), $first_source );
	check( count( $db->get_results( $probe ) ) === 2, 'duplicate immutable source survives when mutable source is absent' );
	$indexes = array();
	foreach ( $db->get_results( 'SHOW INDEX FROM ' . $t->order_items() ) as $part ) { $indexes[$part->Key_name][(int) $part->Seq_in_index] = $part->Column_name; }
	$covered = false;
	foreach ( $indexes as $parts ) { ksort( $parts ); $covered = $covered || array_slice( array_values( $parts ), 0, 2 ) === array( 'order_id', 'source_cart_item_id' ); }
	check( ! $covered, 'production schema lacks order/source composite access path' );
	$before = baseline_snapshot( $db );
	$plan = json_decode( $db->get_var( 'ANALYZE FORMAT=JSON ' . $probe ), true, 512, JSON_THROW_ON_ERROR );
	$scanned = examined_rows( $plan, $t->order_items() );
	check( $scanned >= $size, 'LIMIT 2 still examines the entire 257-item order on current indexes' );
	echo 'EVIDENCE: current probe examined ' . $scanned . " rows for two results.\n";
	// Install the actual production migration, preserving the explicit contradiction.
	check( CommerceOrderSourceIndexMigration::run( $db ), 'production v17 migration installs the nonunique access path' );
	$probe = str_replace( ' WHERE', ' FORCE INDEX(order_source) WHERE', $probe );
	$plan = json_decode( $db->get_var( 'ANALYZE FORMAT=JSON ' . $probe ), true, 512, JSON_THROW_ON_ERROR );
	$scanned = examined_rows( $plan, $t->order_items() );
	check( 2 === $scanned, 'production composite index bounds the same duplicate probe to two examined rows' );
	echo 'EVIDENCE: production probe examined ' . $scanned . " rows for two results.\n";
	check( CommerceOrderSourceIndexMigration::verify_structure( $db, $t ), 'exact nonunique ordered index verified' );
	check( $before === baseline_snapshot( $db ), 'access-path experiment changes no durable fixture rows' );
	$result = ( new CartReconciliationService( $carts, $orders, $tx, new UtcClock() ) )->reconcile( $order->id() );
	check( $result->is_retryable() && 'continuation_verification_required' === $result->code() && $before === baseline_snapshot($db), 'a shape-only paid Order cannot authorize K11; native duplicate-source refusal is covered by D4' );
	check( $stock === serialize( $db->get_results( 'SELECT * FROM ' . $db->postmeta . ' ORDER BY meta_id' ) ), 'stock remains byte/value equivalent throughout reproduction' );
	check( '0' === $db->get_var( 'SELECT COUNT(*) FROM ' . $t->commerce_resets() ), 'no validation progress, retirement or reset transition is fabricated' );
} finally {
	if ( $tx->is_active() ) { $tx->rollback(); }
	baseline_cleanup( $db );
}
echo "All A1 access-path prerequisite checks passed; access path is installed; this suite does not certify A1 validation.\n";
