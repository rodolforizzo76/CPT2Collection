<?php
/** Atomic permanent deletion of one operational order aggregate. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class OrderDeletionService {
	public function __construct(
		private OrderDeletionRepository $repository,
		private OrderDeletionRegistry $deletions,
		private TransactionManager $transactions,
		private UtcClock $clock
	) {}

	public function delete( int $order_id, ?CommerceContext $expected = null ): OrderDeletionResult {
		if ( $order_id < 1 ) { return OrderDeletionResult::failure( OrderDeletionResult::INCONSISTENT_STATE, $order_id ); }
		if ( ! $this->transactions->supports_order_deletion() || $this->transactions->is_active() ) {
			return OrderDeletionResult::failure( $this->transactions->is_outcome_uncertain() ? OrderDeletionResult::TRANSACTION_OUTCOME_UNKNOWN : OrderDeletionResult::DATABASE_ERROR, $order_id );
		}
		if ( ! $this->transactions->begin_commerce_exclusive( $expected ) ) {
			return OrderDeletionResult::failure( $this->transactions->is_outcome_uncertain() ? OrderDeletionResult::TRANSACTION_OUTCOME_UNKNOWN : OrderDeletionResult::DATABASE_ERROR, $order_id );
		}
		try {
			$context = $this->transactions->commerce_context();
			if ( null === $context ) { return $this->rollback_failure( OrderDeletionResult::DATABASE_ERROR, $order_id ); }
			$discovered = $this->repository->discover_order( $order_id );
			if ( false === $discovered ) { return $this->rollback_failure( OrderDeletionResult::DATABASE_ERROR, $order_id ); }
			if ( null === $discovered ) { return $this->finish_absent( $order_id, $context ); }
   // A live Order cannot reuse an identity carrying another deletion witness.
   $retained=$this->deletions->retained_order($order_id);
   if(false===$retained)return $this->rollback_failure($this->deletions->retained_database_error()?OrderDeletionResult::DATABASE_ERROR:OrderDeletionResult::EVIDENCE_CONFLICT,$order_id);
   if($retained instanceof OrderDeletionRecord)return $this->rollback_failure($retained->epoch_id()===$context->epoch_id()?OrderDeletionResult::INCONSISTENT_STATE:OrderDeletionResult::EVIDENCE_CONFLICT,$order_id);

			if ( (int) $discovered->epoch_id !== $context->epoch_id() ) { return $this->rollback_failure( OrderDeletionResult::EVIDENCE_CONFLICT, $order_id ); }

			$cart = null;
			if ( null !== $discovered->cart_id ) {
				$cart = $this->repository->lock_cart( (int) $discovered->cart_id );
				if ( ! is_object( $cart ) || (int) $cart->epoch_id !== $context->epoch_id() ) { return $this->rollback_failure( OrderDeletionResult::INCONSISTENT_STATE, $order_id ); }
			}
			$order = $this->repository->lock_order( $order_id );
			if ( ! $order instanceof OrderRecord || $order->epoch_id() !== $context->epoch_id() || $order->cart_id() !== ( null === $discovered->cart_id ? null : (int) $discovered->cart_id ) ) { return $this->rollback_failure( OrderDeletionResult::INCONSISTENT_STATE, $order_id ); }

			$sessions = $this->repository->lock_sessions( $order_id );
			if ( false === $sessions ) { return $this->rollback_failure( OrderDeletionResult::DATABASE_ERROR, $order_id ); }
			$event_ids = $this->repository->discover_event_ids( $order_id, $sessions );
			if ( false === $event_ids ) { return $this->rollback_failure( OrderDeletionResult::DATABASE_ERROR, $order_id ); }
			$events = $this->repository->lock_events( $event_ids );
			if ( false === $events ) { return $this->rollback_failure( OrderDeletionResult::DATABASE_ERROR, $order_id ); }
			$items = $this->repository->lock_items( $order_id );
			if ( false === $items ) { return $this->rollback_failure( OrderDeletionResult::DATABASE_ERROR, $order_id ); }
			$item_ids = array_map( static fn( object $item ): int => (int) $item->id, $items );
			$reservations = $this->repository->lock_reservations( $order_id, $item_ids );
			if ( false === $reservations ) { return $this->rollback_failure( OrderDeletionResult::DATABASE_ERROR, $order_id ); }

			$existing_deletion = $this->deletions->find_owned_order( $order_id, true );
			if ( false === $existing_deletion ) { return $this->rollback_failure( OrderDeletionResult::EVIDENCE_CONFLICT, $order_id ); }
			if ( $existing_deletion instanceof OrderDeletionRecord ) { return $this->rollback_failure( OrderDeletionResult::INCONSISTENT_STATE, $order_id ); }
			$claims = $this->repository->lock_claims( $order_id );
			if ( false === $claims ) { return $this->rollback_failure( OrderDeletionResult::DATABASE_ERROR, $order_id ); }
			$claim_ids = array_map( static fn( object $claim ): int => (int) $claim->id, $claims );
			$identities = $this->repository->lock_identities( $claim_ids, $events );
			if ( false === $identities ) { return $this->rollback_failure( OrderDeletionResult::DATABASE_ERROR, $order_id ); }

			if ( ! $this->valid_operational_ownership( $context, $order, $sessions, $items, $reservations ) || ! $this->valid_claims( $context, $sessions, $claims ) || ! $this->valid_events( $context, $sessions, $events, $claims, $identities ) ) {
				return $this->rollback_failure( OrderDeletionResult::EVIDENCE_CONFLICT, $order_id );
			}

			$deletion = $this->deletions->persist( $order, $this->clock->now_mysql() );
			if ( ! $deletion instanceof OrderDeletionRecord ) { return $this->rollback_failure( OrderDeletionResult::DATABASE_ERROR, $order_id ); }
			$now = $deletion->deleted_at();
			foreach ( $claims as $claim ) { if ( ! $this->repository->tombstone_claim( (int) $claim->id, $now ) ) { return $this->rollback_failure( OrderDeletionResult::DATABASE_ERROR, $order_id ); } }

			$event_identity_ids = array(); $event_keys = array();
			foreach ( $events as $event ) { $event_keys[ $this->identity_key( $event->provider(), 'event', $event->provider_event_id() ) ] = true; }
			foreach ( $identities as $identity ) {
				$key = $this->identity_key( (string) $identity->provider, (string) $identity->identity_kind, (string) $identity->identity_value );
				if ( isset( $event_keys[ $key ] ) ) { $event_identity_ids[] = (int) $identity->id; if ( ! $this->repository->terminalize_event_identity( (int) $identity->id ) ) { return $this->rollback_failure( OrderDeletionResult::DATABASE_ERROR, $order_id ); } }
			}

			$session_ids = array_map( static fn( PaymentSessionRecord $session ): int => $session->id(), $sessions );
			$reservation_ids = array_map( static fn( object $reservation ): int => (int) $reservation->id, $reservations );
			if ( ! $this->repository->delete_operational( $order_id, $event_ids, $session_ids, $reservation_ids, $item_ids ) ) { return $this->rollback_failure( OrderDeletionResult::DATABASE_ERROR, $order_id ); }
			$confirmed = $this->deletions->find_owned_order( $order_id, true );
			if ( ! $confirmed instanceof OrderDeletionRecord || ! $this->repository->operational_absent( $order_id, $event_ids, $session_ids, $reservation_ids, $item_ids ) || ! $this->repository->durable_postconditions( $claim_ids, $event_identity_ids ) || ( null !== $cart && ! $this->repository->same_cart( $cart ) ) ) {
				return $this->rollback_failure( OrderDeletionResult::DATABASE_ERROR, $order_id );
			}
			if ( ! $this->transactions->commit() ) { return OrderDeletionResult::failure( OrderDeletionResult::TRANSACTION_OUTCOME_UNKNOWN, $order_id ); }
			return OrderDeletionResult::success( OrderDeletionResult::DELETED, $order_id );
		} catch ( \Throwable $error ) {
			return $this->rollback_failure( OrderDeletionResult::DATABASE_ERROR, $order_id );
		}
	}

	private function finish_absent( int $order_id, CommerceContext $context ): OrderDeletionResult {
  $deletion=$this->deletions->retained_order($order_id);
  if(false===$deletion)return $this->rollback_failure($this->deletions->retained_database_error()?OrderDeletionResult::DATABASE_ERROR:OrderDeletionResult::EVIDENCE_CONFLICT,$order_id);
  if($deletion instanceof OrderDeletionRecord&&$deletion->epoch_id()!==$context->epoch_id()){
   $residuals=$this->repository->has_residuals($order_id,true);
   if($residuals!==false)return $this->rollback_failure($residuals===null?OrderDeletionResult::DATABASE_ERROR:OrderDeletionResult::INCONSISTENT_STATE,$order_id);
   // Read-only historical result ends with confirmed rollback, never source authority.
   return $this->finish_read_only(OrderDeletionResult::ALREADY_DELETED,$order_id,true);
  }
  if($deletion===null){
   $residuals=$this->repository->has_residuals($order_id,false);
   if($residuals!==false)return $this->rollback_failure($residuals===null?OrderDeletionResult::DATABASE_ERROR:OrderDeletionResult::INCONSISTENT_STATE,$order_id);
   return $this->finish_read_only(OrderDeletionResult::ABSENCE_UNPROVABLE,$order_id,false);
  }

		if ( false === $deletion ) { return $this->rollback_failure( OrderDeletionResult::EVIDENCE_CONFLICT, $order_id ); }
		$foreign = $this->repository->has_foreign_deletion( $order_id, $context );
		if ( null === $foreign ) { return $this->rollback_failure( OrderDeletionResult::DATABASE_ERROR, $order_id ); }
		if ( $foreign ) { return $this->rollback_failure( OrderDeletionResult::EVIDENCE_CONFLICT, $order_id ); }
		$residuals = $this->repository->has_residuals( $order_id, $deletion instanceof OrderDeletionRecord );
		if ( null === $residuals ) { return $this->rollback_failure( OrderDeletionResult::DATABASE_ERROR, $order_id ); }
		if ( $residuals ) { return $this->rollback_failure( OrderDeletionResult::INCONSISTENT_STATE, $order_id ); }
		if ( $deletion instanceof OrderDeletionRecord ) {
			$evidence = $this->repository->validate_deleted_evidence( $order_id, $context );
			if ( null === $evidence ) { return $this->rollback_failure( OrderDeletionResult::DATABASE_ERROR, $order_id ); }
			if ( ! $evidence ) { return $this->rollback_failure( OrderDeletionResult::EVIDENCE_CONFLICT, $order_id ); }
		}
		if ( ! $this->transactions->commit() ) { return OrderDeletionResult::failure( OrderDeletionResult::TRANSACTION_OUTCOME_UNKNOWN, $order_id ); }
		return OrderDeletionResult::success( OrderDeletionResult::ALREADY_DELETED, $order_id );
	}

 /** Read-only compatibility results cannot escape an uncertain transaction as success. */
 private function finish_read_only(string $code,int $order_id,bool $success):OrderDeletionResult {
  if(!$this->transactions->rollback()){$this->deletions->discard_connection();return OrderDeletionResult::failure(OrderDeletionResult::TRANSACTION_OUTCOME_UNKNOWN,$order_id);}
  return $success?OrderDeletionResult::success($code,$order_id):OrderDeletionResult::failure($code,$order_id);
 }

	/** @param PaymentSessionRecord[] $sessions @param object[] $items @param object[] $reservations */
	private function valid_operational_ownership( CommerceContext $context, OrderRecord $order, array $sessions, array $items, array $reservations ): bool {
		$item_map = array();
		foreach ( $items as $item ) { if ( (int) $item->order_id !== $order->id() ) { return false; } $item_map[(int) $item->id] = (int) $item->post_id; }
		foreach ( $sessions as $session ) { if ( $session->epoch_id() !== $context->epoch_id() || $session->order_id() !== $order->id() ) { return false; } }
		foreach ( $reservations as $reservation ) { $item_id = (int) $reservation->order_item_id; if ( (int) $reservation->epoch_id !== $context->epoch_id() || (int) $reservation->order_id !== $order->id() || ! isset( $item_map[ $item_id ] ) || $item_map[ $item_id ] !== (int) $reservation->post_id ) { return false; } }
		return true;
	}

	/** @param PaymentSessionRecord[] $sessions @param object[] $claims */
	private function valid_claims( CommerceContext $context, array $sessions, array $claims ): bool {
		$by_session = array(); foreach ( $claims as $claim ) { $by_session[(int) $claim->payment_session_id][] = $claim; }
		if ( count( $sessions ) !== count( $claims ) ) { return false; }
		foreach ( $sessions as $session ) {
			$matched = $by_session[$session->id()] ?? array(); if ( 1 !== count( $matched ) ) { return false; } $claim = $matched[0];
			if ( $claim->installation_id !== $context->installation_id() || (int) $claim->epoch_id !== $context->epoch_id() || $claim->epoch_identity !== $context->epoch_identity() || (int) $claim->order_id !== $session->order_id() || $claim->provider !== $session->provider() || $claim->reference !== $session->reference() || $claim->creation_key_hash !== $session->creation_key_hash() || 'open' !== $claim->disposition || null !== $claim->tombstoned_at || 'native' !== $claim->evidence_state ) { return false; }
		}
		return true;
	}

	/** @param PaymentSessionRecord[] $sessions @param PaymentEventRecord[] $events @param object[] $claims @param object[] $identities */
	private function valid_events( CommerceContext $context, array $sessions, array $events, array $claims, array $identities ): bool {
		$session_claim = array(); $reference_claim = array(); $target_claims = array();
		foreach ( $claims as $claim ) { $target_claims[(int) $claim->id] = true; $session_claim[(int) $claim->payment_session_id] = (int) $claim->id; $reference_claim[(string) $claim->reference] = (int) $claim->id; }
		$reconciliation_claim = array(); foreach ( $sessions as $session ) { if ( null !== $session->reconciliation_event_id() ) { $reconciliation_claim[$session->reconciliation_event_id()][] = $session_claim[$session->id()] ?? 0; } }
		$identity_map = array(); foreach ( $identities as $identity ) { $identity_map[$this->identity_key((string)$identity->provider,(string)$identity->identity_kind,(string)$identity->identity_value)] = $identity; }
		foreach ( $sessions as $session ) {
			$claim_id = $session_claim[$session->id()] ?? 0;
			foreach ( array( 'session' => $session->provider_session_id(), 'payment' => $session->provider_payment_id(), 'event' => $session->provider_event_id() ) as $kind => $value ) {
				if ( null === $value ) { continue; } $identity = $identity_map[$this->identity_key($session->provider(),$kind,$value)] ?? null;
				if ( ! is_object( $identity ) ) { return false; }
				$valid_binding = 'event' === $kind ? in_array( $identity->binding_state, array( 'bound', 'quarantined' ), true ) : 'bound' === $identity->binding_state;
				if ( (int) $identity->claim_id !== $claim_id || ! $valid_binding ) { return false; }
			}
		}
		foreach ( $events as $event ) {
			$candidates = array();
			foreach ( $reconciliation_claim[$event->id()] ?? array() as $claim_id ) { if ( $claim_id > 0 ) { $candidates[] = $claim_id; } }
			if ( null !== $event->payment_session_id() ) { if ( ! isset( $session_claim[$event->payment_session_id()] ) ) { return false; } $candidates[] = $session_claim[$event->payment_session_id()]; }
			if ( null !== $event->local_reference() ) { if ( ! isset( $reference_claim[$event->local_reference()] ) ) { return false; } $candidates[] = $reference_claim[$event->local_reference()]; }
			foreach ( array( 'event' => $event->provider_event_id(), 'session' => $event->provider_session_id(), 'payment' => $event->provider_payment_id() ) as $kind => $value ) {
				if ( null === $value ) { continue; } $identity = $identity_map[$this->identity_key($event->provider(),$kind,$value)] ?? null;
				if ( 'event' === $kind && ! is_object( $identity ) ) { return false; }
				if ( is_object( $identity ) && null !== $identity->claim_id ) { $claim_id = (int) $identity->claim_id; if ( ! isset( $target_claims[$claim_id] ) ) { return false; } $candidates[] = $claim_id; }
			}
			$candidates = array_values( array_unique( $candidates ) ); if ( 1 !== count( $candidates ) ) { return false; }
			$event_identity = $identity_map[$this->identity_key($event->provider(),'event',$event->provider_event_id())];
			if ( ( null !== $event_identity->claim_id && (int) $event_identity->claim_id !== $candidates[0] ) || ( null !== $event_identity->payload_sha256 && ! hash_equals((string)$event_identity->payload_sha256,$event->payload_sha256()) ) || ( null !== $event_identity->event_type && $event_identity->event_type !== $event->event_type() ) || ( null !== $event_identity->environment && $event_identity->environment !== ($event->livemode()?'live':'test') ) ) { return false; }
			if ( null !== $event->epoch_id() && $event->epoch_id() !== $context->epoch_id() ) { return false; }
		}
		return true;
	}

	private function identity_key( string $provider, string $kind, string $value ): string { return $provider . "\0" . $kind . "\0" . $value; }
	private function rollback_failure( string $code, int $order_id ): OrderDeletionResult {
		if ( ! $this->transactions->rollback() || $this->transactions->is_outcome_uncertain() ) { return OrderDeletionResult::failure( OrderDeletionResult::TRANSACTION_OUTCOME_UNKNOWN, $order_id ); }
		return OrderDeletionResult::failure( $code, $order_id );
	}
}
