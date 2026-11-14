<?php
/** Payment-session statuses. @package CPT2Collection */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

final class PaymentSessionStatuses {
	public const CREATING  = 'creating';
	public const ACTIVE    = 'active';
	public const COMPLETED = 'completed';
	public const EXPIRED   = 'expired';
	public const CANCELLED = 'cancelled';
	public const FAILED    = 'failed';

	/** @return array<string,string> */
	public static function get_statuses(): array {
		return array(
			self::CREATING  => __( 'Creating', 'cpt2collection' ),
			self::ACTIVE    => __( 'Active', 'cpt2collection' ),
			self::COMPLETED => __( 'Completed', 'cpt2collection' ),
			self::EXPIRED   => __( 'Expired', 'cpt2collection' ),
			self::CANCELLED => __( 'Cancelled', 'cpt2collection' ),
			self::FAILED    => __( 'Failed', 'cpt2collection' ),
		);
	}

	public static function is_valid( string $status ): bool { return isset( self::get_statuses()[ $status ] ); }
	public static function owns_active_slot( string $status ): bool { return in_array( $status, array( self::CREATING, self::ACTIVE ), true ); }
	public static function is_terminal( string $status ): bool { return self::is_valid( $status ) && ! self::owns_active_slot( $status ); }
}
