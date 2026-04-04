<?php
/**
 * One-time: pages, CF7 forms, reading options.
 *
 * @package Vila_Akoya
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run after theme switch.
 * Note: Do not require current_user_can() — WP-CLI (`wp theme activate`) has no logged-in user,
 * and skipping setup leaves pages empty.
 */
function vila_akoya_after_switch_theme(): void {
	vila_akoya_run_setup( false );
}
add_action( 'after_switch_theme', 'vila_akoya_after_switch_theme' );

/**
 * Create pages and optional CF7 forms.
 *
 * @param bool $force When true, import page content even if setup version matches (repair).
 */
function vila_akoya_run_setup( bool $force = false ): void {
	if ( ! $force && get_option( 'vila_akoya_setup_version' ) === VILA_AKOYA_VERSION ) {
		return;
	}

	/*
	 * Saving raw block HTML requires unfiltered_html. CLI / cron may have no user, which strips content.
	 */
	$previous_user_id = get_current_user_id();
	if ( ! current_user_can( 'unfiltered_html' ) ) {
		$admin_id = vila_akoya_first_admin_user_id();
		if ( $admin_id ) {
			wp_set_current_user( $admin_id );
		}
	}

	$pages = array(
		'home'      => array( 'title' => 'Home', 'file' => 'home.txt' ),
		'about'     => array( 'title' => 'About', 'file' => 'about.txt' ),
		'village'   => array( 'title' => 'Village', 'file' => 'village.txt' ),
		'campsite'  => array( 'title' => 'Campsite', 'file' => 'campsite.txt' ),
		'booking'   => array( 'title' => 'Booking', 'file' => 'booking.txt' ),
		'campus'    => array( 'title' => 'Campus', 'file' => 'campus.txt' ),
		'the-kids'  => array( 'title' => 'The Kids', 'file' => 'the-kids.txt' ),
		'contact'   => array( 'title' => 'Contact', 'file' => 'contact.txt' ),
		'thank-you' => array( 'title' => 'Thank You', 'file' => 'thank-you.txt' ),
	);

	$dir = VILA_AKOYA_DIR . '/inc/page-content/';
	$ids = array();
	$imported = 0;

	foreach ( $pages as $slug => $cfg ) {
		$path = $dir . $cfg['file'];
		if ( ! is_readable( $path ) ) {
			continue;
		}
		$content = file_get_contents( $path );
		if ( false === $content || '' === trim( $content ) ) {
			continue;
		}
		$content = str_replace( 'THEME_URI', VILA_AKOYA_URI, $content );
		// Convert legacy Custom HTML (wp:html) chunks to native core blocks for the block editor.
		if ( str_contains( $content, '<!-- wp:html -->' ) && function_exists( 'vila_akoya_convert_page_content_to_native_blocks' ) ) {
			$content = vila_akoya_convert_page_content_to_native_blocks( $content );
		}

		$existing = get_page_by_path( $slug, OBJECT, 'page' );
		if ( $existing ) {
			wp_update_post(
				array(
					'ID'           => $existing->ID,
					'post_content' => $content,
				)
			);
			$ids[ $slug ] = $existing->ID;
			++$imported;
		} else {
			$new_id = wp_insert_post(
				array(
					'post_title'   => $cfg['title'],
					'post_name'    => $slug,
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_content' => $content,
				),
				true
			);
			if ( ! is_wp_error( $new_id ) ) {
				$ids[ $slug ] = (int) $new_id;
				++$imported;
			}
		}
	}

	if ( ! empty( $ids['home'] ) ) {
		update_option( 'page_on_front', $ids['home'] );
		update_option( 'show_on_front', 'page' );
	}

	if ( post_type_exists( 'wpcf7_contact_form' ) ) {
		vila_akoya_create_cf7_forms();
	}

	// Only mark complete when we actually pulled content from disk (avoids locking empty state).
	if ( $imported > 0 ) {
		update_option( 'vila_akoya_setup_version', VILA_AKOYA_VERSION );
	}

	wp_set_current_user( $previous_user_id );
}

/**
 * First administrator user ID (for elevating privileges during CLI import).
 */
function vila_akoya_first_admin_user_id(): int {
	$users = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	return ! empty( $users ) ? (int) $users[0] : 1;
}

/**
 * Re-import brochure HTML if core pages are missing or still empty (KSES/CLI issues).
 */
function vila_akoya_needs_brochure_import(): bool {
	$slugs = array(
		'home',
		'about',
		'village',
		'campsite',
		'booking',
		'campus',
		'the-kids',
		'contact',
		'thank-you',
	);
	foreach ( $slugs as $slug ) {
		$p = get_page_by_path( $slug, OBJECT, 'page' );
		if ( ! $p ) {
			return true;
		}
		$raw = (string) get_post_field( 'post_content', $p->ID, 'raw' );
		// Brochure pages are block HTML; empty or nearly empty means import never landed.
		if ( strlen( trim( $raw ) ) < 200 ) {
			return true;
		}
	}
	return false;
}

/**
 * If brochure pages are incomplete, re-run import (e.g. first activation was via WP-CLI with no user).
 */
function vila_akoya_repair_empty_pages(): void {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( vila_akoya_needs_brochure_import() ) {
		vila_akoya_run_setup( true );
	}
}
add_action( 'admin_init', 'vila_akoya_repair_empty_pages', 5 );

/**
 * After a theme version bump, re-import page content from disk (e.g. HTML → native blocks migration).
 */
function vila_akoya_upgrade_from_theme_version(): void {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( get_option( 'vila_akoya_setup_version' ) === VILA_AKOYA_VERSION ) {
		return;
	}
	vila_akoya_run_setup( true );
}
add_action( 'admin_init', 'vila_akoya_upgrade_from_theme_version', 4 );

/**
 * Create Contact Form 7 entries and store IDs.
 */
function vila_akoya_create_cf7_forms(): void {
	$admin_email = (string) get_option( 'admin_email' );
	$blogname    = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );

	$forms = array(
		array(
			'option' => 'vila_akoya_cf7_home_newsletter',
			'title'  => 'Newsletter — Home',
			'form'   => "[email* your-email placeholder \"your@email.com\"]\n[submit \"Subscribe\"]",
			'subject' => 'Vila Akoya — Home newsletter signup',
			'body'    => "From: [your-email]\n\nPlease add this email address to the mailing list. Signup: Home page newsletter.\n",
		),
		array(
			'option' => 'vila_akoya_cf7_village_waitlist',
			'title'  => 'Waitlist — Village',
			'form'   => "[email* your-email placeholder \"Your email address\"]\n[submit \"Join the Waitlist\"]",
			'subject' => 'Vila Akoya — Village mailing list signup',
			'body'    => "From: [your-email]\n\nPlease add this email address to the mailing list. Signup: Village page waitlist.\n",
		),
		array(
			'option' => 'vila_akoya_cf7_campus_host',
			'title'  => 'Host your own — Campus',
			'form'   => "[text* your-name]\n[email* your-email]\n[textarea* your-message]\n[checkbox guest-updates \"Keep me updated on guest facilitator openings.\"]\n[submit \"Send Inquiry\"]",
			'subject' => 'Vila Akoya — Campus: host your own',
			'body'    => "From: [your-name] <[your-email]>\n\n[your-message]\n\nGuest updates: [guest-updates]\n",
		),
		array(
			'option' => 'vila_akoya_cf7_booking',
			'title'  => 'Booking inquiry',
			'form'   => "[text* your-name]\n[email* your-email]\n[tel your-phone]\n[radio* primary-interest use_label_element \"Village\" \"Campsite\" \"Both\"]\n[textarea* your-message]\n[submit \"Send Inquiry\"]",
			'subject' => 'Vila Akoya — booking inquiry',
			'body'    => "From: [your-name] <[your-email]>\nPhone: [your-phone]\nPrimary interest: [primary-interest]\n\n[your-message]\n",
		),
		array(
			'option' => 'vila_akoya_cf7_contact',
			'title'  => 'Contact',
			'form'   => "[text* your-name]\n[email* your-email]\n[tel your-phone]\n[textarea* your-message]\n[submit \"Send message\"]",
			'subject' => 'Vila Akoya — contact form',
			'body'    => "From: [your-name] <[your-email]>\nPhone: [your-phone]\n\n[your-message]\n",
		),
	);

	foreach ( $forms as $def ) {
		$existing = absint( get_option( $def['option'], 0 ) );
		if ( $existing && get_post( $existing ) ) {
			continue;
		}
		$id = vila_akoya_insert_cf7_form( $def['title'], $def['form'], $def['subject'], $def['body'], $admin_email, $blogname );
		if ( $id ) {
			update_option( $def['option'], $id );
		}
	}
}

/**
 * Insert a CF7 form post.
 *
 * @param string $title Form title.
 * @param string $form_body Form template.
 * @param string $subject Email subject.
 * @param string $body Email body.
 * @param string $to Recipient.
 * @param string $sender_name Site name.
 * @return int Post ID.
 */
function vila_akoya_insert_cf7_form( string $title, string $form_body, string $subject, string $body, string $to, string $sender_name ): int {
	if ( ! post_type_exists( 'wpcf7_contact_form' ) ) {
		return 0;
	}

	$post_id = wp_insert_post(
		array(
			'post_title'  => $title,
			'post_status' => 'publish',
			'post_type'   => 'wpcf7_contact_form',
		),
		true
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return 0;
	}

	update_post_meta( $post_id, '_form', $form_body );

	$mail = array(
		'active'              => true,
		'subject'             => $subject,
		'sender'              => sprintf( '%s <%s>', $sender_name, $to ),
		'body'                => $body,
		'recipient'           => $to,
		'additional_headers'  => 'Reply-To: [your-email]',
		'attachments'         => '',
		'use_html'            => false,
		'exclude_blank'       => true,
	);

	update_post_meta( $post_id, '_mail', $mail );

	if ( function_exists( 'wpcf7_flush_cache' ) ) {
		wpcf7_flush_cache();
	}

	return (int) $post_id;
}
