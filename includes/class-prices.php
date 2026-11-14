<?php
/**
 * Frontend price rendering.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

/**
 * Renders stored minor-unit prices for public templates and shortcodes.
 */
final class Prices {
	/**
	 * Gets price HTML for a post without echoing it.
	 */
	public static function get_price_html( int $post_id = 0 ): string {
		$post_id = $post_id > 0 ? $post_id : get_the_ID();
		$post_id = $post_id ? (int) $post_id : 0;

		if ( $post_id <= 0 ) {
			return '';
		}

		$price_minor = get_post_meta( $post_id, MetaKeys::PRICE_MINOR, true );
		if ( ! is_numeric( $price_minor ) || (int) $price_minor < 0 ) {
			return '';
		}

		$formatted_price = Money::format_minor( (int) $price_minor );

		/**
		 * Filters the formatted CPT2Collection price text.
		 *
		 * @param string $formatted_price Formatted price text.
		 * @param int    $post_id         Post ID.
		 */
		$formatted_price = (string) apply_filters( 'cpt2collection_formatted_price', $formatted_price, $post_id );

		$html = sprintf(
			'<span class="cpt2collection-price" aria-label="%1$s">%2$s</span>',
			esc_attr__( 'Price', 'cpt2collection' ),
			esc_html( $formatted_price )
		);

		/**
		 * Filters the final CPT2Collection price HTML.
		 *
		 * @param string $html            Price HTML.
		 * @param int    $post_id         Post ID.
		 * @param string $formatted_price Formatted price text.
		 */
		return (string) apply_filters( 'cpt2collection_price_html', $html, $post_id, $formatted_price );
	}
}
