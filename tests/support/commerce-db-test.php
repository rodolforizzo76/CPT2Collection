<?php
/** Disposable database setup shared by the foundation suites. */
declare( strict_types=1 );
if ( ! extension_loaded( 'mysqli' ) || false === getenv( 'C2C_TEST_DB_NAME' ) ) { fwrite( STDERR, "C2C_TEST_DB_NAME and mysqli are required.\n" ); exit( 1 ); }
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );
$db_host = getenv( 'C2C_TEST_DB_HOST' ) ?: '127.0.0.1';
$db_port = (int) ( getenv( 'C2C_TEST_DB_PORT' ) ?: 3306 );
$db_user = getenv( 'C2C_TEST_DB_USER' ) ?: 'root';
$db_pass = getenv( 'C2C_TEST_DB_PASSWORD' ) ?: '';
$db_name = getenv( 'C2C_TEST_DB_NAME' );
mysqli_report( MYSQLI_REPORT_OFF );
require_once __DIR__ . '/mariadb-wpdb.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-table-names.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-commerce-foundation.php';
function check( bool $ok, string $label ): void { if ( ! $ok ) { throw new RuntimeException( $label ); } echo "PASS: $label\n"; }
function sql( wpdb $db, string $sql ): void { if ( false === $db->query( $sql ) ) { throw new RuntimeException( $db->last_error ); } }
