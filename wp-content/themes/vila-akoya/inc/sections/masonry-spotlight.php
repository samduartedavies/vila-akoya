<?php
/**
 * Masonry spotlight (About “Where is Vila Akoya?”).
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

$heading      = (string) vila_akoya_section_field( 'heading', 'Where is Vila Akoya?' );
$body         = (string) vila_akoya_section_field( 'body', '' );
$image        = vila_akoya_section_field( 'image', '' );
$card_1_icon  = (string) vila_akoya_section_field( 'card_1_icon', 'park' );
$card_1_title = (string) vila_akoya_section_field( 'card_1_title', '' );
$card_2_icon  = (string) vila_akoya_section_field( 'card_2_icon', 'explore' );
$card_2_title = (string) vila_akoya_section_field( 'card_2_title', '' );
?>
<section class="wp-block-group max-w-[1236px] box-border mx-auto px-8 mb-32">
	<div class="wp-block-group grid grid-cols-1 md:grid-cols-4 md:grid-rows-2 gap-6 h-auto md:h-[600px]">
		<div class="wp-block-group md:col-span-2 md:row-span-2 bg-primary text-on-primary p-12 rounded-xl flex flex-col justify-center">
			<?php if ( '' !== $heading ) : ?>
				<h2 class="wp-block-heading font-headline text-5xl mb-6"><?php echo esc_html( $heading ); ?></h2>
			<?php endif; ?>
			<?php if ( '' !== $body ) : ?>
				<div class="font-body text-lg opacity-90 leading-relaxed space-y-4">
					<?php echo wp_kses_post( wpautop( $body ) ); ?>
				</div>
			<?php endif; ?>
		</div>
		<div class="wp-block-group md:col-span-2 bg-surface-container-highest rounded-xl overflow-hidden relative min-h-[240px] md:min-h-0">
			<?php vila_akoya_section_image( $image, 'wp-block-image size-large vila-img-fill', $heading ); ?>
		</div>
		<?php if ( '' !== $card_1_title ) : ?>
			<div class="wp-block-group md:col-span-1 bg-secondary text-on-secondary p-8 rounded-xl flex flex-col justify-end min-h-[180px]">
				<p class="mb-0"><span class="material-symbols-outlined text-4xl mb-4 block" aria-hidden="true"><?php echo esc_html( $card_1_icon ); ?></span></p>
				<h3 class="wp-block-heading font-headline text-2xl mb-0"><?php echo esc_html( $card_1_title ); ?></h3>
			</div>
		<?php endif; ?>
		<?php if ( '' !== $card_2_title ) : ?>
			<div class="wp-block-group md:col-span-1 bg-tertiary-container text-on-tertiary p-8 rounded-xl flex flex-col justify-end min-h-[180px]">
				<p class="mb-0"><span class="material-symbols-outlined text-4xl mb-4 block" aria-hidden="true"><?php echo esc_html( $card_2_icon ); ?></span></p>
				<h3 class="wp-block-heading font-headline text-2xl mb-0"><?php echo esc_html( $card_2_title ); ?></h3>
			</div>
		<?php endif; ?>
	</div>
</section>
