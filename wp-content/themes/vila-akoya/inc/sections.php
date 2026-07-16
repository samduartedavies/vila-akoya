<?php
/**
 * Editable page sections backed by a custom-fields plugin.
 *
 * Compatible with Secure Custom Fields (free, from WordPress.org) or ACF/ACF
 * Pro, which share the same acf_* API. The editor owns content only (image,
 * copy, links); the theme owns the markup, Tailwind classes, and animation
 * hooks, so client edits cannot strip layout or motion from image blocks.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field name used for the page section builder.
 */
const VILA_AKOYA_SECTIONS_FIELD = 'vila_page_sections';

/**
 * Load (and save) the Page Sections field group from theme acf-json.
 *
 * JSON keeps the group visible in the Secure Custom Fields admin UI and
 * version-controlled in git, instead of hidden PHP-only registration.
 *
 * @param string|string[] $path Save path or load paths.
 * @return string|string[]
 */
function vila_akoya_sections_acf_json_path( $path ) {
	return VILA_AKOYA_DIR . '/acf-json';
}
add_filter( 'acf/settings/save_json', 'vila_akoya_sections_acf_json_path' );
add_filter(
	'acf/settings/load_json',
	function ( $paths ) {
		$paths[] = VILA_AKOYA_DIR . '/acf-json';
		return $paths;
	}
);

/**
 * Whether a page has one or more section-builder rows saved.
 *
 * @param int $post_id Page ID. Defaults to current post in the loop.
 * @return bool
 */
function vila_akoya_page_has_sections( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();
	if ( $post_id <= 0 ) {
		return false;
	}

	if ( function_exists( 'vila_akoya_page_has_section_blocks' ) && vila_akoya_page_has_section_blocks( $post_id ) ) {
		return true;
	}

	if ( ! function_exists( 'have_rows' ) ) {
		return false;
	}

	return (bool) have_rows( VILA_AKOYA_SECTIONS_FIELD, $post_id );
}

/**
 * Warn admins when the field-driven editing layer cannot be used.
 */
function vila_akoya_sections_acf_notice() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( function_exists( 'get_field' ) || function_exists( 'acf_get_field_group' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'page' !== $screen->post_type ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'Vila Akoya editable sections require a custom fields plugin. Install the free Secure Custom Fields plugin (or ACF/ACF Pro) to use the safe section editor. Existing block content keeps working until then.', 'vila-akoya' )
	);
}
add_action( 'admin_notices', 'vila_akoya_sections_acf_notice' );

/**
 * Tell editors to use Page Sections instead of the block canvas on pilot pages.
 */
function vila_akoya_sections_page_editor_notice() {
	if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || 'page' !== $screen->post_type || ! in_array( $screen->base, array( 'post', 'page' ), true ) ) {
		return;
	}

	$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $post_id <= 0 || ! vila_akoya_page_has_sections( $post_id ) ) {
		return;
	}

	$uses_blocks = function_exists( 'vila_akoya_page_has_section_blocks' ) && vila_akoya_page_has_section_blocks( $post_id );

	printf(
		'<div class="notice notice-info"><p><strong>%s</strong> %s</p></div>',
		esc_html__( 'This page uses Vila Sections.', 'vila-akoya' ),
		$uses_blocks
			? esc_html__( 'Click a section in the canvas to edit its image, copy, and links in the sidebar. Layout and animations stay in the theme.', 'vila-akoya' )
			: esc_html__( 'Edit content in the Page Sections box (Meta Boxes). Prefer Vila Sections blocks for a visual editor.', 'vila-akoya' )
	);
}
add_action( 'admin_notices', 'vila_akoya_sections_page_editor_notice' );

/**
 * Snackbar hint for section-managed pages in the block editor.
 */
function vila_akoya_sections_block_editor_assets() {
	if ( ! function_exists( 'get_current_screen' ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || 'page' !== $screen->post_type || 'post' !== $screen->base ) {
		return;
	}

	$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $post_id <= 0 || ! vila_akoya_page_has_sections( $post_id ) ) {
		return;
	}

	$uses_blocks = function_exists( 'vila_akoya_page_has_section_blocks' ) && vila_akoya_page_has_section_blocks( $post_id );
	$msg         = $uses_blocks
		? __( 'Click a Vila Section in the canvas, then edit fields in the right sidebar (panel icon next to Save).', 'vila-akoya' )
		: __( 'This page still uses Page Sections meta boxes. Run npm run wp:seed-acf-home to switch Home to visual blocks.', 'vila-akoya' );

	wp_register_script( 'vila-akoya-sections-editor-hint', '', array( 'wp-dom-ready', 'wp-data' ), VILA_AKOYA_VERSION, true );
	wp_enqueue_script( 'vila-akoya-sections-editor-hint' );
	wp_add_inline_script(
		'vila-akoya-sections-editor-hint',
		'wp.domReady(function(){var n=wp.data.dispatch("core/notices");if(n&&n.createNotice){n.createNotice("info",' . wp_json_encode( $msg ) . ',{isDismissible:true,type:"snackbar"});}});'
	);
}
add_action( 'enqueue_block_editor_assets', 'vila_akoya_sections_block_editor_assets' );

/**
 * Replace page block content with theme-rendered sections when rows exist.
 *
 * @param string $content Normal post content.
 * @return string
 */
function vila_akoya_render_acf_sections_content( $content ) {
	if (
		is_admin()
		|| ! is_singular( 'page' )
		|| ! in_the_loop()
		|| ! is_main_query()
		|| ! function_exists( 'have_rows' )
	) {
		return $content;
	}

	$post_id = get_the_ID();
	// Visual section blocks render themselves — do not replace their output.
	if ( ! $post_id || ( function_exists( 'vila_akoya_page_has_section_blocks' ) && vila_akoya_page_has_section_blocks( $post_id ) ) ) {
		return $content;
	}

	if ( ! have_rows( VILA_AKOYA_SECTIONS_FIELD, $post_id ) ) {
		return $content;
	}

	$wrapper_classes = array( 'vila-section-builder' );
	if ( is_page( 'home' ) || (int) get_option( 'page_on_front' ) === (int) $post_id ) {
		$wrapper_classes[] = 'vila-home';
	} else {
		$wrapper_classes[] = 'vila-page-inner';
	}

	ob_start();
	echo '<div class="' . esc_attr( implode( ' ', $wrapper_classes ) ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	while ( have_rows( VILA_AKOYA_SECTIONS_FIELD, $post_id ) ) {
		the_row();
		vila_akoya_render_section_template( (string) get_row_layout() );
	}
	echo '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	$rendered = ob_get_clean();
	return is_string( $rendered ) && '' !== trim( $rendered ) ? $rendered : $content;
}
add_filter( 'the_content', 'vila_akoya_render_acf_sections_content', 5 );

/**
 * Outer wrapper classes that restore original page padding / texture.
 *
 * Visual SCF blocks no longer sit inside the seeded HTML wrapper group, so
 * top padding and campus texture must be re-applied here.
 *
 * @param string $slug Page slug.
 * @return string
 */
function vila_akoya_page_section_wrapper_class( $slug ) {
	$slug = sanitize_title( $slug );
	$map  = array(
		'home'      => 'vila-home',
		'about'     => 'vila-page-inner pt-0 md:pt-28 pb-20',
		'village'   => 'vila-page-inner pt-10 md:pt-28',
		'campsite'  => 'vila-page-inner pt-0',
		'campus'    => 'vila-page-inner bg-background text-on-background textured-bg pt-10',
		'the-kids'  => 'vila-page-inner pt-0 md:pt-28',
		'contact'   => 'vila-page-inner pt-10 md:pt-28 pb-20',
		'thank-you' => 'vila-page-inner pt-10 md:pt-28 pb-20',
		'blog'      => 'vila-page-inner vila-blog-page pt-0 pb-8',
		'booking'   => 'vila-page-inner', // booking-blocks.html already includes width/padding wrapper.
	);

	return $map[ $slug ] ?? 'vila-page-inner pt-10 md:pt-28';
}

/**
 * Wrap Vila section-block pages so spacing matches the original brochure HTML.
 *
 * @param string $content Post content.
 * @return string
 */
function vila_akoya_wrap_section_blocks_content( $content ) {
	if (
		is_admin()
		|| ! is_singular( 'page' )
		|| ! in_the_loop()
		|| ! is_main_query()
		|| ! function_exists( 'vila_akoya_page_has_section_blocks' )
	) {
		return $content;
	}

	$post_id = (int) get_the_ID();
	if ( $post_id <= 0 || ! vila_akoya_page_has_section_blocks( $post_id ) ) {
		return $content;
	}

	// Avoid double-wrapping if seed HTML already included a page wrapper.
	if ( str_contains( $content, 'vila-page-inner' ) || str_contains( $content, 'class="vila-home"' ) ) {
		return $content;
	}

	$slug  = (string) get_post_field( 'post_name', $post_id );
	$class = vila_akoya_page_section_wrapper_class( $slug );

	return '<div class="' . esc_attr( $class ) . '">' . $content . '</div>';
}
add_filter( 'the_content', 'vila_akoya_wrap_section_blocks_content', 8 );

/**
 * Include the PHP template for a section layout.
 *
 * @param string $layout ACF flexible-content layout name.
 */
function vila_akoya_render_section_template( $layout ) {
	$map = array(
		'home_hero'          => 'home-hero.php',
		'cover_hero'         => 'cover-hero.php',
		'campsite_facilities'=> 'campsite-facilities.php',
		'feature_split'      => 'feature-split.php',
		'text_image'         => 'text-image.php',
		'people'             => 'people.php',
		'image_card_grid'    => 'image-card-grid.php',
		'gallery'            => 'gallery.php',
		'newsletter'         => 'newsletter.php',
		'prose'              => 'prose.php',
		'page_hero'          => 'page-hero.php',
		'cta_band'           => 'cta-band.php',
		'icon_card_grid'     => 'icon-card-grid.php',
		'masonry_spotlight'  => 'masonry-spotlight.php',
	);

	if ( empty( $map[ $layout ] ) ) {
		return;
	}

	$template = VILA_AKOYA_DIR . '/inc/sections/' . $map[ $layout ];
	if ( is_readable( $template ) ) {
		include $template;
	}
}

/**
 * Get an ACF sub field with a default fallback.
 *
 * @param string $name    Field name.
 * @param mixed  $default Default value.
 * @return mixed
 */
/**
 * Repair strings corrupted when block JSON unicode escapes lost their backslash.
 *
 * wp_update_post() unslashes content, turning "\u003c" into "u003c". That then
 * shows as weird codes on the front end instead of HTML.
 *
 * @param mixed $value Field value.
 * @return mixed
 */
function vila_akoya_section_decode_value( $value ) {
	if ( ! is_string( $value ) || ! preg_match( '/(?:\\\\u00|u00)[0-9a-f]{2}/i', $value ) ) {
		return $value;
	}

	$fixed = preg_replace_callback(
		'/\\\\?u00([0-9a-f]{2})/i',
		static function ( $matches ) {
			return chr( hexdec( $matches[1] ) );
		},
		$value
	);

	return is_string( $fixed ) ? $fixed : $value;
}

function vila_akoya_section_field( $name, $default = '' ) {
	$value = null;

	// Flexible Content / repeater context.
	if ( function_exists( 'get_sub_field' ) ) {
		$value = get_sub_field( $name );
	}

	// SCF/ACF block context (and top-level fields).
	if ( ( null === $value || false === $value || '' === $value || array() === $value ) && function_exists( 'get_field' ) ) {
		$value = get_field( $name );
	}

	if ( null === $value || false === $value || '' === $value || array() === $value ) {
		return $default;
	}

	if ( is_string( $value ) ) {
		return vila_akoya_section_decode_value( $value );
	}

	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			if ( is_string( $v ) ) {
				$value[ $k ] = vila_akoya_section_decode_value( $v );
			}
		}
	}

	return $value;
}

/**
 * Render an image from an ACF image field, attachment ID, URL, or fallback URL.
 *
 * @param mixed  $image    Image field value.
 * @param string $class    Figure classes.
 * @param string $alt      Fallback alt text.
 * @param string $size     Attachment image size.
 * @return void
 */
function vila_akoya_section_image( $image, $class, $alt = '', $size = 'large' ) {
	$id      = 0;
	$url     = '';
	$img_alt = $alt;

	if ( is_array( $image ) ) {
		$id      = isset( $image['ID'] ) ? (int) $image['ID'] : ( isset( $image['id'] ) ? (int) $image['id'] : 0 );
		$url     = isset( $image['url'] ) ? (string) $image['url'] : '';
		$img_alt = isset( $image['alt'] ) && '' !== $image['alt'] ? (string) $image['alt'] : $alt;
	} elseif ( is_numeric( $image ) ) {
		$id = (int) $image;
	} elseif ( is_string( $image ) ) {
		$url = $image;
	}

	echo '<figure class="' . esc_attr( $class ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	if ( $id ) {
		echo wp_get_attachment_image(
			$id,
			$size,
			false,
			array(
				'alt'      => $img_alt,
				'loading'  => 'lazy',
				'decoding' => 'async',
			)
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	} elseif ( '' !== $url ) {
		printf(
			'<img src="%s" alt="%s" loading="lazy" decoding="async" />',
			esc_url( $url ),
			esc_attr( $img_alt )
		);
	}
	echo '</figure>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Render a link from an ACF link field or URL string.
 *
 * @param mixed  $link          Link field value.
 * @param string $default_label Default label.
 * @param string $class         Anchor classes.
 * @return void
 */
function vila_akoya_section_link( $link, $default_label, $class ) {
	$url    = '';
	$label  = $default_label;
	$target = '';

	if ( is_array( $link ) ) {
		$url    = isset( $link['url'] ) ? (string) $link['url'] : '';
		$label  = isset( $link['title'] ) && '' !== $link['title'] ? (string) $link['title'] : $default_label;
		$target = isset( $link['target'] ) ? (string) $link['target'] : '';
	} elseif ( is_string( $link ) ) {
		$url = $link;
	}

	if ( '' === $url || '' === $label ) {
		return;
	}

	printf(
		'<a class="%s" href="%s"%s>%s</a>',
		esc_attr( $class ),
		esc_url( $url ),
		'' !== $target ? ' target="' . esc_attr( $target ) . '" rel="noopener"' : '',
		esc_html( $label )
	);
}

/**
 * Default Home rows for the ACF section pilot.
 *
 * @return array<int, array<string, mixed>>
 */
function vila_akoya_home_section_rows() {
	return array(
		array(
			'acf_fc_layout'  => 'home_hero',
			'image'          => 'https://vila-akoya.com/images/home-hero.jpg',
			'heading'        => 'The little village of our dreams',
			'body'           => 'A tiny house village, glamping and creative community bordering the river Mira in the south of Alentejo. An extraordinary place of creation, relaxation and inspiration that we get to share with our friends and friends disguised as strangers.',
			'primary_link'   => array(
				'url'    => '/booking',
				'title'  => 'Book your stay',
				'target' => '',
			),
			'secondary_link' => array(
				'url'    => '/campus',
				'title'  => 'Tell me more!',
				'target' => '',
			),
		),
		array(
			'acf_fc_layout' => 'feature_split',
			'left_eyebrow' => 'A place to stay',
			'left_heading' => 'The Village',
			'left_body'    => "Unique tiny homes in all shapes and forms. A feast for the eye and a home for the kind. This is the little village we've always been hoping to find.",
			'left_image'   => 'https://vila-akoya.com/images/village-small.jpg',
			'left_link'    => array(
				'url'    => '/village',
				'title'  => 'Looking for a village?',
				'target' => '',
			),
			'right_eyebrow'=> '...or to visit',
			'right_heading'=> 'The Campsite',
			'right_body'   => 'Want to dip you toe in our home-away-from home? Swing by, join our table, have a swim, build a treehouse, make a friend.. and leave forever changed.',
			'right_image'  => 'https://vila-akoya.com/images/campsite.jpeg',
			'right_link'   => array(
				'url'    => '/booking',
				'title'  => 'Book your dates',
				'target' => '',
			),
		),
		array(
			'acf_fc_layout'  => 'text_image',
			'layout'         => 'home',
			'image'          => 'https://vila-akoya.com/images/river.jpeg',
			'badge_text'     => 'A little village with a big heart.',
			'heading'        => 'That little village',
			'heading_accent' => "we've been looking for",
			'body'           => '<p>We wanted to live in the forest, and raise our kids amongst the trees. But we did not want to do it alone. Frankly, we\'re done with doing everything alone! Who came up with that? Lucky for us, there are loads more people like us. So we got a bunch of fantastic humans together and set out to build the little village of our dreams.</p><p>Expect to find something fantastic in every corner of this space. With playgrounds for children and for grown-ups, areas for wellness and relaxation, spaces designed for connection, creation and celebration, and a full agenda of workshops, classes and activities for humans of all ages.</p>',
			'link'           => array(
				'url'    => '/campus',
				'title'  => 'Learn more about the activities for adults and kids',
				'target' => '',
			),
		),
		array(
			'acf_fc_layout' => 'people',
			'heading'       => 'Who are we?',
			'intro'         => 'The family behind Vila Akoya',
			'people'        => array(
				array(
					'name'    => 'Joyaa',
					'image'   => 'https://vila-akoya.com/images/joyaa.jpg',
					'bio'     => '<p>It takes a village to raise a child, and for us it took a child to build a village. Vila Akoya wouldn\'t be anything without our daughter Joyaa. It\'s with her in mind that we envision the village in which we want to raise her; a green and inspiring place with a vibrant community where she - and her brother - will get to grow, learn and adventure.</p><p>Joyaa is 10 years old now. She\'s creative, funny, kind, smart and a great friend to have... and did I mention she\'s creative?</p>',
					'variant' => '',
				),
				array(
					'name'    => 'Sammy',
					'image'   => 'https://vila-akoya.com/images/sammy.jpg',
					'bio'     => '<p>My name is Sammy. I am Joyaa and Akhoda\'s mother and Hans\'s partner.</p><p>Besides a mother and partner, I am many other things. I have an entrepreneurial mind and a heart for sustainability; I founded and ran sustainable clothing and baby brands, and spend the last 4 years working on Vila Akoya.</p>',
					'variant' => 'who-we-portrait--blob-3',
				),
				array(
					'name'    => 'Hans',
					'image'   => 'https://vila-akoya.com/images/hans.jpg',
					'bio'     => '<p>Hans is the brains and the muscles behind Vila Akoya. He knows and learns about the possibles and impossibles surrounding our projects.</p>',
					'variant' => 'who-we-portrait--blob-2',
				),
				array(
					'name'    => 'Little Akhoda',
					'image'   => 'https://vila-akoya.com/images/akhoda.jpg',
					'bio'     => '<p>In summer 2024 this little guy made our family complete. Akhoda is such a wonderful little person; he is strong but kind, he loves his family and the forest and meets plants and creatures with gentle hands and great curiosity.</p>',
					'variant' => 'who-we-portrait--blob-4',
				),
			),
			'link'          => array(
				'url'    => '/about',
				'title'  => 'How we got here',
				'target' => '',
			),
		),
		array(
			'acf_fc_layout'  => 'newsletter',
			'heading'        => 'Join our digi-family',
			'body'           => 'Want to stay in the loop about what is happening on our little slice of paradise? Enter your email here, and we will try not to spam you too much with the things we are excited to share.',
			'form_shortcode' => '[contact-form-7 id="430" html_class="vila-cf7 vila-cf7--newsletter"]',
			'helper_link'    => array(
				'url'    => '/contact',
				'title'  => 'Want to get in touch? Find our contact info here',
				'target' => '',
			),
			'image'          => 'https://vila-akoya.com/images/home-newsletter.jpg',
		),
	);
}

/**
 * Seed Home with visual SCF section blocks (preferred) or Flexible Content rows.
 *
 * @return int|WP_Error Home page ID or error.
 */
function vila_akoya_seed_home_sections() {
	if ( function_exists( 'vila_akoya_seed_home_section_blocks' ) ) {
		return vila_akoya_seed_home_section_blocks();
	}

	if ( ! function_exists( 'update_field' ) ) {
		return new WP_Error( 'acf_missing', __( 'Secure Custom Fields (or ACF) must be active before seeding Home sections.', 'vila-akoya' ) );
	}

	$page = get_page_by_path( 'home', OBJECT, 'page' );
	if ( ! $page instanceof WP_Post ) {
		return new WP_Error( 'home_missing', __( 'Home page not found.', 'vila-akoya' ) );
	}

	$rows = function_exists( 'vila_akoya_seed_resolve_images' )
		? vila_akoya_seed_resolve_images( vila_akoya_home_section_rows() )
		: vila_akoya_home_section_rows();

	update_field( 'field_vila_page_sections', $rows, $page->ID );

	if ( '' !== trim( (string) $page->post_content ) ) {
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => '' ), array( 'ID' => $page->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $page->ID );
	}

	return (int) $page->ID;
}
