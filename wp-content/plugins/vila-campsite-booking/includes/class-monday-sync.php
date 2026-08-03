<?php
/**
 * Push campsite stay-day and meal headcounts to monday.com when orders change.
 *
 * Board model (day-centric, like meals):
 *   35 — Campsite Bookings  → one item per calendar day (arrivals / departures / on site)
 *   36 — Campsite Meals     → one item per calendar day (breakfast / dinner cooks)
 *
 * Secrets live in wp-config.php (never commit them):
 *   define( 'VILA_MONDAY_API_TOKEN', '...' );
 * Optional:
 *   define( 'VILA_MONDAY_BOOKINGS_BOARD', '35 — Campsite Bookings' );
 *   define( 'VILA_MONDAY_MEALS_BOARD', '36 — Campsite Meals' );
 *   define( 'VILA_MONDAY_API_VERSION', '2026-10' );
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress → monday.com sync on booking / meal lifecycle events.
 */
class Monday_Sync {

	const CRON_STAY_DAYS = 'vila_cb_monday_sync_stay_days';
	const CRON_MEALS     = 'vila_cb_monday_sync_meals';
	const OPTION_STAY_DAY_ITEMS = 'vila_cb_monday_stay_day_item_ids';
	const OPTION_MEAL_ITEMS     = 'vila_cb_monday_meal_item_ids';
	const API_URL               = 'https://api.monday.com/v2';

	/**
	 * Boot hooks.
	 */
	public static function init() {
		add_action( 'vila_cb_booking_confirmed', array( __CLASS__, 'on_booking_confirmed' ), 20, 2 );
		add_action( 'vila_cb_booking_updated', array( __CLASS__, 'on_booking_updated' ), 20, 1 );
		add_action( 'vila_cb_booking_released', array( __CLASS__, 'on_booking_released' ), 20, 2 );
		add_action( 'vila_cb_meals_changed', array( __CLASS__, 'on_meals_changed' ), 20, 1 );

		add_action( self::CRON_STAY_DAYS, array( __CLASS__, 'cron_sync_stay_days' ), 10, 1 );
		add_action( self::CRON_MEALS, array( __CLASS__, 'cron_sync_meals' ), 10, 1 );

		// Back-compat for jobs scheduled under previous hook names.
		add_action( 'vila_cb_monday_sync_booking', array( __CLASS__, 'cron_sync_booking_legacy' ), 10, 1 );
		add_action( 'vila_cb_monday_sync_release', array( __CLASS__, 'cron_sync_stay_days' ), 10, 1 );
	}

	/**
	 * Whether Monday write sync is configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::api_token();
	}

	/**
	 * @return string
	 */
	public static function api_token() {
		if ( defined( 'VILA_MONDAY_API_TOKEN' ) && is_string( VILA_MONDAY_API_TOKEN ) ) {
			return trim( VILA_MONDAY_API_TOKEN );
		}
		return '';
	}

	/**
	 * @return string
	 */
	public static function api_version() {
		if ( defined( 'VILA_MONDAY_API_VERSION' ) && is_string( VILA_MONDAY_API_VERSION ) && '' !== VILA_MONDAY_API_VERSION ) {
			return VILA_MONDAY_API_VERSION;
		}
		return '2026-10';
	}

	/**
	 * @return string
	 */
	public static function bookings_board_name() {
		if ( defined( 'VILA_MONDAY_BOOKINGS_BOARD' ) && is_string( VILA_MONDAY_BOOKINGS_BOARD ) && '' !== VILA_MONDAY_BOOKINGS_BOARD ) {
			return VILA_MONDAY_BOOKINGS_BOARD;
		}
		return '35 — Campsite Bookings';
	}

	/**
	 * @return string
	 */
	public static function meals_board_name() {
		if ( defined( 'VILA_MONDAY_MEALS_BOARD' ) && is_string( VILA_MONDAY_MEALS_BOARD ) && '' !== VILA_MONDAY_MEALS_BOARD ) {
			return VILA_MONDAY_MEALS_BOARD;
		}
		return '36 — Campsite Meals';
	}

	/**
	 * @param int $booking_id Booking ID.
	 * @param int $order_id   Order ID.
	 */
	public static function on_booking_confirmed( $booking_id, $order_id = 0 ) {
		unset( $order_id );
		self::schedule_booking_days( (int) $booking_id );
	}

	/**
	 * @param int $booking_id Booking ID.
	 */
	public static function on_booking_updated( $booking_id ) {
		self::schedule_booking_days( (int) $booking_id );
	}

	/**
	 * Booking still exists when this fires (deleted immediately after).
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $order_id   Order ID.
	 */
	public static function on_booking_released( $booking_id, $order_id = 0 ) {
		unset( $order_id );
		$row = ( new Booking_Repository() )->get( (int) $booking_id );
		if ( ! $row || ! self::is_configured() ) {
			return;
		}
		$dates = self::stay_dates_for_booking_row( $row );
		if ( empty( $dates ) ) {
			return;
		}
		self::schedule_unique( self::CRON_STAY_DAYS, array( $dates ) );
		$meal_dates = self::meal_dates_touching_booking( $row );
		if ( ! empty( $meal_dates ) ) {
			self::schedule_unique( self::CRON_MEALS, array( $meal_dates ) );
		}
	}

	/**
	 * @param string[] $ymd_list Meal dates to refresh.
	 */
	public static function on_meals_changed( $ymd_list ) {
		if ( ! is_array( $ymd_list ) ) {
			return;
		}
		$dates = array();
		foreach ( $ymd_list as $ymd ) {
			$ymd = sanitize_text_field( (string) $ymd );
			if ( Dates::parse_ymd( $ymd ) ) {
				$dates[] = $ymd;
			}
		}
		$dates = array_values( array_unique( $dates ) );
		if ( empty( $dates ) || ! self::is_configured() ) {
			return;
		}
		self::schedule_unique( self::CRON_MEALS, array( $dates ) );
	}

	/**
	 * @param int $booking_id Booking ID.
	 */
	private static function schedule_booking_days( $booking_id ) {
		$booking_id = (int) $booking_id;
		if ( $booking_id < 1 || ! self::is_configured() ) {
			return;
		}
		$row = ( new Booking_Repository() )->get( $booking_id );
		if ( ! $row ) {
			return;
		}
		$dates = self::stay_dates_for_booking_row( $row );
		if ( empty( $dates ) ) {
			return;
		}
		self::schedule_unique( self::CRON_STAY_DAYS, array( $dates ) );
		$meal_dates = self::meal_dates_touching_booking( $row );
		if ( ! empty( $meal_dates ) ) {
			self::schedule_unique( self::CRON_MEALS, array( $meal_dates ) );
		}
	}

	/**
	 * @param string $hook Hook.
	 * @param array  $args Args.
	 */
	private static function schedule_unique( $hook, array $args ) {
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time() + 5, $hook, $args );
		}
	}

	/**
	 * Legacy: booking id → recompute stay days for that booking.
	 *
	 * @param int $booking_id Booking ID.
	 */
	public static function cron_sync_booking_legacy( $booking_id ) {
		$row = ( new Booking_Repository() )->get( (int) $booking_id );
		if ( ! $row ) {
			return;
		}
		self::cron_sync_stay_days( self::stay_dates_for_booking_row( $row ) );
		$meal_dates = self::meal_dates_touching_booking( $row );
		if ( ! empty( $meal_dates ) ) {
			self::cron_sync_meals( $meal_dates );
		}
	}

	/**
	 * @param string[] $ymd_list Dates.
	 */
	public static function cron_sync_stay_days( $ymd_list ) {
		if ( ! is_array( $ymd_list ) || empty( $ymd_list ) ) {
			return;
		}
		$dates = array();
		foreach ( $ymd_list as $ymd ) {
			$ymd = sanitize_text_field( (string) $ymd );
			if ( Dates::parse_ymd( $ymd ) ) {
				$dates[] = $ymd;
			}
		}
		$dates = array_values( array_unique( $dates ) );
		if ( empty( $dates ) ) {
			return;
		}
		sort( $dates );

		try {
			$plan = Ops_Snapshot::get_activity_range( $dates[0], $dates[ count( $dates ) - 1 ] );
			$want = array_fill_keys( $dates, true );
			foreach ( (array) $plan['days'] as $day ) {
				$ymd = isset( $day['ymd'] ) ? (string) $day['ymd'] : '';
				if ( '' === $ymd || empty( $want[ $ymd ] ) ) {
					continue;
				}
				$activity = trim( (string) ( $day['arrivals_detail'] ?? '' ) . (string) ( $day['departures_detail'] ?? '' ) . (string) ( $day['staying_detail'] ?? '' ) );
				if ( '' === $activity ) {
					self::delete_stay_day_item( $ymd );
					continue;
				}
				self::upsert_stay_day_item( $day );
			}
		} catch ( \Throwable $e ) {
			self::log( 'stay-day sync failed: ' . $e->getMessage() );
		}
	}

	/**
	 * @param string[] $ymd_list Dates.
	 */
	public static function cron_sync_meals( $ymd_list ) {
		if ( ! is_array( $ymd_list ) || empty( $ymd_list ) ) {
			return;
		}
		sort( $ymd_list );
		$from = $ymd_list[0];
		$to   = $ymd_list[ count( $ymd_list ) - 1 ];
		$want = array_fill_keys( $ymd_list, true );

		try {
			$plan = Ops_Snapshot::get_meals_range( $from, $to );
			foreach ( (array) $plan['days'] as $day ) {
				$ymd = isset( $day['ymd'] ) ? (string) $day['ymd'] : '';
				if ( '' === $ymd || empty( $want[ $ymd ] ) ) {
					continue;
				}
				$cooks = (int) ( $day['breakfast']['cook'] ?? 0 ) + (int) ( $day['dinner']['cook'] ?? 0 );
				if ( $cooks < 1 ) {
					continue;
				}
				self::upsert_meal_day_item( $day );
			}
		} catch ( \Throwable $e ) {
			self::log( 'meals sync failed: ' . $e->getMessage() );
		}
	}

	/**
	 * @param array<string, mixed> $day Activity day from Ops_Snapshot::get_activity_range.
	 */
	private static function upsert_stay_day_item( array $day ) {
		$ymd = isset( $day['ymd'] ) ? (string) $day['ymd'] : '';
		if ( '' === $ymd ) {
			return;
		}

		$board = self::resolve_board( self::bookings_board_name() );
		$cols  = self::column_map( $board );
		$group = self::group_id_by_title( $board, 'Stay days' );
		if ( ! $group ) {
			$group = self::first_group_id( $board );
		}

		$map     = self::get_map( self::OPTION_STAY_DAY_ITEMS );
		$item_id = isset( $map[ $ymd ] ) ? (string) $map[ $ymd ] : '';

		$arriving  = (int) ( $day['arriving'] ?? 0 );
		$departing = (int) ( $day['departing'] ?? 0 );
		$checking_in  = (string) ( $day['arrivals_detail'] ?? '' );
		$checking_out = (string) ( $day['departures_detail'] ?? '' );
		$on_site      = (string) ( $day['staying_detail'] ?? '' );
		$label        = isset( $day['label'] ) ? (string) $day['label'] : $ymd;
		$name         = sprintf( '%s — %d in · %d out', $label, $arriving, $departing );

		$values = array();
		self::set_col( $values, $cols, 'Day ID', 'text', $ymd );
		self::set_col( $values, $cols, 'Date', 'date', array( 'date' => $ymd ) );
		self::set_col( $values, $cols, 'Checking in', 'long_text', array( 'text' => $checking_in ) );
		self::set_col( $values, $cols, 'Checking out', 'long_text', array( 'text' => $checking_out ) );
		self::set_col( $values, $cols, 'Staying', 'long_text', array( 'text' => $on_site ) );
		// Older column titles if the board was created before this simplification.
		self::set_col( $values, $cols, 'Arrivals detail', 'long_text', array( 'text' => $checking_in ) );
		self::set_col( $values, $cols, 'Departures detail', 'long_text', array( 'text' => $checking_out ) );
		self::set_col( $values, $cols, 'On site detail', 'long_text', array( 'text' => $on_site ) );

		if ( '' === $item_id ) {
			$created = self::graphql(
				'mutation ($boardId: ID!, $groupId: String!, $name: String!) {
					create_item(board_id: $boardId, group_id: $groupId, item_name: $name) { id }
				}',
				array(
					'boardId' => (string) $board['id'],
					'groupId' => (string) $group,
					'name'    => $name,
				)
			);
			$item_id = (string) ( $created['create_item']['id'] ?? '' );
			if ( '' === $item_id ) {
				throw new \RuntimeException( 'create_item returned no id for stay day' );
			}
			$map[ $ymd ] = $item_id;
			self::save_map( self::OPTION_STAY_DAY_ITEMS, $map );
		} else {
			self::graphql(
				'mutation ($id: ID!, $name: String!) { change_item_name(item_id: $id, name: $name) { id } }',
				array(
					'id'   => $item_id,
					'name' => $name,
				)
			);
		}

		if ( ! empty( $values ) ) {
			self::change_columns( (string) $board['id'], $item_id, $values );
		}
	}

	/**
	 * @param string $ymd Y-m-d.
	 */
	private static function delete_stay_day_item( $ymd ) {
		$map     = self::get_map( self::OPTION_STAY_DAY_ITEMS );
		$item_id = isset( $map[ $ymd ] ) ? (string) $map[ $ymd ] : '';
		if ( '' === $item_id ) {
			return;
		}
		try {
			self::graphql(
				'mutation ($id: ID!) { delete_item(item_id: $id) { id } }',
				array( 'id' => $item_id )
			);
		} catch ( \Throwable $e ) {
			self::log( 'delete stay day failed ' . $ymd . ': ' . $e->getMessage() );
		}
		unset( $map[ $ymd ] );
		self::save_map( self::OPTION_STAY_DAY_ITEMS, $map );
	}

	/**
	 * @param array<string, mixed> $day Meal day from Ops_Snapshot.
	 */
	private static function upsert_meal_day_item( array $day ) {
		$ymd = isset( $day['ymd'] ) ? (string) $day['ymd'] : '';
		if ( '' === $ymd ) {
			return;
		}

		$board = self::resolve_board( self::meals_board_name() );
		$cols  = self::column_map( $board );
		$group = self::group_id_by_title( $board, 'Meal days' );
		if ( ! $group ) {
			$group = self::first_group_id( $board );
		}

		$map     = self::get_map( self::OPTION_MEAL_ITEMS );
		$item_id = isset( $map[ $ymd ] ) ? (string) $map[ $ymd ] : '';

		$b_cook = isset( $day['breakfast']['cook'] ) ? (int) $day['breakfast']['cook'] : 0;
		$d_cook = isset( $day['dinner']['cook'] ) ? (int) $day['dinner']['cook'] : 0;
		$label  = isset( $day['label'] ) ? (string) $day['label'] : $ymd;
		$name   = sprintf( '%s — breakfast %d · dinner %d', $label, $b_cook, $d_cook );

		$values = array();
		self::set_col( $values, $cols, 'Day ID', 'text', $ymd );
		self::set_col( $values, $cols, 'Date', 'date', array( 'date' => $ymd ) );
		self::set_col( $values, $cols, 'Breakfast cooks', 'numbers', (string) $b_cook );
		self::set_col( $values, $cols, 'Dinner cooks', 'numbers', (string) $d_cook );
		self::set_col(
			$values,
			$cols,
			'Breakfast detail',
			'long_text',
			array( 'text' => self::meal_parties_text( isset( $day['breakfast']['parties'] ) ? $day['breakfast']['parties'] : array() ) )
		);
		self::set_col(
			$values,
			$cols,
			'Dinner detail',
			'long_text',
			array( 'text' => self::meal_parties_text( isset( $day['dinner']['parties'] ) ? $day['dinner']['parties'] : array() ) )
		);
		self::set_col(
			$values,
			$cols,
			'Weekday dinner?',
			'checkbox',
			array( 'checked' => ! empty( $day['is_weekday'] ) ? 'true' : 'false' )
		);

		if ( '' === $item_id ) {
			$created = self::graphql(
				'mutation ($boardId: ID!, $groupId: String!, $name: String!) {
					create_item(board_id: $boardId, group_id: $groupId, item_name: $name) { id }
				}',
				array(
					'boardId' => (string) $board['id'],
					'groupId' => (string) $group,
					'name'    => $name,
				)
			);
			$item_id = (string) ( $created['create_item']['id'] ?? '' );
			if ( '' === $item_id ) {
				throw new \RuntimeException( 'create_item returned no id for meal day' );
			}
			$map[ $ymd ] = $item_id;
			self::save_map( self::OPTION_MEAL_ITEMS, $map );
		} else {
			self::graphql(
				'mutation ($id: ID!, $name: String!) { change_item_name(item_id: $id, name: $name) { id } }',
				array(
					'id'   => $item_id,
					'name' => $name,
				)
			);
		}

		if ( ! empty( $values ) ) {
			self::change_columns( (string) $board['id'], $item_id, $values );
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $parties Parties.
	 * @return string
	 */
	private static function meal_parties_text( array $parties ) {
		$lines = array();
		foreach ( $parties as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$cook    = (int) ( $p['adults'] ?? 0 ) + (int) ( $p['children'] ?? 0 );
			$lines[] = sprintf(
				'%s · %s · %d to cook',
				(string) ( $p['name'] ?? '' ),
				(string) ( $p['plot'] ?? '?' ),
				$cook
			);
		}
		return implode( "\n", $lines );
	}

	/**
	 * Inclusive stay window: check-in through check-out (departure day included).
	 *
	 * @param array<string, mixed> $row Booking row.
	 * @return string[]
	 */
	private static function stay_dates_for_booking_row( array $row ) {
		$cin  = (string) $row['check_in'];
		$cout = (string) $row['check_out'];
		if ( ! Dates::parse_ymd( $cin ) || ! Dates::parse_ymd( $cout ) || $cout < $cin ) {
			return array();
		}
		return Dates::enumerate_inclusive( $cin, $cout );
	}

	/**
	 * @param array<string, mixed> $row Booking DB row.
	 * @return string[]
	 */
	private static function meal_dates_touching_booking( array $row ) {
		$cin    = (string) $row['check_in'];
		$cout   = (string) $row['check_out'];
		$nights = Dates::nights_in_range( $cin, $cout );
		$dates  = $nights;
		foreach ( $nights as $night ) {
			$dt = Dates::parse_ymd( $night );
			if ( $dt ) {
				$dates[] = $dt->modify( '+1 day' )->format( 'Y-m-d' );
			}
		}
		$explicit = Meal_Plan_Service::meal_dates_for_booking( $row );
		$dates    = array_merge( $dates, $explicit['breakfast'], $explicit['dinner'] );
		$dates    = array_values( array_unique( array_filter( $dates ) ) );
		sort( $dates );
		return $dates;
	}

	/**
	 * @param string $board_name Board name.
	 * @return array{id:string,name:string,columns:array,groups:array}
	 */
	private static function resolve_board( $board_name ) {
		static $cache = array();
		if ( isset( $cache[ $board_name ] ) ) {
			return $cache[ $board_name ];
		}
		$data = self::graphql(
			'query {
				boards(limit: 100) {
					id
					name
					state
					columns { id title type archived }
					groups { id title }
				}
			}'
		);
		foreach ( (array) ( $data['boards'] ?? array() ) as $board ) {
			if ( ( $board['state'] ?? '' ) === 'archived' ) {
				continue;
			}
			if ( (string) ( $board['name'] ?? '' ) === (string) $board_name ) {
				$cache[ $board_name ] = $board;
				return $board;
			}
		}
		throw new \RuntimeException( 'Monday board not found: ' . $board_name );
	}

	/**
	 * @param array<string, mixed> $board Board.
	 * @return array<string, array{id:string,type:string}>
	 */
	private static function column_map( array $board ) {
		$map = array();
		foreach ( (array) ( $board['columns'] ?? array() ) as $col ) {
			if ( ! empty( $col['archived'] ) ) {
				continue;
			}
			$title = (string) ( $col['title'] ?? '' );
			if ( '' === $title ) {
				continue;
			}
			$map[ $title ] = array(
				'id'   => (string) $col['id'],
				'type' => (string) ( $col['type'] ?? '' ),
			);
		}
		return $map;
	}

	/**
	 * @param array<string, mixed> $board Board.
	 * @param string               $title Group title.
	 * @return string|null
	 */
	private static function group_id_by_title( array $board, $title ) {
		foreach ( (array) ( $board['groups'] ?? array() ) as $g ) {
			if ( (string) ( $g['title'] ?? '' ) === (string) $title ) {
				return (string) $g['id'];
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $board Board.
	 * @return string
	 */
	private static function first_group_id( array $board ) {
		$groups = (array) ( $board['groups'] ?? array() );
		if ( empty( $groups ) ) {
			throw new \RuntimeException( 'Monday board has no groups' );
		}
		return (string) $groups[0]['id'];
	}

	/**
	 * @param array  $values Values by column id.
	 * @param array  $cols   Column map.
	 * @param string $title  Column title.
	 * @param string $type   Expected type (informational).
	 * @param mixed  $value  Value.
	 */
	private static function set_col( array &$values, array $cols, $title, $type, $value ) {
		unset( $type );
		if ( ! isset( $cols[ $title ] ) ) {
			return;
		}
		$values[ $cols[ $title ]['id'] ] = $value;
	}

	/**
	 * @param string               $board_id Board ID.
	 * @param string               $item_id  Item ID.
	 * @param array<string, mixed> $values   Column values.
	 */
	private static function change_columns( $board_id, $item_id, array $values ) {
		self::graphql(
			'mutation ($boardId: ID!, $itemId: ID!, $vals: JSON!) {
				change_multiple_column_values(
					board_id: $boardId,
					item_id: $itemId,
					column_values: $vals,
					create_labels_if_missing: true
				) { id }
			}',
			array(
				'boardId' => (string) $board_id,
				'itemId'  => (string) $item_id,
				'vals'    => wp_json_encode( $values ),
			)
		);
	}

	/**
	 * @param string               $query     GraphQL.
	 * @param array<string, mixed> $variables Variables.
	 * @return array<string, mixed>
	 */
	private static function graphql( $query, array $variables = array() ) {
		$token = self::api_token();
		if ( '' === $token ) {
			throw new \RuntimeException( 'VILA_MONDAY_API_TOKEN is not set in wp-config.php' );
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => $token,
					'Content-Type'  => 'application/json',
					'API-Version'   => self::api_version(),
				),
				'body'    => wp_json_encode(
					array(
						'query'     => $query,
						'variables' => $variables,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			throw new \RuntimeException( 'Invalid Monday API response (HTTP ' . $code . ')' );
		}
		if ( ! empty( $body['errors'] ) ) {
			throw new \RuntimeException( wp_json_encode( $body['errors'] ) );
		}
		return isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
	}

	/**
	 * @param string $option Option key.
	 * @return array<string|int, string>
	 */
	private static function get_map( $option ) {
		$raw = get_option( $option, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * @param string                    $option Option key.
	 * @param array<string|int, string> $map    Map.
	 */
	private static function save_map( $option, array $map ) {
		update_option( $option, $map, false );
	}

	/**
	 * @param string $message Message.
	 */
	private static function log( $message ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[vila-cb monday] ' . $message );
		}
	}
}
