<?php
/**
 * Activation / schema.
 *
 * @package Vila_Campsite_Booking
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * DB install and versioned upgrades.
 */
class Install {

	const OPTION_DB_VERSION = 'vila_cb_db_version';
	const OPTION_GUEST_REGISTRATION_PAGE_ID = 'vila_cb_guest_registration_page_id';
	const OPTION_MEAL_BOOKING_PAGE_ID = 'vila_cb_meal_booking_page_id';
	const OPTION_CHILDCARE_BOOKING_PAGE_ID = 'vila_cb_childcare_booking_page_id';

	/**
	 * Plugin activation.
	 */
	public static function activate() {
		self::create_tables();
		self::seed_plots();
		self::ensure_guest_registration_page();
		self::ensure_meal_booking_page();
		self::ensure_childcare_booking_page();
		update_option( self::OPTION_DB_VERSION, '1.4.0' );
		self::schedule_stay_hold_backfill();
		Guest_Registration_Reminders::activate();
	}

	public static function init() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		add_action( 'admin_init', array( __CLASS__, 'handle_force_public_pages' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_admin_deploy_notice' ) );
	}

	/**
	 * Whether meal booking PHP files exist in this plugin directory.
	 *
	 * @return bool
	 */
	public static function meals_files_present() {
		return is_readable( VILA_CB_DIR . 'includes/class-meal-booking.php' )
			&& is_readable( VILA_CB_DIR . 'includes/class-meal-plan-service.php' )
			&& is_readable( VILA_CB_DIR . 'assets/js/meal-booking.js' );
	}

	/**
	 * Whether meal booking PHP (admin calendar + guest form) is present in this plugin build.
	 *
	 * @return bool
	 */
	public static function meals_module_available() {
		return self::meals_files_present()
			&& class_exists( Meal_Plan_Service::class )
			&& class_exists( Meal_Booking::class )
			&& method_exists( Admin::class, 'render_meals' );
	}

	/**
	 * Warn when an old plugin copy is deployed (meals code missing).
	 */
	public static function maybe_admin_deploy_notice() {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! self::meals_files_present() ) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'Vila Campsite Booking on this server is missing the meals module files. Re-upload the full wp-content/plugins/vila-campsite-booking folder (include class-meal-booking.php and class-meal-plan-service.php), then deactivate and reactivate the plugin.',
				'vila-campsite-booking'
			);
			echo ' <strong>' . esc_html(
				sprintf(
					/* translators: %s: plugin version constant */
					__( 'Installed build: %s', 'vila-campsite-booking' ),
					defined( 'VILA_CB_VERSION' ) ? (string) VILA_CB_VERSION : '?'
				)
			) . '</strong>';
			echo '</p></div>';
		}
	}

	/**
	 * Admin ?vila_cb_create_public_pages=1 — force-create guest / meal pages.
	 */
	public static function handle_force_public_pages() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( empty( $_GET['vila_cb_create_public_pages'] ) ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( Request_String::scalar( $_GET['_wpnonce'] ) ) ), 'vila_cb_create_public_pages' ) ) {
			return;
		}
		self::ensure_meal_booking_page();
		self::ensure_childcare_booking_page();
		self::ensure_guest_registration_page();
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                    => Admin::PAGE_SETTINGS,
					'vila_cb_pages_created'   => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @return int
	 */
	private static function default_page_author_id() {
		$uid = (int) get_current_user_id();
		if ( $uid > 0 ) {
			return $uid;
		}
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => array( 'ID' ),
			)
		);
		if ( ! empty( $admins[0]->ID ) ) {
			return (int) $admins[0]->ID;
		}
		return 1;
	}

	/**
	 * Run on plugins_loaded if version changed.
	 */
	public static function maybe_upgrade() {
		$ver = get_option( self::OPTION_DB_VERSION, '' );
		if ( version_compare( (string) $ver, '1.1.1', '<' ) ) {
			self::create_tables();
			self::seed_plots();
			self::maybe_schedule_guest_registration_page();
			update_option( self::OPTION_DB_VERSION, '1.1.1' );
		}
		if ( version_compare( (string) get_option( self::OPTION_DB_VERSION, '' ), '1.1.2', '<' ) ) {
			self::maybe_schedule_meal_booking_page();
			update_option( self::OPTION_DB_VERSION, '1.1.2' );
		}
		if ( version_compare( (string) get_option( self::OPTION_DB_VERSION, '' ), '1.1.3', '<' ) ) {
			Guest_Registration_Exporter::seed_siba_settings();
			update_option( self::OPTION_DB_VERSION, '1.1.3' );
		}
		if ( version_compare( (string) get_option( self::OPTION_DB_VERSION, '' ), '1.1.4', '<' ) ) {
			Guest_Registration_Exporter::apply_siba_prefill( true );
			update_option( self::OPTION_DB_VERSION, '1.1.4' );
		}
		if ( version_compare( (string) get_option( self::OPTION_DB_VERSION, '' ), '1.1.5', '<' ) ) {
			Guest_Registration_Exporter::seed_siba_settings();
			update_option( self::OPTION_DB_VERSION, '1.1.5' );
		}
		if ( version_compare( (string) get_option( self::OPTION_DB_VERSION, '' ), '1.2.0', '<' ) ) {
			self::create_tables();
			if ( function_exists( 'vila_akoya_invalidate_campsite_plot_availability_caches' ) ) {
				vila_akoya_invalidate_campsite_plot_availability_caches();
			}
			update_option( self::OPTION_DB_VERSION, '1.2.0' );
			self::schedule_stay_hold_backfill();
		}
		if ( version_compare( (string) get_option( self::OPTION_DB_VERSION, '' ), '1.2.1', '<' ) ) {
			if ( class_exists( Stay_Hold_Repository::class ) ) {
				( new Stay_Hold_Repository() )->repair_invalid_hold_dates();
			}
			update_option( self::OPTION_DB_VERSION, '1.2.1' );
		}
		if ( version_compare( (string) get_option( self::OPTION_DB_VERSION, '' ), '1.3.0', '<' ) ) {
			self::create_tables();
			self::maybe_schedule_childcare_booking_page();
			update_option( self::OPTION_DB_VERSION, '1.3.0' );
		}
		if ( version_compare( (string) get_option( self::OPTION_DB_VERSION, '' ), '1.4.0', '<' ) ) {
			self::create_tables();
			update_option( self::OPTION_DB_VERSION, '1.4.0' );
		}
		if ( version_compare( (string) get_option( self::OPTION_DB_VERSION, '' ), '1.4.1', '<' ) ) {
			Guest_Registration_Reminders::activate();
			update_option( self::OPTION_DB_VERSION, '1.4.1' );
		}
		self::schedule_public_pages_check();
	}

	/**
	 * Import existing village-stay WooCommerce orders into occupancy holds after init (CPT registered).
	 */
	public static function backfill_stay_holds() {
		if ( ! class_exists( Stay_Hold_Service::class ) ) {
			return;
		}
		( new Stay_Hold_Service() )->backfill_from_orders();
	}

	/**
	 * @return void
	 */
	private static function schedule_stay_hold_backfill() {
		if ( did_action( 'init' ) ) {
			self::backfill_stay_holds();
			return;
		}
		add_action( 'init', array( __CLASS__, 'backfill_stay_holds' ), 40 );
	}

	/**
	 * Idempotent: create guest-registration / book-meals pages when missing (e.g. file-only deploys).
	 */
	public static function ensure_public_pages() {
		self::schedule_public_pages_check();
	}

	/**
	 * Run page checks on init so wp_insert_post / permalinks are ready.
	 */
	private static function schedule_public_pages_check() {
		static $scheduled = false;
		if ( $scheduled ) {
			return;
		}
		$scheduled = true;
		if ( did_action( 'init' ) ) {
			self::ensure_public_pages_now();
			return;
		}
		add_action( 'init', array( __CLASS__, 'ensure_public_pages_now' ), 21 );
	}

	/**
	 * Create missing shortcode pages and sync stored page IDs.
	 */
	public static function ensure_public_pages_now() {
		if ( ! self::is_public_page_valid( self::OPTION_MEAL_BOOKING_PAGE_ID, 'book-meals', '[vila_meal_booking]' ) ) {
			self::ensure_meal_booking_page();
		}
		if ( ! self::is_public_page_valid( self::OPTION_CHILDCARE_BOOKING_PAGE_ID, 'book-childcare', '[vila_childcare_booking]' ) ) {
			self::ensure_childcare_booking_page();
		}
		if ( ! self::is_public_page_valid( self::OPTION_GUEST_REGISTRATION_PAGE_ID, 'guest-registration', '[vila_guest_registration]' ) ) {
			self::ensure_guest_registration_page();
		}
	}

	/**
	 * @param string $option_key   Option storing page ID.
	 * @param string $slug         Expected page slug.
	 * @param string $shortcode_snippet Shortcode substring that must appear in content.
	 * @return bool
	 */
	private static function is_public_page_valid( $option_key, $slug, $shortcode_snippet ) {
		$id = (int) get_option( $option_key, 0 );
		if ( $id > 0 ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post && 'page' === $post->post_type && 'publish' === $post->post_status ) {
				return false !== strpos( (string) $post->post_content, $shortcode_snippet );
			}
		}
		$existing = get_page_by_path( $slug, OBJECT, 'page' );
		return $existing instanceof \WP_Post && 'publish' === $existing->post_status
			&& false !== strpos( (string) $existing->post_content, $shortcode_snippet );
	}

	/**
	 * Reconcile plot rows after settings change (e.g. plot count).
	 */
	public static function sync_plots() {
		self::seed_plots();
	}

	/**
	 * Ensure page creation runs after WordPress rewrite/page globals are ready.
	 */
	private static function maybe_schedule_guest_registration_page() {
		if ( did_action( 'init' ) ) {
			self::ensure_guest_registration_page();
			return;
		}
		add_action( 'init', array( __CLASS__, 'ensure_guest_registration_page' ), 20 );
	}

	/**
	 * Published page that hosts the legal guest registration shortcode.
	 *
	 * @return int Page ID.
	 */
	public static function ensure_guest_registration_page() {
		$slug    = 'guest-registration';
		$content = "<!-- wp:shortcode -->\n[vila_guest_registration]\n<!-- /wp:shortcode -->";

		$existing = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $existing instanceof \WP_Post ) {
			$body = (string) $existing->post_content;
			if ( false === strpos( $body, '[vila_guest_registration]' ) ) {
				$body = trim( $body );
				$body = '' === $body ? $content : $body . "\n\n" . $content;
				wp_update_post(
					array(
						'ID'           => (int) $existing->ID,
						'post_content' => $body,
						'post_status'  => 'publish',
					)
				);
			}
			update_option( self::OPTION_GUEST_REGISTRATION_PAGE_ID, (int) $existing->ID );
			return (int) $existing->ID;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Guest Registration', 'vila-campsite-booking' ),
				'post_name'    => $slug,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_author'  => self::default_page_author_id(),
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return 0;
		}

		update_option( self::OPTION_GUEST_REGISTRATION_PAGE_ID, (int) $page_id );
		return (int) $page_id;
	}

	/**
	 * Ensure meal booking page creation after init when needed.
	 */
	private static function maybe_schedule_meal_booking_page() {
		if ( did_action( 'init' ) ) {
			self::ensure_meal_booking_page();
			return;
		}
		add_action( 'init', array( __CLASS__, 'ensure_meal_booking_page' ), 21 );
	}

	/**
	 * Ensure childcare booking page creation after init when needed.
	 */
	private static function maybe_schedule_childcare_booking_page() {
		if ( did_action( 'init' ) ) {
			self::ensure_childcare_booking_page();
			return;
		}
		add_action( 'init', array( __CLASS__, 'ensure_childcare_booking_page' ), 22 );
	}

	/**
	 * Published page that hosts the meal booking shortcode.
	 *
	 * @return int Page ID.
	 */
	public static function ensure_meal_booking_page() {
		$slug    = 'book-meals';
		$content = "<!-- wp:shortcode -->\n[vila_meal_booking]\n<!-- /wp:shortcode -->";

		$existing = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $existing instanceof \WP_Post ) {
			$body = (string) $existing->post_content;
			if ( false === strpos( $body, '[vila_meal_booking]' ) ) {
				$body = trim( $body );
				$body = '' === $body ? $content : $body . "\n\n" . $content;
				wp_update_post(
					array(
						'ID'           => (int) $existing->ID,
						'post_content' => $body,
						'post_status'  => 'publish',
					)
				);
			}
			update_option( self::OPTION_MEAL_BOOKING_PAGE_ID, (int) $existing->ID );
			return (int) $existing->ID;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Book meals', 'vila-campsite-booking' ),
				'post_name'    => $slug,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_author'  => self::default_page_author_id(),
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return 0;
		}

		update_option( self::OPTION_MEAL_BOOKING_PAGE_ID, (int) $page_id );
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
		return (int) $page_id;
	}

	/**
	 * Resolve published meal booking page ID (0 if missing).
	 *
	 * @return int
	 */
	public static function get_meal_booking_page_id() {
		if ( self::is_public_page_valid( self::OPTION_MEAL_BOOKING_PAGE_ID, 'book-meals', '[vila_meal_booking]' ) ) {
			$id = (int) get_option( self::OPTION_MEAL_BOOKING_PAGE_ID, 0 );
			if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
				return $id;
			}
			$existing = get_page_by_path( 'book-meals', OBJECT, 'page' );
			return $existing instanceof \WP_Post ? (int) $existing->ID : 0;
		}
		return 0;
	}

	/**
	 * Published page that hosts the childcare booking shortcode.
	 *
	 * @return int Page ID.
	 */
	public static function ensure_childcare_booking_page() {
		$slug    = 'book-childcare';
		$content = "<!-- wp:shortcode -->\n[vila_childcare_booking]\n<!-- /wp:shortcode -->";

		$existing = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $existing instanceof \WP_Post ) {
			$body = (string) $existing->post_content;
			if ( false === strpos( $body, '[vila_childcare_booking]' ) ) {
				$body = trim( $body );
				$body = '' === $body ? $content : $body . "\n\n" . $content;
				wp_update_post(
					array(
						'ID'           => (int) $existing->ID,
						'post_content' => $body,
						'post_status'  => 'publish',
					)
				);
			}
			update_option( self::OPTION_CHILDCARE_BOOKING_PAGE_ID, (int) $existing->ID );
			return (int) $existing->ID;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Book childcare', 'vila-campsite-booking' ),
				'post_name'    => $slug,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_author'  => self::default_page_author_id(),
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return 0;
		}

		update_option( self::OPTION_CHILDCARE_BOOKING_PAGE_ID, (int) $page_id );
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
		return (int) $page_id;
	}

	/**
	 * Resolve published childcare booking page ID.
	 *
	 * @return int
	 */
	public static function get_childcare_booking_page_id() {
		if ( self::is_public_page_valid( self::OPTION_CHILDCARE_BOOKING_PAGE_ID, 'book-childcare', '[vila_childcare_booking]' ) ) {
			$id = (int) get_option( self::OPTION_CHILDCARE_BOOKING_PAGE_ID, 0 );
			if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
				return $id;
			}
			$existing = get_page_by_path( 'book-childcare', OBJECT, 'page' );
			return $existing instanceof \WP_Post ? (int) $existing->ID : 0;
		}
		return 0;
	}

	/**
	 * Create custom tables.
	 */
	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$plots   = Database::plots_table();
		$books   = Database::bookings_table();
		$nights  = Database::booking_nights_table();
		$blocks  = Database::plot_blocks_table();
		$regs       = Database::guest_registrations_table();
		$persons    = Database::guest_persons_table();
		$stay_holds = Database::stay_holds_table();
		$stay_nights = Database::stay_hold_nights_table();
		$childcare = Database::childcare_places_table();

		$sql = "CREATE TABLE $plots (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			label VARCHAR(191) NOT NULL,
			sort_order INT NOT NULL DEFAULT 0,
			maintenance_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY sort_order (sort_order)
		) $charset;\n";

		$sql .= "CREATE TABLE $books (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			plot_id BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NULL,
			line_item_id BIGINT UNSIGNED NULL,
			check_in DATE NOT NULL,
			check_out DATE NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			guest_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY plot_id (plot_id),
			KEY order_id (order_id),
			KEY status_created (status, created_at),
			KEY range_plot (plot_id, check_in, check_out)
		) $charset;\n";

		$sql .= "CREATE TABLE $nights (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			booking_id BIGINT UNSIGNED NOT NULL,
			plot_id BIGINT UNSIGNED NOT NULL,
			night_ymd DATE NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY plot_night (plot_id, night_ymd),
			KEY booking_id (booking_id),
			KEY night_ymd (night_ymd)
		) $charset;\n";

		$sql .= "CREATE TABLE $blocks (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			plot_id BIGINT UNSIGNED NOT NULL,
			start_ymd DATE NOT NULL,
			end_ymd DATE NOT NULL,
			note VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY plot_id (plot_id)
		) $charset;\n";

		$sql .= "CREATE TABLE $regs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			booking_id BIGINT UNSIGNED NULL,
			order_id BIGINT UNSIGNED NULL,
			check_in DATE NOT NULL,
			check_out DATE NOT NULL,
			booker_name VARCHAR(191) NOT NULL DEFAULT '',
			contact_email VARCHAR(191) NOT NULL DEFAULT '',
			contact_phone VARCHAR(80) NOT NULL DEFAULT '',
			stay_type VARCHAR(20) NOT NULL DEFAULT 'guest',
			source VARCHAR(30) NOT NULL DEFAULT 'manual',
			siba_status VARCHAR(20) NOT NULL DEFAULT 'pending',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id),
			KEY order_id (order_id),
			KEY stay_range (check_in, check_out),
			KEY siba_status (siba_status),
			KEY stay_type (stay_type)
		) $charset;\n";

		$sql .= "CREATE TABLE $persons (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			registration_id BIGINT UNSIGNED NOT NULL,
			given_name VARCHAR(80) NOT NULL DEFAULT '',
			surname VARCHAR(80) NOT NULL DEFAULT '',
			nationality CHAR(3) NOT NULL DEFAULT '',
			date_of_birth DATE NULL,
			document_type VARCHAR(3) NOT NULL DEFAULT '',
			document_number VARCHAR(32) NOT NULL DEFAULT '',
			document_country CHAR(3) NOT NULL DEFAULT '',
			country_of_residence CHAR(3) NOT NULL DEFAULT '',
			emergency_contact_name VARCHAR(191) NOT NULL DEFAULT '',
			emergency_contact_phone VARCHAR(80) NOT NULL DEFAULT '',
			emergency_contact_relation VARCHAR(80) NOT NULL DEFAULT '',
			allergies TEXT NULL,
			skip_siba TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY registration_id (registration_id),
			KEY skip_siba (skip_siba)
		) $charset;\n";

		$sql .= "CREATE TABLE $stay_holds (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			stay_id BIGINT UNSIGNED NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			order_id BIGINT UNSIGNED NULL,
			line_item_id BIGINT UNSIGNED NULL,
			kind VARCHAR(20) NOT NULL DEFAULT 'booking',
			source VARCHAR(20) NOT NULL DEFAULT 'admin',
			guest_name VARCHAR(191) NOT NULL DEFAULT '',
			note VARCHAR(255) NULL,
			check_in DATE NOT NULL,
			check_out DATE NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'confirmed',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY stay_id (stay_id),
			KEY order_id (order_id),
			KEY status_range (status, check_in, check_out)
		) $charset;\n";

		$sql .= "CREATE TABLE $stay_nights (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			hold_id BIGINT UNSIGNED NOT NULL,
			stay_id BIGINT UNSIGNED NOT NULL,
			night_ymd DATE NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY stay_night (stay_id, night_ymd),
			KEY hold_id (hold_id),
			KEY night_ymd (night_ymd)
		) $charset;\n";

		$sql .= "CREATE TABLE $childcare (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			line_item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			child_index INT NOT NULL DEFAULT 0,
			ymd DATE NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'booked',
			parent_name VARCHAR(191) NOT NULL DEFAULT '',
			parent_phone VARCHAR(80) NOT NULL DEFAULT '',
			parent_email VARCHAR(191) NOT NULL DEFAULT '',
			telegram_username VARCHAR(80) NOT NULL DEFAULT '',
			child_name VARCHAR(191) NOT NULL DEFAULT '',
			child_age INT NOT NULL DEFAULT 0,
			allergies TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_child_day (order_id, line_item_id, child_index, ymd),
			KEY ymd_status (ymd, status),
			KEY child_name (child_name),
			KEY telegram_username (telegram_username)
		) $charset;\n";

		dbDelta( $sql );
	}

	/**
	 * Ensure plot rows exist for each default plot number (1..34 minus excluded).
	 */
	private static function seed_plots() {
		$numbers = Plot_Repository::default_plot_numbers();
		if ( empty( $numbers ) ) {
			return;
		}
		update_option( 'vila_cb_plot_count', count( $numbers ) );

		$repo  = new Plot_Repository();
		$plots = $repo->get_all();
		$now   = current_time( 'mysql' );

		$by_number = array();
		foreach ( $plots as $p ) {
			$num = Plot_Repository::plot_number_from_row( $p );
			if ( $num > 0 ) {
				$by_number[ $num ] = $p;
			}
		}

		$sort = 0;
		foreach ( $numbers as $num ) {
			++$sort;
			if ( isset( $by_number[ $num ] ) ) {
				continue;
			}
			$repo->insert(
				array(
					/* translators: %d: plot number */
					'label'      => sprintf( __( 'Plot %d', 'vila-campsite-booking' ), $num ),
					'sort_order' => $sort,
					'created_at' => $now,
					'updated_at' => $now,
				)
			);
		}
	}
}
