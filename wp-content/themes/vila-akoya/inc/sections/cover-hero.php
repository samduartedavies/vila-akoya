<?php
/**
 * Full-bleed cover hero (Campsite-style).
 *
 * Avoids core `.wp-block-cover` styles — those force a solid black dim layer
 * that can hide the background image in our Tailwind layout.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

$heading      = (string) vila_akoya_section_field( 'heading', 'A Creative campsite' );
$body         = (string) vila_akoya_section_field( 'body', '' );
$image        = vila_akoya_section_field( 'image', '' );
$primary_link = vila_akoya_section_field( 'primary_link', false );
$image_url    = '';

if ( is_array( $image ) && ! empty( $image['url'] ) ) {
	$image_url = (string) $image['url'];
} elseif ( is_numeric( $image ) ) {
	$image_url = (string) wp_get_attachment_image_url( (int) $image, 'full' );
} elseif ( is_string( $image ) ) {
	$image_url = $image;
}

$has_link = is_array( $primary_link ) && ! empty( $primary_link['url'] );
?>
<section class="va-cover-hero vila-motion-hero vila-motion-hero-ambient relative w-full min-h-[80vh] flex items-end md:items-center overflow-hidden mb-0">
	<?php if ( '' !== $image_url ) : ?>
		<img class="va-cover-hero__image absolute inset-0 z-0 w-full h-full object-cover" alt="" src="<?php echo esc_url( $image_url ); ?>" />
	<?php endif; ?>
	<span aria-hidden="true" class="va-cover-hero__dim absolute inset-0 z-[1] bg-black/40 pointer-events-none"></span>
	<div class="va-cover-hero__inner relative z-[2] w-full max-w-[1236px] mx-auto box-border px-8 py-16 md:py-24 space-y-8">
		<h1 class="wp-block-heading text-6xl md:text-8xl font-normal text-on-primary font-barrio leading-none drop-shadow-lg" data-vila-hero-item style="--vila-hero-i:0"><?php echo esc_html( $heading ); ?></h1>
		<?php if ( '' !== $body ) : ?>
			<p class="text-xl md:text-2xl text-on-primary font-light max-w-xl leading-relaxed whitespace-pre-line" data-vila-hero-item style="--vila-hero-i:1"><?php echo esc_html( $body ); ?></p>
		<?php endif; ?>
		<?php if ( $has_link ) : ?>
			<p class="pt-6 mb-0" data-vila-hero-item style="--vila-hero-i:2">
				<?php vila_akoya_section_link( $primary_link, 'Book your plot', 'inline-block px-10 py-5 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-xl text-lg font-semibold editorial-shadow hover:-translate-y-0.5 transition-all duration-300 text-center' ); ?>
			</p>
		<?php endif; ?>
	</div>
</section>
