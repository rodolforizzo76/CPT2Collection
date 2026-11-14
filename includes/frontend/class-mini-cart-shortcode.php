<?php
/** Accessible mini-cart shortcode. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection\Frontend;
use RodolfoRizzo\Cpt2Collection\CartPage;
defined( 'ABSPATH' ) || exit;

final class MiniCartShortcode {
	private Assets $assets;
	public function __construct( Assets $assets ) { $this->assets = $assets; }
	public function register(): void { add_action( 'init', array( $this, 'add_shortcode' ) ); }
	public function add_shortcode(): void { add_shortcode( 'cpt2collection_mini_cart', array( $this, 'render' ) ); }
	public function render(): string {
		$this->assets->enqueue_cart();
		$id = wp_unique_id( 'cpt2collection-mini-cart-' );
		ob_start(); ?>
		<div class="cpt2collection-mini" data-cpt2collection-mini aria-busy="true">
			<button class="cpt2collection-mini__trigger" type="button" aria-expanded="false" aria-controls="<?php echo esc_attr( $id ); ?>">
				<span aria-hidden="true">&#128722;</span> <span class="screen-reader-text"><?php echo esc_html__( 'Open cart', 'cpt2collection' ); ?></span>
				<span class="cpt2collection-cart-count" data-mini-count>0</span>
			</button>
			<div id="<?php echo esc_attr( $id ); ?>" class="cpt2collection-mini__panel" data-panel tabindex="-1" hidden>
				<div data-items><p><?php echo esc_html__( 'Loading cart…', 'cpt2collection' ); ?></p></div>
				<div class="cpt2collection-mini__summary" data-summary></div>
				<?php if ( '' !== CartPage::get_url() ) : ?><a class="cpt2collection-button cpt2collection-button--primary cpt2collection-mini__view-cart" href="<?php echo esc_url( CartPage::get_url() ); ?>"><?php echo esc_html__( 'View cart', 'cpt2collection' ); ?></a><?php endif; ?>
				<div class="cpt2collection-message" data-message aria-live="polite"></div>
			</div>
		</div>
		<?php return (string) ob_get_clean();
	}
}
