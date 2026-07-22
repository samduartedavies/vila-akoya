<?php
/**
 * Text and image split section.
 *
 * Variants (layout field):
 * - home: home story (rotated tall image + glass badge)
 * - band: inset low-surface band (About)
 * - village: full-bleed low-surface + circular image
 * - campsite: pitches with circular image + floating badge
 * - kids-cta: va-kids-cta split card
 * - plain: simple split, no band
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

$layout         = (string) vila_akoya_section_field( 'layout', 'plain' );
$heading        = (string) vila_akoya_section_field( 'heading', '' );
$heading_accent = (string) vila_akoya_section_field( 'heading_accent', '' );
if ( str_contains( $heading, '<' ) || str_contains( $heading, 'u003c' ) ) {
	$heading        = vila_akoya_section_decode_value( $heading );
	$heading_accent = '';
}
$body       = (string) vila_akoya_section_field( 'body', '' );
$image      = vila_akoya_section_field( 'image', '' );
$badge_text = (string) vila_akoya_section_field( 'badge_text', '' );
$link       = vila_akoya_section_field( 'link', false );
$has_link   = is_array( $link ) && ! empty( $link['url'] );
$alt        = trim( $heading . ( $heading_accent ? ' ' . $heading_accent : '' ) );

if ( '' === $layout && '' !== $heading_accent ) {
	$layout = 'home';
}

if ( 'kids-cta' === $layout ) :
	?>
<section class="wp-block-group va-kids-cta vila-reveal">
	<div class="wp-block-columns va-kids-cta__card items-stretch flex flex-col md:flex-row w-full">
		<div class="wp-block-column va-kids-cta__content w-full md:w-3/5 space-y-8 md:space-y-10" style="flex-basis:60%">
			<?php if ( '' !== $heading ) : ?>
				<h2 class="wp-block-heading font-headline text-5xl md:text-6xl text-on-background leading-tight !mb-0"><?php echo esc_html( $heading ); ?></h2>
			<?php endif; ?>
			<?php if ( '' !== $body ) : ?>
				<div class="font-headline text-xl opacity-90 leading-relaxed !mb-0"><?php echo wp_kses_post( wpautop( $body ) ); ?></div>
			<?php endif; ?>
			<?php if ( $has_link ) : ?>
				<div class="wp-block-buttons !mt-0 !mb-0">
					<div class="wp-block-button is-style-fill">
						<?php vila_akoya_section_link( $link, 'Contact us', 'wp-block-button__link wp-element-button' ); ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<div class="wp-block-column va-kids-cta__media w-full md:w-2/5" style="flex-basis:40%">
			<?php vila_akoya_section_image( $image, 'wp-block-image size-full vila-img-fill', wp_strip_all_tags( $alt ), 'full' ); ?>
		</div>
	</div>
</section>
	<?php
	return;
endif;

if ( 'campsite' === $layout ) :
	?>
<section class="wp-block-group vila-nature-band vila-dappled-surface py-20 px-8 md:px-20 bg-surface mb-0">
	<div class="wp-block-group max-w-[1236px] box-border mx-auto flex flex-col md:flex-row gap-20 items-center">
		<div class="wp-block-group vila-reveal w-full md:w-1/2 space-y-10">
			<?php if ( '' !== $heading ) : ?>
				<h2 class="wp-block-heading text-5xl md:text-7xl font-normal text-primary font-headline leading-tight">
					<?php if ( '' !== $heading_accent ) : ?>
						<?php echo esc_html( $heading ); ?><br><?php echo esc_html( $heading_accent ); ?>
					<?php else : ?>
						<?php echo esc_html( $heading ); ?>
					<?php endif; ?>
				</h2>
			<?php endif; ?>
			<?php if ( '' !== $body ) : ?>
				<div class="text-xl text-on-surface-variant font-light leading-relaxed">
					<?php echo wp_kses_post( wpautop( $body ) ); ?>
				</div>
			<?php endif; ?>
			<?php if ( $has_link ) : ?>
				<p class="pt-2 mb-0"><?php vila_akoya_section_link( $link, 'Check availability', 'text-primary font-headline font-bold text-xl' ); ?></p>
			<?php endif; ?>
		</div>
		<div class="wp-block-group vila-reveal w-full md:w-1/2 relative">
			<div class="wp-block-group relative aspect-square rounded-full overflow-hidden border-8 border-surface-container shadow-2xl">
				<?php vila_akoya_section_image( $image, 'wp-block-image size-large vila-img-fill', wp_strip_all_tags( $alt ) ); ?>
			</div>
			<?php if ( '' !== $badge_text ) : ?>
				<div class="wp-block-group absolute -top-10 -right-10 w-48 h-48 bg-tertiary-container rounded-full flex items-center justify-center p-8 text-center rotate-12 editorial-shadow">
					<p class="font-headline text-on-tertiary-container text-xl leading-none mb-0"><?php echo esc_html( $badge_text ); ?></p>
				</div>
			<?php endif; ?>
		</div>
	</div>
</section>
	<?php
	return;
endif;

$section_class = 'wp-block-group mb-32 md:mb-40';
$inner_open    = '<div class="wp-block-group max-w-[1236px] mx-auto box-border px-8">';
$inner_close   = '</div>';
$image_class   = 'wp-block-image size-large vila-img-fill overflow-hidden';
$image_wrap    = '';

if ( 'home' === $layout ) {
	$section_class = 'wp-block-group max-w-[1236px] mx-auto box-border px-8 mb-32 md:mb-40';
	$inner_open    = '';
	$inner_close   = '';
	$image_class   = 'wp-block-image size-large vila-img-fill flex-1 w-full min-h-[min(26rem,55vh)] md:min-h-[min(32rem,72vh)] overflow-hidden rounded-[2.5rem] md:rounded-[3rem] editorial-shadow relative z-10 rotate-[-1.5deg]';
} elseif ( 'band' === $layout ) {
	$section_class = 'wp-block-group vila-nature-band vila-dappled-surface bg-surface-container-low py-32 rounded-xl mx-8 mb-32';
	$inner_open    = '<div class="wp-block-group max-w-7xl mx-auto px-8">';
	$image_class   = 'wp-block-image size-large vila-img-fill rounded-lg overflow-hidden h-[500px]';
} elseif ( 'village' === $layout ) {
	$section_class = 'wp-block-group vila-nature-band vila-dappled-surface bg-surface-container-low py-32 px-6 md:px-20 overflow-hidden mb-0';
	$inner_open    = '<div class="wp-block-group max-w-7xl mx-auto">';
	$image_wrap    = 'relative z-10 aspect-square rounded-full border-[1.5rem] border-surface-container-high overflow-hidden';
	$image_class   = 'wp-block-image size-large vila-img-fill';
} else {
	$section_class = 'wp-block-group max-w-[1236px] mx-auto box-border px-8 mb-32 md:mb-40';
	$inner_open    = '';
	$inner_close   = '';
	$image_class   = 'wp-block-image size-large vila-img-fill rounded-xl overflow-hidden editorial-shadow aspect-[4/5] md:aspect-auto md:min-h-[28rem]';
}
?>
<section class="<?php echo esc_attr( $section_class ); ?>">
	<?php echo $inner_open; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<div class="wp-block-columns gap-12 lg:gap-20 items-center vila-columns-stretch flex flex-col md:flex-row w-full">
		<div class="wp-block-column vila-reveal relative flex flex-col h-full min-h-0 w-full md:w-5/12 order-2 md:order-1" style="flex-basis:41.66%">
			<?php if ( '' !== $image_wrap ) : ?>
				<div class="<?php echo esc_attr( $image_wrap ); ?>">
					<?php vila_akoya_section_image( $image, $image_class, wp_strip_all_tags( $alt ) ); ?>
				</div>
			<?php else : ?>
				<?php vila_akoya_section_image( $image, $image_class, wp_strip_all_tags( $alt ) ); ?>
			<?php endif; ?>
			<?php if ( 'home' === $layout && '' !== $badge_text ) : ?>
				<div class="wp-block-group absolute bottom-[-2rem] right-[-2rem] glass-card bg-surface-bright/80 p-6 rounded-2xl editorial-shadow max-w-[200px] z-20 rotate-[3deg] hidden md:block">
					<p class="text-sm leading-tight text-on-surface flex flex-col gap-2"><span class="material-symbols-outlined text-primary text-4xl mb-2" style="font-variation-settings:'FILL' 1" aria-hidden="true">favorite</span><?php echo esc_html( $badge_text ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<div class="wp-block-column vila-reveal space-y-6 md:space-y-8 flex flex-col justify-center w-full md:w-7/12 order-1 md:order-2" style="flex-basis:58.33%">
			<?php if ( '' !== $heading ) : ?>
				<h2 class="wp-block-heading text-4xl md:text-5xl lg:text-6xl font-headline text-on-surface font-light leading-tight">
					<?php if ( '' !== $heading_accent ) : ?>
						<?php echo esc_html( $heading ); ?><br><span class="text-primary italic"><?php echo esc_html( $heading_accent ); ?></span>
					<?php else : ?>
						<?php echo esc_html( $heading ); ?>
					<?php endif; ?>
				</h2>
			<?php endif; ?>
			<?php if ( '' !== $body ) : ?>
				<div class="wp-block-group space-y-6 text-lg md:text-xl font-light text-on-surface-variant leading-relaxed">
					<?php echo wp_kses_post( wpautop( $body ) ); ?>
				</div>
			<?php endif; ?>
			<?php if ( $has_link ) : ?>
				<p class="pt-4">
					<?php vila_akoya_section_link( $link, 'Learn more', 'group flex items-center gap-4 text-primary font-bold text-xl' ); ?>
				</p>
			<?php endif; ?>
		</div>
	</div>
	<?php echo $inner_close; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</section>
