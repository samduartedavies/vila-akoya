<?php
/**
 * Seed a default Contact Form 7 “Booking inquiry” form (recipient: Info@vila-akoya.com).
 *
 * @package vila-akoya
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create the booking form once CF7 is available.
 */
function vila_akoya_maybe_create_booking_cf7() {
	if ( get_option( 'vila_akoya_cf7_booking_created' ) ) {
		return;
	}

	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		return;
	}

	$existing = WPCF7_ContactForm::find(
		array(
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);

	foreach ( $existing as $form ) {
		if ( 'Booking inquiry' === $form->title() ) {
			update_option( 'vila_akoya_cf7_booking_created', true );
			return;
		}
	}

	$form_html = trim(
		'
<label> Your name
    [text* your-name autocomplete:name] </label>

<label> Your email
    [email* your-email autocomplete:email] </label>

<label> What are you interested in?
    [select your-interest "Plot / village" "Campsite stay" "Workshop" "Other"] </label>

<label> Preferred dates
    [text your-dates] </label>

<label> Party size
    [text your-party] </label>

<label> Message
    [textarea your-message] </label>

[submit "Send inquiry"]'
	);

	$contact_form = WPCF7_ContactForm::get_template(
		array(
			'title'  => 'Booking inquiry',
			'locale' => determine_locale(),
		)
	);

	$props = $contact_form->get_properties();

	$props['form'] = $form_html;

	$mail = isset( $props['mail'] ) && is_array( $props['mail'] ) ? $props['mail'] : array();

	$mail = array_merge(
		$mail,
		array(
			'subject'             => sprintf(
				/* translators: %s: submitter name */
				__( 'Vila Akoya booking inquiry from %s', 'vila-akoya' ),
				'[your-name]'
			),
			'recipient'           => 'Info@vila-akoya.com',
			'sender'              => sprintf(
				'%s <%s>',
				'[_site_title]',
				'wordpress@' . wp_parse_url( home_url(), PHP_URL_HOST )
			),
			'body'                => trim(
				"From: [your-name] <[your-email]>\n\nInterest: [your-interest]\nDates: [your-dates]\nParty size: [your-party]\n\nMessage:\n[your-message]\n\n--\n[_site_title] · [_site_url]"
			),
			'additional_headers'  => 'Reply-To: [your-email]',
			'attachments'         => isset( $mail['attachments'] ) ? $mail['attachments'] : '',
			'use_html'            => isset( $mail['use_html'] ) ? $mail['use_html'] : 0,
			'exclude_blank'       => isset( $mail['exclude_blank'] ) ? $mail['exclude_blank'] : 0,
		)
	);

	$props['mail'] = $mail;

	$contact_form->set_properties( $props );

	$post_id = $contact_form->save();

	if ( $post_id ) {
		update_option( 'vila_akoya_cf7_booking_created', true );
	}
}
add_action( 'init', 'vila_akoya_maybe_create_booking_cf7', 50 );

/**
 * Render booking form markup with a graceful fallback when CF7 is unavailable.
 *
 * @return string
 */
function vila_akoya_render_booking_form_shortcode() {
	if ( class_exists( 'WPCF7_ContactForm' ) ) {
		return do_shortcode( '[contact-form-7 title="Booking inquiry"]' );
	}

	$fallback = '
<!-- wp:group {"className":"va-booking-fallback","layout":{"type":"constrained"}} -->
<div class="wp-block-group va-booking-fallback">
<!-- wp:paragraph {"style":{"typography":{"fontSize":"var:preset|font-size|medium","lineHeight":"1.75"}},"textColor":"on-surface-variant"} -->
<p class="has-on-surface-variant-color has-text-color" style="font-size:var(--wp--preset--font-size--medium);line-height:1.75">Our booking form is loading. In the meantime, email us at <a href="mailto:Info@vila-akoya.com">Info@vila-akoya.com</a> and include your preferred dates, party size, and whether you are interested in The Village or The Campsite.</p>
<!-- /wp:paragraph -->
<!-- /wp:group -->
';

	return do_blocks( trim( $fallback ) );
}
add_shortcode( 'vila_akoya_booking_form', 'vila_akoya_render_booking_form_shortcode' );
