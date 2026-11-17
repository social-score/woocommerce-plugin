<?php
/**
 * Plugin Name:       SocialScore for WooCommerce
 * Plugin URI:        https://socialscore.io/
 * Description:       Check customers and orders with SocialScore: digital presence, email and phone quality, fraud signals and business-buyer detection, right inside WooCommerce.
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * Author:            SocialScore
 * Author URI:        https://socialscore.io/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       socialscore
 *
 * @package SocialScore
 */

defined( 'ABSPATH' ) || exit;

define( 'SOCIALSCORE_VERSION', '0.2.0' );
define( 'SOCIALSCORE_FILE', __FILE__ );
define( 'SOCIALSCORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOCIALSCORE_URL', plugin_dir_url( __FILE__ ) );

require_once SOCIALSCORE_DIR . 'includes/autoload.php';

// Declare compatibility with High-Performance Order Storage (custom order tables).
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SOCIALSCORE_FILE, true );
		}
	}
);

register_deactivation_hook( __FILE__, array( '\SocialScore\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( '\SocialScore\Plugin', 'boot' ), 20 );
