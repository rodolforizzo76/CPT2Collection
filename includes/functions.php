<?php
/**
 * Public template functions.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

use RodolfoRizzo\Cpt2Collection\Prices;
use RodolfoRizzo\Cpt2Collection\Ratings;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'cpt2collection_get_rating_html' ) ) {
	/**
	 * Gets rating HTML for a post without echoing it.
	 */
	function cpt2collection_get_rating_html( int $post_id = 0 ): string {
		return Ratings::get_rating_html( $post_id );
	}
}

if ( ! function_exists( 'cpt2collection_get_price_html' ) ) {
	/**
	 * Gets price HTML for a post without echoing it.
	 */
	function cpt2collection_get_price_html( int $post_id = 0 ): string {
		return Prices::get_price_html( $post_id );
	}
}
