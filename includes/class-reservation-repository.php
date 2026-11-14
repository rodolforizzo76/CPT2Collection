<?php
/**
 * Reservation database persistence.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

use wpdb;

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-transaction-manager.php';
require_once __DIR__ . '/class-epoch-ownership.php';

/**
 * Persists reservation rows without checkout orchestration or stock mutation.
 */
final class ReservationRepository {
	private wpdb $wpdb;
	private TableNames $tables;

	public function __construct( wpdb $wpdb, TableNames $tables ) {
		$this->wpdb   = $wpdb;
		$this->tables = $tables;
	}

	/** @return ReservationRecord|false */
	public function create( int $order_id, int $order_item_id, int $post_id, int $quantity, string $status, string $created_at, string $updated_at, string $expires_at ) {
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return false; }
		if ( $order_id <= 0 || $order_item_id <= 0 || $post_id <= 0 || $quantity < 1 || ! ReservationStatuses::is_valid( $status ) || '' === $created_at || '' === $updated_at || '' === $expires_at ) {
			return false;
		}

		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->orders(), $order_id ) ) { return false; }
		$parent = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT order_id, post_id FROM ' . $this->tables->order_items() . ' WHERE id = %d', $order_item_id ) );
		if ( null === $parent || (int) $parent->order_id !== $order_id || (int) $parent->post_id !== $post_id ) { return false; }
		$result = $this->wpdb->insert(
			$this->tables->reservations(),
			array(
				'epoch_id' => TransactionManager::context_on( $this->wpdb )->epoch_id(),
				'order_id'      => $order_id,
				'order_item_id' => $order_item_id,
				'post_id'       => $post_id,
				'quantity'      => $quantity,
				'status'        => $status,
				'created_at'    => $created_at,
				'updated_at'    => $updated_at,
				'expires_at'    => $expires_at,
			),
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result || $this->wpdb->insert_id <= 0 ) {
			return false;
		}

		return $this->find( (int) $this->wpdb->insert_id ) ?: false;
	}

	public function find( int $reservation_id ): ?ReservationRecord {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->reservations(), $reservation_id ) ) { return null; }
		if ( $reservation_id <= 0 ) {
			return null;
		}
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT epoch_id, id, order_id, order_item_id, post_id, quantity, status, created_at, updated_at, expires_at, released_at, converted_at FROM ' . $this->tables->reservations() . ' WHERE id = %d LIMIT 1', $reservation_id ) );

		return null === $row ? null : $this->owned_record( $row );
	}

	public function find_by_order_item( int $order_item_id ): ?ReservationRecord {
		if ( $order_item_id <= 0 ) {
			return null;
		}
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT epoch_id, id, order_id, order_item_id, post_id, quantity, status, created_at, updated_at, expires_at, released_at, converted_at FROM ' . $this->tables->reservations() . ' WHERE order_item_id = %d LIMIT 1', $order_item_id ) );

		return null === $row ? null : $this->owned_record( $row );
	}

	/** @return ReservationRecord[] */
	public function get_for_order( int $order_id ): array {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->orders(), $order_id ) ) { return array(); }
		if ( $order_id <= 0 ) {
			return array();
		}
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT epoch_id, id, order_id, order_item_id, post_id, quantity, status, created_at, updated_at, expires_at, released_at, converted_at FROM ' . $this->tables->reservations() . ' WHERE order_id = %d ORDER BY id ASC', $order_id ) );

		return empty( $rows ) ? array() : array_map( fn( object $row ): ReservationRecord => $this->owned_record( $row ), $rows );
	}

	/** @return ReservationRecord[]|false */
	public function get_for_order_for_update( int $order_id ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->orders(), $order_id ) ) { return false; }
		if ( $order_id <= 0 ) { return array(); }
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT epoch_id, id, order_id, order_item_id, post_id, quantity, status, created_at, updated_at, expires_at, released_at, converted_at FROM ' . $this->tables->reservations() . ' WHERE order_id = %d ORDER BY id ASC FOR UPDATE', $order_id ) );
		if ( '' !== $this->wpdb->last_error || null === $rows ) { return false; }
		return array_map( fn( object $row ): ReservationRecord => $this->owned_record( $row ), $rows );
	}

    /**
	 * Reads every reservation owned by the order or attached to one of its items.
	 * This also exposes a corrupt row whose order_id does not match its order item.
     * The owning order row serializes acquisition, so this intentionally avoids
	 * taking reservation gap locks before the product mutexes are acquired.
	 *
	 * @param int[] $order_item_ids Expected order-item identifiers.
	 * @return ReservationRecord[]|false
	 */
	public function get_for_order_and_items_for_acquisition( int $order_id, array $order_item_ids ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->orders(), $order_id ) ) { return false; }
		$order_item_ids = array_values( array_unique( array_filter( array_map( 'intval', $order_item_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( $order_id <= 0 || array() === $order_item_ids ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $order_item_ids ), '%d' ) );
		$args         = array_merge( array( $order_id ), $order_item_ids );
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT epoch_id, id, order_id, order_item_id, post_id, quantity, status, created_at, updated_at, expires_at, released_at, converted_at FROM ' . $this->tables->reservations() . " WHERE order_id = %d OR order_item_id IN ({$placeholders}) ORDER BY id ASC", ...$args ) );
		if ( '' !== $this->wpdb->last_error || null === $rows ) {
			return false;
		}

		return empty( $rows ) ? array() : array_map( fn( object $row ): ReservationRecord => $this->owned_record( $row ), $rows );
	}

	/**
	 * Nonlocking conversion discovery. The locked order row prevents a cooperating
	 * writer from adding order reservations before product mutexes are acquired.
	 *
	 * @param int[] $order_item_ids Expected immutable order-item identifiers.
	 * @return ReservationRecord[]|false
	 */
	public function discover_for_conversion( int $order_id, array $order_item_ids ) {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->orders(), $order_id ) ) { return false; }
		return $this->get_for_order_and_items_for_acquisition( $order_id, $order_item_ids );
	}

	/** @param int[] $reservation_ids @return ReservationRecord[]|false */
	public function get_by_ids_for_conversion_for_update( array $reservation_ids ) {
		$reservation_ids = $this->positive_ids( $reservation_ids );
		if ( array() === $reservation_ids ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $reservation_ids ), '%d' ) );
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT epoch_id, id, order_id, order_item_id, post_id, quantity, status, created_at, updated_at, expires_at, released_at, converted_at FROM ' . $this->tables->reservations() . " WHERE id IN ({$placeholders}) ORDER BY post_id ASC, id ASC FOR UPDATE", ...$reservation_ids ) );
		if ( '' !== $this->wpdb->last_error || null === $rows ) {
			return false;
		}
		return array_map( fn( object $row ): ReservationRecord => $this->owned_record( $row ), $rows );
	}

	/** @param int[] $post_ids @return ReservationRecord[]|false */
	public function get_active_for_posts_for_conversion_for_update( array $post_ids, string $current_utc ) {
		$post_ids = $this->positive_ids( $post_ids );
		if ( array() === $post_ids || '' === $current_utc ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );
		$args = array_merge( $post_ids, array( ReservationStatuses::ACTIVE, $current_utc ) );
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT epoch_id, id, order_id, order_item_id, post_id, quantity, status, created_at, updated_at, expires_at, released_at, converted_at FROM ' . $this->tables->reservations() . ' WHERE ' . EpochOwnership::predicate( $this->wpdb, $this->tables ) . " AND post_id IN ({$placeholders}) AND status = %s AND expires_at > %s ORDER BY post_id ASC, id ASC FOR UPDATE", ...$args ) );
		if ( '' !== $this->wpdb->last_error || null === $rows ) {
			return false;
		}
		return array_map( fn( object $row ): ReservationRecord => $this->owned_record( $row ), $rows );
	}

	/** @param mixed[] $ids @return int[] */
	private function positive_ids( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/**
	 * Performs a current locking read and returns active quantities by product.
	 *
	 * @param int[] $post_ids Product identifiers.
	 * @return array<int,int>|false
	 */
	public function get_active_reserved_quantities_for_posts_for_update( array $post_ids, string $current_utc ) {
		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( array() === $post_ids || '' === $current_utc ) {
			return array();
		}
		sort( $post_ids, SORT_NUMERIC );
		$placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );
		$args         = array_merge( $post_ids, array( ReservationStatuses::ACTIVE, $current_utc ) );
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT epoch_id, id, post_id, quantity FROM ' . $this->tables->reservations() . ' WHERE ' . EpochOwnership::predicate( $this->wpdb, $this->tables ) . " AND post_id IN ({$placeholders}) AND status = %s AND expires_at > %s ORDER BY post_id ASC, id ASC FOR UPDATE", ...$args ) );
		if ( '' !== $this->wpdb->last_error || null === $rows ) {
			return false;
		}

		$totals = array_fill_keys( $post_ids, 0 );
		foreach ( $rows as $row ) {
			$post_id  = (int) $row->post_id;
			$quantity = (int) $row->quantity;
			if ( ! isset( $totals[ $post_id ] ) || $quantity < 1 || $quantity > PHP_INT_MAX - $totals[ $post_id ] ) {
				return false;
			}
			$totals[ $post_id ] += $quantity;
		}

		return $totals;
	}

	/** @return ReservationRecord[] */
	public function get_for_post( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return array();
		}
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT epoch_id, id, order_id, order_item_id, post_id, quantity, status, created_at, updated_at, expires_at, released_at, converted_at FROM ' . $this->tables->reservations() . ' WHERE ' . EpochOwnership::predicate( $this->wpdb, $this->tables ) . ' AND post_id = %d ORDER BY id ASC', $post_id ) );

		return empty( $rows ) ? array() : array_map( fn( object $row ): ReservationRecord => $this->owned_record( $row ), $rows );
	}

	public function update_status( int $reservation_id, string $expected_status, string $new_status, string $updated_at, ?string $released_at = null, ?string $converted_at = null ): bool {
		if ( ! EpochOwnership::owns( $this->wpdb, $this->tables, $this->tables->reservations(), $reservation_id ) ) { return false; }
		if ( ! TransactionManager::admitted_on( $this->wpdb ) ) { return false; }
		if ( $reservation_id <= 0 || ! ReservationStatuses::is_valid( $expected_status ) || ! ReservationStatuses::is_valid( $new_status ) || '' === $updated_at ) {
			return false;
		}
		$data    = array( 'status' => $new_status, 'updated_at' => $updated_at );
		$formats = array( '%s', '%s' );
		if ( null !== $released_at ) {
			$data['released_at'] = $released_at;
			$formats[]           = '%s';
		}
		if ( null !== $converted_at ) {
			$data['converted_at'] = $converted_at;
			$formats[]            = '%s';
		}

		$result = $this->wpdb->update( $this->tables->reservations(), $data, array( 'id' => $reservation_id, 'status' => $expected_status ), $formats, array( '%d', '%s' ) );

		return false !== $result && $result > 0;
	}

	public function get_active_reserved_quantity_for_post( int $post_id, string $current_utc ): int {
		if ( $post_id <= 0 || '' === $current_utc ) {
			return 0;
		}
		$total = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COALESCE(SUM(quantity), 0) FROM ' . $this->tables->reservations() . ' WHERE ' . EpochOwnership::predicate( $this->wpdb, $this->tables ) . ' AND post_id = %d AND status = %s AND expires_at > %s', $post_id, ReservationStatuses::ACTIVE, $current_utc ) );

		return max( 0, (int) $total );
	}
	private function owned_record( object $row ): ReservationRecord {
		$context = EpochOwnership::context( $this->wpdb, $this->tables );
		if ( null === $context || ! isset( $row->epoch_id ) || (int) $row->epoch_id !== $context->epoch_id() ) { throw new \RuntimeException( 'commerce_epoch_mismatch' ); }
		return ReservationRecord::from_row( $row );
	}
}
