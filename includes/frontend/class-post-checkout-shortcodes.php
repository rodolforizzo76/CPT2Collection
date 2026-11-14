<?php
/** Observational checkout return views. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection\Frontend;

use RodolfoRizzo\Cpt2Collection\CartPage;

defined( 'ABSPATH' ) || exit;

final class PostCheckoutShortcodes {
	public function __construct( private Assets $assets ) {}

	public function register(): void { add_action( 'init', array( $this, 'add_shortcodes' ) ); }
	public function add_shortcodes(): void {
		add_shortcode( 'cpt2collection_checkout_success', array( $this, 'render_success' ) );
		add_shortcode( 'cpt2collection_checkout_cancel', array( $this, 'render_cancel' ) );
	}

	/** Cacheable static shell only; order details are fetched privately in the browser. */
	public function render_success(): string {
		$this->assets->enqueue_post_checkout( true );
		return '<section class="cpt2collection-post-checkout" data-cpt2collection-checkout-success>'
			. '<div role="status" aria-live="polite" aria-atomic="true" data-status><h2>' . esc_html__( 'Order status', 'cpt2collection' ) . '</h2>'
			. '<p>' . esc_html__( 'Check your order status here.', 'cpt2collection' ) . '</p></div>'
			. '<div data-summary></div><div data-cart-update></div>'
			. '<div class="cpt2collection-post-checkout__actions"><button type="button" data-check-status hidden>' . esc_html__( 'Check status', 'cpt2collection' ) . '</button>'
			. $this->return_link() . '</div>'
			. '<noscript><p>' . esc_html__( 'Enable JavaScript to check your order status. Visiting this page does not confirm payment.', 'cpt2collection' ) . '</p></noscript></section>';
	}

	/** No session lookup, script, or lifecycle inference on cancellation navigation. */
	public function render_cancel(): string {
		$this->assets->enqueue_post_checkout( false );
		return '<section class="cpt2collection-post-checkout">'
			. '<h2>' . esc_html__( 'Checkout', 'cpt2collection' ) . '</h2>'
			. '<p>' . esc_html__( 'Opening this page does not confirm or cancel your order or payment.', 'cpt2collection' ) . '</p>'
			. '<p>' . esc_html__( 'You can return to review your items and decide what to do next.', 'cpt2collection' ) . '</p>'
			. '<div class="cpt2collection-post-checkout__actions">' . $this->return_link() . '</div></section>';
	}

	private function return_link(): string {
		$url = CartPage::get_url();
		$label = '' !== $url ? __( 'Return to cart', 'cpt2collection' ) : __( 'Return to site', 'cpt2collection' );
		return '<a href="' . esc_url( '' !== $url ? $url : home_url( '/' ) ) . '">' . esc_html( $label ) . '</a>';
	}
}
