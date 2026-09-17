<?php
/**
 * SIBA / CSV export helpers.
 *
 * @package Vila_Campsite_Booking
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Formats guest registrations for staff export.
 */
class Guest_Registration_Exporter {

	const OPTION_SETTINGS = 'vila_cb_siba_export_settings';
	const OPTION_SEQ      = 'vila_cb_siba_file_sequence';

	/**
	 * Official SIBA portal registration (Povoado Gentil LDA / Parque de campismo).
	 *
	 * @return array<string, string>
	 */
	public static function prefill_settings() {
		return array(
			'nif'           => '519423178',
			'establishment' => '0',
			'unit_name'     => 'Povoado Gentil LDA',
			'abbreviation'  => 'POVOADO GENTIL',
			'address'       => 'Lugar Serro da Bica S/N Monte Horta da Bica',
			'locality'      => 'ALDEIA DOS PALHEIROS',
			'postal_code'   => '7670-202',
			'phone'         => '960446285',
			'fax'           => '',
			'contact_name'  => 'Hans Wolfgramm',
			'contact_email' => 'Hans.wolfgramm@icloud.com',
			'access_key'    => '986501541973',
			'environment'   => 'prod',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function default_settings() {
		$prefill = self::prefill_settings();
		return array(
			'nif'           => $prefill['nif'],
			'establishment' => $prefill['establishment'],
			'unit_name'     => $prefill['unit_name'] !== '' ? $prefill['unit_name'] : get_bloginfo( 'name' ),
			'abbreviation'  => $prefill['abbreviation'],
			'address'       => $prefill['address'],
			'locality'      => $prefill['locality'],
			'postal_code'   => $prefill['postal_code'],
			'phone'         => $prefill['phone'],
			'fax'           => $prefill['fax'],
			'contact_name'  => $prefill['contact_name'],
			'contact_email' => $prefill['contact_email'] !== '' ? $prefill['contact_email'] : get_option( 'admin_email' ),
			'access_key'    => $prefill['access_key'],
			'environment'   => $prefill['environment'],
		);
	}

	/**
	 * Fill empty stored SIBA settings from prefill_settings() without overwriting edits.
	 */
	public static function seed_siba_settings() {
		self::apply_siba_prefill( false );
	}

	/**
	 * Apply official SIBA portal values to stored settings.
	 *
	 * @param bool $overwrite When true, replace all prefill keys (used after correcting seeded data).
	 */
	public static function apply_siba_prefill( $overwrite = false ) {
		$stored = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$changed = false;
		foreach ( self::prefill_settings() as $key => $value ) {
			if ( ! $overwrite && ( ! isset( $stored[ $key ] ) || '' === (string) $stored[ $key ] ) ) {
				$stored[ $key ] = (string) $value;
				$changed        = true;
				continue;
			}
			if ( $overwrite && ( ! isset( $stored[ $key ] ) || (string) $stored[ $key ] !== (string) $value ) ) {
				$stored[ $key ] = (string) $value;
				$changed        = true;
			}
		}
		if ( $changed ) {
			update_option( self::OPTION_SETTINGS, self::sanitize_settings( $stored ) );
		}
	}

	/**
	 * @return array<string, string>
	 */
	public static function settings() {
		$stored = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::default_settings(), array_map( 'strval', $stored ) );
	}

	/**
	 * @param array<string, mixed> $raw Raw settings.
	 * @return array<string, string>
	 */
	public static function sanitize_settings( array $raw ) {
		$out = self::default_settings();
		foreach ( $out as $key => $default ) {
			$value = isset( $raw[ $key ] ) ? (string) $raw[ $key ] : $default;
			if ( in_array( $key, array( 'nif', 'establishment', 'phone', 'fax', 'postal_code', 'access_key' ), true ) ) {
				$value = preg_replace( '/[^0-9]/', '', $value );
			} elseif ( 'contact_email' === $key ) {
				$value = sanitize_email( $value );
			} elseif ( 'environment' === $key ) {
				$value = 'prod' === $value ? 'prod' : 'dev';
			} elseif ( 'abbreviation' === $key ) {
				$value = sanitize_text_field( $value );
				$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 15 ) : substr( $value, 0, 15 );
			} else {
				$value = sanitize_text_field( $value );
			}
			$out[ $key ] = (string) $value;
		}
		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Export person rows.
	 * @return string
	 */
	public static function csv( array $rows ) {
		$fh = fopen( 'php://temp', 'r+' );
		if ( ! $fh ) {
			return '';
		}
		fputcsv(
			$fh,
			array(
				'registration_id',
				'booking_id',
				'order_id',
				'check_in',
				'check_out',
				'booker_name',
				'contact_email',
				'contact_phone',
				'stay_type',
				'given_name',
				'surname',
				'nationality',
				'date_of_birth',
				'document_type',
				'document_number',
				'document_country',
				'country_of_residence',
				'siba_status',
			)
		);
		foreach ( $rows as $row ) {
			fputcsv(
				$fh,
				array(
					isset( $row['registration_id'] ) ? (string) $row['registration_id'] : '',
					isset( $row['booking_id'] ) ? (string) $row['booking_id'] : '',
					isset( $row['order_id'] ) ? (string) $row['order_id'] : '',
					isset( $row['check_in'] ) ? (string) $row['check_in'] : '',
					isset( $row['check_out'] ) ? (string) $row['check_out'] : '',
					isset( $row['booker_name'] ) ? (string) $row['booker_name'] : '',
					isset( $row['contact_email'] ) ? (string) $row['contact_email'] : '',
					isset( $row['contact_phone'] ) ? (string) $row['contact_phone'] : '',
					isset( $row['stay_type'] ) ? (string) $row['stay_type'] : '',
					isset( $row['given_name'] ) ? (string) $row['given_name'] : '',
					isset( $row['surname'] ) ? (string) $row['surname'] : '',
					isset( $row['nationality'] ) ? (string) $row['nationality'] : '',
					isset( $row['date_of_birth'] ) ? (string) $row['date_of_birth'] : '',
					isset( $row['document_type'] ) ? (string) $row['document_type'] : '',
					isset( $row['document_number'] ) ? (string) $row['document_number'] : '',
					isset( $row['document_country'] ) ? (string) $row['document_country'] : '',
					isset( $row['country_of_residence'] ) ? (string) $row['country_of_residence'] : '',
					isset( $row['siba_status'] ) ? (string) $row['siba_status'] : '',
				)
			);
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );
		return false === $csv ? '' : $csv;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Export person rows.
	 * @return string|\WP_Error
	 */
	public static function dat( array $rows ) {
		$settings = self::settings();
		if ( '' === $settings['nif'] || '' === $settings['establishment'] ) {
			return new \WP_Error( 'missing_siba_settings', __( 'Add the SIBA NIF and establishment number in Campsite settings before exporting DAT.', 'vila-campsite-booking' ) );
		}
		if ( empty( $rows ) ) {
			return new \WP_Error( 'no_siba_rows', __( 'There are no non-Portuguese guest rows to export.', 'vila-campsite-booking' ) );
		}

		$seq   = max( 1, (int) get_option( self::OPTION_SEQ, 1 ) );
		$lines = array();
		$lines[] = self::header_line( $settings );
		foreach ( $rows as $row ) {
			$lines[] = self::person_line( $row );
		}
		$lines[] = self::trailer_line( count( $lines ) + 1, $seq );
		update_option( self::OPTION_SEQ, $seq + 1 );

		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * @param array<string, string> $settings Settings.
	 * @return string
	 */
	private static function header_line( array $settings ) {
		$postal = preg_replace( '/[^0-9]/', '', $settings['postal_code'] );
		$postal = str_pad( (string) $postal, 7, '0', STR_PAD_RIGHT );
		return '0'
			. self::field( 'BA03', 4 )
			. self::digits( $settings['nif'], 9 )
			. self::digits( $settings['establishment'], 4 )
			. self::field( $settings['unit_name'], 40 )
			. self::field( $settings['address'], 40 )
			. self::field( $settings['locality'], 30 )
			. self::digits( substr( $postal, 0, 4 ), 4 )
			. self::digits( substr( $postal, 4, 3 ), 3 )
			. self::digits( $settings['phone'], 10 )
			. self::digits( $settings['fax'], 10 )
			. self::field( $settings['contact_name'], 40 )
			. self::field( $settings['contact_email'], 140 );
	}

	/**
	 * @param array<string, mixed> $row Export row.
	 * @return string
	 */
	private static function person_line( array $row ) {
		return '1'
			. self::field( isset( $row['surname'] ) ? (string) $row['surname'] : '', 40 )
			. self::field( isset( $row['given_name'] ) ? (string) $row['given_name'] : '', 40 )
			. self::field( isset( $row['nationality'] ) ? (string) $row['nationality'] : '', 3 )
			. self::date_field( isset( $row['date_of_birth'] ) ? (string) $row['date_of_birth'] : '' )
			. self::field( '', 30 )
			. self::field( isset( $row['document_number'] ) ? (string) $row['document_number'] : '', 16 )
			. self::field( isset( $row['document_type'] ) ? (string) $row['document_type'] : '', 3 )
			. self::field( isset( $row['document_country'] ) ? (string) $row['document_country'] : '', 3 )
			. self::date_field( isset( $row['check_in'] ) ? (string) $row['check_in'] : '' )
			. self::date_field( isset( $row['check_out'] ) ? (string) $row['check_out'] : '' )
			. self::field( isset( $row['country_of_residence'] ) ? (string) $row['country_of_residence'] : '', 3 )
			. self::field( '', 30 );
	}

	/**
	 * @param int $count Record count including trailer.
	 * @param int $seq File sequence number.
	 * @return string
	 */
	private static function trailer_line( $count, $seq ) {
		return '9'
			. self::digits( (string) $count, 5 )
			. gmdate( 'Ymd', current_time( 'timestamp' ) )
			. self::digits( (string) $seq, 5 );
	}

	/**
	 * @param string $ymd Date in Y-m-d.
	 * @return string
	 */
	private static function date_field( $ymd ) {
		$dt = Dates::parse_ymd( (string) $ymd, 1900 );
		return $dt ? $dt->format( 'Ymd' ) : str_repeat( ' ', 8 );
	}

	/**
	 * @param string $value Raw string.
	 * @param int    $len Field length.
	 * @return string
	 */
	private static function field( $value, $len ) {
		$value = remove_accents( strtoupper( (string) $value ) );
		$value = preg_replace( '/[^A-Z0-9 @._,\-\'"]/', ' ', $value );
		$value = preg_replace( '/\s+/', ' ', trim( (string) $value ) );
		return str_pad( substr( (string) $value, 0, (int) $len ), (int) $len, ' ', STR_PAD_RIGHT );
	}

	/**
	 * @param string $value Raw digits.
	 * @param int    $len Field length.
	 * @return string
	 */
	private static function digits( $value, $len ) {
		$value = preg_replace( '/[^0-9]/', '', (string) $value );
		return str_pad( substr( (string) $value, 0, (int) $len ), (int) $len, '0', STR_PAD_LEFT );
	}
}
