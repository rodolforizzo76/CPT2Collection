<?php
/** Phase 2 shortcode/asset integration plus executable JavaScript behavior tests. */
declare( strict_types=1 );
define( 'ABSPATH', __DIR__ );
define( 'CPT2COLLECTION_PATH', dirname( __DIR__ ) . '/' );
define( 'CPT2COLLECTION_URL', 'https://shop.example/plugin/' );
define( 'CPT2COLLECTION_VERSION', 'test' );
$GLOBALS['cart_page'] = 42;
$GLOBALS['scripts'] = $GLOBALS['styles'] = $GLOBALS['inline'] = $GLOBALS['enqueued_scripts'] = $GLOBALS['enqueued_styles'] = array();
function __( string $text, string $domain ): string { $GLOBALS['translated'][$text] = $domain; return ! empty( $GLOBALS['hostile_translation'] ) ? '</script><script>untrusted()</script>' : $text; }
function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( string $text, string $domain ): string { return esc_html( __( $text, $domain ) ); }
function esc_url( string $url ): string { return esc_html( $url ); }
function esc_url_raw( string $url ): string { return $url; }
function home_url( string $path ): string { return 'https://shop.example' . $path; }
function rest_url( string $path ): string { return 'https://shop.example/wp-json/' . $path; }
function determine_locale(): string { return $GLOBALS['test_locale'] ?? 'en_US'; }
function wp_json_encode( mixed $value, int $flags = 0 ): string { return json_encode( $value, $flags | JSON_THROW_ON_ERROR ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function get_option( string $key, mixed $default = false ): mixed { return 'cpt2collection_cart_page_id' === $key ? $GLOBALS['cart_page'] : $default; }
function get_post_type( int $id ): string { return 'page'; }
function get_post_status( int $id ): string { return 'publish'; }
function get_permalink( int $id ): string { return 'https://shop.example/cart/?a=1&b=2'; }
function add_action( string $hook, callable $callback ): void { $GLOBALS['actions'][$hook][] = $callback; }
function add_shortcode( string $tag, callable $callback ): void { $GLOBALS['shortcodes'][$tag] = $callback; }
function wp_register_style( string $handle, string $url, array $deps, string $version ): void { $GLOBALS['styles'][$handle] = $url; }
function wp_register_script( string $handle, string $url, array $deps, string $version, bool $footer ): void { $GLOBALS['scripts'][$handle] = array( 'url'=>$url, 'footer'=>$footer, 'deps'=>$deps ); }
function wp_set_script_translations( string $handle, string $domain, string $path ): void {
	$GLOBALS['script_translations'][$handle] = array( $domain, $path );
}
function wp_style_is( string $handle, string $status ): bool { return isset( $GLOBALS['styles'][$handle] ); }
function wp_script_is( string $handle, string $status ): bool { return isset( $GLOBALS['scripts'][$handle] ); }
function wp_enqueue_style( string $handle ): void { $GLOBALS['enqueued_styles'][$handle] = true; }
function wp_enqueue_script( string $handle ): void { $GLOBALS['enqueued_scripts'][$handle] = true; }
function wp_add_inline_script( string $handle, string $code, string $position ): void { $GLOBALS['inline'][] = compact( 'handle','code','position' ); }
foreach ( array( 'class-post-types.php', 'class-cart-page.php', 'frontend/class-assets.php', 'frontend/class-post-checkout-shortcodes.php' ) as $file ) { require_once dirname( __DIR__ ) . '/includes/' . $file; }
use RodolfoRizzo\Cpt2Collection\PostTypes;
use RodolfoRizzo\Cpt2Collection\Frontend\{Assets,PostCheckoutShortcodes};
$checks = 0;
$fixture_only = in_array( '--fixture', $argv, true );
function check( bool $condition, string $label ): void { global $checks, $fixture_only; if ( ! $condition ) { throw new RuntimeException( $label ); } ++$checks; if ( ! $fixture_only ) { echo "PASS: {$label}\n"; } }
$assets = new Assets( new PostTypes() );
$views = new PostCheckoutShortcodes( $assets );
$views->register();
check( isset( $GLOBALS['actions']['init'] ), 'shortcodes use the existing init registration pattern' );
foreach ( $GLOBALS['actions']['init'] as $callback ) { $callback(); }
check( array_keys( $GLOBALS['shortcodes'] ) === array( 'cpt2collection_checkout_success', 'cpt2collection_checkout_cancel' ), 'both dedicated return renderers registered' );
$cancel = $GLOBALS['shortcodes']['cpt2collection_checkout_cancel']();
check( array() === $GLOBALS['enqueued_scripts'] && array() === $GLOBALS['inline'], 'cancel enqueues CSS only, with no lookup script or executable configuration' );
check( isset( $GLOBALS['enqueued_styles'][Assets::POST_CHECKOUT_HANDLE] ), 'cancel uses isolated post-checkout styles' );
check( str_contains( $cancel, 'does not confirm or cancel your order or payment' ), 'cancel wording makes no lifecycle conclusion' );
check( str_contains( $cancel, 'Return to cart' ) && str_contains( $cancel, 'a=1&amp;b=2' ), 'configured cart URL is reused and escaped' );
$GLOBALS['cart_page'] = 0;
check( str_contains( $views->render_cancel(), 'Return to site' ) && str_contains( $views->render_cancel(), 'https://shop.example/' ), 'missing cart page falls back to home without new settings' );
$GLOBALS['cart_page'] = 42;
$success = $GLOBALS['shortcodes']['cpt2collection_checkout_success']();
check( str_contains( $success, 'data-cpt2collection-checkout-success' ) && str_contains( $success, 'data-status' ) && str_contains( $success, 'data-summary' ) && str_contains( $success, 'data-cart-update' ) && str_contains( $success, 'data-check-status' ), 'success static shell has required rendering and explicit read controls' );
check( str_contains( $success, 'aria-live="polite"' ) && str_contains( $success, '<noscript>' ) && str_contains( $success, 'hidden' ), 'shell has live status, no-JS fallback, and initially hidden read control' );
check( array_keys( $GLOBALS['enqueued_scripts'] ) === array( Assets::POST_CHECKOUT_HANDLE ) && $GLOBALS['scripts'][Assets::POST_CHECKOUT_HANDLE]['footer'], 'success loads its own footer script without the cart mutation script' );
$views->render_success();
check( 1 === count( $GLOBALS['inline'] ) && 'before' === $GLOBALS['inline'][0]['position'], 'multiple shortcode instances share one configuration' );
$script = $GLOBALS['inline'][0]['code'];
$config = json_decode( substr( $script, strlen( 'window.CPT2CollectionPostCheckout=' ), -1 ), true, 512, JSON_THROW_ON_ERROR );
check( array_keys( $config ) === array( 'lookupUrl','locale' ) && rest_url( 'cpt2collection/v1/post-checkout' ) === $config['lookupUrl'], 'configuration includes only read endpoint and locale' );
check( ! str_contains( $script . $success . $cancel, 'cs_test_' ) && ! str_contains( $script, 'cartToken' ) && ! str_contains( $script, 'currency' ), 'PHP shell/config contains no session credential or global currency' );
$assets->register_cart_assets();
foreach ( array( Assets::CART_SCRIPT_HANDLE, Assets::POST_CHECKOUT_HANDLE ) as $handle ) {
	check( array( 'wp-i18n' ) === $GLOBALS['scripts'][$handle]['deps'] && array( 'cpt2collection', CPT2COLLECTION_PATH . 'languages' ) === $GLOBALS['script_translations'][$handle], 'WordPress registers the script locale catalog and dependency for ' . $handle );
}
$GLOBALS['hostile_translation'] = true;
$GLOBALS['test_locale'] = 'pt_PT_ao90';
$new_views = new PostCheckoutShortcodes( new Assets( new PostTypes() ) );
$escaped = $new_views->render_success();
$encoded = end( $GLOBALS['inline'] )['code'];
check( ! str_contains( $escaped, '<script>' ) && ! str_contains( $encoded, '</script>' ), 'PHP output escaping and JSON hex encoding contain hostile translations' );
check( str_contains( $encoded, '"locale":"pt-PT"' ), 'browser Intl receives a valid tag for a WordPress locale variant' );
$GLOBALS['hostile_translation'] = false;
$root = dirname( __DIR__ );
$plugin = file_get_contents( $root . '/includes/class-plugin.php' );
$bootstrap = file_get_contents( $root . '/cpt2collection.php' );
check( str_contains( $plugin, 'new PostCheckoutShortcodes( $assets )' ) && str_contains( $bootstrap, 'frontend/class-post-checkout-shortcodes.php' ), 'production bootstrap wires the shortcodes to shared assets' );
$js = file_get_contents( $root . '/assets/js/post-checkout.js' );
$php = file_get_contents( $root . '/includes/frontend/class-post-checkout-shortcodes.php' );
check( ! preg_match( '/innerHTML|insertAdjacentHTML|localStorage|sessionStorage|document\.cookie|setInterval|setTimeout/', $js ), 'no unsafe HTML insertion, persistence, cookie access or polling timers' );
check( ! preg_match( '/CheckoutService|PaymentSessionService|SaleConversionService|PaymentExpirationService|CartReconciliationService|Stripe|wpdb/', $php ) && ! preg_match( '/method:\s*[\x27\x22](POST|PATCH|PUT|DELETE)/', $js ), 'renderers contain no commerce service, provider or mutation path' );
$css = file_get_contents( $root . '/assets/css/post-checkout.css' );
check( ! preg_match( '/(^|})\s*(body|button|table|h2|a)\s*[{,]/', $css ) && str_contains( $css, ':focus-visible' ), 'styles are scoped and keyboard focus remains visible' );
$fixture = array( 'config'=>$config, 'success'=>$success, 'cancel'=>$cancel );
if ( $fixture_only ) { echo json_encode( $fixture, JSON_THROW_ON_ERROR ); exit( 0 ); }
echo "All {$checks} post-checkout PHP frontend checks passed.\n";
$process = proc_open( array( 'node', __DIR__ . '/verify-post-checkout-frontend.js' ), array( 0=>array('pipe','r'), 1=>array('pipe','w'), 2=>STDERR ), $pipes, $root );
if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Node.js is required for executable frontend verification.' ); }
fwrite( $pipes[0], json_encode( $fixture, JSON_THROW_ON_ERROR ) ); fclose( $pipes[0] );
echo stream_get_contents( $pipes[1] ); fclose( $pipes[1] );
exit( proc_close( $process ) );
