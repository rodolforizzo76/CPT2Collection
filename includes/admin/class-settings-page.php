<?php
/**
 * Plugin settings page.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection\Admin;

use RodolfoRizzo\Cpt2Collection\CartPage;
use RodolfoRizzo\Cpt2Collection\CommerceSettings;
use RodolfoRizzo\Cpt2Collection\Money;
use RodolfoRizzo\Cpt2Collection\PostTypes;
use RodolfoRizzo\Cpt2Collection\StripeSettings;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {
	private PostTypes $post_types;

	public function __construct( PostTypes $post_types ) {
		$this->post_types = $post_types;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'redirect_legacy_url' ) );
	}

	public function add_page(): void {
		add_submenu_page( OrdersPage::MENU_SLUG, __( 'CPT2Collection Settings', 'cpt2collection' ), __( 'Settings', 'cpt2collection' ), 'manage_options', 'cpt2collection', array( $this, 'render' ) );
	}

	/** Preserve old Settings bookmarks; saving still uses the unchanged options.php form. */
	public function redirect_legacy_url(): void {
		global $pagenow;
		if ( 'options-general.php' === $pagenow && 'cpt2collection' === ( $_GET['page'] ?? null ) && current_user_can( 'manage_options' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=cpt2collection' ) );
			exit;
		}
	}

	public function register_settings(): void {
		register_setting( 'cpt2collection_settings', PostTypes::OPTION_POST_TYPE, array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_post_type' ), 'default' => '' ) );
		register_setting( 'cpt2collection_settings', Money::OPTION_CURRENCY, array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_currency' ), 'default' => Money::DEFAULT_CURRENCY ) );
		register_setting( 'cpt2collection_settings', CommerceSettings::OPTION_MINIMUM_ORDER_QUANTITY, array( 'type' => 'integer', 'sanitize_callback' => array( CommerceSettings::class, 'normalize_minimum_order_quantity' ), 'default' => CommerceSettings::DEFAULT_MINIMUM_ORDER_QUANTITY ) );
		register_setting( 'cpt2collection_settings', CartPage::OPTION_PAGE_ID, array( 'type' => 'integer', 'sanitize_callback' => array( CartPage::class, 'sanitize' ), 'default' => 0 ) );
		register_setting( 'cpt2collection_settings', CommerceSettings::OPTION_RESERVATION_DURATION_MINUTES, array( 'type' => 'integer', 'sanitize_callback' => array( CommerceSettings::class, 'normalize_reservation_duration_minutes' ), 'default' => CommerceSettings::DEFAULT_RESERVATION_DURATION_MINUTES ) );
		register_setting( 'cpt2collection_settings', StripeSettings::OPTION_ENVIRONMENT, array( 'type' => 'string', 'sanitize_callback' => array( StripeSettings::class, 'sanitize_environment' ), 'default' => StripeSettings::ENVIRONMENT_TEST ) );
		register_setting( 'cpt2collection_settings', StripeSettings::OPTION_SECRET_KEY, array( 'type' => 'string', 'sanitize_callback' => array( StripeSettings::class, 'sanitize_secret_key' ), 'default' => '' ) );
		register_setting( 'cpt2collection_settings', StripeSettings::OPTION_WEBHOOK_SECRET, array( 'type' => 'string', 'sanitize_callback' => array( StripeSettings::class, 'sanitize_webhook_secret' ), 'default' => '' ) );
		register_setting( 'cpt2collection_settings', StripeSettings::OPTION_SUCCESS_URL, array( 'type' => 'string', 'sanitize_callback' => array( StripeSettings::class, 'sanitize_https_url' ), 'default' => '' ) );
		register_setting( 'cpt2collection_settings', StripeSettings::OPTION_CANCEL_URL, array( 'type' => 'string', 'sanitize_callback' => array( StripeSettings::class, 'sanitize_https_url' ), 'default' => '' ) );

		add_settings_section( 'cpt2collection_main', __( 'Collection settings', 'cpt2collection' ), '__return_false', 'cpt2collection' );
		add_settings_field( PostTypes::OPTION_POST_TYPE, __( 'Managed post type', 'cpt2collection' ), array( $this, 'render_post_type_field' ), 'cpt2collection', 'cpt2collection_main' );
		add_settings_field( Money::OPTION_CURRENCY, __( 'Currency', 'cpt2collection' ), array( $this, 'render_currency_field' ), 'cpt2collection', 'cpt2collection_main' );
		add_settings_field( CommerceSettings::OPTION_MINIMUM_ORDER_QUANTITY, __( 'Minimum order quantity', 'cpt2collection' ), array( $this, 'render_minimum_order_quantity_field' ), 'cpt2collection', 'cpt2collection_main' );
		add_settings_field( CartPage::OPTION_PAGE_ID, __( 'Cart page', 'cpt2collection' ), array( $this, 'render_cart_page_field' ), 'cpt2collection', 'cpt2collection_main' );
		add_settings_field( CommerceSettings::OPTION_RESERVATION_DURATION_MINUTES, __( 'Reservation duration', 'cpt2collection' ), array( $this, 'render_reservation_duration_field' ), 'cpt2collection', 'cpt2collection_main' );
		add_settings_section( 'cpt2collection_stripe', __( 'Stripe Checkout', 'cpt2collection' ), array( $this, 'render_stripe_section' ), 'cpt2collection' );
		add_settings_field( StripeSettings::OPTION_ENVIRONMENT, __( 'Environment', 'cpt2collection' ), array( $this, 'render_stripe_environment_field' ), 'cpt2collection', 'cpt2collection_stripe' );
		add_settings_field( StripeSettings::OPTION_SECRET_KEY, __( 'Secret key', 'cpt2collection' ), array( $this, 'render_stripe_secret_field' ), 'cpt2collection', 'cpt2collection_stripe' );
		add_settings_field( StripeSettings::OPTION_WEBHOOK_SECRET, __( 'Webhook signing secret', 'cpt2collection' ), array( $this, 'render_stripe_webhook_secret_field' ), 'cpt2collection', 'cpt2collection_stripe' );
		add_settings_field( StripeSettings::OPTION_SUCCESS_URL, __( 'Success URL', 'cpt2collection' ), array( $this, 'render_stripe_success_url_field' ), 'cpt2collection', 'cpt2collection_stripe' );
		add_settings_field( StripeSettings::OPTION_CANCEL_URL, __( 'Cancel URL', 'cpt2collection' ), array( $this, 'render_stripe_cancel_url_field' ), 'cpt2collection', 'cpt2collection_stripe' );
	}

	public function sanitize_post_type( mixed $value ): string {
		$post_type = sanitize_key( (string) $value );
		return $this->post_types->is_eligible( $post_type ) ? $post_type : '';
	}

	public function sanitize_currency( mixed $value ): string {
		$currency = strtoupper( sanitize_key( (string) $value ) );
		return isset( Money::get_currencies()[ $currency ] ) ? $currency : Money::DEFAULT_CURRENCY;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'CPT2Collection Settings', 'cpt2collection' ); ?></h1>
			<form action="options.php" method="post">
				<?php settings_fields( 'cpt2collection_settings' ); ?>
				<?php do_settings_sections( 'cpt2collection' ); ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function render_post_type_field(): void {
		$selected   = (string) get_option( PostTypes::OPTION_POST_TYPE, '' );
		$post_types = $this->post_types->get_eligible_post_types();
		?>
		<select name="<?php echo esc_attr( PostTypes::OPTION_POST_TYPE ); ?>" id="<?php echo esc_attr( PostTypes::OPTION_POST_TYPE ); ?>">
			<option value=""><?php echo esc_html__( 'Select a custom post type', 'cpt2collection' ); ?></option>
			<?php foreach ( $post_types as $slug => $post_type ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $selected, $slug ); ?>><?php
					/* translators: 1: post type display name, 2: post type slug. */
					echo esc_html( sprintf( _x( '%1$s — %2$s', 'post type selector option', 'cpt2collection' ), $post_type->labels->name, $slug ) );
				?></option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php echo esc_html__( 'Choose one existing custom post type to manage with CPT2Collection.', 'cpt2collection' ); ?></p>
		<?php
	}

	public function render_currency_field(): void {
		$selected = Money::get_currency();
		?>
		<select name="<?php echo esc_attr( Money::OPTION_CURRENCY ); ?>" id="<?php echo esc_attr( Money::OPTION_CURRENCY ); ?>">
			<?php foreach ( Money::get_currencies() as $code => $currency ) : ?>
				<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $selected, $code ); ?>><?php
					/* translators: 1: currency code, 2: currency name. */
					echo esc_html( sprintf( _x( '%1$s — %2$s', 'currency selector option', 'cpt2collection' ), $code, $currency['label'] ) );
				?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function render_minimum_order_quantity_field(): void {
		$quantity = CommerceSettings::get_minimum_order_quantity();
		?>
		<input type="number" name="<?php echo esc_attr( CommerceSettings::OPTION_MINIMUM_ORDER_QUANTITY ); ?>" id="<?php echo esc_attr( CommerceSettings::OPTION_MINIMUM_ORDER_QUANTITY ); ?>" value="<?php echo esc_attr( (string) $quantity ); ?>" min="1" step="1" />
		<p class="description"><?php echo esc_html__( 'Minimum total item quantity required at checkout. Use 1 for no additional minimum.', 'cpt2collection' ); ?></p>
		<?php
	}

	public function render_cart_page_field(): void {
		wp_dropdown_pages( array(
			'name'              => CartPage::OPTION_PAGE_ID,
			'id'                => CartPage::OPTION_PAGE_ID,
			'show_option_none'  => __( 'Select a page', 'cpt2collection' ),
			'option_none_value' => '0',
			'sort_column'       => 'post_title',
			'selected'          => CartPage::get_id(),
		) );
		?>
		<p class="description"><?php /* translators: Keep the shortcode [cpt2collection_cart] unchanged. */ echo esc_html__( 'Choose a page containing the [cpt2collection_cart] shortcode.', 'cpt2collection' ); ?></p>
		<?php
	}

	public function render_reservation_duration_field(): void { $value = CommerceSettings::get_reservation_duration_minutes(); ?><input type="number" min="5" max="120" step="1" name="<?php echo esc_attr( CommerceSettings::OPTION_RESERVATION_DURATION_MINUTES ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" /><p class="description"><?php echo esc_html__( 'Minutes stock remains reserved. Stripe Checkout requires at least 30 minutes remaining; 35 minutes is recommended.', 'cpt2collection' ); ?></p><?php }
	public function render_stripe_section(): void { ?><p><?php echo esc_html__( 'Hosted, one-time, card-only Checkout. Creating a Session does not confirm payment.', 'cpt2collection' ); ?></p><?php }
	public function render_stripe_environment_field(): void { $value = StripeSettings::environment(); ?><select name="<?php echo esc_attr( StripeSettings::OPTION_ENVIRONMENT ); ?>"><option value="test" <?php selected( $value, 'test' ); ?>><?php echo esc_html__( 'Test', 'cpt2collection' ); ?></option><option value="live" <?php selected( $value, 'live' ); ?>><?php echo esc_html__( 'Live', 'cpt2collection' ); ?></option></select><?php }
	public function render_stripe_secret_field(): void { $configured = '' !== StripeSettings::secret_key(); ?><input type="password" autocomplete="new-password" name="<?php echo esc_attr( StripeSettings::OPTION_SECRET_KEY ); ?>" value="" /><p class="description"><?php echo esc_html( $configured ? __( 'Configured. Leave empty to keep the current secret.', 'cpt2collection' ) : __( 'Not configured. Enter an sk_test_ or sk_live_ secret key.', 'cpt2collection' ) ); ?></p><?php }
	public function render_stripe_webhook_secret_field(): void { $configured = '' !== StripeSettings::webhook_secret(); ?><input type="password" autocomplete="new-password" name="<?php echo esc_attr( StripeSettings::OPTION_WEBHOOK_SECRET ); ?>" value="" /><p class="description"><?php echo esc_html( $configured ? __( 'Configured. Leave empty to keep the current webhook secret.', 'cpt2collection' ) : __( 'Not configured. Enter the whsec_ signing secret for this environment.', 'cpt2collection' ) ); ?></p><?php }
	public function render_stripe_success_url_field(): void { $this->render_stripe_url( StripeSettings::OPTION_SUCCESS_URL, StripeSettings::success_url(), __( 'Absolute HTTPS URL. Stripe preserves and replaces the literal {CHECKOUT_SESSION_ID} placeholder.', 'cpt2collection' ) ); }
	public function render_stripe_cancel_url_field(): void { $this->render_stripe_url( StripeSettings::OPTION_CANCEL_URL, StripeSettings::cancel_url(), __( 'Absolute HTTPS URL. A visit does not cancel local state.', 'cpt2collection' ) ); }
	private function render_stripe_url( string $name, string $value, string $description ): void { ?><input class="regular-text code" type="url" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" /><p class="description"><?php echo esc_html( $description ); ?></p><?php }
}
