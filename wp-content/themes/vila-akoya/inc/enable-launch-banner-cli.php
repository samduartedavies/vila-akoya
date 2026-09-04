<?php
/**
 * Prepend the launch-festival announcement banner to the Home page (idempotent).
 *
 * Usage (from WordPress root):
 *   wp eval-file wp-content/themes/vila-akoya/inc/enable-launch-banner-cli.php
 *
 * @package Vila_Akoya
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'VILA_AKOYA_DIR' ) ) {
	define( 'VILA_AKOYA_DIR', dirname( __DIR__ ) );
}

require_once VILA_AKOYA_DIR . '/inc/sections.php';
require_once VILA_AKOYA_DIR . '/inc/blocks.php';
require_once VILA_AKOYA_DIR . '/inc/site-deploy.php';

$result = vila_akoya_deploy_enable_launch_banner();
if ( is_wp_error( $result ) ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::error( $result->get_error_message() );
	}
	echo esc_html( $result->get_error_message() ) . PHP_EOL;
	exit( 1 );
}

$msg = __( 'Launch banner is on the Home page (or was already in place). Purge cache if needed.', 'vila-akoya' );
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::success( $msg );
}
echo esc_html( $msg ) . PHP_EOL;
