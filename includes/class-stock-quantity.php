<?php
/** Strict administrative/persistence stock validation, separate from legacy reads. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class StockQuantity {
	/** Canonical decimal integer, bounded by the PHP range used by commerce arithmetic. */
	public static function parse( mixed $value ): ?int {
		if ( is_int( $value ) ) { return $value >= 0 ? $value : null; }
		if ( ! is_string( $value ) || strlen( $value ) > strlen( (string) PHP_INT_MAX ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) ) { return null; }
		$number = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		return false === $number ? null : $number;
	}
}
