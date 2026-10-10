<?php
/**
 * Delete generated attachment sizes listed by list-image-sizes.php.
 * Disk only. Does not change the database or R2.
 *
 *   scripts/delete-image-sizes.sh /tmp/image-sizes-to-delete.tsv
 *   scripts/delete-image-sizes.sh /tmp/image-sizes-to-delete.tsv apply 200
 *   scripts/delete-image-sizes.sh /tmp/image-sizes-to-delete.tsv apply
 *
 * Without "apply" it only counts. The third argument stops after that many deletions.
 */

if ( ! isset( $args ) || ! is_array( $args ) || empty( $args[0] ) || ! is_string( $args[0] ) ) {
	fwrite( STDERR, "kullanim: scripts/delete-image-sizes.sh <tsv> [apply] [adet]\n" );
	exit( 1 );
}

$tsv   = $args[0];
$apply = isset( $args[1] ) && 'apply' === $args[1];
$limit = isset( $args[2] ) ? (int) $args[2] : 0;
if ( ! is_file( $tsv ) ) {
	fwrite( STDERR, "liste yok: {$tsv}\n" );
	exit( 1 );
}

$uploads  = wp_get_upload_dir();
$basedir  = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
$realbase = '' !== $basedir ? realpath( $basedir ) : false;
if ( '' === $basedir || false === $realbase || ! empty( $uploads['error'] ) ) {
	fwrite( STDERR, "uploads dizini yok\n" );
	exit( 1 );
}
$realbase = wp_normalize_path( $realbase );

$handle = fopen( $tsv, 'rb' );
if ( false === $handle ) {
	fwrite( STDERR, "liste acilamadi: {$tsv}\n" );
	exit( 1 );
}

$present    = 0;
$bytes      = 0;
$deleted    = 0;
$deleted_b  = 0;
$missing    = 0;
$refused    = 0;
$allowed    = null;
$current_id = 0;

while ( ( $line = fgets( $handle ) ) !== false ) {
	if ( $limit > 0 && $deleted >= $limit ) {
		break;
	}
	$line = trim( $line );
	if ( '' === $line ) {
		continue;
	}
	$cols = explode( "\t", $line );
	if ( count( $cols ) < 5 ) {
		$refused++;
		continue;
	}
	$id     = (int) $cols[0];
	$rel    = str_replace( '\\', '/', trim( $cols[2] ) );
	$rel    = preg_replace( '#/+#', '/', ltrim( $rel, '/' ) ) ?? '';
	$exists = (int) trim( $cols[4] );
	if ( $id <= 0 || '' === $rel || str_contains( $rel, '..' ) || 1 !== $exists ) {
		if ( 1 !== $exists ) {
			$missing++;
		} else {
			$refused++;
		}
		continue;
	}
	if ( ! preg_match( '/-\d+x\d+\.(?:jpe?g|png|gif|webp)$/i', $rel ) ) {
		$refused++;
		continue;
	}

	$present++;
	$row_bytes = (int) trim( $cols[3] );
	$bytes    += $row_bytes;

	if ( ! $apply ) {
		continue;
	}
	if ( $id !== $current_id ) {
		$current_id = $id;
		$allowed    = news_delete_image_size_rels( $id );
	}
	if ( ! isset( $allowed[ $rel ] ) ) {
		$refused++;
		$present--;
		$bytes -= $row_bytes;
		continue;
	}

	$abs = wp_normalize_path( $basedir . '/' . $rel );
	if ( ! is_file( $abs ) ) {
		$missing++;
		continue;
	}
	$real = realpath( $abs );
	if ( false === $real || ! str_starts_with( wp_normalize_path( $real ), $realbase . '/' ) ) {
		$refused++;
		continue;
	}
	$size_bytes = (int) filesize( $real );
	if ( ! unlink( $real ) ) {
		$refused++;
		fwrite( STDERR, "silinemedi {$rel}\n" );
		continue;
	}
	$deleted++;
	$deleted_b += $size_bytes;
	if ( 0 === $deleted % 5000 ) {
		fwrite( STDERR, "silindi={$deleted}\n" );
	}
}
fclose( $handle );

if ( ! $apply ) {
	fwrite( STDERR, "deneme dosya={$present} bayt={$bytes} diskte_yok_satir={$missing} reddedildi={$refused}\n" );
	fwrite( STDERR, "silinmedi. uygulamak icin: apply\n" );
	exit( 0 );
}

fwrite( STDERR, "silindi={$deleted} bayt={$deleted_b} diskte_yok_satir={$missing} reddedildi={$refused}\n" );

/**
 * Relative uploads paths that are generated sizes for one attachment.
 *
 * @return array<string,true>
 */
function news_delete_image_size_rels( int $id ): array {
	if ( '1' === (string) get_post_meta( $id, '_news_single_image', true ) ) {
		return [];
	}
	$meta = wp_get_attachment_metadata( $id );
	if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
		return [];
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
	$rels = [];
	foreach ( $meta['sizes'] as $size ) {
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
			if ( '' === $rel || str_contains( $rel, '..' ) || isset( $keep[ $rel ] ) ) {
				continue;
			}
			$rels[ $rel ] = true;
		}
	}
	return $rels;
}
