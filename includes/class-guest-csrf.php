<?php
/**
 * Same-origin CSRF protection for public REST mutations.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

final class GuestCsrf {
	public const HEADER_NAME = 'X-CPT2Collection-CSRF';
	private const WINDOW_SECONDS = 3600;

	public function create_token(): string {
		return $this->token_for_window( $this->current_window() );
	}

	public function verify_request( \WP_REST_Request $request ): bool {
		$content_type = strtolower( (string) $request->get_header( 'content-type' ) );
		if ( 0 !== strpos( $content_type, 'application/json' ) ) {
			return false;
		}

		$source = (string) $request->get_header( 'origin' );
		if ( '' === $source ) {
			$source = (string) $request->get_header( 'referer' );
		}
		if ( ! $this->is_same_origin( $source, home_url( '/' ) ) ) {
			return false;
		}

		$provided = (string) $request->get_header( self::HEADER_NAME );
		if ( '' === $provided ) {
			return false;
		}

		$window = $this->current_window();
		return hash_equals( $this->token_for_window( $window ), $provided )
			|| hash_equals( $this->token_for_window( $window - 1 ), $provided );
	}

	private function current_window(): int {
		return intdiv( time(), self::WINDOW_SECONDS );
	}

	private function token_for_window( int $window ): string {
		return hash_hmac( 'sha256', 'cpt2collection-rest-cart|' . $window, wp_salt( 'nonce' ) );
	}

	private function is_same_origin( string $source, string $site ): bool {
		$source_parts = wp_parse_url( $source );
		$site_parts   = wp_parse_url( $site );
		if ( ! is_array( $source_parts ) || ! is_array( $site_parts ) ) {
			return false;
		}

		return strtolower( (string) ( $source_parts['scheme'] ?? '' ) ) === strtolower( (string) ( $site_parts['scheme'] ?? '' ) )
			&& strtolower( (string) ( $source_parts['host'] ?? '' ) ) === strtolower( (string) ( $site_parts['host'] ?? '' ) )
			&& $this->port( $source_parts ) === $this->port( $site_parts );
	}

	/** @param array<string,mixed> $parts */
	private function port( array $parts ): int {
		if ( isset( $parts['port'] ) ) {
			return (int) $parts['port'];
		}

		return 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) ? 443 : 80;
	}
}
