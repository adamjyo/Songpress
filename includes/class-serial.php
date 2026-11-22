<?php
/**
 * Serial numbers. The original work is just NNNN (version 0). Each version after it is NNNN-VV: 0012, 0012-01, 0012-02.
 * NNNN is the family (one song idea), VV the version of it. The original carries no release information.
 * Pure functions plus an allocator that reserves the next number under a database lock.
 */
defined( 'ABSPATH' ) || defined( 'SONGPRESS_TESTING' ) || exit;

class Songpress_Serial {

	/** @return array{family:int,version:int}|null */
	public static function parse( $serial ) {
		$serial = trim( (string) $serial );
		if ( preg_match( '/^(\d{1,6})(?:\s*-\s*(\d{1,3}))?$/', $serial, $m ) ) {
			return array( 'family' => (int) $m[1], 'version' => isset( $m[2] ) ? (int) $m[2] : 0 );
		}
		return null;
	}

	/** Version 0 is the original work and has no suffix. */
	public static function format( $family, $version ) {
		return $version > 0 ? sprintf( '%04d-%02d', (int) $family, (int) $version ) : sprintf( '%04d', (int) $family );
	}

	/** Normalizes "12", "0012-00", "12-3" and "0012 - 03" to "0012" or "0012-03". Returns null if it is not a serial. */
	public static function normalize( $serial ) {
		$p = self::parse( $serial );
		return $p ? self::format( $p['family'], $p['version'] ) : null;
	}

	/**
	 * Reserve the next number and run $insert( $family_id, $family_no, $version_no, $serial ) while holding the lock.
	 * $new_family true: a new family number. false: the next version of $family_id.
	 *
	 * @return mixed whatever $insert returns, or WP_Error when the lock cannot be taken.
	 */
	public static function allocate( $new_family, $family_id, callable $insert ) {
		global $wpdb;
		$families = Songpress_Install::table( 'families' );
		$songs    = Songpress_Install::table( 'songs' );
		$lock     = 'songpress_serial_' . $wpdb->prefix;
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
			return new WP_Error( 'songpress_lock', __( 'Another save is in progress. Try again in a moment.', 'songpress' ) );
		}
		try {
			if ( $new_family ) {
				$family_no = 1 + (int) $wpdb->get_var( "SELECT MAX(family_no) FROM {$families}" );
				$wpdb->insert( $families, array( 'family_no' => $family_no, 'created_at' => current_time( 'mysql', true ) ) );
				$family_id = (int) $wpdb->insert_id;
				$version   = 0;
			} else {
				$family_no = (int) $wpdb->get_var( $wpdb->prepare( "SELECT family_no FROM {$families} WHERE id = %d", $family_id ) );
				if ( ! $family_no ) {
					return new WP_Error( 'songpress_family', __( 'That song family does not exist.', 'songpress' ) );
				}
				$version = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(version_no) FROM {$songs} WHERE family_id = %d", $family_id ) );
			}
			return $insert( $family_id, $family_no, $version, self::format( $family_no, $version ) );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}
