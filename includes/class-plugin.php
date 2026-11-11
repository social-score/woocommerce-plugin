<?php
/**
 * Plugin bootstrap.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component to its WordPress hooks.
 */
class Plugin {

	/**
	 * Boots the plugin once WordPress and the other plugins are loaded.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woocommerce_notice' ) );
			return;
		}

		( new Rest_Proxy() )->register();
		( new Connect() )->register();
		( new Sync() )->register();
		( new Enrichment() )->register();
		( new Privacy() )->register();
		( new Pixel() )->register();

		if ( is_admin() ) {
			( new Admin_Page() )->register();
			( new Check_Panels() )->register();
		}
	}

	/**
	 * Tells the admin that WooCommerce is required.
	 *
	 * @return void
	 */
	public static function missing_woocommerce_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'SocialScore for WooCommerce needs WooCommerce to be installed and active.', 'socialscore' ) . '</p></div>';
	}

	/**
	 * Stops all background work. The connection stays, so activating again resumes.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Sync::unschedule_all();
	}
}
