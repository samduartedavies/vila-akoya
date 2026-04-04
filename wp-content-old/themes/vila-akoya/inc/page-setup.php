<?php
/**
 * Page seeding for V1 IA + design content (bump VILA_AKOYA_SEED_VERSION to refresh default copy).
 *
 * @package vila-akoya
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VILA_AKOYA_SEED_VERSION', 11 );

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
		'thank-you'                => array(
			'title'   => 'Thank you',
			'content' => vila_akoya_thank_you_content(),
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
		<<<'HTML'
<!-- wp:group {"align":"wide","className":"va-book-hero","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide va-book-hero" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80)">
	<!-- wp:columns {"verticalAlignment":"center","style":{"spacing":{"blockGap":{"left":"var:preset|spacing|80"}}}} -->
	<div class="wp-block-columns are-vertically-aligned-center">
		<!-- wp:column {"verticalAlignment":"center","width":"58%"} -->
		<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:58%">
			<!-- wp:paragraph {"style":{"typography":{"fontFamily":"var:preset|font-family|plus-jakarta","fontSize":"var:preset|font-size|small","fontWeight":"600","letterSpacing":"0.14em","textTransform":"uppercase"}},"textColor":"primary"} -->
			<p class="has-primary-color has-text-color" style="font-family:var(--wp--preset--font-family--plus-jakarta);font-size:var(--wp--preset--font-size--small);font-weight:600;letter-spacing:0.14em;text-transform:uppercase">Begin your journey</p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"clamp(2.5rem,6vw,4.6rem)","lineHeight":"1.05"}}} -->
			<h1 class="wp-block-heading" style="font-size:clamp(2.5rem,6vw,4.6rem);line-height:1.05">An invitation to <em>slow living</em></h1>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"style":{"typography":{"fontSize":"var:preset|font-size|large","lineHeight":"1.75"}},"textColor":"on-surface-variant"} -->
			<p class="has-on-surface-variant-color has-text-color" style="font-size:var(--wp--preset--font-size--large);line-height:1.75">Tell us whether you're looking for a Village plot, Campsite stay, or both. We'll reply with the next steps, availability, and a tailored plan.</p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column {"width":"42%"} -->
		<div class="wp-block-column" style="flex-basis:42%">
			<!-- wp:image {"sizeSlug":"large","style":{"border":{"radius":"5rem"}},"className":"va-editorial-shadow"} -->
			<figure class="wp-block-image size-large va-editorial-shadow" style="border-radius:5rem"><img src="https://lh3.googleusercontent.com/aida-public/AB6AXuB0YO7RwzDaUBYXKWatnP-c3s1YNuEUYn9nNLqgcJUySFbisQ2mXzUAT24CCyVBwx9DoUq4rSTBoXh_rs86cenlRIxZ4kH-iF9V7HFXY3Z5UU19DlS_5Dhi1KZ1wrTKDiZZnwPWQ0fGDkZOAycv95LFi8ZMTDFQhulSQw5Vy291KYk80nSgOQ_pFPH_dUh9OShoPFYq53tA6pQpmpbrK0Mb_BLqqSGrPC_IxJTfNW6AwVOeSJlSWwna2x4Cgyc0QNxyPvVBWTB4Dy3v" alt="Sunlit Alentejo cork tree landscape"/></figure>
			<!-- /wp:image -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"left":"var:preset|spacing|80"}}}} -->
<div class="wp-block-columns alignwide">
	<!-- wp:column {"width":"42%"} -->
	<div class="wp-block-column" style="flex-basis:42%">
		<!-- wp:group {"className":"va-book-info","style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70","left":"var:preset|spacing|70","right":"var:preset|spacing|70"}},"color":{"background":"var:preset|color|surface-container-low"},"border":{"radius":"1rem"}}} -->
		<div class="wp-block-group va-book-info has-surface-container-low-background-color has-background" style="border-radius:5rem;padding-top:var(--wp--preset--spacing--70);padding-right:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70);padding-left:var(--wp--preset--spacing--70)">
			<!-- wp:heading {"level":3,"style":{"typography":{"fontSize":"var:preset|font-size|x-large"}}} -->
			<h3 class="wp-block-heading" style="font-size:var(--wp--preset--font-size--x-large)">Inquiry details</h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"on-surface-variant"} -->
			<p class="has-on-surface-variant-color has-text-color">Village plots are best for long stays. Campsite stays are ideal for short soulful retreats. Select what fits, and we will map options for you.</p>
			<!-- /wp:paragraph -->
			<!-- wp:paragraph {"style":{"typography":{"fontFamily":"var:preset|font-family|plus-jakarta","fontSize":"var:preset|font-size|small","fontWeight":"600"}},"textColor":"secondary"} -->
			<p class="has-secondary-color has-text-color" style="font-family:var(--wp--preset--font-family--plus-jakarta);font-size:var(--wp--preset--font-size--small);font-weight:600">WhatsApp: <a href="https://wa.me/351960446285">+351 960 446 285</a></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:column -->
	<!-- wp:column {"width":"58%"} -->
	<div class="wp-block-column" style="flex-basis:58%">
		<!-- wp:shortcode -->
[vila_akoya_booking_form]
		<!-- /wp:shortcode -->
	</div>
	<!-- /wp:column -->
</div>
<!-- /wp:columns -->
HTML
	);
}

/**
 * About — personable intro + editorial image pattern.
 *
 * @return string
 */
function vila_akoya_about_content() {
	return trim(
		<<<'HTML'
<!-- wp:group {"align":"wide","className":"va-about-hero","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide va-about-hero" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80)">
	<!-- wp:columns {"verticalAlignment":"center","style":{"spacing":{"blockGap":{"left":"var:preset|spacing|80"}}}} -->
	<div class="wp-block-columns are-vertically-aligned-center">
		<!-- wp:column {"verticalAlignment":"center","width":"58%"} -->
		<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:58%">
			<!-- wp:paragraph {"style":{"typography":{"fontFamily":"var:preset|font-family|plus-jakarta","fontSize":"var:preset|font-size|small","fontWeight":"700","letterSpacing":"0.14em","textTransform":"uppercase"}},"textColor":"primary"} -->
			<p class="has-primary-color has-text-color" style="font-family:var(--wp--preset--font-family--plus-jakarta);font-size:var(--wp--preset--font-size--small);font-weight:700;letter-spacing:0.14em;text-transform:uppercase">Our story</p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"clamp(2.4rem,5.5vw,4.7rem)","lineHeight":"1.1"}}} -->
			<h1 class="wp-block-heading" style="font-size:clamp(2.4rem,5.5vw,4.7rem);line-height:1.1">Rooted in <em>passion</em>, built for the future.</h1>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"style":{"typography":{"fontSize":"var:preset|font-size|large","lineHeight":"1.75"}},"textColor":"on-surface-variant"} -->
			<p class="has-on-surface-variant-color has-text-color" style="font-size:var(--wp--preset--font-size--large);line-height:1.75">Vila Akoya is a creative ecosystem in Alentejo where children can roam free and adults can rediscover shared life. From Campus workshops to Forest School, each space is shaped for community.</p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column {"width":"42%"} -->
		<div class="wp-block-column" style="flex-basis:42%">
			<!-- wp:image {"sizeSlug":"large","style":{"border":{"radius":"5rem"}},"className":"va-editorial-shadow"} -->
			<figure class="wp-block-image size-large va-editorial-shadow" style="border-radius:5rem"><img src="https://lh3.googleusercontent.com/aida-public/AB6AXuBlUgYMyX1OvCEoPwQQlJnZdEI1RO7hI9ie7ZzlV9-LKqly81fdreXJoFvr3Yf-Ix_S_0K4GD9e23_kZYulsXoCw5_BxE1kQ0jhGFA_p-WlKNUvGnqk0rjvdZb3xCXocPGI0rZF_wPaiP6jAiaoSN_EAzmEO5_ngBRUMc4oV696A8-Vp3UVi08UaBtgUuwi4KOaWOBzNg3R5z3Hw_H4vXmBPypkHShmsexIWwQQWvViplLkHZLihjnVx0vb9Rqzb1v6IgBD9Lhs5jgR" alt="Vila Akoya founders in Alentejo"/></figure>
			<!-- /wp:image -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:pattern {"slug":"vila-akoya/about-story"} /-->
<!-- wp:pattern {"slug":"vila-akoya/editorial-text-image"} /-->
<!-- wp:pattern {"slug":"vila-akoya/contact-strip"} /-->
HTML
	);
}

/**
 * Contact.
 *
 * @return string
 */
function vila_akoya_contact_content() {
	return trim(
		<<<'HTML'
<!-- wp:group {"align":"wide","className":"va-contact-hero","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide va-contact-hero" style="padding-top:var(--wp--preset--spacing--80);padding-bottom:var(--wp--preset--spacing--80)">
	<!-- wp:columns {"verticalAlignment":"center","style":{"spacing":{"blockGap":{"left":"var:preset|spacing|80"}}}} -->
	<div class="wp-block-columns are-vertically-aligned-center">
		<!-- wp:column {"verticalAlignment":"center","width":"52%"} -->
		<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:52%">
			<!-- wp:paragraph {"style":{"typography":{"fontFamily":"var:preset|font-family|plus-jakarta","fontSize":"var:preset|font-size|small","fontWeight":"700","letterSpacing":"0.14em","textTransform":"uppercase"}},"textColor":"primary"} -->
			<p class="has-primary-color has-text-color" style="font-family:var(--wp--preset--font-family--plus-jakarta);font-size:var(--wp--preset--font-size--small);font-weight:700;letter-spacing:0.14em;text-transform:uppercase">Get in touch</p>
			<!-- /wp:paragraph -->
			<!-- wp:heading {"level":1,"style":{"typography":{"fontSize":"clamp(2.4rem,5.5vw,4.7rem)","lineHeight":"1.08"}}} -->
			<h1 class="wp-block-heading" style="font-size:clamp(2.4rem,5.5vw,4.7rem);line-height:1.08">Let's start <em>your journey.</em></h1>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"style":{"typography":{"fontSize":"var:preset|font-size|large","lineHeight":"1.75"}},"textColor":"on-surface-variant"} -->
			<p class="has-on-surface-variant-color has-text-color" style="font-size:var(--wp--preset--font-size--large);line-height:1.75">We read every message. For same-day replies, WhatsApp is usually fastest. If you are unsure where to begin, send a short note and we will guide you.</p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column {"width":"48%"} -->
		<div class="wp-block-column" style="flex-basis:48%">
			<!-- wp:image {"sizeSlug":"large","style":{"border":{"radius":"5rem"}},"className":"va-editorial-shadow"} -->
			<figure class="wp-block-image size-large va-editorial-shadow" style="border-radius:5rem"><img src="https://lh3.googleusercontent.com/aida-public/AB6AXuCZfnr1xRx1RVyv9IeVK7WUmBGmSgMG4Z_4p7ZhF-_LqO5bgvPMyCEJNodKlsea8Mo046UQAKcoYl2GyOePjAYQXuAqACbw1QLLaT0tYWCIbLHf_XJaDzh3FN4vHPuvImT2b6RVdNmaQNDImYTbj71iTcQcOw50oxUD31JzqpSzLhNRe_AMam4Uz4e5EHp8HY70jF-pZW48MVbaiUzhsQ4O9G79o4_44hYzKkYHGaTtxcr-i68Ump17z6KNWvgQ5oeSkIj_vbO-8VRQ" alt="Alentejo landscape at golden hour"/></figure>
			<!-- /wp:image -->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:columns {"align":"wide","style":{"spacing":{"blockGap":{"left":"var:preset|spacing|70"}}}} -->
<div class="wp-block-columns alignwide">
	<!-- wp:column -->
	<div class="wp-block-column">
		<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70","left":"var:preset|spacing|70","right":"var:preset|spacing|70"}},"color":{"background":"var:preset|color|surface-container-low"},"border":{"radius":"1rem"}},"className":"va-contact-card"} -->
		<div class="wp-block-group va-contact-card has-surface-container-low-background-color has-background" style="border-radius:5rem;padding-top:var(--wp--preset--spacing--70);padding-right:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70);padding-left:var(--wp--preset--spacing--70)">
			<!-- wp:heading {"level":3,"style":{"typography":{"fontSize":"var:preset|font-size|x-large"}}} -->
			<h3 class="wp-block-heading" style="font-size:var(--wp--preset--font-size--x-large)">Email</h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"on-surface-variant"} -->
			<p class="has-on-surface-variant-color has-text-color">For general inquiries and booking details, reach us anytime.</p>
			<!-- /wp:paragraph -->
			<!-- wp:paragraph {"style":{"typography":{"fontSize":"var:preset|font-size|large","fontWeight":"600"}}} -->
			<p style="font-size:var(--wp--preset--font-size--large);font-weight:600"><a href="mailto:Info@vila-akoya.com">Info@vila-akoya.com</a></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:column -->
	<!-- wp:column -->
	<div class="wp-block-column">
		<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70","left":"var:preset|spacing|70","right":"var:preset|spacing|70"}},"color":{"background":"var:preset|color|secondary"},"border":{"radius":"1rem"}},"textColor":"on-secondary","className":"va-contact-card"} -->
		<div class="wp-block-group va-contact-card has-on-secondary-color has-secondary-background-color has-text-color has-background" style="border-radius:5rem;padding-top:var(--wp--preset--spacing--70);padding-right:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70);padding-left:var(--wp--preset--spacing--70)">
			<!-- wp:heading {"level":3,"style":{"typography":{"fontSize":"var:preset|font-size|x-large"}}} -->
			<h3 class="wp-block-heading" style="font-size:var(--wp--preset--font-size--x-large)">WhatsApp</h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph -->
			<p>Quick questions or immediate assistance while traveling.</p>
			<!-- /wp:paragraph -->
			<!-- wp:paragraph {"style":{"typography":{"fontSize":"var:preset|font-size|large","fontWeight":"600"}}} -->
			<p style="font-size:var(--wp--preset--font-size--large);font-weight:600"><a href="https://wa.me/351960446285">+351 960 446 285</a></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:column -->
	<!-- wp:column -->
	<div class="wp-block-column">
		<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|70","bottom":"var:preset|spacing|70","left":"var:preset|spacing|70","right":"var:preset|spacing|70"}},"color":{"background":"var:preset|color|surface-container-high"},"border":{"radius":"1rem"}},"className":"va-contact-card"} -->
		<div class="wp-block-group va-contact-card has-surface-container-high-background-color has-background" style="border-radius:5rem;padding-top:var(--wp--preset--spacing--70);padding-right:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70);padding-left:var(--wp--preset--spacing--70)">
			<!-- wp:heading {"level":3,"style":{"typography":{"fontSize":"var:preset|font-size|x-large"}}} -->
			<h3 class="wp-block-heading" style="font-size:var(--wp--preset--font-size--x-large)">Phone</h3>
			<!-- /wp:heading -->
			<!-- wp:paragraph {"textColor":"on-surface-variant"} -->
			<p class="has-on-surface-variant-color has-text-color">Speak directly with our team for personalized tours and visits.</p>
			<!-- /wp:paragraph -->
			<!-- wp:paragraph {"style":{"typography":{"fontSize":"var:preset|font-size|large","fontWeight":"600"}}} -->
			<p style="font-size:var(--wp--preset--font-size--large);font-weight:600"><a href="tel:+351960446285">+351 960 446 285</a></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	</div>
	<!-- /wp:column -->
</div>
<!-- /wp:columns -->

<!-- wp:group {"align":"wide","style":{"spacing":{"padding":{"top":"var:preset|spacing|80","bottom":"var:preset|spacing|80","left":"var:preset|spacing|70","right":"var:preset|spacing|70"}},"color":{"background":"var:preset|color|surface-container-low"},"border":{"radius":"1rem"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide has-surface-container-low-background-color has-background" style="border-radius:5rem;padding-top:var(--wp--preset--spacing--80);padding-right:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--80);padding-left:var(--wp--preset--spacing--70)">
	<!-- wp:heading {"level":2,"style":{"typography":{"fontSize":"clamp(2rem,4.2vw,3.3rem)","fontStyle":"italic"}}} -->
	<h2 class="wp-block-heading" style="font-size:clamp(2rem,4.2vw,3.3rem);font-style:italic">A place built for family.</h2>
	<!-- /wp:heading -->
	<!-- wp:paragraph {"textColor":"on-surface-variant","style":{"typography":{"fontSize":"var:preset|font-size|large","lineHeight":"1.75"}}} -->
	<p class="has-on-surface-variant-color has-text-color" style="font-size:var(--wp--preset--font-size--large);line-height:1.75">Whether you're interested in Forest School, Campsite stays, or the Village, we are here to help you find your place in the Akoya ecosystem.</p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
HTML
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

<!-- wp:pattern {"slug":"vila-akoya/forest-wisdom"} /-->

<!-- wp:pattern {"slug":"vila-akoya/forest-final-cta"} /-->

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

<!-- wp:pattern {"slug":"vila-akoya/campus-nurture"} /-->

<!-- wp:pattern {"slug":"vila-akoya/campus-upcoming"} /-->

<!-- wp:pattern {"slug":"vila-akoya/campus-earn-stay"} /-->

<!-- wp:pattern {"slug":"vila-akoya/campus-host-form"} /-->

<!-- wp:pattern {"slug":"vila-akoya/contact-strip"} /-->'
	);
}

/**
 * Thank you — FormSubmit redirect target (brochure thank-you.html).
 *
 * @return string
 */
function vila_akoya_thank_you_content() {
	return trim(
		<<<'HTML'
<!-- wp:group {"align":"wide","className":"va-thank-you","style":{"spacing":{"padding":{"top":"var:preset|spacing|90","bottom":"var:preset|spacing|90"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide va-thank-you" style="padding-top:var(--wp--preset--spacing--90);padding-bottom:var(--wp--preset--spacing--90)">
	<!-- wp:group {"layout":{"type":"constrained"},"style":{"spacing":{"blockGap":"var:preset|spacing|70"}}} -->
	<div class="wp-block-group">
		<!-- wp:paragraph {"align":"center"} -->
		<p class="has-text-align-center"><span class="material-symbols-outlined va-thank-you__icon" aria-hidden="true">mark_email_read</span></p>
		<!-- /wp:paragraph -->
		<!-- wp:paragraph {"align":"center","style":{"typography":{"fontFamily":"var:preset|font-family|plus-jakarta","fontSize":"var:preset|font-size|small","fontWeight":"600","letterSpacing":"0.2em","textTransform":"uppercase"}},"textColor":"primary"} -->
		<p class="has-text-align-center has-primary-color has-text-color" style="font-family:var(--wp--preset--font-family--plus-jakarta);font-size:var(--wp--preset--font-size--small);font-weight:600;letter-spacing:0.2em;text-transform:uppercase">Thank you</p>
		<!-- /wp:paragraph -->
		<!-- wp:heading {"textAlign":"center","level":1,"style":{"typography":{"fontFamily":"var:preset|font-family|barrio","fontSize":"clamp(2rem,4vw,3rem)","lineHeight":"1.15"}}} -->
		<h1 class="wp-block-heading has-text-align-center" style="font-family:var(--wp--preset--font-family--barrio);font-size:clamp(2rem,4vw,3rem);line-height:1.15">We received your <em>inquiry</em></h1>
		<!-- /wp:heading -->
		<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"var:preset|font-size|large","lineHeight":"1.65"}},"textColor":"on-surface-variant"} -->
		<p class="has-text-align-center has-on-surface-variant-color has-text-color" style="font-size:var(--wp--preset--font-size--large);line-height:1.65">We will get back to you soon. If you need anything urgent, message us on WhatsApp or email <a href="mailto:Info@vila-akoya.com">Info@vila-akoya.com</a>.</p>
		<!-- /wp:paragraph -->
		<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"},"style":{"spacing":{"blockGap":"var:preset|spacing|60"}}} -->
		<div class="wp-block-buttons">
			<!-- wp:button {"className":"is-style-fill"} -->
			<div class="wp-block-button is-style-fill"><a class="wp-block-button__link wp-element-button" href="/">Back to home</a></div>
			<!-- /wp:button -->
			<!-- wp:button {"className":"is-style-outline"} -->
			<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="/book/">Send another message</a></div>
			<!-- /wp:button -->
		</div>
		<!-- /wp:buttons -->
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
HTML
	);
}
