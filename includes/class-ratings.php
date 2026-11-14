<?php
/**
 * Rating validation and rendering.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

/**
 * Handles supported rating values and frontend HTML.
 */
final class Ratings {
	/**
	 * Gets allowed rating values.
	 *
	 * @return array<string,string>
	 */
	public static function get_allowed_ratings(): array {
		return array(
			'0.5' => '0.5',
			'1'   => '1',
			'1.5' => '1.5',
			'2'   => '2',
			'2.5' => '2.5',
			'3'   => '3',
			'3.5' => '3.5',
			'4'   => '4',
			'4.5' => '4.5',
			'5'   => '5',
		);
	}

	/**
	 * Normalizes a rating value.
	 */
	public static function normalize( string $rating ): string {
		$rating = trim( $rating );

		return isset( self::get_allowed_ratings()[ $rating ] ) ? $rating : '';
	}

	/**
	 * Gets rating HTML for a post.
	 */
	public static function get_rating_html( int $post_id = 0 ): string {
		if ( 0 === $post_id ) {
			$post_id = (int) get_the_ID();
		}

		if ( $post_id <= 0 ) {
			return '';
		}

		$rating = self::normalize( (string) get_post_meta( $post_id, MetaKeys::RATING, true ) );
		if ( '' === $rating ) {
			return '';
		}

		$value      = max( 0, min( 5, (float) $rating ) );
		$stars      = '';
		$base_class = 'cpt2collection-rating-star';

		for ( $i = 1; $i <= 5; $i++ ) {
			if ( $value >= $i ) {
				$state = 'full';
			} elseif ( $value >= $i - 0.5 ) {
				$state = 'half';
			} else {
				$state = 'empty';
			}

			$stars .= sprintf(
				'<span class="%1$s %1$s--%2$s" aria-hidden="true">★</span>',
				esc_attr( $base_class ),
				esc_attr( $state )
			);
		}

		return sprintf(
			'<span class="cpt2collection-rating-stars" role="img" aria-label="%1$s">%2$s</span>',
			/* translators: %s: the displayed rating. */
			esc_attr( sprintf( __( 'Rating: %s out of 5', 'cpt2collection' ), $rating ) ),
			$stars
		);
	}
}
