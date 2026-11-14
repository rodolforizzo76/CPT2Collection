<?php
/** Versioned sealed frontier and bounded stock-neutral operational erasure. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class CommerceResetDeletion {
	/** Installation-wide physical domain, including NULL/older epoch history. */
	public const STAGES = array( 'e'=>'payment_events', 's'=>'payment_sessions', 'r'=>'reservations', 'j'=>'order_items', 'o'=>'orders', 'k'=>'cart_items', 'a'=>'carts' );
	public function __construct( private \wpdb $db, private TableNames $tables, private CommerceResetRecord $operation ) {}

	/** z1_<erasure stage>_<base36 physical frontier>_<retained position>; cursor is retained-only. */
	public static function position( CommerceResetRecord $operation ): ?array {
		$cp = $operation->checkpoint();
		if ( ! in_array( $operation->phase(), array( 'deleting', 'finalizing' ), true ) || ! is_string( $cp ) || ! preg_match( '/\Az1_([esrjokaxn])_([0-9a-z]+)_(c|d|i|dc_[0-9a-z]+|done)\z/', $cp, $parts ) ) { return null; }
		try {
			$physical = CommerceResetRetirement::decode_cursor( $parts[2] );
			if ( str_starts_with( $parts[3], 'dc_' ) ) { CommerceResetRetirement::decode_cursor( substr( $parts[3], 3 ) ); }
		} catch ( \InvalidArgumentException $error ) { return null; }
		if ( ( in_array( $parts[1], array( 'x', 'n' ), true ) && 0 !== $physical ) || ( 'done' === $parts[3] && 0 !== $operation->cursor() ) ) { return null; }
		return array( $parts[1], $physical, $parts[3] );
	}
	public static function prefix( string $stage, int $physical ): string {
		return 'z1_' . $stage . '_' . CommerceResetRetirement::encode_cursor( $physical ) . '_';
	}
	/** Durable empty fact and freshness are separate: generation changes preserve n. */
	public static function operational_empty( CommerceResetRecord $operation ): bool {
		return 'n' === ( self::position( $operation )[0] ?? null );
	}
	public static function certified_empty( CommerceResetRecord $operation ): bool {
		return 'running' === $operation->status() && self::operational_empty( $operation ) && 'done' === self::position( $operation )[2] && $operation->evidence_generation() === $operation->validation_generation();
	}
	private function rows( string $query ): array {
		$this->db->last_error = ''; $rows = $this->db->get_results( $query );
		if ( ! is_array( $rows ) || '' !== $this->db->last_error ) { throw new \RuntimeException( 'Operational deletion read failed.' ); }
		return $rows;
	}
	/** At most one indexed physical row, never COUNT or a residual business predicate. */
	private function empty( string $method ): bool {
		return ! $this->rows( 'SELECT id FROM ' . $this->tables->$method() . ' FORCE INDEX(PRIMARY) ORDER BY id LIMIT 1 FOR UPDATE' );
	}
	public function batch( int $size ): string {
		$owned = TransactionManager::reset_deletion_on( $this->db );
		$position = self::position( $this->operation );
		if ( null === $owned || ! $owned->operation()->equals( $this->operation ) || null === $position || 'running' !== $this->operation->status() || 'deleting' !== $this->operation->phase() || $size < 1 || $size > 1000 || $this->operation->validation_generation() !== $this->operation->evidence_generation() || 'done' !== $position[2] || false === ( new CommerceResetRepository( $this->db, $this->tables ) )->find_for_maintenance( $owned ) ) { throw new \RuntimeException( 'Uncertified operational deletion.' ); }
		[ $stage, $physical ] = $position;
		if ( in_array( $stage, array( 'x', 'n' ), true ) ) {
			foreach ( self::STAGES as $method ) { if ( ! $this->empty( $method ) ) { throw new \InvalidArgumentException( 'deletion_residual_' . $method ); } }
			return 'z1_n_0_done';
		}
		$table = $this->tables->{self::STAGES[$stage]}();
		// Authority prevents reinsertion/PK mutation; detect adversarial behind/boundary rows anyway.
		if ( $this->rows( $this->db->prepare( 'SELECT id FROM ' . $table . ' FORCE INDEX(PRIMARY) WHERE id<=%d ORDER BY id LIMIT 1 FOR UPDATE', $physical ) ) ) { throw new \InvalidArgumentException( 'deletion_behind_' . self::STAGES[$stage] ); }
		$rows = $this->rows( $this->db->prepare( 'SELECT id FROM ' . $table . ' FORCE INDEX(PRIMARY) WHERE id>%d ORDER BY id LIMIT %d FOR UPDATE', $physical, $size ) );
		if ( ! $rows ) {
			if ( ! $this->empty( self::STAGES[$stage] ) ) { throw new \InvalidArgumentException( 'deletion_residual_' . self::STAGES[$stage] ); }
			$stages = array_keys( self::STAGES ); $next = $stages[array_search( $stage, $stages, true ) + 1] ?? 'x';
			return self::prefix( $next, 0 ) . 'done';
		}
		// Validate the entire bounded ID list before any DELETE; integer encoding never uses floats.
		$ids = array(); $last = $physical;
		foreach ( $rows as $row ) {
			$id = CommerceResetRetainedEvidence::integer( $row->id, 1 );
			if ( $id <= $last ) { throw new \InvalidArgumentException( 'deletion_pk_order' ); }
			$ids[] = $id; $last = $id;
		}
		foreach ( $ids as $id ) {
			// Unique equality avoids optimizer-dependent IN/range deletion plans at large batch sizes.
			if ( 1 !== $this->db->query( $this->db->prepare( 'DELETE FROM ' . $table . ' WHERE id=%d', $id ) ) ) { throw new \RuntimeException( 'Operational deletion write failed.' ); }
		}
		return self::prefix( $stage, $last ) . 'done';
	}
}
