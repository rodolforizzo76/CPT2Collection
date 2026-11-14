<?php
/** Checked discovery, deterministic locks, and explicit operational deletion. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class OrderDeletionRepository {
	private const ORDER_COLUMNS = 'epoch_id, id, cart_id, cart_revision, checkout_generation, checkout_attempt_hash, cart_reconciliation_status, cart_reconciliation_code, cart_reconciliation_updated_at, status, currency, subtotal_minor, total_minor, created_at, updated_at, paid_at, cancelled_at, expired_at, failed_at';
	private const SESSION_COLUMNS = 'epoch_id, id, reference, order_id, provider, provider_session_id, creation_key_hash, status, active_slot, currency, amount_minor, redirect_url, created_at, updated_at, expires_at, completed_at, cancelled_at, failed_at, provider_payment_id, provider_event_id, payment_confirmed_at, confirmed_currency, confirmed_amount_minor, expired_at, reconciliation_required_at, reconciliation_code, reconciliation_event_id';
	private const EVENT_COLUMNS = 'epoch_id, ownership_state, id, provider, provider_event_id, event_type, provider_created_at, livemode, payment_session_id, provider_session_id, local_reference, provider_payment_id, currency, amount_minor, payment_status, session_status, processing_status, result_code, attempt_count, received_at, last_attempt_at, processed_at, attention_at, error_code, error_detail, payload_sha256, normalized_payload';

	public function __construct( private \wpdb $db, private TableNames $tables ) {}

	/** Nonlocking routing read. @return object|false|null */
	public function discover_order( int $order_id ) {
		if ( $order_id < 1 ) { return null; }
		return $this->one( $this->db->prepare( 'SELECT epoch_id, id, cart_id FROM ' . $this->tables->orders() . ' WHERE id=%d LIMIT 1', $order_id ) );
	}

	/** @return object|false|null */
	public function lock_cart( int $cart_id ) {
		return $this->one( $this->db->prepare( 'SELECT * FROM ' . $this->tables->carts() . ' WHERE id=%d LIMIT 1 FOR UPDATE', $cart_id ) );
	}

	/** @return OrderRecord|false|null */
	public function lock_order( int $order_id ) {
		$row = $this->one( $this->db->prepare( 'SELECT ' . self::ORDER_COLUMNS . ' FROM ' . $this->tables->orders() . ' WHERE id=%d LIMIT 1 FOR UPDATE', $order_id ) );
		if ( ! is_object( $row ) ) { return $row; }
		try { return OrderRecord::from_row( $row ); } catch ( \Throwable $error ) { return false; }
	}

	/** @return PaymentSessionRecord[]|false */
	public function lock_sessions( int $order_id ) {
		$rows = $this->many( $this->db->prepare( 'SELECT ' . self::SESSION_COLUMNS . ' FROM ' . $this->tables->payment_sessions() . ' WHERE order_id=%d ORDER BY id ASC FOR UPDATE', $order_id ) );
		if ( false === $rows ) { return false; }
		try { return array_map( static fn( object $row ): PaymentSessionRecord => PaymentSessionRecord::from_row( $row ), $rows ); } catch ( \Throwable $error ) { return false; }
	}

	/**
	 * Discovers the union of locally provable event relationships. The rows are
	 * subsequently locked by identifier and every relationship is revalidated.
	 * @param PaymentSessionRecord[] $sessions
	 * @return int[]|false
	 */
	public function discover_event_ids( int $order_id, array $sessions ) {
		$session_ids = array_map( static fn( PaymentSessionRecord $session ): int => $session->id(), $sessions );
		$event_ids = array_values( array_filter( array_map( static fn( PaymentSessionRecord $session ): ?int => $session->reconciliation_event_id(), $sessions ) ) );
		$where = array(); $args = array();
		if ( $session_ids ) { $where[] = 'e.payment_session_id IN (' . implode( ',', array_fill( 0, count( $session_ids ), '%d' ) ) . ')'; $args = array_merge( $args, $session_ids ); }
		$where[] = '(ce.order_id=%d OR cs.order_id=%d OR cp.order_id=%d)'; array_push( $args, $order_id, $order_id, $order_id );
		$sql = 'SELECT DISTINCT e.id FROM ' . $this->tables->payment_events() . ' e' .
			' LEFT JOIN ' . $this->tables->provider_identities() . " ie ON ie.provider=e.provider AND ie.identity_kind='event' AND ie.identity_value=e.provider_event_id" .
			' LEFT JOIN ' . $this->tables->provider_claims() . ' ce ON ce.id=ie.claim_id' .
			' LEFT JOIN ' . $this->tables->provider_identities() . " ids ON ids.provider=e.provider AND ids.identity_kind='session' AND ids.identity_value=e.provider_session_id" .
			' LEFT JOIN ' . $this->tables->provider_claims() . ' cs ON cs.id=ids.claim_id' .
			' LEFT JOIN ' . $this->tables->provider_identities() . " idp ON idp.provider=e.provider AND idp.identity_kind='payment' AND idp.identity_value=e.provider_payment_id" .
			' LEFT JOIN ' . $this->tables->provider_claims() . ' cp ON cp.id=idp.claim_id';
		if ( $sessions ) {
			$refs = array_map( static fn( PaymentSessionRecord $session ): string => $session->reference(), $sessions );
			$where[] = 'e.local_reference IN (' . implode( ',', array_fill( 0, count( $refs ), '%s' ) ) . ')'; $args = array_merge( $args, $refs );
			foreach ( array( 'provider_session_id', 'provider_payment_id', 'provider_event_id' ) as $method ) {
				$column = 'provider_event_id' === $method ? 'provider_event_id' : $method;
				$pairs = array();
				foreach ( $sessions as $session ) { $value = $session->$method(); if ( null === $value ) { continue; } $pairs[] = '(e.provider=%s AND e.' . $column . '=%s)'; array_push( $args, $session->provider(), $value ); }
				if ( $pairs ) { $where[] = '(' . implode( ' OR ', $pairs ) . ')'; }
			}
		}
		$rows = $this->many( $this->db->prepare( $sql . ' WHERE ' . implode( ' OR ', $where ) . ' ORDER BY e.id ASC', ...$args ) );
		if ( false === $rows ) { return false; }
		foreach ( $rows as $row ) { $event_ids[] = (int) $row->id; }
		$event_ids = array_values( array_unique( array_filter( $event_ids, static fn( int $id ): bool => $id > 0 ) ) ); sort( $event_ids, SORT_NUMERIC );
		return $event_ids;
	}

	/** @param int[] $ids @return PaymentEventRecord[]|false */
	public function lock_events( array $ids ) {
		if ( ! $ids ) { return array(); }
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) ); sort( $ids, SORT_NUMERIC );
		$rows = $this->many( $this->db->prepare( 'SELECT ' . self::EVENT_COLUMNS . ' FROM ' . $this->tables->payment_events() . ' WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY id ASC FOR UPDATE', ...$ids ) );
		if ( false === $rows || count( $rows ) !== count( $ids ) ) { return false; }
		try { return array_map( static fn( object $row ): PaymentEventRecord => PaymentEventRecord::from_row( $row ), $rows ); } catch ( \Throwable $error ) { return false; }
	}

	/** @return object[]|false */
	public function lock_items( int $order_id ) { return $this->many( $this->db->prepare( 'SELECT * FROM ' . $this->tables->order_items() . ' WHERE order_id=%d ORDER BY id ASC FOR UPDATE', $order_id ) ); }
	/** Locks both direct and item-linked rows so contradictory ownership cannot hide. @param int[] $item_ids @return object[]|false */
	public function lock_reservations( int $order_id, array $item_ids ) {
		$where = 'order_id=%d'; $args = array( $order_id );
		if ( $item_ids ) { $where .= ' OR order_item_id IN (' . implode( ',', array_fill( 0, count( $item_ids ), '%d' ) ) . ')'; $args = array_merge( $args, $item_ids ); }
		return $this->many( $this->db->prepare( 'SELECT * FROM ' . $this->tables->reservations() . ' WHERE ' . $where . ' ORDER BY post_id ASC, id ASC FOR UPDATE', ...$args ) );
	}

	/** @return object[]|false */
	public function lock_claims( int $order_id ) { return $this->many( $this->db->prepare( 'SELECT * FROM ' . $this->tables->provider_claims() . ' WHERE order_id=%d ORDER BY id ASC FOR UPDATE', $order_id ) ); }

	/** @param int[] $claim_ids @param PaymentEventRecord[] $events @return object[]|false */
	public function lock_identities( array $claim_ids, array $events ) {
		$where = array(); $args = array();
		if ( $claim_ids ) { $where[] = 'claim_id IN (' . implode( ',', array_fill( 0, count( $claim_ids ), '%d' ) ) . ')'; $args = array_merge( $args, $claim_ids ); }
		foreach ( $events as $event ) {
			foreach ( array( 'event' => $event->provider_event_id(), 'session' => $event->provider_session_id(), 'payment' => $event->provider_payment_id() ) as $kind => $value ) {
				if ( null === $value ) { continue; }
				$where[] = '(provider=%s AND identity_kind=%s AND identity_value=%s)'; array_push( $args, $event->provider(), $kind, $value );
			}
		}
		if ( ! $where ) { return array(); }
		return $this->many( $this->db->prepare( 'SELECT * FROM ' . $this->tables->provider_identities() . ' WHERE ' . implode( ' OR ', $where ) . ' ORDER BY provider ASC, identity_kind ASC, identity_value ASC FOR UPDATE', ...$args ) );
	}

	/** Detects residual rows before reporting an unknown order as absent. @return bool|null */
	public function has_residuals( int $order_id, bool $durable_evidence_is_expected ): ?bool {
		$queries = array(
			$this->db->prepare( 'SELECT id FROM ' . $this->tables->order_items() . ' WHERE order_id=%d LIMIT 1', $order_id ),
			$this->db->prepare( 'SELECT id FROM ' . $this->tables->reservations() . ' WHERE order_id=%d LIMIT 1', $order_id ),
			$this->db->prepare( 'SELECT id FROM ' . $this->tables->payment_sessions() . ' WHERE order_id=%d LIMIT 1', $order_id ),
			$this->db->prepare( "SELECT e.id FROM {$this->tables->payment_events()} e LEFT JOIN {$this->tables->provider_identities()} ie ON ie.provider=e.provider AND ie.identity_kind='event' AND ie.identity_value=e.provider_event_id LEFT JOIN {$this->tables->provider_identities()} ids ON ids.provider=e.provider AND ids.identity_kind='session' AND ids.identity_value=e.provider_session_id LEFT JOIN {$this->tables->provider_identities()} idp ON idp.provider=e.provider AND idp.identity_kind='payment' AND idp.identity_value=e.provider_payment_id LEFT JOIN {$this->tables->provider_claims()} c ON c.id IN (ie.claim_id,ids.claim_id,idp.claim_id) OR c.reference=e.local_reference WHERE c.order_id=%d LIMIT 1", $order_id ),
		);
		if ( ! $durable_evidence_is_expected ) { $queries[] = $this->db->prepare( 'SELECT id FROM ' . $this->tables->provider_claims() . ' WHERE order_id=%d LIMIT 1', $order_id ); }
		foreach ( $queries as $query ) { $this->db->last_error = ''; $value = $this->db->get_var( $query ); if ( '' !== $this->db->last_error ) { return null; } if ( null !== $value ) { return true; } }
		return false;
	}

	/** A deletion identity for another installation/epoch is contradictory evidence. @return bool|null */
	public function has_foreign_deletion( int $order_id, CommerceContext $context ): ?bool {
		$this->db->last_error = '';
		$value = $this->db->get_var( $this->db->prepare( 'SELECT id FROM ' . $this->tables->order_deletions() . ' WHERE original_order_id=%d AND (installation_id<>%s OR epoch_id<>%d OR epoch_identity<>%s) LIMIT 1', $order_id, $context->installation_id(), $context->epoch_id(), $context->epoch_identity() ) );
		return '' !== $this->db->last_error ? null : null !== $value;
	}

	/** Validates the retained evidence before an idempotent already-deleted result. @return bool|null */
	public function validate_deleted_evidence( int $order_id, CommerceContext $context ): ?bool {
		$claims = $this->many( $this->db->prepare( 'SELECT * FROM ' . $this->tables->provider_claims() . ' WHERE order_id=%d ORDER BY id ASC FOR UPDATE', $order_id ) );
		if ( false === $claims ) { return null; }
		foreach ( $claims as $claim ) {
			if ( $claim->installation_id !== $context->installation_id() || (int) $claim->epoch_id !== $context->epoch_id() || $claim->epoch_identity !== $context->epoch_identity() || 'deleted' !== $claim->disposition || null === $claim->tombstoned_at ) { return false; }
		}
		return true;
	}

	private function baseline_claim(int $claim_id): bool {
		$context=TransactionManager::context_on($this->db);
		if(null===$context)return false;
		$registry=new ProviderRegistry($this->db,$this->tables);$claim=$registry->claim_by_id($claim_id,true);
		return is_object($claim)&&$claim->installation_id===$context->installation_id()&&(int)$claim->epoch_id===$context->epoch_id()&&$claim->epoch_identity===$context->epoch_identity()&&$registry->scope($claim->installation_id,$claim->epoch_identity,(int)$claim->epoch_id)===CommerceBaseline::ELIGIBLE;
	}
	public function tombstone_claim( int $claim_id, string $now ): bool {
		if(!$this->baseline_claim($claim_id))return false;
		$result = $this->db->query( $this->db->prepare( "UPDATE {$this->tables->provider_claims()} SET disposition='deleted', tombstoned_at=COALESCE(tombstoned_at,%s) WHERE id=%d AND disposition='open'", $now, $claim_id ) );
		return 1 === $result;
	}
	public function terminalize_event_identity( int $identity_id ): bool {
		if(!TransactionManager::admitted_on($this->db))return false;
		$this->db->last_error='';$identity=$this->db->get_row($this->db->prepare('SELECT claim_id FROM '.$this->tables->provider_identities().' WHERE id=%d',$identity_id));
		if($this->db->last_error!==''||!is_object($identity)||$identity->claim_id===null||!$this->baseline_claim((int)$identity->claim_id))return false;
		$result = $this->db->query( $this->db->prepare( "UPDATE {$this->tables->provider_identities()} SET disposition='neutralized_order_deleted' WHERE id=%d AND identity_kind='event'", $identity_id ) );
		return false !== $result;
	}

	/** @param int[] $event_ids @param int[] $session_ids @param int[] $reservation_ids @param int[] $item_ids */
	public function delete_operational( int $order_id, array $event_ids, array $session_ids, array $reservation_ids, array $item_ids ): bool {
		foreach ( array(
			array( $this->tables->payment_events(), $event_ids ), array( $this->tables->payment_sessions(), $session_ids ),
			array( $this->tables->reservations(), $reservation_ids ), array( $this->tables->order_items(), $item_ids ),
		) as [ $table, $ids ] ) {
			if ( ! $ids ) { continue; }
			$result = $this->db->query( $this->db->prepare( 'DELETE FROM ' . $table . ' WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', ...$ids ) );
			if ( count( $ids ) !== $result ) { return false; }
		}
		return 1 === $this->db->query( $this->db->prepare( 'DELETE FROM ' . $this->tables->orders() . ' WHERE id=%d', $order_id ) );
	}

	/** @param int[] $event_ids @param int[] $session_ids @param int[] $reservation_ids @param int[] $item_ids */
	public function operational_absent( int $order_id, array $event_ids, array $session_ids, array $reservation_ids, array $item_ids ): bool {
		$sets = array( $this->tables->payment_events() => $event_ids, $this->tables->payment_sessions() => $session_ids, $this->tables->reservations() => $reservation_ids, $this->tables->order_items() => $item_ids );
		foreach ( $sets as $table => $ids ) { if ( ! $ids ) { continue; } $value = $this->scalar( $this->db->prepare( 'SELECT COUNT(*) FROM ' . $table . ' WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', ...$ids ) ); if ( 0 !== $value ) { return false; } }
		return 0 === $this->scalar( $this->db->prepare( 'SELECT COUNT(*) FROM ' . $this->tables->orders() . ' WHERE id=%d', $order_id ) );
	}

	public function same_cart( object $before ): bool {
		$after = $this->one( $this->db->prepare( 'SELECT * FROM ' . $this->tables->carts() . ' WHERE id=%d LIMIT 1', (int) $before->id ) );
		return is_object( $after ) && (array) $before === (array) $after;
	}

	/** @param int[] $claim_ids @param int[] $event_identity_ids */
	public function durable_postconditions( array $claim_ids, array $event_identity_ids ): bool {
		if ( $claim_ids ) {
			$count = $this->scalar( $this->db->prepare( "SELECT COUNT(*) FROM {$this->tables->provider_claims()} WHERE id IN (" . implode( ',', array_fill( 0, count( $claim_ids ), '%d' ) ) . ") AND disposition='deleted' AND tombstoned_at IS NOT NULL", ...$claim_ids ) );
			if ( count( $claim_ids ) !== $count ) { return false; }
		}
		if ( $event_identity_ids ) {
			$count = $this->scalar( $this->db->prepare( "SELECT COUNT(*) FROM {$this->tables->provider_identities()} WHERE id IN (" . implode( ',', array_fill( 0, count( $event_identity_ids ), '%d' ) ) . ") AND identity_kind='event' AND disposition='neutralized_order_deleted'", ...$event_identity_ids ) );
			if ( count( $event_identity_ids ) !== $count ) { return false; }
		}
		return true;
	}

	/** @return object|false|null */ private function one( string $sql ) { $this->db->last_error = ''; $row = $this->db->get_row( $sql ); return '' === $this->db->last_error ? $row : false; }
	/** @return object[]|false */ private function many( string $sql ) { $this->db->last_error = ''; $rows = $this->db->get_results( $sql ); return '' === $this->db->last_error && is_array( $rows ) ? $rows : false; }
	private function scalar( string $sql ): int { $this->db->last_error = ''; $value = $this->db->get_var( $sql ); return '' === $this->db->last_error && null !== $value ? (int) $value : -1; }
}
