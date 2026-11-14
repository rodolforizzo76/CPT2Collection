<?php
/**
 * Catalog metadata normalization and reads.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes catalog-level product metadata behavior.
 */
final class Catalog {
	public const DEFAULT_STOCK_QUANTITY = 1;

	/**
	 * Normalizes stock quantity input or stored metadata.
	 *
	 * Missing, empty, decimal, or malformed values normalize to the backward-compatible default.
	 * Negative integers normalize to zero.
	 *
	 * @param mixed $value Raw stock quantity value.
	 */
	public static function normalize_stock_quantity( $value ): int {
		if ( null === $value ) {
			return self::DEFAULT_STOCK_QUANTITY;
		}

		if ( is_int( $value ) ) {
			return max( 0, $value );
		}

		if ( is_float( $value ) ) {
			return self::DEFAULT_STOCK_QUANTITY;
		}

		if ( is_bool( $value ) || is_array( $value ) || is_object( $value ) ) {
			return self::DEFAULT_STOCK_QUANTITY;
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return self::DEFAULT_STOCK_QUANTITY;
		}

		if ( ! preg_match( '/^-?\d+$/', $value ) ) {
			return self::DEFAULT_STOCK_QUANTITY;
		}

		return max( 0, (int) $value );
	}

	/**
	 * Gets the normalized stock quantity for a post.
	 */
	public static function get_stock_quantity( int $post_id ): int {
		if ( $post_id <= 0 ) {
			return self::DEFAULT_STOCK_QUANTITY;
		}

		return self::normalize_stock_quantity( get_post_meta( $post_id, MetaKeys::STOCK_QUANTITY, true ) );
	}

	/**
	 * Determines whether the post can bypass the future minimum-order quantity rule.
	 */
	public static function is_minimum_order_exempt( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		return '1' === (string) get_post_meta( $post_id, MetaKeys::MINIMUM_ORDER_EXEMPT, true );
	}
}
