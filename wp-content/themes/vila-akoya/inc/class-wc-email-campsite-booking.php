<?php
/**
 * WooCommerce email: campsite booking confirmation (customer).
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Email', false ) ) {
	return;
}

/**
 * Sends booking details to the customer after a paid campsite order.
 */
class Vila_Akoya_Email_Campsite_Booking extends WC_Email {

	const SENT_META_KEY = '_vila_campsite_booking_confirmation_sent';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'vila_campsite_booking';
		$this->customer_email = true;
		$this->title          = __( 'Campsite booking confirmation', 'vila-akoya' );
		$this->description    = __( 'Sent to the customer when a campsite stay is booked and paid. Includes dates, guests, extras, and order totals.', 'vila-akoya' );
		$this->template_html  = 'emails/vila-campsite-booking.php';
		$this->template_plain = 'emails/plain/vila-campsite-booking.php';
		$this->template_base  = VILA_AKOYA_DIR . '/woocommerce/';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		add_action( 'woocommerce_payment_complete', array( $this, 'trigger' ), 25, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'trigger' ), 25, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'trigger' ), 25, 1 );

		parent::__construct();

		$this->heading = __( 'Your campsite booking is confirmed', 'vila-akoya' );
		$this->subject = __( 'Your Vila Akoya campsite booking ({order_number})', 'vila-akoya' );
	}

	/**
	 * Default: enabled for new installs.
	 *
	 * @return string
	 */
	public function get_default_enabled() {
		return 'yes';
	}

	/**
	 * @param int            $order_id Order ID.
	 * @param WC_Order|false $order    Order object (optional).
	 * @return void
	 */
	public function trigger( $order_id, $order = false ) {
		$this->setup_locale();

		if ( $order_id && ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order instanceof WC_Order ) {
			$this->restore_locale();
			return;
		}

		if ( ! $this->is_enabled() || ! function_exists( 'vila_akoya_order_has_campsite_booking' ) ) {
			$this->restore_locale();
			return;
		}

		if ( 'yes' === $order->get_meta( self::SENT_META_KEY ) ) {
			$this->restore_locale();
			return;
		}

		if ( ! vila_akoya_order_has_campsite_booking( $order ) ) {
			$this->restore_locale();
			return;
		}

		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			$this->restore_locale();
			return;
		}

		$this->object                           = $order;
		$this->recipient                        = $email;
		$this->placeholders['{order_date}']     = wc_format_datetime( $order->get_date_created() );
		$this->placeholders['{order_number}'] = $order->get_order_number();

		if ( $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			$order->update_meta_data( self::SENT_META_KEY, 'yes' );
			$order->save();
		}

		$this->restore_locale();
	}

	/**
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order'         => $this->object,
				'email_heading' => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin' => false,
				'plain_text'    => false,
				'email'         => $this,
			),
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
			array(
				'order'         => $this->object,
				'email_heading' => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin' => false,
				'plain_text'    => true,
				'email'         => $this,
			),
			'',
			$this->template_base
		);
	}
}
