<?php
/**
 * Optional two-connection InnoDB integration verification for Milestone 7B.
 *
 * Set C2C_TEST_DB_NAME and optionally C2C_TEST_DB_HOST, C2C_TEST_DB_PORT,
 * C2C_TEST_DB_USER and C2C_TEST_DB_PASSWORD before running this script.
 */

declare( strict_types=1 );

if ( ! extension_loaded( 'mysqli' ) || ! function_exists( 'pcntl_fork' ) || false === getenv( 'C2C_TEST_DB_NAME' ) ) {
	echo "SKIP: mysqli, pcntl, and C2C_TEST_DB_NAME are required for the two-connection integration test.\n";
	exit( 0 );
}

$host = getenv( 'C2C_TEST_DB_HOST' ) ?: '127.0.0.1';
$port = (int) ( getenv( 'C2C_TEST_DB_PORT' ) ?: 3306 );
$user = getenv( 'C2C_TEST_DB_USER' ) ?: 'root';
$pass = getenv( 'C2C_TEST_DB_PASSWORD' ) ?: '';
$name = (string) getenv( 'C2C_TEST_DB_NAME' );
$base = 'c2c_7b_' . bin2hex( random_bytes( 4 ) );
$posts = $base . '_posts';
$reservations = $base . '_reservations';

function connection(): mysqli {
	global $host, $port, $user, $pass, $name;
	$db = new mysqli( $host, $user, $pass, $name, $port );
	$db->set_charset( 'utf8mb4' );
	return $db;
}

function attempt( int $order_id, int $quantity, string $signal = '' ): string {
	global $posts, $reservations;
	$db = connection();
	$db->begin_transaction();
	$product = $db->query( "SELECT ID, stock FROM `{$posts}` WHERE ID = 1 FOR UPDATE" )->fetch_assoc();
	if ( '' !== $signal ) {
		file_put_contents( $signal, 'locked' );
		usleep( 750000 );
	}
	$now = gmdate( 'Y-m-d H:i:s' );
	$stmt = $db->prepare( "SELECT quantity FROM `{$reservations}` WHERE post_id = 1 AND status = 'active' AND expires_at > ? FOR UPDATE" );
	$stmt->bind_param( 's', $now );
	$stmt->execute();
	$result = $stmt->get_result();
	$reserved = 0;
	while ( $row = $result->fetch_assoc() ) { $reserved += (int) $row['quantity']; }
	if ( $quantity > (int) $product['stock'] - $reserved ) {
		$db->rollback();
		return 'insufficient_stock';
	}
	$expires = gmdate( 'Y-m-d H:i:s', time() + 900 );
	$stmt = $db->prepare( "INSERT INTO `{$reservations}` (order_id, post_id, quantity, status, expires_at) VALUES (?, 1, ?, 'active', ?)" );
	$stmt->bind_param( 'iis', $order_id, $quantity, $expires );
	$stmt->execute();
	$db->commit();
	return 'reservation_acquired';
}

$db = connection();
try {
	$db->query( "CREATE TABLE `{$posts}` (ID BIGINT UNSIGNED NOT NULL, stock INT UNSIGNED NOT NULL, PRIMARY KEY (ID)) ENGINE=InnoDB" );
	$db->query( "CREATE TABLE `{$reservations}` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, order_id BIGINT UNSIGNED NOT NULL, post_id BIGINT UNSIGNED NOT NULL, quantity INT UNSIGNED NOT NULL, status VARCHAR(32) NOT NULL, expires_at DATETIME NOT NULL, PRIMARY KEY (id), KEY active_lookup (post_id, status, expires_at)) ENGINE=InnoDB" );
	$db->query( "INSERT INTO `{$posts}` (ID, stock) VALUES (1, 1)" );
	$signal = sys_get_temp_dir() . '/' . $base . '.lock';
	$out1 = sys_get_temp_dir() . '/' . $base . '.one';
	$out2 = sys_get_temp_dir() . '/' . $base . '.two';
	$pid1 = pcntl_fork();
	if ( 0 === $pid1 ) { file_put_contents( $out1, attempt( 1, 1, $signal ) ); exit( 0 ); }
	$limit = microtime( true ) + 5;
	while ( ! is_file( $signal ) && microtime( true ) < $limit ) { usleep( 10000 ); }
	$pid2 = pcntl_fork();
	if ( 0 === $pid2 ) { file_put_contents( $out2, attempt( 2, 1 ) ); exit( 0 ); }
	pcntl_waitpid( $pid1, $status ); pcntl_waitpid( $pid2, $status );
	$results = array( trim( (string) file_get_contents( $out1 ) ), trim( (string) file_get_contents( $out2 ) ) );
	sort( $results );
	if ( array( 'insufficient_stock', 'reservation_acquired' ) !== $results ) { throw new RuntimeException( 'Concurrent stock-one invariant failed: ' . implode( ', ', $results ) ); }
	$count = (int) $db->query( "SELECT id FROM `{$reservations}`" )->num_rows;
	if ( 1 !== $count ) { throw new RuntimeException( 'Exactly one reservation must survive.' ); }
	echo "PASS: two independent connections cannot over-reserve stock one.\n";

	$db->query( "DELETE FROM `{$reservations}`" );
	$db->query( "UPDATE `{$posts}` SET stock = 2 WHERE ID = 1" );
	@unlink( $signal ); @unlink( $out1 ); @unlink( $out2 );
	$pid1 = pcntl_fork();
	if ( 0 === $pid1 ) { file_put_contents( $out1, attempt( 3, 1, $signal ) ); exit( 0 ); }
	$limit = microtime( true ) + 5;
	while ( ! is_file( $signal ) && microtime( true ) < $limit ) { usleep( 10000 ); }
	$pid2 = pcntl_fork();
	if ( 0 === $pid2 ) { file_put_contents( $out2, attempt( 4, 1 ) ); exit( 0 ); }
	pcntl_waitpid( $pid1, $status ); pcntl_waitpid( $pid2, $status );
	$results = array( trim( (string) file_get_contents( $out1 ) ), trim( (string) file_get_contents( $out2 ) ) );
	if ( array( 'reservation_acquired', 'reservation_acquired' ) !== $results || 2 !== $db->query( "SELECT id FROM `{$reservations}`" )->num_rows ) { throw new RuntimeException( 'Concurrent stock-two invariant failed.' ); }
	echo "PASS: two serialized connections can reserve within stock greater than one.\n";
} finally {
	$db->query( "DROP TABLE IF EXISTS `{$reservations}`" );
	$db->query( "DROP TABLE IF EXISTS `{$posts}`" );
	foreach ( array( $signal ?? '', $out1 ?? '', $out2 ?? '' ) as $file ) { if ( '' !== $file && is_file( $file ) ) { unlink( $file ); } }
}
