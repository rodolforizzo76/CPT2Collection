<?php
/** Fast persistence and schema verification for Cross-Tab Checkout Identity Step A. */
declare( strict_types=1 );
define( 'ABSPATH', __DIR__ );
function __( string $message, string $domain ): string { return $message; }
function get_option( string $name, mixed $default = false ): mixed { return 'EUR' === $default ? $default : $default; }
foreach ( array( 'money', 'order-statuses', 'cart-record', 'order-record', 'database' ) as $class ) require_once dirname( __DIR__ ) . '/includes/class-' . $class . '.php';
use RodolfoRizzo\Cpt2Collection\CartRecord;
use RodolfoRizzo\Cpt2Collection\Database;
use RodolfoRizzo\Cpt2Collection\OrderRecord;
function check_step_a( bool $condition, string $label ): void { if ( ! $condition ) throw new RuntimeException( $label ); echo "PASS: {$label}\n"; }
$cart = CartRecord::from_row( (object) array( 'id'=>1, 'token_hash'=>'hash', 'content_revision'=>'0', 'checkout_generation'=>'0', 'created_at'=>'2026-01-01 00:00:00', 'updated_at'=>'2026-01-01 00:00:00', 'last_activity_at'=>'2026-01-01 00:00:00', 'expires_at'=>'2027-01-01 00:00:00' ) );
check_step_a( 0 === $cart->content_revision() && 0 === $cart->checkout_generation(), 'CartRecord hydrates zero checkout identity' );
foreach ( array( '-1', 'abc', '1.5', PHP_INT_MAX . '0' ) as $bad ) { try { CartRecord::from_row( (object) array( 'id'=>1, 'token_hash'=>'hash', 'content_revision'=>$bad, 'checkout_generation'=>0, 'created_at'=>'x', 'updated_at'=>'x', 'last_activity_at'=>'x', 'expires_at'=>'x' ) ); throw new RuntimeException( 'malformed cart revision accepted' ); } catch ( InvalidArgumentException $e ) {} }
check_step_a( true, 'CartRecord rejects malformed, negative, and overflowing revisions' );
$base = array( 'id'=>1, 'cart_id'=>1, 'status'=>'pending', 'currency'=>'EUR', 'subtotal_minor'=>100, 'total_minor'=>100, 'created_at'=>'2026-01-01 00:00:00', 'updated_at'=>'2026-01-01 00:00:00', 'paid_at'=>null, 'cancelled_at'=>null, 'expired_at'=>null, 'failed_at'=>null );
$legacy = OrderRecord::from_row( (object) ( $base + array( 'cart_revision'=>null, 'checkout_generation'=>null ) ) );
$new = OrderRecord::from_row( (object) ( $base + array( 'cart_revision'=>'4', 'checkout_generation'=>'2' ) ) );
check_step_a( null === $legacy->cart_revision() && null === $legacy->checkout_generation(), 'historical OrderRecord NULL identity remains valid' );
check_step_a( null === $legacy->cart_reconciliation_status(), 'historical OrderRecord remains unclassified for cart reconciliation' );
check_step_a( 4 === $new->cart_revision() && 2 === $new->checkout_generation(), 'OrderRecord hydrates revision and generation' );
try { OrderRecord::from_row( (object) ( $base + array( 'cart_revision'=>'1', 'checkout_generation'=>null ) ) ); throw new RuntimeException( 'partial order identity accepted' ); } catch ( InvalidArgumentException $e ) {}
check_step_a( true, 'OrderRecord rejects partial checkout identity' );
$schema = file_get_contents( dirname( __DIR__ ) . '/includes/class-database.php' );
check_step_a( '0.12.0' === Database::SCHEMA_VERSION, 'database schema version is 0.12.0' );
check_step_a( str_contains( $schema, 'content_revision BIGINT UNSIGNED NOT NULL DEFAULT 0' ) && str_contains( $schema, 'checkout_generation BIGINT UNSIGNED NOT NULL DEFAULT 0' ), 'carts schema contains revision and generation defaults' );
check_step_a( str_contains( $schema, 'cart_revision BIGINT UNSIGNED NULL' ) && str_contains( $schema, 'checkout_generation BIGINT UNSIGNED NULL' ), 'orders schema contains nullable checkout identity' );
check_step_a( str_contains( $schema, 'content_version BIGINT UNSIGNED NOT NULL DEFAULT 0' ) && str_contains( $schema, 'source_cart_item_id BIGINT UNSIGNED NULL' ) && str_contains( $schema, 'source_cart_item_version BIGINT UNSIGNED NULL' ), 'post-checkout item identity columns preserve legacy rows' );
check_step_a( str_contains( $schema, 'UNIQUE KEY cart_revision_generation (cart_id, cart_revision, checkout_generation)' ), 'revision-generation checkout identity is unique' );
$plugin = file_get_contents( dirname( __DIR__ ) . '/cpt2collection.php' );
$assets = file_get_contents( dirname( __DIR__ ) . '/includes/frontend/class-assets.php' );
check_step_a( str_contains( $plugin, 'Version: 0.9.0' ) && str_contains( $plugin, "CPT2COLLECTION_VERSION', '0.9.0" ), 'plugin release version remains 0.9.0' );
check_step_a( str_contains( $assets, "CPT2COLLECTION_VERSION" ), 'frontend assets derive cache version from plugin release' );
echo "All Cross-Tab Checkout Identity Step A checks passed.\n";
