<?php
/** Add the Order-owned outbox without changing existing commerce data. */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class PaymentNotificationMigration {
	private const COLUMNS = array(
		'notification_event_id' => 'bigint unsigned',
		'customer_mail_state'   => 'tinyint unsigned',
		'customer_mail_to'      => 'varchar(254) CHARACTER SET ascii COLLATE ascii_bin',
		'admin_mail_state'      => 'tinyint unsigned',
		'admin_mail_to'         => 'varchar(254) CHARACTER SET ascii COLLATE ascii_bin',
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
		$lock = 'c2c_mail_' . substr( hash( 'sha256', $db->get_var( 'SELECT DATABASE()' ) . ':' . $table ), 0, 55 );
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
		foreach ( self::COLUMNS as $name => $definition ) {
			$column = $columns[ $name ] ?? null;
			if ( null === $column ) {
				$changes[] = "ADD COLUMN $name $definition NULL DEFAULT NULL";
				continue;
			}
			$type = preg_replace( '/^(bigint|tinyint)\([0-9]+\)/', '$1', $column->Type );
			$is_address = str_ends_with( $name, '_to' );
			if ( $type !== ( $is_address ? 'varchar(254)' : $definition ) || 'YES' !== $column->Null
				|| null !== $column->Default || '' !== $column->Extra || $column->Collation !== ( $is_address ? 'ascii_bin' : null ) ) {
				return null;
			}
		}
		$indexes = $db->get_results( 'SHOW INDEX FROM ' . $table );
		if ( ! is_array( $indexes ) || $db->last_error !== '' ) {
			return null;
		}
		foreach ( array( 'customer_mail_state', 'admin_mail_state' ) as $state ) {
			$parts = array();
			foreach ( $indexes as $index ) {
				if ( $index->Key_name !== $state ) {
					continue;
				}
				if ( '1' !== (string) $index->Non_unique || null !== $index->Sub_part || 'BTREE' !== $index->Index_type
					|| 'A' !== $index->Collation || 'NO' !== ( $index->Ignored ?? 'NO' ) || 'YES' !== ( $index->Visible ?? 'YES' ) ) {
					return null;
				}
				$parts[ (int) $index->Seq_in_index ] = $index->Column_name;
			}
			ksort( $parts );
			if ( ! $parts ) {
				$changes[] = "ADD KEY $state (epoch_id, $state, id)";
			} elseif ( array( 1 => 'epoch_id', $state, 'id' ) !== $parts ) {
				return null;
			}
		}
		return $changes;
	}
}
