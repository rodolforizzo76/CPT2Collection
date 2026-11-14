<?php
/**
 * Frontend assets.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection\Frontend;

use RodolfoRizzo\Cpt2Collection\CartPage;
use RodolfoRizzo\Cpt2Collection\GuestCsrf;
use RodolfoRizzo\Cpt2Collection\Money;
use RodolfoRizzo\Cpt2Collection\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and conditionally enqueues frontend CSS.
 */
final class Assets {
	public const RATING_STYLE_HANDLE = 'cpt2collection-rating';
	public const CART_STYLE_HANDLE = 'cpt2collection-cart-frontend';
	public const CART_SCRIPT_HANDLE = 'cpt2collection-cart-frontend';
	private bool $cart_configured = false;
	private bool $post_checkout_configured = false;
	public const POST_CHECKOUT_HANDLE = 'cpt2collection-post-checkout';

	private PostTypes $post_types;

	public function __construct( PostTypes $post_types ) {
		$this->post_types = $post_types;
	}

	/**
	 * Registers hooks.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_rating_style' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_post_checkout_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_cart_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_when_configured' ) );
	}

	public function register_post_checkout_assets(): void {
		wp_register_style( self::POST_CHECKOUT_HANDLE, CPT2COLLECTION_URL . 'assets/css/post-checkout.css', array(), CPT2COLLECTION_VERSION );
		wp_register_script( self::POST_CHECKOUT_HANDLE, CPT2COLLECTION_URL . 'assets/js/post-checkout.js', array( 'wp-i18n' ), CPT2COLLECTION_VERSION . '-i18n-1', true );
		wp_set_script_translations( self::POST_CHECKOUT_HANDLE, 'cpt2collection', CPT2COLLECTION_PATH . 'languages' );
	}

	/** Cancel uses CSS only. Success receives no identifiers or shop currency. */
	public function enqueue_post_checkout( bool $success ): void {
		if ( ! wp_style_is( self::POST_CHECKOUT_HANDLE, 'registered' ) || ! wp_script_is( self::POST_CHECKOUT_HANDLE, 'registered' ) ) {
			$this->register_post_checkout_assets();
		}
		wp_enqueue_style( self::POST_CHECKOUT_HANDLE );
		if ( ! $success ) { return; }
		wp_enqueue_script( self::POST_CHECKOUT_HANDLE );
		if ( $this->post_checkout_configured ) { return; }
		$config = array(
			'lookupUrl' => esc_url_raw( rest_url( 'cpt2collection/v1/post-checkout' ) ),
			'locale' => self::browser_locale(),
		);
		wp_add_inline_script( self::POST_CHECKOUT_HANDLE, 'window.CPT2CollectionPostCheckout=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
		$this->post_checkout_configured = true;
	}

	public function register_cart_assets(): void {
		wp_register_style( self::CART_STYLE_HANDLE, CPT2COLLECTION_URL . 'assets/css/cart-frontend.css', array(), CPT2COLLECTION_VERSION );
		wp_register_script( self::CART_SCRIPT_HANDLE, CPT2COLLECTION_URL . 'assets/js/cart-frontend.js', array( 'wp-i18n' ), CPT2COLLECTION_VERSION . '-i18n-1', true );
		wp_set_script_translations( self::CART_SCRIPT_HANDLE, 'cpt2collection', CPT2COLLECTION_PATH . 'languages' );
	}

	/** Enqueues cart assets once, at shortcode render time. */
	public function enqueue_cart(): void {
		if ( ! wp_style_is( self::CART_STYLE_HANDLE, 'registered' ) || ! wp_script_is( self::CART_SCRIPT_HANDLE, 'registered' ) ) {
			$this->register_cart_assets();
		}
		wp_enqueue_style( self::CART_STYLE_HANDLE );
		wp_enqueue_script( self::CART_SCRIPT_HANDLE );
		if ( $this->cart_configured ) {
			return;
		}
		$currency = Money::get_currency();
		$money    = Money::get_currencies()[ $currency ];
		$config   = array(
			'cartUrl'     => esc_url_raw( rest_url( 'cpt2collection/v1/cart' ) ),
			'checkoutUrl' => esc_url_raw( rest_url( 'cpt2collection/v1/checkout' ) ),
			'csrfHeader' => GuestCsrf::HEADER_NAME,
			'cartPageUrl'=> esc_url_raw( CartPage::get_url() ),
			'currency'   => $currency,
			'decimals'   => $money['decimals'],
			'locale'     => self::browser_locale(),
		);
		wp_add_inline_script( self::CART_SCRIPT_HANDLE, 'window.CPT2CollectionCart=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
		$this->cart_configured = true;
	}

	/** Trim WordPress-specific locale variants before passing the tag to Intl. */
	private static function browser_locale(): string {
		$locale = str_replace( '_', '-', determine_locale() );
		return preg_match( '/\A[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-[A-Z]{2}|-[0-9]{3})?(?=-|\z)/', $locale, $matches )
			? $matches[0] : 'en-US';
	}

	/**
	 * Registers the rating stylesheet.
	 */
	public function register_rating_style(): void {
		wp_register_style(
			self::RATING_STYLE_HANDLE,
			CPT2COLLECTION_URL . 'assets/css/rating.css',
			array(),
			CPT2COLLECTION_VERSION
		);
	}

	/**
	 * Enqueues the small rating stylesheet when the plugin has a configured post type.
	 */
	public function enqueue_when_configured(): void {
		if ( '' !== $this->post_types->get_selected_post_type() ) {
			wp_enqueue_style( self::RATING_STYLE_HANDLE );
		}
	}
}
