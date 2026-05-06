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
		$rows = $repo->get_all();
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'id'    => (int) $row['id'],
				'label' => (string) $row['label'],
			);
		}
		return rest_ensure_response( $out );
	}
}
