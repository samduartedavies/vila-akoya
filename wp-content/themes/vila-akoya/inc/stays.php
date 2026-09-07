<?php
/**
 * Village stays — tiny houses, caravans and other bookable accommodation.
 *
 * Each stay is a public CPT at /stays/{slug}/ with photos, description, reviews,
 * and a linked hidden WooCommerce bookable product for its own calendar and price.
 *
 * @package Vila_Akoya
 */

defined( 'ABSPATH' ) || exit;

define( 'VILA_AKOYA_STAYS_REWRITE_VER', '1' );
define( 'VILA_AKOYA_STAY_PRODUCT_IDS_OPTION', 'vila_akoya_stay_product_ids' );
if ( ! defined( 'VILA_AKOYA_STAY_ONLINE_BOOKING' ) ) {
	define( 'VILA_AKOYA_STAY_ONLINE_BOOKING', true );
}

/**
 * Register Stay post type (archive at /stays/).
 */
function vila_akoya_register_stay_cpt() {
	register_post_type(
		'stay',
		array(
			'labels'              => array(
				'name'               => __( 'Village stays', 'vila-akoya' ),
				'singular_name'      => __( 'Stay', 'vila-akoya' ),
				'add_new'            => __( 'Add stay', 'vila-akoya' ),
				'add_new_item'       => __( 'Add new stay', 'vila-akoya' ),
				'edit_item'          => __( 'Edit stay', 'vila-akoya' ),
				'new_item'           => __( 'New stay', 'vila-akoya' ),
				'view_item'          => __( 'View stay', 'vila-akoya' ),
				'search_items'       => __( 'Search stays', 'vila-akoya' ),
				'not_found'          => __( 'No stays found', 'vila-akoya' ),
				'not_found_in_trash' => __( 'No stays found in Trash', 'vila-akoya' ),
				'all_items'          => __( 'All stays', 'vila-akoya' ),
				'menu_name'          => __( 'Village stays', 'vila-akoya' ),
			),
			'public'              => true,
			'has_archive'         => 'stays',
			'rewrite'             => array(
				'slug'       => 'stays',
				'with_front' => false,
			),
			'menu_icon'           => 'dashicons-admin-home',
			'menu_position'       => 22,
			'show_in_rest'        => true,
			'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt', 'comments' ),
			'exclude_from_search' => false,
		)
	);
}
add_action( 'init', 'vila_akoya_register_stay_cpt' );

/**
 * Pretty URLs for the public review page: /review-accommodation/{stay-slug}/.
 */
function vila_akoya_register_stay_review_rewrites() {
	add_rewrite_tag( '%va_review_stay%', '([^/]+)' );
	add_rewrite_rule(
		'^review-accommodation/([^/]+)/?$',
		'index.php?pagename=review-accommodation&va_review_stay=$matches[1]',
		'top'
	);
}
add_action( 'init', 'vila_akoya_register_stay_review_rewrites', 20 );

/**
 * @param string[] $vars Query vars.
 * @return string[]
 */
function vila_akoya_register_stay_review_query_var( $vars ) {
	$vars[] = 'va_review_stay';
	return $vars;
}
add_filter( 'query_vars', 'vila_akoya_register_stay_review_query_var' );

/**
 * Flush rewrites when review URLs are introduced.
 */
function vila_akoya_maybe_flush_stay_review_rewrites() {
	if ( '1' === get_option( 'vila_akoya_stay_review_rewrites_ready' ) ) {
		return;
	}
	if ( function_exists( 'vila_akoya_flush_rewrite_rules_hard' ) ) {
		vila_akoya_flush_rewrite_rules_hard();
	} else {
		flush_rewrite_rules( false );
	}
	update_option( 'vila_akoya_stay_review_rewrites_ready', '1' );
}
add_action( 'init', 'vila_akoya_maybe_flush_stay_review_rewrites', 99 );

/**
 * Flush rewrites once after Stays CPT is introduced.
 */
function vila_akoya_maybe_flush_stays_rewrites() {
	if ( (string) get_option( 'vila_akoya_stays_rewrite_ver', '' ) === VILA_AKOYA_STAYS_REWRITE_VER ) {
		return;
	}
	if ( function_exists( 'vila_akoya_flush_rewrite_rules_hard' ) ) {
		vila_akoya_flush_rewrite_rules_hard();
	} else {
		flush_rewrite_rules( false );
	}
	update_option( 'vila_akoya_stays_rewrite_ver', VILA_AKOYA_STAYS_REWRITE_VER );
}
add_action( 'init', 'vila_akoya_maybe_flush_stays_rewrites', 99 );

/**
 * @return array<string, string>
 */
function vila_akoya_stay_type_labels() {
	return array(
		'tiny-house' => __( 'Tiny house', 'vila-akoya' ),
		'caravan'    => __( 'Caravan', 'vila-akoya' ),
		'cabin'      => __( 'Cabin', 'vila-akoya' ),
		'unique'     => __( 'Unique stay', 'vila-akoya' ),
		'other'      => __( 'Stay', 'vila-akoya' ),
	);
}

/**
 * @return array<string, string>
 */
function vila_akoya_stay_amenity_labels() {
	return array(
		'kitchen'     => __( 'Kitchen', 'vila-akoya' ),
		'bathroom'    => __( 'Private bathroom', 'vila-akoya' ),
		'outdoor'     => __( 'Outdoor shower', 'vila-akoya' ),
		'heating'     => __( 'Heating', 'vila-akoya' ),
		'electricity' => __( 'Electricity', 'vila-akoya' ),
		'wifi'        => __( 'Wi-Fi', 'vila-akoya' ),
		'terrace'     => __( 'Terrace', 'vila-akoya' ),
		'linens'      => __( 'Linens', 'vila-akoya' ),
		'towels'      => __( 'Towels', 'vila-akoya' ),
		'pets'        => __( 'Pets welcome', 'vila-akoya' ),
	);
}

/**
 * @param int    $post_id Stay ID.
 * @param string $key     Meta key without prefix.
 * @param mixed  $default Default.
 * @return mixed
 */
function vila_akoya_get_stay_meta( $post_id, $key, $default = '' ) {
	$value = get_post_meta( (int) $post_id, '_vila_stay_' . $key, true );
	if ( '' === $value || null === $value ) {
		return $default;
	}
	return $value;
}

/**
 * Normalized stay data for display.
 *
 * @param int $post_id Stay ID.
 * @return array<string, mixed>
 */
function vila_akoya_get_stay_data( $post_id ) {
	$post_id  = (int) $post_id;
	$type     = (string) vila_akoya_get_stay_meta( $post_id, 'type', 'tiny-house' );
	$labels   = vila_akoya_stay_type_labels();
	$amenity_raw = vila_akoya_get_stay_meta( $post_id, 'amenities', array() );
	if ( ! is_array( $amenity_raw ) ) {
		$amenity_raw = array_filter( array_map( 'sanitize_key', explode( ',', (string) $amenity_raw ) ) );
	}
	$gallery = vila_akoya_get_stay_meta( $post_id, 'gallery', array() );
	if ( is_string( $gallery ) ) {
		$gallery = array_filter( array_map( 'absint', explode( ',', $gallery ) ) );
	}
	if ( ! is_array( $gallery ) ) {
		$gallery = array();
	}
	$gallery = array_values( array_filter( array_map( 'absint', $gallery ) ) );

	$sleeps  = max( 1, (int) vila_akoya_get_stay_meta( $post_id, 'sleeps', 2 ) );
	$nightly = (float) vila_akoya_get_stay_meta( $post_id, 'nightly', 0 );
	$pets    = (string) vila_akoya_get_stay_meta( $post_id, 'pets', 'no' ) === 'yes';
	$product_id = (int) vila_akoya_get_stay_meta( $post_id, 'product_id', 0 );
	$reviews = vila_akoya_get_stay_review_summary( $post_id );

	return array(
		'type'        => isset( $labels[ $type ] ) ? $type : 'other',
		'type_label'  => isset( $labels[ $type ] ) ? $labels[ $type ] : $labels['other'],
		'sleeps'      => $sleeps,
		'nightly'     => $nightly,
		'min_nights'  => max( 1, (int) vila_akoya_get_stay_meta( $post_id, 'min_nights', 1 ) ),
		'pets'        => $pets,
		'amenities'   => array_values( array_filter( array_map( 'sanitize_key', $amenity_raw ) ) ),
		'gallery'     => $gallery,
		'product_id'  => $product_id,
		'review_avg'  => $reviews['average'],
		'review_count'=> $reviews['count'],
	);
}

/**
 * @param int $product_id WooCommerce product ID.
 * @return bool
 */
function vila_akoya_is_stay_product_id( $product_id ) {
	$product_id = (int) $product_id;
	if ( $product_id < 1 ) {
		return false;
	}
	$stay_id = (int) get_post_meta( $product_id, '_vila_stay_id', true );
	return $stay_id > 0 && 'stay' === get_post_type( $stay_id );
}

/**
 * Campsite pitch or village stay — both go through the booking checkout flow.
 *
 * @param int $product_id Product ID.
 * @return bool
 */
function vila_akoya_is_bookable_product_id( $product_id ) {
	$product_id = (int) $product_id;
	if ( $product_id < 1 ) {
		return false;
	}
	if ( function_exists( 'vila_akoya_is_campsite_product_id' ) && vila_akoya_is_campsite_product_id( $product_id ) ) {
		return true;
	}
	return vila_akoya_is_stay_product_id( $product_id );
}

/**
 * @param int $product_id Product ID.
 * @return int Stay post ID or 0.
 */
function vila_akoya_stay_id_from_product_id( $product_id ) {
	return (int) get_post_meta( (int) $product_id, '_vila_stay_id', true );
}

/**
 * Published stay product IDs (for availability cache invalidation).
 *
 * @return int[]
 */
function vila_akoya_get_stay_product_ids() {
	$ids = get_option( VILA_AKOYA_STAY_PRODUCT_IDS_OPTION, array() );
	if ( ! is_array( $ids ) ) {
		return array();
	}
	$out = array();
	foreach ( $ids as $id ) {
		$id = (int) $id;
		if ( $id && get_post_status( $id ) ) {
			$out[] = $id;
		}
	}
	return $out;
}

/**
 * @param int[] $ids Product IDs.
 */
function vila_akoya_store_stay_product_ids( array $ids ) {
	$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	update_option( VILA_AKOYA_STAY_PRODUCT_IDS_OPTION, $ids, false );
}

/**
 * Published stays for browse grids.
 *
 * @return WP_Post[]
 */
function vila_akoya_get_published_stays() {
	$posts = get_posts(
		array(
			'post_type'      => 'stay',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
		)
	);
	return is_array( $posts ) ? $posts : array();
}

/**
 * Create or update the hidden bookable WooCommerce product for a stay.
 *
 * @param int $stay_id Stay post ID.
 * @return int Product ID or 0.
 */
function vila_akoya_sync_stay_booking_product( $stay_id ) {
	$stay_id = (int) $stay_id;
	$stay    = get_post( $stay_id );
	if ( ! $stay || 'stay' !== $stay->post_type ) {
		return 0;
	}
	if ( ! function_exists( 'wc_get_product_id_by_sku' ) || ! function_exists( 'wps_booking_get_meta_data' ) ) {
		return (int) vila_akoya_get_stay_meta( $stay_id, 'product_id', 0 );
	}

	$data    = vila_akoya_get_stay_data( $stay_id );
	$sku     = 'vila-stay-' . $stay_id;
	$price   = $data['nightly'] > 0 ? (string) $data['nightly'] : '0';
	$found   = (int) $data['product_id'];
	if ( $found < 1 ) {
		$by_sku = (int) wc_get_product_id_by_sku( $sku );
		if ( $by_sku > 0 ) {
			$found = $by_sku;
		}
	}

	$title = $stay->post_title ? $stay->post_title : __( 'Village stay', 'vila-akoya' );
	$status = 'publish' === $stay->post_status ? 'publish' : 'draft';

	if ( $found < 1 ) {
		$found = (int) wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => '',
				'post_excerpt' => '',
				'post_status'  => $status,
				'post_type'      => 'product',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);
		if ( is_wp_error( $found ) || $found < 1 ) {
			return 0;
		}
	} else {
		wp_update_post(
			array(
				'ID'             => $found,
				'post_title'     => $title,
				'post_status'    => $status,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);
	}

	wp_set_object_terms( $found, 'mwb_booking', 'product_type' );

	update_post_meta( $found, '_sku', $sku );
	update_post_meta( $found, '_virtual', 'yes' );
	update_post_meta( $found, '_downloadable', 'no' );
	update_post_meta( $found, '_sold_individually', 'yes' );
	update_post_meta( $found, '_manage_stock', 'no' );
	update_post_meta( $found, '_stock_status', 'instock' );
	update_post_meta( $found, '_regular_price', $price );
	update_post_meta( $found, '_price', $price );
	update_post_meta( $found, '_catalog_visibility', 'hidden' );
	update_post_meta( $found, '_vila_stay_id', $stay_id );

	update_post_meta( $found, 'wps_mbfw_booking_type', 'dual_cal' );
	update_post_meta( $found, 'mwb_mbfw_booking_criteria', 'customer_selected_unit' );
	update_post_meta( $found, 'mwb_mbfw_booking_count', '1' );
	update_post_meta( $found, 'mwb_mbfw_booking_unit', 'day' );
	update_post_meta( $found, 'mwb_mbfw_enable_calendar', 'yes' );
	update_post_meta( $found, '_is_calendar_booking_product', 'yes' );
	update_post_meta( $found, 'mwb_mbfw_booking_unit_cost', $price );
	update_post_meta( $found, 'mwb_mbfw_admin_confirmation', 'no' );
	update_post_meta( $found, 'mwb_mbfw_cancellation_allowed', 'yes' );
	update_post_meta( $found, 'mwb_mbfw_show_date_with_time', 'no' );
	update_post_meta( $found, 'mwb_mbfw_enable_time_picker', 'no' );
	update_post_meta( $found, 'mwb_mbfw_max_bookings', '1' );
	update_post_meta( $found, 'mwb_mbfw_is_people_option', 'yes' );
	update_post_meta( $found, 'mwb_mbfw_minimum_people_per_booking', '1' );
	update_post_meta( $found, 'mwb_mbfw_maximum_people_per_booking', (string) $data['sleeps'] );
	update_post_meta( $found, 'mwb_mbfw_is_booking_unit_cost_per_people', 'no' );
	update_post_meta( $found, 'mwb_mbfw_is_booking_base_cost_per_people', 'no' );
	$min_nights = max( 1, (int) $data['min_nights'] );
	update_post_meta( $found, 'mwb_mbfw_minimum_booking_unit', (string) $min_nights );

	if ( function_exists( 'vila_akoya_mwb_availability_dates' ) ) {
		$avail = vila_akoya_mwb_availability_dates( 548 );
		update_post_meta( $found, 'wps_mbfw_set_availability', $avail['dates'] );
		update_post_meta( $found, 'wps_mbfw_set_availability_upto', $avail['until'] );
	}

	if ( has_post_thumbnail( $stay_id ) ) {
		set_post_thumbnail( $found, (int) get_post_thumbnail_id( $stay_id ) );
	}

	update_post_meta( $stay_id, '_vila_stay_product_id', $found );

	$ids = vila_akoya_get_stay_product_ids();
	if ( ! in_array( $found, $ids, true ) ) {
		$ids[] = $found;
		vila_akoya_store_stay_product_ids( $ids );
	}

	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients( $found );
	}

	return $found;
}

/**
 * Sync product when a stay is saved.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post.
 * @param bool    $update  Whether this is an update.
 */
function vila_akoya_save_stay_meta( $post_id, $post, $update ) {
	unset( $update );
	if ( ! $post instanceof WP_Post || 'stay' !== $post->post_type ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$nonce = isset( $_POST['vila_stay_meta_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['vila_stay_meta_nonce'] ) ) : '';
	if ( $nonce && wp_verify_nonce( $nonce, 'vila_stay_meta' ) ) {
		$type = isset( $_POST['vila_stay_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['vila_stay_type'] ) ) : 'tiny-house';
		if ( ! isset( vila_akoya_stay_type_labels()[ $type ] ) ) {
			$type = 'other';
		}
		update_post_meta( $post_id, '_vila_stay_type', $type );
		update_post_meta( $post_id, '_vila_stay_sleeps', max( 1, (int) ( isset( $_POST['vila_stay_sleeps'] ) ? wp_unslash( $_POST['vila_stay_sleeps'] ) : 2 ) ) );
		$nightly = isset( $_POST['vila_stay_nightly'] ) ? (float) wp_unslash( $_POST['vila_stay_nightly'] ) : 0;
		update_post_meta( $post_id, '_vila_stay_nightly', max( 0, $nightly ) );
		update_post_meta( $post_id, '_vila_stay_min_nights', max( 1, (int) ( isset( $_POST['vila_stay_min_nights'] ) ? wp_unslash( $_POST['vila_stay_min_nights'] ) : 1 ) ) );
		$pets = ! empty( $_POST['vila_stay_pets'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, '_vila_stay_pets', $pets );

		$amenities = array();
		if ( isset( $_POST['vila_stay_amenities'] ) && is_array( $_POST['vila_stay_amenities'] ) ) {
			$allowed = array_keys( vila_akoya_stay_amenity_labels() );
			foreach ( $_POST['vila_stay_amenities'] as $slug ) {
				$slug = sanitize_key( wp_unslash( (string) $slug ) );
				if ( in_array( $slug, $allowed, true ) ) {
					$amenities[] = $slug;
				}
			}
		}
		if ( 'yes' === $pets && ! in_array( 'pets', $amenities, true ) ) {
			$amenities[] = 'pets';
		}
		update_post_meta( $post_id, '_vila_stay_amenities', $amenities );

		$gallery = array();
		if ( isset( $_POST['vila_stay_gallery'] ) ) {
			$raw = wp_unslash( $_POST['vila_stay_gallery'] );
			if ( is_array( $raw ) ) {
				$gallery = array_values( array_filter( array_map( 'absint', $raw ) ) );
			} else {
				$gallery = array_values( array_filter( array_map( 'absint', explode( ',', (string) $raw ) ) ) );
			}
		}
		update_post_meta( $post_id, '_vila_stay_gallery', $gallery );
	}

	vila_akoya_sync_stay_booking_product( $post_id );
}
add_action( 'save_post_stay', 'vila_akoya_save_stay_meta', 20, 3 );

/**
 * Admin metabox.
 */
function vila_akoya_stay_add_meta_boxes() {
	add_meta_box(
		'vila-stay-details',
		__( 'Stay details', 'vila-akoya' ),
		'vila_akoya_stay_render_meta_box',
		'stay',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'vila_akoya_stay_add_meta_boxes' );

/**
 * @param WP_Post $post Post.
 */
function vila_akoya_stay_render_meta_box( $post ) {
	$data = vila_akoya_get_stay_data( (int) $post->ID );
	wp_nonce_field( 'vila_stay_meta', 'vila_stay_meta_nonce' );
	$types     = vila_akoya_stay_type_labels();
	$amenities = vila_akoya_stay_amenity_labels();
	?>
	<style>
		.vila-stay-admin label.vila-stay-admin__lbl { display:block; font-weight:600; margin:0 0 .35rem; }
		.vila-stay-admin .vila-stay-admin__row { margin:0 0 1rem; }
		.vila-stay-admin .vila-stay-admin__grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:.75rem 1.25rem; }
		.vila-stay-admin .vila-stay-admin__chips { display:flex; flex-wrap:wrap; gap:.5rem 1rem; }
		.vila-stay-admin input[type="number"], .vila-stay-admin select { max-width:14rem; }
		.vila-stay-admin__gallery { display:flex; flex-wrap:wrap; gap:.75rem; margin:.5rem 0 0; }
		.vila-stay-admin__thumb { position:relative; width:96px; height:96px; border-radius:8px; overflow:hidden; background:#f3f0e7; }
		.vila-stay-admin__thumb img { width:100%; height:100%; object-fit:cover; display:block; }
		.vila-stay-admin__thumb-remove { position:absolute; top:4px; right:4px; border:0; border-radius:999px; width:22px; height:22px; background:#fff; cursor:pointer; line-height:1; }
	</style>
	<div class="vila-stay-admin">
		<p class="vila-stay-admin__row">
			<label class="vila-stay-admin__lbl" for="vila_stay_type"><?php esc_html_e( 'Type', 'vila-akoya' ); ?></label>
			<select name="vila_stay_type" id="vila_stay_type">
				<?php foreach ( $types as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $data['type'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<div class="vila-stay-admin__grid">
			<p class="vila-stay-admin__row">
				<label class="vila-stay-admin__lbl" for="vila_stay_nightly"><?php esc_html_e( 'Price per night (€)', 'vila-akoya' ); ?></label>
				<input type="number" min="0" step="0.01" name="vila_stay_nightly" id="vila_stay_nightly" value="<?php echo esc_attr( (string) $data['nightly'] ); ?>" />
			</p>
			<p class="vila-stay-admin__row">
				<label class="vila-stay-admin__lbl" for="vila_stay_sleeps"><?php esc_html_e( 'Sleeps (adults and children)', 'vila-akoya' ); ?></label>
				<input type="number" min="1" max="20" name="vila_stay_sleeps" id="vila_stay_sleeps" value="<?php echo esc_attr( (string) $data['sleeps'] ); ?>" />
			</p>
			<p class="vila-stay-admin__row">
				<label class="vila-stay-admin__lbl" for="vila_stay_min_nights"><?php esc_html_e( 'Minimum nights', 'vila-akoya' ); ?></label>
				<input type="number" min="1" max="30" name="vila_stay_min_nights" id="vila_stay_min_nights" value="<?php echo esc_attr( (string) $data['min_nights'] ); ?>" />
			</p>
		</div>
		<p class="vila-stay-admin__row">
			<label>
				<input type="checkbox" name="vila_stay_pets" value="1" <?php checked( $data['pets'] ); ?> />
				<?php esc_html_e( 'Pets are welcome', 'vila-akoya' ); ?>
			</label>
		</p>
		<div class="vila-stay-admin__row">
			<span class="vila-stay-admin__lbl"><?php esc_html_e( 'Amenities', 'vila-akoya' ); ?></span>
			<div class="vila-stay-admin__chips">
				<?php foreach ( $amenities as $slug => $label ) : ?>
					<label>
						<input type="checkbox" name="vila_stay_amenities[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $data['amenities'], true ) ); ?> />
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="vila-stay-admin__row">
			<span class="vila-stay-admin__lbl"><?php esc_html_e( 'Photo gallery', 'vila-akoya' ); ?></span>
			<p class="description"><?php esc_html_e( 'Use the featured image as the cover photo, then add extra photos here. Guests see them on the stay page.', 'vila-akoya' ); ?></p>
			<input type="hidden" name="vila_stay_gallery" id="vila_stay_gallery" value="<?php echo esc_attr( implode( ',', $data['gallery'] ) ); ?>" />
			<div class="vila-stay-admin__gallery" data-vila-stay-gallery>
				<?php foreach ( $data['gallery'] as $att_id ) : ?>
					<?php $src = wp_get_attachment_image_url( (int) $att_id, 'thumbnail' ); ?>
					<?php if ( $src ) : ?>
						<div class="vila-stay-admin__thumb" data-id="<?php echo esc_attr( (string) (int) $att_id ); ?>">
							<img src="<?php echo esc_url( $src ); ?>" alt="" />
							<button type="button" class="vila-stay-admin__thumb-remove" aria-label="<?php esc_attr_e( 'Remove photo', 'vila-akoya' ); ?>">&times;</button>
						</div>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
			<p>
				<button type="button" class="button" data-vila-stay-gallery-add><?php esc_html_e( 'Add photos', 'vila-akoya' ); ?></button>
			</p>
		</div>
		<?php if ( $data['product_id'] ) : ?>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: WooCommerce product ID */
						__( 'Linked booking product ID: %d (created automatically — guests book from this stay page).', 'vila-akoya' ),
						(int) $data['product_id']
					)
				);
				?>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Media uploader for stay gallery.
 *
 * @param string $hook_suffix Screen.
 */
function vila_akoya_stay_admin_assets( $hook_suffix ) {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'stay' !== $screen->post_type ) {
		return;
	}
	if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}
	wp_enqueue_media();
	wp_add_inline_script(
		'jquery-core',
		<<<'JS'
(function($){$(function(){var $input=$('#vila_stay_gallery');var $grid=$('[data-vila-stay-gallery]');function ids(){return String($input.val()||'').split(',').map(function(s){return parseInt(s,10)||0;}).filter(Boolean);}function write(list){$input.val(list.join(','));}function thumb(id,url){return $('<div class="vila-stay-admin__thumb" data-id="'+id+'"><img src="'+url+'" alt="" /><button type="button" class="vila-stay-admin__thumb-remove" aria-label="Remove">&times;</button></div>');}$('[data-vila-stay-gallery-add]').on('click',function(e){e.preventDefault();var frame=wp.media({title:'Stay photos',multiple:true,library:{type:'image'}});frame.on('select',function(){var list=ids();frame.state().get('selection').each(function(att){att=att.toJSON();if(list.indexOf(att.id)===-1){list.push(att.id);$grid.append(thumb(att.id,att.sizes&&att.sizes.thumbnail?att.sizes.thumbnail.url:att.url));}});write(list);});frame.open();});$grid.on('click','.vila-stay-admin__thumb-remove',function(){var $t=$(this).closest('[data-id]');var id=parseInt($t.attr('data-id'),10);$t.remove();write(ids().filter(function(n){return n!==id;}));});});})(jQuery);
JS
	);
}
add_action( 'admin_enqueue_scripts', 'vila_akoya_stay_admin_assets' );

/**
 * Hide stay products from the shop catalog.
 *
 * @param bool       $visible Visible.
 * @param WC_Product $product Product.
 * @return bool
 */
function vila_akoya_stay_product_not_visible_in_catalog( $visible, $product ) {
	if ( $product && is_a( $product, 'WC_Product' ) && vila_akoya_is_stay_product_id( (int) $product->get_id() ) ) {
		return false;
	}
	return $visible;
}
add_filter( 'woocommerce_product_is_visible', 'vila_akoya_stay_product_not_visible_in_catalog', 20, 2 );

/**
 * Direct visits to the hidden WC product go to the public stay page.
 */
function vila_akoya_redirect_stay_product_to_stay() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	$pid = (int) get_queried_object_id();
	if ( ! vila_akoya_is_stay_product_id( $pid ) ) {
		return;
	}
	$stay_id = vila_akoya_stay_id_from_product_id( $pid );
	if ( $stay_id < 1 ) {
		return;
	}
	$url = get_permalink( $stay_id );
	if ( is_string( $url ) && '' !== $url ) {
		wp_safe_redirect( $url, 301 );
		exit;
	}
}
add_action( 'template_redirect', 'vila_akoya_redirect_stay_product_to_stay', 6 );

/**
 * Review summary for a stay.
 *
 * @param int $stay_id Stay ID.
 * @return array{average: float, count: int}
 */
function vila_akoya_get_stay_review_summary( $stay_id ) {
	$stay_id = (int) $stay_id;
	$comments = get_comments(
		array(
			'post_id' => $stay_id,
			'status'  => 'approve',
			'type'    => 'comment',
			'count'   => false,
		)
	);
	$sum   = 0;
	$count = 0;
	foreach ( (array) $comments as $comment ) {
		$rating = (int) get_comment_meta( (int) $comment->comment_ID, '_vila_stay_rating', true );
		if ( $rating >= 1 && $rating <= 5 ) {
			$sum  += $rating;
			$count++;
		}
	}
	return array(
		'average' => $count > 0 ? round( $sum / $count, 1 ) : 0.0,
		'count'   => $count,
	);
}

/**
 * Save star rating with a stay review.
 *
 * @param int        $comment_id Comment ID.
 * @param int|string $approved   Approval status.
 */
function vila_akoya_save_stay_review_rating( $comment_id, $approved ) {
	unset( $approved );
	$comment = get_comment( $comment_id );
	if ( ! $comment || 'stay' !== get_post_type( (int) $comment->comment_post_ID ) ) {
		return;
	}
	$rating = isset( $_POST['vila_stay_rating'] ) ? (int) wp_unslash( $_POST['vila_stay_rating'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( $rating >= 1 && $rating <= 5 ) {
		update_comment_meta( (int) $comment_id, '_vila_stay_rating', $rating );
	}
}
add_action( 'comment_post', 'vila_akoya_save_stay_review_rating', 10, 2 );

/**
 * Require a 1–5 rating on stay reviews.
 *
 * @param array<string, mixed> $commentdata Comment data.
 * @return array<string, mixed>
 */
function vila_akoya_require_stay_review_rating( $commentdata ) {
	$post_id = isset( $commentdata['comment_post_ID'] ) ? (int) $commentdata['comment_post_ID'] : 0;
	if ( $post_id && 'stay' === get_post_type( $post_id ) ) {
		$rating = isset( $_POST['vila_stay_rating'] ) ? (int) wp_unslash( $_POST['vila_stay_rating'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $rating < 1 || $rating > 5 ) {
			wp_die(
				esc_html__( 'Please choose a star rating for this stay.', 'vila-akoya' ),
				esc_html__( 'Rating required', 'vila-akoya' ),
				array( 'back_link' => true )
			);
		}
	}
	return $commentdata;
}
add_filter( 'preprocess_comment', 'vila_akoya_require_stay_review_rating' );

/**
 * @param float $average Average 0–5.
 * @param int   $count   Review count.
 * @return string
 */
function vila_akoya_stay_stars_html( $average, $count = 0 ) {
	$average = (float) $average;
	$count   = (int) $count;
	if ( $count < 1 || $average <= 0 ) {
		return '<span class="vila-stay-stars vila-stay-stars--empty">' . esc_html__( 'No reviews yet', 'vila-akoya' ) . '</span>';
	}
	$full = (int) floor( $average + 0.001 );
	$html = '<span class="vila-stay-stars" aria-label="' . esc_attr(
		sprintf(
			/* translators: 1: average rating, 2: number of reviews */
			_n( '%1$s out of 5 from %2$d review', '%1$s out of 5 from %2$d reviews', $count, 'vila-akoya' ),
			number_format_i18n( $average, 1 ),
			$count
		)
	) . '">';
	for ( $i = 1; $i <= 5; $i++ ) {
		$html .= $i <= $full ? '<span class="vila-stay-stars__on" aria-hidden="true">★</span>' : '<span class="vila-stay-stars__off" aria-hidden="true">★</span>';
	}
	$html .= '<span class="vila-stay-stars__num">' . esc_html( number_format_i18n( $average, 1 ) ) . '</span>';
	$html .= '</span>';
	return $html;
}

/**
 * Image IDs for a stay gallery (featured first, then extras).
 *
 * @param int $stay_id Stay ID.
 * @return int[]
 */
function vila_akoya_stay_gallery_ids( $stay_id ) {
	$data = vila_akoya_get_stay_data( (int) $stay_id );
	$ids  = array();
	$feat = (int) get_post_thumbnail_id( (int) $stay_id );
	if ( $feat > 0 ) {
		$ids[] = $feat;
	}
	foreach ( $data['gallery'] as $id ) {
		$id = (int) $id;
		if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
			$ids[] = $id;
		}
	}
	return $ids;
}

/**
 * Format nightly price.
 *
 * @param float $amount Amount.
 * @return string
 */
function vila_akoya_stay_price_label( $amount ) {
	$amount = (float) $amount;
	if ( $amount <= 0 ) {
		return '';
	}
	$formatted = function_exists( 'vila_akoya_format_price_plain' )
		? vila_akoya_format_price_plain( $amount )
		: ( '€' . (string) (int) round( $amount ) );
	return sprintf(
		/* translators: %s: price */
		__( '%s / night', 'vila-akoya' ),
		$formatted
	);
}

/**
 * Monthly stay rate (stay 30 nights, pay 20).
 *
 * @param float $nightly Nightly amount.
 * @return string
 */
function vila_akoya_stay_month_price_label( $nightly ) {
	$nightly = (float) $nightly;
	if ( $nightly <= 0 ) {
		return '';
	}
	$month     = $nightly * 20;
	$formatted = function_exists( 'vila_akoya_format_price_plain' )
		? vila_akoya_format_price_plain( $month )
		: ( '€' . (string) (int) round( $month ) );
	return sprintf(
		/* translators: %s: price */
		__( '%s / month', 'vila-akoya' ),
		$formatted
	);
}

/**
 * One stay card.
 *
 * @param int    $stay_id Stay ID.
 * @param string $layout  booking|archive.
 * @return string
 */
function vila_akoya_render_stay_card( $stay_id, $layout = 'archive' ) {
	$stay_id   = (int) $stay_id;
	$data      = vila_akoya_get_stay_data( $stay_id );
	$permalink = get_permalink( $stay_id );
	$title     = get_the_title( $stay_id );
	$post      = get_post( $stay_id );
	$excerpt   = '';
	if ( $post instanceof WP_Post ) {
		$excerpt = $post->post_excerpt
			? $post->post_excerpt
			: wp_trim_words( wp_strip_all_tags( $post->post_content ), 22 );
	}
	$price  = vila_akoya_stay_price_label( $data['nightly'] );
	$images = function_exists( 'vila_akoya_stay_gallery_ids' ) ? vila_akoya_stay_gallery_ids( $stay_id ) : array();
	ob_start();
	?>
	<article class="vila-stay-card vila-stay-card--<?php echo esc_attr( $layout ); ?>">
		<div class="vila-stay-card__media<?php echo count( $images ) > 1 ? ' vila-stay-card__media--carousel' : ''; ?>"<?php echo count( $images ) > 1 ? ' data-vila-stay-carousel data-vila-stay-carousel-index="0"' : ''; ?>>
			<?php if ( $images ) : ?>
				<div class="vila-stay-card__slides">
					<?php foreach ( $images as $i => $att_id ) : ?>
						<a class="vila-stay-card__slide<?php echo 0 === $i ? ' is-active' : ''; ?>" href="<?php echo esc_url( $permalink ); ?>" <?php echo 0 === $i ? '' : 'tabindex="-1" aria-hidden="true"'; ?>>
							<?php echo wp_get_attachment_image( (int) $att_id, 'large', false, array( 'alt' => $title, 'loading' => 0 === $i ? 'eager' : 'lazy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</a>
					<?php endforeach; ?>
				</div>
				<?php if ( count( $images ) > 1 ) : ?>
					<button type="button" class="vila-stay-card__nav vila-stay-card__nav--prev" data-vila-stay-carousel-prev aria-label="<?php esc_attr_e( 'Previous photo', 'vila-akoya' ); ?>">‹</button>
					<button type="button" class="vila-stay-card__nav vila-stay-card__nav--next" data-vila-stay-carousel-next aria-label="<?php esc_attr_e( 'Next photo', 'vila-akoya' ); ?>">›</button>
					<div class="vila-stay-card__dots" role="tablist" aria-label="<?php esc_attr_e( 'Photos', 'vila-akoya' ); ?>">
						<?php foreach ( $images as $i => $att_id ) : ?>
							<button type="button" class="vila-stay-card__dot<?php echo 0 === $i ? ' is-active' : ''; ?>" data-vila-stay-carousel-dot="<?php echo esc_attr( (string) $i ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: photo number */ __( 'Photo %d', 'vila-akoya' ), $i + 1 ) ); ?>"></button>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<a class="vila-stay-card__slide is-active" href="<?php echo esc_url( $permalink ); ?>">
					<span class="vila-stay-card__placeholder" aria-hidden="true"></span>
				</a>
			<?php endif; ?>
			<span class="vila-stay-card__badge"><?php echo esc_html( $data['type_label'] ); ?></span>
		</div>
		<div class="vila-stay-card__body">
			<p class="vila-stay-card__meta">
				<span><?php echo esc_html( sprintf( /* translators: %d: guest capacity */ _n( 'Sleeps %d', 'Sleeps %d', $data['sleeps'], 'vila-akoya' ), $data['sleeps'] ) ); ?></span>
				<?php if ( $data['pets'] ) : ?>
					<span><?php esc_html_e( 'Pets welcome', 'vila-akoya' ); ?></span>
				<?php endif; ?>
			</p>
			<h3 class="vila-stay-card__title">
				<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
			</h3>
			<?php if ( $excerpt ) : ?>
				<p class="vila-stay-card__excerpt"><?php echo esc_html( wp_strip_all_tags( $excerpt ) ); ?></p>
			<?php endif; ?>
			<div class="vila-stay-card__footer">
				<?php if ( (int) $data['review_count'] > 0 ) : ?>
					<?php echo vila_akoya_stay_stars_html( $data['review_avg'], $data['review_count'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
				<?php if ( $price ) : ?>
					<strong class="vila-stay-card__price"><?php echo esc_html( $price ); ?></strong>
				<?php endif; ?>
			</div>
			<a class="vila-stay-card__cta" href="<?php echo esc_url( $permalink ); ?>"><?php esc_html_e( 'View stay', 'vila-akoya' ); ?></a>
		</div>
	</article>
	<?php
	$html = (string) ob_get_clean();
	return (string) preg_replace( '/<br\s*\/?>/i', '', $html );
}

/**
 * Default Accommodation-tab intro on the booking page.
 *
 * @return string
 */
function vila_akoya_default_accommodation_browse_lede() {
	return __( 'Choose a stay.', 'vila-akoya' );
}

/**
 * Editable intro above accommodation cards on /booking/#accommodation.
 * Empty string hides the copy. Unset option uses the default.
 *
 * @return string
 */
function vila_akoya_get_accommodation_browse_lede() {
	$saved = get_option( 'vila_akoya_accommodation_browse_lede', false );
	if ( false === $saved ) {
		return vila_akoya_default_accommodation_browse_lede();
	}
	return is_string( $saved ) ? $saved : '';
}

/**
 * Village stays → Booking intro.
 *
 * @return void
 */
function vila_akoya_register_accommodation_copy_admin_page() {
	add_submenu_page(
		'edit.php?post_type=stay',
		__( 'Booking intro', 'vila-akoya' ),
		__( 'Booking intro', 'vila-akoya' ),
		'edit_pages',
		'vila-stay-booking-intro',
		'vila_akoya_render_accommodation_copy_admin_page'
	);
}
add_action( 'admin_menu', 'vila_akoya_register_accommodation_copy_admin_page' );

/**
 * Save Accommodation-tab intro copy.
 *
 * @return void
 */
function vila_akoya_handle_accommodation_copy_save() {
	if ( ! is_admin() || ! current_user_can( 'edit_pages' ) ) {
		return;
	}
	if ( empty( $_POST['vila_akoya_accommodation_copy_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return;
	}
	check_admin_referer( 'vila_akoya_accommodation_copy_save', 'vila_akoya_accommodation_copy_nonce' );

	$raw = isset( $_POST['vila_akoya_accommodation_browse_lede'] )
		? wp_unslash( (string) $_POST['vila_akoya_accommodation_browse_lede'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';
	update_option( 'vila_akoya_accommodation_browse_lede', sanitize_textarea_field( $raw ), false );

	wp_safe_redirect(
		add_query_arg(
			array(
				'post_type' => 'stay',
				'page'      => 'vila-stay-booking-intro',
				'updated'   => '1',
			),
			admin_url( 'edit.php' )
		)
	);
	exit;
}
add_action( 'admin_init', 'vila_akoya_handle_accommodation_copy_save' );

/**
 * @return void
 */
function vila_akoya_render_accommodation_copy_admin_page() {
	if ( ! current_user_can( 'edit_pages' ) ) {
		wp_die( esc_html__( 'You do not have permission to edit this copy.', 'vila-akoya' ) );
	}
	$lede    = vila_akoya_get_accommodation_browse_lede();
	$updated = ! empty( $_GET['updated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Booking intro', 'vila-akoya' ); ?></h1>
		<?php if ( $updated ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Copy saved.', 'vila-akoya' ); ?></p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'This text appears on the Booking page, Accommodation tab, above the list of places to stay. Leave it blank to hide it.', 'vila-akoya' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'vila_akoya_accommodation_copy_save', 'vila_akoya_accommodation_copy_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="vila_akoya_accommodation_browse_lede"><?php esc_html_e( 'Intro', 'vila-akoya' ); ?></label>
					</th>
					<td>
						<textarea name="vila_akoya_accommodation_browse_lede" id="vila_akoya_accommodation_browse_lede" class="large-text" rows="6"><?php echo esc_textarea( $lede ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Line breaks become new paragraphs on the site.', 'vila-akoya' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save copy', 'vila-akoya' ) ); ?>
		</form>
	</div>
	<?php
}

/**
 * @param string $lede Plain text intro.
 * @return string
 */
function vila_akoya_accommodation_lede_html( $lede ) {
	$lede = trim( (string) $lede );
	if ( '' === $lede ) {
		return '';
	}
	$parts = preg_split( '/\R+/', $lede );
	$html  = '';
	if ( ! is_array( $parts ) ) {
		return '<p>' . esc_html( $lede ) . '</p>';
	}
	foreach ( $parts as $part ) {
		$part = trim( (string) $part );
		if ( '' === $part ) {
			continue;
		}
		$html .= '<p>' . esc_html( $part ) . '</p>';
	}
	return $html;
}

/**
 * Browse grid shortcode (booking page village tab, or archive).
 *
 * @param array<string, string>|string $atts Attributes.
 * @return string
 */
function vila_akoya_shortcode_stays_browse( $atts = array() ) {
	$atts = shortcode_atts(
		array(
			'layout' => 'booking',
		),
		$atts,
		'vila_stays_browse'
	);
	$layout = 'archive' === $atts['layout'] ? 'archive' : 'booking';
	$stays  = vila_akoya_get_published_stays();
	$lede   = 'booking' === $layout ? trim( vila_akoya_get_accommodation_browse_lede() ) : '';

	ob_start();
	?>
	<div class="vila-stays-browse vila-stays-browse--<?php echo esc_attr( $layout ); ?>">
		<?php if ( $lede ) : ?>
			<div class="vila-stays-browse__lede"><?php echo vila_akoya_accommodation_lede_html( $lede ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<?php endif; ?>
		<?php if ( $stays ) : ?>
			<div class="vila-stays-browse__grid">
				<?php
				foreach ( $stays as $stay_post ) {
					echo vila_akoya_render_stay_card( (int) $stay_post->ID, $layout ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>
		<?php else : ?>
			<p class="vila-stays-browse__empty"><?php esc_html_e( 'Village stays will appear here when they are ready to book. In the meantime, send us a note and we will help you find a place.', 'vila-akoya' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'vila_stays_browse', 'vila_akoya_shortcode_stays_browse' );

/**
 * Existing booking pages (seeded before stays) still have an empty village tab.
 * Inject the browse grid when the shortcode is not already in the content.
 *
 * @param string $content Content.
 * @return string
 */
function vila_akoya_booking_inject_stays_browse( $content ) {
	if ( is_admin() || ! is_page( 'booking' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$content = str_replace( 'data-va-booking-tab="village"', 'data-va-booking-tab="accommodation"', $content );
	$content = str_replace( 'data-va-booking-panel="village"', 'data-va-booking-panel="accommodation"', $content );
	$content = preg_replace(
		'/(<button[^>]*data-va-booking-tab="accommodation"[^>]*>)\s*The Village\s*(<\/button>)/i',
		'$1Accommodation$2',
		$content,
		1
	);

	if ( false !== strpos( $content, 'vila-stays-browse' ) || false !== strpos( $content, '[vila_stays_browse' ) ) {
		return $content;
	}
	if ( false === strpos( $content, 'data-va-booking-panel="accommodation"' ) && false === strpos( $content, 'data-va-booking-panel="village"' ) ) {
		return $content;
	}
	$browse  = do_shortcode( '[vila_stays_browse layout="booking"]' );
	$updated = preg_replace(
		'/(data-va-booking-panel="(?:accommodation|village)"[^>]*>)/i',
		'$1' . $browse,
		$content,
		1
	);
	return is_string( $updated ) && '' !== $updated ? $updated : $content;
}
add_filter( 'the_content', 'vila_akoya_booking_inject_stays_browse', 12 );

/**
 * Archive shortcode.
 *
 * @return string
 */
function vila_akoya_shortcode_stays_archive() {
	$stays = vila_akoya_get_published_stays();
	$booking = function_exists( 'vila_akoya_booking_page_url' ) ? vila_akoya_booking_page_url() : home_url( '/booking/' );
	ob_start();
	?>
	<div class="vila-stays-archive">
		<header class="vila-stays-archive__intro">
			<p class="vila-stays-archive__eyebrow"><?php esc_html_e( 'The Village', 'vila-akoya' ); ?></p>
			<h1 class="vila-stays-archive__title"><?php esc_html_e( 'Places to stay', 'vila-akoya' ); ?></h1>
			<p class="vila-stays-archive__lede"><?php esc_html_e( 'Tiny houses, caravans and other unique homes you can rent for a night or a longer stretch. Each stay has its own calendar.', 'vila-akoya' ); ?></p>
			<p class="vila-stays-archive__alt"><a href="<?php echo esc_url( $booking ); ?>"><?php esc_html_e( 'Or book a campsite pitch →', 'vila-akoya' ); ?></a></p>
		</header>
		<?php if ( $stays ) : ?>
			<div class="vila-stays-browse__grid vila-stays-browse__grid--wide">
				<?php
				foreach ( $stays as $stay_post ) {
					echo vila_akoya_render_stay_card( (int) $stay_post->ID, 'archive' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>
		<?php else : ?>
			<p class="vila-stays-browse__empty"><?php esc_html_e( 'No accommodation is listed yet. Check back soon, or get in touch.', 'vila-akoya' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'vila_stays_archive', 'vila_akoya_shortcode_stays_archive' );

/**
 * Embedded WooCommerce booking form for a stay product.
 *
 * @param int $product_id Product ID.
 * @return string
 */
function vila_akoya_render_stay_woocommerce_booking_form( $product_id ) {
	$product_id = (int) $product_id;
	if ( $product_id < 1 || ! function_exists( 'wc_get_product' ) ) {
		return '<p class="vila-cb-msg vila-cb-msg--error">' . esc_html__( 'Booking is not available for this stay yet.', 'vila-akoya' ) . '</p>';
	}
	$product = wc_get_product( $product_id );
	if ( ! $product || ! $product->is_purchasable() ) {
		return '<p class="vila-cb-msg vila-cb-msg--error">' . esc_html__( 'This stay cannot be booked online right now.', 'vila-akoya' ) . '</p>';
	}
	$html = do_shortcode( sprintf( '[product_page id="%d"]', $product_id ) );
	$html = is_string( $html ) ? $html : '';
	return '<div class="vila-wc-booking vila-wc-booking--stay">' . vila_akoya_compact_markup( vila_akoya_repair_wpautop_html( $html ) ) . '</div>';
}

/**
 * Inquiry form (dates + contact details) until online stay checkout is re-enabled.
 *
 * @param int $stay_id Stay ID.
 * @return string
 */
function vila_akoya_render_stay_inquiry_form( $stay_id ) {
	$stay_id = (int) $stay_id;
	if ( ! function_exists( 'vila_akoya_get_cf7_form_ids' ) ) {
		return '<p class="font-body text-on-surface-variant">' . esc_html__( 'The request form is not available yet.', 'vila-akoya' ) . '</p>';
	}
	$ids = vila_akoya_get_cf7_form_ids();
	if ( empty( $ids['stay_inquiry'] ) ) {
		return '<p class="font-body text-on-surface-variant">' . esc_html__( 'The request form is not available yet.', 'vila-akoya' ) . '</p>';
	}

	$GLOBALS['vila_akoya_stay_inquiry_context'] = array(
		'title' => get_the_title( $stay_id ),
		'url'   => get_permalink( $stay_id ),
		'id'    => (string) $stay_id,
	);

	$html = do_shortcode(
		sprintf(
			'[contact-form-7 id="%s" html_class="vila-cf7 vila-cf7--stay"]',
			esc_attr( (string) $ids['stay_inquiry'] )
		)
	);

	unset( $GLOBALS['vila_akoya_stay_inquiry_context'] );

	return is_string( $html ) ? $html : '';
}

/**
 * Insert the stay guest picker into the inquiry form markup.
 *
 * @param string $html Form HTML.
 * @return string
 */
function vila_akoya_cf7_stay_inquiry_form_elements( $html ) {
	if ( empty( $GLOBALS['vila_akoya_stay_inquiry_context'] ) || ! is_array( $GLOBALS['vila_akoya_stay_inquiry_context'] ) ) {
		return $html;
	}
	if ( false === strpos( $html, 'vila-stay-guest-picker-slot' ) ) {
		return $html;
	}
	$stay_id = isset( $GLOBALS['vila_akoya_stay_inquiry_context']['id'] ) ? (int) $GLOBALS['vila_akoya_stay_inquiry_context']['id'] : 0;
	if ( $stay_id < 1 ) {
		return $html;
	}
	$template = VILA_AKOYA_DIR . '/inc/templates/stay-guest-picker.php';
	if ( ! is_readable( $template ) ) {
		return $html;
	}
	$inquiry        = true;
	$include_inputs = false;
	ob_start();
	include $template;
	$picker   = vila_akoya_compact_markup( (string) ob_get_clean() );
	$estimate = vila_akoya_compact_markup( vila_akoya_stay_inquiry_estimate_html( $stay_id ) );
	$html     = (string) preg_replace(
		'/<div class="vila-stay-guest-picker-slot">\s*<\/div>/',
		$picker,
		$html,
		1
	);
	if ( false !== strpos( $html, 'vila-stay-estimate-slot' ) ) {
		$replaced = preg_replace(
			'/<div class="vila-stay-estimate-slot">\s*<\/div>/',
			$estimate,
			$html,
			1
		);
		return is_string( $replaced ) ? $replaced : $html;
	}
	if ( $picker && false !== strpos( $html, $picker ) ) {
		return str_replace( $picker, $picker . $estimate, $html );
	}
	return $html . $estimate;
}
add_filter( 'wpcf7_form_elements', 'vila_akoya_cf7_stay_inquiry_form_elements' );

/**
 * Collapse whitespace between tags so Gutenberg wpautop cannot wrap the form.
 *
 * @param string $html HTML.
 * @return string
 */
function vila_akoya_compact_markup( $html ) {
	$html = preg_replace( '/\s+/', ' ', $html );
	$html = preg_replace( '/>\s+</', '><', $html );
	return is_string( $html ) ? trim( $html ) : '';
}

/**
 * Undo wpautop leftovers that break guest-picker / booking markup.
 *
 * @param string $html HTML.
 * @return string
 */
function vila_akoya_repair_wpautop_html( $html ) {
	if ( ! is_string( $html ) || '' === $html ) {
		return '';
	}
	$html = preg_replace( '/<p>\s*(<(?:div|section|aside|ul|ol|li|h[1-6]|form|button|fieldset|table))/i', '$1', $html );
	$html = preg_replace( '/(<\/(?:div|section|aside|ul|ol|li|h[1-6]|form|button|fieldset|table)>)\s*<\/p>/i', '$1', $html );
	$html = preg_replace( '/<(div|section|aside|ul|ol|li|form|button|fieldset)(\s[^>]*)?>\s*<\/p>/i', '<$1$2>', $html );
	$html = preg_replace( '/<p>\s*<\/(div|section|aside|ul|ol|li|form|button|fieldset)>/i', '</$1>', $html );
	$html = preg_replace( '/<\/p>\s*(<(?:div|section|aside|ul|ol|li|h[1-6]|form|button|fieldset))/i', '$1', $html );
	$html = preg_replace( '/<br\s*\/?>/i', '', $html );
	return is_string( $html ) ? $html : '';
}

/**
 * Live estimate + request disclaimer for the stay inquiry form.
 *
 * @param int $stay_id Stay ID.
 * @return string
 */
function vila_akoya_stay_inquiry_estimate_html( $stay_id ) {
	ob_start();
	?>
	<div class="vila-stay-estimate" data-vila-stay-estimate data-stay-id="<?php echo esc_attr( (string) (int) $stay_id ); ?>">
		<div class="vila-stay-discount-upsell-row" data-vila-stay-upsell hidden></div>
		<div class="vila-live-bill" data-vila-stay-bill aria-live="polite" hidden></div>
		<p class="vila-stay-estimate__rates" data-vila-stay-rates hidden><?php echo '&nbsp;'; ?></p>
		<p class="vila-stay-estimate__note"><?php esc_html_e( 'This is an estimate. Sending the form only sends a request — it does not book the stay.', 'vila-akoya' ); ?></p>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Inject stay context as CF7 hidden fields.
 *
 * @param array<string, string> $fields Existing hidden fields.
 * @return array<string, string>
 */
function vila_akoya_cf7_stay_inquiry_hidden_fields( $fields ) {
	if ( empty( $GLOBALS['vila_akoya_stay_inquiry_context'] ) || ! is_array( $GLOBALS['vila_akoya_stay_inquiry_context'] ) ) {
		return $fields;
	}
	$ctx                  = $GLOBALS['vila_akoya_stay_inquiry_context'];
	$fields['stay-title'] = isset( $ctx['title'] ) ? (string) $ctx['title'] : '';
	$fields['stay-url']   = isset( $ctx['url'] ) ? (string) $ctx['url'] : '';
	$fields['stay-id']    = isset( $ctx['id'] ) ? (string) $ctx['id'] : '';
	return $fields;
}
add_filter( 'wpcf7_form_hidden_fields', 'vila_akoya_cf7_stay_inquiry_hidden_fields' );

/**
 * Recalculate the inquiry estimate from dates so the email total matches the stay rate.
 *
 * @param array<string, mixed> $posted Posted fields.
 * @return array<string, mixed>
 */
function vila_akoya_cf7_posted_stay_total( $posted ) {
	if ( ! is_array( $posted ) ) {
		return $posted;
	}
	$stay_id = isset( $posted['stay-id'] ) ? absint( $posted['stay-id'] ) : 0;
	$from    = isset( $posted['stay-from'] ) ? sanitize_text_field( (string) $posted['stay-from'] ) : '';
	$to      = isset( $posted['stay-to'] ) ? sanitize_text_field( (string) $posted['stay-to'] ) : '';
	if ( $stay_id < 1 || '' === $from || '' === $to ) {
		return $posted;
	}
	$from_ts = strtotime( $from );
	$to_ts   = strtotime( $to );
	if ( ! $from_ts || ! $to_ts || $to_ts <= $from_ts ) {
		return $posted;
	}
	$nights  = (int) round( ( $to_ts - $from_ts ) / DAY_IN_SECONDS );
	$data    = function_exists( 'vila_akoya_get_stay_data' ) ? vila_akoya_get_stay_data( $stay_id ) : array();
	$nightly = isset( $data['nightly'] ) ? (float) $data['nightly'] : 0.0;
	if ( $nights < 1 || $nightly <= 0 ) {
		$posted['stay-total'] = '';
		return $posted;
	}
	$billable = $nights;
	if ( function_exists( 'vila_akoya_stay_discount_info' ) ) {
		$info     = vila_akoya_stay_discount_info( $nights );
		$billable = isset( $info['billable'] ) ? max( 0, (int) $info['billable'] ) : $nights;
	}
	$total                 = $nightly * $billable;
	$posted['stay-total'] = function_exists( 'vila_akoya_format_price_plain' )
		? vila_akoya_format_price_plain( $total )
		: ( '€' . (string) (int) round( $total ) );
	return $posted;
}
add_filter( 'wpcf7_posted_data', 'vila_akoya_cf7_posted_stay_total' );

/**
 * Check-out must be after arrival.
 *
 * @param WPCF7_Validation $result Validation result.
 * @param WPCF7_FormTag    $tag    Field.
 * @return WPCF7_Validation
 */
function vila_akoya_cf7_validate_stay_dates( $result, $tag ) {
	if ( ! is_object( $tag ) || 'stay-to' !== $tag->name ) {
		return $result;
	}
	$from = isset( $_POST['stay-from'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['stay-from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$to   = isset( $_POST['stay-to'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['stay-to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( '' === $from || '' === $to ) {
		return $result;
	}
	$from_ts = strtotime( $from );
	$to_ts   = strtotime( $to );
	if ( $from_ts && $to_ts && $to_ts <= $from_ts ) {
		$result->invalidate( $tag, __( 'The leaving date must be after the arrival date.', 'vila-akoya' ) );
	}
	return $result;
}
add_filter( 'wpcf7_validate_date', 'vila_akoya_cf7_validate_stay_dates', 20, 2 );
add_filter( 'wpcf7_validate_date*', 'vila_akoya_cf7_validate_stay_dates', 20, 2 );

/**
 * Stay inquiry: adults + children cannot exceed sleeps; babies and pets do not take a bed.
 *
 * @param WPCF7_Validation $result Validation result.
 * @param WPCF7_FormTag    $tag    Field.
 * @return WPCF7_Validation
 */
function vila_akoya_cf7_validate_stay_guests( $result, $tag ) {
	if ( ! is_object( $tag ) || 'stay-adults' !== $tag->name ) {
		return $result;
	}
	$stay_id = isset( $_POST['stay-id'] ) ? absint( wp_unslash( (string) $_POST['stay-id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( $stay_id < 1 || ! function_exists( 'vila_akoya_get_stay_data' ) ) {
		return $result;
	}
	$data     = vila_akoya_get_stay_data( $stay_id );
	$sleeps   = isset( $data['sleeps'] ) ? max( 1, (int) $data['sleeps'] ) : 2;
	$pets_ok  = ! empty( $data['pets'] );
	$adults   = isset( $_POST['stay-adults'] ) ? absint( wp_unslash( (string) $_POST['stay-adults'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$children = isset( $_POST['stay-children'] ) ? absint( wp_unslash( (string) $_POST['stay-children'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$infants  = isset( $_POST['stay-infants'] ) ? absint( wp_unslash( (string) $_POST['stay-infants'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$pets     = isset( $_POST['stay-pets'] ) ? absint( wp_unslash( (string) $_POST['stay-pets'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

	if ( $adults < 1 ) {
		$result->invalidate( $tag, __( 'Please choose at least one adult.', 'vila-akoya' ) );
		return $result;
	}
	if ( ( $adults + $children ) > $sleeps ) {
		$result->invalidate(
			$tag,
			sprintf(
				/* translators: %d: sleeps */
				__( 'This stay sleeps %d (adults and children). Babies and pets do not take a bed.', 'vila-akoya' ),
				$sleeps
			)
		);
		return $result;
	}
	if ( $infants > 5 ) {
		$result->invalidate( $tag, __( 'Please enter a valid number of babies.', 'vila-akoya' ) );
		return $result;
	}
	$max_pets = $pets_ok ? 2 : 0;
	if ( $pets > $max_pets ) {
		$result->invalidate( $tag, $pets_ok ? __( 'Please enter a valid number of pets.', 'vila-akoya' ) : __( 'Pets are not allowed in this stay.', 'vila-akoya' ) );
	}
	return $result;
}
add_filter( 'wpcf7_validate_hidden', 'vila_akoya_cf7_validate_stay_guests', 20, 2 );

/**
 * Stay sidebar form: online checkout when enabled, otherwise a contact form.
 *
 * @param int $stay_id    Stay ID.
 * @param int $product_id Linked product ID.
 * @return string
 */
function vila_akoya_render_stay_booking_form( $stay_id, $product_id = 0 ) {
	if ( VILA_AKOYA_STAY_ONLINE_BOOKING ) {
		return vila_akoya_render_stay_woocommerce_booking_form( (int) $product_id );
	}
	return vila_akoya_render_stay_inquiry_form( (int) $stay_id );
}

/**
 * Single stay layout.
 *
 * @return string
 */
function vila_akoya_shortcode_stay_single() {
	if ( ! is_singular( 'stay' ) ) {
		return '';
	}
	$stay_id = (int) get_the_ID();
	$data    = vila_akoya_get_stay_data( $stay_id );
	$images  = vila_akoya_stay_gallery_ids( $stay_id );
	$price   = vila_akoya_stay_price_label( $data['nightly'] );
	$amenity_labels = vila_akoya_stay_amenity_labels();
	$archive = get_post_type_archive_link( 'stay' );
	$booking = function_exists( 'vila_akoya_booking_page_url' ) ? vila_akoya_booking_page_url() . '#accommodation' : home_url( '/booking/#accommodation' );

	ob_start();
	?>
	<div class="vila-stay-single">
		<p class="vila-stay-single__crumb">
			<a href="<?php echo esc_url( is_string( $archive ) ? $archive : $booking ); ?>">← <?php esc_html_e( 'All accommodation', 'vila-akoya' ); ?></a>
		</p>

		<?php if ( $images ) : ?>
			<?php
			$visible = array_slice( $images, 0, 5 );
			$extra   = max( 0, count( $images ) - count( $visible ) );
			?>
			<div class="vila-stay-gallery" data-count="<?php echo esc_attr( (string) count( $visible ) ); ?>" data-total="<?php echo esc_attr( (string) count( $images ) ); ?>">
				<?php foreach ( $visible as $i => $att_id ) : ?>
					<?php
					$full  = wp_get_attachment_image_url( (int) $att_id, 'full' );
					$alt   = (string) get_post_meta( (int) $att_id, '_wp_attachment_image_alt', true );
					$class = 0 === $i ? 'vila-stay-gallery__item vila-stay-gallery__item--hero' : 'vila-stay-gallery__item';
					?>
					<a class="<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $full ? $full : '#' ); ?>" data-gallery-src="<?php echo esc_url( $full ? $full : '' ); ?>" data-gallery-alt="<?php echo esc_attr( $alt ); ?>">
						<?php echo wp_get_attachment_image( (int) $att_id, 0 === $i ? 'large' : 'medium_large', false, array( 'alt' => $alt ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</a>
				<?php endforeach; ?>
				<?php foreach ( array_slice( $images, 5 ) as $att_id ) : ?>
					<?php
					$full = wp_get_attachment_image_url( (int) $att_id, 'full' );
					$alt  = (string) get_post_meta( (int) $att_id, '_wp_attachment_image_alt', true );
					?>
					<a class="vila-stay-gallery__item vila-stay-gallery__item--hidden" href="<?php echo esc_url( $full ? $full : '#' ); ?>" data-gallery-src="<?php echo esc_url( $full ? $full : '' ); ?>" data-gallery-alt="<?php echo esc_attr( $alt ); ?>" tabindex="-1" aria-hidden="true"></a>
				<?php endforeach; ?>
				<?php if ( count( $images ) > 1 ) : ?>
					<button type="button" class="vila-stay-gallery__all" data-vila-stay-gallery-all>
						<?php
						echo esc_html(
							$extra > 0
								? sprintf(
									/* translators: %d: extra photo count */
									__( 'Show all photos (%d)', 'vila-akoya' ),
									count( $images )
								)
								: __( 'Show all photos', 'vila-akoya' )
						);
						?>
					</button>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="vila-stay-layout">
			<div class="vila-stay-main">
				<p class="vila-stay-single__kicker">
					<span class="vila-stay-card__badge"><?php echo esc_html( $data['type_label'] ); ?></span>
					<span><?php echo esc_html( sprintf( /* translators: %d: guests */ _n( 'Sleeps %d', 'Sleeps %d', $data['sleeps'], 'vila-akoya' ), $data['sleeps'] ) ); ?></span>
					<?php if ( $price ) : ?>
						<span><?php echo esc_html( $price ); ?></span>
					<?php endif; ?>
				</p>
				<h1 class="vila-stay-single__title"><?php the_title(); ?></h1>
				<?php if ( $data['review_count'] > 0 ) : ?>
				<div class="vila-stay-single__rating">
					<?php echo vila_akoya_stay_stars_html( $data['review_avg'], $data['review_count'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<a class="vila-stay-single__reviews-jump" href="#stay-reviews"><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d review', '%d reviews', $data['review_count'], 'vila-akoya' ), $data['review_count'] ) ); ?></a>
				</div>
				<?php endif; ?>

				<?php if ( $data['amenities'] ) : ?>
					<ul class="vila-stay-amenities">
						<?php foreach ( $data['amenities'] as $slug ) : ?>
							<?php if ( isset( $amenity_labels[ $slug ] ) ) : ?>
								<li><?php echo esc_html( $amenity_labels[ $slug ] ); ?></li>
							<?php endif; ?>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<div class="vila-stay-single__body">
					<?php the_content(); ?>
				</div>

				<?php if ( $data['review_count'] > 0 ) : ?>
				<section class="vila-stay-reviews" id="stay-reviews">
					<h2 class="vila-stay-reviews__title"><?php esc_html_e( 'Reviews', 'vila-akoya' ); ?></h2>
					<?php vila_akoya_render_stay_reviews_list( $stay_id ); ?>
				</section>
				<?php endif; ?>
			</div>
			<aside class="vila-stay-book" id="book-this-stay">
				<div class="vila-stay-book__card">
					<p class="vila-stay-book__price">
						<?php if ( $price ) : ?>
							<strong><?php echo esc_html( $price ); ?></strong>
							<?php
							$month_label = vila_akoya_stay_month_price_label( isset( $data['nightly'] ) ? (float) $data['nightly'] : 0 );
							if ( $month_label ) :
								?>
							<span class="vila-stay-book__price-month"><?php echo esc_html( $month_label ); ?></span>
							<?php endif; ?>
						<?php else : ?>
							<strong><?php esc_html_e( 'Request dates', 'vila-akoya' ); ?></strong>
						<?php endif; ?>
					</p>
					<?php echo vila_akoya_render_stay_booking_form( $stay_id, (int) $data['product_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</aside>
		</div>
	</div>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'vila_stay_single', 'vila_akoya_shortcode_stay_single' );

/**
 * Gutenberg shortcode blocks run wpautop on the output, which inserts <br>
 * throughout the stay booking form and gallery.
 *
 * @param string $content Block HTML.
 * @param array  $block   Block.
 * @return string
 */
function vila_akoya_strip_stay_shortcode_autop( $content, $block = array() ) {
	$name = is_array( $block ) && isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
	if ( 'core/shortcode' !== $name || ! is_string( $content ) ) {
		return $content;
	}
	if ( false === strpos( $content, 'vila-stay-single' ) && false === strpos( $content, 'vila-stay-book' ) && false === strpos( $content, 'vila-stay-card' ) && false === strpos( $content, 'vila-stays-browse' ) ) {
		return $content;
	}
	return vila_akoya_repair_wpautop_html( $content );
}
add_filter( 'render_block', 'vila_akoya_strip_stay_shortcode_autop', 20, 2 );

/**
 * Reviews list (no form — guests review via the dedicated review page from confirmation email).
 *
 * @param int $stay_id Stay ID.
 */
function vila_akoya_render_stay_reviews_list( $stay_id ) {
	$stay_id = (int) $stay_id;
	$comments = get_comments(
		array(
			'post_id' => $stay_id,
			'status'  => 'approve',
			'type'    => 'comment',
			'order'   => 'DESC',
		)
	);
	if ( ! $comments ) {
		return;
	}

	echo '<ol class="vila-stay-reviews__list">';
	foreach ( $comments as $comment ) {
		$rating = (int) get_comment_meta( (int) $comment->comment_ID, '_vila_stay_rating', true );
		echo '<li class="vila-stay-review">';
		echo '<div class="vila-stay-review__head">';
		echo '<strong class="vila-stay-review__author">' . esc_html( $comment->comment_author ) . '</strong>';
		if ( $rating >= 1 ) {
			echo vila_akoya_stay_stars_html( (float) $rating, 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '<time class="vila-stay-review__date" datetime="' . esc_attr( get_comment_date( 'c', $comment ) ) . '">' . esc_html( get_comment_date( '', $comment ) ) . '</time>';
		echo '</div>';
		echo '<div class="vila-stay-review__body">' . wp_kses_post( wpautop( $comment->comment_content ) ) . '</div>';
		echo '</li>';
	}
	echo '</ol>';
}

/**
 * @deprecated Use vila_akoya_render_stay_reviews_list().
 *
 * @param int $stay_id Stay ID.
 */
function vila_akoya_render_stay_reviews( $stay_id ) {
	vila_akoya_render_stay_reviews_list( $stay_id );
}

/**
 * Public URL for leaving a review for a stay.
 *
 * @param int $stay_id Stay post ID (optional).
 * @return string
 */
function vila_akoya_stay_review_page_url( $stay_id = 0 ) {
	$page = get_page_by_path( 'review-accommodation', OBJECT, 'page' );
	$base = ( $page instanceof WP_Post ) ? get_permalink( $page ) : home_url( '/review-accommodation/' );
	$base = is_string( $base ) ? $base : home_url( '/review-accommodation/' );

	$stay_id = (int) $stay_id;
	if ( $stay_id > 0 ) {
		$slug = get_post_field( 'post_name', $stay_id );
		if ( is_string( $slug ) && '' !== $slug ) {
			$pretty = home_url( user_trailingslashit( 'review-accommodation/' . $slug ) );
			return $pretty;
		}
	}

	return $base;
}

/**
 * Resolve a stay post ID from the review page URL.
 *
 * @return int
 */
function vila_akoya_stay_id_from_request() {
	$slug = get_query_var( 'va_review_stay' );
	if ( ! is_string( $slug ) || '' === $slug ) {
		$slug = isset( $_GET['accommodation'] ) ? sanitize_title( wp_unslash( (string) $_GET['accommodation'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
	if ( '' === $slug ) {
		return 0;
	}
	$post = get_page_by_path( $slug, OBJECT, 'stay' );
	return ( $post instanceof WP_Post ) ? (int) $post->ID : 0;
}

/**
 * Stay IDs booked on an order.
 *
 * @param WC_Order $order Order.
 * @return int[]
 */
function vila_akoya_order_stay_ids( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return array();
	}
	$ids = array();
	foreach ( $order->get_items() as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		$pid = (int) $item->get_product_id();
		if ( $pid > 0 && vila_akoya_is_stay_product_id( $pid ) ) {
			$stay_id = vila_akoya_stay_id_from_product_id( $pid );
			if ( $stay_id > 0 ) {
				$ids[] = $stay_id;
			}
		}
	}
	return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
}

/**
 * Review form for the dedicated review page.
 *
 * @param int $stay_id Stay ID.
 */
function vila_akoya_render_stay_review_form( $stay_id ) {
	$stay_id = (int) $stay_id;
	if ( $stay_id < 1 || ! comments_open( $stay_id ) ) {
		return;
	}

	comment_form(
		array(
			'title_reply'          => __( 'Write a review', 'vila-akoya' ),
			'title_reply_before'   => '<h2 class="vila-stay-reviews__form-title">',
			'title_reply_after'    => '</h2>',
			'label_submit'         => __( 'Submit review', 'vila-akoya' ),
			'class_form'           => 'vila-stay-review-form',
			'class_submit'         => 'vila-stay-review-form__submit',
			'comment_notes_before' => '<p class="vila-stay-review-form__note">' . esc_html__( 'Reviews are published after a quick check. Please only review a stay you have actually been in.', 'vila-akoya' ) . '</p>',
			'comment_field'        => vila_akoya_stay_review_rating_field() . '<p class="comment-form-comment"><label for="comment">' . esc_html__( 'Your review', 'vila-akoya' ) . '</label><textarea id="comment" name="comment" cols="45" rows="6" required></textarea></p>',
		),
		$stay_id
	);
}

/**
 * [vila_stay_review] — review form for a stay selected via ?accommodation={slug}.
 *
 * @return string
 */
function vila_akoya_shortcode_stay_review() {
	$stay_id = vila_akoya_stay_id_from_request();
	$stays   = vila_akoya_get_published_stays();

	ob_start();
	?>
	<div class="vila-stay-review-page vila-page-inner pt-10 md:pt-28 pb-20 max-w-2xl mx-auto px-6">
		<p class="vila-stay-single__crumb">
			<a href="<?php echo esc_url( vila_akoya_stay_review_page_url() ); ?>">← <?php esc_html_e( 'Choose accommodation', 'vila-akoya' ); ?></a>
		</p>
		<h1 class="vila-stay-single__title mb-4"><?php esc_html_e( 'Leave a review', 'vila-akoya' ); ?></h1>

		<?php if ( $stay_id < 1 ) : ?>
			<p class="font-body text-on-surface-variant mb-8"><?php esc_html_e( 'Select the accommodation you stayed in, then share your experience.', 'vila-akoya' ); ?></p>
			<?php if ( $stays ) : ?>
				<ul class="vila-stay-review-page__pick list-none pl-0 space-y-3">
					<?php foreach ( $stays as $stay_post ) : ?>
						<li>
							<a class="vila-stay-card__cta inline-flex" href="<?php echo esc_url( vila_akoya_stay_review_page_url( (int) $stay_post->ID ) ); ?>">
								<?php echo esc_html( get_the_title( $stay_post ) ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="vila-stays-browse__empty"><?php esc_html_e( 'No accommodation is available to review right now.', 'vila-akoya' ); ?></p>
			<?php endif; ?>
		<?php else : ?>
			<?php
			$data = vila_akoya_get_stay_data( $stay_id );
			$thumb = get_post_thumbnail_id( $stay_id );
			?>
			<div class="vila-stay-review-page__stay mb-8 flex items-center gap-4">
				<?php if ( $thumb ) : ?>
					<div class="vila-stay-review-page__thumb shrink-0 overflow-hidden rounded-2xl w-20 h-20">
						<?php echo wp_get_attachment_image( (int) $thumb, 'thumbnail', false, array( 'class' => 'w-full h-full object-cover' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php endif; ?>
				<div>
					<p class="vila-stay-card__badge mb-1"><?php echo esc_html( $data['type_label'] ); ?></p>
					<h2 class="font-headline text-xl text-on-surface mb-0"><?php echo esc_html( get_the_title( $stay_id ) ); ?></h2>
				</div>
			</div>
			<?php vila_akoya_render_stay_review_form( $stay_id ); ?>
		<?php endif; ?>
	</div>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'vila_stay_review', 'vila_akoya_shortcode_stay_review' );

/**
 * Ensure the public review page exists.
 */
function vila_akoya_maybe_ensure_stay_review_page() {
	if ( '1' === get_option( 'vila_akoya_stay_review_page_ready' ) ) {
		return;
	}

	$existing = get_page_by_path( 'review-accommodation', OBJECT, 'page' );
	if ( $existing instanceof WP_Post ) {
		update_option( 'vila_akoya_stay_review_page_ready', '1' );
		return;
	}

	$page_id = wp_insert_post(
		array(
			'post_title'   => __( 'Review accommodation', 'vila-akoya' ),
			'post_name'    => 'review-accommodation',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '[vila_stay_review]',
		),
		true
	);
	if ( ! is_wp_error( $page_id ) && $page_id ) {
		update_option( 'vila_akoya_stay_review_page_ready', '1' );
	}
}
add_action( 'wp_loaded', 'vila_akoya_maybe_ensure_stay_review_page', 36 );

/**
 * @return string
 */
function vila_akoya_stay_review_rating_field() {
	ob_start();
	?>
	<fieldset class="vila-stay-review-form__stars">
		<legend><?php esc_html_e( 'Your rating', 'vila-akoya' ); ?></legend>
		<div class="vila-stay-rating-input" data-vila-stay-rating>
			<?php for ( $i = 5; $i >= 1; $i-- ) : ?>
				<input type="radio" name="vila_stay_rating" id="vila-stay-rating-<?php echo esc_attr( (string) $i ); ?>" value="<?php echo esc_attr( (string) $i ); ?>" required />
				<label for="vila-stay-rating-<?php echo esc_attr( (string) $i ); ?>" title="<?php echo esc_attr( sprintf( /* translators: %d: stars */ _n( '%d star', '%d stars', $i, 'vila-akoya' ), $i ) ); ?>">★</label>
			<?php endfor; ?>
		</div>
	</fieldset>
	<?php
	return (string) ob_get_clean();
}

/**
 * @param string[] $classes Body classes.
 * @return string[]
 */
function vila_akoya_stay_body_classes( $classes ) {
	if ( is_singular( 'stay' ) ) {
		$classes[] = 'vila-page-stay';
		$classes[] = 'vila-wc-commerce';
	}
	if ( is_post_type_archive( 'stay' ) ) {
		$classes[] = 'vila-page-stays';
	}
	return $classes;
}
add_filter( 'body_class', 'vila_akoya_stay_body_classes' );

/**
 * WooCommerce / MWB scripts are registered for product pages; stay singles embed the same form.
 */
function vila_akoya_enqueue_stay_commerce_assets() {
	if ( ! is_singular( 'stay' ) ) {
		return;
	}
	if ( ! VILA_AKOYA_STAY_ONLINE_BOOKING ) {
		wp_enqueue_script(
			'vila-akoya-guest-picker',
			VILA_AKOYA_URI . '/assets/js/guest-picker.js',
			array(),
			function_exists( 'vila_akoya_asset_version' ) ? vila_akoya_asset_version( '/assets/js/guest-picker.js' ) : VILA_AKOYA_VERSION,
			true
		);
		$stay_data = function_exists( 'vila_akoya_get_stay_data' ) ? vila_akoya_get_stay_data( (int) get_queried_object_id() ) : array();
		wp_localize_script(
			'vila-akoya-guest-picker',
			'vilaStayInquiry',
			array(
				'nightly'   => isset( $stay_data['nightly'] ) ? (float) $stay_data['nightly'] : 0,
				'minNights' => isset( $stay_data['min_nights'] ) ? max( 1, (int) $stay_data['min_nights'] ) : 1,
				'strings'   => array(
					'night'           => __( 'night', 'vila-akoya' ),
					'nights'          => __( 'nights', 'vila-akoya' ),
					'total'           => __( 'Total', 'vila-akoya' ),
					'minStay'         => __( 'Minimum stay is %1$d nights.', 'vila-akoya' ),
					'stayRateFlat'    => __( 'Stay — %1$s/night × %2$d %3$s', 'vila-akoya' ),
					'discountApplied' => __( 'Stay %1$d, pay %2$d — %3$d %4$s free', 'vila-akoya' ),
					'upsell'          => __( 'Only %1$d more %2$s and you unlock stay %3$d, pay %4$d (%5$d extra %6$s free).', 'vila-akoya' ),
					'perNight'        => __( '%1$s / night', 'vila-akoya' ),
					'perMonth'        => __( '%1$s / month', 'vila-akoya' ),
				),
			)
		);
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	wp_enqueue_script( 'wc-add-to-cart' );
	if ( wp_script_is( 'flatpicker_js', 'registered' ) ) {
		wp_enqueue_script( 'flatpicker_js' );
	}
	if ( wp_script_is( 'bookings-for-woocommercepublic', 'registered' ) ) {
		wp_enqueue_script( 'bookings-for-woocommercepublic' );
	}
	if ( wp_style_is( 'bookings-for-woocommercepublic', 'registered' ) ) {
		wp_enqueue_style( 'bookings-for-woocommercepublic' );
	}
}
add_action( 'wp_enqueue_scripts', 'vila_akoya_enqueue_stay_commerce_assets', 24 );

/**
 * New stays accept reviews unless an editor closes comments.
 *
 * @param array<string, mixed> $data    Post data.
 * @param array<string, mixed> $postarr Raw post array.
 * @return array<string, mixed>
 */
function vila_akoya_stay_default_comment_status( $data, $postarr ) {
	if ( empty( $data['post_type'] ) || 'stay' !== $data['post_type'] ) {
		return $data;
	}
	if ( empty( $postarr['ID'] ) && empty( $data['comment_status'] ) ) {
		$data['comment_status'] = 'open';
	}
	return $data;
}
add_filter( 'wp_insert_post_data', 'vila_akoya_stay_default_comment_status', 10, 2 );

/**
 * Seed a handful of example stays (idempotent).
 */
function vila_akoya_maybe_seed_stays() {
	if ( '1' === get_option( 'vila_akoya_stays_seeded' ) ) {
		return;
	}
	if ( ! function_exists( 'wps_booking_get_meta_data' ) ) {
		return;
	}
	$existing = vila_akoya_get_published_stays();
	if ( ! empty( $existing ) ) {
		update_option( 'vila_akoya_stays_seeded', '1' );
		return;
	}

	$samples = array(
		array(
			'title'    => __( 'The Cork Cabin', 'vila-akoya' ),
			'excerpt'  => __( 'A warm wooden tiny house under the cork oaks, with a small kitchen and a terrace for slow mornings.', 'vila-akoya' ),
			'content'  => '<p>' . esc_html__( 'This little house is built for two. Wake up to birds in the cork forest, cook a simple breakfast, and step out onto the terrace. The cabin is finished with natural materials and has everything you need for a short stay or a longer pause.', 'vila-akoya' ) . '</p>',
			'type'     => 'tiny-house',
			'sleeps'   => 2,
			'nightly'  => 85,
			'pets'     => 'no',
			'amenities'=> array( 'kitchen', 'bathroom', 'heating', 'electricity', 'terrace', 'linens', 'towels' ),
			'image'    => 'https://vila-akoya.com/images/village-5year.jpg',
			'gallery'  => array(
				'https://vila-akoya.com/images/village-large.jpg',
				'https://vila-akoya.com/images/village-cork.jpg',
				'https://vila-akoya.com/images/village-circle.jpg',
				'https://vila-akoya.com/images/village-walking.jpg',
			),
		),
		array(
			'title'    => __( 'Luna the Caravan', 'vila-akoya' ),
			'excerpt'  => __( 'A decorated vintage caravan with a clever kitchenette and a shady outdoor sitting spot.', 'vila-akoya' ),
			'content'  => '<p>' . esc_html__( 'Luna is a lovingly decorated caravan for two. Compact, colourful, and close to village life — perfect if you want the romance of a home-on-wheels without bringing your own.', 'vila-akoya' ) . '</p>',
			'type'     => 'caravan',
			'sleeps'   => 2,
			'nightly'  => 65,
			'pets'     => 'yes',
			'amenities'=> array( 'kitchen', 'outdoor', 'electricity', 'terrace', 'linens', 'pets' ),
			'image'    => 'https://vila-akoya.com/images/village-rental.jpg',
			'gallery'  => array(
				'https://vila-akoya.com/images/village-plot.jpg',
				'https://vila-akoya.com/images/village-river.jpg',
				'https://vila-akoya.com/images/village-cork.jpg',
				'https://vila-akoya.com/images/village-small.jpg',
			),
		),
		array(
			'title'    => __( 'The Double Decker', 'vila-akoya' ),
			'excerpt'  => __( 'A converted double-decker with a living space below and a snug sleeping loft above — a feast for the eye.', 'vila-akoya' ),
			'content'  => '<p>' . esc_html__( 'One of the more out-of-the-ordinary homes in the village. The lower deck is for cooking, sitting and gathering; upstairs sleeps four. Unique, playful, and very Vila Akoya.', 'vila-akoya' ) . '</p>',
			'type'     => 'unique',
			'sleeps'   => 4,
			'nightly'  => 110,
			'pets'     => 'no',
			'amenities'=> array( 'kitchen', 'bathroom', 'heating', 'electricity', 'linens', 'towels' ),
			'image'    => 'https://vila-akoya.com/images/village-large.jpg',
			'gallery'  => array(
				'https://vila-akoya.com/images/village-5year.jpg',
				'https://vila-akoya.com/images/village-rental.jpg',
				'https://vila-akoya.com/images/village-circle.jpg',
				'https://vila-akoya.com/images/village-river.jpg',
			),
		),
	);

	foreach ( $samples as $i => $sample ) {
		$post_id = wp_insert_post(
			array(
				'post_type'      => 'stay',
				'post_status'    => 'publish',
				'post_title'     => $sample['title'],
				'post_excerpt'   => $sample['excerpt'],
				'post_content'   => $sample['content'],
				'comment_status' => 'open',
				'menu_order'     => $i + 1,
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			continue;
		}
		update_post_meta( $post_id, '_vila_stay_type', $sample['type'] );
		update_post_meta( $post_id, '_vila_stay_sleeps', $sample['sleeps'] );
		update_post_meta( $post_id, '_vila_stay_nightly', $sample['nightly'] );
		update_post_meta( $post_id, '_vila_stay_min_nights', 1 );
		update_post_meta( $post_id, '_vila_stay_pets', $sample['pets'] );
		update_post_meta( $post_id, '_vila_stay_amenities', $sample['amenities'] );
		if ( ! empty( $sample['image'] ) && function_exists( 'vila_akoya_seed_image_attachment_id' ) ) {
			$att = vila_akoya_seed_image_attachment_id( $sample['image'] );
			if ( $att > 0 ) {
				set_post_thumbnail( $post_id, $att );
			}
		}
		if ( ! empty( $sample['gallery'] ) ) {
			vila_akoya_attach_stay_gallery_from_urls( (int) $post_id, $sample['gallery'] );
		}
		vila_akoya_sync_stay_booking_product( (int) $post_id );
	}

	update_option( 'vila_akoya_stays_seeded', '1' );
}
add_action( 'wp_loaded', 'vila_akoya_maybe_seed_stays', 30 );

/**
 * Attach extra gallery photos from brochure image URLs.
 *
 * @param int      $stay_id Stay ID.
 * @param string[] $urls    Image URLs.
 */
function vila_akoya_attach_stay_gallery_from_urls( $stay_id, array $urls ) {
	$stay_id = (int) $stay_id;
	if ( $stay_id < 1 || ! function_exists( 'vila_akoya_seed_image_attachment_id' ) ) {
		return;
	}
	$feat = (int) get_post_thumbnail_id( $stay_id );
	$ids  = array();
	foreach ( $urls as $url ) {
		$url = is_string( $url ) ? $url : '';
		if ( '' === $url ) {
			continue;
		}
		$att = vila_akoya_seed_image_attachment_id( $url );
		if ( $att > 0 && $att !== $feat && ! in_array( $att, $ids, true ) ) {
			$ids[] = $att;
		}
	}
	if ( $ids ) {
		update_post_meta( $stay_id, '_vila_stay_gallery', $ids );
	}
}

/**
 * One-time: give seeded stays extra photos so the Airbnb-style grid can be previewed.
 */
function vila_akoya_maybe_seed_stay_galleries() {
	if ( '1' === get_option( 'vila_akoya_stay_galleries_seeded' ) ) {
		return;
	}

	$map = array(
		'the-cork-cabin' => array(
			'https://vila-akoya.com/images/village-large.jpg',
			'https://vila-akoya.com/images/village-cork.jpg',
			'https://vila-akoya.com/images/village-circle.jpg',
			'https://vila-akoya.com/images/village-walking.jpg',
		),
		'luna-the-caravan' => array(
			'https://vila-akoya.com/images/village-plot.jpg',
			'https://vila-akoya.com/images/village-river.jpg',
			'https://vila-akoya.com/images/village-cork.jpg',
			'https://vila-akoya.com/images/village-small.jpg',
		),
		'the-double-decker' => array(
			'https://vila-akoya.com/images/village-5year.jpg',
			'https://vila-akoya.com/images/village-rental.jpg',
			'https://vila-akoya.com/images/village-circle.jpg',
			'https://vila-akoya.com/images/village-river.jpg',
		),
	);

	foreach ( $map as $slug => $urls ) {
		$post = get_page_by_path( $slug, OBJECT, 'stay' );
		if ( $post instanceof WP_Post ) {
			vila_akoya_attach_stay_gallery_from_urls( (int) $post->ID, $urls );
		}
	}

	update_option( 'vila_akoya_stay_galleries_seeded', '1' );
}
add_action( 'wp_loaded', 'vila_akoya_maybe_seed_stay_galleries', 32 );

/**
 * Refresh booking page content when the theme HTML seed changes.
 */
function vila_akoya_maybe_patch_booking_page_stays() {
	$version = '3';
	if ( $version === get_option( 'vila_akoya_booking_content_version' ) ) {
		return;
	}

	$page = get_page_by_path( 'booking', OBJECT, 'page' );
	if ( ! $page instanceof WP_Post ) {
		return;
	}

	if (
		function_exists( 'vila_akoya_page_has_section_blocks' )
		&& vila_akoya_page_has_section_blocks( (int) $page->ID )
	) {
		update_option( 'vila_akoya_booking_content_version', $version );
		return;
	}

	if ( ! function_exists( 'vila_akoya_build_booking_page_html' ) ) {
		return;
	}

	$html = vila_akoya_build_booking_page_html();
	if ( '' === trim( $html ) ) {
		return;
	}

	wp_update_post(
		array(
			'ID'           => (int) $page->ID,
			'post_content' => $html,
		)
	);
	update_option( 'vila_akoya_booking_content_version', $version );
}
add_action( 'wp_loaded', 'vila_akoya_maybe_patch_booking_page_stays', 35 );

/**
 * Replace {{stays:browse}} in seeded booking HTML.
 *
 * @param string $html HTML.
 * @return string
 */
function vila_akoya_seed_replace_stays_placeholders( $html ) {
	$html = is_string( $html ) ? $html : '';
	return str_replace( '{{stays:browse}}', '[vila_stays_browse layout="booking"]', $html );
}

/**
 * Admin list columns.
 *
 * @param array<string, string> $columns Columns.
 * @return array<string, string>
 */
function vila_akoya_stay_admin_columns( $columns ) {
	$new = array();
	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;
		if ( 'title' === $key ) {
			$new['vila_stay_type']    = __( 'Type', 'vila-akoya' );
			$new['vila_stay_nightly'] = __( 'Per night', 'vila-akoya' );
			$new['vila_stay_sleeps']  = __( 'Sleeps', 'vila-akoya' );
		}
	}
	return $new;
}
add_filter( 'manage_stay_posts_columns', 'vila_akoya_stay_admin_columns' );

/**
 * @param string $column  Column.
 * @param int    $post_id Post ID.
 */
function vila_akoya_stay_admin_column_content( $column, $post_id ) {
	$data = vila_akoya_get_stay_data( (int) $post_id );
	if ( 'vila_stay_type' === $column ) {
		echo esc_html( $data['type_label'] );
	} elseif ( 'vila_stay_nightly' === $column ) {
		echo esc_html( $data['nightly'] > 0 ? vila_akoya_stay_price_label( $data['nightly'] ) : '—' );
	} elseif ( 'vila_stay_sleeps' === $column ) {
		echo esc_html( (string) $data['sleeps'] );
	}
}
add_action( 'manage_stay_posts_custom_column', 'vila_akoya_stay_admin_column_content', 10, 2 );
