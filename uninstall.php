<?php
/**
 * CPT2Collection uninstall handler.
 *
 * This milestone intentionally preserves all plugin options, post metadata, and
 * future data. A destructive uninstall may be added later only behind an
 * explicit opt-in setting.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
