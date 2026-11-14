<?php
/** Classified provider failure without raw provider details. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
use RuntimeException;
defined( 'ABSPATH' ) || exit;
final class PaymentProviderException extends RuntimeException {
	public const REJECTED = 'rejected'; public const UNAVAILABLE = 'unavailable'; public const OUTCOME_UNKNOWN = 'outcome_unknown'; public const WINDOW_TOO_SHORT = 'window_too_short';
	private string $failure_type;
	public function __construct( string $failure_type, private ?string $provider_session_id = null, private ?string $provider_payment_id = null ) { if ( ! in_array( $failure_type, array( self::REJECTED, self::UNAVAILABLE, self::OUTCOME_UNKNOWN, self::WINDOW_TOO_SHORT ), true ) ) { $failure_type = self::OUTCOME_UNKNOWN; } parent::__construct( 'Payment provider operation failed.' ); $this->failure_type = $failure_type; }
	public function provider_payment_id(): ?string { return null !== $this->provider_payment_id && preg_match('/\Api_[A-Za-z0-9_]+\z/', $this->provider_payment_id) && strlen($this->provider_payment_id)<=255 ? $this->provider_payment_id : null; }
	public function provider_session_id(): ?string { return null !== $this->provider_session_id && preg_match('/\Acs_[A-Za-z0-9_]+\z/', $this->provider_session_id) && strlen($this->provider_session_id)<=255 ? $this->provider_session_id : null; }
	public function failure_type(): string { return $this->failure_type; }
}
