<?php
/**
 * Commerce settings normalization and reads.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes global commerce settings.
 */
final class CommerceSettings {
	public const OPTION_MINIMUM_ORDER_QUANTITY = 'cpt2collection_minimum_order_quantity';
	public const DEFAULT_MINIMUM_ORDER_QUANTITY = 1;
	public const OPTION_RESERVATION_DURATION_MINUTES = 'cpt2collection_reservation_duration_minutes';
	public const DEFAULT_RESERVATION_DURATION_MINUTES = 35;
	public const MIN_RESERVATION_DURATION_MINUTES = 5;
	public const MAX_RESERVATION_DURATION_MINUTES = 120;

	/**
	 * Normalizes a minimum-order quantity to an integer of at least one.
	 *
	 * @param mixed $value Raw setting value.
	 */
	public static function normalize_minimum_order_quantity( $value ): int {
		if ( is_int( $value ) ) {
			return $value >= 1 ? $value : self::DEFAULT_MINIMUM_ORDER_QUANTITY;
		}

		if ( ! is_string( $value ) || ! preg_match( '/^\d+$/', $value ) ) {
			return self::DEFAULT_MINIMUM_ORDER_QUANTITY;
		}

		$quantity = filter_var( $value, FILTER_VALIDATE_INT );

		return is_int( $quantity ) && $quantity >= 1 ? $quantity : self::DEFAULT_MINIMUM_ORDER_QUANTITY;
	}

	/**
	 * Gets the normalized global minimum-order quantity.
	 */
	public static function get_minimum_order_quantity(): int {
		$value = get_option( self::OPTION_MINIMUM_ORDER_QUANTITY, self::DEFAULT_MINIMUM_ORDER_QUANTITY );

		return self::normalize_minimum_order_quantity( $value );
	}

	/**
	 * Normalizes a reservation duration to the accepted minute range.
	 *
	 * @param mixed $value Raw setting value.
	 */
	public static function normalize_reservation_duration_minutes( $value ): int {
		if ( is_int( $value ) ) {
			return $value >= self::MIN_RESERVATION_DURATION_MINUTES && $value <= self::MAX_RESERVATION_DURATION_MINUTES ? $value : self::DEFAULT_RESERVATION_DURATION_MINUTES;
		}

		if ( ! is_string( $value ) || ! preg_match( '/^\d+$/', $value ) ) {
			return self::DEFAULT_RESERVATION_DURATION_MINUTES;
		}

		$minutes = filter_var( $value, FILTER_VALIDATE_INT );

		return is_int( $minutes ) && $minutes >= self::MIN_RESERVATION_DURATION_MINUTES && $minutes <= self::MAX_RESERVATION_DURATION_MINUTES ? $minutes : self::DEFAULT_RESERVATION_DURATION_MINUTES;
	}

	public static function get_reservation_duration_minutes(): int {
		$value = get_option( self::OPTION_RESERVATION_DURATION_MINUTES, self::DEFAULT_RESERVATION_DURATION_MINUTES );

		return self::normalize_reservation_duration_minutes( $value );
	}

	public static function get_reservation_duration_seconds(): int {
		return self::get_reservation_duration_minutes() * MINUTE_IN_SECONDS;
	}
}
