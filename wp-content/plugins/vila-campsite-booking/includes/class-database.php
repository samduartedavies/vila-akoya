<?php
/**
 * Table names.
 *
 * @package Vila_Campsite_Booking
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Database table helpers.
 */
class Database {

	/**
	 * @return string
	 */
	public static function plots_table() {
		global $wpdb;
		return $wpdb->prefix . 'vila_plots';
	}

	/**
	 * @return string
	 */
	public static function bookings_table() {
		global $wpdb;
		return $wpdb->prefix . 'vila_bookings';
	}

	/**
	 * @return string
	 */
	public static function booking_nights_table() {
		global $wpdb;
		return $wpdb->prefix . 'vila_booking_nights';
	}

	/**
	 * Maintenance / blocked ranges (stub).
	 *
	 * @return string
	 */
	public static function plot_blocks_table() {
		global $wpdb;
		return $wpdb->prefix . 'vila_plot_blocks';
	}
}
