<?php
/** Authorized, bounded Full Commerce Reset REST boundary. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;
defined( 'ABSPATH' ) || exit;

final class CommerceResetRestController {
	public const REST_NAMESPACE = 'cpt2collection/v1';
	public const ROOT = '/commerce/resets';
	public const CONFIRMATION = 'FULL COMMERCE RESET';
	/** @param callable(string,array):CommerceResetResult $execute Fresh private connection per invocation. */
	public function __construct( private $execute ) {}
	public function register(): void {
		foreach ( array(
			array( self::ROOT, 'POST', 'start' ),
			array( self::ROOT . '/(?P<operation_identity>[^/]+)', 'GET', 'status' ),
			array( self::ROOT . '/(?P<operation_identity>[^/]+)/resume', 'POST', 'resume' ),
		) as [ $route, $method, $callback ] ) {
			register_rest_route( self::REST_NAMESPACE, $route, array( 'methods'=>$method, 'callback'=>array( $this, $callback ), 'permission_callback'=>array( $this, 'authorize' ) ) );
		}
	}
	/** Core REST authentication validates cookie nonces BEFORE route authorization. */
	public function authorize() {
		if ( ! is_user_logged_in() ) { return new \WP_Error( 'cpt2collection_reset_authentication_required', __( 'Authentication is required.', 'cpt2collection' ), array( 'status'=>401 ) ); }
		return current_user_can( 'manage_options' ) ? true : new \WP_Error( 'cpt2collection_reset_forbidden', __( 'You are not allowed to reset commerce history.', 'cpt2collection' ), array( 'status'=>403 ) );
	}
	public function start( \WP_REST_Request $request ): \WP_REST_Response { return $this->handle( 'start', $request ); }
	public function status( \WP_REST_Request $request ): \WP_REST_Response { return $this->handle( 'status', $request ); }
	public function resume( \WP_REST_Request $request ): \WP_REST_Response { return $this->handle( 'resume', $request ); }
	private function handle( string $action, \WP_REST_Request $request ): \WP_REST_Response {
		// Also fail closed for direct callback invocation outside the REST dispatcher.
		$permission = $this->authorize();
		if ( true !== $permission ) { return $this->response( new CommerceResetResult( is_user_logged_in() ? 'forbidden' : 'authentication_required' ) ); }
		$query = $request->get_query_params();
		if ( strlen( $request->get_body() ) > 2048 || ! $this->routing_query_valid( $action, $request, $query ) || array_key_exists( '_wpnonce', $query ) && ( ! is_string( $query['_wpnonce'] ) || strlen( $query['_wpnonce'] ) > 128 ) || null !== $request->get_header( 'idempotency-key' ) ) { return $this->response( new CommerceResetResult( 'invalid_request' ) ); }
		$params = array();
		if ( 'status' !== $action ) {
			if ( ! $request->is_json_content_type() ) { return $this->response( new CommerceResetResult( 'invalid_request' ) ); }
			$params = $request->get_json_params();
			$keys = 'start' === $action ? array( 'operation_identity', 'expected_epoch_identity', 'confirmation' ) : array( 'expected_epoch_identity', 'revision', 'confirmation' );
			if ( ! is_array( $params ) || count( $params ) !== count( $keys ) || array_diff( $keys, array_keys( $params ) ) ) { return $this->response( new CommerceResetResult( 'invalid_request' ) ); }
		} elseif ( '' !== $request->get_body() ) { return $this->response( new CommerceResetResult( 'invalid_request' ) ); }
		$identity = 'start' === $action ? ( $params['operation_identity'] ?? null ) : ( $request->get_url_params()['operation_identity'] ?? null );
		if ( ! is_string( $identity ) || ! CommerceResetLifecycle::identity_valid( $identity ) ) { return $this->response( new CommerceResetResult( 'invalid_request' ) ); }
		if ( 'status' !== $action ) {
			$epoch = $params['expected_epoch_identity'];
			$confirmation = self::CONFIRMATION . ( 'resume' === $action ? ' ' . $identity : '' );
			if ( ! is_string( $epoch ) || ! CommerceResetLifecycle::identity_valid( $epoch ) ) { return $this->response( new CommerceResetResult( 'invalid_request' ) ); }
			if ( $params['confirmation'] !== $confirmation ) { return $this->response( new CommerceResetResult( 'confirmation_required' ) ); }
			if ( 'resume' === $action && ! self::revision_valid( $params['revision'] ) ) { return $this->response( new CommerceResetResult( 'invalid_request' ) ); }
		}
		$params['operation_identity'] = $identity;
		if ( 'start' === $action ) { $params['requested_by'] = get_current_user_id(); }
		try { $result = ( $this->execute )( $action, $params ); }
		catch ( \Throwable $error ) { $result = new CommerceResetResult( 'verification_required' ); }
		return $this->response( $result );
	}
	/** Core routing is transport metadata, never a second source of operation identity. */
	private function routing_query_valid( string $action, \WP_REST_Request $request, array $query ): bool {
		if ( array_diff( array_keys( $query ), array( '_wpnonce', 'rest_route' ) ) ) { return false; }
		if ( ! array_key_exists( 'rest_route', $query ) ) { return true; }
		$route = '/' . self::REST_NAMESPACE . self::ROOT;
		if ( 'start' !== $action ) {
			$id = $request->get_url_params()['operation_identity'] ?? null;
			if ( ! is_string( $id ) || ! CommerceResetLifecycle::identity_valid( $id ) ) { return false; }
			$route .= '/' . $id . ( 'resume' === $action ? '/resume' : '' );
		}
		return is_string( $query['rest_route'] ) && in_array( $query['rest_route'], array( $route, $route . '/' ), true );
	}

	private static function revision_valid( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) && ( strlen( $value ) < strlen( (string) PHP_INT_MAX ) || strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) <= 0 );
	}
	private function response( CommerceResetResult $result ): \WP_REST_Response {
		$mapping = array(
			'started'=>202, 'existing'=>200, 'running'=>202, 'progressed'=>202, 'completed'=>200,
			'blocked'=>409, 'not_found'=>404, 'conflict'=>409, 'stale'=>409, 'inconsistent_state'=>409,
			'invalid_request'=>400, 'confirmation_required'=>400, 'authentication_required'=>401, 'forbidden'=>403,
			'database_error'=>500, 'epoch_id_exhausted'=>409, 'temporarily_unavailable'=>503,
			'transaction_outcome_unknown'=>503, 'verification_required'=>503,
		);
		$code = isset( $mapping[$result->code()] ) ? $result->code() : 'verification_required';
		$r = $result->operation();
		$verify = in_array( $code, array( 'transaction_outcome_unknown', 'verification_required' ), true );
		$success = in_array( $code, array( 'started', 'existing', 'running', 'progressed', 'completed' ), true );
		if ( ! $verify && null !== $r && 'blocked' === $r->status() && ( $success || 'blocked' === $code ) ) { $code = 'blocked'; $success = false; }
		// Never publish contradictory acceptance, completion or block snapshots.
		if ( ( $success && ( null === $r || ! in_array( $r->status(), array( 'running', 'completed' ), true ) ) ) ||
			( 'completed' === $code && 'completed' !== $r?->status() ) ||
			( in_array( $code, array( 'started', 'running', 'progressed' ), true ) && 'running' !== $r?->status() ) ||
			( 'blocked' === $code && 'blocked' !== $r?->status() ) ) { $code = 'verification_required'; $verify = true; $success = false; }
		$operation = null;
		if ( null !== $r && ! $verify && ( $success || 'blocked' === $code ) ) {
			$operation = array(
				'operation_identity'=>$r->operation_identity(), 'source_epoch_identity'=>$r->source_epoch_identity(),
				'status'=>$r->status(), 'phase'=>$r->phase(), 'completed'=>'completed' === $r->status(),
				'blocked'=>'blocked' === $r->status(), 'revision'=>(string) $r->revision(),
				'checkpoint'=>$r->checkpoint(), 'cursor'=>(string) $r->cursor(),
				'can_resume'=>'running' === $r->status() && in_array( $r->phase(), array( 'validating', 'retiring_claims', 'terminalizing_events', 'deleting' ), true ),
			);
		}
		$message = $verify ? __( 'Outcome requires fresh durable status inspection before any further mutation.', 'cpt2collection' ) : ( 'blocked' === $code ? __( 'Reset is blocked. Administrative investigation is required; automatic continuation is unavailable.', 'cpt2collection' ) : ( $success ? ( $operation['completed'] ? __( 'Full Commerce Reset is complete.', 'cpt2collection' ) : __( 'Reset remains incomplete. Query status before requesting one bounded continuation.', 'cpt2collection' ) ) : __( 'Request was not completed. Query the exact operation status before deciding further action.', 'cpt2collection' ) ) );
		$response = new \WP_REST_Response( array( 'success'=>$success, 'code'=>$code, 'message'=>$message, 'verify_state'=>$verify, 'attention_code'=>$success ? null : $code, 'operation'=>$operation, 'next_action'=>$verify || ! $success && 'blocked' !== $code ? 'query_status' : ( 'blocked' === $code ? 'investigate' : ( $operation['completed'] ? 'none' : 'query_status' ) ) ), $mapping[$code] );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
