<?php
/** Isolated schema migration verification; no WordPress installation or database required. */

declare( strict_types=1 );

$fixture = sys_get_temp_dir() . '/c2c-schema-' . bin2hex( random_bytes( 8 ) );
mkdir( $fixture . '/wp-admin/includes', 0700, true );
file_put_contents( $fixture . '/wp-admin/includes/upgrade.php', "<?php // dbDelta is supplied by the isolated test.\n" );
register_shutdown_function( static function () use ( $fixture ): void {
	unlink( $fixture . '/wp-admin/includes/upgrade.php' );
	rmdir( $fixture . '/wp-admin/includes' );
	rmdir( $fixture . '/wp-admin' );
	rmdir( $fixture );
} );
define( 'ABSPATH', $fixture . '/' );

require_once __DIR__ . '/support/commerce-fixtures.php';
class wpdb implements \RodolfoRizzo\Cpt2Collection\CommerceConnection {
	use TestCommerceConnection;
	public array $foundation = [];
	public string $last_error = '';
	public function insert(string $table,array $data,array $formats):int { $this->foundation[$table][]=(object)$data; return 1; }
	public function get_results(string $sql):array {
		if(str_contains($sql,'information_schema.TABLES'))return [(object)['TABLE_NAME'=>'test_c2c_commerce_control','ENGINE'=>'InnoDB'],(object)['TABLE_NAME'=>'test_c2c_commerce_epochs','ENGINE'=>'InnoDB']];
		if(preg_match('/SHOW FULL COLUMNS FROM `([^`]+)`/',$sql,$m))return array_map(fn($c)=>(object)['Field'=>$c,'Type'=>match($c){'id'=>str_contains($m[1],'control')?'tinyint unsigned':'bigint unsigned','current_epoch_id'=>'bigint unsigned','identity','installation_id'=>'char(32)','state'=>'varchar(16)',default=>'datetime'},'Null'=>$c==='retired_at'?'YES':'NO','Collation'=>in_array($c,['identity','installation_id','state'])?'ascii_bin':null],$this->columns[$m[1]]??[]);
		if(str_contains($sql,'SHOW INDEX'))return [(object)['Non_unique'=>0,'Key_name'=>'PRIMARY','Seq_in_index'=>1,'Column_name'=>'id'],(object)['Non_unique'=>0,'Key_name'=>'identity','Seq_in_index'=>1,'Column_name'=>'identity']];
		if(preg_match('/FROM (test_c2c_commerce_\w+)/',$sql,$m))return $this->foundation[$m[1]]??[];
		throw new RuntimeException('Unexpected '.$sql);
	}

	public string $prefix = 'test_';
	public string $posts = 'test_posts';
	public string $postmeta = 'test_postmeta';
	public array $columns = array();
	public ?string $missing_column = null;
	public ?string $missing_table = null;
	public bool $stock_succeeds = true;
	public bool $column_query_fails = false;
	public int $delta_calls = 0;

	public function get_charset_collate(): string { return ''; }
	public function prepare( string $sql, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$sql = (string) preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'", $sql, 1 );
		}
		return $sql;
	}
	public function get_var( string $sql ): ?string {
		if ($sql==='SELECT DATABASE()')return 'test';
		if ( preg_match( "/^SHOW TABLES LIKE '([^']+)'$/", $sql, $match ) ) {
			return isset( $this->columns[ $match[1] ] ) ? $match[1] : null;
		}
		if ( str_contains( $sql, 'GET_LOCK' ) || str_contains( $sql, 'RELEASE_LOCK' ) ) { return '1'; }
		throw new RuntimeException( 'Unexpected query: ' . $sql );
	}
	public function get_col( string $sql ): ?array {
		if ( ! preg_match( '/^SHOW COLUMNS FROM `([^`]+)`$/', $sql, $match ) ) {
			throw new RuntimeException( 'Unexpected query: ' . $sql );
		}
		return $this->column_query_fails ? null : ( $this->columns[ $match[1] ] ?? array() );
	}
	public function query( string $sql ): int|false {
		if (in_array($sql,['START TRANSACTION','COMMIT','ROLLBACK'],true))return 0;
		if ( ! str_starts_with( $sql, 'INSERT INTO test_postmeta ' ) ) { throw new RuntimeException( 'Unexpected query: ' . $sql ); }
		return $this->stock_succeeds ? 0 : false;
	}
}

/** Models dbDelta applying the supplied DDL, optionally failing to add one column/table. */
function dbDelta( string $sql ): array {
	global $wpdb;
	$wpdb->delta_calls++;
	if ( ! preg_match( '/CREATE TABLE (\w+) \(/', $sql, $table ) ) { throw new RuntimeException( 'Unexpected DDL' ); }
	if ( $table[1] === $wpdb->missing_table ) { return array(); }
	preg_match_all( '/^\s+(\w+)\s+(?:BIGINT|INT|TINYINT|CHAR|VARCHAR|DATETIME|TEXT|LONGTEXT)\b/m', $sql, $columns );
	$wpdb->columns[ $table[1] ] = array_values( array_filter( $columns[1], static fn( string $column ): bool => $table[1] . '.' . $column !== $wpdb->missing_column ) );
	return array();
}
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['options'][ $name ] ?? $default; }
function update_option( string $name, mixed $value, bool $autoload = true ): bool {
	$GLOBALS['option_updates']++;
	$GLOBALS['options'][ $name ] = $value;
	return true;
}
foreach ( array( 'table-names', 'post-types', 'meta-keys', 'catalog', 'stock-metadata-migration', 'database' ) as $class ) {
	require_once dirname( __DIR__ ) . '/includes/class-' . $class . '.php';
}

use RodolfoRizzo\Cpt2Collection\Database;

function check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $message ); }
	echo "PASS: {$message}\n";
}
function reset_migration(): void {
	$GLOBALS['wpdb'] = new wpdb();
	$GLOBALS['options'] = array( Database::OPTION_SCHEMA_VERSION => '0.8.0', 'cpt2collection_post_type' => 'artwork' );
	$GLOBALS['option_updates'] = 0;
}

reset_migration();
Database::maybe_upgrade();
check( '0.10.0' === get_option( Database::OPTION_SCHEMA_VERSION ) && 1 === $option_updates, 'complete schema and stock migration advance the version' );
$calls = $wpdb->delta_calls;
Database::maybe_upgrade();
check( $calls === $wpdb->delta_calls, 'completed migration is not repeated' );

foreach ( array(
	'test_c2c_cart_items.content_version',
	'test_c2c_orders.cart_reconciliation_status',
	'test_c2c_orders.cart_reconciliation_code',
	'test_c2c_orders.cart_reconciliation_updated_at',
	'test_c2c_order_items.source_cart_item_id',
	'test_c2c_order_items.source_cart_item_version',
) as $missing ) {
	reset_migration();
	$wpdb->missing_column = $missing;
	Database::maybe_upgrade();
	check( 10 === count( $wpdb->columns ), 'all tables exist despite the missing column' );
	check( '0.8.0' === get_option( Database::OPTION_SCHEMA_VERSION ) && 0 === $option_updates, "missing {$missing} prevents version advancement" );
	$wpdb->missing_column = null;
	$calls = $wpdb->delta_calls;
	Database::maybe_upgrade();
	check( $wpdb->delta_calls > $calls && '0.10.0' === get_option( Database::OPTION_SCHEMA_VERSION ), 'a subsequent request retries and completes the migration' );
}

foreach ( array( 'missing_table', 'stock_succeeds', 'column_query_fails' ) as $failure ) {
	reset_migration();
	$wpdb->$failure = match ( $failure ) {
		'missing_table' => 'test_c2c_payment_events',
		'stock_succeeds' => false,
		'column_query_fails' => true,
	};
	if ('stock_succeeds'===$failure) $GLOBALS['options'][Database::OPTION_SCHEMA_VERSION]='0.7.0';
	Database::maybe_upgrade();
	check( ('stock_succeeds'===$failure?'0.7.0':'0.8.0') === get_option( Database::OPTION_SCHEMA_VERSION ) && 0 === $option_updates, "{$failure} prevents version advancement" );
}
echo "Database migration verification passed.\n";
