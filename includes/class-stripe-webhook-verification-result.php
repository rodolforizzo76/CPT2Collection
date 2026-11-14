<?php
/** Typed Stripe signature-verification result. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class StripeWebhookVerificationResult {
	public const VERIFIED = 'verified';
	public const INVALID_SIGNATURE = 'invalid_signature';
	public const INVALID_CONFIGURATION = 'invalid_configuration';
	public const MALFORMED_PAYLOAD = 'malformed_payload';
	public const INTERNAL_FAILURE = 'internal_failure';
	private string $outcome;
	/** @var array<string,mixed>|null */ private ?array $event;
	private function __construct( string $outcome, ?array $event = null ) { $this->outcome = $outcome; $this->event = $event; }
	/** @param array<string,mixed> $event */ public static function verified( array $event ): self { return new self( self::VERIFIED, $event ); }
	public static function failure( string $outcome ): self { return new self( $outcome ); }
	public function outcome(): string { return $this->outcome; }
	public function is_verified(): bool { return self::VERIFIED === $this->outcome; }
	/** @return array<string,mixed>|null */ public function event(): ?array { return $this->event; }
}
