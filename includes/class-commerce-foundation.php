<?php
/** Idempotent migration-only foundation initialization. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-transaction-manager.php';

final class CommerceFoundation {
	/** Never called by commerce admission or when schema already records completion. */
	public static function initialize( \wpdb $db ): bool {
		if ( ! $db instanceof CommerceConnection ) { return false; }
		$tables = new TableNames( $db );
		$name = 'c2c_foundation_' . hash( 'sha256', (string) $db->get_var( 'SELECT DATABASE()' ) . ':' . $db->prefix );
		$name = substr( $name, 0, 64 );
		if ( '1' !== (string) $db->get_var( $db->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) ) ) { return false; }
		$tx = new TransactionManager( $db, $tables );
		try {
			if ( ! self::verify_structure( $db, $tables ) || ! $tx->begin() ) { return false; }
			$db->last_error = '';
			$controls = $db->get_results( 'SELECT id FROM ' . $tables->commerce_control() . ' ORDER BY id LIMIT 2 FOR UPDATE' );
			if ( '' !== $db->last_error || ! is_array( $controls ) ) { $tx->rollback(); return false; }
			$epochs = $db->get_results( 'SELECT id FROM ' . $tables->commerce_epochs() . ' ORDER BY id LIMIT 2 FOR UPDATE' );
			if ( '' !== $db->last_error || ! is_array( $epochs ) ) { $tx->rollback(); return false; }
			if ( array() === $controls && array() === $epochs ) {
				$now = gmdate( 'Y-m-d H:i:s' );
				$installation = bin2hex( random_bytes( 16 ) ); $epoch = bin2hex( random_bytes( 16 ) );
				if ( false === $db->insert( $tables->commerce_epochs(), array( 'id' => 1, 'identity' => $epoch, 'created_at' => $now, 'retired_at' => null ), array( '%d', '%s', '%s', null ) ) || false === $db->insert( $tables->commerce_control(), array( 'id' => 1, 'installation_id' => $installation, 'current_epoch_id' => 1, 'state' => 'ready', 'updated_at' => $now ), array( '%d', '%s', '%d', '%s', '%s' ) ) ) { $tx->rollback(); return false; }
			}
			if ( null === ( new CommerceControlRepository( $db, $tables ) )->read( 'FOR UPDATE' ) ) { $tx->rollback(); return false; }
			return $tx->commit();
		} catch ( \Throwable $error ) { $tx->rollback(); return false; }
		finally {
			// An uncertain connection is never probed or reused, including lock cleanup.
			if ( ! $tx->is_outcome_uncertain() ) { $db->get_var( $db->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
		}
	}

	public static function verify_structure( \wpdb $db, TableNames $tables ): bool {
		$required = array(
			$tables->commerce_control() => array( 'id', 'installation_id', 'current_epoch_id', 'state', 'updated_at' ),
			$tables->commerce_epochs() => array( 'id', 'identity', 'created_at', 'retired_at' ),
		);
		$db->last_error = '';
		$engines = $db->get_results( $db->prepare( 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s)', ...array_keys( $required ) ) );
		if ( '' !== $db->last_error || ! is_array( $engines ) || 2 !== count( $engines ) ) { return false; }
		foreach ( $engines as $row ) { if ( 'INNODB' !== strtoupper( $row->ENGINE ) ) { return false; } }
		foreach ( $required as $table => $columns ) {
			$quoted = '`' . str_replace( '`', '``', $table ) . '`';
			$found = $db->get_results( 'SHOW FULL COLUMNS FROM ' . $quoted );
			if ( '' !== $db->last_error || ! is_array( $found ) || array_diff( $columns, array_map( static fn( $r ) => $r->Field, $found ) ) ) { return false; }
			$types = array( 'id' => $table === $tables->commerce_control() ? 'tinyint unsigned' : 'bigint unsigned', 'installation_id' => 'char(32)', 'current_epoch_id' => 'bigint unsigned', 'state' => 'varchar(16)', 'updated_at' => 'datetime', 'identity' => 'char(32)', 'created_at' => 'datetime', 'retired_at' => 'datetime' );
			foreach ( $found as $column ) {
				if ( isset( $types[$column->Field] ) ) {
					$type = preg_replace( '/\b(tinyint|bigint)\(\d+\)/', '$1', strtolower( (string) $column->Type ) );
					if ( $types[$column->Field] !== $type || ( 'retired_at' === $column->Field ? 'YES' : 'NO' ) !== $column->Null ) { return false; }
				}
				if ( in_array( $column->Field, array( 'identity', 'installation_id', 'state' ), true ) && 'ascii_bin' !== $column->Collation ) { return false; }
			}
			$indexes = $db->get_results( 'SHOW INDEX FROM ' . $quoted );
			if ( '' !== $db->last_error || ! is_array( $indexes ) ) { return false; }
			$unique = array();
			foreach ( $indexes as $index ) { if ( 0 === (int) $index->Non_unique ) { $unique[$index->Key_name][(int) $index->Seq_in_index] = $index->Column_name; } }
			if ( array( 1 => 'id' ) !== ( $unique['PRIMARY'] ?? null ) || ( $table === $tables->commerce_epochs() && ! in_array( array( 1 => 'identity' ), $unique, true ) ) ) { return false; }
		}
		return true;
	}
}
