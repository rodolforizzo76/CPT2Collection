<?php
/**
 * Cart token generation and hashing.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

/**
 * Handles raw browser cart tokens and their database hashes.
 */
final class CartToken {
	public const TOKEN_PATTERN = '/\A[0-9a-fA-F]{64}\z/';

	/**
	 * Generates a 256-bit random raw token represented as lowercase hex.
	 *
	 * @throws \Random\RandomException When secure randomness is unavailable.
	 */
	public function generate(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Returns a normalized lowercase token, or null for malformed input.
	 */
	public function normalize( string $token ): ?string {
		$token = trim( $token );

		if ( ! preg_match( self::TOKEN_PATTERN, $token ) ) {
			return null;
		}

		return strtolower( $token );
	}

	public function hash( string $raw_token ): string {
		$normalized = $this->normalize( $raw_token );

		if ( null === $normalized ) {
			return '';
		}

		return hash( 'sha256', $normalized );
	}
}
