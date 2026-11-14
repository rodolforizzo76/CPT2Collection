<?php
/** Isolated verification for Milestone 7B reservation acquisition. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );

require_once __DIR__ . '/support/commerce-fixtures.php';
class wpdb implements \RodolfoRizzo\Cpt2Collection\CommerceConnection {
	use TestCommerceConnection;
	public string $prefix = 'wp_';
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $last_error = '';
	public int $last_errno = 0;
	public int $insert_id = 0;
	public array $orders = array();
	public array $items = array();
	public array $reservations = array();
	public array $products = array();
	public array $locked = array();
	public string $engine = 'InnoDB';
	public ?int $fail_order_item_id = null;
	public array $product_lock_errors = array();
	public array $queries = array();
	public int $transaction_starts = 0;
	public ?array $reservation_on_rollback = null;
	public bool $throw_on_order_items = false;
	public bool $fail_rollback = false;
	private ?array $backup = null;

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$value = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query = preg_replace( '/%[ds]/', $value, $query, 1 );
		}
		return $query;
	}
	public function query( string $query ): int|false {
		$this->queries[] = $query;
		if ( 'START TRANSACTION' === $query ) { $this->transaction_starts++; $this->backup = $this->reservations; return 0; }
		if ( 'COMMIT' === $query ) { $this->backup = null; return 0; }
		if ( 'ROLLBACK' === $query ) { if ( $this->fail_rollback ) { return false; } $this->reservations = $this->backup ?? $this->reservations; $this->backup = null; if ( null !== $this->reservation_on_rollback ) { $row = $this->reservation_on_rollback; $this->reservations[ $row['id'] ] = $row; $this->reservation_on_rollback = null; } return 0; }
		return false;
	}
	public function get_row( string $query ): ?object {
		$this->queries[] = $query;
		if ( str_contains($query,'wp_c2c_reservations r JOIN wp_c2c_order_items i') && preg_match('/r\.id\s*=\s*(\d+)/',$query,$m) ) { $r=$this->reservations[(int)$m[1]]??null; $i=null===$r?null:($this->items[$r['order_item_id']]??null); return null===$r||null===$i?null:(object)['order_id'=>$r['order_id'],'post_id'=>$r['post_id'],'item_order_id'=>$i['order_id'],'item_post_id'=>$i['post_id']]; }
		if ( str_contains( $query, 'wp_c2c_order_items' ) && preg_match( '/id\s*=\s*(\d+)/', $query, $m ) ) { return isset( $this->items[ (int) $m[1] ] ) ? (object) $this->items[ (int) $m[1] ] : null; }
		if ( str_contains( $query, 'wp_c2c_orders' ) && preg_match( '/id\s*=\s*(\d+)/', $query, $m ) ) { return isset( $this->orders[ (int) $m[1] ] ) ? (object) $this->orders[ (int) $m[1] ] : null; }
		if ( str_contains( $query, 'wp_c2c_reservations' ) && preg_match( '/id\s*=\s*(\d+)/', $query, $m ) ) { return isset( $this->reservations[ (int) $m[1] ] ) ? (object) $this->reservations[ (int) $m[1] ] : null; }
		return null;
	}
	public function get_var( string $query ): mixed {
		$this->queries[] = $query;
		if ( str_contains( $query, 'FROM wp_posts' ) && preg_match( '/ID = (\d+)/', $query, $m ) ) { $id = (int) $m[1]; $this->locked[] = $id; if ( array() !== $this->product_lock_errors ) { $this->last_errno = (int) array_shift( $this->product_lock_errors ); $this->last_error = 1213 === $this->last_errno ? 'Deadlock found when trying to get lock' : ( 1205 === $this->last_errno ? 'Lock wait timeout exceeded' : 'Injected database error' ); return null; } return isset( $this->products[ $id ] ) ? $id : null; }
		return null;
	}
	public function get_results( string $query ): array { if (null !== ($foundation = $this->foundation_results($query))) { return $foundation; }
		$this->queries[] = $query;
		if ( $this->throw_on_order_items && str_contains( $query, 'FROM wp_c2c_order_items' ) ) { throw new RuntimeException( "Injected <b>repository</b>\nexception" ); }
		if ( str_contains( $query, 'information_schema.TABLES' ) ) {
			preg_match_all( "/'([^']+)'/", $query, $names );
			return array_map( fn( string $name ): object => (object) array( 'TABLE_NAME' => $name, 'ENGINE' => $this->engine ), $names[1] );
		}
		if ( str_contains( $query, 'wp_c2c_order_items' ) && preg_match( '/order_id = (\d+)/', $query, $m ) ) { return $this->objects( array_filter( $this->items, static fn( array $r ): bool => $r['order_id'] === (int) $m[1] ) ); }
		if ( str_contains( $query, 'wp_c2c_reservations' ) && str_contains( $query, 'WHERE order_id' ) && preg_match( '/order_id = (\d+)/', $query, $m ) ) {
			$order_id = (int) $m[1];
			preg_match( '/order_item_id IN \(([^)]+)\)/', $query, $ids );
			$item_ids = isset( $ids[1] ) ? array_map( 'intval', explode( ',', $ids[1] ) ) : array();
			return $this->objects( array_filter( $this->reservations, static fn( array $r ): bool => $r['order_id'] === $order_id || in_array( $r['order_item_id'], $item_ids, true ) ) );
		}
		if ( str_contains( $query, 'wp_c2c_reservations' ) && str_contains( $query, 'post_id IN' ) ) {
			preg_match( "/expires_at > '([^']+)'/", $query, $now );
			return $this->objects( array_filter( $this->reservations, static fn( array $r ): bool => 'active' === $r['status'] && $r['expires_at'] > $now[1] ) );
		}
		if ( str_contains( $query, 'FROM wp_posts p' ) && preg_match( '/p.ID = (\d+)/', $query, $m ) ) {
			$p = $this->products[ (int) $m[1] ] ?? null;
			if ( null === $p ) { return array(); }
			return array_map( static fn( string $key, mixed $value ): object => (object) array( 'post_type' => $p['post_type'], 'meta_key' => $key, 'meta_value' => $value ), array_keys( $p['meta'] ), $p['meta'] );
		}
		return array();
	}
	public function insert( string $table, array $data, array $formats ): int|false {
		$this->queries[] = 'INSERT INTO ' . $table;
		if ( 'wp_c2c_reservations' !== $table ) { return false; }
		if ( $this->fail_order_item_id === $data['order_item_id'] ) { $this->last_error = 'injected insert failure'; return false; }
		foreach ( $this->reservations as $row ) { if ( $row['order_item_id'] === $data['order_item_id'] ) { return false; } }
		$this->insert_id++; $data['id'] = $this->insert_id; $data += array( 'released_at' => null, 'converted_at' => null ); $this->reservations[ $this->insert_id ] = $data; return 1;
	}
	private function objects( array $rows ): array { return array_values( array_map( static fn( array $r ): object => (object) $r, $rows ) ); }
}

function __( string $message, string $domain ): string { return $message; }
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['options'][ $name ] ?? $default; }
function get_post_types( array $args, string $output ): array { return array( 'collection_item' => new WP_Post_Type() ); }
function apply_filters( string $name, mixed $value ): mixed { return $value; }
function current_time( string $type, bool $gmt ): int { return 1786017600; }
class WP_Post_Type { public bool $_builtin = false; public bool $show_ui = true; public bool $public = true; public string $name = 'collection_item'; }

foreach ( array( 'table-names', 'money', 'commerce-settings', 'order-statuses', 'reservation-statuses', 'order-record', 'order-item', 'reservation-record', 'order-repository', 'reservation-repository', 'reservation-operation-result', 'reservation-set-validation-result', 'reservation-set-validator', 'transaction-manager', 'meta-keys', 'locked-catalog-reader', 'post-types', 'utc-clock', 'reservation-acquirer' ) as $class ) { require_once dirname( __DIR__ ) . '/includes/class-' . $class . '.php'; }

use RodolfoRizzo\Cpt2Collection\LockedCatalogReader;
use RodolfoRizzo\Cpt2Collection\OrderRepository;
use RodolfoRizzo\Cpt2Collection\PostTypes;
use RodolfoRizzo\Cpt2Collection\ReservationAcquirer;
use RodolfoRizzo\Cpt2Collection\ReservationRepository;
use RodolfoRizzo\Cpt2Collection\TableNames;
use RodolfoRizzo\Cpt2Collection\TransactionManager;
use RodolfoRizzo\Cpt2Collection\UtcClock;

function check( bool $condition, string $label ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } echo "PASS: {$label}\n"; }
function order( int $id, int $total ): array { return array( 'epoch_id' => 1, 'id' => $id, 'cart_id' => null, 'status' => 'pending', 'currency' => 'EUR', 'subtotal_minor' => $total, 'total_minor' => $total, 'created_at' => '2026-08-06 12:00:00', 'updated_at' => '2026-08-06 12:00:00', 'paid_at' => null, 'cancelled_at' => null, 'expired_at' => null, 'failed_at' => null ); }
function item( int $id, int $order, int $post, int $quantity ): array { return array( 'id' => $id, 'order_id' => $order, 'post_id' => $post, 'item_name' => 'Item', 'unit_price_minor' => 100, 'quantity' => $quantity, 'line_subtotal_minor' => 100 * $quantity, 'line_total_minor' => 100 * $quantity, 'currency' => 'EUR', 'created_at' => '2026-08-06 12:00:00' ); }

$GLOBALS['options'] = array( 'cpt2collection_post_type' => 'collection_item', 'cpt2collection_currency' => 'EUR', 'cpt2collection_reservation_duration_minutes' => 15 );
$db = new wpdb(); $tables = new TableNames( $db );
$acquirer = new ReservationAcquirer( new OrderRepository( $db, $tables ), new ReservationRepository( $db, $tables ), new TransactionManager( $db, $tables ), new LockedCatalogReader( $db ), new PostTypes(), new UtcClock() );
$db->products[2] = array( 'post_type' => 'collection_item', 'meta' => array( '_cpt2collection_price_minor' => '100', '_cpt2collection_status' => 'available', '_cpt2collection_stock_quantity' => '2' ) );
$db->products[10] = array( 'post_type' => 'collection_item', 'meta' => array( '_cpt2collection_price_minor' => '100', '_cpt2collection_status' => 'available', '_cpt2collection_stock_quantity' => '5' ) );
$db->orders[1] = order( 1, 300 ); $db->items = array( 1 => item( 1, 1, 10, 1 ), 2 => item( 2, 1, 2, 1 ), 3 => item( 3, 1, 10, 1 ) );
$result = $acquirer->acquire( 1 );
check( $result->is_success() && 'reservation_acquired' === $result->get_code(), 'atomic reservation acquisition succeeds' );
check( array( 2, 10 ) === $db->locked, 'duplicate product ids lock once in numeric order' );
check( 3 === count( $db->reservations ) && 1 === count( array_unique( array_column( $db->reservations, 'expires_at' ) ) ), 'one reservation per item shares expiration' );
$item_query = current( array_values( array_filter( $db->queries, static fn( string $query ): bool => str_contains( $query, 'wp_c2c_order_items' ) ) ) );
$history_query = current( array_values( array_filter( $db->queries, static fn( string $query ): bool => str_contains( $query, 'wp_c2c_reservations' ) && str_contains( $query, 'WHERE order_id' ) ) ) );
$product_query_index = array_key_first( array_filter( $db->queries, static fn( string $query ): bool => str_contains( $query, 'FROM wp_posts WHERE' ) ) );
$active_query_index = array_key_first( array_filter( $db->queries, static fn( string $query ): bool => str_contains( $query, 'post_id IN' ) && str_contains( $query, 'FOR UPDATE' ) ) );
check( false === str_contains( (string) $item_query, 'FOR UPDATE' ) && false === str_contains( (string) $history_query, 'FOR UPDATE' ), 'order items and idempotency history are read without locking' );
check( is_int( $product_query_index ) && is_int( $active_query_index ) && $product_query_index < $active_query_index, 'product mutexes precede the active reservation locking read' );
$expires = array_column( $db->reservations, 'expires_at' );
$replay = $acquirer->acquire( 1 );
check( $replay->is_success() && 'reservation_already_active' === $replay->get_code() && $expires === array_column( $db->reservations, 'expires_at' ), 'exact replay is idempotent and does not extend expiration' );
$complete = $db->reservations;
$db->reservations = array( array_key_first( $complete ) => reset( $complete ) );
$partial = $acquirer->acquire( 1 );
check( ! $partial->is_success() && 'reservation_conflict' === $partial->get_code() && 1 === count( $db->reservations ), 'partial reservation set is a conflict and is not repaired' );
$db->reservations = $complete;
$db->reservations[ array_key_first( $db->reservations ) ]['status'] = 'expired';
$conflict = $acquirer->acquire( 1 );
check( ! $conflict->is_success() && 'reservation_conflict' === $conflict->get_code() && 3 === count( $db->reservations ), 'terminated reservation set is a conflict and is not repaired' );

$db2 = new wpdb(); $tables2 = new TableNames( $db2 ); $acquirer2 = new ReservationAcquirer( new OrderRepository( $db2, $tables2 ), new ReservationRepository( $db2, $tables2 ), new TransactionManager( $db2, $tables2 ), new LockedCatalogReader( $db2 ), new PostTypes(), new UtcClock() );
$db2->products[50] = array( 'post_type' => 'collection_item', 'meta' => array( '_cpt2collection_price_minor' => '100', '_cpt2collection_status' => 'available', '_cpt2collection_stock_quantity' => '4' ) );
$db2->orders[2] = order( 2, 500 ); $db2->items = array( 4 => item( 4, 2, 50, 2 ), 5 => item( 5, 2, 50, 3 ) );
$failed = $acquirer2->acquire( 2 );
check( ! $failed->is_success() && 'insufficient_stock' === $failed->get_code() && array() === $db2->reservations, 'duplicate product quantities aggregate and failure leaves no reservations' );

$db3 = new wpdb(); $tables3 = new TableNames( $db3 ); $acquirer3 = new ReservationAcquirer( new OrderRepository( $db3, $tables3 ), new ReservationRepository( $db3, $tables3 ), new TransactionManager( $db3, $tables3 ), new LockedCatalogReader( $db3 ), new PostTypes(), new UtcClock() );
$db3->products[60] = array( 'post_type' => 'collection_item', 'meta' => array( '_cpt2collection_price_minor' => '100', '_cpt2collection_status' => 'available', '_cpt2collection_stock_quantity' => '5' ) );
$db3->orders[3] = order( 3, 200 ); $db3->items = array( 6 => item( 6, 3, 60, 1 ), 7 => item( 7, 3, 60, 1 ) ); $db3->fail_order_item_id = 7;
$database_failure = $acquirer3->acquire( 3 );
check( 'database_error' === $database_failure->get_code() && array() === $db3->reservations, 'later insert failure rolls back earlier reservation' );
$db3->engine = 'MyISAM'; $db3->fail_order_item_id = null;
$unsupported = $acquirer3->acquire( 3 );
check( 'transactions_not_supported' === $unsupported->get_code() && array() === $db3->reservations, 'non-InnoDB tables fail closed before acquisition' );

foreach ( array( 1213 => 'deadlock', 1205 => 'lock wait timeout' ) as $error_number => $label ) {
	$retry_db = new wpdb(); $retry_tables = new TableNames( $retry_db );
	$retry_db->products[70] = array( 'post_type' => 'collection_item', 'meta' => array( '_cpt2collection_price_minor' => '100', '_cpt2collection_status' => 'available', '_cpt2collection_stock_quantity' => '1' ) );
	$retry_db->orders[7] = order( 7, 100 ); $retry_db->items = array( 8 => item( 8, 7, 70, 1 ) ); $retry_db->product_lock_errors = array( $error_number );
	$retry_acquirer = new ReservationAcquirer( new OrderRepository( $retry_db, $retry_tables ), new ReservationRepository( $retry_db, $retry_tables ), new TransactionManager( $retry_db, $retry_tables ), new LockedCatalogReader( $retry_db ), new PostTypes(), new UtcClock() );
	$retry_result = $retry_acquirer->acquire( 7 ); $retry_diagnostics = $retry_acquirer->get_last_diagnostics();
	check( $retry_result->is_success() && 2 === $retry_db->transaction_starts && 2 === $retry_diagnostics['attempts'] && $retry_diagnostics['recovered'] && $error_number === $retry_diagnostics['error_number'], $label . ' is retried once and recovers' );
}

$exhausted_db = new wpdb(); $exhausted_tables = new TableNames( $exhausted_db );
$exhausted_db->products[80] = array( 'post_type' => 'collection_item', 'meta' => array( '_cpt2collection_price_minor' => '100', '_cpt2collection_status' => 'available', '_cpt2collection_stock_quantity' => '1' ) );
$exhausted_db->orders[8] = order( 8, 100 ); $exhausted_db->items = array( 9 => item( 9, 8, 80, 1 ) ); $exhausted_db->product_lock_errors = array( 1213, 1213, 1213 );
$exhausted_acquirer = new ReservationAcquirer( new OrderRepository( $exhausted_db, $exhausted_tables ), new ReservationRepository( $exhausted_db, $exhausted_tables ), new TransactionManager( $exhausted_db, $exhausted_tables ), new LockedCatalogReader( $exhausted_db ), new PostTypes(), new UtcClock() );
$exhausted_result = $exhausted_acquirer->acquire( 8 );
check( 'database_error' === $exhausted_result->get_code() && 3 === $exhausted_db->transaction_starts && 3 === $exhausted_acquirer->get_last_diagnostics()['attempts'], 'deadlock retries stop after three total attempts' );

$nonretry_db = new wpdb(); $nonretry_tables = new TableNames( $nonretry_db );
$nonretry_db->products[90] = array( 'post_type' => 'collection_item', 'meta' => array( '_cpt2collection_price_minor' => '100', '_cpt2collection_status' => 'available', '_cpt2collection_stock_quantity' => '1' ) );
$nonretry_db->orders[9] = order( 9, 100 ); $nonretry_db->items = array( 10 => item( 10, 9, 90, 1 ) ); $nonretry_db->product_lock_errors = array( 999 );
$nonretry_acquirer = new ReservationAcquirer( new OrderRepository( $nonretry_db, $nonretry_tables ), new ReservationRepository( $nonretry_db, $nonretry_tables ), new TransactionManager( $nonretry_db, $nonretry_tables ), new LockedCatalogReader( $nonretry_db ), new PostTypes(), new UtcClock() );
$nonretry_result = $nonretry_acquirer->acquire( 9 );
check( 'database_error' === $nonretry_result->get_code() && 1 === $nonretry_db->transaction_starts, 'non-retryable database errors are not retried' );

$uncertain_db = new wpdb(); $uncertain_tables = new TableNames( $uncertain_db );
$uncertain_db->products[91] = array( 'post_type' => 'collection_item', 'meta' => array( '_cpt2collection_price_minor' => '100', '_cpt2collection_status' => 'available', '_cpt2collection_stock_quantity' => '1' ) );
$uncertain_db->orders[11] = order( 11, 100 ); $uncertain_db->items = array( 12 => item( 12, 11, 91, 1 ) ); $uncertain_db->product_lock_errors = array( 1213 ); $uncertain_db->fail_rollback = true;
$uncertain_acquirer = new ReservationAcquirer( new OrderRepository( $uncertain_db, $uncertain_tables ), new ReservationRepository( $uncertain_db, $uncertain_tables ), new TransactionManager( $uncertain_db, $uncertain_tables ), new LockedCatalogReader( $uncertain_db ), new PostTypes(), new UtcClock() );
$uncertain_result = $uncertain_acquirer->acquire( 11 );
check( 'transaction_outcome_unknown' === $uncertain_result->get_code() && 1 === $uncertain_db->transaction_starts, 'rollback uncertainty is reported and prevents deadlock retry' );

$throwing_db = new wpdb(); $throwing_tables = new TableNames( $throwing_db );
$throwing_db->orders[12] = order( 12, 100 ); $throwing_db->throw_on_order_items = true;
$throwing_acquirer = new ReservationAcquirer( new OrderRepository( $throwing_db, $throwing_tables ), new ReservationRepository( $throwing_db, $throwing_tables ), new TransactionManager( $throwing_db, $throwing_tables ), new LockedCatalogReader( $throwing_db ), new PostTypes(), new UtcClock() );
$throwing_result = $throwing_acquirer->acquire( 12 ); $throwing_diagnostics = $throwing_acquirer->get_last_diagnostics();
check( 'database_error' === $throwing_result->get_code() && 'The reservation could not be completed because of a database error.' === $throwing_result->get_message(), 'caught Throwable keeps the public database failure message unchanged' );
check( 'exception' === $throwing_diagnostics['failed_stage'] && 'RuntimeException' === $throwing_diagnostics['exception_class'] && 'Injected repository exception' === $throwing_diagnostics['exception_message'], 'caught Throwable exposes sanitized administrator diagnostics' );

$replay_db = new wpdb(); $replay_tables = new TableNames( $replay_db ); $replay_now = '2026-08-06 12:00:00';
$replay_db->products[100] = array( 'post_type' => 'collection_item', 'meta' => array( '_cpt2collection_price_minor' => '100', '_cpt2collection_status' => 'available', '_cpt2collection_stock_quantity' => '1' ) );
$replay_db->orders[10] = order( 10, 100 ); $replay_db->items = array( 11 => item( 11, 10, 100, 1 ) ); $replay_db->product_lock_errors = array( 1213 );
$replay_db->reservation_on_rollback = array( 'epoch_id' => 1, 'id' => 50, 'order_id' => 10, 'order_item_id' => 11, 'post_id' => 100, 'quantity' => 1, 'status' => 'active', 'created_at' => $replay_now, 'updated_at' => $replay_now, 'expires_at' => '2026-08-06 12:15:00', 'released_at' => null, 'converted_at' => null );
$replay_acquirer = new ReservationAcquirer( new OrderRepository( $replay_db, $replay_tables ), new ReservationRepository( $replay_db, $replay_tables ), new TransactionManager( $replay_db, $replay_tables ), new LockedCatalogReader( $replay_db ), new PostTypes(), new UtcClock() );
$replay_result = $replay_acquirer->acquire( 10 );
check( 'reservation_already_active' === $replay_result->get_code() && 2 === $replay_db->transaction_starts && 1 === count( $replay_db->reservations ), 'retry reclassifies idempotency after a competing commit' );

echo "All Milestone 7B isolated acquisition checks passed.\n";
