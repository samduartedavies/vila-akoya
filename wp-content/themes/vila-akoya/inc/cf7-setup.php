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
define( 'VILA_AKOYA_CF7_SCHEMA_VERSION', '16' );
define( 'VILA_AKOYA_CF7_MAIL_TEMPLATE_VER', '5' );
define( 'VILA_AKOYA_CF7_MAIL_FROM', 'info@vila-akoya.com' );
define( 'VILA_AKOYA_CF7_MAIL_TO', 'info@vila-akoya.com' );

require_once VILA_AKOYA_DIR . '/inc/cf7-emails.php';

/**
 * Load one CF7 form by post ID without hijacking WPCF7_ContactForm::$current.
 *
 * CF7's get_instance() always sets the global "current" form, which makes the
 * Contact Forms admin screen show a single edit view instead of the list table.
 *
 * @param int|string $form_id Contact form post ID.
 * @return WPCF7_ContactForm|null
 */
function vila_akoya_cf7_get_form( $form_id ) {
	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		return null;
	}

	$id = (int) $form_id;
	if ( $id <= 0 ) {
		return null;
	}

	$previous_current = WPCF7_ContactForm::get_current();
	$form             = WPCF7_ContactForm::get_instance( $id );

	if ( ! $form instanceof WPCF7_ContactForm ) {
		return null;
	}

	// get_instance() sets CF7's global "current" form; restore so the admin list still works.
	if ( $previous_current instanceof WPCF7_ContactForm ) {
		WPCF7_ContactForm::get_instance( $previous_current->id() );
	} else {
		vila_akoya_cf7_reset_current_contact_form();
	}

	return $form;
}

/**
 * Clear CF7's static current form (fixes admin list showing only one edit screen).
 *
 * @return void
 */
function vila_akoya_cf7_reset_current_contact_form() {
	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		return;
	}

	try {
		$reflection = new ReflectionClass( 'WPCF7_ContactForm' );
		if ( $reflection->hasProperty( 'current' ) ) {
			$property = $reflection->getProperty( 'current' );
			$property->setAccessible( true );
			$property->setValue( null, null );
		}
	} catch ( ReflectionException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		// CF7 internals changed; safe to ignore.
	}
}

/**
 * Ensure the Contact Forms list table is shown (not a stray edit screen).
 *
 * @return void
 */
function vila_akoya_cf7_prepare_contact_forms_admin_list() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'wpcf7' !== $page ) {
		return;
	}

	// Editing a specific form — leave CF7's current contact form alone.
	if ( isset( $_GET['post'] ) && '' !== (string) wp_unslash( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	vila_akoya_cf7_reset_current_contact_form();
}
add_action( 'load-toplevel_page_wpcf7', 'vila_akoya_cf7_prepare_contact_forms_admin_list', 1 );

/**
 * Keys used for seeded CF7 forms.
 *
 * @return string[]
 */
function vila_akoya_cf7_required_form_keys() {
	return array(
		'newsletter_home',
		'newsletter_village',
		'village_inquiry',
		'campus',
		'contact',
	);
}

/**
 * Placeholder copy for the village inquiry message field.
 *
 * @return string
 */
function vila_akoya_cf7_village_inquiry_placeholder() {
	return esc_attr__(
		'Looking for accommodation?

If you’re looking to rent accommodation with us, please let us know here.

Do share a little about yourself, who will be coming with you, your dates and what kind of accommodation you’d need. We will get back to you with options!

Looking for a village?

If you’ve ended up here because you’re interested in joining our project as a villager for a longer period of time, do write us a little bit about yourself and the humans that belong with you, and we will get back to you with options and info!

Love,
Sammy and the family.',
		'vila-akoya'
	);
}

/**
 * Drop retired keys from the stored CF7 ID map (e.g. removed booking form).
 *
 * @return array<string, string>
 */
function vila_akoya_cf7_prune_stored_form_ids() {
	$ids = get_option( 'vila_akoya_cf7_form_ids', array() );
	if ( ! is_array( $ids ) ) {
		return array();
	}

	$pruned = array();
	foreach ( vila_akoya_cf7_required_form_keys() as $key ) {
		if ( ! empty( $ids[ $key ] ) ) {
			$pruned[ $key ] = (string) $ids[ $key ];
		}
	}

	if ( $pruned !== $ids ) {
		update_option( 'vila_akoya_cf7_form_ids', $pruned );
	}

	return $pruned;
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
		if ( '' === $form_id || ! vila_akoya_cf7_get_form( $form_id ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Detect seeded form slug from unique field id in the form markup.
 *
 * @param WPCF7_ContactForm $form CF7 form.
 * @return string|null
 */
function vila_akoya_cf7_detect_form_key( WPCF7_ContactForm $form ) {
	if ( ! $form instanceof WPCF7_ContactForm ) {
		return null;
	}

	$markup = (string) $form->prop( 'form' );
	if ( '' === $markup ) {
		return null;
	}

	$markers = array(
		'home-newsletter-email'  => 'newsletter_home',
		'village-waitlist-email' => 'newsletter_village',
		'village-inq-name'       => 'village_inquiry',
		'campus-host-name'       => 'campus',
		'contact-name'           => 'contact',
	);

	foreach ( $markers as $needle => $key ) {
		if ( false !== strpos( $markup, $needle ) ) {
			return $key;
		}
	}

	return null;
}

/**
 * Map of active seeded forms: prefer stored IDs, fall back to forms used on pages.
 *
 * @return array<string, string>
 */
function vila_akoya_cf7_resolve_active_form_ids() {
	$ids = vila_akoya_cf7_prune_stored_form_ids();

	foreach ( vila_akoya_cf7_get_referenced_form_ids() as $form_id ) {
		$form = vila_akoya_cf7_get_form( $form_id );
		if ( ! $form instanceof WPCF7_ContactForm ) {
			continue;
		}

		$key = vila_akoya_cf7_detect_form_key( $form );
		if ( null !== $key ) {
			$ids[ $key ] = (string) $form->id();
		}
	}

	update_option( 'vila_akoya_cf7_form_ids', $ids );

	return $ids;
}

/**
 * Whether any active form still lacks HTML mail or an enabled autoreply.
 *
 * @return bool
 */
function vila_akoya_cf7_forms_need_mail_template_upgrade() {
	$ids = vila_akoya_cf7_resolve_active_form_ids();

	foreach ( vila_akoya_cf7_required_form_keys() as $key ) {
		if ( empty( $ids[ $key ] ) ) {
			return true;
		}

		$form = vila_akoya_cf7_get_form( $ids[ $key ] );
		if ( ! $form instanceof WPCF7_ContactForm ) {
			return true;
		}

		$props = $form->get_properties();
		$mail  = isset( $props['mail'] ) && is_array( $props['mail'] ) ? $props['mail'] : array();
		$mail2 = isset( $props['mail_2'] ) && is_array( $props['mail_2'] ) ? $props['mail_2'] : array();

		if ( empty( $mail['use_html'] ) || empty( $mail2['active'] ) || empty( $mail2['use_html'] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Push branded Mail + Mail (2) templates onto all active forms.
 *
 * @return int Number of forms updated.
 */
function vila_akoya_cf7_upgrade_mail_templates() {
	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		return 0;
	}

	$form_ids = vila_akoya_cf7_resolve_active_form_ids();
	vila_akoya_cf7_apply_mail_templates( $form_ids );
	vila_akoya_cf7_sync_mail_settings( $form_ids );
	update_option( 'vila_akoya_cf7_mail_tpl_ver', VILA_AKOYA_CF7_MAIL_TEMPLATE_VER );

	return count( $form_ids );
}

/**
 * Push small, versioned form markup changes into existing seeded CF7 forms.
 *
 * @param array<string, string> $seeded_form_ids Seeded CF7 form IDs keyed by slug.
 * @return int Number of forms updated.
 */
function vila_akoya_cf7_upgrade_form_markup( array $seeded_form_ids ) {
	if ( empty( $seeded_form_ids['village_inquiry'] ) ) {
		return 0;
	}

	$form = vila_akoya_cf7_get_form( $seeded_form_ids['village_inquiry'] );
	if ( ! $form instanceof WPCF7_ContactForm ) {
		return 0;
	}

	$properties = $form->get_properties();
	$markup     = isset( $properties['form'] ) ? (string) $properties['form'] : (string) $form->prop( 'form' );
	if ( '' === $markup ) {
		return 0;
	}

	$old_placeholder = esc_attr__(
		'Share a little about who you are, what draws you to this way of living, and why Vila Akoya feels like the right fit for you.',
		'vila-akoya'
	);

	$updated_markup = str_replace(
		'placeholder "' . $old_placeholder . '"',
		'placeholder "' . vila_akoya_cf7_village_inquiry_placeholder() . '"',
		$markup,
		$replacement_count
	);

	$updated_markup = str_replace(
		esc_html__( 'Tell us a little about yourself', 'vila-akoya' ),
		esc_html__( 'Want to join our little village?', 'vila-akoya' ),
		$updated_markup,
		$label_replacement_count
	);

	$updated_markup = str_replace(
		'[textarea* your-message id:village-inq-message placeholder "',
		'[textarea* your-message 40x22 id:village-inq-message placeholder "',
		$updated_markup,
		$textarea_replacement_count
	);

	$updated_markup = str_replace(
		array(
			'[textarea* your-message 40x8 id:village-inq-message placeholder "',
			'[textarea* your-message 40x12 id:village-inq-message placeholder "',
			'[textarea* your-message 40x15 id:village-inq-message placeholder "',
			'[textarea* your-message 40x20 id:village-inq-message placeholder "',
		),
		'[textarea* your-message 40x22 id:village-inq-message placeholder "',
		$updated_markup,
		$textarea_size_replacement_count
	);

	if ( 0 === $replacement_count + $label_replacement_count + $textarea_replacement_count + $textarea_size_replacement_count || $updated_markup === $markup ) {
		return 0;
	}

	$properties['form'] = $updated_markup;
	$form->set_properties( $properties );
	$form->save();

	return 1;
}

/**
 * Ensure all CF7 forms use consistent mail sender/recipient.
 *
 * @param array<string, string> $seeded_form_ids Seeded CF7 form IDs keyed by slug.
 * @return void
 */
function vila_akoya_cf7_sync_mail_settings( array $seeded_form_ids ) {
	$target_ids = array_values(
		array_unique(
			array_filter(
				array_map( 'intval', array_values( $seeded_form_ids ) )
			)
		)
	);

	foreach ( $target_ids as $form_id ) {
		if ( $form_id <= 0 ) {
			continue;
		}

		$form = vila_akoya_cf7_get_form( $form_id );
		if ( ! $form instanceof WPCF7_ContactForm ) {
			continue;
		}

		$properties = $form->get_properties();
		$mail       = isset( $properties['mail'] ) && is_array( $properties['mail'] )
			? $properties['mail']
			: array();

		$mail['recipient'] = VILA_AKOYA_CF7_MAIL_TO;
		$mail['sender']    = sprintf( '%s <%s>', vila_akoya_cf7_brand_name(), VILA_AKOYA_CF7_MAIL_FROM );

		$properties['mail'] = $mail;

		if ( isset( $properties['mail_2'] ) && is_array( $properties['mail_2'] ) && ! empty( $properties['mail_2']['active'] ) ) {
			$properties['mail_2']['sender'] = sprintf(
				'%s <%s>',
				vila_akoya_cf7_brand_name(),
				VILA_AKOYA_CF7_MAIL_FROM
			);
		}

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
	if ( vila_akoya_cf7_cached_ids_are_valid( $cached ) ) {
		$cached = vila_akoya_cf7_resolve_active_form_ids();
		if ( VILA_AKOYA_CF7_SCHEMA_VERSION !== $schema ) {
			vila_akoya_cf7_upgrade_form_markup( $cached );
			vila_akoya_cf7_upgrade_mail_templates();
			$cached = vila_akoya_cf7_prune_stored_form_ids();
			if ( ( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( 'manage_options' ) ) {
				vila_akoya_cf7_delete_unused_forms();
			}
			update_option( 'vila_akoya_cf7_schema_ver', VILA_AKOYA_CF7_SCHEMA_VERSION );
		}
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
		array_merge(
			array(
				'form' => implode(
					"\n",
					array(
						'<div class="flex flex-col md:flex-row gap-4 items-stretch md:items-center">',
						'<label class="sr-only" for="home-newsletter-email">' . esc_html__( 'Email for updates', 'vila-akoya' ) . '</label>',
						'[email* your-email id:home-newsletter-email class:newsletter-email placeholder "your@email.com"]',
						'<input type="hidden" name="mc4wp-subscribe" value="1" />',
						'[submit class:newsletter-submit "' . esc_html__( 'Subscribe', 'vila-akoya' ) . '"]',
						'</div>',
					)
				),
			),
			vila_akoya_cf7_mail_properties_for_key( 'newsletter_home' )
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
		array_merge(
			array(
				'form' => implode(
					"\n",
					array(
						'<div class="flex flex-col md:flex-row gap-4 justify-center items-stretch md:items-center max-w-2xl mx-auto">',
						'<label class="sr-only" for="village-waitlist-email">' . esc_html__( 'Email address', 'vila-akoya' ) . '</label>',
						'[email* your-email id:village-waitlist-email class:newsletter-email placeholder "' . esc_attr__( 'Your email address', 'vila-akoya' ) . '"]',
						'<input type="hidden" name="mc4wp-subscribe" value="1" />',
						'[submit class:newsletter-submit "' . esc_html__( 'Join the Waitlist', 'vila-akoya' ) . '"]',
						'</div>',
					)
				),
			),
			vila_akoya_cf7_mail_properties_for_key( 'newsletter_village' )
		)
	);
	$newsletter_village->save();
	$ids['newsletter_village'] = (string) $newsletter_village->id();

	$village_inquiry = WPCF7_ContactForm::get_template(
		array(
			'title' => __( 'Village inquiry', 'vila-akoya' ),
		)
	);
	$village_inquiry_placeholder = vila_akoya_cf7_village_inquiry_placeholder();
	$village_inquiry->set_properties(
		array_merge(
			array(
				'form' => implode(
					"\n",
					array(
						'<div class="grid grid-cols-1 md:grid-cols-2 gap-6">',
						'<div><label for="village-inq-name">' . esc_html__( 'Your Name', 'vila-akoya' ) . '</label>[text* your-name id:village-inq-name placeholder "Elias Duarte"]</div>',
						'<div><label for="village-inq-email">' . esc_html__( 'Email Address', 'vila-akoya' ) . '</label>[email* your-email id:village-inq-email placeholder "elias@example.com"]</div>',
						'</div>',
						'<div><label for="village-inq-phone">' . esc_html__( 'Phone Number', 'vila-akoya' ) . '</label>[tel* your-phone id:village-inq-phone placeholder "+351 000 000 000"]</div>',
						'<div><label for="village-inq-message">' . esc_html__( 'Want to join our little village?', 'vila-akoya' ) . '</label>[textarea* your-message 40x22 id:village-inq-message placeholder "' . $village_inquiry_placeholder . '"]</div>',
						'[submit "' . esc_html__( 'Send Inquiry →', 'vila-akoya' ) . '"]',
					)
				),
			),
			vila_akoya_cf7_mail_properties_for_key( 'village_inquiry' )
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
		array_merge(
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
			),
			vila_akoya_cf7_mail_properties_for_key( 'campus' )
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
		array_merge(
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
			),
			vila_akoya_cf7_mail_properties_for_key( 'contact' )
		)
	);
	$contact->save();
	$ids['contact'] = (string) $contact->id();

	update_option( 'vila_akoya_cf7_form_ids', $ids );
	update_option( 'vila_akoya_cf7_schema_ver', VILA_AKOYA_CF7_SCHEMA_VERSION );
	vila_akoya_cf7_sync_mail_settings( $ids );
	vila_akoya_cf7_delete_unused_forms();

	return $ids;
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

	$updated = vila_akoya_cf7_upgrade_mail_templates();
	vila_akoya_cf7_reset_current_contact_form();

	$target = remove_query_arg( array( 'vila_cf7_sync' ) );
	$target = add_query_arg(
		array(
			'vila_cf7_synced' => '1',
			'vila_cf7_mail_updated' => (string) $updated,
		),
		$target
	);
	wp_safe_redirect( $target );
	exit;
}
add_action( 'admin_init', 'vila_akoya_handle_manual_cf7_sync', 5 );

/**
 * Apply HTML mail templates when missing (runs on admin load).
 *
 * @return void
 */
function vila_akoya_cf7_maybe_apply_mail_templates() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		return;
	}

	$force = ! empty( $_GET['vila_cf7_apply_mail'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$ver   = (string) get_option( 'vila_akoya_cf7_mail_tpl_ver', '' );

	if ( $force ) {
		$updated = vila_akoya_cf7_upgrade_mail_templates();
		$target  = remove_query_arg( array( 'vila_cf7_apply_mail' ) );
		$target  = add_query_arg(
			array(
				'vila_cf7_mail_applied' => '1',
				'vila_cf7_mail_updated' => (string) $updated,
			),
			$target
		);
		wp_safe_redirect( $target );
		exit;
	}

	if ( VILA_AKOYA_CF7_MAIL_TEMPLATE_VER !== $ver || vila_akoya_cf7_forms_need_mail_template_upgrade() ) {
		vila_akoya_cf7_upgrade_mail_templates();
	}

	vila_akoya_cf7_reset_current_contact_form();
}
add_action( 'admin_init', 'vila_akoya_cf7_maybe_apply_mail_templates', 15 );

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
		<p>
			<?php
			$updated = isset( $_GET['vila_cf7_mail_updated'] ) ? (int) $_GET['vila_cf7_mail_updated'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $updated > 0 ) {
				printf(
					/* translators: %d: number of forms updated. */
					esc_html__( 'CF7 mail updated: HTML templates and autoreplies applied to %d form(s).', 'vila-akoya' ),
					(int) $updated
				);
			} else {
				esc_html_e( 'CF7 mail settings synced.', 'vila-akoya' );
			}
			?>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'vila_akoya_cf7_sync_admin_notice' );

/**
 * Confirm forced mail template apply.
 *
 * @return void
 */
function vila_akoya_cf7_apply_mail_admin_notice() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( empty( $_GET['vila_cf7_mail_applied'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$updated = isset( $_GET['vila_cf7_mail_updated'] ) ? (int) $_GET['vila_cf7_mail_updated'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	?>
	<div class="notice notice-success is-dismissible">
		<p>
			<?php
			printf(
				/* translators: %d: number of forms updated. */
				esc_html__( 'CF7 branded emails applied to %d form(s) (Mail + Mail 2).', 'vila-akoya' ),
				(int) $updated
			);
			?>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'vila_akoya_cf7_apply_mail_admin_notice' );

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
			   AND post_type IN ('page', 'post', 'wp_block')
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
	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		return 0;
	}

	$seeded = vila_akoya_cf7_prune_stored_form_ids();
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
 * Remove unused CF7 forms once after deploy (admin dashboard visit).
 *
 * @return void
 */
function vila_akoya_cf7_maybe_delete_unused_forms() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		return;
	}

	$cleaned_ver = (string) get_option( 'vila_akoya_cf7_unused_cleaned_ver', '' );
	if ( VILA_AKOYA_CF7_SCHEMA_VERSION === $cleaned_ver ) {
		return;
	}

	vila_akoya_cf7_delete_unused_forms();
	update_option( 'vila_akoya_cf7_unused_cleaned_ver', VILA_AKOYA_CF7_SCHEMA_VERSION );
}
add_action( 'admin_init', 'vila_akoya_cf7_maybe_delete_unused_forms', 25 );

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
	update_option( 'vila_akoya_cf7_unused_cleaned_ver', VILA_AKOYA_CF7_SCHEMA_VERSION );

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
