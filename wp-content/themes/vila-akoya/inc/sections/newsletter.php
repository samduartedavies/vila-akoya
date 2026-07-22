<?php
/**
 * Form / newsletter section — shell depends on CF7 class or layout field.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

$heading        = (string) vila_akoya_section_field( 'heading', '' );
$body           = (string) vila_akoya_section_field( 'body', '' );
$form_shortcode = (string) vila_akoya_section_field( 'form_shortcode', '' );
$helper_link    = vila_akoya_section_field( 'helper_link', false );
$image          = vila_akoya_section_field( 'image', '' );
$layout         = (string) vila_akoya_section_field( 'layout', '' );

$is_inquiry = false !== strpos( $form_shortcode, 'vila-cf7--booking' ) || 'inquiry' === $layout;
$is_contact = false !== strpos( $form_shortcode, 'vila-cf7--contact' ) || 'contact' === $layout;
$is_campus  = false !== strpos( $form_shortcode, 'vila-cf7--campus' ) || 'campus' === $layout;
$has_image  = ! ( false === $image || null === $image || '' === $image || array() === $image );

if ( $is_inquiry ) :
	?>
<section class="va-village-newsletter vila-reveal py-32 px-6 md:px-20 text-center">
	<div class="max-w-6xl mx-auto bg-surface-container-high rounded-[4rem] p-12 md:p-24 relative overflow-hidden">
		<div class="absolute top-0 right-0 w-64 h-64 bg-primary/10 rounded-full -translate-y-1/2 translate-x-1/2 blur-3xl pointer-events-none" aria-hidden="true"></div>
		<div class="relative z-10">
			<?php if ( '' !== $heading ) : ?>
				<h2 class="wp-block-heading font-headline text-5xl md:text-7xl text-primary mb-8"><?php echo esc_html( $heading ); ?></h2>
			<?php endif; ?>
			<?php if ( '' !== trim( $body ) ) : ?>
				<p class="font-body text-2xl italic text-tertiary mb-12 max-w-2xl mx-auto"><?php echo esc_html( $body ); ?></p>
			<?php endif; ?>
			<div class="va-village-inquiry-card max-w-3xl mx-auto bg-surface-container-low rounded-[2rem] p-8 md:p-10 border border-outline-variant/20 shadow-sm text-left">
				<?php echo do_shortcode( $form_shortcode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<?php if ( is_array( $helper_link ) && ! empty( $helper_link['url'] ) ) : ?>
				<p class="text-sm text-on-surface-variant mt-8 mb-0"><?php vila_akoya_section_link( $helper_link, 'Or contact us directly', 'underline underline-offset-4 hover:text-primary transition-colors' ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</section>
	<?php
	return;
endif;

if ( $is_contact ) :
	?>
<section class="wp-block-group vila-reveal max-w-[1236px] mx-auto box-border px-8 mt-24 mb-32">
	<div class="wp-block-group va-contact-form-card max-w-2xl mx-auto bg-surface-container-low rounded-xl p-8 md:p-12 border border-outline-variant/20 shadow-sm">
		<div class="wp-block-group text-center mb-10">
			<?php if ( '' !== $heading ) : ?>
				<h2 class="wp-block-heading font-headline text-4xl md:text-5xl text-on-surface mb-4"><?php echo esc_html( $heading ); ?></h2>
			<?php endif; ?>
			<?php if ( '' !== $body ) : ?>
				<p class="text-on-surface-variant text-lg leading-relaxed"><?php echo esc_html( $body ); ?></p>
			<?php endif; ?>
		</div>
		<?php echo do_shortcode( $form_shortcode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
</section>
	<?php
	return;
endif;

if ( $is_campus ) :
	?>
<section class="wp-block-group vila-reveal py-24 px-6 md:px-12 max-w-[1236px] mx-auto box-border mb-16">
	<div class="wp-block-group text-center mb-16">
		<?php if ( '' !== $heading ) : ?>
			<h2 class="wp-block-heading text-5xl font-headline text-on-surface mb-6"><?php echo esc_html( $heading ); ?></h2>
		<?php endif; ?>
		<?php if ( '' !== $body ) : ?>
			<p class="text-on-surface-variant max-w-2xl mx-auto text-lg mb-0"><?php echo esc_html( $body ); ?></p>
		<?php endif; ?>
	</div>
	<div class="wp-block-group va-campus-form-card max-w-3xl mx-auto bg-surface-container-low rounded-[2rem] p-8 md:p-10 border border-outline-variant/20 shadow-sm">
		<?php echo do_shortcode( $form_shortcode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
</section>
	<?php
	return;
endif;

// Home digi-family (or newsletter with image).
?>
<div class="wp-block-columns alignfull va-home-digi-family vila-reveal is-not-stacked-on-mobile mx-auto mb-32 md:mb-40 editorial-shadow rounded-[2rem] md:rounded-[3rem] overflow-hidden items-stretch vila-columns-stretch gap-0 vila-split-flush md:min-h-[min(28rem,70vh)] max-w-[1236px] px-4 flex flex-col md:flex-row md:flex-nowrap w-full">
	<div class="wp-block-column bg-primary text-on-primary p-10 md:p-14 lg:p-16 flex flex-col justify-center min-h-0 space-y-6 w-full <?php echo $has_image ? 'md:w-2/3' : 'md:w-full'; ?>" style="flex-basis:<?php echo $has_image ? '66.66%' : '100%'; ?>">
		<?php if ( '' !== $heading ) : ?>
			<h2 class="wp-block-heading text-5xl md:text-7xl font-headline leading-tight"><?php echo esc_html( $heading ); ?></h2>
		<?php endif; ?>
		<?php if ( '' !== $body ) : ?>
			<p class="text-xl opacity-90 leading-relaxed font-light max-w-2xl"><?php echo esc_html( $body ); ?></p>
		<?php endif; ?>
		<?php echo do_shortcode( $form_shortcode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php if ( is_array( $helper_link ) && ! empty( $helper_link['url'] ) ) : ?>
			<p class="text-sm opacity-85 mt-2"><?php vila_akoya_section_link( $helper_link, 'Want to get in touch? Find our contact info here', 'underline underline-offset-4 hover:opacity-100' ); ?></p>
		<?php endif; ?>
	</div>
	<?php if ( $has_image ) : ?>
		<div class="wp-block-column va-home-digi-family__media relative block h-full bg-primary overflow-hidden min-h-[12rem] md:min-h-full w-full md:w-1/3" style="flex-basis:33.33%">
			<?php vila_akoya_section_image( $image, 'wp-block-image size-full vila-img-fill absolute inset-0 h-full w-full opacity-[0.58]', $heading, 'full' ); ?>
		</div>
	<?php endif; ?>
</div>
