<?php
/**
 * wp-admin insights page.
 *
 * The page shell renders immediately; report data is fetched over admin-ajax so a slow
 * or failing aggregation never blocks (or 500s) the whole admin screen.
 *
 * @package Vila_Campsite_Booking
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce → Campsite · Insights.
 */
class Admin_Insights {

	const PAGE        = 'vila-cb-insights';
	const AJAX_ACTION = 'vila_cb_insights_data';
	const NONCE       = 'vila_cb_insights';
	const BUILD       = '1.6.20';

	/**
	 * Whether the AJAX handler already sent its response.
	 *
	 * @var bool
	 */
	private static $responded = false;

	/**
	 * Where the previous insights request stopped, if it never answered.
	 *
	 * @var string
	 */
	private static $last_stuck = '';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 63 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_data' ) );
	}

	/**
	 * Register submenu.
	 */
	public static function register_menu() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_submenu_page(
			'woocommerce',
			__( 'Campsite insights', 'vila-campsite-booking' ),
			__( 'Campsite · Insights', 'vila-campsite-booking' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * @return array<string, string>
	 */
	private static function filters_from_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$src = $_REQUEST;
		return Insights_Service::sanitize_filters(
			array(
				'preset'   => isset( $src['preset'] ) ? sanitize_key( wp_unslash( Request_String::scalar( $src['preset'] ) ) ) : 'last30',
				'from'     => isset( $src['from'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $src['from'] ) ) ) : '',
				'to'       => isset( $src['to'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $src['to'] ) ) ) : '',
				'count_by' => isset( $src['count_by'] ) ? sanitize_key( wp_unslash( Request_String::scalar( $src['count_by'] ) ) ) : 'order',
				'type'     => isset( $src['type'] ) ? sanitize_key( wp_unslash( Request_String::scalar( $src['type'] ) ) ) : 'all',
			)
		);
		// phpcs:enable
	}

	/**
	 * Render page shell (no report work here).
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		wp_enqueue_style( 'vila-cb-admin', VILA_CB_URL . 'assets/css/admin.css', array(), VILA_CB_VERSION );
		wp_enqueue_script( 'vila-cb-chart', VILA_CB_URL . 'assets/js/vendor/chart.umd.min.js', array(), VILA_CB_VERSION, true );
		wp_enqueue_script( 'vila-cb-admin-insights', VILA_CB_URL . 'assets/js/admin-insights.js', array( 'vila-cb-chart' ), VILA_CB_VERSION, true );

		$filters = self::filters_from_request();
		wp_add_inline_script(
			'vila-cb-admin-insights',
			'window.vilaCbInsightsConfig = ' . wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'action'  => self::AJAX_ACTION,
					'nonce'   => wp_create_nonce( self::NONCE ),
					'filters' => $filters,
					'build'   => self::BUILD,
				)
			) . ';',
			'before'
		);
		?>
		<div class="wrap vila-cb-admin vila-cb-insights">
			<?php Admin::render_section_nav( self::PAGE ); ?>
			<div class="vila-cb-ui-hero">
				<div class="vila-cb-ui-hero__text">
					<h1 class="vila-cb-ui-title"><?php esc_html_e( 'Campsite · Insights', 'vila-campsite-booking' ); ?></h1>
					<p class="vila-cb-ui-lead"><?php esc_html_e( 'Income, payment collection, visitor countries, booking behaviour, and checkout health from WooCommerce booking data.', 'vila-campsite-booking' ); ?></p>
				</div>
			</div>

			<form method="get" class="vila-cb-ui-panel vila-cb-insights-filter" id="vila-cb-insights-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<label>
					<?php esc_html_e( 'Date range', 'vila-campsite-booking' ); ?>
					<select name="preset">
						<?php foreach ( Insights_Service::presets() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['preset'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'From', 'vila-campsite-booking' ); ?>
					<input type="date" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>" />
				</label>
				<label>
					<?php esc_html_e( 'To', 'vila-campsite-booking' ); ?>
					<input type="date" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>" />
				</label>
				<label>
					<?php esc_html_e( 'Count by', 'vila-campsite-booking' ); ?>
					<select name="count_by">
						<option value="order" <?php selected( $filters['count_by'], 'order' ); ?>><?php esc_html_e( 'Order date', 'vila-campsite-booking' ); ?></option>
						<option value="stay" <?php selected( $filters['count_by'], 'stay' ); ?>><?php esc_html_e( 'Stay date', 'vila-campsite-booking' ); ?></option>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Type', 'vila-campsite-booking' ); ?>
					<select name="type">
						<?php foreach ( Insights_Service::type_options() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="vila-cb-insights-filter__check">
					<span><input type="checkbox" name="refresh" value="1" /> <?php esc_html_e( 'Skip cache', 'vila-campsite-booking' ); ?></span>
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Update insights', 'vila-campsite-booking' ); ?></button>
			</form>

			<div id="vila-cb-insights-status" class="vila-cb-ui-panel vila-cb-insights-status is-loading" role="status" aria-live="polite">
				<span class="spinner is-active"></span>
				<span class="vila-cb-insights-status__text"><?php echo esc_html( sprintf( __( 'Loading insights… (%s)', 'vila-campsite-booking' ), self::BUILD ) ); ?></span>
				<span class="vila-cb-insights-status__timer"></span>
			</div>

			<div id="vila-cb-insights-body" hidden>
				<div class="vila-cb-kpis" id="vila-cb-insights-kpis"></div>

				<div class="vila-cb-insights-grid">
					<?php self::chart_panel( 'incomeOverTime', __( 'Income over time', 'vila-campsite-booking' ) ); ?>
					<?php self::chart_panel( 'paymentMix', __( 'How people choose to pay', 'vila-campsite-booking' ) ); ?>
					<?php self::chart_panel( 'onsiteMethods', __( 'On-site payment methods', 'vila-campsite-booking' ) ); ?>
					<?php self::chart_panel( 'countryRevenue', __( 'Revenue by visitor country', 'vila-campsite-booking' ) ); ?>
					<?php self::chart_panel( 'countryPayment', __( 'Online vs on-arrival by country', 'vila-campsite-booking' ) ); ?>
					<?php self::chart_panel( 'typeRevenue', __( 'What sells', 'vila-campsite-booking' ) ); ?>
					<?php self::chart_panel( 'unitTypes', __( 'Pitch unit types', 'vila-campsite-booking' ) ); ?>
					<?php self::chart_panel( 'leadTime', __( 'Lead time (days before arrival)', 'vila-campsite-booking' ) ); ?>
					<?php self::chart_panel( 'stayLength', __( 'Length of stay (nights)', 'vila-campsite-booking' ) ); ?>
					<?php self::chart_panel( 'weekday', __( 'Bookings by weekday', 'vila-campsite-booking' ) ); ?>
					<?php self::chart_panel( 'hour', __( 'Bookings by hour', 'vila-campsite-booking' ) ); ?>
					<?php self::chart_panel( 'checkoutHealth', __( 'Checkout health', 'vila-campsite-booking' ) ); ?>
					<?php self::chart_panel( 'returningGuests', __( 'New vs returning guests', 'vila-campsite-booking' ) ); ?>
					<?php self::chart_panel( 'upcomingDue', __( 'Upcoming pay-on-arrival income', 'vila-campsite-booking' ) ); ?>
				</div>

				<div class="vila-cb-insights-grid" id="vila-cb-insights-tables"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: build the report and return KPI/table HTML plus chart data.
	 */
	public static function ajax_data() {
		self::unhook_slow_shutdown();
		$started = microtime( true );
		$boot_ms = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (int) round( ( $started - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000 ) : 0;

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			self::send_json( false, array( 'message' => __( 'You are not allowed to view insights.', 'vila-campsite-booking' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		register_shutdown_function( array( __CLASS__, 'ajax_fatal_guard' ) );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 45 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$last       = Insights_Service::read_diag();
		$last_stuck = '';
		if ( $last && 'sent' !== $last['phase'] ) {
			$last_stuck = sprintf(
				/* translators: 1: phase, 2: detail, 3: seconds */
				__( 'Previous attempt stopped while %1$s%2$s, %3$ds after it started.', 'vila-campsite-booking' ),
				$last['phase'],
				'' !== $last['extra'] ? ' (' . $last['extra'] . ')' : '',
				(int) round( (float) $last['at'] - (float) $last['started'] )
			);
		}
		Insights_Service::diag( empty( $_REQUEST['job'] ) ? 'starting job' : 'starting step' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::$last_stuck = $last_stuck;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified above.
		$job_id = isset( $_REQUEST['job'] ) ? sanitize_key( wp_unslash( Request_String::scalar( $_REQUEST['job'] ) ) ) : '';
		$fresh  = ! empty( $_REQUEST['refresh'] );
		$batch  = isset( $_REQUEST['batch'] ) ? absint( $_REQUEST['batch'] ) : 25;
		// phpcs:enable
		$filters = self::filters_from_request();
		$service = new Insights_Service();

		if ( ! empty( $_REQUEST['render'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$report = Insights_Service::cached_report( $filters );
			if ( ! is_array( $report ) ) {
				self::send_json(
					false,
					array( 'message' => __( 'Report cache missing. Reload the page and try again.', 'vila-campsite-booking' ) ),
					500
				);
			}
			Insights_Service::diag( 'rendering html' );
			$payload = array(
				'done'    => true,
				'stats'   => isset( $report['stats'] ) ? (array) $report['stats'] : array(),
				'kpis'    => self::kpis_html( $report ),
				'tables'  => self::tables_html( $report ),
				'charts'  => self::chart_payload( $report ),
				'orders'  => (int) $report['headline']['orders'],
				'seconds' => round( microtime( true ) - $started, 1 ),
			);
			Insights_Service::save_ajax_payload( $filters, $payload );
			self::send_json(
				true,
				array_merge(
					$payload,
					array(
						'build'   => self::BUILD,
						'boot_ms' => $boot_ms,
					)
				)
			);
		}

		// Ping + optional instant cache / job start (avoids a second 60s admin-ajax round-trip).
		if ( ! empty( $_REQUEST['ping'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$payload = array(
				'pong'    => true,
				'build'   => self::BUILD,
				'boot_ms' => $boot_ms,
			);
			if ( ! empty( $_REQUEST['diag_only'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				self::send_json( true, $payload );
			}
			if ( '' === $job_id && ! $fresh ) {
				$report = Insights_Service::cached_report( $filters );
				if ( is_array( $report ) && ! empty( $report['ajax_payload'] ) ) {
					self::send_json(
						true,
						array_merge(
							$report['ajax_payload'],
							$payload,
							array( 'done' => true )
						)
					);
				}
				if ( is_array( $report ) ) {
					Insights_Service::drop_cached_report( $filters );
				}
			}
			if ( '' === $job_id && ( $fresh || null === Insights_Service::cached_report( $filters ) ) ) {
				$prep            = microtime( true );
				$job             = $service->start_job( $filters );
				$payload['job']  = $job['job'];
				$payload['done'] = false;
				$payload['processed'] = 0;
				$payload['total']     = 0;
				$payload['message']   = __( 'Loading bookings…', 'vila-campsite-booking' );
				$payload['prepare_ms'] = (int) round( ( microtime( true ) - $prep ) * 1000 );
			}
			self::send_json( true, $payload );
		}

		try {
			if ( '' === $job_id ) {
				$report = $fresh ? null : Insights_Service::cached_report( $filters );
				if ( null === $report ) {
					$job = $service->start_job( $filters );
					self::send_json(
						true,
						array(
							'done'      => false,
							'build'     => self::BUILD,
							'boot_ms'   => $boot_ms,
							'job'       => $job['job'],
							'processed' => 0,
							'total'     => $job['total'],
							'message'   => __( 'Loading bookings…', 'vila-campsite-booking' ),
						)
					);
				}
				if ( ! empty( $report['ajax_payload'] ) ) {
					self::send_json(
						true,
						array_merge(
							$report['ajax_payload'],
							array(
								'done'    => true,
								'build'   => self::BUILD,
								'boot_ms' => $boot_ms,
							)
						)
					);
				}
				Insights_Service::drop_cached_report( $filters );
				$job = $service->start_job( $filters );
				self::send_json(
					true,
					array(
						'done'      => false,
						'build'     => self::BUILD,
						'boot_ms'   => $boot_ms,
						'job'       => $job['job'],
						'processed' => 0,
						'total'     => $job['total'],
						'message'   => __( 'Loading bookings…', 'vila-campsite-booking' ),
					)
				);
			} else {
				$step = $service->run_job_step( $job_id, $batch );
				if ( ! empty( $step['render'] ) ) {
					self::send_json(
						true,
						array(
							'done'      => false,
							'render'    => true,
							'build'     => self::BUILD,
							'boot_ms'   => $boot_ms,
							'processed' => $step['processed'],
							'total'     => $step['total'],
							'message'   => isset( $step['message'] ) ? $step['message'] : '',
						)
					);
				}
				if ( empty( $step['done'] ) ) {
					self::send_json(
						true,
						array(
							'done'      => false,
							'build'     => self::BUILD,
							'boot_ms'   => $boot_ms,
							'job'       => $job_id,
							'processed' => $step['processed'],
							'total'     => $step['total'],
							'message'   => isset( $step['message'] ) ? $step['message'] : '',
						)
					);
				}
				$report = $step['report'];
			}
		} catch ( \Throwable $e ) {
			self::send_json(
				false,
				array(
					'message' => sprintf(
						/* translators: 1: error message, 2: file, 3: line */
						__( 'Insights failed: %1$s (%2$s:%3$d)', 'vila-campsite-booking' ),
						$e->getMessage(),
						basename( $e->getFile() ),
						$e->getLine()
					),
				),
				500
			);
		}

		$payload = array(
			'done'    => true,
			'kpis'    => self::kpis_html( $report ),
			'tables'  => self::tables_html( $report ),
			'charts'  => self::chart_payload( $report ),
			'orders'  => (int) $report['headline']['orders'],
			'seconds' => round( microtime( true ) - $started, 1 ),
		);
		if ( ! empty( $report['filters'] ) && is_array( $report['filters'] ) ) {
			Insights_Service::save_ajax_payload( $report['filters'], $payload );
		}
		self::send_json(
			true,
			array_merge(
				$payload,
				array(
					'build'   => self::BUILD,
					'boot_ms' => $boot_ms,
				)
			)
		);
	}

	/**
	 * Action Scheduler and similar shutdown tasks keep the HTTP connection open on LiteSpeed
	 * until the host cuts it off. This request only needs to return JSON.
	 */
	private static function unhook_slow_shutdown() {
		global $wp_filter;
		if ( ! isset( $wp_filter['shutdown'] ) || ! is_object( $wp_filter['shutdown'] ) ) {
			return;
		}
		foreach ( $wp_filter['shutdown']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$fn   = $cb['function'];
				$name = '';
				if ( is_array( $fn ) && is_object( $fn[0] ) ) {
					$name = get_class( $fn[0] );
				} elseif ( is_string( $fn ) ) {
					$name = $fn;
				}
				if ( false !== stripos( $name, 'ActionScheduler' ) || false !== stripos( $name, 'AsyncRequest' ) ) {
					remove_action( 'shutdown', $fn, (int) $priority );
				}
			}
		}
	}

	/**
	 * Send JSON and close the connection before PHP shutdown work runs.
	 *
	 * @param bool                 $success Success.
	 * @param array<string, mixed> $data    Payload.
	 * @param int                  $code    HTTP status.
	 */
	private static function send_json( $success, array $data, $code = 200 ) {
		self::$responded = true;
		if ( '' !== self::$last_stuck ) {
			$data['last_stuck'] = self::$last_stuck;
		}
		Insights_Service::diag( 'sent' );
		$payload         = wp_json_encode(
			array(
				'success' => (bool) $success,
				'data'    => $data,
			)
		);
		if ( ! headers_sent() ) {
			status_header( (int) $code );
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
			header( 'Content-Length: ' . strlen( (string) $payload ) );
		}
		echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response.
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		flush();
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		exit;
	}

	/**
	 * Turn PHP fatals (timeouts, memory) into a readable JSON error instead of a blank 500.
	 */
	public static function ajax_fatal_guard() {
		if ( self::$responded ) {
			return;
		}
		$err = error_get_last();
		if ( ! $err || ! in_array( $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			return;
		}
		if ( ! headers_sent() ) {
			status_header( 500 );
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		}
		$diag = Insights_Service::read_diag();
		echo wp_json_encode(
			array(
				'success' => false,
				'data'    => array(
					'message' => sprintf(
						'Insights crashed while %s%s: %s (%s:%d)',
						$diag ? $diag['phase'] : 'starting',
						$diag && '' !== $diag['extra'] ? ' (' . $diag['extra'] . ')' : '',
						$err['message'],
						str_replace( ABSPATH, '', (string) $err['file'] ),
						(int) $err['line']
					),
				),
			)
		);
		Insights_Service::diag( 'sent' );
	}

	/**
	 * @param array<string, mixed> $report Report.
	 * @return string
	 */
	private static function kpis_html( array $report ) {
		$h = $report['headline'];
		ob_start();
		self::kpi( __( 'Booked revenue', 'vila-campsite-booking' ), self::money( $h['booked'] ), $h['booked_change'] );
		self::kpi( __( 'Collected', 'vila-campsite-booking' ), self::money( $h['collected'] ), $h['collected_change'] );
		self::kpi( __( 'Still to collect', 'vila-campsite-booking' ), self::money( $h['due'] ), $h['due_change'] );
		self::kpi( __( 'Refunded', 'vila-campsite-booking' ), self::money( $h['refunded'] ), $h['refunded_change'] );
		self::kpi( __( 'Average booking value', 'vila-campsite-booking' ), self::money( $h['avg_order'] ), $h['avg_order_change'] );
		self::kpi( __( 'Bookings', 'vila-campsite-booking' ), (string) (int) $h['orders'], $h['orders_change'] );
		self::kpi( __( 'Paid online share', 'vila-campsite-booking' ), number_format_i18n( (float) $h['paid_online_share'], 1 ) . '%', $h['paid_online_share_change'] );
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $report Report.
	 * @return string
	 */
	private static function tables_html( array $report ) {
		ob_start();
		self::money_to_chase_table( $report['money_to_chase'] );
		self::top_guests_table( $report['top_guests'] );
		return (string) ob_get_clean();
	}

	private static function kpi( $label, $value, $change ) {
		$change_text = null === $change ? __( 'No previous period', 'vila-campsite-booking' ) : sprintf( '%+.1f%% vs previous period', (float) $change );
		echo '<div class="vila-cb-kpi"><span class="vila-cb-kpi__label">' . esc_html( $label ) . '</span><strong class="vila-cb-kpi__val">' . wp_kses_post( $value ) . '</strong><span class="vila-cb-kpi__sub">' . esc_html( $change_text ) . '</span></div>';
	}

	private static function chart_panel( $id, $title ) {
		echo '<section class="vila-cb-ui-panel vila-cb-insights-chart"><h2 class="vila-cb-ui-section-title">' . esc_html( $title ) . '</h2><div class="vila-cb-insights-chart__canvas"><canvas id="vila-cb-chart-' . esc_attr( $id ) . '"></canvas></div></section>';
	}

	private static function money( $amount ) {
		return function_exists( 'wc_price' ) ? wc_price( (float) $amount ) : '€' . number_format_i18n( (float) $amount, 2 );
	}

	private static function money_to_chase_table( array $rows ) {
		echo '<section class="vila-cb-ui-panel"><h2 class="vila-cb-ui-section-title">' . esc_html__( 'Money to chase', 'vila-campsite-booking' ) . '</h2><p class="description">' . esc_html__( 'Guests who have checked out but are still marked as not paid.', 'vila-campsite-booking' ) . '</p><table class="vila-cb-insights-table"><thead><tr><th>' . esc_html__( 'Guest', 'vila-campsite-booking' ) . '</th><th>' . esc_html__( 'Checked out', 'vila-campsite-booking' ) . '</th><th>' . esc_html__( 'Amount', 'vila-campsite-booking' ) . '</th></tr></thead><tbody>';
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="3">' . esc_html__( 'No overdue unpaid bookings in this range.', 'vila-campsite-booking' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			$oid = (int) $row['order_id'];
			$url = function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
				? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $oid )
				: admin_url( 'post.php?post=' . $oid . '&action=edit' );
			echo '<tr><td><a href="' . esc_url( $url ) . '">' . esc_html( (string) $row['name'] ) . '</a><br /><small>' . esc_html( sprintf( __( 'Order #%s', 'vila-campsite-booking' ), (string) $row['order_number'] ) ) . '</small></td><td>' . esc_html( (string) $row['check_out'] ) . '</td><td>' . wp_kses_post( self::money( $row['amount'] ) ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	private static function top_guests_table( array $rows ) {
		echo '<section class="vila-cb-ui-panel"><h2 class="vila-cb-ui-section-title">' . esc_html__( 'Top guests by spend', 'vila-campsite-booking' ) . '</h2><table class="vila-cb-insights-table"><thead><tr><th>' . esc_html__( 'Guest', 'vila-campsite-booking' ) . '</th><th>' . esc_html__( 'Bookings', 'vila-campsite-booking' ) . '</th><th>' . esc_html__( 'Spend', 'vila-campsite-booking' ) . '</th></tr></thead><tbody>';
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="3">' . esc_html__( 'No booking data in this range yet.', 'vila-campsite-booking' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( (string) $row['name'] ) . '</td><td>' . esc_html( (string) (int) $row['orders'] ) . '</td><td>' . wp_kses_post( self::money( $row['amount'] ) ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	/**
	 * @param array<string, mixed> $report Report.
	 * @return array<string, mixed>
	 */
	private static function chart_payload( array $report ) {
		$labels = array(
			'pitch'     => __( 'Pitches', 'vila-campsite-booking' ),
			'stay'      => __( 'Village stays', 'vila-campsite-booking' ),
			'meal'      => __( 'Meals', 'vila-campsite-booking' ),
			'childcare' => __( 'Childcare', 'vila-campsite-booking' ),
			'other'     => __( 'Other', 'vila-campsite-booking' ),
		);
		$types = array();
		foreach ( (array) $report['type_revenue'] as $row ) {
			$row['label'] = isset( $labels[ $row['label'] ] ) ? $labels[ $row['label'] ] : $row['label'];
			$types[]      = $row;
		}
		return array(
			'currency'       => html_entity_decode( (string) $report['currency'], ENT_QUOTES, 'UTF-8' ),
			'series'         => $report['series'],
			'paymentMix'     => $report['payment_mix'],
			'onsiteMethods'  => $report['onsite_methods'],
			'byCountry'      => $report['by_country'],
			'countryPayment' => $report['country_payment'],
			'typeRevenue'    => $types,
			'unitTypes'      => $report['unit_types'],
			'leadTime'       => $report['lead_time'],
			'stayLength'     => $report['stay_length'],
			'weekday'        => $report['weekday'],
			'hour'           => $report['hour'],
			'checkoutHealth' => $report['checkout_health'],
			'returning'      => $report['returning'],
			'upcomingDue'    => $report['upcoming_due'],
		);
	}
}
