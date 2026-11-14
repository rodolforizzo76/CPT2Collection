<?php
/** Shared exact reservation-set validator. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
final class ReservationSetValidator {
	/** @param OrderItem[] $items @param ReservationRecord[] $reservations */
	public function validate( int $order_id, array $items, array $reservations, string $now ): ReservationSetValidationResult {
		if ( array() === $reservations || count( $items ) > count( $reservations ) ) { return ReservationSetValidationResult::invalid( 'reservation_missing' ); }
		if ( count( $items ) !== count( $reservations ) ) { return ReservationSetValidationResult::invalid( 'reservation_conflict' ); }
		$expected = array(); foreach ( $items as $item ) { if ( ! $item instanceof OrderItem || $item->order_id() !== $order_id ) { return ReservationSetValidationResult::invalid( 'reservation_conflict' ); } $expected[ $item->id() ] = $item; }
		$seen = array(); $deadline = null;
		foreach ( $reservations as $reservation ) {
			if ( ! $reservation instanceof ReservationRecord ) { return ReservationSetValidationResult::invalid( 'reservation_conflict' ); }
			$item_id = $reservation->order_item_id();
			if ( isset( $seen[ $item_id ] ) || ! isset( $expected[ $item_id ] ) || $reservation->order_id() !== $order_id || $reservation->post_id() !== $expected[ $item_id ]->post_id() || $reservation->quantity() !== $expected[ $item_id ]->quantity() || null !== $reservation->released_at() || null !== $reservation->converted_at() ) { return ReservationSetValidationResult::invalid( 'reservation_conflict' ); }
			if ( ReservationStatuses::ACTIVE !== $reservation->status() ) { return ReservationSetValidationResult::invalid( 'reservation_conflict' ); }
			if ( ! $reservation->is_active_at( $now ) ) { return ReservationSetValidationResult::invalid( 'reservation_expired' ); }
			$deadline = null === $deadline || $reservation->expires_at() < $deadline ? $reservation->expires_at() : $deadline; $seen[ $item_id ] = true;
		}
		return count( $seen ) === count( $expected ) && null !== $deadline ? ReservationSetValidationResult::valid( $deadline ) : ReservationSetValidationResult::invalid( 'reservation_missing' );
	}
}
