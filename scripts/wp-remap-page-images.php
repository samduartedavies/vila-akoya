<?php
global $wpdb;

$attachments = $wpdb->get_results(
	"SELECT p.ID, pm.meta_value AS file
	 FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->postmeta} pm
	   ON pm.post_id = p.ID
	  AND pm.meta_key = '_wp_attached_file'
	 WHERE p.post_type = 'attachment'"
);

$map = array();
foreach ( (array) $attachments as $attachment ) {
	$name = basename( (string) $attachment->file );
	$url  = wp_get_attachment_url( (int) $attachment->ID );
	if ( '' !== $name && $url ) {
		$map[ $name ] = $url;
	}
}

$all_attachment_urls = array_values( $map );

$rows = $wpdb->get_results(
	"SELECT ID, post_content
	 FROM {$wpdb->posts}
	 WHERE post_type = 'page'
	   AND post_content LIKE '%https://vila-akoya.com/images/%'"
);

$updated = 0;
$replaced = 0;

foreach ( (array) $rows as $row ) {
	$content = (string) $row->post_content;
	$new     = preg_replace_callback(
		'#https://vila-akoya\\.com/images/([^"\\s>]+)#',
		static function ( array $matches ) use ( $map, &$replaced ): string {
			$file = basename( (string) $matches[1] );
			if ( isset( $map[ $file ] ) ) {
				$replaced++;
				return (string) $map[ $file ];
			}
			$base = pathinfo( $file, PATHINFO_FILENAME );
			$ext  = pathinfo( $file, PATHINFO_EXTENSION );
			$slug = preg_quote( $base, '#' );
			$extq = preg_quote( $ext, '#' );
			foreach ( $map as $attached_file => $attached_url ) {
				if ( preg_match( '#^' . $slug . '(?:-[0-9]+)?(?:-scaled)?\\.' . $extq . '$#i', (string) $attached_file ) ) {
					$replaced++;
					return (string) $attached_url;
				}
			}
			return (string) $matches[0];
		},
		$content
	);

	if ( is_string( $new ) && $new !== $content ) {
		wp_update_post(
			array(
				'ID'           => (int) $row->ID,
				'post_content' => $new,
			)
		);
		$updated++;
	}
}

echo 'pages_updated=' . $updated . ' replacements=' . $replaced . PHP_EOL;
