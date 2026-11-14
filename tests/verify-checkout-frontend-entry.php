<?php
/** Structural verification for the production cart checkout control. */
declare( strict_types=1 );

$root = dirname( __DIR__ );
$js = file_get_contents( $root . '/assets/js/cart-frontend.js' );
$shortcode = file_get_contents( $root . '/includes/frontend/class-cart-shortcode.php' );
$assets = file_get_contents( $root . '/includes/frontend/class-assets.php' );
function check_frontend( bool $condition, string $label ): void { if ( ! $condition ) throw new RuntimeException( $label ); echo "PASS: {$label}\n"; }
check_frontend( str_contains( $shortcode, 'data-checkout' ) && str_contains( $shortcode, 'Proceed to checkout' ), 'cart shortcode supplies one minimal checkout control' );
check_frontend( str_contains( $assets, "rest_url( 'cpt2collection/v1/checkout' )" ), 'frontend receives only the production checkout endpoint URL' );
check_frontend( str_contains( $js, 'checkout_attempt_key') && str_contains( $js, 'payment_attempt_key' ) && str_contains( $js, 'crypto.getRandomValues'), 'frontend generates opaque attempt identities without commerce inputs' );
check_frontend( str_contains( $js, 'if (state.checkout) return') && str_contains( $js, 'button.disabled = true'), 'frontend suppresses duplicate in-flight submissions' );
check_frontend( str_contains( $js, 'window.location.assign(redirect)') && str_contains( $js, 'data.redirect_url'), 'frontend redirects only to the URL returned by the production endpoint' );
check_frontend( ! preg_match( '/(?:sk_(?:test|live)|whsec_)/', $js . $assets . $shortcode ), 'frontend assets contain no Stripe secrets' );
echo "All production checkout frontend checks passed.\n";
