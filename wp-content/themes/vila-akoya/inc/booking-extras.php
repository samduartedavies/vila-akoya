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
 * Plain-text price for breakdown titles and chip meta (no HTML).
 *
 * Always places the currency symbol before the number (e.g. €5).
 * Whole amounts omit decimals (€5); fractional amounts keep two digits (€7,50).
 *
 * @param float $amount Amount in store currency.
 * @return string
 */
function vila_akoya_format_price_plain( $amount ) {
	$amount  = (float) $amount;
	$rounded = round( $amount, 2 );
	$symbol  = '€';
	if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
		$symbol = html_entity_decode( (string) get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
	}
	$dec_sep  = function_exists( 'wc_get_price_decimal_separator' ) ? (string) wc_get_price_decimal_separator() : ',';
	$thou_sep = function_exists( 'wc_get_price_thousand_separator' ) ? (string) wc_get_price_thousand_separator() : '.';
	if ( abs( $rounded - round( $rounded ) ) < 0.00001 ) {
		$num = number_format( $rounded, 0, $dec_sep, $thou_sep );
	} else {
		$num = number_format( $rounded, 2, $dec_sep, $thou_sep );
	}
	return $symbol . $num;
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
				'fee'   => 3,
			),
			'caravan'      => array(
				'label' => __( 'Caravan', 'vila-akoya' ),
				'fee'   => 5,
			),
			'camper'       => array(
				'label' => __( 'Campervan', 'vila-akoya' ),
				'fee'   => 6,
			),
			'electricity'  => array(
				'label' => __( 'Include electricity', 'vila-akoya' ),
				'fee'   => 2,
			),
			'car'          => array(
				'label' => __( 'Car', 'vila-akoya' ),
				'fee'   => 2,
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
		$out = array();
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
	return array();
}

/**
 * Optional meal add-ons (per person per day). Filter to change amounts/labels.
 *
 * day_mode: `all_nights` = every night of the stay; `weekdays` = Mon–Fri nights only.
 *
 * @return array<string, array{ label: string, adult_fee: float, child_fee: float, infant_fee: float, day_mode: string, meta: string }>
 */
function vila_akoya_get_meal_options() {
	$breakfast_meta = sprintf(
		/* translators: 1: adult price plain, 2: child price plain */
		__( 'Adults %1$s · Kids %2$s · Under 2 free', 'vila-akoya' ),
		vila_akoya_format_price_plain( 5 ),
		vila_akoya_format_price_plain( 3 )
	);
	$dinner_meta = sprintf(
		/* translators: 1: adult price plain, 2: child price plain */
		__( 'Adults %1$s · Kids %2$s · Under 2 free', 'vila-akoya' ),
		vila_akoya_format_price_plain( 10 ),
		vila_akoya_format_price_plain( 7.5 )
	);

	$defaults = array(
		'breakfast' => array(
			'label'      => __( 'Bio breakfast & coffee', 'vila-akoya' ),
			'adult_fee'  => 5.0,
			'child_fee'  => 3.0,
			'infant_fee' => 0.0,
			'day_mode'   => 'all_nights',
			'meta'       => $breakfast_meta,
		),
		'dinner'    => array(
			'label'      => __( 'Weekday dinner (Mon–Fri)', 'vila-akoya' ),
			'adult_fee'  => 10.0,
			'child_fee'  => 7.5,
			'infant_fee' => 0.0,
			'day_mode'   => 'weekdays',
			'meta'       => $dinner_meta,
		),
	);

	$options = apply_filters( 'vila_akoya_meal_options', $defaults );
	if ( ! is_array( $options ) ) {
		return array();
	}

	// Rebuild meta from (possibly filtered) rates so admin overrides stay in sync.
	foreach ( $options as $slug => &$row ) {
		if ( ! is_array( $row ) ) {
			unset( $options[ $slug ] );
			continue;
		}
		$row['adult_fee']  = isset( $row['adult_fee'] ) ? (float) $row['adult_fee'] : 0.0;
		$row['child_fee']  = isset( $row['child_fee'] ) ? (float) $row['child_fee'] : 0.0;
		$row['infant_fee'] = isset( $row['infant_fee'] ) ? (float) $row['infant_fee'] : 0.0;
		$row['day_mode']   = isset( $row['day_mode'] ) && 'weekdays' === $row['day_mode'] ? 'weekdays' : 'all_nights';
		if ( 'dinner' === $slug || 'weekdays' === $row['day_mode'] ) {
			$row['label'] = __( 'Weekday dinner (Mon–Fri)', 'vila-akoya' );
		} else {
			$row['label'] = isset( $row['label'] ) ? (string) $row['label'] : (string) $slug;
		}
		$row['meta'] = sprintf(
			/* translators: 1: adult price plain, 2: child price plain */
			__( 'Adults %1$s · Kids %2$s · Under 2 free', 'vila-akoya' ),
			vila_akoya_format_price_plain( $row['adult_fee'] ),
			vila_akoya_format_price_plain( $row['child_fee'] )
		);
	}
	unset( $row );

	return $options;
}

/**
 * @param array<int|string>|string $raw Slugs from request or storage.
 * @return string[]
 */
function vila_akoya_normalize_meal_options_array( $raw ) {
	$keys = array_keys( vila_akoya_get_meal_options() );
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
	return $out;
}

/**
 * Meal slugs selected on a cart line.
 *
 * @param array<string, mixed> $cart_item Cart row.
 * @return string[]
 */
function vila_akoya_cart_meal_options( array $cart_item ) {
	if ( empty( $cart_item['vila_meal_options'] ) || ! is_array( $cart_item['vila_meal_options'] ) ) {
		return array();
	}
	return vila_akoya_normalize_meal_options_array( $cart_item['vila_meal_options'] );
}

/**
 * Human-readable list of selected meal labels.
 *
 * @param string[] $slugs Normalized meal slugs.
 * @return string
 */
function vila_akoya_meal_labels_join( array $slugs ) {
	$options = vila_akoya_get_meal_options();
	$labels  = array();
	foreach ( vila_akoya_normalize_meal_options_array( $slugs ) as $slug ) {
		$labels[] = isset( $options[ $slug ]['label'] ) ? $options[ $slug ]['label'] : $slug;
	}
	return implode( __( ', ', 'vila-akoya' ), $labels );
}

/**
 * Count Mon–Fri nights between check-in (inclusive) and check-out (exclusive).
 *
 * @param DateTimeInterface $from Check-in.
 * @param DateTimeInterface $to   Check-out.
 * @return int
 */
function vila_akoya_count_weekday_nights( $from, $to ) {
	if ( ! ( $from instanceof DateTimeInterface ) || ! ( $to instanceof DateTimeInterface ) ) {
		return 0;
	}
	$cur = DateTimeImmutable::createFromInterface( $from )->setTime( 0, 0, 0 );
	$end = DateTimeImmutable::createFromInterface( $to )->setTime( 0, 0, 0 );
	if ( $end <= $cur ) {
		return 0;
	}
	$count = 0;
	while ( $cur < $end ) {
		$n = (int) $cur->format( 'N' ); // 1 = Mon … 7 = Sun.
		if ( $n <= 5 ) {
			++$count;
		}
		$cur = $cur->modify( '+1 day' );
	}
	return $count;
}

/**
 * Weekday (Mon–Fri) nights from MWB date fields on the current request.
 *
 * @return int
 */
function vila_akoya_weekday_nights_from_request() {
	$from = isset( $_POST['mwb_mbfw_booking_from_time'] ) ? sanitize_text_field( wp_unslash( vila_akoya_scalar_request_string( $_POST['mwb_mbfw_booking_from_time'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$to   = isset( $_POST['mwb_mbfw_booking_to_time'] ) ? sanitize_text_field( wp_unslash( vila_akoya_scalar_request_string( $_POST['mwb_mbfw_booking_to_time'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( '' === $from || '' === $to ) {
		return 0;
	}
	$df = DateTime::createFromFormat( 'd-m-Y', $from, wp_timezone() );
	$dt = DateTime::createFromFormat( 'd-m-Y', $to, wp_timezone() );
	if ( ! $df || ! $dt ) {
		return 0;
	}
	return vila_akoya_count_weekday_nights( $df, $dt );
}

/**
 * Weekday nights for a cart line (stored count, booking timestamps, or check-in/out YMD).
 *
 * @param array<string, mixed> $cart_item Cart line.
 * @return int
 */
function vila_akoya_cart_weekday_nights_for_line( array $cart_item ) {
	if ( isset( $cart_item['vila_meal_weekday_nights'] ) ) {
		return max( 0, (int) $cart_item['vila_meal_weekday_nights'] );
	}
	if ( ! empty( $cart_item['booking']['_start_date'] ) && ! empty( $cart_item['booking']['_end_date'] ) ) {
		$start = (int) $cart_item['booking']['_start_date'];
		$end   = (int) $cart_item['booking']['_end_date'];
		if ( $end > $start ) {
			$tz = wp_timezone();
			$df = ( new DateTimeImmutable( '@' . $start ) )->setTimezone( $tz );
			$dt = ( new DateTimeImmutable( '@' . $end ) )->setTimezone( $tz );
			return vila_akoya_count_weekday_nights( $df, $dt );
		}
	}
	$cin  = isset( $cart_item['vila_cb_check_in'] ) ? (string) $cart_item['vila_cb_check_in'] : '';
	$cout = isset( $cart_item['vila_cb_check_out'] ) ? (string) $cart_item['vila_cb_check_out'] : '';
	if ( '' !== $cin && '' !== $cout ) {
		$df = DateTime::createFromFormat( 'Y-m-d', $cin, wp_timezone() );
		$dt = DateTime::createFromFormat( 'Y-m-d', $cout, wp_timezone() );
		if ( $df && $dt ) {
			return vila_akoya_count_weekday_nights( $df, $dt );
		}
	}
	return 0;
}

/**
 * Chargeable meal days for a slug given total nights and weekday nights.
 *
 * @param array{ day_mode?: string } $info Meal option row.
 * @param int                        $nights Total nights.
 * @param int                        $weekday_nights Mon–Fri nights.
 * @return int
 */
function vila_akoya_meal_days_for_option( array $info, $nights, $weekday_nights ) {
	$nights         = max( 0, (int) $nights );
	$weekday_nights = max( 0, (int) $weekday_nights );
	if ( isset( $info['day_mode'] ) && 'weekdays' === $info['day_mode'] ) {
		return $weekday_nights;
	}
	return $nights;
}

/**
 * Line total for one meal option.
 *
 * @param array{ adult_fee?: float, child_fee?: float, infant_fee?: float } $info Meal rates.
 * @param int                                                              $adults Adults.
 * @param int                                                              $children Children (ages 2–12).
 * @param int                                                              $infants Infants (under 2).
 * @param int                                                              $days Chargeable days.
 * @return float
 */
function vila_akoya_meal_line_total( array $info, $adults, $children, $infants, $days ) {
	$days = max( 0, (int) $days );
	if ( $days < 1 ) {
		return 0.0;
	}
	$adult_fee  = isset( $info['adult_fee'] ) ? (float) $info['adult_fee'] : 0.0;
	$child_fee  = isset( $info['child_fee'] ) ? (float) $info['child_fee'] : 0.0;
	$infant_fee = isset( $info['infant_fee'] ) ? (float) $info['infant_fee'] : 0.0;
	$per_day    = ( max( 0, (int) $adults ) * $adult_fee )
		+ ( max( 0, (int) $children ) * $child_fee )
		+ ( max( 0, (int) $infants ) * $infant_fee );
	return $per_day * $days;
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
		'children' => 6.0,
		'infants'  => 3.0,
		'pets'     => 2.0,
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
	if ( ! empty( $types ) ) {
		$cart_item_data['vila_vehicle_type'] = $types[0];
	} else {
		unset( $cart_item_data['vila_vehicle_type'] );
	}
	$cart_item_data['vila_booking_nights'] = vila_akoya_nights_from_request();
	return $cart_item_data;
}
add_filter( 'woocommerce_add_cart_item_data', 'vila_akoya_add_vehicle_cart_item_data', 30, 3 );

/**
 * Persist meal add-on choices and weekday-night count on the cart line.
 *
 * @param array<string, mixed> $cart_item_data Cart item data.
 * @param int                  $product_id     Product ID.
 * @param int                  $variation_id   Variation ID.
 * @return array<string, mixed>
 */
function vila_akoya_add_meal_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
	unset( $variation_id );
	if ( ! vila_akoya_is_campsite_product_id( (int) $product_id ) ) {
		return $cart_item_data;
	}
	if ( ! isset( $_POST['_mwb_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( vila_akoya_scalar_request_string( $_POST['_mwb_nonce'] ) ) ), 'mwb_booking_frontend' ) ) {
		return $cart_item_data;
	}
	$raw = isset( $_POST['vila_meal_options'] ) && is_array( $_POST['vila_meal_options'] ) ? $_POST['vila_meal_options'] : array();
	$cart_item_data['vila_meal_options']        = vila_akoya_normalize_meal_options_array( $raw );
	$cart_item_data['vila_meal_weekday_nights'] = vila_akoya_weekday_nights_from_request();
	return $cart_item_data;
}
add_filter( 'woocommerce_add_cart_item_data', 'vila_akoya_add_meal_cart_item_data', 33, 3 );

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
 * Sanitize optional guest notes from the booking form.
 *
 * @param string $raw Raw notes.
 * @return string
 */
function vila_akoya_sanitize_booking_notes( $raw ) {
	$notes = sanitize_textarea_field( (string) $raw );
	if ( strlen( $notes ) > 2000 ) {
		$notes = substr( $notes, 0, 2000 );
	}
	return trim( $notes );
}

/**
 * Persist guest notes on the campsite cart line (MWB add-to-cart).
 *
 * @param array<string, mixed> $cart_item_data Cart item data.
 * @param int                  $product_id     Product ID.
 * @param int                  $variation_id   Variation ID.
 * @return array<string, mixed>
 */
function vila_akoya_add_booking_notes_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
	unset( $variation_id );
	if ( ! vila_akoya_is_campsite_product_id( (int) $product_id ) ) {
		return $cart_item_data;
	}
	if ( ! isset( $_POST['_mwb_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( vila_akoya_scalar_request_string( $_POST['_mwb_nonce'] ) ) ), 'mwb_booking_frontend' ) ) {
		return $cart_item_data;
	}
	if ( ! isset( $_POST['vila_cb_booking_notes'] ) ) {
		return $cart_item_data;
	}
	$notes = vila_akoya_sanitize_booking_notes( wp_unslash( vila_akoya_scalar_request_string( $_POST['vila_cb_booking_notes'] ) ) );
	if ( '' !== $notes ) {
		$cart_item_data['vila_cb_booking_notes'] = $notes;
	}
	return $cart_item_data;
}
add_filter( 'woocommerce_add_cart_item_data', 'vila_akoya_add_booking_notes_cart_item_data', 32, 3 );

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
	foreach ( array( 'vila_vehicle_types', 'vila_vehicle_type', 'vila_booking_nights', 'vila_guest_children', 'vila_guest_infants', 'vila_guest_pets', 'vila_cb_booking_notes', 'vila_meal_options', 'vila_meal_weekday_nights' ) as $key ) {
		if ( isset( $values[ $key ] ) ) {
			$cart_item[ $key ] = $values[ $key ];
		}
	}
	if ( ! empty( $cart_item['vila_vehicle_types'] ) && is_array( $cart_item['vila_vehicle_types'] ) ) {
		$cart_item['vila_vehicle_types'] = vila_akoya_normalize_vehicle_types_array( $cart_item['vila_vehicle_types'] );
	} else	if ( ! empty( $cart_item['vila_vehicle_type'] ) ) {
		$cart_item['vila_vehicle_types'] = vila_akoya_normalize_vehicle_types_array( array( $cart_item['vila_vehicle_type'] ) );
	}
	if ( ! empty( $cart_item['vila_meal_options'] ) && is_array( $cart_item['vila_meal_options'] ) ) {
		$cart_item['vila_meal_options'] = vila_akoya_normalize_meal_options_array( $cart_item['vila_meal_options'] );
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
		WC()->cart->add_fee( __( 'Children', 'vila-akoya' ), $totals['children'] );
	}
	if ( $totals['infants'] > 0 ) {
		WC()->cart->add_fee( __( 'Babies — nightly supplement', 'vila-akoya' ), $totals['infants'] );
	}
	if ( $totals['pets'] > 0 ) {
		WC()->cart->add_fee( __( 'Pets', 'vila-akoya' ), $totals['pets'] );
	}
}
add_action( 'woocommerce_cart_calculate_fees', 'vila_akoya_cart_guest_category_fees', 25 );


/**
 * Per-person meal add-ons (breakfast every night; dinner Mon–Fri nights).
 */
function vila_akoya_cart_meal_fees() {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}

	$meal_catalog = vila_akoya_get_meal_options();

	foreach ( WC()->cart->get_cart() as $cart_item ) {
		if ( ! vila_akoya_is_campsite_product_id( (int) $cart_item['product_id'] ) ) {
			continue;
		}
		$meals = vila_akoya_cart_meal_options( $cart_item );
		if ( empty( $meals ) ) {
			continue;
		}
		$nights         = vila_akoya_cart_nights_for_line( $cart_item );
		$weekday_nights = vila_akoya_cart_weekday_nights_for_line( $cart_item );
		$adults         = vila_akoya_cart_people_for_line( $cart_item );
		$children       = isset( $cart_item['vila_guest_children'] ) ? (int) $cart_item['vila_guest_children'] : 0;
		$infants        = isset( $cart_item['vila_guest_infants'] ) ? (int) $cart_item['vila_guest_infants'] : 0;

		foreach ( $meals as $slug ) {
			if ( ! isset( $meal_catalog[ $slug ] ) ) {
				continue;
			}
			$info  = $meal_catalog[ $slug ];
			$days  = vila_akoya_meal_days_for_option( $info, $nights, $weekday_nights );
			$total = vila_akoya_meal_line_total( $info, $adults, $children, $infants, $days );
			if ( $total <= 0 ) {
				continue;
			}
			$day_word = ( 1 === $days )
				? __( 'day', 'vila-akoya' )
				: __( 'days', 'vila-akoya' );
			WC()->cart->add_fee(
				sprintf(
					/* translators: 1: meal label, 2: number of days, 3: "day" or "days" */
					__( '%1$s (%2$d %3$s)', 'vila-akoya' ),
					$info['label'],
					$days,
					$day_word
				),
				$total
			);
		}
	}
}
add_action( 'woocommerce_cart_calculate_fees', 'vila_akoya_cart_meal_fees', 26 );

/**
 * Safety net: keep the campsite pitch line adults-only for the MWB booking flow.
 *
 * The theme itemises children/infants/pets as their own checkout fees
 * (vila_akoya_cart_guest_category_fees). The pitch line must therefore price
 * adults only, otherwise those guests are billed twice — once inside the pitch
 * line and once as the separate fee. The plugin already does this when up to
 * date, but this override guarantees correct totals even if an older plugin
 * build (which folds every guest category into the pitch line) is deployed.
 *
 * Runs after the plugin's own price hook (priority 100000) and mirrors its
 * formula (adult rate × adults × nights, honouring vila_cb_adult_rate_per_night),
 * so it produces an identical result when the up-to-date plugin is present.
 *
 * @param WC_Cart $cart Cart.
 * @return void
 */
function vila_akoya_force_pitch_line_adults_only( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}
	if ( ! is_object( $cart ) || ! is_callable( array( $cart, 'get_cart' ) ) ) {
		return;
	}
	foreach ( $cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['product_id'] ) || ! vila_akoya_is_campsite_product_id( (int) $cart_item['product_id'] ) ) {
			continue;
		}
		// Only the MWB booking flow itemises guest categories as separate fees.
		// The plugin's own shortcode flow has no vila_guest_* keys and prices
		// every guest into the pitch line, so those lines are left untouched.
		$mwb_flow = isset( $cart_item['vila_guest_children'] )
			|| isset( $cart_item['vila_guest_infants'] )
			|| isset( $cart_item['vila_guest_pets'] );
		if ( ! $mwb_flow ) {
			continue;
		}
		if ( empty( $cart_item['data'] ) || ! is_object( $cart_item['data'] ) ) {
			continue;
		}
		$product = $cart_item['data'];
		$pid     = (int) $cart_item['product_id'];

		$nights = 0;
		$cin    = isset( $cart_item['vila_cb_check_in'] ) ? (string) $cart_item['vila_cb_check_in'] : '';
		$cout   = isset( $cart_item['vila_cb_check_out'] ) ? (string) $cart_item['vila_cb_check_out'] : '';
		if ( '' !== $cin && '' !== $cout ) {
			$df = DateTime::createFromFormat( 'Y-m-d', $cin, wp_timezone() );
			$dt = DateTime::createFromFormat( 'Y-m-d', $cout, wp_timezone() );
			if ( $df && $dt ) {
				$nights = max( 0, (int) $df->diff( $dt )->format( '%a' ) );
			}
		}
		if ( $nights < 1 ) {
			$nights = vila_akoya_cart_nights_for_line( $cart_item );
		}
		if ( $nights < 1 ) {
			continue;
		}

		$adults = 1;
		if ( ! empty( $cart_item['vila_cb_guests']['adults'] ) ) {
			$adults = max( 1, (int) $cart_item['vila_cb_guests']['adults'] );
		} elseif ( ! empty( $cart_item['mwb_mbfw_booking_values']['people_number'] ) ) {
			$adults = max( 1, (int) $cart_item['mwb_mbfw_booking_values']['people_number'] );
		}

		$rate = (float) apply_filters( 'vila_cb_adult_rate_per_night', (float) $product->get_regular_price(), $pid );
		if ( $rate <= 0 ) {
			continue;
		}

		$product->set_price( $adults * $rate * $nights );
	}
}
add_action( 'woocommerce_before_calculate_totals', 'vila_akoya_force_pitch_line_adults_only', 1000000 );

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
 * Show meal add-ons on cart / checkout line.
 *
 * @param array<string, mixed> $item_data Item data.
 * @param array<string, mixed> $cart_item Cart item.
 * @return array<string, mixed>
 */
function vila_akoya_get_item_data_meals( $item_data, $cart_item ) {
	$meals = vila_akoya_cart_meal_options( $cart_item );
	if ( empty( $meals ) ) {
		return $item_data;
	}
	$item_data[] = array(
		'name'    => __( 'Meals', 'vila-akoya' ),
		'display' => esc_html( vila_akoya_meal_labels_join( $meals ) ),
	);
	return $item_data;
}
add_filter( 'woocommerce_get_item_data', 'vila_akoya_get_item_data_meals', 17, 2 );


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
 * Persist meal add-ons on the order line.
 *
 * @param mixed  $item      Order item.
 * @param string $cart_key  Cart key.
 * @param array  $values    Cart line values.
 * @param mixed  $order     Order.
 */
function vila_akoya_checkout_save_meal_meta( $item, $cart_key, $values, $order ) {
	unset( $cart_key, $order );
	$meals = vila_akoya_cart_meal_options( is_array( $values ) ? $values : array() );
	if ( empty( $meals ) ) {
		return;
	}
	$item->add_meta_data( __( 'Meals', 'vila-akoya' ), vila_akoya_meal_labels_join( $meals ), true );
	$item->add_meta_data( '_vila_meal_options', wp_json_encode( $meals ), false );
}
add_action( 'woocommerce_checkout_create_order_line_item', 'vila_akoya_checkout_save_meal_meta', 18, 4 );


/**
 * Persist guest notes on the order line (admin only; hidden from customer views).
 *
 * @param mixed  $item      Order item.
 * @param string $cart_key  Cart key.
 * @param array  $values    Cart line values.
 * @param mixed  $order     Order.
 */
function vila_akoya_checkout_save_booking_notes_meta( $item, $cart_key, $values, $order ) {
	unset( $order );
	if ( ! is_object( $item ) || ! is_callable( array( $item, 'get_product_id' ) ) ) {
		return;
	}
	if ( ! vila_akoya_is_campsite_product_id( (int) $item->get_product_id() ) ) {
		return;
	}
	$merged = is_array( $values ) ? $values : array();
	if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() && is_string( $cart_key ) ) {
		$cart = WC()->cart->get_cart();
		if ( isset( $cart[ $cart_key ] ) && is_array( $cart[ $cart_key ] ) ) {
			$merged = array_merge( $cart[ $cart_key ], $merged );
		}
	}
	$notes = isset( $merged['vila_cb_booking_notes'] ) ? vila_akoya_sanitize_booking_notes( (string) $merged['vila_cb_booking_notes'] ) : '';
	if ( '' !== $notes ) {
		$item->add_meta_data( '_vila_cb_booking_notes', $notes, true );
	}
}
add_action( 'woocommerce_checkout_create_order_line_item', 'vila_akoya_checkout_save_booking_notes_meta', 17, 4 );

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
			<?php esc_html_e( 'Plot', 'vila-akoya' ); ?>
		</legend>
		<p class="text-sm text-on-surface-variant mb-3">
			<?php
			if ( class_exists( '\Vila_CB\WooCommerce_Integration' ) ) {
				esc_html_e( 'Plot is assigned automatically from availability. The calendar only blocks nights when every plot is fully booked.', 'vila-akoya' );
			} else {
				esc_html_e( 'Each pitch has its own calendar. Choose one — dates already taken on that pitch are blocked.', 'vila-akoya' );
			}
			?>
		</p>
		<div class="vila-plot-grid grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-2">
			<?php
			foreach ( $plots as $plot_pid ) :
				$plot_pid = (int) $plot_pid;
				$fid      = 'vila-plot-' . $plot_pid;
				$plot_num = function_exists( 'vila_akoya_campsite_plot_number_from_product_id' )
					? vila_akoya_campsite_plot_number_from_product_id( $plot_pid )
					: 0;
				if ( $plot_num <= 0 ) {
					$plot_num = $plot_pid;
				}
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
							$plot_num
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
 * Render plot selector dropdown (shared-pool mode). Lets the user pick a specific pitch or "auto-assign".
 *
 * @param int $product_id Product ID.
 */
function vila_akoya_render_plot_dropdown( $product_id ) {
	if ( ! class_exists( '\Vila_CB\WooCommerce_Integration' ) ) {
		return;
	}
	if ( ! vila_akoya_is_campsite_product_id( (int) $product_id ) ) {
		return;
	}
	$repo  = new \Vila_CB\Plot_Repository();
	$plots = $repo->get_bookable();
	if ( empty( $plots ) ) {
		return;
	}
	?>
	<div class="vila-plot-dropdown-wrap vila-airbnb-booking mb-8">
		<label for="vila-plot-select" class="vila-airbnb-booking__legend mb-2 block">
			<?php esc_html_e( 'Plot', 'vila-akoya' ); ?>
		</label>
		<select id="vila-plot-select" name="vila_cb_preferred_plot" class="vila-plot-select w-full">
			<option value=""><?php esc_html_e( 'Auto-assign me a pitch', 'vila-akoya' ); ?></option>
			<?php foreach ( $plots as $plot ) : ?>
				<option value="<?php echo esc_attr( (string) (int) $plot['id'] ); ?>">
					<?php echo esc_html( (string) $plot['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<input type="hidden" name="vila_cb_preferred_plot_id" id="vila-cb-preferred-plot-id" value="" />
	</div>
	<?php
}

/**
 * Optional notes field — guests can mention preferences (e.g. a plot number); pitch is auto-assigned in the backend.
 *
 * @param int $product_id Product ID.
 */
function vila_akoya_render_booking_notes_field( $product_id ) {
	if ( ! vila_akoya_is_campsite_product_id( (int) $product_id ) ) {
		return;
	}
	?>
	<div class="vila-booking-notes-wrap vila-airbnb-booking mb-8">
		<label for="vila-cb-booking-notes" class="vila-airbnb-booking__legend mb-2 block">
			<?php esc_html_e( 'Notes', 'vila-akoya' ); ?>
			<span class="font-body normal-case tracking-normal text-on-surface-variant font-normal"><?php esc_html_e( '(optional)', 'vila-akoya' ); ?></span>
		</label>
		<textarea
			id="vila-cb-booking-notes"
			name="vila_cb_booking_notes"
			class="vila-booking-notes"
			rows="3"
			maxlength="2000"
			placeholder="<?php echo esc_attr__( 'Anything we should know? You can mention a preferred plot number or special requests.', 'vila-akoya' ); ?>"
		></textarea>
	</div>
	<?php
}
add_action( 'mwb_booking_after_add_to_cart_form', 'vila_akoya_render_booking_notes_field', 20 );

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
	$selected_types = array();
	$currency = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '€';
	?>
	<div class="vila-airbnb-booking vila-airbnb-booking--units mb-8">
		<fieldset class="vila-vehicle-fieldset vila-airbnb-booking__card border-0 m-0 min-w-0">
			<legend class="vila-airbnb-booking__legend">
				<?php esc_html_e( 'Your setup', 'vila-akoya' ); ?>
			</legend>
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
							<?php echo in_array( $slug, $selected_types, true ) ? 'checked' : ''; ?>
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
add_action( 'mwb_booking_after_add_to_cart_form', 'vila_akoya_render_vehicle_selector', 12 );


/**
 * Render meal add-on checkboxes on the campsite booking product.
 *
 * @param int $product_id Product ID.
 */
function vila_akoya_render_meal_selector( $product_id ) {
	if ( ! vila_akoya_is_campsite_product_id( (int) $product_id ) ) {
		return;
	}
	$options = vila_akoya_get_meal_options();
	if ( empty( $options ) ) {
		return;
	}
	?>
	<div class="vila-airbnb-booking vila-airbnb-booking--meals mb-8">
		<fieldset class="vila-meal-fieldset vila-airbnb-booking__card border-0 m-0 min-w-0">
			<legend class="vila-airbnb-booking__legend">
				<?php esc_html_e( 'Meals', 'vila-akoya' ); ?>
			</legend>
			<div class="vila-meal-grid vila-airbnb-booking__chip-grid">
				<?php foreach ( $options as $slug => $info ) :
					$fid = 'vila-meal-' . $slug;
					?>
					<label class="vila-meal-option vila-airbnb-booking__chip">
						<input
							class="vila-meal-input sr-only"
							type="checkbox"
							name="vila_meal_options[]"
							id="<?php echo esc_attr( $fid ); ?>"
							value="<?php echo esc_attr( $slug ); ?>"
							data-vila-day-mode="<?php echo esc_attr( isset( $info['day_mode'] ) ? (string) $info['day_mode'] : 'all_nights' ); ?>"
						/>
						<span class="vila-airbnb-booking__chip-body">
							<span class="vila-airbnb-booking__chip-title"><?php echo esc_html( $info['label'] ); ?></span>
							<span class="vila-airbnb-booking__chip-meta"><?php echo esc_html( isset( $info['meta'] ) ? (string) $info['meta'] : '' ); ?></span>
						</span>
					</label>
				<?php endforeach; ?>
			</div>
		</fieldset>
	</div>
	<?php
}
add_action( 'mwb_booking_after_add_to_cart_form', 'vila_akoya_render_meal_selector', 14 );


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
	// MWB's general_cost can arrive as 0 on an early/racey AJAX pass (dates and people not yet posted together),
	// which understates the pitch line and Total. Fall back to our own computed pitch when it should be positive.
	if ( $pitch_value <= 0 && $expected_pitch > 0 ) {
		$pitch_value = $expected_pitch;
	}

	$night_word = ( 1 === $nights )
		? __( 'night', 'vila-akoya' )
		: __( 'nights', 'vila-akoya' );

	if ( $per_person ) {
		$pitch_title = sprintf(
			/* translators: 1: adults count, 2: formatted price per adult per night, 3: nights count, 4: "night" or "nights" */
			__( 'Plot rate — %1$d adults × %2$s/adult/night × %3$d %4$s', 'vila-akoya' ),
			$adults,
			vila_akoya_format_price_plain( $unit_rate ),
			$nights,
			$night_word
		);
	} else {
		$pitch_title = sprintf(
			/* translators: 1: formatted price per night, 2: nights count, 3: "night" or "nights" */
			__( 'Plot rate — %1$s/night × %2$d %3$s', 'vila-akoya' ),
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
				__( 'Babies — %1$d × %2$s/night × %3$d %4$s', 'vila-akoya' ),
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


	$raw_meals = isset( $_POST['vila_meal_options'] ) && is_array( $_POST['vila_meal_options'] ) ? $_POST['vila_meal_options'] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$meal_slugs = vila_akoya_normalize_meal_options_array( $raw_meals );
	$weekday_nights = vila_akoya_weekday_nights_from_request();
	$meal_catalog = vila_akoya_get_meal_options();
	$day_singular = __( 'day', 'vila-akoya' );
	$day_plural   = __( 'days', 'vila-akoya' );

	foreach ( $meal_slugs as $slug ) {
		if ( ! isset( $meal_catalog[ $slug ] ) ) {
			continue;
		}
		$info = $meal_catalog[ $slug ];
		$days = vila_akoya_meal_days_for_option( $info, $nights, $weekday_nights );
		$line_total = vila_akoya_meal_line_total( $info, $adults, $ch, $inf, $days );
		if ( $line_total <= 0 || $days < 1 ) {
			continue;
		}
		$day_word = ( 1 === $days ) ? $day_singular : $day_plural;
		$parts = array();
		if ( $adults > 0 && (float) $info['adult_fee'] > 0 ) {
			$parts[] = sprintf(
				/* translators: 1: adult count, 2: price each */
				__( '%1$d adults × %2$s', 'vila-akoya' ),
				$adults,
				vila_akoya_format_price_plain( (float) $info['adult_fee'] )
			);
		}
		if ( $ch > 0 && (float) $info['child_fee'] > 0 ) {
			$parts[] = sprintf(
				/* translators: 1: children count, 2: price each */
				__( '%1$d kids × %2$s', 'vila-akoya' ),
				$ch,
				vila_akoya_format_price_plain( (float) $info['child_fee'] )
			);
		}
		$people_bit = $parts ? implode( ' + ', $parts ) : vila_akoya_format_price_plain( 0 );
		$title = sprintf(
			/* translators: 1: meal label, 2: people pricing summary, 3: days, 4: day/days */
			__( '%1$s — (%2$s) × %3$d %4$s', 'vila-akoya' ),
			$info['label'],
			$people_bit,
			$days,
			$day_word
		);
		$new[ 'vila_meal_' . $slug ] = array(
			'title' => $title,
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

	// Pricing data for the instant client-side bill. These mirror the exact
	// rates/filters used by vila_akoya_mbfw_expand_booking_charge_breakdown()
	// and the checkout fee hooks, so the live total matches checkout to the cent.
	$live_product  = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
	$regular_price = 0.0;
	if ( $live_product ) {
		$regular_price = (float) $live_product->get_regular_price();
		if ( $regular_price <= 0 ) {
			$regular_price = (float) $live_product->get_price();
		}
	}
	$adult_rate  = (float) apply_filters( 'vila_cb_adult_rate_per_night', $regular_price, $pid );
	$per_person  = function_exists( 'wps_booking_get_meta_data' )
		? ( 'yes' === wps_booking_get_meta_data( $pid, 'mwb_mbfw_is_booking_unit_cost_per_people', true ) )
		: true;
	$guest_rates = vila_akoya_get_guest_category_nightly_rates( $pid );
	$by_head     = (bool) apply_filters( 'vila_akoya_pitch_supplement_multiplies_by_guests', false, array() );

	$vehicle_fees = array();
	foreach ( vila_akoya_get_vehicle_types() as $slug => $info ) {
		$vehicle_fees[ (string) $slug ] = array(
			'label' => (string) $info['label'],
			'fee'   => (float) $info['fee'],
		);
	}

	$meal_fees = array();
	foreach ( vila_akoya_get_meal_options() as $slug => $info ) {
		$meal_fees[ (string) $slug ] = array(
			'label'     => (string) $info['label'],
			'adultFee'  => (float) $info['adult_fee'],
			'childFee'  => (float) $info['child_fee'],
			'infantFee' => (float) $info['infant_fee'],
			'dayMode'   => (string) $info['day_mode'],
		);
	}

	$currency = array(
		'symbol'      => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '€',
		'decimals'    => function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2,
		'decimalSep'  => function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : ',',
		'thousandSep' => function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : '.',
		'format'      => function_exists( 'get_woocommerce_price_format' ) ? get_woocommerce_price_format() : '%1$s%2$s',
	);

	wp_localize_script(
		'vila-akoya-booking-ui',
		'vilaAkoyaBooking',
		array(
			'defaultPlotProductId'      => (int) $pid,
			'plotProductIds'             => $plot_ids_js,
			'plotUnavailableYmd'         => $plot_unavail,
			'useSharedCampsiteInventory' => $use_vila_pool_calendar,
			'vilaCbAvailabilityRestUrl'  => $use_vila_pool_calendar ? esc_url_raw( rest_url( 'vila-campsite-booking/v1/availability' ) ) : '',
			'pricing'                   => array(
				'adultPerNight'   => (float) $adult_rate,
				'perPerson'       => (bool) $per_person,
				'vehicleByGuests' => (bool) $by_head,
				'guestRates'      => array(
					'children' => (float) $guest_rates['children'],
					'infants'  => (float) $guest_rates['infants'],
					'pets'     => (float) $guest_rates['pets'],
				),
				'vehicleFees'     => $vehicle_fees,
				'mealFees'        => $meal_fees,
			),
			'currency'                  => $currency,
			'billStrings'               => array(
				'plotRatePerPerson' => __( 'Plot rate — %1$d adults × %2$s/adult/night × %3$d %4$s', 'vila-akoya' ),
				'plotRateFlat'      => __( 'Plot rate — %1$s/night × %2$d %3$s', 'vila-akoya' ),
				'children'          => __( 'Children — %1$d × %2$s/night × %3$d %4$s', 'vila-akoya' ),
				'infants'           => __( 'Babies — %1$d × %2$s/night × %3$d %4$s', 'vila-akoya' ),
				'pets'              => __( 'Pets — %1$d × %2$s/night × %3$d %4$s', 'vila-akoya' ),
				'vehicleFlat'       => __( '%1$s — %2$s/night × %3$d %4$s', 'vila-akoya' ),
				'vehicleByGuests'   => __( '%1$s — %2$s/night × %3$d guests × %4$d %5$s', 'vila-akoya' ),
				'mealLine'          => __( '%1$s — (%2$s) × %3$d %4$s', 'vila-akoya' ),
				'mealAdults'        => __( '%1$d adults × %2$s', 'vila-akoya' ),
				'mealKids'          => __( '%1$d kids × %2$s', 'vila-akoya' ),
				'total'             => __( 'Total', 'vila-akoya' ),
				'nightSingular'     => __( 'night', 'vila-akoya' ),
				'nightPlural'       => __( 'nights', 'vila-akoya' ),
				'daySingular'       => __( 'day', 'vila-akoya' ),
				'dayPlural'         => __( 'days', 'vila-akoya' ),
			),
			'strings'                   => array(
				'stayDates'               => __( 'Your dates', 'vila-akoya' ),
				'rangeUnavailable'        => __( 'No pitch is free for this full stay on those dates. Choose different check-in and check-out — some nights can look available when each pitch is booked on different nights.', 'vila-akoya' ),
				'placeholder'             => __( 'Check-in — Check-out', 'vila-akoya' ),
				'guestCountAffectsRate'   => __( 'The amount above reflects adults at the per-guest nightly rate. Extra categories add supplements at checkout.', 'vila-akoya' ),
				'finalising'              => __( 'Finalising your booking…', 'vila-akoya' ),
				'finalisingSub'           => __( 'Securing your pitch and preparing checkout. This only takes a few seconds.', 'vila-akoya' ),
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
