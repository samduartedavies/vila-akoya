<?php
/**
 * One-click deploy helpers (database patches after uploading theme files).
 *
 * Visit while logged in as an administrator:
 *   /?vila_akoya_deploy=1
 *   /?vila_akoya_deploy=launch-banner
 *   /?vila_akoya_deploy=booking-page
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * @return string[]
 */
function vila_akoya_deploy_task_keys() {
	return array( 'launch-banner', 'booking-page', 'all' );
}

/**
 * Add the launch-festival banner block to the Home page (idempotent).
 *
 * @return true|WP_Error
 */
function vila_akoya_deploy_enable_launch_banner() {
	if ( ! function_exists( 'acf_register_block_type' ) ) {
		return new WP_Error( 'acf_missing', __( 'Secure Custom Fields (or ACF) must be active.', 'vila-akoya' ) );
	}

	$page = get_page_by_path( 'home', OBJECT, 'page' );
	if ( ! $page instanceof WP_Post ) {
		return new WP_Error( 'home_missing', __( 'Home page not found.', 'vila-akoya' ) );
	}

	$content = (string) $page->post_content;
	if ( str_contains( $content, 'acf/vila-announcement-banner' ) ) {
		return true;
	}

	$rows = vila_akoya_home_section_rows();
	$row  = null;
	foreach ( $rows as $candidate ) {
		if ( 'announcement_banner' === ( $candidate['acf_fc_layout'] ?? '' ) ) {
			$row = $candidate;
			break;
		}
	}

	if ( ! is_array( $row ) ) {
		return new WP_Error( 'seed_missing', __( 'No announcement banner seed data found.', 'vila-akoya' ) );
	}

	unset( $row['acf_fc_layout'] );
	$block = vila_akoya_section_row_to_acf_block( 'announcement_banner', $row );
	if ( '' === $block ) {
		return new WP_Error( 'block_failed', __( 'Could not build the announcement banner block.', 'vila-akoya' ) );
	}

	$new_content = trim( $block ) . "\n\n" . ltrim( $content );
	$updated     = wp_update_post(
		wp_slash(
			array(
				'ID'           => (int) $page->ID,
				'post_content' => $new_content,
			)
		),
		true
	);

	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	clean_post_cache( (int) $page->ID );

	return true;
}

/**
 * Rebuild the Booking page from the theme HTML seed (tabs + accommodation grid).
 *
 * @return true|WP_Error
 */
function vila_akoya_deploy_patch_booking_page() {
	$page = get_page_by_path( 'booking', OBJECT, 'page' );
	if ( ! $page instanceof WP_Post ) {
		return new WP_Error( 'booking_missing', __( 'Booking page not found.', 'vila-akoya' ) );
	}

	if ( ! function_exists( 'vila_akoya_build_booking_page_html' ) ) {
		return new WP_Error( 'builder_missing', __( 'Booking page builder is not available.', 'vila-akoya' ) );
	}

	$html = vila_akoya_build_booking_page_html();
	if ( '' === trim( $html ) ) {
		return new WP_Error( 'html_empty', __( 'Booking page HTML seed is empty.', 'vila-akoya' ) );
	}

	$updated = wp_update_post(
		wp_slash(
			array(
				'ID'           => (int) $page->ID,
				'post_content' => $html,
			)
		),
		true
	);

	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	update_option( 'vila_akoya_booking_content_version', '3' );
	clean_post_cache( (int) $page->ID );

	return true;
}

/**
 * @param string $task Task key.
 * @return array{ok:bool, messages:list<string>, errors:list<string>}
 */
function vila_akoya_run_deploy_task( $task ) {
	$task = sanitize_key( (string) $task );
	if ( 'all' === $task || '1' === $task || '' === $task ) {
		$tasks = array( 'launch-banner', 'booking-page' );
	} else {
		$tasks = array( $task );
	}

	$result = array(
		'ok'       => true,
		'messages' => array(),
		'errors'   => array(),
	);

	foreach ( $tasks as $key ) {
		if ( 'launch-banner' === $key ) {
			$run = vila_akoya_deploy_enable_launch_banner();
			if ( is_wp_error( $run ) ) {
				$result['ok']       = false;
				$result['errors'][] = $run->get_error_message();
			} else {
				$result['messages'][] = __( 'Launch banner: Home page updated (or was already in place).', 'vila-akoya' );
			}
			continue;
		}

		if ( 'booking-page' === $key ) {
			$run = vila_akoya_deploy_patch_booking_page();
			if ( is_wp_error( $run ) ) {
				$result['ok']       = false;
				$result['errors'][] = $run->get_error_message();
			} else {
				$result['messages'][] = __( 'Booking page: tabs and accommodation panel refreshed from theme seed.', 'vila-akoya' );
			}
		}
	}

	return $result;
}

/**
 * Handle /?vila_akoya_deploy=… for logged-in administrators.
 */
function vila_akoya_maybe_handle_deploy_url() {
	if ( is_admin() ) {
		return;
	}

	$raw = isset( $_GET['vila_akoya_deploy'] ) ? wp_unslash( (string) $_GET['vila_akoya_deploy'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( '' === $raw ) {
		return;
	}

	if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
		wp_die(
			esc_html__( 'Log in as a site administrator, then open this link again.', 'vila-akoya' ),
			esc_html__( 'Vila Akoya deploy', 'vila-akoya' ),
			array( 'response' => 403 )
		);
	}

	$task   = ( '1' === $raw ) ? 'all' : sanitize_key( $raw );
	$result = vila_akoya_run_deploy_task( $task );

	$title   = $result['ok'] ? __( 'Deploy complete', 'vila-akoya' ) : __( 'Deploy finished with errors', 'vila-akoya' );
	$body    = '';
	$home    = esc_url( home_url( '/' ) );
	$booking = esc_url( home_url( '/booking/' ) );

	if ( $result['messages'] ) {
		$body .= '<ul>';
		foreach ( $result['messages'] as $message ) {
			$body .= '<li>' . esc_html( $message ) . '</li>';
		}
		$body .= '</ul>';
	}
	if ( $result['errors'] ) {
		$body .= '<ul style="color:#9a1c1c">';
		foreach ( $result['errors'] as $error ) {
			$body .= '<li>' . esc_html( $error ) . '</li>';
		}
		$body .= '</ul>';
	}

	$body .= '<p>' . esc_html__( 'Purge your site cache (LiteSpeed, etc.) if changes are not visible yet.', 'vila-akoya' ) . '</p>';
	$body .= '<p><a href="' . $home . '">' . esc_html__( 'View homepage', 'vila-akoya' ) . '</a> · ';
	$body .= '<a href="' . $booking . '">' . esc_html__( 'View booking page', 'vila-akoya' ) . '</a></p>';

	wp_die( $body, esc_html( $title ), array( 'response' => $result['ok'] ? 200 : 500 ) );
}
add_action( 'template_redirect', 'vila_akoya_maybe_handle_deploy_url', 0 );

/**
 * Admin notice with deploy links (logged-in administrators only).
 */
function vila_akoya_deploy_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$home = get_page_by_path( 'home', OBJECT, 'page' );
	$needs_banner = ( $home instanceof WP_Post ) && ! str_contains( (string) $home->post_content, 'acf/vila-announcement-banner' );

	$booking = get_page_by_path( 'booking', OBJECT, 'page' );
	$needs_booking = ( $booking instanceof WP_Post ) && (
		str_contains( (string) $booking->post_content, 'data-va-booking-tab="village"' )
		|| ! str_contains( (string) $booking->post_content, 'data-va-booking-tab="accommodation"' )
	);

	if ( ! $needs_banner && ! $needs_booking ) {
		return;
	}

	$deploy_all = esc_url( add_query_arg( 'vila_akoya_deploy', '1', home_url( '/' ) ) );
	$deploy_banner = esc_url( add_query_arg( 'vila_akoya_deploy', 'launch-banner', home_url( '/' ) ) );
	$deploy_booking = esc_url( add_query_arg( 'vila_akoya_deploy', 'booking-page', home_url( '/' ) ) );

	echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Vila Akoya theme files are updated but the database still needs a one-time sync.', 'vila-akoya' ) . '</strong></p><ul style="list-style:disc;margin-left:1.25rem">';
	if ( $needs_banner ) {
		echo '<li><a href="' . $deploy_banner . '">' . esc_html__( 'Add launch banner to homepage', 'vila-akoya' ) . '</a></li>';
	}
	if ( $needs_booking ) {
		echo '<li><a href="' . $deploy_booking . '">' . esc_html__( 'Fix booking page tabs (Accommodation)', 'vila-akoya' ) . '</a></li>';
	}
	echo '<li><a href="' . $deploy_all . '">' . esc_html__( 'Run all deploy tasks', 'vila-akoya' ) . '</a></li>';
	echo '</ul><p class="description">' . esc_html__( 'You must be logged in as an administrator. Purge LiteSpeed/cache after running.', 'vila-akoya' ) . '</p></div>';
}
add_action( 'admin_notices', 'vila_akoya_deploy_admin_notice' );
