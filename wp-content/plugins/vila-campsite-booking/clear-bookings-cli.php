<?php
/**
 * Remove campsite schedule rows and WooCommerce booking orders.
 *
 * Deletes:
 * - wp_vila_bookings / wp_vila_booking_nights (campsite calendar inventory)
 * - WC orders tagged as MWB booking orders or containing campsite / mwb_booking lines
 *
 * Set VILA_CB_CLEAR_DRY_RUN=1 to list counts without deleting.
 *
 * Usage (wp-env): npm run wp:clear-bookings
 * Production:    wp eval-file wp-content/plugins/vila-campsite-booking/clear-bookings-cli.php
 *
 * @package Vila_CB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

if ( ! function_exists( 'wc_get_orders' ) ) {
	echo "WooCommerce not loaded.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

/**
 * Whether a WooCommerce order should be removed by this cleanup.
 *
 * @param \WC_Order $order Order.
 * @return bool
 */
function vila_cb_clear_order_is_booking( $order ) {
	if ( ! $order instanceof \WC_Order ) {
		return false;
	}

	if ( 'booking' === (string) $order->get_meta( 'mwb_order_type', true ) ) {
		return true;
	}

	$line_meta_keys = array(
		'_vila_cb_check_in',
		'_vila_cb_check_out',
		'_mwb_bfwp_date_time_from',
		'_wps_mbfw_booking_date_from',
		'_mwb_mbfw_date_time_from',
		'From',
	);

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		if ( ! is_object( $item ) || ! is_callable( array( $item, 'get_product_id' ) ) ) {
			continue;
		}

		$pid = (int) $item->get_product_id();
		if ( $pid > 0 && \Vila_CB\WooCommerce_Integration::is_campsite_product( $pid ) ) {
			return true;
		}

		if ( $pid > 0 && function_exists( 'vila_akoya_is_campsite_product_id' ) && vila_akoya_is_campsite_product_id( $pid ) ) {
			return true;
		}

		$product = $pid > 0 ? wc_get_product( $pid ) : null;
		if ( $product && $product->is_type( 'mwb_booking' ) ) {
			return true;
		}

		foreach ( $line_meta_keys as $key ) {
			if ( '' !== trim( (string) $item->get_meta( $key, true ) ) ) {
				return true;
			}
		}
	}

	return false;
}

$dry_run = '1' === getenv( 'VILA_CB_CLEAR_DRY_RUN' );

global $wpdb;

$nights_table   = \Vila_CB\Database::booking_nights_table();
$bookings_table = \Vila_CB\Database::bookings_table();

$nights_before   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $nights_table" );
$bookings_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $bookings_table" );

$order_ids = wc_get_orders(
	array(
		'limit'  => -1,
		'return' => 'ids',
		'status' => 'any',
	)
);

$booking_order_ids = array();
foreach ( $order_ids as $order_id ) {
	$order = wc_get_order( $order_id );
	if ( $order && vila_cb_clear_order_is_booking( $order ) ) {
		$booking_order_ids[] = (int) $order_id;
	}
}

if ( $dry_run ) {
	echo sprintf(
		"DRY RUN: would remove %d schedule booking(s), %d night row(s), and %d WooCommerce order(s).\n",
		$bookings_before,
		$nights_before,
		count( $booking_order_ids )
	); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 0 );
}

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from plugin helper.
$wpdb->query( "DELETE FROM $nights_table" );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DELETE FROM $bookings_table" );

$deleted_orders = 0;
foreach ( $booking_order_ids as $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		continue;
	}
	$order->delete( true );
	++$deleted_orders;
}

echo sprintf(
	"Removed %d schedule booking(s), %d night row(s), and %d WooCommerce booking order(s).\n",
	$bookings_before,
	$nights_before,
	$deleted_orders
); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
