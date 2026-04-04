<?php
/**
 * One-time: pages, menus, reading settings, block content.
 *
 * @package VilaAkoya
 */

defined( 'ABSPATH' ) || exit;

add_action( 'after_switch_theme', 'vila_akoya_run_theme_setup' );
add_action( 'admin_init', 'vila_akoya_run_theme_setup_lazy', 5 );
add_action( 'admin_init', 'vila_akoya_sync_seeded_page_contents_if_needed', 6 );

/**
 * Bump when default seeded block markup changes (existing sites update on next wp-admin load).
 *
 * @return int
 */
function vila_akoya_get_content_seed_version() {
	return 3;
}

/**
 * Refresh page block content when the seed version increases (keeps brochure updates in sync).
 */
function vila_akoya_sync_seeded_page_contents_if_needed() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$target = vila_akoya_get_content_seed_version();
	$stored = (int) get_option( 'vila_akoya_content_seed_version', 0 );
	if ( $stored >= $target ) {
		return;
	}
	if ( ! get_option( 'vila_akoya_setup_complete' ) ) {
		return;
	}

	require_once get_template_directory() . '/inc/all-blocks.php';

	$cf7_ids = get_option( 'vila_akoya_cf7_ids', array() );
	if ( ( ! is_array( $cf7_ids ) || empty( $cf7_ids['contact'] ) ) && post_type_exists( 'wpcf7_contact_form' ) ) {
		$cf7_ids = vila_akoya_seed_cf7_forms();
	}
	if ( ! is_array( $cf7_ids ) ) {
		$cf7_ids = array();
	}

	$contents = vila_akoya_get_seeded_page_contents( $cf7_ids );
	foreach ( $contents as $slug => $html ) {
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		if ( ! $page ) {
			continue;
		}
		wp_update_post(
			array(
				'ID'           => (int) $page->ID,
				'post_content' => $html,
			)
		);
	}

	update_option( 'vila_akoya_content_seed_version', $target );
}

/**
 * Run immediately after theme activation.
 */
function vila_akoya_run_theme_setup() {
	vila_akoya_seed_all();
}

/**
 * Run on first admin load if setup did not finish (e.g. CF7 loaded after switch).
 */
function vila_akoya_run_theme_setup_lazy() {
	if ( get_option( 'vila_akoya_setup_complete' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	vila_akoya_seed_all();
}

/**
 * Create CF7 forms, pages, menus, and front page settings.
 */
function vila_akoya_seed_all() {
	if ( get_option( 'vila_akoya_setup_complete' ) ) {
		return;
	}

	require_once get_template_directory() . '/inc/all-blocks.php';

	$cf7_ids = array();
	if ( post_type_exists( 'wpcf7_contact_form' ) ) {
		$cf7_ids = vila_akoya_seed_cf7_forms();
	}

	$contents = vila_akoya_get_seeded_page_contents( $cf7_ids );

	$definitions = array(
		array(
			'title'   => __( 'Home', 'vila-akoya' ),
			'slug'    => 'home',
			'content' => $contents['home'],
		),
		array(
			'title'   => __( 'About', 'vila-akoya' ),
			'slug'    => 'about',
			'content' => $contents['about'],
		),
		array(
			'title'   => __( 'Village', 'vila-akoya' ),
			'slug'    => 'village',
			'content' => $contents['village'],
		),
		array(
			'title'   => __( 'Campsite', 'vila-akoya' ),
			'slug'    => 'campsite',
			'content' => $contents['campsite'],
		),
		array(
			'title'   => __( 'Booking', 'vila-akoya' ),
			'slug'    => 'booking',
			'content' => $contents['booking'],
		),
		array(
			'title'   => __( 'The Campus', 'vila-akoya' ),
			'slug'    => 'campus',
			'content' => $contents['campus'],
		),
		array(
			'title'   => __( 'The Kids', 'vila-akoya' ),
			'slug'    => 'the-kids',
			'content' => $contents['the-kids'],
		),
		array(
			'title'   => __( 'Contact', 'vila-akoya' ),
			'slug'    => 'contact',
			'content' => $contents['contact'],
		),
		array(
			'title'   => __( 'Thank You', 'vila-akoya' ),
			'slug'    => 'thank-you',
			'content' => $contents['thank-you'],
		),
	);

	$page_ids = array();
	foreach ( $definitions as $def ) {
		$existing = get_page_by_path( $def['slug'], OBJECT, 'page' );
		if ( $existing ) {
			$page_ids[ $def['slug'] ] = (int) $existing->ID;
			continue;
		}
		$pid = wp_insert_post(
			array(
				'post_title'   => $def['title'],
				'post_name'    => $def['slug'],
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => $def['content'],
			),
			true
		);
		if ( ! is_wp_error( $pid ) && $pid ) {
			$page_ids[ $def['slug'] ] = (int) $pid;
		}
	}

	$home_id = isset( $page_ids['home'] ) ? $page_ids['home'] : 0;
	if ( ! $home_id ) {
		$h = get_page_by_path( 'home', OBJECT, 'page' );
		if ( $h ) {
			$home_id = (int) $h->ID;
		}
	}

	if ( $home_id ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $home_id );
	}

	vila_akoya_seed_nav_menus( $page_ids );

	update_option( 'vila_akoya_setup_complete', 1 );
	update_option( 'vila_akoya_content_seed_version', vila_akoya_get_content_seed_version() );
}

/**
 * Assign primary/footer menus from pages.
 *
 * @param array<string,int> $page_ids Slug => page ID.
 */
function vila_akoya_seed_nav_menus( $page_ids ) {
	$menu_name = 'Vila Akoya Primary';
	$menu      = wp_get_nav_menu_object( $menu_name );
	if ( ! $menu ) {
		$menu_id = wp_create_nav_menu( $menu_name );
		if ( is_wp_error( $menu_id ) ) {
			return;
		}
	} else {
		$menu_id = (int) $menu->term_id;
	}

	$order = array( 'home', 'village', 'campsite', 'booking', 'campus', 'the-kids', 'about', 'contact' );
	foreach ( $order as $slug ) {
		if ( empty( $page_ids[ $slug ] ) ) {
			continue;
		}
		$pid = (int) $page_ids[ $slug ];
		if ( $pid < 1 ) {
			continue;
		}
		$items = wp_get_nav_menu_items( $menu_id );
		$exists = false;
		if ( is_array( $items ) ) {
			foreach ( $items as $it ) {
				if ( (int) $it->object_id === $pid ) {
					$exists = true;
					break;
				}
			}
		}
		if ( $exists ) {
			continue;
		}
		wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'     => get_the_title( $pid ),
				'menu-item-object-id' => $pid,
				'menu-item-object'    => 'page',
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
			)
		);
	}

	$locations = get_theme_mod( 'nav_menu_locations', array() );
	if ( ! is_array( $locations ) ) {
		$locations = array();
	}
	$locations['primary'] = $menu_id;
	$locations['footer']  = $menu_id;
	set_theme_mod( 'nav_menu_locations', $locations );
}
