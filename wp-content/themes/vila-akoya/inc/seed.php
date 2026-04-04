<?php
/**
 * One-time seed: pages, front page, CF7 IDs in content.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Run seed when an admin visits the dashboard (idempotent).
 */
function vila_akoya_maybe_seed_content() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( '1' === get_option( 'vila_akoya_content_seeded' ) ) {
		return;
	}
	vila_akoya_run_seed();
	update_option( 'vila_akoya_content_seeded', '1' );
}
add_action( 'admin_init', 'vila_akoya_maybe_seed_content', 5 );

/**
 * Create CF7 forms and all brochure pages with block content.
 */
function vila_akoya_run_seed() {
	$cf7 = function_exists( 'vila_akoya_get_cf7_form_ids' ) ? vila_akoya_get_cf7_form_ids() : array();

	$dir = VILA_AKOYA_DIR . '/inc/page-content/';

	$map = array(
		'home'      => array(
			'title' => 'Home',
			'file'  => 'home-blocks.html',
		),
		'about'     => array( 'title' => 'About', 'file' => 'about-blocks.html' ),
		'village'   => array( 'title' => 'Village', 'file' => 'village-blocks.html' ),
		'campsite'  => array( 'title' => 'Campsite', 'file' => 'campsite-blocks.html' ),
		'booking'   => array( 'title' => 'Booking', 'file' => 'booking-blocks.html' ),
		'campus'    => array( 'title' => 'The Campus', 'file' => 'campus-blocks.html' ),
		'the-kids'  => array( 'title' => 'The Kids', 'file' => 'the-kids-blocks.html' ),
		'contact'   => array( 'title' => 'Contact', 'file' => 'contact-blocks.html' ),
		'thank-you' => array( 'title' => 'Thank You', 'file' => 'thank-you-blocks.html' ),
	);

	$page_ids = array();

	foreach ( $map as $slug => $def ) {
		$path = $dir . $def['file'];
		if ( ! is_readable( $path ) ) {
			continue;
		}
		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = vila_akoya_seed_replace_cf7_placeholders( $content, $cf7 );

		$existing = get_page_by_path( $slug, OBJECT, 'page' );
		$args     = array(
			'post_title'   => $def['title'],
			'post_name'    => $slug,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => $content,
		);
		if ( $existing ) {
			$args['ID'] = $existing->ID;
			wp_update_post( $args );
			$page_ids[ $slug ] = (int) $existing->ID;
		} else {
			$pid = wp_insert_post( $args, true );
			if ( ! is_wp_error( $pid ) ) {
				$page_ids[ $slug ] = (int) $pid;
			}
		}
	}

	if ( ! empty( $page_ids['home'] ) ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_ids['home'] );
	}
}

/**
 * Replace {{cf7:key}} placeholders in block HTML.
 *
 * @param string             $html HTML / block content.
 * @param array<string, int> $cf7  CF7 form IDs.
 * @return string
 */
function vila_akoya_seed_replace_cf7_placeholders( $html, array $cf7 ) {
	$classes = array(
		'newsletter_home'    => 'vila-cf7 vila-cf7--newsletter',
		'newsletter_village' => 'vila-cf7 vila-cf7--newsletter',
		'booking'            => 'vila-cf7 vila-cf7--booking',
		'campus'             => 'vila-cf7 vila-cf7--campus',
		'contact'            => 'vila-cf7 vila-cf7--contact',
	);

	foreach ( $classes as $key => $class ) {
		$id      = isset( $cf7[ $key ] ) ? (int) $cf7[ $key ] : 0;
		$replace = $id
			? sprintf( '[contact-form-7 id="%d" html_class="%s"]', $id, esc_attr( $class ) )
			: '';
		$html    = str_replace( '{{cf7:' . $key . '}}', $replace, $html );
	}

	if ( strpos( $html, '{{cf7:' ) !== false ) {
		$html = preg_replace(
			'/\{\{cf7:[a-z0-9_]+\}\}/',
			'',
			$html
		);
	}

	return $html;
}
