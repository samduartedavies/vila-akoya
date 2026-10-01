<?php
/**
 * Guest registration reminder (plain text).
 *
 * @package Vila_Campsite_Booking
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 * @var string   $reminder_type
 * @var string   $stay_check_in
 * @var string   $stay_check_out
 * @var string   $checkin_url
 */

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

$first = trim( (string) $order->get_billing_first_name() );
$fmt   = function_exists( 'wc_date_format' ) ? wc_date_format() : get_option( 'date_format' );
$in_lbl = $stay_check_in ? date_i18n( $fmt, strtotime( $stay_check_in . ' 12:00:00' ) ) : '';
$out_lbl = $stay_check_out ? date_i18n( $fmt, strtotime( $stay_check_out . ' 12:00:00' ) ) : '';

if ( '' !== $first ) {
	printf( esc_html__( 'Hi %s,', 'vila-campsite-booking' ), esc_html( $first ) );
} else {
	esc_html_e( 'Hi there,', 'vila-campsite-booking' );
}
echo "\n\n";

if ( 'pre' === $reminder_type ) {
	if ( $in_lbl && $out_lbl ) {
		printf(
			esc_html__( 'Your stay at Vila Akoya starts on %1$s (check-out %2$s). We still need your legal guest check-in details.', 'vila-campsite-booking' ),
			esc_html( $in_lbl ),
			esc_html( $out_lbl )
		);
	} else {
		esc_html_e( 'Your stay at Vila Akoya is coming up soon. We still need your legal guest check-in details.', 'vila-campsite-booking' );
	}
} else {
	esc_html_e( 'Thank you for staying with us at Vila Akoya. Portuguese law requires guest identity details for your visit — we do not have yours on file yet.', 'vila-campsite-booking' );
}
echo "\n\n";

if ( '' !== $checkin_url ) {
	esc_html_e( 'COMPLETE GUEST CHECK-IN', 'vila-campsite-booking' );
	echo "\n";
	esc_html_e( 'Open this link to find your booking and fill in the form for each guest:', 'vila-campsite-booking' );
	echo "\n";
	echo esc_url( $checkin_url ) . "\n\n";
}

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
