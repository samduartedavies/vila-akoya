<?php
/**
 * Visual SCF section blocks.
 *
 * Each section is a Gutenberg block with a live preview. Editors only change
 * fields (image, copy, links); render templates in inc/sections/ own the markup.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the Vila Sections block category.
 *
 * @param array<int, array<string, mixed>> $categories Block categories.
 * @return array<int, array<string, mixed>>
 */
function vila_akoya_block_categories( $categories ) {
	$categories[] = array(
		'slug'  => 'vila-sections',
		'title' => __( 'Vila Sections', 'vila-akoya' ),
	);
	return $categories;
}
add_filter( 'block_categories_all', 'vila_akoya_block_categories', 10, 1 );

/**
 * Section block definitions (slug => config).
 *
 * @return array<string, array<string, mixed>>
 */
function vila_akoya_section_block_defs() {
	return array(
		'home-hero'       => array(
			'title'       => __( 'Home hero', 'vila-akoya' ),
			'description' => __( 'Full-bleed home hero with heading, body, and two buttons.', 'vila-akoya' ),
			'icon'        => 'cover-image',
			'template'    => 'home-hero.php',
			'keywords'    => array( 'hero', 'home', 'banner' ),
		),
		'cover-hero'      => array(
			'title'       => __( 'Cover hero', 'vila-akoya' ),
			'description' => __( 'Full-bleed cover hero (Campsite-style) with heading, body, and CTA.', 'vila-akoya' ),
			'icon'        => 'format-image',
			'template'    => 'cover-hero.php',
			'keywords'    => array( 'hero', 'cover', 'campsite' ),
		),
		'campsite-facilities' => array(
			'title'       => __( 'Campsite facilities', 'vila-akoya' ),
			'description' => __( 'Campsite Activities & Facilities brochure mosaic.', 'vila-akoya' ),
			'icon'        => 'index-card',
			'template'    => 'campsite-facilities.php',
			'keywords'    => array( 'campsite', 'facilities', 'activities' ),
		),
		'feature-split'   => array(
			'title'       => __( 'Feature split', 'vila-akoya' ),
			'description' => __( 'Two side-by-side feature columns with images and links.', 'vila-akoya' ),
			'icon'        => 'columns',
			'template'    => 'feature-split.php',
			'keywords'    => array( 'split', 'village', 'campsite' ),
		),
		'text-image'      => array(
			'title'       => __( 'Text + image', 'vila-akoya' ),
			'description' => __( 'Story section with image, badge, heading, and body.', 'vila-akoya' ),
			'icon'        => 'align-pull-left',
			'template'    => 'text-image.php',
			'keywords'    => array( 'text', 'image', 'story' ),
		),
		'people'          => array(
			'title'       => __( 'People', 'vila-akoya' ),
			'description' => __( 'Family / people profiles with portraits and bios.', 'vila-akoya' ),
			'icon'        => 'groups',
			'template'    => 'people.php',
			'keywords'    => array( 'people', 'family', 'about' ),
		),
		'image-card-grid' => array(
			'title'       => __( 'Image card grid', 'vila-akoya' ),
			'description' => __( 'Grid of image cards with headings and links.', 'vila-akoya' ),
			'icon'        => 'grid-view',
			'template'    => 'image-card-grid.php',
			'keywords'    => array( 'cards', 'grid' ),
		),
		'gallery'         => array(
			'title'       => __( 'Gallery', 'vila-akoya' ),
			'description' => __( 'Image gallery section.', 'vila-akoya' ),
			'icon'        => 'format-gallery',
			'template'    => 'gallery.php',
			'keywords'    => array( 'gallery', 'photos' ),
		),
		'newsletter'      => array(
			'title'       => __( 'Newsletter', 'vila-akoya' ),
			'description' => __( 'Newsletter signup with optional image and shortcode form.', 'vila-akoya' ),
			'icon'        => 'email',
			'template'    => 'newsletter.php',
			'keywords'    => array( 'newsletter', 'email', 'signup' ),
		),
		'prose'           => array(
			'title'       => __( 'Prose', 'vila-akoya' ),
			'description' => __( 'Simple rich-text prose section.', 'vila-akoya' ),
			'icon'        => 'text',
			'template'    => 'prose.php',
			'keywords'    => array( 'text', 'prose', 'copy' ),
		),
		'page-hero'       => array(
			'title'       => __( 'Page hero', 'vila-akoya' ),
			'description' => __( 'Inner-page hero with copy, image, and optional CTAs.', 'vila-akoya' ),
			'icon'        => 'align-pull-right',
			'template'    => 'page-hero.php',
			'keywords'    => array( 'hero', 'page', 'banner' ),
		),
		'cta-band'        => array(
			'title'       => __( 'CTA band', 'vila-akoya' ),
			'description' => __( 'Centered call-to-action with optional icon and two links.', 'vila-akoya' ),
			'icon'        => 'megaphone',
			'template'    => 'cta-band.php',
			'keywords'    => array( 'cta', 'button', 'band' ),
		),
		'icon-card-grid'  => array(
			'title'       => __( 'Icon card grid', 'vila-akoya' ),
			'description' => __( 'Grid of icon cards with title, body, and optional links.', 'vila-akoya' ),
			'icon'        => 'screenoptions',
			'template'    => 'icon-card-grid.php',
			'keywords'    => array( 'icons', 'cards', 'grid' ),
		),
		'masonry-spotlight' => array(
			'title'       => __( 'Masonry spotlight', 'vila-akoya' ),
			'description' => __( 'Masonry copy + image + accent cards (About location).', 'vila-akoya' ),
			'icon'        => 'layout',
			'template'    => 'masonry-spotlight.php',
			'keywords'    => array( 'masonry', 'about', 'location' ),
		),
	);
}

/**
 * Register SCF/ACF section blocks.
 */
function vila_akoya_register_section_blocks() {
	if ( ! function_exists( 'acf_register_block_type' ) ) {
		return;
	}

	foreach ( vila_akoya_section_block_defs() as $slug => $def ) {
		acf_register_block_type(
			array(
				'name'            => 'vila-' . $slug,
				'title'           => $def['title'],
				'description'     => $def['description'],
				'category'        => 'vila-sections',
				'icon'            => $def['icon'],
				'keywords'        => $def['keywords'],
				'mode'            => 'preview',
				'align'           => 'full',
				'supports'        => array(
					'align' => false,
					'mode'  => true,
					'jsx'   => false,
				),
				'render_template' => VILA_AKOYA_DIR . '/inc/sections/' . $def['template'],
				'example'         => array(
					'attributes' => array(
						'mode' => 'preview',
					),
				),
			)
		);
	}

	vila_akoya_register_section_block_field_groups();
}
add_action( 'acf/init', 'vila_akoya_register_section_blocks', 5 );

/**
 * Field helpers shared by block field groups.
 *
 * @param string $key   Field key.
 * @param string $name  Field name.
 * @param string $label Field label.
 * @return array<string, mixed>
 */
function vila_akoya_block_text_field( $key, $name, $label ) {
	return array(
		'key'   => $key,
		'label' => $label,
		'name'  => $name,
		'type'  => 'text',
	);
}

/**
 * @param string $key   Field key.
 * @param string $name  Field name.
 * @param string $label Field label.
 * @return array<string, mixed>
 */
function vila_akoya_block_textarea_field( $key, $name, $label ) {
	return array(
		'key'   => $key,
		'label' => $label,
		'name'  => $name,
		'type'  => 'textarea',
		'rows'  => 4,
	);
}

/**
 * @param string $key   Field key.
 * @param string $name  Field name.
 * @param string $label Field label.
 * @return array<string, mixed>
 */
function vila_akoya_block_wysiwyg_field( $key, $name, $label ) {
	return array(
		'key'          => $key,
		'label'        => $label,
		'name'         => $name,
		'type'         => 'wysiwyg',
		'tabs'         => 'visual',
		'toolbar'      => 'basic',
		'media_upload' => 0,
	);
}

/**
 * @param string $key   Field key.
 * @param string $name  Field name.
 * @param string $label Field label.
 * @return array<string, mixed>
 */
function vila_akoya_block_image_field( $key, $name, $label ) {
	return array(
		'key'           => $key,
		'label'         => $label,
		'name'          => $name,
		'type'          => 'image',
		'return_format' => 'array',
		'preview_size'  => 'medium',
		'library'       => 'all',
	);
}

/**
 * @param string $key   Field key.
 * @param string $name  Field name.
 * @param string $label Field label.
 * @return array<string, mixed>
 */
function vila_akoya_block_link_field( $key, $name, $label ) {
	return array(
		'key'           => $key,
		'label'         => $label,
		'name'          => $name,
		'type'          => 'link',
		'return_format' => 'array',
	);
}

/**
 * Register local field groups for each section block.
 */
function vila_akoya_register_section_block_field_groups() {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	$groups = array(
		array(
			'key'      => 'group_vila_block_home_hero',
			'title'    => 'Block: Home hero',
			'block'    => 'acf/vila-home-hero',
			'fields'   => array(
				vila_akoya_block_image_field( 'field_vila_b_home_hero_image', 'image', __( 'Image', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_home_hero_heading', 'heading', __( 'Heading', 'vila-akoya' ) ),
				vila_akoya_block_textarea_field( 'field_vila_b_home_hero_body', 'body', __( 'Body', 'vila-akoya' ) ),
				vila_akoya_block_link_field( 'field_vila_b_home_hero_primary_link', 'primary_link', __( 'Primary button', 'vila-akoya' ) ),
				vila_akoya_block_link_field( 'field_vila_b_home_hero_secondary_link', 'secondary_link', __( 'Secondary button', 'vila-akoya' ) ),
			),
		),
		array(
			'key'      => 'group_vila_block_feature_split',
			'title'    => 'Block: Feature split',
			'block'    => 'acf/vila-feature-split',
			'fields'   => array(
				vila_akoya_block_text_field( 'field_vila_b_feature_left_eyebrow', 'left_eyebrow', __( 'Left eyebrow', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_feature_left_heading', 'left_heading', __( 'Left heading', 'vila-akoya' ) ),
				vila_akoya_block_textarea_field( 'field_vila_b_feature_left_body', 'left_body', __( 'Left body', 'vila-akoya' ) ),
				vila_akoya_block_image_field( 'field_vila_b_feature_left_image', 'left_image', __( 'Left image', 'vila-akoya' ) ),
				vila_akoya_block_link_field( 'field_vila_b_feature_left_link', 'left_link', __( 'Left link', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_feature_right_eyebrow', 'right_eyebrow', __( 'Right eyebrow', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_feature_right_heading', 'right_heading', __( 'Right heading', 'vila-akoya' ) ),
				vila_akoya_block_textarea_field( 'field_vila_b_feature_right_body', 'right_body', __( 'Right body', 'vila-akoya' ) ),
				vila_akoya_block_image_field( 'field_vila_b_feature_right_image', 'right_image', __( 'Right image', 'vila-akoya' ) ),
				vila_akoya_block_link_field( 'field_vila_b_feature_right_link', 'right_link', __( 'Right link', 'vila-akoya' ) ),
			),
		),
		array(
			'key'      => 'group_vila_block_cover_hero',
			'title'    => 'Block: Cover hero',
			'block'    => 'acf/vila-cover-hero',
			'fields'   => array(
				vila_akoya_block_image_field( 'field_vila_b_cover_hero_image', 'image', __( 'Image', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_cover_hero_heading', 'heading', __( 'Heading', 'vila-akoya' ) ),
				vila_akoya_block_textarea_field( 'field_vila_b_cover_hero_body', 'body', __( 'Body', 'vila-akoya' ) ),
				vila_akoya_block_link_field( 'field_vila_b_cover_hero_primary_link', 'primary_link', __( 'Primary button', 'vila-akoya' ) ),
			),
		),
		array(
			'key'    => 'group_vila_block_campsite_facilities',
			'title'  => 'Block: Campsite facilities',
			'block'  => 'acf/vila-campsite-facilities',
			'fields' => array(
				vila_akoya_block_text_field( 'field_vila_b_cf_heading', 'heading', __( 'Heading', 'vila-akoya' ) ),
				vila_akoya_block_textarea_field( 'field_vila_b_cf_intro', 'intro', __( 'Intro', 'vila-akoya' ) ),
				vila_akoya_block_image_field( 'field_vila_b_cf_river_image', 'river_image', __( 'River image', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_cf_river_heading', 'river_heading', __( 'River heading', 'vila-akoya' ) ),
				vila_akoya_block_textarea_field( 'field_vila_b_cf_river_body', 'river_body', __( 'River body', 'vila-akoya' ) ),
				vila_akoya_block_image_field( 'field_vila_b_cf_workshop_image', 'workshop_image', __( 'Workshop image', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_cf_workshop_heading', 'workshop_heading', __( 'Workshop heading', 'vila-akoya' ) ),
				vila_akoya_block_textarea_field( 'field_vila_b_cf_workshop_body', 'workshop_body', __( 'Workshop body', 'vila-akoya' ) ),
				vila_akoya_block_link_field( 'field_vila_b_cf_workshop_link', 'workshop_link', __( 'Workshop link', 'vila-akoya' ) ),
				vila_akoya_block_image_field( 'field_vila_b_cf_little_image', 'little_image', __( 'Littler ones image', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_cf_little_heading', 'little_heading', __( 'Littler ones heading', 'vila-akoya' ) ),
				vila_akoya_block_textarea_field( 'field_vila_b_cf_little_body', 'little_body', __( 'Littler ones body', 'vila-akoya' ) ),
				vila_akoya_block_link_field( 'field_vila_b_cf_little_link', 'little_link', __( 'Littler ones link', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_cf_trails_heading', 'trails_heading', __( 'Trails heading', 'vila-akoya' ) ),
				vila_akoya_block_textarea_field( 'field_vila_b_cf_trails_body', 'trails_body', __( 'Trails body', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_cf_food_heading', 'food_heading', __( 'Food heading', 'vila-akoya' ) ),
				vila_akoya_block_textarea_field( 'field_vila_b_cf_food_body', 'food_body', __( 'Food body', 'vila-akoya' ) ),
			),
		),
		array(
			'key'      => 'group_vila_block_text_image',
			'title'    => 'Block: Text + image',
			'block'    => 'acf/vila-text-image',
			'fields'   => array(
				vila_akoya_block_text_field( 'field_vila_b_text_image_layout', 'layout', __( 'Layout (home|band|village|campsite|kids-cta|plain)', 'vila-akoya' ) ),
				vila_akoya_block_image_field( 'field_vila_b_text_image_image', 'image', __( 'Image', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_text_image_badge', 'badge_text', __( 'Image badge text', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_text_image_heading', 'heading', __( 'Heading', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_text_image_heading_accent', 'heading_accent', __( 'Heading accent line', 'vila-akoya' ) ),
				vila_akoya_block_wysiwyg_field( 'field_vila_b_text_image_body', 'body', __( 'Body', 'vila-akoya' ) ),
				vila_akoya_block_link_field( 'field_vila_b_text_image_link', 'link', __( 'Link', 'vila-akoya' ) ),
			),
		),
		array(
			'key'      => 'group_vila_block_people',
			'title'    => 'Block: People',
			'block'    => 'acf/vila-people',
			'fields'   => array(
				vila_akoya_block_text_field( 'field_vila_b_people_heading', 'heading', __( 'Heading', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_people_intro', 'intro', __( 'Intro', 'vila-akoya' ) ),
				array(
					'key'          => 'field_vila_b_people_items',
					'label'        => __( 'People', 'vila-akoya' ),
					'name'         => 'people',
					'type'         => 'repeater',
					'layout'       => 'block',
					'button_label' => __( 'Add person', 'vila-akoya' ),
					'sub_fields'   => array(
						vila_akoya_block_text_field( 'field_vila_b_person_name', 'name', __( 'Name', 'vila-akoya' ) ),
						vila_akoya_block_image_field( 'field_vila_b_person_image', 'image', __( 'Image', 'vila-akoya' ) ),
						vila_akoya_block_wysiwyg_field( 'field_vila_b_person_bio', 'bio', __( 'Bio', 'vila-akoya' ) ),
						vila_akoya_block_text_field( 'field_vila_b_person_variant', 'variant', __( 'Shape variant', 'vila-akoya' ) ),
					),
				),
				vila_akoya_block_link_field( 'field_vila_b_people_link', 'link', __( 'Footer link', 'vila-akoya' ) ),
			),
		),
		array(
			'key'      => 'group_vila_block_image_card_grid',
			'title'    => 'Block: Image card grid',
			'block'    => 'acf/vila-image-card-grid',
			'fields'   => array(
				vila_akoya_block_text_field( 'field_vila_b_cards_layout', 'layout', __( 'Layout (default|village|campsite|kids)', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_cards_heading', 'heading', __( 'Heading', 'vila-akoya' ) ),
				vila_akoya_block_textarea_field( 'field_vila_b_cards_intro', 'intro', __( 'Intro', 'vila-akoya' ) ),
				array(
					'key'          => 'field_vila_b_cards_items',
					'label'        => __( 'Cards', 'vila-akoya' ),
					'name'         => 'cards',
					'type'         => 'repeater',
					'layout'       => 'block',
					'button_label' => __( 'Add card', 'vila-akoya' ),
					'sub_fields'   => array(
						vila_akoya_block_image_field( 'field_vila_b_card_image', 'image', __( 'Image', 'vila-akoya' ) ),
						vila_akoya_block_text_field( 'field_vila_b_card_icon', 'icon', __( 'Icon (village)', 'vila-akoya' ) ),
						vila_akoya_block_text_field( 'field_vila_b_card_heading', 'heading', __( 'Heading', 'vila-akoya' ) ),
						vila_akoya_block_textarea_field( 'field_vila_b_card_body', 'body', __( 'Body', 'vila-akoya' ) ),
						vila_akoya_block_link_field( 'field_vila_b_card_link', 'link', __( 'Link', 'vila-akoya' ) ),
					),
				),
			),
		),
		array(
			'key'      => 'group_vila_block_gallery',
			'title'    => 'Block: Gallery',
			'block'    => 'acf/vila-gallery',
			'fields'   => array(
				vila_akoya_block_text_field( 'field_vila_b_gallery_layout', 'layout', __( 'Layout (campsite|village)', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_gallery_heading', 'heading', __( 'Heading', 'vila-akoya' ) ),
				array(
					'key'           => 'field_vila_b_gallery_images',
					'label'         => __( 'Images', 'vila-akoya' ),
					'name'          => 'images',
					'type'          => 'gallery',
					'return_format' => 'array',
					'preview_size'  => 'medium',
					'instructions'  => __( 'Village layout: image 1 = left square, image 2 = top-right, image 3 = bottom-right (offset).', 'vila-akoya' ),
				),
				vila_akoya_block_text_field( 'field_vila_b_gallery_caption_1', 'caption_1', __( 'Caption under image 1 (left)', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_gallery_caption_2', 'caption_2', __( 'Caption under image 2', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_gallery_caption_3', 'caption_3', __( 'Caption under image 3', 'vila-akoya' ) ),
			),
		),
		array(
			'key'      => 'group_vila_block_newsletter',
			'title'    => 'Block: Newsletter',
			'block'    => 'acf/vila-newsletter',
			'fields'   => array(
				vila_akoya_block_text_field( 'field_vila_b_newsletter_layout', 'layout', __( 'Layout (home|inquiry|contact|campus)', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_newsletter_heading', 'heading', __( 'Heading', 'vila-akoya' ) ),
				vila_akoya_block_textarea_field( 'field_vila_b_newsletter_body', 'body', __( 'Body', 'vila-akoya' ) ),
				vila_akoya_block_textarea_field( 'field_vila_b_newsletter_shortcode', 'form_shortcode', __( 'Form shortcode', 'vila-akoya' ) ),
				vila_akoya_block_link_field( 'field_vila_b_newsletter_helper_link', 'helper_link', __( 'Helper link', 'vila-akoya' ) ),
				vila_akoya_block_image_field( 'field_vila_b_newsletter_image', 'image', __( 'Image', 'vila-akoya' ) ),
			),
		),
		array(
			'key'      => 'group_vila_block_prose',
			'title'    => 'Block: Prose',
			'block'    => 'acf/vila-prose',
			'fields'   => array(
				vila_akoya_block_text_field( 'field_vila_b_prose_layout', 'layout', __( 'Layout (plain|skew)', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_prose_heading', 'heading', __( 'Heading', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_prose_heading_accent', 'heading_accent', __( 'Heading accent', 'vila-akoya' ) ),
				vila_akoya_block_textarea_field( 'field_vila_b_prose_lede', 'lede', __( 'Lede / definition', 'vila-akoya' ) ),
				vila_akoya_block_wysiwyg_field( 'field_vila_b_prose_body', 'body', __( 'Body', 'vila-akoya' ) ),
			),
		),
		array(
			'key'    => 'group_vila_block_page_hero',
			'title'  => 'Block: Page hero',
			'block'  => 'acf/vila-page-hero',
			'fields' => array(
				vila_akoya_block_text_field( 'field_vila_b_page_hero_layout', 'layout', __( 'Layout (default|contact|village|campus|kids)', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_page_hero_eyebrow', 'eyebrow', __( 'Eyebrow', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_page_hero_heading', 'heading', __( 'Heading', 'vila-akoya' ) ),
				vila_akoya_block_textarea_field( 'field_vila_b_page_hero_body', 'body', __( 'Body', 'vila-akoya' ) ),
				vila_akoya_block_image_field( 'field_vila_b_page_hero_image', 'image', __( 'Image', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_page_hero_badge', 'badge_text', __( 'Badge text', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_page_hero_heading_style', 'heading_style', __( 'Heading style (headline|barrio)', 'vila-akoya' ) ),
				vila_akoya_block_link_field( 'field_vila_b_page_hero_primary_link', 'primary_link', __( 'Primary button', 'vila-akoya' ) ),
				vila_akoya_block_link_field( 'field_vila_b_page_hero_secondary_link', 'secondary_link', __( 'Secondary button', 'vila-akoya' ) ),
			),
		),
		array(
			'key'    => 'group_vila_block_cta_band',
			'title'  => 'Block: CTA band',
			'block'  => 'acf/vila-cta-band',
			'fields' => array(
				vila_akoya_block_text_field( 'field_vila_b_cta_layout', 'layout', __( 'Layout (default|about|campsite)', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_cta_icon', 'icon', __( 'Icon name (Material Symbols)', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_cta_eyebrow', 'eyebrow', __( 'Eyebrow', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_cta_heading', 'heading', __( 'Heading', 'vila-akoya' ) ),
				vila_akoya_block_textarea_field( 'field_vila_b_cta_body', 'body', __( 'Body', 'vila-akoya' ) ),
				vila_akoya_block_link_field( 'field_vila_b_cta_primary_link', 'primary_link', __( 'Primary button', 'vila-akoya' ) ),
				vila_akoya_block_link_field( 'field_vila_b_cta_secondary_link', 'secondary_link', __( 'Secondary button', 'vila-akoya' ) ),
			),
		),
		array(
			'key'    => 'group_vila_block_icon_card_grid',
			'title'  => 'Block: Icon card grid',
			'block'  => 'acf/vila-icon-card-grid',
			'fields' => array(
				vila_akoya_block_text_field( 'field_vila_b_icon_cards_layout', 'layout', __( 'Layout (default|contact|campus)', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_icon_cards_heading', 'heading', __( 'Heading', 'vila-akoya' ) ),
				vila_akoya_block_textarea_field( 'field_vila_b_icon_cards_intro', 'intro', __( 'Intro', 'vila-akoya' ) ),
				array(
					'key'          => 'field_vila_b_icon_cards_items',
					'label'        => __( 'Cards', 'vila-akoya' ),
					'name'         => 'cards',
					'type'         => 'repeater',
					'layout'       => 'block',
					'button_label' => __( 'Add card', 'vila-akoya' ),
					'sub_fields'   => array(
						vila_akoya_block_text_field( 'field_vila_b_icon_card_icon', 'icon', __( 'Icon', 'vila-akoya' ) ),
						vila_akoya_block_text_field( 'field_vila_b_icon_card_heading', 'heading', __( 'Heading', 'vila-akoya' ) ),
						vila_akoya_block_textarea_field( 'field_vila_b_icon_card_body', 'body', __( 'Body', 'vila-akoya' ) ),
						vila_akoya_block_link_field( 'field_vila_b_icon_card_link', 'link', __( 'Link', 'vila-akoya' ) ),
						vila_akoya_block_text_field( 'field_vila_b_icon_card_variant', 'variant', __( 'Variant (email|whatsapp|phone)', 'vila-akoya' ) ),
					),
				),
			),
		),
		array(
			'key'    => 'group_vila_block_masonry_spotlight',
			'title'  => 'Block: Masonry spotlight',
			'block'  => 'acf/vila-masonry-spotlight',
			'fields' => array(
				vila_akoya_block_text_field( 'field_vila_b_masonry_heading', 'heading', __( 'Heading', 'vila-akoya' ) ),
				vila_akoya_block_wysiwyg_field( 'field_vila_b_masonry_body', 'body', __( 'Body', 'vila-akoya' ) ),
				vila_akoya_block_image_field( 'field_vila_b_masonry_image', 'image', __( 'Image', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_masonry_card_1_icon', 'card_1_icon', __( 'Card 1 icon', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_masonry_card_1_title', 'card_1_title', __( 'Card 1 title', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_masonry_card_2_icon', 'card_2_icon', __( 'Card 2 icon', 'vila-akoya' ) ),
				vila_akoya_block_text_field( 'field_vila_b_masonry_card_2_title', 'card_2_title', __( 'Card 2 title', 'vila-akoya' ) ),
			),
		),
	);

	foreach ( $groups as $group ) {
		$block = $group['block'];
		unset( $group['block'] );
		$group['location'] = array(
			array(
				array(
					'param'    => 'block',
					'operator' => '==',
					'value'    => $block,
				),
			),
		);
		$group['active'] = true;
		acf_add_local_field_group( $group );
	}
}

/**
 * Whether post content contains Vila section blocks.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function vila_akoya_page_has_section_blocks( $post_id = 0 ) {
	$post_id = $post_id ? (int) $post_id : (int) get_the_ID();
	if ( $post_id <= 0 ) {
		return false;
	}
	$content = (string) get_post_field( 'post_content', $post_id );
	return str_contains( $content, '<!-- wp:acf/vila-' );
}

/**
 * Build ACF block attribute data with underscore field-key entries.
 *
 * @param array<string, mixed>  $values     Field name => value.
 * @param array<string, string> $field_keys Field name => field key.
 * @return array<string, mixed>
 */
function vila_akoya_acf_block_data( array $values, array $field_keys ) {
	$data = array();
	foreach ( $values as $name => $value ) {
		if ( ! isset( $field_keys[ $name ] ) ) {
			continue;
		}
		$data[ $name ]            = $value;
		$data[ '_' . $name ]      = $field_keys[ $name ];
	}
	return $data;
}

/**
 * Serialize one ACF section block.
 *
 * @param string               $block_name Block name without acf/ prefix, e.g. vila-home-hero.
 * @param array<string, mixed> $data       ACF data attrs (with underscore keys).
 * @return string
 */
function vila_akoya_serialize_acf_section_block( $block_name, array $data ) {
	$block = array(
		'blockName'    => 'acf/' . $block_name,
		'attrs'        => array(
			'name' => 'acf/' . $block_name,
			'data' => $data,
			'mode' => 'preview',
		),
		'innerBlocks'  => array(),
		'innerHTML'    => '',
		'innerContent' => array(),
	);
	return serialize_block( $block );
}

/**
 * Resolve a remote/CDN image URL to a local attachment ID (find or sideload).
 *
 * @param string $url Image URL.
 * @return int Attachment ID or 0.
 */
function vila_akoya_seed_image_attachment_id( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return 0;
	}

	static $cache = array();
	if ( isset( $cache[ $url ] ) ) {
		return $cache[ $url ];
	}

	$filename = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
	if ( '' === $filename ) {
		$cache[ $url ] = 0;
		return 0;
	}

	global $wpdb;
	$like = '%' . $wpdb->esc_like( $filename );
	$id   = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
			 WHERE p.post_type = 'attachment' AND pm.meta_value LIKE %s
			 ORDER BY p.ID DESC LIMIT 1",
			$like
		)
	);

	if ( $id <= 0 ) {
		$base = pathinfo( $filename, PATHINFO_FILENAME );
		$ext  = pathinfo( $filename, PATHINFO_EXTENSION );
		$rows = $wpdb->get_results(
			"SELECT p.ID, pm.meta_value AS attached_file
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
			 WHERE p.post_type = 'attachment'"
		);
		foreach ( (array) $rows as $row ) {
			$attached = basename( (string) $row->attached_file );
			if ( preg_match( '/^' . preg_quote( $base, '/' ) . '(?:-[0-9]+)?(?:-scaled)?\.' . preg_quote( $ext, '/' ) . '$/i', $attached ) ) {
				$id = (int) $row->ID;
				break;
			}
		}
	}

	if ( $id <= 0 ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$sideloaded = media_sideload_image( $url, 0, null, 'id' );
		if ( ! is_wp_error( $sideloaded ) ) {
			$id = (int) $sideloaded;
		}
	}

	$cache[ $url ] = $id;
	return $id;
}

/**
 * Recursively convert image URL strings in seed data to attachment IDs.
 *
 * @param mixed $value Seed value.
 * @return mixed
 */
function vila_akoya_seed_resolve_images( $value ) {
	if ( is_string( $value ) && preg_match( '#^https?://.+\\.(jpe?g|png|gif|webp)(\\?.*)?$#i', $value ) ) {
		$id = vila_akoya_seed_image_attachment_id( $value );
		return $id > 0 ? $id : $value;
	}
	if ( is_array( $value ) ) {
		// Link fields: leave url/title/target alone.
		if ( isset( $value['url'] ) && ( isset( $value['title'] ) || isset( $value['target'] ) ) ) {
			return $value;
		}
		foreach ( $value as $k => $v ) {
			$value[ $k ] = vila_akoya_seed_resolve_images( $v );
		}
	}
	return $value;
}

/**
 * Seed Home page content as visual SCF section blocks.
 *
 * @return int|WP_Error Home page ID or error.
 */
function vila_akoya_seed_home_section_blocks() {
	return vila_akoya_seed_page_section_blocks( 'home' );
}

/**
 * Seed a brochure page with visual SCF section blocks.
 *
 * @param string $slug Page slug (home, about, village, …).
 * @return int|WP_Error Page ID or error.
 */
function vila_akoya_seed_page_section_blocks( $slug ) {
	if ( ! function_exists( 'acf_register_block_type' ) ) {
		return new WP_Error( 'acf_missing', __( 'Secure Custom Fields (or ACF) must be active before seeding section blocks.', 'vila-akoya' ) );
	}

	$slug = sanitize_title( (string) $slug );
	$page = get_page_by_path( $slug, OBJECT, 'page' );
	if ( ! $page instanceof WP_Post ) {
		return new WP_Error( 'page_missing', sprintf( /* translators: %s: page slug */ __( 'Page “%s” not found.', 'vila-akoya' ), $slug ) );
	}

	if ( ! function_exists( 'vila_akoya_page_section_rows' ) ) {
		require_once VILA_AKOYA_DIR . '/inc/page-section-data.php';
	}

	$rows = vila_akoya_page_section_rows( $slug );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}

	$rows = vila_akoya_seed_resolve_images( $rows );
	if ( ! is_array( $rows ) || empty( $rows ) ) {
		return new WP_Error( 'seed_rows', __( 'Could not build section rows for this page.', 'vila-akoya' ) );
	}

	$blocks = array();
	foreach ( $rows as $row ) {
		$layout = (string) ( $row['acf_fc_layout'] ?? '' );
		unset( $row['acf_fc_layout'] );
		if ( 'legacy_html' === $layout ) {
			$html = (string) ( $row['html'] ?? '' );
			if ( '' !== trim( $html ) ) {
				$blocks[] = '<!-- wp:html -->' . "\n" . $html . "\n" . '<!-- /wp:html -->';
			}
			continue;
		}
		$serialized = vila_akoya_section_row_to_acf_block( $layout, $row );
		if ( '' !== $serialized ) {
			$blocks[] = $serialized;
		}
	}

	$content = implode( "\n\n", $blocks );
	wp_update_post(
		wp_slash(
			array(
				'ID'           => $page->ID,
				'post_content' => $content,
			)
		)
	);

	if ( function_exists( 'delete_field' ) ) {
		delete_field( 'field_vila_page_sections', $page->ID );
		delete_field( VILA_AKOYA_SECTIONS_FIELD, $page->ID );
	}

	clean_post_cache( $page->ID );
	return (int) $page->ID;
}

/**
 * Seed every brochure page that has section data.
 *
 * @return array{ok: array<string, int>, errors: array<string, string>}
 */
function vila_akoya_seed_all_page_section_blocks() {
	if ( ! function_exists( 'vila_akoya_page_section_slugs' ) ) {
		require_once VILA_AKOYA_DIR . '/inc/page-section-data.php';
	}

	$result = array(
		'ok'     => array(),
		'errors' => array(),
	);

	foreach ( vila_akoya_page_section_slugs() as $slug ) {
		$seeded = vila_akoya_seed_page_section_blocks( $slug );
		if ( is_wp_error( $seeded ) ) {
			$result['errors'][ $slug ] = $seeded->get_error_message();
		} else {
			$result['ok'][ $slug ] = (int) $seeded;
		}
	}

	return $result;
}

/**
 * Convert a seed row into a serialized ACF block.
 *
 * @param string               $layout Layout name.
 * @param array<string, mixed> $row    Field values (images already resolved to IDs).
 * @return string
 */
function vila_akoya_section_row_to_acf_block( $layout, array $row ) {
	$map = vila_akoya_section_block_field_map();

	if ( empty( $map[ $layout ] ) ) {
		return '';
	}

	$def  = $map[ $layout ];
	$data = vila_akoya_acf_block_data( $row, $def['keys'] );

	if ( ! empty( $def['repeater_keys'] ) ) {
		foreach ( $def['repeater_keys'] as $rep_name => $sub_keys ) {
			$items = isset( $row[ $rep_name ] ) && is_array( $row[ $rep_name ] ) ? $row[ $rep_name ] : array();
			unset( $data[ $rep_name ], $data[ '_' . $rep_name ] );
			$data[ $rep_name ]       = count( $items );
			$data[ '_' . $rep_name ] = $def['keys'][ $rep_name ];
			foreach ( $items as $i => $item ) {
				foreach ( $sub_keys as $sub_name => $sub_key ) {
					$data[ "{$rep_name}_{$i}_{$sub_name}" ]  = $item[ $sub_name ] ?? '';
					$data[ "_{$rep_name}_{$i}_{$sub_name}" ] = $sub_key;
				}
			}
		}
	}

	return vila_akoya_serialize_acf_section_block( $def['block'], $data );
}

/**
 * @deprecated Use vila_akoya_section_row_to_acf_block().
 *
 * @param string               $layout Layout name.
 * @param array<string, mixed> $row    Row data.
 * @return string
 */
function vila_akoya_home_row_to_acf_block( $layout, array $row ) {
	return vila_akoya_section_row_to_acf_block( $layout, $row );
}

/**
 * Field-key map for every section layout.
 *
 * @return array<string, array<string, mixed>>
 */
function vila_akoya_section_block_field_map() {
	return array(
		'home_hero'       => array(
			'block' => 'vila-home-hero',
			'keys'  => array(
				'image'          => 'field_vila_b_home_hero_image',
				'heading'        => 'field_vila_b_home_hero_heading',
				'body'           => 'field_vila_b_home_hero_body',
				'primary_link'   => 'field_vila_b_home_hero_primary_link',
				'secondary_link' => 'field_vila_b_home_hero_secondary_link',
			),
		),
		'cover_hero'      => array(
			'block' => 'vila-cover-hero',
			'keys'  => array(
				'image'        => 'field_vila_b_cover_hero_image',
				'heading'      => 'field_vila_b_cover_hero_heading',
				'body'         => 'field_vila_b_cover_hero_body',
				'primary_link' => 'field_vila_b_cover_hero_primary_link',
			),
		),
		'campsite_facilities' => array(
			'block' => 'vila-campsite-facilities',
			'keys'  => array(
				'heading'          => 'field_vila_b_cf_heading',
				'intro'            => 'field_vila_b_cf_intro',
				'river_image'      => 'field_vila_b_cf_river_image',
				'river_heading'    => 'field_vila_b_cf_river_heading',
				'river_body'       => 'field_vila_b_cf_river_body',
				'workshop_image'   => 'field_vila_b_cf_workshop_image',
				'workshop_heading' => 'field_vila_b_cf_workshop_heading',
				'workshop_body'    => 'field_vila_b_cf_workshop_body',
				'workshop_link'    => 'field_vila_b_cf_workshop_link',
				'little_image'     => 'field_vila_b_cf_little_image',
				'little_heading'   => 'field_vila_b_cf_little_heading',
				'little_body'      => 'field_vila_b_cf_little_body',
				'little_link'      => 'field_vila_b_cf_little_link',
				'trails_heading'   => 'field_vila_b_cf_trails_heading',
				'trails_body'      => 'field_vila_b_cf_trails_body',
				'food_heading'     => 'field_vila_b_cf_food_heading',
				'food_body'        => 'field_vila_b_cf_food_body',
			),
		),
		'feature_split'   => array(
			'block' => 'vila-feature-split',
			'keys'  => array(
				'left_eyebrow'  => 'field_vila_b_feature_left_eyebrow',
				'left_heading'  => 'field_vila_b_feature_left_heading',
				'left_body'     => 'field_vila_b_feature_left_body',
				'left_image'    => 'field_vila_b_feature_left_image',
				'left_link'     => 'field_vila_b_feature_left_link',
				'right_eyebrow' => 'field_vila_b_feature_right_eyebrow',
				'right_heading' => 'field_vila_b_feature_right_heading',
				'right_body'    => 'field_vila_b_feature_right_body',
				'right_image'   => 'field_vila_b_feature_right_image',
				'right_link'    => 'field_vila_b_feature_right_link',
			),
		),
		'text_image'      => array(
			'block' => 'vila-text-image',
			'keys'  => array(
				'layout'         => 'field_vila_b_text_image_layout',
				'image'          => 'field_vila_b_text_image_image',
				'badge_text'     => 'field_vila_b_text_image_badge',
				'heading'        => 'field_vila_b_text_image_heading',
				'heading_accent' => 'field_vila_b_text_image_heading_accent',
				'body'           => 'field_vila_b_text_image_body',
				'link'           => 'field_vila_b_text_image_link',
			),
		),
		'people'          => array(
			'block'         => 'vila-people',
			'keys'          => array(
				'heading' => 'field_vila_b_people_heading',
				'intro'   => 'field_vila_b_people_intro',
				'people'  => 'field_vila_b_people_items',
				'link'    => 'field_vila_b_people_link',
			),
			'repeater_keys' => array(
				'people' => array(
					'name'    => 'field_vila_b_person_name',
					'image'   => 'field_vila_b_person_image',
					'bio'     => 'field_vila_b_person_bio',
					'variant' => 'field_vila_b_person_variant',
				),
			),
		),
		'image_card_grid' => array(
			'block'         => 'vila-image-card-grid',
			'keys'          => array(
				'layout'  => 'field_vila_b_cards_layout',
				'heading' => 'field_vila_b_cards_heading',
				'intro'   => 'field_vila_b_cards_intro',
				'cards'   => 'field_vila_b_cards_items',
			),
			'repeater_keys' => array(
				'cards' => array(
					'image'   => 'field_vila_b_card_image',
					'icon'    => 'field_vila_b_card_icon',
					'heading' => 'field_vila_b_card_heading',
					'body'    => 'field_vila_b_card_body',
					'link'    => 'field_vila_b_card_link',
				),
			),
		),
		'gallery'         => array(
			'block' => 'vila-gallery',
			'keys'  => array(
				'layout'    => 'field_vila_b_gallery_layout',
				'heading'   => 'field_vila_b_gallery_heading',
				'images'    => 'field_vila_b_gallery_images',
				'caption_1' => 'field_vila_b_gallery_caption_1',
				'caption_2' => 'field_vila_b_gallery_caption_2',
				'caption_3' => 'field_vila_b_gallery_caption_3',
			),
		),
		'newsletter'      => array(
			'block' => 'vila-newsletter',
			'keys'  => array(
				'layout'         => 'field_vila_b_newsletter_layout',
				'heading'        => 'field_vila_b_newsletter_heading',
				'body'           => 'field_vila_b_newsletter_body',
				'form_shortcode' => 'field_vila_b_newsletter_shortcode',
				'helper_link'    => 'field_vila_b_newsletter_helper_link',
				'image'          => 'field_vila_b_newsletter_image',
			),
		),
		'prose'           => array(
			'block' => 'vila-prose',
			'keys'  => array(
				'layout'         => 'field_vila_b_prose_layout',
				'heading'        => 'field_vila_b_prose_heading',
				'heading_accent' => 'field_vila_b_prose_heading_accent',
				'lede'           => 'field_vila_b_prose_lede',
				'body'           => 'field_vila_b_prose_body',
			),
		),
		'page_hero'       => array(
			'block' => 'vila-page-hero',
			'keys'  => array(
				'layout'         => 'field_vila_b_page_hero_layout',
				'eyebrow'        => 'field_vila_b_page_hero_eyebrow',
				'heading'        => 'field_vila_b_page_hero_heading',
				'body'           => 'field_vila_b_page_hero_body',
				'image'          => 'field_vila_b_page_hero_image',
				'badge_text'     => 'field_vila_b_page_hero_badge',
				'heading_style'  => 'field_vila_b_page_hero_heading_style',
				'primary_link'   => 'field_vila_b_page_hero_primary_link',
				'secondary_link' => 'field_vila_b_page_hero_secondary_link',
			),
		),
		'cta_band'        => array(
			'block' => 'vila-cta-band',
			'keys'  => array(
				'layout'         => 'field_vila_b_cta_layout',
				'icon'           => 'field_vila_b_cta_icon',
				'eyebrow'        => 'field_vila_b_cta_eyebrow',
				'heading'        => 'field_vila_b_cta_heading',
				'body'           => 'field_vila_b_cta_body',
				'primary_link'   => 'field_vila_b_cta_primary_link',
				'secondary_link' => 'field_vila_b_cta_secondary_link',
			),
		),
		'icon_card_grid'  => array(
			'block'         => 'vila-icon-card-grid',
			'keys'          => array(
				'layout'  => 'field_vila_b_icon_cards_layout',
				'heading' => 'field_vila_b_icon_cards_heading',
				'intro'   => 'field_vila_b_icon_cards_intro',
				'cards'   => 'field_vila_b_icon_cards_items',
			),
			'repeater_keys' => array(
				'cards' => array(
					'icon'    => 'field_vila_b_icon_card_icon',
					'heading' => 'field_vila_b_icon_card_heading',
					'body'    => 'field_vila_b_icon_card_body',
					'link'    => 'field_vila_b_icon_card_link',
					'variant' => 'field_vila_b_icon_card_variant',
				),
			),
		),
		'masonry_spotlight' => array(
			'block' => 'vila-masonry-spotlight',
			'keys'  => array(
				'heading'      => 'field_vila_b_masonry_heading',
				'body'         => 'field_vila_b_masonry_body',
				'image'        => 'field_vila_b_masonry_image',
				'card_1_icon'  => 'field_vila_b_masonry_card_1_icon',
				'card_1_title' => 'field_vila_b_masonry_card_1_title',
				'card_2_icon'  => 'field_vila_b_masonry_card_2_icon',
				'card_2_title' => 'field_vila_b_masonry_card_2_title',
			),
		),
	);
}
