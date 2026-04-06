<?php
/**
 * Uninstall: optional table removal (orders are WooCommerce data and are never deleted here).
 *
 * @package Vila_Campsite_Booking
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$remove_tables = apply_filters( 'vila_cb_uninstall_remove_tables', false );

if ( $remove_tables ) {
	global $wpdb;
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'vila_booking_nights' );
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'vila_bookings' );
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'vila_plot_blocks' );
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'vila_plots' );
}

delete_option( 'vila_cb_db_version' );
delete_option( 'vila_cb_product_id' );
delete_option( 'vila_cb_plot_count' );
