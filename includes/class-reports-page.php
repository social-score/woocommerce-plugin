<?php
/**
 * The reports page inside the module: traffic from the Pixel, what the server caught, the bad-bot mode and the warnings.
 *
 * @package SocialScore
 */

namespace SocialScore;

defined( 'ABSPATH' ) || exit;

/**
 * Rendered by PHP from `GET /api/v2/stores/{id}/pixel/report` (the traffic report of stage 2). The browser never sees
 * the store key: the page asks for the report on the server, with the store's own key, and caches an answer for five
 * minutes. Every value is escaped where it is printed.
 */
class Reports_Page {


	const SLUG         = 'socialscore-reports';
	const ACTION       = 'socialscore_bot_mode';
	const PROXY_ACTION = 'socialscore_bot_proxy';
	const CACHE_TTL    = 300;
	const PERIODS      = array( 'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'last_3_months' );
	const WARNINGS     = 20;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save_mode' ) );
		add_action( 'admin_post_' . self::PROXY_ACTION, array( $this, 'save_proxy' ) );
	}

	/**
	 * Adds the Reports entry under SocialScore.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page( Config::MENU_SLUG, __( 'Reports', 'socialscore' ), __( 'Reports', 'socialscore' ), Config::CAPABILITY, self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Texts of the session classes.
	 *
	 * @return array<string,string>
	 */
	public static function class_labels() {
		return array(
			'human'    => __( 'People (active buyers)', 'socialscore' ),
			'lurker'   => __( 'People (only browsing)', 'socialscore' ),
			'info_bot' => __( 'Known bots', 'socialscore' ),
			'ai_agent' => __( 'AI agents', 'socialscore' ),
			'bad_bot'  => __( 'Bad bots', 'socialscore' ),
		);
	}

	/**
	 * Texts of the bad-bot modes.
	 *
	 * @return array<string,string>
	 */
	public static function mode_labels() {
		return array(
			Bot_Guard::MODE_FLAG       => __( 'Flag (default): the session and its orders are flagged; the bot sees the normal price and only whether an item is in stock', 'socialscore' ),
			Bot_Guard::MODE_SUBSTITUTE => __( 'Substitute data: the bot is shown other prices and no stock; what is charged never changes', 'socialscore' ),
			Bot_Guard::MODE_BLOCK      => __( 'Block: the request of a bad bot is stopped', 'socialscore' ),
		);
	}

	/**
	 * A period from the URL, one of the allowed ones.
	 *
	 * @param  mixed $value Raw value.
	 * @return string
	 */
	public static function period( $value ) {
		return is_string( $value ) && in_array( $value, self::PERIODS, true ) ? $value : 'last_30_days';
	}

	/**
	 * The report of the store for a period, grouped, from the API or from the five-minute cache.
	 *
	 * @param  string $period   One of PERIODS.
	 * @param  string $group_by day, channel, class, country or device_type.
	 * @return array|\WP_Error The report, or an error whose code is the API's `detail` (or `http_<status>`).
	 */
	public static function fetch( $period, $group_by ) {
		$key    = 'socialscore_report_' . md5( Connection::store_id() . '|' . $period . '|' . $group_by );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$response = ( new Api_Client() )->request(
			'GET',
			'/api/v2/stores/' . rawurlencode( (string) Connection::store_id() ) . '/pixel/report',
			null,
			array(),
			array(
				'period'   => $period,
				'group_by' => $group_by,
				'compare'  => 'previous_period',
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== $response['status'] || ! is_array( $response['data'] ) || ! isset( $response['data']['totals'] ) ) {
			$code = Sync::error_code( $response );
			return new \WP_Error( '' !== $code ? $code : 'http_' . $response['status'] );
		}
		set_transient( $key, $response['data'], self::CACHE_TTL );
		return $response['data'];
	}

	/**
	 * Text for an error of the report.
	 *
	 * @param  \WP_Error $error Error from fetch().
	 * @return string
	 */
	public static function error_text( $error ) {
		switch ( $error->code ) {
			case 'pixel_not_enabled':
				return __( 'The Pixel is not available for this store yet.', 'socialscore' );
			case 'permission_required':
				return __( 'The SocialScore member who connected this store may not read results. Ask a Main, Marketing or Risk member to connect it.', 'socialscore' );
			case 'missing_scope':
				return __( 'The store key may not read profiles. Connect the store again.', 'socialscore' );
			case 'socialscore_not_connected':
				return __( 'SocialScore is not connected.', 'socialscore' );
		}
		return __( 'The report could not be loaded. Try again in a moment.', 'socialscore' );
	}

	/**
	 * A whole number for the screen.
	 *
	 * @param  int|float $n Number.
	 * @return string
	 */
	private static function num( $n ) {
		return function_exists( 'number_format_i18n' ) ? number_format_i18n( (float) $n ) : (string) (int) $n;
	}

	/**
	 * The change from a previous value, as text, or a dash when there is nothing to compare with.
	 *
	 * @param  int|float $now    Value now.
	 * @param  int|float $before Value in the previous period.
	 * @return string
	 */
	public static function delta( $now, $before ) {
		if ( (float) $before <= 0 ) {
			return '–';
		}
		$percent = ( ( (float) $now - (float) $before ) / (float) $before ) * 100;
		return sprintf( '%+d%%', (int) round( $percent ) );
	}

	/**
	 * Rows of the totals table: label and the key in the totals.
	 *
	 * @return array<string,string>
	 */
	private static function total_rows() {
		return array(
			'sessions'       => __( 'Sessions', 'socialscore' ),
			'humans'         => __( 'People', 'socialscore' ),
			'pages'          => __( 'Pages viewed', 'socialscore' ),
			'add_to_cart'    => __( 'Added to cart', 'socialscore' ),
			'begin_checkout' => __( 'Started checkout', 'socialscore' ),
			'orders'         => __( 'Orders', 'socialscore' ),
		);
	}

	/**
	 * Totals table.
	 *
	 * @param  array $report Report.
	 * @return string
	 */
	public static function totals_html( array $report ) {
		$totals = is_array( $report['totals'] ?? null ) ? $report['totals'] : array();
		$before = is_array( $report['comparison']['totals'] ?? null ) ? $report['comparison']['totals'] : null;
		$html   = '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Traffic', 'socialscore' ) . '</th><th>' . esc_html__( 'Now', 'socialscore' ) . '</th><th>' . esc_html__( 'Previous period', 'socialscore' ) . '</th><th>' . esc_html__( 'Change', 'socialscore' ) . '</th></tr></thead><tbody>';
		foreach ( self::total_rows() as $key => $label ) {
			$now   = isset( $totals[ $key ] ) ? (int) $totals[ $key ] : 0;
			$prev  = $before && isset( $before[ $key ] ) ? (int) $before[ $key ] : null;
			$html .= '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( self::num( $now ) ) . '</td><td>' . ( null === $prev ? '–' : esc_html( self::num( $prev ) ) ) . '</td><td>' . ( null === $prev ? '–' : esc_html( self::delta( $now, $prev ) ) ) . '</td></tr>';
		}
		$revenue = is_array( $totals['revenue'] ?? null ) ? $totals['revenue'] : array();
		$html   .= '<tr><td>' . esc_html__( 'Revenue', 'socialscore' ) . '</td><td colspan="3">';
		if ( $revenue ) {
			$parts = array();
			foreach ( $revenue as $currency => $amount ) {
				if ( is_string( $currency ) && 1 === preg_match( '/^[A-Z]{3}$/D', $currency ) && is_numeric( $amount ) ) {
					$parts[] = esc_html( $currency . ' ' . number_format( (float) $amount, 2, '.', ' ' ) );
				}
			}
			$html .= $parts ? implode( ', ', $parts ) : '–';
		} else {
			$html .= '–';
		}
		return $html . '</td></tr></tbody></table>';
	}

	/**
	 * A table of the series of a report, one row per key.
	 *
	 * @param  array                $report Report grouped by class or channel.
	 * @param  string               $title  Heading of the first column.
	 * @param  array<string,string> $labels Key => text; a key without text shows as it is.
	 * @return string
	 */
	public static function series_html( array $report, $title, array $labels = array() ) {
		$series = is_array( $report['series'] ?? null ) ? $report['series'] : array();
		if ( ! $series ) {
			return '<p>' . esc_html__( 'No visits were counted in this period.', 'socialscore' ) . '</p>';
		}
		$html = '<table class="widefat striped"><thead><tr><th>' . esc_html( $title ) . '</th><th>' . esc_html__( 'Sessions', 'socialscore' ) . '</th><th>' . esc_html__( 'Orders', 'socialscore' ) . '</th></tr></thead><tbody>';
		foreach ( $series as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['key'] ) || ! is_string( $row['key'] ) ) {
				continue;
			}
			$label = isset( $labels[ $row['key'] ] ) ? $labels[ $row['key'] ] : $row['key'];
			$html .= '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( self::num( (int) ( $row['sessions'] ?? 0 ) ) ) . '</td><td>' . esc_html( self::num( (int) ( $row['orders'] ?? 0 ) ) ) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}

	/**
	 * What this server caught without JavaScript, for the last 30 days.
	 *
	 * @return string
	 */
	public static function server_html() {
		$totals = Bot_Stats::totals( 30 );
		$labels = self::class_labels();
		$html   = '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Seen by this server', 'socialscore' ) . '</th><th>' . esc_html__( 'Requests, last 30 days', 'socialscore' ) . '</th></tr></thead><tbody>';
		foreach ( $totals as $class => $n ) {
			$html .= '<tr><td>' . esc_html( $labels[ $class ] ) . '</td><td>' . esc_html( self::num( $n ) ) . '</td></tr>';
		}
		return $html . '</tbody></table><p class="description">' . esc_html__( 'Counted from the headers of each request, with no cookie and no JavaScript. These are requests, not visitors.', 'socialscore' ) . '</p>';
	}

	/**
	 * The flagged orders, newest first.
	 *
	 * @return string
	 */
	public static function warnings_html() {
		$orders = wc_get_orders(
			array(
				'limit'    => self::WARNINGS,
				'orderby'  => 'date',
				'order'    => 'DESC',
				'meta_key' => Not_Human::META_CLASS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_compare' => 'EXISTS',
			)
		);
		if ( ! $orders ) {
			return '<p>' . esc_html__( 'No order is flagged as not from a human.', 'socialscore' ) . '</p>';
		}
		$html = '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Order', 'socialscore' ) . '</th><th>' . esc_html__( 'Class', 'socialscore' ) . '</th><th>' . esc_html__( 'Why', 'socialscore' ) . '</th></tr></thead><tbody>';
		foreach ( $orders as $order ) {
			$flag = Not_Human::for_order( $order );
			if ( ! $flag ) {
				continue;
			}
			$why   = implode( ', ', array_map( array( Not_Human::class, 'signal_text' ), $flag['signals'] ) );
			$html .= '<tr><td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( (string) $order->get_order_number() ) . '</a></td><td>' . esc_html( Not_Human::text( $flag['class'] ) ) . '</td><td>' . esc_html( '' !== $why ? $why : '–' ) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}

	/**
	 * The mode form.
	 *
	 * @param  string $mode Current mode.
	 * @return string
	 */
	public static function mode_form_html( $mode ) {
		$html = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" /><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::ACTION ) ) . '" />';
		foreach ( self::mode_labels() as $value => $label ) {
			$html .= '<p><label><input type="radio" name="bot_mode" value="' . esc_attr( $value ) . '"' . ( $value === $mode ? ' checked="checked"' : '' ) . ' /> ' . esc_html( $label ) . '</label></p>';
		}
		return $html . '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save', 'socialscore' ) . '</button></p></form>';
	}

	/**
	 * The warning for a shop that is behind a proxy it has not described: a search engine was turned away, or could not be
	 * checked, from an address that looks like a proxy's.
	 *
	 * @return string
	 */
	public static function hints_html() {
		$hints = Bot_Settings::hints();
		$html  = '';
		if ( ! empty( $hints['cloudflare']['count'] ) ) {
			/* translators: %d: number of requests. */
			$html .= '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'It looks like this shop is behind Cloudflare: %d requests from Googlebot or Bingbot came from a Cloudflare address, so they could not be checked. If the server only accepts requests from Cloudflare, switch on "Behind Cloudflare" below.', 'socialscore' ), (int) $hints['cloudflare']['count'] ) ) . '</p></div>';
		}
		if ( ! empty( $hints['proxy']['count'] ) ) {
			/* translators: %d: number of requests. */
			$html .= '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'It looks like this shop is behind a proxy: %d requests that said they were Googlebot or Bingbot came with proxy headers and were taken for fakes. Enter the proxy under "Trusted proxies" below, or search engines may be turned away in block mode.', 'socialscore' ), (int) $hints['proxy']['count'] ) ) . '</p></div>';
		}
		return $html;
	}

	/**
	 * The proxy settings form.
	 *
	 * @return string
	 */
	public static function proxy_form_html() {
		$s     = Bot_Settings::get();
		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::PROXY_ACTION ) . '" /><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::PROXY_ACTION ) ) . '" />';
		$html .= '<p><label><input type="checkbox" name="behind_cloudflare" value="1"' . ( $s['behind_cloudflare'] ? ' checked="checked"' : '' ) . ' /> ' . esc_html__( 'Behind Cloudflare: take the visitor from CF-Connecting-IP. Safe only when the server accepts requests from Cloudflare alone (a firewall, or Authenticated Origin Pulls).', 'socialscore' ) . '</label></p>';
		$html .= '<p><label>' . esc_html__( 'Trusted proxies (addresses or ranges, one per line)', 'socialscore' ) . '<br /><textarea name="trusted_proxies" rows="4" cols="40">' . esc_textarea( implode( "\n", $s['trusted_proxies'] ) ) . '</textarea></label></p>';
		$html .= '<p><label>' . esc_html__( 'Header with the visitor, for the trusted proxies', 'socialscore' ) . ' <input type="text" name="proxy_header" value="' . esc_attr( $s['header'] ) . '" /></label></p>';
		return $html . '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save', 'socialscore' ) . '</button></p></form>';
	}

	/**
	 * Saves the proxy settings.
	 *
	 * @return void
	 */
	public function save_proxy() {
		// A trusted proxy decides whose address the module believes: only an administrator may name one, not a shop manager.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'socialscore' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::PROXY_ACTION );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer above.
		$cloudflare = ! empty( $_POST['behind_cloudflare'] );
		$proxies    = isset( $_POST['trusted_proxies'] ) && is_string( $_POST['trusted_proxies'] ) ? sanitize_textarea_field( wp_unslash( $_POST['trusted_proxies'] ) ) : '';
		$header     = isset( $_POST['proxy_header'] ) && is_string( $_POST['proxy_header'] ) ? sanitize_text_field( wp_unslash( $_POST['proxy_header'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		Bot_Settings::save( $cloudflare, $proxies, $header );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::SLUG,
					'socialscore_mode' => 'proxy_saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Saves the mode in SocialScore, where the decision is kept: the module only sends it and reads it back.
	 *
	 * @return void
	 */
	public function save_mode() {
		if ( ! current_user_can( Config::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'socialscore' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
     // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer above.
		$mode   = isset( $_POST['bot_mode'] ) ? sanitize_key( wp_unslash( $_POST['bot_mode'] ) ) : '';
		$result = 'failed';
		$state  = Pixel::state();
		if ( ! in_array( $mode, array_keys( self::mode_labels() ), true ) ) {
			$result = 'invalid';
		} elseif ( ! Connection::is_connected() || empty( $state['enabled'] ) ) {
			$result = 'off';
		} else {
			$response = ( new Api_Client() )->request(
				'PATCH',
				'/api/v2/stores/' . rawurlencode( (string) Connection::store_id() ) . '/pixel',
				array(
					'enabled'  => true,
					'bot_mode' => $mode,
				)
			);
			if ( ! is_wp_error( $response ) && 200 === $response['status'] ) {
				Pixel::forget();
				$result = 'saved';
			} elseif ( ! is_wp_error( $response ) && 403 === $response['status'] ) {
				$result = 'denied';
			}
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::SLUG,
					'socialscore_mode' => $result,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Notice text for the result of saving the mode.
	 *
	 * @param  string $result Code from the URL.
	 * @return array{0:string,1:string} CSS class and text; empty text for none.
	 */
	public static function mode_notice( $result ) {
		switch ( $result ) {
			case 'saved':
				return array( 'notice-success', __( 'The bad-bot mode is saved.', 'socialscore' ) );
			case 'proxy_saved':
				return array( 'notice-success', __( 'The proxy settings are saved.', 'socialscore' ) );
			case 'denied':
				return array( 'notice-error', __( 'Only the SocialScore member who connected this store with the Main role can change the mode.', 'socialscore' ) );
			case 'off':
				return array( 'notice-warning', __( 'Switch the Pixel on first.', 'socialscore' ) );
			case 'invalid':
			case 'failed':
				return array( 'notice-error', __( 'The mode could not be saved. Try again in a moment.', 'socialscore' ) );
		}
		return array( '', '' );
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
		echo '<div class="wrap socialscore-admin-wrap"><h1>' . esc_html__( 'SocialScore reports', 'socialscore' ) . '</h1>';
		if ( ! Connection::is_connected() ) {
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . Config::MENU_SLUG ) ) . '">' . esc_html__( 'Connect SocialScore to see the reports.', 'socialscore' ) . '</a></p></div>';
			return;
		}
     // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of a period and a result code.
		$period = self::period( isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : '' );
		$result = isset( $_GET['socialscore_mode'] ) ? sanitize_key( wp_unslash( $_GET['socialscore_mode'] ) ) : '';
     // phpcs:enable WordPress.Security.NonceVerification.Recommended
		list( $notice_class, $notice_text ) = self::mode_notice( $result );
		if ( '' !== $notice_text ) {
			echo '<div class="notice ' . esc_attr( $notice_class ) . '"><p>' . esc_html( $notice_text ) . '</p></div>';
		}

		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" /><label>' . esc_html__( 'Period', 'socialscore' ) . ' <select name="period">';
		foreach ( self::PERIODS as $p ) {
			echo '<option value="' . esc_attr( $p ) . '"' . ( $p === $period ? ' selected="selected"' : '' ) . '>' . esc_html( ucfirst( str_replace( '_', ' ', $p ) ) ) . '</option>';
		}
		echo '</select></label> <button type="submit" class="button">' . esc_html__( 'Show', 'socialscore' ) . '</button></form>';

		echo '<h2>' . esc_html__( 'Traffic', 'socialscore' ) . '</h2>';
		$by_class = self::fetch( $period, 'class' );
		if ( is_wp_error( $by_class ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( self::error_text( $by_class ) ) . '</p></div>';
		} else {
			echo wp_kses_post( self::totals_html( $by_class ) );
			echo '<h3>' . esc_html__( 'By kind of visitor', 'socialscore' ) . '</h3>' . wp_kses_post( self::series_html( $by_class, __( 'Kind', 'socialscore' ), self::class_labels() ) );
			$by_channel = self::fetch( $period, 'channel' );
			if ( ! is_wp_error( $by_channel ) ) {
				echo '<h3>' . esc_html__( 'By channel', 'socialscore' ) . '</h3>' . wp_kses_post( self::series_html( $by_channel, __( 'Channel', 'socialscore' ) ) );
			}
		}

		$state = Pixel::state();
		echo '<h2>' . esc_html__( 'Bad bots', 'socialscore' ) . '</h2>';
		echo wp_kses_post( self::server_html() );
		if ( ! empty( $state['enabled'] ) ) {
			echo '<h3>' . esc_html__( 'What to do with a bad bot', 'socialscore' ) . '</h3>';
			echo wp_kses( self::mode_form_html( $state['bot_mode'] ), self::form_tags() );
		} else {
			echo '<p>' . esc_html__( 'Switch the Pixel on in the SocialScore settings to choose what happens to a bad bot.', 'socialscore' ) . '</p>';
		}

		echo '<h2>' . esc_html__( 'Proxy or CDN in front of the shop', 'socialscore' ) . '</h2>';
		echo wp_kses_post( self::hints_html() );
		echo '<p class="description">' . esc_html__( 'Googlebot and Bingbot are checked by the address they come from. Behind a proxy that address is the proxy\'s, so the shop has to say which proxies it trusts. Until it does, a search engine that cannot be checked is never blocked.', 'socialscore' ) . '</p>';
		if ( current_user_can( 'manage_options' ) ) {
			echo wp_kses( self::proxy_form_html(), self::form_tags() );
		} else {
			echo '<p>' . esc_html__( 'Only an administrator can change the proxy settings.', 'socialscore' ) . '</p>';
		}

		echo '<h2>' . esc_html__( 'Warnings', 'socialscore' ) . '</h2>' . wp_kses_post( self::warnings_html() ) . '</div>';
	}

	/**
	 * Tags the mode form needs beyond what wp_kses_post allows.
	 *
	 * @return array
	 */
	private static function form_tags() {
		return array(
			'form'     => array(
				'method' => true,
				'action' => true,
			),
			'input'    => array(
				'type'    => true,
				'name'    => true,
				'value'   => true,
				'checked' => true,
			),
			'label'    => array(),
			'br'       => array(),
			'textarea' => array(
				'name' => true,
				'rows' => true,
				'cols' => true,
			),
			'p'        => array(),
			'button'   => array(
				'type'  => true,
				'class' => true,
			),
		);
	}
}
