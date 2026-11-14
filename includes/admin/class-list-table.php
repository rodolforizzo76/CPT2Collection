<?php
/**
 * Admin list-table integration.
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
use WP_Query;

defined( 'ABSPATH' ) || exit;

final class ListTable {
	private PostTypes $post_types;

	public function __construct( PostTypes $post_types, private StockQuickEdit $stock_quick_edit ) {
		$this->post_types = $post_types;
	}

	public function register(): void {
		add_action( 'current_screen', array( $this, 'register_screen_hooks' ) );
		// admin-ajax does not fire current_screen before rendering the replacement row.
		add_action( 'wp_ajax_inline-save', array( $this, 'register_screen_hooks' ), 0 );
		add_action( 'restrict_manage_posts', array( $this, 'render_status_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'adjust_query' ) );
		add_filter( 'posts_clauses', array( $this, 'sort_missing_meta_safely' ), 10, 2 );
	}

	public function register_screen_hooks(): void {
		$selected = $this->post_types->get_selected_post_type();

		if ( '' === $selected ) {
			return;
		}

		add_filter( "manage_{$selected}_posts_columns", array( $this, 'add_columns' ) );
		add_action( "manage_{$selected}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		add_filter( "manage_edit-{$selected}_sortable_columns", array( $this, 'sortable_columns' ) );
	}

	/**
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function add_columns( array $columns ): array {
		$columns['cpt2collection_price']  = __( 'Price', 'cpt2collection' );
		$columns['cpt2collection_status'] = __( 'Status', 'cpt2collection' );
		$columns['cpt2collection_stock']  = __( 'Stock', 'cpt2collection' );
		$columns['cpt2collection_exempt'] = __( 'Min. exempt', 'cpt2collection' );
		$columns['cpt2collection_rating'] = __( 'Rating', 'cpt2collection' );

		return $columns;
	}

	public function render_column( string $column, int $post_id ): void {
		if ( 'cpt2collection_price' === $column ) {
			$minor = get_post_meta( $post_id, MetaKeys::PRICE_MINOR, true );
			echo is_numeric( $minor ) ? esc_html( Money::format_minor( (int) $minor ) ) : '&mdash;';
			return;
		}

		if ( 'cpt2collection_status' === $column ) {
			$status = (string) get_post_meta( $post_id, MetaKeys::STATUS, true );
			echo esc_html( Statuses::get_label( Statuses::is_valid( $status ) ? $status : Statuses::DEFAULT_STATUS ) );
			return;
		}

		if ( 'cpt2collection_stock' === $column ) {
			$this->stock_quick_edit->render_stock( $post_id );
			return;
		}

		if ( 'cpt2collection_exempt' === $column ) {
			echo Catalog::is_minimum_order_exempt( $post_id ) ? esc_html__( 'Yes', 'cpt2collection' ) : '&mdash;';
			return;
		}

		if ( 'cpt2collection_rating' === $column ) {
			$rating = Ratings::normalize( (string) get_post_meta( $post_id, MetaKeys::RATING, true ) );
			/* translators: %s: the displayed rating. */
			echo '' === $rating ? '&mdash;' : esc_html( sprintf( __( '%s / 5', 'cpt2collection' ), $rating ) );
		}
	}

	/**
	 * @param array<string,string> $columns Sortable columns.
	 * @return array<string,string>
	 */
	public function sortable_columns( array $columns ): array {
		$columns['cpt2collection_price']  = 'cpt2collection_price';
		$columns['cpt2collection_rating'] = 'cpt2collection_rating';

		return $columns;
	}

	public function render_status_filter(): void {
		$selected = $this->post_types->get_selected_post_type();
		$screen   = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( '' === $selected || ! $screen || $screen->post_type !== $selected ) {
			return;
		}

		$current = isset( $_GET['cpt2collection_status'] ) ? sanitize_key( wp_unslash( $_GET['cpt2collection_status'] ) ) : '';
		?>
		<label class="screen-reader-text" for="cpt2collection_status_filter"><?php echo esc_html__( 'Filter by commercial status', 'cpt2collection' ); ?></label>
		<select name="cpt2collection_status" id="cpt2collection_status_filter">
			<option value=""><?php echo esc_html__( 'All commercial statuses', 'cpt2collection' ); ?></option>
			<?php foreach ( Statuses::get_statuses() as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function adjust_query( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$selected = $this->post_types->get_selected_post_type();
		if ( '' === $selected || $query->get( 'post_type' ) !== $selected ) {
			return;
		}

		$meta_query = (array) $query->get( 'meta_query' );
		$status     = isset( $_GET['cpt2collection_status'] ) ? sanitize_key( wp_unslash( $_GET['cpt2collection_status'] ) ) : '';

		if ( Statuses::is_valid( $status ) ) {
			if ( Statuses::DEFAULT_STATUS === $status ) {
				$meta_query[] = array(
					'relation' => 'OR',
					array(
						'key'     => MetaKeys::STATUS,
						'value'   => Statuses::DEFAULT_STATUS,
						'compare' => '=',
					),
					array(
						'key'     => MetaKeys::STATUS,
						'compare' => 'NOT EXISTS',
					),
				);
			} else {
				$meta_query[] = array(
					'key'     => MetaKeys::STATUS,
					'value'   => $status,
					'compare' => '=',
				);
			}
		}

		$orderby = (string) $query->get( 'orderby' );
		if ( in_array( $orderby, array( 'cpt2collection_price', 'cpt2collection_rating' ), true ) ) {
			$query->set( 'cpt2collection_sort_meta_key', 'cpt2collection_price' === $orderby ? MetaKeys::PRICE_MINOR : MetaKeys::RATING );
			$query->set( 'orderby', 'none' );
		}

		if ( ! empty( $meta_query ) ) {
			$query->set( 'meta_query', $meta_query );
		}
	}
	/**
	 * Sorts configured metadata while keeping posts with missing metadata visible.
	 *
	 * @param array<string,string> $clauses SQL clauses.
	 * @param WP_Query            $query   Query object.
	 * @return array<string,string>
	 */
	public function sort_missing_meta_safely( array $clauses, WP_Query $query ): array {
		global $wpdb;

		$meta_key = (string) $query->get( 'cpt2collection_sort_meta_key' );
		$selected = $this->post_types->get_selected_post_type();

		if ( ! is_admin() || ! $query->is_main_query() || '' === $selected || $query->get( 'post_type' ) !== $selected ) {
			return $clauses;
		}

		if ( ! in_array( $meta_key, array( MetaKeys::PRICE_MINOR, MetaKeys::RATING ), true ) ) {
			return $clauses;
		}

		$order = 'desc' === strtolower( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';
		$alias = 'cpt2collection_sort_meta';

		$clauses['join']   .= $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} AS {$alias} ON ({$wpdb->posts}.ID = {$alias}.post_id AND {$alias}.meta_key = %s)", $meta_key );
		$clauses['orderby'] = "CASE WHEN {$alias}.meta_id IS NULL THEN 1 ELSE 0 END ASC, CAST({$alias}.meta_value AS DECIMAL(20,4)) {$order}";

		return $clauses;
	}

}
