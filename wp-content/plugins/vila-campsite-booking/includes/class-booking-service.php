<?php
/**
 * Availability and atomic assignment.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Core booking logic: half-open ranges, night rows, concurrency.
 */
class Booking_Service {

	/**
	 * @var Plot_Repository
	 */
	private $plots;

	/**
	 * @var Booking_Repository
	 */
	private $bookings;

	/**
	 * @var Plot_Block_Repository
	 */
	private $blocks;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->plots    = new Plot_Repository();
		$this->bookings = new Booking_Repository();
		$this->blocks   = new Plot_Block_Repository();
	}

	/**
	 * Guest category nightly rates (store currency). Filter mirrors theme.
	 *
	 * @param int $product_id Product ID.
	 * @return array{children: float, infants: float, pets: float}
	 */
	public static function guest_category_rates( $product_id ) {
		$defaults = array(
			'children' => 6.0,
			'infants'  => 3.0,
			'pets'     => 2.0,
		);
		return apply_filters( 'vila_cb_guest_category_nightly_rates', $defaults, (int) $product_id );
	}

	/**
	 * Whether a plot is free for every night in [check_in, check_out).
	 *
	 * @param int    $plot_id Plot ID.
	 * @param string $check_in Y-m-d.
	 * @param string $check_out Y-m-d exclusive.
	 * @return bool
	 */
	public function is_plot_free_for_range( $plot_id, $check_in, $check_out ) {
		global $wpdb;
		$nights = Dates::nights_in_range( $check_in, $check_out );
		if ( empty( $nights ) ) {
			return false;
		}
		$plot_id  = (int) $plot_id;
		$nt       = Database::booking_nights_table();
		$bt       = Database::bookings_table();
		$statuses = Booking_Repository::occupying_statuses();
		$night_ph = implode( ',', array_fill( 0, count( $nights ), '%s' ) );
		$st_ph    = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql      = "SELECT COUNT(*) FROM $nt n INNER JOIN $bt b ON b.id = n.booking_id WHERE n.plot_id = %d AND n.night_ymd IN ($night_ph) AND b.status IN ($st_ph)";
		$args     = array_merge( array( $plot_id ), $nights, $statuses );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built safely.
		$prepared = $wpdb->prepare( $sql, $args );
		$count    = (int) $wpdb->get_var( $prepared );
		return 0 === $count;
	}

	/**
	 * Find first plot (by sort order) free for the full range.
	 *
	 * Uses every row in wp_vila_plots (any count). WooCommerce products are not per-pitch; assignment is internal only.
	 *
	 * @param string $check_in Y-m-d.
	 * @param string $check_out Y-m-d exclusive.
	 * @return int|null Plot ID or null.
	 */
	public function find_available_plot( $check_in, $check_out ) {
		foreach ( $this->plots->get_bookable() as $p ) {
			$pid = (int) $p['id'];
			if ( $this->is_plot_blocked_for_range( $pid, $check_in, $check_out ) ) {
				continue;
			}
			if ( $this->is_plot_free_for_range( $pid, $check_in, $check_out ) ) {
				return $pid;
			}
		}
		return null;
	}

	/**
	 * Whether any night in [check_in, check_out) is blocked site-wide (all plots).
	 *
	 * @param string $check_in Y-m-d.
	 * @param string $check_out Y-m-d exclusive.
	 * @return bool
	 */
	public function is_globally_blocked_for_range( $check_in, $check_out ) {
		return $this->is_plot_blocked_for_range( Plot_Block_Repository::PLOT_ID_ALL, $check_in, $check_out );
	}

	/**
	 * Maintenance / closure blocks from wp_vila_plot_blocks (plot-specific and site-wide).
	 *
	 * @param int    $plot_id Plot ID (ignored when checking global-only via PLOT_ID_ALL).
	 * @param string $check_in Y-m-d.
	 * @param string $check_out Y-m-d exclusive.
	 * @return bool True if any night overlaps a block.
	 */
	public function is_plot_blocked_for_range( $plot_id, $check_in, $check_out ) {
		global $wpdb;
		$plot_id = (int) $plot_id;
		$nights  = Dates::nights_in_range( $check_in, $check_out );
		if ( empty( $nights ) ) {
			return true;
		}
		$t = Database::plot_blocks_table();
		if ( Plot_Block_Repository::PLOT_ID_ALL === $plot_id ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT start_ymd, end_ymd FROM $t WHERE plot_id = %d AND end_ymd > %s AND start_ymd < %s",
					Plot_Block_Repository::PLOT_ID_ALL,
					$check_in,
					$check_out
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT start_ymd, end_ymd FROM $t WHERE plot_id IN (%d, %d) AND end_ymd > %s AND start_ymd < %s",
					Plot_Block_Repository::PLOT_ID_ALL,
					$plot_id,
					$check_in,
					$check_out
				),
				ARRAY_A
			);
		}
		if ( empty( $rows ) ) {
			return false;
		}
		foreach ( $nights as $ymd ) {
			if ( self::night_overlaps_block_rows( $ymd, $rows ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string               $ymd  Y-m-d night.
	 * @param array<int, array<string, mixed>> $rows Block rows with start_ymd, end_ymd.
	 * @return bool
	 */
	private static function night_overlaps_block_rows( $ymd, array $rows ) {
		$d = Dates::parse_ymd( $ymd );
		if ( ! $d ) {
			return false;
		}
		foreach ( $rows as $row ) {
			$bs = Dates::parse_ymd( $row['start_ymd'] );
			$be = Dates::parse_ymd( $row['end_ymd'] );
			if ( ! $bs || ! $be ) {
				continue;
			}
			if ( $d >= $bs && $d < $be ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a specific plot is available for every night in [check_in, check_out).
	 *
	 * Combines booking conflicts and maintenance blocks into a single check.
	 *
	 * @param int    $plot_id   Plot ID.
	 * @param string $check_in  Y-m-d.
	 * @param string $check_out Y-m-d exclusive.
	 * @return bool
	 */
	public function is_plot_available_for_range( $plot_id, $check_in, $check_out ) {
		$plot_id = (int) $plot_id;
		if ( $this->is_plot_blocked_for_range( $plot_id, $check_in, $check_out ) ) {
			return false;
		}
		return $this->is_plot_free_for_range( $plot_id, $check_in, $check_out );
	}

	/**
	 * Dates in [from, to] inclusive where a specific plot is unavailable (booked or blocked).
	 *
	 * @param int    $plot_id Plot ID.
	 * @param string $from    Y-m-d.
	 * @param string $to      Y-m-d inclusive.
	 * @return string[] Y-m-d list.
	 */
	public function get_plot_unavailable_dates( $plot_id, $from, $to ) {
		global $wpdb;
		$plot_id = (int) $plot_id;

		if ( ! Dates::parse_ymd( $from ) || ! Dates::parse_ymd( $to ) ) {
			return array();
		}

		$nt       = Database::booking_nights_table();
		$bt       = Database::bookings_table();
		$statuses = Booking_Repository::occupying_statuses();
		$st_ph    = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$sql  = "SELECT DISTINCT n.night_ymd FROM $nt n INNER JOIN $bt b ON b.id = n.booking_id WHERE n.plot_id = %d AND n.night_ymd >= %s AND n.night_ymd <= %s AND b.status IN ($st_ph)";
		$args = array_merge( array( $plot_id, $from, $to ), $statuses );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders match $args.
		$booked = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
		$booked = is_array( $booked ) ? $booked : array();

		$pbt  = Database::plot_blocks_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT start_ymd, end_ymd FROM $pbt WHERE plot_id IN (%d, %d) AND end_ymd > %s AND start_ymd <= %s",
				Plot_Block_Repository::PLOT_ID_ALL,
				$plot_id,
				$from,
				$to
			),
			ARRAY_A
		);

		$blocked = array();
		if ( ! empty( $rows ) ) {
			$days = Dates::enumerate_inclusive( $from, $to );
			foreach ( $days as $ymd ) {
				$d = Dates::parse_ymd( $ymd );
				if ( ! $d ) {
					continue;
				}
				foreach ( $rows as $row ) {
					$bs = Dates::parse_ymd( $row['start_ymd'] );
					$be = Dates::parse_ymd( $row['end_ymd'] );
					if ( ! $bs || ! $be ) {
						continue;
					}
					if ( $d >= $bs && $d < $be ) {
						$blocked[] = $ymd;
						break;
					}
				}
			}
		}

		return array_values( array_unique( array_merge( $booked, $blocked ) ) );
	}

	/**
	 * Dates in [from, to] inclusive where all plots are occupied that night.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to Y-m-d inclusive.
	 * @return string[] Y-m-d list.
	 */
	public function get_fully_booked_dates( $from, $to ) {
		global $wpdb;
		$bookable_ids = $this->plots->get_bookable_ids();
		$plot_count   = count( $bookable_ids );
		if ( $plot_count <= 0 ) {
			return Dates::enumerate_inclusive( $from, $to );
		}
		$days = Dates::enumerate_inclusive( $from, $to );
		if ( empty( $days ) ) {
			return array();
		}

		$block_rows = $this->blocks->get_overlapping( $from, $to );
		$global_blocks = array();
		$plot_blocks   = array();
		foreach ( $block_rows as $row ) {
			$pid = (int) $row['plot_id'];
			if ( Plot_Block_Repository::PLOT_ID_ALL === $pid ) {
				$global_blocks[] = $row;
			} else {
				if ( ! isset( $plot_blocks[ $pid ] ) ) {
					$plot_blocks[ $pid ] = array();
				}
				$plot_blocks[ $pid ][] = $row;
			}
		}

		$nt       = Database::booking_nights_table();
		$bt       = Database::bookings_table();
		$statuses = Booking_Repository::occupying_statuses();
		$st_ph    = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$id_ph    = implode( ',', array_fill( 0, $plot_count, '%d' ) );
		$out      = array();
		foreach ( $days as $ymd ) {
			if ( self::night_overlaps_block_rows( $ymd, $global_blocks ) ) {
				$out[] = $ymd;
				continue;
			}
			$sql  = "SELECT DISTINCT n.plot_id FROM $nt n INNER JOIN $bt b ON b.id = n.booking_id WHERE n.plot_id IN ($id_ph) AND n.night_ymd = %s AND b.status IN ($st_ph)";
			$args = array_merge( $bookable_ids, array( $ymd ), $statuses );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders match $args.
			$booked_plot_ids = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
			$booked_plot_ids = is_array( $booked_plot_ids ) ? array_map( 'intval', $booked_plot_ids ) : array();
			$available       = 0;
			foreach ( $bookable_ids as $pid ) {
				if ( in_array( (int) $pid, $booked_plot_ids, true ) ) {
					continue;
				}
				$prow = isset( $plot_blocks[ (int) $pid ] ) ? $plot_blocks[ (int) $pid ] : array();
				if ( self::night_overlaps_block_rows( $ymd, $prow ) ) {
					continue;
				}
				$available++;
			}
			if ( $available <= 0 ) {
				$out[] = $ymd;
			}
		}
		return $out;
	}

	/**
	 * Create confirmed booking + night rows atomically. Rolls back on failure.
	 *
	 * @param int         $order_id Order ID.
	 * @param int         $line_item_id Line item ID.
	 * @param string      $check_in Y-m-d.
	 * @param string      $check_out Y-m-d exclusive.
	 * @param string|null $guest_json JSON.
	 * @param int         $preferred_plot_id Optional: try this plot first before iterating others.
	 * @return array{success: bool, booking_id?: int, plot_id?: int, message?: string}
	 */
	public function confirm_booking_atomic( $order_id, $line_item_id, $check_in, $check_out, $guest_json, $preferred_plot_id = 0 ) {
		global $wpdb;

		$nights = Dates::nights_in_range( $check_in, $check_out );
		if ( empty( $nights ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid date range.', 'vila-campsite-booking' ),
			);
		}

		$order_id      = (int) $order_id;
		$line_item_id  = (int) $line_item_id;
		$now           = current_time( 'mysql' );

		$wpdb->query( 'START TRANSACTION' );

		try {
			$plot_rows = $this->plots->get_bookable();

			$preferred_plot_id = (int) $preferred_plot_id;
			if ( $preferred_plot_id > 0 && ! $this->plots->is_bookable_plot_id( $preferred_plot_id ) ) {
				$preferred_plot_id = 0;
			}
			if ( $preferred_plot_id > 0 ) {
				$reordered = array();
				$rest      = array();
				foreach ( $plot_rows as $prow ) {
					if ( (int) $prow['id'] === $preferred_plot_id ) {
						$reordered[] = $prow;
					} else {
						$rest[] = $prow;
					}
				}
				$plot_rows = array_merge( $reordered, $rest );
			}

			$booking_id = $this->bookings->insert(
				array(
					'plot_id'      => 0,
					'order_id'     => $order_id,
					'line_item_id' => $line_item_id,
					'check_in'     => $check_in,
					'check_out'    => $check_out,
					'status'       => 'pending',
					'guest_json'   => $guest_json,
					'created_at'   => $now,
					'updated_at'   => $now,
				)
			);

			if ( ! $booking_id ) {
				throw new \RuntimeException( 'insert_booking' );
			}

			$nt = Database::booking_nights_table();
			$assigned_plot = null;

			foreach ( $plot_rows as $prow ) {
				$plot_id = (int) $prow['id'];
				if ( $this->is_plot_blocked_for_range( $plot_id, $check_in, $check_out ) ) {
					continue;
				}

				$wpdb->delete( $nt, array( 'booking_id' => $booking_id ) );

				$ok = true;
				foreach ( $nights as $ymd ) {
					$res = $wpdb->insert(
						$nt,
						array(
							'booking_id' => $booking_id,
							'plot_id'    => $plot_id,
							'night_ymd'  => $ymd,
						),
						array( '%d', '%d', '%s' )
					);
					if ( ! $res ) {
						$ok = false;
						break;
					}
				}

				if ( $ok ) {
					$assigned_plot = $plot_id;
					break;
				}
			}

			if ( null === $assigned_plot ) {
				$this->bookings->delete_with_nights( $booking_id );
				$wpdb->query( 'COMMIT' );
				return array(
					'success' => false,
					'message' => __( 'No pitch is available for the full stay. Please choose different dates or contact us.', 'vila-campsite-booking' ),
				);
			}

			$this->bookings->update(
				$booking_id,
				array(
					'plot_id'    => $assigned_plot,
					'status'     => 'confirmed',
					'updated_at' => $now,
				)
			);

			$wpdb->query( 'COMMIT' );

			/**
			 * Fires after a campsite booking is confirmed and inventory assigned.
			 *
			 * @param int $booking_id Booking ID.
			 * @param int $order_id   WooCommerce order ID.
			 */
			do_action( 'vila_cb_booking_confirmed', $booking_id, $order_id );

			return array(
				'success'    => true,
				'booking_id' => $booking_id,
				'plot_id'    => $assigned_plot,
			);
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			unset( $e );
			$wpdb->query( 'ROLLBACK' );
			return array(
				'success' => false,
				'message' => __( 'Could not complete the reservation. Please try again.', 'vila-campsite-booking' ),
			);
		}
	}

	/**
	 * Release inventory for a booking linked to order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function release_order_bookings( $order_id ) {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}
		foreach ( $this->bookings->get_by_order( $order_id ) as $row ) {
			$booking_id = (int) $row['id'];
			/**
			 * Fires before a booking is deleted and inventory released.
			 *
			 * @param int $booking_id Booking ID.
			 * @param int $order_id   WooCommerce order ID.
			 */
			do_action( 'vila_cb_booking_released', $booking_id, $order_id );
			$this->bookings->delete_with_nights( $booking_id );
		}
	}

	/**
	 * KPI helpers for a calendar day Y-m-d.
	 *
	 * @param string $ymd Y-m-d.
	 * @return array{arriving: int, departing: int, staying: int, activity_orders: int}
	 */
	public function get_day_stats( $ymd ) {
		$d = Dates::parse_ymd( $ymd );
		if ( ! $d ) {
			return array(
				'arriving'        => 0,
				'departing'       => 0,
				'staying'         => 0,
				'activity_orders' => 0,
			);
		}

		$bookings = $this->bookings->get_activity_for_day( $ymd );

		$arriving  = 0;
		$departing = 0;
		$staying   = 0;
		$orders    = array();

		foreach ( $bookings as $b ) {
			if ( 'confirmed' !== $b['status'] ) {
				continue;
			}
			$cin  = (string) $b['check_in'];
			$cout = (string) $b['check_out'];
			if ( $cin === $ymd ) {
				++$arriving;
			}
			if ( $cout === $ymd ) {
				++$departing;
			}
			$nights = Dates::nights_in_range( $cin, $cout );
			if ( in_array( $ymd, $nights, true ) ) {
				++$staying;
			}
			$oid = (int) $b['order_id'];
			if ( $oid ) {
				if ( $cin === $ymd || $cout === $ymd || in_array( $ymd, $nights, true ) ) {
					$orders[ $oid ] = true;
				}
			}
		}

		return array(
			'arriving'        => $arriving,
			'departing'       => $departing,
			'staying'         => $staying,
			'activity_orders' => count( $orders ),
		);
	}

	/**
	 * Whether another booking already occupies any night on this plot in the range (excluding one booking).
	 *
	 * @param int    $plot_id            Plot ID.
	 * @param string $check_in           Y-m-d inclusive.
	 * @param string $check_out          Y-m-d exclusive.
	 * @param int    $exclude_booking_id Booking to ignore (same row being edited).
	 * @return bool True if conflict.
	 */
	public function plot_range_has_conflict_excluding_booking( $plot_id, $check_in, $check_out, $exclude_booking_id ) {
		$nights = Dates::nights_in_range( $check_in, $check_out );
		if ( empty( $nights ) ) {
			return true;
		}
		$plot_id            = (int) $plot_id;
		$exclude_booking_id = (int) $exclude_booking_id;
		global $wpdb;
		$nt       = Database::booking_nights_table();
		$bt       = Database::bookings_table();
		$statuses = Booking_Repository::occupying_statuses();
		$st_ph    = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		foreach ( $nights as $ymd ) {
			$sql = "SELECT COUNT(*) FROM $nt n INNER JOIN $bt b ON b.id = n.booking_id WHERE n.plot_id = %d AND n.night_ymd = %s AND n.booking_id != %d AND b.status IN ($st_ph)";
			$args = array_merge( array( $plot_id, $ymd, $exclude_booking_id ), $statuses );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders match $args.
			$c = (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
			if ( $c > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Admin: update plot, dates, guest JSON; rebuild night rows; sync WooCommerce order line + billing.
	 *
	 * @param int                  $booking_id Booking PK.
	 * @param array<string, mixed> $args       plot_id, check_in, check_out, guests array, billing_first_name, billing_last_name, unit_type_label.
	 * @return array{success: bool, message?: string}
	 */
	public function admin_update_booking( $booking_id, array $args ) {
		global $wpdb;

		$booking_id = (int) $booking_id;
		$row        = $this->bookings->get( $booking_id );
		if ( ! $row || 'confirmed' !== (string) $row['status'] ) {
			return array(
				'success' => false,
				'message' => __( 'Booking not found or not confirmed.', 'vila-campsite-booking' ),
			);
		}

		$plot_id   = isset( $args['plot_id'] ) ? (int) $args['plot_id'] : 0;
		$check_in  = isset( $args['check_in'] ) ? sanitize_text_field( (string) $args['check_in'] ) : '';
		$check_out = isset( $args['check_out'] ) ? sanitize_text_field( (string) $args['check_out'] ) : '';

		if ( ! Dates::parse_ymd( $check_in ) || ! Dates::parse_ymd( $check_out ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid dates. Use Y-m-d.', 'vila-campsite-booking' ),
			);
		}

		$nights = Dates::nights_in_range( $check_in, $check_out );
		if ( empty( $nights ) ) {
			return array(
				'success' => false,
				'message' => __( 'Check-out must be after check-in.', 'vila-campsite-booking' ),
			);
		}

		if ( ! $this->plots->get( $plot_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid plot.', 'vila-campsite-booking' ),
			);
		}

		if ( $this->is_globally_blocked_for_range( $check_in, $check_out ) ) {
			return array(
				'success' => false,
				'message' => __( 'The campsite is closed for part of that date range.', 'vila-campsite-booking' ),
			);
		}
		if ( $this->is_plot_blocked_for_range( $plot_id, $check_in, $check_out ) ) {
			return array(
				'success' => false,
				'message' => __( 'This plot is blocked for part of that range.', 'vila-campsite-booking' ),
			);
		}

		if ( $this->plot_range_has_conflict_excluding_booking( $plot_id, $check_in, $check_out, $booking_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'Another booking already uses that plot for overlapping nights.', 'vila-campsite-booking' ),
			);
		}

		$guests_in = isset( $args['guests'] ) && is_array( $args['guests'] ) ? $args['guests'] : array();
		$guest_arr = WooCommerce_Integration::normalize_guest_counts_array( $guests_in );
		$existing  = json_decode( (string) $row['guest_json'], true );
		if ( is_array( $existing ) ) {
			foreach ( array( 'vehicle_slugs', 'extras_label', 'meal_options', 'meal_dates', 'meal_party' ) as $ek ) {
				if ( array_key_exists( $ek, $guests_in ) ) {
					continue;
				}
				if ( 'vehicle_slugs' === $ek && ! empty( $existing['vehicle_slugs'] ) && is_array( $existing['vehicle_slugs'] ) ) {
					$guest_arr['vehicle_slugs'] = array_values( array_filter( array_map( 'sanitize_key', $existing['vehicle_slugs'] ) ) );
				}
				if ( 'extras_label' === $ek && ! empty( $existing['extras_label'] ) && is_string( $existing['extras_label'] ) && '' !== trim( $existing['extras_label'] ) ) {
					$guest_arr['extras_label'] = sanitize_text_field( $existing['extras_label'] );
				}
				if ( 'meal_options' === $ek && ! empty( $existing['meal_options'] ) ) {
					$guest_arr['meal_options'] = Meal_Plan_Service::normalize_meal_slugs( $existing['meal_options'] );
				}
				if ( 'meal_dates' === $ek && ! empty( $existing['meal_dates'] ) && is_array( $existing['meal_dates'] ) ) {
					$guest_arr['meal_dates'] = $existing['meal_dates'];
				}
				if ( 'meal_party' === $ek && ! empty( $existing['meal_party'] ) && is_array( $existing['meal_party'] ) ) {
					$guest_arr['meal_party'] = $existing['meal_party'];
				}
			}
		}
		if ( isset( $guests_in['vehicle_slugs'] ) && is_array( $guests_in['vehicle_slugs'] ) ) {
			$guest_arr['vehicle_slugs'] = array_values( array_filter( array_map( 'sanitize_key', $guests_in['vehicle_slugs'] ) ) );
			if ( array() === $guest_arr['vehicle_slugs'] ) {
				unset( $guest_arr['vehicle_slugs'], $guest_arr['extras_label'] );
			}
		}
		if ( isset( $guests_in['extras_label'] ) && is_string( $guests_in['extras_label'] ) && '' !== trim( $guests_in['extras_label'] ) ) {
			$guest_arr['extras_label'] = sanitize_text_field( $guests_in['extras_label'] );
		}
		if ( isset( $guests_in['extras_label'] ) && is_string( $guests_in['extras_label'] ) && '' === trim( $guests_in['extras_label'] ) ) {
			unset( $guest_arr['extras_label'] );
		}
		// Explicit meal date edits from the booking form (empty = clear meals).
		if ( array_key_exists( 'meal_dates', $guests_in ) ) {
			$b_dates = Meal_Booking::sanitize_ymd_list( isset( $guests_in['meal_dates']['breakfast'] ) ? $guests_in['meal_dates']['breakfast'] : array() );
			$d_dates = Meal_Booking::sanitize_ymd_list( isset( $guests_in['meal_dates']['dinner'] ) ? $guests_in['meal_dates']['dinner'] : array() );
			$d_dates = array_values(
				array_filter(
					$d_dates,
					static function ( $ymd ) {
						return Meal_Plan_Service::is_weekday( $ymd );
					}
				)
			);
			$slugs = array();
			if ( ! empty( $b_dates ) ) {
				$slugs[] = 'breakfast';
			}
			if ( ! empty( $d_dates ) ) {
				$slugs[] = 'dinner';
			}
			if ( empty( $slugs ) ) {
				unset( $guest_arr['meal_options'], $guest_arr['meal_dates'], $guest_arr['meal_party'] );
			} else {
				$guest_arr['meal_options'] = $slugs;
				$guest_arr['meal_dates']   = array(
					'breakfast' => $b_dates,
					'dinner'    => $d_dates,
				);
				if ( empty( $guest_arr['meal_party'] ) ) {
					$guest_arr['meal_party'] = array(
						'adults'   => (int) $guest_arr['adults'],
						'children' => (int) $guest_arr['children'],
						'infants'  => (int) $guest_arr['infants'],
					);
				}
			}
		}
		$guest_json = wp_json_encode( $guest_arr );

		$order_id     = (int) $row['order_id'];
		$line_item_id = isset( $row['line_item_id'] ) ? (int) $row['line_item_id'] : 0;
		$now          = current_time( 'mysql' );

		$wpdb->query( 'START TRANSACTION' );

		try {
			$nt = Database::booking_nights_table();
			$wpdb->delete( $nt, array( 'booking_id' => $booking_id ), array( '%d' ) );

			foreach ( $nights as $ymd ) {
				$res = $wpdb->insert(
					$nt,
					array(
						'booking_id' => $booking_id,
						'plot_id'    => $plot_id,
						'night_ymd'  => $ymd,
					),
					array( '%d', '%d', '%s' )
				);
				if ( ! $res ) {
					throw new \RuntimeException( 'night_insert' );
				}
			}

			$this->bookings->update(
				$booking_id,
				array(
					'plot_id'    => $plot_id,
					'check_in'   => $check_in,
					'check_out'  => $check_out,
					'guest_json' => $guest_json,
					'updated_at' => $now,
				)
			);

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			$wpdb->query( 'ROLLBACK' );
			return array(
				'success' => false,
				'message' => __( 'Could not save the booking. Try again.', 'vila-campsite-booking' ),
			);
		}

		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( $order instanceof \WC_Order && $line_item_id > 0 ) {
			$unit_label = isset( $args['unit_type_label'] ) ? sanitize_text_field( (string) $args['unit_type_label'] ) : '';
			WooCommerce_Integration::sync_order_line_after_booking_admin_edit(
				$order,
				$line_item_id,
				$check_in,
				$check_out,
				$guest_arr,
				$unit_label,
				$plot_id
			);

			$fn = isset( $args['billing_first_name'] ) ? sanitize_text_field( (string) $args['billing_first_name'] ) : '';
			$ln = isset( $args['billing_last_name'] ) ? sanitize_text_field( (string) $args['billing_last_name'] ) : '';
			if ( '' !== $fn || '' !== $ln ) {
				$order->set_billing_first_name( $fn );
				$order->set_billing_last_name( $ln );
				$order->save();
			}

			$note = sprintf(
				/* translators: %d: booking ID */
				__( 'Campsite booking #%d updated from Campsite admin.', 'vila-campsite-booking' ),
				$booking_id
			);
			$order->add_order_note( $note, false, true );
		}

		/**
		 * Fires after an admin booking edit is saved.
		 *
		 * @param int $booking_id Booking ID.
		 */
		do_action( 'vila_cb_booking_updated', $booking_id );

		$row_after = $this->bookings->get( $booking_id );
		if ( $row_after ) {
			$meal_touch = array_merge(
				Dates::nights_in_range( (string) $row_after['check_in'], (string) $row_after['check_out'] ),
				Meal_Plan_Service::meal_dates_for_booking( $row_after )['breakfast'],
				Meal_Plan_Service::meal_dates_for_booking( $row_after )['dinner']
			);
			do_action( 'vila_cb_meals_changed', array_values( array_unique( $meal_touch ) ) );
		}

		return array( 'success' => true );
	}
}
