<?php
/**
 * Home hero section.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

$heading        = (string) vila_akoya_section_field( 'heading', 'The little village of our dreams' );
$body           = (string) vila_akoya_section_field( 'body', 'A tiny house village, glamping and creative community bordering the river Mira in the south of Alentejo. An extraordinary place of creation, relaxation and inspiration that we get to share with our friends and friends disguised as strangers.' );
$image          = vila_akoya_section_field( 'image', 'https://vila-akoya.com/images/home-hero.jpg' );
$primary_link   = vila_akoya_section_field( 'primary_link', array( 'url' => '/booking', 'title' => 'Book your stay' ) );
$secondary_link = vila_akoya_section_field( 'secondary_link', array( 'url' => '/campus', 'title' => 'Tell me more!' ) );
?>
<section class="wp-block-group relative w-full max-w-[1236px] mx-auto box-border px-8 pt-8 pb-10 mb-10 md:mb-10">
	<div class="wp-block-group relative w-full rounded-xl overflow-hidden editorial-shadow md:min-h-[calc(100vh-140px)]">
		<?php vila_akoya_section_image( $image, 'wp-block-image size-full vila-img-fill vila-home-hero-image w-full aspect-[4/3] md:absolute md:inset-0 md:h-full md:min-h-0 md:w-full md:aspect-auto', $heading, 'full' ); ?>
		<div class="wp-block-group relative flex flex-col justify-end bg-gradient-to-b from-surface-container/80 via-surface-container/95 to-surface-container p-6 sm:p-8 md:absolute md:inset-0 md:justify-center md:bg-gradient-to-t md:from-on-surface/35 mt-0 md:via-on-surface/15 md:to-transparent md:p-20">
			<div class="wp-block-group max-w-4xl space-y-6 md:text-on-primary">
				<h1 class="wp-block-heading text-5xl md:text-8xl font-barrio text-on-surface md:text-on-primary leading-tight"><?php echo esc_html( $heading ); ?></h1>
				<p class="text-lg md:text-2xl text-on-surface-variant md:text-on-primary/90 font-light max-w-2xl leading-relaxed"><?php echo esc_html( $body ); ?></p>
				<div class="wp-block-buttons flex flex-wrap gap-4 pt-4">
					<div class="wp-block-button is-style-fill">
						<?php vila_akoya_section_link( $primary_link, 'Book your stay', 'wp-block-button__link wp-element-button px-8 py-4 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-xl font-medium text-lg hover:scale-95 transition-transform' ); ?>
					</div>
					<div class="wp-block-button is-style-outline">
						<?php vila_akoya_section_link( $secondary_link, 'Tell me more!', 'wp-block-button__link wp-element-button px-8 py-4 bg-primary/10 border border-primary/20 text-primary md:bg-surface-bright/20 md:border-white/20 md:text-on-primary rounded-xl font-medium text-lg hover:bg-primary/15 md:hover:bg-surface-bright/30 transition-all' ); ?>
					</div>
				</div>
			</div>
		</div>
	</div>
</section>
