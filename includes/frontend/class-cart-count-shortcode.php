<?php
/** Cart total-quantity shortcode. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection\Frontend;
defined( 'ABSPATH' ) || exit;

final class CartCountShortcode {
	private Assets $assets;
	public function __construct( Assets $assets ) { $this->assets = $assets; }
	public function register(): void { add_action( 'init', array( $this, 'add_shortcode' ) ); }
	public function add_shortcode(): void { add_shortcode( 'cpt2collection_cart_count', array( $this, 'render' ) ); }
	/** @param array<string,mixed>|string $atts */
	public function render( array|string $atts = array() ): string {
		$atts = shortcode_atts( array( 'hide_when_empty' => 'no' ), (array) $atts, 'cpt2collection_cart_count' );
		$this->assets->enqueue_cart();
		$hide = 'yes' === strtolower( sanitize_key( (string) $atts['hide_when_empty'] ) );
		/* translators: %d: number of items in the cart. */
		$count = sprintf( _n( '%d item in cart', '%d items in cart', 0, 'cpt2collection' ), 0 );

		return sprintf( '<span class="cpt2collection-cart-count" data-cpt2collection-cart-count data-hide-empty="%s" aria-label="%s" aria-busy="true">0</span>', $hide ? 'yes' : 'no', esc_attr( $count ) );
	}
}
