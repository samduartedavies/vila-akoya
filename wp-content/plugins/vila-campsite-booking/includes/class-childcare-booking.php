<?php
/**
 * Guest-facing childcare booking: hidden WooCommerce product and paid roster rows.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Paid childcare checkout flow.
 */
class Childcare_Booking {

	const OPTION_PRODUCT_ID = 'vila_cb_childcare_product_id';
	const PRODUCT_SKU       = 'vila-akoya-childcare';
	const META_PURCHASE     = '_vila_childcare_purchase';
	const CART_KEY          = 'vila_childcare_purchase';

	/**
	 * Boot hooks.
	 */
	public static function init() {
		add_action( 'wp_loaded', array( __CLASS__, 'ensure_product' ), 31 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 16, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 21, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'get_cart_item_from_session' ), 21, 3 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_cart_capacity' ), 20 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'before_calculate_totals' ), 100011, 1 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_cart_item_data' ), 21, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'checkout_save_line_item' ), 31, 4 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_order_paid' ), 21, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order_paid' ), 21, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_paid' ), 21, 1 );
		add_action( 'woocommerce_order_status_on-hold', array( __CLASS__, 'on_order_paid' ), 21, 1 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_order_itemmeta' ), 21, 1 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'force_purchasable' ), 11, 2 );
		add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'redirect_to_checkout' ), 101, 1 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'redirect_after_childcare_added_to_cart' ), 21, 6 );
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_redirect_childcare_booking_to_checkout' ), 26 );
		add_filter( 'woocommerce_cart_redirect_after_error', array( __CLASS__, 'redirect_childcare_errors_to_booking_page' ), 11, 1 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_public_childcare_product_page' ), 5 );
	}

	/**
	 * @return int Product ID.
	 */
	public static function get_product_id() {
		$pid = (int) get_option( self::OPTION_PRODUCT_ID, 0 );
		if ( $pid > 0 && function_exists( 'wc_get_product' ) ) {
			$p = wc_get_product( $pid );
			if ( $p ) {
				return $pid;
			}
		}
		if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			$by_sku = (int) wc_get_product_id_by_sku( self::PRODUCT_SKU );
			if ( $by_sku > 0 ) {
				update_option( self::OPTION_PRODUCT_ID, $by_sku );
				return $by_sku;
			}
		}
		return 0;
	}

	/**
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function is_childcare_product( $product_id ) {
		$product_id = (int) $product_id;
		$known      = self::get_product_id();
		return $product_id > 0 && $known > 0 && $product_id === $known;
	}

	/**
	 * @return int
	 */
	public static function ensure_product() {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Product_Simple' ) ) {
			return 0;
		}
		$existing = self::get_product_id();
		if ( $existing > 0 ) {
			self::sync_product_settings( $existing );
			return $existing;
		}

		$product = new \WC_Product_Simple();
		$product->set_name( __( 'Childcare booking', 'vila-campsite-booking' ) );
		$product->set_slug( 'childcare-booking' );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_price( 0 );
		$product->set_regular_price( 0 );
		$product->set_sku( self::PRODUCT_SKU );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_manage_stock( false );
		$product->set_reviews_allowed( false );
		$id = $product->save();
		if ( $id > 0 ) {
			update_option( self::OPTION_PRODUCT_ID, $id );
		}
		return (int) $id;
	}

	/**
	 * @param int $product_id Product ID.
	 */
	private static function sync_product_settings( $product_id ) {
		$product = wc_get_product( (int) $product_id );
		if ( ! $product ) {
			return;
		}
		$changed = false;
		if ( 'hidden' !== $product->get_catalog_visibility() ) {
			$product->set_catalog_visibility( 'hidden' );
			$changed = true;
		}
		if ( (float) $product->get_price() !== 0.0 ) {
			$product->set_price( 0 );
			$changed = true;
		}
		if ( (float) $product->get_regular_price() !== 0.0 ) {
			$product->set_regular_price( 0 );
			$changed = true;
		}
		if ( $changed ) {
			$product->save();
		}
	}

	/**
	 * Redirect direct product page views to /book-childcare/.
	 */
	public static function redirect_public_childcare_product_page() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}
		if ( ! self::is_childcare_product( (int) get_queried_object_id() ) ) {
			return;
		}
		$page_id = Install::get_childcare_booking_page_id();
		if ( $page_id > 0 ) {
			wp_safe_redirect( get_permalink( $page_id ) );
			exit;
		}
	}

	/**
	 * @param bool        $purchasable Purchasable.
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	public static function force_purchasable( $purchasable, $product ) {
		if ( $product instanceof \WC_Product && self::is_childcare_product( (int) $product->get_id() ) ) {
			return true;
		}
		return $purchasable;
	}

	/**
	 * @param string $url URL.
	 * @return string
	 */
	public static function redirect_to_checkout( $url ) {
		if ( self::is_childcare_booking_post_request() ) {
			return function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : $url;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $url;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( self::is_childcare_product( $pid ) && ! empty( $item[ self::CART_KEY ] ) ) {
				return function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : $url;
			}
		}
		return $url;
	}

	/**
	 * @param string               $cart_item_key Cart item key.
	 * @param int                  $product_id Product ID.
	 * @param int                  $quantity Quantity.
	 * @param int                  $variation_id Variation ID.
	 * @param array<string, mixed> $variation Variation.
	 * @param array<string, mixed> $cart_item_data Cart data.
	 */
	public static function redirect_after_childcare_added_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0, $variation = array(), $cart_item_data = array() ) {
		unset( $quantity, $variation_id, $variation );
		if ( ! self::is_childcare_product( (int) $product_id ) || ! self::is_childcare_booking_post_request() ) {
			return;
		}
		$purchase = self::resolve_cart_purchase_payload( $cart_item_key, $cart_item_data );
		if ( ! $purchase ) {
			if ( function_exists( 'WC' ) && WC()->cart && $cart_item_key ) {
				WC()->cart->remove_cart_item( $cart_item_key );
			}
			wc_add_notice( __( 'Childcare booking could not be added. Please select dates and try again.', 'vila-campsite-booking' ), 'error' );
			return;
		}
		self::redirect_to_checkout_now();
	}

	/**
	 * Fallback after WooCommerce add-to-cart processing.
	 */
	public static function maybe_redirect_childcare_booking_to_checkout() {
		if ( ! self::is_childcare_booking_post_request() || ! isset( $_REQUEST['add-to-cart'] ) ) {
			return;
		}
		$product_id = absint( wp_unslash( Request_String::scalar( $_REQUEST['add-to-cart'] ) ) );
		if ( self::is_childcare_product( $product_id ) && self::cart_has_valid_childcare_line() ) {
			self::redirect_to_checkout_now();
		}
	}

	/**
	 * @param string $url URL.
	 * @return string
	 */
	public static function redirect_childcare_errors_to_booking_page( $url ) {
		if ( ! self::is_childcare_booking_post_request() ) {
			return $url;
		}
		$page_id = Install::get_childcare_booking_page_id();
		if ( $page_id > 0 ) {
			$childcare_url = get_permalink( $page_id );
			if ( is_string( $childcare_url ) && '' !== $childcare_url ) {
				return $childcare_url;
			}
		}
		return $url;
	}

	/**
	 * @param string               $cart_item_key Cart item key.
	 * @param array<string, mixed> $cart_item_data Cart item data.
	 * @return array<string, mixed>|null
	 */
	private static function resolve_cart_purchase_payload( $cart_item_key, $cart_item_data ) {
		if ( is_array( $cart_item_data ) && ! empty( $cart_item_data[ self::CART_KEY ] ) && is_array( $cart_item_data[ self::CART_KEY ] ) ) {
			return $cart_item_data[ self::CART_KEY ];
		}
		if ( function_exists( 'WC' ) && WC()->cart && is_string( $cart_item_key ) && '' !== $cart_item_key ) {
			$line = WC()->cart->get_cart_item( $cart_item_key );
			if ( is_array( $line ) && ! empty( $line[ self::CART_KEY ] ) && is_array( $line[ self::CART_KEY ] ) ) {
				return $line[ self::CART_KEY ];
			}
		}
		return null;
	}

	/**
	 * @return bool
	 */
	private static function cart_has_valid_childcare_line() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( self::is_childcare_product( $pid ) && ! empty( $item[ self::CART_KEY ] ) && is_array( $item[ self::CART_KEY ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Redirect to checkout.
	 */
	private static function redirect_to_checkout_now() {
		if ( ! function_exists( 'wc_get_checkout_url' ) ) {
			return;
		}
		$checkout = wc_get_checkout_url();
		if ( is_string( $checkout ) && '' !== $checkout ) {
			wp_safe_redirect( $checkout );
			exit;
		}
	}

	/**
	 * @return bool
	 */
	private static function is_childcare_booking_post_request() {
		if ( ! isset( $_POST['vila_childcare_booking_nonce'] ) ) {
			return false;
		}
		return (bool) wp_verify_nonce(
			sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_childcare_booking_nonce'] ) ) ),
			'vila_childcare_booking'
		);
	}

	/**
	 * @param array<string, mixed> $raw Raw data.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function normalize_purchase( array $raw ) {
		$parent_name = isset( $raw['parent_name'] ) ? sanitize_text_field( (string) $raw['parent_name'] ) : '';
		$phone       = isset( $raw['parent_phone'] ) ? sanitize_text_field( (string) $raw['parent_phone'] ) : '';
		$telegram    = isset( $raw['telegram_username'] ) ? Childcare_Service::normalize_telegram_username( (string) $raw['telegram_username'] ) : '';
		$dates       = Childcare_Service::sanitize_ymd_list( isset( $raw['dates'] ) ? $raw['dates'] : array() );

		if ( '' === $parent_name ) {
			return new \WP_Error( 'childcare_parent_name', __( 'Please enter the parent name.', 'vila-campsite-booking' ) );
		}
		if ( '' === $phone ) {
			return new \WP_Error( 'childcare_parent_phone', __( 'Please enter a phone number.', 'vila-campsite-booking' ) );
		}

		$children = array();
		foreach ( (array) ( $raw['children'] ?? array() ) as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$name = sanitize_text_field( (string) ( $child['name'] ?? '' ) );
			$age  = isset( $child['age'] ) ? max( 0, (int) $child['age'] ) : 0;
			if ( '' === $name ) {
				continue;
			}
			$children[] = array(
				'name'      => $name,
				'age'       => $age,
				'allergies' => sanitize_textarea_field( (string) ( $child['allergies'] ?? '' ) ),
			);
		}
		if ( empty( $children ) ) {
			return new \WP_Error( 'childcare_children', __( 'Add at least one child.', 'vila-campsite-booking' ) );
		}

		$available = Childcare_Service::validate_dates_available( $dates, count( $children ) );
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		return array(
			'parent_name'       => $parent_name,
			'parent_phone'      => $phone,
			'telegram_username' => $telegram,
			'children'          => $children,
			'dates'             => $dates,
		);
	}

	/**
	 * @param array<string, mixed> $purchase Purchase.
	 * @return float
	 */
	public static function calculate_total( array $purchase ) {
		$settings = Childcare_Service::settings();
		$fee      = isset( $settings['fee_per_child_day'] ) ? (float) $settings['fee_per_child_day'] : 15.0;
		$children = isset( $purchase['children'] ) && is_array( $purchase['children'] ) ? count( $purchase['children'] ) : 0;
		$dates    = isset( $purchase['dates'] ) && is_array( $purchase['dates'] ) ? count( $purchase['dates'] ) : 0;
		return round( $fee * $children * $dates, 2 );
	}

	/**
	 * @param bool $passed Passed.
	 * @param int  $product_id Product ID.
	 * @param int  $quantity Quantity.
	 * @return bool
	 */
	public static function validate_add_to_cart( $passed, $product_id, $quantity ) {
		unset( $quantity );
		if ( ! $passed || ! self::is_childcare_product( (int) $product_id ) ) {
			return $passed;
		}
		if ( ! self::is_childcare_booking_post_request() ) {
			wc_add_notice( __( 'Please book childcare using the form on the Book childcare page.', 'vila-campsite-booking' ), 'error' );
			return false;
		}
		self::remove_childcare_items_from_cart();
		$norm = self::normalize_purchase( self::purchase_from_request() );
		if ( is_wp_error( $norm ) ) {
			wc_add_notice( $norm->get_error_message(), 'error' );
			return false;
		}
		if ( self::calculate_total( $norm ) <= 0 ) {
			wc_add_notice( __( 'Childcare total must be greater than zero.', 'vila-campsite-booking' ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Re-check capacity before checkout / payment.
	 */
	public static function validate_cart_capacity() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( empty( $item[ self::CART_KEY ] ) || ! is_array( $item[ self::CART_KEY ] ) ) {
				continue;
			}
			$p      = $item[ self::CART_KEY ];
			$needed = isset( $p['children'] ) && is_array( $p['children'] ) ? count( $p['children'] ) : 1;
			$dates  = isset( $p['dates'] ) && is_array( $p['dates'] ) ? $p['dates'] : array();
			$ok     = Childcare_Service::validate_dates_available( $dates, $needed );
			if ( is_wp_error( $ok ) ) {
				wc_add_notice( $ok->get_error_message(), 'error' );
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function purchase_from_request() {
		$dates = isset( $_POST['vila_childcare_dates'] ) ? wp_unslash( $_POST['vila_childcare_dates'] ) : array();
		$names = isset( $_POST['vila_childcare_child_name'] ) ? wp_unslash( $_POST['vila_childcare_child_name'] ) : array();
		$ages  = isset( $_POST['vila_childcare_child_age'] ) ? wp_unslash( $_POST['vila_childcare_child_age'] ) : array();
		$meds  = isset( $_POST['vila_childcare_child_allergies'] ) ? wp_unslash( $_POST['vila_childcare_child_allergies'] ) : array();

		$children = array();
		$names    = is_array( $names ) ? $names : array();
		$ages     = is_array( $ages ) ? $ages : array();
		$meds     = is_array( $meds ) ? $meds : array();
		foreach ( $names as $idx => $name ) {
			$children[] = array(
				'name'      => Request_String::scalar( $name ),
				'age'       => isset( $ages[ $idx ] ) ? Request_String::scalar( $ages[ $idx ] ) : 0,
				'allergies' => isset( $meds[ $idx ] ) ? Request_String::scalar( $meds[ $idx ] ) : '',
			);
		}

		return array(
			'parent_name'       => isset( $_POST['vila_childcare_parent_name'] ) ? wp_unslash( Request_String::scalar( $_POST['vila_childcare_parent_name'] ) ) : '',
			'parent_phone'      => isset( $_POST['vila_childcare_parent_phone'] ) ? wp_unslash( Request_String::scalar( $_POST['vila_childcare_parent_phone'] ) ) : '',
			'telegram_username' => isset( $_POST['vila_childcare_telegram'] ) ? wp_unslash( Request_String::scalar( $_POST['vila_childcare_telegram'] ) ) : '',
			'children'          => $children,
			'dates'             => $dates,
		);
	}

	/**
	 * @param array<string, mixed> $cart_item_data Cart data.
	 * @param int                  $product_id Product ID.
	 * @param int                  $variation_id Variation.
	 * @return array<string, mixed>
	 */
	public static function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		unset( $variation_id );
		if ( ! self::is_childcare_product( (int) $product_id ) || ! self::is_childcare_booking_post_request() ) {
			return $cart_item_data;
		}
		$norm = self::normalize_purchase( self::purchase_from_request() );
		if ( is_wp_error( $norm ) ) {
			return $cart_item_data;
		}
		$cart_item_data[ self::CART_KEY ] = $norm;
		$cart_item_data['unique_key'] = self::CART_KEY;
		return $cart_item_data;
	}

	/**
	 * Drop existing childcare cart lines before adding a new one.
	 */
	private static function remove_childcare_items_from_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $cart_key => $item ) {
			$pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( self::is_childcare_product( $pid ) ) {
				WC()->cart->remove_cart_item( $cart_key );
			}
		}
	}

	/**
	 * @param array<string, mixed> $cart_item Cart item.
	 * @param array<string, mixed> $values Session values.
	 * @param string               $key Cart key.
	 * @return array<string, mixed>
	 */
	public static function get_cart_item_from_session( $cart_item, $values, $key ) {
		unset( $key );
		if ( isset( $values[ self::CART_KEY ] ) && is_array( $values[ self::CART_KEY ] ) ) {
			$cart_item[ self::CART_KEY ] = $values[ self::CART_KEY ];
		}
		return $cart_item;
	}

	/**
	 * @param \WC_Cart $cart Cart.
	 */
	public static function before_calculate_totals( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! $cart || $cart->is_empty() ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item[ self::CART_KEY ] ) || ! is_array( $item[ self::CART_KEY ] ) ) {
				continue;
			}
			if ( empty( $item['data'] ) || ! is_object( $item['data'] ) ) {
				continue;
			}
			$item['data']->set_price( self::calculate_total( $item[ self::CART_KEY ] ) );
		}
	}

	/**
	 * @param array<string, mixed> $item_data Item data.
	 * @param array<string, mixed> $cart_item Cart item.
	 * @return array<string, mixed>
	 */
	public static function display_cart_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item[ self::CART_KEY ] ) || ! is_array( $cart_item[ self::CART_KEY ] ) ) {
			return $item_data;
		}
		$p        = $cart_item[ self::CART_KEY ];
		$children = self::children_summary( isset( $p['children'] ) ? (array) $p['children'] : array() );
		$item_data[] = array(
			'name'    => __( 'Parent', 'vila-campsite-booking' ),
			'display' => esc_html( (string) $p['parent_name'] ),
		);
		$item_data[] = array(
			'name'    => __( 'Children', 'vila-campsite-booking' ),
			'display' => esc_html( $children ),
		);
		$item_data[] = array(
			'name'    => __( 'Childcare dates', 'vila-campsite-booking' ),
			'display' => esc_html( implode( ', ', (array) $p['dates'] ) ),
		);
		return $item_data;
	}

	/**
	 * @param \WC_Order_Item_Product $item Item.
	 * @param string                 $cart_key Cart key.
	 * @param array<string, mixed>   $values Values.
	 * @param \WC_Order              $order Order.
	 */
	public static function checkout_save_line_item( $item, $cart_key, $values, $order ) {
		unset( $cart_key, $order );
		if ( empty( $values[ self::CART_KEY ] ) || ! is_array( $values[ self::CART_KEY ] ) ) {
			return;
		}
		$p = $values[ self::CART_KEY ];
		$item->update_meta_data( self::META_PURCHASE, wp_json_encode( $p ) );
		$item->update_meta_data( __( 'Childcare parent', 'vila-campsite-booking' ), (string) $p['parent_name'] );
		$item->update_meta_data( __( 'Childcare phone', 'vila-campsite-booking' ), (string) $p['parent_phone'] );
		$item->update_meta_data( __( 'Childcare children', 'vila-campsite-booking' ), self::children_summary( isset( $p['children'] ) ? (array) $p['children'] : array() ) );
		$item->update_meta_data( __( 'Childcare dates', 'vila-campsite-booking' ), implode( ', ', (array) $p['dates'] ) );
	}

	/**
	 * @param string[] $hidden Hidden keys.
	 * @return string[]
	 */
	public static function hidden_order_itemmeta( $hidden ) {
		if ( ! is_array( $hidden ) ) {
			$hidden = array();
		}
		$hidden[] = self::META_PURCHASE;
		return array_values( array_unique( array_map( 'strval', $hidden ) ) );
	}

	/**
	 * @param int $order_id Order ID.
	 */
	public static function on_order_paid( $order_id ) {
		$order_id = (int) $order_id;
		if ( $order_id < 1 || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( 'yes' === $order->get_meta( '_vila_childcare_purchase_merged', true ) ) {
			return;
		}

		$touched = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! is_callable( array( $item, 'get_meta' ) ) ) {
				continue;
			}
			$raw = $item->get_meta( self::META_PURCHASE, true );
			$p   = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
			if ( ! is_array( $p ) ) {
				continue;
			}
			$line_id = is_callable( array( $item, 'get_id' ) ) ? (int) $item->get_id() : 0;
			$touched = array_merge(
				$touched,
				Childcare_Service::insert_paid_purchase( $order_id, $line_id, $p, (string) $order->get_billing_email() )
			);
		}

		$touched = array_values( array_unique( $touched ) );
		if ( ! empty( $touched ) ) {
			$order->update_meta_data( '_vila_childcare_purchase_merged', 'yes' );
			$order->save();
			do_action( 'vila_cb_childcare_changed', $touched );
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $children Children.
	 * @return string
	 */
	private static function children_summary( array $children ) {
		$lines = array();
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$name = isset( $child['name'] ) ? (string) $child['name'] : '';
			$age  = isset( $child['age'] ) ? (int) $child['age'] : 0;
			if ( '' !== $name ) {
				$lines[] = sprintf( '%s (%d)', $name, $age );
			}
		}
		return implode( ', ', $lines );
	}
}
