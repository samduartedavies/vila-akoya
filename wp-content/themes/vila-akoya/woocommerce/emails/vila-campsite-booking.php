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

$first      = trim( (string) $order->get_billing_first_name() );
$checkin_url = function_exists( 'vila_akoya_guest_registration_url' ) ? vila_akoya_guest_registration_url() : '';
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

<?php if ( '' !== $checkin_url ) : ?>
<div style="margin: 28px 0; padding: 24px; background: #f7f1e6; border: 1px solid #e5d8c0; border-radius: 8px;">
	<p style="margin: 0 0 10px; font-size: 16px; line-height: 1.45; color: #332e1e;">
		<strong><?php esc_html_e( 'Please complete guest check-in', 'vila-akoya' ); ?></strong>
	</p>
	<p style="margin: 0 0 20px; font-size: 15px; line-height: 1.5; color: #5c5344;">
		<?php esc_html_e( 'Portuguese law requires us to collect guest identity details before or at check-in. Use the button below to find your booking and fill in the form for each guest.', 'vila-akoya' ); ?>
	</p>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin: 0 auto;">
		<tr>
			<td align="center" bgcolor="#ba5737" style="border-radius: 6px; background-color: #ba5737;">
				<a href="<?php echo esc_url( $checkin_url ); ?>" target="_blank" rel="noopener noreferrer" style="display: inline-block; padding: 16px 32px; font-size: 17px; font-weight: 700; line-height: 1.2; color: #ffffff; text-decoration: none; border-radius: 6px; background-color: #ba5737;">
					<?php esc_html_e( 'Complete guest check-in', 'vila-akoya' ); ?>
				</a>
			</td>
		</tr>
	</table>
	<p style="margin: 16px 0 0; font-size: 13px; line-height: 1.4; color: #7a7163; text-align: center;">
		<a href="<?php echo esc_url( $checkin_url ); ?>" style="color: #8f3a1d; word-break: break-all;"><?php echo esc_html( $checkin_url ); ?></a>
	</p>
</div>
<?php endif; ?>

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
