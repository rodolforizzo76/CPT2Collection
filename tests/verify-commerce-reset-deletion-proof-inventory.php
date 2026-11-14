<?php
/** Read-only predicate diagnostics after TEST-ONLY deletion. No proposed proof schema. */
declare( strict_types=1 );
require __DIR__ . '/support/reset-retirement.php';

use RodolfoRizzo\Cpt2Collection\{CommerceResetValidation, CommerceResetRepository, TableNames, TransactionManager, PaymentEventRepository, PaymentEventOutcomeService, PaymentSessionRepository, OrderRepository, UtcClock, ProviderClaimRecord, ProviderRegistry};

function inventory_reader( wpdb $db, object $r ): CommerceResetValidation {
	$reader = new CommerceResetValidation( $db, new TableNames( $db ), $r );
	// Diagnostic calls to existing private predicates, never public validation authority.
	( new ReflectionProperty( CommerceResetValidation::class, 'first_epoch' ) )->setValue( $reader, 1 );
	return $reader;
}
function inventory_reason( CommerceResetValidation $reader, string $method, array $args ): ?string {
	try { ( new ReflectionMethod( CommerceResetValidation::class, $method ) )->invokeArgs( $reader, $args ); }
	catch ( InvalidArgumentException $error ) { return $error->getMessage(); }
	return null;
}
function inventory_remove( wpdb $db, object $r, int $stage, bool $partial ): object {
	$t = new TableNames( $db );
	$stages = array( 'payment_events', 'payment_sessions', 'reservations', 'order_items', 'orders', 'cart_items', 'carts' );
	for ( $n = 0; $n <= $stage; ++$n ) {
		$cursor = 0;
		do {
			$tx = new TransactionManager( $db, $t );
			if ( ! $tx->begin_reset_maintenance( $r ) ) { throw new RuntimeException( 'diagnostic deletion admission' ); }
			$row = $db->get_row( $db->prepare( 'SELECT id FROM ' . $t->{$stages[$n]}() . ' FORCE INDEX(PRIMARY) WHERE id>%d ORDER BY id LIMIT 1 FOR UPDATE', $cursor ) );
			if ( null !== $row ) {
				$cursor = (int) $row->id;
				if ( 1 !== $db->query( $db->prepare( 'DELETE FROM ' . $t->{$stages[$n]}() . ' WHERE id=%d', $cursor ) ) ) { throw new RuntimeException( 'diagnostic deletion' ); }
			}
			// Test-only absence keeps the strengthened seal untouched.
			if ( ! $tx->commit() ) { throw new RuntimeException( 'diagnostic absence commit' ); }
			if ( $partial && $n === $stage && null !== $row ) { return $r; }
		} while ( null !== $row );
	}
	return $r;
}

foreach ( array( 'event_link', 'session_owner', 'item_sum', 'request_suffix', 'request_empty', 'item_membership', 'order_header', 'cart_optional', 'all_empty' ) as $case ) {
	$db = new wpdb( 'c2c_45_inv_' . bin2hex( random_bytes( 5 ) ) . '_' );
	$t = new TableNames( $db );
	try {
		validation_schema( $db );
		$paid = payment_fixture( $db );
		$provider = new ModernBoundaryProvider();
		$calls = 0;
		$provider->during_call = static function () use ( &$calls ): void { ++$calls; };
		[ $payments ] = payment_service( $db, $provider );
		check( $payments->create( $paid, bin2hex( random_bytes( 16 ) ) )->is_success(), "$case: real native attempt" );
		$session = $db->get_row( "SELECT * FROM {$t->payment_sessions()} WHERE order_id=$paid" );
		$claim = $db->get_row( "SELECT * FROM {$t->provider_claims()} WHERE payment_session_id={$session->id}" );
		$payload = modern_payload( $session->reference, 'evt_inventory', $claim->installation_id, $claim->epoch_identity );
		check( webhook_for( $db )->handle( $payload, hash( 'sha256', json_encode( $payload ) ) )->status() === 200, "$case: actual sale and reconciliation" );
		$events = new PaymentEventRepository( $db, $t );
		$event = $events->find_by_provider_event_id( 'stripe_checkout', 'evt_inventory' );
		$outcomes = new PaymentEventOutcomeService( new OrderRepository( $db, $t ), new PaymentSessionRepository( $db, $t ), $events, new TransactionManager( $db, $t ), new UtcClock() );
		check( $outcomes->integrity_conflict( $event, \RodolfoRizzo\Cpt2Collection\CommittedOperation::authenticated_receipt(\RodolfoRizzo\Cpt2Collection\ProviderClaimRecord::from_row($claim), (new \RodolfoRizzo\Cpt2Collection\StripeEventNormalizer())->normalize($payload,hash('sha256',json_encode($payload)))->receipt(),gmdate('Y-m-d H:i:s')) )->status() === 200, "$case: genuine terminal-receipt reconciliation alarm" );
		$session = $db->get_row( "SELECT * FROM {$t->payment_sessions()} WHERE id={$session->id}" );
		check( (int) $session->reconciliation_event_id === $event->id(), "$case: original numeric reconciliation Event link" );
		// Pending items remain cart-backed. Two distinct lines expose prefix loss.
		$f = validation_order( $db, 2 );
		$original_items = $db->get_results( "SELECT * FROM {$t->order_items()} WHERE order_id={$f['order']} ORDER BY id" );
		$original_order = $db->get_row( "SELECT * FROM {$t->orders()} WHERE id={$f['order']}" );
		$stock = serialize( $db->get_results( 'SELECT * FROM ' . $db->postmeta . ' ORDER BY meta_id' ) );
		$r = retirement_pass( $db, validation_pass( $db, validation_start( $db ) ), 1 );
		check( $r->phase() === 'deleting' && $r->validation_generation() === $r->evidence_generation(), "$case: actual fresh A1/B certification" );
		$stage = array( 'event_link'=>0, 'session_owner'=>1, 'item_sum'=>3, 'request_suffix'=>3, 'request_empty'=>3, 'item_membership'=>3, 'order_header'=>4, 'cart_optional'=>6, 'all_empty'=>6 )[$case];
		$r = inventory_remove( $db, $r, $stage, in_array( $case, array( 'item_sum', 'request_suffix', 'item_membership' ), true ) );
		if ( in_array( $case, array( 'item_sum', 'request_suffix', 'item_membership' ), true ) ) {
			// First global item was paid; now remove the first pending item too.
			$tx = new TransactionManager( $db, $t );
			check( $tx->begin_reset_maintenance( $r ), "$case: bounded pending-prefix diagnostic" );
			sql( $db, "DELETE FROM {$t->order_items()} WHERE id={$original_items[0]->id}" );
			check( $tx->commit(), "$case: test-only partial prefix preserves seal marker" );
		}
		$r = validation_bind( $db, $r, (int) $claim->id, 'event', 'evt_inventory_late_' . $case, hash( 'sha256', $case ), 'checkout.session.completed' );
		check( $r->evidence_generation() !== $r->validation_generation(), "$case: real generation change after deletion" );
		$prefix = $db->prefix;
		$db->close();
		$db = new wpdb( $prefix );
		$t = new TableNames( $db );
		$r = validation_service( $db )->resume( $r->operation_identity() )->operation();
		check( null !== $r && $r->phase() === 'deleting', "$case: exact durable progress recovered on fresh connection" );
		$reader = inventory_reader( $db, $r );
		if ( $case === 'event_link' ) {
			$session = $db->get_row( "SELECT * FROM {$t->payment_sessions()} WHERE id={$session->id}" );
			check( inventory_reason( $reader, 'payment_sessions', array( $session ) ) === 'validation_parent_missing', 'D-E: session reconciliation points to deleted local Event PK' );
			$identity = $db->get_row( "SELECT * FROM {$t->provider_identities()} WHERE identity_value='evt_inventory'" );
			check( $identity->disposition === 'attention' && (int) $identity->claim_id === (int) $claim->id && $identity->payload_sha256 === $event->payload_sha256(), 'C/E: B retains Event owner/hash/meaningful disposition' );
			check( ! property_exists( $identity, 'payment_session_id' ) && ! property_exists( $identity, 'payment_event_id' ), 'D-E: retained identity has neither operational Event PK nor direct session link' );
		} elseif ( $case === 'session_owner' ) {
			$current = $db->get_row( "SELECT * FROM {$t->provider_claims()} WHERE id={$claim->id}" );
			check( inventory_reason( $reader, 'provider_claims', array( $current ) ) === 'validation_claim_parent', 'D-S: original Session corroboration lost while order survives' );
			check( inventory_reason( $reader, 'session_claim', array( $session, $current ) ) === null, 'original independent Session facts corroborated the claim' );
			// Explicit corruption control, not a supported writer or proof model.
			$wrong = clone $current; $wrong->order_id = (string) $f['order'];
			ProviderClaimRecord::from_row( $wrong );
			check( inventory_reason( $reader, 'session_claim', array( $session, $wrong ) ) === 'validation_session_claim', 'D-S: native claim parser alone cannot replace independent Session/order comparison' );
		} elseif ( $case === 'item_sum' ) {
			$position = ( new ReflectionMethod( CommerceResetValidation::class, 'sum_batch' ) )->invoke( $reader, $f['order'], 0, 0, 1 );
			check( inventory_reason( $reader, 'sum_batch', array( $f['order'], (int) $original_items[1]->id, 100, 1 ) ) === 'validation_order_total', 'D-I: bounded surviving suffix no longer proves full order total' );
			check( $position[1] === 'v_sum_' . $original_items[1]->id . '_100', 'D-I: size-one total stream lost the first 100-minor contribution' );
		} elseif ( $case === 'request_suffix' || $case === 'request_empty' ) {
			$expected = $case === 'request_suffix' ? 'validation_request_item' : 'validation_request_items';
			check( inventory_reason( $reader, 'request_batch', array( $f['claim'], 0, 0, 1 ) ) === $expected, 'D-I: captured request loses original ordered snapshot ' . $case );
		} elseif ( $case === 'item_membership' ) {
			// Corruption control: deleted source/product member reused by a survivor.
			$survivor = clone $original_items[1];
			$survivor->source_cart_item_id = $original_items[0]->source_cart_item_id;
			$survivor->post_id = $original_items[0]->post_id;
			sql( $db, "UPDATE {$t->order_items()} SET source_cart_item_id={$survivor->source_cart_item_id},post_id={$survivor->post_id} WHERE id={$survivor->id}" );
			check( inventory_reason( $reader, 'order_items', array( $survivor ) ) === null, 'D-I: operational-only duplicate probes lose removed source/product membership' );
			check( $original_items[0]->order_id === $survivor->order_id && $original_items[0]->source_cart_item_id === $survivor->source_cart_item_id, 'D-I: original deleted member exposes the contradiction' );
		} elseif ( $case === 'order_header' ) {
			check( inventory_reason( $reader, 'order_items', array( $original_items[0] ) ) === 'validation_parent_missing', 'D-O: item witness still needs original order scope/currency/cart header' );
			check( (int) $db->get_var( 'SELECT COUNT(*) FROM ' . $t->order_deletions() ) === 0, 'D-O: reset removal is not a Phase 3 deletion' );
			$current = $db->get_row( "SELECT * FROM {$t->provider_claims()} WHERE id={$f['claim']}" );
			check( ! property_exists( $current, 'cart_id' ) && ! property_exists( $current, 'checkout_attempt_hash' ), 'D-O: retained native claim lacks checkout cart/revision/generation/attempt header' );
		} else {
			check( inventory_reason( $reader, 'cart_snapshot', array( $original_order->cart_id, $original_order->cart_revision, $original_order->checkout_generation ) ) === null, 'A/F: existing cart predicate already accepts absent mutable cart' );
			$identity = $db->get_row( "SELECT * FROM {$t->provider_identities()} WHERE identity_value='evt_inventory'" );
			check( inventory_reason( $reader, 'provider_identities', array( $identity ) ) === null, 'C/E: retained provider owner remains valid without operational parents' );
			foreach ( array( 'payment_events','payment_sessions','reservations','order_items','orders','cart_items','carts' ) as $table ) {
				check( null === $db->get_var( 'SELECT id FROM ' . $t->$table() . ' FORCE INDEX(PRIMARY) ORDER BY id LIMIT 1' ), "$case: independent bounded emptiness $table" );
			}
			$r = validation_bind( $db, $r, (int) $claim->id, 'payment', 'pi_inventory_repeat_' . $case );
			check( $r->evidence_generation() === 2 && $r->target_epoch_id() === null, 'repeated generation changes never complete or allocate an epoch' );
		}
		check( $stock === serialize( $db->get_results( 'SELECT * FROM ' . $db->postmeta . ' ORDER BY meta_id' ) ) && $calls === 1, "$case: unchanged sold stock and zero reset provider calls" );
		check( ! ( new TransactionManager( $db, $t ) )->begin_commerce_shared(), "$case: normal writers remain fenced" );
	} finally { baseline_cleanup( $db ); }
}
echo "All deletion-proof inventory diagnostics passed; no production proof model implemented.\n";
