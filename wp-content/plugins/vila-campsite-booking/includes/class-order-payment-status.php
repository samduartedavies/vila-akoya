<?php
/**
 * Payment status for orders: paid online vs. unpaid (pay on arrival / bank transfer),
 * plus manual "payment received" marking for cash, card machine and bank transfer.
 *
 * WooCommerce puts pay-on-arrival orders into Processing even though nothing has been
 * collected, so order status alone cannot tell staff who still owes money.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Orders list column, filter, bulk actions and order-screen actions for payment tracking.
 */
class Order_Payment_Status {

	const META_METHOD = '_vila_cb_payment_received';
	const META_AT     = '_vila_cb_payment_received_at';
	const META_BY     = '_vila_cb_payment_received_by';

	const STATE_PAID_ONLINE  = 'paid_online';
	const STATE_PAID_ON_SITE = 'paid_on_site';
	const STATE_UNPAID       = 'unpaid';
	const STATE_REFUNDED     = 'refunded';
	const STATE_NOT_BOOKED   = 'not_booked';
	const STATE_OFFSITE      = 'offsite';

	const FILTER_PAID_ANY = 'paid';

	const COLUMN = 'vila_payment';
	const FILTER = 'vila_payment';

	const NOTICE_ARG = 'vila_payment_marked';

	/**
	 * Gateways that do not collect money at checkout.
	 *
	 * @return string[]
	 */
	public static function offline_gateways() {
		return (array) apply_filters( 'vila_cb_offline_payment_gateways', array( 'cod', 'bacs', 'cheque' ) );
	}

	/**
	 * @return array<string, string> Method key => label.
	 */
	public static function manual_methods() {
		return array(
			'cash'          => __( 'Cash', 'vila-campsite-booking' ),
			'card_machine'  => __( 'Card machine', 'vila-campsite-booking' ),
			'bank_transfer' => __( 'Bank transfer', 'vila-campsite-booking' ),
		);
	}

	public static function init() {
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_column_hpos' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column_legacy' ), 10, 2 );

		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( __CLASS__, 'render_filter' ), 20 );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( __CLASS__, 'apply_filter' ) );

		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );

		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'add_order_actions' ), 10, 2 );
		foreach ( array_keys( self::manual_methods() ) as $method ) {
			add_action(
				'woocommerce_order_action_vila_paid_' . $method,
				static function ( $order ) use ( $method ) {
					self::mark_paid( $order, $method );
				}
			);
		}
		add_action( 'woocommerce_order_action_vila_mark_unpaid', array( __CLASS__, 'mark_unpaid' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'render_order_box' ) );
		add_action( 'admin_post_vila_cb_mark_payment', array( __CLASS__, 'handle_mark_payment' ) );
	}

	/**
	 * @param \WC_Order $order Order.
	 * @return string One of the STATE_* constants.
	 */
	public static function get_state( $order ) {
		$status = $order->get_status();
		if ( 'refunded' === $status ) {
			return self::STATE_REFUNDED;
		}
		if ( in_array( $status, array( 'cancelled', 'failed', 'pending', 'checkout-draft', 'trash' ), true ) ) {
			return self::STATE_NOT_BOOKED;
		}
		if ( '' !== (string) $order->get_meta( self::META_METHOD ) ) {
			return self::STATE_PAID_ON_SITE;
		}
		if ( ! in_array( $order->get_payment_method(), self::offline_gateways(), true ) && $order->get_date_paid() ) {
			return self::STATE_PAID_ONLINE;
		}
		return self::STATE_UNPAID;
	}

	/**
	 * @param \WC_Order $order Order.
	 * @return array{ state: string, label: string, detail: string }
	 */
	public static function describe( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return array(
				'state'  => self::STATE_OFFSITE,
				'label'  => __( 'Off-site booking', 'vila-campsite-booking' ),
				'detail' => '',
			);
		}

		$state  = self::get_state( $order );
		$detail = '';
		switch ( $state ) {
			case self::STATE_PAID_ONLINE:
				$label  = __( 'Paid online', 'vila-campsite-booking' );
				$detail = $order->get_payment_method_title();
				break;
			case self::STATE_PAID_ON_SITE:
				$methods = self::manual_methods();
				$method  = (string) $order->get_meta( self::META_METHOD );
				$label   = __( 'Paid', 'vila-campsite-booking' );
				$detail  = isset( $methods[ $method ] ) ? $methods[ $method ] : $method;
				break;
			case self::STATE_UNPAID:
				$label  = __( 'Not paid yet', 'vila-campsite-booking' );
				$detail = $order->get_payment_method_title();
				break;
			case self::STATE_REFUNDED:
				$label = __( 'Refunded', 'vila-campsite-booking' );
				break;
			default:
				$label = __( 'No payment', 'vila-campsite-booking' );
				break;
		}
		return array(
			'state'  => $state,
			'label'  => $label,
			'detail' => $detail,
		);
	}

	/**
	 * @param \WC_Order|null $order Order.
	 * @param string         $variant default|compact.
	 */
	public static function badge_html( $order, $variant = 'default' ) {
		$info = self::describe( $order );
		$classes = array(
			'vila-cb-pay',
			'vila-cb-pay--' . $info['state'],
			'compact' === $variant ? 'vila-cb-pay--compact' : '',
		);
		$html = '<mark class="' . esc_attr( trim( implode( ' ', array_filter( $classes ) ) ) ) . '"><span>' . esc_html( $info['label'] ) . '</span></mark>';
		if ( '' !== $info['detail'] && 'compact' !== $variant ) {
			$html .= '<small class="vila-cb-pay__detail">' . esc_html( $info['detail'] ) . '</small>';
		}
		return $html;
	}

	/**
	 * @return array<string, string> Filter value => label.
	 */
	public static function filter_options() {
		return array(
			''                       => __( 'All payments', 'vila-campsite-booking' ),
			self::STATE_UNPAID       => __( 'Not paid yet', 'vila-campsite-booking' ),
			self::FILTER_PAID_ANY    => __( 'Paid', 'vila-campsite-booking' ),
			self::STATE_PAID_ONLINE  => __( 'Paid online', 'vila-campsite-booking' ),
			self::STATE_PAID_ON_SITE => __( 'Paid on site', 'vila-campsite-booking' ),
		);
	}

	/**
	 * @param \WC_Order|null $order  Order.
	 * @param string         $filter Filter key.
	 * @return bool
	 */
	public static function matches_filter( $order, $filter ) {
		$filter = sanitize_key( (string) $filter );
		if ( '' === $filter ) {
			return true;
		}
		if ( ! $order instanceof \WC_Order ) {
			return self::STATE_OFFSITE === $filter;
		}
		$state = self::get_state( $order );
		if ( self::FILTER_PAID_ANY === $filter ) {
			return in_array( $state, array( self::STATE_PAID_ONLINE, self::STATE_PAID_ON_SITE ), true );
		}
		return $state === $filter;
	}

	/**
	 * @param string $current Current filter value.
	 * @param string $name    Select name.
	 * @param string $label   Optional screen-reader label.
	 * @return string Safe HTML.
	 */
	public static function filter_select_html( $current = '', $name = self::FILTER, $label = '' ) {
		$current = sanitize_key( (string) $current );
		$html    = '';
		if ( '' !== $label ) {
			$html .= '<label class="screen-reader-text" for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
		}
		$html .= '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '">';
		foreach ( self::filter_options() as $value => $text ) {
			$html .= '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $text ) . '</option>';
		}
		$html .= '</select>';
		return $html;
	}

	/**
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function paid_attribution_html( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}
		$at = (int) $order->get_meta( self::META_AT );
		if ( ! $at ) {
			return '';
		}
		$by = get_userdata( (int) $order->get_meta( self::META_BY ) );
		return '<small class="vila-cb-pay__marked">' . esc_html(
			sprintf(
				/* translators: 1: date, 2: staff name */
				__( 'Marked %1$s by %2$s', 'vila-campsite-booking' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $at ),
				$by ? $by->display_name : '—'
			)
		) . '</small>';
	}

	/**
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function add_column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out[ self::COLUMN ] = __( 'Payment', 'vila-campsite-booking' );
			}
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Payment', 'vila-campsite-booking' );
		}
		return $out;
	}

	/**
	 * @param string    $column Column key.
	 * @param \WC_Order $order  Order.
	 */
	public static function render_column_hpos( $column, $order ) {
		if ( self::COLUMN === $column && $order instanceof \WC_Order ) {
			echo self::badge_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge_html().
		}
	}

	/**
	 * @param string $column  Column key.
	 * @param int    $post_id Order ID.
	 */
	public static function render_column_legacy( $column, $post_id ) {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order instanceof \WC_Order ) {
			echo self::badge_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge_html().
		}
	}

	/**
	 * @param string $order_type Order type being listed.
	 */
	public static function render_filter( $order_type = 'shop_order' ) {
		if ( 'shop_order' !== $order_type ) {
			return;
		}
		$current = isset( $_GET[ self::FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$options = array(
			''                       => __( 'All payments', 'vila-campsite-booking' ),
			self::STATE_UNPAID       => __( 'Not paid yet', 'vila-campsite-booking' ),
			self::STATE_PAID_ONLINE  => __( 'Paid online', 'vila-campsite-booking' ),
			self::STATE_PAID_ON_SITE => __( 'Paid on site', 'vila-campsite-booking' ),
		);
		echo '<select name="' . esc_attr( self::FILTER ) . '">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * @param array<string, mixed> $args wc_get_orders() args used by the HPOS list table.
	 * @return array<string, mixed>
	 */
	public static function apply_filter( $args ) {
		$filter = isset( $_GET[ self::FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $filter ) {
			return $args;
		}

		$booked     = array( 'wc-processing', 'wc-on-hold', 'wc-completed' );
		$meta_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();

		if ( self::STATE_PAID_ON_SITE === $filter ) {
			$meta_query[] = array(
				'key'     => self::META_METHOD,
				'compare' => 'EXISTS',
			);
		} elseif ( self::STATE_UNPAID === $filter || self::STATE_PAID_ONLINE === $filter ) {
			$meta_query[] = array(
				'key'     => self::META_METHOD,
				'compare' => 'NOT EXISTS',
			);
			$field_query = isset( $args['field_query'] ) && is_array( $args['field_query'] ) ? $args['field_query'] : array();
			$field_query[] = array(
				'field'   => 'payment_method',
				'value'   => self::offline_gateways(),
				'compare' => self::STATE_UNPAID === $filter ? 'IN' : 'NOT IN',
			);
			$args['field_query'] = $field_query;
		} else {
			return $args;
		}

		$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$requested = array();
		foreach ( isset( $args['status'] ) ? (array) $args['status'] : array() as $s ) {
			if ( 'all' !== $s && 'any' !== $s && '' !== $s ) {
				$requested[] = 0 === strpos( $s, 'wc-' ) ? $s : 'wc-' . $s;
			}
		}
		$args['status'] = $requested ? array_values( array_intersect( $requested, $booked ) ) : $booked;
		if ( ! $args['status'] ) {
			$args['status'] = array( 'vila-none' );
		}
		return $args;
	}

	/**
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string>
	 */
	public static function add_bulk_actions( $actions ) {
		foreach ( self::manual_methods() as $key => $label ) {
			/* translators: %s: payment method, e.g. Cash */
			$actions[ 'vila_paid_' . $key ] = sprintf( __( 'Mark as paid – %s', 'vila-campsite-booking' ), $label );
		}
		$actions['vila_mark_unpaid'] = __( 'Mark as not paid', 'vila-campsite-booking' );
		return $actions;
	}

	/**
	 * @param string $redirect Redirect URL.
	 * @param string $action   Bulk action.
	 * @param int[]  $ids      Order IDs.
	 * @return string
	 */
	public static function handle_bulk_actions( $redirect, $action, $ids ) {
		$method = 0 === strpos( (string) $action, 'vila_paid_' ) ? substr( $action, strlen( 'vila_paid_' ) ) : '';
		if ( 'vila_mark_unpaid' !== $action && ! isset( self::manual_methods()[ $method ] ) ) {
			return $redirect;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return $redirect;
		}
		$changed = 0;
		foreach ( array_map( 'absint', (array) $ids ) as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$done = '' !== $method ? self::mark_paid( $order, $method ) : self::mark_unpaid( $order );
			if ( $done ) {
				++$changed;
			}
		}
		return add_query_arg( self::NOTICE_ARG, $changed, $redirect );
	}

	public static function bulk_notice() {
		if ( ! isset( $_GET[ self::NOTICE_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$count = absint( $_GET[ self::NOTICE_ARG ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		/* translators: %d: number of orders */
		$message = sprintf( _n( 'Payment status updated on %d order.', 'Payment status updated on %d orders.', $count, 'vila-campsite-booking' ), $count );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * @param array<string, string> $actions Order actions.
	 * @param \WC_Order|null        $order   Order.
	 * @return array<string, string>
	 */
	public static function add_order_actions( $actions, $order = null ) {
		if ( ! $order instanceof \WC_Order ) {
			return $actions;
		}
		$state = self::get_state( $order );
		if ( self::STATE_UNPAID === $state ) {
			foreach ( self::manual_methods() as $key => $label ) {
				/* translators: %s: payment method, e.g. Cash */
				$actions[ 'vila_paid_' . $key ] = sprintf( __( 'Mark as paid – %s', 'vila-campsite-booking' ), $label );
			}
		} elseif ( self::STATE_PAID_ON_SITE === $state ) {
			$actions['vila_mark_unpaid'] = __( 'Mark as not paid', 'vila-campsite-booking' );
		}
		return $actions;
	}

	/**
	 * Record a payment taken outside the online checkout.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $method Key from manual_methods().
	 * @return bool Whether the order changed.
	 */
	public static function mark_paid( $order, $method ) {
		$methods = self::manual_methods();
		if ( ! isset( $methods[ $method ] ) || self::STATE_UNPAID !== self::get_state( $order ) ) {
			return false;
		}
		$user = wp_get_current_user();
		$order->update_meta_data( self::META_METHOD, $method );
		$order->update_meta_data( self::META_AT, time() );
		$order->update_meta_data( self::META_BY, (int) $user->ID );
		if ( ! $order->get_date_paid() ) {
			$order->set_date_paid( time() );
		}
		/* translators: 1: payment method, 2: staff name */
		$note = sprintf( __( 'Payment received on site (%1$s), marked by %2$s.', 'vila-campsite-booking' ), $methods[ $method ], $user->display_name );
		if ( 'on-hold' === $order->get_status() ) {
			$order->update_status( 'processing', $note );
		} else {
			$order->add_order_note( $note );
			$order->save();
		}
		do_action( 'vila_cb_payment_status_changed', $order->get_id(), self::STATE_PAID_ON_SITE );
		return true;
	}

	/**
	 * Undo a manual payment mark.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool Whether the order changed.
	 */
	public static function mark_unpaid( $order ) {
		if ( self::STATE_PAID_ON_SITE !== self::get_state( $order ) ) {
			return false;
		}
		$order->delete_meta_data( self::META_METHOD );
		$order->delete_meta_data( self::META_AT );
		$order->delete_meta_data( self::META_BY );
		if ( in_array( $order->get_payment_method(), self::offline_gateways(), true ) ) {
			$order->set_date_paid( null );
		}
		/* translators: %s: staff name */
		$order->add_order_note( sprintf( __( 'Manual payment mark removed by %s.', 'vila-campsite-booking' ), wp_get_current_user()->display_name ) );
		$order->save();
		do_action( 'vila_cb_payment_status_changed', $order->get_id(), self::STATE_UNPAID );
		return true;
	}

	/**
	 * Mark an order paid/unpaid from plugin admin screens.
	 */
	public static function handle_mark_payment() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit orders.', 'vila-campsite-booking' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'vila_cb_mark_payment' );
		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( Request_String::scalar( $_POST['order_id'] ) ) ) : 0;
		$method   = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( Request_String::scalar( $_POST['method'] ) ) ) : '';
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( Request_String::scalar( $_POST['redirect_to'] ) ) ) : admin_url( 'admin.php?page=' . Admin::PAGE_OVERVIEW );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		$changed  = false;
		if ( $order instanceof \WC_Order ) {
			$changed = 'unpaid' === $method ? self::mark_unpaid( $order ) : self::mark_paid( $order, $method );
		}
		wp_safe_redirect( add_query_arg( self::NOTICE_ARG, $changed ? '1' : '0', $redirect ) );
		exit;
	}

	/**
	 * @param \WC_Order|null $order        Order.
	 * @param string         $redirect_url Where to return after action.
	 * @param string         $variant      full|compact.
	 * @return string Safe HTML.
	 */
	public static function render_mark_paid_controls( $order, $redirect_url, $variant = 'full' ) {
		if ( ! $order instanceof \WC_Order || ! current_user_can( 'edit_shop_orders' ) ) {
			return '';
		}
		$state = self::get_state( $order );
		if ( self::STATE_UNPAID !== $state && self::STATE_PAID_ON_SITE !== $state ) {
			return '';
		}
		$html = '<div class="vila-cb-pay-actions vila-cb-pay-actions--' . esc_attr( $variant ) . '">';
		if ( self::STATE_UNPAID === $state ) {
			foreach ( self::manual_methods() as $method => $label ) {
				$html .= self::mark_paid_form_html( $order, $method, $label, $redirect_url, $variant );
			}
		} else {
			$html .= self::mark_paid_form_html( $order, 'unpaid', __( 'Mark as not paid', 'vila-campsite-booking' ), $redirect_url, $variant );
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * @param \WC_Order $order        Order.
	 * @param string    $method       Method key.
	 * @param string    $label        Button label.
	 * @param string    $redirect_url Redirect.
	 * @param string    $variant      full|compact.
	 * @return string Safe HTML.
	 */
	private static function mark_paid_form_html( $order, $method, $label, $redirect_url, $variant ) {
		$button_label = 'compact' === $variant && 'unpaid' !== $method
			? $label
			: ( 'unpaid' === $method ? $label : sprintf( __( 'Mark paid: %s', 'vila-campsite-booking' ), $label ) );
		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= wp_nonce_field( 'vila_cb_mark_payment', '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="vila_cb_mark_payment" />';
		$html .= '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '" />';
		$html .= '<input type="hidden" name="method" value="' . esc_attr( $method ) . '" />';
		$html .= '<input type="hidden" name="redirect_to" value="' . esc_url( $redirect_url ) . '" />';
		$html .= '<button type="submit" class="button button-small">' . esc_html( $button_label ) . '</button>';
		$html .= '</form>';
		return $html;
	}

	/**
	 * @param \WC_Order $order Order.
	 */
	public static function render_order_box( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		echo '<p class="form-field form-field-wide vila-cb-pay-box"><strong>' . esc_html__( 'Payment:', 'vila-campsite-booking' ) . '</strong> ';
		echo self::badge_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge_html().
		echo '<br>' . self::paid_attribution_html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
		if ( self::STATE_UNPAID === self::get_state( $order ) ) {
			echo '<br><small>' . esc_html__( 'Use the Order actions box to mark it as paid (cash, card machine or bank transfer).', 'vila-campsite-booking' ) . '</small>';
		}
		echo '</p>';
	}
}
