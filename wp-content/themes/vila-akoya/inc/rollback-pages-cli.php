<?php
/**
 * Roll back brochure pages to their most recent revision BEFORE a cutoff.
 *
 * This is the safe way to undo the image-migration damage: it restores each
 * page to the last revision WordPress saved before today's breakage, so all
 * image markup and styling classes come back exactly as they were.
 *
 * Dry run first (default) - shows what WOULD be restored, changes nothing:
 *   wp eval-file wp-content/themes/vila-akoya/inc/rollback-pages-cli.php
 *
 * Apply the rollback to every page (positional "apply" — no dashes; Hostinger WP-CLI
 * rejects --flags before they reach eval-file):
 *   wp eval-file wp-content/themes/vila-akoya/inc/rollback-pages-cli.php apply
 *
 * Only certain pages:
 *   wp eval-file wp-content/themes/vila-akoya/inc/rollback-pages-cli.php apply slugs=home,about,village
 *
 * Custom cutoff (restore latest revision strictly before this datetime):
 *   wp eval-file wp-content/themes/vila-akoya/inc/rollback-pages-cli.php apply before="2026-07-06 15:00:00"
 *
 * Roll back N hours ago (default 4 — keeps today's edits, skips recent breakage):
 *   wp eval-file wp-content/themes/vila-akoya/inc/rollback-pages-cli.php apply hours=4
 *
 * Alternative (env var):
 *   VILA_ROLLBACK_APPLY=1 wp eval-file wp-content/themes/vila-akoya/inc/rollback-pages-cli.php
 *
 * @package Vila_Akoya
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Slugs of the brochure pages to roll back. Kept inline (rather than pulled
 * from page-sync.php) so this recovery script has zero dependencies and cannot
 * be taken down by an error in another include.
 */
$vila_rollback_slugs = array(
	'home',
	'about',
	'village',
	'campsite',
	'booking',
	'campus',
	'the-kids',
	'contact',
	'thank-you',
	'blog',
);

/*
 * Hostinger WP-CLI rejects custom --flags on eval-file. Use positional tokens only:
 *   apply
 *   hours=4          (default: 4 hours ago — keeps earlier-today edits)
 *   before=2026-07-06 15:00:00   (overrides hours=)
 *   slugs=home,about
 * Or set VILA_ROLLBACK_APPLY=1 in the environment.
 */
$positional = isset( $args ) && is_array( $args ) ? $args : array();

$apply            = (bool) getenv( 'VILA_ROLLBACK_APPLY' );
$before           = getenv( 'VILA_ROLLBACK_BEFORE' ) ?: '';
$before_explicit  = (bool) getenv( 'VILA_ROLLBACK_BEFORE' );
$hours_ago        = getenv( 'VILA_ROLLBACK_HOURS' ) ? (int) getenv( 'VILA_ROLLBACK_HOURS' ) : 4;
$slugs_raw        = getenv( 'VILA_ROLLBACK_SLUGS' ) ?: '';

foreach ( $positional as $token ) {
	if ( ! is_string( $token ) || '' === $token || '--' === $token ) {
		continue;
	}
	if ( 'apply' === $token || '1' === $token || 'yes' === $token ) {
		$apply = true;
		continue;
	}
	if ( 0 === strpos( $token, 'before=' ) ) {
		$before          = substr( $token, strlen( 'before=' ) );
		$before_explicit = true;
		continue;
	}
	if ( 0 === strpos( $token, 'hours=' ) ) {
		$hours_ago = max( 1, (int) substr( $token, strlen( 'hours=' ) ) );
		continue;
	}
	if ( 0 === strpos( $token, 'slugs=' ) ) {
		$slugs_raw = substr( $token, strlen( 'slugs=' ) );
		continue;
	}
	// Legacy: tolerate --apply if a host ever passes it through.
	if ( '--apply' === $token ) {
		$apply = true;
	} elseif ( 0 === strpos( $token, '--before=' ) ) {
		$before          = substr( $token, strlen( '--before=' ) );
		$before_explicit = true;
	} elseif ( 0 === strpos( $token, '--hours=' ) ) {
		$hours_ago = max( 1, (int) substr( $token, strlen( '--hours=' ) ) );
	} elseif ( 0 === strpos( $token, '--slugs=' ) ) {
		$slugs_raw = substr( $token, strlen( '--slugs=' ) );
	}
}

if ( ! $before_explicit ) {
	$before = gmdate(
		'Y-m-d H:i:s',
		(int) current_time( 'timestamp' ) - ( $hours_ago * HOUR_IN_SECONDS )
	);
}

$slug_filter = array();
if ( '' !== $slugs_raw ) {
	$slug_filter = array_filter( array_map( 'trim', explode( ',', $slugs_raw ) ) );
}

$out = function ( $line ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::log( $line );
	} else {
		echo $line . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
};

$out( sprintf( '%s rollback: restoring latest revision before "%s"%s', $apply ? 'APPLYING' : 'DRY RUN', $before, $before_explicit ? '' : sprintf( ' (%d hours ago)', $hours_ago ) ) );
$out( str_repeat( '-', 60 ) );

$restored = 0;
$skipped  = 0;
$missing  = 0;

foreach ( $vila_rollback_slugs as $slug ) {
	if ( ! empty( $slug_filter ) && ! in_array( $slug, $slug_filter, true ) ) {
		continue;
	}

	$page = get_page_by_path( $slug, OBJECT, 'page' );
	if ( ! $page instanceof WP_Post ) {
		$out( sprintf( '  [missing] %-10s (no page found)', $slug ) );
		++$missing;
		continue;
	}

	$revisions = wp_get_post_revisions(
		$page->ID,
		array(
			'orderby' => 'date',
			'order'   => 'DESC',
		)
	);

	if ( empty( $revisions ) ) {
		$out( sprintf( '  [skip]    %-10s (page %d has no revisions)', $slug, $page->ID ) );
		++$skipped;
		continue;
	}

	$target = null;
	foreach ( $revisions as $rev ) {
		if ( strcmp( (string) $rev->post_date, $before ) < 0 ) {
			$target = $rev;
			break;
		}
	}

	if ( null === $target ) {
		$out( sprintf( '  [skip]    %-10s (no revision before cutoff)', $slug ) );
		++$skipped;
		continue;
	}

	$out(
		sprintf(
			'  [%s] %-10s page=%d  ->  revision=%d (%s)',
			$apply ? 'restore' : 'would  ',
			$slug,
			$page->ID,
			$target->ID,
			$target->post_date
		)
	);

	if ( $apply ) {
		$result = wp_restore_post_revision( $target->ID );
		if ( $result ) {
			++$restored;
		} else {
			$out( sprintf( '            !! failed to restore revision %d for %s', $target->ID, $slug ) );
			++$skipped;
		}
	} else {
		++$restored;
	}
}

$out( str_repeat( '-', 60 ) );
$summary = sprintf(
	'%s %d page(s), skipped %d, missing %d.',
	$apply ? 'Restored' : 'Would restore',
	$restored,
	$skipped,
	$missing
);

if ( ! $apply ) {
	$summary .= ' Re-run with positional "apply" to perform the rollback.';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::success( $summary );
} else {
	echo $summary . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
