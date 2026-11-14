<?php
/** Bounded local retirement; checkpoint encoding keeps operational and validation positions distinct. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class CommerceResetRetirement {
	public function __construct( private \wpdb $db, private TableNames $tables, private CommerceResetRecord $operation ) {}
	/** Integer-only base36: PHP_INT_MAX uses 13 bytes, without float conversion. */
	public static function encode_cursor( int $value ): string {
		if ( $value < 0 ) { throw new \InvalidArgumentException( 'retirement_position' ); }
		$out = ''; do { $out = '0123456789abcdefghijklmnopqrstuvwxyz'[$value % 36] . $out; $value = intdiv( $value, 36 ); } while ( $value ); return $out;
	}
	public static function decode_cursor( string $value ): int {
		if ( ! preg_match( '/\A(?:0|[1-9a-z][0-9a-z]*)\z/', $value ) ) { throw new \InvalidArgumentException( 'retirement_position' ); }
		$out = 0;
		foreach ( str_split( $value ) as $char ) { $digit = strpos( '0123456789abcdefghijklmnopqrstuvwxyz', $char ); if ( $out > intdiv( PHP_INT_MAX - $digit, 36 ) ) { throw new \InvalidArgumentException( 'retirement_position' ); } $out = $out * 36 + $digit; } return $out;
	}
	/** r_<operational-stage>_<base36 operational cursor>_<unchanged A1 checkpoint>. */
	public static function revalidation( string $stage, int $cursor, string $validation ): string {
		$token = 'r_' . $stage . '_' . self::encode_cursor( $cursor ) . '_' . $validation;
		if ( ! in_array( $stage, array( 'c', 'e', 'i' ), true ) || ! CommerceResetLifecycle::token_valid( $token ) ) { throw new \InvalidArgumentException( 'retirement_position' ); } return $token;
	}
	public static function position( CommerceResetRecord $operation ): array {
		$cp = $operation->checkpoint();
		if ( null === $cp && 0 === $operation->cursor() ) { return array( 'retiring_claims' === $operation->phase() ? 'c' : 'e', 0, null ); }
		if ( in_array( $cp, array( 'b_c', 'b_e', 'b_i' ), true ) ) { return array( substr( $cp, 2 ), $operation->cursor(), null ); }
		if ( is_string( $cp ) && preg_match( '/\Ar_([cei])_([0-9a-z]+)_(v_[a-z0-9_]+)\z/', $cp, $parts ) ) { return array( $parts[1], self::decode_cursor( $parts[2] ), $parts[3] ); }
		throw new \InvalidArgumentException( 'retirement_position' );
	}
	private function rows( string $sql ): array {
		$this->db->last_error = ''; $rows = $this->db->get_results( $sql );
		if ( '' !== $this->db->last_error || ! is_array( $rows ) ) { throw new \RuntimeException( 'Retirement read failed.' ); } return $rows;
	}
	private function source( object $claim ): bool { return (string) $claim->epoch_id === (string) $this->operation->source_epoch_id() && $claim->installation_id === $this->operation->installation_id() && $claim->epoch_identity === $this->operation->source_epoch_identity(); }
	/** Caller already owns exact control/source/reset locks. Side effects share its transaction. */
	public function batch( string $stage, int $cursor, int $size, string $now ): array {
		$owned = TransactionManager::reset_context_on( $this->db );
		if ( null === $owned || ! $owned->operation()->equals( $this->operation ) || $this->operation->validation_generation() !== $this->operation->evidence_generation() || ! in_array( $this->operation->phase(), array( 'retiring_claims', 'terminalizing_events' ), true ) || $size < 1 || $size > 1000 || ! in_array( $stage, array( 'c', 'e', 'i' ), true ) || ( 'retiring_claims' === $this->operation->phase() ) !== ( 'c' === $stage ) ) { throw new \RuntimeException( 'Unowned retirement.' ); }
		if ( false === ( new CommerceResetRepository( $this->db, $this->tables ) )->find_for_maintenance( $owned ) ) { throw new \RuntimeException( 'Consumed retirement snapshot.' ); }
		[ $saved_stage, $saved_cursor, $validation ] = self::position( $this->operation );
		if ( null !== $validation || $saved_stage !== $stage || $saved_cursor !== $cursor ) { throw new \RuntimeException( 'Uncertified retirement position.' ); }
		$table = array( 'c'=>'provider_claims', 'e'=>'payment_events', 'i'=>'provider_identities' )[$stage];
		$rows = $this->rows( $this->db->prepare( 'SELECT * FROM ' . $this->tables->$table() . ' FORCE INDEX(PRIMARY) WHERE id>%d ORDER BY id LIMIT %d FOR UPDATE', $cursor, $size ) );
		$registry = new ProviderRegistry( $this->db, $this->tables );
		foreach ( $rows as $row ) {
			if ( 'c' === $stage && $this->source( $row ) ) {
				try { ProviderClaimRecord::from_row( $row ); } catch ( \Throwable $error ) { throw new \InvalidArgumentException( 'retirement_claim_shape' ); }
				if ( 'open' === $row->disposition && 1 !== $this->db->query( $this->db->prepare( "UPDATE " . $this->tables->provider_claims() . " SET disposition='retired',tombstoned_at=%s WHERE id=%d AND disposition='open' AND tombstoned_at IS NULL", $now, $row->id ) ) ) { throw new \RuntimeException( 'Retirement write failed.' ); }
			} elseif ( 'e' === $stage ) {
				// Receipt gap: derive the original claim only from immutable local evidence.
				$payload = json_decode( $row->normalized_payload ?? '', true );
				if ( is_array( $payload ) && ( $payload['installation_id'] ?? null ) === $this->operation->installation_id() && ( $payload['epoch_identity'] ?? null ) === $this->operation->source_epoch_identity() ) {
					if ( ! $registry->terminalize_reset_receipt( $this->operation, $row ) ) { throw new \RuntimeException( 'Event retirement failed.' ); }
				}
			} elseif ( 'i' === $stage && 'event' === $row->identity_kind ) {
				$claim = null === $row->claim_id ? null : $registry->claim_by_id( (int) $row->claim_id );
				if ( false === $claim ) { throw new \RuntimeException( 'Identity owner read failed.' ); }
				if ( (string) $row->observed_epoch_id === (string) $this->operation->source_epoch_id() || ( is_object( $claim ) && $this->source( $claim ) ) ) {
					if ( ! $registry->terminalize_reset_identity( $this->operation, $row ) ) { throw new \RuntimeException( 'Identity terminalization failed.' ); }
				}
			}
			$cursor = (int) $row->id;
		}
		if ( $rows ) { return array( $this->operation->phase(), 'b_' . $stage, $cursor ); }
		if ( 'c' === $stage ) { return array( 'terminalizing_events', null, 0 ); }
		if ( 'e' === $stage ) { return array( 'terminalizing_events', 'b_i', 0 ); }
		return array( 'terminalizing_events', 's1_s', 0 );
	}
}
