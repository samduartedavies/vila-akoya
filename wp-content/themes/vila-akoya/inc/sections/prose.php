<?php
/**
 * Prose section.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

$layout         = (string) vila_akoya_section_field( 'layout', 'plain' );
$heading        = (string) vila_akoya_section_field( 'heading', '' );
$heading_accent = (string) vila_akoya_section_field( 'heading_accent', '' );
$body           = (string) vila_akoya_section_field( 'body', '' );
$lede           = (string) vila_akoya_section_field( 'lede', '' );

if ( '' === trim( $body ) && '' === $heading ) {
	return;
}

if ( 'skew' === $layout ) :
	?>
<section class="wp-block-group vila-reveal relative py-32 overflow-hidden mb-0">
	<div class="absolute inset-0 bg-primary/5 -skew-y-3 scale-110 pointer-events-none" aria-hidden="true"></div>
	<div class="wp-block-group max-w-4xl mx-auto px-6 relative text-center">
		<?php if ( '' !== $heading ) : ?>
			<h2 class="wp-block-heading font-headline text-5xl md:text-7xl mb-12">
				<?php echo esc_html( $heading ); ?>
				<?php if ( '' !== $heading_accent ) : ?>
					<br><span class="text-primary"><?php echo esc_html( $heading_accent ); ?></span>
				<?php endif; ?>
			</h2>
		<?php endif; ?>
		<?php if ( '' !== $lede ) : ?>
			<p class="font-headline text-2xl md:text-3xl italic text-on-surface leading-snug mb-12"><?php echo esc_html( $lede ); ?></p>
		<?php endif; ?>
		<?php if ( '' !== trim( $body ) ) : ?>
			<div class="font-headline text-2xl md:text-3xl text-on-surface leading-snug">
				<?php echo wp_kses_post( wpautop( $body ) ); ?>
			</div>
		<?php endif; ?>
	</div>
</section>
	<?php
	return;
endif;
?>
<section class="wp-block-group vila-reveal vila-page-prose max-w-[720px] mx-auto px-8 pb-12 md:pb-16">
	<?php echo wp_kses_post( wpautop( $body ) ); ?>
</section>
