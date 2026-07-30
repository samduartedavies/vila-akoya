<?php
/**
 * Plugin Name: Vila Campsite Booking
 * Description: Multi-plot campsite inventory with WooCommerce checkout, Airbnb-style booking UI, and operations dashboard.
 * Version: 1.3.3
 * Author: Vila Akoya
 * Text Domain: vila-campsite-booking
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 *
 * @package Vila_Campsite_Booking
 */

defined( 'ABSPATH' ) || exit;

define( 'VILA_CB_VERSION', '1.3.3' );
define( 'VILA_CB_FILE', __FILE__ );
define( 'VILA_CB_DIR', plugin_dir_path( __FILE__ ) );
define( 'VILA_CB_URL', plugin_dir_url( __FILE__ ) );

require_once VILA_CB_DIR . 'includes/class-deprecation-trace.php';
\Vila_CB\Deprecation_Trace::init();

/**
 * Declare HPOS compatibility (use WC order APIs only; no direct postmeta for orders).
 */
function vila_cb_declare_hpos() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', VILA_CB_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'vila_cb_declare_hpos' );

require_once VILA_CB_DIR . 'includes/class-install.php';
require_once VILA_CB_DIR . 'includes/class-database.php';
require_once VILA_CB_DIR . 'includes/class-request-string.php';
require_once VILA_CB_DIR . 'includes/class-dates.php';
require_once VILA_CB_DIR . 'includes/class-plot-repository.php';
require_once VILA_CB_DIR . 'includes/class-plot-block-repository.php';
require_once VILA_CB_DIR . 'includes/class-booking-repository.php';
require_once VILA_CB_DIR . 'includes/class-guest-registration-repository.php';
require_once VILA_CB_DIR . 'includes/class-guest-registration-exporter.php';
require_once VILA_CB_DIR . 'includes/class-booking-service.php';
require_once VILA_CB_DIR . 'includes/class-meal-plan-service.php';
require_once VILA_CB_DIR . 'includes/class-meal-booking.php';
require_once VILA_CB_DIR . 'includes/class-woocommerce-integration.php';
require_once VILA_CB_DIR . 'includes/class-rest-controller.php';
require_once VILA_CB_DIR . 'includes/class-admin.php';
require_once VILA_CB_DIR . 'includes/class-admin-guest-registrations.php';
require_once VILA_CB_DIR . 'includes/class-pricing-settings.php';
require_once VILA_CB_DIR . 'includes/class-shortcode.php';

add_action( 'init', array( 'Vila_CB\\Install', 'ensure_public_pages_now' ), 21 );
add_action( 'plugins_loaded', array( 'Vila_CB\\Install', 'init' ), 1 );

/**
 * Warn if WooCommerce is missing (plugin still shows under Plugins).
 */
function vila_cb_wc_missing_notice() {
	if ( class_exists( 'WooCommerce' ) ) {
		return;
	}
	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Vila Campsite Booking requires WooCommerce to be installed and active.', 'vila-campsite-booking' );
			echo '</p></div>';
		}
	);
}
add_action( 'plugins_loaded', 'vila_cb_wc_missing_notice', 1 );

/**
 * Bootstrap after WooCommerce core is ready (menus, shortcode, REST).
 * Using plugins_loaded alone can run before WC; woocommerce_loaded is the supported hook.
 */
function vila_cb_bootstrap() {
	Vila_CB\Install::maybe_upgrade();
	Vila_CB\Pricing_Settings::migrate_legacy_pricing_option();
	Vila_CB\Pricing_Settings::migrate_pricing_rates_2026();
	Vila_CB\Pricing_Settings::migrate_vehicle_fees_tent_caravan();
	Vila_CB\Pricing_Settings::init();
	Vila_CB\Meal_Booking::init();
	Vila_CB\WooCommerce_Integration::init();
	Vila_CB\Rest_Controller::init();
	Vila_CB\Admin::init();
	Vila_CB\Admin_Guest_Registrations::init();
	Vila_CB\Shortcode::init();
}
add_action( 'woocommerce_loaded', 'vila_cb_bootstrap' );

/**
 * Plugins list row: quick link to Campsite settings (WooCommerce → Campsite).
 *
 * @param string[] $links Links.
 * @return string[]
 */
function vila_cb_plugin_action_links( $links ) {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return $links;
	}
	array_unshift(
		$links,
		'<a href="' . esc_url( admin_url( 'admin.php?page=' . \Vila_CB\Admin::PAGE_SETTINGS ) ) . '">' . esc_html__( 'Campsite settings', 'vila-campsite-booking' ) . '</a>'
	);
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'vila_cb_plugin_action_links' );

register_activation_hook( __FILE__, array( 'Vila_CB\\Install', 'activate' ) );
