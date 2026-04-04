<?php
/**
 * Navigation fallbacks and walkers.
 *
 * @package VilaAkoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fallback menu when no menu assigned (desktop).
 */
function vila_akoya_fallback_primary_menu() {
	echo vila_akoya_get_nav_links_markup( 'desktop' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Fallback menu (mobile).
 */
function vila_akoya_fallback_primary_menu_mobile() {
	echo vila_akoya_get_nav_links_markup( 'mobile' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Footer fallback links.
 */
function vila_akoya_footer_fallback() {
	$items = array(
		array( 'label' => __( 'Village', 'vila-akoya' ), 'url' => home_url( '/village/' ) ),
		array( 'label' => __( 'Campsite', 'vila-akoya' ), 'url' => home_url( '/campsite/' ) ),
		array( 'label' => __( 'Booking', 'vila-akoya' ), 'url' => home_url( '/booking/' ) ),
		array( 'label' => __( 'The Campus', 'vila-akoya' ), 'url' => home_url( '/campus/' ) ),
		array( 'label' => __( 'The Kids', 'vila-akoya' ), 'url' => home_url( '/the-kids/' ) ),
		array( 'label' => __( 'About', 'vila-akoya' ), 'url' => home_url( '/about/' ) ),
		array( 'label' => __( 'Contact', 'vila-akoya' ), 'url' => home_url( '/contact/' ) ),
	);
	foreach ( $items as $item ) {
		printf(
			'<a class="text-orange-50/80 hover:text-orange-50 text-base font-light font-body hover:translate-y-[-2px] transition-transform duration-300" href="%s">%s</a>',
			esc_url( $item['url'] ),
			esc_html( $item['label'] )
		);
	}
}

/**
 * Default nav links as in brochure HTML.
 *
 * @param string $context 'desktop'|'mobile'.
 * @return string HTML.
 */
function vila_akoya_get_nav_links_markup( $context = 'desktop' ) {
	$items = array(
		array( 'slug' => 'village', 'label' => __( 'Village', 'vila-akoya' ), 'url' => home_url( '/village/' ) ),
		array( 'slug' => 'campsite', 'label' => __( 'Campsite', 'vila-akoya' ), 'url' => home_url( '/campsite/' ) ),
		array( 'slug' => 'booking', 'label' => __( 'Booking', 'vila-akoya' ), 'url' => home_url( '/booking/' ) ),
		array( 'slug' => 'campus', 'label' => __( 'The Campus', 'vila-akoya' ), 'url' => home_url( '/campus/' ) ),
		array( 'slug' => 'the-kids', 'label' => __( 'The Kids', 'vila-akoya' ), 'url' => home_url( '/the-kids/' ) ),
		array( 'slug' => 'about', 'label' => __( 'About', 'vila-akoya' ), 'url' => home_url( '/about/' ) ),
		array( 'slug' => 'contact', 'label' => __( 'Contact', 'vila-akoya' ), 'url' => home_url( '/contact/' ) ),
	);
	$out = '';
	foreach ( $items as $item ) {
		if ( 'mobile' === $context ) {
			$out .= sprintf(
				'<a data-nav="%1$s" class="block rounded-lg px-2 py-3 text-stone-600 dark:text-stone-400 hover:bg-[#9a4021]/5 dark:hover:bg-[#ba5737]/10" href="%2$s">%3$s</a>',
				esc_attr( $item['slug'] ),
				esc_url( $item['url'] ),
				esc_html( $item['label'] )
			);
		} else {
			$out .= sprintf(
				'<a data-nav="%1$s" class="text-stone-600 dark:text-stone-400 hover:text-[#9a4021] transition-colors hover:bg-[#9a4021]/5 dark:hover:bg-[#ba5737]/10 px-2 py-1 rounded-md" href="%2$s">%3$s</a>',
				esc_attr( $item['slug'] ),
				esc_url( $item['url'] ),
				esc_html( $item['label'] )
			);
		}
	}
	return $out;
}

/**
 * Nav walkers output plain <a> with data-nav for JS active states.
 */
class Vila_Akoya_Nav_Walker extends Walker_Nav_Menu {
	/**
	 * Start element.
	 *
	 * @param string $output Output.
	 * @param object $item   Item.
	 * @param int    $depth  Depth.
	 * @param array  $args   Args.
	 * @param int    $id     ID.
	 */
	public function start_el( &$output, $item, $depth = 0, $args = array(), $id = 0 ) {
		$slug = vila_akoya_nav_item_slug( $item );
		$output .= sprintf(
			'<a data-nav="%s" class="text-stone-600 dark:text-stone-400 hover:text-[#9a4021] transition-colors hover:bg-[#9a4021]/5 dark:hover:bg-[#ba5737]/10 px-2 py-1 rounded-md" href="%s">%s</a>',
			esc_attr( $slug ),
			esc_url( $item->url ),
			esc_html( $item->title )
		);
	}
}

/**
 * Mobile walker.
 */
class Vila_Akoya_Nav_Walker_Mobile extends Walker_Nav_Menu {
	/**
	 * Start element.
	 *
	 * @param string $output Output.
	 * @param object $item   Item.
	 * @param int    $depth  Depth.
	 * @param array  $args   Args.
	 * @param int    $id     ID.
	 */
	public function start_el( &$output, $item, $depth = 0, $args = array(), $id = 0 ) {
		$slug = vila_akoya_nav_item_slug( $item );
		$output .= sprintf(
			'<a data-nav="%s" class="block rounded-lg px-2 py-3 text-stone-600 dark:text-stone-400 hover:bg-[#9a4021]/5 dark:hover:bg-[#ba5737]/10" href="%s">%s</a>',
			esc_attr( $slug ),
			esc_url( $item->url ),
			esc_html( $item->title )
		);
	}
}

/**
 * Footer: flat anchor links (no list).
 */
class Vila_Akoya_Footer_Nav_Walker extends Walker_Nav_Menu {
	/**
	 * Start element.
	 *
	 * @param string $output Output.
	 * @param object $item   Item.
	 * @param int    $depth  Depth.
	 * @param array  $args   Args.
	 * @param int    $id     ID.
	 */
	public function start_el( &$output, $item, $depth = 0, $args = array(), $id = 0 ) {
		$output .= sprintf(
			'<a class="text-orange-50/80 hover:text-orange-50 text-base font-light font-body hover:translate-y-[-2px] transition-transform duration-300" href="%s">%s</a>',
			esc_url( $item->url ),
			esc_html( $item->title )
		);
	}
}

/**
 * Map menu item URL to data-nav key.
 *
 * @param WP_Post $item Menu item.
 * @return string
 */
function vila_akoya_nav_item_slug( $item ) {
	$path = wp_parse_url( $item->url, PHP_URL_PATH );
	$path = trim( (string) $path, '/' );
	if ( '' === $path || 'home' === $path ) {
		return '';
	}
	$parts = explode( '/', $path );
	$last  = end( $parts );
	if ( 'the-kids' === $last ) {
		return 'the-kids';
	}
	if ( 'booking' === $last ) {
		return 'booking';
	}
	if ( 'campus' === $last ) {
		return 'campus';
	}
	if ( 'campsite' === $last ) {
		return 'campsite';
	}
	if ( 'village' === $last ) {
		return 'village';
	}
	if ( 'about' === $last ) {
		return 'about';
	}
	if ( 'contact' === $last ) {
		return 'contact';
	}
	return sanitize_title( $last );
}
