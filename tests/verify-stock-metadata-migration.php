<?php
/**
 * Isolated verification for the legacy stock metadata backfill.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

class wpdb {
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	/** @var array<int,array{ID:int,post_type:string}> */
	public array $post_rows = array();
	/** @var array<int,array{post_id:int,meta_key:string,meta_value:string}> */
	public array $meta_rows = array();
	public bool $lock_available = true;
	/** @var string[] */
	public array $lock_queries = array();

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query       = (string) preg_replace( '/%[ds]/', $replacement, $query, 1 );
		}
		return $query;
	}

	public function get_var( string $query ): ?string {
		$this->lock_queries[] = $query;
		if ( str_contains( $query, 'GET_LOCK' ) ) {
			return $this->lock_available ? '1' : '0';
		}
		return str_contains( $query, 'RELEASE_LOCK' ) ? '1' : null;
	}

	public function query( string $query ): int|false {
		if ( ! preg_match( "/SELECT p.ID, '([^']+)', '([^']+)'.*p.post_type = '([^']+)'/s", $query, $matches ) ) {
			return false;
		}

		$count = 0;
		foreach ( $this->post_rows as $post ) {
			if ( $post['post_type'] !== $matches[3] || $this->has_meta( $post['ID'], $matches[1] ) ) {
				continue;
			}
			$this->meta_rows[] = array( 'post_id' => $post['ID'], 'meta_key' => $matches[1], 'meta_value' => $matches[2] );
			$count++;
		}
		return $count;
	}

	private function has_meta( int $post_id, string $key ): bool {
		foreach ( $this->meta_rows as $row ) {
			if ( $post_id === $row['post_id'] && $key === $row['meta_key'] ) {
				return true;
			}
		}
		return false;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-meta-keys.php';
require_once dirname( __DIR__ ) . '/includes/class-catalog.php';
require_once dirname( __DIR__ ) . '/includes/class-stock-metadata-migration.php';

use RodolfoRizzo\Cpt2Collection\MetaKeys;
use RodolfoRizzo\Cpt2Collection\StockMetadataMigration;

function check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
	echo "PASS: {$message}\n";
}

$db = new wpdb();
$db->post_rows = array(
	array( 'ID' => 10, 'post_type' => 'artwork' ),
	array( 'ID' => 11, 'post_type' => 'artwork' ),
	array( 'ID' => 12, 'post_type' => 'artwork' ),
	array( 'ID' => 13, 'post_type' => 'artwork' ),
	array( 'ID' => 20, 'post_type' => 'book' ),
);
$db->meta_rows = array(
	1 => array( 'post_id' => 11, 'meta_key' => MetaKeys::STOCK_QUANTITY, 'meta_value' => '0' ),
	2 => array( 'post_id' => 12, 'meta_key' => MetaKeys::STOCK_QUANTITY, 'meta_value' => '1' ),
	3 => array( 'post_id' => 13, 'meta_key' => MetaKeys::STOCK_QUANTITY, 'meta_value' => '7' ),
);

check( ! StockMetadataMigration::migrate( $db, '' ), 'migration remains pending without a selected CPT' );
check( array() === $db->lock_queries, 'missing CPT does not acquire the migration lock' );

$db->lock_available = false;
check( ! StockMetadataMigration::migrate( $db, 'artwork' ), 'concurrent lock contention leaves migration pending' );
check( 3 === count( $db->meta_rows ), 'lock contention inserts no metadata' );
$db->lock_available = true;

check( StockMetadataMigration::migrate( $db, 'artwork' ), 'migration succeeds' );
$stock = static fn( int $post_id ): array => array_values( array_filter( $db->meta_rows, static fn( array $row ): bool => $post_id === $row['post_id'] && MetaKeys::STOCK_QUANTITY === $row['meta_key'] ) );
check( 1 === count( $stock( 10 ) ) && '1' === $stock( 10 )[0]['meta_value'], 'missing stock gets exactly one default row' );
check( 1 === count( $stock( 11 ) ) && '0' === $stock( 11 )[0]['meta_value'], 'existing zero stock is unchanged' );
check( 1 === count( $stock( 12 ) ) && '1' === $stock( 12 )[0]['meta_value'], 'existing default stock is unchanged' );
check( 1 === count( $stock( 13 ) ) && '7' === $stock( 13 )[0]['meta_value'], 'existing positive stock is unchanged' );
check( array() === $stock( 20 ), 'unrelated post type is untouched' );
check( str_contains( $db->lock_queries[1], 'GET_LOCK' ) && str_contains( $db->lock_queries[2], 'RELEASE_LOCK' ), 'migration holds and releases its advisory lock around the insert' );

$after_first_run = $db->meta_rows;
check( StockMetadataMigration::migrate( $db, 'artwork' ), 'second migration succeeds' );
check( $after_first_run === $db->meta_rows, 'second migration creates no duplicates or changes' );

echo "Stock metadata migration verification passed.\n";
