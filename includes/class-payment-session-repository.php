<?php
/** Payment-session database persistence. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
use wpdb;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-transaction-manager.php';
require_once __DIR__ . '/class-epoch-ownership.php';
require_once __DIR__ . '/class-provider-registry.php';
require_once __DIR__ . '/class-provider-response-evidence.php';
final class PaymentSessionRepository {
	private const COLUMNS = 'epoch_id, id, reference, order_id, provider, provider_session_id, creation_key_hash, status, active_slot, currency, amount_minor, redirect_url, created_at, updated_at, expires_at, completed_at, cancelled_at, failed_at, provider_payment_id, provider_event_id, payment_confirmed_at, confirmed_currency, confirmed_amount_minor, expired_at, reconciliation_required_at, reconciliation_code, reconciliation_event_id';
 /** Positive exact native-attempt evidence is a refusal barrier, never a substitute Session. */
 public function retained_attempt_exists(int $order_id,string $hash):?bool {
  $context=TransactionManager::context_on($this->wpdb);if($context===null)return null;
  $this->wpdb->last_error='';$row=$this->wpdb->get_row($this->wpdb->prepare('SELECT id FROM '.$this->tables->provider_claims().' FORCE INDEX(attempt) WHERE installation_id=%s AND epoch_id=%d AND order_id=%d AND creation_key_hash=%s LIMIT 1',$context->installation_id(),$context->epoch_id(),$order_id,$hash));
  return $this->wpdb->last_error!==''?null:$row!==null;
 }
	public function checkout_carts(): CartRepository {return new CartRepository($this->wpdb,$this->tables);}
	public function registry(): ProviderRegistry { return new ProviderRegistry( $this->wpdb, $this->tables ); }
	/** Shared adapter default; runtime supplies an independent private-connection factory. */
	public function response_evidence(): ProviderResponseEvidence { return new ProviderResponseEvidence(fn()=>$this->wpdb,false); }
	private wpdb $wpdb; private TableNames $tables;
	public function __construct( wpdb $wpdb, TableNames $tables ) { $this->wpdb = $wpdb; $this->tables = $tables; }
	/** @return PaymentSessionRecord|false */
	public function create_attempt( string $reference, int $order_id, string $provider, string $creation_key_hash, string $currency, int $amount_minor, string $created_at ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->orders(), $order_id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return false; }
		if ( $order_id <= 0 || 1 !== preg_match( '/\A[0-9a-f]{32}\z/', $reference ) || 1 !== preg_match( '/\A[a-z0-9_]{1,32}\z/', $provider ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $creation_key_hash ) || ! isset( Money::get_currencies()[ $currency ] ) || $amount_minor < 0 || '' === $created_at ) { return false; }
		$result = $this->wpdb->insert( $this->tables->payment_sessions(), array( 'epoch_id' => TransactionManager::context_on( $this->wpdb )->epoch_id(), 'reference' => $reference, 'order_id' => $order_id, 'provider' => $provider, 'provider_session_id' => null, 'creation_key_hash' => $creation_key_hash, 'status' => PaymentSessionStatuses::CREATING, 'active_slot' => 1, 'currency' => $currency, 'amount_minor' => $amount_minor, 'redirect_url' => null, 'created_at' => $created_at, 'updated_at' => $created_at, 'expires_at' => null, 'completed_at' => null, 'cancelled_at' => null, 'failed_at' => null, 'provider_payment_id' => null, 'provider_event_id' => null, 'payment_confirmed_at' => null, 'confirmed_currency' => null, 'confirmed_amount_minor' => null, 'expired_at' => null, 'reconciliation_required_at' => null, 'reconciliation_code' => null, 'reconciliation_event_id' => null ), array( '%d', '%s', '%d', '%s', null, '%s', '%s', '%d', '%s', '%d', null, '%s', '%s', null, null, null, null, null, null, null, null, null, null, null, null, null ) );
		return false === $result || $this->wpdb->insert_id <= 0 ? false : ( $this->find( (int) $this->wpdb->insert_id ) ?: false );
	}
	/** @return PaymentSessionRecord|false|null */
	public function find( int $id ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->payment_sessions(), $id ) ) { return false; } return $this->read_one( $id > 0 ? $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM ' . $this->tables->payment_sessions() . ' WHERE id = %d LIMIT 1', $id ) : '' ); }
	/** @return PaymentSessionRecord|false|null */
	public function find_for_update( int $id ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->payment_sessions(), $id ) ) { return false; } return $this->read_one( $id > 0 ? $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM ' . $this->tables->payment_sessions() . ' WHERE id = %d LIMIT 1 FOR UPDATE', $id ) : '' ); }
	/** @return PaymentSessionRecord|false|null */
	public function find_by_reference( string $reference ) { return $this->read_one( 1 === preg_match( '/\A[0-9a-f]{32}\z/', $reference ) ? $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM ' . $this->tables->payment_sessions() . ' WHERE reference = %s LIMIT 1', $reference ) : '' ); }
	/** @return PaymentSessionRecord|false|null */
	public function find_by_reference_for_update( string $reference ) { return $this->read_one( 1 === preg_match( '/\A[0-9a-f]{32}\z/', $reference ) ? $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM ' . $this->tables->payment_sessions() . ' WHERE reference = %s LIMIT 1 FOR UPDATE', $reference ) : '' ); }
	/** @return PaymentSessionRecord|false|null */
	public function find_by_provider_session_id( string $provider, string $provider_session_id ) { return $this->read_one( $this->valid_provider_identity( $provider, $provider_session_id ) ? $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM ' . $this->tables->payment_sessions() . ' WHERE provider = %s AND provider_session_id = %s LIMIT 1', $provider, $provider_session_id ) : '' ); }
	/** @return PaymentSessionRecord|false|null */
	public function find_by_provider_session_id_for_update( string $provider, string $provider_session_id ) { return $this->read_one( $this->valid_provider_identity( $provider, $provider_session_id ) ? $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM ' . $this->tables->payment_sessions() . ' WHERE provider = %s AND provider_session_id = %s LIMIT 1 FOR UPDATE', $provider, $provider_session_id ) : '' ); }
	/** @return PaymentSessionRecord|false|null */
		public function find_by_provider_payment_id( string $provider, string $provider_payment_id ) { return $this->read_one( $this->valid_provider_identity( $provider, $provider_payment_id ) ? $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM ' . $this->tables->payment_sessions() . ' WHERE provider = %s AND provider_payment_id = %s LIMIT 1', $provider, $provider_payment_id ) : '' ); }
	/** @return PaymentSessionRecord|false|null */
	public function find_by_creation_key( int $order_id, string $hash ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->orders(), $order_id ) ) { return false; } return $this->read_one( $order_id > 0 && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $hash ) ? $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM ' . $this->tables->payment_sessions() . ' WHERE order_id = %d AND creation_key_hash = %s LIMIT 1', $order_id, $hash ) : '' ); }
	/** @return PaymentSessionRecord|false|null */
	public function find_slot_owner_for_order( int $order_id ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->orders(), $order_id ) ) { return false; } return $this->read_one( $order_id > 0 ? $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM ' . $this->tables->payment_sessions() . ' WHERE order_id = %d AND active_slot = 1 LIMIT 1 FOR UPDATE', $order_id ) : '' ); }
	/** Stage predicate only, never an original-owner selector. Order mutex precedes all Session locks. */
	public function has_later_attempt_for_update(int $order_id,int $session_id): ?bool {
		if(!TransactionManager::admitted_on($this->wpdb)||!EpochOwnership::owns($this->wpdb,$this->tables,$this->tables->orders(),$order_id))return null;
		$this->wpdb->last_error='';
		$row=$this->wpdb->get_row($this->wpdb->prepare('SELECT id FROM '.$this->tables->payment_sessions().' FORCE INDEX(order_id) WHERE order_id=%d AND id>%d ORDER BY id LIMIT 1 FOR UPDATE',$order_id,$session_id));
		return $this->wpdb->last_error!==''?null:$row!==null;
	}

	/** @return PaymentSessionRecord[]|false */
	public function get_for_order_for_update( int $order_id ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->orders(), $order_id ) ) { return false; }
		if ( $order_id <= 0 ) { return array(); }
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM ' . $this->tables->payment_sessions() . ' WHERE order_id = %d ORDER BY id ASC FOR UPDATE', $order_id ) );
		if ( '' !== $this->wpdb->last_error || null === $rows ) { return false; }
		return array_map( fn( object $row ): PaymentSessionRecord => $this->owned_record( $row ), $rows );
	}
	public function activate( int $id, string $provider_session_id, string $redirect_url, string $expires_at, string $updated_at ): bool {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->payment_sessions(), $id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) || ! $this->claim_allows_mutation($id) ) { return false; }
		if ( $id <= 0 || '' === trim( $provider_session_id ) || '' === $redirect_url || '' === $expires_at || '' === $updated_at ) { return false; }
		$result = $this->wpdb->update( $this->tables->payment_sessions(), array( 'provider_session_id' => $provider_session_id, 'redirect_url' => $redirect_url, 'expires_at' => $expires_at, 'status' => PaymentSessionStatuses::ACTIVE, 'active_slot' => 1, 'updated_at' => $updated_at ), array( 'id' => $id, 'status' => PaymentSessionStatuses::CREATING, 'active_slot' => 1 ), array( '%s', '%s', '%s', '%s', '%d', '%s' ), array( '%d', '%s', '%d' ) );
		return false !== $result && $result > 0;
	}
	public function bind_provider_session_id_if_missing( int $id, string $provider, string $provider_session_id, string $updated_at ): bool {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->payment_sessions(), $id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) || ! $this->claim_allows_mutation($id) ) { return false; }
		if ( $id <= 0 || ! $this->valid_provider_identity( $provider, $provider_session_id ) || '' === $updated_at ) { return false; }
		$result = $this->wpdb->update( $this->tables->payment_sessions(), array( 'provider_session_id' => $provider_session_id, 'updated_at' => $updated_at ), array( 'id' => $id, 'provider' => $provider, 'provider_session_id' => null ), array( '%s', '%s' ), array( '%d', '%s', null ) );
		return false !== $result && $result > 0;
	}
	public function mark_reconciliation_required( int $id, string $required_at, string $code, int $event_id ): bool {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->payment_sessions(), $id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) || ! $this->claim_allows_mutation($id) ) { return false; }
		if ( $id <= 0 || '' === $required_at || $event_id <= 0 || 1 !== preg_match( '/\A[a-z0-9_]{1,64}\z/', $code ) ) { return false; }
		$result = $this->wpdb->update( $this->tables->payment_sessions(), array( 'reconciliation_required_at' => $required_at, 'reconciliation_code' => $code, 'reconciliation_event_id' => $event_id, 'updated_at' => $required_at ), array( 'id' => $id ), array( '%s', '%s', '%d', '%s' ), array( '%d' ) );
		return false !== $result && $result > 0;
	}
	public function clear_reconciliation_required( int $id, string $updated_at ): bool {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->payment_sessions(), $id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) || ! $this->claim_allows_mutation($id) ) { return false; }
		if ( $id <= 0 || '' === $updated_at ) { return false; }
		$result = $this->wpdb->update( $this->tables->payment_sessions(), array( 'reconciliation_required_at' => null, 'reconciliation_code' => null, 'reconciliation_event_id' => null, 'updated_at' => $updated_at ), array( 'id' => $id ), array( null, null, null, '%s' ), array( '%d' ) );
		return false !== $result && $result > 0;
	}
	public function mark_failed( int $id, string $failed_at ): bool {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->payment_sessions(), $id ) ) { return false; } return $this->transition_terminal( $id, PaymentSessionStatuses::CREATING, PaymentSessionStatuses::FAILED, $failed_at ); }
	public function transition_terminal( int $id, string $expected_status, string $new_status, string $timestamp ): bool {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->payment_sessions(), $id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) || ! $this->claim_allows_mutation($id) ) { return false; }
		if ( $id <= 0 || ! PaymentSessionStatuses::is_valid( $expected_status ) || ! in_array( $new_status, array( PaymentSessionStatuses::EXPIRED, PaymentSessionStatuses::CANCELLED, PaymentSessionStatuses::FAILED ), true ) || '' === $timestamp ) { return false; }
		$data = array( 'status' => $new_status, 'active_slot' => null, 'updated_at' => $timestamp ); $formats = array( '%s', null, '%s' );
		$timestamp_columns = array( PaymentSessionStatuses::EXPIRED => 'expired_at', PaymentSessionStatuses::CANCELLED => 'cancelled_at', PaymentSessionStatuses::FAILED => 'failed_at' );
		if ( isset( $timestamp_columns[ $new_status ] ) ) { $data[ $timestamp_columns[ $new_status ] ] = $timestamp; $formats[] = '%s'; }
		$result = $this->wpdb->update( $this->tables->payment_sessions(), $data, array( 'id' => $id, 'status' => $expected_status ), $formats, array( '%d', '%s' ) );
		return false !== $result && $result > 0;
	}
	public function complete_verified_payment( int $id, string $expected_status, string $provider_payment_id, string $provider_event_id, string $payment_confirmed_at, string $confirmed_currency, int $confirmed_amount_minor ): bool {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->payment_sessions(), $id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) || ! $this->claim_allows_mutation($id) ) { return false; }
		if ( $id <= 0 || ! in_array( $expected_status, array( PaymentSessionStatuses::ACTIVE, PaymentSessionStatuses::EXPIRED ), true ) || ! $this->valid_text( $provider_payment_id, 255 ) || ! $this->valid_text( $provider_event_id, 255 ) || '' === $payment_confirmed_at || ! isset( Money::get_currencies()[ $confirmed_currency ] ) || $confirmed_amount_minor < 0 ) { return false; }
		$result = $this->wpdb->update(
			$this->tables->payment_sessions(),
			array( 'status' => PaymentSessionStatuses::COMPLETED, 'active_slot' => null, 'provider_payment_id' => $provider_payment_id, 'provider_event_id' => $provider_event_id, 'payment_confirmed_at' => $payment_confirmed_at, 'confirmed_currency' => $confirmed_currency, 'confirmed_amount_minor' => $confirmed_amount_minor, 'completed_at' => $payment_confirmed_at, 'updated_at' => $payment_confirmed_at ),
			array( 'id' => $id, 'status' => $expected_status ),
			array( '%s', null, '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
			array( '%d', '%s' )
		);
		return false !== $result && $result > 0;
	}
	/** @return PaymentSessionRecord|false|null */
	private function claim_allows_mutation(int $id):bool {
		$context=TransactionManager::context_on($this->wpdb);if(null===$context){return false;}
		$session=$this->find($id);if(!$session instanceof PaymentSessionRecord){return false;}
		$registry=$this->registry();$claim=$registry->claim($session->reference());
		return is_object($claim)&&$registry->matches($claim,$session,$context);
	}
	private function read_one( string $query ) { if ( '' === $query ) { return null; } $this->wpdb->last_error = ''; $row = $this->wpdb->get_row( $query ); if ( '' !== $this->wpdb->last_error ) { return false; } return null === $row ? null : $this->owned_record( $row ); }
	private function valid_provider_identity( string $provider, string $provider_session_id ): bool { return 1 === preg_match( '/\A[a-z0-9_]{1,32}\z/', $provider ) && '' !== trim( $provider_session_id ) && strlen( $provider_session_id ) <= 255; }
	private function valid_text( string $value, int $maximum ): bool { return '' !== trim( $value ) && strlen( $value ) <= $maximum; }
	private function owned_record( object $row ): PaymentSessionRecord {
		$context = EpochOwnership::context( $this->wpdb, $this->tables );
		if ( null === $context || ! isset( $row->epoch_id ) || (int) $row->epoch_id !== $context->epoch_id() ) { throw new \RuntimeException( 'commerce_epoch_mismatch' ); }
		return PaymentSessionRecord::from_row( $row );
	}
}
