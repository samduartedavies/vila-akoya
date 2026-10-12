<?php
/**
 * Campsite long-stay pricing: weekly night discount + flat monthly blocks.
 *
 * Under 30 nights: 1 free night per full week (same as before, no 30→20 night deal).
 * Each full 30-night block: flat fee (guests, electric, and pitch supplements included).
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Campsite monthly flat rate and nightly billable helpers.
 */
class Campsite_Monthly_Rate {

	const BLOCK_NIGHTS = 30;

	const FLAT_EUR = 590.0;

	/**
	 * Flat fee per full 30-night block (filterable).
	 *
	 * @return float
	 */
	public static function flat_fee() {
		return (float) apply_filters( 'vila_cb_campsite_monthly_flat_eur', self::FLAT_EUR );
	}

	/**
	 * @param int $stay_nights Calendar nights.
	 * @return int
	 */
	public static function month_blocks( $stay_nights ) {
		return intdiv( max( 0, (int) $stay_nights ), self::BLOCK_NIGHTS );
	}

	/**
	 * @param int $stay_nights Calendar nights.
	 * @return int
	 */
	public static function remainder_nights( $stay_nights ) {
		return max( 0, (int) $stay_nights ) % self::BLOCK_NIGHTS;
	}

	/**
	 * Nights billed at per-night rates (weekly discount on the nightly portion only).
	 *
	 * @param int $stay_nights Calendar nights.
	 * @return int
	 */
	public static function nightly_billable_nights( $stay_nights ) {
		$stay = max( 0, (int) $stay_nights );
		if ( $stay < 1 ) {
			return 0;
		}
		$remainder = self::remainder_nights( $stay );
		if ( self::month_blocks( $stay ) > 0 ) {
			if ( $remainder < 1 ) {
				return 0;
			}
			return Stay_Discount::billable_weekly( $remainder );
		}
		return Stay_Discount::billable_weekly( $stay );
	}

	/**
	 * Total flat monthly portion for the stay.
	 *
	 * @param int $stay_nights Calendar nights.
	 * @return float
	 */
	public static function flat_portion_total( $stay_nights ) {
		return self::month_blocks( $stay_nights ) * self::flat_fee();
	}

	/**
	 * Calendar nights charged at per-night rates (remainder after full months).
	 *
	 * @param int $stay_nights Calendar nights.
	 * @return int
	 */
	public static function nightly_calendar_nights( $stay_nights ) {
		$stay = max( 0, (int) $stay_nights );
		if ( self::month_blocks( $stay ) > 0 ) {
			return self::remainder_nights( $stay );
		}
		return $stay;
	}

	/**
	 * Weekly free nights on the per-night portion only.
	 *
	 * @param int $stay_nights Calendar nights.
	 * @return int
	 */
	public static function weekly_free_nights( $stay_nights ) {
		$calendar = self::nightly_calendar_nights( $stay_nights );
		if ( $calendar < 1 ) {
			return 0;
		}
		return max( 0, $calendar - Stay_Discount::billable_weekly( $calendar ) );
	}

	/**
	 * @param int $stay Current stay nights.
	 * @return int
	 */
	private static function upsell_target_stay( $stay ) {
		$stay = max( 0, (int) $stay );
		if ( $stay < Stay_Discount::WEEK_STAY ) {
			return Stay_Discount::WEEK_STAY;
		}
		if ( $stay < self::BLOCK_NIGHTS ) {
			return self::BLOCK_NIGHTS;
		}
		$remainder = self::remainder_nights( $stay );
		if ( $remainder > 0 ) {
			return $stay + ( self::BLOCK_NIGHTS - $remainder );
		}
		return $stay + self::BLOCK_NIGHTS;
	}

	/**
	 * Discount / upsell info for campsite bookings (UI + cart notices).
	 *
	 * @param int $stay_nights Calendar nights.
	 * @return array<string, mixed>
	 */
	public static function info( $stay_nights ) {
		$stay            = max( 0, (int) $stay_nights );
		$months          = self::month_blocks( $stay );
		$billable        = self::nightly_billable_nights( $stay );
		$free            = self::weekly_free_nights( $stay );
		$flat_total      = self::flat_portion_total( $stay );
		$applied         = null;

		if ( $months > 0 ) {
			$applied = array(
				'kind'   => 'monthly_flat',
				'months' => $months,
				'flat'   => $flat_total,
			);
		} elseif ( $free > 0 ) {
			$applied = array(
				'kind' => 'weekly',
				'stay' => $stay,
				'pay'  => $billable,
			);
		}

		$target      = self::upsell_target_stay( $stay );
		$next          = null;
		$more_nights   = 0;
		$upsell_kind   = '';

		if ( $target > $stay ) {
			$more_nights = $target - $stay;
			if ( $stay < Stay_Discount::WEEK_STAY ) {
				$upsell_kind = 'weekly';
				$target_pay  = Stay_Discount::billable_weekly( $target );
				$save_nights = max( 0, $target - $target_pay );
				$next        = array(
					'kind'        => 'weekly',
					'stay'        => $target,
					'pay'         => $target_pay,
					'save_nights' => $save_nights,
				);
			} else {
				$upsell_kind = 'monthly_flat';
				$target_months = self::month_blocks( $target );
				$next          = array(
					'kind'        => 'monthly_flat',
					'stay'        => $target,
					'months'      => $target_months,
					'flat'        => $target_months * self::flat_fee(),
					'save_nights' => 0,
				);
			}
		}

		return array(
			'stay'          => $stay,
			'billable'      => $billable,
			'free'          => $free,
			'month_blocks'  => $months,
			'flat_total'    => $flat_total,
			'applied'       => $applied,
			'next'          => $next,
			'more_nights'   => $more_nights,
			'upsell_kind'   => $upsell_kind,
		);
	}
}
