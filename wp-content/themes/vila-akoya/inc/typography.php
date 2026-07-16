<?php
/**
 * Bridge Site Editor typography (Appearance → Editor → Styles) to theme CSS variables.
 *
 * Tailwind utilities (font-body, font-headline, font-barrio) read --vila-font-* so brochure
 * sections, nav, and booking UI follow the same fonts as Text / H2 / H1 in Global Styles.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Convert a theme.json / global-styles fontFamily value into usable CSS.
 *
 * @param mixed $value Raw fontFamily from wp_get_global_styles().
 * @return string CSS font-family value, or empty string if unusable.
 */
function vila_akoya_resolve_font_family_value( $value ) {
	if ( ! is_string( $value ) ) {
		return '';
	}

	$value = trim( $value );
	if ( '' === $value ) {
		return '';
	}

	// Internal preset ref: var:preset|font-family|body
	if ( preg_match( '/^var:preset\|font-family\|(.+)$/', $value, $matches ) ) {
		$slug = sanitize_title( $matches[1] );
		return $slug ? 'var(--wp--preset--font-family--' . $slug . ')' : '';
	}

	return $value;
}

/**
 * Font stacks for body copy, section headings, and brand/display (H1).
 *
 * @return array{body: string, headline: string, barrio: string}
 */
function vila_akoya_get_role_font_families() {
	$defaults = array(
		'body'     => 'var(--wp--preset--font-family--body)',
		'headline' => 'var(--wp--preset--font-family--headline)',
		'barrio'   => 'var(--wp--preset--font-family--barrio)',
	);

	if ( ! function_exists( 'wp_get_global_styles' ) ) {
		return $defaults;
	}

	$styles = wp_get_global_styles();
	if ( ! is_array( $styles ) ) {
		return $defaults;
	}

	$body = vila_akoya_resolve_font_family_value(
		$styles['typography']['fontFamily'] ?? ''
	);

	$h1 = vila_akoya_resolve_font_family_value(
		$styles['elements']['h1']['typography']['fontFamily'] ?? ''
	);

	$headline = vila_akoya_resolve_font_family_value(
		$styles['elements']['h2']['typography']['fontFamily'] ?? ''
	);
	if ( '' === $headline ) {
		$headline = vila_akoya_resolve_font_family_value(
			$styles['elements']['heading']['typography']['fontFamily'] ?? ''
		);
	}
	if ( '' === $headline ) {
		$headline = vila_akoya_resolve_font_family_value(
			$styles['elements']['h3']['typography']['fontFamily'] ?? ''
		);
	}

	return array(
		'body'     => $body !== '' ? $body : $defaults['body'],
		'headline' => $headline !== '' ? $headline : $defaults['headline'],
		'barrio'   => $h1 !== '' ? $h1 : $defaults['barrio'],
	);
}

/**
 * CSS custom properties consumed by Tailwind font-* utilities.
 *
 * @return string
 */
function vila_akoya_typography_bridge_css() {
	$fonts = vila_akoya_get_role_font_families();

	$sanitize = static function ( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		return str_replace( array( '{', '}', ';', '<', '>' ), '', $value );
	};

	return sprintf(
		':root{--vila-font-body:%1$s;--vila-font-headline:%2$s;--vila-font-barrio:%3$s;}',
		$sanitize( $fonts['body'] ),
		$sanitize( $fonts['headline'] ),
		$sanitize( $fonts['barrio'] )
	);
}

/**
 * Attach bridge CSS after the main theme stylesheet (front end + block editor).
 *
 * @param string $handle Style handle that already exists.
 */
function vila_akoya_enqueue_typography_bridge( $handle ) {
	if ( ! wp_style_is( $handle, 'enqueued' ) && ! wp_style_is( $handle, 'registered' ) ) {
		return;
	}
	wp_add_inline_style( $handle, vila_akoya_typography_bridge_css() );
}

/**
 * Front-end bridge.
 */
function vila_akoya_enqueue_typography_bridge_front() {
	vila_akoya_enqueue_typography_bridge( 'vila-akoya-theme' );
}
add_action( 'wp_enqueue_scripts', 'vila_akoya_enqueue_typography_bridge_front', 20 );

/**
 * Block / site editor bridge so the canvas matches the front end.
 */
function vila_akoya_enqueue_typography_bridge_editor() {
	vila_akoya_enqueue_typography_bridge( 'vila-akoya-theme-editor' );
}
add_action( 'enqueue_block_editor_assets', 'vila_akoya_enqueue_typography_bridge_editor', 20 );
