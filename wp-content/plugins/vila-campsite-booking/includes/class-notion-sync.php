<?php
/**
 * Push campsite stay-day and meal headcounts to Notion when orders change.
 *
 * Database model (day-centric, like meals):
 *   Campsite Bookings → one page per calendar day (arrivals / departures / on site)
 *   Campsite Meals    → one page per calendar day (breakfast / dinner cooks)
 *
 * Secrets live in wp-config.php (never commit them):
 *   define( 'VILA_NOTION_API_KEY', '...' );
 * Optional:
 *   define( 'VILA_NOTION_VERSION', '2022-06-28' );
 *   define( 'VILA_NOTION_BOOKINGS_DB_ID', '...' );
 *   define( 'VILA_NOTION_MEALS_DB_ID', '...' );
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress → Notion sync on booking / meal lifecycle events.
 */
class Notion_Sync {

	const CRON_STAY_DAYS = 'vila_cb_notion_sync_stay_days';
	const CRON_MEALS     = 'vila_cb_notion_sync_meals';
	const OPTION_STAY_DAY_PAGES = 'vila_cb_notion_stay_day_page_ids';
	const OPTION_MEAL_PAGES     = 'vila_cb_notion_meal_page_ids';
	const OPTION_BOOKINGS_DB_ID = 'vila_cb_notion_bookings_db_id';
	const OPTION_MEALS_DB_ID    = 'vila_cb_notion_meals_db_id';
	const OPTION_CATALOG        = 'vila_cb_notion_catalog';
	const API_URL               = 'https://api.notion.com/v1';

	const DB_BOOKINGS = 'Campsite Bookings';
	const DB_MEALS    = 'Campsite Meals';

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

		// Back-compat for jobs scheduled under Monday hook names.
		add_action( 'vila_cb_monday_sync_stay_days', array( __CLASS__, 'cron_sync_stay_days' ), 10, 1 );
		add_action( 'vila_cb_monday_sync_meals', array( __CLASS__, 'cron_sync_meals' ), 10, 1 );
		add_action( 'vila_cb_monday_sync_booking', array( __CLASS__, 'cron_sync_booking_legacy' ), 10, 1 );
		add_action( 'vila_cb_monday_sync_release', array( __CLASS__, 'cron_sync_stay_days' ), 10, 1 );
	}

	/**
	 * Whether Notion write sync is configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::api_key();
	}

	/**
	 * @return string
	 */
	public static function api_key() {
		if ( defined( 'VILA_NOTION_API_KEY' ) && is_string( VILA_NOTION_API_KEY ) ) {
			return trim( VILA_NOTION_API_KEY );
		}
		return '';
	}

	/**
	 * @return string
	 */
	public static function api_version() {
		if ( defined( 'VILA_NOTION_VERSION' ) && is_string( VILA_NOTION_VERSION ) && '' !== VILA_NOTION_VERSION ) {
			return VILA_NOTION_VERSION;
		}
		return '2022-06-28';
	}

	/**
	 * @return string
	 */
	public static function bookings_database_name() {
		return self::DB_BOOKINGS;
	}

	/**
	 * @return string
	 */
	public static function meals_database_name() {
		return self::DB_MEALS;
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
					self::delete_stay_day_page( $ymd );
					continue;
				}
				self::upsert_stay_day_page( $day );
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
				self::upsert_meal_day_page( $day );
			}
		} catch ( \Throwable $e ) {
			self::log( 'meals sync failed: ' . $e->getMessage() );
		}
	}

	/**
	 * @param array<string, mixed> $day Activity day from Ops_Snapshot::get_activity_range.
	 */
	private static function upsert_stay_day_page( array $day ) {
		$ymd = isset( $day['ymd'] ) ? (string) $day['ymd'] : '';
		if ( '' === $ymd ) {
			return;
		}

		$db = self::resolve_database( self::DB_BOOKINGS, self::OPTION_BOOKINGS_DB_ID, 'VILA_NOTION_BOOKINGS_DB_ID' );

		$map     = self::get_map( self::OPTION_STAY_DAY_PAGES );
		$page_id = isset( $map[ $ymd ] ) ? (string) $map[ $ymd ] : '';

		$arriving     = (int) ( $day['arriving'] ?? 0 );
		$departing    = (int) ( $day['departing'] ?? 0 );
		$checking_in  = (string) ( $day['arrivals_detail'] ?? '' );
		$checking_out = (string) ( $day['departures_detail'] ?? '' );
		$on_site      = (string) ( $day['staying_detail'] ?? '' );
		$label        = isset( $day['label'] ) ? (string) $day['label'] : $ymd;
		$name         = sprintf( '%s — %d in · %d out', $label, $arriving, $departing );

		$props = array(
			'Name'          => $name,
			'Day ID'        => $ymd,
			'Date'          => $ymd,
			'Checking in'   => $checking_in,
			'Checking out'  => $checking_out,
			'Staying'       => $on_site,
		);

		if ( '' === $page_id ) {
			$page_id = self::find_page_by_day_id( $db, $ymd );
		}

		if ( '' === $page_id ) {
			$created = self::create_page( $db, $name, $props );
			$page_id = (string) ( $created['id'] ?? '' );
			if ( '' === $page_id ) {
				throw new \RuntimeException( 'create page returned no id for stay day' );
			}
			$map[ $ymd ] = $page_id;
			self::save_map( self::OPTION_STAY_DAY_PAGES, $map );
		} else {
			self::update_page( $db, $page_id, $name, $props );
		}
	}

	/**
	 * @param string $ymd Y-m-d.
	 */
	private static function delete_stay_day_page( $ymd ) {
		$map     = self::get_map( self::OPTION_STAY_DAY_PAGES );
		$page_id = isset( $map[ $ymd ] ) ? (string) $map[ $ymd ] : '';
		if ( '' === $page_id ) {
			try {
				$db      = self::resolve_database( self::DB_BOOKINGS, self::OPTION_BOOKINGS_DB_ID, 'VILA_NOTION_BOOKINGS_DB_ID' );
				$page_id = self::find_page_by_day_id( $db, $ymd );
			} catch ( \Throwable $e ) {
				return;
			}
		}
		if ( '' === $page_id ) {
			return;
		}
		try {
			self::archive_page( $page_id );
		} catch ( \Throwable $e ) {
			self::log( 'archive stay day failed ' . $ymd . ': ' . $e->getMessage() );
		}
		unset( $map[ $ymd ] );
		self::save_map( self::OPTION_STAY_DAY_PAGES, $map );
	}

	/**
	 * @param array<string, mixed> $day Meal day from Ops_Snapshot.
	 */
	private static function upsert_meal_day_page( array $day ) {
		$ymd = isset( $day['ymd'] ) ? (string) $day['ymd'] : '';
		if ( '' === $ymd ) {
			return;
		}

		$db = self::resolve_database( self::DB_MEALS, self::OPTION_MEALS_DB_ID, 'VILA_NOTION_MEALS_DB_ID' );

		$map     = self::get_map( self::OPTION_MEAL_PAGES );
		$page_id = isset( $map[ $ymd ] ) ? (string) $map[ $ymd ] : '';

		$b_cook = isset( $day['breakfast']['cook'] ) ? (int) $day['breakfast']['cook'] : 0;
		$d_cook = isset( $day['dinner']['cook'] ) ? (int) $day['dinner']['cook'] : 0;
		$label  = isset( $day['label'] ) ? (string) $day['label'] : $ymd;
		$name   = sprintf( '%s — breakfast %d · dinner %d', $label, $b_cook, $d_cook );

		$props = array(
			'Name'              => $name,
			'Day ID'            => $ymd,
			'Date'              => $ymd,
			'Breakfast cooks'   => $b_cook,
			'Dinner cooks'      => $d_cook,
			'Breakfast detail'  => self::meal_parties_text( isset( $day['breakfast']['parties'] ) ? $day['breakfast']['parties'] : array() ),
			'Dinner detail'     => self::meal_parties_text( isset( $day['dinner']['parties'] ) ? $day['dinner']['parties'] : array() ),
			'Weekday dinner?'   => ! empty( $day['is_weekday'] ),
		);

		if ( '' === $page_id ) {
			$page_id = self::find_page_by_day_id( $db, $ymd );
		}

		if ( '' === $page_id ) {
			$created = self::create_page( $db, $name, $props );
			$page_id = (string) ( $created['id'] ?? '' );
			if ( '' === $page_id ) {
				throw new \RuntimeException( 'create page returned no id for meal day' );
			}
			$map[ $ymd ] = $page_id;
			self::save_map( self::OPTION_MEAL_PAGES, $map );
		} else {
			self::update_page( $db, $page_id, $name, $props );
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
	 * @param string $name          Canonical database title.
	 * @param string $option_key    WP option for resolved database id.
	 * @param string $constant_name wp-config constant name.
	 * @return array{id:string,name:string,properties:array}
	 */
	private static function resolve_database( $name, $option_key, $constant_name ) {
		static $cache = array();
		$cache_key = $name;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$db_id = '';
		if ( defined( $constant_name ) && is_string( constant( $constant_name ) ) ) {
			$db_id = trim( constant( $constant_name ) );
		}
		if ( '' === $db_id ) {
			$stored = get_option( $option_key, '' );
			if ( is_string( $stored ) && '' !== $stored ) {
				$db_id = trim( $stored );
			}
		}
		if ( '' === $db_id ) {
			$catalog = get_option( self::OPTION_CATALOG, array() );
			if ( is_array( $catalog ) && ! empty( $catalog['databases'][ $name ] ) ) {
				$db_id = trim( (string) $catalog['databases'][ $name ] );
			}
		}
		if ( '' === $db_id ) {
			$db_id = self::search_database_id( $name );
		}
		if ( '' === $db_id ) {
			throw new \RuntimeException( 'Notion database not found: ' . $name );
		}

		update_option( $option_key, $db_id, false );
		$catalog = get_option( self::OPTION_CATALOG, array() );
		if ( ! is_array( $catalog ) ) {
			$catalog = array();
		}
		if ( empty( $catalog['databases'] ) || ! is_array( $catalog['databases'] ) ) {
			$catalog['databases'] = array();
		}
		$catalog['databases'][ $name ] = $db_id;
		update_option( self::OPTION_CATALOG, $catalog, false );

		$data = self::api_request( 'GET', '/databases/' . rawurlencode( $db_id ) );
		$entry = array(
			'id'         => (string) ( $data['id'] ?? $db_id ),
			'name'       => (string) $name,
			'properties' => isset( $data['properties'] ) && is_array( $data['properties'] ) ? $data['properties'] : array(),
		);
		$cache[ $cache_key ] = $entry;
		return $entry;
	}

	/**
	 * @param string $title Database title.
	 * @return string
	 */
	private static function search_database_id( $title ) {
		$data = self::api_request(
			'POST',
			'/search',
			array(
				'query'     => (string) $title,
				'page_size' => 25,
				'filter'    => array(
					'property' => 'object',
					'value'    => 'database',
				),
			)
		);
		foreach ( (array) ( $data['results'] ?? array() ) as $result ) {
			$db_title = self::plain_from_rich( isset( $result['title'] ) ? $result['title'] : array() );
			if ( $db_title === (string) $title ) {
				return (string) ( $result['id'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * @param array<string, mixed> $db   Database schema.
	 * @param string               $ymd  Day ID.
	 * @return string
	 */
	private static function find_page_by_day_id( array $db, $ymd ) {
		$data = self::api_request(
			'POST',
			'/databases/' . rawurlencode( (string) $db['id'] ) . '/query',
			array(
				'page_size' => 1,
				'filter'    => array(
					'property' => 'Day ID',
					'rich_text' => array(
						'equals' => (string) $ymd,
					),
				),
			)
		);
		$results = (array) ( $data['results'] ?? array() );
		if ( empty( $results[0]['id'] ) ) {
			return '';
		}
		return (string) $results[0]['id'];
	}

	/**
	 * @param array<string, mixed> $db    Database schema.
	 * @param string               $title Page title.
	 * @param array<string, mixed> $props Property map.
	 * @return array<string, mixed>
	 */
	private static function create_page( array $db, $title, array $props ) {
		return self::api_request(
			'POST',
			'/pages',
			array(
				'parent'     => array( 'database_id' => (string) $db['id'] ),
				'properties' => self::build_properties( $db, $props, $title ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $db      Database schema.
	 * @param string               $page_id Page ID.
	 * @param string               $title   Page title.
	 * @param array<string, mixed> $props   Property map.
	 */
	private static function update_page( array $db, $page_id, $title, array $props ) {
		self::api_request(
			'PATCH',
			'/pages/' . rawurlencode( (string) $page_id ),
			array(
				'properties' => self::build_properties( $db, $props, $title ),
			)
		);
	}

	/**
	 * @param string $page_id Page ID.
	 */
	private static function archive_page( $page_id ) {
		self::api_request(
			'PATCH',
			'/pages/' . rawurlencode( (string) $page_id ),
			array( 'archived' => true )
		);
	}

	/**
	 * @param array<string, mixed> $db    Database schema.
	 * @param array<string, mixed> $map   Simple property values keyed by title.
	 * @param string               $title Page title.
	 * @return array<string, mixed>
	 */
	private static function build_properties( array $db, array $map, $title ) {
		$out       = array();
		$title_key = self::title_property_name( $db );
		$map[ $title_key ] = $title;

		foreach ( $map as $prop_name => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$schema = isset( $db['properties'][ $prop_name ] ) ? $db['properties'][ $prop_name ] : null;
			if ( ! is_array( $schema ) || empty( $schema['type'] ) ) {
				continue;
			}
			$built = self::property_value( (string) $schema['type'], $value );
			if ( null !== $built ) {
				$out[ $prop_name ] = $built;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $db Database schema.
	 * @return string
	 */
	private static function title_property_name( array $db ) {
		foreach ( (array) ( $db['properties'] ?? array() ) as $name => $schema ) {
			if ( is_array( $schema ) && ( $schema['type'] ?? '' ) === 'title' ) {
				return (string) $name;
			}
		}
		return 'Name';
	}

	/**
	 * @param string $type  Notion property type.
	 * @param mixed  $value Simple value.
	 * @return array<string, mixed>|null
	 */
	private static function property_value( $type, $value ) {
		switch ( $type ) {
			case 'title':
				return array( 'title' => self::rich_text_chunks( (string) $value ) );
			case 'rich_text':
				return array( 'rich_text' => self::rich_text_chunks( (string) $value ) );
			case 'number':
				$num = is_numeric( $value ) ? (float) $value : null;
				return array( 'number' => $num );
			case 'checkbox':
				return array( 'checkbox' => (bool) $value );
			case 'date':
				return array(
					'date' => array(
						'start' => substr( (string) $value, 0, 10 ),
					),
				);
			default:
				return null;
		}
	}

	/**
	 * @param string $text Text content.
	 * @return array<int, array<string, mixed>>
	 */
	private static function rich_text_chunks( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return array();
		}
		$chunks = array();
		$len    = strlen( $text );
		for ( $i = 0; $i < $len; $i += 1900 ) {
			$chunks[] = array(
				'type' => 'text',
				'text' => array( 'content' => substr( $text, $i, 1900 ) ),
			);
		}
		return $chunks;
	}

	/**
	 * @param array<int, array<string, mixed>> $arr Rich text array.
	 * @return string
	 */
	private static function plain_from_rich( array $arr ) {
		$parts = array();
		foreach ( $arr as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$parts[] = (string) ( $item['plain_text'] ?? ( $item['text']['content'] ?? '' ) );
		}
		return implode( '', $parts );
	}

	/**
	 * @param string               $method HTTP method.
	 * @param string               $path   API path (with leading slash).
	 * @param array<string, mixed> $body   Request body.
	 * @return array<string, mixed>
	 */
	private static function api_request( $method, $path, array $body = array() ) {
		$key = self::api_key();
		if ( '' === $key ) {
			throw new \RuntimeException( 'VILA_NOTION_API_KEY is not set in wp-config.php' );
		}

		$args = array(
			'method'  => strtoupper( (string) $method ),
			'timeout' => 30,
			'headers' => array(
				'Authorization'  => 'Bearer ' . $key,
				'Notion-Version' => self::api_version(),
				'Content-Type'   => 'application/json',
			),
		);
		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_URL . $path, $args );
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Invalid Notion API response (HTTP ' . $code . ')' );
		}
		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['message'] ) ? (string) $data['message'] : $raw;
			throw new \RuntimeException( 'Notion HTTP ' . $code . ': ' . $msg );
		}
		return $data;
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
			error_log( '[vila-cb notion] ' . $message );
		}
	}
}
