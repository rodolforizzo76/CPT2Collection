<?php
/** Immutable payment-session persistence record. @package CPT2Collection */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

final class PaymentSessionRecord {
	private ?int $epoch_id = null;
	public function epoch_id(): ?int { return $this->epoch_id; }

	private int $id;
	private string $reference;
	private int $order_id;
	private string $provider;
	private ?string $provider_session_id;
	private string $creation_key_hash;
	private string $status;
	private ?int $active_slot;
	private string $currency;
	private int $amount_minor;
	private ?string $redirect_url;
	private string $created_at;
	private string $updated_at;
	private ?string $expires_at;
	private ?string $completed_at;
	private ?string $cancelled_at;
	private ?string $failed_at;
	private ?string $provider_payment_id;
	private ?string $provider_event_id;
	private ?string $payment_confirmed_at;
	private ?string $confirmed_currency;
	private ?int $confirmed_amount_minor;
	private ?string $expired_at;
	private ?string $reconciliation_required_at;
	private ?string $reconciliation_code;
	private ?int $reconciliation_event_id;

	public function __construct( int $id, string $reference, int $order_id, string $provider, ?string $provider_session_id, string $creation_key_hash, string $status, ?int $active_slot, string $currency, int $amount_minor, ?string $redirect_url, string $created_at, string $updated_at, ?string $expires_at = null, ?string $completed_at = null, ?string $cancelled_at = null, ?string $failed_at = null, ?string $provider_payment_id = null, ?string $provider_event_id = null, ?string $payment_confirmed_at = null, ?string $confirmed_currency = null, ?int $confirmed_amount_minor = null, ?string $expired_at = null, ?string $reconciliation_required_at = null, ?string $reconciliation_code = null, ?int $reconciliation_event_id = null ) {
		if ( $id <= 0 || $order_id <= 0 || 1 !== preg_match( '/\A[0-9a-f]{32}\z/', $reference ) || 1 !== preg_match( '/\A[a-z0-9_]{1,32}\z/', $provider ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $creation_key_hash ) || ! PaymentSessionStatuses::is_valid( $status ) || ! isset( Money::get_currencies()[ $currency ] ) || $amount_minor < 0 || '' === $created_at || '' === $updated_at ) {
			throw new InvalidArgumentException( 'Payment session data is invalid.' );
		}
		$expected_slot = PaymentSessionStatuses::owns_active_slot( $status ) ? 1 : null;
		if ( $active_slot !== $expected_slot ) {
			throw new InvalidArgumentException( 'Payment session active slot does not match its status.' );
		}
		if ( ( null !== $confirmed_currency && ! isset( Money::get_currencies()[ $confirmed_currency ] ) ) || ( null !== $confirmed_amount_minor && $confirmed_amount_minor < 0 ) || ( null !== $reconciliation_event_id && $reconciliation_event_id <= 0 ) || ( null !== $reconciliation_code && ( '' === trim( $reconciliation_code ) || strlen( $reconciliation_code ) > 64 ) ) ) { throw new InvalidArgumentException( 'Payment session confirmation data is invalid.' ); }
		$this->id = $id; $this->reference = $reference; $this->order_id = $order_id; $this->provider = $provider;
		$this->provider_session_id = $this->nullable( $provider_session_id ); $this->creation_key_hash = $creation_key_hash;
		$this->status = $status; $this->active_slot = $active_slot; $this->currency = $currency; $this->amount_minor = $amount_minor;
		$this->redirect_url = $this->nullable( $redirect_url ); $this->created_at = $created_at; $this->updated_at = $updated_at;
		$this->expires_at = $this->nullable( $expires_at ); $this->completed_at = $this->nullable( $completed_at );
		$this->cancelled_at = $this->nullable( $cancelled_at ); $this->failed_at = $this->nullable( $failed_at );
		$this->provider_payment_id = $this->nullable( $provider_payment_id ); $this->provider_event_id = $this->nullable( $provider_event_id ); $this->payment_confirmed_at = $this->nullable( $payment_confirmed_at ); $this->confirmed_currency = $this->nullable( $confirmed_currency ); $this->confirmed_amount_minor = $confirmed_amount_minor; $this->expired_at = $this->nullable( $expired_at ); $this->reconciliation_required_at = $this->nullable( $reconciliation_required_at ); $this->reconciliation_code = $this->nullable( $reconciliation_code ); $this->reconciliation_event_id = $reconciliation_event_id;
	}

	public static function from_row( object $row ): self {
		$record = new self( (int) $row->id, (string) $row->reference, (int) $row->order_id, (string) $row->provider, null === $row->provider_session_id ? null : (string) $row->provider_session_id, (string) $row->creation_key_hash, (string) $row->status, null === $row->active_slot ? null : (int) $row->active_slot, (string) $row->currency, (int) $row->amount_minor, null === $row->redirect_url ? null : (string) $row->redirect_url, (string) $row->created_at, (string) $row->updated_at, null === $row->expires_at ? null : (string) $row->expires_at, null === $row->completed_at ? null : (string) $row->completed_at, null === $row->cancelled_at ? null : (string) $row->cancelled_at, null === $row->failed_at ? null : (string) $row->failed_at, null === $row->provider_payment_id ? null : (string) $row->provider_payment_id, null === $row->provider_event_id ? null : (string) $row->provider_event_id, null === $row->payment_confirmed_at ? null : (string) $row->payment_confirmed_at, null === $row->confirmed_currency ? null : (string) $row->confirmed_currency, null === $row->confirmed_amount_minor ? null : (int) $row->confirmed_amount_minor, null === $row->expired_at ? null : (string) $row->expired_at, null === $row->reconciliation_required_at ? null : (string) $row->reconciliation_required_at, null === $row->reconciliation_code ? null : (string) $row->reconciliation_code, null === $row->reconciliation_event_id ? null : (int) $row->reconciliation_event_id );
		$record->epoch_id = isset( $row->epoch_id ) ? (int) $row->epoch_id : null;
		return $record;
	}

	private function nullable( ?string $value ): ?string { return null === $value || '' === $value ? null : $value; }
	public function is_active_at( string $now ): bool { return PaymentSessionStatuses::ACTIVE === $this->status && 1 === $this->active_slot && null !== $this->provider_session_id && null !== $this->redirect_url && null !== $this->expires_at && $this->expires_at > $now; }
	public function id(): int { return $this->id; } public function reference(): string { return $this->reference; }
	public function order_id(): int { return $this->order_id; } public function provider(): string { return $this->provider; }
	public function provider_session_id(): ?string { return $this->provider_session_id; } public function creation_key_hash(): string { return $this->creation_key_hash; }
	public function status(): string { return $this->status; } public function active_slot(): ?int { return $this->active_slot; }
	public function currency(): string { return $this->currency; } public function amount_minor(): int { return $this->amount_minor; }
	public function redirect_url(): ?string { return $this->redirect_url; } public function created_at(): string { return $this->created_at; }
	public function updated_at(): string { return $this->updated_at; } public function expires_at(): ?string { return $this->expires_at; }
	public function completed_at(): ?string { return $this->completed_at; } public function cancelled_at(): ?string { return $this->cancelled_at; }
	public function failed_at(): ?string { return $this->failed_at; }
	public function provider_payment_id(): ?string { return $this->provider_payment_id; } public function provider_event_id(): ?string { return $this->provider_event_id; }
	public function payment_confirmed_at(): ?string { return $this->payment_confirmed_at; } public function confirmed_currency(): ?string { return $this->confirmed_currency; }
	public function confirmed_amount_minor(): ?int { return $this->confirmed_amount_minor; } public function expired_at(): ?string { return $this->expired_at; }
	public function reconciliation_required_at(): ?string { return $this->reconciliation_required_at; } public function reconciliation_code(): ?string { return $this->reconciliation_code; }
	public function reconciliation_event_id(): ?int { return $this->reconciliation_event_id; }
}
