<?php
/**
 * Create / cancel village stay occupancy and keep WooCommerce orders in sync.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Stay availability service.
 */
class Stay_Hold_Service {

	const LINE_META_HOLD_ID = '_vila_stay_hold_id';

	/**
	 * @var Stay_Hold_Repository
	 */
	private $repo;

	public function __construct() {
		$this->repo = new Stay_Hold_Repository();
	}

	/**
	 * Published stay posts.
	 *
	 * @return \WP_Post[]
	 */
	public static function published_stays() {
		if ( function_exists( 'vila_akoya_get_published_stays' ) ) {
			$posts = vila_akoya_get_published_stays();
			return is_array( $posts ) ? $posts : array();
		}
		$posts = get_posts(
			array(
				'post_type'      => 'stay',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);
		return is_array( $posts ) ? $posts : array();
	}

	/**
	 * @param int $product_id Product ID.
	 * @return int Stay CPT ID.
	 */
	public static function stay_id_from_product( $product_id ) {
		$product_id = (int) $product_id;
		if ( $product_id < 1 ) {
			return 0;
		}
		if ( function_exists( 'vila_akoya_stay_id_from_product_id' ) ) {
			return (int) vila_akoya_stay_id_from_product_id( $product_id );
		}
		return (int) get_post_meta( $product_id, '_vila_stay_id', true );
	}

	/**
	 * @param int $stay_id Stay CPT ID.
	 * @return int Product ID.
	 */
	public static function product_id_from_stay( $stay_id ) {
		$stay_id = (int) $stay_id;
		if ( $stay_id < 1 ) {
			return 0;
		}
		return (int) get_post_meta( $stay_id, '_vila_stay_product_id', true );
	}

	/**
	 * Occupied Y-m-d nights for a stay product (admin holds + website bookings).
	 *
	 * @param int $product_id Product ID.
	 * @return string[]
	 */
	public function unavailable_ymd_for_product( $product_id ) {
		$stay_id = self::stay_id_from_product( $product_id );
		if ( $stay_id < 1 ) {
			return array();
		}
		return $this->repo->occupied_nights_for_stay( $stay_id );
	}

	/**
	 * @param int    $stay_id   Stay ID.
	 * @param string $check_in  Y-m-d.
	 * @param string $check_out Y-m-d exclusive.
	 * @param int    $ignore_id Hold ID to ignore (edits).
	 * @return bool
	 */
	public function is_available( $stay_id, $check_in, $check_out, $ignore_id = 0 ) {
		$nights = Dates::nights_in_range( $check_in, $check_out );
		if ( empty( $nights ) ) {
			return false;
		}
		$occupied = $this->repo->occupied_nights_for_stay( (int) $stay_id );
		if ( $ignore_id > 0 ) {
			$hold = $this->repo->get( (int) $ignore_id );
			if ( $hold && (int) $hold['stay_id'] === (int) $stay_id ) {
				$own = Dates::nights_in_range( (string) $hold['check_in'], (string) $hold['check_out'] );
				$occupied = array_values( array_diff( $occupied, $own ) );
			}
		}
		return empty( array_intersect( $nights, $occupied ) );
	}

	/**
	 * Create a confirmed hold (booking or block).
	 *
	 * @param array<string, mixed> $args Args.
	 * @return array{success: bool, hold_id?: int, error?: string}
	 */
	public function create( array $args ) {
		global $wpdb;

		$stay_id    = isset( $args['stay_id'] ) ? (int) $args['stay_id'] : 0;
		$check_in   = isset( $args['check_in'] ) ? (string) $args['check_in'] : '';
		$check_out  = isset( $args['check_out'] ) ? (string) $args['check_out'] : '';
		$kind       = isset( $args['kind'] ) ? sanitize_key( (string) $args['kind'] ) : Stay_Hold_Repository::KIND_BOOKING;
		$source     = isset( $args['source'] ) ? sanitize_key( (string) $args['source'] ) : 'admin';
		$guest_name = isset( $args['guest_name'] ) ? sanitize_text_field( (string) $args['guest_name'] ) : '';
		$note       = isset( $args['note'] ) ? sanitize_text_field( (string) $args['note'] ) : '';
		$order_id   = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
		$line_id    = isset( $args['line_item_id'] ) ? (int) $args['line_item_id'] : 0;
		$product_id = isset( $args['product_id'] ) ? (int) $args['product_id'] : self::product_id_from_stay( $stay_id );

		if ( ! in_array( $kind, array( Stay_Hold_Repository::KIND_BOOKING, Stay_Hold_Repository::KIND_BLOCK ), true ) ) {
			$kind = Stay_Hold_Repository::KIND_BOOKING;
		}
		$nights = Dates::nights_in_range( $check_in, $check_out );
		if ( $stay_id < 1 || empty( $nights ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Choose a stay and a valid date range.', 'vila-campsite-booking' ),
			);
		}
		if ( ! $this->is_available( $stay_id, $check_in, $check_out ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Those dates are already booked or blocked for this stay.', 'vila-campsite-booking' ),
			);
		}

		$now = current_time( 'mysql' );
		$wpdb->query( 'START TRANSACTION' );
		$hold_id = $this->repo->insert_hold(
			array(
				'stay_id'      => $stay_id,
				'product_id'   => $product_id,
				'order_id'     => $order_id > 0 ? $order_id : null,
				'line_item_id' => $line_id > 0 ? $line_id : null,
				'kind'         => $kind,
				'source'       => $source ? $source : 'admin',
				'guest_name'   => $guest_name,
				'note'         => $note,
				'check_in'     => $check_in,
				'check_out'    => $check_out,
				'status'       => Stay_Hold_Repository::STATUS_CONFIRMED,
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
		if ( $hold_id < 1 || ! $this->repo->replace_nights( $hold_id, $stay_id, $nights ) ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'success' => false,
				'error'   => __( 'Could not save those dates. They may overlap another booking.', 'vila-campsite-booking' ),
			);
		}
		$wpdb->query( 'COMMIT' );
		$this->invalidate_product_cache( $product_id );
		return array(
			'success' => true,
			'hold_id' => $hold_id,
		);
	}

	/**
	 * @param int $hold_id Hold ID.
	 * @return bool
	 */
	public function cancel( $hold_id ) {
		$hold = $this->repo->get( (int) $hold_id );
		if ( ! $hold ) {
			return false;
		}
		$this->repo->delete_nights( (int) $hold_id );
		$this->repo->update_hold(
			(int) $hold_id,
			array(
				'status'     => Stay_Hold_Repository::STATUS_CANCELLED,
				'updated_at' => current_time( 'mysql' ),
			)
		);
		$this->invalidate_product_cache( isset( $hold['product_id'] ) ? (int) $hold['product_id'] : 0 );
		return true;
	}

	/**
	 * Cancel confirmed holds for a WooCommerce order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function release_order( $order_id ) {
		foreach ( $this->repo->get_by_order_id( (int) $order_id ) as $hold ) {
			if ( Stay_Hold_Repository::STATUS_CONFIRMED === (string) $hold['status'] ) {
				$this->cancel( (int) $hold['id'] );
			}
		}
	}

	/**
	 * Create a hold from a paid/pending stay order line.
	 *
	 * @param \WC_Order      $order Order.
	 * @param \WC_Order_Item $item  Line.
	 * @return int Hold ID or 0.
	 */
	public function sync_order_line( $order, $item ) {
		if ( ! is_object( $item ) || ! is_callable( array( $item, 'get_product_id' ) ) ) {
			return 0;
		}
		$product_id = (int) $item->get_product_id();
		$stay_id    = self::stay_id_from_product( $product_id );
		if ( $stay_id < 1 ) {
			return 0;
		}
		$existing = (int) $item->get_meta( self::LINE_META_HOLD_ID, true );
		if ( $existing > 0 ) {
			$hold = $this->repo->get( $existing );
			if ( $hold && Stay_Hold_Repository::STATUS_CONFIRMED === (string) $hold['status'] ) {
				$this->maybe_repair_hold_dates( $existing, $item );
				return $existing;
			}
		}
		$range = self::dates_from_order_item( $item );
		if ( ! $range ) {
			return 0;
		}
		$name = '';
		if ( $order instanceof \WC_Order ) {
			$name = (string) $order->get_formatted_billing_full_name();
			if ( '' === $name ) {
				$name = '#' . $order->get_order_number();
			}
		}
		$res = $this->create(
			array(
				'stay_id'      => $stay_id,
				'product_id'   => $product_id,
				'check_in'     => $range['check_in'],
				'check_out'    => $range['check_out'],
				'kind'         => Stay_Hold_Repository::KIND_BOOKING,
				'source'       => 'woocommerce',
				'guest_name'   => $name,
				'note'         => '',
				'order_id'     => $order instanceof \WC_Order ? (int) $order->get_id() : 0,
				'line_item_id' => (int) $item->get_id(),
			)
		);
		if ( empty( $res['success'] ) || empty( $res['hold_id'] ) ) {
			return 0;
		}
		$item->update_meta_data( self::LINE_META_HOLD_ID, (string) (int) $res['hold_id'] );
		$item->save();
		return (int) $res['hold_id'];
	}

	/**
	 * Import existing stay WooCommerce orders into holds (one-time / idempotent).
	 *
	 * @return int Number of holds created.
	 */
	public function backfill_from_orders() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		$created = 0;
		foreach ( self::published_stays() as $stay ) {
			$product_id = self::product_id_from_stay( (int) $stay->ID );
			if ( $product_id < 1 ) {
				continue;
			}
			if ( ! function_exists( 'vila_akoya_campsite_plot_booking_segments_for_product' ) ) {
				continue;
			}
			$segments = vila_akoya_campsite_plot_booking_segments_for_product( $product_id );
			foreach ( $segments as $seg ) {
				$order_id = isset( $seg['order_id'] ) ? (int) $seg['order_id'] : 0;
				if ( $order_id < 1 ) {
					continue;
				}
				$already = false;
				foreach ( $this->repo->get_by_order_id( $order_id ) as $hold ) {
					if ( (int) $hold['stay_id'] === (int) $stay->ID && Stay_Hold_Repository::STATUS_CONFIRMED === (string) $hold['status'] ) {
						$already = true;
						break;
					}
				}
				if ( $already || empty( $seg['nights'] ) || ! is_array( $seg['nights'] ) ) {
					continue;
				}
				$nights = array_values( $seg['nights'] );
				sort( $nights );
				$check_in  = (string) $nights[0];
				$last      = new \DateTimeImmutable( end( $nights ) . ' 00:00:00', wp_timezone() );
				$check_out = $last->modify( '+1 day' )->format( 'Y-m-d' );
				$res       = $this->create(
					array(
						'stay_id'    => (int) $stay->ID,
						'product_id' => $product_id,
						'check_in'   => $check_in,
						'check_out'  => $check_out,
						'kind'       => Stay_Hold_Repository::KIND_BOOKING,
						'source'     => 'woocommerce',
						'guest_name' => isset( $seg['label'] ) ? (string) $seg['label'] : '',
						'order_id'   => $order_id,
					)
				);
				if ( ! empty( $res['success'] ) ) {
					++$created;
				}
			}
		}
		return $created;
	}

	/**
	 * @param int            $hold_id Hold ID.
	 * @param \WC_Order_Item $item    Line item.
	 * @return void
	 */
	private function maybe_repair_hold_dates( $hold_id, $item ) {
		$hold_id = (int) $hold_id;
		$hold    = $this->repo->get( $hold_id );
		if ( ! $hold ) {
			return;
		}
		if ( ! empty( Dates::nights_in_range( (string) $hold['check_in'], (string) $hold['check_out'] ) ) ) {
			return;
		}
		$range = self::dates_from_order_item( $item );
		if ( ! $range ) {
			$nights = $this->repo->nights_by_hold_ids( array( $hold_id ) );
			$range  = isset( $nights[ $hold_id ] ) ? Dates::bounds_from_nights( $nights[ $hold_id ] ) : null;
		}
		if ( ! $range ) {
			return;
		}
		$this->repo->update_hold(
			$hold_id,
			array(
				'check_in'   => $range['check_in'],
				'check_out'  => $range['check_out'],
				'updated_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * @param mixed $item Order item.
	 * @return array{check_in: string, check_out: string}|null
	 */
	public static function dates_from_order_item( $item ) {
		if ( ! is_object( $item ) || ! is_callable( array( $item, 'get_meta' ) ) ) {
			return null;
		}
		$from = (string) $item->get_meta( '_mwb_bfwp_date_time_from', true );
		$to   = (string) $item->get_meta( '_mwb_bfwp_date_time_to', true );
		if ( '' === $from || '' === $to ) {
			$from = (string) $item->get_meta( '_wps_single_cal_date_time_from', true );
			$to   = (string) $item->get_meta( '_wps_single_cal_date_time_to', true );
		}
		if ( '' === $from || '' === $to ) {
			$from = (string) $item->get_meta( 'From', true );
			$to   = (string) $item->get_meta( 'To', true );
		}
		$cin  = Dates::to_ymd( $from );
		$cout = Dates::to_ymd( $to );
		if ( '' === $cin || '' === $cout || empty( Dates::nights_in_range( $cin, $cout ) ) ) {
			return null;
		}
		return array(
			'check_in'  => $cin,
			'check_out' => $cout,
		);
	}

	/**
	 * Read stay dates from the add-to-cart request (MWB dual calendar).
	 *
	 * @return array{check_in: string, check_out: string}|null
	 */
	public static function dates_from_request() {
		$from = '';
		$to   = '';
		$keys = array(
			array( 'mwb-mbfw-booking-from-time', 'mwb-mbfw-booking-to-time' ),
			array( 'mwb_mbfw_booking_from_time', 'mwb_mbfw_booking_to_time' ),
		);
		foreach ( $keys as $pair ) {
			$f = isset( $_POST[ $pair[0] ] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST[ $pair[0] ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$t = isset( $_POST[ $pair[1] ] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST[ $pair[1] ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( '' !== $f && '' !== $t ) {
				$from = $f;
				$to   = $t;
				break;
			}
		}
		$cin  = Dates::to_ymd( $from );
		$cout = Dates::to_ymd( $to );
		if ( '' === $cin || '' === $cout ) {
			return null;
		}
		return array(
			'check_in'  => $cin,
			'check_out' => $cout,
		);
	}

	/**
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private function invalidate_product_cache( $product_id ) {
		$product_id = (int) $product_id;
		if ( $product_id > 0 ) {
			delete_transient( 'vila_plot_unavail_' . $product_id );
		}
		if ( function_exists( 'vila_akoya_invalidate_campsite_plot_availability_caches' ) ) {
			vila_akoya_invalidate_campsite_plot_availability_caches();
		}
	}
}
