<?php
/** Read-only, paginated order administration projection. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-epoch-ownership.php';
require_once __DIR__ . '/class-order-customer-migration.php';

final class OrderAdminReadRepository {
	public const PER_PAGE = 20;
	private int $epoch = 0;
	private ?CommerceContext $context = null;
	private function capture_epoch(): bool { $c=EpochOwnership::context($this->wpdb,$this->tables); $this->context=$c; $this->epoch=$c?->epoch_id()??0; return $this->epoch>0; }
	private function epoch_predicate(string $alias=''):string { return ($alias ? $alias.'.':'').'epoch_id='.$this->epoch." AND ".$this->epoch."=(SELECT current_epoch_id FROM ".$this->tables->commerce_control()." WHERE id=1 AND state='ready')"; }
	private function epoch_unchanged():bool { $c=EpochOwnership::context($this->wpdb,$this->tables);return null!==$c&&$this->context!==null&&$c->equals($this->context); }

	public function __construct( private \wpdb $wpdb, private TableNames $tables ) {}

	/** Validate raw, unslashed discovery inputs without coercing malformed filters away.
	 * Dates in the form include the selected final day; SQL uses the next UTC midnight.
	 * @return array{values:array<string,string>,errors:array<string,string>,order_id:?int,from:?string,to:?string}
	 */
	public static function validate_filters( array $input ): array {
		$values = array(); $errors = array(); $order_id = null; $from = null; $to = null;
		foreach ( array( 'order_reference', 'order_status', 'created_from', 'created_to', 'payment_review' ) as $key ) {
			$value = array_key_exists( $key, $input ) ? $input[$key] : '';
			if ( ! is_string( $value ) || strlen( $value ) > 64 ) {
				$errors[$key] = __( 'A filter value is invalid. Correct the filters or clear them to continue.', 'cpt2collection' );
				$value = '';
			}
			$values[$key] = $value;
		}
		if ( '' !== $values['order_reference'] ) {
			$reference = $values['order_reference'];
			$number = str_starts_with( $reference, 'C2C-' ) ? substr( $reference, 4 ) : $reference;
			$id = preg_match( '/\A[1-9][0-9]*\z/', $number ) ? filter_var( $number, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) : false;
			if ( false === $id ) { $errors['order_reference'] = __( 'Enter an exact order reference, such as C2C-123 or 123.', 'cpt2collection' ); }
			else { $order_id = $id; }
		}
		if ( '' !== $values['order_status'] && ! OrderStatuses::is_valid( $values['order_status'] ) ) {
			$errors['order_status'] = __( 'Choose a valid order status.', 'cpt2collection' );
		}
		if ( ! in_array( $values['payment_review'], array( '', 'required' ), true ) ) {
			$errors['payment_review'] = __( 'Choose a valid payment review filter.', 'cpt2collection' );
		}
		foreach ( array( 'created_from', 'created_to' ) as $key ) {
			$value = $values[$key];
			if ( '' === $value ) { continue; }
			$date = preg_match( '/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/', $value ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) ) : false;
			if ( false === $date || $date->format( 'Y-m-d' ) !== $value || ( 'created_to' === $key && '9999-12-31' === $value ) ) {
				$errors[$key] = __( 'Enter a valid date in YYYY-MM-DD format (years 1000–9999; the final date must be before 9999-12-31).', 'cpt2collection' );
				continue;
			}
			if ( 'created_from' === $key ) { $from = $date->format( 'Y-m-d H:i:s' ); }
			else { $to = $date->modify( '+1 day' )->format( 'Y-m-d H:i:s' ); }
		}
		if ( null !== $from && null !== $to && $from >= $to ) {
			$errors['created_to'] = __( 'Created to must be on or after Created from.', 'cpt2collection' );
		}
		return array( 'values' => $values, 'errors' => $errors, 'order_id' => $order_id, 'from' => $from, 'to' => $to );
	}

	/** SQL aliases s/e are local to each session review projection/predicate. */
	private function payment_review_predicate(): string {
		return "s.reconciliation_required_at IS NOT NULL OR s.reconciliation_code IS NOT NULL OR s.reconciliation_event_id IS NOT NULL
			OR EXISTS (SELECT 1 FROM {$this->tables->payment_events()} e WHERE e.payment_session_id = s.id AND e.epoch_id=s.epoch_id AND e.processing_status = 'attention')";
	}

	/**
	 * Ordinary committed reads scoped to one order. No catalog/cart hydration.
	 * Null means absent; false means a failed read (including items or sessions).
	 * The caller must authorize access before invoking this method.
	 * @return array{order:object,items:array,sessions:array,context:CommerceContext}|false|null
	 */
	public function find_detail( int $order_id ): array|false|null {
		if ( $order_id < 1 ) { throw new \InvalidArgumentException( 'A positive order ID is required.' ); }
		$can_suppress = method_exists( $this->wpdb, 'suppress_errors' );
		$previous = $can_suppress ? $this->wpdb->suppress_errors( true ) : false;
		try {
			if(!$this->capture_epoch()){return false;}
			$epoch_predicate=$this->epoch_predicate();
			// Keep existing Orders readable while additive DDL is awaiting retry.
			$installed_schema = (string) get_option( Database::OPTION_SCHEMA_VERSION, '' );
			$customer_columns = version_compare( $installed_schema, OrderCustomerMigration::SCHEMA_VERSION, '>=' )
				? 'customer_name, customer_email'
				: 'NULL AS customer_name, NULL AS customer_email';
			$this->wpdb->last_error = '';
			$rows = $this->wpdb->get_results( $this->wpdb->prepare(
				"SELECT id, status, currency, subtotal_minor, total_minor, created_at, paid_at, {$customer_columns},
				cart_reconciliation_status, cart_reconciliation_code, cart_reconciliation_updated_at
				FROM {$this->tables->orders()} WHERE id = %d AND {$epoch_predicate} LIMIT 1", $order_id
			) );
			if ( $this->wpdb->last_error || ! is_array( $rows ) ) { return false; }
			if ( ! $rows ) { return null; }
			$order = $rows[0];
			$this->wpdb->last_error = '';
			$items = $this->wpdb->get_results( $this->wpdb->prepare(
				"SELECT item_name, quantity, unit_price_minor, line_total_minor, currency
				FROM {$this->tables->order_items()} WHERE order_id = %d ORDER BY id ASC", $order_id
			) );
			if ( $this->wpdb->last_error || ! is_array( $items ) ) { return false; }
			$this->wpdb->last_error = '';
			// Summarize associated event attention without retrieving event bodies or identities.
			$review = $this->payment_review_predicate();
			$sessions = $this->wpdb->get_results( $this->wpdb->prepare(
				"SELECT s.status, s.currency, s.amount_minor, s.created_at, s.updated_at,
				s.expires_at, s.expired_at, s.completed_at, s.cancelled_at, s.failed_at,
				s.payment_confirmed_at, s.reconciliation_required_at,
				CASE WHEN {$review}
				THEN 1 ELSE 0 END AS requires_review
				FROM {$this->tables->payment_sessions()} s WHERE s.epoch_id={$this->epoch} AND s.order_id = %d ORDER BY s.created_at ASC, s.id ASC",
				$order_id
			) );
			if ( $this->wpdb->last_error || ! is_array( $sessions ) ) { return false; }
			if(!$this->epoch_unchanged()){return false;}
			return array( 'order' => $order, 'items' => $items, 'sessions' => $sessions, 'context' => $this->context );
		} finally {
			if ( $can_suppress ) { $this->wpdb->suppress_errors( $previous ); }
		}
	}

	/** Independent committed reads: this view never locks or repairs commerce state.
	 * Invalid filters return errors without querying; false means a read failure.
	 * @return array{orders:array,total:int,page:int,pages:int,filters:array,errors:array}|false
	 */
	public function list_page( mixed $requested_page, array $input = array() ): array|false {
		$filters = self::validate_filters( $input );
		if ( $filters['errors'] ) {
			return array( 'orders' => array(), 'total' => 0, 'page' => 1, 'pages' => 1, 'filters' => $filters['values'], 'errors' => $filters['errors'] );
		}
		// Even installations displaying wpdb errors must not print SQL on this screen.
		$can_suppress = method_exists( $this->wpdb, 'suppress_errors' );
		$previous = $can_suppress ? $this->wpdb->suppress_errors( true ) : false;
		try { return $this->read_page( $requested_page, $filters ); }
		finally { if ( $can_suppress ) { $this->wpdb->suppress_errors( $previous ); } }
	}

	private function read_page( mixed $requested_page, array $filters ): array|false {
		if(!$this->capture_epoch()){return false;}
		$page = filter_var( is_scalar( $requested_page ) ? $requested_page : null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) ?: 1;
		$predicates = array( $this->epoch_predicate() );
		if ( null !== $filters['order_id'] ) { $predicates[] = $this->wpdb->prepare( 'id = %d', $filters['order_id'] ); }
		if ( '' !== $filters['values']['order_status'] ) { $predicates[] = $this->wpdb->prepare( 'status = %s', $filters['values']['order_status'] ); }
		if ( null !== $filters['from'] ) { $predicates[] = $this->wpdb->prepare( 'created_at >= %s', $filters['from'] ); }
		if ( null !== $filters['to'] ) { $predicates[] = $this->wpdb->prepare( 'created_at < %s', $filters['to'] ); }
		$review = $this->payment_review_predicate();
		if ( 'required' === $filters['values']['payment_review'] ) {
			$predicates[] = "EXISTS (SELECT 1 FROM {$this->tables->payment_sessions()} s WHERE s.epoch_id={$this->epoch} AND s.order_id = {$this->tables->orders()}.id AND ({$review}))";
		}
		// One prepared predicate shared verbatim by count and page queries.
		$where = $predicates ? ' WHERE ' . implode( ' AND ', $predicates ) : '';
		$this->wpdb->last_error = '';
		$count = $this->wpdb->get_var( "SELECT COUNT(id) FROM {$this->tables->orders()}{$where}" );
		if ( $this->wpdb->last_error || null === $count || false === $count ) { return false; }
		$total = (int) $count;
		$pages = $total > 0 ? intdiv( $total - 1, self::PER_PAGE ) + 1 : 1;
		$page = min( $page, $pages );
		$result = array( 'orders' => array(), 'total' => $total, 'page' => $page, 'pages' => $pages, 'filters' => $filters['values'], 'errors' => array() );
		if ( 0 === $total ) { return $result; }
		$this->wpdb->last_error = '';
		$orders = $this->wpdb->get_results( $this->wpdb->prepare(
			"SELECT id, status, currency, total_minor, created_at, paid_at, cart_reconciliation_status, cart_reconciliation_code FROM {$this->tables->orders()}{$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
			self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE
		) );
		if ( $this->wpdb->last_error || ! is_array( $orders ) ) { return false; }
		if ( ! $orders ) { return $result; }
		$ids = array_map( static fn( object $row ): int => (int) $row->id, $orders );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$this->wpdb->last_error = '';
		// Existing order_id and payment_session_id indexes bound this aggregation to this page.
		$sessions = $this->wpdb->get_results( $this->wpdb->prepare(
			"SELECT s.order_id, s.status, COUNT(s.id) AS session_count,
			MAX(CASE WHEN {$review} THEN 1 ELSE 0 END) AS requires_review
			FROM {$this->tables->payment_sessions()} s WHERE s.epoch_id={$this->epoch} AND s.order_id IN ($placeholders) GROUP BY s.order_id, s.status ORDER BY s.order_id, s.status",
			...$ids
		) );
		if ( $this->wpdb->last_error || ! is_array( $sessions ) ) { return false; }
		foreach ( $orders as $order ) {
			$order->sessions = array();
			$order->payment_attention = false;
			foreach ( $sessions as $session ) {
				if ( (int) $session->order_id === (int) $order->id ) {
					$order->sessions[ $session->status ] = (int) $session->session_count;
					$order->payment_attention = $order->payment_attention || (bool) $session->requires_review;
				}
			}
		}
		if(!$this->epoch_unchanged()){return false;}
		$result['orders'] = $orders;
		return $result;
	}
}
