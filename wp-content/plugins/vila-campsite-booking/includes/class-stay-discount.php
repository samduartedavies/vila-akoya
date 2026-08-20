<?php
/**
 * Long-stay pitch discounts: stay 7 pay 6, stay 30 pay 20.
 *
 * Applies to pitch, extras, and guest supplements. Meals stay charged per night stayed.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Threshold deals: the largest qualifying tier wins (30-for-20 supersedes 7-for-6).
 */
class Stay_Discount {

	/**
	 * @return array<int, array{ stay: int, pay: int }>
	 */
	public static function tiers() {
		$tiers = array(
			array(
				'stay' => 7,
				'pay'  => 6,
			),
			array(
				'stay' => 30,
				'pay'  => 20,
			),
		);
		/**
		 * Long-stay discount tiers (sorted by stay nights, ascending).
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
	 * @param int $stay_nights Calendar nights of the stay.
	 * @return array{stay: int, billable: int, free: int, applied: array{stay: int, pay: int}|null, next: array{stay: int, pay: int}|null, more_nights: int}
	 */
	public static function info( $stay_nights ) {
		$stay  = max( 0, (int) $stay_nights );
		$tiers = self::tiers();
		$applied = null;
		foreach ( $tiers as $tier ) {
			if ( $stay >= $tier['stay'] ) {
				$applied = $tier;
			}
		}
		$free     = $applied ? ( $applied['stay'] - $applied['pay'] ) : 0;
		$billable = max( 0, $stay - $free );
		$next     = null;
		foreach ( $tiers as $tier ) {
			if ( $stay < $tier['stay'] ) {
				$next = $tier;
				break;
			}
		}
		return array(
			'stay'        => $stay,
			'billable'    => $billable,
			'free'        => $free,
			'applied'     => $applied,
			'next'        => $next,
			'more_nights' => $next ? max( 0, $next['stay'] - $stay ) : 0,
		);
	}

	/**
	 * Nights charged for pitch, extras, and guest supplements.
	 *
	 * @param int $stay_nights Calendar nights.
	 * @return int
	 */
	public static function billable_nights( $stay_nights ) {
		$info = self::info( $stay_nights );
		return (int) $info['billable'];
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
