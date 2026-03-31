<?php
/**
 * Vila Akoya — block theme bootstrap.
 *
 * @package vila-akoya
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VILA_AKOYA_VERSION', '1.2.29' );

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
		'https://fonts.googleapis.com/css2?family=Barrio&family=Marcellus&family=Josefin+Sans:wght@300;400;500;600&family=Newsreader:ital,opsz,wght@0,6..72,200..800;1,6..72,200..800&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap',
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
		'https://fonts.googleapis.com/css2?family=Barrio&family=Marcellus&family=Josefin+Sans:wght@300;400;500;600&family=Newsreader:ital,opsz,wght@0,6..72,200..800;1,6..72,200..800&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap',
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

/**
 * Render design HTML main/body assets as WordPress content.
 *
 * This keeps the site fully WordPress-rendered while allowing near-exact
 * visual parity with the provided design HTML files.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function vila_akoya_design_html_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'slug' => '',
		),
		$atts,
		'vila_akoya_design'
	);

	$map = array(
		'home'         => 'home.html',
		'village'      => 'village.html',
		'campsite'     => 'campsite.html',
		'booking'      => 'booking.html',
		'forest'       => 'forest_school.html',
		'contact'      => 'contact.html',
		'about'        => 'about.html',
		'campus'       => 'campus.html',
	);

	$slug = sanitize_key( (string) $atts['slug'] );
	if ( empty( $map[ $slug ] ) ) {
		return '';
	}

	$file = trailingslashit( get_template_directory() ) . 'design-html/' . $map[ $slug ];
	if ( ! file_exists( $file ) ) {
		return '';
	}

	$html = file_get_contents( $file ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
	if ( false === $html || '' === $html ) {
		return '';
	}

	$assets = '';
	if ( preg_match( '/<head[^>]*>(.*?)<\/head>/is', $html, $head_match ) ) {
		$head_inner = $head_match[1];
		$kept       = array();
		if ( preg_match_all( '/<link[^>]+googleapis[^>]*>/i', $head_inner, $m1 ) ) {
			$kept = array_merge( $kept, $m1[0] );
		}
		if ( preg_match_all( '/<script[^>]+tailwindcss[^>]*>.*?<\/script>/is', $head_inner, $m2 ) ) {
			$kept = array_merge( $kept, $m2[0] );
		}
		if ( preg_match_all( '/<script[^>]*id="tailwind-config"[^>]*>.*?<\/script>/is', $head_inner, $m3 ) ) {
			$kept = array_merge( $kept, $m3[0] );
		}
		if ( preg_match_all( '/<style[^>]*>.*?<\/style>/is', $head_inner, $m4 ) ) {
			$kept = array_merge( $kept, $m4[0] );
		}
		$assets = implode( "\n", array_unique( $kept ) );
	}

	$main_inner = '';
	if ( preg_match( '/<main[^>]*>(.*?)<\/main>/is', $html, $main_match ) ) {
		$main_inner = $main_match[1];
	}
	if ( '' === $main_inner && preg_match( '/<body[^>]*>(.*?)<\/body>/is', $html, $body_match ) ) {
		$main_inner = $body_match[1];
		$main_inner = preg_replace( '/<nav[\s\S]*?<\/nav>/is', '', $main_inner, 1 );
		$main_inner = preg_replace( '/<footer[\s\S]*?<\/footer>/is', '', $main_inner, 1 );
	}

	if ( '' === $main_inner ) {
		return '';
	}

	return '<div class="va-design-html va-design-html--' . esc_attr( $slug ) . '">' . $assets . $main_inner . '</div>';
}
add_shortcode( 'vila_akoya_design', 'vila_akoya_design_html_shortcode' );
