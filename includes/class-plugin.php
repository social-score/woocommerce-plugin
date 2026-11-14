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
		( new Bot_Guard() )->register();
		add_action( Bot_Verifier::HOOK, array( Bot_Verifier::class, 'refresh' ) );
		// On init: scheduling reads the cron schedules, and WooCommerce translates their names, which is too early before init.
		add_action( 'init', array( Bot_Verifier::class, 'schedule' ) );
		( new Not_Human() )->register();
		( new Identity() )->register();

		if ( is_admin() ) {
			( new Admin_Page() )->register();
			( new Check_Panels() )->register();
			( new Reports_Page() )->register();
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
		wp_clear_scheduled_hook( Bot_Verifier::HOOK );
	}
}
