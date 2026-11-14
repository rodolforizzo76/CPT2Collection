<?php
/** Transaction-scoped physical stock conversion primitive. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class StockConversionProcessor {
	private ReservationRepository $reservations;
	private LockedCatalogReader $catalog;
	private TransactionManager $transactions;
	private ConversionReservationSetValidator $validator;

	public function __construct( ReservationRepository $reservations, LockedCatalogReader $catalog, TransactionManager $transactions, ?ConversionReservationSetValidator $validator = null ) {
		$this->reservations = $reservations;
		$this->catalog      = $catalog;
		$this->transactions = $transactions;
		$this->validator    = $validator ?? new ConversionReservationSetValidator();
	}

	/**
	 * Runs inside a transaction after the order/session locks and immutable item read.
	 * It deliberately does not begin, commit, roll back, transition rows, or flush caches.
	 *
	 * @param OrderItem[] $items
	 */
	public function decrement( int $order_id, array $items, string $post_type, string $current_utc, ?callable $before_decrement = null ): StockConversionResult {
		if ( ! $this->transactions->is_commerce_admitted() ) {
			return StockConversionResult::failure( 'transaction_required' );
		}
		if ( $order_id <= 0 || array() === $items || '' === $post_type || '' === $current_utc ) {
			return StockConversionResult::failure( 'reservation_structural_mismatch' );
		}

		$item_ids = array();
		$post_ids = array();
		foreach ( $items as $item ) {
			if ( ! $item instanceof OrderItem || $item->order_id() !== $order_id ) {
				return StockConversionResult::failure( 'reservation_structural_mismatch' );
			}
			$item_ids[] = $item->id();
			$post_ids[] = $item->post_id();
		}
		$discovered = $this->reservations->discover_for_conversion( $order_id, $item_ids );
		if ( false === $discovered ) {
			return StockConversionResult::failure( 'database_error' );
		}
		$reservation_ids = array();
		foreach ( $discovered as $reservation ) {
			$reservation_ids[] = $reservation->id();
			$post_ids[] = $reservation->post_id();
		}
		$post_ids = array_values( array_unique( $post_ids ) );
		sort( $post_ids, SORT_NUMERIC );

		$locked = $this->catalog->lock_products( $post_ids );
		if ( null === $locked ) {
			return StockConversionResult::failure( 'database_error' );
		}
		if ( ! $locked ) {
			return StockConversionResult::failure( 'product_missing' );
		}

		$stocks = array();
		foreach ( $post_ids as $post_id ) {
			$product = $this->catalog->read_product( $post_id );
			if ( null === $product ) {
				return StockConversionResult::failure( 'database_error' );
			}
			if ( false === $product ) {
				return StockConversionResult::failure( 'product_missing' );
			}
			if ( $post_type !== $product['post_type'] ) {
				return StockConversionResult::failure( 'product_invalid' );
			}
			$stock = $this->nonnegative_integer( $product['stock'] );
			if ( null === $stock ) {
				return StockConversionResult::failure( 'stock_invalid' );
			}
			$stocks[ $post_id ] = $stock;
		}

		$active = $this->reservations->get_active_for_posts_for_conversion_for_update( $post_ids, $current_utc );
		if ( false === $active ) {
			return StockConversionResult::failure( 'database_error' );
		}
		$locked_reservations = $this->reservations->get_by_ids_for_conversion_for_update( $reservation_ids );
		if ( false === $locked_reservations ) {
			return StockConversionResult::failure( 'database_error' );
		}
		$validation = $this->validator->validate( $order_id, $items, $locked_reservations );
		if ( ! $validation->is_valid() ) {
			return StockConversionResult::failure( $validation->code() );
		}

		$converting_ids = array_fill_keys( $validation->reservation_ids(), true );
		$conversion_reservations = array();
		foreach ( $locked_reservations as $reservation ) {
			$conversion_reservations[ $reservation->id() ] = $reservation->status();
		}
		ksort( $conversion_reservations, SORT_NUMERIC );
		$other = array_fill_keys( $post_ids, 0 );
		foreach ( $active as $reservation ) {
			if ( isset( $converting_ids[ $reservation->id() ] ) ) {
				continue;
			}
			$post_id = $reservation->post_id();
			if ( ! isset( $other[ $post_id ] ) || $reservation->quantity() > PHP_INT_MAX - $other[ $post_id ] ) {
				return StockConversionResult::failure( 'stock_invalid' );
			}
			$other[ $post_id ] += $reservation->quantity();
		}

		$new_stocks = array();
		foreach ( $validation->quantities() as $post_id => $quantity ) {
			$stock = $stocks[ $post_id ] ?? -1;
			$reserved_other = $other[ $post_id ] ?? 0;
			if ( $stock < $quantity || $reserved_other > $stock - $quantity ) {
				return StockConversionResult::failure( 'insufficient_stock' );
			}
			$new_stocks[ $post_id ] = $stock - $quantity;
		}

		// Internal conversion owner validation/acquisition, after every stock/reservation lock,
		// before the first physical write. Other primitive callers retain their own authority.
		if ($before_decrement !== null) {
			$code = $before_decrement();
			if ($code !== null) return StockConversionResult::failure($code);
		}

		foreach ( $new_stocks as $post_id => $new_stock ) {
			if ( ! $this->catalog->decrement_stock( $post_id, $stocks[ $post_id ], $validation->quantities()[ $post_id ] ) ) {
				return StockConversionResult::failure( 'database_error' );
			}
		}
		return StockConversionResult::success( $new_stocks, $conversion_reservations );
	}

	private function nonnegative_integer( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9]\d*)$/', $value ) ) {
			return null;
		}
		$value = filter_var( $value, FILTER_VALIDATE_INT );
		return is_int( $value ) && $value >= 0 ? $value : null;
	}
}
