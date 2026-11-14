<?php
/**
 * Reservation statuses.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

/**
 * Defines allowed stock-reservation statuses.
 */
final class ReservationStatuses {
	public const ACTIVE    = 'active';
	public const EXPIRED   = 'expired';
	public const RELEASED  = 'released';
	public const CONVERTED = 'converted';

	/**
	 * Gets statuses keyed by technical value.
	 *
	 * @return array<string,string>
	 */
	public static function get_statuses(): array {
		return array(
			self::ACTIVE    => __( 'Active', 'cpt2collection' ),
			self::EXPIRED   => __( 'Expired', 'cpt2collection' ),
			self::RELEASED  => __( 'Released', 'cpt2collection' ),
			self::CONVERTED => __( 'Converted', 'cpt2collection' ),
		);
	}

	public static function is_valid( string $status ): bool {
		return isset( self::get_statuses()[ $status ] );
	}

	public static function get_label( string $status ): string {
		$statuses = self::get_statuses();

		return $statuses[ $status ] ?? $statuses[ self::ACTIVE ];
	}
}
