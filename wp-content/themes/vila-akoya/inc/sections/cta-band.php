<?php
/**
 * Centered CTA band with optional icon, body, and two links.
 *
 * Variants (layout): default | about | campsite | thank-you
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

$layout         = (string) vila_akoya_section_field( 'layout', 'default' );
$eyebrow        = (string) vila_akoya_section_field( 'eyebrow', '' );
$heading        = (string) vila_akoya_section_field( 'heading', '' );
$body           = (string) vila_akoya_section_field( 'body', '' );
$icon           = (string) vila_akoya_section_field( 'icon', '' );
$primary_link   = vila_akoya_section_field( 'primary_link', false );
$secondary_link = vila_akoya_section_field( 'secondary_link', false );
$has_primary    = is_array( $primary_link ) && ! empty( $primary_link['url'] );
$has_secondary  = is_array( $secondary_link ) && ! empty( $secondary_link['url'] );

if ( 'campsite' === $layout ) :
	?>
<section class="wp-block-group vila-reveal vila-dappled-surface py-32 px-8 text-center relative overflow-hidden bg-surface-container-low mb-0">
	<div class="absolute -top-40 -left-40 w-96 h-96 bg-primary/5 rounded-full blur-3xl pointer-events-none" aria-hidden="true"></div>
	<div class="absolute -bottom-40 -right-40 w-96 h-96 bg-secondary/5 rounded-full blur-3xl pointer-events-none" aria-hidden="true"></div>
	<div class="wp-block-group max-w-[50rem] mx-auto space-y-10 relative z-10">
		<?php if ( '' !== $heading ) : ?>
			<h2 class="wp-block-heading text-5xl md:text-7xl font-normal text-primary font-headline leading-none"><?php echo esc_html( $heading ); ?></h2>
		<?php endif; ?>
		<?php if ( '' !== $body ) : ?>
			<p class="max-w-2xl mx-auto text-xl font-light text-on-surface-variant"><?php echo esc_html( $body ); ?></p>
		<?php endif; ?>
		<?php if ( $has_primary || $has_secondary ) : ?>
			<p class="flex flex-col sm:flex-row gap-6 justify-center items-center mb-0">
				<?php if ( $has_primary ) : ?>
					<?php vila_akoya_section_link( $primary_link, 'Book', 'w-full sm:w-auto px-12 py-5 bg-primary text-on-primary rounded-xl font-bold text-lg editorial-shadow transition-transform hover:scale-105 active:scale-95 inline-block text-center' ); ?>
				<?php endif; ?>
				<?php if ( $has_secondary ) : ?>
					<?php vila_akoya_section_link( $secondary_link, 'Village', 'w-full sm:w-auto px-12 py-5 border-2 border-primary text-primary rounded-xl font-bold text-lg hover:bg-primary/5 transition-colors inline-block text-center' ); ?>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</div>
</section>
	<?php
	return;
endif;

if ( 'about' === $layout ) :
	?>
<section class="wp-block-group vila-reveal max-w-4xl mx-auto px-8 text-center py-20 mb-0">
	<p class="mb-6"><span class="material-symbols-outlined text-6xl text-primary" style="font-variation-settings:'FILL' 1" aria-hidden="true"><?php echo esc_html( $icon ? $icon : 'favorite' ); ?></span></p>
	<?php if ( '' !== $heading ) : ?>
		<h2 class="wp-block-heading font-headline text-5xl text-on-surface mb-8"><?php echo esc_html( $heading ); ?></h2>
	<?php endif; ?>
	<?php if ( '' !== $body ) : ?>
		<p class="font-body text-xl text-on-surface-variant leading-relaxed"><?php echo esc_html( $body ); ?></p>
	<?php endif; ?>
	<?php if ( $has_primary || $has_secondary ) : ?>
		<div class="wp-block-buttons mt-12 flex flex-wrap justify-center gap-6">
			<?php if ( $has_primary ) : ?>
				<div class="wp-block-button is-style-fill">
					<?php vila_akoya_section_link( $primary_link, 'Village', 'wp-block-button__link wp-element-button bg-primary text-on-primary px-10 py-4 rounded-xl font-medium shadow-lg hover:shadow-primary/20 transition-all' ); ?>
				</div>
			<?php endif; ?>
			<?php if ( $has_secondary ) : ?>
				<div class="wp-block-button is-style-fill">
					<?php vila_akoya_section_link( $secondary_link, 'Campsite', 'wp-block-button__link wp-element-button bg-surface-container-high text-on-surface px-10 py-4 rounded-xl font-medium transition-all' ); ?>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</section>
	<?php
	return;
endif;
?>
<section class="wp-block-group vila-reveal max-w-[1236px] mx-auto box-border px-8 mb-32 md:mb-40">
	<div class="wp-block-group text-center space-y-8 max-w-3xl mx-auto py-12 md:py-20">
		<?php if ( '' !== $icon ) : ?>
			<p class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-secondary/15 text-secondary mx-auto mb-0">
				<span class="material-symbols-outlined text-4xl" aria-hidden="true"><?php echo esc_html( $icon ); ?></span>
			</p>
		<?php endif; ?>
		<?php if ( '' !== $eyebrow ) : ?>
			<p class="text-primary font-label uppercase tracking-[0.2em] text-xs"><?php echo esc_html( $eyebrow ); ?></p>
		<?php endif; ?>
		<?php if ( '' !== $heading ) : ?>
			<h2 class="wp-block-heading font-headline text-4xl md:text-6xl text-on-surface leading-tight"><?php echo esc_html( $heading ); ?></h2>
		<?php endif; ?>
		<?php if ( '' !== $body ) : ?>
			<p class="font-body text-lg md:text-xl text-on-surface-variant leading-relaxed"><?php echo esc_html( $body ); ?></p>
		<?php endif; ?>
		<?php if ( $has_primary || $has_secondary ) : ?>
			<div class="wp-block-buttons flex flex-col sm:flex-row gap-4 justify-center pt-4">
				<?php if ( $has_primary ) : ?>
					<div class="wp-block-button is-style-fill">
						<?php vila_akoya_section_link( $primary_link, 'Continue', 'wp-block-button__link wp-element-button rounded-full bg-primary text-white px-8 py-3 font-medium hover:bg-primary-container transition-colors' ); ?>
					</div>
				<?php endif; ?>
				<?php if ( $has_secondary ) : ?>
					<div class="wp-block-button is-style-outline">
						<?php vila_akoya_section_link( $secondary_link, 'Learn more', 'wp-block-button__link wp-element-button rounded-full border-2 border-primary/30 text-primary px-8 py-3 font-medium hover:bg-primary/5 transition-colors' ); ?>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
</section>
