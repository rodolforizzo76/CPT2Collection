<?php
/** Phase 2C: signed production webhook orchestration with real MariaDB transactions. */
declare( strict_types=1 );
if ( ! extension_loaded( 'mysqli' ) || ! function_exists( 'pcntl_fork' ) || ! function_exists( 'stream_socket_pair' ) || false === getenv( 'C2C_TEST_DB_NAME' ) ) {
	echo "SKIP: mysqli, pcntl, sockets and C2C_TEST_DB_NAME required.\n"; exit( 0 );
}
define( 'ABSPATH', __DIR__ );
$db_host = getenv( 'C2C_TEST_DB_HOST' ) ?: '127.0.0.1';
$db_port = (int) ( getenv( 'C2C_TEST_DB_PORT' ) ?: 3306 );
$db_user = getenv( 'C2C_TEST_DB_USER' ) ?: 'root';
$db_pass = getenv( 'C2C_TEST_DB_PASSWORD' ) ?: '';
$db_name = (string) getenv( 'C2C_TEST_DB_NAME' );
$prefix = 'c2c_phase2c_' . bin2hex( random_bytes( 4 ) ) . '_';
mysqli_report( MYSQLI_REPORT_OFF );
require_once __DIR__ . '/support/mariadb-wpdb.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
function __( string $text, string $domain ): string { return $text; }
function get_option( string $key, mixed $default = false ): mixed { return 'cpt2collection_post_type' === $key ? 'collection_item' : $default; }
function apply_filters( string $key, mixed $value ): mixed { return $value; }
class WP_Post_Type { public bool $_builtin = false; public bool $public = true; public bool $show_ui = true; public string $name = 'collection_item'; }
function get_post_types( array $args, string $output ): array { return array( 'collection_item' => new WP_Post_Type() ); }
function wp_cache_delete( int $id, string $group ): bool { return true; }
class WP_REST_Request {
	public function __construct( private string $body, private string $signature ) {}
	public function get_body(): string { return $this->body; }
	public function get_header( string $name ): string { return $this->signature; }
}
class WP_REST_Response {
	public function __construct( private array $data, private int $status ) {}
	public function get_status(): int { return $this->status; }
	public function get_data(): array { return $this->data; }
}
foreach ( array( 'table-names', 'money', 'order-statuses', 'reservation-statuses', 'payment-session-statuses', 'payment-event-statuses', 'cart-record', 'cart-item', 'order-record', 'order-item', 'reservation-record', 'payment-session-record', 'payment-event-record', 'cart-repository', 'order-repository', 'reservation-repository', 'payment-session-repository', 'payment-event-repository', 'payment-event-receipt', 'payment-event-receipt-result', 'payment-event-association-result', 'payment-event-attention-result', 'conversion-reservation-validation-result', 'conversion-reservation-set-validator', 'stock-conversion-result', 'sale-conversion-result', 'transaction-manager', 'meta-keys', 'locked-catalog-reader', 'stock-conversion-processor', 'post-types', 'utc-clock', 'sale-conversion-service', 'cart-reconciliation-result', 'cart-reconciliation-service', 'webhook-http-disposition', 'webhook-association-result', 'webhook-outcome-categories', 'sale-conversion-result-classifier', 'stripe-event-normalization-result', 'stripe-event-normalizer', 'payment-event-association-service', 'payment-event-outcome-service', 'payment-expiration-service', 'payment-webhook-orchestrator', 'stripe-settings', 'stripe-webhook-verification-result', 'stripe-webhook-verifier', 'stripe-webhook-rest-controller' ) as $class ) {
	require_once dirname( __DIR__ ) . '/includes/class-' . $class . '.php';
}
use RodolfoRizzo\Cpt2Collection\{CartRepository,OrderRepository,ReservationRepository,PaymentSessionRepository,PaymentEventRepository,TableNames,TransactionManager,UtcClock,PostTypes,ConversionReservationSetValidator,LockedCatalogReader,StockConversionProcessor,SaleConversionService,CartReconciliationService,StripeEventNormalizer,PaymentEventAssociationService,PaymentEventOutcomeService,PaymentExpirationService,PaymentWebhookOrchestrator,StripeWebhookRestController,StripeWebhookVerifier};

/** Observes and injects failures at SQL boundaries; no production service is mocked. */
class WebhookDb extends wpdb {
 public bool $closed=false;
 public function close():void {if(!$this->closed){$this->poison_transaction();\RodolfoRizzo\Cpt2Collection\TransactionManager::connection_discarded_on($this);parent::close();$this->closed=true;}}
	public array $log = array();
	public ?string $fail = null;
	public bool $reconciling = false;
	public int $starts = 0;
	public $on_reconciliation = null;
	public function query( string $sql ): int|false {
		$this->log[] = $sql;
		if ( 'START TRANSACTION' === $sql ) { ++$this->starts; }
		$fail = ( 'sale_commit' === $this->fail && ! $this->reconciling && 2 === $this->starts && 'COMMIT' === $sql )
			|| ( $this->reconciling && (
				( in_array( $this->fail, array( 'write', 'rollback' ), true ) && str_contains( $sql, 'SET cart_reconciliation_status =' ) )
				|| ( 'begin' === $this->fail && 'START TRANSACTION' === $sql )
				|| ( in_array( $this->fail, array( 'commit', 'commit_accepted' ), true ) && 'COMMIT' === $sql )
				|| ( 'rollback' === $this->fail && 'ROLLBACK' === $sql && TransactionManager::admitted_on($this) )
			) );
		if ( $fail ) {
			if ( 'commit_accepted' === $this->fail ) { parent::query( $sql ); }
			$this->last_error = 'injected transport/database failure'; $this->last_errno = 999; return false;
		}
		return parent::query( $sql );
	}
	public function get_results( string $sql ): ?array {
		$this->log[] = $sql;
		if ( str_contains($sql,'information_schema.TABLES') && str_contains($sql,$this->prefix.'c2c_carts') && str_contains($sql,$this->prefix.'c2c_cart_items') && str_contains($sql,$this->prefix.'c2c_order_items') ) {
			$this->reconciling = true;
			if ( null !== $this->on_reconciliation ) { ( $this->on_reconciliation )(); }
		}
		return parent::get_results( $sql );
	}
}
function db(): WebhookDb { global $prefix; return new WebhookDb( $prefix ); }
function check( bool $ok, string $label ): void { if ( ! $ok ) { throw new RuntimeException( $label ); } echo "PASS: {$label}\n"; }
function sql( wpdb $db, string $query ): void { if ( false === $db->query( $query ) ) { throw new RuntimeException( $db->last_error ); } }
function components( WebhookDb $db ): array {
	$tables = new TableNames( $db ); $clock = new UtcClock(); $tx = new TransactionManager( $db, $tables );
	$orders = new OrderRepository( $db, $tables ); $sessions = new PaymentSessionRepository( $db, $tables ); $events = new PaymentEventRepository( $db, $tables ); $reservations = new ReservationRepository( $db, $tables ); $carts = new CartRepository( $db, $tables );
	$validator = new ConversionReservationSetValidator(); $stock = new StockConversionProcessor( $reservations, new LockedCatalogReader( $db, $tx ), $tx, $validator );
	$conversion = new SaleConversionService( $orders, $reservations, $sessions, $events, $tx, $stock, $validator, new PostTypes(), $clock );
	$outcomes = new PaymentEventOutcomeService( $orders, $sessions, $events, $tx, $clock );
	$association = new PaymentEventAssociationService( $orders, $sessions, $events, $tx, $clock, false );
	$expiration = new PaymentExpirationService( $orders, $sessions, $events, $tx, $outcomes, $clock );
	$reconciliation = new CartReconciliationService( $carts, $orders, $tx, $clock );
	$orchestrator = new PaymentWebhookOrchestrator( new StripeEventNormalizer(), $events, $association, $conversion, $expiration, $outcomes, $clock, $reconciliation, $tx, new \RodolfoRizzo\Cpt2Collection\ProviderWebhookEvidence(static fn()=>new wpdb($db->prefix)) );
	return compact( 'tx', 'orchestrator' );
}
function payload( string $id = 'evt_phase2c', string $type = 'checkout.session.completed', string $payment_status = 'paid' ): string {
	return json_encode( array( 'id' => $id, 'object' => 'event', 'type' => $type, 'created' => 1790164800, 'livemode' => false, 'data' => array( 'object' => array( 'object' => 'checkout.session', 'id' => 'cs_phase2c', 'mode' => 'payment', 'client_reference_id' => str_repeat( 'a', 32 ), 'metadata'=>['c2c_integration'=>'cpt2collection','c2c_installation_id'=>str_repeat('a',32),'c2c_epoch_identity'=>str_repeat('b',32),'c2c_payment_session_reference'=>str_repeat('a',32)], 'payment_intent' => 'pi_phase2c', 'currency' => 'eur', 'amount_total' => 200, 'payment_status' => $payment_status, 'status' => 'checkout.session.expired' === $type ? 'expired' : 'complete' ) ) ), JSON_THROW_ON_ERROR );
}
function deliver( array $services, string $raw ): WP_REST_Response {
	$secret = 'whsec_' . str_repeat( 'a', 32 ); $at = time();
	$signature = 't=' . $at . ',v1=' . hash_hmac( 'sha256', $at . '.' . $raw, $secret );
	$controller = new StripeWebhookRestController( new StripeWebhookVerifier( $secret ), $services['orchestrator'] );
	return $controller->receive( new WP_REST_Request( $raw, $signature ) );
}
function seed( WebhookDb &$db, string $case = 'delta' ): void {
	if (!$db->transaction_connection_usable()) { $db->close(); $db=db(); }
	global $prefix;
	foreach ( array( 'c2c_provider_identities', 'c2c_provider_claims', 'c2c_payment_events', 'c2c_payment_sessions', 'c2c_reservations', 'c2c_order_items', 'c2c_orders', 'c2c_cart_items', 'c2c_carts', 'postmeta', 'posts' ) as $table ) { sql( $db, "DELETE FROM {$prefix}{$table}" ); }
	$now = gmdate( 'Y-m-d H:i:s' );
	sql( $db, "INSERT INTO {$prefix}posts VALUES(123,'collection_item','Phase2C')" );
	sql( $db, "INSERT INTO {$prefix}postmeta(post_id,meta_key,meta_value) VALUES(123,'_cpt2collection_stock_quantity','10')" );
	$t = new TableNames( $db ); $seed_tx = new TransactionManager($db,$t); check($seed_tx->begin_commerce_shared(), "seed admitted"); $carts = new CartRepository( $db, $t ); $orders = new OrderRepository( $db, $t ); $reservations = new ReservationRepository( $db, $t ); $sessions = new PaymentSessionRepository( $db, $t );
	$carts->create( hash( 'sha256', 'phase2c' ), $now, $now, $now, '2030-01-01 00:00:00' );
	sql( $db, "UPDATE {$prefix}c2c_carts SET id=1,content_revision=7,checkout_generation=3" );
	$quantity = 'exact' === $case ? 2 : ( 'reduced' === $case ? 1 : 5 ); $version = 'exact' === $case ? 0 : 1;
	sql( $db, "INSERT INTO {$prefix}c2c_cart_items VALUES(42,1,123,{$quantity},{$version},'{$now}','{$now}')" );
	$orders->create( 1, 'awaiting_payment', 'EUR', 200, 200, $now, $now, hash('sha256','modern-checkout'), 'exact' === $case ? 7 : 6, 3 );
	sql( $db, "UPDATE {$prefix}c2c_orders SET id=1" );
	$orders->insert_item( 1, 123, 'Snapshot', 100, 2, 200, 200, 'EUR', $now, 42, 0 );
	sql( $db, "UPDATE {$prefix}c2c_order_items SET id=10" );
	$reservations->create( 1, 10, 123, 2, 'active', $now, $now, gmdate( 'Y-m-d H:i:s', time() + 3600 ) );
	$session = $sessions->create_attempt( str_repeat( 'a', 32 ), 1, 'stripe_checkout', hash( 'sha256', 'key' ), 'EUR', 200, $now );
	$context=$seed_tx->commerce_context();
	$request=new \RodolfoRizzo\Cpt2Collection\PaymentProviderSessionRequest($session->reference(),'c2c_ps_'.$session->reference(),'EUR',200,[new \RodolfoRizzo\Cpt2Collection\PaymentProviderLineItem('Snapshot',100,2)],gmdate('Y-m-d H:i:s',time()+3600),['environment'=>'test','installation_id'=>$context->installation_id(),'epoch_identity'=>$context->epoch_identity()]);
	check( false !== $session && false !== $sessions->registry()->create_claim( $session, $context, $request->snapshot(), 'test', $now ), 'fixture provider claim created with exact original request money' );
	check( false !== $session && $sessions->activate( $session->id(), 'cs_phase2c', 'https://example.test', gmdate( 'Y-m-d H:i:s', time() + 1800 ), $now ), 'fixture payment session created' );
	if ( 'attention' === $case ) { sql( $db, "UPDATE {$prefix}c2c_cart_items SET post_id=999" ); }
	if ( 'readd' === $case ) { sql( $db, "UPDATE {$prefix}c2c_cart_items SET id=57,content_version=0" ); }
	check($seed_tx->commit(), 'seed committed');
	$db->log = array(); $db->starts = 0; $db->reconciling = false; $db->on_reconciliation = null;
}
function row( wpdb $db, string $table ): ?object { global $prefix; return $db->get_row( "SELECT * FROM {$prefix}{$table} LIMIT 1" ); }
function financial_state( wpdb $db ): string {
	global $prefix;
	return serialize( array( $db->get_row( "SELECT status,paid_at,failed_at,updated_at FROM {$prefix}c2c_orders WHERE id=1" ), $db->get_results( "SELECT * FROM {$prefix}c2c_payment_sessions" ), $db->get_results( "SELECT * FROM {$prefix}c2c_payment_events ORDER BY id" ), $db->get_results( "SELECT * FROM {$prefix}c2c_reservations" ), $db->get_results( "SELECT * FROM {$prefix}postmeta" ) ) );
}
function assert_paid( wpdb $db ): void {
	global $prefix;
	$o = row( $db, 'c2c_orders' ); $s = row( $db, 'c2c_payment_sessions' ); $r = row( $db, 'c2c_reservations' ); $e = $db->get_row( "SELECT * FROM {$prefix}c2c_payment_events WHERE result_code='sale_converted'" );
	check( 'paid' === $o->status && null !== $o->paid_at && null === $o->failed_at && 'completed' === $s->status && 'converted' === $r->status && '8' === row( $db, 'postmeta' )->meta_value, 'paid sale, completed session and stock converted exactly once' );
	check( null !== $e && 'processed' === $e->processing_status && 'sale_converted' === $e->result_code && 1 === (int) $e->attempt_count && null === $e->error_code && null === $e->attention_at, 'processed event retains original successful payment evidence' );
}

function create_schema( wpdb $db ): void {
	global $prefix;
	$definitions = array(
		'posts' => '(ID BIGINT UNSIGNED PRIMARY KEY, post_type VARCHAR(32), post_title VARCHAR(255)) ENGINE=InnoDB',
		'postmeta' => '(meta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,post_id BIGINT UNSIGNED,meta_key VARCHAR(255),meta_value LONGTEXT,KEY post_id(post_id)) ENGINE=InnoDB',
		'c2c_carts' => "(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, token_hash CHAR(64) NOT NULL, content_revision BIGINT UNSIGNED NOT NULL DEFAULT 0, checkout_generation BIGINT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, last_activity_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY token_hash (token_hash), KEY expires_at (expires_at), KEY last_activity_at (last_activity_at)) ENGINE=InnoDB",
		'c2c_cart_items' => "(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, cart_id BIGINT UNSIGNED NOT NULL, post_id BIGINT UNSIGNED NOT NULL, quantity INT UNSIGNED NOT NULL DEFAULT 1, content_version BIGINT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id), UNIQUE KEY cart_product (cart_id, post_id), KEY cart_id (cart_id), KEY post_id (post_id)) ENGINE=InnoDB",
		'c2c_orders' => "(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, cart_id BIGINT UNSIGNED NULL, cart_revision BIGINT UNSIGNED NULL, checkout_generation BIGINT UNSIGNED NULL, checkout_attempt_hash CHAR(64) NULL, cart_reconciliation_status VARCHAR(32) NULL, cart_reconciliation_code VARCHAR(64) NULL, cart_reconciliation_updated_at DATETIME NULL, status VARCHAR(32) NOT NULL DEFAULT 'pending', currency CHAR(3) NOT NULL, subtotal_minor BIGINT UNSIGNED NOT NULL DEFAULT 0, total_minor BIGINT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, paid_at DATETIME NULL, cancelled_at DATETIME NULL, expired_at DATETIME NULL, failed_at DATETIME NULL, PRIMARY KEY (id), KEY cart_id (cart_id), UNIQUE KEY cart_revision_generation (cart_id, cart_revision, checkout_generation), UNIQUE KEY cart_checkout_attempt (cart_id, checkout_attempt_hash), KEY status (status), KEY created_at (created_at), KEY updated_at (updated_at)) ENGINE=InnoDB",
		'c2c_order_items' => "(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, order_id BIGINT UNSIGNED NOT NULL, post_id BIGINT UNSIGNED NOT NULL, source_cart_item_id BIGINT UNSIGNED NULL, source_cart_item_version BIGINT UNSIGNED NULL, item_name VARCHAR(255) NOT NULL, unit_price_minor BIGINT UNSIGNED NOT NULL, quantity INT UNSIGNED NOT NULL, line_subtotal_minor BIGINT UNSIGNED NOT NULL, line_total_minor BIGINT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY order_id (order_id), KEY post_id (post_id), KEY order_post (order_id, post_id)) ENGINE=InnoDB",
		'c2c_reservations' => "(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, order_id BIGINT UNSIGNED NOT NULL, order_item_id BIGINT UNSIGNED NOT NULL, post_id BIGINT UNSIGNED NOT NULL, quantity INT UNSIGNED NOT NULL, status VARCHAR(32) NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, released_at DATETIME NULL, converted_at DATETIME NULL, PRIMARY KEY (id), UNIQUE KEY order_item_id (order_item_id), KEY order_id (order_id), KEY post_id (post_id), KEY active_stock_lookup (post_id, status, expires_at), KEY status_expires (status, expires_at), KEY expires_at (expires_at)) ENGINE=InnoDB",
		'c2c_payment_sessions' => "(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, reference CHAR(32) NOT NULL, order_id BIGINT UNSIGNED NOT NULL, provider VARCHAR(32) NOT NULL, provider_session_id VARCHAR(255) NULL, provider_payment_id VARCHAR(255) NULL, provider_event_id VARCHAR(255) NULL, creation_key_hash CHAR(64) NOT NULL, status VARCHAR(32) NOT NULL DEFAULT 'creating', active_slot TINYINT UNSIGNED NULL, currency CHAR(3) NOT NULL, amount_minor BIGINT UNSIGNED NOT NULL, redirect_url TEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, expires_at DATETIME NULL, expired_at DATETIME NULL, completed_at DATETIME NULL, cancelled_at DATETIME NULL, failed_at DATETIME NULL, payment_confirmed_at DATETIME NULL, confirmed_currency CHAR(3) NULL, confirmed_amount_minor BIGINT UNSIGNED NULL, reconciliation_required_at DATETIME NULL, reconciliation_code VARCHAR(64) NULL, reconciliation_event_id BIGINT UNSIGNED NULL, PRIMARY KEY (id), UNIQUE KEY reference (reference), UNIQUE KEY order_creation_key (order_id, creation_key_hash), UNIQUE KEY provider_session (provider, provider_session_id), UNIQUE KEY provider_payment (provider, provider_payment_id), UNIQUE KEY order_active_slot (order_id, active_slot), KEY order_id (order_id), KEY order_status (order_id, status), KEY status_expires (status, expires_at), KEY updated_at (updated_at)) ENGINE=InnoDB",
		'c2c_payment_events' => "(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, provider VARCHAR(32) NOT NULL, provider_event_id VARCHAR(255) NOT NULL, event_type VARCHAR(128) NOT NULL, provider_created_at DATETIME NULL, livemode TINYINT(1) NOT NULL, payment_session_id BIGINT UNSIGNED NULL, provider_session_id VARCHAR(255) NULL, local_reference CHAR(32) NULL, provider_payment_id VARCHAR(255) NULL, currency CHAR(3) NULL, amount_minor BIGINT UNSIGNED NULL, payment_status VARCHAR(32) NULL, session_status VARCHAR(32) NULL, processing_status VARCHAR(32) NOT NULL, result_code VARCHAR(64) NULL, attempt_count INT UNSIGNED NOT NULL DEFAULT 0, received_at DATETIME NOT NULL, last_attempt_at DATETIME NULL, processed_at DATETIME NULL, attention_at DATETIME NULL, error_code VARCHAR(64) NULL, error_detail TEXT NULL, payload_sha256 CHAR(64) NOT NULL, normalized_payload LONGTEXT NULL, PRIMARY KEY (id), UNIQUE KEY provider_event (provider, provider_event_id), KEY provider_session (provider, provider_session_id), KEY payment_session_id (payment_session_id), KEY processing_status (processing_status), KEY status_received (processing_status, received_at), KEY attention_at (attention_at), KEY received_at (received_at)) ENGINE=InnoDB",
	);
	foreach ( $definitions as $table => $definition ) { sql( $db, "CREATE TABLE {$prefix}{$table} {$definition}" ); }
	create_commerce_foundation($db);
	migrate_commerce_phase_two_fixture($db); attest_modern_runtime_fixture($db); current_provider_runtime_fixture($db);
}
$db = db();
try {
	create_schema( $db );
	foreach ( array( 'exact', 'delta', 'reduced', 'attention', 'readd' ) as $case ) {
		seed( $db, $case ); $services = components( $db ); $observer = db(); $observations = 0;
		$db->on_reconciliation = function () use ( $db, $observer, $services, &$observations ): void {
			++$observations;
			check( ! $services['tx']->is_active() && 0 === (int) $db->get_var( 'SELECT @@in_transaction' ), 'reconciliation entry has no application or native sale transaction' );
			assert_paid( $observer );
		};
		$response = deliver( $services, payload() ); $db->on_reconciliation = null; $observer->close();
		check( ('attention' === $case ? 500 : 200) === $response->get_status() && array( 'code' => ('attention' === $case ? 'delivery_failed' : 'accepted') ) === $response->get_data() && 1 === $observations, $case . ' signed webhook reconciles after externally visible sale commit' );
		$order = row( $db, 'c2c_orders' ); $item = row( $db, 'c2c_cart_items' ); $cart = row( $db, 'c2c_carts' );
		$expected_code = 'attention' === $case ? null : 'cart_reconciled';
		check( ( 'attention' === $case ? 'pending' : 'completed' ) === $order->cart_reconciliation_status && $expected_code === $order->cart_reconciliation_code && ('attention' === $case ? null === $order->cart_reconciliation_updated_at : null !== $order->cart_reconciliation_updated_at), $case . ' durable reconciliation outcome' );
		if ( in_array($case,array('exact','reduced'),true) ) { check( null === $item && 8 === (int) $cart->content_revision, 'purchased unchanged row removed' ); }
		elseif ( 'delta' === $case ) { check( 3 === (int) $item->quantity && 2 === (int) $item->content_version && 8 === (int) $cart->content_revision, 'positive delta, version and revision preserved through webhook' ); }
		else { check( 5 === (int) $item->quantity && 7 === (int) $cart->content_revision && ( 'readd' !== $case || ( 57 === (int) $item->id && 0 === (int) $item->content_version ) ), 'terminal no-op preserves newer or unavailable source intent' ); }
		$before = financial_state( $db ); $cart_before = serialize( array( $order, $item, $cart ) );
		check( ('attention' === $case ? 500 : 200) === deliver( $services, payload() )->get_status(), 'same-event replay either validates terminal cart or preserves nonmutating source refusal' );
		check( $before === financial_state( $db ) && $cart_before === serialize( array( row( $db, 'c2c_orders' ), row( $db, 'c2c_cart_items' ), row( $db, 'c2c_carts' ) ) ), 'terminal replay changes neither sale evidence nor cart/outcome timestamps' );
	}

	seed( $db ); $services = components( $db ); $db->fail = 'write';
	$response = deliver( $services, payload() ); $db->fail = null;
	check( 500 === $response->get_status() && array( 'code' => 'delivery_failed' ) === $response->get_data(), 'transient reconciliation failure requests HTTP retry' );
	assert_paid( $db );
	check( 'pending' === row( $db, 'c2c_orders' )->cart_reconciliation_status && 5 === (int) row( $db, 'c2c_cart_items' )->quantity && 1 === (int) row( $db, 'c2c_cart_items' )->content_version && 7 === (int) row( $db, 'c2c_carts' )->content_revision, 'reconciliation rollback restores quantity/version/revision and leaves sale paid' );
	$before = financial_state( $db ); $db->close(); $db = db(); $services = components( $db );
	check( 200 === deliver( $services, payload() )->get_status() && $before === financial_state( $db ), 'fresh same-event replay retries reconciliation without rewriting processed payment evidence' );
	check( 3 === (int) row( $db, 'c2c_cart_items' )->quantity && 2 === (int) row( $db, 'c2c_cart_items' )->content_version && 8 === (int) row( $db, 'c2c_carts' )->content_revision, 'retry subtracts purchased intent exactly once' );
	check( 200 === deliver( $services, payload() )->get_status() && 3 === (int) row( $db, 'c2c_cart_items' )->quantity, 'subsequent replay cannot subtract twice' );

	// Both supported paid event types converge on the same financial identity.
	check( 200 === deliver( $services, payload( 'evt_async', 'checkout.session.async_payment_succeeded' ) )->get_status(), 'distinct asynchronous success event recognizes converted sale' );
	check( 3 === (int) row( $db, 'c2c_cart_items' )->quantity && '8' === row( $db, 'postmeta' )->meta_value, 'equivalent event type does not repeat sale or cart subtraction' );
	$second = $db->get_row( "SELECT * FROM {$prefix}c2c_payment_events WHERE provider_event_id='evt_async'" );
	check( 'processed' === $second->processing_status && 'sale_already_converted' === $second->result_code, 'distinct event records idempotent sale evidence' );

	foreach ( array( 'begin', 'commit', 'commit_accepted', 'rollback' ) as $failure ) {
		seed( $db ); $services = components( $db ); $db->fail = $failure;
		$response = deliver( $services, payload() );
		check( 500 === $response->get_status() && $services['tx']->is_outcome_uncertain(), $failure . ' reconciliation uncertainty requests a fresh delivery' );
		check( in_array( end( $db->log ), array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ), 'uncertain boundary is the last SQL statement' );
		$log = $db->log;
		$result = $services['orchestrator']->handle( json_decode( payload(), true ), hash( 'sha256', payload() ) );
		check( 500 === $result->status() && 'transaction_outcome_unknown' === $result->code() && $log === $db->log, 'unsafe same-instance reuse stops before receipt persistence' );
		$observer = db(); assert_paid( $observer ); $before = financial_state( $observer ); $observer->close();
		$db->close(); $db = db(); $services = components( $db );
		check( 200 === deliver( $services, payload() )->get_status() && $before === financial_state( $db ), 'fresh request safely resolves uncertain cart outcome without changing sale' );
		check( 3 === (int) row( $db, 'c2c_cart_items' )->quantity && 2 === (int) row( $db, 'c2c_cart_items' )->content_version && 8 === (int) row( $db, 'c2c_carts' )->content_revision, 'uncertain replay converges with one cart mutation' );
	}

	seed( $db ); $services = components( $db ); $db->fail = 'sale_commit';
	check( 500 === deliver( $services, payload() )->get_status() && ! $db->reconciling && 'COMMIT' === end( $db->log ), 'uncertain sale commit never invokes reconciliation or follow-up SQL' );
	$db->close(); $db = db(); $services = components( $db );
	check( 200 === deliver( $services, payload() )->get_status(), 'fresh delivery recovers uncommitted sale before reconciliation' ); assert_paid( $db );

	seed( $db ); $services = components( $db );
	$services['tx']->begin(); $log = $db->log;
	check( 500 === deliver( $services, payload() )->get_status() && $log === $db->log && $services['tx']->is_active(), 'active caller transaction rejected without SQL or rollback' ); $services['tx']->rollback();
	foreach ( array( array( 'evt_unpaid', 'checkout.session.completed', 'unpaid' ), array( 'evt_other', 'charge.succeeded', 'paid' ), array( 'evt_expired', 'checkout.session.expired', 'unpaid' ) ) as [ $id, $type, $payment_status ] ) {
		seed( $db ); $services = components( $db );
		check( 200 === deliver( $services, payload( $id, $type, $payment_status ) )->get_status() && ! $db->reconciling && 'pending' === row( $db, 'c2c_orders' )->cart_reconciliation_status, $type . '/' . $payment_status . ' does not reconcile unpaid/lifecycle event' );
		check( 200 === deliver( $services, payload( $id, $type, $payment_status ) )->get_status() && ! $db->reconciling, 'unrelated terminal replay remains unchanged' );
	}
	seed( $db ); $services = components( $db );
	sql( $db, "UPDATE {$prefix}postmeta SET meta_value='1'" );
	check( 200 === deliver( $services, payload() )->get_status() && ! $db->reconciling, 'sale attention does not invoke cart reconciliation' );
	check( 'awaiting_payment' === row( $db, 'c2c_orders' )->status && 'pending' === row( $db, 'c2c_orders' )->cart_reconciliation_status && 'attention' === row( $db, 'c2c_payment_events' )->processing_status && 5 === (int) row( $db, 'c2c_cart_items' )->quantity, 'pre-sale attention retains existing financial and cart behavior' );

	seed( $db ); $services = components( $db ); $verified = json_decode( payload(), true ); $hash = hash( 'sha256', payload() );
	check( 'sale_converted' === $services['orchestrator']->handle( $verified, $hash )->code(), 'successful fresh webhook disposition code is unchanged' );
	check( 'coherent_idempotent_delivery' === $services['orchestrator']->handle( $verified, $hash )->code(), 'successful processed replay disposition code is unchanged' );

	foreach ( array( 'same_event', 'equivalent_events', 'mutation', 'readd' ) as $scenario ) {
		seed( $db ); $db->close(); $db = null; $children = array();
		$payloads = array( payload() );
		if ( in_array( $scenario, array( 'same_event', 'equivalent_events' ), true ) ) { $payloads[] = 'same_event' === $scenario ? payload() : payload( 'evt_concurrent_async', 'checkout.session.async_payment_succeeded' ); }
		foreach ( $payloads as $raw ) {
			$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
			foreach ( $pair as $socket ) { stream_set_timeout( $socket, 15 ); }
			$pid = pcntl_fork();
			if ( 0 === $pid ) {
				fclose( $pair[0] );
				try {
					if ( "go\n" !== fgets( $pair[1] ) ) { exit( 20 ); }
					$child = db(); $services = components( $child );
					fwrite( $pair[1], "ready\n" ); fflush( $pair[1] );
					$response = deliver( $services, $raw );
					fwrite( $pair[1], json_encode( array( 'status' => $response->get_status(), 'active' => $services['tx']->is_active() ) ) . "\n" );
					$child->close(); exit( 0 );
				} catch ( Throwable $error ) { fwrite( $pair[1], json_encode( array( 'error' => $error->getMessage() ) ) . "\n" ); exit( 21 ); }
			}
			fclose( $pair[1] ); $children[] = array( $pid, $pair[0] );
		}
		// This lock-order observer must see the sale committed after its cart lock,
		// rather than upgrade an older MVCC snapshot (MariaDB may return errno 1020).
		$db = db(); sql($db,'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'); $race_tx = new TransactionManager( $db, new TableNames( $db ) ); check( $race_tx->begin_commerce_shared(), 'competing cart writer admitted' );
		$db->get_row( "SELECT id FROM {$prefix}c2c_carts WHERE id=1 FOR UPDATE" );
		foreach ( $children as [ $pid, $socket ] ) { fwrite( $socket, "go\n" ); fflush( $socket ); check( "ready\n" === fgets( $socket ), 'signed webhook worker started' ); }
		// A1 now drains all normal writers at exclusive control before evidence.
		$observer = db(); $deadline = microtime( true ) + 10;
		do { $waiting = (int)$observer->get_var("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE INFO LIKE 'SELECT %FROM {$prefix}c2c_commerce_control%FOR UPDATE' AND TIME_MS>=200")>0; if(!$waiting)usleep(10000); } while(!$waiting&&microtime(true)<$deadline);
		check($waiting,'authenticated webhook waits at A1 control behind admitted cart writer');
		check('awaiting_payment'===row($observer,'c2c_orders')->status&&'pending'===row($observer,'c2c_orders')->cart_reconciliation_status&&5===(int)row($observer,'c2c_cart_items')->quantity,'evidence wait precedes sale and reconciliation effects');$observer->close();
		// The waiting evidence transaction cannot hold downstream order/cart locks.
		sql( $db, 'SET SESSION innodb_lock_wait_timeout=2' );
		check( null !== $db->get_row( "SELECT id FROM {$prefix}c2c_orders WHERE id=1 FOR UPDATE" ) && '' === $db->last_error, 'control wait holds no downstream order lock: '.$db->last_error );
		$carts = new CartRepository( $db, new TableNames( $db ) );
		if ( 'mutation' === $scenario ) {
			check( 1 === $carts->update_item_quantity_locked( 1, 123, 7 ) && $carts->advance_content_revision( 1, 7, gmdate( 'Y-m-d H:i:s' ) ), 'user changes quantity under cart lock before reconciliation' );
		} elseif ( 'readd' === $scenario ) {
			check( 1 === $carts->delete_item_locked( 1, 123 ) && false !== $carts->add_item_quantity_locked( 1, 123, 6 ) && $carts->advance_content_revision( 1, 7, gmdate( 'Y-m-d H:i:s' ) ), 'user replaces source identity under cart lock' );
		}
		check( $race_tx->commit(), 'competing cart writer committed' );
		foreach ( $children as [ $pid, $socket ] ) {
			$result = json_decode( (string) fgets( $socket ), true, 512, JSON_THROW_ON_ERROR );
			pcntl_waitpid( $pid, $status ); fclose( $socket );
			check( pcntl_wifexited( $status ) && 0 === pcntl_wexitstatus( $status ) && in_array( $result['status'] ?? null, array( 200, 500 ), true ) && ! $result['active'], 'concurrent worker returns success or safe retry with no active transaction' );
		}
		// Existing association races may request retry when another delivery just processed it.
		foreach ( $payloads as $raw ) { check( 200 === deliver( components( $db ), $raw )->get_status(), 'concurrent delivery converges on replay' ); }
		assert_paid( $db ); $item = row( $db, 'c2c_cart_items' ); $revision = (int) row( $db, 'c2c_carts' )->content_revision;
		if ( 'mutation' === $scenario ) { check( 5 === (int) $item->quantity && 3 === (int) $item->content_version && 9 === $revision, 'integrated reconciliation preserves concurrent positive intent' ); }
		elseif ( 'readd' === $scenario ) { check( 42 !== (int) $item->id && 6 === (int) $item->quantity && 0 === (int) $item->content_version && 8 === $revision, 'integrated reconciliation leaves replacement row untouched' ); }
		else { check( 3 === (int) $item->quantity && 2 === (int) $item->content_version && 8 === $revision, 'duplicate/equivalent deliveries mutate cart once' ); }
	}
	echo "All Phase 2C signed-webhook MariaDB checks passed.\n";
} finally {
 if (!$db->transaction_connection_usable()) {$db->close();$db=db();}
	if ( null === $db ) { $db = db(); }
	$db->fail = null; $db->query( 'ROLLBACK' );
	foreach ( array( 'c2c_commerce_resets', 'c2c_order_deletions', 'c2c_commerce_control', 'c2c_commerce_epochs', 'c2c_payment_events', 'c2c_payment_sessions', 'c2c_reservations', 'c2c_order_items', 'c2c_orders', 'c2c_cart_items', 'c2c_carts', 'postmeta', 'posts' ) as $table ) { $db->query( "DROP TABLE IF EXISTS {$prefix}{$table}" ); }
	$db->close();
}
