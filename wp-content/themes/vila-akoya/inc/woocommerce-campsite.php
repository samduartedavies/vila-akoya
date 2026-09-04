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
 * Default campsite plot numbers (1..34 minus gaps). Matches vila-campsite-booking plugin when active.
 *
 * @return int[]
 */
function vila_akoya_default_campsite_plot_numbers() {
	if ( class_exists( '\Vila_CB\Plot_Repository' ) ) {
		return \Vila_CB\Plot_Repository::default_plot_numbers();
	}
	$excluded = array( 6, 16, 17, 20, 22, 25, 26, 27, 29 );
	$numbers  = array();
	for ( $i = 1; $i <= 34; $i++ ) {
		if ( ! in_array( $i, $excluded, true ) ) {
			$numbers[] = $i;
		}
	}
	/**
	 * @param int[] $numbers Plot numbers for WooCommerce plot products.
	 */
	return apply_filters( 'vila_akoya_default_plot_numbers', $numbers );
}

/**
 * Plot number from a plot product SKU (`vila-akoya-plot-12` → 12).
 *
 * @param int $product_id Product ID.
 * @return int
 */
function vila_akoya_campsite_plot_number_from_product_id( $product_id ) {
	$product_id = (int) $product_id;
	if ( ! $product_id || ! function_exists( 'wc_get_product' ) || ! did_action( 'woocommerce_init' ) ) {
		return 0;
	}
	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return 0;
	}
	$sku = (string) $product->get_sku();
	if ( preg_match( '/^' . preg_quote( VILA_AKOYA_CAMPSITE_PLOT_SKU_PREFIX, '/' ) . '(\d+)$/', $sku, $m ) ) {
		return (int) $m[1];
	}
	return 0;
}

/**
 * @return int
 */
function vila_akoya_default_campsite_plot_count() {
	return count( vila_akoya_default_campsite_plot_numbers() );
}

add_filter(
	'vila_akoya_campsite_plot_count',
	static function () {
		return vila_akoya_default_campsite_plot_count();
	}
);

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
	$night_price = apply_filters( 'vila_akoya_campsite_default_night_price', '9' );

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

	$plot_numbers = vila_akoya_default_campsite_plot_numbers();
	if ( empty( $plot_numbers ) ) {
		return;
	}

	$existing_ids = vila_akoya_get_campsite_plot_product_ids();
	if ( count( $existing_ids ) === count( $plot_numbers ) ) {
		update_option( 'vila_akoya_campsite_product_id', (int) $existing_ids[0] );
		return;
	}

	$night_price = apply_filters( 'vila_akoya_campsite_default_night_price', '9' );
	$plot_ids    = array();

	foreach ( $plot_numbers as $i ) {
		$i   = (int) $i;
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
				'post_excerpt' => '',
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
	if ( class_exists( 'WooCommerce' ) && function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
		$classes[] = 'vila-page-order-received';
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
 * Hide SKU and product meta row on campsite bookable products (internal SKU is not for guests).
 */
function vila_akoya_campsite_remove_single_product_meta() {
	global $product;
	if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
		return;
	}
	if ( ! vila_akoya_is_campsite_product_id( (int) $product->get_id() ) ) {
		return;
	}
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
}
add_action( 'woocommerce_before_single_product', 'vila_akoya_campsite_remove_single_product_meta', 3 );

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
	echo '<h2 class="product_title entry-title">' . esc_html__( 'Book your stay', 'vila-akoya' ) . '</h2>';
}

/**
 * Hide WooCommerce product short description for campsite bookable products.
 *
 * Copy for the booking flow lives in the page layout; the product excerpt is only
 * used internally and was showing redundant checkout/payment hints on the form.
 *
 * @param string $desc HTML.
 * @return string
 */
function vila_akoya_booking_product_short_description( $desc ) {
	$product_id = (int) get_the_ID();
	if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
		return is_string( $desc ) ? $desc : (string) ( $desc ?? '' );
	}
	if ( ! vila_akoya_is_campsite_product_id( $product_id ) ) {
		return is_string( $desc ) ? $desc : (string) ( $desc ?? '' );
	}
	return '';
}
add_filter( 'woocommerce_short_description', 'vila_akoya_booking_product_short_description', 15 );

/**
 * Hide campsite short description on cart/checkout line rendering (Checkout block uses product short_description).
 *
 * @param string     $desc    Product short description.
 * @param WC_Product $product Product object.
 * @return string
 */
function vila_akoya_campsite_cart_checkout_short_description( $desc, $product ) {
	if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
		return is_string( $desc ) ? $desc : (string) ( $desc ?? '' );
	}
	if ( ! ( is_cart() || is_checkout() ) ) {
		return is_string( $desc ) ? $desc : (string) ( $desc ?? '' );
	}
	if ( ! vila_akoya_is_campsite_product_id( (int) $product->get_id() ) ) {
		return is_string( $desc ) ? $desc : (string) ( $desc ?? '' );
	}
	return '';
}
add_filter( 'woocommerce_product_get_short_description', 'vila_akoya_campsite_cart_checkout_short_description', 15, 2 );

/**
 * Product ID from the current add-to-cart request (classic form or WC AJAX).
 *
 * @return int
 */
function vila_akoya_add_to_cart_request_product_id() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- WC core validates; we only read product id for routing.
	if ( isset( $_REQUEST['add-to-cart'] ) ) {
		$id = absint( wp_unslash( $_REQUEST['add-to-cart'] ) );
		if ( $id ) {
			return $id;
		}
	}
	if ( isset( $_REQUEST['product_id'] ) ) {
		$id = absint( wp_unslash( $_REQUEST['product_id'] ) );
		if ( $id ) {
			return $id;
		}
	}
	if ( isset( $_REQUEST['mwb_mbfw_booking_product_id'] ) ) {
		$id = absint( wp_unslash( $_REQUEST['mwb_mbfw_booking_product_id'] ) );
		if ( $id ) {
			return $id;
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	return 0;
}

/**
 * After adding a campsite stay to the cart, go straight to checkout (classic + AJAX add-to-cart).
 *
 * @param string         $url     Redirect URL.
 * @param WC_Product|null $product Product when provided by WooCommerce.
 * @return string
 */
function vila_akoya_campsite_add_to_cart_redirect_checkout( $url, $product = null ) {
	$pid = 0;
	if ( $product && is_a( $product, 'WC_Product' ) ) {
		$pid = (int) $product->get_id();
	}
	if ( ! $pid ) {
		$pid = vila_akoya_add_to_cart_request_product_id();
	}
	$passthrough = is_string( $url ) ? $url : (string) ( $url ?? '' );
	if ( ! $pid ) {
		return $passthrough;
	}
	if ( class_exists( '\Vila_CB\Meal_Booking' ) && \Vila_CB\Meal_Booking::is_meals_product( $pid ) ) {
		if ( wc_get_page_id( 'checkout' ) <= 0 ) {
			return $passthrough;
		}
		return wc_get_checkout_url();
	}
	if ( ! vila_akoya_is_campsite_product_id( $pid ) && ! ( function_exists( 'vila_akoya_is_stay_product_id' ) && vila_akoya_is_stay_product_id( $pid ) ) ) {
		return $passthrough;
	}
	if ( wc_get_page_id( 'checkout' ) <= 0 ) {
		return $passthrough;
	}
	return wc_get_checkout_url();
}
add_filter( 'woocommerce_add_to_cart_redirect', 'vila_akoya_campsite_add_to_cart_redirect_checkout', 99, 2 );

/**
 * Hide the “… has been added to your cart” notice for campsite stays (redundant when redirecting to checkout).
 *
 * @param string               $message  Notice HTML/text.
 * @param int|array<int, int>  $products Product ID(s) or cart qty map keyed by product ID.
 * @param bool                 $show_qty Whether quantities are shown.
 * @return string
 */
function vila_akoya_suppress_campsite_add_to_cart_notice( $message, $products, $show_qty = false ) {
	unset( $show_qty );
	if ( ! function_exists( 'vila_akoya_is_campsite_product_id' ) ) {
		return $message;
	}
	$ids = array();
	if ( is_array( $products ) ) {
		foreach ( array_keys( $products ) as $k ) {
			$ids[] = (int) $k;
		}
	} elseif ( is_numeric( $products ) ) {
		$ids[] = (int) $products;
	}
	foreach ( array_unique( array_filter( $ids ) ) as $pid ) {
		if ( ! $pid ) {
			continue;
		}
		if ( vila_akoya_is_campsite_product_id( $pid ) || ( function_exists( 'vila_akoya_is_stay_product_id' ) && vila_akoya_is_stay_product_id( $pid ) ) ) {
			return '';
		}
	}
	return $message;
}
add_filter( 'woocommerce_add_to_cart_message', 'vila_akoya_suppress_campsite_add_to_cart_notice', 10, 3 );

/**
 * Block checkout: hide “Return to cart” (Checkout Actions block may still have it enabled in saved templates).
 *
 * @param array $parsed_block Parsed block.
 * @param array $source_block Source block.
 * @return array
 */
function vila_akoya_checkout_actions_hide_return_to_cart( $parsed_block, $source_block ) {
	unset( $source_block );
	if ( empty( $parsed_block['blockName'] ) ) {
		return $parsed_block;
	}
	$names = array(
		'woocommerce/checkout-actions-block',
		'woocommerce/checkout-actions',
	);
	if ( ! in_array( $parsed_block['blockName'], $names, true ) ) {
		return $parsed_block;
	}
	if ( ! isset( $parsed_block['attrs'] ) || ! is_array( $parsed_block['attrs'] ) ) {
		$parsed_block['attrs'] = array();
	}
	$parsed_block['attrs']['showReturnToCart'] = false;
	return $parsed_block;
}
add_filter( 'render_block_data', 'vila_akoya_checkout_actions_hide_return_to_cart', 10, 2 );

/**
 * Resolve the booking page URL.
 *
 * @return string
 */
function vila_akoya_booking_page_url() {
	$page = get_page_by_path( 'booking' );
	if ( $page && ! is_wp_error( $page ) ) {
		$url = get_permalink( (int) $page->ID );
		if ( is_string( $url ) && '' !== $url ) {
			return $url;
		}
	}
	return home_url( '/booking/' );
}

/**
 * Keep empty checkout sessions on the booking flow (checkout -> booking), not cart.
 *
 * @return bool
 */
function vila_akoya_disable_checkout_empty_cart_default_redirect() {
	return false;
}
add_filter( 'woocommerce_checkout_redirect_empty_cart', 'vila_akoya_disable_checkout_empty_cart_default_redirect' );

/**
 * Redirect direct visits to checkout with an empty cart back to the booking page.
 *
 * @return void
 */
function vila_akoya_redirect_empty_checkout_to_booking() {
	if ( is_admin() || wp_doing_ajax() ) {
		return;
	}
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}
	if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
		return;
	}
	if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
		return;
	}
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}
	if ( ! WC()->cart->is_empty() ) {
		return;
	}
	wp_safe_redirect( vila_akoya_booking_page_url() );
	exit;
}
add_action( 'template_redirect', 'vila_akoya_redirect_empty_checkout_to_booking', 5 );

/**
 * WooCommerce only includes a redirect in the AJAX add-to-cart JSON when this option is "yes".
 * Enable it only for campsite products so other products keep the store setting.
 *
 * @param string $value Option value.
 * @return string
 */
function vila_akoya_campsite_option_cart_redirect_after_add( $value ) {
	$pid = vila_akoya_add_to_cart_request_product_id();
	if ( $pid && ( vila_akoya_is_campsite_product_id( $pid ) || ( function_exists( 'vila_akoya_is_stay_product_id' ) && vila_akoya_is_stay_product_id( $pid ) ) ) ) {
		return 'yes';
	}
	if ( null === $value ) {
		return false;
	}
	return $value;
}
add_filter( 'option_woocommerce_cart_redirect_after_add', 'vila_akoya_campsite_option_cart_redirect_after_add', 20 );

/**
 * Checkout address fields guests may leave blank. Email stays required.
 *
 * @return string[] Locale / default-address field keys.
 */
function vila_akoya_checkout_optional_address_field_keys() {
	return array( 'first_name', 'last_name', 'address_1', 'city', 'postcode', 'phone' );
}

/**
 * Mark selected address fields optional (visible, not required).
 *
 * @param array<string, mixed> $fields Address or locale field map.
 * @return array<string, mixed>
 */
function vila_akoya_checkout_mark_address_fields_optional( $fields ) {
	if ( ! is_array( $fields ) ) {
		return $fields;
	}
	foreach ( vila_akoya_checkout_optional_address_field_keys() as $key ) {
		if ( ! isset( $fields[ $key ] ) || ! is_array( $fields[ $key ] ) ) {
			$fields[ $key ] = array();
		}
		$fields[ $key ]['required'] = false;
	}
	return $fields;
}

/**
 * Make billing name, address, and phone optional (classic + block checkout).
 *
 * @param array<string, mixed> $fields Address fields.
 * @return array<string, mixed>
 */
function vila_akoya_checkout_optional_address_fields( $fields ) {
	return vila_akoya_checkout_mark_address_fields_optional( $fields );
}
add_filter( 'woocommerce_default_address_fields', 'vila_akoya_checkout_optional_address_fields', 100 );

/**
 * @param array<string, array<string, mixed>> $fields Checkout fields.
 * @return array<string, array<string, mixed>>
 */
function vila_akoya_checkout_billing_optional_fields( $fields ) {
	if ( ! isset( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
		return $fields;
	}
	foreach ( vila_akoya_checkout_optional_address_field_keys() as $key ) {
		$billing_key = 'billing_' . $key;
		if ( isset( $fields['billing'][ $billing_key ] ) && is_array( $fields['billing'][ $billing_key ] ) ) {
			$fields['billing'][ $billing_key ]['required'] = false;
		}
	}
	return $fields;
}
add_filter( 'woocommerce_checkout_fields', 'vila_akoya_checkout_billing_optional_fields', 100 );

/**
 * @param array<string, array<string, mixed>> $locale Country locale fields.
 * @return array<string, array<string, mixed>>
 */
function vila_akoya_checkout_country_locale_optional_fields( $locale ) {
	if ( ! is_array( $locale ) ) {
		return $locale;
	}
	foreach ( $locale as $country => $fields ) {
		$locale[ $country ] = vila_akoya_checkout_mark_address_fields_optional( is_array( $fields ) ? $fields : array() );
	}
	return $locale;
}
add_filter( 'woocommerce_get_country_locale', 'vila_akoya_checkout_country_locale_optional_fields', 100 );

/**
 * @param array<string, mixed> $locale Default / base locale.
 * @return array<string, mixed>
 */
function vila_akoya_checkout_default_locale_optional_fields( $locale ) {
	return vila_akoya_checkout_mark_address_fields_optional( $locale );
}
add_filter( 'woocommerce_get_country_locale_default', 'vila_akoya_checkout_default_locale_optional_fields', 100 );
add_filter( 'woocommerce_get_country_locale_base', 'vila_akoya_checkout_default_locale_optional_fields', 100 );

/**
 * Phone is stored as a Checkout-block option (required / optional / hidden).
 *
 * @return string
 */
function vila_akoya_checkout_phone_field_optional() {
	return 'optional';
}
add_filter( 'option_woocommerce_checkout_phone_field', 'vila_akoya_checkout_phone_field_optional' );
add_filter( 'default_option_woocommerce_checkout_phone_field', 'vila_akoya_checkout_phone_field_optional' );

/**
 * Block checkout: do not require phone even if the saved block still has requirePhoneField.
 *
 * @param array $parsed_block Parsed block.
 * @param array $source_block Source block.
 * @return array
 */
function vila_akoya_checkout_block_optional_phone( $parsed_block, $source_block ) {
	unset( $source_block );
	if ( empty( $parsed_block['blockName'] ) ) {
		return $parsed_block;
	}
	$names = array(
		'woocommerce/checkout',
		'woocommerce/checkout-fields-block',
		'woocommerce/checkout-contact-information-block',
	);
	if ( ! in_array( $parsed_block['blockName'], $names, true ) ) {
		return $parsed_block;
	}
	if ( ! isset( $parsed_block['attrs'] ) || ! is_array( $parsed_block['attrs'] ) ) {
		$parsed_block['attrs'] = array();
	}
	$parsed_block['attrs']['requirePhoneField'] = false;
	return $parsed_block;
}
add_filter( 'render_block_data', 'vila_akoya_checkout_block_optional_phone', 10, 2 );

/**
 * Whether the checkout page uses the WooCommerce Checkout block.
 *
 * @return bool
 */
function vila_akoya_checkout_uses_blocks() {
	if ( ! function_exists( 'has_block' ) || ! function_exists( 'wc_get_page_id' ) ) {
		return false;
	}
	$page_id = (int) wc_get_page_id( 'checkout' );
	return $page_id > 0 && has_block( 'woocommerce/checkout', $page_id );
}

/**
 * Block checkout: visitor intro (WC additional fields API supports text, not textarea).
 */
function vila_akoya_register_checkout_visitor_intro_field() {
	if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) || ! vila_akoya_checkout_uses_blocks() ) {
		return;
	}

	woocommerce_register_additional_checkout_field(
		array(
			'id'         => 'vila-akoya/visitor-intro',
			'label'      => __( 'About your group', 'vila-akoya' ),
			'location'   => 'contact',
			'type'       => 'text',
			'required'   => false,
			'attributes' => array(
				'maxLength'            => '2000',
				'data-vila-placeholder' => __( 'We would like to know our visitors a little. Please, if you have a moment, do tell us more about us and the people and creatures that belong with you...', 'vila-akoya' ),
			),
		)
	);
}
add_action( 'woocommerce_init', 'vila_akoya_register_checkout_visitor_intro_field' );

/**
 * Block checkout: multiline UI for visitor intro (registered as type text for API compatibility).
 */
function vila_akoya_enqueue_checkout_visitor_intro_script() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! vila_akoya_checkout_uses_blocks() ) {
		return;
	}
	if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
		return;
	}
	wp_enqueue_script(
		'vila-akoya-checkout-visitor-intro',
		get_template_directory_uri() . '/assets/js/checkout-visitor-intro.js',
		array(),
		defined( 'VILA_AKOYA_VERSION' ) ? VILA_AKOYA_VERSION : '1.0.0',
		true
	);
}
add_action( 'wp_enqueue_scripts', 'vila_akoya_enqueue_checkout_visitor_intro_script', 30 );

/**
 * Classic checkout: multiline visitor intro (not used when the Checkout block is active).
 *
 * @param array<string, array<string, mixed>> $fields Checkout fields.
 * @return array<string, array<string, mixed>>
 */
function vila_akoya_checkout_visitor_intro_classic_field( $fields ) {
	if ( vila_akoya_checkout_uses_blocks() ) {
		return $fields;
	}
	$fields['order']['vila_visitor_intro'] = array(
		'type'              => 'textarea',
		'class'             => array( 'form-row-wide', 'vila-checkout-visitor-intro' ),
		'label'             => __( 'About your group', 'vila-akoya' ),
		'description'       => __( 'We would like to know our visitors a little. Please, if you have a moment, do tell us more about you and the people and creatures that belong with you.', 'vila-akoya' ),
		'placeholder'       => __( 'We would like to know our visitors a little. Please, if you have a moment, do tell us more about us and the people and creatures that belong with you...', 'vila-akoya' ),
		'custom_attributes' => array(
			'rows' => '5',
		),
		'required'          => false,
		'priority'          => 120,
	);
	return $fields;
}
add_filter( 'woocommerce_checkout_fields', 'vila_akoya_checkout_visitor_intro_classic_field', 25 );

/**
 * @param int $order_id Order ID.
 */
function vila_akoya_save_checkout_visitor_intro_classic( $order_id ) {
	if ( vila_akoya_checkout_uses_blocks() ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC checkout nonce.
	if ( empty( $_POST['vila_visitor_intro'] ) ) {
		return;
	}
	$text = sanitize_textarea_field( wp_unslash( vila_akoya_scalar_request_string( $_POST['vila_visitor_intro'] ) ) );
	if ( '' === $text ) {
		return;
	}
	update_post_meta( $order_id, '_vila_visitor_intro', $text );
}
add_action( 'woocommerce_checkout_update_order_meta', 'vila_akoya_save_checkout_visitor_intro_classic' );

/**
 * Mirror block-checkout visitor intro into a stable order meta key for emails/admin.
 *
 * @param int $order_id Order ID.
 */
function vila_akoya_sync_checkout_visitor_intro_meta( $order_id ) {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$text = (string) $order->get_meta( 'vila-akoya/visitor-intro' );
	if ( '' === trim( $text ) ) {
		return;
	}
	$order->update_meta_data( '_vila_visitor_intro', $text );
	$order->save();
}
add_action( 'woocommerce_checkout_update_order_meta', 'vila_akoya_sync_checkout_visitor_intro_meta', 30 );

/**
 * Show visitor intro in admin and customer order views.
 *
 * @param WC_Order $order Order.
 */
function vila_akoya_display_visitor_intro_in_admin( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$text = (string) $order->get_meta( '_vila_visitor_intro' );
	if ( '' === $text && function_exists( 'wc_get_order' ) ) {
		$text = (string) $order->get_meta( 'vila-akoya/visitor-intro' );
	}
	if ( '' === trim( $text ) ) {
		return;
	}
	echo '<p><strong>' . esc_html__( 'About your group', 'vila-akoya' ) . '</strong></p>';
	echo '<p style="white-space:pre-wrap;">' . esc_html( $text ) . '</p>';
}
add_action( 'woocommerce_admin_order_data_after_billing_address', 'vila_akoya_display_visitor_intro_in_admin', 15 );

/**
 * @param WC_Order $order Order.
 */
function vila_akoya_email_visitor_intro( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$text = (string) $order->get_meta( '_vila_visitor_intro' );
	if ( '' === $text ) {
		$text = (string) $order->get_meta( 'vila-akoya/visitor-intro' );
	}
	if ( '' === trim( $text ) ) {
		return;
	}
	echo '<h2>' . esc_html__( 'About your group', 'vila-akoya' ) . '</h2>';
	echo '<p style="white-space:pre-wrap;">' . esc_html( $text ) . '</p>';
}
add_action( 'woocommerce_email_after_order_table', 'vila_akoya_email_visitor_intro', 15, 1 );

/**
 * Description under the block-checkout visitor intro field.
 *
 * @param string $html       Field HTML.
 * @param string $field_id   Field id.
 * @param array  $field_data Field config.
 * @return string
 */
function vila_akoya_checkout_visitor_intro_field_html( $html, $field_id, $field_data ) {
	unset( $field_data );
	if ( 'vila-akoya/visitor-intro' !== $field_id ) {
		return $html;
	}
	$intro = '<p class="vila-checkout-visitor-intro__lead">' . esc_html__(
		'We would like to know our visitors a little. Please, if you have a moment, do tell us more about you and the people and creatures that belong with you.',
		'vila-akoya'
	) . '</p>';
	return $intro . $html;
}
add_filter( 'woocommerce_additional_checkout_field_html', 'vila_akoya_checkout_visitor_intro_field_html', 10, 3 );

/**
 * Classic checkout: lead copy above the visitor intro textarea.
 */
function vila_akoya_checkout_visitor_intro_classic_description() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || vila_akoya_checkout_uses_blocks() ) {
		return;
	}
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	echo '<p class="vila-checkout-visitor-intro__lead form-row form-row-wide">';
	echo esc_html__(
		'We would like to know our visitors a little. Please, if you have a moment, do tell us more about you and the people and creatures that belong with you.',
		'vila-akoya'
	);
	echo '</p>';
}
add_action( 'woocommerce_before_order_notes', 'vila_akoya_checkout_visitor_intro_classic_description', 5 );
