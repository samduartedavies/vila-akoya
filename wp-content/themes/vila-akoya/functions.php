<?php
/**
 * Vila Akoya theme bootstrap.
 *
 * @package VilaAkoya
 */

defined( 'ABSPATH' ) || exit;

define( 'VILA_AKOYA_VERSION', '1.1.1' );

require get_template_directory() . '/inc/nav-walkers.php';
require get_template_directory() . '/inc/setup-theme.php';
require get_template_directory() . '/inc/cf7.php';
require get_template_directory() . '/inc/redirects.php';
require get_template_directory() . '/inc/seed-cf7.php';
require get_template_directory() . '/inc/seed-pages.php';
require get_template_directory() . '/inc/patterns.php';
