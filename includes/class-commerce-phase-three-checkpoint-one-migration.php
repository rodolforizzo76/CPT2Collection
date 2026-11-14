<?php
/** Restartable additive migration for durable order-deletion identity. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class CommercePhaseThreeCheckpointOneMigration {
	public static function run( \wpdb $db ): bool {
		if ( ! $db instanceof CommerceConnection ) { return false; }
		$tables = new TableNames( $db ); $tx = new TransactionManager( $db, $tables );
		$name = substr( 'c2c_phase3_cp1_' . hash( 'sha256', (string) $db->get_var( 'SELECT DATABASE()' ) . ':' . $db->prefix ), 0, 64 );
		if ( '1' !== (string) $db->get_var( $db->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) ) ) { return false; }
		try {
			if ( ! CommerceFoundation::verify_structure( $db, $tables ) || ! $tx->begin() ) { return false; }
			$control = self::lock_context( $db, $tables, array( 'ready', 'migrating_v12' ) );
			if ( null === $control ) { $tx->rollback(); return false; }
			if ( false === $db->query( "UPDATE {$tables->commerce_control()} SET state='migrating_v12' WHERE id=1" ) || ! $tx->commit() ) { return false; }
			if ( false === $db->query( self::ddl( $tables ) ) || ! self::verify_structure( $db, $tables ) ) { return false; }
			if ( ! $tx->begin() ) { return false; }
			$locked = self::lock_context( $db, $tables, array( 'migrating_v12' ) );
			if ( null === $locked || $locked['installation_id'] !== $control['installation_id'] || $locked['epoch_id'] !== $control['epoch_id'] || $locked['epoch_identity'] !== $control['epoch_identity'] || ! self::verify_structure( $db, $tables ) ) { $tx->rollback(); return false; }
			if ( false === $db->query( "UPDATE {$tables->commerce_control()} SET state='ready' WHERE id=1 AND state='migrating_v12'" ) ) { $tx->rollback(); return false; }
			return $tx->commit();
		} catch ( \Throwable $error ) {
			if ( $tx->is_active() ) { $tx->rollback(); }
			return false;
		} finally {
			if ( ! $tx->is_outcome_uncertain() ) { $db->get_var( $db->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
		}
	}

	public static function ddl( TableNames $tables ): string {
		return "CREATE TABLE IF NOT EXISTS {$tables->order_deletions()} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			installation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			epoch_id BIGINT UNSIGNED NOT NULL,
			epoch_identity CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			original_order_id BIGINT UNSIGNED NOT NULL,
			deleted_at DATETIME NOT NULL,
			cart_id BIGINT UNSIGNED NULL,
			checkout_attempt_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
			cart_revision BIGINT UNSIGNED NULL,
			checkout_generation BIGINT UNSIGNED NULL,
			checkout_disposition VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			cart_revision_at_deletion BIGINT UNSIGNED NULL,
			PRIMARY KEY (id),
			UNIQUE KEY owned_order (installation_id, epoch_id, original_order_id),
			UNIQUE KEY checkout_attempt (installation_id, epoch_id, cart_id, checkout_attempt_hash),
			UNIQUE KEY checkout_revision (installation_id, epoch_id, cart_id, cart_revision, checkout_generation),
			KEY epoch_cart (epoch_id, cart_id, checkout_disposition, cart_revision, cart_revision_at_deletion)
		) ENGINE=InnoDB";
	}

	public static function verify_structure( \wpdb $db, TableNames $tables ): bool {
		$engine = $db->get_var( $db->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $tables->order_deletions() ) );
		if ( ! is_string( $engine ) || 'INNODB' !== strtoupper( $engine ) ) { return false; }
		$columns = $db->get_results( 'SHOW FULL COLUMNS FROM ' . $tables->order_deletions() );
		if ( ! is_array( $columns ) ) { return false; }
		$fields = array_column( $columns, null, 'Field' );
		$required = array( 'id', 'installation_id', 'epoch_id', 'epoch_identity', 'original_order_id', 'deleted_at', 'cart_id', 'checkout_attempt_hash', 'cart_revision', 'checkout_generation', 'checkout_disposition', 'cart_revision_at_deletion' );
		if ( array_diff( $required, array_keys( $fields ) ) ) { return false; }
		$types = array( 'id' => 'bigint unsigned', 'installation_id' => 'char(32)', 'epoch_id' => 'bigint unsigned', 'epoch_identity' => 'char(32)', 'original_order_id' => 'bigint unsigned', 'deleted_at' => 'datetime', 'cart_id' => 'bigint unsigned', 'checkout_attempt_hash' => 'char(64)', 'cart_revision' => 'bigint unsigned', 'checkout_generation' => 'bigint unsigned', 'checkout_disposition' => 'varchar(16)', 'cart_revision_at_deletion' => 'bigint unsigned' );
		foreach ( $types as $field => $type ) { if ( $type !== preg_replace( '/bigint\(\d+\)/', 'bigint', strtolower( $fields[$field]->Type ) ) ) { return false; } }
		foreach ( array( 'installation_id', 'epoch_identity', 'checkout_attempt_hash', 'checkout_disposition' ) as $field ) { if ( 'ascii_bin' !== $fields[ $field ]->Collation ) { return false; } }
		$expected_null = array( 'cart_id', 'checkout_attempt_hash', 'cart_revision', 'checkout_generation', 'cart_revision_at_deletion' );
		foreach ( $required as $field ) { if ( ( in_array( $field, $expected_null, true ) ? 'YES' : 'NO' ) !== $fields[ $field ]->Null ) { return false; } }
		$indexes = $db->get_results( 'SHOW INDEX FROM ' . $tables->order_deletions() );
		if ( ! is_array( $indexes ) ) { return false; }
		$actual = array();
		foreach ( $indexes as $part ) { if ( null !== $part->Sub_part ) { return false; } $actual[ $part->Key_name ]['unique'] = 0 === (int) $part->Non_unique; $actual[ $part->Key_name ]['columns'][(int) $part->Seq_in_index] = $part->Column_name; }
		$expected = array(
			'PRIMARY' => array( true, array( 'id' ) ),
			'owned_order' => array( true, array( 'installation_id', 'epoch_id', 'original_order_id' ) ),
			'checkout_attempt' => array( true, array( 'installation_id', 'epoch_id', 'cart_id', 'checkout_attempt_hash' ) ),
			'checkout_revision' => array( true, array( 'installation_id', 'epoch_id', 'cart_id', 'cart_revision', 'checkout_generation' ) ),
			'epoch_cart' => array( false, array( 'epoch_id', 'cart_id', 'checkout_disposition', 'cart_revision', 'cart_revision_at_deletion' ) ),
		);
		foreach ( $expected as $name => [ $unique, $wanted ] ) {
			if ( ! isset( $actual[ $name ] ) || $actual[ $name ]['unique'] !== $unique ) { return false; }
			ksort( $actual[ $name ]['columns'] ); if ( array_values( $actual[ $name ]['columns'] ) !== $wanted ) { return false; }
		}
		return true;
	}

	/** @return array{installation_id:string,epoch_id:int,epoch_identity:string}|null */
	private static function lock_context( \wpdb $db, TableNames $tables, array $states ): ?array {
		$db->last_error = '';
		$controls = $db->get_results( 'SELECT * FROM ' . $tables->commerce_control() . ' ORDER BY id FOR UPDATE' );
		if ( '' !== $db->last_error || ! is_array( $controls ) || 1 !== count( $controls ) || 1 !== (int) $controls[0]->id || ! in_array( $controls[0]->state, $states, true ) || ! preg_match( '/\A[0-9a-f]{32}\z/', $controls[0]->installation_id ) || (int) $controls[0]->current_epoch_id < 1 ) { return null; }
		$epochs = $db->get_results( $db->prepare( 'SELECT * FROM ' . $tables->commerce_epochs() . ' WHERE id=%d FOR UPDATE', (int) $controls[0]->current_epoch_id ) );
		if ( '' !== $db->last_error || ! is_array( $epochs ) || 1 !== count( $epochs ) || null !== $epochs[0]->retired_at || ! preg_match( '/\A[0-9a-f]{32}\z/', $epochs[0]->identity ) ) { return null; }
		return array( 'installation_id' => $controls[0]->installation_id, 'epoch_id' => (int) $controls[0]->current_epoch_id, 'epoch_identity' => $epochs[0]->identity );
	}
}
