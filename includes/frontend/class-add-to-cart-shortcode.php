<?php
/** Add-to-cart shortcode. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection\Frontend;
use RodolfoRizzo\Cpt2Collection\Catalog;
use RodolfoRizzo\Cpt2Collection\CommerceRules;
defined( 'ABSPATH' ) || exit;

final class AddToCartShortcode {
	private Assets $assets;
	private CommerceRules $rules;
	public function __construct( Assets $assets, CommerceRules $rules ) { $this->assets = $assets; $this->rules = $rules; }
	public function register(): void { add_action( 'init', array( $this, 'add_shortcode' ) ); }
	public function add_shortcode(): void { add_shortcode( 'cpt2collection_add_to_cart', array( $this, 'render' ) ); }
	/** @param array<string,mixed>|string $atts */
	public function render( array|string $atts = array() ): string {
		$atts    = shortcode_atts( array( 'post_id' => 0 ), (array) $atts, 'cpt2collection_add_to_cart' );
		$post_id = absint( $atts['post_id'] );
		if ( 0 === $post_id ) { $post_id = absint( get_the_ID() ); }
		if ( $post_id <= 0 ) { return current_user_can( 'manage_options' ) ? '<p class="cpt2collection-notice">' . esc_html__( 'No collection item is available for this add-to-cart control.', 'cpt2collection' ) . '</p>' : ''; }
		$this->assets->enqueue_cart();
		$result = $this->rules->can_add_item( $post_id, 1 );
		$stock  = Catalog::get_stock_quantity( $post_id );
		$id     = wp_unique_id( 'cpt2collection-quantity-' );
		ob_start(); ?>
		<form class="cpt2collection-add" data-cpt2collection-add data-post-id="<?php echo esc_attr( (string) $post_id ); ?>" aria-busy="true">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html__( 'Quantity', 'cpt2collection' ); ?></label>
			<input id="<?php echo esc_attr( $id ); ?>" class="cpt2collection-quantity" type="number" name="quantity" min="1" <?php echo $stock > 0 ? 'max="' . esc_attr( (string) $stock ) . '"' : ''; ?> value="1" required />
			<button class="cpt2collection-button cpt2collection-button--primary" type="submit" <?php disabled( ! $result->is_allowed() ); ?>><?php echo esc_html__( 'Add to cart', 'cpt2collection' ); ?></button>
			<span class="cpt2collection-add__present" data-present hidden></span>
			<div class="cpt2collection-message<?php echo $result->is_allowed() ? '' : ' cpt2collection-message--error'; ?>" data-message aria-live="polite"><?php echo $result->is_allowed() ? '' : esc_html( $result->get_message() ); ?></div>
		</form>
		<?php return (string) ob_get_clean();
	}
}
