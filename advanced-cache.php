<?php
/**
 * WP File Cache — drop-in.
 *
 * Copy this file to wp-content/advanced-cache.php and add
 * define( 'WP_CACHE', true ); to wp-config.php.
 * WordPress loads it before plugins, so a fresh cached page is served
 * without booting WordPress at all.
 *
 * Cache files live in wp-content/cache/wp-file-cache/ and are keyed by
 * md5( host + request URI ). Settings (TTL, excluded paths) are read from
 * wp-content/cache/wp-file-cache/config.php, written by the plugin.
 */

if ( defined( 'ABSPATH' ) ) {
	$wfc_content_dir = WP_CONTENT_DIR;
} else {
	$wfc_content_dir = dirname( __FILE__ );
}

$wfc_cache_dir = $wfc_content_dir . '/cache/wp-file-cache';
$wfc_ttl       = 3600; // seconds; overridden by config.php
$wfc_excluded  = array();

// Settings written by the plugin. Missing file = defaults.
$wfc_config = $wfc_cache_dir . '/config.php';
if ( is_readable( $wfc_config ) ) {
	$wfc_settings = include $wfc_config;
	if ( is_array( $wfc_settings ) ) {
		if ( isset( $wfc_settings['ttl'] ) ) {
			$wfc_ttl = (int) $wfc_settings['ttl'];
		}
		if ( isset( $wfc_settings['excluded'] ) && is_array( $wfc_settings['excluded'] ) ) {
			$wfc_excluded = $wfc_settings['excluded'];
		}
	}
}

// Only plain GET requests from anonymous visitors are cacheable.
$wfc_cacheable = true;
if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
	$wfc_cacheable = false;
}
if ( $wfc_cacheable && isset( $_SERVER['HTTP_COOKIE'] ) ) {
	// Any WordPress login/session cookie means a logged-in user.
	if ( false !== strpos( $_SERVER['HTTP_COOKIE'], 'wordpress_logged_in_' ) ) {
		$wfc_cacheable = false;
	}
}
if ( $wfc_cacheable && isset( $_SERVER['REQUEST_URI'] ) ) {
	$wfc_uri = $_SERVER['REQUEST_URI'];
	if ( false !== strpos( $wfc_uri, '/wp-admin/' )
		|| false !== strpos( $wfc_uri, 'wp-login.php' )
		|| false !== strpos( $wfc_uri, 'wp-cron.php' )
		|| false !== strpos( $wfc_uri, 'xmlrpc.php' )
		|| false !== strpos( $wfc_uri, '/wp-json/' )
		|| false !== strpos( $wfc_uri, 'preview=true' )
		|| false !== strpos( $wfc_uri, 'customize_changeset_uuid' )
	) {
		$wfc_cacheable = false;
	}
	foreach ( $wfc_excluded as $wfc_pattern ) {
		if ( '' !== $wfc_pattern && false !== strpos( $wfc_uri, $wfc_pattern ) ) {
			$wfc_cacheable = false;
			break;
		}
	}
}

if ( $wfc_cacheable ) {
	$wfc_host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
	$wfc_key  = md5( $wfc_host . $_SERVER['REQUEST_URI'] );
	$wfc_file = $wfc_cache_dir . '/' . $wfc_key . '.html';

	if ( is_readable( $wfc_file ) && ( time() - filemtime( $wfc_file ) ) < $wfc_ttl ) {
		header( 'X-WP-File-Cache: HIT' );
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', filemtime( $wfc_file ) ) . ' GMT' );
		readfile( $wfc_file );
		exit;
	}
}

// Miss: let WordPress boot. The plugin captures the output on shutdown.
if ( ! defined( 'WFC_CACHE_MISS' ) ) {
	define( 'WFC_CACHE_MISS', true );
}
