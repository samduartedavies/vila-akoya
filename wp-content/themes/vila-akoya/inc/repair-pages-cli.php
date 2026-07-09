<?php
/**
 * Repair brochure pages after a bad image migration (restores missing images).
 *
 * Safe to run on live — only replaces empty self-closing group blocks with
 * group+image snippets from inc/page-content/ seed templates. Text edits are kept.
 *
 * Usage (live SSH):
 *   wp eval-file wp-content/themes/vila-akoya/inc/repair-pages-cli.php
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

require_once VILA_AKOYA_DIR . '/inc/seed.php';
require_once VILA_AKOYA_DIR . '/inc/page-sync.php';

$stats = vila_akoya_repair_all_seed_page_images();
$msg   = sprintf(
	'Repair complete: %d page(s) updated, %d skipped (~%d empty image shells processed).',
	$stats['repaired'],
	$stats['skipped'],
	$stats['empty_shells']
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::success( $msg );
} else {
	echo $msg . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
