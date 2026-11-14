<?php
/**
 * Cart operation result value object.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result for cart mutation operations.
 */
final class CartOperationResult {
	private bool $success;
	private string $operation;
	private string $code;
	private ?int $post_id;
	private ?int $quantity;
	private string $message;

	private function __construct( bool $success, string $operation, string $code, ?int $post_id, ?int $quantity, string $message ) {
		$this->success   = $success;
		$this->operation = $operation;
		$this->code      = $code;
		$this->post_id   = $post_id;
		$this->quantity  = $quantity;
		$this->message   = $message;
	}

	public static function success( string $operation, string $code, ?int $post_id, ?int $quantity, string $message ): self {
		return new self( true, $operation, $code, $post_id, $quantity, $message );
	}

	public static function failure( string $operation, string $code, ?int $post_id, ?int $quantity, string $message ): self {
		return new self( false, $operation, $code, $post_id, $quantity, $message );
	}

	public function is_success(): bool { return $this->success; }
	public function get_operation(): string { return $this->operation; }
	public function get_code(): string { return $this->code; }
	public function get_post_id(): ?int { return $this->post_id; }
	public function get_quantity(): ?int { return $this->quantity; }
	public function get_message(): string { return $this->message; }
}
