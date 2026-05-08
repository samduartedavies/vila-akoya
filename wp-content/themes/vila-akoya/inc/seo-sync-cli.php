<?php
/**
 * Push brochure SEO strings into TSF post meta for all seeded pages (WP-CLI).
 *
 * Usage (after theme is in the container):
 *   wp eval-file wp-content/themes/vila-akoya/inc/seo-sync-cli.php
 * Or: npm run wp:sync-seo
 *
 * @package Vila_Akoya
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$theme_dir = dirname( __DIR__ );
if ( ! is_readable( $theme_dir . '/style.css' ) ) {
	$msg = "Vila Akoya theme files are missing at {$theme_dir}.\n";
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

require_once VILA_AKOYA_DIR . '/inc/seo-brochure-meta.php';

vila_akoya_sync_brochure_seo_meta_for_all_pages();
update_option( 'vila_akoya_seo_brochure_meta_ver', VILA_AKOYA_SEO_BROCHURE_META_VER );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::success( 'Brochure SEO meta applied to pages (The SEO Framework fields).' );
}
