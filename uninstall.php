<?php
/**
 * Uninstall WP File Cache.
 * Removes the option and every cached page; the drop-in and the
 * WP_CACHE constant are left alone (removing the constant is a
 * wp-config.php edit, which a plugin must never do silently).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wfc_settings' );

$dir = WP_CONTENT_DIR . '/cache/wp-file-cache';
if ( is_dir( $dir ) ) {
	foreach ( glob( $dir . '/*' ) as $file ) {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
	rmdir( $dir );
}
