<?php
/**
 * Commercial statuses.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

/**
 * Defines allowed commercial statuses.
 */
final class Statuses {
	public const DEFAULT_STATUS = 'not_for_sale';

	/**
	 * Gets statuses keyed by technical value.
	 *
	 * @return array<string,string>
	 */
	public static function get_statuses(): array {
		return array(
			'available'    => __( 'Available', 'cpt2collection' ),
			'reserved'     => __( 'Reserved', 'cpt2collection' ),
			'sold'         => __( 'Sold', 'cpt2collection' ),
			'not_for_sale' => __( 'Not for sale', 'cpt2collection' ),
		);
	}

	/**
	 * Validates a status value.
	 */
	public static function is_valid( string $status ): bool {
		return isset( self::get_statuses()[ $status ] );
	}

	/**
	 * Gets a status label.
	 */
	public static function get_label( string $status ): string {
		$statuses = self::get_statuses();

		return $statuses[ $status ] ?? $statuses[ self::DEFAULT_STATUS ];
	}
}
