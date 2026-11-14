<?php
/** Exact-raw-body Stripe webhook signature verifier. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException as StripeUnexpectedValueException;
use Stripe\Webhook;
use Throwable;
use UnexpectedValueException;
defined( 'ABSPATH' ) || exit;

final class StripeWebhookVerifier {
	public const DEFAULT_TOLERANCE_SECONDS = 300;
	private string $secret;
	private int $tolerance;
	public function __construct( string $secret, int $tolerance = self::DEFAULT_TOLERANCE_SECONDS ) { $this->secret = trim( $secret ); $this->tolerance = $tolerance; }
	public static function configured(): self { return new self( StripeSettings::webhook_secret() ); }

	public function verify( string $raw_body, string $signature_header ): StripeWebhookVerificationResult {
		if ( ! StripeSettings::is_valid_webhook_secret( $this->secret ) || $this->tolerance <= 0 ) { return StripeWebhookVerificationResult::failure( StripeWebhookVerificationResult::INVALID_CONFIGURATION ); }
		if ( '' === trim( $signature_header ) ) { return StripeWebhookVerificationResult::failure( StripeWebhookVerificationResult::INVALID_SIGNATURE ); }
		try {
			$event = Webhook::constructEvent( $raw_body, $signature_header, $this->secret, $this->tolerance );
			if ( is_object( $event ) && method_exists( $event, 'toArray' ) ) { $event = $event->toArray(); }
			return is_array( $event ) ? StripeWebhookVerificationResult::verified( $event ) : StripeWebhookVerificationResult::failure( StripeWebhookVerificationResult::MALFORMED_PAYLOAD );
		} catch ( SignatureVerificationException $error ) {
			return StripeWebhookVerificationResult::failure( StripeWebhookVerificationResult::INVALID_SIGNATURE );
		} catch ( UnexpectedValueException | StripeUnexpectedValueException $error ) {
			return StripeWebhookVerificationResult::failure( StripeWebhookVerificationResult::MALFORMED_PAYLOAD );
		} catch ( Throwable $error ) {
			return StripeWebhookVerificationResult::failure( StripeWebhookVerificationResult::INTERNAL_FAILURE );
		}
	}
}
