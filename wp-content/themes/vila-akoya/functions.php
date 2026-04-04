<?php
/**
 * Vila Akoya block theme.
 *
 * @package Vila_Akoya
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VILA_AKOYA_VERSION', '1.0.3' );
define( 'VILA_AKOYA_DIR', get_template_directory() );
define( 'VILA_AKOYA_URI', get_template_directory_uri() );

/**
 * Theme setup.
 */
function vila_akoya_setup(): void {
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );
	add_editor_style( 'assets/css/editor.css' );
}
add_action( 'after_setup_theme', 'vila_akoya_setup' );

/**
 * Block pattern category (patterns can be added under patterns/ later).
 */
function vila_akoya_register_pattern_category(): void {
	register_block_pattern_category(
		'vila-akoya',
		array( 'label' => __( 'Vila Akoya', 'vila-akoya' ) )
	);
}
add_action( 'init', 'vila_akoya_register_pattern_category' );

/**
 * Enqueue front-end assets.
 */
function vila_akoya_enqueue_assets(): void {
	wp_enqueue_style(
		'vila-akoya-fonts',
		'https://fonts.googleapis.com/css2?family=Barrio&family=Marcellus&family=Josefin+Sans:ital,wght@0,100..700;1,100..700&display=swap',
		array(),
		null
	);
	wp_enqueue_style(
		'material-symbols',
		'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&display=swap',
		array(),
		null
	);
	wp_enqueue_style(
		'vila-akoya-app',
		VILA_AKOYA_URI . '/assets/css/app.css',
		array(),
		VILA_AKOYA_VERSION
	);
	wp_enqueue_style(
		'vila-akoya-custom',
		VILA_AKOYA_URI . '/assets/css/custom.css',
		array( 'vila-akoya-app' ),
		VILA_AKOYA_VERSION
	);
	if ( function_exists( 'wpcf7_contact_form' ) ) {
		$cf7_deps = array( 'vila-akoya-custom' );
		if ( wp_style_is( 'contact-form-7', 'registered' ) ) {
			$cf7_deps[] = 'contact-form-7';
		}
		wp_enqueue_style(
			'vila-akoya-cf7',
			VILA_AKOYA_URI . '/assets/css/cf7.css',
			$cf7_deps,
			VILA_AKOYA_VERSION
		);
		wp_enqueue_script(
			'vila-akoya-cf7',
			VILA_AKOYA_URI . '/assets/js/cf7-redirect.js',
			array(),
			VILA_AKOYA_VERSION,
			true
		);
		wp_localize_script(
			'vila-akoya-cf7',
			'vilaAkoyaCf7',
			array(
				'thankYouUrl' => home_url( '/thank-you/' ),
			)
		);
	}
	wp_enqueue_script(
		'vila-akoya-nav',
		VILA_AKOYA_URI . '/assets/js/site-nav.js',
		array(),
		VILA_AKOYA_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'vila_akoya_enqueue_assets' );

/**
 * Replace THEME_URI and CF7 placeholder tokens in post content.
 *
 * @param string $content Post content.
 * @return string
 */
function vila_akoya_filter_post_content( string $content ): string {
	$content = str_replace( 'THEME_URI', VILA_AKOYA_URI, $content );

	$map = array(
		'%%VILA_CF7_HOME_NEWSLETTER%%'   => 'vila_akoya_cf7_home_newsletter',
		'%%VILA_CF7_VILLAGE_WAITLIST%%' => 'vila_akoya_cf7_village_waitlist',
		'%%VILA_CF7_CAMPUS_HOST%%'      => 'vila_akoya_cf7_campus_host',
		'%%VILA_CF7_BOOKING%%'          => 'vila_akoya_cf7_booking',
		'%%VILA_CF7_CONTACT%%'          => 'vila_akoya_cf7_contact',
	);

	foreach ( $map as $token => $option ) {
		$id = absint( get_option( $option, 0 ) );
		if ( $id ) {
			$content = str_replace( $token, '[contact-form-7 id="' . $id . '" html_class="vila-cf7-form"]', $content );
		}
	}

	return $content;
}
add_filter( 'the_content', 'vila_akoya_filter_post_content', 8 );

/**
 * Redirect legacy paths.
 */
function vila_akoya_legacy_redirects(): void {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}
	$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
	$path = is_string( $path ) ? untrailingslashit( $path ) : '';

	if ( '/forest-school' === $path ) {
		wp_safe_redirect( home_url( '/the-kids/' ), 301 );
		exit;
	}
	if ( '/home' === $path ) {
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'vila_akoya_legacy_redirects', 1 );

/**
 * Thank-you page: noindex.
 */
function vila_akoya_thankyou_noindex(): void {
	if ( ! is_page( 'thank-you' ) ) {
		return;
	}
	echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
}
add_action( 'wp_head', 'vila_akoya_thankyou_noindex', 1 );

require_once VILA_AKOYA_DIR . '/inc/html-to-blocks.php';
require_once VILA_AKOYA_DIR . '/inc/setup.php';

/**
 * Use the "Home" page as the static front page so `/` loads front-page.html + page content.
 * Default WordPress + wp-env often leave "Your latest posts" enabled, which shows an empty blog instead.
 */
function vila_akoya_ensure_static_front_page(): void {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}
	$home = get_page_by_path( 'home', OBJECT, 'page' );
	if ( ! $home ) {
		return;
	}
	if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) $home->ID ) {
		return;
	}
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', (int) $home->ID );
	// Main query was built before this; reload so front-page + page content render.
	wp_safe_redirect( home_url( '/' ), 302 );
	exit;
}
add_action( 'template_redirect', 'vila_akoya_ensure_static_front_page', 0 );

/**
 * If Contact Form 7 was activated after the theme, create forms once options are empty.
 */
function vila_akoya_maybe_create_cf7_forms(): void {
	if ( ! current_user_can( 'manage_options' ) || ! post_type_exists( 'wpcf7_contact_form' ) ) {
		return;
	}
	if ( absint( get_option( 'vila_akoya_cf7_booking', 0 ) ) > 0 ) {
		return;
	}
	if ( function_exists( 'vila_akoya_create_cf7_forms' ) ) {
		vila_akoya_create_cf7_forms();
	}
}
add_action( 'admin_init', 'vila_akoya_maybe_create_cf7_forms' );

/**
 * Remind admins to install Contact Form 7 if missing.
 */
function vila_akoya_admin_notice_cf7(): void {
	if ( ! current_user_can( 'activate_plugins' ) || function_exists( 'wpcf7_contact_form' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>';
	echo esc_html__( 'Vila Akoya: Install and activate the Contact Form 7 plugin so inquiry forms work.', 'vila-akoya' );
	echo '</p></div>';
}
add_action( 'admin_notices', 'vila_akoya_admin_notice_cf7' );
