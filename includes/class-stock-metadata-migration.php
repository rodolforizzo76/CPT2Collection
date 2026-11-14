<?php
/**
 * Legacy stock metadata migration.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Backfills explicit stock metadata for legacy collection posts.
 */
final class StockMetadataMigration {
	private const LOCK_NAME    = 'cpt2collection_stock_metadata_0_7_1';
	private const LOCK_TIMEOUT = 10;

	/**
	 * Adds the catalog default only where the selected CPT has no stock row.
	 */
	public static function migrate( wpdb $wpdb, string $post_type ): bool {
		if ( '' === $post_type ) {
			return false;
		}

		$locked = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK_NAME, self::LOCK_TIMEOUT )
		);
		if ( '1' !== (string) $locked ) {
			return false;
		}

		try {
			$result = $wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . $wpdb->postmeta . ' (post_id, meta_key, meta_value)
					SELECT p.ID, %s, %s
					FROM ' . $wpdb->posts . ' p
					WHERE p.post_type = %s
					AND NOT EXISTS (
						SELECT 1 FROM ' . $wpdb->postmeta . ' pm
						WHERE pm.post_id = p.ID AND pm.meta_key = %s
					)',
					MetaKeys::STOCK_QUANTITY,
					(string) Catalog::DEFAULT_STOCK_QUANTITY,
					$post_type,
					MetaKeys::STOCK_QUANTITY
				)
			);

			return false !== $result;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK_NAME ) );
		}
	}
}
