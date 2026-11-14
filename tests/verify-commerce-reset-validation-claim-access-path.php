<?php
/** A1 second prerequisite: retained native claims cannot reuse an original session. */
declare( strict_types=1 );
require __DIR__ . '/support/modern-baseline.php';
use RodolfoRizzo\Cpt2Collection\{CommerceBaselineMigration,CommerceBaselinePurgeMigration,CommerceOrderSourceIndexMigration,CommerceClaimSessionIndexMigration,CartRepository,OrderRepository,ReservationRepository,PaymentSessionRepository,ProviderRegistry,ProviderClaimRecord,PaymentProviderSessionRequest,PaymentProviderLineItem,OrderDeletionRecord,OrderDeletionRepository,OrderDeletionRegistry,OrderDeletionService,TransactionManager,TableNames,UtcClock};

$db = new wpdb( 'c2c_a1_claim_path_' . bin2hex( random_bytes( 5 ) ) . '_' ); $t = new TableNames( $db ); $tx = new TransactionManager( $db, $t );
try {
	baseline_schema( $db );
	check( CommerceBaselineMigration::run( $db ) && CommerceBaselinePurgeMigration::run( $db ) && CommerceOrderSourceIndexMigration::run( $db ), 'fixture has completed modern baseline and verified schema 0.17 access path' );
	$carts = new CartRepository( $db, $t ); $orders = new OrderRepository( $db, $t ); $sessions = new PaymentSessionRepository( $db, $t ); $registry = new ProviderRegistry( $db, $t );
	$reservations = new ReservationRepository( $db, $t );
	// Current DELETE requires the existing v19 global deletion sentinel; keep this test's target index absent below.
	sql($db,'ALTER TABLE '.$t->order_deletions().' ADD KEY original_order_id(original_order_id)');
	$delete = new OrderDeletionService( new OrderDeletionRepository( $db, $t ), new OrderDeletionRegistry( $db, $t ), $tx, new UtcClock() );
	$stock = serialize( $db->get_results( 'SELECT * FROM ' . $db->postmeta . ' ORDER BY meta_id' ) );
	$first_session = 0; $last_claim = 0; $size = 257; $valid = true;
	for ( $n = 1; $n <= $size; ++$n ) {
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( ! $tx->begin_commerce_shared() ) { throw new RuntimeException( 'Fixture admission failed.' ); }
		$cart = $carts->create( hash( 'sha256', 'A1 claim cart ' . $n ), $now, $now, $now, '2030-01-01 00:00:00' );
		$source = $carts->add_item_quantity_locked( $cart->id(), 77, 1 );
		$order = $orders->create( $cart->id(), 'pending', 'EUR', 100, 100, $now, $now, hash( 'sha256', 'A1 order ' . $n ), 0, 0 );
		$item = $orders->insert_item( $order->id(), 77, 'Modern snapshot', 100, 1, 100, 100, 'EUR', $now, $source->id(), $source->content_version() );
		if ( false === $item || false === $reservations->create( $order->id(), $item->id(), 77, 1, 'active', $now, $now, '2030-01-01 00:00:00' ) || ! $orders->update_status( $order->id(), 'pending', 'awaiting_payment', $now ) ) { throw new RuntimeException( 'Modern checkout ownership fixture failed.' ); }
		$session = $sessions->create_attempt( bin2hex( random_bytes( 16 ) ), $order->id(), 'stripe_checkout', hash( 'sha256', 'A1 payment ' . $n ), 'EUR', 100, $now );
		$context = $tx->commerce_context();
		$request = new PaymentProviderSessionRequest( $session->reference(), 'c2c_ps_' . $session->reference(), 'EUR', 100, array( new PaymentProviderLineItem( 'Modern snapshot', 100, 1 ) ), '2030-01-01 00:00:00', array( 'environment'=>'test', 'installation_id'=>$context->installation_id(), 'epoch_identity'=>$context->epoch_identity() ) );
		$claim = $registry->create_claim( $session, $context, $request->snapshot(), 'test', $now );
		if ( false === $item || ! is_object( $claim ) || ! $tx->commit() || 'deleted' !== $delete->delete( $order->id() )->code() ) { $valid = false; break; }
		if ( 1 === $n ) { $first_session = $session->id(); }
		$last_claim = (int) $claim->id;
	}
	check( $valid && $last_claim > 0, '257 modern orders/attempts/native claims created by guarded writers and permanently deleted by production service' );
	check( '0' === $db->get_var( 'SELECT COUNT(*) FROM ' . $t->orders() ) && '0' === $db->get_var( 'SELECT COUNT(*) FROM ' . $t->payment_sessions() ), 'legitimate permanent deletion leaves no operational order/session witnesses' );
	// Explicit persisted corruption, never attributed to a supported writer.
	sql( $db, $db->prepare( 'UPDATE ' . $t->provider_claims() . ' SET payment_session_id=%d WHERE id=%d', $first_session, $last_claim ) );
	$probe = $db->prepare( 'SELECT id FROM ' . $t->provider_claims() . ' WHERE payment_session_id=%d ORDER BY id LIMIT 2', $first_session );
	check( count( $db->get_results( $probe ) ) === 2, 'two different immutable native claim owners now name one original session ID' );
	$individual = true;
	foreach ( $db->get_results( $db->prepare( 'SELECT * FROM ' . $t->provider_claims() . ' WHERE payment_session_id=%d ORDER BY id', $first_session ) ) as $row ) {
		$claim = ProviderClaimRecord::from_row( $row );
		$deletion = OrderDeletionRecord::from_row( $db->get_row( $db->prepare( 'SELECT * FROM ' . $t->order_deletions() . ' WHERE installation_id=%s AND epoch_id=%d AND original_order_id=%d', $claim->installation_id(), $claim->epoch_id(), (int) $row->order_id ) ) );
		$individual = $individual && 'deleted' === $row->disposition && $deletion->deleted_at() === $row->tombstoned_at;
	}
	check( $individual, 'both rows separately retain valid native captured scope and revision-bound deletion evidence' );
	$indexes = array();
	foreach ( $db->get_results( 'SHOW INDEX FROM ' . $t->provider_claims() ) as $part ) { $indexes[$part->Key_name][(int) $part->Seq_in_index] = $part->Column_name; }
	$covered = false;
	foreach ( $indexes as $parts ) { ksort( $parts ); $covered = $covered || 'payment_session_id' === array_values( $parts )[0]; }
	check( ! $covered, 'provider_claims has no original-session lookup index' );
	$before = baseline_snapshot( $db );
	$plan = json_decode( $db->get_var( 'ANALYZE FORMAT=JSON ' . $probe ), true, 512, JSON_THROW_ON_ERROR );
	$scanned = 0;
	$walk = static function ( array $node ) use ( &$walk, &$scanned, $t ): void {
		foreach ( $node as $key=>$value ) {
			if ( 'table' === $key && is_array( $value ) && ( $value['table_name'] ?? null ) === $t->provider_claims() ) { $scanned += (int) ( ( $value['r_rows'] ?? 0 ) * ( $value['r_loops'] ?? 0 ) ); }
			elseif ( is_array( $value ) ) { $walk( $value ); }
		}
	};
	$walk( $plan );
	check( $scanned >= $size, 'LIMIT 2 examines 257 retained claims without a session-owner access path' );
	echo 'EVIDENCE: retained-claim probe examined ' . $scanned . " rows for two results.\n";
	check( CommerceClaimSessionIndexMigration::run( $db ) && CommerceClaimSessionIndexMigration::verify_structure( $db, $t ), 'v18 installs the exact nonunique full original-session index' );
	$bounded = $db->prepare( 'SELECT id FROM ' . $t->provider_claims() . ' FORCE INDEX(payment_session_id) WHERE payment_session_id=%d ORDER BY id LIMIT 2', $first_session );
	check( count( $db->get_results( $bounded ) ) === 2, 'bounded probe retains duplicate detection across separate batches' );
	$scanned = 0; $walk( json_decode( $db->get_var( 'ANALYZE FORMAT=JSON ' . $bounded ), true, 512, JSON_THROW_ON_ERROR ) );
	check( 2 === $scanned, 'indexed LIMIT 2 examines only the two matching claims' );
	echo 'EVIDENCE: indexed retained-claim probe examined ' . $scanned . " rows for two results.\n";
	check( $before === baseline_snapshot( $db ), 'migration and validation probes change no durable rows' );
	check( $stock === serialize( $db->get_results( 'SELECT * FROM ' . $db->postmeta . ' ORDER BY meta_id' ) ), 'stock unchanged across actual creation/deletion and read-only validation reproduction' );
	check( CommerceOrderSourceIndexMigration::verify_structure( $db, $t ), 'authorized order_source index remains intact' );
	check( '0' === $db->get_var( 'SELECT COUNT(*) FROM ' . $t->commerce_resets() ), 'no A1 certificate, B/4.5 effect or reset allocation fabricated' );
} finally { if ( $tx->is_active() ) { $tx->rollback(); } baseline_cleanup( $db ); }
echo "All retained-claim access-path checks passed; A1 remains incomplete.\n";
