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

	/**
	 * Plugin activation.
	 */
	public static function activate() {
		self::create_tables();
		self::seed_plots();
		self::ensure_guest_registration_page();
		update_option( self::OPTION_DB_VERSION, '1.1.1' );
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
		$regs    = Database::guest_registrations_table();
		$persons = Database::guest_persons_table();

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
			source VARCHAR(30) NOT NULL DEFAULT 'manual',
			siba_status VARCHAR(20) NOT NULL DEFAULT 'pending',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id),
			KEY order_id (order_id),
			KEY stay_range (check_in, check_out),
			KEY siba_status (siba_status)
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
			skip_siba TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY registration_id (registration_id),
			KEY skip_siba (skip_siba)
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
