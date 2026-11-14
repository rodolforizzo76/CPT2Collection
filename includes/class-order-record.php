<?php
/**
 * Order persistence record.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable representation of a persisted order row.
 */
final class OrderRecord {
	private ?int $epoch_id = null;
	public function epoch_id(): ?int { return $this->epoch_id; }

	private int $id;
	private ?int $cart_id;
	private string $status;
	private string $currency;
	private int $subtotal_minor;
	private int $total_minor;
	private string $created_at;
	private string $updated_at;
	private ?string $paid_at;
	private ?string $cancelled_at;
	private ?string $expired_at;
	private ?string $failed_at;
	private ?int $cart_revision;
	private ?int $checkout_generation;
	private ?string $cart_reconciliation_status;
	private ?string $cart_reconciliation_code;
	private ?string $cart_reconciliation_updated_at;
	private ?string $checkout_attempt_hash;

	public function __construct( int $id, ?int $cart_id, string $status, string $currency, int $subtotal_minor, int $total_minor, string $created_at, string $updated_at, ?string $paid_at = null, ?string $cancelled_at = null, ?string $expired_at = null, ?string $failed_at = null, ?int $cart_revision = null, ?int $checkout_generation = null, ?string $cart_reconciliation_status = null, ?string $cart_reconciliation_code = null, ?string $cart_reconciliation_updated_at = null, ?string $checkout_attempt_hash = null ) {
		if ( $id <= 0 ) {
			throw new InvalidArgumentException( 'Order id must be greater than zero.' );
		}
		if ( null !== $cart_id && $cart_id <= 0 ) {
			throw new InvalidArgumentException( 'Order cart_id must be null or greater than zero.' );
		}
		if ( ( null === $cart_revision ) !== ( null === $checkout_generation ) || ( null !== $cart_revision && ( $cart_revision < 0 || $checkout_generation < 0 ) ) ) {
			throw new InvalidArgumentException( 'Order cart revision identity is invalid.' );
		}
		if ( ! OrderStatuses::is_valid( $status ) ) {
			throw new InvalidArgumentException( 'Order status is invalid.' );
		}
		if ( ! isset( Money::get_currencies()[ $currency ] ) ) {
			throw new InvalidArgumentException( 'Order currency is invalid.' );
		}
		if ( $subtotal_minor < 0 || $total_minor < 0 ) {
			throw new InvalidArgumentException( 'Order monetary values must be non-negative.' );
		}
		if ( '' === $created_at || '' === $updated_at ) {
			throw new InvalidArgumentException( 'Order timestamps are required.' );
		}
		if ( ! self::valid_nullable_token( $cart_reconciliation_status, 32 ) || ! self::valid_nullable_token( $cart_reconciliation_code, 64 ) ) {
			throw new InvalidArgumentException( 'Order cart reconciliation state is invalid.' );
		}
		if ( null !== $checkout_attempt_hash && 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $checkout_attempt_hash ) ) {
			throw new InvalidArgumentException( 'Order checkout attempt identity is invalid.' );
		}

		$this->id             = $id;
		$this->cart_id        = $cart_id;
		$this->status         = $status;
		$this->currency       = $currency;
		$this->subtotal_minor = $subtotal_minor;
		$this->total_minor    = $total_minor;
		$this->created_at     = $created_at;
		$this->updated_at     = $updated_at;
		$this->paid_at        = $this->nullable_timestamp( $paid_at );
		$this->cancelled_at   = $this->nullable_timestamp( $cancelled_at );
		$this->expired_at     = $this->nullable_timestamp( $expired_at );
		$this->failed_at      = $this->nullable_timestamp( $failed_at );
		$this->cart_revision  = $cart_revision;
		$this->checkout_generation = $checkout_generation;
		$this->cart_reconciliation_status = $this->nullable_timestamp( $cart_reconciliation_status );
		$this->cart_reconciliation_code = $this->nullable_timestamp( $cart_reconciliation_code );
		$this->cart_reconciliation_updated_at = $this->nullable_timestamp( $cart_reconciliation_updated_at );
		$this->checkout_attempt_hash = $checkout_attempt_hash;
	}

	/**
	 * @param object{id:mixed,cart_id:mixed,status:mixed,currency:mixed,subtotal_minor:mixed,total_minor:mixed,created_at:mixed,updated_at:mixed,paid_at:mixed,cancelled_at:mixed,expired_at:mixed,failed_at:mixed,cart_revision?:mixed,checkout_generation?:mixed,cart_reconciliation_status?:mixed,cart_reconciliation_code?:mixed,cart_reconciliation_updated_at?:mixed} $row Database row.
	 */
	public static function from_row( object $row ): self {
		$cart_id = null === $row->cart_id ? null : (int) $row->cart_id;

		$revision   = self::persisted_nullable_nonnegative_integer( $row->cart_revision ?? null, 'cart_revision' );
		$generation = self::persisted_nullable_nonnegative_integer( $row->checkout_generation ?? null, 'checkout_generation' );

		$record = new self( (int) $row->id, $cart_id, (string) $row->status, (string) $row->currency, (int) $row->subtotal_minor, (int) $row->total_minor, (string) $row->created_at, (string) $row->updated_at, null === $row->paid_at ? null : (string) $row->paid_at, null === $row->cancelled_at ? null : (string) $row->cancelled_at, null === $row->expired_at ? null : (string) $row->expired_at, null === $row->failed_at ? null : (string) $row->failed_at, $revision, $generation, null === ( $row->cart_reconciliation_status ?? null ) ? null : (string) $row->cart_reconciliation_status, null === ( $row->cart_reconciliation_code ?? null ) ? null : (string) $row->cart_reconciliation_code, null === ( $row->cart_reconciliation_updated_at ?? null ) ? null : (string) $row->cart_reconciliation_updated_at, null === ( $row->checkout_attempt_hash ?? null ) ? null : (string) $row->checkout_attempt_hash );
		$record->epoch_id = isset( $row->epoch_id ) ? (int) $row->epoch_id : null;
		return $record;
	}

	private static function valid_nullable_token( ?string $value, int $maximum_length ): bool {
		return null === $value || '' === $value || ( strlen( $value ) <= $maximum_length && 1 === preg_match( '/\A[a-z0-9_]+\z/', $value ) );
	}

	private static function persisted_nullable_nonnegative_integer( mixed $value, string $name ): ?int {
		if ( null === $value ) {
			return null;
		}
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/\A(?:0|[1-9]\d*)\z/', $value ) ) {
			$parsed = filter_var( $value, FILTER_VALIDATE_INT );
			if ( is_int( $parsed ) && $parsed >= 0 ) {
				return $parsed;
			}
		}
		throw new InvalidArgumentException( 'Order ' . $name . ' is invalid.' );
	}

	private function nullable_timestamp( ?string $value ): ?string {
		return null === $value || '' === $value ? null : $value;
	}

	public function id(): int { return $this->id; }
	public function cart_id(): ?int { return $this->cart_id; }
	public function status(): string { return $this->status; }
	public function currency(): string { return $this->currency; }
	public function subtotal_minor(): int { return $this->subtotal_minor; }
	public function total_minor(): int { return $this->total_minor; }
	public function created_at(): string { return $this->created_at; }
	public function updated_at(): string { return $this->updated_at; }
	public function paid_at(): ?string { return $this->paid_at; }
	public function cancelled_at(): ?string { return $this->cancelled_at; }
	public function expired_at(): ?string { return $this->expired_at; }
	public function failed_at(): ?string { return $this->failed_at; }
	public function cart_revision(): ?int { return $this->cart_revision; }
	public function checkout_generation(): ?int { return $this->checkout_generation; }
	public function cart_reconciliation_status(): ?string { return $this->cart_reconciliation_status; }
	public function cart_reconciliation_code(): ?string { return $this->cart_reconciliation_code; }
	public function cart_reconciliation_updated_at(): ?string { return $this->cart_reconciliation_updated_at; }
	public function checkout_attempt_hash(): ?string { return $this->checkout_attempt_hash; }
}
