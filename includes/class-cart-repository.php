<?php
/**
 * Cart database persistence.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

use wpdb;

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-transaction-manager.php';
require_once __DIR__ . '/class-epoch-ownership.php';
require_once __DIR__ . '/class-cart-reconciliation-boundary.php';
require_once __DIR__ . '/class-checkout-context-token.php';

/**
 * Persists cart and cart item rows. It does not handle browser cookies.
 */
final class CartRepository {
	private wpdb $wpdb;
	private TableNames $tables;
	private ?TransactionManager $transaction_manager = null;
	public function reconciliation_boundary(?callable $connections=null): CartReconciliationBoundary { return new CartReconciliationBoundary($this->wpdb,$this->tables,$connections); }
	public function discard_connection():void {try{if(method_exists($this->wpdb,'close'))$this->wpdb->close();}catch(\Throwable $ignored){}}
	public function transactions(): TransactionManager { return $this->transaction_manager ??= new TransactionManager( $this->wpdb, $this->tables ); }

	public function __construct( wpdb $wpdb, TableNames $tables ) {
		$this->wpdb   = $wpdb;
		$this->tables = $tables;
	}

	/** Read-only fresh normal admission; never sign a different/newer cart than the response. */
 public function browser_checkout_context(CartRecord $cart):?array {
  $tx=$this->transactions();if($tx->is_active()||!$tx->begin_commerce_shared())return null;
  try {
   $locked=$this->find_for_update($cart->id());$context=$tx->commerce_context();
   $out=$locked instanceof CartRecord&&CheckoutContextToken::same_cart($cart,$locked)&&$locked->epoch_id()===$context->epoch_id()&&$locked->expires_at()>gmdate('Y-m-d H:i:s')?CheckoutContextToken::issue($locked,$context):null;
   if(!$tx->rollback()){try{$this->wpdb->close();}catch(\Throwable $ignored){}return null;}
   return $out;
  }catch(\Throwable $error){if(!$tx->rollback())try{$this->wpdb->close();}catch(\Throwable $ignored){}return null;}
 }

	public function is_current(CartRecord $cart):bool {
		$context=EpochOwnership::context($this->wpdb,$this->tables);
		return null!==$context&&$cart->epoch_id()===$context->epoch_id()&&EpochOwnership::owns($this->wpdb,$this->tables,$this->tables->carts(),$cart->id());
	}

	public function find_active_by_token_hash( string $token_hash, string $now_utc ): ?CartRecord {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
					'SELECT epoch_id, id, token_hash, content_revision, checkout_generation, created_at, updated_at, last_activity_at, expires_at FROM ' . $this->tables->carts() . ' WHERE ' . EpochOwnership::predicate( $this->wpdb, $this->tables ) . ' AND token_hash = %s AND expires_at > %s LIMIT 1',
				$token_hash,
				$now_utc
			)
		);

		return null === $row ? null : $this->owned_record( $row );
	}

	/**
	 * @return CartRecord|false
	 */
	public function create( string $token_hash, string $created_at, string $updated_at, string $last_activity_at, string $expires_at ) {
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return false; }
		$result = $this->wpdb->insert(
			$this->tables->carts(),
			array(
					'epoch_id' => TransactionManager::context_on( $this->wpdb )->epoch_id(),
				'token_hash'       => $token_hash,
					'content_revision' => 0,
					'checkout_generation' => 0,
				'created_at'       => $created_at,
				'updated_at'       => $updated_at,
				'last_activity_at' => $last_activity_at,
				'expires_at'       => $expires_at,
			),
				array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result || $this->wpdb->insert_id <= 0 ) {
			return false;
		}

			return new CartRecord( (int) $this->wpdb->insert_id, $token_hash, $created_at, $updated_at, $last_activity_at, $expires_at, 0, 0, TransactionManager::context_on( $this->wpdb )->epoch_id() );
	}

	/**
	 * @return CartRecord|false
	 */
	public function touch( int $cart_id, string $updated_at, string $last_activity_at, string $expires_at ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $cart_id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return false; }
		if ( $cart_id <= 0 ) {
			return false;
		}

		$result = $this->wpdb->update(
			$this->tables->carts(),
			array(
				'updated_at'       => $updated_at,
				'last_activity_at' => $last_activity_at,
				'expires_at'       => $expires_at,
			),
			array( 'id' => $cart_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
					'SELECT epoch_id, id, token_hash, content_revision, checkout_generation, created_at, updated_at, last_activity_at, expires_at FROM ' . $this->tables->carts() . ' WHERE id = %d LIMIT 1',
				$cart_id
			)
		);

		return null === $row ? false : $this->owned_record( $row );
	}

	/** @return CartRecord|false|null Caller must own the transaction. */
	public function find_for_update( int $cart_id ) {
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return false; }
		if ( $cart_id <= 0 ) {
			return null;
		}
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT epoch_id, id, token_hash, content_revision, checkout_generation, created_at, updated_at, last_activity_at, expires_at FROM ' . $this->tables->carts() . ' WHERE id = %d LIMIT 1 FOR UPDATE', $cart_id ) );
		if ( '' !== $this->wpdb->last_error ) {
			return false;
		}
		$context = TransactionManager::context_on( $this->wpdb );
		if ( null === $row || null === $context || ! isset( $row->epoch_id ) || (int) $row->epoch_id !== $context->epoch_id() ) { return false; }
		return $this->owned_record( $row );
	}

	public function advance_content_revision( int $cart_id, int $expected_revision, string $updated_at, ?string $last_activity_at = null, ?string $expires_at = null ): bool {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $cart_id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return false; }
		if ( $cart_id <= 0 || $expected_revision < 0 || PHP_INT_MAX === $expected_revision || '' === $updated_at || ( null === $last_activity_at ) !== ( null === $expires_at ) ) {
			return false;
		}
		$data = array( 'content_revision' => $expected_revision + 1, 'checkout_generation' => 0, 'updated_at' => $updated_at );
		$formats = array( '%d', '%d', '%s' );
		if ( null !== $last_activity_at ) {
			$data['last_activity_at'] = $last_activity_at;
			$data['expires_at'] = $expires_at;
			$formats[] = '%s';
			$formats[] = '%s';
		}
		$result = $this->wpdb->update( $this->tables->carts(), $data, array( 'id' => $cart_id, 'content_revision' => $expected_revision ), $formats, array( '%d', '%d' ) );
		return false !== $result && 1 === $result;
	}

	/** Caller must own the cart row lock. */
	public function advance_checkout_generation( int $cart_id, int $expected_revision, int $expected_generation, string $updated_at ): bool {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $cart_id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return false; }
		if ( $cart_id <= 0 || $expected_revision < 0 || $expected_generation < 0 || PHP_INT_MAX === $expected_generation || '' === $updated_at ) {
			return false;
		}
		$result = $this->wpdb->update(
			$this->tables->carts(),
			array( 'checkout_generation' => $expected_generation + 1, 'updated_at' => $updated_at ),
			array( 'id' => $cart_id, 'content_revision' => $expected_revision, 'checkout_generation' => $expected_generation ),
			array( '%d', '%s' ),
			array( '%d', '%d', '%d' )
		);
		return false !== $result && 1 === $result;
	}

	/** @return CartItem|false|null Caller must own the cart row lock. */
	public function find_item_locked( int $cart_id, int $post_id ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $cart_id ) ) { return false; }
		if ( $cart_id <= 0 || $post_id <= 0 ) {
			return null;
		}
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT id, cart_id, post_id, quantity, content_version FROM ' . $this->tables->cart_items() . ' WHERE cart_id = %d AND post_id = %d LIMIT 1', $cart_id, $post_id ) );
		if ( '' !== $this->wpdb->last_error ) {
			return false;
		}
		return null === $row ? null : CartItem::from_row( $row );
	}

	/** @return CartItem|false|null Caller owns the cart and order locks. Identity is the row ID. */
	public function find_source_item_for_update( int $item_id ) {
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT id, cart_id, post_id, quantity, content_version FROM ' . $this->tables->cart_items() . ' WHERE id = %d LIMIT 1 FOR UPDATE', $item_id ) );
		if ( '' !== $this->wpdb->last_error ) { return false; }
		return null === $row ? null : CartItem::from_row( $row );
	}

	/** Caller owns the cart and source row locks and has validated the complete snapshot. */
	public function reconcile_source_item( CartItem $item, int $remainder, string $now ): bool {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $item->cart_id() ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return false; }
		if ( $remainder < 0 || $remainder >= $item->quantity() ) { return false; }
		if ( 0 === $remainder ) {
			return 1 === $this->wpdb->delete( $this->tables->cart_items(), array( 'id' => $item->id(), 'cart_id' => $item->cart_id(), 'post_id'=>$item->post_id(), 'quantity'=>$item->quantity(), 'content_version'=>$item->content_version() ), array( '%d', '%d', '%d', '%d', '%d' ) );
		}
		return 1 === $this->wpdb->query( $this->wpdb->prepare(
			'UPDATE ' . $this->tables->cart_items() . ' SET quantity = %d, content_version = content_version + 1, updated_at = %s WHERE id = %d AND cart_id = %d AND content_version = %d AND content_version < %d AND post_id = %d AND quantity = %d',
			$remainder, $now, $item->id(), $item->cart_id(), $item->content_version(), PHP_INT_MAX, $item->post_id(), $item->quantity()
		) );
	}

	public function delete_expired( string $now_utc, int $batch_size = 100 ): int {
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return 0; }
		$batch_size = max( 1, min( 500, $batch_size ) );
		$ids        = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT id FROM ' . $this->tables->carts() . ' WHERE ' . EpochOwnership::predicate( $this->wpdb, $this->tables ) . ' AND expires_at <= %s ORDER BY id ASC LIMIT %d FOR UPDATE',
				$now_utc,
				$batch_size
			)
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		$ids          = array_map( 'intval', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->tables->cart_items() . ' WHERE cart_id IN (' . $placeholders . ')', $ids ) );
		$result = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->tables->carts() . ' WHERE id IN (' . $placeholders . ')', $ids ) );

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Returns cart items ordered by insertion order.
	 *
	 * @return CartItem[]
	 */
	public function get_items( int $cart_id ): array {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $cart_id ) ) { return array(); }
		if ( $cart_id <= 0 ) {
			return array();
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT id, cart_id, post_id, quantity, content_version FROM ' . $this->tables->cart_items() . ' WHERE cart_id = %d ORDER BY id ASC',
				$cart_id
			)
		);

		if ( empty( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( object $row ): CartItem {
				return CartItem::from_row( $row );
			},
			$rows
		);
	}

	/**
	 * Loads checkout input while preserving database failure as a distinct result.
	 *
	 * @return CartItem[]|false
	 */
	public function get_items_for_checkout( int $cart_id ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $cart_id ) ) { return false; }
		if ( $cart_id <= 0 ) {
			return false;
		}

		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT id, cart_id, post_id, quantity, content_version FROM ' . $this->tables->cart_items() . ' WHERE cart_id = %d ORDER BY id ASC',
				$cart_id
			)
		);
		if ( '' !== $this->wpdb->last_error || null === $rows ) {
			return false;
		}

		return empty( $rows ) ? array() : array_map( static fn( object $row ): CartItem => CartItem::from_row( $row ), $rows );
	}

	public function find_item( int $cart_id, int $post_id ): ?CartItem {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $cart_id ) ) { return null; }
		if ( $cart_id <= 0 || $post_id <= 0 ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT id, cart_id, post_id, quantity, content_version FROM ' . $this->tables->cart_items() . ' WHERE cart_id = %d AND post_id = %d LIMIT 1',
				$cart_id,
				$post_id
			)
		);

		return null === $row ? null : CartItem::from_row( $row );
	}

	/**
	 * Atomically inserts a new item or increments the existing row quantity.
	 *
	 * The UNIQUE(cart_id, post_id) index remains the concurrency safeguard while
	 * ON DUPLICATE KEY UPDATE performs the increment in the database to avoid lost
	 * updates between close requests. New rows use the version-zero schema default.
	 * Existing rows advance quantity/version together; exhausted PHP integer versions
	 * leave the entire row unchanged and report failure (wpdb changed-row semantics).
	 *
	 * @return CartItem|false
	 */
	public function add_item_quantity( int $cart_id, int $post_id, int $quantity ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $cart_id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return false; }
		if ( $cart_id <= 0 || $post_id <= 0 || $quantity < 1 ) {
			return false;
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				'INSERT INTO ' . $this->tables->cart_items() . ' (cart_id, post_id, quantity, created_at, updated_at) VALUES (%d, %d, %d, %s, %s) ON DUPLICATE KEY UPDATE quantity = IF(content_version < %d, quantity + %d, quantity), updated_at = IF(content_version < %d, %s, updated_at), content_version = IF(content_version < %d, content_version + 1, content_version)',
				$cart_id,
				$post_id,
				$quantity,
				$now,
				$now,
				PHP_INT_MAX,
				$quantity,
				PHP_INT_MAX,
				$now,
				PHP_INT_MAX
			)
		);

		if ( false === $result || 0 === $result ) {
			return false;
		}

		$item = $this->find_item( $cart_id, $post_id );

		return null === $item ? false : $item;
	}

	/** @return CartItem|false Caller must own the cart row lock. */
	public function add_item_quantity_locked( int $cart_id, int $post_id, int $quantity ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $cart_id ) ) { return false; }
		return $this->add_item_quantity( $cart_id, $post_id, $quantity );
	}

	/**
	 * @return CartItem|false
	 */
	public function update_item_quantity( int $cart_id, int $post_id, int $quantity ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $cart_id ) ) { return false; }
		if ( $cart_id <= 0 || $post_id <= 0 || $quantity < 1 ) {
			return false;
		}

		$result = $this->update_item_quantity_locked( $cart_id, $post_id, $quantity );

		if ( false === $result ) {
			return false;
		}

		$item = $this->find_item( $cart_id, $post_id );

		return null === $item || $item->quantity() !== $quantity ? false : $item;
	}

	/** Caller must own the cart row lock. @return int|false */
	public function update_item_quantity_locked( int $cart_id, int $post_id, int $quantity ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $cart_id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return false; }
		if ( $cart_id <= 0 || $post_id <= 0 || $quantity < 1 ) { return false; }
		// Predicate preserves no-ops and prevents a version outside CartItem's int range.
		return $this->wpdb->query( $this->wpdb->prepare(
			'UPDATE ' . $this->tables->cart_items() . ' SET quantity = %d, content_version = content_version + 1, updated_at = %s WHERE cart_id = %d AND post_id = %d AND quantity <> %d AND content_version < %d',
			$quantity, gmdate( 'Y-m-d H:i:s' ), $cart_id, $post_id, $quantity, PHP_INT_MAX
		) );
	}

	public function delete_item( int $cart_id, int $post_id ): bool {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $cart_id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return false; }
		if ( $cart_id <= 0 || $post_id <= 0 ) {
			return false;
		}

		$result = $this->wpdb->delete(
			$this->tables->cart_items(),
			array(
				'cart_id' => $cart_id,
				'post_id' => $post_id,
			),
			array( '%d', '%d' )
		);

		return false !== $result && $result > 0;
	}

	/** Caller must own the cart row lock. @return int|false */
	public function delete_item_locked( int $cart_id, int $post_id ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $cart_id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return false; }
		if ( $cart_id <= 0 || $post_id <= 0 ) { return false; }
		return $this->wpdb->delete( $this->tables->cart_items(), array( 'cart_id' => $cart_id, 'post_id' => $post_id ), array( '%d', '%d' ) );
	}

	public function delete_items( int $cart_id ): bool {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $cart_id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return false; }
		if ( $cart_id <= 0 ) {
			return false;
		}

		$result = $this->wpdb->delete(
			$this->tables->cart_items(),
			array( 'cart_id' => $cart_id ),
			array( '%d' )
		);

		return false !== $result;
	}

	/** Caller must own the cart row lock. @return int|false */
	public function delete_items_locked( int $cart_id ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->carts(), $cart_id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return false; }
		if ( $cart_id <= 0 ) { return false; }
		return $this->wpdb->delete( $this->tables->cart_items(), array( 'cart_id' => $cart_id ), array( '%d' ) );
	}

	private function owned_record( object $row ): CartRecord {
		$context = EpochOwnership::context( $this->wpdb, $this->tables );
		if ( null === $context || ! isset( $row->epoch_id ) || (int) $row->epoch_id !== $context->epoch_id() ) { throw new \RuntimeException( 'commerce_epoch_mismatch' ); }
		return CartRecord::from_row( $row );
	}
}
