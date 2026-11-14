<?php
/** Physical stock in WordPress Quick Edit. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection\Admin;
use RodolfoRizzo\Cpt2Collection\{AdminStockService, StockQuantity, PostTypes};
use WP_Post;
defined( 'ABSPATH' ) || exit;

final class StockQuickEdit {
	public function __construct( private PostTypes $post_types, private AdminStockService $stock ) {}

	public function register(): void {
		add_action( 'quick_edit_custom_box', array( $this, 'render' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'save_post', array( $this, 'save' ), 5, 2 );
	}

	public function enqueue(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base || $screen->post_type !== $this->post_types->get_selected_post_type() ) { return; }
		wp_enqueue_script( 'cpt2collection-stock-quick-edit', CPT2COLLECTION_URL . 'assets/js/stock-quick-edit.js', array( 'jquery', 'inline-edit-post', 'wp-i18n' ), CPT2COLLECTION_VERSION, true );
		wp_set_script_translations( 'cpt2collection-stock-quick-edit', 'cpt2collection', CPT2COLLECTION_PATH . 'languages' );
	}

	public function render( string $column, string $post_type ): void {
		if ( 'cpt2collection_stock' !== $column || $post_type !== $this->post_types->get_selected_post_type() ) { return; }
		?>
		<fieldset class="inline-edit-col-right"><div class="inline-edit-col">
			<label><span class="title"><?php echo esc_html__( 'Stock', 'cpt2collection' ); ?></span>
			<input type="number" name="cpt2collection_quick_stock" min="0" max="<?php echo esc_attr( (string) PHP_INT_MAX ); ?>" step="1" disabled /></label>
			<input type="hidden" name="cpt2collection_quick_expected" value="" />
			<p class="description"><?php echo esc_html__( 'Includes units reserved for checkout. Stock cannot be reduced below active reservations.', 'cpt2collection' ); ?></p>
			<p class="description cpt2collection-quick-stock-help"></p>
		</div></fieldset>
		<?php
	}

	/** Both initial and AJAX replacement rows carry an uncached, strict baseline. */
	public function render_stock( int $post_id ): void {
		$result = $this->stock->inspect( $post_id );
		$value = $result->is_success() ? (string) $result->stock() : '';
		echo '<span class="cpt2collection-stock-value" data-stock="' . esc_attr( $value ) . '">' . esc_html( '' === $value ? __( 'Unavailable — reload or request technical review', 'cpt2collection' ) : $value ) . '</span>';
	}

	public function save( int $post_id, WP_Post $post ): void {
		if ( ! wp_doing_ajax() || ( $_POST['action'] ?? null ) !== 'inline-save' || ! array_key_exists( 'cpt2collection_quick_stock', $_POST ) ) { return; }
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) { return; }
		$nonce = $_POST['_inline_edit'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), 'inlineeditnonce' ) || ! current_user_can( 'edit_post', $post_id ) || $post->post_type !== $this->post_types->get_selected_post_type() || ( $_POST['post_type'] ?? null ) !== $post->post_type || StockQuantity::parse( $_POST['post_ID'] ?? null ) !== $post_id ) {
			$this->reject( 'not_authorized', $post_id, false );
			return;
		}
		$target = StockQuantity::parse( wp_unslash( $_POST['cpt2collection_quick_stock'] ) );
		$expected = StockQuantity::parse( isset( $_POST['cpt2collection_quick_expected'] ) ? wp_unslash( $_POST['cpt2collection_quick_expected'] ) : null );
		if ( null === $target || null === $expected ) { $this->reject( 'invalid_input', $post_id ); return; }
		// An unchanged form is a read, never an exact-set request against newer stock.
		$result = $target === $expected ? $this->stock->inspect( $post_id ) : $this->stock->set_exact( $post_id, $expected, $target );
		if ( ! $result->is_success() ) { $this->reject( $result->code(), $post_id, 'transaction_outcome_unknown' !== $result->code() ); }
	}

	private function reject( string $code, int $post_id, bool $can_read = true ): void {
		// No follow-up persistence access after an uncertain transaction or denied request.
		$current = $can_read ? $this->stock->inspect( $post_id ) : null;
		$value = $current && $current->is_success() ? (string) $current->stock() : '';
		$message = match ( $code ) {
			'stock_conflict' => __( 'Stock was not saved because it changed after this list was loaded. Review the refreshed quantity before editing again.', 'cpt2collection' ),
			'below_reservations' => __( 'Stock was not saved because the quantity is below the units currently reserved for checkout.', 'cpt2collection' ),
			'invalid_input' => __( 'Stock was not saved. Enter a whole number of zero or more within the supported range.', 'cpt2collection' ),
			'stock_missing', 'stock_duplicate', 'stock_invalid' => __( 'Stock data is missing or invalid and needs technical review. Quick Edit cannot repair it.', 'cpt2collection' ),
			'transaction_outcome_unknown' => __( 'The stock save could not be confirmed. Reload the page and verify stock before making another change.', 'cpt2collection' ),
			'not_authorized' => __( 'Stock was not saved. Reload the page and check that you have permission to edit this product.', 'cpt2collection' ),
			default => __( 'Stock could not be saved or checked. Reload the page and review the current stock before trying again.', 'cpt2collection' ),
		};
		// Core recognizes a response without a table row as an error and keeps Quick Edit open.
		wp_die( '<span class="cpt2collection-quick-stock-error" data-stock="' . esc_attr( $value ) . '">' . esc_html( $message . ' ' . __( 'WordPress may have saved the other post fields.', 'cpt2collection' ) ) . '</span>', '', array( 'response' => 200 ) );
	}
}
