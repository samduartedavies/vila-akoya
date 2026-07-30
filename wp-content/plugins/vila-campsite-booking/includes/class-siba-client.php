<?php
/**
 * SIBA Web Service client (EntregaBoletinsAlojamento).
 *
 * @package Vila_Campsite_Booking
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Builds BAL XML and submits via SOAP to SIBA.
 */
class Siba_Client {

	const NS_BAWS = 'http://sef.pt/BAws';
	const NS_SOAP = 'http://sef.pt/';

	const URL_DEV  = 'https://siba.ssi.gov.pt/bawsdev/boletinsalojamento.asmx';
	const URL_PROD = 'https://siba.ssi.gov.pt/baws/boletinsalojamento.asmx';

	const URL_DEV_ALT  = 'https://siba.sef.pt/bawsdev/boletinsalojamento.asmx';
	const URL_PROD_ALT = 'https://siba.sef.pt/baws/boletinsalojamento.asmx';

	/**
	 * Build MovimentoBAL XML for the given person export rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Non-Portuguese guest rows.
	 * @param int|null                         $seq Optional file sequence (null = next).
	 * @return string|\WP_Error
	 */
	public static function build_xml( array $rows, $seq = null ) {
		$settings = Guest_Registration_Exporter::settings();
		$missing  = self::missing_required_settings( $settings );
		if ( ! empty( $missing ) ) {
			return new \WP_Error(
				'missing_siba_settings',
				sprintf(
					/* translators: %s: comma-separated setting names */
					__( 'Complete SIBA settings before submitting: %s', 'vila-campsite-booking' ),
					implode( ', ', $missing )
				)
			);
		}
		if ( empty( $rows ) ) {
			return new \WP_Error( 'no_siba_rows', __( 'There are no non-Portuguese guest rows to submit.', 'vila-campsite-booking' ) );
		}

		foreach ( $rows as $i => $row ) {
			$err = self::validate_person_row( $row, (int) $i + 1 );
			if ( is_wp_error( $err ) ) {
				return $err;
			}
		}

		if ( null === $seq ) {
			$seq = max( 1, (int) get_option( Guest_Registration_Exporter::OPTION_SEQ, 1 ) );
		}

		$postal = preg_replace( '/[^0-9]/', '', (string) $settings['postal_code'] );
		$postal = str_pad( (string) $postal, 7, '0', STR_PAD_RIGHT );
		$estab  = self::establishment_xml( $settings['establishment'] );

		$dom               = new \DOMDocument( '1.0', 'UTF-8' );
		$dom->formatOutput = true;
		$root              = $dom->createElementNS( self::NS_BAWS, 'MovimentoBAL' );
		$dom->appendChild( $root );

		$unit = $dom->createElementNS( self::NS_BAWS, 'Unidade_Hoteleira' );
		self::append_text( $dom, $unit, 'Codigo_Unidade_Hoteleira', self::clip( preg_replace( '/[^0-9]/', '', $settings['nif'] ), 9 ) );
		self::append_text( $dom, $unit, 'Estabelecimento', $estab );
		self::append_text( $dom, $unit, 'Nome', self::clip( self::alpha_field( $settings['unit_name'] ), 40 ) );
		self::append_text( $dom, $unit, 'Abreviatura', self::clip( self::alpha_field( $settings['abbreviation'] ), 15 ) );
		self::append_text( $dom, $unit, 'Morada', self::clip( self::alpha_field( $settings['address'] ), 40 ) );
		self::append_text( $dom, $unit, 'Localidade', self::clip( self::alpha_field( $settings['locality'] ), 30 ) );
		self::append_text( $dom, $unit, 'Codigo_Postal', substr( $postal, 0, 4 ) );
		self::append_text( $dom, $unit, 'Zona_Postal', substr( $postal, 4, 3 ) );
		self::append_text( $dom, $unit, 'Telefone', self::clip( preg_replace( '/[^0-9]/', '', $settings['phone'] ), 10 ) );
		self::append_text( $dom, $unit, 'Fax', self::clip( preg_replace( '/[^0-9]/', '', $settings['fax'] ), 10 ) );
		self::append_text( $dom, $unit, 'Nome_Contacto', self::clip( self::alpha_field( $settings['contact_name'] ), 40 ) );
		if ( '' !== trim( (string) $settings['contact_email'] ) ) {
			self::append_text( $dom, $unit, 'Email_Contacto', self::clip( (string) $settings['contact_email'], 140 ) );
		}
		$root->appendChild( $unit );

		foreach ( $rows as $row ) {
			$boletim = $dom->createElementNS( self::NS_BAWS, 'Boletim_Alojamento' );
			$surname = self::name_field( isset( $row['surname'] ) ? (string) $row['surname'] : '' );
			$given   = self::name_field( isset( $row['given_name'] ) ? (string) $row['given_name'] : '' );
			if ( '' === $surname && '' !== $given ) {
				$surname = $given;
				$given   = '';
			}
			self::append_text( $dom, $boletim, 'Apelido', self::clip( $surname, 40 ) );
			if ( '' !== $given ) {
				self::append_text( $dom, $boletim, 'Nome', self::clip( $given, 40 ) );
			}
			self::append_text( $dom, $boletim, 'Nacionalidade', self::clip( self::icao( isset( $row['nationality'] ) ? (string) $row['nationality'] : '' ), 3 ) );
			self::append_text( $dom, $boletim, 'Data_Nascimento', self::datetime_xml( isset( $row['date_of_birth'] ) ? (string) $row['date_of_birth'] : '' ) );
			self::append_text( $dom, $boletim, 'Documento_Identificacao', self::clip( self::doc_number( isset( $row['document_number'] ) ? (string) $row['document_number'] : '' ), 16 ) );
			self::append_text( $dom, $boletim, 'Pais_Emissor_Documento', self::clip( self::icao( isset( $row['document_country'] ) ? (string) $row['document_country'] : '' ), 3 ) );
			self::append_text( $dom, $boletim, 'Tipo_Documento', self::clip( self::doc_type( isset( $row['document_type'] ) ? (string) $row['document_type'] : '' ), 3 ) );
			self::append_text( $dom, $boletim, 'Data_Entrada', self::datetime_xml( isset( $row['check_in'] ) ? (string) $row['check_in'] : '' ) );
			$checkout = isset( $row['check_out'] ) ? (string) $row['check_out'] : '';
			if ( '' !== $checkout ) {
				self::append_text( $dom, $boletim, 'Data_Saida', self::datetime_xml( $checkout ) );
			}
			self::append_text( $dom, $boletim, 'Pais_Residencia_Origem', self::clip( self::icao( isset( $row['country_of_residence'] ) ? (string) $row['country_of_residence'] : '' ), 3 ) );
			$local = isset( $row['place_of_residence'] ) ? self::alpha_field( (string) $row['place_of_residence'] ) : '';
			self::append_text( $dom, $boletim, 'Local_Residencia_Origem', self::clip( '' !== $local ? $local : 'NA', 30 ) );
			$root->appendChild( $boletim );
		}

		$envio = $dom->createElementNS( self::NS_BAWS, 'Envio' );
		self::append_text( $dom, $envio, 'Numero_Ficheiro', (string) (int) $seq );
		self::append_text( $dom, $envio, 'Data_Movimento', gmdate( 'Y-m-d\T00:00:00', current_time( 'timestamp' ) ) );
		$root->appendChild( $envio );

		$xml = $dom->saveXML();
		if ( false === $xml || '' === $xml ) {
			return new \WP_Error( 'xml_build_failed', __( 'Could not build the SIBA XML payload.', 'vila-campsite-booking' ) );
		}
		return $xml;
	}

	/**
	 * Submit person rows to SIBA via Web Service.
	 *
	 * @param array<int, array<string, mixed>> $rows Export rows.
	 * @return array{ok:bool,result:string,xml:string,endpoint:string,file_number:int}|\WP_Error
	 */
	public static function submit( array $rows ) {
		$settings = Guest_Registration_Exporter::settings();
		$seq      = max( 1, (int) get_option( Guest_Registration_Exporter::OPTION_SEQ, 1 ) );
		$xml      = self::build_xml( $rows, $seq );
		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		$endpoints = self::endpoints( $settings['environment'] );
		$b64       = base64_encode( $xml );
		$unidade   = preg_replace( '/[^0-9]/', '', $settings['nif'] );
		$estab     = (int) preg_replace( '/[^0-9]/', '', $settings['establishment'] );
		$chave     = preg_replace( '/[^0-9]/', '', $settings['access_key'] );

		$result   = null;
		$endpoint = $endpoints[0];
		$last_err = null;
		foreach ( $endpoints as $candidate ) {
			$endpoint = $candidate;
			$result   = self::soap_entrega( $candidate, $unidade, $estab, $chave, $b64 );
			if ( ! is_wp_error( $result ) ) {
				$last_err = null;
				break;
			}
			$last_err = $result;
			// Retry alternate host on DNS / connect failures only.
			$code = $result->get_error_code();
			if ( ! in_array( $code, array( 'siba_http', 'siba_http_status' ), true ) ) {
				break;
			}
		}
		if ( is_wp_error( $last_err ) ) {
			return $last_err;
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$parsed = self::parse_result( $result );
		if ( ! $parsed['ok'] ) {
			return new \WP_Error(
				'siba_rejected',
				$parsed['message'],
				array(
					'result'   => $result,
					'xml'      => $xml,
					'endpoint' => $endpoint,
				)
			);
		}

		update_option( Guest_Registration_Exporter::OPTION_SEQ, $seq + 1 );

		return array(
			'ok'          => true,
			'result'      => $result,
			'xml'         => $xml,
			'endpoint'    => $endpoint,
			'file_number' => $seq,
		);
	}

	/**
	 * @param string $environment "dev" or "prod".
	 * @return string[]
	 */
	public static function endpoints( $environment ) {
		if ( 'prod' === $environment ) {
			// SSI is canonical; SEF host still serves production when SSI DNS flakes.
			return array( self::URL_PROD, self::URL_PROD_ALT );
		}
		// Dev exists only on ssi.gov.pt (sef.pt/bawsdev returns 404).
		return array( self::URL_DEV );
	}

	/**
	 * @param string $environment "dev" or "prod".
	 * @return string
	 */
	public static function endpoint( $environment ) {
		$endpoints = self::endpoints( $environment );
		return $endpoints[0];
	}

	/**
	 * @param array<string, string> $settings Settings.
	 * @return string[]
	 */
	public static function missing_required_settings( array $settings ) {
		$missing = array();
		if ( '' === preg_replace( '/[^0-9]/', '', (string) $settings['nif'] ) ) {
			$missing[] = 'NIF';
		}
		if ( ! array_key_exists( 'establishment', $settings ) || null === $settings['establishment'] ) {
			$missing[] = 'Establishment';
		}
		if ( '' === preg_replace( '/[^0-9]/', '', (string) $settings['access_key'] ) ) {
			$missing[] = 'Access key';
		}
		if ( '' === trim( (string) $settings['abbreviation'] ) ) {
			$missing[] = 'Abbreviation';
		}
		if ( '' === trim( (string) $settings['unit_name'] ) ) {
			$missing[] = 'Unit name';
		}
		if ( '' === trim( (string) $settings['address'] ) ) {
			$missing[] = 'Address';
		}
		if ( '' === trim( (string) $settings['locality'] ) ) {
			$missing[] = 'Locality';
		}
		if ( '' === preg_replace( '/[^0-9]/', '', (string) $settings['postal_code'] ) ) {
			$missing[] = 'Postal code';
		}
		if ( '' === trim( (string) $settings['contact_name'] ) ) {
			$missing[] = 'Contact name';
		}
		return $missing;
	}

	/**
	 * @param string $endpoint SOAP endpoint URL.
	 * @param string $unidade NIF.
	 * @param int    $estabelecimento Establishment number.
	 * @param string $chave Access key.
	 * @param string $boletins_b64 Base64 XML.
	 * @return string|\WP_Error Raw EntregaBoletinsAlojamentoResult.
	 */
	private static function soap_entrega( $endpoint, $unidade, $estabelecimento, $chave, $boletins_b64 ) {
		$xml_esc = static function ( $value ) {
			return htmlspecialchars( (string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		};

		$body = '<?xml version="1.0" encoding="utf-8"?>'
			. '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
			. '<soap:Body>'
			. '<EntregaBoletinsAlojamento xmlns="' . self::NS_SOAP . '">'
			. '<UnidadeHoteleira>' . $xml_esc( $unidade ) . '</UnidadeHoteleira>'
			. '<Estabelecimento>' . (int) $estabelecimento . '</Estabelecimento>'
			. '<ChaveAcesso>' . $xml_esc( $chave ) . '</ChaveAcesso>'
			. '<Boletins>' . $xml_esc( $boletins_b64 ) . '</Boletins>'
			. '</EntregaBoletinsAlojamento>'
			. '</soap:Body>'
			. '</soap:Envelope>';

		$response = null;
		$last_err = null;
		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout'   => 60,
					'sslverify' => true,
					'headers'   => array(
						'Content-Type' => 'text/xml; charset=utf-8',
						'SOAPAction'   => '"' . self::NS_SOAP . 'EntregaBoletinsAlojamento"',
					),
					'body'      => $body,
				)
			);

			if ( is_wp_error( $response ) ) {
				$last_err = $response;
				$msg      = $response->get_error_message();
				if ( false !== stripos( $msg, 'SSL' ) || false !== stripos( $msg, 'certificate' ) ) {
					$response = wp_remote_post(
						$endpoint,
						array(
							'timeout'   => 60,
							'sslverify' => false,
							'headers'   => array(
								'Content-Type' => 'text/xml; charset=utf-8',
								'SOAPAction'   => '"' . self::NS_SOAP . 'EntregaBoletinsAlojamento"',
							),
							'body'      => $body,
						)
					);
					if ( ! is_wp_error( $response ) ) {
						break;
					}
					$last_err = $response;
					$msg      = $response->get_error_message();
				}
				if ( false !== stripos( $msg, 'resolve host' ) || false !== stripos( $msg, 'Could not resolve' ) || false !== stripos( $msg, 'Failed to connect' ) ) {
					usleep( 500000 * $attempt );
					continue;
				}
				break;
			}
			break;
		}
		if ( is_wp_error( $response ) && $last_err instanceof \WP_Error ) {
			$response = $last_err;
		}

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'siba_http',
				sprintf(
					/* translators: %s: error message */
					__( 'SIBA request failed: %s', 'vila-campsite-booking' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'siba_http_status',
				sprintf(
					/* translators: 1: HTTP status, 2: response snippet */
					__( 'SIBA returned HTTP %1$d: %2$s', 'vila-campsite-booking' ),
					$code,
					self::clip( wp_strip_all_tags( $raw ), 300 )
				)
			);
		}

		if ( ! preg_match( '/<EntregaBoletinsAlojamentoResult[^>]*>(.*?)<\/EntregaBoletinsAlojamentoResult>/s', $raw, $m ) ) {
			return new \WP_Error(
				'siba_bad_response',
				sprintf(
					/* translators: %s: response snippet */
					__( 'Unexpected SIBA response: %s', 'vila-campsite-booking' ),
					self::clip( wp_strip_all_tags( $raw ), 300 )
				)
			);
		}

		return html_entity_decode( (string) $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * @param string $result Raw result string.
	 * @return array{ok:bool,message:string,code:string}
	 */
	private static function parse_result( $result ) {
		$result = trim( (string) $result );
		if ( '0' === $result ) {
			return array(
				'ok'      => true,
				'message' => __( 'SIBA accepted the bulletins.', 'vila-campsite-booking' ),
				'code'    => '0',
			);
		}

		$code = '';
		$desc = $result;
		if ( preg_match( '/<Codigo_Retorno[^>]*>(.*?)<\/Codigo_Retorno>/s', $result, $m ) ) {
			$code = trim( html_entity_decode( (string) $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
		}
		if ( preg_match( '/<Descricao[^>]*>(.*?)<\/Descricao>/s', $result, $m ) ) {
			$desc = trim( html_entity_decode( (string) $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
		}

		$message = '' !== $code
			? sprintf(
				/* translators: 1: SIBA error code, 2: description */
				__( 'SIBA rejected the submission (%1$s): %2$s', 'vila-campsite-booking' ),
				$code,
				$desc
			)
			: sprintf(
				/* translators: %s: raw result */
				__( 'SIBA rejected the submission: %s', 'vila-campsite-booking' ),
				self::clip( $desc, 400 )
			);

		return array(
			'ok'      => false,
			'message' => $message,
			'code'    => $code,
		);
	}

	/**
	 * @param array<string, mixed> $row Person row.
	 * @param int                  $n Human index.
	 * @return true|\WP_Error
	 */
	private static function validate_person_row( array $row, $n ) {
		$surname = isset( $row['surname'] ) ? trim( (string) $row['surname'] ) : '';
		$given   = isset( $row['given_name'] ) ? trim( (string) $row['given_name'] ) : '';
		if ( '' === $surname && '' === $given ) {
			return new \WP_Error( 'invalid_person', sprintf( __( 'Guest %d is missing a name.', 'vila-campsite-booking' ), $n ) );
		}
		foreach (
			array(
				'nationality'          => __( 'nationality', 'vila-campsite-booking' ),
				'date_of_birth'        => __( 'date of birth', 'vila-campsite-booking' ),
				'document_number'      => __( 'document number', 'vila-campsite-booking' ),
				'document_type'        => __( 'document type', 'vila-campsite-booking' ),
				'document_country'     => __( 'document country', 'vila-campsite-booking' ),
				'check_in'             => __( 'check-in', 'vila-campsite-booking' ),
				'country_of_residence' => __( 'country of residence', 'vila-campsite-booking' ),
			) as $key => $label
		) {
			if ( empty( $row[ $key ] ) || '' === trim( (string) $row[ $key ] ) ) {
				return new \WP_Error(
					'invalid_person',
					sprintf(
						/* translators: 1: guest number, 2: field label */
						__( 'Guest %1$d is missing %2$s.', 'vila-campsite-booking' ),
						$n,
						$label
					)
				);
			}
		}
		return true;
	}

	/**
	 * @param \DOMDocument $dom DOM.
	 * @param \DOMElement  $parent Parent.
	 * @param string       $name Local name.
	 * @param string       $value Text.
	 */
	private static function append_text( \DOMDocument $dom, \DOMElement $parent, $name, $value ) {
		$el = $dom->createElementNS( self::NS_BAWS, $name );
		$el->appendChild( $dom->createTextNode( (string) $value ) );
		$parent->appendChild( $el );
	}

	/**
	 * @param string $establishment Raw establishment.
	 * @return string Max 2 chars for XML.
	 */
	private static function establishment_xml( $establishment ) {
		$digits = preg_replace( '/[^0-9]/', '', (string) $establishment );
		if ( '' === $digits ) {
			$digits = '0';
		}
		return self::clip( $digits, 2 );
	}

	/**
	 * @param string $ymd Y-m-d or datetime.
	 * @return string
	 */
	private static function datetime_xml( $ymd ) {
		$ymd = trim( (string) $ymd );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $ymd, $m ) ) {
			return $m[0] . 'T00:00:00';
		}
		$dt = Dates::parse_ymd( $ymd );
		return $dt ? $dt->format( 'Y-m-d\T00:00:00' ) : '1900-01-01T00:00:00';
	}

	/**
	 * @param string $value Raw.
	 * @return string
	 */
	private static function alpha_field( $value ) {
		$value = remove_accents( strtoupper( (string) $value ) );
		$value = preg_replace( "/[^A-Z0-9 @._,\\-'\\\"]/", ' ', $value );
		$value = preg_replace( '/\s+/', ' ', trim( (string) $value ) );
		return (string) $value;
	}

	/**
	 * @param string $value Raw name.
	 * @return string
	 */
	private static function name_field( $value ) {
		$value = remove_accents( strtoupper( (string) $value ) );
		$value = preg_replace( "/[^A-ZÇÃÁÀÉÊÍÕÔÓÚ'\\- ]/u", '', $value );
		$value = preg_replace( '/\s+/', ' ', trim( (string) $value ) );
		return (string) $value;
	}

	/**
	 * Normalize ICAO/nationality code for SIBA (legacy exceptions).
	 *
	 * @param string $value ICAO code.
	 * @return string
	 */
	private static function icao( $value ) {
		$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $value ) );
		$map  = array(
			'DEU' => 'D',
			'ROU' => 'ROM',
		);
		return isset( $map[ $code ] ) ? $map[ $code ] : $code;
	}

	/**
	 * @param string $value Document number.
	 * @return string
	 */
	private static function doc_number( $value ) {
		return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $value ) );
	}

	/**
	 * @param string $value Document type.
	 * @return string
	 */
	private static function doc_type( $value ) {
		$value = strtoupper( substr( trim( (string) $value ), 0, 1 ) );
		return in_array( $value, array( 'P', 'B', 'O' ), true ) ? $value : 'O';
	}

	/**
	 * @param string $value Value.
	 * @param int    $len Max length.
	 * @return string
	 */
	private static function clip( $value, $len ) {
		$value = (string) $value;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, (int) $len );
		}
		return substr( $value, 0, (int) $len );
	}
}
