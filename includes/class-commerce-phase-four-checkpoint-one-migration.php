<?php
/** Restartable additive migration for durable reset-operation storage. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class CommercePhaseFourCheckpointOneMigration {
	public static function run( \wpdb $db ): bool {
		if ( ! $db instanceof CommerceConnection ) { return false; }
		$tables = new TableNames( $db ); $tx = new TransactionManager( $db, $tables );
		$name = substr( 'c2c_phase4_cp1_' . hash( 'sha256', (string) $db->get_var( 'SELECT DATABASE()' ) . ':' . $db->prefix ), 0, 64 );
		if ( '1' !== (string) $db->get_var( $db->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) ) ) { return false; }
		try {
			if ( ! CommerceFoundation::verify_structure( $db, $tables ) || ! $tx->begin() ) { return false; }
			$control = self::lock_context( $db, $tables, array( 'ready', 'migrating_v13' ) );
			if ( null === $control || ! CommercePhaseTwoMigration::verify_structure( $db, $tables ) || ! CommercePhaseThreeCheckpointOneMigration::verify_structure( $db, $tables ) ) { $tx->rollback(); return false; }
			if ( false === $db->query( "UPDATE {$tables->commerce_control()} SET state='migrating_v13' WHERE id=1" ) || ! $tx->commit() ) { return false; }
			if ( false === $db->query( self::ddl( $tables ) . ' ' . $db->get_charset_collate() ) || ! self::verify_structure( $db, $tables ) ) { return false; }
			if ( ! $tx->begin() ) { return false; }
			$locked = self::lock_context( $db, $tables, array( 'migrating_v13' ) );
			if ( null === $locked || $locked['installation_id'] !== $control['installation_id'] || $locked['epoch_id'] !== $control['epoch_id'] || $locked['epoch_identity'] !== $control['epoch_identity'] || ! self::verify_structure( $db, $tables ) ) { $tx->rollback(); return false; }
			if ( false === $db->query( "UPDATE {$tables->commerce_control()} SET state='ready' WHERE id=1 AND state='migrating_v13'" ) ) { $tx->rollback(); return false; }
			return $tx->commit();
		} catch ( \Throwable $error ) {
			if ( $tx->is_active() ) { $tx->rollback(); }
			return false;
		} finally {
			if ( $tx->is_active() && ! $tx->is_outcome_uncertain() ) { $tx->rollback(); }
			if ( ! $tx->is_outcome_uncertain() ) { $db->get_var( $db->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
		}
	}

	public static function ddl( TableNames $tables ): string {
		return "CREATE TABLE IF NOT EXISTS {$tables->commerce_resets()} (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`operation_identity` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`installation_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`source_epoch_id` BIGINT UNSIGNED NOT NULL,
			`source_epoch_identity` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`target_epoch_identity` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`target_epoch_id` BIGINT UNSIGNED NULL,
			`active_slot` TINYINT UNSIGNED NULL,
			`status` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`phase` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
			`checkpoint` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
			`cursor` BIGINT UNSIGNED NOT NULL DEFAULT 0,
			`revision` BIGINT UNSIGNED NOT NULL DEFAULT 0,
			`requested_by` BIGINT UNSIGNED NULL,
			`error_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
			`started_at` DATETIME NOT NULL,
			`updated_at` DATETIME NOT NULL,
			`completed_at` DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY operation_identity (operation_identity),
			UNIQUE KEY source_epoch (installation_id, source_epoch_id),
			UNIQUE KEY target_epoch_identity (target_epoch_identity),
			UNIQUE KEY active_slot (active_slot),
			UNIQUE KEY target_epoch_id (target_epoch_id)
		) ENGINE=InnoDB";
	}

	/** Fail closed on partial structures; no ALTER or evidence repair is safe here. */
	public static function verify_structure( \wpdb $db, TableNames $tables, array $generation_columns = array() ): bool {
		$db->last_error = '';
		$engine = $db->get_var( $db->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $tables->commerce_resets() ) );
		if ( '' !== $db->last_error || ! is_string( $engine ) || 'INNODB' !== strtoupper( $engine ) ) { return false; }
		$columns = $db->get_results( 'SHOW FULL COLUMNS FROM ' . $tables->commerce_resets() );
		if ( '' !== $db->last_error || ! is_array( $columns ) ) { return false; }
		$fields = array_column( $columns, null, 'Field' );
		// type, nullable, default. Integer display widths are not storage types.
		$expected = array(
			'id' => array( 'bigint unsigned', false, null ),
			'operation_identity' => array( 'char(32)', false, null ),
			'installation_id' => array( 'char(32)', false, null ),
			'source_epoch_id' => array( 'bigint unsigned', false, null ),
			'source_epoch_identity' => array( 'char(32)', false, null ),
			'target_epoch_identity' => array( 'char(32)', false, null ),
			'target_epoch_id' => array( 'bigint unsigned', true, null ),
			'active_slot' => array( 'tinyint unsigned', true, null ),
			'status' => array( 'varchar(32)', false, null ),
			'phase' => array( 'varchar(32)', false, null ),
			'checkpoint' => array( 'varchar(64)', true, null ),
			'cursor' => array( 'bigint unsigned', false, '0' ),
			'revision' => array( 'bigint unsigned', false, '0' ),
			'requested_by' => array( 'bigint unsigned', true, null ),
			'error_code' => array( 'varchar(64)', true, null ),
			'started_at' => array( 'datetime', false, null ),
			'updated_at' => array( 'datetime', false, null ),
			'completed_at' => array( 'datetime', true, null ),
		);
		// The v14 migration verifies exact partial/additive definitions using this base schema.
		foreach ( $generation_columns as $column ) {
			if ( ! in_array( $column, array( 'evidence_generation', 'validation_generation' ), true ) ) { return false; }
			$expected[$column] = array( 'bigint unsigned', 'validation_generation' === $column, 'validation_generation' === $column ? null : '0' );
		}
		if ( count( $fields ) !== count( $expected ) || array_diff_key( $expected, $fields ) ) { return false; }
		foreach ( $expected as $name => [ $type, $nullable, $default ] ) {
			$column = $fields[ $name ];
			$actual_type = preg_replace( '/\b(tinyint|bigint)\(\d+\)/', '$1', strtolower( $column->Type ) );
			// MariaDB reports a nullable SQL NULL default as the string "NULL" on some versions.
			$actual_default = $nullable && 'NULL' === $column->Default ? null : $column->Default;
			if ( $type !== $actual_type || ( $nullable ? 'YES' : 'NO' ) !== $column->Null || $default !== $actual_default || ( 'id' === $name ? 'auto_increment' : '' ) !== strtolower( $column->Extra ) ) { return false; }
			$collation = str_contains( $type, 'char(' ) ? 'ascii_bin' : null;
			if ( $collation !== $column->Collation ) { return false; }
		}
		$indexes = $db->get_results( 'SHOW INDEX FROM ' . $tables->commerce_resets() );
		if ( '' !== $db->last_error || ! is_array( $indexes ) ) { return false; }
		$actual = array();
		foreach ( $indexes as $part ) {
			if ( null !== $part->Sub_part || 0 !== (int) $part->Non_unique || 'BTREE' !== strtoupper( $part->Index_type ) ) { return false; }
			$actual[ $part->Key_name ][ (int) $part->Seq_in_index ] = $part->Column_name;
		}
		$required = array(
			'PRIMARY' => array( 'id' ),
			'operation_identity' => array( 'operation_identity' ),
			'source_epoch' => array( 'installation_id', 'source_epoch_id' ),
			'target_epoch_identity' => array( 'target_epoch_identity' ),
			'active_slot' => array( 'active_slot' ),
			'target_epoch_id' => array( 'target_epoch_id' ),
		);
		if ( count( $actual ) !== count( $required ) ) { return false; }
		foreach ( $required as $name => $wanted ) {
			if ( ! isset( $actual[ $name ] ) ) { return false; }
			ksort( $actual[ $name ] );
			if ( array_values( $actual[ $name ] ) !== $wanted ) { return false; }
		}
		return true;
	}

	/** @return array{installation_id:string,epoch_id:int,epoch_identity:string}|null */
	private static function lock_context( \wpdb $db, TableNames $tables, array $states ): ?array {
		$db->last_error = '';
		$controls = $db->get_results( 'SELECT * FROM ' . $tables->commerce_control() . ' ORDER BY id FOR UPDATE' );
		if ( '' !== $db->last_error || ! is_array( $controls ) || 1 !== count( $controls ) || 1 !== (int) $controls[0]->id || ! in_array( $controls[0]->state, $states, true ) || ! preg_match( '/\A[0-9a-f]{32}\z/', $controls[0]->installation_id ) || false === filter_var( $controls[0]->current_epoch_id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) || ! self::date_valid( $controls[0]->updated_at ) ) { return null; }
		$epochs = $db->get_results( $db->prepare( 'SELECT * FROM ' . $tables->commerce_epochs() . ' WHERE id=%d FOR UPDATE', (int) $controls[0]->current_epoch_id ) );
		if ( '' !== $db->last_error || ! is_array( $epochs ) || 1 !== count( $epochs ) || (string) $epochs[0]->id !== (string) $controls[0]->current_epoch_id || ! self::date_valid( $epochs[0]->created_at ) || null !== $epochs[0]->retired_at || ! preg_match( '/\A[0-9a-f]{32}\z/', $epochs[0]->identity ) ) { return null; }
		return array( 'installation_id' => $controls[0]->installation_id, 'epoch_id' => (int) $controls[0]->current_epoch_id, 'epoch_identity' => $epochs[0]->identity );
	}
	private static function date_valid( mixed $value ): bool {
		if ( ! is_string( $value ) ) { return false; }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value && '0000' !== substr( $value, 0, 4 );
	}
}
