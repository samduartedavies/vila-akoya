<?php
/**
 * Final pass for codes interrupted by DNS + remove known-bad from JS later.
 *
 * @package Vila_Campsite_Booking
 */

$codes = array(
	'OMN', 'PAK', 'PLW', 'PAN', 'PNG', 'PRY', 'PER', 'PHL', 'POL', 'PRT',
	'QAT', 'ROU', 'RUS', 'RWA', 'KNA', 'LCA', 'VCT', 'WSM', 'SMR', 'STP',
	'SAU', 'SEN', 'SRB', 'SYC', 'SLE', 'SGP', 'SVK', 'SVN', 'SLB', 'SOM',
	'ZAF', 'SSD', 'ESP', 'LKA', 'SDN', 'SUR', 'SWE', 'CHE', 'SYR', 'TWN',
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

foreach ( array_chunk( $codes, 10 ) as $ci => $chunk ) {
	for ( $attempt = 1; $attempt <= 5; $attempt++ ) {
		$rows = array();
		foreach ( $chunk as $i => $code ) {
			$rows[] = array(
				'surname'              => 'Fin' . $code,
				'given_name'           => 'Guest',
				'nationality'          => $code,
				'date_of_birth'        => '1990-01-15',
				'document_number'      => 'F' . sprintf( '%05d', ( $ci * 10 ) + $i ) . $attempt,
				'document_type'        => 'P',
				'document_country'     => $code,
				'check_in'             => '2026-07-24',
				'check_out'            => '2026-07-25',
				'country_of_residence' => $code,
			);
		}
		$res = Vila_CB\Siba_Client::submit( $rows );
		if ( ! is_wp_error( $res ) ) {
			foreach ( $chunk as $code ) {
				$ok[ $code ] = true;
			}
			echo "CHUNK $ci OK\n";
			break;
		}
		$msg = $res->get_error_message();
		if ( false !== stripos( $msg, 'resolve' ) || false !== stripos( $msg, '404' ) ) {
			echo "CHUNK $ci DNS attempt $attempt\n";
			sleep( 3 );
			if ( 5 === $attempt ) {
				echo "CHUNK $ci GAVE UP DNS\n";
			}
			continue;
		}
		$data = $res->get_error_data();
		$raw  = is_array( $data ) && isset( $data['result'] ) ? (string) $data['result'] : $msg;
		echo "CHUNK $ci REJECT: " . substr( $msg, 0, 200 ) . "\n";
		if ( preg_match_all( "/Nacionalidade '([A-Z0-9]+)'/", $raw, $nm ) ) {
			foreach ( $nm[1] as $bad ) {
				$failed[ $bad ] = 'nationality';
				echo "  BAD $bad\n";
			}
		}
		foreach ( $chunk as $code ) {
			if ( isset( $failed[ $code ] ) ) {
				continue;
			}
			$one = Vila_CB\Siba_Client::submit(
				array(
					array(
						'surname'              => 'FinOne' . $code,
						'given_name'           => 'Guest',
						'nationality'          => $code,
						'date_of_birth'        => '1990-01-15',
						'document_number'      => 'Z' . substr( md5( $code . $attempt . wp_rand() ), 0, 8 ),
						'document_type'        => 'P',
						'document_country'     => $code,
						'check_in'             => '2026-07-24',
						'check_out'            => '2026-07-25',
						'country_of_residence' => $code,
					),
				)
			);
			if ( is_wp_error( $one ) ) {
				$failed[ $code ] = substr( $one->get_error_message(), 0, 120 );
				echo "  FAIL $code\n";
			} else {
				$ok[ $code ] = true;
				echo "  OK $code\n";
			}
			usleep( 250000 );
		}
		break;
	}
	usleep( 400000 );
}

update_option( Vila_CB\Guest_Registration_Exporter::OPTION_SETTINGS, is_array( $backup ) ? $backup : array() );
echo 'OK_COUNT=' . count( $ok ) . "\nFAIL_COUNT=" . count( $failed ) . "\n";
foreach ( $failed as $c => $w ) {
	echo "FAIL $c :: $w\n";
}
