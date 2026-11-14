<?php
/** WordPress scheduling for the Order-owned paid notification outbox. */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class PaymentNotificationCron {
	public const IMMEDIATE = 'cpt2collection_paid_notification_wake';
	public const SWEEP = 'cpt2collection_paid_notification_sweep';

	public static function register(): void {
		$run = static function (): void {
			( new PaymentNotificationDispatcher( static function (): CommerceWpdb {
				global $wpdb;
				$db = CommerceWpdb::connect( $wpdb );
				$db->suppress_errors();
				return $db;
			}, static function (): mixed {
				global $wpdb;
				// A cron worker can outlive its cached alloptions snapshot during slow mail.
				$rows = $wpdb->query( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'admin_email' ) );
				return 1 === $rows ? $wpdb->get_var() : null;
			} ) )->dispatch();
		};
		add_action( self::IMMEDIATE, $run );
		add_action( self::SWEEP, $run );
		add_action( 'init', array( self::class, 'ensure_sweep' ) );
	}

	public static function ensure_sweep(): void {
		try {
			if ( Database::SCHEMA_VERSION === get_option( Database::OPTION_SCHEMA_VERSION ) && false === wp_next_scheduled( self::SWEEP ) ) {
				wp_schedule_event( intdiv( time(), 300 ) * 300 + 300, 'hourly', self::SWEEP );
			}
		} catch ( \Throwable $error ) {
			// The next bootstrap can try again.
		}
	}

	public static function wake(): void {
		try {
			if ( false === wp_next_scheduled( self::IMMEDIATE ) ) {
				wp_schedule_single_event( time(), self::IMMEDIATE );
			}
			// Alternate cron can execute inline on GET. Respect host-managed cron too.
			if ( ! ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON )
				&& ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
				spawn_cron();
			}
		} catch ( \Throwable $error ) {
			// Scheduling and the core loopback are best effort.
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::IMMEDIATE );
		wp_clear_scheduled_hook( self::SWEEP );
	}
}
