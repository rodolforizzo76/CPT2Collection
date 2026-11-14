<?php
/**
 * Price shortcode.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection\Frontend;

use RodolfoRizzo\Cpt2Collection\Prices;

defined( 'ABSPATH' ) || exit;

final class PriceShortcode {
	public function register(): void {
		add_action( 'init', array( $this, 'add_shortcode' ) );
	}

	public function add_shortcode(): void {
		add_shortcode( 'cpt2collection_price', array( $this, 'render' ) );
	}

	/**
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function render( array|string $atts = array() ): string {
		$atts = shortcode_atts(
			array( 'post_id' => 0 ),
			(array) $atts,
			'cpt2collection_price'
		);

		return Prices::get_price_html( absint( $atts['post_id'] ) );
	}
}
