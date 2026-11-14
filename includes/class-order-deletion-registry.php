<?php
/** Durable order-deletion identity and checkout replay barriers. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class OrderDeletionRegistry {
	private const COLUMNS = 'id, installation_id, epoch_id, epoch_identity, original_order_id, deleted_at, cart_id, checkout_attempt_hash, cart_revision, checkout_generation, checkout_disposition, cart_revision_at_deletion';
	public function __construct( private \wpdb $wpdb, private TableNames $tables ) {}

 /**
  * Read-only classification DATA under an existing normal control fence.
  * A global two-row sentinel forbids choosing the first installation/epoch owner.
  * Historical proof grants no operational, provider or reset mutation authority.
  * @return OrderDeletionRecord|false|null
  */
 public function retained_order(int $order_id) {
  $context=TransactionManager::context_on($this->wpdb);
  if($context===null||$order_id<1)return false;
  $this->wpdb->last_error='';
  $rows=$this->wpdb->get_results($this->wpdb->prepare('SELECT '.self::COLUMNS.' FROM '.$this->tables->order_deletions().' FORCE INDEX(original_order_id) WHERE original_order_id=%d ORDER BY id LIMIT 2',$order_id));
  if($this->wpdb->last_error!==''||!is_array($rows)||count($rows)>1)return false;
  if(!$rows)return null;
  try {
   $record=OrderDeletionRecord::from_row($rows[0]);
   if($record->installation_id()!==$context->installation_id()||$record->epoch_id()>$context->epoch_id())return false;
   $source=$this->retained_point($this->wpdb->prepare('SELECT * FROM '.$this->tables->commerce_epochs().' FORCE INDEX(PRIMARY) WHERE id=%d LIMIT 1',$record->epoch_id()));
   if(!is_object($source)||$source->identity!==$record->epoch_identity()||!ProviderClaimRecord::valid_date($source->created_at)||$record->deleted_at()<$source->created_at||$record->deleted_at()>gmdate('Y-m-d H:i:s'))return false;
   if($record->epoch_id()===$context->epoch_id())return $source->retired_at===null&&$source->identity===$context->epoch_identity()?$record:false;
   $row=$this->retained_point($this->wpdb->prepare('SELECT * FROM '.$this->tables->commerce_resets().' FORCE INDEX(source_epoch) WHERE installation_id=%s AND source_epoch_id=%d LIMIT 1',$record->installation_id(),$record->epoch_id()));
   if(!is_object($row))return false;
   $reset=CommerceResetRecord::from_row($row);
   $target=$this->retained_point($this->wpdb->prepare('SELECT * FROM '.$this->tables->commerce_epochs().' FORCE INDEX(PRIMARY) WHERE id=%d LIMIT 1',(int)$reset->target_epoch_id()));
   return is_object($target)&&$record->deleted_at()<=$reset->started_at()&&CommerceResetHistory::completed($reset,$context->installation_id(),$context->epoch_id(),$source,$target)?$record:false;
  }catch(\Throwable $error){return false;}
 }
 public function discard_connection():void {try{if(method_exists($this->wpdb,'close'))$this->wpdb->close();}catch(\Throwable $ignored){}}
 public function retained_database_error():bool {return $this->wpdb->last_error!=='';}
 private function retained_point(string $query) {
  $this->wpdb->last_error='';$row=$this->wpdb->get_row($query);
  return $this->wpdb->last_error===''?$row:false;
 }

	/** Caller owns the cart and order locks in an admitted transaction. @return OrderDeletionRecord|false */
	public function persist( OrderRecord $order, string $deleted_at ) {
		$context = TransactionManager::context_on( $this->wpdb );
		if ( null === $context || $order->epoch_id() !== $context->epoch_id() || ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->orders(), $order->id() ) || '' === $deleted_at ) { return false; }
		$cart_id = $order->cart_id();
		if ( null !== $cart_id && ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $cart_id ) ) { return false; }
		if ( null === $cart_id || null === $order->cart_revision() || null === $order->checkout_generation() || null === $order->checkout_attempt_hash() ) { return false; }
		$disposition = 'revision'; $observed = null;
		$data = array(
			'installation_id' => $context->installation_id(), 'epoch_id' => $context->epoch_id(), 'epoch_identity' => $context->epoch_identity(),
			'original_order_id' => $order->id(), 'deleted_at' => $deleted_at, 'cart_id' => $cart_id,
			'checkout_attempt_hash' => $order->checkout_attempt_hash(), 'cart_revision' => $order->cart_revision(),
			'checkout_generation' => $order->checkout_generation(), 'checkout_disposition' => $disposition,
			'cart_revision_at_deletion' => $observed,
		);
		$this->wpdb->last_error = '';
		$inserted = $this->wpdb->insert( $this->tables->order_deletions(), $data, array( '%s', '%d', '%s', '%d', '%s', null === $cart_id ? null : '%d', null === $order->checkout_attempt_hash() ? null : '%s', null === $order->cart_revision() ? null : '%d', null === $order->checkout_generation() ? null : '%d', '%s', null === $observed ? null : '%d' ) );
		if ( false !== $inserted && $this->wpdb->insert_id > 0 ) { return $this->find_owned_order( $order->id(), true ); }
		// A byte-for-byte equivalent existing identity is the deterministic idempotent result.
		$existing = $this->find_owned_order( $order->id(), true );
		if ( ! $existing instanceof OrderDeletionRecord ) { return false; }
		return $this->equivalent( $existing, $data ) ? $existing : false;
	}

	/** Caller owns the cart row lock. @return OrderDeletionRecord|false|null */
	public function find_checkout_block_for_update( int $cart_id, string $attempt_hash, int $cart_revision ) {
		$context = TransactionManager::context_on( $this->wpdb );
		if ( null === $context || $cart_id < 1 || $cart_revision < 0 || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $attempt_hash ) || ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $cart_id ) ) { return false; }
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare(
			'SELECT ' . self::COLUMNS . ' FROM ' . $this->tables->order_deletions() . ' WHERE epoch_id = %d AND cart_id = %d AND (checkout_attempt_hash = %s OR (checkout_disposition = %s AND cart_revision = %d)) ORDER BY id FOR UPDATE',
			$context->epoch_id(), $cart_id, $attempt_hash, 'revision', $cart_revision
		) );
		if ( '' !== $this->wpdb->last_error || ! is_array( $rows ) ) { return false; }
		$match = null;
		foreach ( $rows as $row ) {
			try { $record = OrderDeletionRecord::from_row( $row ); } catch ( \Throwable $error ) { return false; }
			if ( ! $this->owned( $record, $context ) || $record->cart_id() !== $cart_id ) { return false; }
			$match ??= $record;
		}
		return $match;
	}

	/** @return OrderDeletionRecord|false|null */
	public function find_owned_order( int $order_id, bool $for_update = false ) {
		$context = TransactionManager::context_on( $this->wpdb );
		if ( null === $context || $order_id < 1 ) { return false; }
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM ' . $this->tables->order_deletions() . ' WHERE epoch_id = %d AND original_order_id = %d LIMIT 1' . ( $for_update ? ' FOR UPDATE' : '' ), $context->epoch_id(), $order_id ) );
		if ( '' !== $this->wpdb->last_error ) { return false; }
		if ( null === $row ) { return null; }
		try { $record = OrderDeletionRecord::from_row( $row ); } catch ( \Throwable $error ) { return false; }
		return $this->owned( $record, $context ) ? $record : false;
	}

	/**
	 * Fresh admitted proof that an operational order is absent because this
	 * installation deliberately deleted it. Every non-positive result is
	 * fail-closed: absence alone, malformed evidence, and read errors are never
	 * treated as deletion.
	 */
	public function proves_deleted_order( int $order_id, bool $for_update = true ): bool {
		$context = TransactionManager::context_on( $this->wpdb );
		if ( null === $context || $order_id < 1 ) { return false; }
		$this->wpdb->last_error = '';
		$operational = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT id, epoch_id FROM ' . $this->tables->orders() . ' WHERE id = %d LIMIT 1' . ( $for_update ? ' FOR UPDATE' : '' ), $order_id ) );
		if ( '' !== $this->wpdb->last_error || null !== $operational ) { return false; }
		return $this->find_owned_order( $order_id, $for_update ) instanceof OrderDeletionRecord;
	}

	/** A deleted provider claim is benign only when the deletion record agrees. */
	public function proves_deleted_claim( object $claim, bool $for_update = true ): bool {
		$context = TransactionManager::context_on( $this->wpdb );
		if ( null === $context || ! isset( $claim->installation_id, $claim->epoch_id, $claim->epoch_identity, $claim->order_id, $claim->disposition, $claim->tombstoned_at ) ) { return false; }
		if ( $claim->installation_id !== $context->installation_id() || (int) $claim->epoch_id !== $context->epoch_id() || $claim->epoch_identity !== $context->epoch_identity() || 'deleted' !== $claim->disposition || ! is_string( $claim->tombstoned_at ) || '' === $claim->tombstoned_at ) { return false; }
		return $this->proves_deleted_order( (int) $claim->order_id, $for_update );
	}

	private function owned( OrderDeletionRecord $record, CommerceContext $context ): bool {
		return $record->installation_id() === $context->installation_id() && $record->epoch_id() === $context->epoch_id() && $record->epoch_identity() === $context->epoch_identity();
	}
	private function equivalent( OrderDeletionRecord $record, array $data ): bool {
		return $record->installation_id() === $data['installation_id'] && $record->epoch_id() === $data['epoch_id'] && $record->epoch_identity() === $data['epoch_identity'] && $record->original_order_id() === $data['original_order_id'] && $record->deleted_at() === $data['deleted_at'] && $record->cart_id() === $data['cart_id'] && $record->checkout_attempt_hash() === $data['checkout_attempt_hash'] && $record->cart_revision() === $data['cart_revision'] && $record->checkout_generation() === $data['checkout_generation'] && $record->checkout_disposition() === $data['checkout_disposition'] && $record->cart_revision_at_deletion() === $data['cart_revision_at_deletion'];
	}
}
