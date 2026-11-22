<?php
defined( 'ABSPATH' ) || exit;

/**
 * Members, inside the SongPress app. No email anywhere: a Manager creates the login, sets the password
 * and shares a link. Logins are firstname.lastname. SongPress never sends email to members.
 */
class Songpress_Members {

	public static function init() {
		add_action( 'admin_post_songpress_member_add', array( __CLASS__, 'handle_add' ) );
		add_action( 'admin_post_songpress_member_update', array( __CLASS__, 'handle_update' ) );
	}

	public static function url() {
		return Songpress_Persons::url();
	}

	/** The address a new person opens: the app's login screen with their username filled in. */
	public static function invite_link( $login ) {
		$id   = Songpress_Frontend::main_id( 'catalog' );
		$base = $id ? get_permalink( $id ) : home_url( '/' );
		return add_query_arg( 'sp_user', rawurlencode( $login ), $base );
	}

	private static function stash( array $items ) {
		set_transient( 'songpress_invites_' . get_current_user_id(), $items, 15 * MINUTE_IN_SECONDS );
	}

	private static function go( $args = array() ) {
		wp_safe_redirect( add_query_arg( $args, self::url() ) );
		exit;
	}

	private static function guard( $nonce ) {
		check_admin_referer( $nonce );
		if ( ! Songpress_Caps::can( 'members' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
	}

	private static function make_login( $first, $last ) {
		$base  = sanitize_user( strtolower( trim( $first ) . '.' . trim( $last ) ), true );
		$base  = preg_replace( '/\s+/', '', (string) $base );
		$login = $base;
		for ( $i = 2; '' !== $login && username_exists( $login ); $i++ ) {
			$login = $base . $i;
		}
		return $login;
	}

	/** Never let WordPress email anyone while SongPress creates or changes a login. */
	private static function quiet( $on ) {
		foreach ( array( 'wp_send_new_user_notification_to_user', 'wp_send_new_user_notification_to_admin', 'send_password_change_email', 'send_email_change_email' ) as $f ) {
			if ( $on ) {
				add_filter( $f, '__return_false', 99 );
			} else {
				remove_filter( $f, '__return_false', 99 );
			}
		}
	}

	public static function handle_add() {
		self::guard( 'songpress_member_add' );
		$first = sanitize_text_field( wp_unslash( $_POST['first'] ?? '' ) );
		$last  = sanitize_text_field( wp_unslash( $_POST['last'] ?? '' ) );
		$class = sanitize_key( $_POST['class_new'] ?? '' );
		$pass  = (string) wp_unslash( $_POST['pass'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- a password is stored hashed, never echoed back except to the Manager who typed it.
		if ( '' === $first || '' === $last ) {
			self::go( array( 'sp_err' => rawurlencode( __( 'Enter a first and last name.', 'songpress' ) ) ) );
		}
		if ( ! isset( Songpress_Caps::assignable()[ $class ] ) ) {
			self::go( array( 'sp_err' => rawurlencode( __( 'Choose a class.', 'songpress' ) ) ) );
		}
		if ( '' === $pass ) {
			$pass = wp_generate_password( 12, false );
		} elseif ( strlen( $pass ) < 8 ) {
			self::go( array( 'sp_err' => rawurlencode( __( 'A password needs at least 8 characters.', 'songpress' ) ) ) );
		}
		$login = self::make_login( $first, $last );
		if ( '' === $login ) {
			self::go( array( 'sp_err' => rawurlencode( __( 'That name cannot be turned into a username.', 'songpress' ) ) ) );
		}
		self::quiet( true );
		$uid = wp_insert_user( array( 'user_login' => $login, 'user_pass' => $pass, 'display_name' => $first . ' ' . $last, 'first_name' => $first, 'last_name' => $last, 'role' => 'subscriber' ) );
		self::quiet( false );
		if ( is_wp_error( $uid ) ) {
			self::go( array( 'sp_err' => rawurlencode( $uid->get_error_message() ) ) );
		}
		Songpress_Caps::assign( $uid, $class );
		Songpress_Profiles::upsert( 'person', $first . ' ' . $last, array() );
		self::stash( array( array( 'name' => $first . ' ' . $last, 'login' => $login, 'pass' => $pass ) ) );
		self::go( array( 'sp_saved' => rawurlencode( __( 'Login created. Copy the invitation below and send it to them.', 'songpress' ) ) ) );
	}

	public static function handle_update() {
		self::guard( 'songpress_member_update' );
		$valid   = array_keys( Songpress_Caps::assignable() );
		$classes = isset( $_POST['class'] ) && is_array( $_POST['class'] ) ? wp_unslash( $_POST['class'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked against the class list below.
		$passes  = isset( $_POST['pw'] ) && is_array( $_POST['pw'] ) ? wp_unslash( $_POST['pw'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- stored hashed.
		$n       = 0;
		$items   = array();
		$short   = false;
		foreach ( $classes as $uid => $class ) {
			$uid   = absint( $uid );
			$class = sanitize_key( $class );
			$u     = $uid ? get_userdata( $uid ) : false;
			if ( ! $u || Songpress_Caps::is_wp_admin( $uid ) || ( '' !== $class && ! in_array( $class, $valid, true ) ) ) {
				continue;
			}
			if ( Songpress_Caps::class_of( $uid ) !== $class ) {
				Songpress_Caps::assign( $uid, $class );
				++$n;
			}
			$pw = (string) ( $passes[ $uid ] ?? '' );
			if ( '' !== $pw && '' !== $class ) {
				if ( strlen( $pw ) < 8 ) {
					$short = true;
					continue;
				}
				self::quiet( true );
				wp_set_password( $pw, $uid );
				self::quiet( false );
				$items[] = array( 'name' => $u->display_name, 'login' => $u->user_login, 'pass' => $pw );
				++$n;
			}
		}
		if ( $items ) {
			self::stash( $items );
		}
		$msg = sprintf( /* translators: %d: people changed */ _n( '%d change saved.', '%d changes saved.', $n, 'songpress' ), $n );
		if ( $short ) {
			$msg .= ' ' . __( 'A password needs at least 8 characters, so one was not changed.', 'songpress' );
		}
		self::go( array( 'sp_saved' => rawurlencode( $msg ) ) );
	}

	/** The People screen. With $inner, only the invitations, class list and add form (People puts its own list under them). */
	public static function render( $inner = false ) {
		if ( ! $inner ) {
			return Songpress_Persons::render();
		}
		if ( ! Songpress_Caps::can( 'members' ) ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'Only Managers can see members.', 'songpress' ) . '</p></div>';
		}
		$o = '';
		$key   = 'songpress_invites_' . get_current_user_id();
		$items = get_transient( $key );
		if ( is_array( $items ) && $items ) {
			delete_transient( $key );
			foreach ( $items as $it ) {
				$text = sprintf(
					/* translators: 1: link 2: username 3: password */
					__( "You're invited to SongPress.\nOpen: %1\$s\nUsername: %2\$s\nPassword: %3\$s", 'songpress' ),
					self::invite_link( $it['login'] ),
					$it['login'],
					$it['pass']
				);
				$o .= '<div class="spe-invite"><b>' . esc_html( $it['name'] ) . '</b><textarea readonly rows="4" onfocus="this.select()">' . esc_textarea( $text ) . '</textarea><button type="button" class="spe-btn spe-copytext">' . esc_html__( 'Copy invitation', 'songpress' ) . '</button><span class="spe-help">' . esc_html__( 'The password is shown only now. Set a new one any time below.', 'songpress' ) . '</span></div>';
			}
		}

		$o .= '<p class="spe-help">' . esc_html__( 'SongPress has its own classes, separate from WordPress roles. WordPress administrators are always Managers. Nobody is emailed: you set the password and send them the invitation.', 'songpress' ) . '</p>';
		$o .= '<ul class="spe-classes">';
		foreach ( Songpress_Caps::CLASSES as $c ) {
			$o .= '<li><b>' . esc_html( $c[0] ) . '</b> ' . esc_html( $c[1] ) . '</li>';
		}
		$o .= '</ul>';

		/* Add */
		$o .= '<h3>' . esc_html__( 'Add a person', 'songpress' ) . '</h3><form method="post" class="spe-addmember" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_member_add', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_member_add"><div class="spe-grid">';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'First name', 'songpress' ) . '</span><input name="first" required autocomplete="off"></label>';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Last name', 'songpress' ) . '</span><input name="last" required autocomplete="off"></label>';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Class', 'songpress' ) . '</span><select name="class_new">';
		foreach ( Songpress_Caps::assignable() as $k => $c ) {
			$o .= '<option value="' . esc_attr( $k ) . '"' . selected( 'viewer', $k, false ) . '>' . esc_html( $c[0] ) . '</option>';
		}
		$o .= '</select></label><label class="spe-field"><span>' . esc_html__( 'Password (blank makes one)', 'songpress' ) . '</span><input name="pass" autocomplete="off" placeholder="' . esc_attr__( 'at least 8 characters', 'songpress' ) . '"></label></div>';
		$o .= '<p class="spe-help">' . esc_html__( 'The username is firstname.lastname.', 'songpress' ) . '</p><button type="submit" class="spe-save">' . esc_html__( 'Create login', 'songpress' ) . '</button></form>';

		$o .= '<script>document.addEventListener("click",function(e){var b=e.target.closest(".spe-copytext");if(!b)return;var t=b.parentNode.querySelector("textarea");t.select();try{navigator.clipboard.writeText(t.value);b.textContent="' . esc_js( __( 'Copied', 'songpress' ) ) . '";}catch(x){}});</script>';
		return $o;
	}
}
