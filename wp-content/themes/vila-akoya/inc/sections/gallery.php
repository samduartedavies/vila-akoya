<?php
/**
 * Gallery section.
 *
 * Variants (layout): campsite (default) | village
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

$layout  = (string) vila_akoya_section_field( 'layout', 'campsite' );
$heading = (string) vila_akoya_section_field( 'heading', '' );
$images  = vila_akoya_section_field( 'images', array() );
if ( ! is_array( $images ) ) {
	$images = array();
}

$caption_1 = (string) vila_akoya_section_field( 'caption_1', '' );
$caption_2 = (string) vila_akoya_section_field( 'caption_2', '' );
$caption_3 = (string) vila_akoya_section_field( 'caption_3', '' );

// Legacy fallback: older seeds used one pipe-separated "captions" field.
if ( '' === $caption_1 && '' === $caption_2 && '' === $caption_3 ) {
	$captions_raw = (string) vila_akoya_section_field( 'captions', '' );
	$legacy       = array_values( array_filter( array_map( 'trim', explode( '|', $captions_raw ) ) ) );
	$caption_1    = $legacy[0] ?? '';
	$caption_2    = $legacy[1] ?? '';
	$caption_3    = $legacy[2] ?? '';
}

$captions = array( $caption_1, $caption_2, $caption_3 );

/**
 * Resolve gallery image sources from ACF / attachment / URL values.
 *
 * @param mixed  $image Image field value.
 * @param string $alt   Fallback alt text.
 * @return array{id:int,thumb:string,full:string,alt:string}|null
 */
$resolve_gallery_image = static function ( $image, $alt = '' ) {
	$id      = 0;
	$url     = '';
	$img_alt = $alt;

	if ( is_array( $image ) ) {
		$id      = isset( $image['ID'] ) ? (int) $image['ID'] : ( isset( $image['id'] ) ? (int) $image['id'] : 0 );
		$url     = isset( $image['url'] ) ? (string) $image['url'] : '';
		$img_alt = isset( $image['alt'] ) && '' !== $image['alt'] ? (string) $image['alt'] : $alt;
	} elseif ( is_numeric( $image ) ) {
		$id = (int) $image;
	} elseif ( is_string( $image ) ) {
		$url = $image;
	}

	$thumb = '';
	$full  = '';

	if ( $id ) {
		$thumb = (string) wp_get_attachment_image_url( $id, 'large' );
		$full  = (string) wp_get_attachment_image_url( $id, 'full' );
		if ( '' === $img_alt ) {
			$img_alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		}
	}

	if ( '' === $thumb && '' !== $url ) {
		$thumb = $url;
	}
	if ( '' === $full ) {
		$full = '' !== $url ? $url : $thumb;
	}

	if ( '' === $thumb && '' === $full ) {
		return null;
	}

	return array(
		'id'    => $id,
		'thumb' => $thumb,
		'full'  => $full,
		'alt'   => $img_alt,
	);
};

if ( 'village' === $layout ) :
	$items = array_values( $images );
	?>
<section class="wp-block-group py-32 bg-white/30 mb-0">
	<div class="wp-block-columns px-6 md:px-20 items-center flex flex-col md:flex-row w-full gap-8">
		<?php if ( ! empty( $items[0] ) ) : ?>
			<div class="wp-block-column order-2 md:order-1 w-full md:w-1/3" style="flex-basis:33.33%">
				<div class="wp-block-group relative aspect-square rounded-[3rem] overflow-hidden mb-4 rotate-3 editorial-shadow">
					<?php vila_akoya_section_image( $items[0], 'wp-block-image size-large vila-img-fill', $captions[0] ?? '' ); ?>
				</div>
				<?php if ( ! empty( $captions[0] ) ) : ?>
					<h5 class="wp-block-heading font-headline text-xl font-bold"><?php echo esc_html( $captions[0] ); ?></h5>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<div class="wp-block-column order-1 md:order-2 w-full md:w-2/3" style="flex-basis:66.66%">
			<div class="wp-block-group md:pl-12">
				<?php if ( '' !== $heading ) : ?>
					<h2 class="wp-block-heading font-headline text-6xl text-on-surface mb-8"><?php echo esc_html( $heading ); ?></h2>
				<?php endif; ?>
				<div class="wp-block-columns flex flex-col md:flex-row w-full gap-8">
					<?php if ( ! empty( $items[1] ) ) : ?>
						<div class="wp-block-column w-full md:flex-1">
							<div class="wp-block-group relative group">
								<div class="wp-block-group relative aspect-[3/2] rounded-xl overflow-hidden mb-4 editorial-shadow">
									<?php vila_akoya_section_image( $items[1], 'wp-block-image size-large vila-img-fill', $captions[1] ?? '' ); ?>
								</div>
								<?php if ( ! empty( $captions[1] ) ) : ?>
									<h5 class="wp-block-heading font-headline text-xl font-bold"><?php echo esc_html( $captions[1] ); ?></h5>
								<?php endif; ?>
							</div>
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $items[2] ) ) : ?>
						<div class="wp-block-column w-full md:flex-1 mt-12 md:mt-24">
							<div class="wp-block-group relative group">
								<div class="wp-block-group relative aspect-[3/2] rounded-xl overflow-hidden mb-4 editorial-shadow">
									<?php vila_akoya_section_image( $items[2], 'wp-block-image size-large vila-img-fill', $captions[2] ?? '' ); ?>
								</div>
								<?php if ( ! empty( $captions[2] ) ) : ?>
									<h5 class="wp-block-heading font-headline text-xl font-bold"><?php echo esc_html( $captions[2] ); ?></h5>
								<?php endif; ?>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</section>
	<?php
	return;
endif;
?>
<section class="wp-block-group py-24 md:py-28 px-6 md:px-12 lg:px-20 overflow-hidden va-campsite-gallery mb-0">
	<div class="wp-block-group w-full max-w-[1280px] mx-auto mb-12 md:mb-16">
		<?php if ( '' !== $heading ) : ?>
			<h2 class="wp-block-heading text-4xl md:text-7xl font-normal text-primary font-headline text-center"><?php echo esc_html( $heading ); ?></h2>
		<?php endif; ?>
	</div>
	<div class="va-campsite-gallery-grid w-full max-w-[1280px] mx-auto" role="list">
		<?php foreach ( $images as $image ) : ?>
			<?php
			$resolved = $resolve_gallery_image( $image );
			if ( ! $resolved ) {
				continue;
			}
			?>
			<a
				class="va-campsite-gallery__item"
				href="<?php echo esc_url( $resolved['full'] ); ?>"
				role="listitem"
				data-gallery-src="<?php echo esc_url( $resolved['full'] ); ?>"
				data-gallery-alt="<?php echo esc_attr( $resolved['alt'] ); ?>"
			>
				<figure class="wp-block-image size-large vila-img-fill">
					<?php if ( $resolved['id'] ) : ?>
						<?php
						echo wp_get_attachment_image( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							$resolved['id'],
							'large',
							false,
							array(
								'alt'      => $resolved['alt'],
								'loading'  => 'lazy',
								'decoding' => 'async',
							)
						);
						?>
					<?php else : ?>
						<img
							src="<?php echo esc_url( $resolved['thumb'] ); ?>"
							alt="<?php echo esc_attr( $resolved['alt'] ); ?>"
							loading="lazy"
							decoding="async"
						/>
					<?php endif; ?>
				</figure>
			</a>
		<?php endforeach; ?>
	</div>
</section>
