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

	/**
	 * Village stay holds (website bookings, admin bookings, and date blocks).
	 *
	 * @return string
	 */
	public static function stay_holds_table() {
		global $wpdb;
		return $wpdb->prefix . 'vila_stay_holds';
	}

	/**
	 * Occupied nights for village stays. UNIQUE (stay_id, night_ymd).
	 *
	 * @return string
	 */
	public static function stay_hold_nights_table() {
		global $wpdb;
		return $wpdb->prefix . 'vila_stay_hold_nights';
	}

	/**
	 * @return string
	 */
	public static function guest_registrations_table() {
		global $wpdb;
		return $wpdb->prefix . 'vila_guest_registrations';
	}

	/**
	 * @return string
	 */
	public static function guest_persons_table() {
		global $wpdb;
		return $wpdb->prefix . 'vila_guest_persons';
	}
}
