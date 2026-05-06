<?php
/**
 * Pretty permalinks + root-relative brochure links resolve to real WordPress URLs.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bump when brochure rewrite registration changes; triggers one hard flush.
 */
define( 'VILA_AKOYA_BROCHURE_REWRITE_VER', '2' );

/**
 * Hard-flush rewrite rules and write .htaccess for Apache (mod_rewrite).
 *
 * core flush_rewrite_rules( true ) alone often skips save_mod_rewrite_rules() when
 * wp-admin/includes/misc.php is not loaded (front-end, WP-CLI), so /slug/ URLs 404.
 */
function vila_akoya_flush_rewrite_rules_hard() {
	flush_rewrite_rules( true );
	if ( ! function_exists( 'save_mod_rewrite_rules' ) ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}
	if ( function_exists( 'save_mod_rewrite_rules' ) ) {
		save_mod_rewrite_rules();
	}
}

/**
 * Brochure page slugs used in templates (href="/slug").
 *
 * @return list<string>
 */
function vila_akoya_internal_page_slugs() {
	return array(
		'village',
		'campsite',
		'booking',
		'campus',
		'the-kids',
		'about',
		'contact',
		'thank-you',
		'home',
	);
}

/**
 * First path segment after the home path (single-segment brochure URLs only).
 *
 * @return string|null
 */
function vila_akoya_request_brochure_segment() {
	if ( empty( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		return null;
	}
	$raw  = (string) wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
	$path = (string) wp_parse_url( $raw, PHP_URL_PATH );
	$path = trim( $path, '/' );
	$home_path = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	if ( '' !== $home_path && 0 === strpos( $path, $home_path ) ) {
		$path = trim( substr( $path, strlen( $home_path ) ), '/' );
	}
	if ( '' === $path || false !== strpos( $path, '/' ) ) {
		return null;
	}
	return $path;
}

/**
 * Register explicit rules before any flush so they are included in rewrite_rules option.
 */
function vila_akoya_register_brochure_page_rewrites() {
	foreach ( vila_akoya_internal_page_slugs() as $slug ) {
		add_rewrite_rule( '^' . preg_quote( $slug, '/' ) . '/?$', 'index.php?pagename=' . $slug, 'top' );
	}
}
add_action( 'init', 'vila_akoya_register_brochure_page_rewrites', -1 );

/**
 * Use "Post name" permalinks so /village/ works; flush rules once when needed.
 */
function vila_akoya_ensure_pretty_permalinks() {
	if ( wp_doing_ajax() || wp_installing() ) {
		return;
	}
	$expected = '/%postname%/';
	$current  = (string) get_option( 'permalink_structure', '' );
	if ( $current === $expected ) {
		return;
	}
	update_option( 'permalink_structure', $expected );
	vila_akoya_flush_rewrite_rules_hard();
	update_option( 'vila_akoya_permalinks_flushed', '1' );
}
add_action( 'init', 'vila_akoya_ensure_pretty_permalinks', 0 );

/**
 * One-time flush after brochure rules were added to this theme version.
 */
function vila_akoya_maybe_flush_brochure_rewrites() {
	if ( wp_installing() ) {
		return;
	}
	if ( (string) get_option( 'vila_akoya_brochure_rewrite_ver', '' ) === VILA_AKOYA_BROCHURE_REWRITE_VER ) {
		return;
	}
	vila_akoya_flush_rewrite_rules_hard();
	update_option( 'vila_akoya_brochure_rewrite_ver', VILA_AKOYA_BROCHURE_REWRITE_VER );
}
add_action( 'init', 'vila_akoya_maybe_flush_brochure_rewrites', 99 );

/**
 * When rewrites are missing or stale, set pagename so WP_Query resolves the page.
 *
 * @param WP $wp WP instance.
 */
function vila_akoya_parse_request_brochure_page( $wp ) {
	if ( wp_doing_ajax() || wp_installing() ) {
		return;
	}
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
	if ( '' !== $uri && preg_match( '#/(wp-admin|wp-login\\.php|wp-cron\\.php|xmlrpc\\.php)(/|$)#', $uri ) ) {
		return;
	}
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	if ( '' !== $path && false !== strpos( $path, '/wp-json' ) ) {
		return;
	}
	if ( ! empty( $wp->query_vars['pagename'] ) ) {
		return;
	}
	if ( ! empty( $wp->query_vars['name'] ) || ! empty( $wp->query_vars['p'] ) || ! empty( $wp->query_vars['attachment'] ) || ! empty( $wp->query_vars['feed'] ) ) {
		return;
	}
	$segment = vila_akoya_request_brochure_segment();
	if ( null === $segment || ! in_array( $segment, vila_akoya_internal_page_slugs(), true ) ) {
		return;
	}
	$page = get_page_by_path( $segment, OBJECT, 'page' );
	if ( ! $page || 'publish' !== $page->post_status ) {
		return;
	}
	unset( $wp->query_vars['error'] );
	$wp->query_vars['post_type'] = 'page';
	$wp->query_vars['pagename']  = $segment;
}
add_action( 'parse_request', 'vila_akoya_parse_request_brochure_page', 999 );

/**
 * Last resort: fix main query when something still ended as 404.
 */
function vila_akoya_fallback_brochure_page_on_404() {
	if ( ! is_404() ) {
		return;
	}
	$segment = vila_akoya_request_brochure_segment();
	if ( null === $segment || ! in_array( $segment, vila_akoya_internal_page_slugs(), true ) ) {
		return;
	}
	$page = get_page_by_path( $segment, OBJECT, 'page' );
	if ( ! $page || 'publish' !== $page->post_status ) {
		return;
	}
	global $wp_query;
	$wp_query->queried_object    = $page;
	$wp_query->queried_object_id = (int) $page->ID;
	$wp_query->is_page             = true;
	$wp_query->is_singular         = true;
	$wp_query->is_404              = false;
	$wp_query->is_archive          = false;
	$wp_query->posts               = array( $page );
	$wp_query->post_count          = 1;
	$wp_query->found_posts         = 1;
	$wp_query->max_num_pages       = 1;
	$wp_query->post                = $page;
	$GLOBALS['post']               = $page;
	setup_postdata( $page );
}
add_action( 'template_redirect', 'vila_akoya_fallback_brochure_page_on_404', 5 );

/**
 * Replace href="/slug" with full site URLs in post content (fixes subdirectory installs & direct nav).
 *
 * @param string $html HTML.
 * @return string
 */
function vila_akoya_absolutize_brochure_hrefs( $html ) {
	$html = is_string( $html ) ? $html : '';
	if ( '' === $html || strpos( $html, 'href="/' ) === false ) {
		return $html;
	}
	foreach ( vila_akoya_internal_page_slugs() as $slug ) {
		$url = esc_url( home_url( '/' . $slug . '/' ) );
		if ( strpos( $html, 'href="/' . $slug . '#' ) !== false ) {
			// Delimiter must not be `#` — the pattern includes `#fragment` (e.g. /campus#section).
			$replaced = preg_replace(
				'~href="/' . preg_quote( $slug, '~' ) . '(#[^"\'>\s]*)~',
				'href="' . $url . '$1"',
				$html
			);
			if ( is_string( $replaced ) ) {
				$html = $replaced;
			}
		}
		$html = str_replace( 'href="/' . $slug . '"', 'href="' . $url . '"', $html );
		$html = str_replace( 'href="/' . $slug . '/"', 'href="' . $url . '"', $html );
	}
	$home = esc_url( trailingslashit( home_url() ) );
	$html = str_replace( 'href="/"', 'href="' . $home . '"', $html );
	return $html;
}

/**
 * Fix stored block HTML that still uses root-relative internal links (front + editor preview).
 */
function vila_akoya_filter_content_absolutize_links( $content ) {
	if ( null === $content ) {
		return '';
	}
	if ( ! is_string( $content ) ) {
		return $content;
	}
	if ( '' === $content ) {
		return '';
	}
	return vila_akoya_absolutize_brochure_hrefs( $content );
}
add_filter( 'the_content', 'vila_akoya_filter_content_absolutize_links', 12 );

/**
 * Header markup with home_url() for every nav link (avoids 404 when permalinks or paths differ).
 */
function vila_akoya_shortcode_header_nav() {
	$h = static function ( $slug = '' ) {
		if ( '' === $slug ) {
			return esc_url( trailingslashit( home_url() ) );
		}
		return esc_url( home_url( '/' . $slug . '/' ) );
	};
	ob_start();
	?>
<nav class="fixed top-0 left-0 right-0 z-50 bg-[#fcf9ef]/80 backdrop-blur-xl shadow-[0_20px_50px_rgba(0,0,0,0.04)]">
<div class="flex justify-between items-center px-8 py-6 max-w-[1236px] box-border mx-auto">
<a class="text-4xl font-normal text-orange-800 font-barrio shrink-0 leading-none" href="<?php echo $h( '' ); ?>">Vila Akoya</a>
<div id="site-nav-desktop-links" class="hidden min-[1020px]:flex items-center ml-12 max-[1240px]:ml-6 space-x-4 max-[1240px]:space-x-2 font-body text-[0.95rem] max-[1240px]:text-[0.84rem] tracking-[0.01em]">
<a data-nav="village" class="text-stone-600 hover:text-[#8f3a1d] transition-colors hover:bg-[#9a4021]/10 px-3 py-1.5 rounded-full" href="<?php echo $h( 'village' ); ?>">The Village</a>
<a data-nav="campsite" class="text-stone-600 hover:text-[#8f3a1d] transition-colors hover:bg-[#9a4021]/10 px-3 py-1.5 rounded-full" href="<?php echo $h( 'campsite' ); ?>">The Campsite</a>
<a data-nav="booking" class="text-stone-600 hover:text-[#8f3a1d] transition-colors hover:bg-[#9a4021]/10 px-3 py-1.5 rounded-full" href="<?php echo $h( 'booking' ); ?>">Join us</a>
<a data-nav="campus" class="text-stone-600 hover:text-[#8f3a1d] transition-colors hover:bg-[#9a4021]/10 px-3 py-1.5 rounded-full" href="<?php echo $h( 'campus' ); ?>">The Campus</a>
<a data-nav="the-kids" class="text-stone-600 hover:text-[#8f3a1d] transition-colors hover:bg-[#9a4021]/10 px-3 py-1.5 rounded-full" href="<?php echo $h( 'the-kids' ); ?>">The Kids</a>
<a data-nav="about" class="text-stone-600 hover:text-[#8f3a1d] transition-colors hover:bg-[#9a4021]/10 px-3 py-1.5 rounded-full" href="<?php echo $h( 'about' ); ?>">About Us</a>
<a data-nav="contact" class="text-stone-600 hover:text-[#8f3a1d] transition-colors hover:bg-[#9a4021]/10 px-3 py-1.5 rounded-full" href="<?php echo $h( 'contact' ); ?>">Contact</a>
</div>
<div class="flex items-center gap-2">
<button type="button" id="site-nav-toggle" class="min-[1020px]:hidden inline-flex rounded-lg p-2 text-[#9a4021] hover:bg-[#9a4021]/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#9a4021]/50" aria-expanded="false" aria-controls="site-mobile-nav" aria-label="Open menu">
<span class="material-symbols-outlined text-3xl" aria-hidden="true">menu</span>
</button>
<a class="hidden min-[1020px]:inline-block bg-[#9a4021] text-white px-8 py-3 rounded-full font-medium hover:bg-[#ba5737] scale-95 active:scale-90 transition-all duration-300 text-center" href="<?php echo $h( 'booking' ); ?>">Book your stay</a>
</div>
</div>
<div id="site-mobile-nav" class="hidden min-[1020px]:hidden border-t border-stone-200/80 bg-[#fcf9ef]/95 backdrop-blur-xl">
<div class="max-w-[1236px] box-border mx-auto px-8">
<div id="site-nav-mobile-links" class="flex flex-col space-y-0.5 font-body text-base tracking-wide py-1">
<a data-nav="village" class="block rounded-lg px-2 py-3 text-stone-600 hover:bg-[#9a4021]/5" href="<?php echo $h( 'village' ); ?>">The Village</a>
<a data-nav="campsite" class="block rounded-lg px-2 py-3 text-stone-600 hover:bg-[#9a4021]/5" href="<?php echo $h( 'campsite' ); ?>">The Campsite</a>
<a data-nav="booking" class="block rounded-lg px-2 py-3 text-stone-600 hover:bg-[#9a4021]/5" href="<?php echo $h( 'booking' ); ?>">Join us</a>
<a data-nav="campus" class="block rounded-lg px-2 py-3 text-stone-600 hover:bg-[#9a4021]/5" href="<?php echo $h( 'campus' ); ?>">The Campus</a>
<a data-nav="the-kids" class="block rounded-lg px-2 py-3 text-stone-600 hover:bg-[#9a4021]/5" href="<?php echo $h( 'the-kids' ); ?>">The Kids</a>
<a data-nav="about" class="block rounded-lg px-2 py-3 text-stone-600 hover:bg-[#9a4021]/5" href="<?php echo $h( 'about' ); ?>">About Us</a>
<a data-nav="contact" class="block rounded-lg px-2 py-3 text-stone-600 hover:bg-[#9a4021]/5" href="<?php echo $h( 'contact' ); ?>">Contact</a>
</div>
<a class="mt-10 mb-4 block w-full text-center bg-[#9a4021] text-white px-8 py-3 rounded-full font-medium hover:bg-[#ba5737] transition-all duration-300" href="<?php echo $h( 'booking' ); ?>">Book your stay</a>
</div>
</div>
</nav>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'vila_akoya_header_nav', 'vila_akoya_shortcode_header_nav' );

/**
 * Footer links with home_url().
 */
function vila_akoya_shortcode_footer_nav() {
	$h = static function ( $slug = '' ) {
		if ( '' === $slug ) {
			return esc_url( trailingslashit( home_url() ) );
		}
		return esc_url( home_url( '/' . $slug . '/' ) );
	};
	ob_start();
	?>
<footer class="w-full rounded-t-[3rem] mt-20 bg-[#2f3628] text-orange-50">
<div class="flex flex-col md:flex-row justify-between items-center w-full px-12 py-16 gap-8 text-center md:text-left">
<div class="space-y-4">
<a class="text-2xl font-normal text-orange-50 font-barrio block hover:opacity-90" href="<?php echo $h( '' ); ?>">Vila Akoya</a>
<p class="text-sm font-medium font-body text-orange-50/60 uppercase tracking-widest">A tiny house village and creative campsite</p>
</div>
<div class="vila-footer-links flex flex-wrap justify-center md:justify-end gap-x-8 gap-y-4">
<a class="text-orange-50/80 hover:text-orange-50 text-base font-light font-body hover:translate-y-[-2px] transition-transform duration-300" href="<?php echo $h( 'village' ); ?>">The Village</a>
<a class="text-orange-50/80 hover:text-orange-50 text-base font-light font-body hover:translate-y-[-2px] transition-transform duration-300" href="<?php echo $h( 'campsite' ); ?>">The Campsite</a>
<a class="text-orange-50/80 hover:text-orange-50 text-base font-light font-body hover:translate-y-[-2px] transition-transform duration-300" href="<?php echo $h( 'booking' ); ?>">Join us</a>
<a class="text-orange-50/80 hover:text-orange-50 text-base font-light font-body hover:translate-y-[-2px] transition-transform duration-300" href="<?php echo $h( 'campus' ); ?>">The Campus</a>
<a class="text-orange-50/80 hover:text-orange-50 text-base font-light font-body hover:translate-y-[-2px] transition-transform duration-300" href="<?php echo $h( 'the-kids' ); ?>">The Kids</a>
<a class="text-orange-50/80 hover:text-orange-50 text-base font-light font-body hover:translate-y-[-2px] transition-transform duration-300" href="<?php echo $h( 'about' ); ?>">About Us</a>
<a class="text-orange-50/80 hover:text-orange-50 text-base font-light font-body hover:translate-y-[-2px] transition-transform duration-300" href="<?php echo $h( 'contact' ); ?>">Contact</a>
</div>
</div>
<div class="w-full px-12 py-8 border-t border-white/5 text-center text-xs font-medium font-body opacity-40">© 2026 Vila Akoya. All rights reserved.</div>
</footer>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'vila_akoya_footer_nav', 'vila_akoya_shortcode_footer_nav' );
