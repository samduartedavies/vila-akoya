<?php
/**
 * Reusable image-card grid.
 *
 * Variants (layout): default | village | kids
 * (Campsite facilities use the dedicated campsite-facilities section.)
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

$layout  = (string) vila_akoya_section_field( 'layout', 'default' );
$heading = (string) vila_akoya_section_field( 'heading', '' );
$intro   = (string) vila_akoya_section_field( 'intro', '' );
$cards   = array();

if ( function_exists( 'have_rows' ) && have_rows( 'cards' ) ) {
	while ( have_rows( 'cards' ) ) {
		the_row();
		$cards[] = array(
			'image'   => vila_akoya_section_field( 'image' ),
			'icon'    => (string) vila_akoya_section_field( 'icon', '' ),
			'heading' => (string) vila_akoya_section_field( 'heading' ),
			'body'    => (string) vila_akoya_section_field( 'body' ),
			'link'    => vila_akoya_section_field( 'link', false ),
		);
	}
}

$section_class = 'wp-block-group py-24 px-8 md:px-20 mb-0';
$heading_class = 'font-headline text-5xl text-primary mb-12 text-center';

if ( 'village' === $layout ) {
	$section_class = 'wp-block-group vila-dappled-surface py-32 px-6 md:px-20 mb-0';
	$heading_class = 'font-headline text-5xl text-primary mb-4';
} elseif ( 'kids' === $layout ) {
	$section_class = 'wp-block-group px-6 md:px-12 lg:px-24 mb-32';
	$heading_class = 'font-headline text-4xl md:text-5xl text-on-surface mb-12 text-center';
}
?>
<section class="<?php echo esc_attr( $section_class ); ?>">
	<div class="wp-block-group max-w-[1236px] mx-auto box-border">
		<?php if ( 'village' === $layout ) : ?>
			<div class="wp-block-group vila-reveal text-center mb-20">
				<?php if ( '' !== $heading ) : ?>
					<h2 class="wp-block-heading <?php echo esc_attr( $heading_class ); ?>"><?php echo esc_html( $heading ); ?></h2>
				<?php endif; ?>
				<?php if ( '' !== $intro ) : ?>
					<p class="font-headline italic text-2xl text-tertiary mb-0"><?php echo esc_html( $intro ); ?></p>
				<?php endif; ?>
			</div>
		<?php elseif ( '' !== $heading ) : ?>
			<h2 class="wp-block-heading vila-reveal <?php echo esc_attr( $heading_class ); ?>"><?php echo esc_html( $heading ); ?></h2>
		<?php endif; ?>

		<div class="vila-reveal-stagger grid grid-cols-1 md:grid-cols-3 gap-8 max-w-7xl mx-auto items-stretch">
			<?php foreach ( $cards as $i => $card ) : ?>
				<?php
				$has_link  = is_array( $card['link'] ) && ! empty( $card['link']['url'] );
				$scale     = ( 'village' === $layout && 1 === $i ) ? ' md:scale-105 z-10' : '';
				$icon_bgs  = array( 'bg-secondary-container', 'bg-primary-container', 'bg-tertiary-container' );
				$icon_fg   = array( 'text-on-secondary-container', 'text-on-primary-container', 'text-on-tertiary-container' );
				$icon_bg   = $icon_bgs[ $i % 3 ];
				$icon_text = $icon_fg[ $i % 3 ];
				?>
				<div class="wp-block-group bg-surface-container rounded-xl p-8 editorial-shadow flex flex-col h-full group hover:bg-surface-bright transition-colors duration-500<?php echo esc_attr( $scale ); ?>">
					<?php if ( 'village' === $layout && '' !== $card['icon'] ) : ?>
						<div class="w-16 h-16 <?php echo esc_attr( $icon_bg ); ?> rounded-full flex items-center justify-center mb-8 group-hover:scale-110 transition-transform duration-300">
							<span class="material-symbols-outlined <?php echo esc_attr( $icon_text ); ?> text-3xl" aria-hidden="true"><?php echo esc_html( $card['icon'] ); ?></span>
						</div>
					<?php endif; ?>
					<?php if ( 'village' === $layout ) : ?>
						<h3 class="wp-block-heading font-headline text-3xl font-bold mb-4"><?php echo esc_html( (string) $card['heading'] ); ?></h3>
						<p class="font-body text-lg text-on-surface-variant mb-8 grow"><?php echo wp_kses_post( nl2br( esc_html( (string) $card['body'] ) ) ); ?></p>
						<div class="aspect-video rounded-lg overflow-hidden mb-8 relative">
							<?php vila_akoya_section_image( $card['image'], 'wp-block-image size-large vila-img-fill', (string) $card['heading'] ); ?>
						</div>
						<?php if ( $has_link && 1 === $i ) : ?>
							<p class="mb-0"><?php vila_akoya_section_link( $card['link'], 'Get in touch', 'bg-primary text-on-primary px-8 py-3 rounded-full font-label font-semibold w-full inline-block text-center' ); ?></p>
						<?php elseif ( $has_link ) : ?>
							<p class="mb-0"><?php vila_akoya_section_link( $card['link'], 'Learn more', 'text-primary font-headline font-bold text-xl flex items-center gap-2 group-hover:translate-x-2 transition-transform duration-300' ); ?></p>
						<?php endif; ?>
					<?php else : ?>
						<div class="aspect-video rounded-lg overflow-hidden mb-8 relative">
							<?php vila_akoya_section_image( $card['image'], 'wp-block-image size-large vila-img-fill', (string) $card['heading'] ); ?>
						</div>
						<h3 class="wp-block-heading font-headline text-3xl font-bold mb-4"><?php echo esc_html( (string) $card['heading'] ); ?></h3>
						<p class="font-body text-lg text-on-surface-variant mb-8 grow"><?php echo esc_html( (string) $card['body'] ); ?></p>
						<?php if ( $has_link ) : ?>
							<p class="mb-0"><?php vila_akoya_section_link( $card['link'], 'Learn more', 'text-primary font-headline font-bold text-xl flex items-center gap-2 group-hover:translate-x-2 transition-transform duration-300' ); ?></p>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
