<?php
/** Pure allowlisted Stripe Checkout event normalizer. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
use InvalidArgumentException;
defined( 'ABSPATH' ) || exit;

final class StripeEventNormalizer {
	private const SUPPORTED = array( 'checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.expired' );
	/** @param array<string,mixed> $event */
	public function normalize( array $event, string $raw_payload_sha256 ): StripeEventNormalizationResult {
		$id = is_string( $event['id'] ?? null ) ? trim( $event['id'] ) : '';
		$type = is_string( $event['type'] ?? null ) ? trim( $event['type'] ) : '';
		if ( '' === $id || strlen( $id ) > 255 || '' === $type || strlen( $type ) > 128 || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $raw_payload_sha256 ) ) { return StripeEventNormalizationResult::result( StripeEventNormalizationResult::INVALID_EVENT, 'invalid_event_envelope', null ); }
		$created = $this->integer( $event['created'] ?? null );
		$created_at = null !== $created && $created >= 0 ? gmdate( 'Y-m-d H:i:s', $created ) : null;
		$livemode_valid = is_bool( $event['livemode'] ?? null );
		$livemode = true === ( $event['livemode'] ?? null );
		$object = is_array( $event['data']['object'] ?? null ) ? $event['data']['object'] : array();
		$is_supported = in_array( $type, self::SUPPORTED, true );

		$checkout_object = $is_supported && 'checkout.session' === ($object['object'] ?? null);
		$session_id = $checkout_object ? $this->identity( $object['id'] ?? null ) : null;
		$client_reference = $this->reference( $object['client_reference_id'] ?? null );
		$metadata = is_array( $object['metadata'] ?? null ) ? $object['metadata'] : array();
		$metadata_reference = $this->reference( $metadata['c2c_payment_session_reference'] ?? null );
		$reference_invalid = ( array_key_exists( 'client_reference_id', $object ) && null !== $object['client_reference_id'] && null === $client_reference ) || ( array_key_exists( 'c2c_payment_session_reference', $metadata ) && null !== $metadata['c2c_payment_session_reference'] && null === $metadata_reference );
		$references_conflict = null !== $client_reference && null !== $metadata_reference && $client_reference !== $metadata_reference;
		$local_reference = !$checkout_object || $references_conflict ? null : ( $client_reference ?? $metadata_reference );
		$payment_id = $checkout_object ? $this->identity( $object['payment_intent'] ?? null ) : null;
		$currency_raw = is_string( $object['currency'] ?? null ) ? strtoupper( trim( $object['currency'] ) ) : null;
		$currency = null !== $currency_raw && isset( Money::get_currencies()[ $currency_raw ] ) ? $currency_raw : null;
		$amount = $this->integer( $object['amount_total'] ?? null );
		$payment_status = $this->text( $object['payment_status'] ?? null, 32 );
		$session_status = $this->text( $object['status'] ?? null, 32 );
		$mode = $this->text( $object['mode'] ?? null, 32 );
		$expires_at = $this->integer( $object['expires_at'] ?? null );
		$minimal = array( 'schema' => 2, 'integration'=>$metadata['c2c_integration']??null, 'identity_invalid'=>!$checkout_object || !$livemode_valid || $reference_invalid || $references_conflict || null===$session_id, 'installation_id'=>$this->reference($metadata['c2c_installation_id']??null), 'epoch_identity'=>$this->reference($metadata['c2c_epoch_identity']??null), 'object' => $this->text( $object['object'] ?? null, 64 ), 'mode' => $mode, 'client_reference_id' => $client_reference, 'metadata_payment_session_reference' => $metadata_reference, 'expires_at' => $expires_at );
		$minimal = array_filter( $minimal, static fn( mixed $value ): bool => null !== $value );
		$payload = json_encode( $minimal, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $payload ) ) { return StripeEventNormalizationResult::result( StripeEventNormalizationResult::INVALID_EVENT, 'normalization_encoding_failed', null ); }
		try { $receipt = new PaymentEventReceipt( 'stripe_checkout', $id, $type, $created_at, $livemode, $session_id, $local_reference, $payment_id, $currency, null !== $amount && $amount >= 0 ? $amount : null, $payment_status, $session_status, $raw_payload_sha256, $payload ); } catch ( InvalidArgumentException $error ) { return StripeEventNormalizationResult::result( StripeEventNormalizationResult::INVALID_EVENT, 'invalid_event_envelope', null ); }
		if ( ! $is_supported ) { return StripeEventNormalizationResult::result( StripeEventNormalizationResult::UNSUPPORTED, 'unsupported_event_type', $receipt ); }
		if ( null === $created_at || ! $livemode_valid ) { return StripeEventNormalizationResult::result( StripeEventNormalizationResult::DETERMINISTIC_ATTENTION, 'invalid_event_metadata', $receipt ); }
		if ( 'checkout.session' !== ( $object['object'] ?? null ) || null === $session_id ) { return StripeEventNormalizationResult::result( StripeEventNormalizationResult::DETERMINISTIC_ATTENTION, 'missing_checkout_session_id', $receipt ); }
		if ( $reference_invalid ) { return StripeEventNormalizationResult::result( StripeEventNormalizationResult::DETERMINISTIC_ATTENTION, 'invalid_local_reference', $receipt ); }
		if ( $references_conflict ) { return StripeEventNormalizationResult::result( StripeEventNormalizationResult::DETERMINISTIC_ATTENTION, 'local_reference_conflict', $receipt ); }
		if ( 'checkout.session.expired' === $type && 'payment' !== $mode ) { return StripeEventNormalizationResult::result( StripeEventNormalizationResult::DETERMINISTIC_ATTENTION, 'invalid_checkout_mode', $receipt ); }
		if ( 'checkout.session.expired' === $type ) { return StripeEventNormalizationResult::result( StripeEventNormalizationResult::EXPIRATION, 'checkout_session_expired', $receipt ); }
		if ( 'checkout.session.completed' === $type && 'paid' !== $payment_status ) { return StripeEventNormalizationResult::result( StripeEventNormalizationResult::LIFECYCLE, 'checkout_completed_payment_pending', $receipt ); }
		if ( 'paid' !== $payment_status || 'payment' !== $mode || null === $payment_id || null === $currency || null === $amount || $amount < 0 ) {
			$code = null === $payment_id ? 'missing_provider_payment_id' : ( null === $currency ? 'invalid_currency' : ( null === $amount || $amount < 0 ? 'invalid_amount' : 'invalid_payment_state' ) );
			return StripeEventNormalizationResult::result( StripeEventNormalizationResult::DETERMINISTIC_ATTENTION, $code, $receipt );
		}
		return StripeEventNormalizationResult::result( StripeEventNormalizationResult::PAYMENT_SUCCESS, 'verified_checkout_payment', $receipt );
	}
	private function text( mixed $value, int $maximum ): ?string { if ( ! is_string( $value ) ) { return null; } $value=trim($value); return ''!==$value&&strlen($value)<=$maximum?$value:null; }
	private function identity( mixed $value ): ?string { if ( is_array( $value ) ) { $value=$value['id']??null; } return $this->text( $value, 255 ); }
	private function reference( mixed $value ): ?string { return is_string( $value ) && 1 === preg_match( '/\A[0-9a-f]{32}\z/', $value ) ? $value : null; }
	private function integer( mixed $value ): ?int { return is_int( $value ) ? $value : ( is_string( $value ) && 1 === preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) && false !== filter_var( $value, FILTER_VALIDATE_INT ) ? (int) $value : null ); }
}
