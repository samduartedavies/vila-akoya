<?php
/**
 * Guest registration reminder (HTML).
 *
 * @package Vila_Campsite_Booking
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 * @var string   $reminder_type pre|post
 * @var string   $stay_check_in Y-m-d
 * @var string   $stay_check_out Y-m-d
 * @var string   $checkin_url
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

$first = trim( (string) $order->get_billing_first_name() );
$fmt   = function_exists( 'wc_date_format' ) ? wc_date_format() : get_option( 'date_format' );
$in_lbl = $stay_check_in ? date_i18n( $fmt, strtotime( $stay_check_in . ' 12:00:00' ) ) : '';
$out_lbl = $stay_check_out ? date_i18n( $fmt, strtotime( $stay_check_out . ' 12:00:00' ) ) : '';
?>
<p>
	<?php
	if ( '' !== $first ) {
		printf( esc_html__( 'Hi %s,', 'vila-campsite-booking' ), esc_html( $first ) );
	} else {
		esc_html_e( 'Hi there,', 'vila-campsite-booking' );
	}
	?>
</p>
<?php if ( 'pre' === $reminder_type ) : ?>
	<p>
		<?php
		if ( $in_lbl && $out_lbl ) {
			printf(
				/* translators: 1: check-in date, 2: check-out date */
				esc_html__( 'Your stay at Vila Akoya starts on %1$s (check-out %2$s). We still need your legal guest check-in details.', 'vila-campsite-booking' ),
				esc_html( $in_lbl ),
				esc_html( $out_lbl )
			);
		} else {
			esc_html_e( 'Your stay at Vila Akoya is coming up soon. We still need your legal guest check-in details.', 'vila-campsite-booking' );
		}
		?>
	</p>
<?php else : ?>
	<p><?php esc_html_e( 'Thank you for staying with us at Vila Akoya. Portuguese law requires guest identity details for your visit — we do not have yours on file yet.', 'vila-campsite-booking' ); ?></p>
<?php endif; ?>

<?php if ( '' !== $checkin_url ) : ?>
<div style="margin: 28px 0; padding: 24px; background: #f7f1e6; border: 1px solid #e5d8c0; border-radius: 8px;">
	<p style="margin: 0 0 10px; font-size: 16px; line-height: 1.45; color: #332e1e;">
		<strong><?php esc_html_e( 'Complete guest check-in', 'vila-campsite-booking' ); ?></strong>
	</p>
	<p style="margin: 0 0 20px; font-size: 15px; line-height: 1.5; color: #5c5344;">
		<?php esc_html_e( 'Use the button below to find your booking and fill in the form for each guest.', 'vila-campsite-booking' ); ?>
	</p>
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin: 0 auto;">
		<tr>
			<td align="center" bgcolor="#ba5737" style="border-radius: 6px; background-color: #ba5737;">
				<a href="<?php echo esc_url( $checkin_url ); ?>" target="_blank" rel="noopener noreferrer" style="display: inline-block; padding: 16px 32px; font-size: 17px; font-weight: 700; line-height: 1.2; color: #ffffff; text-decoration: none; border-radius: 6px; background-color: #ba5737;">
					<?php esc_html_e( 'Complete guest check-in', 'vila-campsite-booking' ); ?>
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
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
