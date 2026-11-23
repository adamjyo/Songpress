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
	const LABELS  = 'songpress_push_labels';
	public static $names = array();
	public static $report = array();
	const NOTICES = 'songpress_notices';
	const ALERTS  = 'songpress_alert_users';

	/** What the push services answered on the last send: code per phone (0 = no answer). For the test buttons. */
	public static $last = array();

	public static function init() {
		add_action( 'init', array( __CLASS__, 'serve' ), 2 );
		add_action( 'wp_ajax_songpress_push', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_post_songpress_push_send', array( __CLASS__, 'handle_send' ) );
		add_action( 'admin_post_songpress_push_alerts', array( __CLASS__, 'handle_alerts' ) );
		add_action( 'admin_post_songpress_push_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_songpress_push_done', array( __CLASS__, 'handle_done' ) );
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

	/** A short name for a device from its browser string, e.g. "iPhone, Home Screen app". */
	public static function label_for( $ua, $app ) {
		$ua = (string) $ua;
		if ( preg_match( '/iPhone/i', $ua ) ) {
			$d = 'iPhone';
		} elseif ( preg_match( '/iPad/i', $ua ) ) {
			$d = 'iPad';
		} elseif ( preg_match( '/Android/i', $ua ) ) {
			$d = 'Android';
		} elseif ( preg_match( '/Macintosh/i', $ua ) ) {
			$d = 'Mac';
		} elseif ( preg_match( '/Windows/i', $ua ) ) {
			$d = 'Windows';
		} else {
			$d = 'Computer';
		}
		$b = preg_match( '/Edg\//', $ua ) ? 'Edge' : ( preg_match( '/Firefox/', $ua ) ? 'Firefox' : ( preg_match( '/Chrome|CriOS/', $ua ) ? 'Chrome' : ( preg_match( '/Safari/', $ua ) ? 'Safari' : '' ) ) );
		return $d . ( $app ? ', Home Screen app' : ( '' !== $b ? ', ' . $b : '' ) );
	}

	public static function label_of( $uid, $ep ) {
		$l = get_user_meta( (int) $uid, self::LABELS, true );
		$k = md5( $ep );
		return is_array( $l ) && ! empty( $l[ $k ] ) ? (string) $l[ $k ] : __( 'a device', 'songpress' );
	}

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
		$reached    = 0;
		self::$last = array();
		self::$names  = array();
		self::$report = array();
		$keep       = array();
		foreach ( self::devices( $uid ) as $ep ) {
			$jwt = self::jwt( $ep );
			$k   = self::keys();
			if ( '' === $jwt || ! $k ) {
				$keep[] = $ep;
				continue;
			}
			$r    = wp_remote_post( $ep, array( 'timeout' => 6, 'body' => '', 'headers' => array( 'Authorization' => 'vapid t=' . $jwt . ', k=' . $k['pub'], 'TTL' => '86400', 'Urgency' => 'normal' ) ) );
			$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			self::$last[] = $code;
			$why          = $code >= 200 && $code < 300 ? '' : ' ' . trim( wp_strip_all_tags( is_wp_error( $r ) ? $r->get_error_message() : substr( (string) wp_remote_retrieve_body( $r ), 0, 120 ) ) );
			self::$report[] = self::label_of( $uid, $ep ) . ' = ' . ( $code ? $code : 'no answer' ) . $why . ( ( 404 === $code || 410 === $code ) ? ' (removed)' : '' );
			if ( 404 === $code || 410 === $code ) {
				continue; // the phone dropped it
			}
			$keep[] = $ep;
			if ( $code >= 200 && $code < 300 ) {
				$reached++;
				self::$names[] = self::label_of( $uid, $ep );
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
			$lab        = get_user_meta( $uid, self::LABELS, true );
			$lab        = is_array( $lab ) ? $lab : array();
			$lab[ md5( $ep ) ] = self::label_for( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '', ! empty( $_POST['app'] ) );
			update_user_meta( $uid, self::LABELS, $lab );
			wp_send_json_success();
		}
		if ( 'remove' === $mode ) {
			update_user_meta( $uid, self::SUBS, array_values( array_diff( $subs, array( $ep ) ) ) );
			wp_send_json_success();
		}
		if ( 'testme' === $mode || 'testalerts' === $mode ) {
			if ( ! Songpress_Caps::can( 'members' ) ) {
				wp_send_json_error( 'no access', 403 );
			}
			$r = self::run_test( 'testme' === $mode );
			wp_send_json_success( array( 'ok' => $r[0], 'msg' => $r[1] ) );
		}
		if ( 'done' === $mode ) {
			self::mark_done( $uid, sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) ) );
			wp_send_json_success( array( 'count' => self::unread( $uid ) ) );
		}
		if ( 'count' === $mode ) {
			wp_send_json_success( array( 'count' => self::unread( $uid ) ) );
		}
		if ( 'test' === $mode ) {
			wp_send_json_success( array( 'reached' => self::notify( $uid, 'SongPress', __( 'Notifications are on. This is a test.', 'songpress' ) ) ) );
		}
		wp_send_json_error( 'bad request', 400 );
	}

	/* ---------- the Notifications section: what has come in, and what is dealt with ---------- */

	public static function inbox( $uid ) {
		$l = get_user_meta( (int) $uid, self::NOTICES, true );
		return is_array( $l ) ? array_reverse( $l ) : array();
	}

	/** How many notifications this person has not marked done. */
	public static function unread( $uid ) {
		$n = 0;
		foreach ( self::inbox( $uid ) as $x ) {
			if ( empty( $x['done'] ) ) {
				$n++;
			}
		}
		return $n;
	}

	public static function inbox_html() {
		$uid  = get_current_user_id();
		$list = self::inbox( $uid );
		$post = esc_url( admin_url( 'admin-post.php' ) );
		$o    = '<div class="sp-notes"><h2>' . esc_html__( 'Notifications', 'songpress' ) . '</h2>';
		if ( ! $list ) {
			return $o . '<p class="songpress-note">' . esc_html__( 'Nothing yet. Password requests, payments and other alerts show up here, and on your phone if you turned notifications on.', 'songpress' ) . '</p></div>';
		}
		if ( self::unread( $uid ) ) {
			$o .= '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="songpress_push_done"><input type="hidden" name="id" value="all">' . wp_nonce_field( 'songpress_push_done', '_wpnonce', true, false ) . '<button class="button">' . esc_html__( 'Mark all done', 'songpress' ) . '</button></form>';
		}
		$o .= '<ul class="sp-notelist">';
		foreach ( $list as $n ) {
			$done = ! empty( $n['done'] );
			$o   .= '<li class="' . ( $done ? 'done' : 'new' ) . '"><div><b>' . esc_html( (string) ( $n['title'] ?? '' ) ) . '</b> <small>' . esc_html( human_time_diff( (int) ( $n['t'] ?? time() ) ) . ' ' . __( 'ago', 'songpress' ) ) . '</small><br>' . esc_html( (string) ( $n['body'] ?? '' ) ) . '</div>';
			if ( ! $done ) {
				$o .= '<form method="post" action="' . $post . '"><input type="hidden" name="action" value="songpress_push_done"><input type="hidden" name="id" value="' . esc_attr( (string) ( $n['id'] ?? '' ) ) . '">' . wp_nonce_field( 'songpress_push_done', '_wpnonce', true, false ) . '<button class="button">' . esc_html__( 'Done', 'songpress' ) . '</button></form>';
			}
			$o .= '</li>';
		}
		$cfg = wp_json_encode( array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'songpress_push' ) ) );
		return $o . '</ul><script>(function(){var C=' . $cfg . ',box=document.querySelector(".sp-notes");if(!box)return;box.addEventListener("submit",function(e){var f=e.target;if(!f.querySelector("[name=id]"))return;e.preventDefault();var id=f.querySelector("[name=id]").value,fd=new FormData();fd.append("action","songpress_push");fd.append("nonce",C.nonce);fd.append("mode","done");fd.append("id",id);fetch(C.ajax,{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json()}).then(function(j){if(!j||!j.success){f.submit();return}box.querySelectorAll("li.new").forEach(function(li){var x=li.querySelector("[name=id]");if(id==="all"||(x&&x.value===id)){li.className="done";var b=li.querySelector("form");if(b)b.remove()}});if(id==="all"||!j.data.count){var all=box.querySelector("[value=all]");if(all)all.closest("form").remove()}var a=document.querySelector(\'.sp-side a[href*="sp_page=notifications"]\');if(a){var bd=a.querySelector(".sp-badge");if(j.data.count>0){if(!bd){bd=document.createElement("span");bd.className="sp-badge";a.appendChild(bd)}bd.textContent=j.data.count}else if(bd){bd.remove()}}}).catch(function(){f.submit()})})})()</script></div>';
	}

	public static function mark_done( $uid, $id ) {
		$list = get_user_meta( $uid, self::NOTICES, true );
		$list = is_array( $list ) ? $list : array();
		foreach ( $list as $i => $n ) {
			if ( 'all' === $id || ( $n['id'] ?? '' ) === $id ) {
				$list[ $i ]['done'] = 1;
			}
		}
		update_user_meta( $uid, self::NOTICES, $list );
	}

	public static function handle_done() {
		check_admin_referer( 'songpress_push_done' );
		self::mark_done( get_current_user_id(), isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '' );
		wp_safe_redirect( add_query_arg( 'sp_page', 'notifications', Songpress_Editor::list_url() ) );
		exit;
	}

	/** The rail asks every half minute: keeps the Notifications badge live while a desktop page stays open. */
	public static function badge_script() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$cfg = wp_json_encode( array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'songpress_push' ) ) );
		return '<script>(function(){var C=' . $cfg . ',a=document.querySelector(\'.sp-side a[href*="sp_page=notifications"]\');if(!a)return;function put(n){var b=a.querySelector(".sp-badge");if(n>0){if(!b){b=document.createElement("span");b.className="sp-badge";a.appendChild(b)}b.textContent=n}else if(b){b.remove()}document.title=(n>0?"("+n+") ":"")+document.title.replace(/^\\(\\d+\\) /,"")}function poll(){if(document.hidden)return;var f=new FormData();f.append("action","songpress_push");f.append("nonce",C.nonce);f.append("mode","count");fetch(C.ajax,{method:"POST",body:f,credentials:"same-origin"}).then(function(r){return r.json()}).then(function(j){if(j&&j.data&&typeof j.data.count==="number")put(j.data.count)}).catch(function(){})}setInterval(poll,30000);document.addEventListener("visibilitychange",poll)})()</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON and a fixed script.
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

	/** Sends the test and says what happened. Returns array( ok, message ). */
	public static function run_test( $me ) {
		$n    = $me ? self::notify( get_current_user_id(), __( 'SongPress test', 'songpress' ), __( 'Notifications are working on this phone.', 'songpress' ) ) : self::alert( __( 'SongPress alert', 'songpress' ), __( 'This is a test alert from the site.', 'songpress' ) );
		if ( $n ) {
			/* translators: %s: device names */
			$msg = sprintf( __( 'Test sent. It reached: %s. If nothing shows on that device, check its notification settings for SongPress, and that Focus or Do Not Disturb is off.', 'songpress' ), implode( '; ', array_unique( self::$names ) ) ) . ' [' . implode( ' | ', self::$report ) . ']';
		} elseif ( ! self::keys() ) {
			$msg = __( 'This server cannot make the signing key (PHP OpenSSL is missing), so notifications cannot be sent.', 'songpress' );
		} elseif ( ! self::$last ) {
			$msg = $me ? __( 'You have not turned notifications on for a phone yet. On the phone, open SongPress from the Home Screen and tap the bell in the menu.', 'songpress' ) : __( 'Nobody chosen for alerts has turned notifications on yet. On their phone they open SongPress from the Home Screen and tap the bell in the menu.', 'songpress' );
		} else {
			$msg = sprintf( /* translators: %s: codes */ __( 'The phone’s push service did not accept it (answers: %s). Turn notifications off and on again with the bell on the phone, then retry.', 'songpress' ) . ' [' . implode( ' | ', self::$report ) . ']', implode( ', ', array_map( function ( $c ) {
				return $c ? (string) $c : __( 'no answer', 'songpress' );
			}, self::$last ) ) );
		}
		return array( (bool) $n, $msg );
	}

	public static function handle_test() {
		check_admin_referer( 'songpress_push_test' );
		self::guard_people();
		$me   = isset( $_POST['who'] ) && 'me' === $_POST['who']; // phpcs:ignore WordPress.Security.NonceVerification -- checked above.
		$back = isset( $_POST['back'] ) && 'settings' === $_POST['back'] ? Songpress_Screens::url( 'settings' ) : Songpress_Persons::url(); // phpcs:ignore WordPress.Security.NonceVerification
		$r    = self::run_test( $me );
		wp_safe_redirect( add_query_arg( $r[0] ? 'sp_saved' : 'sp_err', rawurlencode( $r[1] ), $back ) );
		exit;
	}

	/** Makes the test buttons work in place: no page load, the answer shows beside the button. Printed once per page. */
	public static function test_script() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		$cfg  = wp_json_encode( array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'songpress_push' ), 'busy' => __( 'Sending…', 'songpress' ) ) );
		return '<script>(function(){var C=' . $cfg . ';document.addEventListener("submit",function(e){var f=e.target,a=f.querySelector&&f.querySelector("[name=action][value=songpress_push_test]");if(!a)return;e.preventDefault();var w=f.querySelector("[name=who]"),m=document.getElementById("sp-testmsg");if(!m){m=document.createElement("p");m.id="sp-testmsg";m.className="spe-note";m.setAttribute("role","status");f.parentNode.parentNode.insertBefore(m,f.parentNode.nextSibling)}var b=f.querySelector("button");m.className="spe-note";m.textContent=C.busy;if(b)b.disabled=true;var d=new FormData();d.append("action","songpress_push");d.append("nonce",C.nonce);d.append("mode",w&&w.value==="me"?"testme":"testalerts");fetch(C.ajax,{method:"POST",body:d,credentials:"same-origin"}).then(function(r){return r.json()}).then(function(j){m.className="spe-note "+(j&&j.success&&j.data.ok?"ok":"bad");m.textContent=j&&j.data&&j.data.msg?j.data.msg:"Could not send the test."}).catch(function(){m.className="spe-note bad";m.textContent="Could not reach the site."}).then(function(){if(b)b.disabled=false})})})()</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON and a fixed script.
	}

	/** Settings: two buttons to check notifications, and what this site knows about them. */
	public static function test_box() {
		$mine = count( self::devices( get_current_user_id() ) );
		$on   = 0;
		foreach ( self::alert_ids() as $id ) {
			$on += count( self::devices( $id ) );
		}
		$f = function ( $who, $label, $class ) {
			return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">' . wp_nonce_field( 'songpress_push_test', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_push_test"><input type="hidden" name="back" value="settings"><input type="hidden" name="who" value="' . esc_attr( $who ) . '"><button class="' . esc_attr( $class ) . '" type="submit">' . esc_html( $label ) . '</button></form> ';
		};
		$note = '';
		if ( isset( $_GET['sp_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$note = '<p class="spe-note ok">' . esc_html( sanitize_text_field( wp_unslash( $_GET['sp_saved'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		} elseif ( isset( $_GET['sp_err'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$note = '<p class="spe-note bad" role="alert">' . esc_html( sanitize_text_field( wp_unslash( $_GET['sp_err'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		return '<div class="sp-app spm-testbox"><h3>' . esc_html__( 'Notifications', 'songpress' ) . '</h3>' . $note . '<p class="spe-help">'
			. esc_html( sprintf( /* translators: 1: phones on for this account, 2: phones that get alerts */ __( 'Signing key: %1$s. Phones on for your account: %2$d. Phones that get alerts: %3$d.', 'songpress' ), self::keys() ? __( 'ready', 'songpress' ) : __( 'missing (PHP OpenSSL)', 'songpress' ), $mine, $on ) ) . '</p><p>'
			. $f( 'me', __( 'Send a test to my phone', 'songpress' ), 'spe-save' ) . $f( 'alerts', __( 'Send a test alert', 'songpress' ), 'spe-btn' ) . '</p><p class="spe-help">' . esc_html__( 'Choose who gets alerts, and message people, under People. To turn notifications on for a phone, open SongPress from its Home Screen and tap the bell in the menu.', 'songpress' ) . '</p></div>' . self::test_script();
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
		return $o . self::test_script();
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

	/** A bar at the top of the app in a phone browser: add SongPress to the Home Screen. Hidden once it runs as a Home Screen app. */
	public static function install_banner() {
		if ( ! is_user_logged_in() || ! wp_is_mobile() ) {
			return '';
		}
		$cfg = wp_json_encode( array(
			'ios'     => array(
				__( 'Add SongPress to your Home Screen', 'songpress' ),
				__( '1. In Safari, tap the menu icon at the left end of the address bar (the three lines, or three dots in some layouts). On older iPhones, tap the Share button at the bottom instead.', 'songpress' ),
				__( '2. Tap Share, then scroll down and tap Add to Home Screen. If you do not see it, tap View More.', 'songpress' ),
				__( '3. Keep Open as Web App switched on, then tap Add.', 'songpress' ),
				__( '4. Open SongPress from the new icon on your Home Screen.', 'songpress' ),
			),
			'android' => array(
				__( 'Add SongPress to your Home Screen', 'songpress' ),
				__( '1. Open the browser menu (the three dots).', 'songpress' ),
				__( '2. Tap Add to Home screen or Install app, then confirm.', 'songpress' ),
				__( '3. Open SongPress from the new icon.', 'songpress' ),
			),
			'ok'      => __( 'OK', 'songpress' ),
		) );
		return '<div id="sp-install" hidden><span class="sp-install-t"><b>' . esc_html__( 'Add SongPress to your Home Screen', 'songpress' ) . '</b><small>' . esc_html__( 'Opens like an app, and lets you get notifications.', 'songpress' ) . '</small></span><button type="button" class="sp-install-go">' . esc_html__( 'Add', 'songpress' ) . '</button><button type="button" class="sp-install-x" aria-label="' . esc_attr__( 'Not now', 'songpress' ) . '">&times;</button></div><script>(function(){var C=' . $cfg . ',el=document.getElementById("sp-install");if(!el)return;var app=(window.matchMedia&&matchMedia("(display-mode: standalone)").matches)||navigator.standalone,K="sp-install-hide";if(app)return;try{var t=+localStorage.getItem(K);if(t&&Date.now()-t<6048e5)return}catch(e){}var ios=/iphone|ipad|ipod/i.test(navigator.userAgent),dp=null;window.addEventListener("beforeinstallprompt",function(e){e.preventDefault();dp=e});window.addEventListener("appinstalled",function(){el.hidden=true});el.hidden=false;function sheet(L){var d=document.createElement("div");d.style.cssText="position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.6);display:flex;align-items:flex-end;justify-content:center;padding:16px";var c=document.createElement("div");c.style.cssText="background:#1b1e24;color:#f2f3f5;max-width:420px;width:100%;border-radius:14px;padding:18px 18px 14px;font:15px/1.45 -apple-system,system-ui,sans-serif";L.forEach(function(x,i){var p=document.createElement(i?"p":"h3");p.textContent=x;p.style.cssText=i?"margin:8px 0":"margin:0 0 6px;font-size:17px";c.appendChild(p)});var o=document.createElement("button");o.type="button";o.textContent=C.ok;o.style.cssText="margin-top:10px;width:100%;padding:12px;border:0;border-radius:10px;background:#f5a524;color:#111;font-weight:600;font-size:16px";o.onclick=function(){d.remove()};c.appendChild(o);d.appendChild(c);d.addEventListener("click",function(e){if(e.target===d)d.remove()});document.body.appendChild(d)}el.querySelector(".sp-install-go").onclick=function(){if(dp){dp.prompt();dp=null;return}sheet(ios?C.ios:C.android)};el.querySelector(".sp-install-x").onclick=function(){el.hidden=true;try{localStorage.setItem(K,Date.now())}catch(e){}}})()</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON and a fixed script.
	}

	/** The bell beside the person's name: bell with a slash when off, solid amber when on. Markup only; script_tag() drives every bell on the page. */
	public static function item() {
		if ( ! is_user_logged_in() || ! self::keys() ) {
			return '';
		}
		$svg  = function ( $d ) {
			return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
		};
		$bell = 'M6 8a6 6 0 0112 0c0 7 3 8 3 8H3s3-1 3-8M10 20a2 2 0 004 0';
		return '<button type="button" class="sp-bell" hidden title="' . esc_attr__( 'Notifications', 'songpress' ) . '" aria-label="' . esc_attr__( 'Notifications', 'songpress' ) . '"><span class="b-off">' . $svg( '<path d="' . $bell . '"/><path d="M3 3l18 18"/>' ) . '</span><span class="b-on">' . $svg( '<path d="' . $bell . '" fill="currentColor"/>' ) . '</span></button><p class="sp-bell-msg" hidden></p>';
	}

	/** The script for the bells on the page. Printed once, after the page. */
	public static function script_tag() {
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
			'on'     => __( 'Notifications are on. Tap to turn them off.', 'songpress' ),
			'off'    => __( 'Notifications are off. Tap to turn them on.', 'songpress' ),
			'ok'     => __( 'OK', 'songpress' ),
			'ios'    => array(
				__( 'Add SongPress to your Home Screen first', 'songpress' ),
				__( 'iPhone only allows notifications for apps on the Home Screen.', 'songpress' ),
				__( '1. In Safari, tap the menu icon at the left end of the address bar (the three lines, or three dots in some layouts). On older iPhones, tap the Share button at the bottom instead.', 'songpress' ),
				__( '2. Tap Share, then scroll down and tap Add to Home Screen. If you do not see it, tap View More.', 'songpress' ),
				__( '3. Keep Open as Web App switched on, then tap Add.', 'songpress' ),
				__( '4. Close Safari and open SongPress from its new Home Screen icon.', 'songpress' ),
				__( '5. Tap the bell and choose Allow. SongPress then appears in Settings > Notifications.', 'songpress' ),
			),
			'denied' => wp_is_mobile() ? array(
				__( 'Notifications are blocked', 'songpress' ),
				__( 'iPhone: open Settings > Notifications > SongPress and turn on Allow Notifications. If SongPress is not in that list, delete the Home Screen icon, add SongPress to the Home Screen again, open it and tap the bell.', 'songpress' ),
				__( 'Android: open Chrome, tap the menu > Settings > Site settings > Notifications, and allow this site.', 'songpress' ),
			) : array(
				__( 'Notifications are blocked in this browser', 'songpress' ),
				__( 'Click the lock or site-settings icon beside the address (in Safari: Safari menu > Settings > Websites > Notifications), set this site to Allow, then reload and click the bell again.', 'songpress' ),
			),
			'fail'   => __( 'Could not turn notifications on:', 'songpress' ),
			'test'   => array(
				__( 'Notifications are on', 'songpress' ),
				__( 'A test was sent. If nothing appeared, check Settings > Notifications > SongPress (iPhone) or the site settings in Chrome (Android), and allow banners and sounds.', 'songpress' ),
			),
		) );
		return '<script>(function(){var C=' . $cfg . ';' . self::script() . '})()</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON and a fixed script.
	}

	private static function script() {
		return <<<'JS'
var bells=Array.prototype.slice.call(document.querySelectorAll('.sp-bell'));if(!bells.length)return;
var ios=/iphone|ipad|ipod/i.test(navigator.userAgent),app=(window.matchMedia&&matchMedia('(display-mode: standalone)').matches)||navigator.standalone;
var ok='serviceWorker' in navigator&&'PushManager' in window&&'Notification' in window;
function say(t){var old=document.getElementById('sp-sheet');if(old)old.remove();if(!t)return;var L=Array.isArray(t)?t:[t],d=document.createElement('div');d.id='sp-sheet';d.setAttribute('role','dialog');d.style.cssText='position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.6);display:flex;align-items:flex-end;justify-content:center;padding:16px';var c=document.createElement('div');c.style.cssText='background:#1b1e24;color:#f2f3f5;max-width:420px;width:100%;border-radius:14px;padding:18px 18px 14px;font:15px/1.45 -apple-system,system-ui,sans-serif;box-shadow:0 10px 40px rgba(0,0,0,.5)';L.forEach(function(x,i){var p=document.createElement(i?'p':'h3');p.textContent=x;p.style.cssText=i?'margin:8px 0':'margin:0 0 6px;font-size:17px';c.appendChild(p)});var o=document.createElement('button');o.type='button';o.textContent=C.ok;o.style.cssText='margin-top:10px;width:100%;padding:12px;border:0;border-radius:10px;background:#f5a524;color:#111;font-weight:600;font-size:16px';o.onclick=function(){d.remove()};c.appendChild(o);d.appendChild(c);d.addEventListener('click',function(e){if(e.target===d)d.remove()});document.body.appendChild(d)}
function key(s){var p=(s+'==='.slice((s.length+3)%4)).replace(/-/g,'+').replace(/_/g,'/'),r=atob(p),a=new Uint8Array(r.length);for(var i=0;i<r.length;i++)a[i]=r.charCodeAt(i);return a}
function post(o){var f=new FormData();f.append('action','songpress_push');f.append('nonce',C.nonce);for(var k in o)f.append(k,o[k]);return fetch(C.ajax,{method:'POST',body:f,credentials:'same-origin'}).then(function(r){return r.json()})}
if(!ok){if(ios&&!app){bells.forEach(function(b){b.hidden=false;b.addEventListener('click',function(){say(C.ios)})})}return}
bells.forEach(function(b){b.hidden=false});
var reg=null;
function show(sub){var t=sub?C.on:C.off;bells.forEach(function(b){b.title=t;b.setAttribute('aria-label',t);b.dataset.on=sub?'1':'0'})}
navigator.serviceWorker.register(C.sw,{scope:C.scope}).then(function(){return navigator.serviceWorker.ready}).then(function(r){reg=r;return r.pushManager.getSubscription()}).then(function(s){show(!!s);if(s&&Notification.permission==='granted'){post({mode:'add',endpoint:s.endpoint,app:app?1:0})}}).catch(function(){bells.forEach(function(b){b.hidden=true})});
bells.forEach(function(b){b.addEventListener('click',function(e){e.preventDefault();if(!reg)return;
 if(b.dataset.on==='1'){reg.pushManager.getSubscription().then(function(s){if(!s)return;var ep=s.endpoint;return s.unsubscribe().then(function(){return post({mode:'remove',endpoint:ep})})}).then(function(){show(false);say('')});return}
 if(Notification.permission==='denied'){say(C.denied);return}
 Notification.requestPermission().then(function(p){
  if(p!=='granted'){say(C.denied);return}
  return reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:key(C.key)}).then(function(s){return post({mode:'add',endpoint:s.endpoint,app:app?1:0})}).then(function(){show(true);return post({mode:'test'})}).then(function(){say(C.test)});
 }).catch(function(e){say([C.fail,(e&&e.message?e.message:'')])});
})});
JS;
	}
}
