<?php
/**
 * Pull live page content into local dev / repo without a full re-seed.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether a path is absolute (wp_is_absolute_path requires WP 6.4+).
 *
 * @param string $path File path.
 * @return bool
 */
function vila_akoya_is_absolute_path( $path ) {
	if ( function_exists( 'wp_is_absolute_path' ) ) {
		return wp_is_absolute_path( $path );
	}

	$path = (string) $path;
	if ( '' === $path ) {
		return false;
	}

	if ( '/' === $path[0] ) {
		return true;
	}

	return (bool) preg_match( '#^[A-Za-z]:[/\\\\]#', $path );
}

/**
 * Resolve a relative path against ABSPATH.
 *
 * @param string $path File path.
 * @return string
 */
function vila_akoya_resolve_path( $path ) {
	$path = function_exists( 'wp_normalize_path' )
		? wp_normalize_path( (string) $path )
		: str_replace( '\\', '/', (string) $path );

	if ( ! vila_akoya_is_absolute_path( $path ) ) {
		$base = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( ABSPATH ) : ABSPATH;
		$path = rtrim( $base, '/' ) . '/' . ltrim( $path, '/' );
	}

	return $path;
}

/**
 * Brochure pages keyed by slug.
 *
 * @return array<string, array{title:string,file:string}>
 */
function vila_akoya_seed_page_map() {
	return array(
		'home'      => array(
			'title' => 'Home',
			'file'  => 'home-blocks.html',
		),
		'about'     => array( 'title' => 'About', 'file' => 'about-blocks.html' ),
		'village'   => array( 'title' => 'Village', 'file' => 'village-blocks.html' ),
		'campsite'  => array( 'title' => 'Campsite', 'file' => 'campsite-blocks.html' ),
		'booking'   => array( 'title' => 'Booking', 'file' => 'booking-blocks.html' ),
		'campus'    => array( 'title' => 'The Campus', 'file' => 'campus-blocks.html' ),
		'the-kids'  => array( 'title' => 'The Kids', 'file' => 'the-kids-blocks.html' ),
		'contact'   => array( 'title' => 'Contact', 'file' => 'contact-blocks.html' ),
		'thank-you' => array( 'title' => 'Thank You', 'file' => 'thank-you-blocks.html' ),
		'blog'      => array( 'title' => 'Blog', 'file' => 'blog-blocks.html' ),
	);
}

/**
 * Export brochure page content from the database.
 *
 * @return array<string, array{slug:string,title:string,ID:int,post_content:string,post_modified:string}>
 */
function vila_akoya_export_seed_pages() {
	$out = array();

	foreach ( vila_akoya_seed_page_map() as $slug => $def ) {
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		if ( ! $page instanceof WP_Post ) {
			continue;
		}

		$out[ $slug ] = array(
			'slug'          => $slug,
			'title'         => (string) $page->post_title,
			'ID'            => (int) $page->ID,
			'post_content'  => (string) $page->post_content,
			'post_modified' => (string) $page->post_modified_gmt,
		);
	}

	return $out;
}

/**
 * Write export JSON to disk.
 *
 * @param string $path Absolute or relative path.
 * @return string Absolute path written.
 */
function vila_akoya_write_pages_export( $path ) {
	$path = vila_akoya_resolve_path( $path );
	$dir  = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	$payload = array(
		'exported_at' => gmdate( 'c' ),
		'site_url'    => home_url( '/' ),
		'pages'       => vila_akoya_export_seed_pages(),
	);

	$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( ! is_string( $json ) ) {
		return '';
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $path, $json . "\n" );

	return $path;
}

/**
 * Read export JSON from disk.
 *
 * @param string $path File path.
 * @return array<string, mixed>|null
 */
function vila_akoya_read_pages_export( $path ) {
	if ( ! is_readable( $path ) ) {
		return null;
	}

	$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( ! is_string( $raw ) || '' === $raw ) {
		return null;
	}

	$data = json_decode( $raw, true );
	return is_array( $data ) ? $data : null;
}

/**
 * Import exported pages into the local database.
 *
 * @param array<string, mixed> $export Export payload.
 * @param bool                 $migrate_images Run image-block migration first (off by default).
 * @return array{updated:int,skipped:int,migrated:int}
 */
function vila_akoya_import_pages_to_database( array $export, $migrate_images = false ) {
	$pages = isset( $export['pages'] ) && is_array( $export['pages'] ) ? $export['pages'] : array();
	$stats = array(
		'updated'  => 0,
		'skipped'  => 0,
		'migrated' => 0,
	);

	foreach ( vila_akoya_seed_page_map() as $slug => $def ) {
		if ( empty( $pages[ $slug ]['post_content'] ) || ! is_string( $pages[ $slug ]['post_content'] ) ) {
			++$stats['skipped'];
			continue;
		}

		$content = $pages[ $slug ]['post_content'];
		if ( $migrate_images ) {
			$migrated = vila_akoya_migrate_image_blocks_in_content( $content );
			if ( $migrated !== $content ) {
				++$stats['migrated'];
				$content = $migrated;
			}
		}

		$existing = get_page_by_path( $slug, OBJECT, 'page' );
		$args     = array(
			'post_title'   => isset( $pages[ $slug ]['title'] ) ? (string) $pages[ $slug ]['title'] : $def['title'],
			'post_name'    => $slug,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => $content,
		);

		if ( $existing instanceof WP_Post ) {
			$args['ID'] = (int) $existing->ID;
			wp_update_post( $args );
		} else {
			wp_insert_post( $args, true );
		}

		++$stats['updated'];
	}

	return $stats;
}

/**
 * Write exported page content into theme inc/page-content/ for version control.
 *
 * @param array<string, mixed> $export Export payload.
 * @param bool                 $migrate_images Normalize image blocks before writing.
 * @return array{written:int,skipped:int}
 */
function vila_akoya_import_pages_to_repo_files( array $export, $migrate_images = false ) {
	$pages = isset( $export['pages'] ) && is_array( $export['pages'] ) ? $export['pages'] : array();
	$dir   = VILA_AKOYA_DIR . '/inc/page-content/';
	$stats = array(
		'written' => 0,
		'skipped' => 0,
	);

	foreach ( vila_akoya_seed_page_map() as $slug => $def ) {
		if ( empty( $pages[ $slug ]['post_content'] ) || ! is_string( $pages[ $slug ]['post_content'] ) ) {
			++$stats['skipped'];
			continue;
		}

		$content = vila_akoya_normalize_content_for_repo( $pages[ $slug ]['post_content'] );
		if ( $migrate_images ) {
			$content = vila_akoya_migrate_image_blocks_in_content( $content );
		}

		$path = $dir . $def['file'];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, rtrim( $content ) . "\n" );
		++$stats['written'];
	}

	return $stats;
}

/**
 * Prepare live DB content for committed page-content HTML files.
 *
 * @param string $html Block content.
 * @return string
 */
function vila_akoya_normalize_content_for_repo( $html ) {
	$html = is_string( $html ) ? $html : '';
	if ( '' === $html ) {
		return $html;
	}

	$html = vila_akoya_repo_replace_cf7_shortcodes( $html );
	$html = vila_akoya_repo_replace_wc_shortcodes( $html );
	$html = vila_akoya_repo_replace_upload_image_urls( $html );

	return $html;
}

/**
 * Replace live CF7 shortcodes with {{cf7:key}} placeholders.
 *
 * @param string $html HTML content.
 * @return string
 */
function vila_akoya_repo_replace_cf7_shortcodes( $html ) {
	if ( ! function_exists( 'vila_akoya_get_cf7_form_ids' ) ) {
		return $html;
	}

	$id_to_key = array_flip( array_map( 'strval', vila_akoya_get_cf7_form_ids() ) );

	return preg_replace_callback(
		'/\[contact-form-7\s+([^\]]+)\]/',
		static function ( array $matches ) use ( $id_to_key ) {
			$attrs = (string) $matches[1];
			if ( ! preg_match( '/\bid="(\d+)"/', $attrs, $id_match ) ) {
				return (string) $matches[0];
			}
			$key = $id_to_key[ $id_match[1] ] ?? '';
			return '' !== $key ? '{{cf7:' . $key . '}}' : (string) $matches[0];
		},
		$html
	);
}

/**
 * Replace live WooCommerce product shortcodes with {{wc:…}} placeholders.
 *
 * @param string $html HTML content.
 * @return string
 */
function vila_akoya_repo_replace_wc_shortcodes( $html ) {
	$pid = function_exists( 'vila_akoya_get_campsite_primary_product_id' )
		? (int) vila_akoya_get_campsite_primary_product_id()
		: (int) get_option( 'vila_akoya_campsite_product_id' );

	if ( ! $pid ) {
		return $html;
	}

	return preg_replace(
		'/\[product_page\s+id="' . preg_quote( (string) $pid, '/' ) . '"\s*\]/',
		'{{wc:campsite_booking}}',
		$html
	);
}

/**
 * Replace wp-content/uploads image URLs with brochure CDN URLs by filename.
 *
 * @param string $html HTML content.
 * @return string
 */
function vila_akoya_repo_replace_upload_image_urls( $html ) {
	return preg_replace_callback(
		'#(?:https?://[^"\'\s>]+)?/wp-content/uploads/[^"\'\s>]*/([^/"\'\s>]+\.(?:jpe?g|png|gif|webp))#i',
		static function ( array $matches ) {
			return 'https://vila-akoya.com/images/' . strtolower( (string) $matches[1] );
		},
		$html
	);
}

/**
 * Classes that may stay on core/image blocks (editor-safe + semantic CSS hooks).
 *
 * @return string[]
 */
function vila_akoya_image_block_preserved_classes() {
	return array(
		'vila-img-fill',
		'vila-home-hero-image',
		'vila-home-village-image',
		'vila-home-campsite-image',
	);
}

/**
 * Tailwind utilities that only fill a parent aspect-ratio shell.
 *
 * @return string[]
 */
function vila_akoya_image_block_redundant_shell_classes() {
	return array(
		'w-full',
		'h-full',
		'object-cover',
		'min-h-0',
		'min-h-full',
		'max-w-none',
	);
}

/**
 * Split image block classes into preserved vs shell (wrapper) classes.
 *
 * @param string $class_name Space-separated class list.
 * @return array{keep:string,shell:string}
 */
function vila_akoya_split_image_block_classes( $class_name ) {
	$preserve = array_flip( vila_akoya_image_block_preserved_classes() );
	$classes  = preg_split( '/\s+/', trim( (string) $class_name ) ) ?: array();
	$keep     = array();
	$shell    = array();

	foreach ( $classes as $class ) {
		if ( '' === $class || isset( $preserve[ $class ] ) ) {
			if ( '' !== $class && isset( $preserve[ $class ] ) ) {
				$keep[] = $class;
			}
			continue;
		}
		$shell[] = $class;
	}

	if ( empty( $keep ) || ! in_array( 'vila-img-fill', $keep, true ) ) {
		$keep[] = 'vila-img-fill';
	}

	return array(
		'keep'  => implode( ' ', array_unique( $keep ) ),
		'shell' => implode( ' ', array_unique( $shell ) ),
	);
}

/**
 * Whether a group block already provides an image frame.
 *
 * @param array<string, mixed>|null $parent Parent block.
 * @return bool
 */
function vila_akoya_group_is_image_shell( $parent ) {
	if ( ! is_array( $parent ) || 'core/group' !== ( $parent['blockName'] ?? '' ) ) {
		return false;
	}

	$class = (string) ( $parent['attrs']['className'] ?? '' );
	if ( '' === $class ) {
		return false;
	}

	return (bool) preg_match(
		'/(?:aspect-|overflow-hidden|rounded-|h-\[|min-h-|va-[\w-]+-shell|vila-[\w-]+-shell)/',
		$class
	);
}

/**
 * Keep core/image innerHTML aligned with attrs (required for block validation).
 *
 * @param array<string, mixed> $block Image block.
 * @return array<string, mixed>
 */
function vila_akoya_sync_image_block_markup( array $block ) {
	$attrs     = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
	$size_slug = isset( $attrs['sizeSlug'] ) ? (string) $attrs['sizeSlug'] : 'large';
	$class     = isset( $attrs['className'] ) ? trim( (string) $attrs['className'] ) : '';
	$classes   = trim( 'wp-block-image size-' . $size_slug . ( '' !== $class ? ' ' . $class : '' ) );

	$inner = (string) ( $block['innerHTML'] ?? '' );
	if ( '' !== $inner && preg_match( '/<figure\b[^>]*>/', $inner ) ) {
		$inner = preg_replace(
			'/<figure\b[^>]*>/',
			'<figure class="' . esc_attr( $classes ) . '">',
			$inner,
			1
		);
		$block['innerHTML']    = $inner;
		$block['innerContent'] = array( $inner );
	}

	return $block;
}

/**
 * Wrap a block in a core/group shell with valid innerContent for serialize_block().
 *
 * @param array<string, mixed> $block         Inner block.
 * @param string               $shell_classes Wrapper class names.
 * @return array<string, mixed>
 */
function vila_akoya_wrap_block_in_group_shell( array $block, $shell_classes ) {
	$shell_classes = trim( (string) $shell_classes );
	$opening       = '<div class="wp-block-group ' . esc_attr( $shell_classes ) . '">';

	return array(
		'blockName'    => 'core/group',
		'attrs'        => array(
			'className' => $shell_classes,
			'layout'    => array( 'type' => 'default' ),
		),
		'innerBlocks'  => array( $block ),
		'innerHTML'    => $opening . '</div>',
		'innerContent' => array( $opening, null, '</div>' ),
	);
}

/**
 * Collect serialized group+image snippets from seed markup (depth-first order).
 *
 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
 * @return string[]
 */
function vila_akoya_collect_group_image_snippets( array $blocks ) {
	$snippets = array();

	foreach ( $blocks as $block ) {
		if ( empty( $block['blockName'] ) ) {
			continue;
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$inner = $block['innerBlocks'];
			$has_image_child = false;
			foreach ( $inner as $child ) {
				if ( 'core/image' === ( $child['blockName'] ?? '' ) ) {
					$has_image_child = true;
					break;
				}
			}

			if ( $has_image_child && 'core/group' === $block['blockName'] ) {
				$snippets[] = serialize_block( $block );
			} else {
				$snippets = array_merge( $snippets, vila_akoya_collect_group_image_snippets( $inner ) );
			}
		}
	}

	return $snippets;
}

/**
 * Replace self-closing empty group blocks with group+image snippets from seed HTML.
 *
 * @param string $content   Broken page content.
 * @param string $seed_html Seed template for the same page.
 * @return string
 */
function vila_akoya_repair_empty_image_shells_in_content( $content, $seed_html ) {
	$content = is_string( $content ) ? $content : '';
	if ( '' === $content || ! str_contains( $content, '} /-->' ) ) {
		return $content;
	}

	$seed_html = is_string( $seed_html ) ? $seed_html : '';
	if ( '' === $seed_html || ! has_blocks( $seed_html ) ) {
		return $content;
	}

	$snippets = vila_akoya_collect_group_image_snippets( parse_blocks( $seed_html ) );
	if ( empty( $snippets ) ) {
		return $content;
	}

	$index = 0;
	$fixed = preg_replace_callback(
		'/<!-- wp:group (\{[^}]+\}) \/-->/',
		static function ( array $matches ) use ( $snippets, &$index ) {
			if ( ! isset( $snippets[ $index ] ) ) {
				return $matches[0];
			}
			$snippet = $snippets[ $index ];
			++$index;
			return $snippet;
		},
		$content
	);

	// WordPress sometimes corrupts `--` in class names to `u002d` in block JSON.
	$fixed = is_string( $fixed )
		? str_replace( array( 'u002du002d', 'u002d' ), array( '--', '-' ), $fixed )
		: $content;

	return is_string( $fixed ) ? $fixed : $content;
}

/**
 * Repair all brochure pages using inc/page-content/ seed files as image templates.
 *
 * @return array{repaired:int,skipped:int,empty_shells:int}
 */
function vila_akoya_repair_all_seed_page_images() {
	$stats = array(
		'repaired'     => 0,
		'skipped'      => 0,
		'empty_shells' => 0,
	);

	$dir = VILA_AKOYA_DIR . '/inc/page-content/';

	foreach ( vila_akoya_seed_page_map() as $slug => $def ) {
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		if ( ! $page instanceof WP_Post ) {
			++$stats['skipped'];
			continue;
		}

		$seed_path = $dir . $def['file'];
		if ( ! is_readable( $seed_path ) ) {
			++$stats['skipped'];
			continue;
		}

		$seed_html = file_get_contents( $seed_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$before    = (string) $page->post_content;
		$shells    = substr_count( $before, '} /-->' );
		$repaired  = vila_akoya_repair_empty_image_shells_in_content( $before, $seed_html );

		if ( function_exists( 'vila_akoya_seed_localize_image_urls' ) ) {
			$repaired = vila_akoya_seed_localize_image_urls( $repaired );
		}

		if ( $repaired === $before ) {
			++$stats['skipped'];
			continue;
		}

		wp_update_post(
			array(
				'ID'           => (int) $page->ID,
				'post_content' => $repaired,
			)
		);

		++$stats['repaired'];
		$stats['empty_shells'] += $shells;
	}

	return $stats;
}

/**
 * Recursively migrate core/image blocks to editor-safe markup.
 *
 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
 * @param array<string, mixed>|null        $parent Parent block.
 * @return array<int, array<string, mixed>>
 */
function vila_akoya_migrate_image_blocks( array $blocks, $parent = null ) {
	$out = array();

	foreach ( $blocks as $block ) {
		if ( empty( $block['blockName'] ) ) {
			continue;
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = vila_akoya_migrate_image_blocks( $block['innerBlocks'], $block );
		}

		if ( 'core/image' === $block['blockName'] ) {
			$split = vila_akoya_split_image_block_classes( (string) ( $block['attrs']['className'] ?? '' ) );
			$shell = $split['shell'];

			if ( vila_akoya_group_is_image_shell( $parent ) ) {
				$redundant = array_flip( vila_akoya_image_block_redundant_shell_classes() );
				$shell     = implode(
					' ',
					array_filter(
						preg_split( '/\s+/', $shell ) ?: array(),
						static function ( $class ) use ( $redundant ) {
							return '' !== $class && ! isset( $redundant[ $class ] );
						}
					)
				);
			}

			$block['attrs']         = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$block['attrs']['className'] = $split['keep'];
			$block                  = vila_akoya_sync_image_block_markup( $block );

			if ( '' !== trim( $shell ) ) {
				$block = vila_akoya_wrap_block_in_group_shell( $block, trim( $shell ) );
			}
		}

		$out[] = $block;
	}

	return $out;
}

/**
 * Migrate image blocks in serialized block content.
 *
 * @param string $content Post content.
 * @return string
 */
function vila_akoya_migrate_image_blocks_in_content( $content ) {
	$content = is_string( $content ) ? $content : '';
	if ( '' === $content || ! has_blocks( $content ) ) {
		return $content;
	}

	$blocks  = vila_akoya_migrate_image_blocks( parse_blocks( $content ) );
	$parts   = array();
	foreach ( $blocks as $block ) {
		if ( empty( $block['blockName'] ) ) {
			continue;
		}
		$parts[] = serialize_block( $block );
	}

	return implode( "\n\n", $parts );
}

/**
 * Migrate image blocks for all brochure pages in the database.
 *
 * @return array{updated:int,unchanged:int,missing:int}
 */
function vila_akoya_migrate_all_seed_page_images() {
	$stats = array(
		'updated'   => 0,
		'unchanged' => 0,
		'missing'   => 0,
	);

	foreach ( vila_akoya_seed_page_map() as $slug => $def ) {
		unset( $def );
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		if ( ! $page instanceof WP_Post ) {
			++$stats['missing'];
			continue;
		}

		$migrated = vila_akoya_migrate_image_blocks_in_content( $page->post_content );
		if ( $migrated === $page->post_content ) {
			++$stats['unchanged'];
			continue;
		}

		wp_update_post(
			array(
				'ID'           => (int) $page->ID,
				'post_content' => $migrated,
			)
		);
		++$stats['updated'];
	}

	return $stats;
}
