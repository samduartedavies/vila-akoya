<?php
/**
 * Campsite booking confirmation email (plain text).
 *
 * @package Vila_Akoya
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

$first = trim( (string) $order->get_billing_first_name() );
if ( '' !== $first ) {
	/* translators: %s: customer first name */
	echo sprintf( esc_html__( 'Hi %s,', 'vila-akoya' ), esc_html( $first ) ) . "\n\n";
} else {
	echo esc_html__( 'Hi there,', 'vila-akoya' ) . "\n\n";
}

echo esc_html__( 'Thank you for booking a stay at Vila Akoya. Here are your booking details:', 'vila-akoya' ) . "\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo "\n" . esc_html( wp_strip_all_tags( $additional_content ) ) . "\n";
}

echo "\n" . esc_html__( 'If you have any questions, reply to this email or contact us at info@vila-akoya.com.', 'vila-akoya' ) . "\n\n";

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
