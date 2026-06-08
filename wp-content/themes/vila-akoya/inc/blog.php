<?php
/**
 * Blog posts page helpers.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render block content from the designated Posts page (page_for_posts).
 *
 * On /blog/, home.html uses the main post query, so core/post-content outputs
 * the latest post instead of the static page intro. This shortcode fixes that.
 *
 * @return string
 */
function vila_akoya_shortcode_posts_page_intro() {
	$page_id = (int) get_option( 'page_for_posts' );
	if ( ! $page_id ) {
		return '';
	}

	$page = get_post( $page_id );
	if ( ! $page instanceof WP_Post || 'page' !== $page->post_type ) {
		return '';
	}

	$content = (string) $page->post_content;
	if ( '' === trim( $content ) ) {
		return '';
	}

	return (string) apply_filters( 'the_content', $content );
}
add_shortcode( 'vila_akoya_posts_page_intro', 'vila_akoya_shortcode_posts_page_intro' );
