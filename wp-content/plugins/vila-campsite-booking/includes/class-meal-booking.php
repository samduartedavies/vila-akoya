<?php
/**
 * Guest-facing meal booking: dedicated WC product, cart pricing, merge into stays.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Standalone / post-stay meal purchases paid via WooCommerce checkout.
 */
class Meal_Booking {

	const OPTION_PRODUCT_ID = 'vila_cb_meals_product_id';
	const PRODUCT_SKU       = 'vila-akoya-meals';
	const META_PURCHASE     = '_vila_meal_purchase';
	const CART_KEY          = 'vila_meal_purchase';

	/**
	 * Boot hooks.
	 */
	public static function init() {
		add_action( 'wp_loaded', array( __CLASS__, 'ensure_product' ), 30 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 15, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 20, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'get_cart_item_from_session' ), 20, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'before_calculate_totals' ), 100010, 1 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_cart_item_data' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'checkout_save_line_item' ), 30, 4 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_order_paid' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order_paid' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_paid' ), 20, 1 );
		add_action( 'woocommerce_order_status_on-hold', array( __CLASS__, 'on_order_paid' ), 20, 1 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_order_itemmeta' ), 20, 1 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'force_purchasable' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'redirect_to_checkout' ), 100, 1 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'redirect_after_meal_added_to_cart' ), 20, 6 );
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_redirect_meal_booking_to_checkout' ), 25 );
		add_filter( 'woocommerce_cart_redirect_after_error', array( __CLASS__, 'redirect_meal_errors_to_booking_page' ), 10, 1 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_public_meals_product_page' ), 5 );
	}

	/**
	 * @return int Meals product ID (0 if unavailable).
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
	public static function is_meals_product( $product_id ) {
		$product_id = (int) $product_id;
		if ( $product_id < 1 ) {
			return false;
		}
		$known = self::get_product_id();
		return $known > 0 && $product_id === $known;
	}

	/**
	 * Seed a simple (catalog-hidden) product used only for meal checkout.
	 *
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
		$product->set_name( __( 'Campsite meals', 'vila-campsite-booking' ) );
		$product->set_slug( 'campsite-meals' );
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
	 * Keep the hidden meals checkout product out of the shop and at price 0.
	 *
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
	 * Guests should use /book-meals/, not the internal WC product page.
	 */
	public static function redirect_public_meals_product_page() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}
		$product_id = (int) get_queried_object_id();
		if ( ! self::is_meals_product( $product_id ) ) {
			return;
		}
		$page_id = Install::get_meal_booking_page_id();
		if ( $page_id > 0 ) {
			wp_safe_redirect( get_permalink( $page_id ) );
			exit;
		}
	}

	/**
	 * Keep the hidden meals product buyable even at price 0.
	 *
	 * @param bool        $purchasable Purchasable.
	 * @param \WC_Product $product     Product.
	 * @return bool
	 */
	public static function force_purchasable( $purchasable, $product ) {
		if ( $product instanceof \WC_Product && self::is_meals_product( (int) $product->get_id() ) ) {
			return true;
		}
		return $purchasable;
	}

	/**
	 * After adding meals, go straight to checkout.
	 *
	 * @param string $url Redirect URL.
	 * @return string
	 */
	public static function redirect_to_checkout( $url ) {
		if ( self::is_meal_booking_post_request() ) {
			return function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : $url;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $url;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( self::is_meals_product( $pid ) && ! empty( $item[ self::CART_KEY ] ) ) {
				return function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : $url;
			}
		}
		return $url;
	}

	/**
	 * Immediately send meal bookings to checkout once the cart line is created.
	 *
	 * @param string               $cart_item_key  Cart item key.
	 * @param int                  $product_id     Product ID.
	 * @param int                  $quantity       Quantity.
	 * @param int                  $variation_id   Variation ID.
	 * @param array<string, mixed> $variation      Variation data.
	 * @param array<string, mixed> $cart_item_data Cart item data.
	 */
	public static function redirect_after_meal_added_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0, $variation = array(), $cart_item_data = array() ) {
		unset( $quantity, $variation_id, $variation );
		if ( ! self::is_meals_product( (int) $product_id ) || ! self::is_meal_booking_post_request() ) {
			return;
		}
		$purchase = self::resolve_cart_purchase_payload( $cart_item_key, $cart_item_data );
		if ( ! $purchase ) {
			if ( function_exists( 'WC' ) && WC()->cart && $cart_item_key ) {
				WC()->cart->remove_cart_item( $cart_item_key );
			}
			wc_add_notice( __( 'Meal booking could not be added. Please select dates and try again.', 'vila-campsite-booking' ), 'error' );
			return;
		}
		self::redirect_to_checkout_now();
	}

	/**
	 * Fallback after WC_Form_Handler::add_to_cart_action (wp_loaded priority 20).
	 */
	public static function maybe_redirect_meal_booking_to_checkout() {
		if ( ! self::is_meal_booking_post_request() ) {
			return;
		}
		if ( ! isset( $_REQUEST['add-to-cart'] ) ) {
			return;
		}
		$product_id = absint( wp_unslash( Request_String::scalar( $_REQUEST['add-to-cart'] ) ) );
		if ( ! self::is_meals_product( $product_id ) ) {
			return;
		}
		if ( self::cart_has_valid_meal_line() ) {
			self::redirect_to_checkout_now();
		}
	}

	/**
	 * Failed meal add-to-cart should return to /book-meals/, not the hidden product page.
	 *
	 * @param string $url Redirect URL.
	 * @return string
	 */
	public static function redirect_meal_errors_to_booking_page( $url ) {
		if ( ! self::is_meal_booking_post_request() ) {
			return $url;
		}
		$page_id = Install::get_meal_booking_page_id();
		if ( $page_id > 0 ) {
			$meal_url = get_permalink( $page_id );
			if ( is_string( $meal_url ) && '' !== $meal_url ) {
				return $meal_url;
			}
		}
		return $url;
	}

	/**
	 * @param string               $cart_item_key  Cart item key.
	 * @param array<string, mixed> $cart_item_data Data passed to the add-to-cart hook (may be empty).
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
	private static function cart_has_valid_meal_line() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( self::is_meals_product( $pid ) && ! empty( $item[ self::CART_KEY ] ) && is_array( $item[ self::CART_KEY ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Send the browser to checkout (no-op if checkout URL unavailable).
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
	private static function is_meal_booking_post_request() {
		if ( ! isset( $_POST['vila_meal_booking_nonce'] ) ) {
			return false;
		}
		return (bool) wp_verify_nonce(
			sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_meal_booking_nonce'] ) ) ),
			'vila_meal_booking'
		);
	}

	/**
	 * Normalize posted / stored meal purchase payload.
	 *
	 * @param array<string, mixed> $raw Raw data.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function normalize_purchase( array $raw ) {
		$name  = isset( $raw['booker_name'] ) ? sanitize_text_field( (string) $raw['booker_name'] ) : '';
		$email = isset( $raw['contact_email'] ) ? sanitize_email( (string) $raw['contact_email'] ) : '';
		$adults   = isset( $raw['adults'] ) ? max( 0, (int) $raw['adults'] ) : 0;
		$children = isset( $raw['children'] ) ? max( 0, (int) $raw['children'] ) : 0;
		$infants  = isset( $raw['infants'] ) ? max( 0, (int) $raw['infants'] ) : 0;
		if ( $adults + $children < 1 ) {
			return new \WP_Error( 'meal_guests', __( 'Add at least one adult or child for meals.', 'vila-campsite-booking' ) );
		}
		if ( '' === $name ) {
			return new \WP_Error( 'meal_name', __( 'Please enter a name for the meal booking.', 'vila-campsite-booking' ) );
		}

		$breakfast = self::sanitize_ymd_list( isset( $raw['breakfast_dates'] ) ? $raw['breakfast_dates'] : array() );
		$dinner    = self::sanitize_ymd_list( isset( $raw['dinner_dates'] ) ? $raw['dinner_dates'] : array() );
		// Dinner only Mon–Fri.
		$dinner = array_values(
			array_filter(
				$dinner,
				static function ( $ymd ) {
					return Meal_Plan_Service::is_weekday( $ymd );
				}
			)
		);
		if ( empty( $breakfast ) && empty( $dinner ) ) {
			return new \WP_Error( 'meal_dates', __( 'Select at least one breakfast or dinner date.', 'vila-campsite-booking' ) );
		}

		$booking_id = isset( $raw['booking_id'] ) ? max( 0, (int) $raw['booking_id'] ) : 0;
		$order_id   = isset( $raw['order_id'] ) ? max( 0, (int) $raw['order_id'] ) : 0;

		if ( $booking_id > 0 ) {
			$repo = new Booking_Repository();
			$row  = $repo->get( $booking_id );
			if ( ! $row || 'confirmed' !== (string) $row['status'] ) {
				return new \WP_Error( 'meal_booking', __( 'That campsite booking could not be found.', 'vila-campsite-booking' ) );
			}
			$order_id = (int) $row['order_id'];
			if ( '' !== $email && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $order_id );
				if ( $order instanceof \WC_Order ) {
					$bill = strtolower( (string) $order->get_billing_email() );
					if ( $bill && strtolower( $email ) !== $bill ) {
						return new \WP_Error( 'meal_booking_email', __( 'The email does not match that booking.', 'vila-campsite-booking' ) );
					}
				}
			}
		}

		$slugs = array();
		if ( ! empty( $breakfast ) ) {
			$slugs[] = 'breakfast';
		}
		if ( ! empty( $dinner ) ) {
			$slugs[] = 'dinner';
		}

		return array(
			'booking_id'       => $booking_id,
			'order_id'         => $order_id,
			'booker_name'      => $name,
			'contact_email'    => $email,
			'adults'           => $adults,
			'children'         => $children,
			'infants'          => $infants,
			'breakfast_dates'  => $breakfast,
			'dinner_dates'     => $dinner,
			'meal_options'     => $slugs,
		);
	}

	/**
	 * @param mixed $raw List of dates.
	 * @return string[]
	 */
	public static function sanitize_ymd_list( $raw ) {
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : preg_split( '/[\s,]+/', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $v ) {
			$ymd = sanitize_text_field( (string) $v );
			if ( Dates::parse_ymd( $ymd ) ) {
				$out[] = $ymd;
			}
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}

	/**
	 * Price for a normalized purchase.
	 *
	 * @param array<string, mixed> $purchase Purchase.
	 * @return float
	 */
	public static function calculate_total( array $purchase ) {
		$catalog = function_exists( 'vila_akoya_get_meal_options' )
			? vila_akoya_get_meal_options()
			: array();
		$adults   = isset( $purchase['adults'] ) ? (int) $purchase['adults'] : 0;
		$children = isset( $purchase['children'] ) ? (int) $purchase['children'] : 0;
		$infants  = isset( $purchase['infants'] ) ? (int) $purchase['infants'] : 0;
		$total    = 0.0;

		if ( ! empty( $purchase['breakfast_dates'] ) && isset( $catalog['breakfast'] ) ) {
			$days   = count( (array) $purchase['breakfast_dates'] );
			$total += vila_akoya_meal_line_total( $catalog['breakfast'], $adults, $children, $infants, $days );
		} elseif ( ! empty( $purchase['breakfast_dates'] ) ) {
			$days   = count( (array) $purchase['breakfast_dates'] );
			$cfg    = Pricing_Settings::get();
			$adult  = isset( $cfg['meal_fees']['breakfast']['adult'] ) ? (float) $cfg['meal_fees']['breakfast']['adult'] : 5.0;
			$child  = isset( $cfg['meal_fees']['breakfast']['child'] ) ? (float) $cfg['meal_fees']['breakfast']['child'] : 3.0;
			$total += ( $adults * $adult + $children * $child ) * $days;
		}

		if ( ! empty( $purchase['dinner_dates'] ) && isset( $catalog['dinner'] ) ) {
			$days   = count( (array) $purchase['dinner_dates'] );
			$total += vila_akoya_meal_line_total( $catalog['dinner'], $adults, $children, $infants, $days );
		} elseif ( ! empty( $purchase['dinner_dates'] ) ) {
			$days   = count( (array) $purchase['dinner_dates'] );
			$cfg    = Pricing_Settings::get();
			$adult  = isset( $cfg['meal_fees']['dinner']['adult'] ) ? (float) $cfg['meal_fees']['dinner']['adult'] : 10.0;
			$child  = isset( $cfg['meal_fees']['dinner']['child'] ) ? (float) $cfg['meal_fees']['dinner']['child'] : 7.5;
			$total += ( $adults * $adult + $children * $child ) * $days;
		}

		return round( $total, 2 );
	}

	/**
	 * @param bool $passed     Passed.
	 * @param int  $product_id Product ID.
	 * @param int  $quantity   Qty.
	 * @return bool
	 */
	public static function validate_add_to_cart( $passed, $product_id, $quantity ) {
		unset( $quantity );
		if ( ! $passed || ! self::is_meals_product( (int) $product_id ) ) {
			return $passed;
		}
		if ( function_exists( 'vila_akoya_campsite_meals_enabled' ) && ! vila_akoya_campsite_meals_enabled() ) {
			wc_add_notice(
				__( 'Meal booking is not available at the moment.', 'vila-campsite-booking' ),
				'error'
			);
			return false;
		}
		// Block the bare hidden product page "Add to cart" (shop managers can still open the product URL).
		if ( ! self::is_meal_booking_post_request() ) {
			wc_add_notice(
				__( 'Please book meals using the form on the Book meals page.', 'vila-campsite-booking' ),
				'error'
			);
			return false;
		}
		self::remove_meal_items_from_cart();
		$raw = self::purchase_from_request();
		$norm = self::normalize_purchase( $raw );
		if ( is_wp_error( $norm ) ) {
			wc_add_notice( $norm->get_error_message(), 'error' );
			return false;
		}
		if ( self::calculate_total( $norm ) <= 0 ) {
			wc_add_notice( __( 'Meal total must be greater than zero.', 'vila-campsite-booking' ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function purchase_from_request() {
		$breakfast = isset( $_POST['vila_meal_breakfast_dates'] ) ? wp_unslash( $_POST['vila_meal_breakfast_dates'] ) : array();
		$dinner    = isset( $_POST['vila_meal_dinner_dates'] ) ? wp_unslash( $_POST['vila_meal_dinner_dates'] ) : array();
		return array(
			'booker_name'     => isset( $_POST['vila_meal_booker_name'] ) ? wp_unslash( Request_String::scalar( $_POST['vila_meal_booker_name'] ) ) : '',
			'contact_email'   => isset( $_POST['vila_meal_contact_email'] ) ? wp_unslash( Request_String::scalar( $_POST['vila_meal_contact_email'] ) ) : '',
			'adults'          => isset( $_POST['vila_meal_adults'] ) ? wp_unslash( Request_String::scalar( $_POST['vila_meal_adults'] ) ) : 0,
			'children'        => isset( $_POST['vila_meal_children'] ) ? wp_unslash( Request_String::scalar( $_POST['vila_meal_children'] ) ) : 0,
			'infants'         => isset( $_POST['vila_meal_infants'] ) ? wp_unslash( Request_String::scalar( $_POST['vila_meal_infants'] ) ) : 0,
			'breakfast_dates' => $breakfast,
			'dinner_dates'    => $dinner,
			'booking_id'      => isset( $_POST['vila_meal_booking_id'] ) ? wp_unslash( Request_String::scalar( $_POST['vila_meal_booking_id'] ) ) : 0,
			'order_id'        => isset( $_POST['vila_meal_order_id'] ) ? wp_unslash( Request_String::scalar( $_POST['vila_meal_order_id'] ) ) : 0,
		);
	}

	/**
	 * @param array<string, mixed> $cart_item_data Cart data.
	 * @param int                  $product_id     Product ID.
	 * @param int                  $variation_id   Variation.
	 * @return array<string, mixed>
	 */
	public static function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		unset( $variation_id );
		if ( ! self::is_meals_product( (int) $product_id ) ) {
			return $cart_item_data;
		}
		if ( ! isset( $_POST['vila_meal_booking_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_meal_booking_nonce'] ) ) ), 'vila_meal_booking' ) ) {
			return $cart_item_data;
		}
		$norm = self::normalize_purchase( self::purchase_from_request() );
		if ( is_wp_error( $norm ) ) {
			return $cart_item_data;
		}
		$cart_item_data[ self::CART_KEY ] = $norm;
		// One meal checkout line per cart — replace on resubmit instead of stacking duplicates.
		$cart_item_data['unique_key'] = self::CART_KEY;
		return $cart_item_data;
	}

	/**
	 * Drop any existing campsite-meals lines so a new submission replaces the last one.
	 */
	private static function remove_meal_items_from_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $cart_key => $item ) {
			$pid = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( self::is_meals_product( $pid ) ) {
				WC()->cart->remove_cart_item( $cart_key );
			}
		}
	}

	/**
	 * @param array<string, mixed> $cart_item Cart item.
	 * @param array<string, mixed> $values    Session values.
	 * @param string               $key       Cart key.
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
			$total = self::calculate_total( $item[ self::CART_KEY ] );
			$item['data']->set_price( $total );
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
		$p = $cart_item[ self::CART_KEY ];
		$item_data[] = array(
			'name'    => __( 'Name', 'vila-campsite-booking' ),
			'display' => esc_html( (string) $p['booker_name'] ),
		);
		$item_data[] = array(
			'name'    => __( 'Guests', 'vila-campsite-booking' ),
			'display' => esc_html(
				WooCommerce_Integration::format_guest_counts_calendar_line(
					array(
						'adults'   => (int) $p['adults'],
						'children' => (int) $p['children'],
						'infants'  => (int) $p['infants'],
						'pets'     => 0,
					)
				)
			),
		);
		if ( ! empty( $p['breakfast_dates'] ) ) {
			$item_data[] = array(
				'name'    => __( 'Breakfast dates', 'vila-campsite-booking' ),
				'display' => esc_html( implode( ', ', (array) $p['breakfast_dates'] ) ),
			);
		}
		if ( ! empty( $p['dinner_dates'] ) ) {
			$item_data[] = array(
				'name'    => __( 'Dinner dates', 'vila-campsite-booking' ),
				'display' => esc_html( implode( ', ', (array) $p['dinner_dates'] ) ),
			);
		}
		if ( ! empty( $p['booking_id'] ) ) {
			$item_data[] = array(
				'name'    => __( 'Linked booking', 'vila-campsite-booking' ),
				'display' => '#' . (int) $p['booking_id'],
			);
		}
		return $item_data;
	}

	/**
	 * @param \WC_Order_Item_Product $item     Item.
	 * @param string                 $cart_key Key.
	 * @param array<string, mixed>   $values   Values.
	 * @param \WC_Order              $order    Order.
	 */
	public static function checkout_save_line_item( $item, $cart_key, $values, $order ) {
		unset( $cart_key, $order );
		if ( empty( $values[ self::CART_KEY ] ) || ! is_array( $values[ self::CART_KEY ] ) ) {
			return;
		}
		$p = $values[ self::CART_KEY ];
		$item->update_meta_data( self::META_PURCHASE, wp_json_encode( $p ) );
		$item->update_meta_data( __( 'Meals for', 'vila-campsite-booking' ), (string) $p['booker_name'] );
		if ( ! empty( $p['breakfast_dates'] ) ) {
			$item->update_meta_data( __( 'Breakfast dates', 'vila-campsite-booking' ), implode( ', ', (array) $p['breakfast_dates'] ) );
		}
		if ( ! empty( $p['dinner_dates'] ) ) {
			$item->update_meta_data( __( 'Dinner dates', 'vila-campsite-booking' ), implode( ', ', (array) $p['dinner_dates'] ) );
		}
		$item->update_meta_data(
			__( 'Meal guests', 'vila-campsite-booking' ),
			WooCommerce_Integration::format_guest_counts_calendar_line(
				array(
					'adults'   => (int) $p['adults'],
					'children' => (int) $p['children'],
					'infants'  => (int) $p['infants'],
					'pets'     => 0,
				)
			)
		);
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
	 * Merge paid meals onto the linked campsite booking (kitchen calendar).
	 *
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
		if ( 'yes' === $order->get_meta( '_vila_meal_purchase_merged', true ) ) {
			return;
		}

		$merged_any = false;
		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! is_callable( array( $item, 'get_meta' ) ) ) {
				continue;
			}
			$raw = $item->get_meta( self::META_PURCHASE, true );
			if ( is_string( $raw ) && '' !== $raw ) {
				$purchase = json_decode( $raw, true );
			} elseif ( is_array( $raw ) ) {
				$purchase = $raw;
			} else {
				continue;
			}
			if ( ! is_array( $purchase ) ) {
				continue;
			}
			$merged_any = true;
			$booking_id = isset( $purchase['booking_id'] ) ? (int) $purchase['booking_id'] : 0;
			if ( $booking_id > 0 ) {
				self::merge_purchase_into_booking( $booking_id, $purchase );
			}
		}

		if ( $merged_any ) {
			$order->update_meta_data( '_vila_meal_purchase_merged', 'yes' );
			$order->save();

			$meal_dates = array();
			foreach ( $order->get_items() as $item ) {
				if ( ! is_object( $item ) || ! is_callable( array( $item, 'get_meta' ) ) ) {
					continue;
				}
				$raw = $item->get_meta( self::META_PURCHASE, true );
				$p   = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
				if ( ! is_array( $p ) ) {
					continue;
				}
				foreach ( array( 'breakfast_dates', 'dinner_dates' ) as $key ) {
					if ( empty( $p[ $key ] ) || ! is_array( $p[ $key ] ) ) {
						continue;
					}
					foreach ( self::sanitize_ymd_list( $p[ $key ] ) as $ymd ) {
						$meal_dates[] = $ymd;
					}
				}
			}
			/**
			 * Fires after standalone meal purchases are paid / merged.
			 *
			 * @param string[] $meal_dates Y-m-d dates affected.
			 */
			do_action( 'vila_cb_meals_changed', array_values( array_unique( $meal_dates ) ) );
		}
	}

	/**
	 * Union meal dates into booking guest_json (+ pitch-line meta when present).
	 *
	 * @param int                  $booking_id Booking ID.
	 * @param array<string, mixed> $purchase   Purchase.
	 */
	public static function merge_purchase_into_booking( $booking_id, array $purchase ) {
		$repo = new Booking_Repository();
		$row  = $repo->get( (int) $booking_id );
		if ( ! $row ) {
			return;
		}
		$guests = json_decode( isset( $row['guest_json'] ) ? (string) $row['guest_json'] : '', true );
		if ( ! is_array( $guests ) ) {
			$guests = array();
		}

		$meal_dates = isset( $guests['meal_dates'] ) && is_array( $guests['meal_dates'] ) ? $guests['meal_dates'] : array();
		$b_exist    = isset( $meal_dates['breakfast'] ) ? self::sanitize_ymd_list( $meal_dates['breakfast'] ) : array();
		$d_exist    = isset( $meal_dates['dinner'] ) ? self::sanitize_ymd_list( $meal_dates['dinner'] ) : array();
		$b_new      = isset( $purchase['breakfast_dates'] ) ? self::sanitize_ymd_list( $purchase['breakfast_dates'] ) : array();
		$d_new      = isset( $purchase['dinner_dates'] ) ? self::sanitize_ymd_list( $purchase['dinner_dates'] ) : array();

		$meal_dates['breakfast'] = array_values( array_unique( array_merge( $b_exist, $b_new ) ) );
		$meal_dates['dinner']    = array_values( array_unique( array_merge( $d_exist, $d_new ) ) );
		sort( $meal_dates['breakfast'] );
		sort( $meal_dates['dinner'] );
		$guests['meal_dates'] = $meal_dates;
		$guests['meal_party'] = array(
			'adults'   => isset( $purchase['adults'] ) ? max( 0, (int) $purchase['adults'] ) : 0,
			'children' => isset( $purchase['children'] ) ? max( 0, (int) $purchase['children'] ) : 0,
			'infants'  => isset( $purchase['infants'] ) ? max( 0, (int) $purchase['infants'] ) : 0,
		);

		$slugs = isset( $guests['meal_options'] ) ? Meal_Plan_Service::normalize_meal_slugs( $guests['meal_options'] ) : array();
		if ( ! empty( $meal_dates['breakfast'] ) && ! in_array( 'breakfast', $slugs, true ) ) {
			$slugs[] = 'breakfast';
		}
		if ( ! empty( $meal_dates['dinner'] ) && ! in_array( 'dinner', $slugs, true ) ) {
			$slugs[] = 'dinner';
		}
		sort( $slugs );
		$guests['meal_options'] = $slugs;

		$repo->update(
			(int) $booking_id,
			array(
				'guest_json' => wp_json_encode( $guests ),
				'updated_at' => current_time( 'mysql' ),
			)
		);

		// Sync pitch-line meta on the original stay order when possible.
		$line_item_id = isset( $row['line_item_id'] ) ? (int) $row['line_item_id'] : 0;
		$stay_order   = ! empty( $row['order_id'] ) && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $row['order_id'] ) : null;
		if ( $stay_order instanceof \WC_Order && $line_item_id > 0 ) {
			$item = $stay_order->get_item( $line_item_id );
			if ( $item && is_callable( array( $item, 'update_meta_data' ) ) ) {
				$item->update_meta_data( Meal_Plan_Service::META_MEAL_OPTIONS, wp_json_encode( $slugs ) );
				$item->update_meta_data( WooCommerce_Integration::META_GUESTS, wp_json_encode( $guests ) );
				$item->save();
			}
		}
	}

	/**
	 * Paid meal-product purchases overlapping a date range (for kitchen calendar standalone rows).
	 *
	 * @param string $from_ymd Inclusive.
	 * @param string $to_ymd   Inclusive.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_paid_purchases_in_range( $from_ymd, $to_ymd ) {
		$pid = self::get_product_id();
		if ( $pid < 1 || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$days = array_fill_keys( Dates::enumerate_inclusive( $from_ymd, $to_ymd ), true );
		if ( empty( $days ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 100,
				'status'     => array( 'processing', 'completed', 'on-hold' ),
				'orderby'    => 'date',
				'order'      => 'DESC',
				'meta_query' => array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		if ( ! is_array( $orders ) ) {
			return array();
		}

		$out = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			foreach ( $order->get_items() as $item ) {
				if ( ! is_object( $item ) || ! is_callable( array( $item, 'get_product_id' ) ) ) {
					continue;
				}
				if ( (int) $item->get_product_id() !== $pid ) {
					continue;
				}
				$raw = $item->get_meta( self::META_PURCHASE, true );
				$p   = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
				if ( ! is_array( $p ) ) {
					continue;
				}
				// Skip purchases already merged onto a booking — those appear via booking rows.
				if ( ! empty( $p['booking_id'] ) ) {
					continue;
				}
				$b_dates = self::sanitize_ymd_list( isset( $p['breakfast_dates'] ) ? $p['breakfast_dates'] : array() );
				$d_dates = self::sanitize_ymd_list( isset( $p['dinner_dates'] ) ? $p['dinner_dates'] : array() );
				$hit     = false;
				foreach ( array_merge( $b_dates, $d_dates ) as $ymd ) {
					if ( isset( $days[ $ymd ] ) ) {
						$hit = true;
						break;
					}
				}
				if ( ! $hit ) {
					continue;
				}
				$out[] = array(
					'order_id'         => (int) $order->get_id(),
					'booker_name'      => isset( $p['booker_name'] ) ? (string) $p['booker_name'] : $order->get_formatted_billing_full_name(),
					'adults'           => isset( $p['adults'] ) ? (int) $p['adults'] : 0,
					'children'         => isset( $p['children'] ) ? (int) $p['children'] : 0,
					'infants'          => isset( $p['infants'] ) ? (int) $p['infants'] : 0,
					'breakfast_dates'  => $b_dates,
					'dinner_dates'     => $d_dates,
					'edit_url'         => $order->get_edit_order_url(),
				);
			}
		}
		return $out;
	}
}
