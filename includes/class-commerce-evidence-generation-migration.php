<?php
/** Restartable v14 reset evidence-generation foundation; no historical evidence backfill. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class CommerceEvidenceGenerationMigration {
	private const COLUMNS = array( 'evidence_generation', 'validation_generation' );
	public static function run( \wpdb $db ): bool {
		if ( ! $db instanceof CommerceConnection ) { return false; }
		$t = new TableNames( $db ); $tx = new TransactionManager( $db, $t );
		// Share the existing Phase 4 migration mutex, including across implicit DDL commits.
		$name = substr( 'c2c_phase4_cp1_' . hash( 'sha256', (string) $db->get_var( 'SELECT DATABASE()' ) . ':' . $db->prefix ), 0, 64 );
		if ( '1' !== (string) $db->get_var( $db->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) ) ) { return false; }
		try {
			if ( ! CommerceFoundation::verify_structure( $db, $t ) || ! self::verify_partial( $db, $t ) || ! $tx->begin() ) { return false; }
			$source = self::lock_source( $db, $t, array( 'ready', 'migrating_v14' ) );
			if ( null === $source || ! CommercePhaseTwoMigration::verify_structure( $db, $t ) || ! CommercePhaseThreeCheckpointOneMigration::verify_structure( $db, $t ) ) { $tx->rollback(); return false; }
			if ( false === $db->query( "UPDATE {$t->commerce_control()} SET state='migrating_v14' WHERE id=1" ) || ! $tx->commit() ) { return false; }
			foreach ( self::COLUMNS as $column ) {
				$db->last_error = '';
				$columns = $db->get_col( 'SHOW COLUMNS FROM ' . $t->commerce_resets() );
				if ( '' !== $db->last_error || ! is_array( $columns ) ) { return false; }
				if ( ! in_array( $column, $columns, true ) ) {
					$definition = 'evidence_generation' === $column ? 'BIGINT UNSIGNED NOT NULL DEFAULT 0' : 'BIGINT UNSIGNED NULL DEFAULT NULL';
					if ( false === $db->query( 'ALTER TABLE ' . $t->commerce_resets() . ' ADD ' . $column . ' ' . $definition ) ) { return false; }
				}
				if ( ! self::verify_partial( $db, $t ) ) { return false; }
			}
			if ( ! self::verify_structure( $db, $t ) || ! $tx->begin() ) { return false; }
			if ( self::lock_source( $db, $t, array( 'migrating_v14' ) ) !== $source || ! self::verify_structure( $db, $t ) ) { $tx->rollback(); return false; }
			if ( false === $db->query( "UPDATE {$t->commerce_control()} SET state='ready' WHERE id=1 AND state='migrating_v14'" ) ) { $tx->rollback(); return false; }
			return $tx->commit();
		} catch ( \Throwable $error ) {
			if ( $tx->is_active() ) { $tx->rollback(); }
			return false;
		} finally {
			if ( $tx->is_active() && ! $tx->is_outcome_uncertain() ) { $tx->rollback(); }
			if ( ! $tx->is_outcome_uncertain() ) { $db->get_var( $db->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
		}
	}
	public static function verify_structure( \wpdb $db, TableNames $t ): bool {
		return CommercePhaseFourCheckpointOneMigration::verify_structure( $db, $t, self::COLUMNS );
	}
	private static function verify_partial( \wpdb $db, TableNames $t ): bool {
		$db->last_error = '';
		$columns = $db->get_col( 'SHOW COLUMNS FROM ' . $t->commerce_resets() );
		return '' === $db->last_error && is_array( $columns ) && CommercePhaseFourCheckpointOneMigration::verify_structure( $db, $t, array_values( array_intersect( self::COLUMNS, $columns ) ) );
	}
	/** Control -> source epoch -> reset state. No running/blocked operation is migration recovery. */
	private static function lock_source( \wpdb $db, TableNames $t, array $states ): ?array {
		$db->last_error = '';
		$rows = $db->get_results( 'SELECT * FROM ' . $t->commerce_control() . ' ORDER BY id LIMIT 2 FOR UPDATE' );
		if ( '' !== $db->last_error || ! is_array( $rows ) || 1 !== count( $rows ) || '1' !== (string) $rows[0]->id || ! in_array( $rows[0]->state, $states, true ) ) { return null; }
		$c = $rows[0];
		if ( ! CommerceResetLifecycle::identity_valid( (string) $c->installation_id ) || ! ProviderClaimRecord::valid_date( $c->updated_at ) || false === filter_var( $c->current_epoch_id, FILTER_VALIDATE_INT, array( 'options'=>array( 'min_range'=>1 ) ) ) ) { return null; }
		$db->last_error = '';
		$epochs = $db->get_results( $db->prepare( 'SELECT * FROM ' . $t->commerce_epochs() . ' WHERE id=%d FOR UPDATE', $c->current_epoch_id ) );
		if ( '' !== $db->last_error || ! is_array( $epochs ) || 1 !== count( $epochs ) || null !== $epochs[0]->retired_at || ! CommerceResetLifecycle::identity_valid( (string) $epochs[0]->identity ) || ! ProviderClaimRecord::valid_date( $epochs[0]->created_at ) ) { return null; }
		$db->last_error = '';
		$active = $db->get_results( "SELECT id FROM {$t->commerce_resets()} WHERE active_slot IS NOT NULL OR status<>'completed' OR phase<>'completed' OR completed_at IS NULL OR target_epoch_id IS NULL OR error_code IS NOT NULL LIMIT 1 FOR UPDATE" );
		if ( '' !== $db->last_error || ! is_array( $active ) || $active ) { return null; }
		return array( (string) $c->installation_id, (string) $c->current_epoch_id, (string) $epochs[0]->identity );
	}
}
