<?php
/**
 * Sideload design imagery into the Media Library and map external URLs → local URLs on render.
 *
 * @package vila-akoya
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VILA_AKOYA_MEDIA_IMPORT_BATCH', 3 );

/**
 * Import a batch of remote images (avoids HTTP timeouts on large sets).
 */
function vila_akoya_maybe_import_media() {
	if ( (int) get_option( 'vila_akoya_media_import_version', 0 ) >= 1 ) {
		return;
	}

	$urls   = vila_akoya_design_images();
	$keys   = array_keys( $urls );
	$total  = count( $keys );
	$map    = get_option( 'vila_akoya_media_url_map' );
	$map    = is_array( $map ) ? $map : array();
	$offset = (int) get_option( 'vila_akoya_media_import_offset', 0 );
	// Avoid a stuck import if the option was corrupted or the image list shrank.
	if ( $total > 0 ) {
		$offset = min( $offset, $total );
	}

	if ( 0 === $total ) {
		update_option( 'vila_akoya_media_url_map', $map );
		update_option( 'vila_akoya_media_import_version', 1 );
		delete_option( 'vila_akoya_media_import_offset' );
		return;
	}

	if ( $offset >= $total ) {
		update_option( 'vila_akoya_media_url_map', $map );
		update_option( 'vila_akoya_media_import_version', 1 );
		delete_option( 'vila_akoya_media_import_offset' );
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$processed = 0;

	while ( $processed < VILA_AKOYA_MEDIA_IMPORT_BATCH && $offset < $total ) {
		$key = $keys[ $offset ];
		$url = $urls[ $key ];

		if ( ! isset( $map[ $url ] ) ) {
			$new = vila_akoya_sideload_remote_image( $key, $url );
			if ( $new ) {
				$map[ $url ] = $new;
			}
		}

		$offset++;
		$processed++;
	}

	update_option( 'vila_akoya_media_url_map', $map );
	update_option( 'vila_akoya_media_import_offset', $offset );

	if ( $offset >= $total ) {
		update_option( 'vila_akoya_media_import_version', 1 );
		delete_option( 'vila_akoya_media_import_offset' );
	}
}
add_action( 'init', 'vila_akoya_maybe_import_media', 100 );

/**
 * Download a remote image and register it as an attachment (no upload_files check).
 *
 * @param string $key Asset key (filename stem).
 * @param string $url Remote URL.
 * @return string Local attachment URL or empty string.
 */
function vila_akoya_sideload_remote_image( $key, $url ) {
	$tmp = download_url( $url, 60 );
	if ( is_wp_error( $tmp ) ) {
		return '';
	}

	$type = wp_check_filetype_and_ext( $tmp, 'import.jpg' );
	$ext  = ! empty( $type['ext'] ) ? '.' . $type['ext'] : '.jpg';

	$bits = file_get_contents( $tmp );
	@unlink( $tmp );

	if ( ! $bits ) {
		return '';
	}

	$filename = 'vila-akoya-' . sanitize_file_name( $key ) . $ext;
	$upload   = wp_upload_bits( $filename, null, $bits );

	if ( ! empty( $upload['error'] ) ) {
		return '';
	}

	$mime = wp_check_filetype( $upload['file'] );

	$attachment = array(
		'post_mime_type' => $mime['type'],
		'post_title'     => 'Vila Akoya — ' . $key,
		'post_content'   => '',
		'post_status'    => 'inherit',
	);

	$attach_id = wp_insert_attachment( $attachment, $upload['file'], 0, true );

	if ( is_wp_error( $attach_id ) || ! $attach_id ) {
		return '';
	}

	$meta = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
	wp_update_attachment_metadata( $attach_id, $meta );

	$new_url = wp_get_attachment_url( $attach_id );

	return $new_url ? $new_url : '';
}

/**
 * Replace known external image URLs with local attachment URLs in rendered blocks.
 *
 * @param string $block_content HTML.
 * @param array  $block         Parsed block.
 * @return string
 */
function vila_akoya_replace_media_urls_in_block( $block_content, $block ) {
	$map = get_option( 'vila_akoya_media_url_map' );
	if ( empty( $map ) || ! is_array( $map ) ) {
		return $block_content;
	}

	foreach ( $map as $from => $to ) {
		if ( $from === $to ) {
			continue;
		}
		$block_content = str_replace( $from, $to, $block_content );
	}

	return $block_content;
}
add_filter( 'render_block', 'vila_akoya_replace_media_urls_in_block', 4, 2 );
