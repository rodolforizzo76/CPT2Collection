<?php
/** Explicit customer projection; never serialize persistence records. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;

use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

final class PostCheckoutView {
	private function __construct( private array $data ) {}

	/** @param object[] $rows Authorized rows from one database statement. */
	public static function from_rows( array $rows ): self {
		$order = $rows[0];
		if ( ! OrderStatuses::is_valid( $order->status ) || ! isset( Money::get_currencies()[ $order->currency ] ) ) {
			throw new UnexpectedValueException( 'Invalid order snapshot.' );
		}
		$paid = OrderStatuses::PAID === $order->status;
		// Payment evidence/attention cannot override a committed paid order.
		$payment = $paid ? 'confirmed' : ( (bool) $order->payment_attention ? 'requires_review' : 'not_confirmed' );
		if ( ! $paid && ! $order->payment_attention && in_array( $order->status, array( OrderStatuses::PENDING, OrderStatuses::AWAITING_PAYMENT ), true ) ) {
			$payment = 'pending';
		}
		$cart = 'not_applicable';
		if ( $paid ) {
			$cart = match ( $order->cart_reconciliation_status ) {
				'completed' => match ( $order->cart_reconciliation_code ) {
					'cart_reconciled' => 'completed',
					default => 'requires_review',
				},
				null, 'pending' => 'pending',
				default => 'requires_review',
			};
		}
		$items = array();
		foreach ( $rows as $row ) {
			if ( null === $row->item_id || $row->item_currency !== $order->currency || ! is_string( $row->item_name ) || '' === $row->item_name ) {
				throw new UnexpectedValueException( 'Invalid item snapshot.' );
			}
			$items[] = array(
				// Decode one entity layer for display only; the immutable snapshot stays untouched.
				// This remains untrusted plain JSON text and must be rendered with textContent.
				'name' => html_entity_decode( $row->item_name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'quantity' => self::integer( $row->quantity, 1 ),
				'unit_price_minor' => self::integer( $row->unit_price_minor ),
				'line_total_minor' => self::integer( $row->line_total_minor ),
			);
		}
		return new self( array(
			'reference' => 'C2C-' . self::integer( $order->order_id, 1 ),
			'order_status' => $order->status,
			'created_at' => self::timestamp( $order->created_at ),
			'paid_at' => $paid ? self::timestamp( $order->paid_at ) : null,
			'currency' => $order->currency,
			'subtotal_minor' => self::integer( $order->subtotal_minor ),
			'total_minor' => self::integer( $order->total_minor ),
			'items' => $items,
			'payment_state' => $payment,
			'cart_update_state' => $cart,
		) );
	}

	public function to_array(): array { return $this->data; }

	private static function integer( mixed $value, int $minimum = 0 ): int {
		if ( ! is_int( $value ) && ( ! is_string( $value ) || ! preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) ) ) {
			throw new UnexpectedValueException( 'Invalid snapshot number.' );
		}
		$number = filter_var( $value, FILTER_VALIDATE_INT );
		if ( false === $number || $number < $minimum ) { throw new UnexpectedValueException( 'Invalid snapshot number.' ); }
		return $number;
	}

	private static function timestamp( mixed $value ): string {
		$date = is_string( $value ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) ) : false;
		if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) { throw new UnexpectedValueException( 'Invalid snapshot timestamp.' ); }
		return $date->format( 'Y-m-d\TH:i:s\Z' );
	}
}
