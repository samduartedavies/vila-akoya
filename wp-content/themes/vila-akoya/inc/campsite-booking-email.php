<?php
/**
 * Customer email: campsite booking confirmation with order and stay details.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the order contains at least one campsite stay line.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function vila_akoya_order_has_campsite_booking( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return false;
	}
	foreach ( $order->get_items() as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		$pid = (int) $item->get_product_id();
		if ( $pid <= 0 ) {
			continue;
		}
		if ( class_exists( 'Vila_CB\WooCommerce_Integration' ) && Vila_CB\WooCommerce_Integration::is_campsite_product( $pid ) ) {
			return true;
		}
		if ( function_exists( 'vila_akoya_is_campsite_product_id' ) && vila_akoya_is_campsite_product_id( $pid ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Register campsite booking confirmation email with WooCommerce.
 *
 * @param array<string, WC_Email> $emails Email classes.
 * @return array<string, WC_Email>
 */
function vila_akoya_register_campsite_booking_email( $emails ) {
	if ( ! class_exists( 'WC_Email', false ) ) {
		return $emails;
	}
	require_once VILA_AKOYA_DIR . '/inc/class-wc-email-campsite-booking.php';
	$emails['vila_campsite_booking'] = new Vila_Akoya_Email_Campsite_Booking();
	return $emails;
}
add_filter( 'woocommerce_email_classes', 'vila_akoya_register_campsite_booking_email' );
