<?php
/**
 * Getting people in: one-time links a Manager can copy or email. A link opens a page where the person chooses their own password (the same link serves a forgotten password), and bringing across the accounts of the old plugin.
 * A link works once and lapses after a week. Only people who have a SongPress class get one.
 */
defined( 'ABSPATH' ) || exit;

class Songpress_Access {

	const META = 'songpress_link';
	const LIFE = 604800; // seven days
	const ASKS = 'songpress_pw_asks';
	const ROLE = array( 'editor' => 'writer', 'contributor' => 'writer', 'member' => 'viewer' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'forgot' ), 1 );
		add_action( 'init', array( __CLASS__, 'consume' ), 1 );
		foreach ( array( 'link', 'mail', 'oldusers', 'dismiss' ) as $a ) {
			add_action( 'admin_post_songpress_access_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	/* ---------- links ---------- */

	/** Where a link leads: the page the app lives on. */
	public static function app_url() {
		$id = class_exists( 'Songpress_Frontend' ) ? Songpress_Frontend::main_id( 'catalog' ) : 0;
		return $id ? get_permalink( $id ) : home_url( '/desktop/' );
	}

	/** Pure: the text to put in a link for a user and token, and back. */
	public static function pack( $uid, $token ) {
		return (int) $uid . '.' . $token;
	}

	public static function unpack( $s ) {
		if ( ! preg_match( '/^(\d+)\.([A-Za-z0-9]{20,64})$/', (string) $s, $m ) ) {
			return null;
		}
		return array( (int) $m[1], $m[2] );
	}

	/** Pure: does this stored record accept this token now? */
	public static function accepts( $rec, $token, $now ) {
		return is_array( $rec ) && ! empty( $rec['hash'] ) && (int) ( $rec['exp'] ?? 0 ) >= (int) $now && hash_equals( (string) $rec['hash'], hash( 'sha256', (string) $token ) );
	}

	/** Make a fresh link for a user (any earlier one stops working). */
	public static function make_link( $uid ) {
		$token = wp_generate_password( 32, false, false );
		update_user_meta( (int) $uid, self::META, array( 'hash' => hash( 'sha256', $token ), 'exp' => time() + self::LIFE ) );
		return add_query_arg( 'sp_link', self::pack( $uid, $token ), self::app_url() );
	}

	/** The page a link opens: the person chooses their own password. Submitting it sets the password, signs them in and uses the link up. */
	public static function consume() {
		if ( empty( $_REQUEST['sp_link'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- the token in the link is the proof.
			return;
		}
		$raw  = sanitize_text_field( wp_unslash( $_REQUEST['sp_link'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$p    = self::unpack( $raw );
		$user = $p ? get_userdata( $p[0] ) : false;
		$ok   = $user && self::accepts( get_user_meta( $user->ID, self::META, true ), $p[1], time() ) && '' !== Songpress_Caps::class_of( $user->ID );
		$here = remove_query_arg( array( 'sp_link', 'sp_login' ) );
		if ( ! $ok ) {
			wp_safe_redirect( add_query_arg( 'sp_login', 'expired', $here ) );
			exit;
		}
		$err = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['sp_pw'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$pw  = (string) wp_unslash( $_POST['sp_pw'] ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- a password is taken as typed.
			$pw2 = (string) wp_unslash( $_POST['sp_pw2'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			$err = self::check_password( $pw, $pw2 );
			if ( '' === $err ) {
				delete_user_meta( $user->ID, self::META );
				wp_set_password( $pw, $user->ID );
				wp_set_current_user( $user->ID );
				wp_set_auth_cookie( $user->ID, true );
				do_action( 'wp_login', $user->user_login, $user );
				Songpress_Push::alert( __( 'Password set', 'songpress' ), sprintf( /* translators: %s: name */ __( '%s chose a password and is signed in.', 'songpress' ), $user->display_name ), Songpress_Persons::url( array( 'person' => Songpress_Profiles::key( $user->display_name ) ) ) );
				Songpress_Audit::log( 'people', 'Chose a password from a link', $user->display_name, array( 'login' => $user->user_login ), (int) $user->ID );
				wp_safe_redirect( $here );
				exit;
			}
		}
		self::page( $user, $raw, $err );
		exit;
	}

	/** Pure: what is wrong with a chosen password, or ''. */
	public static function check_password( $pw, $pw2 ) {
		if ( strlen( $pw ) < 8 ) {
			return __( 'Use at least 8 characters.', 'songpress' );
		}
		if ( $pw !== $pw2 ) {
			return __( 'The two passwords are not the same.', 'songpress' );
		}
		return '';
	}

	private static function page( $user, $raw, $err ) {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>SongPress</title><link rel="icon" href="' . esc_url( SONGPRESS_URL . 'assets/icon.svg' ) . '" type="image/svg+xml"><link rel="stylesheet" href="' . esc_url( SONGPRESS_URL . 'assets/songpress.css?ver=' . SONGPRESS_VERSION ) . '"></head><body class="sp-body">'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="sp-login"><main><img class="sp-login-logo" src="' . esc_url( SONGPRESS_URL . 'assets/stacked-amber.svg' ) . '" alt="SongPress"><section class="sp-card"><h1>' . esc_html__( 'Choose your password', 'songpress' ) . '</h1><p class="sp-sub">' . esc_html( sprintf( /* translators: 1: name, 2: username */ __( 'Hi %1\$s. Your username is %2\$s. Pick a password you will remember.', 'songpress' ), $user->display_name, $user->user_login ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( '' !== $err ) {
			echo '<p class="sp-err" role="alert">' . esc_html( $err ) . '</p>';
		}
		echo '<form method="post" action="">'
			. '<input type="hidden" name="sp_link" value="' . esc_attr( $raw ) . '">'
			. '<label for="sp-n1">' . esc_html__( 'New password', 'songpress' ) . '</label><input id="sp-n1" name="sp_pw" type="password" autocomplete="new-password" minlength="8" required>'
			. '<label for="sp-n2">' . esc_html__( 'Again', 'songpress' ) . '</label><input id="sp-n2" name="sp_pw2" type="password" autocomplete="new-password" minlength="8" required>'
			. '<button type="submit">' . esc_html__( 'Save and sign in', 'songpress' ) . '</button></form></section></main></div></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function guard( $nonce ) {
		check_admin_referer( $nonce );
		if ( ! Songpress_Caps::can( 'members' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
	}

	private static function back( $uid, $args ) {
		$u = $uid ? get_userdata( (int) $uid ) : false;
		wp_safe_redirect( add_query_arg( $args, Songpress_Persons::url( $u ? array( 'person' => Songpress_Profiles::key( $u->display_name ) ) : array() ) ) );
		exit;
	}

	/** May the signed-in Manager make a link for this user? Not for an administrator unless they are one, not for someone with no class. */
	private static function target( $uid ) {
		$u = $uid ? get_userdata( (int) $uid ) : false;
		if ( ! $u || '' === Songpress_Caps::class_of( $u->ID ) || ( Songpress_Caps::is_wp_admin( $u->ID ) && ! Songpress_Caps::is_wp_admin( get_current_user_id() ) ) ) {
			return null;
		}
		return $u;
	}

	public static function handle_link() {
		self::guard( 'songpress_access_link' );
		$uid = absint( $_POST['uid'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- checked by guard().
		$u   = self::target( $uid );
		if ( ! $u ) {
			self::back( $uid, array( 'sp_err' => rawurlencode( __( 'That person cannot be given a link. They need a login with a class first.', 'songpress' ) ) ) );
		}
		set_transient( 'songpress_links_' . get_current_user_id(), array( 'uid' => $u->ID, 'url' => self::make_link( $u->ID ) ), 15 * MINUTE_IN_SECONDS );
		self::clear_ask( $u->ID );
		Songpress_Audit::log( 'people', 'Made a password link', $u->display_name, array( 'login' => $u->user_login ) );
		self::back( $uid, array( 'sp_saved' => rawurlencode( __( 'Link made. It opens a page where they choose their own password. It works once and lapses in a week.', 'songpress' ) ) ) );
	}

	public static function handle_mail() {
		self::guard( 'songpress_access_mail' );
		$uid = absint( $_POST['uid'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- checked by guard().
		$u   = self::target( $uid );
		if ( ! $u || ! self::real_email( $u->user_email ) ) {
			self::back( $uid, array( 'sp_err' => rawurlencode( __( 'There is no email address on file for them. Copy the link and send it yourself.', 'songpress' ) ) ) );
		}
		$url  = self::make_link( $u->ID );
		$name = get_bloginfo( 'name' );
		/* translators: 1: site name, 2: link, 3: days */
		$body = sprintf( __( "Open this link to choose your password for %1\$s:\n\n%2\$s\n\nIt works once and lapses in %3\$d days. If you did not expect this, ignore it.", 'songpress' ), $name, $url, 7 );
		self::clear_ask( $u->ID );
		$ok   = wp_mail( $u->user_email, sprintf( /* translators: %s: site name */ __( 'Choose your password for %s', 'songpress' ), $name ), $body );
		Songpress_Audit::log( 'people', $ok ? 'Emailed a password link' : 'Could not email a password link', $u->display_name, array( 'login' => $u->user_login ) );
		self::back( $uid, $ok ? array( 'sp_saved' => rawurlencode( sprintf( /* translators: %s: email */ __( 'Link emailed to %s.', 'songpress' ), $u->user_email ) ) ) : array( 'sp_err' => rawurlencode( __( 'WordPress could not send the email. Copy the link and send it yourself.', 'songpress' ) ) ) );
	}

	/** An address that can get mail: not blank and not the made-up one the old plugin gave people with none. */
	public static function real_email( $e ) {
		return is_email( $e ) && false === strpos( (string) $e, '@users.' );
	}

	/** The link just made for someone, once, as a box to copy; plus the buttons for a person who has a login. */
	public static function link_box( $uid ) {
		$u = get_userdata( (int) $uid );
		if ( ! $u ) {
			return '';
		}
		if ( '' === Songpress_Caps::class_of( $u->ID ) ) {
			return '<h3 id="sp-link">' . esc_html__( 'Login link', 'songpress' ) . '</h3><p class="spe-help">' . esc_html__( 'Choose a class for this person in the list first, then come back here to send their login link.', 'songpress' ) . '</p>';
		}
		$o   = '<h3 id="sp-link">' . esc_html__( 'Send a login link', 'songpress' ) . '</h3>';
		$key = 'songpress_links_' . get_current_user_id();
		$got = get_transient( $key );
		if ( is_array( $got ) && (int) $got['uid'] === (int) $u->ID ) {
			delete_transient( $key );
			$first = (string) get_user_meta( $u->ID, 'first_name', true );
			$text  = sprintf( /* translators: 1: first name, 2: link */ __( "Hi %1\$s, here is your personal SongPress link. Open it to choose your password:\n%2\$s", 'songpress' ), '' !== $first ? $first : $u->display_name, $got['url'] );
			$o .= '<p class="spe-help">' . esc_html( sprintf( /* translators: %s: name */ __( 'This link is only for %s. It will not sign in anyone else.', 'songpress' ), $u->display_name ) ) . '</p><div class="spe-invite"><textarea readonly rows="3" onfocus="this.select()">' . esc_textarea( $text ) . '</textarea><button type="button" class="spe-btn spe-copytext">' . esc_html__( 'Copy', 'songpress' ) . '</button></div>';
		}
		$f = function ( $action, $label, $class ) use ( $u ) {
			return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">' . wp_nonce_field( $action, '_wpnonce', true, false ) . '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="uid" value="' . (int) $u->ID . '"><button class="' . esc_attr( $class ) . '" type="submit">' . esc_html( $label ) . '</button></form> ';
		};
		$o .= '<p>' . $f( 'songpress_access_link', __( 'Send a password link', 'songpress' ), 'spe-save' );
		if ( self::real_email( $u->user_email ) ) {
			$o .= $f( 'songpress_access_mail', sprintf( /* translators: %s: email */ __( 'Email it to %s', 'songpress' ), $u->user_email ), 'spe-btn' );
		}
		return $o . '</p><p class="spe-help">' . esc_html__( 'Use this for a new person and for anyone who forgets their password. The link opens a page where they choose their own password, then signs them in. It works once and lapses after a week; a new link cancels the old one.', 'songpress' ) . '</p>';
	}

	/* ---------- "I forgot my password" ---------- */

	/** Pure: requests still worth showing (the last two weeks), newest first. Returns uid => time. */
	public static function asks_pending( $asks, $now ) {
		$out = array();
		foreach ( (array) $asks as $uid => $t ) {
			if ( (int) $t >= (int) $now - 14 * DAY_IN_SECONDS ) {
				$out[ (int) $uid ] = (int) $t;
			}
		}
		arsort( $out );
		return $out;
	}

	public static function asks() {
		return self::asks_pending( get_option( self::ASKS, array() ), time() );
	}

	public static function clear_ask( $uid ) {
		$all = (array) get_option( self::ASKS, array() );
		unset( $all[ (int) $uid ] );
		update_option( self::ASKS, $all, false );
	}

	/** The login page form: someone who is not signed in says who they are; the Managers are told. The answer is the same whether or not the account exists. */
	public static function forgot() {
		if ( is_user_logged_in() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['sp_forgot'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- a public form; it only tells the Managers.
			return;
		}
		$who  = sanitize_text_field( wp_unslash( $_POST['sp_who'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$user = '' === $who ? false : ( get_user_by( 'login', $who ) ?: ( is_email( $who ) ? get_user_by( 'email', $who ) : false ) );
		if ( $user && '' !== Songpress_Caps::class_of( $user->ID ) && ! get_transient( 'songpress_ask_' . $user->ID ) ) {
			set_transient( 'songpress_ask_' . $user->ID, 1, 10 * MINUTE_IN_SECONDS );
			$all                 = (array) get_option( self::ASKS, array() );
			$all[ $user->ID ]    = time();
			update_option( self::ASKS, $all, false );
			Songpress_Push::alert( __( 'Password help', 'songpress' ), sprintf( /* translators: %s: name */ __( '%s forgot their password. Open People to send a link.', 'songpress' ), $user->display_name ), Songpress_Persons::url() );
			Songpress_Audit::log( 'people', 'Asked for a password link', $user->display_name, array( 'login' => $user->user_login ), 0 );
		}
		wp_safe_redirect( add_query_arg( 'sp_login', 'asked', remove_query_arg( array( 'sp_login', 'sp_forgot' ) ) ) );
		exit;
	}

	public static function handle_dismiss() {
		self::guard( 'songpress_access_dismiss' );
		self::clear_ask( absint( $_POST['uid'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked by guard().
		wp_safe_redirect( Songpress_Persons::url() );
		exit;
	}

	/** The box at the top of People: who asked, with the buttons to answer them. */
	public static function asks_box() {
		$rows = '';
		foreach ( self::asks() as $uid => $t ) {
			$u = get_userdata( $uid );
			if ( ! $u ) {
				continue;
			}
			$f = function ( $action, $label, $class ) use ( $uid ) {
				return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">' . wp_nonce_field( $action, '_wpnonce', true, false ) . '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="uid" value="' . (int) $uid . '"><button class="' . esc_attr( $class ) . '" type="submit">' . esc_html( $label ) . '</button></form> ';
			};
			$rows .= '<li><b>' . esc_html( $u->display_name ) . '</b> <small>' . esc_html( sprintf( /* translators: 1: username, 2: how long ago */ __( '%1$s, asked %2$s ago', 'songpress' ), $u->user_login, human_time_diff( $t ) ) ) . '</small> ' . $f( 'songpress_access_link', __( 'Send a password link', 'songpress' ), 'spe-save' ) . ( self::real_email( $u->user_email ) ? $f( 'songpress_access_mail', __( 'Email it', 'songpress' ), 'spe-btn' ) : '' ) . $f( 'songpress_access_dismiss', __( 'Dismiss', 'songpress' ), 'spe-btn' ) . '</li>';
		}
		return '' === $rows ? '' : '<div class="spe-tidy spm-asks"><h3>' . esc_html__( 'Forgot their password', 'songpress' ) . '</h3><ul>' . $rows . '</ul></div>';
	}

	/* ---------- the old plugin's accounts ---------- */

	/**
	 * Pure: what to do with each account from the old plugin. $old rows have username, email, display_name, pass_hash, role, person_name, active.
	 * $have maps a lowercase login or email to array( id, classed ). Returns rows of action ('create', 'class' or 'skip'), plus login, email, name, hash, class, id.
	 */
	public static function plan_accounts( array $old, array $have ) {
		$out  = array();
		$seen = array();
		foreach ( $old as $r ) {
			$login = trim( (string) ( $r['username'] ?? '' ) );
			if ( '' === $login || isset( $seen[ strtolower( $login ) ] ) || empty( $r['active'] ) ) {
				continue;
			}
			$seen[ strtolower( $login ) ] = 1;
			$email = trim( (string) ( $r['email'] ?? '' ) );
			$hit   = $have[ strtolower( $login ) ] ?? ( '' !== $email ? ( $have[ strtolower( $email ) ] ?? null ) : null );
			$row   = array(
				'login' => $login,
				'email' => $email,
				'name'  => trim( (string) ( $r['person_name'] ?? '' ) ) ?: trim( (string) ( $r['display_name'] ?? '' ) ) ?: $login,
				'hash'  => (string) ( $r['pass_hash'] ?? '' ),
				'class' => self::ROLE[ strtolower( (string) ( $r['role'] ?? '' ) ) ] ?? 'viewer',
				'id'    => $hit ? (int) $hit[0] : 0,
			);
			$row['action'] = ! $hit ? 'create' : ( empty( $hit[1] ) ? 'class' : 'skip' );
			$out[]         = $row;
		}
		return $out;
	}

	private static function plan() {
		global $wpdb;
		$have = array();
		foreach ( (array) $wpdb->get_results( "SELECT ID, user_login, user_email FROM {$wpdb->users}" ) as $u ) { // phpcs:ignore WordPress.DB
			$classed = '' !== Songpress_Caps::class_of( (int) $u->ID );
			$have[ strtolower( $u->user_login ) ] = array( (int) $u->ID, $classed );
			if ( '' !== $u->user_email ) {
				$have[ strtolower( $u->user_email ) ] = array( (int) $u->ID, $classed );
			}
		}
		return self::plan_accounts( Songpress_Legacy::stored_rows( 'catalog_users' ), $have );
	}

	/** A box for the People screen when old accounts are waiting. */
	public static function old_box() {
		$todo = array_filter( self::plan(), function ( $p ) {
			return 'skip' !== $p['action'];
		} );
		if ( ! $todo ) {
			return '';
		}
		$mk = count( array_filter( $todo, function ( $p ) {
			return 'create' === $p['action'];
		} ) );
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="spe-tidy">' . wp_nonce_field( 'songpress_access_oldusers', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_access_oldusers"><p class="spe-help">'
			. esc_html( sprintf( /* translators: 1: accounts waiting, 2: of them new */ _n( '%1$d person from the old plugin cannot sign in yet (%2$d need a new login).', '%1$d people from the old plugin cannot sign in yet (%2$d need a new login).', count( $todo ), 'songpress' ), count( $todo ), $mk ) )
			. ' ' . esc_html__( 'Bring them across with the same username and password. Writers and contributors become Writers, members become Viewers.', 'songpress' ) . '</p><button class="spe-save" type="submit">' . esc_html__( 'Bring old accounts across', 'songpress' ) . '</button></form>';
	}

	public static function handle_oldusers() {
		self::guard( 'songpress_access_oldusers' );
		global $wpdb;
		$made = $set = 0;
		foreach ( self::plan() as $p ) {
			if ( 'skip' === $p['action'] ) {
				continue;
			}
			$id = (int) $p['id'];
			if ( 'create' === $p['action'] ) {
				$email = ( '' !== $p['email'] && is_email( $p['email'] ) && ! email_exists( $p['email'] ) ) ? $p['email'] : '';
				$id    = wp_insert_user( array( 'user_login' => $p['login'], 'user_pass' => wp_generate_password( 24 ), 'user_email' => $email, 'display_name' => $p['name'], 'role' => 'subscriber' ) );
				if ( is_wp_error( $id ) ) {
					continue;
				}
				list( $f, $l ) = Songpress_Persons::split_name( $p['name'] );
				update_user_meta( $id, 'first_name', $f );
				update_user_meta( $id, 'last_name', $l );
				$made++;
			}
			// the old password keeps working: WordPress checks bcrypt hashes as they are and upgrades them on first sign-in
			if ( 'create' === $p['action'] && preg_match( '/^\$2[aby]\$\d\d\$/', $p['hash'] ) ) {
				$wpdb->update( $wpdb->users, array( 'user_pass' => $p['hash'] ), array( 'ID' => $id ) );
				clean_user_cache( $id );
			}
			update_user_meta( $id, Songpress_Caps::META, $p['class'] );
			Songpress_Caps::forget( $id );
			$set++;
		}
		Songpress_Audit::log( 'people', 'Brought old accounts across', '', array( 'new logins' => $made, 'given a class' => $set ) );
		wp_safe_redirect( add_query_arg( 'sp_saved', rawurlencode( sprintf( /* translators: 1: new logins, 2: given access */ __( 'Done: %1$d new logins made, %2$d people given access.', 'songpress' ), $made, $set ) ), Songpress_Persons::url() ) );
		exit;
	}
}
