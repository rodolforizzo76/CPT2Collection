<?php
/**
 * Order statuses.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

/**
 * Defines allowed order lifecycle statuses.
 */
final class OrderStatuses {
	public const PENDING          = 'pending';
	public const AWAITING_PAYMENT = 'awaiting_payment';
	public const PAID             = 'paid';
	public const CANCELLED        = 'cancelled';
	public const EXPIRED          = 'expired';
	public const FAILED           = 'failed';

	/**
	 * Gets statuses keyed by technical value.
	 *
	 * @return array<string,string>
	 */
	public static function get_statuses(): array {
		return array(
			self::PENDING          => __( 'Pending', 'cpt2collection' ),
			self::AWAITING_PAYMENT => __( 'Awaiting payment', 'cpt2collection' ),
			self::PAID             => __( 'Paid', 'cpt2collection' ),
			self::CANCELLED        => __( 'Cancelled', 'cpt2collection' ),
			self::EXPIRED          => __( 'Expired', 'cpt2collection' ),
			self::FAILED           => __( 'Failed', 'cpt2collection' ),
		);
	}

	public static function is_valid( string $status ): bool {
		return isset( self::get_statuses()[ $status ] );
	}

	public static function get_label( string $status ): string {
		$statuses = self::get_statuses();

		return $statuses[ $status ] ?? $statuses[ self::PENDING ];
	}
}
