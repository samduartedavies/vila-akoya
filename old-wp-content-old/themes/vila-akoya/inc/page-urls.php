<?php
/**
 * Pretty permalinks, static homepage, and navigation URL resolution.
 *
 * @package vila-akoya
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page slugs used across the site (path segment = slug).
 *
 * @return array<int, string>
 */
function vila_akoya_page_slugs() {
	return array(
		'home',
		'the-village',
		'the-campsite',
		'book',
		'about',
		'contact',
		'homeschooling-collective',
		'the-campus',
		'thank-you',
	);
}

/**
 * Set /%postname%/ permalinks, sync page slugs, assign Home as front page, flush rewrites once.
 *
 * Runs after page seeding (init priority 30).
 */
function vila_akoya_configure_permalinks_and_home() {
	if ( get_option( 'vila_akoya_urls_configured' ) ) {
		return;
	}

	// Pretty URLs: /the-village/, /book/, etc.
	if ( get_option( 'permalink_structure' ) !== '/%postname%/' ) {
		update_option( 'permalink_structure', '/%postname%/' );
	}

	foreach ( vila_akoya_page_slugs() as $slug ) {
		$found = get_posts(
			array(
				'name'             => $slug,
				'post_type'        => 'page',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'   => 1,
				'suppress_filters' => true,
			)
		);

		if ( empty( $found ) ) {
			continue;
		}

		$page = $found[0];
		if ( $page->post_name !== $slug ) {
			wp_update_post(
				array(
					'ID'        => $page->ID,
					'post_name' => $slug,
				)
			);
		}
	}

	$home_pages = get_posts(
		array(
			'name'             => 'home',
			'post_type'        => 'page',
			'post_status'      => 'publish',
			'posts_per_page'   => 1,
			'suppress_filters' => true,
		)
	);

	if ( ! empty( $home_pages ) ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', (int) $home_pages[0]->ID );

		// No separate “Posts” page — keep blog off the root when needed later.
		update_option( 'page_for_posts', 0 );

		vila_akoya_ensure_htaccess_rewrite_rules();
		flush_rewrite_rules( false );

		// Only mark complete once the Home page exists; otherwise we’d skip fixing Reading forever.
		update_option( 'vila_akoya_urls_configured', true );
	}
}
add_action( 'init', 'vila_akoya_configure_permalinks_and_home', 30 );

/**
 * Ensure Apache rewrite rules exist for pretty permalinks in local wp-env.
 *
 * If .htaccess is missing, Apache returns 404 for /slug/ URLs before WordPress boots.
 */
function vila_akoya_ensure_htaccess_rewrite_rules() {
	$htaccess_path = trailingslashit( ABSPATH ) . '.htaccess';
	if ( file_exists( $htaccess_path ) ) {
		return;
	}

	if ( ! is_writable( ABSPATH ) ) {
		return;
	}

	$rules = <<<HTACCESS
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress

HTACCESS;

	file_put_contents( $htaccess_path, $rules );
}

/**
 * Keep Settings → Reading aligned with the seeded “Home” page (fixes “Hello World” on the front).
 *
 * Runs every request (cheap); needed when urls were marked configured before Home existed, or Reading was changed in admin.
 */
function vila_akoya_ensure_static_homepage() {
	$home_pages = get_posts(
		array(
			'name'             => 'home',
			'post_type'        => 'page',
			'post_status'      => 'publish',
			'posts_per_page'   => 1,
			'suppress_filters' => true,
		)
	);

	if ( empty( $home_pages ) ) {
		return;
	}

	$home_id = (int) $home_pages[0]->ID;

	if ( 'page' !== get_option( 'show_on_front' ) || (int) get_option( 'page_on_front' ) !== $home_id ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $home_id );
		update_option( 'page_for_posts', 0 );
	}
}
add_action( 'init', 'vila_akoya_ensure_static_homepage', 40 );

/**
 * Turn root-relative nav hrefs into full URLs (supports subdirectory installs).
 *
 * @param string $block_content Block HTML.
 * @param array  $block         Parsed block.
 * @return string
 */
function vila_akoya_resolve_navigation_link_urls( $block_content, $block ) {
	if ( empty( $block['blockName'] ) || 'core/navigation-link' !== $block['blockName'] ) {
		return $block_content;
	}

	if ( empty( $block['attrs']['url'] ) ) {
		return $block_content;
	}

	$url = $block['attrs']['url'];
	if ( preg_match( '#^https?://#i', $url ) ) {
		return $block_content;
	}

	$resolved = esc_url( home_url( $url ) );

	$replaced = preg_replace(
		'/href=(["\'])' . preg_quote( $url, '/' ) . '\1/',
		'href=$1' . $resolved . '$1',
		$block_content,
		1
	);

	return is_string( $replaced ) ? $replaced : $block_content;
}
add_filter( 'render_block', 'vila_akoya_resolve_navigation_link_urls', 10, 2 );

/**
 * Resolve root-relative button hrefs (same as navigation links).
 *
 * @param string $block_content Block HTML.
 * @param array  $block         Parsed block.
 * @return string
 */
function vila_akoya_resolve_button_block_urls( $block_content, $block ) {
	if ( empty( $block['blockName'] ) || 'core/button' !== $block['blockName'] ) {
		return $block_content;
	}

	if ( empty( $block['attrs']['url'] ) ) {
		return $block_content;
	}

	$url = $block['attrs']['url'];
	if ( preg_match( '#^https?://#i', $url ) ) {
		return $block_content;
	}

	$resolved = esc_url( home_url( $url ) );

	$replaced = preg_replace(
		'/href=(["\'])' . preg_quote( $url, '/' ) . '\1/',
		'href=$1' . $resolved . '$1',
		$block_content,
		1
	);

	return is_string( $replaced ) ? $replaced : $block_content;
}
add_filter( 'render_block', 'vila_akoya_resolve_button_block_urls', 11, 2 );
