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

	/**
	 * Plugin activation.
	 */
	public static function activate() {
		self::create_tables();
		self::seed_plots();
		update_option( self::OPTION_DB_VERSION, '1.0.0' );
	}

	/**
	 * Run on plugins_loaded if version changed.
	 */
	public static function maybe_upgrade() {
		$ver = get_option( self::OPTION_DB_VERSION, '' );
		if ( version_compare( (string) $ver, '1.0.0', '<' ) ) {
			self::create_tables();
			self::seed_plots();
			update_option( self::OPTION_DB_VERSION, '1.0.0' );
		}
	}

	/**
	 * Reconcile plot rows after settings change (e.g. plot count).
	 */
	public static function sync_plots() {
		self::seed_plots();
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
