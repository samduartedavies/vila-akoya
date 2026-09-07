<?php
/**
 * Aggregate campsite meal headcounts for kitchen / ops planning.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Builds per-day breakfast / dinner cook lists from confirmed bookings.
 *
 * Semantics (kitchen-facing):
 * - Dinner on date D = evening of D for guests occupying night D (Mon–Fri only).
 * - Breakfast on date D = morning of D for guests who occupied night D−1
 *   (still on site that morning; matches “breakfast the morning after each night”).
 */
class Meal_Plan_Service {

	const META_MEAL_OPTIONS = '_vila_meal_options';

	/**
	 * Empty meal bucket.
	 *
	 * @return array{adults: int, children: int, infants: int, cook: int, parties: array<int, array<string, mixed>>}
	 */
	private static function empty_bucket() {
		return array(
			'adults'   => 0,
			'children' => 0,
			'infants'  => 0,
			'cook'     => 0,
			'parties'  => array(),
		);
	}

	/**
	 * Normalize meal option slugs from order line meta (or guest_json).
	 *
	 * @param mixed $raw JSON string, array, or empty.
	 * @return string[]
	 */
	public static function normalize_meal_slugs( $raw ) {
		$slugs = array();
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			} else {
				$raw = array_map( 'trim', explode( ',', $raw ) );
			}
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$allowed = array( 'breakfast', 'dinner' );
		if ( function_exists( 'vila_akoya_normalize_meal_options_array' ) ) {
			return vila_akoya_normalize_meal_options_array( $raw );
		}
		foreach ( $raw as $v ) {
			$s = sanitize_key( is_string( $v ) ? $v : (string) $v );
			if ( $s && in_array( $s, $allowed, true ) ) {
				$slugs[] = $s;
			}
		}
		$slugs = array_values( array_unique( $slugs ) );
		sort( $slugs );
		return $slugs;
	}

	/**
	 * Meal option slugs for a booking line (order meta, then guest_json fallback).
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @param \WC_Order|null       $order   Order.
	 * @return string[]
	 */
	public static function meal_slugs_for_booking( array $booking, $order ) {
		$line_item_id = isset( $booking['line_item_id'] ) ? (int) $booking['line_item_id'] : 0;
		if ( $order instanceof \WC_Order && $line_item_id > 0 ) {
			$item = $order->get_item( $line_item_id );
			if ( $item && is_callable( array( $item, 'get_meta' ) ) ) {
				$from_meta = self::normalize_meal_slugs( $item->get_meta( self::META_MEAL_OPTIONS, true ) );
				if ( ! empty( $from_meta ) ) {
					return $from_meta;
				}
			}
		}
		if ( ! empty( $booking['guest_json'] ) ) {
			$dec = json_decode( (string) $booking['guest_json'], true );
			if ( is_array( $dec ) && ! empty( $dec['meal_options'] ) ) {
				return self::normalize_meal_slugs( $dec['meal_options'] );
			}
		}
		return array();
	}

	/**
	 * Whether Y-m-d is Monday–Friday in the site timezone.
	 *
	 * @param string $ymd Y-m-d.
	 * @return bool
	 */
	public static function is_weekday( $ymd ) {
		$d = Dates::parse_ymd( $ymd );
		if ( ! $d ) {
			return false;
		}
		return (int) $d->format( 'N' ) <= 5;
	}

	/**
	 * Previous calendar day (Y-m-d).
	 *
	 * @param string $ymd Y-m-d.
	 * @return string
	 */
	private static function previous_ymd( $ymd ) {
		$d = Dates::parse_ymd( $ymd );
		if ( ! $d ) {
			return '';
		}
		return $d->modify( '-1 day' )->format( 'Y-m-d' );
	}

	/**
	 * Build meal plan for inclusive [from, to] calendar days.
	 *
	 * @param string $from_ymd Y-m-d inclusive start.
	 * @param string $to_ymd   Y-m-d inclusive end.
	 * @return array{
	 *   days: array<int, array<string, mixed>>,
	 *   totals: array{breakfast_cook: int, dinner_cook: int, breakfast_days: int, dinner_days: int}
	 * }
	 */
	public function get_range( $from_ymd, $to_ymd ) {
		$days_ymd = Dates::enumerate_inclusive( $from_ymd, $to_ymd );
		$out_days = array();
		$totals   = array(
			'breakfast_cook' => 0,
			'dinner_cook'    => 0,
			'breakfast_days' => 0,
			'dinner_days'    => 0,
		);

		if ( empty( $days_ymd ) ) {
			return array(
				'days'   => array(),
				'totals' => $totals,
			);
		}

		// Load bookings that could contribute: need night before first day (breakfast) through last day (dinner).
		$prev_first = self::previous_ymd( $days_ymd[0] );
		$range_from = '' !== $prev_first ? $prev_first : $days_ymd[0];
		$last       = $days_ymd[ count( $days_ymd ) - 1 ];
		$last_dt    = Dates::parse_ymd( $last );
		$range_to   = $last_dt ? $last_dt->modify( '+1 day' )->format( 'Y-m-d' ) : $last;

		$repo     = new Booking_Repository();
		$bookings = $repo->get_in_range( $range_from, $range_to );
		$plot_repo = new Plot_Repository();

		// Cache orders + resolved guests/meals per booking id.
		$resolved = array();
		foreach ( $bookings as $b ) {
			$bid = (int) $b['id'];
			$oid = (int) $b['order_id'];
			$order = $oid ? wc_get_order( $oid ) : null;
			$meals = self::meal_slugs_for_booking( $b, $order );
			$meal_dates = self::meal_dates_for_booking( $b );
			if ( empty( $meals ) && empty( $meal_dates['breakfast'] ) && empty( $meal_dates['dinner'] ) ) {
				continue;
			}
			$guests = WooCommerce_Integration::resolve_guest_counts_for_booking(
				isset( $b['guest_json'] ) ? (string) $b['guest_json'] : '',
				$order,
				isset( $b['line_item_id'] ) ? (int) $b['line_item_id'] : 0
			);
			$name = '';
			if ( $order ) {
				$name = trim( (string) $order->get_formatted_billing_full_name() );
				if ( '' === $name ) {
					$name = '#' . $order->get_order_number();
				}
			}
			if ( '' === $name ) {
				$name = '#' . $bid;
			}
			$plot   = $plot_repo->get( (int) $b['plot_id'] );
			$plabel = $plot ? (string) $plot['label'] : '#' . (int) $b['plot_id'];
			$nights = Dates::nights_in_range( (string) $b['check_in'], (string) $b['check_out'] );
			$night_set = array_fill_keys( $nights, true );

			$resolved[ 'booking-' . $bid ] = array(
				'booking'    => $b,
				'order'      => $order,
				'meals'      => $meals,
				'meal_dates' => $meal_dates,
				'guests'     => $guests,
				'name'       => $name,
				'plot'       => $plabel,
				'night_set'  => $night_set,
				'edit_url'   => add_query_arg(
					array(
						'page'       => Admin::PAGE_BOOKING,
						'booking_id' => $bid,
					),
					admin_url( 'admin.php' )
				),
			);
		}

		foreach ( self::resolve_stay_holds_for_range( $range_from, $range_to ) as $stay_key => $stay_row ) {
			$resolved[ $stay_key ] = $stay_row;
		}

		$standalone = Meal_Booking::get_paid_purchases_in_range( $days_ymd[0], $last );

		foreach ( $days_ymd as $ymd ) {
			$dt         = Dates::parse_ymd( $ymd );
			$is_weekday = self::is_weekday( $ymd );
			$breakfast  = self::empty_bucket();
			$dinner     = self::empty_bucket();
			$prev_night = self::previous_ymd( $ymd );

			foreach ( $resolved as $row ) {
				$g = $row['guests'];
				// Prefer meal_party when dates were bought separately (may differ from stay headcount).
				if ( ! empty( $row['meal_dates']['breakfast'] ) || ! empty( $row['meal_dates']['dinner'] ) ) {
					$dec = json_decode( isset( $row['booking']['guest_json'] ) ? (string) $row['booking']['guest_json'] : '', true );
					if ( is_array( $dec ) && ! empty( $dec['meal_party'] ) && is_array( $dec['meal_party'] ) ) {
						$g = array_merge( $g, $dec['meal_party'] );
					}
				}
				$adults = (int) $g['adults'];
				$kids   = (int) $g['children'];
				$inf    = (int) $g['infants'];
				$cook   = $adults + $kids;

				$party_base = array(
					'booking_id' => (int) $row['booking']['id'],
					'name'       => $row['name'],
					'plot'       => $row['plot'],
					'adults'     => $adults,
					'children'   => $kids,
					'infants'    => $inf,
					'cook'       => $cook,
					'edit_url'   => $row['edit_url'],
					'order_url'  => $row['order'] ? $row['order']->get_edit_order_url() : '',
				);

				$has_explicit_b = ! empty( $row['meal_dates']['breakfast'] );
				$has_explicit_d = ! empty( $row['meal_dates']['dinner'] );

				$want_breakfast = $has_explicit_b
					? in_array( $ymd, $row['meal_dates']['breakfast'], true )
					: ( in_array( 'breakfast', $row['meals'], true ) && '' !== $prev_night && ! empty( $row['night_set'][ $prev_night ] ) );

				$want_dinner = $has_explicit_d
					? ( $is_weekday && in_array( $ymd, $row['meal_dates']['dinner'], true ) )
					: ( $is_weekday && in_array( 'dinner', $row['meals'], true ) && ! empty( $row['night_set'][ $ymd ] ) );

				if ( $want_breakfast ) {
					$breakfast['adults']   += $adults;
					$breakfast['children'] += $kids;
					$breakfast['infants']  += $inf;
					$breakfast['cook']     += $cook;
					$breakfast['parties'][] = $party_base;
				}

				if ( $want_dinner ) {
					$dinner['adults']   += $adults;
					$dinner['children'] += $kids;
					$dinner['infants']  += $inf;
					$dinner['cook']     += $cook;
					$dinner['parties'][] = $party_base;
				}
			}

			foreach ( $standalone as $sp ) {
				$adults = (int) $sp['adults'];
				$kids   = (int) $sp['children'];
				$inf    = (int) $sp['infants'];
				$cook   = $adults + $kids;
				$party  = array(
					'booking_id' => 0,
					'name'       => (string) $sp['booker_name'],
					'plot'       => __( 'Walk-in', 'vila-campsite-booking' ),
					'adults'     => $adults,
					'children'   => $kids,
					'infants'    => $inf,
					'cook'       => $cook,
					'edit_url'   => (string) $sp['edit_url'],
					'order_url'  => (string) $sp['edit_url'],
				);
				if ( in_array( $ymd, (array) $sp['breakfast_dates'], true ) ) {
					$breakfast['adults']   += $adults;
					$breakfast['children'] += $kids;
					$breakfast['infants']  += $inf;
					$breakfast['cook']     += $cook;
					$breakfast['parties'][] = $party;
				}
				if ( $is_weekday && in_array( $ymd, (array) $sp['dinner_dates'], true ) ) {
					$dinner['adults']   += $adults;
					$dinner['children'] += $kids;
					$dinner['infants']  += $inf;
					$dinner['cook']     += $cook;
					$dinner['parties'][] = $party;
				}
			}

			if ( $breakfast['cook'] > 0 ) {
				$totals['breakfast_cook'] += $breakfast['cook'];
				++$totals['breakfast_days'];
			}
			if ( $dinner['cook'] > 0 ) {
				$totals['dinner_cook'] += $dinner['cook'];
				++$totals['dinner_days'];
			}

			$df = get_option( 'date_format' );
			$df = ( is_string( $df ) && '' !== $df ) ? $df : 'Y-m-d';

			$out_days[] = array(
				'ymd'        => $ymd,
				'dow'        => $dt ? $dt->format( 'D' ) : '',
				'label'      => $dt ? $dt->format( 'l' ) : $ymd,
				'date_label' => $dt ? $dt->format( $df ) : $ymd,
				'is_weekday' => $is_weekday,
				'is_today'   => $ymd === wp_date( 'Y-m-d' ),
				'breakfast'  => $breakfast,
				'dinner'     => $dinner,
			);
		}

		return array(
			'days'   => $out_days,
			'totals' => $totals,
		);
	}

	/**
	 * Village-stay meals (tiny houses / caravans) paid with the stay order.
	 *
	 * @param string $range_from Y-m-d.
	 * @param string $range_to   Y-m-d exclusive-ish end used for booking overlap.
	 * @return array<string, array<string, mixed>>
	 */
	private static function resolve_stay_holds_for_range( $range_from, $range_to ) {
		if ( ! class_exists( Stay_Hold_Repository::class ) ) {
			return array();
		}
		$from = (string) $range_from;
		$to_inclusive = (string) $range_to;
		$to_dt = Dates::parse_ymd( $to_inclusive );
		if ( $to_dt ) {
			$to_inclusive = $to_dt->modify( '-1 day' )->format( 'Y-m-d' );
		}
		$repo  = new Stay_Hold_Repository();
		$holds = $repo->get_overlapping( $from, $to_inclusive );
		if ( empty( $holds ) ) {
			return array();
		}
		$nights_by_hold = $repo->nights_by_hold_ids(
			array_map(
				static function ( $hold ) {
					return isset( $hold['id'] ) ? (int) $hold['id'] : 0;
				},
				$holds
			)
		);
		$out = array();
		foreach ( $holds as $hold ) {
			if ( Stay_Hold_Repository::KIND_BLOCK === (string) $hold['kind'] ) {
				continue;
			}
			$hid   = (int) $hold['id'];
			$oid   = isset( $hold['order_id'] ) ? (int) $hold['order_id'] : 0;
			$lid   = isset( $hold['line_item_id'] ) ? (int) $hold['line_item_id'] : 0;
			$order = $oid ? wc_get_order( $oid ) : null;
			$item  = ( $order instanceof \WC_Order && $lid > 0 ) ? $order->get_item( $lid ) : null;
			$guest_json = '';
			if ( $item && is_callable( array( $item, 'get_meta' ) ) ) {
				$guest_json = (string) $item->get_meta( WooCommerce_Integration::META_GUESTS, true );
			}
			$booking_like = array(
				'id'           => $hid,
				'guest_json'   => $guest_json,
				'line_item_id' => $lid,
				'order_id'     => $oid,
			);
			$meals      = self::meal_slugs_for_booking( $booking_like, $order );
			$meal_dates = self::meal_dates_for_booking( $booking_like );
			if ( empty( $meals ) && empty( $meal_dates['breakfast'] ) && empty( $meal_dates['dinner'] ) ) {
				continue;
			}
			$guests = WooCommerce_Integration::resolve_guest_counts_for_booking( $guest_json, $order, $lid );
			$nights = isset( $nights_by_hold[ $hid ] ) ? $nights_by_hold[ $hid ] : Dates::nights_in_range( (string) $hold['check_in'], (string) $hold['check_out'] );
			$name   = trim( (string) $hold['guest_name'] );
			if ( '' === $name && $order instanceof \WC_Order ) {
				$name = trim( (string) $order->get_formatted_billing_full_name() );
			}
			if ( '' === $name ) {
				$name = $oid ? '#' . $oid : '#' . $hid;
			}
			$stay_id = (int) $hold['stay_id'];
			$plabel  = $stay_id ? get_the_title( $stay_id ) : __( 'Village stay', 'vila-campsite-booking' );
			if ( '' === (string) $plabel ) {
				$plabel = __( 'Village stay', 'vila-campsite-booking' );
			}
			$out[ 'stay-' . $hid ] = array(
				'booking'    => $booking_like,
				'order'      => $order,
				'meals'      => $meals,
				'meal_dates' => $meal_dates,
				'guests'     => $guests,
				'name'       => $name,
				'plot'       => $plabel,
				'night_set'  => array_fill_keys( $nights, true ),
				'edit_url'   => add_query_arg(
					array(
						'page'    => Admin_Stays::PAGE,
						'hold_id' => $hid,
					),
					admin_url( 'admin.php' )
				),
			);
		}
		return $out;
	}

	/**
	 * Explicit per-date meal lists from guest_json (empty = use all-nights fallback).
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @return array{breakfast: string[], dinner: string[]}
	 */
	public static function meal_dates_for_booking( array $booking ) {
		$out = array(
			'breakfast' => array(),
			'dinner'    => array(),
		);
		if ( empty( $booking['guest_json'] ) ) {
			return $out;
		}
		$dec = json_decode( (string) $booking['guest_json'], true );
		if ( ! is_array( $dec ) || empty( $dec['meal_dates'] ) || ! is_array( $dec['meal_dates'] ) ) {
			return $out;
		}
		if ( ! empty( $dec['meal_dates']['breakfast'] ) ) {
			$out['breakfast'] = Meal_Booking::sanitize_ymd_list( $dec['meal_dates']['breakfast'] );
		}
		if ( ! empty( $dec['meal_dates']['dinner'] ) ) {
			$out['dinner'] = Meal_Booking::sanitize_ymd_list( $dec['meal_dates']['dinner'] );
		}
		return $out;
	}

	/**
	 * Candidate meal calendar days for a stay (breakfast mornings + weekday dinners).
	 *
	 * @param string $check_in  Y-m-d.
	 * @param string $check_out Y-m-d exclusive end.
	 * @return array{breakfast: string[], dinner: string[]}
	 */
	public static function candidate_meal_dates_for_stay( $check_in, $check_out ) {
		$nights     = Dates::nights_in_range( (string) $check_in, (string) $check_out );
		$breakfast  = array();
		$dinner     = array();
		foreach ( $nights as $night ) {
			$dt = Dates::parse_ymd( $night );
			if ( $dt ) {
				$breakfast[] = $dt->modify( '+1 day' )->format( 'Y-m-d' );
			}
			if ( self::is_weekday( $night ) ) {
				$dinner[] = $night;
			}
		}
		$breakfast = array_values( array_unique( $breakfast ) );
		sort( $breakfast );
		sort( $dinner );
		return array(
			'breakfast' => $breakfast,
			'dinner'    => $dinner,
		);
	}

	/**
	 * Meal date choices + checked state for the booking edit form.
	 *
	 * Slug-only bookings (no meal_dates) treat all stay candidates as checked.
	 *
	 * @param array<string, mixed> $booking Booking row.
	 * @param \WC_Order|null       $order   Order (for line meta meal slugs).
	 * @return array{
	 *   breakfast_choices: string[],
	 *   dinner_choices: string[],
	 *   breakfast_checked: string[],
	 *   dinner_checked: string[],
	 *   meal_party: array{adults: int, children: int, infants: int}|null
	 * }
	 */
	public static function meal_edit_state_for_booking( array $booking, $order = null ) {
		$candidates = self::candidate_meal_dates_for_stay(
			isset( $booking['check_in'] ) ? (string) $booking['check_in'] : '',
			isset( $booking['check_out'] ) ? (string) $booking['check_out'] : ''
		);
		$explicit   = self::meal_dates_for_booking( $booking );
		$slugs      = self::meal_slugs_for_booking( $booking, $order );

		$checked_b = $explicit['breakfast'];
		$checked_d = $explicit['dinner'];
		if ( empty( $checked_b ) && in_array( 'breakfast', $slugs, true ) ) {
			$checked_b = $candidates['breakfast'];
		}
		if ( empty( $checked_d ) && in_array( 'dinner', $slugs, true ) ) {
			$checked_d = $candidates['dinner'];
		}

		$choices_b = array_values( array_unique( array_merge( $candidates['breakfast'], $checked_b ) ) );
		$choices_d = array_values( array_unique( array_merge( $candidates['dinner'], $checked_d ) ) );
		sort( $choices_b );
		sort( $choices_d );

		$party = null;
		if ( ! empty( $booking['guest_json'] ) ) {
			$dec = json_decode( (string) $booking['guest_json'], true );
			if ( is_array( $dec ) && ! empty( $dec['meal_party'] ) && is_array( $dec['meal_party'] ) ) {
				$party = array(
					'adults'   => isset( $dec['meal_party']['adults'] ) ? max( 0, (int) $dec['meal_party']['adults'] ) : 0,
					'children' => isset( $dec['meal_party']['children'] ) ? max( 0, (int) $dec['meal_party']['children'] ) : 0,
					'infants'  => isset( $dec['meal_party']['infants'] ) ? max( 0, (int) $dec['meal_party']['infants'] ) : 0,
				);
			}
		}

		return array(
			'breakfast_choices' => $choices_b,
			'dinner_choices'    => $choices_d,
			'breakfast_checked' => $checked_b,
			'dinner_checked'    => $checked_d,
			'meal_party'        => $party,
		);
	}
}
