<?php
/**
 * WordPress Administration Bootstrap
 *
 * @package WordPress
 * @subpackage Administration
 */

/**
 * In WordPress Administration Screens
 *
 * @since 2.3.2
 */
if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}

if ( ! defined('WP_NETWORK_ADMIN') )
	define('WP_NETWORK_ADMIN', false);

if ( ! defined('WP_USER_ADMIN') )
	define('WP_USER_ADMIN', false);

if ( ! WP_NETWORK_ADMIN && ! WP_USER_ADMIN ) {
	define('WP_BLOG_ADMIN', true);
}

if ( isset($_GET['import']) && !defined('WP_LOAD_IMPORTERS') )
	define('WP_LOAD_IMPORTERS', true);

require_once(dirname(dirname(__FILE__)) . '/wp-load.php');

nocache_headers();

if ( get_option('db_upgraded') ) {
	flush_rewrite_rules();
	update_option( 'db_upgraded',  false );

	/**
	 * Fires on the next page load after a successful DB upgrade.
	 *
	 * @since 2.8.0
	 */
	do_action( 'after_db_upgrade' );
} elseif ( get_option('db_version') != $wp_db_version && empty($_POST) ) {
	if ( !is_multisite() ) {
		wp_redirect( admin_url( 'upgrade.php?_wp_http_referer=' . urlencode( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) );
		exit;

	/**
	 * Filters whether to attempt to perform the multisite DB upgrade routine.
	 *
	 * In single site, the user would be redirected to wp-admin/upgrade.php.
	 * In multisite, the DB upgrade routine is automatically fired, but only
	 * when this filter returns true.
	 *
	 * If the network is 50 sites or less, it will run every time. Otherwise,
	 * it will throttle itself to reduce load.
	 *
	 * @since 3.0.0
	 *
	 * @param bool $do_mu_upgrade Whether to perform the Multisite upgrade routine. Default true.
	 */
	} elseif ( apply_filters( 'do_mu_upgrade', true ) ) {
		$c = get_blog_count();

		/*
		 * If there are 50 or fewer sites, run every time. Otherwise, throttle to reduce load:
		 * attempt to do no more than threshold value, with some +/- allowed.
		 */
		if ( $c <= 50 || ( $c > 50 && mt_rand( 0, (int)( $c / 50 ) ) == 1 ) ) {
			require_once( ABSPATH . WPINC . '/http.php' );
			$response = wp_remote_get( admin_url( 'upgrade.php?step=1' ), array( 'timeout' => 120, 'httpversion' => '1.1' ) );
			/** This action is documented in wp-admin/network/upgrade.php */
			do_action( 'after_mu_upgrade', $response );
			unset($response);
		}
		unset($c);
	}
}

require_once(ABSPATH . 'wp-admin/includes/admin.php');

auth_redirect();

// Schedule trash collection
if ( ! wp_next_scheduled( 'wp_scheduled_delete' ) && ! wp_installing() )
	wp_schedule_event(time(), 'daily', 'wp_scheduled_delete');

set_screen_options();

$date_format = __( 'F j, Y' );
$