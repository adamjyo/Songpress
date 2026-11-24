<?php
defined( 'ABSPATH' ) || exit;

/**
 * People: one list where a person's profile (photo, bio, IPI) and their SongPress login (class, password) are the
 * same record, with a report of what they have earned from ASCAP, MLC, DistroKid (as a performer) and CCLI.
 * Bands, labels and publishers stay on their own lists. No email is ever sent.
 */
class Songpress_Persons {

	public static function init() {
		add_action( 'admin_post_songpress_person_rename', array( __CLASS__, 'handle_rename' ) );
		add_action( 'wp_ajax_songpress_person_rename', array( __CLASS__, 'handle_rename' ) ); // admin-post.php is blocked on some hosts, so the form posts here
		add_action( 'admin_post_songpress_person_tidy', array( __CLASS__, 'handle_tidy' ) );
		add_action( 'wp_ajax_songpress_person_adopt', array( __CLASS__, 'ajax_adopt' ) );
		add_action( 'admin_post_songpress_person_delete', array( __CLASS__, 'handle_delete' ) );
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'sp_page' => 'people' ), $args ), Songpress_Editor::list_url() );
	}

	/** First and last name from a display name: the last word is the last name. */
	public static function split_name( $name ) {
		$w = preg_split( '/\s+/', trim( (string) $name ) );
		if ( count( $w ) < 2 ) {
			return array( (string) $name, '' );
		}
		$last = array_pop( $w );
		return array( implode( ' ', $w ), $last );
	}

	/** "adam.page" from first and last name. */
	public static function login_for( $first, $last ) {
		return preg_replace( '/\s+/', '', (string) sanitize_user( strtolower( trim( $first ) . '.' . trim( $last ) ), true ) );
	}

	/** Every person: profile and login matched by name. Each row: key, name, profile, user. */
	public static function rows() {
		global $wpdb;
		$t   = Songpress_Install::table( 'profiles' );
		$out = array();
		foreach ( $wpdb->get_results( "SELECT * FROM {$t} WHERE kind = 'person' ORDER BY name LIMIT 2000" ) as $p ) { // phpcs:ignore WordPress.DB
			$out[ Songpress_Profiles::key( $p->name ) ] = array( 'name' => $p->name, 'profile' => $p, 'user' => null );
		}
		// A name that is a band is not a person: leave it out unless someone has a login under that name.
		foreach ( $wpdb->get_col( "SELECT name_key FROM {$t} WHERE kind = 'band'" ) as $bk ) { // phpcs:ignore WordPress.DB
			unset( $out[ $bk ] );
		}
		$ids = array_map( 'intval', get_users( array( 'meta_key' => Songpress_Caps::META, 'meta_compare' => 'EXISTS', 'fields' => 'ID' ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( get_users( array( 'capability' => 'manage_options', 'fields' => 'ID' ) ) as $a ) {
			$ids[] = (int) $a;
		}
		foreach ( array_unique( $ids ) as $uid ) {
			$u = get_userdata( $uid );
			if ( ! $u ) {
				continue;
			}
			$k = Songpress_Profiles::key( $u->display_name );
			if ( ! isset( $out[ $k ] ) ) {
				$out[ $k ] = array( 'name' => $u->display_name, 'profile' => null, 'user' => null );
			}
			$out[ $k ]['user'] = $u;
		}
		uasort( $out, function ( $a, $b ) {
			return strcasecmp( $a['name'], $b['name'] );
		} );
		return $out;
	}

	public static function total_for( $name ) {
		$key = Songpress_Money::person_key( $name );
		$sum = 0.0;
		foreach ( '' === $key ? array() : Songpress_Money::my_rows( $key ) as $r ) {
			$sum += (float) $r->total;
		}
		return $sum;
	}

	/** What one person earned: totals per source, per quarter and source, and their top songs. Pure data. */
	public static function report( $name ) {
		$key  = Songpress_Money::person_key( $name );
		$rows = '' === $key ? array() : Songpress_Money::my_rows( $key );
		$src  = array();
		foreach ( array_keys( Songpress_Money::SOURCES ) as $s ) {
			$src[ $s ] = 0.0;
		}
		$q   = array();
		$all = 0.0;
		foreach ( $rows as $r ) {
			$src[ $r->src ]         = ( $src[ $r->src ] ?? 0 ) + (float) $r->total;
			$q[ $r->quarter ][ $r->src ] = (float) $r->total;
			$all                   += (float) $r->total;
		}
		krsort( $q );
		return array( 'total' => $all, 'sources' => $src, 'quarters' => $q, 'songs' => '' === $key ? array() : Songpress_Money::my_songs( $key, 10 ) );
	}

	/**
	 * Logins like abby.russ2 that should be abby.russ. $users: id => array( login, display, admin ).
	 * Rename when the plain login is free; merge into the plain account when it is the same person; leave
	 * it alone when the plain login belongs to someone else. Pure. Returns a list of array( action, id, to, into ).
	 */
	public static function tidy_plan( array $users ) {
		$taken = array();
		foreach ( $users as $id => $u ) {
			$taken[ $u['login'] ] = $id;
		}
		$plan = array();
		foreach ( $users as $id => $u ) {
			if ( ! empty( $u['admin'] ) ) {
				continue;
			}
			$base = Songpress_People::username_for( $u['display'] );
			if ( '' === $base || ! preg_match( '/^' . preg_quote( $base, '/' ) . '\d+$/', $u['login'] ) ) {
				continue;
			}
			if ( ! isset( $taken[ $base ] ) ) {
				$plan[]         = array( 'rename', $id, $base, 0 );
				$taken[ $base ] = $id;
				unset( $taken[ $u['login'] ] );
			} elseif ( $taken[ $base ] !== $id && isset( $users[ $taken[ $base ] ] ) && empty( $users[ $taken[ $base ] ]['admin'] ) && empty( $users[ $taken[ $base ] ]['classed'] ) ) {
				// a leftover account from the old app, with no SongPress class: the numbered one takes its login
				$plan[] = array( 'replace', $id, $base, $taken[ $base ] );
			} elseif ( $taken[ $base ] !== $id && isset( $users[ $taken[ $base ] ] ) && Songpress_People::key_for( $users[ $taken[ $base ] ]['display'] ) === Songpress_People::key_for( $u['display'] ) ) {
				$plan[] = array( 'merge', $id, $base, $taken[ $base ] );
			}
		}
		return $plan;
	}

	private static function tidy_users() {
		$users = array();
		$ids   = array_map( 'intval', get_users( array( 'meta_key' => Songpress_Caps::META, 'meta_compare' => 'EXISTS', 'fields' => 'ID' ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( get_users( array( 'fields' => 'ID' ) ) as $i ) {
			$ids[] = (int) $i;
		}
		foreach ( array_unique( $ids ) as $id ) {
			$u = get_userdata( $id );
			if ( $u ) {
				$users[ $id ] = array( 'login' => $u->user_login, 'display' => $u->display_name, 'admin' => Songpress_Caps::is_wp_admin( $id ), 'classed' => '' !== (string) get_user_meta( $id, Songpress_Caps::META, true ) );
			}
		}
		return $users;
	}

	/**
	 * Make the logged-in WordPress user be an existing SongPress person: everything filed under that person's account
	 * (credits, class, playlists, favorites, ratings, usage) moves to this login, and this login takes the person's name.
	 * The old account is left in place, only emptied.
	 */
	public static function adopt( $from, $to ) {
		global $wpdb;
		$from = (int) $from;
		$to   = (int) $to;
		if ( ! $from || ! $to || $from === $to ) {
			return false;
		}
		$moved = array();
		foreach ( array( 'credits', 'playlists', 'favorites', 'usage' ) as $tb ) {
			$t = Songpress_Install::table( $tb );
			if ( 'favorites' === $tb ) { // one favorite per song and person: keep this login's own, move the rest
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE user_id = %d AND song_id IN (SELECT song_id FROM (SELECT song_id FROM {$t} WHERE user_id = %d) x)", $from, $to ) ); // phpcs:ignore WordPress.DB
			}
			$moved[ $tb ] = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET user_id = %d WHERE user_id = %d", $to, $from ) ); // phpcs:ignore WordPress.DB
		}
		$r = Songpress_Install::table( 'ratings' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$r} WHERE voter = %s AND song_id IN (SELECT song_id FROM (SELECT song_id FROM {$r} WHERE voter = %s) x)", 'u:' . $from, 'u:' . $to ) ); // phpcs:ignore WordPress.DB
		$moved['ratings'] = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$r} SET voter = %s WHERE voter = %s", 'u:' . $to, 'u:' . $from ) ); // phpcs:ignore WordPress.DB
		$fu = get_userdata( $from );
		if ( $fu ) {
			wp_update_user( array( 'ID' => $to, 'display_name' => $fu->display_name ) );
		}
		foreach ( array( Songpress_People::KEY_META ) as $m ) {
			$v = get_user_meta( $from, $m, true );
			if ( $v ) {
				update_user_meta( $to, $m, $v );
				delete_user_meta( $from, $m );
			}
		}
		if ( '' === (string) get_user_meta( $to, Songpress_Caps::META, true ) ) {
			$c = get_user_meta( $from, Songpress_Caps::META, true );
			if ( $c ) {
				update_user_meta( $to, Songpress_Caps::META, $c );
			}
		}
		delete_user_meta( $from, Songpress_Caps::META );
		Songpress_Caps::forget( $from );
		Songpress_Caps::forget( $to );
		Songpress_Audit::log( 'people', 'A login took over a person', $fu ? $fu->user_login : '', array( 'from' => $from, 'to' => $to, 'moved' => $moved ) );
		return $moved;
	}

	public static function ajax_adopt() {
		check_ajax_referer( 'songpress_adopt', 'n' );
		if ( ! Songpress_Caps::can( 'members' ) ) {
			wp_send_json_error( array( 'msg' => __( 'Only Managers can do this.', 'songpress' ) ), 403 );
		}
		$login = sanitize_user( wp_unslash( $_POST['login'] ?? '' ), true );
		$from  = '' !== $login ? get_user_by( 'login', $login ) : false;
		if ( ! $from ) {
			wp_send_json_error( array( 'msg' => __( 'No login with that name was found.', 'songpress' ) ) );
		}
		if ( (int) $from->ID === get_current_user_id() ) {
			wp_send_json_error( array( 'msg' => __( 'That is the login you are using now.', 'songpress' ) ) );
		}
		if ( empty( $_POST['go'] ) ) { // a first look: what would move
			global $wpdb;
			$c = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT song_id) FROM ' . Songpress_Install::table( 'credits' ) . ' WHERE user_id = %d', $from->ID ) ); // phpcs:ignore WordPress.DB
			wp_send_json_success( array( 'preview' => true, 'name' => $from->display_name, 'works' => $c ) );
		}
		$moved = self::adopt( $from->ID, get_current_user_id() );
		wp_send_json_success( array( 'done' => true, 'name' => $from->display_name ) );
	}

	private static function adopt_box() {
		$u = wp_get_current_user();
		$o = '<details class="spe-tidy"><summary>' . esc_html( sprintf( /* translators: %s: the login you are using */ __( 'Be someone else\'s account: you are logged in as %s', 'songpress' ), $u->user_login ) ) . '</summary>'
			. '<p class="spe-help">' . esc_html__( 'If you already exist in SongPress under another login (for example adam.page), type that login. Its credits, playlists, favorites and ratings move to the login you are using now, and this login takes that person\'s name.', 'songpress' ) . '</p>'
			. '<p><input type="text" id="sp-adopt-login" placeholder="adam.page" autocomplete="off"> <button type="button" class="spe-btn" id="sp-adopt-go">' . esc_html__( 'Take over', 'songpress' ) . '</button></p><p class="spe-help" id="sp-adopt-msg" aria-live="polite"></p></details>'
			. '<script>(function(){var b=document.getElementById("sp-adopt-go"),i=document.getElementById("sp-adopt-login"),m=document.getElementById("sp-adopt-msg"),arm=false,u=' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ',n=' . wp_json_encode( wp_create_nonce( 'songpress_adopt' ) ) . ';if(!b)return;'
			. 'i.addEventListener("input",function(){arm=false;b.textContent="Take over"});'
			. 'b.addEventListener("click",function(){var f=new FormData();f.append("action","songpress_person_adopt");f.append("n",n);f.append("login",i.value.trim());if(arm)f.append("go","1");m.textContent="Working…";fetch(u,{method:"POST",body:f,credentials:"same-origin"}).then(function(r){return r.json()}).then(function(j){if(!j||!j.success){m.textContent=(j&&j.data&&j.data.msg)||"Could not do that.";return}if(j.data.preview){arm=true;b.textContent="Yes, take over";m.textContent="This moves "+j.data.name+"\u2019s "+j.data.works+" credited work(s), playlists, favorites and ratings to you. Press again to do it."}else{arm=false;b.textContent="Take over";m.textContent="Done. You are now "+j.data.name+". Reload the app."}}).catch(function(){m.textContent="Could not do that."})});})();</script>';
		return $o;
	}

	public static function handle_tidy() {
		check_admin_referer( 'songpress_person_tidy' );
		if ( ! Songpress_Caps::can( 'members' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$ren = 0;
		$mer = 0;
		foreach ( self::tidy_plan( self::tidy_users() ) as $a ) {
			list( $act, $id, $to, $into ) = $a;
			if ( 'replace' === $act ) {
				$ct = Songpress_Install::table( 'credits' );
				$wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET user_id = %d WHERE user_id = %d", $id, $into ) ); // phpcs:ignore WordPress.DB
				wp_delete_user( $into, $id );
				$act = 'rename';
			}
			if ( 'rename' === $act ) {
				if ( username_exists( $to ) ) {
					continue;
				}
				$wpdb->update( $wpdb->users, array( 'user_login' => $to, 'user_nicename' => sanitize_title( $to ) ), array( 'ID' => $id ) );
				clean_user_cache( $id );
				++$ren;
			} else {
				$ct = Songpress_Install::table( 'credits' );
				$wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET user_id = %d WHERE user_id = %d", $into, $id ) ); // phpcs:ignore WordPress.DB
				$key = get_user_meta( $id, Songpress_People::KEY_META, true );
				if ( $key && ! get_user_meta( $into, Songpress_People::KEY_META, true ) ) {
					update_user_meta( $into, Songpress_People::KEY_META, $key );
				}
				wp_delete_user( $id, $into );
				++$mer;
			}
		}
		self::go( array( 'sp_saved' => rawurlencode( sprintf( /* translators: 1: renamed 2: merged */ __( 'Tidied logins: %1$d renamed, %2$d duplicates merged into the account with the plain login.', 'songpress' ), $ren, $mer ) ) ) );
	}

	/** The trash can on a person: one form for the page, buttons point at it. The first press arms it, the second deletes. */
	private static function del_button( $name ) {
		return '<button type="submit" form="sp-person-del" name="person" value="' . esc_attr( Songpress_Profiles::key( $name ) ) . '" class="spe-trash" data-name="' . esc_attr( $name ) . '" title="' . esc_attr__( 'Delete this person', 'songpress' ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: name */ __( 'Delete %s', 'songpress' ), $name ) ) . '"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 002 2h6a2 2 0 002-2l1-12M9 7V4h6v3"/></svg></button>';
	}

	private static function del_form() {
		if ( ! Songpress_Caps::can( 'members' ) ) {
			return '';
		}
		return '<form method="post" id="sp-person-del" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_person_delete', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_person_delete"></form>'
			. '<script>(function(){var f=document.getElementById("sp-person-del"),armed=null,tm;if(!f)return;f.addEventListener("submit",function(e){var b=e.submitter;if(!b||!b.classList.contains("spe-trash"))return;if(armed!==b){e.preventDefault();if(armed)armed.classList.remove("armed");armed=b;b.classList.add("armed");b.title="Click again to delete "+b.getAttribute("data-name");clearTimeout(tm);tm=setTimeout(function(){b.classList.remove("armed");armed=null},4000)}})})()</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed script.
	}

	/** Remove a person: their profile and their SongPress access. Their login account is kept, and a person who is credited on songs or has earnings is not deleted (that would orphan the credits and the money). */
	public static function handle_delete() {
		check_admin_referer( 'songpress_person_delete' );
		if ( ! Songpress_Caps::can( 'members' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		global $wpdb;
		$key = sanitize_text_field( wp_unslash( $_POST['person'] ?? '' ) );
		$all = self::rows();
		if ( '' === $key || ! isset( $all[ $key ] ) ) {
			self::go( array( 'sp_err' => rawurlencode( __( 'That person was not found.', 'songpress' ) ) ) );
		}
		$r    = $all[ $key ];
		$name = $r['name'];
		$u    = $r['user'];
		if ( $u && ( Songpress_Caps::is_wp_admin( $u->ID ) || get_current_user_id() === (int) $u->ID ) ) {
			self::go( array( 'sp_err' => rawurlencode( sprintf( /* translators: %s: name */ __( '%s is a manager or is you, so they cannot be deleted here.', 'songpress' ), $name ) ) ) );
		}
		// Credits and earnings are left alone: they live on the songs by name, so the history stays intact.
		if ( $r['profile'] ) {
			$wpdb->delete( Songpress_Install::table( 'profiles' ), array( 'id' => (int) $r['profile']->id ) );
			Songpress_Profiles::reset_cache();
		}
		if ( $u ) {
			Songpress_Caps::assign( (int) $u->ID, '' );
		}
		self::go( array( 'sp_saved' => rawurlencode( sprintf( /* translators: %s: name */ __( '%s was deleted. Their credits and earnings stay on the songs.', 'songpress' ), $name ) . ( $u ? ' ' . __( 'Their login was kept but no longer has access to SongPress.', 'songpress' ) : '' ) ) ) );
	}

	/* ---------- rename ---------- */

	private static function go( $args ) {
		wp_safe_redirect( add_query_arg( $args, self::url() ) );
		exit;
	}

	public static function handle_rename() {
		check_admin_referer( 'songpress_person_rename' );
		if ( ! Songpress_Caps::can( 'members' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		global $wpdb;
		$uid   = absint( $_POST['uid'] ?? 0 );
		$old   = sanitize_text_field( wp_unslash( $_POST['old'] ?? '' ) );
		$first = sanitize_text_field( wp_unslash( $_POST['first'] ?? '' ) );
		$last  = sanitize_text_field( wp_unslash( $_POST['last'] ?? '' ) );
		$new   = trim( $first . ' ' . $last );
		$back  = array( 'person' => Songpress_Profiles::key( $old ) );
		if ( '' === $first || '' === $last ) {
			self::go( $back + array( 'sp_err' => rawurlencode( __( 'Enter a first and last name.', 'songpress' ) ) ) );
		}
		$nk = Songpress_Profiles::key( $new );
		$ok = Songpress_Profiles::key( $old );
		$pt = Songpress_Install::table( 'profiles' );
		$clash = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$pt} WHERE kind = 'person' AND name_key = %s", $nk ) ); // phpcs:ignore WordPress.DB
		$mine  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$pt} WHERE kind = 'person' AND name_key = %s", $ok ) ); // phpcs:ignore WordPress.DB
		if ( $clash && $clash !== $mine ) {
			self::go( $back + array( 'sp_err' => rawurlencode( __( 'Someone with that name is already in People.', 'songpress' ) ) ) );
		}
		$login = '';
		$moved = '';
		$self  = false;
		if ( $uid ) {
			$u = get_userdata( $uid );
			if ( ! $u ) {
				self::go( $back );
			}
			$typed = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ), true ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$typed = preg_replace( '/\s+/', '', (string) $typed );
			if ( '' !== $typed && $typed !== $u->user_login ) {
				$login = $typed; // a username typed by the manager is used exactly as typed
			} elseif ( $ok !== $nk ) {
				$login = self::login_for( $first, $last ); // a new name makes first.last, as before
			} else {
				$login = $u->user_login; // nothing changed: keep it
			}
			$other = '' === $login ? 0 : (int) username_exists( $login );
			if ( '' === $login ) {
				self::go( $back + array( 'sp_err' => rawurlencode( __( 'That login is not valid.', 'songpress' ) ) ) );
			}
			if ( $other && $other !== $uid ) { // taken by another account: ask, then give it to this person and rename the other one
				$ou = get_userdata( $other );
				if ( empty( $_POST['confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
					$f = '<form method="post" action="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '">' . wp_nonce_field( 'songpress_person_rename', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_person_rename"><input type="hidden" name="confirm" value="1">';
					foreach ( array( 'uid' => $uid, 'old' => $old, 'first' => $first, 'last' => $last, 'username' => $login ) as $k => $v ) {
						$f .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
					}
					$f .= '<p><button type="submit">' . esc_html__( 'Yes, replace it', 'songpress' ) . '</button> <a href="' . esc_url( self::url( $back ) ) . '">' . esc_html__( 'Cancel', 'songpress' ) . '</a></p></form>';
					wp_die( '<h1>' . esc_html__( 'That username is already used', 'songpress' ) . '</h1><p>' . esc_html( sprintf( /* translators: 1: login 2: display name */ __( '%1$s belongs to %2$s. If you continue, %1$s moves to %3$s and the other account is renamed (its name and password stay).', 'songpress' ), $login, $ou ? $ou->display_name : '', $new ) ) . '</p>' . $f, '', array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				$free = $login . '-old';
				for ( $i = 2; username_exists( $free ); $i++ ) {
					$free = $login . '-old' . $i;
				}
				$wpdb->update( $wpdb->users, array( 'user_login' => $free, 'user_nicename' => sanitize_title( $free ) ), array( 'ID' => $other ) );
				clean_user_cache( $other );
				$moved = $free;
			}
			$wpdb->update( $wpdb->users, array( 'user_login' => $login, 'user_nicename' => sanitize_title( $login ), 'display_name' => $new ), array( 'ID' => $uid ) );
			update_user_meta( $uid, 'first_name', $first );
			update_user_meta( $uid, 'last_name', $last );
			update_user_meta( $uid, 'nickname', $new );
			clean_user_cache( $uid );
			$self = get_current_user_id() === $uid;
		}
		if ( $mine ) {
			$wpdb->update( $pt, array( 'name' => $new, 'name_key' => $nk, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $mine ) );
			Songpress_Profiles::reset_cache();
		} else {
			Songpress_Profiles::upsert( 'person', $new, array() );
		}
		// credits and earnings follow the new name
		if ( $ok !== $nk ) {
			$ct = Songpress_Install::table( 'credits' );
			$wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET name = %s WHERE LOWER(TRIM(name)) = %s", $new, $ok ) ); // phpcs:ignore WordPress.DB
			$pe = Songpress_Install::table( 'person_earnings' );
			$wpdb->query( $wpdb->prepare( "UPDATE {$pe} SET person = %s, person_key = %s WHERE person_key = %s", $new, Songpress_Money::person_key( $new ), Songpress_Money::person_key( $old ) ) ); // phpcs:ignore WordPress.DB
		}
		if ( $self ) {
			wp_clear_auth_cookie();
			wp_safe_redirect( Songpress_Members::invite_link( $login ) );
			exit;
		}
		self::go( array( 'person' => $nk, 'sp_saved' => rawurlencode( '' !== $login ? sprintf( /* translators: 1: name 2: login */ __( 'Renamed to %1$s. Their login is now %2$s.', 'songpress' ), $new, $login ) . ( '' !== $moved ? ' ' . sprintf( /* translators: %s: new login of the other account */ __( 'The account that had it is now %s.', 'songpress' ), $moved ) : '' ) : sprintf( __( 'Renamed to %s.', 'songpress' ), $new ) ) ) );
	}

	/* ---------- screens ---------- */

	public static function render() {
		if ( ! Songpress_Caps::can( 'members' ) ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'Only Managers can see people.', 'songpress' ) . '</p></div>';
		}
		$one = isset( $_GET['person'] ) ? Songpress_Profiles::key( sanitize_text_field( wp_unslash( $_GET['person'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$all = self::rows();
		return ( '' !== $one && isset( $all[ $one ] ) ? self::render_one( $all[ $one ] ) : self::render_list( $all ) ) . self::del_form();
	}

	private static function notes() {
		$o = '';
		if ( isset( $_GET['sp_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<p class="spe-note ok">' . esc_html( sanitize_text_field( wp_unslash( $_GET['sp_saved'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( isset( $_GET['sp_err'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<p class="spe-note bad" role="alert">' . esc_html( sanitize_text_field( wp_unslash( $_GET['sp_err'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		return $o;
	}

	private static function render_list( array $all ) {
		// the invitation boxes and the add form are the old Members screen, still used for the login side
		$o = Songpress_Members::render( true );
		$o = '<div class="songpress sp-app sp-editor sp-members sp-people"><div class="spe-top"><h2>' . esc_html__( 'People', 'songpress' ) . '</h2> <a class="spe-btn" href="' . esc_url( self::url( array( 'profile' => 'new', 'kind' => 'person' ) ) ) . '">' . esc_html__( 'Add a person without a login', 'songpress' ) . '</a></div>' . self::notes() . $o;
		$plan = self::tidy_plan( self::tidy_users() );
		if ( $plan ) {
			$o .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="spe-tidy">' . wp_nonce_field( 'songpress_person_tidy', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_person_tidy"><p class="spe-help">' . esc_html( sprintf( /* translators: %d: logins */ _n( '%d login ends in a number (like abby.russ2).', '%d logins end in a number (like abby.russ2).', count( $plan ), 'songpress' ), count( $plan ) ) ) . ' ' . esc_html__( 'Tidy them to the plain first.last. If the plain login already belongs to the same person, the two accounts are merged into it.', 'songpress' ) . '</p><button class="spe-save" type="submit">' . esc_html__( 'Tidy logins', 'songpress' ) . '</button></form>';
		}
		$o .= self::adopt_box();
		$o .= Songpress_Access::asks_box();
		$o .= Songpress_Access::old_box();
		$o .= Songpress_Push::alerts_box();
		$o .= Songpress_Push::send_box();
		$o .= '<h3>' . esc_html__( 'Everyone', 'songpress' ) . '</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_member_update', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_member_update">';
		$o .= '<div class="spe-head spe-mhead"><span>' . esc_html__( 'Person', 'songpress' ) . '</span><span>' . esc_html__( 'Class', 'songpress' ) . '</span><span>' . esc_html__( 'New password', 'songpress' ) . '</span><span>' . esc_html__( 'Earned', 'songpress' ) . '</span></div>';
		foreach ( $all as $k => $r ) {
			$u    = $r['user'];
			$earn = Songpress_Money::money( self::total_for( $r['name'] ) );
			$o   .= '<div class="spe-member"><span class="spe-who">' . Songpress_Profiles::chip( $r['name'], 28, 'person' ) . '<span><a href="' . esc_url( self::url( array( 'person' => $k ) ) ) . '"><b>' . esc_html( $r['name'] ) . '</b></a><br><small>' . esc_html( $u ? $u->user_login : __( 'no login', 'songpress' ) ) . '</small></span></span>';
			if ( $u && Songpress_Caps::is_wp_admin( $u->ID ) ) {
				$o .= '<span><b>' . esc_html__( 'Manager', 'songpress' ) . '</b> <small>' . esc_html__( '(WordPress administrator)', 'songpress' ) . '</small></span><span></span>';
			} elseif ( $u ) {
				$class = Songpress_Caps::class_of( $u->ID );
				$o    .= '<select name="class[' . (int) $u->ID . ']"><option value="">' . esc_html__( 'No access (remove)', 'songpress' ) . '</option>';
				foreach ( Songpress_Caps::assignable() as $ck => $c ) {
					$o .= '<option value="' . esc_attr( $ck ) . '"' . selected( $class, $ck, false ) . '>' . esc_html( $c[0] ) . '</option>';
				}
				$o .= '</select><input name="pw[' . (int) $u->ID . ']" autocomplete="off" placeholder="' . esc_attr__( 'leave blank to keep', 'songpress' ) . '">';
			} else {
				$o .= '<span><small>' . esc_html__( 'Open the person to give them a login', 'songpress' ) . '</small></span><span></span>';
			}
			$o .= '<span class="n">' . esc_html( $earn ) . ' ' . ( $u ? '<button type="button" class="spe-btn sp-copylink" data-uid="' . (int) $u->ID . '" title="' . esc_attr__( 'Make a one-time link and copy it to send to them', 'songpress' ) . '">' . esc_html__( 'Copy login link', 'songpress' ) . '</button> ' : '' ) . self::del_button( $r['name'] ) . '</span></div>';
		}
		return $o . '<div class="spe-bar"><button type="submit" class="spe-save">' . esc_html__( 'Save changes', 'songpress' ) . '</button></div></form>' . Songpress_Access::copy_script() . '</div>';
	}

	private static function render_one( array $r ) {
		$u    = $r['user'];
		$name = $r['name'];
		list( $first, $last ) = self::split_name( $name );
		if ( $u ) {
			$fm = (string) get_user_meta( $u->ID, 'first_name', true );
			$lm = (string) get_user_meta( $u->ID, 'last_name', true );
			if ( '' !== $fm && '' !== $lm ) {
				list( $first, $last ) = array( $fm, $lm );
			} elseif ( 'adamjyo' === $u->user_login ) {
				list( $first, $last ) = array( 'Adam', 'Page' );
			}
		}
		$o  = '<div class="songpress sp-app sp-editor sp-members sp-people"><div class="spe-top"><h2>' . Songpress_Profiles::chip( $name, 40, 'person' ) . esc_html( $name ) . '</h2> <a class="spe-btn" href="' . esc_url( self::url() ) . '">&lsaquo; ' . esc_html__( 'All people', 'songpress' ) . '</a></div>' . self::notes();
		$o .= '<p>' . self::del_button( $name ) . ' ' . ( $r['profile'] ? '<a class="spe-btn" href="' . esc_url( self::url( array( 'profile' => (int) $r['profile']->id, 'kind' => 'person' ) ) ) . '">' . esc_html__( 'Edit photo, bio, contact and IPI', 'songpress' ) . '</a>' : '<a class="spe-btn" href="' . esc_url( self::url( array( 'profile' => 'new', 'kind' => 'person' ) ) ) . '">' . esc_html__( 'Add a profile', 'songpress' ) . '</a>' ) . '</p>';

		$o .= '<h3>' . esc_html__( 'Name and login', 'songpress' ) . '</h3><form method="post" action="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" class="spe-addmember">' . wp_nonce_field( 'songpress_person_rename', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="songpress_person_rename"><input type="hidden" name="uid" value="' . (int) ( $u ? $u->ID : 0 ) . '"><input type="hidden" name="old" value="' . esc_attr( $name ) . '">'
			. '<label class="spe-field"><span>' . esc_html__( 'First name', 'songpress' ) . '</span><input name="first" value="' . esc_attr( $first ) . '" required></label>'
			. '<label class="spe-field"><span>' . esc_html__( 'Last name', 'songpress' ) . '</span><input name="last" value="' . esc_attr( $last ) . '" required></label>'
			. ( $u ? '<label class="spe-field"><span>' . esc_html__( 'Username', 'songpress' ) . '</span><input name="username" value="' . esc_attr( $u->user_login ) . '" autocapitalize="none" autocomplete="off" spellcheck="false"></label>' : '' );
		$o .= '<p class="spe-help">' . ( $u ? sprintf( /* translators: 1: current login 2: new login */ esc_html__( 'Login now: %1$s. Change the username above to set it yourself. If you change only the name, the username becomes %2$s (first.last). Credits and earnings follow the new name.', 'songpress' ), '<b>' . esc_html( $u->user_login ) . '</b>', '<b>' . esc_html( self::login_for( $first, $last ) ) . '</b>' ) . ( get_current_user_id() === $u->ID ? ' ' . esc_html__( 'This is you, so you will be asked to sign in again with the new login. Your password stays the same.', 'songpress' ) : '' ) : esc_html__( 'This person has no login yet.', 'songpress' ) ) . '</p><button class="spe-save" type="submit">' . esc_html__( 'Save name and login', 'songpress' ) . '</button></form>';

		if ( ! $u ) {
			$o .= '<h3>' . esc_html__( 'Give them a login', 'songpress' ) . '</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="spe-addmember">' . wp_nonce_field( 'songpress_member_add', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_member_add"><input type="hidden" name="first" value="' . esc_attr( $first ) . '"><input type="hidden" name="last" value="' . esc_attr( $last ) . '"><label class="spe-field"><span>' . esc_html__( 'Class', 'songpress' ) . '</span><select name="class_new">';
			foreach ( Songpress_Caps::assignable() as $ck => $c ) {
				$o .= '<option value="' . esc_attr( $ck ) . '"' . selected( 'viewer', $ck, false ) . '>' . esc_html( $c[0] ) . '</option>';
			}
			$o .= '</select></label><label class="spe-field"><span>' . esc_html__( 'Password (blank makes one)', 'songpress' ) . '</span><input name="pass" autocomplete="off"></label><button class="spe-save" type="submit">' . esc_html__( 'Create login', 'songpress' ) . '</button></form>';
		}

		if ( ! $u ) {
			$o .= '<h3 id="sp-link">' . esc_html__( 'Login link', 'songpress' ) . '</h3><p class="spe-help">' . esc_html__( 'Give them a login above, then the button to send their login link appears here.', 'songpress' ) . '</p>';
		}
		if ( $u ) {
			$o .= Songpress_Access::link_box( $u->ID );
			$o .= Songpress_Push::send_box( $u->ID );
		}

		$rep = self::report( $name );
		$o  .= '<h3>' . esc_html__( 'Earnings', 'songpress' ) . '</h3>';
		if ( ! $rep['quarters'] ) {
			return $o . '<p class="spe-help">' . esc_html__( 'No earnings yet. They appear when statements are loaded on the Upload / Download page and this person is credited on the songs (as a writer for ASCAP, MLC and CCLI, as a performer for DistroKid).', 'songpress' ) . '</p></div>';
		}
		$o .= '<ul class="songpress-stats"><li>' . esc_html__( 'All time', 'songpress' ) . '<strong>' . esc_html( Songpress_Money::money( $rep['total'] ) ) . '</strong></li>';
		foreach ( $rep['sources'] as $s => $v ) {
			$lab = Songpress_Money::STREAMS[ $s ] ?? strtoupper( $s );
			$o  .= '<li>' . esc_html( 'distrokid' === $s ? $lab . ' ' . __( '(as performer)', 'songpress' ) : $lab ) . '<strong>' . esc_html( Songpress_Money::money( $v ) ) . '</strong></li>';
		}
		$o .= '</ul><div class="spm-scroll"><table class="spm-table"><thead><tr><th>' . esc_html__( 'Quarter', 'songpress' ) . '</th>';
		$cols = array_keys( $rep['sources'] );
		foreach ( $cols as $s ) {
			$o .= '<th class="n">' . esc_html( Songpress_Money::STREAMS[ $s ] ?? strtoupper( $s ) ) . '</th>';
		}
		$o .= '<th class="n">' . esc_html__( 'Total', 'songpress' ) . '</th></tr></thead><tbody>';
		foreach ( $rep['quarters'] as $q => $by ) {
			$o .= '<tr><td>' . esc_html( $q ) . '</td>';
			foreach ( $cols as $s ) {
				$o .= '<td class="n">' . esc_html( isset( $by[ $s ] ) ? Songpress_Money::money( $by[ $s ] ) : '' ) . '</td>';
			}
			$o .= '<td class="n"><b>' . esc_html( Songpress_Money::money( array_sum( $by ) ) ) . '</b></td></tr>';
		}
		$o .= '</tbody></table></div>';
		if ( $rep['songs'] ) {
			$items = array();
			foreach ( $rep['songs'] as $s ) {
				$items[] = array( $s->work_title, (float) $s->total, Songpress_Money::money( $s->total ) );
			}
			$o .= '<h3>' . esc_html__( 'Top songs', 'songpress' ) . '</h3>' . Songpress_Money::bars( $items );
		}
		return $o . '</div>';
	}
}
