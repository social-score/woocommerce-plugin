<?php
/**
 * Loads the built admin bundles.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues build/<entry>.js and its stylesheet. The source lives in src/js.
 */
class Assets {

	/**
	 * Enqueues an entry of the wp-scripts build.
	 *
	 * @param string $entry Entry name: dashboard or panel.
	 * @return bool False when the bundle has not been built.
	 */
	public static function enqueue( $entry ) {
		$base  = SOCIALSCORE_DIR . 'build/' . $entry;
		$asset = $base . '.asset.php';
		if ( ! is_readable( $asset ) ) {
			return false;
		}
		$meta   = require $asset;
		$handle = 'socialscore-' . $entry;

		wp_enqueue_script( $handle, SOCIALSCORE_URL . 'build/' . $entry . '.js', $meta['dependencies'], $meta['version'], true );
		wp_set_script_translations( $handle, 'socialscore' );
		wp_add_inline_script(
			$handle,
			'window.socialscoreI18n = ' . wp_json_encode( Messages::all() ) . ';',
			'before'
		);

		if ( is_readable( $base . '.css' ) ) {
			wp_enqueue_style( $handle, SOCIALSCORE_URL . 'build/' . $entry . '.css', array(), $meta['version'] );
		}
		return true;
	}
}
