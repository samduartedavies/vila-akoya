<?php
/**
 * Guest registrations for SIBA export.
 *
 * @package Vila_Campsite_Booking
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Storage and lookup helpers for legal guest registrations.
 */
class Guest_Registration_Repository {

	/**
	 * @param string $value Raw country code.
	 * @return string
	 */
	public static function country_code( $value ) {
		$value = strtoupper( preg_replace( '/[^A-Z]/', '', (string) $value ) );
		$value = substr( $value, 0, 3 );
		$map   = array(
			'DEU' => 'D',
			'ROU' => 'ROM',
		);
		return isset( $map[ $value ] ) ? $map[ $value ] : $value;
	}

	/**
	 * @param string $value Raw date.
	 * @return string
	 */
	public static function ymd_or_empty( $value ) {
		$dt = Dates::parse_ymd( (string) $value );
		return $dt ? $dt->format( 'Y-m-d' ) : '';
	}

	/**
	 * @param string $value Raw date of birth.
	 * @return string
	 */
	public static function birth_ymd_or_empty( $value ) {
		$dt = Dates::parse_birth_ymd( (string) $value );
		return $dt ? $dt->format( 'Y-m-d' ) : '';
	}

	/**
	 * Whether legal guest details exist for a website booking or order.
	 *
	 * @param int $booking_id Campsite booking ID (0 if N/A).
	 * @param int $order_id   WooCommerce order ID (0 if N/A).
	 * @return bool
	 */
	public function has_registration_for_booking_or_order( $booking_id, $order_id ) {
		$booking_id = (int) $booking_id;
		$order_id   = (int) $order_id;
		if ( $booking_id > 0 && $this->get_by_booking_id( $booking_id ) ) {
			return true;
		}
		if ( $order_id < 1 ) {
			return false;
		}
		global $wpdb;
		$t = Database::guest_registrations_table();
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE order_id = %d LIMIT 1", $order_id ) );
		return $id > 0;
	}

	/**
	 * @param int $booking_id Booking ID.
	 * @return array<string, mixed>|null
	 */
	public function get_by_booking_id( $booking_id ) {
		global $wpdb;
		$t   = Database::guest_registrations_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE booking_id = %d ORDER BY id DESC LIMIT 1", (int) $booking_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Map booking_id => registration_id for bookings that have submitted guest details.
	 *
	 * @param array<int, int|string> $booking_ids Booking IDs.
	 * @return array<int, int>
	 */
	public function get_registration_ids_by_booking_ids( array $booking_ids ) {
		global $wpdb;
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $booking_ids )
				)
			)
		);
		if ( empty( $ids ) ) {
			return array();
		}
		$t    = Database::guest_registrations_table();
		$ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql  = "SELECT booking_id, MAX(id) AS registration_id FROM $t WHERE booking_id IN ($ph) GROUP BY booking_id";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A );
		$out  = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$bid = (int) $row['booking_id'];
				$rid = (int) $row['registration_id'];
				if ( $bid > 0 && $rid > 0 ) {
					$out[ $bid ] = $rid;
				}
			}
		}
		return $out;
	}

	/**
	 * @param int $id Registration ID.
	 * @return array<string, mixed>|null
	 */
	public function get( $id ) {
		global $wpdb;
		$t   = Database::guest_registrations_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", (int) $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param int $registration_id Registration ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_persons( $registration_id ) {
		global $wpdb;
		$t    = Database::guest_persons_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $t WHERE registration_id = %d ORDER BY id ASC", (int) $registration_id ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param int $id Registration ID.
	 * @return array{registration: array<string, mixed>, persons: array<int, array<string, mixed>>}|null
	 */
	public function get_with_persons( $id ) {
		$reg = $this->get( $id );
		if ( ! $reg ) {
			return null;
		}
		return array(
			'registration' => $reg,
			'persons'      => $this->get_persons( (int) $reg['id'] ),
		);
	}

	/**
	 * @param array<string, mixed>              $registration Registration values.
	 * @param array<int, array<string, mixed>> $persons Persons.
	 * @return int|\WP_Error
	 */
	public function create_or_replace( array $registration, array $persons ) {
		$registration = $this->normalize_registration( $registration );
		$persons      = $this->normalize_persons( $persons );

		if ( is_wp_error( $registration ) ) {
			return $registration;
		}
		if ( is_wp_error( $persons ) ) {
			return $persons;
		}

		if ( ! empty( $registration['booking_id'] ) ) {
			$existing = $this->get_by_booking_id( (int) $registration['booking_id'] );
			if ( $existing ) {
				$ok = $this->update( (int) $existing['id'], $registration, $persons );
				return $ok ? (int) $existing['id'] : new \WP_Error( 'save_failed', __( 'Could not update the guest registration.', 'vila-campsite-booking' ), array( 'status' => 500 ) );
			}
		}

		return $this->insert( $registration, $persons );
	}

	/**
	 * @param array<string, mixed>              $registration Registration values.
	 * @param array<int, array<string, mixed>> $persons Persons.
	 * @return int|\WP_Error
	 */
	public function insert( array $registration, array $persons ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$reg = array_merge(
			$registration,
			array(
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$ok = $wpdb->insert( Database::guest_registrations_table(), $reg );
		if ( false === $ok ) {
			return new \WP_Error( 'save_failed', __( 'Could not save the guest registration.', 'vila-campsite-booking' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		$this->replace_persons( $id, $persons );
		$this->refresh_status( $id );
		return $id;
	}

	/**
	 * @param int                               $id Registration ID.
	 * @param array<string, mixed>              $registration Registration values.
	 * @param array<int, array<string, mixed>> $persons Persons.
	 * @return bool
	 */
	public function update( $id, array $registration, array $persons ) {
		global $wpdb;
		$registration['updated_at'] = current_time( 'mysql' );
		$ok                         = false !== $wpdb->update( Database::guest_registrations_table(), $registration, array( 'id' => (int) $id ) );
		if ( ! $ok ) {
			return false;
		}
		$this->replace_persons( (int) $id, $persons );
		$this->refresh_status( (int) $id );
		return true;
	}

	/**
	 * @param int                               $id Registration ID.
	 * @param array<string, mixed>              $registration Registration values.
	 * @param array<int, array<string, mixed>> $persons Persons.
	 * @return bool|\WP_Error
	 */
	public function update_normalized( $id, array $registration, array $persons ) {
		$registration = $this->normalize_registration( $registration );
		$persons      = $this->normalize_persons( $persons );
		if ( is_wp_error( $registration ) ) {
			return $registration;
		}
		if ( is_wp_error( $persons ) ) {
			return $persons;
		}
		return $this->update( (int) $id, $registration, $persons );
	}

	/**
	 * @param int                               $registration_id Registration ID.
	 * @param array<int, array<string, mixed>> $persons Persons.
	 * @return void
	 */
	private function replace_persons( $registration_id, array $persons ) {
		global $wpdb;
		$t   = Database::guest_persons_table();
		$now = current_time( 'mysql' );
		$wpdb->delete( $t, array( 'registration_id' => (int) $registration_id ) );
		foreach ( $persons as $person ) {
			$wpdb->insert(
				$t,
				array_merge(
					$person,
					array(
						'registration_id' => (int) $registration_id,
						'created_at'       => $now,
						'updated_at'       => $now,
					)
				)
			);
		}
	}

	/**
	 * @param int $registration_id Registration ID.
	 * @return void
	 */
	public function refresh_status( $registration_id ) {
		global $wpdb;
		$persons = $this->get_persons( (int) $registration_id );
		if ( empty( $persons ) ) {
			return;
		}
		$has_siba = false;
		foreach ( $persons as $person ) {
			if ( empty( $person['skip_siba'] ) ) {
				$has_siba = true;
				break;
			}
		}
		$reg = $this->get( (int) $registration_id );
		if ( ! $reg || 'exported' === (string) $reg['siba_status'] ) {
			return;
		}
		$wpdb->update(
			Database::guest_registrations_table(),
			array(
				'siba_status' => $has_siba ? 'pending' : 'not_required',
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => (int) $registration_id )
		);
	}

	/**
	 * @param int    $registration_id Registration ID.
	 * @param string $status Status.
	 * @return bool
	 */
	public function update_status( $registration_id, $status ) {
		global $wpdb;
		$status = in_array( $status, array( 'pending', 'exported', 'not_required' ), true ) ? $status : 'pending';
		return false !== $wpdb->update(
			Database::guest_registrations_table(),
			array(
				'siba_status' => $status,
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => (int) $registration_id )
		);
	}

	/**
	 * @param array<string, mixed> $args Filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_registrations( array $args = array() ) {
		global $wpdb;
		$t     = Database::guest_registrations_table();
		$where = array( '1=1' );
		$vals  = array();

		if ( ! empty( $args['from'] ) ) {
			$where[] = 'check_out >= %s';
			$vals[]  = self::ymd_or_empty( (string) $args['from'] );
		}
		if ( ! empty( $args['to'] ) ) {
			$where[] = 'check_in <= %s';
			$vals[]  = self::ymd_or_empty( (string) $args['to'] );
		}
		if ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) {
			$where[] = 'siba_status = %s';
			$vals[]  = sanitize_key( (string) $args['status'] );
		}
		if ( ! empty( $args['s'] ) ) {
			$like    = '%' . $wpdb->esc_like( sanitize_text_field( (string) $args['s'] ) ) . '%';
			$where[] = '(booker_name LIKE %s OR contact_email LIKE %s)';
			$vals[]  = $like;
			$vals[]  = $like;
		}

		$sql = "SELECT * FROM $t WHERE " . implode( ' AND ', $where ) . ' ORDER BY check_in DESC, id DESC LIMIT 300';
		if ( ! empty( $vals ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built from fixed fragments.
			$sql = $wpdb->prepare( $sql, $vals );
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<string, mixed> $args Filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function export_person_rows( array $args = array() ) {
		global $wpdb;
		$r     = Database::guest_registrations_table();
		$p     = Database::guest_persons_table();
		$where = array( 'p.skip_siba = 0' );
		$vals  = array();

		if ( ! empty( $args['ids'] ) && is_array( $args['ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'absint', $args['ids'] ) ) );
			if ( ! empty( $ids ) ) {
				$where[] = 'r.id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
				$vals    = array_merge( $vals, $ids );
			}
		}
		if ( ! empty( $args['from'] ) ) {
			$where[] = 'r.check_out >= %s';
			$vals[]  = self::ymd_or_empty( (string) $args['from'] );
		}
		if ( ! empty( $args['to'] ) ) {
			$where[] = 'r.check_in <= %s';
			$vals[]  = self::ymd_or_empty( (string) $args['to'] );
		}
		if ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) {
			$where[] = 'r.siba_status = %s';
			$vals[]  = sanitize_key( (string) $args['status'] );
		}

		$sql = "SELECT r.id AS registration_id, r.booking_id, r.order_id, r.check_in, r.check_out, r.booker_name, r.contact_email, r.contact_phone, r.stay_type, r.siba_status, p.* FROM $p p INNER JOIN $r r ON r.id = p.registration_id WHERE " . implode( ' AND ', $where ) . ' ORDER BY r.check_in ASC, r.id ASC, p.id ASC';
		if ( ! empty( $vals ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built from fixed fragments.
			$sql = $wpdb->prepare( $sql, $vals );
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param string $last_name Booker last name.
	 * @param string $email Booker email.
	 * @return array<int, array<string, mixed>>
	 */
	public function lookup_bookings( $last_name, $email ) {
		global $wpdb;
		$last_name = strtolower( trim( sanitize_text_field( (string) $last_name ) ) );
		$email     = sanitize_email( (string) $email );
		if ( '' === $last_name || '' === $email || ! is_email( $email ) ) {
			return array();
		}

		$cutoff = gmdate( 'Y-m-d', strtotime( '-7 days', current_time( 'timestamp' ) ) );
		$b      = Database::bookings_table();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $b WHERE status = %s AND check_out >= %s ORDER BY check_in ASC LIMIT 100",
				'confirmed',
				$cutoff
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $booking ) {
			$order_id = isset( $booking['order_id'] ) ? (int) $booking['order_id'] : 0;
			$order    = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$order_email = strtolower( (string) $order->get_billing_email() );
			if ( strtolower( $email ) !== $order_email ) {
				continue;
			}
			$billing_last = strtolower( trim( (string) $order->get_billing_last_name() ) );
			$full_name    = trim( (string) $order->get_formatted_billing_full_name() );
			if ( '' === $billing_last || false === strpos( $billing_last, $last_name ) ) {
				$parts        = preg_split( '/\s+/', strtolower( $full_name ) );
				$candidate_ln = is_array( $parts ) && ! empty( $parts ) ? end( $parts ) : '';
				if ( $candidate_ln !== $last_name ) {
					continue;
				}
			}

			$guests = json_decode( isset( $booking['guest_json'] ) ? (string) $booking['guest_json'] : '', true );
			$adults = is_array( $guests ) && isset( $guests['adults'] ) ? max( 1, (int) $guests['adults'] ) : 1;
			$kids   = is_array( $guests ) && isset( $guests['children'] ) ? max( 0, (int) $guests['children'] ) : 0;
			$infants = is_array( $guests ) && isset( $guests['infants'] ) ? max( 0, (int) $guests['infants'] ) : 0;

			$nights = Dates::nights_in_range( (string) $booking['check_in'], (string) $booking['check_out'] );
			$dinner_dates = array();
			$breakfast_dates = array();
			foreach ( $nights as $night ) {
				if ( Meal_Plan_Service::is_weekday( $night ) ) {
					$dinner_dates[] = $night;
				}
				$nd = Dates::parse_ymd( $night );
				if ( $nd ) {
					$breakfast_dates[] = $nd->modify( '+1 day' )->format( 'Y-m-d' );
				}
			}

			$out[] = array(
				'booking_id'       => (int) $booking['id'],
				'order_id'         => $order_id,
				'booker_name'      => $full_name,
				'check_in'         => (string) $booking['check_in'],
				'check_out'        => (string) $booking['check_out'],
				'adults'           => $adults,
				'children'         => $kids,
				'infants'          => $infants,
				'suggested_guests' => $adults + $kids,
				'nights'           => $nights,
				'dinner_dates'     => $dinner_dates,
				'breakfast_dates'  => array_values( array_unique( $breakfast_dates ) ),
			);
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $registration Raw registration.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function normalize_registration( array $registration ) {
		$check_in  = self::ymd_or_empty( isset( $registration['check_in'] ) ? (string) $registration['check_in'] : '' );
		$check_out = self::ymd_or_empty( isset( $registration['check_out'] ) ? (string) $registration['check_out'] : '' );
		if ( '' === $check_in || '' === $check_out || $check_out <= $check_in ) {
			return new \WP_Error( 'invalid_stay', __( 'Please enter valid check-in and check-out dates.', 'vila-campsite-booking' ), array( 'status' => 400 ) );
		}

		$stay_type = isset( $registration['stay_type'] ) ? sanitize_key( (string) $registration['stay_type'] ) : 'guest';
		if ( ! in_array( $stay_type, array( 'guest', 'volunteer' ), true ) ) {
			$stay_type = 'guest';
		}
		if ( ! empty( $registration['booking_id'] ) ) {
			$stay_type = 'guest';
		}

		$booker = isset( $registration['booker_name'] ) ? sanitize_text_field( (string) $registration['booker_name'] ) : '';
		$email  = isset( $registration['contact_email'] ) ? sanitize_email( (string) $registration['contact_email'] ) : '';
		$phone  = isset( $registration['contact_phone'] ) ? sanitize_text_field( (string) $registration['contact_phone'] ) : '';
		if ( empty( $registration['booking_id'] ) ) {
			if ( '' === $booker ) {
				return new \WP_Error( 'invalid_booker', __( 'Please enter a name for this stay.', 'vila-campsite-booking' ), array( 'status' => 400 ) );
			}
			if ( ! is_email( $email ) ) {
				return new \WP_Error( 'invalid_email', __( 'Please enter a valid contact email.', 'vila-campsite-booking' ), array( 'status' => 400 ) );
			}
		}

		return array(
			'booking_id'    => isset( $registration['booking_id'] ) ? max( 0, (int) $registration['booking_id'] ) : null,
			'order_id'      => isset( $registration['order_id'] ) ? max( 0, (int) $registration['order_id'] ) : null,
			'check_in'      => $check_in,
			'check_out'     => $check_out,
			'booker_name'   => $booker,
			'contact_email' => $email,
			'contact_phone' => $phone,
			'stay_type'     => $stay_type,
			'source'        => isset( $registration['source'] ) && 'booking_lookup' === $registration['source'] ? 'booking_lookup' : 'manual',
			'siba_status'   => isset( $registration['siba_status'] ) && in_array( $registration['siba_status'], array( 'pending', 'exported', 'not_required' ), true ) ? (string) $registration['siba_status'] : 'pending',
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $persons Raw persons.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private function normalize_persons( array $persons ) {
		$out = array();
		foreach ( $persons as $idx => $person ) {
			if ( ! is_array( $person ) ) {
				continue;
			}
			$skip    = ! empty( $person['skip_siba'] ) || 'PRT' === self::country_code( isset( $person['nationality'] ) ? (string) $person['nationality'] : '' );
			$raw_dob = isset( $person['date_of_birth'] ) ? trim( (string) $person['date_of_birth'] ) : '';
			$dob     = self::birth_ymd_or_empty( $raw_dob );
			$row     = array(
				'given_name'           => isset( $person['given_name'] ) ? sanitize_text_field( (string) $person['given_name'] ) : '',
				'surname'              => isset( $person['surname'] ) ? sanitize_text_field( (string) $person['surname'] ) : '',
				'nationality'          => self::country_code( isset( $person['nationality'] ) ? (string) $person['nationality'] : '' ),
				'date_of_birth'        => '' !== $dob ? $dob : null,
				'document_type'        => isset( $person['document_type'] ) ? strtoupper( sanitize_text_field( (string) $person['document_type'] ) ) : '',
				'document_number'      => isset( $person['document_number'] ) ? strtoupper( preg_replace( '/[^A-Z0-9]/', '', (string) $person['document_number'] ) ) : '',
				'document_country'     => self::country_code( isset( $person['document_country'] ) ? (string) $person['document_country'] : '' ),
				'country_of_residence' => self::country_code( isset( $person['country_of_residence'] ) ? (string) $person['country_of_residence'] : '' ),
				'skip_siba'            => $skip ? 1 : 0,
			);
			if ( '' === $row['given_name'] && '' === $row['surname'] ) {
				return new \WP_Error( 'invalid_person', sprintf( __( 'Person %d needs a name.', 'vila-campsite-booking' ), (int) $idx + 1 ), array( 'status' => 400 ) );
			}
			if ( ! $skip ) {
				if ( '' !== $raw_dob && null === $row['date_of_birth'] ) {
					return new \WP_Error( 'invalid_person', sprintf( __( 'Person %d has an invalid date of birth.', 'vila-campsite-booking' ), (int) $idx + 1 ), array( 'status' => 400 ) );
				}
				foreach ( array( 'nationality', 'date_of_birth', 'document_type', 'document_number', 'document_country', 'country_of_residence' ) as $field ) {
					if ( '' === (string) $row[ $field ] ) {
						return new \WP_Error( 'invalid_person', sprintf( __( 'Person %d is missing required SIBA information.', 'vila-campsite-booking' ), (int) $idx + 1 ), array( 'status' => 400 ) );
					}
				}
				if ( ! in_array( $row['document_type'], array( 'P', 'B', 'O' ), true ) ) {
					return new \WP_Error( 'invalid_document_type', sprintf( __( 'Person %d has an invalid document type.', 'vila-campsite-booking' ), (int) $idx + 1 ), array( 'status' => 400 ) );
				}
			}
			$out[] = $row;
		}
		if ( empty( $out ) ) {
			return new \WP_Error( 'no_persons', __( 'Please add at least one person.', 'vila-campsite-booking' ), array( 'status' => 400 ) );
		}
		return $out;
	}
}
