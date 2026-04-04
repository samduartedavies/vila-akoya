<?php
/**
 * Theme supports, assets, menus, editor styles.
 *
 * @package VilaAkoya
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'after_setup_theme',
	function () {
		load_theme_textdomain( 'vila-akoya', get_template_directory() . '/languages' );
		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'responsive-embeds' );
		add_theme_support(
			'html5',
			array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' )
		);
		add_theme_support( 'editor-styles' );
		add_editor_style( 'assets/css/theme.css' );
		add_editor_style( 'assets/css/editor-canvas.css' );

		register_nav_menus(
			array(
				'primary' => __( 'Primary navigation', 'vila-akoya' ),
				'footer'  => __( 'Footer links', 'vila-akoya' ),
			)
		);
	}
);

add_filter(
	'body_class',
	function ( $classes ) {
		if ( is_front_page() ) {
			$classes[] = 'vila-akoya-home';
		}
		return $classes;
	}
);

add_action(
	'admin_bar_menu',
	function ( $bar ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'     => 'vila-akoya-site-editor',
				'parent' => 'site-name',
				'title'  => __( 'Edit site (header & footer)', 'vila-akoya' ),
				'href'   => admin_url( 'site-editor.php' ),
			)
		);
	},
	100
);

add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( post_type_exists( 'wpcf7_contact_form' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		esc_html_e( 'Vila Akoya: Install and activate the Contact Form 7 plugin so inquiry forms render on the site.', 'vila-akoya' );
		echo '</p></div>';
	}
);

add_action(
	'wp_enqueue_scripts',
	function () {
		wp_enqueue_style(
			'vila-akoya-fonts',
			'https://fonts.googleapis.com/css2?family=Barrio&family=Marcellus&family=Josefin+Sans:ital,wght@0,100..700;1,100..700&display=swap',
			array(),
			null
		);
		wp_enqueue_style(
			'vila-akoya-material-symbols',
			'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0',
			array(),
			null
		);

		wp_enqueue_script(
			'tailwind-cdn',
			'https://cdn.tailwindcss.com?plugins=forms,container-queries',
			array(),
			null,
			false
		);

		$config_path = get_template_directory() . '/assets/js/tailwind-config.js';
		if ( file_exists( $config_path ) ) {
			wp_add_inline_script(
				'tailwind-cdn',
				file_get_contents( $config_path ),
				'after'
			);
		}

		wp_enqueue_style(
			'vila-akoya-theme',
			get_template_directory_uri() . '/assets/css/theme.css',
			array(),
			VILA_AKOYA_VERSION
		);
	},
	20
);

add_action(
	'enqueue_block_editor_assets',
	function () {
		wp_enqueue_style(
			'vila-akoya-fonts',
			'https://fonts.googleapis.com/css2?family=Barrio&family=Marcellus&family=Josefin+Sans:ital,wght@0,100..700;1,100..700&display=swap',
			array(),
			null
		);
		wp_enqueue_script(
			'tailwind-cdn',
			'https://cdn.tailwindcss.com?plugins=forms,container-queries',
			array( 'wp-blocks', 'wp-element' ),
			null,
			false
		);
		$config_path = get_template_directory() . '/assets/js/tailwind-config.js';
		if ( file_exists( $config_path ) ) {
			wp_add_inline_script(
				'tailwind-cdn',
				file_get_contents( $config_path ),
				'after'
			);
		}
		wp_enqueue_style(
			'vila-akoya-theme',
			get_template_directory_uri() . '/assets/css/theme.css',
			array(),
			VILA_AKOYA_VERSION
		);

		wp_enqueue_script(
			'vila-akoya-editor-tailwind',
			get_template_directory_uri() . '/assets/js/editor-tailwind-refresh.js',
			array( 'wp-data', 'tailwind-cdn', 'wp-blocks' ),
			VILA_AKOYA_VERSION,
			true
		);
	},
	20
);
