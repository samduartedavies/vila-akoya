
<?php
if (!defined('ABSPATH')) { exit; }

function vila_akoya_setup() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('custom-logo');
    register_nav_menus([
        'primary' => __('Primary Menu', 'vila-akoya'),
        'footer'  => __('Footer Menu', 'vila-akoya'),
    ]);
}
add_action('after_setup_theme', 'vila_akoya_setup');

function vila_akoya_assets() {
    wp_enqueue_style('vila-akoya-fonts', 'https://fonts.googleapis.com/css2?family=Barrio&family=Newsreader:ital,wght@0,300;0,400;0,500;0,700;1,300;1,400&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap', [], null);
    wp_enqueue_style('vila-akoya-style', VILA_AKOYA_URI . '/assets/css/theme.css', [], VILA_AKOYA_VERSION);
    wp_enqueue_script('vila-akoya-script', VILA_AKOYA_URI . '/assets/js/theme.js', [], VILA_AKOYA_VERSION, true);
}
add_action('wp_enqueue_scripts', 'vila_akoya_assets');

function vila_akoya_body_classes($classes) {
    if (is_front_page()) { $classes[] = 'akoya-home'; }
    return $classes;
}
add_filter('body_class', 'vila_akoya_body_classes');

function vila_akoya_create_pages() {
    $pages = vila_akoya_default_pages();
    $created = [];
    foreach ($pages as $slug => $page) {
        $existing = get_page_by_path($slug);
        if ($existing) {
            $created[$slug] = $existing->ID;
            continue;
        }
        $post_id = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => $page['title'],
            'post_name' => $slug,
            'post_content' => $page['content'] ?? '',
            'post_excerpt' => $page['excerpt'] ?? '',
            'page_template' => $page['template'],
        ]);
        if ($post_id && !is_wp_error($post_id)) {
            update_post_meta($post_id, '_wp_page_template', $page['template']);
            $created[$slug] = $post_id;
        }
    }
    if (!empty($created['home'])) {
        update_option('show_on_front', 'page');
        update_option('page_on_front', $created['home']);
    }

    $menu_name = 'Primary Menu';
    $menu = wp_get_nav_menu_object($menu_name);
    if (!$menu) {
        $menu_id = wp_create_nav_menu($menu_name);
        foreach (['village','campsite','booking','campus','forest-school','contact'] as $slug) {
            if (!empty($created[$slug])) {
                wp_update_nav_menu_item($menu_id, 0, [
                    'menu-item-title' => vila_akoya_default_pages()[$slug]['title'],
                    'menu-item-object' => 'page',
                    'menu-item-object-id' => $created[$slug],
                    'menu-item-type' => 'post_type',
                    'menu-item-status' => 'publish',
                ]);
            }
        }
        set_theme_mod('nav_menu_locations', ['primary' => $menu_id, 'footer' => $menu_id]);
    }
}
add_action('after_switch_theme', 'vila_akoya_create_pages');
