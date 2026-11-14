<?php
/**
 * Isolated CLI verification for CommerceRules.
 *
 * Run from the plugin root with: php tests/verify-commerce-rules.php
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

final class WP_Post_Type {
	public string $name;
	public bool $_builtin = false;
	public bool $show_ui = true;
	public bool $public = true;

	public function __construct( string $name ) {
		$this->name = $name;
	}
}

$GLOBALS['c2c_test_options'] = array(
	'cpt2collection_post_type'              => 'collection_item',
	'cpt2collection_minimum_order_quantity' => 20,
);
$GLOBALS['c2c_test_posts']   = array();

function __( string $message, string $domain ): string { return $message; }
function apply_filters( string $hook_name, mixed $value ): mixed { return $value; }
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['c2c_test_options'][ $name ] ?? $default; }
function get_post_types( array $args, string $output ): array { return array( 'collection_item' => new WP_Post_Type( 'collection_item' ) ); }
function get_post( int $post_id ): ?object { return isset( $GLOBALS['c2c_test_posts'][ $post_id ] ) ? (object) array( 'ID' => $post_id ) : null; }
function get_post_type( int $post_id ): string|false { return $GLOBALS['c2c_test_posts'][ $post_id ]['post_type'] ?? false; }
function get_post_meta( int $post_id, string $key, bool $single ): mixed { return $GLOBALS['c2c_test_posts'][ $post_id ]['meta'][ $key ] ?? ''; }

require_once dirname( __DIR__ ) . '/includes/class-meta-keys.php';
require_once dirname( __DIR__ ) . '/includes/class-catalog.php';
require_once dirname( __DIR__ ) . '/includes/class-post-types.php';
require_once dirname( __DIR__ ) . '/includes/class-cart-item.php';
require_once dirname( __DIR__ ) . '/includes/class-commerce-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-commerce-rule-result.php';
require_once dirname( __DIR__ ) . '/includes/class-commerce-rules.php';

use RodolfoRizzo\Cpt2Collection\CartItem;
use RodolfoRizzo\Cpt2Collection\CommerceRules;
use RodolfoRizzo\Cpt2Collection\CommerceSettings;
use RodolfoRizzo\Cpt2Collection\MetaKeys;
use RodolfoRizzo\Cpt2Collection\PostTypes;

function set_test_post( int $post_id, string $post_type = 'collection_item', string $status = 'available', mixed $price = '1000', mixed $stock = '20', bool $exempt = false ): void {
	$GLOBALS['c2c_test_posts'][ $post_id ] = array(
		'post_type' => $post_type,
		'meta'      => array(
			MetaKeys::STATUS                 => $status,
			MetaKeys::PRICE_MINOR            => $price,
			MetaKeys::STOCK_QUANTITY         => $stock,
			MetaKeys::MINIMUM_ORDER_EXEMPT   => $exempt ? '1' : '',
		),
	);
}

function expect_result( string $label, object $result, bool $allowed, string $code ): void {
	if ( $allowed !== $result->is_allowed() || $code !== $result->get_code() ) {
		throw new RuntimeException( sprintf( '%s: expected %s/%s, got %s/%s', $label, $allowed ? 'allowed' : 'denied', $code, $result->is_allowed() ? 'allowed' : 'denied', $result->get_code() ) );
	}

	echo "PASS: {$label}\n";
}

$rules = new CommerceRules( new PostTypes() );

if ( 1 !== CommerceSettings::normalize_minimum_order_quantity( '' ) || 1 !== CommerceSettings::normalize_minimum_order_quantity( 0 ) || 1 !== CommerceSettings::normalize_minimum_order_quantity( -2 ) || 20 !== CommerceSettings::normalize_minimum_order_quantity( '20' ) ) {
	throw new RuntimeException( 'Minimum-order setting normalization failed.' );
}
echo "PASS: minimum-order setting normalization\n";

expect_result( 'post_id zero', $rules->can_add_item( 0, 1 ), false, 'invalid_post' );
expect_result( 'missing post', $rules->can_add_item( 999, 1 ), false, 'invalid_post' );
set_test_post( 1, 'post' );
expect_result( 'wrong post type', $rules->can_add_item( 1, 1 ), false, 'wrong_post_type' );
set_test_post( 1 );
expect_result( 'available item', $rules->can_add_item( 1, 1 ), true, 'allowed' );
expect_result( 'invalid quantity', $rules->can_set_quantity( 1, 0 ), false, 'invalid_quantity' );

foreach ( array( 'reserved', 'sold', 'not_for_sale' ) as $status ) {
	set_test_post( 1, 'collection_item', $status );
	expect_result( $status . ' status', $rules->can_add_item( 1, 1 ), false, 'not_available' );
}

set_test_post( 1, 'collection_item', 'available', '0' );
expect_result( 'zero price', $rules->can_add_item( 1, 1 ), false, 'invalid_price' );
set_test_post( 1, 'collection_item', 'available', '1.5' );
expect_result( 'malformed price', $rules->can_add_item( 1, 1 ), false, 'invalid_price' );
set_test_post( 1, 'collection_item', 'available', '1000', '0' );
expect_result( 'out of stock', $rules->can_add_item( 1, 1 ), false, 'out_of_stock' );
set_test_post( 1, 'collection_item', 'available', '1000', '5' );
expect_result( 'quantity equals stock', $rules->can_set_quantity( 1, 5 ), true, 'allowed' );
expect_result( 'quantity exceeds stock', $rules->can_set_quantity( 1, 6 ), false, 'insufficient_stock' );

set_test_post( 1, 'collection_item', 'available', '1000', '20' );
set_test_post( 2, 'collection_item', 'available', '1000', '20', true );
expect_result( 'empty cart', $rules->can_checkout( array() ), false, 'empty_cart' );
expect_result( 'nineteen normal items', $rules->can_checkout( array( new CartItem( 1, 19 ) ) ), false, 'minimum_order_not_met' );
expect_result( 'twenty normal items', $rules->can_checkout( array( new CartItem( 1, 20 ) ) ), true, 'allowed' );
expect_result( 'one exempt item', $rules->can_checkout( array( new CartItem( 2, 1 ) ) ), true, 'minimum_order_exempt' );
expect_result( 'exempt plus normal items', $rules->can_checkout( array( new CartItem( 2, 1 ), new CartItem( 1, 3 ) ) ), true, 'minimum_order_exempt' );
expect_result( 'exempt removed', $rules->can_checkout( array( new CartItem( 1, 3 ) ) ), false, 'minimum_order_not_met' );

set_test_post( 1, 'collection_item', 'sold', '1000', '20' );
expect_result( 'status revalidation', $rules->can_checkout( array( new CartItem( 1, 20 ) ) ), false, 'not_available' );
set_test_post( 1, 'collection_item', 'available', '1000', '2' );
expect_result( 'stock revalidation', $rules->can_checkout( array( new CartItem( 1, 5 ) ) ), false, 'insufficient_stock' );

echo "All CommerceRules checks passed.\n";
