<?php
/**
 * Vila Akoya — block theme bootstrap.
 *
 * @package vila-akoya
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VILA_AKOYA_VERSION', '1.2.9' );

require_once get_template_directory() . '/inc/design-assets.php';
require_once get_template_directory() . '/inc/media-import.php';
require_once get_template_directory() . '/inc/page-setup.php';
require_once get_template_directory() . '/inc/page-urls.php';
require_once get_template_directory() . '/inc/cf7-booking-form.php';

/**
 * Theme setup.
 */
function vila_akoya_setup() {
	add_editor_style( 'assets/css/style.css' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'custom-line-height' );
	add_theme_support( 'custom-spacing' );
	add_theme_support( 'custom-units', array( 'px', 'em', 'rem', 'vh', 'vw', '%' ) );
}
add_action( 'after_setup_theme', 'vila_akoya_setup' );

/**
 * Front-end assets.
 */
function vila_akoya_enqueue() {
	wp_enqueue_style(
		'vila-akoya-fonts',
		'https://fonts.googleapis.com/css2?family=Barrio&family=Newsreader:ital,opsz,wght@0,6..72,200..800;1,6..72,200..800&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap',
		array(),
		null
	);
	wp_enqueue_style(
		'vila-akoya',
		get_stylesheet_uri(),
		array( 'vila-akoya-fonts' ),
		VILA_AKOYA_VERSION
	);
	wp_enqueue_style(
		'vila-akoya-theme',
		get_template_directory_uri() . '/assets/css/style.css',
		array( 'vila-akoya' ),
		VILA_AKOYA_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'vila_akoya_enqueue' );

/**
 * Editor fonts + shared CSS.
 */
function vila_akoya_enqueue_block_editor_assets() {
	wp_enqueue_style(
		'vila-akoya-fonts',
		'https://fonts.googleapis.com/css2?family=Barrio&family=Newsreader:ital,opsz,wght@0,6..72,200..800;1,6..72,200..800&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap',
		array(),
		null
	);
	wp_enqueue_style(
		'vila-akoya-editor',
		get_template_directory_uri() . '/assets/css/style.css',
		array( 'wp-edit-blocks' ),
		VILA_AKOYA_VERSION
	);
}
add_action( 'enqueue_block_editor_assets', 'vila_akoya_enqueue_block_editor_assets' );

/**
 * Pattern category for inserter.
 */
function vila_akoya_register_pattern_category() {
	register_block_pattern_category(
		'vila-akoya',
		array(
			'label' => __( 'Vila Akoya', 'vila-akoya' ),
		)
	);
}
add_action( 'init', 'vila_akoya_register_pattern_category' );
