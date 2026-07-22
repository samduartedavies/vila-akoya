<?php
/**
 * Inner-page hero: copy + portrait image (optional eyebrow, badge, CTAs).
 *
 * Variants (layout field):
 * - default: about-style columns with optional badge
 * - contact: 12-col grid, landscape image, glass “with love” badge
 * - campus: barrio heading + CTAs
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

$layout         = (string) vila_akoya_section_field( 'layout', 'default' );
$eyebrow        = (string) vila_akoya_section_field( 'eyebrow', '' );
$heading        = (string) vila_akoya_section_field( 'heading', 'Page title' );
$body           = (string) vila_akoya_section_field( 'body', '' );
$image          = vila_akoya_section_field( 'image', '' );
$badge_text     = (string) vila_akoya_section_field( 'badge_text', '' );
$primary_link   = vila_akoya_section_field( 'primary_link', false );
$secondary_link = vila_akoya_section_field( 'secondary_link', false );
$heading_style  = (string) vila_akoya_section_field( 'heading_style', 'headline' );
$heading_class  = 'barrio' === $heading_style
	? 'font-barrio text-6xl md:text-8xl text-primary leading-none mb-8 -rotate-1 origin-left'
	: 'font-headline text-6xl md:text-8xl text-on-surface leading-[1.1] mb-8';

$has_primary   = is_array( $primary_link ) && ! empty( $primary_link['url'] );
$has_secondary = is_array( $secondary_link ) && ! empty( $secondary_link['url'] );

if ( 'contact' === $layout ) :
	?>
<section class="wp-block-group vila-motion-hero max-w-[1236px] mx-auto box-border px-8 grid grid-cols-1 lg:grid-cols-12 gap-12 items-center content-center pb-28">
	<div class="wp-block-group lg:col-span-5 z-10">
		<?php if ( '' !== $eyebrow ) : ?>
			<p class="font-label text-primary tracking-[0.2em] uppercase text-sm mb-4" data-vila-hero-item style="--vila-hero-i:0"><?php echo esc_html( $eyebrow ); ?></p>
		<?php endif; ?>
		<h1 class="wp-block-heading font-headline text-6xl md:text-8xl text-on-surface leading-tight mb-8" data-vila-hero-item style="--vila-hero-i:1"><?php echo esc_html( $heading ); ?></h1>
		<?php if ( '' !== $body ) : ?>
			<p class="font-body text-xl text-on-surface-variant max-w-md leading-relaxed mb-12" data-vila-hero-item style="--vila-hero-i:2"><?php echo esc_html( $body ); ?></p>
		<?php endif; ?>
	</div>
	<div class="wp-block-group lg:col-span-7 relative" data-vila-hero-item style="--vila-hero-i:3">
		<div class="wp-block-group vila-motion-hero-frame overflow-hidden aspect-[4/5] md:aspect-[16/10] shadow-2xl relative">
			<?php vila_akoya_section_image( $image, 'wp-block-image size-large vila-img-fill', $heading ); ?>
		</div>
		<?php if ( '' !== $badge_text ) : ?>
			<div class="wp-block-group absolute -bottom-10 -left-10 md:left-20 bg-surface-container-lowest/90 backdrop-blur-xl p-6 rounded-lg shadow-xl max-w-sm hidden md:block">
				<div class="wp-block-group flex items-start gap-4">
					<p class="mb-0"><span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">favorite</span></p>
					<div class="wp-block-group mt-0">
						<p class="font-label text-xs uppercase tracking-widest text-secondary mb-1">With love</p>
						<p class="font-headline text-lg italic mb-0 mt-0"><?php echo esc_html( $badge_text ); ?></p>
					</div>
				</div>
			</div>
		<?php endif; ?>
	</div>
</section>
	<?php
	return;
endif;

if ( 'campus' === $layout ) :
	$primary_class   = 'px-8 py-4 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-xl font-medium shadow-lg hover:shadow-xl transition-all inline-block text-center';
	$secondary_class = 'px-8 py-4 font-headline font-bold text-tertiary underline decoration-tertiary/30 hover:decoration-tertiary transition-all inline-block';
	?>
<section class="wp-block-group vila-motion-hero relative flex flex-col justify-center px-8 py-12 md:py-8 max-w-[1236px] mx-auto box-border overflow-hidden mb-0">
	<div class="wp-block-columns items-center flex flex-col md:flex-row w-full gap-12">
		<div class="wp-block-column z-10 w-full md:w-7/12" style="flex-basis:58.33%">
			<h1 class="wp-block-heading text-6xl md:text-8xl font-normal font-barrio text-primary leading-none mb-8" data-vila-hero-item style="--vila-hero-i:0"><?php echo esc_html( $heading ); ?></h1>
			<?php if ( '' !== $body ) : ?>
				<p class="text-xl md:text-2xl font-light text-on-surface-variant max-w-xl leading-relaxed mb-12 italic whitespace-pre-line" data-vila-hero-item style="--vila-hero-i:1"><?php echo esc_html( $body ); ?></p>
			<?php endif; ?>
			<?php if ( $has_primary || $has_secondary ) : ?>
				<p class="flex flex-wrap gap-6 mb-0" data-vila-hero-item style="--vila-hero-i:2">
					<?php if ( $has_primary ) : ?>
						<?php vila_akoya_section_link( $primary_link, 'Book your dates', $primary_class ); ?>
					<?php endif; ?>
					<?php if ( $has_secondary ) : ?>
						<?php vila_akoya_section_link( $secondary_link, 'And what about the kids?', $secondary_class ); ?>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
		<div class="wp-block-column relative w-full md:w-5/12" style="flex-basis:41.66%; --vila-hero-i:3" data-vila-hero-item>
			<div class="wp-block-group vila-motion-hero-frame relative aspect-[4/5] overflow-hidden shadow-2xl rotate-2">
				<?php vila_akoya_section_image( $image, 'wp-block-image size-large vila-img-fill', $heading ); ?>
			</div>
		</div>
	</div>
</section>
	<?php
	return;
endif;

$section_class = 'wp-block-group vila-motion-hero flex flex-col justify-center max-w-[1236px] box-border mx-auto px-8 py-12 md:py-0 mb-32';
$image_wrap    = 'relative aspect-[4/5] rounded-xl overflow-hidden shadow-2xl';
$badge_class   = 'absolute -bottom-10 -left-20 hidden xl:block bg-secondary-container p-12 rounded-lg max-w-xs shadow-xl';

if ( 'village' === $layout ) {
	$section_class = 'wp-block-group vila-motion-hero flex flex-col justify-center px-8 mb-20 relative pb-12 md:pb-0 max-w-[1236px] mx-auto box-border';
	$image_wrap    = 'relative aspect-[4/5] rounded-xl overflow-hidden editorial-shadow shadow-2xl rotate-2';
	$badge_class   = 'absolute -bottom-10 -left-8 xl:-left-20 hidden md:block bg-secondary-container p-8 xl:p-12 rounded-lg max-w-xs shadow-xl';
}

$body_class = 'font-body text-xl text-on-surface-variant max-w-xl leading-relaxed';
if ( 'kids' === $layout ) {
	$body_class = 'font-headline italic text-xl md:text-2xl text-tertiary max-w-xl leading-relaxed mb-8';
}
?>
<section class="<?php echo esc_attr( $section_class ); ?>">
	<div class="wp-block-columns gap-6 md:gap-12 items-center flex flex-col md:flex-row w-full">
		<div class="wp-block-column z-10 w-full md:w-7/12 space-y-2" style="flex-basis:58.33%">
			<?php if ( '' !== $eyebrow ) : ?>
				<p class="font-label text-primary uppercase tracking-[0.2em] text-sm font-bold mb-4" data-vila-hero-item style="--vila-hero-i:0"><?php echo esc_html( $eyebrow ); ?></p>
			<?php endif; ?>
			<h1 class="wp-block-heading <?php echo esc_attr( $heading_class ); ?>" data-vila-hero-item style="--vila-hero-i:1"><?php echo esc_html( $heading ); ?></h1>
			<?php if ( '' !== $body ) : ?>
				<p class="<?php echo esc_attr( $body_class ); ?>" data-vila-hero-item style="--vila-hero-i:2"><?php echo esc_html( $body ); ?></p>
			<?php endif; ?>
			<?php if ( $has_primary || $has_secondary ) : ?>
				<div class="wp-block-buttons flex flex-wrap gap-4 pt-2" data-vila-hero-item style="--vila-hero-i:3">
					<?php if ( $has_primary ) : ?>
						<div class="wp-block-button is-style-fill">
							<?php vila_akoya_section_link( $primary_link, 'Learn more', 'wp-block-button__link wp-element-button px-8 py-4 bg-primary text-on-primary rounded-xl font-medium text-lg hover:opacity-90 transition-opacity' ); ?>
						</div>
					<?php endif; ?>
					<?php if ( $has_secondary ) : ?>
						<div class="wp-block-button is-style-outline">
							<?php vila_akoya_section_link( $secondary_link, 'Tell me more', 'wp-block-button__link wp-element-button px-8 py-4 border border-primary/30 text-primary rounded-xl font-medium text-lg hover:bg-primary/5 transition-colors' ); ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<div class="wp-block-column relative w-full md:w-5/12" style="flex-basis:41.66%; --vila-hero-i:4" data-vila-hero-item>
			<div class="wp-block-group vila-motion-hero-frame <?php echo esc_attr( $image_wrap ); ?>">
				<?php vila_akoya_section_image( $image, 'wp-block-image size-large vila-img-fill', $heading ); ?>
			</div>
			<?php if ( '' !== $badge_text ) : ?>
				<div class="wp-block-group <?php echo esc_attr( $badge_class ); ?>">
					<p class="font-headline text-2xl text-on-secondary-container leading-snug mb-0"><?php echo esc_html( $badge_text ); ?></p>
				</div>
			<?php endif; ?>
		</div>
	</div>
</section>
