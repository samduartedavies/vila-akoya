<?php
/**
 * Block patterns sourced from inc/page-content/*.html (brochure pages).
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Brochure page slug => human label for pattern titles.
 *
 * @return array<string, string>
 */
function vila_akoya_page_content_pattern_map() {
	return array(
		'home'      => __( 'Home', 'vila-akoya' ),
		'about'     => __( 'About', 'vila-akoya' ),
		'village'   => __( 'The Village', 'vila-akoya' ),
		'campsite'  => __( 'The Campsite', 'vila-akoya' ),
		'booking'   => __( 'Booking', 'vila-akoya' ),
		'campus'    => __( 'The Campus', 'vila-akoya' ),
		'the-kids'  => __( 'The Kids', 'vila-akoya' ),
		'contact'   => __( 'Contact', 'vila-akoya' ),
		'thank-you' => __( 'Thank You', 'vila-akoya' ),
		'blog'      => __( 'Blog', 'vila-akoya' ),
	);
}

/**
 * Prepare brochure HTML for use as a block pattern (forms, shortcodes).
 *
 * @param string $html Raw block HTML from inc/page-content/.
 * @return string
 */
function vila_akoya_prepare_page_content_pattern( $html ) {
	$html = is_string( $html ) ? $html : '';
	if ( '' === $html ) {
		return '';
	}

	$cf7 = function_exists( 'vila_akoya_get_cf7_form_ids' ) ? vila_akoya_get_cf7_form_ids() : array();

	if ( function_exists( 'vila_akoya_seed_replace_cf7_placeholders' ) ) {
		$html = vila_akoya_seed_replace_cf7_placeholders( $html, $cf7 );
	}
	if ( function_exists( 'vila_akoya_seed_replace_wc_placeholders' ) ) {
		$html = vila_akoya_seed_replace_wc_placeholders( $html );
	}
	if ( function_exists( 'vila_akoya_absolutize_brochure_hrefs' ) ) {
		$html = vila_akoya_absolutize_brochure_hrefs( $html );
	}

	return $html;
}

/**
 * Extract insertable section blocks from a page content file.
 *
 * @param string $content Prepared block HTML.
 * @return list<array{title:string,content:string}>
 */
function vila_akoya_extract_page_content_sections( $content ) {
	$blocks = parse_blocks( $content );
	$blocks = array_values(
		array_filter(
			$blocks,
			static function ( $block ) {
				return ! empty( $block['blockName'] );
			}
		)
	);

	if ( 1 === count( $blocks ) && 'core/group' === ( $blocks[0]['blockName'] ?? '' ) ) {
		$inner = $blocks[0]['innerBlocks'] ?? array();
		$inner = array_values(
			array_filter(
				$inner,
				static function ( $block ) {
					return ! empty( $block['blockName'] );
				}
			)
		);
		if ( ! empty( $inner ) ) {
			$blocks = $inner;
		}
	}

	$sections = array();
	foreach ( $blocks as $index => $block ) {
		$heading = vila_akoya_pattern_title_from_block( $block );
		$sections[] = array(
			'title'   => $heading
				? $heading
				: sprintf(
					/* translators: %d: section number */
					__( 'Section %d', 'vila-akoya' ),
					$index + 1
				),
			'content' => serialize_block( $block ),
		);
	}

	return $sections;
}

/**
 * Find the first heading text inside a parsed block (for pattern titles).
 *
 * @param array<string, mixed> $block Parsed block.
 * @return string
 */
function vila_akoya_pattern_title_from_block( array $block ) {
	if ( 'core/heading' === ( $block['blockName'] ?? '' ) ) {
		$text = trim( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ) );
		if ( '' !== $text ) {
			return wp_html_excerpt( $text, 56, '…' );
		}
	}

	foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $inner ) {
		if ( ! is_array( $inner ) ) {
			continue;
		}
		$title = vila_akoya_pattern_title_from_block( $inner );
		if ( '' !== $title ) {
			return $title;
		}
	}

	return '';
}

/**
 * Register full-page and section patterns from brochure page content files.
 */
function vila_akoya_register_page_content_block_patterns() {
	register_block_pattern_category(
		'vila-akoya-pages',
		array(
			'label' => __( 'Vila Akoya — Full pages', 'vila-akoya' ),
		)
	);

	register_block_pattern_category(
		'vila-akoya-sections',
		array(
			'label' => __( 'Vila Akoya — Page sections', 'vila-akoya' ),
		)
	);

	$dir = VILA_AKOYA_DIR . '/inc/page-content/';

	foreach ( vila_akoya_page_content_pattern_map() as $slug => $label ) {
		$path = $dir . $slug . '-blocks.html';
		if ( ! is_readable( $path ) ) {
			continue;
		}

		$raw     = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = vila_akoya_prepare_page_content_pattern( $raw );
		if ( '' === trim( $content ) ) {
			continue;
		}

		register_block_pattern(
			'vila-akoya/page-full-' . $slug,
			array(
				'title'         => sprintf(
					/* translators: %s: page name */
					__( 'Full page: %s', 'vila-akoya' ),
					$label
				),
				'description'   => sprintf(
					/* translators: %s: page name */
					__( 'Complete layout from the %s page.', 'vila-akoya' ),
					$label
				),
				'content'       => $content,
				'categories'    => array( 'vila-akoya', 'vila-akoya-pages' ),
				'postTypes'     => array( 'page' ),
				'templateTypes' => array( 'page' ),
				'inserter'      => true,
				'keywords'      => array( $slug, $label, 'vila', 'akoya', 'full page' ),
			)
		);

		$sections = vila_akoya_extract_page_content_sections( $content );
		foreach ( $sections as $index => $section ) {
			register_block_pattern(
				'vila-akoya/page-section-' . $slug . '-' . ( $index + 1 ),
				array(
					'title'         => $label . ' — ' . $section['title'],
					'description'   => sprintf(
						/* translators: 1: page name, 2: section title */
						__( 'Section from %1$s: %2$s', 'vila-akoya' ),
						$label,
						$section['title']
					),
					'content'       => $section['content'],
					'categories'    => array( 'vila-akoya', 'vila-akoya-sections' ),
					'postTypes'     => array( 'page' ),
					'templateTypes' => array( 'page' ),
					'inserter'      => true,
					'keywords'      => array( $slug, $label, 'section', 'vila', 'akoya' ),
				)
			);
		}
	}

	if ( function_exists( 'vila_akoya_default_page_block_markup' ) ) {
		register_block_pattern(
			'vila-akoya/default-page-template',
			array(
				'title'         => __( 'Default page (hero + prose)', 'vila-akoya' ),
				'description'   => __( 'Village-style hero with Contact title font and a post-style content area below.', 'vila-akoya' ),
				'content'       => vila_akoya_default_page_block_markup(),
				'categories'    => array( 'vila-akoya', 'vila-akoya-pages' ),
				'postTypes'     => array( 'page' ),
				'templateTypes' => array( 'page' ),
				'inserter'      => true,
				'keywords'      => array( 'default', 'hero', 'prose', 'template' ),
			)
		);
	}
}
