<?php
/**
 * Page seeding for V1 IA + design content (bump VILA_AKOYA_SEED_VERSION to refresh default copy).
 *
 * @package vila-akoya
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VILA_AKOYA_SEED_VERSION', 5 );

/**
 * Create or update core pages when the seed version increases.
 */
function vila_akoya_maybe_seed_pages() {
	$seed_version = (int) get_option( 'vila_akoya_seed_version', 0 );

	// If options say we’re seeded but the Home page is missing (empty DB, import mismatch), re-seed.
	if ( $seed_version >= VILA_AKOYA_SEED_VERSION ) {
		$home_ok = ! empty(
			get_posts(
				array(
					'name'             => 'home',
					'post_type'        => 'page',
					'post_status'      => 'any',
					'posts_per_page'   => 1,
					'suppress_filters' => true,
				)
			)
		);
		if ( $home_ok ) {
			return;
		}
		delete_option( 'vila_akoya_seed_version' );
		delete_option( 'vila_akoya_urls_configured' );
		$seed_version = 0;
	}

	if ( $seed_version >= VILA_AKOYA_SEED_VERSION ) {
		return;
	}

	// Re-run permalink + homepage config after content/slug updates (see inc/page-urls.php).
	delete_option( 'vila_akoya_urls_configured' );

	// Legacy flag from v1: allow migration to seed_version 2.
	if ( get_option( 'vila_akoya_pages_seeded' ) && ! get_option( 'vila_akoya_seed_version' ) ) {
		update_option( 'vila_akoya_seed_version', 1 );
	}

	$pages = array(
		'home'                     => array(
			'title'   => 'Home',
			'content' => vila_akoya_home_content(),
		),
		'the-village'              => array(
			'title'   => 'The Village',
			'content' => vila_akoya_brochure_village_content(),
		),
		'the-campsite'             => array(
			'title'   => 'The Campsite',
			'content' => vila_akoya_brochure_campsite_content(),
		),
		'book'                     => array(
			'title'    => 'Book / inquire',
			'content'  => vila_akoya_book_content(),
			'template' => 'page-book',
		),
		'about'                    => array(
			'title'   => 'About',
			'content' => vila_akoya_about_content(),
		),
		'contact'                  => array(
			'title'   => 'Contact',
			'content' => vila_akoya_contact_content(),
		),
		'homeschooling-collective' => array(
			'title'   => 'Homeschooling collective',
			'content' => vila_akoya_homeschooling_content(),
		),
		'the-campus'               => array(
			'title'   => 'The Campus',
			'content' => vila_akoya_campus_content(),
		),
	);

	$ids = array();

	foreach ( $pages as $slug => $cfg ) {
		$existing = get_posts(
			array(
				'name'             => $slug,
				'post_type'        => 'page',
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'suppress_filters' => true,
			)
		);

		if ( empty( $existing ) ) {
			$post_id = wp_insert_post(
				array(
					'post_title'   => $cfg['title'],
					'post_name'    => $slug,
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_content' => $cfg['content'],
					'post_author'  => 1,
				),
				true
			);
		} else {
			$post_id = (int) $existing[0]->ID;
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_title'   => $cfg['title'],
					'post_name'    => $slug,
					'post_content' => $cfg['content'],
				)
			);
		}

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			continue;
		}

		if ( ! empty( $cfg['template'] ) ) {
			update_post_meta( $post_id, '_wp_page_template', $cfg['template'] );
		}

		$ids[ $slug ] = (int) $post_id;
	}

	if ( ! empty( $ids['home'] ) ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $ids['home'] );
	}

	update_option( 'vila_akoya_seed_version', VILA_AKOYA_SEED_VERSION );
	update_option( 'vila_akoya_pages_seeded', true );
}
add_action( 'after_switch_theme', 'vila_akoya_maybe_seed_pages' );
add_action( 'init', 'vila_akoya_maybe_seed_pages', 5 );

/**
 * Home — Stitch portal + pillars + newsletter band.
 *
 * @return string
 */
function vila_akoya_home_content() {
	return trim(
		'<!-- wp:pattern {"slug":"vila-akoya/hero-portal"} /-->

<!-- wp:pattern {"slug":"vila-akoya/split-village-campsite"} /-->

<!-- wp:pattern {"slug":"vila-akoya/home-deeply-rooted"} /-->

<!-- wp:pattern {"slug":"vila-akoya/home-pillars-four"} /-->

<!-- wp:pattern {"slug":"vila-akoya/home-newsletter-orbit"} /-->'
	);
}

/**
 * Village brochure — Stitch “The Village” page.
 *
 * @return string
 */
function vila_akoya_brochure_village_content() {
	return trim(
		'<!-- wp:pattern {"slug":"vila-akoya/village-hero"} /-->

<!-- wp:pattern {"slug":"vila-akoya/village-ethos"} /-->

<!-- wp:pattern {"slug":"vila-akoya/village-three-paths"} /-->

<!-- wp:pattern {"slug":"vila-akoya/village-landscape"} /-->

<!-- wp:pattern {"slug":"vila-akoya/village-waitlist"} /-->

<!-- wp:pattern {"slug":"vila-akoya/contact-strip"} /-->'
	);
}

/**
 * Campsite brochure — Stitch campsite page.
 *
 * @return string
 */
function vila_akoya_brochure_campsite_content() {
	return trim(
		'<!-- wp:pattern {"slug":"vila-akoya/campsite-hero"} /-->

<!-- wp:pattern {"slug":"vila-akoya/campsite-campus-hub"} /-->

<!-- wp:pattern {"slug":"vila-akoya/campsite-workshops"} /-->

<!-- wp:pattern {"slug":"vila-akoya/campsite-rhythm"} /-->

<!-- wp:pattern {"slug":"vila-akoya/campsite-testimonials"} /-->

<!-- wp:pattern {"slug":"vila-akoya/campsite-final-cta"} /-->

<!-- wp:pattern {"slug":"vila-akoya/contact-strip"} /-->'
	);
}

/**
 * Booking page — CF7 shortcode.
 *
 * @return string
 */
function vila_akoya_book_content() {
	return trim(
		'<!-- wp:paragraph {"style":{"typography":{"fontSize":"var:preset|font-size|medium"}}} -->
<p style="font-size:var(--wp--preset--font-size--medium)">Use the form below — or reach us on WhatsApp if you prefer a voice note.</p>
<!-- /wp:paragraph -->

<!-- wp:shortcode -->
[vila_akoya_booking_form]
<!-- /wp:shortcode -->'
	);
}

/**
 * About — personable intro + editorial image pattern.
 *
 * @return string
 */
function vila_akoya_about_content() {
	return trim(
		'<!-- wp:pattern {"slug":"vila-akoya/about-story"} /-->

<!-- wp:pattern {"slug":"vila-akoya/editorial-text-image"} /-->

<!-- wp:pattern {"slug":"vila-akoya/contact-strip"} /-->'
	);
}

/**
 * Contact.
 *
 * @return string
 */
function vila_akoya_contact_content() {
	return trim(
		'<!-- wp:pattern {"slug":"vila-akoya/contact-strip"} /-->

<!-- wp:paragraph {"style":{"typography":{"fontSize":"var:preset|font-size|medium","lineHeight":"1.75"}}} -->
<p style="font-size:var(--wp--preset--font-size--medium);line-height:1.75">We read every message. For faster replies on the day, WhatsApp is usually best.</p>
<!-- /wp:paragraph -->'
	);
}

/**
 * Homeschooling collective — Forest School designs.
 *
 * @return string
 */
function vila_akoya_homeschooling_content() {
	return trim(
		'<!-- wp:pattern {"slug":"vila-akoya/forest-school-hero"} /-->

<!-- wp:pattern {"slug":"vila-akoya/homeschool-collective"} /-->

<!-- wp:pattern {"slug":"vila-akoya/homeschool-extracurriculars"} /-->

<!-- wp:pattern {"slug":"vila-akoya/cta-band"} /-->

<!-- wp:pattern {"slug":"vila-akoya/contact-strip"} /-->'
	);
}

/**
 * Campus page.
 *
 * @return string
 */
function vila_akoya_campus_content() {
	return trim(
		'<!-- wp:pattern {"slug":"vila-akoya/campus-overview"} /-->

<!-- wp:pattern {"slug":"vila-akoya/campsite-workshops"} /-->

<!-- wp:pattern {"slug":"vila-akoya/contact-strip"} /-->'
	);
}
