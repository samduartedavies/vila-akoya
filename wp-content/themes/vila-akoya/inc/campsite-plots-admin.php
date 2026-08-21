<?php
/**
 * WooCommerce admin: campsite plot timeline + KPIs (per-plot inventory from WC orders).
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

/**
 * Invalidate cached unavailable-date lists for all plot products.
 */
function vila_akoya_invalidate_campsite_plot_availability_caches() {
	foreach ( vila_akoya_get_campsite_plot_product_ids() as $pid ) {
		delete_transient( 'vila_plot_unavail_' . (int) $pid );
	}
	if ( function_exists( 'vila_akoya_get_stay_product_ids' ) ) {
		foreach ( vila_akoya_get_stay_product_ids() as $pid ) {
			delete_transient( 'vila_plot_unavail_' . (int) $pid );
		}
	}
}
add_action( 'woocommerce_order_status_changed', 'vila_akoya_invalidate_campsite_plot_availability_caches', 30 );
add_action( 'woocommerce_new_order', 'vila_akoya_invalidate_campsite_plot_availability_caches', 30 );
add_action( 'woocommerce_after_order_object_save', 'vila_akoya_invalidate_campsite_plot_availability_caches', 30 );

/**
 * Y-m-d dates that are fully booked for this plot (cached; invalidated on order changes).
 *
 * @param int $product_id Plot product ID.
 * @return string[]
 */
function vila_akoya_get_plot_unavailable_ymd_for_product( $product_id ) {
	$product_id = (int) $product_id;
	if ( ! $product_id ) {
		return array();
	}
	$cache_key = 'vila_plot_unavail_' . $product_id;
	$cached    = get_transient( $cache_key );
	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}
	$dates    = array();
	$segments = vila_akoya_campsite_plot_booking_segments_for_product( $product_id );
	foreach ( $segments as $seg ) {
		foreach ( $seg['nights'] as $ymd ) {
			$dates[ $ymd ] = true;
		}
	}
	$out = array_keys( $dates );
	sort( $out );
	set_transient( $cache_key, $out, 5 * MINUTE_IN_SECONDS );
	return $out;
}

/**
 * Submenu under WooCommerce.
 */
function vila_akoya_register_campsite_plots_admin_page() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	// vila-campsite-booking plugin registers WooCommerce → Campsite (plots, calendar, settings).
	if ( class_exists( '\Vila_CB\Admin' ) ) {
		return;
	}
	add_submenu_page(
		'woocommerce',
		__( 'Campsite plots', 'vila-akoya' ),
		__( 'Campsite plots', 'vila-akoya' ),
		'manage_woocommerce',
		'vila-akoya-campsite-plots',
		'vila_akoya_render_campsite_plots_admin_page'
	);
}
add_action( 'admin_menu', 'vila_akoya_register_campsite_plots_admin_page', 60 );

/**
 * @param string $from_dmy d-m-Y.
 * @param string $to_dmy   d-m-Y checkout (exclusive end for nights, same as theme booking helpers).
 * @return string[] List of Y-m-d for each booked night.
 */
function vila_akoya_booking_nights_ymd_from_dmy_range( $from_dmy, $to_dmy ) {
	$from_dmy = trim( (string) $from_dmy );
	$to_dmy   = trim( (string) $to_dmy );
	if ( '' === $from_dmy || '' === $to_dmy ) {
		return array();
	}
	$df = DateTime::createFromFormat( 'd-m-Y', $from_dmy, wp_timezone() );
	$dt = DateTime::createFromFormat( 'd-m-Y', $to_dmy, wp_timezone() );
	if ( ! $df || ! $dt || $dt <= $df ) {
		return array();
	}
	$out = array();
	$cur = clone $df;
	while ( $cur < $dt ) {
		$out[] = $cur->format( 'Y-m-d' );
		$cur->modify( '+1 day' );
	}
	return $out;
}

/**
 * Collect booking segments for a plot product from paid/pending orders.
 *
 * @param int $product_id Plot product ID.
 * @return array<int, array{ order_id: int, nights: string[], label: string }>
 */
function vila_akoya_campsite_plot_booking_segments_for_product( $product_id ) {
	$product_id = (int) $product_id;
	if ( ! $product_id || ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}

	$orders = wc_get_orders(
		array(
			'limit'   => 300,
			'status'  => array( 'pending', 'processing', 'on-hold', 'completed' ),
			'orderby' => 'date',
			'order'   => 'DESC',
		)
	);

	$segments = array();
	foreach ( $orders as $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_product_id() !== $product_id ) {
				continue;
			}
			$from = $item->get_meta( '_mwb_bfwp_date_time_from', true );
			$to   = $item->get_meta( '_mwb_bfwp_date_time_to', true );
			if ( ! $from || ! $to ) {
				$from = $item->get_meta( '_wps_single_cal_date_time_from', true );
				$to   = $item->get_meta( '_wps_single_cal_date_time_to', true );
			}
			if ( ! $from || ! $to ) {
				$from = $item->get_meta( 'From', true );
				$to   = $item->get_meta( 'To', true );
			}
			$nights = vila_akoya_booking_nights_ymd_from_dmy_range( (string) $from, (string) $to );
			if ( empty( $nights ) ) {
				continue;
			}
			$name = $order->get_formatted_billing_full_name();
			if ( '' === $name ) {
				$name = '#' . $order->get_order_number();
			}
			$segments[] = array(
				'order_id' => (int) $order->get_id(),
				'nights'   => $nights,
				'label'    => $name,
			);
		}
	}

	return $segments;
}

/**
 * Admin page callback.
 */
function vila_akoya_render_campsite_plots_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$plot_ids = vila_akoya_get_campsite_plot_product_ids();
	if ( empty( $plot_ids ) ) {
		echo '<div class="wrap"><h1>' . esc_html__( 'Campsite plots', 'vila-akoya' ) . '</h1>';
		echo '<p>' . esc_html__( 'No plot products are registered yet. Visit the storefront once or run the theme seed so campsite plot products are created.', 'vila-akoya' ) . '</p></div>';
		return;
	}

	$tz   = wp_timezone();
	$base = new DateTime( 'now', $tz );
	$base->setTime( 0, 0, 0 );
	if ( isset( $_GET['vila_date'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = wp_unslash( vila_akoya_scalar_request_string( $_GET['vila_date'] ) );
		$d   = sanitize_text_field( $raw );
		$try = DateTime::createFromFormat( 'Y-m-d', $d, $tz );
		if ( $try ) {
			$base = $try;
		}
	}

	$day_labels = array();
	for ( $i = 0; $i < 14; $i++ ) {
		$d = clone $base;
		$d->modify( '+' . $i . ' days' );
		$day_labels[] = array(
			'ymd' => $d->format( 'Y-m-d' ),
			'dow' => $d->format( 'D' ),
			'day' => $d->format( 'j' ),
			'mon' => $d->format( 'M' ),
		);
	}

	$today_ymd = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );

	$plot_rows = array();
	foreach ( $plot_ids as $idx => $pid ) {
		$n        = (int) $idx + 1;
		$segments = vila_akoya_campsite_plot_booking_segments_for_product( $pid );
		$plot_rows[] = array(
			'id'       => (int) $pid,
			'title'    => sprintf(
				/* translators: %d: plot number */
				__( 'Plot %d', 'vila-akoya' ),
				$n
			),
			'edit_url' => get_edit_post_link( $pid, '' ),
			'segments' => $segments,
		);
	}

	$arriving_today  = 0;
	$departing_today = 0;
	$staying_tonight = 0;
	$orders_today    = array();

	foreach ( $plot_rows as $row ) {
		foreach ( $row['segments'] as $seg ) {
			$nights = $seg['nights'];
			if ( empty( $nights ) ) {
				continue;
			}
			sort( $nights );
			$first = $nights[0];
			$last  = $nights[ count( $nights ) - 1 ];
			if ( $first === $today_ymd ) {
				++$arriving_today;
			}
			$checkout_day = ( new DateTime( $last, $tz ) )->modify( '+1 day' )->format( 'Y-m-d' );
			if ( $checkout_day === $today_ymd ) {
				++$departing_today;
			}
			if ( in_array( $today_ymd, $nights, true ) ) {
				++$staying_tonight;
			}
			if ( $first === $today_ymd || $checkout_day === $today_ymd || in_array( $today_ymd, $nights, true ) ) {
				$orders_today[ $seg['order_id'] ] = true;
			}
		}
	}

	$bookings_today = count( $orders_today );

	wp_enqueue_style( 'vila-akoya-campsite-admin', VILA_AKOYA_URI . '/assets/css/campsite-plots-admin.css', array(), VILA_AKOYA_VERSION );

	$prev = clone $base;
	$prev->modify( '-14 days' );
	$next = clone $base;
	$next->modify( '+14 days' );

	?>
	<div class="wrap vila-campsite-plots-admin">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Campsite plots', 'vila-akoya' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'Each plot is a separate bookable product with its own calendar. This view summarises orders that include booking dates from Bookings for WooCommerce.', 'vila-akoya' ); ?>
		</p>

		<div class="vila-campsite-kpis">
			<div class="vila-campsite-kpi vila-campsite-kpi--arrive">
				<span class="vila-campsite-kpi__label"><?php esc_html_e( 'Arriving today', 'vila-akoya' ); ?></span>
				<strong class="vila-campsite-kpi__val"><?php echo esc_html( (string) (int) $arriving_today ); ?></strong>
			</div>
			<div class="vila-campsite-kpi vila-campsite-kpi--depart">
				<span class="vila-campsite-kpi__label"><?php esc_html_e( 'Departing today', 'vila-akoya' ); ?></span>
				<strong class="vila-campsite-kpi__val"><?php echo esc_html( (string) (int) $departing_today ); ?></strong>
			</div>
			<div class="vila-campsite-kpi vila-campsite-kpi--stay">
				<span class="vila-campsite-kpi__label"><?php esc_html_e( 'Staying tonight', 'vila-akoya' ); ?></span>
				<strong class="vila-campsite-kpi__val"><?php echo esc_html( (string) (int) $staying_tonight ); ?></strong>
			</div>
			<div class="vila-campsite-kpi vila-campsite-kpi--book">
				<span class="vila-campsite-kpi__label"><?php esc_html_e( 'Orders with activity today', 'vila-akoya' ); ?></span>
				<strong class="vila-campsite-kpi__val"><?php echo esc_html( (string) (int) $bookings_today ); ?></strong>
			</div>
		</div>

		<form method="get" class="vila-campsite-plots-nav">
			<input type="hidden" name="page" value="vila-akoya-campsite-plots" />
			<button type="submit" name="vila_date" value="<?php echo esc_attr( $prev->format( 'Y-m-d' ) ); ?>" class="button"><?php esc_html_e( '← Previous', 'vila-akoya' ); ?></button>
			<strong><?php echo esc_html( $base->format( 'd M Y' ) ); ?></strong>
			<button type="submit" name="vila_date" value="<?php echo esc_attr( $next->format( 'Y-m-d' ) ); ?>" class="button"><?php esc_html_e( 'Next →', 'vila-akoya' ); ?></button>
		</form>

		<table class="widefat striped vila-campsite-grid">
			<thead>
				<tr>
					<th class="vila-campsite-grid__plot"><?php esc_html_e( 'Plot', 'vila-akoya' ); ?></th>
					<?php foreach ( $day_labels as $dl ) : ?>
						<th class="vila-campsite-grid__day">
							<span class="vila-campsite-grid__dow"><?php echo esc_html( $dl['dow'] ); ?></span>
							<span class="vila-campsite-grid__num"><?php echo esc_html( $dl['day'] ); ?></span>
							<span class="vila-campsite-grid__mon"><?php echo esc_html( $dl['mon'] ); ?></span>
						</th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $plot_rows as $row ) : ?>
					<tr>
						<td class="vila-campsite-grid__plot">
							<a href="<?php echo esc_url( (string) $row['edit_url'] ); ?>"><?php echo esc_html( (string) $row['title'] ); ?></a>
						</td>
						<?php foreach ( $day_labels as $dl ) : ?>
							<?php
							$ymd   = $dl['ymd'];
							$cell  = __( '—', 'vila-akoya' );
							$class = '';
							foreach ( $row['segments'] as $seg ) {
								if ( in_array( $ymd, $seg['nights'], true ) ) {
									$cell  = mb_substr( (string) $seg['label'], 0, 18 );
									$class = 'vila-campsite-grid__busy';
									break;
								}
							}
							?>
							<td class="<?php echo esc_attr( $class ); ?>" title="<?php echo esc_attr( $ymd ); ?>">
								<?php echo esc_html( $cell ); ?>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
