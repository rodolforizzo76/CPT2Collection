<?php
/**
 * Main plugin bootstrap.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

use RodolfoRizzo\Cpt2Collection\Admin\AdminNotices;
use RodolfoRizzo\Cpt2Collection\Admin\ListTable;
use RodolfoRizzo\Cpt2Collection\Admin\StockQuickEdit;
use RodolfoRizzo\Cpt2Collection\Admin\MetaBox;
use RodolfoRizzo\Cpt2Collection\Admin\SettingsPage;
use RodolfoRizzo\Cpt2Collection\Admin\OrdersPage;
use RodolfoRizzo\Cpt2Collection\Admin\CommerceResetPage;
use RodolfoRizzo\Cpt2Collection\Frontend\Assets;
use RodolfoRizzo\Cpt2Collection\Frontend\AddToCartShortcode;
use RodolfoRizzo\Cpt2Collection\Frontend\CartCountShortcode;
use RodolfoRizzo\Cpt2Collection\Frontend\CartShortcode;
use RodolfoRizzo\Cpt2Collection\Frontend\MiniCartShortcode;
use RodolfoRizzo\Cpt2Collection\Frontend\PriceShortcode;
use RodolfoRizzo\Cpt2Collection\Frontend\PostCheckoutShortcodes;
use RodolfoRizzo\Cpt2Collection\Frontend\RatingShortcode;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	public function register(): void {
		add_action( 'init', fn() => $this->load_textdomain(), 0 );
		Database::maybe_upgrade();
		PaymentNotificationCron::register();

		$post_types = new PostTypes();
		$this->register_cart_rest_api( $post_types );
		$this->register_checkout_rest_api( $post_types );
		$this->register_post_checkout_rest_api();
		$this->register_stripe_webhook( $post_types );
		$this->register_order_deletion_rest_api();
		$this->register_commerce_reset_rest_api();

		$assets = new Assets( $post_types );
		$assets->register();
		( new RatingShortcode() )->register();
		( new PriceShortcode() )->register();
		( new AddToCartShortcode( $assets, new CommerceRules( $post_types ) ) )->register();
		( new CartCountShortcode( $assets ) )->register();
		( new MiniCartShortcode( $assets ) )->register();
		( new CartShortcode( $assets ) )->register();
		( new PostCheckoutShortcodes( $assets ) )->register();

		if ( is_admin() ) {
			global $wpdb;
			( new OrdersPage( new OrderAdminReadRepository( $wpdb, new TableNames( $wpdb ) ) ) )->register();
			( new CommerceResetPage( static function (): ?CommerceContext {
				global $wpdb;
				$db = CommerceWpdb::connect( $wpdb );
				try {
					$transactions = new TransactionManager( $db, new TableNames( $db ) );
					if ( ! $transactions->begin_commerce_shared() ) { return null; }
					$context = $transactions->commerce_context();
					return $transactions->rollback() ? $context : null;
				} finally { $db->close(); }
			} ) )->register();
			( new SettingsPage( $post_types ) )->register();
			$stock_db = CommerceWpdb::connect( $wpdb );
			$stock_tables = new TableNames( $stock_db );
			$stock_transactions = new TransactionManager( $stock_db, $stock_tables );
			$admin_stock = new AdminStockService( $stock_db, new AdminStockRepository( $stock_db, $stock_transactions ), new LockedCatalogReader( $stock_db, $stock_transactions ), new ReservationRepository( $stock_db, $stock_tables ), $stock_transactions, $post_types, new UtcClock() );
			( new MetaBox( $post_types, $admin_stock ) )->register();
			$stock_quick_edit = new StockQuickEdit( $post_types, $admin_stock );
			$stock_quick_edit->register();
			( new ListTable( $post_types, $stock_quick_edit ) )->register();
			( new AdminNotices() )->register();
		}
	}

	private function register_commerce_reset_rest_api(): void {
		add_action( 'rest_api_init', static function (): void {
			( new CommerceResetRestController( static function ( string $action, array $input ): CommerceResetResult {
				global $wpdb;
				$db = CommerceWpdb::connect( $wpdb );
				try {
					$tables = new TableNames( $db );
					$service = new CommerceResetService( new CommerceResetRepository( $db, $tables ), new TransactionManager( $db, $tables ), new UtcClock() );
					return match ( $action ) {
						'start' => $service->start( $input['operation_identity'], $input['expected_epoch_identity'], $input['requested_by'] ),
						'status' => $service->inspect( $input['operation_identity'] ),
						'resume' => $service->continue_operation( $input['operation_identity'], $input['expected_epoch_identity'], (int) $input['revision'] ),
						default => new CommerceResetResult( 'invalid_request' ),
					};
				} finally { $db->close(); }
			} ) )->register();
		} );
	}

	private function register_order_deletion_rest_api(): void {
		add_action( 'rest_api_init', static function (): void {
			( new OrderDeletionRestController( static function ( int $order_id, CommerceContext $expected ): OrderDeletionResult {
				global $wpdb;
				$db = CommerceWpdb::connect( $wpdb );
				try {
					$tables = new TableNames( $db );
					$current = ( new CommerceControlRepository( $db, $tables ) )->read();
					if ( null === $current ) { return OrderDeletionResult::failure( 'database_error', $order_id ); }
					if ( ! $current->equals( $expected ) ) { return OrderDeletionResult::failure( 'stale_context', $order_id ); }
					$service = new OrderDeletionService( new OrderDeletionRepository( $db, $tables ), new OrderDeletionRegistry( $db, $tables ), new TransactionManager( $db, $tables ), new UtcClock() );
					// The unlocked preflight is not authority: admission compares the same exact context again.
					return $service->delete( $order_id, $expected );
				} finally { $db->close(); }
			} ) )->register();
		} );
	}

	private function register_post_checkout_rest_api(): void {
		add_action( 'rest_api_init', static function (): void {
			global $wpdb;
			$tokens = new CartToken();
			( new PostCheckoutRestController(
				new PostCheckoutReadRepository( $wpdb, new TableNames( $wpdb ) ),
				new CartCookie( $tokens ), $tokens, new UtcClock()
			) )->register();
		} );
	}

	private function register_checkout_rest_api( PostTypes $post_types ): void {
		add_action( 'rest_api_init', static function () use ( $post_types ): void {
			global $wpdb; $db = CommerceWpdb::connect( $wpdb );
			$tables       = new TableNames( $db );
			$clock        = new UtcClock();
			$carts        = new CartRepository( $db, $tables );
			$tokens       = new CartToken();
			$activity     = new CartActivity( $carts, $clock );
			$context      = new CartContext( $carts, $tokens, new CartCookie( $tokens ), $clock, $activity );
			$orders       = new OrderRepository( $db, $tables );
			$reservations = new ReservationRepository( $db, $tables );
			$sessions     = new PaymentSessionRepository( $db, $tables );
			$deletions    = new OrderDeletionRegistry( $db, $tables );
			$transactions = $carts->transactions();
			$acquirer     = new ReservationAcquirer( $orders, $reservations, $transactions, new LockedCatalogReader( $db, $transactions ), $post_types, $clock );
			$checkout     = new CheckoutService( $carts, new CommerceRules( $post_types ), $orders, $transactions, $acquirer, $clock, $reservations, $sessions, new PaymentEventRepository( $db, $tables ), $deletions );
			$payments     = static function ( int $order_id, string $key, ?CommerceContext $expected = null, ?string $browser_context = null ) use ( $wpdb, $orders, $reservations, $sessions, $transactions, $clock, $deletions ): PaymentSessionResult {
				$provider = apply_filters( 'cpt2collection_checkout_payment_provider', null, $order_id );
				if ( ! $provider instanceof PaymentProviderInterface ) {
					$provider = StripeCheckoutProvider::configured( $clock );
				}
				return ( new PaymentSessionService( $orders, $reservations, $sessions, $transactions, new ReservationSetValidator(), $provider, $clock, $deletions, new ProviderResponseEvidence(static fn()=>CommerceWpdb::connect($wpdb)) ) )->create( $order_id, $key, $expected, $browser_context );
			};
			( new CheckoutRestController(
				static fn(): ?CartRecord => $context->get_existing_cart(),
				static fn( CartRecord $cart, string $key, string $browser_context ): CheckoutPreparationResult => $checkout->prepare( $cart, $key, $browser_context ),
				$payments,
				new GuestCsrf()
			) )->register();
		} );
	}
	private function register_stripe_webhook(PostTypes $post_types):void {
		add_action('rest_api_init',static function()use($post_types):void { global $wpdb; $db = CommerceWpdb::connect( $wpdb );
			$db->suppress_errors();
			$tables=new TableNames($db);$clock=new UtcClock();$tx=new TransactionManager($db,$tables);$orders=new OrderRepository($db,$tables);$sessions=new PaymentSessionRepository($db,$tables);$events=new PaymentEventRepository($db,$tables);$reservations=new ReservationRepository($db,$tables);$deletions=new OrderDeletionRegistry($db,$tables);
			$validator=new ConversionReservationSetValidator();$stock=new StockConversionProcessor($reservations,new LockedCatalogReader($db,$tx),$tx,$validator);$continuation=$events->continuation(static fn()=>CommerceWpdb::connect($wpdb));$conversion=new SaleConversionService($orders,$reservations,$sessions,$events,$tx,$stock,$validator,$post_types,$clock,$deletions,$continuation);$outcomes=new PaymentEventOutcomeService($orders,$sessions,$events,$tx,$clock,$continuation);$association=new PaymentEventAssociationService($orders,$sessions,$events,$tx,$clock,StripeSettings::ENVIRONMENT_LIVE===StripeSettings::environment(),$deletions,$continuation);$expiration=new PaymentExpirationService($orders,$sessions,$events,$tx,$outcomes,$clock,$deletions,$continuation);
			$reconciliation=new CartReconciliationService(new CartRepository($db,$tables),$orders,$tx,$clock,$deletions,(new CartRepository($db,$tables))->reconciliation_boundary(static fn()=>CommerceWpdb::connect($wpdb)));
			(new StripeWebhookRestController(StripeWebhookVerifier::configured(),new PaymentWebhookOrchestrator(new StripeEventNormalizer(),$events,$association,$conversion,$expiration,$outcomes,$clock,$reconciliation,$tx,new ProviderWebhookEvidence(static fn()=>CommerceWpdb::connect($wpdb))),static fn()=> $db->close()))->register();
		});
	}

	private function register_cart_rest_api( PostTypes $post_types ): void {
		add_action(
			'rest_api_init',
			static function () use ( $post_types ): void {
				global $wpdb; $db = CommerceWpdb::connect( $wpdb );

				$tables     = new TableNames( $db );
				$repository = new CartRepository( $db, $tables );
				$clock      = new UtcClock();
				$tokens     = new CartToken();
				$activity   = new CartActivity( $repository, $clock );
				$rules      = new CommerceRules( $post_types );
				$context    = new CartContext( $repository, $tokens, new CartCookie( $tokens ), $clock, $activity );
					$builder    = new CartResponseBuilder( $repository, $rules );
					$transactions = $repository->transactions();

					( new CartRestController( $context, $repository, $activity, $rules, $builder, new GuestCsrf(), $transactions ) )->register();
			}
		);
	}

	private function load_textdomain(): void {
		load_plugin_textdomain( 'cpt2collection', false, dirname( plugin_basename( CPT2COLLECTION_FILE ) ) . '/languages' );
	}
}
