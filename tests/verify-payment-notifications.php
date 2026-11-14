<?php
/** Paid notification outbox, real Stripe SDK signatures and disposable MariaDB. No transport. */
declare( strict_types=1 );

require __DIR__ . '/support/payment-financial-continuation.php';
require dirname( __DIR__ ) . '/includes/admin/class-orders-page.php';

use RodolfoRizzo\Cpt2Collection\{Database, OrderCustomerMigration, PaymentNotificationCron, PaymentNotificationDispatcher, PaymentNotificationMigration, ProviderWebhookEvidence, StripeWebhookRestController, StripeWebhookVerifier, TableNames, TransactionManager};

function is_email( string $email ): string|false { return filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function get_bloginfo( string $field ): string { return 'Collection <West> & Co'; }
function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( string $text, string $domain ): string { return esc_html( $text ); }
function _x( string $text, string $context, string $domain ): string { return $text; }
function esc_html_x( string $text, string $context, string $domain ): string { return esc_html( _x( $text, $context, $domain ) ); }
function esc_url( string $value, array $schemes ): string { return in_array( parse_url( $value, PHP_URL_SCHEME ), $schemes, true ) ? esc_html( $value ) : ''; }
function admin_url( string $file ): string { return 'https://example.test/wp-admin/' . $file; }
function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
function wp_next_scheduled( string $hook ): int|false { return $GLOBALS['mail_cron'][ $hook ] ?? false; }
function wp_schedule_single_event( int $at, string $hook, array $args = array() ): bool {
	check( $args === array() && $at <= time(), 'immediate cron is due with empty arguments' );
	$GLOBALS['mail_cron'][ $hook ] = $at;
	return true;
}
function spawn_cron(): bool {
	check( $GLOBALS['webhook_db']->closed, 'operational connection closed before failed loopback request' );
	++$GLOBALS['mail_wakes'];
	return false;
}
function wp_mail( string $to, string $subject, string $body, array $headers ): bool {
	foreach ( $GLOBALS['mail_connections'] as $connection ) {
		if ( ! $connection->closed || TransactionManager::admitted_on( $connection ) ) {
			throw new LogicException( 'Mail called with an open commerce connection' );
		}
	}
	file_put_contents( $GLOBALS['mail_capture'], json_encode( compact( 'to', 'subject', 'body', 'headers' ), JSON_THROW_ON_ERROR ) . "\n", FILE_APPEND | LOCK_EX );
	if ( 'after-mail' === $GLOBALS['mail_mode'] ) {
		exit( 74 );
	}
	if ( 'over-budget' === $GLOBALS['mail_mode'] ) {
		usleep( 8_200_000 );
	}
	if ( 'blocked' === $GLOBALS['mail_mode'] ) {
		$deadline = microtime( true ) + 12;
		while ( ! file_exists( $GLOBALS['mail_capture'] . '.release' ) && microtime( true ) < $deadline ) {
			usleep( 10_000 );
		}
	}
	return ! str_starts_with( $to, 'fail@' );
}

class NotificationDb extends D2Db {
	public string $mode = '';
	private bool $outbox_write = false;

	public function query( string $query ): int|false {
		if ( str_starts_with( $query, 'UPDATE ' ) && ( str_contains( $query, 'notification_event_id' ) && str_contains( $query, '_mail_to' ) ) ) {
			$this->outbox_write = true;
		}
		if ( 'fail-reconciliation' === $this->mode && str_starts_with( $query, 'UPDATE ' )
			&& str_contains( $query, ' SET cart_reconciliation_status = ' ) ) {
			$this->last_error = 'Injected reconciliation write failure';
			return false;
		}
		if ( 'COMMIT' === $query && $this->outbox_write && in_array( $this->mode, array( 'unknown-before', 'unknown-after' ), true ) ) {
			if ( 'unknown-after' === $this->mode ) {
				parent::query( $query );
			}
			$this->last_error = 'Lost COMMIT acknowledgement';
			return false;
		}
		if ( 'COMMIT' === $query && $this->outbox_write && 'race' === $this->mode ) {
			usleep( 200_000 );
		}
		return parent::query( $query );
	}

	public function close(): void {
		parent::close();
		if ( $this->outbox_write && 'after-claim' === $this->mode ) {
			exit( 73 );
		}
	}
}

function mail_dispatch( string $prefix, string $mode = '' ): void {
	$GLOBALS['mail_mode'] = $mode;
	$GLOBALS['mail_connections'] = array();
	( new PaymentNotificationDispatcher( static function () use ( $prefix, $mode ): NotificationDb {
		$db = new NotificationDb( $prefix );
		$db->mode = $mode;
		$GLOBALS['mail_connections'][] = $db;
		return $db;
	}, static fn() => get_option( 'admin_email' ) ) )->dispatch();
}
function mail_deliver( string $prefix, array $payload, string $mode = '', string $signature = 'valid' ): WP_REST_Response {
	$db = new NotificationDb( $prefix );
	$db->mode = $mode;
	$GLOBALS['webhook_db'] = $db;
	$controller = new StripeWebhookRestController(
		new StripeWebhookVerifier( 'whsec_' . str_repeat( 'fixture', 3 ) ),
		webhook_for( $db, new ProviderWebhookEvidence( static fn() => new ResponseDb( $prefix ) ) ),
		static function () use ( $db, $mode ): void {
			$db->close();
			if ( 'before-wake' === $mode ) {
				exit( 72 );
			}
		}
	);
	try {
		return webhook_deliver( $controller, $payload, $signature );
	} finally {
		$db->close();
	}
}
function mail_state( wpdb $db, int $id ): ?object {
	return $db->get_row( 'SELECT status, notification_event_id, customer_mail_state, customer_mail_to, admin_mail_state, admin_mail_to, customer_name, customer_email FROM ' . ( new TableNames( $db ) )->orders() . ' WHERE id=' . $id );
}
function mail_messages(): array {
	clearstatcache( true, $GLOBALS['mail_capture'] );
	return array_map( static fn( $line ) => json_decode( $line, true, 512, JSON_THROW_ON_ERROR ), file( $GLOBALS['mail_capture'], FILE_IGNORE_NEW_LINES ) );
}
function mail_payload( wpdb $db, string $email = 'buyer@example.test' ): array {
	$f = validation_order( $db, 1 );
	webhook_activate( $db, $f );
	$payload = webhook_payload( $f, 'evt_' . $f['order'] );
	$payload['data']['object']['customer_details'] = array( 'email' => $email, 'name' => 'Élodie 李𠮷' );
	return array( $f, $payload );
}
function mail_worker( string $prefix, string $mode, ?array $payload = null ): array {
	$worker = proc_open(
		array( PHP_BINARY, __FILE__, $prefix, $GLOBALS['mail_capture'], $mode, json_encode( $payload, JSON_THROW_ON_ERROR ) ),
		array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
		$pipes
	);
	if ( ! is_resource( $worker ) ) {
		throw new RuntimeException( 'Cannot start notification actor' );
	}
	return array( $worker, $pipes );
}
function mail_join( array $worker, int $expected ): string {
	[ $process, $pipes ] = $worker;
	$out = stream_get_contents( $pipes[1] );
	$error = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	check( proc_close( $process ) === $expected && $error === '', 'independent actor exited as expected ' . $error );
	return $out;
}

$GLOBALS['baseline_options']['admin_email'] = 'owner@example.test';
$GLOBALS['mail_cron'] = array();
$GLOBALS['mail_wakes'] = 0;
if ( $argc > 1 ) {
	[ , $prefix, $GLOBALS['mail_capture'], $mode, $payload ] = $argv;
	if ( 'null' !== $payload ) {
		echo 'HTTP ' . mail_deliver( $prefix, json_decode( $payload, true, 512, JSON_THROW_ON_ERROR ), $mode )->get_status();
	} else {
		mail_dispatch( $prefix, $mode );
	}
	exit;
}

$prefix = 'c2c_notify_' . bin2hex( random_bytes( 4 ) ) . '_';
$db = new NotificationDb( $prefix );
$tables = new TableNames( $db );
$GLOBALS['mail_capture'] = tempnam( sys_get_temp_dir(), 'c2c_notify_' );
try {
	validation_schema( $db, false );
	discovery_finish( $db, validation_start( $db ) );
	[ $pending, $payload ] = mail_payload( $db );
	[ $old, $old_payload ] = mail_payload( $db );
	check( PaymentNotificationMigration::run( $db ) && OrderCustomerMigration::run( $db ) && mail_deliver( $prefix, $old_payload )->get_status() === 200, 'fixture creates coherent paid Order with verified provider evidence' );
	sql( $db, "ALTER TABLE {$tables->orders()} DROP INDEX customer_mail_state, DROP INDEX admin_mail_state,
		DROP COLUMN customer_name, DROP COLUMN customer_email, DROP COLUMN notification_event_id, DROP COLUMN customer_mail_state, DROP COLUMN customer_mail_to, DROP COLUMN admin_mail_state, DROP COLUMN admin_mail_to" );
	$GLOBALS['mail_cron'] = array();
	$GLOBALS['mail_wakes'] = 0;
	$before = unserialize( baseline_snapshot( $db ) );
	// Model DDL completed partially, then option acknowledgement lost after the remainder.
	sql( $db, "ALTER TABLE {$tables->orders()} ADD COLUMN notification_event_id BIGINT UNSIGNED NULL DEFAULT NULL" );
	$GLOBALS['wpdb'] = $db;
	$GLOBALS['baseline_options'][ Database::OPTION_SCHEMA_VERSION ] = '0.19.0';
	sql( $db, "ALTER TABLE {$tables->orders()} ADD COLUMN customer_mail_state VARCHAR(16) NULL" );
	Database::maybe_upgrade();
	check( get_option( Database::OPTION_SCHEMA_VERSION ) === '0.19.0' && ! in_array( 'admin_mail_state', $db->get_col( "SHOW COLUMNS FROM {$tables->orders()}" ), true ), 'malformed partial schema fails before any further DDL' );
	sql( $db, "ALTER TABLE {$tables->orders()} DROP COLUMN customer_mail_state" );
	$holder = new wpdb( $prefix );
	sql( $holder, 'START TRANSACTION' );
	$holder->get_var( "SELECT id FROM {$tables->orders()} LIMIT 1" );
	$started = hrtime( true );
	try {
		Database::maybe_upgrade();
		check( get_option( Database::OPTION_SCHEMA_VERSION ) === '0.19.0' && hrtime( true ) - $started < 8_000_000_000, 'held metadata lock bounds the migration wait and leaves schema acknowledgement pending' );
	} finally {
		$holder->query( 'ROLLBACK' );
		$holder->close();
	}
	$GLOBALS['baseline_lose_ack'] = true;
	Database::maybe_upgrade();
	check( get_option( Database::OPTION_SCHEMA_VERSION ) === '0.19.0', 'interrupted schema acknowledgement leaves 0.19.0 recorded' );
	$GLOBALS['baseline_lose_ack'] = false;
	Database::maybe_upgrade();
	check( get_option( Database::OPTION_SCHEMA_VERSION ) === '0.21.0', 'retry verifies full additive schemas and acknowledges 0.21.0' );
	$after = unserialize( baseline_snapshot( $db ) );
	foreach ( $after['orders'] as $row ) {
		foreach ( array( 'notification_event_id', 'customer_mail_state', 'customer_mail_to', 'admin_mail_state', 'admin_mail_to', 'customer_name', 'customer_email' ) as $field ) {
			check( null === $row->$field, 'existing Order outbox field remains NULL ' . $field );
			unset( $row->$field );
		}
	}
	check( serialize( $before ) === serialize( $after ), 'migration preserves populated Orders, baseline, epochs, reset history, evidence and stock' );
	mail_dispatch( $prefix );
	check( mail_messages() === array(), 'existing paid and pending Orders are not backfilled' );

	// Give the populated 0.20.0 fixture a paid Order with genuine notification evidence.
	[ $upgrade_order, $upgrade_event ] = mail_payload( $db, 'upgrade@example.test' );
	check( mail_deliver( $prefix, $upgrade_event )->get_status() === 200, 'upgrade fixture has a paid Order with pending mail' );
	sql( $db, "ALTER TABLE {$tables->orders()} DROP COLUMN customer_name, DROP COLUMN customer_email" );
	$GLOBALS['baseline_options'][ Database::OPTION_SCHEMA_VERSION ] = '0.20.0';
	$before = unserialize( baseline_snapshot( $db ) );
	$holder = new wpdb( $prefix );
	$lock = 'c2c_customer_' . substr( hash( 'sha256', $db->get_var( 'SELECT DATABASE()' ) . ':' . $tables->orders() ), 0, 51 );
	check( '1' === (string) $holder->get_var( $holder->prepare( 'SELECT GET_LOCK(%s,5)', $lock ) ), 'independent connection holds the customer migration advisory lock' );
	$started = hrtime( true );
	try {
		Database::maybe_upgrade();
		check( get_option( Database::OPTION_SCHEMA_VERSION ) === '0.20.0' && hrtime( true ) - $started < 8_000_000_000, 'advisory contention is bounded and leaves 0.20.0 recorded' );
	} finally {
		$holder->close();
	}
	sql( $db, "ALTER TABLE {$tables->orders()} ADD COLUMN customer_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL, ADD COLUMN customer_email VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL" );
	Database::maybe_upgrade();
	check( get_option( Database::OPTION_SCHEMA_VERSION ) === '0.20.0', 'wrong email collation prevents schema acknowledgement' );
	sql( $db, "ALTER TABLE {$tables->orders()} DROP COLUMN customer_email" );
	$holder = new wpdb( $prefix );
	sql( $holder, 'START TRANSACTION' );
	$holder->get_var( "SELECT id FROM {$tables->orders()} LIMIT 1" );
	$started = hrtime( true );
	try {
		Database::maybe_upgrade();
		check( get_option( Database::OPTION_SCHEMA_VERSION ) === '0.20.0' && hrtime( true ) - $started < 8_000_000_000, 'metadata contention is bounded and leaves partial schema restartable' );
	} finally {
		$holder->query( 'ROLLBACK' );
		$holder->close();
	}
	$GLOBALS['baseline_lose_ack'] = true;
	Database::maybe_upgrade();
	check( get_option( Database::OPTION_SCHEMA_VERSION ) === '0.20.0' && in_array( 'customer_email', $db->get_col( "SHOW COLUMNS FROM {$tables->orders()}" ), true ), 'interruption after DDL leaves 0.20.0 recorded' );
	$GLOBALS['baseline_lose_ack'] = false;
	Database::maybe_upgrade();
	check( get_option( Database::OPTION_SCHEMA_VERSION ) === '0.21.0', 'retry verifies both column definitions and acknowledges 0.21.0' );
	$after = unserialize( baseline_snapshot( $db ) );
	foreach ( $after['orders'] as $row ) {
		check( null === $row->customer_name && null === $row->customer_email, 'existing Order customer snapshot defaults to NULL' );
		unset( $row->customer_name, $row->customer_email );
	}
	check( serialize( $before ) === serialize( $after ), 'customer migration preserves populated commerce, pending mail, retained evidence, and stock' );
	check( mail_deliver( $prefix, $upgrade_event )->get_status() === 200 && mail_state( $db, $upgrade_order['order'] )->customer_email === null, 'historical paid replay leaves the migrated snapshot empty' );
	webhook_deleted( $db, $upgrade_order );
	$GLOBALS['mail_cron'] = array();
	$GLOBALS['mail_wakes'] = 0;


	check( mail_deliver( $prefix, $payload, signature: 'invalid' )->get_status() === 400, 'invalid Stripe signature creates no intent' );
	$unpaid = $payload;
	$unpaid['id'] .= '_unpaid';
	$unpaid['data']['object']['payment_status'] = 'unpaid';
	check( mail_deliver( $prefix, $unpaid )->get_status() === 200 && mail_state( $db, $pending['order'] )->notification_event_id === null, 'unpaid Checkout Event creates no intent' );
	check( mail_deliver( $prefix, $payload )->get_status() === 200 && $GLOBALS['mail_wakes'] === 1 && mail_messages() === array(), 'paid webhook succeeds with failed loopback and zero inline mail' );
	$won = mail_state( $db, $pending['order'] );
	$distinct = $payload;
	$distinct['id'] .= '_distinct';
	$distinct['data']['object']['customer_details'] = array( 'email' => 'other@example.test', 'name' => 'Different buyer' );
	check( mail_deliver( $prefix, $payload )->get_status() === 200 && mail_deliver( $prefix, $distinct )->get_status() === 200, 'duplicate and distinct paid Events preserve webhook success' );
	check( $won == mail_state( $db, $pending['order'] ) && $won->customer_mail_to === 'buyer@example.test' && $won->customer_email === 'buyer@example.test' && $won->customer_name === 'Élodie 李𠮷' && $GLOBALS['mail_wakes'] === 1, 'only the winning Event supplies the snapshot, recipient and wake' );
	$distinct['id'] .= '_missing';
	unset( $distinct['data']['object']['customer_details'] );
	check( mail_deliver( $prefix, $distinct )->get_status() === 200 && mail_state( $db, $pending['order'] ) == $won, 'later paid Event without contact preserves the winning snapshot' );
	$receipt = $db->get_var( "SELECT normalized_payload FROM {$tables->payment_events()} WHERE id=" . $won->notification_event_id );
	check( ! str_contains( $receipt, 'buyer@example.test' ) && ! str_contains( $receipt, 'customer_details' ), 'customer snapshot is excluded from durable receipt evidence' );
	sql( $db, "UPDATE {$tables->orders()} SET notification_event_id=999999 WHERE id=" . $pending['order'] );
	mail_dispatch( $prefix );
	check( mail_messages() === array(), 'malformed paid intent with an absent winning Event cannot authorize mail' );
	sql( $db, "UPDATE {$tables->orders()} SET notification_event_id=" . (int) $won->notification_event_id . ' WHERE id=' . $pending['order'] );
	mail_dispatch( $prefix );
	mail_dispatch( $prefix );
	[ $buyer, $admin ] = mail_messages();
	check( count( mail_messages() ) === 2 && $buyer['to'] === 'buyer@example.test' && $admin['to'] === 'owner@example.test', 'fallback submits each eligible role once' );
	check( str_contains( $buyer['body'], 'Collection &lt;West&gt; &amp; Co' ) && str_contains( $buyer['body'], 'Snapshot 0' ) && str_contains( $buyer['body'], '€1.00' ) && ! str_contains( $buyer['body'], 'EUR' ) && str_contains( $buyer['body'], 'C2C-' . $pending['order'] ), 'HTML includes escaped snapshot data and paid total' );
	check( ! str_contains( $buyer['body'], 'wp-admin' ) && str_contains( $admin['body'], 'wp-admin/admin.php?page=cpt2collection-orders&amp;order_id=' ) && $admin['headers'] === array( 'Content-Type: text/html; charset=UTF-8' ), 'protected detail link and per-message HTML header' );
	$observed = mail_state( $db, $pending['order'] );
	check( 4 === (int) $observed->admin_mail_state && 4 === (int) $observed->customer_mail_state && $observed->customer_mail_to === null && $observed->admin_mail_to === null, 'observed success and address erasure persist' );
	check( $observed->customer_email === $won->customer_email && $observed->customer_name === $won->customer_name, 'mail consumption preserves both snapshot fields' );

	[ $late, $late_payload ] = mail_payload( $db, "bad@example.test\r\nBcc:x@example.test" );
	$expires = $late_payload;
	$expires['id'] .= '_expires';
	$expires['type'] = 'checkout.session.expired';
	$expires['data']['object']['status'] = 'expired';
	$expires['data']['object']['payment_status'] = 'unpaid';
	$expires['data']['object']['payment_intent'] = null;
	check( mail_deliver( $prefix, $expires )->get_status() === 200 && mail_state( $db, $late['order'] )->status === 'expired', 'verified expiration creates no paid intent' );
	$late_payload['type'] = 'checkout.session.async_payment_succeeded';
	check( mail_deliver( $prefix, $late_payload )->get_status() === 200 && (int) mail_state( $db, $late['order'] )->customer_mail_state === 2, 'late success persists independently skipped invalid customer intent' );
	check( mail_state( $db, $late['order'] )->customer_email === null && mail_state( $db, $late['order'] )->customer_name === 'Élodie 李𠮷', 'invalid email preserves independently valid name' );
	mail_dispatch( $prefix );
	check( count( mail_messages() ) === 3 && (int) mail_state( $db, $late['order'] )->admin_mail_state === 4, 'invalid customer address leaves admin eligible' );

	[ $missing, $no_contact ] = mail_payload( $db );
	unset( $no_contact['data']['object']['customer_details'] );
	$GLOBALS['baseline_options']['admin_email'] = array( 'badly configured' );
	check( mail_deliver( $prefix, $no_contact )->get_status() === 200, 'missing customer and invalid administrator contact preserve payment' );
	$skipped = mail_state( $db, $missing['order'] );
	check( $skipped->customer_name === null && $skipped->customer_email === null, 'missing customer details are stored as NULL' );
	foreach ( array(
		array( 'name' => array( 'wrong type' ), 'email' => 'plain-address' ),
		array( 'name' => '　 ', 'email' => 'buyer..name@example.test' ),
		array( 'name' => str_repeat( '界', 256 ), 'email' => str_repeat( 'a', 245 ) . '@example.test' ),
		array( 'name' => "Malformed\nname", 'email' => array( 'wrong type' ) ),
		'wrong details type',
	) as $details ) {
		[ $invalid, $event ] = mail_payload( $db );
		$event['data']['object']['customer_details'] = $details;
		check( mail_deliver( $prefix, $event )->get_status() === 200, 'malformed or oversized snapshot does not prevent payment' );
		$state = mail_state( $db, $invalid['order'] );
		check( $state->status === 'paid' && $state->customer_name === null && $state->customer_email === null, 'invalid snapshot values are stored as NULL' );
	}
	mail_dispatch( $prefix );
	check( (int) $skipped->customer_mail_state === 2 && (int) $skipped->admin_mail_state === 2 && count( mail_messages() ) === 3, 'missing and invalid recipients are separately skipped' );
	$GLOBALS['baseline_options']['admin_email'] = 'owner@example.test';

	[ $reconcile, $event ] = mail_payload( $db );
	$wakes = $GLOBALS['mail_wakes'];
	check( mail_deliver( $prefix, $event, 'fail-reconciliation' )->get_status() === 500 && $GLOBALS['mail_wakes'] === $wakes + 1, 'confirmed paid COMMIT signals dispatch while retryable reconciliation preserves HTTP 500' );
	check( 'paid' === mail_state( $db, $reconcile['order'] )->status && mail_deliver( $prefix, $event )->get_status() === 200, 'webhook retry completes reconciliation without changing the paid Order' );
	mail_dispatch( $prefix );

	foreach ( array( 'unknown-before', 'unknown-after' ) as $mode ) {
		[ $order, $event ] = mail_payload( $db );
		$wakes = $GLOBALS['mail_wakes'];
		check( mail_deliver( $prefix, $event, $mode )->get_status() === 500 && $GLOBALS['mail_wakes'] === $wakes, 'unknown paid COMMIT preserves HTTP 500 and emits no wake ' . $mode );
		check( ( 'unknown-after' === $mode ) === ( 'paid' === mail_state( $db, $order['order'] )->status ), 'fresh reader sees physical sale outcome ' . $mode );
		$expected = 'unknown-after' === $mode ? 'Élodie 李𠮷' : null;
		check( mail_state( $db, $order['order'] )->customer_name === $expected, 'snapshot follows actual commit outcome ' . $mode );
		check( mail_deliver( $prefix, $event )->get_status() === 200, 'fresh webhook recovers or acknowledges ' . $mode );
		check( mail_state( $db, $order['order'] )->customer_name === 'Élodie 李𠮷' && mail_state( $db, $order['order'] )->customer_email === 'buyer@example.test', 'fresh continuation observes the winning snapshot ' . $mode );
		$count = count( mail_messages() );
		mail_dispatch( $prefix, $mode );
		check( count( mail_messages() ) === $count, 'unknown claim authorizes zero mail calls ' . $mode );
		mail_dispatch( $prefix );
		check( count( mail_messages() ) === $count + ( 'unknown-after' === $mode ? 1 : 2 ), 'fallback obeys physical claim outcome ' . $mode );
	}

	[ $dead, $event ] = mail_payload( $db );
	mail_join( mail_worker( $prefix, 'before-wake', $event ), 72 );
	check( (int) mail_state( $db, $dead['order'] )->customer_mail_state === 1, 'process death before wake leaves committed pending work' );
	mail_join( mail_worker( $prefix, 'after-claim' ), 73 );
	check( (int) mail_state( $db, $dead['order'] )->customer_mail_state === 3, 'process death after claim leaves consumed permission' );
	$count = count( mail_messages() );
	mail_join( mail_worker( $prefix, 'after-mail' ), 74 );
	mail_dispatch( $prefix );
	check( count( mail_messages() ) === $count + 1 && (int) mail_state( $db, $dead['order'] )->admin_mail_state === 3, 'process death after mail causes no second invocation' );

	[ $race, $event ] = mail_payload( $db, 'fail@example.test' );
	$other = $event;
	$other['id'] .= '_other';
	$other['data']['object']['customer_details'] = array( 'name' => 'Concurrent buyer', 'email' => 'fail@other.example.test' );
	$stock_before = d3_stock( $db )[0];
	$a = mail_worker( $prefix, '', $event );
	$b = mail_worker( $prefix, '', $other );
	$responses = array( mail_join( $a, 0 ), mail_join( $b, 0 ) );
	check( in_array( true, array_map( static fn( $out ) => str_contains( $out, 'HTTP 200' ), $responses ), true ) && (int) mail_state( $db, $race['order'] )->customer_mail_state === 1, 'independent webhook processes produce one pending state' );
	$race_state = mail_state( $db, $race['order'] );
	$winner = $db->get_var( "SELECT provider_event_id FROM {$tables->payment_events()} WHERE id=" . $race_state->notification_event_id );
	check( in_array( $winner, array( $event['id'], $other['id'] ), true ) && $race_state->customer_name === ( $winner === $event['id'] ? 'Élodie 李𠮷' : 'Concurrent buyer' ) && $race_state->customer_email === ( $winner === $event['id'] ? 'fail@example.test' : 'fail@other.example.test' ), 'concurrent sale snapshot matches the sole committed winner' );
	check( d3_stock( $db )[0] === $stock_before - 1, 'concurrent payment converts physical stock exactly once' );
	$count = count( mail_messages() );
	$a = mail_worker( $prefix, 'race' );
	$b = mail_worker( $prefix, 'race' );
	mail_join( $a, 0 );
	mail_join( $b, 0 );
	mail_dispatch( $prefix );
	check( count( mail_messages() ) === $count + 2 && (int) mail_state( $db, $race['order'] )->customer_mail_state === 5, 'competing dispatchers invoke each role once and persist failed mail (' . count( mail_messages() ) . '/' . ($count + 2) . ', ' . json_encode( mail_state( $db, $race['order'] ) ) . ')' );

	[ $deleted, $event ] = mail_payload( $db, 'erase-deleted@example.test' );
	$event['data']['object']['customer_details']['name'] = 'Deleted fixture contact';
	check( mail_deliver( $prefix, $event )->get_status() === 200, 'paid before blocked transport race' );
	$count = count( mail_messages() );
	$actor = mail_worker( $prefix, 'blocked' );
	$deadline = microtime( true ) + 6;
	while ( count( mail_messages() ) === $count && microtime( true ) < $deadline ) {
		usleep( 10_000 );
	}
	check( count( mail_messages() ) === $count + 1, 'worker blocked outside commerce during captured mail transport' );
	webhook_deleted( $db, $deleted );
	touch( $GLOBALS['mail_capture'] . '.release' );
	mail_join( $actor, 0 );
	check( mail_state( $db, $deleted['order'] ) === null && count( mail_messages() ) === $count + 1, 'deletion while mail is blocked removes addresses and prevents any new claim' );
	unlink( $GLOBALS['mail_capture'] . '.release' );
	check( mail_deliver( $prefix, $event )->get_status() === 200 && mail_state( $db, $deleted['order'] ) === null, 'deleted paid Order replay cannot restore its customer snapshot' );
	$retained = baseline_snapshot( $db );
	check( ! str_contains( $retained, 'erase-deleted@example.test' ) && ! str_contains( $retained, 'Deleted fixture contact' ), 'deletion and replay leave neither contact value in commerce evidence' );

	baseline_cleanup( $db );
	$db->close();
	$prefix .= 'reset_';
	$db = new NotificationDb( $prefix );
	validation_schema( $db );
	for ( $i = 0; $i < 6; ++$i ) {
		[ , $event ] = mail_payload( $db );
		check( mail_deliver( $prefix, $event )->get_status() === 200, 'batch fixture paid Order ' . $i );
	}
	$count = count( mail_messages() );
	mail_dispatch( $prefix );
	check( count( mail_messages() ) === $count + 10, 'one dispatcher stops after ten claims' );
	mail_dispatch( $prefix );
	check( count( mail_messages() ) === $count + 12, 'later dispatch submits the remainder exactly once' );

	[ $reset, $event ] = mail_payload( $db, 'erase-reset@example.test' );
	$event['data']['object']['customer_details']['name'] = 'Reset fixture contact';
	check( mail_deliver( $prefix, $event )->get_status() === 200, 'paid before reset fence' );
	$count = count( mail_messages() );
	mail_join( mail_worker( $prefix, 'over-budget' ), 0 );
	check( count( mail_messages() ) === $count + 1 && (int) mail_state( $db, $reset['order'] )->admin_mail_state === 1, 'runtime budget allows slow in-flight mail to finish and stops before another claim' );
	$operation = validation_start( $db );
	$count = count( mail_messages() );
	mail_join( mail_worker( $prefix, '' ), 0 );
	check( count( mail_messages() ) === $count && (int) mail_state( $db, $reset['order'] )->admin_mail_state === 1, 'independent dispatcher fails admission during reset' );
	$checked = validation_pass( $db, $operation, 100 );
	check( $checked->status() !== 'blocked', 'paid Order with pending mail passes reset validation ' . $checked->error_code() );
	discovery_finish( $db, $checked );
	check( mail_deliver( $prefix, $event )->get_status() === 200 && mail_state( $db, $reset['order'] ) === null, 'completed reset erases outbox and snapshot and retired-epoch replay is harmless' );
	$retained = baseline_snapshot( $db );
	check( ! str_contains( $retained, 'erase-reset@example.test' ) && ! str_contains( $retained, 'Reset fixture contact' ), 'reset and replay leave neither contact value in commerce evidence' );
	mail_dispatch( $prefix );
	check( count( mail_messages() ) === $count, 'retired epoch has no claimable addresses' );
} finally {
	baseline_cleanup( $db );
	@unlink( $GLOBALS['mail_capture'] . '.release' );
	unlink( $GLOBALS['mail_capture'] );
}
