<?php
/** A1 STOP reproduction: retained deletion owners cannot reuse a global order ID. */
declare( strict_types=1 );
require __DIR__ . '/support/modern-baseline.php';
use RodolfoRizzo\Cpt2Collection\{CommerceBaselineMigration,CommerceBaselinePurgeMigration,CommerceOrderSourceIndexMigration,CommerceClaimSessionIndexMigration,CommerceDeletedOrderIndexesMigration,CartRepository,OrderRepository,ReservationRepository,PaymentSessionRepository,ProviderRegistry,ProviderClaimRecord,PaymentProviderSessionRequest,PaymentProviderLineItem,OrderDeletionRecord,OrderDeletionRepository,OrderDeletionRegistry,OrderDeletionService,TransactionManager,TableNames,UtcClock};

$db = new wpdb( 'c2c_a1_deletion_path_' . bin2hex( random_bytes( 5 ) ) . '_' ); $t = new TableNames( $db ); $tx = new TransactionManager( $db, $t );
try {
	baseline_schema( $db );
	check( CommerceBaselineMigration::run( $db ) && CommerceBaselinePurgeMigration::run( $db ) && CommerceOrderSourceIndexMigration::run( $db ) && CommerceClaimSessionIndexMigration::run( $db ), 'fixture has completed modern baseline and verified schema 0.18 access paths' );
	$carts = new CartRepository( $db, $t ); $orders = new OrderRepository( $db, $t ); $sessions = new PaymentSessionRepository( $db, $t ); $registry = new ProviderRegistry( $db, $t );
	$reservations = new ReservationRepository( $db, $t );
	// Current DELETE requires the existing v19 global deletion sentinel; keep this test's target index absent below.
	sql($db,'ALTER TABLE '.$t->order_deletions().' ADD KEY original_order_id(original_order_id)');
	$delete = new OrderDeletionService( new OrderDeletionRepository( $db, $t ), new OrderDeletionRegistry( $db, $t ), $tx, new UtcClock() );
	$stock = serialize( $db->get_results( 'SELECT * FROM ' . $db->postmeta . ' ORDER BY meta_id' ) );
	$last_order = 0; $prior_claim = 0; $prior_deletion = 0; $last_claim = 0; $size = 257; $valid = true;
	for ( $n = 1; $n <= $size; ++$n ) {
		if ( $n === $size ) { baseline_epoch_switch( $db ); }
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
		if ( $n === $size-1 ) { $prior_claim = (int) $claim->id; $prior_deletion = (int) $db->get_var( $db->prepare( 'SELECT id FROM ' . $t->order_deletions() . ' WHERE original_order_id=%d', $order->id() ) ); }
		$last_order = $order->id();
		$last_claim = (int) $claim->id;
	}
	check( $valid && $last_claim > 0, '257 modern orders/attempts/native claims created by guarded writers and permanently deleted by production service' );
	check( '0' === $db->get_var( 'SELECT COUNT(*) FROM ' . $t->orders() ) && '0' === $db->get_var( 'SELECT COUNT(*) FROM ' . $t->payment_sessions() ), 'legitimate permanent deletion leaves no operational order/session witnesses' );
	// Explicit persisted contradiction after genuine deletion in two modern epochs.
	// Keep each claim/deletion owner separately coherent; only the global ID collides.
	sql( $db, $db->prepare( 'UPDATE ' . $t->order_deletions() . ' SET original_order_id=%d WHERE id=%d', $last_order, $prior_deletion ) );
	sql( $db, $db->prepare( 'UPDATE ' . $t->provider_claims() . ' SET order_id=%d WHERE id=%d', $last_order, $prior_claim ) );
	$probe = $db->prepare( 'SELECT id FROM ' . $t->order_deletions() . ' WHERE original_order_id=%d ORDER BY id LIMIT 2', $last_order );
	check( count( $db->get_results( $probe ) ) === 2, 'retired and source deletion owners now contradictorily name one global original order ID' );
	$individual = true;
	foreach ( $db->get_results( $db->prepare( 'SELECT * FROM ' . $t->provider_claims() . ' WHERE id IN (%d,%d) ORDER BY id', $prior_claim, $last_claim ) ) as $row ) {
		$claim = ProviderClaimRecord::from_row( $row );
		$deletion = OrderDeletionRecord::from_row( $db->get_row( $db->prepare( 'SELECT * FROM ' . $t->order_deletions() . ' WHERE installation_id=%s AND epoch_id=%d AND original_order_id=%d', $claim->installation_id(), $claim->epoch_id(), (int) $row->order_id ) ) );
		$individual = $individual && 'deleted' === $row->disposition && $deletion->deleted_at() === $row->tombstoned_at;
	}
	check( $individual, 'each native claim separately retains coherent scope, revision-bound deletion and tombstone' );
	// Restore the deliberate pre-v19 access-path defect only after supported deletion has completed.
	sql($db,'ALTER TABLE '.$t->order_deletions().' DROP KEY original_order_id');
	$indexes = array();
	foreach ( $db->get_results( 'SHOW INDEX FROM ' . $t->order_deletions() ) as $part ) { $indexes[$part->Key_name][(int) $part->Seq_in_index] = $part->Column_name; }
	$covered = false;
	foreach ( $indexes as $parts ) { ksort( $parts ); $covered = $covered || 'original_order_id' === array_values( $parts )[0]; }
	check( ! $covered, 'order_deletions has no global original-order lookup index' );
	$before = baseline_snapshot( $db );
	$plan = json_decode( $db->get_var( 'ANALYZE FORMAT=JSON ' . $probe ), true, 512, JSON_THROW_ON_ERROR );
	$scanned = 0;
	$walk = static function ( array $node ) use ( &$walk, &$scanned, $t ): void {
		foreach ( $node as $key=>$value ) {
			if ( 'table' === $key && is_array( $value ) && ( $value['table_name'] ?? null ) === $t->order_deletions() ) { $scanned += (int) ( ( $value['r_rows'] ?? 0 ) * ( $value['r_loops'] ?? 0 ) ); }
			elseif ( is_array( $value ) ) { $walk( $value ); }
		}
	};
	$walk( $plan );
	check( $scanned >= $size, 'LIMIT 2 examines 257 deletion records without a global original-order access path' );
	echo 'EVIDENCE: deletion-owner probe examined ' . $scanned . " rows for two results.\n";
	$current = $db->get_row( 'SELECT * FROM ' . $t->commerce_control() );
	$epoch = $db->get_row( $db->prepare( 'SELECT * FROM ' . $t->commerce_epochs() . ' WHERE id=%d', $current->current_epoch_id ) );
	$context = new \RodolfoRizzo\Cpt2Collection\CommerceContext( $current->installation_id, (int) $epoch->id, $epoch->identity );
	check( true === ( new OrderDeletionRepository( $db, $t ) )->has_foreign_deletion( $last_order, $context ), 'production Phase 3 rejects the other-epoch deletion owner as conflicting evidence' );
	$foreign = $db->prepare( 'SELECT id FROM ' . $t->order_deletions() . ' WHERE original_order_id=%d AND (installation_id<>%s OR epoch_id<>%d OR epoch_identity<>%s) LIMIT 1', $last_order, $context->installation_id(), $context->epoch_id(), $context->epoch_identity() );
	$scanned = 0; $walk( json_decode( $db->get_var( 'ANALYZE FORMAT=JSON ' . $foreign ), true, 512, JSON_THROW_ON_ERROR ) );
	check( $scanned >= $size-1, 'existing foreign-deletion predicate examines 256 unrelated/retained owners' );
	echo 'EVIDENCE: production foreign-deletion predicate examined ' . $scanned . " rows for one result.\n";
	check( CommerceDeletedOrderIndexesMigration::run( $db ) && CommerceDeletedOrderIndexesMigration::verify_structure( $db, $t ), 'v19 installs both exact nonunique owner access paths' );
	$bounded = $db->prepare( 'SELECT id FROM ' . $t->order_deletions() . ' FORCE INDEX(original_order_id) WHERE original_order_id=%d ORDER BY id LIMIT 2', $last_order );
	check( count( $db->get_results( $bounded ) ) === 2, 'duplicate deletion owners remain visible after v19' );
	$scanned = 0; $walk( json_decode( $db->get_var( 'ANALYZE FORMAT=JSON ' . $bounded ), true, 512, JSON_THROW_ON_ERROR ) );
	check( 2 === $scanned, 'indexed duplicate probe examines only two matching deletion owners' );
	echo 'EVIDENCE: v19 deletion duplicate probe examined ' . $scanned . " rows.\n";
	$bounded_foreign = str_replace( ' WHERE', ' FORCE INDEX(original_order_id) WHERE', $foreign );
	$scanned = 0; $walk( json_decode( $db->get_var( 'ANALYZE FORMAT=JSON ' . $bounded_foreign ), true, 512, JSON_THROW_ON_ERROR ) );
	check( $scanned <= 2 && count( $db->get_results( $bounded_foreign ) ) === 1, 'indexed contradictory-owner lookup inspects at most two matching owners' );
	echo 'EVIDENCE: v19 foreign deletion lookup examined ' . $scanned . " rows.\n";
	check( $before === baseline_snapshot( $db ), 'migration and access probes preserve durable rows' );
	check( CommerceClaimSessionIndexMigration::verify_structure( $db, $t ), 'authorized nonunique original-session claim index remains intact' );
	check( $stock === serialize( $db->get_results( 'SELECT * FROM ' . $db->postmeta . ' ORDER BY meta_id' ) ), 'stock unchanged across actual creation/deletion and read-only validation reproduction' );
	check( CommerceOrderSourceIndexMigration::verify_structure( $db, $t ), 'authorized order_source index remains intact' );
	check( '0' === $db->get_var( 'SELECT COUNT(*) FROM ' . $t->commerce_resets() ), 'no A1 certificate, B/4.5 effect or reset allocation fabricated' );
} finally { if ( $tx->is_active() ) { $tx->rollback(); } baseline_cleanup( $db ); }
echo "All deletion-owner access-path checks passed.\n";
