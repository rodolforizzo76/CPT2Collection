<?php
/** Immutable payment-session orchestration outcome. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
final class PaymentSessionResult {
	private bool $success; private string $code; private string $message; private ?int $order_id; private ?int $payment_session_id; private ?string $reference; private ?string $provider; private ?string $provider_session_id; private ?string $redirect_url; private ?string $expires_at;
	private function __construct( bool $success, string $code, string $message, ?int $order_id, ?int $payment_session_id, ?string $reference, ?string $provider, ?string $provider_session_id, ?string $redirect_url, ?string $expires_at ) { $this->success = $success; $this->code = $code; $this->message = $message; $this->order_id = $order_id; $this->payment_session_id = $payment_session_id; $this->reference = $reference; $this->provider = $provider; $this->provider_session_id = $provider_session_id; $this->redirect_url = $redirect_url; $this->expires_at = $expires_at; }
	public static function success( string $code, string $message, PaymentSessionRecord $session ): self { return new self( true, $code, $message, $session->order_id(), $session->id(), $session->reference(), $session->provider(), $session->provider_session_id(), $session->redirect_url(), $session->expires_at() ); }
	public static function failure( string $code, string $message, ?int $order_id = null, ?PaymentSessionRecord $session = null ): self { return new self( false, $code, $message, $order_id, $session?->id(), $session?->reference(), $session?->provider(), $session?->provider_session_id(), null, $session?->expires_at() ); }
	public function is_success(): bool { return $this->success; } public function get_code(): string { return $this->code; } public function get_message(): string { return $this->message; }
	public function get_order_id(): ?int { return $this->order_id; } public function get_payment_session_id(): ?int { return $this->payment_session_id; }
	public function get_reference(): ?string { return $this->reference; } public function get_provider(): ?string { return $this->provider; }
	public function get_provider_session_id(): ?string { return $this->provider_session_id; } public function get_redirect_url(): ?string { return $this->redirect_url; }
	public function get_expires_at(): ?string { return $this->expires_at; }
}
