<?php
/** Artifact-level namespace isolation and collision verification. */

declare( strict_types=1 );

function has_current_commerce_schema( string $root ): bool {
	$database = file_get_contents( $root . '/includes/class-database.php' );
	return false !== $database
		&& 1 === preg_match( "/\\bconst SCHEMA_VERSION\\s*=\\s*'0\\.21\\.0';/", $database )
		&& is_readable( $root . '/includes/class-order-customer-migration.php' );
}

// Source-only verification for infrastructure checkpoints that explicitly forbid builds.
if ( '--source' === ( $argv[1] ?? '' ) ) {
	require __DIR__ . '/verify-commerce-reset-loading.php';
	if ( ! has_current_commerce_schema( dirname( __DIR__ ) ) || ! class_exists( \RodolfoRizzo\Cpt2Collection\OrderCustomerMigration::class, false ) ) {
		throw new RuntimeException( 'Current commerce schema or customer migration missing from source.' );
	}
	echo "PASS: source matches the artifact schema and loads the customer migration\n";
	foreach ( array( 'commerce-reset-lifecycle', 'commerce-reset-result', 'commerce-reset-service', 'commerce-reset-repository', 'commerce-reset-validation', 'commerce-reset-retirement', 'commerce-reset-provider-coverage', 'commerce-reset-retained-evidence', 'commerce-reset-deletion', 'commerce-baseline', 'commerce-baseline-migration', 'commerce-order-source-index-migration', 'commerce-claim-session-index-migration', 'commerce-deleted-order-indexes-migration', 'commerce-baseline-purge-migration', 'commerce-baseline-purge-record', 'commerce-baseline-purge-result', 'commerce-baseline-purge-service' ) as $name ) {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-' . $name . '.php' );
		if ( false === $source || ! preg_match( '/final class (Commerce[A-Za-z]+)/', $source, $match ) || ! class_exists( 'RodolfoRizzo\\Cpt2Collection\\' . $match[1], false ) ) { throw new RuntimeException( 'Reset production class unavailable: ' . $name ); }
	}
	echo "PASS: release source/reset classes load; no built artifact verified or generated\n";
	exit( 0 );
}

$artifact = isset( $argv[1] ) ? realpath( $argv[1] ) : false;
if ( false === $artifact || ! is_dir( $artifact ) ) {
	fwrite( STDERR, "Usage: php tests/verify-release-artifact.php <artifact-directory>\n" );
	exit( 1 );
}

function artifact_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "Artifact verification failed: {$message}\n" );
		exit( 1 );
	}
}

/** Run a verifier regex without allowing an invalid pattern to pass as "no match". */
function artifact_pattern_matches( string $pattern, string $subject, string $context ): bool {
	$result = @preg_match( $pattern, $subject );
	artifact_check( false !== $result, 'regex failed for ' . $context . ': ' . preg_last_error_msg() );

	return 1 === $result;
}

$prefixed_wordpress_pattern = '/\bCPT2CollectionVendor\\\\(?:WP_[A-Za-z0-9_]+|wpdb)\b/';
artifact_check( artifact_pattern_matches( $prefixed_wordpress_pattern, 'CPT2CollectionVendor\WP_Query', 'prefixed WP_Query probe' ), 'WordPress symbol pattern does not match prefixed WP_Query' );
artifact_check( artifact_pattern_matches( $prefixed_wordpress_pattern, 'CPT2CollectionVendor\wpdb', 'prefixed wpdb probe' ), 'WordPress symbol pattern does not match prefixed wpdb' );
artifact_check( ! artifact_pattern_matches( $prefixed_wordpress_pattern, 'WP_Query', 'global WP_Query probe' ), 'WordPress symbol pattern matches global WP_Query' );
artifact_check( ! artifact_pattern_matches( $prefixed_wordpress_pattern, 'wpdb', 'global wpdb probe' ), 'WordPress symbol pattern matches global wpdb' );

artifact_check( is_readable( $artifact . '/cpt2collection-release-autoload.php' ), 'release loader is absent' );
artifact_check( is_readable( $artifact . '/vendor/autoload.php' ), 'scoped Composer autoloader is absent' );
artifact_check( ! is_dir( $artifact . '/tests' ), 'tests were packaged' );
foreach ( array( 'AGENTS.md', 'docs', 'tools', 'node_modules', '.deploy-stage.env', '.deploy-stage.env.example' ) as $excluded ) {
	artifact_check( ! file_exists( $artifact . '/' . $excluded ), 'development-only file packaged: ' . $excluded );
}
$metadata = file_get_contents( $artifact . '/cpt2collection.php' );
artifact_check( false !== $metadata && str_contains( $metadata, "CPT2COLLECTION_VERSION', '1.0.0'" ), 'release asset version mismatch' );
foreach ( array( 'Version: 1.0.0', 'Requires at least: 6.4', 'Requires PHP: 8.2', 'Text Domain: cpt2collection', 'Domain Path: /languages' ) as $header ) {
	artifact_check( str_contains( $metadata, " * {$header}\n" ), 'release header mismatch: ' . $header );
}

artifact_check( ! is_dir( $artifact . '/cpt2collection-7b-harness' ), 'browser harness was packaged' );
artifact_check( ! is_dir( $artifact . '/vendor/humbug/php-scoper' ), 'PHP-Scoper was packaged' );
artifact_check( ! file_exists( $artifact . '/composer.json' ), 'source Composer manifest was packaged' );

foreach ( array( 'commerce-baseline', 'commerce-baseline-migration', 'commerce-order-source-index-migration', 'commerce-claim-session-index-migration', 'commerce-deleted-order-indexes-migration', 'commerce-baseline-purge-migration', 'commerce-baseline-purge-record', 'commerce-baseline-purge-result', 'commerce-baseline-purge-service', 'commerce-connection', 'commerce-wpdb', 'commerce-context', 'commerce-control-repository', 'commerce-foundation', 'commerce-phase-four-checkpoint-one-migration', 'commerce-evidence-generation-migration', 'commerce-reset-record', 'commerce-reset-lifecycle', 'commerce-reset-result', 'commerce-reset-service', 'commerce-reset-context', 'commerce-evidence-context', 'provider-claim-record', 'commerce-reset-repository', 'commerce-reset-validation', 'commerce-reset-retirement', 'commerce-reset-provider-coverage', 'commerce-reset-retained-evidence' ) as $class ) {
	artifact_check( is_readable( $artifact . '/includes/class-' . $class . '.php' ), 'commerce foundation class absent: ' . $class );
}

artifact_check( has_current_commerce_schema( $artifact ), 'release schema or customer migration mismatch' );
foreach ( array( 'cron', 'dispatcher', 'email', 'migration' ) as $component ) {
	artifact_check( is_readable( $artifact . '/includes/class-payment-notification-' . $component . '.php' ), 'payment notification component absent: ' . $component );
}

$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $artifact, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	if ( 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$contents = file_get_contents( $file->getPathname() );
	artifact_check( false !== $contents, 'could not read ' . $file->getPathname() );
	artifact_check( ! artifact_pattern_matches( '/^\s*namespace\s+Stripe(?:\s*;|\\\\)/m', $contents, 'Stripe namespace scan in ' . $file->getPathname() ), 'global Stripe namespace declaration in ' . $file->getPathname() );
	artifact_check( ! artifact_pattern_matches( '/\b(?:use|new|extends|implements|instanceof|catch\s*\()\s*\\?Stripe\\\\/m', $contents, 'Stripe runtime scan in ' . $file->getPathname() ), 'global Stripe runtime reference in ' . $file->getPathname() );
	// WordPress owns its global WP_* and wpdb runtime types. This generalized
	// guard catches current and future WordPress class references accidentally
	// moved under the dependency prefix, rather than testing only known types.
	artifact_check( ! artifact_pattern_matches( $prefixed_wordpress_pattern, $contents, 'WordPress runtime scan in ' . $file->getPathname() ), 'prefixed WordPress runtime symbol in ' . $file->getPathname() );
	}

$list_table_source = file_get_contents( $artifact . '/includes/admin/class-list-table.php' );
artifact_check( false !== $list_table_source, 'could not read the generated list table source' );
artifact_check( artifact_pattern_matches( '/public function adjust_query\(\s*WP_Query \$query\s*\): void/', $list_table_source, 'list table WP_Query signature scan' ), 'list table no longer type-hints global WP_Query' );

// An external plugin and its autoloader are deliberately present first.
spl_autoload_register(
	static function ( string $class ): void {
		if ( 'ExternalComposer\\Probe' === $class ) {
			eval( 'namespace ExternalComposer; final class Probe {}' );
		}
	},
	true,
	true
);
$GLOBALS['c2c_global_stripe_constructed'] = false;
eval( 'namespace Stripe; final class StripeClient { public function __construct(...$args) { $GLOBALS["c2c_global_stripe_constructed"] = true; } }' );

// Exercise the generated Composer entry point directly: the release loader is
// intentionally only a small production-facing wrapper around this file.
$release_loader = require $artifact . '/vendor/autoload.php';
$generated_map = require $artifact . '/vendor/composer/autoload_classmap.php';
foreach ( $generated_map as $class => $target ) {
	artifact_check( is_readable( $target ) && str_starts_with( realpath( $target ), $artifact . DIRECTORY_SEPARATOR ), 'invalid classmap target: ' . $class );
	artifact_check( realpath( (string) $release_loader->findFile( $class ) ) === realpath( $target ), 'active/generated classmap mismatch: ' . $class );
	artifact_check( class_exists( $class ) || interface_exists( $class ) || trait_exists( $class ), 'classmap symbol cannot load: ' . $class );
}
echo 'PASS: all ' . count( $generated_map ) . " generated classmap targets exist, match the active loader and load\n";

artifact_check( $release_loader instanceof Composer\Autoload\ClassLoader, 'generated vendor/autoload.php did not return a Composer ClassLoader' );
artifact_check( false === $release_loader->findFile( 'Stripe\\StripeClient' ), 'generated autoloader exposes the bundled SDK as global Stripe classes' );
$psr4_prefixes = $release_loader->getPrefixesPsr4();
artifact_check( ! isset( $psr4_prefixes['Stripe\\'] ), 'generated autoloader retains the input Stripe PSR-4 mapping' );
artifact_check( isset( $psr4_prefixes['CPT2CollectionVendor\\Stripe\\'] ), 'generated autoloader lacks the scoped Stripe PSR-4 mapping' );
artifact_check( count( $release_loader->getClassMap() ) > 100, 'authoritative class map did not index the scoped dependency tree' );
artifact_check( class_exists( 'ExternalComposer\\Probe' ), 'external Composer-style autoloader stopped working' );
artifact_check( class_exists( 'CPT2CollectionVendor\\Stripe\\StripeClient' ), 'prefixed StripeClient is unavailable' );
artifact_check( class_exists( 'CPT2CollectionVendor\\Stripe\\Exception\\ApiErrorException' ), 'prefixed Stripe exception hierarchy is unavailable' );
artifact_check( is_subclass_of( 'CPT2CollectionVendor\\Stripe\\Exception\\ApiErrorException', Throwable::class ), 'prefixed Stripe exception is not throwable' );
$scoped_stripe = new CPT2CollectionVendor\Stripe\StripeClient( 'sk_test_autoload-isolation' );
artifact_check( $scoped_stripe instanceof CPT2CollectionVendor\Stripe\StripeClient, 'prefixed StripeClient resolved to the wrong class' );
artifact_check( ! $scoped_stripe instanceof Stripe\StripeClient, 'prefixed StripeClient resolved to the global trap' );
artifact_check( false === $GLOBALS['c2c_global_stripe_constructed'], 'global StripeClient was used by the release autoloader' );

$sdk_source = file_get_contents( $artifact . '/includes/class-stripe-sdk-checkout-client.php' );
$provider_source = file_get_contents( $artifact . '/includes/class-stripe-checkout-provider.php' );
artifact_check( str_contains( (string) $sdk_source, 'CPT2CollectionVendor\\Stripe\\StripeClient' ), 'SDK client does not reference prefixed StripeClient' );
artifact_check( str_contains( (string) $provider_source, 'CPT2CollectionVendor\\Stripe\\Exception' ), 'provider does not reference prefixed exceptions' );
artifact_check( ! str_contains( (string) $provider_source, "class_exists( 'Stripe\\\\StripeClient'" ), 'global Stripe runtime check remains' );

define( 'ABSPATH', $artifact . '/' );
$GLOBALS['c2c_artifact_options'] = array(
	'cpt2collection_stripe_secret_key' => 'sk_test_artifact-key',
	'cpt2collection_stripe_environment' => 'test',
	'cpt2collection_stripe_success_url' => 'https://example.com/success',
	'cpt2collection_stripe_cancel_url' => 'https://example.com/cancel',
);
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['c2c_artifact_options'][ $key ] ?? $default; }
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { return $value; }
function __( string $text, ?string $domain = null ): string { return $text; }

foreach ( array( 'commerce-phase-four-checkpoint-one-migration', 'commerce-evidence-generation-migration', 'commerce-reset-record', 'commerce-reset-lifecycle', 'commerce-reset-result', 'commerce-reset-service', 'commerce-reset-context', 'commerce-evidence-context', 'provider-claim-record', 'commerce-reset-repository', 'commerce-reset-validation', 'commerce-reset-retirement', 'commerce-reset-provider-coverage', 'commerce-reset-retained-evidence', 'commerce-reset-deletion', 'commerce-context', 'money', 'utc-clock', 'payment-provider-line-item', 'payment-provider-session-request', 'payment-provider-session-result', 'payment-provider-exception', 'payment-provider-interface', 'stripe-settings', 'stripe-checkout-client-interface', 'stripe-sdk-checkout-client', 'stripe-checkout-provider' ) as $class ) {
	require_once $artifact . '/includes/class-' . $class . '.php';
}

final class ArtifactCaptureClient implements RodolfoRizzo\Cpt2Collection\StripeCheckoutClientInterface {
	public array $parameters = array();
	public array $options = array();
	public ?Throwable $error = null;
	public function create_checkout_session( array $parameters, array $options ): array {
		$this->parameters = $parameters;
		$this->options = $options;
		if ( null !== $this->error ) { throw $this->error; }
		return array( 'id' => 'cs_artifact', 'url' => 'https://checkout.stripe.com/c/pay/artifact', 'expires_at' => $parameters['expires_at'] );
	}
}

$clock = new RodolfoRizzo\Cpt2Collection\UtcClock();
$deadline = gmdate( 'Y-m-d H:i:s', time() + 2100 );
$client = new ArtifactCaptureClient();
$provider = new RodolfoRizzo\Cpt2Collection\StripeCheckoutProvider( $client, $clock );
$installation_id = str_repeat( '1', 32 );
$epoch_identity = str_repeat( '2', 32 );
$configuration = $provider->request_configuration( new RodolfoRizzo\Cpt2Collection\CommerceContext( $installation_id, 1, $epoch_identity ) );
$reference = str_repeat( 'a', 32 );
$request = new RodolfoRizzo\Cpt2Collection\PaymentProviderSessionRequest(
	$reference,
	'c2c_ps_' . $reference,
	'EUR',
	2500,
	array( new RodolfoRizzo\Cpt2Collection\PaymentProviderLineItem( 'Artifact item', 1250, 2 ) ),
	$deadline,
	$configuration
);
$result = $provider->create_session( $request );
$params = $client->parameters;
artifact_check( 'payment' === $client->parameters['mode'], 'Checkout payment mode changed' );
artifact_check( array( 'card' ) === $client->parameters['payment_method_types'], 'card-only setting changed' );
artifact_check( strtotime( $deadline ) === $client->parameters['expires_at'], 'exact reservation deadline changed' );
artifact_check( $reference === $client->parameters['client_reference_id'], 'client reference changed' );
artifact_check( $reference === $client->parameters['metadata']['c2c_payment_session_reference'], 'metadata reference changed' );
artifact_check( $installation_id === $client->parameters['metadata']['c2c_installation_id'], 'installation metadata changed' );
artifact_check( $epoch_identity === $client->parameters['metadata']['c2c_epoch_identity'], 'epoch metadata changed' );
artifact_check( 'c2c_ps_' . $reference === $client->options['idempotency_key'], 'idempotency key changed' );
artifact_check( 'eur' === $client->parameters['line_items'][0]['price_data']['currency'], 'EUR normalization changed' );
artifact_check( 'cs_artifact' === $result->provider_session_id(), 'hosted Checkout result changed' );

$usd_request = new RodolfoRizzo\Cpt2Collection\PaymentProviderSessionRequest( str_repeat( 'b', 32 ), 'usd-key', 'USD', 100, array( new RodolfoRizzo\Cpt2Collection\PaymentProviderLineItem( 'USD item', 100, 1 ) ), $deadline, $configuration );
$provider->create_session( $usd_request );
artifact_check( 'usd' === $client->parameters['line_items'][0]['price_data']['currency'], 'USD normalization changed' );

$client->error = new CPT2CollectionVendor\Stripe\Exception\AuthenticationException( 'artifact exception test' );
try {
	$provider->create_session( $request );
	artifact_check( false, 'prefixed Stripe exception was not thrown' );
} catch ( RodolfoRizzo\Cpt2Collection\PaymentProviderException $error ) {
	artifact_check( RodolfoRizzo\Cpt2Collection\PaymentProviderException::REJECTED === $error->failure_type(), 'prefixed Stripe exception was not classified' );
}

class_alias( CPT2CollectionVendor\Stripe\HttpClient\ClientInterface::class, 'CheckoutTestTransportInterface' );
require __DIR__ . '/support/stripe-checkout-transport.php';
$transport = new StripeCheckoutTestTransport();
CPT2CollectionVendor\Stripe\ApiRequestor::setHttpClient( $transport );
$result = RodolfoRizzo\Cpt2Collection\StripeCheckoutProvider::configured( $clock )->create_session( $request );
$transport->assert_checkout_request( $request->idempotency_key(), $params );
artifact_check( 'cs_test_sdk_boundary' === $result->provider_session_id(), 'scoped SDK response did not map to the generic result' );
artifact_check( false === $GLOBALS['c2c_global_stripe_constructed'], 'production provider constructed the global Stripe client' );
echo "PASS: scoped SDK sends the pinned Checkout header and maps the response\n";

require_once $artifact . '/includes/class-stripe-webhook-verification-result.php';
require_once $artifact . '/includes/class-stripe-webhook-verifier.php';
$webhook_secret = 'whsec_artifact_fixture';
$verifier = new RodolfoRizzo\Cpt2Collection\StripeWebhookVerifier( $webhook_secret );
$timestamp = time();
foreach ( array( '2026-07-29.dahlia', '2026-08-26.dahlia' ) as $event_version ) {
	$payload = json_encode(
		array( 'id' => 'evt_artifact', 'object' => 'event', 'type' => 'checkout.session.completed', 'api_version' => $event_version ),
		JSON_THROW_ON_ERROR
	);
	$signature = 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $payload, $webhook_secret );
	$verification = $verifier->verify( $payload, $signature );
	artifact_check( $verification->is_verified() && $event_version === ( $verification->event()['api_version'] ?? null ), 'scoped webhook verification did not preserve the signed Event version' );
	artifact_check( ! $verifier->verify( $payload . ' ', $signature )->is_verified(), 'scoped webhook verifier accepted a changed raw body' );
}
echo "PASS: scoped webhook verification preserves the signed Event API version\n";

echo "Release artifact namespace-isolation verification passed.\n";
