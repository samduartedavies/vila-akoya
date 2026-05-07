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
define( 'VILA_AKOYA_CF7_SCHEMA_VERSION', '5' );

/**
 * Create or retrieve CF7 form IDs keyed by slug.
 *
 * @return array<string, int>
 */
function vila_akoya_get_cf7_form_ids() {
	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		return array();
	}

	$cached = get_option( 'vila_akoya_cf7_form_ids', array() );
	$schema = (string) get_option( 'vila_akoya_cf7_schema_ver', '' );
	if ( is_array( $cached ) && count( $cached ) >= 6 && VILA_AKOYA_CF7_SCHEMA_VERSION === $schema ) {
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
	$ids['newsletter_home'] = (int) $newsletter_home->id();

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
					'[email* your-email id:village-waitlist-email placeholder "' . esc_attr__( 'Your email address', 'vila-akoya' ) . '"]',
					'[submit "' . esc_html__( 'Join the Waitlist', 'vila-akoya' ) . '"]',
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
	$ids['newsletter_village'] = (int) $newsletter_village->id();

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
	$ids['booking'] = (int) $booking->id();

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
					'<div><label for="village-inq-phone">' . esc_html__( 'Phone Number', 'vila-akoya' ) . '</label>[tel your-phone id:village-inq-phone placeholder "+351 000 000 000"]</div>',
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
	$ids['village_inquiry'] = (int) $village_inquiry->id();

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
	$ids['campus'] = (int) $campus->id();

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
	$ids['contact'] = (int) $contact->id();

	update_option( 'vila_akoya_cf7_form_ids', $ids );
	update_option( 'vila_akoya_cf7_schema_ver', VILA_AKOYA_CF7_SCHEMA_VERSION );

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
	$admin = get_option( 'admin_email' );
	return array(
		'active'           => true,
		'subject'          => $subject,
		'sender'           => sprintf( '%s <%s>', get_bloginfo( 'name', 'display' ), $admin ),
		'body'             => $body,
		'recipient'        => $admin,
		'additional_headers' => 'Reply-To: [your-email]',
		'attachments'      => '',
		'use_html'         => false,
		'exclude_blank'    => true,
	);
}
