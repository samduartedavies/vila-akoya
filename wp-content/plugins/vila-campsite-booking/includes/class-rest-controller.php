<?php
/**
 * REST API: availability for calendar greying.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Registers REST routes.
 */
class Rest_Controller {

	/**
	 * Init.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public static function register_routes() {
		register_rest_route(
			'vila-campsite-booking/v1',
			'/availability',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'availability' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'from' => array(
						'required'          => true,
						'validate_callback' => array( __CLASS__, 'validate_ymd' ),
					),
					'to'   => array(
						'required'          => true,
						'validate_callback' => array( __CLASS__, 'validate_ymd' ),
					),
					'check_in' => array(
						'required'          => false,
						'validate_callback' => array( __CLASS__, 'validate_ymd_optional' ),
					),
					'check_out' => array(
						'required'          => false,
						'validate_callback' => array( __CLASS__, 'validate_ymd_optional' ),
					),
					'plot_id' => array(
						'required'          => false,
						'validate_callback' => array( __CLASS__, 'validate_positive_int_optional' ),
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			'vila-campsite-booking/v1',
			'/plots',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'plots' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'vila-campsite-booking/v1',
			'/guest-registration/lookup',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'guest_registration_lookup' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'last_name' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'     => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					),
				),
			)
		);

		register_rest_route(
			'vila-campsite-booking/v1',
			'/guest-registration',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'guest_registration_submit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param string $param Param.
	 * @return bool
	 */
	public static function validate_ymd( $param ) {
		return (bool) Dates::parse_ymd( (string) $param );
	}

	/**
	 * @param string $param Param.
	 * @return bool
	 */
	public static function validate_ymd_optional( $param ) {
		if ( null === $param || '' === $param ) {
			return true;
		}
		return (bool) Dates::parse_ymd( (string) $param );
	}

	/**
	 * @param mixed $param Param.
	 * @return bool
	 */
	public static function validate_positive_int_optional( $param ) {
		if ( null === $param || '' === $param ) {
			return true;
		}
		return is_numeric( $param ) && (int) $param > 0;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function availability( $request ) {
		$from = (string) $request->get_param( 'from' );
		$to   = (string) $request->get_param( 'to' );
		if ( $to < $from ) {
			return new \WP_Error( 'invalid_range', __( 'Invalid date range.', 'vila-campsite-booking' ), array( 'status' => 400 ) );
		}

		$svc     = new Booking_Service();
		$plot_id = (int) $request->get_param( 'plot_id' );

		if ( $plot_id > 0 ) {
			$plot_repo = new Plot_Repository();
			if ( ! $plot_repo->is_bookable_plot_id( $plot_id ) ) {
				return rest_ensure_response(
					array(
						'fully_booked_dates' => Dates::enumerate_inclusive( $from, $to ),
						'bookable'           => false,
						'assigned_plot_id'   => null,
					)
				);
			}
			$unavailable = $svc->get_plot_unavailable_dates( $plot_id, $from, $to );

			$bookable = null;
			$cin      = $request->get_param( 'check_in' );
			$cout     = $request->get_param( 'check_out' );
			if ( $cin && $cout && Dates::parse_ymd( (string) $cin ) && Dates::parse_ymd( (string) $cout ) ) {
				$bookable = $svc->is_plot_available_for_range( $plot_id, (string) $cin, (string) $cout );
			}

			return rest_ensure_response(
				array(
					'fully_booked_dates' => $unavailable,
					'bookable'           => $bookable,
				)
			);
		}

		$full = $svc->get_fully_booked_dates( $from, $to );

		$bookable        = null;
		$assigned_plot   = null;
		$cin             = $request->get_param( 'check_in' );
		$cout            = $request->get_param( 'check_out' );
		if ( $cin && $cout && Dates::parse_ymd( (string) $cin ) && Dates::parse_ymd( (string) $cout ) ) {
			$found    = $svc->find_available_plot( (string) $cin, (string) $cout );
			$bookable = null !== $found;
			$assigned_plot = $found;
		}

		return rest_ensure_response(
			array(
				'fully_booked_dates' => $full,
				'bookable'           => $bookable,
				'assigned_plot_id'   => $assigned_plot,
			)
		);
	}

	/**
	 * @return \WP_REST_Response
	 */
	public static function plots() {
		$repo = new Plot_Repository();
		$rows = $repo->get_bookable();
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'id'    => (int) $row['id'],
				'label' => (string) $row['label'],
			);
		}
		return rest_ensure_response( $out );
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function guest_registration_lookup( $request ) {
		$nonce = self::verify_guest_registration_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}
		$last_name = (string) $request->get_param( 'last_name' );
		$email     = (string) $request->get_param( 'email' );
		if ( strlen( trim( $last_name ) ) < 2 ) {
			return new \WP_Error( 'invalid_last_name', __( 'Please enter at least two letters from the booking last name.', 'vila-campsite-booking' ), array( 'status' => 400 ) );
		}
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', __( 'Please enter the email used for the booking.', 'vila-campsite-booking' ), array( 'status' => 400 ) );
		}
		$repo = new Guest_Registration_Repository();
		return rest_ensure_response(
			array(
				'bookings' => $repo->lookup_bookings( $last_name, $email ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function guest_registration_submit( $request ) {
		$nonce = self::verify_guest_registration_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		if ( ! empty( $params['website'] ) ) {
			return new \WP_Error( 'spam_blocked', __( 'The registration could not be submitted.', 'vila-campsite-booking' ), array( 'status' => 400 ) );
		}

		$email = isset( $params['contact_email'] ) ? sanitize_email( (string) $params['contact_email'] ) : '';
		if ( self::rate_limited( $email ) ) {
			return new \WP_Error( 'rate_limited', __( 'Too many attempts. Please try again later.', 'vila-campsite-booking' ), array( 'status' => 429 ) );
		}

		$booking_id = isset( $params['booking_id'] ) ? absint( $params['booking_id'] ) : 0;
		$order_id   = 0;
		$check_in   = isset( $params['check_in'] ) ? (string) $params['check_in'] : '';
		$check_out  = isset( $params['check_out'] ) ? (string) $params['check_out'] : '';
		$booker     = isset( $params['booker_name'] ) ? sanitize_text_field( (string) $params['booker_name'] ) : '';
		$source     = $booking_id ? 'booking_lookup' : 'manual';

		if ( $booking_id ) {
			$booking_repo = new Booking_Repository();
			$booking      = $booking_repo->get( $booking_id );
			if ( ! $booking ) {
				return new \WP_Error( 'booking_not_found', __( 'We could not find that booking.', 'vila-campsite-booking' ), array( 'status' => 404 ) );
			}
			$order_id = isset( $booking['order_id'] ) ? (int) $booking['order_id'] : 0;
			$order    = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( ! $order instanceof \WC_Order || strtolower( (string) $order->get_billing_email() ) !== strtolower( $email ) ) {
				return new \WP_Error( 'booking_mismatch', __( 'The booking details do not match.', 'vila-campsite-booking' ), array( 'status' => 403 ) );
			}
			$booker    = '' !== $booker ? $booker : trim( (string) $order->get_formatted_billing_full_name() );
			$check_in  = (string) $booking['check_in'];
			$check_out = (string) $booking['check_out'];
		}

		$persons = isset( $params['persons'] ) && is_array( $params['persons'] ) ? $params['persons'] : array();
		$repo    = new Guest_Registration_Repository();
		$saved   = $repo->create_or_replace(
			array(
				'booking_id'    => $booking_id,
				'order_id'      => $order_id,
				'check_in'      => $check_in,
				'check_out'     => $check_out,
				'booker_name'   => $booker,
				'contact_email' => $email,
				'source'        => $source,
			),
			$persons
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return rest_ensure_response(
			array(
				'ok'              => true,
				'registration_id' => (int) $saved,
			)
		);
	}

	/**
	 * Require a valid wp_rest nonce (sent as X-WP-Nonce) for CSRF protection.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	private static function verify_guest_registration_nonce( $request ) {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( '' === $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'invalid_nonce', __( 'Security check failed. Please reload the page and try again.', 'vila-campsite-booking' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * @param string $email Email.
	 * @return bool
	 */
	private static function rate_limited( $email ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_SERVER['REMOTE_ADDR'] ) ) ) : 'unknown';
		$key = 'vila_cb_gr_' . md5( strtolower( (string) $email ) . '|' . $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 10 ) {
			return true;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return false;
	}
}
