<?php
/**
 * wp-admin childcare operations board.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce → Campsite · Childcare.
 */
class Childcare_Admin {

	const PAGE = 'vila-cb-childcare';

	/**
	 * Boot hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 61 );
		add_action( 'admin_post_vila_cb_childcare_absent', array( __CLASS__, 'handle_absent' ) );
	}

	/**
	 * Register submenu.
	 */
	public static function register_menu() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_submenu_page(
			'woocommerce',
			__( 'Campsite childcare', 'vila-campsite-booking' ),
			__( 'Campsite · Childcare', 'vila-campsite-booking' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Mark a child absent from wp-admin.
	 */
	public static function handle_absent() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage childcare.', 'vila-campsite-booking' ) );
		}
		check_admin_referer( 'vila_cb_childcare_absent' );
		$place_id = isset( $_POST['place_id'] ) ? absint( wp_unslash( Request_String::scalar( $_POST['place_id'] ) ) ) : 0;
		$result   = Childcare_Service::mark_absent_by_id( $place_id );
		$date     = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( Request_String::scalar( $_POST['date'] ) ) ) : wp_date( 'Y-m-d' );
		$args     = array(
			'page'              => self::PAGE,
			'vila_cb_childcare' => $date,
			'marked_absent'     => is_wp_error( $result ) ? '0' : '1',
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render 14-day childcare board.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		wp_enqueue_style( 'vila-cb-admin', VILA_CB_URL . 'assets/css/admin.css', array(), VILA_CB_VERSION );

		$tz   = wp_timezone();
		$base = new \DateTime( 'now', $tz );
		$base->setTime( 0, 0, 0 );
		if ( isset( $_GET['vila_cb_childcare'] ) ) {
			$raw = wp_unslash( Request_String::scalar( $_GET['vila_cb_childcare'] ) );
			$try = Dates::parse_ymd( sanitize_text_field( $raw ) );
			if ( $try ) {
				$base = new \DateTime( $try->format( 'Y-m-d' ), $tz );
			}
		}

		$from   = $base->format( 'Y-m-d' );
		$to     = ( clone $base )->modify( '+13 days' )->format( 'Y-m-d' );
		$prev   = ( clone $base )->modify( '-14 days' )->format( 'Y-m-d' );
		$nxt    = ( clone $base )->modify( '+14 days' )->format( 'Y-m-d' );
		$plan   = Childcare_Service::get_range( $from, $to );
		$days   = isset( $plan['days'] ) ? (array) $plan['days'] : array();
		$totals = isset( $plan['totals'] ) ? (array) $plan['totals'] : array();

		$period_label = sprintf(
			/* translators: 1: start date, 2: end date */
			__( '%1$s – %2$s', 'vila-campsite-booking' ),
			$base->format( self::wp_date_format_pattern() ),
			( clone $base )->modify( '+13 days' )->format( self::wp_date_format_pattern() )
		);
		?>
		<div class="wrap vila-cb-admin vila-cb-childcare-admin">
			<?php Admin::render_section_nav( self::PAGE ); ?>
			<div class="vila-cb-ui-hero">
				<div class="vila-cb-ui-hero__text">
					<h1 class="vila-cb-ui-title"><?php esc_html_e( 'Campsite · Childcare', 'vila-campsite-booking' ); ?></h1>
					<p class="vila-cb-ui-lead"><?php esc_html_e( 'Daily childcare roster with parent contacts, allergies, capacity, and absences. Marking absent frees the place but does not refund the paid order.', 'vila-campsite-booking' ); ?></p>
				</div>
				<form method="get" class="vila-cb-daynav vila-cb-ui-nav">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
					<button type="submit" class="button vila-cb-ui-nav-btn" name="vila_cb_childcare" value="<?php echo esc_attr( $prev ); ?>">
						<span class="vila-cb-ui-nav-arrow" aria-hidden="true">‹</span>
						<span><?php esc_html_e( 'Prev', 'vila-campsite-booking' ); ?></span>
					</button>
					<div class="vila-cb-ui-nav-period">
						<span class="vila-cb-ui-nav-month"><?php esc_html_e( '14 days', 'vila-campsite-booking' ); ?></span>
						<span class="vila-cb-ui-nav-sub"><?php echo esc_html( $period_label ); ?></span>
					</div>
					<button type="submit" class="button vila-cb-ui-nav-btn vila-cb-ui-nav-btn--primary" name="vila_cb_childcare" value="<?php echo esc_attr( $nxt ); ?>">
						<span><?php esc_html_e( 'Next', 'vila-campsite-booking' ); ?></span>
						<span class="vila-cb-ui-nav-arrow" aria-hidden="true">›</span>
					</button>
				</form>
			</div>

			<div class="vila-cb-kpis">
				<div class="vila-cb-kpi vila-cb-kpi--childcare">
					<span class="vila-cb-kpi__label"><?php esc_html_e( 'Booked children', 'vila-campsite-booking' ); ?></span>
					<strong class="vila-cb-kpi__val"><?php echo esc_html( (string) (int) ( $totals['booked_children'] ?? 0 ) ); ?></strong>
				</div>
				<div class="vila-cb-kpi">
					<span class="vila-cb-kpi__label"><?php esc_html_e( 'Absent children', 'vila-campsite-booking' ); ?></span>
					<strong class="vila-cb-kpi__val"><?php echo esc_html( (string) (int) ( $totals['absent_children'] ?? 0 ) ); ?></strong>
				</div>
				<div class="vila-cb-kpi">
					<span class="vila-cb-kpi__label"><?php esc_html_e( 'Days with childcare', 'vila-campsite-booking' ); ?></span>
					<strong class="vila-cb-kpi__val"><?php echo esc_html( (string) (int) ( $totals['days_with_care'] ?? 0 ) ); ?></strong>
				</div>
			</div>

			<div class="vila-cb-meal-days vila-cb-childcare-days">
				<?php foreach ( $days as $day ) : ?>
					<?php
					$classes = 'vila-cb-meal-day vila-cb-childcare-day';
					$classes .= ! empty( $day['is_today'] ) ? ' is-today' : '';
					$classes .= empty( $day['open'] ) ? ' is-closed' : '';
					$children = isset( $day['children'] ) ? (array) $day['children'] : array();
					$absent   = isset( $day['absent_children'] ) ? (array) $day['absent_children'] : array();
					?>
					<section class="<?php echo esc_attr( $classes ); ?>">
						<header class="vila-cb-meal-day__head">
							<div>
								<span class="vila-cb-meal-day__dow"><?php echo esc_html( (string) $day['label'] ); ?></span>
								<span class="vila-cb-meal-day__date"><?php echo esc_html( (string) $day['date_label'] ); ?></span>
							</div>
							<div class="vila-cb-meal-day__totals">
								<span class="vila-cb-meal-pill vila-cb-meal-pill--b"><?php echo esc_html( sprintf( __( '%1$d / %2$d booked', 'vila-campsite-booking' ), (int) $day['booked_count'], (int) $day['capacity'] ) ); ?></span>
								<?php if ( empty( $day['open'] ) ) : ?>
									<span class="vila-cb-meal-pill vila-cb-meal-pill--off"><?php esc_html_e( 'Closed', 'vila-campsite-booking' ); ?></span>
								<?php elseif ( ! empty( $day['cutoff_blocked'] ) ) : ?>
									<span class="vila-cb-meal-pill vila-cb-meal-pill--off"><?php esc_html_e( 'Booking closed', 'vila-campsite-booking' ); ?></span>
								<?php endif; ?>
							</div>
						</header>
						<div class="vila-cb-meal-col">
							<?php if ( empty( $children ) && empty( $absent ) ) : ?>
								<p class="vila-cb-meal-col__empty"><?php esc_html_e( 'No children booked.', 'vila-campsite-booking' ); ?></p>
							<?php else : ?>
								<ul class="vila-cb-meal-parties vila-cb-childcare-roster">
									<?php foreach ( $children as $child ) : ?>
										<?php self::render_child_row( $child, (string) $day['ymd'], false ); ?>
									<?php endforeach; ?>
									<?php foreach ( $absent as $child ) : ?>
										<?php self::render_child_row( $child, (string) $day['ymd'], true ); ?>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>
					</section>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $child Child row.
	 * @param string               $ymd Date.
	 * @param bool                 $absent Is absent.
	 */
	private static function render_child_row( array $child, $ymd, $absent ) {
		$order_id = isset( $child['order_id'] ) ? (int) $child['order_id'] : 0;
		?>
		<li class="vila-cb-meal-party vila-cb-childcare-child<?php echo $absent ? ' is-absent' : ''; ?>">
			<div>
				<strong class="vila-cb-meal-party__name">
					<?php echo esc_html( (string) ( $child['child_name'] ?? '' ) ); ?>
					<?php echo $absent ? ' · ' . esc_html__( 'Absent', 'vila-campsite-booking' ) : ''; ?>
				</strong>
				<span class="vila-cb-meal-party__meta">
					<?php echo esc_html( sprintf( __( 'Age %d', 'vila-campsite-booking' ), (int) ( $child['child_age'] ?? 0 ) ) ); ?>
					<?php if ( ! empty( $child['allergies'] ) ) : ?>
						<br /><?php echo esc_html( sprintf( __( 'Allergies / medical: %s', 'vila-campsite-booking' ), (string) $child['allergies'] ) ); ?>
					<?php endif; ?>
					<br /><?php echo esc_html( sprintf( __( 'Parent: %1$s · %2$s', 'vila-campsite-booking' ), (string) ( $child['parent_name'] ?? '' ), (string) ( $child['parent_phone'] ?? '' ) ) ); ?>
					<?php if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) : ?>
						<?php $order = wc_get_order( $order_id ); ?>
						<?php if ( $order instanceof \WC_Order ) : ?>
							<br /><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"><?php echo esc_html( sprintf( __( 'Order #%s', 'vila-campsite-booking' ), $order->get_order_number() ) ); ?></a>
						<?php endif; ?>
					<?php endif; ?>
				</span>
			</div>
			<?php if ( ! $absent ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="vila-cb-childcare-absent-form">
					<?php wp_nonce_field( 'vila_cb_childcare_absent' ); ?>
					<input type="hidden" name="action" value="vila_cb_childcare_absent" />
					<input type="hidden" name="place_id" value="<?php echo esc_attr( (string) (int) ( $child['id'] ?? 0 ) ); ?>" />
					<input type="hidden" name="date" value="<?php echo esc_attr( $ymd ); ?>" />
					<button type="submit" class="button button-small"><?php esc_html_e( 'Mark absent', 'vila-campsite-booking' ); ?></button>
				</form>
			<?php endif; ?>
		</li>
		<?php
	}

	/**
	 * @return string
	 */
	private static function wp_date_format_pattern() {
		$df = get_option( 'date_format' );
		return ( is_string( $df ) && '' !== $df ) ? $df : 'Y-m-d';
	}
}
