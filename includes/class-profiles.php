<?php
defined( 'ABSPATH' ) || exit;

/** Photos, bios and contact details for people, bands and publishers, matched to credits by name. */
class Songpress_Profiles {

	const KINDS = array( 'person', 'band', 'label', 'publisher' );

	private static $cache = null;

	public static function key( $name ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $name ) ) );
	}

	/** The old catalog stored photo links in several shapes; return a full URL or ''. */
	public static function clean_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		} elseif ( '/' === $url[0] && function_exists( 'home_url' ) ) {
			$url = home_url( $url );
		}
		return preg_match( '#^https?://#i', $url ) ? $url : '';
	}

	/** Insert or update one profile. Blank incoming fields never wipe what is already saved. */
	public static function upsert( $kind, $name, array $f ) {
		global $wpdb;
		$kind = in_array( $kind, self::KINDS, true ) ? $kind : 'person';
		$name = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( $name ) ) );
		$key  = self::key( $name );
		if ( '' === $key ) {
			return 0;
		}
		$t   = Songpress_Install::table( 'profiles' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE kind = %s AND name_key = %s", $kind, $key ) );
		$in  = array();
		foreach ( array( 'aliases', 'bio', 'email', 'phone', 'website', 'ipi', 'pro', 'birthday', 'address' ) as $k ) {
			if ( isset( $f[ $k ] ) && '' !== trim( (string) $f[ $k ] ) ) {
				$in[ $k ] = 'bio' === $k || 'aliases' === $k || 'address' === $k ? sanitize_textarea_field( $f[ $k ] ) : ( 'website' === $k ? esc_url_raw( $f[ $k ] ) : sanitize_text_field( $f[ $k ] ) );
			}
		}
		if ( array_key_exists( 'payee', $f ) ) {
			$py = sanitize_text_field( (string) $f['payee'] );
			if ( '' !== $py ) {
				$in['payee'] = $py;
			} elseif ( empty( $f['keep'] ) ) {
				$in['payee'] = '';
			}
		}
		if ( ! empty( $f['photo_id'] ) ) {
			$in['photo_id']  = absint( $f['photo_id'] );
			$in['photo_url'] = '';
		} elseif ( ! empty( $f['photo_url'] ) ) {
			$url = self::clean_url( $f['photo_url'] );
			if ( '' !== $url ) {
				$id              = Songpress_Media::id_from_url( $url );
				$in['photo_id']  = $id;
				$in['photo_url'] = $id ? '' : $url;
			}
		}
		$in['updated_at'] = current_time( 'mysql' );
		self::$cache      = null;
		if ( $row ) {
			// when importing, keep what the user already filled in
			if ( ! empty( $f['keep'] ) ) {
				foreach ( $in as $k => $v ) {
					if ( 'updated_at' !== $k && ! empty( $row->$k ) ) {
						unset( $in[ $k ] );
					}
				}
			}
			$wpdb->update( $t, $in, array( 'id' => (int) $row->id ) );
			return (int) $row->id;
		}
		$wpdb->insert( $t, array_merge( array( 'kind' => $kind, 'name' => $name, 'name_key' => $key ), $in ) );
		return (int) $wpdb->insert_id;
	}

	/* ---------- a signed-in person edits their own details (the phone app and the desktop app) ---------- */

	const MINE = array( 'email', 'phone', 'birthday', 'address', 'website', 'ipi', 'pro', 'bio' );

	public static function init_mine() {
		add_action( 'wp_ajax_songpress_my_profile', array( __CLASS__, 'ajax_mine' ) );
		add_action( 'wp_ajax_songpress_my_photo', array( __CLASS__, 'ajax_photo' ) );
	}

	/** The form on the Profile screen. Fields are saved as typed, so a field can be cleared. */
	public static function mine_form( $user ) {
		$p   = self::find( $user->display_name );
		$v   = function ( $k ) use ( $p, $user ) {
			if ( $p && isset( $p->$k ) && '' !== (string) $p->$k ) {
				return (string) $p->$k;
			}
			return 'email' === $k ? (string) $user->user_email : '';
		};
		$f   = function ( $k, $label, $type = 'text', $extra = '' ) use ( $v ) {
			return '<label class="songpress-fld"><span>' . esc_html( $label ) . '</span><input type="' . esc_attr( $type ) . '" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v( $k ) ) . '"' . $extra . '></label>';
		};
		$o   = '<details class="songpress-myinfo"><summary>' . esc_html__( 'My information', 'songpress' ) . '</summary><form class="songpress-myform" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '">'
			. '<input type="hidden" name="action" value="songpress_my_profile"><input type="hidden" name="n" value="' . esc_attr( wp_create_nonce( 'songpress_my_profile' ) ) . '">'
			. $f( 'email', __( 'Email', 'songpress' ), 'email', ' autocomplete="email"' )
			. $f( 'phone', __( 'Phone', 'songpress' ), 'tel', ' autocomplete="tel"' )
			. $f( 'birthday', __( 'Birthday', 'songpress' ), 'date', ' autocomplete="bday"' )
			. '<label class="songpress-fld"><span>' . esc_html__( 'Address', 'songpress' ) . '</span><textarea name="address" rows="3" autocomplete="street-address">' . esc_textarea( $v( 'address' ) ) . '</textarea></label>'
			. $f( 'website', __( 'Website', 'songpress' ), 'url', ' autocomplete="url"' )
			. $f( 'ipi', __( 'IPI number', 'songpress' ), 'text', ' inputmode="numeric"' )
			. $f( 'pro', __( 'PRO (ASCAP, BMI...)', 'songpress' ) )
			. '<label class="songpress-fld"><span>' . esc_html__( 'Bio', 'songpress' ) . '</span><textarea name="bio" rows="4">' . esc_textarea( $v( 'bio' ) ) . '</textarea></label>'
			. '<button class="songpress-btn" type="submit">' . esc_html__( 'Save', 'songpress' ) . '</button> <span class="songpress-myinfo-msg" role="status"></span></form>'
			. '<script>(function(){var f=document.currentScript.parentNode.querySelector(".songpress-myform");if(!f)return;f.addEventListener("submit",function(e){e.preventDefault();var m=f.querySelector(".songpress-myinfo-msg");m.textContent="…";fetch(f.dataset.ajax,{method:"POST",credentials:"same-origin",body:new FormData(f)}).then(function(r){return r.json()}).then(function(j){m.textContent=j&&j.success?' . wp_json_encode( __( 'Saved.', 'songpress' ) ) . ':((j&&j.data&&j.data.msg)||' . wp_json_encode( __( 'Could not save.', 'songpress' ) ) . ')}).catch(function(){m.textContent=' . wp_json_encode( __( 'Could not save.', 'songpress' ) ) . '})})})();</script></details>';
		return $o;
	}

	/** "Adam P": the first name and the last initial, in capitals. Fixed: the picture maker prints exactly this. */
	public static function photo_label( $name ) {
		$w = preg_split( '/\s+/', trim( (string) $name ) );
		$f = (string) array_shift( $w );
		$l = $w ? (string) end( $w ) : '';
		return trim( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $f ) . ( '' !== $l ? ' ' . mb_strtoupper( mb_substr( $l, 0, 1 ) ) : '' ) : strtoupper( $f ) . ( '' !== $l ? ' ' . strtoupper( $l[0] ) : '' ) );
	}

	/** The picture maker on the Profile screen. */
	public static function avatar_tool( $user, $person = '' ) {
		$cfg = array(
			'label' => self::photo_label( $user->display_name ),
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'n'     => wp_create_nonce( 'songpress_my_photo' ),
			'person' => (string) $person, // set when a manager makes the picture for someone else
			'lib'   => 'https://cdn.jsdelivr.net/npm/@imgly/background-removal@1.5.5/+esm',
			't'     => array(
				'cut'    => __( 'Remove background', 'songpress' ),
				'keep'   => __( 'Bring the background back', 'songpress' ),
				'work'   => __( 'Removing the background (first time can take a minute)', 'songpress' ),
				'fail'   => __( 'The background could not be removed here. You can still use the photo as it is.', 'songpress' ),
				'bad'    => __( 'That file could not be opened as a picture.', 'songpress' ),
				'pick'   => __( 'Choose a photo first.', 'songpress' ),
				'saving' => __( 'Saving…', 'songpress' ),
				'saved'  => __( 'Saved.', 'songpress' ),
				'fail2'  => __( 'Could not save the picture.', 'songpress' ),
			),
		);
		$o = '<details class="songpress-myinfo sp-avatar-tool"><summary>' . esc_html__( 'Profile picture', 'songpress' ) . '</summary><div class="sp-av">'
			. '<canvas aria-label="' . esc_attr__( 'Profile picture preview', 'songpress' ) . '"></canvas>'
			. '<label class="sp-av-pick songpress-btn">' . esc_html__( 'Choose a photo', 'songpress' ) . '<input class="sp-av-file" type="file" accept="image/*" hidden></label>'
			. '<button type="button" class="sp-av-cut songpress-btn2">' . esc_html__( 'Remove background', 'songpress' ) . '</button>'
			. '<div class="sp-av-bg" role="group" aria-label="' . esc_attr__( 'Background', 'songpress' ) . '"><button type="button" data-bg="accent" class="on">' . esc_html__( 'Color', 'songpress' ) . '</button><button type="button" data-bg="dark">' . esc_html__( 'Dark', 'songpress' ) . '</button><button type="button" data-bg="white">' . esc_html__( 'White', 'songpress' ) . '</button><button type="button" data-bg="none">' . esc_html__( 'None', 'songpress' ) . '</button></div>'
			. '<label class="sp-av-zl"><span>' . esc_html__( 'Zoom', 'songpress' ) . '</span><input class="sp-av-zoom" type="range" min="1" max="3" step="0.01" value="1"></label>'
			. '<p class="songpress-note">' . esc_html__( 'Drag the photo to move it. The name strip is added for you.', 'songpress' ) . '</p>'
			. '<button type="button" class="sp-av-save songpress-btn">' . esc_html( '' !== (string) $person ? __( 'Use as their profile picture', 'songpress' ) : __( 'Use as my profile picture', 'songpress' ) ) . '</button> <span class="sp-av-msg" role="status"></span>'
			. '</div></details>';
		wp_enqueue_script( 'songpress-avatar', SONGPRESS_URL . 'assets/avatar.js', array(), SONGPRESS_VERSION, true );
		wp_add_inline_script( 'songpress-avatar', 'window.SP_AV=' . wp_json_encode( $cfg ) . ';', 'before' );
		return $o;
	}

	public static function ajax_photo() {
		check_ajax_referer( 'songpress_my_photo', 'n' );
		if ( ! is_user_logged_in() || ! Songpress_Caps::can_view() ) {
			wp_send_json_error( array( 'msg' => __( 'Not allowed.', 'songpress' ) ), 403 );
		}
		if ( empty( $_FILES['file']['tmp_name'] ) || $_FILES['file']['size'] > 6 * MB_IN_BYTES ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_send_json_error( array( 'msg' => __( 'The picture is missing or too large.', 'songpress' ) ) );
		}
		$info = @getimagesize( $_FILES['file']['tmp_name'] ); // phpcs:ignore
		if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_PNG, IMAGETYPE_JPEG ), true ) || $info[0] > 2000 || $info[1] > 2000 ) {
			wp_send_json_error( array( 'msg' => __( 'That is not a usable picture.', 'songpress' ) ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$u    = wp_get_current_user();
		$who  = isset( $_POST['person'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['person'] ) ) ) : '';
		if ( '' !== $who ) { // a manager making the picture for someone else
			if ( ! Songpress_Caps::can( 'members' ) ) {
				wp_send_json_error( array( 'msg' => __( 'Not allowed.', 'songpress' ) ), 403 );
			}
			$u = (object) array( 'user_login' => sanitize_title( $who ), 'display_name' => $who );
		}
		$file = array( 'name' => sanitize_file_name( 'profile-' . $u->user_login . '-' . time() . '.png' ), 'type' => 'image/png', 'tmp_name' => $_FILES['file']['tmp_name'], 'error' => 0, 'size' => (int) $_FILES['file']['size'] ); // phpcs:ignore
		$up   = wp_handle_upload( $file, array( 'test_form' => false, 'mimes' => array( 'png' => 'image/png', 'jpg|jpeg' => 'image/jpeg' ) ) );
		if ( ! empty( $up['error'] ) ) {
			wp_send_json_error( array( 'msg' => $up['error'] ) );
		}
		$att = wp_insert_attachment( array( 'post_mime_type' => $up['type'], 'post_title' => $u->display_name, 'post_status' => 'inherit' ), $up['file'] );
		if ( ! $att || is_wp_error( $att ) ) {
			wp_send_json_error( array( 'msg' => __( 'Could not save the picture.', 'songpress' ) ) );
		}
		wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $up['file'] ) );
		self::upsert( 'person', $u->display_name, array( 'photo_id' => (int) $att ) );
		self::reset_cache();
		wp_send_json_success( array( 'url' => $up['url'] ) );
	}

	public static function ajax_mine() {
		check_ajax_referer( 'songpress_my_profile', 'n' );
		if ( ! is_user_logged_in() || ! Songpress_Caps::can_view() ) {
			wp_send_json_error( array( 'msg' => __( 'Not allowed.', 'songpress' ) ), 403 );
		}
		global $wpdb;
		$u = wp_get_current_user();
		self::upsert( 'person', $u->display_name, array() ); // make sure the row exists
		$row = self::find( $u->display_name );
		if ( ! $row ) {
			wp_send_json_error( array( 'msg' => __( 'Could not save.', 'songpress' ) ) );
		}
		$in = array();
		foreach ( self::MINE as $k ) {
			$x = isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( 'bio' === $k || 'address' === $k ) {
				$in[ $k ] = sanitize_textarea_field( $x );
			} elseif ( 'website' === $k ) {
				$in[ $k ] = esc_url_raw( $x );
			} elseif ( 'email' === $k ) {
				$in[ $k ] = sanitize_email( $x );
			} elseif ( 'birthday' === $k ) {
				$in[ $k ] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $x ) ? $x : '';
			} else {
				$in[ $k ] = sanitize_text_field( $x );
			}
		}
		$in['updated_at'] = current_time( 'mysql' );
		$wpdb->update( Songpress_Install::table( 'profiles' ), $in, array( 'id' => (int) $row->id ) );
		self::reset_cache();
		wp_send_json_success();
	}

	/** Which kind of profile a credit role or field points at. A band is never looked up as a person, or the reverse. */
	public static function kind_for_role( $role ) {
		if ( 'publisher' === $role ) {
			return 'publisher';
		}
		if ( 'label' === $role ) {
			return 'label';
		}
		if ( 'band' === $role || 'artist' === $role ) {
			return 'band';
		}
		return 'person'; // writer, performer, producer
	}

	/** Every profile, keyed by kind and name (and each alias). Names are only ever matched inside their own kind. */
	public static function map() {
		global $wpdb;
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$map = array();
		$t   = Songpress_Install::table( 'profiles' );
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id" ) as $r ) {
			$map[ $r->kind . '|' . $r->name_key ] = $r;
			foreach ( preg_split( '/[,|\n]+/', (string) $r->aliases ) as $a ) {
				$k = self::key( $a );
				if ( '' !== $k && ! isset( $map[ $r->kind . '|' . $k ] ) ) {
					$map[ $r->kind . '|' . $k ] = $r;
				}
			}
		}
		self::$cache = $map;
		return $map;
	}

	public static function reset_cache() {
		self::$cache = null;
	}

	public static function find( $name, $kind = 'person' ) {
		$m = self::map();
		$k = $kind . '|' . self::key( $name );
		return isset( $m[ $k ] ) ? $m[ $k ] : null;
	}

	public static function photo_url( $profile, $size = 'thumbnail' ) {
		if ( ! $profile ) {
			return '';
		}
		if ( (int) $profile->photo_id ) {
			$u = wp_get_attachment_image_url( (int) $profile->photo_id, $size );
			if ( $u ) {
				return (string) $u;
			}
		}
		return (string) $profile->photo_url;
	}

	/** A small round photo, or initials when there is no photo. */
	public static function chip( $name, $px = 28, $kind = 'person' ) {
		$name = (string) $name;
		$p    = self::find( $name, $kind );
		$src  = self::photo_url( $p );
		$st   = 'width:' . (int) $px . 'px;height:' . (int) $px . 'px;border-radius:50%;object-fit:cover;vertical-align:middle;margin-right:6px';
		if ( '' !== $src ) {
			return '<img class="sp-face" src="' . esc_url( $src ) . '" alt="" loading="lazy" style="' . esc_attr( $st ) . '">';
		}
		$ini = '';
		foreach ( array_slice( preg_split( '/\s+/', trim( $name ) ), 0, 2 ) as $w ) {
			$ini .= function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $w, 0, 1 ) ) : strtoupper( substr( $w, 0, 1 ) );
		}
		return '<span class="sp-face sp-face-ini" aria-hidden="true" style="' . esc_attr( $st . ';display:inline-flex;align-items:center;justify-content:center;background:#e8e2d4;color:#5a4a1f;font-size:' . round( $px * 0.4 ) . 'px;font-weight:600' ) . '">' . esc_html( $ini ) . '</span>';
	}

	/** Bring photos, bios and contact details across from the old S&P Catalog database. Safe to run again. */
	public static function import_sqlite( $path ) {
		$out = array( 'people' => 0, 'bands' => 0, 'publishers' => 0 );
		try {
			$pdo = new PDO( 'sqlite:' . $path, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
		} catch ( Exception $e ) {
			return $out;
		}
		$has = function ( $table ) use ( $pdo ) {
			$q = $pdo->prepare( "SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?" );
			$q->execute( array( $table ) );
			return (bool) $q->fetchColumn();
		};
		$all = function ( $table ) use ( $pdo ) {
			try {
				return $pdo->query( 'SELECT * FROM ' . $table )->fetchAll( PDO::FETCH_ASSOC ); // table name is a literal from this file
			} catch ( Exception $e ) {
				return array();
			}
		};
		$pick = function ( $r, $keys ) {
			foreach ( $keys as $k ) {
				if ( isset( $r[ $k ] ) && '' !== trim( (string) $r[ $k ] ) && '#N/A' !== trim( (string) $r[ $k ] ) ) {
					return trim( (string) $r[ $k ] );
				}
			}
			return '';
		};
		if ( $has( 'people' ) ) {
			foreach ( $all( 'people' ) as $r ) {
				if ( '' === $pick( $r, array( 'name' ) ) ) {
					continue;
				}
				if ( self::upsert( 'person', $r['name'], array(
					'aliases'   => $pick( $r, array( 'aliases' ) ),
					'photo_url' => $pick( $r, array( 'photo', 'link' ) ),
					'bio'       => $pick( $r, array( 'bio' ) ),
					'email'     => $pick( $r, array( 'email' ) ),
					'phone'     => $pick( $r, array( 'phone' ) ),
					'ipi'       => $pick( $r, array( 'ipi' ) ),
					'pro'       => $pick( $r, array( 'pro' ) ),
					'keep'      => true,
				) ) ) {
					$out['people']++;
				}
			}
		}
		if ( $has( 'bands' ) ) {
			foreach ( $all( 'bands' ) as $r ) {
				if ( '' === $pick( $r, array( 'name' ) ) ) {
					continue;
				}
				if ( self::upsert( 'band', $r['name'], array( 'photo_url' => $pick( $r, array( 'icon_url' ) ), 'bio' => $pick( $r, array( 'bio', 'notes' ) ), 'payee' => in_array( strtolower( $pick( $r, array( 'pays_to' ) ) ), array( 'artist', 'performer' ), true ) ? '' : $pick( $r, array( 'pays_to' ) ), 'keep' => true ) ) ) {
					$out['bands']++;
				}
			}
		}
		if ( $has( 'publishers' ) ) {
			foreach ( $all( 'publishers' ) as $r ) {
				if ( '' === $pick( $r, array( 'name' ) ) ) {
					continue;
				}
				if ( self::upsert( 'publisher', $r['name'], array(
					'photo_url' => $pick( $r, array( 'photo' ) ),
					'email'     => $pick( $r, array( 'email' ) ),
					'phone'     => $pick( $r, array( 'phone' ) ),
					'website'   => $pick( $r, array( 'website' ) ),
					'keep'      => true,
				) ) ) {
					$out['publishers']++;
				}
			}
		}
		return $out;
	}
}
