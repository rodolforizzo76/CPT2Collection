<?php
/** Phase 4.1 production migration boundaries, preservation, structure, and admitted reads. */
declare( strict_types=1 );
$wp_fixture = sys_get_temp_dir() . '/c2c_p41_wp_' . bin2hex( random_bytes( 5 ) );
mkdir( $wp_fixture . '/wp-admin/includes', 0700, true );
file_put_contents( $wp_fixture . '/wp-admin/includes/upgrade.php', '<?php' );
define( 'ABSPATH', $wp_fixture . '/' );
register_shutdown_function( static function () use ( $wp_fixture ): void {
	unlink( $wp_fixture . '/wp-admin/includes/upgrade.php' ); rmdir( $wp_fixture . '/wp-admin/includes' ); rmdir( $wp_fixture . '/wp-admin' ); rmdir( $wp_fixture );
} );
require __DIR__ . '/support/phase-two-schema.php';
use RodolfoRizzo\Cpt2Collection\{CommercePhaseTwoMigration,CommercePhaseThreeCheckpointOneMigration,CommercePhaseFourCheckpointOneMigration as Migration,CommerceEvidenceGenerationMigration as CurrentMigration,CommerceResetRecord,CommerceResetRepository,Database,TableNames,TransactionManager};
$options = array(); $acknowledgements = array(); $lose_ack = false;
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['options'][$key] ?? $default; }
function update_option( string $key, mixed $value, bool $autoload = true ): bool {
	if ( $GLOBALS['lose_ack'] && '0.13.0' === $value ) { return false; }
	$GLOBALS['acknowledgements'][] = $value; $GLOBALS['options'][$key] = $value; return true;
}
function dbDelta( string $sql ): array { global $wpdb; sql( $wpdb, str_replace( 'CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $sql ) ); return array(); }

final class InterruptedResetMigrationDb extends wpdb {
	public string $boundary = ''; public bool $fired = false; private int $commits = 0;
	public function query( string $query ): int|false {
		$result = parent::query( $query );
		if ( '' === $this->boundary ) { return $result; }
		if ( str_starts_with( $query, 'CREATE TABLE IF NOT EXISTS' ) && str_contains( $query, 'c2c_commerce_resets' ) ) {
			$probe = new wpdb( $this->prefix );
			$name = substr( 'c2c_phase4_cp1_' . hash( 'sha256', (string) $probe->get_var( 'SELECT DATABASE()' ) . ':' . $this->prefix ), 0, 64 );
			check( '0' === (string) $probe->get_var( $probe->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) && 'migrating_v13' === $probe->get_var( 'SELECT state FROM ' . $this->prefix . 'c2c_commerce_control' ), 'DDL retains advisory mutex and committed migration fence' );
			$probe->close();
		}
		if ( 'COMMIT' === $query ) { ++$this->commits; }
		$match = match ( $this->boundary ) {
			'before_fence' => str_contains( $query, "SET state='migrating_v13'" ),
			'fence' => 'COMMIT' === $query && 1 === $this->commits,
			'ddl' => str_starts_with( $query, 'CREATE TABLE IF NOT EXISTS' ) && str_contains( $query, 'c2c_commerce_resets' ),
			'verified' => 'START TRANSACTION' === $query && 1 === $this->commits,
			'ready' => str_contains( $query, "SET state='ready'" ),
			'ready_commit' => 'COMMIT' === $query && 2 === $this->commits,
			default => false,
		};
		if ( $match && ! $this->fired ) { $this->fired = true; $this->last_error = 'injected result loss after production statement'; return false; }
		return $result;
	}
}
function reset_snapshot( wpdb $db, TableNames $t ): string {
	$rows = array();
	foreach ( array( 'commerce_control', 'commerce_epochs', 'carts', 'cart_items', 'orders', 'order_items', 'reservations', 'payment_sessions', 'payment_events', 'provider_claims', 'provider_identities', 'order_deletions' ) as $method ) {
		$rows[$method] = $db->get_results( 'SELECT * FROM ' . $t->$method() . ' ORDER BY id' );
		if ( 'commerce_control' === $method ) { unset( $rows[$method][0]->state ); }
	}
	$rows['stock'] = $db->get_results( 'SELECT * FROM ' . $db->postmeta . ' ORDER BY meta_id' );
	$rows['configuration'] = array_diff_key( $GLOBALS['options'], array( Database::OPTION_SCHEMA_VERSION => true ) );
	return serialize( $rows );
}
function reset_fixture( wpdb $db, TableNames $t, bool $multiple = false ): void {
	phase_one_schema( $db ); $at = '2026-01-01 00:00:00';
	sql( $db, "CREATE TABLE {$db->postmeta}(meta_id BIGINT PRIMARY KEY,post_id BIGINT,meta_key VARCHAR(255),meta_value LONGTEXT) ENGINE=InnoDB" );
	sql( $db, "INSERT INTO {$db->postmeta} VALUES(1,10,'_cpt2collection_stock_quantity','7')" );
	sql( $db, "INSERT INTO {$t->carts()}(id,token_hash,created_at,updated_at,last_activity_at,expires_at) VALUES(1,REPEAT('c',64),'$at','$at','$at','2030-01-01')" );
	sql( $db, "INSERT INTO {$t->cart_items()}(cart_id,post_id,quantity,created_at,updated_at) VALUES(1,10,1,'$at','$at')" );
	sql( $db, "INSERT INTO {$t->orders()}(id,cart_id,status,currency,subtotal_minor,total_minor,created_at,updated_at,paid_at) VALUES(1,1,'paid','EUR',100,100,'$at','$at','$at')" );
	sql( $db, "INSERT INTO {$t->order_items()}(id,order_id,post_id,item_name,unit_price_minor,quantity,line_subtotal_minor,line_total_minor,currency,created_at) VALUES(1,1,10,'Legacy',100,1,100,100,'EUR','$at')" );
	sql( $db, "INSERT INTO {$t->reservations()}(order_id,order_item_id,post_id,quantity,status,created_at,updated_at,expires_at,converted_at) VALUES(1,1,10,1,'converted','$at','$at','$at','$at')" );
	sql( $db, "INSERT INTO {$t->payment_sessions()}(id,reference,order_id,provider,provider_session_id,provider_payment_id,provider_event_id,creation_key_hash,status,currency,amount_minor,created_at,updated_at,completed_at) VALUES(1,REPEAT('c',32),1,'stripe_checkout','cs_old','pi_old','evt_old',REPEAT('d',64),'completed','EUR',100,'$at','$at','$at')" );
	sql( $db, "INSERT INTO {$t->payment_events()}(provider,provider_event_id,event_type,livemode,payment_session_id,provider_session_id,provider_payment_id,local_reference,processing_status,received_at,payload_sha256) VALUES('stripe_checkout','evt_old','checkout.session.completed',0,1,'cs_old','pi_old',REPEAT('c',32),'processed','$at',REPEAT('e',64))" );
	check( CommercePhaseTwoMigration::run( $db ) && CommercePhaseThreeCheckpointOneMigration::run( $db ), 'fixture reaches genuine schema 0.12 through historical migrations' );
	sql( $db, "INSERT INTO {$t->order_deletions()}(installation_id,epoch_id,epoch_identity,original_order_id,deleted_at,checkout_disposition) SELECT c.installation_id,e.id,e.identity,99,'$at','identity_only' FROM {$t->commerce_control()} c JOIN {$t->commerce_epochs()} e ON e.id=c.current_epoch_id" );
	if ( $multiple ) {
		// Fixture SQL only, never a production epoch transition.
		sql( $db, "INSERT INTO {$t->commerce_epochs()} VALUES(2,REPEAT('f',32),'$at',NULL)" );
		sql( $db, "UPDATE {$t->commerce_epochs()} SET retired_at='$at' WHERE id=1" );
		sql( $db, "UPDATE {$t->commerce_control()} SET current_epoch_id=2" );
		// Historical evidence remains in epoch 1; a new unrelated cart exercises non-1 ownership.
		sql( $db, "INSERT INTO {$t->carts()}(epoch_id,id,token_hash,created_at,updated_at,last_activity_at,expires_at) VALUES(2,2,REPEAT('b',64),'$at','$at','$at','2030-01-01')" );
	}
	$GLOBALS['options'] = array( Database::OPTION_SCHEMA_VERSION => '0.12.0', 'provider_configuration' => array( 'enabled' => true, 'mode' => 'test' ), 'plugin_configuration' => array( 'currency' => 'EUR' ) );
}
function reset_cleanup( wpdb $db, TableNames $t ): void {
	foreach ( array( 'commerce_resets', 'order_deletions', 'provider_identities', 'provider_claims', 'payment_events', 'payment_sessions', 'reservations', 'order_items', 'orders', 'cart_items', 'carts', 'commerce_control', 'commerce_epochs' ) as $method ) { $db->query( 'DROP TABLE IF EXISTS ' . $t->$method() ); }
	$db->query( 'DROP TABLE IF EXISTS ' . $db->postmeta ); $db->close();
}

function historical_reset_upgrade(): void {
 global $wpdb;
 if(get_option(Database::OPTION_SCHEMA_VERSION)==='0.14.0')return;
 if(Migration::run($wpdb)) {
  update_option(Database::OPTION_SCHEMA_VERSION,'0.13.0');
  if(get_option(Database::OPTION_SCHEMA_VERSION)==='0.13.0'&&CurrentMigration::run($wpdb))update_option(Database::OPTION_SCHEMA_VERSION,'0.14.0');
 }
}
foreach ( array( 'before_fence', 'fence', 'ddl', 'verified', 'ready', 'ready_commit', 'ack', 'normal', 'multiple' ) as $boundary ) {
	$prefix = 'c2c_p41_' . bin2hex( random_bytes( 5 ) ) . '_'; $wpdb = new InterruptedResetMigrationDb( $prefix ); $t = new TableNames( $wpdb );
	try {
		reset_fixture( $wpdb, $t, 'multiple' === $boundary ); $before = reset_snapshot( $wpdb, $t );
		$wpdb->boundary = $boundary; $lose_ack = 'ack' === $boundary; historical_reset_upgrade();
		$failed = ! in_array( $boundary, array( 'normal', 'multiple' ), true );
		check( ( $failed ? '0.12.0' : '0.14.0' ) === get_option( Database::OPTION_SCHEMA_VERSION ), "$boundary: schema acknowledgement follows complete migration" );
		if ( $failed && 'ack' !== $boundary ) { check( $wpdb->fired, "$boundary: actual SQL interruption reached" ); }
		$wpdb->close(); $wpdb = new wpdb( $prefix ); $tx = new TransactionManager( $wpdb, $t );
		$open = in_array( $boundary, array( 'before_fence', 'ready_commit', 'ack', 'normal', 'multiple' ), true );
		check( !$tx->begin_commerce_shared(), "$boundary: old schema remains without modern admission" ); $tx->rollback();
		check( $before === reset_snapshot( $wpdb, $t ), "$boundary: all commerce, epoch history, evidence, stock and configuration unchanged" );
		$lose_ack = false; $wpdb->boundary=''; historical_reset_upgrade();
		check( '0.14.0' === get_option( Database::OPTION_SCHEMA_VERSION ) && CurrentMigration::verify_structure( $wpdb, $t ), "$boundary: fresh connection resumes to verified schema 0.14" );
		check( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $t->commerce_resets() ), "$boundary: no reset operations fabricated" );
		check( $before === reset_snapshot( $wpdb, $t ) && !$tx->begin_commerce_shared(), "$boundary: retry preserves old state without modern authority" ); $tx->rollback();
		check( CurrentMigration::run( $wpdb ) && $before === reset_snapshot( $wpdb, $t ), "$boundary: repeated bootstrap and migration preserve state" );
		if ( 'multiple' === $boundary ) {
			check( CommercePhaseTwoMigration::verify_structure( $wpdb, $t ) && ! CommercePhaseTwoMigration::verify( $wpdb, $t ), 'multi-epoch structural verification succeeds while historical epoch-1 proof remains strict' );
			check( ! CommercePhaseTwoMigration::run( $wpdb ) && $before === reset_snapshot( $wpdb, $t ), 'historical backfill refuses multi-epoch input without rewriting data' );
		}
	} finally { $wpdb->close(); reset_cleanup( new wpdb( $prefix ), $t ); }
}

// Production bootstrap, including every historical version acknowledgement.
$prefix = 'c2c_p41fresh_' . bin2hex( random_bytes( 5 ) ) . '_'; $wpdb = new wpdb( $prefix ); $t = new TableNames( $wpdb );
try {
	$options = array(); $acknowledgements = array(); Database::maybe_upgrade();
	check( array( '0.10.0', '0.11.0', '0.12.0', '0.13.0', '0.14.0', '0.15.0', '0.16.0', '0.17.0', '0.18.0', '0.19.0', '0.20.0', '0.21.0' ) === $acknowledgements, 'fresh install explicitly completes each historical migration in order' );
	check( CurrentMigration::verify_structure( $wpdb, $t ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $t->commerce_resets() ), 'fresh reset table is structurally valid and empty' );
	check( 1 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $t->commerce_epochs() ) && '1' === (string) $wpdb->get_var( 'SELECT current_epoch_id FROM ' . $t->commerce_control() ) && null === $wpdb->get_var( 'SELECT retired_at FROM ' . $t->commerce_epochs() ), 'fresh install retains one unretired initial epoch' );
	foreach ( array( 'provider_claims', 'provider_identities', 'order_deletions' ) as $method ) { check( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $t->$method() ), "fresh install fabricates no $method evidence" ); }
} finally { reset_cleanup( $wpdb, $t ); }

// Every malformed definition must fail closed, with schema acknowledgement withheld.
$prefix = 'c2c_p41bad_' . bin2hex( random_bytes( 5 ) ) . '_'; $wpdb = new wpdb( $prefix ); $t = new TableNames( $wpdb );
try {
	reset_fixture( $wpdb, $t ); $before = reset_snapshot( $wpdb, $t );
	$faults = array(
		'partial' => 'DROP COLUMN error_code',
		'extra_column' => 'ADD unexpected INT NULL',
		'engine' => 'ENGINE=MyISAM',
		'signed' => 'MODIFY revision BIGINT NOT NULL DEFAULT 0',
		'width' => 'MODIFY operation_identity CHAR(31) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
		'collation' => 'MODIFY operation_identity CHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL',
		'nullable' => 'MODIFY source_epoch_id BIGINT UNSIGNED NULL',
		'default' => 'ALTER revision SET DEFAULT 1',
		'extra' => 'MODIFY updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
		'auto_increment' => 'MODIFY id BIGINT UNSIGNED NOT NULL',
		'primary' => 'MODIFY id BIGINT UNSIGNED NOT NULL, DROP PRIMARY KEY, ADD UNIQUE KEY alternate_id (id)',
		'unique_missing' => 'DROP INDEX active_slot',
		'unique_nonunique' => 'DROP INDEX target_epoch_id, ADD KEY target_epoch_id (target_epoch_id)',
		'unique_partial' => 'DROP INDEX source_epoch, ADD UNIQUE KEY source_epoch (installation_id)',
		'unique_prefix' => 'DROP INDEX operation_identity, ADD UNIQUE KEY operation_identity (operation_identity(16))',
		'unique_extra_column' => 'DROP INDEX active_slot, ADD UNIQUE KEY active_slot (active_slot, source_epoch_id)',
		'extra_index' => 'ADD KEY speculative (status)',
	);
	foreach ( $faults as $fault => $alter ) {
		sql( $wpdb, Migration::ddl( $t ) . ' ' . $wpdb->get_charset_collate() ); sql( $wpdb, 'ALTER TABLE ' . $t->commerce_resets() . ' ' . $alter );
		$options[Database::OPTION_SCHEMA_VERSION] = '0.12.0';
		check( ! Migration::verify_structure( $wpdb, $t ), "$fault: malformed structure rejected" );
		historical_reset_upgrade(); $tx = new TransactionManager( $wpdb, $t );
		check( '0.12.0' === get_option( Database::OPTION_SCHEMA_VERSION ) && ! $tx->begin_commerce_shared(), "$fault: schema stays incomplete and commerce fenced" );
		check( $before === reset_snapshot( $wpdb, $t ), "$fault: no existing state rewritten" );
		// Test-only removal of the empty malformed fixture models explicit operator repair.
		sql( $wpdb, 'DROP TABLE ' . $t->commerce_resets() ); historical_reset_upgrade();
		check( '0.14.0' === get_option( Database::OPTION_SCHEMA_VERSION ) && CurrentMigration::verify_structure( $wpdb, $t ), "$fault: retry after explicit fixture repair succeeds" );
		sql( $wpdb, 'DROP TABLE ' . $t->commerce_resets() );
	}
	sql( $wpdb, "UPDATE {$t->commerce_control()} SET state='resetting'" ); $options[Database::OPTION_SCHEMA_VERSION] = '0.12.0'; historical_reset_upgrade();
	check( 'resetting' === $wpdb->get_var( 'SELECT state FROM ' . $t->commerce_control() ) && '0.12.0' === get_option( Database::OPTION_SCHEMA_VERSION ), 'migration refuses to reinterpret or reopen runtime resetting state' );
	check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t->commerce_resets() ) ), 'runtime resetting rejection happens before reset DDL' );
	check( $before === reset_snapshot( $wpdb, $t ), 'runtime fence rejection preserves all existing data' );
} finally { reset_cleanup( $wpdb, $t ); }


// Migration must wait for already-admitted commerce before committing its fence.
$prefix = 'c2c_p41lock_' . bin2hex( random_bytes( 5 ) ) . '_'; $wpdb = new wpdb( $prefix ); $t = new TableNames( $wpdb );
try {
	reset_fixture( $wpdb, $t ); $before = reset_snapshot( $wpdb, $t );
	$other = new wpdb( $prefix ); $owner = new TransactionManager( $other, $t );
	check( $owner->begin(), 'simulated pre-upgrade holder begins private transaction' );
	$other->get_row('SELECT * FROM '.$t->commerce_control().' LOCK IN SHARE MODE');
	sql( $wpdb, 'SET SESSION innodb_lock_wait_timeout=1' ); historical_reset_upgrade();
	check( '0.12.0' === get_option( Database::OPTION_SCHEMA_VERSION ) && 'ready' === $other->get_var( 'SELECT state FROM ' . $t->commerce_control() ), 'migration cannot fence or acknowledge while prior commerce owns admission' );
	$owner->rollback(); $other->close(); historical_reset_upgrade();
	check( '0.14.0' === get_option( Database::OPTION_SCHEMA_VERSION ) && $before === reset_snapshot( $wpdb, $t ), 'migration retries after prior commerce releases admission' );
} finally { reset_cleanup( $wpdb, $t ); }
// Existing historical installations use the explicit upgrade branch, not fresh bootstrap.
foreach ( array( '0.10.0', '0.11.0' ) as $installed ) {
	$prefix = 'c2c_p41path_' . bin2hex( random_bytes( 5 ) ) . '_'; $wpdb = new wpdb( $prefix ); $t = new TableNames( $wpdb );
	try {
		phase_one_schema( $wpdb );
		if ( '0.11.0' === $installed ) { check( CommercePhaseTwoMigration::run( $wpdb ), 'existing 0.11 fixture completes historical backfill' ); }
		$options = array( Database::OPTION_SCHEMA_VERSION => $installed ); $acknowledgements = array(); Database::maybe_upgrade();
		$expected = '0.10.0' === $installed ? array( '0.11.0', '0.12.0', '0.13.0', '0.14.0', '0.15.0', '0.16.0', '0.17.0', '0.18.0', '0.19.0', '0.20.0', '0.21.0' ) : array( '0.12.0', '0.13.0', '0.14.0', '0.15.0', '0.16.0', '0.17.0', '0.18.0', '0.19.0', '0.20.0', '0.21.0' );
		check( $expected === $acknowledgements && CurrentMigration::verify_structure( $wpdb, $t ), "$installed: explicit upgrade branch preserves every required historical checkpoint" );
	} finally { reset_cleanup( $wpdb, $t ); }
}
echo "All Phase 4.1 migration, structure, and preservation checks passed.\n";
