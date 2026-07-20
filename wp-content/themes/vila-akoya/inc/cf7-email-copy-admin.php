<?php
/**
 * Admin UI: edit CF7 confirmation (Mail 2) copy without touching HTML.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Capability required to edit confirmation email copy.
 *
 * @return string
 */
function vila_akoya_cf7_mail_copy_capability() {
	return class_exists( 'WPCF7_ContactForm' ) ? 'wpcf7_edit_contact_forms' : 'manage_options';
}

/**
 * Register submenu under Contact Form 7.
 *
 * @return void
 */
function vila_akoya_cf7_register_mail_copy_admin_page() {
	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		return;
	}

	add_submenu_page(
		'wpcf7',
		__( 'Confirmation emails', 'vila-akoya' ),
		__( 'Confirmation emails', 'vila-akoya' ),
		vila_akoya_cf7_mail_copy_capability(),
		'vila-akoya-cf7-emails',
		'vila_akoya_cf7_render_mail_copy_admin_page'
	);
}
add_action( 'admin_menu', 'vila_akoya_cf7_register_mail_copy_admin_page', 25 );

/**
 * Field labels for the copy editor.
 *
 * @return array<string, string>
 */
function vila_akoya_cf7_mail_copy_field_labels() {
	return array(
		'subject'  => __( 'Email subject', 'vila-akoya' ),
		'title'    => __( 'Headline', 'vila-akoya' ),
		'greeting' => __( 'Greeting', 'vila-akoya' ),
		'intro'    => __( 'Intro paragraph', 'vila-akoya' ),
		'outro'    => __( 'Closing paragraph', 'vila-akoya' ),
	);
}

/**
 * Sanitize and save submitted confirmation email copy.
 *
 * @return void
 */
function vila_akoya_cf7_handle_mail_copy_save() {
	if ( ! is_admin() || ! current_user_can( vila_akoya_cf7_mail_copy_capability() ) ) {
		return;
	}

	if ( empty( $_POST['vila_akoya_cf7_mail_copy_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return;
	}

	check_admin_referer( 'vila_akoya_cf7_mail_copy_save', 'vila_akoya_cf7_mail_copy_nonce' );

	$raw     = isset( $_POST['vila_cf7_copy'] ) && is_array( $_POST['vila_cf7_copy'] ) ? wp_unslash( $_POST['vila_cf7_copy'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$labels  = vila_akoya_cf7_form_copy_labels();
	$fields  = vila_akoya_cf7_mail_copy_fields();
	$cleaned = array();

	foreach ( array_keys( $labels ) as $form_key ) {
		if ( empty( $raw[ $form_key ] ) || ! is_array( $raw[ $form_key ] ) ) {
			continue;
		}

		$cleaned[ $form_key ] = array( 'autoreply' => array() );
		foreach ( $fields as $field ) {
			$value = isset( $raw[ $form_key ][ $field ] ) ? sanitize_textarea_field( (string) $raw[ $form_key ][ $field ] ) : '';
			$cleaned[ $form_key ]['autoreply'][ $field ] = $value;
		}
	}

	update_option( 'vila_akoya_cf7_mail_copy', $cleaned, false );

	if ( function_exists( 'vila_akoya_cf7_upgrade_mail_templates' ) ) {
		vila_akoya_cf7_upgrade_mail_templates();
	}

	$redirect = add_query_arg(
		array(
			'page'              => 'vila-akoya-cf7-emails',
			'vila_cf7_copy_saved' => '1',
		),
		admin_url( 'admin.php' )
	);
	wp_safe_redirect( $redirect );
	exit;
}
add_action( 'admin_init', 'vila_akoya_cf7_handle_mail_copy_save' );

/**
 * Render the confirmation email copy editor.
 *
 * @return void
 */
function vila_akoya_cf7_render_mail_copy_admin_page() {
	if ( ! current_user_can( vila_akoya_cf7_mail_copy_capability() ) ) {
		wp_die( esc_html__( 'You do not have permission to edit these emails.', 'vila-akoya' ) );
	}

	$configs      = vila_akoya_cf7_form_mail_configs();
	$form_labels  = vila_akoya_cf7_form_copy_labels();
	$field_labels = vila_akoya_cf7_mail_copy_field_labels();
	$saved        = ! empty( $_GET['vila_cf7_copy_saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Confirmation emails', 'vila-akoya' ); ?></h1>
		<p>
			<?php esc_html_e( 'Edit the text people receive after submitting a form. Layout and branding stay the same — only change the wording below.', 'vila-akoya' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'You can use mail-tags like [your-name] or [your-email] in greeting and subject lines. Do not edit the HTML “Message body” inside each Contact Form 7 form.', 'vila-akoya' ); ?>
		</p>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Confirmation emails updated.', 'vila-akoya' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="">
			<?php wp_nonce_field( 'vila_akoya_cf7_mail_copy_save', 'vila_akoya_cf7_mail_copy_nonce' ); ?>

			<?php foreach ( $form_labels as $form_key => $form_label ) : ?>
				<?php
				$autoreply = isset( $configs[ $form_key ]['autoreply'] ) && is_array( $configs[ $form_key ]['autoreply'] )
					? $configs[ $form_key ]['autoreply']
					: array();
				?>
				<div class="card" style="max-width:720px;padding:1.25em 1.5em;margin:1.5em 0;">
					<h2 style="margin-top:0;"><?php echo esc_html( $form_label ); ?></h2>
					<table class="form-table" role="presentation">
						<?php foreach ( $field_labels as $field => $label ) : ?>
							<?php
							$value     = isset( $autoreply[ $field ] ) ? (string) $autoreply[ $field ] : '';
							$input_id  = 'vila-cf7-copy-' . $form_key . '-' . $field;
							$is_long   = in_array( $field, array( 'intro', 'outro' ), true );
							?>
							<tr>
								<th scope="row"><label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label></th>
								<td>
									<?php if ( $is_long ) : ?>
										<textarea
											class="large-text"
											rows="3"
											id="<?php echo esc_attr( $input_id ); ?>"
											name="<?php echo esc_attr( 'vila_cf7_copy[' . $form_key . '][' . $field . ']' ); ?>"
										><?php echo esc_textarea( $value ); ?></textarea>
									<?php else : ?>
										<input
											type="text"
											class="large-text"
											id="<?php echo esc_attr( $input_id ); ?>"
											name="<?php echo esc_attr( 'vila_cf7_copy[' . $form_key . '][' . $field . ']' ); ?>"
											value="<?php echo esc_attr( $value ); ?>"
										/>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				</div>
			<?php endforeach; ?>

			<?php submit_button( __( 'Save confirmation emails', 'vila-akoya' ) ); ?>
		</form>
	</div>
	<?php
}

/**
 * Point editors away from the HTML Message body on CF7 form screens.
 *
 * @return void
 */
function vila_akoya_cf7_mail_copy_edit_notice() {
	if ( ! is_admin() || ! current_user_can( vila_akoya_cf7_mail_copy_capability() ) ) {
		return;
	}

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! in_array( $page, array( 'wpcf7', 'wpcf7-new' ), true ) ) {
		return;
	}

	if ( empty( $_GET['post'] ) && 'wpcf7-new' !== $page ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$url = admin_url( 'admin.php?page=vila-akoya-cf7-emails' );
	?>
	<div class="notice notice-info">
		<p>
			<?php
			printf(
				/* translators: %s: link to confirmation email copy editor */
				esc_html__( 'To change confirmation email wording (Mail 2), use %s — leave the HTML Message body alone.', 'vila-akoya' ),
				'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Contact → Confirmation emails', 'vila-akoya' ) . '</a>'
			);
			?>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'vila_akoya_cf7_mail_copy_edit_notice' );
