<?php
defined( 'ABSPATH' ) || exit;

/**
 * SongPress has its own people and classes, separate from WordPress roles. The plugin manages them
 * (SongPress > Members). A WordPress administrator is always a Manager, with every permission, and cannot be lowered.
 * Everyone else has only the class a Manager gave them, whatever their WordPress role is.
 *
 *   manager  everything
 *   writer   sees every song, but edits and shares only the songs they are credited on
 *   viewer   sees every song, changes nothing
 */
class Songpress_Caps {
	const META = 'songpress_class';

	const CLASSES = array(
		'manager' => array( 'Manager', 'Everything: add, edit, archive, share and export any song, money and stats, import, settings and members. WordPress administrators are always Managers.' ),
		'writer'  => array( 'Writer', 'Sees every song. Edits and shares only the songs they are credited on, and can upload files for them.' ),
		'viewer'  => array( 'Viewer', 'Sees every song. Changes nothing.' ),
	);

	const PERMS = array(
		'manager' => array( 'view_all', 'view_own', 'create', 'edit_any', 'edit_own', 'archive', 'share_any', 'share_own', 'export', 'upload', 'link', 'import', 'settings', 'members', 'artists', 'money', 'journal', 'art' ),
		'writer'  => array( 'view_all', 'view_own', 'edit_own', 'share_own', 'upload' ),
		'viewer'  => array( 'view_all', 'view_own' ),
	);

	private static $cache = array();

	/** Permissions of a class. Pure, no WordPress needed. */
	public static function perms_for( $class ) {
		return self::PERMS[ $class ] ?? array();
	}

	/** The classes a Manager may give to other people. */
	public static function assignable() {
		return self::CLASSES;
	}

	/** True for WordPress administrators (and network super admins). They are Managers automatically. */
	public static function is_wp_admin( $user_id ) {
		return user_can( $user_id, 'manage_options' ) || ( is_multisite() && is_super_admin( $user_id ) );
	}

	/** A user's SongPress class: owner for WordPress administrators, else what was assigned, else '' (no access). */
	public static function class_of( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return '';
		}
		if ( isset( self::$cache[ $user_id ] ) ) {
			return self::$cache[ $user_id ];
		}
		$class = '';
		if ( self::is_wp_admin( $user_id ) ) {
			$class = 'manager';
		} else {
			$set   = (string) get_user_meta( $user_id, self::META, true );
			$class = isset( self::CLASSES[ $set ] ) ? $set : '';
		}
		return self::$cache[ $user_id ] = $class;
	}

	public static function forget( $user_id ) {
		unset( self::$cache[ (int) $user_id ] );
	}

	public static function can( $perm, $user_id = 0 ) {
		return in_array( $perm, self::perms_for( self::class_of( $user_id ) ), true );
	}

	/** May the current user change this song? Managers any song; writers only songs they are credited on. */
	public static function can_edit_song( $song_id ) {
		if ( self::can( 'edit_any' ) ) {
			return true;
		}
		return self::can( 'edit_own' ) && Songpress_Songs::user_is_credited( (int) $song_id, get_current_user_id() );
	}

	public static function can_share_song( $song_id ) {
		if ( self::can( 'share_any' ) ) {
			return true;
		}
		return self::can( 'share_own' ) && Songpress_Songs::user_is_credited( (int) $song_id, get_current_user_id() );
	}

	/** Anyone with a class can open the plugin; what they see depends on the class. */
	public static function can_view() {
		return self::can( 'view_own' );
	}

	/** May the current user see this song? Whole-catalog classes see all; writers only works they are credited on. */
	public static function can_see_song( $song_id ) {
		if ( self::can( 'view_all' ) ) {
			return true;
		}
		return self::can( 'view_own' ) && Songpress_Songs::user_is_credited( (int) $song_id, get_current_user_id() );
	}

	public static function assign( $user_id, $class ) {
		if ( '' === $class ) {
			delete_user_meta( $user_id, self::META );
		} elseif ( isset( self::assignable()[ $class ] ) ) {
			update_user_meta( $user_id, self::META, $class );
		}
		self::forget( $user_id );
	}

	/**
	 * WordPress itself needs two permissions for people who only exist in SongPress: `read` (to open the dashboard
	 * area at all) and `upload_files` (so the Media Library picker works). They are given only while the person is
	 * using a SongPress screen or SongPress's own upload, and only if their class allows it.
	 */
	public static function filter_user_caps( $allcaps, $caps, $args, $user ) {
		if ( empty( $user->ID ) || ! empty( $allcaps['manage_options'] ) ) {
			return $allcaps;
		}
		// class_of() asks WordPress whether the user is an administrator, which runs this same filter again. Skip that inner pass.
		static $busy = false;
		if ( $busy ) {
			return $allcaps;
		}
		$busy  = true;
		$class = self::class_of( $user->ID );
		$busy  = false;
		if ( '' === $class ) {
			return $allcaps;
		}
		$allcaps['read'] = true;
		if ( in_array( 'upload', self::perms_for( $class ), true ) && self::on_songpress_request() ) {
			$allcaps['upload_files'] = true;
		}
		return $allcaps;
	}

	private static function on_songpress_request() {
		$page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 0 === strpos( $page, 'songpress' ) ) {
			return true;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'songpress_upload' === $action ) {
			return true;
		}
		// The media picker talks to admin-ajax from the song screen.
		if ( in_array( $action, array( 'query-attachments', 'upload-attachment', 'get-attachment' ), true ) ) {
			$ref = wp_get_referer();
			return $ref && false !== strpos( $ref, 'page=songpress' );
		}
		return false;
	}

	/** One-time move from the old design (a WordPress role plus two capabilities) to SongPress classes. */
	public static function migrate_old_roles() {
		if ( get_option( 'songpress_classes_migrated' ) ) {
			return;
		}
		foreach ( get_users( array( 'role' => 'songpress_writer', 'fields' => 'ID' ) ) as $uid ) {
			if ( '' === (string) get_user_meta( $uid, self::META, true ) ) {
				update_user_meta( $uid, self::META, 'writer' );
			}
			$u = get_userdata( $uid );
			if ( $u ) {
				$u->set_role( 'subscriber' );
			}
		}
		foreach ( get_users( array( 'role' => 'editor', 'fields' => 'ID' ) ) as $uid ) { // editors could manage songs before
			if ( '' === (string) get_user_meta( $uid, self::META, true ) ) {
				update_user_meta( $uid, self::META, 'manager' );
			}
		}
		remove_role( 'songpress_writer' );
		foreach ( array( 'administrator', 'editor' ) as $name ) {
			$role = get_role( $name );
			if ( $role ) {
				$role->remove_cap( 'songpress_manage' );
				$role->remove_cap( 'songpress_view' );
			}
		}
		update_option( 'songpress_classes_migrated', '1' );
	}

	/**
	 * Second move: the classes were renamed. Old Owner and Manager became Manager; old Editor became Writer (sees all,
	 * edits what they are on); the old own-songs-only Writer is now a Viewer, because that class no longer exists.
	 */
	public static function migrate_class_names() {
		if ( get_option( 'songpress_classes_v2' ) ) {
			return;
		}
		$map = array( 'owner' => 'manager', 'editor' => 'writer', 'writer' => 'viewer' );
		foreach ( get_users( array( 'meta_key' => self::META, 'meta_compare' => 'EXISTS', 'fields' => 'ID' ) ) as $uid ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$old = (string) get_user_meta( $uid, self::META, true );
			if ( isset( $map[ $old ] ) ) {
				update_user_meta( $uid, self::META, $map[ $old ] );
			}
		}
		update_option( 'songpress_classes_v2', '1' );
	}
}
