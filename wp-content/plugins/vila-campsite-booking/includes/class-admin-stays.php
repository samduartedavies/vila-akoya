<?php
/**
 * Village stays: calendar rows + admin bookings/blocks.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Stay occupancy admin.
 */
class Admin_Stays {

	const PAGE = 'vila-cb-stays';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 62 );
		add_action( 'admin_init', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * WooCommerce submenu.
	 */
	public static function register_menu() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_submenu_page(
			'woocommerce',
			__( 'Village stay bookings', 'vila-campsite-booking' ),
			__( 'Village stays · Bookings', 'vila-campsite-booking' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Add / cancel stay holds.
	 */
	public static function handle_save() {
		if ( ! isset( $_POST['vila_cb_stays_nonce'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_stays_nonce'] ) ) ), 'vila_cb_stays' ) ) {
			return;
		}

		$svc    = new Stay_Hold_Service();
		$action = isset( $_POST['vila_cb_stays_action'] ) ? sanitize_key( wp_unslash( Request_String::scalar( $_POST['vila_cb_stays_action'] ) ) ) : '';
		$base   = admin_url( 'admin.php?page=' . self::PAGE );

		if ( 'cancel' === $action ) {
			if ( ! empty( $_POST['vila_cb_stay_cancel'] ) && is_array( $_POST['vila_cb_stay_cancel'] ) ) {
				foreach ( $_POST['vila_cb_stay_cancel'] as $raw_id ) {
					$svc->cancel( (int) wp_unslash( Request_String::scalar( $raw_id ) ) );
				}
			}
			wp_safe_redirect( add_query_arg( 'updated', '1', $base ) );
			exit;
		}

		if ( 'add' !== $action ) {
			return;
		}

		$stay_id   = isset( $_POST['vila_cb_stay_id'] ) ? absint( wp_unslash( Request_String::scalar( $_POST['vila_cb_stay_id'] ) ) ) : 0;
		$kind      = isset( $_POST['vila_cb_stay_kind'] ) ? sanitize_key( wp_unslash( Request_String::scalar( $_POST['vila_cb_stay_kind'] ) ) ) : Stay_Hold_Repository::KIND_BOOKING;
		$check_in  = isset( $_POST['vila_cb_stay_check_in'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_stay_check_in'] ) ) ) : '';
		$check_out = isset( $_POST['vila_cb_stay_check_out'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_stay_check_out'] ) ) ) : '';
		$guest     = isset( $_POST['vila_cb_stay_guest'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_stay_guest'] ) ) ) : '';
		$note      = isset( $_POST['vila_cb_stay_note'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['vila_cb_stay_note'] ) ) ) : '';

		$res = $svc->create(
			array(
				'stay_id'    => $stay_id,
				'check_in'   => $check_in,
				'check_out'  => $check_out,
				'kind'       => $kind,
				'source'     => 'admin',
				'guest_name' => $guest,
				'note'       => $note,
			)
		);
		if ( empty( $res['success'] ) ) {
			$err = isset( $res['error'] ) ? (string) $res['error'] : __( 'Could not save.', 'vila-campsite-booking' );
			wp_safe_redirect( add_query_arg( 'error', rawurlencode( $err ), $base ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'updated', '1', $base ) );
		exit;
	}

	/**
	 * Bookings / blocks screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$repo   = new Stay_Hold_Repository();
		$stays  = Stay_Hold_Service::published_stays();
		$holds  = $repo->get_all_confirmed();
		$repo->repair_invalid_hold_dates();
		$holds  = $repo->get_all_confirmed();
		$nights_by_hold = $repo->nights_by_hold_ids(
			array_map(
				static function ( $hold ) {
					return isset( $hold['id'] ) ? (int) $hold['id'] : 0;
				},
				$holds
			)
		);
		$df     = get_option( 'date_format' );
		$df     = is_string( $df ) && '' !== $df ? $df : 'Y-m-d';
		$labels = array();
		foreach ( $stays as $stay ) {
			$labels[ (int) $stay->ID ] = get_the_title( $stay );
		}

		echo '<div class="wrap vila-cb-admin vila-cb-stays">';
		if ( class_exists( Admin::class ) && method_exists( Admin::class, 'render_section_nav' ) ) {
			Admin::render_section_nav( self::PAGE );
		}
		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Stay availability saved.', 'vila-campsite-booking' ) . '</p></div>';
		}
		if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( Request_String::scalar( $_GET['error'] ) ) ) ) . '</p></div>';
		}
		?>
		<div class="vila-cb-ui-hero">
			<div class="vila-cb-ui-hero__text">
				<h1 class="vila-cb-ui-title"><?php esc_html_e( 'Village stays', 'vila-campsite-booking' ); ?></h1>
				<p class="vila-cb-ui-lead">
					<?php esc_html_e( 'Add a booking that happened off the website (Airbnb, phone, WhatsApp) or block dates for maintenance. Occupied nights cannot be booked online and show on the calendar.', 'vila-campsite-booking' ); ?>
				</p>
			</div>
		</div>

		<?php if ( empty( $stays ) ) : ?>
			<p><?php esc_html_e( 'No published stays yet. Add accommodation under Stays first.', 'vila-campsite-booking' ); ?></p>
		<?php else : ?>
			<div class="vila-cb-ui-panel vila-cb-ui-panel--compact">
				<h2 class="vila-cb-ui-section-title"><?php esc_html_e( 'Add booking or block', 'vila-campsite-booking' ); ?></h2>
				<form method="post" class="vila-cb-blocks-add-form">
					<?php wp_nonce_field( 'vila_cb_stays', 'vila_cb_stays_nonce' ); ?>
					<input type="hidden" name="vila_cb_stays_action" value="add" />
					<div class="vila-cb-blocks-add-grid">
						<p class="vila-cb-ui-field">
							<label for="vila_cb_stay_id"><?php esc_html_e( 'Stay', 'vila-campsite-booking' ); ?></label>
							<select name="vila_cb_stay_id" id="vila_cb_stay_id" class="regular-text" required>
								<?php foreach ( $stays as $stay ) : ?>
									<option value="<?php echo esc_attr( (string) (int) $stay->ID ); ?>"><?php echo esc_html( get_the_title( $stay ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p class="vila-cb-ui-field">
							<label for="vila_cb_stay_kind"><?php esc_html_e( 'Type', 'vila-campsite-booking' ); ?></label>
							<select name="vila_cb_stay_kind" id="vila_cb_stay_kind" class="regular-text">
								<option value="booking"><?php esc_html_e( 'Booking (guest / external)', 'vila-campsite-booking' ); ?></option>
								<option value="block"><?php esc_html_e( 'Block dates (maintenance / closed)', 'vila-campsite-booking' ); ?></option>
							</select>
						</p>
						<p class="vila-cb-ui-field">
							<label for="vila_cb_stay_check_in"><?php esc_html_e( 'Check-in', 'vila-campsite-booking' ); ?></label>
							<input type="date" name="vila_cb_stay_check_in" id="vila_cb_stay_check_in" required />
						</p>
						<p class="vila-cb-ui-field">
							<label for="vila_cb_stay_check_out"><?php esc_html_e( 'Check-out', 'vila-campsite-booking' ); ?></label>
							<input type="date" name="vila_cb_stay_check_out" id="vila_cb_stay_check_out" required />
						</p>
						<p class="vila-cb-ui-field">
							<label for="vila_cb_stay_guest"><?php esc_html_e( 'Guest name', 'vila-campsite-booking' ); ?></label>
							<input type="text" name="vila_cb_stay_guest" id="vila_cb_stay_guest" class="regular-text" maxlength="191" placeholder="<?php esc_attr_e( 'e.g. Maria (Airbnb)', 'vila-campsite-booking' ); ?>" />
						</p>
						<p class="vila-cb-ui-field vila-cb-blocks-add-grid__note">
							<label for="vila_cb_stay_note"><?php esc_html_e( 'Note (optional)', 'vila-campsite-booking' ); ?></label>
							<input type="text" name="vila_cb_stay_note" id="vila_cb_stay_note" class="large-text" maxlength="255" placeholder="<?php esc_attr_e( 'Phone booking, owner stay, maintenance…', 'vila-campsite-booking' ); ?>" />
						</p>
					</div>
					<p class="description"><?php esc_html_e( 'Check-out is the morning they leave — that night stays free for the next guest, same as the website.', 'vila-campsite-booking' ); ?></p>
					<?php submit_button( __( 'Add to calendar', 'vila-campsite-booking' ), 'primary', '', false, array( 'class' => 'vila-cb-ui-submit' ) ); ?>
				</form>
			</div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'vila_cb_stays', 'vila_cb_stays_nonce' ); ?>
			<input type="hidden" name="vila_cb_stays_action" value="cancel" />
			<div class="vila-cb-ui-panel">
				<h2 class="vila-cb-ui-section-title"><?php esc_html_e( 'Current bookings and blocks', 'vila-campsite-booking' ); ?></h2>
				<?php if ( empty( $holds ) ) : ?>
					<p class="description"><?php esc_html_e( 'None yet. Website bookings appear here after payment, and you can add external bookings above.', 'vila-campsite-booking' ); ?></p>
				<?php else : ?>
					<div class="vila-cb-ui-table-wrap">
						<table class="widefat striped vila-cb-form-table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Stay', 'vila-campsite-booking' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Dates', 'vila-campsite-booking' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Type', 'vila-campsite-booking' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Guests', 'vila-campsite-booking' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Guest / note', 'vila-campsite-booking' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Source', 'vila-campsite-booking' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Remove', 'vila-campsite-booking' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $holds as $hold ) : ?>
									<?php
									$sid   = (int) $hold['stay_id'];
									$title = isset( $labels[ $sid ] ) ? $labels[ $sid ] : sprintf( __( 'Stay #%d', 'vila-campsite-booking' ), $sid );
									$hid   = isset( $hold['id'] ) ? (int) $hold['id'] : 0;
									$nights = isset( $nights_by_hold[ $hid ] ) ? $nights_by_hold[ $hid ] : array();
									$range = Dates::format_stay_range_label(
										(string) $hold['check_in'],
										(string) $hold['check_out'],
										$nights,
										$df
									);
									$kind_label = Stay_Hold_Repository::KIND_BLOCK === (string) $hold['kind']
										? __( 'Block', 'vila-campsite-booking' )
										: __( 'Booking', 'vila-campsite-booking' );
									$source_label = 'woocommerce' === (string) $hold['source']
										? __( 'Website', 'vila-campsite-booking' )
										: __( 'Admin', 'vila-campsite-booking' );
									$who = trim( (string) $hold['guest_name'] );
									if ( '' !== (string) $hold['note'] ) {
										$who = $who ? $who . ' — ' . (string) $hold['note'] : (string) $hold['note'];
									}
									$order_id = isset( $hold['order_id'] ) ? (int) $hold['order_id'] : 0;
									$line_id  = isset( $hold['line_item_id'] ) ? (int) $hold['line_item_id'] : 0;
									$party    = '';
									if ( Stay_Hold_Repository::KIND_BLOCK !== (string) $hold['kind'] ) {
										$order = $order_id ? wc_get_order( $order_id ) : null;
										$party = WooCommerce_Integration::format_guest_counts_calendar_line(
											WooCommerce_Integration::resolve_guest_counts_for_booking( '', $order, $line_id )
										);
									}
									?>
									<tr<?php echo ( isset( $_GET['hold_id'] ) && (int) $_GET['hold_id'] === (int) $hold['id'] ) ? ' class="vila-cb-row-highlight"' : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>>
										<td><?php echo esc_html( $title ); ?></td>
										<td><?php echo esc_html( $range ); ?></td>
										<td><?php echo esc_html( $kind_label ); ?></td>
										<td><?php echo esc_html( $party ); ?></td>
										<td>
											<?php echo esc_html( $who ); ?>
											<?php if ( $order_id > 0 ) : ?>
												<br /><a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ); ?>"><?php echo esc_html( sprintf( __( 'Order #%d', 'vila-campsite-booking' ), $order_id ) ); ?></a>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( $source_label ); ?></td>
										<td>
											<label class="vila-cb-ui-check">
												<input type="checkbox" name="vila_cb_stay_cancel[]" value="<?php echo esc_attr( (string) (int) $hold['id'] ); ?>" />
												<?php esc_html_e( 'Remove', 'vila-campsite-booking' ); ?>
											</label>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php submit_button( __( 'Remove selected', 'vila-campsite-booking' ), 'secondary', '', false, array( 'class' => 'vila-cb-ui-submit' ) ); ?>
				<?php endif; ?>
			</div>
		</form>
		</div>
		<?php
	}

	/**
	 * Stay rows for the shared 14-day calendar.
	 *
	 * @param array<int, array{ymd: string, dow: string, day: string, mon: string}> $days      Columns.
	 * @param string                                                                 $today_ymd Today.
	 * @return void
	 */
	public static function render_calendar_rows( array $days, $today_ymd ) {
		$stays = Stay_Hold_Service::published_stays();
		if ( empty( $stays ) || empty( $days ) ) {
			return;
		}

		$from  = $days[0]['ymd'];
		$to    = $days[ count( $days ) - 1 ]['ymd'];
		$repo  = new Stay_Hold_Repository();
		$holds = $repo->get_overlapping( $from, $to );
		$nights_by_hold = $repo->nights_by_hold_ids(
			array_map(
				static function ( $hold ) {
					return isset( $hold['id'] ) ? (int) $hold['id'] : 0;
				},
				$holds
			)
		);
		$by    = array();
		foreach ( $holds as $hold ) {
			$hid = isset( $hold['id'] ) ? (int) $hold['id'] : 0;
			if ( $hid > 0 && isset( $nights_by_hold[ $hid ] ) ) {
				$hold['nights'] = $nights_by_hold[ $hid ];
			} else {
				$hold['nights'] = Dates::nights_in_range( (string) $hold['check_in'], (string) $hold['check_out'] );
			}
			$sid = (int) $hold['stay_id'];
			if ( ! isset( $by[ $sid ] ) ) {
				$by[ $sid ] = array();
			}
			$by[ $sid ][] = $hold;
		}

		$day_count = count( $days );
		$edit_base = admin_url( 'admin.php?page=' . self::PAGE );

		foreach ( $stays as $stay ) {
			$sid = (int) $stay->ID;
			echo '<tr class="vila-cb-grid__row-stay">';
			echo '<th class="vila-cb-grid__plot-cell" scope="row">' . esc_html( get_the_title( $stay ) ) . '</th>';
			$j = 0;
			while ( $j < $day_count ) {
				$ymd  = $days[ $j ]['ymd'];
				$seg  = self::hold_for_day( $by, $sid, $ymd );
				if ( null === $seg ) {
					$span = 1;
					for ( $k = $j + 1; $k < $day_count; $k++ ) {
						if ( null !== self::hold_for_day( $by, $sid, $days[ $k ]['ymd'] ) ) {
							break;
						}
						$span++;
					}
					$today = self::range_includes_today( $days, $j, $span, $today_ymd );
					$class = 'vila-cb-grid__cell--free' . ( $today ? ' vila-cb-grid__cell--today' : '' );
					echo '<td class="' . esc_attr( $class ) . '" colspan="' . (int) $span . '"><span class="vila-cb-grid__empty">' . esc_html__( '—', 'vila-campsite-booking' ) . '</span></td>';
					$j += $span;
					continue;
				}
				$hid  = (int) $seg['id'];
				$span = 0;
				for ( $k = $j; $k < $day_count; $k++ ) {
					$sk = self::hold_for_day( $by, $sid, $days[ $k ]['ymd'] );
					if ( null === $sk || (int) $sk['id'] !== $hid ) {
						break;
					}
					$span++;
				}
				$is_block = Stay_Hold_Repository::KIND_BLOCK === (string) $seg['kind'];
				$today    = self::range_includes_today( $days, $j, $span, $today_ymd );
				$class    = $is_block
					? 'vila-cb-grid__blocked vila-cb-grid__blocked-bar'
					: 'vila-cb-grid__busy vila-cb-grid__busy-bar';
				if ( $today ) {
					$class .= ' vila-cb-grid__cell--today';
				}
				$payload = self::calendar_hold_cell_payload( $seg, $edit_base );
				echo '<td class="' . esc_attr( $class ) . '" colspan="' . (int) $span . '" title="' . esc_attr( $payload['cell_title'] ) . '">';
				echo $payload['cell_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built in Admin::render_calendar_busy_cell_markup().
				echo '</td>';
				$j += $span;
			}
			echo '</tr>';
		}
	}

	/**
	 * Guest / meal payload for a stay hold bar (same layout as pitch bookings).
	 *
	 * @param array<string, mixed> $seg      Hold row.
	 * @param string               $edit_base Village stays admin URL.
	 * @return array{cell_title: string, cell_html: string}
	 */
	private static function calendar_hold_cell_payload( array $seg, $edit_base ) {
		$hid      = isset( $seg['id'] ) ? (int) $seg['id'] : 0;
		$url      = add_query_arg( 'hold_id', $hid, $edit_base );
		$is_block = Stay_Hold_Repository::KIND_BLOCK === (string) $seg['kind'];
		$oid      = isset( $seg['order_id'] ) ? (int) $seg['order_id'] : 0;
		$order    = $oid ? wc_get_order( $oid ) : null;
		$line_id  = isset( $seg['line_item_id'] ) ? (int) $seg['line_item_id'] : 0;

		$guest_name = trim( (string) $seg['guest_name'] );
		if ( '' === $guest_name && $order instanceof \WC_Order ) {
			$guest_name = trim( (string) $order->get_formatted_billing_full_name() );
			if ( '' === $guest_name ) {
				$guest_name = '#' . $order->get_order_number();
			}
		}
		if ( '' === $guest_name ) {
			$guest_name = trim( (string) $seg['note'] );
		}
		if ( '' === $guest_name ) {
			$guest_name = $is_block ? __( 'Blocked', 'vila-campsite-booking' ) : __( 'Booked', 'vila-campsite-booking' );
		}

		$check_in  = (string) $seg['check_in'];
		$check_out = (string) $seg['check_out'];
		if ( empty( Dates::nights_in_range( $check_in, $check_out ) ) && ! empty( $seg['nights'] ) && is_array( $seg['nights'] ) ) {
			$bounds = Dates::bounds_from_nights( $seg['nights'] );
			if ( $bounds ) {
				$check_in  = $bounds['check_in'];
				$check_out = $bounds['check_out'];
			}
		}
		$date_range = Admin::calendar_booking_stay_date_range_label( $check_in, $check_out );

		$guest_json  = '';
		if ( $order instanceof \WC_Order && $line_id > 0 ) {
			$item = $order->get_item( $line_id );
			if ( $item && is_callable( array( $item, 'get_meta' ) ) ) {
				$guest_json = (string) $item->get_meta( WooCommerce_Integration::META_GUESTS, true );
			}
		}
		$counts      = $is_block ? array() : WooCommerce_Integration::resolve_guest_counts_for_booking( $guest_json, $order, $line_id );
		$detail_line = $is_block ? '' : WooCommerce_Integration::format_guest_counts_calendar_line( $counts );
		$meals_line  = $is_block ? '' : WooCommerce_Integration::meal_labels_for_order_line( $order, $line_id, $guest_json );
		if ( '' === $meals_line && '' !== trim( (string) $seg['note'] ) && $is_block ) {
			$meals_line = (string) $seg['note'];
		}

		$tip_bits = array_filter(
			array(
				$date_range,
				$guest_name,
				$detail_line,
				$meals_line,
			)
		);

		return array(
			'cell_title' => implode( ' — ', $tip_bits ),
			'cell_html'  => Admin::render_calendar_busy_cell_markup(
				$guest_name,
				$detail_line,
				$meals_line,
				$url,
				$date_range,
				false,
				0,
				false
			),
		);
	}

	/**
	 * @param array<int, array<int, array<string, mixed>>> $by  Holds by stay.
	 * @param int                                          $sid Stay ID.
	 * @param string                                       $ymd Y-m-d.
	 * @return array<string, mixed>|null
	 */
	private static function hold_for_day( array $by, $sid, $ymd ) {
		$sid = (int) $sid;
		$ymd = (string) $ymd;
		foreach ( $by[ $sid ] ?? array() as $seg ) {
			$nights = ! empty( $seg['nights'] ) && is_array( $seg['nights'] )
				? $seg['nights']
				: Dates::nights_in_range( (string) $seg['check_in'], (string) $seg['check_out'] );
			if ( in_array( $ymd, $nights, true ) ) {
				return $seg;
			}
		}
		return null;
	}

	/**
	 * @param array<int, array{ymd: string}> $days      Columns.
	 * @param int                            $start_idx Start.
	 * @param int                            $span      Span.
	 * @param string                         $today_ymd Today.
	 * @return bool
	 */
	private static function range_includes_today( array $days, $start_idx, $span, $today_ymd ) {
		for ( $i = 0; $i < (int) $span; $i++ ) {
			$idx = (int) $start_idx + $i;
			if ( isset( $days[ $idx ]['ymd'] ) && $days[ $idx ]['ymd'] === $today_ymd ) {
				return true;
			}
		}
		return false;
	}
}
