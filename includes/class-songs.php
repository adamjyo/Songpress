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
		'starter'         => 'Starter',
		'scrapped'        => 'Scrapped',
		'scratch'         => 'Scratch',
		'studio_demo'     => 'Studio Demo',
		'pre_production'  => 'Pre Production',
		'production'      => 'Production',
		'post_production' => 'Post Production',
		'taken_down'      => 'Taken Down',
		'outside'         => 'Outside',
	);
	/** Genres from the old work editor, always offered beside any the Manager adds. */
	const GENRES = array( 'Folk', 'Country', 'Pop', 'Rock', 'Americana', 'R&B', 'Hip-Hop', 'Gospel', 'Jazz', 'Electronic', 'Soundtrack', 'Other' );

	/** Release information. Only versions have it. The original work (version 0) does not. */
	const RELEASE_FIELDS      = array( 'artist', 'album', 'release_title', 'release_date' );
	/** What every version shares. Edited on any of them, it is copied to all the others. */
	const SHARED_FIELDS = array( 'title', 'alt_titles', 'writing_date', 'copyright_date', 'lyrics', 'chords_lyrics', 'chordpro', 'story', 'themes' );
	const SHARED_ROLES  = array( 'writer', 'publisher', 'label' );
	/** Numbers each version keeps for itself: never copied to a new version and never synced. */
	const RELEASE_IDENTIFIERS = array( 'isrc', 'distrokid', 'upc' );
	/** The original work holds none of them: they belong to a recording or release, so they are dropped from an original. */
	const ROOT_DROPPED = array( 'isrc', 'distrokid', 'upc' );

	/** What differs from one version to the next. A new version starts with these empty; everything else is shared and starts as a copy. */
	const VERSION_FIELDS = array( 'artist', 'album', 'release_title', 'release_date', 'musical_key', 'tempo', 'time_signature', 'genre', 'format', 'production_date', 'audio_url', 'audio_id', 'art_id', 'art_url', 'stage', 'style', 'ai_prompt', 'art_prompt', 'notes' );

	/** The statuses in use: the Manager's own list (Lists screen) or the built-in six. key => label. */
	public static function statuses() {
		$o = get_option( 'songpress_statuses', array() );
		return ( is_array( $o ) && $o ) ? $o : self::STATUSES;
	}

	/** The genres the Manager listed, offered as suggestions. A song's genre can still be anything. */
	public static function genres() {
		$o = get_option( 'songpress_genres', array() );
		$o = is_array( $o ) ? array_values( $o ) : array();
		foreach ( self::GENRES as $g ) {
			if ( ! in_array( strtolower( $g ), array_map( 'strtolower', $o ), true ) ) {
				$o[] = $g;
			}
		}
		return $o;
	}

	/** The statuses a song may have. The original work can never be released. */
	public static function statuses_for( $version_no ) {
		$s = self::statuses();
		if ( 0 === (int) $version_no ) {
			unset( $s['released'] );
		}
		return $s;
	}

	/** Columns a form may set. Anything else is ignored. */
	const FIELDS = array(
		'title', 'alt_titles', 'status', 'stage', 'writing_date', 'musical_key', 'tempo', 'time_signature', 'genre',
		'artist', 'album', 'release_title', 'release_date', 'copyright_date', 'production_date', 'format', 'style', 'themes', 'ai_prompt', 'art_prompt', 'lyrics', 'chords_lyrics', 'chordpro', 'story', 'notes',
		'audio_url', 'audio_id', 'art_id', 'art_url', 'artist_id',
	);

	public static function user_is_credited( $song_id, $user_id ) {
		global $wpdb;
		if ( ! $user_id ) {
			return false;
		}
		$t = Songpress_Install::table( 'credits' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE song_id = %d AND user_id = %d LIMIT 1", $song_id, $user_id ) );
	}

	/**
	 * Every version carries the original work's title. A title a version had of its own (for example "Land Of My Father (R&B)")
	 * moves to its release title when that is empty, so the name is kept. Returns the number of versions changed.
	 */
	public static function unify_titles() {
		global $wpdb;
		$t = Songpress_Install::table( 'songs' );
		$n = 0;
		foreach ( (array) $wpdb->get_results( "SELECT id, family_id, title FROM {$t} WHERE version_no = 0 AND title <> ''" ) as $o ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, title, release_title FROM {$t} WHERE family_id = %d AND version_no > 0 AND title <> %s", (int) $o->family_id, $o->title ) ) as $v ) {
				$set = array( 'title' => $o->title );
				if ( '' !== trim( (string) $v->title ) && '' === trim( (string) $v->release_title ) ) {
					$set['release_title'] = $v->title;
				}
				$wpdb->update( $t, $set, array( 'id' => (int) $v->id ) );
				++$n;
			}
		}
		return $n;
	}

	/** A version with no title of its own shows its original work's title. */
	public static function display_title( $s ) {
		if ( '' !== trim( (string) $s->title ) ) {
			return $s->title;
		}
		global $wpdb;
		static $memo = array();
		$fid = (int) $s->family_id;
		if ( ! isset( $memo[ $fid ] ) ) {
			$tbl         = Songpress_Install::table( 'songs' );
			$memo[ $fid ] = (string) $wpdb->get_var( $wpdb->prepare( "SELECT title FROM {$tbl} WHERE family_id = %d AND title <> '' ORDER BY version_no ASC LIMIT 1", $fid ) );
		}
		return $memo[ $fid ];
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
		if ( self::ARCHIVING && 'any' !== $arch ) {
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
		if ( ! empty( $args['artist_id'] ) ) {
			$where[] = 's.artist_id = %d';
			$vals[]  = (int) $args['artist_id'];
		}
		if ( ! empty( $args['family_id'] ) ) {
			$where[] = 's.family_id = %d';
			$vals[]  = (int) $args['family_id'];
		}
		if ( isset( $args['search'] ) && '' !== trim( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( trim( $args['search'] ) ) . '%';
			$where[] = "(s.title LIKE %s OR s.serial LIKE %s OR s.artist LIKE %s OR s.id IN (SELECT lb.song_id FROM {$c} lb WHERE lb.role = 'label' AND lb.name LIKE %s))";
			array_push( $vals, $like, $like, $like, $like );
		}
		$per    = max( 1, min( 100, (int) ( $args['per_page'] ?? 25 ) ) );
		$offset = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per );
		$sql    = implode( ' AND ', $where );

		$total_sql = "SELECT COUNT(*) FROM {$s} s WHERE {$sql}";
		$dir   = ( isset( $args['dir'] ) && 'desc' === $args['dir'] ) ? 'DESC' : 'ASC';
		$cols  = array(
			'serial' => 's.serial',
			'title'  => 's.title',
			'genre'  => 's.genre',
			'artist' => 's.artist',
			'status' => 's.status',
			'label'  => "(SELECT MIN(l.name) FROM {$c} l WHERE l.song_id = s.id AND l.role = 'label')",
			'writer' => "(SELECT MIN(w.name) FROM {$c} w WHERE w.song_id = s.id AND w.role = 'writer')",
		);
		$order = isset( $args['order'], $cols[ $args['order'] ] ) ? $cols[ $args['order'] ] . " {$dir}, s.serial ASC" : 's.family_id DESC, s.version_no DESC';
		$rows_sql  = "SELECT s.* FROM {$s} s WHERE {$sql} ORDER BY {$order} LIMIT %d OFFSET %d";
		$total     = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $total_sql, $vals ) ) : $wpdb->get_var( $total_sql ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $vals, array( $per, $offset ) ) ) );
		return array( 'rows' => $rows, 'total' => $total );
	}

	/** Fields the old catalog had that earlier loads dropped. Filled in on an already-imported song only where it is still blank. */
	const BACKFILL = array( 'copyright_date', 'production_date', 'format', 'style', 'themes', 'ai_prompt', 'art_prompt' );

	/** Returns 1 if the song (found by its old id) got something, else 0. Never overwrites anything you typed. */
	public static function fill_blank( $legacy_id, array $incoming ) {
		global $wpdb;
		$t   = Songpress_Install::table( 'songs' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE legacy_id = %d", (int) $legacy_id ) ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return 0;
		}
		$set = array();
		foreach ( self::BACKFILL as $f ) {
			if ( '' !== trim( (string) ( $incoming[ $f ] ?? '' ) ) && '' === trim( (string) ( $row->$f ?? '' ) ) ) {
				$set[ $f ] = $incoming[ $f ];
			}
		}
		if ( ! $set ) {
			return 0;
		}
		$wpdb->update( $t, $set, array( 'id' => (int) $row->id ) );
		return 1;
	}

	/** Clean a posted array down to the allowed columns. */
	public static function sanitize( array $in ) {
		$out = array();
		$long = array( 'lyrics', 'chords_lyrics', 'chordpro', 'story', 'notes', 'alt_titles', 'ai_prompt', 'art_prompt' );
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
			} elseif ( 'artist_id' === $f ) {
				$out[ $f ] = absint( $v );
			} elseif ( in_array( $f, array( 'art_id', 'audio_id' ), true ) ) {
				$out[ $f ] = absint( $v ) ?: null;
			} elseif ( 'status' === $f ) {
				$out[ $f ] = isset( self::statuses()[ $v ] ) ? $v : 'idea';
			} else {
				$out[ $f ] = sanitize_text_field( $v );
			}
		}
		return $out;
	}

	/** New song: gets the next family number, version 00. @return int|WP_Error song id */
	public static function create( array $data, $owner_id ) {
		if ( empty( $data['artist_id'] ) ) {
			$data['artist_id'] = Songpress_Artists::default_id();
		}
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
		// A new version is a new recording or arrangement and its own work: it keeps what is shared (title, lyrics, chords, story, themes,
		// writers and publishers) and starts with its own release, band, key, tempo, genre, art and audio left empty.
		foreach ( self::VERSION_FIELDS as $f ) {
			$data[ $f ] = in_array( $f, array( 'audio_id', 'art_id' ), true ) ? 0 : '';
		}
		$data['status']          = 'writing';
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

	/** Works are never archived in this catalog: every work is active. The archive code stays, switched off, so nothing else changes. */
	const ARCHIVING = false;

	/** Songs are archived, never deleted: serial numbers stay taken and the history stays. Archiving an original archives its versions too. */
	public static function archive( $id ) {
		if ( ! self::ARCHIVING ) {
			return false;
		}
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
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE share_token = %s", $token ) ) ?: null;
	}

	/** One-time: any work marked archived becomes active again. Returns how many were changed. */
	public static function unarchive_all() {
		global $wpdb;
		$t = Songpress_Install::table( 'songs' );
		return (int) $wpdb->query( "UPDATE {$t} SET archived_at = NULL WHERE archived_at IS NOT NULL" );
	}

	public static function count_archived() {
		if ( ! self::ARCHIVING ) {
			return 0;
		}
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

	/** When on, a writer with no account gets one (Viewer class) as the song is saved. The import turns it off and uses the batch button instead. */
	public static $auto_users = true;

	public static function replace_credits( $song_id, array $rows ) {
		global $wpdb;
		if ( self::$auto_users && class_exists( 'Songpress_People' ) && Songpress_People::ready() ) {
			$rows = Songpress_People::link_rows( $rows );
		}
		$t      = Songpress_Install::table( 'credits' );
		$before = self::credit_signature( self::credits( $song_id ) );
		$wpdb->delete( $t, array( 'song_id' => (int) $song_id ) );
		foreach ( array_values( $rows ) as $pos => $r ) {
			$name = sanitize_text_field( $r['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$role = in_array( $r['role'] ?? '', array( 'writer', 'publisher', 'label', 'performer', 'producer' ), true ) ? $r['role'] : 'writer';
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

	/**
	 * One-time catch-up for versions made before the sharing rules (0.43.0). A per-version field that is still identical to the
	 * original's was only a copy, so it is cleared; anything someone typed or changed in the version is kept. Labels are filled in
	 * from the original when the version has none. Status is left alone. Returns the number of versions changed.
	 */
	public static function apply_version_rules() {
		global $wpdb;
		$t    = Songpress_Install::table( 'songs' );
		$cr   = Songpress_Install::table( 'credits' );
		$rows = $wpdb->get_results( "SELECT * FROM {$t} WHERE version_no > 0" );
		$changed = 0;
		foreach ( (array) $rows as $v ) {
			$orig = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE family_id = %d AND version_no = 0", (int) $v->family_id ) );
			if ( ! $orig ) {
				continue;
			}
			$set = array();
			foreach ( self::VERSION_FIELDS as $f ) {
				if ( '' === (string) $orig->$f || '0' === (string) $orig->$f ) {
					continue;
				}
				if ( (string) $v->$f === (string) $orig->$f ) {
					$set[ $f ] = in_array( $f, array( 'audio_id', 'art_id' ), true ) ? 0 : '';
				}
			}
			if ( $set ) {
				$wpdb->update( $t, $set, array( 'id' => (int) $v->id ) );
			}
			$has = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$cr} WHERE song_id = %d AND role = 'label'", (int) $v->id ) );
			if ( ! $has ) {
				$wpdb->query( $wpdb->prepare( "INSERT INTO {$cr} (song_id, role, name, ipi, share, user_id, position) SELECT %d, role, name, ipi, share, user_id, position FROM {$cr} WHERE song_id = %d AND role = 'label'", (int) $v->id, (int) $orig->id ) );
			}
			$changed += $set ? 1 : 0;
		}
		return $changed;
	}

	/**
	 * Copy the shared fields, the shared people (writers, publishers, labels) and the work-level codes from one song to every
	 * other song in its family. Per-version fields, performers, producers, links and ISRC, DistroKid and UPC numbers are never touched.
	 * Returns the number of songs updated.
	 */
	public static function sync_shared( $from_id ) {
		global $wpdb;
		$from = self::get( $from_id );
		if ( ! $from ) {
			return 0;
		}
		$t     = Songpress_Install::table( 'songs' );
		$cr    = Songpress_Install::table( 'credits' );
		$idt   = Songpress_Install::table( 'identifiers' );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE family_id = %d AND id <> %d", (int) $from->family_id, (int) $from->id ) );
		$roles = "'" . implode( "','", self::SHARED_ROLES ) . "'";
		$kinds = "'" . implode( "','", self::RELEASE_IDENTIFIERS ) . "'";
		$shared = function ( $rows ) {
			return array_filter( $rows, function ( $c ) {
				return in_array( $c->role, self::SHARED_ROLES, true );
			} );
		};
		$sig_a = self::credit_signature( $shared( self::credits( (int) $from->id ) ) );
		$codes = function ( $sid ) {
			$o = array();
			foreach ( self::identifiers( (int) $sid ) as $i ) {
				if ( ! in_array( $i->kind, self::RELEASE_IDENTIFIERS, true ) ) {
					$o[] = $i->kind . ' ' . $i->value;
				}
			}
			sort( $o );
			return implode( ',', $o );
		};
		$code_a = $codes( $from->id );
		$n     = 0;
		foreach ( (array) $ids as $to ) {
			$to  = (int) $to;
			$set = array();
			foreach ( self::SHARED_FIELDS as $f ) {
				$set[ $f ] = $from->$f;
			}
			$old  = self::get( $to );
			$same = $old;
			foreach ( $set as $f => $v ) {
				if ( $old && (string) $old->$f !== (string) $v ) {
					$same = false;
				}
			}
			if ( $same && $sig_a === self::credit_signature( $shared( self::credits( $to ) ) ) && $code_a === $codes( $to ) ) {
				continue;
			}
			$wpdb->update( $t, $set, array( 'id' => $to ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$cr} WHERE song_id = %d AND role IN ({$roles})", $to ) );
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$cr} (song_id, role, name, ipi, share, user_id, position) SELECT %d, role, name, ipi, share, user_id, position FROM {$cr} WHERE song_id = %d AND role IN ({$roles})", $to, (int) $from->id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$idt} WHERE song_id = %d AND kind NOT IN ({$kinds})", $to ) );
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$idt} (song_id, kind, value) SELECT %d, kind, value FROM {$idt} WHERE song_id = %d AND kind NOT IN ({$kinds})", $to, (int) $from->id ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET revision = revision + 1, updated_at = %s WHERE id = %d", current_time( 'mysql', true ), $to ) );
			Songpress_History::log( $to, 'synced', array( 'from' => $from->serial ) );
			++$n;
		}
		return $n;
	}

	private static function copy_children( $from, $to ) {
		global $wpdb;
		// Each version has its own people. Writers, publishers and labels (with their splits) start as a copy so they can be changed
		// without touching the original or any other version. Performers and producers differ per version, so they start empty.
		$cr = Songpress_Install::table( 'credits' );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$cr} (song_id, role, name, ipi, share, user_id, position) SELECT %d, role, name, ipi, share, user_id, position FROM {$cr} WHERE song_id = %d AND role IN ('writer','publisher','label')", $to, $from ) );
		// Work-level codes (ISWC, CCLI, MLC...) follow the song. The ISRC, DistroKid, UPC and streaming links do not.
		$id = Songpress_Install::table( 'identifiers' );
		$in = implode( ',', array_fill( 0, count( self::RELEASE_IDENTIFIERS ), '%s' ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$id} (song_id, kind, value) SELECT %d, kind, value FROM {$id} WHERE song_id = %d AND kind NOT IN ({$in})", array_merge( array( $to, $from ), self::RELEASE_IDENTIFIERS ) ) );
	}

	public static function export_header() {
		return array( 'serial', 'title', 'catalog_owner', 'status', 'archived', 'key', 'tempo', 'genre', 'artist', 'album', 'release_title', 'release_date', 'copyright_date', 'production_date', 'format', 'style', 'themes', 'ai_prompt', 'art_prompt', 'writers', 'publishers', 'labels', 'performers', 'producers', 'codes', 'links', 'audio_url', 'notes' );
	}

	/** One CSV row. People are "Name 50%" joined by "; ". Cells that start with = + - @ get a leading apostrophe so a spreadsheet never runs them as formulas. */
	public static function export_row( $s ) {
		$by = array( 'writer' => array(), 'publisher' => array(), 'label' => array(), 'performer' => array(), 'producer' => array() );
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
		$art = ! empty( $s->artist_id ) ? Songpress_Artists::get( (int) $s->artist_id ) : null;
		$row = array( $s->serial, $s->title, $art ? $art->name : '', $s->status, $s->archived_at ? 'yes' : '', $s->musical_key, $s->tempo, $s->genre, $s->artist, $s->album, $s->release_title, $s->release_date, $s->copyright_date, $s->production_date, $s->format, $s->style, $s->themes, $s->ai_prompt, $s->art_prompt,
			implode( '; ', $by['writer'] ), implode( '; ', $by['publisher'] ), implode( '; ', $by['label'] ), implode( '; ', $by['performer'] ), implode( '; ', $by['producer'] ), implode( '; ', $codes ), implode( '; ', $links ), Songpress_Media::audio_url( $s ), $s->notes );
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
	 * Replace a song's codes. Any number, any kind. The ISRC, DistroKid and UPC are dropped from an original work,
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
			if ( 0 === (int) $version_no && in_array( $kind, self::ROOT_DROPPED, true ) ) {
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

	/**
	 * The lyric process: ChordPro, then chords and lyrics, then lyrics. Each level shows what was typed into it; a blank
	 * level falls back to the highest level that is present, stepped down (chords dropped as it goes).
	 * Returns level => array( text, from ) where from is 'own', the level it was stepped down from, or '' when nothing exists. Pure.
	 */
	public static function lyric_levels( $song ) {
		$cp = trim( (string) ( $song->chordpro ?? '' ) );
		$cl = trim( (string) ( $song->chords_lyrics ?? '' ) );
		$ly = trim( (string) ( $song->lyrics ?? '' ) );
		$out = array(
			'chordpro'      => array( (string) ( $song->chordpro ?? '' ), '' !== $cp ? 'own' : '' ),
			'chords_lyrics' => array( '', '' ),
			'lyrics'        => array( '', '' ),
		);
		if ( '' !== $cl ) {
			$out['chords_lyrics'] = array( (string) $song->chords_lyrics, 'own' );
		} elseif ( '' !== $cp ) {
			$out['chords_lyrics'] = array( Songpress_ChordPro::to_chords_lyrics( $cp ), 'chordpro' );
		}
		if ( '' !== $ly ) {
			$out['lyrics'] = array( (string) $song->lyrics, 'own' );
		} elseif ( '' !== $cl ) {
			$out['lyrics'] = array( Songpress_ChordPro::chords_lyrics_to_lyrics( $cl ), 'chords_lyrics' );
		} elseif ( '' !== $cp ) {
			$out['lyrics'] = array( Songpress_ChordPro::to_lyrics( $cp ), 'chordpro' );
		}
		return $out;
	}
}

