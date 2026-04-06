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
				),
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
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function availability( $request ) {
		$from = (string) $request->get_param( 'from' );
		$to   = (string) $request->get_param( 'to' );
		if ( $to < $from ) {
			return new \WP_Error( 'invalid_range', __( 'Invalid date range.', 'vila-campsite-booking' ), array( 'status' => 400 ) );
		}

		$svc = new Booking_Service();
		$full = $svc->get_fully_booked_dates( $from, $to );

		$bookable = null;
		$cin      = $request->get_param( 'check_in' );
		$cout     = $request->get_param( 'check_out' );
		if ( $cin && $cout && Dates::parse_ymd( (string) $cin ) && Dates::parse_ymd( (string) $cout ) ) {
			$bookable = null !== $svc->find_available_plot( (string) $cin, (string) $cout );
		}

		return rest_ensure_response(
			array(
				'fully_booked_dates' => $full,
				'bookable'           => $bookable,
			)
		);
	}
}
