<?php
/** Order inspection and deliberate deletion confirmation within Orders. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection\Admin;

use RodolfoRizzo\Cpt2Collection\{OrderAdminReadRepository, OrderStatuses, PaymentSessionStatuses, Money};

defined( 'ABSPATH' ) || exit;

final class OrderDetailPage {
	public function __construct( private OrderAdminReadRepository $orders ) {}

	/** Canonical positive decimal integers only, bounded by the PHP/SQL binding range. */
	public static function parse_order_id( mixed $selector ): ?int {
		if ( ! is_int( $selector ) && ! is_string( $selector ) ) { return null; }
		$text = (string) $selector;
		if ( strlen( $text ) > strlen( (string) PHP_INT_MAX ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $text ) ) { return null; }
		$value = filter_var( $text, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		return false === $value ? null : $value;
	}

	public function render( mixed $selector, array $list_context = array() ): void {
		// Also guard direct rendering, independently of menu registration and the list router.
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$id = self::parse_order_id( $selector );
		$result = null;
		if ( null !== $id ) {
			try { $result = $this->orders->find_detail( $id ); }
			catch ( \Throwable $error ) { $result = false; }
		}
		/* translators: %s: order reference such as C2C-123. */
		echo '<div class="wrap c2c-order-detail"><h1>' . esc_html( is_array( $result ) ? sprintf( __( 'Order %s', 'cpt2collection' ), 'C2C-' . $id ) : __( 'Order detail', 'cpt2collection' ) ) . '</h1>';
		echo '<p><a href="' . esc_url( add_query_arg( array( 'page' => OrdersPage::MENU_SLUG ) + OrdersPage::list_context( $list_context ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Back to Orders', 'cpt2collection' ) . '</a></p>';
		if ( null !== $id && ! is_array( $result ) ) { $this->deletion( $id, null, $list_context ); }
		if ( null === $id || ! is_array( $result ) ) {
			$message = null === $id ? __( 'Invalid order reference.', 'cpt2collection' ) : ( false === $result ? __( 'Order details are temporarily unavailable. Please try again.', 'cpt2collection' ) : __( 'Order not found.', 'cpt2collection' ) );
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div></div>';
			return;
		}
		$order = $result['order'];
		echo '<p class="c2c-order-intro">' . esc_html__( 'This page shows information recorded by the system, without checking it with the payment provider in real time. Dates and times are in UTC.', 'cpt2collection' ) . '</p>';
		if ( OrderStatuses::PAID === $order->status && 'attention' === $order->cart_reconciliation_status ) {
			echo '<div class="notice notice-warning inline c2c-order-cart-warning"><p>' . esc_html__( 'Payment confirmed. Cart reconciliation needs attention.', 'cpt2collection' ) . '</p></div>';
		}
		echo '<div class="c2c-order-grid">';
		echo '<section class="c2c-order-card" aria-labelledby="c2c-order-summary"><h2 id="c2c-order-summary">' . esc_html__( 'Order summary', 'cpt2collection' ) . '</h2>';
		$this->fields( array(
			array( __( 'Order reference', 'cpt2collection' ), 'C2C-' . $id ),
			array( __( 'Order status', 'cpt2collection' ), OrderStatuses::get_statuses()[ $order->status ] ?? __( 'Unknown', 'cpt2collection' ) ),
			array( __( 'Created (UTC)', 'cpt2collection' ), (string) $order->created_at ),
			array( __( 'Paid (UTC)', 'cpt2collection' ), $order->paid_at ?? '—' ),
			array( __( 'Order currency', 'cpt2collection' ), Money::supports_display_currency( $order->currency ) ? $order->currency : __( 'Unknown', 'cpt2collection' ) ),
			array( __( 'Subtotal', 'cpt2collection' ), $this->money( $order->subtotal_minor, $order->currency ) ),
			array( __( 'Total', 'cpt2collection' ), $this->money( $order->total_minor, $order->currency ) ),
		), 'c2c-order-summary-fields' );
		echo '</section><section class="c2c-order-card" aria-labelledby="c2c-order-customer"><h2 id="c2c-order-customer">' . esc_html__( 'Customer', 'cpt2collection' ) . '</h2>';
		$this->fields( array(
			array( __( 'Customer name', 'cpt2collection' ), $order->customer_name ?? __( 'Not available', 'cpt2collection' ) ),
			array( __( 'Customer email', 'cpt2collection' ), $order->customer_email ?? __( 'Not available', 'cpt2collection' ) ),
		) );
		echo '</section></div><section class="c2c-order-card" aria-labelledby="c2c-order-items"><h2 id="c2c-order-items">' . esc_html__( 'Order items', 'cpt2collection' ) . '</h2><p>' . esc_html__( 'Items and prices recorded for this purchase may differ from the current catalog.', 'cpt2collection' ) . '</p>';
		if ( ! $result['items'] ) {
			echo '<p>' . esc_html__( 'No saved item details are available for this order.', 'cpt2collection' ) . '</p>';
		} else {
			echo '<table class="widefat striped c2c-order-items" aria-labelledby="c2c-order-items"><thead><tr>';
			foreach ( array( __( 'Item', 'cpt2collection' ), __( 'Qty', 'cpt2collection' ), __( 'Unit price', 'cpt2collection' ), __( 'Total', 'cpt2collection' ) ) as $label ) { echo '<th scope="col">' . esc_html( $label ) . '</th>'; }
			echo '</tr></thead><tbody>';
			foreach ( $result['items'] as $item ) {
				echo '<tr>';
				foreach ( array( $item->item_name, $item->quantity, $this->money( $item->unit_price_minor, $item->currency ), $this->money( $item->line_total_minor, $item->currency ) ) as $value ) { echo '<td>' . esc_html( (string) $value ) . '</td>'; }
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</section>';
		echo '<details class="c2c-order-card c2c-order-technical"><summary><h2>' . esc_html__( 'Technical Payment Details', 'cpt2collection' ) . '</h2></summary>';
		echo '<p>' . esc_html__( 'During processing, different sections may reflect updates saved at different times.', 'cpt2collection' ) . '</p>';
		$this->payment( $order, $result['sessions'] );
		echo '<section aria-labelledby="c2c-order-cart"><h3 id="c2c-order-cart">' . esc_html__( 'Cart Reconciliation', 'cpt2collection' ) . '</h3><p>' . esc_html( $this->cart_explanation( $order ) ) . '</p>';
		echo '<p>' . esc_html__( 'Cart updating is separate from payment confirmation. Completion does not mean the entire cart is empty.', 'cpt2collection' ) . '</p>';
		$this->fields( array( array( __( 'Cart update status recorded (UTC)', 'cpt2collection' ), $order->cart_reconciliation_updated_at ?? '—' ) ) );
		echo '</section></details>';
		$this->deletion( $id, $result, $list_context );
		echo '</div>';
	}

	/** Confirmation data is an expected identity, never an eligibility grant. */
	private function deletion( int $id, ?array $result, array $list_context ): void {
		$context = OrdersPage::list_context( $list_context );
		$inspect = add_query_arg( array( 'page' => OrdersPage::MENU_SLUG, 'order_id' => $id, 'deletion_inspection' => '1' ) + $context, admin_url( 'admin.php' ) );
		$read_only = ( $list_context['deletion_inspection'] ?? null ) === '1';
		$config = array(
			'order_id' => (string) $id,
			'key' => 'c2c-order-deletion-' . hash( 'sha256', admin_url( 'admin.php' ) . ':' . get_current_user_id() . ':' . $id ),
			'read_only' => $read_only,
		);
		if ( null !== $result && ! $read_only ) {
			$c = $result['context'];
			$config += array( 'url' => rest_url( 'cpt2collection/v1/orders/' . $id ), 'nonce' => wp_create_nonce( 'wp_rest' ),
				'expected_context' => array( 'installation_id' => $c->installation_id(), 'epoch_id' => (string) $c->epoch_id(), 'epoch_identity' => $c->epoch_identity() ) );
		}
		echo '<section id="c2c-order-deletion" class="c2c-order-card c2c-order-danger" aria-labelledby="c2c-delete-title" data-config="' . esc_attr( wp_json_encode( $config ) ) . '">';
		echo '<h2 id="c2c-delete-title">' . esc_html__( 'Permanent Deletion', 'cpt2collection' ) . '</h2>';
		echo '<div class="notice inline" id="c2c-delete-result" role="status" aria-live="polite" tabindex="-1" hidden><p></p>';
		if ( ! $read_only ) {
			echo '<p><a id="c2c-delete-inspect" href="' . esc_url( $inspect ) . '">' . esc_html__( 'Inspect Order (read-only)', 'cpt2collection' ) . '</a></p>';
		}
		echo '</div>';
		if ( null !== $result && ! $read_only ) {
			echo '<p>' . esc_html__( 'Deleting this order is permanent and cannot be undone. It does not issue refunds, change stock, or cancel any Stripe payments or sessions.', 'cpt2collection' ) . '</p>';
			echo '<button type="button" class="button" id="c2c-delete-reveal" aria-expanded="false" aria-controls="c2c-delete-form" hidden>' . esc_html__( 'Review Permanent Deletion', 'cpt2collection' ) . '</button>';
			echo '<form id="c2c-delete-form" hidden>';
			$order = $result['order'];
			echo '<p>' . esc_html( 'C2C-' . $id . ' — ' . $order->created_at . ' UTC — ' . $this->money( $order->total_minor, $order->currency ) . ' — ' . ( OrderStatuses::get_statuses()[ $order->status ] ?? __( 'Unknown', 'cpt2collection' ) ) ) . '</p>';
			echo '<p id="c2c-delete-data-warning">' . esc_html__( 'Deletion is permanent. There is no Trash or Restore. Operational Order data and associated records are removed when the service permits deletion. Physical stock is not restored or modified, and cart data is retained.', 'cpt2collection' ) . '</p>';
			echo '<p id="c2c-delete-payment-warning">' . esc_html__( 'This does not refund payments or cancel external payment sessions. A provider payment may still complete after local deletion. Provider and deletion evidence is retained for integrity and historical safety. Displayed status does not establish deletion eligibility.', 'cpt2collection' ) . '</p>';
			echo '<p id="c2c-delete-help">' . esc_html__( 'Type this exact phrase, including case and spacing:', 'cpt2collection' ) . ' <strong>' . esc_html( 'DELETE ORDER C2C-' . $id ) . '</strong></p>';
			echo '<p><label for="c2c-delete-confirmation">' . esc_html__( 'Deletion confirmation', 'cpt2collection' ) . '</label><br><input type="text" class="regular-text" id="c2c-delete-confirmation" autocomplete="off" spellcheck="false" aria-describedby="c2c-delete-help c2c-delete-data-warning c2c-delete-payment-warning"></p>';
			echo '<button type="submit" class="button button-primary" id="c2c-delete-submit" disabled>' . esc_html__( 'Permanently delete Order', 'cpt2collection' ) . '</button> <button type="button" class="button" id="c2c-delete-cancel">' . esc_html__( 'Cancel', 'cpt2collection' ) . '</button></form>';
			echo '<noscript><p>' . esc_html__( 'JavaScript is required for the confirmed deletion workflow.', 'cpt2collection' ) . '</p></noscript>';
		} else {
			echo '<p>' . esc_html__( 'Read-only inspection. Order absence does not prove that a deletion committed. Without positive deletion evidence, an earlier uncertain outcome remains unverified.', 'cpt2collection' ) . '</p>';
		}
		echo '</section>';
	}

	/** All values pass through text escaping, including immutable snapshot text. */
	private function fields( array $fields, string $class = '' ): void {
		echo '<table class="' . esc_attr( trim( 'widefat c2c-order-fields ' . $class ) ) . '"><tbody>';
		foreach ( $fields as [ $label, $value ] ) { echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>'; }
		echo '</tbody></table>';
	}

	private function money( mixed $amount, string $currency ): string {
		$value = filter_var( $amount, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		if ( false === $value || ! Money::supports_display_currency( $currency ) ) { return __( 'Unavailable', 'cpt2collection' ); }
		return Money::format_paid_total( $value, $currency );
	}

	private function payment( object $order, array $sessions ): void {
		$attention = false;
		foreach ( $sessions as $session ) { $attention = $attention || (bool) $session->requires_review; }
		$confirmation = match ( $order->status ) {
			OrderStatuses::PAID => __( 'Payment confirmed in order records', 'cpt2collection' ),
			OrderStatuses::PENDING, OrderStatuses::AWAITING_PAYMENT => $attention ? __( 'Needs review — no payment confirmation is recorded for this order.', 'cpt2collection' ) : __( 'Awaiting confirmation', 'cpt2collection' ),
			OrderStatuses::CANCELLED, OrderStatuses::EXPIRED, OrderStatuses::FAILED => __( 'No payment confirmation recorded', 'cpt2collection' ),
			default => __( 'Payment confirmation cannot be determined from the order records.', 'cpt2collection' ),
		};
		echo '<section aria-labelledby="c2c-order-payment"><h3 id="c2c-order-payment">' . esc_html__( 'Payment information', 'cpt2collection' ) . '</h3>';
		$this->fields( array(
			array( __( 'Order payment confirmation', 'cpt2collection' ), $confirmation ),
			array( __( 'Payment processing review', 'cpt2collection' ), $attention ? __( 'Requires review', 'cpt2collection' ) : __( 'No review requirement recorded', 'cpt2collection' ) ),
		), 'c2c-order-payment-fields' );
		echo '<p>' . esc_html__( 'Payment confirmation comes from the order record. A completed Checkout Session alone does not confirm payment. Attempts do not establish a decline, refund or absence of a charge. A session recorded as active may no longer be usable.', 'cpt2collection' ) . '</p>';
		echo '</section><section aria-labelledby="c2c-order-attempts"><h3 id="c2c-order-attempts">' . esc_html__( 'Payment attempts', 'cpt2collection' ) . '</h3>';
		if ( ! $sessions ) { echo '<p>' . esc_html__( 'No payment sessions recorded.', 'cpt2collection' ) . '</p>'; }
		foreach ( $sessions as $index => $session ) {
			$heading_id = 'c2c-order-attempt-' . ( $index + 1 );
			/* translators: %d: sequential display number of the saved payment attempt. */
			echo '<section class="c2c-order-attempt" aria-labelledby="' . esc_attr( $heading_id ) . '"><h4 id="' . esc_attr( $heading_id ) . '">' . esc_html( sprintf( __( 'Payment attempt %d', 'cpt2collection' ), $index + 1 ) ) . '</h4>';
			$fields = array(
				array( __( 'Recorded session status', 'cpt2collection' ), PaymentSessionStatuses::get_statuses()[ $session->status ] ?? __( 'Unknown', 'cpt2collection' ) ),
				array( __( 'Attempt amount', 'cpt2collection' ), $this->money( $session->amount_minor, $session->currency ) ),
				array( __( 'Created (UTC)', 'cpt2collection' ), $session->created_at ),
				array( __( 'Updated (UTC)', 'cpt2collection' ), $session->updated_at ),
				array( __( 'Recorded expiry (UTC)', 'cpt2collection' ), $session->expires_at ?? '—' ),
				array( __( 'Payment processing review', 'cpt2collection' ), $session->requires_review ? __( 'Requires review', 'cpt2collection' ) : __( 'No review requirement recorded', 'cpt2collection' ) ),
			);
			foreach ( array(
				'completed_at' => __( 'Locally completed (UTC)', 'cpt2collection' ),
				'expired_at' => __( 'Locally expired (UTC)', 'cpt2collection' ),
				'cancelled_at' => __( 'Locally cancelled (UTC)', 'cpt2collection' ),
				'failed_at' => __( 'Locally failed (UTC)', 'cpt2collection' ),
				'payment_confirmed_at' => __( 'Payment evidence recorded (UTC)', 'cpt2collection' ),
				'reconciliation_required_at' => __( 'Payment review flagged (UTC)', 'cpt2collection' ),
			) as $field => $label ) {
				if ( null !== $session->$field ) { $fields[] = array( $label, $session->$field ); }
			}
			$this->fields( $fields );
			echo '</section>';
		}
		echo '</section>';
	}

	private function cart_explanation( object $order ): string {
		if ( 'attention' === $order->cart_reconciliation_status ) { return __( 'The cart update needs review. Payment confirmation is unchanged.', 'cpt2collection' ); }
		if ( 'completed' === $order->cart_reconciliation_status ) {
			return match ( $order->cart_reconciliation_code ) {
				'cart_reconciled' => __( 'The cart was updated to account for this purchase without discarding later changes.', 'cpt2collection' ),
				default => __( 'The cart update is marked complete, but its outcome is unclear and needs review.', 'cpt2collection' ),
			};
		}
		if ( null === $order->cart_reconciliation_status || 'pending' === $order->cart_reconciliation_status ) {
			return __( 'The cart update is not yet confirmed. It may not have started, or processing may still be unresolved. No retry schedule is shown here.', 'cpt2collection' );
		}
		return __( 'The recorded cart update status is unclear and needs review.', 'cpt2collection' );
	}
}
