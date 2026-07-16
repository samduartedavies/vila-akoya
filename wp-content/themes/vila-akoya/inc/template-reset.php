<?php
/**
 * Restore theme Page template when a Site Editor customization overrides page.html.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * @param string $slug Template slug, e.g. page or front-page.
 * @return WP_Block_Template|null
 */
function vila_akoya_get_custom_theme_template( $slug ) {
	if ( ! function_exists( 'get_block_template' ) ) {
		return null;
	}

	$template = get_block_template( get_stylesheet() . '//' . $slug, 'wp_template' );
	if (
		$template
		&& ! empty( $template->source )
		&& 'custom' === $template->source
		&& ! empty( $template->wp_id )
	) {
		return $template;
	}

	return null;
}

/**
 * @return WP_Block_Template|null
 */
function vila_akoya_get_custom_page_template() {
	return vila_akoya_get_custom_theme_template( 'page' );
}

/**
 * @return WP_Block_Template|null
 */
function vila_akoya_get_custom_front_page_template() {
	return vila_akoya_get_custom_theme_template( 'front-page' );
}

/**
 * Delete customized template(s) so the theme file is used again.
 *
 * @param string|string[] $slugs Template slug(s).
 * @return int Number of templates removed.
 */
function vila_akoya_reset_custom_theme_templates( $slugs ) {
	$slugs   = (array) $slugs;
	$deleted = 0;

	foreach ( $slugs as $slug ) {
		$custom = vila_akoya_get_custom_theme_template( (string) $slug );
		if ( $custom && ! empty( $custom->wp_id ) && wp_delete_post( (int) $custom->wp_id, true ) ) {
			++$deleted;
		}
	}

	$posts = get_posts(
		array(
			'post_type'      => 'wp_template',
			'post_status'    => array( 'publish', 'draft', 'auto-draft' ),
			'posts_per_page' => -1,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'wp_theme',
					'field'    => 'name',
					'terms'    => get_stylesheet(),
				),
			),
		)
	);

	foreach ( (array) $posts as $post ) {
		if ( ! $post instanceof WP_Post ) {
			continue;
		}
		if ( ! in_array( $post->post_name, $slugs, true ) && ! in_array( str_replace( get_stylesheet() . '//', '', (string) $post->post_name ), $slugs, true ) ) {
			continue;
		}
		if ( wp_delete_post( (int) $post->ID, true ) ) {
			++$deleted;
		}
	}

	if ( $deleted > 0 && function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}

	return $deleted;
}

/**
 * Delete customized Page template(s) so the theme file (parts/header + post-content + parts/footer) is used.
 *
 * @return int Number of templates removed.
 */
function vila_akoya_reset_custom_page_template() {
	return vila_akoya_reset_custom_theme_templates( array( 'page' ) );
}

/**
 * Delete customized Front Page template(s) so the theme front-page.html is used.
 *
 * @return int Number of templates removed.
 */
function vila_akoya_reset_custom_front_page_template() {
	return vila_akoya_reset_custom_theme_templates( array( 'front-page' ) );
}

/**
 * Remove customized Page and Front Page templates on load (theme files are source of truth).
 */
function vila_akoya_maybe_restore_broken_page_template() {
	if ( wp_installing() ) {
		return;
	}

	if ( vila_akoya_get_custom_page_template() ) {
		vila_akoya_reset_custom_page_template();
	}

	if ( vila_akoya_get_custom_front_page_template() ) {
		vila_akoya_reset_custom_front_page_template();
	}
}
add_action( 'init', 'vila_akoya_maybe_restore_broken_page_template', 5 );

/**
 * Tools → Reset page template (manual fallback).
 */
function vila_akoya_register_template_reset_admin_page() {
	add_management_page(
		__( 'Reset page template', 'vila-akoya' ),
		__( 'Reset page template', 'vila-akoya' ),
		'edit_theme_options',
		'vila-akoya-reset-page-template',
		'vila_akoya_render_template_reset_admin_page'
	);
}
add_action( 'admin_menu', 'vila_akoya_register_template_reset_admin_page' );

/**
 * Handle reset form POST.
 */
function vila_akoya_handle_template_reset_post() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to reset templates.', 'vila-akoya' ) );
	}

	check_admin_referer( 'vila_akoya_reset_page_template' );

	$count = vila_akoya_reset_custom_theme_templates( array( 'page', 'front-page' ) );

	$redirect = add_query_arg(
		array(
			'page'       => 'vila-akoya-reset-page-template',
			'vila_reset' => $count > 0 ? '1' : '0',
		),
		admin_url( 'tools.php' )
	);

	wp_safe_redirect( $redirect );
	exit;
}
add_action( 'admin_post_vila_akoya_reset_page_template', 'vila_akoya_handle_template_reset_post' );

/**
 * Admin page markup.
 */
function vila_akoya_render_template_reset_admin_page() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}

	$custom_page  = vila_akoya_get_custom_page_template();
	$custom_front = vila_akoya_get_custom_front_page_template();
	$reset  = isset( $_GET['vila_reset'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['vila_reset'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Reset page template', 'vila-akoya' ); ?></h1>

		<?php if ( '1' === $reset ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Custom Page and Front Page templates removed. Pages use the theme defaults (header + content + footer).', 'vila-akoya' ); ?></p></div>
		<?php elseif ( '0' === $reset ) : ?>
			<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'No customized Page template was found — the theme file is already in use.', 'vila-akoya' ); ?></p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'If the editor shows header/footer shortcodes, a broken middle block, or "Front Page · Template" when you meant to edit Home, a customized template in the Site Editor is usually the cause. Resetting restores the theme files:', 'vila-akoya' ); ?></p>
		<ul class="ul-disc" style="list-style:disc;margin-left:1.5rem;">
			<li><?php esc_html_e( 'Header (site navigation)', 'vila-akoya' ); ?></li>
			<li><?php esc_html_e( 'Your page content (hero + text — what you edit)', 'vila-akoya' ); ?></li>
			<li><?php esc_html_e( 'Footer', 'vila-akoya' ); ?></li>
		</ul>

		<?php if ( $custom_page || $custom_front ) : ?>
			<p><strong><?php esc_html_e( 'Status:', 'vila-akoya' ); ?></strong> <?php esc_html_e( 'A customized template is active and should be reset.', 'vila-akoya' ); ?></p>
		<?php else : ?>
			<p><strong><?php esc_html_e( 'Status:', 'vila-akoya' ); ?></strong> <?php esc_html_e( 'Theme Page template is active (no customization in the database).', 'vila-akoya' ); ?></p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'vila_akoya_reset_page_template' ); ?>
			<input type="hidden" name="action" value="vila_akoya_reset_page_template" />
			<?php submit_button( __( 'Reset Page template to theme default', 'vila-akoya' ), 'primary', 'submit', false ); ?>
		</form>

		<p class="description"><?php esc_html_e( 'After resetting, trash any broken page drafts and create a new page under Pages → Add New.', 'vila-akoya' ); ?></p>
	</div>
	<?php
}

/**
 * Notice on page list / editor when a custom Page template is still active.
 */
function vila_akoya_admin_notice_custom_page_template() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, array( 'page', 'edit-page', 'site-editor' ), true ) ) {
		return;
	}

	if ( ! vila_akoya_get_custom_page_template() && ! vila_akoya_get_custom_front_page_template() ) {
		return;
	}

	$url = admin_url( 'tools.php?page=vila-akoya-reset-page-template' );
	?>
	<div class="notice notice-warning">
		<p>
			<?php
			printf(
				/* translators: %s: tools page URL */
				wp_kses_post( __( 'The Page template was customized in the Site Editor, which can break new pages. <a href="%s">Reset the Page template</a> to use the theme default.', 'vila-akoya' ) ),
				esc_url( $url )
			);
			?>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'vila_akoya_admin_notice_custom_page_template' );
