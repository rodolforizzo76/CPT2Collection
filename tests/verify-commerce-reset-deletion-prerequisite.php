<?php
/** Diagnostic prerequisite only: historical whole-A1 predicate loss; erasure remains test-only. */
declare( strict_types=1 );
require __DIR__ . '/support/reset-retirement.php';

use RodolfoRizzo\Cpt2Collection\{CommerceResetValidation, CommerceResetRepository, TableNames, TransactionManager};

$db = new wpdb( 'c2c_45_proof_' . bin2hex( random_bytes( 5 ) ) . '_' );
$t = new TableNames( $db );
try {
	validation_schema( $db );
	$order = payment_fixture( $db );
	$provider = new ModernBoundaryProvider();
	[ $payments ] = payment_service( $db, $provider );
	check( $payments->create( $order, bin2hex( random_bytes( 16 ) ) )->is_success(), 'genuine modern payment activates' );
	$session = $db->get_row( "SELECT * FROM {$t->payment_sessions()} WHERE order_id=$order" );
	$claim = $db->get_row( "SELECT * FROM {$t->provider_claims()} WHERE payment_session_id={$session->id}" );
	$payload = modern_payload( $session->reference, 'evt_45_proof', $claim->installation_id, $claim->epoch_identity );
	check( webhook_for( $db )->handle( $payload, hash( 'sha256', json_encode( $payload ) ) )->status() === 200, 'genuine sale converts stock and reservations' );
	validation_order( $db, 1 );
	$r = retirement_pass( $db, validation_pass( $db, validation_start( $db ) ), 1 );
	check( $r->phase() === 'deleting' && $r->validation_generation() === $r->evidence_generation(), 'production A1 and B reach fresh deleting' );
	$item = $db->get_row( "SELECT * FROM {$t->order_items()} WHERE order_id=$order" );
	$reservation = $db->get_row( "SELECT * FROM {$t->reservations()} WHERE order_item_id={$item->id}" );
	check( $reservation->status === 'converted', 'paid item has its validated converted reservation' );
	$columns = $db->get_col( 'SHOW COLUMNS FROM ' . $t->order_items() );
	check( ! in_array( 'reservation_id', $columns, true ), 'surviving item has no inverse original reservation primary key' );
	$stock = serialize( $db->get_results( 'SELECT * FROM ' . $db->postmeta . ' ORDER BY meta_id' ) );
	$tx = new TransactionManager( $db, $t );
	check( $tx->begin_reset_maintenance( $r ), 'diagnostic transaction owns exact deleting boundary' );
	// Explicit test-only simulation of the planned first three deletion stages.
	// This is not a production deletion service, finalization or reset certificate.
	foreach ( array( 'payment_events', 'payment_sessions' ) as $table ) {
		$rows = $db->get_results( 'SELECT id FROM ' . $t->$table() . ' ORDER BY id LIMIT 100 FOR UPDATE' );
		foreach ( $rows as $row ) { sql( $db, 'DELETE FROM ' . $t->$table() . ' WHERE id=' . (int) $row->id ); }
	}
	check( 1 === $db->query( 'DELETE FROM ' . $t->reservations() . ' WHERE id=' . (int) $reservation->id ), 'test-only bounded reservation removal' );
	check( $tx->commit(), 'test-only absence commits without forging or erasing the strengthened seal' );
	$next = $r;
	$r = validation_bind( $db, $next, (int) $claim->id, 'event', 'evt_45_late', hash( 'sha256', 'late' ), 'checkout.session.completed' );
	check( $r->evidence_generation() !== $r->validation_generation() && $r->phase() === 'deleting', 'real late historical evidence invalidates deleting certification' );
	$prefix = $db->prefix;
	$db->close();
	$db = new wpdb( $prefix );
	$t = new TableNames( $db );
	$r = validation_service( $db )->resume( $r->operation_identity() )->operation();
	$item = $db->get_row( "SELECT * FROM {$t->order_items()} WHERE id={$item->id}" );
	$tx = new TransactionManager( $db, $t );
	check( $db->get_var( "SELECT disposition FROM {$t->provider_claims()} WHERE id={$claim->id}" ) === 'retired', 'historical binding never reopens the retired claim' );
	check( (int) $db->get_var( 'SELECT COUNT(*) FROM ' . $t->reservations() ) === 1, 'reservation stage is genuinely partial' );
	// Invoke the existing read-only semantic predicate diagnostically. Public A1
	// admission intentionally rejects deleting; this does not grant new authority.
	$predicate = new ReflectionMethod( CommerceResetValidation::class, 'order_items' );
	$reason = null;
	try { $predicate->invoke( new CommerceResetValidation( $db, $t, $r ), $item ); }
	catch ( InvalidArgumentException $error ) { $reason = $error->getMessage(); }
	check( $reason === 'validation_reservation_coverage', 'unchanged A1 rejects intentional partial-reset reservation absence' );
	check( null === $db->get_row( "SELECT * FROM {$t->reservations()} WHERE order_item_id={$item->id}" ), 'inverse lookup loses the deleted primary key needed for frontier comparison' );
	check( (int) $db->get_var( 'SELECT COUNT(*) FROM ' . $t->order_deletions() ) === 0, 'permanent order deletion evidence does not supply a reset reservation proof' );
	check( $stock === serialize( $db->get_results( 'SELECT * FROM ' . $db->postmeta . ' ORDER BY meta_id' ) ), 'physical stock after the historical sale stays unchanged' );
	check( ! $tx->begin_commerce_shared(), 'normal commerce remains fenced' );
	check( $r->target_epoch_id() === null && $r->status() === 'running', 'no epoch allocation or reset completion is claimed' );
} finally { baseline_cleanup( $db ); }

echo "All Phase 4.5 prerequisite diagnostic checks passed; operational Phase 4.5 remains unimplemented.\n";
