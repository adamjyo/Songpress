<?php
defined( 'ABSPATH' ) || exit;

/**
 * Forms and links that used to go to /wp-admin/admin-post.php now go to the site's own address, and are run from there.
 * Some hosts and security plugins turn people away from admin-post.php when they sign in on the front end, which showed up as
 * "page not found" after pressing a button (uploads, downloads, saves). Same handlers, same nonces, same permission checks;
 * only the door is different. Inside wp-admin nothing changes.
 */
class Songpress_Post {

	public static function init() {
		add_filter( 'admin_url', array( __CLASS__, 'door' ), 10, 2 );
		add_action( 'wp_loaded', array( __CLASS__, 'run' ), 20 );
	}

	/** Pure-ish: the address for an admin-post.php path, or the original. */
	public static function door( $url, $path = '' ) {
		if ( is_admin() || ! preg_match( '#^admin-post\.php(?:\?(.*))?$#', (string) $path, $m ) ) {
			return $url;
		}
		$u = add_query_arg( 'songpress_post', '1', home_url( '/' ) );
		return empty( $m[1] ) ? $u : $u . '&' . $m[1];
	}

	/** Run the SongPress action named in the request, as admin-post.php would. */
	public static function run() {
		if ( ! isset( $_REQUEST['songpress_post'], $_REQUEST['action'] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification -- each handler checks its own nonce.
			return;
		}
		$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 0 !== strpos( $action, 'songpress_' ) || ! has_action( 'admin_post_' . $action ) ) {
			return;
		}
		do_action( 'admin_post_' . $action );
		exit;
	}
}
