<?php
/**
 * Date helpers (site timezone, half-open ranges).
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Date utilities for [check_in, check_out) night lists.
 */
class Dates {

	/**
	 * Parse Y-m-d in site timezone.
	 *
	 * @param string $ymd Date string.
	 * @return \DateTimeImmutable|null
	 */
	public static function parse_ymd( $ymd ) {
		$ymd = trim( (string) $ymd );
		if ( '' === $ymd ) {
			return null;
		}
		try {
			$d = new \DateTimeImmutable( $ymd . ' 00:00:00', wp_timezone() );
			return $d;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * List each night Y-m-d for [check_in, check_out) — checkout is exclusive.
	 *
	 * @param string $check_in Y-m-d.
	 * @param string $check_out Y-m-d exclusive end.
	 * @return string[]
	 */
	public static function nights_in_range( $check_in, $check_out ) {
		$a = self::parse_ymd( $check_in );
		$b = self::parse_ymd( $check_out );
		if ( ! $a || ! $b || $b <= $a ) {
			return array();
		}
		$out = array();
		$cur = $a;
		while ( $cur < $b ) {
			$out[] = $cur->format( 'Y-m-d' );
			$cur   = $cur->modify( '+1 day' );
		}
		return $out;
	}

	/**
	 * @param string $check_in Y-m-d.
	 * @param string $check_out Y-m-d exclusive.
	 * @return int
	 */
	public static function night_count( $check_in, $check_out ) {
		return count( self::nights_in_range( $check_in, $check_out ) );
	}

	/**
	 * Enumerate Y-m-d from start to end (inclusive) for calendar APIs.
	 *
	 * @param string $from_ymd Y-m-d.
	 * @param string $to_ymd Y-m-d inclusive.
	 * @return string[]
	 */
	public static function enumerate_inclusive( $from_ymd, $to_ymd ) {
		$a = self::parse_ymd( $from_ymd );
		$b = self::parse_ymd( $to_ymd );
		if ( ! $a || ! $b || $b < $a ) {
			return array();
		}
		$out = array();
		$cur = $a;
		while ( $cur <= $b ) {
			$out[] = $cur->format( 'Y-m-d' );
			$cur   = $cur->modify( '+1 day' );
		}
		return $out;
	}
}
