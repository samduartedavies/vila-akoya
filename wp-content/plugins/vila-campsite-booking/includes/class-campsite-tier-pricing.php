<?php
/**
 * Campsite accommodation: standard vs solo tier pricing (1 / 7 / 28 nights), with optional no-electricity rates.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Tier rate card and accommodation totals for campsite bookings.
 */
class Campsite_Tier_Pricing {

	const WEEK_NIGHTS       = 7;
	const FOUR_WEEKS_NIGHTS = 28;

	/**
	 * Default tier amounts (EUR). All “with electricity” cards include pitch power unless guest opts out.
	 *
	 * @return array<string, array{ night: float, week: float, four_weeks: float }>
	 */
	public static function default_tiers() {
		return array(
			'standard'                  => array(
				'night'      => 27.0,
				'week'       => 165.0,
				'four_weeks' => 555.0,
			),
			'standard_no_electricity'   => array(
				'night'      => 24.0,
				'week'       => 147.0,
				'four_weeks' => 495.0,
			),
			'solo_tent'                 => array(
				'night'      => 15.0,
				'week'       => 90.0,
				'four_weeks' => 330.0,
			),
			'solo_tent_no_electricity'  => array(
				'night'      => 12.0,
				'week'       => 72.0,
				'four_weeks' => 270.0,
			),
		);
	}

	/**
	 * @param bool $solo_tent       One adult (solo traveller rate).
	 * @param bool $no_electricity Guest opted out of pitch electricity.
	 * @return string
	 */
	public static function tier_storage_key( $solo_tent, $no_electricity ) {
		if ( $solo_tent ) {
			return $no_electricity ? 'solo_tent_no_electricity' : 'solo_tent';
		}
		return $no_electricity ? 'standard_no_electricity' : 'standard';
	}

	/**
	 * Saved + default tier rates (filterable).
	 *
	 * @param bool $solo_tent       Solo traveller (1 adult).
	 * @param bool $no_electricity  Without pitch electricity.
	 * @return array{ night: float, week: float, four_weeks: float }
	 */
	public static function rates( $solo_tent = false, $no_electricity = false ) {
		$defaults = self::default_tiers();
		$key      = self::tier_storage_key( $solo_tent, $no_electricity );
		$base     = isset( $defaults[ $key ] ) ? $defaults[ $key ] : $defaults['standard'];
		if ( class_exists( __NAMESPACE__ . '\\Pricing_Settings' ) ) {
			$cfg = Pricing_Settings::get();
			if ( ! empty( $cfg['campsite_tiers'][ $key ] ) && is_array( $cfg['campsite_tiers'][ $key ] ) ) {
				$row = $cfg['campsite_tiers'][ $key ];
				foreach ( array( 'night', 'week', 'four_weeks' ) as $k ) {
					if ( isset( $row[ $k ] ) ) {
						$base[ $k ] = max( 0.0, (float) $row[ $k ] );
					}
				}
			}
		}
		/**
		 * Campsite tier rates for the selected card.
		 *
		 * @param array{ night: float, week: float, four_weeks: float } $base Rates.
		 * @param bool                                                   $solo_tent Solo when true.
		 * @param bool                                                   $no_electricity Opt-out when true.
		 */
		return (array) apply_filters( 'vila_cb_campsite_tier_rates', $base, (bool) $solo_tent, (bool) $no_electricity );
	}

	/**
	 * @param int $stay_nights Calendar nights.
	 * @return array{ four_week_blocks: int, week_blocks: int, single_nights: int }
	 */
	public static function decompose_nights( $stay_nights ) {
		$n      = max( 0, (int) $stay_nights );
		$four   = intdiv( $n, self::FOUR_WEEKS_NIGHTS );
		$rem    = $n % self::FOUR_WEEKS_NIGHTS;
		$week   = intdiv( $rem, self::WEEK_NIGHTS );
		$single = $rem % self::WEEK_NIGHTS;
		return array(
			'four_week_blocks' => $four,
			'week_blocks'      => $week,
			'single_nights'    => $single,
		);
	}

	/**
	 * @param int  $stay_nights    Calendar nights.
	 * @param bool $solo_tent      Solo traveller (1 adult).
	 * @param bool $no_electricity Without pitch electricity.
	 * @return float
	 */
	public static function accommodation_total( $stay_nights, $solo_tent = false, $no_electricity = false ) {
		$n = max( 0, (int) $stay_nights );
		if ( $n < 1 ) {
			return 0.0;
		}
		$r     = self::rates( $solo_tent, $no_electricity );
		$parts = self::decompose_nights( $n );
		$total = ( $parts['four_week_blocks'] * (float) $r['four_weeks'] )
			+ ( $parts['week_blocks'] * (float) $r['week'] )
			+ ( $parts['single_nights'] * (float) $r['night'] );
		return (float) apply_filters( 'vila_cb_campsite_tier_accommodation_total', $total, $n, (bool) $solo_tent, (bool) $no_electricity );
	}

	/**
	 * Solo traveller rate: one adult with no children or babies (pets allowed).
	 *
	 * @param array<string, int> $guests        adults, children, infants, pets.
	 * @param string[]           $vehicle_slugs Unused (legacy).
	 * @return bool
	 */
	public static function is_solo_tent_stay( array $guests, array $vehicle_slugs = array() ) {
		unset( $vehicle_slugs );
		$adults   = isset( $guests['adults'] ) ? max( 0, (int) $guests['adults'] ) : 0;
		$children = isset( $guests['children'] ) ? max( 0, (int) $guests['children'] ) : 0;
		$infants  = isset( $guests['infants'] ) ? max( 0, (int) $guests['infants'] ) : 0;
		return 1 === $adults && 0 === $children && 0 === $infants;
	}

	/**
	 * @param int $stay Current nights.
	 * @return int
	 */
	private static function upsell_target_stay( $stay ) {
		$stay = max( 0, (int) $stay );
		if ( $stay < self::WEEK_NIGHTS ) {
			return self::WEEK_NIGHTS;
		}
		if ( $stay < self::FOUR_WEEKS_NIGHTS ) {
			return self::FOUR_WEEKS_NIGHTS;
		}
		$blocks = intdiv( $stay, self::FOUR_WEEKS_NIGHTS );
		$rem    = $stay % self::FOUR_WEEKS_NIGHTS;
		if ( $rem > 0 ) {
			return ( $blocks + 1 ) * self::FOUR_WEEKS_NIGHTS;
		}
		return $stay + self::FOUR_WEEKS_NIGHTS;
	}

	/**
	 * @param int  $stay_nights    Calendar nights.
	 * @param bool $solo_tent      Solo traveller.
	 * @param bool $no_electricity Opt-out.
	 * @return array<string, mixed>
	 */
	public static function info( $stay_nights, $solo_tent = false, $no_electricity = false ) {
		$stay   = max( 0, (int) $stay_nights );
		$target = self::upsell_target_stay( $stay );
		$more   = max( 0, $target - $stay );
		$next   = null;
		if ( $more > 0 && $target > $stay ) {
			$current     = self::accommodation_total( $stay, $solo_tent, $no_electricity );
			$at_target   = self::accommodation_total( $target, $solo_tent, $no_electricity );
			$night_rate  = (float) self::rates( $solo_tent, $no_electricity )['night'];
			$next        = array(
				'kind'        => $target === self::WEEK_NIGHTS ? 'week' : 'four_weeks',
				'stay'        => $target,
				'total'       => $at_target,
				'save_amount' => max( 0.0, $current + ( ( $target - $stay ) * $night_rate ) - $at_target ),
			);
		}
		return array(
			'stay'            => $stay,
			'solo_tent'       => (bool) $solo_tent,
			'no_electricity'  => (bool) $no_electricity,
			'total'           => self::accommodation_total( $stay, $solo_tent, $no_electricity ),
			'next'            => $next,
			'more_nights'     => $more,
		);
	}
}
