<?php
namespace SocialScore\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Brain Monkey base with an in-memory options/transients/user-meta store and the HTTP seam.
 */
abstract class TestCase extends PHPUnitTestCase {

	/** @var array */
	protected $options = array();
	/** @var array */
	protected $autoload = array();
	/** @var array */
	protected $transients = array();
	/** @var array */
	protected $user_meta = array();
	/** @var array Requests that reached wp_remote_request. */
	protected $http = array();
	/** @var callable|null Answers wp_remote_request: fn(url, args) => array|WP_Error */
	protected $http_responder;
	/** @var bool */
	protected $define_constants = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( $this->define_constants ) {
			foreach ( array(
				'SOCIALSCORE_VERSION' => '0.1.0',
				'SOCIALSCORE_DIR'     => dirname( __DIR__, 2 ) . '/',
				'SOCIALSCORE_URL'     => 'https://example.test/wp-content/plugins/socialscore/',
				'SOCIALSCORE_FILE'    => dirname( __DIR__, 2 ) . '/socialscore.php',
			) as $name => $value ) {
				if ( ! defined( $name ) ) {
					define( $name, $value );
				}
			}
		}
		require_once dirname( __DIR__, 2 ) . '/includes/autoload.php';

		Functions\stubs(
			array(
				'__'                    => static function ( $t ) { return $t; },
				'esc_html__'            => static function ( $t ) { return $t; },
				'esc_html'              => static function ( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); },
				'esc_attr'              => static function ( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); },
				'esc_url'               => static function ( $t ) { return (string) $t; },
				'sanitize_text_field'   => static function ( $t ) { return trim( strip_tags( (string) $t ) ); },
				'sanitize_key'          => static function ( $t ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $t ) ); },
				'wp_unslash'            => static function ( $t ) { return $t; },
				'wp_json_encode'        => static function ( $d ) { return json_encode( $d ); },
				'untrailingslashit'     => static function ( $t ) { return rtrim( $t, '/' ); },
				'home_url'              => static function () { return 'https://shop.test'; },
				'admin_url'             => static function ( $p = '' ) { return 'https://shop.test/wp-admin/' . $p; },
				'rest_url'              => static function ( $p = '' ) { return 'https://shop.test/wp-json/' . $p; },
				'wp_create_nonce'       => static function () { return 'nonce123'; },
				'is_wp_error'           => static function ( $t ) { return $t instanceof \WP_Error; },
				'wp_salt'               => static function () { return 'unit-test-salt-unit-test-salt-unit-test-salt'; },
				'get_woocommerce_currency' => static function () { return 'EUR'; },
				'add_query_arg'         => static function ( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); },
				'apply_filters'         => static function ( $tag, $value ) { return $value; },
				'wp_remote_retrieve_response_code' => static function ( $r ) { return $r['response']['code']; },
				'wp_remote_retrieve_body'          => static function ( $r ) { return $r['body']; },
				'wp_remote_retrieve_header'        => static function ( $r, $h ) { return $r['headers'][ $h ] ?? ''; },
			)
		);

		Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => $this->options[ $k ] ?? $d );
		Functions\when( 'add_option' )->alias(
			function ( $k, $v, $dep = '', $autoload = 'yes' ) {
				$this->options[ $k ]  = $v;
				$this->autoload[ $k ] = $autoload;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $k, $v, $autoload = null ) {
				$this->options[ $k ] = $v;
				if ( null !== $autoload ) {
					$this->autoload[ $k ] = false === $autoload ? 'no' : 'yes';
				}
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $k ) {
				unset( $this->options[ $k ], $this->autoload[ $k ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( $k ) => $this->transients[ $k ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $k, $v ) {
				$this->transients[ $k ] = $v;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $k ) {
				unset( $this->transients[ $k ] );
				return true;
			}
		);
		Functions\when( 'get_user_meta' )->alias( fn( $id, $k ) => $this->user_meta[ $id ][ $k ] ?? '' );
		Functions\when( 'update_user_meta' )->alias(
			function ( $id, $k, $v ) {
				$this->user_meta[ $id ][ $k ] = $v;
				return true;
			}
		);

		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) {
				$this->http[] = array( 'url' => $url, 'args' => $args );
				return $this->http_responder ? ( $this->http_responder )( $url, $args ) : self::response( 200, array() );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Builds a wp_remote_request style answer.
	 */
	protected static function response( $code, $body, array $headers = array() ) {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => $headers,
			'body'     => is_string( $body ) ? $body : json_encode( $body ),
		);
	}

	/**
	 * Stores a connection the normal way.
	 */
	protected function connect_store( $store_id = '42', $key = 'sk_test_secret_key' ) {
		\SocialScore\Connection::save( $store_id, $key );
	}
}
