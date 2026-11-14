<?php
/** Static and isolated checks for the Milestone 6 frontend. */
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );
$GLOBALS['frontend_test_posts'] = array( 41 => array( 'type' => 'page', 'status' => 'publish' ), 42 => array( 'type' => 'post', 'status' => 'publish' ), 43 => array( 'type' => 'page', 'status' => 'trash' ) );
function absint( mixed $value ): int { return abs( (int) $value ); }
function get_post_type( int $post_id ): string|false { return $GLOBALS['frontend_test_posts'][ $post_id ]['type'] ?? false; }
function get_post_status( int $post_id ): string|false { return $GLOBALS['frontend_test_posts'][ $post_id ]['status'] ?? false; }
function get_option( string $name, mixed $default = false ): mixed { return $default; }
function get_permalink( int $post_id ): string|false { return 41 === $post_id ? 'https://example.test/cart/' : false; }

require_once dirname( __DIR__ ) . '/includes/class-cart-page.php';

use RodolfoRizzo\Cpt2Collection\CartPage;

function check( bool $condition, string $label ): void {
	if ( ! $condition ) { throw new RuntimeException( $label ); }
	echo "PASS: {$label}\n";
}

$root       = dirname( __DIR__ );
$bootstrap  = file_get_contents( $root . '/cpt2collection.php' );
$plugin     = file_get_contents( $root . '/includes/class-plugin.php' );
$assets     = file_get_contents( $root . '/includes/frontend/class-assets.php' );
$javascript = file_get_contents( $root . '/assets/js/cart-frontend.js' );
$css        = file_get_contents( $root . '/assets/css/cart-frontend.css' );

check( 41 === CartPage::sanitize( '41' ), 'cart page sanitizer accepts an existing page' );
check( 0 === CartPage::sanitize( 42 ) && 0 === CartPage::sanitize( 43 ) && 0 === CartPage::sanitize( 'invalid' ), 'cart page sanitizer rejects posts, trashed pages, and invalid values' );

foreach ( array( 'add-to-cart', 'cart-count', 'mini-cart', 'cart' ) as $name ) {
	check( str_contains( (string) $bootstrap, "class-{$name}-shortcode.php" ), "{$name} shortcode has manual bootstrap" );
}
foreach ( array( 'cpt2collection_add_to_cart', 'cpt2collection_cart_count', 'cpt2collection_mini_cart', 'cpt2collection_cart' ) as $tag ) {
	$files = glob( $root . '/includes/frontend/class-*-shortcode.php' ) ?: array();
	$all   = implode( '', array_map( static fn( string $file ): string => (string) file_get_contents( $file ), $files ) );
	check( str_contains( $all, "add_shortcode( '{$tag}'" ), "{$tag} is registered" );
}
check( str_contains( (string) $plugin, 'new CartCountShortcode' ) && str_contains( (string) $plugin, 'new MiniCartShortcode' ), 'cart count and mini-cart remain separate components' );
check( str_contains( (string) $assets, "'cartUrl'") && ! str_contains( (string) $assets, "'cartToken'") && ! str_contains( (string) $assets, "'cartId'"), 'public JS configuration contains no cart identity' );
check( 1 === substr_count( (string) $javascript, "method: 'GET'" ) && str_contains( (string) $javascript, 'if (!state.loading)'), 'shared store performs one cached initial GET' );
check( str_contains( (string) $javascript, "response.headers.get(config.csrfHeader)") && str_contains( (string) $javascript, "[config.csrfHeader]: state.csrf"), 'guest CSRF token flows from response header to mutations' );
check( str_contains( (string) $javascript, "credentials: 'same-origin'") && str_contains( (string) $javascript, "'Content-Type': 'application/json'"), 'REST requests use same-origin credentials and JSON' );
check( str_contains( (string) $javascript, "new CustomEvent('cpt2collection:cart-updated'") && str_contains( (string) $javascript, 'renderAll(cart)'), 'mutations publish shared state to every component' );
check( str_contains( (string) $javascript, "event.key === 'Escape'") && str_contains( (string) $javascript, 'state.mutations.has(key)'), 'Escape and duplicate mutation guards are present' );
check( str_contains( (string) $javascript, 'item.commercial.message') && str_contains( (string) $javascript, 'removeButton(item, root)'), 'invalid items remain rendered and removable' );
check( str_contains( (string) $javascript, 'cart.total_minor === null ? strings.totalUnavailable') && str_contains( (string) $javascript, 'cart.minimum_order_remaining'), 'indeterminate total and minimum remaining are rendered from REST data' );
check( str_contains( (string) $javascript, 'finally { state.mutations.delete(key); setBusy(key, false); }'), 'mutation controls are restored after errors' );
check( str_contains( (string) $css, '.cpt2collection-') && ! preg_match( '/(^|})\s*(button|input|\.cart)\s*[,{]/m', (string) $css ), 'frontend CSS is prefixed and has no generic reset selectors' );
check( str_contains( (string) $javascript, 'getBoundingClientRect()' ) && str_contains( (string) $javascript, "window.addEventListener('orientationchange'" ), 'open mini-cart alignment is viewport-aware and recalculated' );
check( str_contains( (string) $javascript, "image.loading = 'lazy'" ) && str_contains( (string) $javascript, 'safeUrl(item.thumbnail.url)' ), 'thumbnail rendering validates URLs and requests lazy decoding' );
check( str_contains( (string) $css, 'object-fit: contain' ) && ! str_contains( (string) $css, 'object-fit: cover' ), 'thumbnail CSS preserves the complete image without cropping' );
check( str_contains( (string) $css, '--cpt2collection-surface:' ) && str_contains( (string) $css, '--cpt2collection-danger:' ), 'theme-facing cart design variables are available' );

echo "All cart frontend checks passed.\n";
