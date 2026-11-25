<?php
/**
 * Revenue and booking insights for Campsite admin.
 *
 * @package Vila_Campsite_Booking
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Builds chart-ready aggregates from WooCommerce orders and booking tables.
 */
class Insights_Service {

	const CACHE_GROUP = 'vila_cb_insights';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_update_order', array( __CLASS__, 'clear_cache' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'clear_cache' ) );
		add_action( 'vila_cb_payment_status_changed', array( __CLASS__, 'clear_cache' ) );
	}

	/**
	 * @return array<string, string>
	 */
	public static function presets() {
		return array(
			'last30'     => __( 'Last 30 days', 'vila-campsite-booking' ),
			'this_month' => __( 'This month', 'vila-campsite-booking' ),
			'season'     => __( 'This year / season', 'vila-campsite-booking' ),
			'last_year'  => __( 'Last year', 'vila-campsite-booking' ),
			'all'        => __( 'All time', 'vila-campsite-booking' ),
			'custom'     => __( 'Custom', 'vila-campsite-booking' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function type_options() {
		return array(
			'all'       => __( 'All booking income', 'vila-campsite-booking' ),
			'pitch'     => __( 'Pitches', 'vila-campsite-booking' ),
			'stay'      => __( 'Village stays', 'vila-campsite-booking' ),
			'meal'      => __( 'Meals', 'vila-campsite-booking' ),
			'childcare' => __( 'Childcare', 'vila-campsite-booking' ),
		);
	}

	/**
	 * @param array<string, mixed> $raw Raw filters.
	 * @return array<string, string>
	 */
	public static function sanitize_filters( array $raw ) {
		$preset = isset( $raw['preset'] ) ? sanitize_key( (string) $raw['preset'] ) : 'last30';
		if ( ! isset( self::presets()[ $preset ] ) ) {
			$preset = 'last30';
		}
		$type = isset( $raw['type'] ) ? sanitize_key( (string) $raw['type'] ) : 'all';
		if ( ! isset( self::type_options()[ $type ] ) ) {
			$type = 'all';
		}
		$count_by = isset( $raw['count_by'] ) && 'stay' === $raw['count_by'] ? 'stay' : 'order';
		$from_raw = isset( $raw['from'] ) ? (string) $raw['from'] : '';
		$to_raw   = isset( $raw['to'] ) ? (string) $raw['to'] : '';
		$range    = self::range_for_preset( $preset, $from_raw, $to_raw );
		return array(
			'preset'   => $preset,
			'from'     => $range['from'],
			'to'       => $range['to'],
			'count_by' => $count_by,
			'type'     => $type,
		);
	}

	/**
	 * @param array<string, string> $filters Filters.
	 * @return array<string, mixed>
	 */
	public function get_report( array $filters ) {
		$filters = self::sanitize_filters( $filters );
		$cached  = self::cached_report( $filters );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$maps    = $this->booking_maps();
		$prev    = $this->previous_range( $filters['from'], $filters['to'] );
		$orders  = $this->load_orders( $prev['from'], $filters['to'] );
		$current = $this->summarize_period( $orders, $maps, $filters['from'], $filters['to'], $filters['count_by'], $filters['type'] );
		$prior   = $this->summarize_period( $orders, $maps, $prev['from'], $prev['to'], $filters['count_by'], $filters['type'] );

		return $this->store_report( $filters, $prev, $current, $prior );
	}

	/**
	 * @param array<string, string> $filters Sanitized filters.
	 * @return array<string, mixed>|null
	 */
	public static function cached_report( array $filters ) {
		$cached = get_transient( self::cache_key( $filters ) );
		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * @param array<string, string>  $filters Filters.
	 * @param array<string, mixed>   $payload Pre-rendered admin-ajax body (without success wrapper).
	 */
	public static function save_ajax_payload( array $filters, array $payload ) {
		$key    = self::cache_key( $filters );
		$cached = get_transient( $key );
		if ( ! is_array( $cached ) ) {
			return;
		}
		$cached['ajax_payload'] = $payload;
		set_transient( $key, $cached, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Drop a cached report that is too heavy to serve on a slow host.
	 *
	 * @param array<string, string> $filters Filters.
	 */
	public static function drop_cached_report( array $filters ) {
		delete_transient( self::cache_key( $filters ) );
	}

	/**
	 * Record what the current insights request is doing, so a request killed by the host
	 * can be explained by the next one.
	 *
	 * @param string $phase Human-readable phase.
	 * @param string $extra Detail (order IDs etc).
	 */
	public static function diag( $phase, $extra = '' ) {
		global $wpdb;
		static $started = null;
		if ( null === $started ) {
			$started = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
		}
		$value = wp_json_encode(
			array(
				'phase'   => (string) $phase,
				'extra'   => substr( (string) $extra, 0, 200 ),
				'started' => $started,
				'at'      => microtime( true ),
			)
		);
		// Direct write: bypasses object caches so the next request always sees it.
		$wpdb->query( $wpdb->prepare( "INSERT INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", self::diag_key(), $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @return array{phase: string, extra: string, started: float, at: float}|null
	 */
	public static function read_diag() {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", self::diag_key() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$val = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $val ) && isset( $val['phase'] ) ? $val : null;
	}

	private static function diag_key() {
		return 'vila_cb_insights_diag_' . get_current_user_id();
	}

	private static function cache_key( array $filters ) {
		return 'vila_cb_insights_' . md5( VILA_CB_VERSION . wp_json_encode( $filters ) );
	}

	private static function job_key( $job_id ) {
		return 'vila_cb_insjob_' . get_current_user_id() . '_' . sanitize_key( (string) $job_id );
	}

	/**
	 * Start a batched report build. Each later step stays well under host request timeouts.
	 *
	 * @param array<string, string> $filters Filters.
	 * @return array{job: string, total: int}
	 */
	public function start_job( array $filters ) {
		$filters = self::sanitize_filters( $filters );
		$prev    = $this->previous_range( $filters['from'], $filters['to'] );
		$job_id  = strtolower( wp_generate_password( 12, false, false ) );
		set_transient(
			self::job_key( $job_id ),
			$this->fresh_job_state( $filters, $prev ),
			HOUR_IN_SECONDS
		);
		return array(
			'job'   => $job_id,
			'total' => 0,
		);
	}

	/**
	 * @param array<string, string> $filters Filters.
	 * @param array<string, string> $prev    Previous range.
	 * @return array<string, mixed>
	 */
	private function fresh_job_state( array $filters, array $prev ) {
		return array(
			'filters'        => $filters,
			'prev'           => $prev,
			'phase'          => 'run',
			'created_offset'   => 0,
			'created_complete' => false,
			'seen'             => array(),
			'plugin_ids'     => array(),
			'plugin_pos'     => 0,
			'plugin_loaded'  => false,
			'queue'          => array(),
			'done'           => 0,
			'current'        => $this->empty_summary( $filters['from'], $filters['to'], false ),
			'prior'          => $this->empty_summary( $prev['from'], $prev['to'], false ),
		);
	}

	/**
	 * Process the next batch of a job.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $batch  Orders per step.
	 * @return array{done: bool, processed: int, total: int, report?: array<string, mixed>}
	 * @throws \RuntimeException When the job is missing or expired.
	 */
	public function run_job_step( $job_id, $batch = 10 ) {
		self::diag( 'reading job' );
		$key = self::job_key( $job_id );
		$job = get_transient( $key );
		if ( ! is_array( $job ) || empty( $job['phase'] ) ) {
			throw new \RuntimeException( __( 'Insights job expired. Reload the page to start again.', 'vila-campsite-booking' ) );
		}

		$filters = $job['filters'];
		$prev    = $job['prev'];
		$max     = min( 8, max( 1, (int) $batch ) );
		$until   = microtime( true ) + 2.5;

		while ( $this->job_has_more( $job ) && microtime( true ) < $until ) {
			if ( empty( $job['queue'] ) ) {
				self::diag( 'finding booking order IDs' );
				$this->fill_job_queue( $job, max( $max, 20 ) );
				if ( empty( $job['queue'] ) ) {
					break;
				}
			}
			$slice = array();
			while ( ! empty( $job['queue'] ) && count( $slice ) < $max ) {
				$slice[] = (int) array_shift( $job['queue'] );
			}
			self::diag( 'reading booking tables', implode( ',', $slice ) );
			$maps = $this->booking_maps( $slice );
			self::diag( 'loading WooCommerce orders', implode( ',', $slice ) );
			$orders = $this->load_order_objects( $slice );
			$by_id  = array();
			foreach ( $orders as $order ) {
				if ( $order instanceof \WC_Order ) {
					$by_id[ (int) $order->get_id() ] = $order;
				}
			}
			foreach ( $slice as $i => $id ) {
				$order = $by_id[ (int) $id ] ?? null;
				$job['stats']['found'] = (int) ( $job['stats']['found'] ?? 0 ) + 1;
				if ( $order instanceof \WC_Order ) {
					self::diag( 'adding order to totals', '#' . (int) $id );
					$reason = $this->add_order( $job['current'], $order, $maps, $filters['from'], $filters['to'], $filters['count_by'], $filters['type'] );
					$this->add_order( $job['prior'], $order, $maps, $prev['from'], $prev['to'], $filters['count_by'], $filters['type'] );
					$job['stats'][ $reason ] = (int) ( $job['stats'][ $reason ] ?? 0 ) + 1;
				} else {
					$job['stats']['not_loaded'] = (int) ( $job['stats']['not_loaded'] ?? 0 ) + 1;
					if ( count( (array) ( $job['stats']['failed'] ?? array() ) ) < 3 ) {
						$job['stats']['failed'][] = $this->describe_unloadable_order( (int) $id );
					}
				}
				$job['done'] = (int) $job['done'] + 1;
				if ( microtime( true ) >= $until ) {
					$job['queue'] = array_merge( array_slice( $slice, $i + 1 ), (array) $job['queue'] );
					break;
				}
			}
		}

		if ( $this->job_has_more( $job ) ) {
			self::diag( 'saving progress' );
			set_transient( $key, $job, HOUR_IN_SECONDS );
			return array(
				'done'      => false,
				'processed' => (int) $job['done'],
				'total'     => 0,
				'message'   => $this->job_progress_message( $job ),
			);
		}

		self::diag( 'summarising current period' );
		$current = $this->finish_summary( $job['current'], $filters['from'], $filters['to'], false );
		self::diag( 'summarising prior period' );
		$prior = $this->finish_summary( $job['prior'], $prev['from'], $prev['to'], false );
		self::diag( 'storing report' );
		$stats           = isset( $job['stats'] ) ? (array) $job['stats'] : array();
		$stats['source'] = isset( $job['source'] ) ? (string) $job['source'] : '';
		$stats['build']  = VILA_CB_VERSION;
		$stats['range']  = $filters['from'] . ' → ' . $filters['to'];
		$this->store_report( $filters, $prev, $current, $prior, $stats );
		delete_transient( $key );
		$processed = (int) $job['done'];
		return array(
			'done'      => false,
			'render'    => true,
			'processed' => $processed,
			'total'     => $processed,
			'message'   => __( 'Building charts…', 'vila-campsite-booking' ),
		);
	}

	/**
	 * @param array<string, mixed> $job Job.
	 * @return bool
	 */
	private function job_has_more( array $job ) {
		if ( empty( $job['created_complete'] ) || empty( $job['plugin_loaded'] ) ) {
			return true;
		}
		if ( (int) $job['plugin_pos'] < count( (array) $job['plugin_ids'] ) ) {
			return true;
		}
		return ! empty( $job['queue'] );
	}

	/**
	 * @param array<string, mixed> $job   Job (by reference).
	 * @param int                  $limit Page size.
	 */
	private function fill_job_queue( array &$job, $limit ) {
		$page = $this->next_job_page( $job, $limit );
		if ( ! empty( $page ) ) {
			$job['queue'] = array_merge( (array) $job['queue'], $page );
		}
	}

	/**
	 * @param array<string, mixed> $job Job.
	 * @return string
	 */
	private function job_progress_message( array $job ) {
		$n = (int) $job['done'];
		if ( $n < 1 ) {
			return __( 'Loading bookings…', 'vila-campsite-booking' );
		}
		return sprintf(
			/* translators: %d: number of bookings processed so far */
			__( 'Loaded %d bookings…', 'vila-campsite-booking' ),
			$n
		);
	}

	/**
	 * Pull the next page of order IDs into the job queue (mutates offsets).
	 *
	 * @param array<string, mixed> $job   Job (by reference).
	 * @param int                  $limit Page size.
	 * @return int[]
	 */
	private function next_job_page( array &$job, $limit ) {
		$from  = $job['prev']['from'];
		$to    = $job['filters']['to'];
		$limit = max( 1, (int) $limit );

		if ( empty( $job['created_complete'] ) ) {
			$offset = (int) ( $job['created_offset'] ?? 0 );
			if ( empty( $job['source'] ) ) {
				$page          = $this->page_created_order_ids( $from, $to, 0, $limit );
				$job['source'] = ! empty( $page ) ? ( $this->orders_use_hpos() ? 'wc_orders table' : 'posts table' ) : 'wc_get_orders';
				if ( empty( $page ) ) {
					$page = $this->page_created_order_ids_via_wc( $from, $to, 0, $limit );
				}
			} elseif ( 'wc_get_orders' === $job['source'] ) {
				$page = $this->page_created_order_ids_via_wc( $from, $to, $offset, $limit );
			} else {
				$page = $this->page_created_order_ids( $from, $to, $offset, $limit );
			}
			$job['created_offset'] = (int) ( $job['created_offset'] ?? 0 ) + count( $page );
			if ( count( $page ) < $limit ) {
				$job['created_complete'] = true;
			}
			foreach ( $page as $id ) {
				$job['seen'][ (int) $id ] = true;
			}
			if ( ! empty( $page ) ) {
				return $page;
			}
		}

		// Stay-date mode also needs orders placed before the window for stays inside it.
		if ( empty( $job['plugin_loaded'] ) ) {
			$extra = array();
			if ( 'stay' === $job['filters']['count_by'] ) {
				foreach ( $this->insights_order_ids_from_plugin( $from, $to ) as $id ) {
					if ( empty( $job['seen'][ (int) $id ] ) ) {
						$extra[] = (int) $id;
					}
				}
			}
			$job['plugin_ids']    = $extra;
			$job['plugin_pos']    = 0;
			$job['plugin_loaded'] = true;
			$job['seen']          = array();
		}
		$slice = array_slice( (array) $job['plugin_ids'], (int) $job['plugin_pos'], $limit );
		if ( empty( $slice ) ) {
			return array();
		}
		$job['plugin_pos'] = (int) $job['plugin_pos'] + count( $slice );
		return array_map( 'intval', $slice );
	}

	/**
	 * Order IDs from plugin booking tables (fast). Avoids scanning the full WooCommerce orders table.
	 *
	 * @param string $from Y-m-d (previous-period start).
	 * @param string $to   Y-m-d inclusive.
	 * @return int[]
	 */
	private function insights_order_ids_from_plugin( $from, $to ) {
		global $wpdb;
		$from = (string) $from;
		$to   = (string) $to;
		$map  = array();
		foreach ( $this->order_ids_for_stay_window( $from, $to ) as $id ) {
			$map[ (int) $id ] = (int) $id;
		}
		$books = Database::bookings_table();
		$holds = Database::stay_holds_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin tables.
		$book_rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM $books WHERE order_id > 0 AND check_out > %s AND check_in <= %s", $from, $to ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin tables.
		$hold_rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM $holds WHERE order_id > 0 AND kind = %s AND check_out > %s AND check_in <= %s", Stay_Hold_Repository::KIND_BOOKING, $from, $to ) );
		foreach ( array_merge( is_array( $book_rows ) ? $book_rows : array(), is_array( $hold_rows ) ? $hold_rows : array() ) as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$map[ $id ] = $id;
			}
		}
		$child_table = Database::childcare_places_table();
		if ( self::table_exists( $child_table ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table.
			$child_rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM $child_table WHERE order_id > 0 AND ymd >= %s AND ymd <= %s", $from, $to ) );
			foreach ( is_array( $child_rows ) ? $child_rows : array() as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$map[ $id ] = $id;
				}
			}
		}
		$ids = array_values( $map );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/**
	 * @param array<string, string> $filters Filters.
	 * @param array<string, string> $prev    Previous range.
	 * @param array<string, mixed>  $current Finished current summary.
	 * @param array<string, mixed>  $prior   Finished prior summary.
	 * @return array<string, mixed>
	 */
	private function store_report( array $filters, array $prev, array $current, array $prior, array $stats = array() ) {
		$report = array(
			'filters'        => $filters,
			'previous_range' => $prev,
			'currency'       => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '€',
			'headline'       => $this->headline_with_change( $current, $prior ),
			'series'         => $current['series'],
			'payment_mix'    => $current['payment_mix'],
			'onsite_methods' => $current['onsite_methods'],
			'by_country'     => $current['by_country'],
			'country_payment'=> $current['country_payment'],
			'type_revenue'   => $current['type_revenue'],
			'unit_types'     => $current['unit_types'],
			'lead_time'      => $current['lead_time'],
			'stay_length'    => $current['stay_length'],
			'weekday'        => $current['weekday'],
			'hour'           => $current['hour'],
			'checkout_health'=> $current['checkout_health'],
			'returning'      => $current['returning'],
			'top_guests'     => $current['top_guests'],
			'money_to_chase' => $current['money_to_chase'],
			'upcoming_due'   => $current['upcoming_due'],
			'stats'          => $stats,
		);
		set_transient( self::cache_key( $filters ), $report, 15 * MINUTE_IN_SECONDS );
		return $report;
	}

	/**
	 * Minimal report used when aggregation fails.
	 *
	 * @return array<string, mixed>
	 */
	public static function empty_report() {
		$empty = array(
			'headline' => array(
				'booked'             => 0.0,
				'collected'          => 0.0,
				'due'                => 0.0,
				'refunded'           => 0.0,
				'avg_order'          => 0.0,
				'orders'             => 0,
				'paid_online_orders' => 0,
				'paid_online_share'  => 0.0,
			),
			'series'          => array(),
			'payment_mix'     => array(),
			'onsite_methods'  => array(),
			'by_country'      => array(),
			'country_payment' => array(),
			'type_revenue'    => array(),
			'unit_types'      => array(),
			'lead_time'       => array(),
			'stay_length'     => array(),
			'weekday'         => array(),
			'hour'            => array(),
			'checkout_health' => array(),
			'returning'       => array(),
			'top_guests'      => array(),
			'money_to_chase'  => array(),
			'upcoming_due'    => array(),
		);
		foreach ( array_keys( $empty['headline'] ) as $metric ) {
			$empty['headline'][ $metric . '_change' ] = null;
		}
		return array(
			'filters'        => array(
				'preset'   => 'last30',
				'from'     => wp_date( 'Y-m-d', strtotime( '-29 days' ) ),
				'to'       => wp_date( 'Y-m-d' ),
				'count_by' => 'order',
				'type'     => 'all',
			),
			'previous_range' => array(
				'from' => wp_date( 'Y-m-d', strtotime( '-59 days' ) ),
				'to'   => wp_date( 'Y-m-d', strtotime( '-30 days' ) ),
			),
			'currency'       => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '€',
			'headline'       => $empty['headline'],
			'series'         => $empty['series'],
			'payment_mix'    => $empty['payment_mix'],
			'onsite_methods' => $empty['onsite_methods'],
			'by_country'     => $empty['by_country'],
			'country_payment'=> $empty['country_payment'],
			'type_revenue'   => $empty['type_revenue'],
			'unit_types'     => $empty['unit_types'],
			'lead_time'      => $empty['lead_time'],
			'stay_length'    => $empty['stay_length'],
			'weekday'        => $empty['weekday'],
			'hour'           => $empty['hour'],
			'checkout_health'=> $empty['checkout_health'],
			'returning'      => $empty['returning'],
			'top_guests'     => $empty['top_guests'],
			'money_to_chase' => $empty['money_to_chase'],
			'upcoming_due'   => $empty['upcoming_due'],
		);
	}

	/**
	 * Clear all insights transients.
	 */
	public static function clear_cache() {
		global $wpdb;
		$like = $wpdb->esc_like( '_transient_vila_cb_insights_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @param string $preset Preset.
	 * @param string $from   Custom from.
	 * @param string $to     Custom to.
	 * @return array{from: string, to: string}
	 */
	private static function range_for_preset( $preset, $from, $to ) {
		$tz    = wp_timezone();
		$today = new \DateTime( 'today', $tz );
		switch ( $preset ) {
			case 'this_month':
				$start = ( clone $today )->modify( 'first day of this month' );
				$end   = $today;
				break;
			case 'season':
				$start = new \DateTime( $today->format( 'Y' ) . '-01-01', $tz );
				$end   = $today;
				break;
			case 'last_year':
				$year  = (int) $today->format( 'Y' ) - 1;
				$start = new \DateTime( $year . '-01-01', $tz );
				$end   = new \DateTime( $year . '-12-31', $tz );
				break;
			case 'all':
				$start = new \DateTime( '2020-01-01', $tz );
				$end   = $today;
				break;
			case 'custom':
				$try_from = Dates::parse_ymd( $from );
				$try_to   = Dates::parse_ymd( $to );
				$start    = $try_from ? $try_from : ( clone $today )->modify( '-29 days' );
				$end      = $try_to ? $try_to : $today;
				break;
			default:
				$start = ( clone $today )->modify( '-29 days' );
				$end   = $today;
				break;
		}
		if ( $start > $end ) {
			$tmp   = $start;
			$start = $end;
			$end   = $tmp;
		}
		return array(
			'from' => $start->format( 'Y-m-d' ),
			'to'   => $end->format( 'Y-m-d' ),
		);
	}

	/**
	 * WooCommerce order statuses safe for wc_get_orders().
	 *
	 * @return string[]
	 */
	private function insight_order_statuses() {
		$want = array( 'pending', 'processing', 'completed', 'on-hold', 'cancelled', 'failed', 'refunded', 'checkout-draft' );
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return $want;
		}
		$registered = array_map(
			static function ( $status ) {
				return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
			},
			array_keys( wc_get_order_statuses() )
		);
		return array_values( array_intersect( $want, $registered ) );
	}

	/**
	 * Orders whose campsite stay overlaps the reporting window (stay-date filtering).
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d inclusive.
	 * @return int[]
	 */
	private function order_ids_for_stay_window( $from, $to ) {
		global $wpdb;
		$from = (string) $from;
		$to   = (string) $to;
		$ids  = array();
		$books = Database::bookings_table();
		$holds = Database::stay_holds_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from plugin schema.
		$book_rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM $books WHERE order_id > 0 AND check_in <= %s AND check_out > %s", $to, $from ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from plugin schema.
		$hold_rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM $holds WHERE order_id > 0 AND kind = %s AND check_in <= %s AND check_out > %s", Stay_Hold_Repository::KIND_BOOKING, $to, $from ) );
		foreach ( array_merge( is_array( $book_rows ) ? $book_rows : array(), is_array( $hold_rows ) ? $hold_rows : array() ) as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}
		return array_values( $ids );
	}

	/**
	 * @param string $from Y-m-d (includes prior period start).
	 * @param string $to   Y-m-d inclusive.
	 * @return \WC_Order[]
	 */
	private function load_orders( $from, $to ) {
		return $this->load_order_objects( $this->collect_order_ids( $from, $to ) );
	}

	/**
	 * IDs of orders created in, or staying during, the window (cheap ID-only queries).
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d inclusive.
	 * @return int[]
	 */
	private function collect_order_ids( $from, $to ) {
		$ids = $this->page_created_order_ids( $from, $to, 0, 20000 );
		foreach ( $this->insights_order_ids_from_plugin( $from, $to ) as $id ) {
			$ids[] = $id;
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	private function gmt_bounds( $from, $to ) {
		$tz = wp_timezone();
		try {
			$start = new \DateTimeImmutable( $from . ' 00:00:00', $tz );
			$end   = new \DateTimeImmutable( $to . ' 23:59:59', $tz );
		} catch ( \Exception $e ) {
			return array(
				'start' => $from . ' 00:00:00',
				'end'   => $to . ' 23:59:59',
			);
		}
		$utc = new \DateTimeZone( 'UTC' );
		return array(
			'start' => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'end'   => $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		);
	}

	private function orders_use_hpos() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	private function sql_status_in_clause() {
		$items = array();
		foreach ( $this->insight_order_statuses() as $status ) {
			$status = sanitize_key( (string) $status );
			if ( '' === $status ) {
				continue;
			}
			if ( 0 !== strpos( $status, 'wc-' ) ) {
				$status = 'wc-' . $status;
			}
			$items[] = "'" . esc_sql( $status ) . "'";
		}
		return $items ? implode( ',', $items ) : "'wc-completed','wc-processing','wc-on-hold'";
	}

	private function count_created_orders( $from, $to ) {
		global $wpdb;
		$bounds = $this->gmt_bounds( $from, $to );
		$in     = $this->sql_status_in_clause();
		if ( $this->orders_use_hpos() ) {
			$table = $wpdb->prefix . 'wc_orders';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table + sanitized status list.
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE type = 'shop_order' AND status IN ($in) AND date_created_gmt >= %s AND date_created_gmt <= %s", $bounds['start'], $bounds['end'] ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- sanitized status list.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'shop_order' AND post_status IN ($in) AND post_date_gmt >= %s AND post_date_gmt <= %s", $bounds['start'], $bounds['end'] ) );
	}

	private function page_created_order_ids( $from, $to, $offset, $limit ) {
		global $wpdb;
		$bounds = $this->gmt_bounds( $from, $to );
		$in     = $this->sql_status_in_clause();
		$offset = max( 0, (int) $offset );
		$limit  = max( 1, (int) $limit );
		if ( $this->orders_use_hpos() ) {
			$table = $wpdb->prefix . 'wc_orders';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table + sanitized status list.
			$col = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE type = 'shop_order' AND status IN ($in) AND date_created_gmt >= %s AND date_created_gmt <= %s ORDER BY id ASC LIMIT %d OFFSET %d", $bounds['start'], $bounds['end'], $limit, $offset ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- sanitized status list.
			$col = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type = 'shop_order' AND post_status IN ($in) AND post_date_gmt >= %s AND post_date_gmt <= %s ORDER BY ID ASC LIMIT %d OFFSET %d", $bounds['start'], $bounds['end'], $limit, $offset ) );
		}
		return array_map( 'intval', is_array( $col ) ? $col : array() );
	}

	/**
	 * Same page of order IDs through WooCommerce's own query (fallback when the direct SQL finds none).
	 */
	private function page_created_order_ids_via_wc( $from, $to, $offset, $limit ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$ids = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => $this->insight_order_statuses(),
				'date_created' => $from . '...' . $to,
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'limit'        => max( 1, (int) $limit ),
				'offset'       => max( 0, (int) $offset ),
				'return'       => 'ids',
			)
		);
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	private function extra_stay_order_ids( $from, $to ) {
		$stay = $this->order_ids_for_stay_window( $from, $to );
		if ( empty( $stay ) ) {
			return array();
		}
		$in_range = array_fill_keys( $this->created_ids_among( $stay, $from, $to ), true );
		$extra    = array();
		foreach ( $stay as $id ) {
			if ( ! isset( $in_range[ $id ] ) ) {
				$extra[] = $id;
			}
		}
		return $extra;
	}

	private function created_ids_among( array $ids, $from, $to ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$bounds = $this->gmt_bounds( $from, $to );
		$in_ids = implode( ',', $ids );
		$in_st  = $this->sql_status_in_clause();
		if ( $this->orders_use_hpos() ) {
			$table = $wpdb->prefix . 'wc_orders';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table, int IDs, sanitized statuses.
			$col = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE id IN ($in_ids) AND type = 'shop_order' AND status IN ($in_st) AND date_created_gmt >= %s AND date_created_gmt <= %s", $bounds['start'], $bounds['end'] ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- int IDs, sanitized statuses.
			$col = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE ID IN ($in_ids) AND post_type = 'shop_order' AND post_status IN ($in_st) AND post_date_gmt >= %s AND post_date_gmt <= %s", $bounds['start'], $bounds['end'] ) );
		}
		return array_map( 'intval', is_array( $col ) ? $col : array() );
	}

	/**
	 * @param int[] $ids Order IDs.
	 * @return \WC_Order[]
	 */
	/**
	 * Short explanation of why an order ID could not be loaded (for the status line).
	 */
	private function describe_unloadable_order( $id ) {
		global $wpdb;
		$what = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
		$kind = is_object( $what ) ? get_class( $what ) : var_export( $what, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT type, status FROM {$wpdb->prefix}wc_orders WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$post = $wpdb->get_row( $wpdb->prepare( "SELECT post_type, post_status FROM $wpdb->posts WHERE ID = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return sprintf(
			'#%d wc_get_order=%s, wc_orders=%s, posts=%s',
			$id,
			$kind,
			$row ? $row['type'] . '/' . $row['status'] : 'none',
			$post ? $post['post_type'] . '/' . $post['post_status'] : 'none'
		);
	}

	private function load_order_objects( array $ids ) {
		$orders = array();
		if ( ! function_exists( 'wc_get_order' ) ) {
			return $orders;
		}
		// wc_get_orders( 'include' ) is not honoured by the HPOS order tables; load each ID directly.
		foreach ( array_values( $ids ) as $id ) {
			$order = wc_get_order( (int) $id );
			if ( $order instanceof \WC_Order && ! $order instanceof \WC_Order_Refund ) {
				$orders[] = $order;
			}
		}

		usort(
			$orders,
			static function ( $a, $b ) {
				$ta = $a instanceof \WC_Order && $a->get_date_created() ? $a->get_date_created()->getTimestamp() : 0;
				$tb = $b instanceof \WC_Order && $b->get_date_created() ? $b->get_date_created()->getTimestamp() : 0;
				return $ta <=> $tb;
			}
		);

		return $orders;
	}

	/**
	 * @param \WC_Order $order Order.
	 * @return string Y-m-d or empty.
	 */
	private function order_day_ymd( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}
		$created = $order->get_date_created();
		if ( ! $created ) {
			return '';
		}
		if ( function_exists( 'wc_format_datetime' ) ) {
			return (string) wc_format_datetime( $created, 'Y-m-d' );
		}
		return $created->date( 'Y-m-d' );
	}

	private static function table_exists( $table ) {
		global $wpdb;
		$key    = 'vila_cb_table_exists_' . md5( (string) $table );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return 'yes' === $cached;
		}
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		set_transient( $key, $exists ? 'yes' : 'no', DAY_IN_SECONDS );
		return $exists;
	}

	/**
	 * @param int[]|null $order_ids Limit to these orders (null = all).
	 * @return array<string, mixed>
	 */
	private function booking_maps( $order_ids = null ) {
		global $wpdb;
		$maps = array(
			'by_order'     => array(),
			'line_types'   => array(),
			'stay_dates'   => array(),
		);
		$where = 'order_id > 0';
		if ( is_array( $order_ids ) ) {
			$order_ids = array_values( array_filter( array_map( 'intval', $order_ids ) ) );
			if ( empty( $order_ids ) ) {
				return $maps;
			}
			$where .= ' AND order_id IN (' . implode( ',', $order_ids ) . ')';
		}
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from schema, IDs cast to int.
		$bookings    = $wpdb->get_results( 'SELECT order_id, line_item_id, check_in, check_out FROM ' . Database::bookings_table() . " WHERE $where", ARRAY_A );
		$holds       = $wpdb->get_results( 'SELECT order_id, line_item_id, check_in, check_out FROM ' . Database::stay_holds_table() . " WHERE $where AND kind = 'booking'", ARRAY_A );
		$child_table = Database::childcare_places_table();
		$child       = self::table_exists( $child_table )
			? $wpdb->get_results( "SELECT DISTINCT order_id, line_item_id FROM $child_table WHERE $where", ARRAY_A )
			: array();
		// phpcs:enable
		foreach ( is_array( $bookings ) ? $bookings : array() as $row ) {
			$oid = (int) $row['order_id'];
			$lid = (int) $row['line_item_id'];
			$maps['by_order'][ $oid ][] = 'pitch';
			$maps['line_types'][ $oid . ':' . $lid ] = 'pitch';
			$maps['stay_dates'][ $oid ] = array( 'check_in' => (string) $row['check_in'], 'check_out' => (string) $row['check_out'] );
		}
		foreach ( is_array( $holds ) ? $holds : array() as $row ) {
			$oid = (int) $row['order_id'];
			$lid = (int) $row['line_item_id'];
			$maps['by_order'][ $oid ][] = 'stay';
			$maps['line_types'][ $oid . ':' . $lid ] = 'stay';
			$maps['stay_dates'][ $oid ] = array( 'check_in' => (string) $row['check_in'], 'check_out' => (string) $row['check_out'] );
		}
		foreach ( is_array( $child ) ? $child : array() as $row ) {
			$maps['line_types'][ (int) $row['order_id'] . ':' . (int) $row['line_item_id'] ] = 'childcare';
		}
		return $maps;
	}

	/**
	 * @param \WC_Order[]          $orders Orders.
	 * @param array<string, mixed> $maps   Maps.
	 * @param string               $from   From Y-m-d.
	 * @param string               $to     To Y-m-d inclusive.
	 * @param string               $count_by order|stay.
	 * @param string               $filter_type Type filter.
	 * @return array<string, mixed>
	 */
	private function summarize_period( array $orders, array $maps, $from, $to, $count_by, $filter_type ) {
		$out = $this->empty_summary( $from, $to );
		foreach ( $orders as $order ) {
			$this->add_order( $out, $order, $maps, $from, $to, $count_by, $filter_type );
		}
		return $this->finish_summary( $out, $from, $to, true );
	}

	/**
	 * Add one order to a running period summary.
	 *
	 * @param array<string, mixed> $out         Summary (by reference).
	 * @param \WC_Order            $order       Order.
	 * @param array<string, mixed> $maps        Maps.
	 * @param string               $from        From Y-m-d.
	 * @param string               $to          To Y-m-d inclusive.
	 * @param string               $count_by    order|stay.
	 * @param string               $filter_type Type filter.
	 */
	private function add_order( array &$out, $order, array $maps, $from, $to, $count_by, $filter_type ) {
		$oid       = (int) $order->get_id();
		$order_day = $this->order_day_ymd( $order );
		$stay_day  = isset( $maps['stay_dates'][ $oid ]['check_in'] ) ? (string) $maps['stay_dates'][ $oid ]['check_in'] : $order_day;
		$day       = 'stay' === $count_by ? $stay_day : $order_day;
		$state     = Order_Payment_Status::get_state( $order );
		$types     = $this->order_types( $order, $maps );
		if ( 'all' !== $filter_type && ! in_array( $filter_type, $types, true ) ) {
			return 'wrong_type';
		}

		if ( $this->date_in_range( $order_day, $from, $to ) ) {
			$health_key = in_array( $order->get_status(), array( 'processing', 'completed', 'on-hold' ), true ) ? 'completed' : ( in_array( $order->get_status(), array( 'pending', 'checkout-draft' ), true ) ? 'abandoned' : 'failed_cancelled' );
			$this->bump_series( $out['checkout_health'], $order_day, $health_key, 1 );
		}

		if ( ! $this->date_in_range( $day, $from, $to ) ) {
			return 'out_of_range';
		}
		if ( Order_Payment_Status::STATE_NOT_BOOKED === $state ) {
			return 'not_booked';
		}
		$total    = max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() );
		$paid    = in_array( $state, array( Order_Payment_Status::STATE_PAID_ONLINE, Order_Payment_Status::STATE_PAID_ON_SITE ), true );
		$country = strtoupper( (string) $order->get_billing_country() );
		$country = '' !== $country ? $country : __( 'Unknown', 'vila-campsite-booking' );
		$email   = strtolower( trim( (string) $order->get_billing_email() ) );
		$name    = trim( (string) $order->get_formatted_billing_full_name() );
		$name    = '' !== $name ? $name : '#' . $order->get_order_number();

		$out['headline']['booked'] += $total;
		$out['headline']['orders'] += 1;
		$out['headline']['refunded'] += (float) $order->get_total_refunded();
		$out['headline'][ $paid ? 'collected' : 'due' ] += $total;
		if ( Order_Payment_Status::STATE_PAID_ONLINE === $state ) {
			$out['headline']['paid_online_orders'] += 1;
		}
		$this->bump_series( $out['series'], $day, $this->series_key_for_state( $state ), $total );
		$this->bump_amount_count( $out['payment_mix'], $order->get_payment_method() ? $order->get_payment_method_title() : __( 'No method', 'vila-campsite-booking' ), $total );
		if ( Order_Payment_Status::STATE_PAID_ON_SITE === $state ) {
			$method = (string) $order->get_meta( Order_Payment_Status::META_METHOD );
			$methods = Order_Payment_Status::manual_methods();
			$this->bump_amount_count( $out['onsite_methods'], $methods[ $method ] ?? $method, $total );
		}
		$this->bump_amount_count( $out['by_country'], $country, $total );
		$this->bump_country_payment( $out['country_payment'], $country, $paid ? 'paid' : 'unpaid', $total );
		foreach ( $this->line_type_totals( $order, $maps ) as $type => $amount ) {
			$this->bump_amount_count( $out['type_revenue'], $type, $amount );
		}
		$unit = $this->unit_type_for_order( $order, $maps );
		if ( '' !== $unit ) {
			$this->bump_amount_count( $out['unit_types'], $unit, $total );
		}
		$this->histogram_bump( $out['lead_time'], $this->lead_days( $order_day, $stay_day ) );
		$this->histogram_bump( $out['stay_length'], $this->stay_nights( $maps['stay_dates'][ $oid ] ?? array() ) );
		if ( '' !== $order_day ) {
			$this->bump_simple( $out['weekday'], wp_date( 'D', strtotime( $order_day ) ), 1 );
		}
		$created = $order->get_date_created();
		if ( $created ) {
			$hour = function_exists( 'wc_format_datetime' )
				? wc_format_datetime( $created, 'G' ) . ':00'
				: $created->date( 'G' ) . ':00';
		} else {
			$hour = __( 'Unknown', 'vila-campsite-booking' );
		}
		$this->bump_simple( $out['hour'], $hour, 1 );
		if ( '' !== $email ) {
			$out['emails'][ $email ] = ( $out['emails'][ $email ] ?? 0 ) + 1;
		}
		$out['top_guests'][ $email ?: $name ]['name'] = $name;
		$out['top_guests'][ $email ?: $name ]['amount'] = ( $out['top_guests'][ $email ?: $name ]['amount'] ?? 0 ) + $total;
		$out['top_guests'][ $email ?: $name ]['orders'] = ( $out['top_guests'][ $email ?: $name ]['orders'] ?? 0 ) + 1;
		if ( Order_Payment_Status::STATE_UNPAID === $state && $this->has_checked_out( $maps['stay_dates'][ $oid ] ?? array() ) ) {
			$out['money_to_chase'][] = array( 'name' => $name, 'amount' => $total, 'order_id' => $oid, 'order_number' => $order->get_order_number(), 'check_out' => $maps['stay_dates'][ $oid ]['check_out'] ?? '' );
		}
		if ( Order_Payment_Status::STATE_UNPAID === $state && isset( $maps['stay_dates'][ $oid ]['check_in'] ) && $maps['stay_dates'][ $oid ]['check_in'] >= wp_date( 'Y-m-d' ) ) {
			$this->bump_series( $out['upcoming_due'], substr( $maps['stay_dates'][ $oid ]['check_in'], 0, 7 ), 'due', $total );
		}
		return 'counted';
	}

	/**
	 * Finalise a running summary for output.
	 *
	 * @param array<string, mixed> $out Summary.
	 * @return array<string, mixed>
	 */
	private function finish_summary( array $out, $from = '', $to = '', $merge_series = true ) {
		$emails = isset( $out['emails'] ) ? (array) $out['emails'] : array();
		unset( $out['emails'] );
		if ( $merge_series && '' !== $from && '' !== $to ) {
			$out['series'] = $this->merge_series_range( $out['series'], $from, $to );
		} else {
			$rows = array();
			foreach ( (array) $out['series'] as $label => $row ) {
				if ( is_array( $row ) && isset( $row['label'] ) ) {
					$rows[] = $row;
				} elseif ( is_array( $row ) ) {
					$row['label'] = (string) $label;
					$rows[]       = $row;
				}
			}
			usort(
				$rows,
				static function ( $a, $b ) {
					return strcmp( (string) ( $a['label'] ?? '' ), (string) ( $b['label'] ?? '' ) );
				}
			);
			$out['series'] = $rows;
		}
		$out['headline']['avg_order'] = $out['headline']['orders'] > 0 ? $out['headline']['booked'] / $out['headline']['orders'] : 0;
		$out['headline']['paid_online_share'] = $out['headline']['orders'] > 0 ? ( $out['headline']['paid_online_orders'] / $out['headline']['orders'] ) * 100 : 0;
		$out['returning'] = array(
			array( 'label' => __( 'New guests', 'vila-campsite-booking' ), 'count' => count( array_filter( $emails, static function ( $c ) { return 1 === (int) $c; } ) ) ),
			array( 'label' => __( 'Returning guests', 'vila-campsite-booking' ), 'count' => count( array_filter( $emails, static function ( $c ) { return (int) $c > 1; } ) ) ),
		);
		$this->sort_and_trim( $out );
		return $out;
	}

	/**
	 * @param string $from From.
	 * @param string $to   To.
	 * @return array{from: string, to: string}
	 */
	private function previous_range( $from, $to ) {
		$start = Dates::parse_ymd( $from );
		$end   = Dates::parse_ymd( $to );
		if ( ! $start || ! $end ) {
			return array( 'from' => $from, 'to' => $to );
		}
		$days = max( 1, (int) $start->diff( $end )->format( '%a' ) + 1 );
		return array(
			'from' => ( clone $start )->modify( '-' . $days . ' days' )->format( 'Y-m-d' ),
			'to'   => ( clone $start )->modify( '-1 day' )->format( 'Y-m-d' ),
		);
	}

	/**
	 * @param array<string, mixed> $current Current summary.
	 * @param array<string, mixed> $prior   Prior summary.
	 * @return array<string, mixed>
	 */
	private function headline_with_change( array $current, array $prior ) {
		$head = $current['headline'];
		foreach ( array_keys( $head ) as $key ) {
			if ( false !== strpos( (string) $key, '_change' ) ) {
				continue;
			}
			$value = isset( $head[ $key ] ) ? (float) $head[ $key ] : 0.0;
			$old   = isset( $prior['headline'][ $key ] ) ? (float) $prior['headline'][ $key ] : 0.0;
			$head[ $key . '_change' ] = $old > 0 ? ( ( $value - $old ) / $old ) * 100 : null;
		}
		return $head;
	}

	/**
	 * @param string $from From.
	 * @param string $to   To.
	 * @return array<string, mixed>
	 */
	private function empty_summary( $from, $to, $prefill_series = true ) {
		return array(
			'headline' => array( 'booked' => 0.0, 'collected' => 0.0, 'due' => 0.0, 'refunded' => 0.0, 'avg_order' => 0.0, 'orders' => 0, 'paid_online_orders' => 0, 'paid_online_share' => 0.0 ),
			'series' => $prefill_series ? $this->empty_series( $from, $to, array( 'paid_online', 'paid_on_site', 'unpaid' ) ) : array(),
			'payment_mix' => array(),
			'onsite_methods' => array(),
			'by_country' => array(),
			'country_payment' => array(),
			'type_revenue' => array(),
			'unit_types' => array(),
			'lead_time' => array(),
			'stay_length' => array(),
			'weekday' => array(),
			'hour' => array(),
			'checkout_health' => array(),
			'returning' => array(),
			'top_guests' => array(),
			'money_to_chase' => array(),
			'upcoming_due' => array(),
		);
	}

	/**
	 * @param string   $from From.
	 * @param string   $to   To.
	 * @param string[] $keys Series keys.
	 * @return array<int, array<string, mixed>>
	 */
	/**
	 * @param array<string, mixed>|array<int, array<string, mixed>> $series Sparse or pre-filled rows.
	 * @param string $from From Y-m-d.
	 * @param string $to   To Y-m-d inclusive.
	 * @return array<int, array<string, mixed>>
	 */
	private function merge_series_range( $series, $from, $to ) {
		$keys = array( 'paid_online', 'paid_on_site', 'unpaid' );
		$base = $this->empty_series( $from, $to, $keys );
		if ( ! is_array( $series ) ) {
			return array_values( $base );
		}
		foreach ( $series as $label => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$day = isset( $row['label'] ) ? (string) $row['label'] : (string) $label;
			if ( ! isset( $base[ $day ] ) ) {
				continue;
			}
			foreach ( $keys as $key ) {
				if ( isset( $row[ $key ] ) ) {
					$base[ $day ][ $key ] = (float) $row[ $key ];
				}
			}
		}
		return array_values( $base );
	}

	private function empty_series( $from, $to, array $keys ) {
		$start = Dates::parse_ymd( $from );
		$end   = Dates::parse_ymd( $to );
		$out   = array();
		if ( ! $start || ! $end ) {
			return $out;
		}
		$days = (int) $start->diff( $end )->format( '%a' ) + 1;
		if ( $days > 800 ) {
			for ( $d = clone $start; $d <= $end; $d->modify( '+7 days' ) ) {
				$row = array( 'label' => $d->format( 'Y-m-d' ) );
				foreach ( $keys as $key ) {
					$row[ $key ] = 0;
				}
				$out[ $row['label'] ] = $row;
			}
			return $out;
		}
		for ( $d = clone $start; $d <= $end; $d->modify( '+1 day' ) ) {
			$row = array( 'label' => $d->format( 'Y-m-d' ) );
			foreach ( $keys as $key ) {
				$row[ $key ] = 0;
			}
			$out[ $row['label'] ] = $row;
		}
		return $out;
	}

	private function date_in_range( $day, $from, $to ) {
		return '' !== $day && $day >= $from && $day <= $to;
	}

	private function series_key_for_state( $state ) {
		return Order_Payment_Status::STATE_PAID_ONLINE === $state ? 'paid_online' : ( Order_Payment_Status::STATE_PAID_ON_SITE === $state ? 'paid_on_site' : 'unpaid' );
	}

	private function bump_series( array &$series, $label, $key, $amount ) {
		if ( ! isset( $series[ $label ] ) ) {
			$series[ $label ] = array( 'label' => $label );
		}
		$series[ $label ][ $key ] = ( $series[ $label ][ $key ] ?? 0 ) + $amount;
	}

	private function bump_amount_count( array &$bucket, $label, $amount ) {
		$label = '' !== (string) $label ? (string) $label : __( 'Unknown', 'vila-campsite-booking' );
		$bucket[ $label ]['label'] = $label;
		$bucket[ $label ]['amount'] = ( $bucket[ $label ]['amount'] ?? 0 ) + (float) $amount;
		$bucket[ $label ]['count'] = ( $bucket[ $label ]['count'] ?? 0 ) + 1;
	}

	private function bump_simple( array &$bucket, $label, $amount ) {
		$bucket[ $label ] = ( $bucket[ $label ] ?? 0 ) + $amount;
	}

	private function bump_country_payment( array &$bucket, $country, $key, $amount ) {
		if ( ! isset( $bucket[ $country ] ) ) {
			$bucket[ $country ] = array( 'label' => $country, 'paid' => 0.0, 'unpaid' => 0.0 );
		}
		$bucket[ $country ][ $key ] += (float) $amount;
	}

	private function histogram_bump( array &$bucket, $value ) {
		$label = $value < 0 ? __( 'Unknown', 'vila-campsite-booking' ) : (string) $value;
		$this->bump_simple( $bucket, $label, 1 );
	}

	private function order_types( $order, array $maps ) {
		$types = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}
			$type = $this->line_type( $order, $item_id, $item, $maps );
			if ( 'other' !== $type ) {
				$types[] = $type;
			}
		}
		return array_values( array_unique( $types ? $types : array( 'other' ) ) );
	}

	private function line_type_totals( $order, array $maps ) {
		$out = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}
			$type = $this->line_type( $order, $item_id, $item, $maps );
			$out[ $type ] = ( $out[ $type ] ?? 0 ) + ( is_callable( array( $item, 'get_total' ) ) ? (float) $item->get_total() : 0.0 );
		}
		return $out;
	}

	private function line_type( $order, $item_id, $item, array $maps ) {
		$key = (int) $order->get_id() . ':' . (int) $item_id;
		if ( isset( $maps['line_types'][ $key ] ) ) {
			return $maps['line_types'][ $key ];
		}
		static $meal_pid = null;
		static $childcare_pid = null;
		if ( null === $meal_pid ) {
			$meal_pid      = (int) Meal_Booking::get_product_id();
			$childcare_pid = (int) Childcare_Booking::get_product_id();
		}
		$product_id = is_callable( array( $item, 'get_product_id' ) ) ? (int) $item->get_product_id() : 0;
		if ( $product_id > 0 && $product_id === $meal_pid ) {
			return 'meal';
		}
		if ( $product_id > 0 && $product_id === $childcare_pid ) {
			return 'childcare';
		}
		static $product_types = array();
		if ( $product_id > 0 ) {
			if ( ! isset( $product_types[ $product_id ] ) ) {
				$product_types[ $product_id ] = WooCommerce_Integration::is_stay_product( $product_id )
					? 'stay'
					: ( WooCommerce_Integration::is_campsite_product( $product_id ) ? 'pitch' : '' );
			}
			if ( '' !== $product_types[ $product_id ] ) {
				return $product_types[ $product_id ];
			}
		}
		if ( is_callable( array( $item, 'get_meta' ) ) ) {
			if ( '' !== (string) $item->get_meta( Meal_Booking::META_PURCHASE, true ) ) {
				return 'meal';
			}
			if ( '' !== (string) $item->get_meta( Childcare_Booking::META_PURCHASE, true ) ) {
				return 'childcare';
			}
		}
		return 'other';
	}

	/**
	 * Unit type from already-loaded line meta (the full WooCommerce_Integration lookup re-reads
	 * meta from the database and writes debug logs, which is far too slow per order here).
	 */
	private function unit_type_for_order( $order, array $maps ) {
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! is_object( $item ) || ! is_callable( array( $item, 'get_meta' ) ) ) {
				continue;
			}
			if ( 'pitch' !== $this->line_type( $order, $item_id, $item, $maps ) ) {
				continue;
			}
			foreach ( array( 'Unit type', 'Extras', __( 'Unit type', 'vila-akoya' ), __( 'Extras', 'vila-akoya' ) ) as $meta_key ) {
				$value = $item->get_meta( $meta_key, true );
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					return trim( wp_strip_all_tags( $value ) );
				}
			}
			$slugs = $item->get_meta( '_vila_vehicle_slugs', true );
			$slugs = is_string( $slugs ) ? json_decode( $slugs, true ) : $slugs;
			if ( is_array( $slugs ) && ! empty( $slugs ) ) {
				return implode( ', ', array_map( static function ( $slug ) { return ucwords( str_replace( array( '-', '_' ), ' ', (string) $slug ) ); }, $slugs ) );
			}
			return __( 'Pitch', 'vila-campsite-booking' );
		}
		return '';
	}

	private function lead_days( $order_day, $stay_day ) {
		$o = Dates::parse_ymd( $order_day );
		$s = Dates::parse_ymd( $stay_day );
		return ( $o && $s ) ? (int) $o->diff( $s )->format( '%r%a' ) : -1;
	}

	private function stay_nights( array $dates ) {
		return isset( $dates['check_in'], $dates['check_out'] ) ? Dates::night_count( $dates['check_in'], $dates['check_out'] ) : -1;
	}

	private function has_checked_out( array $dates ) {
		return isset( $dates['check_out'] ) && '' !== $dates['check_out'] && $dates['check_out'] < wp_date( 'Y-m-d' );
	}

	private function sort_and_trim( array &$out ) {
		foreach ( array( 'payment_mix', 'onsite_methods', 'by_country', 'type_revenue', 'unit_types' ) as $key ) {
			uasort( $out[ $key ], static function ( $a, $b ) { return (float) $b['amount'] <=> (float) $a['amount']; } );
			$out[ $key ] = array_slice( array_values( $out[ $key ] ), 0, 12 );
		}
		$out['series'] = array_values( $out['series'] );
		$out['checkout_health'] = array_values( $out['checkout_health'] );
		$out['upcoming_due'] = array_values( $out['upcoming_due'] );
		$out['country_payment'] = array_slice( array_values( $out['country_payment'] ), 0, 12 );
		foreach ( array( 'lead_time', 'stay_length', 'weekday', 'hour' ) as $key ) {
			ksort( $out[ $key ] );
			$out[ $key ] = array_map(
				static function ( $label, $count ) {
					return array( 'label' => (string) $label, 'count' => (int) $count );
				},
				array_keys( $out[ $key ] ),
				$out[ $key ]
			);
		}
		uasort( $out['top_guests'], static function ( $a, $b ) { return (float) $b['amount'] <=> (float) $a['amount']; } );
		$out['top_guests'] = array_slice( array_values( $out['top_guests'] ), 0, 10 );
		usort( $out['money_to_chase'], static function ( $a, $b ) { return (float) $b['amount'] <=> (float) $a['amount']; } );
		$out['money_to_chase'] = array_slice( $out['money_to_chase'], 0, 20 );
	}
}
