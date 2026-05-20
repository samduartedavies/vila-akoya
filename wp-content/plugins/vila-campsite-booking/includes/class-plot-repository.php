<?php
/**
 * Plot CRUD.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Plots data access.
 */
class Plot_Repository {

	/**
	 * Plot numbers (from labels like "Plot 17") hidden on the public booking form and auto-assign pool.
	 *
	 * @return int[]
	 */
	public static function hidden_booking_plot_numbers() {
		$numbers = array( 17, 19, 20, 25, 27 );
		/**
		 * Plot numbers to hide from the campsite booking form and exclude from guest auto-assign.
		 *
		 * @param int[] $numbers Plot numbers parsed from plot labels (e.g. 17 for "Plot 17").
		 */
		$numbers = apply_filters( 'vila_cb_hidden_booking_plot_numbers', $numbers );
		$numbers = apply_filters( 'vila_akoya_hidden_booking_plot_numbers', $numbers );
		$out     = array();
		foreach ( (array) $numbers as $n ) {
			$n = (int) $n;
			if ( $n > 0 ) {
				$out[] = $n;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param array<string, mixed> $plot Plot row from get_all().
	 * @return int Plot number from label, or 0 if unknown.
	 */
	public static function plot_number_from_row( array $plot ) {
		$label = isset( $plot['label'] ) ? (string) $plot['label'] : '';
		if ( preg_match( '/\b(\d+)\s*$/', $label, $m ) ) {
			return (int) $m[1];
		}
		if ( preg_match( '/plot\s*#?\s*(\d+)/i', $label, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	/**
	 * @param array<string, mixed> $plot Plot row.
	 * @return bool
	 */
	public static function is_hidden_from_booking_form( array $plot ) {
		$num = self::plot_number_from_row( $plot );
		if ( $num <= 0 ) {
			return false;
		}
		return in_array( $num, self::hidden_booking_plot_numbers(), true );
	}

	/**
	 * Plots guests may pick or receive via auto-assign (admin calendar still lists all plots).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_bookable() {
		return array_values(
			array_filter(
				$this->get_all(),
				static function ( $plot ) {
					return is_array( $plot ) && ! self::is_hidden_from_booking_form( $plot );
				}
			)
		);
	}

	/**
	 * @return int[]
	 */
	public function get_bookable_ids() {
		$ids = array();
		foreach ( $this->get_bookable() as $plot ) {
			$ids[] = (int) $plot['id'];
		}
		return $ids;
	}

	/**
	 * @return int
	 */
	public function count_bookable() {
		return count( $this->get_bookable() );
	}

	/**
	 * @param int $plot_id Plot database ID.
	 * @return bool
	 */
	public function is_bookable_plot_id( $plot_id ) {
		$plot_id = (int) $plot_id;
		if ( $plot_id <= 0 ) {
			return false;
		}
		$row = $this->get( $plot_id );
		return is_array( $row ) && ! self::is_hidden_from_booking_form( $row );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all() {
		global $wpdb;
		$t = Database::plots_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
		$rows = $wpdb->get_results( "SELECT * FROM $t ORDER BY sort_order ASC, id ASC", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param int $id Plot ID.
	 * @return array<string, mixed>|null
	 */
	public function get( $id ) {
		global $wpdb;
		$t = Database::plots_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<string, mixed> $data Data.
	 * @return int Insert ID.
	 */
	public function insert( array $data ) {
		global $wpdb;
		$t = Database::plots_table();
		$wpdb->insert( $t, $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int                  $id Plot ID.
	 * @param array<string, mixed> $data Data.
	 * @return bool
	 */
	public function update( $id, array $data ) {
		global $wpdb;
		$t = Database::plots_table();
		return false !== $wpdb->update( $t, $data, array( 'id' => $id ) );
	}

	/**
	 * @param int $id Plot ID.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;
		$t = Database::plots_table();
		return false !== $wpdb->delete( $t, array( 'id' => $id ) );
	}

	/**
	 * Count plots.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;
		$t = Database::plots_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" );
	}
}
