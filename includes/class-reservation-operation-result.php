<?php
/**
 * Reservation acquisition result.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

/** Immutable, public-safe outcome of a reservation acquisition attempt. */
final class ReservationOperationResult {
	private bool $success;
	private string $code;
	private string $message;
	private int $order_id;

	private function __construct( bool $success, string $code, string $message, int $order_id ) {
		$this->success  = $success;
		$this->code     = $code;
		$this->message  = $message;
		$this->order_id = $order_id;
	}

	public static function success( string $code, string $message, int $order_id ): self {
		return new self( true, $code, $message, $order_id );
	}

	public static function failure( string $code, string $message, int $order_id ): self {
		return new self( false, $code, $message, $order_id );
	}

	public function is_success(): bool { return $this->success; }
	public function get_code(): string { return $this->code; }
	public function get_message(): string { return $this->message; }
	public function get_order_id(): int { return $this->order_id; }
}
