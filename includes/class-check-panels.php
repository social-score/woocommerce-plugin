<?php
/**
 * Check panel on the order screen and the user profile, plus the orders list column.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * HPOS aware: order screens differ between custom order tables and legacy posts.
 */
class Check_Panels {

	const COLUMN = 'socialscore_risk';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'show_user_profile', array( $this, 'render_user_section' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user_section' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		// HPOS list table and legacy post list table.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column_hpos' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column_legacy' ), 10, 2 );
	}

	/**
	 * Screen id of the order edit screen.
	 *
	 * @return string
	 */
	public static function order_screen_id() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// wc_get_page_screen_id() is only loaded in wp-admin; the literal is what it returns for orders.
			return function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'woocommerce_page_wc-orders';
		}
		return 'shop_order';
	}

	/**
	 * Adds the meta box to the order screen.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		add_meta_box(
			'socialscore-check',
			__( 'SocialScore', 'socialscore' ),
			array( $this, 'render_meta_box' ),
			self::order_screen_id(),
			'side',
			'default'
		);
	}

	/**
	 * Meta box callback: gets a WP_Post (legacy) or a WC_Order (HPOS).
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Object for the screen.
	 * @return void
	 */
	public function render_meta_box( $post_or_order ) {
		$order = ( $post_or_order instanceof \WP_Post ) ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order ) {
			return;
		}
		echo wp_kses_post( self::panel_markup( 'order', (string) $order->get_id() ) );
	}

	/**
	 * Section on the user profile for customers.
	 *
	 * @param \WP_User $user User being edited.
	 * @return void
	 */
	public function render_user_section( $user ) {
		if ( ! current_user_can( Config::CAPABILITY ) || ! in_array( 'customer', (array) $user->roles, true ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'SocialScore', 'socialscore' ) . '</h2>';
		echo wp_kses_post( self::panel_markup( 'customer', Mapper::customer_external_id( $user->ID, $user->user_email ) ) );
	}

	/**
	 * Mount point for plugin-core's CheckPanel. All values are escaped.
	 *
	 * @param string $kind        order or customer.
	 * @param string $external_id Our id.
	 * @return string
	 */
	public static function panel_markup( $kind, $external_id ) {
		if ( ! Connection::is_connected() ) {
			return '<p>' . sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . Config::MENU_SLUG ) ),
				esc_html__( 'Connect SocialScore to check this record.', 'socialscore' )
			) . '</p>';
		}
		return sprintf(
			'<div class="socialscore-admin"><div class="socialscore-panel-root" data-kind="%1$s" data-external-id="%2$s" data-store-id="%3$s" data-rest-url="%4$s" data-nonce="%5$s" data-dashboard-url="%6$s"></div></div>',
			esc_attr( $kind ),
			esc_attr( $external_id ),
			esc_attr( Connection::store_id() ),
			esc_url( rest_url( Rest_Proxy::NAMESPACE_ . '/proxy' ) ),
			esc_attr( wp_create_nonce( 'wp_rest' ) ),
			esc_url( admin_url( 'admin.php?page=' . Config::MENU_SLUG ) )
		);
	}

	/**
	 * Loads the panel bundle on order and profile screens.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! Connection::is_connected() || ! current_user_can( Config::CAPABILITY ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->id, array( self::order_screen_id(), 'profile', 'user-edit' ), true ) ) {
			Assets::enqueue( 'panel' );
		}
	}

	/**
	 * Adds the risk column after the status column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new[ self::COLUMN ] = __( 'SocialScore', 'socialscore' );
			}
		}
		if ( ! isset( $new[ self::COLUMN ] ) ) {
			$new[ self::COLUMN ] = __( 'SocialScore', 'socialscore' );
		}
		return $new;
	}

	/**
	 * HPOS column cell.
	 *
	 * @param string    $column Column key.
	 * @param \WC_Order $order  Order.
	 * @return void
	 */
	public function render_column_hpos( $column, $order ) {
		if ( self::COLUMN === $column && $order ) {
			echo esc_html( self::column_text( $order ) );
		}
	}

	/**
	 * Legacy column cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Order id.
	 * @return void
	 */
	public function render_column_legacy( $column, $post_id ) {
		if ( self::COLUMN === $column ) {
			$order = wc_get_order( $post_id );
			if ( $order ) {
				echo esc_html( self::column_text( $order ) );
			}
		}
	}

	/**
	 * Text of the cell: the label names, or a dash.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function column_text( $order ) {
		$labels = Labels::for_order( $order );
		if ( ! $labels ) {
			return '–';
		}
		return implode( ', ', array_map( array( Labels::class, 'text' ), $labels ) );
	}
}
