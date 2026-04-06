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
