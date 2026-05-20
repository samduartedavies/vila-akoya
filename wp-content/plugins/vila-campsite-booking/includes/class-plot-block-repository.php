<?php
/**
 * Date blocks (maintenance / closure) per plot or all plots.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for wp_vila_plot_blocks. plot_id = 0 means every plot.
 */
class Plot_Block_Repository {

	/** plot_id value: block applies to all pitches. */
	const PLOT_ID_ALL = 0;

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all() {
		global $wpdb;
		$t = Database::plot_blocks_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM $t ORDER BY start_ymd DESC, plot_id ASC, id DESC", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Blocks overlapping [from, to] inclusive (any night in the window may be blocked).
	 *
	 * @param string $from_ymd Y-m-d.
	 * @param string $to_ymd   Y-m-d inclusive.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_overlapping( $from_ymd, $to_ymd ) {
		global $wpdb;
		$from_ymd = trim( (string) $from_ymd );
		$to_ymd   = trim( (string) $to_ymd );
		if ( ! Dates::parse_ymd( $from_ymd ) || ! Dates::parse_ymd( $to_ymd ) ) {
			return array();
		}
		$t = Database::plot_blocks_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $t WHERE end_ymd > %s AND start_ymd <= %s ORDER BY start_ymd ASC, plot_id ASC, id ASC",
				$from_ymd,
				$to_ymd
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param int $id Block ID.
	 * @return array<string, mixed>|null
	 */
	public function get( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) {
			return null;
		}
		$t   = Database::plot_blocks_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<string, mixed> $data Row data.
	 * @return int Insert ID or 0.
	 */
	public function insert( array $data ) {
		global $wpdb;
		$t = Database::plot_blocks_table();
		$ok = $wpdb->insert( $t, $data );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param int $id Block ID.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) {
			return false;
		}
		$t = Database::plot_blocks_table();
		return false !== $wpdb->delete( $t, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Validate and normalize a half-open block range.
	 *
	 * @param string $start_ymd First blocked night (inclusive).
	 * @param string $end_ymd   First unblocked night (exclusive), same as checkout date.
	 * @return array{start: string, end: string}|null
	 */
	public static function normalize_range( $start_ymd, $end_ymd ) {
		$start_ymd = trim( (string) $start_ymd );
		$end_ymd   = trim( (string) $end_ymd );
		if ( ! Dates::parse_ymd( $start_ymd ) || ! Dates::parse_ymd( $end_ymd ) ) {
			return null;
		}
		if ( ! Dates::nights_in_range( $start_ymd, $end_ymd ) ) {
			return null;
		}
		return array(
			'start' => $start_ymd,
			'end'   => $end_ymd,
		);
	}
}
