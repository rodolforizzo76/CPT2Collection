<?php
/** Real-MariaDB verification for Step D cross-tab checkout convergence. */

declare( strict_types=1 );

if ( ! extension_loaded( 'mysqli' ) || ! function_exists( 'pcntl_fork' ) || ! function_exists( 'stream_socket_pair' ) || false === getenv( 'C2C_TEST_DB_NAME' ) ) {
	echo "SKIP: mysqli, pcntl, stream sockets, and C2C_TEST_DB_NAME are required for cross-tab checkout concurrency.\n";
	exit( 0 );
}

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );

$db_host = getenv( 'C2C_TEST_DB_HOST' ) ?: '127.0.0.1';
$db_port = (int) ( getenv( 'C2C_TEST_DB_PORT' ) ?: 3306 );
$db_user = getenv( 'C2C_TEST_DB_USER' ) ?: 'root';
$db_pass = getenv( 'C2C_TEST_DB_PASSWORD' ) ?: '';
$db_name = (string) getenv( 'C2C_TEST_DB_NAME' );
$prefix  = 'c2c_step_d_' . bin2hex( random_bytes( 4 ) ) . '_';

mysqli_report( MYSQLI_REPORT_OFF );

require_once __DIR__ . '/support/mariadb-wpdb.php';

function __( string $message, string $domain ): string { return $message; }
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['test_options'][ $name ] ?? $default; }
function get_post( int $id ): ?object { global $wpdb; return null === $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID=%d", $id ) ) ? null : (object) array( 'ID' => $id ); }
function get_post_type( int $id ): string|false { global $wpdb; $value = $wpdb->get_var( $wpdb->prepare( "SELECT post_type FROM {$wpdb->posts} WHERE ID=%d", $id ) ); return null === $value ? false : (string) $value; }
function get_post_meta( int $id, string $key, bool $single ): mixed { global $wpdb; $value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s ORDER BY meta_id ASC LIMIT 1", $id, $key ) ); return null === $value ? '' : (string) $value; }
function get_the_title( int $id ): string { global $wpdb; return (string) ( $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM {$wpdb->posts} WHERE ID=%d", $id ) ) ?? '' ); }
function get_post_types( array $args, string $output ): array { return array( 'collection_item' => new WP_Post_Type() ); }
function apply_filters( string $name, mixed $value ): mixed { return $value; }
function current_time( string $type, bool $gmt ): int { return time(); }
class WP_Post_Type { public bool $_builtin = false; public bool $show_ui = true; public bool $public = true; public string $name = 'collection_item'; }

foreach ( array( 'table-names', 'payment-session-statuses', 'payment-event-statuses', 'payment-session-record', 'payment-event-record', 'payment-session-repository', 'payment-event-repository', 'money', 'commerce-settings', 'order-statuses', 'reservation-statuses', 'order-record', 'order-deletion-record', 'order-item', 'reservation-record', 'cart-record', 'cart-item', 'cart-repository', 'order-repository', 'order-deletion-registry', 'reservation-repository', 'reservation-operation-result', 'reservation-set-validation-result', 'reservation-set-validator', 'transaction-manager', 'commerce-phase-three-checkpoint-one-migration', 'meta-keys', 'catalog', 'locked-catalog-reader', 'post-types', 'utc-clock', 'commerce-rule-result', 'commerce-rules', 'reservation-acquirer', 'checkout-preparation-result', 'checkout-service' ) as $class ) {
	require_once dirname( __DIR__ ) . '/includes/class-' . $class . '.php';
}

use RodolfoRizzo\Cpt2Collection\CartRecord;
use RodolfoRizzo\Cpt2Collection\CartRepository;
use RodolfoRizzo\Cpt2Collection\CheckoutService;
use RodolfoRizzo\Cpt2Collection\CommerceRules;
use RodolfoRizzo\Cpt2Collection\LockedCatalogReader;
use RodolfoRizzo\Cpt2Collection\OrderRepository;
use RodolfoRizzo\Cpt2Collection\OrderDeletionRegistry;
use RodolfoRizzo\Cpt2Collection\PaymentEventRepository;
use RodolfoRizzo\Cpt2Collection\PaymentSessionRepository;
use RodolfoRizzo\Cpt2Collection\PostTypes;
use RodolfoRizzo\Cpt2Collection\ReservationAcquirer;
use RodolfoRizzo\Cpt2Collection\ReservationOperationResult;
use RodolfoRizzo\Cpt2Collection\ReservationRepository;
use RodolfoRizzo\Cpt2Collection\TableNames;
use RodolfoRizzo\Cpt2Collection\TransactionManager;
use RodolfoRizzo\Cpt2Collection\UtcClock;

$GLOBALS['test_options'] = array( 'cpt2collection_post_type' => 'collection_item', 'cpt2collection_currency' => 'EUR', 'cpt2collection_minimum_order_quantity' => 1, 'cpt2collection_reservation_duration_minutes' => 15 );

function components( wpdb $db ): array {
	$tables = new TableNames( $db ); $orders = new OrderRepository( $db, $tables ); $transactions = new TransactionManager( $db, $tables ); $clock = new UtcClock(); $post_types = new PostTypes(); $reservations = new ReservationRepository( $db, $tables );
	$acquirer = new ReservationAcquirer( $orders, $reservations, $transactions, new LockedCatalogReader( $db ), $post_types, $clock );
	$service = new CheckoutService( new CartRepository( $db, $tables ), new CommerceRules( $post_types ), $orders, $transactions, $acquirer, $clock, $reservations, new PaymentSessionRepository( $db, $tables ), new PaymentEventRepository( $db, $tables ), new OrderDeletionRegistry( $db, $tables ) );
	return compact( 'service', 'orders', 'transactions', 'acquirer' );
}
function connect_test_db(): wpdb { global $prefix; $db = new wpdb( $prefix ); $GLOBALS['wpdb'] = $db; return $db; }
function check( bool $condition, string $label ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } echo "PASS: {$label}\n"; }
function send_packet( $stream, array $packet ): void { fwrite( $stream, json_encode( $packet, JSON_THROW_ON_ERROR ) . "\n" ); fflush( $stream ); }
function receive_packet( $stream, string $label ): array { $line = fgets( $stream ); if ( false === $line ) { throw new RuntimeException( "IPC closed while waiting for {$label}." ); } return json_decode( trim( $line ), true, 512, JSON_THROW_ON_ERROR ); }
function wait_child( int $pid, string $label ): void { pcntl_waitpid( $pid, $status ); if ( ! pcntl_wifexited( $status ) || 0 !== pcntl_wexitstatus( $status ) ) { throw new RuntimeException( "{$label} child failed (status {$status})." ); } }
function cart_record( int $id, int $revision, int $generation ): CartRecord { return new CartRecord( $id, hash( 'sha256', 'cart-' . $id ), '2026-09-12 12:00:00', '2026-09-12 12:00:00', '2026-09-12 12:00:00', '2030-01-01 00:00:00', $revision, $generation, 1 ); }
function scalar( wpdb $db, string $sql ): mixed { $value = $db->get_var( $sql ); if ( '' !== $db->last_error ) { throw new RuntimeException( $db->last_error ); } return $value; }

function create_schema( wpdb $db ): void {
	global $prefix;
	$definitions = array(
		'posts' => '(ID BIGINT UNSIGNED PRIMARY KEY, post_type VARCHAR(32) NOT NULL, post_title VARCHAR(255) NOT NULL) ENGINE=InnoDB',
		'postmeta' => '(meta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, post_id BIGINT UNSIGNED NOT NULL, meta_key VARCHAR(255) NOT NULL, meta_value LONGTEXT, KEY post_id(post_id), KEY meta_key(meta_key)) ENGINE=InnoDB',
		'c2c_carts' => '(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, token_hash CHAR(64) NOT NULL UNIQUE, content_revision BIGINT UNSIGNED NOT NULL, checkout_generation BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, last_activity_at DATETIME NOT NULL, expires_at DATETIME NOT NULL) ENGINE=InnoDB',
		'c2c_cart_items' => '(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, cart_id BIGINT UNSIGNED NOT NULL, post_id BIGINT UNSIGNED NOT NULL, quantity INT UNSIGNED NOT NULL, content_version BIGINT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE KEY cart_product(cart_id,post_id)) ENGINE=InnoDB',
		'c2c_orders' => '(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, cart_id BIGINT UNSIGNED NULL, cart_revision BIGINT UNSIGNED NULL, checkout_generation BIGINT UNSIGNED NULL, checkout_attempt_hash CHAR(64) NULL, cart_reconciliation_status VARCHAR(32) NULL, cart_reconciliation_code VARCHAR(64) NULL, cart_reconciliation_updated_at DATETIME NULL, status VARCHAR(32) NOT NULL, currency CHAR(3) NOT NULL, subtotal_minor BIGINT UNSIGNED NOT NULL, total_minor BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, paid_at DATETIME NULL, cancelled_at DATETIME NULL, expired_at DATETIME NULL, failed_at DATETIME NULL, UNIQUE KEY cart_revision_generation(cart_id,cart_revision,checkout_generation), UNIQUE KEY cart_checkout_attempt(cart_id,checkout_attempt_hash)) ENGINE=InnoDB',
		'c2c_order_items' => '(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, post_id BIGINT UNSIGNED NOT NULL, source_cart_item_id BIGINT UNSIGNED NULL, source_cart_item_version BIGINT UNSIGNED NULL, item_name VARCHAR(255) NOT NULL, unit_price_minor BIGINT UNSIGNED NOT NULL, quantity INT UNSIGNED NOT NULL, line_subtotal_minor BIGINT UNSIGNED NOT NULL, line_total_minor BIGINT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL, created_at DATETIME NOT NULL, KEY order_id(order_id)) ENGINE=InnoDB',
		'c2c_reservations' => '(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, order_item_id BIGINT UNSIGNED NOT NULL, post_id BIGINT UNSIGNED NOT NULL, quantity INT UNSIGNED NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, released_at DATETIME NULL, converted_at DATETIME NULL, UNIQUE KEY order_item_id(order_item_id), KEY order_id(order_id), KEY active_stock_lookup(post_id,status,expires_at)) ENGINE=InnoDB',
	);
	foreach ( $definitions as $suffix => $definition ) { if ( false === $db->query( "CREATE TABLE `{$prefix}{$suffix}` {$definition}" ) ) { throw new RuntimeException( $db->last_error ); } }
	create_commerce_foundation($db);
	$tables = new TableNames($db);
	$source = file_get_contents(dirname(__DIR__).'/includes/class-database.php');
	preg_match_all('/"CREATE TABLE (.*?) ENGINE=InnoDB \{\$charset_collate\};"/s',$source,$matches);
	foreach($matches[1] as $ddl) {
		if(!str_contains($ddl,'payment_sessions')&&!str_contains($ddl,'payment_events'))continue;
		$ddl=preg_replace_callback('/\{\$tables->([a-z_]+)\(\)\}/',fn($m)=>$tables->{$m[1]}(),$ddl);
		if(false===$db->query('CREATE TABLE '.$ddl.' ENGINE=InnoDB'))throw new RuntimeException($db->last_error);
	}
	migrate_commerce_phase_two_fixture($db);
	if ( ! \RodolfoRizzo\Cpt2Collection\CommercePhaseThreeCheckpointOneMigration::run( $db ) ) { throw new RuntimeException( 'Phase 3 Checkpoint 1 fixture migration failed: ' . $db->last_error ); }

}
function seed_cart( wpdb $db, int $cart_id, int $post_id, int $revision, int $generation, int $quantity, int $stock ): void {
	global $prefix; $token = hash( 'sha256', 'cart-' . $cart_id ); $now = '2026-09-12 12:00:00';
	$db->query( "INSERT INTO `{$prefix}posts` VALUES ({$post_id},'collection_item','Concurrency item {$post_id}')" );
	foreach ( array( '_cpt2collection_price_minor' => '1250', '_cpt2collection_status' => 'available', '_cpt2collection_stock_quantity' => (string) $stock, '_cpt2collection_minimum_order_exempt' => '' ) as $key => $value ) { $db->query( $db->prepare( "INSERT INTO `{$prefix}postmeta`(post_id,meta_key,meta_value) VALUES(%d,%s,%s)", $post_id, $key, $value ) ); }
	$db->query( $db->prepare( "INSERT INTO `{$prefix}c2c_carts`(id,token_hash,content_revision,checkout_generation,created_at,updated_at,last_activity_at,expires_at) VALUES(%d,%s,%d,%d,%s,%s,%s,'2030-01-01 00:00:00')", $cart_id, $token, $revision, $generation, $now, $now, $now ) );
	$db->query( $db->prepare( "INSERT INTO `{$prefix}c2c_cart_items`(cart_id,post_id,quantity,created_at,updated_at) VALUES(%d,%d,%d,%s,%s)", $cart_id, $post_id, $quantity, $now, $now ) );
}
function assert_state( wpdb $db, int $cart_id, int $post_id, int $revision, int $generation, int $quantity, int $stock ): int {
	global $prefix;
	$order_count = (int) scalar( $db, "SELECT COUNT(*) FROM `{$prefix}c2c_orders` WHERE cart_id={$cart_id} AND cart_revision={$revision} AND checkout_generation={$generation}" );
	check( 1 === $order_count, 'exactly one revision/generation order exists' );
	$order = $db->get_row( "SELECT * FROM `{$prefix}c2c_orders` WHERE cart_id={$cart_id} AND cart_revision={$revision} AND checkout_generation={$generation}" );
	check( null !== $order && 'pending' === $order->status && null === $order->failed_at, 'the converged order remains pending and is not failed' );
	$order_id = (int) $order->id;
	check( 1 === (int) scalar( $db, "SELECT COUNT(*) FROM `{$prefix}c2c_order_items` WHERE order_id={$order_id}" ), 'exactly one immutable order-item snapshot exists' );
	check( 1 === (int) scalar( $db, "SELECT COUNT(*) FROM `{$prefix}c2c_reservations` WHERE order_id={$order_id} AND post_id={$post_id} AND quantity={$quantity} AND status='active' AND expires_at > UTC_TIMESTAMP()" ), 'the exact active reservation set belongs to the order' );
	check( 1 === (int) scalar( $db, "SELECT COUNT(*) FROM `{$prefix}c2c_reservations` WHERE order_id={$order_id}" ), 'no partial or duplicate reservation rows exist' );
	check( 0 === (int) scalar( $db, "SELECT COUNT(*) FROM `{$prefix}c2c_orders` o JOIN `{$prefix}c2c_reservations` r ON r.order_id=o.id WHERE o.status='failed' AND r.status='active'" ), 'no failed plus active-reservations split state exists' );
	check( (string) $stock === (string) scalar( $db, "SELECT meta_value FROM `{$prefix}postmeta` WHERE post_id={$post_id} AND meta_key='_cpt2collection_stock_quantity'" ), 'reservation acquisition does not decrement physical stock' );
	$cart = $db->get_row( "SELECT content_revision,checkout_generation FROM `{$prefix}c2c_carts` WHERE id={$cart_id}" );
	check( (int) $cart->content_revision === $revision && (int) $cart->checkout_generation === $generation, 'checkout leaves cart revision and generation unchanged' );
	return $order_id;
}

$admin = connect_test_db();
try {
	create_schema( $admin );

	// Scenario 1: both children block on a parent-owned socket barrier, then enter prepare together.
	seed_cart( $admin, 1, 101, 7, 3, 2, 10 );
	$admin->close(); $admin = null; unset( $GLOBALS['wpdb'] );
	$children = array();
	foreach ( array( str_repeat( 'a', 32 ), str_repeat( 'b', 32 ) ) as $key ) {
		$sockets = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
		if ( false === $sockets ) { throw new RuntimeException( 'Could not create checkout barrier.' ); }
		$pid = pcntl_fork();
		if ( 0 === $pid ) {
			fclose( $sockets[0] );
			try { if ( "go\n" !== fgets( $sockets[1] ) ) { exit( 20 ); } $db = connect_test_db(); $result = components( $db )['service']->prepare( cart_record( 1, 7, 3 ), $key ); send_packet( $sockets[1], array( 'success' => $result->is_success(), 'code' => $result->get_code(), 'order_id' => $result->get_order_id(), 'reservation_code' => $result->get_reservation_code(), 'sql_error' => $db->last_error ) ); $db->close(); exit( 0 ); } catch ( Throwable $error ) { send_packet( $sockets[1], array( 'error' => $error->getMessage() ) ); exit( 21 ); }
		}
		fclose( $sockets[1] ); $children[] = array( $pid, $sockets[0] );
	}
	foreach ( $children as $child ) { fwrite( $child[1], "go\n" ); fflush( $child[1] ); }
	$results = array(); foreach ( $children as $child ) { $results[] = receive_packet( $child[1], 'checkout result' ); } foreach ( $children as $child ) { wait_child( $child[0], 'checkout' ); fclose( $child[1] ); }
	check( $results[0]['success'] && $results[1]['success'] && 'checkout_prepared' === $results[0]['code'] && 'checkout_prepared' === $results[1]['code'], 'two concurrent real checkout callers converge successfully' );
	check( $results[0]['order_id'] === $results[1]['order_id'], 'both checkout callers return the same order ID' );
	check( '' === $results[0]['sql_error'] && '' === $results[1]['sql_error'], 'convergence does not accept a raw SQL or duplicate-key warning' );
	$codes = array( $results[0]['reservation_code'], $results[1]['reservation_code'] ); sort( $codes );
	check( array( 'reservation_acquired', 'reservation_already_active' ) === $codes, 'one caller acquires and the other idempotently accepts the exact set' );
	$admin = connect_test_db(); $scenario_one_order = assert_state( $admin, 1, 101, 7, 3, 2, 10 );
	check( $scenario_one_order === $results[0]['order_id'], 'persisted order matches both public checkout results' );
	$source = $admin->get_row( "SELECT i.source_cart_item_id,i.source_cart_item_version,i.quantity,c.id,c.content_version FROM `{$prefix}c2c_order_items` i JOIN `{$prefix}c2c_cart_items` c ON c.cart_id=1 WHERE i.order_id={$scenario_one_order}" );
	check( (int) $source->id === (int) $source->source_cart_item_id && 0 === (int) $source->source_cart_item_version && 0 === (int) $source->content_version && 2 === (int) $source->quantity, 'concurrent checkout captures the original row identity and version zero without mutation' );


	// Phase 2A: preparation waits for a genuine cart mutation, then captures its committed state.
	seed_cart( $admin, 3, 303, 0, 0, 1, 20 );
	$source_id = (int) scalar( $admin, "SELECT id FROM `{$prefix}c2c_cart_items` WHERE cart_id=3" );
	$admin->close(); $admin = null; unset( $GLOBALS['wpdb'] );
	$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
	foreach ( $pair as $socket ) { stream_set_timeout( $socket, 10 ); }
	$pid = pcntl_fork();
	if ( 0 === $pid ) {
		fclose( $pair[0] );
		try {
			if ( 'go' !== trim( (string) fgets( $pair[1] ) ) ) { exit( 40 ); }
			$db = connect_test_db(); $db->query( 'SET innodb_lock_wait_timeout=5' );
			send_packet( $pair[1], array( 'connection' => $db->dbh->thread_id ) );
			$result = components( $db )['service']->prepare( cart_record( 3, 0, 0 ), str_repeat( 'v', 32 ) );
			send_packet( $pair[1], array( 'success' => $result->is_success(), 'order_id' => $result->get_order_id(), 'code' => $result->get_code() ) );
			$db->close(); exit( 0 );
		} catch ( Throwable $error ) { send_packet( $pair[1], array( 'error' => $error->getMessage() ) ); exit( 41 ); }
	}
	if ( $pid < 0 ) { throw new RuntimeException( 'Fork failed' ); }
	fclose( $pair[1] ); $admin = connect_test_db(); $carts = new CartRepository( $admin, new TableNames( $admin ) );
	$carts->transactions()->begin_commerce_shared(); check( $carts->find_for_update( 3 ) instanceof CartRecord, 'mutation holds the cart row before checkout' );
	fwrite( $pair[0], "go\n" ); fflush( $pair[0] ); $packet = receive_packet( $pair[0], 'checkout connection' ); $connection = (int) $packet['connection'];
	$observer = new wpdb( $prefix ); $deadline = microtime( true ) + 5; $waiting = false;
	do {
		$waiting = (int) scalar( $observer, "SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX t ON t.trx_id=w.requesting_trx_id WHERE t.trx_mysql_thread_id={$connection}" ) > 0;
		if ( ! $waiting ) { usleep( 200000 ); }
	} while ( ! $waiting && microtime( true ) < $deadline );
	$observer->close();
	check( $waiting, 'checkout demonstrably waits for the cart mutation lock' );
	$changed = $carts->add_item_quantity_locked( 3, 303, 2 );
	check( 3 === $changed->quantity() && 1 === $changed->content_version() && $carts->advance_content_revision( 3, 0, gmdate( 'Y-m-d H:i:s' ) ), 'genuine mutation changes quantity/version/revision atomically' );
	check( false !== $carts->transactions()->commit(), 'mutation commits before checkout continues' );
	$prepared = receive_packet( $pair[0], 'source capture' ); wait_child( $pid, 'source capture' ); fclose( $pair[0] );
	check( true === ( $prepared['success'] ?? false ), 'checkout succeeds after mutation commit' );
	$captured_order = (int) $prepared['order_id'];
	$captured = $admin->get_row( "SELECT * FROM `{$prefix}c2c_order_items` WHERE order_id={$captured_order}" );
	check( $source_id === (int) $captured->source_cart_item_id && 1 === (int) $captured->source_cart_item_version && 3 === (int) $captured->quantity, 'source ID, incremented version and quantity describe the same locked state' );
	check( 1 === $carts->find_item( 3, 303 )->content_version() && 1 === (int) scalar( $admin, "SELECT content_revision FROM `{$prefix}c2c_carts` WHERE id=3" ), 'checkout does not increment content version or revision' );
	$replay = components( $admin )['service']->prepare( cart_record( 3, 0, 0 ), str_repeat( 'v', 32 ) );
	check( $replay->is_success() && $captured_order === $replay->get_order_id() && $captured == $admin->get_row( "SELECT * FROM `{$prefix}c2c_order_items` WHERE order_id={$captured_order}" ), 'same-key replay retains exact immutable source snapshot' );
	$carts->transactions()->begin_commerce_shared(); $carts->find_for_update( 3 );
	check( 1 === $carts->update_item_quantity_locked( 3, 303, 4 ) && $carts->advance_content_revision( 3, 1, gmdate( 'Y-m-d H:i:s' ) ), 'second genuine mutation advances the generation' );
	$carts->transactions()->commit();
	$new = components( $admin )['service']->prepare( cart_record( 3, 0, 0 ), str_repeat( 'w', 32 ) );
	$new_id = (int) $new->get_order_id(); $new_item = $admin->get_row( "SELECT * FROM `{$prefix}c2c_order_items` WHERE order_id={$new_id}" );
	check( $new->is_success() && $captured_order !== $new_id && $source_id === (int) $new_item->source_cart_item_id && 2 === (int) $new_item->source_cart_item_version && 4 === (int) $new_item->quantity, 'new checkout after mutation captures new version and quantity' );
	check( $captured == $admin->get_row( "SELECT * FROM `{$prefix}c2c_order_items` WHERE order_id={$captured_order}" ), 'new checkout cannot rewrite the original source snapshot' );

	// Scenario 2 setup has a real pending order/snapshot but deliberately no reservation.
	seed_cart( $admin, 2, 202, 11, 5, 3, 12 ); $now = '2026-09-12 12:00:00';
	$admin->query( "INSERT INTO `{$prefix}c2c_orders`(cart_id,cart_revision,checkout_generation,checkout_attempt_hash,status,currency,subtotal_minor,total_minor,created_at,updated_at) VALUES(2,11,5,SHA2('scenario-two',256),'pending','EUR',3750,3750,'{$now}','{$now}')" ); $order_id = $admin->insert_id;
	$admin->query( "INSERT INTO `{$prefix}c2c_order_items`(order_id,post_id,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at) VALUES({$order_id},202,'Concurrency item 202',1250,3,3750,3750,'EUR','{$now}')" );
	$trigger = $prefix . 'fail_reservation_insert';
	if ( false === $admin->query( "CREATE TRIGGER `{$trigger}` BEFORE INSERT ON `{$prefix}c2c_reservations` FOR EACH ROW BEGIN IF COALESCE(@c2c_step_d_fail,0)=1 THEN SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO=1644, MESSAGE_TEXT='Step D controlled non-retryable failure'; END IF; END" ) ) { throw new RuntimeException( 'Could not create failure trigger: ' . $admin->last_error ); }
	$admin->close(); $admin = null; unset( $GLOBALS['wpdb'] );

	$a_pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP ); $b_pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
	$a_pid = pcntl_fork();
	if ( 0 === $a_pid ) {
		fclose( $a_pair[0] ); fclose( $b_pair[0] ); fclose( $b_pair[1] );
		try { $db = connect_test_db(); $parts = components( $db ); $db->query( 'SET @c2c_step_d_fail=1' ); $order = $parts['orders']->find( $order_id ); $failure = $parts['acquirer']->acquire( $order_id ); $rolled_back = 0 === (int) scalar( $db, 'SELECT @@in_transaction' ); send_packet( $a_pair[1], array( 'phase' => 'rolled_back', 'code' => $failure->get_code(), 'rolled_back' => $rolled_back, 'errno' => $parts['acquirer']->get_last_diagnostics()['error_number'] ) ); if ( "finalize\n" !== fgets( $a_pair[1] ) ) { exit( 30 ); } $method = new ReflectionMethod( CheckoutService::class, 'finalize_reservation_failure' ); $final = $method->invoke( $parts['service'], $order, $failure ); send_packet( $a_pair[1], array( 'success' => $final->is_success(), 'code' => $final->get_code(), 'order_id' => $final->get_order_id(), 'reservation_code' => $final->get_reservation_code(), 'transaction_open' => $parts['transactions']->is_active(), 'server_transaction' => (int) scalar( $db, 'SELECT @@in_transaction' ) ) ); $db->close(); exit( 0 ); } catch ( Throwable $error ) { send_packet( $a_pair[1], array( 'error' => $error->getMessage() ) ); exit( 31 ); }
	}
	fclose( $a_pair[1] );
	$a_failed = receive_packet( $a_pair[0], 'A rollback' );
	check( 'rolled_back' === ( $a_failed['phase'] ?? '' ) && 'database_error' === ( $a_failed['code'] ?? '' ) && true === ( $a_failed['rolled_back'] ?? false ) && 1644 === ( $a_failed['errno'] ?? 0 ), 'A reports the controlled non-retryable failure after rollback' );
	$b_pid = pcntl_fork();
	if ( 0 === $b_pid ) {
		fclose( $b_pair[0] ); fclose( $a_pair[0] );
		try { $db = connect_test_db(); $parts = components( $db ); $result = $parts['acquirer']->acquire( $order_id ); send_packet( $b_pair[1], array( 'success' => $result->is_success(), 'code' => $result->get_code(), 'server_transaction' => (int) scalar( $db, 'SELECT @@in_transaction' ) ) ); $db->close(); exit( 0 ); } catch ( Throwable $error ) { send_packet( $b_pair[1], array( 'error' => $error->getMessage() ) ); exit( 32 ); }
	}
	fclose( $b_pair[1] ); $b_done = receive_packet( $b_pair[0], 'B commit' ); wait_child( $b_pid, 'reservation winner' ); fclose( $b_pair[0] );
	check( true === ( $b_done['success'] ?? false ) && 'reservation_acquired' === ( $b_done['code'] ?? '' ) && 0 === ( $b_done['server_transaction'] ?? -1 ), 'B commits the exact reservation set before A is released' );
	fwrite( $a_pair[0], "finalize\n" ); fflush( $a_pair[0] ); $a_final = receive_packet( $a_pair[0], 'A finalization' ); wait_child( $a_pid, 'failure finalizer' ); fclose( $a_pair[0] );
	check( true === ( $a_final['success'] ?? false ) && 'checkout_prepared' === ( $a_final['code'] ?? '' ) && 'reservation_already_active' === ( $a_final['reservation_code'] ?? '' ), 'A protected finalizer converges on B\'s committed reservation set' );
	check( false === ( $a_final['transaction_open'] ?? true ) && 0 === ( $a_final['server_transaction'] ?? -1 ), 'both application and server report no transaction left open' );
	$admin = connect_test_db(); $scenario_two_order = assert_state( $admin, 2, 202, 11, 5, 3, 12 );
	check( $scenario_two_order === $order_id && $a_final['order_id'] === $order_id, 'the stale-failure race creates no second order' );
	echo "PASS: deterministic Step D stale-failure race remains internally coherent.\n";
} finally {
	try { if ( ! isset( $admin ) || ! $admin instanceof wpdb ) { $admin = connect_test_db(); } } catch ( Throwable $ignored ) { $admin = null; }
	if ( $admin instanceof wpdb ) {
		$admin->query( "DROP TRIGGER IF EXISTS `{$prefix}fail_reservation_insert`" );
		foreach ( array( 'c2c_commerce_control', 'c2c_commerce_epochs', 'c2c_payment_sessions', 'c2c_payment_events', 'c2c_reservations', 'c2c_order_items', 'c2c_orders', 'c2c_cart_items', 'c2c_carts', 'postmeta', 'posts' ) as $suffix ) { $admin->query( "DROP TABLE IF EXISTS `{$prefix}{$suffix}`" ); }
		$admin->close();
	}
}
