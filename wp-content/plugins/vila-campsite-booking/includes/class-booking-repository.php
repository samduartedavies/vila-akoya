<?php
/**
 * Booking rows.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Bookings table access.
 */
class Booking_Repository {

	/**
	 * Statuses that occupy inventory.
	 *
	 * @return string[]
	 */
	public static function occupying_statuses() {
		return array( 'confirmed' );
	}

	/**
	 * @param int $id Booking ID.
	 * @return array<string, mixed>|null
	 */
	public function get( $id ) {
		global $wpdb;
		$t = Database::bookings_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<string, mixed> $data Data.
	 * @return int
	 */
	public function insert( array $data ) {
		global $wpdb;
		$t = Database::bookings_table();
		$wpdb->insert( $t, $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int                  $id Booking ID.
	 * @param array<string, mixed> $data Data.
	 * @return bool
	 */
	public function update( $id, array $data ) {
		global $wpdb;
		$t = Database::bookings_table();
		return false !== $wpdb->update( $t, $data, array( 'id' => $id ) );
	}

	/**
	 * @param int $order_id Order ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_by_order( $order_id ) {
		global $wpdb;
		$t = Database::bookings_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $t WHERE order_id = %d ORDER BY id ASC",
				$order_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete booking and its nights.
	 *
	 * @param int $id Booking ID.
	 * @return void
	 */
	public function delete_with_nights( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) {
			return;
		}
		$n = Database::booking_nights_table();
		$b = Database::bookings_table();
		$wpdb->delete( $n, array( 'booking_id' => $id ) );
		$wpdb->delete( $b, array( 'id' => $id ) );
	}

	/**
	 * For admin timeline: bookings overlapping a half-open window [from, to).
	 *
	 * @param string $from Y-m-d inclusive.
	 * @param string $to   Y-m-d exclusive end.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_in_range( $from, $to ) {
		global $wpdb;
		$b        = Database::bookings_table();
		$statuses = self::occupying_statuses();
		$in_place = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql      = "SELECT * FROM $b WHERE status IN ($in_place) AND check_in < %s AND check_out > %s ORDER BY check_in ASC";
		$args     = array_merge( $statuses, array( $to, $from ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders match args.
		$prepared = $wpdb->prepare( $sql, $args );
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Bookings that include the night of $ymd (check_in <= ymd < check_out).
	 *
	 * @param string $ymd Y-m-d.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_overlapping_night( $ymd ) {
		global $wpdb;
		$b        = Database::bookings_table();
		$statuses = self::occupying_statuses();
		$in_place = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql      = "SELECT * FROM $b WHERE status IN ($in_place) AND check_in <= %s AND check_out > %s ORDER BY check_in ASC";
		$args     = array_merge( $statuses, array( $ymd, $ymd ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared = $wpdb->prepare( $sql, $args );
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Bookings with “activity” on a calendar day: occupying a night, arriving, or departing.
	 *
	 * @param string $ymd Y-m-d.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_activity_for_day( $ymd ) {
		global $wpdb;
		$b        = Database::bookings_table();
		$statuses = self::occupying_statuses();
		$in_place = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql      = "SELECT * FROM $b WHERE status IN ($in_place) AND ( ( check_in <= %s AND check_out > %s ) OR check_out = %s ) ORDER BY check_in ASC";
		$args     = array_merge( $statuses, array( $ymd, $ymd, $ymd ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared = $wpdb->prepare( $sql, $args );
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}
