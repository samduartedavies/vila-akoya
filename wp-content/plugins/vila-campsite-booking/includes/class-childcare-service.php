<?php
/**
 * Childcare booking storage, settings, availability, and day snapshots.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Source of truth for paid childcare places.
 */
class Childcare_Service {

	const OPTION_SETTINGS = 'vila_cb_childcare_settings';

	/**
	 * Default childcare operations settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'fee_per_child_day' => 15.0,
			'capacity'          => 8,
			'open_weekdays'     => array( 0, 1, 2, 3, 4, 5, 6 ),
			'cutoff_hour'       => 15,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function settings() {
		$stored = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return self::sanitize_settings( array_merge( self::defaults(), $stored ) );
	}

	/**
	 * @param mixed $raw Raw settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize_settings( $raw ) {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$base = self::defaults();
		$fee  = isset( $raw['fee_per_child_day'] ) ? (float) str_replace( ',', '.', (string) $raw['fee_per_child_day'] ) : (float) $base['fee_per_child_day'];
		$cap  = isset( $raw['capacity'] ) ? (int) $raw['capacity'] : (int) $base['capacity'];
		$hour = isset( $raw['cutoff_hour'] ) ? (int) $raw['cutoff_hour'] : (int) $base['cutoff_hour'];

		$weekdays = isset( $raw['open_weekdays'] ) && is_array( $raw['open_weekdays'] ) ? $raw['open_weekdays'] : $base['open_weekdays'];
		$weekdays = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $weekdays ),
					static function ( $day ) {
						return $day >= 0 && $day <= 6;
					}
				)
			)
		);
		sort( $weekdays );

		return array(
			'fee_per_child_day' => max( 0.0, round( $fee, 2 ) ),
			'capacity'          => max( 1, $cap ),
			'open_weekdays'     => $weekdays,
			'cutoff_hour'       => max( 0, min( 23, $hour ) ),
		);
	}

	/**
	 * @param mixed $raw List of dates.
	 * @return string[]
	 */
	public static function sanitize_ymd_list( $raw ) {
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : preg_split( '/[\s,]+/', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $v ) {
			$ymd = sanitize_text_field( (string) $v );
			if ( Dates::parse_ymd( $ymd ) ) {
				$out[] = $ymd;
			}
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}

	/**
	 * @param string $ymd Y-m-d.
	 * @return int 0 (Sunday) through 6 (Saturday).
	 */
	private static function weekday_index( $ymd ) {
		$dt = Dates::parse_ymd( $ymd );
		return $dt ? (int) $dt->format( 'w' ) : -1;
	}

	/**
	 * @param string $ymd Y-m-d.
	 * @return bool
	 */
	public static function is_open_day( $ymd ) {
		$settings = self::settings();
		return in_array( self::weekday_index( $ymd ), (array) $settings['open_weekdays'], true );
	}

	/**
	 * @param string $ymd Y-m-d.
	 * @return bool
	 */
	public static function is_cutoff_blocked( $ymd ) {
		$dt = Dates::parse_ymd( $ymd );
		if ( ! $dt ) {
			return true;
		}
		$settings = self::settings();
		$today    = wp_date( 'Y-m-d' );
		if ( $ymd <= $today ) {
			return true;
		}
		$tomorrow = ( new \DateTimeImmutable( $today . ' 12:00:00', wp_timezone() ) )->modify( '+1 day' )->format( 'Y-m-d' );
		if ( $ymd === $tomorrow && (int) wp_date( 'G' ) >= (int) $settings['cutoff_hour'] ) {
			return true;
		}
		return false;
	}

	/**
	 * @param string $from_ymd From.
	 * @param string $to_ymd   To.
	 * @return array<string, int>
	 */
	public static function booked_counts_by_day( $from_ymd, $to_ymd ) {
		global $wpdb;
		$table = Database::childcare_places_table();
		$sql   = $wpdb->prepare(
			"SELECT ymd, COUNT(*) AS c FROM $table WHERE ymd >= %s AND ymd <= %s AND status = 'booked' GROUP BY ymd",
			$from_ymd,
			$to_ymd
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['ymd'] ] = (int) $row['c'];
		}
		return $out;
	}

	/**
	 * @param string $from_ymd From.
	 * @param string $to_ymd   To.
	 * @param int    $child_count Needed places.
	 * @return array<int, array<string, mixed>>
	 */
	public static function availability_range( $from_ymd, $to_ymd, $child_count = 1 ) {
		$settings = self::settings();
		$capacity = (int) $settings['capacity'];
		$needed   = max( 1, (int) $child_count );
		$counts   = self::booked_counts_by_day( $from_ymd, $to_ymd );
		$days     = array();

		foreach ( Dates::enumerate_inclusive( $from_ymd, $to_ymd ) as $ymd ) {
			$booked = isset( $counts[ $ymd ] ) ? (int) $counts[ $ymd ] : 0;
			$open   = self::is_open_day( $ymd );
			$cutoff = self::is_cutoff_blocked( $ymd );
			$remain = max( 0, $capacity - $booked );
			$days[] = array(
				'ymd'            => $ymd,
				'open'           => $open,
				'cutoff_blocked' => $cutoff,
				'capacity'       => $capacity,
				'booked'         => $booked,
				'remaining'      => $remain,
				'full'           => $remain < $needed,
				'bookable'       => $open && ! $cutoff && $remain >= $needed,
			);
		}

		return $days;
	}

	/**
	 * @param string[] $dates Dates.
	 * @param int      $child_count Needed places per date.
	 * @return true|\WP_Error
	 */
	public static function validate_dates_available( array $dates, $child_count ) {
		$dates = self::sanitize_ymd_list( $dates );
		if ( empty( $dates ) ) {
			return new \WP_Error( 'childcare_dates', __( 'Select at least one childcare date.', 'vila-campsite-booking' ) );
		}
		$needed = max( 1, (int) $child_count );
		$days   = self::availability_range( $dates[0], $dates[ count( $dates ) - 1 ], $needed );
		$by_day = array();
		foreach ( $days as $day ) {
			$by_day[ (string) $day['ymd'] ] = $day;
		}
		foreach ( $dates as $ymd ) {
			$day = isset( $by_day[ $ymd ] ) ? $by_day[ $ymd ] : null;
			if ( ! $day || empty( $day['open'] ) ) {
				return new \WP_Error( 'childcare_closed', sprintf( __( 'Childcare is not open on %s.', 'vila-campsite-booking' ), $ymd ) );
			}
			if ( ! empty( $day['cutoff_blocked'] ) ) {
				return new \WP_Error( 'childcare_cutoff', sprintf( __( 'Childcare can no longer be booked for %s.', 'vila-campsite-booking' ), $ymd ) );
			}
			if ( (int) $day['remaining'] < $needed ) {
				return new \WP_Error( 'childcare_full', sprintf( __( 'Childcare is full on %s.', 'vila-campsite-booking' ), $ymd ) );
			}
		}
		return true;
	}

	/**
	 * @param string $from_ymd From.
	 * @param string $to_ymd   To.
	 * @return array<int, array<string, mixed>>
	 */
	public static function places_in_range( $from_ymd, $to_ymd ) {
		global $wpdb;
		$table = Database::childcare_places_table();
		$sql   = $wpdb->prepare(
			"SELECT * FROM $table WHERE ymd >= %s AND ymd <= %s ORDER BY ymd ASC, child_name ASC, id ASC",
			$from_ymd,
			$to_ymd
		);
		$rows  = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param string $ymd Day.
	 * @return array<string, mixed>
	 */
	public static function day_snapshot( $ymd ) {
		$range = self::get_range( $ymd, $ymd );
		return isset( $range['days'][0] ) ? $range['days'][0] : array();
	}

	/**
	 * @param string $from_ymd From.
	 * @param string $to_ymd   To.
	 * @return array<string, mixed>
	 */
	public static function get_range( $from_ymd, $to_ymd ) {
		$settings = self::settings();
		$capacity = (int) $settings['capacity'];
		$places   = self::places_in_range( $from_ymd, $to_ymd );
		$by_day   = array();
		foreach ( $places as $row ) {
			$ymd = (string) $row['ymd'];
			if ( ! isset( $by_day[ $ymd ] ) ) {
				$by_day[ $ymd ] = array();
			}
			$by_day[ $ymd ][] = $row;
		}

		$days       = array();
		$total_book = 0;
		$total_abs  = 0;
		foreach ( Dates::enumerate_inclusive( $from_ymd, $to_ymd ) as $ymd ) {
			$dt      = Dates::parse_ymd( $ymd );
			$rows    = isset( $by_day[ $ymd ] ) ? $by_day[ $ymd ] : array();
			$booked  = array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						return 'booked' === (string) $row['status'];
					}
				)
			);
			$absent  = array_values(
				array_filter(
					$rows,
					static function ( $row ) {
						return 'absent' === (string) $row['status'];
					}
				)
			);
			$total_book += count( $booked );
			$total_abs  += count( $absent );
			$days[] = array(
				'ymd'             => $ymd,
				'label'           => $dt ? $dt->format( 'l' ) : $ymd,
				'date_label'      => $dt ? $dt->format( self::wp_date_format_pattern() ) : $ymd,
				'is_today'        => $ymd === wp_date( 'Y-m-d' ),
				'open'            => self::is_open_day( $ymd ),
				'cutoff_blocked'  => self::is_cutoff_blocked( $ymd ),
				'capacity'        => $capacity,
				'booked_count'    => count( $booked ),
				'absent_count'    => count( $absent ),
				'remaining'       => max( 0, $capacity - count( $booked ) ),
				'children'        => array_map( array( __CLASS__, 'serialize_place' ), $booked ),
				'absent_children' => array_map( array( __CLASS__, 'serialize_place' ), $absent ),
			);
		}

		return array(
			'from'   => $from_ymd,
			'to'     => $to_ymd,
			'days'   => $days,
			'totals' => array(
				'booked_children' => $total_book,
				'absent_children' => $total_abs,
				'days_with_care'  => count(
					array_filter(
						$days,
						static function ( $day ) {
							return ! empty( $day['booked_count'] );
						}
					)
				),
			),
		);
	}

	/**
	 * @param array<string, mixed> $row Place row.
	 * @return array<string, mixed>
	 */
	public static function serialize_place( array $row ) {
		return array(
			'id'                => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'ymd'               => isset( $row['ymd'] ) ? (string) $row['ymd'] : '',
			'status'            => isset( $row['status'] ) ? (string) $row['status'] : 'booked',
			'parent_name'       => isset( $row['parent_name'] ) ? (string) $row['parent_name'] : '',
			'parent_phone'      => isset( $row['parent_phone'] ) ? (string) $row['parent_phone'] : '',
			'parent_email'      => isset( $row['parent_email'] ) ? (string) $row['parent_email'] : '',
			'telegram_username' => isset( $row['telegram_username'] ) ? (string) $row['telegram_username'] : '',
			'child_name'        => isset( $row['child_name'] ) ? (string) $row['child_name'] : '',
			'child_age'         => isset( $row['child_age'] ) ? (int) $row['child_age'] : 0,
			'allergies'         => isset( $row['allergies'] ) ? (string) $row['allergies'] : '',
			'order_id'          => isset( $row['order_id'] ) ? (int) $row['order_id'] : 0,
			'line_item_id'      => isset( $row['line_item_id'] ) ? (int) $row['line_item_id'] : 0,
		);
	}

	/**
	 * Persist paid childcare rows idempotently.
	 *
	 * @param int                  $order_id Order ID.
	 * @param int                  $line_item_id Line item ID.
	 * @param array<string, mixed> $purchase Normalized purchase.
	 * @param string               $email Billing email.
	 * @return string[] Dates touched.
	 */
	public static function insert_paid_purchase( $order_id, $line_item_id, array $purchase, $email = '' ) {
		global $wpdb;
		$table = Database::childcare_places_table();
		$dates = self::sanitize_ymd_list( isset( $purchase['dates'] ) ? $purchase['dates'] : array() );
		$now   = current_time( 'mysql' );
		$idx   = 0;
		foreach ( (array) ( $purchase['children'] ?? array() ) as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$child_name = sanitize_text_field( (string) ( $child['name'] ?? '' ) );
			if ( '' === $child_name ) {
				continue;
			}
			foreach ( $dates as $ymd ) {
				$wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO $table
						(order_id, line_item_id, child_index, ymd, status, parent_name, parent_phone, parent_email, telegram_username, child_name, child_age, allergies, created_at, updated_at)
						VALUES (%d, %d, %d, %s, 'booked', %s, %s, %s, %s, %s, %d, %s, %s, %s)",
						(int) $order_id,
						(int) $line_item_id,
						(int) $idx,
						$ymd,
						sanitize_text_field( (string) ( $purchase['parent_name'] ?? '' ) ),
						sanitize_text_field( (string) ( $purchase['parent_phone'] ?? '' ) ),
						sanitize_email( (string) $email ),
						self::normalize_telegram_username( (string) ( $purchase['telegram_username'] ?? '' ) ),
						$child_name,
						max( 0, (int) ( $child['age'] ?? 0 ) ),
						sanitize_textarea_field( (string) ( $child['allergies'] ?? '' ) ),
						$now,
						$now
					)
				);
			}
			++$idx;
		}
		return $dates;
	}

	/**
	 * @param int $place_id Place ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function mark_absent_by_id( $place_id ) {
		global $wpdb;
		$table    = Database::childcare_places_table();
		$place_id = (int) $place_id;
		if ( $place_id < 1 ) {
			return new \WP_Error( 'childcare_absent_missing', __( 'Missing childcare place.', 'vila-campsite-booking' ), array( 'status' => 400 ) );
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $place_id ), ARRAY_A );
		if ( ! $row ) {
			return new \WP_Error( 'childcare_absent_not_found', __( 'Childcare place not found.', 'vila-campsite-booking' ), array( 'status' => 404 ) );
		}
		if ( 'absent' !== (string) $row['status'] ) {
			$wpdb->update(
				$table,
				array(
					'status'     => 'absent',
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => $place_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			$row['status'] = 'absent';
		}
		do_action( 'vila_cb_childcare_changed', array( (string) $row['ymd'] ) );
		return self::serialize_place( $row );
	}

	/**
	 * @param string $ymd Date.
	 * @param string $child_name Child name fragment.
	 * @param string $telegram_username Optional parent Telegram username.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function mark_absent_by_match( $ymd, $child_name, $telegram_username = '' ) {
		global $wpdb;
		$table = Database::childcare_places_table();
		$ymd   = sanitize_text_field( (string) $ymd );
		if ( ! Dates::parse_ymd( $ymd ) ) {
			return new \WP_Error( 'childcare_absent_date', __( 'Invalid childcare date.', 'vila-campsite-booking' ), array( 'status' => 400 ) );
		}
		$name = trim( sanitize_text_field( (string) $child_name ) );
		if ( '' === $name ) {
			return new \WP_Error( 'childcare_absent_name', __( 'Please include the child name.', 'vila-campsite-booking' ), array( 'status' => 400 ) );
		}

		$base_where  = "ymd = %s AND status = 'booked' AND child_name LIKE %s";
		$base_params = array( $ymd, '%' . $wpdb->esc_like( $name ) . '%' );
		$user        = self::normalize_telegram_username( $telegram_username );
		$rows        = array();
		if ( '' !== $user ) {
			$sql  = $wpdb->prepare( "SELECT * FROM $table WHERE $base_where AND telegram_username = %s ORDER BY id ASC", array_merge( $base_params, array( $user ) ) );
			$rows = $wpdb->get_results( $sql, ARRAY_A );
		}
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			$sql  = $wpdb->prepare( "SELECT * FROM $table WHERE $base_where ORDER BY id ASC", $base_params );
			$rows = $wpdb->get_results( $sql, ARRAY_A );
		}
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return new \WP_Error( 'childcare_absent_not_found', __( 'No booked childcare place matched that child today.', 'vila-campsite-booking' ), array( 'status' => 404 ) );
		}
		if ( count( $rows ) > 1 ) {
			return new \WP_Error(
				'childcare_absent_ambiguous',
				__( 'More than one child matched. Please include the full child name.', 'vila-campsite-booking' ),
				array(
					'status'  => 409,
					'matches' => array_map( array( __CLASS__, 'serialize_place' ), $rows ),
				)
			);
		}
		return self::mark_absent_by_id( (int) $rows[0]['id'] );
	}

	/**
	 * @param string $username Username.
	 * @return string
	 */
	public static function normalize_telegram_username( $username ) {
		return strtolower( ltrim( trim( sanitize_text_field( (string) $username ) ), '@' ) );
	}

	/**
	 * @return string
	 */
	private static function wp_date_format_pattern() {
		$df = get_option( 'date_format' );
		return ( is_string( $df ) && '' !== $df ) ? $df : 'Y-m-d';
	}
}
