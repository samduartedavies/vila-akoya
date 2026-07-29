<?php
/**
 * Contact page location map (Google Maps embed, site-tinted).
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Site address + map coordinates.
 *
 * @return array{line:string,lat:float,lng:float,maps_url:string}
 */
function vila_akoya_location_details() {
	return array(
		'line'     => 'Horta da Bica, 7670-202 Ourique',
		'lat'      => 37.5679167,
		'lng'      => -8.2642778,
		'maps_url' => 'https://maps.app.goo.gl/oBuiBrk987Q6FmTN6',
	);
}

/**
 * Markup for the Find us / Google Map section.
 *
 * @return string
 */
function vila_akoya_render_location_map() {
	$loc      = vila_akoya_location_details();
	$embed    = sprintf(
		'https://www.google.com/maps?q=%1$s,%2$s&hl=en&z=9&output=embed',
		rawurlencode( (string) $loc['lat'] ),
		rawurlencode( (string) $loc['lng'] )
	);
	$title_id = 'vila-location-map-heading';

	ob_start();
	?>
<section class="vila-location-map max-w-[1236px] mx-auto box-border px-8 mt-24 mb-4" aria-labelledby="<?php echo esc_attr( $title_id ); ?>">
	<div class="vila-location-map__card vila-reveal grid grid-cols-1 lg:grid-cols-12 overflow-hidden rounded-xl bg-surface-container-low border border-outline-variant/15">
		<div class="lg:col-span-5 flex flex-col justify-center gap-6 p-10 md:p-14">
			<p class="font-label text-primary tracking-[0.2em] uppercase text-sm mb-0">Find us</p>
			<h2 id="<?php echo esc_attr( $title_id ); ?>" class="font-headline text-4xl md:text-5xl text-on-surface leading-tight mb-0">Come say hello</h2>
			<p class="font-body text-lg text-on-surface-variant leading-relaxed mb-0">We're nestled in the Alentejo hills near Ourique.</p>
			<p class="mb-0">
				<a class="vila-location-map__address inline-flex items-start gap-3 text-on-surface hover:text-primary transition-colors" href="<?php echo esc_url( $loc['maps_url'] ); ?>" target="_blank" rel="noopener noreferrer">
					<span class="material-symbols-outlined text-primary text-2xl mt-0.5 shrink-0" aria-hidden="true">location_on</span>
					<span class="font-headline text-xl md:text-2xl leading-snug"><?php echo esc_html( $loc['line'] ); ?></span>
				</a>
			</p>
		</div>
		<div class="vila-location-map__frame lg:col-span-7 relative min-h-[280px] md:min-h-[360px] lg:min-h-full">
			<iframe
				class="vila-location-map__iframe absolute inset-0 w-full h-full border-0"
				src="<?php echo esc_url( $embed ); ?>"
				title="<?php echo esc_attr__( 'Map showing Vila Akoya at Horta da Bica, Ourique', 'vila-akoya' ); ?>"
				loading="lazy"
				referrerpolicy="no-referrer-when-downgrade"
				allowfullscreen
			></iframe>
			<div class="vila-location-map__wash pointer-events-none absolute inset-0" aria-hidden="true"></div>
		</div>
	</div>
</section>
	<?php
	return (string) ob_get_clean();
}

/**
 * Shortcode: [vila_akoya_location_map]
 *
 * @return string
 */
function vila_akoya_shortcode_location_map() {
	return vila_akoya_render_location_map();
}
add_shortcode( 'vila_akoya_location_map', 'vila_akoya_shortcode_location_map' );

/**
 * Insert the map on the contact page between the contact cards and the form.
 *
 * @param string $content Post content.
 * @return string
 */
function vila_akoya_append_contact_location_map( $content ) {
	if (
		is_admin()
		|| ! is_singular( 'page' )
		|| ! in_the_loop()
		|| ! is_main_query()
		|| ! is_page( 'contact' )
	) {
		return $content;
	}

	$content = (string) $content;

	if (
		str_contains( $content, 'vila-location-map' )
		|| str_contains( $content, '[vila_akoya_location_map' )
	) {
		return $content;
	}

	$map = "\n\n" . vila_akoya_render_location_map() . "\n\n";

	// Prefer inserting before the contact form (ACF newsletter block or seeded HTML form card).
	$markers = array(
		'<!-- wp:acf/vila-newsletter',
		'va-contact-form-card',
		'<!-- Contact form -->',
	);

	foreach ( $markers as $marker ) {
		$pos = strpos( $content, $marker );
		if ( false !== $pos ) {
			return substr( $content, 0, $pos ) . $map . substr( $content, $pos );
		}
	}

	return $content . $map;
}
add_filter( 'the_content', 'vila_akoya_append_contact_location_map', 7 );
