<?php
/**
 * Configured cart page resolution.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

final class CartPage {
	public const OPTION_PAGE_ID = 'cpt2collection_cart_page_id';

	public static function sanitize( mixed $value ): int {
		$page_id = absint( $value );
		return $page_id > 0 && 'page' === get_post_type( $page_id ) && 'trash' !== get_post_status( $page_id ) ? $page_id : 0;
	}

	public static function get_id(): int {
		return self::sanitize( get_option( self::OPTION_PAGE_ID, 0 ) );
	}

	public static function get_url(): string {
		$page_id = self::get_id();
		$url     = $page_id > 0 ? get_permalink( $page_id ) : false;
		return is_string( $url ) ? $url : '';
	}
}
