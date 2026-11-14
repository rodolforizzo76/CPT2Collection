<?php
/**
 * Plugin post meta keys.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes CPT2Collection post meta key names.
 */
final class MetaKeys {
	public const PRICE_MINOR          = '_cpt2collection_price_minor';
	public const STATUS               = '_cpt2collection_status';
	public const STOCK_QUANTITY       = '_cpt2collection_stock_quantity';
	public const MINIMUM_ORDER_EXEMPT = '_cpt2collection_minimum_order_exempt';
	public const RATING               = '_cpt2collection_rating';
}
