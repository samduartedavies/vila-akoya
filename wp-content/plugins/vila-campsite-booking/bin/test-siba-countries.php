<?php
/**
 * Batch-test ICAO country codes against SIBA bawsdev (fictional unit).
 *
 * Usage: wp eval-file bin/test-siba-countries.php
 *
 * @package Vila_Campsite_Booking
 */

if ( ! class_exists( 'Vila_CB\\Siba_Client' ) ) {
	fwrite( STDERR, "Siba_Client not loaded\n" );
	exit( 1 );
}

$countries_js = file_get_contents( VILA_CB_DIR . 'assets/js/icao-countries.js' );
preg_match_all( "/code:\\s*'([A-Z0-9]+)'/", $countries_js, $m );
$codes = isset( $m[1] ) ? $m[1] : array();
echo 'codes_in_js=' . count( $codes ) . "\n";

$backup = get_option( Vila_CB\Guest_Registration_Exporter::OPTION_SETTINGS, array() );
update_option(
	Vila_CB\Guest_Registration_Exporter::OPTION_SETTINGS,
	Vila_CB\Guest_Registration_Exporter::sanitize_settings(
		array(
			'nif'           => '121212121',
			'establishment' => '00',
			'access_key'    => '999999999',
			'environment'   => 'dev',
			'unit_name'     => 'Unidade Teste',
			'abbreviation'  => 'UH TESTE',
			'address'       => 'Rua Teste 1',
			'locality'      => 'LISBOA',
			'postal_code'   => '1000-001',
			'phone'         => '210000000',
			'fax'           => '',
			'contact_name'  => 'Teste Contacto',
			'contact_email' => 'teste@example.com',
		)
	)
);

$failed     = array();
$ok         = array();
$chunk_size = 20;
$chunks     = array_chunk( $codes, $chunk_size );

foreach ( $chunks as $ci => $chunk ) {
	$rows = array();
	foreach ( $chunk as $i => $code ) {
		$rows[] = array(
			'surname'              => 'Test' . $code,
			'given_name'           => 'Guest',
			'nationality'          => $code,
			'date_of_birth'        => '1990-01-15',
			'document_number'      => 'X' . sprintf( '%05d', ( $ci * $chunk_size ) + $i ),
			'document_type'        => 'P',
			'document_country'     => $code,
			'check_in'             => '2026-07-20',
			'check_out'            => '2026-07-21',
			'country_of_residence' => $code,
		);
	}

	$res = Vila_CB\Siba_Client::submit( $rows );
	if ( is_wp_error( $res ) ) {
		$data = $res->get_error_data();
		$raw  = is_array( $data ) && isset( $data['result'] ) ? (string) $data['result'] : $res->get_error_message();
		echo 'CHUNK ' . $ci . ' FAIL (' . count( $chunk ) . '): ' . substr( $res->get_error_message(), 0, 160 ) . "\n";

		if ( preg_match_all( "/Nacionalidade '([A-Z0-9]+)'/", $raw, $nm ) ) {
			foreach ( $nm[1] as $bad ) {
				$failed[ $bad ] = 'nationality';
			}
		}
		if ( preg_match_all( '/País emissor[^\(]*\(([A-Z0-9 ]+)\)/u', $raw, $em ) ) {
			foreach ( $em[1] as $bad ) {
				$bad = trim( $bad );
				$failed[ $bad ] = isset( $failed[ $bad ] ) ? $failed[ $bad ] . '+issuer' : 'issuer';
			}
		}
		if ( preg_match_all( '/País de residência ([A-Z0-9]+) /u', $raw, $rm ) ) {
			foreach ( $rm[1] as $bad ) {
				$failed[ $bad ] = isset( $failed[ $bad ] ) ? $failed[ $bad ] . '+res' : 'residence';
			}
		}
		// If whole chunk failed but no country parsed, retest one-by-one.
		$known_bad = array_intersect( $chunk, array_keys( $failed ) );
		if ( empty( $known_bad ) ) {
			echo "  retesting chunk $ci one-by-one...\n";
			foreach ( $chunk as $code ) {
				$one = array(
					array(
						'surname'              => 'Solo' . $code,
						'given_name'           => 'Guest',
						'nationality'          => $code,
						'date_of_birth'        => '1990-01-15',
						'document_number'      => 'S' . substr( md5( $code ), 0, 8 ),
						'document_type'        => 'P',
						'document_country'     => $code,
						'check_in'             => '2026-07-20',
						'check_out'            => '2026-07-21',
						'country_of_residence' => $code,
					),
				);
				$one_res = Vila_CB\Siba_Client::submit( $one );
				if ( is_wp_error( $one_res ) ) {
					$failed[ $code ] = 'solo: ' . substr( $one_res->get_error_message(), 0, 100 );
					echo "  FAIL $code\n";
				} else {
					$ok[ $code ] = true;
					echo "  OK $code\n";
				}
				usleep( 200000 );
			}
		} else {
			foreach ( $chunk as $code ) {
				if ( ! isset( $failed[ $code ] ) ) {
					// Unknown: retest solos for remaining.
					$one = array(
						array(
							'surname'              => 'Solo' . $code,
							'given_name'           => 'Guest',
							'nationality'          => $code,
							'date_of_birth'        => '1990-01-15',
							'document_number'      => 'S' . substr( md5( $code . 'x' ), 0, 8 ),
							'document_type'        => 'P',
							'document_country'     => $code,
							'check_in'             => '2026-07-20',
							'check_out'            => '2026-07-21',
							'country_of_residence' => $code,
						),
					);
					$one_res = Vila_CB\Siba_Client::submit( $one );
					if ( is_wp_error( $one_res ) ) {
						$failed[ $code ] = 'solo: ' . substr( $one_res->get_error_message(), 0, 100 );
						echo "  FAIL $code\n";
					} else {
						$ok[ $code ] = true;
						echo "  OK $code\n";
					}
					usleep( 200000 );
				}
			}
		}
		@file_put_contents( '/tmp/siba-chunk-' . $ci . '.txt', $raw );
	} else {
		foreach ( $chunk as $code ) {
			$ok[ $code ] = true;
		}
		echo 'CHUNK ' . $ci . ' OK (' . count( $chunk ) . ') file#' . $res['file_number'] . ' via ' . $res['endpoint'] . "\n";
	}
	usleep( 350000 );
}

update_option( Vila_CB\Guest_Registration_Exporter::OPTION_SETTINGS, is_array( $backup ) ? $backup : array() );

echo 'OK_COUNT=' . count( $ok ) . "\n";
echo 'FAIL_COUNT=' . count( $failed ) . "\n";
ksort( $failed );
foreach ( $failed as $code => $why ) {
	echo "FAIL $code :: $why\n";
}
