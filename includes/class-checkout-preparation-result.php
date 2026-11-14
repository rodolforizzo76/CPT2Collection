<?php
/** Checkout preparation outcome. @package CPT2Collection */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

final class CheckoutPreparationResult {
	private ?CommerceContext $context = null;
 private ?array $browser_context=null;
 public function with_browser_context(?array $context):self {$copy=clone $this;$copy->browser_context=$context;return $copy;}
 public function browser_context():?array {return $this->browser_context;}
	public function with_context( CommerceContext $context ): self { $copy=clone $this; $copy->context=$context; return $copy; }
	public function commerce_context(): ?CommerceContext { return $this->context; }
	private bool $success;
	private string $code;
	private string $message;
	private ?int $order_id;
	private ?string $checkout_rule_code;
	private ?string $reservation_code;

	private function __construct( bool $success, string $code, string $message, ?int $order_id, ?string $checkout_rule_code, ?string $reservation_code ) {
		$this->success            = $success;
		$this->code               = $code;
		$this->message            = $message;
		$this->order_id           = $order_id;
		$this->checkout_rule_code = $checkout_rule_code;
		$this->reservation_code   = $reservation_code;
	}

	public static function success( string $code, string $message, int $order_id, ?string $reservation_code = null ): self {
		return new self( true, $code, $message, $order_id, null, $reservation_code );
	}

	public static function failure( string $code, string $message, ?int $order_id = null, ?string $checkout_rule_code = null, ?string $reservation_code = null ): self {
		return new self( false, $code, $message, $order_id, $checkout_rule_code, $reservation_code );
	}

	public function is_success(): bool { return $this->success; }
	public function get_code(): string { return $this->code; }
	public function get_message(): string { return $this->message; }
	public function get_order_id(): ?int { return $this->order_id; }
	public function get_checkout_rule_code(): ?string { return $this->checkout_rule_code; }
	public function get_reservation_code(): ?string { return $this->reservation_code; }
}
