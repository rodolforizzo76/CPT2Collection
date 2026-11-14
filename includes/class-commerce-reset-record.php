<?php
/** Immutable durable reset-operation snapshot; no transition or execution behavior. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class CommerceResetRecord {
	private function __construct( private array $data ) {}

	public static function from_row( object $row ): self {
		$data = get_object_vars( $row );
		$required = array( 'id', 'operation_identity', 'installation_id', 'source_epoch_id', 'source_epoch_identity', 'target_epoch_identity', 'target_epoch_id', 'active_slot', 'status', 'phase', 'checkpoint', 'cursor', 'revision', 'evidence_generation', 'validation_generation', 'requested_by', 'error_code', 'started_at', 'updated_at', 'completed_at' );
		if ( array_diff( $required, array_keys( $data ) ) ) { throw new \InvalidArgumentException( 'Incomplete reset record.' ); }
		$data['id'] = self::integer( $data['id'], false, 1 );
		if ( ! is_string( $data['operation_identity'] ) || 1 !== preg_match( '/\A[0-9a-f]{32}\z/', $data['operation_identity'] ) ) { throw new \InvalidArgumentException( 'Invalid reset identity.' ); }
		if ( ! is_string( $data['installation_id'] ) || 1 !== preg_match( '/\A[0-9a-f]{32}\z/', $data['installation_id'] ) ) { throw new \InvalidArgumentException( 'Invalid reset identity.' ); }
		$data['source_epoch_id'] = self::integer( $data['source_epoch_id'], false, 1 );
		if ( ! is_string( $data['source_epoch_identity'] ) || 1 !== preg_match( '/\A[0-9a-f]{32}\z/', $data['source_epoch_identity'] ) ) { throw new \InvalidArgumentException( 'Invalid reset identity.' ); }
		if ( ! is_string( $data['target_epoch_identity'] ) || 1 !== preg_match( '/\A[0-9a-f]{32}\z/', $data['target_epoch_identity'] ) ) { throw new \InvalidArgumentException( 'Invalid reset identity.' ); }
		$data['target_epoch_id'] = self::integer( $data['target_epoch_id'], true, 1 );
		$data['active_slot'] = self::integer( $data['active_slot'], true, 1 );
		self::token( $data['status'], 32, false );
		self::token( $data['phase'], 32, false );
		self::token( $data['checkpoint'], 64, true );
		$data['cursor'] = self::integer( $data['cursor'], false, 0 );
		$data['revision'] = self::integer( $data['revision'], false, 0 );
		$data['evidence_generation'] = self::integer( $data['evidence_generation'], false, 0 );
		$data['validation_generation'] = self::integer( $data['validation_generation'], true, 0 );
		$data['requested_by'] = self::integer( $data['requested_by'], true, 1 );
		self::token( $data['error_code'], 64, true );
		self::date( $data['started_at'], false );
		self::date( $data['updated_at'], false );
		self::date( $data['completed_at'], true );
		if ( null !== $data['active_slot'] && 1 !== $data['active_slot'] ) { throw new \InvalidArgumentException( 'Invalid reset active slot.' ); }
		if ( $data['source_epoch_identity'] === $data['target_epoch_identity'] || $data['source_epoch_id'] === $data['target_epoch_id'] ) { throw new \InvalidArgumentException( 'Invalid reset target.' ); }
		return new self( array_intersect_key( $data, array_flip( $required ) ) );
	}

	private static function integer( mixed $value, bool $nullable, int $minimum ): ?int {
		if ( null === $value && $nullable ) { return null; }
		if ( is_string( $value ) && 1 === preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) ) { $value = filter_var( $value, FILTER_VALIDATE_INT ); }
		if ( ! is_int( $value ) || $value < $minimum ) { throw new \InvalidArgumentException( 'Invalid reset integer.' ); }
		return $value;
	}
	private static function token( mixed $value, int $limit, bool $nullable ): void {
		if ( null === $value && $nullable ) { return; }
		if ( ! is_string( $value ) || strlen( $value ) > $limit || 1 !== preg_match( '/\A[a-z][a-z0-9_]*\z/', $value ) ) { throw new \InvalidArgumentException( 'Invalid reset machine code.' ); }
	}
	private static function date( mixed $value, bool $nullable ): void {
		if ( null === $value && $nullable ) { return; }
		if ( ! is_string( $value ) ) { throw new \InvalidArgumentException( 'Invalid reset timestamp.' ); }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $value || '0000' === substr( $value, 0, 4 ) ) { throw new \InvalidArgumentException( 'Invalid reset timestamp.' ); }
	}
	/** Exact immutable snapshot comparison, including expected phase and revision. */
	public function equals( self $other ): bool {
		foreach ( $this->data as $key => $value ) { if ( $value !== $other->data[$key] ) { return false; } }
		return true;
	}
	/** Exact pre-final snapshot -> one canonical completion; no arbitrary stale retry. */
	public function completion_of( self $expected ): bool {
		if ( ! CommerceResetLifecycle::final_entry( $expected ) || 'completed' !== $this->status() || 'completed' !== $this->phase() || 'z1_n_0_done' !== $this->checkpoint() || 0 !== $this->cursor() || $expected->revision() === PHP_INT_MAX || $this->revision() !== $expected->revision() + 1 || null === $this->target_epoch_id() || null !== $this->active_slot() || null === $this->completed_at() || $this->updated_at() !== $this->completed_at() || $this->completed_at() < $expected->updated_at() ) { return false; }
		foreach ( $this->data as $key => $value ) {
			if ( ! in_array( $key, array( 'status', 'phase', 'target_epoch_id', 'active_slot', 'completed_at', 'updated_at', 'revision' ), true ) && $value !== $expected->data[$key] ) { return false; }
		}
		return true;
	}
	public function id(): int { return $this->data['id']; }
	public function operation_identity(): string { return $this->data['operation_identity']; }
	public function installation_id(): string { return $this->data['installation_id']; }
	public function source_epoch_id(): int { return $this->data['source_epoch_id']; }
	public function source_epoch_identity(): string { return $this->data['source_epoch_identity']; }
	public function target_epoch_identity(): string { return $this->data['target_epoch_identity']; }
	public function target_epoch_id(): ?int { return $this->data['target_epoch_id']; }
	public function active_slot(): ?int { return $this->data['active_slot']; }
	public function status(): string { return $this->data['status']; }
	public function phase(): string { return $this->data['phase']; }
	public function checkpoint(): ?string { return $this->data['checkpoint']; }
	public function cursor(): int { return $this->data['cursor']; }
	public function evidence_generation(): int { return $this->data['evidence_generation']; }
	public function validation_generation(): ?int { return $this->data['validation_generation']; }
	public function revision(): int { return $this->data['revision']; }
	public function requested_by(): ?int { return $this->data['requested_by']; }
	public function error_code(): ?string { return $this->data['error_code']; }
	public function started_at(): string { return $this->data['started_at']; }
	public function updated_at(): string { return $this->data['updated_at']; }
	public function completed_at(): ?string { return $this->data['completed_at']; }
}
