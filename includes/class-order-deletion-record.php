<?php
/** Durable, minimal identity of a deliberately deleted order. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class OrderDeletionRecord {
	public function __construct(
		private int $id,
		private string $installation_id,
		private int $epoch_id,
		private string $epoch_identity,
		private int $original_order_id,
		private string $deleted_at,
		private ?int $cart_id,
		private ?string $checkout_attempt_hash,
		private ?int $cart_revision,
		private ?int $checkout_generation,
		private string $checkout_disposition,
		private ?int $cart_revision_at_deletion
	) {}

	public static function from_row( object $row ): self {
		$integer = static function ( mixed $value, bool $nullable = false ): ?int {
			if ( $nullable && null === $value ) { return null; }
			if ( is_int( $value ) && $value >= 0 ) { return $value; }
			if ( is_string( $value ) && preg_match( '/\A(?:0|[1-9]\d*)\z/', $value ) ) {
				$parsed = filter_var( $value, FILTER_VALIDATE_INT );
				if ( is_int( $parsed ) && $parsed >= 0 ) { return $parsed; }
			}
			throw new \InvalidArgumentException( 'Invalid order deletion integer.' );
		};
		$id = $integer( $row->id ); $epoch = $integer( $row->epoch_id ); $order = $integer( $row->original_order_id );
		$cart = $integer( $row->cart_id, true ); $revision = $integer( $row->cart_revision, true );
		$generation = $integer( $row->checkout_generation, true ); $observed = $integer( $row->cart_revision_at_deletion, true );
		$attempt = null === $row->checkout_attempt_hash ? null : (string) $row->checkout_attempt_hash;
		$disposition = (string) $row->checkout_disposition;
		$deleted_at = (string) $row->deleted_at;
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $deleted_at, new \DateTimeZone( 'UTC' ) );
		if ( $id < 1 || $epoch < 1 || $order < 1 || ( null !== $cart && $cart < 1 ) ||
			1 !== preg_match( '/\A[0-9a-f]{32}\z/', (string) $row->installation_id ) ||
			1 !== preg_match( '/\A[0-9a-f]{32}\z/', (string) $row->epoch_identity ) ||
			( null !== $attempt && 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $attempt ) ) ||
			false === $date || $date->format( 'Y-m-d H:i:s' ) !== $deleted_at || '0000' === substr( $deleted_at, 0, 4 ) ||
			'revision' !== $disposition || null === $attempt || null === $cart || null === $revision || null === $generation || null !== $observed ) {
			throw new \InvalidArgumentException( 'Invalid order deletion identity.' );
		}
		return new self( $id, (string) $row->installation_id, $epoch, (string) $row->epoch_identity, $order, $deleted_at, $cart, $attempt, $revision, $generation, $disposition, $observed );
	}
	public function id(): int { return $this->id; }
	public function installation_id(): string { return $this->installation_id; }
	public function epoch_id(): int { return $this->epoch_id; }
	public function epoch_identity(): string { return $this->epoch_identity; }
	public function original_order_id(): int { return $this->original_order_id; }
	public function deleted_at(): string { return $this->deleted_at; }
	public function cart_id(): ?int { return $this->cart_id; }
	public function checkout_attempt_hash(): ?string { return $this->checkout_attempt_hash; }
	public function cart_revision(): ?int { return $this->cart_revision; }
	public function checkout_generation(): ?int { return $this->checkout_generation; }
	public function checkout_disposition(): string { return $this->checkout_disposition; }
	public function cart_revision_at_deletion(): ?int { return $this->cart_revision_at_deletion; }
}
