<?php
/**
 * Campsite “Activities & Facilities” brochure mosaic.
 *
 * Matches the original campsite-blocks.html grid (campsite-card-* classes).
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

$heading = (string) vila_akoya_section_field( 'heading', 'Activities & Facilities' );
$intro   = (string) vila_akoya_section_field( 'intro', 'The beautiful spaces we get to share together.' );

$river_image   = vila_akoya_section_field( 'river_image', '' );
$river_heading = (string) vila_akoya_section_field( 'river_heading', 'The River Mira' );
$river_body    = (string) vila_akoya_section_field( 'river_body', 'Vila Akoya borders the river Mira, which hold water all-year-around. Have a swim, catch a fish, or take a little boat around!' );

$workshop_image   = vila_akoya_section_field( 'workshop_image', '' );
$workshop_heading = (string) vila_akoya_section_field( 'workshop_heading', 'Workshops' );
$workshop_body    = (string) vila_akoya_section_field( 'workshop_body', "We're planning lots of workshops, classes and events for adults and children." );
$workshop_link    = vila_akoya_section_field( 'workshop_link', false );

$little_image   = vila_akoya_section_field( 'little_image', '' );
$little_heading = (string) vila_akoya_section_field( 'little_heading', 'For the littler ones' );
$little_body    = (string) vila_akoya_section_field( 'little_body', 'We have loads of activities, a homeschooling collective, playgrounds, and a babysitter on-site!' );
$little_link    = vila_akoya_section_field( 'little_link', false );

$trails_heading = (string) vila_akoya_section_field( 'trails_heading', 'Hiking and biking trails' );
$trails_body    = (string) vila_akoya_section_field( 'trails_body', 'There are fantastic hiking and biking trails that start and end at Vila Akoya.' );
$food_heading   = (string) vila_akoya_section_field( 'food_heading', 'Food and Drinks' );
$food_body      = (string) vila_akoya_section_field( 'food_body', 'Join our table for breakfast, lunch or dinner, and we will cook you up a feast!' );

$has_workshop_link = is_array( $workshop_link ) && ! empty( $workshop_link['url'] );
$has_little_link   = is_array( $little_link ) && ! empty( $little_link['url'] );

$little_url = '';
if ( is_array( $little_image ) && ! empty( $little_image['url'] ) ) {
	$little_url = (string) $little_image['url'];
} elseif ( is_numeric( $little_image ) ) {
	$little_url = (string) wp_get_attachment_image_url( (int) $little_image, 'large' );
} elseif ( is_string( $little_image ) ) {
	$little_url = $little_image;
}
?>
<section class="wp-block-group py-24 px-8 md:px-20 bg-surface-container-low campsite-brochure-section mb-0">
	<div class="wp-block-group max-w-[1236px] box-border mx-auto space-y-16">
		<div class="wp-block-group flex flex-col md:flex-row justify-between items-end gap-6">
			<h2 class="wp-block-heading text-4xl md:text-6xl font-normal text-secondary font-headline"><?php echo esc_html( $heading ); ?></h2>
			<?php if ( '' !== $intro ) : ?>
				<p class="max-w-md text-on-surface-variant italic mb-0"><?php echo esc_html( $intro ); ?></p>
			<?php endif; ?>
		</div>

		<div class="wp-block-group bg-surface-container-high p-8 rounded-xl campsite-card campsite-card-river">
			<p class="mb-0"><span class="material-symbols-outlined text-5xl text-primary" aria-hidden="true">water</span></p>
			<h3 class="wp-block-heading text-3xl font-headline text-on-surface mt-0"><?php echo esc_html( $river_heading ); ?></h3>
			<p class="text-on-surface-variant mt-0"><?php echo esc_html( $river_body ); ?></p>
			<div class="wp-block-group mt-8 rounded-lg overflow-hidden h-48 relative">
				<?php vila_akoya_section_image( $river_image, 'wp-block-image size-large vila-img-fill absolute inset-0 h-full w-full', $river_heading ); ?>
			</div>
		</div>

		<div class="wp-block-columns editorial-shadow campsite-card campsite-card-workshops gap-8 flex flex-col md:flex-row w-full">
			<div class="wp-block-column relative overflow-hidden w-full md:w-1/2 space-y-4">
				<p class="mb-0"><span class="material-symbols-outlined text-5xl text-tertiary" aria-hidden="true">palette</span></p>
				<h3 class="wp-block-heading text-4xl font-headline text-on-surface"><?php echo esc_html( $workshop_heading ); ?></h3>
				<p class="text-on-surface-variant"><?php echo esc_html( $workshop_body ); ?></p>
				<?php if ( $has_workshop_link ) : ?>
					<p class="mb-0"><?php vila_akoya_section_link( $workshop_link, 'View schedule here', 'text-primary font-bold decoration-primary/30 flex items-center gap-2' ); ?></p>
				<?php endif; ?>
			</div>
			<div class="wp-block-column relative overflow-x-hidden w-full md:w-1/2 min-h-[16rem]">
				<div class="wp-block-group rounded-xl overflow-hidden h-full min-h-[16rem] relative">
					<?php vila_akoya_section_image( $workshop_image, 'wp-block-image size-large vila-img-fill absolute inset-0 h-full w-full', $workshop_heading ); ?>
				</div>
			</div>
		</div>

		<div class="va-cover-hero relative rounded-xl overflow-hidden campsite-card campsite-card-littler min-h-[280px] flex items-end">
			<?php if ( '' !== $little_url ) : ?>
				<img class="va-cover-hero__image absolute inset-0 z-0 w-full h-full object-cover" alt="" src="<?php echo esc_url( $little_url ); ?>" />
			<?php endif; ?>
			<span aria-hidden="true" class="absolute inset-0 z-[1] bg-secondary/60 pointer-events-none"></span>
			<div class="relative z-[2] p-8 md:p-10 space-y-4 max-w-lg">
				<h3 class="wp-block-heading text-4xl font-headline text-white"><?php echo esc_html( $little_heading ); ?></h3>
				<p class="max-w-sm font-light text-white mb-0"><?php echo esc_html( $little_body ); ?></p>
				<?php if ( $has_little_link ) : ?>
					<p class="mb-0 pt-2"><?php vila_akoya_section_link( $little_link, 'Kids programme', 'text-white font-bold underline underline-offset-4' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<div class="wp-block-columns campsite-card campsite-card-trails-food gap-6 flex flex-col w-full">
			<div class="wp-block-column w-full">
				<div class="wp-block-group bg-surface-container p-8 rounded-xl h-full border border-outline-variant/10 flex flex-col justify-between">
					<div>
						<h4 class="wp-block-heading text-2xl font-headline text-on-surface-variant uppercase tracking-tighter"><?php echo esc_html( $trails_heading ); ?></h4>
						<p class="text-sm mt-2"><?php echo esc_html( $trails_body ); ?></p>
					</div>
					<p class="mb-0 text-right mt-2"><span class="material-symbols-outlined text-tertiary" aria-hidden="true">hiking</span></p>
				</div>
			</div>
			<div class="wp-block-column w-full">
				<div class="wp-block-group bg-surface-container-highest p-8 rounded-xl h-full border border-outline-variant/10 flex flex-col justify-between">
					<div>
						<h4 class="wp-block-heading text-2xl font-headline text-on-surface-variant uppercase tracking-tighter"><?php echo esc_html( $food_heading ); ?></h4>
						<p class="text-sm mt-2"><?php echo esc_html( $food_body ); ?></p>
					</div>
					<p class="mb-0 text-right mt-2"><span class="material-symbols-outlined text-primary" aria-hidden="true">restaurant</span></p>
				</div>
			</div>
		</div>
	</div>
</section>
