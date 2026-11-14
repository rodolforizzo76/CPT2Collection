<?php
/**
 * Secure cart cookie abstraction.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the raw cart token cookie without exposing it to JavaScript.
 */
final class CartCookie {
	public const COOKIE_NAME      = 'cpt2collection_cart_token';
	public const LIFETIME_SECONDS = 30 * DAY_IN_SECONDS;

	private CartToken $tokens;

	public function __construct( CartToken $tokens ) {
		$this->tokens = $tokens;
	}

	public function read(): ?string {
		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) || ! is_string( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return null;
		}

		return $this->tokens->normalize( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) );
	}

	public function write( string $raw_token, int $expires_at ): bool {
		$raw_token = $this->tokens->normalize( $raw_token );
		if ( null === $raw_token || headers_sent() ) {
			return false;
		}

		$result = setcookie( self::COOKIE_NAME, $raw_token, $this->cookie_options( $expires_at ) );
		if ( $result ) {
			$_COOKIE[ self::COOKIE_NAME ] = $raw_token;
		}

		return $result;
	}

	public function clear(): bool {
		if ( headers_sent() ) {
			return false;
		}

		$result = setcookie( self::COOKIE_NAME, '', $this->cookie_options( time() - HOUR_IN_SECONDS ) );
		unset( $_COOKIE[ self::COOKIE_NAME ] );

		return $result;
	}

	/**
	 * @return array{expires:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string}
	 */
	private function cookie_options( int $expires_at ): array {
		$path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		$domain = defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '';

		return array(
			'expires'  => $expires_at,
			'path'     => $path,
			'domain'   => $domain,
			'secure'   => function_exists( 'is_ssl' ) && is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
	}
}
