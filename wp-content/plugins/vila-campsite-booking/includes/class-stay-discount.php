<?php
/**
 * Long-stay pitch discounts with repeating blocks.
 *
 * Weekly: every 7 nights → 1 night free (pay 6 per week).
 * Monthly: every 30 nights → pay 20 (10 nights free per block) — village accommodation only.
 * Campsite bookings use {@see Campsite_Monthly_Rate} instead of the monthly night discount.
 * The better scheme applies to the whole stay — weekly and monthly are not combined.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Repeating block discounts; best single scheme wins for the full stay length.
 */
class Stay_Discount {

	const WEEK_STAY = 7;
	const WEEK_PAY  = 6;
	const MONTH_STAY = 30;
	const MONTH_PAY  = 20;

	/**
	 * @return array<int, array{ stay: int, pay: int }>
	 */
	public static function tiers() {
		$tiers = array(
			array(
				'stay' => self::WEEK_STAY,
				'pay'  => self::WEEK_PAY,
			),
			array(
				'stay' => self::MONTH_STAY,
				'pay'  => self::MONTH_PAY,
			),
		);
		/**
		 * Long-stay discount block sizes (sorted by stay nights, ascending).
		 *
		 * @param array<int, array{ stay: int, pay: int }> $tiers Tiers.
		 */
		$tiers = apply_filters( 'vila_cb_stay_discount_tiers', $tiers );
		if ( ! is_array( $tiers ) ) {
			return array();
		}
		$out = array();
		foreach ( $tiers as $tier ) {
			if ( ! is_array( $tier ) ) {
				continue;
			}
			$stay = isset( $tier['stay'] ) ? (int) $tier['stay'] : 0;
			$pay  = isset( $tier['pay'] ) ? (int) $tier['pay'] : 0;
			if ( $stay < 1 || $pay < 0 || $pay >= $stay ) {
				continue;
			}
			$out[] = array(
				'stay' => $stay,
				'pay'  => $pay,
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $a['stay'] <=> $b['stay'];
			}
		);
		return $out;
	}

	/**
	 * Billable nights if only weekly blocks apply (1 free night per full week).
	 *
	 * @param int $stay Stay nights.
	 * @return int
	 */
	public static function billable_weekly( $stay ) {
		$stay = max( 0, (int) $stay );
		return $stay - intdiv( $stay, self::WEEK_STAY );
	}

	/**
	 * Billable nights if only monthly blocks apply (pay 20 per full 30 nights).
	 *
	 * @param int $stay Stay nights.
	 * @return int
	 */
	public static function billable_monthly( $stay ) {
		$stay      = max( 0, (int) $stay );
		$blocks    = intdiv( $stay, self::MONTH_STAY );
		$remainder = $stay % self::MONTH_STAY;
		return ( $blocks * self::MONTH_PAY ) + $remainder;
	}

	/**
	 * Nights charged for pitch, extras, and guest supplements.
	 *
	 * @param int $stay_nights Calendar nights.
	 * @return int
	 */
	public static function billable_nights( $stay_nights ) {
		$stay = max( 0, (int) $stay_nights );
		if ( $stay < 1 ) {
			return 0;
		}
		return min( self::billable_weekly( $stay ), self::billable_monthly( $stay ) );
	}

	/**
	 * Next stay length to upsell (bigger discount tier), not the next weekly step.
	 *
	 * @param int $stay Current stay nights.
	 * @return int
	 */
	private static function upsell_target_stay( $stay ) {
		$stay = max( 0, (int) $stay );
		if ( $stay < self::WEEK_STAY ) {
			return self::WEEK_STAY;
		}
		if ( $stay < self::MONTH_STAY ) {
			return self::MONTH_STAY;
		}
		$blocks = intdiv( $stay, self::MONTH_STAY );
		return ( $blocks + 1 ) * self::MONTH_STAY;
	}

	/**
	 * @param int $stay_nights Calendar nights of the stay.
	 * @return array{stay: int, billable: int, free: int, applied: array{stay: int, pay: int}|null, next: array{stay: int, pay: int, save_nights: int}|null, more_nights: int}
	 */
	public static function info( $stay_nights ) {
		$stay     = max( 0, (int) $stay_nights );
		$billable = self::billable_nights( $stay );
		$free     = max( 0, $stay - $billable );
		$applied  = $free > 0 ? array(
			'stay' => $stay,
			'pay'  => $billable,
		) : null;

		$target        = self::upsell_target_stay( $stay );
		$target_free   = max( 0, $target - self::billable_nights( $target ) );
		$save_nights   = max( 0, $target_free - $free );
		$next          = null;
		$more_nights   = 0;
		if ( $save_nights > 0 && $target > $stay ) {
			$next = array(
				'stay'        => $target,
				'pay'         => self::billable_nights( $target ),
				'save_nights' => $save_nights,
			);
			$more_nights = $target - $stay;
		}

		return array(
			'stay'        => $stay,
			'billable'    => $billable,
			'free'        => $free,
			'applied'     => $applied,
			'next'        => $next,
			'more_nights' => $more_nights,
		);
	}

	/**
	 * Tiers in the shape the booking UI script expects.
	 *
	 * @return array<int, array{ stay: int, pay: int }>
	 */
	public static function tiers_for_js() {
		return self::tiers();
	}
}
