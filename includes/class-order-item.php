<?php
/**
 * Order item snapshot.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable commercial snapshot for one persisted order item row.
 */
final class OrderItem {
	private int $id;
	private int $order_id;
	private int $post_id;
	private string $item_name;
	private int $unit_price_minor;
	private int $quantity;
	private int $line_subtotal_minor;
	private int $line_total_minor;
	private string $currency;
	private string $created_at;
	private ?int $source_cart_item_id;
	private ?int $source_cart_item_version;

	public function __construct( int $id, int $order_id, int $post_id, string $item_name, int $unit_price_minor, int $quantity, int $line_subtotal_minor, int $line_total_minor, string $currency, string $created_at, ?int $source_cart_item_id = null, ?int $source_cart_item_version = null ) {
		if ( $id <= 0 || $order_id <= 0 || $post_id <= 0 ) {
			throw new InvalidArgumentException( 'Order item ids must be greater than zero.' );
		}
		if ( '' === $item_name ) {
			throw new InvalidArgumentException( 'Order item name is required.' );
		}
		if ( $unit_price_minor < 0 || $line_subtotal_minor < 0 || $line_total_minor < 0 ) {
			throw new InvalidArgumentException( 'Order item monetary values must be non-negative.' );
		}
		if ( $quantity < 1 ) {
			throw new InvalidArgumentException( 'Order item quantity must be at least 1.' );
		}
		if ( ! isset( Money::get_currencies()[ $currency ] ) ) {
			throw new InvalidArgumentException( 'Order item currency is invalid.' );
		}
		if ( '' === $created_at ) {
			throw new InvalidArgumentException( 'Order item created_at is required.' );
		}
		if ( ( null === $source_cart_item_id ) !== ( null === $source_cart_item_version ) || ( null !== $source_cart_item_id && ( $source_cart_item_id <= 0 || $source_cart_item_version < 0 ) ) ) {
			throw new InvalidArgumentException( 'Order item source cart identity is invalid.' );
		}

		$this->id                  = $id;
		$this->order_id            = $order_id;
		$this->post_id             = $post_id;
		$this->item_name           = $item_name;
		$this->unit_price_minor    = $unit_price_minor;
		$this->quantity            = $quantity;
		$this->line_subtotal_minor = $line_subtotal_minor;
		$this->line_total_minor    = $line_total_minor;
		$this->currency            = $currency;
		$this->created_at          = $created_at;
		$this->source_cart_item_id = $source_cart_item_id;
		$this->source_cart_item_version = $source_cart_item_version;
	}

	/**
	 * @param object{id:mixed,order_id:mixed,post_id:mixed,item_name:mixed,unit_price_minor:mixed,quantity:mixed,line_subtotal_minor:mixed,line_total_minor:mixed,currency:mixed,created_at:mixed,source_cart_item_id?:mixed,source_cart_item_version?:mixed} $row Database row.
	 */
	public static function from_row( object $row ): self {
		$source_id      = self::persisted_nullable_nonnegative_integer( $row->source_cart_item_id ?? null, 'source_cart_item_id' );
		$source_version = self::persisted_nullable_nonnegative_integer( $row->source_cart_item_version ?? null, 'source_cart_item_version' );
		return new self( (int) $row->id, (int) $row->order_id, (int) $row->post_id, (string) $row->item_name, (int) $row->unit_price_minor, (int) $row->quantity, (int) $row->line_subtotal_minor, (int) $row->line_total_minor, (string) $row->currency, (string) $row->created_at, $source_id, $source_version );
	}

	private static function persisted_nullable_nonnegative_integer( mixed $value, string $name ): ?int {
		if ( null === $value ) { return null; }
		if ( is_int( $value ) && $value >= 0 ) { return $value; }
		if ( is_string( $value ) && 1 === preg_match( '/\A(?:0|[1-9]\d*)\z/', $value ) ) {
			$parsed = filter_var( $value, FILTER_VALIDATE_INT );
			if ( is_int( $parsed ) && $parsed >= 0 ) { return $parsed; }
		}
		throw new InvalidArgumentException( 'Order item ' . $name . ' is invalid.' );
	}

	public function id(): int { return $this->id; }
	public function order_id(): int { return $this->order_id; }
	public function post_id(): int { return $this->post_id; }
	public function item_name(): string { return $this->item_name; }
	public function unit_price_minor(): int { return $this->unit_price_minor; }
	public function quantity(): int { return $this->quantity; }
	public function line_subtotal_minor(): int { return $this->line_subtotal_minor; }
	public function line_total_minor(): int { return $this->line_total_minor; }
	public function currency(): string { return $this->currency; }
	public function created_at(): string { return $this->created_at; }
	public function source_cart_item_id(): ?int { return $this->source_cart_item_id; }
	public function source_cart_item_version(): ?int { return $this->source_cart_item_version; }
}
