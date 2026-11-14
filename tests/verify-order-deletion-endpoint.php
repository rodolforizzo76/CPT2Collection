<?php
/** Focused unit verification for the administrator deletion REST contract. */
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
function __( string $message, string $domain = '' ): string { return $message; }
require __DIR__ . '/support/reset-rest.php';
final class WP_REST_Server { public const DELETABLE = 'DELETE'; }
function endpoint_check( bool $condition, string $label ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } echo "PASS: {$label}\n"; }

require_once dirname( __DIR__ ) . '/includes/class-commerce-context.php';
require_once dirname( __DIR__ ) . '/includes/class-order-deletion-result.php';
require_once dirname( __DIR__ ) . '/includes/class-order-deletion-rest-controller.php';

use RodolfoRizzo\Cpt2Collection\OrderDeletionRestController;
use RodolfoRizzo\Cpt2Collection\OrderDeletionResult;

function delete_body( string $id = '7' ): array { return array( 'confirmation' => 'DELETE ORDER C2C-' . $id, 'expected_context' => array( 'installation_id' => str_repeat( 'a', 32 ), 'epoch_id' => '2', 'epoch_identity' => str_repeat( 'b', 32 ) ) ); }
function delete_request( string $id = '7', ?array $body = null, array $query = array(), ?string $raw = null, string $type = 'application/json', array $headers = array() ): WP_REST_Request { return new WP_REST_Request( array( 'order_id' => $id ), $body ?? delete_body( $id ), $query, $raw, $type, $headers ); }
$calls = 0;
$next = OrderDeletionResult::success( OrderDeletionResult::DELETED, 7 );
$controller = new OrderDeletionRestController( static function ( int $order_id, \RodolfoRizzo\Cpt2Collection\CommerceContext $expected ) use ( &$calls, &$next ): OrderDeletionResult { ++$calls; endpoint_check( $expected->installation_id() === str_repeat('a',32) && $expected->epoch_id() === 2 && $expected->epoch_identity() === str_repeat('b',32), 'exact immutable context reaches delegate' ); return $next; } );
$controller->register();
$route = OrderDeletionRestController::ROUTE; [ $namespace, $arguments ] = $GLOBALS['reset_routes'][$route];
endpoint_check( OrderDeletionRestController::REST_NAMESPACE === $namespace && OrderDeletionRestController::ROUTE === $route, 'one-order route registers in the plugin namespace' );
endpoint_check( WP_REST_Server::DELETABLE === $arguments['methods'] && array( $controller, 'delete' ) === $arguments['callback'], 'route accepts DELETE only' );
endpoint_check( array( $controller, 'authorize' ) === $arguments['permission_callback'], 'route has an explicit capability callback' );

$GLOBALS['reset_capable'] = false;
endpoint_check( $controller->authorize() instanceof WP_Error && 403 === $controller->authorize()->data['status'] && 0 === $calls, 'unauthenticated or non-capable user is denied before service invocation' );
$GLOBALS['reset_capable'] = true;
endpoint_check( true === $controller->authorize(), 'manage_options administrator is authorized' );

// WordPress rejects invalid cookie nonces in REST authentication before route permission/callback dispatch.
$dispatch = static function ( bool $rest_auth_valid, WP_REST_Request $request ) use ( $controller ): WP_REST_Response|WP_Error {
	if ( ! $rest_auth_valid ) { return new WP_Error( 'rest_cookie_invalid_nonce', 'Cookie check failed', array( 'status' => 403 ) ); }
	$permission = $controller->authorize();
	return true === $permission ? $controller->delete( $request ) : $permission;
};
$before = $calls; $nonce_failure = $dispatch( false, delete_request( '7' ) );
endpoint_check( $nonce_failure instanceof WP_Error && 'rest_cookie_invalid_nonce' === $nonce_failure->code && $before === $calls, 'invalid REST nonce is rejected before deletion callback' );

foreach ( array( null, 0, -1, '0', '-1', '', '1.0', ' 1', '01', array( 1 ), (object) array( 'id' => 1 ), str_repeat( '9', 100 ) ) as $invalid ) {
	$request = new WP_REST_Request( null === $invalid ? array() : array( 'order_id' => $invalid ) );
	$before = $calls; $response = $controller->delete( $request );
	endpoint_check( 400 === $response->get_status() && 'invalid_order_id' === $response->get_data()['code'] && $before === $calls, 'malformed order id is rejected without service invocation: ' . get_debug_type( $invalid ) );
}

$GLOBALS['reset_user'] = 0; $before = $calls;
endpoint_check( $controller->authorize()->data['status'] === 401 && $controller->delete( delete_request() )->get_status() === 401 && $calls === $before, 'anonymous direct callback cannot invoke service' );
$GLOBALS['reset_user'] = 7; $GLOBALS['reset_capable'] = false;
endpoint_check( $controller->delete( delete_request() )->get_status() === 403 && $calls === $before, 'subscriber direct callback cannot invoke service' );
$GLOBALS['reset_capable'] = true;
$bad = array();
foreach ( array( null, false, 7, '', 'DELETE ORDER C2C-8', 'delete order C2C-7', ' DELETE ORDER C2C-7', 'DELETE ORDER C2C-7 ' ) as $confirmation ) { $bad[] = delete_request( '7', array_replace( delete_body(), array( 'confirmation' => $confirmation ) ) ); }
foreach ( array( array(), array( 'confirmation' => 'DELETE ORDER C2C-7' ), array( 'expected_context' => delete_body()['expected_context'] ), delete_body() + array( 'extra' => 'x' ) ) as $body ) { $bad[] = delete_request( '7', $body ); }
foreach ( array( null, false, 'x', array(), array( 'epoch_id' => '2' ), delete_body()['expected_context'] + array( 'extra' => 'x' ) ) as $context ) { $bad[] = delete_request( '7', array_replace( delete_body(), array( 'expected_context' => $context ) ) ); }
foreach ( array( 'installation_id', 'epoch_identity', 'epoch_id' ) as $field ) {
 foreach ( array( null, false, 2, '', '02', '-1', str_repeat( '9', 100 ), array() ) as $value ) { $body = delete_body(); $body['expected_context'][$field] = $value; $bad[] = delete_request( '7', $body ); }
}
$bad[] = delete_request( '7', null, array( 'confirmation' => 'DELETE ORDER C2C-7' ) );
$bad[] = delete_request( '7', null, array( 'rest_route' => '/cpt2collection/v1/orders/8' ) );
$bad[] = delete_request( '7', null, array( 'rest_route' => array() ) );
$bad[] = delete_request( '7', null, array( '_wpnonce' => array() ) );
$bad[] = delete_request( '7', null, array(), '{' );
$bad[] = delete_request( '7', null, array(), str_repeat( 'x', 2049 ) );
$bad[] = delete_request( '7', null, array(), null, 'text/plain' );
$bad[] = delete_request( '7', null, array(), null, 'application/json', array( 'idempotency-key' => 'x' ) );
foreach ( $bad as $request ) { $before = $calls; endpoint_check( $controller->delete( $request )->get_status() === 400 && $calls === $before, 'invalid request cannot invoke destructive delegate' ); }
foreach ( array( '/cpt2collection/v1/orders/7', '/cpt2collection/v1/orders/7/' ) as $route ) { endpoint_check( $controller->delete( delete_request( '7', null, array( 'rest_route' => $route ) ) )->get_status() === 200, 'exact query-form routing accepted' ); }
$cases = array(
	OrderDeletionResult::DELETED => array( true, 200, false ),
	OrderDeletionResult::ALREADY_DELETED => array( true, 200, false ),
	OrderDeletionResult::ALREADY_ABSENT => array( false, 409, false ),
	OrderDeletionResult::ABSENCE_UNPROVABLE => array( false, 409, false ),
	OrderDeletionResult::EVIDENCE_CONFLICT => array( false, 409, false ),
	OrderDeletionResult::INCONSISTENT_STATE => array( false, 409, false ),
	OrderDeletionResult::DATABASE_ERROR => array( false, 500, false ),
	OrderDeletionResult::TRANSACTION_OUTCOME_UNKNOWN => array( false, 503, true ),
);
foreach ( $cases as $code => [ $success, $status, $verify_state ] ) {
	$next = $success ? OrderDeletionResult::success( $code, 7 ) : OrderDeletionResult::failure( $code, 7 );
	$response = $controller->delete( delete_request( '7' ) ); $data = $response->get_data();
	endpoint_check( $status === $response->get_status() && $code === $data['code'] && $success === $data['success'] && '7' === $data['order_id'] && $verify_state === $data['verify_state'], "{$code} has stable public mapping" );
	endpoint_check( ! str_contains( serialize( $data ), 'SELECT ' ) && ! str_contains( serialize( $data ), 'pi_') && ! str_contains( serialize( $data ), 'provider'), "{$code} response exposes no SQL/provider detail" );
}

$sequence = array( OrderDeletionResult::DELETED, OrderDeletionResult::ALREADY_DELETED ); $index = 0;
$replay = new OrderDeletionRestController( static function ( int $order_id ) use ( &$sequence, &$index ): OrderDeletionResult { return OrderDeletionResult::success( $sequence[ $index++ ], $order_id ); } );
$first = $replay->delete( delete_request( '9' ) ); $second = $replay->delete( delete_request( '9' ) );
endpoint_check( 200 === $first->get_status() && 'deleted' === $first->get_data()['code'] && 200 === $second->get_status() && 'already_deleted' === $second->get_data()['code'], 'repeated request maps idempotently' );

$bootstrap = file_get_contents( dirname( __DIR__ ) . '/cpt2collection.php' ); $plugin = file_get_contents( dirname( __DIR__ ) . '/includes/class-plugin.php' );
endpoint_check( str_contains( $bootstrap, 'class-order-deletion-rest-controller.php' ) && str_contains( $plugin, "add_action( 'rest_api_init'") && str_contains( $plugin, 'new OrderDeletionService(' ), 'source loading and rest_api_init service wiring are explicit' );
endpoint_check( ! str_contains( file_get_contents( dirname( __DIR__ ) . '/includes/class-order-deletion-rest-controller.php' ), 'Stripe' ), 'endpoint constructs no provider client' );

$large = new OrderDeletionRestController( static fn( int $id ) => OrderDeletionResult::success( 'deleted', $id ) );
endpoint_check( $large->delete( delete_request( (string) PHP_INT_MAX ) )->get_data()['order_id'] === (string) PHP_INT_MAX, 'response identity remains exact beyond JavaScript integer precision' );
$unexpected = new OrderDeletionRestController( static function (): never { throw new RuntimeException( 'PRIVATE SQL' ); } );
endpoint_check( $unexpected->delete( delete_request() )->get_data()['verify_state'] === true, 'unexpected exception stays unverified' );
$wrong = new OrderDeletionRestController( static fn() => OrderDeletionResult::success( 'deleted', 8 ) );
endpoint_check( $wrong->delete( delete_request() )->get_data()['verify_state'] === true, 'mismatched result identity stays unverified' );
echo "All order-deletion endpoint contract checks passed.\n";
