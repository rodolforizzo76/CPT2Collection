<?php
/**
 * Money parsing and formatting.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

/**
 * Handles currency and minor-unit prices.
 */
final class Money {
	public const OPTION_CURRENCY = 'cpt2collection_currency';
	public const DEFAULT_CURRENCY = 'EUR';
	private const ZERO_DECIMAL_CHARGE_CURRENCIES = array(
		'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
	);

	/**
	 * Gets supported currencies.
	 *
	 * @return array<string,array{label:string,symbol:string,decimals:int}>
	 */
	public static function get_currencies(): array {
		return array(
			'EUR' => array(
				'label'    => __( 'Euro', 'cpt2collection' ),
				'symbol'   => '€',
				'decimals' => 2,
			),
			'USD' => array(
				'label'    => __( 'US Dollar', 'cpt2collection' ),
				'symbol'   => '$',
				'decimals' => 2,
			),
		);
	}

	/**
	 * Gets the configured currency.
	 */
	public static function get_currency(): string {
		$currency = (string) get_option( self::OPTION_CURRENCY, self::DEFAULT_CURRENCY );

		return isset( self::get_currencies()[ $currency ] ) ? $currency : self::DEFAULT_CURRENCY;
	}

	/**
	 * Parses a human price into minor units.
	 */
	public static function parse_price_minor( string $value ): ?int {
		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		$value = preg_replace( '/\s+/', '', $value );
		if ( null === $value || ! preg_match( '/^\d+(?:[\.,]\d{1,2})?$/', $value ) ) {
			return null;
		}

		$parts    = preg_split( '/[\.,]/', $value );
		$major    = isset( $parts[0] ) ? (int) $parts[0] : 0;
		$decimals = $parts[1] ?? '';

		if ( 1 === strlen( $decimals ) ) {
			$decimals .= '0';
		}

		if ( '' === $decimals ) {
			$decimals = '00';
		}

		return ( $major * 100 ) + (int) $decimals;
	}

	/**
	 * Formats minor units for display.
	 */
	public static function format_minor( int $minor, ?string $currency = null ): string {
		$currency   = $currency ?: self::get_currency();
		$currencies = self::get_currencies();
		$config     = $currencies[ $currency ] ?? $currencies[ self::DEFAULT_CURRENCY ];
		$major      = intdiv( $minor, 100 );
		$cents      = abs( $minor % 100 );
		$amount     = number_format_i18n( $major, 0 ) . ( $config['decimals'] > 0 ? sprintf( '%s%02d', self::get_decimal_separator(), $cents ) : '' );

		return sprintf( '%s%s', $config['symbol'], $amount );
	}

	private static function get_decimal_separator(): string {
		$formatted = number_format_i18n( 1.1, 1 );

		return false !== strpos( $formatted, ',' ) ? ',' : '.';
	}

	/** Whether a recorded currency has explicit display support. */
	public static function supports_display_currency( string $currency ): bool {
		return isset( self::get_currencies()[ $currency ] ) || in_array( $currency, self::ZERO_DECIMAL_CHARGE_CURRENCIES, true );
	}

	/** Formats a recorded amount with one unambiguous currency marker. */
	public static function format_paid_total( int $minor, string $currency ): string {
		global $wp_locale;

		// Checkout currently writes EUR and USD. For display, also handle zero-decimal
		// Stripe charge units. ISK and UGX still use two decimal places in charge amounts.
		$currencies = self::get_currencies();
		$decimals = $currencies[ $currency ]['decimals'] ?? ( in_array( $currency, self::ZERO_DECIMAL_CHARGE_CURRENCIES, true ) ? 0 : 2 );
		$scale = 10 ** $decimals;
		$major = (string) intdiv( $minor, $scale );
		$grouping = $wp_locale->number_format['thousands_sep'] ?? ',';
		$amount = preg_replace_callback( '/\B(?=(?:[0-9]{3})+\b)/', static fn() => $grouping, ltrim( $major, '-' ) );
		if ( $decimals > 0 ) {
			$decimal = $wp_locale->number_format['decimal_point'] ?? '.';
			$amount .= $decimal . str_pad( (string) abs( $minor % $scale ), $decimals, '0', STR_PAD_LEFT );
		}

		$marker = 'USD' === $currency ? 'US$' : ( $currencies[ $currency ]['symbol'] ?? $currency );
		if ( isset( $currencies[ $currency ] ) ) {
			/* translators: 1: currency symbol (for example € or US$), 2: amount. */
			$formatted = sprintf( _x( '%1$s%2$s', 'amount with currency symbol', 'cpt2collection' ), $marker, $amount );
		} else {
			/* translators: 1: amount, 2: ISO currency code such as JPY. */
			$formatted = sprintf( _x( '%1$s %2$s', 'amount with ISO currency code', 'cpt2collection' ), $amount, $marker );
		}

		return ( $minor < 0 ? '-' : '' ) . $formatted;
	}

	/**
	 * Converts stored minor units to a safe editable decimal string.
	 */
	public static function minor_to_input( int $minor ): string {
		$major = intdiv( $minor, 100 );
		$cents = $minor % 100;

		return sprintf( '%d.%02d', $major, $cents );
	}
}
