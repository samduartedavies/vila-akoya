<?php
/**
 * Seed data for Vila Section blocks on brochure pages.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * @return string[]
 */
function vila_akoya_page_section_slugs() {
	return array( 'home', 'about', 'village', 'campsite', 'campus', 'the-kids', 'contact', 'thank-you', 'blog', 'booking' );
}

/**
 * @param string $slug Page slug.
 * @return array<int, array<string, mixed>>|WP_Error
 */
function vila_akoya_page_section_rows( $slug ) {
	$slug = sanitize_title( $slug );

	if ( 'home' === $slug ) {
		return vila_akoya_home_section_rows();
	}

	$map = array(
		'about'     => 'vila_akoya_about_section_rows',
		'village'   => 'vila_akoya_village_section_rows',
		'campsite'  => 'vila_akoya_campsite_section_rows',
		'campus'    => 'vila_akoya_campus_section_rows',
		'the-kids'  => 'vila_akoya_the_kids_section_rows',
		'contact'   => 'vila_akoya_contact_section_rows',
		'thank-you' => 'vila_akoya_thank_you_section_rows',
		'blog'      => 'vila_akoya_blog_section_rows',
		'booking'   => 'vila_akoya_booking_section_rows',
	);

	if ( empty( $map[ $slug ] ) || ! function_exists( $map[ $slug ] ) ) {
		return new WP_Error( 'no_seed', sprintf( 'No section seed data for “%s”.', $slug ) );
	}

	$rows = call_user_func( $map[ $slug ] );
	return is_array( $rows ) ? $rows : new WP_Error( 'bad_seed', 'Invalid section seed data.' );
}

/**
 * @param string $key CF7 logical key from seed.php.
 * @return string
 */
function vila_akoya_section_cf7_shortcode( $key ) {
	$cf7 = function_exists( 'vila_akoya_get_cf7_form_ids' ) ? vila_akoya_get_cf7_form_ids() : array();
	$id  = isset( $cf7[ $key ] ) ? (int) $cf7[ $key ] : 0;
	if ( $id <= 0 ) {
		return '';
	}

	$classes = array(
		'newsletter_home'    => 'vila-cf7 vila-cf7--newsletter',
		'newsletter_village' => 'vila-cf7 vila-cf7--newsletter',
		'village_inquiry'    => 'vila-cf7 vila-cf7--booking',
		'campus'             => 'vila-cf7 vila-cf7--campus',
		'contact'            => 'vila-cf7 vila-cf7--contact',
		'event_inquiry'      => 'vila-cf7 vila-cf7--event',
		'stay_inquiry'       => 'vila-cf7 vila-cf7--stay',
	);

	$class = $classes[ $key ] ?? 'vila-cf7';
	return sprintf( '[contact-form-7 id="%d" html_class="%s"]', $id, $class );
}

/**
 * @return array<int, array<string, mixed>>
 */
function vila_akoya_about_section_rows() {
	return array(
		array(
			'acf_fc_layout' => 'page_hero',
			'layout'        => 'default',
			'eyebrow'       => 'About',
			'heading'       => 'The little village of our dreams',
			'body'          => 'With fantastic spaces for our kids to play, learn and grow, for our hooved, furred and winged friends to graze, hop and skip, and for our grown-ups to relax, learn, eat, work and create together.',
			'image'         => 'https://vila-akoya.com/images/about-hero.jpg',
			'badge_text'    => 'Bordering the beautiful river Mira',
			'heading_style' => 'headline',
		),
		array(
			'acf_fc_layout' => 'text_image',
			'layout'        => 'band',
			'image'         => 'https://vila-akoya.com/images/about-what-is.jpg',
			'heading'       => 'What is Vila Akoya?',
			'body'          => '<p>Think of a wholesome festival meets artistic campsite meets permaculture farm meets eco-village sort of space. Where we spend our days in the sun, surrounded by nature, art, great people, good food and clean water.</p><p>A place where our kids get to grow up in a healthy place, have their own homeschooling hub, and get to spend their days outdoor busy with lots of extracurricular activities, adventure and play.</p><p>A place where we, the adults, get to spend time with our kids and our friends, and where we get to learn lots, grow foods, take care of the forest and of the animals, of ourselves, and each other and create one of the most fantastic and unique eco-campsites Portugal has ever seen.</p>',
		),
		array(
			'acf_fc_layout' => 'masonry_spotlight',
			'heading'       => 'Where is Vila Akoya?',
			'body'          => '<p>On a great central location along the IC1 - the scenic toll-free road connecting Lisbon to the Algarve - that\'s where you find us. Right on the border of Alentejo and the Algarve.</p><p>When you\'re here it feels like you\'re far way from civilisation, but in reality, we\'re only minutes away from the town of Ourique.</p><p>We fell in love as soon as we stepped foot on this little slice of paradise; the rolling hills, beautiful nature, cute little wooden structures, olive groves, cozy nooks and vast depths... and all of this bordering a river which, even at the end of the dry season, still was full of water. We feel really lucky to have found this place.</p>',
			'image'         => 'https://vila-akoya.com/images/about-where-is.jpg',
			'card_1_icon'   => 'park',
			'card_1_title'  => 'Surrounded by sees of green',
			'card_2_icon'   => 'explore',
			'card_2_title'  => 'Only 10 minutes from Ourique',
		),
		array(
			'acf_fc_layout'  => 'cta_band',
			'layout'         => 'about',
			'icon'           => 'favorite',
			'heading'        => 'Beautifully Different People',
			'body'           => 'We believe the best group of people is a diverse group of people. At Vila Akoya we\'re excited to share our home and our table with wonderful humans of different backgrounds, ideas, skills and ages, in a place where we celebrate diversity and tolerance.',
			'primary_link'   => array( 'url' => '/village', 'title' => 'More about The Village', 'target' => '' ),
			'secondary_link' => array( 'url' => '/campsite', 'title' => 'More about The Campsite', 'target' => '' ),
		),
	);
}

/**
 * @return array<int, array<string, mixed>>
 */
function vila_akoya_village_section_rows() {
	return array(
		array(
			'acf_fc_layout' => 'page_hero',
			'layout'        => 'village',
			'heading'       => 'Our tiny house eco village',
			'body'          => 'A little village of tiny houses and other out-of-the-ordinary living spaces.',
			'image'         => 'https://vila-akoya.com/images/village-large.jpg',
			'heading_style' => 'barrio',
		),
		array(
			'acf_fc_layout' => 'text_image',
			'layout'        => 'village',
			'image'         => 'https://vila-akoya.com/images/village-circle.jpg',
			'heading'       => 'A Tiny Home Away From Home',
			'body'          => '<p>One side of our project, which we call "The Village" is the more community-like part of our project; a car-free area with about 15 plots intended exclusively for tiny homes.</p><p>Here a diverse crowd of fantastic humans of all sorts of different backgrounds, cultures and ages, spend longer stretches of time with us in their own tiny homes and other kinds of unique accommodations.</p><p>We want this little village to be an inspiring place, so it is important to us that these tiny homes are really beautiful, interesting, unique, weird, cute or absolutely fantastic, that they\'re built in a sustainable way and do not have a concrete foundation.</p><p>Every now and then we have some plots available for sale or for rent in our little village. If you\'d like to be considered for one of these spots, please get in touch with us.</p>',
		),
		array(
			'acf_fc_layout' => 'image_card_grid',
			'layout'        => 'village',
			'heading'       => 'How to join our little village',
			'intro'         => 'There are three ways in which to join our tiny house village',
			'cards'         => array(
				array(
					'image'   => 'https://vila-akoya.com/images/village-rental.jpg',
					'icon'    => 'landscape',
					'heading' => 'Tiny House Rental',
					'body'    => "Always wanted to stay in a tiny house, a converted double decker or a boat?\n\nFind here our out-of-the-ordinary stays, available for short- and long term rental.",
					'link'    => array( 'url' => '/booking/#village', 'title' => 'Find the available rentals', 'target' => '' ),
				),
				array(
					'image'   => 'https://vila-akoya.com/images/village-5year.jpg',
					'icon'    => 'cottage',
					'heading' => '5-year-lease',
					'body'    => 'Have you fallen in love with our project and people, and want to put down some roots with us? Check here to see if we have any 5-year-lease spots available, to put down your own tiny home.',
					'link'    => array( 'url' => '/contact', 'title' => 'Get in touch', 'target' => '' ),
				),
				array(
					'image'   => 'https://vila-akoya.com/images/village-plot.jpg',
					'icon'    => 'key',
					'heading' => 'Plot Purchase',
					'body'    => 'Every now and then we have plots in our little village available for sale. If you\'re interested in one of these plots, please get in touch with us.',
					'link'    => array( 'url' => '/contact', 'title' => 'Here', 'target' => '' ),
				),
			),
		),
		array(
			'acf_fc_layout' => 'gallery',
			'layout'        => 'village',
			'heading'       => 'This land is our living room',
			'caption_1'     => 'The cork oak forest and olive groves',
			'caption_2'     => 'Bordering the river Mira',
			'caption_3'     => 'Spectacular hiking and biking paths',
			'images'        => array(
				'https://vila-akoya.com/images/village-cork.jpg',
				'https://vila-akoya.com/images/village-river.jpg',
				'https://vila-akoya.com/images/village-walking.jpg',
			),
		),
		array(
			'acf_fc_layout'  => 'newsletter',
			'layout'         => 'inquiry',
			'heading'        => 'Want to learn more about our tiny home village?',
			'body'           => 'Tell us a little about yourself, who will be coming with you, and what you\'re looking for — we\'ll get back to you with options and info.',
			'form_shortcode' => vila_akoya_section_cf7_shortcode( 'village_inquiry' ),
			'helper_link'    => array( 'url' => '/contact', 'title' => 'Or contact us directly', 'target' => '' ),
			'image'          => '',
		),
	);
}

/**
 * @return array<int, array<string, mixed>>
 */
function vila_akoya_campsite_section_rows() {
	return array(
		array(
			'acf_fc_layout' => 'cover_hero',
			'image'         => 'https://vila-akoya.com/images/campsite-hero.jpg',
			'heading'       => 'A Creative campsite',
			'body'          => "A playground for kids and for adults, surrounded by seas of green as far as the eye can see.\n\nBring your own home-on-wheels or tent, all the people you love, and a story to share around the campfire.",
			'primary_link'  => array( 'url' => '/booking', 'title' => 'Book your plot', 'target' => '' ),
		),
		array(
			'acf_fc_layout'  => 'text_image',
			'layout'         => 'campsite',
			'image'          => 'https://vila-akoya.com/images/campsite-pitches.jpg',
			'badge_text'     => 'With access to all activities & facilities',
			'heading'        => 'Spacious pitches',
			'heading_accent' => 'Surrounded by nature',
			'body'           => '<p>We have 15 beautiful spacious camping pitches with decks, surrounded by proud trees, rolling hills and the singing of birds. Here our visitors can come and join us in their own campers, caravans or tents.</p>',
		),
		array(
			'acf_fc_layout'     => 'campsite_facilities',
			'heading'           => 'Activities & Facilities',
			'intro'             => 'The beautiful spaces we get to share together.',
			'river_image'       => 'https://vila-akoya.com/images/campsite-river.jpg',
			'river_heading'     => 'The River Mira',
			'river_body'        => 'Vila Akoya borders the river Mira, which hold water all-year-around. Have a swim, catch a fish, or take a little boat around!',
			'workshop_image'    => 'https://vila-akoya.com/images/campsite-workshop.jpg',
			'workshop_heading'  => 'Workshops',
			'workshop_body'     => "We're planning lots of workshops, classes and events for adults and children.",
			'workshop_link'     => array( 'url' => '/events', 'title' => 'View schedule here', 'target' => '' ),
			'little_image'      => 'https://vila-akoya.com/images/campsite-little.jpg',
			'little_heading'    => 'For the littler ones',
			'little_body'       => 'We have loads of activities, a homeschooling collective, playgrounds, and a babysitter on-site!',
			'little_link'       => array( 'url' => '/the-kids', 'title' => 'Kids programme', 'target' => '' ),
			'trails_heading'    => 'Hiking and biking trails',
			'trails_body'       => 'There are fantastic hiking and biking trails that start and end at Vila Akoya.',
			'food_heading'      => 'Food and Drinks',
			'food_body'         => 'Join our table for breakfast, lunch or dinner, and we will cook you up a feast!',
		),
		array(
			'acf_fc_layout' => 'gallery',
			'layout'        => 'campsite',
			'heading'       => "It's Beautiful Here",
			'images'        => array(
				'https://vila-akoya.com/images/campsite-slider-1.jpg',
				'https://vila-akoya.com/images/campsite-slider-2.jpg',
				'https://vila-akoya.com/images/campsite-slider-3.jpg',
				'https://vila-akoya.com/images/campsite-slider-4.jpg',
			),
		),
		array(
			'acf_fc_layout'  => 'cta_band',
			'layout'         => 'campsite',
			'heading'        => 'We are looking forward to meeting you!',
			'body'           => 'We are happy to host you and the humans and creatures that belong with you. Let us know your dates and needs, so we can reserve you a spot with us.',
			'primary_link'   => array( 'url' => '/booking', 'title' => 'Book your camping pitch', 'target' => '' ),
			'secondary_link' => array( 'url' => '/village', 'title' => 'Look at the village', 'target' => '' ),
		),
	);
}

/**
 * @return array<int, array<string, mixed>>
 */
function vila_akoya_campus_section_rows() {
	return array(
		array(
			'acf_fc_layout'  => 'page_hero',
			'layout'         => 'campus',
			'heading'        => 'The heart of our creative community',
			'body'           => "We believe that it's important to learn something new every day. That's why at the heart of our project there's The Campus; A campus-meets-playground where villagers, guests, and visitors come together to learn and to share skills and ideas.\n\nThe Campus offers a daily program with a lively mix of classes, workshops, retreats, lectures and activities in all fields of life.",
			'image'          => 'https://vila-akoya.com/images/campus-hero.jpg',
			'heading_style'  => 'barrio',
			'primary_link'   => array( 'url' => '/booking', 'title' => 'Book your dates', 'target' => '' ),
			'secondary_link' => array( 'url' => '/the-kids', 'title' => 'And what about the kids?', 'target' => '' ),
		),
		array(
			'acf_fc_layout' => 'icon_card_grid',
			'layout'        => 'campus',
			'heading'       => 'Classes in All Schools of Life',
			'intro'         => 'Body, craft and practical skill — woven through the week.',
			'cards'         => array(
				array(
					'icon'    => 'self_improvement',
					'heading' => 'Body & Mind Wellness',
					'body'    => 'Yoga, breathwork, movement and stillness practices.',
					'link'    => array(),
				),
				array(
					'icon'    => 'palette',
					'heading' => 'Creative Arts',
					'body'    => 'Making, music, writing and visual arts with visiting teachers.',
					'link'    => array(),
				),
				array(
					'icon'    => 'handyman',
					'heading' => 'Practical Skills',
					'body'    => 'Natural building, crafts, food and land skills.',
					'link'    => array(),
				),
			),
		),
		array(
			'acf_fc_layout' => 'legacy_html',
			'html'          => '[vila_events_preview]',
		),
		array(
			'acf_fc_layout' => 'text_image',
			'layout'        => 'plain',
			'image'         => 'https://vila-akoya.com/images/campus-teacher.jpg',
			'heading'       => "We're Looking For Artists, Teachers and Facilitators!",
			'body'          => '<p>If you have a skill to share — from ceramics to conflict resolution — we would love to hear from you. Come teach, host a workshop, or stay a while and weave into campus life.</p>',
			'link'          => array( 'url' => '/contact', 'title' => 'Get in touch', 'target' => '' ),
		),
		array(
			'acf_fc_layout' => 'text_image',
			'layout'        => 'plain',
			'image'         => 'https://vila-akoya.com/images/campus-detail.jpg',
			'heading'       => 'Our wonderfully busy days',
			'body'          => '<p>Days fill with classes, shared meals, river swims and quiet corners for work. Participation can be drop-in or deeper residency-style — there is room for many rhythms.</p><p><strong>Emphasis on Portuguese Arts and Crafts</strong> — we love hosting makers who carry local craft traditions and contemporary practice.</p>',
		),
		array(
			'acf_fc_layout'  => 'newsletter',
			'layout'         => 'campus',
			'heading'        => 'Got a skill to share? Get in touch with us!',
			'body'           => 'Please send us an introduction about yourself, your skills and credentials, what you\'d like to offer us and anything else that\'s relevant.',
			'form_shortcode' => vila_akoya_section_cf7_shortcode( 'campus' ),
			'helper_link'    => array( 'url' => '/contact', 'title' => 'Prefer email? Contact us', 'target' => '' ),
			'image'          => '',
		),
	);
}

/**
 * @return array<int, array<string, mixed>>
 */
function vila_akoya_the_kids_section_rows() {
	return array(
		array(
			'acf_fc_layout' => 'page_hero',
			'layout'        => 'kids',
			'heading'       => 'It takes a village..',
			'body'          => '"It takes a village to raise a child". We sure heard that one before, and we believe this to be very true. So we decided to build that village for our children.',
			'image'         => 'https://vila-akoya.com/images/kids-hero.jpg',
			'heading_style' => 'barrio',
		),
		array(
			'acf_fc_layout' => 'text_image',
			'layout'        => 'band',
			'image'         => 'https://vila-akoya.com/images/kids-school.jpg',
			'heading'       => 'Homeschooling Hub & Extracurricular Activities',
			'body'          => '<p>Our kids programme weaves a homeschooling hub with daily extracurricular activities — outdoors as much as possible — so children can learn, play and belong alongside the wider village.</p>',
		),
		array(
			'acf_fc_layout' => 'image_card_grid',
			'layout'        => 'kids',
			'heading'       => 'Daily Extracurricular Activities',
			'cards'         => array(
				array(
					'image'   => 'https://vila-akoya.com/images/kids-creative.jpg',
					'heading' => 'Creative Arts & Knowledge',
					'body'    => 'Ceramics, woodwork, natural building, weaving, photography, improv, painting, writing, music, sustainability workshops, languages, cooperative living and conflict resolution skills.',
					'link'    => array(),
				),
				array(
					'image'   => 'https://vila-akoya.com/images/kids-movement.jpg',
					'heading' => 'Movement and Stillness',
					'body'    => 'Climbing, dance, trail running, archery, martial arts, horseback riding, swimming, fencing, table tennis, yoga, tai-chi, breathwork, meditation and mindfulness.',
					'link'    => array(),
				),
				array(
					'image'   => 'https://vila-akoya.com/images/kids-practical.jpg',
					'heading' => 'Practical Skills',
					'body'    => 'Permaculture, cooking, fermentation, foraging, animal care, herbal medicine, survival skills, first aid, beekeeping, composting, construction, carpentry, welding and sewing.',
					'link'    => array(),
				),
			),
		),
		array(
			'acf_fc_layout'  => 'prose',
			'layout'         => 'skew',
			'heading'        => 'The Coddiwomples',
			'heading_accent' => 'Ages 0 to 5',
			'lede'           => 'Coddiwomple (verb): To travel in a purposeful manner towards a vague destination.',
			'body'           => 'For the littlest ones we also have loads to do! Join the Coddiwomples; with drop-in classes and activities -mostly outdoor- throughout the day. We also have on-site babysitters, available for private babysitting at most hours of the day.',
		),
		array(
			'acf_fc_layout' => 'text_image',
			'layout'        => 'kids-cta',
			'image'         => 'https://vila-akoya.com/images/kids-contact.jpg',
			'heading'       => 'Want to know more?',
			'body'          => '<p>Get in touch with us for all your questions and suggestions regarding our kids program, and to book your dates.</p>',
			'link'          => array( 'url' => '/book-childcare/', 'title' => 'Book childcare', 'target' => '' ),
		),
	);
}

/**
 * @return array<int, array<string, mixed>>
 */
function vila_akoya_contact_section_rows() {
	return array(
		array(
			'acf_fc_layout' => 'page_hero',
			'layout'        => 'contact',
			'eyebrow'       => 'Get in Touch',
			'heading'       => "It's nice to meet you here!",
			'body'          => 'Thanks for wanting to get in touch with us. We\'re excited to learn more about you, and the people and creatures that belong with you.',
			'image'         => 'https://vila-akoya.com/images/contact-hero.jpg',
			'badge_text'    => 'Sammy, Hans, Joyaa and Akhoda',
			'heading_style' => 'headline',
		),
		array(
			'acf_fc_layout' => 'icon_card_grid',
			'layout'        => 'contact',
			'heading'       => '',
			'cards'         => array(
				array(
					'icon'    => 'mail',
					'heading' => 'Email',
					'body'    => 'For general inquiries, booking details, questions, answers or just to say hi! Reach out to us anytime.',
					'link'    => array( 'url' => 'mailto:Info@vila-akoya.com', 'title' => 'Info@vila-akoya.com', 'target' => '' ),
					'variant' => 'email',
				),
				array(
					'icon'    => 'chat',
					'heading' => 'WhatsApp',
					'body'    => 'Want to reach us quicker?',
					'link'    => array( 'url' => 'https://wa.me/351960446285', 'title' => '+351 960 446 285', 'target' => '_blank' ),
					'variant' => 'whatsapp',
				),
				array(
					'icon'    => 'call',
					'heading' => 'Phone',
					'body'    => 'Or old-school, by phone call.',
					'link'    => array( 'url' => 'tel:+351960446285', 'title' => '+351 960 446 285', 'target' => '' ),
					'variant' => 'phone',
				),
			),
		),
		array(
			'acf_fc_layout'  => 'newsletter',
			'layout'         => 'contact',
			'heading'        => 'Send a message',
			'body'           => 'Tell us what you need and we will get back to you soon.',
			'form_shortcode' => vila_akoya_section_cf7_shortcode( 'contact' ),
			'helper_link'    => array(),
			'image'          => '',
		),
	);
}

/**
 * @return array<int, array<string, mixed>>
 */
function vila_akoya_thank_you_section_rows() {
	return array(
		array(
			'acf_fc_layout'  => 'cta_band',
			'layout'         => 'default',
			'icon'           => 'mark_email_read',
			'eyebrow'        => 'Thank you',
			'heading'        => 'We received your inquiry',
			'body'           => 'We will get back to you soon. If you need anything urgent, message us on WhatsApp or email info@vila-akoya.com.',
			'primary_link'   => array( 'url' => '/', 'title' => 'Back to home', 'target' => '' ),
			'secondary_link' => array( 'url' => '/booking', 'title' => 'Send another message', 'target' => '' ),
		),
	);
}

/**
 * @return array<int, array<string, mixed>>
 */
function vila_akoya_blog_section_rows() {
	return array(
		array(
			'acf_fc_layout' => 'cta_band',
			'layout'        => 'default',
			'eyebrow'       => 'Stories',
			'heading'       => 'From the village',
			'body'          => 'News, updates and reflections from life at Vila Akoya — the campsite, the campus, the kids and everything in between.',
		),
	);
}

/**
 * Booking page: use the original brochure HTML (hero + help column + reserve widget).
 * SCF sections cannot recreate the WooCommerce/CF7 two-column booking layout.
 *
 * @return array<int, array<string, mixed>>
 */
function vila_akoya_booking_section_rows() {
	$html = vila_akoya_booking_legacy_page_html();
	if ( '' === $html ) {
		return array();
	}

	return array(
		array(
			'acf_fc_layout' => 'legacy_html',
			'html'          => $html,
		),
	);
}

/**
 * Full booking page markup from the theme HTML seed (WC + CF7).
 *
 * @return string
 */
function vila_akoya_booking_legacy_page_html() {
	$path = VILA_AKOYA_DIR . '/inc/page-content/booking-blocks.html';
	if ( ! is_readable( $path ) ) {
		return '';
	}

	$html = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$cf7  = function_exists( 'vila_akoya_get_cf7_form_ids' ) ? vila_akoya_get_cf7_form_ids() : array();
	if ( function_exists( 'vila_akoya_seed_replace_cf7_placeholders' ) ) {
		$html = vila_akoya_seed_replace_cf7_placeholders( $html, $cf7 );
	}
	if ( function_exists( 'vila_akoya_seed_replace_wc_placeholders' ) ) {
		$html = vila_akoya_seed_replace_wc_placeholders( $html );
	}
	if ( function_exists( 'vila_akoya_seed_replace_stays_placeholders' ) ) {
		$html = vila_akoya_seed_replace_stays_placeholders( $html );
	}

	// Prefer local media URLs when attachments exist.
	$html = preg_replace_callback(
		'#https?://vila-akoya\.com/images/([^"\'\s>]+)#i',
		static function ( $m ) {
			$url = 'https://vila-akoya.com/images/' . $m[1];
			if ( function_exists( 'vila_akoya_seed_image_attachment_id' ) ) {
				$id = vila_akoya_seed_image_attachment_id( $url );
				if ( $id > 0 ) {
					$local = wp_get_attachment_url( $id );
					if ( is_string( $local ) && '' !== $local ) {
						return $local;
					}
				}
			}
			return $m[0];
		},
		$html
	);

	if ( function_exists( 'vila_akoya_sanitize_booking_page_html' ) ) {
		return vila_akoya_sanitize_booking_page_html( $html );
	}

	$html = preg_replace( '/<!--\s*\/?wp:[^>]*-->\s*/', '', (string) $html );

	return is_string( $html ) ? $html : '';
}

/**
 * @deprecated Prefer vila_akoya_booking_legacy_page_html().
 *
 * @return string
 */
function vila_akoya_booking_legacy_widget_html() {
	return vila_akoya_booking_legacy_page_html();
}
