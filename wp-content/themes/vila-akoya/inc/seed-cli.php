<?php
/**
 * Brochure page import for WP-CLI (works when the theme is not active).
 *
 * Usage: run `npm run wp:seed` (syncs theme into Docker, then imports). Or after `npm run wp:sync-theme`:
 *   cd /var/www/html && wp eval-file /var/www/html/wp-content/themes/vila-akoya/inc/seed-cli.php
 *
 * @package Vila_Akoya
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$theme_dir = dirname( __DIR__ );
if ( ! is_readable( $theme_dir . '/style.css' ) ) {
	$msg = "Vila Akoya theme files are missing at {$theme_dir}. Ensure wp-env lists ./wp-content/themes/vila-akoya in \"themes\" and restart, or copy the theme into the container.\n";
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::error( $msg );
	} else {
		echo $msg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit( 1 );
	}
}

if ( ! defined( 'VILA_AKOYA_DIR' ) ) {
	define( 'VILA_AKOYA_DIR', $theme_dir );
}
if ( ! defined( 'VILA_AKOYA_URI' ) ) {
	define( 'VILA_AKOYA_URI', content_url( 'themes/vila-akoya' ) );
}

require_once VILA_AKOYA_DIR . '/inc/cf7-setup.php';
require_once VILA_AKOYA_DIR . '/inc/woocommerce-campsite.php';
require_once VILA_AKOYA_DIR . '/inc/seed.php';

vila_akoya_run_seed();
if ( function_exists( 'vila_akoya_cf7_upgrade_mail_templates' ) ) {
	vila_akoya_cf7_upgrade_mail_templates();
}
if ( function_exists( 'vila_akoya_cf7_delete_unused_forms' ) ) {
	$removed = vila_akoya_cf7_delete_unused_forms();
	update_option( 'vila_akoya_cf7_unused_cleaned_ver', VILA_AKOYA_CF7_SCHEMA_VERSION );
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::log( sprintf( 'Removed %d unused CF7 form(s).', (int) $removed ) );
	}
}
vila_akoya_flush_rewrite_rules_hard();
update_option( 'vila_akoya_content_seeded', '1' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::success( 'Brochure pages imported from theme inc/page-content/ (CF7 shortcodes applied).' );
}
