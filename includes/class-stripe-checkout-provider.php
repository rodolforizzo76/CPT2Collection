<?php
/** Stripe hosted Checkout implementation of the generic provider contract. @package CPT2Collection */
declare( strict_types=1 ); namespace RodolfoRizzo\Cpt2Collection;
use Stripe\Exception\{ApiConnectionException,ApiErrorException,AuthenticationException,IdempotencyException,InvalidRequestException,PermissionException,RateLimitException}; use Throwable;
defined( 'ABSPATH' ) || exit;
final class StripeCheckoutProvider implements PaymentProviderInterface {
	public const NAME = 'stripe_checkout'; public const MINIMUM_REMAINING_RESERVATION_SECONDS = 1800; private const MAX_LINE_ITEMS = 100;
	private StripeCheckoutClientInterface $client; private UtcClock $clock; /** @var callable|null */ private $diagnostic_sink;
	public function __construct( StripeCheckoutClientInterface $client, UtcClock $clock, ?callable $diagnostic_sink = null ) { $this->client = $client; $this->clock = $clock; $this->diagnostic_sink = $diagnostic_sink; }
	public static function configured( UtcClock $clock, ?callable $diagnostic_sink = null ): self { $key = StripeSettings::secret_key(); if ( ! class_exists( 'Stripe\\StripeClient' ) || ! StripeSettings::key_matches_environment( $key, StripeSettings::environment() ) ) { throw new PaymentProviderException( PaymentProviderException::REJECTED ); } return new self( new StripeSdkCheckoutClient( $key ), $clock, $diagnostic_sink ); }
	public function name(): string { return self::NAME; }
	/** Non-secret configuration captured before claim commit. A digest prevents switching credentials on retry. */
	public function request_configuration( CommerceContext $context ): array {
		return array( 'success_url'=>StripeSettings::sanitize_https_url(StripeSettings::success_url()), 'cancel_url'=>StripeSettings::sanitize_https_url(StripeSettings::cancel_url()), 'environment'=>StripeSettings::environment(), 'credential_fingerprint'=>hash('sha256',StripeSettings::secret_key()), 'installation_id'=>$context->installation_id(), 'epoch_identity'=>$context->epoch_identity() );
	}
	public function create_session( PaymentProviderSessionRequest $request ): PaymentProviderSessionResult {
		$config = $request->configuration();
		$key = StripeSettings::secret_key(); if ( ! isset($config['environment'],$config['credential_fingerprint']) || ! hash_equals($config['credential_fingerprint'],hash('sha256',$key)) || ! StripeSettings::key_matches_environment( $key, $config['environment'] ) ) { throw new PaymentProviderException( PaymentProviderException::REJECTED ); }
		$success_url = StripeSettings::sanitize_https_url( $config['success_url'] ?? '' ); $cancel_url = StripeSettings::sanitize_https_url( $config['cancel_url'] ?? '' ); if ( '' === $success_url || '' === $cancel_url ) { throw new PaymentProviderException( PaymentProviderException::REJECTED ); }
		$deadline = $this->clock->mysql_to_timestamp( $request->reservation_deadline() ); $now = $this->clock->now_timestamp();
		if ( $deadline <= 0 || $deadline - $now < self::MINIMUM_REMAINING_RESERVATION_SECONDS ) { throw new PaymentProviderException( PaymentProviderException::WINDOW_TOO_SHORT ); }
		$metadata = array( 'c2c_payment_session_reference' => $request->reference(), 'c2c_integration' => 'cpt2collection', 'c2c_installation_id'=>$config['installation_id'], 'c2c_epoch_identity'=>$config['epoch_identity'] );
		$parameters = array( 'mode' => 'payment', 'payment_method_types' => array( 'card' ), 'success_url' => $success_url, 'cancel_url' => $cancel_url, 'expires_at' => $deadline, 'client_reference_id' => $request->reference(), 'metadata' => $metadata, 'payment_intent_data' => array( 'metadata' => $metadata ), 'line_items' => $this->line_items( $request ) );
		try { $session = $this->client->create_checkout_session( $parameters, array( 'idempotency_key' => $request->idempotency_key(), 'stripe_version' => '2026-07-29.dahlia' ) ); }
		catch ( IdempotencyException $error ) { $this->report_sdk_failure( $error, PaymentProviderException::OUTCOME_UNKNOWN ); throw new PaymentProviderException( PaymentProviderException::OUTCOME_UNKNOWN ); }
		catch ( AuthenticationException | PermissionException | InvalidRequestException $error ) { $this->report_sdk_failure( $error, PaymentProviderException::REJECTED ); throw new PaymentProviderException( PaymentProviderException::REJECTED ); }
		catch ( RateLimitException $error ) { $this->report_sdk_failure( $error, PaymentProviderException::UNAVAILABLE ); throw new PaymentProviderException( PaymentProviderException::UNAVAILABLE ); }
		catch ( ApiConnectionException | ApiErrorException $error ) { $this->report_sdk_failure( $error, PaymentProviderException::OUTCOME_UNKNOWN ); throw new PaymentProviderException( PaymentProviderException::OUTCOME_UNKNOWN ); }
		catch ( Throwable $error ) { $this->report_sdk_failure( $error, PaymentProviderException::OUTCOME_UNKNOWN ); throw new PaymentProviderException( PaymentProviderException::OUTCOME_UNKNOWN ); }
		$id = is_string( $session['id'] ?? null ) ? trim( $session['id'] ) : ''; $url = is_string( $session['url'] ?? null ) ? $session['url'] : ''; $expires = ctype_digit( (string) ( $session['expires_at'] ?? '' ) ) ? (int) $session['expires_at'] : 0;
		$payment_id = $session['payment_intent'] ?? null; if(is_array($payment_id)){$payment_id=$payment_id['id']??null;} if(!is_string($payment_id)||!preg_match('/\Api_[A-Za-z0-9_]+\z/',$payment_id)||strlen($payment_id)>255){$payment_id=null;}
		$validation_failures = array(); if ( !preg_match('/\Acs_[A-Za-z0-9_]+\z/',$id) || strlen($id)>255 ) $validation_failures[] = 'missing_id'; if ( false === filter_var( $url, FILTER_VALIDATE_URL ) || 'https' !== strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ) ) $validation_failures[] = 'invalid_redirect_url'; if ( $expires <= $now ) $validation_failures[] = 'expiry_not_future'; if ( $expires > $deadline ) $validation_failures[] = 'expiry_after_reservation';
		if ( array() !== $validation_failures ) { $this->report( array( 'stage' => 'provider_response_validation', 'classification' => PaymentProviderException::OUTCOME_UNKNOWN, 'validation_failures' => $validation_failures ) ); throw new PaymentProviderException( PaymentProviderException::OUTCOME_UNKNOWN, $id, $payment_id ); }
		return new PaymentProviderSessionResult( $id, $url, $this->clock->format_mysql( $expires ), $payment_id );
	}
	private function report_sdk_failure( Throwable $error, string $classification ): void {
		$diagnostic = array( 'stage' => 'sdk_call', 'classification' => $classification, 'exception_class' => get_class( $error ) );
		if ( $error instanceof ApiErrorException ) { $diagnostic['stripe_http_status'] = $error->getHttpStatus(); $diagnostic['stripe_request_id'] = $error->getRequestId(); $diagnostic['stripe_error_code'] = $error->getStripeCode(); $stripe_error = $error->getError(); $diagnostic['stripe_error_type'] = is_object( $stripe_error ) && isset( $stripe_error->type ) && is_string( $stripe_error->type ) ? $stripe_error->type : null; }
		elseif ( ! str_starts_with( get_class( $error ), 'Stripe\\' ) ) { $diagnostic['exception_message'] = $this->safe_exception_message( $error->getMessage() ); $diagnostic['source_file'] = basename( $error->getFile() ); $diagnostic['source_line'] = $error->getLine(); }
		$this->report( array_filter( $diagnostic, static fn( $value ): bool => null !== $value && '' !== $value ) );
	}
	private function safe_exception_message( string $message ): string {
		$key = StripeSettings::secret_key(); if ( '' !== $key ) { $message = str_replace( $key, '[redacted]', $message ); }
		$message = (string) preg_replace( array( '/sk_(?:test|live)_[A-Za-z0-9_-]+/i', '/\b(?:Bearer|Basic)\s+[A-Za-z0-9+\/_=.-]+/i', '/\b(Authorization\s*[:=]\s*)[^\s,;]+/i', '/[A-Za-z0-9+\/_=-]{40,}/' ), array( '[redacted]', '[redacted]', '$1[redacted]', '[redacted]' ), $message );
		$message = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $message ) );
		return strlen( $message ) > 240 ? substr( $message, 0, 237 ) . '...' : $message;
	}
	private function report( array $diagnostic ): void { if ( is_callable( $this->diagnostic_sink ) ) { try { ( $this->diagnostic_sink )( $diagnostic ); } catch ( Throwable $ignored ) {} } }
	private function line_items( PaymentProviderSessionRequest $request ): array {
		$items = $request->line_items(); if ( array() === $items || count( $items ) > self::MAX_LINE_ITEMS ) { throw new PaymentProviderException( PaymentProviderException::REJECTED ); } $total = 0; $output = array();
		foreach ( $items as $item ) { if ( ! $item instanceof PaymentProviderLineItem || '' === trim( $item->name() ) || $item->unit_amount_minor() <= 0 || $item->quantity() <= 0 || $item->quantity() > intdiv( PHP_INT_MAX, $item->unit_amount_minor() ) ) { throw new PaymentProviderException( PaymentProviderException::REJECTED ); } $line = $item->unit_amount_minor() * $item->quantity(); if ( $line > PHP_INT_MAX - $total ) { throw new PaymentProviderException( PaymentProviderException::REJECTED ); } $total += $line; $output[] = array( 'price_data' => array( 'currency' => strtolower( $request->currency() ), 'unit_amount' => $item->unit_amount_minor(), 'product_data' => array( 'name' => $item->name() ) ), 'quantity' => $item->quantity() ); }
		if ( $total !== $request->amount_minor() ) { throw new PaymentProviderException( PaymentProviderException::REJECTED ); } return $output;
	}
}
