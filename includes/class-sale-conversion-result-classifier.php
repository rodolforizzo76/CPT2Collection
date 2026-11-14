<?php
/** Exhaustive fail-safe classification of sale-conversion result codes. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class SaleConversionResultClassifier {
	private const SUCCESS_CODES = array( 'sale_converted', 'sale_already_converted', 'order_deleted', 'commerce_reset_fenced', 'expiration_already_processed' );
	private const RETRYABLE_CODES = array( 'database_error', 'transaction_required', 'transactions_not_supported', 'continuation_verification_required', 'processed_state_incoherent', 'transaction_already_active' );
	private const ATTENTION_CODES = array(
		'payment_association_mismatch', 'product_invalid', 'order_not_found',
		'payment_session_not_found', 'payment_event_not_found', 'event_already_processing',
		'event_requires_attention', 'event_state_conflict', 'inconsistent_conversion_state',
		'order_terminal_conflict', 'session_state_conflict', 'payment_identity_conflict',
		'payment_currency_mismatch', 'payment_amount_mismatch', 'reservation_missing',
		'reservation_structural_mismatch', 'reservation_released', 'reservation_already_converted',
		'product_missing', 'stock_invalid', 'insufficient_stock',
	);

	public static function classify( string $code ): string {
		if ( 'transaction_outcome_unknown' === $code ) { return WebhookOutcomeCategories::OUTCOME_UNKNOWN; }
		if ( in_array( $code, self::SUCCESS_CODES, true ) || 'event_ignored' === $code ) { return WebhookOutcomeCategories::SUCCESS; }
		if ( in_array( $code, self::ATTENTION_CODES, true ) ) { return WebhookOutcomeCategories::DETERMINISTIC_ATTENTION; }
		// Unknown/future codes fail safely: they must never cause acknowledgement and terminal attention.
		return WebhookOutcomeCategories::RETRYABLE;
	}

	/** @return array<string,string> */
	public static function known_codes(): array {
		$output = array( 'transaction_outcome_unknown' => WebhookOutcomeCategories::OUTCOME_UNKNOWN, 'event_ignored' => WebhookOutcomeCategories::SUCCESS );
		foreach ( self::SUCCESS_CODES as $code ) { $output[ $code ] = WebhookOutcomeCategories::SUCCESS; }
		foreach ( self::RETRYABLE_CODES as $code ) { $output[ $code ] = WebhookOutcomeCategories::RETRYABLE; }
		foreach ( self::ATTENTION_CODES as $code ) { $output[ $code ] = WebhookOutcomeCategories::DETERMINISTIC_ATTENTION; }
		ksort( $output );
		return $output;
	}
}
