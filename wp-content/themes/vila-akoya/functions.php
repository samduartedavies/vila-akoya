<?php
/**
 * Vila Akoya block theme.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

define( 'VILA_AKOYA_VERSION', '1.0.0' );
define( 'VILA_AKOYA_DIR', get_template_directory() );
define( 'VILA_AKOYA_URI', get_template_directory_uri() );

require_once VILA_AKOYA_DIR . '/inc/redirects.php';
require_once VILA_AKOYA_DIR . '/inc/cf7-setup.php';
require_once VILA_AKOYA_DIR . '/inc/seed.php';

/**
 * Theme setup.
 */
function vila_akoya_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'editor-styles' );
	add_editor_style( 'assets/css/theme.css' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'appearance-tools' );
	add_theme_support( 'custom-spacing' );
	add_theme_support( 'custom-line-height' );
}
add_action( 'after_setup_theme', 'vila_akoya_setup' );

/**
 * Enqueue front-end assets.
 */
function vila_akoya_enqueue_assets() {
	wp_enqueue_style(
		'vila-akoya-fonts',
		'https://fonts.googleapis.com/css2?family=Barrio&family=Marcellus&family=Josefin+Sans:ital,wght@0,100..700;1,100..700&display=swap',
		array(),
		null
	);
	wp_enqueue_style(
		'vila-akoya-material-symbols',
		'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&display=swap',
		array(),
		null
	);
	wp_enqueue_style(
		'vila-akoya-theme',
		VILA_AKOYA_URI . '/assets/css/theme.css',
		array(),
		VILA_AKOYA_VERSION
	);
	wp_enqueue_script(
		'vila-akoya-site-nav',
		VILA_AKOYA_URI . '/assets/js/site-nav.js',
		array(),
		VILA_AKOYA_VERSION,
		true
	);
	if ( function_exists( 'wpcf7_contact_form' ) ) {
		wp_enqueue_script(
			'vila-akoya-cf7-redirect',
			VILA_AKOYA_URI . '/assets/js/cf7-redirect.js',
			array(),
			VILA_AKOYA_VERSION,
			true
		);
		$thank_you = home_url( '/thank-you/' );
		wp_add_inline_script(
			'vila-akoya-cf7-redirect',
			'window.vilaAkoyaCf7 = ' . wp_json_encode( array( 'thankYouUrl' => $thank_you ) ) . ';',
			'before'
		);
	}
}
add_action( 'wp_enqueue_scripts', 'vila_akoya_enqueue_assets' );

/**
 * Block editor: load theme CSS in editor iframe for visual parity.
 */
function vila_akoya_enqueue_block_editor_assets() {
	wp_enqueue_style(
		'vila-akoya-fonts-editor',
		'https://fonts.googleapis.com/css2?family=Barrio&family=Marcellus&family=Josefin+Sans:ital,wght@0,100..700;1,100..700&display=swap',
		array(),
		null
	);
	wp_enqueue_style(
		'vila-akoya-material-editor',
		'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&display=swap',
		array(),
		null
	);
	wp_enqueue_style(
		'vila-akoya-theme-editor',
		VILA_AKOYA_URI . '/assets/css/theme.css',
		array(),
		VILA_AKOYA_VERSION
	);
}
add_action( 'enqueue_block_editor_assets', 'vila_akoya_enqueue_block_editor_assets' );
