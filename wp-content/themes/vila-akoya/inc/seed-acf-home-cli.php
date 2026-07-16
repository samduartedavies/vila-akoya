<?php
/**
 * Seed the Home page with visual Vila Sections (SCF blocks).
 *
 * Requires the Secure Custom Fields plugin (or ACF/ACF Pro) to be active.
 * Images are sideloaded/resolved to media library attachments so the editor
 * can show and edit them.
 *
 * Usage:
 *   wp eval-file wp-content/themes/vila-akoya/inc/seed-acf-home-cli.php
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
require_once VILA_AKOYA_DIR . '/inc/page-section-data.php';

$result = vila_akoya_seed_home_sections();
if ( is_wp_error( $result ) ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::error( $result->get_error_message() );
	}
	echo esc_html( $result->get_error_message() ) . PHP_EOL;
	exit( 1 );
}

$message = sprintf( 'Seeded Home page with visual Vila Section blocks (page ID %d).', (int) $result );
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::success( $message );
} else {
	echo esc_html( $message ) . PHP_EOL;
}
