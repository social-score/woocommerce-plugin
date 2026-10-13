<?php
/**
 * HTTP client for the SocialScore public API v2.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * Every call to SocialScore goes through here. Nothing is sent before the store is connected.
 */
class Api_Client {

	/**
	 * Calls the API with the store key. Returns an array with `status`, `data` and `retry_after`
	 * (seconds or null), or a WP_Error when the request could not be made.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    API path starting with /api/v2/.
	 * @param array|null $body    JSON body.
	 * @param array      $headers Extra headers (for example Idempotency-Key).
	 * @param array      $query   Query arguments.
	 * @return array|\WP_Error
	 */
	public function request( $method, $path, $body = null, array $headers = array(), array $query = array() ) {
		$key = Connection::api_key();
		if ( '' === $key ) {
			return new \WP_Error( 'socialscore_not_connected', __( 'SocialScore is not connected.', 'socialscore' ) );
		}
		return $this->send( $method, $path, $body, array_merge( $headers, array( 'X-API-KEY' => $key ) ), $query );
	}

	/**
	 * Sends a request without a store key (the connect code exchange).
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    API path.
	 * @param array|null $body    JSON body.
	 * @param array      $headers Extra headers.
	 * @param array      $query   Query arguments.
	 * @return array|\WP_Error
	 */
	public function send( $method, $path, $body = null, array $headers = array(), array $query = array() ) {
		$url = Config::api_base() . $path;
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}
		$args = array(
			'method'      => $method,
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => array_merge(
				array(
					'Accept'     => 'application/json',
					'User-Agent' => 'SocialScore-WooCommerce/' . SOCIALSCORE_VERSION . '; ' . home_url(),
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
		$raw         = wp_remote_retrieve_body( $response );
		$data        = '' === $raw ? null : json_decode( $raw, true );

		return array(
			'status'      => (int) wp_remote_retrieve_response_code( $response ),
			'data'        => $data,
			'retry_after' => ( '' !== $retry_after && ctype_digit( (string) $retry_after ) ) ? (int) $retry_after : null,
		);
	}

	/**
	 * Exchanges the connect code for a store key (PKCE).
	 *
	 * @param string $code     Authorization code.
	 * @param string $verifier PKCE code verifier.
	 * @param string $redirect_uri The redirect_uri sent with the connect request (RFC 6749 section 4.1.3).
	 * @return array|\WP_Error
	 */
	public function exchange_token( $code, $verifier, $redirect_uri ) {
		return $this->send(
			'POST',
			'/api/v2/stores/token',
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'code_verifier' => $verifier,
				'redirect_uri'  => $redirect_uri,
			)
		);
	}

	/**
	 * Pushes customers (no charge).
	 *
	 * @param array $customers Rows from Mapper.
	 * @return array|\WP_Error
	 */
	public function push_customers( array $customers ) {
		return $this->request( 'POST', $this->store_path() . '/customers:batch', array( 'customers' => array_values( $customers ) ) );
	}

	/**
	 * Pushes orders (no charge).
	 *
	 * @param array $orders Rows from Mapper.
	 * @return array|\WP_Error
	 */
	public function push_orders( array $orders ) {
		return $this->request( 'POST', $this->store_path() . '/orders:batch', array( 'orders' => array_values( $orders ) ) );
	}

	/**
	 * Runs a Check. The server decides whether it is charged or refused (plan).
	 *
	 * @param string $kind            customer or order.
	 * @param string $external_id     Our id of the customer or order.
	 * @param string $idempotency_key Stable key per subject.
	 * @return array|\WP_Error
	 */
	public function run_check( $kind, $external_id, $idempotency_key ) {
		$field = 'order' === $kind ? 'order_external_id' : 'customer_external_id';
		return $this->request(
			'POST',
			$this->store_path() . '/checks',
			array( $field => (string) $external_id ),
			array( 'Idempotency-Key' => $idempotency_key )
		);
	}

	/**
	 * Reads the store settings (enrichment triggers).
	 *
	 * @return array|\WP_Error
	 */
	public function get_settings() {
		return $this->request( 'GET', $this->store_path() . '/settings' );
	}

	/**
	 * Disconnects the store on the SocialScore side.
	 *
	 * @return array|\WP_Error
	 */
	public function delete_store() {
		return $this->request( 'DELETE', $this->store_path() );
	}

	/**
	 * Path of the connected store.
	 *
	 * @return string
	 */
	private function store_path() {
		return '/api/v2/stores/' . rawurlencode( Connection::store_id() );
	}
}
