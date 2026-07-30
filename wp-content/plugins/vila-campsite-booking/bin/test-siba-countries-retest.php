<?php
/**
 * Retest remaining / previously unverified SIBA country codes on bawsdev.
 *
 * @package Vila_Campsite_Booking
 */

if ( ! class_exists( 'Vila_CB\\Siba_Client' ) ) {
	fwrite( STDERR, "Siba_Client not loaded\n" );
	exit( 1 );
}

// Codes that still need a definitive result after the first run (404 noise after PSE).
$codes = array(
	'NER', 'NGA', 'MKD', 'NOR', 'OMN', 'PAK', 'PLW', 'PSE', 'PAN', 'PNG', 'PRY', 'PER',
	'PHL', 'POL', 'PRT', 'QAT', 'ROU', 'RUS', 'RWA', 'KNA', 'LCA', 'VCT', 'WSM', 'SMR',
	'STP', 'SAU', 'SEN', 'SRB', 'SYC', 'SLE', 'SGP', 'SVK', 'SVN', 'SLB', 'SOM', 'ZAF',
	'SSD', 'ESP', 'LKA', 'SDN', 'SUR', 'SWE', 'CHE', 'SYR', 'TWN', 'TJK', 'TZA', 'THA',
	'TLS', 'TGO', 'TON', 'TTO', 'TUN', 'TUR', 'TKM', 'TUV', 'UGA', 'UKR', 'ARE', 'GBR',
	'USA', 'URY', 'UZB', 'VUT', 'VEN', 'VNM', 'YEM', 'ZMB', 'ZWE', 'XXA', 'XXB', 'XXX',
);

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
			'contact_name'  => 'Teste Contacto',
			'contact_email' => 'teste@example.com',
		)
	)
);

$failed = array();
$ok     = array();
$http   = array();

foreach ( array_chunk( $codes, 15 ) as $ci => $chunk ) {
	$rows = array();
	foreach ( $chunk as $i => $code ) {
		$rows[] = array(
			'surname'              => 'Retest' . $code,
			'given_name'           => 'Guest',
			'nationality'          => $code,
			'date_of_birth'        => '1990-01-15',
			'document_number'      => 'R' . sprintf( '%05d', ( $ci * 15 ) + $i ),
			'document_type'        => 'P',
			'document_country'     => $code,
			'check_in'             => '2026-07-22',
			'check_out'            => '2026-07-23',
			'country_of_residence' => $code,
		);
	}

	$attempts = 0;
	do {
		$attempts++;
		$res = Vila_CB\Siba_Client::submit( $rows );
		if ( ! is_wp_error( $res ) ) {
			foreach ( $chunk as $code ) {
				$ok[ $code ] = true;
			}
			echo "CHUNK $ci OK (" . count( $chunk ) . ")\n";
			break;
		}
		$msg  = $res->get_error_message();
		$data = $res->get_error_data();
		$raw  = is_array( $data ) && isset( $data['result'] ) ? (string) $data['result'] : $msg;
		if ( false !== stripos( $msg, '404' ) || false !== stripos( $msg, 'resolve host' ) || false !== stripos( $msg, 'Could not resolve' ) ) {
			echo "CHUNK $ci HTTP/DNS retry $attempts: $msg\n";
			$http[] = $ci;
			sleep( 2 );
			continue;
		}
		echo "CHUNK $ci REJECT: " . substr( $msg, 0, 180 ) . "\n";
		$bad_in_chunk = array();
		if ( preg_match_all( "/Nacionalidade '([A-Z0-9]+)'/", $raw, $nm ) ) {
			foreach ( $nm[1] as $bad ) {
				$failed[ $bad ] = 'nationality';
				$bad_in_chunk[ $bad ] = true;
			}
		}
		if ( preg_match_all( '/País emissor[^\(]*\(([A-Z0-9 ]+)\)/u', $raw, $em ) ) {
			foreach ( $em[1] as $bad ) {
				$bad = trim( $bad );
				$failed[ $bad ] = isset( $failed[ $bad ] ) ? $failed[ $bad ] . '+issuer' : 'issuer';
				$bad_in_chunk[ $bad ] = true;
			}
		}
		if ( preg_match_all( '/País de residência ([A-Z0-9]+) /u', $raw, $rm ) ) {
			foreach ( $rm[1] as $bad ) {
				$failed[ $bad ] = isset( $failed[ $bad ] ) ? $failed[ $bad ] . '+res' : 'residence';
				$bad_in_chunk[ $bad ] = true;
			}
		}
		// Retest non-bad codes from this chunk individually.
		foreach ( $chunk as $code ) {
			if ( isset( $bad_in_chunk[ $code ] ) ) {
				echo "  BAD $code\n";
				continue;
			}
			$one_res = Vila_CB\Siba_Client::submit(
				array(
					array(
						'surname'              => 'One' . $code,
						'given_name'           => 'Guest',
						'nationality'          => $code,
						'date_of_birth'        => '1990-01-15',
						'document_number'      => 'O' . substr( md5( $code . wp_rand() ), 0, 8 ),
						'document_type'        => 'P',
						'document_country'     => $code,
						'check_in'             => '2026-07-22',
						'check_out'            => '2026-07-23',
						'country_of_residence' => $code,
					),
				)
			);
			if ( is_wp_error( $one_res ) ) {
				$omsg = $one_res->get_error_message();
				if ( false !== stripos( $omsg, '404' ) || false !== stripos( $omsg, 'resolve' ) ) {
					echo "  RETRY-LATER $code ($omsg)\n";
					$http[] = $code;
				} else {
					$failed[ $code ] = substr( $omsg, 0, 120 );
					echo "  FAIL $code\n";
				}
			} else {
				$ok[ $code ] = true;
				echo "  OK $code\n";
			}
			usleep( 250000 );
		}
		break;
	} while ( $attempts < 4 );

	usleep( 400000 );
}

update_option( Vila_CB\Guest_Registration_Exporter::OPTION_SETTINGS, is_array( $backup ) ? $backup : array() );

echo 'OK_COUNT=' . count( $ok ) . "\n";
echo 'FAIL_COUNT=' . count( $failed ) . "\n";
ksort( $failed );
foreach ( $failed as $code => $why ) {
	echo "FAIL $code :: $why\n";
}
if ( ! empty( $http ) ) {
	echo 'HTTP_ISSUES=' . count( $http ) . "\n";
}
