<?php
/**
 * Village stay occupancy (website bookings, admin bookings, date blocks).
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for wp_vila_stay_holds and night rows.
 */
class Stay_Hold_Repository {

	const STATUS_CONFIRMED = 'confirmed';
	const STATUS_CANCELLED = 'cancelled';
	const KIND_BOOKING     = 'booking';
	const KIND_BLOCK       = 'block';

	/**
	 * @param int $id Hold ID.
	 * @return array<string, mixed>|null
	 */
	public function get( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id < 1 ) {
			return null;
		}
		$t   = Database::stay_holds_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Confirmed holds overlapping [from, to] inclusive.
	 *
	 * @param string $from_ymd Y-m-d.
	 * @param string $to_ymd   Y-m-d inclusive.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_in_range( $from_ymd, $to_ymd ) {
		global $wpdb;
		$from_ymd = trim( (string) $from_ymd );
		$to_ymd   = trim( (string) $to_ymd );
		if ( ! Dates::parse_ymd( $from_ymd ) || ! Dates::parse_ymd( $to_ymd ) ) {
			return array();
		}
		$t    = Database::stay_holds_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $t WHERE status = %s AND check_out > %s AND check_in <= %s ORDER BY check_in ASC, stay_id ASC, id ASC",
				self::STATUS_CONFIRMED,
				$from_ymd,
				$to_ymd
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Confirmed holds with an occupied night in [from, to] inclusive.
	 * Uses the nights table so holds with invalid DATE columns still appear.
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
		$h    = Database::stay_holds_table();
		$n    = Database::stay_hold_nights_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT h.* FROM $h h
				LEFT JOIN $n n ON n.hold_id = h.id
				WHERE h.status = %s
				AND (
					(h.check_out > %s AND h.check_in <= %s AND h.check_in <> %s AND h.check_out <> %s)
					OR (n.night_ymd >= %s AND n.night_ymd <= %s)
				)
				ORDER BY h.check_in ASC, h.stay_id ASC, h.id ASC",
				self::STATUS_CONFIRMED,
				$from_ymd,
				$to_ymd,
				'0000-00-00',
				'0000-00-00',
				$from_ymd,
				$to_ymd
			),
			ARRAY_A
		);
		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$out[ (int) $row['id'] ] = $row;
			}
		}
		foreach ( $this->get_in_range( $from_ymd, $to_ymd ) as $row ) {
			$out[ (int) $row['id'] ] = $row;
		}
		return array_values( $out );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all_confirmed() {
		global $wpdb;
		$t    = Database::stay_holds_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $t WHERE status = %s ORDER BY check_in DESC, stay_id ASC, id DESC",
				self::STATUS_CONFIRMED
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param int $order_id Order ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_by_order_id( $order_id ) {
		global $wpdb;
		$order_id = (int) $order_id;
		if ( $order_id < 1 ) {
			return array();
		}
		$t    = Database::stay_holds_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $t WHERE order_id = %d", $order_id ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param int $stay_id Stay CPT ID.
	 * @return string[] Occupied Y-m-d nights.
	 */
	public function occupied_nights_for_stay( $stay_id ) {
		global $wpdb;
		$stay_id = (int) $stay_id;
		if ( $stay_id < 1 ) {
			return array();
		}
		$n = Database::stay_hold_nights_table();
		$h = Database::stay_holds_table();
		$col = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT n.night_ymd FROM $n n INNER JOIN $h h ON h.id = n.hold_id WHERE n.stay_id = %d AND h.status = %s ORDER BY n.night_ymd ASC",
				$stay_id,
				self::STATUS_CONFIRMED
			)
		);
		return is_array( $col ) ? array_values( array_unique( array_map( 'strval', $col ) ) ) : array();
	}

	/**
	 * @param array<string, mixed> $data Hold row.
	 * @return int Insert ID or 0.
	 */
	public function insert_hold( array $data ) {
		global $wpdb;
		$ok = $wpdb->insert( Database::stay_holds_table(), $data );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param int                  $id   Hold ID.
	 * @param array<string, mixed> $data Fields.
	 * @return bool
	 */
	public function update_hold( $id, array $data ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id < 1 ) {
			return false;
		}
		return false !== $wpdb->update( Database::stay_holds_table(), $data, array( 'id' => $id ) );
	}

	/**
	 * @param int      $hold_id Hold ID.
	 * @param int      $stay_id Stay ID.
	 * @param string[] $nights  Y-m-d nights.
	 * @return bool
	 */
	public function replace_nights( $hold_id, $stay_id, array $nights ) {
		global $wpdb;
		$hold_id = (int) $hold_id;
		$stay_id = (int) $stay_id;
		if ( $hold_id < 1 || $stay_id < 1 ) {
			return false;
		}
		$t = Database::stay_hold_nights_table();
		$wpdb->delete( $t, array( 'hold_id' => $hold_id ), array( '%d' ) );
		foreach ( $nights as $ymd ) {
			$ymd = (string) $ymd;
			if ( ! Dates::parse_ymd( $ymd ) ) {
				continue;
			}
			$ok = $wpdb->insert(
				$t,
				array(
					'hold_id'   => $hold_id,
					'stay_id'   => $stay_id,
					'night_ymd' => $ymd,
				)
			);
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param int $hold_id Hold ID.
	 * @return void
	 */
	public function delete_nights( $hold_id ) {
		global $wpdb;
		$hold_id = (int) $hold_id;
		if ( $hold_id < 1 ) {
			return;
		}
		$wpdb->delete( Database::stay_hold_nights_table(), array( 'hold_id' => $hold_id ), array( '%d' ) );
	}

	/**
	 * Occupied nights keyed by hold ID.
	 *
	 * @param int[] $hold_ids Hold IDs.
	 * @return array<int, string[]>
	 */
	public function nights_by_hold_ids( array $hold_ids ) {
		global $wpdb;
		$hold_ids = array_values(
			array_filter(
				array_map(
					static function ( $id ) {
						return (int) $id;
					},
					$hold_ids
				)
			)
		);
		if ( empty( $hold_ids ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $hold_ids ), '%d' ) );
		$n            = Database::stay_hold_nights_table();
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT hold_id, night_ymd FROM $n WHERE hold_id IN ($placeholders) ORDER BY hold_id ASC, night_ymd ASC",
				$hold_ids
			),
			ARRAY_A
		);
		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$hid = (int) $row['hold_id'];
				if ( ! isset( $out[ $hid ] ) ) {
					$out[ $hid ] = array();
				}
				$out[ $hid ][] = (string) $row['night_ymd'];
			}
		}
		return $out;
	}

	/**
	 * Fix holds whose DATE columns were stored as display strings (0000-00-00 in MySQL).
	 *
	 * @return int Number of holds repaired.
	 */
	public function repair_invalid_hold_dates() {
		global $wpdb;
		$t     = Database::stay_holds_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, check_in, check_out FROM $t WHERE status = %s",
				self::STATUS_CONFIRMED
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return 0;
		}
		$nights_map = $this->nights_by_hold_ids(
			array_map(
				static function ( $row ) {
					return (int) $row['id'];
				},
				$rows
			)
		);
		$fixed = 0;
		foreach ( $rows as $row ) {
			$id      = (int) $row['id'];
			$check_in  = (string) $row['check_in'];
			$check_out = (string) $row['check_out'];
			$valid     = '' !== $check_in
				&& '' !== $check_out
				&& ! empty( Dates::nights_in_range( $check_in, $check_out ) );
			if ( $valid ) {
				continue;
			}
			$nights = isset( $nights_map[ $id ] ) ? $nights_map[ $id ] : array();
			if ( empty( $nights ) ) {
				continue;
			}
			sort( $nights );
			$check_in  = (string) $nights[0];
			$last      = Dates::parse_ymd( (string) end( $nights ) );
			if ( ! $last ) {
				continue;
			}
			$check_out = $last->modify( '+1 day' )->format( 'Y-m-d' );
			if ( $this->update_hold(
				$id,
				array(
					'check_in'   => $check_in,
					'check_out'  => $check_out,
					'updated_at' => current_time( 'mysql' ),
				)
			) ) {
				++$fixed;
			}
		}
		return $fixed;
	}

	/**
	 * Website accommodation holds arriving on check-in day.
	 *
	 * @param string $check_in_ymd Y-m-d.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_confirmed_order_holds_arriving_on( $check_in_ymd ) {
		if ( ! Dates::parse_ymd( $check_in_ymd ) ) {
			return array();
		}
		global $wpdb;
		$t    = Database::stay_holds_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $t WHERE status = %s AND kind = %s AND order_id > 0 AND check_in = %s ORDER BY id ASC",
				self::STATUS_CONFIRMED,
				self::KIND_BOOKING,
				$check_in_ymd
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Website accommodation holds with departure on check-out day.
	 *
	 * @param string $check_out_ymd Y-m-d.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_confirmed_order_holds_departing_on( $check_out_ymd ) {
		if ( ! Dates::parse_ymd( $check_out_ymd ) ) {
			return array();
		}
		global $wpdb;
		$t    = Database::stay_holds_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $t WHERE status = %s AND kind = %s AND order_id > 0 AND check_out = %s ORDER BY id ASC",
				self::STATUS_CONFIRMED,
				self::KIND_BOOKING,
				$check_out_ymd
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}
