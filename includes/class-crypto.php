<?php
/**
 * Encryption of the stored store key.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the store key with a key derived from the site's salts (libsodium when available).
 */
class Crypto {

	/**
	 * Derives the 32-byte secretbox key from the WordPress salts.
	 *
	 * @return string
	 */
	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . 'socialscore-store-key', true );
	}

	/**
	 * Encrypts a secret for storage.
	 *
	 * @param string $plain Secret.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext, not obfuscation.
			return 'sodium:' . base64_encode( $nonce . sodium_crypto_secretbox( (string) $plain, $nonce, self::key() ) );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Fallback marker; libsodium is missing on this host.
		return 'plain:' . base64_encode( (string) $plain );
	}

	/**
	 * Decrypts a stored secret. Returns an empty string when it cannot be read.
	 *
	 * @param string $stored Value from encrypt().
	 * @return string
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( 0 === strpos( $stored, 'plain:' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Matches the fallback marker above.
			$raw = base64_decode( substr( $stored, 6 ), true );
			return false === $raw ? '' : $raw;
		}
		if ( 0 === strpos( $stored, 'sodium:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary ciphertext.
			$raw = base64_decode( substr( $stored, 7 ), true );
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
			return false === $plain ? '' : $plain;
		}
		return '';
	}
}
