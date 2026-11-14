<?php
/**
 * Guest cart REST routes.
 *
 * @package CPT2Collection
 */

declare( strict_types=1 );

namespace RodolfoRizzo\Cpt2Collection;

defined( 'ABSPATH' ) || exit;

final class CartRestController {
	public const REST_NAMESPACE = 'cpt2collection/v1';

	private CartContext $context;
	private CartRepository $repository;
	private CartActivity $activity;
	private CommerceRules $rules;
	private CartResponseBuilder $responses;
	private GuestCsrf $csrf;
	private TransactionManager $transactions;

	public function __construct( CartContext $context, CartRepository $repository, CartActivity $activity, CommerceRules $rules, CartResponseBuilder $responses, GuestCsrf $csrf, TransactionManager $transactions ) {
		$this->context    = $context;
		$this->repository = $repository;
		$this->activity   = $activity;
		$this->rules      = $rules;
		$this->responses  = $responses;
		$this->csrf       = $csrf;
		$this->transactions = $transactions;
	}

	public function register(): void {
		register_rest_route( self::REST_NAMESPACE, '/cart', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'get_cart' ), 'permission_callback' => array( $this, 'allow_public_read' ) ),
			array( 'methods' => \WP_REST_Server::DELETABLE, 'callback' => array( $this, 'clear_cart' ), 'permission_callback' => array( $this, 'authorize_mutation' ) ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/cart/items', array(
			'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'add_item' ), 'permission_callback' => array( $this, 'authorize_mutation' ),
		) );
		register_rest_route( self::REST_NAMESPACE, '/cart/items/(?P<post_id>\d+)', array(
			array( 'methods' => 'PATCH', 'callback' => array( $this, 'set_quantity' ), 'permission_callback' => array( $this, 'authorize_mutation' ) ),
			array( 'methods' => \WP_REST_Server::DELETABLE, 'callback' => array( $this, 'remove_item' ), 'permission_callback' => array( $this, 'authorize_mutation' ) ),
		) );
	}

	public function allow_public_read(): bool { return true; }

	/** @return true|\WP_Error */
	public function authorize_mutation( \WP_REST_Request $request ) {
		return $this->csrf->verify_request( $request ) ? true : new \WP_Error( 'cpt2collection_csrf_failed', __( 'The cart request failed same-origin CSRF validation.', 'cpt2collection' ), array( 'status' => 403 ) );
	}

	public function get_cart(): \WP_REST_Response {
		$response = new \WP_REST_Response( $this->responses->build( $this->context->get_existing_cart() ), 200 );
		$response->header( GuestCsrf::HEADER_NAME, $this->csrf->create_token() );
		return $response;
	}

	public function add_item( \WP_REST_Request $request ): \WP_REST_Response {
		$input = $this->json_integers( $request, array( 'post_id', 'quantity' ) );
		if ( $input instanceof \WP_Error ) { return $this->error_response( $input ); }
		$cart = $this->context->get_or_create_cart();
		if ( ! $cart instanceof CartRecord ) { return $this->server_error(); }
		$service = $this->service( $cart ); $result = $service->add( $input['post_id'], $input['quantity'] );
		return $this->mutation_response( $result, $service->cart(), $result->is_success() ? 201 : 422 );
	}

	public function set_quantity( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = $this->route_post_id( $request );
		$input   = $this->json_integers( $request, array( 'quantity' ) );
		if ( $post_id instanceof \WP_Error ) { return $this->error_response( $post_id ); }
		if ( $input instanceof \WP_Error ) { return $this->error_response( $input ); }
		$cart = $this->context->get_existing_cart();
		if ( null === $cart ) { return $this->missing_cart_response( 'set_quantity', $post_id, $input['quantity'] ); }
		$service = $this->service( $cart ); $result = $service->set_quantity( $post_id, $input['quantity'] );
		return $this->mutation_response( $result, $service->cart(), $result->is_success() ? 200 : $this->failure_status( $result ) );
	}

	public function remove_item( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = $this->route_post_id( $request );
		if ( $post_id instanceof \WP_Error ) { return $this->error_response( $post_id ); }
		$cart = $this->context->get_existing_cart();
		if ( null === $cart ) { return $this->missing_cart_response( 'remove', $post_id, null ); }
		$service = $this->service( $cart ); $result = $service->remove( $post_id );
		return $this->mutation_response( $result, $service->cart(), $result->is_success() ? 200 : $this->failure_status( $result ) );
	}

	public function clear_cart(): \WP_REST_Response {
		$cart = $this->context->get_existing_cart();
		if ( null === $cart ) {
			$result = CartOperationResult::failure( 'clear', 'cart_not_found', null, null, __( 'No active cart was found.', 'cpt2collection' ) );
			return new \WP_REST_Response( array( 'operation' => $this->operation_data( $result ), 'cart' => $this->responses->build( null ) ), 404 );
		}
		$service = $this->service( $cart ); $result = $service->clear();
		return $this->mutation_response( $result, $service->cart(), $result->is_success() ? 200 : 500 );
	}

	private function service( CartRecord $cart ): CartService { return new CartService( $cart, $this->repository, $this->activity, $this->rules, $this->transactions ); }

	/** @param string[] $required @return array<string,int>|\WP_Error */
	private function json_integers( \WP_REST_Request $request, array $required ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) { return new \WP_Error( 'invalid_json', __( 'A JSON object is required.', 'cpt2collection' ), array( 'status' => 400 ) ); }
		$output = array();
		foreach ( $required as $name ) {
			if ( ! isset( $params[ $name ] ) || ! is_int( $params[ $name ] ) || $params[ $name ] < 1 || ( 'post_id' === $name && $params[ $name ] <= 1 ) ) {
				/* translators: %s: untranslated REST field name, such as post_id or quantity. */
				return new \WP_Error( 'invalid_' . $name, sprintf( __( '%s must be a positive JSON integer.', 'cpt2collection' ), $name ), array( 'status' => 400 ) );
			}
			$output[ $name ] = $params[ $name ];
		}
		return $output;
	}

	/** @return int|\WP_Error */
	private function route_post_id( \WP_REST_Request $request ) {
		$value = $request->get_url_params()['post_id'] ?? null;
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9]\d*$/', $value ) || (int) $value <= 1 ) {
			/* translators: post_id is a REST field name. Keep it unchanged. */
			return new \WP_Error( 'invalid_post_id', __( 'post_id must be an integer greater than 1.', 'cpt2collection' ), array( 'status' => 400 ) );
		}
		return (int) $value;
	}

	private function mutation_response( CartOperationResult $result, CartRecord $cart, int $status ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'operation' => $this->operation_data( $result ), 'cart' => $this->responses->build( $cart ) ), $status );
	}

	private function missing_cart_response( string $operation, ?int $post_id, ?int $quantity ): \WP_REST_Response {
		$result = CartOperationResult::failure( $operation, 'item_not_found', $post_id, $quantity, __( 'No active cart or matching item was found.', 'cpt2collection' ) );
		return new \WP_REST_Response( array( 'operation' => $this->operation_data( $result ), 'cart' => $this->responses->build( null ) ), 404 );
	}

	/** @return array<string,mixed> */
	private function operation_data( CartOperationResult $result ): array {
		return array( 'success' => $result->is_success(), 'operation' => $result->get_operation(), 'code' => $result->get_code(), 'post_id' => $result->get_post_id(), 'quantity' => $result->get_quantity(), 'message' => $result->get_message() );
	}

	private function failure_status( CartOperationResult $result ): int { return 'item_not_found' === $result->get_code() ? 404 : 422; }
	private function error_response( \WP_Error $error ): \WP_REST_Response { return new \WP_REST_Response( array( 'code' => $error->get_error_code(), 'message' => $error->get_error_message() ), (int) ( $error->get_error_data()['status'] ?? 400 ) ); }
	private function server_error(): \WP_REST_Response { return new \WP_REST_Response( array( 'code' => 'cart_unavailable', 'message' => __( 'Unable to initialize the cart.', 'cpt2collection' ) ), 500 ); }
}
