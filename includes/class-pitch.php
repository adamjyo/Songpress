<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pitch: getting a song heard. Where to send it (playlists, radio, worship, sync), ready-to-send copy and a one-sheet
 * built from the work's own details, and a log of who it was sent to, when to follow up, and how it went.
 * Nothing is sent from here and nobody is emailed: it writes the words, you send them.
 */
class Songpress_Pitch {

	const OUTCOMES = array(
		'open'     => 'Waiting',
		'replied'  => 'Replied',
		'placed'   => 'Placed',
		'passed'   => 'Passed',
		'no_reply' => 'No reply',
	);

	public static function init() {
		add_action( 'admin_post_songpress_pitch_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_songpress_pitch_update', array( __CLASS__, 'handle_update' ) );
		add_action( 'admin_post_songpress_pitch_delete', array( __CLASS__, 'handle_delete' ) );
	}

	private static function t() {
		return Songpress_Install::table( 'pitches' );
	}

	/** Groups of channels: label and items (id, name, cost, blurb, url, cta). */
	public static function channels() {
		static $c = null;
		if ( null === $c ) {
			$c = require SONGPRESS_DIR . 'includes/data-pitch-channels.php';
		}
		return $c;
	}

	/** Channel id => name, for the log's dropdown. */
	public static function channel_names() {
		$o = array();
		foreach ( self::channels() as $g ) {
			foreach ( $g['items'] as $i ) {
				$o[ $i['id'] ] = $i['name'];
			}
		}
		return $o;
	}

	/* ---------- the facts and the words (pure) ---------- */

	/** What a pitch needs to know about a work, in one array. Read from the database. */
	public static function facts( $song ) {
		$w = array();
		foreach ( Songpress_Songs::credits( (int) $song->id ) as $c ) {
			if ( 'writer' === $c->role && '' !== $c->name ) {
				$w[] = array( 'name' => (string) $c->name, 'share' => null === $c->share ? '' : rtrim( rtrim( number_format( (float) $c->share, 2, '.', '' ), '0' ), '.' ) );
			}
		}
		$ids = array();
		foreach ( Songpress_Songs::identifiers( (int) $song->id ) as $i ) {
			if ( ! isset( $ids[ $i->kind ] ) ) {
				$ids[ $i->kind ] = (string) $i->value;
			}
		}
		return array(
			'title'   => (string) $song->title,
			'serial'  => (string) $song->serial,
			'band'    => (string) $song->artist,
			'writers' => $w,
			'genre'   => (string) $song->genre,
			'key'     => (string) $song->musical_key,
			'tempo'   => (string) $song->tempo,
			'isrc'    => $ids['isrc'] ?? '',
			'iswc'    => $ids['iswc'] ?? '',
			'audio'   => Songpress_Media::audio_url( $song ),
			'art'     => Songpress_Media::art_url( $song, 'full' ),
			'about'   => (string) $song->story,
			'status'  => (string) $song->status,
			'release' => (string) $song->release_date,
		);
	}

	private static function names( array $f ) {
		return implode( ', ', array_column( $f['writers'], 'name' ) );
	}

	private static function who( array $f ) {
		return '' !== $f['band'] ? $f['band'] : self::names( $f );
	}

	private static function story( array $f ) {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $f['about'] ) ) );
	}

	/** What is missing before this work is ready to pitch. [ [ok, label], … ] */
	public static function readiness( array $f ) {
		$sh = 0.0;
		foreach ( $f['writers'] as $w ) {
			$sh += (float) $w['share'];
		}
		return array(
			array( '' !== $f['title'], __( 'Title', 'songpress' ) ),
			array( '' !== $f['audio'], __( 'Audio file', 'songpress' ) ),
			array( '' !== $f['art'], __( 'Artwork', 'songpress' ) ),
			array( (bool) $f['writers'], __( 'Writers listed', 'songpress' ) ),
			array( $sh >= 95 && $sh <= 105, __( 'Writer shares near 100%', 'songpress' ) ),
			array( '' !== $f['genre'], __( 'Genre', 'songpress' ) ),
			array( '' !== trim( $f['about'] ), __( 'About / story (the heart of a pitch)', 'songpress' ) ),
			array( '' !== $f['isrc'], __( 'ISRC (needed once released)', 'songpress' ) ),
			array( '' !== $f['iswc'], __( 'ISWC (helps sync and your PRO)', 'songpress' ) ),
			array( '' !== $f['key'] && '' !== $f['tempo'], __( 'Key and tempo (sync tags)', 'songpress' ) ),
		);
	}

	/** An email to a radio music director. */
	public static function radio_copy( array $f ) {
		$who   = self::who( $f );
		$lines = array(
			'Subject: ' . ( '' !== $f['genre'] ? strtoupper( $f['genre'] ) . ' ' : '' ) . 'Submission: ' . ( '' !== $who ? $who . ' – ' : '' ) . '"' . $f['title'] . '"',
			'',
			'Dear [Music Director],',
			'',
			'Please consider "' . $f['title'] . '"' . ( '' !== $who ? ' by ' . $who : '' ) . ' for airplay.',
		);
		$meta = array_filter( array(
			'' !== $f['genre'] ? 'Format/genre: ' . $f['genre'] : '',
			'' !== $f['tempo'] ? 'Tempo: ' . $f['tempo'] . ' BPM' : '',
			'' !== $f['key'] ? 'Key: ' . $f['key'] : '',
			'' !== $f['isrc'] ? 'ISRC: ' . $f['isrc'] : '',
			'' !== $f['serial'] ? 'Catalog: ' . $f['serial'] : '',
		) );
		if ( $meta ) {
			$lines[] = implode( "\n", $meta );
		}
		if ( $f['writers'] && '' !== $f['band'] ) {
			$lines[] = 'Writers: ' . self::names( $f ) . '.';
		}
		if ( '' !== self::story( $f ) ) {
			$lines[] = self::story( $f );
		}
		$lines[] = '';
		$lines[] = 'Broadcast file: WAV (or 320 kbps MP3) download — not a streaming link.';
		if ( '' !== $f['audio'] ) {
			$lines[] = 'Listen / download: ' . $f['audio'];
		}
		$lines[] = '';
		$lines[] = 'Happy to send a clean/radio edit if you need one.';
		$lines[] = 'Thank you for the time.';
		return implode( "\n", $lines );
	}

	/** The 500-character pitch Spotify for Artists asks for. */
	public static function spotify_copy( array $f ) {
		$who  = self::who( $f );
		$head = '"' . $f['title'] . '"' . ( '' !== $who ? ' by ' . $who : '' );
		$meta = array_filter( array( $f['genre'], '' !== $f['tempo'] ? $f['tempo'] . ' BPM' : '', '' !== $f['key'] ? 'key of ' . $f['key'] : '' ) );
		$bits = array( $head . ( $meta ? ' — ' . implode( ', ', $meta ) : '' ) . '.' );
		if ( $f['writers'] && '' !== $f['band'] && 0 !== strcasecmp( self::names( $f ), $f['band'] ) ) {
			$bits[] = 'Written by ' . self::names( $f ) . '.';
		}
		$s = self::story( $f );
		if ( '' !== $s ) {
			$bits[] = mb_strlen( $s ) > 220 ? mb_substr( $s, 0, 217 ) . '…' : $s;
		}
		$bits[] = 'Fits ' . ( '' !== $f['genre'] ? $f['genre'] : 'mood' ) . ' and new-music playlists.';
		$out = implode( ' ', $bits );
		return mb_strlen( $out ) > 500 ? mb_substr( $out, 0, 497 ) . '…' : $out;
	}

	/** A short note to a curator, blogger or contact. */
	public static function email_copy( array $f ) {
		$who   = self::who( $f );
		$lines = array( 'Hi [Name],', '', 'I am sharing "' . $f['title'] . '"' . ( '' !== $who ? ' by ' . $who : '' ) . '.' );
		$meta  = array_filter( array( $f['genre'], '' !== $f['tempo'] ? $f['tempo'] . ' BPM' : '', '' !== $f['key'] ? 'key ' . $f['key'] : '' ) );
		if ( $meta ) {
			$lines[] = implode( ' · ', $meta ) . '.';
		}
		if ( $f['writers'] && '' !== $f['band'] ) {
			$lines[] = 'Writers: ' . self::names( $f ) . '.';
		}
		if ( '' !== self::story( $f ) ) {
			$lines[] = self::story( $f );
		}
		if ( '' !== $f['audio'] ) {
			$lines[] = 'Listen: ' . $f['audio'];
		}
		$lines[] = '';
		$lines[] = 'Thanks for a listen either way — no worries if it is not a fit.';
		return implode( "\n", $lines );
	}

	/** One page of facts for a music supervisor, station or curator. */
	public static function onesheet( array $f ) {
		$w = array();
		foreach ( $f['writers'] as $x ) {
			$w[] = $x['name'] . ( '' !== $x['share'] ? ' (' . $x['share'] . '%)' : '' );
		}
		$lines = array_filter( array(
			'ONE-SHEET',
			$f['title'],
			'' !== $f['serial'] ? 'Catalog: ' . $f['serial'] : '',
			'' !== $f['band'] ? 'Artist / band: ' . $f['band'] : '',
			'' !== $f['release'] ? 'Release date: ' . $f['release'] : '',
			'' !== $f['genre'] ? 'Genre: ' . $f['genre'] : '',
			'' !== $f['key'] ? 'Key: ' . $f['key'] : '',
			'' !== $f['tempo'] ? 'Tempo: ' . $f['tempo'] : '',
			'' !== $f['isrc'] ? 'ISRC: ' . $f['isrc'] : '',
			'' !== $f['iswc'] ? 'ISWC: ' . $f['iswc'] : '',
			$w ? 'Writers: ' . implode( '; ', $w ) : '',
			'' !== $f['audio'] ? 'Audio: ' . $f['audio'] : '',
			'' !== $f['art'] ? 'Artwork: ' . $f['art'] : '',
		), function ( $l ) {
			return '' !== $l;
		} );
		$lines[] = '';
		$lines[] = 'SYNC NOTES';
		$lines[] = 'Master: controlled by the catalog owner (confirm before quoting).';
		$lines[] = 'Publishing: splits as listed. Instrumental: attach if you have one.';
		$lines[] = 'No uncleared samples (confirm before sending).';
		if ( '' !== trim( (string) $f['about'] ) ) {
			$lines[] = '';
			$lines[] = 'ABOUT';
			$lines[] = trim( wp_strip_all_tags( (string) $f['about'] ) );
		}
		return implode( "\n", $lines );
	}

	/* ---------- the log ---------- */

	public static function outcome( $o ) {
		return isset( self::OUTCOMES[ $o ] ) ? $o : 'open';
	}

	private static function date_in( $d ) {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $d ) ? (string) $d : '';
	}

	/** Add a pitch to the log. Returns its id, or 0 when there is no work or nothing to say where it went. */
	public static function add( array $in ) {
		global $wpdb;
		$sid = absint( $in['song_id'] ?? 0 );
		$to  = mb_substr( sanitize_text_field( $in['sent_to'] ?? '' ), 0, 191 );
		$ch  = sanitize_key( $in['channel'] ?? '' );
		if ( ! $sid || ( '' === $to && '' === $ch ) ) {
			return 0;
		}
		$now = current_time( 'mysql' );
		$wpdb->insert( self::t(), array(
			'song_id'    => $sid,
			'channel'    => mb_substr( $ch, 0, 64 ),
			'sent_to'    => $to,
			'sent_at'    => self::date_in( $in['sent_at'] ?? '' ) ?: substr( $now, 0, 10 ),
			'follow_up'  => self::date_in( $in['follow_up'] ?? '' ),
			'outcome'    => 'open',
			'notes'      => sanitize_textarea_field( $in['notes'] ?? '' ),
			'user_id'    => get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
		) );
		return (int) $wpdb->insert_id;
	}

	public static function update( $id, array $in ) {
		global $wpdb;
		$row = array( 'outcome' => self::outcome( (string) ( $in['outcome'] ?? '' ) ), 'follow_up' => self::date_in( $in['follow_up'] ?? '' ), 'notes' => sanitize_textarea_field( $in['notes'] ?? '' ), 'updated_at' => current_time( 'mysql' ) );
		if ( 'open' !== $row['outcome'] && ! isset( $in['follow_up'] ) ) {
			$row['follow_up'] = '';
		}
		return (bool) $wpdb->update( self::t(), $row, array( 'id' => (int) $id ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::t(), array( 'id' => (int) $id ) );
	}

	/** Pitches with the work's serial and title. filter: all, open, due. */
	public static function rows( $filter = 'all', $song_id = 0, $today = '' ) {
		global $wpdb;
		$today = '' === $today ? gmdate( 'Y-m-d' ) : $today;
		$s     = Songpress_Install::table( 'songs' );
		$where = array( '1=1' );
		$args  = array();
		if ( $song_id ) {
			$where[] = 'p.song_id = %d';
			$args[]  = (int) $song_id;
		}
		if ( 'open' === $filter ) {
			$where[] = "p.outcome = 'open'";
		} elseif ( 'due' === $filter ) {
			$where[] = "p.outcome = 'open' AND p.follow_up <> '' AND p.follow_up <= %s";
			$args[]  = $today;
		}
		$sql = 'SELECT p.*, s.serial, s.title FROM ' . self::t() . " p LEFT JOIN {$s} s ON s.id = p.song_id WHERE " . implode( ' AND ', $where ) . ' ORDER BY p.sent_at DESC, p.id DESC LIMIT 500';
		return $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore WordPress.DB
	}

	/* ---------- handlers ---------- */

	private static function guard( $nonce ) {
		check_admin_referer( $nonce );
		if ( ! Songpress_Caps::can( 'pitch' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
	}

	private static function go( $args = array() ) {
		$ret = isset( $_POST['sp_return'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['sp_return'] ) ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- after guard().
		wp_safe_redirect( add_query_arg( array_merge( array( 'sp_page' => 'pitch' ), $args ), '' !== $ret ? remove_query_arg( array( 'p_ok', 'p_err' ), $ret ) : Songpress_Editor::list_url() ) );
		exit;
	}

	public static function handle_save() {
		self::guard( 'songpress_pitch_save' );
		$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- cleaned in add().
		$ser = preg_replace( '/\s+/', '', (string) ( $in['serial'] ?? '' ) );
		if ( '' !== $ser && empty( $in['song_id'] ) ) {
			global $wpdb;
			$in['song_id'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Songpress_Install::table( 'songs' ) . ' WHERE serial = %s', $ser ) ); // phpcs:ignore WordPress.DB
		}
		$id = self::add( $in );
		self::go( $id ? array( 'p_ok' => rawurlencode( __( 'Pitch logged.', 'songpress' ) ), 'ps' => 'log' ) : array( 'p_err' => rawurlencode( __( 'Pick a work (its serial) and say where it went.', 'songpress' ) ), 'ps' => 'log' ) );
	}

	public static function handle_update() {
		self::guard( 'songpress_pitch_update' );
		self::update( absint( $_POST['pitch_id'] ?? 0 ), wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- cleaned in update().
		self::go( array( 'p_ok' => rawurlencode( __( 'Saved.', 'songpress' ) ), 'ps' => 'log' ) );
	}

	public static function handle_delete() {
		self::guard( 'songpress_pitch_delete' );
		self::delete( absint( $_POST['pitch_id'] ?? 0 ) );
		self::go( array( 'p_ok' => rawurlencode( __( 'Removed.', 'songpress' ) ), 'ps' => 'log' ) );
	}

	/* ---------- screen ---------- */

	public static function due_count() {
		return count( self::rows( 'due' ) );
	}

	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'sp_page' => 'pitch' ), $args ), Songpress_Editor::list_url() );
	}

	public static function render() {
		if ( ! Songpress_Caps::can( 'pitch' ) ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'Only Managers can open this screen.', 'songpress' ) . '</p></div>';
		}
		$ps   = isset( $_GET['ps'] ) ? sanitize_key( wp_unslash( $_GET['ps'] ) ) : 'log'; // phpcs:ignore WordPress.Security.NonceVerification
		$ps   = in_array( $ps, array( 'log', 'where', 'kit' ), true ) ? $ps : 'log';
		$due  = self::due_count();
		$tabs = array( 'log' => __( 'Pitch log', 'songpress' ), 'where' => __( 'Where to pitch', 'songpress' ), 'kit' => __( 'Pitch kit', 'songpress' ) );
		$o    = '<div class="songpress sp-app sp-editor sp-pitch"><div class="spe-top"><h2>' . esc_html__( 'Pitch', 'songpress' ) . '</h2></div><nav class="sp-tabs">';
		foreach ( $tabs as $k => $l ) {
			$o .= '<a href="' . esc_url( self::url( array( 'ps' => $k ) ) ) . '"' . ( $k === $ps ? ' class="on" aria-current="page"' : '' ) . '>' . esc_html( $l ) . ( 'log' === $k && $due ? ' <span class="spp-due">' . (int) $due . '</span>' : '' ) . '</a>';
		}
		$o .= '</nav>';
		if ( ! empty( $_GET['p_ok'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<p class="spe-note ok">' . esc_html( sanitize_text_field( wp_unslash( $_GET['p_ok'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( ! empty( $_GET['p_err'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<p class="spe-note bad" role="alert">' . esc_html( sanitize_text_field( wp_unslash( $_GET['p_err'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( 'where' === $ps ) {
			return $o . self::render_where() . '</div>';
		}
		if ( 'kit' === $ps ) {
			return $o . self::render_kit() . '</div>';
		}
		return $o . self::render_log() . '</div>';
	}

	private static function work_from_get() {
		global $wpdb;
		$sid = isset( $_GET['song'] ) ? absint( $_GET['song'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$ser = isset( $_GET['serial'] ) ? preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_GET['serial'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $sid && '' !== $ser ) {
			$sid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Songpress_Install::table( 'songs' ) . ' WHERE serial = %s', $ser ) ); // phpcs:ignore WordPress.DB
		}
		return $sid ? Songpress_Songs::get( $sid ) : null;
	}

	private static function log_form( $song = null, $channel = '' ) {
		$names = self::channel_names();
		$o     = Songpress_Money::form_open( 'songpress_pitch_save', 'spp-form' );
		$o    .= '<input type="hidden" name="sp_return" value="' . esc_attr( self::url( array( 'ps' => 'log' ) ) ) . '">';
		$o    .= '<label class="spe-field"><span>' . esc_html__( 'Work (serial)', 'songpress' ) . '</span><input name="serial" size="10" placeholder="0378" required value="' . esc_attr( $song ? (string) $song->serial : '' ) . '"></label>';
		$o    .= '<label class="spe-field"><span>' . esc_html__( 'Where', 'songpress' ) . '</span><select name="channel"><option value="">' . esc_html__( 'Other / a person', 'songpress' ) . '</option>';
		foreach ( $names as $id => $n ) {
			$o .= '<option value="' . esc_attr( $id ) . '"' . selected( $channel, $id, false ) . '>' . esc_html( $n ) . '</option>';
		}
		$o .= '</select></label>';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Sent to (name, station, curator)', 'songpress' ) . '</span><input name="sent_to"></label>';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Sent', 'songpress' ) . '</span><input type="date" name="sent_at" value="' . esc_attr( gmdate( 'Y-m-d' ) ) . '"></label>';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Follow up on', 'songpress' ) . '</span><input type="date" name="follow_up" value="' . esc_attr( gmdate( 'Y-m-d', time() + 14 * DAY_IN_SECONDS ) ) . '"></label>';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Notes', 'songpress' ) . '</span><textarea name="notes" rows="2"></textarea></label>';
		return $o . '<button class="spe-save" type="submit">' . esc_html__( 'Log this pitch', 'songpress' ) . '</button></form>';
	}

	private static function render_log() {
		$flt = isset( $_GET['pf'] ) ? sanitize_key( wp_unslash( $_GET['pf'] ) ) : 'open'; // phpcs:ignore WordPress.Security.NonceVerification
		$flt = in_array( $flt, array( 'open', 'due', 'all' ), true ) ? $flt : 'open';
		$o   = '<details class="spp-new"' . ( isset( $_GET['song'] ) ? ' open' : '' ) . '><summary>+ ' . esc_html__( 'Log a pitch', 'songpress' ) . '</summary>' . self::log_form( self::work_from_get() ) . '</details><p>'; // phpcs:ignore WordPress.Security.NonceVerification
		foreach ( array( 'open' => __( 'Waiting', 'songpress' ), 'due' => __( 'Follow-up due', 'songpress' ), 'all' => __( 'Everything', 'songpress' ) ) as $k => $l ) {
			$o .= '<a class="spe-btn' . ( $k === $flt ? ' on' : '' ) . '" href="' . esc_url( self::url( array( 'ps' => 'log', 'pf' => $k ) ) ) . '">' . esc_html( $l ) . '</a> ';
		}
		$o   .= '</p>';
		$rows = self::rows( $flt );
		if ( ! $rows ) {
			return $o . '<p class="spe-help">' . esc_html( 'due' === $flt ? __( 'Nothing is due. Nice.', 'songpress' ) : __( 'No pitches yet. Open the Pitch kit for a work, send your pitch, then log it here so you remember to follow up.', 'songpress' ) ) . '</p>';
		}
		$names = self::channel_names();
		$today = gmdate( 'Y-m-d' );
		foreach ( $rows as $r ) {
			$late = 'open' === $r->outcome && '' !== $r->follow_up && $r->follow_up <= $today;
			$o   .= '<details class="spp-row"><summary><span><b>' . esc_html( (string) $r->serial ) . '</b> ' . esc_html( (string) $r->title ) . '</span><span class="spp-meta">' . esc_html( ( $names[ $r->channel ] ?? '' ) . ( '' !== $r->sent_to ? ( isset( $names[ $r->channel ] ) ? ' · ' : '' ) . $r->sent_to : '' ) ) . '</span><span class="spp-chip ' . esc_attr( $late ? 'late' : $r->outcome ) . '">' . esc_html( $late ? __( 'Follow up', 'songpress' ) : __( self::OUTCOMES[ $r->outcome ] ?? 'Waiting', 'songpress' ) ) . '</span></summary>'; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
			$o   .= Songpress_Money::form_open( 'songpress_pitch_update', 'spp-edit' ) . '<input type="hidden" name="pitch_id" value="' . (int) $r->id . '"><input type="hidden" name="sp_return" value="' . esc_attr( self::url( array( 'ps' => 'log', 'pf' => $flt ) ) ) . '">';
			$o   .= '<p class="spe-help">' . esc_html( sprintf( /* translators: %s: date */ __( 'Sent %s', 'songpress' ), $r->sent_at ) ) . '</p>';
			$o   .= '<label class="spe-field"><span>' . esc_html__( 'How it went', 'songpress' ) . '</span><select name="outcome">';
			foreach ( self::OUTCOMES as $k => $l ) {
				$o .= '<option value="' . esc_attr( $k ) . '"' . selected( $r->outcome, $k, false ) . '>' . esc_html( $l ) . '</option>'; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
			}
			$o .= '</select></label><label class="spe-field"><span>' . esc_html__( 'Follow up on', 'songpress' ) . '</span><input type="date" name="follow_up" value="' . esc_attr( $r->follow_up ) . '"></label><label class="spe-field"><span>' . esc_html__( 'Notes', 'songpress' ) . '</span><textarea name="notes" rows="2">' . esc_textarea( (string) $r->notes ) . '</textarea></label>';
			$o .= '<button class="spe-save" type="submit">' . esc_html__( 'Save', 'songpress' ) . '</button> <a class="spe-btn" href="' . esc_url( self::url( array( 'ps' => 'kit', 'song' => (int) $r->song_id ) ) ) . '">' . esc_html__( 'Open its pitch kit', 'songpress' ) . '</a></form>';
			$o .= Songpress_Money::form_open( 'songpress_pitch_delete', 'spp-del' ) . '<input type="hidden" name="pitch_id" value="' . (int) $r->id . '"><input type="hidden" name="sp_return" value="' . esc_attr( self::url( array( 'ps' => 'log', 'pf' => $flt ) ) ) . '"><button class="spe-btn" type="submit" onclick="return confirm(\'' . esc_js( __( 'Remove this pitch from the log?', 'songpress' ) ) . '\')">' . esc_html__( 'Remove', 'songpress' ) . '</button></form></details>';
		}
		return $o;
	}

	private static function render_where() {
		$o = '<p class="spe-help">' . esc_html__( 'Free doors first, paid ones last. Each link opens the site; nothing is sent from here. Never pay anyone who sells streams or plays.', 'songpress' ) . '</p>';
		foreach ( self::channels() as $g ) {
			$o .= '<h3>' . esc_html( $g['label'] ) . '</h3><ul class="spp-chan">';
			foreach ( $g['items'] as $i ) {
				$o .= '<li><div><b>' . esc_html( $i['name'] ) . '</b> <span class="spp-cost">' . esc_html( $i['cost'] ) . '</span><br><span class="spe-help">' . esc_html( $i['blurb'] ) . '</span></div><a class="spe-btn" href="' . esc_url( $i['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $i['cta'] ) . '</a></li>';
			}
			$o .= '</ul>';
		}
		return $o;
	}

	private static function copy_box( $label, $text, $id ) {
		return '<div class="spp-copy"><h4>' . esc_html( $label ) . ' <button type="button" class="spe-btn" data-copy="' . esc_attr( $id ) . '">' . esc_html__( 'Copy', 'songpress' ) . '</button></h4><textarea id="' . esc_attr( $id ) . '" rows="' . (int) min( 14, max( 4, substr_count( $text, "\n" ) + 2 ) ) . '" readonly>' . esc_textarea( $text ) . '</textarea></div>';
	}

	private static function render_kit() {
		$song = self::work_from_get();
		$o    = '<form method="get" class="spp-pick"><input type="hidden" name="sp_page" value="pitch"><input type="hidden" name="ps" value="kit"><label class="spe-field"><span>' . esc_html__( 'Work (serial)', 'songpress' ) . '</span><input name="serial" size="10" placeholder="0378" value="' . esc_attr( $song ? (string) $song->serial : '' ) . '"></label> <button class="spe-save" type="submit">' . esc_html__( 'Build the kit', 'songpress' ) . '</button></form>';
		if ( ! $song ) {
			return $o . '<p class="spe-help">' . esc_html__( 'Type a work\'s serial to get its checklist, ready-to-send copy and a one-sheet. You can also open this from a work\'s editor.', 'songpress' ) . '</p>';
		}
		$f  = self::facts( $song );
		$o .= '<h3>' . esc_html( $f['serial'] . ' ' . $f['title'] ) . '</h3><ul class="spp-ready">';
		foreach ( self::readiness( $f ) as $r ) {
			$o .= '<li class="' . ( $r[0] ? 'ok' : 'no' ) . '">' . ( $r[0] ? '✓ ' : '○ ' ) . esc_html( $r[1] ) . '</li>';
		}
		$o .= '</ul><p><a class="spe-btn" href="' . esc_url( Songpress_Editor::edit_url( (int) $song->id ) ) . '">' . esc_html__( 'Fill in what is missing', 'songpress' ) . '</a></p>';
		$o .= self::copy_box( __( 'Radio email', 'songpress' ), self::radio_copy( $f ), 'spp-radio' ) . self::copy_box( __( 'Spotify for Artists pitch (500 characters)', 'songpress' ), self::spotify_copy( $f ), 'spp-spot' ) . self::copy_box( __( 'Short email to a curator or contact', 'songpress' ), self::email_copy( $f ), 'spp-mail' ) . self::copy_box( __( 'One-sheet', 'songpress' ), self::onesheet( $f ), 'spp-one' );
		$o .= '<h3>' . esc_html__( 'Log it once you have sent it', 'songpress' ) . '</h3>' . self::log_form( $song );
		$o .= '<script>document.querySelectorAll("[data-copy]").forEach(function(b){b.onclick=function(){var t=document.getElementById(b.getAttribute("data-copy"));t.select();var ok=false;try{ok=navigator.clipboard&&navigator.clipboard.writeText?(navigator.clipboard.writeText(t.value),true):document.execCommand("copy")}catch(e){try{ok=document.execCommand("copy")}catch(x){}}b.textContent=ok?"Copied":"Press Ctrl+C";setTimeout(function(){b.textContent="Copy"},1800)}});</script>';
		return $o;
	}
}
