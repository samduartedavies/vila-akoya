<?php
/**
 * Campsite booking extras: pitch / unit type surcharges + script loader for range UI.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nightly supplement (EUR) by unit slug. Filter to change amounts.
 *
 * @return array<string, array{ label: string, fee: float }>
 */
function vila_akoya_get_vehicle_types() {
	return apply_filters(
		'vila_akoya_vehicle_types',
		array(
			'tent'     => array(
				'label' => __( 'Tent', 'vila-akoya' ),
				'fee'   => 0,
			),
			'caravan'  => array(
				'label' => __( 'Caravan', 'vila-akoya' ),
				'fee'   => 8,
			),
			'camper'   => array(
				'label' => __( 'Camper van', 'vila-akoya' ),
				'fee'   => 10,
			),
			'four_by_four' => array(
				'label' => __( '4×4 / off-road rig', 'vila-akoya' ),
				'fee'   => 12,
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
 * Compute nights from MWB date fields (d-m-Y).
 *
 * @return int
 */
function vila_akoya_nights_from_request() {
	$from = isset( $_POST['mwb_mbfw_booking_from_time'] ) ? sanitize_text_field( wp_unslash( $_POST['mwb_mbfw_booking_from_time'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$to   = isset( $_POST['mwb_mbfw_booking_to_time'] ) ? sanitize_text_field( wp_unslash( $_POST['mwb_mbfw_booking_to_time'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
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
 * Persist vehicle choice and night count on the cart line.
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
	if ( ! isset( $_POST['_mwb_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_mwb_nonce'] ) ), 'mwb_booking_frontend' ) ) {
		return $cart_item_data;
	}
	$slug = isset( $_POST['vila_vehicle_type'] ) ? sanitize_key( wp_unslash( $_POST['vila_vehicle_type'] ) ) : 'tent';
	$keys = array_keys( vila_akoya_get_vehicle_types() );
	if ( ! in_array( $slug, $keys, true ) ) {
		$slug = 'tent';
	}
	$cart_item_data['vila_vehicle_type']   = $slug;
	$cart_item_data['vila_booking_nights'] = vila_akoya_nights_from_request();
	return $cart_item_data;
}
add_filter( 'woocommerce_add_cart_item_data', 'vila_akoya_add_vehicle_cart_item_data', 30, 3 );

/**
 * Restore custom cart line data from session.
 *
 * @param array<string, mixed> $cart_item Cart item.
 * @param array<string, mixed> $values    Session values.
 * @return array<string, mixed>
 */
function vila_akoya_get_cart_item_from_session_vehicle( $cart_item, $values, $cart_item_key ) {
	unset( $cart_item_key );
	foreach ( array( 'vila_vehicle_type', 'vila_booking_nights' ) as $key ) {
		if ( isset( $values[ $key ] ) ) {
			$cart_item[ $key ] = $values[ $key ];
		}
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
		if ( empty( $cart_item['vila_vehicle_type'] ) || ! vila_akoya_is_campsite_product_id( (int) $cart_item['product_id'] ) ) {
			continue;
		}
		$rate   = vila_akoya_vehicle_fee_per_night( $cart_item['vila_vehicle_type'] );
		$nights = isset( $cart_item['vila_booking_nights'] ) ? (int) $cart_item['vila_booking_nights'] : 1;
		$total  = $rate * $nights;
		if ( $total <= 0 ) {
			continue;
		}
		WC()->cart->add_fee(
			sprintf(
				/* translators: 1: unit label, 2: number of nights */
				__( 'Pitch supplement — %1$s (%2$d nights)', 'vila-akoya' ),
				vila_akoya_vehicle_label( $cart_item['vila_vehicle_type'] ),
				$nights
			),
			$total
		);
	}
}
add_action( 'woocommerce_cart_calculate_fees', 'vila_akoya_cart_vehicle_fees', 20 );

/**
 * Cart / checkout line item meta display.
 *
 * @param array<string, mixed> $item_data Item data.
 * @param array<string, mixed> $cart_item Cart item.
 * @return array<string, mixed>
 */
function vila_akoya_get_item_data_vehicle( $item_data, $cart_item ) {
	if ( empty( $cart_item['vila_vehicle_type'] ) ) {
		return $item_data;
	}
	$item_data[] = array(
		'name'    => __( 'Unit type', 'vila-akoya' ),
		'display' => esc_html( vila_akoya_vehicle_label( $cart_item['vila_vehicle_type'] ) ),
	);
	if ( ! empty( $cart_item['vila_booking_nights'] ) ) {
		$item_data[] = array(
			'name'    => __( 'Nights (for supplement)', 'vila-akoya' ),
			'display' => (string) (int) $cart_item['vila_booking_nights'],
		);
	}
	return $item_data;
}
add_filter( 'woocommerce_get_item_data', 'vila_akoya_get_item_data_vehicle', 15, 2 );

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
	if ( empty( $values['vila_vehicle_type'] ) ) {
		return;
	}
	$item->add_meta_data( __( 'Unit type', 'vila-akoya' ), vila_akoya_vehicle_label( $values['vila_vehicle_type'] ), true );
	if ( ! empty( $values['vila_booking_nights'] ) ) {
		$item->add_meta_data( __( 'Supplement nights', 'vila-akoya' ), (string) (int) $values['vila_booking_nights'], true );
	}
}
add_action( 'woocommerce_checkout_create_order_line_item', 'vila_akoya_checkout_save_vehicle_meta', 15, 4 );

/**
 * Render pitch / vehicle radios on the campsite booking product.
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
	<fieldset class="vila-vehicle-fieldset space-y-3 mb-6 border-0 p-0 m-0">
		<legend class="font-label text-xs uppercase tracking-widest text-on-surface-variant mb-3 block">
			<?php esc_html_e( 'What are you staying in?', 'vila-akoya' ); ?>
		</legend>
		<div class="vila-vehicle-grid grid grid-cols-1 sm:grid-cols-2 gap-2">
			<?php
			$first = true;
			foreach ( $types as $slug => $info ) :
				$fid = 'vila-vehicle-' . $slug;
				$fee = (float) $info['fee'];
				?>
				<label class="vila-vehicle-option flex cursor-pointer items-center gap-3 rounded-lg border-2 border-transparent bg-surface-container-low px-4 py-3 transition-colors has-[:checked]:border-primary has-[:checked]:bg-primary/5">
					<input
						class="vila-vehicle-input sr-only"
						type="radio"
						name="vila_vehicle_type"
						id="<?php echo esc_attr( $fid ); ?>"
						value="<?php echo esc_attr( $slug ); ?>"
						<?php echo $first ? 'checked' : ''; ?>
						data-vila-fee="<?php echo esc_attr( (string) $fee ); ?>"
					/>
					<span class="font-body text-sm text-on-surface">
						<?php echo esc_html( $info['label'] ); ?>
						<?php
						if ( $fee > 0 ) {
							echo ' <span class="text-on-surface-variant">+';
							echo esc_html( $currency . sprintf( '%.0f', $fee ) );
							echo esc_html( _x( '/night', 'price suffix for pitch fee', 'vila-akoya' ) );
							echo '</span>';
						} else {
							echo ' <span class="text-on-surface-variant">' . esc_html__( '(included)', 'vila-akoya' ) . '</span>';
						}
						?>
					</span>
				</label>
				<?php
				$first = false;
			endforeach;
			?>
		</div>
		<p class="vila-vehicle-hint mt-2 text-xs text-on-surface-variant mb-0" aria-live="polite"></p>
	</fieldset>
	<?php
}
add_action( 'mwb_booking_after_add_to_cart_form', 'vila_akoya_render_vehicle_selector', 15 );

/**
 * MWB: drop a zero "Base cost" row so only the nightly stay line appears (less confusing than Base 0 + General).
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
 * Enqueue booking UI script on booking page and campsite product views.
 */
function vila_akoya_enqueue_booking_ui_script() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	$pid = (int) get_option( 'vila_akoya_campsite_product_id' );
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
	wp_localize_script(
		'vila-akoya-booking-ui',
		'vilaAkoyaBooking',
		array(
			'currencySymbol' => get_woocommerce_currency_symbol(),
			'vehicleRates'   => $rates,
			'strings'        => array(
				'stayDates'       => __( 'Stay dates', 'vila-akoya' ),
				'placeholder'     => __( 'Check-in — Check-out', 'vila-akoya' ),
				'supplement'      => __( 'Pitch supplement estimate', 'vila-akoya' ),
				'finalAtCheckout' => __( 'Final amount includes taxes & fees at checkout.', 'vila-akoya' ),
				'nightSingular'   => __( 'night', 'vila-akoya' ),
				'nightPlural'     => __( 'nights', 'vila-akoya' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'vila_akoya_enqueue_booking_ui_script', 30 );
