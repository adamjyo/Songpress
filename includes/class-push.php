<?php
/**
 * Notifications for the app saved to a phone's home screen (a web app).
 * Web Push without a message body: the phone is woken, the service worker asks the site what to say, and shows it. That needs only
 * a signing key (VAPID), no extra libraries. Works on Android and on iPhones (iOS 16.4 or later) once the app is added to the Home Screen.
 */
defined( 'ABSPATH' ) || exit;

class Songpress_Push {

	const KEYS    = 'songpress_vapid';
	const SUBS    = 'songpress_push_subs';
	const NOTICES = 'songpress_notices';
	const ALERTS  = 'songpress_alert_users';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'serve' ), 2 );
		add_action( 'wp_ajax_songpress_push', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_post_songpress_push_send', array( __CLASS__, 'handle_send' ) );
		add_action( 'admin_post_songpress_push_alerts', array( __CLASS__, 'handle_alerts' ) );
		add_action( 'admin_post_songpress_push_test', array( __CLASS__, 'handle_test' ) );
	}

	/* ---------- pure helpers ---------- */

	public static function b64u( $s ) {
		return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
	}

	/** An ECDSA signature from OpenSSL (DER) as the 64 bytes a JWT wants (r then s, 32 bytes each). */
	public static function der_to_raw( $der ) {
		$i = 2;
		if ( "\x30" !== $der[0] ) {
			return '';
		}
		if ( ord( $der[1] ) & 0x80 ) {
			$i += ord( $der[1] ) & 0x7f;
		}
		$parts = array();
		for ( $n = 0; $n < 2; $n++ ) {
			if ( "\x02" !== $der[ $i ] ) {
				return '';
			}
			$len     = ord( $der[ $i + 1 ] );
			$int     = substr( $der, $i + 2, $len );
			$parts[] = str_pad( ltrim( $int, "\x00" ), 32, "\x00", STR_PAD_LEFT );
			$i      += 2 + $len;
		}
		return $parts[0] . $parts[1];
	}

	/** Pure: add a notice to a person's list (newest last), keeping the last 20. */
	public static function notice_add( array $list, array $n ) {
		$list[] = $n;
		return array_slice( $list, -20 );
	}

	/** Pure: the oldest notice not yet shown. Returns array( index, notice ) or null. */
	public static function notice_next( array $list ) {
		foreach ( $list as $i => $n ) {
			if ( empty( $n['seen'] ) ) {
				return array( $i, $n );
			}
		}
		return null;
	}

	/* ---------- keys and signing ---------- */

	/** The site's signing key, made once. Returns array( pem, public key as base64url ) or null when OpenSSL cannot make one. */
	public static function keys() {
		$k = get_option( self::KEYS );
		if ( is_array( $k ) && ! empty( $k['pem'] ) && ! empty( $k['pub'] ) ) {
			return $k;
		}
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return null;
		}
		$res = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
		if ( ! $res ) {
			return null;
		}
		openssl_pkey_export( $res, $pem );
		$d = openssl_pkey_get_details( $res );
		if ( empty( $d['ec']['x'] ) || empty( $d['ec']['y'] ) ) {
			return null;
		}
		$k = array( 'pem' => $pem, 'pub' => self::b64u( "\x04" . str_pad( $d['ec']['x'], 32, "\x00", STR_PAD_LEFT ) . str_pad( $d['ec']['y'], 32, "\x00", STR_PAD_LEFT ) ) );
		update_option( self::KEYS, $k, false );
		return $k;
	}

	/** A signed token proving the push comes from this site, for the service that holds the phone's address. */
	public static function jwt( $endpoint ) {
		$k = self::keys();
		if ( ! $k ) {
			return '';
		}
		$p   = wp_parse_url( $endpoint );
		$aud = ( $p['scheme'] ?? 'https' ) . '://' . ( $p['host'] ?? '' );
		$in  = self::b64u( wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) ) . '.' . self::b64u( wp_json_encode( array( 'aud' => $aud, 'exp' => time() + 43200, 'sub' => 'mailto:' . get_option( 'admin_email' ) ) ) );
		if ( ! openssl_sign( $in, $der, $k['pem'], OPENSSL_ALGO_SHA256 ) ) {
			return '';
		}
		return $in . '.' . self::b64u( self::der_to_raw( $der ) );
	}

	/* ---------- sending ---------- */

	public static function devices( $uid ) {
		$s = get_user_meta( (int) $uid, self::SUBS, true );
		return is_array( $s ) ? array_values( $s ) : array();
	}

	/** Tell one person: it is kept on their list and every phone they turned notifications on for is woken. Returns the number of phones reached. */
	public static function notify( $uid, $title, $body, $url = '' ) {
		$uid  = (int) $uid;
		$list = get_user_meta( $uid, self::NOTICES, true );
		$list = self::notice_add( is_array( $list ) ? $list : array(), array( 'id' => wp_generate_password( 8, false ), 'title' => (string) $title, 'body' => (string) $body, 'url' => '' !== $url ? $url : Songpress_Access::app_url(), 't' => time(), 'seen' => 0 ) );
		update_user_meta( $uid, self::NOTICES, $list );
		$reached = 0;
		$keep    = array();
		foreach ( self::devices( $uid ) as $ep ) {
			$jwt = self::jwt( $ep );
			$k   = self::keys();
			if ( '' === $jwt || ! $k ) {
				$keep[] = $ep;
				continue;
			}
			$r    = wp_remote_post( $ep, array( 'timeout' => 6, 'body' => '', 'headers' => array( 'Authorization' => 'vapid t=' . $jwt . ', k=' . $k['pub'], 'TTL' => '86400', 'Urgency' => 'normal' ) ) );
			$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			if ( 404 === $code || 410 === $code ) {
				continue; // the phone dropped it
			}
			$keep[] = $ep;
			if ( $code >= 200 && $code < 300 ) {
				$reached++;
			}
		}
		if ( count( $keep ) !== count( self::devices( $uid ) ) ) {
			update_user_meta( $uid, self::SUBS, $keep );
		}
		return $reached;
	}

	/** The WordPress user for a person's name, if they have a login with a class. */
	public static function user_for_name( $name ) {
		$key = Songpress_Money::person_key( $name );
		if ( '' === $key ) {
			return 0;
		}
		foreach ( get_users( array( 'fields' => array( 'ID', 'display_name' ) ) ) as $u ) {
			if ( Songpress_Money::person_key( $u->display_name ) === $key && '' !== Songpress_Caps::class_of( (int) $u->ID ) ) {
				return (int) $u->ID;
			}
		}
		return 0;
	}

	/* ---------- what the phone asks for ---------- */

	public static function serve() {
		if ( isset( $_GET['sp_manifest'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- public file.
			self::manifest();
		}
		if ( isset( $_GET['sp_sw'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::worker();
		}
		if ( isset( $_GET['sp_push'] ) && 'next' === $_GET['sp_push'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::next();
		}
	}

	private static function manifest() {
		nocache_headers();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		$app = Songpress_Access::app_url();
		echo wp_json_encode( array(
			'name'             => 'SongPress',
			'short_name'       => 'SongPress',
			'start_url'        => $app,
			'scope'            => home_url( '/' ),
			'display'          => 'standalone',
			'background_color' => '#0f1012',
			'theme_color'      => '#0f1012',
			'icons'            => array(
				array( 'src' => SONGPRESS_URL . 'assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => SONGPRESS_URL . 'assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
			),
		) );
		exit;
	}

	private static function worker() {
		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . ( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?: '/' ) );
		$next = esc_url_raw( add_query_arg( 'sp_push', 'next', home_url( '/' ) ) );
		$app  = esc_url_raw( Songpress_Access::app_url() );
		$icon = esc_url_raw( SONGPRESS_URL . 'assets/icon-192.png' );
		echo 'var NEXT=' . wp_json_encode( $next ) . ',APP=' . wp_json_encode( $app ) . ',ICON=' . wp_json_encode( $icon ) . ";\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON.
		echo <<<'JS'
self.addEventListener('install',function(){self.skipWaiting()});
self.addEventListener('activate',function(e){e.waitUntil(self.clients.claim())});
self.addEventListener('push',function(e){
 e.waitUntil(fetch(NEXT,{credentials:'include',cache:'no-store'}).then(function(r){return r.ok?r.json():null}).catch(function(){return null}).then(function(d){
  d=d&&d.title?d:{title:'SongPress',body:'You have something new.',url:APP};
  return self.registration.showNotification(d.title,{body:d.body,icon:ICON,badge:ICON,tag:d.id||'songpress',data:{url:d.url||APP}});
 }));
});
self.addEventListener('notificationclick',function(e){
 e.notification.close();
 var u=(e.notification.data&&e.notification.data.url)||APP;
 e.waitUntil(clients.matchAll({type:'window',includeUncontrolled:true}).then(function(l){
  for(var i=0;i<l.length;i++){if('focus' in l[i]){if(l[i].navigate){l[i].navigate(u)}return l[i].focus()}}
  return clients.openWindow(u);
 }));
});
JS;
		exit;
	}

	/** Called by the service worker with the person's own sign-in: the next thing to say. */
	private static function next() {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		$uid = get_current_user_id();
		if ( ! $uid ) {
			echo '{}';
			exit;
		}
		$list = get_user_meta( $uid, self::NOTICES, true );
		$list = is_array( $list ) ? $list : array();
		$hit  = self::notice_next( $list );
		if ( ! $hit ) {
			echo '{}';
			exit;
		}
		$list[ $hit[0] ]['seen'] = 1;
		update_user_meta( $uid, self::NOTICES, $list );
		echo wp_json_encode( array( 'id' => $hit[1]['id'], 'title' => $hit[1]['title'], 'body' => $hit[1]['body'], 'url' => $hit[1]['url'] ) );
		exit;
	}

	/* ---------- from the app ---------- */

	public static function ajax() {
		check_ajax_referer( 'songpress_push', 'nonce' );
		$uid  = get_current_user_id();
		$mode = sanitize_key( wp_unslash( $_POST['mode'] ?? '' ) );
		$ep   = esc_url_raw( wp_unslash( $_POST['endpoint'] ?? '' ) );
		if ( ! $uid || '' === Songpress_Caps::class_of( $uid ) ) {
			wp_send_json_error( 'no access', 403 );
		}
		$subs = self::devices( $uid );
		if ( 'add' === $mode && 0 === strpos( $ep, 'https://' ) ) {
			$subs[] = $ep;
			update_user_meta( $uid, self::SUBS, array_values( array_unique( $subs ) ) );
			wp_send_json_success();
		}
		if ( 'remove' === $mode ) {
			update_user_meta( $uid, self::SUBS, array_values( array_diff( $subs, array( $ep ) ) ) );
			wp_send_json_success();
		}
		if ( 'test' === $mode ) {
			wp_send_json_success( array( 'reached' => self::notify( $uid, 'SongPress', __( 'Notifications are on. This is a test.', 'songpress' ) ) ) );
		}
		wp_send_json_error( 'bad request', 400 );
	}

	/* ---------- alerts: what happens on the site, sent to the phones you choose ---------- */

	/** Who gets alerts: the people chosen under People; until someone is chosen, adam.page, else every Manager. */
	public static function alert_ids() {
		$ids = array();
		foreach ( (array) get_option( self::ALERTS, array() ) as $id ) {
			if ( get_userdata( (int) $id ) && '' !== Songpress_Caps::class_of( (int) $id ) ) {
				$ids[] = (int) $id;
			}
		}
		if ( $ids ) {
			return $ids;
		}
		$u = get_user_by( 'login', 'adam.page' );
		if ( $u && '' !== Songpress_Caps::class_of( $u->ID ) ) {
			return array( (int) $u->ID );
		}
		foreach ( get_users( array( 'fields' => 'ID' ) ) as $id ) {
			if ( 'manager' === Songpress_Caps::class_of( (int) $id ) ) {
				$ids[] = (int) $id;
			}
		}
		return $ids;
	}

	/** Send an alert to every phone chosen. Returns the number of phones reached. */
	public static function alert( $title, $body, $url = '' ) {
		$n = 0;
		foreach ( self::alert_ids() as $id ) {
			$n += self::notify( $id, $title, $body, $url );
		}
		return $n;
	}

	private static function guard_people() {
		if ( ! Songpress_Caps::can( 'members' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
	}

	public static function handle_alerts() {
		check_admin_referer( 'songpress_push_alerts' );
		self::guard_people();
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['alert'] ?? array() ) ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- ids only.
		update_option( self::ALERTS, $ids, false );
		$names = array_map( function ( $id ) {
			$u = get_userdata( $id );
			return $u ? $u->display_name : '#' . $id;
		}, $ids );
		Songpress_Audit::log( 'people', 'Chose who gets alerts', '', array( 'people' => $names ? implode( ', ', $names ) : 'nobody chosen' ) );
		wp_safe_redirect( add_query_arg( 'sp_saved', rawurlencode( __( 'Saved who gets alerts.', 'songpress' ) ), Songpress_Persons::url() ) );
		exit;
	}

	public static function handle_test() {
		check_admin_referer( 'songpress_push_test' );
		self::guard_people();
		$n = self::alert( __( 'SongPress alert', 'songpress' ), __( 'This is a test alert from the site.', 'songpress' ) );
		wp_safe_redirect( add_query_arg( $n ? 'sp_saved' : 'sp_err', rawurlencode( $n ? sprintf( /* translators: %d: phones */ _n( 'Test sent. It reached %d phone.', 'Test sent. It reached %d phones.', $n, 'songpress' ), $n ) : __( 'It did not reach a phone. The person has to open the app from their Home Screen and tap the bell to turn notifications on.', 'songpress' ) ), Songpress_Persons::url() ) );
		exit;
	}

	/** People: choose who gets the alerts, and test them. */
	public static function alerts_box() {
		$on = self::alert_ids();
		$o  = '<h3>' . esc_html__( 'Alerts to your phone', 'songpress' ) . '</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="spe-addmember">' . wp_nonce_field( 'songpress_push_alerts', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_push_alerts"><div class="spm-alerts">';
		foreach ( get_users( array( 'fields' => array( 'ID', 'display_name', 'user_login' ) ) ) as $u ) {
			if ( '' === Songpress_Caps::class_of( (int) $u->ID ) ) {
				continue;
			}
			$o .= '<label><input type="checkbox" name="alert[]" value="' . (int) $u->ID . '"' . ( in_array( (int) $u->ID, $on, true ) ? ' checked' : '' ) . '> ' . esc_html( $u->display_name ) . ' <small>' . esc_html( $u->user_login ) . ' · ' . esc_html( self::devices( (int) $u->ID ) ? __( 'phone on', 'songpress' ) : __( 'phone not on yet', 'songpress' ) ) . '</small></label>';
		}
		$o .= '</div><button class="spe-save" type="submit">' . esc_html__( 'Save', 'songpress' ) . '</button></form>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">' . wp_nonce_field( 'songpress_push_test', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_push_test"><button class="spe-btn" type="submit">' . esc_html__( 'Send a test alert', 'songpress' ) . '</button></form>'
			. '<p class="spe-help">' . esc_html__( 'These people get a notification on their phone when something happens that you would otherwise only see on the desktop: someone forgot their password, or chose one from a link. Each person must open the app from their Home Screen and tap the bell once.', 'songpress' ) . '</p>';
		return $o;
	}

	/** A Manager sends a message to one person or to everyone who turned notifications on. */
	public static function handle_send() {
		check_admin_referer( 'songpress_push_send' );
		if ( ! Songpress_Caps::can( 'members' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		$in    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- cleaned below.
		$to    = sanitize_text_field( $in['to'] ?? 'all' );
		$title = sanitize_text_field( $in['title'] ?? '' );
		$body  = sanitize_text_field( $in['body'] ?? '' );
		$back  = isset( $in['person'] ) && '' !== $in['person'] ? Songpress_Persons::url( array( 'person' => Songpress_Profiles::key( sanitize_text_field( $in['person'] ) ) ) ) : Songpress_Persons::url();
		if ( '' === $body ) {
			wp_safe_redirect( add_query_arg( 'sp_err', rawurlencode( __( 'Write the message first.', 'songpress' ) ), $back ) );
			exit;
		}
		$ids = array();
		if ( 'all' === $to ) {
			foreach ( get_users( array( 'fields' => 'ID' ) ) as $id ) {
				if ( self::devices( (int) $id ) ) {
					$ids[] = (int) $id;
				}
			}
		} else {
			$ids[] = absint( $to );
		}
		$one = 'all' === $to ? null : get_userdata( absint( $to ) );
		if ( 'all' !== $to && ( ! $one || ! self::devices( $one->ID ) ) ) {
			wp_safe_redirect( add_query_arg( 'sp_err', rawurlencode( sprintf( /* translators: %s: name */ __( '%s has not turned on notifications on a phone yet. Ask them to tap the bell in the app menu.', 'songpress' ), $one ? $one->display_name : __( 'That person', 'songpress' ) ) ), $back ) );
			exit;
		}
		$phones = 0;
		foreach ( $ids as $id ) {
			$phones += self::notify( $id, '' !== $title ? $title : 'SongPress', $body );
		}
		Songpress_Audit::log( 'people', 'Sent a notification', $one ? $one->display_name : 'everyone', array( 'message' => $body, 'phones reached' => $phones ) );
		wp_safe_redirect( add_query_arg( 'sp_saved', rawurlencode( sprintf( /* translators: %d: phones */ _n( 'Sent. It reached %d phone.', 'Sent. It reached %d phones.', $phones, 'songpress' ), $phones ) ), $back ) );
		exit;
	}

	/** The form for People: choose who (or it is for one person already), write the message. */
	public static function send_box( $only = 0 ) {
		$opts = '';
		$on   = 0;
		if ( $only ) {
			$u    = get_userdata( (int) $only );
			$on   = $u && self::devices( $u->ID ) ? 1 : 0;
			$opts = '<input type="hidden" name="to" value="' . (int) $only . '"><input type="hidden" name="person" value="' . esc_attr( $u ? $u->display_name : '' ) . '">';
			if ( ! $on ) {
				return '<h3>' . esc_html__( 'Send a notification', 'songpress' ) . '</h3><p class="spe-help">' . esc_html__( 'They have not turned on notifications yet. They do it from the bell in the app menu, after adding SongPress to their phone’s Home Screen.', 'songpress' ) . '</p>';
			}
		} else {
			$all = '';
			$off = array();
			foreach ( get_users( array( 'fields' => array( 'ID', 'display_name' ) ) ) as $u ) {
				if ( '' === Songpress_Caps::class_of( (int) $u->ID ) ) {
					continue;
				}
				if ( self::devices( (int) $u->ID ) ) {
					$all .= '<option value="' . (int) $u->ID . '">' . esc_html( $u->display_name ) . '</option>';
				} else {
					$off[] = $u->display_name;
				}
			}
			$opts = '<label class="spe-field"><span>' . esc_html__( 'To', 'songpress' ) . '</span><select name="to"><option value="all">' . esc_html__( 'Everyone with notifications on', 'songpress' ) . '</option>' . $all . '</select></label>';
		}
		return '<h3>' . esc_html__( 'Send a notification', 'songpress' ) . '</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="spe-addmember">' . wp_nonce_field( 'songpress_push_send', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_push_send">' . $opts
			. '<label class="spe-field"><span>' . esc_html__( 'Title', 'songpress' ) . '</span><input name="title" placeholder="SongPress"></label>'
			. '<label class="spe-field grow"><span>' . esc_html__( 'Message', 'songpress' ) . '</span><input name="body" required></label>'
			. '<button class="spe-save" type="submit">' . esc_html__( 'Send', 'songpress' ) . '</button></form>'
			. ( $only ? '' : '<p class="spe-help">' . esc_html( sprintf( /* translators: %s: names */ __( 'Only people who turned notifications on can be picked. Not on yet: %s.', 'songpress' ), $off ? implode( ', ', $off ) : __( 'nobody', 'songpress' ) ) ) . ' ' . esc_html__( 'They turn them on from the bell in the app menu, after adding SongPress to their Home Screen. People are also told when a payment is recorded for them.', 'songpress' ) . '</p>' );
	}

	/* ---------- in the page ---------- */

	/** Tags for the head of the app: the manifest and icons that let a phone treat it as an app. */
	public static function head() {
		return '<link rel="manifest" href="' . esc_url( add_query_arg( 'sp_manifest', '1', home_url( '/' ) ) ) . '"><link rel="apple-touch-icon" href="' . esc_url( SONGPRESS_URL . 'assets/icon-180.png' ) . '"><meta name="theme-color" content="#0f1012"><meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="SongPress"><meta name="apple-mobile-web-app-status-bar-style" content="black">';
	}

	/** The bell in the menu, and the script that drives it. Hidden where the phone cannot do it and no help would change that. */
	public static function item( $icon ) {
		$k = self::keys();
		if ( ! is_user_logged_in() || ! $k ) {
			return '';
		}
		$cfg = wp_json_encode( array(
			'key'    => $k['pub'],
			'sw'     => add_query_arg( 'sp_sw', '1', home_url( '/' ) ),
			'scope'  => (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?: '/',
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'nonce'  => wp_create_nonce( 'songpress_push' ),
			'on'     => __( 'Notifications on (tap to turn off)', 'songpress' ),
			'off'    => __( 'Turn on notifications', 'songpress' ),
			'ios'    => __( 'To get notifications, tap Share, then Add to Home Screen, and open SongPress from your Home Screen.', 'songpress' ),
			'denied' => __( 'Notifications are blocked. Allow them for SongPress in your phone settings.', 'songpress' ),
			'test'   => __( 'Sent a test. If nothing shows, check your phone settings.', 'songpress' ),
		) );
		return '<a href="#" id="sp-push" hidden>' . $icon . '<span>' . esc_html__( 'Notifications', 'songpress' ) . '</span></a><p id="sp-push-msg" class="spe-help" hidden></p><script>(function(){var C=' . $cfg . ';' . self::script() . '})()</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON and a fixed script.
	}

	private static function script() {
		return <<<'JS'
var b=document.getElementById('sp-push'),m=document.getElementById('sp-push-msg');if(!b)return;
var lab=b.querySelector('span'),ios=/iphone|ipad|ipod/i.test(navigator.userAgent),app=(window.matchMedia&&matchMedia('(display-mode: standalone)').matches)||navigator.standalone;
var ok='serviceWorker' in navigator&&'PushManager' in window&&'Notification' in window;
function say(t){m.textContent=t;m.hidden=!t}
function key(s){var p=(s+'==='.slice((s.length+3)%4)).replace(/-/g,'+').replace(/_/g,'/'),r=atob(p),a=new Uint8Array(r.length);for(var i=0;i<r.length;i++)a[i]=r.charCodeAt(i);return a}
function post(o){var f=new FormData();f.append('action','songpress_push');f.append('nonce',C.nonce);for(var k in o)f.append(k,o[k]);return fetch(C.ajax,{method:'POST',body:f,credentials:'same-origin'}).then(function(r){return r.json()})}
if(!ok){if(ios&&!app){b.hidden=false;b.addEventListener('click',function(e){e.preventDefault();say(C.ios)})}return}
b.hidden=false;
var reg=null;
function show(sub){lab.textContent=sub?C.on:C.off;b.dataset.on=sub?'1':''}
navigator.serviceWorker.register(C.sw,{scope:C.scope}).then(function(){return navigator.serviceWorker.ready}).then(function(r){reg=r;return r.pushManager.getSubscription()}).then(function(s){show(!!s);if(s&&Notification.permission==='granted'){post({mode:'add',endpoint:s.endpoint})}}).catch(function(){b.hidden=true});
b.addEventListener('click',function(e){e.preventDefault();if(!reg)return;
 if(b.dataset.on){reg.pushManager.getSubscription().then(function(s){if(!s)return;var ep=s.endpoint;return s.unsubscribe().then(function(){return post({mode:'remove',endpoint:ep})})}).then(function(){show(false);say('')});return}
 if(Notification.permission==='denied'){say(C.denied);return}
 Notification.requestPermission().then(function(p){
  if(p!=='granted'){say(C.denied);return}
  return reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:key(C.key)}).then(function(s){return post({mode:'add',endpoint:s.endpoint})}).then(function(){show(true);return post({mode:'test'})}).then(function(){say(C.test)});
 }).catch(function(){say(C.denied)});
});
JS;
	}
}
