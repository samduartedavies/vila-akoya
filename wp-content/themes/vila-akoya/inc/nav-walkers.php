<?php
/**
 * Custom nav menu walkers for header and footer markup.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Header nav walker — desktop and mobile link classes.
 */
class Vila_Akoya_Header_Nav_Walker extends Walker_Nav_Menu {

	/**
	 * @var string
	 */
	private $variant = 'desktop';

	/**
	 * @param string $variant desktop|mobile
	 */
	public function __construct( $variant = 'desktop' ) {
		$this->variant = 'mobile' === $variant ? 'mobile' : 'desktop';
	}

	/**
	 * @param string   $output Output.
	 * @param WP_Post  $item   Menu item.
	 * @param int      $depth  Depth.
	 * @param stdClass $args   Args.
	 * @param int      $id     ID.
	 */
	public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
		unset( $depth, $id );
		$args   = is_object( $args ) ? $args : (object) array();
		$slug   = vila_akoya_nav_item_slug( $item );
		if ( in_array( $slug, vila_akoya_hidden_nav_slugs(), true ) ) {
			return;
		}
		$base   = 'mobile' === $this->variant
			? 'block px-2 py-3 text-stone-600 transition-colors'
			: 'text-stone-600 transition-colors px-3 py-1.5 rounded-full';
		$attrs  = ' data-nav="' . esc_attr( $slug ) . '" class="' . esc_attr( $base ) . '"';
		if ( ! empty( $item->current ) || ! empty( $item->current_item_ancestor ) ) {
			$attrs .= ' aria-current="page"';
		}
		$output .= '<a' . $attrs . ' href="' . esc_url( $item->url ) . '">' . esc_html( $item->title ) . '</a>';
	}

	/**
	 * @param string   $output Output.
	 * @param WP_Post  $item   Menu item.
	 * @param int      $depth  Depth.
	 * @param stdClass $args   Args.
	 */
	public function end_el( &$output, $item, $depth = 0, $args = null ) {
		unset( $output, $item, $depth, $args );
	}
}

/**
 * Footer nav walker.
 */
class Vila_Akoya_Footer_Nav_Walker extends Walker_Nav_Menu {

	/**
	 * @param string   $output Output.
	 * @param WP_Post  $item   Menu item.
	 * @param int      $depth  Depth.
	 * @param stdClass $args   Args.
	 * @param int      $id     ID.
	 */
	public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
		unset( $depth, $id );
		$class = 'text-orange-50/80 hover:text-orange-50 text-base font-light font-body hover:translate-y-[-2px] transition-transform duration-300';
		$output .= '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $item->url ) . '">' . esc_html( $item->title ) . '</a>';
	}

	/**
	 * @param string   $output Output.
	 * @param WP_Post  $item   Menu item.
	 * @param int      $depth  Depth.
	 * @param stdClass $args   Args.
	 */
	public function end_el( &$output, $item, $depth = 0, $args = null ) {
		unset( $output, $item, $depth, $args );
	}
}

/**
 * Derive brochure-style data-nav slug from a menu item.
 *
 * @param WP_Post $item Menu item.
 * @return string
 */
function vila_akoya_nav_item_slug( $item ) {
	$path = (string) wp_parse_url( (string) $item->url, PHP_URL_PATH );
	$path = trim( $path, '/' );
	if ( '' === $path ) {
		return 'home';
	}
	$parts = explode( '/', $path );
	return sanitize_title( (string) end( $parts ) );
}

/**
 * Nav slugs hidden from the header (still shown in the footer).
 *
 * @return list<string>
 */
function vila_akoya_hidden_nav_slugs() {
	return array( 'booking' );
}

/**
 * Default brochure nav items (fallback when no menu assigned).
 *
 * @return list<array{slug:string,label:string}>
 */
function vila_akoya_default_nav_items() {
	return array(
		array( 'slug' => 'village', 'label' => 'The Village' ),
		array( 'slug' => 'campsite', 'label' => 'The Campsite' ),
		array( 'slug' => 'campus', 'label' => 'The Campus' ),
		array( 'slug' => 'events', 'label' => 'Events' ),
		array( 'slug' => 'the-kids', 'label' => 'The Kids' ),
		array( 'slug' => 'about', 'label' => 'About Us' ),
		array( 'slug' => 'contact', 'label' => 'Contact' ),
	);
}

/**
 * Default footer nav items (includes blog).
 *
 * @return list<array{slug:string,label:string}>
 */
function vila_akoya_default_footer_nav_items() {
	$items   = vila_akoya_default_nav_items();
	$booking = array( 'slug' => 'booking', 'label' => 'Join us' );
	array_splice( $items, 2, 0, array( $booking ) );
	$items[] = array( 'slug' => 'blog', 'label' => 'Blog' );
	return $items;
}

/**
 * Render fallback header links.
 *
 * @param string $variant desktop|mobile
 * @param string $current_nav Active slug.
 * @param callable $h URL helper.
 */
function vila_akoya_render_fallback_header_links( $variant, $current_nav, $h ) {
	$base = 'mobile' === $variant
		? 'block px-2 py-3 text-stone-600 transition-colors'
		: 'text-stone-600 transition-colors px-3 py-1.5 rounded-full';
	foreach ( vila_akoya_default_nav_items() as $item ) {
		$slug = $item['slug'];
		if ( in_array( $slug, vila_akoya_hidden_nav_slugs(), true ) ) {
			continue;
		}
		$attrs = ' data-nav="' . esc_attr( $slug ) . '" class="' . esc_attr( $base ) . '"';
		if ( $slug === $current_nav ) {
			$attrs .= ' aria-current="page"';
		}
		echo '<a' . $attrs . ' href="' . esc_url( $h( $slug ) ) . '">' . esc_html( $item['label'] ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * Render fallback footer links.
 *
 * @param callable $h URL helper.
 */
function vila_akoya_render_fallback_footer_links( $h ) {
	$class = 'text-orange-50/80 hover:text-orange-50 text-base font-light font-body hover:translate-y-[-2px] transition-transform duration-300';
	foreach ( vila_akoya_default_footer_nav_items() as $item ) {
		echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $h( $item['slug'] ) ) . '">' . esc_html( $item['label'] ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * Render header nav links from menu or fallback.
 *
 * @param string   $location  Theme location.
 * @param string   $variant   desktop|mobile
 * @param string   $current_nav Active slug.
 * @param callable $h URL helper.
 */
function vila_akoya_render_header_nav_links( $location, $variant, $current_nav, $h ) {
	if ( has_nav_menu( $location ) ) {
		wp_nav_menu(
			array(
				'theme_location' => $location,
				'container'      => false,
				'items_wrap'     => '%3$s',
				'depth'          => 1,
				'fallback_cb'    => false,
				'walker'         => new Vila_Akoya_Header_Nav_Walker( $variant ),
			)
		);
		return;
	}
	vila_akoya_render_fallback_header_links( $variant, $current_nav, $h );
}

/**
 * Render footer nav links from menu or fallback.
 *
 * @param string   $location Theme location.
 * @param callable $h URL helper.
 */
function vila_akoya_render_footer_nav_links( $location, $h ) {
	if ( has_nav_menu( $location ) ) {
		wp_nav_menu(
			array(
				'theme_location' => $location,
				'container'      => false,
				'items_wrap'     => '%3$s',
				'depth'          => 1,
				'fallback_cb'    => false,
				'walker'         => new Vila_Akoya_Footer_Nav_Walker(),
			)
		);
		return;
	}
	vila_akoya_render_fallback_footer_links( $h );
}

/**
 * Make sure the footer menu includes Events (archive URL).
 *
 * @return void
 */
function vila_akoya_ensure_footer_events_link() {
	if ( (string) get_option( 'vila_akoya_footer_events_nav', '' ) === '1' ) {
		return;
	}
	$locations = get_theme_mod( 'nav_menu_locations', array() );
	if ( empty( $locations['footer'] ) ) {
		return;
	}
	$menu_id = (int) $locations['footer'];
	$items   = wp_get_nav_menu_items( $menu_id );
	foreach ( (array) $items as $item ) {
		if ( 'events' === vila_akoya_nav_item_slug( $item ) ) {
			update_option( 'vila_akoya_footer_events_nav', '1' );
			return;
		}
	}

	$position = is_array( $items ) ? count( $items ) + 1 : 1;
	wp_update_nav_menu_item(
		$menu_id,
		0,
		array(
			'menu-item-title'    => __( 'Events', 'vila-akoya' ),
			'menu-item-type'     => 'custom',
			'menu-item-url'      => home_url( '/events/' ),
			'menu-item-status'   => 'publish',
			'menu-item-position' => $position,
		)
	);
	update_option( 'vila_akoya_footer_events_nav', '1' );
}
add_action( 'wp', 'vila_akoya_ensure_footer_events_link', 20 );

/**
 * Sync header menu: show Events, hide Join us (booking page link).
 *
 * @return void
 */
function vila_akoya_sync_header_nav_items() {
	if ( (string) get_option( 'vila_akoya_header_nav_v2', '' ) === '1' ) {
		return;
	}
	$locations = get_theme_mod( 'nav_menu_locations', array() );
	if ( empty( $locations['header'] ) ) {
		update_option( 'vila_akoya_header_nav_v2', '1' );
		return;
	}
	$menu_id = (int) $locations['header'];
	$items   = wp_get_nav_menu_items( $menu_id );
	if ( ! is_array( $items ) ) {
		update_option( 'vila_akoya_header_nav_v2', '1' );
		return;
	}

	$has_events = false;
	foreach ( $items as $item ) {
		$slug = vila_akoya_nav_item_slug( $item );
		if ( 'booking' === $slug ) {
			wp_delete_post( (int) $item->ID, true );
			continue;
		}
		if ( 'events' === $slug ) {
			$has_events = true;
		}
	}

	if ( ! $has_events ) {
		$position = count( $items ) + 1;
		foreach ( $items as $item ) {
			if ( 'campus' === vila_akoya_nav_item_slug( $item ) ) {
				$position = (int) $item->menu_order + 1;
				break;
			}
		}
		wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'    => __( 'Events', 'vila-akoya' ),
				'menu-item-type'     => 'custom',
				'menu-item-url'      => home_url( '/events/' ),
				'menu-item-status'   => 'publish',
				'menu-item-position' => $position,
			)
		);
	}

	update_option( 'vila_akoya_header_nav_v2', '1' );
}
add_action( 'wp', 'vila_akoya_sync_header_nav_items', 19 );
