<?php
/**
 * One-shot: activate theme, seed all pages (block patterns), permalinks, homepage,
 * import design images into Media Library, ensure CF7 booking form.
 *
 * Content matches the theme patterns + design URLs in inc/design-assets.php.
 *
 * Usage (from project root, with wp-env):
 *   wp-env run cli wp eval-file wp-content/themes/vila-akoya/scripts/seed-vila-akoya.php
 *
 * With global WP-CLI pointed at this WordPress install:
 *   wp eval-file wp-content/themes/vila-akoya/scripts/seed-vila-akoya.php
 *
 * @package vila-akoya
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This script must be run with WP-CLI (wp eval-file).\n" );
	exit( 1 );
}

$theme_slug = 'vila-akoya';
$theme      = wp_get_theme( $theme_slug );

if ( ! $theme->exists() ) {
	fwrite( STDERR, "Theme \"{$theme_slug}\" is not installed. Check wp-content/themes/{$theme_slug}.\n" );
	exit( 1 );
}

switch_theme( $theme_slug );

if ( ! function_exists( 'vila_akoya_maybe_seed_pages' ) ) {
	fwrite( STDERR, "Theme functions not loaded. Is functions.php valid?\n" );
	exit( 1 );
}

// Force a full page + pattern content pass (same as bumping seed version).
delete_option( 'vila_akoya_seed_version' );
delete_option( 'vila_akoya_urls_configured' );

vila_akoya_maybe_seed_pages();

// Pretty permalinks + static front page flags (runs same logic as front-end init).
delete_option( 'vila_akoya_urls_configured' );
if ( function_exists( 'vila_akoya_configure_permalinks_and_home' ) ) {
	vila_akoya_configure_permalinks_and_home();
}
if ( function_exists( 'vila_akoya_ensure_static_homepage' ) ) {
	vila_akoya_ensure_static_homepage();
}

// Re-import all design images (see vila_akoya_design_images() in inc/design-assets.php).
delete_option( 'vila_akoya_media_import_version' );
delete_option( 'vila_akoya_media_import_offset' );
delete_option( 'vila_akoya_media_url_map' );

$max_batches = 60;
for ( $i = 0; $i < $max_batches; $i++ ) {
	if ( (int) get_option( 'vila_akoya_media_import_version', 0 ) >= 1 ) {
		break;
	}
	if ( function_exists( 'vila_akoya_maybe_import_media' ) ) {
		vila_akoya_maybe_import_media();
	}
}

if ( (int) get_option( 'vila_akoya_media_import_version', 0 ) < 1 ) {
	fwrite( STDERR, "Warning: media import did not finish after {$max_batches} batches. Check connectivity or debug.log.\n" );
}

if ( function_exists( 'vila_akoya_maybe_create_booking_cf7' ) ) {
	vila_akoya_maybe_create_booking_cf7();
}

flush_rewrite_rules( false );

if ( class_exists( 'WP_CLI', false ) ) {
	WP_CLI::success(
		'Vila Akoya seeded: pages + patterns, Reading/permalink settings, media import, booking form (if CF7 is active).'
	);
} else {
	echo "Vila Akoya seed completed.\n";
}
