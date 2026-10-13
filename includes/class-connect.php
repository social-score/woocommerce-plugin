<?php
/**
 * Store connect flow (OAuth-like, PKCE) and disconnect.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing is sent to SocialScore until an administrator starts this flow.
 */
class Connect {

	const START_ACTION      = 'socialscore_connect_start';
	const CALLBACK_ACTION   = 'socialscore_connect_callback';
	const DISCONNECT_ACTION = 'socialscore_disconnect';
	const PKCE_TTL          = 600;

	/**
	 * Registers the admin-post handlers.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_' . self::START_ACTION, array( $this, 'handle_start' ) );
		add_action( 'admin_post_' . self::CALLBACK_ACTION, array( $this, 'handle_callback' ) );
		add_action( 'admin_post_' . self::DISCONNECT_ACTION, array( $this, 'handle_disconnect' ) );
	}

	/**
	 * Nonce-protected URL that begins the flow.
	 *
	 * @return string
	 */
	public static function start_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::START_ACTION ), self::START_ACTION );
	}

	/**
	 * The URL SocialScore sends the browser back to.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=' . self::CALLBACK_ACTION );
	}

	/**
	 * Base64url without padding (RFC 7636).
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	public static function base64url( $bytes ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE encoding required by RFC 7636.
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * S256 code challenge for a verifier.
	 *
	 * @param string $verifier Code verifier.
	 * @return string
	 */
	public static function challenge( $verifier ) {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * The store-connect URL on SocialScore.
	 *
	 * `shop` is the storefront host (home_url) and `redirect_uri` lives on the WordPress admin host (admin_url).
	 * They differ when siteurl and home are on different hosts. Needs backend (api-extras item 10): the server must
	 * accept a redirect_uri on the shop's registrable domain and a redirect_uri field in the token request; until
	 * then a split-host site cannot connect. The same redirect_uri is sent again with the token request, as
	 * RFC 6749 section 4.1.3 requires.
	 *
	 * @param string $state     Random state.
	 * @param string $challenge PKCE challenge.
	 * @return string
	 */
	public static function authorize_url( $state, $challenge ) {
		return add_query_arg(
			array(
				'platform'              => Config::PLATFORM,
				'shop'                  => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'redirect_uri'          => self::redirect_uri(),
				'state'                 => $state,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			),
			Config::api_base() . '/api/v2/stores/connect'
		);
	}

	/**
	 * Starts the flow: remembers state and verifier for this user and sends the browser to SocialScore.
	 *
	 * @return void
	 */
	public function handle_start() {
		if ( ! current_user_can( Config::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'socialscore' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::START_ACTION );

		// The target is the fixed SocialScore API host, so wp_safe_redirect (same-host only) cannot be used.
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		wp_redirect( self::begin( get_current_user_id() ) );
		exit;
	}

	/**
	 * Remembers state and verifier for the user and returns the SocialScore connect URL.
	 *
	 * @param int $user_id User who starts the flow.
	 * @return string
	 */
	public static function begin( $user_id ) {
		$state    = self::base64url( random_bytes( 24 ) );
		$verifier = self::base64url( random_bytes( 48 ) );
		set_transient(
			'socialscore_pkce_' . hash( 'sha256', $state ),
			array(
				'verifier'     => $verifier,
				'user_id'      => (int) $user_id,
				'redirect_uri' => self::redirect_uri(),
			),
			self::PKCE_TTL
		);
		return self::authorize_url( $state, self::challenge( $verifier ) );
	}

	/**
	 * Receives the code, checks state, exchanges it and stores the store key.
	 *
	 * @return void
	 */
	public function handle_callback() {
		if ( ! current_user_can( Config::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'socialscore' ), '', array( 'response' => 403 ) );
		}

		// The one-time state parameter protects this redirect, not a nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = $this->complete( $state, $code, $error, get_current_user_id() );
		self::finish( $result );
	}

	/**
	 * The testable core of the callback.
	 *
	 * @param string $state   State from the query.
	 * @param string $code    Code from the query.
	 * @param string $error   Error code from the query.
	 * @param int    $user_id Current user.
	 * @return string `connected` or an error code.
	 */
	public function complete( $state, $code, $error, $user_id ) {
		if ( '' === $state ) {
			return 'state';
		}
		$key     = 'socialscore_pkce_' . hash( 'sha256', $state );
		$pending = get_transient( $key );
		delete_transient( $key ); // One use only.

		if ( ! is_array( $pending ) || (int) $pending['user_id'] !== (int) $user_id ) {
			return 'state';
		}
		if ( '' !== $error || '' === $code ) {
			return 'denied';
		}

		$client   = new Api_Client();
		$response = $client->exchange_token( $code, $pending['verifier'], isset( $pending['redirect_uri'] ) ? (string) $pending['redirect_uri'] : self::redirect_uri() );
		if ( is_wp_error( $response ) || $response['status'] < 200 || $response['status'] > 299 ) {
			return 'exchange';
		}
		$data = $response['data'];
		if ( ! is_array( $data ) || empty( $data['store_id'] ) || empty( $data['api_key'] ) ) {
			return 'exchange';
		}

		Connection::save( sanitize_text_field( (string) $data['store_id'] ), (string) $data['api_key'] );
		Sync::start_import();
		return 'connected';
	}

	/**
	 * Sends the admin back to the dashboard with a result code.
	 *
	 * @param string $result Result code.
	 * @return void
	 */
	private static function finish( $result ) {
		wp_safe_redirect( add_query_arg( 'socialscore_result', $result, admin_url( 'admin.php?page=' . Config::MENU_SLUG ) ) );
		exit;
	}

	/**
	 * Disconnects: tells SocialScore (best effort), then forgets the key and stops background work.
	 *
	 * @return void
	 */
	public function handle_disconnect() {
		if ( ! current_user_can( Config::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'socialscore' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::DISCONNECT_ACTION );
		self::disconnect();
		self::finish( 'disconnected' );
	}

	/**
	 * Disconnect without the HTTP plumbing.
	 *
	 * @return void
	 */
	public static function disconnect() {
		if ( Connection::is_connected() ) {
			( new Api_Client() )->delete_store();
		}
		Connection::clear();
		Sync::unschedule_all();
		delete_option( Config::OPTION_SYNC );
	}
}
