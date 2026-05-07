<?php
/**
 * One-time seed: pages, front page, CF7 IDs in content.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/permalink-nav.php';

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
 * First activation: seed without requiring a dashboard visit.
 */
function vila_akoya_maybe_seed_on_switch_theme() {
	if ( '1' === get_option( 'vila_akoya_content_seeded' ) ) {
		return;
	}
	vila_akoya_run_seed();
	update_option( 'vila_akoya_content_seeded', '1' );
}
add_action( 'after_switch_theme', 'vila_akoya_maybe_seed_on_switch_theme' );

/**
 * Create CF7 forms and all brochure pages with block content.
 */
function vila_akoya_run_seed() {
	if ( function_exists( 'vila_akoya_ensure_campsite_booking_product' ) ) {
		vila_akoya_ensure_campsite_booking_product();
	}
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
		$content = vila_akoya_seed_replace_wc_placeholders( $content );
		$content = vila_akoya_seed_localize_image_urls( $content );
		$content = vila_akoya_absolutize_brochure_hrefs( $content );

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
 * Replace static brochure CDN image URLs with local attachment URLs by filename.
 *
 * This keeps seeded content portable while still loading images from the local media library.
 *
 * @param string $html HTML / block content.
 * @return string
 */
function vila_akoya_seed_localize_image_urls( $html ) {
	$html = is_string( $html ) ? $html : '';
	if ( '' === $html || false === strpos( $html, 'https://vila-akoya.com/images/' ) ) {
		return $html;
	}

	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT p.ID, pm.meta_value AS attached_file
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm
		   ON pm.post_id = p.ID
		  AND pm.meta_key = '_wp_attached_file'
		 WHERE p.post_type = 'attachment'"
	);

	$map = array();
	foreach ( (array) $rows as $row ) {
		$attached = isset( $row->attached_file ) ? (string) $row->attached_file : '';
		$name     = basename( $attached );
		$url      = wp_get_attachment_url( (int) $row->ID );
		if ( '' !== $name && $url ) {
			$map[ $name ] = wp_make_link_relative( (string) $url );
		}
	}

	if ( empty( $map ) ) {
		return $html;
	}

	$localized = preg_replace_callback(
		'#https://vila-akoya\\.com/images/([^"\\s>]+)#',
		static function ( array $matches ) use ( $map ) {
			$file = basename( (string) $matches[1] );
			if ( isset( $map[ $file ] ) ) {
				return $map[ $file ];
			}
			$base = pathinfo( $file, PATHINFO_FILENAME );
			$ext  = pathinfo( $file, PATHINFO_EXTENSION );
			$slug = preg_quote( $base, '#' );
			$extq = preg_quote( $ext, '#' );
			foreach ( $map as $attached_file => $attached_url ) {
				if ( preg_match( '#^' . $slug . '(?:-[0-9]+)?(?:-scaled)?\\.' . $extq . '$#i', (string) $attached_file ) ) {
					return (string) $attached_url;
				}
			}
			return (string) $matches[0];
		},
		$html
	);

	return is_string( $localized ) ? $localized : $html;
}

/**
 * Replace {{cf7:key}} placeholders in block HTML.
 *
 * @param string             $html HTML / block content.
 * @param array<string, int> $cf7  CF7 form IDs.
 * @return string
 */
function vila_akoya_seed_replace_cf7_placeholders( $html, array $cf7 ) {
	$html = is_string( $html ) ? $html : '';
	$classes = array(
		'newsletter_home'    => 'vila-cf7 vila-cf7--newsletter',
		'newsletter_village' => 'vila-cf7 vila-cf7--newsletter',
		'booking'            => 'vila-cf7 vila-cf7--booking',
		'village_inquiry'    => 'vila-cf7 vila-cf7--booking',
		'campus'             => 'vila-cf7 vila-cf7--campus',
		'contact'            => 'vila-cf7 vila-cf7--contact',
	);

	foreach ( $classes as $key => $class ) {
		$id      = isset( $cf7[ $key ] ) ? (string) $cf7[ $key ] : '';
		$replace = '' !== $id
			? sprintf( '[contact-form-7 id="%s" html_class="%s"]', esc_attr( $id ), esc_attr( $class ) )
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

/**
 * Replace {{wc:…}} placeholders (WooCommerce shortcodes).
 *
 * @param string $html HTML / block content.
 * @return string
 */
function vila_akoya_seed_replace_wc_placeholders( $html ) {
	$html = is_string( $html ) ? $html : '';
	$pid = function_exists( 'vila_akoya_get_campsite_primary_product_id' ) ? (int) vila_akoya_get_campsite_primary_product_id() : (int) get_option( 'vila_akoya_campsite_product_id' );
	if ( $pid && get_post_status( $pid ) === 'publish' ) {
		$campsite = sprintf( '[product_page id="%d"]', $pid );
	} else {
		$campsite = '';
	}
	$html = str_replace( '{{wc:campsite_booking}}', $campsite, $html );

	if ( strpos( $html, '{{wc:' ) !== false ) {
		$html = preg_replace( '/\{\{wc:[a-z0-9_]+\}\}/', '', $html );
	}

	return $html;
}
