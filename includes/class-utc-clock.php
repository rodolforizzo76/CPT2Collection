<?php
/**
 * UTC clock helpers.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

/**
 * Provides UTC timestamps suitable for MySQL DATETIME columns.
 */
final class UtcClock {
	public function now_timestamp(): int {
		return function_exists( 'current_time' ) ? (int) current_time( 'timestamp', true ) : time();
	}

	public function now_mysql(): string {
		return $this->format_mysql( $this->now_timestamp() );
	}

	public function plus_seconds_mysql( int $seconds, ?int $from_timestamp = null ): string {
		$from_timestamp = $from_timestamp ?? $this->now_timestamp();

		return $this->format_mysql( $from_timestamp + $seconds );
	}

	public function format_mysql( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	public function mysql_to_timestamp( string $mysql_datetime ): int {
		$timestamp = strtotime( $mysql_datetime . ' UTC' );

		return false === $timestamp ? 0 : $timestamp;
	}
}
