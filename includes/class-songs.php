<?php
defined( 'ABSPATH' ) || exit;

/** Reads and writes songs. Every read takes a user so a writer can never list someone else's work. */
class Songpress_Songs {

	const STATUSES = array(
		'idea'     => 'Idea',
		'writing'  => 'Writing',
		'finished' => 'Finished',
		'recorded' => 'Recorded',
		'released' => 'Released',
		'retired'  => 'Retired',
	);

	/** Release information. Only versions have it. The original work (version 0) does not. */
	const RELEASE_FIELDS      = array( 'artist', 'album', 'release_title', 'release_date' );
	const RELEASE_IDENTIFIERS = array( 'isrc', 'distrokid' );

	/** The statuses a song may have. The original work can never be released. */
	public static function statuses_for( $version_no ) {
		$s = self::STATUSES;
		if ( 0 === (int) $version_no ) {
			unset( $s['released'] );
		}
		return $s;
	}

	/** Columns a form may set. Anything else is ignored. */
	const FIELDS = array(
		'title', 'alt_titles', 'status', 'stage', 'writing_date', 'musical_key', 'tempo', 'time_signature', 'genre',
		'artist', 'album', 'release_title', 'release_date', 'lyrics', 'chords_lyrics', 'chordpro', 'story', 'notes',
		'audio_url', 'audio_id', 'art_id', 'art_url',
	);

	public static function user_is_credited( $song_id, $user_id ) {
		global $wpdb;
		if ( ! $user_id ) {
			return false;
		}
		$t = Songpress_Install::table( 'credits' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE song_id = %d AND user_id = %d LIMIT 1", $song_id, $user_id ) );
	}

	public static function get( $id ) {
		global $wpdb;
		$t = Songpress_Install::table( 'songs' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	public static function credits( $song_id ) {
		global $wpdb;
		$t = Songpress_Install::table( 'credits' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE song_id = %d ORDER BY role, position, id", $song_id ) );
	}

	/** "You, Writer Two" style line for list rows. Writers only; the viewer's own name shows as "You". */
	public static function authors_line( $song_id, $viewer_id = 0 ) {
		$names = array();
		foreach ( self::credits( $song_id ) as $c ) {
			if ( 'writer' !== $c->role || '' === $c->name ) {
				continue;
			}
			$names[] = ( $viewer_id && (int) $c->user_id === (int) $viewer_id ) ? __( 'You', 'songpress' ) : $c->name;
		}
		return implode( ', ', $names );
	}

	/**
	 * @param array $args search, status, family_id, page, per_page, user_id (limit to works this user is credited on)
	 * @return array{rows:array,total:int}
	 */
	public static function query( array $args ) {
		global $wpdb;
		$s     = Songpress_Install::table( 'songs' );
		$c     = Songpress_Install::table( 'credits' );
		$where = array( '1=1' );
		$vals  = array();
		$arch = $args['archived'] ?? 0;
		if ( 'any' !== $arch ) {
			$where[] = $arch ? 's.archived_at IS NOT NULL' : 's.archived_at IS NULL';
		}
		if ( ! empty( $args['user_id'] ) ) {
			$where[] = "s.id IN (SELECT song_id FROM {$c} WHERE user_id = %d)";
			$vals[]  = (int) $args['user_id'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where[] = 's.status = %s';
			$vals[]  = $args['status'];
		}
		if ( ! empty( $args['family_id'] ) ) {
			$where[] = 's.family_id = %d';
			$vals[]  = (int) $args['family_id'];
		}
		if ( isset( $args['search'] ) && '' !== trim( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( trim( $args['search'] ) ) . '%';
			$where[] = '(s.title LIKE %s OR s.serial LIKE %s OR s.artist LIKE %s)';
			array_push( $vals, $like, $like, $like );
		}
		$per    = max( 1, min( 100, (int) ( $args['per_page'] ?? 25 ) ) );
		$offset = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per );
		$sql    = implode( ' AND ', $where );

		$total_sql = "SELECT COUNT(*) FROM {$s} s WHERE {$sql}";
		$rows_sql  = "SELECT s.* FROM {$s} s WHERE {$sql} ORDER BY s.family_id DESC, s.version_no DESC LIMIT %d OFFSET %d";
		$total     = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $total_sql, $vals ) ) : $wpdb->get_var( $total_sql ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $vals, array( $per, $offset ) ) ) );
		return array( 'rows' => $rows, 'total' => $total );
	}

	/** Clean a posted array down to the allowed columns. */
	public static function sanitize( array $in ) {
		$out = array();
		$long = array( 'lyrics', 'chords_lyrics', 'chordpro', 'story', 'notes', 'alt_titles' );
		foreach ( self::FIELDS as $f ) {
			if ( ! array_key_exists( $f, $in ) ) {
				continue;
			}
			$v = wp_unslash( $in[ $f ] );
			if ( in_array( $f, $long, true ) ) {
				$out[ $f ] = sanitize_textarea_field( $v );
				// sanitize_textarea_field keeps line breaks; chord charts need leading spaces, which it also keeps.
			} elseif ( in_array( $f, array( 'audio_url', 'art_url' ), true ) ) {
				$out[ $f ] = esc_url_raw( $v );
			} elseif ( in_array( $f, array( 'art_id', 'audio_id' ), true ) ) {
				$out[ $f ] = absint( $v ) ?: null;
			} elseif ( 'status' === $f ) {
				$out[ $f ] = isset( self::STATUSES[ $v ] ) ? $v : 'idea';
			} else {
				$out[ $f ] = sanitize_text_field( $v );
			}
		}
		return $out;
	}

	/** New song: gets the next family number, version 00. @return int|WP_Error song id */
	public static function create( array $data, $owner_id ) {
		$id = self::insert( true, 0, $data, $owner_id );
		if ( ! is_wp_error( $id ) ) {
			Songpress_History::log( $id, 'created' );
		}
		return $id;
	}

	/** Copy of an existing song as the next version of its family. @return int|WP_Error song id */
	public static function create_version( $from_id, $owner_id ) {
		$from = self::get( $from_id );
		if ( ! $from ) {
			return new WP_Error( 'songpress_missing', __( 'That song does not exist.', 'songpress' ) );
		}
		$data = array();
		foreach ( self::FIELDS as $f ) {
			$data[ $f ] = $from->$f;
		}
		// A new version is a new recording or arrangement: it starts unreleased, so release information is not carried over.
		foreach ( self::RELEASE_FIELDS as $f ) {
			if ( 'artist' !== $f ) {
				$data[ $f ] = '';
			}
		}
		$data['status'] = 'writing';
		$new = self::insert( false, (int) $from->family_id, $data, $owner_id );
		if ( ! is_wp_error( $new ) ) {
			self::copy_children( (int) $from->id, (int) $new );
			Songpress_History::log( $new, 'version', array( 'from' => $from->serial ) );
		}
		return $new;
	}

	private static function insert( $new_family, $family_id, array $data, $owner_id ) {
		global $wpdb;
		$songs = Songpress_Install::table( 'songs' );
		$now   = current_time( 'mysql', true );
		$result = Songpress_Serial::allocate( $new_family, $family_id, function ( $fid, $fno, $ver, $serial ) use ( $wpdb, $songs, $data, $owner_id, $now ) {
			$row = array_merge( $data, array(
				'family_id'  => $fid,
				'version_no' => $ver,
				'serial'     => $serial,
				'owner_id'   => (int) $owner_id,
				'created_at' => $now,
				'updated_at' => $now,
			) );
			$ok = $wpdb->insert( $songs, $row );
			return $ok ? (int) $wpdb->insert_id : new WP_Error( 'songpress_db', __( 'The song could not be saved.', 'songpress' ) );
		} );
		return $result;
	}

	public static function update( $id, array $data ) {
		global $wpdb;
		$t    = Songpress_Install::table( 'songs' );
		$data = array_intersect_key( $data, array_flip( self::FIELDS ) );
		$was  = self::get( $id );
		$data['updated_at'] = current_time( 'mysql', true );
		$ok = false !== $wpdb->update( $t, $data, array( 'id' => (int) $id ) );
		// Every save bumps the revision. The editor compares it to detect that someone else saved first.
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET revision = revision + 1 WHERE id = %d", $id ) );
		if ( $was ) {
			$changes = Songpress_History::diff( $was, $data, self::FIELDS );
			if ( $changes ) {
				Songpress_History::log( $id, 'edited', $changes );
			}
		}
		return $ok;
	}

	/** Songs are archived, never deleted: serial numbers stay taken and the history stays. Archiving an original archives its versions too. */
	public static function archive( $id ) {
		return self::set_archived( $id, true );
	}

	public static function restore( $id ) {
		return self::set_archived( $id, false );
	}

	private static function set_archived( $id, $archive ) {
		global $wpdb;
		$t    = Songpress_Install::table( 'songs' );
		$song = self::get( $id );
		if ( ! $song ) {
			return 0;
		}
		$ids = array( (int) $song->id );
		if ( $archive && 0 === (int) $song->version_no ) {
			$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE family_id = %d AND archived_at IS NULL", $song->family_id ) ) );
		}
		$n = 0;
		foreach ( $ids as $one ) {
			$wpdb->query( $archive
				? $wpdb->prepare( "UPDATE {$t} SET archived_at = %s, revision = revision + 1 WHERE id = %d", current_time( 'mysql', true ), $one )
				: $wpdb->prepare( "UPDATE {$t} SET archived_at = NULL, revision = revision + 1 WHERE id = %d", $one ) );
			Songpress_History::log( $one, $archive ? 'archived' : 'restored' );
			++$n;
		}
		return $n;
	}

	/** Sharing: a song has a public link only while it has a token. Turning sharing off, or making a new link, kills the old one. */
	public static function share_set( $id, $on ) {
		global $wpdb;
		$t     = Songpress_Install::table( 'songs' );
		$token = $on ? wp_generate_password( 24, false, false ) : null;
		$wpdb->query( $on
			? $wpdb->prepare( "UPDATE {$t} SET share_token = %s WHERE id = %d", $token, $id )
			: $wpdb->prepare( "UPDATE {$t} SET share_token = NULL WHERE id = %d", $id ) );
		Songpress_History::log( $id, 'sharing', array( 'after' => $on ? 'link on' : 'link off' ) );
		return $token;
	}

	/** A shared song, or null. Archived songs are never served. */
	public static function by_token( $token ) {
		global $wpdb;
		if ( ! preg_match( '/^[A-Za-z0-9]{24}$/', (string) $token ) ) {
			return null;
		}
		$t = Songpress_Install::table( 'songs' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE share_token = %s AND archived_at IS NULL", $token ) ) ?: null;
	}

	public static function count_archived() {
		global $wpdb;
		$t = Songpress_Install::table( 'songs' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE archived_at IS NOT NULL" );
	}

	/** Short text of who is credited, for comparing before and after a save and for the history. */
	private static function credit_signature( $credits ) {
		$parts = array();
		foreach ( $credits as $c ) {
			$parts[] = $c->role . ' ' . $c->name . ( null !== $c->share && '' !== (string) $c->share ? ' ' . rtrim( rtrim( number_format( (float) $c->share, 4, '.', '' ), '0' ), '.' ) . '%' : '' );
		}
		return mb_substr( implode( ', ', $parts ), 0, 480 );
	}

	public static function replace_credits( $song_id, array $rows ) {
		global $wpdb;
		$t      = Songpress_Install::table( 'credits' );
		$before = self::credit_signature( self::credits( $song_id ) );
		$wpdb->delete( $t, array( 'song_id' => (int) $song_id ) );
		foreach ( array_values( $rows ) as $pos => $r ) {
			$name = sanitize_text_field( $r['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$role = in_array( $r['role'] ?? '', array( 'writer', 'publisher', 'performer', 'producer' ), true ) ? $r['role'] : 'writer';
			$share = ( isset( $r['share'] ) && '' !== $r['share'] && is_numeric( $r['share'] ) ) ? (float) $r['share'] : null;
			$wpdb->insert( $t, array(
				'song_id'  => (int) $song_id,
				'role'     => $role,
				'name'     => $name,
				'ipi'      => sanitize_text_field( $r['ipi'] ?? '' ),
				'share'    => $share,
				'user_id'  => ! empty( $r['user_id'] ) ? absint( $r['user_id'] ) : null,
				'position' => $pos,
			) );
		}
		$after = self::credit_signature( self::credits( $song_id ) );
		if ( $after !== $before ) {
			Songpress_History::log( $song_id, 'people', array( 'after' => $after ) );
		}
	}

	private static function copy_children( $from, $to ) {
		global $wpdb;
		// Each version has its own people. Writers and publishers (with their splits) start as a copy so they can be changed
		// without touching the original or any other version. Performers and producers differ per version, so they start empty.
		$cr = Songpress_Install::table( 'credits' );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$cr} (song_id, role, name, ipi, share, user_id, position) SELECT %d, role, name, ipi, share, user_id, position FROM {$cr} WHERE song_id = %d AND role IN ('writer','publisher')", $to, $from ) );
		// Work-level codes (ISWC, CCLI, MLC...) follow the song. Release codes (ISRC, DistroKid) and streaming links do not.
		$id = Songpress_Install::table( 'identifiers' );
		$in = implode( ',', array_fill( 0, count( self::RELEASE_IDENTIFIERS ), '%s' ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$id} (song_id, kind, value) SELECT %d, kind, value FROM {$id} WHERE song_id = %d AND kind NOT IN ({$in})", array_merge( array( $to, $from ), self::RELEASE_IDENTIFIERS ) ) );
	}

	public static function export_header() {
		return array( 'serial', 'title', 'status', 'archived', 'key', 'tempo', 'genre', 'artist', 'album', 'release_title', 'release_date', 'writers', 'publishers', 'performers', 'producers', 'codes', 'links', 'audio_url', 'notes' );
	}

	/** One CSV row. People are "Name 50%" joined by "; ". Cells that start with = + - @ get a leading apostrophe so a spreadsheet never runs them as formulas. */
	public static function export_row( $s ) {
		$by = array( 'writer' => array(), 'publisher' => array(), 'performer' => array(), 'producer' => array() );
		foreach ( self::credits( (int) $s->id ) as $c ) {
			$share = ( null !== $c->share && '' !== (string) $c->share ) ? ' ' . rtrim( rtrim( number_format( (float) $c->share, 4, '.', '' ), '0' ), '.' ) . '%' : '';
			$by[ $c->role ][] = $c->name . $share;
		}
		$codes = array();
		foreach ( self::identifiers( (int) $s->id ) as $i ) {
			$codes[] = strtoupper( $i->kind ) . ' ' . $i->value;
		}
		$links = array();
		foreach ( self::links( (int) $s->id ) as $l ) {
			$links[] = $l->url;
		}
		$row = array( $s->serial, $s->title, $s->status, $s->archived_at ? 'yes' : '', $s->musical_key, $s->tempo, $s->genre, $s->artist, $s->album, $s->release_title, $s->release_date,
			implode( '; ', $by['writer'] ), implode( '; ', $by['publisher'] ), implode( '; ', $by['performer'] ), implode( '; ', $by['producer'] ), implode( '; ', $codes ), implode( '; ', $links ), Songpress_Media::audio_url( $s ), $s->notes );
		return array_map( function ( $v ) {
			$v = (string) $v;
			return ( '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ) ? "'" . $v : $v;
		}, $row );
	}

	const CODE_KINDS = array( 'iswc', 'isrc', 'ccli', 'mlc', 'bmi', 'ascap', 'work_id', 'copyright', 'distrokid', 'upc' );
	const LINK_KINDS = array( 'spotify', 'apple', 'youtube', 'bandcamp', 'soundcloud', 'distrokid', 'other' );

	public static function identifiers( $song_id ) {
		global $wpdb;
		$t = Songpress_Install::table( 'identifiers' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT kind, value FROM {$t} WHERE song_id = %d ORDER BY id", $song_id ) );
	}

	public static function links( $song_id ) {
		global $wpdb;
		$t = Songpress_Install::table( 'links' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT kind, url FROM {$t} WHERE song_id = %d ORDER BY id", $song_id ) );
	}

	/**
	 * Replace a song's codes. Any number, any kind. Release codes (ISRC, DistroKid) are dropped from an original work,
	 * because the original has no release information. Returns the number of codes dropped for that reason.
	 */
	public static function replace_identifiers( $song_id, array $rows, $version_no ) {
		global $wpdb;
		$t       = Songpress_Install::table( 'identifiers' );
		$dropped = 0;
		$before  = self::pair_signature( self::identifiers( $song_id ), 'value' );
		$wpdb->delete( $t, array( 'song_id' => $song_id ) );
		foreach ( $rows as $r ) {
			$kind  = strtolower( preg_replace( '/[^a-z0-9_]/i', '', sanitize_text_field( $r['kind'] ?? '' ) ) );
			$value = trim( sanitize_text_field( $r['value'] ?? '' ) );
			if ( '' === $kind || '' === $value ) {
				continue;
			}
			if ( 0 === (int) $version_no && in_array( $kind, self::RELEASE_IDENTIFIERS, true ) ) {
				++$dropped;
				continue;
			}
			$wpdb->insert( $t, array( 'song_id' => $song_id, 'kind' => substr( $kind, 0, 16 ), 'value' => substr( $value, 0, 64 ) ) );
		}
		$after = self::pair_signature( self::identifiers( $song_id ), 'value' );
		if ( $after !== $before ) {
			Songpress_History::log( $song_id, 'codes', array( 'after' => $after ) );
		}
		return $dropped;
	}

	public static function replace_links( $song_id, array $rows ) {
		global $wpdb;
		$t      = Songpress_Install::table( 'links' );
		$before = self::pair_signature( self::links( $song_id ), 'url' );
		$wpdb->delete( $t, array( 'song_id' => $song_id ) );
		foreach ( $rows as $r ) {
			$url = trim( esc_url_raw( $r['url'] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}
			$kind = strtolower( preg_replace( '/[^a-z0-9_]/i', '', sanitize_text_field( $r['kind'] ?? '' ) ) );
			$wpdb->insert( $t, array( 'song_id' => $song_id, 'kind' => substr( '' === $kind ? 'other' : $kind, 0, 16 ), 'url' => $url ) );
		}
		$after = self::pair_signature( self::links( $song_id ), 'url' );
		if ( $after !== $before ) {
			Songpress_History::log( $song_id, 'links', array( 'after' => $after ) );
		}
	}

	private static function pair_signature( $rows, $field ) {
		$parts = array();
		foreach ( $rows as $r ) {
			$parts[] = $r->kind . ' ' . $r->$field;
		}
		return mb_substr( implode( ', ', $parts ), 0, 480 );
	}

	/** Which chart to show, first match wins: ChordPro, then chords and lyrics, then lyrics only. */
	public static function best_chart( $song ) {
		if ( '' !== trim( (string) $song->chordpro ) ) {
			return array( 'chordpro', $song->chordpro );
		}
		if ( '' !== trim( (string) $song->chords_lyrics ) ) {
			return array( 'chords_lyrics', $song->chords_lyrics );
		}
		if ( '' !== trim( (string) $song->lyrics ) ) {
			return array( 'lyrics', $song->lyrics );
		}
		return array( 'none', '' );
	}
}
