<?php
/**
 * The SocialScore admin menu page.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * Connect screen when not connected, the plugin-core dashboard when connected.
 */
class Admin_Page {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Adds the top-level menu.
	 *
	 * @return void
	 */
	public function add_menu() {
		$hook = add_menu_page(
			__( 'SocialScore', 'socialscore' ),
			__( 'SocialScore', 'socialscore' ),
			Config::CAPABILITY,
			Config::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-shield',
			56
		);
		add_action( 'load-' . $hook, array( $this, 'enqueue' ) );
	}

	/**
	 * Loads the dashboard bundle on our page only.
	 *
	 * @return void
	 */
	public function enqueue() {
		Assets::enqueue( 'dashboard' );
	}

	/**
	 * Message for a result code in the URL.
	 *
	 * @param string $result Code from the connect flow.
	 * @return array{0:string,1:string} CSS class and text; empty text for none.
	 */
	public static function result_notice( $result ) {
		switch ( $result ) {
			case 'connected':
				return array( 'notice-success', __( 'SocialScore is connected. Your existing customers and orders are being imported in the background.', 'socialscore' ) );
			case 'disconnected':
				return array( 'notice-success', __( 'SocialScore is disconnected.', 'socialscore' ) );
			case 'denied':
				return array( 'notice-warning', __( 'The connection was not approved in SocialScore. Nothing was connected.', 'socialscore' ) );
			case 'state':
				return array( 'notice-error', __( 'The connection attempt expired or did not match. Start again.', 'socialscore' ) );
			case 'exchange':
				return array( 'notice-error', __( 'SocialScore could not complete the connection. Try again in a moment.', 'socialscore' ) );
		}
		return array( '', '' );
	}

	/**
	 * Text about the first import.
	 *
	 * @return string
	 */
	public static function import_status_text() {
		$state = get_option( Config::OPTION_SYNC, array() );
		if ( ! is_array( $state ) || empty( $state['status'] ) ) {
			return '';
		}
		$customers = isset( $state['customers'] ) ? (int) $state['customers'] : 0;
		$orders    = isset( $state['orders'] ) ? (int) $state['orders'] : 0;
		if ( 'done' === $state['status'] ) {
			/* translators: 1: customers sent, 2: orders sent. */
			return sprintf( __( 'Import finished: %1$d customers and %2$d orders sent to SocialScore.', 'socialscore' ), $customers, $orders );
		}
		if ( 'failed' === $state['status'] ) {
			return __( 'The import stopped after repeated errors. Disconnect and connect again to restart it.', 'socialscore' );
		}
		/* translators: 1: customers sent so far, 2: orders sent so far. */
		return sprintf( __( 'Import running: %1$d customers and %2$d orders sent so far.', 'socialscore' ), $customers, $orders );
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Config::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'socialscore' ), '', array( 'response' => 403 ) );
		}

		// Read-only display of a result code; it selects a fixed message.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result = isset( $_GET['socialscore_result'] ) ? sanitize_key( wp_unslash( $_GET['socialscore_result'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$profile                            = isset( $_GET['profile'] ) ? sanitize_text_field( wp_unslash( $_GET['profile'] ) ) : '';
		list( $notice_class, $notice_text ) = self::result_notice( $result );

		echo '<div class="wrap socialscore-admin-wrap"><h1>' . esc_html__( 'SocialScore', 'socialscore' ) . '</h1>';
		if ( '' !== $notice_text ) {
			echo '<div class="notice ' . esc_attr( $notice_class ) . '"><p>' . esc_html( $notice_text ) . '</p></div>';
		}

		if ( ! Connection::is_connected() ) {
			if ( Connection::was_lost() ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'SocialScore no longer accepts this store\'s key. The store was uninstalled in SocialScore or connected again somewhere else. Connect again to continue.', 'socialscore' ) . '</p></div>';
			}
			$this->render_connect();
		} else {
			$this->render_dashboard( $profile );
		}
		echo '</div>';
	}

	/**
	 * Consent text and the connect screen. Nothing is sent before the button is used.
	 *
	 * @return void
	 */
	private function render_connect() {
		echo '<div class="socialscore-admin">';
		echo '<p>' . wp_kses(
			sprintf(
				/* translators: 1: terms of service link, 2: privacy policy link. */
				__( 'Connecting sends your store address, and then your customers (email, phone, name, city, postcode, country, order count and total paid) and orders (products, totals, shipping city and country) to the SocialScore service. Until you connect, nothing is sent. See the <a href="%1$s" target="_blank" rel="noopener">Terms of Service</a> and the <a href="%2$s" target="_blank" rel="noopener">Privacy Policy</a>.', 'socialscore' ),
				esc_url( Config::TERMS_URL ),
				esc_url( Config::PRIVACY_URL )
			),
			array(
				'a' => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
			)
		) . '</p>';
		printf(
			'<div id="socialscore-root" data-connected="0" data-connect-url="%s"></div>',
			esc_url( Connect::start_url() )
		);
		echo '</div>';
	}

	/**
	 * The dashboard mount point, status line and disconnect form.
	 *
	 * @param string $profile Profile to open, `customer/42` or `order/42`.
	 * @return void
	 */
	private function render_dashboard( $profile ) {
		$status = self::import_status_text();
		if ( '' !== $status ) {
			echo '<p class="socialscore-import-status">' . esc_html( $status ) . '</p>';
		}

		echo '<div class="socialscore-admin">';
		printf(
			'<div id="socialscore-root" data-connected="1" data-store-id="%1$s" data-rest-url="%2$s" data-nonce="%3$s" data-dashboard-url="%4$s" data-profile="%5$s"></div>',
			esc_attr( Connection::store_id() ),
			esc_url( rest_url( Rest_Proxy::NAMESPACE_ . '/proxy' ) ),
			esc_attr( wp_create_nonce( 'wp_rest' ) ),
			esc_url( admin_url( 'admin.php?page=' . Config::MENU_SLUG ) ),
			esc_attr( $profile )
		);
		echo '</div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="socialscore-disconnect">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Connect::DISCONNECT_ACTION ) . '" />';
		wp_nonce_field( Connect::DISCONNECT_ACTION );
		submit_button( __( 'Disconnect SocialScore', 'socialscore' ), 'secondary', 'submit', false );
		echo '</form>';
	}
}
