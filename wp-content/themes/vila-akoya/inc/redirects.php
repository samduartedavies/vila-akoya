<?php
/**
 * Legacy path redirects (brochure _redirects parity).
 *
 * @package VilaAkoya
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'template_redirect',
	function () {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		$path = trim( (string) $path, '/' );
		if ( 'forest-school' === $path ) {
			wp_safe_redirect( home_url( '/the-kids/' ), 301 );
			exit;
		}
	}
);
