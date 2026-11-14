<?php
/** Real-MariaDB verification for payment-session claim and replay convergence. */

declare( strict_types=1 );

if ( ! extension_loaded( 'mysqli' ) || ! function_exists( 'pcntl_fork' ) || ! function_exists( 'stream_socket_pair' ) || ! function_exists( 'flock' ) || false === getenv( 'C2C_TEST_DB_NAME' ) ) {
	echo "SKIP: mysqli, pcntl, stream sockets, flock, and C2C_TEST_DB_NAME are required for payment-session concurrency.\n";
	exit( 0 );
}

define( 'ABSPATH', __DIR__ );

$db_host = getenv( 'C2C_TEST_DB_HOST' ) ?: '127.0.0.1';
$db_port = (int) ( getenv( 'C2C_TEST_DB_PORT' ) ?: 3306 );
$db_user = getenv( 'C2C_TEST_DB_USER' ) ?: 'root';
$db_pass = getenv( 'C2C_TEST_DB_PASSWORD' ) ?: '';
$db_name = (string) getenv( 'C2C_TEST_DB_NAME' );
$prefix  = 'c2c_payment_race_' . bin2hex( random_bytes( 4 ) ) . '_';
$workdir = sys_get_temp_dir() . '/c2c-payment-race-' . bin2hex( random_bytes( 6 ) );

mysqli_report( MYSQLI_REPORT_OFF );
$probe = @new mysqli( $db_host, $db_user, $db_pass, $db_name, $db_port );
if ( $probe->connect_errno ) {
	echo 'SKIP: MariaDB test connection is unusable: ' . $probe->connect_error . "\n";
	exit( 0 );
}
$probe->close();

/** Minimal wpdb adapter; production repositories execute their real SQL through it. */
require_once __DIR__ . '/support/commerce-fixtures.php';
class wpdb implements \RodolfoRizzo\Cpt2Collection\CommerceConnection {
	use TestCommerceConnection;
	public string $prefix;
	public string $posts;
	public string $postmeta;
	public string $last_error = '';
	public int $last_errno = 0;
	public int $insert_id = 0;
	public mysqli $dbh;

	public function __construct( string $prefix ) {
		global $db_host, $db_port, $db_user, $db_pass, $db_name;
		$this->prefix = $prefix; $this->posts = $prefix . 'posts'; $this->postmeta = $prefix . 'postmeta';
		$this->dbh = @new mysqli( $db_host, $db_user, $db_pass, $db_name, $db_port );
		if ( $this->dbh->connect_errno ) { throw new RuntimeException( 'MariaDB connection failed: ' . $this->dbh->connect_error ); }
		$this->dbh->set_charset( 'utf8mb4' );
	}
	public function close(): void { $this->dbh->close(); }
	private function reset_error(): void { $this->last_error = ''; $this->last_errno = 0; }
	private function failed(): false { $this->last_error = $this->dbh->error; $this->last_errno = $this->dbh->errno; return false; }
	public function prepare( string $query, mixed ...$args ): string {
		$i = 0;
		return (string) preg_replace_callback( '/%(?:%|d|s)/', function ( array $match ) use ( $args, &$i ): string {
			if ( '%%' === $match[0] ) { return '%'; }
			$value = $args[ $i++ ];
			return '%d' === $match[0] ? (string) (int) $value : "'" . $this->dbh->real_escape_string( (string) $value ) . "'";
		}, $query );
	}
	public function query( string $query ): int|false {
		$this->reset_error(); $result = $this->dbh->query( $query );
		if ( false === $result ) { return $this->failed(); }
		$this->insert_id = (int) $this->dbh->insert_id;
		return true === $result ? $this->dbh->affected_rows : $result->num_rows;
	}
	public function get_results( string $query ): ?array {
		$this->reset_error(); $result = $this->dbh->query( $query );
		if ( false === $result ) { $this->failed(); return null; }
		$rows = array(); while ( $row = $result->fetch_object() ) { $rows[] = $row; } $result->free(); return $rows;
	}
	public function get_row( string $query ): ?object { $rows = $this->get_results( $query ); return null === $rows ? null : ( $rows[0] ?? null ); }
	public function get_var( string $query ): mixed { $row = $this->get_row( $query ); if ( null === $row ) { return null; } $values = get_object_vars( $row ); return reset( $values ); }
	public function insert( string $table, array $data, array $formats ): int|false {
		$columns = array(); $values = array(); $i = 0;
		foreach ( $data as $column => $value ) { $format = $formats[ $i++ ] ?? '%s'; $columns[] = '`' . $column . '`'; $values[] = null === $value || null === $format ? 'NULL' : $this->prepare( $format, $value ); }
		return $this->query( 'INSERT INTO `' . str_replace( '`', '``', $table ) . '` (' . implode( ',', $columns ) . ') VALUES (' . implode( ',', $values ) . ')' );
	}
	public function update( string $table, array $data, array $where, array $formats, array $where_formats ): int|false {
		$set = array(); $conditions = array(); $i = 0;
		foreach ( $data as $column => $value ) { $format = $formats[ $i++ ] ?? '%s'; $set[] = '`' . $column . '`=' . ( null === $value || null === $format ? 'NULL' : $this->prepare( $format, $value ) ); }
		$i = 0; foreach ( $where as $column => $value ) { $format = $where_formats[ $i++ ] ?? '%s'; $conditions[] = null === $value || null === $format ? '`' . $column . '` IS NULL' : '`' . $column . '`=' . $this->prepare( $format, $value ); }
		return $this->query( 'UPDATE `' . str_replace( '`', '``', $table ) . '` SET ' . implode( ',', $set ) . ' WHERE ' . implode( ' AND ', $conditions ) );
	}
}

function __( string $message, string $domain ): string { return $message; }
function get_option( string $name, mixed $default = false ): mixed { return 'cpt2collection_currency' === $name ? 'EUR' : $default; }

foreach ( array( 'table-names', 'money', 'order-statuses', 'reservation-statuses', 'payment-session-statuses', 'order-record', 'order-item', 'reservation-record', 'payment-session-record', 'order-repository', 'reservation-repository', 'payment-session-repository', 'transaction-manager', 'utc-clock', 'reservation-set-validation-result', 'reservation-set-validator', 'payment-provider-line-item', 'payment-provider-session-request', 'payment-provider-session-result', 'payment-provider-exception', 'payment-provider-interface', 'payment-session-result', 'payment-session-service' ) as $class ) {
	require_once dirname( __DIR__ ) . '/includes/class-' . $class . '.php';
}

use RodolfoRizzo\Cpt2Collection\OrderRepository;
use RodolfoRizzo\Cpt2Collection\PaymentProviderException;
use RodolfoRizzo\Cpt2Collection\PaymentProviderInterface;
use RodolfoRizzo\Cpt2Collection\PaymentProviderSessionRequest;
use RodolfoRizzo\Cpt2Collection\PaymentProviderSessionResult;
use RodolfoRizzo\Cpt2Collection\PaymentSessionRepository;
use RodolfoRizzo\Cpt2Collection\PaymentSessionResult;
use RodolfoRizzo\Cpt2Collection\PaymentSessionService;
use RodolfoRizzo\Cpt2Collection\ReservationRepository;
use RodolfoRizzo\Cpt2Collection\ReservationSetValidator;
use RodolfoRizzo\Cpt2Collection\TableNames;
use RodolfoRizzo\Cpt2Collection\TransactionManager;
use RodolfoRizzo\Cpt2Collection\UtcClock;

/** Cross-process fake provider backed by a locked JSON-lines invocation journal. */
final class IpcPaymentProvider implements PaymentProviderInterface {
	private wpdb $db; private TransactionManager $transactions; private string $journal; private string $mode; private $control;
	public function __construct( wpdb $db, TransactionManager $transactions, string $journal, string $mode = 'success', $control = null ) { $this->db = $db; $this->transactions = $transactions; $this->journal = $journal; $this->mode = $mode; $this->control = $control; }
	public function name(): string { return 'fake_checkout'; }
	public function create_session( PaymentProviderSessionRequest $request ): PaymentProviderSessionResult {
		$server_active = (int) $this->db->get_var( 'SELECT @@in_transaction' );
		$logical_id = 'fake_ps_' . substr( hash( 'sha256', $request->idempotency_key() ), 0, 24 );
		$entry = array( 'pid' => getmypid(), 'reference' => $request->reference(), 'idempotency_key' => $request->idempotency_key(), 'currency' => $request->currency(), 'amount_minor' => $request->amount_minor(), 'logical_id' => $logical_id, 'application_transaction' => $this->transactions->is_active(), 'server_transaction' => 0 !== $server_active );
		$handle = fopen( $this->journal, 'ab' ); if ( false === $handle || ! flock( $handle, LOCK_EX ) ) { throw new RuntimeException( 'Could not lock provider journal.' ); }
		fwrite( $handle, json_encode( $entry, JSON_THROW_ON_ERROR ) . "\n" ); fflush( $handle ); flock( $handle, LOCK_UN ); fclose( $handle );
		if ( 'block' === $this->mode ) { send_packet( $this->control, array( 'event' => 'provider_entered', 'entry' => $entry ) ); if ( "release\n" !== fgets( $this->control ) ) { throw new RuntimeException( 'Provider release barrier closed.' ); } }
		if ( 'ambiguous' === $this->mode ) { throw new PaymentProviderException( PaymentProviderException::OUTCOME_UNKNOWN ); }
		return new PaymentProviderSessionResult( $logical_id, 'https://payments.example.test/session/' . $logical_id, gmdate( 'Y-m-d H:i:s', time() + 600 ) );
	}
}

function connect_test_db(): wpdb { global $prefix; $db = new wpdb( $prefix ); $GLOBALS['wpdb'] = $db; return $db; }
function check( bool $condition, string $label ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } echo "PASS: {$label}\n"; }
function scalar( wpdb $db, string $sql ): mixed { $value = $db->get_var( $sql ); if ( '' !== $db->last_error ) { throw new RuntimeException( $db->last_error ); } return $value; }
function send_packet( $stream, array $packet ): void { fwrite( $stream, json_encode( $packet, JSON_THROW_ON_ERROR ) . "\n" ); fflush( $stream ); }
function receive_packet( $stream, string $label ): array { $line = fgets( $stream ); if ( false === $line ) { throw new RuntimeException( "IPC closed while waiting for {$label}." ); } return json_decode( trim( $line ), true, 512, JSON_THROW_ON_ERROR ); }
function wait_child( int $pid, string $label ): void { pcntl_waitpid( $pid, $status ); if ( ! pcntl_wifexited( $status ) || 0 !== pcntl_wexitstatus( $status ) ) { throw new RuntimeException( "{$label} child failed (status {$status})." ); } }
function result_packet( PaymentSessionResult $result, wpdb $db ): array { return array( 'success' => $result->is_success(), 'code' => $result->get_code(), 'order_id' => $result->get_order_id(), 'session_id' => $result->get_payment_session_id(), 'reference' => $result->get_reference(), 'provider' => $result->get_provider(), 'provider_session_id' => $result->get_provider_session_id(), 'redirect_url' => $result->get_redirect_url(), 'expires_at' => $result->get_expires_at(), 'sql_error' => $db->last_error ); }
function service( wpdb $db, string $journal, string $mode = 'success', $control = null ): array { $tables = new TableNames( $db ); $transactions = new TransactionManager( $db, $tables ); $provider = new IpcPaymentProvider( $db, $transactions, $journal, $mode, $control ); return array( new PaymentSessionService( new OrderRepository( $db, $tables ), new ReservationRepository( $db, $tables ), new PaymentSessionRepository( $db, $tables ), $transactions, new ReservationSetValidator(), $provider, new UtcClock() ), $transactions ); }
function journal( string $path ): array { if ( ! is_file( $path ) ) { return array(); } return array_map( static fn( string $line ): array => json_decode( $line, true, 512, JSON_THROW_ON_ERROR ), file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array() ); }

function create_schema( wpdb $db ): void {
	global $prefix;
	$definitions = array(
		'c2c_orders' => '(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, cart_id BIGINT UNSIGNED NULL, cart_revision BIGINT UNSIGNED NULL, checkout_generation BIGINT UNSIGNED NULL, checkout_attempt_hash CHAR(64) NULL, status VARCHAR(32) NOT NULL, currency CHAR(3) NOT NULL, subtotal_minor BIGINT UNSIGNED NOT NULL, total_minor BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, paid_at DATETIME NULL, cancelled_at DATETIME NULL, expired_at DATETIME NULL, failed_at DATETIME NULL) ENGINE=InnoDB',
		'c2c_order_items' => '(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, post_id BIGINT UNSIGNED NOT NULL, item_name VARCHAR(255) NOT NULL, unit_price_minor BIGINT UNSIGNED NOT NULL, quantity INT UNSIGNED NOT NULL, line_subtotal_minor BIGINT UNSIGNED NOT NULL, line_total_minor BIGINT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL, created_at DATETIME NOT NULL, KEY order_id(order_id)) ENGINE=InnoDB',
		'c2c_reservations' => '(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL, order_item_id BIGINT UNSIGNED NOT NULL, post_id BIGINT UNSIGNED NOT NULL, quantity INT UNSIGNED NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, released_at DATETIME NULL, converted_at DATETIME NULL, UNIQUE KEY order_item_id(order_item_id), KEY order_id(order_id)) ENGINE=InnoDB',
		'c2c_payment_sessions' => '(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reference CHAR(32) NOT NULL UNIQUE, order_id BIGINT UNSIGNED NOT NULL, provider VARCHAR(32) NOT NULL, provider_session_id VARCHAR(255) NULL, provider_payment_id VARCHAR(255) NULL, provider_event_id VARCHAR(255) NULL, creation_key_hash CHAR(64) NOT NULL, status VARCHAR(32) NOT NULL, active_slot TINYINT UNSIGNED NULL, currency CHAR(3) NOT NULL, amount_minor BIGINT UNSIGNED NOT NULL, redirect_url TEXT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, expires_at DATETIME NULL, expired_at DATETIME NULL, completed_at DATETIME NULL, cancelled_at DATETIME NULL, failed_at DATETIME NULL, payment_confirmed_at DATETIME NULL, confirmed_currency CHAR(3) NULL, confirmed_amount_minor BIGINT UNSIGNED NULL, reconciliation_required_at DATETIME NULL, reconciliation_code VARCHAR(64) NULL, reconciliation_event_id BIGINT UNSIGNED NULL, UNIQUE KEY order_creation_key(order_id,creation_key_hash), UNIQUE KEY provider_session(provider,provider_session_id), UNIQUE KEY provider_payment(provider,provider_payment_id), UNIQUE KEY order_active_slot(order_id,active_slot), KEY order_id(order_id)) ENGINE=InnoDB',
	);
	foreach ( $definitions as $suffix => $definition ) { if ( false === $db->query( "CREATE TABLE `{$prefix}{$suffix}` {$definition}" ) ) { throw new RuntimeException( $db->last_error ); } }
	create_commerce_foundation($db);
}
function seed_order( wpdb $db, int $order_id ): array {
	global $prefix; $now = gmdate( 'Y-m-d H:i:s' ); $expires = gmdate( 'Y-m-d H:i:s', time() + 1200 ); $post_id = 1000 + $order_id;
	if ( false === $db->query( "INSERT INTO `{$prefix}c2c_orders`(id,status,currency,subtotal_minor,total_minor,created_at,updated_at) VALUES({$order_id},'pending','EUR',2500,2500,'{$now}','{$now}')" ) ) { throw new RuntimeException( $db->last_error ); }
	if ( false === $db->query( "INSERT INTO `{$prefix}c2c_order_items`(order_id,post_id,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at) VALUES({$order_id},{$post_id},'Concurrency item',1250,2,2500,2500,'EUR','{$now}')" ) ) { throw new RuntimeException( $db->last_error ); }
	$item_id = $db->insert_id;
	if ( false === $db->query( "INSERT INTO `{$prefix}c2c_reservations`(order_id,order_item_id,post_id,quantity,status,created_at,updated_at,expires_at) VALUES({$order_id},{$item_id},{$post_id},2,'active','{$now}','{$now}','{$expires}')" ) ) { throw new RuntimeException( $db->last_error ); }
	return array( 'reservation_id' => $db->insert_id, 'expires_at' => $expires );
}
function assert_final_state( wpdb $db, int $order_id, string $creation_key, array $reservation, string $label ): object {
	global $prefix; $session = $db->get_row( "SELECT * FROM `{$prefix}c2c_payment_sessions` WHERE order_id={$order_id}" );
	check( 1 === (int) scalar( $db, "SELECT COUNT(*) FROM `{$prefix}c2c_payment_sessions` WHERE order_id={$order_id}" ), "{$label}: exactly one local payment session exists" );
	check( null !== $session && 'active' === $session->status && 1 === (int) $session->active_slot, "{$label}: the sole active-slot owner is coherent and active" );
	check( hash( 'sha256', $creation_key ) === $session->creation_key_hash, "{$label}: the winning creation identity remains unchanged" );
	check( 'awaiting_payment' === scalar( $db, "SELECT status FROM `{$prefix}c2c_orders` WHERE id={$order_id}" ), "{$label}: order is awaiting payment" );
	check( 1 === (int) scalar( $db, "SELECT COUNT(*) FROM `{$prefix}c2c_reservations` WHERE id={$reservation['reservation_id']} AND order_id={$order_id} AND status='active' AND quantity=2 AND expires_at='" . $db->dbh->real_escape_string( $reservation['expires_at'] ) . "' AND released_at IS NULL AND converted_at IS NULL" ), "{$label}: reservation remains active and unchanged" );
	check( 0 === (int) scalar( $db, 'SELECT @@in_transaction' ), "{$label}: parent database connection has no open transaction" );
	return $session;
}
function drop_fixture( ?wpdb $db ): void { global $prefix; if ( null === $db ) { try { $db = connect_test_db(); } catch ( Throwable $error ) { return; } } foreach ( array( 'c2c_commerce_control', 'c2c_commerce_epochs', 'c2c_payment_sessions', 'c2c_reservations', 'c2c_order_items', 'c2c_orders' ) as $suffix ) { $db->query( "DROP TABLE IF EXISTS `{$prefix}{$suffix}`" ); } }

$admin = null;
try {
	if ( ! mkdir( $workdir, 0700 ) && ! is_dir( $workdir ) ) { throw new RuntimeException( 'Could not create IPC work directory.' ); }
	$admin = connect_test_db(); create_schema( $admin );

	// Scenario 1: both children wait on parent-owned barriers before calling create().
	$reservation_one = seed_order( $admin, 1 ); $journal_one = $workdir . '/scenario-1.jsonl'; $admin->close(); $admin = null; unset( $GLOBALS['wpdb'] );
	$children = array(); $keys = array( str_repeat( 'a', 32 ), str_repeat( 'b', 32 ) );
	foreach ( $keys as $key ) {
		$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP ); if ( false === $pair ) { throw new RuntimeException( 'Could not create simultaneous-start barrier.' ); }
		$pid = pcntl_fork();
		if ( 0 === $pid ) { fclose( $pair[0] ); try { if ( "go\n" !== fgets( $pair[1] ) ) { exit( 20 ); } $db = connect_test_db(); [ $svc, $tx ] = service( $db, $journal_one ); $result = $svc->create( 1, $key ); $packet = result_packet( $result, $db ); $packet['transaction_active'] = $tx->is_active(); send_packet( $pair[1], $packet ); $db->close(); exit( 0 ); } catch ( Throwable $error ) { send_packet( $pair[1], array( 'error' => $error->getMessage() ) ); exit( 21 ); } }
		fclose( $pair[1] ); $children[] = array( $pid, $pair[0] );
	}
	foreach ( $children as $child ) { fwrite( $child[1], "go\n" ); fflush( $child[1] ); }
	$results = array(); foreach ( $children as $child ) { $results[] = receive_packet( $child[1], 'scenario 1 result' ); } foreach ( $children as $child ) { wait_child( $child[0], 'scenario 1' ); fclose( $child[1] ); }
	check( ! isset( $results[0]['error'], $results[1]['error'] ) && '' === $results[0]['sql_error'] && '' === $results[1]['sql_error'], 'scenario 1: neither caller exposes a SQL/duplicate-key error or crashes' );
	$successes = array_values( array_filter( $results, static fn( array $r ): bool => $r['success'] ) ); $losers = array_values( array_filter( $results, static fn( array $r ): bool => ! $r['success'] ) );
	check( 1 === count( $successes ) || 2 === count( $successes ), 'scenario 1: one creator and an allowed in-progress or active replay outcome return' );
	check( 1 === count( array_filter( $results, static fn( array $r ): bool => 'payment_session_created' === $r['code'] ) ), 'scenario 1: exactly one caller wins creation' );
	check( array() === $losers || ( 1 === count( $losers ) && 'payment_session_creation_in_progress' === $losers[0]['code'] ), 'scenario 1: any losing caller receives only creation-in-progress' );
	$scenario_one_calls = journal( $journal_one );
	check( 1 === count( $scenario_one_calls ), 'scenario 1: exactly one physical provider invocation occurs' );
	check( ! $scenario_one_calls[0]['application_transaction'] && ! $scenario_one_calls[0]['server_transaction'], 'scenario 1: provider executes outside application and server transactions' );
	check( ! $results[0]['transaction_active'] && ! $results[1]['transaction_active'], 'scenario 1: both application transaction managers finish inactive' );
	$admin = connect_test_db(); $session_one = assert_final_state( $admin, 1, $keys[ array_search( 'payment_session_created', array_column( $results, 'code' ), true ) ], $reservation_one, 'scenario 1' );
	foreach ( $successes as $successful_result ) { check( (int) $session_one->id === $successful_result['session_id'] && $session_one->reference === $successful_result['reference'] && $session_one->provider_session_id === $successful_result['provider_session_id'], 'scenario 1: every successful outcome converges on the sole provider identity' ); }

	// Scenario 2: A announces provider entry and blocks; only then is B started.
	$reservation_two = seed_order( $admin, 2 ); $journal_two = $workdir . '/scenario-2.jsonl'; $admin->close(); $admin = null; unset( $GLOBALS['wpdb'] );
	$a_pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
	$a_pid = pcntl_fork();
	if ( 0 === $a_pid ) { fclose( $a_pair[0] ); try { $db = connect_test_db(); [ $svc, $tx ] = service( $db, $journal_two, 'block', $a_pair[1] ); $result = $svc->create( 2, str_repeat( 'c', 32 ) ); $packet = result_packet( $result, $db ); $packet['transaction_active'] = $tx->is_active(); send_packet( $a_pair[1], $packet ); $db->close(); exit( 0 ); } catch ( Throwable $error ) { send_packet( $a_pair[1], array( 'error' => $error->getMessage() ) ); exit( 31 ); } }
	fclose( $a_pair[1] ); $entered = receive_packet( $a_pair[0], 'A provider-entry proof' );
	check( 'provider_entered' === ( $entered['event'] ?? '' ) && ! $entered['entry']['application_transaction'] && ! $entered['entry']['server_transaction'], 'scenario 2: A is proven inside provider with application and server transactions closed' );
	$b_pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP ); $b_pid = pcntl_fork();
	if ( 0 === $b_pid ) { fclose( $b_pair[0] ); fclose( $a_pair[0] ); try { $db = connect_test_db(); [ $svc, $tx ] = service( $db, $journal_two ); $result = $svc->create( 2, str_repeat( 'd', 32 ) ); $packet = result_packet( $result, $db ); $packet['transaction_active'] = $tx->is_active(); send_packet( $b_pair[1], $packet ); $db->close(); exit( 0 ); } catch ( Throwable $error ) { send_packet( $b_pair[1], array( 'error' => $error->getMessage() ) ); exit( 32 ); } }
	fclose( $b_pair[1] ); $b_first = receive_packet( $b_pair[0], 'B creating-window result' ); wait_child( $b_pid, 'scenario 2 B' ); fclose( $b_pair[0] );
	check( ! $b_first['success'] && 'payment_session_creation_in_progress' === $b_first['code'] && '' === $b_first['sql_error'], 'scenario 2: B sees the committed creating attempt and receives the stable retryable result' );
	check( 1 === count( journal( $journal_two ) ), 'scenario 2: B makes no provider call while A is blocked' );
	fwrite( $a_pair[0], "release\n" ); fflush( $a_pair[0] ); $a_result = receive_packet( $a_pair[0], 'A activation result' ); wait_child( $a_pid, 'scenario 2 A' ); fclose( $a_pair[0] );
	check( $a_result['success'] && 'payment_session_created' === $a_result['code'], 'scenario 2: released A activates its claimed session' );
	$db = connect_test_db(); [ $svc, $tx ] = service( $db, $journal_two ); $b_retry = result_packet( $svc->create( 2, str_repeat( 'd', 32 ) ), $db ); $b_retry['transaction_active'] = $tx->is_active(); $db->close(); unset( $GLOBALS['wpdb'] );
	check( $b_retry['success'] && 'payment_session_already_active' === $b_retry['code'], 'scenario 2: B retry converges on the active order-owned session' );
	foreach ( array( 'session_id', 'reference', 'provider', 'provider_session_id', 'redirect_url', 'expires_at' ) as $field ) { check( $a_result[ $field ] === $b_retry[ $field ], "scenario 2: B retry preserves {$field}" ); }
	check( 1 === count( journal( $journal_two ) ) && ! $b_retry['transaction_active'], 'scenario 2: B retry makes no provider call and leaves no transaction open' );
	$admin = connect_test_db(); $session_two = assert_final_state( $admin, 2, str_repeat( 'c', 32 ), $reservation_two, 'scenario 2' ); check( 2500 === (int) $session_two->amount_minor && 'EUR' === $session_two->currency, 'scenario 2: amount and currency remain coherent' );

	// Scenarios 3/4: an OUTCOME_UNKNOWN exception preserves creating; keys then exercise takeover and recovery.
	$reservation_three = seed_order( $admin, 3 ); $journal_three = $workdir . '/scenario-3.jsonl'; $db = $admin; [ $svc, $tx ] = service( $db, $journal_three, 'ambiguous' ); $key_k = str_repeat( 'e', 32 );
	$first = $svc->create( 3, $key_k ); $creating = $db->get_row( "SELECT * FROM `{$prefix}c2c_payment_sessions` WHERE order_id=3" );
	check( ! $first->is_success() && 'provider_response_unknown' === $first->get_code() && 'creating' === $creating->status && 1 === (int) $creating->active_slot, 'scenario 3: current ambiguous-provider contract keeps the durable creating slot' );
	$first_calls = journal( $journal_three ); check( 1 === count( $first_calls ) && ! $first_calls[0]['application_transaction'] && ! $first_calls[0]['server_transaction'], 'scenario 3: ambiguous provider invocation occurs outside both transaction layers' );
	[ $different_service, $different_tx ] = service( $db, $journal_three ); $different = $different_service->create( 3, str_repeat( 'f', 32 ) );
	check( ! $different->is_success() && 'payment_session_creation_in_progress' === $different->get_code(), 'scenario 4: a different key cannot take over the ambiguous creating attempt' );
	check( 1 === count( journal( $journal_three ) ) && hash( 'sha256', $key_k ) === scalar( $db, "SELECT creation_key_hash FROM `{$prefix}c2c_payment_sessions` WHERE order_id=3" ), 'scenario 4: different key neither calls provider nor alters creation identity' );
	check( 1 === (int) scalar( $db, "SELECT COUNT(*) FROM `{$prefix}c2c_payment_sessions` WHERE order_id=3 AND active_slot=1" ) && ! $different_tx->is_active(), 'scenario 4: different key neither replaces the slot nor leaves a transaction open' );
	[ $retry_service, $retry_tx ] = service( $db, $journal_three ); $recovered = $retry_service->create( 3, $key_k ); $calls = journal( $journal_three );
	check( $recovered->is_success() && 'payment_session_created' === $recovered->get_code(), 'scenario 3: original same-key retry recovers the attempt' );
	check( 2 === count( $calls ) && $calls[0]['reference'] === $calls[1]['reference'] && $calls[0]['reference'] === $creating->reference, 'scenario 3: retry preserves the durable local reference' );
	check( $calls[0]['idempotency_key'] === $calls[1]['idempotency_key'] && $calls[0]['logical_id'] === $calls[1]['logical_id'], 'scenario 3: retry preserves provider idempotency and one logical remote identity' );
	check( $calls[1]['logical_id'] === $recovered->get_provider_session_id() && ! $calls[1]['application_transaction'] && ! $calls[1]['server_transaction'], 'scenario 3: recovered provider identity is coherent and called outside transactions' );
	check( 2500 === $calls[0]['amount_minor'] && 'EUR' === $calls[0]['currency'] && $calls[0]['amount_minor'] === $calls[1]['amount_minor'] && $calls[0]['currency'] === $calls[1]['currency'], 'scenario 3: amount and currency are stable across response-loss retry' );
	$session_three = assert_final_state( $db, 3, $key_k, $reservation_three, 'scenario 3' ); check( $session_three->reference === $creating->reference && $session_three->provider_session_id === $calls[0]['logical_id'] && ! $retry_tx->is_active(), 'scenario 3: the same local row activates with the logical provider session and no open transaction' );

	echo "All payment-session real-MariaDB concurrency checks passed.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" ); $exit = 1;
} finally {
	drop_fixture( $admin ); if ( $admin instanceof wpdb ) { $admin->close(); }
	if ( is_dir( $workdir ) ) { foreach ( glob( $workdir . '/*' ) ?: array() as $file ) { @unlink( $file ); } @rmdir( $workdir ); }
}
exit( $exit ?? 0 );
