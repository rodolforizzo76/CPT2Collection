<?php
/**
 * Isolated CLI verification for CartService and CommerceRules integration.
 *
 * Run from the plugin root with: php tests/verify-cart-rules-integration.php
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

require_once __DIR__ . '/support/commerce-fixtures.php';
class wpdb implements \RodolfoRizzo\Cpt2Collection\CommerceConnection {
	use TestCommerceConnection;
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public array $carts = array();
	public array $items = array();
	public int $touches = 0;
	public string $last_error = '';
	private ?array $backup = null;
	public bool $fail_cart_update = false;
	public bool $fail_commit = false;
	public bool $fail_rollback = false;

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$value = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query = preg_replace( '/%[ds]/', $value, $query, 1 );
		}

		return $query;
	}

	public function query( string $query ): int|false {
		if ( 'START TRANSACTION' === $query ) { $this->backup = array( $this->carts, $this->items, $this->touches ); return 0; }
		if ( 'COMMIT' === $query ) { if ( $this->fail_commit ) return false; $this->backup = null; return 0; }
		if ( 'ROLLBACK' === $query ) { if ( $this->fail_rollback ) return false; if ( null !== $this->backup ) { [ $this->carts, $this->items, $this->touches ] = $this->backup; } $this->backup = null; return 0; }
		if ( str_starts_with( $query, 'UPDATE wp_c2c_cart_items SET quantity' ) ) {
			if ( ! preg_match( "/SET quantity = (\d+), content_version = content_version \+ 1, updated_at = '[^']+' WHERE cart_id = (\d+) AND post_id = (\d+) AND quantity <> \d+ AND content_version < \d+/", $query, $m ) ) { throw new RuntimeException( 'Unexpected quantity SQL' ); }
			$key = $m[2] . ':' . $m[3]; $quantity = (int) $m[1];
			if ( ! isset( $this->items[$key] ) || $this->items[$key]['quantity'] === $quantity || $this->items[$key]['content_version'] >= PHP_INT_MAX ) { return 0; }
			$this->items[$key]['quantity'] = $quantity;
			++$this->items[$key]['content_version'];
			return 1;
		}
		if ( ! str_starts_with( $query, 'INSERT INTO wp_c2c_cart_items' ) || 1 !== preg_match( '/VALUES \((\d+), (\d+), (\d+),/', $query, $matches ) ) {
			return false;
		}

		$cart_id  = (int) $matches[1];
		$post_id  = (int) $matches[2];
		$quantity = (int) $matches[3];
		$key      = $cart_id . ':' . $post_id;

		if ( isset( $this->items[ $key ] ) ) {
			if ( $this->items[ $key ]['content_version'] >= PHP_INT_MAX ) { return 0; }
			$this->items[ $key ]['quantity'] += $quantity;
			++$this->items[ $key ]['content_version'];
		} else {
			$this->insert_id++;
			$this->items[ $key ] = array( 'id' => $this->insert_id, 'cart_id' => $cart_id, 'post_id' => $post_id, 'quantity' => $quantity, 'content_version' => 0 );
		}

		return 1;
	}

	public function get_row( string $query ): ?object {
		if ( str_contains( $query, 'wp_c2c_cart_items' ) && 1 === preg_match( '/cart_id = (\d+) AND post_id = (\d+)/', $query, $matches ) ) {
			$item = $this->items[ $matches[1] . ':' . $matches[2] ] ?? null;

			return null === $item ? null : (object) $item;
		}

		if ( str_contains( $query, 'wp_c2c_carts' ) && 1 === preg_match( '/id = (\d+)/', $query, $matches ) ) {
			$cart = $this->carts[ (int) $matches[1] ] ?? null;

			return null === $cart ? null : (object) $cart;
		}

		return null;
	}

	public function get_results( string $query ): array { if (null !== ($foundation = $this->foundation_results($query))) { return $foundation; }
		if ( str_contains( $query, 'information_schema.TABLES' ) ) { preg_match_all( "/'([^']+)'/", $query, $names ); return array_map( fn($name)=>(object)['TABLE_NAME'=>$name,'ENGINE'=>'InnoDB'], $names[1]); }
		preg_match( '/cart_id = (\d+)/', $query, $matches );
		$cart_id = (int) ( $matches[1] ?? 0 );

		return array_values( array_map( static fn( array $item ): object => (object) $item, array_filter( $this->items, static fn( array $item ): bool => $item['cart_id'] === $cart_id ) ) );
	}

	public function update( string $table, array $data, array $where, array $formats, array $where_formats ): int|false {
			if ( 'wp_c2c_carts' === $table ) {
				if ( $this->fail_cart_update ) return false;
			if ( ! isset( $this->carts[ $where['id'] ] ) ) {
				return false;
			}
				if ( isset( $where['content_revision'] ) && $this->carts[ $where['id'] ]['content_revision'] !== $where['content_revision'] ) return 0;
				$this->carts[ $where['id'] ] = array_merge( $this->carts[ $where['id'] ], $data );
			$this->touches++;

			return 1;
		}

		$key = $where['cart_id'] . ':' . $where['post_id'];
		if ( ! isset( $this->items[ $key ] ) ) {
			return 0;
		}
		$this->items[ $key ] = array_merge( $this->items[ $key ], $data );

		return 1;
	}

	public function delete( string $table, array $where, array $formats ): int|false {
		if ( isset( $where['post_id'] ) ) {
			$key = $where['cart_id'] . ':' . $where['post_id'];
			if ( ! isset( $this->items[ $key ] ) ) {
				return 0;
			}
			unset( $this->items[ $key ] );

			return 1;
		}

		$count = 0;
		foreach ( $this->items as $key => $item ) {
			if ( $item['cart_id'] === $where['cart_id'] ) {
				unset( $this->items[ $key ] );
				$count++;
			}
		}

		return $count;
	}
}

final class WP_Post_Type {
	public string $name;
	public bool $_builtin = false;
	public bool $show_ui = true;
	public bool $public = true;
	public function __construct( string $name ) { $this->name = $name; }
}

$GLOBALS['c2c_test_options'] = array( 'cpt2collection_post_type' => 'collection_item' );
$GLOBALS['c2c_test_posts']   = array();

function __( string $message, string $domain ): string { return $message; }
function apply_filters( string $hook_name, mixed $value ): mixed { return $value; }
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['c2c_test_options'][ $name ] ?? $default; }
function get_post_types( array $args, string $output ): array { return array( 'collection_item' => new WP_Post_Type( 'collection_item' ) ); }
function get_post( int $post_id ): ?object { return isset( $GLOBALS['c2c_test_posts'][ $post_id ] ) ? (object) array( 'ID' => $post_id ) : null; }
function get_post_type( int $post_id ): string|false { return $GLOBALS['c2c_test_posts'][ $post_id ]['post_type'] ?? false; }
function get_post_meta( int $post_id, string $key, bool $single ): mixed { return $GLOBALS['c2c_test_posts'][ $post_id ]['meta'][ $key ] ?? ''; }

foreach ( array( 'meta-keys', 'catalog', 'post-types', 'cart-item', 'cart-record', 'cart-cookie', 'table-names', 'cart-repository', 'utc-clock', 'cart-activity', 'commerce-settings', 'commerce-rule-result', 'commerce-rules', 'cart-operation-result', 'transaction-manager', 'cart-service' ) as $class ) {
	require_once dirname( __DIR__ ) . '/includes/class-' . $class . '.php';
}

use RodolfoRizzo\Cpt2Collection\CartActivity;
use RodolfoRizzo\Cpt2Collection\CartRecord;
use RodolfoRizzo\Cpt2Collection\CartRepository;
use RodolfoRizzo\Cpt2Collection\CartService;
use RodolfoRizzo\Cpt2Collection\CommerceRules;
use RodolfoRizzo\Cpt2Collection\MetaKeys;
use RodolfoRizzo\Cpt2Collection\PostTypes;
use RodolfoRizzo\Cpt2Collection\TableNames;
use RodolfoRizzo\Cpt2Collection\UtcClock;

function set_test_post( int $post_id, string $type = 'collection_item', string $status = 'available', string $price = '1000', string $stock = '10' ): void {
	$GLOBALS['c2c_test_posts'][ $post_id ] = array( 'post_type' => $type, 'meta' => array( MetaKeys::STATUS => $status, MetaKeys::PRICE_MINOR => $price, MetaKeys::STOCK_QUANTITY => $stock ) );
}

function expect_operation( string $label, object $result, bool $success, string $code, ?int $quantity = null, ?string $message = null ): void {
	if ( $result->is_success() !== $success || $result->get_code() !== $code || ( null !== $quantity && $result->get_quantity() !== $quantity ) || ( null !== $message && $result->get_message() !== $message ) ) {
		throw new RuntimeException( $label . ': unexpected result ' . json_encode(array($result->is_success(),$result->get_code(),$result->get_quantity(),$result->get_message())) );
	}
	echo "PASS: {$label}\n";
}

$wpdb = new wpdb();
$now  = '2020-01-01 00:00:00';
$wpdb->carts[1] = array( 'epoch_id' => 1, 'id' => 1, 'token_hash' => 'test', 'content_revision' => 0, 'checkout_generation' => 7, 'created_at' => $now, 'updated_at' => $now, 'last_activity_at' => $now, 'expires_at' => '2030-01-01 00:00:00' );
$repository = new CartRepository( $wpdb, new TableNames( $wpdb ) );
	$service    = new CartService( CartRecord::from_row( (object) $wpdb->carts[1] ), $repository, new CartActivity( $repository, new UtcClock() ), new CommerceRules( new PostTypes() ), new RodolfoRizzo\Cpt2Collection\TransactionManager( $wpdb, new TableNames( $wpdb ) ) );

set_test_post( 1 );
expect_operation( 'empty cart add 2', $service->add( 1, 2 ), true, 'item_added', 2 );
if ( 0 !== $repository->find_item( 1, 1 )->content_version() ) { throw new RuntimeException( 'Insert version' ); }
expect_operation( 'set existing quantity 3', $service->set_quantity( 1, 3 ), true, 'quantity_updated', 3 );
if ( 1 !== $repository->find_item( 1, 1 )->content_version() ) { throw new RuntimeException( 'Set version' ); }
expect_operation( 'existing add validates final quantity 5', $service->add( 1, 2 ), true, 'item_added', 5 );
if ( 2 !== $repository->find_item( 1, 1 )->content_version() ) { throw new RuntimeException( 'Add version' ); }
expect_operation( 'restore quantity 3', $service->set_quantity( 1, 3 ), true, 'quantity_updated', 3 );

set_test_post( 1, 'collection_item', 'available', '1000', '4' );
$touches = $wpdb->touches;
expect_operation( 'final quantity exceeds stock', $service->add( 1, 2 ), false, 'insufficient_stock', 5, 'The requested quantity exceeds the available stock.' );
if ( 3 !== $repository->find_item( 1, 1 )->content_version() || 3 !== $repository->find_item( 1, 1 )?->quantity() || $touches !== $wpdb->touches ) { throw new RuntimeException( 'Denied add mutated or touched the cart.' ); }
echo "PASS: denied add leaves persistence and activity unchanged\n";

foreach ( array( array( 2, 'collection_item', 'sold', '1000', '10', 'not_available' ), array( 3, 'collection_item', 'available', '1000', '0', 'out_of_stock' ), array( 4, 'collection_item', 'available', '0', '10', 'invalid_price' ), array( 5, 'post', 'available', '1000', '10', 'wrong_post_type' ) ) as $case ) {
	set_test_post( $case[0], $case[1], $case[2], $case[3], $case[4] );
	expect_operation( 'add denied with ' . $case[5], $service->add( $case[0] ), false, $case[5] );
}
expect_operation( 'add rejects zero quantity', $service->add( 1, 0 ), false, 'invalid_quantity', 0 );

set_test_post( 1, 'collection_item', 'available', '1000', '10' );
expect_operation( 'set quantity within stock', $service->set_quantity( 1, 4 ), true, 'quantity_updated', 4 );
$revision = $service->cart()->content_revision();
expect_operation( 'identical quantity is a no-op', $service->set_quantity( 1, 4 ), true, 'quantity_unchanged', 4 );
if ( $repository->find_item( 1, 1 )->content_version() !== 4 || $revision !== $service->cart()->content_revision() || 0 !== $service->cart()->checkout_generation() ) { throw new RuntimeException( 'No-op quantity changed checkout identity.' ); }
$touches = $wpdb->touches;
expect_operation( 'set quantity exceeds stock', $service->set_quantity( 1, 11 ), false, 'insufficient_stock', 11 );
if ( 4 !== $repository->find_item( 1, 1 )->content_version() || 4 !== $repository->find_item( 1, 1 )?->quantity() || $touches !== $wpdb->touches ) { throw new RuntimeException( 'Denied set_quantity mutated or touched the cart.' ); }
set_test_post( 1, 'collection_item', 'sold' );
expect_operation( 'sold item set denied', $service->set_quantity( 1, 2 ), false, 'not_available' );
expect_operation( 'missing item set denied operationally', $service->set_quantity( 99, 2 ), false, 'item_not_found' );
expect_operation( 'set rejects zero quantity', $service->set_quantity( 1, 0 ), false, 'invalid_quantity', 0 );

$removed_id = $repository->find_item( 1, 1 )->id();
expect_operation( 'sold item can be removed', $service->remove( 1 ), true, 'removed' );
if ( null !== $repository->find_item( 1, 1 ) ) { throw new RuntimeException( 'Remove retained identity' ); }
set_test_post( 3, 'collection_item', 'available', '1000', '10' );
expect_operation( 'add item before it loses stock', $service->add( 3 ), true, 'item_added' );
set_test_post( 3, 'collection_item', 'available', '1000', '0' );
expect_operation( 'out-of-stock item can be removed', $service->remove( 3 ), true, 'removed' );
set_test_post( 6 );
expect_operation( 'add item before clear', $service->add( 6 ), true, 'item_added' );
set_test_post( 6, 'collection_item', 'sold' );
expect_operation( 'commercially invalid cart can be cleared', $service->clear(), true, 'cleared' );
$revision = $service->cart()->content_revision();
expect_operation( 'empty cart clear is a no-op', $service->clear(), true, 'cart_already_empty' );
if ( 10 !== $revision || $revision !== $service->cart()->content_revision() || 0 !== $service->cart()->checkout_generation() ) { throw new RuntimeException( 'Cart revision/generation mutation semantics failed.' ); }
echo "PASS: content changes increment once, while no-ops preserve identity\n";

$failure_db = new wpdb();
set_test_post( 1 );
$failure_db->carts[2] = array( 'epoch_id'=>1, 'id'=>2, 'token_hash'=>'failure', 'content_revision'=>0, 'checkout_generation'=>8, 'created_at'=>$now, 'updated_at'=>$now, 'last_activity_at'=>$now, 'expires_at'=>'2030-01-01 00:00:00' );
$failure_repo = new CartRepository( $failure_db, new TableNames( $failure_db ) );
$failure_service = new CartService( CartRecord::from_row( (object) $failure_db->carts[2] ), $failure_repo, new CartActivity( $failure_repo, new UtcClock() ), new CommerceRules( new PostTypes() ), new RodolfoRizzo\Cpt2Collection\TransactionManager( $failure_db, new TableNames( $failure_db ) ) );
$failure_db->fail_cart_update = true;
expect_operation( 'revision write failure is reported', $failure_service->add( 1 ), false, 'repository_error' );
if ( array() !== $failure_db->items || 0 !== $failure_db->carts[2]['content_revision'] || 8 !== $failure_db->carts[2]['checkout_generation'] ) throw new RuntimeException( 'Revision failure left a partial item write.' );
echo "PASS: revision failure rolls back the content write\n";

$uncertain_db = new wpdb();
$uncertain_db->carts[3] = array( 'epoch_id'=>1, 'id'=>3, 'token_hash'=>'uncertain', 'content_revision'=>0, 'checkout_generation'=>0, 'created_at'=>$now, 'updated_at'=>$now, 'last_activity_at'=>$now, 'expires_at'=>'2030-01-01 00:00:00' );
$uncertain_repo = new CartRepository( $uncertain_db, new TableNames( $uncertain_db ) );
$uncertain_service = new CartService( CartRecord::from_row( (object) $uncertain_db->carts[3] ), $uncertain_repo, new CartActivity( $uncertain_repo, new UtcClock() ), new CommerceRules( new PostTypes() ), new RodolfoRizzo\Cpt2Collection\TransactionManager( $uncertain_db, new TableNames( $uncertain_db ) ) );
$uncertain_db->fail_commit = true;
expect_operation( 'commit uncertainty is surfaced', $uncertain_service->add( 1 ), false, 'transaction_outcome_unknown' );
echo "PASS: uncertain commit stops mutation orchestration\n";


// Direct primitives use an admitted transaction and retain the same generation rules.
$direct_tx = $repository->transactions(); if (!$direct_tx->begin_commerce_shared()) throw new RuntimeException("Fixture admission");
$readded = $repository->add_item_quantity( 1, 1, 1 );
if ( $readded->id() === $removed_id || 0 !== $readded->content_version() ) { throw new RuntimeException( 'Re-add identity/version' ); }
foreach ( array( 1, 2, 3 ) as $version ) {
	if ( $version !== $repository->add_item_quantity( 1, 1, 1 )->content_version() ) { throw new RuntimeException( 'Repeated add version' ); }
}
if ( 4 !== $repository->update_item_quantity( 1, 1, 7 )->content_version() || 4 !== $repository->update_item_quantity( 1, 1, 7 )->content_version() || 0 !== $repository->update_item_quantity_locked( 1, 1, 7 ) ) { throw new RuntimeException( 'Direct set/no-op invariant' ); }
$direct_tx->commit();
$before = $wpdb->items;
$direct_tx->begin_commerce_shared();
$repository->update_item_quantity_locked( 1, 1, 8 );
$direct_tx->rollback();
if ( $wpdb->items !== $before ) { throw new RuntimeException( 'Rollback quantity/version' ); }
$wpdb->fail_cart_update = true;
expect_operation( 'existing add revision failure', $service->add( 1 ), false, 'repository_error' );
expect_operation( 'existing set revision failure', $service->set_quantity( 1, 8 ), false, 'repository_error' );
if ( $wpdb->items !== $before ) { throw new RuntimeException( 'Revision failure quantity/version' ); }
$wpdb->fail_cart_update = false;
$direct_tx->begin_commerce_shared();
$wpdb->items['1:1']['content_version'] = PHP_INT_MAX;
$before = $wpdb->items;
if ( false !== $repository->add_item_quantity( 1, 1, 1 ) || false !== $repository->update_item_quantity( 1, 1, 8 ) || 0 !== $repository->update_item_quantity_locked( 1, 1, 8 ) || PHP_INT_MAX !== $repository->update_item_quantity( 1, 1, 7 )->content_version() || $before !== $wpdb->items ) { throw new RuntimeException( 'Version exhaustion invariant' ); }
$repository->delete_items( 1 ); $direct_tx->commit();
if ( array() !== $wpdb->items ) { throw new RuntimeException( 'Clear retained rows' ); }
echo "PASS: direct mutations, re-add, rollback, revision failure and version exhaustion preserve invariants.\n";

echo "All CartService and CommerceRules integration checks passed.\n";
