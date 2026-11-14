<?php
/**
 * Uncached catalog reads performed while product mutexes are held.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

use wpdb;

defined( 'ABSPATH' ) || exit;

final class LockedCatalogReader {
	private wpdb $wpdb;
	private ?TransactionManager $transactions;

	public function __construct( wpdb $wpdb, ?TransactionManager $transactions = null ) {
		$this->wpdb = $wpdb;
		$this->transactions = $transactions;
	}

	/** @param int[] $post_ids Returns false for absence and null for SQL failure. */
	public function lock_products( array $post_ids ): ?bool {
		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		sort( $post_ids, SORT_NUMERIC );
		foreach ( $post_ids as $post_id ) {
			$locked = $this->lock_product( $post_id );
			if ( true !== $locked ) {
				return $locked;
			}
		}
		return true;
	}

	/** Returns true when locked, false when absent, and null on SQL failure. */
	public function lock_product( int $post_id ): ?bool {
		$this->wpdb->last_error = '';
		$id = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT ID FROM ' . $this->wpdb->posts . ' WHERE ID = %d FOR UPDATE', $post_id ) );
		if ( '' !== $this->wpdb->last_error ) {
			return null;
		}

		return null === $id ? false : (int) $id === $post_id;
	}

	/**
	 * Gets raw current catalog persistence without using WordPress object/meta caches.
	 * A locking read is essential: acquisition/conversion may have established a
	 * REPEATABLE READ snapshot before waiting for the product mutex. A plain SELECT
	 * could otherwise return stock from before a committed administrative edit.
	 *
	 * @return array{post_type:string,price:mixed,status:mixed,stock:mixed}|false|null Null on SQL failure.
	 */
	public function read_product( int $post_id ) {
		$keys = array( MetaKeys::PRICE_MINOR, MetaKeys::STATUS, MetaKeys::STOCK_QUANTITY );
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT p.post_type, pm.meta_key, pm.meta_value FROM ' . $this->wpdb->posts . ' p LEFT JOIN ' . $this->wpdb->postmeta . ' pm ON pm.post_id = p.ID AND pm.meta_key IN (%s, %s, %s) WHERE p.ID = %d ORDER BY pm.meta_id ASC FOR UPDATE',
				$keys[0],
				$keys[1],
				$keys[2],
				$post_id
			)
		);
		if ( '' !== $this->wpdb->last_error || ! is_array( $rows ) ) {
			return null;
		}
		if ( array() === $rows ) {
			return false;
		}
		$values = array_fill_keys( $keys, array() );
		foreach ( $rows as $row ) {
			$key = null === $row->meta_key ? '' : (string) $row->meta_key;
			if ( isset( $values[ $key ] ) ) {
				$values[ $key ][] = $row->meta_value;
			}
		}

		return array(
			'post_type' => (string) $rows[0]->post_type,
			'price'     => 1 === count( $values[ MetaKeys::PRICE_MINOR ] ) ? $values[ MetaKeys::PRICE_MINOR ][0] : null,
			'status'    => 1 === count( $values[ MetaKeys::STATUS ] ) ? $values[ MetaKeys::STATUS ][0] : null,
			'stock'     => 1 === count( $values[ MetaKeys::STOCK_QUANTITY ] ) ? $values[ MetaKeys::STOCK_QUANTITY ][0] : null,
		);
	}

	/** Guarded raw stock CAS. Requires the caller's open transaction and product lock. */
	public function decrement_stock( int $post_id, int $expected_stock, int $quantity ): bool {
		if ( null === $this->transactions || ! $this->transactions->is_commerce_admitted() || $this->transactions->is_outcome_uncertain() || $post_id <= 0 || $expected_stock < 0 || $quantity < 1 || $quantity > $expected_stock ) {
			return false;
		}
		$new_stock = $expected_stock - $quantity;
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE ' . $this->wpdb->postmeta . ' SET meta_value = %s WHERE post_id = %d AND meta_key = %s AND meta_value = %s',
				(string) $new_stock,
				$post_id,
				MetaKeys::STOCK_QUANTITY,
				(string) $expected_stock
			)
		);
		return 1 === $result;
	}
}
