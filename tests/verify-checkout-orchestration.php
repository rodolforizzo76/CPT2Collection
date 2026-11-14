<?php
/** Isolated verification for Milestone 7C checkout orchestration. */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );

require_once __DIR__ . '/support/commerce-fixtures.php';
class wpdb implements \RodolfoRizzo\Cpt2Collection\CommerceConnection {
	use TestCommerceConnection;
	public string $prefix = 'wp_'; public string $posts = 'wp_posts'; public string $postmeta = 'wp_postmeta'; public string $last_error = ''; public int $insert_id = 0;
	public array $carts = array(); public array $sessions = array(); public array $events = array(); public array $cart_items = array(); public array $orders = array(); public array $deletions = array(); public array $items = array(); public array $reservations = array(); public array $products = array();
	public int $fail_item_insert_at = 0; public int $item_inserts = 0; public bool $cart_locked = false; public int $cart_reads = 0; public bool $fail_item_insert = false; public bool $fail_order_insert = false; public bool $fail_reservation_insert = false; public bool $fail_rollback = false; public bool $throw_item_insert = false; public int $winner_lookups = 0; public bool $fail_status_update = false; public bool $fail_deletion_read = false; public int $fail_admission_at = 0; public int $fail_start_at = 0; public int $starts = 0; public ?string $inject_reservation_status_after_failure = null; public ?string $inject_order_status_after_failure = null; private ?array $backup = null;
	public function prepare( string $query, mixed ...$args ): string { foreach ( $args as $arg ) { $value = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'"; $query = preg_replace( '/%[ds]/', $value, $query, 1 ); } return $query; }
	public function query( string $query ): int|false { if ( 'START TRANSACTION' === $query ) { $this->cart_locked = false; ++$this->starts; if ( $this->fail_start_at === $this->starts ) { return false; } $this->backup = array( $this->orders, $this->items, $this->reservations, $this->carts ); return 0; } if ( 'COMMIT' === $query ) { $this->backup = null; return 0; } if ( 'ROLLBACK' === $query ) { if ( $this->fail_rollback ) { return false; } if ( null !== $this->backup ) { $this->orders = $this->backup[0]; $this->items = $this->backup[1]; $this->reservations = $this->backup[2]; $this->carts = $this->backup[3]; } $this->backup = null; if ( $this->fail_reservation_insert ) { $this->inject_competing_checkout_state(); $this->fail_reservation_insert = false; } return 0; } if ( str_contains( $query, 'FROM wp_posts WHERE' ) && preg_match( '/ID = (\d+)/', $query, $m ) ) { return isset( $this->products[ (int) $m[1] ] ) ? 1 : 0; } return false; }
	private function inject_competing_checkout_state(): void { $order = end( $this->orders ); if ( ! is_array( $order ) ) { return; } $order_id = (int) $order['id']; if ( null !== $this->inject_order_status_after_failure ) { $this->orders[ $order_id ]['status'] = $this->inject_order_status_after_failure; } if ( null === $this->inject_reservation_status_after_failure ) { return; } $items = array_values( array_filter( $this->items, static fn( array $item ): bool => $item['order_id'] === $order_id ) ); if ( array() === $items ) { return; } $item = $items[0]; $id = ++$this->insert_id; $status = $this->inject_reservation_status_after_failure; $this->reservations[ $id ] = array( 'epoch_id' => 1, 'id' => $id, 'order_id' => $order_id, 'order_item_id' => $item['id'], 'post_id' => $item['post_id'], 'quantity' => $item['quantity'], 'status' => $status, 'created_at' => '2026-08-06 12:00:00', 'updated_at' => '2026-08-06 12:00:00', 'expires_at' => '2030-01-01 00:00:00', 'released_at' => null, 'converted_at' => 'converted' === $status ? '2026-08-06 12:00:00' : null ); }
	public function get_var( string $query ): mixed { if ( str_contains( $query, 'cart_revision IS NOT NULL' ) && preg_match( '/cart_id = (\d+)/', $query, $m ) ) { foreach ( $this->orders as $row ) { if ( $row['cart_id'] === (int) $m[1] && null !== $row['cart_revision'] ) { return 1; } } return null; } if ( str_contains( $query, 'wp_c2c_payment_events' ) ) { return null; } if ( str_contains( $query, 'FROM wp_posts WHERE' ) && preg_match( '/ID = (\d+)/', $query, $m ) ) { return isset( $this->products[ (int) $m[1] ] ) ? (int) $m[1] : null; } return null; }
	public function get_row( string $query ): ?object { if(null!==($foundation=$this->foundation_results($query)))return $foundation[0]??null;
		if(str_contains($query,'wp_c2c_cart_items i JOIN wp_c2c_carts c')&&preg_match('/i\.id\s*=\s*(\d+)/',$query,$m)&&preg_match('/o\.id\s*=\s*(\d+)/',$query,$o)){foreach($this->cart_items as $item){if($item['id']===(int)$m[1])return(object)['epoch_id'=>1,'cart_id'=>$item['cart_id'],'post_id'=>$item['post_id'],'order_cart_id'=>$this->orders[(int)$o[1]]['cart_id']??null];}return null;}
		if ( str_contains($query,'wp_c2c_reservations r JOIN wp_c2c_order_items i') && preg_match('/r\.id\s*=\s*(\d+)/',$query,$m) ) { $r=$this->reservations[(int)$m[1]]??null; $i=null===$r?null:($this->items[$r['order_item_id']]??null); return null===$r||null===$i?null:(object)['order_id'=>$r['order_id'],'post_id'=>$r['post_id'],'item_order_id'=>$i['order_id'],'item_post_id'=>$i['post_id']]; }
		if ( str_contains( $query, 'wp_c2c_carts' ) && preg_match( '/id\s*=\s*(\d+)/', $query, $m ) ) { if(str_contains($query,'FOR UPDATE'))$this->cart_locked=true; return isset( $this->carts[(int)$m[1]] ) ? (object) array_merge(array('epoch_id'=>1),$this->carts[(int)$m[1]]) : null; }
		if ( str_contains( $query, 'wp_c2c_orders' ) && str_contains( $query, 'cart_revision = ' ) && preg_match( '/cart_id = (\d+) AND cart_revision = (\d+) AND checkout_generation = (\d+)/', $query, $m ) ) { foreach ( $this->orders as $row ) { if ( $row['cart_id']===(int)$m[1] && $row['cart_revision']===(int)$m[2] && $row['checkout_generation']===(int)$m[3] ) return (object)array_merge(array('epoch_id'=>1),$row); } return null; }

		if ( str_contains( $query, 'wp_c2c_orders' ) && str_contains( $query, 'checkout_attempt_hash' ) && preg_match( "/cart_id = (\d+) AND checkout_attempt_hash = '([^']+)'/", $query, $m ) ) { ++$this->winner_lookups; foreach ( $this->orders as $row ) { if ( $row['cart_id'] === (int) $m[1] && $row['checkout_attempt_hash'] === $m[2] ) { return (object) array_merge(array('epoch_id'=>1),$row); } } return null; }
		if ( str_contains( $query, 'wp_c2c_orders' ) && preg_match( '/id\s*=\s*(\d+)/', $query, $m ) ) { return isset( $this->orders[ (int) $m[1] ] ) ? (object) array_merge(array('epoch_id'=>1),$this->orders[ (int) $m[1] ]) : null; }
		if ( str_contains( $query, 'wp_c2c_order_items' ) && preg_match( '/id\s*=\s*(\d+)/', $query, $m ) ) { return isset( $this->items[ (int) $m[1] ] ) ? (object) $this->items[ (int) $m[1] ] : null; }
		if ( str_contains( $query, 'wp_c2c_reservations' ) && preg_match( '/id\s*=\s*(\d+)/', $query, $m ) ) { return isset( $this->reservations[ (int) $m[1] ] ) ? (object) array_merge(array('epoch_id'=>1),$this->reservations[ (int) $m[1] ]) : null; }
		return null;
	}
	public function get_results( string $query ): array|null {
		if ($this->starts === $this->fail_admission_at && str_contains($query, 'SELECT id, installation_id')) { $this->last_error='injected admission failure'; return null; }
		if (null !== ($foundation = $this->foundation_results($query))) { return $foundation; }
		if ( str_contains( $query, 'FROM wp_c2c_order_deletions' ) && $this->fail_deletion_read ) { $this->last_error = 'injected deletion read failure'; return null; }
		if ( str_contains( $query, 'wp_c2c_order_deletions' ) && preg_match( '/epoch_id = (\d+) AND cart_id = (\d+).*checkout_attempt_hash = \'([^\']+)\'.*cart_revision = (\d+)/', $query, $m ) ) { return $this->objects( array_filter( $this->deletions, static fn( array $r ): bool => $r['epoch_id'] === (int) $m[1] && $r['cart_id'] === (int) $m[2] && ( $r['checkout_attempt_hash'] === $m[3] || ( 'revision' === $r['checkout_disposition'] && $r['cart_revision'] === (int) $m[4] ) ) ) ); }
		if ( str_contains( $query, 'wp_c2c_orders' ) && str_contains( $query, 'cart_revision IS NULL' ) && preg_match( '/cart_id = (\d+)/', $query, $m ) ) { return $this->objects(array_map(static fn(array $r):array=>array_merge(array('epoch_id'=>1),$r),array_filter($this->orders,static fn(array $r):bool=>$r['cart_id']===(int)$m[1] && null===$r['cart_revision']))); }
		if ( str_contains( $query, 'wp_c2c_payment_sessions' ) && preg_match( '/order_id = (\d+)/', $query, $m ) ) { return $this->objects( array_map(static fn(array $r):array=>array_merge(array('epoch_id'=>1),$r),array_filter( $this->sessions, static fn( array $r ): bool => $r['order_id'] === (int) $m[1] ) ) ); }

		if ( str_contains( $query, 'information_schema.TABLES' ) ) { preg_match_all( "/'([^']+)'/", $query, $m ); return array_map( static fn( string $name ): object => (object) array( 'TABLE_NAME' => $name, 'ENGINE' => 'InnoDB' ), $m[1] ); }
		if ( str_contains( $query, 'wp_c2c_cart_items' ) && preg_match( '/cart_id = (\d+)/', $query, $m ) ) { if ( ! $this->cart_locked || null === $this->backup ) { throw new RuntimeException( 'Cart snapshot read without transaction/cart lock' ); } ++$this->cart_reads; return $this->objects( array_filter( $this->cart_items, static fn( array $r ): bool => $r['cart_id'] === (int) $m[1] ) ); }
		if ( str_contains( $query, 'wp_c2c_order_items' ) && preg_match( '/order_id = (\d+)/', $query, $m ) ) { return $this->objects( array_filter( $this->items, static fn( array $r ): bool => $r['order_id'] === (int) $m[1] ) ); }
		if ( str_contains( $query, 'wp_c2c_reservations' ) && str_contains( $query, 'WHERE order_id' ) && preg_match( '/order_id = (\d+)/', $query, $m ) ) { return $this->objects( array_filter( $this->reservations, static fn( array $r ): bool => $r['order_id'] === (int) $m[1] ) ); }
		if ( str_contains( $query, 'post_id IN' ) && str_contains( $query, 'wp_c2c_reservations' ) ) { preg_match( '/post_id IN \(([^)]+)\)/', $query, $m ); $ids = array_map( 'intval', explode( ',', $m[1] ?? '' ) ); return $this->objects( array_filter( $this->reservations, static fn( array $r ): bool => in_array( $r['post_id'], $ids, true ) && 'active' === $r['status'] ) ); }
		if ( str_contains( $query, 'FROM wp_posts p' ) && preg_match( '/p.ID = (\d+)/', $query, $m ) ) { $p = $this->products[ (int) $m[1] ] ?? null; if ( null === $p ) { return array(); } return array_map( static fn( string $key, mixed $value ): object => (object) array( 'post_type' => $p['post_type'], 'meta_key' => $key, 'meta_value' => $value ), array_keys( $p['meta'] ), $p['meta'] ); }
		return array();
	}
	public function insert( string $table, array $data, array $formats ): int|false {
		if ( 'wp_c2c_orders' === $table ) { if ( $this->fail_order_insert ) { return false; } foreach ( $this->orders as $row ) { if ( null !== $data['checkout_attempt_hash'] && $row['cart_id'] === $data['cart_id'] && $row['checkout_attempt_hash'] === $data['checkout_attempt_hash'] ) { return false; } } $id = ++$this->insert_id; $data['id'] = $id; $data += array( 'paid_at' => null, 'cancelled_at' => null, 'expired_at' => null, 'failed_at' => null ); $this->orders[ $id ] = $data; return 1; }
		if ( 'wp_c2c_order_items' === $table ) { if ( $this->throw_item_insert ) { throw new RuntimeException( 'injected item exception' ); } if ( ++$this->item_inserts === $this->fail_item_insert_at || $this->fail_item_insert ) { return false; } $id = ++$this->insert_id; $data['id'] = $id; $this->items[ $id ] = $data; return 1; }
		if ( 'wp_c2c_reservations' === $table ) { if ( $this->fail_reservation_insert ) { return false; } $id = ++$this->insert_id; $data['id'] = $id; $data += array( 'released_at' => null, 'converted_at' => null ); $this->reservations[ $id ] = $data; return 1; }
		return false;
	}
	public function update( string $table, array $data, array $where, array $formats, array $where_formats ): int|false { if ( 'wp_c2c_carts' === $table ) { $id=$where['id']; if (!isset($this->carts[$id])) return 0; $this->carts[$id]=array_merge($this->carts[$id],$data); return 1; } if ( $this->fail_status_update || ! isset( $this->orders[ $where['id'] ] ) || $this->orders[ $where['id'] ]['status'] !== $where['status'] ) { return 0; } $this->orders[ $where['id'] ] = array_merge( $this->orders[ $where['id'] ], $data ); return 1; }
	private function objects( array $rows ): array { return array_values( array_map( static fn( array $row ): object => (object) $row, $rows ) ); }
}

function __( string $message, string $domain ): string { return $message; }
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['options'][ $name ] ?? $default; }
function get_post( int $id ): ?object { return isset( $GLOBALS['db']->products[ $id ] ) ? (object) array( 'ID' => $id ) : null; }
function get_post_type( int $id ): string|false { return $GLOBALS['db']->products[ $id ]['post_type'] ?? false; }
function get_post_meta( int $id, string $key, bool $single ): mixed { return $GLOBALS['db']->products[ $id ]['meta'][ $key ] ?? ''; }
function get_the_title( int $id ): string { return $GLOBALS['db']->products[ $id ]['title'] ?? ''; }
function get_post_types( array $args, string $output ): array { return array( 'collection_item' => new WP_Post_Type() ); }
function apply_filters( string $name, mixed $value ): mixed { return $value; }
function current_time( string $type, bool $gmt ): int { return 1786017600; }
class WP_Post_Type { public bool $_builtin = false; public bool $show_ui = true; public bool $public = true; public string $name = 'collection_item'; }

foreach ( array( 'table-names', 'payment-session-statuses', 'payment-event-statuses', 'payment-session-record', 'payment-event-record', 'payment-session-repository', 'payment-event-repository',  'money', 'commerce-settings', 'order-statuses', 'reservation-statuses', 'order-record', 'order-deletion-record', 'order-item', 'reservation-record', 'cart-record', 'cart-item', 'cart-repository', 'order-repository', 'order-deletion-registry', 'reservation-repository', 'reservation-operation-result', 'reservation-set-validation-result', 'reservation-set-validator', 'transaction-manager', 'meta-keys', 'catalog', 'locked-catalog-reader', 'post-types', 'utc-clock', 'commerce-rule-result', 'commerce-rules', 'reservation-acquirer', 'checkout-preparation-result', 'checkout-service' ) as $class ) { require_once dirname( __DIR__ ) . '/includes/class-' . $class . '.php'; }

use RodolfoRizzo\Cpt2Collection\PaymentSessionRepository; use RodolfoRizzo\Cpt2Collection\PaymentEventRepository; use RodolfoRizzo\Cpt2Collection\CartRecord; use RodolfoRizzo\Cpt2Collection\CartRepository; use RodolfoRizzo\Cpt2Collection\CheckoutService; use RodolfoRizzo\Cpt2Collection\CommerceRules; use RodolfoRizzo\Cpt2Collection\LockedCatalogReader; use RodolfoRizzo\Cpt2Collection\OrderRepository; use RodolfoRizzo\Cpt2Collection\OrderDeletionRegistry; use RodolfoRizzo\Cpt2Collection\PostTypes; use RodolfoRizzo\Cpt2Collection\ReservationAcquirer; use RodolfoRizzo\Cpt2Collection\ReservationRepository; use RodolfoRizzo\Cpt2Collection\TableNames; use RodolfoRizzo\Cpt2Collection\TransactionManager; use RodolfoRizzo\Cpt2Collection\UtcClock;

function check( bool $condition, string $label ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } echo "PASS: {$label}\n"; }
function service( wpdb $db ): CheckoutService { $tables = new TableNames( $db ); $orders = new OrderRepository( $db, $tables ); $transactions = new TransactionManager( $db, $tables ); $clock = new UtcClock(); $post_types = new PostTypes(); $acquirer = new ReservationAcquirer( $orders, new ReservationRepository( $db, $tables ), $transactions, new LockedCatalogReader( $db ), $post_types, $clock ); return new CheckoutService( new CartRepository( $db, $tables ), new CommerceRules( $post_types ), $orders, $transactions, $acquirer, $clock, new ReservationRepository( $db, $tables ), new PaymentSessionRepository( $db, $tables ), new PaymentEventRepository( $db, $tables ), new OrderDeletionRegistry( $db, $tables ) ); }
function cart( int $id ): CartRecord { $record = new CartRecord( $id, hash( 'sha256', 'cart-' . $id ), '2026-08-06 12:00:00', '2026-08-06 12:00:00', '2026-08-06 12:00:00', '2030-01-01 00:00:00', 0, 0, 1 ); $GLOBALS['db']->carts[$id]=array('epoch_id'=>1,'id'=>$id,'token_hash'=>$record->token_hash(),'content_revision'=>$record->content_revision(),'checkout_generation'=>$record->checkout_generation(),'created_at'=>$record->created_at(),'updated_at'=>$record->updated_at(),'last_activity_at'=>$record->last_activity_at(),'expires_at'=>$record->expires_at()); return $record; }

$GLOBALS['options'] = array( 'cpt2collection_post_type' => 'collection_item', 'cpt2collection_currency' => 'EUR', 'cpt2collection_minimum_order_quantity' => 1, 'cpt2collection_reservation_duration_minutes' => 15 );
$db = new wpdb(); $GLOBALS['db'] = $db;
$db->cart_items[] = array( 'id' => 1, 'cart_id' => 1, 'post_id' => 10, 'quantity' => 2, 'content_version' => 0 );
$db->products[10] = array( 'post_type' => 'collection_item', 'title' => 'Current title', 'meta' => array( '_cpt2collection_price_minor' => '1250', '_cpt2collection_status' => 'available', '_cpt2collection_stock_quantity' => '20', '_cpt2collection_minimum_order_exempt' => '' ) );
$checkout = service( $db ); $key = str_repeat( 'a', 32 ); $before = $db->cart_items;
$result = $checkout->prepare( cart( 1 ), $key );
check( $result->is_success() && 'checkout_prepared' === $result->get_code() && 'reservation_acquired' === $result->get_reservation_code(), 'valid cart prepares checkout through ReservationAcquirer' );
$order_id = $result->get_order_id(); $order = $db->orders[ $order_id ]; $item = reset( $db->items );
check( 1 === count( $db->orders ) && 1 === $order['cart_id'] && hash( 'sha256', $key ) === $order['checkout_attempt_hash'] && ! str_contains( serialize( $order ), $key ), 'order stores cart-scoped key hash but not raw key' );
check( 'pending' === $order['status'] && 0 === $order['cart_revision'] && 0 === $order['checkout_generation'] && null === $order['failed_at'] && 2500 === $order['total_minor'], 'successful order remains pending with exact total' );
check( 'Current title' === $item['item_name'] && 1250 === $item['unit_price_minor'] && 2 === $item['quantity'] && 2500 === $item['line_total_minor'], 'immutable item snapshot uses authoritative values' );
check( 1 === $item['source_cart_item_id'] && 0 === $item['source_cart_item_version'] && 0 === $db->carts[1]['content_revision'], 'checkout captures source identity/version zero without advancing revision' );
check( $before === $db->cart_items, 'successful preparation leaves cart unchanged' );
$original_items = $db->items; $reads = $db->cart_reads;
$expires = array_column( $db->reservations, 'expires_at' ); $replay = $checkout->prepare( cart( 1 ), $key );
check( $replay->is_success() && $order_id === $replay->get_order_id() && 'reservation_already_active' === $replay->get_reservation_code() && 1 === count( $db->orders ) && $expires === array_column( $db->reservations, 'expires_at' ), 'same-key replay converges without extending reservations' );
check( $original_items === $db->items && $reads === $db->cart_reads, 'same-key replay retains original source pair without reading current cart contents' );
$db->orders[ $order_id ]['status'] = 'awaiting_payment';
$awaiting_snapshot = array( 'orders' => $db->orders, 'items' => $db->items, 'reservations' => $db->reservations );
$awaiting_replay = $checkout->prepare( cart( 1 ), $key );
check( $awaiting_replay->is_success() && 'checkout_prepared' === $awaiting_replay->get_code() && $order_id === $awaiting_replay->get_order_id(), 'same-key awaiting-payment order is a successful checkout replay' );
check( $awaiting_snapshot === array( 'orders' => $db->orders, 'items' => $db->items, 'reservations' => $db->reservations ), 'awaiting-payment replay does not rebuild snapshots, reacquire reservations, extend expiry, or change lifecycle' );
$db->orders[ $order_id ]['status'] = 'paid';
check( 'checkout_already_paid' === $checkout->prepare( cart( 1 ), $key )->get_code(), 'paid generation cannot renew' );
$db->orders[ $order_id ]['status'] = 'pending';
$different_key = $checkout->prepare( cart( 1 ), str_repeat( 'b', 32 ) );
check( $different_key->get_order_id() === $order_id && 1 === count( $db->orders ), 'different checkout key converges on current revision and generation' );
$historical_key = $key; $current_key = str_repeat( 'k', 32 ); $current_order_id = ++$db->insert_id;
$db->orders[ $current_order_id ] = array( 'id' => $current_order_id, 'cart_id' => 1, 'cart_revision' => 1, 'checkout_generation' => 0, 'checkout_attempt_hash' => hash( 'sha256', $current_key ), 'status' => 'pending', 'currency' => 'EUR', 'subtotal_minor' => 2500, 'total_minor' => 2500, 'created_at' => '2026-08-06 12:00:00', 'updated_at' => '2026-08-06 12:00:00', 'paid_at' => null, 'cancelled_at' => null, 'expired_at' => null, 'failed_at' => null );
$current_cart = new CartRecord( 1, hash( 'sha256', 'cart-1' ), '2026-08-06 12:00:00', '2026-08-06 12:00:00', '2026-08-06 12:00:00', '2030-01-01 00:00:00', 1, 0, 1 );
$db->carts[1] = array( 'id' => 1, 'token_hash' => $current_cart->token_hash(), 'content_revision' => 1, 'checkout_generation' => 0, 'created_at' => $current_cart->created_at(), 'updated_at' => $current_cart->updated_at(), 'last_activity_at' => $current_cart->last_activity_at(), 'expires_at' => $current_cart->expires_at() );
$historical_reuse = $checkout->prepare( $current_cart, $historical_key );
check( 'checkout_attempt_conflict' === $historical_reuse->get_code() && $order_id === $historical_reuse->get_order_id() && 2 === count( $db->orders ), 'historical attempt key conflicts instead of replaying the current revision order' );
$empty = $checkout->prepare( cart( 2 ), str_repeat( 'c', 32 ) );
check( ! $empty->is_success() && 'cart_empty' === $empty->get_code() && 'empty_cart' === $empty->get_checkout_rule_code() && 2 === count( $db->orders ), 'empty cart creates no order' );
$invalid_key = $checkout->prepare( cart( 1 ), 'short' );
check( 'invalid_checkout_attempt' === $invalid_key->get_code(), 'malformed idempotency key is rejected before persistence' );
$db->cart_items[] = array( 'id' => 2, 'cart_id' => 3, 'post_id' => 11, 'quantity' => 1, 'content_version' => 0 ); $db->products[11] = array( 'post_type' => 'collection_item', 'title' => '', 'meta' => array( '_cpt2collection_price_minor' => '100', '_cpt2collection_status' => 'available', '_cpt2collection_stock_quantity' => '1', '_cpt2collection_minimum_order_exempt' => '' ) );
$bad_title = $checkout->prepare( cart( 3 ), str_repeat( 'd', 32 ) ); check( 'snapshot_invalid' === $bad_title->get_code() && 2 === count( $db->orders ), 'empty authoritative title creates no order' );
$db->cart_items[] = array( 'id' => 3, 'cart_id' => 4, 'post_id' => 12, 'quantity' => 2, 'content_version' => 0 ); $db->products[12] = array( 'post_type' => 'collection_item', 'title' => 'Unavailable stock', 'meta' => array( '_cpt2collection_price_minor' => '100', '_cpt2collection_status' => 'available', '_cpt2collection_stock_quantity' => '1', '_cpt2collection_minimum_order_exempt' => '' ) );
$denied = $checkout->prepare( cart( 4 ), str_repeat( 'e', 32 ) ); check( 'checkout_not_allowed' === $denied->get_code() && 'insufficient_stock' === $denied->get_checkout_rule_code(), 'commercial denial preserves rule code and creates no order' );
$db->fail_item_insert = true; $db->cart_items[] = array( 'id' => 4, 'cart_id' => 5, 'post_id' => 10, 'quantity' => 1, 'content_version' => 0 ); $count = count( $db->orders );
$persistence = $checkout->prepare( cart( 5 ), str_repeat( 'f', 32 ) ); check( 'order_persistence_failed' === $persistence->get_code() && $count === count( $db->orders ), 'item insertion failure rolls back complete order aggregate' ); $db->fail_item_insert = false;

$main_db = $db; $product = $db->products[10]; $uncertain_db = new wpdb(); $GLOBALS['db'] = $uncertain_db; $uncertain_db->cart_items[] = array( 'id' => 50, 'cart_id' => 50, 'post_id' => 10, 'quantity' => 1, 'content_version' => 0 ); $uncertain_db->products[10] = $product; $uncertain_db->fail_order_insert = true; $uncertain_db->fail_rollback = true; $uncertain_checkout = service( $uncertain_db ); $before_lookups = $uncertain_db->winner_lookups; $uncertain = $uncertain_checkout->prepare( cart( 50 ), str_repeat( 'u', 32 ) );
check( 'transaction_outcome_unknown' === $uncertain->get_code() && $before_lookups + 1 === $uncertain_db->winner_lookups, 'order insert rollback uncertainty performs no post-rollback winner lookup' );
$item_uncertain_db = new wpdb(); $GLOBALS['db'] = $item_uncertain_db; $item_uncertain_db->cart_items[] = array( 'id' => 51, 'cart_id' => 51, 'post_id' => 10, 'quantity' => 1, 'content_version' => 0 ); $item_uncertain_db->products[10] = $product; $item_uncertain_db->fail_item_insert = true; $item_uncertain_db->fail_rollback = true; $item_uncertain = service( $item_uncertain_db )->prepare( cart( 51 ), str_repeat( 'v', 32 ) ); check( 'transaction_outcome_unknown' === $item_uncertain->get_code(), 'item failure with rollback uncertainty is reported' );
$throw_uncertain_db = new wpdb(); $GLOBALS['db'] = $throw_uncertain_db; $throw_uncertain_db->cart_items[] = array( 'id' => 52, 'cart_id' => 52, 'post_id' => 10, 'quantity' => 1, 'content_version' => 0 ); $throw_uncertain_db->products[10] = $product; $throw_uncertain_db->throw_item_insert = true; $throw_uncertain_db->fail_rollback = true; $throw_uncertain = service( $throw_uncertain_db )->prepare( cart( 52 ), str_repeat( 'w', 32 ) ); check( 'transaction_outcome_unknown' === $throw_uncertain->get_code(), 'exception with rollback uncertainty is reported' ); $GLOBALS['db'] = $main_db; $db = $main_db;

$same_key = str_repeat( 'g', 32 );
$db->cart_items[] = array( 'id' => 5, 'cart_id' => 6, 'post_id' => 10, 'quantity' => 1, 'content_version' => 0 );
$db->cart_items[] = array( 'id' => 6, 'cart_id' => 7, 'post_id' => 10, 'quantity' => 1, 'content_version' => 0 );
$cart_six = $checkout->prepare( cart( 6 ), $same_key );
$cart_seven = $checkout->prepare( cart( 7 ), $same_key );
check( $cart_six->is_success() && $cart_seven->is_success() && $cart_six->get_order_id() !== $cart_seven->get_order_id(), 'different carts may use the same raw attempt key' );

$db->cart_items[] = array( 'id' => 7, 'cart_id' => 8, 'post_id' => 13, 'quantity' => 1, 'content_version' => 0 );
$db->products[13] = array( 'post_type' => 'collection_item', 'title' => 'Contended', 'meta' => array( '_cpt2collection_price_minor' => '500', '_cpt2collection_status' => 'available', '_cpt2collection_stock_quantity' => '1', '_cpt2collection_minimum_order_exempt' => '' ) );
$db->reservations[900] = array( 'id' => 900, 'order_id' => 900, 'order_item_id' => 900, 'post_id' => 13, 'quantity' => 1, 'status' => 'active', 'created_at' => '2026-08-06 12:00:00', 'updated_at' => '2026-08-06 12:00:00', 'expires_at' => '2030-01-01 00:00:00', 'released_at' => null, 'converted_at' => null );
$failed_key = str_repeat( 'h', 32 ); $failed = $checkout->prepare( cart( 8 ), $failed_key ); $failed_order = $db->orders[ $failed->get_order_id() ];
check( 'reservation_failed' === $failed->get_code() && 'insufficient_stock' === $failed->get_reservation_code() && 'failed' === $failed_order['status'] && $failed_order['failed_at'] === $failed_order['updated_at'], 'reservation failure preserves complete aggregate and marks order failed' );
$item_count = count( $db->items ); $failed_replay = $checkout->prepare( cart( 8 ), $failed_key );
check( 'checkout_attempt_conflict' === $failed_replay->get_code() && $failed->get_order_id() === $failed_replay->get_order_id() && $item_count === count( $db->items ), 'same key cannot cross a generation because historical attempt uniqueness is preserved' );
$failed_renewal = $checkout->prepare( cart( 8 ), str_repeat( 'j', 32 ) );
check( 'reservation_failed' === $failed_renewal->get_code() && $failed->get_order_id() !== $failed_renewal->get_order_id() && $item_count + 1 === count( $db->items ) && 1 === $db->carts[8]['checkout_generation'], 'clean failed generation renews exactly once and creates a new snapshot' );

$db->cart_items[] = array( 'id' => 8, 'cart_id' => 9, 'post_id' => 13, 'quantity' => 1, 'content_version' => 0 ); $db->fail_status_update = true;
$mark_failure = $checkout->prepare( cart( 9 ), str_repeat( 'i', 32 ) );
check( 'database_error' === $mark_failure->get_code() && 'insufficient_stock' === $mark_failure->get_reservation_code(), 'failure-marking error is reported without losing reservation detail' ); $db->fail_status_update = false;

function failure_finalization_fixture( ?string $reservation_status = null, ?string $order_status = null, bool $partial = false, bool $fail_finalization_begin = false ): array {
	$db = new wpdb(); $GLOBALS['db'] = $db;
	$db->products[20] = array( 'post_type' => 'collection_item', 'title' => 'Race item A', 'meta' => array( '_cpt2collection_price_minor' => '600', '_cpt2collection_status' => 'available', '_cpt2collection_stock_quantity' => '10', '_cpt2collection_minimum_order_exempt' => '' ) );
	$db->cart_items[] = array( 'id' => 20, 'cart_id' => 20, 'post_id' => 20, 'quantity' => 1, 'content_version' => 0 );
	if ( $partial ) {
		$db->products[21] = array( 'post_type' => 'collection_item', 'title' => 'Race item B', 'meta' => array( '_cpt2collection_price_minor' => '700', '_cpt2collection_status' => 'available', '_cpt2collection_stock_quantity' => '10', '_cpt2collection_minimum_order_exempt' => '' ) );
		$db->cart_items[] = array( 'id' => 21, 'cart_id' => 20, 'post_id' => 21, 'quantity' => 1, 'content_version' => 0 );
	}
	$db->fail_reservation_insert = true;
	$db->inject_reservation_status_after_failure = $reservation_status;
	$db->inject_order_status_after_failure = $order_status;
	if ( $fail_finalization_begin ) { $db->fail_start_at = 3; }
	$result = service( $db )->prepare( cart( 20 ), str_repeat( 'r', 32 ) );
	$order = reset( $db->orders );
	return array( $db, $result, $order );
}

[ $race_db, $race_result, $race_order ] = failure_finalization_fixture( 'active' );
check( $race_result->is_success() && 'checkout_prepared' === $race_result->get_code() && 'reservation_already_active' === $race_result->get_reservation_code() && 'pending' === $race_order['status'] && 1 === count( $race_db->orders ) && 1 === count( $race_db->reservations ) && 0 === $race_db->carts[20]['checkout_generation'], 'stale reservation failure converges when another caller established the exact active set' );

[ $clean_db, $clean_result, $clean_order ] = failure_finalization_fixture();
check( 'reservation_failed' === $clean_result->get_code() && 'failed' === $clean_order['status'] && 0 === count( $clean_db->reservations ), 'clean reservation failure is finalized as failed inside the protected transaction' );

[ $partial_db, $partial_result, $partial_order ] = failure_finalization_fixture( 'active', null, true );
check( 'reservation_missing' === $partial_result->get_code() && 'pending' === $partial_order['status'] && 1 === count( $partial_db->reservations ), 'partial competing reservation state fails closed without marking the order failed' );

[ $converted_db, $converted_result, $converted_order ] = failure_finalization_fixture( 'converted' );
check( 'reservation_conflict' === $converted_result->get_code() && 'pending' === $converted_order['status'] && 'converted' === reset( $converted_db->reservations )['status'], 'converted reservation state is not treated as checkout success or clean failure' );

[ $terminal_db, $terminal_result, $terminal_order ] = failure_finalization_fixture( null, 'cancelled' );
check( 'checkout_attempt_conflict' === $terminal_result->get_code() && 'cancelled' === $terminal_order['status'], 'terminal order state is not overwritten by stale failure finalization' );

[ $begin_db, $begin_result, $begin_order ] = failure_finalization_fixture( null, null, false, true );
check( 'transaction_outcome_unknown' === $begin_result->get_code() && 'pending' === $begin_order['status'] && 3 === $begin_db->starts, 'uncertain finalization begin causes no follow-up lifecycle write' );

$GLOBALS['db'] = $db = $main_db;

$crash_key = str_repeat( 'j', 32 ); $crash_hash = hash( 'sha256', $crash_key ); $crash_order_id = ++$db->insert_id; $crash_item_id = ++$db->insert_id;
$db->orders[ $crash_order_id ] = array( 'id' => $crash_order_id, 'cart_id' => 10, 'cart_revision' => 0, 'checkout_generation' => 0, 'checkout_attempt_hash' => $crash_hash, 'status' => 'pending', 'currency' => 'EUR', 'subtotal_minor' => 1250, 'total_minor' => 1250, 'created_at' => '2026-08-06 12:00:00', 'updated_at' => '2026-08-06 12:00:00', 'paid_at' => null, 'cancelled_at' => null, 'expired_at' => null, 'failed_at' => null );
$db->items[ $crash_item_id ] = array( 'id' => $crash_item_id, 'order_id' => $crash_order_id, 'post_id' => 10, 'item_name' => 'Current title', 'unit_price_minor' => 1250, 'quantity' => 1, 'line_subtotal_minor' => 1250, 'line_total_minor' => 1250, 'currency' => 'EUR', 'created_at' => '2026-08-06 12:00:00' );
$crash_replay = $checkout->prepare( cart( 10 ), $crash_key );
check( $crash_replay->is_success() && $crash_order_id === $crash_replay->get_order_id() && 'reservation_acquired' === $crash_replay->get_reservation_code(), 'crash-gap replay resumes an existing complete pending order' );

$db->cart_items[] = array( 'id' => 20, 'cart_id' => 11, 'post_id' => 10, 'quantity' => 1, 'content_version' => 0 );
// A new revision captures the current locked generation; the old snapshot stays immutable.
$db = new wpdb(); $GLOBALS['db'] = $db; $db->products[10] = $product;
$db->cart_items[] = array( 'id' => 401, 'cart_id' => 40, 'post_id' => 10, 'quantity' => 2, 'content_version' => 3 );
$record = cart( 40 ); $checkout = service( $db );
$first = $checkout->prepare( $record, str_repeat( 'x', 32 ) ); $original = $db->items;
$item = reset( $original );
check( $first->is_success() && 401 === $item['source_cart_item_id'] && 3 === $item['source_cart_item_version'] && 2 === $item['quantity'], 'nonzero source version and quantity captured from locked state' );
// Simulate a committed cart mutation; production SQL is exercised in the MariaDB suite.
$db->cart_items[0]['quantity'] = 3; $db->cart_items[0]['content_version'] = 4; $db->carts[40]['content_revision'] = 1;
$before = $db->cart_items;
$next = $checkout->prepare( $record, str_repeat( 'y', 32 ) ); $item = end( $db->items );
check( $next->is_success() && $first->get_order_id() !== $next->get_order_id() && 401 === $item['source_cart_item_id'] && 4 === $item['source_cart_item_version'] && 3 === $item['quantity'] && 1 === $db->carts[40]['content_revision'] && $before === $db->cart_items, 'new checkout captures changed version from locked cart instead of stale caller record' );
foreach ( $original as $id => $old ) { check( $old === $db->items[$id], 'prior order snapshot remains immutable after mutation' ); }
foreach ( $db->items as $item ) { check( is_int( $item['source_cart_item_id'] ) && is_int( $item['source_cart_item_version'] ), 'checkout persists both source pair members' ); }
$db = new wpdb(); $GLOBALS['db'] = $db; $db->products[10] = $product; $db->products[11] = $product;
$db->cart_items = array( array( 'id' => 1, 'cart_id' => 1, 'post_id' => 10, 'quantity' => 1, 'content_version' => 0 ), array( 'id' => 2, 'cart_id' => 1, 'post_id' => 11, 'quantity' => 1, 'content_version' => 2 ) );
$db->fail_item_insert_at = 2; $before = $db->cart_items;
$failed = service( $db )->prepare( cart( 1 ), str_repeat( 'z', 32 ) );
check( 'order_persistence_failed' === $failed->get_code() && 2 === $db->item_inserts && array() === $db->orders && array() === $db->items && array() === $db->reservations && $before === $db->cart_items, 'second-item insert failure rolls back header and already-inserted source snapshot' );
$db->fail_item_insert_at = 0; $db->cart_items[0]['id'] = 0;
check( 'snapshot_invalid' === service( $db )->prepare( cart( 1 ), str_repeat( 'q', 32 ) )->get_code() && array() === $db->orders, 'invalid source identity cannot create a checkout snapshot' );

echo "All checkout orchestration and Phase 2A source-capture checks passed.\n";

// Durable deletion evidence is consulted under the cart lock before any order replay or creation.
$db = new wpdb(); $GLOBALS['db'] = $db; $db->products[10] = $product;
$db->cart_items[] = array( 'id' => 601, 'cart_id' => 60, 'post_id' => 10, 'quantity' => 1, 'content_version' => 0 );
$deleted_key = str_repeat( 'd', 32 );
$db->deletions[] = array( 'id' => 1, 'installation_id' => str_repeat( 'a', 32 ), 'epoch_id' => 1, 'epoch_identity' => str_repeat( 'b', 32 ), 'original_order_id' => 600, 'deleted_at' => '2026-08-06 12:00:00', 'cart_id' => 60, 'checkout_attempt_hash' => hash( 'sha256', $deleted_key ), 'cart_revision' => 0, 'checkout_generation' => 0, 'checkout_disposition' => 'revision', 'cart_revision_at_deletion' => null );
$checkout = service( $db );
check( 'checkout_order_deleted' === $checkout->prepare( cart( 60 ), $deleted_key )->get_code() && array() === $db->orders, 'deleted checkout attempt replay is durably blocked' );
check( 'checkout_order_deleted' === $checkout->prepare( cart( 60 ), str_repeat( 'e', 32 ) )->get_code() && array() === $db->orders, 'unchanged deleted checkout revision is durably blocked' );
$db->carts[60]['content_revision'] = 1;
$mutated = $checkout->prepare( CartRecord::from_row( (object) $db->carts[60] ), str_repeat( 'f', 32 ) );
check( $mutated->is_success() && 1 === count( $db->orders ), 'genuinely mutated cart may create a fresh checkout' );
check( 'checkout_order_deleted' === $checkout->prepare( CartRecord::from_row( (object) $db->carts[60] ), $deleted_key )->get_code() && 1 === count( $db->orders ), 'old deleted attempt remains blocked after cart mutation' );

$db->cart_items[] = array( 'id' => 621, 'cart_id' => 62, 'post_id' => 10, 'quantity' => 1, 'content_version' => 0 );
check( service( $db )->prepare( cart( 62 ), str_repeat( 'h', 32 ) )->is_success(), 'unrelated cart remains unaffected by deletion evidence' );
$db->cart_items[] = array( 'id' => 631, 'cart_id' => 63, 'post_id' => 10, 'quantity' => 1, 'content_version' => 0 );
$db->deletions[] = array( 'id' => 3, 'installation_id' => str_repeat( 'c', 32 ), 'epoch_id' => 1, 'epoch_identity' => str_repeat( 'b', 32 ), 'original_order_id' => 630, 'deleted_at' => '2026-08-06 12:00:00', 'cart_id' => 63, 'checkout_attempt_hash' => hash( 'sha256', str_repeat( 'i', 32 ) ), 'cart_revision' => 0, 'checkout_generation' => 0, 'checkout_disposition' => 'revision', 'cart_revision_at_deletion' => null );
check( 'database_error' === service( $db )->prepare( cart( 63 ), str_repeat( 'i', 32 ) )->get_code(), 'contradictory deletion ownership fails closed' );
$db->fail_deletion_read = true;
check( 'database_error' === service( $db )->prepare( CartRecord::from_row( (object) $db->carts[62] ), str_repeat( 'j', 32 ) )->get_code(), 'deletion registry read failure fails closed' );
$db->fail_deletion_read = false;

$db = new wpdb(); $GLOBALS['db']=$db; $db->products[1]=array('post_type'=>'collection_item','title'=>'Admission','meta'=>array('_cpt2collection_price_minor'=>'1250','_cpt2collection_status'=>'available','_cpt2collection_stock_quantity'=>'10','_cpt2collection_minimum_order_exempt'=>'')); $db->cart_items[]=array('id'=>1,'cart_id'=>1,'post_id'=>1,'quantity'=>1,'content_version'=>0); $db->fail_admission_at=2;
$result=service($db)->prepare(cart(1),str_repeat('j',32)); check('database_error'===$result->get_code() && 'pending'===reset($db->orders)['status'] && array()===$db->reservations && 2===$db->starts,'reservation admission failure never terminalizes an order or opens finalization');
