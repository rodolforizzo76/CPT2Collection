<?php
/** Checkout preparation application service. @package CPT2Collection */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

use Throwable;

defined( 'ABSPATH' ) || exit;
require_once __DIR__.'/class-checkout-context-token.php';

final class CheckoutService {
	private CartRepository $carts;
	private CommerceRules $rules;
	private OrderRepository $orders;
	private TransactionManager $transactions;
	private ReservationAcquirer $reservations;
	private UtcClock $clock;
	private ReservationRepository $reservation_repository;
	private PaymentSessionRepository $sessions;
	private PaymentEventRepository $events;
	private ReservationSetValidator $reservation_validator;
	private OrderDeletionRegistry $deletions;

	public function __construct( CartRepository $carts, CommerceRules $rules, OrderRepository $orders, TransactionManager $transactions, ReservationAcquirer $reservations, UtcClock $clock, ReservationRepository $reservation_repository, PaymentSessionRepository $sessions, PaymentEventRepository $events, OrderDeletionRegistry $deletions ) {
		$this->carts        = $carts;
		$this->rules        = $rules;
		$this->orders       = $orders;
		$this->transactions = $transactions;
		$this->reservations = $reservations;
		$this->clock        = $clock;
		$this->reservation_repository = $reservation_repository;
		$this->sessions     = $sessions;
		$this->events       = $events;
		$this->deletions    = $deletions;
		$this->reservation_validator = new ReservationSetValidator();
	}

	public function prepare( CartRecord $cart, string $idempotency_key, ?string $browser_context = null ): CheckoutPreparationResult {
		if ( $cart->id() <= 0 || ! $this->valid_idempotency_key( $idempotency_key ) ) {
			return CheckoutPreparationResult::failure( 'invalid_checkout_attempt', __( 'The checkout attempt identifier is invalid.', 'cpt2collection' ) );
		}
		if ( ! $this->transactions->supports_order_persistence() || ! $this->transactions->begin_commerce_shared() ) {
			return $this->transactions->is_outcome_uncertain() ? $this->transaction_outcome_unknown() : CheckoutPreparationResult::failure( 'order_persistence_failed', __( 'The order could not be persisted atomically.', 'cpt2collection' ) );
		}

		$context = $this->transactions->commerce_context();
		if ( $cart->epoch_id() !== $context->epoch_id() ) { return $this->abort( 'commerce_epoch_mismatch', __( 'The cart belongs to another commerce epoch.', 'cpt2collection' ) ); }
		try {
			$locked = $this->carts->find_for_update( $cart->id() );
			if ( ! $locked instanceof CartRecord ) { return $this->abort( 'database_error', __( 'The cart could not be locked for checkout.', 'cpt2collection' ) ); }
   $valid_context=$browser_context===null||CheckoutContextToken::verify($browser_context,$locked,$context,$this->clock->now_timestamp());
   if(!$valid_context){
	// Only the exact caller's committed renewal may recover a lost response.
	// Never select a newer Order from a stale token alone.
	$own=$this->orders->find_by_checkout_attempt($locked->id(),hash('sha256',$idempotency_key));
	$valid_context=$own instanceof OrderRecord&&$own->cart_revision()===$locked->content_revision()&&$own->checkout_generation()===$locked->checkout_generation()&&CheckoutContextToken::previous_generation($browser_context,$locked,$context);
   }
   if(!CheckoutContextToken::same_owner($cart,$locked)||$locked->expires_at()<=$this->clock->now_mysql()||!$valid_context)return $this->abort('stale_checkout_context',__('The checkout context changed or expired. Refresh the cart before trying again.','cpt2collection'));

			$revision = $locked->content_revision();
			$generation = $locked->checkout_generation();
			$attempt_hash = hash( 'sha256', $idempotency_key );
			$deleted = $this->deletions->find_checkout_block_for_update( $locked->id(), $attempt_hash, $revision );
			if ( false === $deleted ) { return $this->abort( 'database_error', __( 'The deleted checkout history could not be read.', 'cpt2collection' ) ); }
			if ( $deleted instanceof OrderDeletionRecord ) { return $this->abort( 'checkout_order_deleted', __( 'This checkout belongs to an order that was deleted. Change the cart before checking out again.', 'cpt2collection' ) ); }
			$current = $this->orders->find_revision_generation_for_update( $locked->id(), $revision, $generation );
			if ( false === $current ) { return $this->abort( 'database_error', __( 'The checkout state could not be read.', 'cpt2collection' ) ); }
			$attempt_order = $this->orders->find_by_checkout_attempt( $locked->id(), $attempt_hash );
			if ( $attempt_order instanceof OrderRecord && ( ! $current instanceof OrderRecord || $attempt_order->id() !== $current->id() ) ) {
				return $this->abort( 'checkout_attempt_conflict', __( 'This checkout attempt identifier belongs to a different cart revision.', 'cpt2collection' ), $attempt_order->id() );
			}
			if ( $current instanceof OrderRecord ) {
				$decision = $this->current_order_decision( $current );
				if ( $decision instanceof CheckoutPreparationResult ) { return $this->abort_result( $decision ); }
				if ( 'replay' === $decision ) {
	 $next_context=$browser_context===null?null:CheckoutContextToken::issue($locked,$context,$this->clock->now_timestamp());
					if ( ! $this->transactions->commit() ) { return $this->transaction_outcome_unknown(); }
					return $this->transactions->with_commerce_context( $context, fn() => $this->replay( $current ) )->with_context($context)->with_browser_context($next_context);
				}
				if ( $attempt_order instanceof OrderRecord ) {
					return $this->abort( 'checkout_attempt_conflict', __( 'This checkout attempt identifier cannot be reused for a new checkout generation.', 'cpt2collection' ), $attempt_order->id() );
				}
				if ( ! $this->carts->advance_checkout_generation( $locked->id(), $revision, $generation, $this->clock->now_mysql() ) ) { return $this->abort( 'order_persistence_failed', __( 'The checkout generation could not be renewed.', 'cpt2collection' ) ); }
				++$generation;
			}

			$items = $this->carts->get_items_for_checkout( $locked->id() );
			if ( false === $items ) { return $this->abort( 'database_error', __( 'The cart could not be read for checkout.', 'cpt2collection' ) ); }
			$rule = $this->rules->can_checkout( $items );
			if ( ! $rule->is_allowed() ) {
				$code = 'empty_cart' === $rule->get_code() ? 'cart_empty' : 'checkout_not_allowed';
				return $this->abort_result( CheckoutPreparationResult::failure( $code, $rule->get_message(), null, $rule->get_code() ) );
			}
			$snapshot = $this->build_snapshot( $items );
			if ( null === $snapshot ) { return $this->abort( 'snapshot_invalid', __( 'The cart could not be converted into a valid order snapshot.', 'cpt2collection' ) ); }
			$created = $this->orders->create( $locked->id(), OrderStatuses::PENDING, $snapshot['currency'], $snapshot['subtotal_minor'], $snapshot['total_minor'], $snapshot['created_at'], $snapshot['created_at'], $attempt_hash, $revision, $generation );
			if ( false === $created ) { return $this->abort( 'order_persistence_failed', __( 'The order could not be created.', 'cpt2collection' ) ); }
			foreach ( $snapshot['items'] as $item ) {
				if ( false === $this->orders->insert_item( $created->id(), $item['post_id'], $item['item_name'], $item['unit_price_minor'], $item['quantity'], $item['line_subtotal_minor'], $item['line_total_minor'], $snapshot['currency'], $snapshot['created_at'], $item['source_cart_item_id'], $item['source_cart_item_version'] ) ) {
					return $this->abort( 'order_persistence_failed', __( 'The complete order snapshot could not be persisted.', 'cpt2collection' ) );
				}
			}
   $next_cart=$this->carts->find_for_update($locked->id());
   if(!$next_cart instanceof CartRecord)return $this->abort('database_error',__( 'The committed checkout context could not be captured.', 'cpt2collection' ));
   $next_context=$browser_context===null?null:CheckoutContextToken::issue($next_cart,$context,$this->clock->now_timestamp());
			if ( ! $this->transactions->commit() ) { return $this->transaction_outcome_unknown(); }
		} catch ( Throwable $error ) {
			if ( ! $this->rollback_confirmed() ) { return $this->transaction_outcome_unknown(); }
			return CheckoutPreparationResult::failure( 'database_error', __( 'The order could not be persisted because of a database error.', 'cpt2collection' ) );
		}
		return $this->transactions->with_commerce_context( $context, fn() => $this->acquire( $created ) )->with_context($context)->with_browser_context($next_context);
	}

	/** @return 'replay'|'renew'|CheckoutPreparationResult */
	private function current_order_decision( OrderRecord $order ) {
		if ( in_array( $order->status(), array( OrderStatuses::PENDING, OrderStatuses::AWAITING_PAYMENT ), true ) ) { return 'replay'; }
		if ( OrderStatuses::PAID === $order->status() ) { return CheckoutPreparationResult::failure( 'checkout_already_paid', __( 'This cart revision has already been paid.', 'cpt2collection' ), $order->id() ); }
		if ( ! in_array( $order->status(), array( OrderStatuses::FAILED, OrderStatuses::EXPIRED, OrderStatuses::CANCELLED ), true ) || ! $this->renewal_is_safe( $order ) ) {
			return CheckoutPreparationResult::failure( 'checkout_renewal_blocked', __( 'A new checkout cannot be created until the prior checkout is conclusively safe.', 'cpt2collection' ), $order->id() );
		}
		return 'renew';
	}

	private function renewal_is_safe( OrderRecord $order ): bool {
		$sessions = $this->sessions->get_for_order_for_update( $order->id() );
		$reservations = $this->reservation_repository->get_for_order_for_update( $order->id() );
		if ( false === $sessions || false === $reservations || $this->unsafe_reservations( $reservations ) ) { return false; }
		if ( OrderStatuses::FAILED === $order->status() || OrderStatuses::CANCELLED === $order->status() ) { return array() === $sessions; }
		if ( 1 !== count( $sessions ) ) { return false; }
		$session = $sessions[0];
		$event = $this->events->has_processed_expiration_for_session( $session->id() );
		return true === $event && PaymentSessionStatuses::EXPIRED === $session->status() && null === $session->active_slot()
			&& null === $session->provider_payment_id() && null === $session->payment_confirmed_at()
			&& null === $session->reconciliation_required_at() && null === $session->reconciliation_code() && null === $session->reconciliation_event_id()
			&& null !== $session->expires_at() && $session->expires_at() <= $this->clock->now_mysql();
	}

	/** @param ReservationRecord[] $reservations */
	private function unsafe_reservations( array $reservations ): bool {
		foreach ( $reservations as $reservation ) { if ( in_array( $reservation->status(), array( ReservationStatuses::ACTIVE, ReservationStatuses::CONVERTED ), true ) ) { return true; } }
		return false;
	}

	private function abort( string $code, string $message, ?int $order_id = null ): CheckoutPreparationResult {
		return $this->abort_result( CheckoutPreparationResult::failure( $code, $message, $order_id ) );
	}

	private function abort_result( CheckoutPreparationResult $result ): CheckoutPreparationResult {
		return $this->rollback_confirmed() ? $result : $this->transaction_outcome_unknown();
	}

	private function replay( OrderRecord $order ): CheckoutPreparationResult {
		if ( OrderStatuses::PENDING === $order->status() ) {
			return $this->acquire( $order );
		}
		if ( OrderStatuses::AWAITING_PAYMENT === $order->status() ) {
			return CheckoutPreparationResult::success( 'checkout_prepared', __( 'This checkout is already awaiting payment.', 'cpt2collection' ), $order->id() );
		}
		if ( OrderStatuses::FAILED === $order->status() ) {
			return CheckoutPreparationResult::failure( 'reservation_failed', __( 'This checkout attempt has already failed. Start a new attempt to retry.', 'cpt2collection' ), $order->id() );
		}

		return CheckoutPreparationResult::failure( 'checkout_attempt_conflict', __( 'This checkout attempt already belongs to an order in another lifecycle state.', 'cpt2collection' ), $order->id() );
	}

	private function acquire( OrderRecord $order ): CheckoutPreparationResult {
		if ( $this->transactions->is_active() ) {
			return CheckoutPreparationResult::failure( 'database_error', __( 'Reservation acquisition could not start safely.', 'cpt2collection' ), $order->id() );
		}
		$result = $this->reservations->acquire( $order->id() );
		if ( $result->is_success() && in_array( $result->get_code(), array( 'reservation_acquired', 'reservation_already_active' ), true ) ) {
			return CheckoutPreparationResult::success( 'checkout_prepared', __( 'Checkout preparation completed.', 'cpt2collection' ), $order->id(), $result->get_code() );
		}
		if ( 'transaction_outcome_unknown' === $result->get_code() || $this->transactions->is_outcome_uncertain() ) {
			return CheckoutPreparationResult::failure( 'transaction_outcome_unknown', __( 'The database did not confirm the reservation transaction outcome.', 'cpt2collection' ), $order->id(), null, $result->get_code() );
		}

		// Admission/storage failures are infrastructure failures, not failed orders.
		if ( '' !== $this->transactions->admission_error() || 'transactions_not_supported' === $result->get_code() ) {
			return CheckoutPreparationResult::failure( 'database_error', __( 'Reservation processing is temporarily unavailable.', 'cpt2collection' ), $order->id(), null, $result->get_code() );
		}

		return $this->finalize_reservation_failure( $order, $result );
	}

	private function finalize_reservation_failure( OrderRecord $order, ReservationOperationResult $failure ): CheckoutPreparationResult {
		if ( ! $this->transactions->begin_commerce_shared() ) {
			return $this->transactions->is_outcome_uncertain() ? $this->transaction_outcome_unknown() : CheckoutPreparationResult::failure( 'database_error', __( 'Reservation failure finalization could not start.', 'cpt2collection' ), $order->id(), null, $failure->get_code() );
		}

		try {
			$locked = $this->orders->find_for_update( $order->id() );
			if ( false === $locked ) { return $this->abort_finalization( 'database_error', __( 'The order could not be locked while finalizing the reservation failure.', 'cpt2collection' ), $order->id(), $failure->get_code() ); }
			if ( ! $locked instanceof OrderRecord || OrderStatuses::PENDING !== $locked->status() ) { return $this->abort_finalization( 'checkout_attempt_conflict', __( 'The order lifecycle changed while finalizing the reservation failure.', 'cpt2collection' ), $order->id(), $failure->get_code() ); }

			$items = $this->orders->get_items_for_acquisition( $order->id() );
			if ( false === $items ) { return $this->abort_finalization( 'database_error', __( 'The immutable order snapshot could not be read while finalizing the reservation failure.', 'cpt2collection' ), $order->id(), $failure->get_code() ); }
			if ( ! $this->valid_order_snapshot( $locked, $items ) ) { return $this->abort_finalization( 'checkout_attempt_conflict', __( 'The immutable order snapshot is inconsistent while finalizing the reservation failure.', 'cpt2collection' ), $order->id(), $failure->get_code() ); }
			$reservations = $this->reservation_repository->get_for_order_and_items_for_acquisition( $order->id(), array_map( static fn( OrderItem $item ): int => $item->id(), $items ) );
			if ( false === $reservations ) { return $this->abort_finalization( 'database_error', __( 'The reservation state could not be verified while finalizing the reservation failure.', 'cpt2collection' ), $order->id(), $failure->get_code() ); }

			if ( array() !== $reservations ) {
				$coverage = $this->reservation_validator->validate( $order->id(), $items, $reservations, $this->clock->now_mysql() );
				if ( ! $coverage->is_valid() ) { return $this->abort_finalization( $coverage->code(), __( 'The reservation state changed incompatibly while finalizing checkout.', 'cpt2collection' ), $order->id(), $failure->get_code() ); }
				if ( ! $this->transactions->commit() ) { return $this->transaction_outcome_unknown(); }
				return CheckoutPreparationResult::success( 'checkout_prepared', __( 'Checkout preparation completed.', 'cpt2collection' ), $order->id(), 'reservation_already_active' );
			}

			$failed_at = $this->clock->now_mysql();
			if ( ! $this->orders->update_status( $order->id(), OrderStatuses::PENDING, OrderStatuses::FAILED, $failed_at, null, null, null, $failed_at ) ) {
				return $this->abort_finalization( 'database_error', __( 'Reservation failed and the order failure state could not be persisted.', 'cpt2collection' ), $order->id(), $failure->get_code() );
			}
			if ( ! $this->transactions->commit() ) { return $this->transaction_outcome_unknown(); }
			return CheckoutPreparationResult::failure( 'reservation_failed', __( 'Checkout preparation could not reserve every item.', 'cpt2collection' ), $order->id(), null, $failure->get_code() );
		} catch ( Throwable $error ) {
			return $this->abort_finalization( 'database_error', __( 'Reservation failure finalization encountered a database error.', 'cpt2collection' ), $order->id(), $failure->get_code() );
		}
	}

	private function abort_finalization( string $code, string $message, int $order_id, string $reservation_code ): CheckoutPreparationResult {
		if ( ! $this->rollback_confirmed() ) { return $this->transaction_outcome_unknown(); }
		return CheckoutPreparationResult::failure( $code, $message, $order_id, null, $reservation_code );
	}

	/** @param OrderItem[] $items */
	private function valid_order_snapshot( OrderRecord $order, array $items ): bool {
		if ( array() === $items ) { return false; }
		$subtotal = 0;
		$total    = 0;
		foreach ( $items as $item ) {
			if ( ! $item instanceof OrderItem || $item->order_id() !== $order->id() || $item->currency() !== $order->currency() || $item->unit_price_minor() < 1 || $item->quantity() < 1 || $item->quantity() > intdiv( PHP_INT_MAX, $item->unit_price_minor() ) ) { return false; }
			$line = $item->unit_price_minor() * $item->quantity();
			if ( $item->line_subtotal_minor() !== $line || $item->line_total_minor() !== $line || $line > PHP_INT_MAX - $subtotal || $line > PHP_INT_MAX - $total ) { return false; }
			$subtotal += $line;
			$total    += $line;
		}
		return $subtotal === $order->subtotal_minor() && $total === $order->total_minor();
	}

	private function rollback_confirmed(): bool {
		return $this->transactions->rollback() && ! $this->transactions->is_outcome_uncertain();
	}

	private function transaction_outcome_unknown(): CheckoutPreparationResult {
  $this->carts->discard_connection();
		return CheckoutPreparationResult::failure( 'transaction_outcome_unknown', __( 'The database did not confirm the checkout transaction outcome.', 'cpt2collection' ) );
	}

	/** @param CartItem[] $items @return array{currency:string,subtotal_minor:int,total_minor:int,created_at:string,items:array<int,array{source_cart_item_id:int,source_cart_item_version:int,post_id:int,item_name:string,unit_price_minor:int,quantity:int,line_subtotal_minor:int,line_total_minor:int}>}|null */
	private function build_snapshot( array $items ): ?array {
		$currency = Money::get_currency();
		$subtotal = 0;
		$total    = 0;
		$output   = array();
		foreach ( $items as $item ) {
			if ( ! $item instanceof CartItem || $item->id() <= 0 || $item->quantity() < 1 ) {
				return null;
			}
			$title = trim( (string) get_the_title( $item->post_id() ) );
			$price = $this->positive_integer( get_post_meta( $item->post_id(), MetaKeys::PRICE_MINOR, true ) );
			if ( '' === $title || null === $price || $item->quantity() > intdiv( PHP_INT_MAX, $price ) ) {
				return null;
			}
			$line = $price * $item->quantity();
			if ( $line > PHP_INT_MAX - $subtotal || $line > PHP_INT_MAX - $total ) {
				return null;
			}
			$subtotal += $line;
			$total    += $line;
			$output[] = array( 'source_cart_item_id' => $item->id(), 'source_cart_item_version' => $item->content_version(), 'post_id' => $item->post_id(), 'item_name' => $title, 'unit_price_minor' => $price, 'quantity' => $item->quantity(), 'line_subtotal_minor' => $line, 'line_total_minor' => $line );
		}

		return array( 'currency' => $currency, 'subtotal_minor' => $subtotal, 'total_minor' => $total, 'created_at' => $this->clock->now_mysql(), 'items' => $output );
	}

	private function positive_integer( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[1-9]\d*\z/', $value ) ) {
			return null;
		}
		$integer = filter_var( $value, FILTER_VALIDATE_INT );

		return is_int( $integer ) && $integer > 0 ? $integer : null;
	}

	private function valid_idempotency_key( string $key ): bool {
		return strlen( $key ) >= 32 && strlen( $key ) <= 128 && 1 === preg_match( '/\A[A-Za-z0-9_-]+\z/', $key );
	}
}
