<?php
/**
 * Plugin Name: CPT2Collection
 * Plugin URI: https://github.com/rodolforizzo76/CPT2Collection
 * Description: Adds collection-management and commercial metadata to one administrator-selected custom post type.
 * Version: 1.0.1
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Author: Rodolfo Rizzo
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cpt2collection
 * Domain Path: /languages
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'CPT2COLLECTION_VERSION', '1.0.1' );
define( 'CPT2COLLECTION_FILE', __FILE__ );
define( 'CPT2COLLECTION_PATH', plugin_dir_path( __FILE__ ) );
define( 'CPT2COLLECTION_URL', plugin_dir_url( __FILE__ ) );
define( 'CPT2COLLECTION_TEXT_DOMAIN', 'cpt2collection' );

$cpt2collection_release_loader = CPT2COLLECTION_PATH . 'cpt2collection-release-autoload.php';
if ( file_exists( $cpt2collection_release_loader ) ) {
	require_once $cpt2collection_release_loader;
} else {
	// Source checkouts use the ordinary Composer tree. Release builds generate the loader above.
	$cpt2collection_autoloader = CPT2COLLECTION_PATH . 'vendor/autoload.php';
	if ( is_readable( $cpt2collection_autoloader ) ) {
		require_once $cpt2collection_autoloader;
	}
}

require_once CPT2COLLECTION_PATH . 'includes/class-commerce-wpdb.php';
require_once CPT2COLLECTION_PATH . 'includes/class-database.php';
require_once CPT2COLLECTION_PATH . 'includes/class-stock-metadata-migration.php';
require_once CPT2COLLECTION_PATH . 'includes/class-table-names.php';
require_once CPT2COLLECTION_PATH . 'includes/class-utc-clock.php';
require_once CPT2COLLECTION_PATH . 'includes/class-cart-token.php';
require_once CPT2COLLECTION_PATH . 'includes/class-cart-record.php';
require_once CPT2COLLECTION_PATH . 'includes/class-cart-item.php';
require_once CPT2COLLECTION_PATH . 'includes/class-cart-operation-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-commerce-settings.php';
require_once CPT2COLLECTION_PATH . 'includes/class-order-statuses.php';
require_once CPT2COLLECTION_PATH . 'includes/class-reservation-statuses.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-session-statuses.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-event-statuses.php';
require_once CPT2COLLECTION_PATH . 'includes/class-order-record.php';
require_once CPT2COLLECTION_PATH . 'includes/class-order-deletion-record.php';
require_once CPT2COLLECTION_PATH . 'includes/class-commerce-evidence-generation-migration.php';
require_once CPT2COLLECTION_PATH . 'includes/class-commerce-reset-record.php';
require_once CPT2COLLECTION_PATH . 'includes/class-provider-retained-discovery.php';
require_once CPT2COLLECTION_PATH . 'includes/class-operational-recovery-inspector.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-event-continuation.php';
require_once CPT2COLLECTION_PATH . 'includes/class-provider-evidence-router.php';
require_once CPT2COLLECTION_PATH . 'includes/class-commerce-reset-repository.php';
require_once CPT2COLLECTION_PATH . 'includes/class-commerce-reset-validation.php';
require_once CPT2COLLECTION_PATH . 'includes/class-commerce-reset-retirement.php';
require_once CPT2COLLECTION_PATH . 'includes/class-commerce-reset-provider-coverage.php';
require_once CPT2COLLECTION_PATH . 'includes/class-commerce-reset-retained-evidence.php';
require_once CPT2COLLECTION_PATH . 'includes/class-commerce-reset-deletion.php';
require_once CPT2COLLECTION_PATH . 'includes/class-commerce-reset-lifecycle.php';
require_once CPT2COLLECTION_PATH . 'includes/class-commerce-reset-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-commerce-reset-service.php';
require_once CPT2COLLECTION_PATH . 'includes/class-commerce-baseline-purge-migration.php';
require_once CPT2COLLECTION_PATH . 'includes/class-commerce-baseline-purge-record.php';
require_once CPT2COLLECTION_PATH . 'includes/class-commerce-baseline-purge-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-commerce-baseline-purge-service.php';
require_once CPT2COLLECTION_PATH . 'includes/class-order-deletion-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-order-item.php';
require_once CPT2COLLECTION_PATH . 'includes/class-reservation-record.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-session-record.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-event-record.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-event-receipt.php';
require_once CPT2COLLECTION_PATH . 'includes/class-order-repository.php';
require_once CPT2COLLECTION_PATH . 'includes/class-order-deletion-registry.php';
require_once CPT2COLLECTION_PATH . 'includes/class-order-deletion-repository.php';
require_once CPT2COLLECTION_PATH . 'includes/class-order-deletion-service.php';
require_once CPT2COLLECTION_PATH . 'includes/class-order-deletion-rest-controller.php';
require_once CPT2COLLECTION_PATH . 'includes/class-commerce-reset-rest-controller.php';
require_once CPT2COLLECTION_PATH . 'includes/class-reservation-repository.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-session-repository.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-event-insert-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-event-receipt-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-event-association-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-event-attention-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-event-repository.php';
require_once CPT2COLLECTION_PATH . 'includes/class-reservation-operation-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-reservation-set-validation-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-reservation-set-validator.php';
require_once CPT2COLLECTION_PATH . 'includes/class-conversion-reservation-validation-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-conversion-reservation-set-validator.php';
require_once CPT2COLLECTION_PATH . 'includes/class-stock-conversion-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-sale-conversion-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-webhook-outcome-categories.php';
require_once CPT2COLLECTION_PATH . 'includes/class-sale-conversion-result-classifier.php';
require_once CPT2COLLECTION_PATH . 'includes/class-transaction-manager.php';
require_once CPT2COLLECTION_PATH . 'includes/class-locked-catalog-reader.php';
require_once CPT2COLLECTION_PATH . 'includes/class-stock-quantity.php';
require_once CPT2COLLECTION_PATH . 'includes/class-admin-stock-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-admin-stock-repository.php';
require_once CPT2COLLECTION_PATH . 'includes/class-admin-stock-service.php';
require_once CPT2COLLECTION_PATH . 'includes/class-stock-conversion-processor.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-financial-stage-inspection.php';
require_once CPT2COLLECTION_PATH . 'includes/class-sale-conversion-service.php';
require_once CPT2COLLECTION_PATH . 'includes/class-cart-reconciliation-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-cart-reconciliation-service.php';
require_once CPT2COLLECTION_PATH . 'includes/class-reservation-acquirer.php';
require_once CPT2COLLECTION_PATH . 'includes/class-checkout-preparation-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-checkout-service.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-provider-line-item.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-provider-session-request.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-provider-session-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-provider-exception.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-provider-interface.php';
require_once CPT2COLLECTION_PATH . 'includes/class-stripe-settings.php';
require_once CPT2COLLECTION_PATH . 'includes/class-stripe-webhook-verification-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-stripe-webhook-verifier.php';
require_once CPT2COLLECTION_PATH . 'includes/class-stripe-event-normalization-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-stripe-event-normalizer.php';
require_once CPT2COLLECTION_PATH . 'includes/class-webhook-http-disposition.php';
require_once CPT2COLLECTION_PATH . 'includes/class-webhook-association-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-event-association-service.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-event-outcome-service.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-expiration-service.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-webhook-orchestrator.php';
require_once CPT2COLLECTION_PATH . 'includes/class-stripe-webhook-rest-controller.php';
require_once CPT2COLLECTION_PATH . 'includes/class-stripe-checkout-client-interface.php';
require_once CPT2COLLECTION_PATH . 'includes/class-stripe-sdk-checkout-client.php';
require_once CPT2COLLECTION_PATH . 'includes/class-stripe-checkout-provider.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-session-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-session-service.php';
require_once CPT2COLLECTION_PATH . 'includes/class-cart-page.php';
require_once CPT2COLLECTION_PATH . 'includes/class-commerce-rule-result.php';
require_once CPT2COLLECTION_PATH . 'includes/class-cart-cookie.php';
require_once CPT2COLLECTION_PATH . 'includes/class-cart-repository.php';
require_once CPT2COLLECTION_PATH . 'includes/class-cart-activity.php';
require_once CPT2COLLECTION_PATH . 'includes/class-cart-context.php';
require_once CPT2COLLECTION_PATH . 'includes/class-cart-service.php';
require_once CPT2COLLECTION_PATH . 'includes/class-post-types.php';
require_once CPT2COLLECTION_PATH . 'includes/class-money.php';
require_once CPT2COLLECTION_PATH . 'includes/class-statuses.php';
require_once CPT2COLLECTION_PATH . 'includes/class-meta-keys.php';
require_once CPT2COLLECTION_PATH . 'includes/class-catalog.php';
require_once CPT2COLLECTION_PATH . 'includes/class-commerce-rules.php';
require_once CPT2COLLECTION_PATH . 'includes/class-cart-response-builder.php';
require_once CPT2COLLECTION_PATH . 'includes/class-guest-csrf.php';
require_once CPT2COLLECTION_PATH . 'includes/class-cart-rest-controller.php';
require_once CPT2COLLECTION_PATH . 'includes/class-checkout-rest-controller.php';
require_once CPT2COLLECTION_PATH . 'includes/class-post-checkout-read-repository.php';
require_once CPT2COLLECTION_PATH . 'includes/class-post-checkout-view.php';
require_once CPT2COLLECTION_PATH . 'includes/class-post-checkout-rest-controller.php';
require_once CPT2COLLECTION_PATH . 'includes/class-ratings.php';
require_once CPT2COLLECTION_PATH . 'includes/class-prices.php';
require_once CPT2COLLECTION_PATH . 'includes/admin/class-admin-notices.php';
require_once CPT2COLLECTION_PATH . 'includes/class-order-admin-read-repository.php';
require_once CPT2COLLECTION_PATH . 'includes/admin/class-order-detail-page.php';
require_once CPT2COLLECTION_PATH . 'includes/admin/class-orders-page.php';
require_once CPT2COLLECTION_PATH . 'includes/admin/class-commerce-reset-page.php';
require_once CPT2COLLECTION_PATH . 'includes/admin/class-settings-page.php';
require_once CPT2COLLECTION_PATH . 'includes/admin/class-meta-box.php';
require_once CPT2COLLECTION_PATH . 'includes/admin/class-stock-quick-edit.php';
require_once CPT2COLLECTION_PATH . 'includes/admin/class-list-table.php';
require_once CPT2COLLECTION_PATH . 'includes/frontend/class-assets.php';
require_once CPT2COLLECTION_PATH . 'includes/frontend/class-add-to-cart-shortcode.php';
require_once CPT2COLLECTION_PATH . 'includes/frontend/class-cart-count-shortcode.php';
require_once CPT2COLLECTION_PATH . 'includes/frontend/class-mini-cart-shortcode.php';
require_once CPT2COLLECTION_PATH . 'includes/frontend/class-cart-shortcode.php';
require_once CPT2COLLECTION_PATH . 'includes/frontend/class-post-checkout-shortcodes.php';
require_once CPT2COLLECTION_PATH . 'includes/frontend/class-rating-shortcode.php';
require_once CPT2COLLECTION_PATH . 'includes/frontend/class-price-shortcode.php';
require_once CPT2COLLECTION_PATH . 'includes/class-payment-notification-cron.php';
require_once CPT2COLLECTION_PATH . 'includes/class-plugin.php';
require_once CPT2COLLECTION_PATH . 'includes/functions.php';

register_activation_hook(
	__FILE__,
	static function (): void {
		RodolfoRizzo\Cpt2Collection\Database::activate();
	}
);

register_deactivation_hook( __FILE__, array( RodolfoRizzo\Cpt2Collection\PaymentNotificationCron::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		$plugin = new RodolfoRizzo\Cpt2Collection\Plugin();
		$plugin->register();
	}
);
