<?php
/**
 * Import brochure pages from a JSON export (local dev / never destructive on live if used carefully).
 *
 * Usage:
 *   npm run wp:pull-pages
 *   npm run wp:pull-pages:repo
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
$path         = $default_path;
$to_repo      = false;
$no_migrate   = false;

if ( isset( $args ) && is_array( $args ) ) {
	foreach ( $args as $arg ) {
		if ( '--repo' === $arg ) {
			$to_repo = true;
		} elseif ( '--no-migrate-images' === $arg ) {
			$no_migrate = true;
		} elseif ( is_string( $arg ) && '' !== $arg && '--' !== $arg[0] ) {
			$path = $arg;
		}
	}
}

$path = vila_akoya_resolve_path( $path );

$export = vila_akoya_read_pages_export( $path );
if ( null === $export ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::error( 'Export file not found or invalid: ' . $path );
	}
	exit( 1 );
}

$migrate = ! $no_migrate;

if ( $to_repo ) {
	$stats = vila_akoya_import_pages_to_repo_files( $export, $migrate );
	$msg   = sprintf(
		'Wrote %d page-content file(s) to inc/page-content/ (%d skipped).',
		$stats['written'],
		$stats['skipped']
	);
} else {
	$stats = vila_akoya_import_pages_to_database( $export, $migrate );
	$msg   = sprintf(
		'Imported %d page(s) into the database (%d image migrations, %d skipped).',
		$stats['updated'],
		$stats['migrated'],
		$stats['skipped']
	);
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::success( $msg );
} else {
	echo $msg . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
