<?php
// Connect the mock store (Pixel on) and make one simple and one variable product.
\SocialScore\Connection::save( 'st_1', 'sk_mock_secret' );
\SocialScore\Pixel::forget();
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_cart_redirect_after_add', 'no' );
update_option( 'woocommerce_enable_ajax_add_to_cart', 'yes' );

$mug = new WC_Product_Simple();
$mug->set_name( 'E2E mug' );
$mug->set_regular_price( '12.50' );
$mug->set_status( 'publish' );
$mug->save();

$tee = new WC_Product_Variable();
$tee->set_name( 'E2E tee' );
$tee->set_status( 'publish' );
$attr = new WC_Product_Attribute();
$attr->set_name( 'Size' );
$attr->set_options( array( 'S', 'M' ) );
$attr->set_visible( true );
$attr->set_variation( true );
$tee->set_attributes( array( $attr ) );
$tee->save();
foreach ( array( 'S' => '20.00', 'M' => '22.00' ) as $size => $price ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $tee->get_id() );
	$v->set_attributes( array( 'size' => $size ) );
	$v->set_regular_price( $price );
	$v->set_status( 'publish' );
	$v->save();
}
WC_Product_Variable::sync( $tee->get_id() );
echo wp_json_encode( array( 'mug' => $mug->get_id(), 'tee' => $tee->get_id(), 'variations' => $tee->get_children() ) ), "\n";
