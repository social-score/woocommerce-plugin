#!/bin/sh
# Runs inside wordpress:cli. Installs WordPress, WooCommerce, a classic and a block theme, connects the mock store,
# and makes the products the browser check adds to the cart. Safe to run again.
set -eu
WP="wp --path=/var/www/html"
i=0; until [ -f /var/www/html/wp-config.php ]; do i=$((i+1)); [ $i -gt 90 ] && { echo "no wp-config"; exit 1; }; sleep 2; done
i=0; until php -r 'mysqli_report(MYSQLI_REPORT_OFF); $m = new mysqli("db", "wp", "wp", "wp"); exit($m->connect_errno ? 1 : 0);' >/dev/null 2>&1; do i=$((i+1)); [ $i -gt 60 ] && { echo "no db"; exit 1; }; sleep 2; done
if ! $WP core is-installed; then
	$WP core install --url=http://wordpress --title=E2E --admin_user=admin --admin_password=admin --admin_email=a@example.test --skip-email
	$WP plugin install woocommerce --activate
	$WP theme install storefront
	$WP theme install twentytwentyfive || true
	$WP option update woocommerce_custom_orders_table_enabled yes
	$WP plugin activate socialscore
	$WP rewrite structure "/%postname%/" --hard
	$WP eval-file /smoke/e2e/products.php
fi
$WP plugin get woocommerce --field=version
$WP theme list --fields=name,status
