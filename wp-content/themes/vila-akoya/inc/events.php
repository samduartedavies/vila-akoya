<?php
/**
 * Events & workshops — CPT, schedule meta, archive/single UI, inquiry shortcode.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

define( 'VILA_AKOYA_EVENTS_REWRITE_VER', '1' );

/**
 * Register Event post type (archive at /events/).
 *
 * @return void
 */
function vila_akoya_register_event_cpt() {
	register_post_type(
		'event',
		array(
			'labels'              => array(
				'name'               => __( 'Events', 'vila-akoya' ),
				'singular_name'      => __( 'Event', 'vila-akoya' ),
				'add_new'            => __( 'Add New', 'vila-akoya' ),
				'add_new_item'       => __( 'Add New Event', 'vila-akoya' ),
				'edit_item'          => __( 'Edit Event', 'vila-akoya' ),
				'new_item'           => __( 'New Event', 'vila-akoya' ),
				'view_item'          => __( 'View Event', 'vila-akoya' ),
				'search_items'       => __( 'Search Events', 'vila-akoya' ),
				'not_found'          => __( 'No events found', 'vila-akoya' ),
				'not_found_in_trash' => __( 'No events found in Trash', 'vila-akoya' ),
				'all_items'          => __( 'All Events', 'vila-akoya' ),
				'menu_name'          => __( 'Events', 'vila-akoya' ),
			),
			'public'              => true,
			'has_archive'         => 'events',
			'rewrite'             => array(
				'slug'       => 'events',
				'with_front' => false,
			),
			'menu_icon'           => 'dashicons-calendar-alt',
			'menu_position'       => 21,
			'show_in_rest'        => true,
			'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			'exclude_from_search' => false,
		)
	);
}
add_action( 'init', 'vila_akoya_register_event_cpt' );

/**
 * Flush rewrites once after Events CPT is introduced.
 *
 * @return void
 */
function vila_akoya_maybe_flush_events_rewrites() {
	if ( (string) get_option( 'vila_akoya_events_rewrite_ver', '' ) === VILA_AKOYA_EVENTS_REWRITE_VER ) {
		return;
	}
	if ( function_exists( 'vila_akoya_flush_rewrite_rules_hard' ) ) {
		vila_akoya_flush_rewrite_rules_hard();
	} else {
		flush_rewrite_rules( false );
	}
	update_option( 'vila_akoya_events_rewrite_ver', VILA_AKOYA_EVENTS_REWRITE_VER );
}
add_action( 'init', 'vila_akoya_maybe_flush_events_rewrites', 99 );

/**
 * Meta key map for event fields.
 *
 * @return array<string, string>
 */
function vila_akoya_event_meta_keys() {
	return array(
		'schedule_type'       => '_vila_event_schedule_type',
		'start_date'          => '_vila_event_start_date',
		'end_date'            => '_vila_event_end_date',
		'start_time'          => '_vila_event_start_time',
		'end_time'            => '_vila_event_end_time',
		'all_day'             => '_vila_event_all_day',
		'recurrence_freq'     => '_vila_event_recurrence_freq',
		'recurrence_interval' => '_vila_event_recurrence_interval',
		'recurrence_byday'    => '_vila_event_recurrence_byday',
		'recurrence_until'    => '_vila_event_recurrence_until',
		'capacity'            => '_vila_event_capacity',
		'price'               => '_vila_event_price',
		'location'            => '_vila_event_location',
		'kind'                => '_vila_event_kind',
		'reservation_required' => '_vila_event_reservation_required',
		'next_occurrence'     => '_vila_event_next_occurrence',
	);
}

/**
 * Weekday codes used for weekly recurrence.
 *
 * @return array<string, string>
 */
function vila_akoya_event_weekday_labels() {
	return array(
		'MO' => __( 'Monday', 'vila-akoya' ),
		'TU' => __( 'Tuesday', 'vila-akoya' ),
		'WE' => __( 'Wednesday', 'vila-akoya' ),
		'TH' => __( 'Thursday', 'vila-akoya' ),
		'FR' => __( 'Friday', 'vila-akoya' ),
		'SA' => __( 'Saturday', 'vila-akoya' ),
		'SU' => __( 'Sunday', 'vila-akoya' ),
	);
}

/**
 * Read a single event meta value.
 *
 * @param int    $post_id Post ID.
 * @param string $field   Logical field name from vila_akoya_event_meta_keys().
 * @param mixed  $default Default.
 * @return mixed
 */
function vila_akoya_get_event_meta( $post_id, $field, $default = '' ) {
	$keys = vila_akoya_event_meta_keys();
	if ( ! isset( $keys[ $field ] ) ) {
		return $default;
	}
	$value = get_post_meta( (int) $post_id, $keys[ $field ], true );
	if ( '' === $value || null === $value ) {
		return $default;
	}
	return $value;
}

/**
 * Normalized event data for display / sorting.
 *
 * @param int $post_id Post ID.
 * @return array<string, mixed>
 */
function vila_akoya_get_event_data( $post_id ) {
	$post_id = (int) $post_id;
	$byday   = vila_akoya_get_event_meta( $post_id, 'recurrence_byday', '' );
	if ( is_string( $byday ) && '' !== $byday ) {
		$byday = array_values( array_filter( array_map( 'trim', explode( ',', $byday ) ) ) );
	} elseif ( ! is_array( $byday ) ) {
		$byday = array();
	}

	return array(
		'schedule_type'       => (string) vila_akoya_get_event_meta( $post_id, 'schedule_type', 'single' ),
		'start_date'          => (string) vila_akoya_get_event_meta( $post_id, 'start_date', '' ),
		'end_date'            => (string) vila_akoya_get_event_meta( $post_id, 'end_date', '' ),
		'start_time'          => (string) vila_akoya_get_event_meta( $post_id, 'start_time', '' ),
		'end_time'            => (string) vila_akoya_get_event_meta( $post_id, 'end_time', '' ),
		'all_day'             => (bool) (int) vila_akoya_get_event_meta( $post_id, 'all_day', '0' ),
		'recurrence_freq'     => (string) vila_akoya_get_event_meta( $post_id, 'recurrence_freq', 'weekly' ),
		'recurrence_interval' => max( 1, (int) vila_akoya_get_event_meta( $post_id, 'recurrence_interval', '1' ) ),
		'recurrence_byday'    => $byday,
		'recurrence_until'    => (string) vila_akoya_get_event_meta( $post_id, 'recurrence_until', '' ),
		'capacity'            => (int) vila_akoya_get_event_meta( $post_id, 'capacity', '0' ),
		'price'               => (string) vila_akoya_get_event_meta( $post_id, 'price', '' ),
		'location'            => (string) vila_akoya_get_event_meta( $post_id, 'location', '' ),
		'kind'                => vila_akoya_normalize_event_kind( vila_akoya_get_event_meta( $post_id, 'kind', 'event' ) ),
		'reservation_required' => (bool) (int) vila_akoya_get_event_meta( $post_id, 'reservation_required', '1' ),
		'next_occurrence'     => (string) vila_akoya_get_event_meta( $post_id, 'next_occurrence', '' ),
	);
}

/**
 * Whether visitors should request a place for this event.
 *
 * @param int|array<string, mixed> $post_id_or_data Post ID or event data from vila_akoya_get_event_data().
 * @return bool
 */
function vila_akoya_event_requires_reservation( $post_id_or_data ) {
	if ( is_array( $post_id_or_data ) ) {
		return ! empty( $post_id_or_data['reservation_required'] );
	}
	return (bool) (int) vila_akoya_get_event_meta( (int) $post_id_or_data, 'reservation_required', '1' );
}

/**
 * Normalize listing kind to event|workshop.
 *
 * @param mixed $kind Raw value.
 * @return string
 */
function vila_akoya_normalize_event_kind( $kind ) {
	return 'workshop' === $kind ? 'workshop' : 'event';
}

/**
 * Human label for event kind.
 *
 * @param string $kind event|workshop.
 * @return string
 */
function vila_akoya_event_kind_label( $kind ) {
	return 'workshop' === vila_akoya_normalize_event_kind( $kind )
		? __( 'Workshop', 'vila-akoya' )
		: __( 'Event', 'vila-akoya' );
}

/**
 * Admin meta box.
 *
 * @return void
 */
function vila_akoya_event_meta_boxes() {
	add_meta_box(
		'vila_event_details',
		__( 'Event details', 'vila-akoya' ),
		'vila_akoya_render_event_meta_box',
		'event',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'vila_akoya_event_meta_boxes' );

/**
 * Render event details meta box.
 *
 * @param WP_Post $post Post.
 * @return void
 */
function vila_akoya_render_event_meta_box( $post ) {
	wp_nonce_field( 'vila_akoya_save_event_meta', 'vila_akoya_event_meta_nonce' );
	$data = vila_akoya_get_event_data( $post->ID );
	$weekdays = vila_akoya_event_weekday_labels();
	$date_min = wp_date( 'Y-m-d', strtotime( '-2 years' ) );
	$date_max = wp_date( 'Y-m-d', strtotime( '+10 years' ) );
	?>
	<style>
		.vila-event-admin { display: grid; gap: 1rem; max-width: 40rem; }
		.vila-event-admin label { display: block; font-weight: 600; margin-bottom: 0.25rem; }
		.vila-event-admin .vila-event-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
		.vila-event-admin .vila-event-checkboxes { display: flex; flex-wrap: wrap; gap: 0.75rem 1rem; }
		.vila-event-admin .vila-event-checkboxes label { font-weight: 400; }
		.vila-event-admin .vila-event-check-row { display: flex; align-items: flex-start; gap: 0.55rem; margin: 0; padding: 0.85rem 1rem; border: 1px solid #c3c4c7; border-radius: 0.5rem; background: #f6f7f7; }
		.vila-event-admin .vila-event-check-row label { display: inline-flex; align-items: center; gap: 0.55rem; margin: 0; font-weight: 600; cursor: pointer; }
		.vila-event-admin .vila-event-check-row input[type="checkbox"] { width: 1.1rem; height: 1.1rem; margin: 0; flex: 0 0 auto; }
		.vila-event-admin .description { color: #646970; margin: 0.25rem 0 0; }
		.vila-event-admin [data-vila-schedule] { display: none; }
		.vila-event-admin[data-schedule="single"] [data-vila-schedule~="single"],
		.vila-event-admin[data-schedule="range"] [data-vila-schedule~="range"],
		.vila-event-admin[data-schedule="recurring"] [data-vila-schedule~="recurring"] { display: block; }
		.vila-event-admin[data-schedule="range"] .vila-event-row[data-vila-schedule~="range"] { display: grid; }
		.vila-event-admin[data-schedule="recurring"] .vila-event-row[data-vila-schedule~="recurring"] { display: grid; }
	</style>
	<div class="vila-event-admin" data-schedule="<?php echo esc_attr( $data['schedule_type'] ); ?>" id="vila-event-admin">
		<p>
			<label for="vila_event_kind"><?php esc_html_e( 'Category', 'vila-akoya' ); ?></label>
			<select name="vila_event_kind" id="vila_event_kind">
				<option value="event" <?php selected( $data['kind'], 'event' ); ?>><?php esc_html_e( 'Event', 'vila-akoya' ); ?></option>
				<option value="workshop" <?php selected( $data['kind'], 'workshop' ); ?>><?php esc_html_e( 'Workshop', 'vila-akoya' ); ?></option>
			</select>
		</p>

		<p class="vila-event-check-row">
			<label for="vila_event_reservation_required">
				<input type="checkbox" name="vila_event_reservation_required" id="vila_event_reservation_required" value="1" <?php checked( $data['reservation_required'] ); ?> />
				<?php esc_html_e( 'Reservation required', 'vila-akoya' ); ?>
			</label>
			<span class="description"><?php esc_html_e( 'Show “Request a place” on cards and the inquiry form on the event page. Leave unchecked for drop-in events.', 'vila-akoya' ); ?></span>
		</p>

		<p>
			<label for="vila_event_schedule_type"><?php esc_html_e( 'Schedule type', 'vila-akoya' ); ?></label>
			<select name="vila_event_schedule_type" id="vila_event_schedule_type">
				<option value="single" <?php selected( $data['schedule_type'], 'single' ); ?>><?php esc_html_e( 'One day', 'vila-akoya' ); ?></option>
				<option value="range" <?php selected( $data['schedule_type'], 'range' ); ?>><?php esc_html_e( 'Multi-day (date range)', 'vila-akoya' ); ?></option>
				<option value="recurring" <?php selected( $data['schedule_type'], 'recurring' ); ?>><?php esc_html_e( 'Recurring', 'vila-akoya' ); ?></option>
			</select>
		</p>

		<div class="vila-event-row" data-vila-schedule="single range recurring">
			<p>
				<label for="vila_event_start_date"><?php esc_html_e( 'Start date', 'vila-akoya' ); ?></label>
				<input type="date" name="vila_event_start_date" id="vila_event_start_date" value="<?php echo esc_attr( $data['start_date'] ); ?>" min="<?php echo esc_attr( $date_min ); ?>" max="<?php echo esc_attr( $date_max ); ?>" required />
			</p>
			<p data-vila-schedule="range">
				<label for="vila_event_end_date"><?php esc_html_e( 'End date', 'vila-akoya' ); ?></label>
				<input type="date" name="vila_event_end_date" id="vila_event_end_date" value="<?php echo esc_attr( $data['end_date'] ); ?>" min="<?php echo esc_attr( $date_min ); ?>" max="<?php echo esc_attr( $date_max ); ?>" />
			</p>
		</div>

		<p>
			<label>
				<input type="checkbox" name="vila_event_all_day" value="1" <?php checked( $data['all_day'] ); ?> />
				<?php esc_html_e( 'All day', 'vila-akoya' ); ?>
			</label>
		</p>

		<div class="vila-event-row" id="vila-event-times">
			<p>
				<label for="vila_event_start_time"><?php esc_html_e( 'Start time', 'vila-akoya' ); ?></label>
				<input type="time" name="vila_event_start_time" id="vila_event_start_time" value="<?php echo esc_attr( $data['start_time'] ); ?>" />
			</p>
			<p>
				<label for="vila_event_end_time"><?php esc_html_e( 'End time', 'vila-akoya' ); ?></label>
				<input type="time" name="vila_event_end_time" id="vila_event_end_time" value="<?php echo esc_attr( $data['end_time'] ); ?>" />
			</p>
		</div>

		<div data-vila-schedule="recurring">
			<div class="vila-event-row" data-vila-schedule="recurring">
				<p>
					<label for="vila_event_recurrence_freq"><?php esc_html_e( 'Repeats', 'vila-akoya' ); ?></label>
					<select name="vila_event_recurrence_freq" id="vila_event_recurrence_freq">
						<option value="weekly" <?php selected( $data['recurrence_freq'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'vila-akoya' ); ?></option>
						<option value="monthly" <?php selected( $data['recurrence_freq'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'vila-akoya' ); ?></option>
					</select>
				</p>
				<p>
					<label for="vila_event_recurrence_interval"><?php esc_html_e( 'Every', 'vila-akoya' ); ?></label>
					<input type="number" min="1" max="52" name="vila_event_recurrence_interval" id="vila_event_recurrence_interval" value="<?php echo esc_attr( (string) $data['recurrence_interval'] ); ?>" />
					<span class="description"><?php esc_html_e( 'weeks or months (depending on “Repeats”).', 'vila-akoya' ); ?></span>
				</p>
			</div>
			<p data-vila-schedule="recurring" id="vila-event-byday-wrap">
				<label><?php esc_html_e( 'On these days (weekly)', 'vila-akoya' ); ?></label>
				<span class="vila-event-checkboxes">
					<?php foreach ( $weekdays as $code => $label ) : ?>
						<label>
							<input type="checkbox" name="vila_event_recurrence_byday[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, $data['recurrence_byday'], true ) ); ?> />
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</span>
			</p>
			<p data-vila-schedule="recurring">
				<label for="vila_event_recurrence_until"><?php esc_html_e( 'Until (optional)', 'vila-akoya' ); ?></label>
				<input type="date" name="vila_event_recurrence_until" id="vila_event_recurrence_until" value="<?php echo esc_attr( $data['recurrence_until'] ); ?>" min="<?php echo esc_attr( $date_min ); ?>" max="<?php echo esc_attr( $date_max ); ?>" />
				<span class="description"><?php esc_html_e( 'Leave blank for an open-ended series. Monthly repeats use the same day of the month as the start date.', 'vila-akoya' ); ?></span>
			</p>
		</div>

		<div class="vila-event-row">
			<p>
				<label for="vila_event_capacity"><?php esc_html_e( 'Capacity (seats)', 'vila-akoya' ); ?></label>
				<input type="number" min="0" name="vila_event_capacity" id="vila_event_capacity" value="<?php echo esc_attr( $data['capacity'] ? (string) $data['capacity'] : '' ); ?>" />
				<span class="description"><?php esc_html_e( 'Shown on the event page. Leave blank if not needed.', 'vila-akoya' ); ?></span>
			</p>
			<p>
				<label for="vila_event_price"><?php esc_html_e( 'Price', 'vila-akoya' ); ?></label>
				<input type="text" name="vila_event_price" id="vila_event_price" value="<?php echo esc_attr( $data['price'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. €45 or Free', 'vila-akoya' ); ?>" />
				<span class="description"><?php esc_html_e( 'Display only — inquiries are not charged online yet.', 'vila-akoya' ); ?></span>
			</p>
		</div>

		<p>
			<label for="vila_event_location"><?php esc_html_e( 'Location', 'vila-akoya' ); ?></label>
			<input type="text" class="widefat" name="vila_event_location" id="vila_event_location" value="<?php echo esc_attr( $data['location'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. The Campus, Vila Akoya', 'vila-akoya' ); ?>" />
		</p>
	</div>
	<script>
	(function () {
		var root = document.getElementById('vila-event-admin');
		var type = document.getElementById('vila_event_schedule_type');
		var allDay = document.querySelector('input[name="vila_event_all_day"]');
		var times = document.getElementById('vila-event-times');
		if (!root || !type) return;
		function sync() {
			root.setAttribute('data-schedule', type.value);
			if (times && allDay) {
				times.style.display = allDay.checked ? 'none' : '';
			}
		}
		type.addEventListener('change', sync);
		if (allDay) allDay.addEventListener('change', sync);
		sync();
	})();
	</script>
	<?php
}

/**
 * Sanitize a Y-m-d date string.
 *
 * @param string $value Raw.
 * @return string
 */
function vila_akoya_sanitize_event_date( $value ) {
	$value = is_string( $value ) ? trim( $value ) : '';
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
		return '';
	}
	$dt = DateTimeImmutable::createFromFormat( 'Y-m-d', $value );
	if ( ! $dt || $dt->format( 'Y-m-d' ) !== $value ) {
		return '';
	}
	return $value;
}

/**
 * Sanitize an H:i time string.
 *
 * @param string $value Raw.
 * @return string
 */
function vila_akoya_sanitize_event_time( $value ) {
	$value = is_string( $value ) ? trim( $value ) : '';
	if ( ! preg_match( '/^\d{2}:\d{2}$/', $value ) ) {
		return '';
	}
	return $value;
}

/**
 * Save event meta.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function vila_akoya_save_event_meta( $post_id ) {
	if ( ! isset( $_POST['vila_akoya_event_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['vila_akoya_event_meta_nonce'] ) ), 'vila_akoya_save_event_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( 'event' !== get_post_type( $post_id ) ) {
		return;
	}

	$keys = vila_akoya_event_meta_keys();

	$kind = isset( $_POST['vila_event_kind'] ) ? sanitize_key( wp_unslash( $_POST['vila_event_kind'] ) ) : 'event';
	update_post_meta( $post_id, $keys['kind'], vila_akoya_normalize_event_kind( $kind ) );

	$type = isset( $_POST['vila_event_schedule_type'] ) ? sanitize_key( wp_unslash( $_POST['vila_event_schedule_type'] ) ) : 'single';
	if ( ! in_array( $type, array( 'single', 'range', 'recurring' ), true ) ) {
		$type = 'single';
	}
	update_post_meta( $post_id, $keys['schedule_type'], $type );

	$start = isset( $_POST['vila_event_start_date'] ) ? vila_akoya_sanitize_event_date( wp_unslash( $_POST['vila_event_start_date'] ) ) : '';
	update_post_meta( $post_id, $keys['start_date'], $start );

	$end = isset( $_POST['vila_event_end_date'] ) ? vila_akoya_sanitize_event_date( wp_unslash( $_POST['vila_event_end_date'] ) ) : '';
	if ( 'range' !== $type ) {
		$end = '';
	}
	update_post_meta( $post_id, $keys['end_date'], $end );

	$all_day = ! empty( $_POST['vila_event_all_day'] ) ? '1' : '0';
	update_post_meta( $post_id, $keys['all_day'], $all_day );

	$start_time = isset( $_POST['vila_event_start_time'] ) ? vila_akoya_sanitize_event_time( wp_unslash( $_POST['vila_event_start_time'] ) ) : '';
	$end_time   = isset( $_POST['vila_event_end_time'] ) ? vila_akoya_sanitize_event_time( wp_unslash( $_POST['vila_event_end_time'] ) ) : '';
	if ( '1' === $all_day ) {
		$start_time = '';
		$end_time   = '';
	}
	update_post_meta( $post_id, $keys['start_time'], $start_time );
	update_post_meta( $post_id, $keys['end_time'], $end_time );

	$freq = isset( $_POST['vila_event_recurrence_freq'] ) ? sanitize_key( wp_unslash( $_POST['vila_event_recurrence_freq'] ) ) : 'weekly';
	if ( ! in_array( $freq, array( 'weekly', 'monthly' ), true ) ) {
		$freq = 'weekly';
	}
	update_post_meta( $post_id, $keys['recurrence_freq'], 'recurring' === $type ? $freq : '' );

	$interval = isset( $_POST['vila_event_recurrence_interval'] ) ? max( 1, (int) $_POST['vila_event_recurrence_interval'] ) : 1;
	update_post_meta( $post_id, $keys['recurrence_interval'], 'recurring' === $type ? (string) $interval : '' );

	$allowed_days = array_keys( vila_akoya_event_weekday_labels() );
	$byday        = array();
	if ( isset( $_POST['vila_event_recurrence_byday'] ) && is_array( $_POST['vila_event_recurrence_byday'] ) ) {
		foreach ( wp_unslash( $_POST['vila_event_recurrence_byday'] ) as $day ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$day = sanitize_text_field( $day );
			if ( in_array( $day, $allowed_days, true ) ) {
				$byday[] = $day;
			}
		}
	}
	update_post_meta( $post_id, $keys['recurrence_byday'], ( 'recurring' === $type && 'weekly' === $freq ) ? implode( ',', $byday ) : '' );

	$until = isset( $_POST['vila_event_recurrence_until'] ) ? vila_akoya_sanitize_event_date( wp_unslash( $_POST['vila_event_recurrence_until'] ) ) : '';
	update_post_meta( $post_id, $keys['recurrence_until'], 'recurring' === $type ? $until : '' );

	$capacity = isset( $_POST['vila_event_capacity'] ) ? max( 0, (int) $_POST['vila_event_capacity'] ) : 0;
	update_post_meta( $post_id, $keys['capacity'], $capacity ? (string) $capacity : '' );

	$price = isset( $_POST['vila_event_price'] ) ? sanitize_text_field( wp_unslash( $_POST['vila_event_price'] ) ) : '';
	update_post_meta( $post_id, $keys['price'], $price );

	$location = isset( $_POST['vila_event_location'] ) ? sanitize_text_field( wp_unslash( $_POST['vila_event_location'] ) ) : '';
	update_post_meta( $post_id, $keys['location'], $location );

	$reservation_required = ! empty( $_POST['vila_event_reservation_required'] ) ? '1' : '0';
	update_post_meta( $post_id, $keys['reservation_required'], $reservation_required );

	$next = vila_akoya_compute_event_next_occurrence( vila_akoya_get_event_data( $post_id ) );
	update_post_meta( $post_id, $keys['next_occurrence'], $next );
}
add_action( 'save_post_event', 'vila_akoya_save_event_meta' );

/**
 * Map PHP date('N') (1=Mon…7=Sun) to MO…SU.
 *
 * @param int $n PHP N.
 * @return string
 */
function vila_akoya_event_php_n_to_byday( $n ) {
	$map = array( 1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU' );
	return isset( $map[ $n ] ) ? $map[ $n ] : '';
}

/**
 * Compute next (or current) occurrence date for listing/sort.
 *
 * @param array<string, mixed> $data Event data.
 * @param string|null          $today Y-m-d override (tests).
 * @return string Y-m-d or empty.
 */
function vila_akoya_compute_event_next_occurrence( array $data, $today = null ) {
	$start = isset( $data['start_date'] ) ? (string) $data['start_date'] : '';
	if ( '' === $start ) {
		return '';
	}

	$today_str = $today ? (string) $today : wp_date( 'Y-m-d' );
	$type      = isset( $data['schedule_type'] ) ? (string) $data['schedule_type'] : 'single';

	if ( 'single' === $type ) {
		return $start;
	}

	if ( 'range' === $type ) {
		$end = isset( $data['end_date'] ) && $data['end_date'] ? (string) $data['end_date'] : $start;
		if ( $today_str > $end ) {
			return $end;
		}
		if ( $today_str < $start ) {
			return $start;
		}
		return $today_str;
	}

	// Recurring.
	$freq     = isset( $data['recurrence_freq'] ) ? (string) $data['recurrence_freq'] : 'weekly';
	$interval = isset( $data['recurrence_interval'] ) ? max( 1, (int) $data['recurrence_interval'] ) : 1;
	$until    = isset( $data['recurrence_until'] ) ? (string) $data['recurrence_until'] : '';
	$byday    = isset( $data['recurrence_byday'] ) && is_array( $data['recurrence_byday'] ) ? $data['recurrence_byday'] : array();

	try {
		$cursor = new DateTimeImmutable( $start, wp_timezone() );
		$today  = new DateTimeImmutable( $today_str, wp_timezone() );
		$limit  = $until ? new DateTimeImmutable( $until, wp_timezone() ) : $today->modify( '+2 years' );
	} catch ( Exception $e ) {
		return $start;
	}

	if ( 'monthly' === $freq ) {
		$day = (int) $cursor->format( 'j' );
		$candidate = $cursor;
		// Walk forward in interval months until >= today (or return start if still upcoming).
		while ( $candidate < $today && $candidate <= $limit ) {
			$candidate = $candidate->modify( '+' . $interval . ' months' );
			// Clamp day for short months.
			$max = (int) $candidate->format( 't' );
			$use = min( $day, $max );
			$candidate = $candidate->setDate( (int) $candidate->format( 'Y' ), (int) $candidate->format( 'n' ), $use );
		}
		if ( $candidate > $limit ) {
			return $until ? $until : $start;
		}
		return $candidate->format( 'Y-m-d' );
	}

	// Weekly.
	if ( empty( $byday ) ) {
		$byday = array( vila_akoya_event_php_n_to_byday( (int) $cursor->format( 'N' ) ) );
	}
	$byday = array_values( array_filter( $byday ) );
	if ( empty( $byday ) ) {
		return $start;
	}

	// Find next matching weekday on/after max(start, today), respecting interval from series start.
	$search     = $today < $cursor ? $cursor : $today;
	$end_search = $limit->modify( '+14 days' );
	// Widen search when interval > 1.
	if ( $interval > 1 ) {
		$end_search = $limit->modify( '+' . ( 7 * $interval * 8 ) . ' days' );
	}
	if ( $end_search > $limit ) {
		$end_search = $limit->modify( '+1 day' );
	}

	for ( $d = $search; $d < $end_search; $d = $d->modify( '+1 day' ) ) {
		$code = vila_akoya_event_php_n_to_byday( (int) $d->format( 'N' ) );
		if ( ! in_array( $code, $byday, true ) ) {
			continue;
		}
		$days_from_start = (int) $cursor->diff( $d )->days;
		$week_index      = (int) floor( $days_from_start / 7 );
		if ( 0 !== ( $week_index % $interval ) ) {
			continue;
		}
		return $d->format( 'Y-m-d' );
	}

	return $start;
}

/**
 * Format a date for display.
 *
 * @param string $ymd Y-m-d.
 * @param string $format PHP date format via wp_date.
 * @return string
 */
function vila_akoya_format_event_date( $ymd, $format = 'j M Y' ) {
	$ymd = vila_akoya_sanitize_event_date( $ymd );
	if ( '' === $ymd ) {
		return '';
	}
	$ts = strtotime( $ymd . ' 12:00:00' );
	if ( ! $ts ) {
		return '';
	}
	return wp_date( $format, $ts );
}

/**
 * Human-readable schedule line.
 *
 * @param int|array<string, mixed> $post_id_or_data Post ID or data array.
 * @return string
 */
function vila_akoya_format_event_schedule( $post_id_or_data ) {
	$data = is_array( $post_id_or_data ) ? $post_id_or_data : vila_akoya_get_event_data( (int) $post_id_or_data );
	$type = $data['schedule_type'];
	$start = $data['start_date'];
	if ( '' === $start ) {
		return '';
	}

	$time_bit = '';
	if ( empty( $data['all_day'] ) && ( $data['start_time'] || $data['end_time'] ) ) {
		$parts = array_filter( array( $data['start_time'], $data['end_time'] ) );
		$time_bit = ' · ' . implode( '–', $parts );
	}

	if ( 'range' === $type ) {
		$end = $data['end_date'] ? $data['end_date'] : $start;
		$label = sprintf(
			/* translators: 1: start date, 2: end date */
			__( '%1$s – %2$s', 'vila-akoya' ),
			vila_akoya_format_event_date( $start, 'j M Y' ),
			vila_akoya_format_event_date( $end, 'j M Y' )
		);
		return $label . $time_bit;
	}

	if ( 'recurring' === $type ) {
		$freq     = $data['recurrence_freq'];
		$interval = max( 1, (int) $data['recurrence_interval'] );
		$next     = $data['next_occurrence'] ? $data['next_occurrence'] : vila_akoya_compute_event_next_occurrence( $data );

		if ( 'monthly' === $freq ) {
			$summary = 1 === $interval
				? __( 'Every month', 'vila-akoya' )
				: sprintf(
					/* translators: %d: interval */
					__( 'Every %d months', 'vila-akoya' ),
					$interval
				);
		} else {
			$labels = vila_akoya_event_weekday_labels();
			$days   = array();
			foreach ( $data['recurrence_byday'] as $code ) {
				if ( isset( $labels[ $code ] ) ) {
					$days[] = $labels[ $code ];
				}
			}
			$day_list = $days ? implode( ', ', $days ) : '';
			if ( 1 === $interval ) {
				$summary = $day_list
					? sprintf(
						/* translators: %s: weekday list */
						__( 'Every %s', 'vila-akoya' ),
						$day_list
					)
					: __( 'Weekly', 'vila-akoya' );
			} else {
				$summary = $day_list
					? sprintf(
						/* translators: 1: interval, 2: weekdays */
						__( 'Every %1$d weeks on %2$s', 'vila-akoya' ),
						$interval,
						$day_list
					)
					: sprintf(
						/* translators: %d: interval */
						__( 'Every %d weeks', 'vila-akoya' ),
						$interval
					);
			}
		}

		if ( $next ) {
			$summary .= ' · ' . sprintf(
				/* translators: %s: next date */
				__( 'next %s', 'vila-akoya' ),
				vila_akoya_format_event_date( $next, 'j M Y' )
			);
		}
		return $summary . $time_bit;
	}

	return vila_akoya_format_event_date( $start, 'l j M Y' ) . $time_bit;
}

/**
 * Order events by next occurrence on the archive.
 *
 * @param WP_Query $query Query.
 * @return void
 */
function vila_akoya_event_archive_order( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( ! $query->is_post_type_archive( 'event' ) ) {
		return;
	}
	$query->set( 'posts_per_page', -1 );
	$query->set( 'orderby', 'title' );
	$query->set( 'order', 'ASC' );
}
add_action( 'pre_get_posts', 'vila_akoya_event_archive_order' );

/**
 * Published events for the public archive (not limited by the main query).
 *
 * @return WP_Post[]
 */
function vila_akoya_get_archive_event_posts() {
	$posts = get_posts(
		array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		)
	);
	if ( ! $posts ) {
		return array();
	}

	usort(
		$posts,
		static function ( $a, $b ) {
			$da = (string) vila_akoya_get_event_meta( $a->ID, 'next_occurrence', vila_akoya_get_event_meta( $a->ID, 'start_date', '' ) );
			$db = (string) vila_akoya_get_event_meta( $b->ID, 'next_occurrence', vila_akoya_get_event_meta( $b->ID, 'start_date', '' ) );
			if ( $da === $db ) {
				return strcasecmp( $a->post_title, $b->post_title );
			}
			if ( '' === $da ) {
				return 1;
			}
			if ( '' === $db ) {
				return -1;
			}
			return strcmp( $da, $db );
		}
	);

	return $posts;
}

/**
 * Occurrence dates for an event between two Y-m-d bounds (inclusive).
 *
 * @param array<string, mixed> $data Event data.
 * @param string               $from Y-m-d.
 * @param string               $to   Y-m-d.
 * @return string[]
 */
function vila_akoya_event_dates_in_range( array $data, $from, $to ) {
	$dates = array();
	$start = isset( $data['start_date'] ) ? (string) $data['start_date'] : '';
	if ( '' === $start || $start > $to ) {
		return $dates;
	}

	$type = isset( $data['schedule_type'] ) ? (string) $data['schedule_type'] : 'single';

	if ( 'single' === $type ) {
		if ( $start >= $from && $start <= $to ) {
			$dates[] = $start;
		}
		return $dates;
	}

	try {
		$tz = wp_timezone();
		$series_start = new DateTimeImmutable( $start, $tz );
	} catch ( Exception $e ) {
		return $dates;
	}

	if ( 'range' === $type ) {
		$end = isset( $data['end_date'] ) && $data['end_date'] ? (string) $data['end_date'] : $start;
		$loop_from = max( $start, $from );
		$loop_to   = min( $end, $to );
		if ( $loop_from > $loop_to ) {
			return $dates;
		}
		try {
			$d    = new DateTimeImmutable( $loop_from, $tz );
			$last = new DateTimeImmutable( $loop_to, $tz );
		} catch ( Exception $e ) {
			return $dates;
		}
		for ( ; $d <= $last; $d = $d->modify( '+1 day' ) ) {
			$dates[] = $d->format( 'Y-m-d' );
		}
		return $dates;
	}

	$until = isset( $data['recurrence_until'] ) && $data['recurrence_until'] ? (string) $data['recurrence_until'] : $to;
	$loop_from = max( $start, $from );
	$loop_to   = min( $until, $to );
	if ( $loop_from > $loop_to ) {
		return $dates;
	}

	try {
		$d    = new DateTimeImmutable( $loop_from, $tz );
		$last = new DateTimeImmutable( $loop_to, $tz );
	} catch ( Exception $e ) {
		return $dates;
	}

	$freq     = isset( $data['recurrence_freq'] ) ? (string) $data['recurrence_freq'] : 'weekly';
	$interval = isset( $data['recurrence_interval'] ) ? max( 1, (int) $data['recurrence_interval'] ) : 1;
	$byday    = isset( $data['recurrence_byday'] ) && is_array( $data['recurrence_byday'] ) ? $data['recurrence_byday'] : array();
	$month_day = (int) $series_start->format( 'j' );

	if ( 'monthly' !== $freq && empty( $byday ) ) {
		$byday = array( vila_akoya_event_php_n_to_byday( (int) $series_start->format( 'N' ) ) );
	}

	for ( ; $d <= $last; $d = $d->modify( '+1 day' ) ) {
		if ( 'monthly' === $freq ) {
			$max = (int) $d->format( 't' );
			$use = min( $month_day, $max );
			if ( (int) $d->format( 'j' ) !== $use ) {
				continue;
			}
			$months = ( (int) $d->format( 'Y' ) - (int) $series_start->format( 'Y' ) ) * 12
				+ ( (int) $d->format( 'n' ) - (int) $series_start->format( 'n' ) );
			if ( 0 !== ( $months % $interval ) ) {
				continue;
			}
			$dates[] = $d->format( 'Y-m-d' );
			continue;
		}

		$code = vila_akoya_event_php_n_to_byday( (int) $d->format( 'N' ) );
		if ( ! in_array( $code, $byday, true ) ) {
			continue;
		}
		$days_from_start = (int) $series_start->diff( $d )->days;
		$week_index      = (int) floor( $days_from_start / 7 );
		if ( 0 !== ( $week_index % $interval ) ) {
			continue;
		}
		$dates[] = $d->format( 'Y-m-d' );
	}

	return $dates;
}

/**
 * Calendar payload for the archive script.
 *
 * @param WP_Post[] $posts Events.
 * @return array<string, mixed>
 */
function vila_akoya_events_calendar_payload( array $posts ) {
	$from = wp_date( 'Y-m-01', strtotime( '-2 months' ) );
	$to   = wp_date( 'Y-m-t', strtotime( '+16 months' ) );
	$days = array();

	foreach ( $posts as $post ) {
		$data  = vila_akoya_get_event_data( $post->ID );
		$time  = '';
		if ( empty( $data['all_day'] ) && $data['start_time'] ) {
			$time = $data['end_time'] ? $data['start_time'] . '–' . $data['end_time'] : $data['start_time'];
		}
		$item = array(
			'id'    => (int) $post->ID,
			'title' => get_the_title( $post ),
			'url'   => get_permalink( $post ),
			'time'  => $time,
			'kind'  => $data['kind'],
		);
		foreach ( vila_akoya_event_dates_in_range( $data, $from, $to ) as $ymd ) {
			if ( ! isset( $days[ $ymd ] ) ) {
				$days[ $ymd ] = array();
			}
			$days[ $ymd ][] = $item;
		}
	}

	foreach ( $days as $ymd => $items ) {
		$days[ $ymd ] = array_values( $items );
	}

	$weekdays = array();
	$start_of_week = (int) get_option( 'start_of_week', 1 );
	for ( $i = 0; $i < 7; $i++ ) {
		$n = ( $start_of_week + $i ) % 7;
		$weekdays[] = wp_date( 'D', strtotime( 'Sunday +' . $n . ' days' ) );
	}

	$months = array();
	for ( $m = 1; $m <= 12; $m++ ) {
		$months[] = wp_date( 'F', strtotime( '2026-' . sprintf( '%02d', $m ) . '-15' ) );
	}

	return array(
		'startOfWeek' => $start_of_week,
		'weekdays'    => $weekdays,
		'months'      => $months,
		'days'        => $days,
		'today'       => wp_date( 'Y-m-d' ),
	);
}

/**
 * Archive listing shortcode (used by archive-event template).
 *
 * @return string
 */
function vila_akoya_shortcode_events_archive() {
	$posts = vila_akoya_get_archive_event_posts();
	$view  = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'calendar' !== $view ) {
		$view = 'list';
	}
	$payload = vila_akoya_events_calendar_payload( $posts );

	ob_start();
	?>
	<div class="vila-events-archive__inner" data-vila-events data-view="<?php echo esc_attr( $view ); ?>">
		<header class="vila-events-archive__intro">
			<div class="vila-events-archive__intro-copy">
				<p class="vila-events-archive__eyebrow"><?php esc_html_e( 'On campus', 'vila-akoya' ); ?></p>
				<h1 class="vila-events-archive__title"><?php esc_html_e( 'Events & workshops', 'vila-akoya' ); ?></h1>
				<p class="vila-events-archive__lede"><?php esc_html_e( 'Classes, workshops and gatherings. Request a place and we will get back to you.', 'vila-akoya' ); ?></p>
			</div>
			<?php if ( $posts ) : ?>
				<div class="vila-events-toggle" role="tablist" aria-label="<?php esc_attr_e( 'Events view', 'vila-akoya' ); ?>"><button type="button" class="vila-events-toggle__btn" data-events-view="list" role="tab" aria-selected="<?php echo 'list' === $view ? 'true' : 'false'; ?>"><?php esc_html_e( 'List', 'vila-akoya' ); ?></button><button type="button" class="vila-events-toggle__btn" data-events-view="calendar" role="tab" aria-selected="<?php echo 'calendar' === $view ? 'true' : 'false'; ?>"><?php esc_html_e( 'Calendar', 'vila-akoya' ); ?></button></div>
			<?php endif; ?>
		</header>
		<?php if ( $posts ) : ?>
			<div class="vila-events-board" data-events-panel="list">
				<?php
				foreach ( $posts as $event_post ) {
					echo vila_akoya_render_event_card( $event_post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>
			<div class="vila-events-calendar" data-events-panel="calendar">
				<div class="vila-events-cal__nav">
					<button type="button" class="vila-events-cal__nav-btn" data-cal-prev aria-label="<?php esc_attr_e( 'Previous month', 'vila-akoya' ); ?>">‹</button>
					<h2 class="vila-events-cal__label" data-cal-label></h2>
					<button type="button" class="vila-events-cal__nav-btn" data-cal-next aria-label="<?php esc_attr_e( 'Next month', 'vila-akoya' ); ?>">›</button>
				</div>
				<div class="vila-events-cal__grid" data-cal-grid></div>
			</div>
			<script type="application/json" id="vila-events-cal-data"><?php echo wp_json_encode( $payload ); ?></script>
		<?php else : ?>
			<p class="vila-events-archive__empty"><?php esc_html_e( 'No upcoming events yet. Check back soon.', 'vila-akoya' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'vila_events_archive', 'vila_akoya_shortcode_events_archive' );

/**
 * Compact upcoming-events preview (Campus page).
 *
 * @param array<string, string>|string $atts Shortcode atts.
 * @return string
 */
function vila_akoya_shortcode_events_preview( $atts = array() ) {
	$atts = shortcode_atts(
		array(
			'count' => '3',
		),
		$atts,
		'vila_events_preview'
	);
	$count = max( 1, min( 6, (int) $atts['count'] ) );
	$all_posts = vila_akoya_get_archive_event_posts();
	$posts     = array();
	foreach ( $all_posts as $event_post ) {
		$data = vila_akoya_get_event_data( $event_post->ID );
		if ( 'workshop' !== $data['kind'] ) {
			continue;
		}
		$posts[] = $event_post;
		if ( count( $posts ) >= $count ) {
			break;
		}
	}
	if ( empty( $posts ) ) {
		return '';
	}
	$all = get_post_type_archive_link( 'event' );
	if ( ! $all ) {
		$all = home_url( '/events/' );
	}

	ob_start();
	?>
	<section class="vila-events-preview" aria-labelledby="vila-events-preview-heading">
		<div class="vila-events-preview__inner">
			<header class="vila-events-preview__header">
				<div>
					<p class="vila-events-preview__eyebrow"><?php esc_html_e( 'On campus', 'vila-akoya' ); ?></p>
					<h2 id="vila-events-preview-heading" class="vila-events-preview__title"><?php esc_html_e( 'Workshops', 'vila-akoya' ); ?></h2>
					<p class="vila-events-preview__lede"><?php esc_html_e( 'Hands-on sessions happening on campus. Request a place, or browse the full programme.', 'vila-akoya' ); ?></p>
				</div>
				<a class="vila-events-preview__btn" href="<?php echo esc_url( $all ); ?>"><?php esc_html_e( 'See all events', 'vila-akoya' ); ?></a>
			</header>
			<?php if ( $posts ) : ?>
				<div class="vila-events-preview__board">
					<?php
					foreach ( $posts as $event_post ) {
						echo vila_akoya_render_event_card( $event_post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					?>
				</div>
			<?php endif; ?>
			<p class="vila-events-preview__footer">
				<a class="vila-events-preview__btn vila-events-preview__btn--ghost" href="<?php echo esc_url( $all ); ?>"><?php esc_html_e( 'See all events', 'vila-akoya' ); ?></a>
			</p>
		</div>
	</section>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'vila_events_preview', 'vila_akoya_shortcode_events_preview' );

/**
 * Insert the events preview on Campus after “Classes in All Schools of Life”.
 *
 * @param string $content Page content.
 * @return string
 */
function vila_akoya_inject_campus_events_preview( $content ) {
	static $injecting = false;
	if ( $injecting ) {
		return $content;
	}
	if ( is_admin() || ! is_singular( 'page' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	if ( ! is_page( 'campus' ) ) {
		return $content;
	}
	if ( (int) get_the_ID() !== (int) get_queried_object_id() ) {
		return $content;
	}
	if ( false !== strpos( $content, 'vila-events-preview' ) || false !== strpos( $content, '[vila_events_preview]' ) ) {
		return $content;
	}

	$injecting = true;
	$preview   = vila_akoya_shortcode_events_preview();
	$injecting = false;
	if ( '' === trim( (string) $preview ) ) {
		return $content;
	}

	$updated = preg_replace(
		'/(<h2[^>]*>\s*Classes in All Schools of Life\s*<\/h2>[\s\S]*?<\/section>)/i',
		'$1' . $preview,
		$content,
		1
	);

	return is_string( $updated ) && '' !== $updated ? $updated : $content . $preview;
}
add_filter( 'the_content', 'vila_akoya_inject_campus_events_preview', 12 );

/**
 * Render one event card HTML.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function vila_akoya_render_event_card( $post_id ) {
	$post_id = (int) $post_id;
	$data    = vila_akoya_get_event_data( $post_id );
	$schedule = vila_akoya_format_event_schedule( $data );
	$permalink = get_permalink( $post_id );
	$title     = get_the_title( $post_id );
	$post      = get_post( $post_id );
	$excerpt   = '';
	if ( $post instanceof WP_Post ) {
		$excerpt = $post->post_excerpt
			? $post->post_excerpt
			: wp_trim_words( wp_strip_all_tags( $post->post_content ), 16 );
	}

	ob_start();
	?>
	<article class="vila-event-card">
		<a class="vila-event-card__image" href="<?php echo esc_url( $permalink ); ?>">
			<?php if ( has_post_thumbnail( $post_id ) ) : ?>
				<?php echo get_the_post_thumbnail( $post_id, 'large', array( 'alt' => $title ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php else : ?>
				<span class="vila-event-card__placeholder" aria-hidden="true"></span>
			<?php endif; ?>
			<span class="vila-event-badge vila-event-badge--<?php echo esc_attr( $data['kind'] ); ?>"><?php echo esc_html( vila_akoya_event_kind_label( $data['kind'] ) ); ?></span>
		</a>
		<div class="vila-event-card__body">
			<?php if ( $schedule ) : ?>
				<p class="vila-event-card__when"><?php echo esc_html( $schedule ); ?></p>
			<?php endif; ?>
			<h3 class="vila-event-card__title">
				<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
			</h3>
			<?php if ( $excerpt ) : ?>
				<p class="vila-event-card__excerpt"><?php echo esc_html( wp_strip_all_tags( $excerpt ) ); ?></p>
			<?php endif; ?>
			<div class="vila-event-card__meta">
				<?php if ( $data['price'] ) : ?>
					<span><?php echo esc_html( $data['price'] ); ?></span>
				<?php endif; ?>
				<?php if ( $data['capacity'] > 0 ) : ?>
					<span><?php echo esc_html( sprintf( /* translators: %d: seats */ __( '%d places', 'vila-akoya' ), $data['capacity'] ) ); ?></span>
				<?php endif; ?>
				<?php if ( $data['reservation_required'] ) : ?>
					<a class="vila-event-card__cta" href="<?php echo esc_url( $permalink ); ?>#request-a-place"><?php esc_html_e( 'Request a place', 'vila-akoya' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	</article>
	<?php
	return (string) ob_get_clean();
}

/**
 * Single event layout shortcode.
 *
 * @return string
 */
function vila_akoya_shortcode_event_single() {
	if ( ! is_singular( 'event' ) ) {
		return '';
	}
	$post_id = get_the_ID();
	$data    = vila_akoya_get_event_data( $post_id );
	$schedule = vila_akoya_format_event_schedule( $data );

	ob_start();
	?>
	<div class="max-w-[720px] mx-auto px-8 py-12 md:py-16">
		<p class="mb-6">
			<a class="font-label text-sm uppercase tracking-[0.14em] text-primary no-underline hover:text-primary-container" href="<?php echo esc_url( get_post_type_archive_link( 'event' ) ); ?>">← <?php esc_html_e( 'All events', 'vila-akoya' ); ?></a>
		</p>
		<?php if ( has_post_thumbnail( $post_id ) ) : ?>
			<div class="vila-single-post__featured rounded-xl overflow-hidden mb-8 editorial-shadow">
				<?php echo get_the_post_thumbnail( $post_id, 'large', array( 'class' => 'w-full object-cover' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		<?php endif; ?>
		<p class="mb-4">
			<span class="vila-event-badge vila-event-badge--<?php echo esc_attr( $data['kind'] ); ?>"><?php echo esc_html( vila_akoya_event_kind_label( $data['kind'] ) ); ?></span>
		</p>
		<?php if ( $schedule ) : ?>
			<p class="font-label text-xs uppercase tracking-[0.2em] text-on-surface-variant mb-4"><?php echo esc_html( $schedule ); ?></p>
		<?php endif; ?>
		<h1 class="font-headline text-4xl md:text-5xl text-on-surface leading-[1.15] mb-6"><?php the_title(); ?></h1>

		<ul class="font-body text-base text-on-surface-variant mb-10 space-y-2 list-none pl-0">
			<?php if ( $data['location'] ) : ?>
				<li><strong class="text-on-surface"><?php esc_html_e( 'Where', 'vila-akoya' ); ?>:</strong> <?php echo esc_html( $data['location'] ); ?></li>
			<?php endif; ?>
			<?php if ( $data['price'] ) : ?>
				<li><strong class="text-on-surface"><?php esc_html_e( 'Price', 'vila-akoya' ); ?>:</strong> <?php echo esc_html( $data['price'] ); ?></li>
			<?php endif; ?>
			<?php if ( $data['capacity'] > 0 ) : ?>
				<li><strong class="text-on-surface"><?php esc_html_e( 'Places', 'vila-akoya' ); ?>:</strong> <?php echo esc_html( (string) $data['capacity'] ); ?></li>
			<?php endif; ?>
		</ul>

		<div class="vila-single-post wp-block-post-content<?php echo $data['reservation_required'] ? ' mb-14' : ''; ?>">
			<?php the_content(); ?>
		</div>

		<?php if ( $data['reservation_required'] ) : ?>
		<section class="va-campus-form-card max-w-3xl mx-auto bg-surface-container-low rounded-[2rem] p-8 md:p-10 border border-outline-variant/20 shadow-sm" id="request-a-place">
			<h2 class="font-headline text-2xl md:text-3xl text-on-surface mb-2"><?php esc_html_e( 'Request a place', 'vila-akoya' ); ?></h2>
			<p class="font-body text-on-surface-variant mb-8"><?php esc_html_e( 'Tell us a little about yourself and how many places you need. We will confirm availability and next steps by email.', 'vila-akoya' ); ?></p>
			<?php echo vila_akoya_render_event_inquiry_form( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'vila_event_single', 'vila_akoya_shortcode_event_single' );

/**
 * Render CF7 inquiry form with event context.
 *
 * @param int $post_id Event ID.
 * @return string
 */
function vila_akoya_render_event_inquiry_form( $post_id ) {
	$post_id = (int) $post_id;
	if ( ! function_exists( 'vila_akoya_get_cf7_form_ids' ) ) {
		return '<p class="font-body text-on-surface-variant">' . esc_html__( 'Inquiry form is not available yet.', 'vila-akoya' ) . '</p>';
	}
	$ids = vila_akoya_get_cf7_form_ids();
	if ( empty( $ids['event_inquiry'] ) ) {
		return '<p class="font-body text-on-surface-variant">' . esc_html__( 'Inquiry form is not available yet.', 'vila-akoya' ) . '</p>';
	}

	$GLOBALS['vila_akoya_event_inquiry_context'] = array(
		'title' => get_the_title( $post_id ),
		'url'   => get_permalink( $post_id ),
		'id'    => (string) $post_id,
	);

	$html = do_shortcode(
		sprintf(
			'[contact-form-7 id="%s" html_class="vila-cf7 vila-cf7--event"]',
			esc_attr( (string) $ids['event_inquiry'] )
		)
	);

	unset( $GLOBALS['vila_akoya_event_inquiry_context'] );

	return is_string( $html ) ? $html : '';
}

/**
 * Inject event context as CF7 hidden fields when rendering the inquiry form.
 *
 * @param array<string, string> $fields Existing hidden fields.
 * @return array<string, string>
 */
function vila_akoya_cf7_event_inquiry_hidden_fields( $fields ) {
	if ( empty( $GLOBALS['vila_akoya_event_inquiry_context'] ) || ! is_array( $GLOBALS['vila_akoya_event_inquiry_context'] ) ) {
		return $fields;
	}
	$ctx                   = $GLOBALS['vila_akoya_event_inquiry_context'];
	$fields['event-title'] = isset( $ctx['title'] ) ? (string) $ctx['title'] : '';
	$fields['event-url']   = isset( $ctx['url'] ) ? (string) $ctx['url'] : '';
	$fields['event-id']    = isset( $ctx['id'] ) ? (string) $ctx['id'] : '';
	return $fields;
}
add_filter( 'wpcf7_form_hidden_fields', 'vila_akoya_cf7_event_inquiry_hidden_fields' );

/**
 * Admin list columns for events.
 *
 * @param array<string, string> $columns Columns.
 * @return array<string, string>
 */
function vila_akoya_event_admin_columns( $columns ) {
	$new = array();
	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;
		if ( 'title' === $key ) {
			$new['vila_kind']     = __( 'Type', 'vila-akoya' );
			$new['vila_schedule'] = __( 'Schedule', 'vila-akoya' );
			$new['vila_price']    = __( 'Price', 'vila-akoya' );
			$new['vila_capacity'] = __( 'Places', 'vila-akoya' );
		}
	}
	return $new;
}
add_filter( 'manage_event_posts_columns', 'vila_akoya_event_admin_columns' );

/**
 * Render admin list column values.
 *
 * @param string $column  Column key.
 * @param int    $post_id Post ID.
 * @return void
 */
function vila_akoya_event_admin_column_content( $column, $post_id ) {
	$data = vila_akoya_get_event_data( $post_id );
	if ( 'vila_kind' === $column ) {
		echo esc_html( vila_akoya_event_kind_label( $data['kind'] ) );
		return;
	}
	if ( 'vila_schedule' === $column ) {
		echo esc_html( vila_akoya_format_event_schedule( $data ) );
		return;
	}
	if ( 'vila_price' === $column ) {
		echo esc_html( $data['price'] ? $data['price'] : '—' );
		return;
	}
	if ( 'vila_capacity' === $column ) {
		echo esc_html( $data['capacity'] > 0 ? (string) $data['capacity'] : '—' );
	}
}
add_action( 'manage_event_posts_custom_column', 'vila_akoya_event_admin_column_content', 10, 2 );

/**
 * Body class for event templates.
 *
 * @param string[] $classes Classes.
 * @return string[]
 */
function vila_akoya_event_body_class( $classes ) {
	if ( is_post_type_archive( 'event' ) ) {
		$classes[] = 'vila-events-archive';
		$classes[] = 'vila-blog-archive';
	}
	if ( is_singular( 'event' ) ) {
		$classes[] = 'vila-single-event';
		$classes[] = 'vila-single-post';
	}
	return $classes;
}
add_filter( 'body_class', 'vila_akoya_event_body_class' );

/**
 * Archive list/calendar script.
 *
 * @return void
 */
function vila_akoya_enqueue_events_archive_assets() {
	if ( ! is_post_type_archive( 'event' ) ) {
		return;
	}
	wp_enqueue_script(
		'vila-akoya-events-archive',
		VILA_AKOYA_URI . '/assets/js/events-archive.js',
		array(),
		VILA_AKOYA_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'vila_akoya_enqueue_events_archive_assets' );
