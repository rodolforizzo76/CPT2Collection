<?php
/** CLI fixture and MU mail capture for the disposable WordPress notification runner. */
declare( strict_types=1 );

use RodolfoRizzo\Cpt2Collection\{CartRepository, CommerceWpdb, Database, OrderRepository, PaymentNotificationCron, StripeCheckoutClientInterface, StripeCheckoutProvider, PaymentSessionRepository, PaymentSessionService, ProviderResponseEvidence, ReservationRepository, ReservationSetValidator, TableNames, TransactionManager, UtcClock};

$fixture = getenv( 'C2C_NOTIFY_NATIVE_ROOT' );
if ( ! is_string( $fixture ) || ! str_starts_with( $fixture, '/tmp/c2c-notify-native-' ) ) {
	throw new RuntimeException( 'Disposable native WordPress fixture required.' );
}

if ( defined( 'ABSPATH' ) ) {
	add_action( 'init', static fn() => register_post_type( 'notify_product', array( 'public' => true ) ) );
	add_filter( 'log_query_custom_data', static function ( array $data, string $sql ) use ( $fixture ): array {
		if ( '[commerce private update]' === $sql || str_contains( $sql, '`customer_mail_to`' ) || str_contains( $sql, 'Snapshot 李' ) || str_contains( $sql, 'buyer@slow.example.test' ) ) {
			file_put_contents( "$fixture/sql-diagnostics", json_encode( $sql ) . "\n", FILE_APPEND | LOCK_EX );
		}
		return $data;
	}, 10, 2 );
	add_filter( 'query', static function ( string $sql ) use ( $fixture ): string {
		if ( file_exists( "$fixture/fail-paid-write" ) && str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, '`customer_mail_to`' ) ) {
			return $sql . ' INVALID SQL';
		}
		return $sql;
	} );
	add_action( 'shutdown', static function () use ( $fixture ): void {
		foreach ( $GLOBALS['EZSQL_ERROR'] ?? array() as $error ) {
			if ( str_contains( $error['query'], '`customer_mail_to`' ) || str_contains( $error['query'], 'Snapshot 李' ) || str_contains( $error['query'], 'buyer@slow.example.test' ) ) {
				file_put_contents( "$fixture/sql-diagnostics", json_encode( $error ) . "\n", FILE_APPEND | LOCK_EX );
			}
		}
	} );
	add_filter( 'cron_request', static function ( array $request ) use ( $fixture ): array {
		file_put_contents( "$fixture/wakes", json_encode( $request ) . "\n", FILE_APPEND | LOCK_EX );
		return $request;
	} );
	add_filter( 'pre_http_request', static function ( $pre, array $args, string $url ) use ( $fixture ) {
		if ( str_starts_with( $url, WP_HOME . '/wp-cron.php?' ) && ! file_exists( "$fixture/fail-loopback" ) ) {
			return $pre;
		}
		return new WP_Error( 'fixture_http_denied' );
	}, 10, 3 );
	add_filter( 'pre_wp_mail', static function ( $pre ) use ( $fixture ) {
		global $phpmailer;
		static $capture_mailer = null;
		if ( null === $capture_mailer ) {
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
			require_once ABSPATH . WPINC . '/class-wp-phpmailer.php';
			$capture_mailer = new class( true ) extends WP_PHPMailer {
				public function send() { return $this->preSend(); }
			};
		}
		$phpmailer = $capture_mailer;
		return $pre;
	} );
	add_action( 'phpmailer_init', static function ( WP_PHPMailer $mailer ) use ( $fixture ): void {
		$to = $mailer->getToAddresses()[0][0];
		$record = array( $to, $mailer->Subject, $mailer->Body, $mailer->ContentType, $mailer->CharSet, $mailer->From );
		file_put_contents( "$fixture/mail", json_encode( $record, JSON_THROW_ON_ERROR ) . "\n", FILE_APPEND | LOCK_EX );
		if ( str_ends_with( $to, '@slow.example.test' ) ) {
			$deadline = microtime( true ) + 15;
			while ( ! file_exists( "$fixture/release-mail" ) && microtime( true ) < $deadline ) {
				usleep( 10_000 );
			}
		}
	} );
	add_action( 'plugins_loaded', static function () use ( $fixture ): void {
		if ( ! class_exists( PaymentNotificationCron::class ) ) {
			return;
		}
		add_action( PaymentNotificationCron::IMMEDIATE, static function () use ( $fixture ): void {
			if ( file_exists( "$fixture/probe-plain" ) ) {
				unlink( "$fixture/probe-plain" );
				wp_mail( 'plain@example.test', 'Plain fixture', 'Plain body' );
			}
		}, 20 );
	} );
	add_filter( 'wp_mail_from', static fn() => 'configured@example.test' );
	return;
}

$mode = $argv[1];
if ( 'install' === $mode ) {
	define( 'WP_INSTALLING', true );
}
if ( in_array( $mode, array( 'admin-localization', 'customer-detail' ), true ) ) {
	define( 'WP_ADMIN', true );
}
define( 'DISABLE_WP_CRON', true );
$_SERVER['REQUEST_METHOD'] = 'GET';
require "$fixture/wordpress/wp-load.php";
if ( 'install' === $mode ) {
	require ABSPATH . 'wp-admin/includes/upgrade.php';
	wp_install( 'Fixture Collection', 'fixture_admin', 'owner@example.test', false, '', bin2hex( random_bytes( 16 ) ) );
	update_option( 'cpt2collection_post_type', 'notify_product' );
	update_option( 'cpt2collection_stripe_secret_key', 'sk_test_' . bin2hex( random_bytes( 16 ) ) );
	update_option( 'cpt2collection_stripe_success_url', 'https://example.test/success' );
	update_option( 'cpt2collection_stripe_cancel_url', 'https://example.test/cancel' );
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$result = activate_plugin( 'cpt2collection/cpt2collection.php' );
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_code() );
	}
	echo json_encode( array( 'schema' => get_option( Database::OPTION_SCHEMA_VERSION ), 'plugin' => CPT2COLLECTION_VERSION, 'wordpress' => $wp_version ) );
	exit;
}

if ( 'schedule' === $mode ) {
	$events = array();
	foreach ( _get_cron_array() as $at => $hooks ) {
		foreach ( array( PaymentNotificationCron::SWEEP, PaymentNotificationCron::IMMEDIATE ) as $hook ) {
			foreach ( $hooks[ $hook ] ?? array() as $event ) {
				$events[] = array( $hook, $at, $event['schedule'], $event['args'] );
			}
		}
	}
	echo json_encode( $events );
	exit;
}
if ( 'hold' === $mode ) {
	set_transient( 'doing_cron', microtime( true ) );
	exit;
}
if ( 'fallback' === $mode ) {
	delete_transient( 'doing_cron' );
	wp_clear_scheduled_hook( PaymentNotificationCron::IMMEDIATE );
	wp_clear_scheduled_hook( PaymentNotificationCron::SWEEP );
	if ( ! wp_schedule_event( time(), 'hourly', PaymentNotificationCron::SWEEP ) ) {
		throw new RuntimeException( 'Could not make recurring sweep due in fixture.' );
	}
	exit;
}
if ( 'change-admin' === $mode ) {
	update_option( 'admin_email', $argv[2] );
	exit;
}
if ( 'change-locale' === $mode ) {
	update_user_meta( 1, 'locale', 'en_US' );
	$admin = wp_insert_user( array(
		'user_login' => 'catalog_fixture_admin',
		'user_pass' => bin2hex( random_bytes( 16 ) ),
		'user_email' => 'catalog-admin@example.test',
		'role' => 'administrator',
	) );
	if ( is_wp_error( $admin ) ) {
		throw new RuntimeException( $admin->get_error_code() );
	}
	update_option( 'catalog_fixture_admin', $admin );
	update_option( 'WPLANG', 'it_IT' );
	exit;
}
if ( 'customer-detail' === $mode ) {
	require_once ABSPATH . 'wp-admin/includes/admin.php';
	$user_id = 'admin' === $argv[3] ? 1 : 0;
	if ( 'subscriber' === $argv[3] ) {
		$user_id = wp_insert_user( array(
			'user_login' => 'detail_fixture_subscriber',
			'user_pass' => bin2hex( random_bytes( 16 ) ),
			'role' => 'subscriber',
		) );
		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( $user_id->get_error_code() );
		}
	}
	wp_set_current_user( $user_id );
	$repository = new RodolfoRizzo\Cpt2Collection\OrderAdminReadRepository( $wpdb, new TableNames( $wpdb ) );
	$page = new RodolfoRizzo\Cpt2Collection\Admin\OrderDetailPage( $repository );
	ob_start();
	$page->render( $argv[2] );
	echo json_encode( ob_get_clean() );
	exit;
}
if ( 'admin-localization' === $mode ) {
	require_once ABSPATH . 'wp-admin/includes/admin.php';
	wp_set_current_user( (int) get_option( 'catalog_fixture_admin' ) );
	$GLOBALS['hook_suffix'] = 'edit.php';
	$_REQUEST['post_type'] = 'notify_product';
	set_current_screen();
	do_action( 'admin_enqueue_scripts', 'edit.php' );
	set_current_screen( 'notify_product' );
	do_action( 'enqueue_block_editor_assets' );
	$_GET = array( 'page' => 'cpt2collection-orders', 'order_id' => $argv[2] );
	do_action( 'admin_enqueue_scripts', 'toplevel_page_cpt2collection-orders' );
	$orders = new RodolfoRizzo\Cpt2Collection\Admin\OrdersPage(
		new RodolfoRizzo\Cpt2Collection\OrderAdminReadRepository( $wpdb, new TableNames( $wpdb ) )
	);
	ob_start();
	$orders->render();
	$order_html = ob_get_clean();
	$_GET = array( 'page' => 'cpt2collection-commerce-reset' );
	do_action( 'admin_enqueue_scripts', 'cpt2collection_page_cpt2collection-commerce-reset' );
	$reset = new RodolfoRizzo\Cpt2Collection\Admin\CommerceResetPage( static fn() => null );
	ob_start();
	$reset->render();
	$reset_html = ob_get_clean();
	do_action( 'admin_init' );
	$settings = new RodolfoRizzo\Cpt2Collection\Admin\SettingsPage( new RodolfoRizzo\Cpt2Collection\PostTypes() );
	ob_start();
	$settings->render();
	$settings_html = ob_get_clean();
	$scripts = wp_scripts();
	$translations = array();
	foreach ( array( 'admin-stock', 'stock-quick-edit', 'order-deletion', 'commerce-reset' ) as $name ) {
		$handle = 'cpt2collection-' . $name;
		$translations[ $handle ] = array( $scripts->registered[ $handle ]->deps, $scripts->print_translations( $handle, false ) );
	}
	echo json_encode( array( 'order' => $order_html, 'reset' => $reset_html, 'settings' => $settings_html, 'translations' => $translations ) );
	exit;
}
if ( 'localization' === $mode ) {
	$assets = new RodolfoRizzo\Cpt2Collection\Frontend\Assets( new RodolfoRizzo\Cpt2Collection\PostTypes() );
	$assets->register_cart_assets();
	$assets->register_post_checkout_assets();
	$scripts = wp_scripts();
	$translations = array();
	foreach ( array( $assets::CART_SCRIPT_HANDLE, $assets::POST_CHECKOUT_HANDLE ) as $handle ) {
		$translations[ $handle ] = array( $scripts->registered[ $handle ]->deps, $scripts->print_translations( $handle, false ) );
	}
	$money = RodolfoRizzo\Cpt2Collection\Money::class;
	$cart = rest_do_request( '/cpt2collection/v1/cart' )->get_data();
	echo json_encode( array(
		'locale' => determine_locale(),
		'translations' => $translations,
		'frontend' => do_shortcode( '[cpt2collection_cart_count] [cpt2collection_cart] [cpt2collection_checkout_cancel]' ),
		'checkout' => $cart['checkout'],
		'plurals' => array_map(
			static fn( int $count ): string => sprintf( _n( '%d item in cart', '%d items in cart', $count, 'cpt2collection' ), $count ),
			array( 0, 1, 2 )
		),
		'amounts' => array(
			$money::format_paid_total( 200, 'EUR' ),
			$money::format_paid_total( 200, 'USD' ),
			$money::format_paid_total( 1200, 'JPY' ),
			$money::format_paid_total( PHP_INT_MAX, 'EUR' ),
		),
	) );
	exit;
}

$db = CommerceWpdb::connect( $wpdb );
$tables = new TableNames( $db );
if ( 'email-escaping' === $mode ) {
	add_filter( 'gettext_cpt2collection', static fn( $translated, $key ) =>
		'Order reference' === $key ? '<b>Riferimento</b>' : $translated, 10, 2 );
	add_filter( 'gettext_with_context_cpt2collection', static fn( $translated, $key, $context ) =>
		'email order reference line' === $context ? '%2$s < %1$s' : $translated, 10, 3 );
	$id = (int) $argv[2];
	$orders = new OrderRepository( $db, $tables );
	$mail = RodolfoRizzo\Cpt2Collection\PaymentNotificationEmail::render( 'customer', $orders->find( $id ), $orders->get_items( $id ) );
	$db->close();
	echo json_encode( $mail[1] );
	exit;
}
if ( 'money' === $mode ) {
	$id = (int) $argv[2];
	echo json_encode( $db->get_row(
		"SELECT o.currency, o.total_minor, s.amount_minor FROM {$tables->orders()} o
		INNER JOIN {$tables->payment_sessions()} s ON s.order_id=o.id WHERE o.id=$id"
	) );
	$db->close();
	exit;
}
if ( 'age' === $mode ) {
	$db->query( "UPDATE {$tables->orders()} SET paid_at = DATE_SUB(paid_at, INTERVAL 10 DAY) WHERE id=" . (int) $argv[2] );
	$db->close();
	exit;
}
if ( 'snapshot' === $mode ) {
	echo json_encode( $db->get_row( "SELECT customer_name, customer_email, customer_mail_to FROM {$tables->orders()} WHERE id=" . (int) $argv[2] ) );
	$db->close();
	exit;
}
if ( 'state' === $mode ) {
	echo json_encode( $db->get_row( "SELECT status, customer_mail_state, admin_mail_state FROM {$tables->orders()} WHERE id=" . (int) $argv[2] ) );
	$db->close();
	exit;
}

$tx = new TransactionManager( $db, $tables );
$orders = new OrderRepository( $db, $tables );
$reservations = new ReservationRepository( $db, $tables );
$clock = new UtcClock();
$now = $clock->now_mysql();
$amount = (int) ( $argv[3] ?? 215 );
$post = wp_insert_post( array( 'post_type' => 'notify_product', 'post_title' => 'Native & <print>', 'post_status' => 'publish' ) );
update_post_meta( $post, '_cpt2collection_stock_quantity', '20' );
if ( ! $tx->begin_commerce_shared() ) {
	throw new RuntimeException( 'Native fixture admission failed.' );
}
$context = $tx->commerce_context();
$carts = new CartRepository( $db, $tables );
$cart = $carts->create( hash( 'sha256', random_bytes( 32 ) ), $now, $now, $now, gmdate( 'Y-m-d H:i:s', time() + 86400 ) );
$order = $orders->create( $cart->id(), 'pending', 'EUR', $amount, $amount, $now, $now, hash( 'sha256', random_bytes( 32 ) ), 0, 0 );
$source = $carts->add_item_quantity_locked( $cart->id(), $post, 1 );
$item = $orders->insert_item( $order->id(), $post, 'Native & <print>', $amount, 1, $amount, $amount, 'EUR', $now, $source->id(), $source->content_version() );
if ( ! $reservations->create( $order->id(), $item->id(), $post, 1, 'active', $now, $now, gmdate( 'Y-m-d H:i:s', time() + 7200 ) ) || ! $tx->commit() ) {
	throw new RuntimeException( 'Native fixture reservation failed.' );
}

$client = new class implements StripeCheckoutClientInterface {
	public function create_checkout_session( array $parameters, array $options ): array {
		$reference = $parameters['client_reference_id'];
		return array(
			'id' => 'cs_' . $reference, 'url' => 'https://example.test/pay',
			'expires_at' => $parameters['expires_at'], 'payment_intent' => 'pi_' . $reference,
		);
	}
};
$provider = new StripeCheckoutProvider( $client, $clock );
$service = new PaymentSessionService(
	$orders, $reservations, new PaymentSessionRepository( $db, $tables ), $tx, new ReservationSetValidator(),
	$provider, $clock, null, new ProviderResponseEvidence( static fn() => CommerceWpdb::connect( $wpdb ) )
);
$result = $service->create( $order->id(), bin2hex( random_bytes( 16 ) ), $context );
if ( ! $result->is_success() ) {
	throw new RuntimeException( 'Native payment fixture ' . $result->get_code() . ' ' . $result->get_message() );
}
$reference = $result->get_reference();
$object = array(
	'object' => 'checkout.session', 'id' => $result->get_provider_session_id(),
	'client_reference_id' => $reference, 'payment_intent' => 'pi_' . $reference,
	'mode' => 'payment', 'currency' => 'eur', 'amount_total' => $amount, 'status' => 'complete',
	'payment_status' => 'paid', 'customer_details' => array( 'email' => $argv[2], 'name' => 'Snapshot 李𠮷 <script>alert(1)</script> & Co' ),
	'metadata' => array(
		'c2c_integration' => 'cpt2collection', 'c2c_installation_id' => $context->installation_id(),
		'c2c_epoch_identity' => $context->epoch_identity(), 'c2c_payment_session_reference' => $reference,
	),
);
$event = array( 'id' => 'evt_' . $reference, 'type' => 'checkout.session.completed', 'created' => time(), 'livemode' => false, 'data' => array( 'object' => $object ) );
echo json_encode( array( 'order' => $order->id(), 'event' => $event ) );
$db->close();
