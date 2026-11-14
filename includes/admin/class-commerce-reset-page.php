<?php
/** Deliberate exact-operation Full Commerce Reset administration. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection\Admin;

use RodolfoRizzo\Cpt2Collection\{CommerceContext, CommerceResetLifecycle};
defined( 'ABSPATH' ) || exit;

final class CommerceResetPage {
	public const MENU_SLUG = 'cpt2collection-commerce-reset';
	/** @param callable():?CommerceContext $read_context Confirmed read-only ready admission; never repairs. */
	public function __construct( private $read_context ) {}
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}
	public function add_page(): void {
		add_submenu_page( OrdersPage::MENU_SLUG, __( 'Full Commerce Reset', 'cpt2collection' ), __( 'Full Commerce Reset', 'cpt2collection' ), 'manage_options', self::MENU_SLUG, array( $this, 'render' ) );
	}
	public function enqueue(): void {
		if ( ! current_user_can( 'manage_options' ) || ( $_GET['page'] ?? null ) !== self::MENU_SLUG ) { return; }
		wp_enqueue_script( 'cpt2collection-commerce-reset', CPT2COLLECTION_URL . 'assets/js/commerce-reset-admin.js', array( 'wp-i18n' ), CPT2COLLECTION_VERSION . '-reset-3', true );
		wp_set_script_translations( 'cpt2collection-commerce-reset', 'cpt2collection', CPT2COLLECTION_PATH . 'languages' );
	}
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		try { $context = ( $this->read_context )(); } catch ( \Throwable $error ) { $context = null; }
		if ( ! $context instanceof CommerceContext ) { $context = null; }
		$input = wp_unslash( $_GET['operation'] ?? '' );
		$identity = is_string( $input ) && CommerceResetLifecycle::identity_valid( $input ) ? $input : '';
		$root = 'cpt2collection/v1/commerce/resets';
		$config = array(
			'identity' => $identity, 'invalid_identity' => '' !== $input && '' === $identity,
			'source' => $context?->epoch_identity(), 'max_integer' => (string) PHP_INT_MAX,
			'start_url' => rest_url( $root ), 'status_url' => rest_url( $root . '/__operation__' ),
			'resume_url' => rest_url( $root . '/__operation__/resume' ), 'nonce' => wp_create_nonce( 'wp_rest' ),
			'page_url' => add_query_arg( array( 'page' => self::MENU_SLUG ), admin_url( 'admin.php' ) ),
			'storage_key' => 'c2c-reset-' . hash( 'sha256', admin_url( 'admin.php' ) . ':' . get_current_user_id() ),
		);
		echo '<div class="wrap" id="c2c-reset" data-config="' . esc_attr( wp_json_encode( $config ) ) . '"><h1>' . esc_html__( 'Full Commerce Reset', 'cpt2collection' ) . '</h1>';
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Operational commerce records will be permanently removed. Commerce will pause until verified completion.', 'cpt2collection' ) . '</p><p>' . esc_html__( 'Products, settings and physical stock are preserved. External payments are not refunded or cancelled.', 'cpt2collection' ) . '</p><p>' . esc_html__( 'Closing this page pauses progression. Keep the recovery URL to check status and continue later.', 'cpt2collection' ) . '</p></div>';
		echo '<div id="c2c-reset-result" class="notice inline notice-info" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"><p>';
		if ( null === $context && '' === $identity ) {
			echo esc_html__( 'A ready commerce context could not be verified. Look up an existing operation in Advanced details.', 'cpt2collection' );
		}
		echo '</p></div>';
		echo '<form id="c2c-reset-start-form" hidden><p><label for="c2c-reset-start-confirmation">' . esc_html__( 'Type the exact confirmation', 'cpt2collection' ) . ' <strong>FULL COMMERCE RESET</strong></label><br><input class="regular-text" id="c2c-reset-start-confirmation" type="text" autocomplete="off" spellcheck="false" disabled></p>';
		echo '<p><button class="button button-primary" type="submit" id="c2c-reset-start" disabled>' . esc_html__( 'Start reset', 'cpt2collection' ) . '</button></p></form>';
		echo '<p id="c2c-reset-phase"></p><progress id="c2c-reset-progress" aria-label="' . esc_attr__( 'Full Commerce Reset progress', 'cpt2collection' ) . '" hidden></progress>';
		echo '<p><span id="c2c-reset-pause-control" hidden><button type="button" id="c2c-reset-pause" class="button" disabled>' . esc_html__( 'Pause after current step', 'cpt2collection' ) . '</button></span><span id="c2c-reset-resume-control" hidden><button type="button" id="c2c-reset-resume" class="button button-primary" disabled>' . esc_html__( 'Continue reset', 'cpt2collection' ) . '</button></span><span id="c2c-reset-orders-control" hidden><a class="button" id="c2c-reset-orders" href="' . esc_url( add_query_arg( 'page', OrdersPage::MENU_SLUG, admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Back to Orders', 'cpt2collection' ) . '</a></span></p>';
		echo '<p id="c2c-reset-recovery-wrap" hidden><label for="c2c-reset-recovery">' . esc_html__( 'Recovery URL', 'cpt2collection' ) . '</label><br><input class="large-text" id="c2c-reset-recovery" type="text" readonly></p>';
		echo '<details><summary>' . esc_html__( 'Advanced details', 'cpt2collection' ) . '</summary>';
		echo '<table class="widefat striped" id="c2c-reset-operation" hidden><tbody>';
		foreach ( array( 'identity' => __( 'Operation identity', 'cpt2collection' ), 'source' => __( 'Source epoch identity', 'cpt2collection' ), 'phase' => __( 'Current phase', 'cpt2collection' ), 'revision' => __( 'Revision', 'cpt2collection' ), 'checkpoint' => __( 'Checkpoint', 'cpt2collection' ), 'cursor' => __( 'Cursor', 'cpt2collection' ), 'status' => __( 'Last confirmed diagnostic status', 'cpt2collection' ) ) as $key => $label ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td id="c2c-reset-value-' . esc_attr( $key ) . '"></td></tr>';
		}
		echo '</tbody></table>';
		echo '<form id="c2c-reset-inspect-form"><p><label for="c2c-reset-identity">' . esc_html__( 'Exact operation identity (32 lowercase hexadecimal characters)', 'cpt2collection' ) . '</label><br><input class="regular-text" id="c2c-reset-identity" type="text" maxlength="32" autocomplete="off" spellcheck="false" value="' . esc_attr( $identity ) . '"></p><p><button type="submit" class="button" id="c2c-reset-inspect" disabled>' . esc_html__( 'Check status', 'cpt2collection' ) . '</button></p></form></details>';
		echo '<noscript><p>' . esc_html__( 'Enable JavaScript to use Full Commerce Reset.', 'cpt2collection' ) . '</p></noscript></div>';
	}
}
