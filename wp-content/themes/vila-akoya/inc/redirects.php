<?php
/**
 * Legacy path redirects (see brochure-website/_redirects).
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * 301 redirect /forest-school to /the-kids/
 */
function vila_akoya_legacy_redirects() {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
	if ( ! is_string( $path ) ) {
		return;
	}
	$path = untrailingslashit( $path );
	if ( '/forest-school' === $path ) {
		wp_safe_redirect( home_url( '/the-kids/' ), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'vila_akoya_legacy_redirects', 1 );
