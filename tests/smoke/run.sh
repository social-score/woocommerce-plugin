#!/bin/sh
# Runs inside wordpress:cli. Exit code 0 only when every step passes and the PHP log is clean.
set -eu
WP="wp --path=/var/www/html"
fail() { echo "SMOKE FAIL: $1"; exit 1; }

echo "== wait for WordPress files and database"
i=0; until [ -f /var/www/html/wp-config.php ]; do i=$((i+1)); [ $i -gt 90 ] && fail "wp-config.php never appeared"; sleep 2; done
i=0; until php -r 'mysqli_report(MYSQLI_REPORT_OFF); $m = new mysqli("db", "wp", "wp", "wp"); exit($m->connect_errno ? 1 : 0);' >/dev/null 2>&1; do i=$((i+1)); [ $i -gt 60 ] && fail "database not reachable"; sleep 2; done

echo "== install WordPress and WooCommerce"
$WP core install --url=http://wordpress --title=Smoke --admin_user=admin --admin_password=admin --admin_email=a@example.test --skip-email
$WP plugin install woocommerce ${WC_VERSION:+--version=$WC_VERSION} --activate
$WP --version; $WP plugin get woocommerce --field=version
$WP option update woocommerce_custom_orders_table_enabled yes
$WP option update woocommerce_custom_orders_table_data_sync_enabled no

echo "== activate SocialScore"
: > /shared/debug.log
$WP plugin activate socialscore
$WP plugin list --status=active --fields=name,version

echo "== HPOS declared compatible"
$WP eval '$c = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( "custom_order_tables", true );
echo json_encode( $c ), "\n";
if ( ! in_array( "socialscore/socialscore.php", $c["compatible"], true ) ) { exit( 1 ); }' || fail "plugin not listed as HPOS compatible"

echo "== functional flow against the mock API"
$WP eval-file /smoke/flow.php || fail "flow.php failed"
$WP rewrite structure "/%postname%/" --hard
$WP eval-file /smoke/http.php || fail "http.php failed"
echo "== Pixel: loader, events, order link"
$WP eval-file /smoke/pixel.php || fail "pixel.php failed"
echo "== Bots: server-side detection, the order flag, the modes, the reports page"
$WP eval-file /smoke/bots.php || fail "bots.php failed"
$WP eval-file /smoke/flow-end.php || fail "flow-end.php failed"

echo "== deactivate unschedules, uninstall removes data"
$WP eval 'SocialScore\Connection::save( "st_2", "sk_x" ); as_enqueue_async_action( "socialscore_sync_order", array( 1, 0 ), "socialscore" ); update_option( "socialscore_sync_state", array( "status" => "done" ), false ); update_option( "socialscore_connection_lost", time(), false );'
ORDER_ID=$($WP eval '$o = wc_create_order(); $o->update_meta_data( "_socialscore_labels", array( "x" ) ); $o->update_meta_data( "_socialscore_checked", 1 ); $o->update_meta_data( "_socialscore_order_id", 9001 ); $o->update_meta_data( "_ss_pixel_visitor", "3f2b8c1e-5d4a-4b7e-9c3d-1a2b3c4d5e6f" ); $o->update_meta_data( "_ss_pixel_session", "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d" ); $o->update_meta_data( "_ss_pixel_purchase_sent", "1" ); $o->update_meta_data( "_ss_checkout_ref", "3f2b8c1e-5d4a-4b7e-9c3d-1a2b3c4d5e6f" ); $o->update_meta_data( "_socialscore_not_human", "bad_bot" ); $o->update_meta_data( "_socialscore_not_human_signals", array( "http_client_ua" ) ); $o->update_meta_data( "_socialscore_not_human_source", "server" ); $o->update_meta_data( "_socialscore_not_human_noted", "bad_bot" ); $o->update_meta_data( "_socialscore_visit_class", "bad_bot" ); $o->save(); echo $o->get_id();')
$WP eval 'set_transient( "socialscore_pixel_" . md5( "st_2" ), array( "enabled" => true, "site_id" => "ps_abcdefghijklmnop" ), 600 );'
$WP eval 'set_transient( "socialscore_pkce_" . hash( "sha256", "x" ), array( "verifier" => "y" ), 600 );'
$WP plugin deactivate socialscore
$WP eval 'if ( function_exists( "as_get_scheduled_actions" ) && as_get_scheduled_actions( array( "group" => "socialscore", "status" => ActionScheduler_Store::STATUS_PENDING ), "ids" ) ) { exit( 1 ); } echo "no pending actions\n";' || fail "deactivation left scheduled actions"
$WP plugin uninstall socialscore --skip-delete
$WP eval 'if ( false !== get_option( "socialscore_connection" ) || false !== get_option( "socialscore_sync_state" ) || false !== get_option( "socialscore_connection_lost" ) ) { exit( 1 ); } echo "options removed\n";' || fail "uninstall left options"
$WP eval 'global $wpdb; if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE \"%socialscore\\_pkce\\_%\"" ) > 0 ) { exit( 1 ); } echo "connect transients removed\n";' || fail "uninstall left connect transients"
$WP eval '$o = wc_get_order( '"$ORDER_ID"' ); if ( $o->get_meta( "_socialscore_labels" ) || $o->get_meta( "_socialscore_checked" ) || $o->get_meta( "_socialscore_order_id" ) || $o->get_meta( "_ss_pixel_visitor" ) || $o->get_meta( "_ss_pixel_session" ) || $o->get_meta( "_ss_pixel_purchase_sent" ) || $o->get_meta( "_ss_checkout_ref" ) || $o->get_meta( "_socialscore_not_human" ) || $o->get_meta( "_socialscore_not_human_signals" ) || $o->get_meta( "_socialscore_not_human_source" ) || $o->get_meta( "_socialscore_not_human_noted" ) || $o->get_meta( "_socialscore_visit_class" ) ) { exit( 1 ); } echo "order meta removed\n";' || fail "uninstall left order meta"
$WP eval 'if ( false !== get_option( "socialscore_bot_hits" ) || false !== get_option( "socialscore_bot_ranges" ) || false !== get_option( "socialscore_bot_settings" ) || false !== get_option( "socialscore_proxy_hints" ) || false !== wp_next_scheduled( "socialscore_refresh_bot_ranges" ) ) { exit( 1 ); } echo "bot counts, ranges and the daily job removed\n";' || fail "uninstall left the bot counts"
$WP eval 'global $wpdb; if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE \"%socialscore\_pixel\_%\"" ) > 0 ) { exit( 1 ); } echo "Pixel state transient removed\n";' || fail "uninstall left the Pixel state transient"

echo "== Plugin Check (WordPress.org checks) on the release layout"
$WP plugin install plugin-check --version=2.2.0 --activate >/dev/null 2>&1 || fail "could not install plugin-check"
$WP plugin check socialscore --format=table > /tmp/plugin-check.txt 2>&1 || true
grep -v '^Deprecated' /tmp/plugin-check.txt || true
if grep -q "ERROR" /tmp/plugin-check.txt; then fail "Plugin Check reported errors"; fi

echo "== PHP log must be empty of notices, warnings, deprecations, fatals"
# WP-CLI's own phar (Symfony Finder, run by `plugin check`) prints deprecations on newer PHP: not this plugin's.
if grep -E "PHP (Notice|Warning|Deprecated|Fatal|Parse)" /shared/debug.log | grep -v "phar:///usr/local/bin/wp/"; then fail "PHP problems in debug.log"; fi
echo "debug.log lines: $(wc -l < /shared/debug.log)"
echo "SMOKE OK"
