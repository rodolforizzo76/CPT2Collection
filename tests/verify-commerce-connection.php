<?php
/** Exercise the wpdb reconnect hook, without a WordPress installation. */
declare( strict_types=1 );
define( 'ABSPATH', __DIR__ );
class wpdb {
	public string $prefix = 'wp_';
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public mixed $dbh = null;
	public string $last_error = '';
	public bool $lost = false;
	public int $reconnects = 0;
	public int $executions = 0;
	public string $close_failure = '';
	public $during_close = null;
	public function close() {
		if ( $this->during_close ) { ($this->during_close)(); }
		if ( 'exception' === $this->close_failure ) { throw new RuntimeException( 'Driver close failed' ); }
		return 'false' !== $this->close_failure;
	}
	public function db_connect( $allow_bail = true ) { ++$this->reconnects; return true; }
	public function check_connection( $allow_bail = true ) { ++$this->reconnects; return true; }
	public function query( $query ) {
		++$this->executions;
		if ( $this->lost ) {
			if ( ! $this->check_connection() ) { return false; }
			++$this->executions; // WordPress's replay after reconnect.
		}
		return 1;
	}
}
require dirname( __DIR__ ) . '/includes/class-commerce-wpdb.php';
use RodolfoRizzo\Cpt2Collection\CommerceWpdb;
function check( bool $ok, string $label ): void { if ( ! $ok ) { throw new RuntimeException( $label ); } echo "PASS: $label\n"; }
foreach ( array( 'START TRANSACTION', 'UPDATE stock', 'COMMIT', 'ROLLBACK' ) as $statement ) {
	$db = new CommerceWpdb(); $owner = new stdClass();
	check( $db->pin_transaction( $owner ), 'ownership established before ' . $statement );
	check( ! $db->pin_transaction( new stdClass() ), 'second transaction owner rejected' );
	$db->lost = true;
	check( false === $db->query( $statement ) && 0 === $db->reconnects && 1 === $db->executions, 'lost connection cannot replay ' . $statement );
	$db->release_transaction( $owner );
	check( ! $db->transaction_connection_usable() && ! $db->pin_transaction( $owner ), 'uncertainty cannot be released' );
	check( false === $db->query( 'UPDATE stock' ) && 1 === $db->executions, 'poisoned connection executes no later statement' );
}
$db = new CommerceWpdb(); $owner = new stdClass(); $db->pin_transaction( $owner ); $db->query( 'COMMIT' ); $db->release_transaction( $owner );
check( $db->pin_transaction( new stdClass() ), 'confirmed termination permits a new owner' );
$global = new wpdb(); $global->lost = true; $global->query( 'SELECT 1' );
check( 1 === $global->reconnects && 2 === $global->executions, 'global WordPress behavior remains unchanged' );

require dirname( __DIR__ ) . '/includes/class-table-names.php';
require dirname( __DIR__ ) . '/includes/class-transaction-manager.php';
use RodolfoRizzo\Cpt2Collection\{TableNames,TransactionManager};
foreach ( array( '', 'false', 'exception' ) as $failure ) {
	$db = new CommerceWpdb(); $tx = new TransactionManager( $db, new TableNames( $db ) );
	check( $tx->begin(), 'owner established before close: ' . $failure );
	$executions = $db->executions;
	$db->close_failure = $failure;
	$db->during_close = function() use ( $db, $tx ): void {
		$owners = ( new ReflectionProperty( TransactionManager::class, 'owners' ) )->getValue();
		check( ! isset( $owners[$db] ) && $tx->is_outcome_uncertain() && ! $db->transaction_connection_usable(), 'revocation precedes driver close, including failure' );
		check( ! ( new TransactionManager( $db, new TableNames( $db ) ) )->begin(), 'driver cleanup cannot reenter ownership' );
	};
	try { check( $db->close() === ( 'false' !== $failure ), 'driver close result preserved' ); }
	catch ( RuntimeException $error ) { check( 'exception' === $failure && 'Driver close failed' === $error->getMessage(), 'driver exception preserved' ); }
	check( ! $tx->commit() && ! $tx->rollback() && $tx->is_outcome_uncertain(), 'close failure never confirms transaction outcome' );
	check( ! $db->db_connect( false ) && 0 === $db->reconnects, 'discard never enters driver reconnect' );
	$db->close_failure = ''; $db->close();
	check( ! $db->pin_transaction( new stdClass() ), 'repeated close cannot restore ownership' );
	check( $executions === $db->executions, 'discard cleanup and outcome refusal issue no SQL' );
}
