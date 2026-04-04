<?php
/**
 * Helpers for building block markup for seeded pages.
 *
 * Crop layout classes (Group wrapping Image — add under Advanced → Additional CSS class(es)):
 * `vila-crop-tout` (16∶9), `vila-crop-editorial` (4∶5), `vila-hero-shell` + `vila-hero-shell__media` + `vila-hero-shell__overlay` (home hero).
 * Theme CSS also fills figures that use Tailwind `aspect-*` or `h-*` on the Image block (brochure-style cover).
 *
 * @package VilaAkoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode block for Contact Form 7.
 *
 * @param int $form_id CF7 post ID.
 * @return string
 */
function vila_akoya_block_cf7( $form_id ) {
	$form_id = (int) $form_id;
	if ( $form_id < 1 ) {
		return '<!-- wp:paragraph --><p>' . esc_html__( 'Add a Contact Form 7 form and place its shortcode here.', 'vila-akoya' ) . '</p><!-- /wp:paragraph -->';
	}
	return sprintf(
		"<!-- wp:shortcode -->\n[contact-form-7 id=\"%d\"]\n<!-- /wp:shortcode -->",
		$form_id
	);
}

/**
 * Image block (full URL).
 *
 * @param string $url Image URL.
 * @param string $alt Alt text.
 * @param string $class Optional figure class.
 * @return string
 */
function vila_akoya_block_image( $url, $alt = '', $class = '' ) {
	$url = esc_url( $url );
	$alt = esc_attr( $alt );
	$class = esc_attr( trim( 'wp-block-image size-full ' . $class ) );
	return <<<BLOCK
<!-- wp:image {"sizeSlug":"full","linkDestination":"none","className":""} -->
<figure class="$class"><img src="$url" alt="$alt"/></figure>
<!-- /wp:image -->
BLOCK;
}
