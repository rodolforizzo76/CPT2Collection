<?php
/** Provider webhook outcome categories. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class WebhookOutcomeCategories {
	public const SUCCESS = 'success';
	public const DETERMINISTIC_ATTENTION = 'deterministic_attention';
	public const RETRYABLE = 'retryable';
	public const OUTCOME_UNKNOWN = 'outcome_unknown';

	public static function is_valid( string $category ): bool {
		return in_array( $category, array( self::SUCCESS, self::DETERMINISTIC_ATTENTION, self::RETRYABLE, self::OUTCOME_UNKNOWN ), true );
	}
}
