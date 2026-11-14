<?php
/**
 * Database schema bootstrap.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

use wpdb;

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-commerce-foundation.php';
require_once __DIR__ . '/class-commerce-phase-two-migration.php';
require_once __DIR__ . '/class-commerce-phase-three-checkpoint-one-migration.php';
require_once __DIR__ . '/class-commerce-phase-four-checkpoint-one-migration.php';
require_once __DIR__ . '/class-commerce-evidence-generation-migration.php';
require_once __DIR__ . '/class-commerce-baseline-migration.php';
require_once __DIR__ . '/class-commerce-baseline-purge-migration.php';
require_once __DIR__ . '/class-commerce-baseline-purge-record.php';
require_once __DIR__ . '/class-commerce-order-source-index-migration.php';
require_once __DIR__ . '/class-commerce-claim-session-index-migration.php';
require_once __DIR__ . '/class-commerce-deleted-order-indexes-migration.php';
require_once __DIR__ . '/class-payment-notification-migration.php';
require_once __DIR__ . '/class-order-customer-migration.php';

/**
 * Installs and upgrades CPT2Collection custom database tables.
 */
final class Database {
	public const SCHEMA_VERSION        = '0.21.0';
	public const OPTION_SCHEMA_VERSION = 'cpt2collection_schema_version';

	public static function activate(): void {
		self::install_or_upgrade();
	}

	public static function maybe_upgrade(): void {
		$installed = (string) get_option( self::OPTION_SCHEMA_VERSION, '' );
		if ( '' === $installed || version_compare( $installed, self::SCHEMA_VERSION, '<' ) ) {
			self::install_or_upgrade();
		}
	}

	private static function install_or_upgrade(): void {
		global $wpdb;

		if ( ! $wpdb instanceof wpdb ) {
			return;
		}

		$installed = (string) get_option( self::OPTION_SCHEMA_VERSION, '' );
		if ( self::SCHEMA_VERSION === $installed ) { return; }
		if ( '0.20.0' === $installed ) {
			self::upgrade_order_customer( $wpdb instanceof CommerceConnection ? $wpdb : CommerceWpdb::connect( $wpdb ) );
			return;
		}
		if ( '0.19.0' === $installed ) {
			self::upgrade_payment_notifications( $wpdb instanceof CommerceConnection ? $wpdb : CommerceWpdb::connect( $wpdb ) );
			return;
		}
		if ( '0.18.0' === $installed ) {
			self::upgrade_deleted_order_indexes( $wpdb instanceof CommerceConnection ? $wpdb : CommerceWpdb::connect( $wpdb ) );
			return;
		}
		if ( '0.17.0' === $installed ) {
			self::upgrade_claim_session_index( $wpdb instanceof CommerceConnection ? $wpdb : CommerceWpdb::connect( $wpdb ) );
			return;
		}
		if ( '0.16.0' === $installed ) {
			self::upgrade_order_source_index( $wpdb instanceof CommerceConnection ? $wpdb : CommerceWpdb::connect( $wpdb ) );
			return;
		}
		if ( '0.15.0' === $installed ) {
			$db = $wpdb instanceof CommerceConnection ? $wpdb : CommerceWpdb::connect($wpdb);
			self::upgrade_purge_storage($db);
			return;
		}
		if ( '0.14.0' === $installed ) {
			$db = $wpdb instanceof CommerceConnection ? $wpdb : CommerceWpdb::connect($wpdb);
			if (CommerceBaselineMigration::run($db)) { update_option(self::OPTION_SCHEMA_VERSION,'0.15.0',false); self::upgrade_purge_storage($db); }
			return;
		}
		if ( '0.13.0' === $installed ) {
			$db = $wpdb instanceof CommerceConnection ? $wpdb : CommerceWpdb::connect( $wpdb );
			if ( '0.13.0' === (string) get_option( self::OPTION_SCHEMA_VERSION, '' ) && CommerceEvidenceGenerationMigration::run( $db ) ) {
					update_option(self::OPTION_SCHEMA_VERSION,'0.14.0',false);
					if ('0.14.0' === (string)get_option(self::OPTION_SCHEMA_VERSION,'') && CommerceBaselineMigration::run($db)) { update_option(self::OPTION_SCHEMA_VERSION,'0.15.0',false); self::upgrade_purge_storage($db); }
				}
			return;
		}
		if ( in_array( $installed, array( '0.10.0', '0.11.0', '0.12.0' ), true ) ) {
			$db = $wpdb instanceof CommerceConnection ? $wpdb : CommerceWpdb::connect( $wpdb );
			if ( '0.10.0' === $installed ) {
				if ( ! CommercePhaseTwoMigration::run( $db ) ) { return; }
				update_option( self::OPTION_SCHEMA_VERSION, '0.11.0', false );
			}
			if ( '0.12.0' !== $installed ) {
				if ( ! CommercePhaseThreeCheckpointOneMigration::run( $db ) ) { return; }
				update_option( self::OPTION_SCHEMA_VERSION, '0.12.0', false );
			}
			if ( CommercePhaseFourCheckpointOneMigration::run( $db ) ) {
				update_option( self::OPTION_SCHEMA_VERSION, '0.13.0', false );
				if ( '0.13.0' === (string) get_option( self::OPTION_SCHEMA_VERSION, '' ) && CommerceEvidenceGenerationMigration::run( $db ) ) {
					update_option(self::OPTION_SCHEMA_VERSION,'0.14.0',false);
					if ('0.14.0' === (string)get_option(self::OPTION_SCHEMA_VERSION,'') && CommerceBaselineMigration::run($db)) { update_option(self::OPTION_SCHEMA_VERSION,'0.15.0',false); self::upgrade_purge_storage($db); }
				}
			}
			return;
		}
		$tables          = new TableNames( $wpdb );
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = array(
			"CREATE TABLE {$tables->commerce_control()} (
				id TINYINT UNSIGNED NOT NULL,
				installation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				current_epoch_id BIGINT UNSIGNED NOT NULL,
				state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id)
			) ENGINE=InnoDB {$charset_collate};",
			"CREATE TABLE {$tables->commerce_epochs()} (
				id BIGINT UNSIGNED NOT NULL,
				identity CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				created_at DATETIME NOT NULL,
				retired_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY identity (identity)
			) ENGINE=InnoDB {$charset_collate};",
			"CREATE TABLE {$tables->carts()} (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					token_hash CHAR(64) NOT NULL,
					content_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
					checkout_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				last_activity_at DATETIME NOT NULL,
				expires_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY expires_at (expires_at),
				KEY last_activity_at (last_activity_at)
			) ENGINE=InnoDB {$charset_collate};",
			"CREATE TABLE {$tables->cart_items()} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				cart_id BIGINT UNSIGNED NOT NULL,
				post_id BIGINT UNSIGNED NOT NULL,
				quantity INT UNSIGNED NOT NULL DEFAULT 1,
				content_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY cart_product (cart_id, post_id),
				KEY cart_id (cart_id),
				KEY post_id (post_id)
			) ENGINE=InnoDB {$charset_collate};",
			"CREATE TABLE {$tables->orders()} (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					cart_id BIGINT UNSIGNED NULL,
					cart_revision BIGINT UNSIGNED NULL,
					checkout_generation BIGINT UNSIGNED NULL,
	                checkout_attempt_hash CHAR(64) NULL,
				cart_reconciliation_status VARCHAR(32) NULL,
				cart_reconciliation_code VARCHAR(64) NULL,
				cart_reconciliation_updated_at DATETIME NULL,
				status VARCHAR(32) NOT NULL DEFAULT 'pending',
				currency CHAR(3) NOT NULL,
				subtotal_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
				total_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				paid_at DATETIME NULL,
				cancelled_at DATETIME NULL,
				expired_at DATETIME NULL,
				failed_at DATETIME NULL,
				PRIMARY KEY  (id),
					KEY cart_id (cart_id),
					UNIQUE KEY cart_revision_generation (cart_id, cart_revision, checkout_generation),
                UNIQUE KEY cart_checkout_attempt (cart_id, checkout_attempt_hash),
				KEY status (status),
				KEY created_at (created_at),
				KEY updated_at (updated_at)
			) ENGINE=InnoDB {$charset_collate};",
			"CREATE TABLE {$tables->order_items()} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				order_id BIGINT UNSIGNED NOT NULL,
				post_id BIGINT UNSIGNED NOT NULL,
				source_cart_item_id BIGINT UNSIGNED NULL,
				source_cart_item_version BIGINT UNSIGNED NULL,
				item_name VARCHAR(255) NOT NULL,
				unit_price_minor BIGINT UNSIGNED NOT NULL,
				quantity INT UNSIGNED NOT NULL,
				line_subtotal_minor BIGINT UNSIGNED NOT NULL,
				line_total_minor BIGINT UNSIGNED NOT NULL,
				currency CHAR(3) NOT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY order_id (order_id),
				KEY post_id (post_id),
				KEY order_post (order_id, post_id)
			) ENGINE=InnoDB {$charset_collate};",
			"CREATE TABLE {$tables->reservations()} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				order_id BIGINT UNSIGNED NOT NULL,
				order_item_id BIGINT UNSIGNED NOT NULL,
				post_id BIGINT UNSIGNED NOT NULL,
				quantity INT UNSIGNED NOT NULL,
				status VARCHAR(32) NOT NULL DEFAULT 'active',
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				expires_at DATETIME NOT NULL,
				released_at DATETIME NULL,
				converted_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY order_item_id (order_item_id),
				KEY order_id (order_id),
				KEY post_id (post_id),
				KEY active_stock_lookup (post_id, status, expires_at),
				KEY status_expires (status, expires_at),
				KEY expires_at (expires_at)
				) ENGINE=InnoDB {$charset_collate};",
			"CREATE TABLE {$tables->payment_sessions()} (
					id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					reference CHAR(32) NOT NULL,
					order_id BIGINT UNSIGNED NOT NULL,
					provider VARCHAR(32) NOT NULL,
					provider_session_id VARCHAR(255) NULL,
                    provider_payment_id VARCHAR(255) NULL,
					provider_event_id VARCHAR(255) NULL,
					creation_key_hash CHAR(64) NOT NULL,
					status VARCHAR(32) NOT NULL DEFAULT 'creating',
					active_slot TINYINT UNSIGNED NULL,
					currency CHAR(3) NOT NULL,
					amount_minor BIGINT UNSIGNED NOT NULL,
					redirect_url TEXT NULL,
					created_at DATETIME NOT NULL,
					updated_at DATETIME NOT NULL,
					expires_at DATETIME NULL,
                    expired_at DATETIME NULL,
					completed_at DATETIME NULL,
					cancelled_at DATETIME NULL,
					failed_at DATETIME NULL,
                    payment_confirmed_at DATETIME NULL,
					confirmed_currency CHAR(3) NULL,
					confirmed_amount_minor BIGINT UNSIGNED NULL,
					reconciliation_required_at DATETIME NULL,
					reconciliation_code VARCHAR(64) NULL,
					reconciliation_event_id BIGINT UNSIGNED NULL,
					PRIMARY KEY  (id),
					UNIQUE KEY reference (reference),
					UNIQUE KEY order_creation_key (order_id, creation_key_hash),
					UNIQUE KEY provider_session (provider, provider_session_id),
                    UNIQUE KEY provider_payment (provider, provider_payment_id),
					UNIQUE KEY order_active_slot (order_id, active_slot),
					KEY order_id (order_id),
					KEY order_status (order_id, status),
					KEY status_expires (status, expires_at),
					KEY updated_at (updated_at)
				) ENGINE=InnoDB {$charset_collate};",
			"CREATE TABLE {$tables->payment_events()} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				provider VARCHAR(32) NOT NULL,
				provider_event_id VARCHAR(255) NOT NULL,
				event_type VARCHAR(128) NOT NULL,
				provider_created_at DATETIME NULL,
				livemode TINYINT(1) NOT NULL,
				payment_session_id BIGINT UNSIGNED NULL,
				provider_session_id VARCHAR(255) NULL,
				local_reference CHAR(32) NULL,
				provider_payment_id VARCHAR(255) NULL,
				currency CHAR(3) NULL,
				amount_minor BIGINT UNSIGNED NULL,
				payment_status VARCHAR(32) NULL,
				session_status VARCHAR(32) NULL,
				processing_status VARCHAR(32) NOT NULL,
				result_code VARCHAR(64) NULL,
				attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
				received_at DATETIME NOT NULL,
				last_attempt_at DATETIME NULL,
				processed_at DATETIME NULL,
				attention_at DATETIME NULL,
				error_code VARCHAR(64) NULL,
				error_detail TEXT NULL,
				payload_sha256 CHAR(64) NOT NULL,
				normalized_payload LONGTEXT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY provider_event (provider, provider_event_id),
				KEY provider_session (provider, provider_session_id),
				KEY payment_session_id (payment_session_id),
				KEY processing_status (processing_status),
				KEY status_received (processing_status, received_at),
				KEY attention_at (attention_at),
				KEY received_at (received_at)
			) ENGINE=InnoDB {$charset_collate};",
			str_replace( 'CREATE TABLE IF NOT EXISTS', 'CREATE TABLE', CommercePhaseThreeCheckpointOneMigration::ddl( $tables ) ) . " {$charset_collate};",
		);

		$installed = (string) get_option( self::OPTION_SCHEMA_VERSION, '' );
		if ( '' !== $installed && version_compare( $installed, self::SCHEMA_VERSION, '>=' ) ) { return; }
		foreach ( $sql as $statement ) {
			// A verified 0.9 installation receives foundation DDL only.
			if ( '0.9.0' !== $installed || str_contains( $statement, 'CREATE TABLE ' . $tables->commerce_control() . ' ' ) || str_contains( $statement, 'CREATE TABLE ' . $tables->commerce_epochs() . ' ' ) ) { dbDelta( $statement ); }
		}

		$post_type = (string) get_option( PostTypes::OPTION_POST_TYPE, '' );
		if ( self::tables_exist( $wpdb, $tables ) && self::phase_one_columns_exist( $wpdb, $tables ) && ( '' === $installed || version_compare( $installed, '0.7.1', '>=' ) || StockMetadataMigration::migrate( $wpdb, $post_type ) ) && CommerceFoundation::initialize( $wpdb instanceof CommerceConnection ? $wpdb : CommerceWpdb::connect( $wpdb ) ) ) {
			update_option( self::OPTION_SCHEMA_VERSION, '0.10.0', false );
			$db = $wpdb instanceof CommerceConnection ? $wpdb : CommerceWpdb::connect( $wpdb );
			if ( CommercePhaseTwoMigration::run( $db ) ) {
				update_option( self::OPTION_SCHEMA_VERSION, '0.11.0', false );
				if ( CommercePhaseThreeCheckpointOneMigration::run( $db ) ) {
					update_option( self::OPTION_SCHEMA_VERSION, '0.12.0', false );
					if ( CommercePhaseFourCheckpointOneMigration::run( $db ) ) {
						update_option( self::OPTION_SCHEMA_VERSION, '0.13.0', false );
						if ( '0.13.0' === (string) get_option( self::OPTION_SCHEMA_VERSION, '' ) && CommerceEvidenceGenerationMigration::run( $db ) ) {
					update_option(self::OPTION_SCHEMA_VERSION,'0.14.0',false);
					if ('0.14.0' === (string)get_option(self::OPTION_SCHEMA_VERSION,'') && CommerceBaselineMigration::run($db)) { update_option(self::OPTION_SCHEMA_VERSION,'0.15.0',false); self::upgrade_purge_storage($db); }
				}
					}
				}
			}
		}
	}

	private static function upgrade_purge_storage(wpdb $db): void {
		if ('0.15.0' === (string)get_option(self::OPTION_SCHEMA_VERSION,'') && CommerceBaselinePurgeMigration::run($db)) {
			update_option(self::OPTION_SCHEMA_VERSION,'0.16.0',false);
			self::upgrade_order_source_index($db);
		}
	}

	private static function upgrade_order_source_index( wpdb $db ): void {
		if ( '0.16.0' === (string) get_option( self::OPTION_SCHEMA_VERSION, '' ) && CommerceOrderSourceIndexMigration::run( $db ) ) {
			update_option( self::OPTION_SCHEMA_VERSION, '0.17.0', false );
			self::upgrade_claim_session_index( $db );
		}
	}

	private static function upgrade_claim_session_index( wpdb $db ): void {
		if ( '0.17.0' === (string) get_option( self::OPTION_SCHEMA_VERSION, '' ) && CommerceClaimSessionIndexMigration::run( $db ) ) {
			update_option( self::OPTION_SCHEMA_VERSION, '0.18.0', false );
			self::upgrade_deleted_order_indexes( $db );
		}
	}

	private static function upgrade_deleted_order_indexes( wpdb $db ): void {
		if ( '0.18.0' === (string) get_option( self::OPTION_SCHEMA_VERSION, '' ) && CommerceDeletedOrderIndexesMigration::run( $db ) ) {
			update_option( self::OPTION_SCHEMA_VERSION, '0.19.0', false );
			self::upgrade_payment_notifications( $db );
		}
	}

	private static function upgrade_payment_notifications( wpdb $db ): void {
		if ( '0.19.0' === (string) get_option( self::OPTION_SCHEMA_VERSION, '' ) && PaymentNotificationMigration::run( $db ) ) {
			update_option( self::OPTION_SCHEMA_VERSION, '0.20.0', false );
			self::upgrade_order_customer( $db );
		}
	}

	private static function upgrade_order_customer( wpdb $db ): void {
		if ( '0.20.0' === (string) get_option( self::OPTION_SCHEMA_VERSION, '' ) && OrderCustomerMigration::run( $db ) ) {
			update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false );
		}
	}

	/** Verifies the additive 0.8.0 to 0.9.0 migration before recording completion. */
	private static function phase_one_columns_exist( wpdb $wpdb, TableNames $tables ): bool {
		$required_columns = array(
			$tables->cart_items() => array( 'content_version' ),
			$tables->orders() => array( 'cart_reconciliation_status', 'cart_reconciliation_code', 'cart_reconciliation_updated_at' ),
			$tables->order_items() => array( 'source_cart_item_id', 'source_cart_item_version' ),
		);

		foreach ( $required_columns as $table_name => $required ) {
			$columns = $wpdb->get_col( 'SHOW COLUMNS FROM `' . str_replace( '`', '``', $table_name ) . '`' );
			if ( ! is_array( $columns ) || array_diff( $required, $columns ) ) {
				return false;
			}
		}

		return true;
	}

	private static function tables_exist( wpdb $wpdb, TableNames $tables ): bool {
		$expected_tables = array(
			$tables->commerce_control(),
			$tables->commerce_epochs(),
			$tables->carts(),
			$tables->cart_items(),
			$tables->orders(),
			$tables->order_items(),
			$tables->reservations(),
			$tables->payment_sessions(),
			$tables->payment_events(),
		);

		foreach ( $expected_tables as $table_name ) {
			$found = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
			);

			if ( $table_name !== $found ) {
				return false;
			}
		}

		return true;
	}
}
