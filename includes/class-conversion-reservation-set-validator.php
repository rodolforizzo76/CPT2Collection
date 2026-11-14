<?php
/** Structural reservation validation for paid-order conversion. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class ConversionReservationSetValidator {
	/** @param OrderItem[] $items @param ReservationRecord[] $reservations */
	public function validate( int $order_id, array $items, array $reservations ): ConversionReservationValidationResult {
		if ( array() === $items || array() === $reservations || count( $reservations ) < count( $items ) ) {
			return ConversionReservationValidationResult::invalid( 'reservation_missing' );
		}
		if ( count( $reservations ) !== count( $items ) ) {
			return ConversionReservationValidationResult::invalid( 'reservation_structural_mismatch' );
		}
		$expected = array();
		foreach ( $items as $item ) {
			if ( ! $item instanceof OrderItem || $item->order_id() !== $order_id || isset( $expected[ $item->id() ] ) ) {
				return ConversionReservationValidationResult::invalid( 'reservation_structural_mismatch' );
			}
			$expected[ $item->id() ] = $item;
		}

		$seen = array();
		$ids = array();
		$quantities = array();
		foreach ( $reservations as $reservation ) {
			if ( ! $reservation instanceof ReservationRecord ) {
				return ConversionReservationValidationResult::invalid( 'reservation_structural_mismatch' );
			}
			if ( ReservationStatuses::RELEASED === $reservation->status() || null !== $reservation->released_at() ) {
				return ConversionReservationValidationResult::invalid( 'reservation_released' );
			}
			if ( ReservationStatuses::CONVERTED === $reservation->status() || null !== $reservation->converted_at() ) {
				return ConversionReservationValidationResult::invalid( 'reservation_already_converted' );
			}
			$item_id = $reservation->order_item_id();
			if ( isset( $seen[ $item_id ] ) || ! isset( $expected[ $item_id ] ) || $reservation->order_id() !== $order_id || $reservation->post_id() !== $expected[ $item_id ]->post_id() || $reservation->quantity() !== $expected[ $item_id ]->quantity() || ! in_array( $reservation->status(), array( ReservationStatuses::ACTIVE, ReservationStatuses::EXPIRED ), true ) ) {
				return ConversionReservationValidationResult::invalid( 'reservation_structural_mismatch' );
			}
			$post_id = $reservation->post_id();
			$current = $quantities[ $post_id ] ?? 0;
			if ( $reservation->quantity() > PHP_INT_MAX - $current ) {
				return ConversionReservationValidationResult::invalid( 'reservation_structural_mismatch' );
			}
			$quantities[ $post_id ] = $current + $reservation->quantity();
			$ids[] = $reservation->id();
			$seen[ $item_id ] = true;
		}
		if ( count( $seen ) !== count( $expected ) ) {
			return ConversionReservationValidationResult::invalid( 'reservation_missing' );
		}
		ksort( $quantities, SORT_NUMERIC );
		sort( $ids, SORT_NUMERIC );
		return ConversionReservationValidationResult::valid( $quantities, $ids );
	}

	/** Proves that an exact immutable item reservation set was already converted. */
	public function validate_converted( int $order_id, array $items, array $reservations ): ConversionReservationValidationResult {
		if ( array() === $items || array() === $reservations || count( $items ) !== count( $reservations ) ) {
			return ConversionReservationValidationResult::invalid( count( $reservations ) < count( $items ) ? 'reservation_missing' : 'reservation_structural_mismatch' );
		}
		$expected = array();
		foreach ( $items as $item ) {
			if ( ! $item instanceof OrderItem || $item->order_id() !== $order_id || isset( $expected[ $item->id() ] ) ) {
				return ConversionReservationValidationResult::invalid( 'reservation_structural_mismatch' );
			}
			$expected[ $item->id() ] = $item;
		}
		$seen = array(); $ids = array(); $quantities = array();
		foreach ( $reservations as $reservation ) {
			if ( ! $reservation instanceof ReservationRecord ) { return ConversionReservationValidationResult::invalid( 'reservation_structural_mismatch' ); }
			$item_id = $reservation->order_item_id();
			if ( isset( $seen[ $item_id ] ) || ! isset( $expected[ $item_id ] ) || $reservation->order_id() !== $order_id || $reservation->post_id() !== $expected[ $item_id ]->post_id() || $reservation->quantity() !== $expected[ $item_id ]->quantity() || ReservationStatuses::CONVERTED !== $reservation->status() || null === $reservation->converted_at() || null !== $reservation->released_at() ) {
				return ConversionReservationValidationResult::invalid( 'reservation_structural_mismatch' );
			}
			$current = $quantities[ $reservation->post_id() ] ?? 0;
			if ( $reservation->quantity() > PHP_INT_MAX - $current ) { return ConversionReservationValidationResult::invalid( 'reservation_structural_mismatch' ); }
			$quantities[ $reservation->post_id() ] = $current + $reservation->quantity(); $ids[] = $reservation->id(); $seen[ $item_id ] = true;
		}
		ksort( $quantities, SORT_NUMERIC ); sort( $ids, SORT_NUMERIC );
		return ConversionReservationValidationResult::valid( $quantities, $ids );
	}
}
