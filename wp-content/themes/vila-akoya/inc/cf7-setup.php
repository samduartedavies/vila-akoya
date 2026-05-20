<?php
/**
 * Register default Contact Form 7 forms (requires CF7 active).
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bump when seeded CF7 form markup changes.
 */
define( 'VILA_AKOYA_CF7_SCHEMA_VERSION', '7' );
define( 'VILA_AKOYA_CF7_MAIL_FROM', 'info@vila-akoya.com' );
define( 'VILA_AKOYA_CF7_MAIL_TO', 'samduarte-davies@hotmail.com' );

/**
 * Keys used for seeded CF7 forms.
 *
 * @return string[]
 */
function vila_akoya_cf7_required_form_keys() {
	return array(
		'newsletter_home',
		'newsletter_village',
		'booking',
		'village_inquiry',
		'campus',
		'contact',
	);
}

/**
 * Whether cached CF7 IDs are complete and still point to real forms.
 *
 * @param mixed $cached Cached option payload.
 * @return bool
 */
function vila_akoya_cf7_cached_ids_are_valid( $cached ) {
	if ( ! is_array( $cached ) ) {
		return false;
	}

	foreach ( vila_akoya_cf7_required_form_keys() as $key ) {
		if ( empty( $cached[ $key ] ) ) {
			return false;
		}

		$form_id = isset( $cached[ $key ] ) ? (string) $cached[ $key ] : '';
		if ( '' === $form_id || ! WPCF7_ContactForm::find( $form_id ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Ensure all CF7 forms use consistent mail sender/recipient.
 *
 * @param array<string, string> $seeded_form_ids Seeded CF7 form IDs keyed by slug.
 * @return void
 */
function vila_akoya_cf7_sync_mail_settings( array $seeded_form_ids ) {
	$all_form_ids = get_posts(
		array(
			'post_type'      => 'wpcf7_contact_form',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		)
	);

	$target_ids = array_values(
		array_unique(
			array_filter(
				array_map(
					'strval',
					array_merge( array_values( $seeded_form_ids ), is_array( $all_form_ids ) ? $all_form_ids : array() )
				)
			)
		)
	);

	foreach ( $target_ids as $form_id ) {
		if ( '' === $form_id ) {
			continue;
		}

		$form = WPCF7_ContactForm::find( $form_id );
		if ( ! $form instanceof WPCF7_ContactForm ) {
			continue;
		}

		$properties = $form->get_properties();
		$mail       = isset( $properties['mail'] ) && is_array( $properties['mail'] )
			? $properties['mail']
			: array();

		$mail['recipient'] = VILA_AKOYA_CF7_MAIL_TO;
		$mail['sender']    = sprintf( '%s <%s>', get_bloginfo( 'name', 'display' ), VILA_AKOYA_CF7_MAIL_FROM );

		$properties['mail'] = $mail;
		$form->set_properties( $properties );
		$form->save();
	}
}

/**
 * Create or retrieve CF7 form IDs keyed by slug.
 *
 * @return array<string, string>
 */
function vila_akoya_get_cf7_form_ids() {
	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		return array();
	}

	$cached = get_option( 'vila_akoya_cf7_form_ids', array() );
	$schema = (string) get_option( 'vila_akoya_cf7_schema_ver', '' );
	if ( VILA_AKOYA_CF7_SCHEMA_VERSION === $schema && vila_akoya_cf7_cached_ids_are_valid( $cached ) ) {
		vila_akoya_cf7_sync_mail_settings( $cached );
		return $cached;
	}

	$ids = array();

	$newsletter_home = WPCF7_ContactForm::get_template(
		array(
			'title' => __( 'Newsletter — Home', 'vila-akoya' ),
		)
	);
	$newsletter_home->set_properties(
		array(
			'form' => implode(
				"\n",
				array(
					'<div class="flex flex-col md:flex-row gap-4 items-stretch md:items-center">',
					'<label class="sr-only" for="home-newsletter-email">' . esc_html__( 'Email for updates', 'vila-akoya' ) . '</label>',
					'[email* your-email id:home-newsletter-email class:newsletter-email placeholder "your@email.com"]',
					'[submit class:newsletter-submit "' . esc_html__( 'Subscribe', 'vila-akoya' ) . '"]',
					'</div>',
				)
			),
			'mail' => vila_akoya_cf7_default_mail(
				__( 'Vila Akoya — Home newsletter signup', 'vila-akoya' ),
				"New newsletter signup (Home).\n\nEmail: [your-email]"
			),
		)
	);
	$newsletter_home->save();
	$ids['newsletter_home'] = (string) $newsletter_home->id();

	$newsletter_village = WPCF7_ContactForm::get_template(
		array(
			'title' => __( 'Village waitlist', 'vila-akoya' ),
		)
	);
	$newsletter_village->set_properties(
		array(
			'form' => implode(
				"\n",
				array(
					'<div class="flex flex-col md:flex-row gap-4 justify-center items-stretch md:items-center max-w-2xl mx-auto">',
					'<label class="sr-only" for="village-waitlist-email">' . esc_html__( 'Email address', 'vila-akoya' ) . '</label>',
					'[email* your-email id:village-waitlist-email class:newsletter-email placeholder "' . esc_attr__( 'Your email address', 'vila-akoya' ) . '"]',
					'[submit class:newsletter-submit "' . esc_html__( 'Join the Waitlist', 'vila-akoya' ) . '"]',
					'</div>',
				)
			),
			'mail' => vila_akoya_cf7_default_mail(
				__( 'Vila Akoya — Village mailing list signup', 'vila-akoya' ),
				"Village waitlist signup.\n\nEmail: [your-email]"
			),
		)
	);
	$newsletter_village->save();
	$ids['newsletter_village'] = (string) $newsletter_village->id();

	$booking = WPCF7_ContactForm::get_template(
		array(
			'title' => __( 'Booking inquiry', 'vila-akoya' ),
		)
	);
	$booking->set_properties(
		array(
			'form' => implode(
				"\n",
				array(
					'<div class="grid grid-cols-1 md:grid-cols-2 gap-6">',
					'<div><label for="booking-name">' . esc_html__( 'Your Name', 'vila-akoya' ) . '</label>[text* your-name id:booking-name placeholder "Elias Duarte"]</div>',
					'<div><label for="booking-email">' . esc_html__( 'Email Address', 'vila-akoya' ) . '</label>[email* your-email id:booking-email placeholder "elias@example.com"]</div>',
					'</div>',
					'<div><label for="booking-phone">' . esc_html__( 'Phone Number', 'vila-akoya' ) . '</label>[tel your-phone id:booking-phone placeholder "+351 000 000 000"]</div>',
					'<div><label for="booking-message">' . esc_html__( 'Booking details', 'vila-akoya' ) . '</label>[textarea* your-message id:booking-message placeholder "' . esc_attr__( 'Share here your booking dates, with how many people you\'re coming, and anything else that might be relevant!', 'vila-akoya' ) . '"]</div>',
					'[submit "' . esc_html__( 'Send Inquiry →', 'vila-akoya' ) . '"]',
				)
			),
			'mail' => vila_akoya_cf7_default_mail(
				__( 'Vila Akoya — booking inquiry', 'vila-akoya' ),
				"Booking inquiry (campsite).\n\nName: [your-name]\nEmail: [your-email]\nPhone: [your-phone]\n\nMessage:\n[your-message]"
			),
		)
	);
	$booking->save();
	$ids['booking'] = (string) $booking->id();

	$village_inquiry = WPCF7_ContactForm::get_template(
		array(
			'title' => __( 'Village inquiry', 'vila-akoya' ),
		)
	);
	$village_inquiry->set_properties(
		array(
			'form' => implode(
				"\n",
				array(
					'<div class="grid grid-cols-1 md:grid-cols-2 gap-6">',
					'<div><label for="village-inq-name">' . esc_html__( 'Your Name', 'vila-akoya' ) . '</label>[text* your-name id:village-inq-name placeholder "Elias Duarte"]</div>',
					'<div><label for="village-inq-email">' . esc_html__( 'Email Address', 'vila-akoya' ) . '</label>[email* your-email id:village-inq-email placeholder "elias@example.com"]</div>',
					'</div>',
					'<div><label for="village-inq-phone">' . esc_html__( 'Phone Number', 'vila-akoya' ) . '</label>[tel* your-phone id:village-inq-phone placeholder "+351 000 000 000"]</div>',
					'<div><label for="village-inq-message">' . esc_html__( 'Tell us a little about yourself', 'vila-akoya' ) . '</label>[textarea* your-message id:village-inq-message placeholder "' . esc_attr__( 'Share a little about who you are, what draws you to this way of living, and why Vila Akoya feels like the right fit for you.', 'vila-akoya' ) . '"]</div>',
					'[submit "' . esc_html__( 'Send Inquiry →', 'vila-akoya' ) . '"]',
				)
			),
			'mail' => vila_akoya_cf7_default_mail(
				__( 'Vila Akoya — village inquiry', 'vila-akoya' ),
				"Village inquiry.\n\nName: [your-name]\nEmail: [your-email]\nPhone: [your-phone]\n\nMessage:\n[your-message]"
			),
		)
	);
	$village_inquiry->save();
	$ids['village_inquiry'] = (string) $village_inquiry->id();

	$campus = WPCF7_ContactForm::get_template(
		array(
			'title' => __( 'Campus — host your own', 'vila-akoya' ),
		)
	);
	$campus->set_properties(
		array(
			'form' => implode(
				"\n",
				array(
					'<div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">',
					'<div><label for="campus-host-name">' . esc_html__( 'Your Name', 'vila-akoya' ) . '</label>[text* your-name id:campus-host-name placeholder "e.g. Elena Santos"]</div>',
					'<div><label for="campus-host-email">' . esc_html__( 'Email Address', 'vila-akoya' ) . '</label>[email* your-email id:campus-host-email placeholder "hello@flow.com"]</div>',
					'</div>',
					'<div class="mb-8"><label for="campus-host-message">' . esc_html__( 'Tell us about your workshop or retreat', 'vila-akoya' ) . '</label>[textarea* your-message id:campus-host-message placeholder "' . esc_attr__( 'What is the intention? Which materials will you need?', 'vila-akoya' ) . '"]</div>',
					'<div class="mb-10">[checkbox guest-updates use_label_element "' . esc_html__( 'Keep me updated on guest facilitator openings.', 'vila-akoya' ) . '"]</div>',
					'[submit "' . esc_html__( 'Send Inquiry', 'vila-akoya' ) . '"]',
				)
			),
			'mail' => vila_akoya_cf7_default_mail(
				__( 'Vila Akoya — Campus: host your own', 'vila-akoya' ),
				"Campus host inquiry.\n\nName: [your-name]\nEmail: [your-email]\n\nMessage:\n[your-message]\n\nUpdates: [guest-updates]"
			),
		)
	);
	$campus->save();
	$ids['campus'] = (string) $campus->id();

	$contact = WPCF7_ContactForm::get_template(
		array(
			'title' => __( 'Contact', 'vila-akoya' ),
		)
	);
	$contact->set_properties(
		array(
			'form' => implode(
				"\n",
				array(
					'<div class="grid grid-cols-1 md:grid-cols-2 gap-6">',
					'<div><label for="contact-name">' . esc_html__( 'Your name', 'vila-akoya' ) . '</label>[text* your-name id:contact-name placeholder "' . esc_attr__( 'Your name', 'vila-akoya' ) . '"]</div>',
					'<div><label for="contact-email">' . esc_html__( 'Email', 'vila-akoya' ) . '</label>[email* your-email id:contact-email placeholder "' . esc_attr__( 'you@example.com', 'vila-akoya' ) . '"]</div>',
					'</div>',
					'<div><label for="contact-phone">' . esc_html__( 'Phone (optional)', 'vila-akoya' ) . '</label>[tel your-phone id:contact-phone placeholder "+351 …"]</div>',
					'<div><label for="contact-message">' . esc_html__( 'Message', 'vila-akoya' ) . '</label>[textarea* your-message id:contact-message placeholder "' . esc_attr__( 'How can we help?', 'vila-akoya' ) . '"]</div>',
					'[submit "' . esc_html__( 'Send message', 'vila-akoya' ) . '"]',
				)
			),
			'mail' => vila_akoya_cf7_default_mail(
				__( 'Vila Akoya — contact form', 'vila-akoya' ),
				"Contact form.\n\nName: [your-name]\nEmail: [your-email]\nPhone: [your-phone]\n\nMessage:\n[your-message]"
			),
		)
	);
	$contact->save();
	$ids['contact'] = (string) $contact->id();

	update_option( 'vila_akoya_cf7_form_ids', $ids );
	update_option( 'vila_akoya_cf7_schema_ver', VILA_AKOYA_CF7_SCHEMA_VERSION );
	vila_akoya_cf7_sync_mail_settings( $ids );

	return $ids;
}

/**
 * Default mail template for seeded forms.
 *
 * @param string $subject Email subject.
 * @param string $body    Mail body with [tags].
 * @return array
 */
function vila_akoya_cf7_default_mail( $subject, $body ) {
	return array(
		'active'           => true,
		'subject'          => $subject,
		'sender'           => sprintf( '%s <%s>', get_bloginfo( 'name', 'display' ), VILA_AKOYA_CF7_MAIL_FROM ),
		'body'             => $body,
		'recipient'        => VILA_AKOYA_CF7_MAIL_TO,
		'additional_headers' => 'Reply-To: [your-email]',
		'attachments'      => '',
		'use_html'         => false,
		'exclude_blank'    => true,
	);
}

/**
 * Disable CF7 autop wrapping for newsletter forms.
 *
 * Contact Form 7 injects <p> and <br> around tags by default, which conflicts
 * with the flex layout we use for the newsletter input/button row.
 *
 * @param bool                $autop        Whether autop is enabled.
 * @param WPCF7_ContactForm   $contact_form Current CF7 form instance.
 * @return bool
 */
function vila_akoya_cf7_disable_autop_for_newsletters( $autop, $contact_form ) {
	if ( ! $contact_form instanceof WPCF7_ContactForm ) {
		return $autop;
	}

	$form_ids = vila_akoya_get_cf7_form_ids();
	$targets  = array_filter(
		array(
			isset( $form_ids['newsletter_home'] ) ? (string) $form_ids['newsletter_home'] : '',
			isset( $form_ids['newsletter_village'] ) ? (string) $form_ids['newsletter_village'] : '',
		)
	);

	if ( in_array( (string) $contact_form->id(), $targets, true ) ) {
		return false;
	}

	return $autop;
}
add_filter( 'wpcf7_autop_or_not', 'vila_akoya_cf7_disable_autop_for_newsletters', 10, 2 );

/**
 * Allow admins to manually trigger CF7 mail settings sync.
 *
 * Visit /wp-admin/?vila_cf7_sync=1 while logged in as admin.
 *
 * @return void
 */
function vila_akoya_handle_manual_cf7_sync() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( empty( $_GET['vila_cf7_sync'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$form_ids = vila_akoya_get_cf7_form_ids();
	vila_akoya_cf7_sync_mail_settings( $form_ids );

	$target = remove_query_arg( array( 'vila_cf7_sync' ) );
	$target = add_query_arg( 'vila_cf7_synced', '1', $target );
	wp_safe_redirect( $target );
	exit;
}
add_action( 'admin_init', 'vila_akoya_handle_manual_cf7_sync' );

/**
 * Confirm manual sync result in wp-admin.
 *
 * @return void
 */
function vila_akoya_cf7_sync_admin_notice() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( empty( $_GET['vila_cf7_synced'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	?>
	<div class="notice notice-success is-dismissible">
		<p><?php esc_html_e( 'CF7 mail settings synced (recipient and sender updated).', 'vila-akoya' ); ?></p>
	</div>
	<?php
}
add_action( 'admin_notices', 'vila_akoya_cf7_sync_admin_notice' );

/**
 * Collect CF7 form IDs referenced in published post/page content.
 *
 * @return string[]
 */
function vila_akoya_cf7_get_referenced_form_ids() {
	global $wpdb;

	$referenced = array();
	$rows       = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT post_content
			 FROM {$wpdb->posts}
			 WHERE post_status = %s
			   AND post_type IN ('page', 'post')
			   AND post_content LIKE %s",
			'publish',
			'%[contact-form-7%'
		)
	);

	foreach ( (array) $rows as $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			continue;
		}

		if ( preg_match_all( '/\[contact-form-7[^\]]*id="([^"]+)"/', $content, $matches ) ) {
			foreach ( (array) $matches[1] as $id ) {
				$referenced[] = (string) $id;
			}
		}
	}

	$referenced = array_values(
		array_unique(
			array_filter(
				array_map( 'strval', $referenced )
			)
		)
	);

	return $referenced;
}

/**
 * Delete unused CF7 forms (not seeded and not referenced in published content).
 *
 * @return int Number of deleted forms.
 */
function vila_akoya_cf7_delete_unused_forms() {
	$seeded = vila_akoya_get_cf7_form_ids();
	$keep   = array_values(
		array_unique(
			array_map(
				'strval',
				array_merge( array_values( $seeded ), vila_akoya_cf7_get_referenced_form_ids() )
			)
		)
	);

	$all_ids = get_posts(
		array(
			'post_type'      => 'wpcf7_contact_form',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		)
	);

	$deleted = 0;
	foreach ( (array) $all_ids as $form_id ) {
		$form_id = (string) $form_id;
		if ( '' === $form_id || in_array( $form_id, $keep, true ) ) {
			continue;
		}
		$removed = wp_delete_post( $form_id, true );
		if ( $removed ) {
			++$deleted;
		}
	}

	return $deleted;
}

/**
 * Allow admins to manually clean up unused CF7 forms.
 *
 * Visit /wp-admin/?vila_cf7_cleanup=1 while logged in as admin.
 *
 * @return void
 */
function vila_akoya_handle_manual_cf7_cleanup() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( empty( $_GET['vila_cf7_cleanup'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$deleted = vila_akoya_cf7_delete_unused_forms();

	$target = remove_query_arg( array( 'vila_cf7_cleanup' ) );
	$target = add_query_arg( 'vila_cf7_cleanup_deleted', (string) $deleted, $target );
	wp_safe_redirect( $target );
	exit;
}
add_action( 'admin_init', 'vila_akoya_handle_manual_cf7_cleanup' );

/**
 * Confirm cleanup result in wp-admin.
 *
 * @return void
 */
function vila_akoya_cf7_cleanup_admin_notice() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! isset( $_GET['vila_cf7_cleanup_deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$deleted = (int) $_GET['vila_cf7_cleanup_deleted']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	?>
	<div class="notice notice-success is-dismissible">
		<p>
			<?php
			printf(
				/* translators: %d: Number of deleted forms. */
				esc_html__( 'CF7 cleanup completed. Removed %d unused form(s).', 'vila-akoya' ),
				(int) $deleted
			);
			?>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'vila_akoya_cf7_cleanup_admin_notice' );
