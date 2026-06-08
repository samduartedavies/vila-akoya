<?php
/**
 * Reusable block patterns matching brochure page styling.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/block-patterns-pages.php';

/**
 * Register Vila Akoya block pattern category and patterns.
 */
function vila_akoya_register_block_patterns() {
	register_block_pattern_category(
		'vila-akoya',
		array(
			'label' => __( 'Vila Akoya', 'vila-akoya' ),
		)
	);

	$patterns = array(
		'hero-section'           => array(
			'title'       => __( 'Hero Section', 'vila-akoya' ),
			'description' => __( 'Label, headline, intro text and optional hero image.', 'vila-akoya' ),
			'content'     => vila_akoya_pattern_hero_section(),
		),
		'two-column-text-image'  => array(
			'title'       => __( 'Two Column Text + Image', 'vila-akoya' ),
			'description' => __( 'Content column alongside a rounded portrait image.', 'vila-akoya' ),
			'content'     => vila_akoya_pattern_two_column_text_image(),
		),
		'feature-card-grid'      => array(
			'title'       => __( 'Feature Card Grid', 'vila-akoya' ),
			'description' => __( 'Three rounded feature cards with images.', 'vila-akoya' ),
			'content'     => vila_akoya_pattern_feature_card_grid(),
		),
		'cta-section'            => array(
			'title'       => __( 'CTA Section', 'vila-akoya' ),
			'description' => __( 'Heading, paragraph and primary button.', 'vila-akoya' ),
			'content'     => vila_akoya_pattern_cta_section(),
		),
		'full-width-image-banner' => array(
			'title'       => __( 'Full-Width Image Banner', 'vila-akoya' ),
			'description' => __( 'Edge-to-edge image with overlay text.', 'vila-akoya' ),
			'content'     => vila_akoya_pattern_full_width_image_banner(),
		),
		'newsletter-section'     => array(
			'title'       => __( 'Newsletter Section', 'vila-akoya' ),
			'description' => __( 'Dark background section with newsletter signup.', 'vila-akoya' ),
			'content'     => vila_akoya_pattern_newsletter_section(),
		),
	);

	foreach ( $patterns as $slug => $def ) {
		register_block_pattern(
			'vila-akoya/' . $slug,
			array(
				'title'         => $def['title'],
				'description'   => $def['description'],
				'content'       => $def['content'],
				'categories'    => array( 'vila-akoya' ),
				'postTypes'     => array( 'page', 'post' ),
				'templateTypes' => array( 'page', 'single' ),
				'inserter'      => true,
			)
		);
	}

	vila_akoya_register_page_content_block_patterns();
}
add_action( 'init', 'vila_akoya_register_block_patterns' );

/**
 * Hero section pattern markup.
 *
 * @return string
 */
function vila_akoya_pattern_hero_section() {
	return <<<'HTML'
<!-- wp:group {"tagName":"div","className":"vila-page-inner pt-0 pb-20","layout":{"type":"default"}} -->
<div class="wp-block-group vila-page-inner pt-0 pb-20">
<!-- wp:group {"tagName":"section","className":"flex flex-col justify-center max-w-[1236px] box-border mx-auto px-8 py-12 md:py-0 mb-32","layout":{"type":"default"}} -->
<section class="wp-block-group flex flex-col justify-center max-w-[1236px] box-border mx-auto px-8 py-12 md:py-0 mb-32">
<!-- wp:columns {"className":"gap-12 items-center vila-columns-stretch"} -->
<div class="wp-block-columns gap-12 items-center vila-columns-stretch">
<!-- wp:column {"width":"58.33%","className":"z-10"} -->
<div class="wp-block-column z-10" style="flex-basis:58.33%">
<!-- wp:paragraph {"className":"font-label text-primary uppercase tracking-[0.2em] text-sm font-bold mb-4"} -->
<p class="font-label text-primary uppercase tracking-[0.2em] text-sm font-bold mb-4">Section label</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":1,"className":"font-headline text-6xl md:text-8xl text-on-surface leading-[1.1] mb-8"} -->
<h1 class="wp-block-heading font-headline text-6xl md:text-8xl text-on-surface leading-[1.1] mb-8">Your page headline</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-body text-xl text-on-surface-variant max-w-xl leading-relaxed"} -->
<p class="font-body text-xl text-on-surface-variant max-w-xl leading-relaxed">Introduce your page with a short, welcoming paragraph that sets the tone.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column {"width":"41.66%","className":"relative"} -->
<div class="wp-block-column relative" style="flex-basis:41.66%">
<!-- wp:group {"className":"rounded-xl overflow-hidden aspect-[4/5] shadow-2xl","layout":{"type":"default"}} -->
<div class="wp-block-group rounded-xl overflow-hidden aspect-[4/5] shadow-2xl">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"w-full h-full object-cover"} -->
<figure class="wp-block-image size-large w-full h-full object-cover"><img alt="Hero image"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</section>
<!-- /wp:group -->
</div>
<!-- /wp:group -->
HTML;
}

/**
 * Two-column text + image pattern markup.
 *
 * @return string
 */
function vila_akoya_pattern_two_column_text_image() {
	return <<<'HTML'
<!-- wp:group {"tagName":"section","className":"bg-surface-container-low py-32 rounded-xl mx-8 mb-32","layout":{"type":"default"}} -->
<section class="wp-block-group bg-surface-container-low py-32 rounded-xl mx-8 mb-32">
<!-- wp:group {"className":"max-w-7xl mx-auto px-8","layout":{"type":"default"}} -->
<div class="wp-block-group max-w-7xl mx-auto px-8">
<!-- wp:columns {"className":"gap-20 items-center vila-columns-stretch"} -->
<div class="wp-block-columns gap-20 items-center vila-columns-stretch">
<!-- wp:column {"className":"order-2 md:order-1"} -->
<div class="wp-block-column order-2 md:order-1">
<!-- wp:group {"className":"rounded-lg overflow-hidden h-[500px]","layout":{"type":"default"}} -->
<div class="wp-block-group rounded-lg overflow-hidden h-[500px]">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"w-full h-full object-cover"} -->
<figure class="wp-block-image size-large w-full h-full object-cover"><img alt="Section image"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
<!-- wp:column {"className":"order-1 md:order-2"} -->
<div class="wp-block-column order-1 md:order-2">
<!-- wp:heading {"level":2,"className":"font-headline text-5xl text-on-surface mb-8"} -->
<h2 class="wp-block-heading font-headline text-5xl text-on-surface mb-8">Section heading</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-body text-lg text-on-surface-variant leading-relaxed mb-6"} -->
<p class="font-body text-lg text-on-surface-variant leading-relaxed mb-6">Add your content here. This layout mirrors the about and village pages — image on one side, copy on the other.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"font-body text-lg text-on-surface-variant leading-relaxed"} -->
<p class="font-body text-lg text-on-surface-variant leading-relaxed">Use a second paragraph for more detail, or replace with a list or buttons.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->
</section>
<!-- /wp:group -->
HTML;
}

/**
 * Feature card grid pattern markup.
 *
 * @return string
 */
function vila_akoya_pattern_feature_card_grid() {
	return <<<'HTML'
<!-- wp:group {"tagName":"section","className":"max-w-[1236px] box-border mx-auto px-8 mb-32","layout":{"type":"default"}} -->
<section class="wp-block-group max-w-[1236px] box-border mx-auto px-8 mb-32">
<!-- wp:heading {"textAlign":"center","level":2,"className":"font-headline text-4xl md:text-5xl text-on-surface mb-12"} -->
<h2 class="wp-block-heading has-text-align-center font-headline text-4xl md:text-5xl text-on-surface mb-12">Features</h2>
<!-- /wp:heading -->
<!-- wp:columns {"className":"gap-8 vila-columns-stretch"} -->
<div class="wp-block-columns gap-8 vila-columns-stretch">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:group {"className":"rounded-xl overflow-hidden bg-surface-container-low p-6 h-full","layout":{"type":"default"}} -->
<div class="wp-block-group rounded-xl overflow-hidden bg-surface-container-low p-6 h-full">
<!-- wp:image {"aspectRatio":"4/3","scale":"cover","sizeSlug":"large","linkDestination":"none","className":"rounded-lg mb-6"} -->
<figure class="wp-block-image size-large rounded-lg mb-6"><img alt="Feature one"/></figure>
<!-- /wp:image -->
<!-- wp:heading {"level":3,"className":"font-headline text-2xl text-on-surface mb-3"} -->
<h3 class="wp-block-heading font-headline text-2xl text-on-surface mb-3">Feature one</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-body text-on-surface-variant leading-relaxed"} -->
<p class="font-body text-on-surface-variant leading-relaxed">Describe this feature in a sentence or two.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:group {"className":"rounded-xl overflow-hidden bg-surface-container-low p-6 h-full","layout":{"type":"default"}} -->
<div class="wp-block-group rounded-xl overflow-hidden bg-surface-container-low p-6 h-full">
<!-- wp:image {"aspectRatio":"4/3","scale":"cover","sizeSlug":"large","linkDestination":"none","className":"rounded-lg mb-6"} -->
<figure class="wp-block-image size-large rounded-lg mb-6"><img alt="Feature two"/></figure>
<!-- /wp:image -->
<!-- wp:heading {"level":3,"className":"font-headline text-2xl text-on-surface mb-3"} -->
<h3 class="wp-block-heading font-headline text-2xl text-on-surface mb-3">Feature two</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-body text-on-surface-variant leading-relaxed"} -->
<p class="font-body text-on-surface-variant leading-relaxed">Describe this feature in a sentence or two.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:group {"className":"rounded-xl overflow-hidden bg-surface-container-low p-6 h-full","layout":{"type":"default"}} -->
<div class="wp-block-group rounded-xl overflow-hidden bg-surface-container-low p-6 h-full">
<!-- wp:image {"aspectRatio":"4/3","scale":"cover","sizeSlug":"large","linkDestination":"none","className":"rounded-lg mb-6"} -->
<figure class="wp-block-image size-large rounded-lg mb-6"><img alt="Feature three"/></figure>
<!-- /wp:image -->
<!-- wp:heading {"level":3,"className":"font-headline text-2xl text-on-surface mb-3"} -->
<h3 class="wp-block-heading font-headline text-2xl text-on-surface mb-3">Feature three</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-body text-on-surface-variant leading-relaxed"} -->
<p class="font-body text-on-surface-variant leading-relaxed">Describe this feature in a sentence or two.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</section>
<!-- /wp:group -->
HTML;
}

/**
 * CTA section pattern markup.
 *
 * @return string
 */
function vila_akoya_pattern_cta_section() {
	return <<<'HTML'
<!-- wp:group {"tagName":"section","className":"max-w-[1236px] box-border mx-auto px-8 mb-32","layout":{"type":"default"}} -->
<section class="wp-block-group max-w-[1236px] box-border mx-auto px-8 mb-32">
<!-- wp:group {"className":"rounded-[3rem] bg-secondary-container px-8 py-16 md:px-16 md:py-20 text-center","layout":{"type":"default"}} -->
<div class="wp-block-group rounded-[3rem] bg-secondary-container px-8 py-16 md:px-16 md:py-20 text-center">
<!-- wp:heading {"textAlign":"center","level":2,"className":"font-headline text-4xl md:text-5xl text-on-secondary-container mb-6"} -->
<h2 class="wp-block-heading has-text-align-center font-headline text-4xl md:text-5xl text-on-secondary-container mb-6">Ready to visit?</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","className":"font-body text-lg text-on-secondary-container/90 max-w-xl mx-auto mb-10 leading-relaxed"} -->
<p class="has-text-align-center font-body text-lg text-on-secondary-container/90 max-w-xl mx-auto mb-10 leading-relaxed">Invite visitors to take the next step — book a stay, get in touch, or learn more.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons">
<!-- wp:button {"className":"is-style-fill"} -->
<div class="wp-block-button is-style-fill"><a class="wp-block-button__link wp-element-button" href="/booking">Book your stay</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
</section>
<!-- /wp:group -->
HTML;
}

/**
 * Full-width image banner pattern markup.
 *
 * @return string
 */
function vila_akoya_pattern_full_width_image_banner() {
	return <<<'HTML'
<!-- wp:group {"tagName":"section","className":"relative mb-32 mx-8 rounded-xl overflow-hidden min-h-[28rem] md:min-h-[36rem] flex items-end","layout":{"type":"default"}} -->
<section class="wp-block-group relative mb-32 mx-8 rounded-xl overflow-hidden min-h-[28rem] md:min-h-[36rem] flex items-end">
<!-- wp:cover {"url":"","dimRatio":40,"overlayColor":"primary","isUserOverlayColor":true,"minHeight":28,"minHeightUnit":"rem","contentPosition":"bottom left","className":"w-full min-h-[28rem] md:min-h-[36rem]"} -->
<div class="wp-block-cover w-full min-h-[28rem] md:min-h-[36rem]"><span aria-hidden="true" class="wp-block-cover__background has-primary-background-color has-background-dim-40 has-background-dim"></span><div class="wp-block-cover__inner-container">
<!-- wp:group {"className":"max-w-[1236px] mx-auto px-8 py-12 md:py-16 w-full","layout":{"type":"default"}} -->
<div class="wp-block-group max-w-[1236px] mx-auto px-8 py-12 md:py-16 w-full">
<!-- wp:heading {"level":2,"className":"font-headline text-4xl md:text-6xl text-white mb-4"} -->
<h2 class="wp-block-heading font-headline text-4xl md:text-6xl text-white mb-4">Banner headline</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-body text-lg md:text-xl text-white/90 max-w-xl leading-relaxed"} -->
<p class="font-body text-lg md:text-xl text-white/90 max-w-xl leading-relaxed">Overlay text on a full-width image. Replace the cover image in the block settings.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
</div></div>
<!-- /wp:cover -->
</section>
<!-- /wp:group -->
HTML;
}

/**
 * Newsletter section pattern markup.
 *
 * @return string
 */
function vila_akoya_pattern_newsletter_section() {
	$cf7 = function_exists( 'vila_akoya_get_cf7_form_ids' ) ? vila_akoya_get_cf7_form_ids() : array();
	$id  = isset( $cf7['newsletter_home'] ) ? (string) $cf7['newsletter_home'] : '';
	$form = '' !== $id
		? sprintf( '[contact-form-7 id="%s" html_class="vila-cf7 vila-cf7--newsletter"]', esc_attr( $id ) )
		: '{{cf7:newsletter_home}}';

	return '<!-- wp:group {"tagName":"section","className":"va-home-digi-family bg-primary rounded-xl mx-8 mb-32 overflow-hidden","layout":{"type":"default"}} -->
<section class="wp-block-group va-home-digi-family bg-primary rounded-xl mx-8 mb-32 overflow-hidden">
<!-- wp:columns {"className":"vila-split-flush va-home-digi-family gap-0 items-stretch"} -->
<div class="wp-block-columns vila-split-flush va-home-digi-family gap-0 items-stretch">
<!-- wp:column {"width":"66.66%","className":"px-8 py-16 md:px-16 md:py-20 flex flex-col justify-center"} -->
<div class="wp-block-column px-8 py-16 md:px-16 md:py-20 flex flex-col justify-center" style="flex-basis:66.66%">
<!-- wp:heading {"level":2,"className":"font-headline text-4xl md:text-5xl text-on-primary mb-4"} -->
<h2 class="wp-block-heading font-headline text-4xl md:text-5xl text-on-primary mb-4">Stay in the loop</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"font-body text-lg text-on-primary/90 mb-8 max-w-lg leading-relaxed"} -->
<p class="font-body text-lg text-on-primary/90 mb-8 max-w-lg leading-relaxed">Sign up for occasional updates about Vila Akoya — openings, events and village life.</p>
<!-- /wp:paragraph -->
<!-- wp:shortcode -->
' . $form . '
<!-- /wp:shortcode -->
</div>
<!-- /wp:column -->
<!-- wp:column {"width":"33.33%","className":"va-home-digi-family__media hidden md:block"} -->
<div class="wp-block-column va-home-digi-family__media hidden md:block" style="flex-basis:33.33%">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none","className":"vila-img-fill w-full h-full object-cover"} -->
<figure class="wp-block-image size-large vila-img-fill w-full h-full object-cover"><img alt="Newsletter"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</section>
<!-- /wp:group -->';
}
