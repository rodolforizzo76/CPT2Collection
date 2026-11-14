<?php
/** Add the paid Order customer snapshot without backfilling existing Orders. */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class OrderCustomerMigration {
	public const SCHEMA_VERSION = '0.21.0';

	private const COLUMNS = array(
		'customer_name'  => array( 'varchar(255)', 'utf8mb4', 'utf8mb4_bin' ),
		'customer_email' => array( 'varchar(254)', 'ascii', 'ascii_bin' ),
	);

	public static function run( \wpdb $db ): bool {
		if ( ! $db instanceof CommerceConnection || ! $db->transaction_connection_usable() ) {
			return false;
		}
		if ( $db instanceof CommerceSessionConnection && ! $db->pin_session() ) {
			return false;
		}
		// A queued ALTER can block later Order reads while waiting for metadata.
		if ( false === $db->query( 'SET SESSION lock_wait_timeout = 5' ) ) {
			return false;
		}
		$table = ( new TableNames( $db ) )->orders();
		$lock = 'c2c_customer_' . substr( hash( 'sha256', $db->get_var( 'SELECT DATABASE()' ) . ':' . $table ), 0, 51 );
		if ( '1' !== (string) $db->get_var( $db->prepare( 'SELECT GET_LOCK(%s,5)', $lock ) ) ) {
			return false;
		}
		try {
			$changes = self::missing_definitions( $db, $table );
			if ( null === $changes ) {
				return false;
			}
			if ( $changes && false === $db->query( 'ALTER TABLE ' . $table . ' ' . implode( ', ', $changes ) ) ) {
				return false;
			}
			return array() === self::missing_definitions( $db, $table );
		} catch ( \Throwable $error ) {
			return false;
		} finally {
			if ( $db->transaction_connection_usable() ) {
				$db->get_var( $db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}
	}

	private static function missing_definitions( \wpdb $db, string $table ): ?array {
		$engine = $db->get_var( $db->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
		if ( 'INNODB' !== strtoupper( (string) $engine ) || $db->last_error !== '' ) {
			return null;
		}
		$columns = $db->get_results( 'SHOW FULL COLUMNS FROM ' . $table );
		if ( ! is_array( $columns ) || $db->last_error !== '' ) {
			return null;
		}
		$columns = array_column( $columns, null, 'Field' );
		$changes = array();
		foreach ( self::COLUMNS as $name => [ $type, $charset, $collation ] ) {
			$column = $columns[ $name ] ?? null;
			if ( null === $column ) {
				$changes[] = "ADD COLUMN $name $type CHARACTER SET $charset COLLATE $collation NULL DEFAULT NULL";
				continue;
			}
			if ( $type !== $column->Type || 'YES' !== $column->Null || null !== $column->Default
				|| '' !== $column->Extra || $collation !== $column->Collation ) {
				return null;
			}
		}
		return $changes;
	}
}
