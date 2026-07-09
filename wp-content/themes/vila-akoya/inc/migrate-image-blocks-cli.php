<?php
/**
 * Migrate image blocks in brochure pages (safe on live — only restructures images).
 *
 * Usage (live SSH, after deploying theme):
 *   wp eval-file wp-content/themes/vila-akoya/inc/migrate-image-blocks-cli.php
 *
 * Local:
 *   npm run wp:migrate-images
 *
 * @package Vila_Akoya
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$theme_dir = dirname( __DIR__ );
if ( ! defined( 'VILA_AKOYA_DIR' ) ) {
	define( 'VILA_AKOYA_DIR', $theme_dir );
}

require_once VILA_AKOYA_DIR . '/inc/page-sync.php';

$stats = vila_akoya_migrate_all_seed_page_images();
$msg   = sprintf(
	'Image migration complete: %d updated, %d unchanged, %d missing.',
	$stats['updated'],
	$stats['unchanged'],
	$stats['missing']
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::success( $msg );
} else {
	echo $msg . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
