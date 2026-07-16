<?php
/**
 * People / family section.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

$heading = (string) vila_akoya_section_field( 'heading', 'Who are we?' );
$intro   = (string) vila_akoya_section_field( 'intro', 'The family behind Vila Akoya' );
$link    = vila_akoya_section_field( 'link', array( 'url' => '/about', 'title' => 'How we got here' ) );

$people = array();
if ( function_exists( 'have_rows' ) && have_rows( 'people' ) ) {
	while ( have_rows( 'people' ) ) {
		the_row();
		$people[] = array(
			'name'    => (string) vila_akoya_section_field( 'name' ),
			'image'   => vila_akoya_section_field( 'image' ),
			'bio'     => (string) vila_akoya_section_field( 'bio' ),
			'variant' => (string) vila_akoya_section_field( 'variant' ),
		);
	}
}

if ( empty( $people ) ) {
	$people = array(
		array(
			'name'    => 'Joyaa',
			'image'   => 'https://vila-akoya.com/images/joyaa.jpg',
			'bio'     => '<p>It takes a village to raise a child, and for us it took a child to build a village. Vila Akoya would not be anything without our daughter Joyaa.</p>',
			'variant' => '',
		),
		array(
			'name'    => 'Sammy',
			'image'   => 'https://vila-akoya.com/images/sammy.jpg',
			'bio'     => '<p>My name is Sammy. I am Joyaa and Akhoda\'s mother and Hans\'s partner.</p>',
			'variant' => 'who-we-portrait--blob-3',
		),
		array(
			'name'    => 'Hans',
			'image'   => 'https://vila-akoya.com/images/hans.jpg',
			'bio'     => '<p>Hans is the brains and the muscles behind Vila Akoya.</p>',
			'variant' => 'who-we-portrait--blob-2',
		),
	);
}
?>
<section class="wp-block-group relative mb-32 md:mb-40 overflow-x-hidden bg-gradient-to-b from-[#ebe4d4] via-[#e8dcc8] to-[#e5d6c4] text-[#3d2a22]">
	<div class="absolute inset-0 pointer-events-none opacity-[0.35] bg-[radial-gradient(ellipse_at_30%_20%,rgba(154,63,33,0.08),transparent_50%),radial-gradient(ellipse_at_70%_80%,rgba(92,99,72,0.06),transparent_45%)]" aria-hidden="true"></div>
	<div class="wp-block-group relative max-w-[1236px] mx-auto box-border px-8 py-20 md:py-28">
		<header class="wp-block-group text-center max-w-3xl mx-auto mb-14 md:mb-20 space-y-4">
			<h2 class="wp-block-heading font-barrio text-5xl md:text-7xl text-on-surface leading-tight"><?php echo esc_html( $heading ); ?></h2>
			<p class="font-headline text-tertiary text-xl md:text-2xl tracking-wide"><?php echo esc_html( $intro ); ?></p>
			<hr class="wp-block-separator has-alpha-channel-opacity h-px w-32 bg-tertiary/25 mx-auto rounded-full border-0"/>
		</header>
		<?php foreach ( $people as $index => $person ) : ?>
			<?php
			$even          = 0 === $index % 2;
			$text_order    = $even ? 'order-2 md:order-1' : 'order-2 md:order-2';
			$image_order   = $even ? 'order-1 md:order-2' : 'order-1 md:order-1';
			$portrait_class = trim( 'who-we-portrait w-full ' . (string) $person['variant'] );
			?>
			<div class="wp-block-columns gap-10 md:gap-16 lg:gap-20 items-center mb-16 md:mb-24 lg:mb-32 flex flex-col md:flex-row w-full">
				<div class="wp-block-column <?php echo esc_attr( $text_order ); ?> space-y-6 w-full md:w-1/2 md:flex-1">
					<h3 class="wp-block-heading font-barrio text-2xl md:text-3xl text-on-surface"><?php echo esc_html( $person['name'] ); ?></h3>
					<div class="text-lg md:text-xl font-light leading-relaxed text-on-surface-variant">
						<?php echo wp_kses_post( wpautop( (string) vila_akoya_section_decode_value( (string) $person['bio'] ) ) ); ?>
					</div>
				</div>
				<div class="wp-block-column <?php echo esc_attr( $image_order ); ?> flex w-full md:w-1/2 md:flex-1 justify-center items-center">
					<div class="wp-block-group <?php echo esc_attr( $portrait_class ); ?>">
						<span class="who-we-portrait__aura" aria-hidden="true"></span>
						<div class="wp-block-group who-we-portrait__clip">
							<?php vila_akoya_section_image( $person['image'], 'wp-block-image size-large', (string) $person['name'] ); ?>
						</div>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
		<div class="wp-block-group max-w-4xl mx-auto text-center space-y-8">
			<p class="font-headline text-xl text-tertiary-dim italic">More about us and the people around us, our story and what brought us here.</p>
			<p><?php vila_akoya_section_link( $link, 'How we got here', 'inline-flex items-center gap-2 text-primary font-bold text-lg hover:underline underline-offset-4' ); ?></p>
		</div>
	</div>
</section>
