<?php
/**
 * Branded HTML email templates for Contact Form 7 (admin + autoreply).
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Display name for email headers (always caps, matches site branding).
 *
 * @return string
 */
function vila_akoya_cf7_brand_name() {
	return 'VILA AKOYA';
}

/**
 * Theme-aligned email colours (Palette A — warm editorial, matches vila-palette.css).
 *
 * @return array<string, string>
 */
function vila_akoya_cf7_email_colors() {
	return array(
		'page_bg'        => '#FFF6E1',
		'card_bg'        => '#FFFFFF',
		'inner_bg'       => '#FBF0D5',
		'text'           => '#332E1E',
		'text_muted'     => '#625B48',
		'primary'        => '#9A3F21',
		'primary_bright' => '#FB8965',
		'tertiary'       => '#7A532A',
		'on_primary'     => '#FFEFEB',
		'border'         => '#E8DCC4',
		'border_soft'    => '#F0E6D0',
	);
}

/**
 * Google Fonts markup for email clients that support linked stylesheets.
 *
 * @return string
 */
function vila_akoya_cf7_email_fonts_markup() {
	return '<link href="https://fonts.googleapis.com/css2?family=Barrio&amp;family=Marcellus&amp;family=Josefin+Sans:ital,wght@0,300;0,400;0,600;1,400&amp;display=swap" rel="stylesheet" />';
}

/**
 * Wrap inner HTML in a table-based, client-safe layout matching the site.
 *
 * @param string $inner_html Main body HTML (may include CF7 mail-tags).
 * @param array  $args       Optional keys: preheader (string).
 * @return string
 */
function vila_akoya_cf7_email_wrap( $inner_html, $args = array() ) {
	$c         = vila_akoya_cf7_email_colors();
	$brand     = vila_akoya_cf7_brand_name();
	$site_url  = home_url( '/' );
	$preheader = isset( $args['preheader'] ) ? (string) $args['preheader'] : '';

	$preheader_html = '' !== $preheader
		? '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">'
			. esc_html( $preheader )
			. '</div>'
		: '';

	$site_label = preg_replace( '#^https?://#', '', untrailingslashit( $site_url ) );

	return '<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="color-scheme" content="light only" />
<meta name="supported-color-schemes" content="light" />
<title>' . esc_html( $brand ) . '</title>
' . vila_akoya_cf7_email_fonts_markup() . '
<style type="text/css">
body,table,td{color:#332E1E !important;}
a{color:#9A3F21 !important;}
@media (prefers-color-scheme:dark){
body,table,td,.va-email-page,.va-email-card,.va-email-inner{background-color:#FFF6E1 !important;color:#332E1E !important;}
}
</style>
</head>
<body class="va-email-page" style="margin:0;padding:0;background-color:' . esc_attr( $c['page_bg'] ) . ' !important;font-family:\'Josefin Sans\',\'Segoe UI\',Arial,sans-serif;font-weight:300;color:' . esc_attr( $c['text'] ) . ' !important;">
' . $preheader_html . '
<table role="presentation" class="va-email-page" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="' . esc_attr( $c['page_bg'] ) . '" style="background-color:' . esc_attr( $c['page_bg'] ) . ' !important;">
<tr><td align="center" style="padding:40px 20px;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:580px;">
<tr><td align="center" style="padding:0 0 28px;">
<p style="margin:0 0 14px;font-family:Barrio,\'Arial Black\',Impact,sans-serif;font-size:36px;line-height:1.1;color:' . esc_attr( $c['primary'] ) . ' !important;letter-spacing:0.14em;text-transform:uppercase;">' . esc_html( $brand ) . '</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="72" align="center"><tr><td style="height:3px;background-color:' . esc_attr( $c['primary'] ) . ';border-radius:999px;font-size:0;line-height:0;">&nbsp;</td></tr></table>
</td></tr>
<tr><td class="va-email-card" bgcolor="' . esc_attr( $c['card_bg'] ) . '" style="background-color:' . esc_attr( $c['card_bg'] ) . ' !important;border:1px solid ' . esc_attr( $c['border'] ) . ';border-radius:20px;padding:32px 28px;">
' . $inner_html . '
</td></tr>
<tr><td align="center" style="padding:28px 12px 0;font-family:\'Josefin Sans\',Arial,sans-serif;font-size:13px;line-height:1.7;color:' . esc_attr( $c['text_muted'] ) . ' !important;">
<p style="margin:0 0 10px;"><a href="' . esc_url( $site_url ) . '" style="color:' . esc_attr( $c['primary'] ) . ' !important;text-decoration:none;font-weight:600;letter-spacing:0.04em;">' . esc_html( $site_label ) . '</a></p>
<p style="margin:0;"><a href="mailto:' . esc_attr( VILA_AKOYA_CF7_MAIL_FROM ) . '" style="color:' . esc_attr( $c['text_muted'] ) . ' !important;text-decoration:underline;">' . esc_html( VILA_AKOYA_CF7_MAIL_FROM ) . '</a></p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>';
}

/**
 * Render a labelled summary table (CF7 tags in values are left unescaped).
 *
 * @param array<int, array{0: string, 1: string}> $rows Label and mail-tag pairs.
 * @return string
 */
function vila_akoya_cf7_email_fields_table( $rows ) {
	if ( empty( $rows ) ) {
		return '';
	}

	$c = vila_akoya_cf7_email_colors();

	$html = '<table role="presentation" class="va-email-inner" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="' . esc_attr( $c['inner_bg'] ) . '" style="background-color:' . esc_attr( $c['inner_bg'] ) . ' !important;border-radius:14px;border:1px solid ' . esc_attr( $c['border_soft'] ) . ';">';

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) || count( $row ) < 2 ) {
			continue;
		}
		$label = (string) $row[0];
		$value = (string) $row[1];
		$html .= '<tr>
<td style="padding:14px 18px 6px;font-family:\'Josefin Sans\',Arial,sans-serif;font-size:10px;font-weight:600;letter-spacing:0.16em;text-transform:uppercase;color:' . esc_attr( $c['tertiary'] ) . ' !important;vertical-align:top;width:30%;">' . esc_html( $label ) . '</td>
<td style="padding:14px 18px 6px;font-family:\'Josefin Sans\',Arial,sans-serif;font-size:15px;font-weight:400;line-height:1.55;color:' . esc_attr( $c['text'] ) . ' !important;vertical-align:top;">' . $value . '</td>
</tr>';
	}

	$html .= '</table>';

	return $html;
}

/**
 * Build inner content block for an email.
 *
 * @param array $args Keys: title, intro, greeting, rows, outro, signoff (all optional strings).
 * @return string
 */
function vila_akoya_cf7_email_content_block( $args ) {
	$c = vila_akoya_cf7_email_colors();

	$html = '';

	if ( ! empty( $args['title'] ) ) {
		$html .= '<h1 style="margin:0 0 18px;font-family:Marcellus,Georgia,\'Times New Roman\',serif;font-size:30px;font-weight:400;line-height:1.3;color:' . esc_attr( $c['text'] ) . ' !important;">'
			. esc_html( (string) $args['title'] )
			. '</h1>';
	}

	if ( ! empty( $args['greeting'] ) ) {
		$html .= '<p style="margin:0 0 12px;font-family:\'Josefin Sans\',Arial,sans-serif;font-size:17px;font-weight:400;line-height:1.6;color:' . esc_attr( $c['text'] ) . ' !important;">' . (string) $args['greeting'] . '</p>';
	}

	if ( ! empty( $args['intro'] ) ) {
		$html .= '<p style="margin:0 0 22px;font-family:\'Josefin Sans\',Arial,sans-serif;font-size:16px;font-weight:300;line-height:1.7;color:' . esc_attr( $c['text_muted'] ) . ' !important;">' . esc_html( (string) $args['intro'] ) . '</p>';
	}

	if ( ! empty( $args['rows'] ) && is_array( $args['rows'] ) ) {
		$html .= vila_akoya_cf7_email_fields_table( $args['rows'] );
	}

	if ( ! empty( $args['outro'] ) ) {
		$html .= '<p style="margin:22px 0 0;font-family:\'Josefin Sans\',Arial,sans-serif;font-size:16px;font-weight:300;line-height:1.7;color:' . esc_attr( $c['text_muted'] ) . ' !important;">' . esc_html( (string) $args['outro'] ) . '</p>';
	}

	if ( ! empty( $args['signoff'] ) ) {
		$html .= '<p style="margin:24px 0 0;font-family:\'Josefin Sans\',Arial,sans-serif;font-size:16px;font-weight:300;line-height:1.7;color:' . esc_attr( $c['text'] ) . ' !important;">' . (string) $args['signoff'] . '</p>';
	}

	return $html;
}

/**
 * Mail (1) — notification to site owner.
 *
 * @param array $config subject, title, intro, rows.
 * @return array
 */
function vila_akoya_cf7_admin_mail( $config ) {
	$inner = vila_akoya_cf7_email_content_block(
		array(
			'title' => isset( $config['title'] ) ? $config['title'] : '',
			'intro' => isset( $config['intro'] ) ? $config['intro'] : '',
			'rows'  => isset( $config['rows'] ) ? $config['rows'] : array(),
		)
	);

	return array(
		'active'             => true,
		'subject'            => (string) $config['subject'],
		'sender'             => sprintf( '%s <%s>', vila_akoya_cf7_brand_name(), VILA_AKOYA_CF7_MAIL_FROM ),
		'body'               => vila_akoya_cf7_email_wrap( $inner, array( 'preheader' => (string) $config['subject'] ) ),
		'recipient'          => VILA_AKOYA_CF7_MAIL_TO,
		'additional_headers' => 'Reply-To: [your-email]',
		'attachments'        => '',
		'use_html'           => true,
		'exclude_blank'      => true,
	);
}

/**
 * Mail (2) — autoreply to submitter.
 *
 * @param array $config subject, title, greeting, intro, rows, outro, signoff.
 * @return array
 */
function vila_akoya_cf7_autoreply_mail( $config ) {
	$inner = vila_akoya_cf7_email_content_block(
		array(
			'title'    => isset( $config['title'] ) ? $config['title'] : '',
			'greeting' => isset( $config['greeting'] ) ? $config['greeting'] : '',
			'intro'    => isset( $config['intro'] ) ? $config['intro'] : '',
			'rows'     => isset( $config['rows'] ) ? $config['rows'] : array(),
			'outro'    => isset( $config['outro'] ) ? $config['outro'] : '',
			'signoff'  => isset( $config['signoff'] ) ? $config['signoff'] : vila_akoya_cf7_email_default_signoff(),
		)
	);

	return array(
		'active'             => true,
		'subject'            => (string) $config['subject'],
		'sender'             => sprintf( '%s <%s>', vila_akoya_cf7_brand_name(), VILA_AKOYA_CF7_MAIL_FROM ),
		'body'               => vila_akoya_cf7_email_wrap( $inner, array( 'preheader' => (string) $config['subject'] ) ),
		'recipient'          => '[your-email]',
		'additional_headers' => 'Reply-To: ' . VILA_AKOYA_CF7_MAIL_FROM,
		'attachments'        => '',
		'use_html'           => true,
		'exclude_blank'      => true,
	);
}

/**
 * Default sign-off HTML for autoreplies.
 *
 * @return string
 */
function vila_akoya_cf7_email_default_signoff() {
	$c = vila_akoya_cf7_email_colors();

	return esc_html__( 'Warmly,', 'vila-akoya' )
		. '<br /><span style="font-family:Marcellus,Georgia,serif;font-size:18px;color:'
		. esc_attr( $c['primary'] )
		. ' !important;">'
		. esc_html( vila_akoya_cf7_brand_name() )
		. '</span>';
}

/**
 * Editable autoreply copy field keys (plain text for non-technical editors).
 *
 * @return string[]
 */
function vila_akoya_cf7_mail_copy_fields() {
	return array( 'subject', 'title', 'greeting', 'intro', 'outro' );
}

/**
 * Human labels for seeded CF7 forms (admin UI).
 *
 * @return array<string, string>
 */
function vila_akoya_cf7_form_copy_labels() {
	return array(
		'newsletter_home'    => __( 'Newsletter — Home', 'vila-akoya' ),
		'newsletter_village' => __( 'Village waitlist', 'vila-akoya' ),
		'village_inquiry'    => __( 'Village inquiry', 'vila-akoya' ),
		'campus'             => __( 'Campus — host your own', 'vila-akoya' ),
		'contact'            => __( 'Contact', 'vila-akoya' ),
	);
}

/**
 * Saved mail copy overrides from the admin settings screen.
 *
 * @return array<string, array{autoreply?: array<string, string>}>
 */
function vila_akoya_cf7_get_mail_copy_overrides() {
	$stored = get_option( 'vila_akoya_cf7_mail_copy', array() );
	return is_array( $stored ) ? $stored : array();
}

/**
 * Merge saved autoreply copy overrides into a mail config set.
 *
 * @param array<string, array{admin: array, autoreply: array}> $configs Default configs.
 * @return array<string, array{admin: array, autoreply: array}>
 */
function vila_akoya_cf7_merge_mail_copy_overrides( array $configs ) {
	$overrides = vila_akoya_cf7_get_mail_copy_overrides();
	$fields    = vila_akoya_cf7_mail_copy_fields();

	foreach ( $configs as $key => $config ) {
		if ( empty( $overrides[ $key ]['autoreply'] ) || ! is_array( $overrides[ $key ]['autoreply'] ) ) {
			continue;
		}

		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $overrides[ $key ]['autoreply'] ) ) {
				continue;
			}
			$value = $overrides[ $key ]['autoreply'][ $field ];
			if ( ! is_string( $value ) ) {
				continue;
			}
			$value = trim( $value );
			if ( '' === $value ) {
				continue;
			}
			$configs[ $key ]['autoreply'][ $field ] = $value;
		}
	}

	return $configs;
}

/**
 * Default per-form admin + autoreply mail configuration (no overrides).
 *
 * @return array<string, array{admin: array, autoreply: array}>
 */
function vila_akoya_cf7_form_mail_configs_defaults() {
	$name_rows = array(
		array( __( 'Name', 'vila-akoya' ), '[your-name]' ),
		array( __( 'Email', 'vila-akoya' ), '[your-email]' ),
		array( __( 'Phone', 'vila-akoya' ), '[your-phone]' ),
		array( __( 'Message', 'vila-akoya' ), '[your-message]' ),
	);

	return array(
		'newsletter_home'    => array(
			'admin'     => array(
				'subject' => __( 'Vila Akoya — Home newsletter signup', 'vila-akoya' ),
				'title'   => __( 'Home newsletter signup', 'vila-akoya' ),
				'intro'   => __( 'Someone subscribed to updates from the home page.', 'vila-akoya' ),
				'rows'    => array(
					array( __( 'Email', 'vila-akoya' ), '[your-email]' ),
				),
			),
			'autoreply' => array(
				'subject'  => __( 'You\'re on the list — Vila Akoya', 'vila-akoya' ),
				'title'    => __( 'Thank you for subscribing', 'vila-akoya' ),
				'greeting' => __( 'Hello,', 'vila-akoya' ),
				'intro'    => __( 'Thanks for signing up for news and updates from Vila Akoya.', 'vila-akoya' ),
				'rows'     => array(
					array( __( 'Email', 'vila-akoya' ), '[your-email]' ),
				),
				'outro'    => __( 'We\'ll be in touch when there is something worth sharing.', 'vila-akoya' ),
			),
		),
		'newsletter_village' => array(
			'admin'     => array(
				'subject' => __( 'Vila Akoya — Village mailing list signup', 'vila-akoya' ),
				'title'   => __( 'Village waitlist signup', 'vila-akoya' ),
				'intro'   => __( 'Someone joined the village waitlist.', 'vila-akoya' ),
				'rows'    => array(
					array( __( 'Email', 'vila-akoya' ), '[your-email]' ),
				),
			),
			'autoreply' => array(
				'subject'  => __( 'You\'re on the village waitlist — Vila Akoya', 'vila-akoya' ),
				'title'    => __( 'You\'re on the waitlist', 'vila-akoya' ),
				'greeting' => __( 'Hello,', 'vila-akoya' ),
				'intro'    => __( 'Thank you for your interest in the Vila Akoya village. We have added you to the waitlist.', 'vila-akoya' ),
				'rows'     => array(
					array( __( 'Email', 'vila-akoya' ), '[your-email]' ),
				),
				'outro'    => __( 'We will reach out when there is news to share about the village.', 'vila-akoya' ),
			),
		),
		'village_inquiry'    => array(
			'admin'     => array(
				'subject' => __( 'Vila Akoya — village inquiry', 'vila-akoya' ),
				'title'   => __( 'New village inquiry', 'vila-akoya' ),
				'intro'   => __( 'Someone submitted an inquiry about joining the village.', 'vila-akoya' ),
				'rows'    => $name_rows,
			),
			'autoreply' => array(
				'subject'  => __( 'Thanks for your village inquiry — Vila Akoya', 'vila-akoya' ),
				'title'    => __( 'We received your message', 'vila-akoya' ),
				'greeting' => __( 'Hi', 'vila-akoya' ) . ' [your-name],',
				'intro'    => __( 'Thank you for sharing a little about yourself and your interest in Vila Akoya. Here is a copy of what you sent:', 'vila-akoya' ),
				'rows'     => $name_rows,
				'outro'    => __( 'We read every inquiry with care and will be in touch when we can.', 'vila-akoya' ),
			),
		),
		'campus'             => array(
			'admin'     => array(
				'subject' => __( 'Vila Akoya — Campus: host your own', 'vila-akoya' ),
				'title'   => __( 'New campus host inquiry', 'vila-akoya' ),
				'intro'   => __( 'Someone submitted a workshop or retreat hosting inquiry.', 'vila-akoya' ),
				'rows'    => array(
					array( __( 'Name', 'vila-akoya' ), '[your-name]' ),
					array( __( 'Email', 'vila-akoya' ), '[your-email]' ),
					array( __( 'Message', 'vila-akoya' ), '[your-message]' ),
					array( __( 'Guest facilitator updates', 'vila-akoya' ), '[guest-updates]' ),
				),
			),
			'autoreply' => array(
				'subject'  => __( 'Thanks for your campus inquiry — Vila Akoya', 'vila-akoya' ),
				'title'    => __( 'We received your inquiry', 'vila-akoya' ),
				'greeting' => __( 'Hi', 'vila-akoya' ) . ' [your-name],',
				'intro'    => __( 'Thank you for reaching out about hosting at the Vila Akoya campus. Here is a copy of what you sent:', 'vila-akoya' ),
				'rows'     => array(
					array( __( 'Name', 'vila-akoya' ), '[your-name]' ),
					array( __( 'Email', 'vila-akoya' ), '[your-email]' ),
					array( __( 'Workshop / retreat', 'vila-akoya' ), '[your-message]' ),
					array( __( 'Guest facilitator updates', 'vila-akoya' ), '[guest-updates]' ),
				),
				'outro'    => __( 'We will review your idea and get back to you soon.', 'vila-akoya' ),
			),
		),
		'contact'            => array(
			'admin'     => array(
				'subject' => __( 'Vila Akoya — contact form', 'vila-akoya' ),
				'title'   => __( 'New contact message', 'vila-akoya' ),
				'intro'   => __( 'Someone sent a message through the contact form.', 'vila-akoya' ),
				'rows'    => $name_rows,
			),
			'autoreply' => array(
				'subject'  => __( 'Thanks for getting in touch — Vila Akoya', 'vila-akoya' ),
				'title'    => __( 'We received your message', 'vila-akoya' ),
				'greeting' => __( 'Hi', 'vila-akoya' ) . ' [your-name],',
				'intro'    => __( 'Thank you for reaching out to Vila Akoya. Here is a copy of what you sent:', 'vila-akoya' ),
				'rows'     => $name_rows,
				'outro'    => __( 'We will read your message and reply as soon as we can.', 'vila-akoya' ),
			),
		),
	);
}

/**
 * Per-form admin + autoreply mail configuration (defaults + editor overrides).
 *
 * @return array<string, array{admin: array, autoreply: array}>
 */
function vila_akoya_cf7_form_mail_configs() {
	return vila_akoya_cf7_merge_mail_copy_overrides( vila_akoya_cf7_form_mail_configs_defaults() );
}

/**
 * Apply branded mail + autoreply templates to existing CF7 forms.
 *
 * @param array<string, string> $form_ids Form IDs keyed by slug.
 * @return void
 */
function vila_akoya_cf7_apply_mail_templates( array $form_ids ) {
	$configs = vila_akoya_cf7_form_mail_configs();

	foreach ( $form_ids as $key => $form_id ) {
		if ( ! isset( $configs[ $key ] ) || '' === (string) $form_id ) {
			continue;
		}

		$form = vila_akoya_cf7_get_form( $form_id );
		if ( ! $form instanceof WPCF7_ContactForm ) {
			continue;
		}

		$properties           = $form->get_properties();
		$properties['mail']   = vila_akoya_cf7_admin_mail( $configs[ $key ]['admin'] );
		$properties['mail_2'] = vila_akoya_cf7_autoreply_mail( $configs[ $key ]['autoreply'] );
		$form->set_properties( $properties );
		$form->save();
	}
}

/**
 * Mail properties for a seeded form (admin + autoreply).
 *
 * @param string $key Form slug.
 * @return array{mail: array, mail_2: array}|array{}
 */
function vila_akoya_cf7_mail_properties_for_key( $key ) {
	$configs = vila_akoya_cf7_form_mail_configs();
	if ( ! isset( $configs[ $key ] ) ) {
		return array();
	}

	return array(
		'mail'   => vila_akoya_cf7_admin_mail( $configs[ $key ]['admin'] ),
		'mail_2' => vila_akoya_cf7_autoreply_mail( $configs[ $key ]['autoreply'] ),
	);
}
