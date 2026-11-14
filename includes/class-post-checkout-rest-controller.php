<?php
/** Guest-cookie-authorized read endpoint, independent of payment orchestration. @package CPT2Collection */
declare( strict_types=1 );
namespace RodolfoRizzo\Cpt2Collection;

use Throwable;

defined( 'ABSPATH' ) || exit;

final class PostCheckoutRestController {
	public const REST_NAMESPACE = 'cpt2collection/v1';
	public const ROUTE = '/post-checkout';
	private const CORS_HEADERS = array( 'Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials', 'Access-Control-Allow-Methods', 'Access-Control-Allow-Headers', 'Access-Control-Expose-Headers' );

	public function __construct( private PostCheckoutReadRepository $reads, private CartCookie $cookie, private CartToken $tokens, private UtcClock $clock ) {}

	public function register(): void {
		// serve_request() can reject malformed JSONP before dispatch/serve filters.
		// Protect that early error path too, only for this top-level REST route.
		$route = $GLOBALS['wp']->query_vars['rest_route'] ?? null;
		if ( is_string( $route ) && $this->matches_route( $route ) ) { $this->send_privacy_headers(); }
		register_rest_route( self::REST_NAMESPACE, self::ROUTE, array(
			'methods' => 'GET',
			'callback' => array( $this, 'read' ),
			// Ownership is checked in the joined read, never by WP login or nonce.
			'permission_callback' => array( $this, 'authorize_origin' ),
		) );
		add_filter( 'rest_post_dispatch', array( $this, 'protect_response' ), PHP_INT_MAX, 3 );
		// Core reflects Origin here at priority 10, after response headers were sent.
		add_filter( 'rest_pre_serve_request', array( $this, 'protect_transport' ), PHP_INT_MAX, 4 );
	}

	/** @return true|\WP_Error */
	public function authorize_origin( \WP_REST_Request $request ) {
		return $this->origin_allowed( $request ) ? true : new \WP_Error( 'post_checkout_unavailable', __( 'This request is unavailable.', 'cpt2collection' ), array( 'status' => 403 ) );
	}

	private function origin_allowed( \WP_REST_Request $request ): bool {
		$origin = $request->get_header( 'origin' );
		// WordPress returns null when the header is absent; do not coerce other types.
		if ( null === $origin || '' === $origin ) { return true; }
		if ( ! is_string( $origin ) ) { return false; }
		$source = parse_url( $origin );
		$site = parse_url( home_url( '/' ) );
		if ( ! is_array( $source ) || ! is_array( $site ) || isset( $source['user'] ) || isset( $source['pass'] ) || isset( $source['query'] ) || isset( $source['fragment'] ) || ! empty( $source['path'] ) ) { return false; }
		$scheme = strtolower( $source['scheme'] ?? '' );
		return in_array( $scheme, array( 'http', 'https' ), true )
			&& $scheme === strtolower( $site['scheme'] ?? '' )
			&& strtolower( $source['host'] ?? '' ) === strtolower( $site['host'] ?? '' )
			&& ( $source['port'] ?? ( 'https' === $scheme ? 443 : 80 ) ) === ( $site['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
	}

	public function read( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->origin_allowed( $request ) ) { return $this->response( array( 'code' => 'post_checkout_unavailable' ), 403 ); }
		// JSONP bypasses CORS through script inclusion; never return order data to it.
		if ( null !== $request->get_param( '_jsonp' ) ) { return $this->response( array( 'code' => 'invalid_post_checkout_request' ), 400 ); }
		$id = $request->get_param( 'session_id' );
		// The database stores up to 255 chars. Accept cs_ opaque IDs (including
		// historical/test IDs), without coupling access to the current environment.
		if ( ! is_string( $id ) || strlen( $id ) > 255 || 1 !== preg_match( '/\Acs_[A-Za-z0-9_]+\z/', $id ) ) {
			return $this->response( array( 'code' => 'invalid_post_checkout_request' ), 400 );
		}
		try {
			$token = $this->cookie->read();
			if ( null === $token ) { return $this->unavailable(); }
			$rows = $this->reads->find_owned( $id, $this->tokens->hash( $token ), $this->clock->now_mysql() );
			if ( false === $rows ) { return $this->temporary_failure(); }
			if ( array() === $rows ) { return $this->unavailable(); }
			return $this->response( PostCheckoutView::from_rows( $rows )->to_array(), 200 );
		} catch ( Throwable $error ) {
			return $this->temporary_failure();
		}
	}

	/** Also applies to permission, routing and other REST errors for this path. */
	public function protect_response( $response, $server, \WP_REST_Request $request ) {
		if ( $this->is_route( $request ) ) {
			$headers = $response->get_headers();
			$remove = array_map( 'strtolower', array_merge( self::CORS_HEADERS, array_keys( $this->privacy_headers() ) ) );
			foreach ( array_keys( $headers ) as $name ) {
				if ( in_array( strtolower( $name ), $remove, true ) ) { unset( $headers[ $name ] ); }
			}
			$response->set_headers( $headers );
			foreach ( $this->privacy_headers() as $name => $value ) { $response->header( $name, $value ); }
		}
		return $response;
	}

	public function protect_transport( $served, $response, \WP_REST_Request $request, $server ) {
		if ( $this->is_route( $request ) ) { $this->send_privacy_headers(); }
		return $served;
	}

	private function send_privacy_headers(): void {
		foreach ( self::CORS_HEADERS as $name ) { header_remove( $name ); }
		foreach ( $this->privacy_headers() as $name => $value ) { header( $name . ': ' . $value ); }
	}
	private function is_route( \WP_REST_Request $request ): bool { return $this->matches_route( $request->get_route() ); }
	private function matches_route( string $route ): bool {
		// WordPress matches registered REST routes case-insensitively.
		return 0 === strcasecmp( '/' . self::REST_NAMESPACE . self::ROUTE, rtrim( $route, '/' ) );
	}
	private function privacy_headers(): array {
		return array( 'Cache-Control' => 'private, no-store, max-age=0', 'Vary' => 'Origin, Cookie', 'Pragma' => 'no-cache', 'Expires' => '0', 'X-Content-Type-Options' => 'nosniff', 'Cross-Origin-Resource-Policy' => 'same-origin' );
	}
	private function response( array $data, int $status ): \WP_REST_Response { return new \WP_REST_Response( $data, $status, $this->privacy_headers() ); }
	private function unavailable(): \WP_REST_Response { return $this->response( array( 'code' => 'post_checkout_unavailable' ), 404 ); }
	private function temporary_failure(): \WP_REST_Response { return $this->response( array( 'code' => 'post_checkout_temporarily_unavailable' ), 503 ); }
}
