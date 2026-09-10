<?php
/**
 * Operational snapshots for integrations (Telegram recap, Monday sync).
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Builds campsite day stats, booking rows, and meal headcounts for external consumers.
 */
class Ops_Snapshot {

	/**
	 * Verify Bearer token for ops REST routes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function verify_ops_token( $request ) {
		$expected = self::get_api_token();
		if ( '' === $expected ) {
			return new \WP_Error(
				'ops_token_missing',
				__( 'Ops API token is not configured. Set VILA_OPS_API_TOKEN in wp-config.php.', 'vila-campsite-booking' ),
				array( 'status' => 503 )
			);
		}

		$auth = (string) $request->get_header( 'authorization' );
		$token = '';
		if ( preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', $auth, $m ) ) {
			$token = (string) $m[1];
		}
		if ( '' === $token ) {
			$token = (string) $request->get_param( 'token' );
		}
		if ( '' === $token || ! hash_equals( $expected, $token ) ) {
			return new \WP_Error(
				'ops_unauthorized',
				__( 'Invalid or missing ops API token.', 'vila-campsite-booking' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Shared secret for ops integrations.
	 *
	 * @return string
	 */
	public static function get_api_token() {
		if ( defined( 'VILA_OPS_API_TOKEN' ) && is_string( VILA_OPS_API_TOKEN ) && '' !== VILA_OPS_API_TOKEN ) {
			return (string) VILA_OPS_API_TOKEN;
		}
		$opt = get_option( 'vila_cb_ops_api_token', '' );
		return is_string( $opt ) ? $opt : '';
	}

	/**
	 * Full operational snapshot for one calendar day.
	 *
	 * @param string $ymd Y-m-d.
	 * @return array<string, mixed>
	 */
	public static function get_day( $ymd ) {
		$ymd = sanitize_text_field( (string) $ymd );
		if ( ! Dates::parse_ymd( $ymd ) ) {
			return array(
				'date'  => $ymd,
				'error' => 'invalid_date',
			);
		}

		$svc   = new Booking_Service();
		$stats = $svc->get_day_stats( $ymd );
		$rows  = ( new Booking_Repository() )->get_activity_for_day( $ymd );

		$bookings = self::serialize_bookings( $rows, $ymd );
		$arrivals = array_values(
			array_filter(
				$bookings,
				static function ( $b ) use ( $ymd ) {
					return ! empty( $b['arriving'] ) && (string) $b['check_in'] === $ymd;
				}
			)
		);
		$departures = array_values(
			array_filter(
				$bookings,
				static function ( $b ) use ( $ymd ) {
					return ! empty( $b['departing'] ) && (string) $b['check_out'] === $ymd;
				}
			)
		);
		$staying = array_values(
			array_filter(
				$bookings,
				static function ( $b ) {
					return ! empty( $b['staying_tonight'] );
				}
			)
		);

		$meal_plan = ( new Meal_Plan_Service() )->get_range( $ymd, $ymd );
		$meal_day  = isset( $meal_plan['days'][0] ) ? $meal_plan['days'][0] : null;

		return array(
			'date'       => $ymd,
			'timezone'   => wp_timezone_string(),
			'stats'      => $stats,
			'arrivals'   => $arrivals,
			'departures' => $departures,
			'staying'    => $staying,
			'bookings'   => $bookings,
			'meals'      => self::serialize_meal_day( $meal_day ),
			'childcare'  => Childcare_Service::day_snapshot( $ymd ),
		);
	}

	/**
	 * Meal plan for an inclusive date range.
	 *
	 * @param string $from_ymd Y-m-d inclusive.
	 * @param string $to_ymd   Y-m-d inclusive.
	 * @return array<string, mixed>
	 */
	public static function get_meals_range( $from_ymd, $to_ymd ) {
		$from_ymd = sanitize_text_field( (string) $from_ymd );
		$to_ymd   = sanitize_text_field( (string) $to_ymd );
		if ( ! Dates::parse_ymd( $from_ymd ) || ! Dates::parse_ymd( $to_ymd ) || $to_ymd < $from_ymd ) {
			return array(
				'from'  => $from_ymd,
				'to'    => $to_ymd,
				'error' => 'invalid_range',
				'days'  => array(),
			);
		}

		$plan = ( new Meal_Plan_Service() )->get_range( $from_ymd, $to_ymd );
		$days = array();
		foreach ( (array) $plan['days'] as $day ) {
			$days[] = self::serialize_meal_day( $day );
		}

		return array(
			'from'   => $from_ymd,
			'to'     => $to_ymd,
			'days'   => $days,
			'totals' => isset( $plan['totals'] ) ? $plan['totals'] : array(),
		);
	}

	/**
	 * Childcare plan for an inclusive date range.
	 *
	 * @param string $from_ymd Y-m-d inclusive.
	 * @param string $to_ymd   Y-m-d inclusive.
	 * @return array<string, mixed>
	 */
	public static function get_childcare_range( $from_ymd, $to_ymd ) {
		$from_ymd = sanitize_text_field( (string) $from_ymd );
		$to_ymd   = sanitize_text_field( (string) $to_ymd );
		if ( ! Dates::parse_ymd( $from_ymd ) || ! Dates::parse_ymd( $to_ymd ) || $to_ymd < $from_ymd ) {
			return array(
				'from'  => $from_ymd,
				'to'    => $to_ymd,
				'error' => 'invalid_range',
				'days'  => array(),
			);
		}

		return Childcare_Service::get_range( $from_ymd, $to_ymd );
	}

	/**
	 * Per-day arrival / departure / on-site snapshots for Monday + Telegram.
	 *
	 * @param string $from_ymd Y-m-d inclusive.
	 * @param string $to_ymd   Y-m-d inclusive.
	 * @return array{from: string, to: string, days: array<int, array<string, mixed>>}
	 */
	public static function get_activity_range( $from_ymd, $to_ymd ) {
		$from_ymd = sanitize_text_field( (string) $from_ymd );
		$to_ymd   = sanitize_text_field( (string) $to_ymd );
		if ( ! Dates::parse_ymd( $from_ymd ) || ! Dates::parse_ymd( $to_ymd ) || $to_ymd < $from_ymd ) {
			return array(
				'from'  => $from_ymd,
				'to'    => $to_ymd,
				'error' => 'invalid_range',
				'days'  => array(),
			);
		}

		$days = array();
		foreach ( Dates::enumerate_inclusive( $from_ymd, $to_ymd ) as $ymd ) {
			$snap = self::get_day( $ymd );
			$dt   = Dates::parse_ymd( $ymd );
			$days[] = array(
				'ymd'               => $ymd,
				'label'             => $dt ? $dt->format( 'l' ) : $ymd,
				'arriving'          => count( (array) $snap['arrivals'] ),
				'departing'         => count( (array) $snap['departures'] ),
				'staying'           => count( (array) $snap['staying'] ),
				'arrivals_detail'   => self::format_activity_detail( (array) $snap['arrivals'] ),
				'departures_detail' => self::format_activity_detail( (array) $snap['departures'] ),
				'staying_detail'    => self::format_activity_detail( (array) $snap['staying'] ),
			);
		}

		return array(
			'from' => $from_ymd,
			'to'   => $to_ymd,
			'days' => $days,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $bookings Booking payloads.
	 * @return string
	 */
	private static function format_activity_detail( array $bookings ) {
		$lines = array();
		foreach ( $bookings as $b ) {
			if ( ! is_array( $b ) ) {
				continue;
			}
			$who  = isset( $b['companions_line'] ) && '' !== (string) $b['companions_line']
				? (string) $b['companions_line']
				: (string) ( $b['guest_name'] ?? '' );
			$bits = array_filter(
				array(
					$who,
					isset( $b['plot_label'] ) ? (string) $b['plot_label'] : '',
					isset( $b['party_line'] ) ? (string) $b['party_line'] : '',
					isset( $b['unit_type'] ) ? (string) $b['unit_type'] : '',
				)
			);
			if ( $bits ) {
				$lines[] = implode( ' · ', $bits );
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Confirmed bookings overlapping [from, to) for Monday sync.
	 *
	 * @param string $from_ymd Y-m-d inclusive.
	 * @param string $to_ymd   Y-m-d inclusive (converted to exclusive end internally).
	 * @return array<string, mixed>
	 */
	public static function get_bookings_range( $from_ymd, $to_ymd ) {
		$from_ymd = sanitize_text_field( (string) $from_ymd );
		$to_ymd   = sanitize_text_field( (string) $to_ymd );
		if ( ! Dates::parse_ymd( $from_ymd ) || ! Dates::parse_ymd( $to_ymd ) || $to_ymd < $from_ymd ) {
			return array(
				'from'     => $from_ymd,
				'to'       => $to_ymd,
				'error'    => 'invalid_range',
				'bookings' => array(),
			);
		}

		$to_exclusive = Dates::parse_ymd( $to_ymd );
		$to_exclusive = $to_exclusive ? $to_exclusive->modify( '+1 day' )->format( 'Y-m-d' ) : $to_ymd;

		$rows = ( new Booking_Repository() )->get_in_range( $from_ymd, $to_exclusive );
		$today = wp_date( 'Y-m-d' );

		return array(
			'from'     => $from_ymd,
			'to'       => $to_ymd,
			'today'    => $today,
			'bookings' => self::serialize_bookings( $rows, $today ),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $rows     Booking rows.
	 * @param string                           $context_ymd Context day for flags.
	 * @return array<int, array<string, mixed>>
	 */
	private static function serialize_bookings( array $rows, $context_ymd ) {
		$plot_repo = new Plot_Repository();
		$reg_repo  = new Guest_Registration_Repository();
		$reg_map   = $reg_repo->get_registration_ids_by_booking_ids(
			array_map(
				static function ( $b ) {
					return isset( $b['id'] ) ? (int) $b['id'] : 0;
				},
				$rows
			)
		);

		$out = array();
		foreach ( $rows as $b ) {
			if ( 'confirmed' !== (string) $b['status'] ) {
				continue;
			}
			$out[] = self::serialize_booking_row( $b, $context_ymd, $plot_repo, $reg_repo, $reg_map );
		}
		return $out;
	}

	/**
	 * @param array<string, mixed>             $b         Booking row.
	 * @param string                           $context_ymd Y-m-d.
	 * @param Plot_Repository                  $plot_repo Plots.
	 * @param Guest_Registration_Repository    $reg_repo  Registrations.
	 * @param array<int, int>                  $reg_map   booking_id => registration_id.
	 * @return array<string, mixed>
	 */
	private static function serialize_booking_row( array $b, $context_ymd, Plot_Repository $plot_repo, Guest_Registration_Repository $reg_repo, array $reg_map ) {
		$bid        = (int) $b['id'];
		$order_id   = (int) $b['order_id'];
		$line_id    = isset( $b['line_item_id'] ) ? (int) $b['line_item_id'] : 0;
		$check_in   = (string) $b['check_in'];
		$check_out  = (string) $b['check_out'];
		$order      = $order_id ? wc_get_order( $order_id ) : null;
		$guest_name = '';
		$email      = '';
		if ( $order ) {
			$guest_name = trim( (string) $order->get_formatted_billing_full_name() );
			$email      = (string) $order->get_billing_email();
			if ( '' === $guest_name ) {
				$guest_name = '#' . $order->get_order_number();
			}
		}
		if ( '' === $guest_name ) {
			$guest_name = '#' . $bid;
		}

		$counts      = WooCommerce_Integration::resolve_guest_counts_for_booking(
			isset( $b['guest_json'] ) ? $b['guest_json'] : '',
			$order,
			$line_id
		);
		$party_line  = WooCommerce_Integration::format_guest_counts_calendar_line( $counts );
		$unit_type   = $order ? (string) WooCommerce_Integration::get_order_line_unit_type_label( $order, $line_id ) : '';
		$plot        = $plot_repo->get( (int) $b['plot_id'] );
		$plot_label  = $plot ? (string) $plot['label'] : '#' . (int) $b['plot_id'];
		$reg_id      = isset( $reg_map[ $bid ] ) ? (int) $reg_map[ $bid ] : 0;
		$companions  = self::companion_names_for_booking( $reg_repo, $reg_id );
		$nights      = Dates::nights_in_range( $check_in, $check_out );
		$staying     = in_array( $context_ymd, $nights, true );
		$arriving    = $check_in === $context_ymd;
		$departing   = $check_out === $context_ymd;
		$ops_status  = self::booking_ops_status( $check_in, $check_out, wp_date( 'Y-m-d' ) );
		$meal_slugs  = Meal_Plan_Service::meal_slugs_for_booking( $b, $order );

		return array(
			'id'                => $bid,
			'guest_name'        => $guest_name,
			'email'             => $email,
			'check_in'          => $check_in,
			'check_out'         => $check_out,
			'plot_id'           => (int) $b['plot_id'],
			'plot_label'        => $plot_label,
			'party'             => $counts,
			'party_line'        => $party_line,
			'companions'        => $companions,
			'companions_line'   => self::format_companions_line( $guest_name, $companions, $party_line ),
			'unit_type'         => $unit_type,
			'registration'      => $reg_id > 0 ? 'received' : 'pending',
			'registration_id'   => $reg_id,
			'order_id'          => $order_id,
			'order_number'      => $order ? (string) $order->get_order_number() : '',
			'meal_options'      => $meal_slugs,
			'arriving'          => $arriving,
			'departing'         => $departing,
			'staying_tonight'   => $staying,
			'ops_status'        => $ops_status,
			'nights'            => count( $nights ),
		);
	}

	/**
	 * @param string $check_in  Y-m-d.
	 * @param string $check_out Y-m-d exclusive.
	 * @param string $today     Y-m-d.
	 * @return string arriving|on_site|departed|upcoming
	 */
	public static function booking_ops_status( $check_in, $check_out, $today ) {
		if ( $today < $check_in ) {
			return 'upcoming';
		}
		if ( $today >= $check_out ) {
			return 'departed';
		}
		return 'on_site';
	}

	/**
	 * @param Guest_Registration_Repository $reg_repo Repo.
	 * @param int                           $reg_id   Registration ID.
	 * @return string[]
	 */
	private static function companion_names_for_booking( Guest_Registration_Repository $reg_repo, $reg_id ) {
		$reg_id = (int) $reg_id;
		if ( $reg_id <= 0 ) {
			return array();
		}
		$names = array();
		foreach ( $reg_repo->get_persons( $reg_id ) as $person ) {
			$given = isset( $person['given_name'] ) ? trim( (string) $person['given_name'] ) : '';
			$sur   = isset( $person['surname'] ) ? trim( (string) $person['surname'] ) : '';
			$full  = trim( $given . ' ' . $sur );
			if ( '' !== $full ) {
				$names[] = $full;
			}
		}
		return $names;
	}

	/**
	 * Human line for recap: lead guest + registered names or party counts.
	 *
	 * @param string   $guest_name  Lead guest.
	 * @param string[] $companions  Registered names.
	 * @param string   $party_line  Count breakdown.
	 * @return string
	 */
	public static function format_companions_line( $guest_name, array $companions, $party_line ) {
		$guest_name = trim( (string) $guest_name );
		if ( count( $companions ) > 1 ) {
			return implode( ', ', $companions );
		}
		if ( 1 === count( $companions ) ) {
			return (string) $companions[0];
		}
		if ( '' !== $party_line ) {
			return $guest_name . ' (' . $party_line . ')';
		}
		return $guest_name;
	}

	/**
	 * @param array<string, mixed>|null $day Meal plan day row.
	 * @return array<string, mixed>|null
	 */
	private static function serialize_meal_day( $day ) {
		if ( ! is_array( $day ) ) {
			return null;
		}

		$serialize_bucket = static function ( $bucket ) {
			if ( ! is_array( $bucket ) ) {
				return array(
					'adults'   => 0,
					'children' => 0,
					'infants'  => 0,
					'cook'     => 0,
					'parties'  => array(),
				);
			}
			$parties = array();
			foreach ( (array) $bucket['parties'] as $p ) {
				if ( ! is_array( $p ) ) {
					continue;
				}
				$parties[] = array(
					'name'     => isset( $p['name'] ) ? (string) $p['name'] : '',
					'plot'     => isset( $p['plot'] ) ? (string) $p['plot'] : '',
					'adults'   => isset( $p['adults'] ) ? (int) $p['adults'] : 0,
					'children' => isset( $p['children'] ) ? (int) $p['children'] : 0,
					'infants'  => isset( $p['infants'] ) ? (int) $p['infants'] : 0,
					'cook'     => isset( $p['cook'] ) ? (int) $p['cook'] : 0,
				);
			}
			return array(
				'adults'   => isset( $bucket['adults'] ) ? (int) $bucket['adults'] : 0,
				'children' => isset( $bucket['children'] ) ? (int) $bucket['children'] : 0,
				'infants'  => isset( $bucket['infants'] ) ? (int) $bucket['infants'] : 0,
				'cook'     => isset( $bucket['cook'] ) ? (int) $bucket['cook'] : 0,
				'parties'  => $parties,
			);
		};

		return array(
			'ymd'        => isset( $day['ymd'] ) ? (string) $day['ymd'] : '',
			'label'      => isset( $day['label'] ) ? (string) $day['label'] : '',
			'date_label' => isset( $day['date_label'] ) ? (string) $day['date_label'] : '',
			'is_weekday' => ! empty( $day['is_weekday'] ),
			'breakfast'  => $serialize_bucket( isset( $day['breakfast'] ) ? $day['breakfast'] : array() ),
			'dinner'     => $serialize_bucket( isset( $day['dinner'] ) ? $day['dinner'] : array() ),
		);
	}
}
