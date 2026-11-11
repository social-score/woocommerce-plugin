#!/bin/sh
# Browser check of the Pixel add-to-cart events on a classic theme (storefront) and a block theme (twentytwentyfive),
# inside the smoke stack. Run from the repo root after `npm run dist`; needs the Playwright image
# (mcr.microsoft.com/playwright:v1.63.0-noble) and network for WordPress.org and npm:
#   sh tests/smoke/e2e/run.sh
set -eu
C="docker compose -f tests/smoke/docker-compose.yml"
trap '$C --profile e2e down -v >/dev/null 2>&1' EXIT
$C up -d db mockapi wordpress
$C run --rm --entrypoint sh smoke /smoke/e2e/setup.sh
IDS=$($C run --rm --entrypoint sh smoke -c 'wp --path=/var/www/html eval "echo wp_json_encode( array( \"mug\" => (int) wc_get_products( array( \"name\" => \"E2E mug\", \"return\" => \"ids\" ) )[0], \"tee\" => (int) wc_get_products( array( \"name\" => \"E2E tee\", \"return\" => \"ids\" ) )[0], \"variations\" => ( wc_get_product( wc_get_products( array( \"name\" => \"E2E tee\", \"return\" => \"ids\" ) )[0] ) )->get_children() ) );"')
echo "ids: $IDS"
rc=0
for pair in classic:storefront block:twentytwentyfive; do
	label=${pair%%:*}; theme=${pair##*:}
	$C run --rm --entrypoint sh smoke -c "wp --path=/var/www/html option update woocommerce_coming_soon no && wp --path=/var/www/html theme activate $theme && wp --path=/var/www/html eval-file /smoke/e2e/mode.php $label"
	$C --profile e2e run --rm -e E2E_IDS="$IDS" e2e "$label" || rc=1
done
exit $rc
