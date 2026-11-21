<?php
/**
 * Plugin Name: WP File Cache
 * Plugin URI: https://github.com/Azanumer/wp-file-cache
 * Description: Full-page file cache for WordPress. A tiny advanced-cache.php drop-in serves cached pages without booting WordPress; this plugin writes the cache, purges it on content changes, and manages settings.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * Text Domain: wp-file-cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WFC_VERSION', '1.0.0' );

/**
 * Cache directory: wp-content/cache/wp-file-cache/
 */
function wfc_cache_dir() {
	return WP_CONTENT_DIR . '/cache/wp-file-cache';
}

/**
 * Cache key for a URL: md5( host + path + query ), matching the drop-in.
 */
function wfc_cache_key( $url ) {
	$parts = wp_parse_url( $url );
	$host  = isset( $parts['host'] ) ? $parts['host'] : '';
	$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
	$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
	return md5( $host . $path . $query );
}

function wfc_cache_file( $url ) {
	return wfc_cache_dir() . '/' . wfc_cache_key( $url ) . '.html';
}

function wfc_get_settings() {
	$defaults = array(
		'enabled'  => true,
		'ttl'      => 3600,
		'excluded' => array( '/cart/', '/checkout/', '/my-account/' ),
	);
	$saved = get_option( 'wfc_settings', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

/**
 * Write the config.php the drop-in reads (TTL + excluded patterns).
 */
function wfc_write_config() {
	$dir = wfc_cache_dir();
	if ( ! wp_mkdir_p( $dir ) ) {
		return false;
	}
	$settings = wfc_get_settings();
	$config   = "<?php\nreturn array(\n\t'ttl' => " . (int) $settings['ttl'] . ",\n\t'excluded' => " . var_export( array_values( (array) $settings['excluded'] ), true ) . ",\n);\n";
	return (bool) file_put_contents( $dir . '/config.php', $config, LOCK_EX );
}

function wfc_is_cacheable_request() {
	$settings = wfc_get_settings();
	if ( ! $settings['enabled'] ) {
		return false;
	}
	if ( ! defined( 'WFC_CACHE_MISS' ) ) {
		return false; // Drop-in already served the page, or WP_CACHE is off.
	}
	if ( 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
		return false;
	}
	if ( is_user_logged_in() || is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return false;
	}
	if ( is_404() || is_search() || is_feed() || is_preview() ) {
		return false;
	}
	if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
		return false;
	}
	if ( ! empty( $_POST ) ) {
		return false;
	}
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
	foreach ( (array) $settings['excluded'] as $pattern ) {
		if ( '' !== $pattern && false !== strpos( $uri, $pattern ) ) {
			return false;
		}
	}
	return apply_filters( 'wfc_is_cacheable_request', true );
}

/**
 * Start output buffering on cacheable front-end requests.
 */
function wfc_maybe_start_buffer() {
	if ( wfc_is_cacheable_request() ) {
		ob_start();
	}
}
add_action( 'template_redirect', 'wfc_maybe_start_buffer', 0 );

/**
 * On shutdown, store the buffered page as a cache file (atomic rename).
 */
function wfc_maybe_store_cache() {
	if ( ! wfc_is_cacheable_request() ) {
		return;
	}
	if ( ob_get_level() < 1 ) {
		return;
	}
	$html = ob_get_contents();
	if ( false === $html || '' === trim( $html ) ) {
		return;
	}
	if ( 200 !== (int) http_response_code() ) {
		return;
	}
	$dir = wfc_cache_dir();
	if ( ! wp_mkdir_p( $dir ) ) {
		return;
	}
	$url  = home_url( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/' );
	$file = wfc_cache_file( $url );
	$tmp  = $file . '.' . getmypid() . '.tmp';
	if ( false !== file_put_contents( $tmp, $html, LOCK_EX ) ) {
		rename( $tmp, $file ); // Atomic: readers never see a partial file.
	} elseif ( file_exists( $tmp ) ) {
		unlink( $tmp );
	}
}
add_action( 'shutdown', 'wfc_maybe_store_cache', 0 );

/**
 * Delete the cache file for one URL.
 */
function wfc_purge_url( $url ) {
	$file = wfc_cache_file( $url );
	if ( file_exists( $file ) ) {
		unlink( $file );
	}
}

/**
 * Delete every cached page. Returns the number of files removed.
 */
function wfc_purge_all() {
	$dir     = wfc_cache_dir();
	$removed = 0;
	if ( ! is_dir( $dir ) ) {
		return 0;
	}
	foreach ( glob( $dir . '/*.html' ) as $file ) {
		if ( unlink( $file ) ) {
			$removed++;
		}
	}
	return $removed;
}

/**
 * Purge a post's own URL plus the front page when content changes.
 */
function wfc_purge_on_save( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( 'publish' !== $post->post_status && 'trash' !== $post->post_status ) {
		return;
	}
	wfc_purge_url( get_permalink( $post_id ) );
	wfc_purge_url( home_url( '/' ) );
}
add_action( 'save_post', 'wfc_purge_on_save', 10, 2 );
add_action( 'deleted_post', 'wfc_purge_on_save', 10, 2 );
add_action( 'switch_theme', 'wfc_purge_all' );
add_action( 'customize_save_after', 'wfc_purge_all' );

/**
 * Total size of cached files, for the settings page.
 */
function wfc_cache_stats() {
	$dir   = wfc_cache_dir();
	$files = is_dir( $dir ) ? glob( $dir . '/*.html' ) : array();
	$size  = 0;
	foreach ( (array) $files as $file ) {
		$size += filesize( $file );
	}
	return array( 'count' => count( (array) $files ), 'size' => $size );
}

/* ------------------------------------------------------------------ */
/* Activation / settings                                               */
/* ------------------------------------------------------------------ */

function wfc_activate() {
	if ( false === get_option( 'wfc_settings' ) ) {
		add_option( 'wfc_settings', wfc_get_settings() );
	}
	wp_mkdir_p( wfc_cache_dir() );
	wfc_write_config();

	// Install the drop-in when WP_CACHE is already on.
	if ( defined( 'WP_CACHE' ) && WP_CACHE && ! file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
		copy( plugin_dir_path( __FILE__ ) . 'advanced-cache.php', WP_CONTENT_DIR . '/advanced-cache.php' );
	}
}
register_activation_hook( __FILE__, 'wfc_activate' );

function wfc_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( $screen && 'settings_page_wp-file-cache' === $screen->id ) {
		return; // The settings page explains it in full.
	}
	$dropin_ok = file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );
	$cache_on  = defined( 'WP_CACHE' ) && WP_CACHE;
	if ( $dropin_ok && $cache_on ) {
		return;
	}
	$steps = array();
	if ( ! $cache_on ) {
		$steps[] = "add <code>define( 'WP_CACHE', true );</code> to wp-config.php";
	}
	if ( ! $dropin_ok ) {
		$steps[] = 'copy <code>advanced-cache.php</code> from this plugin into <code>wp-content/</code>';
	}
	echo '<div class="notice notice-warning"><p><strong>WP File Cache:</strong> caching is inactive — '
		. esc_html__( 'to enable it, ', 'wp-file-cache' )
		. implode( esc_html__( ' and ', 'wp-file-cache' ), $steps )
		. '. <a href="' . esc_url( admin_url( 'options-general.php?page=wp-file-cache' ) ) . '">'
		. esc_html__( 'Open settings', 'wp-file-cache' ) . '</a></p></div>';
}
add_action( 'admin_notices', 'wfc_admin_notice' );

function wfc_register_settings_page() {
	add_options_page(
		__( 'File Cache', 'wp-file-cache' ),
		__( 'File Cache', 'wp-file-cache' ),
		'manage_options',
		'wp-file-cache',
		'wfc_render_settings_page'
	);
}
add_action( 'admin_menu', 'wfc_register_settings_page' );

function wfc_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-file-cache' ) );
	}
	$settings = wfc_get_settings();
	$message  = '';

	if ( isset( $_POST['wfc_save'] ) && check_admin_referer( 'wfc_save_settings' ) ) {
		$excluded_raw = isset( $_POST['wfc_excluded'] ) ? wp_unslash( $_POST['wfc_excluded'] ) : '';
		$excluded     = array_filter( array_map( 'trim', explode( "\n", $excluded_raw ) ) );
		$settings     = array(
			'enabled'  => ! empty( $_POST['wfc_enabled'] ),
			'ttl'      => max( 60, (int) ( $_POST['wfc_ttl'] ?? 3600 ) ),
			'excluded' => array_values( $excluded ),
		);
		update_option( 'wfc_settings', $settings );
		wfc_write_config();
		$message = __( 'Settings saved.', 'wp-file-cache' );
	}

	if ( isset( $_POST['wfc_purge'] ) && check_admin_referer( 'wfc_purge_cache' ) ) {
		$removed = wfc_purge_all();
		/* translators: %d: number of cached files removed. */
		$message = sprintf( __( 'Cache purged — %d file(s) removed.', 'wp-file-cache' ), $removed );
	}

	if ( isset( $_POST['wfc_install_dropin'] ) && check_admin_referer( 'wfc_install_dropin' ) ) {
		if ( copy( plugin_dir_path( __FILE__ ) . 'advanced-cache.php', WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
			$message = __( 'Drop-in installed to wp-content/advanced-cache.php.', 'wp-file-cache' );
		} else {
			$message = __( 'Could not copy the drop-in — check wp-content/ permissions and copy it manually.', 'wp-file-cache' );
		}
	}

	$stats     = wfc_cache_stats();
	$dropin_ok = file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );
	$cache_on  = defined( 'WP_CACHE' ) && WP_CACHE;
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'WP File Cache', 'wp-file-cache' ); ?></h1>
		<?php if ( '' !== $message ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Status', 'wp-file-cache' ); ?></h2>
		<table class="form-table"><tbody>
			<tr><th><?php esc_html_e( 'WP_CACHE constant', 'wp-file-cache' ); ?></th><td><?php echo $cache_on ? esc_html__( 'On', 'wp-file-cache' ) : esc_html__( 'Off — add define( \'WP_CACHE\', true ); to wp-config.php', 'wp-file-cache' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Drop-in', 'wp-file-cache' ); ?></th><td><?php echo $dropin_ok ? esc_html__( 'Installed (wp-content/advanced-cache.php)', 'wp-file-cache' ) : esc_html__( 'Not installed', 'wp-file-cache' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Cached pages', 'wp-file-cache' ); ?></th><td><?php echo esc_html( number_format_i18n( $stats['count'] ) . ' (' . size_format( $stats['size'] ) . ')' ); ?></td></tr>
		</tbody></table>

		<?php if ( ! $dropin_ok ) : ?>
			<form method="post">
				<?php wp_nonce_field( 'wfc_install_dropin' ); ?>
				<p><input type="submit" name="wfc_install_dropin" class="button" value="<?php esc_attr_e( 'Install drop-in', 'wp-file-cache' ); ?>"></p>
			</form>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'wfc_save_settings' ); ?>
			<table class="form-table"><tbody>
				<tr>
					<th scope="row"><label for="wfc_enabled"><?php esc_html_e( 'Enable caching', 'wp-file-cache' ); ?></label></th>
					<td><input type="checkbox" id="wfc_enabled" name="wfc_enabled" value="1" <?php checked( $settings['enabled'] ); ?>></td>
				</tr>
				<tr>
					<th scope="row"><label for="wfc_ttl"><?php esc_html_e( 'Cache lifetime (seconds)', 'wp-file-cache' ); ?></label></th>
					<td><input type="number" id="wfc_ttl" name="wfc_ttl" min="60" step="60" value="<?php echo esc_attr( $settings['ttl'] ); ?>" class="small-text"> <span class="description"><?php esc_html_e( 'Minimum 60 seconds.', 'wp-file-cache' ); ?></span></td>
				</tr>
				<tr>
					<th scope="row"><label for="wfc_excluded"><?php esc_html_e( 'Excluded URL patterns', 'wp-file-cache' ); ?></label></th>
					<td>
						<textarea id="wfc_excluded" name="wfc_excluded" rows="4" cols="50" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $settings['excluded'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One substring per line. Any URL containing one of these is never cached (e.g. /cart/, /checkout/).', 'wp-file-cache' ); ?></p>
					</td>
				</tr>
			</tbody></table>
			<p><input type="submit" name="wfc_save" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'wp-file-cache' ); ?>"></p>
		</form>

		<form method="post">
			<?php wp_nonce_field( 'wfc_purge_cache' ); ?>
			<p><input type="submit" name="wfc_purge" class="button" value="<?php esc_attr_e( 'Purge Entire Cache', 'wp-file-cache' ); ?>"></p>
		</form>
		<p class="description"><?php esc_html_e( 'Cached pages are served with an X-WP-File-Cache: HIT header. Logged-in users, POST requests, admin pages, feeds and search results are never cached.', 'wp-file-cache' ); ?></p>
	</div>
	<?php
}
