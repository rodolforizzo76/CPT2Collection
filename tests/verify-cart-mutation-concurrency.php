<?php
/** Real-MariaDB cart generation and serialized mutation verification. */
declare( strict_types=1 );
if ( ! extension_loaded( 'mysqli' ) || ! function_exists( 'pcntl_fork' ) || ! function_exists( 'stream_socket_pair' ) || false === getenv( 'C2C_TEST_DB_NAME' ) ) {
	echo "SKIP: mysqli, pcntl, stream sockets, and C2C_TEST_DB_NAME are required for cart mutation concurrency.\n"; exit( 0 );
}
define( 'ABSPATH', __DIR__ );
$db_host = getenv( 'C2C_TEST_DB_HOST' ) ?: '127.0.0.1';
$db_port = (int) ( getenv( 'C2C_TEST_DB_PORT' ) ?: 3306 );
$db_user = getenv( 'C2C_TEST_DB_USER' ) ?: 'root';
$db_pass = getenv( 'C2C_TEST_DB_PASSWORD' ) ?: '';
$db_name = (string) getenv( 'C2C_TEST_DB_NAME' );
$prefix = 'c2c_cart_version_' . bin2hex( random_bytes( 4 ) ) . '_';
mysqli_report( MYSQLI_REPORT_OFF );
require_once __DIR__ . '/support/mariadb-wpdb.php';
foreach ( array( 'table-names', 'cart-item', 'cart-record', 'cart-repository' ) as $class ) { require_once dirname( __DIR__ ) . '/includes/class-' . $class . '.php'; }
use RodolfoRizzo\Cpt2Collection\CartRepository;
use RodolfoRizzo\Cpt2Collection\TableNames;
function db(): wpdb { global $prefix; return new wpdb( $prefix ); }
function repo( wpdb $db ): CartRepository { return new CartRepository( $db, new TableNames( $db ) ); }
function check( bool $ok, string $label ): void { if ( ! $ok ) { throw new RuntimeException( $label ); } echo "PASS: {$label}\n"; }
function sql( wpdb $db, string $query ): void { if ( false === $db->query( $query ) ) { throw new RuntimeException( $db->last_error ); } }
function read_packet( $stream ): string { $line = fgets( $stream ); if ( false === $line ) { throw new RuntimeException( 'Worker timeout/closed IPC' ); } return trim( $line ); }

// The child starts its real repository operation while the parent owns the cart lock.
function serialized_mutations( bool $remove ): void {
	global $prefix;
	$sockets = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
	if ( false === $sockets ) { throw new RuntimeException( 'IPC unavailable' ); }
	foreach ( $sockets as $socket ) { stream_set_timeout( $socket, 10 ); }
	$pid = pcntl_fork();
	if ( $pid < 0 ) { throw new RuntimeException( 'Fork failed' ); }
	if ( 0 === $pid ) {
		fclose( $sockets[0] );
		try {
			if ( 'go' !== read_packet( $sockets[1] ) ) { exit( 2 ); }
			$d = db(); $r = repo( $d ); sql( $d, 'SET innodb_lock_wait_timeout=5' ); check($r->transactions()->begin_commerce_shared(),'mutation admitted');
			fwrite( $sockets[1], $d->dbh->thread_id . "\n" ); fflush( $sockets[1] );
			if ( ! $r->find_for_update( 1 ) ) { throw new RuntimeException( 'Child lock failed' ); }
			if ( $remove ) { $ok = 1 === $r->delete_item_locked( 1, 1 ); }
			else { $item = $r->add_item_quantity_locked( 1, 1, 1 ); $ok = false !== $item && 3 === $item->quantity() && 2 === $item->content_version(); }
			if ( ! $ok || ! $r->advance_content_revision( 1, 1, gmdate( 'Y-m-d H:i:s' ) ) ) { throw new RuntimeException( 'Child mutation failed' ); }
			$r->transactions()->commit(); fwrite( $sockets[1], "done\n" ); $d->close(); exit( 0 );
		} catch ( Throwable $e ) { fwrite( $sockets[1], $e->getMessage() . "\n" ); exit( 1 ); }
	}
	fclose( $sockets[1] ); $d = db(); $r = repo( $d );
	try {
		check($r->transactions()->begin_commerce_shared(),'mutation admitted'); check( null !== $r->find_for_update( 1 ), 'parent owns cart row lock' );
		fwrite( $sockets[0], "go\n" ); fflush( $sockets[0] ); $child_id = (int) read_packet( $sockets[0] );
		$observer = db(); $waiting = false; $deadline = microtime( true ) + 5;
		do {
			$waiting = (int) $observer->get_var( "SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX t ON t.trx_id=w.requesting_trx_id WHERE t.trx_mysql_thread_id={$child_id}" ) > 0;
			if ( ! $waiting ) { usleep( 200000 ); }
		} while ( ! $waiting && microtime( true ) < $deadline );
		$observer->close();
		check( $waiting, 'second process demonstrably waits on the cart row' );
		if ( $remove ) { check( 1 === $r->update_item_quantity_locked( 1, 1, 7 ), 'set changes row once before concurrent remove' ); }
		else { check( 1 === $r->add_item_quantity_locked( 1, 1, 1 )->content_version(), 'first serialized add advances version once' ); }
		check( $r->advance_content_revision( 1, 0, gmdate( 'Y-m-d H:i:s' ) ), 'first mutation advances revision' );
		$r->transactions()->commit(); $child_result=read_packet( $sockets[0] ); check( 'done' === $child_result, 'second mutation completes after commit: '.$child_result );
		pcntl_waitpid( $pid, $status ); check( pcntl_wifexited( $status ) && 0 === pcntl_wexitstatus( $status ), 'worker exits successfully' );
		check( $r->transactions()->begin_commerce_shared(), 'final state read admitted' );
		$cart = $r->find_for_update( 1 ); check( 2 === $cart->content_revision() && 0 === $cart->checkout_generation(), 'serialized mutations advance revision twice and reset generation' );
		check( $remove ? null === $r->find_item( 1, 1 ) : 2 === $r->find_item( 1, 1 )->content_version(), 'serialized mutations preserve item generation/identity semantics' );
		$r->transactions()->commit();
	} finally { $d->query( 'ROLLBACK' ); $d->close(); fclose( $sockets[0] ); }
}
$d = db();
try {
	create_commerce_foundation($d);
	sql( $d, "CREATE TABLE `{$prefix}c2c_carts` (epoch_id BIGINT UNSIGNED NOT NULL DEFAULT 1,id BIGINT UNSIGNED PRIMARY KEY,token_hash CHAR(64) NOT NULL,content_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,checkout_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,last_activity_at DATETIME NOT NULL,expires_at DATETIME NOT NULL) ENGINE=InnoDB" );
	sql( $d, "CREATE TABLE `{$prefix}c2c_cart_items` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,cart_id BIGINT UNSIGNED NOT NULL,post_id BIGINT UNSIGNED NOT NULL,quantity INT UNSIGNED NOT NULL,content_version BIGINT UNSIGNED NOT NULL DEFAULT 0,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,UNIQUE KEY cart_product(cart_id,post_id)) ENGINE=InnoDB" );
	sql( $d, "INSERT INTO `{$prefix}c2c_carts` (id,token_hash,content_revision,checkout_generation,created_at,updated_at,last_activity_at,expires_at) VALUES(1,'test',0,9,NOW(),NOW(),NOW(),'2030-01-01')" );
	$r = repo( $d ); $r->transactions()->begin_commerce_shared(); $first = $r->add_item_quantity( 1, 1, 1 );
	check( false !== $first && 0 === $first->content_version(), 'real insert uses version zero default' );
	$r->transactions()->commit(); $d->close(); unset( $d, $r ); serialized_mutations( false );
	$d = db(); $r = repo( $d ); $r->transactions()->begin_commerce_shared();
	check( 1 === $r->update_item_quantity_locked( 1, 1, 4 ) && 3 === $r->find_item( 1, 1 )->content_version(), 'locked set increments version once' );
	check( 0 === $r->update_item_quantity_locked( 1, 1, 4 ) && 3 === $r->update_item_quantity( 1, 1, 4 )->content_version(), 'both direct set primitives preserve identical quantity' );
	check( 4 === $r->update_item_quantity( 1, 1, 5 )->content_version(), 'unlocked set primitive also advances version once' );
	$before = $d->get_row( "SELECT * FROM `{$prefix}c2c_cart_items`" ); $r->transactions()->commit();
	check($r->transactions()->begin_commerce_shared(),'mutation admitted'); $r->find_for_update( 1 ); $r->add_item_quantity_locked( 1, 1, 1 ); $r->transactions()->rollback();
	check( $before == $d->get_row( "SELECT * FROM `{$prefix}c2c_cart_items`" ), 'transaction rollback restores quantity/version/timestamps' );
	check($r->transactions()->begin_commerce_shared(),'mutation admitted'); $r->find_for_update( 1 ); $r->update_item_quantity_locked( 1, 1, 6 );
	check( ! $r->advance_content_revision( 1, 999, gmdate( 'Y-m-d H:i:s' ) ), 'revision compare-and-set failure after mutation' ); $r->transactions()->rollback();
	check( $before == $d->get_row( "SELECT * FROM `{$prefix}c2c_cart_items`" ), 'revision failure rollback restores quantity and version' );
	$r->transactions()->begin_commerce_shared();
	sql( $d, "UPDATE `{$prefix}c2c_cart_items` SET content_version=" . ( PHP_INT_MAX - 1 ) );
	check( PHP_INT_MAX === $r->add_item_quantity( 1, 1, 1 )->content_version(), 'last representable version is reachable' );
	$before = $d->get_row( "SELECT * FROM `{$prefix}c2c_cart_items`" );
	check( false === $r->add_item_quantity( 1, 1, 1 ) && false === $r->update_item_quantity( 1, 1, 7 ) && 0 === $r->update_item_quantity_locked( 1, 1, 7 ), 'exhausted versions reject all quantity-changing primitives' );
	check( PHP_INT_MAX === $r->update_item_quantity( 1, 1, 6 )->content_version() && $before == $d->get_row( "SELECT * FROM `{$prefix}c2c_cart_items`" ), 'version exhaustion preserves full row and permits no-op' );
	check( $r->delete_item( 1, 1 ) && null === $r->find_item( 1, 1 ), 'remove destroys row identity' );
	$next = $r->add_item_quantity( 1, 1, 1 ); check( $next->id() !== $first->id() && 0 === $next->content_version(), 're-add creates new identity at zero' );
	sql( $d, "UPDATE `{$prefix}c2c_carts` SET content_revision=0,checkout_generation=5" );
	$r->transactions()->commit(); $d->close(); unset( $d, $r ); serialized_mutations( true );
	$d = db(); $r = repo( $d ); $r->transactions()->begin_commerce_shared(); $r->add_item_quantity( 1, 1, 1 ); $r->add_item_quantity( 1, 2, 1 );
	check( 2 === $r->delete_items_locked( 1 ) && array() === $r->get_items( 1 ), 'clear removes all identities' );
} finally {
	if(isset($r)) $r->transactions()->rollback();
	if ( ! isset( $d ) ) { $d = db(); }
	$d->query("DROP TABLE IF EXISTS {$prefix}c2c_commerce_control, {$prefix}c2c_commerce_epochs");
	$d->query( "DROP TABLE IF EXISTS `{$prefix}c2c_cart_items`" ); $d->query( "DROP TABLE IF EXISTS `{$prefix}c2c_carts`" ); $d->close();
}
