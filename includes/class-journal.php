<?php
defined( 'ABSPATH' ) || exit;

/**
 * Notes, inside the SongPress app (the old S&P Catalog notes app): dated notes about the work, searchable, with pinning,
 * archiving, key, tempo, authors and place, and links to the songs a note mentions (^0378 or #0378). Managers only.
 * The old notes and song ratings can be loaded once from the catalog file.
 */
class Songpress_Journal {

	const PER_PAGE = 40;
	const KINDS    = array( 'day' => 'Day', 'note' => 'Note', 'idea' => 'Idea', 'session' => 'Session' );

	public static function init() {
		add_action( 'admin_post_songpress_journal_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_songpress_journal_flag', array( __CLASS__, 'handle_flag' ) );
		add_action( 'admin_post_songpress_journal_ajax', array( __CLASS__, 'handle_ajax' ) );
		add_action( 'wp_ajax_songpress_journal_ajax', array( __CLASS__, 'handle_ajax' ) );
	}

	private static function t() {
		return Songpress_Install::table( 'journal' );
	}

	/** Pull a serial like 0378 or 0258-01 off the front of a title. Pure. */
	public static function serial_in( $text ) {
		if ( preg_match( '/^\s*#?(\d{3,5})(?:-(\d{1,2}))?\b/', (string) $text, $m ) ) {
			return Songpress_Serial::format( (int) $m[1], isset( $m[2] ) ? (int) $m[2] : 0 );
		}
		return '';
	}

	/** Serials a note mentions as ^0378, ^0378-00 or #0378-02, normalized and without repeats. Pure. */
	public static function serials_in( $text ) {
		$out = array();
		if ( preg_match_all( '/[\^#](\d{3,5})(?:-(\d{1,2}))?\b/', (string) $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$out[ Songpress_Serial::format( (int) $x[1], isset( $x[2] ) ? (int) $x[2] : 0 ) ] = true;
			}
		}
		return array_keys( $out );
	}

	/** The single song a note names, or 0 when it names none or several. $byser maps serial to song id. */
	public static function sole_song( $text, array $byser ) {
		$ids = array();
		foreach ( self::serials_in( $text ) as $s ) {
			if ( isset( $byser[ $s ] ) ) {
				$ids[ $byser[ $s ] ] = true;
			}
		}
		return 1 === count( $ids ) ? (int) array_key_first( $ids ) : 0;
	}

	/** Songs a note mentions: rows of id, serial, title. */
	public static function mentioned( $text ) {
		global $wpdb;
		$ser = self::serials_in( $text );
		if ( ! $ser ) {
			return array();
		}
		$ph = implode( ',', array_fill( 0, count( $ser ), '%s' ) );
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, serial, title FROM ' . Songpress_Install::table( 'songs' ) . " WHERE serial IN ({$ph}) ORDER BY serial", $ser ) ); // phpcs:ignore WordPress.DB
	}

	public static function kind( $k ) {
		return isset( self::KINDS[ $k ] ) ? $k : 'day';
	}

	/* ---------- import ---------- */

	/** Load the old journal and ratings. $pdo is an open connection to the old catalog file. Safe to run again. */
	public static function import_pdo( PDO $pdo ) {
		global $wpdb;
		$out = array( 'journal' => 0, 'ratings' => 0, 'activity' => 0 );
		$has = function ( $table ) use ( $pdo ) {
			$q = $pdo->prepare( "SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?" );
			$q->execute( array( $table ) );
			return (bool) $q->fetchColumn();
		};
		$songs = Songpress_Install::table( 'songs' );
		$byser = array();
		$bylegacy = array();
		foreach ( $wpdb->get_results( "SELECT id, serial, legacy_id FROM {$songs}" ) as $r ) { // phpcs:ignore WordPress.DB
			$byser[ $r->serial ] = (int) $r->id;
			if ( $r->legacy_id ) {
				$bylegacy[ (int) $r->legacy_id ] = (int) $r->id;
			}
		}
		$j = self::t();
		if ( $has( 'work_log' ) ) {
			$rows = array();
			foreach ( $pdo->query( 'SELECT * FROM work_log' ) as $r ) {
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $r['log_date'] ) ) {
					continue;
				}
				$key   = 'old:' . (int) $r['id'];
				$title = trim( (string) ( $r['title'] ?? '' ) );
				$sid   = 0;
				$ser   = self::serial_in( $title );
				if ( '' !== $ser && isset( $byser[ $ser ] ) ) {
					$sid = $byser[ $ser ];
				}
				if ( ! $sid ) {
					$sid = self::sole_song( (string) ( $r['notes'] ?? '' ), $byser );
				}
				$rows[] = array( $key, substr( $r['log_date'], 0, 10 ), self::kind( (string) ( $r['kind'] ?? '' ) ), mb_substr( $title, 0, 255 ), (string) ( $r['notes'] ?? '' ), mb_substr( (string) ( $r['place'] ?? '' ), 0, 191 ), $sid, (int) ! empty( $r['pinned'] ), (int) ! empty( $r['archived'] ),
					mb_substr( trim( (string) ( $r['song_key'] ?? '' ) ), 0, 16 ), mb_substr( trim( (string) ( $r['song_tempo'] ?? '' ) ), 0, 16 ), mb_substr( trim( (string) ( $r['authors'] ?? '' ) ), 0, 255 ), mb_substr( trim( (string) ( $r['latitude'] ?? '' ) ), 0, 24 ), mb_substr( trim( (string) ( $r['longitude'] ?? '' ) ), 0, 24 ), preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', (string) ( $r['created_at'] ?? '' ) ) ? str_replace( 'T', ' ', substr( $r['created_at'], 0, 19 ) ) : current_time( 'mysql' ) );
			}
			// only entries not already here, so a second load never doubles or overwrites edits
			$have = array_flip( $wpdb->get_col( "SELECT source_key FROM {$j} WHERE source_key IS NOT NULL" ) ); // phpcs:ignore WordPress.DB
			foreach ( $rows as $r ) {
				if ( isset( $have[ $r[0] ] ) ) {
					// entries loaded before key, tempo, authors and place existed: fill only what is still blank
					foreach ( array( 9 => 'song_key', 10 => 'tempo', 11 => 'authors', 12 => 'latitude', 13 => 'longitude' ) as $i => $col ) {
						if ( '' !== $r[ $i ] ) {
							$wpdb->query( $wpdb->prepare( "UPDATE {$j} SET {$col} = %s WHERE source_key = %s AND {$col} = ''", $r[ $i ], $r[0] ) ); // phpcs:ignore WordPress.DB -- $col is one of five literals.
						}
					}
					continue;
				}
				$wpdb->insert( $j, array( 'source_key' => $r[0], 'log_date' => $r[1], 'kind' => $r[2], 'title' => $r[3], 'notes' => $r[4], 'place' => $r[5], 'song_id' => $r[6], 'pinned' => $r[7], 'archived' => $r[8], 'song_key' => $r[9], 'tempo' => $r[10], 'authors' => $r[11], 'latitude' => $r[12], 'longitude' => $r[13], 'user_id' => 0, 'created_at' => $r[14] ) );
				$out['journal']++;
			}
		}
		if ( $has( 'work_activity' ) ) {
			$at   = Songpress_Install::table( 'activity' );
			$seen = array_flip( $wpdb->get_col( "SELECT source_key FROM {$at} WHERE source_key <> ''" ) ); // phpcs:ignore WordPress.DB
			foreach ( $pdo->query( 'SELECT * FROM work_activity ORDER BY id' ) as $r ) {
				$k = 'old:' . (int) $r['id'];
				if ( isset( $seen[ $k ] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $r['day'] ) ) {
					continue;
				}
				$sr   = self::serials_in( '#' . (string) $r['serial_number'] );
				$sr   = $sr ? $sr[0] : '';
				$kind = in_array( $r['kind'], array( 'worked', 'wrote', 'released' ), true ) ? $r['kind'] : 'worked';
				$wpdb->insert( $at, array( 'day' => substr( $r['day'], 0, 10 ), 'kind' => $kind, 'song_id' => $byser[ $sr ] ?? 0, 'serial' => $sr, 'title' => mb_substr( (string) $r['song_title'], 0, 255 ), 'detail' => (string) $r['detail'], 'source_key' => $k, 'created_at' => (string) ( $r['created_at'] ?: current_time( 'mysql' ) ) ) );
				$out['activity'] = ( $out['activity'] ?? 0 ) + 1;
			}
		}
		if ( $has( 'song_ratings' ) ) {
			$rt = Songpress_Install::table( 'ratings' );
			foreach ( $pdo->query( 'SELECT song_id, voter, stars, created_at FROM song_ratings' ) as $r ) {
				$sid = $bylegacy[ (int) $r['song_id'] ] ?? 0;
				$st  = (int) $r['stars'];
				if ( ! $sid || $st < 1 || $st > 5 || '' === (string) $r['voter'] ) {
					continue;
				}
				$wpdb->query( $wpdb->prepare( "REPLACE INTO {$rt} (song_id, voter, stars, created_at) VALUES (%d, %s, %d, %s)", $sid, substr( (string) $r['voter'], 0, 64 ), $st, substr( str_replace( 'T', ' ', (string) $r['created_at'] ), 0, 19 ) ) ); // phpcs:ignore WordPress.DB
				$out['ratings']++;
			}
		}
		return $out;
	}

	/* ---------- queries ---------- */

	public static function search( $q, $show, $page ) {
		global $wpdb;
		$j     = self::t();
		$where = array( 'archived = ' . ( 'archived' === $show ? 1 : 0 ) );
		$args  = array();
		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '(title LIKE %s OR notes LIKE %s OR place LIKE %s OR authors LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}
		$w     = implode( ' AND ', $where );
		$count = "SELECT COUNT(*) FROM {$j} WHERE {$w}";
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count, $args ) ) : $wpdb->get_var( $count ) ); // phpcs:ignore WordPress.DB
		$sql   = "SELECT * FROM {$j} WHERE {$w} ORDER BY pinned DESC, log_date DESC, id DESC LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( self::PER_PAGE, max( 0, $page - 1 ) * self::PER_PAGE ) ) ) ); // phpcs:ignore WordPress.DB
		return array( 'rows' => $rows, 'total' => $total );
	}

	public static function top_rated( $limit = 10 ) {
		global $wpdb;
		$r = Songpress_Install::table( 'ratings' );
		$s = Songpress_Install::table( 'songs' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT s.id, s.serial, s.title, AVG(r.stars) AS avg_stars, COUNT(*) AS votes FROM {$r} r JOIN {$s} s ON s.id = r.song_id GROUP BY s.id, s.serial, s.title ORDER BY avg_stars DESC, votes DESC LIMIT %d", (int) $limit ) ); // phpcs:ignore WordPress.DB
	}

	/* ---------- handlers ---------- */

	private static function guard( $nonce ) {
		check_admin_referer( $nonce );
		if ( ! Songpress_Caps::can( 'journal' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
	}

	private static function go( $args = array() ) {
		$ret  = isset( $_POST['sp_return'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['sp_return'] ) ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked by guard().
		$base = '' !== $ret ? $ret : Songpress_Editor::list_url();
		wp_safe_redirect( add_query_arg( array_merge( array( 'sp_page' => 'notes' ), $args ), $base ) );
		exit;
	}

	/** Save one entry from posted fields. Returns array( id, err ). Shared by the form handler and the Notes screen. */
	public static function persist( array $in ) {
		global $wpdb;
		$id    = absint( $in['entry_id'] ?? 0 );
		$notes = (string) ( $in['notes'] ?? '' );
		$title = sanitize_text_field( $in['title'] ?? '' );
		if ( '' === trim( $notes ) && '' === $title && ! $id ) {
			return array( 0, __( 'Write something first.', 'songpress' ) );
		}
		$ser = self::serial_in( $title );
		$sid = 0;
		if ( '' !== $ser ) {
			$sid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Songpress_Install::table( 'songs' ) . ' WHERE serial = %s', $ser ) ); // phpcs:ignore WordPress.DB
		}
		$row = array(
			'log_date' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $in['log_date'] ?? '' ) ) ? $in['log_date'] : gmdate( 'Y-m-d' ),
			'kind'     => self::kind( (string) ( $in['kind'] ?? '' ) ),
			'title'    => $title,
			'notes'    => sanitize_textarea_field( $notes ),
			'place'    => sanitize_text_field( $in['place'] ?? '' ),
			'song_id'  => $sid,
			'song_key' => mb_substr( sanitize_text_field( $in['song_key'] ?? '' ), 0, 16 ),
			'tempo'    => mb_substr( sanitize_text_field( $in['tempo'] ?? '' ), 0, 16 ),
			'authors'  => mb_substr( sanitize_text_field( $in['authors'] ?? '' ), 0, 255 ),
		);
		if ( array_key_exists( 'lat', $in ) ) {
			$la = is_numeric( $in['lat'] ?? '' ) ? (float) $in['lat'] : null;
			$lo = is_numeric( $in['lng'] ?? '' ) ? (float) $in['lng'] : null;
			$ok = null !== $la && null !== $lo && abs( $la ) <= 90 && abs( $lo ) <= 180;
			$row['latitude']  = $ok ? (string) round( $la, 6 ) : '';
			$row['longitude'] = $ok ? (string) round( $lo, 6 ) : '';
		}
		if ( ! $sid ) {
			$byser = array();
			foreach ( self::serials_in( $notes ) as $sr ) {
				$byser[ $sr ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Songpress_Install::table( 'songs' ) . ' WHERE serial = %s', $sr ) ); // phpcs:ignore WordPress.DB
			}
			$row['song_id'] = self::sole_song( $notes, array_filter( $byser ) );
		}
		$cr = isset( $in['created_at'] ) && preg_match( '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/', (string) $in['created_at'], $cm ) ? $cm[1] . ' ' . $cm[2] . ':00' : '';
		if ( $id ) {
			if ( '' !== $cr ) {
				$row['created_at'] = $cr;
			}
			self::keep_version( $id, $row['title'], $row['notes'] );
			$wpdb->update( self::t(), $row, array( 'id' => $id ) );
			return array( $id, '' );
		}
		$wpdb->insert( self::t(), array_merge( $row, array( 'user_id' => get_current_user_id(), 'created_at' => '' !== $cr ? $cr : current_time( 'mysql' ) ) ) );
		return array( (int) $wpdb->insert_id, '' );
	}

	public static function handle_save() {
		self::guard( 'songpress_journal_save' );
		$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- cleaned in persist().
		list( $id, $err ) = self::persist( $in );
		if ( '' !== $err ) {
			self::go( array( 'j_err' => $err ) );
		}
		self::go( array( 'j_ok' => absint( $in['entry_id'] ?? 0 ) ? __( 'Entry saved.', 'songpress' ) : __( 'Entry added.', 'songpress' ) ) );
	}

	/** The shape the Notes screen works with. */
	const VERSION_GAP = 300;
	const VERSION_KEEP = 30;

	/** Before a note is overwritten, keep what it said (at most one copy every five minutes; the newest 30 stay). */
	public static function keep_version( $id, $new_title, $new_notes ) {
		global $wpdb;
		$old = self::row( $id );
		if ( ! $old || ( (string) $old->title === (string) $new_title && (string) $old->notes === (string) $new_notes ) ) {
			return false;
		}
		$vt   = Songpress_Install::table( 'note_versions' );
		$last = $wpdb->get_var( $wpdb->prepare( "SELECT saved_at FROM {$vt} WHERE entry_id = %d ORDER BY id DESC LIMIT 1", $id ) ); // phpcs:ignore WordPress.DB
		if ( $last && ( strtotime( current_time( 'mysql' ) ) - strtotime( (string) $last ) ) < self::VERSION_GAP ) {
			return false;
		}
		$wpdb->insert( $vt, array( 'entry_id' => $id, 'title' => (string) $old->title, 'notes' => (string) $old->notes, 'user_id' => get_current_user_id(), 'saved_at' => current_time( 'mysql' ) ) );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$vt} WHERE entry_id = %d ORDER BY id DESC", $id ) ); // phpcs:ignore WordPress.DB
		foreach ( array_slice( (array) $ids, self::VERSION_KEEP ) as $gone ) {
			$wpdb->delete( $vt, array( 'id' => (int) $gone ) );
		}
		return true;
	}

	public static function shape( $e ) {
		return array(
			'id'       => (int) $e->id,
			'date'     => (string) $e->log_date,
			'kind'     => (string) $e->kind,
			'title'    => (string) $e->title,
			'notes'    => (string) $e->notes,
			'place'    => (string) $e->place,
			'key'      => (string) $e->song_key,
			'tempo'    => (string) $e->tempo,
			'authors'  => (string) $e->authors,
			'lat'      => (string) $e->latitude,
			'lng'      => (string) $e->longitude,
			'created'  => $e->created_at ? str_replace( ' ', 'T', substr( (string) $e->created_at, 0, 16 ) ) : '',
			'pinned'   => (int) $e->pinned,
			'archived' => (int) $e->archived,
		);
	}

	private static function len( $s ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $s ) : strlen( (string) $s );
	}

	/** What moved between two copies of a note: changed fields as old and new, and how many characters of text came and went. Empty when nothing changed. */
	public static function changes( $a, $b ) {
		$out = array();
		foreach ( array( 'title' => 'title', 'log_date' => 'date', 'kind' => 'kind', 'place' => 'place', 'authors' => 'authors', 'song_key' => 'key', 'tempo' => 'tempo' ) as $col => $name ) {
			$x = (string) ( $a->$col ?? '' );
			$y = (string) ( $b->$col ?? '' );
			if ( $x !== $y ) {
				$out[ $name ] = array( function_exists( 'mb_substr' ) ? mb_substr( $x, 0, 40 ) : substr( $x, 0, 40 ), function_exists( 'mb_substr' ) ? mb_substr( $y, 0, 40 ) : substr( $y, 0, 40 ) );
			}
		}
		$o = (string) ( $a->notes ?? '' );
		$n = (string) ( $b->notes ?? '' );
		if ( $o !== $n ) {
			$p = 0;
			$m = min( strlen( $o ), strlen( $n ) );
			while ( $p < $m && $o[ $p ] === $n[ $p ] ) {
				$p++;
			}
			$q = 0;
			while ( $q < $m - $p && $o[ strlen( $o ) - 1 - $q ] === $n[ strlen( $n ) - 1 - $q ] ) {
				$q++;
			}
			$out['added']   = self::len( substr( $n, $p, strlen( $n ) - $p - $q ) );
			$out['removed'] = self::len( substr( $o, $p, strlen( $o ) - $p - $q ) );
		}
		return $out;
	}

	/** A note for the list: no body, just what the list needs (a short preview and the #tags), so the page stays small. The full text is fetched when a note is opened. */
	public static function shape_slim( $e ) {
		$a    = self::shape( $e );
		$body = (string) $a['notes'];
		$prose = preg_replace( '/```[\s\S]*?```/', ' ', $body );
		$tags  = array();
		if ( preg_match_all( '/(?:^|[\s(])#([A-Za-z][A-Za-z0-9\/_-]*)/', (string) $prose, $m ) ) {
			foreach ( $m[1] as $t ) {
				$tags[ strtolower( $t ) ] = $t;
			}
		}
		$head = function_exists( 'mb_substr' ) ? mb_substr( (string) $prose, 0, 600 ) : substr( (string) $prose, 0, 600 );
		$pv   = trim( preg_replace( '/\s+/', ' ', preg_replace( '/[#*_>`\[\]]/', '', $head ) ) );
		$a['notes'] = '';
		$a['pv']    = function_exists( 'mb_substr' ) ? mb_substr( $pv, 0, 240 ) : substr( $pv, 0, 240 );
		$a['tags']  = array_values( $tags );
		$a['slim']  = 1;
		return $a;
	}

	private static function row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	/** One action from the Notes screen: save, pin, archive, delete or songs. Returns the reply as an array. */
	public static function ajax_op( array $in ) {
		global $wpdb;
		$op = (string) ( $in['op'] ?? '' );
		$id = absint( $in['entry_id'] ?? 0 );
		if ( 'save' === $op ) {
			$before = $id ? self::row( $id ) : null;
			list( $nid, $err ) = self::persist( $in );
			if ( '' !== $err ) {
				return array( 'ok' => false, 'error' => $err );
			}
			$nrow = self::row( $nid );
			if ( ! $before ) {
				Songpress_Audit::log( 'notes', 'Added a note', $nrow->title, array( 'entry_id' => $nid, 'added' => self::len( $nrow->notes ) ) );
			} else {
				$ch = self::changes( $before, $nrow );
				if ( $ch ) {
					Songpress_Audit::log_change( 'notes', array( 'Edited a note', 'Added a note' ), (string) $nrow->title, 'entry_id', $nid, $ch );
				}
			}
			return array( 'ok' => true, 'note' => self::shape( $nrow ) );
		}
		if ( 'flag' === $op && $id ) {
			$what = 'archived' === ( $in['flag'] ?? '' ) ? 'archived' : 'pinned';
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . " SET {$what} = 1 - {$what} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB -- one of two literals.
			Songpress_Audit::log( 'notes', ( 'archived' === $what ? 'Archived or restored' : 'Pinned or unpinned' ) . ' a note', (string) ( self::row( $id )->title ?? '' ), array( 'entry_id' => $id ) );
			return array( 'ok' => true, 'note' => self::shape( self::row( $id ) ) );
		}
		if ( 'get' === $op && $id ) {
			$r = self::row( $id );
			return $r ? array( 'ok' => true, 'note' => self::shape( $r ) ) : array( 'ok' => false, 'error' => 'That note is gone.' );
		}
		if ( 'getmany' === $op ) {
			$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) ( $in['ids'] ?? '' ) ) ) ) ) ), 0, 100 );
			$out = array();
			foreach ( $ids as $gid ) {
				$r = self::row( $gid );
				if ( $r ) {
					$out[] = self::shape( $r );
				}
			}
			return array( 'ok' => true, 'notes' => $out );
		}
		if ( 'find' === $op ) {
			$q = trim( (string) ( $in['q'] ?? '' ) );
			if ( strlen( $q ) < 2 ) {
				return array( 'ok' => true, 'ids' => array() );
			}
			$like = '%' . $wpdb->esc_like( $q ) . '%';
			$ids  = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::t() . ' WHERE notes LIKE %s OR title LIKE %s LIMIT 800', $like, $like ) ); // phpcs:ignore WordPress.DB
			return array( 'ok' => true, 'ids' => array_map( 'intval', (array) $ids ) );
		}
		if ( 'delete_many' === $op ) {
			$ids = array_filter( array_map( 'absint', explode( ',', (string) ( $in['ids'] ?? '' ) ) ) );
			$ids = array_slice( array_values( array_unique( $ids ) ), 0, 500 );
			foreach ( $ids as $gid ) {
				$wpdb->delete( self::t(), array( 'id' => $gid ) );
				$wpdb->delete( Songpress_Install::table( 'note_versions' ), array( 'entry_id' => $gid ) );
			}
			if ( $ids ) {
				Songpress_Audit::log( 'notes', 'Deleted notes', (string) count( $ids ), array( 'entry_ids' => implode( ',', $ids ) ) );
			}
			return array( 'ok' => true, 'deleted' => $ids );
		}
		if ( 'delete' === $op && $id ) {
			$gone = self::row( $id );
			$wpdb->delete( self::t(), array( 'id' => $id ) );
			$wpdb->delete( Songpress_Install::table( 'note_versions' ), array( 'entry_id' => $id ) );
			Songpress_Audit::log( 'notes', 'Deleted a note', $gone ? (string) $gone->title : '', array( 'entry_id' => $id ) );
			return array( 'ok' => true, 'deleted' => $id );
		}
		if ( 'versions' === $op && $id ) {
			$vt  = Songpress_Install::table( 'note_versions' );
			$out = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, title, notes, saved_at FROM {$vt} WHERE entry_id = %d ORDER BY id DESC", $id ) ) as $v ) { // phpcs:ignore WordPress.DB
				$out[] = array( 'id' => (int) $v->id, 'at' => str_replace( ' ', 'T', substr( (string) $v->saved_at, 0, 16 ) ), 'title' => (string) $v->title, 'notes' => (string) $v->notes );
			}
			return array( 'ok' => true, 'versions' => $out );
		}
		if ( 'media' === $op ) {
			$mq  = new WP_Query( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'audio', 's' => sanitize_text_field( (string) ( $in['q'] ?? '' ) ), 'posts_per_page' => 12, 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true ) );
			$out = array();
			foreach ( $mq->posts as $p ) {
				$out[] = array( 'title' => get_the_title( $p ), 'url' => (string) wp_get_attachment_url( $p->ID ) );
			}
			return array( 'ok' => true, 'items' => $out );
		}
		if ( 'songs' === $op ) {
			$out = array();
			foreach ( self::mentioned( (string) ( $in['text'] ?? '' ) ) as $m ) {
				$out[] = array( 'serial' => (string) $m->serial, 'title' => (string) $m->title, 'url' => Songpress_Editor::edit_url( (int) $m->id ) );
			}
			return array( 'ok' => true, 'songs' => $out );
		}
		return array( 'ok' => false, 'error' => 'Unknown action.' );
	}

	public static function handle_ajax() {
		check_admin_referer( 'songpress_journal_ajax' );
		if ( ! Songpress_Caps::can( 'journal' ) ) {
			wp_send_json( array( 'ok' => false, 'error' => 'Not allowed.' ), 403 );
		}
		wp_send_json( self::ajax_op( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- cleaned in persist().
	}

	public static function handle_flag() {
		self::guard( 'songpress_journal_flag' );
		global $wpdb;
		$id   = absint( $_POST['entry_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- checked by guard().
		$what = 'archived' === ( $_POST['flag'] ?? '' ) ? 'archived' : 'pinned'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $id ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . " SET {$what} = 1 - {$what} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB -- $what is one of two literals.
		}
		self::go();
	}

	/* ---------- screen ---------- */

	private static function form_open( $action, $class = '' ) {
		return '<form method="post" class="' . esc_attr( $class ) . '" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( $action, '_wpnonce', true, false ) . '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="sp_return" value="' . esc_attr( Songpress_Editor::list_url() ) . '">';
	}

	private static function fields( $e = null ) {
		$o  = '<div class="spe-grid"><label class="spe-field"><span>' . esc_html__( 'Created', 'songpress' ) . '</span><input type="datetime-local" name="created_at" value="' . esc_attr( $e && $e->created_at ? str_replace( ' ', 'T', substr( $e->created_at, 0, 16 ) ) : str_replace( ' ', 'T', substr( current_time( 'mysql' ), 0, 16 ) ) ) . '"></label><label class="spe-field"><span>' . esc_html__( 'Date', 'songpress' ) . '</span><input type="date" name="log_date" value="' . esc_attr( $e ? $e->log_date : gmdate( 'Y-m-d' ) ) . '"></label>';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Kind', 'songpress' ) . '</span><select name="kind">';
		foreach ( self::KINDS as $k => $label ) {
			$o .= '<option value="' . esc_attr( $k ) . '"' . selected( $e ? $e->kind : 'day', $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$o .= '</select></label><label class="spe-field"><span>' . esc_html__( 'Title (start with a serial to link a song)', 'songpress' ) . '</span><input name="title" value="' . esc_attr( $e ? $e->title : '' ) . '"></label>';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Place', 'songpress' ) . '</span><input name="place" value="' . esc_attr( $e ? $e->place : '' ) . '"></label></div>';
		$o .= '<div class="spe-grid"><label class="spe-field"><span>' . esc_html__( 'Key', 'songpress' ) . '</span><input name="song_key" size="6" value="' . esc_attr( $e ? $e->song_key : '' ) . '"></label><label class="spe-field"><span>' . esc_html__( 'Tempo', 'songpress' ) . '</span><input name="tempo" size="6" value="' . esc_attr( $e ? $e->tempo : '' ) . '"></label><label class="spe-field"><span>' . esc_html__( 'Authors', 'songpress' ) . '</span><input name="authors" value="' . esc_attr( $e ? $e->authors : '' ) . '"></label></div>';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Notes (write ^0378 to link a song)', 'songpress' ) . '</span><textarea name="notes" rows="' . ( $e ? 8 : 4 ) . '">' . esc_textarea( $e ? $e->notes : '' ) . '</textarea></label>';
		return $o;
	}

	public static function render() {
		if ( ! Songpress_Caps::can( 'journal' ) ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'Only Managers can open this screen.', 'songpress' ) . '</p></div>';
		}
		$base = Songpress_Editor::list_url();
		$view = isset( $_GET['jview'] ) && in_array( $_GET['jview'], array( 'days', 'map' ), true ) ? sanitize_key( wp_unslash( $_GET['jview'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'list' !== $view ) {
			$o  = '<div class="songpress sp-app sp-editor sp-journal"><div class="spe-top"><h2>' . esc_html( 'days' === $view ? __( 'Days', 'songpress' ) : __( 'Map', 'songpress' ) ) . '</h2></div>';
			$o .= '<p class="spj-tabs"><a class="spe-btn" href="' . esc_url( add_query_arg( 'sp_page', 'notes', $base ) ) . '">&larr; ' . esc_html__( 'Notes', 'songpress' ) . '</a> ';
			foreach ( array( 'days' => __( 'Days', 'songpress' ), 'map' => __( 'Map', 'songpress' ) ) as $vk => $vl ) {
				$o .= '<a class="spe-btn' . ( $view === $vk ? ' on' : '' ) . '" href="' . esc_url( add_query_arg( array( 'sp_page' => 'notes', 'jview' => $vk ), $base ) ) . '">' . esc_html( $vl ) . '</a> ';
			}
			return $o . '</p>' . ( 'days' === $view ? self::days_html() : self::map_html() ) . '</div>';
		}
		global $wpdb;
		// Read the notes in small slices and encode each at once, so thousands of notes never sit in memory three times over.
		$fl    = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
		$parts = array();
		for ( $off = 0; $off < 3000; $off += 250 ) {
			$rows = $wpdb->get_results( 'SELECT * FROM ' . self::t() . ' ORDER BY pinned DESC, log_date DESC, id DESC LIMIT 250 OFFSET ' . (int) $off ); // phpcs:ignore WordPress.DB
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $e ) {
				$parts[] = wp_json_encode( self::shape_slim( $e ), $fl );
			}
			$n = count( $rows );
			unset( $rows );
			if ( $n < 250 ) {
				break;
			}
		}
		$data = array(
			'today' => gmdate( 'Y-m-d' ),
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'songpress_journal_ajax' ),
			'aj'    => admin_url( 'admin-ajax.php' ),
			'upnon' => wp_create_nonce( 'songpress_upload' ),
			'canup' => Songpress_Caps::can( 'upload' ),
			'kinds' => self::KINDS,
			'urls'  => array(
				'import' => add_query_arg( array( 'sp_page' => 'uploads', 'type' => 'notes_files' ), $base ),
				'days'   => add_query_arg( array( 'sp_page' => 'notes', 'jview' => 'days' ), $base ),
				'map'    => add_query_arg( array( 'sp_page' => 'notes', 'jview' => 'map' ), $base ),
				'md'     => wp_nonce_url( admin_url( 'admin-post.php?action=songpress_download&table=notes_md' ), 'songpress_download' ),
				'bear'   => wp_nonce_url( admin_url( 'admin-post.php?action=songpress_download&table=notes_bear' ), 'songpress_download' ),
				'tb'     => wp_nonce_url( admin_url( 'admin-post.php?action=songpress_download&table=notes_textbundle' ), 'songpress_download' ),
				'tool'   => class_exists( 'Songpress_ChordTool' ) ? Songpress_ChordTool::url() : '',
			),
		);
		$json = '{"notes":[' . implode( ',', $parts ) . '],' . substr( wp_json_encode( $data, $fl ), 1 );
		unset( $parts );
		$o    = '<div class="songpress sp-app spn-wrap"><div class="spn" id="spn">';
		$o   .= '<div class="spn-tags" id="spn-tags"></div>';
		$o   .= '<div class="spn-side"><div class="spn-top">';
		foreach ( array( 'new' => array( '+', __( 'New note', 'songpress' ) ), 'imp' => array( '↓', __( 'Import notes (.bear, .md, .txt)', 'songpress' ) ), 'exp' => array( '↑', __( 'Export the open or selected notes as .md', 'songpress' ) ), 'sel' => array( '☑', __( 'Select several', 'songpress' ) ), 'sort' => array( '↕', __( 'Sort newest / oldest', 'songpress' ) ), 'foc' => array( '⛶', __( 'Focus: hide everything but the note', 'songpress' ) ), 'lay' => array( '▤', __( 'Show or hide the tag column', 'songpress' ) ) ) as $k => $b ) {
			$o .= '<button type="button" class="spn-btn" data-act="' . esc_attr( $k ) . '" title="' . esc_attr( $b[1] ) . '" aria-label="' . esc_attr( $b[1] ) . '">' . esc_html( $b[0] ) . '</button>';
		}		$o .= '<div class="spn-pop" id="spn-pop" hidden><button type="button" data-go="sel">' . esc_html__( 'Open or selected notes (.md)', 'songpress' ) . '</button><button type="button" data-go="md">' . esc_html__( 'All as .md', 'songpress' ) . '</button><button type="button" data-go="bear">' . esc_html__( 'All as .bear', 'songpress' ) . '</button><button type="button" data-go="tb">' . esc_html__( 'All for Bear (.textbundle)', 'songpress' ) . '</button><i></i><button type="button" data-go="days">' . esc_html__( 'Days', 'songpress' ) . '</button><button type="button" data-go="map">' . esc_html__( 'Map', 'songpress' ) . '</button>' . ( class_exists( 'Songpress_ChordTool' ) ? '<button type="button" data-go="tool">' . esc_html__( 'ChordPro tool', 'songpress' ) . '</button>' : '' ) . '</div>';

		$o .= '<div class="spn-cal" id="spn-cal"></div>';
		$o .= '<input class="spn-search" id="spn-search" type="search" placeholder="' . esc_attr__( 'Search', 'songpress' ) . '"></div><div class="spn-list" id="spn-list"></div></div>';
		$o .= '<div class="spn-main"><button type="button" class="spn-tgl spn-tgl-list" data-act="list" title="' . esc_attr__( 'Show or hide the list', 'songpress' ) . '">◧</button><button type="button" class="spn-tgl spn-tgl-info" data-act="info" title="' . esc_attr__( 'Show or hide the details', 'songpress' ) . '">◨</button>';
		$o .= '<div class="spn-editor" id="spn-editor"><input class="spn-title" id="spn-title" placeholder="' . esc_attr__( 'Title', 'songpress' ) . '"><div class="spn-card"><div class="spn-auds" id="spn-auds"></div><textarea class="spn-body" id="spn-body" placeholder="' . esc_attr__( 'Write here. ^0378 links a song, #tag adds a tag.', 'songpress' ) . '"></textarea><div class="spn-prev" id="spn-prev" hidden></div><div class="spn-audbox" id="spn-audbox" hidden><button type="button" class="spn-audx" id="spn-audx" aria-label="' . esc_attr__( 'Close', 'songpress' ) . '">×</button><div class="spn-drop" id="spn-drop" tabindex="0" role="button">' . esc_html__( 'Drop audio here to add it to WordPress media, or click to search or upload', 'songpress' ) . '</div><div class="spn-audpick" id="spn-audpick" hidden><input type="search" id="spn-audq" placeholder="' . esc_attr__( 'Search WordPress media', 'songpress' ) . '"><div id="spn-audres" class="spn-audres"></div><button type="button" id="spn-audup" class="spn-audupb">' . esc_html__( 'Upload from this computer…', 'songpress' ) . '</button></div></div>';
		$o .= '<div class="spn-bar" id="spn-bar">';
		foreach ( array( array( 'b', 'B', 'Bold' ), array( 'i', 'I', 'Italic' ), array( 'h', 'H', 'Heading' ), array( 'ul', '•', 'List' ), array( 'a', '🔗', 'Link' ), array( 'cp', '♫', 'ChordPro block' ), array( 'img', '▣', 'Image' ), array( 'aud', '♪', 'Audio' ), array( 'eye', '👁', 'Preview' ), '-', array( 'pin', '📌', 'Pin' ), array( 'arch', '🗄', 'Archive' ), array( 'del', '🗑', 'Delete' ) ) as $b ) {
			$o .= is_array( $b ) ? '<button type="button" data-t="' . esc_attr( $b[0] ) . '" title="' . esc_attr( $b[2] ) . '" aria-label="' . esc_attr( $b[2] ) . '">' . esc_html( $b[1] ) . '</button>' : '<i class="spn-sep"></i>';
		}
		$o .= '</div><span class="spn-status" id="spn-status"></span></div></div>';
		$o .= '<aside class="spn-info" id="spn-info">';
		foreach ( array(
			array( 'date', __( 'Date', 'songpress' ), 'date' ),
			array( 'created', __( 'Created', 'songpress' ), 'datetime-local' ),
			array( 'place', __( 'Location', 'songpress' ), 'text' ),
			array( 'authors', __( 'Authors', 'songpress' ), 'text' ),
		) as $f ) {
			$o .= '<label><span>' . esc_html( $f[1] ) . '</span><input type="' . esc_attr( $f[2] ) . '" id="spn-f-' . esc_attr( $f[0] ) . '"' . ( 'authors' === $f[0] ? ' list="spn-authors"' : '' ) . '></label>';
		}
		$o .= '<div class="spn-two"><label><span>' . esc_html__( 'Key', 'songpress' ) . '</span><input id="spn-f-key"></label><label><span>' . esc_html__( 'Tempo', 'songpress' ) . '</span><input id="spn-f-tempo"></label></div>';
		$o .= '<label><span>' . esc_html__( 'Kind', 'songpress' ) . '</span><select id="spn-f-kind"></select></label>';
		$o .= '<div class="spn-songs"><span>' . esc_html__( 'Songs mentioned', 'songpress' ) . '</span><div id="spn-songs"></div></div>';
		$o .= '<div class="spn-chips" id="spn-chips"></div><datalist id="spn-authors"></datalist>';
		$o .= '<div class="spn-hist"><span>' . esc_html__( 'Earlier versions', 'songpress' ) . ' <a href="#" id="spn-hist-show">' . esc_html__( 'show', 'songpress' ) . '</a></span><div id="spn-hist" hidden></div></div>';
		$o .= '<label><span>' . esc_html( "#'s" ) . '</span><input id="spn-f-tag" placeholder="' . esc_attr__( '# then a tag, Enter to add', 'songpress' ) . '"></label>';
		$o .= '<div class="spn-pos spn-pos-end"><span>' . esc_html__( 'Position', 'songpress' ) . ' <a href="#" id="spn-pos-here">' . esc_html__( 'here', 'songpress' ) . '</a> <a href="#" id="spn-pos-clear">' . esc_html__( 'clear', 'songpress' ) . '</a></span><small id="spn-pos-txt"></small><div id="spn-map"></div></div>';
		$o .= '</aside></div>';
		$o .= '<script type="application/json" id="spn-data">' . $json . '</script>' . self::script() . '</div></div>';
		return $o;
	}

	private static function script() {
		return '<script>' . <<<'JS'
(function(){
var root=document.getElementById('spn');if(!root)return;
var D=JSON.parse(document.getElementById('spn-data').textContent),notes=D.notes;
var $=function(i){return document.getElementById(i)};
var cur=null,tag='',q='',asc=false,sel={},selMode=false,busy=false,again=false,timer=null,dirty=false,showPrev=false;
var MON=['January','February','March','April','May','June','July','August','September','October','November','December'],DOW=['SUN','MON','TUE','WED','THU','FRI','SAT'];
function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function pd(d){var m=/^(\d{4})-(\d{2})-(\d{2})/.exec(d||'');return m?new Date(Date.UTC(+m[1],+m[2]-1,+m[3])):null}
function longDate(d){var x=pd(d);return x?MON[x.getUTCMonth()]+' '+x.getUTCDate()+', '+x.getUTCFullYear():''}
function head(n){return (n.title||'').trim()||longDate(n.date)||'Untitled'}
function prose(t){return String(t||'').replace(/```[\s\S]*?```/g,' ')}
function tagsOf(n){if(n.slim)return n.tags||[];if(n._ts===n.notes)return n._t;var o={};prose(n.notes).replace(/(^|[\s(])#([A-Za-z][A-Za-z0-9\/_-]*)/g,function(_,a,t){o[t.toLowerCase()]=t});n._ts=n.notes;n._t=Object.keys(o);return n._t}
function preview(n){if(n.slim)return n.pv||'';return prose(String(n.notes||'').slice(0,600)).replace(/[#*_>`\[\]]/g,'').replace(/\s+/g,' ').trim().slice(0,240)}
var day='',calY=0,calM=0;
function two(x){return (x<10?'0':'')+x}
function renderCal(){var c=$('spn-cal');if(!c)return;if(!calY){var b0=pd(cur&&cur.date)||new Date();calY=b0.getUTCFullYear();calM=b0.getUTCMonth()}
 var has={};notes.forEach(function(n){if(!n.archived&&n.date)has[String(n.date).slice(0,10)]=1});
 var first=new Date(Date.UTC(calY,calM,1)).getUTCDay(),dim=new Date(Date.UTC(calY,calM+1,0)).getUTCDate(),h='<div class="spn-calh"><button type="button" data-m="-1" aria-label="Previous month">‹</button><b>'+MON[calM].slice(0,3)+' '+calY+'</b><button type="button" data-m="1" aria-label="Next month">›</button></div><div class="spn-calg">',i;
 ['S','M','T','W','T','F','S'].forEach(function(x){h+='<i>'+x+'</i>'});
 for(i=0;i<first;i++)h+='<span></span>';
 for(i=1;i<=dim;i++){var k=calY+'-'+two(calM+1)+'-'+two(i);h+='<button type="button" data-d="'+k+'" class="'+(has[k]?'has ':'')+(day===k?'on':'')+'">'+i+'</button>'}
 c.innerHTML=h+'</div>'}
function visible(){
 var k=q.toLowerCase();
 var r=notes.filter(function(n){
  if(tag==='@arch')return n.archived;
  if(n.archived)return false;
  if(tag==='@pin'&&!n.pinned)return false;
  if(tag&&tag.charAt(0)!=='@'&&tagsOf(n).indexOf(tag)<0)return false;
  if(day&&String(n.date||'').slice(0,10)!==day)return false;
  if(k&&(n.title+' '+(n.slim?n.pv:n.notes)+' '+n.place+' '+n.authors).toLowerCase().indexOf(k)<0&&!(FQ===k&&FS[n.id]))return false;
  return true});
 r.sort(function(a,b){if(a.pinned!==b.pinned)return b.pinned-a.pinned;var c=(a.date<b.date?-1:a.date>b.date?1:0)||(a.id-b.id);return asc?c:-c});
 return r}
function renderTags(){
 var all={},pins=0,arch=0;
 notes.forEach(function(n){if(n.archived){arch++;return}if(n.pinned)pins++;tagsOf(n).forEach(function(t){all[t.toLowerCase()]=t})});
 var h='<h3>#\'s</h3>';
 function b(k,l,on){return '<button type="button" class="spn-tag'+(on?' on':'')+'" data-tag="'+esc(k)+'">'+esc(l)+'</button>'}
 h+=b('','All',!tag);
 if(pins)h+=b('@pin','Pinned',tag==='@pin');
 Object.keys(all).sort().forEach(function(k){h+=b(k,'#'+all[k],tag===k)});
 if(arch)h+=b('@arch','Archived',tag==='@arch');
 $('spn-tags').innerHTML=h}
var LIM=150,lk='',FQ='',FS={},fT=null,want=0;
function renderList(){
 var r=visible(),h='',key=q+'|'+tag+'|'+day+'|'+(asc?1:0);if(key!==lk){lk=key;LIM=150}var total=r.length;r=r.slice(0,LIM);
 r.forEach(function(n){
  var x=pd(n.date);
  h+='<div class="spn-row'+(cur&&cur.id===n.id?' on':'')+(sel[n.id]?' sel':'')+'" data-id="'+n.id+'"><span class="spn-date"><b>'+(x?DOW[x.getUTCDay()]:'')+'</b><em>'+(x?x.getUTCDate():'')+'</em><small>'+(x?x.getUTCFullYear():'')+'</small></span><span class="spn-info2"><span class="spn-t">'+(n.pinned?'★ ':'')+esc(head(n))+'</span><span class="spn-p">'+esc(preview(n))+'</span></span></div>'});
 var nsel=Object.keys(sel).length;if(selMode)h='<div class="spn-selbar" style="padding:8px 10px;position:sticky;top:0;background:inherit"><button type="button" class="spe-btn" data-delsel="1"'+(nsel?'':' disabled')+'>Delete '+nsel+' selected</button> <button type="button" class="spe-btn" data-allsel="1">Select all shown</button></div>'+h;
 if(total>r.length)h+='<button type="button" class="spe-btn" data-more="1" style="margin:10px">Show more ('+(total-r.length)+' left)</button>';
 $('spn-list').innerHTML=h||'<p class="spn-empty">No notes here.</p>';renderCal()}
function setStatus(s){$('spn-status').textContent=s||''}
function mark(id){try{var u=new URL(location.href);if(id)u.searchParams.set('note',id);else u.searchParams.delete('note');var k=q.trim();if(k)u.searchParams.set('nq',k);else u.searchParams.delete('nq');history.replaceState(null,'',u)}catch(e){}}
function fill(n){
 want=n.id||0;mark(want);
 if(n.slim){setStatus('Opening…');var w0=n.id;post({op:'get',entry_id:w0}).then(function(r){if(want!==w0)return;if(r.ok){fill(merge(r.note));setStatus('')}else setStatus(r.error||'Could not open it')}).catch(function(){setStatus('Could not reach the server')});return}
 cur=n;pos=(n.lat!==''&&n.lat!=null&&n.lng!=='')?{lat:+n.lat,lng:+n.lng}:null;
 $('spn-title').value=n.title||'';AL=[];$('spn-body').value=toDisp(n.notes||'');
 $('spn-f-date').value=n.date||D.today;$('spn-f-created').value=n.created||'';
 $('spn-f-place').value=n.place||'';$('spn-f-authors').value=n.authors||'';
 $('spn-f-key').value=n.key||'';$('spn-f-tempo').value=n.tempo||'';$('spn-f-kind').value=n.kind||'day';
 dirty=false;setStatus('');
 root.querySelector('[data-t=pin]').classList.toggle('on',!!n.pinned);
 root.querySelector('[data-t=arch]').classList.toggle('on',!!n.archived);
 sizeBody();showPrev=false;$('spn-prev').hidden=true;$('spn-body').hidden=false;
 songs();renderList();chips();showPos();$('spn-hist').hidden=true}
function blank(){return {id:0,date:D.today,kind:'day',title:'',notes:'',place:'',key:'',tempo:'',authors:'',lat:'',lng:'',created:'',pinned:0,archived:0}}
function sizeBody(){var t=$('spn-body');t.style.height='auto';t.style.height=Math.max(t.scrollHeight,300)+'px';if(typeof auds==='function')auds()}
function post(f){
 var fd=new FormData();fd.append('action','songpress_journal_ajax');fd.append('_wpnonce',D.nonce);
 Object.keys(f).forEach(function(k){fd.append(k,f[k])});
 return fetch(D.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()})}
function collect(){return {op:'save',entry_id:cur?cur.id:0,title:$('spn-title').value,notes:toRaw($('spn-body').value),log_date:$('spn-f-date').value,created_at:$('spn-f-created').value,place:$('spn-f-place').value,authors:$('spn-f-authors').value,song_key:$('spn-f-key').value,tempo:$('spn-f-tempo').value,kind:$('spn-f-kind').value,lat:pos?pos.lat:'',lng:pos?pos.lng:''}}
function merge(n){
 var i=notes.findIndex(function(x){return x.id===n.id});
 if(i<0)notes.unshift(n);else notes[i]=n;return n}
var edits=0;
function save(quiet){
 if(busy){again=true;return Promise.resolve()}
 var f=collect();if(!f.title.trim()&&!f.notes.trim()&&!f.entry_id)return Promise.resolve();
 busy=true;again=false;var mark=edits;setStatus('Saving…');
 return post(f).then(function(r){busy=false;
  if(!r.ok){setStatus(r.error||'Could not save');return}
  var n=merge(r.note);cur=n;dirty=(edits!==mark);renderTags();renderList();songs();
  if(dirty||again){again=false;clearTimeout(timer);timer=setTimeout(function(){if(dirty)save(true)},300);setStatus('Saving…');return}
  setStatus('Saved');setTimeout(function(){if(!dirty)setStatus('')},1500)
 }).catch(function(){busy=false;setStatus('Could not save. Retrying…');clearTimeout(timer);timer=setTimeout(function(){if(dirty)save(true)},4000)})}
function touch(){edits++;dirty=true;setStatus('');clearTimeout(timer);timer=setTimeout(function(){if(dirty)save(true)},700)}
function flush(){if(!dirty||busy)return;var f=collect();if(!f.title.trim()&&!f.notes.trim()&&!f.entry_id)return;var fd=new FormData();fd.append('action','songpress_journal_ajax');fd.append('_wpnonce',D.nonce);Object.keys(f).forEach(function(k){fd.append(k,f[k])});try{navigator.sendBeacon(D.ajax,fd)}catch(e){}}
document.addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden')flush()});window.addEventListener('pagehide',flush);
function songs(){
 var box=$('spn-songs'),t=($('spn-title').value+'\n'+$('spn-body').value);
 if(!/[\^#]\d{3,5}/.test(t)){box.innerHTML='<small>None</small>';return}
 post({op:'songs',text:t}).then(function(r){
  box.innerHTML=(r.songs&&r.songs.length)?r.songs.map(function(s){return '<a href="'+esc(s.url)+'"><b>'+esc(s.serial)+'</b> '+esc(s.title)+'</a>'}).join(''):'<small>None</small>'})}
function wrap(a,b,ph){
 var t=$('spn-body'),s=t.selectionStart,e=t.selectionEnd,v=t.value,m=v.slice(s,e)||ph||'';
 t.value=v.slice(0,s)+a+m+b+v.slice(e);t.focus();t.selectionStart=s+a.length;t.selectionEnd=s+a.length+m.length;sizeBody();touch()}
function lineStart(p){var t=$('spn-body'),v=t.value,s=t.selectionStart,ls=v.lastIndexOf('\n',s-1)+1;t.value=v.slice(0,ls)+p+v.slice(ls);t.focus();t.selectionStart=t.selectionEnd=s+p.length;sizeBody();touch()}
function md(t){
 var s=esc(String(t).replace(/(\[[^\]\n]*\])[ \t]*\n[ \t]*\(/g,'$1(')),out=[],inCode=false,inList=false;
 s.split('\n').forEach(function(l){
  if(/^```/.test(l)){out.push(inCode?'</pre>':'<pre class="spn-cp">');inCode=!inCode;return}
  if(inCode){out.push(l);return}
  var li=/^\s*[-*•]\s+(.*)/.exec(l);
  if(li){if(!inList){out.push('<ul>');inList=true}out.push('<li>'+inl(li[1])+'</li>');return}
  if(inList){out.push('</ul>');inList=false}
  var h=/^(#{1,3})\s+(.*)/.exec(l);
  if(h){out.push('<h'+h[1].length+'>'+inl(h[2])+'</h'+h[1].length+'>');return}
  out.push(l.trim()?'<p>'+inl(l)+'</p>':'')});
 if(inList)out.push('</ul>');if(inCode)out.push('</pre>');
 return out.join('\n')}
function inl(s){
 return s.replace(/\[(♪\s*)?([^\]]*)\]\s*\((https?:[^)\s]+)\)/g,function(m,n,t,u){return (n||isAud(u))?'<span class="spn-aui"><span>♪ '+(t||'Audio')+'</span><audio controls preload="none" src="'+u+'"></audio></span>':m})
  .replace(/!\[([^\]]*)\]\((https?:[^)\s]+)\)/g,'<img src="$2" alt="$1">')
  .replace(/\[([^\]]+)\]\((https?:[^)\s]+)\)/g,'<a href="$2" target="_blank" rel="noopener">$1</a>')
  .replace(/\*\*([^*]+)\*\*/g,'<b>$1</b>').replace(/(^|[\s(])\*([^*]+)\*/g,'$1<i>$2</i>')
  .replace(/(^|[\s(])#([A-Za-z][A-Za-z0-9\/_-]*)/g,'$1<span class="spn-hash">#$2</span>')
  .replace(/([\^#])(\d{3,5}(?:-\d{1,2})?)/g,'<span class="spn-hash">$1$2</span>')}

var pos=null,lmap=null,lmark=null,lload=null;
function chips(){var a=$('spn-f-authors').value.split(',').map(function(x){return x.trim()}).filter(Boolean);$('spn-chips').innerHTML=a.map(function(x){return '<button type="button" class="spn-chip" data-au="'+esc(x)+'">'+esc(x)+'</button>'}).join('');
 var seen={};notes.forEach(function(n){String(n.authors||'').split(',').forEach(function(x){x=x.trim();if(x)seen[x]=1})});$('spn-authors').innerHTML=Object.keys(seen).sort().map(function(x){return '<option value="'+esc(x)+'">'}).join('')}
$('spn-chips').addEventListener('click',function(e){var b=e.target.closest('[data-au]');if(!b)return;q=b.getAttribute('data-au');$('spn-search').value=q;tag='';renderTags();renderList()});
$('spn-f-authors').addEventListener('input',chips);
function showPos(){$('spn-pos-txt').textContent=pos?pos.lat.toFixed(4)+', '+pos.lng.toFixed(4):'No position';if(lmap){if(pos){lmark.setLatLng([pos.lat,pos.lng]);lmark.addTo(lmap);lmap.setView([pos.lat,pos.lng],Math.max(lmap.getZoom(),10))}else lmark.remove()}}
function loadMap(){return lload||(lload=new Promise(function(res,rej){if(window.L){res();return}var c=document.createElement('link');c.rel='stylesheet';c.href='https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';document.head.appendChild(c);var s=document.createElement('script');s.src='https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';s.onload=res;s.onerror=function(){lload=null;rej()};document.head.appendChild(s)}))}
function openMap(){$('spn-map').hidden=false;loadMap().then(function(){if(!lmap){lmap=L.map('spn-map').setView(pos?[pos.lat,pos.lng]:[39,-98],pos?10:3);L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:18,attribution:'&copy; OpenStreetMap'}).addTo(lmap);lmark=L.marker([0,0]);lmap.on('click',function(e){pos={lat:e.latlng.lat,lng:e.latlng.lng};showPos();touch()})}showPos();setTimeout(function(){lmap.invalidateSize()},50)},function(){setStatus('The map could not load')})}
$('spn-pos-clear').addEventListener('click',function(e){e.preventDefault();pos=null;showPos();touch()});
$('spn-pos-here').addEventListener('click',function(e){e.preventDefault();if(!navigator.geolocation){setStatus('Location is not available here');return}navigator.geolocation.getCurrentPosition(function(p){pos={lat:p.coords.latitude,lng:p.coords.longitude};showPos();touch()},function(){setStatus('Location was not shared')})});
$('spn-hist-show').addEventListener('click',function(e){e.preventDefault();var h=$('spn-hist');if(!h.hidden){h.hidden=true;return}
 if(!cur||!cur.id){h.hidden=false;h.innerHTML='<em>Save the note first.</em>';return}
 post({op:'versions',entry_id:cur.id}).then(function(r){h.hidden=false;if(!r.versions||!r.versions.length){h.innerHTML='<em>No earlier versions yet. One is kept each time you change a note (at most every five minutes).</em>';return}
  h.innerHTML=r.versions.map(function(v,i){return '<div class="spn-ver"><span>'+esc(longDate(v.at)+' '+v.at.slice(11))+'<small>'+esc((v.title||v.notes).replace(/\s+/g,' ').slice(0,60))+'</small></span><button type="button" data-v="'+i+'">Restore</button></div>'}).join('');
  h.onclick=function(ev){var b=ev.target.closest('[data-v]');if(!b)return;var v=r.versions[+b.getAttribute('data-v')];$('spn-title').value=v.title;AL=[];$('spn-body').value=toDisp(v.notes);sizeBody();touch();setStatus('Restored. The version you replaced is kept too.')}})});
function upload(file,kind){if(!D.canup){setStatus('You cannot upload files');return}setStatus('Uploading…');var fd=new FormData();fd.append('action','songpress_upload');fd.append('nonce',D.upnon);fd.append('file',file);
 fetch(D.aj,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){if(!j.success){setStatus((j.data&&j.data.message)||'Upload failed');return}setStatus('');var nm=file.name.replace(/\.[^.]+$/,'');if(kind==='img')wrap('![',']('+j.data.url+')',nm);else addAud(nm,j.data.url)}).catch(function(){setStatus('Upload failed')})}
function pickFile(kind,accept){var i=document.createElement('input');i.type='file';i.accept=accept;i.onchange=function(){if(i.files[0])upload(i.files[0],kind)};i.click()}
function caretXY(t,pos){var d=document.createElement('div'),c=getComputedStyle(t),p=['fontFamily','fontSize','fontWeight','lineHeight','letterSpacing','paddingTop','paddingRight','paddingBottom','paddingLeft','borderTopWidth','borderLeftWidth','boxSizing','whiteSpace','wordWrap','overflowWrap','tabSize'];p.forEach(function(k){d.style[k]=c[k]});d.style.position='absolute';d.style.visibility='hidden';d.style.top='0';d.style.left='-9999px';d.style.width=t.clientWidth+'px';d.style.whiteSpace='pre-wrap';d.style.wordWrap='break-word';d.textContent=t.value.slice(0,pos==null?t.selectionStart:pos);var m=document.createElement('span');m.textContent='\u200b';d.appendChild(m);document.body.appendChild(d);var r={x:m.offsetLeft,y:m.offsetTop,h:parseFloat(c.lineHeight)||m.offsetHeight||24};d.remove();return r}
function placeBox(){var t=$('spn-body'),b=$('spn-audbox'),xy=caretXY(t),w=Math.min(420,t.clientWidth),l=Math.max(0,Math.min(xy.x,t.clientWidth-w));b.style.width=w+'px';b.style.left=(t.offsetLeft+l)+'px';b.style.top=(t.offsetTop+xy.y+xy.h-t.scrollTop+4)+'px'}
function isAud(u){return /\.(mp3|wav|m4a|aac|ogg|oga|flac)(\?|#|$)/i.test(u)}
var AL=[],TOK=/\[♪ ([^\]\n]*)\]⟦(\d+)⟧\n?/g;
function toDisp(t){return String(t).replace(/\[(♪\s*)?([^\]]*)\]\s*\((https?:[^)\s]+)\)/g,function(m,n,x,u){if(!(n||isAud(u)))return m;AL.push(u);return '[♪ '+String(x).replace(/\n/g,' ')+']⟦'+(AL.length-1)+'⟧\n'})}
function toRaw(t){return String(t).replace(TOK,function(m,x,k){return AL[+k]?'[♪ '+x+']('+AL[+k]+')':m})}
function audLinks(t){var r=/\[♪ ([^\]\n]*)\]⟦(\d+)⟧\n?/g,m,o=[];t=String(t);while((m=r.exec(t)))if(AL[+m[2]])o.push([m[1],AL[+m[2]],m.index,m.index+m[0].length,+m[2]]);return o}
var audKey='';
function auds(){var t=$('spn-body'),a=audLinks(t.value),h=$('spn-auds');if(t.hidden){h.innerHTML='';audKey='';return}var key=a.map(function(x){return x[1]}).join('|');
 if(key!==audKey){audKey=key;h.innerHTML=a.map(function(x){return '<div class="spn-au"><span>♪ '+esc(x[0]||'Audio')+'</span><audio controls preload="none" src="'+esc(x[1])+'"></audio><button type="button" class="spn-auedit" title="Change the address" aria-label="Change the address">✎</button></div>'}).join('')}
 var n=h.children,foc=document.activeElement===t,ss=t.selectionStart,se=t.selectionEnd,i,x,g,y1,y2,top;
 for(i=0;i<a.length&&i<n.length;i++){x=a[i];g=n[i];y1=caretXY(t,x[2]);y2=caretXY(t,x[3]);top=t.offsetTop+y1.y-t.scrollTop;
  g.style.left=t.offsetLeft+'px';g.style.width=t.clientWidth+'px';g.style.top=top+'px';g.style.height=Math.max(y2.y+y2.h-y1.y,54)+'px';g.hidden=foc&&ss<x[3]-1&&se>x[2]}}
$('spn-auds').addEventListener('click',function(e){var b=e.target.closest('.spn-auedit');if(!b)return;var a=audLinks($('spn-body').value),i=Array.prototype.indexOf.call($('spn-auds').children,b.parentNode);if(!a[i])return;var u=window.prompt('Audio address',a[i][1]);if(u&&/^https?:/.test(u)){AL[a[i][4]]=u;audKey='';auds();touch()}});
document.addEventListener('selectionchange',function(){if(document.activeElement===$('spn-body'))auds()});
$('spn-body').addEventListener('blur',function(){auds()});
window.addEventListener('resize',function(){auds()});
function addAud(name,url){var t=$('spn-body'),v=t.value,s=t.selectionStart,e=t.selectionEnd;AL.push(url);var tok=(s>0&&v.charAt(s-1)!=='\n'?'\n':'')+'[♪ '+String(name||'Audio').replace(/[\]\n]/g,' ')+']⟦'+(AL.length-1)+'⟧\n\n';t.value=v.slice(0,s)+tok+v.slice(e);t.focus();t.selectionStart=t.selectionEnd=s+tok.length;$('spn-audbox').hidden=true;sizeBody();touch()}
function audSearch(){var qv=$('spn-audq').value;post({op:'media',q:qv}).then(function(r){var h=$('spn-audres');if(!r.items||!r.items.length){h.innerHTML='<em>No audio found in WordPress media.</em>';return}h.innerHTML=r.items.map(function(x,i){return '<button type="button" data-i="'+i+'">♪ '+esc(x.title||'Audio')+'</button>'}).join('');h.onclick=function(ev){var b=ev.target.closest('[data-i]');if(!b)return;var x=r.items[+b.getAttribute('data-i')];addAud(x.title,x.url)}})}
function audFiles(fl){Array.prototype.forEach.call(fl,function(f){if(/^audio\//.test(f.type)||/\.(mp3|wav|m4a|aac|ogg|flac)$/i.test(f.name))upload(f,'aud');else setStatus('That is not an audio file')})}
$('spn-drop').addEventListener('click',function(){$('spn-audpick').hidden=false;$('spn-audq').focus();audSearch()});
$('spn-drop').addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();this.click()}});
['dragenter','dragover'].forEach(function(n){$('spn-drop').addEventListener(n,function(e){e.preventDefault();this.classList.add('over')})});
['dragleave','drop'].forEach(function(n){$('spn-drop').addEventListener(n,function(e){e.preventDefault();this.classList.remove('over');if(n==='drop'&&e.dataTransfer)audFiles(e.dataTransfer.files)})});
$('spn-audq').addEventListener('input',function(){clearTimeout(audT);audT=setTimeout(audSearch,250)});
$('spn-audup').addEventListener('click',function(){pickFile('aud','audio/*')});
$('spn-audx').addEventListener('click',function(){$('spn-audbox').hidden=true});
$('spn-body').addEventListener('input',auds);
function expSel(){var ids=Object.keys(sel).map(Number),ns=ids.length?notes.filter(function(n){return sel[n.id]}):(cur&&cur.id?[cur]:[]);
 if(!ns.length){setStatus('Open or select a note first');return}
 var need=ns.filter(function(n){return n.slim}).map(function(n){return n.id});
 if(need.length){setStatus('Getting '+need.length+' notes…');var ch=[],i;for(i=0;i<need.length;i+=100)ch.push(need.slice(i,i+100));ch.reduce(function(pr,c){return pr.then(function(){return post({op:'getmany',ids:c.join(',')}).then(function(r){(r.notes||[]).forEach(merge)})})},Promise.resolve()).then(function(){setStatus('');var ids2=ns.map(function(n){return n.id});expSel2(notes.filter(function(n){return ids2.indexOf(n.id)>=0}))}).catch(function(){setStatus('Could not reach the server')});return}
 expSel2(ns)}
function expSel2(ns){
 download(ns.length===1?(head(ns[0]).replace(/[^\w -]+/g,'').trim()||'note')+'.md':'notes.md',ns.map(mdFile).join('\n---\n\n'))}
$('spn-pop').addEventListener('click',function(e){var x=e.target.closest('[data-go]');if(!x)return;var g=x.getAttribute('data-go');this.hidden=true;var eb=root.querySelector('[data-act=exp]');if(eb)eb.classList.remove('on');if(g==='sel')expSel();else if(D.urls[g])location.href=D.urls[g]});
document.addEventListener('click',function(e){var pm=$('spn-pop');if(!pm||pm.hidden)return;if(e.target.closest('#spn-pop')||e.target.closest('[data-act=exp]'))return;pm.hidden=true;var eb=root.querySelector('[data-act=exp]');if(eb)eb.classList.remove('on')});
$('spn-cal').addEventListener('click',function(e){var m=e.target.closest('[data-m]'),d=e.target.closest('[data-d]');if(m){calM+=+m.getAttribute('data-m');if(calM<0){calM=11;calY--}if(calM>11){calM=0;calY++}renderCal();return}if(d){var v=d.getAttribute('data-d');day=day===v?'':v;renderList()}});
function mdFile(n){return '# '+head(n)+'\n\n'+(n.notes||'')+'\n'}
function download(name,text){var a=document.createElement('a');a.href=URL.createObjectURL(new Blob([text],{type:'text/markdown'}));a.download=name;document.body.appendChild(a);a.click();setTimeout(function(){URL.revokeObjectURL(a.href);a.remove()},500)}
function pick(id){var n=notes.find(function(x){return x.id===id});if(!n)return;(dirty?save(true):Promise.resolve()).then(function(){fill(n);if(window.innerWidth<=800)root.classList.remove('m-list')})}
function newNote(){(dirty?save(true):Promise.resolve()).then(function(){fill(blank());$('spn-title').focus()})}

$('spn-tags').addEventListener('click',function(e){var b=e.target.closest('[data-tag]');if(!b)return;tag=b.getAttribute('data-tag');renderTags();renderList()});
$('spn-list').addEventListener('click',function(e){if(e.target.closest&&e.target.closest('[data-more]')){LIM+=300;renderList();return}
 if(e.target.closest('[data-allsel]')){visible().slice(0,LIM).forEach(function(n){sel[n.id]=true});renderList();return}
 if(e.target.closest('[data-delsel]')){var ids=Object.keys(sel);if(!ids.length)return;setStatus('Deleting…');post({op:'delete_many',ids:ids.join(',')}).then(function(r){if(!r.ok){setStatus(r.error||'Could not delete');return}var g={};r.deleted.forEach(function(i){g[i]=1});notes=notes.filter(function(x){return !g[x.id]});sel={};if(cur&&g[cur.id]){var v=visible();fill(v[0]||blank())}renderTags();renderList();setStatus('Deleted '+r.deleted.length+' notes.')}).catch(function(){setStatus('Could not reach the server')});return}

 var r=e.target.closest('.spn-row');if(!r)return;var id=+r.getAttribute('data-id');
 if(selMode||e.metaKey||e.ctrlKey||e.shiftKey){sel[id]=!sel[id];if(!sel[id])delete sel[id];renderList();return}
 pick(id)});
$('spn-search').addEventListener('input',function(){q=this.value;mark(cur&&cur.id);renderList();clearTimeout(fT);var k=q.trim().toLowerCase();if(k.length<2){FQ='';FS={};return}fT=setTimeout(function(){post({op:'find',q:k}).then(function(r){if(r.ok&&q.trim().toLowerCase()===k){FS={};r.ids.forEach(function(i){FS[i]=1});FQ=k;renderList()}}).catch(function(){})},350)});
root.querySelector('.spn-top').addEventListener('click',function(e){
 var b=e.target.closest('[data-act]');if(!b)return;var a=b.getAttribute('data-act');
 if(a==='new')newNote();
 else if(a==='imp')location.href=D.urls.import;
 else if(a==='exp'){var pm=$('spn-pop');pm.hidden=!pm.hidden;b.classList.toggle('on',!pm.hidden)}
 else if(a==='sel'){selMode=!selMode;b.classList.toggle('on',selMode);if(!selMode)sel={};renderList()}
 else if(a==='sort'){asc=!asc;renderList()}
 else if(a==='lay')root.classList.toggle('no-tags');else if(a==='foc')root.classList.toggle('focus')});
root.querySelector('.spn-main').addEventListener('click',function(e){
 var g=e.target.closest('[data-act]');if(!g)return;
 if(root.classList.contains('focus')){root.classList.remove('focus');return}
 if(g.getAttribute('data-act')==='list')root.classList.toggle('no-list');
 if(g.getAttribute('data-act')==='info')root.classList.toggle('no-info')});
$('spn-bar').addEventListener('click',function(e){
 var b=e.target.closest('[data-t]');if(!b)return;var t=b.getAttribute('data-t'),u;
 if(t==='b')wrap('**','**','bold');else if(t==='i')wrap('*','*','italic');else if(t==='h')lineStart('## ');else if(t==='ul')lineStart('- ');
 else if(t==='a'){u=window.prompt('Link address (https://…)');if(u)wrap('[',']('+u+')','link')}
 else if(t==='cp')wrap('\n```chordpro\n','\n```\n','[C]Words with [G]chords');
 else if(t==='img'){if(e.shiftKey||!D.canup){u=window.prompt('Image address');if(u)wrap('![',']('+u+')','image')}else pickFile('img','image/*')}
 else if(t==='aud'){if(e.shiftKey||!D.canup){u=window.prompt('Audio address');if(u)addAud(decodeURIComponent(u.split('/').pop().split('?')[0]).replace(/\.[^.]+$/,''),u)}else{var bx=$('spn-audbox');bx.hidden=!bx.hidden;if(!bx.hidden){$('spn-audpick').hidden=true;placeBox()}}}
 else if(t==='eye'){showPrev=!showPrev;$('spn-prev').hidden=!showPrev;$('spn-body').hidden=showPrev;if(showPrev)$('spn-prev').innerHTML=md(toRaw($('spn-body').value));b.classList.toggle('on',showPrev);auds()}
 else if(t==='pin'||t==='arch'){
  if(!cur||!cur.id){setStatus('Save the note first');return}
  post({op:'flag',entry_id:cur.id,flag:t==='pin'?'pinned':'archived'}).then(function(r){if(r.ok){cur=merge(r.note);b.classList.toggle('on',!!(t==='pin'?cur.pinned:cur.archived));renderTags();renderList()}})}
 else if(t==='del'){
  if(!cur||!cur.id){fill(blank());return}
  post({op:'delete',entry_id:cur.id}).then(function(r){if(r.ok){notes=notes.filter(function(x){return x.id!==r.deleted});var v=visible();fill(v[0]||blank());renderTags();renderList();setStatus('Deleted.')}else setStatus(r.error||'Could not delete')}).catch(function(){setStatus('Could not reach the server')})}});
['spn-title','spn-body','spn-f-date','spn-f-created','spn-f-place','spn-f-authors','spn-f-key','spn-f-tempo','spn-f-kind'].forEach(function(i){$(i).addEventListener('input',function(){if(i==='spn-body')sizeBody();touch()})});
$('spn-f-tag').addEventListener('keydown',function(e){
 if(e.key!=='Enter')return;e.preventDefault();var v=this.value.trim().replace(/^[#$]+/,'').replace(/\s+/g,'-');if(!v)return;
 var t=$('spn-body');t.value=t.value.replace(/\s*$/,'')+(t.value.trim()?'\n\n':'')+'#'+v;this.value='';sizeBody();touch()});
$('spn-body').addEventListener('keydown',function(e){if((e.metaKey||e.ctrlKey)&&e.key==='s'){e.preventDefault();save()}});
document.addEventListener('keydown',function(e){if(e.key==='Escape')root.classList.remove('focus')});
window.addEventListener('beforeunload',function(e){if(dirty){e.preventDefault();e.returnValue=''}});
var ks=$('spn-f-kind');Object.keys(D.kinds).forEach(function(k){var o=document.createElement('option');o.value=k;o.textContent=D.kinds[k];ks.appendChild(o)});
var secs=document.createElement('div');
renderTags();
var P=new URLSearchParams(location.search),pq=P.get('nq')||'',pn=parseInt(P.get('note')||'0',10);
if(pq){$('spn-search').value=pq;$('spn-search').dispatchEvent(new Event('input'))}
var v=visible(),bk=pn?notes.find(function(x){return x.id===pn}):null;
fill(bk||(pn?{id:pn,slim:true}:(v[0]||blank())));openMap();
})();
JS
. '</script>';
	}

	/** Each day: what was written, worked on and released, with links to the songs, newest first. */
	public static function days_html() {
		global $wpdb;
		$at   = Songpress_Install::table( 'activity' );
		$days = $wpdb->get_col( "SELECT DISTINCT day FROM {$at} ORDER BY day DESC LIMIT 90" ); // phpcs:ignore WordPress.DB
		if ( ! $days ) {
			return '<p class="spe-help">' . esc_html__( 'No days yet. Load the old catalog file on Upload / Download to bring the history across.', 'songpress' ) . '</p>';
		}
		$ph   = implode( ',', array_fill( 0, count( $days ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$at} WHERE day IN ({$ph}) ORDER BY day DESC, id", $days ) ); // phpcs:ignore WordPress.DB
		$by   = array();
		foreach ( $rows as $r ) {
			$by[ $r->day ][ $r->kind ][] = $r;
		}
		$lab = array( 'wrote' => __( 'Wrote', 'songpress' ), 'worked' => __( 'Worked on', 'songpress' ), 'released' => __( 'Released', 'songpress' ) );
		$o   = '<div class="spj-days">';
		foreach ( $days as $d ) {
			$o .= '<div class="spj-day"><h4>' . esc_html( $d ) . ' <small>' . esc_html( gmdate( 'l', strtotime( $d . ' 12:00:00 UTC' ) ) ) . '</small></h4>';
			foreach ( $lab as $k => $l ) {
				if ( empty( $by[ $d ][ $k ] ) ) {
					continue;
				}
				$items = array();
				foreach ( $by[ $d ][ $k ] as $r ) {
					$txt     = ( '' !== $r->serial ? $r->serial . ' ' : '' ) . $r->title;
					$items[] = (int) $r->song_id ? '<a href="' . esc_url( Songpress_Editor::edit_url( (int) $r->song_id ) ) . '">' . esc_html( $txt ) . '</a>' : esc_html( $txt );
				}
				$o .= '<p><b>' . esc_html( $l ) . ':</b> ' . implode( ', ', $items ) . '</p>';
			}
			$o .= '</div>';
		}
		return $o . '</div>';
	}

	/** Notes that have a position, on a map. Leaflet and OpenStreetMap tiles load from their own sites. */
	public static function map_html() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, log_date, title, place, latitude, longitude FROM ' . self::t() . " WHERE latitude <> '' AND longitude <> '' AND archived = 0 ORDER BY log_date DESC LIMIT 500" ); // phpcs:ignore WordPress.DB
		$pts  = array();
		foreach ( $rows as $r ) {
			$la = (float) $r->latitude;
			$lo = (float) $r->longitude;
			if ( abs( $la ) <= 90 && abs( $lo ) <= 180 && ( 0.0 !== $la || 0.0 !== $lo ) ) {
				$pts[] = array( $la, $lo, $r->log_date . ' ' . ( '' !== $r->title ? $r->title : $r->place ) );
			}
		}
		if ( ! $pts ) {
			return '<p class="spe-help">' . esc_html__( 'No notes have a position yet.', 'songpress' ) . '</p>';
		}
		return '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"><div id="spj-map" style="height:460px;border-radius:10px"></div><script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script><script>(function(){var P=' . wp_json_encode( $pts, JSON_HEX_TAG | JSON_HEX_AMP ) . ';if(!window.L)return;var m=L.map("spj-map");L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png",{maxZoom:18,attribution:"&copy; OpenStreetMap"}).addTo(m);var b=[];P.forEach(function(p){b.push([p[0],p[1]]);L.marker([p[0],p[1]]).addTo(m).bindPopup(document.createTextNode(p[2]).textContent);});m.fitBounds(b,{padding:[30,30],maxZoom:14});})();</script><p class="spe-help">' . esc_html( sprintf( /* translators: %d: notes */ _n( '%d note with a position.', '%d notes with a position.', count( $pts ), 'songpress' ), count( $pts ) ) ) . '</p>';
	}

	/* ---------- note files (.bear, .md, .txt) ---------- */

	/** One note file into a note. Understands front matter (--- title: ... ---), Bear exports ("# Title", authors, "key | tempo | date") and plain text. Pure. */
	public static function parse_note_file( $name, $text ) {
		$base = preg_replace( '/\.(bear|md|txt|markdown)$/i', '', basename( (string) $name ) );
		$text = str_replace( array( "\r\n", "\r" ), "\n", preg_replace( '/^\xEF\xBB\xBF/', '', (string) $text ) );
		$text = preg_replace( '/\[([^\]\n]*)\]\(bear:\/\/[^)\s]*\)/', '$1', $text ); // Bear's links to other notes and people mean nothing here; keep their words
		$n    = array( 'title' => '', 'date' => '', 'kind' => 'note', 'pinned' => 0, 'archived' => 0, 'song_key' => '', 'tempo' => '', 'authors' => '', 'place' => '', 'latitude' => '', 'longitude' => '', 'notes' => '' );
		if ( preg_match( '/^---\n(.*?)\n---\n?(.*)$/s', $text, $m ) ) {
			foreach ( explode( "\n", $m[1] ) as $line ) {
				if ( ! preg_match( '/^([A-Za-z_]+):\s*(.*)$/', $line, $k ) ) {
					continue;
				}
				$v = trim( $k[2] );
				$v = preg_replace( '/^([\'"])(.*)\1$/', '$2', $v );
				switch ( strtolower( $k[1] ) ) {
					case 'title':
						$n['title'] = $v;
						break;
					case 'date':
						$n['date'] = $v;
						break;
					case 'kind':
						$n['kind'] = self::kind( $v );
						break;
					case 'pinned':
						$n['pinned'] = 'true' === strtolower( $v ) ? 1 : 0;
						break;
					case 'archived':
						$n['archived'] = 'true' === strtolower( $v ) ? 1 : 0;
						break;
					case 'key':
						$n['song_key'] = $v;
						break;
					case 'tempo':
						$n['tempo'] = $v;
						break;
					case 'authors':
						$n['authors'] = $v;
						break;
					case 'place':
						$n['place'] = $v;
						break;
					case 'lat':
					case 'latitude':
						$n['latitude'] = $v;
						break;
					case 'lng':
					case 'longitude':
						$n['longitude'] = $v;
						break;
				}
			}
			$n['notes'] = rtrim( $m[2] );
			if ( '' === $n['title'] ) {
				$n['title'] = $base;
			}
			return $n;
		}
		$lines = explode( "\n", $text );
		if ( isset( $lines[0], $lines[2] ) && preg_match( '/^#+\s/', $lines[0] ) && false !== strpos( $lines[2], '|' ) ) {
			// Bear: "# Title", then authors, then "key | tempo | date", then the note
			$n['title'] = trim( preg_replace( '/^#+\s*/', '', $lines[0] ) );
			$i          = 1;
			if ( false === strpos( $lines[1], '|' ) ) {
				$n['authors'] = trim( $lines[1] );
				$i            = 2;
			}
			if ( isset( $lines[ $i ] ) && false !== strpos( $lines[ $i ], '|' ) ) {
				$p = array_map( 'trim', explode( '|', $lines[ $i ] ) );
				$n['song_key'] = ( '?' === ( $p[0] ?? '' ) ) ? '' : ( $p[0] ?? '' );
				$n['tempo']    = preg_match( '/^\?\s*(bpm)?$/i', $p[1] ?? '' ) ? '' : ( $p[1] ?? '' );
				$d             = $p[2] ?? '';
				if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $d, $dm ) ) {
					$n['date'] = $dm[1];
				} elseif ( preg_match( '/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $d, $dm ) ) {
					$n['date'] = sprintf( '%04d-%02d-%02d', $dm[3], $dm[1], $dm[2] );
				}
				++$i;
			}
			$n['notes'] = rtrim( implode( "\n", array_slice( $lines, $i ) ) );
			if ( '' === $n['title'] ) {
				$n['title'] = $base;
			}
			return $n;
		}
		$n['title'] = $base;
		$n['notes'] = rtrim( $text );
		if ( isset( $lines[0] ) && preg_match( '/^#\s+(.+)$/', $lines[0], $h ) ) {
			$n['title'] = trim( $h[1] );
			$n['notes'] = rtrim( ltrim( implode( "\n", array_slice( $lines, 1 ) ), "\n" ) );
		}
		return $n;
	}

	/** A note as a Markdown file (front matter, then the text). Pure. */
	public static function note_to_md( $e ) {
		$fm = array( 'title' => $e->title, 'date' => $e->log_date, 'kind' => $e->kind, 'pinned' => (int) $e->pinned ? 'true' : 'false', 'archived' => (int) $e->archived ? 'true' : 'false', 'key' => $e->song_key, 'tempo' => $e->tempo, 'authors' => $e->authors, 'place' => $e->place, 'latitude' => $e->latitude, 'longitude' => $e->longitude );
		$o  = "---\n";
		foreach ( $fm as $k => $v ) {
			if ( '' !== (string) $v ) {
				$o .= $k . ': ' . str_replace( "\n", ' ', (string) $v ) . "\n";
			}
		}
		return $o . "---\n" . (string) $e->notes . "\n";
	}

	/** A note in the Bear layout: "# Title", authors, "key | tempo | date", then the note. Always has the authors line so it reads back. Pure. */
	public static function note_to_bear( $e ) {
		return '# ' . str_replace( "\n", ' ', (string) $e->title ) . "\n" . str_replace( "\n", ' ', (string) $e->authors ) . "\n" . $e->song_key . ' | ' . $e->tempo . ' | ' . $e->log_date . "\n\n" . (string) $e->notes . "\n";
	}

	/** A note as a TextBundle (the folder format Bear imports): info.json and text.md. Untitled notes are named after their date. Pure. */
	public static function note_to_textbundle( $e ) {
		$c = clone $e;
		if ( '' === trim( (string) $c->title ) ) {
			$c->title = ucfirst( (string) $c->kind ) . ' ' . $c->log_date;
		}
		return array(
			'info' => wp_json_encode( array( 'version' => 2, 'type' => 'net.daringfireball.markdown', 'transient' => true, 'creatorIdentifier' => 'com.songpress' ) ),
			'text' => self::note_to_bear( $c ),
		);
	}

	/** Add one parsed note. A note with the same source key is not added twice. Returns true when it was added. */
	public static function add_note( array $n, $source_key = '' ) {
		global $wpdb;
		$j = self::t();
		if ( '' !== $source_key && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$j} WHERE source_key = %s", $source_key ) ) ) { // phpcs:ignore WordPress.DB
			return false;
		}
		$byser = array();
		foreach ( self::serials_in( $n['title'] . "\n" . $n['notes'] ) as $sr ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Songpress_Install::table( 'songs' ) . ' WHERE serial = %s', $sr ) ); // phpcs:ignore WordPress.DB
			if ( $id ) {
				$byser[ $sr ] = $id;
			}
		}
		$wpdb->insert( $j, array(
			'log_date' => preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $n['date'] ) ? substr( $n['date'], 0, 10 ) : gmdate( 'Y-m-d' ),
			'kind' => self::kind( (string) $n['kind'] ), 'title' => mb_substr( (string) $n['title'], 0, 255 ), 'notes' => (string) $n['notes'],
			'place' => mb_substr( (string) $n['place'], 0, 191 ), 'song_id' => self::sole_song( $n['title'] . "\n" . $n['notes'], $byser ),
			'pinned' => (int) $n['pinned'], 'archived' => (int) $n['archived'], 'song_key' => mb_substr( (string) $n['song_key'], 0, 16 ), 'tempo' => mb_substr( (string) $n['tempo'], 0, 16 ),
			'authors' => mb_substr( (string) $n['authors'], 0, 255 ), 'latitude' => mb_substr( (string) $n['latitude'], 0, 24 ), 'longitude' => mb_substr( (string) $n['longitude'], 0, 24 ),
			'source_key' => '' === $source_key ? null : $source_key, 'user_id' => get_current_user_id(), 'created_at' => current_time( 'mysql' ),
		) );
		return true;
	}
}

