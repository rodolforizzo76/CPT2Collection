<?php
/** Load the actual plugin entry point, without invoking WordPress hooks or commerce. */
declare( strict_types=1 );
define( 'ABSPATH', __DIR__ . '/' );
class wpdb {}
function plugin_dir_path( string $file ): string { return dirname( $file ) . '/'; }
function plugin_dir_url( string $file ): string { return 'https://example.test/'; }
function register_activation_hook( string $file, callable $hook ): void {}
function register_deactivation_hook( string $file, callable $hook ): void {}
function add_action( string $name, callable $hook ): void {}
require dirname( __DIR__ ) . '/cpt2collection.php';
foreach ( array( 'CartReconciliationBoundary', 'CartReconciliationStageInspection', 'PaymentEventContinuation', 'PaymentEventStageInspection', 'CommittedOperation', 'CommittedOperationAuthority', 'OperationalRecoveryInspector', 'OperationalInspectionResult', 'ProviderWebhookEvidence', 'ProviderResponseEvidence', 'ProviderEvidenceRouter', 'ProviderEvidenceContext', 'ProviderEvidenceResult', 'ProviderEvidenceRecovery', 'ProviderRetainedDiscovery', 'ProviderClaimLocator', 'ProviderDiscoveryCandidate', 'ProviderDiscoveryResult', 'ProviderIdentityDiscovery', 'ProviderOwnerDiscovery', 'CommerceResetHistory', 'CommerceDeletedOrderIndexesMigration', 'CommerceClaimSessionIndexMigration', 'CommerceOrderSourceIndexMigration', 'CommerceBaseline', 'CommerceBaselineMigration', 'CommerceBaselinePurgeMigration', 'CommerceBaselinePurgeRecord', 'CommerceBaselinePurgeResult', 'CommerceBaselinePurgeService', 'CommercePhaseFourCheckpointOneMigration', 'CommerceEvidenceGenerationMigration', 'CommerceResetRecord', 'CommerceResetLifecycle', 'CommerceResetResult', 'CommerceResetService', 'CommerceResetRestController', 'CommerceResetRepository', 'CommerceResetValidation', 'CommerceResetRetirement', 'CommerceResetProviderCoverage', 'CommerceResetRetainedEvidence', 'CommerceResetDeletion', 'CommerceResetContext', 'CommerceEvidenceContext', 'ProviderClaimRecord' ) as $class ) {
	if ( ! class_exists( 'RodolfoRizzo\\Cpt2Collection\\' . $class, false ) ) { throw new RuntimeException( 'Missing bootstrap class: ' . $class ); }
	echo "PASS: plugin bootstrap loads $class without test autoloading\n";
}
if ( '0.21.0' !== \RodolfoRizzo\Cpt2Collection\Database::SCHEMA_VERSION ) { throw new RuntimeException( 'Unexpected schema version.' ); }
echo "PASS: actual plugin bootstrap exposes schema 0.21.0\n";

if (!interface_exists('RodolfoRizzo\\Cpt2Collection\\OperationalStageInspection',false)) throw new RuntimeException('Missing stage inspection interface.');
if(!class_exists('RodolfoRizzo\\Cpt2Collection\\PaymentFinancialStageInspection',false))throw new RuntimeException('Missing D3 financial stage predicate.');
echo "PASS: D3 financial stage predicate loads from production bootstrap\n";
echo "PASS: plugin bootstrap loads stage inspection contract\n";

if (!class_exists('RodolfoRizzo\\Cpt2Collection\\Admin\\CommerceResetPage',false)) throw new RuntimeException('Missing reset admin page.');
echo "PASS: bootstrap loads the dedicated reset admin page\n";
