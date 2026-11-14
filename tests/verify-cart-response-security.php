<?php
/**
 * Isolated CLI verification for the Milestone 5 response and guest security layers.
 *
 * Run from the plugin root with: php tests/verify-cart-response-security.php
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

require_once __DIR__ . '/support/commerce-fixtures.php';
class wpdb implements \RodolfoRizzo\Cpt2Collection\CommerceConnection {
	use TestCommerceConnection;
	public string $prefix = 'wp_';
	public array $items = array();
	public ?object $cart = null;
	public int $writes = 0;
	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) { $query = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'", $query, 1 ); }
		return $query;
	}
	public function get_row( string $query ): ?object { return str_contains( $query, 'wp_c2c_carts' ) ? $this->cart : null; }
	public function get_results( string $query ): array { if (null !== ($foundation = $this->foundation_results($query))) { return $foundation; } return array_map( static fn( array $row ): object => (object) $row, $this->items ); }
	public function update( string $table, array $data, array $where, array $formats, array $where_formats ): int|false { $this->writes++; return 1; }
}
final class WP_Post_Type { public string $name = 'collection_item'; public bool $_builtin = false; public bool $show_ui = true; public bool $public = true; }
class WP_REST_Request {
	private array $headers;
	public function __construct( array $headers ) { $this->headers = array_change_key_case( $headers ); }
	public function get_header( string $name ): string { return (string) ( $this->headers[ strtolower( $name ) ] ?? '' ); }
}

$GLOBALS['options'] = array( 'cpt2collection_post_type' => 'collection_item', 'cpt2collection_minimum_order_quantity' => 5 );
$GLOBALS['posts'] = array();
function __( string $message, string $domain ): string { return $message; }
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['options'][ $name ] ?? $default; }
function get_post_types( array $args, string $output ): array { return array( 'collection_item' => new WP_Post_Type() ); }
function apply_filters( string $name, mixed $value ): mixed { return $value; }
function get_post( int $id ): ?object { return isset( $GLOBALS['posts'][ $id ] ) ? (object) array( 'ID' => $id ) : null; }
function get_post_type( int $id ): string|false { return $GLOBALS['posts'][ $id ]['type'] ?? false; }
function get_post_meta( int $id, string $key, bool $single ): mixed { return $GLOBALS['posts'][ $id ]['meta'][ $key ] ?? ''; }
function get_the_title( int $id ): string { return $GLOBALS['posts'][ $id ]['title'] ?? ''; }
function get_permalink( int $id ): string { return 'https://example.test/item/' . $id; }
function get_post_thumbnail_id( int $id ): int { return 2 === $id ? 102 : 0; }
function wp_get_attachment_image_src( int $id, string $size ): array|false { return 102 === $id && 'medium' === $size ? array( 'https://example.test/image.jpg', 300, 200 ) : false; }
function home_url( string $path = '' ): string { return 'https://example.test' . $path; }
function wp_parse_url( string $url ): array|false { return parse_url( $url ); }
function wp_salt( string $scheme ): string { return 'test-secret-salt'; }
function sanitize_text_field( string $value ): string { return $value; }
function wp_unslash( string $value ): string { return $value; }
function current_time( string $type, bool $gmt ): int { return 1700000000; }

foreach ( array( 'meta-keys', 'catalog', 'post-types', 'cart-token', 'cart-cookie', 'cart-record', 'cart-item', 'table-names', 'cart-repository', 'utc-clock', 'cart-activity', 'commerce-settings', 'commerce-rule-result', 'commerce-rules', 'cart-context', 'cart-response-builder', 'guest-csrf' ) as $class ) {
	require_once dirname( __DIR__ ) . '/includes/class-' . $class . '.php';
}

use RodolfoRizzo\Cpt2Collection\CartActivity;
use RodolfoRizzo\Cpt2Collection\CartContext;
use RodolfoRizzo\Cpt2Collection\CartCookie;
use RodolfoRizzo\Cpt2Collection\CartRepository;
use RodolfoRizzo\Cpt2Collection\CartResponseBuilder;
use RodolfoRizzo\Cpt2Collection\CartToken;
use RodolfoRizzo\Cpt2Collection\CommerceRules;
use RodolfoRizzo\Cpt2Collection\GuestCsrf;
use RodolfoRizzo\Cpt2Collection\MetaKeys;
use RodolfoRizzo\Cpt2Collection\PostTypes;
use RodolfoRizzo\Cpt2Collection\TableNames;
use RodolfoRizzo\Cpt2Collection\UtcClock;

function check( bool $condition, string $label ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } echo "PASS: {$label}\n"; }
function product( int $id, string $status, string $price, string $stock, bool $exempt = false ): void {
	$GLOBALS['posts'][ $id ] = array( 'type' => 'collection_item', 'title' => 'Item ' . $id, 'meta' => array( MetaKeys::STATUS => $status, MetaKeys::PRICE_MINOR => $price, MetaKeys::STOCK_QUANTITY => $stock, MetaKeys::MINIMUM_ORDER_EXEMPT => $exempt ? '1' : '' ) );
}

$wpdb = new wpdb();
$repo = new CartRepository( $wpdb, new TableNames( $wpdb ) );
$clock = new UtcClock();
$tokens = new CartToken();
$context = new CartContext( $repo, $tokens, new CartCookie( $tokens ), $clock, new CartActivity( $repo, $clock ) );
$builder = new CartResponseBuilder( $repo, new CommerceRules( new PostTypes() ) );

unset( $_COOKIE[ CartCookie::COOKIE_NAME ] );
$empty = $builder->build( $context->get_existing_cart() );
check( array() === $empty['items'] && 0 === $empty['total_minor'] && 'empty_cart' === $empty['checkout']['code'], 'GET model without cookie is a virtual empty cart' );
check( 0 === $wpdb->writes, 'read-only existing-cart lookup performs no writes' );

$raw = str_repeat( 'a', 64 );
$_COOKIE[ CartCookie::COOKIE_NAME ] = $raw;
$wpdb->cart = (object) array( 'epoch_id' => 1, 'id' => 7, 'token_hash' => hash( 'sha256', $raw ), 'content_revision' => 0, 'checkout_generation' => 0, 'created_at' => '2023-01-01 00:00:00', 'updated_at' => '2023-01-01 00:00:00', 'last_activity_at' => '2023-01-01 00:00:00', 'expires_at' => '2030-01-01 00:00:00' );
$wpdb->items = array( array( 'id' => 1, 'cart_id' => 7, 'post_id' => 2, 'quantity' => 2 ), array( 'id' => 2, 'cart_id' => 7, 'post_id' => 3, 'quantity' => 1 ) );
product( 2, 'available', '1250', '5' );
product( 3, 'available', '500', '1', true );
$GLOBALS['posts'][2]['title'] = 'Mortal Kombat &#8211; Il mito';
$cart = $context->get_existing_cart();
$data = $builder->build( $cart );
check( 2 === $data['unique_item_count'] && 3 === $data['total_quantity'], 'unique and total quantities are distinct and correct' );
check( 'Mortal Kombat – Il mito' === $data['items'][0]['title'], 'cart title projection decodes an en-dash entity for text rendering' );
check( 1250 === $data['items'][0]['price_minor'] && 2500 === $data['items'][0]['subtotal_minor'] && 3000 === $data['total_minor'], 'minor-unit prices and subtotals are correct' );
check( true === $data['items'][0]['thumbnail']['available'] && 300 === $data['items'][0]['thumbnail']['width'] && 'Product image' === $data['items'][0]['thumbnail']['alt'], 'response exposes structured optimized medium image data with accessible fallback alt' );
check( false === $data['items'][1]['thumbnail']['available'] && '' === $data['items'][1]['thumbnail']['url'], 'missing thumbnails have an explicit response state and no invalid URL' );
check( 0 === $data['minimum_order_remaining'] && 'minimum_order_exempt' === $data['checkout']['code'], 'minimum-order exemption is represented by CommerceRules' );
check( ! str_contains( json_encode( $data, JSON_THROW_ON_ERROR ), $raw ) && ! array_key_exists( 'cart_id', $data ), 'response exposes neither cart token nor cart id' );

product( 2, 'sold', '1250', '5' );
product( 3, 'available', '500', '0', true );
$data = $builder->build( $cart );
check( 2 === count( $data['items'] ) && ! $data['items'][0]['commercial']['valid'] && 'not_available' === $data['items'][0]['commercial']['code'], 'sold item stays visible and invalid' );
check( ! $data['items'][1]['commercial']['valid'] && 'out_of_stock' === $data['items'][1]['commercial']['code'], 'stock-invalid item stays visible and invalid' );
check( true === $data['has_invalid_items'] && null === $data['total_minor'] && ! $data['checkout']['allowed'], 'invalid lines make checkout false and total indeterminate' );
check( 0 === $wpdb->writes, 'response revalidation does not mutate catalog or cart' );

$csrf = new GuestCsrf();
$valid = new WP_REST_Request( array( 'content-type' => 'application/json; charset=UTF-8', 'origin' => 'https://example.test', GuestCsrf::HEADER_NAME => $csrf->create_token() ) );
$missing = new WP_REST_Request( array( 'content-type' => 'application/json', 'origin' => 'https://example.test' ) );
$foreign = new WP_REST_Request( array( 'content-type' => 'application/json', 'origin' => 'https://evil.test', GuestCsrf::HEADER_NAME => $csrf->create_token() ) );
$form = new WP_REST_Request( array( 'content-type' => 'application/x-www-form-urlencoded', 'referer' => 'https://example.test/cart', GuestCsrf::HEADER_NAME => $csrf->create_token() ) );
check( $csrf->verify_request( $valid ), 'same-origin JSON mutation with guest token is accepted' );
check( ! $csrf->verify_request( $missing ), 'mutation without guest CSRF token is rejected' );
check( ! $csrf->verify_request( $foreign ), 'foreign Origin is rejected' );
check( ! $csrf->verify_request( $form ), 'non-JSON mutation is rejected' );

echo "All Cart REST response and guest security checks passed.\n";
