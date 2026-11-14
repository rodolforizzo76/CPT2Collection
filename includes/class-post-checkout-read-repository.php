<?php
/** Cookie-owned, nonlocking post-checkout snapshot reads. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;

use wpdb;

defined( 'ABSPATH' ) || exit;

final class PostCheckoutReadRepository {
	public function __construct( private wpdb $wpdb, private TableNames $tables ) {}

	/**
	 * Same expiry rule as CartContext: possession of an unexpired stored cart.
	 * Deliberately does not hydrate/touch a cart, inspect its items or compare
	 * revisions. One statement gives ownership, lifecycle and immutable items
	 * one committed read view without starting a transaction or taking locks.
	 *
	 * @return object[]|false Empty means inaccessible; false means read failure.
	 */
	public function find_owned( string $session_id, string $token_hash, string $now ): array|false {
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare(
			'SELECT o.id AS order_id, o.status, o.currency, o.subtotal_minor, o.total_minor,
			 o.created_at, o.paid_at, o.cart_reconciliation_status, o.cart_reconciliation_code,
			 EXISTS (SELECT 1 FROM ' . $this->tables->payment_sessions() . ' ps
			 WHERE ps.order_id = o.id AND ps.epoch_id=o.epoch_id AND (ps.reconciliation_required_at IS NOT NULL
			 OR ps.reconciliation_code IS NOT NULL OR ps.reconciliation_event_id IS NOT NULL
			 OR EXISTS (SELECT 1 FROM ' . $this->tables->payment_events() . " pe
			 WHERE pe.payment_session_id = ps.id AND pe.epoch_id=ps.epoch_id AND pe.processing_status = 'attention'))) AS payment_attention,
			 i.id AS item_id, i.item_name, i.quantity, i.unit_price_minor, i.line_total_minor,
			 i.currency AS item_currency
			 FROM " . $this->tables->payment_sessions() . ' s
			 INNER JOIN ' . $this->tables->orders() . ' o ON o.id = s.order_id
			 INNER JOIN ' . $this->tables->carts() . ' c ON c.id = o.cart_id
			 LEFT JOIN ' . $this->tables->order_items() . ' i ON i.order_id = o.id
			 WHERE s.provider = %s AND s.provider_session_id = %s
			 AND c.token_hash = %s AND c.expires_at > %s ORDER BY i.id ASC',
			'stripe_checkout', $session_id, $token_hash, $now
		) );
		return '' !== $this->wpdb->last_error || ! is_array( $rows ) ? false : $rows;
	}
}
