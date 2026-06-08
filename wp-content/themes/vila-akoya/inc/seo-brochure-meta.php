<?php
/**
 * Brochure-aligned SEO meta for The SEO Framework (TSF).
 *
 * Mirrors brochure-website/*.html SEO into TSF post meta: title, description, canonical,
 * social image, explicit Open Graph / Twitter fields, and Twitter card type.
 *
 * Install TSF from Plugins → Add New → "The SEO Framework" (WordPress.org slug: autodescription).
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bump when brochure copy or mapping changes so admin sync re-applies meta.
 */
define( 'VILA_AKOYA_SEO_BROCHURE_META_VER', 2 );

/**
 * Brochure SEO strings keyed by page slug (same as inc/seed.php).
 *
 * @return array<string, array{title:string,description:string,image_file:string}>
 */
function vila_akoya_brochure_seo_config() {
	return array(
		'home'      => array(
			'title'       => 'Vila Akoya | Creative Eco-Camping and Tiny Home Village in Portugal',
			'description' => 'Vila Akoya is an inspiring place of inspiration, creation and relaxation. A playground for kids and for grown-ups, bordering the river Mira in Alentejo.',
			'image_file'  => 'home-hero.jpg',
		),
		'about'     => array(
			'title'       => 'Vila Akoya | Our Story, Place and Community in Alentejo',
			'description' => 'Vila Akoya is the little village of our dreams. Discover our story, our place by the river Mira in Alentejo, and the spaces we are creating for kids, grown-ups and the natural world around us.',
			'image_file'  => 'about-hero.jpg',
		),
		'village'   => array(
			'title'       => 'Vila Akoya | Tiny Home Village in Alentejo, Portugal',
			'description' => 'Vila Akoya is a tiny home village of out-of-the-ordinary living spaces. Discover short stays, 5-year lease spots and opportunities to put down roots in Alentejo.',
			'image_file'  => 'village-large.jpg',
		),
		'campsite'  => array(
			'title'       => 'Vila Akoya | Creative Eco-Campsite in Alentejo, Portugal',
			'description' => 'Vila Akoya is a creative campsite for relaxation, connection and adventure. Bring your tent or home-on-wheels and gather by the river Mira in Alentejo.',
			'image_file'  => 'campsite-hero.jpg',
		),
		'booking'   => array(
			'title'       => 'Vila Akoya | Book Your Stay in Our Village or Campsite',
			'description' => 'Vila Akoya is a place to stay for a night, a month or longer. Tell us how you\'d like to join our village or campsite in Alentejo, and we will be in touch.',
			'image_file'  => 'booking-hero.jpg',
		),
		'campus'    => array(
			'title'       => 'Vila Akoya | Creative Campus for Learning and Workshops',
			'description' => 'Vila Akoya is a creative campus for learning, sharing and playful discovery. Join classes, workshops, retreats and activities for villagers, guests and visitors in Alentejo.',
			'image_file'  => 'campus-hero.jpg',
		),
		'the-kids'  => array(
			'title'       => 'Vila Akoya | A Village for Kids to Learn and Grow',
			'description' => 'Vila Akoya is a playful place for children to learn, grow and explore. Discover our homeschooling collective, creative classes and outdoor adventures in nature.',
			'image_file'  => 'kids-hero.jpg',
		),
		'contact'   => array(
			'title'       => 'Vila Akoya | Get in Touch With Our Family',
			'description' => 'Vila Akoya is a place built on connection, and we would love to hear from you. Reach out by email, WhatsApp or phone for questions, ideas and bookings.',
			'image_file'  => 'contact-hero.jpg',
		),
		'thank-you' => array(
			'title'       => 'Vila Akoya | Thank You for Getting in Touch',
			'description' => 'Vila Akoya has received your message and we will be in touch soon.',
			'image_file'  => 'home-hero.jpg',
		),
		'blog'      => array(
			'title'       => 'Vila Akoya | Stories from the Village',
			'description' => 'News, updates and reflections from life at Vila Akoya — the campsite, the campus, the kids and everything in between.',
			'image_file'  => 'home-hero.jpg',
		),
	);
}

/**
 * Absolute URL for a brochure hero image: local attachment if found, else production CDN (matches seeded content fallbacks).
 *
 * @param string $basename Image filename (e.g. home-hero.jpg).
 * @return string
 */
function vila_akoya_brochure_seo_social_image_url( $basename ) {
	$basename = is_string( $basename ) ? basename( $basename ) : '';
	if ( '' === $basename ) {
		return '';
	}

	global $wpdb;
	static $map = null;

	if ( null === $map ) {
		$map = array();
		$rows = $wpdb->get_results(
			"SELECT p.ID, pm.meta_value AS attached_file
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm
			   ON pm.post_id = p.ID
			  AND pm.meta_key = '_wp_attached_file'
			 WHERE p.post_type = 'attachment'"
		);
		foreach ( (array) $rows as $row ) {
			$attached = isset( $row->attached_file ) ? (string) $row->attached_file : '';
			$name     = basename( $attached );
			if ( '' !== $name ) {
				$url = wp_get_attachment_url( (int) $row->ID );
				if ( $url ) {
					$map[ $name ] = (string) $url;
				}
			}
		}
	}

	if ( isset( $map[ $basename ] ) ) {
		return $map[ $basename ];
	}

	$base = pathinfo( $basename, PATHINFO_FILENAME );
	$ext  = pathinfo( $basename, PATHINFO_EXTENSION );
	if ( '' !== $base && '' !== $ext ) {
		$slug = preg_quote( $base, '#' );
		$extq = preg_quote( $ext, '#' );
		foreach ( $map as $fname => $url ) {
			if ( preg_match( '#^' . $slug . '(?:-[0-9]+)?(?:-scaled)?\\.' . $extq . '$#i', $fname ) ) {
				return $url;
			}
		}
	}

	return 'https://vila-akoya.com/images/' . $basename;
}

/**
 * Write TSF-compatible post meta for one page.
 *
 * @param int    $post_id Post ID.
 * @param string $slug    Page slug.
 * @return void
 */
function vila_akoya_apply_brochure_seo_meta_to_post( $post_id, $slug ) {
	$post_id = (int) $post_id;
	$slug    = is_string( $slug ) ? $slug : '';
	if ( $post_id <= 0 || '' === $slug ) {
		return;
	}

	$config = vila_akoya_brochure_seo_config();
	if ( ! isset( $config[ $slug ] ) ) {
		return;
	}

	$row     = $config[ $slug ];
	$title   = $row['title'];
	$desc    = $row['description'];
	$img_url = vila_akoya_brochure_seo_social_image_url( $row['image_file'] );

	$permalink = get_permalink( $post_id );
	$canonical = is_string( $permalink ) ? $permalink : '';

	update_post_meta( $post_id, '_genesis_title', $title );
	update_post_meta( $post_id, '_genesis_description', $desc );
	update_post_meta( $post_id, '_tsf_title_no_blogname', '1' );

	if ( '' !== $canonical ) {
		update_post_meta( $post_id, '_genesis_canonical_uri', $canonical );
	}

	if ( '' !== $img_url ) {
		update_post_meta( $post_id, '_social_image_url', esc_url_raw( $img_url ) );
	}

	// Brochure sets og:* / twitter:* explicitly to the same strings; TSF uses these when present.
	update_post_meta( $post_id, '_open_graph_title', $title );
	update_post_meta( $post_id, '_open_graph_description', $desc );
	update_post_meta( $post_id, '_twitter_title', $title );
	update_post_meta( $post_id, '_twitter_description', $desc );
	update_post_meta( $post_id, '_tsf_twitter_card_type', 'summary_large_image' );
}

/**
 * Apply brochure SEO meta to seeded pages (by slug → ID map).
 *
 * @param array<string, int> $page_ids Slug => post ID.
 * @return void
 */
function vila_akoya_seed_apply_brochure_seo_meta( array $page_ids ) {
	foreach ( $page_ids as $slug => $pid ) {
		vila_akoya_apply_brochure_seo_meta_to_post( (int) $pid, (string) $slug );
	}
}

/**
 * Re-apply for all configured slugs (upgrade path).
 *
 * @return void
 */
function vila_akoya_sync_brochure_seo_meta_for_all_pages() {
	foreach ( array_keys( vila_akoya_brochure_seo_config() ) as $slug ) {
		$page = get_page_by_path( (string) $slug, OBJECT, 'page' );
		if ( $page && isset( $page->ID ) ) {
			vila_akoya_apply_brochure_seo_meta_to_post( (int) $page->ID, (string) $slug );
		}
	}
}

/**
 * When the theme bumps VILA_AKOYA_SEO_BROCHURE_META_VER, refresh meta once (TSF reads these keys when active).
 *
 * @return void
 */
function vila_akoya_maybe_sync_brochure_seo_meta_ver() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$current = (int) get_option( 'vila_akoya_seo_brochure_meta_ver', 0 );
	if ( $current >= VILA_AKOYA_SEO_BROCHURE_META_VER ) {
		return;
	}
	vila_akoya_sync_brochure_seo_meta_for_all_pages();
	update_option( 'vila_akoya_seo_brochure_meta_ver', VILA_AKOYA_SEO_BROCHURE_META_VER );
}
add_action( 'admin_init', 'vila_akoya_maybe_sync_brochure_seo_meta_ver', 8 );
