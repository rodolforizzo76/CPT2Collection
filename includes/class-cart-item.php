<?php
/**
 * Cart item value object.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable representation of a persisted cart item row.
 */
final class CartItem {
	private int $id;
	private int $cart_id;
	private int $post_id;
	private int $quantity;
	private int $content_version;

	public function __construct( int $post_id, int $quantity, int $id = 0, int $cart_id = 0, int $content_version = 0 ) {
		if ( $post_id <= 0 ) {
			throw new InvalidArgumentException( 'Cart item post_id must be greater than zero.' );
		}

		if ( $quantity < 1 ) {
			throw new InvalidArgumentException( 'Cart item quantity must be at least 1.' );
		}
		if ( $content_version < 0 ) {
			throw new InvalidArgumentException( 'Cart item content_version must be non-negative.' );
		}

		$this->id       = $id;
		$this->cart_id  = $cart_id;
		$this->post_id  = $post_id;
		$this->quantity = $quantity;
		$this->content_version = $content_version;
	}

	/**
	 * @param object{id:mixed,cart_id:mixed,post_id:mixed,quantity:mixed,content_version?:mixed} $row Database row.
	 */
	public static function from_row( object $row ): self {
		return new self(
			(int) $row->post_id,
			(int) $row->quantity,
			(int) $row->id,
			(int) $row->cart_id,
			self::persisted_nonnegative_integer( $row->content_version ?? 0 )
		);
	}

	private static function persisted_nonnegative_integer( mixed $value ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/\A(?:0|[1-9]\d*)\z/', $value ) ) {
			$parsed = filter_var( $value, FILTER_VALIDATE_INT );
			if ( is_int( $parsed ) && $parsed >= 0 ) {
				return $parsed;
			}
		}
		throw new InvalidArgumentException( 'Cart item content_version is invalid.' );
	}

	public function id(): int { return $this->id; }
	public function cart_id(): int { return $this->cart_id; }
	public function post_id(): int { return $this->post_id; }
	public function quantity(): int { return $this->quantity; }
	public function content_version(): int { return $this->content_version; }
}
