<?php
/** Executable D2 behavioral verification assembled from the real production-service suites. */
declare( strict_types=1 );

$root = dirname( __DIR__ );
$suites = array(
	'tests/verify-stripe-webhook-foundation.php' => array(
		'valid Stripe signature', 'unpaid completed event is lifecycle-only', 'unsupported event remains receiptable',
		'same immutable duplicate is coherent', 'different duplicate hash conflicts without overwrite',
		'contradictory association cannot mutate identity', 'attention protocol rejects processing source',
	),
	'tests/verify-sale-conversion.php' => array(
		'real service commits fresh conversion', 'real service commits late expired conversion',
		'insufficient stock rolls back processing acquisition and attempt metadata cleanly',
		'matching idempotent replay consumes event without stock mutation or cache eviction',
		'second distinct event for same payment is idempotent',
		'same processed event replay is an idempotent no-op',
		'processed event with an unconverted local sale fails closed',
		'processing event is not reacquired or mutated',
		'later reservation transition failure rolls back earlier transition and stock',
		'rollback failure after stock mutation is uncertain and never retried',
		'ambiguous commit is not rolled back, retried, or followed by an event mutation',
	),
	'tests/verify-transaction-manager.php' => array(
		'failed BEGIN becomes uncertain', 'failed COMMIT becomes uncertain', 'failed ROLLBACK becomes uncertain',
	),
);
foreach ( $suites as $file => $evidence ) {
	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/' . $file );
	exec( $command . ' 2>&1', $lines, $status );
	$output = implode( "\n", $lines );
	if ( 0 !== $status ) { fwrite( STDERR, $output . "\n" ); exit( 1 ); }
	foreach ( $evidence as $label ) {
		if ( ! str_contains( $output, $label ) ) { fwrite( STDERR, "Missing behavioral evidence '{$label}' from {$file}.\n" ); exit( 1 ); }
		echo "PASS: {$label}\n";
	}
	$lines = array();
}

// HTTP mapping is executable value-object behavior, rather than a source-string assertion.
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . '/' );
require_once $root . '/includes/class-webhook-http-disposition.php';
use RodolfoRizzo\Cpt2Collection\WebhookHttpDisposition;
$mapping = array(
	array( WebhookHttpDisposition::accepted( 'processed' ), 200 ),
	array( WebhookHttpDisposition::bad_request( 'malformed_event' ), 400 ),
	array( WebhookHttpDisposition::failure( 'database_error' ), 500 ),
	array( WebhookHttpDisposition::failure( 'configuration', true ), 503 ),
);
foreach ( $mapping as list( $disposition, $expected ) ) {
	if ( $disposition->status() !== $expected ) { throw new RuntimeException( 'HTTP disposition mismatch' ); }
}
echo "PASS: HTTP disposition mapping\n";
echo "All Milestone 7F-D2 behavioral service checks passed.\n";
