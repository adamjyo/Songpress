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

	public static function handle_save() {
		self::guard( 'songpress_journal_save' );
		global $wpdb;
		$in    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- cleaned below.
		$id    = absint( $in['entry_id'] ?? 0 );
		$notes = (string) ( $in['notes'] ?? '' );
		$title = sanitize_text_field( $in['title'] ?? '' );
		if ( '' === trim( $notes ) && '' === $title ) {
			self::go( array( 'j_err' => __( 'Write something first.', 'songpress' ) ) );
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
			$wpdb->update( self::t(), $row, array( 'id' => $id ) );
		} else {
			$wpdb->insert( self::t(), array_merge( $row, array( 'user_id' => get_current_user_id(), 'created_at' => '' !== $cr ? $cr : current_time( 'mysql' ) ) ) );
		}
		self::go( array( 'j_ok' => $id ? __( 'Entry saved.', 'songpress' ) : __( 'Entry added.', 'songpress' ) ) );
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
		$q    = isset( $_GET['jq'] ) ? sanitize_text_field( wp_unslash( $_GET['jq'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$show = isset( $_GET['jshow'] ) && 'archived' === $_GET['jshow'] ? 'archived' : 'live'; // phpcs:ignore WordPress.Security.NonceVerification
		$page = isset( $_GET['jp'] ) ? max( 1, absint( $_GET['jp'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$res  = self::search( $q, $show, $page );
		$base = Songpress_Editor::list_url();
		$o    = '<div class="songpress sp-app sp-editor sp-journal"><div class="spe-top"><h2>' . esc_html__( 'Notes', 'songpress' ) . '</h2></div>';
		if ( ! empty( $_GET['j_ok'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<p class="spe-note ok">' . esc_html( sanitize_text_field( wp_unslash( $_GET['j_ok'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( ! empty( $_GET['j_err'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<p class="spe-note bad" role="alert">' . esc_html( sanitize_text_field( wp_unslash( $_GET['j_err'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		$view = isset( $_GET['jview'] ) && in_array( $_GET['jview'], array( 'days', 'map' ), true ) ? sanitize_key( wp_unslash( $_GET['jview'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification
		$o   .= '<p class="spj-tabs">';
		foreach ( array( 'list' => __( 'Notes', 'songpress' ), 'days' => __( 'Days', 'songpress' ), 'map' => __( 'Map', 'songpress' ) ) as $vk => $vl ) {
			$o .= '<a class="spe-btn' . ( $view === $vk ? ' on' : '' ) . '" href="' . esc_url( add_query_arg( array( 'sp_page' => 'notes', 'jview' => $vk ), $base ) ) . '">' . esc_html( $vl ) . '</a> ';
		}
		$o .= '<a class="spe-btn" href="' . esc_url( add_query_arg( array( 'sp_page' => 'uploads', 'type' => 'notes_files' ), $base ) ) . '">' . esc_html__( 'Import notes (.bear, .md, .txt)', 'songpress' ) . '</a> <a class="spe-btn" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=songpress_download&table=notes_md' ), 'songpress_download' ) ) . '">' . esc_html__( 'Export all as .md', 'songpress' ) . '</a> <a class="spe-btn" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=songpress_download&table=notes_bear' ), 'songpress_download' ) ) . '">' . esc_html__( 'Export all as .bear', 'songpress' ) . '</a></p>';
		if ( 'days' === $view ) {
			return $o . self::days_html() . '</div>';
		}
		if ( 'map' === $view ) {
			return $o . self::map_html() . '</div>';
		}
		$o .= '<details class="spj-new"><summary class="spe-btn">+ ' . esc_html__( 'New entry', 'songpress' ) . '</summary>' . self::form_open( 'songpress_journal_save' ) . self::fields() . '<button class="spe-save" type="submit">' . esc_html__( 'Add entry', 'songpress' ) . '</button></form></details>';
		$o .= '<form method="get" class="spj-find"><input type="hidden" name="sp_page" value="notes"><input type="search" name="jq" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Search notes', 'songpress' ) . '"><select name="jshow" onchange="this.form.submit()"><option value="live"' . selected( $show, 'live', false ) . '>' . esc_html__( 'Current', 'songpress' ) . '</option><option value="archived"' . selected( $show, 'archived', false ) . '>' . esc_html__( 'Archived', 'songpress' ) . '</option></select><button class="spe-btn" type="submit">' . esc_html__( 'Search', 'songpress' ) . '</button></form>';
		$o .= '<p class="spe-help">' . esc_html( sprintf( /* translators: %d: number of entries */ _n( '%d entry', '%d entries', $res['total'], 'songpress' ), $res['total'] ) ) . '</p>';
		foreach ( $res['rows'] as $e ) {
			$head = trim( $e->title ) !== '' ? $e->title : ucfirst( $e->kind );
			$o   .= '<details class="spj-entry' . ( (int) $e->pinned ? ' pinned' : '' ) . '"><summary><time>' . esc_html( $e->log_date ) . '</time> <b>' . esc_html( $head ) . '</b>' . ( (int) $e->pinned ? ' <span class="spj-pin">★</span>' : '' ) . ( '' !== $e->place ? ' <span class="spe-help">' . esc_html( $e->place ) . '</span>' : '' ) . '</summary>';
			$men = self::mentioned( $e->title . "\n" . $e->notes );
			if ( $men ) {
				$o .= '<p class="spj-mention">' . esc_html__( 'Songs:', 'songpress' ) . ' ';
				foreach ( $men as $m ) {
					$o .= '<a href="' . esc_url( Songpress_Editor::edit_url( (int) $m->id ) ) . '"><span class="sp-serial">' . esc_html( $m->serial ) . '</span> ' . esc_html( $m->title ) . '</a> ';
				}
				$o .= '</p>';
			}
			if ( (int) $e->song_id && ! $men ) {
				$o .= '<p><a href="' . esc_url( Songpress_Editor::edit_url( (int) $e->song_id ) ) . '">' . esc_html__( 'Open the song', 'songpress' ) . '</a></p>';
			}
			$o .= self::form_open( 'songpress_journal_save' ) . '<input type="hidden" name="entry_id" value="' . (int) $e->id . '">' . self::fields( $e ) . '<button class="spe-save" type="submit">' . esc_html__( 'Save', 'songpress' ) . '</button></form>';
			foreach ( array( 'pinned' => array( 'Pin', 'Unpin' ), 'archived' => array( 'Archive', 'Restore' ) ) as $flag => $labels ) {
				$o .= self::form_open( 'songpress_journal_flag', 'spj-flag' ) . '<input type="hidden" name="entry_id" value="' . (int) $e->id . '"><input type="hidden" name="flag" value="' . esc_attr( $flag ) . '"><button class="spe-btn" type="submit">' . esc_html( (int) $e->$flag ? $labels[1] : $labels[0] ) . '</button></form>';
			}
			$o .= '</details>';
		}
		$pages = (int) ceil( $res['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			$o .= '<p class="spj-pages">';
			$arg = array( 'sp_page' => 'notes', 'jq' => $q, 'jshow' => $show );
			if ( $page > 1 ) {
				$o .= '<a class="spe-btn" href="' . esc_url( add_query_arg( array_merge( $arg, array( 'jp' => $page - 1 ) ), $base ) ) . '">' . esc_html__( 'Newer', 'songpress' ) . '</a> ';
			}
			$o .= esc_html( $page . ' / ' . $pages );
			if ( $page < $pages ) {
				$o .= ' <a class="spe-btn" href="' . esc_url( add_query_arg( array_merge( $arg, array( 'jp' => $page + 1 ) ), $base ) ) . '">' . esc_html__( 'Older', 'songpress' ) . '</a>';
			}
			$o .= '</p>';
		}
		return $o . '</div>';
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
				$n['song_key'] = $p[0] ?? '';
				$n['tempo']    = $p[1] ?? '';
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

