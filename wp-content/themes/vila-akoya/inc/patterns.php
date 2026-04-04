<?php
/**
 * Block patterns (optional inserts in the editor).
 *
 * @package VilaAkoya
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	function () {
		register_block_pattern_category(
			'vila-akoya',
			array( 'label' => __( 'Vila Akoya', 'vila-akoya' ) )
		);

		register_block_pattern(
			'vila-akoya/hero-cta',
			array(
				'title'      => __( 'Hero: headline + two buttons', 'vila-akoya' ),
				'categories' => array( 'vila-akoya' ),
				'content'    => '<!-- wp:group {"className":"max-w-4xl space-y-6","layout":{"type":"constrained"}} -->
<div class="wp-block-group max-w-4xl space-y-6">
<!-- wp:heading {"level":1,"className":"text-5xl md:text-8xl font-barrio"} -->
<h1 class="text-5xl md:text-8xl font-barrio">Your headline</h1>
<!-- /wp:heading -->
<!-- wp:buttons -->
<div class="wp-block-buttons">
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Primary</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button">Secondary</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->',
			)
		);

		register_block_pattern(
			'vila-akoya/cropped-landscape-image',
			array(
				'title'       => __( 'Image: fixed landscape frame (16∶9)', 'vila-akoya' ),
				'description' => __( 'Replace the image in the block; any photo is cropped to the same shape.', 'vila-akoya' ),
				'categories'    => array( 'vila-akoya' ),
				'content'       => '<!-- wp:group {"className":"vila-crop-tout","layout":{"type":"constrained"}} -->
<div class="wp-block-group vila-crop-tout">
<!-- wp:image {"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img alt="" src=""/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:group -->',
			)
		);
	}
);
