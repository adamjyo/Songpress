<?php
defined( 'ABSPATH' ) || exit;

/**
 * A plain record of who changed what on a song and when. Nothing here is ever edited or deleted by the plugin.
 * Long text (lyrics, charts, notes) is recorded as "changed" with the size, not copied each time.
 */
class Songpress_History {
	const LONG_FIELDS = array( 'lyrics', 'chords_lyrics', 'chordpro', 'story', 'notes', 'alt_titles' );

	public static function log( $song_id, $action, array $detail = array() ) {
		global $wpdb;
		$wpdb->insert( Songpress_Install::table( 'history' ), array(
			'song_id'    => (int) $song_id,
			'user_id'    => (int) get_current_user_id(),
			'action'     => substr( $action, 0, 24 ),
			'detail'     => $detail ? wp_json_encode( $detail ) : '',
			'created_at' => current_time( 'mysql', true ),
		) );
		if ( class_exists( 'Songpress_Audit' ) ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT serial, title FROM ' . Songpress_Install::table( 'songs' ) . ' WHERE id = %d', (int) $song_id ) ); // phpcs:ignore WordPress.DB
			Songpress_Audit::log( 'songs', ucfirst( $action ), $row ? trim( $row->serial . ' ' . $row->title ) : '', array_intersect_key( $detail, array_flip( array( 'from', 'after' ) ) ) );
		}
	}

	/** Compare old and new values of the listed fields. Returns field => array( old, new ) for what changed. */
	public static function diff( $old, array $new, array $fields ) {
		$out = array();
		foreach ( $fields as $f ) {
			if ( ! array_key_exists( $f, $new ) ) {
				continue;
			}
			$a = (string) ( $old->$f ?? '' );
			$b = (string) $new[ $f ];
			if ( $a === $b ) {
				continue;
			}
			$out[ $f ] = in_array( $f, self::LONG_FIELDS, true )
				? array( strlen( $a ) . ' characters', strlen( $b ) . ' characters' )
				: array( mb_substr( $a, 0, 120 ), mb_substr( $b, 0, 120 ) );
		}
		return $out;
	}

	public static function for_song( $song_id, $limit = 100 ) {
		global $wpdb;
		$t = Songpress_Install::table( 'history' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE song_id = %d ORDER BY id DESC LIMIT %d", $song_id, $limit ) );
	}

	/** One readable line for a history row. */
	public static function describe( $row ) {
		$d = '' !== (string) $row->detail ? json_decode( $row->detail, true ) : array();
		$d = is_array( $d ) ? $d : array();
		switch ( $row->action ) {
			case 'created':
				return __( 'Created', 'songpress' );
			case 'version':
				return __( 'Created as a version of an earlier song', 'songpress' );
			case 'archived':
				return __( 'Archived', 'songpress' );
			case 'restored':
				return __( 'Restored from the archive', 'songpress' );
			case 'imported':
				return __( 'Imported from the old catalog', 'songpress' );
			case 'edited':
				$parts = array();
				foreach ( $d as $field => $pair ) {
					$parts[] = in_array( $field, self::LONG_FIELDS, true )
						? ucfirst( str_replace( '_', ' ', $field ) ) . ' changed'
						: ucfirst( str_replace( '_', ' ', $field ) ) . ': ' . ( '' === $pair[0] ? '(empty)' : $pair[0] ) . ' → ' . ( '' === $pair[1] ? '(empty)' : $pair[1] );
				}
				return implode( '; ', $parts );
			case 'sharing':
			case 'people':
			case 'codes':
			case 'links':
				return ucfirst( $row->action ) . ' changed' . ( isset( $d['after'] ) ? ': ' . $d['after'] : '' );
		}
		return $row->action;
	}
}
