<?php
/** Immutable attributable claim snapshot; not transaction authority. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-payment-provider-session-request.php';
require_once __DIR__ . '/class-payment-provider-line-item.php';
require_once __DIR__ . '/class-money.php';

final class ProviderClaimRecord {
	private function __construct( private array $data ) {}
	public static function from_row( object $row ): self {
		$data = get_object_vars( $row );
		$fields = array( 'id', 'installation_id', 'epoch_id', 'epoch_identity', 'provider', 'reference', 'order_id', 'payment_session_id', 'creation_key_hash', 'idempotency_key', 'request_version', 'request_snapshot', 'environment', 'evidence_state', 'disposition', 'created_at', 'tombstoned_at' );
		if ( array_diff( $fields, array_keys( $data ) ) ) { throw new \InvalidArgumentException( 'Incomplete provider claim.' ); }
		foreach ( array( 'id', 'epoch_id', 'order_id', 'payment_session_id', 'request_version' ) as $key ) {
			$value = $data[$key];
			if ( is_string( $value ) && preg_match( '/\A[1-9][0-9]*\z/', $value ) ) { $value = filter_var( $value, FILTER_VALIDATE_INT ); }
			if ( ! is_int( $value ) || $value < 1 ) { throw new \InvalidArgumentException( 'Invalid claim integer.' ); }
			$data[$key] = $value;
		}
		foreach ( array( 'installation_id'=>32, 'epoch_identity'=>32, 'reference'=>32, 'creation_key_hash'=>64 ) as $key=>$length ) {
			if ( ! is_string( $data[$key] ) || ! preg_match( '/\A[0-9a-f]{' . $length . '}\z/', $data[$key] ) ) { throw new \InvalidArgumentException( 'Invalid claim identity.' ); }
		}
		if ( ! is_string( $data['provider'] ) || ! preg_match( '/\A[a-z0-9_]{1,32}\z/', $data['provider'] ) || $data['idempotency_key'] !== 'c2c_ps_' . $data['reference'] || ! in_array( $data['environment'], array( 'test', 'live' ), true ) ) { throw new \InvalidArgumentException( 'Unproven claim provider/environment.' ); }
		if ( ! in_array( $data['disposition'], array( 'open', 'deleted', 'retired' ), true ) || ! self::valid_date( $data['created_at'] ) || ( null !== $data['tombstoned_at'] && ! self::valid_date( $data['tombstoned_at'] ) ) || ( 'open' === $data['disposition'] ) !== ( null === $data['tombstoned_at'] ) || ( null !== $data['tombstoned_at'] && $data['tombstoned_at'] < $data['created_at'] ) ) { throw new \InvalidArgumentException( 'Invalid claim lifecycle.' ); }
		if ( 'native' !== $data['evidence_state'] ) { throw new \InvalidArgumentException( 'Invalid modern evidence state.' ); }
		if ( 2 !== $data['request_version'] || ! is_string( $data['request_snapshot'] ) ) { throw new \InvalidArgumentException( 'Missing native request evidence.' ); }
		$request = PaymentProviderSessionRequest::from_snapshot( $data['request_snapshot'] );
		$config = $request->configuration();
		if ( $request->reference() !== $data['reference'] || $request->idempotency_key() !== $data['idempotency_key'] || ( $config['environment'] ?? null ) !== $data['environment'] || ( $config['installation_id'] ?? null ) !== $data['installation_id'] || ( $config['epoch_identity'] ?? null ) !== $data['epoch_identity'] ) { throw new \InvalidArgumentException( 'Contradictory native request evidence.' ); }
		return new self( array_intersect_key( $data, array_flip( $fields ) ) );
	}
	public static function valid_date( mixed $value ): bool {
		if ( ! is_string( $value ) ) { return false; }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value && '0000' !== substr( $value, 0, 4 );
	}
	public function equals( self $other ): bool { foreach ( $this->data as $key=>$value ) { if ( $value !== $other->data[$key] ) { return false; } } return true; }
	/** Network-gap correlation ignores lifecycle changes, never immutable request facts. */
	public function same_request_as( self $other ): bool {
		foreach ( $this->data as $key=>$value ) { if ( ! in_array($key,array('disposition','tombstoned_at'),true) && $value !== $other->data[$key] ) return false; }
		return true;
	}
	public function creation_key_hash(): string { return $this->data['creation_key_hash']; }
	public function idempotency_key(): string { return $this->data['idempotency_key']; }
	public function request_snapshot(): string { return $this->data['request_snapshot']; }
	public function order_id(): int { return $this->data['order_id']; }
	public function payment_session_id(): int { return $this->data['payment_session_id']; }
	public function disposition(): string { return $this->data['disposition']; }
	public function tombstoned_at(): ?string { return $this->data['tombstoned_at']; }
	public function id(): int { return $this->data['id']; }
	public function installation_id(): string { return $this->data['installation_id']; }
	public function epoch_id(): int { return $this->data['epoch_id']; }
	public function epoch_identity(): string { return $this->data['epoch_identity']; }
	public function provider(): string { return $this->data['provider']; }
	public function reference(): string { return $this->data['reference']; }
	public function environment(): string { return $this->data['environment']; }
	public function created_at(): string { return $this->data['created_at']; }
}
