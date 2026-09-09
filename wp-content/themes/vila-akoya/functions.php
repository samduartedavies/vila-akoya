<?php
/**
 * Vila Akoya block theme.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

define( 'VILA_AKOYA_VERSION', '1.0.88' );
define( 'VILA_AKOYA_DIR', get_template_directory() );
define( 'VILA_AKOYA_URI', get_template_directory_uri() );

require_once VILA_AKOYA_DIR . '/inc/typography.php';
require_once VILA_AKOYA_DIR . '/inc/redirects.php';
require_once VILA_AKOYA_DIR . '/inc/woocommerce-campsite.php';
require_once VILA_AKOYA_DIR . '/inc/campsite-booking-email.php';
require_once VILA_AKOYA_DIR . '/inc/booking-extras.php';
require_once VILA_AKOYA_DIR . '/inc/stays.php';
require_once VILA_AKOYA_DIR . '/inc/campsite-plots-admin.php';
require_once VILA_AKOYA_DIR . '/inc/cf7-setup.php';
require_once VILA_AKOYA_DIR . '/inc/cf7-email-copy-admin.php';
require_once VILA_AKOYA_DIR . '/inc/mc4wp-cf7.php';
require_once VILA_AKOYA_DIR . '/inc/nav-walkers.php';
require_once VILA_AKOYA_DIR . '/inc/permalink-nav.php';
require_once VILA_AKOYA_DIR . '/inc/location-map.php';
require_once VILA_AKOYA_DIR . '/inc/blog.php';
require_once VILA_AKOYA_DIR . '/inc/events.php';
require_once VILA_AKOYA_DIR . '/inc/template-reset.php';
require_once VILA_AKOYA_DIR . '/inc/page-editor.php';
require_once VILA_AKOYA_DIR . '/inc/block-patterns.php';
require_once VILA_AKOYA_DIR . '/inc/sections.php';
require_once VILA_AKOYA_DIR . '/inc/blocks.php';
require_once VILA_AKOYA_DIR . '/inc/seed.php';
require_once VILA_AKOYA_DIR . '/inc/site-deploy.php';

/**
 * Body classes that select brochure Palette B (neutral cream / #fcf9ef family) via CSS variables in vila-palette.css.
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function vila_akoya_body_class_neutral_canvas( $classes ) {
	if ( is_front_page() || is_page( 'home' ) ) {
		$classes[] = 'vila-home';
	} elseif ( is_page() && function_exists( 'vila_akoya_page_has_section_blocks' ) && vila_akoya_page_has_section_blocks( get_queried_object_id() ) ) {
		$classes[] = 'vila-page-inner';
	}
	if ( is_page( 'contact' ) ) {
		$classes[] = 'vila-page-contact';
	}
	if ( is_page( 'about' ) ) {
		$classes[] = 'vila-page-about';
	}
	if ( is_page( 'thank-you' ) ) {
		$classes[] = 'vila-page-thank-you';
	}
	return $classes;
}
add_filter( 'body_class', 'vila_akoya_body_class_neutral_canvas' );

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

	register_nav_menus(
		array(
			'header' => __( 'Header Navigation', 'vila-akoya' ),
			'footer' => __( 'Footer Navigation', 'vila-akoya' ),
		)
	);
}
add_action( 'after_setup_theme', 'vila_akoya_setup' );

/**
 * Cache-busting query string for a theme file (version + mtime).
 *
 * @param string $relative_path Path under the theme directory, e.g. '/assets/js/booking-ui.js'.
 * @return string
 */
function vila_akoya_asset_version( $relative_path ) {
	$ver  = defined( 'VILA_AKOYA_VERSION' ) ? VILA_AKOYA_VERSION : '1.0.0';
	$path = VILA_AKOYA_DIR . $relative_path;
	if ( is_readable( $path ) ) {
		$ver .= '.' . (string) filemtime( $path );
	}
	return $ver;
}

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
	$theme_deps = array();
	if (
		class_exists( 'WooCommerce' )
		&& (
			( function_exists( 'is_woocommerce' ) && is_woocommerce() )
			|| is_cart()
			|| is_checkout()
			|| is_account_page()
			|| is_page( 'booking' )
			|| is_singular( 'stay' )
			|| is_post_type_archive( 'stay' )
		)
	) {
		$theme_deps[] = 'woocommerce-general';
	}
	wp_enqueue_style(
		'vila-akoya-theme',
		VILA_AKOYA_URI . '/assets/css/theme.css',
		$theme_deps,
		vila_akoya_asset_version( '/assets/css/theme.css' )
	);
	wp_enqueue_script(
		'vila-akoya-site-nav',
		VILA_AKOYA_URI . '/assets/js/site-nav.js',
		array(),
		VILA_AKOYA_VERSION,
		true
	);
	wp_enqueue_script(
		'vila-akoya-gallery-lightbox',
		VILA_AKOYA_URI . '/assets/js/gallery-lightbox.js',
		array(),
		VILA_AKOYA_VERSION,
		true
	);
	wp_enqueue_script(
		'vila-akoya-site-motion',
		VILA_AKOYA_URI . '/assets/js/site-motion.js',
		array(),
		VILA_AKOYA_VERSION,
		true
	);
	if ( function_exists( 'wpcf7_contact_form' ) ) {
		wp_enqueue_script(
			'vila-akoya-cf7-success',
			VILA_AKOYA_URI . '/assets/js/cf7-redirect.js',
			array(),
			VILA_AKOYA_VERSION,
			true
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

/**
 * Print favicon and web app icon links from brochure icon set.
 */
function vila_akoya_print_favicon_meta() {
	$icons_uri = trailingslashit( VILA_AKOYA_URI . '/assets/icons' );
	?>
	<link rel="icon" type="image/png" href="<?php echo esc_url( $icons_uri . 'favicon-96x96.png' ); ?>" sizes="96x96" />
	<link rel="icon" type="image/svg+xml" href="<?php echo esc_url( $icons_uri . 'favicon.svg' ); ?>" />
	<link rel="shortcut icon" href="<?php echo esc_url( $icons_uri . 'favicon.ico' ); ?>" />
	<link rel="apple-touch-icon" sizes="180x180" href="<?php echo esc_url( $icons_uri . 'apple-touch-icon.png' ); ?>" />
	<meta name="apple-mobile-web-app-title" content="Vila Akoya" />
	<link rel="manifest" href="<?php echo esc_url( $icons_uri . 'site.webmanifest' ); ?>" />
	<?php
}
add_action( 'wp_head', 'vila_akoya_print_favicon_meta', 1 );
