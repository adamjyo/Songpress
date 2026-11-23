<?php
defined( 'ABSPATH' ) || exit;

/**
 * An artist is a band, a solo act or a record label that owns songs. One site can hold many.
 * Each artist has its own details (who the writer and publisher are by default) and brand (logo, color, default art, links).
 * Serial numbers are shared across all artists, so every serial is unique on the site.
 */
class Songpress_Artists {
	/** key => type. Settings live in one JSON column so new ones need no database change. */
	const SETTINGS = array(
		'writer_name'     => 'text', 'writer_ipi' => 'text', 'writer_share' => 'number',
		'publisher_name'  => 'text', 'publisher_ipi' => 'text', 'publisher_share' => 'number',
		'accent'          => 'color', 'logo_id' => 'id', 'default_art_id' => 'id',
		'footer'          => 'text', 'pro_name' => 'text', 'pro_url' => 'url',
		'radio_url'       => 'url', 'radio_label' => 'text',
	);

	/** Clean a posted settings array down to known keys. Pure except for the sanitizers WordPress provides. */
	public static function clean_settings( array $in ) {
		$out = array();
		foreach ( self::SETTINGS as $k => $type ) {
			$v = isset( $in[ $k ] ) ? (string) $in[ $k ] : '';
			switch ( $type ) {
				case 'id':
					$out[ $k ] = absint( $v );
					break;
				case 'number':
					$out[ $k ] = ( '' !== trim( $v ) && is_numeric( $v ) && (float) $v >= 0 && (float) $v <= 100 ) ? (float) $v : '';
					break;
				case 'color':
					$c         = function_exists( 'sanitize_hex_color' ) ? sanitize_hex_color( $v ) : ( preg_match( '/^#[0-9a-fA-F]{6}$/', $v ) ? $v : '' );
					$out[ $k ] = $c ? $c : '';
					break;
				case 'url':
					$out[ $k ] = esc_url_raw( $v );
					break;
				default:
					$out[ $k ] = sanitize_text_field( $v );
			}
		}
		return $out;
	}

	private static $synced = false;

	/**
	 * Bands and artists are one list. Every artist has a band page and every band has an artist record, matched by name.
	 * Runs once per request and only writes when something is missing.
	 */
	public static function sync() {
		global $wpdb;
		if ( self::$synced ) {
			return;
		}
		self::$synced = true;
		$at   = Songpress_Install::table( 'artists' );
		$pt   = Songpress_Install::table( 'profiles' );
		$band = array();
		foreach ( (array) $wpdb->get_results( "SELECT name, name_key FROM {$pt} WHERE kind = 'band'" ) as $b ) { // phpcs:ignore WordPress.DB
			$band[ (string) $b->name_key ] = (string) $b->name;
		}
		$have = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, name FROM {$at}" ) as $a ) { // phpcs:ignore WordPress.DB
			$k            = Songpress_Profiles::key( $a->name );
			$have[ $k ]   = true;
			if ( '' !== $k && ! isset( $band[ $k ] ) ) {
				Songpress_Profiles::upsert( 'band', (string) $a->name, array() );
			}
		}
		foreach ( $band as $k => $n ) {
			if ( '' !== $k && ! isset( $have[ $k ] ) ) {
				self::save( 0, $n, array() );
			}
		}
		Songpress_Profiles::reset_cache();
	}

	/** Runs the band/artist matching again (after something was added behind its back). */
	public static function resync() {
		self::$synced = false;
		self::sync();
	}

	public static function all() {
		global $wpdb;
		self::sync();
		return $wpdb->get_results( 'SELECT * FROM ' . Songpress_Install::table( 'artists' ) . ' ORDER BY name' );
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Songpress_Install::table( 'artists' ) . ' WHERE id = %d', $id ) );
		return $row ?: null;
	}

	public static function by_slug( $slug ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Songpress_Install::table( 'artists' ) . ' WHERE slug = %s', $slug ) );
		return $row ?: null;
	}

	/** One setting of one artist, with a fallback. */
	public static function setting( $artist, $key, $fallback = '' ) {
		if ( is_numeric( $artist ) ) {
			$artist = self::get( (int) $artist );
		}
		if ( ! $artist ) {
			return $fallback;
		}
		$s = json_decode( (string) $artist->settings, true );
		return ( is_array( $s ) && isset( $s[ $key ] ) && '' !== $s[ $key ] && 0 !== $s[ $key ] ) ? $s[ $key ] : $fallback;
	}

	public static function default_id() {
		global $wpdb;
		$id = (int) get_option( 'songpress_default_artist' );
		if ( $id && self::get( $id ) ) {
			return $id;
		}
		$id = (int) $wpdb->get_var( 'SELECT id FROM ' . Songpress_Install::table( 'artists' ) . ' ORDER BY id LIMIT 1' );
		return $id ?: self::create_default();
	}

	/** The artist every clean install starts with, named after the site. Rename it any time. */
	public static function create_default() {
		$name = trim( (string) get_bloginfo( 'name' ) );
		$id   = self::save( 0, '' !== $name ? $name : __( 'My catalog', 'songpress' ), array() );
		if ( $id ) {
			update_option( 'songpress_default_artist', $id );
		}
		return (int) $id;
	}

	/** Create (id 0) or update an artist. Returns the id, or 0 on failure. */
	public static function save( $id, $name, array $settings ) {
		global $wpdb;
		$t    = Songpress_Install::table( 'artists' );
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			return 0;
		}
		$json = wp_json_encode( self::clean_settings( $settings ) );
		if ( $id ) {
			$wpdb->update( $t, array( 'name' => $name, 'settings' => $json ), array( 'id' => (int) $id ) );
			return (int) $id;
		}
		$base = sanitize_title( $name );
		$base = '' !== $base ? $base : 'artist';
		$slug = $base;
		for ( $i = 2; self::by_slug( $slug ); $i++ ) {
			$slug = $base . '-' . $i;
		}
		$wpdb->insert( $t, array( 'name' => $name, 'slug' => $slug, 'settings' => $json, 'created_at' => current_time( 'mysql', true ) ) );
		return (int) $wpdb->insert_id;
	}

	public static function song_count( $id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Songpress_Install::table( 'songs' ) . ' WHERE artist_id = %d', $id ) );
	}

	/** Only an artist with no songs can be removed. Songs are archived, never moved by accident. */
	public static function delete( $id ) {
		global $wpdb;
		if ( self::song_count( $id ) || count( self::all() ) < 2 ) {
			return false;
		}
		$wpdb->delete( Songpress_Install::table( 'artists' ), array( 'id' => (int) $id ) );
		return true;
	}

	/** Starting rows for a new song of this artist: you as writer, your publisher. Objects shaped like credit rows. */
	public static function starting_credits( $artist_id ) {
		$a   = self::get( $artist_id );
		$out = array( 'writer' => array(), 'publisher' => array() );
		if ( ! $a ) {
			return $out;
		}
		$w = self::setting( $a, 'writer_name' );
		if ( '' !== $w ) {
			$out['writer'][] = (object) array( 'name' => $w, 'ipi' => self::setting( $a, 'writer_ipi' ), 'share' => self::setting( $a, 'writer_share', '' ), 'user_id' => null );
		}
		$p = self::setting( $a, 'publisher_name' );
		if ( '' !== $p ) {
			$out['publisher'][] = (object) array( 'name' => $p, 'ipi' => self::setting( $a, 'publisher_ipi' ), 'share' => self::setting( $a, 'publisher_share', '' ), 'user_id' => null );
		}
		return $out;
	}
}
