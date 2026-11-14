<?php
/** Administrator-only permanent order deletion endpoint. @package CPT2Collection */
declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

final class OrderDeletionRestController {
	public const REST_NAMESPACE = 'cpt2collection/v1';
	public const ROUTE = '/orders/(?P<order_id>[^/]+)';

	/** @var callable(int,CommerceContext):OrderDeletionResult */
	private $delete_order;

	/** @param callable(int,CommerceContext):OrderDeletionResult $delete_order */
	public function __construct( callable $delete_order ) { $this->delete_order = $delete_order; }

	public function register(): void {
		register_rest_route( self::REST_NAMESPACE, self::ROUTE, array(
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => array( $this, 'delete' ),
			'permission_callback' => array( $this, 'authorize' ),
		) );
	}

	/** WordPress REST authentication and cookie nonce validation run before this callback. @return true|\WP_Error */
	public function authorize() {
		if ( ! is_user_logged_in() ) { return new \WP_Error( 'cpt2collection_order_delete_authentication_required', __( 'Authentication is required.', 'cpt2collection' ), array( 'status' => 401 ) ); }
		return current_user_can( 'manage_options' )
			? true
			: new \WP_Error( 'cpt2collection_order_delete_forbidden', __( 'You are not allowed to permanently delete orders.', 'cpt2collection' ), array( 'status' => 403 ) );
	}

	public function delete( \WP_REST_Request $request ): \WP_REST_Response {
		if ( true !== $this->authorize() ) { return $this->response( is_user_logged_in() ? 'forbidden' : 'authentication_required', __( 'You are not allowed to permanently delete orders.', 'cpt2collection' ), is_user_logged_in() ? 403 : 401, null, false, false ); }
		$value = $request->get_url_params()['order_id'] ?? null;
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[1-9]\d*\z/', $value ) || strlen( $value ) > strlen( (string) PHP_INT_MAX ) || ( strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) > 0 ) ) {
			return $this->response( 'invalid_order_id', __( 'order_id must be one positive integer.', 'cpt2collection' ), 400, null, false, false );
		}

		$order_id = (int) $value;
		$query = $request->get_query_params();
		$route = '/' . self::REST_NAMESPACE . '/orders/' . $value;
		if ( strlen( $request->get_body() ) > 2048 || ! $request->is_json_content_type() ||
			array_diff( array_keys( $query ), array( '_wpnonce', 'rest_route' ) ) ||
			array_key_exists( 'rest_route', $query ) && ( ! is_string( $query['rest_route'] ) || ! in_array( $query['rest_route'], array( $route, $route . '/' ), true ) ) ||
			array_key_exists( '_wpnonce', $query ) && ( ! is_string( $query['_wpnonce'] ) || strlen( $query['_wpnonce'] ) > 128 ) ||
			! in_array( $request->get_header( 'idempotency-key' ), array( null, '' ), true ) || $request->get_body_params() || $request->get_file_params() ) {
			return $this->invalid_request( $order_id );
		}
		$input = $request->get_json_params();
		$keys = array( 'confirmation', 'expected_context' );
		if ( ! is_array( $input ) || count( $input ) !== 2 || array_diff( $keys, array_keys( $input ) ) ) { return $this->invalid_request( $order_id ); }
		if ( $input['confirmation'] !== 'DELETE ORDER C2C-' . $value ) {
			return $this->response( 'confirmation_required', __( 'Type the exact confirmation for this Order.', 'cpt2collection' ), 400, $order_id, false, false );
		}
		$c = $input['expected_context'];
		if ( ! is_array( $c ) || count( $c ) !== 3 || array_diff( array( 'installation_id', 'epoch_id', 'epoch_identity' ), array_keys( $c ) ) ||
			! is_string( $c['installation_id'] ) || 1 !== preg_match( '/\A[0-9a-f]{32}\z/', $c['installation_id'] ) ||
			! is_string( $c['epoch_identity'] ) || 1 !== preg_match( '/\A[0-9a-f]{32}\z/', $c['epoch_identity'] ) ||
			! is_string( $c['epoch_id'] ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $c['epoch_id'] ) ||
			false === filter_var( $c['epoch_id'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) ) { return $this->invalid_request( $order_id ); }
		$expected = new CommerceContext( $c['installation_id'], (int) $c['epoch_id'], $c['epoch_identity'] );
		try {
			$result = ( $this->delete_order )( $order_id, $expected );
			if ( $result->order_id() !== $order_id ) { throw new \UnexpectedValueException( 'Deletion identity mismatch' ); }
			return $this->map_result( $result );
		} catch ( \Throwable $error ) {
			return $this->response( 'verification_required', __( 'The deletion outcome is unverified. Use read-only inspection; do not repeat the deletion.', 'cpt2collection' ), 503, $order_id, false, true );
		}
	}

	private function invalid_request( int $id ): \WP_REST_Response {
		return $this->response( 'invalid_request', __( 'A confirmed deletion and exact expected commerce context are required.', 'cpt2collection' ), 400, $id, false, false );
	}

	private function map_result( OrderDeletionResult $result ): \WP_REST_Response {
		$mapping = array(
			'stale_context' => array( 409, __( 'The displayed commerce context is no longer current. Inspect the Order again.', 'cpt2collection' ), false, false ),
			OrderDeletionResult::DELETED => array( 200, __( 'The order was permanently deleted.', 'cpt2collection' ), true, false ),
			OrderDeletionResult::ALREADY_DELETED => array( 200, __( 'The order was already permanently deleted.', 'cpt2collection' ), true, false ),
			OrderDeletionResult::ALREADY_ABSENT => array( 409, __( 'The Order is absent, but permanent deletion is unverified.', 'cpt2collection' ), false, false ),
			OrderDeletionResult::ABSENCE_UNPROVABLE => array(409, __('The order is absent, but its historical identity cannot be proved.', 'cpt2collection'), false, false),
			OrderDeletionResult::EVIDENCE_CONFLICT => array( 409, __( 'The order cannot be deleted because its durable identity evidence conflicts.', 'cpt2collection' ), false, false ),
			OrderDeletionResult::INCONSISTENT_STATE => array( 409, __( 'The order cannot be deleted because its stored state is inconsistent.', 'cpt2collection' ), false, false ),
			OrderDeletionResult::DATABASE_ERROR => array( 500, __( 'The order deletion could not be completed.', 'cpt2collection' ), false, false ),
			OrderDeletionResult::TRANSACTION_OUTCOME_UNKNOWN => array( 503, __( 'The deletion outcome is unknown. Verify the order state before taking further action.', 'cpt2collection' ), false, true ),
		);
		[ $status, $message, $success, $verify_state ] = $mapping[ $result->code() ] ?? array( 503, __( 'The deletion outcome is unverified. Use read-only inspection.', 'cpt2collection' ), false, true );
		$code = isset( $mapping[ $result->code() ] ) ? $result->code() : 'verification_required';
		return $this->response( $code, $message, $status, $result->order_id(), $success, $verify_state );
	}

	private function response( string $code, string $message, int $status, ?int $order_id, bool $success, bool $verify_state ): \WP_REST_Response {
		$response = new \WP_REST_Response( array(
			'success'      => $success,
			'code'         => $code,
			'message'      => $message,
			'order_id'     => null === $order_id ? null : (string) $order_id,
			'verify_state' => $verify_state,
		), $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
