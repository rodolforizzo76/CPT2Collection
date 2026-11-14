<?php
/** Build-only PHP-Scoper configuration. */

declare( strict_types=1 );

use Isolated\Symfony\Component\Finder\Finder;

$input = getenv( 'CPT2COLLECTION_SCOPER_INPUT' );
if ( false === $input || '' === $input || ! is_dir( $input ) ) {
	throw new RuntimeException( 'CPT2COLLECTION_SCOPER_INPUT must identify the release staging directory.' );
}

return array(
	'prefix'             => 'CPT2CollectionVendor',
	'finders'            => array(
		Finder::create()
			->files()
			->in( $input )
			->exclude( array( 'tests', 'cpt2collection-7b-harness' ) )
			// Composer owns these generated bootstrap files. They are copied back
			// and regenerated after PHP-Scoper has transformed the application and
			// dependency sources.
			->notPath( '#^vendor/composer(?:/|$)#' ),
	),
	'exclude-namespaces' => array( 'RodolfoRizzo\\Cpt2Collection' ),
	// These classes and interfaces are supplied by WordPress at runtime. An
	// excluded application namespace only preserves its declaration; PHP-Scoper
	// still scopes class references inside it unless the external symbols are
	// explicitly excluded. Keep this inventory in sync with WordPress types used
	// by the plugin. WordPress functions and constants are not declared in the
	// release tree, so PHP-Scoper leaves those external runtime symbols global.
	'exclude-classes'    => array(
		'WP_Error',
		'WP_Post',
		'WP_Post_Type',
		'WP_Query',
		'WP_REST_Request',
		'WP_REST_Response',
		'WP_REST_Server',
		'wpdb',
	),
	'patchers'           => array(
		static function ( string $file_path, string $prefix, string $contents ): string {
			// PHP-Scoper does not promise to rewrite class names used as diagnostic strings.
			if ( str_ends_with( $file_path, 'class-stripe-checkout-provider.php' ) ) {
				$contents = str_replace( "'Stripe\\\\'", "'{$prefix}\\\\Stripe\\\\'", $contents );
			}
			if ( str_ends_with( $file_path, 'class-stripe-checkout-provider.php' ) ) {
				$contents = str_replace( "'Stripe\\\\StripeClient'", "'{$prefix}\\\\Stripe\\\\StripeClient'", $contents );
			}

			return $contents;
		},
	),
);
