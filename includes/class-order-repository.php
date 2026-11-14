<?php
/**
 * Order database persistence.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

use wpdb;

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-transaction-manager.php';
require_once __DIR__ . '/class-epoch-ownership.php';
require_once __DIR__ . '/class-payment-notification-dispatcher.php';

/**
 * Persists orders and immutable order item snapshots.
 */
final class OrderRepository {
	private const COLUMNS = 'epoch_id, id, cart_id, cart_revision, checkout_generation, checkout_attempt_hash, cart_reconciliation_status, cart_reconciliation_code, cart_reconciliation_updated_at, status, currency, subtotal_minor, total_minor, created_at, updated_at, paid_at, cancelled_at, expired_at, failed_at';
	private const ITEM_COLUMNS = 'id, order_id, post_id, source_cart_item_id, source_cart_item_version, item_name, unit_price_minor, quantity, line_subtotal_minor, line_total_minor, currency, created_at';
	private wpdb $wpdb;
	private TableNames $tables;

	public function __construct( wpdb $wpdb, TableNames $tables ) {
		$this->wpdb   = $wpdb;
		$this->tables = $tables;
	}

	/** @return OrderRecord|false */
	public function create( ?int $cart_id, string $status, string $currency, int $subtotal_minor, int $total_minor, string $created_at, string $updated_at, ?string $checkout_attempt_hash = null, ?int $cart_revision = null, ?int $checkout_generation = null ) {
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return false; }
		// Every modern operational order originates in checkout with durable cart ownership.
		if ( null === $cart_id || null === $cart_revision || null === $checkout_generation || null === $checkout_attempt_hash ) { return false; }
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $cart_id ) ) { return false; }
		if ( $cart_id <= 0 || $cart_revision < 0 || $checkout_generation < 0 || ! OrderStatuses::is_valid( $status ) || ! isset( Money::get_currencies()[ $currency ] ) || $subtotal_minor < 0 || $total_minor < 0 || '' === $created_at || '' === $updated_at || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $checkout_attempt_hash ) ) {
			return false;
		}

		$result = $this->wpdb->insert(
			$this->tables->orders(),
			array(
				'epoch_id' => TransactionManager::context_on( $this->wpdb )->epoch_id(),
				'cart_id'                        => $cart_id,
				'cart_revision'                  => $cart_revision,
				'checkout_generation'            => $checkout_generation,
				'cart_reconciliation_status'     => 'pending',
				'cart_reconciliation_code'       => null,
				'cart_reconciliation_updated_at' => null,
				'checkout_attempt_hash'           => $checkout_attempt_hash,
				'status'                          => $status,
				'currency'                        => $currency,
				'subtotal_minor'                  => $subtotal_minor,
				'total_minor'                     => $total_minor,
				'created_at'                      => $created_at,
				'updated_at'                      => $updated_at,
			),
			array( '%d', '%d', '%d', '%d', '%s', null, null, '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( false === $result || $this->wpdb->insert_id <= 0 ) {
			return false;
		}

		return $this->find( (int) $this->wpdb->insert_id ) ?: false;
	}

	public function find_by_checkout_attempt( int $cart_id, string $checkout_attempt_hash ): ?OrderRecord {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $cart_id ) ) { return null; }
		if ( $cart_id <= 0 || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $checkout_attempt_hash ) ) {
			return null;
		}
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM ' . $this->tables->orders() . ' WHERE cart_id = %d AND checkout_attempt_hash = %s LIMIT 1', $cart_id, $checkout_attempt_hash ) );

		return null === $row ? null : $this->owned_record( $row );
	}

	/** @return OrderRecord|false|null Caller must own the cart row lock. */
	public function find_revision_generation_for_update( int $cart_id, int $revision, int $generation ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $cart_id ) ) { return false; }
		if ( $cart_id <= 0 || $revision < 0 || $generation < 0 ) { return null; }
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM ' . $this->tables->orders() . ' WHERE cart_id = %d AND cart_revision = %d AND checkout_generation = %d LIMIT 1 FOR UPDATE', $cart_id, $revision, $generation ) );
		if ( '' !== $this->wpdb->last_error ) { return false; }
		return null === $row ? null : $this->owned_record( $row );
	}

	/** Nonlocking routing read only; the cart association must be rechecked under locks.
	 * @return object|false|null
	 */
	public function discover_reconciliation_cart( int $order_id ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->orders(), $order_id ) ) { return false; }
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT epoch_id, id, cart_id FROM ' . $this->tables->orders() . ' WHERE id = %d LIMIT 1', $order_id ) );
		return '' !== $this->wpdb->last_error ? false : $row;
	}

	/**
	 * Locks the complete reconciliation and immutable sale/source context.
	 * K11 independently validates the original sale before trusting a terminal marker.
	 * @return object|false|null Caller must first lock the discovered cart.
	 */
	public function find_reconciliation_for_update( int $order_id ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->orders(), $order_id ) ) { return false; }
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM ' . $this->tables->orders() . ' WHERE id = %d LIMIT 1 FOR UPDATE', $order_id ) );
		return '' !== $this->wpdb->last_error ? false : $row;
	}

	/** Caller owns the order lock; only nonterminal reconciliation states may transition. */
	public function finish_cart_reconciliation( int $order_id, string $status, string $code, string $now ): bool {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->orders(), $order_id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return false; }
		if ( ! in_array( $status, array( 'completed', 'attention' ), true ) || 1 !== preg_match( '/\A[a-z0-9_]{1,64}\z/', $code ) || '' === $now ) { return false; }
		$result = $this->wpdb->query( $this->wpdb->prepare(
			'UPDATE ' . $this->tables->orders() . " SET cart_reconciliation_status = %s, cart_reconciliation_code = %s, cart_reconciliation_updated_at = %s WHERE id = %d AND (cart_reconciliation_status IS NULL OR cart_reconciliation_status = 'pending') AND cart_reconciliation_code IS NULL AND cart_reconciliation_updated_at IS NULL AND status = 'paid' AND paid_at IS NOT NULL",
			$status, $code, $now, $order_id
		) );
		return 1 === $result;
	}

	public function find( int $order_id ): ?OrderRecord {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->orders(), $order_id ) ) { return null; }
		if ( $order_id <= 0 ) {
			return null;
		}

		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM ' . $this->tables->orders() . ' WHERE id = %d LIMIT 1', $order_id ) );

		return null === $row ? null : $this->owned_record( $row );
	}

	/**
	 * Loads and locks an order for transaction-scoped orchestration.
	 *
	 * @return OrderRecord|false|null False on database failure, null when absent.
	 */
	public function find_for_update( int $order_id ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->orders(), $order_id ) ) { return false; }
		if ( $order_id <= 0 ) {
			return null;
		}
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT ' . self::COLUMNS . ' FROM ' . $this->tables->orders() . ' WHERE id = %d LIMIT 1 FOR UPDATE', $order_id ) );
		if ( '' !== $this->wpdb->last_error ) {
			return false;
		}

		return null === $row ? null : $this->owned_record( $row );
	}

	/** @return OrderItem|false */
	public function insert_item( int $order_id, int $post_id, string $item_name, int $unit_price_minor, int $quantity, int $line_subtotal_minor, int $line_total_minor, string $currency, string $created_at, ?int $source_cart_item_id = null, ?int $source_cart_item_version = null ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->orders(), $order_id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return false; }
		// Checkout knows the immutable cart source before inserting the snapshot.
		if ( null === $source_cart_item_id || null === $source_cart_item_version ) { return false; }
		if ( $order_id <= 0 || $post_id <= 0 || '' === $item_name || $unit_price_minor < 0 || $quantity < 1 || $line_subtotal_minor < 0 || $line_total_minor < 0 || ! isset( Money::get_currencies()[ $currency ] ) || '' === $created_at || $source_cart_item_id <= 0 || $source_cart_item_version < 0 ) {
			return false;
		}

		$source=$this->wpdb->get_row($this->wpdb->prepare('SELECT c.epoch_id, i.cart_id, i.post_id, o.cart_id AS order_cart_id FROM '.$this->tables->cart_items().' i JOIN '.$this->tables->carts().' c ON c.id=i.cart_id JOIN '.$this->tables->orders().' o ON o.id=%d WHERE i.id=%d',$order_id,$source_cart_item_id));
		if(null===$source||(int)$source->epoch_id!==TransactionManager::context_on($this->wpdb)->epoch_id()||(int)$source->cart_id!==(int)$source->order_cart_id||(int)$source->post_id!==$post_id){return false;}
		$result = $this->wpdb->insert(
			$this->tables->order_items(),
			array(
				'order_id'                 => $order_id,
				'post_id'                  => $post_id,
				'source_cart_item_id'      => $source_cart_item_id,
				'source_cart_item_version' => $source_cart_item_version,
				'item_name'                => $item_name,
				'unit_price_minor'         => $unit_price_minor,
				'quantity'                 => $quantity,
				'line_subtotal_minor'      => $line_subtotal_minor,
				'line_total_minor'         => $line_total_minor,
				'currency'                 => $currency,
				'created_at'               => $created_at,
			),
			array( '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
		);

		if ( false === $result || $this->wpdb->insert_id <= 0 ) {
			return false;
		}

		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT ' . self::ITEM_COLUMNS . ' FROM ' . $this->tables->order_items() . ' WHERE id = %d LIMIT 1', (int) $this->wpdb->insert_id ) );

		return null === $row ? false : OrderItem::from_row( $row );
	}

	/** @return OrderItem[] */
	public function get_items( int $order_id ): array {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->orders(), $order_id ) ) { return array(); }
		if ( $order_id <= 0 ) {
			return array();
		}
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT ' . self::ITEM_COLUMNS . ' FROM ' . $this->tables->order_items() . ' WHERE order_id = %d ORDER BY id ASC', $order_id ) );

		return empty( $rows ) ? array() : array_map( static fn( object $row ): OrderItem => OrderItem::from_row( $row ), $rows );
	}

	/**
	 * Reads immutable order-item snapshots while the owning order row is locked.
	 *
	 * @return OrderItem[]|false
	 */
	public function get_items_for_acquisition( int $order_id ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->orders(), $order_id ) ) { return false; }
		if ( $order_id <= 0 ) {
			return array();
		}
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT ' . self::ITEM_COLUMNS . ' FROM ' . $this->tables->order_items() . ' WHERE order_id = %d ORDER BY id ASC', $order_id ) );
		if ( '' !== $this->wpdb->last_error || null === $rows ) {
			return false;
		}

		return empty( $rows ) ? array() : array_map( static fn( object $row ): OrderItem => OrderItem::from_row( $row ), $rows );
	}

	public function update_status( int $order_id, string $expected_status, string $new_status, string $updated_at, ?string $paid_at = null, ?string $cancelled_at = null, ?string $expired_at = null, ?string $failed_at = null ): bool {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->orders(), $order_id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return false; }
		if ( $order_id <= 0 || ! OrderStatuses::is_valid( $expected_status ) || ! OrderStatuses::is_valid( $new_status ) || '' === $updated_at ) {
			return false;
		}
		$data    = array( 'status' => $new_status, 'updated_at' => $updated_at );
		$formats = array( '%s', '%s' );
		foreach ( array( 'paid_at' => $paid_at, 'cancelled_at' => $cancelled_at, 'expired_at' => $expired_at, 'failed_at' => $failed_at ) as $key => $value ) {
			if ( null !== $value ) {
				$data[ $key ] = $value;
				$formats[]    = '%s';
			}
		}

		$result = $this->wpdb->update( $this->tables->orders(), $data, array( 'id' => $order_id, 'status' => $expected_status ), $formats, array( '%d', '%s' ) );

		return false !== $result && $result > 0;
	}

	/** Atomically records the financial order transition used by sale conversion. */
	public function mark_paid( int $order_id, string $expected_status, string $paid_at, int $event_id, ?string $customer_mail_to, ?string $admin_email, ?string $customer_name = null, ?string $customer_email = null ): bool {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->orders(), $order_id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return false; }
		if ( $order_id <= 0 || ! in_array( $expected_status, array( OrderStatuses::AWAITING_PAYMENT, OrderStatuses::EXPIRED ), true ) || '' === $paid_at || $event_id <= 0 ) {
			return false;
		}
		$update = $this->wpdb instanceof CommerceWpdb ? 'update_private_values' : 'update';
		$result = $this->wpdb->$update(
			$this->tables->orders(),
			array(
				'status' => OrderStatuses::PAID,
				'updated_at' => $paid_at,
				'paid_at' => $paid_at,
				'notification_event_id' => $event_id,
				'customer_name' => $customer_name,
				'customer_email' => $customer_email,
				'customer_mail_state' => null === $customer_mail_to ? PaymentNotificationDispatcher::SKIPPED : PaymentNotificationDispatcher::PENDING,
				'customer_mail_to' => $customer_mail_to,
				'admin_mail_state' => null === $admin_email ? PaymentNotificationDispatcher::SKIPPED : PaymentNotificationDispatcher::PENDING,
				'admin_mail_to' => $admin_email,
			),
			array( 'id' => $order_id, 'status' => $expected_status, 'paid_at' => null, 'notification_event_id' => null ),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%s' ),
			array( '%d', '%s', '%s', '%d' )
		);

		return false !== $result && $result > 0;
	}
	private function owned_record( object $row ): OrderRecord {
		$context = EpochOwnership::context( $this->wpdb, $this->tables );
		if ( null === $context || ! isset( $row->epoch_id ) || (int) $row->epoch_id !== $context->epoch_id() ) { throw new \RuntimeException( 'commerce_epoch_mismatch' ); }
		return OrderRecord::from_row( $row );
	}
}
