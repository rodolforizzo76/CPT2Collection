<?php
/** Phase 1: real SQL ownership/projection and read-only transport verification.
 * Run with C2C_TEST_DB_NAME and the existing MariaDB test connection variables.
 * Uses uniquely prefixed disposable copies of the production schema.
 */
declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection {
	// Capture the final HTTP transport policy, including removal of core CORS.
	function header_remove( string $name ): void { unset( $GLOBALS['transport'][strtolower( $name )] ); }
	function header( string $value ): void { [$name, $value] = explode( ':', $value, 2 ); $GLOBALS['transport'][strtolower( $name )] = trim( $value ); }
}
namespace {
if ( ! extension_loaded( 'mysqli' ) || false === getenv( 'C2C_TEST_DB_NAME' ) ) {
	echo "SKIP: mysqli and C2C_TEST_DB_NAME required.\n"; exit( 0 );
}
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
$db_host = getenv( 'C2C_TEST_DB_HOST' ) ?: '127.0.0.1';
$db_port = (int) ( getenv( 'C2C_TEST_DB_PORT' ) ?: 3306 );
$db_user = getenv( 'C2C_TEST_DB_USER' ) ?: 'root';
$db_pass = getenv( 'C2C_TEST_DB_PASSWORD' ) ?: '';
$db_name = (string) getenv( 'C2C_TEST_DB_NAME' );
$prefix = 'c2c_read_' . bin2hex( random_bytes( 4 ) ) . '_';
mysqli_report( MYSQLI_REPORT_OFF );
require_once __DIR__ . '/support/mariadb-wpdb.php';
function __( string $text, string $domain ): string { return $text; }
function home_url( string $path ): string { return ( $GLOBALS['test_site_origin'] ?? 'https://shop.example' ) . $path; }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function wp_unslash( string $value ): string { return stripslashes( $value ); }
function current_time( string $type, bool $utc ): int { return 1790164800; }
function get_option( string $name, mixed $default = false ): mixed {
	return match ( $name ) { 'cpt2collection_currency' => 'EUR', 'cpt2collection_post_type' => 'collection_item', default => $default };
}
function apply_filters( string $name, mixed $value ): mixed { return $value; }
function wp_cache_delete( int $id, string $group ): bool { return true; }
class WP_Post_Type { public bool $_builtin = false; public bool $public = true; public bool $show_ui = true; public string $name = 'collection_item'; }
function get_post_types( array $args, string $output ): array { return array( 'collection_item' => new WP_Post_Type() ); }
function register_rest_route( string $namespace, string $route, array $args ): void { $GLOBALS['route'] = [$namespace, $route, $args]; }
function add_filter( string $name, callable $callback, int $priority, int $args ): void { $GLOBALS['filters'][$name] = [$callback, $priority, $args]; }
class WP_Error { public function __construct( public string $code, public string $message, public array $data ) {} }
class WP_REST_Request {
	public function __construct( private array $params = array(), private array $headers = array(), private string $route = '/cpt2collection/v1/post-checkout' ) {}
	public function get_param( string $name ): mixed { return $this->params[$name] ?? null; }
	// WordPress returns null for an absent header. Mixed also permits hostile-value guard tests.
	public function get_header( string $name ): mixed { return $this->headers[$name] ?? null; }
	public function get_route(): string { return $this->route; }
}
class WP_REST_Response {
	public function __construct( private mixed $data, private int $status = 200, private array $headers = array() ) {}
	public function get_data(): mixed { return $this->data; }
	public function get_status(): int { return $this->status; }
	public function get_headers(): array { return $this->headers; }
	public function set_headers( array $headers ): void { $this->headers = $headers; }
	public function header( string $name, string $value ): void { $this->headers[$name] = $value; }
}
foreach ( array( 'table-names', 'cart-token', 'cart-cookie', 'utc-clock', 'money', 'order-statuses', 'post-checkout-read-repository', 'post-checkout-view', 'post-checkout-rest-controller' ) as $class ) {
	require_once dirname( __DIR__ ) . '/includes/class-' . $class . '.php';
}
use RodolfoRizzo\Cpt2Collection\{TableNames,CartToken,CartCookie,UtcClock,Money,PostCheckoutReadRepository,PostCheckoutRestController};
class FixtureDb extends wpdb {
	public $observe = null;
	public function query( string $sql ): int|false {
		$result = parent::query( $sql );
		if ( null !== $this->observe ) { ( $this->observe )( $sql ); }
		return $result;
	}
}
class ReadDb extends wpdb {
	public array $reads = array();
	public bool $fail = false;
	public int $write_attempts = 0;
	public function query( string $sql ): int|false { ++$this->write_attempts; throw new RuntimeException( 'Endpoint attempted non-read SQL: ' . $sql ); }
	public function get_results( string $sql ): ?array {
		$this->reads[] = $sql;
		if ( ! preg_match( '/\ASELECT\s/i', $sql ) || preg_match( '/FOR\s+UPDATE|LOCK\s+IN\s+SHARE/i', $sql ) ) { throw new RuntimeException( 'Endpoint attempted a locking read.' ); }
		if ( $this->fail ) { $this->last_error = 'PRIVATE DATABASE ERROR secret'; return null; }
		return parent::get_results( $sql );
	}
}
$checks = 0;
function check( bool $condition, string $label ): void { global $checks; if ( ! $condition ) { throw new RuntimeException( $label ); } ++$checks; echo "PASS: {$label}\n"; }
function sql( wpdb $db, string $sql ): void { if ( false === $db->query( $sql ) ) { throw new RuntimeException( $db->last_error ); } }
function state( wpdb $db, array $tables ): string { $rows = array(); foreach ( $tables as $table ) { $rows[$table] = $db->get_results( "SELECT * FROM {$table} ORDER BY id" ); } return serialize( $rows ); }
function lookup( PostCheckoutRestController $controller, mixed $selector = 'cs_test_owned', array $headers = array() ): WP_REST_Response { return $controller->read( new WP_REST_Request( array( 'session_id' => $selector ), $headers ) ); }
function public_result( WP_REST_Response $response ): array { return [$response->get_status(), $response->get_data(), $response->get_headers()]; }
function private_headers( WP_REST_Response $response ): bool { $headers = array_change_key_case( $response->get_headers() ); return 'private, no-store, max-age=0' === ( $headers['cache-control'] ?? null ) && 'Origin, Cookie' === ( $headers['vary'] ?? null ) && ! isset( $headers['access-control-allow-origin'] ) && ! isset( $headers['access-control-allow-credentials'] ); }
$writer = new FixtureDb( $prefix );
$reader = new ReadDb( $prefix );
$tables = new TableNames( $writer );
$created = array();
$extra_tables = array();
try {
	// Use the exact production definitions without invoking migration/bootstrap.
	$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-database.php' );
	preg_match_all( '/"CREATE TABLE (.*?) ENGINE=InnoDB \{\$charset_collate\};"/s', $source, $matches );
	check( 9 === count( $matches[1] ), 'all production commerce table definitions found' );
	foreach ( $matches[1] as $definition ) {
		$definition = preg_replace_callback( '/\{\$tables->([a-z_]+)\(\)\}/', static fn( array $m ): string => $tables->{$m[1]}(), $definition );
		preg_match( '/\A([^\s]+)/', $definition, $name );
		sql( $writer, 'CREATE TABLE ' . $definition . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' ); $created[] = $name[1];
	}
		seed_commerce_foundation( $writer );
		$created = array_merge( $created, migrate_commerce_phase_two_fixture( $writer ) ); attest_modern_runtime_fixture($writer); current_provider_runtime_fixture($writer);
	$token = str_repeat( 'a', 64 ); $hash = hash( 'sha256', $token );
	$now = '2026-09-23 12:00:00';
	sql( $writer, "INSERT INTO {$tables->carts()} (id,token_hash,content_revision,checkout_generation,created_at,updated_at,last_activity_at,expires_at) VALUES (1,'{$hash}',99,88,'{$now}','{$now}','{$now}','2030-01-01 00:00:00')" );
	sql( $writer, "INSERT INTO {$tables->orders()} (id,cart_id,cart_revision,checkout_generation,checkout_attempt_hash,status,currency,subtotal_minor,total_minor,created_at,updated_at,paid_at,cart_reconciliation_status,cart_reconciliation_code) VALUES (7,1,0,0,REPEAT('d',64),'paid','USD',600,600,'{$now}','{$now}','{$now}','completed','cart_reconciled')" );
	$name = '<script>alert("x")</script> & "Snapshot" — old name';
	sql( $writer, $writer->prepare( "INSERT INTO {$tables->order_items()} (id,order_id,post_id,source_cart_item_id,source_cart_item_version,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at) VALUES (11,7,123,42,0,%s,200,3,600,600,'USD',%s)", $name, $now ) );
	sql( $writer, "INSERT INTO {$tables->payment_sessions()} (id,reference,order_id,provider,provider_session_id,creation_key_hash,status,currency,amount_minor,redirect_url,created_at,updated_at) VALUES (9,REPEAT('b',32),7,'stripe_checkout','cs_test_owned',REPEAT('c',64),'completed','USD',600,'https://checkout.stripe.com/PRIVATE','{$now}','{$now}')" );
	sql( $writer, "INSERT INTO {$tables->provider_claims()} (id,installation_id,epoch_id,epoch_identity,provider,reference,order_id,payment_session_id,creation_key_hash,idempotency_key,request_version,request_snapshot,environment,evidence_state,disposition,created_at) VALUES (1,REPEAT('a',32),1,REPEAT('b',32),'stripe_checkout',REPEAT('b',32),7,9,REPEAT('c',64),CONCAT('c2c_ps_',REPEAT('b',32)),2,'{}','test','native','open','{$now}')" );
	sql( $writer, "INSERT INTO {$tables->provider_identities()} (provider,identity_kind,identity_value,claim_id,binding_state,evidence_state,observed_epoch_id,first_seen_at,environment,disposition) VALUES ('stripe_checkout','session','cs_test_owned',1,'bound','native',1,'{$now}','test','pending')" );
	$_COOKIE[CartCookie::COOKIE_NAME] = $token;
	$tokens = new CartToken();
	$controller = new PostCheckoutRestController( new PostCheckoutReadRepository( $reader, new TableNames( $reader ) ), new CartCookie( $tokens ), $tokens, new UtcClock() );
	$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/CPT2Collection/v1/Post-Checkout' ) );
	$controller->register();
	check( 'private, no-store, max-age=0' === $GLOBALS['transport']['cache-control'], 'privacy headers precede core early JSONP errors, including mixed-case routes' );
	unset( $GLOBALS['wp'] );
	check( array_slice( $GLOBALS['route'], 0, 2 ) === array( 'cpt2collection/v1', '/post-checkout' ) && 'GET' === $GLOBALS['route'][2]['methods'], 'only GET route registered' );
	check( PHP_INT_MAX === $GLOBALS['filters']['rest_pre_serve_request'][1] && PHP_INT_MAX === $GLOBALS['filters']['rest_post_dispatch'][1], 'route-scoped protection runs after core CORS and on REST errors' );
	$before = state( $writer, $created ); $cookies = $_COOKIE;
	$response = lookup( $controller ); $data = $response->get_data();
	check( 200 === $response->get_status() && 'confirmed' === $data['payment_state'] && 'completed' === $data['cart_update_state'], 'owned paid order confirms payment independently of cart update' );
	check( 'C2C-7' === $data['reference'] && '2026-09-23T12:00:00Z' === $data['created_at'] && $data['created_at'] === $data['paid_at'], 'stable display-only reference and UTC timestamps' );
	check( 'EUR' === Money::get_currency() && 'USD' === $data['currency'] && 600 === $data['total_minor'] && 600 === $data['subtotal_minor'], 'historical currency and totals ignore current shop settings' );
	check( array( array( 'name'=>$name, 'quantity'=>3, 'unit_price_minor'=>200, 'line_total_minor'=>600 ) ) === $data['items'], 'immutable names including HTML remain JSON text; quantities and historical prices preserved' );
	check( array_keys( $data ) === array( 'reference','order_status','created_at','paid_at','currency','subtotal_minor','total_minor','items','payment_state','cart_update_state' ), 'exact response field allowlist excludes all persistence credentials and diagnostics' );
	check( private_headers( $response ), 'success is private and no-store without CORS grants' );	foreach ( array(
		array( 'Mortal Kombat &#8211; Il mito', 'Mortal Kombat – Il mito' ),
		array( 'A &amp; B &quot;quoted&quot; &#039;single&#039;', 'A & B "quoted" \'single\'' ),
		array( 'Mortal Kombat &amp;#8211; Il mito', 'Mortal Kombat &#8211; Il mito' ),
		array( '&lt;img src=x onerror=alert(1)&gt;', '<img src=x onerror=alert(1)>' ),
		array( 'Odd &bogus; &#xZZ; &#8211 no semicolon', 'Odd &bogus; &#xZZ; &#8211 no semicolon' ),
	) as [$stored, $display] ) {
		sql( $writer, $writer->prepare( "UPDATE {$tables->order_items()} SET item_name=%s WHERE id=11", $stored ) );
		$saved = state( $writer, $created );
		$decoded = lookup( $controller );
		check( 200 === $decoded->get_status() && $display === $decoded->get_data()['items'][0]['name'], 'customer projection decodes exactly one layer: ' . $stored );
		check( $saved === state( $writer, $created ), 'entity presentation leaves historical records and all commerce state unchanged' );
	}
	sql( $writer, $writer->prepare( "UPDATE {$tables->order_items()} SET item_name=%s WHERE id=11", $name ) );

	for ( $i=0; $i<5; ++$i ) { check( public_result( $response ) === public_result( lookup( $controller ) ), 'terminal refresh ' . $i . ' is identical' ); }
	check( $before === state( $writer, $created ) && $cookies === $_COOKIE, 'repeated reads change no commerce row, timestamps, or cookies' );
	check( 0 === (int) $reader->get_var( 'SELECT @@in_transaction' ), 'reader never opens a transaction' );
	check( ! class_exists( 'RodolfoRizzo\\Cpt2Collection\\CartContext', false ) && ! class_exists( 'RodolfoRizzo\\Cpt2Collection\\PaymentSessionService', false ) && ! class_exists( 'Stripe\\StripeClient', false ), 'successful reads need no mutation services or Stripe runtime' );
	check( 0 === (int) $writer->get_var( "SELECT COUNT(*) FROM {$tables->cart_items()}" ), 'authorization survives deleted purchased rows and unrelated cart revision/generation' );

	$unknown = public_result( lookup( $controller, 'cs_test_unknown' ) );
	check( 404 === $unknown[0] && array( 'code'=>'post_checkout_unavailable' ) === $unknown[1], 'unknown selector is generic unavailable' );
	foreach ( array( null, '', array( 'bad' ), 'invalid', str_repeat( 'd',64 ), str_repeat( 'e',64 ) ) as $cookie ) {
		if ( null === $cookie ) { unset( $_COOKIE[CartCookie::COOKIE_NAME] ); } else { $_COOKIE[CartCookie::COOKIE_NAME] = $cookie; }
		check( $unknown === public_result( lookup( $controller ) ), 'absent/malformed/foreign cookie gives identical unavailable response' );
	}
	// A real foreign cart, not only an unknown token.
	$foreign = str_repeat( 'd',64 );
	sql( $writer, $writer->prepare( "INSERT INTO {$tables->carts()} (id,token_hash,created_at,updated_at,last_activity_at,expires_at) VALUES (2,%s,%s,%s,%s,'2030-01-01 00:00:00')", hash('sha256',$foreign),$now,$now,$now ) );
	$_COOKIE[CartCookie::COOKIE_NAME] = $foreign;
	check( $unknown === public_result( lookup( $controller ) ), 'real foreign cart and nonexistent session are publicly equivalent' );
	$_COOKIE[CartCookie::COOKIE_NAME] = $token;
	foreach ( array( "expires_at='2020-01-01 00:00:00'", "expires_at='2026-09-23 12:00:00'" ) as $change ) {
		sql( $writer, "UPDATE {$tables->carts()} SET {$change} WHERE id=1" );
		check( $unknown === public_result( lookup( $controller ) ), 'expired cart including exact deadline fails closed' );
	}
	sql( $writer, "UPDATE {$tables->carts()} SET expires_at='2030-01-01 00:00:00' WHERE id=1" );
	foreach ( array( "UPDATE {$tables->payment_sessions()} SET order_id=999 WHERE id=9", "UPDATE {$tables->orders()} SET cart_id=999 WHERE id=7", "UPDATE {$tables->orders()} SET cart_id=NULL WHERE id=7", "UPDATE {$tables->payment_sessions()} SET provider='other' WHERE id=9" ) as $change ) {
		sql($writer,'START TRANSACTION'); sql($writer,$change); sql($writer,'COMMIT');
		check( $unknown === public_result( lookup( $controller ) ), 'missing association or wrong provider fails closed' );
		sql($writer,"UPDATE {$tables->orders()} SET cart_id=1 WHERE id=7"); sql($writer,"UPDATE {$tables->payment_sessions()} SET order_id=7,provider='stripe_checkout' WHERE id=9");
	}
	foreach ( array( null, '', array('cs_test_owned'), 7, '7', 'cs_', 'cs_test_owned ', 'cs_test_x\' OR 1=1', 'cs_' . str_repeat('a',253) ) as $invalid ) {
		$count = count($reader->reads); $bad=lookup($controller,$invalid);
		check( 400 === $bad->get_status() && private_headers($bad) && $count===count($reader->reads), 'malformed selector rejected without SQL and with privacy headers' );
	}
	check( 400 === $controller->read(new WP_REST_Request())->get_status(), 'missing parameter rejected' );
	foreach ( array('cs_live_historical','cs_legacy_123','cs_' . str_repeat('a',252)) as $id ) {
		sql($writer,$writer->prepare("UPDATE {$tables->payment_sessions()} SET provider_session_id=%s WHERE id=9",$id));
		check( 200===lookup($controller,$id)->get_status(), 'historical/environment-independent bounded selector accepted' );
	}
	sql($writer,"UPDATE {$tables->payment_sessions()} SET provider_session_id='cs_test_owned' WHERE id=9");

	foreach ( array( array('completed','cart_reconciled','completed'), array('pending',null,'pending'), array(null,null,'pending'), array('attention','source_cart_mismatch','requires_review'), array('completed','future_code','requires_review') ) as [$status,$code,$expected] ) {
		$writer->update($tables->orders(),array('cart_reconciliation_status'=>$status,'cart_reconciliation_code'=>$code),array('id'=>7),array('%s','%s'),array('%d'));
		$view=lookup($controller)->get_data();
		check( 'confirmed'===$view['payment_state'] && $expected===$view['cart_update_state'] && !isset($view['cart_reconciliation_code']), 'paid cart outcome '.($status??'NULL').'/'.($code??'NULL').' is mapped independently' );
	}
	foreach ( array('pending','awaiting_payment','expired','failed','cancelled') as $status ) {
		sql($writer,$writer->prepare("UPDATE {$tables->orders()} SET status=%s,paid_at=NULL WHERE id=7",$status));
		$view=lookup($controller)->get_data();
		check( $status===$view['order_status'] && (in_array($status,array('pending','awaiting_payment'),true)?'pending':'not_confirmed')===$view['payment_state'] && null===$view['paid_at'] && 'not_applicable'===$view['cart_update_state'], 'local '.$status.' state never infers payment confirmation or provider failure' );
	}
	sql($writer,"UPDATE {$tables->payment_sessions()} SET reconciliation_required_at='{$now}',reconciliation_code='PRIVATE_CODE',reconciliation_event_id=77 WHERE id=9");
	check( 'requires_review'===lookup($controller)->get_data()['payment_state'], 'payment-processing attention is not payment confirmation' );
	sql($writer,"UPDATE {$tables->orders()} SET status='paid',paid_at='{$now}' WHERE id=7");
	check( 'confirmed'===lookup($controller)->get_data()['payment_state'], 'payment attention never negates authoritative paid state' );
	sql($writer,"UPDATE {$tables->orders()} SET status='awaiting_payment',paid_at=NULL WHERE id=7");
	sql($writer,"UPDATE {$tables->payment_sessions()} SET reconciliation_required_at=NULL,reconciliation_code=NULL,reconciliation_event_id=NULL WHERE id=9");
	sql($writer,"INSERT INTO {$tables->payment_events()} (epoch_id,ownership_state,id,provider,provider_event_id,event_type,livemode,payment_session_id,processing_status,received_at,payload_sha256,normalized_payload) VALUES (1,'bound',77,'stripe_checkout','evt_read','checkout.session.completed',0,9,'attention','{$now}',REPEAT('f',64),'{\"PRIVATE\":true}')");
	check( 'requires_review'===lookup($controller)->get_data()['payment_state'], 'associated event attention also maps cautiously' );
	sql($writer,"DELETE FROM {$tables->payment_events()} WHERE id=77");

	foreach (array('https://evil.example','https://sibling.shop.example','http://shop.example','https://shop.example:444','null','https://user@shop.example','https://shop.example/path') as $origin) {
		$count=count($reader->reads); $request=new WP_REST_Request(array('session_id'=>'cs_test_owned'),array('origin'=>$origin));
		check( $controller->authorize_origin($request) instanceof WP_Error && 403===$controller->authorize_origin($request)->data['status'] && 403===$controller->read($request)->get_status() && $count===count($reader->reads), 'foreign/opaque/malformed origin rejected before lookup: '.$origin );
	}
	$request = new WP_REST_Request( array( 'session_id' => 'cs_test_owned' ) );
	check( null === $request->get_header( 'origin' ), 'absent Origin reproduces the real WordPress null return' );
	check( true === $controller->authorize_origin( $request ) && 200 === $controller->read( $request )->get_status(), 'absent null Origin succeeds in permission and read paths without parse_url TypeError' );
	foreach(array('https://shop.example','https://shop.example:443','',null) as $origin) { $response=lookup($controller,'cs_test_owned',array('origin'=>$origin)); check(200===$response->get_status() && private_headers($response),'same-origin, empty or null Origin JSON read allowed with privacy headers'); }
	foreach ( array( false, true, 0, 1, array(), array( 'https://shop.example' ), new stdClass() ) as $origin ) {
		$count = count( $reader->reads );
		$request = new WP_REST_Request( array( 'session_id' => 'cs_test_owned' ), array( 'origin' => $origin ) );
		$response = $controller->read( $request );
		check( $controller->authorize_origin( $request ) instanceof WP_Error && 403 === $response->get_status() && private_headers( $response ) && $count === count( $reader->reads ), 'non-string Origin denied without coercion or SQL: ' . get_debug_type( $origin ) );
	}
	$GLOBALS['test_site_origin'] = 'http://shop.example';
	foreach ( array( 'http://shop.example', 'http://shop.example:80' ) as $origin ) { check( 200 === lookup( $controller, 'cs_test_owned', array( 'origin' => $origin ) )->get_status(), 'explicit same-origin HTTP remains allowed' ); }
	unset( $GLOBALS['test_site_origin'] );
	$count=count($reader->reads);
	check(400===$controller->read(new WP_REST_Request(array('session_id'=>'cs_test_owned','_jsonp'=>'steal')))->get_status() && $count===count($reader->reads),'JSONP cannot receive cookie-authorized data');
	$error=new WP_REST_Response(array('code'=>'rest_forbidden'),403,array('access-control-allow-origin'=>'https://evil.example','Access-Control-Allow-Credentials'=>'true','cache-control'=>'public'));
	$protected=$controller->protect_response($error,null,new WP_REST_Request());
	check(private_headers($protected),'permission/framework errors also lose CORS grants and public caching');
	$variant = new WP_REST_Request( array(), array(), '/CPT2Collection/v1/Post-Checkout/' );
	$variant_response = $controller->protect_response( new WP_REST_Response( array(), 400, array( 'Access-Control-Allow-Origin' => '*' ) ), null, $variant );
	check( private_headers( $variant_response ), 'mixed-case/trailing-slash error responses retain privacy policy' );
	$GLOBALS['transport']=array('access-control-allow-origin'=>'https://evil.example','access-control-allow-credentials'=>'true','access-control-allow-methods'=>'GET','access-control-expose-headers'=>'Link');
	check(false===$controller->protect_transport(false,$protected,new WP_REST_Request(),null) && !isset($GLOBALS['transport']['access-control-allow-origin']) && !isset($GLOBALS['transport']['access-control-allow-credentials']) && 'private, no-store, max-age=0'===$GLOBALS['transport']['cache-control'],'final serving hook removes core reflected CORS and preserves response serving');
	$other=new WP_REST_Request(array(),array(),'/cpt2collection/v1/cart'); $before_transport=$GLOBALS['transport'];
	$controller->protect_transport(false,$protected,$other,null);
	check($before_transport===$GLOBALS['transport'],'other routes retain their existing transport behavior');
	$reader->fail=true; $failed=lookup($controller); $reader->fail=false;
	check(503===$failed->get_status() && array('code'=>'post_checkout_temporarily_unavailable')===$failed->get_data() && private_headers($failed),'database failure differs from unavailable without database details');
	// Structurally unusable persisted snapshots never masquerade as valid orders.
	sql($writer,"UPDATE {$tables->order_items()} SET order_id=999 WHERE id=11");
	check(503===lookup($controller)->get_status(),'missing immutable items fail closed as temporarily unavailable');
	sql($writer,"UPDATE {$tables->order_items()} SET order_id=7 WHERE id=11");

	// Two real connections: reads during uncommitted sale and reconciliation
	// observe the preceding committed snapshot and neither block nor repair it.
	$before=state($writer,$created);
	sql($writer,'START TRANSACTION');
	sql($writer,"UPDATE {$tables->orders()} SET status='paid',paid_at='{$now}',cart_reconciliation_status='pending',cart_reconciliation_code=NULL WHERE id=7");
	sql($writer,"UPDATE {$tables->payment_sessions()} SET status='completed' WHERE id=9");
	$start=microtime(true); $during=lookup($controller)->get_data();
	check(microtime(true)-$start<2 && 'pending'===$during['payment_state'],'uncommitted sale is invisible and read does not take writer locks');
	sql($writer,'COMMIT');
	$after=lookup($controller)->get_data();
	check('confirmed'===$after['payment_state'] && 'pending'===$after['cart_update_state'],'sale commit is visible independently of cart reconciliation');
	sql($writer,'START TRANSACTION');
	sql($writer,"UPDATE {$tables->orders()} SET cart_reconciliation_status='completed',cart_reconciliation_code='cart_reconciled' WHERE id=7");
	$start=microtime(true); $during=lookup($controller)->get_data();
	check(microtime(true)-$start<2 && 'confirmed'===$during['payment_state'] && 'pending'===$during['cart_update_state'],'uncommitted cart reconciliation does not suppress confirmed payment');
	sql($writer,'COMMIT'); $before=state($writer,$created);
	for($i=0;$i<3;++$i){check('completed'===lookup($controller)->get_data()['cart_update_state'],'terminal reconciliation read remains completed');}
	check($before===state($writer,$created),'terminal replay reads do not repair or advance any persisted state');
	check(0===(int)$reader->get_var('SELECT @@in_transaction'),'no transaction left by any lookup');
	// Exercise the real authoritative webhook orchestrator on the writer connection.
	// These collaborators are deliberately loaded only after the independent-read checks.
	foreach ( array( 'reservation-statuses', 'payment-session-statuses', 'payment-event-statuses', 'cart-record', 'cart-item', 'order-record', 'order-item', 'reservation-record', 'payment-session-record', 'payment-event-record', 'cart-repository', 'order-repository', 'reservation-repository', 'payment-session-repository', 'payment-event-repository', 'payment-event-receipt', 'payment-event-receipt-result', 'payment-event-association-result', 'payment-event-attention-result', 'conversion-reservation-validation-result', 'conversion-reservation-set-validator', 'stock-conversion-result', 'sale-conversion-result', 'transaction-manager', 'meta-keys', 'locked-catalog-reader', 'stock-conversion-processor', 'post-types', 'sale-conversion-service', 'cart-reconciliation-result', 'cart-reconciliation-service', 'webhook-http-disposition', 'webhook-association-result', 'webhook-outcome-categories', 'sale-conversion-result-classifier', 'stripe-event-normalization-result', 'stripe-event-normalizer', 'payment-event-association-service', 'payment-event-outcome-service', 'payment-expiration-service', 'payment-webhook-orchestrator' ) as $class ) {
		require_once dirname( __DIR__ ) . '/includes/class-' . $class . '.php';
	}
	sql( $writer, "CREATE TABLE {$writer->posts} (ID BIGINT UNSIGNED PRIMARY KEY, post_type VARCHAR(32), post_title VARCHAR(255)) ENGINE=InnoDB" );
	$extra_tables[] = $writer->posts;
	sql( $writer, "CREATE TABLE {$writer->postmeta} (meta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_id BIGINT UNSIGNED, meta_key VARCHAR(255), meta_value LONGTEXT, KEY post_id(post_id)) ENGINE=InnoDB" );
	$extra_tables[] = $writer->postmeta;
	sql( $writer, "INSERT INTO {$writer->posts} VALUES (123,'collection_item','Current catalog name')" );
	sql( $writer, "INSERT INTO {$writer->postmeta} (post_id,meta_key,meta_value) VALUES (123,'_cpt2collection_stock_quantity','10')" );
	sql( $writer, "UPDATE {$tables->orders()} SET status='awaiting_payment',paid_at=NULL,cart_reconciliation_status='pending',cart_reconciliation_code=NULL WHERE id=7" );
	sql( $writer, "UPDATE {$tables->payment_sessions()} SET status='active',active_slot=1,expires_at='2030-01-01 00:00:00' WHERE id=9" );
	sql( $writer, "INSERT INTO {$tables->reservations()} (id,order_id,order_item_id,post_id,quantity,status,created_at,updated_at,expires_at) VALUES (1,7,11,123,3,'active','{$now}','{$now}','2030-01-01 00:00:00')" );
	$clock = new UtcClock();
	$tx = new \RodolfoRizzo\Cpt2Collection\TransactionManager( $writer, $tables );
	$orders = new \RodolfoRizzo\Cpt2Collection\OrderRepository( $writer, $tables );
	$sessions = new \RodolfoRizzo\Cpt2Collection\PaymentSessionRepository( $writer, $tables );
	$events = new \RodolfoRizzo\Cpt2Collection\PaymentEventRepository( $writer, $tables );
	$reservations = new \RodolfoRizzo\Cpt2Collection\ReservationRepository( $writer, $tables );
	$validator = new \RodolfoRizzo\Cpt2Collection\ConversionReservationSetValidator();
	$stock = new \RodolfoRizzo\Cpt2Collection\StockConversionProcessor( $reservations, new \RodolfoRizzo\Cpt2Collection\LockedCatalogReader( $writer, $tx ), $tx, $validator );
	$sale = new \RodolfoRizzo\Cpt2Collection\SaleConversionService( $orders, $reservations, $sessions, $events, $tx, $stock, $validator, new \RodolfoRizzo\Cpt2Collection\PostTypes(), $clock );
	$outcomes = new \RodolfoRizzo\Cpt2Collection\PaymentEventOutcomeService( $orders, $sessions, $events, $tx, $clock );
	$association = new \RodolfoRizzo\Cpt2Collection\PaymentEventAssociationService( $orders, $sessions, $events, $tx, $clock, false );
	$expiration = new \RodolfoRizzo\Cpt2Collection\PaymentExpirationService( $orders, $sessions, $events, $tx, $outcomes, $clock );
	$reconciliation = new \RodolfoRizzo\Cpt2Collection\CartReconciliationService( new \RodolfoRizzo\Cpt2Collection\CartRepository( $writer, $tables ), $orders, $tx, $clock );
	$webhook = new \RodolfoRizzo\Cpt2Collection\PaymentWebhookOrchestrator( new \RodolfoRizzo\Cpt2Collection\StripeEventNormalizer(), $events, $association, $sale, $expiration, $outcomes, $clock, $reconciliation, $tx, new \RodolfoRizzo\Cpt2Collection\ProviderWebhookEvidence(static fn()=>new wpdb($writer->prefix)) );
	check($tx->begin_commerce_shared(),'native endpoint webhook fixture admitted');
	$native_snapshot=json_encode(['version'=>2,'reference'=>str_repeat('b',32),'idempotency_key'=>'c2c_ps_'.str_repeat('b',32),'currency'=>'USD','amount_minor'=>600,'deadline'=>'2030-01-01 00:00:00','items'=>[['name'=>'Snapshot','unit_amount'=>200,'quantity'=>3]],'configuration'=>['environment'=>'test','installation_id'=>str_repeat('a',32),'epoch_identity'=>str_repeat('b',32)]],JSON_THROW_ON_ERROR);
	// Repair the deliberately minimal read fixture before using it as native writer evidence.
	check(false!==$writer->update($tables->provider_claims(),['request_snapshot'=>$native_snapshot],['id'=>1],['%s'],['%d'])&&$tx->commit(),'native endpoint webhook evidence persisted');
	$observed = array();
	$writer->observe = static function ( string $sql ) use ( $controller, &$observed, $tables ): void {
		if ( 'COMMIT' === $sql || ( str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, $tables->orders() ) ) ) {
			$view = lookup( $controller )->get_data();
			$observed[] = array( $view['payment_state'], $view['cart_update_state'] );
		}
	};
	$event = array( 'id'=>'evt_owned_read', 'type'=>'checkout.session.completed', 'created'=>1790164800, 'livemode'=>false, 'data'=>array('object'=>array('object'=>'checkout.session', 'id'=>'cs_test_owned', 'mode'=>'payment', 'client_reference_id'=>str_repeat('b',32), 'metadata'=>['c2c_integration'=>'cpt2collection','c2c_installation_id'=>str_repeat('a',32),'c2c_epoch_identity'=>str_repeat('b',32),'c2c_payment_session_reference'=>str_repeat('b',32)], 'payment_intent'=>'pi_owned_read', 'currency'=>'usd', 'amount_total'=>600, 'payment_status'=>'paid', 'status'=>'complete')) );
	// handle() receives a verified envelope; signature ingress is covered by the
	// existing signed webhook suites, which this test does not replace.
	$delivery = $webhook->handle( $event, hash( 'sha256', json_encode( $event ) ) );
	$writer->observe = null;
	check( 200 === $delivery->status(), 'real webhook sale and reconciliation commit successfully with endpoint observers: '.$delivery->code() );
	check( in_array( array('pending','not_applicable'), $observed, true ), 'read during real uncommitted sale sees preceding committed order' );
	check( in_array( array('confirmed','pending'), $observed, true ), 'read between real sale and reconciliation commits confirms payment separately' );
	check( in_array( array('confirmed','completed'), $observed, true ), 'read after real reconciliation commit sees completed cart update' );
	$before = state( $writer, $created );
	$stock_before = $writer->get_var( "SELECT meta_value FROM {$writer->postmeta} WHERE post_id=123" );
	check( '7' === $stock_before, 'real sale decremented stock once' );
	check( 200 === lookup( $controller )->get_status() && $before === state( $writer, $created ), 'customer read after webhook commit leaves all commerce evidence unchanged' );
	check( 200 === $webhook->handle( $event, hash('sha256',json_encode($event)) )->status(), 'processed-event terminal replay remains accepted' );
	check( $before === state( $writer, $created ) && $stock_before === $writer->get_var("SELECT meta_value FROM {$writer->postmeta} WHERE post_id=123"), 'webhook replay and subsequent reads never repeat stock or cart work' );
	check( 'completed' === lookup($controller)->get_data()['cart_update_state'] && 0 === $reader->write_attempts, 'final terminal read still has zero mutation attempts' );
	echo "All {$checks} post-checkout read checks passed.\n";
} finally {
	$writer->observe = null;
	$writer->query('ROLLBACK');
	$writer->query('DROP TABLE IF EXISTS '.$tables->commerce_resets().', '.$tables->order_deletions());
	foreach ( $extra_tables as $table ) { $writer->query("DROP TABLE IF EXISTS {$table}"); }
	foreach(array_reverse($created) as $table){$writer->query("DROP TABLE IF EXISTS {$table}");}
	$reader->close(); $writer->close();
}
}
