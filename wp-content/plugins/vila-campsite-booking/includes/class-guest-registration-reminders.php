<?php
/**
 * Daily reminders to complete legal guest registration (pre-arrival and post-departure).
 *
 * @package Vila_Campsite_Booking
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Schedules and runs guest check-in reminder emails.
 */
class Guest_Registration_Reminders {

	const CRON_HOOK = 'vila_cb_guest_reg_reminders_daily';

	const ORDER_META_PRE_SENT  = '_vila_guest_reg_reminder_pre_sent';
	const ORDER_META_POST_SENT = '_vila_guest_reg_reminder_post_sent';

	/**
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_daily' ) );
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_emails' ) );
		add_action( 'init', array( __CLASS__, 'ensure_cron_scheduled' ), 30 );
	}

	/**
	 * @return void
	 */
	public static function activate() {
		self::ensure_cron_scheduled();
	}

	/**
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * @return void
	 */
	public static function ensure_cron_scheduled() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		$tz   = wp_timezone();
		$now  = new \DateTimeImmutable( 'now', $tz );
		$next = $now->setTime( 9, 0, 0 );
		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' );
		}
		wp_schedule_event( $next->getTimestamp(), 'daily', self::CRON_HOOK );
	}

	/**
	 * @param array<string, \WC_Email> $emails Email classes.
	 * @return array<string, \WC_Email>
	 */
	public static function register_emails( $emails ) {
		if ( ! class_exists( 'WC_Email', false ) ) {
			return $emails;
		}
		require_once VILA_CB_DIR . 'includes/class-wc-email-guest-registration-reminder.php';
		$emails['vila_guest_reg_reminder_pre']  = new WC_Email_Guest_Reg_Reminder_Pre();
		$emails['vila_guest_reg_reminder_post'] = new WC_Email_Guest_Reg_Reminder_Post();
		return $emails;
	}

	/**
	 * Public URL for the guest registration form.
	 *
	 * @return string
	 */
	public static function registration_page_url() {
		if ( function_exists( 'vila_akoya_guest_registration_url' ) ) {
			$url = vila_akoya_guest_registration_url();
			return is_string( $url ) ? $url : '';
		}
		$page_id = (int) get_option( Install::OPTION_GUEST_REGISTRATION_PAGE_ID, 0 );
		if ( $page_id > 0 ) {
			$url = get_permalink( $page_id );
			return is_string( $url ) ? $url : '';
		}
		return home_url( '/guest-registration/' );
	}

	/**
	 * @return void
	 */
	public static function run_daily() {
		if ( ! function_exists( 'wc_get_order' ) || ! class_exists( 'WC_Email', false ) ) {
			return;
		}

		$today = new \DateTimeImmutable( 'today', wp_timezone() );
		$tomorrow  = $today->modify( '+1 day' )->format( 'Y-m-d' );
		$yesterday = $today->modify( '-1 day' )->format( 'Y-m-d' );

		self::send_pre_arrival_for_date( $tomorrow );
		self::send_post_departure_for_date( $yesterday );
	}

	/**
	 * @param string $arrival_ymd Check-in Y-m-d (tomorrow when run the day before).
	 * @return void
	 */
	private static function send_pre_arrival_for_date( $arrival_ymd ) {
		$reg_repo   = new Guest_Registration_Repository();
		$book_repo  = new Booking_Repository();
		$hold_repo  = new Stay_Hold_Repository();
		$seen_order = array();

		foreach ( $book_repo->get_confirmed_arriving_on( $arrival_ymd ) as $booking ) {
			$order_id = isset( $booking['order_id'] ) ? (int) $booking['order_id'] : 0;
			if ( $order_id < 1 || isset( $seen_order[ $order_id ] ) ) {
				continue;
			}
			$booking_id = isset( $booking['id'] ) ? (int) $booking['id'] : 0;
			if ( $reg_repo->has_registration_for_booking_or_order( $booking_id, $order_id ) ) {
				continue;
			}
			if ( self::send_pre( $order_id, (string) $booking['check_in'], (string) $booking['check_out'] ) ) {
				$seen_order[ $order_id ] = true;
			}
		}

		foreach ( $hold_repo->get_confirmed_order_holds_arriving_on( $arrival_ymd ) as $hold ) {
			$order_id = isset( $hold['order_id'] ) ? (int) $hold['order_id'] : 0;
			if ( $order_id < 1 || isset( $seen_order[ $order_id ] ) ) {
				continue;
			}
			if ( $reg_repo->has_registration_for_booking_or_order( 0, $order_id ) ) {
				continue;
			}
			if ( self::send_pre( $order_id, (string) $hold['check_in'], (string) $hold['check_out'] ) ) {
				$seen_order[ $order_id ] = true;
			}
		}
	}

	/**
	 * @param string $departure_ymd Check-out Y-m-d (yesterday when run the day after departure).
	 * @return void
	 */
	private static function send_post_departure_for_date( $departure_ymd ) {
		$reg_repo   = new Guest_Registration_Repository();
		$book_repo  = new Booking_Repository();
		$hold_repo  = new Stay_Hold_Repository();
		$seen_order = array();

		foreach ( $book_repo->get_confirmed_departing_on( $departure_ymd ) as $booking ) {
			$order_id = isset( $booking['order_id'] ) ? (int) $booking['order_id'] : 0;
			if ( $order_id < 1 || isset( $seen_order[ $order_id ] ) ) {
				continue;
			}
			$booking_id = isset( $booking['id'] ) ? (int) $booking['id'] : 0;
			if ( $reg_repo->has_registration_for_booking_or_order( $booking_id, $order_id ) ) {
				continue;
			}
			if ( self::send_post( $order_id, (string) $booking['check_in'], (string) $booking['check_out'] ) ) {
				$seen_order[ $order_id ] = true;
			}
		}

		foreach ( $hold_repo->get_confirmed_order_holds_departing_on( $departure_ymd ) as $hold ) {
			$order_id = isset( $hold['order_id'] ) ? (int) $hold['order_id'] : 0;
			if ( $order_id < 1 || isset( $seen_order[ $order_id ] ) ) {
				continue;
			}
			if ( $reg_repo->has_registration_for_booking_or_order( 0, $order_id ) ) {
				continue;
			}
			if ( self::send_post( $order_id, (string) $hold['check_in'], (string) $hold['check_out'] ) ) {
				$seen_order[ $order_id ] = true;
			}
		}
	}

	/**
	 * @param int    $order_id Order ID.
	 * @param string $check_in Y-m-d.
	 * @param string $check_out Y-m-d.
	 * @return bool Whether the email was sent and recorded.
	 */
	private static function send_pre( $order_id, $check_in, $check_out ) {
		return self::send_reminder( 'pre', (int) $order_id, $check_in, $check_out );
	}

	/**
	 * @param int    $order_id Order ID.
	 * @param string $check_in Y-m-d.
	 * @param string $check_out Y-m-d.
	 * @return bool
	 */
	private static function send_post( $order_id, $check_in, $check_out ) {
		return self::send_reminder( 'post', (int) $order_id, $check_in, $check_out );
	}

	/**
	 * @param string $type     pre|post.
	 * @param int    $order_id Order ID.
	 * @param string $check_in Y-m-d.
	 * @param string $check_out Y-m-d.
	 * @return bool
	 */
	private static function send_reminder( $type, $order_id, $check_in, $check_out ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}
		if ( ! self::order_is_live_for_reminders( $order ) ) {
			return false;
		}

		$meta_key = 'pre' === $type ? self::ORDER_META_PRE_SENT : self::ORDER_META_POST_SENT;
		if ( 'yes' === $order->get_meta( $meta_key, true ) ) {
			return false;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return false;
		}

		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();
		$id     = 'pre' === $type ? 'vila_guest_reg_reminder_pre' : 'vila_guest_reg_reminder_post';
		if ( empty( $emails[ $id ] ) || ! is_object( $emails[ $id ] ) || ! method_exists( $emails[ $id ], 'trigger_stay' ) ) {
			return false;
		}

		$sent = (bool) $emails[ $id ]->trigger_stay( $order, $check_in, $check_out );
		if ( $sent ) {
			$order->update_meta_data( $meta_key, 'yes' );
			$order->save();
		}
		return $sent;
	}

	/**
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private static function order_is_live_for_reminders( $order ) {
		$status = $order->get_status();
		return in_array( $status, array( 'processing', 'completed', 'on-hold' ), true );
	}
}
