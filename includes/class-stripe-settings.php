<?php
/** Stripe Checkout configuration. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
final class StripeSettings {
	public const OPTION_SECRET_KEY = 'cpt2collection_stripe_secret_key'; public const OPTION_WEBHOOK_SECRET = 'cpt2collection_stripe_webhook_secret'; public const OPTION_ENVIRONMENT = 'cpt2collection_stripe_environment'; public const OPTION_SUCCESS_URL = 'cpt2collection_stripe_success_url'; public const OPTION_CANCEL_URL = 'cpt2collection_stripe_cancel_url';
	public const ENVIRONMENT_TEST = 'test'; public const ENVIRONMENT_LIVE = 'live';
	public static function secret_key(): string { if ( defined( 'CPT2COLLECTION_STRIPE_SECRET_KEY' ) ) { return trim( (string) constant( 'CPT2COLLECTION_STRIPE_SECRET_KEY' ) ); } return trim( (string) apply_filters( 'cpt2collection_stripe_secret_key', get_option( self::OPTION_SECRET_KEY, '' ) ) ); }
	public static function webhook_secret(): string { if ( defined( 'CPT2COLLECTION_STRIPE_WEBHOOK_SECRET' ) ) { return trim( (string) constant( 'CPT2COLLECTION_STRIPE_WEBHOOK_SECRET' ) ); } return trim( (string) apply_filters( 'cpt2collection_stripe_webhook_secret', get_option( self::OPTION_WEBHOOK_SECRET, '' ) ) ); }
	public static function environment(): string { return self::sanitize_environment( get_option( self::OPTION_ENVIRONMENT, self::ENVIRONMENT_TEST ) ); }
	public static function success_url(): string { return (string) get_option( self::OPTION_SUCCESS_URL, '' ); } public static function cancel_url(): string { return (string) get_option( self::OPTION_CANCEL_URL, '' ); }
	public static function sanitize_environment( mixed $value ): string { return self::ENVIRONMENT_LIVE === $value ? self::ENVIRONMENT_LIVE : self::ENVIRONMENT_TEST; }
	public static function sanitize_secret_key( mixed $value ): string { $value = trim( (string) $value ); return '' === $value ? (string) get_option( self::OPTION_SECRET_KEY, '' ) : $value; }
	public static function sanitize_webhook_secret( mixed $value ): string { $value = trim( (string) $value ); return '' === $value ? (string) get_option( self::OPTION_WEBHOOK_SECRET, '' ) : ( self::is_valid_webhook_secret( $value ) ? $value : (string) get_option( self::OPTION_WEBHOOK_SECRET, '' ) ); }
	public static function is_valid_webhook_secret( string $secret ): bool { return str_starts_with( $secret, 'whsec_' ) && strlen( $secret ) > 6 && strlen( $secret ) <= 255; }
	public static function sanitize_https_url( mixed $value ): string { $value = trim( (string) $value ); if ( '' === $value || false === filter_var( $value, FILTER_VALIDATE_URL ) || 'https' !== strtolower( (string) parse_url( $value, PHP_URL_SCHEME ) ) || '' === (string) parse_url( $value, PHP_URL_HOST ) ) { return ''; } return $value; }
	public static function key_matches_environment( string $key, string $environment ): bool { $prefix = self::ENVIRONMENT_LIVE === $environment ? 'sk_live_' : 'sk_test_'; return str_starts_with( $key, $prefix ) && strlen( $key ) > strlen( $prefix ); }
}
