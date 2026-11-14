<?php
/** Authorized conditional exact stock setting. No orders, payments, carts or reservation mutations. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
use Throwable;
defined( 'ABSPATH' ) || exit;

final class AdminStockService {
	private bool $uncertain = false;
	public function __construct(
		private \wpdb $wpdb, private AdminStockRepository $stock, private LockedCatalogReader $catalog,
		private ReservationRepository $reservations, private TransactionManager $transactions,
		private PostTypes $post_types, private UtcClock $clock
	) {}

	/** Authorized uncached editor read; never invents a stock value for bad metadata. */
	public function inspect( int $post_id ): AdminStockResult {
		return $this->quietly( function () use ( $post_id ): AdminStockResult {
			if ( $this->uncertain || $this->transactions->is_active() ) { return AdminStockResult::failure( 'transaction_outcome_unknown' ); }
			if ( $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) ) { return AdminStockResult::failure( 'not_authorized' ); }
			try {
				$row = $this->stock->read( $post_id );
				$failure = $this->row_failure( $row );
				if ( null !== $failure ) { return AdminStockResult::failure( $failure ); }
				return 'valid' === $row['state'] ? AdminStockResult::success( $row['stock'], 'stock_read' ) : AdminStockResult::failure( $row['state'] );
			} catch ( Throwable $error ) { return AdminStockResult::failure( 'database_error' ); }
		} );
	}

	/** Caller verifies its request nonce; this service independently enforces capability and persisted CPT. */
	public function set_exact( int $post_id, mixed $expected, mixed $target ): AdminStockResult {
		$expected = StockQuantity::parse( $expected ); $target = StockQuantity::parse( $target );
		if ( null === $expected || null === $target ) { return AdminStockResult::failure( 'invalid_input' ); }
		return $this->quietly( fn(): AdminStockResult => $this->mutate( $post_id, $expected, $target, false ) );
	}

	/** Only call from WordPress's new auto-draft save_post hook. $update is the SERVER lifecycle flag, never request input. */
	public function initialize_auto_draft( int $post_id, bool $update ): AdminStockResult {
		if ( $update ) { return AdminStockResult::failure( 'initialization_not_allowed' ); }
		return $this->quietly( fn(): AdminStockResult => $this->mutate( $post_id, null, Catalog::DEFAULT_STOCK_QUANTITY, true ) );
	}

	private function mutate( int $post_id, ?int $expected, int $target, bool $initialize ): AdminStockResult {
		if ( $this->uncertain || $this->transactions->is_outcome_uncertain() ) { return AdminStockResult::failure( 'transaction_outcome_unknown' ); }
		if ( $this->transactions->is_active() ) { return AdminStockResult::failure( 'transaction_already_active' ); }
		if ( $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) || '' === $this->post_types->get_selected_post_type() ) { return AdminStockResult::failure( 'not_authorized' ); }
		try {
			if ( ! $this->transactions->supports_admin_stock() ) { return AdminStockResult::failure( 'transactions_not_supported' ); }
		} catch ( Throwable $error ) { return AdminStockResult::failure( 'database_error' ); }
		try {
			if ( ! $this->transactions->begin_commerce_shared() ) { return $this->transactions->is_outcome_uncertain() ? $this->unknown() : AdminStockResult::failure( 'database_error' ); }
		} catch ( Throwable $error ) { return $this->unknown(); }
		try {
			$locked = $this->catalog->lock_product( $post_id );
			if ( null === $locked ) { return $this->database_failure(); }
			if ( ! $locked ) { return $this->abort( 'product_invalid' ); }
			// Admission may have established a snapshot before waiting on the product lock.
			$row = $this->stock->read( $post_id, true );
			$failure = $this->row_failure( $row );
			if ( null !== $failure ) { return 'database_error' === $failure ? $this->database_failure() : $this->abort( $failure ); }
			if ( $initialize && 'auto-draft' !== $row['post_status'] ) { return $this->abort( 'initialization_not_allowed' ); }
			if ( $initialize && 'valid' === $row['state'] ) { return $this->finish( $post_id, $row['stock'], false ); }
			if ( ( ! $initialize && 'valid' !== $row['state'] ) || ( $initialize && 'stock_missing' !== $row['state'] ) ) { return $this->abort( $row['state'] ); }
			if ( ! $initialize && $expected !== $row['stock'] ) { return $this->abort( 'stock_conflict' ); }
			$reserved = $this->reservations->get_active_reserved_quantities_for_posts_for_update( array( $post_id ), $this->clock->now_mysql() );
			if ( false === $reserved ) { return $this->database_failure(); }
			if ( $target < ( $reserved[$post_id] ?? 0 ) ) { return $this->abort( 'below_reservations' ); }
			$changed = $initialize || $target !== $expected;
			if ( $changed && ! ( $initialize ? $this->stock->initialize( $post_id ) : $this->stock->set_exact( $post_id, $row['meta_id'], $expected, $target ) ) ) { return $this->database_failure(); }
			return $this->finish( $post_id, $target, $changed );
		} catch ( Throwable $error ) { return $this->abort( 'database_error' ); }
	}

	private function row_failure( array|false|null $row ): ?string {
		if ( false === $row ) { return 'database_error'; }
		return null === $row || '' === $this->post_types->get_selected_post_type() || $row['post_type'] !== $this->post_types->get_selected_post_type() ? 'product_invalid' : null;
	}

	private function finish( int $post_id, int $stock, bool $changed ): AdminStockResult {
		try { if ( ! $this->transactions->commit() ) { return $this->unknown(); } }
		catch ( Throwable $error ) { return $this->unknown(); }
		if ( $changed ) {
			try { wp_cache_delete( $post_id, 'post_meta' ); } catch ( Throwable $error ) { /* A cache failure cannot undo a confirmed commit. */ }
		}
		return AdminStockResult::success( $stock, $changed ? 'stock_updated' : 'stock_unchanged' );
	}

	private function database_failure(): AdminStockResult {
		$code = in_array( $this->transactions->last_error_number(), array( 1205, 1213 ), true ) ? 'stock_busy' : 'database_error';
		// No automatic retry: preserve the original expected value and require a fresh deliberate submission.
		return $this->abort( $code );
	}
	private function abort( string $code ): AdminStockResult {
		try { if ( ! $this->transactions->rollback() ) { return $this->unknown(); } }
		catch ( Throwable $error ) { return $this->unknown(); }
		return AdminStockResult::failure( $code );
	}
	private function unknown(): AdminStockResult { $this->uncertain = true; return AdminStockResult::failure( 'transaction_outcome_unknown' ); }
	private function quietly( callable $operation ): AdminStockResult {
		$can_suppress = method_exists( $this->wpdb, 'suppress_errors' );
		$previous = $can_suppress ? $this->wpdb->suppress_errors( true ) : false;
		try { return $operation(); }
		finally { if ( $can_suppress ) { $this->wpdb->suppress_errors( $previous ); } }
	}
}
