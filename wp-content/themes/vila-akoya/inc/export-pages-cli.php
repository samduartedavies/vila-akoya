<?php
/**
 * Export brochure page content to JSON (run on live or local).
 *
 * Usage (live SSH):
 *   wp eval-file wp-content/themes/vila-akoya/inc/export-pages-cli.php -- scripts/exports/vila-pages-export.json
 *
 * Or from repo root via wp-env after syncing theme:
 *   npm run wp:export-pages
 *
 * @package Vila_Akoya
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$theme_dir = dirname( __DIR__ );
if ( ! defined( 'VILA_AKOYA_DIR' ) ) {
	define( 'VILA_AKOYA_DIR', $theme_dir );
}

require_once VILA_AKOYA_DIR . '/inc/page-sync.php';

$default_path = VILA_AKOYA_DIR . '/exports/vila-pages-export.json';
$path         = isset( $args[0] ) && is_string( $args[0] ) && '' !== $args[0] ? $args[0] : $default_path;

$path = vila_akoya_resolve_path( $path );

$written = vila_akoya_write_pages_export( $path );
if ( '' === $written ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::error( 'Failed to write export file.' );
	}
	exit( 1 );
}

$count = count( vila_akoya_export_seed_pages() );
$msg   = sprintf( 'Exported %d brochure page(s) to %s', $count, $written );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::success( $msg );
} else {
	echo $msg . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
