
<?php if (!defined('ABSPATH')) { exit; } ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo('charset'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<header class="site-header">
  <div class="site-header__inner ak-container">
    <a class="site-branding" href="<?php echo esc_url(home_url('/')); ?>"><?php bloginfo('name'); ?></a>
    <nav class="primary-nav" aria-label="Primary">
      <?php
      wp_nav_menu([
        'theme_location' => 'primary',
        'container' => false,
        'fallback_cb' => 'vila_akoya_menu_fallback',
      ]);
      ?>
    </nav>
    <a class="header-booking" href="<?php echo esc_url(home_url('/booking/')); ?>">Booking</a>
    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="mobile-menu">☰</button>
  </div>
  <div id="mobile-menu" class="mobile-menu">
    <?php vila_akoya_menu_fallback(); ?>
  </div>
</header>
<main id="site-content">
