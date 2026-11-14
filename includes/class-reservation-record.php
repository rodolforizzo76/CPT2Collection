<?php
/**
 * Reservation persistence record.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable representation of a persisted reservation row.
 */
final class ReservationRecord {
	private ?int $epoch_id = null;
	public function epoch_id(): ?int { return $this->epoch_id; }

	private int $id;
	private int $order_id;
	private int $order_item_id;
	private int $post_id;
	private int $quantity;
	private string $status;
	private string $created_at;
	private string $updated_at;
	private string $expires_at;
	private ?string $released_at;
	private ?string $converted_at;

	public function __construct( int $id, int $order_id, int $order_item_id, int $post_id, int $quantity, string $status, string $created_at, string $updated_at, string $expires_at, ?string $released_at = null, ?string $converted_at = null ) {
		if ( $id <= 0 || $order_id <= 0 || $order_item_id <= 0 || $post_id <= 0 ) {
			throw new InvalidArgumentException( 'Reservation ids must be greater than zero.' );
		}
		if ( $quantity < 1 ) {
			throw new InvalidArgumentException( 'Reservation quantity must be at least 1.' );
		}
		if ( ! ReservationStatuses::is_valid( $status ) ) {
			throw new InvalidArgumentException( 'Reservation status is invalid.' );
		}
		if ( '' === $created_at || '' === $updated_at || '' === $expires_at ) {
			throw new InvalidArgumentException( 'Reservation timestamps are required.' );
		}

		$this->id            = $id;
		$this->order_id      = $order_id;
		$this->order_item_id = $order_item_id;
		$this->post_id       = $post_id;
		$this->quantity      = $quantity;
		$this->status        = $status;
		$this->created_at    = $created_at;
		$this->updated_at    = $updated_at;
		$this->expires_at    = $expires_at;
		$this->released_at   = $this->nullable_timestamp( $released_at );
		$this->converted_at  = $this->nullable_timestamp( $converted_at );
	}

	/**
	 * @param object{id:mixed,order_id:mixed,order_item_id:mixed,post_id:mixed,quantity:mixed,status:mixed,created_at:mixed,updated_at:mixed,expires_at:mixed,released_at:mixed,converted_at:mixed} $row Database row.
	 */
	public static function from_row( object $row ): self {
		$record = new self( (int) $row->id, (int) $row->order_id, (int) $row->order_item_id, (int) $row->post_id, (int) $row->quantity, (string) $row->status, (string) $row->created_at, (string) $row->updated_at, (string) $row->expires_at, null === $row->released_at ? null : (string) $row->released_at, null === $row->converted_at ? null : (string) $row->converted_at );
		$record->epoch_id = isset( $row->epoch_id ) ? (int) $row->epoch_id : null;
		return $record;
	}

	public function is_active_at( string $current_utc ): bool {
		return ReservationStatuses::ACTIVE === $this->status && $this->expires_at > $current_utc;
	}

	private function nullable_timestamp( ?string $value ): ?string {
		return null === $value || '' === $value ? null : $value;
	}

	public function id(): int { return $this->id; }
	public function order_id(): int { return $this->order_id; }
	public function order_item_id(): int { return $this->order_item_id; }
	public function post_id(): int { return $this->post_id; }
	public function quantity(): int { return $this->quantity; }
	public function status(): string { return $this->status; }
	public function created_at(): string { return $this->created_at; }
	public function updated_at(): string { return $this->updated_at; }
	public function expires_at(): string { return $this->expires_at; }
	public function released_at(): ?string { return $this->released_at; }
	public function converted_at(): ?string { return $this->converted_at; }
}
