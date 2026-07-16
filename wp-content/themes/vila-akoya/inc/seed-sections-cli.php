<?php
/**
 * Seed brochure pages with visual Vila Section blocks.
 *
 * Usage:
 *   wp eval-file wp-content/themes/vila-akoya/inc/seed-sections-cli.php
 *   wp eval-file wp-content/themes/vila-akoya/inc/seed-sections-cli.php about
 *   wp eval-file wp-content/themes/vila-akoya/inc/seed-sections-cli.php all
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
if ( is_readable( VILA_AKOYA_DIR . '/inc/cf7-setup.php' ) ) {
	require_once VILA_AKOYA_DIR . '/inc/cf7-setup.php';
}
if ( is_readable( VILA_AKOYA_DIR . '/inc/seed.php' ) ) {
	require_once VILA_AKOYA_DIR . '/inc/seed.php';
}

$arg = '';
if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
	// Positional args after eval-file path are not always available; allow ENV.
	$arg = getenv( 'VILA_SEED_SLUG' ) ? (string) getenv( 'VILA_SEED_SLUG' ) : '';
}
if ( '' === $arg && isset( $args[0] ) ) {
	$arg = (string) $args[0];
}
if ( '' === $arg && isset( $argv ) && is_array( $argv ) ) {
	foreach ( $argv as $i => $token ) {
		if ( str_contains( (string) $token, 'seed-sections-cli.php' ) && isset( $argv[ $i + 1 ] ) ) {
			$arg = (string) $argv[ $i + 1 ];
			break;
		}
	}
}
if ( '' === $arg ) {
	$arg = 'all';
}

$arg = sanitize_title( $arg );

if ( 'all' === $arg ) {
	$result = vila_akoya_seed_all_page_section_blocks();
	foreach ( $result['ok'] as $slug => $id ) {
		$msg = sprintf( 'Seeded %s (page ID %d) with Vila Section blocks.', $slug, $id );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::success( $msg );
		} else {
			echo esc_html( $msg ) . PHP_EOL;
		}
	}
	foreach ( $result['errors'] as $slug => $error ) {
		$msg = sprintf( '%s: %s', $slug, $error );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::warning( $msg );
		} else {
			echo esc_html( $msg ) . PHP_EOL;
		}
	}
	if ( empty( $result['ok'] ) ) {
		exit( 1 );
	}
	exit( 0 );
}

$seeded = vila_akoya_seed_page_section_blocks( $arg );
if ( is_wp_error( $seeded ) ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::error( $seeded->get_error_message() );
	}
	echo esc_html( $seeded->get_error_message() ) . PHP_EOL;
	exit( 1 );
}

$message = sprintf( 'Seeded %s with Vila Section blocks (page ID %d).', $arg, (int) $seeded );
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::success( $message );
} else {
	echo esc_html( $message ) . PHP_EOL;
}
