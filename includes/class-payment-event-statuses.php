<?php
/** Payment-event processing statuses. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;
final class PaymentEventStatuses {
	public const RECEIVED = 'received';
	public const PROCESSING = 'processing';
	public const PROCESSED = 'processed';
	public const IGNORED = 'ignored';
	public const RETRYABLE_FAILED = 'retryable_failed';
	public const ATTENTION = 'attention';
	/** @return string[] */
	public static function all(): array { return array( self::RECEIVED, self::PROCESSING, self::PROCESSED, self::IGNORED, self::RETRYABLE_FAILED, self::ATTENTION ); }
	public static function is_valid( string $status ): bool { return in_array( $status, self::all(), true ); }
	public static function is_terminal( string $status ): bool { return in_array( $status, array( self::PROCESSED, self::IGNORED, self::ATTENTION ), true ); }
}
