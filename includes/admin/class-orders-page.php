<?php
/** Read-only Orders landing page. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection\Admin;
use RodolfoRizzo\Cpt2Collection\{OrderAdminReadRepository, OrderStatuses, PaymentSessionStatuses, Money};
defined( 'ABSPATH' ) || exit;

final class OrdersPage {
	public const MENU_SLUG = 'cpt2collection-orders';
	public function __construct( private OrderAdminReadRepository $orders ) {}
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 9 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}
	public function enqueue(): void {
		if ( ! current_user_can( 'manage_options' ) || ( $_GET['page'] ?? null ) !== self::MENU_SLUG || null === OrderDetailPage::parse_order_id( $_GET['order_id'] ?? null ) ) { return; }
		wp_enqueue_style( 'cpt2collection-order-detail', CPT2COLLECTION_URL . 'assets/css/order-detail.css', array(), CPT2COLLECTION_VERSION );
		wp_enqueue_script( 'cpt2collection-order-deletion', CPT2COLLECTION_URL . 'assets/js/order-deletion.js', array( 'wp-i18n' ), CPT2COLLECTION_VERSION, true );
		wp_set_script_translations( 'cpt2collection-order-deletion', 'cpt2collection', CPT2COLLECTION_PATH . 'languages' );
	}
	public function add_page(): void {
		add_menu_page( __( 'CPT2Collection Orders', 'cpt2collection' ), __( 'CPT2Collection', 'cpt2collection' ), 'manage_options', self::MENU_SLUG, array( $this, 'render' ), 'dashicons-list-view' );
		// Matching parent/child slugs replace WordPress's automatic parent mirror.
		add_submenu_page( self::MENU_SLUG, __( 'CPT2Collection Orders', 'cpt2collection' ), __( 'Orders', 'cpt2collection' ), 'manage_options', self::MENU_SLUG, array( $this, 'render' ) );
	}
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$input = wp_unslash( $_GET );
		if ( array_key_exists( 'order_id', $_GET ) ) {
			( new OrderDetailPage( $this->orders ) )->render( $_GET['order_id'], $input );
			return;
		}
		try { $result = $this->orders->list_page( $_GET['paged'] ?? 1, $input ); }
		catch ( \Throwable $error ) { $result = false; }
		echo '<div class="wrap"><h1>' . esc_html__( 'Orders', 'cpt2collection' ) . '</h1>';
		$this->render_filters( false === $result ? OrderAdminReadRepository::validate_filters( $input )['values'] : $result['filters'] );
		if ( false === $result ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Orders are temporarily unavailable. Please try again.', 'cpt2collection' ) . '</p></div></div>';
			return;
		}
		if ( $result['errors'] ) {
			echo '<div class="notice notice-error"><p>' . esc_html( implode( ' ', array_unique( $result['errors'] ) ) ) . '</p></div></div>';
			return;
		}
		$context = self::list_context( $result['filters'] + array( 'paged' => $result['page'] ) );
		echo '<p>' . esc_html__( 'Saved order information. An Active payment session may no longer be usable. A completed cart update does not mean the cart is empty.', 'cpt2collection' ) . '</p>';
		if ( ! $result['orders'] ) {
			echo '<p>' . esc_html__( 'No orders found.', 'cpt2collection' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array( __( 'Order reference', 'cpt2collection' ), __( 'Order status', 'cpt2collection' ), __( 'Created (UTC)', 'cpt2collection' ), __( 'Paid (UTC)', 'cpt2collection' ), __( 'Total', 'cpt2collection' ), __( 'Payment', 'cpt2collection' ), __( 'Cart update', 'cpt2collection' ) ) as $label ) { echo '<th scope="col">' . esc_html( $label ) . '</th>'; }
			echo '</tr></thead><tbody>';
			foreach ( $result['orders'] as $order ) {
				$currency = (string) $order->currency;
				$total = isset( Money::get_currencies()[ $currency ] ) ? Money::format_minor( (int) $order->total_minor, $currency ) . ' ' . $currency : __( 'Unavailable', 'cpt2collection' );
				$values = array( 'C2C-' . (int) $order->id, OrderStatuses::get_statuses()[ $order->status ] ?? __( 'Unknown', 'cpt2collection' ), $order->created_at, $order->paid_at ?: '—', $total, $this->payment_label( $order ), $this->cart_label( $order ) );
				echo '<tr>';
				foreach ( $values as $column => $value ) {
					if ( 0 === $column ) {
						echo '<td><a href="' . esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'order_id' => (int) $order->id ) + $context, admin_url( 'admin.php' ) ) ) . '">' . esc_html( (string) $value ) . '</a></td>';
					} else { echo '<td>' . esc_html( (string) $value ) . '</td>'; }
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		/* translators: 1: current page, 2: total pages, 3: total orders. */
		$pagination = _n( 'Page %1$d of %2$d — %3$d order', 'Page %1$d of %2$d — %3$d orders', $result['total'], 'cpt2collection' );
		echo '<div class="tablenav"><div class="tablenav-pages">' . esc_html( sprintf( $pagination, $result['page'], $result['pages'], $result['total'] ) ) . ' ';
		foreach ( array( -1 => __( 'Previous', 'cpt2collection' ), 1 => __( 'Next', 'cpt2collection' ) ) as $step => $label ) {
			$target = $result['page'] + $step;
			if ( $target >= 1 && $target <= $result['pages'] ) {
				echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'paged' => $target ) + $context, admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a> ';
			}
		}
		echo '</div></div></div>';
	}

	/** Only validated filter values and a bounded page may travel through detail links. */
	public static function list_context( array $input ): array {
		$filters = OrderAdminReadRepository::validate_filters( $input );
		if ( $filters['errors'] ) { return array(); }
		$context = array_filter( $filters['values'], static fn( string $value ): bool => '' !== $value );
		$page = filter_var( is_scalar( $input['paged'] ?? null ) ? $input['paged'] : null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		if ( false !== $page && $page > 1 ) { $context['paged'] = $page; }
		return $context;
	}

	private function render_filters( array $values ): void {
		// A GET form with a clean action and no paged field starts every submission on page one.
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '">';
		echo '<div style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:8px;margin:12px 0">';
		echo '<div><label for="c2c-order-reference">' . esc_html__( 'Order reference', 'cpt2collection' ) . '</label><br>';
		echo '<input type="text" id="c2c-order-reference" name="order_reference" maxlength="64" placeholder="C2C-123" value="' . esc_attr( $values['order_reference'] ) . '"></div>';
		$this->filter_select( 'order_status', __( 'Order status', 'cpt2collection' ), array( '' => __( 'All order statuses', 'cpt2collection' ) ) + OrderStatuses::get_statuses(), $values['order_status'] );
		foreach ( array( 'created_from' => __( 'Created from', 'cpt2collection' ), 'created_to' => __( 'Created to', 'cpt2collection' ) ) as $key => $label ) {
			echo '<div><label for="c2c-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><br>';
			echo '<input type="date" id="c2c-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" min="1000-01-01" max="' . ( 'created_to' === $key ? '9999-12-30' : '9999-12-31' ) . '" aria-describedby="c2c-date-help" value="' . esc_attr( $values[$key] ) . '"></div>';
		}
		$this->filter_select( 'payment_review', __( 'Payment review', 'cpt2collection' ), array( '' => __( 'All orders', 'cpt2collection' ), 'required' => __( 'Requires review', 'cpt2collection' ) ), $values['payment_review'] );
		echo '<div><button type="submit" class="button">' . esc_html__( 'Filter', 'cpt2collection' ) . '</button> ';
		echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => self::MENU_SLUG ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Clear filters', 'cpt2collection' ) . '</a></div></div>';
		echo '<p class="description" id="c2c-date-help">' . esc_html__( 'Dates use UTC and include the whole final day. Paid orders can also require payment review.', 'cpt2collection' ) . '</p></form>';
	}

	private function filter_select( string $name, string $label, array $options, string $current ): void {
		echo '<div><label for="c2c-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label><br><select id="c2c-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $value => $text ) {
			echo '<option value="' . esc_attr( $value ) . '"' . ( $current === $value ? ' selected' : '' ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></div>';
	}
	private function payment_label( object $order ): string {
		if ( OrderStatuses::PAID === $order->status ) { $label = __( 'Payment confirmed in order records', 'cpt2collection' ); }
		elseif ( $order->payment_attention ) { $label = __( 'Requires review', 'cpt2collection' ); }
		elseif ( in_array( $order->status, array( OrderStatuses::PENDING, OrderStatuses::AWAITING_PAYMENT ), true ) ) { $label = __( 'Awaiting confirmation', 'cpt2collection' ); }
		else { $label = __( 'No payment confirmation recorded', 'cpt2collection' ); }
		if ( OrderStatuses::PAID === $order->status && $order->payment_attention ) { $label .= ' — ' . __( 'Payment review required', 'cpt2collection' ); }
		$attempts = array();
		foreach ( $order->sessions as $status => $count ) {
			$status_label = PaymentSessionStatuses::get_statuses()[ $status ] ?? __( 'Unknown', 'cpt2collection' );
			/* translators: 1: payment session status, 2: number of sessions with that status. */
			$attempts[] = sprintf( __( '%1$s × %2$d', 'cpt2collection' ), $status_label, $count );
		}
		/* translators: %s: list of payment session statuses with counts. */
		$attempt_summary = $attempts ? sprintf( __( 'Payment attempts: %s', 'cpt2collection' ), implode( ', ', $attempts ) ) : __( 'No payment session recorded', 'cpt2collection' );
		/* translators: 1: payment confirmation state, 2: payment attempt summary. */
		return sprintf( __( '%1$s; %2$s', 'cpt2collection' ), $label, $attempt_summary );
	}
	private function cart_label( object $order ): string {
		$status = $order->cart_reconciliation_status;
		if ( 'attention' === $status ) { return __( 'Needs attention', 'cpt2collection' ); }
		if ( 'completed' === $status ) {
			return match ( $order->cart_reconciliation_code ) {
				'cart_reconciled' => __( 'Completed', 'cpt2collection' ),
				default => __( 'Unknown result — review required', 'cpt2collection' ),
			};
		}
		if ( null === $status || 'pending' === $status ) { return OrderStatuses::PAID === $order->status ? __( 'Cart update not yet confirmed', 'cpt2collection' ) : __( 'No cart update result recorded', 'cpt2collection' ); }
		return __( 'Unknown — review required', 'cpt2collection' );
	}
}
