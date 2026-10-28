<?php
/**
 * Back-compat shim — campsite pricing moved to {@see Campsite_Tier_Pricing}.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * @deprecated Use Campsite_Tier_Pricing.
 */
class Campsite_Monthly_Rate {

	const BLOCK_NIGHTS = 28;

	const FLAT_EUR = 555.0;

	/**
	 * @deprecated
	 */
	public static function flat_fee() {
		return (float) Campsite_Tier_Pricing::rates( false )['four_weeks'];
	}

	/**
	 * @deprecated
	 */
	public static function month_blocks( $stay_nights ) {
		return Campsite_Tier_Pricing::decompose_nights( $stay_nights )['four_week_blocks'];
	}

	/**
	 * @deprecated
	 */
	public static function remainder_nights( $stay_nights ) {
		$d = Campsite_Tier_Pricing::decompose_nights( $stay_nights );
		return ( $d['week_blocks'] * Campsite_Tier_Pricing::WEEK_NIGHTS ) + $d['single_nights'];
	}

	/**
	 * @deprecated Campsite extras use calendar nights; accommodation uses tiers.
	 */
	public static function nightly_billable_nights( $stay_nights ) {
		unset( $stay_nights );
		return 0;
	}

	/**
	 * @deprecated
	 */
	public static function flat_portion_total( $stay_nights ) {
		unset( $stay_nights );
		return 0.0;
	}

	/**
	 * @deprecated
	 */
	public static function nightly_calendar_nights( $stay_nights ) {
		return max( 0, (int) $stay_nights );
	}

	/**
	 * @deprecated
	 */
	public static function weekly_free_nights( $stay_nights ) {
		unset( $stay_nights );
		return 0;
	}

	/**
	 * @deprecated
	 */
	public static function info( $stay_nights ) {
		return Campsite_Tier_Pricing::info( $stay_nights, false );
	}
}
