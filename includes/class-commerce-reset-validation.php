<?php
/** Read-only, generation-specific modern history checks. Never grants write authority. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class CommerceResetValidation {
	public const STAGES = array( 'carts', 'cart_items', 'orders', 'order_items', 'order_totals', 'reservations', 'payment_sessions', 'payment_events', 'provider_claims', 'provider_identities', 'order_deletions', 'complete' );
	private int $first_epoch = 0;
	public function __construct( private \wpdb $db, private TableNames $tables, private CommerceResetRecord $operation ) {}

	/** Physical PK batches, positive child ranges and fixed unique-point probes only. */
	public function batch( int $size, ?string $position = null, ?int $last = null ): array {
		$owner = TransactionManager::reset_context_on( $this->db );
		if ( null === $owner || ! $owner->operation()->equals( $this->operation ) || 'running' !== $this->operation->status() || ! in_array( $this->operation->phase(), array( 'validating', 'retiring_claims', 'terminalizing_events' ), true ) || $this->operation->validation_generation() !== $this->operation->evidence_generation() || $size < 1 || $size > 1000 ) { throw new \RuntimeException( 'Unowned validation.' ); }
		// Admission has already locked control, source epoch and reset in order.
		$control = $this->parent( 'commerce_control', 1 );
		$baseline = json_decode( $control->baseline_contract ?? '', true );
		self::check( is_array( $baseline ) && ( $baseline['status'] ?? null ) === 'completed' && ( $baseline['installation_id'] ?? null ) === $this->operation->installation_id(), 'baseline' );
		$this->first_epoch = self::integer( $baseline['first_epoch_id'], 1 );
		$this->epoch( $baseline['first_epoch_id'], $baseline['first_epoch_identity'] );
		$checkpoint = $position ?? $this->operation->checkpoint(); $cursor = $last ?? $this->operation->cursor();
		if ( 'v_complete' === $checkpoint ) { self::check( 0 === $cursor, 'position' ); return 'validating' === $this->operation->phase() ? array( 'retiring_claims', null, 0 ) : array( $this->operation->phase(), 'v_complete', 0 ); }
		if ( is_string( $checkpoint ) && preg_match( '/\Av_sum_([0-9]+)_([0-9]+)\z/', $checkpoint, $parts ) ) { return $this->sum_batch( $cursor, self::integer( $parts[1] ), self::integer( $parts[2] ), $size ); }
		if ( is_string( $checkpoint ) && preg_match( '/\Av_dc_([0-9]+)\z/', $checkpoint, $parts ) ) { return $this->deleted_claim_batch( $cursor, self::integer( $parts[1] ), $size ); }
		if ( is_string( $checkpoint ) && preg_match( '/\Av_req_([0-9]+)_([0-9]+)\z/', $checkpoint, $parts ) ) { return $this->request_batch( $cursor, self::integer( $parts[1] ), self::integer( $parts[2] ), $size ); }
		$stage = is_string( $checkpoint ) && str_starts_with( $checkpoint, 'v_' ) ? substr( $checkpoint, 2 ) : '';
		$position = array_search( $stage, self::STAGES, true ); self::check( false !== $position && 'complete' !== $stage, 'position' );
		if ( 'order_totals' === $stage ) {
			$rows = $this->scan( 'orders', $cursor, 1 );
			if ( $rows ) { $row = $rows[0]; return $this->source( $row ) ? array( $this->operation->phase(), 'v_sum_0_0', self::integer( $row->id, 1 ) ) : array( $this->operation->phase(), $checkpoint, self::integer( $row->id, 1 ) ); }
		} else {
			$rows = $this->scan( $stage, $cursor, in_array( $stage, array( 'order_deletions', 'provider_claims' ), true ) ? 1 : $size );
			foreach ( $rows as $row ) {
				$this->$stage( $row ); $cursor = self::integer( $row->id, 1 );
				if ( 'provider_claims' === $stage && $this->source( $row ) && $this->operational_claim( $row ) ) { return array( $this->operation->phase(), 'v_req_0_0', $cursor ); }
				if ( 'order_deletions' === $stage && $this->source( $row ) ) { return array( $this->operation->phase(), 'v_dc_0', $cursor ); }
			}
			if ( $rows ) { return array( $this->operation->phase(), $checkpoint, $cursor ); }
		}
		return array( $this->operation->phase(), 'v_' . self::STAGES[$position+1], 0 );
	}

	private static function check( bool $condition, string $reason ): void { if ( ! $condition ) { throw new \InvalidArgumentException( 'validation_' . $reason ); } }
	private static function integer( mixed $value, int $minimum = 0 ): int {
		$parsed = is_int( $value ) ? $value : ( is_string( $value ) && preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) ? filter_var( $value, FILTER_VALIDATE_INT ) : false );
		self::check( is_int( $parsed ) && $parsed >= $minimum, 'integer' ); return $parsed;
	}
	private static function parse( string $class, object $row, string $reason ): object {
		try { return $class::from_row( $row ); } catch ( \Throwable $error ) { throw new \InvalidArgumentException( 'validation_' . $reason ); }
	}
	private static function integers( object $row, array $fields ): void {
		foreach ( $fields as $field=>$minimum ) { self::integer( $row->$field, $minimum ); }
	}

	private static function dates( object $row, array $fields ): void {
		foreach ( $fields as $field ) { self::check( property_exists( $row, $field ) && ( null === $row->$field || ProviderClaimRecord::valid_date( $row->$field ) ), 'timestamp' ); }
	}
	private function source( object $row ): bool { return isset( $row->epoch_id ) && (string) $row->epoch_id === (string) $this->operation->source_epoch_id(); }
	private function rows( string $sql ): array {
		$this->db->last_error = ''; $rows = $this->db->get_results( $sql );
		if ( '' !== $this->db->last_error || ! is_array( $rows ) ) { throw new \RuntimeException( 'Validation read failed.' ); } return $rows;
	}
	private function scan( string $table, int $cursor, int $size ): array { return $this->rows( $this->db->prepare( 'SELECT * FROM ' . $this->tables->$table() . ' FORCE INDEX(PRIMARY) WHERE id>%d ORDER BY id LIMIT %d', $cursor, $size ) ); }
	/** The caller supplies an inventoried complete unique prefix, never a residual scan. */
	private function point( string $table, string $where ): ?object {
		$rows = $this->rows( 'SELECT * FROM ' . $this->tables->$table() . ' WHERE ' . $where . ' LIMIT 2' ); self::check( count( $rows ) <= 1, 'duplicate_point' ); return $rows[0] ?? null;
	}
	private function id( string $table, mixed $id ): ?object { return $this->point( $table, $this->db->prepare( 'id=%d', self::integer( $id, 1 ) ) ); }
	private function parent( string $table, mixed $id ): object { $row = $this->id( $table, $id ); self::check( null !== $row, 'parent_missing' ); return $row; }
	private function epoch( mixed $id, ?string $identity = null ): object {
		$row = $this->parent( 'commerce_epochs', $id ); self::check( preg_match( '/\A[0-9a-f]{32}\z/', $row->identity ) === 1 && ( null === $identity || $row->identity === $identity ), 'epoch' );
		self::dates( $row, array( 'created_at', 'retired_at' ) ); self::check( null === $row->retired_at || $row->retired_at >= $row->created_at, 'epoch_lifecycle' );
		self::check( self::integer( $row->id, 1 ) >= $this->first_epoch && self::integer( $row->id, 1 ) <= $this->operation->source_epoch_id() && ( (string) $row->id === (string) $this->operation->source_epoch_id() ? null === $row->retired_at : null !== $row->retired_at ), 'epoch_lifecycle' ); return $row;
	}
	private function scope( object $row ): void {
		self::check( $row->installation_id === $this->operation->installation_id() && $row->epoch_identity === $this->operation->source_epoch_identity() && $this->source( $row ), 'scope' ); $this->epoch( $row->epoch_id, $row->epoch_identity );
	}
	private function identity( string $provider, string $kind, string $value ): ?object { return $this->point( 'provider_identities', $this->db->prepare( 'provider=%s AND identity_kind=%s AND identity_value=%s', $provider, $kind, $value ) ); }
	private function deletion( object $claim ): ?object { return $this->point( 'order_deletions', $this->db->prepare( 'installation_id=%s AND epoch_id=%d AND original_order_id=%d', $claim->installation_id, $claim->epoch_id, $claim->order_id ) ); }

	private function carts( object $row ): void {
		$this->epoch( $row->epoch_id ); if ( ! $this->source( $row ) ) { return; }
		self::parse( CartRecord::class, $row, 'cart_shape' ); self::check( preg_match( '/\A[0-9a-f]{64}\z/', $row->token_hash ) === 1, 'cart_token' );
		self::integer( $row->content_revision ); self::integer( $row->checkout_generation ); self::dates( $row, array( 'created_at', 'updated_at', 'last_activity_at', 'expires_at' ) );
	}
	private function cart_items( object $row ): void {
		$cart = $this->parent( 'carts', $row->cart_id ); if ( ! $this->source( $cart ) ) { return; }
		self::parse( CartItem::class, $row, 'cart_item_shape' ); self::integer( $row->id, 1 ); self::integer( $row->post_id, 1 ); self::integer( $row->quantity, 1 ); self::integer( $row->content_version ); self::dates( $row, array( 'created_at', 'updated_at' ) );
	}
	private function cart_snapshot( mixed $cart_id, mixed $revision, mixed $generation ): void {
		$id = self::integer( $cart_id, 1 ); $revision = self::integer( $revision ); $generation = self::integer( $generation ); $cart = $this->id( 'carts', $id );
		if ( null !== $cart ) { self::check( $this->source( $cart ) && self::integer( $cart->content_revision ) >= $revision && ( self::integer( $cart->content_revision ) !== $revision || self::integer( $cart->checkout_generation ) >= $generation ), 'cart_snapshot' ); }
	}
	private function orders( object $row ): void {
		$this->epoch( $row->epoch_id ); if ( ! $this->source( $row ) ) { return; }
		self::integers( $row, array( 'subtotal_minor'=>0, 'total_minor'=>0 ) ); self::parse( OrderRecord::class, $row, 'order_shape' ); $this->cart_snapshot( $row->cart_id, $row->cart_revision, $row->checkout_generation );
		self::check( is_string( $row->checkout_attempt_hash ) && preg_match( '/\A[0-9a-f]{64}\z/', $row->checkout_attempt_hash ) === 1 && in_array( $row->cart_reconciliation_status, array( null, 'pending', 'completed', 'attention' ), true ) && 'source_identity_unavailable' !== $row->cart_reconciliation_code, 'order_snapshot' );
		self::dates( $row, array( 'created_at', 'updated_at', 'paid_at', 'cancelled_at', 'expired_at', 'failed_at', 'cart_reconciliation_updated_at' ) );
		$proof = $this->rows( $this->db->prepare( 'SELECT id FROM ' . $this->tables->order_deletions() . ' FORCE INDEX(original_order_id) WHERE original_order_id=%d ORDER BY id LIMIT 1', $row->id ) ); self::check( array() === $proof, 'deleted_order_reused' );
	}
	private function order_items( object $row ): void {
		$order = $this->parent( 'orders', $row->order_id ); if ( ! $this->source( $order ) ) { return; }
		self::integers( $row, array( 'post_id'=>1, 'quantity'=>1, 'unit_price_minor'=>0, 'line_subtotal_minor'=>0, 'line_total_minor'=>0 ) );
		$item = self::parse( OrderItem::class, $row, 'item_shape' ); self::check( null !== $row->source_cart_item_id && null !== $row->source_cart_item_version, 'item_source_identity' ); self::integer( $row->source_cart_item_id, 1 ); self::integer( $row->source_cart_item_version );
		self::check( $item->unit_price_minor() > 0 && $item->unit_price_minor() <= intdiv( PHP_INT_MAX, $item->quantity() ), 'line_amount' ); $amount = $item->unit_price_minor() * $item->quantity();
		self::check( $amount === $item->line_subtotal_minor() && $amount === $item->line_total_minor() && $item->currency() === $order->currency, 'line_amount' ); self::dates( $row, array( 'created_at' ) );
		foreach ( array( 'order_source'=>'source_cart_item_id', 'order_post'=>'post_id' ) as $index=>$column ) {
			$duplicates = $this->rows( $this->db->prepare( 'SELECT id FROM ' . $this->tables->order_items() . ' FORCE INDEX(' . $index . ') WHERE order_id=%d AND ' . $column . '=%d ORDER BY id LIMIT 2', $row->order_id, $row->$column ) ); self::check( 1 === count( $duplicates ), 'duplicate_' . $column );
		}
		$reservation = $this->point( 'reservations', $this->db->prepare( 'order_item_id=%d', $row->id ) );
		if ( in_array( $order->status, array( 'awaiting_payment', 'paid', 'expired' ), true ) ) { self::check( null !== $reservation && ( 'paid' !== $order->status || 'converted' === $reservation->status ), 'reservation_coverage' ); }
		$source = $this->id( 'cart_items', $row->source_cart_item_id );
		if ( null !== $source ) { self::check( (string) $source->cart_id === (string) $order->cart_id && (string) $source->post_id === (string) $row->post_id && self::integer( $source->content_version ) >= $item->source_cart_item_version() && ( self::integer( $source->content_version ) !== $item->source_cart_item_version() || (string) $source->quantity === (string) $row->quantity ), 'item_source' ); }
	}
	private function sum_batch( int $order_id, int $last, int $sum, int $size ): array {
		$order = $this->parent( 'orders', $order_id ); self::check( $this->source( $order ), 'sum_owner' );
		$items = $this->rows( $this->db->prepare( 'SELECT id,line_total_minor FROM ' . $this->tables->order_items() . ' FORCE INDEX(order_id) WHERE order_id=%d AND id>%d ORDER BY id LIMIT %d', $order_id, $last, $size ) );
		foreach ( $items as $item ) { $amount = self::integer( $item->line_total_minor ); self::check( $amount <= PHP_INT_MAX-$sum, 'total_overflow' ); $sum += $amount; $last = self::integer( $item->id, 1 ); }
		if ( $items ) { return array( $this->operation->phase(), 'v_sum_' . $last . '_' . $sum, $order_id ); }
		self::check( $last > 0 && $sum === self::integer( $order->total_minor ) && $sum === self::integer( $order->subtotal_minor ), 'order_total' ); return array( $this->operation->phase(), 'v_order_totals', $order_id );
	}
	private function reservations( object $row ): void {
		$order = $this->parent( 'orders', $row->order_id ); if ( ! $this->source( $row ) && ! $this->source( $order ) ) { return; }
		self::integers( $row, array( 'post_id'=>1, 'quantity'=>1 ) ); self::parse( ReservationRecord::class, $row, 'reservation_shape' ); $item = $this->parent( 'order_items', $row->order_item_id );
		self::check( $this->source( $row ) && $this->source( $order ) && (string) $item->order_id === (string) $row->order_id && (string) $item->post_id === (string) $row->post_id && (string) $item->quantity === (string) $row->quantity, 'reservation_owner' );
		self::dates( $row, array( 'created_at', 'updated_at', 'expires_at', 'released_at', 'converted_at' ) );
	}
	/** B retirement revokes authority while the original operational corroboration remains. */
	private function operational_claim( object $claim ): bool {
		if ( 'open' === $claim->disposition ) { return true; }
		return 'validating' !== $this->operation->phase() && 'retired' === $claim->disposition && is_string( $claim->tombstoned_at ) && $claim->tombstoned_at >= $this->operation->started_at() && $claim->tombstoned_at <= $this->operation->updated_at();
	}
	private function session_claim( object $session, object $claim ): void {
		self::check( (string) $claim->payment_session_id === (string) $session->id && (string) $claim->order_id === (string) $session->order_id && $claim->reference === $session->reference && $claim->provider === $session->provider && $claim->creation_key_hash === $session->creation_key_hash && $this->operational_claim( $claim ) && $this->source( $claim ), 'session_claim' ); $this->scope( $claim );
		self::parse( ProviderClaimRecord::class, $claim, 'claim_shape' );
		$request = $this->request( $claim );
		self::check( $request->currency() === $session->currency && $request->amount_minor() === self::integer( $session->amount_minor ), 'request_amount' );
	}
	private function request( object $claim ): PaymentProviderSessionRequest {
		try { $request = PaymentProviderSessionRequest::from_snapshot( $claim->request_snapshot ); } catch ( \Throwable $error ) { throw new \InvalidArgumentException( 'validation_request' ); }
		self::check( ProviderClaimRecord::valid_date( $request->reservation_deadline() ), 'request_deadline' );
		$total = 0;
		foreach ( $request->line_items() as $line ) { self::check( $line->unit_amount_minor() <= intdiv( PHP_INT_MAX, $line->quantity() ), 'request_amount' ); $amount = $line->unit_amount_minor()*$line->quantity(); self::check( $amount <= PHP_INT_MAX-$total, 'request_amount' ); $total += $amount; }
		self::check( $total === $request->amount_minor(), 'request_amount' ); return $request;
	}

	private function payment_sessions( object $row ): void {
		$order = $this->parent( 'orders', $row->order_id ); if ( ! $this->source( $row ) && ! $this->source( $order ) ) { return; }
		self::integer( $row->amount_minor ); if ( null !== $row->confirmed_amount_minor ) { self::integer( $row->confirmed_amount_minor ); } self::parse( PaymentSessionRecord::class, $row, 'session_shape' ); self::check( $this->source( $row ) && $this->source( $order ) && $row->currency === $order->currency && (string) $row->amount_minor === (string) $order->total_minor, 'session_order' );
		self::dates( $row, array( 'created_at', 'updated_at', 'expires_at', 'completed_at', 'cancelled_at', 'failed_at', 'payment_confirmed_at', 'expired_at', 'reconciliation_required_at' ) );
		self::check( ( null === $row->confirmed_currency || $row->confirmed_currency === $row->currency ) && ( null === $row->confirmed_amount_minor || (string) $row->confirmed_amount_minor === (string) $row->amount_minor ), 'session_confirmation' );
		if ( 'completed' === $row->status ) { self::check( null !== $row->provider_payment_id && null !== $row->provider_event_id && null !== $row->payment_confirmed_at && $row->completed_at === $row->payment_confirmed_at && null !== $row->confirmed_currency && null !== $row->confirmed_amount_minor, 'session_confirmation' ); }
		$claim = $this->point( 'provider_claims', $this->db->prepare( 'reference=%s', $row->reference ) ); self::check( null !== $claim, 'session_claim_missing' ); $this->session_claim( $row, $claim );
		foreach ( array( 'session'=>'provider_session_id', 'payment'=>'provider_payment_id', 'event'=>'provider_event_id' ) as $kind=>$column ) {
			if ( null === $row->$column ) { continue; } $identity = $this->identity( $row->provider, $kind, $row->$column ); self::check( null !== $identity && (string) $identity->claim_id === (string) $claim->id, 'session_identity' );
		}
		if ( null !== $row->reconciliation_event_id ) { $event = $this->parent( 'payment_events', $row->reconciliation_event_id ); self::check( (string) $event->payment_session_id === (string) $row->id && $event->provider === $row->provider, 'session_event' ); }
	}
	/** Captured request items follow immutable order-item PK order, without materializing siblings. */
	private function request_batch( int $claim_id, int $last, int $offset, int $size ): array {
		$claim = $this->parent( 'provider_claims', $claim_id ); self::check( $this->source( $claim ) && $this->operational_claim( $claim ), 'request_owner' );
		try { $request = PaymentProviderSessionRequest::from_snapshot( $claim->request_snapshot ); } catch ( \Throwable $error ) { throw new \InvalidArgumentException( 'validation_request' ); }
		$lines = $request->line_items();
		$items = $this->rows( $this->db->prepare( 'SELECT * FROM ' . $this->tables->order_items() . ' FORCE INDEX(order_id) WHERE order_id=%d AND id>%d ORDER BY id LIMIT %d', $claim->order_id, $last, $size ) );
		foreach ( $items as $item ) { $line = $lines[$offset] ?? null; self::check( null !== $line && $line->name() === html_entity_decode( $item->item_name, ENT_QUOTES|ENT_HTML5, 'UTF-8' ) && $line->unit_amount_minor() === self::integer( $item->unit_price_minor ) && $line->quantity() === self::integer( $item->quantity, 1 ), 'request_item' ); ++$offset; $last = self::integer( $item->id, 1 ); }
		if ( $items ) { return array( $this->operation->phase(), 'v_req_' . $last . '_' . $offset, $claim_id ); }
		self::check( $offset > 0 && $offset === count( $lines ), 'request_items' ); return array( $this->operation->phase(), 'v_provider_claims', $claim_id );
	}

	private function provider_claims( object $row ): void {
		$order = $this->id( 'orders', $row->order_id ); $session = $this->id( 'payment_sessions', $row->payment_session_id );
		if ( ! $this->source( $row ) && ( null === $order || ! $this->source( $order ) ) && ( null === $session || ! $this->source( $session ) ) ) { return; }
		$this->scope( $row ); self::parse( ProviderClaimRecord::class, $row, 'claim_shape' ); $this->request( $row );
		$duplicates = $this->rows( $this->db->prepare( 'SELECT id FROM ' . $this->tables->provider_claims() . ' FORCE INDEX(payment_session_id) WHERE payment_session_id=%d ORDER BY id LIMIT 2', $row->payment_session_id ) ); self::check( 1 === count( $duplicates ), 'duplicate_session_claim' );
		if ( $this->operational_claim( $row ) ) { self::check( null !== $order && null !== $session && $this->source( $order ) && $this->source( $session ), 'claim_parent' ); $this->session_claim( $session, $row ); }
		else { self::check( 'deleted' === $row->disposition && null === $order && null === $session, 'claim_disposition' ); $deletion = $this->deletion( $row ); self::check( null !== $deletion && $deletion->epoch_identity === $row->epoch_identity && $deletion->deleted_at === $row->tombstoned_at, 'claim_deletion' ); self::parse( OrderDeletionRecord::class, $deletion, 'deletion_shape' ); }
	}
	private function provider_identities( object $row ): void {
		$claim = null === $row->claim_id ? null : $this->id( 'provider_claims', $row->claim_id );
		if ( (string) $row->observed_epoch_id !== (string) $this->operation->source_epoch_id() && ( null === $claim || ! $this->source( $claim ) ) ) { return; }
		self::check( 'native' === $row->evidence_state && in_array( $row->identity_kind, array( 'session', 'payment', 'event' ), true ) && preg_match( '/\A[\x21-\x7e]{1,255}\z/D', $row->identity_value ) === 1 && preg_match( '/\A[a-z0-9_]{1,32}\z/', $row->provider ) === 1, 'identity_shape' );
		self::check( in_array( $row->binding_state, array( 'bound', 'unresolved', 'quarantined' ), true ) && ( 'bound' !== $row->binding_state || null !== $row->claim_id ) && ( 'unresolved' !== $row->binding_state || null === $row->claim_id ) && ( 'quarantined' === $row->binding_state || null === $row->conflict_code ), 'identity_binding' );
		self::check( null === $row->environment || in_array( $row->environment, array( 'test', 'live' ), true ), 'identity_environment' );
		self::check( null === $row->conflict_code || CommerceResetLifecycle::token_valid( $row->conflict_code ), 'identity_conflict' );
		self::check( null === $row->event_type || preg_match( '/\A[a-zA-Z0-9_.]{1,128}\z/', $row->event_type ) === 1, 'identity_type' );
		self::check( null === $row->payload_sha256 || preg_match( '/\A[0-9a-f]{64}\z/', $row->payload_sha256 ) === 1, 'identity_hash' );
		self::check( CommerceResetLifecycle::token_valid( $row->disposition ), 'identity_disposition' ); self::dates( $row, array( 'first_seen_at' ) ); $this->epoch( $row->observed_epoch_id );
		if ( null !== $row->claim_id ) {
			self::check( null !== $claim, 'identity_owner' ); self::parse( ProviderClaimRecord::class, $claim, 'identity_claim' ); $this->request( $claim ); $this->epoch( $claim->epoch_id, $claim->epoch_identity );
			self::check( $claim->installation_id === $this->operation->installation_id() && $claim->provider === $row->provider && self::integer( $claim->epoch_id, 1 ) <= self::integer( $row->observed_epoch_id, 1 ) && ( null === $row->environment || $row->environment === $claim->environment ), 'identity_owner' );
		}
		if ( 'event' === $row->identity_kind ) { $event = $this->point( 'payment_events', $this->db->prepare( 'provider=%s AND provider_event_id=%s', $row->provider, $row->identity_value ) ); if ( null !== $event ) { $this->event_binding( $event, $row, $claim ); } }
	}
	private function event_binding( object $event, object $identity, ?object $claim ): void {
		// A quarantine deliberately preserves original ownership against a conflicting receipt.
		if ( 'quarantined' === $identity->binding_state ) { return; }
		self::check( ( null === $identity->payload_sha256 || $identity->payload_sha256 === $event->payload_sha256 ) && ( null === $identity->event_type || $identity->event_type === $event->event_type ) && ( null === $identity->environment || $identity->environment === ( (int) $event->livemode ? 'live' : 'test' ) ), 'event_identity' );
		if ( null !== $claim ) {
			$payload = json_decode( $event->normalized_payload ?? '', true );
			self::check( $event->local_reference === $claim->reference && $event->provider === $claim->provider && $claim->environment === ( (int) $event->livemode ? 'live' : 'test' ) && is_array( $payload ) && ( $payload['installation_id'] ?? null ) === $claim->installation_id && ( $payload['epoch_identity'] ?? null ) === $claim->epoch_identity, 'event_claim' );
		}
	}
	private function payment_events( object $row ): void {
		$payload = json_decode( $row->normalized_payload ?? '', true ); $claim = null === $row->local_reference ? null : $this->point( 'provider_claims', $this->db->prepare( 'reference=%s', $row->local_reference ) );
		$identities = array(); $associated = $this->source( $row ) || ( is_array( $payload ) && ( $payload['installation_id'] ?? null ) === $this->operation->installation_id() && ( $payload['epoch_identity'] ?? null ) === $this->operation->source_epoch_identity() ) || ( null !== $claim && $this->source( $claim ) );
		foreach ( array( 'event'=>'provider_event_id', 'session'=>'provider_session_id', 'payment'=>'provider_payment_id' ) as $kind=>$column ) {
			if ( null === $row->$column ) { continue; } $identity = $this->identity( $row->provider, $kind, $row->$column ); $identities[$kind] = $identity;
			if ( null !== $identity ) { $owner = null === $identity->claim_id ? null : $this->id( 'provider_claims', $identity->claim_id ); $associated = $associated || ( null !== $owner && $this->source( $owner ) ) || (string) $identity->observed_epoch_id === (string) $this->operation->source_epoch_id(); }
		}
		if ( ! $associated ) { return; }
		self::check( in_array( (string) $row->livemode, array( '0', '1' ), true ), 'event_environment' ); self::integer( $row->attempt_count ); if ( null !== $row->amount_minor ) { self::integer( $row->amount_minor ); } self::parse( PaymentEventRecord::class, $row, 'event_shape' ); self::dates( $row, array( 'provider_created_at', 'received_at', 'last_attempt_at', 'processed_at', 'attention_at' ) );
		self::check( is_array( $payload ) && ( $payload['schema'] ?? null ) === 2 && ( $payload['integration'] ?? null ) === 'cpt2collection' && ( $payload['identity_invalid'] ?? true ) === false && ( $payload['installation_id'] ?? null ) === $this->operation->installation_id() && ( ( $payload['client_reference_id'] ?? null ) === $row->local_reference || ( $payload['metadata_payment_session_reference'] ?? null ) === $row->local_reference ) && null !== $row->local_reference, 'event_payload' );
		$payload_epoch = $this->point( 'commerce_epochs', $this->db->prepare( 'identity=%s', $payload['epoch_identity'] ?? '' ) ); self::check( null !== $payload_epoch, 'event_payload_epoch' ); $this->epoch( $payload_epoch->id, $payload_epoch->identity ); self::check( in_array( $row->ownership_state, array( 'bound', 'unresolved', 'quarantined' ), true ), 'event_ownership' );
		$event_identity = $identities['event'] ?? null;
		if ( 'bound' === $row->ownership_state ) { self::check( null !== $row->epoch_id && null !== $event_identity && null !== $event_identity->claim_id, 'event_owner' ); $owner = $this->parent( 'provider_claims', $event_identity->claim_id ); self::check( (string) $row->epoch_id === (string) $owner->epoch_id, 'event_epoch' ); }
		elseif ( 'unresolved' === $row->ownership_state ) { self::check( null === $row->epoch_id && null === $row->payment_session_id, 'event_unresolved' ); }
		if ( null !== $event_identity ) { $owner = null === $event_identity->claim_id ? null : $this->parent( 'provider_claims', $event_identity->claim_id ); $this->event_binding( $row, $event_identity, $owner ); }
		if ( null !== $row->payment_session_id ) { $session = $this->parent( 'payment_sessions', $row->payment_session_id ); self::check( $session->provider === $row->provider && $session->reference === $row->local_reference && (string) $session->epoch_id === (string) $row->epoch_id && ( null === $row->currency || $row->currency === $session->currency ) && ( null === $row->amount_minor || (string) $row->amount_minor === (string) $session->amount_minor ), 'event_session' ); }
	}
	private function order_deletions( object $row ): void {
		if ( ! $this->source( $row ) ) { return; } $this->scope( $row ); self::parse( OrderDeletionRecord::class, $row, 'deletion_shape' );
		self::check( null === $this->id( 'orders', $row->original_order_id ), 'deleted_order_reused' ); $this->cart_snapshot( $row->cart_id, $row->cart_revision, $row->checkout_generation );
		$owners = $this->rows( $this->db->prepare( 'SELECT * FROM ' . $this->tables->order_deletions() . ' FORCE INDEX(original_order_id) WHERE original_order_id=%d ORDER BY id LIMIT 2', $row->original_order_id ) ); self::check( 1 === count( $owners ), 'deletion_owners' );
		foreach ( array( 'order_items', 'reservations', 'payment_sessions' ) as $table ) { self::check( array() === $this->rows( $this->db->prepare( 'SELECT id FROM ' . $this->tables->$table() . ' FORCE INDEX(order_id) WHERE order_id=%d ORDER BY id LIMIT 1', $row->original_order_id ) ), 'deleted_children' ); }
		foreach ( array( $this->db->prepare( 'cart_id=%d AND checkout_attempt_hash=%s', $row->cart_id, $row->checkout_attempt_hash ), $this->db->prepare( 'cart_id=%d AND cart_revision=%d AND checkout_generation=%d', $row->cart_id, $row->cart_revision, $row->checkout_generation ) ) as $where ) { self::check( null === $this->point( 'orders', $where ), 'deleted_checkout_reused' ); }
	}
	private function deleted_claim_batch( int $deletion_id, int $last, int $size ): array {
		$deletion = $this->parent( 'order_deletions', $deletion_id ); self::check( $this->source( $deletion ), 'deletion_owner' );
		$claims = $this->rows( $this->db->prepare( 'SELECT * FROM ' . $this->tables->provider_claims() . ' FORCE INDEX(order_id) WHERE order_id=%d AND id>%d ORDER BY id LIMIT %d', $deletion->original_order_id, $last, $size ) );
		foreach ( $claims as $claim ) { self::parse( ProviderClaimRecord::class, $claim, 'deleted_claim_shape' ); self::check( $this->source( $claim ) && $claim->installation_id === $deletion->installation_id && $claim->epoch_identity === $deletion->epoch_identity && 'deleted' === $claim->disposition && $claim->tombstoned_at === $deletion->deleted_at, 'deleted_claim_owner' ); $last = self::integer( $claim->id, 1 ); }
		return $claims ? array( $this->operation->phase(), 'v_dc_' . $last, $deletion_id ) : array( $this->operation->phase(), 'v_order_deletions', $deletion_id );
	}
}
