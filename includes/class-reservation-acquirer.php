<?php
/**
 * Atomic reservation acquisition orchestration.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

use Throwable;

defined( 'ABSPATH' ) || exit;

final class ReservationAcquirer {
	private const MAX_ATTEMPTS = 3;
	private const RETRYABLE_DATABASE_ERRORS = array( 1205, 1213 );
	private OrderRepository $orders;
	private ReservationRepository $reservations;
	private TransactionManager $transactions;
	private LockedCatalogReader $catalog;
	private PostTypes $post_types;
	private UtcClock $clock;
	private ReservationSetValidator $reservation_validator;
	/** @var array{attempts:int,retries:int,recovered:bool,error_number:int,error_type:string,failed_stage:string,exception_class:string,exception_message:string,elapsed_ms:int} */
	private array $diagnostics = array();

	public function __construct( OrderRepository $orders, ReservationRepository $reservations, TransactionManager $transactions, LockedCatalogReader $catalog, PostTypes $post_types, UtcClock $clock ) {
		$this->orders       = $orders;
		$this->reservations = $reservations;
		$this->transactions = $transactions;
		$this->catalog      = $catalog;
		$this->post_types   = $post_types;
		$this->clock        = $clock;
		$this->reservation_validator = new ReservationSetValidator();
	}

	public function acquire( int $order_id ): ReservationOperationResult {
		$started_at = microtime( true );
		// Configuration is intentionally a single, unlocked snapshot for this operation.
		$post_type       = $this->post_types->get_selected_post_type();
		$currency        = Money::get_currency();
		$duration_seconds = CommerceSettings::get_reservation_duration_seconds();

		if ( ! $this->transactions->supports_reservation_acquisition() ) {
			$this->set_diagnostics( 0, 0, false, 0, '', 'engine_check', $started_at );
			return $this->failure( 'transactions_not_supported', __( 'Atomic stock reservations are not supported by the current database configuration.', 'cpt2collection' ), $order_id );
		}

		$retry_error_number = 0;
		$retry_failed_stage = '';
		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			$this->last_database_error_number = 0;
			$this->last_failed_stage          = '';
			$this->last_exception_class       = '';
			$this->last_exception_message     = '';
			$result = $this->attempt( $order_id, $post_type, $currency, $duration_seconds );
			$retryable = in_array( $this->last_database_error_number, self::RETRYABLE_DATABASE_ERRORS, true );
			if ( 'database_error' !== $result->get_code() || ! $retryable || self::MAX_ATTEMPTS === $attempt ) {
				$recovered    = $attempt > 1 && 'database_error' !== $result->get_code();
				$error_number = $recovered ? $retry_error_number : $this->last_database_error_number;
				$failed_stage = $recovered ? $retry_failed_stage : $this->last_failed_stage;
				$this->set_diagnostics( $attempt, $attempt - 1, $recovered, $error_number, $this->database_error_type( $error_number ), $failed_stage, $started_at );
				return $result;
			}
			$retry_error_number = $this->last_database_error_number;
			$retry_failed_stage = $this->last_failed_stage;

			// The failed transaction has been rolled back. Jitter outside a transaction.
			usleep( random_int( 25_000 * $attempt, 75_000 * $attempt ) );
		}

		return $this->database_failure( $order_id );
	}

	private int $last_database_error_number = 0;
	private string $last_failed_stage = '';
	private string $last_exception_class = '';
	private string $last_exception_message = '';

	private function attempt( int $order_id, string $post_type, string $currency, int $duration_seconds ): ReservationOperationResult {
		if ( ! $this->transactions->begin_commerce_shared() ) {
			if ( $this->transactions->is_outcome_uncertain() ) {
				$this->last_database_error_number = 0;
				$this->last_failed_stage          = 'begin';
				return $this->transaction_outcome_unknown( $order_id );
			}
			return $this->database_failure_at( $order_id, 'begin' );
		}

		try {
			$order = $this->orders->find_for_update( $order_id );
			if ( false === $order ) {
				return $this->rollback_database_failure( $order_id, 'order_lock' );
			}
			if ( null === $order ) {
				return $this->rollback_failure( 'order_not_found', __( 'The requested order does not exist.', 'cpt2collection' ), $order_id );
			}
			if ( OrderStatuses::PENDING !== $order->status() ) {
				return $this->rollback_failure( 'order_not_pending', __( 'The order is not eligible for a new reservation.', 'cpt2collection' ), $order_id );
			}

			$items = $this->orders->get_items_for_acquisition( $order_id );
			if ( false === $items ) {
				return $this->rollback_database_failure( $order_id, 'order_items_read' );
			}
			if ( array() === $items ) {
				return $this->rollback_failure( 'order_has_no_items', __( 'The order does not contain any items.', 'cpt2collection' ), $order_id );
			}
			if ( ! $this->is_valid_order_snapshot( $order, $items ) ) {
				return $this->rollback_failure( 'order_invalid', __( 'The order contains invalid snapshot data.', 'cpt2collection' ), $order_id );
			}

			$now_timestamp = $this->clock->now_timestamp();
			$now            = $this->clock->format_mysql( $now_timestamp );
			$existing       = $this->reservations->get_for_order_and_items_for_acquisition( $order_id, array_map( static fn( OrderItem $item ): int => $item->id(), $items ) );
			if ( false === $existing ) {
				return $this->rollback_database_failure( $order_id, 'idempotency_read' );
			}
			if ( array() !== $existing ) {
				if ( $this->reservation_validator->validate( $order_id, $items, $existing, $now )->is_valid() ) {
					if ( ! $this->transactions->commit() ) {
						$this->capture_database_failure( 'commit' );
						return ReservationOperationResult::failure( 'transaction_outcome_unknown', __( 'The database did not confirm the reservation transaction outcome.', 'cpt2collection' ), $order_id );
					}
					return ReservationOperationResult::success( 'reservation_already_active', __( 'The order already has an active reservation.', 'cpt2collection' ), $order_id );
				}
				return $this->rollback_failure( 'reservation_conflict', __( 'The order has an incompatible reservation history.', 'cpt2collection' ), $order_id );
			}

			$requested = array();
			foreach ( $items as $item ) {
				$post_id = $item->post_id();
				if ( ! isset( $requested[ $post_id ] ) ) {
					$requested[ $post_id ] = 0;
				}
				if ( $item->quantity() > PHP_INT_MAX - $requested[ $post_id ] ) {
					return $this->rollback_failure( 'order_invalid', __( 'The order contains invalid snapshot data.', 'cpt2collection' ), $order_id );
				}
				$requested[ $post_id ] += $item->quantity();
			}
			$post_ids = array_keys( $requested );
			sort( $post_ids, SORT_NUMERIC );
			foreach ( $post_ids as $post_id ) {
				$locked = $this->catalog->lock_product( $post_id );
				if ( null === $locked ) {
					return $this->rollback_database_failure( $order_id, 'product_lock' );
				}
				if ( ! $locked ) {
					return $this->rollback_failure( 'item_not_found', __( 'An order item no longer exists.', 'cpt2collection' ), $order_id );
				}
			}

			$snapshots = array();
			foreach ( $post_ids as $post_id ) {
				$snapshot = $this->catalog->read_product( $post_id );
				if ( null === $snapshot ) {
					return $this->rollback_database_failure( $order_id, 'catalog_read' );
				}
				if ( false === $snapshot ) {
					return $this->rollback_failure( 'item_not_found', __( 'An order item no longer exists.', 'cpt2collection' ), $order_id );
				}
				if ( $snapshot['post_type'] !== $post_type || 'available' !== $snapshot['status'] ) {
					return $this->rollback_failure( 'item_not_available', __( 'An order item is no longer available.', 'cpt2collection' ), $order_id );
				}
				$price = $this->positive_integer( $snapshot['price'] );
				$stock = $this->nonnegative_integer( $snapshot['stock'] );
				if ( null === $price || null === $stock ) {
					return $this->rollback_failure( 'item_invalid', __( 'An order item has invalid catalog data.', 'cpt2collection' ), $order_id );
				}
				$snapshots[ $post_id ] = array( 'price' => $price, 'stock' => $stock );
			}

			foreach ( $items as $item ) {
				if ( $currency !== $item->currency() || $currency !== $order->currency() ) {
					return $this->rollback_failure( 'currency_mismatch', __( 'The catalog currency no longer matches the order.', 'cpt2collection' ), $order_id );
				}
				if ( $snapshots[ $item->post_id() ]['price'] !== $item->unit_price_minor() ) {
					return $this->rollback_failure( 'snapshot_mismatch', __( 'An order item price no longer matches the catalog.', 'cpt2collection' ), $order_id );
				}
			}

			$reserved = $this->reservations->get_active_reserved_quantities_for_posts_for_update( $post_ids, $now );
			if ( false === $reserved ) {
				return $this->rollback_database_failure( $order_id, 'active_reservation_read' );
			}
			foreach ( $post_ids as $post_id ) {
				$available = $snapshots[ $post_id ]['stock'] - ( $reserved[ $post_id ] ?? 0 );
				if ( $available < $requested[ $post_id ] ) {
					return $this->rollback_failure( 'insufficient_stock', __( 'There is not enough stock to reserve every order item.', 'cpt2collection' ), $order_id );
				}
			}

			$expires_at = $this->clock->format_mysql( $now_timestamp + $duration_seconds );
			foreach ( $items as $item ) {
				$created = $this->reservations->create( $order_id, $item->id(), $item->post_id(), $item->quantity(), ReservationStatuses::ACTIVE, $now, $now, $expires_at );
				if ( false === $created ) {
					return $this->rollback_database_failure( $order_id, 'reservation_insert' );
				}
			}

			if ( ! $this->transactions->commit() ) {
				$this->capture_database_failure( 'commit' );
				return ReservationOperationResult::failure( 'transaction_outcome_unknown', __( 'The database did not confirm the reservation transaction outcome.', 'cpt2collection' ), $order_id );
			}
			return ReservationOperationResult::success( 'reservation_acquired', __( 'Stock was reserved for the order.', 'cpt2collection' ), $order_id );
			} catch ( Throwable $error ) {
				$this->capture_exception_failure( $error );
				if ( ! $this->rollback_confirmed() ) {
					return $this->transaction_outcome_unknown( $order_id );
				}
				return $this->database_failure( $order_id );
		}
	}

	/** @param OrderItem[] $items */
	private function is_valid_order_snapshot( OrderRecord $order, array $items ): bool {
		$subtotal = 0;
		$total    = 0;
		foreach ( $items as $item ) {
			if ( ! $item instanceof OrderItem || $item->order_id() !== $order->id() || $item->unit_price_minor() < 1 || $item->quantity() < 1 || $item->currency() !== $order->currency() ) {
				return false;
			}
			if ( $item->quantity() > intdiv( PHP_INT_MAX, $item->unit_price_minor() ) ) {
				return false;
			}
			$line = $item->unit_price_minor() * $item->quantity();
			if ( $item->line_subtotal_minor() !== $line || $item->line_total_minor() !== $line || $line > PHP_INT_MAX - $subtotal || $line > PHP_INT_MAX - $total ) {
				return false;
			}
			$subtotal += $line;
			$total    += $line;
		}

		return $subtotal === $order->subtotal_minor() && $total === $order->total_minor();
	}

	private function positive_integer( $value ): ?int {
		$value = $this->nonnegative_integer( $value );
		return null !== $value && $value > 0 ? $value : null;
	}

	private function nonnegative_integer( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 0 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9]\d*)$/', $value ) ) {
			return null;
		}
		$integer = filter_var( $value, FILTER_VALIDATE_INT );
		return is_int( $integer ) && $integer >= 0 ? $integer : null;
	}

	private function rollback_failure( string $code, string $message, int $order_id ): ReservationOperationResult {
		if ( ! $this->rollback_confirmed() ) {
			return $this->transaction_outcome_unknown( $order_id );
		}
		return $this->failure( $code, $message, $order_id );
	}

	private function rollback_database_failure( int $order_id, string $stage ): ReservationOperationResult {
		$this->capture_database_failure( $stage );
		if ( ! $this->rollback_confirmed() ) {
			return $this->transaction_outcome_unknown( $order_id );
		}
		return $this->database_failure( $order_id );
	}

	private function rollback_confirmed(): bool {
		$rolled_back = $this->transactions->rollback();
		if ( ! $rolled_back || $this->transactions->is_outcome_uncertain() ) {
			$this->last_database_error_number = 0;
			return false;
		}
		return true;
	}

	private function transaction_outcome_unknown( int $order_id ): ReservationOperationResult {
		return ReservationOperationResult::failure( 'transaction_outcome_unknown', __( 'The database did not confirm the reservation transaction outcome.', 'cpt2collection' ), $order_id );
	}

	private function database_failure_at( int $order_id, string $stage ): ReservationOperationResult {
		$this->capture_database_failure( $stage );
		return $this->database_failure( $order_id );
	}

	private function capture_database_failure( string $stage ): void {
		$this->last_database_error_number = $this->transactions->last_error_number();
		$this->last_failed_stage          = $stage;
	}

	private function capture_exception_failure( Throwable $error ): void {
		$this->capture_database_failure( 'exception' );
		$this->last_exception_class   = preg_replace( '/[^A-Za-z0-9_\\\\]/', '', get_class( $error ) ) ?: 'Throwable';
		$message                      = strip_tags( $error->getMessage() );
		$message                      = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $message ) ?? '';
		$this->last_exception_message = substr( trim( $message ), 0, 500 );
	}

	private function database_error_type( int $number ): string {
		if ( 1213 === $number ) {
			return 'deadlock';
		}
		if ( 1205 === $number ) {
			return 'lock_wait_timeout';
		}

		return 0 === $number ? '' : 'database_error';
	}

	private function set_diagnostics( int $attempts, int $retries, bool $recovered, int $error_number, string $error_type, string $failed_stage, float $started_at ): void {
		$this->diagnostics = array(
			'attempts'     => $attempts,
			'retries'      => $retries,
			'recovered'    => $recovered,
			'error_number' => $error_number,
			'error_type'   => $error_type,
			'failed_stage' => $failed_stage,
			'exception_class' => $this->last_exception_class,
			'exception_message' => $this->last_exception_message,
			'elapsed_ms'   => max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) ),
		);
	}

	/**
	 * Returns safe operational diagnostics for administrator-only verification.
	 *
	 * @return array{attempts:int,retries:int,recovered:bool,error_number:int,error_type:string,failed_stage:string,exception_class:string,exception_message:string,elapsed_ms:int}
	 */
	public function get_last_diagnostics(): array {
		return $this->diagnostics;
	}

	private function database_failure( int $order_id ): ReservationOperationResult {
		return $this->failure( 'database_error', __( 'The reservation could not be completed because of a database error.', 'cpt2collection' ), $order_id );
	}

	private function failure( string $code, string $message, int $order_id ): ReservationOperationResult {
		return ReservationOperationResult::failure( $code, $message, max( 0, $order_id ) );
	}
}
