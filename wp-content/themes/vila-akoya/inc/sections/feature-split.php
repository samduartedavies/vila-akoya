<?php
/**
 * Two-column Home feature split.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

$left = array(
	'eyebrow' => (string) vila_akoya_section_field( 'left_eyebrow', 'A place to stay' ),
	'heading' => (string) vila_akoya_section_field( 'left_heading', 'The Village' ),
	'body'    => (string) vila_akoya_section_field( 'left_body', "Unique tiny homes in all shapes and forms. A feast for the eye and a home for the kind. This is the little village we've always been hoping to find." ),
	'image'   => vila_akoya_section_field( 'left_image', 'https://vila-akoya.com/images/village-small.jpg' ),
	'link'    => vila_akoya_section_field( 'left_link', array( 'url' => '/village', 'title' => 'Looking for a village?' ) ),
);
$right = array(
	'eyebrow' => (string) vila_akoya_section_field( 'right_eyebrow', '...or to visit' ),
	'heading' => (string) vila_akoya_section_field( 'right_heading', 'The Campsite' ),
	'body'    => (string) vila_akoya_section_field( 'right_body', 'Want to dip you toe in our home-away-from home? Swing by, join our table, have a swim, build a treehouse, make a friend.. and leave forever changed.' ),
	'image'   => vila_akoya_section_field( 'right_image', 'https://vila-akoya.com/images/campsite.jpeg' ),
	'link'    => vila_akoya_section_field( 'right_link', array( 'url' => '/booking', 'title' => 'Book your dates' ) ),
);
?>
<section class="wp-block-group mb-32 md:mb-40">
	<div class="wp-block-columns vila-reveal-stagger vila-dappled-surface min-h-[min(100vh,70rem)] gap-0 vila-split-flush flex flex-col md:flex-row md:flex-nowrap w-full">
		<div class="wp-block-column relative group flex flex-col items-center p-10 md:p-20 lg:p-24 bg-surface-container-low transition-all duration-700 hover:bg-surface-container overflow-hidden w-full md:w-1/2 md:flex-1">
			<div class="absolute -top-10 -left-10 w-64 h-64 bg-tertiary/5 rounded-full blur-3xl group-hover:bg-tertiary/10 transition-colors pointer-events-none" aria-hidden="true"></div>
			<div class="wp-block-group z-10 text-center space-y-7 md:space-y-8 max-w-[31rem] mx-auto">
				<p class="text-tertiary text-2xl font-headline"><?php echo esc_html( $left['eyebrow'] ); ?></p>
				<h2 class="wp-block-heading text-6xl md:text-7xl font-barrio text-on-surface narrow-header"><?php echo esc_html( $left['heading'] ); ?></h2>
				<hr class="wp-block-separator has-alpha-channel-opacity h-1 w-24 bg-tertiary/20 mx-auto rounded-full border-0"/>
				<p class="text-xl font-light text-on-surface-variant italic"><?php echo esc_html( $left['body'] ); ?></p>
				<?php vila_akoya_section_image( $left['image'], 'wp-block-image size-large vila-img-fill vila-home-village-image w-full aspect-[4/2] overflow-hidden rounded-3xl editorial-shadow mb-6', $left['heading'] ); ?>
				<p class="pt-6"><?php vila_akoya_section_link( $left['link'], 'Looking for a village?', 'font-headline font-bold text-lg text-tertiary-dim underline decoration-tertiary/40 underline-offset-8 hover:decoration-tertiary transition-all' ); ?></p>
			</div>
		</div>
		<div class="wp-block-column relative group flex flex-col items-center p-10 md:p-20 lg:p-24 bg-secondary-container/30 transition-all duration-700 hover:bg-secondary-container/50 overflow-hidden w-full md:w-1/2 md:flex-1">
			<div class="absolute -bottom-10 -right-10 w-64 h-64 bg-secondary/5 rounded-full blur-3xl group-hover:bg-secondary/10 transition-colors pointer-events-none" aria-hidden="true"></div>
			<div class="wp-block-group z-10 text-center space-y-7 md:space-y-8 max-w-[31rem] mx-auto">
				<p class="text-secondary text-2xl font-headline"><?php echo esc_html( $right['eyebrow'] ); ?></p>
				<h2 class="wp-block-heading text-6xl md:text-7xl font-barrio text-on-surface narrow-header"><?php echo esc_html( $right['heading'] ); ?></h2>
				<hr class="wp-block-separator has-alpha-channel-opacity h-1 w-24 bg-secondary/20 mx-auto rounded-full border-0"/>
				<p class="text-xl font-light text-on-surface-variant italic"><?php echo esc_html( $right['body'] ); ?></p>
				<?php vila_akoya_section_image( $right['image'], 'wp-block-image size-large vila-img-fill vila-home-campsite-image w-full aspect-[4/2] overflow-hidden rounded-3xl editorial-shadow mb-6', $right['heading'] ); ?>
				<p class="pt-6"><?php vila_akoya_section_link( $right['link'], 'Book your dates', 'font-headline font-bold text-lg text-secondary-dim underline decoration-secondary/40 underline-offset-8 hover:decoration-secondary transition-all' ); ?></p>
			</div>
		</div>
	</div>
</section>
