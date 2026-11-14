<?php
/** Provider-neutral verified-payment to local-sale orchestration. @package CPT2Collection */
declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

use Throwable;

defined( 'ABSPATH' ) || exit;
require_once __DIR__.'/class-payment-financial-stage-inspection.php';

final class SaleConversionService {
	private const MAX_ATTEMPTS = 3;
	private const RETRYABLE_DATABASE_ERRORS = array( 1205, 1213 );

	private OrderRepository $orders;
	private ReservationRepository $reservations;
	private PaymentSessionRepository $sessions;
	private PaymentEventRepository $events;
	private TransactionManager $transactions;
	private StockConversionProcessor $stock;
	private ConversionReservationSetValidator $validator;
	private PostTypes $post_types;
	private UtcClock $clock;
	private ?OrderDeletionRegistry $deletions;
	private int $last_database_error = 0;
	private PaymentEventContinuation $continuation;
	private ?CommittedOperation $operation = null;
	private ?PaymentFinancialStageInspection $stage = null;

	public function __construct( OrderRepository $orders, ReservationRepository $reservations, PaymentSessionRepository $sessions, PaymentEventRepository $events, TransactionManager $transactions, StockConversionProcessor $stock, ConversionReservationSetValidator $validator, PostTypes $post_types, UtcClock $clock, ?OrderDeletionRegistry $deletions = null, ?PaymentEventContinuation $continuation = null ) {
		$this->orders       = $orders;
		$this->reservations = $reservations;
		$this->sessions     = $sessions;
		$this->events       = $events;
		$this->transactions = $transactions;
		$this->stock        = $stock;
		$this->validator    = $validator;
		$this->post_types   = $post_types;
		$this->clock        = $clock;
		$this->deletions    = $deletions;
		$this->continuation = $continuation ?? $events->continuation();
	}

	public function convert( int $order_id, int $payment_session_id, int $payment_event_id, ?CommittedOperation $operation = null, mixed $customer_email = null, mixed $customer_name = null ): SaleConversionResult {
		$this->operation = $operation;
		$this->stage = null;
		if ($operation?->receipt === null || $operation->claim->order_id() !== $order_id || $operation->claim->payment_session_id() !== $payment_session_id) {
			return SaleConversionResult::failure('continuation_verification_required',$order_id,$payment_session_id,$payment_event_id);
		}

		if ( $order_id <= 0 || $payment_session_id <= 0 || $payment_event_id <= 0 ) {
			return SaleConversionResult::failure( 'payment_association_mismatch', $order_id > 0 ? $order_id : null, $payment_session_id > 0 ? $payment_session_id : null, $payment_event_id > 0 ? $payment_event_id : null, true );
		}
		if ( $this->transactions->is_active() || ! $this->transactions->supports_sale_conversion() ) {
			return SaleConversionResult::failure( $this->transactions->is_outcome_uncertain() ? 'transaction_outcome_unknown' : 'transactions_not_supported', $order_id, $payment_session_id, $payment_event_id, true );
		}
		$post_type = $this->post_types->get_selected_post_type();
		if ( '' === $post_type ) {
			return SaleConversionResult::failure( 'product_invalid', $order_id, $payment_session_id, $payment_event_id, true );
		}

		$customer_name = self::validated_customer_name( $customer_name );
		$snapshot_email = is_string( $customer_email ) && strlen( $customer_email ) <= 254
			&& preg_match( '/\A[\x21-\x7e]+\z/', $customer_email ) === 1
			&& false !== filter_var( $customer_email, FILTER_VALIDATE_EMAIL ) && is_email( $customer_email )
			? $customer_email : null;
		$customer_mail_to = PaymentNotificationDispatcher::valid_address( $customer_email );
		$admin_email = PaymentNotificationDispatcher::valid_address( get_option( 'admin_email' ) );
		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			$this->last_database_error = 0;
			$result = $this->attempt( $order_id, $payment_session_id, $payment_event_id, $post_type, $customer_mail_to, $admin_email, $customer_name, $snapshot_email );
			if ( ! $result->has_confirmed_rollback() || 'database_error' !== $result->code() || ! in_array( $this->last_database_error, self::RETRYABLE_DATABASE_ERRORS, true ) || self::MAX_ATTEMPTS === $attempt ) {
				return $result;
			}
			usleep( random_int( 25_000 * $attempt, 75_000 * $attempt ) );
		}

		return SaleConversionResult::failure( 'database_error', $order_id, $payment_session_id, $payment_event_id, true );
	}

	/** K12: fresh exact admission and a read-only coherence probe. No registry binder. */
	public function probe_processed(PaymentEventRecord $event, CommittedOperation $operation): SaleConversionResult {
		$this->operation=$operation;$this->stage=null;
		$o=$operation->claim->order_id();$sid=$operation->claim->payment_session_id();$eid=$event->id();
		if($this->transactions->is_active())return SaleConversionResult::failure($this->transactions->is_outcome_uncertain()?'transaction_outcome_unknown':'transaction_already_active',$o,$sid,$eid);
		try {
			$admission=$this->continuation->begin($operation,$event,$this->transactions);
			if($admission->status!=='admitted')return SaleConversionResult::failure(PaymentEventContinuation::disposition($admission),$o,$sid,$eid);
			$order=$this->orders->find_for_update($o);$session=$order instanceof OrderRecord?$this->sessions->find_for_update($sid):false;
			$locked=$session instanceof PaymentSessionRecord?$this->events->find_for_update($eid):false;
			if(!$order instanceof OrderRecord||!$session instanceof PaymentSessionRecord||!$locked instanceof PaymentEventRecord||$locked->processing_status()!==PaymentEventStatuses::PROCESSED||!$this->continuation->validate($operation,$event,$order,$session,$locked))return $this->rollback_failure($this->continuation->validation_failure_code()==='database_error'?'database_error':'processed_state_incoherent',$o,$sid,$eid,false);
			$this->stage=new PaymentFinancialStageInspection($eid,'processed_replay');
			$inspection=$this->events->inspect_financial($operation,$this->stage);
			if($inspection->status!=='logically_completed')return $this->rollback_failure($inspection->status==='database_error'?'database_error':($inspection->status==='conflict'?'processed_state_incoherent':'continuation_verification_required'),$o,$sid,$eid,false);
			if(!$this->transactions->rollback())return $this->unknown($o,$sid,$eid);
			return SaleConversionResult::success($locked->event_type()==='checkout.session.expired'?'expiration_already_processed':'sale_already_converted',$o,$sid,$eid,[],true);
		} catch(Throwable $error) {return $this->transactions->is_active()?$this->database_failure($o,$sid,$eid):SaleConversionResult::failure('database_error',$o,$sid,$eid);}
	}

	private static function validated_customer_name( mixed $name ): ?string {
		if ( ! is_string( $name ) || strlen( $name ) > 1020 || preg_match( '/\p{Cc}/u', $name ) !== 0 ) {
			return null;
		}
		$name = preg_replace( '/\A\p{Z}+|\p{Z}+\z/u', '', $name );
		return is_string( $name ) && preg_match( '/\A.{1,255}\z/us', $name ) === 1 ? $name : null;
	}

	private function attempt( int $order_id, int $session_id, int $event_id, string $post_type, ?string $customer_mail_to, ?string $admin_email, ?string $customer_name, ?string $snapshot_email ): SaleConversionResult {
		$this->stage = null;
		$admission = $this->continuation->begin_financial($this->operation,$this->transactions);
		if ($admission->status !== 'admitted') {
			 $code=PaymentEventContinuation::disposition($admission);
			return in_array($code,['order_deleted','commerce_reset_fenced'],true)?SaleConversionResult::success($code,$order_id,$session_id,$event_id,[],true):SaleConversionResult::failure($code,$order_id,$session_id,$event_id);
		}

		try {
			$order = $this->orders->find_for_update( $order_id );
			if ( false === $order ) { return $this->database_failure( $order_id, $session_id, $event_id ); }
			if ( null === $order ) { return $this->rollback_failure( 'order_not_found', $order_id, $session_id, $event_id ); }

			$session = $this->sessions->find_for_update( $session_id );
			if ( false === $session ) { return $this->database_failure( $order_id, $session_id, $event_id ); }
			if ( null === $session ) { return $this->rollback_failure( 'payment_session_not_found', $order_id, $session_id, $event_id ); }

			$later=$this->sessions->has_later_attempt_for_update($order_id,$session_id);
			if($later===null)return $this->database_failure($order_id,$session_id,$event_id);
			if($later)return $this->rollback_failure('continuation_verification_required',$order_id,$session_id,$event_id,false);

			$event = $this->events->find_for_update( $event_id );
			if ( false === $event ) { return $this->database_failure( $order_id, $session_id, $event_id ); }
			if ( null === $event ) { return $this->rollback_failure( 'payment_event_not_found', $order_id, $session_id, $event_id ); }

			if (!PaymentEventContinuation::business_tuple($this->operation,$order,$session,$event) || $event->ownership_state()!=='bound' || !PaymentFinancialStageInspection::paid_receipt($event)) {
				return $this->rollback_failure('payment_identity_conflict',$order_id,$session_id,$event_id);
			}

			$code = $this->association_code( $order, $session, $event );
			if ( null !== $code ) { return $this->rollback_failure( $code, $order_id, $session_id, $event_id ); }

			$event_already_processed = PaymentEventStatuses::PROCESSED === $event->processing_status();
			if ( in_array( $event->processing_status(), array( PaymentEventStatuses::RECEIVED, PaymentEventStatuses::RETRYABLE_FAILED ), true ) ) {
				// Acquisition is deferred until all product/reservation and original-owner locks exist.

			} elseif ( PaymentEventStatuses::PROCESSING === $event->processing_status() ) {
				// A committed processing row is anomalous and cannot be reclaimed without a lease/owner protocol.
				return $this->rollback_failure( 'event_already_processing', $order_id, $session_id, $event_id );
			} elseif ( PaymentEventStatuses::ATTENTION === $event->processing_status() ) {
				return $this->rollback_failure( 'event_requires_attention', $order_id, $session_id, $event_id );
			} elseif ( PaymentEventStatuses::IGNORED === $event->processing_status() ) {
				return $this->rollback_failure( 'event_ignored', $order_id, $session_id, $event_id, false );
			} elseif ( ! $event_already_processed ) {
				return $this->rollback_failure( 'event_state_conflict', $order_id, $session_id, $event_id );
			}

			/*
			 * This nonlocking check catches committed owners without taking a unique-key
			 * gap lock out of the global lock order. The database UNIQUE key remains the
			 * final race barrier; a concurrent loser fails session completion and rolls
			 * its entire transaction back before any mutation can commit.
			 */
			$owner = $this->sessions->find_by_provider_payment_id( $event->provider(), (string) $event->provider_payment_id() );
			if ( false === $owner ) { return $this->database_failure( $order_id, $session_id, $event_id ); }
			if ( $owner instanceof PaymentSessionRecord && $owner->id() !== $session_id ) { return $this->rollback_failure( 'payment_identity_conflict', $order_id, $session_id, $event_id ); }

			$items = $this->orders->get_items_for_acquisition( $order_id );
			if ( false === $items ) { return $this->database_failure( $order_id, $session_id, $event_id ); }
			if ( !PaymentFinancialStageInspection::items_match($this->operation,$order,$items) ) { return $this->rollback_failure( 'inconsistent_conversion_state', $order_id, $session_id, $event_id ); }

			if ( $event_already_processed && ( OrderStatuses::PAID !== $order->status() || PaymentSessionStatuses::COMPLETED !== $session->status() ) ) {
				return $this->rollback_failure( 'inconsistent_conversion_state', $order_id, $session_id, $event_id );
			}
			if ( OrderStatuses::PAID === $order->status() || PaymentSessionStatuses::COMPLETED === $session->status() ) {
				$converted = $this->reservations->discover_for_conversion( $order_id, array_map( static fn( OrderItem $item ): int => $item->id(), $items ) );
				if ( false === $converted ) { return $this->database_failure( $order_id, $session_id, $event_id ); }
				return $this->finish_idempotent( $order, $session, $event, $items, $converted, $event_already_processed );
			}
			if ( $event_already_processed ) { return $this->rollback_failure( 'inconsistent_conversion_state', $order_id, $session_id, $event_id ); }

			if ( in_array( $order->status(), array( OrderStatuses::PENDING, OrderStatuses::CANCELLED, OrderStatuses::FAILED ), true ) ) {
				return $this->rollback_failure( 'order_terminal_conflict', $order_id, $session_id, $event_id );
			}
			$normal = OrderStatuses::AWAITING_PAYMENT === $order->status() && PaymentSessionStatuses::ACTIVE === $session->status() && $session->active_slot()===1 && $order->expired_at()===null && $session->expired_at()===null;
			$late   = OrderStatuses::EXPIRED === $order->status() && PaymentSessionStatuses::EXPIRED === $session->status() && $session->active_slot()===null && $order->expired_at()!==null && $session->expired_at()!==null && $order->expired_at()===$session->expired_at();
			if ($session->failed_at()!==null || $session->cancelled_at()!==null || $order->paid_at()!==null || $session->payment_confirmed_at()!==null || $session->completed_at()!==null || ( ! $normal && ! $late )) { return $this->rollback_failure( 'session_state_conflict', $order_id, $session_id, $event_id ); }

			$now   = $this->clock->now_mysql();
			$stock = $this->stock->decrement( $order_id, $items, $post_type, $now, function() use ($order,$session,$event,$now): ?string {
				// Last locks: exact claim, then Event/payment/Session identities. No DML preceded these locks.
				if (!$this->continuation->validate($this->operation,$event,$order,$session,$event)) return $this->continuation->validation_failure_code();
				$this->stage = new PaymentFinancialStageInspection($event->id(),'conversion','sale_converted');
				return $this->events->mark_processing($event->id(),$event->processing_status(),$now) ? null : 'database_error';
			} );
			if ( ! $stock->is_success() ) {
				$code = 'reservation_already_converted' === $stock->code() ? 'inconsistent_conversion_state' : $stock->code();
				return 'database_error' === $code ? $this->database_failure( $order_id, $session_id, $event_id ) : $this->rollback_failure( $code, $order_id, $session_id, $event_id );
			}

			foreach ( $stock->reservations() as $reservation_id => $expected_status ) {
				if ( ! in_array( $expected_status, array( ReservationStatuses::ACTIVE, ReservationStatuses::EXPIRED ), true ) || ! $this->reservations->update_status( $reservation_id, $expected_status, ReservationStatuses::CONVERTED, $now, null, $now ) ) {
					return $this->database_failure( $order_id, $session_id, $event_id );
				}
			}
			if ( ! $this->orders->mark_paid( $order_id, $order->status(), $now, $event_id, $customer_mail_to, $admin_email, $customer_name, $snapshot_email ) ) { return $this->database_failure( $order_id, $session_id, $event_id ); }
			if ( ! $this->sessions->complete_verified_payment( $session_id, $session->status(), (string) $event->provider_payment_id(), $event->provider_event_id(), $now, (string) $event->currency(), (int) $event->amount_minor() ) ) { return $this->database_failure( $order_id, $session_id, $event_id ); }
			if ( ! $this->events->mark_terminal( $event_id, PaymentEventStatuses::PROCESSING, PaymentEventStatuses::PROCESSED, 'sale_converted', $now ) ) { return $this->database_failure( $order_id, $session_id, $event_id ); }

			if ( ! $this->finish_registry( $session, $event ) ) { return $this->rollback_failure('payment_identity_conflict',$order->id(),$session->id(),$event->id()); }
			if ( ! $this->transactions->commit() ) {
				return $this->unknown($order_id,$session_id,$event_id);
			}

			$this->invalidate_product_caches( array_keys( $stock->stocks() ) );
			return SaleConversionResult::success( 'sale_converted', $order_id, $session_id, $event_id, $stock->stocks() );
		} catch ( Throwable $error ) {
			return $this->database_failure( $order_id, $session_id, $event_id );
		}
	}

	private function finish_registry(PaymentSessionRecord $session,PaymentEventRecord $event):bool {
		$registry=$this->events->registry();$context=$this->transactions->commerce_context();$claim=$registry->claim($session->reference(),true);
		return is_object($claim)&&$registry->matches($claim,$session,$context)&&'bound'===$registry->bind_event($event,$claim,$this->clock->now_mysql())&&$registry->terminal($event->provider(),$event->provider_event_id(),'processed');
	}
	private function association_code( OrderRecord $order, PaymentSessionRecord $session, PaymentEventRecord $event ): ?string {
		if ( $session->order_id() !== $order->id() || $event->payment_session_id() !== $session->id() || $event->provider() !== $session->provider() || null === $event->provider_session_id() || $event->provider_session_id() !== $session->provider_session_id() || $event->local_reference() !== $session->reference() ) {
			return 'payment_association_mismatch';
		}
		if ( null === $event->provider_payment_id() || '' === trim( $event->provider_payment_id() ) ) { return 'payment_identity_conflict'; }
		if ( $event->currency() !== $session->currency() || $event->currency() !== $order->currency() || null === $event->currency() || ! isset( Money::get_currencies()[ $event->currency() ] ) ) { return 'payment_currency_mismatch'; }
		if ( $event->amount_minor() !== $session->amount_minor() || $event->amount_minor() !== $order->total_minor() || null === $event->amount_minor() || $event->amount_minor() < 0 ) { return 'payment_amount_mismatch'; }
		return null;
	}

	private function finish_idempotent( OrderRecord $order, PaymentSessionRecord $session, PaymentEventRecord $event, array $items, array $reservations, bool $event_already_processed ): SaleConversionResult {
		if ( OrderStatuses::PAID !== $order->status() || null === $order->paid_at() || PaymentSessionStatuses::COMPLETED !== $session->status() ) {
			return $this->rollback_failure( 'inconsistent_conversion_state', $order->id(), $session->id(), $event->id() );
		}
		if (($paid_code=PaymentFinancialStageInspection::paid_code($order,$session,$event))!==null) return $this->rollback_failure($paid_code,$order->id(),$session->id(),$event->id());
		$complete = null !== $session->provider_payment_id() && null !== $session->provider_event_id() && null !== $session->payment_confirmed_at() && null !== $session->confirmed_currency() && null !== $session->confirmed_amount_minor() && null !== $session->completed_at();
		if ( ! $complete ) { return $this->rollback_failure( 'inconsistent_conversion_state', $order->id(), $session->id(), $event->id() ); }
		if ( $session->provider_payment_id() !== $event->provider_payment_id() ) { return $this->rollback_failure( 'payment_identity_conflict', $order->id(), $session->id(), $event->id() ); }
		if ( $session->confirmed_currency() !== $event->currency() ) { return $this->rollback_failure( 'payment_currency_mismatch', $order->id(), $session->id(), $event->id() ); }
		if ( $session->confirmed_amount_minor() !== $event->amount_minor() ) { return $this->rollback_failure( 'payment_amount_mismatch', $order->id(), $session->id(), $event->id() ); }
		if ( ! $this->validator->validate_converted( $order->id(), $items, $reservations )->is_valid() ) { return $this->rollback_failure( 'inconsistent_conversion_state', $order->id(), $session->id(), $event->id() ); }

		$locked = $this->reservations->get_by_ids_for_conversion_for_update(array_map(static fn(ReservationRecord $r): int => $r->id(),$reservations));
		if ($locked === false) return $this->database_failure($order->id(),$session->id(),$event->id());
		if (!$this->validator->validate_converted($order->id(),$items,$locked)->is_valid()) return $this->rollback_failure('inconsistent_conversion_state',$order->id(),$session->id(),$event->id());
		if (!$this->continuation->validate($this->operation,$event,$order,$session,$event)) return $this->rollback_failure($this->continuation->validation_failure_code(),$order->id(),$session->id(),$event->id());
		if ($event_already_processed) {
			$this->stage = new PaymentFinancialStageInspection($event->id(),'processed_replay');
			$inspection = $this->events->inspect_financial($this->operation,$this->stage);
			if ($inspection->status !== 'logically_completed') return $this->rollback_failure($inspection->status==='database_error'?'database_error':($inspection->status==='conflict'?'inconsistent_conversion_state':'continuation_verification_required'),$order->id(),$session->id(),$event->id());
			if (!$this->transactions->rollback()) return $this->unknown($order->id(),$session->id(),$event->id());
		} else {
			// Prove the original completing Event before acknowledging a different paid Event.
			$inspection=$this->events->inspect_financial($this->operation,new PaymentFinancialStageInspection($event->id(),'paid_before_terminal'));
			if ($inspection->status !== 'logically_completed') return $this->rollback_failure($inspection->status==='database_error'?'database_error':($inspection->status==='conflict'?'inconsistent_conversion_state':'continuation_verification_required'),$order->id(),$session->id(),$event->id());
			$now = $this->clock->now_mysql();
			$this->stage = new PaymentFinancialStageInspection($event->id(),'conversion','sale_already_converted');
			if (!$this->events->mark_processing($event->id(),$event->processing_status(),$now) || !$this->events->mark_terminal($event->id(),PaymentEventStatuses::PROCESSING,PaymentEventStatuses::PROCESSED,'sale_already_converted',$now)) return $this->database_failure($order->id(),$session->id(),$event->id());
			if (!$this->finish_registry($session,$event)) return $this->rollback_failure('payment_identity_conflict',$order->id(),$session->id(),$event->id());
			if (!$this->transactions->commit()) return $this->unknown($order->id(),$session->id(),$event->id());
		}

		return SaleConversionResult::success( 'sale_already_converted', $order->id(), $session->id(), $event->id(), array(), true );
	}

	private function database_failure( int $order_id, int $session_id, int $event_id ): SaleConversionResult {
		$this->capture_database_error();
		return $this->rollback_failure( 'database_error', $order_id, $session_id, $event_id );
	}

	private function capture_database_error(): void {
		$this->last_database_error = $this->transactions->last_error_number();
	}

	private function rollback_failure( string $code, ?int $order_id, ?int $session_id, ?int $event_id, bool $attention = true ): SaleConversionResult {
		if ( ! $this->transactions->rollback() ) {
			$this->last_database_error = 0;
			return $this->unknown($order_id,$session_id,$event_id);
		}
		return SaleConversionResult::failure( $code, $order_id, $session_id, $event_id, $attention, true );
	}

	private function unknown(?int $order_id, ?int $session_id, ?int $event_id): SaleConversionResult {
		$inspection = null;
		if ($this->operation !== null && $this->stage !== null) $inspection = $this->continuation->inspect($this->operation,$this->stage);
		else $this->continuation->discard();
		return SaleConversionResult::uncertain($order_id,$session_id,$event_id,$inspection);
	}

	/** @param int[] $post_ids */
	private function invalidate_product_caches( array $post_ids ): void {
		foreach ( array_values( array_unique( array_map( 'intval', $post_ids ) ) ) as $post_id ) {
			try {
				wp_cache_delete( $post_id, 'post_meta' );
			} catch ( Throwable $error ) {
				// Cache invalidation is best-effort and must not alter a committed sale.
			}
		}
	}
}
