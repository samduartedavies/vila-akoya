<?php
/**
 * WooCommerce emails: guest registration reminders (pre-arrival / post-departure).
 *
 * @package Vila_Campsite_Booking
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Email', false ) ) {
	return;
}

/**
 * Shared reminder email behaviour.
 */
abstract class WC_Email_Guest_Reg_Reminder_Base extends \WC_Email {

	/**
	 * Stay window for the template.
	 *
	 * @var string
	 */
	public $stay_check_in = '';

	/**
	 * @var string
	 */
	public $stay_check_out = '';

	/**
	 * pre|post
	 *
	 * @var string
	 */
	public $reminder_type = 'pre';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->customer_email = true;
		$this->template_base  = VILA_CB_DIR . 'templates/';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);
		parent::__construct();
	}

	/**
	 * @return string
	 */
	public function get_default_enabled() {
		return 'yes';
	}

	/**
	 * @param \WC_Order $order     Order.
	 * @param string    $check_in  Y-m-d.
	 * @param string    $check_out Y-m-d.
	 * @return bool
	 */
	public function trigger_stay( $order, $check_in, $check_out ) {
		$this->setup_locale();

		if ( ! $order instanceof \WC_Order ) {
			$this->restore_locale();
			return false;
		}

		if ( ! $this->is_enabled() ) {
			$this->restore_locale();
			return false;
		}

		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			$this->restore_locale();
			return false;
		}

		$this->object              = $order;
		$this->recipient             = $email;
		$this->stay_check_in         = Guest_Registration_Repository::ymd_or_empty( $check_in );
		$this->stay_check_out        = Guest_Registration_Repository::ymd_or_empty( $check_out );
		$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
		$this->placeholders['{order_number}'] = $order->get_order_number();

		$sent = false;
		if ( $this->get_recipient() ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
		return $sent;
	}

	/**
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			$this->template_args(),
			'',
			$this->template_base
		);
	}

	/**
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			$this->template_args(),
			'',
			$this->template_base
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function template_args() {
		return array(
			'order'              => $this->object,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $this->get_email_type() !== 'html',
			'email'              => $this,
			'reminder_type'      => $this->reminder_type,
			'stay_check_in'      => $this->stay_check_in,
			'stay_check_out'     => $this->stay_check_out,
			'checkin_url'        => Guest_Registration_Reminders::registration_page_url(),
		);
	}
}

/**
 * Reminder the day before check-in.
 */
class WC_Email_Guest_Reg_Reminder_Pre extends WC_Email_Guest_Reg_Reminder_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'vila_guest_reg_reminder_pre';
		$this->reminder_type  = 'pre';
		$this->title          = __( 'Guest check-in reminder (before arrival)', 'vila-campsite-booking' );
		$this->description    = __( 'Sent the day before check-in when legal guest registration is still missing.', 'vila-campsite-booking' );
		$this->template_html  = 'emails/guest-reg-reminder.php';
		$this->template_plain = 'emails/plain/guest-reg-reminder.php';
		parent::__construct();
		$this->heading = __( 'Complete your guest check-in before you arrive', 'vila-campsite-booking' );
		$this->subject = __( 'Reminder: guest check-in for your Vila Akoya stay ({order_number})', 'vila-campsite-booking' );
	}
}

/**
 * Reminder the day after departure.
 */
class WC_Email_Guest_Reg_Reminder_Post extends WC_Email_Guest_Reg_Reminder_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'vila_guest_reg_reminder_post';
		$this->reminder_type  = 'post';
		$this->title          = __( 'Guest check-in reminder (after stay)', 'vila-campsite-booking' );
		$this->description    = __( 'Sent the day after check-out when legal guest registration was never submitted.', 'vila-campsite-booking' );
		$this->template_html  = 'emails/guest-reg-reminder.php';
		$this->template_plain = 'emails/plain/guest-reg-reminder.php';
		parent::__construct();
		$this->heading = __( 'Please complete your guest registration', 'vila-campsite-booking' );
		$this->subject = __( 'Guest registration still needed for your Vila Akoya stay ({order_number})', 'vila-campsite-booking' );
	}
}
