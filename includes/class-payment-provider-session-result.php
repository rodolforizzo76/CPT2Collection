<?php
/** Immutable successful provider session response. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
final class PaymentProviderSessionResult {
	private string $provider_session_id; private string $redirect_url; private string $expires_at;
	public function __construct( string $provider_session_id, string $redirect_url, string $expires_at, private ?string $provider_payment_id = null ) { $this->provider_session_id = $provider_session_id; $this->redirect_url = $redirect_url; $this->expires_at = $expires_at; }
	public function provider_payment_id(): ?string { return $this->provider_payment_id; }
	public function provider_session_id(): string { return $this->provider_session_id; } public function redirect_url(): string { return $this->redirect_url; } public function expires_at(): string { return $this->expires_at; }
}
