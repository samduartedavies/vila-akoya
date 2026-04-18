<?php
/**
 * Campsite booking extras: pitch / unit type surcharges + MWB (Bookings for WooCommerce) UI script.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coerce request values to string for PHP 8.1+ (null/array must not reach sanitize_text_field).
 *
 * @param mixed $value Raw value.
 * @return string
 */
function vila_akoya_scalar_request_string( $value ) {
	if ( is_string( $value ) ) {
		return $value;
	}
	if ( is_int( $value ) || is_float( $value ) ) {
		return (string) $value;
	}
	if ( is_bool( $value ) ) {
		return '';
	}
	return '';
}

/**
 * Plain-text price for use inside translated breakdown titles (no HTML).
 *
 * @param float $amount Amount in store currency.
 * @return string
 */
function vila_akoya_format_price_plain( $amount ) {
	$amount = (float) $amount;
	if ( function_exists( 'wc_price' ) ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}
	return (string) round( $amount, 2 );
}

/**
 * Nightly supplement (EUR) by unit slug. Filter to change amounts.
 *
 * @return array<string, array{ label: string, fee: float }>
 */
function vila_akoya_get_vehicle_types() {
	return apply_filters(
		'vila_akoya_vehicle_types',
		array(
			'tent'         => array(
				'label' => __( 'Tent', 'vila-akoya' ),
				'fee'   => 0,
			),
			'caravan'      => array(
				'label' => __( 'Caravan', 'vila-akoya' ),
				'fee'   => 8,
			),
			'camper'       => array(
				'label' => __( 'Camper van', 'vila-akoya' ),
				'fee'   => 10,
			),
			'electricity'  => array(
				'label' => __( 'Include electricity', 'vila-akoya' ),
				'fee'   => 5,
			),
			'car'          => array(
				'label' => __( 'Car', 'vila-akoya' ),
				'fee'   => 5,
			),
		)
	);
}

/**
 * @param string $slug Vehicle slug.
 * @return float Nightly fee in store currency.
 */
function vila_akoya_vehicle_fee_per_night( $slug ) {
	$types = vila_akoya_get_vehicle_types();
	$slug  = sanitize_key( $slug );
	if ( ! isset( $types[ $slug ] ) ) {
		return 0.0;
	}
	return (float) $types[ $slug ]['fee'];
}

/**
 * @param string $slug Vehicle slug.
 * @return string
 */
function vila_akoya_vehicle_label( $slug ) {
	$types = vila_akoya_get_vehicle_types();
	$slug  = sanitize_key( $slug );
	return isset( $types[ $slug ] ) ? $types[ $slug ]['label'] : $slug;
}

/**
 * Normalize POST or stored slugs to a unique sorted list of valid unit types (default: tent only).
 *
 * @param array<int|string>|string $raw Slugs from request or storage.
 * @return string[]
 */
function vila_akoya_normalize_vehicle_types_array( $raw ) {
	$keys = array_keys( vila_akoya_get_vehicle_types() );
	$out  = array();
	if ( is_array( $raw ) ) {
		foreach ( $raw as $v ) {
			$s = sanitize_key( is_string( $v ) ? $v : (string) $v );
			if ( $s && in_array( $s, $keys, true ) ) {
				$out[] = $s;
			}
		}
	} elseif ( is_string( $raw ) && '' !== $raw ) {
		$s = sanitize_key( $raw );
		if ( in_array( $s, $keys, true ) ) {
			$out[] = $s;
		}
	}
	$out = array_values( array_unique( $out ) );
	sort( $out );
	if ( empty( $out ) ) {
		$out = array( 'tent' );
	}
	return $out;
}

/**
 * Combined nightly pitch supplement for all selected unit types.
 *
 * @param string[] $slugs Normalized slugs.
 * @return float
 */
function vila_akoya_vehicle_fee_total_per_night( array $slugs ) {
	$t = 0.0;
	foreach ( vila_akoya_normalize_vehicle_types_array( $slugs ) as $slug ) {
		$t += vila_akoya_vehicle_fee_per_night( $slug );
	}
	return $t;
}

/**
 * Human-readable list of selected unit labels (e.g. for cart and orders).
 *
 * @param string[] $slugs Normalized slugs.
 * @return string
 */
function vila_akoya_vehicle_labels_join( array $slugs ) {
	$labels = array();
	foreach ( vila_akoya_normalize_vehicle_types_array( $slugs ) as $slug ) {
		$labels[] = vila_akoya_vehicle_label( $slug );
	}
	return implode(
		/* translators: Separator between unit types in a list (e.g. "Tent, Caravan"). */
		__( ', ', 'vila-akoya' ),
		$labels
	);
}

/**
 * Primary interest options for the booking form (what guests are most curious about).
 *
 * @return array<string, string> slug => label
 */
function vila_akoya_get_primary_interest_choices() {
	return apply_filters(
		'vila_akoya_primary_interest_choices',
		array(
			'village'  => __( 'Village', 'vila-akoya' ),
			'campsite' => __( 'Campsite', 'vila-akoya' ),
			'both'     => __( 'Both', 'vila-akoya' ),
		)
	);
}

/**
 * @return string[]
 */
function vila_akoya_get_primary_interest_slugs() {
	return array_keys( vila_akoya_get_primary_interest_choices() );
}

/**
 * @param mixed $raw Raw slug from request or storage.
 * @return string Empty string if unset/invalid; otherwise village|campsite|both.
 */
function vila_akoya_normalize_primary_interest( $raw ) {
	$allowed = vila_akoya_get_primary_interest_slugs();
	$s       = sanitize_key( is_string( $raw ) ? $raw : (string) $raw );
	if ( $s && in_array( $s, $allowed, true ) ) {
		return $s;
	}
	return '';
}

/**
 * @param string $slug Normalized slug (may be empty).
 * @return string Display label or empty string.
 */
function vila_akoya_primary_interest_label( $slug ) {
	$choices = vila_akoya_get_primary_interest_choices();
	$slug    = vila_akoya_normalize_primary_interest( $slug );
	if ( '' === $slug ) {
		return '';
	}
	return isset( $choices[ $slug ] ) ? $choices[ $slug ] : '';
}

/**
 * Unit types for a cart line (array), migrating legacy single slug.
 *
 * @param array<string, mixed> $cart_item Cart row.
 * @return string[]
 */
function vila_akoya_cart_vehicle_types( array $cart_item ) {
	if ( ! empty( $cart_item['vila_vehicle_types'] ) && is_array( $cart_item['vila_vehicle_types'] ) ) {
		return vila_akoya_normalize_vehicle_types_array( $cart_item['vila_vehicle_types'] );
	}
	if ( ! empty( $cart_item['vila_vehicle_type'] ) ) {
		return vila_akoya_normalize_vehicle_types_array( array( $cart_item['vila_vehicle_type'] ) );
	}
	return array( 'tent' );
}

/**
 * Nightly supplement rates for non-adult guest categories (adults use MWB per-person unit cost).
 *
 * @param int $product_id Product ID.
 * @return array{ children: float, infants: float, pets: float }
 */
function vila_akoya_get_guest_category_nightly_rates( $product_id ) {
	$product_id = (int) $product_id;
	$defaults   = array(
		'children' => 12.0,
		'infants'  => 0.0,
		'pets'     => 6.0,
	);
	return apply_filters( 'vila_akoya_guest_category_nightly_rates', $defaults, $product_id );
}

/**
 * Summary line for the guest picker trigger (Airbnb-style).
 *
 * @param int $adults   Adults count.
 * @param int $children Children count.
 * @param int $infants  Infants count.
 * @param int $pets     Pets count.
 * @return string
 */
function vila_akoya_guest_picker_summary_string( $adults, $children, $infants, $pets ) {
	$adults   = max( 0, (int) $adults );
	$children = max( 0, (int) $children );
	$infants  = max( 0, (int) $infants );
	$pets     = max( 0, (int) $pets );

	$people = $adults + $children + $infants;
	$parts  = array();
	if ( 1 === $people ) {
		$parts[] = __( '1 guest', 'vila-akoya' );
	} else {
		/* translators: %d: number of people */
		$parts[] = sprintf( __( '%d guests', 'vila-akoya' ), $people );
	}
	if ( $pets > 0 ) {
		$parts[] = sprintf(
			/* translators: %d: number of pets */
			_n( '%d pet', '%d pets', $pets, 'vila-akoya' ),
			$pets
		);
	}
	return implode( ' · ', $parts );
}

/**
 * Capture product ID for `mbfw_load_people_option_template` (works on non–single-product booking views).
 *
 * @param int   $product_id Product ID.
 * @param mixed $product    Product object.
 */
function vila_akoya_mbfw_before_people_template( $product_id, $product ) {
	unset( $product );
	$GLOBALS['vila_mbfw_people_product_id'] = (int) $product_id;
}
add_action( 'mwb_mbfw_number_of_people_while_booking_on_form', 'vila_akoya_mbfw_before_people_template', 1, 2 );

/**
 * @param int   $product_id Product ID.
 * @param mixed $product    Product.
 */
function vila_akoya_mbfw_after_people_template( $product_id, $product ) {
	unset( $product_id, $product );
	unset( $GLOBALS['vila_mbfw_people_product_id'] );
}
add_action( 'mwb_mbfw_number_of_people_while_booking_on_form', 'vila_akoya_mbfw_after_people_template', 999, 2 );

/**
 * Replace MWB people template with Airbnb-style breakdown on the campsite product.
 *
 * @param string $file Filtered template path.
 * @param string $path Relative path (unused).
 * @return string
 */
function vila_akoya_mbfw_guest_picker_template( $file, $path ) {
	unset( $path );
	$pid = isset( $GLOBALS['vila_mbfw_people_product_id'] ) ? (int) $GLOBALS['vila_mbfw_people_product_id'] : 0;
	if ( $pid && vila_akoya_is_campsite_product_id( $pid ) ) {
		return VILA_AKOYA_DIR . '/inc/templates/campsite-guest-picker.php';
	}
	// Never return null: MWB uses `require_once apply_filters( … )` → plugin_basename() → wp_normalize_path().
	return is_string( $file ) ? $file : '';
}
add_filter( 'mbfw_load_people_option_template', 'vila_akoya_mbfw_guest_picker_template', 10, 2 );

/**
 * Compute nights from MWB date fields (d-m-Y) submitted with add-to-cart.
 *
 * @return int
 */
function vila_akoya_nights_from_request() {
	$from = isset( $_POST['mwb_mbfw_booking_from_time'] ) ? sanitize_text_field( wp_unslash( vila_akoya_scalar_request_string( $_POST['mwb_mbfw_booking_from_time'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$to   = isset( $_POST['mwb_mbfw_booking_to_time'] ) ? sanitize_text_field( wp_unslash( vila_akoya_scalar_request_string( $_POST['mwb_mbfw_booking_to_time'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( '' === $from || '' === $to ) {
		return 1;
	}
	$df = DateTime::createFromFormat( 'd-m-Y', $from, wp_timezone() );
	$dt = DateTime::createFromFormat( 'd-m-Y', $to, wp_timezone() );
	if ( ! $df || ! $dt ) {
		return 1;
	}
	$n = (int) $df->diff( $dt )->format( '%a' );
	return max( 1, $n );
}

/**
 * Guest count from MWB cart line data (used for optional pitch fee multiplier).
 *
 * @param array<string, mixed> $cart_item Cart line.
 * @return int
 */
function vila_akoya_cart_people_for_line( array $cart_item ) {
	if ( ! empty( $cart_item['mwb_mbfw_booking_values']['people_number'] ) ) {
		return max( 1, (int) $cart_item['mwb_mbfw_booking_values']['people_number'] );
	}
	return 1;
}

/**
 * Nights for pitch fee from stored line meta or MWB POST at add-to-cart.
 *
 * @param array<string, mixed> $cart_item Cart line.
 * @return int
 */
function vila_akoya_cart_nights_for_line( array $cart_item ) {
	if ( ! empty( $cart_item['booking']['_start_date'] ) && ! empty( $cart_item['booking']['_end_date'] ) ) {
		$start = (int) $cart_item['booking']['_start_date'];
		$end   = (int) $cart_item['booking']['_end_date'];
		if ( $end > $start ) {
			$days = (int) floor( ( $end - $start ) / DAY_IN_SECONDS );
			return max( 1, $days );
		}
	}
	if ( ! empty( $cart_item['vila_booking_nights'] ) ) {
		return max( 1, (int) $cart_item['vila_booking_nights'] );
	}
	return 1;
}

/**
 * Persist vehicle choice and night count on the cart line (MWB booking add-to-cart).
 *
 * @param array<string, mixed> $cart_item_data Cart item data.
 * @param int                    $product_id     Product ID.
 * @param int                    $variation_id   Variation ID.
 * @return array<string, mixed>
 */
function vila_akoya_add_vehicle_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
	unset( $variation_id );
	if ( ! vila_akoya_is_campsite_product_id( (int) $product_id ) ) {
		return $cart_item_data;
	}
	if ( ! isset( $_POST['_mwb_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( vila_akoya_scalar_request_string( $_POST['_mwb_nonce'] ) ) ), 'mwb_booking_frontend' ) ) {
		return $cart_item_data;
	}
	$raw_types = array();
	if ( isset( $_POST['vila_vehicle_types'] ) && is_array( $_POST['vila_vehicle_types'] ) ) {
		$raw_types = $_POST['vila_vehicle_types'];
	} elseif ( isset( $_POST['vila_vehicle_type'] ) ) {
		$raw_types = array( wp_unslash( vila_akoya_scalar_request_string( $_POST['vila_vehicle_type'] ) ) );
	}
	$types = vila_akoya_normalize_vehicle_types_array( $raw_types );
	$cart_item_data['vila_vehicle_types'] = $types;
	$cart_item_data['vila_vehicle_type']  = $types[0];
	$cart_item_data['vila_booking_nights'] = vila_akoya_nights_from_request();
	return $cart_item_data;
}
add_filter( 'woocommerce_add_cart_item_data', 'vila_akoya_add_vehicle_cart_item_data', 30, 3 );

/**
 * Store guest-type breakdown for campsite bookings (MWB still stores adults in `people_number`).
 *
 * @param array<string, mixed> $cart_item_data Cart item data.
 * @param int                  $product_id     Product ID.
 * @param int                  $variation_id   Variation ID.
 * @return array<string, mixed>
 */
function vila_akoya_add_guest_breakdown_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
	unset( $variation_id );
	if ( ! vila_akoya_is_campsite_product_id( (int) $product_id ) ) {
		return $cart_item_data;
	}
	if ( ! isset( $_POST['_mwb_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( vila_akoya_scalar_request_string( $_POST['_mwb_nonce'] ) ) ), 'mwb_booking_frontend' ) ) {
		return $cart_item_data;
	}
	$children = isset( $_POST['vila_guest_children'] ) ? (int) wp_unslash( vila_akoya_scalar_request_string( $_POST['vila_guest_children'] ) ) : 0;
	$infants  = isset( $_POST['vila_guest_infants'] ) ? (int) wp_unslash( vila_akoya_scalar_request_string( $_POST['vila_guest_infants'] ) ) : 0;
	$pets     = isset( $_POST['vila_guest_pets'] ) ? (int) wp_unslash( vila_akoya_scalar_request_string( $_POST['vila_guest_pets'] ) ) : 0;

	$cart_item_data['vila_guest_children'] = max( 0, $children );
	$cart_item_data['vila_guest_infants']  = max( 0, $infants );
	$cart_item_data['vila_guest_pets']     = max( 0, $pets );

	return $cart_item_data;
}
add_filter( 'woocommerce_add_cart_item_data', 'vila_akoya_add_guest_breakdown_cart_item_data', 31, 3 );

/**
 * Store “primary interest” on the cart line (MWB booking add-to-cart).
 *
 * @param array<string, mixed> $cart_item_data Cart item data.
 * @param int                  $product_id     Product ID.
 * @param int                  $variation_id   Variation ID.
 * @return array<string, mixed>
 */
function vila_akoya_add_primary_interest_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
	unset( $variation_id );
	if ( ! vila_akoya_is_campsite_product_id( (int) $product_id ) ) {
		return $cart_item_data;
	}
	if ( ! isset( $_POST['_mwb_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( vila_akoya_scalar_request_string( $_POST['_mwb_nonce'] ) ) ), 'mwb_booking_frontend' ) ) {
		return $cart_item_data;
	}
	$raw = isset( $_POST['vila_primary_interest'] ) ? vila_akoya_scalar_request_string( $_POST['vila_primary_interest'] ) : '';
	$cart_item_data['vila_primary_interest'] = vila_akoya_normalize_primary_interest( $raw );
	return $cart_item_data;
}
add_filter( 'woocommerce_add_cart_item_data', 'vila_akoya_add_primary_interest_cart_item_data', 32, 3 );

/**
 * Validate guest breakdown vs MWB min/max people (capacity counts adults + children + infants; pets separate).
 *
 * @param bool $passed     Whether validation passed.
 * @param int  $product_id Product ID.
 * @param int  $quantity   Quantity.
 * @return bool
 */
function vila_akoya_validate_guest_breakdown_add_to_cart( $passed, $product_id, $quantity ) {
	unset( $quantity );
	if ( ! $passed || ! vila_akoya_is_campsite_product_id( (int) $product_id ) ) {
		return $passed;
	}
	if ( ! function_exists( 'wps_booking_get_meta_data' ) ) {
		return $passed;
	}
	if ( ! isset( $_POST['_mwb_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( vila_akoya_scalar_request_string( $_POST['_mwb_nonce'] ) ) ), 'mwb_booking_frontend' ) ) {
		return $passed;
	}

	$adults   = isset( $_POST['mwb_mbfw_people_number'] ) ? (int) wp_unslash( vila_akoya_scalar_request_string( $_POST['mwb_mbfw_people_number'] ) ) : 0;
	$children = isset( $_POST['vila_guest_children'] ) ? (int) wp_unslash( vila_akoya_scalar_request_string( $_POST['vila_guest_children'] ) ) : 0;
	$infants  = isset( $_POST['vila_guest_infants'] ) ? (int) wp_unslash( vila_akoya_scalar_request_string( $_POST['vila_guest_infants'] ) ) : 0;
	$pets     = isset( $_POST['vila_guest_pets'] ) ? (int) wp_unslash( vila_akoya_scalar_request_string( $_POST['vila_guest_pets'] ) ) : 0;

	$min_p = (int) wps_booking_get_meta_data( $product_id, 'mwb_mbfw_minimum_people_per_booking', true );
	$max_p = (int) wps_booking_get_meta_data( $product_id, 'mwb_mbfw_maximum_people_per_booking', true );
	if ( $min_p < 1 ) {
		$min_p = 1;
	}
	if ( $max_p < $min_p ) {
		$max_p = max( $min_p, 8 );
	}

	if ( $adults < $min_p ) {
		wc_add_notice( __( 'Please choose at least the minimum number of adults for this booking.', 'vila-akoya' ), 'error' );
		return false;
	}
	if ( $adults + $children + $infants > $max_p ) {
		wc_add_notice(
			sprintf(
				/* translators: %d: maximum guests */
				__( 'Guest count cannot exceed %d people.', 'vila-akoya' ),
				$max_p
			),
			'error'
		);
		return false;
	}
	if ( $pets > 5 ) {
		wc_add_notice( __( 'Please enter a valid number of pets.', 'vila-akoya' ), 'error' );
		return false;
	}

	return $passed;
}
add_filter( 'woocommerce_add_to_cart_validation', 'vila_akoya_validate_guest_breakdown_add_to_cart', 10, 3 );

/**
 * Restore custom cart line data from session.
 *
 * @param array<string, mixed> $cart_item Cart item.
 * @param array<string, mixed> $values    Session values.
 * @return array<string, mixed>
 */
function vila_akoya_get_cart_item_from_session_vehicle( $cart_item, $values, $cart_item_key ) {
	unset( $cart_item_key );
	foreach ( array( 'vila_vehicle_types', 'vila_vehicle_type', 'vila_booking_nights', 'vila_guest_children', 'vila_guest_infants', 'vila_guest_pets', 'vila_primary_interest' ) as $key ) {
		if ( isset( $values[ $key ] ) ) {
			$cart_item[ $key ] = $values[ $key ];
		}
	}
	if ( ! empty( $cart_item['vila_vehicle_types'] ) && is_array( $cart_item['vila_vehicle_types'] ) ) {
		$cart_item['vila_vehicle_types'] = vila_akoya_normalize_vehicle_types_array( $cart_item['vila_vehicle_types'] );
	} else	if ( ! empty( $cart_item['vila_vehicle_type'] ) ) {
		$cart_item['vila_vehicle_types'] = vila_akoya_normalize_vehicle_types_array( array( $cart_item['vila_vehicle_type'] ) );
	}
	if ( array_key_exists( 'vila_primary_interest', $cart_item ) ) {
		$cart_item['vila_primary_interest'] = vila_akoya_normalize_primary_interest( $cart_item['vila_primary_interest'] );
	}
	return $cart_item;
}
add_filter( 'woocommerce_get_cart_item_from_session', 'vila_akoya_get_cart_item_from_session_vehicle', 20, 3 );

/**
 * Add pitch supplement as a fee (per night × nightly rate).
 */
function vila_akoya_cart_vehicle_fees() {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}
	foreach ( WC()->cart->get_cart() as $cart_item ) {
		$types = vila_akoya_cart_vehicle_types( $cart_item );
		if ( empty( $types ) || ! vila_akoya_is_campsite_product_id( (int) $cart_item['product_id'] ) ) {
			continue;
		}
		$rate    = vila_akoya_vehicle_fee_total_per_night( $types );
		$nights  = vila_akoya_cart_nights_for_line( $cart_item );
		$people  = vila_akoya_cart_people_for_line( $cart_item );
		$by_head = (bool) apply_filters( 'vila_akoya_pitch_supplement_multiplies_by_guests', false, $cart_item );
		$total   = $rate * $nights * ( $by_head ? $people : 1 );
		if ( $total <= 0 ) {
			continue;
		}
		WC()->cart->add_fee(
			sprintf(
				/* translators: 1: extra labels (comma-separated), 2: number of nights */
				__( 'Extras — %1$s (%2$d nights)', 'vila-akoya' ),
				vila_akoya_vehicle_labels_join( $types ),
				$nights
			),
			$total
		);
	}
}
add_action( 'woocommerce_cart_calculate_fees', 'vila_akoya_cart_vehicle_fees', 20 );

/**
 * Nightly supplements for children, infants, and pets (adults priced via MWB per-person rate).
 */
function vila_akoya_cart_guest_category_fees() {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}

	$totals = array(
		'children' => 0.0,
		'infants'  => 0.0,
		'pets'     => 0.0,
	);

	foreach ( WC()->cart->get_cart() as $cart_item ) {
		if ( ! vila_akoya_is_campsite_product_id( (int) $cart_item['product_id'] ) ) {
			continue;
		}
		$nights = vila_akoya_cart_nights_for_line( $cart_item );
		$pid    = (int) $cart_item['product_id'];
		$rates  = vila_akoya_get_guest_category_nightly_rates( $pid );
		$ch     = isset( $cart_item['vila_guest_children'] ) ? (int) $cart_item['vila_guest_children'] : 0;
		$inf    = isset( $cart_item['vila_guest_infants'] ) ? (int) $cart_item['vila_guest_infants'] : 0;
		$pet    = isset( $cart_item['vila_guest_pets'] ) ? (int) $cart_item['vila_guest_pets'] : 0;

		$totals['children'] += $ch * (float) $rates['children'] * $nights;
		$totals['infants']  += $inf * (float) $rates['infants'] * $nights;
		$totals['pets']     += $pet * (float) $rates['pets'] * $nights;
	}

	if ( $totals['children'] > 0 ) {
		WC()->cart->add_fee( __( 'Children — nightly supplement', 'vila-akoya' ), $totals['children'] );
	}
	if ( $totals['infants'] > 0 ) {
		WC()->cart->add_fee( __( 'Infants — nightly supplement', 'vila-akoya' ), $totals['infants'] );
	}
	if ( $totals['pets'] > 0 ) {
		WC()->cart->add_fee( __( 'Pets — nightly supplement', 'vila-akoya' ), $totals['pets'] );
	}
}
add_action( 'woocommerce_cart_calculate_fees', 'vila_akoya_cart_guest_category_fees', 25 );

/**
 * Cart / checkout line item meta display.
 *
 * @param array<string, mixed> $item_data Item data.
 * @param array<string, mixed> $cart_item Cart item.
 * @return array<string, mixed>
 */
function vila_akoya_get_item_data_vehicle( $item_data, $cart_item ) {
	$types = vila_akoya_cart_vehicle_types( $cart_item );
	if ( empty( $types ) ) {
		return $item_data;
	}
	$item_data[] = array(
		'name'    => __( 'Extras', 'vila-akoya' ),
		'display' => esc_html( vila_akoya_vehicle_labels_join( $types ) ),
	);
	$nights = vila_akoya_cart_nights_for_line( $cart_item );
	if ( $nights > 0 ) {
		$item_data[] = array(
			'name'    => __( 'Nights (for extras)', 'vila-akoya' ),
			'display' => (string) $nights,
		);
	}
	return $item_data;
}
add_filter( 'woocommerce_get_item_data', 'vila_akoya_get_item_data_vehicle', 15, 2 );

/**
 * Show guest breakdown on cart / checkout line.
 *
 * @param array<string, mixed> $item_data Item data.
 * @param array<string, mixed> $cart_item Cart item.
 * @return array<string, mixed>
 */
function vila_akoya_get_item_data_guest_breakdown( $item_data, $cart_item ) {
	if ( empty( $cart_item['product_id'] ) || ! vila_akoya_is_campsite_product_id( (int) $cart_item['product_id'] ) ) {
		return $item_data;
	}
	$adults = ! empty( $cart_item['mwb_mbfw_booking_values']['people_number'] ) ? (int) $cart_item['mwb_mbfw_booking_values']['people_number'] : 1;
	$ch     = isset( $cart_item['vila_guest_children'] ) ? (int) $cart_item['vila_guest_children'] : 0;
	$inf    = isset( $cart_item['vila_guest_infants'] ) ? (int) $cart_item['vila_guest_infants'] : 0;
	$pet    = isset( $cart_item['vila_guest_pets'] ) ? (int) $cart_item['vila_guest_pets'] : 0;

	$item_data[] = array(
		'name'    => __( 'Guests', 'vila-akoya' ),
		'display' => esc_html( vila_akoya_guest_picker_summary_string( $adults, $ch, $inf, $pet ) ),
	);

	return $item_data;
}
add_filter( 'woocommerce_get_item_data', 'vila_akoya_get_item_data_guest_breakdown', 16, 2 );

/**
 * Cart / checkout: primary interest line.
 *
 * @param array<string, mixed> $item_data Item data.
 * @param array<string, mixed> $cart_item Cart item.
 * @return array<string, mixed>
 */
function vila_akoya_get_item_data_primary_interest( $item_data, $cart_item ) {
	if ( empty( $cart_item['product_id'] ) || ! vila_akoya_is_campsite_product_id( (int) $cart_item['product_id'] ) ) {
		return $item_data;
	}
	$slug = isset( $cart_item['vila_primary_interest'] ) ? $cart_item['vila_primary_interest'] : '';
	$slug = vila_akoya_normalize_primary_interest( $slug );
	if ( '' === $slug ) {
		return $item_data;
	}
	$item_data[] = array(
		'name'    => __( 'Primary interest', 'vila-akoya' ),
		'display' => esc_html( vila_akoya_primary_interest_label( $slug ) ),
	);
	return $item_data;
}
add_filter( 'woocommerce_get_item_data', 'vila_akoya_get_item_data_primary_interest', 18, 2 );

/**
 * Save to order line items.
 *
 * @param WC_Order_Item $item      Order item.
 * @param string        $cart_key  Cart key.
 * @param array         $values    Cart line values.
 * @param WC_Order      $order     Order.
 */
function vila_akoya_checkout_save_vehicle_meta( $item, $cart_key, $values, $order ) {
	unset( $cart_key, $order );
	$types = vila_akoya_cart_vehicle_types( $values );
	if ( empty( $types ) ) {
		return;
	}
	$item->add_meta_data( __( 'Extras', 'vila-akoya' ), vila_akoya_vehicle_labels_join( $types ), true );
	$item->add_meta_data( '_vila_vehicle_slugs', wp_json_encode( $types ), false );
	$nights = vila_akoya_cart_nights_for_line( $values );
	if ( $nights > 0 ) {
		$item->add_meta_data( __( 'Nights (for extras)', 'vila-akoya' ), (string) $nights, true );
	}
}
add_action( 'woocommerce_checkout_create_order_line_item', 'vila_akoya_checkout_save_vehicle_meta', 15, 4 );

/**
 * Persist guest breakdown on the order item.
 *
 * @param mixed  $item      Order item.
 * @param string $cart_key  Cart key.
 * @param array  $values    Cart line values.
 * @param mixed  $order     Order.
 */
function vila_akoya_checkout_save_guest_breakdown_meta( $item, $cart_key, $values, $order ) {
	unset( $cart_key, $order );
	$adults = ! empty( $values['mwb_mbfw_booking_values']['people_number'] ) ? (int) $values['mwb_mbfw_booking_values']['people_number'] : 0;
	$ch     = isset( $values['vila_guest_children'] ) ? (int) $values['vila_guest_children'] : 0;
	$inf    = isset( $values['vila_guest_infants'] ) ? (int) $values['vila_guest_infants'] : 0;
	$pet    = isset( $values['vila_guest_pets'] ) ? (int) $values['vila_guest_pets'] : 0;
	if ( $adults < 1 && 0 === $ch && 0 === $inf && 0 === $pet ) {
		return;
	}
	if ( $adults > 0 || $ch > 0 || $inf > 0 || $pet > 0 ) {
		$item->add_meta_data( __( 'Guests', 'vila-akoya' ), vila_akoya_guest_picker_summary_string( max( 1, $adults ), $ch, $inf, $pet ), true );
	}
}
add_action( 'woocommerce_checkout_create_order_line_item', 'vila_akoya_checkout_save_guest_breakdown_meta', 16, 4 );

/**
 * Persist primary interest on the order line item (admin, emails, customer order view).
 *
 * @param \WC_Order_Item $item     Order item.
 * @param string         $cart_key Cart key.
 * @param array          $values   Cart line values.
 * @param \WC_Order      $order    Order.
 */
function vila_akoya_checkout_save_primary_interest_meta( $item, $cart_key, $values, $order ) {
	unset( $cart_key, $order );
	if ( empty( $values['product_id'] ) || ! vila_akoya_is_campsite_product_id( (int) $values['product_id'] ) ) {
		return;
	}
	$slug = isset( $values['vila_primary_interest'] ) ? $values['vila_primary_interest'] : '';
	$slug = vila_akoya_normalize_primary_interest( $slug );
	if ( '' === $slug ) {
		return;
	}
	$item->add_meta_data( __( 'Primary interest', 'vila-akoya' ), vila_akoya_primary_interest_label( $slug ), true );
}
add_action( 'woocommerce_checkout_create_order_line_item', 'vila_akoya_checkout_save_primary_interest_meta', 17, 4 );

/**
 * Whether to show the legacy “Plot 1 / Plot 2 …” radios (one WC product per pitch).
 *
 * Pitches are assigned automatically from the shared pool; the plot picker stays off unless a site explicitly enables it.
 *
 * @return bool
 */
function vila_akoya_should_show_campsite_plot_selector() {
	return (bool) apply_filters( 'vila_akoya_show_campsite_plot_selector', false );
}

/**
 * Plot / pitch selector when multiple campsite products exist (one per plot).
 *
 * @param int $product_id Current product ID (typically plot 1 on the booking page).
 */
function vila_akoya_render_plot_selector( $product_id ) {
	if ( ! function_exists( 'vila_akoya_get_campsite_plot_product_ids' ) ) {
		return;
	}
	if ( ! vila_akoya_is_campsite_product_id( (int) $product_id ) ) {
		return;
	}
	if ( ! vila_akoya_should_show_campsite_plot_selector() ) {
		return;
	}
	$plots = vila_akoya_get_campsite_plot_product_ids();
	?>
	<fieldset class="vila-plot-fieldset space-y-3 mb-6 border-0 p-0 m-0">
		<legend class="font-label text-xs uppercase tracking-widest text-on-surface-variant mb-3 block">
			<?php esc_html_e( 'Pitch', 'vila-akoya' ); ?>
		</legend>
		<p class="text-sm text-on-surface-variant mb-3">
			<?php
			if ( class_exists( '\Vila_CB\WooCommerce_Integration' ) ) {
				esc_html_e( 'Pitch is assigned automatically from availability. The calendar only blocks nights when every pitch is fully booked.', 'vila-akoya' );
			} else {
				esc_html_e( 'Each pitch has its own calendar. Choose one — dates already taken on that pitch are blocked.', 'vila-akoya' );
			}
			?>
		</p>
		<div class="vila-plot-grid grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-2">
			<?php
			foreach ( $plots as $idx => $plot_pid ) :
				$plot_pid = (int) $plot_pid;
				$fid      = 'vila-plot-' . $plot_pid;
				?>
				<label class="vila-plot-option flex cursor-pointer items-center justify-center gap-2 rounded-lg border-2 border-transparent bg-surface-container-low px-3 py-3 transition-colors has-[:checked]:border-primary has-[:checked]:bg-primary/5">
					<input
						class="vila-plot-input sr-only"
						type="radio"
						name="vila_plot_product_id"
						id="<?php echo esc_attr( $fid ); ?>"
						value="<?php echo esc_attr( (string) $plot_pid ); ?>"
						<?php checked( $plot_pid, (int) $product_id ); ?>
					/>
					<span class="font-body text-sm text-on-surface font-medium">
						<?php
						printf(
							/* translators: %d: plot number */
							esc_html__( 'Plot %d', 'vila-akoya' ),
							(int) $idx + 1
						);
						?>
					</span>
				</label>
			<?php endforeach; ?>
		</div>
	</fieldset>
	<?php
}
/**
 * Render pitch / vehicle radios on the campsite booking product (MWB template).
 *
 * @param int $product_id Product ID.
 */
function vila_akoya_render_vehicle_selector( $product_id ) {
	if ( ! vila_akoya_is_campsite_product_id( (int) $product_id ) ) {
		return;
	}
	$types = vila_akoya_get_vehicle_types();
	if ( empty( $types ) ) {
		return;
	}
	$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '€';
	?>
	<div class="vila-airbnb-booking vila-airbnb-booking--units mb-6">
		<fieldset class="vila-vehicle-fieldset vila-airbnb-booking__card border-0 m-0 min-w-0">
			<legend class="vila-airbnb-booking__legend">
				<?php esc_html_e( 'Extras', 'vila-akoya' ); ?>
			</legend>
			<p class="vila-airbnb-booking__lead text-sm text-on-surface-variant mb-4 mt-0">
				<?php esc_html_e( 'Pitch type and optional add-ons. Select all that apply per night.', 'vila-akoya' ); ?>
			</p>
			<div class="vila-vehicle-grid vila-airbnb-booking__chip-grid">
				<?php
				foreach ( $types as $slug => $info ) :
					$fid = 'vila-vehicle-' . $slug;
					$fee = (float) $info['fee'];
					?>
					<label class="vila-vehicle-option vila-airbnb-booking__chip">
						<input
							class="vila-vehicle-input sr-only"
							type="checkbox"
							name="vila_vehicle_types[]"
							id="<?php echo esc_attr( $fid ); ?>"
							value="<?php echo esc_attr( $slug ); ?>"
							<?php echo 'tent' === $slug ? 'checked' : ''; ?>
							data-vila-fee="<?php echo esc_attr( (string) $fee ); ?>"
						/>
						<span class="vila-airbnb-booking__chip-body">
							<span class="vila-airbnb-booking__chip-title"><?php echo esc_html( $info['label'] ); ?></span>
							<?php
							if ( $fee > 0 ) {
								echo '<span class="vila-airbnb-booking__chip-meta">+';
								echo esc_html( $currency . sprintf( '%.0f', $fee ) );
								echo esc_html( _x( '/night', 'price suffix for pitch fee', 'vila-akoya' ) );
								echo '</span>';
							} else {
								echo '<span class="vila-airbnb-booking__chip-meta">' . esc_html__( 'Included', 'vila-akoya' ) . '</span>';
							}
							?>
						</span>
					</label>
				<?php endforeach; ?>
			</div>
			<p class="vila-vehicle-hint vila-airbnb-booking__hint mt-3 text-sm text-on-surface-variant mb-0" aria-live="polite"></p>
		</fieldset>
	</div>
	<?php
}

/**
 * Primary interest (Village / Campsite / Both) — optional; shown after unit type on the campsite booking form.
 *
 * @param int $product_id Product ID.
 */
function vila_akoya_render_primary_interest_selector( $product_id ) {
	if ( ! vila_akoya_is_campsite_product_id( (int) $product_id ) ) {
		return;
	}
	$choices = vila_akoya_get_primary_interest_choices();
	if ( empty( $choices ) ) {
		return;
	}
	?>
	<div class="vila-airbnb-booking vila-airbnb-booking--primary-interest mb-6">
		<fieldset class="vila-primary-interest-fieldset vila-airbnb-booking__card border-0 m-0 min-w-0">
			<legend class="vila-airbnb-booking__legend">
				<?php esc_html_e( 'Primary interest (optional)', 'vila-akoya' ); ?>
			</legend>
			<p class="vila-airbnb-booking__lead vila-primary-interest__explore text-sm text-on-surface-variant mb-4 mt-0">
				<span class="vila-primary-interest__explore-label"><?php esc_html_e( 'Explore first:', 'vila-akoya' ); ?></span>
				<span class="vila-primary-interest__explore-links">
					<a class="text-primary underline-offset-2 hover:underline" href="<?php echo esc_url( home_url( '/village/' ) ); ?>"><?php esc_html_e( 'Village', 'vila-akoya' ); ?></a>
					<span class="text-on-surface-variant/70" aria-hidden="true"><?php echo esc_html( ' · ' ); ?></span>
					<a class="text-primary underline-offset-2 hover:underline" href="<?php echo esc_url( home_url( '/campsite/' ) ); ?>"><?php esc_html_e( 'Campsite', 'vila-akoya' ); ?></a>
					<span class="text-on-surface-variant/70" aria-hidden="true"><?php echo esc_html( ' · ' ); ?></span>
					<a class="text-primary underline-offset-2 hover:underline" href="<?php echo esc_url( home_url( '/the-kids/' ) ); ?>"><?php esc_html_e( 'The Kids', 'vila-akoya' ); ?></a>
				</span>
			</p>
			<div class="vila-primary-interest-grid vila-airbnb-booking__chip-grid" role="radiogroup" aria-label="<?php esc_attr_e( 'Primary interest (optional)', 'vila-akoya' ); ?>" aria-required="false">
				<?php
				foreach ( $choices as $slug => $label ) :
					$fid = 'vila-primary-interest-' . $slug;
					?>
					<label class="vila-primary-interest-option vila-airbnb-booking__chip">
						<input
							class="vila-primary-interest-input sr-only"
							type="radio"
							name="vila_primary_interest"
							id="<?php echo esc_attr( $fid ); ?>"
							value="<?php echo esc_attr( $slug ); ?>"
						/>
						<span class="vila-airbnb-booking__chip-body">
							<span class="vila-airbnb-booking__chip-title"><?php echo esc_html( $label ); ?></span>
						</span>
					</label>
				<?php endforeach; ?>
			</div>
		</fieldset>
	</div>
	<?php
}
add_action( 'mwb_booking_after_add_to_cart_form', 'vila_akoya_render_vehicle_selector', 12 );
add_action( 'mwb_booking_after_add_to_cart_form', 'vila_akoya_render_primary_interest_selector', 18 );

/**
 * MWB: hide a zero “Base cost” row in the AJAX totals so pricing reads clearly for campsite stays.
 *
 * @param array<string, array{ title: string, value: float|string }> $charges Charge rows keyed by type.
 * @param int                                                           $product_id Product ID.
 * @return array<string, array{ title: string, value: float|string }>
 */
function vila_akoya_mbfw_simplify_booking_charges( $charges, $product_id ) {
	if ( ! vila_akoya_is_campsite_product_id( (int) $product_id ) ) {
		return $charges;
	}
	if ( isset( $charges['base_cost'] ) && (float) $charges['base_cost']['value'] <= 0 ) {
		unset( $charges['base_cost'] );
	}
	return $charges;
}
add_filter( 'mbfw_ajax_load_total_booking_charge_individually', 'vila_akoya_mbfw_simplify_booking_charges', 20, 2 );

/**
 * Replace MWB's generic rows with a clear per-night breakdown (pitch + unit supplements + guest supplements).
 *
 * MWB passes charge rows with numeric totals; we keep those totals authoritative for the pitch line and add
 * theme-calculated lines for vehicle and child/infant/pet supplements so the AJAX total matches checkout fees.
 *
 * @param array<string, array{ title: string, value: float|string }> $charges Charge rows keyed by type.
 * @param int                                                           $product_id Product ID.
 * @return array<string, array{ title: string, value: float|string }>
 */
function vila_akoya_mbfw_expand_booking_charge_breakdown( $charges, $product_id ) {
	$product_id = (int) $product_id;
	if ( ! vila_akoya_is_campsite_product_id( $product_id ) || ! function_exists( 'wps_booking_get_meta_data' ) ) {
		return $charges;
	}

	$gc = isset( $charges['general_cost']['value'] ) ? (float) $charges['general_cost']['value'] : 0.0;
	$bc = isset( $charges['base_cost']['value'] ) ? (float) $charges['base_cost']['value'] : 0.0;

	$nights = vila_akoya_nights_from_request();
	$adults = isset( $_POST['mwb_mbfw_people_number'] ) ? (int) wp_unslash( vila_akoya_scalar_request_string( $_POST['mwb_mbfw_people_number'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$adults = max( 1, $adults );

	$per_person = 'yes' === wps_booking_get_meta_data( $product_id, 'mwb_mbfw_is_booking_unit_cost_per_people', true );
	$raw_unit   = (float) wps_booking_get_meta_data( $product_id, 'mwb_mbfw_booking_unit_cost', true );
	if ( $raw_unit <= 0 ) {
		$raw_unit = (float) wps_booking_get_meta_data( $product_id, '_price', true );
	}
	$unit_rate = (float) apply_filters( 'vila_cb_mwb_pitch_unit_rate_per_night', $raw_unit, $product_id );
	$expected_pitch = $per_person ? $unit_rate * $adults * $nights : $unit_rate * $nights;
	// MWB's general_cost uses product meta; when Campsite Settings overrides the adult rate, recompute the pitch line so AJAX matches checkout.
	$pitch_value = $gc;
	if ( abs( $raw_unit - $unit_rate ) > 0.0001 ) {
		$pitch_value = $expected_pitch;
	}

	$night_word = ( 1 === $nights )
		? __( 'night', 'vila-akoya' )
		: __( 'nights', 'vila-akoya' );

	if ( $per_person ) {
		$pitch_title = sprintf(
			/* translators: 1: adults count, 2: formatted price per adult per night, 3: nights count, 4: "night" or "nights" */
			__( 'Pitch rate — %1$d adults × %2$s/adult/night × %3$d %4$s', 'vila-akoya' ),
			$adults,
			vila_akoya_format_price_plain( $unit_rate ),
			$nights,
			$night_word
		);
	} else {
		$pitch_title = sprintf(
			/* translators: 1: formatted price per night, 2: nights count, 3: "night" or "nights" */
			__( 'Pitch rate — %1$s/night × %2$d %3$s', 'vila-akoya' ),
			vila_akoya_format_price_plain( $unit_rate ),
			$nights,
			$night_word
		);
	}

	$expected_meta_pitch = $per_person
		? $raw_unit * $adults * $nights
		: $raw_unit * $nights;
	if ( $gc > 0 && abs( $expected_meta_pitch - $gc ) > 0.02 ) {
		$pitch_title .= ' — ' . __( 'stay total (rate may include date rules)', 'vila-akoya' );
	}

	$new = array();

	if ( $bc > 0 && isset( $charges['base_cost']['title'] ) ) {
		$new['base_cost'] = $charges['base_cost'];
	}

	$new['vila_pitch'] = array(
		'title' => $pitch_title,
		'value' => $pitch_value,
	);

	if ( isset( $charges['service_cost'] ) && (float) $charges['service_cost']['value'] > 0 ) {
		$new['service_cost'] = $charges['service_cost'];
	}

	$raw_types = array();
	if ( isset( $_POST['vila_vehicle_types'] ) && is_array( $_POST['vila_vehicle_types'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw_types = $_POST['vila_vehicle_types'];
	} elseif ( isset( $_POST['vila_vehicle_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw_types = array( wp_unslash( vila_akoya_scalar_request_string( $_POST['vila_vehicle_type'] ) ) );
	}
	$vehicle_slugs = vila_akoya_normalize_vehicle_types_array( $raw_types );
	$by_head       = (bool) apply_filters( 'vila_akoya_pitch_supplement_multiplies_by_guests', false, array() );

	foreach ( $vehicle_slugs as $slug ) {
		$fee = vila_akoya_vehicle_fee_per_night( $slug );
		if ( $fee <= 0 ) {
			continue;
		}
		$line_total = $fee * $nights * ( $by_head ? $adults : 1 );
		$label      = vila_akoya_vehicle_label( $slug );
		if ( $by_head ) {
			$title = sprintf(
				/* translators: 1: unit label, 2: formatted price per night, 3: guest count, 4: nights, 5: night/nights */
				__( '%1$s — %2$s/night × %3$d guests × %4$d %5$s', 'vila-akoya' ),
				$label,
				vila_akoya_format_price_plain( $fee ),
				$adults,
				$nights,
				$night_word
			);
		} else {
			$title = sprintf(
				/* translators: 1: unit label, 2: formatted price per night, 3: nights, 4: night/nights */
				__( '%1$s — %2$s/night × %3$d %4$s', 'vila-akoya' ),
				$label,
				vila_akoya_format_price_plain( $fee ),
				$nights,
				$night_word
			);
		}
		$new[ 'vila_vehicle_' . $slug ] = array(
			'title' => $title,
			'value' => $line_total,
		);
	}

	$rates = vila_akoya_get_guest_category_nightly_rates( $product_id );
	$ch    = isset( $_POST['vila_guest_children'] ) ? (int) wp_unslash( vila_akoya_scalar_request_string( $_POST['vila_guest_children'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$inf   = isset( $_POST['vila_guest_infants'] ) ? (int) wp_unslash( vila_akoya_scalar_request_string( $_POST['vila_guest_infants'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$pet   = isset( $_POST['vila_guest_pets'] ) ? (int) wp_unslash( vila_akoya_scalar_request_string( $_POST['vila_guest_pets'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$ch    = max( 0, $ch );
	$inf   = max( 0, $inf );
	$pet   = max( 0, $pet );

	if ( $ch > 0 && (float) $rates['children'] > 0 ) {
		$r           = (float) $rates['children'];
		$line_total  = $ch * $r * $nights;
		$new['vila_guest_children'] = array(
			'title' => sprintf(
				/* translators: 1: children count, 2: price/night each, 3: nights, 4: night/nights */
				__( 'Children — %1$d × %2$s/night × %3$d %4$s', 'vila-akoya' ),
				$ch,
				vila_akoya_format_price_plain( $r ),
				$nights,
				$night_word
			),
			'value' => $line_total,
		);
	}
	if ( $inf > 0 && (float) $rates['infants'] > 0 ) {
		$r           = (float) $rates['infants'];
		$line_total  = $inf * $r * $nights;
		$new['vila_guest_infants'] = array(
			'title' => sprintf(
				/* translators: 1: infants count, 2: price/night each, 3: nights, 4: night/nights */
				__( 'Infants — %1$d × %2$s/night × %3$d %4$s', 'vila-akoya' ),
				$inf,
				vila_akoya_format_price_plain( $r ),
				$nights,
				$night_word
			),
			'value' => $line_total,
		);
	}
	if ( $pet > 0 && (float) $rates['pets'] > 0 ) {
		$r           = (float) $rates['pets'];
		$line_total  = $pet * $r * $nights;
		$new['vila_guest_pets'] = array(
			'title' => sprintf(
				/* translators: 1: pets count, 2: price/night each, 3: nights, 4: night/nights */
				__( 'Pets — %1$d × %2$s/night × %3$d %4$s', 'vila-akoya' ),
				$pet,
				vila_akoya_format_price_plain( $r ),
				$nights,
				$night_word
			),
			'value' => $line_total,
		);
	}

	if ( isset( $charges['additional_charge'] ) && (float) $charges['additional_charge']['value'] > 0 ) {
		$new['additional_charge'] = $charges['additional_charge'];
	}

	return $new;
}
add_filter( 'mbfw_ajax_load_total_booking_charge_individually', 'vila_akoya_mbfw_expand_booking_charge_breakdown', 30, 2 );

/**
 * Enqueue booking UI script on booking page and campsite product views.
 */
function vila_akoya_enqueue_booking_ui_script() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	$pid = function_exists( 'vila_akoya_get_campsite_primary_product_id' ) ? (int) vila_akoya_get_campsite_primary_product_id() : (int) get_option( 'vila_akoya_campsite_product_id' );
	if ( ! $pid && function_exists( 'wc_get_product_id_by_sku' ) ) {
		$pid = (int) wc_get_product_id_by_sku( VILA_AKOYA_CAMPSITE_SKU );
	}
	if ( ! $pid ) {
		return;
	}
	$load = is_page( 'booking' ) || ( function_exists( 'is_product' ) && is_product() && vila_akoya_is_campsite_product_id( get_queried_object_id() ) );
	if ( ! $load ) {
		return;
	}
	wp_enqueue_script(
		'vila-akoya-booking-ui',
		VILA_AKOYA_URI . '/assets/js/booking-ui.js',
		array( 'jquery', 'flatpicker_js', 'bookings-for-woocommercepublic' ),
		VILA_AKOYA_VERSION,
		true
	);
	$rates = array();
	foreach ( vila_akoya_get_vehicle_types() as $slug => $info ) {
		$rates[ $slug ] = (float) $info['fee'];
	}
	$guest_rates = vila_akoya_get_guest_category_nightly_rates( $pid );

	$plot_ids     = function_exists( 'vila_akoya_get_campsite_plot_product_ids' ) ? vila_akoya_get_campsite_plot_product_ids() : array();
	$plot_unavail = array();
	$vila_cb      = class_exists( '\Vila_CB\WooCommerce_Integration' );
	// Shared pool: calendar greys out a night only when all pitches are full (Vila REST). Used whenever the plugin is active, including legacy plot radios.
	$use_vila_pool_calendar = $vila_cb;
	$plot_ids_js            = ( ! vila_akoya_should_show_campsite_plot_selector() || count( $plot_ids ) < 2 )
		? array( (int) $pid )
		: array_map( 'intval', $plot_ids );

	if ( ! empty( $plot_ids ) && function_exists( 'vila_akoya_get_plot_unavailable_ymd_for_product' ) ) {
		if ( $vila_cb ) {
			$plot_unavail[ (string) (int) $pid ] = vila_akoya_get_plot_unavailable_ymd_for_product( (int) $pid );
		} else {
			foreach ( $plot_ids as $ppid ) {
				$plot_unavail[ (string) (int) $ppid ] = vila_akoya_get_plot_unavailable_ymd_for_product( (int) $ppid );
			}
		}
	}

	wp_localize_script(
		'vila-akoya-booking-ui',
		'vilaAkoyaBooking',
		array(
			'defaultPlotProductId'      => (int) $pid,
			'plotProductIds'             => $plot_ids_js,
			'plotUnavailableYmd'         => $plot_unavail,
			'useSharedCampsiteInventory' => $use_vila_pool_calendar,
			'vilaCbAvailabilityRestUrl'  => $use_vila_pool_calendar ? esc_url_raw( rest_url( 'vila-campsite-booking/v1/availability' ) ) : '',
			'currencySymbol'            => get_woocommerce_currency_symbol(),
			'pitchSupplementPerGuest'   => (bool) apply_filters( 'vila_akoya_pitch_supplement_multiplies_by_guests', false, array() ),
			'vehicleRates'              => $rates,
			'guestCategoryRates'        => array(
				'children' => (float) $guest_rates['children'],
				'infants'  => (float) $guest_rates['infants'],
				'pets'     => (float) $guest_rates['pets'],
			),
			'strings'                   => array(
				'stayDates'               => __( 'Stay dates', 'vila-akoya' ),
				'rangeUnavailable'        => __( 'No pitch is free for this full stay on those dates. Choose different check-in and check-out — some nights can look available when each pitch is booked on different nights.', 'vila-akoya' ),
				'placeholder'             => __( 'Check-in — Check-out', 'vila-akoya' ),
				'supplement'              => __( 'Extras estimate', 'vila-akoya' ),
				'finalAtCheckout'         => __( 'Final amount includes taxes & fees at checkout.', 'vila-akoya' ),
				'nightSingular'           => __( 'night', 'vila-akoya' ),
				'nightPlural'             => __( 'nights', 'vila-akoya' ),
				'guestCountAffectsRate'   => __( 'The amount above reflects adults at the per-guest nightly rate. Extra categories add supplements at checkout.', 'vila-akoya' ),
			),
			'guestStrings'              => array(
				'oneGuest' => __( '1 guest', 'vila-akoya' ),
				/* translators: %d will be replaced in JS */
				'nGuests'  => __( '%d guests', 'vila-akoya' ),
				'onePet'   => __( '1 pet', 'vila-akoya' ),
				/* translators: %d will be replaced in JS */
				'nPets'    => __( '%d pets', 'vila-akoya' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'vila_akoya_enqueue_booking_ui_script', 30 );
