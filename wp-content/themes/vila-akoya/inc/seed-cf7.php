<?php
/**
 * Programmatically create Contact Form 7 forms (first run).
 *
 * @package VilaAkoya
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_init',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! post_type_exists( 'wpcf7_contact_form' ) ) {
			return;
		}
		$ids = get_option( 'vila_akoya_cf7_ids', array() );
		if ( ! empty( $ids['newsletter'] ) && ! empty( $ids['contact'] ) ) {
			return;
		}
		vila_akoya_seed_cf7_forms();
	},
	4
);

/**
 * Create CF7 posts if missing; store IDs in option vila_akoya_cf7_ids.
 *
 * @return array<string, int>
 */
function vila_akoya_seed_cf7_forms() {
	$existing = get_option( 'vila_akoya_cf7_ids', array() );
	if ( ! empty( $existing['newsletter'] ) && ! empty( $existing['contact'] ) ) {
		return $existing;
	}

	if ( ! post_type_exists( 'wpcf7_contact_form' ) ) {
		return array();
	}

	$ids = array();

	$ids['newsletter'] = vila_akoya_upsert_cf7(
		'Vila Akoya — Newsletter',
		"[email* your-email placeholder \"your@email.com\"]\n[submit \"Subscribe\"]",
		'Vila Akoya — Home newsletter signup',
		"Mailing list signup\n\nEmail: [your-email]\n\n(Message: please add to digi-family list.)"
	);

	$ids['village_waitlist'] = vila_akoya_upsert_cf7(
		'Vila Akoya — Village waitlist',
		"[email* your-email placeholder \"Your email address\"]\n[submit \"Join the Waitlist\"]",
		'Vila Akoya — Village mailing list signup',
		"Village waitlist\n\nEmail: [your-email]"
	);

	$ids['campus_host'] = vila_akoya_upsert_cf7(
		'Vila Akoya — Campus host inquiry',
		"[text* your-name]\n[email* your-email]\n[textarea* your-message]\n[checkbox updates \"Guest facilitator updates|yes\"]\n[submit \"Send Inquiry\"]",
		'Vila Akoya — Campus: host your own',
		"Campus host inquiry\n\nName: [your-name]\nEmail: [your-email]\n\nMessage:\n[your-message]\n\nUpdates: [updates]"
	);

	$ids['booking'] = vila_akoya_upsert_cf7(
		'Vila Akoya — Booking inquiry',
		"[text* your-name]\n[email* your-email]\n[tel your-phone]\n[radio primary-interest use_label_element default:1 \"Village\" \"Campsite\" \"Both\"]\n[textarea* your-message]\n[submit \"Send Inquiry\"]",
		'Vila Akoya — booking inquiry',
		"Booking inquiry\n\nName: [your-name]\nEmail: [your-email]\nPhone: [your-phone]\nInterest: [primary-interest]\n\nMessage:\n[your-message]"
	);

	$ids['contact'] = vila_akoya_upsert_cf7(
		'Vila Akoya — Contact',
		"[text* your-name]\n[email* your-email]\n[tel your-phone]\n[textarea* your-message]\n[submit \"Send message\"]",
		'Vila Akoya — contact form',
		"Contact form\n\nName: [your-name]\nEmail: [your-email]\nPhone: [your-phone]\n\nMessage:\n[your-message]"
	);

	$ids = array_filter( $ids );
	update_option( 'vila_akoya_cf7_ids', $ids );
	return $ids;
}

/**
 * Insert or update a CF7 form by title.
 *
 * @param string $title   Post title.
 * @param string $form    Form body.
 * @param string $subject Email subject.
 * @param string $body    Email body.
 * @return int Post ID.
 */
function vila_akoya_upsert_cf7( $title, $form, $subject, $body ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$post_id = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s AND post_status IN ('publish','draft','private') LIMIT 1",
			'wpcf7_contact_form',
			$title
		)
	);

	if ( ! $post_id ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'wpcf7_contact_form',
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}
	}

	$mail = array(
		'active'               => true,
		'subject'              => $subject,
		'sender'               => sprintf( '%s <%s>', get_bloginfo( 'name' ), get_option( 'admin_email' ) ),
		'body'                 => $body,
		'recipient'            => get_option( 'admin_email' ),
		'additional_headers'   => 'Reply-To: [your-email]',
		'attachments'          => '',
		'use_html'             => false,
		'exclude_blank'        => false,
	);

	update_post_meta( $post_id, '_form', $form );
	update_post_meta( $post_id, '_mail', $mail );
	update_post_meta( $post_id, '_mail_2', array( 'active' => false ) );
	update_post_meta( $post_id, '_messages', vila_akoya_cf7_default_messages() );
	update_post_meta( $post_id, '_additional_settings', '' );
	update_post_meta( $post_id, '_locale', determine_locale() );

	return (int) $post_id;
}

/**
 * Default CF7 messages.
 *
 * @return array<string, string>
 */
function vila_akoya_cf7_default_messages() {
	return array(
		'mail_sent_ok'           => __( 'Thank you for your message. It has been sent.', 'vila-akoya' ),
		'mail_sent_ng'           => __( 'There was an error trying to send your message. Please try again later.', 'vila-akoya' ),
		'validation_error'       => __( 'One or more fields have an error. Please check and try again.', 'vila-akoya' ),
		'spam'                   => __( 'There was an error trying to send your message. Please try again later.', 'vila-akoya' ),
		'accept_terms'           => __( 'You must accept the terms and conditions before sending your message.', 'vila-akoya' ),
		'invalid_required'       => __( 'Please fill out this field.', 'vila-akoya' ),
		'invalid_too_long'       => __( 'This field has a too long input.', 'vila-akoya' ),
		'invalid_too_short'      => __( 'This field has a too short input.', 'vila-akoya' ),
		'upload_failed'          => __( 'Uploading a file failed for an unknown reason.', 'vila-akoya' ),
		'upload_file_type_invalid' => __( 'You are not allowed to upload files of this type.', 'vila-akoya' ),
		'upload_file_too_large'  => __( 'The uploaded file is too large.', 'vila-akoya' ),
		'upload_failed_php_error' => __( 'Uploading a file failed due to a PHP error.', 'vila-akoya' ),
	);
}
