<?php
/**
 * WordPress REST proxy to the SocialScore API.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * The browser talks to /wp-json/socialscore/v1/proxy/*; the server adds the store key.
 * The key never reaches the browser, and only allow-listed API paths pass.
 */
class Rest_Proxy {

	const NAMESPACE_ = 'socialscore/v1';

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Route with a path wildcard; WordPress checks the REST nonce for cookie logins.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_,
			'/proxy/(?P<path>api/v2/.+)',
			array(
				'methods'             => array( 'GET', 'POST', 'PATCH' ),
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'path' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Only store managers may use the proxy.
	 *
	 * @return bool
	 */
	public function permission() {
		return current_user_can( Config::CAPABILITY );
	}

	/**
	 * API paths the dashboard and panels may call, per method.
	 *
	 * @param string $store_id Connected store id.
	 * @return array<string,string[]> method => regex list.
	 */
	public static function allow_list( $store_id ) {
		$s  = preg_quote( '/api/v2/stores/' . rawurlencode( $store_id ), '#' );
		$id = '(?!\\.+/)[A-Za-z0-9._:~-]+'; // Not a bare run of dots: "." is a path segment, not an id.
		return array(
			'GET'   => array(
				'#^' . $s . '/settings$#D',
				'#^' . $s . '/settings/estimate$#D',
				'#^' . $s . '/overview$#D',
				'#^' . $s . '/customers$#D',
				'#^' . $s . '/customers/' . $id . '/profile$#D',
				'#^' . $s . '/orders/' . $id . '/profile$#D',
				'#^' . $s . '/pixel$#D',
				'#^' . $s . '/customers/' . $id . '/session$#D',
				'#^' . $s . '/orders/' . $id . '/session$#D',
				'#^/api/v2/segments$#D',
			),
			'PATCH' => array(
				'#^' . $s . '/settings$#D',
				'#^' . $s . '/pixel$#D',
			),
			'POST'  => array(
				'#^' . $s . '/checks$#D',
			),
		);
	}

	/**
	 * Whether a request may be forwarded.
	 *
	 * @param string $method   HTTP method.
	 * @param string $path     API path.
	 * @param string $store_id Connected store id.
	 * @return bool
	 */
	public static function is_allowed( $method, $path, $store_id ) {
		if ( '' === $store_id || false !== strpos( $path, '..' ) ) {
			return false;
		}
		$list = self::allow_list( $store_id );
		foreach ( $list[ strtoupper( $method ) ] ?? array() as $pattern ) {
			if ( preg_match( $pattern, $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Forwards an allowed request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( $request ) {
		if ( ! Connection::is_connected() ) {
			return $this->error( 'socialscore_not_connected', __( 'SocialScore is not connected.', 'socialscore' ), 409 );
		}

		$method = strtoupper( $request->get_method() );
		// WordPress hands over the path as sent: an id like guest%3Aabc arrives still encoded.
		$path = '/' . ltrim( rawurldecode( (string) $request['path'] ), '/' );
		if ( ! self::is_allowed( $method, $path, Connection::store_id() ) ) {
			return $this->error( 'socialscore_path_not_allowed', __( 'This request is not allowed.', 'socialscore' ), 403 );
		}

		$query = $request->get_query_params();
		unset( $query['rest_route'], $query['_wpnonce'] );
		$query = array_map( 'sanitize_text_field', array_filter( $query, 'is_scalar' ) );

		$headers = array();
		$key     = (string) $request->get_header( 'idempotency_key' );
		if ( '' !== $key && preg_match( '/^[A-Za-z0-9._:-]{8,128}$/', $key ) ) {
			$headers['Idempotency-Key'] = $key;
		}

		$body = null;
		if ( in_array( $method, array( 'POST', 'PATCH' ), true ) ) {
			$json = $request->get_json_params();
			$body = is_array( $json ) ? $json : array();
		}

		$response = ( new Api_Client() )->request( $method, $path, $body, $headers, $query );
		if ( is_wp_error( $response ) ) {
			return $this->error( 'socialscore_unreachable', __( 'SocialScore could not be reached. Try again in a moment.', 'socialscore' ), 502 );
		}

		if ( 'POST' === $method && $response['status'] >= 200 && $response['status'] < 300 && is_array( $body ) ) {
			if ( isset( $body['order_external_id'] ) ) {
				Labels::apply_from_check( 'order', (string) $body['order_external_id'], $response['data'] );
			} elseif ( isset( $body['customer_external_id'] ) ) {
				Labels::apply_from_check( 'customer', (string) $body['customer_external_id'], $response['data'] );
			}
		}

		if ( 'PATCH' === $method && $response['status'] >= 200 && $response['status'] < 300 && 1 === preg_match( '#/pixel$#D', $path ) ) {
			Pixel::forget(); // The storefront must see the switch at once, not after the cache runs out.
		}

		$out = new \WP_REST_Response( $response['data'], $response['status'] );
		if ( null !== $response['retry_after'] ) {
			$out->header( 'Retry-After', (string) $response['retry_after'] );
		}
		return $out;
	}

	/**
	 * Error body in the shape plugin-core reads (`code`, `message`).
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return \WP_REST_Response
	 */
	private function error( $code, $message, $status ) {
		return new \WP_REST_Response(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}
}
