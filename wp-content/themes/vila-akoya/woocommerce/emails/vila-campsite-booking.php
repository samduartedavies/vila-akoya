<?php
/**
 * Campsite booking confirmation email (HTML).
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

do_action( 'woocommerce_email_header', $email_heading, $email );

$first = trim( (string) $order->get_billing_first_name() );
?>
<p>
	<?php
	if ( '' !== $first ) {
		/* translators: %s: customer first name */
		printf( esc_html__( 'Hi %s,', 'vila-akoya' ), esc_html( $first ) );
	} else {
		esc_html_e( 'Hi there,', 'vila-akoya' );
	}
	?>
</p>
<p><?php esc_html_e( 'Thank you for booking a stay at Vila Akoya. Here are your booking details:', 'vila-akoya' ); ?></p>
<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
?>
<p><?php esc_html_e( 'If you have any questions, reply to this email or contact us at info@vila-akoya.com.', 'vila-akoya' ); ?></p>
<?php
do_action( 'woocommerce_email_footer', $email );
