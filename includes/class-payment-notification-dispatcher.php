<?php
/** One bounded dispatcher for both paid-Order cron hooks. */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-payment-notification-email.php';

final class PaymentNotificationDispatcher {
	public const PENDING = 1;
	public const SKIPPED = 2;
	private const CONSUMED = 3;
	private const ACCEPTED = 4;
	private const FAILED = 5;

	private const MAX_ATTEMPTS = 10;
	private const RUNTIME_NS = 8_000_000_000;

	/**
	 * @param \Closure(): \wpdb $connect Opens an owned, private connection.
	 * @param \Closure(): mixed $current_admin_email Reads the current stored site address.
	 */
	public function __construct( private \Closure $connect, private \Closure $current_admin_email ) {}

	public static function valid_address( mixed $address ): ?string {
		return is_string( $address ) && strlen( $address ) <= 254
			&& preg_match( '/\A[\x21-\x7e]+\z/', $address ) === 1
			&& false === strpbrk( $address, '"(),:;<>[\\]' )
			&& false !== filter_var( $address, FILTER_VALIDATE_EMAIL )
			? $address : null;
	}

	public function dispatch(): void {
		$deadline = hrtime( true ) + self::RUNTIME_NS;
		$roles = array( 'customer', 'admin' );
		for ( $i = 0; $i < self::MAX_ATTEMPTS && hrtime( true ) < $deadline; ++$i ) {
			$role = $roles[ $i % 2 ];
			$claim = $this->claim( $role );
			if ( null === $claim && hrtime( true ) < $deadline ) {
				$role = $roles[ ( $i + 1 ) % 2 ];
				$claim = $this->claim( $role );
			}
			if ( ! is_array( $claim ) ) {
				return;
			}
			if ( null === $claim['address'] ) {
				continue;
			}

			$accepted = false;
			try {
				[ $subject, $body ] = PaymentNotificationEmail::render( $role, $claim['order'], $claim['items'] );
				// Do not disclose Order details to a former administrator address.
				if ( 'admin' === $role && $claim['address'] !== self::valid_address( ( $this->current_admin_email )() ) ) {
					$this->observe( $role, $claim, self::SKIPPED );
					continue;
				}
				$accepted = wp_mail( $claim['address'], $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
			} catch ( \Throwable $error ) {
				// A transport or mail hook can throw after submission. The attempt is spent.
			}
			$this->observe( $role, $claim, true === $accepted ? self::ACCEPTED : self::FAILED );
		}
	}

	private function claim( string $role ): array|false|null {
		return $this->with_admission( static function ( \wpdb $db, TableNames $tables, TransactionManager $tx ) use ( $role ): ?array {
			$context = $tx->commerce_context();
			$table = $tables->orders();
			$state = $role . '_mail_state';
			$address_column = $role . '_mail_to';

			// Discover via the pending index, then take only the candidate's Order lock.
			$id = (int) $db->get_var( $db->prepare(
				"SELECT id FROM $table FORCE INDEX ($state) WHERE epoch_id = %d AND $state = %d ORDER BY id LIMIT 1",
				$context->epoch_id(), self::PENDING
			) );
			if ( $id <= 0 || '' !== $db->last_error ) {
				return null;
			}
			$orders = new OrderRepository( $db, $tables );
			$order = $orders->find_for_update( $id );
			if ( ! $order instanceof OrderRecord || OrderStatuses::PAID !== $order->status() || null === $order->paid_at() ) {
				return null;
			}

			// Read the current row version, including a claim that committed while we waited.
			$row = $db->get_row( $db->prepare(
				"SELECT notification_event_id, $address_column AS address FROM $table FORCE INDEX (PRIMARY)
				 WHERE id = %d AND epoch_id = %d AND $state = %d FOR UPDATE",
				$id, $context->epoch_id(), self::PENDING
			) );
			if ( '' !== $db->last_error || ! $row || (int) $row->notification_event_id <= 0 ) {
				return null;
			}
			$event_id = (int) $row->notification_event_id;
			// The original winning Event and completed Session stay with the live Order.
			$winner = $db->get_var( $db->prepare(
				'SELECT e.id FROM ' . $tables->payment_events() . ' e JOIN ' . $tables->payment_sessions() . ' s ON s.id = e.payment_session_id
				 WHERE e.id = %d AND e.epoch_id = %d AND s.epoch_id = e.epoch_id AND s.order_id = %d
				 AND e.processing_status = %s AND e.result_code = %s AND s.status = %s
				 AND BINARY s.provider = BINARY e.provider AND BINARY s.provider_event_id = BINARY e.provider_event_id',
				$event_id, $context->epoch_id(), $id, PaymentEventStatuses::PROCESSED, 'sale_converted', PaymentSessionStatuses::COMPLETED
			) );
			if ( '' !== $db->last_error || (int) $winner !== $event_id ) {
				return null;
			}
			$address = self::valid_address( $row->address );
			$items = null === $address ? array() : $orders->get_items_for_acquisition( $id );
			if ( false === $items || ( null !== $address && ! $items ) ) {
				return null;
			}
			$changed = $db->query( $db->prepare(
				"UPDATE $table FORCE INDEX (PRIMARY) SET $state = %d, $address_column = NULL
				 WHERE id = %d AND epoch_id = %d AND notification_event_id = %d AND $state = %d",
				null === $address ? self::SKIPPED : self::CONSUMED, $id, $context->epoch_id(), $event_id, self::PENDING
			) );
			if ( 1 !== $changed || ! $tx->commit() ) {
				return null;
			}

			return compact( 'context', 'id', 'event_id', 'address', 'order', 'items' );
		} );
	}

	private function observe( string $role, array $claim, int $result ): void {
		$this->with_admission( static function ( \wpdb $db, TableNames $tables, TransactionManager $tx ) use ( $role, $claim, $result ): void {
			$table = $tables->orders();
			$state = $role . '_mail_state';
			$changed = $db->query( $db->prepare(
				"UPDATE $table FORCE INDEX (PRIMARY) SET $state = %d WHERE id = %d AND epoch_id = %d
				 AND status = %s AND paid_at IS NOT NULL AND notification_event_id = %d AND $state = %d",
				$result, $claim['id'], $claim['context']->epoch_id(), OrderStatuses::PAID, $claim['event_id'], self::CONSUMED
			) );
			if ( 1 === $changed ) {
				$tx->commit();
			}
		}, $claim['context'] );
	}

	private function with_admission( \Closure $work, ?CommerceContext $expected = null ): mixed {
		$db = null;
		$tx = null;
		$result = null;
		try {
			$db = ( $this->connect )();
			// Claims revalidate under row locks. Use current reads so a concurrent claim
			// or observation on the same Order need not invalidate an older snapshot.
			if ( false === $db->query( 'SET SESSION innodb_lock_wait_timeout = 2, lock_wait_timeout = 2' )
				|| false === $db->query( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' ) ) {
				return null;
			}
			$tables = new TableNames( $db );
			$tx = new TransactionManager( $db, $tables );
			if ( $tx->supports_payment_notifications() && $tx->begin_commerce_shared( $expected ) ) {
				$result = $work( $db, $tables, $tx );
			}
		} catch ( \Throwable $error ) {
			$result = null;
		} finally {
			try {
				if ( $tx && $tx->is_active() && ! $tx->is_outcome_uncertain() ) {
					$tx->rollback();
				}
				if ( $tx && $tx->is_outcome_uncertain() ) {
					$result = false;
				}
			} catch ( \Throwable $error ) {
				$result = false;
			}
			try {
				if ( $db && false === $db->close() ) {
					$result = false;
				}
			} catch ( \Throwable $error ) {
				$result = false;
			}
		}
		return $result;
	}
}
