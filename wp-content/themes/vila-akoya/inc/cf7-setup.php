<?php
/**
 * Register default Contact Form 7 forms (requires CF7 active).
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

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
	if ( is_array( $cached ) && count( $cached ) >= 5 ) {
		return $cached;
	}

	$ids = array();

	$newsletter_home = new WPCF7_ContactForm();
	$newsletter_home->set_title( __( 'Newsletter — Home', 'vila-akoya' ) );
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

	$newsletter_village = new WPCF7_ContactForm();
	$newsletter_village->set_title( __( 'Village waitlist', 'vila-akoya' ) );
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

	$booking = new WPCF7_ContactForm();
	$booking->set_title( __( 'Booking inquiry', 'vila-akoya' ) );
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
					'<div><span class="block mb-2">' . esc_html__( 'Primary Interest', 'vila-akoya' ) . '</span><div class="grid grid-cols-1 sm:grid-cols-3 gap-3 vila-cf7-booking-radios" role="radiogroup">[radio primary-interest use_label_element default:1 "Village" "Campsite" "Both"]</div></div>',
					'<div><label for="booking-message">' . esc_html__( 'Tell us your story', 'vila-akoya' ) . '</label>[textarea* your-message id:booking-message placeholder "' . esc_attr__( 'How do you see yourself spending time at Akoya?', 'vila-akoya' ) . '"]</div>',
					'[submit "' . esc_html__( 'Send Inquiry', 'vila-akoya' ) . '"]',
				)
			),
			'mail' => vila_akoya_cf7_default_mail(
				__( 'Vila Akoya — booking inquiry', 'vila-akoya' ),
				"Booking inquiry.\n\nName: [your-name]\nEmail: [your-email]\nPhone: [your-phone]\nInterest: [primary-interest]\n\nMessage:\n[your-message]"
			),
		)
	);
	$booking->save();
	$ids['booking'] = (int) $booking->id();

	$campus = new WPCF7_ContactForm();
	$campus->set_title( __( 'Campus — host your own', 'vila-akoya' ) );
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

	$contact = new WPCF7_ContactForm();
	$contact->set_title( __( 'Contact', 'vila-akoya' ) );
	$contact->set_properties(
		array(
			'form' => implode(
				"\n",
				array(
					'<div class="grid grid-cols-1 md:grid-cols-2 gap-6">',
					'<div><label for="contact-name">' . esc_html__( 'Your name', 'vila-akoya' ) . '</label>[text* your-name id:contact-name]</div>',
					'<div><label for="contact-email">' . esc_html__( 'Email', 'vila-akoya' ) . '</label>[email* your-email id:contact-email]</div>',
					'</div>',
					'<div><label for="contact-phone">' . esc_html__( 'Phone (optional)', 'vila-akoya' ) . '</label>[tel your-phone id:contact-phone]</div>',
					'<div><label for="contact-message">' . esc_html__( 'Message', 'vila-akoya' ) . '</label>[textarea* your-message id:contact-message]</div>',
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
