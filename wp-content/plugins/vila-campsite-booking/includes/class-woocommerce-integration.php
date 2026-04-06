<?php
/**
 * WooCommerce: product, cart, orders, HPOS-safe meta.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * WC integration.
 */
class WooCommerce_Integration {

	const META_CHECK_IN      = '_vila_cb_check_in';
	const META_CHECK_OUT     = '_vila_cb_check_out';
	const META_PLOT_ID       = '_vila_cb_plot_id';
	const META_BOOKING_ID    = '_vila_cb_booking_id';
	const META_GUESTS        = '_vila_cb_guest_json';
	const PRODUCT_META_FLAG  = '_vila_cb_campsite_product';

	/**
	 * If Campsite → Settings was never saved, map the Vila Akoya theme’s seeded campsite product (MWB plot products).
	 *
	 * Runs after the theme’s wp_loaded seed (priority 20).
	 */
	public static function maybe_autoconfigure_product() {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		// Only when the option was never stored. If you saved an empty field (0), delete the option or set the ID:
		// wp option delete vila_cb_product_id
		if ( false !== get_option( 'vila_cb_product_id' ) ) {
			return;
		}

		$candidate = 0;
		if ( function_exists( 'vila_akoya_get_campsite_primary_product_id' ) ) {
			$candidate = (int) vila_akoya_get_campsite_primary_product_id();
		}
		if ( ! $candidate ) {
			$candidate = (int) get_option( 'vila_akoya_campsite_product_id', 0 );
		}
		if ( ! $candidate && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$candidate = (int) wc_get_product_id_by_sku( 'vila-akoya-plot-1' );
		}
		if ( ! $candidate && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$candidate = (int) wc_get_product_id_by_sku( 'vila-akoya-campsite' );
		}

		$candidate = (int) apply_filters( 'vila_cb_autoconfigure_product_id', $candidate );
		if ( ! $candidate ) {
			return;
		}

		$product = wc_get_product( $candidate );
		if ( ! $product || 'publish' !== get_post_status( $candidate ) ) {
			return;
		}

		update_option( 'vila_cb_product_id', $candidate );
		update_post_meta( $candidate, self::PRODUCT_META_FLAG, 'yes' );
	}

	/**
	 * Boot hooks.
	 */
	public static function init() {
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_autoconfigure_product' ), 25 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'enforce_single_campsite_cart_rules' ), 5, 3 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'add_to_cart_validation' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'get_cart_item_from_session' ), 10, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'before_calculate_totals' ), 20, 1 );
		// Priority 25: after MWB (10) so we can copy mwb_mbfw_booking_values onto the line when blocks omit $values.
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'checkout_save_line_item' ), 25, 4 );
		// Last-chance copy: some checkouts populate cart line data late or strip $values before earlier hooks see it.
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'checkout_late_line_item_meta' ), 999, 4 );
		// Cart is still populated here; fixes cart_key mismatches / partial $values before empty_cart().
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'checkout_order_processed_backfill_line_meta' ), 5, 3 );
		// Checkout Block / Store API does not always fire woocommerce_checkout_order_processed — same backfill (WC 7.2+).
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'store_api_checkout_order_processed_backfill' ), 5, 1 );
		// Block checkout: validate only when placing order (not on add-to-cart — validate_cart_item fires too early there).
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'store_api_cart_errors_validate_campsite' ), 10, 2 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'maybe_finalize_bookings' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'maybe_finalize_bookings' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'maybe_finalize_bookings' ), 10, 1 );
		// BACS, cheque, and some gateways use on-hold; bookings never finalized without this.
		add_action( 'woocommerce_order_status_on-hold', array( __CLASS__, 'maybe_finalize_bookings' ), 10, 1 );
		// Catches bulk status edits and flows where per-status hooks do not run reliably.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed_finalize' ), 20, 4 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'release_bookings' ), 10, 1 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'release_bookings' ), 10, 1 );
		add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'release_bookings' ), 10, 1 );
		// Late: strip MWB/other duplicate Stay & Guests rows, then append ours (avoids duplicate React keys in Cart/Checkout blocks).
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_cart_item_data' ), 999, 2 );
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'cart_item_product_name' ), 15, 3 );
		add_filter( 'woocommerce_is_sold_individually', array( __CLASS__, 'campsite_sold_individually' ), 10, 2 );
		add_filter( 'woocommerce_quantity_input_args', array( __CLASS__, 'campsite_quantity_input_args' ), 10, 2 );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_campsite_cart_has_stay_data' ), 10, 0 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_order_itemmeta' ), 10, 1 );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( __CLASS__, 'order_item_formatted_meta_data' ), 20, 2 );

		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api_cart_extensions' ), 5 );
		add_filter( 'woocommerce_store_api_add_to_cart_data', array( __CLASS__, 'store_api_merge_add_to_cart_extensions' ), 10, 2 );
		add_filter( 'woocommerce_add_cart_item', array( __CLASS__, 'add_cart_item_merge_store_extensions' ), 25, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_checkout_blocks_bridge' ), 25 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_cart_line_styles' ), 20 );
	}

	/**
	 * Styles for stay line under product title on cart / checkout.
	 */
	public static function enqueue_cart_line_styles() {
		if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
			return;
		}
		if ( ! is_cart() && ! is_checkout() ) {
			return;
		}
		wp_enqueue_style(
			'vila-cb-cart-line',
			VILA_CB_URL . 'assets/css/cart-line.css',
			array(),
			VILA_CB_VERSION
		);
	}

	/**
	 * Always log when checkout is blocked for missing stay data (see WooCommerce → Status → Logs, source vila-campsite-booking).
	 *
	 * @param string              $where   Where in the plugin this was triggered.
	 * @param array<string,mixed> $context Context (JSON-safe).
	 */
	private static function log_stay_gate_failure( $where, array $context ) {
		$line = '[vila-campsite-booking] CHECKOUT_STAY_GATE ' . $where . ' | ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $line, array( 'source' => 'vila-campsite-booking' ) );
		} else {
			error_log( $line );
		}
	}

	/**
	 * Rich cart-line snapshot for CHECKOUT_STAY_GATE logs.
	 *
	 * @param array<string, mixed> $cart_item Cart line.
	 * @return array<string, mixed>
	 */
	private static function diagnose_cart_stay_for_log( array $cart_item ) {
		$flat = self::cart_item_flattened_for_stay_check( $cart_item );
		$ext  = array();
		if ( ! empty( $flat['extensions'] ) && is_array( $flat['extensions'] ) ) {
			$ext = array_keys( $flat['extensions'] );
		}
		$mwb_keys = array();
		if ( ! empty( $flat['mwb_mbfw_booking_values'] ) && is_array( $flat['mwb_mbfw_booking_values'] ) ) {
			$mwb_keys = array_keys( $flat['mwb_mbfw_booking_values'] );
		}
		$cin  = isset( $flat['vila_cb_check_in'] ) ? (string) $flat['vila_cb_check_in'] : '';
		$cout = isset( $flat['vila_cb_check_out'] ) ? (string) $flat['vila_cb_check_out'] : '';
		$prev = function ( $s ) {
			$s = (string) $s;
			return strlen( $s ) > 16 ? substr( $s, 0, 16 ) . '…' : $s;
		};
		return array(
			'product_id'             => isset( $flat['product_id'] ) ? (int) $flat['product_id'] : 0,
			'variation_id'           => isset( $flat['variation_id'] ) ? (int) $flat['variation_id'] : 0,
			'vila_in_preview'        => $prev( $cin ),
			'vila_out_preview'       => $prev( $cout ),
			'vila_both_ymd'          => self::validate_date_ymd( $cin ) && self::validate_date_ymd( $cout ),
			'extension_namespaces'   => $ext,
			'mwb_bv_keys'            => $mwb_keys,
			'stay_resolvable'        => self::cart_line_has_stay_dates( $cart_item ),
			'cart_item_keys_sample'  => array_slice( array_keys( $flat ), 0, 35 ),
		);
	}

	/**
	 * Opt-in debug logging (WooCommerce → Status → Logs when using wc_get_logger).
	 *
	 * Enable any of:
	 * - `wp-config.php`: `define( 'WP_DEBUG', true );` and `define( 'WP_DEBUG_LOG', true );`
	 * - Theme/plugin: `add_filter( 'vila_cb_debug_logging', '__return_true' );`
	 *
	 * @param string              $message One-line message.
	 * @param array<string,mixed> $context Small JSON-safe context (no secrets).
	 */
	private static function debug_log( $message, array $context = array() ) {
		$enabled = apply_filters(
			'vila_cb_debug_logging',
			( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG )
		);
		if ( ! $enabled ) {
			return;
		}
		$line = '[vila-campsite-booking] ' . $message;
		if ( array() !== $context ) {
			$line .= ' | ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->debug( $line, array( 'source' => 'vila-campsite-booking' ) );
		} else {
			error_log( $line );
		}
	}

	/**
	 * Safe cart row summary for logs (stay flags only).
	 *
	 * @param array<string, mixed> $cart_item Cart line.
	 * @return array<string, mixed>
	 */
	private static function cart_item_debug_snapshot( array $cart_item ) {
		$flat = self::cart_item_flattened_for_stay_check( $cart_item );
		return array(
			'product_id'     => isset( $flat['product_id'] ) ? (int) $flat['product_id'] : 0,
			'variation_id'   => isset( $flat['variation_id'] ) ? (int) $flat['variation_id'] : 0,
			'vila_in_len'    => isset( $flat['vila_cb_check_in'] ) ? strlen( (string) $flat['vila_cb_check_in'] ) : 0,
			'vila_out_len'   => isset( $flat['vila_cb_check_out'] ) ? strlen( (string) $flat['vila_cb_check_out'] ) : 0,
			'has_extensions' => ! empty( $flat['extensions'] ),
			'has_mwb_bv'     => ! empty( $flat['mwb_mbfw_booking_values'] ) && is_array( $flat['mwb_mbfw_booking_values'] ),
			'stay_ok'        => self::cart_line_has_stay_dates( $cart_item ),
		);
	}

	/**
	 * Whether product is the campsite bookable product.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function is_campsite_product( $product_id ) {
		$product_id = (int) $product_id;
		if ( ! $product_id ) {
			return false;
		}
		$opt = (int) get_option( 'vila_cb_product_id', 0 );
		if ( $opt && $opt === $product_id ) {
			return true;
		}
		if ( 'yes' === get_post_meta( $product_id, self::PRODUCT_META_FLAG, true ) ) {
			return true;
		}
		// Vila Akoya theme: one WC product per plot (SKU vila-akoya-plot-N); same inventory as this plugin’s plots table.
		if ( function_exists( 'vila_akoya_is_campsite_product_id' ) && vila_akoya_is_campsite_product_id( $product_id ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @param int $product_id Product ID.
	 * @return array{adults: int, children: int, infants: int, pets: int}
	 */
	public static function parse_guests_from_request( $product_id ) {
		unset( $product_id );
		$min_a = max( 1, (int) apply_filters( 'vila_cb_min_adults', 1 ) );
		$max_p = max( $min_a, (int) apply_filters( 'vila_cb_max_people', 8 ) );
		$max_t = max( 0, (int) apply_filters( 'vila_cb_max_pets', 5 ) );

		$adults   = isset( $_POST['vila_cb_adults'] ) ? (int) wp_unslash( Request_String::scalar( $_POST['vila_cb_adults'] ) ) : $min_a;
		$children = isset( $_POST['vila_cb_children'] ) ? (int) wp_unslash( Request_String::scalar( $_POST['vila_cb_children'] ) ) : 0;
		$infants  = isset( $_POST['vila_cb_infants'] ) ? (int) wp_unslash( Request_String::scalar( $_POST['vila_cb_infants'] ) ) : 0;
		$pets     = isset( $_POST['vila_cb_pets'] ) ? (int) wp_unslash( Request_String::scalar( $_POST['vila_cb_pets'] ) ) : 0;

		$adults   = max( $min_a, $adults );
		$children = max( 0, $children );
		$infants  = max( 0, $infants );
		$pets     = max( 0, min( $max_t, $pets ) );

		$people = $adults + $children + $infants;
		if ( $people > $max_p ) {
			$people = $max_p;
			// Reduce children then infants then adults as last resort.
			while ( $adults + $children + $infants > $max_p && $children > 0 ) {
				--$children;
			}
			while ( $adults + $children + $infants > $max_p && $infants > 0 ) {
				--$infants;
			}
			while ( $adults + $children + $infants > $max_p && $adults > $min_a ) {
				--$adults;
			}
		}

		return array(
			'adults'   => $adults,
			'children' => $children,
			'infants'  => $infants,
			'pets'     => $pets,
		);
	}

	/**
	 * Whether the MWB (Bookings for WooCommerce) booking nonce is valid for this request.
	 */
	private static function mwb_booking_nonce_ok() {
		return isset( $_POST['_mwb_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['_mwb_nonce'] ) ) ), 'mwb_booking_frontend' );
	}

	/**
	 * Read MWB single-product form dates (typically d-m-Y) and normalize to Y-m-d.
	 *
	 * @return array{check_in: string, check_out: string}|null
	 */
	private static function get_mwb_booking_stay_ymd_from_post() {
		$from = isset( $_POST['mwb_mbfw_booking_from_time'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['mwb_mbfw_booking_from_time'] ) ) ) : '';
		$to   = isset( $_POST['mwb_mbfw_booking_to_time'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['mwb_mbfw_booking_to_time'] ) ) ) : '';
		if ( '' === $from || '' === $to ) {
			return null;
		}
		$cin  = self::parse_mwb_date_to_ymd( $from );
		$cout = self::parse_mwb_date_to_ymd( $to );
		if ( ! self::validate_date_ymd( $cin ) || ! self::validate_date_ymd( $cout ) ) {
			return null;
		}
		return array(
			'check_in'  => $cin,
			'check_out' => $cout,
		);
	}

	/**
	 * Guests when MWB posts `mwb_mbfw_people_number` + theme fields `vila_guest_*` (Vila Akoya + MWB form).
	 *
	 * @param int $product_id Product ID.
	 * @return array{adults: int, children: int, infants: int, pets: int}
	 */
	private static function parse_guests_from_mwb_mixed_post( $product_id ) {
		unset( $product_id );
		$min_a = max( 1, (int) apply_filters( 'vila_cb_min_adults', 1 ) );
		$max_p = max( $min_a, (int) apply_filters( 'vila_cb_max_people', 8 ) );
		$max_t = max( 0, (int) apply_filters( 'vila_cb_max_pets', 5 ) );

		$adults = isset( $_POST['mwb_mbfw_people_number'] ) ? (int) wp_unslash( Request_String::scalar( $_POST['mwb_mbfw_people_number'] ) ) : $min_a;
		$adults = max( $min_a, $adults );

		$children = isset( $_POST['vila_guest_children'] ) ? (int) wp_unslash( Request_String::scalar( $_POST['vila_guest_children'] ) ) : 0;
		$infants  = isset( $_POST['vila_guest_infants'] ) ? (int) wp_unslash( Request_String::scalar( $_POST['vila_guest_infants'] ) ) : 0;
		$pets     = isset( $_POST['vila_guest_pets'] ) ? (int) wp_unslash( Request_String::scalar( $_POST['vila_guest_pets'] ) ) : 0;

		$children = max( 0, $children );
		$infants  = max( 0, $infants );
		$pets     = max( 0, min( $max_t, $pets ) );

		$people = $adults + $children + $infants;
		if ( $people > $max_p ) {
			while ( $adults + $children + $infants > $max_p && $children > 0 ) {
				--$children;
			}
			while ( $adults + $children + $infants > $max_p && $infants > 0 ) {
				--$infants;
			}
			while ( $adults + $children + $infants > $max_p && $adults > $min_a ) {
				--$adults;
			}
		}

		return self::normalize_guests_for_storage(
			array(
				'adults'   => $adults,
				'children' => $children,
				'infants'  => $infants,
				'pets'     => $pets,
			)
		);
	}

	/**
	 * Resolve stay Y-m-d from POST: shortcode fields first, then MWB booking fields.
	 *
	 * @return array{check_in: string, check_out: string}|null
	 */
	private static function resolve_campsite_stay_from_post() {
		$check_in  = isset( $_POST['vila_cb_check_in'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_check_in'] ) ) ) : '';
		$check_out = isset( $_POST['vila_cb_check_out'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_check_out'] ) ) ) : '';

		if ( self::validate_date_ymd( $check_in ) && self::validate_date_ymd( $check_out ) ) {
			return array(
				'check_in'  => $check_in,
				'check_out' => $check_out,
			);
		}

		if ( self::mwb_booking_nonce_ok() ) {
			return self::get_mwb_booking_stay_ymd_from_post();
		}

		return null;
	}

	/**
	 * @param bool $passed Passed.
	 * @param int  $product_id Product ID.
	 * @param int  $qty Qty.
	 * @return bool
	 */
	public static function add_to_cart_validation( $passed, $product_id, $qty ) {
		unset( $qty );
		if ( ! $passed || ! self::is_campsite_product( $product_id ) ) {
			return $passed;
		}

		$vila_nonce_ok = isset( $_POST['vila_cb_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_nonce'] ) ) ), 'vila_cb_add_to_cart' );
		$mwb_nonce_ok  = self::mwb_booking_nonce_ok();

		if ( ! $vila_nonce_ok && ! $mwb_nonce_ok ) {
			wc_add_notice( __( 'Invalid booking request. Please refresh and try again.', 'vila-campsite-booking' ), 'error' );
			return false;
		}

		$stay = self::resolve_campsite_stay_from_post();
		if ( null === $stay ) {
			wc_add_notice( __( 'Please choose valid check-in and check-out dates.', 'vila-campsite-booking' ), 'error' );
			return false;
		}

		$check_in  = $stay['check_in'];
		$check_out = $stay['check_out'];

		if ( $check_out <= $check_in ) {
			wc_add_notice( __( 'Check-out must be after check-in.', 'vila-campsite-booking' ), 'error' );
			return false;
		}

		$guests = isset( $_POST['mwb_mbfw_people_number'] )
			? self::parse_guests_from_mwb_mixed_post( $product_id )
			: self::parse_guests_from_request( $product_id );
		$min_a  = max( 1, (int) apply_filters( 'vila_cb_min_adults', 1 ) );
		$max_p  = max( $min_a, (int) apply_filters( 'vila_cb_max_people', 8 ) );

		if ( $guests['adults'] < $min_a ) {
			wc_add_notice( __( 'Please choose at least the minimum number of adults.', 'vila-campsite-booking' ), 'error' );
			return false;
		}
		if ( $guests['adults'] + $guests['children'] + $guests['infants'] > $max_p ) {
			wc_add_notice(
				sprintf(
					/* translators: %d: max guests */
					__( 'Guest count cannot exceed %d people.', 'vila-campsite-booking' ),
					$max_p
				),
				'error'
			);
			return false;
		}

		$svc = new Booking_Service();
		if ( null === $svc->find_available_plot( $check_in, $check_out ) ) {
			wc_add_notice( __( 'No pitch is available for those dates. Please choose a different stay.', 'vila-campsite-booking' ), 'error' );
			return false;
		}

		return true;
	}

	/**
	 * One booking per cart: quantity 1, no other products while a campsite line exists; adding a new booking replaces the cart.
	 *
	 * @param bool $passed      Passed.
	 * @param int  $product_id Product ID.
	 * @param int  $quantity   Qty.
	 * @return bool
	 */
	public static function enforce_single_campsite_cart_rules( $passed, $product_id, $quantity ) {
		if ( ! $passed || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $passed;
		}
		$product_id = (int) $product_id;
		$quantity   = (int) $quantity;
		$campsite   = self::is_campsite_product( $product_id );

		if ( $campsite && $quantity > 1 ) {
			wc_add_notice( __( 'You can only add one campsite stay at a time (quantity must be 1).', 'vila-campsite-booking' ), 'error' );
			return false;
		}

		if ( ! $campsite && self::cart_contains_campsite_line() ) {
			wc_add_notice(
				__( 'Your cart already contains a campsite booking. Complete checkout or remove it before adding other products.', 'vila-campsite-booking' ),
				'error'
			);
			return false;
		}

		if ( $campsite && ! WC()->cart->is_empty() ) {
			WC()->cart->empty_cart( true );
			if ( apply_filters( 'vila_cb_show_notice_cart_cleared_for_booking', true ) ) {
				wc_add_notice( __( 'Previous cart items were cleared so your new booking can be added.', 'vila-campsite-booking' ), 'notice' );
			}
		}

		return $passed;
	}

	/**
	 * @param \WC_Cart|null $cart Cart.
	 * @return bool
	 */
	private static function cart_contains_campsite_line( $cart = null ) {
		if ( ! $cart ) {
			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				return false;
			}
			$cart = WC()->cart;
		}
		if ( $cart->is_empty() ) {
			return false;
		}
		foreach ( $cart->get_cart() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$pid = isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;
			$vid = isset( $row['variation_id'] ) ? (int) $row['variation_id'] : 0;
			if ( self::is_campsite_product( $pid ) || ( $vid && self::is_campsite_product( $vid ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Append stay range to line title (classic cart + anywhere this filter runs).
	 *
	 * @param string               $name      Product name HTML.
	 * @param array<string, mixed> $cart_item Cart row.
	 * @param string               $cart_key  Key.
	 * @return string
	 */
	public static function cart_item_product_name( $name, $cart_item, $cart_key ) {
		unset( $cart_key );
		if ( ! is_array( $cart_item ) ) {
			return $name;
		}
		$pid = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
		$vid = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
		if ( ! self::is_campsite_product( $pid ) && ! ( $vid && self::is_campsite_product( $vid ) ) ) {
			return $name;
		}
		$merged = self::cart_item_flattened_for_stay_check( $cart_item );
		$cin    = isset( $merged['vila_cb_check_in'] ) ? trim( (string) $merged['vila_cb_check_in'] ) : '';
		$cout   = isset( $merged['vila_cb_check_out'] ) ? trim( (string) $merged['vila_cb_check_out'] ) : '';
		if ( self::validate_date_ymd( $cin ) && self::validate_date_ymd( $cout ) ) {
			$range = self::format_stay_range_display( $cin, $cout );
			$suffix = ' <span class="vila-cb-cart-stay">' . esc_html( $range ) . '</span>';
		} else {
			$suffix = ' <span class="vila-cb-cart-stay vila-cb-cart-stay--missing">' . esc_html__( '(stay dates not set)', 'vila-campsite-booking' ) . '</span>';
		}
		return $name . $suffix;
	}

	/**
	 * Localized check-in → check-out for cart line title and item data.
	 *
	 * @param string $cin_ymd  Y-m-d.
	 * @param string $cout_ymd Y-m-d exclusive.
	 * @return string
	 */
	private static function format_stay_range_display( $cin_ymd, $cout_ymd ) {
		if ( ! self::validate_date_ymd( $cin_ymd ) || ! self::validate_date_ymd( $cout_ymd ) ) {
			return '';
		}
		$df = get_option( 'date_format' );
		$df = ( is_string( $df ) && '' !== $df ) ? $df : 'Y-m-d';
		$t1 = strtotime( $cin_ymd . ' 00:00:00' );
		$t2 = strtotime( $cout_ymd . ' 00:00:00' );
		if ( ! $t1 || ! $t2 ) {
			return $cin_ymd . ' → ' . $cout_ymd;
		}
		return sprintf(
			/* translators: 1: check-in date, 2: check-out date (exclusive) */
			__( '%1$s → %2$s', 'vila-campsite-booking' ),
			date_i18n( $df, $t1 ),
			date_i18n( $df, $t2 )
		);
	}

	/**
	 * @param bool        $sold_individually Whether sold individually.
	 * @param \WC_Product $product           Product.
	 * @return bool
	 */
	public static function campsite_sold_individually( $sold_individually, $product ) {
		if ( $product instanceof \WC_Product && self::is_campsite_product( (int) $product->get_id() ) ) {
			return true;
		}
		return $sold_individually;
	}

	/**
	 * @param array<string, mixed> $args    Quantity args.
	 * @param \WC_Product|null     $product Product.
	 * @return array<string, mixed>
	 */
	public static function campsite_quantity_input_args( $args, $product ) {
		if ( ! $product instanceof \WC_Product || ! self::is_campsite_product( (int) $product->get_id() ) ) {
			return $args;
		}
		$args['min_value']   = 1;
		$args['max_value']   = 1;
		$args['input_value'] = 1;
		return $args;
	}

	/**
	 * @param string $ymd Y-m-d.
	 * @return bool
	 */
	private static function validate_date_ymd( $ymd ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
			return false;
		}
		$d = Dates::parse_ymd( $ymd );
		return (bool) $d;
	}

	/**
	 * MWB / theme booking fields are usually d-m-Y (checkout date is exclusive, same as this plugin).
	 *
	 * @param string $raw Raw meta.
	 * @return string Y-m-d or empty.
	 */
	private static function parse_mwb_date_to_ymd( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		// ISO or "Y-m-d H:i:s" — MWB sometimes stores full timestamps.
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $raw, $m ) ) {
			$ymd = $m[1];
			if ( self::validate_date_ymd( $ymd ) ) {
				return $ymd;
			}
		}
		// MWB uses gmdate( get_option( 'date_format' ), … ) — match the site’s Settings → General date format.
		$wp_df = (string) get_option( 'date_format' );
		$wp_tf = (string) get_option( 'time_format' );
		foreach ( array_filter( array( $wp_df, trim( $wp_df . ' ' . $wp_tf ) ) ) as $fmt ) {
			$dt = \DateTime::createFromFormat( $fmt, $raw, wp_timezone() );
			if ( $dt instanceof \DateTimeInterface ) {
				return $dt->format( 'Y-m-d' );
			}
		}
		// Last resort: PHP’s natural-language parser (handles many localized month/day strings MWB emits).
		$parsed = date_create( $raw, wp_timezone() );
		if ( $parsed instanceof \DateTimeInterface ) {
			return $parsed->setTimezone( wp_timezone() )->format( 'Y-m-d' );
		}
		$df = \DateTime::createFromFormat( 'd-m-Y', $raw, wp_timezone() );
		if ( $df ) {
			return $df->format( 'Y-m-d' );
		}
		$df = \DateTime::createFromFormat( 'd-m-Y H:i', $raw, wp_timezone() );
		if ( $df ) {
			return $df->format( 'Y-m-d' );
		}
		$df = \DateTime::createFromFormat( 'd-m-Y H:i:s', $raw, wp_timezone() );
		if ( $df ) {
			return $df->format( 'Y-m-d' );
		}
		$d = Dates::parse_ymd( $raw );
		return $d ? $d->format( 'Y-m-d' ) : '';
	}

	/**
	 * MWB / booking plugin meta key pairs tried on order line items (order matters).
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private static function get_mwb_line_item_date_key_pairs() {
		return array(
			array( '_mwb_bfwp_date_time_from', '_mwb_bfwp_date_time_to' ),
			array( '_wps_single_cal_date_time_from', '_wps_single_cal_date_time_to' ),
			array( 'From', 'To' ),
			array( '_mwb_mbfw_date_time_from', '_mwb_mbfw_date_time_to' ),
			array( '_wps_mbfw_booking_date_from', '_wps_mbfw_booking_date_to' ),
		);
	}

	/**
	 * Raw vila + MWB date fields from a line item (shared by resolve + failure diagnostics).
	 *
	 * @param \WC_Order_Item_Product $item Line item.
	 * @return array{vila_in: string, vila_out: string, guest_json: string, mwb_from: string, mwb_to: string, mwb_pair_label: string, used_order_item_meta_fallback: bool}
	 */
	private static function line_item_get_raw_stay_meta( $item ) {
		if ( is_callable( array( $item, 'read_meta_data' ) ) ) {
			$item->read_meta_data( true );
		}

		$vila_in  = (string) $item->get_meta( self::META_CHECK_IN, true );
		$vila_out = (string) $item->get_meta( self::META_CHECK_OUT, true );
		$guest_json = (string) $item->get_meta( self::META_GUESTS, true );

		$key_pairs     = self::get_mwb_line_item_date_key_pairs();
		$from          = '';
		$to            = '';
		$pair_label    = '';
		foreach ( $key_pairs as $pair ) {
			$f = $item->get_meta( $pair[0], true );
			$t = $item->get_meta( $pair[1], true );
			if ( '' !== trim( (string) $f ) && '' !== trim( (string) $t ) ) {
				$from       = (string) $f;
				$to         = (string) $t;
				$pair_label = $pair[0] . ' + ' . $pair[1];
				break;
			}
		}

		$item_id = (int) $item->get_id();
		$used_fallback = false;
		if ( ( ! $from || ! $to ) && $item_id && function_exists( 'wc_get_order_item_meta' ) ) {
			$used_fallback = true;
			$from          = $from ? $from : wc_get_order_item_meta( $item_id, '_mwb_bfwp_date_time_from', true );
			$to            = $to ? $to : wc_get_order_item_meta( $item_id, '_mwb_bfwp_date_time_to', true );
			if ( ! $from || ! $to ) {
				$from = $from ? $from : wc_get_order_item_meta( $item_id, '_wps_single_cal_date_time_from', true );
				$to   = $to ? $to : wc_get_order_item_meta( $item_id, '_wps_single_cal_date_time_to', true );
			}
			if ( '' === $pair_label && $from && $to ) {
				$pair_label = 'wc_get_order_item_meta( _mwb_bfwp_* / _wps_single_cal_* )';
			}
		}

		return array(
			'vila_in'                      => $vila_in,
			'vila_out'                     => $vila_out,
			'guest_json'                   => $guest_json,
			'mwb_from'                     => (string) $from,
			'mwb_to'                       => (string) $to,
			'mwb_pair_label'               => $pair_label,
			'used_order_item_meta_fallback' => $used_fallback,
		);
	}

	/**
	 * Shorten / normalize a value for order notes (no secrets; avoid huge blobs).
	 *
	 * @param mixed    $value Raw.
	 * @param int|null $max Max length.
	 * @return string
	 */
	private static function format_debug_scalar( $value, $max = 160 ) {
		$s = is_scalar( $value ) ? (string) $value : '';
		$s = preg_replace( '/\s+/', ' ', $s );
		if ( null !== $max && strlen( $s ) > $max ) {
			$s = substr( $s, 0, $max ) . '…';
		}
		return '' === $s ? '(empty)' : $s;
	}

	/**
	 * Multiline explanation of why stay resolution failed (for WooCommerce order notes).
	 *
	 * @param \WC_Order_Item_Product $item Line item.
	 * @return string
	 */
	private static function line_item_stay_debug_text( $item ) {
		$m = self::line_item_get_raw_stay_meta( $item );

		$lines   = array();
		$lines[] = sprintf(
			/* translators: 1: line item ID, 2: product ID */
			__( 'Line item ID %1$d, product ID %2$d.', 'vila-campsite-booking' ),
			(int) $item->get_id(),
			(int) $item->get_product_id()
		);
		$lines[] = sprintf(
			/* translators: 1,3: meta keys; 2,4: values */
			__( 'Vila meta: %1$s=%2$s; %3$s=%4$s (both must be valid Y-m-d).', 'vila-campsite-booking' ),
			self::META_CHECK_IN,
			self::format_debug_scalar( $m['vila_in'] ),
			self::META_CHECK_OUT,
			self::format_debug_scalar( $m['vila_out'] )
		);
		$vi = self::validate_date_ymd( $m['vila_in'] );
		$vo = self::validate_date_ymd( $m['vila_out'] );
		$lines[] = sprintf(
			/* translators: 1,2: ok or invalid */
			__( 'Vila validation: check-in %1$s; check-out %2$s.', 'vila-campsite-booking' ),
			$vi ? __( 'ok', 'vila-campsite-booking' ) : __( 'invalid', 'vila-campsite-booking' ),
			$vo ? __( 'ok', 'vila-campsite-booking' ) : __( 'invalid', 'vila-campsite-booking' )
		);

		$lines[] = sprintf(
			/* translators: %s: MWB key pair label or "(none)" */
			__( 'MWB keys used: %s.', 'vila-campsite-booking' ),
			$m['mwb_pair_label'] ? $m['mwb_pair_label'] : __( '(none — no From/To pair found on item)', 'vila-campsite-booking' )
		);
		if ( $m['used_order_item_meta_fallback'] ) {
			$lines[] = __( 'Also read wc_get_order_item_meta() for _mwb_bfwp_* and _wps_single_cal_*.', 'vila-campsite-booking' );
		}
		$lines[] = sprintf(
			/* translators: %s: raw from value */
			__( 'MWB raw from: %s', 'vila-campsite-booking' ),
			self::format_debug_scalar( $m['mwb_from'] )
		);
		$lines[] = sprintf(
			/* translators: %s: raw to value */
			__( 'MWB raw to: %s', 'vila-campsite-booking' ),
			self::format_debug_scalar( $m['mwb_to'] )
		);

		if ( $m['mwb_from'] && $m['mwb_to'] ) {
			$pc = self::parse_mwb_date_to_ymd( $m['mwb_from'] );
			$pt = self::parse_mwb_date_to_ymd( $m['mwb_to'] );
			$lines[] = sprintf(
				/* translators: 1,3: parsed dates; 2,4: valid/invalid */
				__( 'After parse_mwb_date_to_ymd: in=%1$s (%2$s), out=%3$s (%4$s).', 'vila-campsite-booking' ),
				$pc ? $pc : __( '(empty)', 'vila-campsite-booking' ),
				self::validate_date_ymd( $pc ) ? __( 'valid', 'vila-campsite-booking' ) : __( 'invalid', 'vila-campsite-booking' ),
				$pt ? $pt : __( '(empty)', 'vila-campsite-booking' ),
				self::validate_date_ymd( $pt ) ? __( 'valid', 'vila-campsite-booking' ) : __( 'invalid', 'vila-campsite-booking' )
			);
		} else {
			$lines[] = __( 'MWB: missing from and/or to — nothing to parse.', 'vila-campsite-booking' );
		}

		$lines[] = sprintf(
			/* translators: %s: WordPress date_format option */
			__( 'WP Settings → General: date_format=%s', 'vila-campsite-booking' ),
			self::format_debug_scalar( (string) get_option( 'date_format' ), 40 )
		);

		if ( '' === trim( $m['vila_in'] ) && '' === trim( $m['vila_out'] ) && '' === trim( $m['mwb_from'] ) && '' === trim( $m['mwb_to'] ) ) {
			$lines[] = __( 'Hint: nothing was saved on this line at checkout. Common causes: order created in wp-admin; product added without the booking form; or the WooCommerce Checkout block (Store API) ran before cart data could copy — deploy the latest plugin (Store API backfill + validation).', 'vila-campsite-booking' );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Resolve stay dates for inventory: plugin meta first, then MWB line-item meta (Bookings for WooCommerce).
	 *
	 * @param \WC_Order_Item_Product $item Line item.
	 * @return array{check_in: string, check_out: string, guest_json: string|null}|null
	 */
	private static function line_item_resolve_stay( $item ) {
		$m = self::line_item_get_raw_stay_meta( $item );

		if ( self::validate_date_ymd( $m['vila_in'] ) && self::validate_date_ymd( $m['vila_out'] ) ) {
			return array(
				'check_in'   => $m['vila_in'],
				'check_out'  => $m['vila_out'],
				'guest_json' => '' !== $m['guest_json'] ? $m['guest_json'] : null,
			);
		}

		if ( ! $m['mwb_from'] || ! $m['mwb_to'] ) {
			return null;
		}

		$cin  = self::parse_mwb_date_to_ymd( $m['mwb_from'] );
		$cout = self::parse_mwb_date_to_ymd( $m['mwb_to'] );
		if ( ! self::validate_date_ymd( $cin ) || ! self::validate_date_ymd( $cout ) ) {
			return null;
		}
		return array(
			'check_in'   => $cin,
			'check_out'  => $cout,
			'guest_json' => '' !== $m['guest_json'] ? $m['guest_json'] : null,
		);
	}

	/**
	 * @param array<string, mixed> $cart_item_data Cart data.
	 * @param int                  $product_id Product ID.
	 * @param int                  $variation_id Variation ID.
	 * @return array<string, mixed>
	 */
	public static function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		unset( $variation_id );
		if ( ! self::is_campsite_product( $product_id ) ) {
			return $cart_item_data;
		}

		$vila_nonce_ok = isset( $_POST['vila_cb_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_nonce'] ) ) ), 'vila_cb_add_to_cart' );
		$mwb_nonce_ok  = self::mwb_booking_nonce_ok();

		if ( ! $vila_nonce_ok && ! $mwb_nonce_ok ) {
			return $cart_item_data;
		}

		$stay = self::resolve_campsite_stay_from_post();
		if ( null === $stay ) {
			return $cart_item_data;
		}

		$guests = isset( $_POST['mwb_mbfw_people_number'] )
			? self::parse_guests_from_mwb_mixed_post( $product_id )
			: self::parse_guests_from_request( $product_id );

		$cart_item_data['vila_cb_check_in']  = $stay['check_in'];
		$cart_item_data['vila_cb_check_out'] = $stay['check_out'];
		$cart_item_data['vila_cb_guests']    = $guests;

		return $cart_item_data;
	}

	/**
	 * @param array<string, mixed> $cart_item Cart item.
	 * @param array<string, mixed> $values Session values.
	 * @param string               $key Cart key.
	 * @return array<string, mixed>
	 */
	public static function get_cart_item_from_session( $cart_item, $values, $key ) {
		unset( $key );
		foreach ( array( 'vila_cb_check_in', 'vila_cb_check_out', 'vila_cb_guests' ) as $k ) {
			if ( isset( $values[ $k ] ) ) {
				$cart_item[ $k ] = $values[ $k ];
			}
		}
		// Session restore: Blocks may persist only `extensions.vila-campsite-booking` on the stored row.
		if ( ( empty( $cart_item['vila_cb_check_in'] ) || empty( $cart_item['vila_cb_check_out'] ) )
			&& ! empty( $values['extensions']['vila-campsite-booking'] )
			&& is_array( $values['extensions']['vila-campsite-booking'] ) ) {
			$ex = $values['extensions']['vila-campsite-booking'];
			if ( empty( $cart_item['vila_cb_check_in'] ) && ! empty( $ex['vila_cb_check_in'] ) ) {
				$cart_item['vila_cb_check_in'] = sanitize_text_field( (string) $ex['vila_cb_check_in'] );
			}
			if ( empty( $cart_item['vila_cb_check_out'] ) && ! empty( $ex['vila_cb_check_out'] ) ) {
				$cart_item['vila_cb_check_out'] = sanitize_text_field( (string) $ex['vila_cb_check_out'] );
			}
			if ( ( empty( $cart_item['vila_cb_guests'] ) || ! is_array( $cart_item['vila_cb_guests'] ) ) && ! empty( $ex['vila_cb_guests'] ) && is_array( $ex['vila_cb_guests'] ) ) {
				$cart_item['vila_cb_guests'] = $ex['vila_cb_guests'];
			}
		}
		return $cart_item;
	}

	/**
	 * Set line price from nights × guest rates.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public static function before_calculate_totals( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! $cart || $cart->is_empty() ) {
			return;
		}
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			$pid = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			if ( ! self::is_campsite_product( $pid ) ) {
				continue;
			}
			$cin = isset( $cart_item['vila_cb_check_in'] ) ? (string) $cart_item['vila_cb_check_in'] : '';
			$cout = isset( $cart_item['vila_cb_check_out'] ) ? (string) $cart_item['vila_cb_check_out'] : '';
			$guests = isset( $cart_item['vila_cb_guests'] ) && is_array( $cart_item['vila_cb_guests'] ) ? $cart_item['vila_cb_guests'] : array();
			$product = $cart_item['data'];
			if ( ! $product ) {
				continue;
			}
			$total = self::calculate_line_total( $product, $cin, $cout, $guests );
			$product->set_price( $total );
		}
	}

	/**
	 * @param \WC_Product $product Product.
	 * @param string      $check_in Y-m-d.
	 * @param string      $check_out Y-m-d exclusive.
	 * @param array       $guests Guest counts.
	 * @return float
	 */
	public static function calculate_line_total( $product, $check_in, $check_out, array $guests ) {
		$nights = Dates::night_count( $check_in, $check_out );
		if ( $nights < 1 ) {
			return (float) $product->get_regular_price();
		}
		$pid = (int) $product->get_id();
		$base = (float) apply_filters( 'vila_cb_adult_rate_per_night', (float) $product->get_regular_price(), $pid );
		$adults = isset( $guests['adults'] ) ? max( 1, (int) $guests['adults'] ) : 1;
		$ch     = isset( $guests['children'] ) ? max( 0, (int) $guests['children'] ) : 0;
		$inf    = isset( $guests['infants'] ) ? max( 0, (int) $guests['infants'] ) : 0;
		$pets   = isset( $guests['pets'] ) ? max( 0, (int) $guests['pets'] ) : 0;

		$rates = Booking_Service::guest_category_rates( $pid );
		$line  = ( $adults * $base * $nights )
			+ ( $ch * (float) $rates['children'] * $nights )
			+ ( $inf * (float) $rates['infants'] * $nights )
			+ ( $pets * (float) $rates['pets'] * $nights );

		return apply_filters( 'vila_cb_calculated_line_total', (float) $line, $pid, $check_in, $check_out, $guests, $nights );
	}

	/**
	 * @param \WC_Order_Item_Product $item Item.
	 * @param string                 $cart_key Key.
	 * @param array<string, mixed>   $values Values.
	 * @param \WC_Order              $order Order.
	 */
	public static function checkout_save_line_item( $item, $cart_key, $values, $order ) {
		unset( $order );
		$pid = (int) $item->get_product_id();
		if ( ! self::is_campsite_product( $pid ) ) {
			return;
		}
		$merged = self::merge_checkout_cart_line( $cart_key, $values );
		$cin    = isset( $merged['vila_cb_check_in'] ) ? (string) $merged['vila_cb_check_in'] : '';
		$cout   = isset( $merged['vila_cb_check_out'] ) ? (string) $merged['vila_cb_check_out'] : '';
		$guests = isset( $merged['vila_cb_guests'] ) && is_array( $merged['vila_cb_guests'] ) ? $merged['vila_cb_guests'] : array();
		$guests = self::normalize_guests_for_storage( $guests );

		self::debug_log(
			'checkout_save_line_item',
			array(
				'cart_key'   => $cart_key,
				'product_id' => $pid,
				'vila_in'    => '' !== $cin ? 'set' : 'empty',
				'vila_out'   => '' !== $cout ? 'set' : 'empty',
				'has_mwb_bv' => ! empty( $merged['mwb_mbfw_booking_values'] ) && is_array( $merged['mwb_mbfw_booking_values'] ),
				'snapshot'   => self::cart_item_debug_snapshot( $merged ),
			)
		);

		$item->update_meta_data( self::META_CHECK_IN, $cin );
		$item->update_meta_data( self::META_CHECK_OUT, $cout );
		$item->update_meta_data( self::META_GUESTS, wp_json_encode( $guests ) );

		self::backfill_mwb_line_meta_from_cart( $item, $merged );
	}

	/**
	 * Merge $values (cart line from WC) with the live cart so nothing is lost when checkout passes a partial array.
	 *
	 * @param string                 $cart_key Cart item key.
	 * @param array<string, mixed>   $values   Cart line from checkout.
	 * @return array<string, mixed>
	 */
	private static function merge_checkout_cart_line( $cart_key, $values ) {
		$line = is_array( $values ) ? $values : array();
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			self::debug_log( 'merge_checkout_cart_line: cart empty, no merge', array( 'cart_key' => $cart_key ) );
			return $line;
		}
		$cart = WC()->cart->get_cart();
		if ( isset( $cart[ $cart_key ] ) && is_array( $cart[ $cart_key ] ) ) {
			self::debug_log( 'merge_checkout_cart_line: direct cart_key match', array( 'cart_key' => $cart_key ) );
			return array_merge( $cart[ $cart_key ], $line );
		}
		// Some gateways / race conditions pass a cart_key that no longer exists; match product + variation.
		$pid = isset( $line['product_id'] ) ? (int) $line['product_id'] : 0;
		$vid = isset( $line['variation_id'] ) ? (int) $line['variation_id'] : 0;
		if ( ! $pid || ( ! self::is_campsite_product( $pid ) && ! ( $vid && self::is_campsite_product( $vid ) ) ) ) {
			self::debug_log(
				'merge_checkout_cart_line: no direct key, not campsite merge',
				array( 'cart_key' => $cart_key, 'product_id' => $pid, 'variation_id' => $vid )
			);
			return $line;
		}
		$matches = array();
		foreach ( $cart as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rp = isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;
			$rv = isset( $row['variation_id'] ) ? (int) $row['variation_id'] : 0;
			if ( $rp === $pid && $rv === $vid ) {
				$matches[] = $row;
			}
		}
		if ( 1 === count( $matches ) ) {
			self::debug_log(
				'merge_checkout_cart_line: product/variation fallback',
				array( 'cart_key' => $cart_key, 'product_id' => $pid, 'variation_id' => $vid )
			);
			return array_merge( $matches[0], $line );
		}
		self::debug_log(
			'merge_checkout_cart_line: fallback ambiguous or none',
			array( 'cart_key' => $cart_key, 'product_id' => $pid, 'match_count' => count( $matches ) )
		);
		return $line;
	}

	/**
	 * After checkout, copy vila/MWB cart data onto order lines if creation hooks missed it (cart still loaded).
	 *
	 * @param int              $order_id   Order ID.
	 * @param array<mixed>     $posted_data Posted checkout fields.
	 * @param \WC_Order|false  $order      Order.
	 */
	public static function checkout_order_processed_backfill_line_meta( $order_id, $posted_data, $order ) {
		unset( $posted_data );
		if ( ! $order_id ) {
			return;
		}
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		self::backfill_campsite_order_lines_from_cart( $order );
	}

	/**
	 * Same as classic checkout backfill, for Checkout Block / Store API (woocommerce_store_api_checkout_order_processed).
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function store_api_checkout_order_processed_backfill( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		self::backfill_campsite_order_lines_from_cart( $order );
	}

	/**
	 * True when the current request is POST to the Store API checkout order route (place order), not add-to-cart.
	 *
	 * @return bool
	 */
	private static function is_store_api_checkout_place_order_request() {
		if ( empty( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return false;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( Request_String::scalar( $_SERVER['REQUEST_URI'] ) ) : '';
		$qs  = isset( $_SERVER['QUERY_STRING'] ) ? (string) wp_unslash( Request_String::scalar( $_SERVER['QUERY_STRING'] ) ) : '';
		$hay = rawurldecode( $uri . ( $qs !== '' ? '?' . $qs : '' ) );
		return (bool) preg_match( '#wc/store/v[0-9]+/checkout#i', $hay );
	}

	/**
	 * Expose booking fields on Store API cart items + schema (Checkout/Cart blocks).
	 */
	public static function register_store_api_cart_extensions() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		if ( ! class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema' ) ) {
			self::debug_log( 'register_store_api_cart_extensions: CartItemSchema not loaded' );
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER,
				'namespace'       => 'vila-campsite-booking',
				'data_callback'   => array( __CLASS__, 'store_api_cart_item_extension_payload' ),
				'schema_callback' => array( __CLASS__, 'store_api_cart_item_extension_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			woocommerce_store_api_register_update_callback(
				array(
					'namespace' => 'vila-campsite-booking',
					'callback'  => array( __CLASS__, 'store_api_extension_update_apply_stay' ),
				)
			);
		}
	}

	/**
	 * Cart/extensions endpoint: apply stay + guests from Blocks `extensionCartUpdate()` to campsite lines.
	 *
	 * @param array<string, mixed> $data Client payload (dates, guests, optional product_id).
	 */
	public static function store_api_extension_update_apply_stay( $data ) {
		if ( ! is_array( $data ) ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$cin  = isset( $data['vila_cb_check_in'] ) ? sanitize_text_field( (string) $data['vila_cb_check_in'] ) : '';
		$cout = isset( $data['vila_cb_check_out'] ) ? sanitize_text_field( (string) $data['vila_cb_check_out'] ) : '';
		if ( ! self::validate_date_ymd( $cin ) || ! self::validate_date_ymd( $cout ) || $cout <= $cin ) {
			self::debug_log(
				'store_api_extension_update_apply_stay: invalid dates',
				array( 'cin' => $cin, 'cout' => $cout )
			);
			return;
		}
		$guests_in = isset( $data['vila_cb_guests'] ) && is_array( $data['vila_cb_guests'] ) ? $data['vila_cb_guests'] : array();
		$guests    = self::normalize_guests_for_storage( $guests_in );
		$filter_pid = isset( $data['product_id'] ) ? (int) $data['product_id'] : 0;

		$updated = false;
		foreach ( WC()->cart->get_cart() as $cart_key => $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}
			$pid = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			$vid = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
			if ( ! self::is_campsite_product( $pid ) && ! ( $vid && self::is_campsite_product( $vid ) ) ) {
				continue;
			}
			if ( $filter_pid && $pid !== $filter_pid && $vid !== $filter_pid ) {
				continue;
			}
			WC()->cart->cart_contents[ $cart_key ]['vila_cb_check_in']  = $cin;
			WC()->cart->cart_contents[ $cart_key ]['vila_cb_check_out'] = $cout;
			WC()->cart->cart_contents[ $cart_key ]['vila_cb_guests']    = $guests;
			if ( ! isset( WC()->cart->cart_contents[ $cart_key ]['extensions'] ) || ! is_array( WC()->cart->cart_contents[ $cart_key ]['extensions'] ) ) {
				WC()->cart->cart_contents[ $cart_key ]['extensions'] = array();
			}
			WC()->cart->cart_contents[ $cart_key ]['extensions']['vila-campsite-booking'] = array(
				'vila_cb_check_in'  => $cin,
				'vila_cb_check_out' => $cout,
				'vila_cb_guests'    => $guests,
			);
			$updated = true;
		}
		if ( $updated ) {
			WC()->cart->set_session();
			WC()->cart->calculate_totals();
			self::debug_log(
				'store_api_extension_update_apply_stay: applied',
				array( 'cin' => $cin, 'cout' => $cout, 'filter_product_id' => $filter_pid )
			);
		}
	}

	/**
	 * Enqueue script that calls `extensionCartUpdate` on Cart/Checkout blocks using sessionStorage from the booking form.
	 */
	public static function enqueue_checkout_blocks_bridge() {
		if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
			return;
		}
		if ( ! is_cart() && ! is_checkout() ) {
			return;
		}
		$handles = array( 'wc-checkout-block-frontend', 'wc-blocks-checkout', 'wc-cart-block-frontend' );
		$dep     = null;
		foreach ( $handles as $h ) {
			if ( wp_script_is( $h, 'registered' ) ) {
				$dep = $h;
				break;
			}
		}
		if ( ! $dep ) {
			return;
		}
		wp_enqueue_script(
			'vila-cb-checkout-blocks',
			VILA_CB_URL . 'assets/js/checkout-blocks.js',
			array( $dep ),
			VILA_CB_VERSION,
			true
		);
	}

	/**
	 * @param array<string, mixed> $cart_item Cart row.
	 * @return array<string, mixed>
	 */
	public static function store_api_cart_item_extension_payload( $cart_item ) {
		if ( ! is_array( $cart_item ) ) {
			return array();
		}
		$pid = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
		$vid = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
		if ( ! self::is_campsite_product( $pid ) && ! ( $vid && self::is_campsite_product( $vid ) ) ) {
			return array();
		}
		$guests = isset( $cart_item['vila_cb_guests'] ) && is_array( $cart_item['vila_cb_guests'] ) ? $cart_item['vila_cb_guests'] : array();
		return array(
			'vila_cb_check_in'  => isset( $cart_item['vila_cb_check_in'] ) ? (string) $cart_item['vila_cb_check_in'] : '',
			'vila_cb_check_out' => isset( $cart_item['vila_cb_check_out'] ) ? (string) $cart_item['vila_cb_check_out'] : '',
			'vila_cb_guests'    => $guests,
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function store_api_cart_item_extension_schema() {
		return array(
			'vila_cb_check_in'  => array(
				'description' => __( 'Check-in date (Y-m-d).', 'vila-campsite-booking' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
			),
			'vila_cb_check_out' => array(
				'description' => __( 'Check-out date (Y-m-d), exclusive.', 'vila-campsite-booking' ),
				'type'        => 'string',
				'context'     => array( 'view', 'edit' ),
			),
			'vila_cb_guests'    => array(
				'description' => __( 'Guest counts object.', 'vila-campsite-booking' ),
				'type'        => 'object',
				'context'     => array( 'view', 'edit' ),
			),
		);
	}

	/**
	 * Merge `extensions.vila-campsite-booking` from Store API add-to-cart into cart_item_data.
	 *
	 * @param array<string, mixed>     $add_to_cart_data Add payload.
	 * @param \WP_REST_Request|mixed   $request          Request.
	 * @return array<string, mixed>
	 */
	public static function store_api_merge_add_to_cart_extensions( $add_to_cart_data, $request ) {
		if ( ! is_array( $add_to_cart_data ) ) {
			return $add_to_cart_data;
		}
		$cid = isset( $add_to_cart_data['cart_item_data'] ) && is_array( $add_to_cart_data['cart_item_data'] ) ? $add_to_cart_data['cart_item_data'] : array();
		$ext = null;
		if ( $request instanceof \WP_REST_Request ) {
			$ext = $request->get_param( 'extensions' );
			if ( ! is_array( $ext ) ) {
				$json = $request->get_json_params();
				if ( is_array( $json ) && isset( $json['extensions'] ) && is_array( $json['extensions'] ) ) {
					$ext = $json['extensions'];
				}
			}
		} elseif ( is_array( $request ) && isset( $request['extensions'] ) ) {
			$ext = $request['extensions'];
		}
		if ( ! is_array( $ext ) || empty( $ext['vila-campsite-booking'] ) || ! is_array( $ext['vila-campsite-booking'] ) ) {
			$add_to_cart_data['cart_item_data'] = $cid;
			return $add_to_cart_data;
		}
		$v = $ext['vila-campsite-booking'];
		if ( isset( $v['vila_cb_check_in'] ) ) {
			$cid['vila_cb_check_in'] = sanitize_text_field( (string) $v['vila_cb_check_in'] );
		}
		if ( isset( $v['vila_cb_check_out'] ) ) {
			$cid['vila_cb_check_out'] = sanitize_text_field( (string) $v['vila_cb_check_out'] );
		}
		if ( isset( $v['vila_cb_guests'] ) && is_array( $v['vila_cb_guests'] ) ) {
			$cid['vila_cb_guests'] = $v['vila_cb_guests'];
		}
		$add_to_cart_data['cart_item_data'] = $cid;
		self::debug_log(
			'store_api_merge_add_to_cart_extensions',
			array(
				'product_id' => isset( $add_to_cart_data['id'] ) ? (int) $add_to_cart_data['id'] : 0,
				'has_in'     => ! empty( $cid['vila_cb_check_in'] ),
				'has_out'    => ! empty( $cid['vila_cb_check_out'] ),
				'has_guests' => ! empty( $cid['vila_cb_guests'] ) && is_array( $cid['vila_cb_guests'] ),
			)
		);
		return $add_to_cart_data;
	}

	/**
	 * Copy Store API extension payload back onto top-level cart line keys (session cart used at checkout).
	 *
	 * @param array<string, mixed> $cart_item Cart row.
	 * @param string               $cart_key  Key.
	 * @return array<string, mixed>
	 */
	public static function add_cart_item_merge_store_extensions( $cart_item, $cart_key ) {
		unset( $cart_key );
		if ( ! is_array( $cart_item ) || empty( $cart_item['product_id'] ) ) {
			return $cart_item;
		}
		$pid = (int) $cart_item['product_id'];
		$vid = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
		if ( ! self::is_campsite_product( $pid ) && ! ( $vid && self::is_campsite_product( $vid ) ) ) {
			return $cart_item;
		}
		if ( ! empty( $cart_item['vila_cb_check_in'] ) && ! empty( $cart_item['vila_cb_check_out'] ) ) {
			return $cart_item;
		}
		$ext = isset( $cart_item['extensions']['vila-campsite-booking'] ) && is_array( $cart_item['extensions']['vila-campsite-booking'] )
			? $cart_item['extensions']['vila-campsite-booking']
			: array();
		if ( empty( $ext ) ) {
			return $cart_item;
		}
		if ( empty( $cart_item['vila_cb_check_in'] ) && ! empty( $ext['vila_cb_check_in'] ) ) {
			$cart_item['vila_cb_check_in'] = sanitize_text_field( (string) $ext['vila_cb_check_in'] );
		}
		if ( empty( $cart_item['vila_cb_check_out'] ) && ! empty( $ext['vila_cb_check_out'] ) ) {
			$cart_item['vila_cb_check_out'] = sanitize_text_field( (string) $ext['vila_cb_check_out'] );
		}
		if ( ( empty( $cart_item['vila_cb_guests'] ) || ! is_array( $cart_item['vila_cb_guests'] ) ) && ! empty( $ext['vila_cb_guests'] ) && is_array( $ext['vila_cb_guests'] ) ) {
			$cart_item['vila_cb_guests'] = $ext['vila_cb_guests'];
		}
		self::debug_log(
			'add_cart_item_merge_store_extensions',
			array(
				'product_id' => $pid,
				'has_in'     => ! empty( $cart_item['vila_cb_check_in'] ),
				'has_out'    => ! empty( $cart_item['vila_cb_check_out'] ),
			)
		);
		return $cart_item;
	}

	/**
	 * Store API: block **checkout** if a campsite line has no stay dates. Runs from CartController::validate_cart
	 * with a WP_Error — only hook when placing an order so add-to-cart is not blocked.
	 *
	 * @param \WP_Error $errors Errors.
	 * @param \WC_Cart $cart   Cart.
	 */
	public static function store_api_cart_errors_validate_campsite( $errors, $cart ) {
		if ( ! $errors instanceof \WP_Error || ! $cart || ! is_object( $cart ) || ! is_callable( array( $cart, 'get_cart' ) ) ) {
			return;
		}
		$is_checkout = self::is_store_api_checkout_place_order_request();
		self::debug_log(
			'store_api_cart_errors',
			array(
				'is_checkout_place_order' => $is_checkout,
				'request_uri'             => isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( Request_String::scalar( $_SERVER['REQUEST_URI'] ) ) : '',
			)
		);
		if ( ! $is_checkout ) {
			return;
		}
		$svc = new Booking_Service();
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}
			$pid = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			$vid = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
			if ( ! self::is_campsite_product( $pid ) && ! ( $vid && self::is_campsite_product( $vid ) ) ) {
				continue;
			}
			if ( self::cart_line_has_stay_dates( $cart_item ) ) {
				$stay = self::cart_item_resolve_stay_ymd( $cart_item );
				if ( null !== $stay && null === $svc->find_available_plot( $stay['check_in'], $stay['check_out'] ) ) {
					$errors->add(
						'vila_cb_no_pitch',
						__( 'No pitch is available for your selected dates. Please choose different dates or contact us.', 'vila-campsite-booking' )
					);
					return;
				}
				continue;
			}
			$campsite_dump = array();
			foreach ( $cart->get_cart() as $ck => $ci ) {
				if ( ! is_array( $ci ) ) {
					continue;
				}
				$p = isset( $ci['product_id'] ) ? (int) $ci['product_id'] : 0;
				$w = isset( $ci['variation_id'] ) ? (int) $ci['variation_id'] : 0;
				if ( ! self::is_campsite_product( $p ) && ! ( $w && self::is_campsite_product( $w ) ) ) {
					continue;
				}
				$campsite_dump[] = array_merge(
					array( 'cart_key' => (string) $ck ),
					self::diagnose_cart_stay_for_log( $ci )
				);
			}
			self::log_stay_gate_failure(
				'store_api_cart_errors',
				array(
					'request_uri'      => isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( Request_String::scalar( $_SERVER['REQUEST_URI'] ) ) : '',
					'campsite_lines'   => $campsite_dump,
					'failing_product'  => $pid,
				)
			);
			$errors->add(
				'vila_cb_missing_stay',
				__( 'Please choose check-in and check-out dates for your campsite booking. Use the booking form on the product page, or return to the cart and add the stay again.', 'vila-campsite-booking' )
			);
			return;
		}
	}

	/**
	 * Copy vila + MWB cart extensions onto order line items when line meta is missing (cart session still available).
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function backfill_campsite_order_lines_from_cart( \WC_Order $order ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			self::debug_log( 'backfill_campsite_order_lines_from_cart: skip (no cart)', array( 'order_id' => $order->get_id() ) );
			return;
		}

		self::debug_log( 'backfill_campsite_order_lines_from_cart: start', array( 'order_id' => $order->get_id() ) );

		$cart_rows = array();
		foreach ( WC()->cart->get_cart() as $cart_key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$pid = isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;
			$vid = isset( $row['variation_id'] ) ? (int) $row['variation_id'] : 0;
			if ( self::is_campsite_product( $pid ) || ( $vid && self::is_campsite_product( $vid ) ) ) {
				$cart_rows[] = $row;
			}
		}
		if ( empty( $cart_rows ) ) {
			self::debug_log( 'backfill: no campsite rows in cart', array( 'order_id' => $order->get_id() ) );
			return;
		}

		$order_items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! is_object( $item ) || ! is_callable( array( $item, 'get_product_id' ) ) ) {
				continue;
			}
			$opid = (int) $item->get_product_id();
			$ovid = is_callable( array( $item, 'get_variation_id' ) ) ? (int) $item->get_variation_id() : 0;
			if ( self::is_campsite_product( $opid ) || ( $ovid && self::is_campsite_product( $ovid ) ) ) {
				$order_items[] = $item;
			}
		}
		if ( count( $cart_rows ) !== count( $order_items ) ) {
			self::debug_log(
				'backfill: campsite line count mismatch',
				array(
					'order_id'             => $order->get_id(),
					'cart_campsite_lines'  => count( $cart_rows ),
					'order_campsite_lines' => count( $order_items ),
				)
			);
			return;
		}

		$changed = false;
		foreach ( $order_items as $i => $item ) {
			$merged = $cart_rows[ $i ];
			$have_in  = (string) $item->get_meta( self::META_CHECK_IN, true );
			$have_out = (string) $item->get_meta( self::META_CHECK_OUT, true );
			$needs    = ! self::validate_date_ymd( $have_in ) || ! self::validate_date_ymd( $have_out );
			if ( ! $needs ) {
				continue;
			}
			$cin  = isset( $merged['vila_cb_check_in'] ) ? (string) $merged['vila_cb_check_in'] : '';
			$cout = isset( $merged['vila_cb_check_out'] ) ? (string) $merged['vila_cb_check_out'] : '';
			if ( self::validate_date_ymd( $cin ) && self::validate_date_ymd( $cout ) ) {
				$item->update_meta_data( self::META_CHECK_IN, $cin );
				$item->update_meta_data( self::META_CHECK_OUT, $cout );
				$gv = isset( $merged['vila_cb_guests'] ) && is_array( $merged['vila_cb_guests'] ) ? $merged['vila_cb_guests'] : array();
				$item->update_meta_data( self::META_GUESTS, wp_json_encode( self::normalize_guests_for_storage( $gv ) ) );
				self::backfill_mwb_line_meta_from_cart( $item, $merged );
				$item->save();
				$changed = true;
				continue;
			}
			self::backfill_mwb_line_meta_from_cart( $item, $merged );
			if ( is_callable( array( $item, 'save' ) ) ) {
				$item->save();
			}
			$changed = true;
		}
		if ( $changed ) {
			self::debug_log( 'backfill: updated line meta', array( 'order_id' => $order->get_id() ) );
			$order->save();
		} else {
			self::debug_log( 'backfill: no changes needed', array( 'order_id' => $order->get_id() ) );
		}
	}

	/**
	 * Runs after all other checkout line hooks; ensures vila_cb + MWB date meta exist on the item.
	 *
	 * @param \WC_Order_Item_Product $item     Line item.
	 * @param string                 $cart_key Cart key.
	 * @param array<string, mixed>   $values   Cart line.
	 * @param \WC_Order              $order    Order.
	 */
	public static function checkout_late_line_item_meta( $item, $cart_key, $values, $order ) {
		unset( $order );
		$pid = (int) $item->get_product_id();
		if ( ! self::is_campsite_product( $pid ) ) {
			return;
		}
		$merged = self::merge_checkout_cart_line( $cart_key, $values );

		$have_in = (string) $item->get_meta( self::META_CHECK_IN, true );
		$have_out = (string) $item->get_meta( self::META_CHECK_OUT, true );
		if ( ( ! $have_in || ! $have_out ) && ! empty( $merged['vila_cb_check_in'] ) && ! empty( $merged['vila_cb_check_out'] ) ) {
			$item->update_meta_data( self::META_CHECK_IN, (string) $merged['vila_cb_check_in'] );
			$item->update_meta_data( self::META_CHECK_OUT, (string) $merged['vila_cb_check_out'] );
		}
		$gv = isset( $merged['vila_cb_guests'] ) && is_array( $merged['vila_cb_guests'] ) ? $merged['vila_cb_guests'] : array();
		$item->update_meta_data( self::META_GUESTS, wp_json_encode( self::normalize_guests_for_storage( $gv ) ) );

		self::backfill_mwb_line_meta_from_cart( $item, $merged );

		if ( is_callable( array( $item, 'save' ) ) ) {
			$item->save();
		}
	}

	/**
	 * MWB writes order line meta from cart mwb_mbfw_booking_values. Copy onto the line when missing or empty.
	 *
	 * @param \WC_Order_Item_Product $item   Line item.
	 * @param array<string, mixed>   $merged Merged cart line (see merge_checkout_cart_line).
	 */
	private static function backfill_mwb_line_meta_from_cart( $item, $merged ) {
		$bv = null;
		if ( ! empty( $merged['mwb_mbfw_booking_values'] ) && is_array( $merged['mwb_mbfw_booking_values'] ) ) {
			$bv = $merged['mwb_mbfw_booking_values'];
		}
		if ( ! is_array( $bv ) ) {
			return;
		}

		$pairs = array(
			array( '_mwb_bfwp_date_time_from', 'date_time_from' ),
			array( '_mwb_bfwp_date_time_to', 'date_time_to' ),
			array( '_wps_single_cal_date_time_from', 'single_cal_date_time_from' ),
			array( '_wps_single_cal_date_time_to', 'single_cal_date_time_to' ),
		);
		foreach ( $pairs as $pair ) {
			$meta_key = $pair[0];
			$bv_key   = $pair[1];
			$existing = (string) $item->get_meta( $meta_key, true );
			if ( '' !== trim( $existing ) ) {
				continue;
			}
			if ( ! empty( $bv[ $bv_key ] ) ) {
				$item->update_meta_data( $meta_key, $bv[ $bv_key ] );
			}
		}
	}

	/**
	 * Persistable guest counts (minimum 1 adult when empty / broken POST).
	 *
	 * @param array<string, mixed> $guests Raw guest array.
	 * @return array{adults: int, children: int, infants: int, pets: int}
	 */
	private static function normalize_guests_for_storage( $guests ) {
		$min_a = max( 1, (int) apply_filters( 'vila_cb_min_adults', 1 ) );
		if ( ! is_array( $guests ) ) {
			$guests = array();
		}
		return array(
			'adults'   => isset( $guests['adults'] ) ? max( $min_a, (int) $guests['adults'] ) : $min_a,
			'children' => isset( $guests['children'] ) ? max( 0, (int) $guests['children'] ) : 0,
			'infants'  => isset( $guests['infants'] ) ? max( 0, (int) $guests['infants'] ) : 0,
			'pets'     => isset( $guests['pets'] ) ? max( 0, (int) $guests['pets'] ) : 0,
		);
	}

	/**
	 * Normalized guest counts for admin booking edits and Booking_Service (wraps private normalizer).
	 *
	 * @param array<string, mixed> $guests Raw counts.
	 * @return array{adults: int, children: int, infants: int, pets: int}
	 */
	public static function normalize_guest_counts_array( array $guests ) {
		return self::normalize_guests_for_storage( $guests );
	}

	/**
	 * Sync WooCommerce line item meta after a campsite booking was updated in wp-admin.
	 *
	 * @param \WC_Order $order          Order.
	 * @param int       $line_item_id   Line item ID.
	 * @param string    $check_in_ymd   Check-in Y-m-d.
	 * @param string    $check_out_ymd  Check-out Y-m-d (exclusive end).
	 * @param array     $guests         Guest counts (normalized by caller or via normalize_guest_counts_array).
	 * @param string    $unit_type_label Unit type label; empty = do not overwrite unit meta keys.
	 * @param int       $plot_id        Plot ID for line meta.
	 * @return void
	 */
	public static function sync_order_line_after_booking_admin_edit( $order, $line_item_id, $check_in_ymd, $check_out_ymd, array $guests, $unit_type_label, $plot_id ) {
		if ( ! $order instanceof \WC_Order || $line_item_id <= 0 ) {
			return;
		}
		$item = $order->get_item( $line_item_id );
		if ( ! $item || ! is_callable( array( $item, 'update_meta_data' ) ) ) {
			return;
		}
		$pid = (int) $item->get_product_id();
		if ( ! self::is_campsite_product( $pid ) ) {
			return;
		}

		$guests = self::normalize_guests_for_storage( $guests );
		$item->update_meta_data( self::META_CHECK_IN, (string) $check_in_ymd );
		$item->update_meta_data( self::META_CHECK_OUT, (string) $check_out_ymd );
		$item->update_meta_data( self::META_GUESTS, wp_json_encode( $guests ) );
		$item->update_meta_data( self::META_PLOT_ID, (string) (int) $plot_id );

		$cin = Dates::parse_ymd( (string) $check_in_ymd );
		$cout = Dates::parse_ymd( (string) $check_out_ymd );
		if ( $cin && $cout ) {
			$mwb_from = $cin->format( 'd-m-Y' );
			$mwb_to   = $cout->format( 'd-m-Y' );
			$item->update_meta_data( '_mwb_bfwp_date_time_from', $mwb_from );
			$item->update_meta_data( '_mwb_bfwp_date_time_to', $mwb_to );
			$item->update_meta_data( '_wps_single_cal_date_time_from', $mwb_from );
			$item->update_meta_data( '_wps_single_cal_date_time_to', $mwb_to );
		}

		$unit_type_label = sanitize_text_field( Request_String::scalar( $unit_type_label ) );
		if ( '' !== $unit_type_label ) {
			$item->update_meta_data( 'Unit type', $unit_type_label );
			if ( function_exists( 'vila_akoya_vehicle_label' ) ) {
				$item->update_meta_data( __( 'Unit type', 'vila-akoya' ), $unit_type_label );
			}
		}

		if ( is_callable( array( $item, 'save' ) ) ) {
			$item->save();
		}
	}

	/**
	 * Guest counts for admin calendar: booking row JSON, else order line meta.
	 *
	 * @param string|null    $guest_json From wp_vila_bookings.guest_json.
	 * @param \WC_Order|null $order      Order object.
	 * @param int            $line_item_id Order line item ID.
	 * @return array{adults: int, children: int, infants: int, pets: int}
	 */
	public static function resolve_guest_counts_for_booking( $guest_json, $order, $line_item_id ) {
		$raw = is_string( $guest_json ) ? $guest_json : '';
		if ( '' === $raw && $order instanceof \WC_Order && $line_item_id > 0 ) {
			$item = $order->get_item( $line_item_id );
			if ( $item && is_callable( array( $item, 'get_meta' ) ) ) {
				$raw = (string) $item->get_meta( self::META_GUESTS, true );
			}
		}
		if ( '' === $raw ) {
			return self::normalize_guests_for_storage( array() );
		}
		$decoded = json_decode( $raw, true );
		return self::normalize_guests_for_storage( is_array( $decoded ) ? $decoded : array() );
	}

	/**
	 * Single-line guest + pet breakdown for the admin occupancy grid.
	 *
	 * @param array<string, mixed> $guests Normalized guest counts.
	 * @return string
	 */
	public static function format_guest_counts_calendar_line( array $guests ) {
		$g = self::normalize_guests_for_storage( $guests );
		$a = (int) $g['adults'];
		$c = (int) $g['children'];
		$i = (int) $g['infants'];
		$p = (int) $g['pets'];
		$parts = array();
		if ( $a > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: adult count */
				_n( '%d adult', '%d adults', $a, 'vila-campsite-booking' ),
				$a
			);
		}
		if ( $c > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: child count */
				_n( '%d child', '%d children', $c, 'vila-campsite-booking' ),
				$c
			);
		}
		if ( $i > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: infant count */
				_n( '%d infant', '%d infants', $i, 'vila-campsite-booking' ),
				$i
			);
		}
		if ( $p > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: pet count */
				_n( '%d pet', '%d pets', $p, 'vila-campsite-booking' ),
				$p
			);
		}
		return implode( __( ' · ', 'vila-campsite-booking' ), $parts );
	}

	/**
	 * Unit type options for the admin booking editor: slug => display label.
	 *
	 * Uses theme `vila_akoya_get_vehicle_types()` when available so options match checkout.
	 *
	 * @return array<string, string>
	 */
	public static function get_admin_vehicle_type_options() {
		if ( function_exists( 'vila_akoya_get_vehicle_types' ) ) {
			$opts = array();
			foreach ( vila_akoya_get_vehicle_types() as $slug => $row ) {
				if ( is_array( $row ) && isset( $row['label'] ) ) {
					$opts[ sanitize_key( (string) $slug ) ] = (string) $row['label'];
				}
			}
			if ( array() !== $opts ) {
				return $opts;
			}
		}
		return array(
			'tent'         => __( 'Tent', 'vila-campsite-booking' ),
			'caravan'      => __( 'Caravan', 'vila-campsite-booking' ),
			'camper'       => __( 'Camper van', 'vila-campsite-booking' ),
			'four_by_four' => __( '4×4 / off-road rig', 'vila-campsite-booking' ),
		);
	}

	/**
	 * Unit type label on the stay order line (e.g. Caravan), from line item meta.
	 *
	 * Vila Akoya theme stores `Unit type` via `woocommerce_checkout_create_order_line_item`.
	 * Filter `vila_cb_order_line_unit_type_label` can override or supply a value when meta is absent.
	 *
	 * @param \WC_Order|null $order          Order.
	 * @param int            $line_item_id   Campsite stay line item ID.
	 * @return string Display label or empty.
	 */
	public static function get_order_line_unit_type_label( $order, $line_item_id ) {
		$line_item_id = (int) $line_item_id;
		if ( ! $order instanceof \WC_Order || $line_item_id <= 0 ) {
			return '';
		}
		$item = $order->get_item( $line_item_id );
		if ( ! $item || ! is_callable( array( $item, 'get_meta' ) ) ) {
			return (string) apply_filters( 'vila_cb_order_line_unit_type_label', '', $order, $line_item_id, null );
		}

		$resolved = '';
		$keys     = array( 'Unit type' );
		if ( function_exists( 'vila_akoya_vehicle_label' ) ) {
			$keys[] = __( 'Unit type', 'vila-akoya' );
		}
		$keys = array_unique( array_filter( $keys ) );
		foreach ( $keys as $key ) {
			$v = $item->get_meta( $key, true );
			if ( is_string( $v ) && '' !== trim( $v ) ) {
				$resolved = trim( $v );
				break;
			}
		}
		if ( '' === $resolved && is_callable( array( $item, 'read_meta_data' ) ) ) {
			$item->read_meta_data( true );
		}
		if ( '' === $resolved && is_callable( array( $item, 'get_meta_data' ) ) ) {
			foreach ( $item->get_meta_data() as $m ) {
				$k = is_object( $m ) && isset( $m->key ) ? (string) $m->key : '';
				if ( '' === $k ) {
					continue;
				}
				if ( preg_match( '/unit/i', $k ) && preg_match( '/type/i', $k ) ) {
					$val = is_object( $m ) && isset( $m->value ) ? $m->value : '';
					if ( is_string( $val ) && '' !== trim( $val ) ) {
						$resolved = trim( $val );
						break;
					}
				}
			}
		}

		$filtered = apply_filters( 'vila_cb_order_line_unit_type_label', $resolved, $order, $line_item_id, $item );
		if ( is_string( $filtered ) ) {
			return $filtered;
		}
		if ( is_scalar( $filtered ) && ! is_bool( $filtered ) ) {
			return (string) $filtered;
		}
		return '';
	}

	/**
	 * @param array $guests Guests.
	 * @return string
	 */
	private static function guest_summary( array $guests ) {
		$guests = self::normalize_guests_for_storage( $guests );
		$a = isset( $guests['adults'] ) ? (int) $guests['adults'] : 1;
		$c = isset( $guests['children'] ) ? (int) $guests['children'] : 0;
		$i = isset( $guests['infants'] ) ? (int) $guests['infants'] : 0;
		$p = isset( $guests['pets'] ) ? (int) $guests['pets'] : 0;
		$people = $a + $c + $i;
		$parts  = array();
		if ( 1 === $people ) {
			$parts[] = __( '1 guest', 'vila-campsite-booking' );
		} else {
			$parts[] = sprintf( /* translators: %d people */ __( '%d guests', 'vila-campsite-booking' ), $people );
		}
		if ( $p > 0 ) {
			$parts[] = sprintf( /* translators: %d pets */ __( '%d pets', 'vila-campsite-booking' ), $p );
		}
		return implode( ' · ', $parts );
	}

	/**
	 * @param array<string, mixed> $item_data Item data.
	 * @param array<string, mixed> $cart_item Cart item.
	 * @return array<string, mixed>
	 */
	public static function display_cart_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item['product_id'] ) || ! self::is_campsite_product( (int) $cart_item['product_id'] ) ) {
			return $item_data;
		}
		if ( ! is_array( $item_data ) ) {
			$item_data = array();
		}

		$stay_label   = __( 'Stay', 'vila-campsite-booking' );
		$guests_label = __( 'Guests', 'vila-campsite-booking' );
		$filtered     = array();
		foreach ( $item_data as $row ) {
			if ( ! is_array( $row ) ) {
				$filtered[] = $row;
				continue;
			}
			$name = isset( $row['name'] ) ? trim( wp_strip_all_tags( (string) $row['name'] ) ) : '';
			// MWB Bookings and other extensions often add "Guests" / "Stay" lines; blocks key by name+display → duplicates.
			if ( $name === $stay_label || $name === $guests_label || 'Stay' === $name || 'Guests' === $name ) {
				continue;
			}
			$filtered[] = $row;
		}
		$item_data = $filtered;

		$merged = self::cart_item_flattened_for_stay_check( $cart_item );
		$cin    = isset( $merged['vila_cb_check_in'] ) ? trim( (string) $merged['vila_cb_check_in'] ) : '';
		$cout   = isset( $merged['vila_cb_check_out'] ) ? trim( (string) $merged['vila_cb_check_out'] ) : '';
		if ( self::validate_date_ymd( $cin ) && self::validate_date_ymd( $cout ) ) {
			$item_data[] = array(
				'key'     => 'vila_cb_stay',
				'name'    => $stay_label,
				'display' => esc_html( self::format_stay_range_display( $cin, $cout ) ),
			);
		} else {
			$item_data[] = array(
				'key'     => 'vila_cb_stay',
				'name'    => $stay_label,
				'display' => esc_html__( '(dates not set — booking may fail at checkout)', 'vila-campsite-booking' ),
			);
		}
		$guests = isset( $merged['vila_cb_guests'] ) && is_array( $merged['vila_cb_guests'] ) ? $merged['vila_cb_guests'] : array();
		$g      = self::guest_summary( $guests );
		if ( $g ) {
			$item_data[] = array(
				'key'     => 'vila_cb_guests',
				'name'    => $guests_label,
				'display' => esc_html( $g ),
			);
		}
		return $item_data;
	}

	/**
	 * Flatten Store API `extensions` namespaces onto cart data for validation (Checkout block).
	 *
	 * @param array<string, mixed> $cart_item Cart line.
	 * @return array<string, mixed>
	 */
	private static function cart_item_flattened_for_stay_check( array $cart_item ) {
		$merged = $cart_item;
		if ( empty( $cart_item['extensions'] ) || ! is_array( $cart_item['extensions'] ) ) {
			return $merged;
		}
		// Do not let empty top-level keys wipe dates that only exist under extensions (Checkout block / Store API).
		foreach ( $cart_item['extensions'] as $blob ) {
			if ( ! is_array( $blob ) ) {
				continue;
			}
			foreach ( $blob as $k => $v ) {
				$cur = isset( $merged[ $k ] ) ? $merged[ $k ] : null;
				$cur_empty = null === $cur
					|| ( is_string( $cur ) && '' === trim( $cur ) )
					|| ( is_array( $cur ) && array() === $cur );
				if ( ! $cur_empty ) {
					continue;
				}
				if ( is_string( $v ) && '' !== trim( $v ) ) {
					$merged[ $k ] = $v;
				} elseif ( is_array( $v ) && array() !== $v ) {
					$merged[ $k ] = $v;
				}
			}
		}
		return $merged;
	}

	/**
	 * Whether a cart line has vila dates or MWB booking values that resolve to valid Y-m-d dates.
	 *
	 * @param array<string, mixed> $cart_item Cart line.
	 * @return bool
	 */
	private static function cart_line_has_stay_dates( array $cart_item ) {
		$cart_item = self::cart_item_flattened_for_stay_check( $cart_item );
		$cin  = isset( $cart_item['vila_cb_check_in'] ) ? trim( (string) $cart_item['vila_cb_check_in'] ) : '';
		$cout = isset( $cart_item['vila_cb_check_out'] ) ? trim( (string) $cart_item['vila_cb_check_out'] ) : '';
		if ( self::validate_date_ymd( $cin ) && self::validate_date_ymd( $cout ) ) {
			return true;
		}
		// Session / edge cases: non–Y-m-d strings that still parse (e.g. site date format).
		if ( '' !== $cin && '' !== $cout ) {
			$pc = self::parse_mwb_date_to_ymd( $cin );
			$pt = self::parse_mwb_date_to_ymd( $cout );
			if ( self::validate_date_ymd( $pc ) && self::validate_date_ymd( $pt ) && $pt > $pc ) {
				return true;
			}
		}
		$bv = isset( $cart_item['mwb_mbfw_booking_values'] ) && is_array( $cart_item['mwb_mbfw_booking_values'] ) ? $cart_item['mwb_mbfw_booking_values'] : null;
		if ( ! is_array( $bv ) ) {
			return false;
		}
		$pairs = array(
			array( 'date_time_from', 'date_time_to' ),
			array( 'single_cal_date_time_from', 'single_cal_date_time_to' ),
		);
		foreach ( $pairs as $pair ) {
			$f = isset( $bv[ $pair[0] ] ) ? $bv[ $pair[0] ] : '';
			$t = isset( $bv[ $pair[1] ] ) ? $bv[ $pair[1] ] : '';
			if ( '' === trim( (string) $f ) || '' === trim( (string) $t ) ) {
				continue;
			}
			$c1 = self::parse_mwb_date_to_ymd( $f );
			$c2 = self::parse_mwb_date_to_ymd( $t );
			if ( self::validate_date_ymd( $c1 ) && self::validate_date_ymd( $c2 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolved Y-m-d stay for inventory checks (same sources as cart_line_has_stay_dates).
	 *
	 * @param array<string, mixed> $cart_item Cart line.
	 * @return array{check_in: string, check_out: string}|null
	 */
	private static function cart_item_resolve_stay_ymd( array $cart_item ) {
		$cart_item = self::cart_item_flattened_for_stay_check( $cart_item );
		$cin       = isset( $cart_item['vila_cb_check_in'] ) ? trim( (string) $cart_item['vila_cb_check_in'] ) : '';
		$cout      = isset( $cart_item['vila_cb_check_out'] ) ? trim( (string) $cart_item['vila_cb_check_out'] ) : '';
		if ( self::validate_date_ymd( $cin ) && self::validate_date_ymd( $cout ) && $cout > $cin ) {
			return array(
				'check_in'  => $cin,
				'check_out' => $cout,
			);
		}
		if ( '' !== $cin && '' !== $cout ) {
			$pc = self::parse_mwb_date_to_ymd( $cin );
			$pt = self::parse_mwb_date_to_ymd( $cout );
			if ( self::validate_date_ymd( $pc ) && self::validate_date_ymd( $pt ) && $pt > $pc ) {
				return array(
					'check_in'  => $pc,
					'check_out' => $pt,
				);
			}
		}
		$bv = isset( $cart_item['mwb_mbfw_booking_values'] ) && is_array( $cart_item['mwb_mbfw_booking_values'] ) ? $cart_item['mwb_mbfw_booking_values'] : null;
		if ( ! is_array( $bv ) ) {
			return null;
		}
		$pairs = array(
			array( 'date_time_from', 'date_time_to' ),
			array( 'single_cal_date_time_from', 'single_cal_date_time_to' ),
		);
		foreach ( $pairs as $pair ) {
			$f = isset( $bv[ $pair[0] ] ) ? $bv[ $pair[0] ] : '';
			$t = isset( $bv[ $pair[1] ] ) ? $bv[ $pair[1] ] : '';
			if ( '' === trim( (string) $f ) || '' === trim( (string) $t ) ) {
				continue;
			}
			$c1 = self::parse_mwb_date_to_ymd( $f );
			$c2 = self::parse_mwb_date_to_ymd( $t );
			if ( self::validate_date_ymd( $c1 ) && self::validate_date_ymd( $c2 ) && $c2 > $c1 ) {
				return array(
					'check_in'  => $c1,
					'check_out' => $c2,
				);
			}
		}
		return null;
	}

	/**
	 * Block checkout when a campsite line has no resolvable stay dates (prevents empty meta / failed finalize).
	 */
	public static function validate_campsite_cart_has_stay_data() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}
		$svc = new Booking_Service();
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$pid = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			$vid = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
			if ( ! self::is_campsite_product( $pid ) && ! ( $vid && self::is_campsite_product( $vid ) ) ) {
				continue;
			}
			if ( ! self::cart_line_has_stay_dates( $cart_item ) ) {
				$campsite_dump = array();
				foreach ( WC()->cart->get_cart() as $ck => $ci ) {
					if ( ! is_array( $ci ) ) {
						continue;
					}
					$p = isset( $ci['product_id'] ) ? (int) $ci['product_id'] : 0;
					$w = isset( $ci['variation_id'] ) ? (int) $ci['variation_id'] : 0;
					if ( ! self::is_campsite_product( $p ) && ! ( $w && self::is_campsite_product( $w ) ) ) {
						continue;
					}
					$campsite_dump[] = array_merge(
						array( 'cart_key' => (string) $ck ),
						self::diagnose_cart_stay_for_log( $ci )
					);
				}
				self::log_stay_gate_failure(
					'woocommerce_checkout_process',
					array(
						'request_uri'     => isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( Request_String::scalar( $_SERVER['REQUEST_URI'] ) ) : '',
						'campsite_lines'  => $campsite_dump,
						'failing_product' => $pid,
					)
				);
				self::debug_log(
					'validate_campsite_cart_has_stay_data: blocked (classic checkout)',
					array_merge( array( 'product_id' => $pid ), self::cart_item_debug_snapshot( $cart_item ) )
				);
				wc_add_notice(
					__( 'Please choose check-in and check-out dates for your campsite booking. Use the booking form on the product page, or return to the cart and add the stay again.', 'vila-campsite-booking' ),
					'error'
				);
				return;
			}
			$stay = self::cart_item_resolve_stay_ymd( $cart_item );
			if ( null !== $stay && null === $svc->find_available_plot( $stay['check_in'], $stay['check_out'] ) ) {
				wc_add_notice(
					__( 'No pitch is available for your selected dates. Please choose different dates or contact us.', 'vila-campsite-booking' ),
					'error'
				);
				return;
			}
		}
	}

	/**
	 * Hide internal campsite meta keys from order item meta lists (emails, etc.).
	 *
	 * @param string[] $hidden Hidden meta keys.
	 * @return string[]
	 */
	public static function hidden_order_itemmeta( $hidden ) {
		if ( ! is_array( $hidden ) ) {
			$hidden = array();
		}
		$hidden[] = self::META_CHECK_IN;
		$hidden[] = self::META_CHECK_OUT;
		$hidden[] = self::META_GUESTS;
		$hidden[] = self::META_BOOKING_ID;
		$hidden[] = self::META_PLOT_ID;
		return $hidden;
	}

	/**
	 * Replace raw _vila_cb_* line meta with readable Stay / Guests rows in admin and customer views.
	 *
	 * @param array<int, object>     $formatted_meta Meta rows.
	 * @param \WC_Order_Item_Product $item Order line item.
	 * @return array<int, object>
	 */
	public static function order_item_formatted_meta_data( $formatted_meta, $item ) {
		if ( ! is_object( $item ) || ! is_callable( array( $item, 'get_product_id' ) ) ) {
			return $formatted_meta;
		}
		if ( ! self::is_campsite_product( (int) $item->get_product_id() ) ) {
			return $formatted_meta;
		}
		if ( ! is_array( $formatted_meta ) ) {
			$formatted_meta = array();
		}
		$strip = array(
			self::META_CHECK_IN,
			self::META_CHECK_OUT,
			self::META_GUESTS,
			self::META_BOOKING_ID,
			self::META_PLOT_ID,
		);
		$stay_label   = __( 'Stay', 'vila-campsite-booking' );
		$guests_label = __( 'Guests', 'vila-campsite-booking' );
		$out          = array();
		foreach ( $formatted_meta as $m ) {
			$key = is_object( $m ) && isset( $m->key ) ? (string) $m->key : '';
			if ( in_array( $key, $strip, true ) ) {
				continue;
			}
			if ( is_string( $key ) && 0 === strpos( $key, '_vila_cb_' ) ) {
				continue;
			}
			// Older plugin versions stored a duplicate human-readable Guests row; we replace it below.
			if ( 'Guests' === $key || $guests_label === $key ) {
				continue;
			}
			// Blocks (React) key rows by label+value; drop any other Stay/Guests lines so we do not duplicate ours.
			$dk = is_object( $m ) && isset( $m->display_key ) ? trim( (string) $m->display_key ) : '';
			if ( '' !== $dk ) {
				if ( $dk === $stay_label || $dk === 'Stay' ) {
					continue;
				}
				if ( $dk === $guests_label || $dk === 'Guests' ) {
					continue;
				}
			}
			$out[] = $m;
		}
		$line_id = (int) $item->get_id();
		$stay    = self::line_item_resolve_stay( $item );
		if ( $stay ) {
			$out[] = (object) array(
				'id'            => 'vila-cb-stay-' . $line_id,
				'key'           => '_vila_cb_display_stay',
				'value'         => $stay['check_in'] . '|' . $stay['check_out'],
				'display_key'   => $stay_label,
				'display_value' => wc_clean( $stay['check_in'] . ' → ' . $stay['check_out'] ),
			);
		}
		$gj = (string) $item->get_meta( self::META_GUESTS, true );
		$guests = array();
		if ( '' !== $gj ) {
			$decoded = json_decode( $gj, true );
			if ( is_array( $decoded ) ) {
				$guests = $decoded;
			}
		}
		$gtext = self::guest_summary( $guests );
		if ( $gtext ) {
			$out[] = (object) array(
				'id'            => 'vila-cb-guests-' . $line_id,
				'key'           => '_vila_cb_display_guests',
				'value'         => $gtext,
				'display_key'   => $guests_label,
				'display_value' => wc_clean( $gtext ),
			);
		}
		return $out;
	}

	/**
	 * When an order moves into a “live” status, ensure campsite rows exist (bulk edits, edge gateways).
	 *
	 * @param int        $order_id Order ID.
	 * @param string     $old_status Old status.
	 * @param string     $new_status New status.
	 * @param \WC_Order|null $order Order object.
	 */
	public static function on_order_status_changed_finalize( $order_id, $old_status, $new_status, $order ) {
		unset( $old_status, $order );
		if ( ! in_array( $new_status, array( 'processing', 'completed', 'on-hold' ), true ) ) {
			return;
		}
		self::maybe_finalize_bookings( $order_id );
	}

	/**
	 * Create booking rows after payment / processing.
	 *
	 * @param int|\WC_Order $order_id Order ID or order object.
	 */
	public static function maybe_finalize_bookings( $order_id ) {
		if ( $order_id instanceof \WC_Order ) {
			$order = $order_id;
		} else {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		$svc                 = new Booking_Service();
		$missing_stay_lines  = array();
		$order_needs_save    = false;

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			unset( $item_id );
			if ( ! is_object( $item ) || ! is_callable( array( $item, 'get_product_id' ) ) ) {
				continue;
			}
			$line_id = (int) $item->get_id();
			$item    = $order->get_item( $line_id );
			if ( ! $item || ! is_callable( array( $item, 'get_product_id' ) ) ) {
				continue;
			}

			$pid = (int) $item->get_product_id();
			if ( ! self::is_campsite_product( $pid ) ) {
				continue;
			}
			$existing = $item->get_meta( self::META_BOOKING_ID, true );
			if ( $existing ) {
				continue;
			}
			$stay = self::line_item_resolve_stay( $item );
			if ( ! $stay ) {
				$missing_stay_lines[] = $line_id;
				continue;
			}
			$cin        = $stay['check_in'];
			$cout       = $stay['check_out'];
			$guest_json = $stay['guest_json'];
			$res        = $svc->confirm_booking_atomic( (int) $order->get_id(), $line_id, $cin, $cout, $guest_json ? $guest_json : null );
			if ( ! empty( $res['success'] ) && ! empty( $res['booking_id'] ) ) {
				$item->update_meta_data( self::META_BOOKING_ID, (string) $res['booking_id'] );
				$item->update_meta_data( self::META_PLOT_ID, (string) (int) $res['plot_id'] );
				$item->save();
			} else {
				$order->add_order_note( isset( $res['message'] ) ? (string) $res['message'] : __( 'Campsite booking could not be confirmed (inventory).', 'vila-campsite-booking' ) );
			}
		}

		if ( ! empty( $missing_stay_lines ) && ! $order->get_meta( '_vila_cb_stay_warned', true ) ) {
			$ids = implode( ', ', array_map( 'strval', $missing_stay_lines ) );
			$note = sprintf(
				/* translators: %s: comma-separated line item IDs */
				__( 'Campsite booking: could not read stay dates on line item(s) %s. Open the order line in WooCommerce and confirm MWB/booking meta exists, or re-checkout using the [vila_campsite_booking] form.', 'vila-campsite-booking' ),
				$ids
			);

			$debug_chunks = array();
			foreach ( $missing_stay_lines as $lid ) {
				$it = $order->get_item( (int) $lid );
				if ( $it && is_callable( array( $it, 'get_product_id' ) ) ) {
					$detail = self::line_item_stay_debug_text( $it );
					$debug_chunks[] = $detail;
					self::debug_log(
						'maybe_finalize_bookings: could not resolve stay',
						array(
							'order_id' => $order->get_id(),
							'line_id'  => (int) $lid,
							'detail'   => strlen( $detail ) > 1500 ? substr( $detail, 0, 1500 ) . '…' : $detail,
						)
					);
				}
			}
			if ( ! empty( $debug_chunks ) ) {
				$note .= "\n\n";
				$note .= __( 'Debug (vila-campsite-booking):', 'vila-campsite-booking' );
				$note .= "\n";
				$note .= implode( "\n---\n", $debug_chunks );
			}

			$note = apply_filters( 'vila_cb_stay_missing_order_note', $note, $order, $missing_stay_lines );
			$order->add_order_note( $note );
			$order->update_meta_data( '_vila_cb_stay_warned', '1' );
			$order_needs_save = true;
		}
		if ( $order_needs_save ) {
			$order->save();
		}
	}

	/**
	 * @param int $order_id Order ID.
	 */
	public static function release_bookings( $order_id ) {
		$svc = new Booking_Service();
		$svc->release_order_bookings( (int) $order_id );
	}
}
