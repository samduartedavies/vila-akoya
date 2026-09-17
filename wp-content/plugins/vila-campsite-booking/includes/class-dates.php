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
	 * Booking dates default to 1970+ (Unix-safe). Pass a lower $min_year for
	 * dates of birth, which commonly predate 1970.
	 *
	 * @param string $ymd      Date string.
	 * @param int    $min_year Inclusive minimum year.
	 * @return \DateTimeImmutable|null
	 */
	public static function parse_ymd( $ymd, $min_year = 1970 ) {
		$ymd = trim( (string) $ymd );
		if ( '' === $ymd || '0000-00-00' === $ymd || str_starts_with( $ymd, '0000-' ) ) {
			return null;
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) {
			return null;
		}
		$min_year = (int) $min_year;
		try {
			$d = new \DateTimeImmutable( $ymd . ' 00:00:00', wp_timezone() );
			if ( $d->format( 'Y-m-d' ) !== $ymd || (int) $d->format( 'Y' ) < $min_year ) {
				return null;
			}
			return $d;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Parse a date of birth (1900 through today).
	 *
	 * @param string $ymd Date string.
	 * @return \DateTimeImmutable|null
	 */
	public static function parse_birth_ymd( $ymd ) {
		$dt = self::parse_ymd( $ymd, 1900 );
		if ( ! $dt ) {
			return null;
		}
		$today = new \DateTimeImmutable( 'today', wp_timezone() );
		return $dt > $today ? null : $dt;
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

	/**
	 * Parse d-m-Y (MWB / theme booking fields) in site timezone.
	 *
	 * @param string $dmy Date string.
	 * @return \DateTimeImmutable|null
	 */
	public static function parse_dmy( $dmy ) {
		$dmy = trim( (string) $dmy );
		if ( '' === $dmy ) {
			return null;
		}
		$dt = \DateTimeImmutable::createFromFormat( '!d-m-Y', $dmy, wp_timezone() );
		return $dt instanceof \DateTimeImmutable ? $dt : null;
	}

	/**
	 * @param string $dmy d-m-Y.
	 * @return string Y-m-d or empty.
	 */
	public static function dmy_to_ymd( $dmy ) {
		$dt = self::parse_dmy( $dmy );
		return $dt ? $dt->format( 'Y-m-d' ) : '';
	}

	/**
	 * Normalize booking meta (d-m-Y, Y-m-d, or MWB display strings) to Y-m-d.
	 *
	 * @param string $date_str Date string from order meta or forms.
	 * @return string Y-m-d or empty when unparseable.
	 */
	public static function to_ymd( $date_str ) {
		$date_str = trim( (string) $date_str );
		if ( '' === $date_str || '0000-00-00' === $date_str ) {
			return '';
		}
		$ymd = self::dmy_to_ymd( $date_str );
		if ( '' !== $ymd ) {
			return $ymd;
		}
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $date_str, $m ) ) {
			$parsed = self::parse_ymd( $m[1] );
			if ( $parsed ) {
				return $parsed->format( 'Y-m-d' );
			}
		}
		$wp_df = (string) get_option( 'date_format' );
		if ( '' !== $wp_df ) {
			$dt = \DateTime::createFromFormat( $wp_df, $date_str, wp_timezone() );
			if ( $dt instanceof \DateTimeInterface ) {
				return $dt->format( 'Y-m-d' );
			}
		}
		$natural = date_create( $date_str, wp_timezone() );
		return $natural instanceof \DateTimeInterface ? $natural->format( 'Y-m-d' ) : '';
	}

	/**
	 * Derive [check_in, check_out) bounds from occupied night Y-m-d values.
	 *
	 * @param string[] $nights Night dates.
	 * @return array{check_in: string, check_out: string}|null
	 */
	public static function bounds_from_nights( array $nights ) {
		$clean = array();
		foreach ( $nights as $night ) {
			$ymd = self::to_ymd( (string) $night );
			if ( '' !== $ymd ) {
				$clean[ $ymd ] = true;
			}
		}
		if ( empty( $clean ) ) {
			return null;
		}
		$keys = array_keys( $clean );
		sort( $keys );
		$check_in = (string) $keys[0];
		$last     = self::parse_ymd( (string) end( $keys ) );
		if ( ! $last ) {
			return null;
		}
		return array(
			'check_in'  => $check_in,
			'check_out' => $last->modify( '+1 day' )->format( 'Y-m-d' ),
		);
	}

	/**
	 * Human-readable stay range for admin lists.
	 *
	 * @param string               $check_in  Y-m-d.
	 * @param string               $check_out Y-m-d exclusive.
	 * @param string[]             $nights    Optional occupied nights fallback.
	 * @param string               $df        WP date format.
	 * @return string
	 */
	public static function format_stay_range_label( $check_in, $check_out, array $nights, $df ) {
		$df = is_string( $df ) && '' !== $df ? $df : 'Y-m-d';
		if ( empty( self::nights_in_range( $check_in, $check_out ) ) ) {
			$bounds = self::bounds_from_nights( $nights );
			if ( $bounds ) {
				$check_in  = $bounds['check_in'];
				$check_out = $bounds['check_out'];
			}
		}
		$start = self::parse_ymd( $check_in );
		$end   = self::parse_ymd( $check_out );
		if ( $start && $end ) {
			return sprintf(
				/* translators: 1: check-in, 2: check-out */
				__( '%1$s → %2$s', 'vila-campsite-booking' ),
				date_i18n( $df, $start->getTimestamp() ),
				date_i18n( $df, $end->getTimestamp() )
			);
		}
		if ( '' !== (string) $check_in || '' !== (string) $check_out ) {
			return (string) $check_in . ' → ' . (string) $check_out;
		}
		return '—';
	}
}
