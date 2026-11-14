<?php
/**
 * Isolated CLI verification for Milestone 7A order and reservation persistence.
 *
 * Run from the plugin root with: php tests/verify-order-reservation-persistence.php
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );

require_once __DIR__ . '/support/commerce-fixtures.php';
class wpdb implements \RodolfoRizzo\Cpt2Collection\CommerceConnection {
	use TestCommerceConnection;
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public string $last_error = '';
	public array $orders = array();
	public array $carts = array();
	public array $order_items = array();
	public array $cart_items = array();
	public array $reservations = array();

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$value = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query = preg_replace( '/%[ds]/', $value, $query, 1 );
		}
		return $query;
	}

	public function insert( string $table, array $data, array $formats ): int|false {
		$this->insert_id++;
		$data['id'] = $this->insert_id;
		if ( 'wp_c2c_orders' === $table ) {
			$data += array( 'paid_at' => null, 'cancelled_at' => null, 'expired_at' => null, 'failed_at' => null );
			$this->orders[ $this->insert_id ] = $data;
			return 1;
		}
		if ( 'wp_c2c_order_items' === $table ) {
			$this->order_items[ $this->insert_id ] = $data;
			return 1;
		}
		if ( 'wp_c2c_reservations' === $table ) {
			foreach ( $this->reservations as $reservation ) {
				if ( $reservation['order_item_id'] === $data['order_item_id'] ) {
					return false;
				}
			}
			$data += array( 'released_at' => null, 'converted_at' => null );
			$this->reservations[ $this->insert_id ] = $data;
			return 1;
		}
		return false;
	}

	public function get_row( string $query ): ?object {
		if ( str_contains( $query, 'FROM wp_c2c_reservations r JOIN wp_c2c_order_items' ) && preg_match( '/r.id\s*=\s*(\d+)/', $query, $m ) ) {
			$reservation = $this->reservations[ (int) $m[1] ] ?? null;
			$item = null === $reservation ? null : ( $this->order_items[ $reservation['order_item_id'] ] ?? null );
			return null === $reservation || null === $item ? null : (object) array( 'order_id' => $reservation['order_id'], 'post_id' => $reservation['post_id'], 'item_order_id' => $item['order_id'], 'item_post_id' => $item['post_id'] );
		}
		if ( str_contains( $query, 'JOIN wp_c2c_carts' ) && preg_match( '/o.id=(\d+)WHEREi.id=(\d+)/', str_replace( ' ', '', $query ), $m ) ) {
			$order = $this->orders[ (int) $m[1] ] ?? null;
			$source = $this->cart_items[ (int) $m[2] ] ?? null;
			if ( null === $order || null === $source ) { return null; }
			return (object) array( 'epoch_id' => 1, 'cart_id' => $source['cart_id'], 'post_id' => $source['post_id'], 'order_cart_id' => $order['cart_id'] );
		}
		if ( str_contains( $query, 'wp_c2c_carts' ) && preg_match( '/id = (\d+)/', $query, $m ) ) {
			$row = $this->carts[ (int) $m[1] ] ?? null;
			return null === $row ? null : (object) $row;
		}
		if ( str_contains( $query, 'wp_c2c_orders' ) && preg_match( '/id\s*=\s*(\d+)/', $query, $m ) ) {
			$row = $this->orders[ (int) $m[1] ] ?? null;
			return null === $row ? null : (object) $row;
		}
		if ( str_contains( $query, 'wp_c2c_order_items' ) && preg_match( '/id\s*=\s*(\d+)/', $query, $m ) ) {
			$row = $this->order_items[ (int) $m[1] ] ?? null;
			return null === $row ? null : (object) $row;
		}
		if ( str_contains( $query, 'wp_c2c_reservations' ) && preg_match( '/order_item_id\s*=\s*(\d+)/', $query, $m ) ) {
			foreach ( $this->reservations as $row ) {
				if ( $row['order_item_id'] === (int) $m[1] ) { return (object) $row; }
			}
			return null;
		}
		if ( str_contains( $query, 'wp_c2c_reservations' ) && preg_match( '/id\s*=\s*(\d+)/', $query, $m ) ) {
			$row = $this->reservations[ (int) $m[1] ] ?? null;
			return null === $row ? null : (object) $row;
		}
		return null;
	}

	public function query(string $query):int { return 0; }
	public function get_results( string $query ): array {
		if(str_contains($query,"information_schema.TABLES")){preg_match_all("/'([^']+)'/",$query,$m);return array_map(fn($n)=>(object)["TABLE_NAME"=>$n,"ENGINE"=>"InnoDB"],$m[1]);} if (null !== ($foundation = $this->foundation_results($query))) { return $foundation; }
		if ( str_contains( $query, 'wp_c2c_order_items' ) && preg_match( '/order_id = (\d+)/', $query, $m ) ) {
			return array_values( array_map( static fn( array $row ): object => (object) $row, array_filter( $this->order_items, static fn( array $row ): bool => $row['order_id'] === (int) $m[1] ) ) );
		}
		if ( str_contains( $query, 'wp_c2c_reservations' ) && preg_match( '/order_id = (\d+)/', $query, $m ) ) {
			return array_values( array_map( static fn( array $row ): object => (object) $row, array_filter( $this->reservations, static fn( array $row ): bool => $row['order_id'] === (int) $m[1] ) ) );
		}
		if ( str_contains( $query, 'wp_c2c_reservations' ) && preg_match( '/post_id = (\d+)/', $query, $m ) ) {
			return array_values( array_map( static fn( array $row ): object => (object) $row, array_filter( $this->reservations, static fn( array $row ): bool => $row['post_id'] === (int) $m[1] ) ) );
		}
		return array();
	}

	public function update( string $table, array $data, array $where, array $formats, array $where_formats ): int|false {
		$store =& $this->orders;
		if ( 'wp_c2c_reservations' === $table ) { $store =& $this->reservations; }
		$id = $where['id'];
		if ( ! isset( $store[ $id ] ) || $store[ $id ]['status'] !== $where['status'] ) { return 0; }
		$store[ $id ] = array_merge( $store[ $id ], $data );
		return 1;
	}

	public function get_var( string $query ): mixed {
		if ( preg_match( "/post_id = (\d+) AND status = 'active' AND expires_at > '([^']+)'/", $query, $m ) ) {
			$total = 0;
			foreach ( $this->reservations as $row ) {
				if ( $row['post_id'] === (int) $m[1] && 'active' === $row['status'] && $row['expires_at'] > $m[2] ) { $total += $row['quantity']; }
			}
			return $total;
		}
		return null;
	}
}

function __( string $message, string $domain ): string { return $message; }
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['c2c_test_options'][ $name ] ?? $default; }

foreach ( array( 'table-names', 'money', 'commerce-settings', 'order-statuses', 'reservation-statuses', 'cart-item', 'order-record', 'order-item', 'reservation-record', 'order-repository', 'reservation-repository', 'database' ) as $class ) {
	require_once dirname( __DIR__ ) . '/includes/class-' . $class . '.php';
}

use RodolfoRizzo\Cpt2Collection\CommerceSettings;
use RodolfoRizzo\Cpt2Collection\CartItem;
use RodolfoRizzo\Cpt2Collection\Database;
use RodolfoRizzo\Cpt2Collection\OrderItem;
use RodolfoRizzo\Cpt2Collection\OrderRecord;
use RodolfoRizzo\Cpt2Collection\OrderRepository;
use RodolfoRizzo\Cpt2Collection\OrderStatuses;
use RodolfoRizzo\Cpt2Collection\ReservationRecord;
use RodolfoRizzo\Cpt2Collection\ReservationRepository;
use RodolfoRizzo\Cpt2Collection\ReservationStatuses;
use RodolfoRizzo\Cpt2Collection\TableNames;

function check( bool $condition, string $label ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } echo "PASS: {$label}\n"; }
function throws( callable $fn, string $label ): void { try { $fn(); } catch ( InvalidArgumentException $e ) { echo "PASS: {$label}\n"; return; } throw new RuntimeException( $label ); }

$wpdb = new wpdb();
$tables = new TableNames( $wpdb );
$fixture_tx = new \RodolfoRizzo\Cpt2Collection\TransactionManager($wpdb,$tables); check($fixture_tx->begin_commerce_shared(), "repository fixture admitted");
check( 'wp_c2c_carts' === $tables->carts(), 'existing carts table name unchanged' );
check( 'wp_c2c_cart_items' === $tables->cart_items(), 'existing cart items table name unchanged' );
check( 'wp_c2c_orders' === $tables->orders(), 'orders table name' );
check( 'wp_c2c_order_items' === $tables->order_items(), 'order items table name' );
check( 'wp_c2c_reservations' === $tables->reservations(), 'reservations table name' );
check( 'wp_c2c_payment_sessions' === $tables->payment_sessions(), 'payment sessions table name' );
check( '0.12.0' === Database::SCHEMA_VERSION, 'schema version is 0.12.0' );
$schema = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-database.php' );
check( str_contains( $schema, 'content_version BIGINT UNSIGNED NOT NULL DEFAULT 0' ), 'cart item content version has a non-destructive default' );
check( str_contains( $schema, 'source_cart_item_id BIGINT UNSIGNED NULL' ) && str_contains( $schema, 'source_cart_item_version BIGINT UNSIGNED NULL' ), 'order item source identity is nullable for historical rows' );
check( str_contains( $schema, 'cart_reconciliation_status VARCHAR(32) NULL' ) && str_contains( $schema, 'cart_reconciliation_code VARCHAR(64) NULL' ) && str_contains( $schema, 'cart_reconciliation_updated_at DATETIME NULL' ), 'order reconciliation state is nullable for historical rows' );
check( str_contains( $schema, 'dbDelta( $statement )' ) && ! str_contains( $schema, 'DROP TABLE' ), 'schema upgrade remains additive through dbDelta' );
foreach ( array( 'carts', 'cart_items', 'orders', 'order_items', 'reservations', 'payment_sessions', 'payment_events' ) as $method ) { check( method_exists( $tables, $method ), "schema checks include {$method} table method" ); }

foreach ( array( 'pending', 'awaiting_payment', 'paid', 'cancelled', 'expired', 'failed' ) as $status ) { check( OrderStatuses::is_valid( $status ), "order status {$status}" ); }
check( ! OrderStatuses::is_valid( 'completed' ), 'arbitrary order status rejected' );
foreach ( array( 'active', 'expired', 'released', 'converted' ) as $status ) { check( ReservationStatuses::is_valid( $status ), "reservation status {$status}" ); }
check( ! ReservationStatuses::is_valid( 'awaiting_payment' ), 'arbitrary reservation status rejected' );

foreach ( array( array( 15, 15 ), array( '15', 15 ), array( 5, 5 ), array( 120, 120 ), array( 4, 35 ), array( 121, 35 ), array( 0, 35 ), array( -1, 35 ), array( 15.5, 35 ), array( 'abc', 35 ) ) as $case ) { check( $case[1] === CommerceSettings::normalize_reservation_duration_minutes( $case[0] ), 'reservation duration normalizes ' . (string) $case[0] ); }

$now = '2026-08-05 12:00:00';
$legacy_cart_item = CartItem::from_row( (object) array( 'id' => 1, 'cart_id' => 2, 'post_id' => 3, 'quantity' => 4 ) );
$versioned_cart_item = CartItem::from_row( (object) array( 'id' => 2, 'cart_id' => 2, 'post_id' => 4, 'quantity' => 1, 'content_version' => '7' ) );
check( 0 === $legacy_cart_item->content_version() && 7 === $versioned_cart_item->content_version(), 'cart item hydration supports legacy default and persisted content version' );
$order = new OrderRecord( 1, null, 'pending', 'EUR', 1000, 1000, $now, $now, null, null, null, null );
check( null === $order->paid_at() && null === $order->cart_reconciliation_status(), 'historical order hydration leaves reconciliation state unclassified' );
throws( static fn() => new OrderRecord( 0, null, 'pending', 'EUR', 0, 0, $now, $now ), 'invalid order id rejected' );
throws( static fn() => new OrderRecord( 1, null, 'bad', 'EUR', 0, 0, $now, $now ), 'invalid order status rejected' );
throws( static fn() => new OrderRecord( 1, null, 'pending', 'GBP', 0, 0, $now, $now ), 'invalid order currency rejected' );
throws( static fn() => new OrderRecord( 1, null, 'pending', 'EUR', -1, 0, $now, $now ), 'negative order amount rejected' );
throws( static fn() => new OrderItem( 1, 1, 1, 'Item', 100, 0, 0, 0, 'EUR', $now ), 'zero order item quantity rejected' );
throws( static fn() => new ReservationRecord( 1, 1, 1, 1, 0, 'active', $now, $now, '2026-08-05 12:15:00' ), 'zero reservation quantity rejected' );
throws( static fn() => new ReservationRecord( 1, 1, 1, 1, 1, 'draft', $now, $now, '2026-08-05 12:15:00' ), 'invalid reservation status rejected' );
$reservation_record = new ReservationRecord( 1, 1, 1, 1, 1, 'active', $now, $now, '2026-08-05 12:15:00' );
check( $reservation_record->is_active_at( $now ), 'reservation active before expiration' );
check( ! $reservation_record->is_active_at( '2026-08-05 12:15:00' ), 'reservation inactive at expiration boundary' );

$order_repo = new OrderRepository( $wpdb, $tables );
$created = $order_repo->create( null, 'pending', 'EUR', 2500, 2500, $now, $now );
check( $created instanceof OrderRecord && 1 === $created->id(), 'order creation' );
check( 'pending' === $created->cart_reconciliation_status() && null === $created->cart_reconciliation_code() && null === $created->cart_reconciliation_updated_at(), 'new order begins with durable pending reconciliation state' );
check( null === $created->cart_revision() && null === $created->checkout_generation(), 'legacy-compatible order creation leaves checkout identity null' );
$wpdb->carts[9] = array( 'id' => 9, 'epoch_id' => 1 );
$revisioned = $order_repo->create( 9, 'pending', 'EUR', 100, 100, $now, $now, null, 3, 2 );
check( $revisioned instanceof OrderRecord && 3 === $revisioned->cart_revision() && 2 === $revisioned->checkout_generation(), 'order creation persists revision and generation' );
check( $order_repo->find( 1 ) instanceof OrderRecord, 'order retrieval' );
$item = $order_repo->insert_item( 1, 44, 'Snapshot title', 1250, 2, 2500, 2500, 'EUR', $now );
check( $item instanceof OrderItem && 44 === $item->post_id() && null === $item->source_cart_item_id() && null === $item->source_cart_item_version(), 'phase-one order-item insertion preserves null legacy source identity' );
$wpdb->cart_items[91] = array( 'id' => 91, 'cart_id' => 9, 'post_id' => 45 );
$source_item = $order_repo->insert_item( $revisioned->id(), 45, 'Source snapshot', 100, 1, 100, 100, 'EUR', $now, 91, 6 );
check( $source_item instanceof OrderItem && 91 === $source_item->source_cart_item_id() && 6 === $source_item->source_cart_item_version(), 'order-item source identity persists and hydrates' );
check( 1 === count( $order_repo->get_items( 1 ) ) && 1 === count( $order_repo->get_items( $revisioned->id() ) ), 'order-item retrieval preserves ownership' );
check( $order_repo->update_status( 1, 'pending', 'awaiting_payment', '2026-08-05 12:01:00' ), 'conditional order status update succeeds' );
check( ! $order_repo->update_status( 1, 'pending', 'paid', '2026-08-05 12:02:00' ), 'conditional order status update rejects stale status' );
check( ! $order_repo->mark_paid( 1, 'pending', '2026-08-05 12:02:00' ), 'financial paid transition rejects pending source' );
check( $order_repo->mark_paid( 1, 'awaiting_payment', '2026-08-05 12:02:00' ), 'financial paid transition accepts awaiting-payment source' );
check( '2026-08-05 12:02:00' === $order_repo->find( 1 )->paid_at(), 'financial paid transition stores paid timestamp' );
check( false === $order_repo->create( null, 'bad', 'EUR', 0, 0, $now, $now ), 'order repository rejects invalid status' );

$res_repo = new ReservationRepository( $wpdb, $tables );
$res = $res_repo->create( 1, $item->id(), 44, 2, 'active', $now, $now, '2026-08-05 12:20:00' );
check( $res instanceof ReservationRecord, 'reservation creation' );
check( $res_repo->find( $res->id() ) instanceof ReservationRecord, 'reservation retrieval by id' );
check( $res_repo->find_by_order_item( $item->id() ) instanceof ReservationRecord, 'reservation retrieval by order item' );
check( 1 === count( $res_repo->get_for_order( 1 ) ), 'reservation retrieval for order' );
check( $res_repo->update_status( $res->id(), 'active', 'released', '2026-08-05 12:03:00', '2026-08-05 12:03:00' ), 'conditional reservation status update succeeds' );
check( ! $res_repo->update_status( $res->id(), 'active', 'converted', '2026-08-05 12:04:00' ), 'conditional reservation status update rejects stale status' );
foreach ( range( 100, 105 ) as $id ) { $wpdb->order_items[$id] = array( 'id' => $id, 'order_id' => 1, 'post_id' => 44 ); }
$res_repo->create( 1, 100, 44, 3, 'active', $now, $now, '2026-08-05 12:20:00' );
$res_repo->create( 1, 101, 44, 5, 'expired', $now, $now, '2026-08-05 12:20:00' );
$res_repo->create( 1, 102, 44, 7, 'released', $now, $now, '2026-08-05 12:20:00' );
$res_repo->create( 1, 103, 44, 11, 'converted', $now, $now, '2026-08-05 12:20:00' );
$res_repo->create( 1, 104, 44, 13, 'active', $now, $now, '2026-08-05 12:00:00' );
$res_repo->create( 1, 105, 44, 17, 'active', $now, $now, '2026-08-05 11:59:59' );
check( 3 === $res_repo->get_active_reserved_quantity_for_post( 44, $now ), 'active reserved quantity excludes inactive and expired rows' );
check( 0 === $res_repo->get_active_reserved_quantity_for_post( 999, $now ), 'active reserved quantity returns zero when none exist' );

echo "All Milestone 7A persistence checks passed.\n";
