<?php
/**
 * Tag Mailchimp subscribers from Contact Form 7 via MC4WP.
 *
 * Hidden HTML tag fields are ignored by the CF7 integration. Tags are set via
 * filters, and also applied explicitly after subscribe so existing contacts
 * (e.g. already on newsletter) still receive a new tag (e.g. waitlist).
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Map seeded CF7 form keys to Mailchimp tags.
 *
 * @return array<string, string>
 */
function vila_akoya_mc4wp_cf7_form_tags() {
	return array(
		'newsletter_home'    => 'newsletter',
		'newsletter_village' => 'village-waitlist',
	);
}

/**
 * Resolve the Mailchimp tag for a CF7 form ID.
 *
 * @param int|string $cf7_form_id Contact Form 7 post ID.
 * @return string Empty string when the form is not mapped.
 */
function vila_akoya_mc4wp_tag_for_cf7_form( $cf7_form_id ) {
	$form_ids = function_exists( 'vila_akoya_get_cf7_form_ids' ) ? vila_akoya_get_cf7_form_ids() : array();
	if ( empty( $form_ids ) || ! is_array( $form_ids ) ) {
		return '';
	}

	$cf7_form_id = (string) (int) $cf7_form_id;

	foreach ( vila_akoya_mc4wp_cf7_form_tags() as $form_key => $tag ) {
		if ( empty( $form_ids[ $form_key ] ) ) {
			continue;
		}
		if ( $cf7_form_id === (string) (int) $form_ids[ $form_key ] ) {
			return $tag;
		}
	}

	return '';
}

/**
 * Allow MC4WP to update existing Mailchimp contacts from CF7.
 *
 * Without this, already-subscribed emails bail before tags can be applied.
 *
 * @param array<string, mixed> $options Integration options.
 * @return array<string, mixed>
 */
function vila_akoya_mc4wp_cf7_force_update_existing( $options ) {
	if ( ! is_array( $options ) ) {
		return $options;
	}

	$options['update_existing'] = 1;
	return $options;
}
add_filter( 'mc4wp_integration_contact-form-7_options', 'vila_akoya_mc4wp_cf7_force_update_existing' );

/**
 * Assign Mailchimp tags based on which CF7 form was submitted.
 *
 * @param MC4WP_MailChimp_Subscriber $subscriber Subscriber data.
 * @param int                        $cf7_form_id Contact Form 7 post ID.
 * @return MC4WP_MailChimp_Subscriber
 */
function vila_akoya_mc4wp_cf7_subscriber_tags( $subscriber, $cf7_form_id ) {
	if ( ! is_object( $subscriber ) || ! isset( $subscriber->tags ) || ! is_array( $subscriber->tags ) ) {
		return $subscriber;
	}

	$tag = vila_akoya_mc4wp_tag_for_cf7_form( $cf7_form_id );
	if ( '' === $tag ) {
		return $subscriber;
	}

	$subscriber->tags[] = array(
		'name'   => $tag,
		'status' => 'active',
	);

	return $subscriber;
}
add_filter( 'mc4wp_integration_contact-form-7_subscriber_data', 'vila_akoya_mc4wp_cf7_subscriber_tags', 10, 2 );

/**
 * Explicitly append the form tag after a successful CF7 → Mailchimp subscribe.
 *
 * This covers existing contacts so joining a second list adds a second tag
 * without removing the first.
 *
 * @param MC4WP_Integration                $integration Integration instance.
 * @param string                           $email_address Subscriber email.
 * @param array                            $merge_fields Merge fields.
 * @param array<string, MC4WP_MailChimp_Subscriber> $map List ID => subscriber map.
 * @param int                              $related_object_id CF7 form ID.
 * @return void
 */
function vila_akoya_mc4wp_cf7_apply_tags_after_subscribe( $integration, $email_address, $merge_fields, $map, $related_object_id ) {
	if ( ! is_object( $integration ) || empty( $integration->slug ) || 'contact-form-7' !== $integration->slug ) {
		return;
	}

	$email_address = is_string( $email_address ) ? sanitize_email( $email_address ) : '';
	if ( '' === $email_address || ! is_email( $email_address ) ) {
		return;
	}

	$tag = vila_akoya_mc4wp_tag_for_cf7_form( $related_object_id );
	if ( '' === $tag ) {
		return;
	}

	if ( ! function_exists( 'mc4wp_get_api_v3' ) || empty( $map ) || ! is_array( $map ) ) {
		return;
	}

	try {
		$api = mc4wp_get_api_v3();
	} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		return;
	}

	if ( ! is_object( $api ) || ! method_exists( $api, 'update_list_member_tags' ) ) {
		return;
	}

	$payload = array(
		'tags' => array(
			array(
				'name'   => $tag,
				'status' => 'active',
			),
		),
	);

	foreach ( array_keys( $map ) as $list_id ) {
		$list_id = (string) $list_id;
		if ( '' === $list_id ) {
			continue;
		}

		try {
			$api->update_list_member_tags( $list_id, $email_address, $payload );
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Fail silently; MC4WP debug log still covers subscribe errors.
			continue;
		}
	}
}
add_action( 'mc4wp_integration_subscribed', 'vila_akoya_mc4wp_cf7_apply_tags_after_subscribe', 10, 5 );
