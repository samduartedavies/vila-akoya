<?php
/**
 * Shortcode [vila_campsite_booking] and asset enqueue.
 *
 * @package Vila_CB
 */

namespace Vila_CB;

defined( 'ABSPATH' ) || exit;

/**
 * Front-end shortcode.
 */
class Shortcode {

	const SHORTCODE = 'vila_campsite_booking';

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Register scripts (enqueue only when shortcode present).
	 */
	public static function register_assets() {
		wp_register_style(
			'vila-cb-frontend',
			VILA_CB_URL . 'assets/css/frontend.css',
			array(),
			VILA_CB_VERSION
		);
		wp_register_script(
			'vila-cb-frontend',
			VILA_CB_URL . 'assets/js/frontend.js',
			array(),
			VILA_CB_VERSION,
			true
		);
	}

	/**
	 * @param array<string, string> $atts Attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'product_id' => (string) (int) get_option( 'vila_cb_product_id', 0 ),
				'label'      => '',
			),
			$atts,
			self::SHORTCODE
		);

		$product_id = (int) $atts['product_id'];
		if ( ! $product_id || ! WooCommerce_Integration::is_campsite_product( $product_id ) ) {
			return '<p class="vila-cb-msg vila-cb-msg--error">' . esc_html__( 'Campsite booking is not configured yet. Please set a product in WooCommerce → Campsite.', 'vila-campsite-booking' ) . '</p>';
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_purchasable() ) {
			return '<p class="vila-cb-msg vila-cb-msg--error">' . esc_html__( 'The campsite product is not available.', 'vila-campsite-booking' ) . '</p>';
		}

		$where_label = '' !== trim( (string) $atts['label'] )
			? sanitize_text_field( Request_String::scalar( $atts['label'] ) )
			: (string) apply_filters( 'vila_cb_where_display_name', __( 'Campsite stay', 'vila-campsite-booking' ), $product_id, $product );

		$plot_repo = new Plot_Repository();
		$plot_n    = $plot_repo->count();
		if ( $plot_n <= 0 ) {
			$assign_hint = (string) apply_filters(
				'vila_cb_assign_hint',
				__( 'Add pitches under WooCommerce → Campsite → Plots so availability can be calculated.', 'vila-campsite-booking' ),
				$plot_n,
				$product_id
			);
		} elseif ( 1 === $plot_n ) {
			$assign_hint = (string) apply_filters(
				'vila_cb_assign_hint',
				__( 'We\'ll assign the pitch if it\'s free for your dates.', 'vila-campsite-booking' ),
				$plot_n,
				$product_id
			);
		} else {
			$assign_hint = (string) apply_filters(
				'vila_cb_assign_hint',
				sprintf(
					/* translators: %d: number of pitches (e.g. 15) */
					__( 'We\'ll pick the first available pitch for your stay (%d pitches share one availability pool).', 'vila-campsite-booking' ),
					$plot_n
				),
				$plot_n,
				$product_id
			);
		}

		wp_enqueue_style( 'vila-cb-frontend' );
		wp_enqueue_script( 'vila-cb-frontend' );

		$min_a = max( 1, (int) apply_filters( 'vila_cb_min_adults', 1 ) );
		$max_p = max( $min_a, (int) apply_filters( 'vila_cb_max_people', 8 ) );
		$max_t = max( 0, (int) apply_filters( 'vila_cb_max_pets', 5 ) );

		$debug_ui = (bool) apply_filters(
			'vila_cb_booking_form_debug_ui',
			isset( $_GET['vila_cb_debug'] ) && is_user_logged_in() && ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) )
		);

		wp_localize_script(
			'vila-cb-frontend',
			'vilaCampsiteBooking',
			array(
				'productId'      => $product_id,
				'debugUi'        => $debug_ui,
				'restUrl'        => esc_url_raw( rest_url( 'vila-campsite-booking/v1/availability' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'currencySymbol' => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '',
				'strings'        => array(
					'where'           => __( 'Where', 'vila-campsite-booking' ),
					'wherePlaceholder' => $where_label,
					'when'            => __( 'When', 'vila-campsite-booking' ),
					'addDates'        => __( 'Add dates', 'vila-campsite-booking' ),
					'who'             => __( 'Who', 'vila-campsite-booking' ),
					'search'          => __( 'Book', 'vila-campsite-booking' ),
					'assignHint'      => $assign_hint,
					'adults'          => __( 'Adults', 'vila-campsite-booking' ),
					'adultsSub'       => __( 'Ages 13 or above', 'vila-campsite-booking' ),
					'children'        => __( 'Children', 'vila-campsite-booking' ),
					'childrenSub'     => __( 'Ages 2 – 12', 'vila-campsite-booking' ),
					'infants'         => __( 'Babies', 'vila-campsite-booking' ),
					'infantsSub'      => __( 'Under 2', 'vila-campsite-booking' ),
					'pets'            => __( 'Pets', 'vila-campsite-booking' ),
					'petsSub'         => '',
					'clear'           => __( 'Clear', 'vila-campsite-booking' ),
					'flexExact'       => __( 'Exact dates', 'vila-campsite-booking' ),
					'selectStayDates' => __( 'Please select a check-in and check-out date before booking.', 'vila-campsite-booking' ),
				),
				'limits'         => array(
					'minAdults' => $min_a,
					'maxPeople' => $max_p,
					'maxPets'   => $max_t,
					'defaultAdults' => $min_a,
				),
			)
		);

		$action = esc_url( $product->get_permalink() );
		$nonce  = wp_nonce_field( 'vila_cb_add_to_cart', 'vila_cb_nonce', true, false );

		ob_start();
		?>
		<div class="vila-cb" data-vila-cb-root>
			<?php if ( $debug_ui ) : ?>
				<div class="vila-cb-debug-banner" role="status">
					<strong><?php esc_html_e( 'Vila booking debug', 'vila-campsite-booking' ); ?></strong>
					<?php
					echo ' ';
					echo esc_html(
						sprintf(
							/* translators: %s: product ID */
							__( '(product ID %s) — panel below updates live. Add ?vila_cb_debug=1 to the URL while logged in as a shop manager.', 'vila-campsite-booking' ),
							(string) (int) $product_id
						)
					);
					?>
				</div>
			<?php endif; ?>
			<form class="vila-cb__form" method="post" action="<?php echo esc_url( $action ); ?>">
				<input type="hidden" name="add-to-cart" value="<?php echo esc_attr( (string) $product_id ); ?>" />
				<?php echo $nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="vila_cb_check_in" id="vila_cb_check_in" value="" />
				<input type="hidden" name="vila_cb_check_out" id="vila_cb_check_out" value="" />
				<input type="hidden" name="vila_cb_adults" id="vila_cb_adults" value="<?php echo esc_attr( (string) $min_a ); ?>" />
				<input type="hidden" name="vila_cb_children" id="vila_cb_children" value="0" />
				<input type="hidden" name="vila_cb_infants" id="vila_cb_infants" value="0" />
				<input type="hidden" name="vila_cb_pets" id="vila_cb_pets" value="0" />

				<div class="vila-cb__bar" role="group" aria-label="<?php esc_attr_e( 'Booking search', 'vila-campsite-booking' ); ?>">
					<div class="vila-cb__seg vila-cb__seg--where">
						<span class="vila-cb__lbl"><?php esc_html_e( 'Where', 'vila-campsite-booking' ); ?></span>
						<span class="vila-cb__val"><?php echo esc_html( $where_label ); ?></span>
					</div>
					<button type="button" class="vila-cb__seg vila-cb__seg--when is-active" data-vila-cb-tab="when" aria-expanded="true">
						<span class="vila-cb__lbl"><?php esc_html_e( 'When', 'vila-campsite-booking' ); ?></span>
						<span class="vila-cb__val" data-vila-cb-when-label><?php esc_html_e( 'Add dates', 'vila-campsite-booking' ); ?></span>
					</button>
					<button type="button" class="vila-cb__seg vila-cb__seg--who" data-vila-cb-tab="who" aria-expanded="false">
						<span class="vila-cb__lbl"><?php esc_html_e( 'Who', 'vila-campsite-booking' ); ?></span>
						<span class="vila-cb__val" data-vila-cb-who-label><?php esc_html_e( 'Guests', 'vila-campsite-booking' ); ?></span>
					</button>
					<button type="submit" class="vila-cb__cta">
						<span class="vila-cb__cta-text"><?php esc_html_e( 'Book', 'vila-campsite-booking' ); ?></span>
					</button>
				</div>

				<p class="vila-cb__hint"><?php echo esc_html( $assign_hint ); ?></p>

				<div class="vila-cb__popover" data-vila-cb-popover="when" hidden>
					<div class="vila-cb__popover-inner">
						<div class="vila-cb-cal" data-vila-cb-calendar></div>
						<p class="vila-cb__flex vila-cb__flex-row" aria-hidden="true">
							<button type="button" class="vila-cb-pill is-active" data-vila-cb-flex="0"><?php esc_html_e( 'Exact dates', 'vila-campsite-booking' ); ?></button>
						</p>
					</div>
				</div>

				<div class="vila-cb__popover" data-vila-cb-popover="who" hidden>
					<div class="vila-cb__popover-inner vila-cb-guests">
						<?php echo self::guest_row( 'adults', __( 'Adults', 'vila-campsite-booking' ), __( 'Ages 13 or above', 'vila-campsite-booking' ) ); ?>
						<?php echo self::guest_row( 'children', __( 'Children', 'vila-campsite-booking' ), __( 'Ages 2 – 12', 'vila-campsite-booking' ) ); ?>
						<?php echo self::guest_row( 'infants', __( 'Babies', 'vila-campsite-booking' ), __( 'Under 2', 'vila-campsite-booking' ) ); ?>
						<?php echo self::guest_row( 'pets', __( 'Pets', 'vila-campsite-booking' ), '' ); ?>
					</div>
				</div>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param string $slug Slug.
	 * @param string $label Label.
	 * @param string $sub Sub.
	 * @return string
	 */
	private static function guest_row( $slug, $label, $sub ) {
		ob_start();
		?>
		<div class="vila-cb-guests__row" data-vila-guest="<?php echo esc_attr( $slug ); ?>">
			<div class="vila-cb-guests__text">
				<div class="vila-cb-guests__label"><?php echo esc_html( $label ); ?></div>
				<?php if ( $sub ) : ?>
					<div class="vila-cb-guests__sub"><?php echo esc_html( $sub ); ?></div>
				<?php endif; ?>
			</div>
			<div class="vila-cb-stepper">
				<button type="button" class="vila-cb-stepper__btn" data-vila-step="<?php echo esc_attr( $slug ); ?>" data-delta="-1" aria-label="<?php echo esc_attr( __( 'Decrease', 'vila-campsite-booking' ) ); ?>">−</button>
				<span class="vila-cb-stepper__val" data-vila-val="<?php echo esc_attr( $slug ); ?>">0</span>
				<button type="button" class="vila-cb-stepper__btn" data-vila-step="<?php echo esc_attr( $slug ); ?>" data-delta="1" aria-label="<?php echo esc_attr( __( 'Increase', 'vila-campsite-booking' ) ); ?>">+</button>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
