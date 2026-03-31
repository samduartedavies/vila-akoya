
<?php
/**
 * Plugin Name: Vila Akoya Setup Notes
 * Description: Optional helper plugin containing onboarding notes for this starter build.
 * Version: 1.0.0
 */
if (!defined('ABSPATH')) { exit; }

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    echo '<div class="notice notice-info"><p><strong>Vila Akoya starter loaded.</strong> This project includes a custom theme and enquiry form. After activating the theme, visit Settings → Reading to confirm the Home page is set as the front page.</p></div>';
});
