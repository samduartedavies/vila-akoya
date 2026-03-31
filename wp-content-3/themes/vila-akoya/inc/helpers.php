
<?php
if (!defined('ABSPATH')) { exit; }

function vila_akoya_asset($path = '') {
    return trailingslashit(VILA_AKOYA_URI) . ltrim($path, '/');
}

function vila_akoya_menu_fallback() {
    $items = [
        ['Village', home_url('/village/')],
        ['Campsite', home_url('/campsite/')],
        ['Booking', home_url('/booking/')],
        ['The Campus', home_url('/campus/')],
        ['Forest School', home_url('/forest-school/')],
        ['Contact', home_url('/contact/')],
    ];
    echo '<ul class="menu-fallback">';
    foreach ($items as $item) {
        printf('<li><a href="%s">%s</a></li>', esc_url($item[1]), esc_html($item[0]));
    }
    echo '</ul>';
}

function vila_akoya_render_button($text, $url = '#', $class = 'button-primary') {
    printf('<a class="ak-button %s" href="%s">%s</a>', esc_attr($class), esc_url($url), esc_html($text));
}

function vila_akoya_section_intro($eyebrow, $title, $text, $center = false) {
    $cls = $center ? ' section-intro center' : ' section-intro';
    echo '<div class="' . esc_attr(trim($cls)) . '">';
    if ($eyebrow) echo '<span class="eyebrow">' . esc_html($eyebrow) . '</span>';
    if ($title) echo '<h2>' . wp_kses_post($title) . '</h2>';
    if ($text) echo '<p>' . wp_kses_post($text) . '</p>';
    echo '</div>';
}
