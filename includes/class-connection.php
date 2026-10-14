<?php
/**
 * The stored link to a SocialScore store.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the store id and the (encrypted) store key. Stored with autoload off.
 */
class Connection {

	/**
	 * Saves a new connection.
	 *
	 * @param string $store_id SocialScore store id.
	 * @param string $api_key  Store-scoped API key.
	 * @return void
	 */
	public static function save( $store_id, $api_key ) {
		delete_option( Config::OPTION_CONN );
		delete_option( Config::OPTION_LOST );
		add_option(
			Config::OPTION_CONN,
			array(
				'store_id'     => (string) $store_id,
				'key'          => Crypto::encrypt( $api_key ),
				'connected_at' => time(),
			),
			'',
			'no'
		);
	}

	/**
	 * Forgets the connection.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( Config::OPTION_CONN );
		delete_transient( Config::TRANSIENT_CONF );
	}

	/**
	 * SocialScore answered 401 for the store key: forget the key and remember that the admin has to connect again.
	 *
	 * @return void
	 */
	public static function mark_lost() {
		self::clear();
		update_option( Config::OPTION_LOST, time(), false );
	}

	/**
	 * Whether the key was dropped because SocialScore no longer accepts it.
	 *
	 * @return bool
	 */
	public static function was_lost() {
		return (bool) get_option( Config::OPTION_LOST, false );
	}

	/**
	 * The stored record or an empty array.
	 *
	 * @return array
	 */
	private static function record() {
		$record = get_option( Config::OPTION_CONN, array() );
		return is_array( $record ) ? $record : array();
	}

	/**
	 * Whether a store is connected.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$record = self::record();
		return ! empty( $record['store_id'] ) && ! empty( $record['key'] );
	}

	/**
	 * SocialScore's id of the connected store, or an empty string.
	 *
	 * @return string
	 */
	public static function store_id() {
		$record = self::record();
		return isset( $record['store_id'] ) ? (string) $record['store_id'] : '';
	}

	/**
	 * The store key in clear text. Only for server-side calls; never send it to a browser.
	 *
	 * @return string
	 */
	public static function api_key() {
		$record = self::record();
		return isset( $record['key'] ) ? Crypto::decrypt( $record['key'] ) : '';
	}
}
