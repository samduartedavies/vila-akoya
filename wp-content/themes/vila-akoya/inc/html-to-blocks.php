<?php
/**
 * Convert brochure HTML fragments to native block markup (no core/html) for visual editing.
 *
 * @package Vila_Akoya
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Map of placeholder index → shortcode inner text while merging HTML (see vila_akoya_convert_page_content_to_native_blocks).
 *
 * @var array<int, string>|null
 */
$GLOBALS['vila_akoya_sc_map'] = null;

/**
 * Convert full page content: merge wp:html chunks with shortcode placeholders, then convert to native blocks.
 *
 * Merging fixes brochure markup split across <!-- wp:html --> / <!-- wp:shortcode --> (e.g. CF7 mid-section).
 *
 * @param string $content Raw post content from page-content/*.txt.
 * @return string
 */
function vila_akoya_convert_page_content_to_native_blocks( string $content ): string {
	if ( ! str_contains( $content, '<!-- wp:html -->' ) ) {
		return trim( $content ) . "\n";
	}

	if ( ! preg_match_all( '/<!-- wp:(html|shortcode) -->\s*([\s\S]*?)\s*<!-- \/wp:\1 -->/', $content, $matches, PREG_SET_ORDER ) ) {
		return trim( $content ) . "\n";
	}

	$merged = '';
	$map    = array();
	$idx    = 0;
	foreach ( $matches as $m ) {
		if ( 'html' === $m[1] ) {
			$merged .= $m[2];
		} else {
			++$idx;
			$map[ $idx ] = trim( $m[2] );
			$merged     .= '<div data-vila-sc="' . $idx . '"></div>';
		}
	}

	$GLOBALS['vila_akoya_sc_map'] = $map;
	$converted                    = vila_akoya_convert_html_fragment_to_blocks( trim( $merged ) );
	$GLOBALS['vila_akoya_sc_map'] = null;

	return trim( $converted ) . "\n";
}

/**
 * Convert an HTML fragment (usually a section) to serialized core blocks.
 *
 * @param string $html HTML (no wp: wrappers).
 * @return string
 */
function vila_akoya_convert_html_fragment_to_blocks( string $html ): string {
	$html = trim( $html );
	if ( '' === $html ) {
		return '';
	}

	libxml_use_internal_errors( true );
	$wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="vila-root">' . $html . '</div></body></html>';
	$dom     = new DOMDocument();
	$dom->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	$root      = $dom->getElementById( 'vila-root' );
	$blocks    = array();
	if ( $root ) {
		foreach ( $root->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				$s = vila_akoya_dom_element_to_blocks( $child );
				if ( '' !== $s ) {
					$blocks[] = $s;
				}
			}
		}
	}
	libxml_clear_errors();

	return implode( "\n\n", array_filter( $blocks ) );
}

/**
 * @param DOMElement $el Element.
 * @return string Serialized blocks.
 */
function vila_akoya_dom_element_to_blocks( DOMElement $el ): string {
	$tag = strtolower( $el->tagName );

	if ( 'section' === $tag ) {
		$inner = vila_akoya_dom_children_to_blocks( $el );
		return vila_akoya_block_group( vila_akoya_dom_get_class( $el ), $inner );
	}

	if ( 'div' === $tag ) {
		return vila_akoya_convert_div( $el );
	}

	if ( preg_match( '/^h[1-6]$/', $tag ) ) {
		return vila_akoya_block_heading_from_element( $el );
	}

	if ( 'p' === $tag ) {
		return vila_akoya_block_paragraph_from_element( $el );
	}

	if ( 'ul' === $tag || 'ol' === $tag ) {
		return vila_akoya_block_list_from_element( $el );
	}

	if ( 'img' === $tag ) {
		return vila_akoya_block_image_from_element( $el );
	}

	if ( 'span' === $tag ) {
		// Wrap as paragraph so it stays editable (icons, labels).
		$inner = $el->ownerDocument->saveHTML( $el );
		return vila_akoya_block_paragraph( vila_akoya_dom_get_class( $el ), $inner );
	}

	if ( 'a' === $tag ) {
		return vila_akoya_block_paragraph( '', '<p>' . $el->ownerDocument->saveHTML( $el ) . '</p>' );
	}

	// Fallback: minimal group with inner HTML as paragraph (still editable vs raw HTML block).
	$html = '';
	foreach ( $el->childNodes as $child ) {
		$html .= $el->ownerDocument->saveHTML( $child );
	}
	if ( '' === trim( $html ) ) {
		return '';
	}
	return vila_akoya_block_paragraph( vila_akoya_dom_get_class( $el ), '<p>' . $html . '</p>' );
}

/**
 * @param DOMElement $el Div element.
 * @return string
 */
function vila_akoya_convert_div( DOMElement $el ): string {
	// Injected when merging page HTML + shortcodes (see vila_akoya_convert_page_content_to_native_blocks).
	if ( $el->hasAttribute( 'data-vila-sc' ) ) {
		$idx  = (int) $el->getAttribute( 'data-vila-sc' );
		$map  = isset( $GLOBALS['vila_akoya_sc_map'] ) && is_array( $GLOBALS['vila_akoya_sc_map'] ) ? $GLOBALS['vila_akoya_sc_map'] : array();
		$body = isset( $map[ $idx ] ) ? $map[ $idx ] : '';
		return "<!-- wp:shortcode -->\n" . $body . "\n<!-- /wp:shortcode -->";
	}

	$class = vila_akoya_dom_get_class( $el );

	// Two-column grid → columns block.
	if ( str_contains( $class, 'grid' ) && preg_match( '/grid-cols-1\s+md:grid-cols-2|grid-cols-2/', $class ) ) {
		$cols = array();
		foreach ( $el->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}
			if ( 'div' !== strtolower( $child->tagName ) ) {
				continue;
			}
			$col_inner = vila_akoya_dom_children_to_blocks( $child );
			if ( '' === trim( $col_inner ) ) {
				continue;
			}
			$cols[] = vila_akoya_block_column( vila_akoya_dom_get_class( $child ), $col_inner );
		}
		if ( count( $cols ) >= 2 ) {
			return vila_akoya_block_columns( $class, implode( "\n\n", $cols ) );
		}
	}

	// Flex row of button-like links.
	if ( str_contains( $class, 'flex' ) && vila_akoya_div_direct_children_only_anchors( $el ) ) {
		return vila_akoya_block_buttons_row( $el );
	}

	// Default: group, recurse children.
	$inner = vila_akoya_dom_children_to_blocks( $el );
	if ( '' === trim( $inner ) && '' === trim( $el->textContent ) ) {
		// Decorative empty shell — keep as group for layout/CSS.
		return vila_akoya_block_group( $class, '' );
	}
	return vila_akoya_block_group( $class, $inner );
}

/**
 * True when direct children are only whitespace text nodes and anchor elements (a button row).
 *
 * @param DOMElement $el Div element.
 * @return bool
 */
function vila_akoya_div_direct_children_only_anchors( DOMElement $el ): bool {
	$has_anchor = false;
	foreach ( $el->childNodes as $child ) {
		if ( $child instanceof DOMText ) {
			if ( '' !== trim( $child->textContent ) ) {
				return false;
			}
			continue;
		}
		if ( $child instanceof DOMElement ) {
			if ( 'a' !== strtolower( $child->tagName ) ) {
				return false;
			}
			$has_anchor = true;
		}
	}
	return $has_anchor;
}

/**
 * @param DOMElement $el Element with button links.
 * @return string
 */
function vila_akoya_block_buttons_row( DOMElement $el ): string {
	$class = vila_akoya_dom_get_class( $el );
	$attrs = wp_json_encode(
		array(
			'layout'    => array(
				'type'           => 'flex',
				'justifyContent' => 'center',
			),
			'className' => $class,
		),
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	);

	$inner = '';
	foreach ( $el->childNodes as $child ) {
		if ( ! $child instanceof DOMElement ) {
			continue;
		}
		if ( 'a' !== strtolower( $child->tagName ) ) {
			continue;
		}
		$href = $child->getAttribute( 'href' );
		$txt  = '';
		foreach ( $child->childNodes as $c ) {
			$txt .= $el->ownerDocument->saveHTML( $c );
		}
		$inner .= vila_akoya_block_single_button( vila_akoya_dom_get_class( $child ), $href, $txt );
	}

	return "<!-- wp:buttons {$attrs} -->\n<div class=\"wp-block-buttons\">{$inner}</div>\n<!-- /wp:buttons -->";
}

/**
 * JSON for block comment, or empty when no attributes (avoids invalid `<!-- wp:paragraph [] -->`).
 *
 * @param array<string,mixed> $attrs Block attributes.
 * @return string Space-prefixed JSON or ''.
 */
function vila_akoya_block_attrs_comment( array $attrs ): string {
	$attrs = array_filter(
		$attrs,
		static function ( $v ): bool {
			if ( is_string( $v ) ) {
				return '' !== $v;
			}
			if ( is_array( $v ) ) {
				return array() !== $v;
			}
			return null !== $v;
		}
	);
	if ( array() === $attrs ) {
		return '';
	}
	return ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/**
 * @param string $class Link classes.
 * @param string $href URL.
 * @param string $label HTML label.
 * @return string
 */
function vila_akoya_block_single_button( string $class, string $href, string $label ): string {
	$attrs = wp_json_encode(
		array(
			'className' => $class,
			'url'       => $href,
		),
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	);
	$label = trim( $label );
	return "<!-- wp:button {$attrs} -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button {$class}\" href=\"" . esc_url( $href ) . "\">{$label}</a></div>\n<!-- /wp:button -->";
}

/**
 * @param DOMElement $parent Parent.
 * @return string
 */
function vila_akoya_dom_children_to_blocks( DOMElement $parent ): string {
	$out = array();
	foreach ( $parent->childNodes as $child ) {
		if ( $child instanceof DOMText ) {
			if ( '' === trim( $child->textContent ) ) {
				continue;
			}
			// Loose text → paragraph.
			$out[] = vila_akoya_block_paragraph( '', '<p>' . esc_html( $child->textContent ) . '</p>' );
			continue;
		}
		if ( $child instanceof DOMElement ) {
			$s = vila_akoya_dom_element_to_blocks( $child );
			if ( '' !== $s ) {
				$out[] = $s;
			}
		}
	}
	return implode( "\n\n", $out );
}

/**
 * @param DOMElement $el Element.
 * @return string
 */
function vila_akoya_dom_get_class( DOMElement $el ): string {
	$c = $el->getAttribute( 'class' );
	return is_string( $c ) ? trim( $c ) : '';
}

/**
 * @param DOMElement $el Heading element.
 * @return string
 */
function vila_akoya_block_heading_from_element( DOMElement $el ): string {
	$level = (int) substr( strtolower( $el->tagName ), 1 );
	$level = max( 1, min( 6, $level ) );
	$inner = '';
	foreach ( $el->childNodes as $n ) {
		$inner .= $el->ownerDocument->saveHTML( $n );
	}
	return vila_akoya_block_heading( $level, vila_akoya_dom_get_class( $el ), $inner );
}

/**
 * @param DOMElement $el Paragraph element.
 * @return string
 */
function vila_akoya_block_paragraph_from_element( DOMElement $el ): string {
	$inner = '';
	foreach ( $el->childNodes as $n ) {
		$inner .= $el->ownerDocument->saveHTML( $n );
	}
	return vila_akoya_block_paragraph( vila_akoya_dom_get_class( $el ), '<p>' . $inner . '</p>' );
}

/**
 * Serialize ul/ol as core/list + core/list-item.
 *
 * @param DOMElement $el List element.
 * @return string
 */
function vila_akoya_block_list_from_element( DOMElement $el ): string {
	$tag   = strtolower( $el->tagName );
	$class = vila_akoya_dom_get_class( $el );
	$items = '';
	foreach ( $el->childNodes as $child ) {
		if ( ! $child instanceof DOMElement || 'li' !== strtolower( $child->tagName ) ) {
			continue;
		}
		$li_inner = '';
		foreach ( $child->childNodes as $n ) {
			$li_inner .= $child->ownerDocument->saveHTML( $n );
		}
		$li_inner = trim( $li_inner );
		$items   .= "<!-- wp:list-item -->\n<li>{$li_inner}</li>\n<!-- /wp:list-item -->\n";
	}
	$items     = trim( $items );
	$list_tag  = 'ol' === $tag ? 'ol' : 'ul';
	$attrs     = array();
	if ( 'ol' === $tag ) {
		$attrs['ordered'] = true;
	}
	if ( '' !== $class ) {
		$attrs['className'] = $class;
	}
	$ac        = vila_akoya_block_attrs_comment( $attrs );
	$cls_extra = $class ? ' ' . esc_attr( $class ) : '';
	return "<!-- wp:list{$ac} -->\n<{$list_tag} class=\"wp-block-list{$cls_extra}\">\n{$items}\n</{$list_tag}>\n<!-- /wp:list -->";
}

/**
 * @param DOMElement $el Image element.
 * @return string
 */
function vila_akoya_block_image_from_element( DOMElement $el ): string {
	$url = $el->getAttribute( 'src' );
	$alt = $el->getAttribute( 'alt' );
	return vila_akoya_block_image( $url, $alt, vila_akoya_dom_get_class( $el ) );
}

/**
 * Heading block.
 *
 * @param int    $level 1–6.
 * @param string $className Classes.
 * @param string $inner_html Inner HTML (no wrapping h tag).
 * @return string
 */
function vila_akoya_block_heading( int $level, string $className, string $inner_html ): string {
	$attrs = array(
		'level' => $level,
	);
	if ( '' !== $className ) {
		$attrs['className'] = $className;
	}
	$ac    = vila_akoya_block_attrs_comment( $attrs );
	$inner = trim( $inner_html );
	return "<!-- wp:heading{$ac} -->\n<h{$level} class=\"wp-block-heading" . ( $className ? ' ' . esc_attr( $className ) : '' ) . "\">{$inner}</h{$level}>\n<!-- /wp:heading -->";
}

/**
 * Paragraph block.
 *
 * @param string $className Classes.
 * @param string $p_html Full <p>...</p> or inner only.
 * @return string
 */
function vila_akoya_block_paragraph( string $className, string $p_html ): string {
	$attrs = array();
	if ( '' !== $className ) {
		$attrs['className'] = $className;
	}
	$ac = vila_akoya_block_attrs_comment( $attrs );
	if ( ! preg_match( '/^<p[\s>]/', trim( $p_html ) ) ) {
		$p_html = '<p>' . $p_html . '</p>';
	}
	return "<!-- wp:paragraph{$ac} -->\n{$p_html}\n<!-- /wp:paragraph -->";
}

/**
 * Image block (external or theme URL).
 *
 * @param string $url Image URL.
 * @param string $alt Alt text.
 * @param string $className Classes.
 * @return string
 */
function vila_akoya_block_image( string $url, string $alt, string $className ): string {
	$attrs = array_filter(
		array(
			'url'       => $url,
			'alt'       => $alt,
			'className' => $className,
		),
		function ( $v ) {
			return '' !== $v && null !== $v;
		}
	);
	$ac  = vila_akoya_block_attrs_comment( $attrs );
	$cls = $className ? ' ' . esc_attr( $className ) : '';
	return "<!-- wp:image{$ac} -->\n<figure class=\"wp-block-image{$cls}\"><img src=\"" . esc_url( $url ) . "\" alt=\"" . esc_attr( $alt ) . "\" class=\"\"/></figure>\n<!-- /wp:image -->";
}

/**
 * Group block.
 *
 * @param string $className Classes.
 * @param string $inner_blocks Serialized inner blocks.
 * @return string
 */
function vila_akoya_block_group( string $className, string $inner_blocks ): string {
	$attrs = array(
		'layout' => array( 'type' => 'default' ),
	);
	if ( '' !== $className ) {
		$attrs['className'] = $className;
	}
	$ac  = vila_akoya_block_attrs_comment( $attrs );
	$cls = $className ? esc_attr( $className ) : '';
	return "<!-- wp:group{$ac} -->\n<div class=\"wp-block-group" . ( $cls ? ' ' . $cls : '' ) . "\">\n{$inner_blocks}\n</div>\n<!-- /wp:group -->";
}

/**
 * Columns block.
 *
 * @param string $className Classes for wp-block-columns.
 * @param string $inner Serialized column blocks.
 * @return string
 */
function vila_akoya_block_columns( string $className, string $inner ): string {
	$attrs = array(
		'isStackedOnMobile' => true,
	);
	if ( '' !== $className ) {
		$attrs['className'] = $className;
	}
	$ac  = vila_akoya_block_attrs_comment( $attrs );
	$cls = $className ? esc_attr( $className ) : '';
	return "<!-- wp:columns{$ac} -->\n<div class=\"wp-block-columns" . ( $cls ? ' ' . $cls : '' ) . "\">\n{$inner}\n</div>\n<!-- /wp:columns -->";
}

/**
 * Column block.
 *
 * @param string $className Classes on column.
 * @param string $inner Inner blocks.
 * @return string
 */
function vila_akoya_block_column( string $className, string $inner ): string {
	$attrs = array();
	if ( '' !== $className ) {
		$attrs['className'] = $className;
	}
	$ac  = vila_akoya_block_attrs_comment( $attrs );
	$cls = $className ? esc_attr( $className ) : '';
	return "<!-- wp:column{$ac} -->\n<div class=\"wp-block-column" . ( $cls ? ' ' . $cls : '' ) . "\">\n{$inner}\n</div>\n<!-- /wp:column -->";
}
