<?php
/**
 * WooCommerce + Bookings for WooCommerce (MWB): theme support, pages, default campsite product.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/** Product SKU used to find or create the default bookable campsite product. */
const VILA_AKOYA_CAMPSITE_SKU = 'vila-akoya-campsite';

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
	$opt = (int) get_option( 'vila_akoya_campsite_product_id' );
	if ( $opt && $opt === $product_id ) {
		return true;
	}
	if ( ! function_exists( 'wc_get_product' ) ) {
		return false;
	}
	$product = wc_get_product( $product_id );
	return $product && $product->get_sku() === VILA_AKOYA_CAMPSITE_SKU;
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
 * Create the default bookable “Campsite stay” product once WooCommerce + MWB are loaded.
 */
function vila_akoya_ensure_campsite_booking_product() {
	if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
		return;
	}

	// MWB must be loaded (booking meta helpers + product class).
	if ( ! function_exists( 'wps_booking_get_meta_data' ) ) {
		return;
	}

	$existing = wc_get_product_id_by_sku( VILA_AKOYA_CAMPSITE_SKU );
	if ( $existing ) {
		update_option( 'vila_akoya_campsite_product_id', (int) $existing );
		$excerpt = get_post_field( 'post_excerpt', $existing );
		if ( '' === (string) $excerpt ) {
			wp_update_post(
				array(
					'ID'           => (int) $existing,
					'post_excerpt' => __( 'Per night · Alentejo campsite. Choose check-in and check-out below.', 'vila-akoya' ),
				)
			);
		}
		return;
	}

	$night_price = apply_filters( 'vila_akoya_campsite_default_night_price', '35' );

	$post_id = wp_insert_post(
		array(
			'post_title'   => __( 'Campsite stay', 'vila-akoya' ),
			'post_content' => '',
			'post_excerpt' => __( 'Per night · Alentejo campsite. Choose check-in and check-out below.', 'vila-akoya' ),
			'post_status'  => 'publish',
			'post_type'    => 'product',
		),
		true
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return;
	}

	wp_set_object_terms( $post_id, 'mwb_booking', 'product_type' );

	update_post_meta( $post_id, '_sku', VILA_AKOYA_CAMPSITE_SKU );
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
	update_post_meta( $post_id, 'mwb_mbfw_max_bookings', '5' );

	$avail = vila_akoya_mwb_availability_dates( 548 );
	update_post_meta( $post_id, 'wps_mbfw_set_availability', $avail['dates'] );
	update_post_meta( $post_id, 'wps_mbfw_set_availability_upto', $avail['until'] );

	update_option( 'vila_akoya_campsite_product_id', (int) $post_id );
}
add_action( 'woocommerce_init', 'vila_akoya_ensure_campsite_booking_product', 5 );

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
 * Booking page: scope Flatpickr / layout; cart & checkout: scope global commerce styles.
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
	return '<p class="vila-wc-booking__lede">' . esc_html__( 'Select your stay dates below. Price is per night.', 'vila-akoya' ) . '</p>';
}
add_filter( 'woocommerce_short_description', 'vila_akoya_booking_product_short_description', 15 );
