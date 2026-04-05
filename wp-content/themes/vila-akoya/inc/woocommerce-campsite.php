<?php
/**
 * WooCommerce + Bookings for WooCommerce (MWB): theme support, default campsite product.
 *
 * Uses the free “Bookings for WooCommerce” plugin (`mwb-bookings-for-woocommerce`) for calendar
 * booking on the front end and booking management in wp-admin.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/** Legacy single-product SKU (older installs); new installs use one bookable product per plot. */
const VILA_AKOYA_CAMPSITE_SKU = 'vila-akoya-campsite';

/** SKU prefix for plot inventory: `vila-akoya-plot-1` … `vila-akoya-plot-N`. */
const VILA_AKOYA_CAMPSITE_PLOT_SKU_PREFIX = 'vila-akoya-plot-';

/**
 * Bump this when default MWB booking meta (people, capacity, etc.) should be re-applied to the seeded product.
 */
const VILA_AKOYA_CAMPSITE_MWB_META_VER = 3;

/**
 * Option key: list of product IDs (one mwb_booking product per plot).
 */
const VILA_AKOYA_CAMPSITE_PLOT_PRODUCT_IDS_OPTION = 'vila_akoya_campsite_plot_product_ids';

/**
 * @param int $n Plot index 1..N.
 * @return string
 */
function vila_akoya_campsite_plot_sku( $n ) {
	$n = max( 1, (int) $n );
	return VILA_AKOYA_CAMPSITE_PLOT_SKU_PREFIX . $n;
}

/**
 * Product IDs for each campsite plot (parallel bookable products; each has max_bookings = 1).
 *
 * @return int[]
 */
function vila_akoya_get_campsite_plot_product_ids() {
	$ids = get_option( VILA_AKOYA_CAMPSITE_PLOT_PRODUCT_IDS_OPTION, array() );
	if ( ! is_array( $ids ) ) {
		return array();
	}
	$out = array();
	foreach ( $ids as $id ) {
		$id = (int) $id;
		if ( $id && get_post_status( $id ) ) {
			$out[] = $id;
		}
	}
	return $out;
}

/**
 * Primary campsite product ID for shortcodes / embedded booking page (first plot).
 *
 * @return int
 */
function vila_akoya_get_campsite_primary_product_id() {
	$plots = vila_akoya_get_campsite_plot_product_ids();
	if ( ! empty( $plots ) ) {
		return (int) $plots[0];
	}
	return (int) get_option( 'vila_akoya_campsite_product_id' );
}

/**
 * Whether this product is the seeded campsite bookable product (stored option or SKU match).
 *
 * @param int $product_id Product ID.
 * @return bool
 */
function vila_akoya_is_campsite_product_id( $product_id ) {
	$product_id = (int) $product_id;
	if ( ! $product_id ) {
		return false;
	}
	$plots = vila_akoya_get_campsite_plot_product_ids();
	if ( $plots && in_array( $product_id, $plots, true ) ) {
		return true;
	}
	$opt = (int) get_option( 'vila_akoya_campsite_product_id' );
	if ( $opt && $opt === $product_id ) {
		return true;
	}
	// SKU check loads the product; only after WooCommerce has finished bootstrap (see wc_doing_it_wrong in WC 3.9+).
	if ( ! function_exists( 'wc_get_product' ) || ! did_action( 'woocommerce_init' ) ) {
		return false;
	}
	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return false;
	}
	$sku = $product->get_sku();
	if ( $sku === VILA_AKOYA_CAMPSITE_SKU ) {
		return true;
	}
	return (bool) preg_match( '/^' . preg_quote( VILA_AKOYA_CAMPSITE_PLOT_SKU_PREFIX, '/' ) . '\d+$/', (string) $sku );
}

/**
 * Theme compatibility for WooCommerce.
 */
function vila_akoya_woocommerce_setup() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	add_theme_support( 'woocommerce' );
}
add_action( 'after_setup_theme', 'vila_akoya_woocommerce_setup' );

/**
 * Create Cart / Checkout / My Account pages if missing (e.g. fresh wp-env).
 */
function vila_akoya_ensure_wc_core_pages() {
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Install' ) ) {
		return;
	}
	if ( wc_get_page_id( 'cart' ) > 0 && wc_get_page_id( 'checkout' ) > 0 ) {
		return;
	}
	WC_Install::create_pages();
}
add_action( 'admin_init', 'vila_akoya_ensure_wc_core_pages', 1 );

/**
 * Build space-separated Y-m-d dates for MWB “Set days availability” (matches plugin admin).
 *
 * @param int $days Number of consecutive bookable days from today.
 * @return array{ dates: string, until: string }
 */
function vila_akoya_mwb_availability_dates( $days = 548 ) {
	$tz    = wp_timezone();
	$start = new DateTime( 'now', $tz );
	$start->setTime( 0, 0, 0 );
	$list  = array();
	for ( $i = 0; $i < $days; $i++ ) {
		$d     = clone $start;
		$d->modify( '+' . $i . ' days' );
		$list[] = $d->format( 'Y-m-d' );
	}
	$until = end( $list );
	return array(
		'dates' => implode( ' ', $list ),
		'until' => $until ? $until : '',
	);
}

/**
 * Apply MWB meta for campsite: guest limits, per-person nightly rate, and concurrent booking capacity for this product.
 *
 * For per-plot products, pass `$max_concurrent_for_this_product = 1` so each plot is exclusive.
 *
 * @param int  $post_id                         Product post ID.
 * @param int|null $max_concurrent_for_this_product MWB "max bookings" for this product (null = sum of all plots — legacy).
 */
function vila_akoya_campsite_apply_mwb_booking_defaults( $post_id, $max_concurrent_for_this_product = null ) {
	$post_id = (int) $post_id;
	if ( ! $post_id ) {
		return;
	}

	$plot_count = (int) apply_filters( 'vila_akoya_campsite_plot_count', 5 );
	$plot_count = max( 1, $plot_count );
	if ( null === $max_concurrent_for_this_product ) {
		$max_concurrent_for_this_product = $plot_count;
	}
	$max_concurrent_for_this_product = max( 1, (int) $max_concurrent_for_this_product );

	$min_p   = (int) apply_filters( 'vila_akoya_campsite_min_people', 1 );
	$max_p   = (int) apply_filters( 'vila_akoya_campsite_max_people', 8 );
	$min_p   = max( 1, $min_p );
	$max_p   = max( $min_p, $max_p );
	$night_price = apply_filters( 'vila_akoya_campsite_default_night_price', '35' );

	$meta = array(
		'mwb_mbfw_max_bookings'                    => (string) $max_concurrent_for_this_product,
		'mwb_mbfw_is_people_option'                => 'yes',
		'mwb_mbfw_minimum_people_per_booking'      => (string) $min_p,
		'mwb_mbfw_maximum_people_per_booking'      => (string) $max_p,
		'mwb_mbfw_is_booking_unit_cost_per_people' => 'yes',
		'mwb_mbfw_is_booking_base_cost_per_people' => 'no',
		'mwb_mbfw_booking_unit_cost'               => $night_price,
	);

	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}

	wc_delete_product_transients( $post_id );
}

/**
 * One-time upgrade: apply people + capacity meta to an existing seeded product after theme updates.
 */
function vila_akoya_campsite_maybe_upgrade_mwb_booking_meta() {
	if ( ! function_exists( 'wps_booking_get_meta_data' ) ) {
		return;
	}
	$current = (int) get_option( 'vila_akoya_campsite_mwb_meta_ver', 0 );
	if ( $current >= VILA_AKOYA_CAMPSITE_MWB_META_VER ) {
		return;
	}
	$plots = vila_akoya_get_campsite_plot_product_ids();
	if ( ! empty( $plots ) ) {
		foreach ( $plots as $plot_pid ) {
			vila_akoya_campsite_apply_mwb_booking_defaults( (int) $plot_pid, 1 );
		}
	} else {
		$pid = (int) get_option( 'vila_akoya_campsite_product_id' );
		if ( ! $pid && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$pid = (int) wc_get_product_id_by_sku( VILA_AKOYA_CAMPSITE_SKU );
		}
		if ( $pid ) {
			vila_akoya_campsite_apply_mwb_booking_defaults( $pid );
		}
	}
	if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
		$legacy = wc_get_product_id_by_sku( VILA_AKOYA_CAMPSITE_SKU );
		if ( $legacy ) {
			vila_akoya_campsite_apply_mwb_booking_defaults( (int) $legacy );
		}
	}
	update_option( 'vila_akoya_campsite_mwb_meta_ver', VILA_AKOYA_CAMPSITE_MWB_META_VER );
}
// After woocommerce_init completes: wc_delete_product_transients() must not run during WC bootstrap.
add_action( 'wp_loaded', 'vila_akoya_campsite_maybe_upgrade_mwb_booking_meta', 21 );

/**
 * Create shared mwb_booking meta for one campsite plot product.
 *
 * @param int    $post_id   Product ID.
 * @param string $sku       Product SKU.
 * @param string $title     Product title.
 * @param string $night_price Price string.
 */
function vila_akoya_seed_campsite_plot_product_core( $post_id, $sku, $title, $night_price ) {
	$post_id = (int) $post_id;
	wp_set_object_terms( $post_id, 'mwb_booking', 'product_type' );

	update_post_meta( $post_id, '_sku', $sku );
	update_post_meta( $post_id, '_virtual', 'yes' );
	update_post_meta( $post_id, '_downloadable', 'no' );
	update_post_meta( $post_id, '_sold_individually', 'no' );
	update_post_meta( $post_id, '_manage_stock', 'no' );
	update_post_meta( $post_id, '_stock_status', 'instock' );
	update_post_meta( $post_id, '_regular_price', $night_price );
	update_post_meta( $post_id, '_price', $night_price );

	update_post_meta( $post_id, 'wps_mbfw_booking_type', 'dual_cal' );
	update_post_meta( $post_id, 'mwb_mbfw_booking_criteria', 'customer_selected_unit' );
	update_post_meta( $post_id, 'mwb_mbfw_booking_count', '1' );
	update_post_meta( $post_id, 'mwb_mbfw_booking_unit', 'day' );
	update_post_meta( $post_id, 'mwb_mbfw_enable_calendar', 'yes' );
	update_post_meta( $post_id, '_is_calendar_booking_product', 'yes' );
	update_post_meta( $post_id, 'mwb_mbfw_booking_unit_cost', $night_price );
	update_post_meta( $post_id, 'mwb_mbfw_admin_confirmation', 'no' );
	update_post_meta( $post_id, 'mwb_mbfw_cancellation_allowed', 'yes' );
	update_post_meta( $post_id, 'mwb_mbfw_show_date_with_time', 'no' );
	update_post_meta( $post_id, 'mwb_mbfw_enable_time_picker', 'no' );
	$avail = vila_akoya_mwb_availability_dates( 548 );
	update_post_meta( $post_id, 'wps_mbfw_set_availability', $avail['dates'] );
	update_post_meta( $post_id, 'wps_mbfw_set_availability_upto', $avail['until'] );

	vila_akoya_campsite_apply_mwb_booking_defaults( $post_id, 1 );
}

/**
 * Create one bookable product per campsite plot (MWB max_bookings = 1 each) so inventory is per plot.
 * Hides the legacy single SKU product from the catalog when plot products exist.
 */
function vila_akoya_ensure_campsite_booking_product() {
	if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
		return;
	}

	if ( ! function_exists( 'wps_booking_get_meta_data' ) ) {
		return;
	}

	$plot_count = (int) apply_filters( 'vila_akoya_campsite_plot_count', 5 );
	$plot_count = max( 1, $plot_count );

	$existing_ids = vila_akoya_get_campsite_plot_product_ids();
	if ( count( $existing_ids ) === $plot_count ) {
		update_option( 'vila_akoya_campsite_product_id', (int) $existing_ids[0] );
		return;
	}

	$night_price = apply_filters( 'vila_akoya_campsite_default_night_price', '35' );
	$plot_ids    = array();

	for ( $i = 1; $i <= $plot_count; $i++ ) {
		$sku = vila_akoya_campsite_plot_sku( $i );
		$found = wc_get_product_id_by_sku( $sku );
		if ( $found ) {
			$plot_ids[] = (int) $found;
			vila_akoya_seed_campsite_plot_product_core( (int) $found, $sku, '', $night_price );
			continue;
		}
		/* translators: %d: plot number */
		$title = sprintf( __( 'Campsite stay — Plot %d', 'vila-akoya' ), $i );

		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => '',
				'post_excerpt' => __( 'Per night · Alentejo campsite. Choose check-in and check-out below.', 'vila-akoya' ),
				'post_status'  => 'publish',
				'post_type'    => 'product',
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			continue;
		}

		vila_akoya_seed_campsite_plot_product_core( (int) $post_id, $sku, $title, $night_price );
		$plot_ids[] = (int) $post_id;
	}

	if ( empty( $plot_ids ) ) {
		return;
	}

	update_option( VILA_AKOYA_CAMPSITE_PLOT_PRODUCT_IDS_OPTION, $plot_ids );
	update_option( 'vila_akoya_campsite_product_id', (int) $plot_ids[0] );
	update_option( 'vila_akoya_campsite_mwb_meta_ver', VILA_AKOYA_CAMPSITE_MWB_META_VER );

	$legacy = wc_get_product_id_by_sku( VILA_AKOYA_CAMPSITE_SKU );
	if ( $legacy ) {
		wp_update_post(
			array(
				'ID'          => (int) $legacy,
				'post_status' => 'draft',
			)
		);
		update_post_meta( (int) $legacy, '_catalog_visibility', 'hidden' );
	}
}
// Run after WooCommerce init: avoids wc_get_product / transients during woocommerce_init (WC 3.9+ notice).
add_action( 'wp_loaded', 'vila_akoya_ensure_campsite_booking_product', 20 );

/**
 * Remind admins when WooCommerce is active but MWB is not (campsite product cannot be seeded).
 */
function vila_akoya_admin_notice_mwb_bookings() {
	if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	if ( function_exists( 'wps_booking_get_meta_data' ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins', 'edit-product', 'woocommerce_page_wc-settings' ), true ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>';
	echo esc_html__(
		'Vila Akoya: Install and activate the free plugin “Bookings for WooCommerce” (Bookings for WooCommerce by WP Swings) so the campsite booking product and calendar can work.',
		'vila-akoya'
	);
	echo '</p></div>';
}
add_action( 'admin_notices', 'vila_akoya_admin_notice_mwb_bookings' );

/**
 * Whether the campsite product is rendered inside the Booking page (shortcode), not on a single-product URL.
 *
 * @return bool
 */
function vila_akoya_is_booking_embedded_product() {
	global $post;
	$booking_id = (int) get_option( 'vila_akoya_campsite_product_id' );
	if ( ! $booking_id || ! is_a( $post, 'WP_Post' ) || 'product' !== $post->post_type ) {
		return false;
	}
	return (int) $post->ID === $booking_id && ! is_singular( 'product' );
}

/**
 * Booking page: layout scope; cart & checkout: scope global commerce styles.
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function vila_akoya_wc_body_classes( $classes ) {
	if ( is_page( 'booking' ) ) {
		$classes[] = 'vila-page-booking';
	}
	if ( class_exists( 'WooCommerce' ) && ( is_cart() || is_checkout() || is_account_page() || ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) ) ) {
		$classes[] = 'vila-wc-commerce';
	}
	return $classes;
}
add_filter( 'body_class', 'vila_akoya_wc_body_classes' );

/**
 * Embedded product on /booking/: use H2 for title (page already has H1), drop related/upsells, hide breadcrumbs.
 */
function vila_akoya_booking_embedded_product_hooks() {
	static $done = false;
	if ( $done || ! vila_akoya_is_booking_embedded_product() ) {
		return;
	}
	$done = true;
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title', 5 );
	add_action( 'woocommerce_single_product_summary', 'vila_akoya_embedded_product_title', 5 );
	remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
	if ( function_exists( 'woocommerce_show_product_sale_flash' ) ) {
		remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10 );
	}
}
add_action( 'woocommerce_before_single_product', 'vila_akoya_booking_embedded_product_hooks', 1 );

/**
 * Campsite stay: no star rating or reviews tab (booking flow, not a review surface).
 */
function vila_akoya_campsite_hide_reviews_and_rating() {
	global $product;
	if ( ! $product || ! vila_akoya_is_campsite_product_id( $product->get_id() ) ) {
		return;
	}
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10 );
}
add_action( 'woocommerce_before_single_product', 'vila_akoya_campsite_hide_reviews_and_rating', 2 );

/**
 * @param array<string, array<string, mixed>> $tabs Product tabs.
 * @return array<string, array<string, mixed>>
 */
function vila_akoya_campsite_remove_reviews_tab( $tabs ) {
	global $product;
	if ( $product && vila_akoya_is_campsite_product_id( $product->get_id() ) ) {
		unset( $tabs['reviews'] );
	}
	return $tabs;
}
add_filter( 'woocommerce_product_tabs', 'vila_akoya_campsite_remove_reviews_tab', 99 );

/**
 * Output product title as H2 when embedded on the Booking page.
 */
function vila_akoya_embedded_product_title() {
	the_title( '<h2 class="product_title entry-title">', '</h2>' );
}

/**
 * Fallback short description for the campsite product when empty.
 *
 * @param string $desc HTML.
 * @return string
 */
function vila_akoya_booking_product_short_description( $desc ) {
	global $post;
	$booking_id = (int) get_option( 'vila_akoya_campsite_product_id' );
	if ( ! $booking_id || ! is_a( $post, 'WP_Post' ) || (int) $post->ID !== $booking_id ) {
		return $desc;
	}
	if ( '' !== trim( wp_strip_all_tags( (string) $desc ) ) ) {
		return $desc;
	}
	return '<p class="vila-wc-booking__lede">' . esc_html__( 'Choose dates, number of guests, and unit type. Nightly rate is per guest; pitch supplements apply by night.', 'vila-akoya' ) . '</p>';
}
add_filter( 'woocommerce_short_description', 'vila_akoya_booking_product_short_description', 15 );
