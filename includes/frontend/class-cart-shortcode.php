<?php
/** Full cart shortcode. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection\Frontend;
defined( 'ABSPATH' ) || exit;

final class CartShortcode {
	private Assets $assets;
	public function __construct( Assets $assets ) { $this->assets = $assets; }
	public function register(): void { add_action( 'init', array( $this, 'add_shortcode' ) ); }
	public function add_shortcode(): void { add_shortcode( 'cpt2collection_cart', array( $this, 'render' ) ); }
	public function render(): string {
		$this->assets->enqueue_cart();
		return '<section class="cpt2collection-cart" data-cpt2collection-cart aria-busy="true"><div class="cpt2collection-cart__items" data-items><p>' . esc_html__( 'Loading cart…', 'cpt2collection' ) . '</p></div><div class="cpt2collection-cart__summary" data-summary></div><div class="cpt2collection-cart__actions"><button class="cpt2collection-button cpt2collection-button--danger" type="button" data-clear hidden>' . esc_html__( 'Clear cart', 'cpt2collection' ) . '</button><button class="cpt2collection-button cpt2collection-button--primary" type="button" data-checkout hidden>' . esc_html__( 'Proceed to checkout', 'cpt2collection' ) . '</button></div><div class="cpt2collection-message" data-message aria-live="polite"></div></section>';
	}
}
