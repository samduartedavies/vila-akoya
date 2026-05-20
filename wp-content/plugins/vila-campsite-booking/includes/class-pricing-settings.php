<?php
/**
 * Admin-configurable campsite pricing + EUR store currency.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Persists rates in options and applies filters used by the theme and WooCommerce integration.
 */
class Pricing_Settings {

	const OPTION_KEY = 'vila_cb_pricing';

	const PRICING_SCHEMA_VERSION_OPTION = 'vila_cb_pricing_schema_version';

	const PRICING_SCHEMA_VERSION = '2026-05';

	/**
	 * Bootstrap hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_currency', array( __CLASS__, 'force_eur' ), 999 );
		add_filter( 'woocommerce_product_get_regular_price', array( __CLASS__, 'filter_product_regular_price' ), 20, 2 );
		add_filter( 'vila_cb_adult_rate_per_night', array( __CLASS__, 'filter_adult_rate_per_night' ), 20, 2 );
		add_filter( 'vila_cb_mwb_pitch_unit_rate_per_night', array( __CLASS__, 'filter_mwb_pitch_unit_rate' ), 10, 2 );
		add_filter( 'vila_cb_guest_category_nightly_rates', array( __CLASS__, 'filter_guest_category_rates' ), 20, 2 );
		add_filter( 'vila_akoya_guest_category_nightly_rates', array( __CLASS__, 'filter_guest_category_rates' ), 20, 2 );
		add_filter( 'vila_akoya_vehicle_types', array( __CLASS__, 'filter_vehicle_types' ), 20, 1 );
		// After fee overrides and any other filters: drop legacy slugs (priority last).
		add_filter( 'vila_akoya_vehicle_types', array( __CLASS__, 'strip_deprecated_vehicle_types' ), 9999, 1 );
	}

	/**
	 * Default structure (amounts match previous theme defaults).
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'adult_per_night' => 9.0,
			'guest_rates'     => array(
				'children' => 6.0,
				'infants'  => 3.0,
				'pets'     => 2.0,
			),
			'vehicle_fees'    => array(
				'tent'         => 0.0,
				'caravan'      => 6.0,
				'camper'       => 6.0,
				'electricity'  => 2.0,
				'car'          => 2.0,
			),
		);
	}

	/**
	 * Vehicle fee keys no longer used; dropped from admin and from merged stored option.
	 *
	 * @return string[]
	 */
	private static function deprecated_vehicle_fee_slugs() {
		return array( 'four_by_four' );
	}

	/**
	 * One-time merge of May 2026 public rate card into saved Campsite → Settings.
	 */
	public static function migrate_pricing_rates_2026() {
		$ver = get_option( self::PRICING_SCHEMA_VERSION_OPTION, '' );
		if ( is_string( $ver ) && version_compare( $ver, self::PRICING_SCHEMA_VERSION, '>=' ) ) {
			return;
		}

		$defaults = self::defaults();
		$cfg      = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $cfg ) ) {
			$cfg = array();
		}

		$cfg['adult_per_night'] = $defaults['adult_per_night'];
		$cfg['guest_rates']     = $defaults['guest_rates'];
		$cfg['vehicle_fees']    = array_merge(
			isset( $cfg['vehicle_fees'] ) && is_array( $cfg['vehicle_fees'] ) ? $cfg['vehicle_fees'] : array(),
			$defaults['vehicle_fees']
		);

		update_option( self::OPTION_KEY, $cfg );
		update_option( self::PRICING_SCHEMA_VERSION_OPTION, self::PRICING_SCHEMA_VERSION );
	}

	/**
	 * Remove deprecated keys from the saved `vila_cb_pricing` option in wp_options (e.g. legacy four_by_four).
	 *
	 * Plot seeding does not touch pricing; this runs on bootstrap so the DB matches current slugs.
	 */
	public static function migrate_legacy_pricing_option() {
		$opt = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $opt ) || empty( $opt['vehicle_fees'] ) || ! is_array( $opt['vehicle_fees'] ) ) {
			return;
		}
		$changed = false;
		foreach ( self::deprecated_vehicle_fee_slugs() as $legacy ) {
			if ( isset( $opt['vehicle_fees'][ $legacy ] ) ) {
				unset( $opt['vehicle_fees'][ $legacy ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( self::OPTION_KEY, $opt );
		}
	}

	/**
	 * Slugs for per-night extra fees (theme defines labels; defaults define fallback amounts).
	 *
	 * Builds from plugin defaults (always includes electricity + car), then appends any keys from
	 * `vila_akoya_vehicle_types` (theme / filters). Finally removes deprecated slugs — this last
	 * step is mandatory so a filter that runs after `strip_deprecated_vehicle_types` cannot
	 * reintroduce e.g. four_by_four into Campsite → Settings.
	 *
	 * @return string[]
	 */
	public static function vehicle_fee_slugs() {
		$slugs = array_keys( self::defaults()['vehicle_fees'] );
		if ( function_exists( 'vila_akoya_get_vehicle_types' ) ) {
			$types = vila_akoya_get_vehicle_types();
			if ( is_array( $types ) && array() !== $types ) {
				foreach ( array_keys( $types ) as $slug ) {
					if ( ! in_array( $slug, $slugs, true ) ) {
						$slugs[] = $slug;
					}
				}
			}
		}
		/**
		 * Slugs for per-night extra fees in Campsite → Settings (Extras) and saved `vehicle_fees`.
		 *
		 * @param string[] $slugs Default keys plus any from theme vehicle types.
		 */
		$slugs = apply_filters( 'vila_cb_vehicle_fee_slugs', $slugs );
		$slugs = array_values( array_diff( array_unique( (array) $slugs ), self::deprecated_vehicle_fee_slugs() ) );
		return $slugs;
	}

	/**
	 * Merged vehicle_fees from defaults + saved option (no theme filter — avoids recursion with filter_vehicle_types).
	 *
	 * @return array<string, float>
	 */
	private static function merged_vehicle_fees_from_option() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$base = self::defaults();
		if ( ! empty( $stored['vehicle_fees'] ) && is_array( $stored['vehicle_fees'] ) ) {
			$merged = array_merge( $base['vehicle_fees'], $stored['vehicle_fees'] );
		} else {
			$merged = $base['vehicle_fees'];
		}
		foreach ( self::deprecated_vehicle_fee_slugs() as $legacy ) {
			unset( $merged[ $legacy ] );
		}
		return $merged;
	}

	/**
	 * Saved + merged defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$base = self::defaults();
		$out  = array_merge( $base, $stored );
		if ( ! empty( $stored['guest_rates'] ) && is_array( $stored['guest_rates'] ) ) {
			$out['guest_rates'] = array_merge( $base['guest_rates'], $stored['guest_rates'] );
		}
		$out['vehicle_fees'] = self::merged_vehicle_fees_from_option();
		foreach ( self::vehicle_fee_slugs() as $slug ) {
			if ( isset( $out['vehicle_fees'][ $slug ] ) ) {
				continue;
			}
			$fallback = isset( $base['vehicle_fees'][ $slug ] ) ? (float) $base['vehicle_fees'][ $slug ] : 0.0;
			if ( $fallback <= 0 && function_exists( 'vila_akoya_get_vehicle_types' ) ) {
				$types = vila_akoya_get_vehicle_types();
				if ( isset( $types[ $slug ]['fee'] ) ) {
					$fallback = (float) $types[ $slug ]['fee'];
				}
			}
			$out['vehicle_fees'][ $slug ] = $fallback;
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $raw Posted or imported data.
	 * @return array<string, mixed>
	 */
	public static function sanitize_from_request( $raw ) {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$base = self::defaults();
		$adult = isset( $raw['adult_per_night'] ) ? self::sanitize_amount( $raw['adult_per_night'] ) : 0.0;
		$adult = max( 0.0, $adult );

		$guest = $base['guest_rates'];
		foreach ( array( 'children', 'infants', 'pets' ) as $k ) {
			if ( isset( $raw['guest_rates'][ $k ] ) ) {
				$guest[ $k ] = max( 0.0, self::sanitize_amount( $raw['guest_rates'][ $k ] ) );
			}
		}

		$veh     = $base['vehicle_fees'];
		$vf_base = $base['vehicle_fees'];
		foreach ( self::vehicle_fee_slugs() as $slug ) {
			if ( ! isset( $veh[ $slug ] ) ) {
				$theme_fee = null;
				if ( function_exists( 'vila_akoya_get_vehicle_types' ) ) {
					$types = vila_akoya_get_vehicle_types();
					if ( isset( $types[ $slug ]['fee'] ) ) {
						$theme_fee = (float) $types[ $slug ]['fee'];
					}
				}
				$veh[ $slug ] = null !== $theme_fee ? max( 0.0, $theme_fee ) : ( isset( $vf_base[ $slug ] ) ? (float) $vf_base[ $slug ] : 0.0 );
			}
			if ( isset( $raw['vehicle_fees'][ $slug ] ) ) {
				$veh[ $slug ] = max( 0.0, self::sanitize_amount( $raw['vehicle_fees'][ $slug ] ) );
			}
		}

		return array(
			'adult_per_night' => $adult,
			'guest_rates'     => $guest,
			'vehicle_fees'    => $veh,
		);
	}

	/**
	 * @param mixed $v Raw value.
	 * @return float
	 */
	private static function sanitize_amount( $v ) {
		if ( is_string( $v ) ) {
			$v = str_replace( ',', '.', $v );
		}
		return round( (float) $v, 4 );
	}

	/**
	 * Persist WC currency option so wp-admin matches runtime.
	 */
	public static function sync_woocommerce_currency_option() {
		update_option( 'woocommerce_currency', 'EUR' );
	}

	/**
	 * @param string $currency Currency code.
	 * @return string
	 */
	public static function force_eur( $currency ) {
		unset( $currency );
		return 'EUR';
	}

	/**
	 * When an override is set, WooCommerce and MWB read the same per-night adult rate from the product object.
	 *
	 * @param string|false    $price Regular price.
	 * @param \WC_Product|null $product Product.
	 * @return string|false
	 */
	public static function filter_product_regular_price( $price, $product ) {
		if ( null === $price ) {
			$price = '';
		}
		if ( ! $product instanceof \WC_Product ) {
			return $price;
		}
		// Keep the raw price in wp-admin product editor; apply override on storefront + AJAX/REST cart.
		if ( is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $price;
		}
		$pid = (int) $product->get_id();
		if ( ! WooCommerce_Integration::is_campsite_product( $pid ) ) {
			return $price;
		}
		$cfg = self::get();
		$ov  = isset( $cfg['adult_per_night'] ) ? (float) $cfg['adult_per_night'] : 0.0;
		if ( $ov > 0 ) {
			return wc_format_decimal( $ov );
		}
		return $price;
	}

	/**
	 * @param float $rate Per-adult nightly rate from product.
	 * @param int   $product_id Product ID.
	 * @return float
	 */
	public static function filter_adult_rate_per_night( $rate, $product_id ) {
		unset( $product_id );
		$cfg = self::get();
		$ov  = isset( $cfg['adult_per_night'] ) ? (float) $cfg['adult_per_night'] : 0.0;
		if ( $ov > 0 ) {
			return $ov;
		}
		return (float) $rate;
	}

	/**
	 * Align MWB “Bookings for WooCommerce” AJAX breakdown with Campsite → Settings adult rate.
	 *
	 * Theme reads `mwb_mbfw_booking_unit_cost` from the product; without this, the booking form still uses WC product meta (e.g. 17.5 × 2 guests = 35) while checkout logic uses the override.
	 *
	 * @param float $unit_rate Rate from MWB meta (per adult per night when per-person).
	 * @param int   $product_id Product ID.
	 * @return float
	 */
	public static function filter_mwb_pitch_unit_rate( $unit_rate, $product_id ) {
		$product_id = (int) $product_id;
		if ( ! WooCommerce_Integration::is_campsite_product( $product_id ) ) {
			return $unit_rate;
		}
		$cfg = self::get();
		$ov  = isset( $cfg['adult_per_night'] ) ? (float) $cfg['adult_per_night'] : 0.0;
		if ( $ov > 0 ) {
			return $ov;
		}
		return (float) $unit_rate;
	}

	/**
	 * @param array<string, float> $rates Guest category nightly rates.
	 * @param int                    $product_id Product ID.
	 * @return array<string, float>
	 */
	public static function filter_guest_category_rates( $rates, $product_id ) {
		unset( $product_id );
		if ( ! is_array( $rates ) ) {
			$rates = array();
		}
		$cfg = self::get();
		$gr  = isset( $cfg['guest_rates'] ) && is_array( $cfg['guest_rates'] ) ? $cfg['guest_rates'] : array();
		foreach ( array( 'children', 'infants', 'pets' ) as $k ) {
			if ( array_key_exists( $k, $gr ) ) {
				$rates[ $k ] = (float) $gr[ $k ];
			}
		}
		return $rates;
	}

	/**
	 * @param array<string, array{ label: string, fee: float }> $types Vehicle types from theme.
	 * @return array<string, array{ label: string, fee: float }>
	 */
	public static function filter_vehicle_types( $types ) {
		if ( ! is_array( $types ) ) {
			return array();
		}
		// Do not call self::get() here: get() → vehicle_fee_slugs() → vila_akoya_get_vehicle_types() → this filter (infinite recursion).
		$vf = self::merged_vehicle_fees_from_option();
		foreach ( $types as $slug => &$row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( isset( $vf[ $slug ] ) ) {
				$row['fee'] = (float) $vf[ $slug ];
			}
		}
		unset( $row );
		return $types;
	}

	/**
	 * Remove legacy slugs (e.g. four_by_four) from the merged vehicle-type list.
	 *
	 * @param array<string, array{ label: string, fee: float }> $types Vehicle types.
	 * @return array<string, array{ label: string, fee: float }>
	 */
	public static function strip_deprecated_vehicle_types( $types ) {
		if ( ! is_array( $types ) ) {
			return array();
		}
		foreach ( self::deprecated_vehicle_fee_slugs() as $legacy ) {
			unset( $types[ $legacy ] );
		}
		return $types;
	}
}
