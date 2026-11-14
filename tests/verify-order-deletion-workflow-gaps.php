<?php
/** Real-InnoDB verification of fresh deletion recognition between workflow transactions. */
declare( strict_types=1 );
if ( ! extension_loaded( 'mysqli' ) || false === getenv( 'C2C_TEST_DB_NAME' ) ) { echo "SKIP: mysqli and C2C_TEST_DB_NAME are required.\n"; exit( 0 ); }
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );

class WP_Post_Type { public string $name = 'collection_item'; public bool $_builtin = false; public bool $show_ui = true; public bool $public = true; }
function __( string $message, string $domain = '' ): string { return $message; }
function get_option( string $key, mixed $default = false ): mixed { return 'cpt2collection_post_type' === $key ? 'collection_item' : (['cpt2collection_stripe_secret_key'=>'sk_test_fixture_only','cpt2collection_stripe_success_url'=>'https://example.test/success','cpt2collection_stripe_cancel_url'=>'https://example.test/cancel'][$key]??$default); }
function get_post_types( array $arguments, string $output ): array { return array( 'collection_item' => new WP_Post_Type() ); }
function apply_filters( string $hook, mixed $value ): mixed { return $value; }
function wp_cache_delete( int $id, string $group ): bool { return true; }
function get_post( int $id ): ?object { global $gap_db; return $gap_db?->get_row( $gap_db->prepare( 'SELECT ID FROM ' . $gap_db->posts . ' WHERE ID=%d', $id ) ); }
function get_post_type( int $id ): string|false { return null === get_post( $id ) ? false : 'collection_item'; }
function get_post_meta( int $id, string $key, bool $single ): mixed { global $gap_db; return $gap_db?->get_var( $gap_db->prepare( 'SELECT meta_value FROM ' . $gap_db->postmeta . ' WHERE post_id=%d AND meta_key=%s ORDER BY meta_id LIMIT 1', $id, $key ) ) ?? ''; }
function get_the_title( int $id ): string { return 'Gap item ' . $id; }

require __DIR__ . '/support/phase-two-schema.php';

class GapDeletionReadFailureDb extends wpdb {
	public bool $fail_deletion_read = false;
	public function get_results( string $query ): ?array {
		if ( $this->fail_deletion_read && str_contains( $query, 'c2c_order_deletions' ) ) { $this->last_error = 'injected deletion proof failure'; return null; }
		return parent::get_results( $query );
	}
}

use RodolfoRizzo\Cpt2Collection\{CartRecord,CartReconciliationService,CartRepository,CheckoutService,CommercePhaseThreeCheckpointOneMigration,CommerceRules,ConversionReservationSetValidator,LockedCatalogReader,OrderDeletionRegistry,OrderDeletionRepository,OrderDeletionResult,OrderDeletionService,OrderRepository,PaymentEventAssociationService,PaymentEventOutcomeService,PaymentEventRepository,PaymentEventStatuses,PaymentExpirationService,PaymentProviderInterface,PaymentProviderSessionRequest,PaymentProviderSessionResult,PaymentSessionRepository,PaymentSessionService,PaymentWebhookOrchestrator,PostTypes,ProviderRegistry,ReservationAcquirer,ReservationRepository,ReservationSetValidator,SaleConversionService,StockConversionProcessor,StripeEventNormalizer,TableNames,TransactionManager,UtcClock,WebhookAssociationResult};

function gap_delete( wpdb $db, TableNames $tables, int $order_id ): OrderDeletionResult {
	return ( new OrderDeletionService( new OrderDeletionRepository( $db, $tables ), new OrderDeletionRegistry( $db, $tables ), new TransactionManager( $db, $tables ), new UtcClock() ) )->delete( $order_id );
}
/** @return array<string,mixed> */
function gap_services( wpdb $db ): array {
	$tables = new TableNames( $db ); $clock = new UtcClock(); $tx = new TransactionManager( $db, $tables ); $orders = new OrderRepository( $db, $tables ); $sessions = new PaymentSessionRepository( $db, $tables ); $events = new PaymentEventRepository( $db, $tables ); $reservations = new ReservationRepository( $db, $tables ); $deletions = new OrderDeletionRegistry( $db, $tables );
	$validator = new ConversionReservationSetValidator(); $stock = new StockConversionProcessor( $reservations, new LockedCatalogReader( $db, $tx ), $tx, $validator ); $outcomes = new PaymentEventOutcomeService( $orders, $sessions, $events, $tx, $clock );
	return array(
		'tables'=>$tables, 'tx'=>$tx, 'orders'=>$orders, 'sessions'=>$sessions, 'events'=>$events, 'reservations'=>$reservations, 'deletions'=>$deletions,
		'association'=>new PaymentEventAssociationService( $orders, $sessions, $events, $tx, $clock, false, $deletions ),
		'conversion'=>new SaleConversionService( $orders, $reservations, $sessions, $events, $tx, $stock, $validator, new PostTypes(), $clock, $deletions ),
		'expiration'=>new PaymentExpirationService( $orders, $sessions, $events, $tx, $outcomes, $clock, $deletions ),
		'reconciliation'=>new CartReconciliationService( new CartRepository( $db, $tables ), $orders, $tx, $clock, $deletions ),
	);
}
function gap_orchestrator( wpdb $db ): PaymentWebhookOrchestrator {
	$tables = new TableNames( $db ); $clock = new UtcClock(); $tx = new TransactionManager( $db, $tables ); $orders = new OrderRepository( $db, $tables ); $sessions = new PaymentSessionRepository( $db, $tables ); $events = new PaymentEventRepository( $db, $tables ); $reservations = new ReservationRepository( $db, $tables ); $deletions = new OrderDeletionRegistry( $db, $tables ); $validator = new ConversionReservationSetValidator();
	$stock = new StockConversionProcessor( $reservations, new LockedCatalogReader( $db, $tx ), $tx, $validator ); $outcomes = new PaymentEventOutcomeService( $orders, $sessions, $events, $tx, $clock );
	return new PaymentWebhookOrchestrator(
		new StripeEventNormalizer(), $events,
		new PaymentEventAssociationService( $orders, $sessions, $events, $tx, $clock, false, $deletions ),
		new SaleConversionService( $orders, $reservations, $sessions, $events, $tx, $stock, $validator, new PostTypes(), $clock, $deletions ),
		new PaymentExpirationService( $orders, $sessions, $events, $tx, $outcomes, $clock, $deletions ),
		$outcomes, $clock, new CartReconciliationService( new CartRepository( $db, $tables ), $orders, $tx, $clock, $deletions ), $tx, new \RodolfoRizzo\Cpt2Collection\ProviderWebhookEvidence(static fn()=>new wpdb($db->prefix))
	);
}
function gap_checkout( wpdb $db ): CheckoutService {
	$tables = new TableNames( $db ); $clock = new UtcClock(); $tx = new TransactionManager( $db, $tables ); $orders = new OrderRepository( $db, $tables ); $reservations = new ReservationRepository( $db, $tables ); $post_types = new PostTypes();
	return new CheckoutService( new CartRepository( $db, $tables ), new CommerceRules( $post_types ), $orders, $tx, new ReservationAcquirer( $orders, $reservations, $tx, new LockedCatalogReader( $db, $tx ), $post_types, $clock ), $clock, $reservations, new PaymentSessionRepository( $db, $tables ), new PaymentEventRepository( $db, $tables ), new OrderDeletionRegistry( $db, $tables ) );
}
/** @return array<string,mixed> */
function gap_event( string $event_id, string $session_id, ?string $reference, ?string $payment_id ): array {
	$metadata = null === $reference ? array() : array( 'c2c_integration'=>'cpt2collection', 'c2c_payment_session_reference'=>$reference, 'c2c_installation_id'=>str_repeat('a',32), 'c2c_epoch_identity'=>str_repeat('b',32) );
	return array( 'id'=>$event_id, 'type'=>'checkout.session.completed', 'created'=>time(), 'livemode'=>false, 'data'=>array( 'object'=>array( 'object'=>'checkout.session', 'id'=>$session_id, 'client_reference_id'=>$reference, 'metadata'=>$metadata, 'payment_intent'=>$payment_id, 'currency'=>'eur', 'amount_total'=>100, 'payment_status'=>'paid', 'status'=>'complete', 'mode'=>'payment' ) ) );
}
/** @return array{session:int,event:int,claim:int,reference:string,post:int,cart_item:int,checkout_attempt:string} */
function gap_seed( wpdb $db, TableNames $tables, int $order_id, string $event_type = 'checkout.session.completed' ): array {
	$at = '2026-09-28 12:00:00'; $session = $order_id * 10; $event = $order_id * 100; $claim = $session; $post = 1000 + $order_id; $cart_item = 2000 + $order_id;
	$checkout_attempt = str_pad( 'checkout-' . $order_id, 32, 'x' );
	$reference = substr( hash( 'sha256', 'reference-' . $session ), 0, 32 ); $key = hash( 'sha256', 'key-' . $session ); $provider_session = 'cs_gap_' . $order_id; $payment = 'pi_gap_' . $order_id; $event_identity = 'evt_gap_' . $order_id; $hash = hash( 'sha256', $event_identity );
	sql( $db, "INSERT INTO {$db->posts}(ID,post_type) VALUES($post,'collection_item')" );
	sql( $db, "INSERT INTO {$db->postmeta}(post_id,meta_key,meta_value) VALUES($post,'_cpt2collection_price_minor','100'),($post,'_cpt2collection_status','available'),($post,'_cpt2collection_stock_quantity','5')" );
	sql( $db, "INSERT INTO {$tables->carts()}(epoch_id,id,token_hash,content_revision,checkout_generation,created_at,updated_at,last_activity_at,expires_at) VALUES(1,$order_id,SHA2('$order_id',256),1,1,'$at','$at','$at','2030-01-01')" );
	sql( $db, "INSERT INTO {$tables->cart_items()}(id,cart_id,post_id,quantity,content_version,created_at,updated_at) VALUES($cart_item,$order_id,$post,1,0,'$at','$at')" );
	sql( $db, "INSERT INTO {$tables->orders()}(epoch_id,id,cart_id,cart_revision,checkout_generation,checkout_attempt_hash,cart_reconciliation_status,status,currency,subtotal_minor,total_minor,created_at,updated_at) VALUES(1,$order_id,$order_id,1,1,SHA2('$checkout_attempt',256),'pending','awaiting_payment','EUR',100,100,'$at','$at')" );
	sql( $db, "INSERT INTO {$tables->order_items()}(id,order_id,post_id,source_cart_item_id,source_cart_item_version,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at) VALUES($cart_item,$order_id,$post,$cart_item,0,'Gap item',100,1,100,100,'EUR','$at')" );
	sql( $db, "INSERT INTO {$tables->reservations()}(epoch_id,id,order_id,order_item_id,post_id,quantity,status,created_at,updated_at,expires_at) VALUES(1,$cart_item,$order_id,$cart_item,$post,1,'active','$at','$at','2030-01-01')" );
	sql( $db, "INSERT INTO {$tables->payment_sessions()}(epoch_id,id,reference,order_id,provider,provider_session_id,creation_key_hash,status,active_slot,currency,amount_minor,redirect_url,created_at,updated_at,expires_at) VALUES(1,$session,'$reference',$order_id,'stripe_checkout','$provider_session','$key','active',1,'EUR',100,'https://example.test/session','$at','$at','2030-01-01')" );
	sql( $db, "INSERT INTO {$tables->provider_claims()}(id,installation_id,epoch_id,epoch_identity,provider,reference,order_id,payment_session_id,creation_key_hash,idempotency_key,request_version,environment,evidence_state,disposition,created_at) VALUES($claim,REPEAT('a',32),1,REPEAT('b',32),'stripe_checkout','$reference',$order_id,$session,'$key','c2c_ps_$reference',2,'test','native','open','$at')" );
	foreach ( array( 'session'=>$provider_session, 'payment'=>$payment, 'event'=>$event_identity ) as $kind=>$value ) {
		$event_fields = 'event' === $kind ? ",'$hash','$event_type'" : ',NULL,NULL';
		sql( $db, "INSERT INTO {$tables->provider_identities()}(provider,identity_kind,identity_value,claim_id,binding_state,evidence_state,observed_epoch_id,first_seen_at,payload_sha256,event_type,environment,disposition) VALUES('stripe_checkout','$kind','$value',$claim,'bound','native',1,'$at'{$event_fields},'test','pending')" );
	}
	$normalized = json_encode( array( 'schema'=>2, 'object'=>'checkout.session', 'mode'=>'payment', 'client_reference_id'=>$reference, 'integration'=>'cpt2collection', 'installation_id'=>str_repeat('a',32), 'epoch_identity'=>str_repeat('b',32) ), JSON_UNESCAPED_SLASHES );
	$session_status = 'checkout.session.expired' === $event_type ? 'expired' : 'complete';
	$payment_columns = 'checkout.session.expired' === $event_type ? "NULL,NULL,NULL,NULL" : "'$payment','EUR',100,'paid'";
	sql( $db, "INSERT INTO {$tables->payment_events()}(epoch_id,ownership_state,id,provider,provider_event_id,event_type,livemode,payment_session_id,provider_session_id,local_reference,provider_payment_id,currency,amount_minor,payment_status,session_status,processing_status,attempt_count,received_at,payload_sha256,normalized_payload) VALUES(NULL,'unresolved',$event,'stripe_checkout','$event_identity','$event_type',0,NULL,'$provider_session','$reference',$payment_columns,'$session_status','received',0,'$at','$hash','" . $db->dbh->real_escape_string( (string) $normalized ) . "')" );
	native_provider_claim_fixture($db,$claim);
	return compact( 'session', 'event', 'claim', 'reference', 'post', 'cart_item', 'checkout_attempt' );
}

/** Exact original claim and receipt DATA, captured before destructive gaps. */
function gap_operation(wpdb $db, \RodolfoRizzo\Cpt2Collection\PaymentEventRecord $e): \RodolfoRizzo\Cpt2Collection\CommittedOperation {
 $t=new TableNames($db);$row=(new \RodolfoRizzo\Cpt2Collection\ProviderRegistry($db,$t))->claim((string)$e->local_reference());
 $r=new \RodolfoRizzo\Cpt2Collection\PaymentEventReceipt($e->provider(),$e->provider_event_id(),$e->event_type(),$e->provider_created_at(),$e->livemode(),$e->provider_session_id(),$e->local_reference(),$e->provider_payment_id(),$e->currency(),$e->amount_minor(),$e->payment_status(),$e->session_status(),$e->payload_sha256(),$e->normalized_payload());
 return \RodolfoRizzo\Cpt2Collection\CommittedOperation::authenticated_receipt(\RodolfoRizzo\Cpt2Collection\ProviderClaimRecord::from_row($row),$r,gmdate('Y-m-d H:i:s'));
}

$prefix = 'c2c_delete_gaps_' . bin2hex( random_bytes( 4 ) ) . '_'; $db = new wpdb( $prefix ); $gap_db = $db; $tables = new TableNames( $db ); $created = phase_two_schema( $db );
try {
	check( CommercePhaseThreeCheckpointOneMigration::run( $db ), 'workflow-gap deletion schema is available' ); attest_modern_runtime_fixture($db); provider_response_schema($db); $created[]=$tables->commerce_resets(); $created[] = $tables->order_deletions();
	sql( $db, "CREATE TABLE {$db->posts}(ID BIGINT PRIMARY KEY,post_type VARCHAR(64) NOT NULL) ENGINE=InnoDB" ); $created[] = $db->posts;
	sql( $db, "CREATE TABLE {$db->postmeta}(meta_id BIGINT AUTO_INCREMENT PRIMARY KEY,post_id BIGINT NOT NULL,meta_key VARCHAR(255) NOT NULL,meta_value LONGTEXT) ENGINE=InnoDB" ); $created[] = $db->postmeta;

	// Receipt committed, then DELETE, then association resumes with a stale PHP event object.
	$ids = gap_seed( $db, $tables, 1 ); $deleted_receipt = $ids; $services = gap_services( $db ); $stale = $services['events']->find( $ids['event'] ); $operation=gap_operation($db,$stale);
	check( $stale instanceof RodolfoRizzo\Cpt2Collection\PaymentEventRecord && OrderDeletionResult::DELETED === gap_delete( $db, $tables, 1 )->code(), 'receipt-to-association fixture deletes after receipt commit' );
	$association = $services['association']->associate( $stale, $operation );
	check( WebhookAssociationResult::ORDER_DELETED === $association->outcome(), 'stale receipt continuation freshly recognizes durable deletion' );
	check( 0 === (int) $db->get_var( "SELECT COUNT(*) FROM {$tables->payment_events()} WHERE id={$ids['event']}" ) && 'deleted' === $db->get_var( "SELECT disposition FROM {$tables->provider_claims()} WHERE id={$ids['claim']}" ), 'association does not recreate event/session and claim stays deleted' );

	// Checkout barriers must be produced by the real physical deletion, not a hand-built deletion row.
	$cart = CartRecord::from_row( $db->get_row( "SELECT * FROM {$tables->carts()} WHERE id=1" ) );
	check( 'checkout_order_deleted' === gap_checkout( $db )->prepare( $cart, $deleted_receipt['checkout_attempt'] )->get_code(), 'real deletion permanently blocks the same checkout attempt' );
	check( 'checkout_order_deleted' === gap_checkout( $db )->prepare( $cart, str_repeat( 'u', 32 ) )->get_code(), 'real deletion blocks a different attempt on the unchanged cart revision' );
	sql( $db, "UPDATE {$tables->cart_items()} SET quantity=2,content_version=content_version+1 WHERE id={$deleted_receipt['cart_item']}" );
	sql( $db, "UPDATE {$tables->carts()} SET content_revision=content_revision+1,checkout_generation=0 WHERE id=1" );
	$mutated_cart = CartRecord::from_row( $db->get_row( "SELECT * FROM {$tables->carts()} WHERE id=1" ) );
	check( 'checkout_order_deleted' === gap_checkout( $db )->prepare( $mutated_cart, $deleted_receipt['checkout_attempt'] )->get_code(), 'historical deleted attempt remains blocked after a real cart mutation' );
	$fresh_checkout = gap_checkout( $db )->prepare( $mutated_cart, str_repeat( 'v', 32 ) );
	check( $fresh_checkout->is_success() && $fresh_checkout->get_order_id() !== 1, 'genuine later cart mutation permits a fresh checkout attempt' );

	// Duplicate, delayed, new coherent, conflicting, and wholly unrecognized deliveries after deletion.
	$duplicate_id = 'evt_gap_1'; $duplicate = gap_orchestrator( $db )->handle( gap_event( $duplicate_id, 'cs_gap_1', $deleted_receipt['reference'], 'pi_gap_1' ), hash( 'sha256', $duplicate_id ) );
	check( $duplicate->is_accepted() && 0 === (int) $db->get_var( "SELECT COUNT(*) FROM {$tables->payment_events()} WHERE provider_event_id='$duplicate_id'" ), 'duplicate event known before deletion is accepted from durable identity without event recreation' );
	$new_id = 'evt_delayed_new_1'; $new_hash = hash( 'sha256', $new_id ); $delayed = gap_orchestrator( $db )->handle( gap_event( $new_id, 'cs_delayed_new_1', $deleted_receipt['reference'], 'pi_delayed_new_1' ), $new_hash );
	check( $delayed->is_accepted() && 'pending' === $db->get_var( "SELECT disposition FROM {$tables->provider_identities()} WHERE identity_kind='event' AND identity_value='$new_id'" ) && 0 === (int) $db->get_var( "SELECT COUNT(*) FROM {$tables->payment_events()} WHERE provider_event_id='$new_id'" ), 'new deleted-owner evidence remains retained-only pending, with no operational recreation' );
	$conflict = gap_orchestrator( $db )->handle( gap_event( $new_id, 'cs_delayed_new_1', $deleted_receipt['reference'], 'pi_delayed_new_1' ), hash( 'sha256', 'conflicting-payload' ) );
	check( !$conflict->is_accepted() && 'quarantined' === $db->get_var( "SELECT binding_state FROM {$tables->provider_identities()} WHERE identity_kind='event' AND identity_value='$new_id'" ), 'conflicting delivery fails without rebinding existing ownership' );
	$unrecognized_id = 'evt_wholly_unknown'; $unrecognized = gap_orchestrator( $db )->handle( gap_event( $unrecognized_id, 'cs_wholly_unknown', null, 'pi_wholly_unknown' ), hash( 'sha256', $unrecognized_id ) );
	check( !$unrecognized->is_accepted() && null === $db->get_var( "SELECT processing_status FROM {$tables->payment_events()} WHERE provider_event_id='$unrecognized_id'" ) && null === $db->get_var( "SELECT claim_id FROM {$tables->provider_identities()} WHERE identity_kind='event' AND identity_value='$unrecognized_id'" ), 'unscoped unknown event is rejected without receipt recreation' );

	// Association committed, then DELETE, then sale conversion and expiration resume.
	$ids = gap_seed( $db, $tables, 12 ); $services = gap_services( $db ); $event = $services['events']->find( $ids['event'] ); $operation=gap_operation($db,$event); check( WebhookAssociationResult::ASSOCIATED === $services['association']->associate( $event, $operation )->outcome(), 'event association commits before deletion gap' );
	$stock_before = (string) $db->get_var( "SELECT meta_value FROM {$db->postmeta} WHERE post_id={$ids['post']} AND meta_key='_cpt2collection_stock_quantity'" ); check( OrderDeletionResult::DELETED === gap_delete( $db, $tables, 12 )->code(), 'associated order deletes before conversion' );
	$sale = $services['conversion']->convert( 12, $ids['session'], $ids['event'], $operation );
	check( $sale->is_success() && 'order_deleted' === $sale->code() && $stock_before === (string) $db->get_var( "SELECT meta_value FROM {$db->postmeta} WHERE post_id={$ids['post']} AND meta_key='_cpt2collection_stock_quantity'" ), 'conversion terminates on fresh deletion proof without stock conversion' );

	$ids = gap_seed( $db, $tables, 13, 'checkout.session.expired' ); $services = gap_services( $db ); $event = $services['events']->find( $ids['event'] ); $operation=gap_operation($db,$event); check( WebhookAssociationResult::ASSOCIATED === $services['association']->associate( $event, $operation )->outcome(), 'expiration event association commits before deletion gap' );
	check( OrderDeletionResult::DELETED === gap_delete( $db, $tables, 13 )->code(), 'associated order deletes before expiration mutation' );
	$expired = $services['expiration']->expire( 13, $ids['session'], $ids['event'], $operation );
	check( $expired->is_accepted() && 'order_deleted' === $expired->code(), 'expiration terminates benignly only on durable deletion proof' );

	// A committed sale stays committed; deletion neither restores stock nor lets stale reconciliation mutate the cart.
	$ids = gap_seed( $db, $tables, 14 ); $services = gap_services( $db ); $event = $services['events']->find( $ids['event'] ); $operation=gap_operation($db,$event); check( WebhookAssociationResult::ASSOCIATED === $services['association']->associate( $event, $operation )->outcome(), 'sale fixture association commits' );
	$sale = $services['conversion']->convert( 14, $ids['session'], $ids['event'], $operation ); check( $sale->is_success() && 'sale_converted' === $sale->code(), 'sale conversion commits before deletion gap' );
	check( '4' === (string) $db->get_var( "SELECT meta_value FROM {$db->postmeta} WHERE post_id={$ids['post']} AND meta_key='_cpt2collection_stock_quantity'" ), 'sale conversion decrements stock exactly once' );
	$cart_before = serialize( $db->get_row( "SELECT * FROM {$tables->cart_items()} WHERE id={$ids['cart_item']}" ) ); check( OrderDeletionResult::DELETED === gap_delete( $db, $tables, 14 )->code(), 'converted order deletes before cart reconciliation' );
	$reconciled = $services['reconciliation']->reconcile( 14, $operation );
	check( $reconciled->is_completed() && 'order_deleted' === $reconciled->code() && $cart_before === serialize( $db->get_row( "SELECT * FROM {$tables->cart_items()} WHERE id={$ids['cart_item']}" ) ), 'post-sale reconciliation is a terminal no-op for proven deletion' );
	check( '4' === (string) $db->get_var( "SELECT meta_value FROM {$db->postmeta} WHERE post_id={$ids['post']} AND meta_key='_cpt2collection_stock_quantity'" ), 'deletion and reconciliation do not restore or reconvert stock' );

	// The opposite orderings remain authoritative: committed expiration/cart work is not undone by deletion.
	$ids = gap_seed( $db, $tables, 16, 'checkout.session.expired' ); $services = gap_services( $db ); $event = $services['events']->find( $ids['event'] ); $operation=gap_operation($db,$event);
	check( WebhookAssociationResult::ASSOCIATED === $services['association']->associate( $event, $operation )->outcome() && $services['expiration']->expire( 16, $ids['session'], $ids['event'], $operation )->is_accepted(), 'expiration transaction commits before deletion' );
	check( OrderDeletionResult::DELETED === gap_delete( $db, $tables, 16 )->code() && 0 === (int) $db->get_var( "SELECT COUNT(*) FROM {$tables->orders()} WHERE id=16" ), 'later deletion preserves committed expiration and recreates nothing' );

	$ids = gap_seed( $db, $tables, 17 ); $services = gap_services( $db ); $event = $services['events']->find( $ids['event'] ); $operation=gap_operation($db,$event);
	check( WebhookAssociationResult::ASSOCIATED === $services['association']->associate( $event, $operation )->outcome() && $services['conversion']->convert( 17, $ids['session'], $ids['event'], $operation )->is_success(), 'second sale commits before cart-first ordering' );
	$cart_first = $services['reconciliation']->reconcile( 17, $operation );
	check( $cart_first->is_completed() && 'cart_reconciled' === $cart_first->code(), 'cart reconciliation commits before deletion' );
	$committed_cart = serialize( array( $db->get_row( "SELECT * FROM {$tables->carts()} WHERE id=17" ), $db->get_results( "SELECT * FROM {$tables->cart_items()} WHERE cart_id=17 ORDER BY id" ) ) );
	check( OrderDeletionResult::DELETED === gap_delete( $db, $tables, 17 )->code() && $committed_cart === serialize( array( $db->get_row( "SELECT * FROM {$tables->carts()} WHERE id=17" ), $db->get_results( "SELECT * FROM {$tables->cart_items()} WHERE cart_id=17 ORDER BY id" ) ) ), 'deletion does not undo already committed cart reconciliation' );

	// Missing rows without deletion evidence stay fail-closed across all continuations.
	$services = gap_services( $db ); $unknown_sale = $services['conversion']->convert( 999, 9990, 99900 ); $unknown_expiration = $services['expiration']->expire( 999, 9990, 99900 ); $unknown_reconciliation = $services['reconciliation']->reconcile( 999 );
	check( ! $unknown_sale->is_success() && 'order_deleted' !== $unknown_sale->code() && ! $unknown_expiration->is_accepted() && 'order_deleted' !== $unknown_expiration->code() && $unknown_reconciliation->is_retryable() && 'order_deleted' !== $unknown_reconciliation->code(), 'unexplained operational absence is never reclassified as deletion' );
	$at = '2026-09-28 12:00:00'; sql( $db, "INSERT INTO {$tables->order_deletions()}(installation_id,epoch_id,epoch_identity,original_order_id,deleted_at,checkout_disposition) VALUES(REPEAT('f',32),1,REPEAT('b',32),998,'$at','identity_only')" );
	$proof_tx = new TransactionManager( $db, $tables ); check( $proof_tx->begin_commerce_shared(), 'contradictory deletion proof fixture is admitted' ); check( ! ( new OrderDeletionRegistry( $db, $tables ) )->proves_deleted_order( 998 ), 'contradictory installation evidence does not prove deletion' ); $proof_tx->rollback();
	$failure_db = new GapDeletionReadFailureDb( $prefix ); $failure_tables = new TableNames( $failure_db ); $failure_tx = new TransactionManager( $failure_db, $failure_tables ); check( $failure_tx->begin_commerce_shared(), 'deletion proof read-failure fixture is admitted' ); $failure_db->fail_deletion_read = true;
	check( ! ( new OrderDeletionRegistry( $failure_db, $failure_tables ) )->proves_deleted_order( 1 ), 'database read failure does not prove deletion' ); $failure_tx->rollback(); $failure_db->close();

	// Provider response returns after another connection deletes the claimed order.
	$order_id = 15; $post = 1015; $item = 2015;
	sql( $db, "INSERT INTO {$db->posts}(ID,post_type) VALUES($post,'collection_item')" ); sql( $db, "INSERT INTO {$db->postmeta}(post_id,meta_key,meta_value) VALUES($post,'_cpt2collection_price_minor','100'),($post,'_cpt2collection_status','available'),($post,'_cpt2collection_stock_quantity','5')" );
	sql($db,"INSERT INTO {$tables->carts()}(epoch_id,id,token_hash,created_at,updated_at,last_activity_at,expires_at) VALUES(1,$order_id,SHA2('provider-gap',256),'$at','$at','$at','2030-01-01')");sql($db,"INSERT INTO {$tables->cart_items()}(id,cart_id,post_id,quantity,created_at,updated_at) VALUES($item,$order_id,$post,1,'$at','$at')");sql( $db, "INSERT INTO {$tables->orders()}(epoch_id,id,cart_id,cart_revision,checkout_generation,checkout_attempt_hash,cart_reconciliation_status,status,currency,subtotal_minor,total_minor,created_at,updated_at) VALUES(1,$order_id,$order_id,0,0,REPEAT('f',64),'pending','pending','EUR',100,100,'$at','$at')" );
	sql( $db, "INSERT INTO {$tables->order_items()}(id,order_id,post_id,source_cart_item_id,source_cart_item_version,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at) VALUES($item,$order_id,$post,$item,0,'Provider gap',100,1,100,100,'EUR','$at')" );
	sql( $db, "INSERT INTO {$tables->reservations()}(epoch_id,id,order_id,order_item_id,post_id,quantity,status,created_at,updated_at,expires_at) VALUES(1,$item,$order_id,$item,$post,1,'active','$at','$at','2030-01-01')" );
	$provider = new class( $prefix, $order_id ) implements \RodolfoRizzo\Cpt2Collection\StripeCheckoutClientInterface {
		public int $calls = 0; public function __construct( private string $prefix, private int $order_id ) {}
		public function name(): string { return 'stripe_checkout'; }
		public function create_checkout_session(array $parameters,array $options): array {
			++$this->calls; $other = new wpdb( $this->prefix ); $tables = new TableNames( $other ); $result = gap_delete( $other, $tables, $this->order_id ); $other->close();
			if ( OrderDeletionResult::DELETED !== $result->code() ) { throw new RuntimeException( 'Concurrent deletion failed.' ); }
			return ['id'=>'cs_returned_after_delete','url'=>'https://example.test/after-delete','expires_at'=>time()+600,'payment_intent'=>'pi_returned_after_delete'];
		}
	};
	$tx = new TransactionManager( $db, $tables ); $deletions = new OrderDeletionRegistry( $db, $tables );
	$payment = new PaymentSessionService( new OrderRepository( $db, $tables ), new ReservationRepository( $db, $tables ), new PaymentSessionRepository( $db, $tables ), $tx, new ReservationSetValidator(), new \RodolfoRizzo\Cpt2Collection\StripeCheckoutProvider($provider,new UtcClock()), new UtcClock(), $deletions );
	$result = $payment->create( $order_id, str_repeat( 'z', 32 ) );
	check( 1 === $provider->calls && ! $result->is_success() && 'order_deleted' === $result->get_code(), 'provider response after deletion records a terminal non-retry outcome' );
	$claim = $db->get_row( "SELECT * FROM {$tables->provider_claims()} WHERE order_id=$order_id" );
	check( is_object( $claim ) && 'deleted' === $claim->disposition && 2 === (int) $db->get_var( "SELECT COUNT(*) FROM {$tables->provider_identities()} WHERE claim_id={$claim->id} AND identity_kind IN ('session','payment')" ), 'returned provider evidence is retained on the permanently closed claim' );
	check( 0 === (int) $db->get_var( "SELECT COUNT(*) FROM {$tables->orders()} WHERE id=$order_id" ) && 0 === (int) $db->get_var( "SELECT COUNT(*) FROM {$tables->payment_sessions()} WHERE order_id=$order_id" ) && '5' === (string) $db->get_var( "SELECT meta_value FROM {$db->postmeta} WHERE post_id=$post AND meta_key='_cpt2collection_stock_quantity'" ), 'provider continuation cannot recreate order/session or convert stock' );

	// Registry routing after deletion: coherent new evidence is retained and neutralized; conflicts quarantine without rebinding.
	$registry_tx = new TransactionManager( $db, $tables ); check( $registry_tx->begin_commerce_shared(), 'post-delete provider evidence transaction is admitted' ); $registry = new ProviderRegistry( $db, $tables );
	$bound = $registry->bind( 'stripe_checkout', 'event', 'evt_new_after_delete', (int) $claim->id, $at, hash( 'sha256', 'new-after-delete' ), 'checkout.session.completed', null );
	check( 'bound' === $bound && $registry->terminal( 'stripe_checkout', 'evt_new_after_delete', 'neutralized_order_deleted' ) && $registry_tx->commit(), 'new coherent evidence is durably neutralized against deleted ownership' );
	check( 'deleted' === $db->get_var( "SELECT disposition FROM {$tables->provider_claims()} WHERE id={$claim->id}" ) && 0 === (int) $db->get_var( "SELECT COUNT(*) FROM {$tables->payment_events()} WHERE provider_event_id='evt_new_after_delete'" ), 'new deleted evidence does not reopen claim or create an operational event' );

	echo "All workflow-gap deletion checks passed.\n";
} finally {
	foreach ( array_unique( $created ) as $table ) { $db->query( 'DROP TABLE IF EXISTS ' . $table ); } $db->close();
}
