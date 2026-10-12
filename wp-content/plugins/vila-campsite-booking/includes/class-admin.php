<?php
/**
 * wp-admin: Campsite overview, timeline, plots, settings.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
class Admin {

	const PAGE_OVERVIEW = 'vila-cb-overview';
	const PAGE_CAL      = 'vila-cb-calendar';
	const PAGE_MEALS    = 'vila-cb-meals';
	const PAGE_PLOTS    = 'vila-cb-plots';
	const PAGE_BLOCKS   = 'vila-cb-blocks';
	const PAGE_SETTINGS = 'vila-cb-settings';
	const PAGE_BOOKING  = 'vila-cb-booking';

	/**
	 * Init.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 60 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'register_admin_bar' ), 100 );
		// Enqueue in admin_enqueue_scripts (before admin_head). Enqueuing only inside page callbacks runs too late.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_vila_cb_admin_styles' ), 10, 1 );
		// Runs after set_current_screen(), before admin-header.php — get_admin_page_title() can leave
		// global $title unset/null; strip_tags(null) is deprecated on PHP 8.1+.
		add_action( 'current_screen', array( __CLASS__, 'ensure_admin_page_title_global' ), 0 );
		add_action( 'admin_init', array( __CLASS__, 'handle_settings_save' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_plots_save' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_blocks_save' ) );
		add_action( 'admin_post_vila_cb_save_booking', array( __CLASS__, 'handle_booking_edit_save' ) );
	}

	/**
	 * Load Campsite wp-admin CSS on all WooCommerce → Campsite screens (calendar, booking edit, etc.).
	 *
	 * @param string $hook_suffix Current screen hook from admin_enqueue_scripts.
	 */
	public static function enqueue_vila_cb_admin_styles( $hook_suffix ) {
		$match = is_string( $hook_suffix ) && 0 === strpos( $hook_suffix, 'woocommerce_page_vila-cb-' );
		if ( ! $match && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			$match  = $screen && isset( $screen->id ) && is_string( $screen->id )
				&& 0 === strpos( $screen->id, 'woocommerce_page_vila-cb-' );
		}
		if ( ! $match ) {
			return;
		}
		wp_enqueue_style( 'vila-cb-admin', VILA_CB_URL . 'assets/css/admin.css', array(), VILA_CB_VERSION );
	}

	/**
	 * Coerce global $title so admin-header.php never calls strip_tags() on null.
	 *
	 * @return void
	 */
	public static function ensure_admin_page_title_global() {
		global $title;
		if ( null === $title ) {
			$title = '';
		}
	}

	/**
	 * Admin bar: quick link to Campsite calendar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public static function register_admin_bar( $wp_admin_bar ) {
		if ( ! class_exists( 'WooCommerce' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'     => 'vila-cb-toolbar-calendar',
				'title'  => __( 'Calendar', 'vila-campsite-booking' ),
				'href'   => admin_url( 'admin.php?page=' . self::PAGE_CAL ),
				'meta'   => array(
					'title' => __( 'Campsite calendar', 'vila-campsite-booking' ),
				),
				'parent' => false,
			)
		);
		$wp_admin_bar->add_node(
			array(
				'id'     => 'vila-cb-toolbar-meals',
				'title'  => __( 'Meals', 'vila-campsite-booking' ),
				'href'   => admin_url( 'admin.php?page=' . self::PAGE_MEALS ),
				'meta'   => array(
					'title' => __( 'Campsite meal plan', 'vila-campsite-booking' ),
				),
				'parent' => false,
			)
		);
		$wp_admin_bar->add_node(
			array(
				'id'     => 'vila-cb-toolbar-childcare',
				'title'  => __( 'Childcare', 'vila-campsite-booking' ),
				'href'   => admin_url( 'admin.php?page=' . Childcare_Admin::PAGE ),
				'meta'   => array(
					'title' => __( 'Campsite childcare roster', 'vila-campsite-booking' ),
				),
				'parent' => false,
			)
		);
		$wp_admin_bar->add_node(
			array(
				'id'     => 'vila-cb-toolbar-guest-checkin',
				'title'  => __( 'Guest check-in', 'vila-campsite-booking' ),
				'href'   => admin_url( 'admin.php?page=' . Admin_Guest_Registrations::PAGE ),
				'meta'   => array(
					'title' => __( 'Campsite guest registrations (SIBA)', 'vila-campsite-booking' ),
				),
				'parent' => false,
			)
		);
	}

	/**
	 * Quick links across Campsite admin screens.
	 *
	 * @param string $current Current page slug (optional).
	 */
	public static function render_section_nav( $current = '' ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( '' === $current && isset( $_GET['page'] ) ) {
			$current = sanitize_key( wp_unslash( Request_String::scalar( $_GET['page'] ) ) );
		}
		$items = array(
			array(
				'page'  => self::PAGE_OVERVIEW,
				'label' => __( 'Overview', 'vila-campsite-booking' ),
			),
			array(
				'page'  => self::PAGE_CAL,
				'label' => __( 'Calendar', 'vila-campsite-booking' ),
			),
			array(
				'page'  => Admin_Stays::PAGE,
				'label' => __( 'Village stays', 'vila-campsite-booking' ),
			),
			array(
				'page'  => self::PAGE_MEALS,
				'label' => __( 'Meals', 'vila-campsite-booking' ),
			),
			array(
				'page'  => Childcare_Admin::PAGE,
				'label' => __( 'Childcare', 'vila-campsite-booking' ),
			),
			array(
				'page'  => Admin_Guest_Registrations::PAGE,
				'label' => __( 'Guest check-in', 'vila-campsite-booking' ),
			),
			array(
				'page'  => self::PAGE_SETTINGS,
				'label' => __( 'Settings', 'vila-campsite-booking' ),
			),
		);
		?>
		<nav class="vila-cb-ui-section-nav" aria-label="<?php esc_attr_e( 'Campsite', 'vila-campsite-booking' ); ?>">
			<?php foreach ( $items as $item ) : ?>
				<?php
				$is_current = $current === $item['page'];
				$classes    = 'vila-cb-ui-section-nav__link' . ( $is_current ? ' is-current' : '' );
				?>
				<a class="<?php echo esc_attr( $classes ); ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $item['page'] ) ); ?>"<?php echo $is_current ? ' aria-current="page"' : ''; ?>>
					<?php echo esc_html( $item['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Shared wp-admin styles for all Campsite screens (fallback if admin_enqueue_scripts did not run).
	 */
	private static function enqueue_admin_assets() {
		if ( wp_style_is( 'vila-cb-admin', 'enqueued' ) || wp_style_is( 'vila-cb-admin', 'done' ) ) {
			return;
		}
		wp_enqueue_style( 'vila-cb-admin', VILA_CB_URL . 'assets/css/admin.css', array(), VILA_CB_VERSION );
	}

	/**
	 * Safe `date_format` option for DateTime::format() (PHP 8.1+ dislikes null / invalid patterns).
	 *
	 * @return string
	 */
	private static function wp_date_format_pattern() {
		$df = get_option( 'date_format' );
		return ( is_string( $df ) && '' !== $df ) ? $df : 'Y-m-d';
	}

	/**
	 * Register WooCommerce submenu pages.
	 */
	public static function register_menu() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_submenu_page(
			'woocommerce',
			__( 'Campsite', 'vila-campsite-booking' ),
			__( 'Campsite', 'vila-campsite-booking' ),
			'manage_woocommerce',
			self::PAGE_OVERVIEW,
			array( __CLASS__, 'render_overview' )
		);

		add_submenu_page(
			'woocommerce',
			__( 'Campsite calendar', 'vila-campsite-booking' ),
			__( 'Campsite · Calendar', 'vila-campsite-booking' ),
			'manage_woocommerce',
			self::PAGE_CAL,
			array( __CLASS__, 'render_calendar' )
		);

		add_submenu_page(
			'woocommerce',
			__( 'Campsite meals', 'vila-campsite-booking' ),
			__( 'Campsite · Meals', 'vila-campsite-booking' ),
			'manage_woocommerce',
			self::PAGE_MEALS,
			array( __CLASS__, 'render_meals' )
		);

		add_submenu_page(
			'woocommerce',
			__( 'Campsite plots', 'vila-campsite-booking' ),
			__( 'Campsite · Plots', 'vila-campsite-booking' ),
			'manage_woocommerce',
			self::PAGE_PLOTS,
			array( __CLASS__, 'render_plots' )
		);

		add_submenu_page(
			'woocommerce',
			__( 'Block campsite dates', 'vila-campsite-booking' ),
			__( 'Campsite · Block dates', 'vila-campsite-booking' ),
			'manage_woocommerce',
			self::PAGE_BLOCKS,
			array( __CLASS__, 'render_blocks' )
		);

		add_submenu_page(
			'woocommerce',
			__( 'Campsite settings', 'vila-campsite-booking' ),
			__( 'Campsite · Settings', 'vila-campsite-booking' ),
			'manage_woocommerce',
			self::PAGE_SETTINGS,
			array( __CLASS__, 'render_settings' )
		);

		// Full menu + page titles so get_admin_page_title() can fill $title. remove_submenu_page() only
		// drops the sidebar row; the load-* hook from add_submenu_page() stays registered.
		add_submenu_page(
			'woocommerce',
			__( 'Edit campsite booking', 'vila-campsite-booking' ),
			__( 'Edit campsite booking', 'vila-campsite-booking' ),
			'manage_woocommerce',
			self::PAGE_BOOKING,
			array( __CLASS__, 'render_booking_edit' )
		);
		// Keep this page registered in the submenu table so direct links from the calendar
		// pass WordPress capability routing on all admin setups.
	}

	/**
	 * Save booking edit (plot, dates, guests, unit type, billing name).
	 */
	public static function handle_booking_edit_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to do that.', 'vila-campsite-booking' ),
				'',
				array( 'response' => 403 )
			);
		}
		if ( ! isset( $_POST['vila_cb_booking_edit_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_booking_edit_nonce'] ) ) ), 'vila_cb_booking_edit' ) ) {
			wp_die(
				esc_html__( 'Security check failed. Please try again.', 'vila-campsite-booking' ),
				'',
				array( 'response' => 403 )
			);
		}

		$booking_id = isset( $_POST['booking_id'] ) ? (int) wp_unslash( Request_String::scalar( $_POST['booking_id'] ) ) : 0;
		if ( $booking_id <= 0 ) {
			wp_die(
				esc_html__( 'Invalid booking.', 'vila-campsite-booking' ),
				'',
				array( 'response' => 400 )
			);
		}

		$repo    = new Booking_Repository();
		$booking = $repo->get( $booking_id );
		if ( ! $booking ) {
			wp_die(
				esc_html__( 'Booking not found.', 'vila-campsite-booking' ),
				'',
				array( 'response' => 404 )
			);
		}

		$order_id     = (int) $booking['order_id'];
		$line_item_id = isset( $booking['line_item_id'] ) ? (int) $booking['line_item_id'] : 0;
		$order        = $order_id ? wc_get_order( $order_id ) : null;

		$plot_id   = isset( $_POST['vila_cb_plot_id'] ) ? (int) wp_unslash( Request_String::scalar( $_POST['vila_cb_plot_id'] ) ) : 0;
		$check_in  = isset( $_POST['vila_cb_check_in'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_check_in'] ) ) ) : '';
		$check_out = isset( $_POST['vila_cb_check_out'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_check_out'] ) ) ) : '';

		$guests = array(
			'adults'   => isset( $_POST['vila_cb_adults'] ) ? (int) wp_unslash( Request_String::scalar( $_POST['vila_cb_adults'] ) ) : 1,
			'children' => isset( $_POST['vila_cb_children'] ) ? (int) wp_unslash( Request_String::scalar( $_POST['vila_cb_children'] ) ) : 0,
			'infants'  => isset( $_POST['vila_cb_infants'] ) ? (int) wp_unslash( Request_String::scalar( $_POST['vila_cb_infants'] ) ) : 0,
			'pets'     => isset( $_POST['vila_cb_pets'] ) ? (int) wp_unslash( Request_String::scalar( $_POST['vila_cb_pets'] ) ) : 0,
		);

		$fn = isset( $_POST['vila_cb_billing_first_name'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_billing_first_name'] ) ) ) : '';
		$ln = isset( $_POST['vila_cb_billing_last_name'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_billing_last_name'] ) ) ) : '';

		$opts = WooCommerce_Integration::get_admin_vehicle_type_options();
		$posted_slugs = isset( $_POST['vila_cb_vehicle_slugs'] ) && is_array( $_POST['vila_cb_vehicle_slugs'] )
			? wp_unslash( $_POST['vila_cb_vehicle_slugs'] )
			: array();
		$slug_set = array();
		foreach ( $posted_slugs as $r ) {
			if ( ! is_string( $r ) && ! is_numeric( $r ) ) {
				continue;
			}
			$k = sanitize_key( (string) $r );
			if ( '' !== $k ) {
				$slug_set[ $k ] = true;
			}
		}
		$vehicle_slugs = array_keys( $slug_set );
		$initial_slugs = WooCommerce_Integration::get_vehicle_slugs_for_booking_edit(
			isset( $booking['guest_json'] ) ? (string) $booking['guest_json'] : '',
			$order,
			$line_item_id
		);
		$whitelist     = array_unique( array_merge( array_keys( $opts ), $initial_slugs ) );
		$whitelist     = array_values( array_filter( array_map( 'sanitize_key', $whitelist ) ) );
		$vehicle_slugs = array_values( array_intersect( $vehicle_slugs, $whitelist ) );
		sort( $vehicle_slugs );

		$guests['vehicle_slugs'] = $vehicle_slugs;
		$unit_label              = WooCommerce_Integration::extras_label_from_slugs( $vehicle_slugs );
		if ( '' !== $unit_label ) {
			$guests['extras_label'] = $unit_label;
		}

		// Always posted when the meals section is present (empty checkboxes = clear meals).
		if ( isset( $_POST['vila_cb_meals_edited'] ) ) {
			$posted_b = isset( $_POST['vila_cb_meal_breakfast_dates'] ) && is_array( $_POST['vila_cb_meal_breakfast_dates'] )
				? wp_unslash( $_POST['vila_cb_meal_breakfast_dates'] )
				: array();
			$posted_d = isset( $_POST['vila_cb_meal_dinner_dates'] ) && is_array( $_POST['vila_cb_meal_dinner_dates'] )
				? wp_unslash( $_POST['vila_cb_meal_dinner_dates'] )
				: array();
			$guests['meal_dates'] = array(
				'breakfast' => $posted_b,
				'dinner'    => $posted_d,
			);
		}

		WooCommerce_Integration::log_debug(
			'extras.admin_booking_edit_save',
			array(
				'booking_id'     => $booking_id,
				'order_id'       => $order_id,
				'line_item_id'   => $line_item_id,
				'vehicle_slugs'  => $vehicle_slugs,
				'unit_label'     => $unit_label,
				'guests_keys'    => array_keys( $guests ),
			)
		);

		$svc = new Booking_Service();
		$res = $svc->admin_update_booking(
			$booking_id,
			array(
				'plot_id'             => $plot_id,
				'check_in'            => $check_in,
				'check_out'           => $check_out,
				'guests'              => $guests,
				'billing_first_name'  => $fn,
				'billing_last_name'   => $ln,
				'unit_type_label'     => $unit_label,
			)
		);

		if ( ! empty( $res['success'] ) ) {
			$cal_start = Dates::parse_ymd( $check_in );
			$redirect  = add_query_arg(
				array(
					'page'        => self::PAGE_CAL,
					'vila_cb_cal' => $cal_start ? $cal_start->format( 'Y-m-d' ) : $check_in,
					'updated'     => '1',
				),
				admin_url( 'admin.php' )
			);
		} else {
			$redirect = add_query_arg(
				array(
					'page'       => self::PAGE_BOOKING,
					'booking_id' => $booking_id,
					'error'      => rawurlencode( isset( $res['message'] ) ? (string) $res['message'] : __( 'Could not save.', 'vila-campsite-booking' ) ),
				),
				admin_url( 'admin.php' )
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Single booking editor (hidden submenu).
	 */
	public static function render_booking_edit() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		self::enqueue_admin_assets();

		$booking_id = isset( $_GET['booking_id'] ) ? (int) wp_unslash( Request_String::scalar( $_GET['booking_id'] ) ) : 0;
		$repo       = new Booking_Repository();
		$booking    = $booking_id > 0 ? $repo->get( $booking_id ) : null;

		if ( ! $booking || 'confirmed' !== (string) $booking['status'] ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Booking not found', 'vila-campsite-booking' ) . '</h1></div>';
			return;
		}

		$plot_repo = new Plot_Repository();
		$plots     = $plot_repo->get_all();

		$order_id     = (int) $booking['order_id'];
		$line_item_id = isset( $booking['line_item_id'] ) ? (int) $booking['line_item_id'] : 0;
		$order        = $order_id ? wc_get_order( $order_id ) : null;

		$guests = array(
			'adults'   => 1,
			'children' => 0,
			'infants'  => 0,
			'pets'     => 0,
		);
		if ( ! empty( $booking['guest_json'] ) ) {
			$dec = json_decode( (string) $booking['guest_json'], true );
			if ( is_array( $dec ) ) {
				foreach ( array( 'adults', 'children', 'infants', 'pets' ) as $k ) {
					if ( isset( $dec[ $k ] ) ) {
						$guests[ $k ] = (int) $dec[ $k ];
					}
				}
			}
		}

		$billing_fn = $order ? (string) $order->get_billing_first_name() : '';
		$billing_ln = $order ? (string) $order->get_billing_last_name() : '';

		$opts = WooCommerce_Integration::get_admin_vehicle_type_options();
		$gj   = isset( $booking['guest_json'] ) ? (string) $booking['guest_json'] : '';

		$vslugs_checked = WooCommerce_Integration::get_vehicle_slugs_for_booking_edit( $gj, $order, $line_item_id );
		$dec_guest      = json_decode( $gj, true );
		$orphan_slugs   = array_values( array_diff( $vslugs_checked, array_keys( $opts ) ) );
		$current_unit   = WooCommerce_Integration::get_booking_extras_display_label( $gj, $order, $line_item_id );
		$booking_notes  = WooCommerce_Integration::get_line_booking_notes( $order, $line_item_id );

		WooCommerce_Integration::log_debug(
			'extras.admin_booking_edit_render',
			array(
				'booking_id'    => $booking_id,
				'order_id'      => $order_id,
				'line_item_id'  => $line_item_id,
				'guest_json_b'  => strlen( $gj ),
				'guest_keys'    => is_array( $dec_guest ) ? array_keys( $dec_guest ) : array(),
				'vslugs_checked'=> $vslugs_checked,
				'orphan_slugs'  => $orphan_slugs,
				'opts_count'    => count( $opts ),
			)
		);

		$order_edit = $order ? $order->get_edit_order_url() : '';

		$guest_reg     = ( new Guest_Registration_Repository() )->get_by_booking_id( $booking_id );
		$guest_reg_id  = $guest_reg ? (int) $guest_reg['id'] : 0;
		$guest_reg_url = $guest_reg_id > 0
			? admin_url( 'admin.php?page=' . Admin_Guest_Registrations::PAGE . '&registration_id=' . $guest_reg_id )
			: '';

		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Booking saved.', 'vila-campsite-booking' ) . '</p></div>';
		}
		if ( isset( $_GET['error'] ) ) {
			$raw = wp_unslash( Request_String::scalar( $_GET['error'] ) );
			$raw = rawurldecode( $raw );
			$err = sanitize_text_field( Request_String::scalar( $raw ) );
			echo '<div class="notice notice-error"><p>' . esc_html( $err ) . '</p></div>';
		}

		?>
		<div class="wrap vila-cb-admin">
			<?php self::render_section_nav( self::PAGE_BOOKING ); ?>
			<h1><?php esc_html_e( 'Edit campsite booking', 'vila-campsite-booking' ); ?></h1>
			<p class="vila-cb-guest-checkin-status">
				<?php echo self::guest_checkin_badge_html( $guest_reg_id > 0, $guest_reg_id, 'large' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper ?>
				<?php if ( $guest_reg_url ) : ?>
					<a class="button button-small" href="<?php echo esc_url( $guest_reg_url ); ?>"><?php esc_html_e( 'View guest registration', 'vila-campsite-booking' ); ?></a>
				<?php endif; ?>
			</p>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: booking ID */
						__( 'Booking #%d — changes sync to the campsite schedule and the WooCommerce order line.', 'vila-campsite-booking' ),
						$booking_id
					)
				);
				?>
				<?php if ( $order_edit ) : ?>
					<a href="<?php echo esc_url( $order_edit ); ?>"><?php esc_html_e( 'View order in WooCommerce', 'vila-campsite-booking' ); ?></a>
				<?php endif; ?>
			</p>

			<?php
			if ( apply_filters( 'vila_cb_booking_edit_extras_debug', defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
				$line_dbg = ( $line_item_id && $order ) ? $order->get_item( $line_item_id ) : null;
				$slug_raw = ( $line_dbg && is_callable( array( $line_dbg, 'get_meta' ) ) ) ? (string) $line_dbg->get_meta( '_vila_vehicle_slugs', true ) : '';
				$extras_m = ( $line_dbg && is_callable( array( $line_dbg, 'get_meta' ) ) ) ? (string) $line_dbg->get_meta( 'Extras', true ) : '';
				$dbg      = array(
					'booking_id'                    => $booking_id,
					'order_id'                      => $order_id,
					'line_item_id'                  => $line_item_id,
					'guest_json'                    => $gj,
					'vslugs_checked_for_form'       => $vslugs_checked,
					'opts_count'                    => count( $opts ),
					'opts_keys'                     => array_keys( $opts ),
					'current_unit_resolved'         => $current_unit,
					'line_meta__vila_vehicle_slugs' => $slug_raw,
					'line_meta_Extras'              => $extras_m,
				);
				echo '<details class="vila-cb-extras-debug" style="margin:1em 0;padding:8px;border:1px solid #ccc;background:#f7f7f7;"><summary>' . esc_html__( 'Extras / unit type debug (WP_DEBUG_LOG)', 'vila-campsite-booking' ) . '</summary><pre style="white-space:pre-wrap;max-height:20em;overflow:auto;">';
				echo esc_html( wp_json_encode( $dbg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
				echo '</pre><p class="description">' . esc_html__( 'Disable with: add_filter( \'vila_cb_booking_edit_extras_debug\', \'__return_false\' );', 'vila-campsite-booking' ) . '</p></details>';
			}
			?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vila-cb-booking-edit">
				<input type="hidden" name="action" value="vila_cb_save_booking" />
				<?php wp_nonce_field( 'vila_cb_booking_edit', 'vila_cb_booking_edit_nonce' ); ?>
				<input type="hidden" name="booking_id" value="<?php echo esc_attr( (string) (int) $booking_id ); ?>" />

				<!-- vila-cb-booking-edit-ui:checkboxes <?php echo esc_attr( defined( 'VILA_CB_VERSION' ) ? (string) VILA_CB_VERSION : '' ); ?> -->

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vila_cb_plot_id"><?php esc_html_e( 'Plot', 'vila-campsite-booking' ); ?></label></th>
						<td>
							<select name="vila_cb_plot_id" id="vila_cb_plot_id" required>
								<?php foreach ( $plots as $p ) : ?>
									<option value="<?php echo esc_attr( (string) (int) $p['id'] ); ?>" <?php selected( (int) $booking['plot_id'], (int) $p['id'] ); ?>>
										<?php echo esc_html( (string) $p['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Stay dates', 'vila-campsite-booking' ); ?></th>
						<td>
							<label for="vila_cb_check_in"><?php esc_html_e( 'Check-in', 'vila-campsite-booking' ); ?></label>
							<input type="date" name="vila_cb_check_in" id="vila_cb_check_in" value="<?php echo esc_attr( (string) $booking['check_in'] ); ?>" required />
							&nbsp;
							<label for="vila_cb_check_out"><?php esc_html_e( 'Check-out', 'vila-campsite-booking' ); ?></label>
							<input type="date" name="vila_cb_check_out" id="vila_cb_check_out" value="<?php echo esc_attr( (string) $booking['check_out'] ); ?>" required />
							<p class="description"><?php esc_html_e( 'Nights are from check-in up to (but not including) check-out.', 'vila-campsite-booking' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Guests', 'vila-campsite-booking' ); ?></th>
						<td>
							<label><span class="screen-reader-text"><?php esc_html_e( 'Adults', 'vila-campsite-booking' ); ?></span>
								<?php esc_html_e( 'Adults', 'vila-campsite-booking' ); ?>
								<input type="number" name="vila_cb_adults" min="1" step="1" class="small-text" value="<?php echo esc_attr( (string) (int) $guests['adults'] ); ?>" />
							</label>
							<label><?php esc_html_e( 'Children', 'vila-campsite-booking' ); ?>
								<input type="number" name="vila_cb_children" min="0" step="1" class="small-text" value="<?php echo esc_attr( (string) (int) $guests['children'] ); ?>" />
							</label>
							<label><?php esc_html_e( 'Babies', 'vila-campsite-booking' ); ?>
								<input type="number" name="vila_cb_infants" min="0" step="1" class="small-text" value="<?php echo esc_attr( (string) (int) $guests['infants'] ); ?>" />
							</label>
							<label><?php esc_html_e( 'Pets', 'vila-campsite-booking' ); ?>
								<input type="number" name="vila_cb_pets" min="0" step="1" class="small-text" value="<?php echo esc_attr( (string) (int) $guests['pets'] ); ?>" />
							</label>
						</td>
					</tr>
					<?php if ( '' !== $booking_notes ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Guest notes', 'vila-campsite-booking' ); ?></th>
						<td>
							<p class="description" style="margin:0;max-width:36em;white-space:pre-wrap;"><?php echo esc_html( $booking_notes ); ?></p>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Extras', 'vila-campsite-booking' ); ?></th>
						<td>
							<div class="vila-cb-unit-types-checkboxes" role="group" aria-labelledby="vila-cb-extras-legend">
								<p id="vila-cb-extras-legend" class="description" style="margin-top:0;">
									<strong><?php esc_html_e( 'Multi-select', 'vila-campsite-booking' ); ?></strong>
									<?php esc_html_e( '— tick every unit type and add-on that applies (same as checkout).', 'vila-campsite-booking' ); ?>
								</p>
								<?php
								WooCommerce_Integration::echo_vehicle_extras_chip_grid(
									'vila_cb_vehicle_slugs[]',
									'vila_cb_vsl',
									$vslugs_checked
								);
								?>
							</div>
						</td>
					</tr>
					<?php
					$meal_edit = Meal_Plan_Service::meal_edit_state_for_booking( $booking, $order );
					$df_meal   = get_option( 'date_format' );
					$df_meal   = ( is_string( $df_meal ) && '' !== $df_meal ) ? $df_meal : 'Y-m-d';
					?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Meals', 'vila-campsite-booking' ); ?></th>
						<td>
							<input type="hidden" name="vila_cb_meals_edited" value="1" />
							<p class="description" style="margin-top:0;">
								<?php esc_html_e( 'Tick the days this party has breakfast or weekday dinner. Uncheck all to remove meals from the booking.', 'vila-campsite-booking' ); ?>
							</p>
							<?php if ( ! empty( $meal_edit['meal_party'] ) ) : ?>
								<p class="description">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: adults, 2: children, 3: infants */
											__( 'Meal headcount (if booked separately): %1$d adults, %2$d children, %3$d babies.', 'vila-campsite-booking' ),
											(int) $meal_edit['meal_party']['adults'],
											(int) $meal_edit['meal_party']['children'],
											(int) $meal_edit['meal_party']['infants']
										)
									);
									?>
								</p>
							<?php endif; ?>
							<div class="vila-cb-meal-edit">
								<div class="vila-cb-meal-edit__block">
									<strong class="vila-cb-meal-edit__title"><?php esc_html_e( 'Bio breakfast & coffee', 'vila-campsite-booking' ); ?></strong>
									<?php if ( empty( $meal_edit['breakfast_choices'] ) ) : ?>
										<p class="description"><?php esc_html_e( 'No breakfast mornings in this stay (need at least one night).', 'vila-campsite-booking' ); ?></p>
									<?php else : ?>
										<div class="vila-cb-unit-types-checkboxes vila-cb-meal-edit__dates" role="group">
											<?php foreach ( $meal_edit['breakfast_choices'] as $ymd ) : ?>
												<?php
												$dt    = Dates::parse_ymd( $ymd );
												$label = $dt ? $dt->format( 'D' ) . ' ' . $dt->format( $df_meal ) : $ymd;
												$id    = 'vila_cb_meal_b_' . preg_replace( '/[^0-9]/', '', $ymd );
												?>
												<label class="vila-cb-unit-types-checkboxes__item" for="<?php echo esc_attr( $id ); ?>">
													<input type="checkbox" name="vila_cb_meal_breakfast_dates[]" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $ymd ); ?>" <?php checked( in_array( $ymd, $meal_edit['breakfast_checked'], true ) ); ?> />
													<span><?php echo esc_html( $label ); ?></span>
												</label>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>
								</div>
								<div class="vila-cb-meal-edit__block">
									<strong class="vila-cb-meal-edit__title"><?php esc_html_e( 'Weekday dinner (Mon–Fri)', 'vila-campsite-booking' ); ?></strong>
									<?php if ( empty( $meal_edit['dinner_choices'] ) ) : ?>
										<p class="description"><?php esc_html_e( 'No weekday dinner nights in this stay.', 'vila-campsite-booking' ); ?></p>
									<?php else : ?>
										<div class="vila-cb-unit-types-checkboxes vila-cb-meal-edit__dates" role="group">
											<?php foreach ( $meal_edit['dinner_choices'] as $ymd ) : ?>
												<?php
												$dt    = Dates::parse_ymd( $ymd );
												$label = $dt ? $dt->format( 'D' ) . ' ' . $dt->format( $df_meal ) : $ymd;
												$id    = 'vila_cb_meal_d_' . preg_replace( '/[^0-9]/', '', $ymd );
												?>
												<label class="vila-cb-unit-types-checkboxes__item" for="<?php echo esc_attr( $id ); ?>">
													<input type="checkbox" name="vila_cb_meal_dinner_dates[]" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $ymd ); ?>" <?php checked( in_array( $ymd, $meal_edit['dinner_checked'], true ) ); ?> />
													<span><?php echo esc_html( $label ); ?></span>
												</label>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>
								</div>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Billing name (order)', 'vila-campsite-booking' ); ?></th>
						<td>
							<input type="text" name="vila_cb_billing_first_name" class="regular-text" value="<?php echo esc_attr( (string) $billing_fn ); ?>" placeholder="<?php esc_attr_e( 'First name', 'vila-campsite-booking' ); ?>" />
							<input type="text" name="vila_cb_billing_last_name" class="regular-text" value="<?php echo esc_attr( (string) $billing_ln ); ?>" placeholder="<?php esc_attr_e( 'Last name', 'vila-campsite-booking' ); ?>" />
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save booking', 'vila-campsite-booking' ), 'primary', 'vila_cb_booking_save' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Save settings form.
	 */
	public static function handle_settings_save() {
		if ( ! isset( $_POST['vila_cb_settings_nonce'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_settings_nonce'] ) ) ), 'vila_cb_settings' ) ) {
			return;
		}
		$product_id = isset( $_POST['vila_cb_product_id'] ) ? (int) wp_unslash( Request_String::scalar( $_POST['vila_cb_product_id'] ) ) : 0;
		$plot_count = isset( $_POST['vila_cb_plot_count'] ) ? (int) wp_unslash( Request_String::scalar( $_POST['vila_cb_plot_count'] ) ) : count( Plot_Repository::default_plot_numbers() );

		update_option( 'vila_cb_product_id', max( 0, $product_id ) );
		update_option( 'vila_cb_plot_count', max( 1, $plot_count ) );

		if ( $product_id ) {
			update_post_meta( $product_id, WooCommerce_Integration::PRODUCT_META_FLAG, 'yes' );
		}

		if ( isset( $_POST['vila_cb_pricing'] ) && is_array( $_POST['vila_cb_pricing'] ) ) {
			$san = Pricing_Settings::sanitize_from_request( wp_unslash( $_POST['vila_cb_pricing'] ) );
			update_option( Pricing_Settings::OPTION_KEY, $san );
		}

		if ( isset( $_POST['vila_cb_siba'] ) && is_array( $_POST['vila_cb_siba'] ) ) {
			update_option( Guest_Registration_Exporter::OPTION_SETTINGS, Guest_Registration_Exporter::sanitize_settings( wp_unslash( $_POST['vila_cb_siba'] ) ) );
		}

		if ( isset( $_POST['vila_cb_childcare'] ) && is_array( $_POST['vila_cb_childcare'] ) ) {
			update_option( Childcare_Service::OPTION_SETTINGS, Childcare_Service::sanitize_settings( wp_unslash( $_POST['vila_cb_childcare'] ) ) );
		}

		Pricing_Settings::sync_woocommerce_currency_option();

		Install::sync_plots();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SETTINGS,
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Plots CRUD.
	 */
	public static function handle_plots_save() {
		if ( ! isset( $_POST['vila_cb_plots_nonce'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_plots_nonce'] ) ) ), 'vila_cb_plots' ) ) {
			return;
		}

		$repo = new Plot_Repository();
		if ( isset( $_POST['vila_cb_new_label'] ) && '' !== trim( wp_unslash( Request_String::scalar( $_POST['vila_cb_new_label'] ) ) ) ) {
			$label = sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_new_label'] ) ) );
			$now   = current_time( 'mysql' );
			$max   = 0;
			foreach ( $repo->get_all() as $p ) {
				$max = max( $max, (int) $p['sort_order'] );
			}
			$repo->insert(
				array(
					'label'      => $label,
					'sort_order' => $max + 1,
					'created_at' => $now,
					'updated_at' => $now,
				)
			);
		}

		if ( ! empty( $_POST['vila_cb_plot'] ) && is_array( $_POST['vila_cb_plot'] ) ) {
			foreach ( $_POST['vila_cb_plot'] as $id => $row ) {
				$id = (int) $id;
				if ( $id <= 0 || ! is_array( $row ) ) {
					continue;
				}
				if ( ! empty( $row['delete'] ) ) {
					$repo->delete( $id );
					continue;
				}
				$label = isset( $row['label'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $row['label'] ) ) ) : '';
				$sort  = isset( $row['sort'] ) ? (int) wp_unslash( Request_String::scalar( $row['sort'] ) ) : 0;
				if ( '' !== $label ) {
					$repo->update(
						$id,
						array(
							'label'      => $label,
							'sort_order' => $sort,
							'updated_at' => current_time( 'mysql' ),
						)
					);
				}
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_PLOTS,
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Overview + daily list.
	 */
	public static function render_overview() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		self::enqueue_admin_assets();

		$tz   = wp_timezone();
		$base = new \DateTime( 'now', $tz );
		$base->setTime( 0, 0, 0 );
		if ( isset( $_GET['vila_cb_day'] ) ) {
			$raw = wp_unslash( Request_String::scalar( $_GET['vila_cb_day'] ) );
			$try = Dates::parse_ymd( sanitize_text_field( $raw ) );
			if ( $try ) {
				$base = new \DateTime( $try->format( 'Y-m-d' ), $tz );
			}
		}
		$ymd = $base->format( 'Y-m-d' );

		$svc   = new Booking_Service();
		$stats = $svc->get_day_stats( $ymd );

		$repo = new Booking_Repository();
		$rows = $repo->get_activity_for_day( $ymd );

		$guest_reg_by_booking = ( new Guest_Registration_Repository() )->get_registration_ids_by_booking_ids(
			array_map(
				static function ( $b ) {
					return isset( $b['id'] ) ? (int) $b['id'] : 0;
				},
				$rows
			)
		);

		$prev = ( clone $base )->modify( '-1 day' )->format( 'Y-m-d' );
		$nxt  = ( clone $base )->modify( '+1 day' )->format( 'Y-m-d' );

		$meal_page_id = Install::get_meal_booking_page_id();
		$show_deploy  = ! Install::meals_module_available() || $meal_page_id <= 0;

		?>
		<div class="wrap vila-cb-admin vila-cb-overview">
			<?php self::render_section_nav( self::PAGE_OVERVIEW ); ?>
			<?php if ( $show_deploy ) : ?>
				<div class="notice notice-warning" style="margin:12px 0 0;">
					<p>
						<strong><?php esc_html_e( 'Meals module', 'vila-campsite-booking' ); ?></strong>
						<?php
						echo ' · ';
						echo esc_html(
							sprintf(
								/* translators: %s: plugin version */
								__( 'Plugin build %s', 'vila-campsite-booking' ),
								defined( 'VILA_CB_VERSION' ) ? (string) VILA_CB_VERSION : '?'
							)
						);
						echo ' · ';
						echo Install::meals_module_available()
							? esc_html__( 'code loaded', 'vila-campsite-booking' )
							: esc_html__( 'code missing on server — re-upload the full plugin folder', 'vila-campsite-booking' );
						if ( $meal_page_id <= 0 ) {
							echo ' · ';
							echo esc_html__( 'book-meals page missing', 'vila-campsite-booking' );
							echo ' — ';
							echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?vila_cb_create_public_pages=1' ), 'vila_cb_create_public_pages' ) ) . '">';
							esc_html_e( 'Create pages now', 'vila-campsite-booking' );
							echo '</a>';
						} elseif ( Install::meals_module_available() ) {
							echo ' · ';
							echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_MEALS ) ) . '">';
							esc_html_e( 'Open Campsite · Meals', 'vila-campsite-booking' );
							echo '</a>';
						}
						?>
					</p>
				</div>
			<?php endif; ?>
			<div class="vila-cb-ui-hero">
				<div class="vila-cb-ui-hero__text">
					<h1 class="vila-cb-ui-title"><?php esc_html_e( 'Campsite · Overview', 'vila-campsite-booking' ); ?></h1>
					<p class="vila-cb-ui-lead"><?php esc_html_e( 'Operational snapshot for the selected day — arrivals, departures, and who is on site.', 'vila-campsite-booking' ); ?></p>
				</div>
				<form method="get" class="vila-cb-daynav vila-cb-ui-nav">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_OVERVIEW ); ?>" />
					<button type="submit" class="button vila-cb-ui-nav-btn" name="vila_cb_day" value="<?php echo esc_attr( $prev ); ?>" aria-label="<?php esc_attr_e( 'Previous day', 'vila-campsite-booking' ); ?>">
						<span class="vila-cb-ui-nav-arrow" aria-hidden="true">‹</span>
						<span><?php esc_html_e( 'Prev', 'vila-campsite-booking' ); ?></span>
					</button>
					<div class="vila-cb-ui-nav-period">
						<span class="vila-cb-ui-nav-month"><?php echo esc_html( $base->format( 'l' ) ); ?></span>
						<span class="vila-cb-ui-nav-sub"><?php echo esc_html( $base->format( self::wp_date_format_pattern() ) ); ?></span>
					</div>
					<button type="submit" class="button vila-cb-ui-nav-btn vila-cb-ui-nav-btn--primary" name="vila_cb_day" value="<?php echo esc_attr( $nxt ); ?>" aria-label="<?php esc_attr_e( 'Next day', 'vila-campsite-booking' ); ?>">
						<span><?php esc_html_e( 'Next', 'vila-campsite-booking' ); ?></span>
						<span class="vila-cb-ui-nav-arrow" aria-hidden="true">›</span>
					</button>
				</form>
			</div>

			<div class="vila-cb-kpis">
				<div class="vila-cb-kpi vila-cb-kpi--arrive">
					<span class="vila-cb-kpi__label"><?php esc_html_e( 'Arriving today', 'vila-campsite-booking' ); ?></span>
					<strong class="vila-cb-kpi__val"><?php echo esc_html( (string) (int) $stats['arriving'] ); ?></strong>
				</div>
				<div class="vila-cb-kpi vila-cb-kpi--depart">
					<span class="vila-cb-kpi__label"><?php esc_html_e( 'Departing today', 'vila-campsite-booking' ); ?></span>
					<strong class="vila-cb-kpi__val"><?php echo esc_html( (string) (int) $stats['departing'] ); ?></strong>
				</div>
				<div class="vila-cb-kpi vila-cb-kpi--stay">
					<span class="vila-cb-kpi__label"><?php esc_html_e( 'Staying tonight', 'vila-campsite-booking' ); ?></span>
					<strong class="vila-cb-kpi__val"><?php echo esc_html( (string) (int) $stats['staying'] ); ?></strong>
				</div>
				<div class="vila-cb-kpi vila-cb-kpi--book">
					<span class="vila-cb-kpi__label"><?php esc_html_e( 'Orders with activity today', 'vila-campsite-booking' ); ?></span>
					<strong class="vila-cb-kpi__val"><?php echo esc_html( (string) (int) $stats['activity_orders'] ); ?></strong>
				</div>
			</div>

			<div class="vila-cb-ui-panel">
				<h2 class="vila-cb-ui-section-title"><?php esc_html_e( 'Daily list', 'vila-campsite-booking' ); ?></h2>
				<div class="vila-cb-ui-table-wrap">
					<table class="vila-cb-list-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Guest / stay', 'vila-campsite-booking' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Plot', 'vila-campsite-booking' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Balance / order', 'vila-campsite-booking' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Actions', 'vila-campsite-booking' ); ?></th>
							</tr>
						</thead>
						<tbody>
					<?php
					if ( empty( $rows ) ) {
						echo '<tr><td colspan="4">' . esc_html__( 'No bookings with activity on this day.', 'vila-campsite-booking' ) . '</td></tr>';
					}
					$plot_repo = new Plot_Repository();
					foreach ( $rows as $b ) {
						if ( 'confirmed' !== $b['status'] ) {
							continue;
						}
						$cin  = (string) $b['check_in'];
						$cout = (string) $b['check_out'];
						$nights = Dates::night_count( $cin, $cout );
						$order_id = (int) $b['order_id'];
						$order    = $order_id ? wc_get_order( $order_id ) : null;
						$name = $order ? trim( (string) $order->get_formatted_billing_full_name() ) : '';
						if ( '' === $name ) {
							$name = $order ? '#' . $order->get_order_number() : '#' . (int) $b['id'];
						}
						$plot = $plot_repo->get( (int) $b['plot_id'] );
						$plabel = $plot ? (string) $plot['label'] : '#' . (int) $b['plot_id'];
						$edit   = $order ? $order->get_edit_order_url() : '';
						$total  = $order ? $order->get_total() : '';
						$booking_edit_url = add_query_arg(
							array(
								'page'       => self::PAGE_BOOKING,
								'booking_id' => (int) $b['id'],
							),
							admin_url( 'admin.php' )
						);
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $name ); ?></strong>
								<?php
								$reg_id = isset( $guest_reg_by_booking[ (int) $b['id'] ] ) ? (int) $guest_reg_by_booking[ (int) $b['id'] ] : 0;
								echo ' ' . self::guest_checkin_badge_html( $reg_id > 0, $reg_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper
								?>
								<br />
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: nights, 2: check-in, 3: check-out */
										_n( '%1$d night · %2$s → %3$s', '%1$d nights · %2$s → %3$s', $nights, 'vila-campsite-booking' ),
										$nights,
										$cin,
										$cout
									)
								);
								?>
							</td>
							<td><?php echo esc_html( $plabel ); ?></td>
							<td>
								<?php if ( $order ) : ?>
									<a href="<?php echo esc_url( $edit ); ?>"><?php echo wp_kses_post( wc_price( $total ) ); ?></a>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( $booking_edit_url ); ?>"><?php esc_html_e( 'Edit booking', 'vila-campsite-booking' ); ?></a>
								<?php if ( $edit ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Order', 'vila-campsite-booking' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
						<?php
					}
					?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Kitchen meal plan: breakfast / dinner headcounts for the next two weeks.
	 */
	public static function render_meals() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		self::enqueue_admin_assets();

		$tz   = wp_timezone();
		$base = new \DateTime( 'now', $tz );
		$base->setTime( 0, 0, 0 );
		if ( isset( $_GET['vila_cb_meals'] ) ) {
			$raw = wp_unslash( Request_String::scalar( $_GET['vila_cb_meals'] ) );
			$try = Dates::parse_ymd( sanitize_text_field( $raw ) );
			if ( $try ) {
				$base = new \DateTime( $try->format( 'Y-m-d' ), $tz );
			}
		}

		$from = $base->format( 'Y-m-d' );
		$to   = ( clone $base )->modify( '+13 days' )->format( 'Y-m-d' );
		$prev = ( clone $base )->modify( '-14 days' )->format( 'Y-m-d' );
		$nxt  = ( clone $base )->modify( '+14 days' )->format( 'Y-m-d' );

		$plan   = ( new Meal_Plan_Service() )->get_range( $from, $to );
		$days   = $plan['days'];
		$totals = $plan['totals'];

		$period_label = sprintf(
			/* translators: 1: start date, 2: end date */
			__( '%1$s – %2$s', 'vila-campsite-booking' ),
			$base->format( self::wp_date_format_pattern() ),
			( clone $base )->modify( '+13 days' )->format( self::wp_date_format_pattern() )
		);

		?>
		<div class="wrap vila-cb-admin vila-cb-meals">
			<?php self::render_section_nav( self::PAGE_MEALS ); ?>
			<div class="vila-cb-ui-hero">
				<div class="vila-cb-ui-hero__text">
					<h1 class="vila-cb-ui-title"><?php esc_html_e( 'Campsite · Meals', 'vila-campsite-booking' ); ?></h1>
					<p class="vila-cb-ui-lead">
						<?php esc_html_e( 'How many guests to cook for, including campsite pitches and village stays. Breakfast is the morning after each night stayed; dinner is Mon–Fri evenings for guests staying that night. Under-2s are listed but not included in the cook total.', 'vila-campsite-booking' ); ?>
					</p>
				</div>
				<form method="get" class="vila-cb-daynav vila-cb-ui-nav">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_MEALS ); ?>" />
					<button type="submit" class="button vila-cb-ui-nav-btn" name="vila_cb_meals" value="<?php echo esc_attr( $prev ); ?>" aria-label="<?php esc_attr_e( 'Previous two weeks', 'vila-campsite-booking' ); ?>">
						<span class="vila-cb-ui-nav-arrow" aria-hidden="true">‹</span>
						<span><?php esc_html_e( 'Prev', 'vila-campsite-booking' ); ?></span>
					</button>
					<div class="vila-cb-ui-nav-period">
						<span class="vila-cb-ui-nav-month"><?php esc_html_e( '14 days', 'vila-campsite-booking' ); ?></span>
						<span class="vila-cb-ui-nav-sub"><?php echo esc_html( $period_label ); ?></span>
					</div>
					<button type="submit" class="button vila-cb-ui-nav-btn vila-cb-ui-nav-btn--primary" name="vila_cb_meals" value="<?php echo esc_attr( $nxt ); ?>" aria-label="<?php esc_attr_e( 'Next two weeks', 'vila-campsite-booking' ); ?>">
						<span><?php esc_html_e( 'Next', 'vila-campsite-booking' ); ?></span>
						<span class="vila-cb-ui-nav-arrow" aria-hidden="true">›</span>
					</button>
				</form>
			</div>

			<div class="vila-cb-kpis">
				<div class="vila-cb-kpi vila-cb-kpi--meal-b">
					<span class="vila-cb-kpi__label"><?php esc_html_e( 'Breakfast covers (period)', 'vila-campsite-booking' ); ?></span>
					<strong class="vila-cb-kpi__val"><?php echo esc_html( (string) (int) $totals['breakfast_cook'] ); ?></strong>
				</div>
				<div class="vila-cb-kpi vila-cb-kpi--meal-d">
					<span class="vila-cb-kpi__label"><?php esc_html_e( 'Dinner covers (period)', 'vila-campsite-booking' ); ?></span>
					<strong class="vila-cb-kpi__val"><?php echo esc_html( (string) (int) $totals['dinner_cook'] ); ?></strong>
				</div>
				<div class="vila-cb-kpi vila-cb-kpi--stay">
					<span class="vila-cb-kpi__label"><?php esc_html_e( 'Days with breakfast', 'vila-campsite-booking' ); ?></span>
					<strong class="vila-cb-kpi__val"><?php echo esc_html( (string) (int) $totals['breakfast_days'] ); ?></strong>
				</div>
				<div class="vila-cb-kpi vila-cb-kpi--book">
					<span class="vila-cb-kpi__label"><?php esc_html_e( 'Days with dinner', 'vila-campsite-booking' ); ?></span>
					<strong class="vila-cb-kpi__val"><?php echo esc_html( (string) (int) $totals['dinner_days'] ); ?></strong>
				</div>
			</div>

			<div class="vila-cb-meal-days">
				<?php
				if ( empty( $days ) ) {
					echo '<div class="vila-cb-ui-panel"><p>' . esc_html__( 'No dates in this range.', 'vila-campsite-booking' ) . '</p></div>';
				}
				foreach ( $days as $day ) :
					$b = $day['breakfast'];
					$d = $day['dinner'];
					$has_any = ( (int) $b['cook'] + (int) $d['cook'] + (int) $b['infants'] + (int) $d['infants'] ) > 0;
					$row_class = 'vila-cb-meal-day';
					if ( ! empty( $day['is_today'] ) ) {
						$row_class .= ' is-today';
					}
					if ( empty( $day['is_weekday'] ) ) {
						$row_class .= ' is-weekend';
					}
					if ( ! $has_any ) {
						$row_class .= ' is-empty';
					}
					?>
					<article class="<?php echo esc_attr( $row_class ); ?>">
						<header class="vila-cb-meal-day__head">
							<div>
								<strong class="vila-cb-meal-day__dow"><?php echo esc_html( (string) $day['label'] ); ?></strong>
								<span class="vila-cb-meal-day__date"><?php echo esc_html( (string) $day['date_label'] ); ?></span>
							</div>
							<div class="vila-cb-meal-day__totals" aria-label="<?php esc_attr_e( 'Cook totals', 'vila-campsite-booking' ); ?>">
								<span class="vila-cb-meal-pill vila-cb-meal-pill--b" title="<?php esc_attr_e( 'Breakfast cook total', 'vila-campsite-booking' ); ?>">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: people to cook breakfast for */
											__( 'Breakfast %d', 'vila-campsite-booking' ),
											(int) $b['cook']
										)
									);
									?>
								</span>
								<?php if ( ! empty( $day['is_weekday'] ) ) : ?>
									<span class="vila-cb-meal-pill vila-cb-meal-pill--d" title="<?php esc_attr_e( 'Dinner cook total', 'vila-campsite-booking' ); ?>">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: people to cook dinner for */
												__( 'Dinner %d', 'vila-campsite-booking' ),
												(int) $d['cook']
											)
										);
										?>
									</span>
								<?php else : ?>
									<span class="vila-cb-meal-pill vila-cb-meal-pill--off"><?php esc_html_e( 'No dinner (weekend)', 'vila-campsite-booking' ); ?></span>
								<?php endif; ?>
							</div>
						</header>

						<div class="vila-cb-meal-day__cols">
							<section class="vila-cb-meal-col">
								<h3 class="vila-cb-meal-col__title"><?php esc_html_e( 'Breakfast', 'vila-campsite-booking' ); ?></h3>
								<?php self::render_meal_party_list( $b, __( 'No breakfast guests', 'vila-campsite-booking' ) ); ?>
							</section>
							<section class="vila-cb-meal-col">
								<h3 class="vila-cb-meal-col__title"><?php esc_html_e( 'Dinner', 'vila-campsite-booking' ); ?></h3>
								<?php
								if ( empty( $day['is_weekday'] ) ) {
									echo '<p class="vila-cb-meal-col__empty">' . esc_html__( 'Weekday dinners only.', 'vila-campsite-booking' ) . '</p>';
								} else {
									self::render_meal_party_list( $d, __( 'No dinner guests', 'vila-campsite-booking' ) );
								}
								?>
							</section>
						</div>
					</article>
					<?php
				endforeach;
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render party list for one meal bucket.
	 *
	 * @param array{adults: int, children: int, infants: int, cook: int, parties: array<int, array<string, mixed>>} $bucket Meal bucket.
	 * @param string                                                                                                 $empty_label Empty state.
	 */
	private static function render_meal_party_list( array $bucket, $empty_label ) {
		$parties = isset( $bucket['parties'] ) && is_array( $bucket['parties'] ) ? $bucket['parties'] : array();
		if ( empty( $parties ) ) {
			echo '<p class="vila-cb-meal-col__empty">' . esc_html( $empty_label ) . '</p>';
			return;
		}
		echo '<ul class="vila-cb-meal-parties">';
		foreach ( $parties as $p ) {
			$breakdown = WooCommerce_Integration::format_guest_counts_calendar_line(
				array(
					'adults'   => isset( $p['adults'] ) ? (int) $p['adults'] : 0,
					'children' => isset( $p['children'] ) ? (int) $p['children'] : 0,
					'infants'  => isset( $p['infants'] ) ? (int) $p['infants'] : 0,
					'pets'     => 0,
				)
			);
			$cook = isset( $p['cook'] ) ? (int) $p['cook'] : 0;
			echo '<li class="vila-cb-meal-party">';
			echo '<div class="vila-cb-meal-party__main">';
			echo '<strong class="vila-cb-meal-party__name">' . esc_html( (string) $p['name'] ) . '</strong>';
			echo '<span class="vila-cb-meal-party__meta">' . esc_html( (string) $p['plot'] ) . ' · ' . esc_html( $breakdown ) . '</span>';
			echo '</div>';
			echo '<div class="vila-cb-meal-party__side">';
			echo '<span class="vila-cb-meal-party__cook">' . esc_html(
				sprintf(
					/* translators: %d: cook count for this party */
					_n( '%d to cook', '%d to cook', $cook, 'vila-campsite-booking' ),
					$cook
				)
			) . '</span>';
			if ( ! empty( $p['edit_url'] ) ) {
				echo '<a class="button button-small" href="' . esc_url( (string) $p['edit_url'] ) . '">' . esc_html__( 'Booking', 'vila-campsite-booking' ) . '</a>';
			}
			echo '</div>';
			echo '</li>';
		}
		echo '</ul>';
		$summary_parts = array();
		if ( (int) $bucket['adults'] > 0 ) {
			$summary_parts[] = sprintf(
				/* translators: %d: adults */
				_n( '%d adult', '%d adults', (int) $bucket['adults'], 'vila-campsite-booking' ),
				(int) $bucket['adults']
			);
		}
		if ( (int) $bucket['children'] > 0 ) {
			$summary_parts[] = sprintf(
				/* translators: %d: children */
				_n( '%d child', '%d children', (int) $bucket['children'], 'vila-campsite-booking' ),
				(int) $bucket['children']
			);
		}
		if ( (int) $bucket['infants'] > 0 ) {
			$summary_parts[] = sprintf(
				/* translators: %d: babies (not in cook total) */
				_n( '%d baby (not counted)', '%d babies (not counted)', (int) $bucket['infants'], 'vila-campsite-booking' ),
				(int) $bucket['infants']
			);
		}
		if ( $summary_parts ) {
			echo '<p class="vila-cb-meal-col__sum"><strong>' . esc_html(
				sprintf(
					/* translators: %d: cook total */
					__( 'Cook for %d', 'vila-campsite-booking' ),
					(int) $bucket['cook']
				)
			) . '</strong> — ' . esc_html( implode( ', ', $summary_parts ) ) . '</p>';
		}
	}

	/**
	 * Gantt-style calendar.
	 */
	public static function render_calendar() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		self::enqueue_admin_assets();

		$tz = wp_timezone();
		if ( isset( $_GET['vila_cb_cal'] ) ) {
			$raw = wp_unslash( Request_String::scalar( $_GET['vila_cb_cal'] ) );
			$try = Dates::parse_ymd( sanitize_text_field( $raw ) );
			if ( $try ) {
				$base = new \DateTime( $try->format( 'Y-m-d' ), $tz );
			} else {
				$base = new \DateTime( 'now', $tz );
				$base->setTime( 0, 0, 0 );
				$base->modify( '-1 day' );
			}
		} else {
			$base = new \DateTime( 'now', $tz );
			$base->setTime( 0, 0, 0 );
			$base->modify( '-1 day' );
		}

		$days = array();
		for ( $i = 0; $i < 14; $i++ ) {
			$d = ( clone $base )->modify( '+' . $i . ' days' );
			$days[] = array(
				'ymd' => $d->format( 'Y-m-d' ),
				'dow' => $d->format( 'D' ),
				'day' => $d->format( 'j' ),
				'mon' => $d->format( 'M' ),
			);
		}

		$from      = $days[0]['ymd'];
		$last_ymd  = $days[ count( $days ) - 1 ]['ymd'];
		$to        = $last_ymd;

		$booking_repo = new Booking_Repository();
		$bookings     = $booking_repo->get_in_range( $from, $to );
		$plot_repo    = new Plot_Repository();
		$plots        = $plot_repo->get_all();
		$blocks_index = self::calendar_blocks_index( ( new Plot_Block_Repository() )->get_overlapping( $from, $to ) );

		$guest_reg_by_booking = ( new Guest_Registration_Repository() )->get_registration_ids_by_booking_ids(
			array_map(
				static function ( $b ) {
					return isset( $b['id'] ) ? (int) $b['id'] : 0;
				},
				$bookings
			)
		);

		$by_plot = array();
		foreach ( $plots as $p ) {
			$by_plot[ (int) $p['id'] ] = array();
		}
		foreach ( $bookings as $b ) {
			$pid = (int) $b['plot_id'];
			if ( ! isset( $by_plot[ $pid ] ) ) {
				$by_plot[ $pid ] = array();
			}
			$by_plot[ $pid ][] = $b;
		}

		$prev = ( clone $base )->modify( '-14 days' )->format( 'Y-m-d' );
		$next = ( clone $base )->modify( '+14 days' )->format( 'Y-m-d' );
		$today_ymd = ( new \DateTime( 'now', $tz ) )->format( 'Y-m-d' );

		?>
		<div class="wrap vila-cb-admin vila-cb-cal">
			<?php self::render_section_nav( self::PAGE_CAL ); ?>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Booking saved.', 'vila-campsite-booking' ); ?></p></div>
			<?php endif; ?>
			<div class="vila-cb-ui-hero">
				<div class="vila-cb-ui-hero__text">
					<h1 class="vila-cb-ui-title"><?php esc_html_e( 'Campsite · Calendar', 'vila-campsite-booking' ); ?></h1>
					<p class="vila-cb-ui-lead">
						<?php esc_html_e( '14-day occupancy grid for pitches and village stays. Website bookings appear after payment (processing, completed, or on-hold). Off-site stay bookings can be added under Village stays.', 'vila-campsite-booking' ); ?>
					</p>
				</div>
				<form method="get" class="vila-cb-daynav vila-cb-ui-nav">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_CAL ); ?>" />
					<button type="submit" class="button vila-cb-ui-nav-btn" name="vila_cb_cal" value="<?php echo esc_attr( $prev ); ?>" aria-label="<?php esc_attr_e( 'Previous 14 days', 'vila-campsite-booking' ); ?>">
						<span class="vila-cb-ui-nav-arrow" aria-hidden="true">‹</span>
						<span><?php esc_html_e( 'Prev', 'vila-campsite-booking' ); ?></span>
					</button>
					<div class="vila-cb-ui-nav-period">
						<span class="vila-cb-ui-nav-month"><?php echo esc_html( $base->format( 'F' ) ); ?></span>
						<span class="vila-cb-ui-nav-sub"><?php echo esc_html( $base->format( 'Y' ) ); ?></span>
					</div>
					<button type="submit" class="button vila-cb-ui-nav-btn vila-cb-ui-nav-btn--primary" name="vila_cb_cal" value="<?php echo esc_attr( $next ); ?>" aria-label="<?php esc_attr_e( 'Next 14 days', 'vila-campsite-booking' ); ?>">
						<span><?php esc_html_e( 'Next', 'vila-campsite-booking' ); ?></span>
						<span class="vila-cb-ui-nav-arrow" aria-hidden="true">›</span>
					</button>
				</form>
			</div>

			<div class="vila-cb-ui-table-wrap vila-cb-cal__table-wrap">
				<table class="vila-cb-grid">
					<thead>
						<tr>
							<th class="vila-cb-grid__plot" scope="col"><?php esc_html_e( 'Pitch / stay', 'vila-campsite-booking' ); ?></th>
							<?php foreach ( $days as $dl ) : ?>
								<?php
								$is_today = ( $dl['ymd'] === $today_ymd );
								$th_class = 'vila-cb-grid__day' . ( $is_today ? ' vila-cb-grid__day--today' : '' );
								?>
								<th class="<?php echo esc_attr( $th_class ); ?>" scope="col">
									<span class="vila-cb-grid__dow"><?php echo esc_html( $dl['dow'] ); ?></span>
									<span class="vila-cb-grid__date-stack">
										<span class="vila-cb-grid__num"><?php echo esc_html( $dl['day'] ); ?></span>
										<span class="vila-cb-grid__mon"><?php echo esc_html( $dl['mon'] ); ?></span>
									</span>
									<?php if ( $is_today ) : ?>
										<span class="vila-cb-grid__today-badge"><?php esc_html_e( 'Today', 'vila-campsite-booking' ); ?></span>
									<?php endif; ?>
								</th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php
						$day_count      = count( $days );
						$has_site_row   = ! empty( $blocks_index['global'] );
						if ( $has_site_row ) :
							?>
							<tr class="vila-cb-grid__row-site-closure">
								<th class="vila-cb-grid__plot-cell vila-cb-grid__plot-cell--closure" scope="row"><?php esc_html_e( 'All plots', 'vila-campsite-booking' ); ?></th>
								<?php
								$j = 0;
								while ( $j < $day_count ) :
									$ymd       = $days[ $j ]['ymd'];
									$block_seg = self::calendar_global_block_for_day( $blocks_index, $ymd );
									if ( null === $block_seg ) {
										echo '<td class="vila-cb-grid__cell--free vila-cb-grid__cell--closure-gap"><span class="vila-cb-grid__empty">' . esc_html( '—' ) . '</span></td>';
										$j++;
										continue;
									}
									$bid  = (int) $block_seg['id'];
									$span = 0;
									for ( $k = $j; $k < $day_count; $k++ ) {
										$sk = self::calendar_global_block_for_day( $blocks_index, $days[ $k ]['ymd'] );
										if ( null === $sk || (int) $sk['id'] !== $bid ) {
											break;
										}
										$span++;
									}
									$range_includes_today = self::calendar_day_range_includes( $days, $j, $span, $today_ymd );
									$cell_classes         = array_filter(
										array(
											'vila-cb-grid__blocked',
											'vila-cb-grid__blocked-bar',
											'vila-cb-grid__blocked-bar--site',
											$range_includes_today ? 'vila-cb-grid__cell--today' : '',
										)
									);
									$title = self::calendar_block_cell_title( $block_seg );
									echo '<td class="' . esc_attr( implode( ' ', $cell_classes ) ) . '" colspan="' . (int) $span . '" title="' . esc_attr( $title ) . '">';
									echo '<span class="vila-cb-grid__blocked-label">' . esc_html( self::calendar_block_cell_label( $block_seg ) ) . '</span>';
									echo '</td>';
									$j += $span;
								endwhile;
								?>
							</tr>
							<?php
						endif;
						Admin_Stays::render_calendar_rows( $days, $today_ymd );
						if ( ! empty( $plots ) ) {
							echo '<tr class="vila-cb-grid__row-section"><th class="vila-cb-grid__section-label" scope="colgroup" colspan="' . (int) ( $day_count + 1 ) . '">' . esc_html__( 'Pitches', 'vila-campsite-booking' ) . '</th></tr>';
						}
						foreach ( $plots as $plot ) :
							$pid = (int) $plot['id'];
							?>
							<tr>
								<th class="vila-cb-grid__plot-cell" scope="row"><?php echo esc_html( (string) $plot['label'] ); ?></th>
								<?php
								$j = 0;
								while ( $j < $day_count ) :
									$ymd = $days[ $j ]['ymd'];
									$seg = self::calendar_booking_for_plot_day( $by_plot, $pid, $ymd );
									if ( null === $seg ) {
										$block_seg = $has_site_row
											? self::calendar_plot_only_block_for_day( $blocks_index, $pid, $ymd )
											: self::calendar_block_for_plot_day( $blocks_index, $pid, $ymd );
										if ( null !== $block_seg ) {
											$bid  = (int) $block_seg['id'];
											$span = 0;
											for ( $k = $j; $k < $day_count; $k++ ) {
												$sk = $has_site_row
													? self::calendar_plot_only_block_for_day( $blocks_index, $pid, $days[ $k ]['ymd'] )
													: self::calendar_block_for_plot_day( $blocks_index, $pid, $days[ $k ]['ymd'] );
												if ( null === $sk || (int) $sk['id'] !== $bid ) {
													break;
												}
												$span++;
											}
											$range_includes_today = self::calendar_day_range_includes( $days, $j, $span, $today_ymd );
											$cell_classes         = array_filter(
												array(
													'vila-cb-grid__blocked',
													'vila-cb-grid__blocked-bar',
													$range_includes_today ? 'vila-cb-grid__cell--today' : '',
												)
											);
											$title = self::calendar_block_cell_title( $block_seg );
											echo '<td class="' . esc_attr( implode( ' ', $cell_classes ) ) . '" colspan="' . (int) $span . '" title="' . esc_attr( $title ) . '">';
											echo '<span class="vila-cb-grid__blocked-label">' . esc_html( self::calendar_block_cell_label( $block_seg ) ) . '</span>';
											echo '</td>';
											$j += $span;
											continue;
										}
										$span = 1;
										for ( $k = $j + 1; $k < $day_count; $k++ ) {
											if ( null !== self::calendar_booking_for_plot_day( $by_plot, $pid, $days[ $k ]['ymd'] ) ) {
												break;
											}
											$next_block = $has_site_row
												? self::calendar_plot_only_block_for_day( $blocks_index, $pid, $days[ $k ]['ymd'] )
												: self::calendar_block_for_plot_day( $blocks_index, $pid, $days[ $k ]['ymd'] );
											if ( null !== $next_block ) {
												break;
											}
											if ( $has_site_row && null !== self::calendar_global_block_for_day( $blocks_index, $days[ $k ]['ymd'] ) ) {
												break;
											}
											$span++;
										}
										$range_includes_today = self::calendar_day_range_includes( $days, $j, $span, $today_ymd );
										$cell_classes         = array_filter(
											array(
												'vila-cb-grid__cell--free',
												$range_includes_today ? 'vila-cb-grid__cell--today' : '',
											)
										);
										$title_dates = self::calendar_day_range_title( $days, $j, $span );
										echo '<td class="' . esc_attr( implode( ' ', $cell_classes ) ) . '" colspan="' . (int) $span . '" title="' . esc_attr( $title_dates ) . '">';
										echo '<span class="vila-cb-grid__empty">' . esc_html( __( '—', 'vila-campsite-booking' ) ) . '</span>';
										echo '</td>';
										$j += $span;
										continue;
									}

									$bid = (int) $seg['id'];
									$span = 0;
									for ( $k = $j; $k < $day_count; $k++ ) {
										$sk = self::calendar_booking_for_plot_day( $by_plot, $pid, $days[ $k ]['ymd'] );
										if ( null === $sk || (int) $sk['id'] !== $bid ) {
											break;
										}
										$span++;
									}

									$payload              = self::calendar_booking_cell_payload( $seg, $guest_reg_by_booking );
									$range_includes_today = self::calendar_day_range_includes( $days, $j, $span, $today_ymd );
									$cell_classes         = array_filter(
										array(
											'vila-cb-grid__busy',
											'vila-cb-grid__busy-bar',
											$range_includes_today ? 'vila-cb-grid__cell--today' : '',
										)
									);
									echo '<td class="' . esc_attr( implode( ' ', $cell_classes ) ) . '" colspan="' . (int) $span . '" title="' . esc_attr( $payload['cell_title'] ) . '">';
									echo $payload['cell_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built in render_calendar_busy_cell_markup().
									echo '</td>';
									$j += $span;
								endwhile;
								?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="vila-cb-ui-legend vila-cb-cal__legend" role="note">
				<span class="vila-cb-ui-legend-item">
					<span class="vila-cb-ui-swatch vila-cb-ui-swatch--free"></span>
					<?php esc_html_e( 'Available', 'vila-campsite-booking' ); ?>
				</span>
				<span class="vila-cb-ui-legend-item">
					<span class="vila-cb-ui-swatch vila-cb-ui-swatch--busy"></span>
					<?php esc_html_e( 'Booked (click to edit booking)', 'vila-campsite-booking' ); ?>
				</span>
				<span class="vila-cb-ui-legend-item">
					<span class="vila-cb-ui-swatch vila-cb-ui-swatch--blocked"></span>
					<?php esc_html_e( 'Blocked (maintenance / closed)', 'vila-campsite-booking' ); ?>
				</span>
				<span class="vila-cb-ui-legend-item">
					<span class="vila-cb-ui-swatch vila-cb-ui-swatch--today"></span>
					<?php esc_html_e( 'Current day column', 'vila-campsite-booking' ); ?>
				</span>
				<span class="vila-cb-ui-legend-item">
					<?php esc_html_e( 'Booked cells show stay dates (from–until), guest counts, and unit type when saved on the order line.', 'vila-campsite-booking' ); ?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * Booking row covering a calendar day for a plot, or null.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $by_plot Plot ID => booking rows.
	 * @param int                                           $pid   Plot ID.
	 * @param string                                        $ymd   Y-m-d.
	 * @return array<string, mixed>|null
	 */
	private static function calendar_booking_for_plot_day( array $by_plot, $pid, $ymd ) {
		$pid = (int) $pid;
		$ymd = (string) $ymd;
		foreach ( $by_plot[ $pid ] ?? array() as $seg ) {
			$nights = Dates::nights_in_range( (string) $seg['check_in'], (string) $seg['check_out'] );
			if ( in_array( $ymd, $nights, true ) ) {
				return $seg;
			}
		}
		return null;
	}

	/**
	 * Whether a contiguous day range in the grid includes "today".
	 *
	 * @param array<int, array{ymd: string}> $days      Grid columns.
	 * @param int                            $start_idx First index.
	 * @param int                            $span      Column count.
	 * @param string                         $today_ymd Today Y-m-d.
	 * @return bool
	 */
	private static function calendar_day_range_includes( array $days, $start_idx, $span, $today_ymd ) {
		$start_idx = (int) $start_idx;
		$span      = (int) $span;
		for ( $i = 0; $i < $span; $i++ ) {
			$idx = $start_idx + $i;
			if ( isset( $days[ $idx ]['ymd'] ) && $days[ $idx ]['ymd'] === $today_ymd ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Compact stay range for booking bar, e.g. 12/03 - 16/03 (check-out is the departure morning).
	 *
	 * @param string $check_in  Y-m-d inclusive.
	 * @param string $check_out Y-m-d exclusive end.
	 * @return string
	 */
	public static function calendar_booking_stay_date_range_label( $check_in, $check_out ) {
		$cin  = Dates::parse_ymd( (string) $check_in );
		$cout = Dates::parse_ymd( (string) $check_out );
		if ( ! $cin || ! $cout ) {
			return '';
		}
		$from = wp_date( 'd/m', $cin->getTimestamp() );
		$to   = wp_date( 'd/m', $cout->getTimestamp() );
		return $from . ' - ' . $to;
	}

	/**
	 * Title text for empty cells (date range shown in this segment).
	 *
	 * @param array<int, array{ymd: string}> $days      Grid columns.
	 * @param int                            $start_idx First index.
	 * @param int                            $span      Column count.
	 * @return string
	 */
	private static function calendar_day_range_title( array $days, $start_idx, $span ) {
		$start_idx = (int) $start_idx;
		$span      = (int) $span;
		if ( $span <= 0 || ! isset( $days[ $start_idx ]['ymd'] ) ) {
			return '';
		}
		$from = $days[ $start_idx ]['ymd'];
		$end  = $days[ $start_idx + $span - 1 ]['ymd'] ?? $from;
		if ( $from === $end ) {
			return $from;
		}
		return $from . ' – ' . $end;
	}

	/**
	 * Guest / order data for one booking bar.
	 *
	 * @param array<string, mixed> $seg Booking row.
	 * @param array<int, int>      $guest_reg_by_booking booking_id => registration_id.
	 * @return array{cell_title: string, cell_html: string}
	 */
	private static function calendar_booking_cell_payload( array $seg, array $guest_reg_by_booking = array() ) {
		$oid   = (int) $seg['order_id'];
		$order = $oid ? wc_get_order( $oid ) : null;
		$bid   = (int) $seg['id'];
		$url   = add_query_arg(
			array(
				'page'       => self::PAGE_BOOKING,
				'booking_id' => $bid,
			),
			admin_url( 'admin.php' )
		);
		if ( $order ) {
			$guest_name = trim( (string) $order->get_formatted_billing_full_name() );
			if ( '' === $guest_name ) {
				$guest_name = '#' . $order->get_order_number();
			}
		} else {
			$guest_name = '#' . $bid;
		}
		$line_id     = isset( $seg['line_item_id'] ) ? (int) $seg['line_item_id'] : 0;
		$gjson       = isset( $seg['guest_json'] ) ? $seg['guest_json'] : null;
		$counts      = WooCommerce_Integration::resolve_guest_counts_for_booking( $gjson, $order, $line_id );
		$detail_line = WooCommerce_Integration::format_guest_counts_calendar_line( $counts );
		$unit_type   = $order ? (string) WooCommerce_Integration::get_order_line_unit_type_label( $order, $line_id ) : '';

		$date_range = self::calendar_booking_stay_date_range_label( (string) $seg['check_in'], (string) $seg['check_out'] );
		$reg_id     = isset( $guest_reg_by_booking[ $bid ] ) ? (int) $guest_reg_by_booking[ $bid ] : 0;
		$submitted  = $reg_id > 0;

		$tip_bits = array();
		if ( '' !== $date_range ) {
			$tip_bits[] = $date_range;
		}
		$tip_bits[] = $guest_name;
		$tip_bits[] = $submitted
			? __( 'Guest details received', 'vila-campsite-booking' )
			: __( 'Guest details pending', 'vila-campsite-booking' );
		$tip_bits[] = $detail_line;
		if ( '' !== $unit_type ) {
			$tip_bits[] = $unit_type;
		}
		$tip_bits = array_filter( $tip_bits );

		return array(
			'cell_title' => implode( ' — ', $tip_bits ),
			'cell_html'  => self::render_calendar_busy_cell_markup( $guest_name, $detail_line, $unit_type, $url, $date_range, $submitted, $reg_id ),
		);
	}

	/**
	 * Markup for a booked calendar cell (guest name, breakdown, unit type).
	 *
	 * @param string $guest_name  Display name.
	 * @param string $detail_line From format_guest_counts_calendar_line().
	 * @param string $unit_type   Unit type label, e.g. Caravan.
	 * @param string $url         Booking edit URL or empty.
	 * @param string $date_range  Compact d/m - d/m range or empty.
	 * @param bool   $submitted   Whether guest registration exists.
	 * @param int    $reg_id      Registration ID when submitted.
	 * @param bool   $show_checkin_badge Whether to show the legal guest-registration badge.
	 * @return string Safe HTML.
	 */
	public static function render_calendar_busy_cell_markup( $guest_name, $detail_line, $unit_type, $url, $date_range = '', $submitted = false, $reg_id = 0, $show_checkin_badge = true ) {
		$guest_name  = (string) $guest_name;
		$detail_line = (string) $detail_line;
		$unit_type   = (string) $unit_type;
		$url         = (string) $url;
		$date_range  = (string) $date_range;

		$inner  = '';
		if ( '' !== $date_range ) {
			$inner .= '<span class="vila-cb-grid__cell-dates">' . esc_html( $date_range ) . '</span>';
		}
		$inner .= '<span class="vila-cb-grid__cell-name">' . esc_html( $guest_name ) . '</span>';
		if ( $show_checkin_badge ) {
			$inner .= self::guest_checkin_badge_html( (bool) $submitted, (int) $reg_id, 'compact' );
		}
		$inner .= '' !== $detail_line
			? '<span class="vila-cb-grid__cell-guests">' . esc_html( $detail_line ) . '</span>'
			: '';
		$inner .= '' !== $unit_type
			? '<span class="vila-cb-grid__cell-unit">' . esc_html( $unit_type ) . '</span>'
			: '';

		if ( '' !== $url ) {
			return '<a class="vila-cb-grid__cell-link" href="' . esc_url( $url ) . '">' . $inner . '</a>';
		}

		return '<span class="vila-cb-grid__cell-link vila-cb-grid__cell-link--static">' . $inner . '</span>';
	}

	/**
	 * Green / yellow badge for legal guest-registration completeness.
	 *
	 * @param bool   $submitted Whether a registration row exists for the booking.
	 * @param int    $reg_id    Registration ID (optional link target for large badges).
	 * @param string $size      default|compact|large.
	 * @return string Safe HTML.
	 */
	public static function guest_checkin_badge_html( $submitted, $reg_id = 0, $size = 'default' ) {
		$submitted = (bool) $submitted;
		$size      = in_array( $size, array( 'default', 'compact', 'large' ), true ) ? $size : 'default';
		$classes   = array(
			'vila-cb-guest-badge',
			$submitted ? 'vila-cb-guest-badge--ok' : 'vila-cb-guest-badge--pending',
			'vila-cb-guest-badge--' . $size,
		);
		$label = $submitted
			? __( 'Details received', 'vila-campsite-booking' )
			: __( 'Details pending', 'vila-campsite-booking' );
		$title = $submitted
			? __( 'Legal guest registration has been submitted for this booking.', 'vila-campsite-booking' )
			: __( 'Guests have not submitted legal registration details yet.', 'vila-campsite-booking' );

		$html = '<span class="' . esc_attr( implode( ' ', $classes ) ) . '" title="' . esc_attr( $title ) . '">' . esc_html( $label ) . '</span>';

		if ( $submitted && (int) $reg_id > 0 && 'compact' !== $size ) {
			$url = admin_url( 'admin.php?page=' . Admin_Guest_Registrations::PAGE . '&registration_id=' . (int) $reg_id );
			$html = '<a class="vila-cb-guest-badge-link" href="' . esc_url( $url ) . '">' . $html . '</a>';
		}

		return $html;
	}

	/**
	 * Plots list.
	 */
	public static function render_plots() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		self::enqueue_admin_assets();

		$repo  = new Plot_Repository();
		$plots = $repo->get_all();

		?>
		<div class="wrap vila-cb-admin vila-cb-plots">
			<?php self::render_section_nav( self::PAGE_PLOTS ); ?>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Plots saved.', 'vila-campsite-booking' ); ?></p></div>
			<?php endif; ?>
			<div class="vila-cb-ui-hero">
				<div class="vila-cb-ui-hero__text">
					<h1 class="vila-cb-ui-title"><?php esc_html_e( 'Campsite · Plots', 'vila-campsite-booking' ); ?></h1>
					<p class="vila-cb-ui-lead"><?php esc_html_e( 'Name and order each pitch. Availability and the booking calendar use this list.', 'vila-campsite-booking' ); ?></p>
				</div>
			</div>
			<form method="post" class="vila-cb-plots-form">
				<?php wp_nonce_field( 'vila_cb_plots', 'vila_cb_plots_nonce' ); ?>
				<div class="vila-cb-ui-panel">
					<h2 class="vila-cb-ui-section-title"><?php esc_html_e( 'All plots', 'vila-campsite-booking' ); ?></h2>
					<div class="vila-cb-ui-table-wrap">
						<table class="vila-cb-form-table vila-cb-plot-rows">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Label', 'vila-campsite-booking' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Sort', 'vila-campsite-booking' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Delete', 'vila-campsite-booking' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $plots as $p ) : ?>
									<tr>
										<td>
											<input type="text" class="regular-text" name="vila_cb_plot[<?php echo esc_attr( (string) (int) $p['id'] ); ?>][label]" value="<?php echo esc_attr( (string) $p['label'] ); ?>" />
										</td>
										<td>
											<input type="number" class="small-text" name="vila_cb_plot[<?php echo esc_attr( (string) (int) $p['id'] ); ?>][sort]" value="<?php echo esc_attr( (string) (int) $p['sort_order'] ); ?>" />
										</td>
										<td>
											<label class="vila-cb-ui-check"><input type="checkbox" name="vila_cb_plot[<?php echo esc_attr( (string) (int) $p['id'] ); ?>][delete]" value="1" /> <?php esc_html_e( 'Delete', 'vila-campsite-booking' ); ?></label>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
				<div class="vila-cb-ui-panel vila-cb-ui-panel--compact">
					<h2 class="vila-cb-ui-section-title"><?php esc_html_e( 'Add plot', 'vila-campsite-booking' ); ?></h2>
					<p class="vila-cb-ui-field">
						<label for="vila_cb_new_label" class="screen-reader-text"><?php esc_html_e( 'New plot label', 'vila-campsite-booking' ); ?></label>
						<input type="text" name="vila_cb_new_label" id="vila_cb_new_label" class="regular-text" placeholder="<?php esc_attr_e( 'New plot label', 'vila-campsite-booking' ); ?>" />
					</p>
					<?php submit_button( __( 'Save plots', 'vila-campsite-booking' ), 'primary', '', false, array( 'class' => 'vila-cb-ui-submit' ) ); ?>
				</div>
			</form>
			<p class="vila-cb-ui-footnote">
				<?php
				$blocks_link = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_BLOCKS ) ) . '">' . esc_html__( 'Campsite · Block dates', 'vila-campsite-booking' ) . '</a>';
				echo wp_kses_post(
					sprintf(
						/* translators: %s: link to Block dates admin screen */
						__( 'To close dates for one pitch or the whole campsite, use %s.', 'vila-campsite-booking' ),
						$blocks_link
					)
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Block dates (per plot or all plots).
	 */
	public static function render_blocks() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		self::enqueue_admin_assets();

		$repo   = new Plot_Block_Repository();
		$blocks = $repo->get_all();
		$plots  = ( new Plot_Repository() )->get_all();
		$df     = self::wp_date_format_pattern();

		?>
		<div class="wrap vila-cb-admin vila-cb-blocks">
			<?php self::render_section_nav( self::PAGE_BLOCKS ); ?>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Date blocks saved.', 'vila-campsite-booking' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( Request_String::scalar( $_GET['error'] ) ) ) ); ?></p></div>
			<?php endif; ?>
			<div class="vila-cb-ui-hero">
				<div class="vila-cb-ui-hero__text">
					<h1 class="vila-cb-ui-title"><?php esc_html_e( 'Campsite · Block dates', 'vila-campsite-booking' ); ?></h1>
					<p class="vila-cb-ui-lead">
						<?php esc_html_e( 'Close pitches for maintenance or block the whole campsite. Blocked nights cannot be booked online and appear on the calendar. To hold a village stay (tiny house / caravan) booked off the website, use Village stays.', 'vila-campsite-booking' ); ?>
					</p>
				</div>
			</div>

			<div class="vila-cb-ui-panel vila-cb-ui-panel--compact">
				<h2 class="vila-cb-ui-section-title"><?php esc_html_e( 'Add block', 'vila-campsite-booking' ); ?></h2>
				<form method="post" class="vila-cb-blocks-add-form">
					<?php wp_nonce_field( 'vila_cb_blocks', 'vila_cb_blocks_nonce' ); ?>
					<input type="hidden" name="vila_cb_blocks_action" value="add" />
					<div class="vila-cb-blocks-add-grid">
						<p class="vila-cb-ui-field">
							<label for="vila_cb_block_scope"><?php esc_html_e( 'Applies to', 'vila-campsite-booking' ); ?></label>
							<select name="vila_cb_block_plot_id" id="vila_cb_block_scope" class="regular-text">
								<option value="0"><?php esc_html_e( 'All plots (whole campsite)', 'vila-campsite-booking' ); ?></option>
								<?php foreach ( $plots as $p ) : ?>
									<option value="<?php echo esc_attr( (string) (int) $p['id'] ); ?>"><?php echo esc_html( (string) $p['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p class="vila-cb-ui-field">
							<label for="vila_cb_block_start"><?php esc_html_e( 'First blocked night', 'vila-campsite-booking' ); ?></label>
							<input type="date" name="vila_cb_block_start" id="vila_cb_block_start" required />
						</p>
						<p class="vila-cb-ui-field">
							<label for="vila_cb_block_end"><?php esc_html_e( 'Reopens on (checkout day)', 'vila-campsite-booking' ); ?></label>
							<input type="date" name="vila_cb_block_end" id="vila_cb_block_end" required />
						</p>
						<p class="vila-cb-ui-field vila-cb-blocks-add-grid__note">
							<label for="vila_cb_block_note"><?php esc_html_e( 'Note (optional)', 'vila-campsite-booking' ); ?></label>
							<input type="text" name="vila_cb_block_note" id="vila_cb_block_note" class="large-text" maxlength="255" placeholder="<?php esc_attr_e( 'e.g. Maintenance, private event', 'vila-campsite-booking' ); ?>" />
						</p>
					</div>
					<p class="description"><?php esc_html_e( 'Uses the same dates as bookings: the last blocked night is the day before “Reopens on”. Guests cannot check in or out on a blocked night.', 'vila-campsite-booking' ); ?></p>
					<?php submit_button( __( 'Add block', 'vila-campsite-booking' ), 'primary', '', false, array( 'class' => 'vila-cb-ui-submit' ) ); ?>
				</form>
			</div>

			<form method="post" class="vila-cb-blocks-list-form">
				<?php wp_nonce_field( 'vila_cb_blocks', 'vila_cb_blocks_nonce' ); ?>
				<input type="hidden" name="vila_cb_blocks_action" value="delete" />
				<div class="vila-cb-ui-panel">
					<h2 class="vila-cb-ui-section-title"><?php esc_html_e( 'Active blocks', 'vila-campsite-booking' ); ?></h2>
					<?php if ( empty( $blocks ) ) : ?>
						<p class="description"><?php esc_html_e( 'No date blocks yet.', 'vila-campsite-booking' ); ?></p>
					<?php else : ?>
						<div class="vila-cb-ui-table-wrap">
							<table class="widefat striped vila-cb-form-table">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Plot', 'vila-campsite-booking' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Blocked nights', 'vila-campsite-booking' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Note', 'vila-campsite-booking' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Remove', 'vila-campsite-booking' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php
									$plot_labels = array();
									foreach ( $plots as $p ) {
										$plot_labels[ (int) $p['id'] ] = (string) $p['label'];
									}
									foreach ( $blocks as $b ) :
										$pid   = (int) $b['plot_id'];
										$scope = Plot_Block_Repository::PLOT_ID_ALL === $pid
											? __( 'All plots', 'vila-campsite-booking' )
											: ( isset( $plot_labels[ $pid ] ) ? $plot_labels[ $pid ] : sprintf( __( 'Plot #%d', 'vila-campsite-booking' ), $pid ) );
										$start = Dates::parse_ymd( (string) $b['start_ymd'] );
										$end   = Dates::parse_ymd( (string) $b['end_ymd'] );
										$range = ( $start && $end )
											? sprintf(
												/* translators: 1: first blocked night, 2: reopening day */
												__( '%1$s → %2$s', 'vila-campsite-booking' ),
												date_i18n( $df, $start->getTimestamp() ),
												date_i18n( $df, $end->getTimestamp() )
											)
											: (string) $b['start_ymd'] . ' → ' . (string) $b['end_ymd'];
										?>
										<tr>
											<td><?php echo esc_html( $scope ); ?></td>
											<td><?php echo esc_html( $range ); ?></td>
											<td><?php echo esc_html( (string) ( $b['note'] ?? '' ) ); ?></td>
											<td>
												<label class="vila-cb-ui-check">
													<input type="checkbox" name="vila_cb_block_delete[]" value="<?php echo esc_attr( (string) (int) $b['id'] ); ?>" />
													<?php esc_html_e( 'Delete', 'vila-campsite-booking' ); ?>
												</label>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<?php submit_button( __( 'Remove selected blocks', 'vila-campsite-booking' ), 'secondary', '', false, array( 'class' => 'vila-cb-ui-submit' ) ); ?>
					<?php endif; ?>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Save / delete date blocks.
	 */
	public static function handle_blocks_save() {
		if ( ! isset( $_POST['vila_cb_blocks_nonce'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_blocks_nonce'] ) ) ), 'vila_cb_blocks' ) ) {
			return;
		}

		$repo   = new Plot_Block_Repository();
		$action = isset( $_POST['vila_cb_blocks_action'] ) ? sanitize_key( wp_unslash( Request_String::scalar( $_POST['vila_cb_blocks_action'] ) ) ) : '';
		$base   = admin_url( 'admin.php?page=' . self::PAGE_BLOCKS );

		if ( 'delete' === $action ) {
			if ( ! empty( $_POST['vila_cb_block_delete'] ) && is_array( $_POST['vila_cb_block_delete'] ) ) {
				foreach ( $_POST['vila_cb_block_delete'] as $raw_id ) {
					$repo->delete( (int) wp_unslash( Request_String::scalar( $raw_id ) ) );
				}
			}
			wp_safe_redirect( add_query_arg( 'updated', '1', $base ) );
			exit;
		}

		if ( 'add' !== $action ) {
			return;
		}

		$plot_id = isset( $_POST['vila_cb_block_plot_id'] ) ? (int) wp_unslash( Request_String::scalar( $_POST['vila_cb_block_plot_id'] ) ) : 0;
		$start   = isset( $_POST['vila_cb_block_start'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_block_start'] ) ) ) : '';
		$end     = isset( $_POST['vila_cb_block_end'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_block_end'] ) ) ) : '';
		$note    = isset( $_POST['vila_cb_block_note'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_block_note'] ) ) ) : '';

		$range = Plot_Block_Repository::normalize_range( $start, $end );
		if ( ! $range ) {
			wp_safe_redirect(
				add_query_arg(
					'error',
					rawurlencode( __( 'Invalid dates. Check-out (reopens on) must be after the first blocked night.', 'vila-campsite-booking' ) ),
					$base
				)
			);
			exit;
		}

		if ( $plot_id > 0 && ! ( new Plot_Repository() )->get( $plot_id ) ) {
			wp_safe_redirect(
				add_query_arg(
					'error',
					rawurlencode( __( 'Unknown plot.', 'vila-campsite-booking' ) ),
					$base
				)
			);
			exit;
		}

		$repo->insert(
			array(
				'plot_id'    => max( 0, $plot_id ),
				'start_ymd'  => $range['start'],
				'end_ymd'    => $range['end'],
				'note'       => '' !== $note ? $note : null,
				'created_at' => current_time( 'mysql' ),
			)
		);

		wp_safe_redirect( add_query_arg( 'updated', '1', $base ) );
		exit;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Block rows.
	 * @return array{global: array<int, array<string, mixed>>, by_plot: array<int, array<int, array<string, mixed>>>}
	 */
	private static function calendar_blocks_index( array $rows ) {
		$index = array(
			'global'  => array(),
			'by_plot' => array(),
		);
		foreach ( $rows as $row ) {
			$pid = (int) $row['plot_id'];
			if ( Plot_Block_Repository::PLOT_ID_ALL === $pid ) {
				$index['global'][] = $row;
			} else {
				if ( ! isset( $index['by_plot'][ $pid ] ) ) {
					$index['by_plot'][ $pid ] = array();
				}
				$index['by_plot'][ $pid ][] = $row;
			}
		}
		return $index;
	}

	/**
	 * @param array{global: array<int, array<string, mixed>>, by_plot: array<int, array<int, array<string, mixed>>>} $index Blocks index.
	 * @param int    $plot_id Plot ID.
	 * @param string $ymd     Y-m-d.
	 * @return array<string, mixed>|null
	 */
	private static function calendar_global_block_for_day( array $index, $ymd ) {
		$ymd = (string) $ymd;
		foreach ( $index['global'] as $row ) {
			if ( self::calendar_night_in_block( $ymd, $row ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Plot-specific block only (not site-wide).
	 *
	 * @param array{global: array<int, array<string, mixed>>, by_plot: array<int, array<int, array<string, mixed>>>} $index Blocks index.
	 * @param int    $plot_id Plot ID.
	 * @param string $ymd     Y-m-d.
	 * @return array<string, mixed>|null
	 */
	private static function calendar_plot_only_block_for_day( array $index, $plot_id, $ymd ) {
		$plot_id = (int) $plot_id;
		$ymd     = (string) $ymd;
		if ( ! isset( $index['by_plot'][ $plot_id ] ) ) {
			return null;
		}
		foreach ( $index['by_plot'][ $plot_id ] as $row ) {
			if ( self::calendar_night_in_block( $ymd, $row ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * @param array{global: array<int, array<string, mixed>>, by_plot: array<int, array<int, array<string, mixed>>>} $index Blocks index.
	 * @param int    $plot_id Plot ID.
	 * @param string $ymd     Y-m-d.
	 * @return array<string, mixed>|null
	 */
	private static function calendar_block_for_plot_day( array $index, $plot_id, $ymd ) {
		$plot_id = (int) $plot_id;
		$ymd     = (string) $ymd;
		foreach ( $index['global'] as $row ) {
			if ( self::calendar_night_in_block( $ymd, $row ) ) {
				return $row;
			}
		}
		if ( isset( $index['by_plot'][ $plot_id ] ) ) {
			foreach ( $index['by_plot'][ $plot_id ] as $row ) {
				if ( self::calendar_night_in_block( $ymd, $row ) ) {
					return $row;
				}
			}
		}
		return null;
	}

	/**
	 * @param string               $ymd Y-m-d.
	 * @param array<string, mixed> $row Block row.
	 * @return bool
	 */
	private static function calendar_night_in_block( $ymd, array $row ) {
		$d = Dates::parse_ymd( $ymd );
		$bs = Dates::parse_ymd( (string) $row['start_ymd'] );
		$be = Dates::parse_ymd( (string) $row['end_ymd'] );
		return $d && $bs && $be && $d >= $bs && $d < $be;
	}

	/**
	 * @param array<string, mixed> $row Block row.
	 * @return string
	 */
	private static function calendar_block_cell_label( array $row ) {
		$note = isset( $row['note'] ) ? trim( (string) $row['note'] ) : '';
		if ( '' !== $note ) {
			return $note;
		}
		return Plot_Block_Repository::PLOT_ID_ALL === (int) $row['plot_id']
			? __( 'Site closed', 'vila-campsite-booking' )
			: __( 'Blocked', 'vila-campsite-booking' );
	}

	/**
	 * @param array<string, mixed> $row Block row.
	 * @return string
	 */
	private static function calendar_block_cell_title( array $row ) {
		$df    = self::wp_date_format_pattern();
		$start = Dates::parse_ymd( (string) $row['start_ymd'] );
		$end   = Dates::parse_ymd( (string) $row['end_ymd'] );
		$range = ( $start && $end )
			? date_i18n( $df, $start->getTimestamp() ) . ' → ' . date_i18n( $df, $end->getTimestamp() )
			: (string) $row['start_ymd'] . ' → ' . (string) $row['end_ymd'];
		$label = self::calendar_block_cell_label( $row );
		return $label . ' · ' . $range;
	}

	/**
	 * Plugin + WC product settings.
	 */
	public static function render_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		self::enqueue_admin_assets();

		$product_id = (int) get_option( 'vila_cb_product_id', 0 );
		$plot_count = (int) get_option( 'vila_cb_plot_count', count( Plot_Repository::default_plot_numbers() ) );
		$pricing    = Pricing_Settings::get();
		$siba       = Guest_Registration_Exporter::settings();
		$childcare  = Childcare_Service::settings();

		?>
		<div class="wrap vila-cb-admin vila-cb-settings">
			<?php self::render_section_nav( self::PAGE_SETTINGS ); ?>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'vila-campsite-booking' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['vila_cb_pages_created'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Guest and meal pages were created (or refreshed).', 'vila-campsite-booking' ); ?></p></div>
			<?php endif; ?>
			<div class="vila-cb-ui-hero">
				<div class="vila-cb-ui-hero__text">
					<h1 class="vila-cb-ui-title"><?php esc_html_e( 'Campsite · Settings', 'vila-campsite-booking' ); ?></h1>
					<p class="vila-cb-ui-lead"><?php esc_html_e( 'Connect WooCommerce checkout to the campsite product, configure nightly rates in euros, and seed plot rows.', 'vila-campsite-booking' ); ?></p>
				</div>
			</div>
			<form method="post" class="vila-cb-settings-form">
				<?php wp_nonce_field( 'vila_cb_settings', 'vila_cb_settings_nonce' ); ?>
				<div class="vila-cb-ui-panel">
					<h2 class="vila-cb-ui-section-title"><?php esc_html_e( 'Notion sync', 'vila-campsite-booking' ); ?></h2>
					<p class="description"><?php esc_html_e( 'When a campsite or meal booking is paid or edited, WordPress pushes updates to the Campsite Bookings and Campsite Meals databases in Notion. The Telegram bot reads those databases only (it does not call the website). Put the Notion integration key in wp-config.php so it stays out of the database and git.', 'vila-campsite-booking' ); ?></p>
					<table class="form-table vila-cb-ui-form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Notion API key', 'vila-campsite-booking' ); ?></th>
							<td>
								<?php if ( Notion_Sync::is_configured() ) : ?>
									<span class="vila-cb-guest-badge vila-cb-guest-badge--ok"><?php esc_html_e( 'Configured (VILA_NOTION_API_KEY)', 'vila-campsite-booking' ); ?></span>
								<?php else : ?>
									<span class="vila-cb-guest-badge vila-cb-guest-badge--pending"><?php esc_html_e( 'Missing — add VILA_NOTION_API_KEY to wp-config.php', 'vila-campsite-booking' ); ?></span>
								<?php endif; ?>
								<p class="description"><code>define( 'VILA_NOTION_API_KEY', 'your-notion-integration-key' );</code></p>
								<p class="description"><?php esc_html_e( 'Optional: VILA_NOTION_BOOKINGS_DB_ID, VILA_NOTION_MEALS_DB_ID, and VILA_NOTION_CHILDCARE_DB_ID if database IDs are known. Otherwise they are resolved from the catalog option or Notion search on first sync.', 'vila-campsite-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Databases', 'vila-campsite-booking' ); ?></th>
							<td>
								<p class="description"><?php echo esc_html( Notion_Sync::bookings_database_name() ); ?> · <?php echo esc_html( Notion_Sync::meals_database_name() ); ?> · <?php echo esc_html( Notion_Sync::childcare_database_name() ); ?></p>
								<p class="description"><?php esc_html_e( 'Create those databases with npm run notion:setup, then add Calendar layouts on Date in Notion. Telegram recap and questions read from these databases.', 'vila-campsite-booking' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="vila-cb-ui-panel">
					<h2 class="vila-cb-ui-section-title"><?php esc_html_e( 'WooCommerce', 'vila-campsite-booking' ); ?></h2>
					<table class="form-table vila-cb-ui-form-table" role="presentation">
						<tr>
							<th scope="row"><label for="vila_cb_product_id"><?php esc_html_e( 'Campsite product', 'vila-campsite-booking' ); ?></label></th>
							<td>
								<input name="vila_cb_product_id" id="vila_cb_product_id" type="number" min="0" step="1" value="<?php echo esc_attr( (string) $product_id ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Use one product ID for checkout and pricing (per adult per night on that product). Pitches are not tied to this product title: inventory lives in Campsite → Plots (e.g. 15 rows). On checkout we assign the first pitch that is free for the whole stay; availability and the calendar use all plots.', 'vila-campsite-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="vila_cb_plot_count"><?php esc_html_e( 'Default plot count (seed)', 'vila-campsite-booking' ); ?></label></th>
							<td>
								<input name="vila_cb_plot_count" id="vila_cb_plot_count" type="number" min="1" step="1" value="<?php echo esc_attr( (string) $plot_count ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( 'Used when seeding new plot rows on upgrade. Defaults match plots 1–34 on the map (skipping 6, 16–17, 20, 22, 25–27, 29). Save settings to add any missing rows.', 'vila-campsite-booking' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="vila-cb-ui-panel">
					<h2 class="vila-cb-ui-section-title"><?php esc_html_e( 'Currency', 'vila-campsite-booking' ); ?></h2>
					<p class="description"><?php esc_html_e( 'All campsite booking prices, cart totals, and payments use EUR. Saving these settings also sets WooCommerce → Settings → General → Currency to Euro (€).', 'vila-campsite-booking' ); ?></p>
					<p class="description"><strong><?php esc_html_e( 'Current store currency:', 'vila-campsite-booking' ); ?></strong> <?php echo esc_html( get_woocommerce_currency() ); ?> — <?php echo wp_kses_post( wc_price( 1 ) ); ?> <?php esc_html_e( 'sample', 'vila-campsite-booking' ); ?></p>
				</div>

				<div class="vila-cb-ui-panel">
					<h2 class="vila-cb-ui-section-title"><?php esc_html_e( 'SIBA export', 'vila-campsite-booking' ); ?></h2>
					<p class="description"><?php esc_html_e( 'These fields build the BA03 header and the Web Service MovimentoBAL XML for SIBA.', 'vila-campsite-booking' ); ?></p>
					<p class="description"><?php esc_html_e( 'Establishment number is from the SIBA portal (0 is valid). Access key is the activation key from the ofício — used for Web Service only.', 'vila-campsite-booking' ); ?></p>
					<table class="form-table vila-cb-ui-form-table" role="presentation">
						<tr>
							<th scope="row"><label for="vila_cb_siba_nif"><?php esc_html_e( 'NIF', 'vila-campsite-booking' ); ?></label></th>
							<td><input name="vila_cb_siba[nif]" id="vila_cb_siba_nif" type="text" value="<?php echo esc_attr( $siba['nif'] ); ?>" class="regular-text" maxlength="9" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="vila_cb_siba_establishment"><?php esc_html_e( 'Establishment number', 'vila-campsite-booking' ); ?></label></th>
							<td><input name="vila_cb_siba[establishment]" id="vila_cb_siba_establishment" type="text" value="<?php echo esc_attr( $siba['establishment'] ); ?>" class="regular-text" maxlength="4" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="vila_cb_siba_access_key"><?php esc_html_e( 'Access key (chave de ativação)', 'vila-campsite-booking' ); ?></label></th>
							<td><input name="vila_cb_siba[access_key]" id="vila_cb_siba_access_key" type="text" value="<?php echo esc_attr( $siba['access_key'] ); ?>" class="regular-text" autocomplete="off" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="vila_cb_siba_environment"><?php esc_html_e( 'Web Service environment', 'vila-campsite-booking' ); ?></label></th>
							<td>
								<select name="vila_cb_siba[environment]" id="vila_cb_siba_environment">
									<option value="dev" <?php selected( $siba['environment'], 'dev' ); ?>><?php esc_html_e( 'Development (bawsdev) — test first', 'vila-campsite-booking' ); ?></option>
									<option value="prod" <?php selected( $siba['environment'], 'prod' ); ?>><?php esc_html_e( 'Production (baws)', 'vila-campsite-booking' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="vila_cb_siba_unit_name"><?php esc_html_e( 'Unit name', 'vila-campsite-booking' ); ?></label></th>
							<td><input name="vila_cb_siba[unit_name]" id="vila_cb_siba_unit_name" type="text" value="<?php echo esc_attr( $siba['unit_name'] ); ?>" class="regular-text" maxlength="40" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="vila_cb_siba_abbreviation"><?php esc_html_e( 'Abbreviation', 'vila-campsite-booking' ); ?></label></th>
							<td><input name="vila_cb_siba[abbreviation]" id="vila_cb_siba_abbreviation" type="text" value="<?php echo esc_attr( $siba['abbreviation'] ); ?>" class="regular-text" maxlength="15" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="vila_cb_siba_address"><?php esc_html_e( 'Address', 'vila-campsite-booking' ); ?></label></th>
							<td><input name="vila_cb_siba[address]" id="vila_cb_siba_address" type="text" value="<?php echo esc_attr( $siba['address'] ); ?>" class="regular-text" maxlength="40" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="vila_cb_siba_locality"><?php esc_html_e( 'Locality', 'vila-campsite-booking' ); ?></label></th>
							<td><input name="vila_cb_siba[locality]" id="vila_cb_siba_locality" type="text" value="<?php echo esc_attr( $siba['locality'] ); ?>" class="regular-text" maxlength="30" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="vila_cb_siba_postal"><?php esc_html_e( 'Postal code', 'vila-campsite-booking' ); ?></label></th>
							<td><input name="vila_cb_siba[postal_code]" id="vila_cb_siba_postal" type="text" value="<?php echo esc_attr( $siba['postal_code'] ); ?>" class="regular-text" maxlength="8" placeholder="0000-000" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="vila_cb_siba_phone"><?php esc_html_e( 'Phone', 'vila-campsite-booking' ); ?></label></th>
							<td><input name="vila_cb_siba[phone]" id="vila_cb_siba_phone" type="text" value="<?php echo esc_attr( $siba['phone'] ); ?>" class="regular-text" maxlength="10" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="vila_cb_siba_fax"><?php esc_html_e( 'Fax', 'vila-campsite-booking' ); ?></label></th>
							<td><input name="vila_cb_siba[fax]" id="vila_cb_siba_fax" type="text" value="<?php echo esc_attr( $siba['fax'] ); ?>" class="regular-text" maxlength="10" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="vila_cb_siba_contact_name"><?php esc_html_e( 'Contact name', 'vila-campsite-booking' ); ?></label></th>
							<td><input name="vila_cb_siba[contact_name]" id="vila_cb_siba_contact_name" type="text" value="<?php echo esc_attr( $siba['contact_name'] ); ?>" class="regular-text" maxlength="40" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="vila_cb_siba_contact_email"><?php esc_html_e( 'Contact email', 'vila-campsite-booking' ); ?></label></th>
							<td><input name="vila_cb_siba[contact_email]" id="vila_cb_siba_contact_email" type="email" value="<?php echo esc_attr( $siba['contact_email'] ); ?>" class="regular-text" maxlength="140" /></td>
						</tr>
					</table>
				</div>

				<div class="vila-cb-ui-panel">
					<h2 class="vila-cb-ui-section-title"><?php esc_html_e( 'Nightly rates (EUR)', 'vila-campsite-booking' ); ?></h2>
					<p class="description"><?php echo esc_html( sprintf( __( 'Vila Campsite Booking %s — these values drive checkout line totals, pitch supplements, and guest-category fees. Amounts are per night unless noted.', 'vila-campsite-booking' ), defined( 'VILA_CB_VERSION' ) ? (string) VILA_CB_VERSION : '' ) ); ?></p>
					<p class="description"><?php esc_html_e( 'Long-stay campsite pricing (pitch, extras, and guest supplements; meals stay per night): 1 night free per full week; each full 30 nights is a flat monthly rate (guests and electric included). Village accommodation bookings still use the 30-for-20 night discount.', 'vila-campsite-booking' ); ?></p>
					<table class="form-table vila-cb-ui-form-table" role="presentation">
						<tr>
							<th scope="row"><label for="vila_cb_pricing_adult"><?php esc_html_e( 'Adult rate (override)', 'vila-campsite-booking' ); ?></label></th>
							<td>
								<input name="vila_cb_pricing[adult_per_night]" id="vila_cb_pricing_adult" type="number" min="0" step="0.01" value="<?php echo esc_attr( (string) $pricing['adult_per_night'] ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( 'Per adult, per night. Leave 0 to use the campsite product regular price from WooCommerce (still calculated and charged in EUR).', 'vila-campsite-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Children', 'vila-campsite-booking' ); ?></th>
							<td>
								<input name="vila_cb_pricing[guest_rates][children]" type="number" min="0" step="0.01" value="<?php echo esc_attr( (string) $pricing['guest_rates']['children'] ); ?>" class="small-text" /> <?php esc_html_e( 'EUR / child / night', 'vila-campsite-booking' ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Babies', 'vila-campsite-booking' ); ?></th>
							<td>
								<input name="vila_cb_pricing[guest_rates][infants]" type="number" min="0" step="0.01" value="<?php echo esc_attr( (string) $pricing['guest_rates']['infants'] ); ?>" class="small-text" /> <?php esc_html_e( 'EUR / baby / night', 'vila-campsite-booking' ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Pets', 'vila-campsite-booking' ); ?></th>
							<td>
								<input name="vila_cb_pricing[guest_rates][pets]" type="number" min="0" step="0.01" value="<?php echo esc_attr( (string) $pricing['guest_rates']['pets'] ); ?>" class="small-text" /> <?php esc_html_e( 'EUR / pet / night', 'vila-campsite-booking' ); ?>
							</td>
						</tr>
					</table>
					<h3 class="vila-cb-ui-section-title" style="margin-top:1.5em;font-size:14px;"><?php esc_html_e( 'Extras', 'vila-campsite-booking' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Per-night amounts for each option on the booking form (pitch type, electricity, car, etc.). Selected lines add together.', 'vila-campsite-booking' ); ?></p>
					<table class="form-table vila-cb-ui-form-table" role="presentation">
						<?php
						$vlabels = array();
						if ( function_exists( 'vila_akoya_get_vehicle_types' ) ) {
							foreach ( vila_akoya_get_vehicle_types() as $vslug => $vrow ) {
								$vlabels[ $vslug ] = isset( $vrow['label'] ) ? (string) $vrow['label'] : $vslug;
							}
						}
						$vlabels_fallback = array(
							'tent'        => __( 'Tent', 'vila-campsite-booking' ),
							'caravan'     => __( 'Caravan', 'vila-campsite-booking' ),
							'camper'      => __( 'Campervan', 'vila-campsite-booking' ),
							'electricity' => __( 'Include electricity', 'vila-campsite-booking' ),
							'car'         => __( 'Car', 'vila-campsite-booking' ),
						);
						foreach ( Pricing_Settings::vehicle_fee_slugs() as $vslug ) {
							if ( isset( $vlabels[ $vslug ] ) ) {
								$lab = $vlabels[ $vslug ];
							} elseif ( isset( $vlabels_fallback[ $vslug ] ) ) {
								$lab = $vlabels_fallback[ $vslug ];
							} else {
								$lab = $vslug;
							}
							$val = isset( $pricing['vehicle_fees'][ $vslug ] ) ? $pricing['vehicle_fees'][ $vslug ] : 0;
							?>
							<tr>
								<th scope="row"><label for="vila_cb_vf_<?php echo esc_attr( $vslug ); ?>"><?php echo esc_html( $lab ); ?></label></th>
								<td>
									<input name="vila_cb_pricing[vehicle_fees][<?php echo esc_attr( $vslug ); ?>]" id="vila_cb_vf_<?php echo esc_attr( $vslug ); ?>" type="number" min="0" step="0.01" value="<?php echo esc_attr( (string) $val ); ?>" class="small-text" /> <?php esc_html_e( 'EUR / night', 'vila-campsite-booking' ); ?>
								</td>
							</tr>
							<?php
						}
						?>
					</table>
					<h3 class="vila-cb-ui-section-title" style="margin-top:1.5em;font-size:14px;"><?php esc_html_e( 'Meals', 'vila-campsite-booking' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Optional meal add-ons on the booking form. Breakfast is charged for every night of the stay; dinner only for Monday–Friday nights. Babies (under 2) are free.', 'vila-campsite-booking' ); ?></p>
					<table class="form-table vila-cb-ui-form-table" role="presentation">
						<?php
						$meal_labels = array(
							'breakfast' => __( 'Bio breakfast & coffee', 'vila-campsite-booking' ),
							'dinner'    => __( 'Weekday dinner (Mon–Fri)', 'vila-campsite-booking' ),
						);
						$meal_fees = isset( $pricing['meal_fees'] ) && is_array( $pricing['meal_fees'] ) ? $pricing['meal_fees'] : array();
						foreach ( $meal_labels as $mslug => $mlab ) {
							$mrow = isset( $meal_fees[ $mslug ] ) && is_array( $meal_fees[ $mslug ] ) ? $meal_fees[ $mslug ] : array();
							$madult = isset( $mrow['adult'] ) ? $mrow['adult'] : 0;
							$mchild = isset( $mrow['child'] ) ? $mrow['child'] : 0;
							?>
							<tr>
								<th scope="row"><?php echo esc_html( $mlab ); ?></th>
								<td>
									<label for="vila_cb_mf_<?php echo esc_attr( $mslug ); ?>_adult" class="screen-reader-text"><?php echo esc_html( sprintf( __( '%s adult rate', 'vila-campsite-booking' ), $mlab ) ); ?></label>
									<input name="vila_cb_pricing[meal_fees][<?php echo esc_attr( $mslug ); ?>][adult]" id="vila_cb_mf_<?php echo esc_attr( $mslug ); ?>_adult" type="number" min="0" step="0.01" value="<?php echo esc_attr( (string) $madult ); ?>" class="small-text" /> <?php esc_html_e( 'EUR / adult / day', 'vila-campsite-booking' ); ?>
									&nbsp;&nbsp;
									<label for="vila_cb_mf_<?php echo esc_attr( $mslug ); ?>_child" class="screen-reader-text"><?php echo esc_html( sprintf( __( '%s child rate', 'vila-campsite-booking' ), $mlab ) ); ?></label>
									<input name="vila_cb_pricing[meal_fees][<?php echo esc_attr( $mslug ); ?>][child]" id="vila_cb_mf_<?php echo esc_attr( $mslug ); ?>_child" type="number" min="0" step="0.01" value="<?php echo esc_attr( (string) $mchild ); ?>" class="small-text" /> <?php esc_html_e( 'EUR / child / day', 'vila-campsite-booking' ); ?>
								</td>
							</tr>
							<?php
						}
						?>
					</table>
					<h3 class="vila-cb-ui-section-title" style="margin-top:1.5em;font-size:14px;"><?php esc_html_e( 'Childcare', 'vila-campsite-booking' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Paid childcare booking settings. Capacity is counted per child per day; absences free a place without refunding the original order.', 'vila-campsite-booking' ); ?></p>
					<table class="form-table vila-cb-ui-form-table" role="presentation">
						<tr>
							<th scope="row"><label for="vila_cb_childcare_fee"><?php esc_html_e( 'Price', 'vila-campsite-booking' ); ?></label></th>
							<td>
								<input name="vila_cb_childcare[fee_per_child_day]" id="vila_cb_childcare_fee" type="number" min="0" step="0.01" value="<?php echo esc_attr( (string) $childcare['fee_per_child_day'] ); ?>" class="small-text" /> <?php esc_html_e( 'EUR / child / day', 'vila-campsite-booking' ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="vila_cb_childcare_capacity"><?php esc_html_e( 'Daily capacity', 'vila-campsite-booking' ); ?></label></th>
							<td>
								<input name="vila_cb_childcare[capacity]" id="vila_cb_childcare_capacity" type="number" min="1" step="1" value="<?php echo esc_attr( (string) $childcare['capacity'] ); ?>" class="small-text" /> <?php esc_html_e( 'children / day', 'vila-campsite-booking' ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Open days', 'vila-campsite-booking' ); ?></th>
							<td>
								<?php
								$day_labels = array(
									0 => __( 'Sunday', 'vila-campsite-booking' ),
									1 => __( 'Monday', 'vila-campsite-booking' ),
									2 => __( 'Tuesday', 'vila-campsite-booking' ),
									3 => __( 'Wednesday', 'vila-campsite-booking' ),
									4 => __( 'Thursday', 'vila-campsite-booking' ),
									5 => __( 'Friday', 'vila-campsite-booking' ),
									6 => __( 'Saturday', 'vila-campsite-booking' ),
								);
								foreach ( $day_labels as $day_idx => $day_label ) :
									?>
									<label style="display:inline-block;margin-right:12px;margin-bottom:8px;">
										<input type="checkbox" name="vila_cb_childcare[open_weekdays][]" value="<?php echo esc_attr( (string) $day_idx ); ?>" <?php checked( in_array( $day_idx, (array) $childcare['open_weekdays'], true ) ); ?> />
										<?php echo esc_html( $day_label ); ?>
									</label>
								<?php endforeach; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="vila_cb_childcare_cutoff"><?php esc_html_e( 'Next-day cutoff', 'vila-campsite-booking' ); ?></label></th>
							<td>
								<input name="vila_cb_childcare[cutoff_hour]" id="vila_cb_childcare_cutoff" type="number" min="0" max="23" step="1" value="<?php echo esc_attr( (string) $childcare['cutoff_hour'] ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( '24-hour Lisbon time. Today is always blocked; after this hour, tomorrow is blocked too.', 'vila-campsite-booking' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save settings', 'vila-campsite-booking' ), 'primary', '', false, array( 'class' => 'vila-cb-ui-submit' ) ); ?>
				</div>
			</form>
			<div class="vila-cb-ui-panel vila-cb-ui-panel--code">
				<h2 class="vila-cb-ui-section-title"><?php esc_html_e( 'Shortcode', 'vila-campsite-booking' ); ?></h2>
				<p class="vila-cb-ui-code-wrap"><code class="vila-cb-ui-code">[vila_campsite_booking]</code></p>
				<p class="description vila-cb-ui-footnote vila-cb-ui-footnote--flush"><?php esc_html_e( 'Optional: [vila_campsite_booking product_id="123" label="Your label"]. The label overrides the “Where” text (defaults to a generic campsite name, not the WC product title).', 'vila-campsite-booking' ); ?></p>
				<p class="vila-cb-ui-code-wrap" style="margin-top:14px;"><code class="vila-cb-ui-code">[vila_guest_registration]</code></p>
				<p class="description vila-cb-ui-footnote vila-cb-ui-footnote--flush"><?php esc_html_e( 'Add this shortcode to a guest-facing page where people can search their booking or enter stay details manually for SIBA registration.', 'vila-campsite-booking' ); ?></p>
				<p class="vila-cb-ui-code-wrap" style="margin-top:14px;"><code class="vila-cb-ui-code">[vila_meal_booking]</code></p>
				<p class="description vila-cb-ui-footnote vila-cb-ui-footnote--flush"><?php esc_html_e( 'Guest page to book and pay for breakfast/dinner — link to an existing stay or book meals as a walk-in.', 'vila-campsite-booking' ); ?></p>
				<p class="vila-cb-ui-code-wrap" style="margin-top:14px;"><code class="vila-cb-ui-code">[vila_childcare_booking]</code></p>
				<p class="description vila-cb-ui-footnote vila-cb-ui-footnote--flush"><?php esc_html_e( 'Guest page to book and pay for childcare by the day.', 'vila-campsite-booking' ); ?></p>
				<p class="description vila-cb-ui-footnote">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: plugin version */
							__( 'Plugin build: %s', 'vila-campsite-booking' ),
							defined( 'VILA_CB_VERSION' ) ? (string) VILA_CB_VERSION : '?'
						)
					);
					echo ' · ';
					echo Install::meals_module_available()
						? esc_html__( 'Meals module: loaded', 'vila-campsite-booking' )
						: esc_html__( 'Meals module: missing — re-upload the full plugin folder', 'vila-campsite-booking' );
					?>
				</p>
				<?php
				Install::ensure_public_pages_now();
				$guest_page = (int) get_option( Install::OPTION_GUEST_REGISTRATION_PAGE_ID, 0 );
				$meal_page  = Install::get_meal_booking_page_id();
				$childcare_page = Install::get_childcare_booking_page_id();
				if ( ! $meal_page ) {
					$meal_page = (int) Install::ensure_meal_booking_page();
				}
				if ( ! $childcare_page ) {
					$childcare_page = (int) Install::ensure_childcare_booking_page();
				}
				if ( $guest_page ) :
					?>
					<p class="description vila-cb-ui-footnote">
						<a href="<?php echo esc_url( get_permalink( $guest_page ) ); ?>" target="_blank" rel="noreferrer"><?php esc_html_e( 'Open guest registration page', 'vila-campsite-booking' ); ?></a>
					</p>
				<?php endif; ?>
				<?php if ( $meal_page ) : ?>
					<p class="description vila-cb-ui-footnote">
						<a href="<?php echo esc_url( get_permalink( $meal_page ) ); ?>" target="_blank" rel="noreferrer"><?php esc_html_e( 'Open meal booking page', 'vila-campsite-booking' ); ?></a>
					</p>
				<?php else : ?>
					<p class="description vila-cb-ui-footnote" style="color:#b00020;">
						<?php esc_html_e( 'Book meals page not found.', 'vila-campsite-booking' ); ?>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?vila_cb_create_public_pages=1' ), 'vila_cb_create_public_pages' ) ); ?>"><?php esc_html_e( 'Create it now', 'vila-campsite-booking' ); ?></a>
					</p>
				<?php endif; ?>
				<?php if ( $childcare_page ) : ?>
					<p class="description vila-cb-ui-footnote">
						<a href="<?php echo esc_url( get_permalink( $childcare_page ) ); ?>" target="_blank" rel="noreferrer"><?php esc_html_e( 'Open childcare booking page', 'vila-campsite-booking' ); ?></a>
					</p>
				<?php else : ?>
					<p class="description vila-cb-ui-footnote" style="color:#b00020;">
						<?php esc_html_e( 'Book childcare page not found.', 'vila-campsite-booking' ); ?>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?vila_cb_create_public_pages=1' ), 'vila_cb_create_public_pages' ) ); ?>"><?php esc_html_e( 'Create it now', 'vila-campsite-booking' ); ?></a>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
