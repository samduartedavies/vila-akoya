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
	const GUEST_REGISTRATION_SHORTCODE = 'vila_guest_registration';
	const MEAL_BOOKING_SHORTCODE = 'vila_meal_booking';

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_shortcode( self::GUEST_REGISTRATION_SHORTCODE, array( __CLASS__, 'render_guest_registration' ) );
		add_shortcode( self::MEAL_BOOKING_SHORTCODE, array( __CLASS__, 'render_meal_booking' ) );
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
		wp_register_script(
			'vila-cb-guest-registration',
			VILA_CB_URL . 'assets/js/guest-registration.js',
			array(),
			VILA_CB_VERSION,
			true
		);
		wp_register_script(
			'vila-cb-meal-booking',
			VILA_CB_URL . 'assets/js/meal-booking.js',
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
	 * @return string
	 */
	public static function render_guest_registration() {
		wp_enqueue_style( 'vila-cb-frontend' );
		wp_enqueue_script( 'vila-cb-guest-registration' );

		$lookup_url = esc_url_raw( rest_url( 'vila-campsite-booking/v1/guest-registration/lookup' ) );
		$submit_url = esc_url_raw( rest_url( 'vila-campsite-booking/v1/guest-registration' ) );
		// wp_rest + X-WP-Nonce: without it REST clears the logged-in user and nonce checks fail for admins.
		$nonce      = wp_create_nonce( 'wp_rest' );

		wp_localize_script(
			'vila-cb-guest-registration',
			'vilaGuestRegistration',
			array(
				'lookupUrl' => $lookup_url,
				'submitUrl' => $submit_url,
				'nonce'     => $nonce,
				'strings'   => array(
					'searching'     => __( 'Searching bookings...', 'vila-campsite-booking' ),
					'noBookings'    => __( 'No matching booking found. You can enter the stay details manually.', 'vila-campsite-booking' ),
					'selectBooking' => __( 'Use this booking', 'vila-campsite-booking' ),
					'guestHeading'  => __( 'Guest', 'vila-campsite-booking' ),
					'saved'         => __( 'Thank you. Your legal guest registration details have been saved.', 'vila-campsite-booking' ),
					'failed'        => __( 'Could not save the registration. Please check the fields and try again.', 'vila-campsite-booking' ),
					'required'      => __( 'Please complete the required fields.', 'vila-campsite-booking' ),
				),
			)
		);

		ob_start();
		?>
		<div
			class="vila-cb vila-cb-gr"
			data-vila-gr-root
			data-lookup-url="<?php echo esc_url( $lookup_url ); ?>"
			data-submit-url="<?php echo esc_url( $submit_url ); ?>"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
		>
			<div class="vila-cb-gr__intro">
				<p class="vila-cb__hint"><?php esc_html_e( 'Portuguese law requires us to collect guest details for SIBA before or at check-in. Search for your booking, or enter your stay details manually.', 'vila-campsite-booking' ); ?></p>
			</div>

			<section class="vila-cb-gr__panel" data-vila-gr-lookup-panel>
				<h2 class="vila-cb-gr__title"><?php esc_html_e( 'Find your booking', 'vila-campsite-booking' ); ?></h2>
				<form class="vila-cb-gr__lookup" data-vila-gr-lookup-form>
					<label>
						<span><?php esc_html_e( 'Booking last name', 'vila-campsite-booking' ); ?></span>
						<input type="text" name="last_name" autocomplete="family-name" required />
					</label>
					<label>
						<span><?php esc_html_e( 'Booking email', 'vila-campsite-booking' ); ?></span>
						<input type="email" name="email" autocomplete="email" required />
					</label>
					<button type="submit" class="vila-cb__cta"><?php esc_html_e( 'Search', 'vila-campsite-booking' ); ?></button>
				</form>
				<div class="vila-cb-gr__results" data-vila-gr-results></div>
				<button type="button" class="vila-cb-gr__manual" data-vila-gr-manual><?php esc_html_e( 'Enter stay details manually instead', 'vila-campsite-booking' ); ?></button>
			</section>

			<form class="vila-cb-gr__form" data-vila-gr-form hidden>
				<input type="text" name="website" value="" class="vila-cb-gr__hp" tabindex="-1" autocomplete="off" aria-hidden="true" />
				<input type="hidden" name="booking_id" value="0" data-vila-gr-booking-id />
				<input type="hidden" name="order_id" value="0" data-vila-gr-order-id />

				<section class="vila-cb-gr__panel">
					<h2 class="vila-cb-gr__title"><?php esc_html_e( 'Stay details', 'vila-campsite-booking' ); ?></h2>
					<p class="vila-cb-gr__selected" data-vila-gr-selected></p>
					<div class="vila-cb-gr__manual-fields" data-vila-gr-manual-fields hidden>
						<label>
							<span><?php esc_html_e( 'Booker name', 'vila-campsite-booking' ); ?></span>
							<input type="text" name="booker_name" autocomplete="name" />
						</label>
						<label>
							<span><?php esc_html_e( 'Contact email', 'vila-campsite-booking' ); ?></span>
							<input type="email" name="contact_email" autocomplete="email" />
						</label>
						<label>
							<span><?php esc_html_e( 'Check-in', 'vila-campsite-booking' ); ?></span>
							<input type="date" name="check_in" />
						</label>
						<label>
							<span><?php esc_html_e( 'Check-out', 'vila-campsite-booking' ); ?></span>
							<input type="date" name="check_out" />
						</label>
					</div>
				</section>

				<section class="vila-cb-gr__panel">
					<div class="vila-cb-gr__guest-tools">
						<h2 class="vila-cb-gr__title"><?php esc_html_e( 'Guests', 'vila-campsite-booking' ); ?></h2>
						<label>
							<span><?php esc_html_e( 'Number of people', 'vila-campsite-booking' ); ?></span>
							<input type="number" min="1" max="20" value="1" data-vila-gr-count />
						</label>
					</div>
					<div data-vila-gr-persons></div>
				</section>

				<div class="vila-cb-gr__actions">
					<button type="submit" class="vila-cb__cta"><?php esc_html_e( 'Save guest registration', 'vila-campsite-booking' ); ?></button>
				</div>
			</form>

			<div class="vila-cb-gr__message" data-vila-gr-message role="status" aria-live="polite"></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Public meal booking / payment form.
	 *
	 * @return string
	 */
	public static function render_meal_booking() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return '';
		}
		$product_id = Meal_Booking::ensure_product();
		if ( $product_id < 1 ) {
			return '<p class="vila-cb-msg vila-cb-msg--error">' . esc_html__( 'Meal booking is not available yet.', 'vila-campsite-booking' ) . '</p>';
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return '<p class="vila-cb-msg vila-cb-msg--error">' . esc_html__( 'Meal booking is not available yet.', 'vila-campsite-booking' ) . '</p>';
		}

		wp_enqueue_style( 'vila-cb-frontend' );
		wp_enqueue_script( 'vila-cb-meal-booking' );

		$lookup_url = esc_url_raw( rest_url( 'vila-campsite-booking/v1/guest-registration/lookup' ) );
		$nonce      = wp_create_nonce( 'wp_rest' );
		$checkout   = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' );

		$catalog = function_exists( 'vila_akoya_get_meal_options' ) ? vila_akoya_get_meal_options() : array();
		$pricing = array(
			'breakfast' => array(
				'adult' => isset( $catalog['breakfast']['adult_fee'] ) ? (float) $catalog['breakfast']['adult_fee'] : 5.0,
				'child' => isset( $catalog['breakfast']['child_fee'] ) ? (float) $catalog['breakfast']['child_fee'] : 3.0,
				'label' => isset( $catalog['breakfast']['label'] ) ? (string) $catalog['breakfast']['label'] : __( 'Bio breakfast & coffee', 'vila-campsite-booking' ),
			),
			'dinner'    => array(
				'adult' => isset( $catalog['dinner']['adult_fee'] ) ? (float) $catalog['dinner']['adult_fee'] : 10.0,
				'child' => isset( $catalog['dinner']['child_fee'] ) ? (float) $catalog['dinner']['child_fee'] : 7.5,
				'label' => isset( $catalog['dinner']['label'] ) ? (string) $catalog['dinner']['label'] : __( 'Weekday dinner (Mon–Fri)', 'vila-campsite-booking' ),
			),
		);

		$currency = array(
			'symbol'     => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '€',
			'decimalSep' => function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : ',',
		);

		wp_localize_script(
			'vila-cb-meal-booking',
			'vilaMealBooking',
			array(
				'lookupUrl' => $lookup_url,
				'nonce'     => $nonce,
				'checkout'  => $checkout,
				'pricing'   => $pricing,
				'currency'  => $currency,
				'strings'   => array(
					'searching'     => __( 'Searching bookings…', 'vila-campsite-booking' ),
					'noBookings'    => __( 'No matching booking found. You can book meals without linking a stay.', 'vila-campsite-booking' ),
					'selectBooking' => __( 'Use this booking', 'vila-campsite-booking' ),
					'linked'        => __( 'Linked to your stay — dates for your nights are pre-selected. Untick any you do not need.', 'vila-campsite-booking' ),
					'standalone'    => __( 'Walk-in meal booking (not linked to a campsite stay).', 'vila-campsite-booking' ),
					'needDates'     => __( 'Select at least one breakfast or dinner date.', 'vila-campsite-booking' ),
					'needGuests'    => __( 'Add at least one adult or child.', 'vila-campsite-booking' ),
					'needName'      => __( 'Please enter your name.', 'vila-campsite-booking' ),
					'total'         => __( 'Total', 'vila-campsite-booking' ),
					'breakdownEmpty'=> __( 'Select breakfast or dinner dates above to see your estimate.', 'vila-campsite-booking' ),
					'mornings'      => __( 'mornings', 'vila-campsite-booking' ),
					'evenings'      => __( 'evenings', 'vila-campsite-booking' ),
					'perDay'        => __( '%s / day', 'vila-campsite-booking' ),
					'adult'         => __( 'adult', 'vila-campsite-booking' ),
					'adults'        => __( 'adults', 'vila-campsite-booking' ),
					'child'         => __( 'child', 'vila-campsite-booking' ),
					'children'      => __( 'children', 'vila-campsite-booking' ),
				),
			)
		);

		$action = esc_url( get_permalink( $product_id ) );
		if ( ! $action ) {
			$action = esc_url( home_url( '/' ) );
		}

		ob_start();
		?>
		<div
			class="vila-cb vila-cb-gr vila-cb-mb"
			data-vila-mb-root
			data-lookup-url="<?php echo esc_url( $lookup_url ); ?>"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
			data-pricing="<?php echo esc_attr( wp_json_encode( $pricing ) ); ?>"
			data-currency="<?php echo esc_attr( wp_json_encode( $currency ) ); ?>"
		>
			<div class="vila-cb-gr__intro">
				<p class="vila-cb__hint"><?php esc_html_e( 'Book breakfast and weekday dinners for your stay — find your campsite booking to pre-fill dates, or book meals on their own.', 'vila-campsite-booking' ); ?></p>
			</div>

			<section class="vila-cb-gr__panel" data-vila-mb-lookup-panel>
				<h2 class="vila-cb-gr__title"><?php esc_html_e( 'Find your booking', 'vila-campsite-booking' ); ?></h2>
				<form class="vila-cb-gr__lookup" data-vila-mb-lookup-form>
					<label>
						<span><?php esc_html_e( 'Booking last name', 'vila-campsite-booking' ); ?></span>
						<input type="text" name="last_name" autocomplete="family-name" required />
					</label>
					<label>
						<span><?php esc_html_e( 'Booking email', 'vila-campsite-booking' ); ?></span>
						<input type="email" name="email" autocomplete="email" required />
					</label>
					<button type="submit" class="vila-cb__cta"><?php esc_html_e( 'Search', 'vila-campsite-booking' ); ?></button>
				</form>
				<div class="vila-cb-gr__results" data-vila-mb-results></div>
				<button type="button" class="vila-cb-gr__manual" data-vila-mb-standalone><?php esc_html_e( 'Book meals without a campsite booking', 'vila-campsite-booking' ); ?></button>
			</section>

			<form class="vila-cb-gr__form" method="post" action="<?php echo esc_url( $action ); ?>" data-vila-mb-form hidden>
				<?php wp_nonce_field( 'vila_meal_booking', 'vila_meal_booking_nonce' ); ?>
				<input type="hidden" name="add-to-cart" value="<?php echo esc_attr( (string) $product_id ); ?>" />
				<input type="hidden" name="quantity" value="1" />
				<input type="hidden" name="vila_meal_booking_id" value="0" data-vila-mb-booking-id />
				<input type="hidden" name="vila_meal_order_id" value="0" data-vila-mb-order-id />

				<section class="vila-cb-gr__panel">
					<h2 class="vila-cb-gr__title"><?php esc_html_e( 'Your details', 'vila-campsite-booking' ); ?></h2>
					<p class="vila-cb-gr__selected" data-vila-mb-selected></p>
					<div class="vila-cb-gr__manual-fields" data-vila-mb-details>
						<label>
							<span><?php esc_html_e( 'Name', 'vila-campsite-booking' ); ?></span>
							<input type="text" name="vila_meal_booker_name" autocomplete="name" required data-vila-mb-name />
						</label>
						<label>
							<span><?php esc_html_e( 'Email', 'vila-campsite-booking' ); ?></span>
							<input type="email" name="vila_meal_contact_email" autocomplete="email" data-vila-mb-email />
						</label>
					</div>
				</section>

				<section class="vila-cb-gr__panel">
					<h2 class="vila-cb-gr__title"><?php esc_html_e( 'How many people', 'vila-campsite-booking' ); ?></h2>
					<div class="vila-cb-mb__guests">
						<?php echo self::guest_row( 'adults', __( 'Adults', 'vila-campsite-booking' ), __( 'Ages 13+', 'vila-campsite-booking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo self::guest_row( 'children', __( 'Children', 'vila-campsite-booking' ), __( 'Ages 2–12', 'vila-campsite-booking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo self::guest_row( 'infants', __( 'Under 2', 'vila-campsite-booking' ), __( 'Free for meals', 'vila-campsite-booking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
					<input type="hidden" name="vila_meal_adults" value="1" data-vila-mb-adults />
					<input type="hidden" name="vila_meal_children" value="0" data-vila-mb-children />
					<input type="hidden" name="vila_meal_infants" value="0" data-vila-mb-infants />
					<div class="vila-cb-mb__rates" aria-label="<?php esc_attr_e( 'Meal prices', 'vila-campsite-booking' ); ?>">
						<div class="vila-cb-mb__rate">
							<strong class="vila-cb-mb__rate-title"><?php echo esc_html( $pricing['breakfast']['label'] ); ?></strong>
							<span class="vila-cb-mb__rate-meta"><?php echo esc_html( self::meal_rate_blurb( 'breakfast', $pricing, $catalog ) ); ?></span>
						</div>
						<div class="vila-cb-mb__rate">
							<strong class="vila-cb-mb__rate-title"><?php echo esc_html( $pricing['dinner']['label'] ); ?></strong>
							<span class="vila-cb-mb__rate-meta"><?php echo esc_html( self::meal_rate_blurb( 'dinner', $pricing, $catalog ) ); ?></span>
						</div>
					</div>
				</section>

				<section class="vila-cb-gr__panel">
					<h2 class="vila-cb-gr__title"><?php echo esc_html( $pricing['breakfast']['label'] ); ?></h2>
					<p class="vila-cb__hint"><?php esc_html_e( 'Tap days on the calendar to add or remove them — you can select multiple mornings.', 'vila-campsite-booking' ); ?></p>
					<div class="vila-cb-mb__dates" data-vila-mb-breakfast-dates></div>
					<div class="vila-cb-mb__calendar" data-vila-mb-calendar="breakfast" aria-label="<?php esc_attr_e( 'Breakfast dates calendar', 'vila-campsite-booking' ); ?>"></div>
				</section>

				<section class="vila-cb-gr__panel">
					<h2 class="vila-cb-gr__title"><?php echo esc_html( $pricing['dinner']['label'] ); ?></h2>
					<p class="vila-cb__hint"><?php esc_html_e( 'Tap weekday evenings (Monday–Friday) on the calendar — select as many as you need.', 'vila-campsite-booking' ); ?></p>
					<div class="vila-cb-mb__dates" data-vila-mb-dinner-dates></div>
					<div class="vila-cb-mb__calendar" data-vila-mb-calendar="dinner" aria-label="<?php esc_attr_e( 'Dinner dates calendar', 'vila-campsite-booking' ); ?>"></div>
				</section>

				<section class="vila-cb-gr__panel vila-cb-mb__summary">
					<div class="vila-cb-mb__bill">
						<ul class="vila-cb-mb__breakdown" data-vila-mb-breakdown aria-live="polite"></ul>
						<p class="vila-cb-mb__total">
							<span class="vila-cb-mb__total-label"><?php esc_html_e( 'Total', 'vila-campsite-booking' ); ?></span>
							<span class="vila-cb-mb__total-amount" data-vila-mb-total>€0</span>
						</p>
					</div>
					<button type="submit" class="vila-cb__cta"><?php esc_html_e( 'Continue to payment', 'vila-campsite-booking' ); ?></button>
				</section>
			</form>

			<div class="vila-cb-gr__message" data-vila-mb-message role="status" aria-live="polite"></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Human-readable per-person meal rates for the booking form.
	 *
	 * @param string               $slug    breakfast|dinner.
	 * @param array<string, mixed> $pricing Normalized pricing array.
	 * @param array<string, mixed> $catalog Theme meal catalog.
	 * @return string
	 */
	private static function meal_rate_blurb( $slug, array $pricing, array $catalog ) {
		if ( isset( $catalog[ $slug ]['meta'] ) && is_string( $catalog[ $slug ]['meta'] ) && '' !== trim( $catalog[ $slug ]['meta'] ) ) {
			return (string) $catalog[ $slug ]['meta'];
		}
		$row = isset( $pricing[ $slug ] ) && is_array( $pricing[ $slug ] ) ? $pricing[ $slug ] : array();
		$adult = isset( $row['adult'] ) ? (float) $row['adult'] : 0.0;
		$child = isset( $row['child'] ) ? (float) $row['child'] : 0.0;
		$fmt   = static function ( $amount ) {
			if ( function_exists( 'vila_akoya_format_price_plain' ) ) {
				return vila_akoya_format_price_plain( $amount );
			}
			$n = round( (float) $amount, 2 );
			if ( abs( $n - round( $n ) ) < 0.00001 ) {
				return '€' . (string) (int) round( $n );
			}
			return '€' . number_format( $n, 2, ',', '' );
		};
		if ( 'dinner' === $slug ) {
			return sprintf(
				/* translators: 1: adult price, 2: child price */
				__( 'Adults %1$s · Kids %2$s · Under 2 free', 'vila-campsite-booking' ),
				$fmt( $adult ),
				$fmt( $child )
			);
		}
		return sprintf(
			/* translators: 1: adult price, 2: child price */
			__( 'Adults %1$s · Kids %2$s / day', 'vila-campsite-booking' ),
			$fmt( $adult ),
			$fmt( $child )
		);
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
