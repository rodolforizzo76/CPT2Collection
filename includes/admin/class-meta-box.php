<?php
/**
 * Collection metadata metabox.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection\Admin;

use RodolfoRizzo\Cpt2Collection\Catalog;
use RodolfoRizzo\Cpt2Collection\MetaKeys;
use RodolfoRizzo\Cpt2Collection\Money;
use RodolfoRizzo\Cpt2Collection\PostTypes;
use RodolfoRizzo\Cpt2Collection\Ratings;
use RodolfoRizzo\Cpt2Collection\Statuses;
use RodolfoRizzo\Cpt2Collection\AdminStockService;
use RodolfoRizzo\Cpt2Collection\StockQuantity;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class MetaBox {
	private const NONCE_ACTION = 'cpt2collection_save_meta';
	private const NONCE_NAME   = 'cpt2collection_meta_nonce';

	private PostTypes $post_types;
	private array $stock_errors = array();

	public function __construct( PostTypes $post_types, private AdminStockService $stock_writer ) {
		$this->post_types = $post_types;
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 3 );
		add_filter( 'redirect_post_location', array( $this, 'redirect_location' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'display_stock_error' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor' ) );
	}

	public function enqueue_block_editor(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== $this->post_types->get_selected_post_type() ) { return; }
		wp_enqueue_script( 'cpt2collection-admin-stock', CPT2COLLECTION_URL . 'assets/js/admin-stock.js', array( 'wp-api-fetch', 'wp-data', 'wp-notices', 'wp-i18n' ), CPT2COLLECTION_VERSION, true );
		wp_set_script_translations( 'cpt2collection-admin-stock', 'cpt2collection', CPT2COLLECTION_PATH . 'languages' );
	}

	public function add_meta_box( string $post_type ): void {
		if ( $post_type !== $this->post_types->get_selected_post_type() ) {
			return;
		}

		add_meta_box( 'cpt2collection_details', __( 'CPT2Collection', 'cpt2collection' ), array( $this, 'render' ), $post_type, 'side', 'default' );
	}

	public function render( WP_Post $post ): void {
		$price_minor = get_post_meta( $post->ID, MetaKeys::PRICE_MINOR, true );
		$status      = (string) get_post_meta( $post->ID, MetaKeys::STATUS, true );
		$stock_result = $this->stock_writer->inspect( $post->ID );
		$stock       = $stock_result->is_success() ? (string) $stock_result->stock() : '';
		$is_exempt   = Catalog::is_minimum_order_exempt( $post->ID );
		$rating      = (string) get_post_meta( $post->ID, MetaKeys::RATING, true );
		$status      = Statuses::is_valid( $status ) ? $status : Statuses::DEFAULT_STATUS;
		$price_value = is_numeric( $price_minor ) ? Money::minor_to_input( (int) $price_minor ) : '';

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<p>
			<label for="cpt2collection_price"><?php echo esc_html__( 'Price', 'cpt2collection' ); ?></label>
			<input type="text" class="widefat" id="cpt2collection_price" name="cpt2collection_price" value="<?php echo esc_attr( $price_value ); ?>" inputmode="decimal" placeholder="<?php echo esc_attr__( 'Example: 12.90', 'cpt2collection' ); ?>" />
		</p>
		<p>
			<label for="cpt2collection_status"><?php echo esc_html__( 'Commercial status', 'cpt2collection' ); ?></label>
			<select class="widefat" id="cpt2collection_status" name="cpt2collection_status">
				<?php foreach ( Statuses::get_statuses() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="cpt2collection_stock_quantity"><?php echo esc_html__( 'Stock', 'cpt2collection' ); ?></label>
			<input type="number" class="widefat" id="cpt2collection_stock_quantity" name="cpt2collection_stock_quantity" value="<?php echo esc_attr( $stock ); ?>" min="0" max="<?php echo esc_attr( (string) PHP_INT_MAX ); ?>" step="1" <?php echo $stock_result->is_success() ? '' : 'disabled'; ?> />
			<input type="hidden" name="cpt2collection_stock_expected" value="<?php echo esc_attr( $stock ); ?>" />
			<span class="description"><?php echo esc_html__( 'Units physically remaining, including units reserved for checkout. Use 0 for out of stock. Stock cannot be reduced below active reservations.', 'cpt2collection' ); ?></span>
			<?php if ( ! $stock_result->is_success() ) : ?>
				<span class="description"><?php echo esc_html( $this->stock_message( $stock_result->code() ) ); ?></span>
			<?php endif; ?>
		</p>
		<p>
			<label for="cpt2collection_minimum_order_exempt">
				<input type="checkbox" id="cpt2collection_minimum_order_exempt" name="cpt2collection_minimum_order_exempt" value="1" <?php checked( $is_exempt ); ?> />
				<?php echo esc_html__( 'Can be purchased without reaching the minimum order quantity', 'cpt2collection' ); ?>
			</label>
		</p>
		<p>
			<label for="cpt2collection_rating"><?php echo esc_html__( 'Rating', 'cpt2collection' ); ?></label>
			<select class="widefat" id="cpt2collection_rating" name="cpt2collection_rating">
				<option value=""><?php echo esc_html__( 'No rating', 'cpt2collection' ); ?></option>
				<?php foreach ( Ratings::get_allowed_ratings() as $value => $label ) : ?>
					<option value="<?php /* translators: %s: the displayed rating. */ echo esc_attr( $value ); ?>" <?php selected( $rating, $value ); ?>><?php /* translators: %s: the displayed rating. */ echo esc_html( sprintf( __( '%s out of 5', 'cpt2collection' ), $label ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	public function save( int $post_id, WP_Post $post, bool $update = true ): void {
		// Quick Edit has its own nonce and stock-only adapter, even if extra fields are submitted.
		if ( ( $_POST['action'] ?? null ) === 'inline-save' ) { return; }
		if ( $post->post_type !== $this->post_types->get_selected_post_type() ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// WordPress creates an auto-draft before rendering Add New. This is the server
		// insertion flag, not a browser claim or a missing-meta fallback on later saves.
		if ( ! $update && 'auto-draft' === $post->post_status ) {
			$result = $this->stock_writer->initialize_auto_draft( $post_id, $update );
			if ( ! $result->is_success() ) { $this->stock_errors[$post_id] = $result->code(); }
			return;
		}
		$nonce = $this->request_string( self::NONCE_NAME );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) { return; }

		// Missing input means no stock mutation. An explicit value requires a valid
		// original baseline, even when unchanged. Never sanitize malformed input into a number.
		if ( array_key_exists( 'cpt2collection_stock_quantity', $_POST ) ) {
			$target = StockQuantity::parse( wp_unslash( $_POST['cpt2collection_stock_quantity'] ) );
			$expected = StockQuantity::parse( isset( $_POST['cpt2collection_stock_expected'] ) ? wp_unslash( $_POST['cpt2collection_stock_expected'] ) : null );
			if ( null === $target || null === $expected ) { $this->stock_errors[$post_id] = 'invalid_input'; return; }
			// The original race: unchanged form 10 must not overwrite a sale's current 9.
			$result = $target === $expected ? $this->stock_writer->inspect( $post_id ) : $this->stock_writer->set_exact( $post_id, $expected, $target );
		} else {
			$result = $this->stock_writer->inspect( $post_id );
		}
		if ( ! $result->is_success() ) { $this->stock_errors[$post_id] = $result->code(); return; }
		$stock = $result->stock();
		unset( $this->stock_errors[$post_id] );

		$raw_price   = sanitize_text_field( $this->request_string( 'cpt2collection_price' ) );
		$price_minor = Money::parse_price_minor( $raw_price );
		$status      = sanitize_key( $this->request_string( 'cpt2collection_status' ) );
		$rating      = Ratings::normalize( sanitize_text_field( $this->request_string( 'cpt2collection_rating' ) ) );

		if ( ! Statuses::is_valid( $status ) ) {
			$status = Statuses::DEFAULT_STATUS;
		}

		// Availability is a catalog validation at this point, not a promise that stock
		// cannot subsequently be consumed. Sales deliberately do not rewrite status.
		if ( 'available' === $status ) {
			$has_valid_price = null !== $price_minor && $price_minor > 0;
			$has_stock       = $stock > 0;

			if ( ! $has_valid_price || ! $has_stock ) {
				$status = Statuses::DEFAULT_STATUS;

				if ( ! $has_valid_price && ! $has_stock ) {
					AdminNotices::add_notice( __( 'The item could not be marked as Available because a valid price greater than zero and a stock quantity greater than zero are required.', 'cpt2collection' ), 'warning', $post_id, $post->post_type );
				} elseif ( ! $has_valid_price ) {
					AdminNotices::add_notice( __( 'The item could not be marked as Available because a valid price greater than zero is required.', 'cpt2collection' ), 'warning', $post_id, $post->post_type );
				} else {
					AdminNotices::add_notice( __( 'The item could not be marked as Available because the stock quantity must be greater than zero.', 'cpt2collection' ), 'warning', $post_id, $post->post_type );
				}
			}
		}

		if ( '' === trim( $raw_price ) || null === $price_minor ) {
			delete_post_meta( $post_id, MetaKeys::PRICE_MINOR );
		} else {
			update_post_meta( $post_id, MetaKeys::PRICE_MINOR, $price_minor );
		}

		update_post_meta( $post_id, MetaKeys::STATUS, $status );

		if ( isset( $_POST['cpt2collection_minimum_order_exempt'] ) ) {
			update_post_meta( $post_id, MetaKeys::MINIMUM_ORDER_EXEMPT, '1' );
		} else {
			delete_post_meta( $post_id, MetaKeys::MINIMUM_ORDER_EXEMPT );
		}

		if ( '' === $rating ) {
			delete_post_meta( $post_id, MetaKeys::RATING );
		} else {
			update_post_meta( $post_id, MetaKeys::RATING, $rating );
		}
	}

	private function request_string( string $key ): string {
		return isset( $_POST[$key] ) && is_string( $_POST[$key] ) ? wp_unslash( $_POST[$key] ) : '';
	}

	/** Carry only a safe error category across the editor redirect. No DB writes after an uncertain stock transaction. */
	public function redirect_location( string $location, int $post_id ): string {
		$location = remove_query_arg( 'cpt2collection_stock_error', $location );
		if ( ! isset( $this->stock_errors[$post_id] ) ) { return $location; }
		return add_query_arg( 'cpt2collection_stock_error', $this->stock_errors[$post_id], remove_query_arg( 'message', $location ) );
	}

	public function display_stock_error(): void {
		$screen = get_current_screen();
		global $post;
		if ( ! $screen || 'post' !== $screen->base || ! $post instanceof WP_Post || $post->post_type !== $this->post_types->get_selected_post_type() || ! current_user_can( 'edit_post', $post->ID ) ) { return; }
		$code = $this->stock_errors[$post->ID] ?? ( isset( $_GET['cpt2collection_stock_error'] ) && is_string( $_GET['cpt2collection_stock_error'] ) ? $_GET['cpt2collection_stock_error'] : '' );
		if ( '' === $code ) { return; }
		echo '<div id="cpt2collection-stock-error" class="notice notice-error"><p>' . esc_html( $this->stock_message( $code ) ) . ' ' . esc_html__( 'The other CPT2Collection fields were not saved. WordPress may have saved the title or content.', 'cpt2collection' ) . '</p></div>';
	}

	private function stock_message( string $code ): string {
		return match ( $code ) {
			'stock_conflict' => __( 'Stock was not saved because it changed since this form was opened. Reload the editor and review the current quantity before editing again.', 'cpt2collection' ),
			'below_reservations' => __( 'Stock was not saved because the quantity is below the units currently reserved for checkout.', 'cpt2collection' ),
			'invalid_input' => __( 'Stock was not saved. Enter a whole number of zero or more within the supported range. If the form is old, reload it before trying again.', 'cpt2collection' ),
			'stock_missing', 'stock_duplicate', 'stock_invalid' => __( 'Stock data is missing or invalid and needs technical review. The editor cannot repair it automatically.', 'cpt2collection' ),
			'transaction_outcome_unknown' => __( 'The stock save could not be confirmed. Do not resubmit the old form. Reload and verify the current stock before making another change.', 'cpt2collection' ),
			'stock_busy' => __( 'Stock was not saved because another operation was in progress. Reload and review the current stock before trying again.', 'cpt2collection' ),
			default => __( 'Stock could not be saved or checked. Reload the editor and try again. If the problem continues, contact the site administrator.', 'cpt2collection' ),
		};
	}
}
