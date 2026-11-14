<?php
/** Raw stock persistence. Mutations are internal to AdminStockService's product-locked transaction. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class AdminStockRepository {
	public function __construct( private \wpdb $wpdb, private TransactionManager $transactions ) {}

	/** No WordPress meta cache or legacy normalization. False = SQL failure; null = missing product.
	 * @return array{post_type:string,post_status:string,state:string,stock:?int,meta_id:?int}|false|null
	 */
	public function read( int $post_id, bool $for_update = false ): array|false|null {
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare(
			"SELECT p.post_type, p.post_status, pm.meta_id, pm.meta_value FROM {$this->wpdb->posts} p
			LEFT JOIN {$this->wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s WHERE p.ID = %d ORDER BY pm.meta_id ASC" . ( $for_update ? ' FOR UPDATE' : '' ),
			MetaKeys::STOCK_QUANTITY, $post_id
		) );
		if ( '' !== $this->wpdb->last_error || ! is_array( $rows ) ) { return false; }
		if ( ! $rows ) { return null; }
		$stock = count( $rows ) === 1 && null !== $rows[0]->meta_id ? StockQuantity::parse( $rows[0]->meta_value ) : null;
		$state = count( $rows ) > 1 ? 'stock_duplicate' : ( null === $rows[0]->meta_id ? 'stock_missing' : ( null === $stock ? 'stock_invalid' : 'valid' ) );
		return array( 'post_type' => (string) $rows[0]->post_type, 'post_status' => (string) $rows[0]->post_status, 'state' => $state, 'stock' => $stock, 'meta_id' => null === $rows[0]->meta_id ? null : (int) $rows[0]->meta_id );
	}

	/** Caller has proved exactly one valid row while holding the product mutex. */
	public function set_exact( int $post_id, int $meta_id, int $expected, int $target ): bool {
		if ( ! $this->transactions->is_commerce_admitted() || $this->transactions->is_outcome_uncertain() || $post_id < 1 || $meta_id < 1 || $expected < 0 || $target < 0 || $expected === $target ) { return false; }
		return 1 === $this->wpdb->query( $this->wpdb->prepare(
			"UPDATE {$this->wpdb->postmeta} SET meta_value = %s WHERE meta_id = %d AND post_id = %d AND meta_key = %s AND BINARY meta_value = BINARY %s",
			(string) $target, $meta_id, $post_id, MetaKeys::STOCK_QUANTITY, (string) $expected
		) );
	}

	/** Fixed default only, for a newly inserted auto-draft; never a legacy metadata repair. */
	public function initialize( int $post_id ): bool {
		if ( ! $this->transactions->is_commerce_admitted() || $this->transactions->is_outcome_uncertain() || $post_id < 1 ) { return false; }
		return 1 === $this->wpdb->query( $this->wpdb->prepare(
			"INSERT INTO {$this->wpdb->postmeta} (post_id, meta_key, meta_value)
			SELECT ID, %s, %s FROM {$this->wpdb->posts} p WHERE ID = %d AND post_status = 'auto-draft'
			AND NOT EXISTS (SELECT 1 FROM {$this->wpdb->postmeta} pm WHERE pm.post_id = p.ID AND pm.meta_key = %s)",
			MetaKeys::STOCK_QUANTITY, (string) Catalog::DEFAULT_STOCK_QUANTITY, $post_id, MetaKeys::STOCK_QUANTITY
		) );
	}
}
