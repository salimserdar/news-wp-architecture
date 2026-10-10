<?php
/**
 * List generated attachment sizes on local disk. Read-only.
 *
 *   scripts/list-image-sizes.sh > /tmp/image-sizes-to-delete.tsv
 *
 * Columns: attachment ID, size name, path under uploads/, bytes, on disk (1 or 0).
 * Keeps the full-size file and original_image. Skips _news_single_image uploads.
 */

$uploads  = wp_get_upload_dir();
$basedir  = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
$realbase = '' !== $basedir ? realpath( $basedir ) : false;
if ( '' === $basedir || false === $realbase || ! empty( $uploads['error'] ) ) {
	fwrite( STDERR, "uploads dizini yok\n" );
	exit( 1 );
}
$realbase = wp_normalize_path( $realbase );

$attachments = 0;
$files       = 0;
$bytes       = 0;
$missing     = 0;
$after       = 0;
$seen        = [];

while ( true ) {
	$ids = $GLOBALS['wpdb']->get_col(
		$GLOBALS['wpdb']->prepare(
			"SELECT ID FROM {$GLOBALS['wpdb']->posts}
			 WHERE post_type = 'attachment' AND post_status = 'inherit'
			   AND post_mime_type LIKE 'image/%%' AND ID > %d
			 ORDER BY ID ASC LIMIT 200",
			$after
		)
	);
	if ( ! $ids ) {
		break;
	}
	foreach ( $ids as $id ) {
		$after = (int) $id;
		if ( '1' === (string) get_post_meta( $after, '_news_single_image', true ) ) {
			continue;
		}
		$meta = wp_get_attachment_metadata( $after );
		if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			continue;
		}
		$main = isset( $meta['file'] ) && is_string( $meta['file'] ) ? $meta['file'] : '';
		$dir  = '' === $main ? '' : dirname( str_replace( '\\', '/', $main ) );
		$dir  = '.' === $dir ? '' : $dir;
		$keep = [];
		if ( '' !== $main ) {
			$keep[ ltrim( str_replace( '\\', '/', $main ), '/' ) ] = true;
		}
		if ( ! empty( $meta['original_image'] ) && is_string( $meta['original_image'] ) ) {
			$orig = ( '' === $dir ? '' : $dir . '/' ) . $meta['original_image'];
			$keep[ ltrim( str_replace( '\\', '/', $orig ), '/' ) ] = true;
		}
		$hit = false;
		foreach ( $meta['sizes'] as $name => $size ) {
			if ( ! is_array( $size ) ) {
				continue;
			}
			$names = [];
			if ( ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
				$names[] = $size['file'];
			}
			if ( ! empty( $size['sources'] ) && is_array( $size['sources'] ) ) {
				foreach ( $size['sources'] as $source ) {
					if ( is_array( $source ) && ! empty( $source['file'] ) && is_string( $source['file'] ) ) {
						$names[] = $source['file'];
					}
				}
			}
			foreach ( $names as $name_file ) {
				$rel = ltrim( str_replace( '\\', '/', ( '' === $dir ? '' : $dir . '/' ) . $name_file ), '/' );
				$rel = preg_replace( '#/+#', '/', $rel ) ?? $rel;
				if ( '' === $rel || str_contains( $rel, '..' ) || isset( $keep[ $rel ] ) || isset( $seen[ $rel ] ) ) {
					continue;
				}
				$abs = wp_normalize_path( $basedir . '/' . $rel );
				if ( is_file( $abs ) ) {
					$real = realpath( $abs );
					if ( false === $real || ! str_starts_with( wp_normalize_path( $real ), $realbase . '/' ) ) {
						continue;
					}
					$exists     = 1;
					$size_bytes = (int) filesize( $real );
				} else {
					$exists     = 0;
					$size_bytes = 0;
				}
				$seen[ $rel ] = true;
				echo $after . "\t" . $name . "\t" . $rel . "\t" . $size_bytes . "\t" . $exists . "\n";
				$files++;
				$bytes += $size_bytes;
				if ( ! $exists ) {
					$missing++;
				}
				$hit = true;
			}
		}
		if ( $hit ) {
			$attachments++;
		}
	}
}

fwrite( STDERR, "ek={$attachments} dosya={$files} bayt={$bytes} diskte_yok={$missing}\n" );
