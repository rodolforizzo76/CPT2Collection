<?php
/** Additive v17 bounded modern source lookup; no data or admission-state mutation. */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class CommerceOrderSourceIndexMigration {
	public const INDEX = 'order_source';
	/** Absent is accepted only before DDL; a same-name malformed index never is. */
	public static function verify_structure( \wpdb $db, TableNames $tables, bool $allow_absent = false ): bool {
		$db->last_error = '';
		$engine = $db->get_var( $db->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $tables->order_items() ) );
		if ( '' !== $db->last_error || ! is_string( $engine ) || 'INNODB' !== strtoupper( $engine ) ) { return false; }
		$rows = $db->get_results( 'SHOW INDEX FROM ' . $tables->order_items() );
		if ( '' !== $db->last_error || ! is_array( $rows ) ) { return false; }
		$parts = array();
		foreach ( $rows as $row ) {
			if ( self::INDEX !== $row->Key_name ) { continue; }
			if ( '1' !== (string) $row->Non_unique || null !== $row->Sub_part || 'BTREE' !== $row->Index_type || 'A' !== $row->Collation || ( isset( $row->Ignored ) && 'NO' !== $row->Ignored ) ) { return false; }
			$parts[(int) $row->Seq_in_index] = $row->Column_name;
		}
		ksort( $parts );
		return ( $allow_absent && array() === $parts ) || $parts === array( 1 => 'order_id', 2 => 'source_cart_item_id' );
	}
	public static function run( \wpdb $db ): bool {
		if ( ! $db instanceof CommerceConnection || ! $db->transaction_connection_usable() ) { return false; }
		$t = new TableNames( $db ); $tx = new TransactionManager( $db, $t );
		$name = substr( 'c2c_phase4_cp1_' . hash( 'sha256', (string) $db->get_var( 'SELECT DATABASE()' ) . ':' . $db->prefix ), 0, 64 );
		if ( '1' !== (string) $db->get_var( $db->prepare( 'SELECT GET_LOCK(%s,10)', $name ) ) ) { return false; }
		try {
			if ( ! CommerceFoundation::verify_structure( $db, $t ) || ! CommercePhaseTwoMigration::verify_structure( $db, $t ) || ! CommercePhaseThreeCheckpointOneMigration::verify_structure( $db, $t ) || ! CommerceEvidenceGenerationMigration::verify_structure( $db, $t ) || ! CommerceBaselineMigration::verify_structure( $db, $t ) || ! CommerceBaselinePurgeMigration::verify_structure( $db, $t ) || ! self::verify_structure( $db, $t, true ) ) { return false; }
			// Like v16 additive storage, this nonunique index grants no new authority.
			// The migration mutex spans DDL; InnoDB metadata locks protect installation.
			// Existing ready/pending/resetting fences remain untouched throughout.
			if ( ! self::inspect( $db, $t, $tx ) ) { return false; }
			if ( ! self::verify_structure( $db, $t ) ) {
				if ( '' !== $db->last_error || false === $db->query( 'ALTER TABLE ' . $t->order_items() . ' ADD KEY ' . self::INDEX . ' (order_id, source_cart_item_id)' ) ) { return false; }
			}
			return self::verify_structure( $db, $t ) && self::inspect( $db, $t, $tx );
		} catch ( \Throwable $error ) { return false; }
		finally {
			if ( $tx->is_active() && ! $tx->is_outcome_uncertain() ) { $tx->rollback(); }
			if ( ! $tx->is_outcome_uncertain() ) { $db->get_var( $db->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
		}
	}
	private static function inspect( \wpdb $db, TableNames $t, TransactionManager $tx ): bool {
		if ( ! $tx->begin() || null === ( new CommerceBaseline( $db, $t ) )->read( 'FOR UPDATE' ) ) { return false; }
		$row = $db->get_row( 'SELECT baseline_purge FROM ' . $t->commerce_control() . ' WHERE id=1 FOR UPDATE' );
		if ( '' !== $db->last_error || ! is_object( $row ) ) { return false; }
		if ( null !== $row->baseline_purge ) { CommerceBaselinePurgeRecord::from_json( $row->baseline_purge ); }
		return $tx->commit();
	}
}
