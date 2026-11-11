<?php
// Switch the cart and checkout pages between the block content WooCommerce installs and the classic shortcodes.
// Usage: wp eval-file mode.php classic|block
$mode  = $args[0] ?? 'block';
$pages = array(
	'cart'     => '[woocommerce_cart]',
	'checkout' => '[woocommerce_checkout]',
);
foreach ( $pages as $slug => $shortcode ) {
	$id  = wc_get_page_id( $slug );
	$opt = 'e2e_blocks_' . $slug;
	if ( false === get_option( $opt ) && false !== strpos( (string) get_post( $id )->post_content, 'wp:woocommerce/' ) ) {
		update_option( $opt, get_post( $id )->post_content, false );
	}
	$content = 'classic' === $mode ? $shortcode : get_option( $opt );
	wp_update_post( array( 'ID' => $id, 'post_content' => $content ) );
}
echo "pages: $mode\n";
