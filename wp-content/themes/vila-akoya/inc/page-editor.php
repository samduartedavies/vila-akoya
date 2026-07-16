<?php
/**
 * Page editor defaults and guardrails.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default block markup for new pages: Village-style hero + post-style prose body.
 *
 * @return string
 */
function vila_akoya_default_page_block_markup() {
	return <<<'HTML'
<!-- wp:group {"tagName":"div","className":"vila-page-inner pt-10 md:pt-28","layout":{"type":"default"}} -->
<div class="wp-block-group vila-page-inner pt-10 md:pt-28">

<!-- wp:group {"tagName":"section","className":"flex flex-col justify-center px-8 mb-20 relative pb-12 md:pb-0 max-w-[1236px] mx-auto box-border","layout":{"type":"default"}} -->
<section class="wp-block-group flex flex-col justify-center px-8 mb-20 relative pb-12 md:pb-0 max-w-[1236px] mx-auto box-border">
<!-- wp:columns {"className":"gap-6 items-end md:items-center vila-columns-stretch"} -->
<div class="wp-block-columns gap-6 items-end md:items-center vila-columns-stretch">
<!-- wp:column {"width":"58.33%"} -->
<div class="wp-block-column" style="flex-basis:58.33%">
<!-- wp:heading {"level":1,"className":"font-headline text-6xl md:text-8xl text-on-surface leading-tight mb-8","placeholder":"Page title"} -->
<h1 class="wp-block-heading font-headline text-6xl md:text-8xl text-on-surface leading-tight mb-8">Page title</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-headline text-2xl md:text-3xl italic text-tertiary max-w-xl mb-12","placeholder":"A short introduction for this page."} -->
<p class="font-headline text-2xl md:text-3xl italic text-tertiary max-w-xl mb-12">A short introduction for this page.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column {"width":"41.66%","className":"relative"} -->
<div class="wp-block-column relative" style="flex-basis:41.66%">
<!-- wp:group {"className":"aspect-[4/5] rounded-xl overflow-hidden editorial-shadow","layout":{"type":"default"}} -->
<div class="wp-block-group aspect-[4/5] rounded-xl overflow-hidden editorial-shadow">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"vila-img-fill"} -->
<figure class="wp-block-image size-large vila-img-fill"><img alt="Hero image"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</section>
<!-- /wp:group -->

<!-- wp:group {"tagName":"section","className":"vila-page-prose max-w-[720px] mx-auto px-8 pb-12 md:pb-16","layout":{"type":"default"}} -->
<section class="wp-block-group vila-page-prose max-w-[720px] mx-auto px-8 pb-12 md:pb-16">
<!-- wp:paragraph {"placeholder":"Write your page content here…"} -->
<p></p>
<!-- /wp:paragraph -->
</section>
<!-- /wp:group -->

</div>
<!-- /wp:group -->
HTML;
}

/**
 * Whether page content is empty or only contains disallowed nav blocks.
 *
 * @param string $content Post content.
 * @return bool
 */
function vila_akoya_page_content_needs_default( $content ) {
	$content = trim( (string) $content );

	if ( '' === $content ) {
		return true;
	}

	if (
		str_contains( $content, '[vila_akoya_header_nav' )
		|| str_contains( $content, '[vila_akoya_footer_nav' )
	) {
		return true;
	}

	if ( ! has_blocks( $content ) ) {
		return '' === trim( wp_strip_all_tags( $content ) );
	}

	$blocks = parse_blocks( $content );
	$blocks = vila_akoya_filter_disallowed_page_blocks( $blocks );

	return '' === trim( vila_akoya_serialize_filtered_page_blocks( $blocks ) );
}

/**
 * Seed default block content for new auto-draft pages before insert.
 *
 * @param array<string, mixed> $data    Post data.
 * @param array<string, mixed> $postarr Raw post data.
 * @return array<string, mixed>
 */
function vila_akoya_seed_new_page_insert_data( $data, $postarr ) {
	if ( empty( $data['post_type'] ) || 'page' !== $data['post_type'] ) {
		return $data;
	}

	if ( ! empty( $postarr['ID'] ) ) {
		return $data;
	}

	if ( empty( $data['post_status'] ) || 'auto-draft' !== $data['post_status'] ) {
		return $data;
	}

	if ( vila_akoya_page_content_needs_default( (string) ( $data['post_content'] ?? '' ) ) ) {
		$data['post_content'] = vila_akoya_default_page_block_markup();
	}

	return $data;
}
add_filter( 'wp_insert_post_data', 'vila_akoya_seed_new_page_insert_data', 5, 2 );

/**
 * Default content for new pages (classic + block editor fallbacks).
 *
 * @param string  $content Default content.
 * @param WP_Post $post    Post object.
 * @return string
 */
function vila_akoya_default_page_content( $content, $post ) {
	if ( $post instanceof WP_Post && 'page' === $post->post_type && vila_akoya_page_content_needs_default( $content ) ) {
		return vila_akoya_default_page_block_markup();
	}

	return $content;
}
add_filter( 'default_content', 'vila_akoya_default_page_content', 10, 2 );

/**
 * Seed default block content when the REST API creates a new auto-draft page.
 *
 * @param WP_Post         $post     Inserted post.
 * @param WP_REST_Request $request  Request.
 * @param bool            $creating True when creating.
 */
function vila_akoya_seed_new_page_content( $post, $request, $creating ) {
	unset( $request );

	if ( ! $creating || ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
		return;
	}

	if ( 'auto-draft' !== $post->post_status ) {
		return;
	}

	if ( ! vila_akoya_page_content_needs_default( $post->post_content ) ) {
		return;
	}

	wp_update_post(
		array(
			'ID'           => $post->ID,
			'post_content' => vila_akoya_default_page_block_markup(),
		)
	);
}
add_action( 'rest_after_insert_page', 'vila_akoya_seed_new_page_content', 10, 3 );

/**
 * Remove header/footer shortcodes and template parts from page content on save.
 *
 * @param array<string, mixed> $data    Post data.
 * @param array<string, mixed> $postarr Raw post data.
 * @return array<string, mixed>
 */
function vila_akoya_sanitize_page_editor_content( $data, $postarr ) {
	$post_id = ! empty( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
	$content = isset( $data['post_content'] ) ? (string) $data['post_content'] : '';
	// Visual Vila Section blocks are the page content — never replace them with defaults.
	if ( ! empty( $data['post_type'] ) && 'page' === $data['post_type'] && str_contains( $content, '<!-- wp:acf/vila-' ) ) {
		return $data;
	}
	if ( ! empty( $data['post_type'] ) && 'page' === $data['post_type'] && $post_id > 0 && function_exists( 'vila_akoya_page_has_sections' ) && vila_akoya_page_has_sections( $post_id ) && ! str_contains( $content, '<!-- wp:acf/vila-' ) ) {
		// Legacy Flexible Content pages keep empty block content.
		if ( empty( $content ) || vila_akoya_page_content_needs_default( $content ) ) {
			$data['post_content'] = '';
		}
		return $data;
	}

	unset( $postarr );
	if ( empty( $data['post_type'] ) || 'page' !== $data['post_type'] ) {
		return $data;
	}
	if ( empty( $data['post_content'] ) || ! is_string( $data['post_content'] ) ) {
		if ( vila_akoya_page_content_needs_default( (string) ( $data['post_content'] ?? '' ) ) ) {
			$data['post_content'] = vila_akoya_default_page_block_markup();
		}
		return $data;
	}

	if (
		str_contains( $data['post_content'], '[vila_akoya_header_nav' )
		|| str_contains( $data['post_content'], '[vila_akoya_footer_nav' )
	) {
		$data['post_content'] = vila_akoya_serialize_filtered_page_blocks(
			vila_akoya_filter_disallowed_page_blocks( parse_blocks( $data['post_content'] ) )
		);
	}

	if ( ! has_blocks( $data['post_content'] ) ) {
		if ( vila_akoya_page_content_needs_default( $data['post_content'] ) ) {
			$data['post_content'] = vila_akoya_default_page_block_markup();
		}
		return $data;
	}

	$clean = vila_akoya_serialize_filtered_page_blocks(
		vila_akoya_filter_disallowed_page_blocks( parse_blocks( $data['post_content'] ) )
	);

	if ( vila_akoya_page_content_needs_default( $clean ) ) {
		$clean = vila_akoya_default_page_block_markup();
	}

	$data['post_content'] = $clean;
	return $data;
}
add_filter( 'wp_insert_post_data', 'vila_akoya_sanitize_page_editor_content', 20, 2 );

/**
 * Recursively drop nav shortcodes and template parts from page blocks.
 *
 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
 * @return array<int, array<string, mixed>>
 */
function vila_akoya_filter_disallowed_page_blocks( array $blocks ) {
	$out = array();

	foreach ( $blocks as $block ) {
		if ( empty( $block['blockName'] ) ) {
			continue;
		}

		if ( 'core/template-part' === $block['blockName'] ) {
			continue;
		}

		if ( 'core/shortcode' === $block['blockName'] ) {
			$shortcode = trim( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ) );
			if (
				str_contains( $shortcode, '[vila_akoya_header_nav' )
				|| str_contains( $shortcode, '[vila_akoya_footer_nav' )
			) {
				continue;
			}
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = vila_akoya_filter_disallowed_page_blocks( $block['innerBlocks'] );
		}

		$out[] = $block;
	}

	return $out;
}

/**
 * Serialize blocks, skipping empty parse artifacts.
 *
 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
 * @return string
 */
function vila_akoya_serialize_filtered_page_blocks( array $blocks ) {
	$parts = array();
	foreach ( $blocks as $block ) {
		if ( empty( $block['blockName'] ) ) {
			continue;
		}
		$parts[] = serialize_block( $block );
	}
	return implode( "\n\n", $parts );
}

/**
 * Whether the Site Editor is open on the Front Page template (not the Home page).
 *
 * @return bool
 */
function vila_akoya_is_site_editor_front_page_template() {
	global $pagenow;
	if ( 'site-editor.php' !== $pagenow ) {
		return false;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$path = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['path'] ) ) : '';
	if ( str_contains( $path, 'front-page' ) ) {
		return true;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$post_id = isset( $_GET['postId'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['postId'] ) ) : '';
	if ( str_contains( $post_id, 'front-page' ) ) {
		return true;
	}

	return false;
}

/**
 * Send editors to Pages → Home instead of the Front Page template.
 *
 * The template only wraps header/footer around post content. Brochure copy and
 * images live on the page (or in Page Sections), so editing the template shows
 * shortcodes and a broken post-content block by design.
 */
function vila_akoya_redirect_front_page_template_editor() {
	if ( ! is_admin() || ! vila_akoya_is_site_editor_front_page_template() ) {
		return;
	}

	$home_id = (int) get_option( 'page_on_front' );
	if ( $home_id <= 0 || ! current_user_can( 'edit_page', $home_id ) ) {
		return;
	}

	wp_safe_redirect( admin_url( 'post.php?post=' . $home_id . '&action=edit' ) );
	exit;
}
add_action( 'admin_init', 'vila_akoya_redirect_front_page_template_editor', 1 );

/**
 * Front page "Edit" links should open the page editor, not the site template.
 *
 * @param string  $link    Edit link.
 * @param int     $post_id Post ID.
 * @param string  $context Link context.
 * @return string
 */
function vila_akoya_front_page_edit_link( $link, $post_id, $context ) {
	unset( $context );
	if ( $post_id > 0 && 'page' === get_post_type( $post_id ) && (int) get_option( 'page_on_front' ) === (int) $post_id ) {
		return admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' );
	}
	return $link;
}
add_filter( 'get_edit_post_link', 'vila_akoya_front_page_edit_link', 10, 3 );

/**
 * Page editor: content only (no header/footer template preview).
 *
 * Template mode wraps page blocks inside core/post-content in the site
 * template, which breaks pattern drag-and-drop and confuses editors.
 *
 * @param array<string, mixed> $settings Block editor settings.
 * @param WP_Block_Editor_Context $block_editor_context Editor context.
 * @return array<string, mixed>
 */
function vila_akoya_page_block_editor_settings( $settings, $block_editor_context ) {
	$post = $block_editor_context->post ?? null;
	if ( $post instanceof WP_Post && 'page' === $post->post_type ) {
		$settings['supportsTemplateMode'] = false;
	}

	return $settings;
}
add_filter( 'block_editor_settings_all', 'vila_akoya_page_block_editor_settings', 20, 2 );
