<?php
/** A1 inventory: all retained claims naming a deleted source order must agree. */
declare( strict_types=1 );
require __DIR__ . '/support/modern-baseline.php';
use RodolfoRizzo\Cpt2Collection\{CommerceBaselineMigration,CommerceBaselinePurgeMigration,CommerceOrderSourceIndexMigration,CommerceClaimSessionIndexMigration,CommerceDeletedOrderIndexesMigration,CartRepository,OrderRepository,ReservationRepository,PaymentSessionRepository,ProviderRegistry,ProviderClaimRecord,PaymentProviderSessionRequest,PaymentProviderLineItem,OrderDeletionRecord,OrderDeletionRepository,OrderDeletionRegistry,OrderDeletionService,TransactionManager,TableNames,UtcClock};

$db = new wpdb( 'c2c_a1_claim_order_path_' . bin2hex( random_bytes( 5 ) ) . '_' ); $t = new TableNames( $db ); $tx = new TransactionManager( $db, $t );
try {
	baseline_schema( $db );
	check( CommerceBaselineMigration::run( $db ) && CommerceBaselinePurgeMigration::run( $db ) && CommerceOrderSourceIndexMigration::run( $db ) && CommerceClaimSessionIndexMigration::run( $db ), 'fixture has completed modern baseline and verified schema 0.18 access paths' );
	// Actual v18 index inventory: these are the existing plan's lookup prefixes.
	$required = array(
		'commerce_control'=>array( 'PRIMARY'=>array( 'id' ) ),
		'commerce_epochs'=>array( 'PRIMARY'=>array( 'id' ), 'identity'=>array( 'identity' ) ),
		'commerce_resets'=>array( 'PRIMARY'=>array( 'id' ), 'operation_identity'=>array( 'operation_identity' ), 'active_slot'=>array( 'active_slot' ) ),
		'carts'=>array( 'PRIMARY'=>array( 'id' ) ),
		'cart_items'=>array( 'PRIMARY'=>array( 'id' ), 'cart_product'=>array( 'cart_id','post_id' ), 'cart_id'=>array( 'cart_id' ) ),
		'orders'=>array( 'PRIMARY'=>array( 'id' ), 'cart_checkout_attempt'=>array( 'cart_id','checkout_attempt_hash' ), 'cart_revision_generation'=>array( 'cart_id','cart_revision','checkout_generation' ) ),
		'order_items'=>array( 'PRIMARY'=>array( 'id' ), 'order_id'=>array( 'order_id' ), 'order_post'=>array( 'order_id','post_id' ), 'order_source'=>array( 'order_id','source_cart_item_id' ) ),
		'reservations'=>array( 'PRIMARY'=>array( 'id' ), 'order_id'=>array( 'order_id' ), 'order_item_id'=>array( 'order_item_id' ) ),
		'payment_sessions'=>array( 'PRIMARY'=>array( 'id' ), 'reference'=>array( 'reference' ), 'order_creation_key'=>array( 'order_id','creation_key_hash' ), 'provider_session'=>array( 'provider','provider_session_id' ), 'provider_payment'=>array( 'provider','provider_payment_id' ), 'order_active_slot'=>array( 'order_id','active_slot' ), 'order_id'=>array( 'order_id' ) ),
		'payment_events'=>array( 'PRIMARY'=>array( 'id' ), 'provider_event'=>array( 'provider','provider_event_id' ), 'provider_session'=>array( 'provider','provider_session_id' ), 'payment_session_id'=>array( 'payment_session_id' ) ),
		'provider_claims'=>array( 'PRIMARY'=>array( 'id' ), 'reference'=>array( 'reference' ), 'provider_idempotency'=>array( 'provider','idempotency_key' ), 'attempt'=>array( 'installation_id','epoch_id','order_id','creation_key_hash' ), 'epoch_order'=>array( 'epoch_id','order_id' ), 'payment_session_id'=>array( 'payment_session_id' ) ),
		'provider_identities'=>array( 'PRIMARY'=>array( 'id' ), 'identity'=>array( 'provider','identity_kind','identity_value' ), 'claim_id'=>array( 'claim_id' ) ),
		'order_deletions'=>array( 'PRIMARY'=>array( 'id' ), 'owned_order'=>array( 'installation_id','epoch_id','original_order_id' ), 'checkout_attempt'=>array( 'installation_id','epoch_id','cart_id','checkout_attempt_hash' ), 'checkout_revision'=>array( 'installation_id','epoch_id','cart_id','cart_revision','checkout_generation' ) ),
	);
	foreach ( $required as $table=>$keys ) {
		$actual = array();
		foreach ( $db->get_results( 'SHOW INDEX FROM ' . $t->$table() ) as $part ) {
			if ( null !== $part->Sub_part ) { throw new RuntimeException( 'Unexpected prefix in inventory.' ); }
			$actual[$part->Key_name][(int) $part->Seq_in_index] = $part->Column_name;
		}
		$valid_keys = true;
		foreach ( $keys as $key=>$columns ) {
			$parts = $actual[$key] ?? array(); ksort( $parts );
			$valid_keys = $valid_keys && array_values( $parts ) === $columns;
		}
		check( $valid_keys, $table . ': actual indexed prefixes cover the inventoried existing lookups' );
	}
	$carts = new CartRepository( $db, $t ); $orders = new OrderRepository( $db, $t ); $sessions = new PaymentSessionRepository( $db, $t ); $registry = new ProviderRegistry( $db, $t );
	$reservations = new ReservationRepository( $db, $t );
	// Current DELETE requires the existing v19 global deletion sentinel; keep this test's target index absent below.
	sql($db,'ALTER TABLE '.$t->order_deletions().' ADD KEY original_order_id(original_order_id)');
	$delete = new OrderDeletionService( new OrderDeletionRepository( $db, $t ), new OrderDeletionRegistry( $db, $t ), $tx, new UtcClock() );
	$stock = serialize( $db->get_results( 'SELECT * FROM ' . $db->postmeta . ' ORDER BY meta_id' ) );
	$last_order = 0; $prior_claim = 0; $last_claim = 0; $size = 257; $valid = true;
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
		if ( $n === $size-1 ) { $prior_claim = (int) $claim->id; }
		$last_order = $order->id();
		$last_claim = (int) $claim->id;
	}
	check( $valid && $last_claim > 0, '257 modern orders/attempts/native claims created by guarded writers and permanently deleted by production service' );
	check( '0' === $db->get_var( 'SELECT COUNT(*) FROM ' . $t->orders() ) && '0' === $db->get_var( 'SELECT COUNT(*) FROM ' . $t->payment_sessions() ), 'legitimate permanent deletion leaves no operational order/session witnesses' );
	// Explicit persisted contradiction after genuine deletion in two modern epochs.
	// Only the retired claim order ID changes. Its old deletion is deliberately untouched.
	// This isolates a claim-owner contradiction from the already-known deletion collision.
	sql( $db, $db->prepare( 'UPDATE ' . $t->provider_claims() . ' SET order_id=%d WHERE id=%d', $last_order, $prior_claim ) );
	$probe = $db->prepare( 'SELECT id FROM ' . $t->provider_claims() . ' WHERE order_id=%d ORDER BY id LIMIT 2', $last_order );
	check( count( $db->get_results( $probe ) ) === 2, 'a retired claim and the source claim now name the same original order ID' );
	$individual = true;
	foreach ( $db->get_results( $db->prepare( 'SELECT * FROM ' . $t->provider_claims() . ' WHERE id IN (%d,%d) ORDER BY id', $prior_claim, $last_claim ) ) as $row ) {
		$claim = ProviderClaimRecord::from_row( $row );
		$individual = $individual && 'deleted' === $row->disposition && $claim->epoch_id() > 0;
	}
	check( $individual, 'both claim rows separately pass the modern native captured-scope parser' );
	$current_deletion = OrderDeletionRecord::from_row( $db->get_row( $db->prepare( 'SELECT * FROM ' . $t->order_deletions() . ' WHERE original_order_id=%d', $last_order ) ) );
	check( $current_deletion->epoch_id() === 2 && count( $db->get_results( $db->prepare( 'SELECT id FROM ' . $t->order_deletions() . ' WHERE original_order_id=%d', $last_order ) ) ) === 1, 'selected source deletion has exactly one owner; no deletion-owner collision is fabricated' );
	$indexes = array();
	foreach ( $db->get_results( 'SHOW INDEX FROM ' . $t->provider_claims() ) as $part ) { $indexes[$part->Key_name][(int) $part->Seq_in_index] = $part->Column_name; }
	$covered = false;
	foreach ( $indexes as $parts ) { ksort( $parts ); $covered = $covered || 'order_id' === array_values( $parts )[0]; }
	check( ! $covered, 'provider_claims has no global original-order lookup index' );
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
	check( $scanned >= $size, 'LIMIT 2 examines 257 claims without a global original-order access path' );
	echo 'EVIDENCE: claim/order probe examined ' . $scanned . " rows for two results.\n";
	$current = $db->get_row( 'SELECT * FROM ' . $t->commerce_control() );
	$epoch = $db->get_row( $db->prepare( 'SELECT * FROM ' . $t->commerce_epochs() . ' WHERE id=%d', $current->current_epoch_id ) );
	$context = new \RodolfoRizzo\Cpt2Collection\CommerceContext( $current->installation_id, (int) $epoch->id, $epoch->identity );
	check( false === ( new OrderDeletionRepository( $db, $t ) )->validate_deleted_evidence( $last_order, $context ), 'existing Phase 3 rejects a retained other-epoch claim naming this deleted source order' );
	$scoped = $db->prepare( 'SELECT id FROM ' . $t->provider_claims() . ' FORCE INDEX(epoch_order) WHERE epoch_id=%d AND order_id=%d ORDER BY id LIMIT 2', $context->epoch_id(), $last_order );
	check( count( $db->get_results( $scoped ) ) === 1, 'epoch-scoped index finds only the coherent source claim and misses the contradiction' );
	$foreign = $db->prepare( "SELECT id FROM " . $t->provider_claims() . " WHERE order_id=%d AND (installation_id<>%s OR epoch_id<>%d OR epoch_identity<>%s OR disposition<>'deleted' OR tombstoned_at IS NULL OR tombstoned_at<>%s) ORDER BY id LIMIT 1", $last_order, $context->installation_id(), $context->epoch_id(), $context->epoch_identity(), $current_deletion->deleted_at() );
	$scanned = 0; $walk( json_decode( $db->get_var( 'ANALYZE FORMAT=JSON ' . $foreign ), true, 512, JSON_THROW_ON_ERROR ) );
	check( $scanned >= $size-1, 'deleted-evidence contradiction probe examines 256 claims for one mismatch' );
	echo 'EVIDENCE: deleted-evidence contradiction predicate examined ' . $scanned . " rows for one result.\n";
	$batch_one = $db->prepare( 'SELECT id FROM ' . $t->provider_claims() . ' WHERE order_id=%d AND id>0 ORDER BY id LIMIT 1', $last_order );
	$scanned = 0; $walk( json_decode( $db->get_var( 'ANALYZE FORMAT=JSON ' . $batch_one ), true, 512, JSON_THROW_ON_ERROR ) );
	check( $scanned >= $size-1, 'batch-size-one related-claim cursor still examines 256 claims for its first row' );
	echo 'EVIDENCE: batch-one related-claim predicate examined ' . $scanned . " rows for one result.\n";
	check( CommerceDeletedOrderIndexesMigration::run( $db ) && CommerceDeletedOrderIndexesMigration::verify_structure( $db, $t ), 'v19 installs both exact nonunique owner access paths' );
	$cursor = 0; $seen = array();
	while ( true ) {
		$bounded = $db->prepare( 'SELECT id,epoch_id FROM ' . $t->provider_claims() . ' FORCE INDEX(order_id) WHERE order_id=%d AND id>%d ORDER BY id LIMIT 1', $last_order, $cursor );
		$scanned = 0; $walk( json_decode( $db->get_var( 'ANALYZE FORMAT=JSON ' . $bounded ), true, 512, JSON_THROW_ON_ERROR ) );
		$rows = $db->get_results( $bounded ); check( $scanned <= 1, 'v19 batch-one traversal examines at most one matching claim' );
		if ( ! $rows ) { break; } $cursor = (int) $rows[0]->id; $seen[] = (int) $rows[0]->epoch_id;
	}
	check( array( 1,2 ) === $seen, 'bounded traversal includes the foreign epoch; it never filters to source scope' );
	echo "EVIDENCE: v19 claim/order batch-one traversal examined 1, 1, 0 rows.\n";
	// A second attempt per order is legitimate and must not be prohibited by this key.
	$clone = get_object_vars( $db->get_row( $db->prepare( 'SELECT * FROM ' . $t->provider_claims() . ' WHERE id=%d', $last_claim ) ) ); unset( $clone['id'] );
	$clone['reference'] = bin2hex( random_bytes( 16 ) ); $clone['idempotency_key'] = 'c2c_ps_' . $clone['reference']; $clone['creation_key_hash'] = hash( 'sha256', 'additional native attempt' ); $clone['payment_session_id'] = 999999;
	$request = PaymentProviderSessionRequest::from_snapshot( $clone['request_snapshot'] );
	$clone['request_snapshot'] = ( new PaymentProviderSessionRequest( $clone['reference'], $clone['idempotency_key'], $request->currency(), $request->amount_minor(), $request->line_items(), $request->reservation_deadline(), $request->configuration() ) )->snapshot();
	ProviderClaimRecord::from_row( (object) array_merge( array( 'id'=>999999 ), $clone ) );
	check( false !== $db->insert( $t->provider_claims(), $clone, array_fill( 0, count( $clone ), '%s' ) ), 'nonunique order index permits another coherent native attempt for this order' );
	sql( $db, $db->prepare( 'DELETE FROM ' . $t->provider_claims() . ' WHERE id=%d', $db->insert_id ) );
	check( $before === baseline_snapshot( $db ), 'migration and access probes preserve durable rows' );
	check( CommerceClaimSessionIndexMigration::verify_structure( $db, $t ), 'authorized nonunique original-session claim index remains intact' );
	check( $stock === serialize( $db->get_results( 'SELECT * FROM ' . $db->postmeta . ' ORDER BY meta_id' ) ), 'stock unchanged across actual creation/deletion and read-only validation reproduction' );
	check( CommerceOrderSourceIndexMigration::verify_structure( $db, $t ), 'authorized order_source index remains intact' );
	check( '0' === $db->get_var( 'SELECT COUNT(*) FROM ' . $t->commerce_resets() ), 'no A1 certificate, B/4.5 effect or reset allocation fabricated' );
} finally { if ( $tx->is_active() ) { $tx->rollback(); } baseline_cleanup( $db ); }
echo "All claim/order inventory checks passed.\n";
