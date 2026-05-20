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
	 * Order IDs where the top-of-order campsite extras chip panel was rendered (skip duplicate UI under each line).
	 *
	 * @var array<int, true>
	 */
	private static $order_admin_extras_panel_rendered = array();

	/**
	 * If Campsite → Settings was never saved, map the Vila Akoya theme's seeded campsite product (MWB plot products).
	 *
	 * Runs after the theme's wp_loaded seed (priority 20).
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
		// Run after MWB and other extensions so campsite line totals match Campsite → Settings (adult override, guest rates).
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'before_calculate_totals' ), 100000, 1 );
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
		add_filter( 'woocommerce_order_item_name', array( __CLASS__, 'order_item_name' ), 20, 3 );
		add_filter( 'woocommerce_is_sold_individually', array( __CLASS__, 'campsite_sold_individually' ), 10, 2 );
		add_filter( 'woocommerce_quantity_input_args', array( __CLASS__, 'campsite_quantity_input_args' ), 10, 2 );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_campsite_cart_has_stay_data' ), 10, 0 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_order_itemmeta' ), 10, 1 );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( __CLASS__, 'order_item_formatted_meta_data' ), 20, 2 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'render_order_admin_extras_after_order_details' ), 10, 1 );
		add_action( 'woocommerce_admin_order_data_after_payment_info', array( __CLASS__, 'render_order_admin_extras_after_order_details' ), 10, 1 );
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'render_order_item_admin_extras' ), 15, 3 );
		add_action( 'woocommerce_saved_order_items', array( __CLASS__, 'save_order_admin_item_extras' ), 99, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_order_edit_admin_assets' ), 10, 1 );

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
	 * - On-screen extras panel on Edit booking: filter `vila_cb_booking_edit_extras_debug` (defaults on when `WP_DEBUG_LOG` is on).
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
	 * Public wrapper for opt-in debug (e.g. Admin booking UI cannot call private debug_log).
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public static function log_debug( $message, array $context = array() ) {
		self::debug_log( (string) $message, $context );
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
		// Vila Akoya theme: one WC product per plot (SKU vila-akoya-plot-N); same inventory as this plugin's plots table.
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

		$preferred_plot = 0;
		if ( ! empty( $_POST['vila_cb_preferred_plot_id'] ) ) {
			$preferred_plot = absint( $_POST['vila_cb_preferred_plot_id'] );
		} elseif ( ! empty( $_POST['vila_cb_preferred_plot'] ) ) {
			$preferred_plot = absint( $_POST['vila_cb_preferred_plot'] );
		}

		if ( $preferred_plot > 0 ) {
			$plot_repo = new Plot_Repository();
			if ( ! $plot_repo->is_bookable_plot_id( $preferred_plot ) ) {
				wc_add_notice( __( 'That pitch cannot be selected online. Please choose another pitch or use auto-assign.', 'vila-campsite-booking' ), 'error' );
				return false;
			}
			if ( ! $svc->is_plot_available_for_range( $preferred_plot, $check_in, $check_out ) ) {
				wc_add_notice( __( 'The selected pitch is not available for those dates. Please choose a different pitch or different dates.', 'vila-campsite-booking' ), 'error' );
				return false;
			}
		} elseif ( null === $svc->find_available_plot( $check_in, $check_out ) ) {
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
			if ( apply_filters( 'vila_cb_show_notice_cart_cleared_for_booking', false ) ) {
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
		if ( ! is_string( $name ) ) {
			$name = (string) $name;
		}
		if ( ! is_array( $cart_item ) ) {
			return $name;
		}
		$pid = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
		$vid = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
		if ( ! self::is_campsite_product( $pid ) && ! ( $vid && self::is_campsite_product( $vid ) ) ) {
			return $name;
		}
		$merged = self::cart_item_flattened_for_stay_check( $cart_item );
		$plot_id = isset( $merged['vila_cb_preferred_plot'] ) ? (int) $merged['vila_cb_preferred_plot'] : 0;
		if ( $plot_id <= 0 && isset( $merged['vila_cb_plot_id'] ) ) {
			$plot_id = (int) $merged['vila_cb_plot_id'];
		}
		if ( $plot_id > 0 ) {
			$plot_row   = ( new Plot_Repository() )->get( $plot_id );
			$plot_label = ( is_array( $plot_row ) && ! empty( $plot_row['label'] ) ) ? (string) $plot_row['label'] : (string) $plot_id;
			$count      = 0;
			$replaced   = preg_replace( '/Plot\s+\d+\b/iu', $plot_label, $name, 1, $count );
			if ( is_string( $replaced ) && $count > 0 ) {
				$name = $replaced;
			}
		}
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
	 * Replace campsite order line name with assigned plot label (instead of base product title).
	 *
	 * @param string          $item_name Rendered item name HTML.
	 * @param \WC_Order_Item  $item      Order item.
	 * @param bool            $is_visible Visibility flag.
	 * @return string
	 */
	public static function order_item_name( $item_name, $item, $is_visible ) {
		unset( $is_visible );
		if ( ! is_object( $item ) || ! is_callable( array( $item, 'get_product_id' ) ) ) {
			return $item_name;
		}
		$pid = (int) $item->get_product_id();
		if ( ! self::is_campsite_product( $pid ) ) {
			return $item_name;
		}
		$plot_id = (int) $item->get_meta( self::META_PLOT_ID, true );
		if ( $plot_id <= 0 ) {
			$plot_id = (int) $item->get_meta( '_vila_cb_preferred_plot', true );
		}
		$label = '';
		if ( $plot_id > 0 ) {
			$row = ( new Plot_Repository() )->get( $plot_id );
			if ( is_array( $row ) && ! empty( $row['label'] ) ) {
				$label = (string) $row['label'];
			}
		}
		$base = __( 'Campsite booking', 'vila-campsite-booking' );
		if ( '' !== $label ) {
			return esc_html( $base . ' — ' . $label );
		}
		return esc_html( $base );
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
		// MWB uses gmdate( get_option( 'date_format' ), … ) — match the site's Settings → General date format.
		$wp_df = (string) get_option( 'date_format' );
		$wp_tf = (string) get_option( 'time_format' );
		foreach ( array_filter( array( $wp_df, trim( $wp_df . ' ' . $wp_tf ) ) ) as $fmt ) {
			$dt = \DateTime::createFromFormat( $fmt, $raw, wp_timezone() );
			if ( $dt instanceof \DateTimeInterface ) {
				return $dt->format( 'Y-m-d' );
			}
		}
		// Last resort: PHP's natural-language parser (handles many localized month/day strings MWB emits).
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

		$preferred_plot = 0;
		if ( ! empty( $_POST['vila_cb_preferred_plot_id'] ) ) {
			$preferred_plot = absint( $_POST['vila_cb_preferred_plot_id'] );
		} elseif ( ! empty( $_POST['vila_cb_preferred_plot'] ) ) {
			$preferred_plot = absint( $_POST['vila_cb_preferred_plot'] );
		}
		if ( $preferred_plot > 0 ) {
			$cart_item_data['vila_cb_preferred_plot'] = $preferred_plot;
		}

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
		foreach ( array( 'vila_cb_check_in', 'vila_cb_check_out', 'vila_cb_guests', 'vila_cb_preferred_plot' ) as $k ) {
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
			// MWB-only cart rows may omit vila_cb_guests; use adults from mwb_mbfw_booking_values so per-person totals match the booking form.
			if ( ( ! isset( $guests['adults'] ) || (int) $guests['adults'] < 1 ) && ! empty( $cart_item['mwb_mbfw_booking_values']['people_number'] ) ) {
				$guests['adults'] = max( 1, (int) $cart_item['mwb_mbfw_booking_values']['people_number'] );
			}
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

		$preferred_plot = isset( $merged['vila_cb_preferred_plot'] ) ? (int) $merged['vila_cb_preferred_plot'] : 0;
		if ( $preferred_plot > 0 ) {
			$item->update_meta_data( '_vila_cb_preferred_plot', (string) $preferred_plot );
		}

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
		if ( ! empty( $v['vila_cb_preferred_plot'] ) ) {
			$cid['vila_cb_preferred_plot'] = absint( $v['vila_cb_preferred_plot'] );
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
		if ( empty( $cart_item['vila_cb_preferred_plot'] ) && ! empty( $ext['vila_cb_preferred_plot'] ) ) {
			$cart_item['vila_cb_preferred_plot'] = absint( $ext['vila_cb_preferred_plot'] );
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
	 * Optionally preserves campsite extras: vehicle_slugs (string[]), extras_label (human-readable line).
	 *
	 * @param array<string, mixed> $guests Raw guest array.
	 * @return array<string, mixed>
	 */
	private static function normalize_guests_for_storage( $guests ) {
		$min_a = max( 1, (int) apply_filters( 'vila_cb_min_adults', 1 ) );
		if ( ! is_array( $guests ) ) {
			$guests = array();
		}
		$out = array(
			'adults'   => isset( $guests['adults'] ) ? max( $min_a, (int) $guests['adults'] ) : $min_a,
			'children' => isset( $guests['children'] ) ? max( 0, (int) $guests['children'] ) : 0,
			'infants'  => isset( $guests['infants'] ) ? max( 0, (int) $guests['infants'] ) : 0,
			'pets'     => isset( $guests['pets'] ) ? max( 0, (int) $guests['pets'] ) : 0,
		);
		if ( ! empty( $guests['vehicle_slugs'] ) && is_array( $guests['vehicle_slugs'] ) ) {
			$slugs = array_values( array_filter( array_map( 'sanitize_key', $guests['vehicle_slugs'] ) ) );
			if ( array() !== $slugs ) {
				$out['vehicle_slugs'] = $slugs;
			}
		}
		if ( isset( $guests['extras_label'] ) && is_string( $guests['extras_label'] ) && '' !== trim( $guests['extras_label'] ) ) {
			$out['extras_label'] = sanitize_text_field( $guests['extras_label'] );
		}
		return $out;
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
	 * Vehicle slugs from theme `_vila_vehicle_slugs` JSON on the order line.
	 *
	 * @param \WC_Order_Item $item Line item.
	 * @return string[]
	 */
	private static function get_vehicle_slugs_from_line_item( $item ) {
		if ( ! is_object( $item ) || ! is_callable( array( $item, 'get_meta' ) ) ) {
			return array();
		}
		// HPOS / lazy meta: ensure item meta is loaded before get_meta().
		if ( is_callable( array( $item, 'read_meta_data' ) ) ) {
			$item->read_meta_data( true );
		}
		$raw = $item->get_meta( '_vila_vehicle_slugs', true );
		$dec = null;
		if ( is_array( $raw ) ) {
			// WooCommerce may return decoded JSON as array (especially with HPOS / CRUD).
			$dec = $raw;
		} elseif ( is_string( $raw ) && '' !== $raw ) {
			$dec = json_decode( $raw, true );
		}
		if ( ! is_array( $dec ) ) {
			return array();
		}
		$out = array();
		foreach ( $dec as $s ) {
			$k = sanitize_key( is_string( $s ) ? $s : (string) $s );
			if ( '' !== $k ) {
				$out[] = $k;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * `_vila_vehicle_slugs` for the campsite stay line (same line resolution as get_order_line_unit_type_label).
	 *
	 * @param \WC_Order|null $order          Order.
	 * @param int            $line_item_id   Line ID, or 0 for first campsite line.
	 * @return string[]
	 */
	public static function get_order_line_vehicle_slugs_resolved( $order, $line_item_id ) {
		$line_item_id = (int) $line_item_id;
		if ( ! $order instanceof \WC_Order ) {
			return array();
		}
		if ( $line_item_id <= 0 ) {
			foreach ( $order->get_items( 'line_item' ) as $lid => $line ) {
				if ( ! is_object( $line ) || ! is_callable( array( $line, 'get_product_id' ) ) ) {
					continue;
				}
				if ( self::is_campsite_product( (int) $line->get_product_id() ) ) {
					$line_item_id = (int) $lid;
					break;
				}
			}
		}
		if ( $line_item_id <= 0 ) {
			return array();
		}
		$item = $order->get_item( $line_item_id );
		if ( ! $item ) {
			return array();
		}
		return self::get_vehicle_slugs_from_line_item( $item );
	}

	/**
	 * Vehicle slugs for the booking edit UI: guest_json first, then order line meta.
	 *
	 * @param string|null    $guest_json_string Booking guest_json.
	 * @param \WC_Order|null $order             Order.
	 * @param int            $line_item_id      Line item ID.
	 * @return string[]
	 */
	public static function get_vehicle_slugs_for_booking_edit( $guest_json_string, $order, $line_item_id ) {
		$dec = json_decode( (string) $guest_json_string, true );
		// If the key exists (even []), treat guest_json as authoritative — do not merge order line.
		if ( is_array( $dec ) && array_key_exists( 'vehicle_slugs', $dec ) && is_array( $dec['vehicle_slugs'] ) ) {
			return array_values( array_filter( array_map( 'sanitize_key', $dec['vehicle_slugs'] ) ) );
		}
		// No vehicle_slugs in DB row: use order line (HPOS-safe; see get_vehicle_slugs_from_line_item).
		return self::get_order_line_vehicle_slugs_resolved( $order, $line_item_id );
	}

	/**
	 * Human-readable extras line from slug list (for admin and order sync).
	 *
	 * @param string[] $slugs Sanitized slugs.
	 * @return string
	 */
	public static function extras_label_from_slugs( array $slugs ) {
		return self::labels_join_from_slugs( array_values( array_filter( array_map( 'sanitize_key', $slugs ) ) ) );
	}

	/**
	 * Human-readable extras from stored slug list.
	 *
	 * @param string[] $slugs Sanitized slugs.
	 * @return string
	 */
	private static function labels_join_from_slugs( array $slugs ) {
		if ( array() === $slugs ) {
			return '';
		}
		if ( function_exists( 'vila_akoya_vehicle_labels_join' ) ) {
			return (string) vila_akoya_vehicle_labels_join( $slugs );
		}
		$opts   = self::get_admin_vehicle_type_options();
		$labels = array();
		foreach ( $slugs as $slug ) {
			$labels[] = isset( $opts[ $slug ] ) ? $opts[ $slug ] : $slug;
		}
		return implode(
			/* translators: Separator between extras labels in a list. */
			__( ', ', 'vila-campsite-booking' ),
			$labels
		);
	}

	/**
	 * Merge guest counts JSON with extras from the order line when creating a booking row.
	 *
	 * @param string|null        $guest_json_string From line META_GUESTS or null.
	 * @param \WC_Order_Item     $item              Line item.
	 * @param \WC_Order          $order             Order.
	 * @param int                $line_item_id      Line item ID.
	 * @return string JSON.
	 */
	public static function merge_guest_json_with_line_extras( $guest_json_string, $item, $order, $line_item_id ) {
		$base = self::normalize_guests_for_storage( json_decode( (string) $guest_json_string, true ) ?: array() );
		if ( ! $item || ! is_callable( array( $item, 'get_meta' ) ) ) {
			return wp_json_encode( $base );
		}
		$slugs = self::get_vehicle_slugs_from_line_item( $item );
		if ( array() !== $slugs ) {
			$base['vehicle_slugs'] = $slugs;
		}
		$label = '';
		if ( $order instanceof \WC_Order && (int) $line_item_id > 0 ) {
			$label = self::get_order_line_unit_type_label( $order, (int) $line_item_id );
		}
		if ( '' !== $label ) {
			$base['extras_label'] = $label;
		} elseif ( array() !== $slugs ) {
			$joined = self::labels_join_from_slugs( $slugs );
			if ( '' !== $joined ) {
				$base['extras_label'] = $joined;
			}
		}
		$encoded = wp_json_encode( $base );
		self::debug_log(
			'extras.merge_guest_json_with_line_extras',
			array(
				'order_id'           => $order instanceof \WC_Order ? $order->get_id() : 0,
				'line_item_id'       => (int) $line_item_id,
				'input_guest_json_b' => strlen( (string) $guest_json_string ),
				'slugs_from_line'    => $slugs,
				'label_from_order'   => $label,
				'out_keys'           => array_keys( $base ),
				'out_b'              => strlen( (string) $encoded ),
			)
		);
		return $encoded;
	}

	/**
	 * Extras line for admin: booking guest_json first, then order line meta.
	 *
	 * @param string|null        $guest_json   wp_vila_bookings.guest_json.
	 * @param \WC_Order|null     $order        Order.
	 * @param int                $line_item_id Line item ID.
	 * @return string
	 */
	public static function get_booking_extras_display_label( $guest_json, $order, $line_item_id ) {
		$raw = is_string( $guest_json ) ? $guest_json : '';
		$dec = json_decode( $raw, true );
		$oid = $order instanceof \WC_Order ? $order->get_id() : 0;
		if ( is_array( $dec ) ) {
			if ( ! empty( $dec['extras_label'] ) && is_string( $dec['extras_label'] ) && '' !== trim( $dec['extras_label'] ) ) {
				$out = trim( $dec['extras_label'] );
				self::debug_log(
					'extras.get_booking_extras_display_label',
					array(
						'order_id'       => $oid,
						'line_item_id'   => (int) $line_item_id,
						'source'         => 'guest_json.extras_label',
						'guest_json_b'   => strlen( $raw ),
						'guest_json_keys'=> array_keys( $dec ),
						'result_len'     => strlen( $out ),
					)
				);
				return $out;
			}
			if ( ! empty( $dec['vehicle_slugs'] ) && is_array( $dec['vehicle_slugs'] ) ) {
				$slugs = array_values( array_filter( array_map( 'sanitize_key', $dec['vehicle_slugs'] ) ) );
				$joined = self::labels_join_from_slugs( $slugs );
				if ( '' !== $joined ) {
					self::debug_log(
						'extras.get_booking_extras_display_label',
						array(
							'order_id'        => $oid,
							'line_item_id'    => (int) $line_item_id,
							'source'          => 'guest_json.vehicle_slugs',
							'guest_json_b'    => strlen( $raw ),
							'slugs_resolved'  => $slugs,
							'result_len'      => strlen( $joined ),
						)
					);
					return $joined;
				}
			}
		}
		$fallback = (string) self::get_order_line_unit_type_label( $order, (int) $line_item_id );
		self::debug_log(
			'extras.get_booking_extras_display_label',
			array(
				'order_id'        => $oid,
				'line_item_id'    => (int) $line_item_id,
				'source'          => 'order_line_fallback',
				'guest_json_ok'   => is_array( $dec ),
				'guest_json_keys' => is_array( $dec ) ? array_keys( $dec ) : array(),
				'result_len'      => strlen( $fallback ),
			)
		);
		return $fallback;
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

		if ( ! empty( $guests['vehicle_slugs'] ) && is_array( $guests['vehicle_slugs'] ) ) {
			$item->update_meta_data( '_vila_vehicle_slugs', wp_json_encode( array_values( $guests['vehicle_slugs'] ) ) );
		} else {
			$item->update_meta_data( '_vila_vehicle_slugs', '[]' );
		}

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
		if ( '' === $unit_type_label && ! empty( $guests['extras_label'] ) && is_string( $guests['extras_label'] ) ) {
			$unit_type_label = sanitize_text_field( $guests['extras_label'] );
		}
		if ( '' !== $unit_type_label ) {
			$item->update_meta_data( 'Extras', $unit_type_label );
			$item->update_meta_data( 'Unit type', $unit_type_label );
			if ( function_exists( 'vila_akoya_vehicle_label' ) ) {
				$item->update_meta_data( __( 'Extras', 'vila-akoya' ), $unit_type_label );
				$item->update_meta_data( __( 'Unit type', 'vila-akoya' ), $unit_type_label );
			}
		} else {
			foreach ( array( 'Extras', 'Unit type' ) as $ek ) {
				if ( is_callable( array( $item, 'delete_meta_data' ) ) ) {
					$item->delete_meta_data( $ek );
				}
			}
			if ( function_exists( 'vila_akoya_vehicle_label' ) ) {
				$tl_ex = __( 'Extras', 'vila-akoya' );
				$tl_ut = __( 'Unit type', 'vila-akoya' );
				if ( is_callable( array( $item, 'delete_meta_data' ) ) ) {
					$item->delete_meta_data( $tl_ex );
					$item->delete_meta_data( $tl_ut );
				}
			}
		}

		if ( is_callable( array( $item, 'save' ) ) ) {
			$item->save();
		}
	}

	/**
	 * Enqueue shared admin styles on WooCommerce order edit (HPOS + classic) for order-line extras checkboxes.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_order_edit_admin_assets( $hook_suffix ) {
		unset( $hook_suffix );
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! isset( $screen->id ) ) {
			return;
		}
		$allowed = array( 'woocommerce_page_wc-orders', 'shop_order' );
		if ( ! in_array( (string) $screen->id, $allowed, true ) ) {
			return;
		}
		wp_enqueue_style( 'vila-cb-admin-order-extras', VILA_CB_URL . 'assets/css/admin.css', array(), VILA_CB_VERSION );
	}

	/**
	 * WooCommerce order edit: multi-select extras (same slugs as Campsite → Edit booking).
	 *
	 * @param int                 $item_id Item ID.
	 * @param \WC_Order_Item      $item    Line item.
	 * @param \WC_Product|mixed   $product Product or null.
	 */
	public static function render_order_item_admin_extras( $item_id, $item, $product ) {
		unset( $product );
		if ( ! is_admin() || ! $item || ! is_callable( array( $item, 'get_product_id' ) ) ) {
			return;
		}
		if ( ! self::is_campsite_product( (int) $item->get_product_id() ) ) {
			return;
		}
		$item_id = (int) $item_id;
		$order_id = is_callable( array( $item, 'get_order_id' ) ) ? (int) $item->get_order_id() : 0;
		if ( $order_id <= 0 ) {
			return;
		}
		if ( isset( self::$order_admin_extras_panel_rendered[ $order_id ] ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		static $nonce_printed = array();
		$need_nonce = ! isset( $nonce_printed[ $order_id ] );
		if ( $need_nonce ) {
			$nonce_printed[ $order_id ] = true;
		}

		echo '<div class="vila-cb-order-item-extras">';
		echo '<p style="margin:0 0 8px;"><strong>' . esc_html__( 'Extras (unit types)', 'vila-campsite-booking' ) . '</strong></p>';
		echo '<p class="description" style="margin:0 0 10px;">' . esc_html__( 'Check all that apply. Saves when you update the order.', 'vila-campsite-booking' ) . '</p>';
		if ( $need_nonce ) {
			wp_nonce_field( 'vila_cb_save_order_extras', 'vila_cb_order_extras_nonce' );
		}
		self::echo_order_line_extras_chips( $order, $item_id, $item );
		echo '</div>';
	}

	/**
	 * Persist extras checkboxes from WooCommerce order edit.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $items    Posted item data (WC passes context).
	 */
	public static function save_order_admin_item_extras( $order_id, $items ) {
		unset( $items );
		if ( ! isset( $_POST['vila_cb_order_extras_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_order_extras_nonce'] ) ) ), 'vila_cb_save_order_extras' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$order_id = (int) $order_id;
		$order    = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$map = isset( $_POST['vila_cb_vehicle_slugs_order'] ) && is_array( $_POST['vila_cb_vehicle_slugs_order'] ) ? wp_unslash( $_POST['vila_cb_vehicle_slugs_order'] ) : array();
		if ( array() === $map ) {
			return;
		}
		$opts = self::get_admin_vehicle_type_options();

		foreach ( $map as $item_id => $raw_slugs ) {
			$item_id = (int) $item_id;
			if ( $item_id <= 0 ) {
				continue;
			}
			$item = $order->get_item( $item_id );
			if ( ! $item || ! self::is_campsite_product( (int) $item->get_product_id() ) ) {
				continue;
			}
			$initial = self::get_order_line_vehicle_slugs_resolved( $order, $item_id );
			$whitelist = array_unique( array_merge( array_keys( $opts ), $initial ) );
			$whitelist = array_values( array_filter( array_map( 'sanitize_key', $whitelist ) ) );

			$slug_set = array();
			if ( is_array( $raw_slugs ) ) {
				foreach ( $raw_slugs as $r ) {
					if ( ! is_string( $r ) && ! is_numeric( $r ) ) {
						continue;
					}
					$k = sanitize_key( (string) $r );
					if ( '' !== $k ) {
						$slug_set[ $k ] = true;
					}
				}
			}
			$vehicle_slugs = array_keys( $slug_set );
			$vehicle_slugs = array_values( array_intersect( $vehicle_slugs, $whitelist ) );
			sort( $vehicle_slugs );

			$guest_raw = $item->get_meta( self::META_GUESTS, true );
			$guest_arr = json_decode( is_string( $guest_raw ) ? $guest_raw : '', true );
			$guest_arr = self::normalize_guest_counts_array( is_array( $guest_arr ) ? $guest_arr : array() );
			if ( array() === $vehicle_slugs ) {
				unset( $guest_arr['vehicle_slugs'], $guest_arr['extras_label'] );
			} else {
				$guest_arr['vehicle_slugs'] = $vehicle_slugs;
				$guest_arr['extras_label']  = self::extras_label_from_slugs( $vehicle_slugs );
			}
			$label = self::extras_label_from_slugs( $vehicle_slugs );

			$check_in = (string) $item->get_meta( self::META_CHECK_IN, true );
			$check_out = (string) $item->get_meta( self::META_CHECK_OUT, true );
			$plot_id   = (int) $item->get_meta( self::META_PLOT_ID, true );

			self::sync_order_line_after_booking_admin_edit(
				$order,
				$item_id,
				$check_in,
				$check_out,
				$guest_arr,
				$label,
				$plot_id
			);

			$bid = (int) $item->get_meta( self::META_BOOKING_ID, true );
			if ( $bid > 0 ) {
				$repo = new Booking_Repository();
				$row  = $repo->get( $bid );
				if ( $row && 'confirmed' === (string) $row['status'] ) {
					$g = json_decode( (string) $row['guest_json'], true );
					if ( ! is_array( $g ) ) {
						$g = array();
					}
					if ( array() === $vehicle_slugs ) {
						unset( $g['vehicle_slugs'], $g['extras_label'] );
					} else {
						$g['vehicle_slugs']  = $vehicle_slugs;
						$g['extras_label'] = $label;
					}
					$repo->update(
						$bid,
						array(
							'guest_json' => wp_json_encode( $g ),
							'updated_at' => current_time( 'mysql' ),
						)
					);
				}
			}
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
				/* translators: %d: baby count */
				_n( '%d baby', '%d babies', $i, 'vila-campsite-booking' ),
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
			$types = vila_akoya_get_vehicle_types();
			if ( ! is_array( $types ) ) {
				$types = array();
			}
			$opts = array();
			foreach ( $types as $slug => $row ) {
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
			'camper'       => __( 'Campervan', 'vila-campsite-booking' ),
			'electricity'  => __( 'Include electricity', 'vila-campsite-booking' ),
			'car'          => __( 'Car', 'vila-campsite-booking' ),
		);
	}

	/**
	 * Meta keys to hide from WooCommerce's editable line-item meta UI (dropdown + text) while we manage extras via chips.
	 *
	 * @return string[]
	 */
	private static function extras_meta_keys_to_hide() {
		$keys = array( '_vila_vehicle_slugs', 'Extras', 'Unit type' );
		if ( function_exists( 'vila_akoya_get_vehicle_types' ) ) {
			$keys[] = __( 'Extras', 'vila-akoya' );
			$keys[] = __( 'Unit type', 'vila-akoya' );
		}
		return array_values( array_unique( array_map( 'strval', $keys ) ) );
	}

	/**
	 * Whether a formatted meta row is a duplicate extras / unit-type field we replace with a single “Extras” row.
	 *
	 * @param string $key         Meta key.
	 * @param string $display_key Display key.
	 * @return bool
	 */
	private static function should_strip_extra_meta_row( $key, $display_key ) {
		$key = (string) $key;
		foreach ( self::extras_meta_keys_to_hide() as $k ) {
			if ( $key === $k ) {
				return true;
			}
		}
		$dk = trim( (string) $display_key );
		if ( '' === $dk ) {
			return false;
		}
		$candidates = array( 'Extras', 'Unit type' );
		if ( function_exists( 'vila_akoya_get_vehicle_types' ) ) {
			$candidates[] = __( 'Extras', 'vila-akoya' );
			$candidates[] = __( 'Unit type', 'vila-akoya' );
		}
		return in_array( $dk, $candidates, true );
	}

	/**
	 * Unit type options with nightly fee for chip UI (matches theme checkout when available).
	 *
	 * @return array<string, array{ label: string, fee: float }>
	 */
	public static function get_admin_vehicle_type_options_with_fees() {
		$out = array();
		if ( function_exists( 'vila_akoya_get_vehicle_types' ) ) {
			$types = vila_akoya_get_vehicle_types();
			if ( is_array( $types ) ) {
				foreach ( $types as $slug => $row ) {
					if ( is_array( $row ) && isset( $row['label'] ) ) {
						$sk       = sanitize_key( (string) $slug );
						$out[ $sk ] = array(
							'label' => (string) $row['label'],
							'fee'   => isset( $row['fee'] ) ? (float) $row['fee'] : 0.0,
						);
					}
				}
			}
		}
		if ( array() !== $out ) {
			return $out;
		}
		foreach ( self::get_admin_vehicle_type_options() as $sk => $lab ) {
			$out[ $sk ] = array(
				'label' => (string) $lab,
				'fee'   => 0.0,
			);
		}
		return $out;
	}

	/**
	 * WooCommerce order edit: chip-style extras above line items (classic + HPOS when hook runs).
	 *
	 * @param \WC_Order|int|\WP_Post|null $order_or_arg Order object, ID, or post (depending on WC version).
	 */
	public static function render_order_admin_extras_after_order_details( $order_or_arg = null ) {
		if ( ! is_admin() ) {
			return;
		}
		$order = null;
		if ( $order_or_arg instanceof \WC_Order ) {
			$order = $order_or_arg;
		} elseif ( is_numeric( $order_or_arg ) ) {
			$order = wc_get_order( (int) $order_or_arg );
		}
		global $theorder;
		if ( ! $order instanceof \WC_Order && isset( $theorder ) && $theorder instanceof \WC_Order ) {
			$order = $theorder;
		}
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$order_id = (int) $order->get_id();
		if ( isset( self::$order_admin_extras_panel_rendered[ $order_id ] ) ) {
			return;
		}
		$has_campsite = false;
		foreach ( $order->get_items( 'line_item' ) as $line ) {
			if ( ! is_object( $line ) || ! is_callable( array( $line, 'get_product_id' ) ) ) {
				continue;
			}
			if ( self::is_campsite_product( (int) $line->get_product_id() ) ) {
				$has_campsite = true;
				break;
			}
		}
		if ( ! $has_campsite ) {
			return;
		}
		self::$order_admin_extras_panel_rendered[ $order_id ] = true;

		echo '<div class="vila-cb-order-extras-panel">';
		echo '<h3 class="vila-cb-order-extras-panel__title">' . esc_html__( 'Campsite extras', 'vila-campsite-booking' ) . '</h3>';
		echo '<p class="description" style="margin:0 0 12px;">' . esc_html__( 'Select pitch types and add-ons (same options as the booking form). Changes apply when you save or update the order.', 'vila-campsite-booking' ) . '</p>';
		wp_nonce_field( 'vila_cb_save_order_extras', 'vila_cb_order_extras_nonce' );
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! is_object( $item ) || ! is_callable( array( $item, 'get_product_id' ) ) ) {
				continue;
			}
			if ( ! self::is_campsite_product( (int) $item->get_product_id() ) ) {
				continue;
			}
			self::echo_order_line_extras_chips( $order, (int) $item_id, $item );
		}
		echo '</div>';
	}

	/**
	 * Chip grid for unit types (booking form parity): used on WooCommerce order edit and Campsite booking editor.
	 *
	 * @param string   $input_name   Full HTML name for checkboxes, e.g. `vila_cb_vehicle_slugs[]` or `vila_cb_vehicle_slugs_order[12][]`.
	 * @param string   $id_prefix    Unique prefix for element ids (slug appended).
	 * @param string[] $checked_slugs Slugs that should be checked (including legacy slugs not in current options).
	 */
	public static function echo_vehicle_extras_chip_grid( $input_name, $id_prefix, array $checked_slugs ) {
		$opts   = self::get_admin_vehicle_type_options_with_fees();
		$slugs  = array_values( array_filter( array_map( 'sanitize_key', $checked_slugs ) ) );
		$orphan = array_values( array_diff( $slugs, array_keys( $opts ) ) );
		$checked = array_fill_keys( $slugs, true );

		$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '€';

		echo '<div class="vila-cb-admin-chip-grid" role="group" aria-label="' . esc_attr__( 'Extras', 'vila-campsite-booking' ) . '">';
		foreach ( $opts as $sk => $meta ) {
			$fid = $id_prefix . '_' . preg_replace( '/[^a-z0-9_-]/i', '_', (string) $sk );
			$fee = isset( $meta['fee'] ) ? (float) $meta['fee'] : 0.0;
			echo '<label class="vila-cb-admin-chip" for="' . esc_attr( $fid ) . '">';
			echo '<input type="checkbox" id="' . esc_attr( $fid ) . '" class="vila-cb-extras-checkbox" name="' . esc_attr( $input_name ) . '" value="' . esc_attr( (string) $sk ) . '" ' . checked( ! empty( $checked[ $sk ] ), true, false ) . ' />';
			echo '<span class="vila-cb-admin-chip__body">';
			echo '<span class="vila-cb-admin-chip__title">' . esc_html( (string) $meta['label'] ) . '</span>';
			if ( $fee > 0 ) {
				echo '<span class="vila-cb-admin-chip__meta">+' . esc_html( $currency . sprintf( '%.0f', $fee ) );
				echo esc_html(
					_x( '/night', 'price suffix for pitch fee', 'vila-campsite-booking' )
				);
				echo '</span>';
			} else {
				echo '<span class="vila-cb-admin-chip__meta">' . esc_html__( 'Included', 'vila-campsite-booking' ) . '</span>';
			}
			echo '</span></label>';
		}
		foreach ( $orphan as $oslug ) {
			$fid = $id_prefix . '_leg_' . preg_replace( '/[^a-z0-9_-]/i', '_', (string) $oslug );
			echo '<label class="vila-cb-admin-chip vila-cb-admin-chip--legacy" for="' . esc_attr( $fid ) . '">';
			echo '<input type="checkbox" id="' . esc_attr( $fid ) . '" class="vila-cb-extras-checkbox" name="' . esc_attr( $input_name ) . '" value="' . esc_attr( (string) $oslug ) . '" ' . checked( ! empty( $checked[ $oslug ] ), true, false ) . ' />';
			echo '<span class="vila-cb-admin-chip__body">';
			echo '<span class="vila-cb-admin-chip__title">' . esc_html( (string) $oslug ) . '</span>';
			echo '<span class="vila-cb-admin-chip__meta">' . esc_html__( '(legacy slug)', 'vila-campsite-booking' ) . '</span>';
			echo '</span></label>';
		}
		echo '</div>';
	}

	/**
	 * Output chip grid + booking link for one campsite order line.
	 *
	 * @param \WC_Order      $order   Order.
	 * @param int            $item_id Line item ID.
	 * @param \WC_Order_Item $item    Line item.
	 */
	private static function echo_order_line_extras_chips( $order, $item_id, $item ) {
		$item_id = (int) $item_id;
		$slugs   = self::get_order_line_vehicle_slugs_resolved( $order, $item_id );
		$slugs   = array_values( array_filter( array_map( 'sanitize_key', $slugs ) ) );

		echo '<div class="vila-cb-order-line-extras" data-vila-cb-item-id="' . esc_attr( (string) $item_id ) . '">';
		echo '<h4 class="vila-cb-order-line-extras__title">' . esc_html( $item->get_name() ) . '</h4>';
		self::echo_vehicle_extras_chip_grid(
			'vila_cb_vehicle_slugs_order[' . (string) $item_id . '][]',
			'vila_cb_ord_' . (string) $item_id,
			$slugs
		);
		$booking_url = add_query_arg(
			array(
				'page'       => 'vila-cb-booking',
				'booking_id' => (int) $item->get_meta( self::META_BOOKING_ID, true ),
			),
			admin_url( 'admin.php' )
		);
		if ( (int) $item->get_meta( self::META_BOOKING_ID, true ) > 0 ) {
			echo '<p class="description" style="margin:10px 0 0;"><a href="' . esc_url( $booking_url ) . '">' . esc_html__( 'Open Campsite booking editor', 'vila-campsite-booking' ) . '</a></p>';
		}
		echo '</div>';
	}

	/**
	 * Human-readable extras line from theme `_vila_vehicle_slugs` JSON (multi-select).
	 *
	 * @param \WC_Order_Item $item Line item.
	 * @return string
	 */
	private static function order_line_label_from_vehicle_slugs_meta( $item ) {
		return self::labels_join_from_slugs( self::get_vehicle_slugs_from_line_item( $item ) );
	}

	/**
	 * Unit type label on the stay order line (e.g. Caravan), from line item meta.
	 *
	 * Theme stores extras under translated “Extras” and `_vila_vehicle_slugs` (JSON). The calendar
	 * must resolve multi-select labels even when the display string meta is missing or keyed by a
	 * different locale than wp-admin.
	 *
	 * Filter `vila_cb_order_line_unit_type_label` can override or supply a value when meta is absent.
	 *
	 * @param \WC_Order|null $order          Order.
	 * @param int            $line_item_id   Campsite stay line item ID (0 = first campsite line on order).
	 * @return string Display label or empty.
	 */
	public static function get_order_line_unit_type_label( $order, $line_item_id ) {
		$line_item_id = (int) $line_item_id;
		$requested_id = $line_item_id;
		if ( ! $order instanceof \WC_Order ) {
			self::debug_log( 'extras.get_order_line_unit_type_label', array( 'reason' => 'no_order' ) );
			return '';
		}
		if ( $line_item_id <= 0 ) {
			foreach ( $order->get_items( 'line_item' ) as $lid => $line ) {
				if ( ! is_object( $line ) || ! is_callable( array( $line, 'get_product_id' ) ) ) {
					continue;
				}
				if ( self::is_campsite_product( (int) $line->get_product_id() ) ) {
					$line_item_id = (int) $lid;
					break;
				}
			}
		}
		if ( $line_item_id <= 0 ) {
			self::debug_log(
				'extras.get_order_line_unit_type_label',
				array(
					'order_id'      => $order->get_id(),
					'requested_id'  => $requested_id,
					'reason'        => 'no_campsite_line',
				)
			);
			return (string) apply_filters( 'vila_cb_order_line_unit_type_label', '', $order, $line_item_id, null );
		}
		$item = $order->get_item( $line_item_id );
		if ( ! $item || ! is_callable( array( $item, 'get_meta' ) ) ) {
			self::debug_log(
				'extras.get_order_line_unit_type_label',
				array(
					'order_id'     => $order->get_id(),
					'line_item_id' => $line_item_id,
					'reason'       => 'item_missing',
				)
			);
			return (string) apply_filters( 'vila_cb_order_line_unit_type_label', '', $order, $line_item_id, null );
		}

		$resolved = '';
		$keys     = array( 'Extras', 'Unit type' );
		if ( function_exists( 'vila_akoya_vehicle_label' ) ) {
			$keys[] = __( 'Extras', 'vila-akoya' );
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
				if ( preg_match( '/^extras$/iu', $k ) ) {
					$val = is_object( $m ) && isset( $m->value ) ? $m->value : '';
					if ( is_string( $val ) && '' !== trim( $val ) ) {
						$resolved = trim( $val );
						break;
					}
				}
				$dk = is_object( $m ) && isset( $m->display_key ) ? (string) $m->display_key : '';
				if ( '' !== $dk && preg_match( '/^extras$/iu', $dk ) ) {
					$val = is_object( $m ) && isset( $m->value ) ? $m->value : '';
					if ( is_string( $val ) && '' !== trim( $val ) ) {
						$resolved = trim( $val );
						break;
					}
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
		if ( '' === $resolved ) {
			$resolved = self::order_line_label_from_vehicle_slugs_meta( $item );
		}

		$filtered = apply_filters( 'vila_cb_order_line_unit_type_label', $resolved, $order, $line_item_id, $item );
		$slug_raw = $item->get_meta( '_vila_vehicle_slugs', true );
		$slug_raw_b = is_string( $slug_raw ) ? strlen( $slug_raw ) : 0;
		$parsed_slugs = self::get_vehicle_slugs_from_line_item( $item );
		$meta_keys    = array();
		if ( is_callable( array( $item, 'get_meta_data' ) ) ) {
			foreach ( $item->get_meta_data() as $m ) {
				if ( is_object( $m ) && isset( $m->key ) ) {
					$meta_keys[] = (string) $m->key;
				}
				if ( count( $meta_keys ) >= 25 ) {
					break;
				}
			}
		}
		$extras_direct = $item->get_meta( 'Extras', true );
		$out           = '';
		if ( is_string( $filtered ) ) {
			$out = $filtered;
		} elseif ( is_scalar( $filtered ) && ! is_bool( $filtered ) ) {
			$out = (string) $filtered;
		}
		self::debug_log(
			'extras.get_order_line_unit_type_label',
			array(
				'order_id'           => $order->get_id(),
				'line_item_id'       => $line_item_id,
				'requested_id'       => $requested_id,
				'resolved_before_f_b'=> strlen( (string) $resolved ),
				'filtered_out_b'     => strlen( $out ),
				'_vila_vehicle_slugs_b' => $slug_raw_b,
				'parsed_slugs'       => $parsed_slugs,
				'extras_meta_preview'=> is_string( $extras_direct ) ? substr( trim( $extras_direct ), 0, 80 ) : '',
				'meta_key_sample'    => $meta_keys,
			)
		);
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

		$details_label = __( 'Booking details', 'vila-campsite-booking' );
		$filtered     = array();
		foreach ( $item_data as $row ) {
			if ( ! is_array( $row ) ) {
				$filtered[] = $row;
				continue;
			}
			$row_key = isset( $row['key'] ) ? trim( (string) $row['key'] ) : '';
			if ( '' !== $row_key && 0 === strpos( $row_key, 'vila_cb_' ) ) {
				continue;
			}
			$name = isset( $row['name'] ) ? trim( wp_strip_all_tags( (string) $row['name'] ) ) : '';
			$name_lc = strtolower( $name );
			$skip_names = array(
				'stay',
				'guests',
				'plot',
				'extras',
				'nights (for extras)',
				'from',
				'to',
				'people',
				'peoples',
				'booking details',
			);
			// Remove booking-related rows from MWB/theme to avoid duplicate/noisy checkout metadata.
			if ( in_array( $name_lc, $skip_names, true ) ) {
				continue;
			}
			$filtered[] = $row;
		}
		$item_data = $filtered;

		$merged = self::cart_item_flattened_for_stay_check( $cart_item );
		$cin    = isset( $merged['vila_cb_check_in'] ) ? trim( (string) $merged['vila_cb_check_in'] ) : '';
		$cout   = isset( $merged['vila_cb_check_out'] ) ? trim( (string) $merged['vila_cb_check_out'] ) : '';
		$guests = isset( $merged['vila_cb_guests'] ) && is_array( $merged['vila_cb_guests'] ) ? $merged['vila_cb_guests'] : array();
		$guests = self::normalize_guests_for_storage( $guests );
		$plot_id = isset( $merged['vila_cb_preferred_plot'] ) ? (int) $merged['vila_cb_preferred_plot'] : 0;
		if ( $plot_id <= 0 && isset( $merged['vila_cb_plot_id'] ) ) {
			$plot_id = (int) $merged['vila_cb_plot_id'];
		}
		$details = array();
		if ( $plot_id > 0 ) {
			$plot_row  = ( new Plot_Repository() )->get( $plot_id );
			$plot_text = ( is_array( $plot_row ) && ! empty( $plot_row['label'] ) ) ? (string) $plot_row['label'] : (string) $plot_id;
			$details[] = sprintf( __( 'Plot: %s', 'vila-campsite-booking' ), $plot_text );
		}
		$people = (int) $guests['adults'] + (int) $guests['children'] + (int) $guests['infants'];
		if ( $people > 0 ) {
			$details[] = sprintf( __( 'Peoples: %d', 'vila-campsite-booking' ), $people );
		}
		if ( self::validate_date_ymd( $cin ) ) {
			$details[] = sprintf( __( 'From: %s', 'vila-campsite-booking' ), date_i18n( get_option( 'date_format' ), strtotime( $cin . ' 00:00:00' ) ) );
		}
		if ( self::validate_date_ymd( $cout ) ) {
			$details[] = sprintf( __( 'To: %s', 'vila-campsite-booking' ), date_i18n( get_option( 'date_format' ), strtotime( $cout . ' 00:00:00' ) ) );
		}
		$extras_text = '';
		if ( ! empty( $guests['extras_label'] ) && is_string( $guests['extras_label'] ) ) {
			$extras_text = trim( $guests['extras_label'] );
		} elseif ( ! empty( $guests['vehicle_slugs'] ) && is_array( $guests['vehicle_slugs'] ) ) {
			$extras_text = self::labels_join_from_slugs( array_values( array_filter( array_map( 'sanitize_key', $guests['vehicle_slugs'] ) ) ) );
		}
		if ( '' !== $extras_text ) {
			$details[] = sprintf( __( 'Extras: %s', 'vila-campsite-booking' ), $extras_text );
		}
		if ( ! empty( $details ) ) {
			$item_data[] = array(
				'key'     => 'booking_details',
				'name'    => $details_label,
				'display' => esc_html( implode( ' / ', $details ) ),
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
		$hidden[] = '_vila_cb_preferred_plot';
		// Theme/plugin JSON for multi-select; managed via Campsite extras UI (do not edit raw JSON in the line meta box).
		$hidden[] = '_vila_vehicle_slugs';
		return array_values( array_unique( array_map( 'strval', $hidden ) ) );
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
		$plot_label_key = __( 'Plot', 'vila-campsite-booking' );
		$extras_label_key = __( 'Extras', 'vila-campsite-booking' );
		$out              = array();
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
				if ( $dk === $plot_label_key || $dk === 'Plot' ) {
					continue;
				}
			}
			if ( self::should_strip_extra_meta_row( $key, $dk ) ) {
				continue;
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
		$plot_id = (int) $item->get_meta( self::META_PLOT_ID, true );
		if ( $plot_id <= 0 ) {
			$plot_id = (int) $item->get_meta( '_vila_cb_preferred_plot', true );
		}
		if ( $plot_id > 0 ) {
			$plot_row = ( new Plot_Repository() )->get( $plot_id );
			$plot_text = ( is_array( $plot_row ) && ! empty( $plot_row['label'] ) ) ? (string) $plot_row['label'] : (string) $plot_id;
			$out[] = (object) array(
				'id'            => 'vila-cb-plot-' . $line_id,
				'key'           => '_vila_cb_display_plot',
				'value'         => $plot_text,
				'display_key'   => $plot_label_key,
				'display_value' => wc_clean( $plot_text ),
			);
		}
		$extras_order = null;
		if ( is_callable( array( $item, 'get_order_id' ) ) ) {
			$oid = (int) $item->get_order_id();
			if ( $oid > 0 ) {
				$extras_order = wc_get_order( $oid );
			}
		}
		$extras_text = '';
		if ( $extras_order instanceof \WC_Order ) {
			$extras_text = (string) self::get_order_line_unit_type_label( $extras_order, $line_id );
		}
		if ( '' !== $extras_text ) {
			$out[] = (object) array(
				'id'            => 'vila-cb-extras-' . $line_id,
				'key'           => '_vila_cb_display_extras',
				'value'         => $extras_text,
				'display_key'   => $extras_label_key,
				'display_value' => wc_clean( $extras_text ),
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
			$guest_json = self::merge_guest_json_with_line_extras( $guest_json ? $guest_json : '', $item, $order, $line_id );
			$preferred_plot = (int) $item->get_meta( '_vila_cb_preferred_plot', true );
			$res        = $svc->confirm_booking_atomic( (int) $order->get_id(), $line_id, $cin, $cout, $guest_json ? $guest_json : null, $preferred_plot );
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
