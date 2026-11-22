<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pitch: getting a song heard.
 *
 * "Pitch a song" opens a step-by-step popup: pick the work, gather the files and bio, check the publisher and rights,
 * then choose where to send it and get the exact words to paste. The page behind it tracks every pitch: who got it,
 * when to follow up, and how it went. Nothing is sent from here and nobody is emailed; the plugin writes the words,
 * you send them.
 */
class Songpress_Pitch {

	const OUTCOMES = array(
		'open'     => 'Waiting',
		'replied'  => 'Replied',
		'placed'   => 'Placed',
		'passed'   => 'Passed',
		'no_reply' => 'No reply',
	);

	/** Kit file fields: key => [ column, kind of file, label ]. */
	const FILES = array(
		'wav'     => array( 'wav_url', 'audio', 'Master WAV' ),
		'clean'   => array( 'clean_url', 'audio', 'Clean version' ),
		'instr'   => array( 'instr_url', 'audio', 'Instrumental' ),
		'stems'   => array( 'stems_url', 'zip', 'Stems (zip)' ),
		'photo'   => array( 'photo_url', 'image', 'Press photo' ),
		'bio_url' => array( 'bio_url', 'doc', 'Bio file' ),
	);

	public static function init() {
		add_action( 'admin_post_songpress_pitch_update', array( __CLASS__, 'handle_update' ) );
		add_action( 'admin_post_songpress_pitch_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'wp_ajax_songpress_pitch_ajax', array( __CLASS__, 'handle_ajax' ) );
		add_action( 'wp_ajax_songpress_pitch_media', array( __CLASS__, 'handle_media' ) );
		add_action( 'wp_ajax_songpress_pitch_upload', array( __CLASS__, 'handle_upload' ) );
	}

	private static function t() {
		return Songpress_Install::table( 'pitches' );
	}

	private static function kt() {
		return Songpress_Install::table( 'pitch_kits' );
	}

	/** Groups of channels: label, tip and items (id, name, cost, blurb, url, cta). */
	public static function channels() {
		static $c = null;
		if ( null === $c ) {
			$c = require SONGPRESS_DIR . 'includes/data-pitch-channels.php';
		}
		return $c;
	}

	/** Channel id => name, for the log. */
	public static function channel_names() {
		$o = array( 'other' => __( 'Somewhere else / a person', 'songpress' ) );
		foreach ( self::channels() as $g ) {
			foreach ( $g['items'] as $i ) {
				$o[ $i['id'] ] = $i['name'];
			}
		}
		return $o;
	}

	/** Price tier of each channel: free, mixed (free to start, paid extras) or paid. Anything not listed is free. */
	const TIERS = array(
		'groover'       => 'paid',
		'plaympe'       => 'paid',
		'submithub'     => 'mixed',
		'submithub-radio' => 'mixed',
		'radioairplay'  => 'mixed',
		'playlistpal'   => 'mixed',
		'musosoup'      => 'mixed',
		'airplaydirect' => 'mixed',
	);

	/** Which ready-made words a channel gets: radio, onesheet or email. */
	public static function copy_key( $channel, $group ) {
		if ( in_array( $group, array( 'terrestrial', 'radio' ), true ) ) {
			return 'radio';
		}
		return 'sync' === $group ? 'onesheet' : 'email';
	}

	/** The channels the popup shows: groups with each item already tagged with its copy key. */
	public static function channel_groups() {
		$out = array();
		foreach ( self::channels() as $gid => $g ) {
			$items = array();
			foreach ( $g['items'] as $i ) {
				$i['copy'] = self::copy_key( $i['id'], $gid );
				$i['tier'] = self::TIERS[ $i['id'] ] ?? 'free';
				$items[]   = $i;
			}
			$rank = array( 'free' => 0, 'mixed' => 1, 'paid' => 2 );
			usort( $items, function ( $a, $b ) use ( $rank ) {
				return $rank[ $a['tier'] ] <=> $rank[ $b['tier'] ];
			} );
			$out[] = array( 'id' => $gid, 'label' => $g['label'], 'tip' => $g['tip'] ?? '', 'items' => $items );
		}
		$out[] = array( 'id' => 'other', 'label' => __( 'Somewhere else', 'songpress' ), 'tip' => __( 'A blogger, a DJ, a friend in the business, a venue. Use the short email and note who you sent it to.', 'songpress' ), 'items' => array( array( 'id' => 'other', 'name' => __( 'A person or site not listed', 'songpress' ), 'cost' => '', 'tier' => 'free', 'blurb' => __( 'Anyone you email or message yourself.', 'songpress' ), 'url' => '', 'cta' => '', 'copy' => 'email' ) ) );
		return $out;
	}

	/* ---------- the kit (files, bio, contact) ---------- */

	public static function kit( $song_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::kt() . ' WHERE song_id = %d', (int) $song_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$k   = array( 'bio' => '', 'mood' => '', 'contact' => '', 'explicit' => 0 );
		foreach ( self::FILES as $key => $def ) {
			$k[ $key ] = '';
		}
		if ( is_array( $row ) ) {
			foreach ( self::FILES as $key => $def ) {
				$k[ $key ] = (string) ( $row[ $def[0] ] ?? '' );
			}
			$k['bio']      = (string) ( $row['bio'] ?? '' );
			$k['mood']     = (string) ( $row['mood'] ?? '' );
			$k['contact']  = (string) ( $row['contact'] ?? '' );
			$k['explicit'] = (int) ( $row['explicit'] ?? 0 );
		}
		return $k;
	}

	/** Save only the fields that were sent, so a step that shows some fields does not blank the others. */
	public static function save_kit( $song_id, array $in ) {
		global $wpdb;
		$song_id = (int) $song_id;
		if ( ! $song_id ) {
			return false;
		}
		$cur = self::kit( $song_id );
		foreach ( self::FILES as $key => $def ) {
			if ( isset( $in[ $key ] ) ) {
				$cur[ $key ] = esc_url_raw( trim( (string) $in[ $key ] ) );
			}
		}
		if ( isset( $in['bio'] ) ) {
			$cur['bio'] = sanitize_textarea_field( $in['bio'] );
		}
		if ( isset( $in['mood'] ) ) {
			$cur['mood'] = mb_substr( sanitize_text_field( $in['mood'] ), 0, 191 );
		}
		if ( isset( $in['contact'] ) ) {
			$cur['contact'] = mb_substr( sanitize_text_field( $in['contact'] ), 0, 191 );
		}
		if ( isset( $in['explicit_set'] ) ) {
			$cur['explicit'] = ! empty( $in['explicit'] ) && '0' !== (string) $in['explicit'] ? 1 : 0;
		}
		$row = array( 'bio' => $cur['bio'], 'mood' => $cur['mood'], 'contact' => $cur['contact'], 'explicit' => (int) $cur['explicit'], 'updated_at' => current_time( 'mysql' ) );
		foreach ( self::FILES as $key => $def ) {
			$row[ $def[0] ] = $cur[ $key ];
		}
		$have = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::kt() . ' WHERE song_id = %d', $song_id ) ); // phpcs:ignore WordPress.DB
		if ( $have ) {
			$wpdb->update( self::kt(), $row, array( 'song_id' => $song_id ) );
		} else {
			$wpdb->insert( self::kt(), array_merge( array( 'song_id' => $song_id ), $row ) );
		}
		return true;
	}

	/** The most recent bio saved for any work by this band, so a band writes it once. */
	public static function band_bio( $band ) {
		global $wpdb;
		if ( '' === trim( (string) $band ) ) {
			return '';
		}
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT k.bio FROM ' . self::kt() . ' k JOIN ' . Songpress_Install::table( 'songs' ) . " s ON s.id = k.song_id WHERE s.artist = %s AND k.bio <> '' ORDER BY k.updated_at DESC LIMIT 1", $band ) ); // phpcs:ignore WordPress.DB
	}

	/** The most recent bio typed for any work, to offer as a starting point. */
	public static function last_bio() {
		global $wpdb;
		return (string) $wpdb->get_var( 'SELECT bio FROM ' . self::kt() . " WHERE bio <> '' ORDER BY updated_at DESC LIMIT 1" ); // phpcs:ignore WordPress.DB
	}

	/* ---------- the facts and the words (pure) ---------- */

	/** What a pitch needs to know about a work, in one array. Read from the database. */
	public static function facts( $song ) {
		$w   = array();
		$pub = array();
		foreach ( Songpress_Songs::credits( (int) $song->id ) as $c ) {
			if ( '' === (string) $c->name ) {
				continue;
			}
			$share = null === $c->share ? '' : rtrim( rtrim( number_format( (float) $c->share, 2, '.', '' ), '0' ), '.' );
			if ( 'writer' === $c->role ) {
				$w[] = array( 'name' => (string) $c->name, 'share' => $share );
			} elseif ( 'publisher' === $c->role ) {
				$pub[] = array( 'name' => (string) $c->name, 'share' => $share, 'ipi' => (string) ( $c->ipi ?? '' ) );
			}
		}
		$ids = array();
		foreach ( Songpress_Songs::identifiers( (int) $song->id ) as $i ) {
			if ( ! isset( $ids[ $i->kind ] ) ) {
				$ids[ $i->kind ] = (string) $i->value;
			}
		}
		$k = self::kit( (int) $song->id );
		return array(
			'title'      => (string) $song->title,
			'serial'     => (string) $song->serial,
			'band'       => (string) $song->artist,
			'writers'    => $w,
			'publishers' => $pub,
			'genre'      => (string) $song->genre,
			'key'        => (string) $song->musical_key,
			'tempo'      => (string) $song->tempo,
			'isrc'       => $ids['isrc'] ?? '',
			'iswc'       => $ids['iswc'] ?? '',
			'pro'        => trim( ( isset( $ids['ascap'] ) ? 'ASCAP ' . $ids['ascap'] : '' ) . ' ' . ( isset( $ids['bmi'] ) ? 'BMI ' . $ids['bmi'] : '' ) ),
			'audio'      => Songpress_Media::audio_url( $song ),
			'art'        => Songpress_Media::art_url( $song, 'full' ),
			'about'      => (string) $song->story,
			'status'     => (string) $song->status,
			'release'    => (string) $song->release_date,
			'wav'        => $k['wav'],
			'clean'      => $k['clean'],
			'instr'      => $k['instr'],
			'stems'      => $k['stems'],
			'photo'      => $k['photo'],
			'bio_url'    => $k['bio_url'],
			'bio'        => $k['bio'],
			'mood'       => $k['mood'],
			'contact'    => $k['contact'],
			'explicit'   => (int) $k['explicit'],
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

	private static function pubs( array $f ) {
		$o = array();
		foreach ( $f['publishers'] as $p ) {
			$o[] = $p['name'] . ( '' !== $p['share'] ? ' (' . $p['share'] . '%)' : '' );
		}
		return implode( '; ', $o );
	}

	/** A starting bio from what the catalog already knows. The person edits it. */
	public static function draft_bio( array $f ) {
		$who = self::who( $f );
		if ( '' === $who ) {
			return '';
		}
		$g = '' !== $f['genre'] ? $f['genre'] : 'original music';
		$o = $who . ' makes ' . $g . ( '' !== $f['mood'] ? ' with a ' . $f['mood'] . ' feel' : '' ) . '.';
		if ( '' !== self::story( $f ) ) {
			$s  = self::story( $f );
			$o .= ' Latest song, "' . $f['title'] . '": ' . ( mb_strlen( $s ) > 200 ? mb_substr( $s, 0, 197 ) . '…' : $s );
		}
		return $o;
	}

	/**
	 * What is missing before this work is ready to pitch. Each row: [ ok, label, where, why ].
	 * where: files, story or rights, the wizard step that shows it. why says in plain words why it matters.
	 */
	public static function readiness( array $f ) {
		$sh = 0.0;
		foreach ( $f['writers'] as $w ) {
			$sh += (float) $w['share'];
		}
		$r = array(
			array( '' !== $f['wav'], __( 'Master WAV file', 'songpress' ), 'files', __( 'Radio stations and sync libraries ask for a WAV, not an MP3 or a streaming link.', 'songpress' ) ),
			array( '' !== $f['audio'] || '' !== $f['wav'], __( 'A way to listen (stream link)', 'songpress' ), 'files', __( 'People decide in seconds. Give them something they can press play on.', 'songpress' ) ),
			array( '' !== $f['art'], __( 'Cover art', 'songpress' ), 'files', __( 'Playlists and blogs need a picture. Add it in the work editor.', 'songpress' ) ),
			array( '' !== trim( $f['bio'] ), __( 'Artist bio', 'songpress' ), 'story', __( 'Two or three sentences on who you are. Curators read this to decide if you fit.', 'songpress' ) ),
			array( '' !== trim( (string) $f['about'] ), __( 'The story of the song', 'songpress' ), 'story', __( 'The most persuasive line in any pitch. Add it in the work editor under About.', 'songpress' ) ),
			array( '' !== $f['genre'], __( 'Genre', 'songpress' ), 'story', __( 'Curators filter by genre first. Set it in the work editor.', 'songpress' ) ),
			array( (bool) $f['writers'], __( 'Writers listed', 'songpress' ), 'rights', __( 'Anyone who licenses or plays the song needs to know who wrote it.', 'songpress' ) ),
			array( $sh >= 95 && $sh <= 105, __( 'Writer shares add up to 100%', 'songpress' ), 'rights', __( 'Splits that do not add up stop a sync deal. Fix them in the work editor.', 'songpress' ) ),
			array( (bool) $f['publishers'], __( 'Publisher listed', 'songpress' ), 'rights', __( 'Sync and radio contacts ask who publishes the song. If you self-publish, list your own publishing name.', 'songpress' ) ),
			array( '' !== $f['isrc'], __( 'ISRC (the recording\'s ID)', 'songpress' ), 'rights', __( 'Your distributor assigns it when the song is released. Needed to collect radio and streaming money.', 'songpress' ) ),
			array( '' !== $f['iswc'], __( 'ISWC (the song\'s ID)', 'songpress' ), 'rights', __( 'Your performing rights organization (ASCAP, BMI) assigns it once the song is registered.', 'songpress' ) ),
		);
		if ( $f['explicit'] ) {
			$r[] = array( '' !== $f['clean'], __( 'Clean version', 'songpress' ), 'files', __( 'Songs with cursing cannot air in the daytime, so stations want a clean edit.', 'songpress' ) );
		}
		return $r;
	}

	private static function listen_lines( array $f ) {
		$o = array();
		if ( '' !== $f['audio'] ) {
			$o[] = 'Listen: ' . $f['audio'];
		}
		if ( '' !== $f['wav'] ) {
			$o[] = 'Download (WAV): ' . $f['wav'];
		}
		return $o;
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
			$f['explicit'] ? 'Explicit lyrics: yes' . ( '' !== $f['clean'] ? ' (clean version: ' . $f['clean'] . ')' : '' ) : 'Explicit lyrics: no',
		) );
		$lines[] = implode( "\n", $meta );
		if ( $f['writers'] && '' !== $f['band'] ) {
			$lines[] = 'Writers: ' . self::names( $f ) . '.';
		}
		if ( '' !== self::story( $f ) ) {
			$lines[] = self::story( $f );
		}
		if ( '' !== trim( $f['bio'] ) ) {
			$lines[] = trim( $f['bio'] );
		}
		$lines[] = '';
		$lines   = array_merge( $lines, self::listen_lines( $f ) );
		$lines[] = '';
		$lines[] = 'Happy to send a different edit if you need one.';
		$lines[] = 'Thank you for the time.';
		if ( '' !== $f['contact'] ) {
			$lines[] = '';
			$lines[] = $f['contact'];
		}
		return implode( "\n", $lines );
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
		if ( '' !== trim( $f['bio'] ) ) {
			$lines[] = trim( $f['bio'] );
		}
		$lines   = array_merge( $lines, self::listen_lines( $f ) );
		$lines[] = '';
		$lines[] = 'Thanks for a listen either way — no worries if it is not a fit.';
		if ( '' !== $f['contact'] ) {
			$lines[] = $f['contact'];
		}
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
			'' !== $f['mood'] ? 'Mood: ' . $f['mood'] : '',
			'' !== $f['key'] ? 'Key: ' . $f['key'] : '',
			'' !== $f['tempo'] ? 'Tempo: ' . $f['tempo'] . ' BPM' : '',
			'Explicit lyrics: ' . ( $f['explicit'] ? 'yes' : 'no' ),
			'' !== $f['isrc'] ? 'ISRC: ' . $f['isrc'] : '',
			'' !== $f['iswc'] ? 'ISWC: ' . $f['iswc'] : '',
			'' !== $f['pro'] ? 'PRO: ' . $f['pro'] : '',
			$w ? 'Writers: ' . implode( '; ', $w ) : '',
			$f['publishers'] ? 'Publisher: ' . self::pubs( $f ) : '',
			'' !== $f['audio'] ? 'Stream: ' . $f['audio'] : '',
			'' !== $f['wav'] ? 'Master WAV: ' . $f['wav'] : '',
			'' !== $f['clean'] ? 'Clean version: ' . $f['clean'] : '',
			'' !== $f['instr'] ? 'Instrumental: ' . $f['instr'] : '',
			'' !== $f['stems'] ? 'Stems: ' . $f['stems'] : '',
			'' !== $f['art'] ? 'Artwork: ' . $f['art'] : '',
			'' !== $f['photo'] ? 'Press photo: ' . $f['photo'] : '',
			'' !== $f['contact'] ? 'Contact: ' . $f['contact'] : '',
		), function ( $l ) {
			return '' !== $l;
		} );
		$lines[] = '';
		$lines[] = 'RIGHTS';
		$lines[] = 'Writer and publisher splits as listed above, agreed in writing.';
		$lines[] = 'No uncleared samples.';
		if ( '' !== trim( $f['bio'] ) ) {
			$lines[] = '';
			$lines[] = 'ARTIST BIO';
			$lines[] = trim( $f['bio'] );
		}
		if ( '' !== trim( (string) $f['about'] ) ) {
			$lines[] = '';
			$lines[] = 'ABOUT THE SONG';
			$lines[] = self::story( $f );
		}
		return implode( "\n", $lines );
	}

	/** Every ready-made text for one work. */
	public static function copies( array $f ) {
		return array( 'radio' => self::radio_copy( $f ), 'onesheet' => self::onesheet( $f ), 'email' => self::email_copy( $f ) );
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
		if ( isset( $in['sent_to'] ) ) {
			$row['sent_to'] = mb_substr( sanitize_text_field( $in['sent_to'] ), 0, 191 );
		}
		if ( isset( $in['channel'] ) && isset( self::channel_names()[ sanitize_key( $in['channel'] ) ] ) ) {
			$row['channel'] = sanitize_key( $in['channel'] );
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

	/** Counts by outcome, plus how many follow-ups are due. */
	public static function counts( $today = '' ) {
		$c = array_fill_keys( array_keys( self::OUTCOMES ), 0 );
		foreach ( self::rows( 'all' ) as $r ) {
			$k = isset( $c[ $r->outcome ] ) ? $r->outcome : 'open';
			++$c[ $k ];
		}
		$c['due'] = count( self::rows( 'due', 0, $today ) );
		return $c;
	}

	/* ---------- the popup's calls ---------- */

	private static function load_song( array $in ) {
		$sid = absint( $in['song_id'] ?? 0 );
		return $sid ? Songpress_Songs::get( $sid ) : null;
	}

	/** Everything the popup shows for one work. */
	private static function song_reply( $song ) {
		$f = self::facts( $song );
		return array(
			'ok'      => true,
			'song_id' => (int) $song->id,
			'facts'   => $f,
			'ready'   => array_map( function ( $r ) {
				return array( 'ok' => (bool) $r[0], 'label' => $r[1], 'where' => $r[2], 'why' => $r[3] );
			}, self::readiness( $f ) ),
			'copies'  => self::copies( $f ),
			'draft'   => '' === trim( $f['bio'] ) ? ( '' !== self::band_bio( $f['band'] ) ? self::band_bio( $f['band'] ) : ( '' !== self::last_bio() ? self::last_bio() : self::draft_bio( $f ) ) ) : '',
			'edit'    => Songpress_Editor::edit_url( (int) $song->id ),
		);
	}

	/** One call from the popup. op: song, kit or log. */
	public static function ajax_op( array $in ) {
		$op   = (string) ( $in['op'] ?? '' );
		$song = self::load_song( $in );
		if ( ! $song ) {
			return array( 'ok' => false, 'error' => 'Pick a work first.' );
		}
		if ( 'kit' === $op ) {
			self::save_kit( (int) $song->id, $in );
			return self::song_reply( $song );
		}
		if ( 'log' === $op ) {
			$id = self::add( $in );
			if ( $id && class_exists( 'Songpress_Audit' ) ) {
				Songpress_Audit::log( 'pitch', 'Logged a pitch', (string) $song->serial, array( 'song_id' => (int) $song->id, 'channel' => sanitize_key( $in['channel'] ?? '' ) ) );
			}
			return $id ? array( 'ok' => true, 'id' => $id ) : array( 'ok' => false, 'error' => 'Say where it went (a name or a site).' );
		}
		return self::song_reply( $song );
	}

	public static function handle_ajax() {
		check_ajax_referer( 'songpress_pitch_ajax' );
		if ( ! Songpress_Caps::can( 'pitch' ) ) {
			wp_send_json( array( 'ok' => false, 'error' => 'Not allowed.' ), 403 );
		}
		wp_send_json( self::ajax_op( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- cleaned in save_kit() and add().
	}

	private static function mime_filter( $kind ) {
		$m = array(
			'audio' => 'audio',
			'image' => 'image',
			'zip'   => array( 'application/zip', 'application/x-zip-compressed' ),
			'doc'   => array( 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain' ),
		);
		return $m[ $kind ] ?? '';
	}

	/** Ajax: search the Media Library for a file of the right kind. */
	public static function handle_media() {
		check_ajax_referer( 'songpress_pitch_ajax' );
		if ( ! Songpress_Caps::can( 'pitch' ) ) {
			wp_send_json( array( 'ok' => false, 'error' => 'Not allowed.' ), 403 );
		}
		$kind = sanitize_key( wp_unslash( $_POST['kind'] ?? '' ) );
		$q    = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => self::mime_filter( $kind ),
			's'              => sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) ),
			'posts_per_page' => 12,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );
		$out = array();
		foreach ( $q->posts as $p ) {
			$out[] = array( 'id' => (int) $p->ID, 'title' => get_the_title( $p ), 'url' => (string) wp_get_attachment_url( $p->ID ), 'type' => (string) $p->post_mime_type );
		}
		wp_send_json( array( 'ok' => true, 'items' => $out ) );
	}

	/** Ajax: upload a file straight into the Media Library and send back its address. */
	public static function handle_upload() {
		check_ajax_referer( 'songpress_pitch_ajax' );
		if ( ! Songpress_Caps::can( 'pitch' ) || ! Songpress_Caps::can( 'upload' ) ) {
			wp_send_json( array( 'ok' => false, 'error' => 'You are not allowed to upload files.' ), 403 );
		}
		if ( empty( $_FILES['file'] ) ) {
			wp_send_json( array( 'ok' => false, 'error' => 'No file arrived.' ), 400 );
		}
		$kind  = sanitize_key( wp_unslash( $_POST['kind'] ?? '' ) );
		$check = wp_check_filetype_and_ext( $_FILES['file']['tmp_name'], $_FILES['file']['name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$type  = (string) ( $check['type'] ?: '' );
		$want  = self::mime_filter( $kind );
		$okay  = is_array( $want ) ? in_array( $type, $want, true ) : ( '' !== $want && 0 === strpos( $type, $want . '/' ) );
		if ( ! $okay ) {
			wp_send_json( array( 'ok' => false, 'error' => 'That kind of file does not fit here. Expected: ' . ( 'audio' === $kind ? 'a WAV or other audio file' : ( 'image' === $kind ? 'a picture' : ( 'zip' === $kind ? 'a .zip file' : 'a PDF, Word or text file' ) ) ) . '.' ), 415 );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$id = media_handle_upload( 'file', 0 );
		if ( is_wp_error( $id ) ) {
			wp_send_json( array( 'ok' => false, 'error' => $id->get_error_message() ), 400 );
		}
		wp_send_json( array( 'ok' => true, 'id' => (int) $id, 'url' => (string) wp_get_attachment_url( $id ), 'name' => wp_basename( (string) wp_get_attachment_url( $id ) ) ) );
	}

	/* ---------- tracker handlers ---------- */

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

	public static function handle_update() {
		self::guard( 'songpress_pitch_update' );
		self::update( absint( $_POST['pitch_id'] ?? 0 ), wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- cleaned in update().
		self::go( array( 'p_ok' => rawurlencode( __( 'Saved.', 'songpress' ) ) ) );
	}

	public static function handle_delete() {
		self::guard( 'songpress_pitch_delete' );
		self::delete( absint( $_POST['pitch_id'] ?? 0 ) );
		self::go( array( 'p_ok' => rawurlencode( __( 'Removed.', 'songpress' ) ) ) );
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
		$c   = self::counts();
		$o   = '<div class="songpress sp-app sp-editor sp-pitch"><div class="spe-top"><h2>' . esc_html__( 'Pitch', 'songpress' ) . '</h2><button type="button" class="spe-save spp-go" id="spp-open">' . esc_html__( 'Pitch a song', 'songpress' ) . '</button></div>';
		$o  .= '<p class="spe-help">' . esc_html__( 'Pitching means sending a song to someone who can help it be heard: a radio station, a playlist curator, a music supervisor. Press Pitch a song and we will walk you through it. This page keeps track of who you sent what, and reminds you when to follow up.', 'songpress' ) . '</p>';
		if ( ! empty( $_GET['p_ok'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<p class="spe-note ok">' . esc_html( sanitize_text_field( wp_unslash( $_GET['p_ok'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		$o .= '<div class="spp-chips">';
		foreach ( self::OUTCOMES as $k => $l ) {
			$o .= '<span class="spp-stat"><b>' . (int) $c[ $k ] . '</b> ' . esc_html( $l ) . '</span>';
		}
		$o  .= '</div>';
		$due = self::rows( 'due' );
		if ( $due ) {
			$o    .= '<div class="spp-attn"><h3>' . esc_html( sprintf( /* translators: %d: count */ _n( '%d follow-up is due', '%d follow-ups are due', count( $due ), 'songpress' ), count( $due ) ) ) . '</h3><p class="spe-help">' . esc_html__( 'A short, friendly nudge is fine. Then set a new date or mark how it went.', 'songpress' ) . '</p><ul>';
			$names = self::channel_names();
			foreach ( $due as $r ) {
				$o .= '<li><b>' . esc_html( (string) $r->serial ) . '</b> ' . esc_html( (string) $r->title ) . ' → ' . esc_html( ( $names[ $r->channel ] ?? '' ) . ( '' !== $r->sent_to ? ' · ' . $r->sent_to : '' ) ) . ' <span class="spe-help">(' . esc_html( sprintf( /* translators: %s: date */ __( 'follow up %s', 'songpress' ), $r->follow_up ) ) . ')</span></li>';
			}
			$o .= '</ul></div>';
		}
		return $o . self::render_log() . self::wizard() . '</div>';
	}

	private static function render_log() {
		$flt = isset( $_GET['pf'] ) ? sanitize_key( wp_unslash( $_GET['pf'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification
		$flt = in_array( $flt, array( 'open', 'due', 'all' ), true ) ? $flt : 'all';
		$o   = '<h3>' . esc_html__( 'Your pitches', 'songpress' ) . '</h3><p>';
		foreach ( array( 'all' => __( 'Everything', 'songpress' ), 'open' => __( 'Waiting to hear back', 'songpress' ), 'due' => __( 'Follow-up due', 'songpress' ) ) as $k => $l ) {
			$o .= '<a class="spe-btn' . ( $k === $flt ? ' on' : '' ) . '" href="' . esc_url( self::url( array( 'pf' => $k ) ) ) . '">' . esc_html( $l ) . '</a> ';
		}
		$o   .= '</p>';
		$rows = self::rows( $flt );
		if ( ! $rows ) {
			return $o . '<p class="spe-help">' . esc_html( 'due' === $flt ? __( 'Nothing is due. Nice.', 'songpress' ) : __( 'No pitches yet. Press Pitch a song above. Every pitch you send shows up here so you never lose track.', 'songpress' ) ) . '</p>';
		}
		$names = self::channel_names();
		$today = gmdate( 'Y-m-d' );
		foreach ( $rows as $r ) {
			$late = 'open' === $r->outcome && '' !== $r->follow_up && $r->follow_up <= $today;
			$o   .= '<details class="spp-row"><summary><span><b>' . esc_html( (string) $r->serial ) . '</b> ' . esc_html( (string) $r->title ) . '</span><span class="spp-meta">' . esc_html( ( $names[ $r->channel ] ?? '' ) . ( '' !== $r->sent_to ? ( isset( $names[ $r->channel ] ) ? ' · ' : '' ) . $r->sent_to : '' ) ) . '</span><span class="spp-chip ' . esc_attr( $late ? 'late' : $r->outcome ) . '">' . esc_html( $late ? __( 'Follow up', 'songpress' ) : ( self::OUTCOMES[ $r->outcome ] ?? 'Waiting' ) ) . '</span></summary>';
			$o   .= Songpress_Money::form_open( 'songpress_pitch_update', 'spp-edit' ) . '<input type="hidden" name="pitch_id" value="' . (int) $r->id . '"><input type="hidden" name="sp_return" value="' . esc_attr( self::url( array( 'pf' => $flt ) ) ) . '">';
			$o   .= '<p class="spe-help">' . esc_html( sprintf( /* translators: %s: date */ __( 'Sent %s', 'songpress' ), $r->sent_at ) ) . '</p>';
			$o   .= '<label class="spe-field"><span>' . esc_html__( 'Where', 'songpress' ) . '</span><select name="channel">';
			foreach ( $names as $cid => $cn ) {
				$o .= '<option value="' . esc_attr( $cid ) . '"' . selected( $r->channel, $cid, false ) . '>' . esc_html( $cn ) . '</option>';
			}
			$o .= '</select></label><label class="spe-field"><span>' . esc_html__( 'Sent to', 'songpress' ) . '</span><input name="sent_to" value="' . esc_attr( (string) $r->sent_to ) . '"></label>';
			$o .= '<label class="spe-field"><span>' . esc_html__( 'How it went', 'songpress' ) . '</span><select name="outcome">';
			foreach ( self::OUTCOMES as $k => $l ) {
				$o .= '<option value="' . esc_attr( $k ) . '"' . selected( $r->outcome, $k, false ) . '>' . esc_html( $l ) . '</option>';
			}
			$o .= '</select></label><label class="spe-field"><span>' . esc_html__( 'Follow up on', 'songpress' ) . '</span><input type="date" name="follow_up" value="' . esc_attr( $r->follow_up ) . '"></label><label class="spe-field"><span>' . esc_html__( 'Notes', 'songpress' ) . '</span><textarea name="notes" rows="2">' . esc_textarea( (string) $r->notes ) . '</textarea></label>';
			$o .= '<button class="spe-save" type="submit">' . esc_html__( 'Save', 'songpress' ) . '</button></form>';
			$o .= Songpress_Money::form_open( 'songpress_pitch_delete', 'spp-del' ) . '<input type="hidden" name="pitch_id" value="' . (int) $r->id . '"><input type="hidden" name="sp_return" value="' . esc_attr( self::url( array( 'pf' => $flt ) ) ) . '"><button class="spe-btn" type="submit" onclick="return confirm(\'' . esc_js( __( 'Remove this pitch from the log?', 'songpress' ) ) . '\')">' . esc_html__( 'Remove', 'songpress' ) . '</button></form></details>';
		}
		return $o;
	}

	/** The step-by-step popup and its script. */
	private static function wizard() {
		global $wpdb;
		$rows  = $wpdb->get_results( 'SELECT id, serial, title, artist FROM ' . Songpress_Install::table( 'songs' ) . ' ORDER BY serial DESC LIMIT 3000' ); // phpcs:ignore WordPress.DB
		$songs = array();
		foreach ( (array) $rows as $r ) {
			$songs[] = array( 'id' => (int) $r->id, 'serial' => (string) $r->serial, 'title' => (string) $r->title, 'band' => (string) $r->artist );
		}
		$start = isset( $_GET['song'] ) ? absint( $_GET['song'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$files = array();
		foreach ( self::FILES as $k => $d ) {
			$files[ $k ] = array( 'kind' => $d[1], 'label' => $d[2] );
		}
		$data = array(
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'songpress_pitch_ajax' ),
			'songs'    => $songs,
			'groups'   => self::channel_groups(),
			'files'    => $files,
			'today'    => gmdate( 'Y-m-d' ),
			'canup'    => Songpress_Caps::can( 'upload' ),
			'start'    => $start,
			'autoopen' => $start && isset( $_GET['wizard'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification
		);
		$o  = '<div class="spp-modal" id="spp-modal" role="dialog" aria-modal="true" aria-labelledby="spp-title" hidden><div class="spp-card">';
		$o .= '<div class="spp-head"><h3 id="spp-title">' . esc_html__( 'Pitch a song', 'songpress' ) . '</h3><button type="button" class="spe-btn" id="spp-x" aria-label="' . esc_attr__( 'Close', 'songpress' ) . '">&times;</button></div>';
		$o .= '<ol class="spp-steps"><li>' . esc_html__( 'Song', 'songpress' ) . '</li><li>' . esc_html__( 'Files & story', 'songpress' ) . '</li><li>' . esc_html__( 'Publisher & rights', 'songpress' ) . '</li><li>' . esc_html__( 'Send', 'songpress' ) . '</li></ol>';
		$o .= '<div class="spp-body"></div><div class="spp-foot"><button type="button" class="spe-btn" id="spp-back">' . esc_html__( 'Back', 'songpress' ) . '</button><button type="button" class="spe-save" id="spp-next">' . esc_html__( 'Next', 'songpress' ) . '</button></div></div></div>';
		$o .= '<script type="application/json" id="spp-data">' . wp_json_encode( $data ) . '</script>';
		return $o . '<script>' . self::script() . '</script>';
	}

	private static function script() {
		return <<<'JS'
(function(){
var D=JSON.parse(document.getElementById('spp-data').textContent),M=document.getElementById('spp-modal');if(!M)return;
var body=M.querySelector('.spp-body'),stepEls=M.querySelectorAll('.spp-steps li'),nextB=document.getElementById('spp-next'),backB=document.getElementById('spp-back');
var S={step:1,id:0,info:null,ch:null,gi:0};
function h(s){var d=document.createElement('div');d.textContent=s==null?'':String(s);return d.innerHTML}
function a(s){return h(s).replace(/"/g,'&quot;')}
function post(fd){fd.append('_wpnonce',D.nonce);return fetch(D.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()})}
function api(action,obj){var f=new FormData();f.append('action',action);for(var k in obj)f.append(k,obj[k]);return post(f)}
function addDays(d,n){var t=new Date(d+'T12:00:00Z');t.setUTCDate(t.getUTCDate()+n);return t.toISOString().slice(0,10)}
function open(id){M.hidden=false;document.body.classList.add('spp-lock');S={step:1,id:0,info:null,ch:null,gi:0};if(id){load(id,2)}else show()}
function close(){M.hidden=true;document.body.classList.remove('spp-lock')}
function load(id,to){body.innerHTML='<p class="spe-help">Loading…</p>';api('songpress_pitch_ajax',{op:'song',song_id:id}).then(function(r){if(!r.ok){body.innerHTML='<p class="spe-note bad">'+h(r.error)+'</p>';return}S.id=id;S.info=r;S.step=to;show()}).catch(function(){body.innerHTML='<p class="spe-note bad">Could not reach the site. Try again.</p>'})}
function intro(t){return '<div class="spp-intro">'+t+'</div>'}
function step1(){
 body.innerHTML=intro('<b>Which song are you pitching?</b> Pick one from your catalog. If it is not here yet, add it under Works first. Tip: pitch a song that is finished and sounds the way you want it heard.')+'<input id="spp-q" type="search" placeholder="Type a title, a serial number or a band…" autocomplete="off"><ul class="spp-list" id="spp-songs"></ul>';
 var inp=body.querySelector('#spp-q'),ul=body.querySelector('#spp-songs');
 function fill(){var t=inp.value.toLowerCase(),n=0,out='';D.songs.forEach(function(s){if(n<40&&(!t||(s.serial+' '+s.title+' '+s.band).toLowerCase().indexOf(t)>-1)){n++;out+='<li><button type="button" data-id="'+s.id+'"><b>'+h(s.serial)+'</b> '+h(s.title)+(s.band?' <span class="spe-help">· '+h(s.band)+'</span>':'')+'</button></li>'}});ul.innerHTML=out||'<li class="spe-help">No match.</li>'}
 inp.oninput=fill;fill();inp.focus();
 ul.onclick=function(e){var b=e.target.closest('button[data-id]');if(b)load(parseInt(b.getAttribute('data-id'),10),2)}
}
function fileRow(key,f){
 var d=D.files[key],v=f[key]||'';
 var note={wav:'The full-quality master. Radio and sync libraries want WAV (ideally 48 kHz, 24-bit), not an MP3.',clean:'A version with no cursing, for radio.',instr:'The song with the vocal taken out. Music supervisors love these.',stems:'Separate tracks (drums, bass, vocal…) in one .zip. Optional, but it helps with sync.',photo:'A good photo of the artist or band.',bio_url:'Optional. A PDF or Word bio, if you already have one.'}[key];
 return '<div class="spp-file"><label><b>'+h(d.label)+'</b></label><p class="spe-help">'+h(note)+'</p><div class="spp-frow"><input type="url" data-k="'+key+'" placeholder="https://… or use the buttons" value="'+a(v)+'">'+(D.canup?'<label class="spe-btn spp-up">Upload<input type="file" hidden data-up="'+key+'"></label>':'')+'<button type="button" class="spe-btn" data-find="'+key+'">Find in Media Library</button></div><div class="spp-find" hidden></div><p class="spp-msg spe-help"></p></div>'
}
function checklist(where){
 var out='';S.info.ready.forEach(function(r){if(r.where!==where)return;out+='<li class="'+(r.ok?'ok':'no')+'">'+(r.ok?'✓ ':'○ ')+h(r.label)+(r.ok?'':'<br><span class="spe-help">'+h(r.why)+'</span>')+'</li>'});return out?'<ul class="spp-ready">'+out+'</ul>':''
}
function step2(){
 var f=S.info.facts,k=S.info.draft;
 body.innerHTML=intro('<b>'+h(f.serial+' '+f.title)+'</b>: gather the files and words. People who receive pitches get hundreds, so a complete package with a real <i>WAV download</i>, a short <i>bio</i> and the <i>story of the song</i> gets noticed. Fill in what you can; you can come back any time.')+checklist('files')+checklist('story')
 +'<h4>Files</h4><p class="spe-help">Files live in your WordPress Media Library. Upload a new one, or search for one you already uploaded.</p>'+['wav','clean','instr','stems','photo','bio_url'].filter(function(x){return x!=='clean'||f.explicit||f.clean}).map(function(x){return fileRow(x,f)}).join('')
 +'<label class="spp-check"><input type="checkbox" id="spp-expl"'+(f.explicit?' checked':'')+'> This song has explicit lyrics (cursing). Radio will then need a clean version.</label>'
 +'<h4>Artist bio</h4><p class="spe-help">Two or three sentences: who you are, what you sound like, one thing that makes you interesting. Write it like you would say it to a new friend.</p><textarea id="spp-bio" rows="4" placeholder="e.g. Red Beard and Company is a Nashville Americana band…">'+h(f.bio||'')+'</textarea>'
 +(k?'<p><button type="button" class="spe-btn" id="spp-draft">Use a starting draft</button> <span class="spe-help">We reuse this band\\'s bio from another song, or write a rough one from your catalog. Edit it so it sounds like you.</span></p>':'')
 +'<label class="spe-field"><span>Mood or vibe (a few words)</span><input id="spp-mood" placeholder="e.g. warm, hopeful, late-night" value="'+a(f.mood||'')+'"></label>';
 var dr=body.querySelector('#spp-draft');if(dr)dr.onclick=function(){body.querySelector('#spp-bio').value=k};
 var ex=body.querySelector('#spp-expl');ex.onchange=function(){var o=collect();o.op='kit';o.song_id=S.id;o.explicit_set=1;o.explicit=ex.checked?1:0;api('songpress_pitch_ajax',o).then(function(r){if(r.ok){S.info=r;show()}})}
}
function step3(){
 var f=S.info.facts,w=f.writers.map(function(x){return h(x.name)+(x.share?' ('+h(x.share)+'%)':'')}).join(', ')||'<i>none listed</i>',p=f.publishers.map(function(x){return h(x.name)+(x.share?' ('+h(x.share)+'%)':'')+(x.ipi?' · IPI '+h(x.ipi):'')}).join(', ')||'<i>none listed</i>';
 body.innerHTML=intro('<b>Who owns the song?</b> Radio, playlists and especially film and TV people ask who wrote it and who publishes it. This is read from the work, so fix anything wrong in the editor. <i>Publisher</i> just means whoever looks after the song\'s copyright; if that is you, your own name or company counts.')
 +'<dl class="spp-dl"><dt>Writers</dt><dd>'+w+'</dd><dt>Publisher</dt><dd>'+p+'</dd><dt>ISRC</dt><dd>'+(h(f.isrc)||'<i>not yet</i>')+'</dd><dt>ISWC</dt><dd>'+(h(f.iswc)||'<i>not yet</i>')+'</dd><dt>PRO</dt><dd>'+(h(f.pro)||'<i>not set</i>')+'</dd></dl>'
 +checklist('rights')+'<p><a class="spe-btn" href="'+a(S.info.edit)+'" target="_blank" rel="noopener">Open the work editor in a new tab</a> <button type="button" class="spe-btn" id="spp-refresh">I fixed it, refresh</button></p>'
 +'<p class="spe-help">Missing an ISRC or ISWC? That is fine for a first pitch to blogs and playlists. Add them before you sign a sync deal.</p>'
 +'<label class="spe-field"><span>Your contact line (goes at the bottom of every pitch)</span><input id="spp-contact" placeholder="e.g. Adam Page · your email · your phone" value="'+a(f.contact||'')+'"></label><p class="spe-help">Use the email or number you want replies to go to. It is only printed in the text you copy; we never send anything.</p>';
 body.querySelector('#spp-refresh').onclick=function(){var o=collect();o.op='kit';o.song_id=S.id;api('songpress_pitch_ajax',o).then(function(r){if(r.ok){S.info=r;show()}})}
}
function step4(){
 var f=S.info.facts,tabs='',g=D.groups[S.gi];
 D.groups.forEach(function(x,gi){tabs+='<button type="button" class="spe-btn'+(gi===S.gi?' on':'')+'" data-g="'+gi+'">'+h(x.label)+'</button> '});
 var list='<p class="spe-help">'+h(g.tip)+'</p><ul class="spp-chan">'+g.items.map(function(i){return '<li><button type="button" class="spp-pick'+(S.ch&&S.ch.id===i.id?' on':'')+'" data-c="'+a(i.id)+'"><b>'+h(i.name)+'</b> <span class="spp-badge '+i.tier+'">'+({free:'Free',mixed:'Free + paid',paid:'Paid'})[i.tier]+'</span> <span class="spp-cost">'+h(i.cost)+'</span><br><span class="spe-help">'+h(i.blurb)+'</span></button></li>'}).join('')+'</ul>';
 var detail='';
 if(S.ch){var c=S.ch,txt=S.info.copies[c.copy]||'',warn='';
  if(c.copy==='radio'&&f.explicit&&!f.clean)warn+='<p class="spe-note bad">This song is marked explicit and has no clean version. Stations cannot play it in the daytime.</p>';
  if(c.copy==='radio'&&!f.wav)warn+='<p class="spe-note bad">No WAV yet. Go back and add it; stations want a download, not just a stream.</p>';
  detail='<div class="spp-send"><h4>'+h(c.name)+'</h4>'+warn
  +(c.url?'<p><b>1.</b> <a class="spe-save" href="'+a(c.url)+'" target="_blank" rel="noopener noreferrer">'+h(c.cta||'Open site')+' ↗</a> <span class="spe-help">opens in a new tab. Sign in or find the contact there.</span></p>':'<p><b>1.</b> Find the person\'s email or contact page.</p>')
  +'<p><b>2.</b> Paste this where it asks for your message. Replace anything in [brackets].</p><textarea id="spp-copy" rows="9" readonly>'+h(txt)+'</textarea><p><button type="button" class="spe-btn" id="spp-cp">Copy the text</button> <span class="spe-help" id="spp-cpm"></span></p>'
  +'<p><b>3.</b> Come back and log it so we can remind you to follow up.</p><div class="spp-logform"><label class="spe-field"><span>Who or where did you send it? (name, station, playlist)</span><input id="spp-to" placeholder="e.g. WXYZ college radio, Jane the music director"></label><label class="spe-field"><span>Follow up on (two weeks is polite)</span><input id="spp-fu" type="date" value="'+addDays(D.today,14)+'"></label><label class="spe-field"><span>Notes</span><textarea id="spp-nt" rows="2"></textarea></label><button type="button" class="spe-save" id="spp-log">I sent it, log it</button> <span class="spe-help" id="spp-lm"></span></div></div>';
 }
 body.innerHTML=intro('<b>Where do you want to send it?</b> Start with the <b>Free</b> ones; each place is marked Free, Free + paid (free to start, paid extras) or Paid. Pick a place, open its site, paste the text we wrote for it, then log it here. You can send the same song to several places; log each one.')+'<div class="spp-tabs">'+tabs+'</div>'+list+detail;
 body.querySelectorAll('[data-g]').forEach(function(b){b.onclick=function(){S.gi=+b.getAttribute('data-g');S.ch=null;step4()}});
 body.querySelectorAll('[data-c]').forEach(function(b){b.onclick=function(){var id=b.getAttribute('data-c');D.groups.forEach(function(gr){gr.items.forEach(function(i){if(i.id===id)S.ch=i})});step4();var d=body.querySelector('.spp-send');if(d)d.scrollIntoView({block:'nearest'})}});
 var cp=body.querySelector('#spp-cp');if(cp)cp.onclick=function(){var t=body.querySelector('#spp-copy');t.select();var m=body.querySelector('#spp-cpm');try{if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t.value);m.textContent='Copied.'}else{m.textContent=document.execCommand('copy')?'Copied.':'Press Ctrl+C.'}}catch(e){m.textContent='Press Ctrl+C.'}};
 var lg=body.querySelector('#spp-log');if(lg)lg.onclick=function(){var to=body.querySelector('#spp-to').value.trim(),lm=body.querySelector('#spp-lm');if(!to&&S.ch.id==='other'){lm.textContent='Say who you sent it to.';return}lg.disabled=true;api('songpress_pitch_ajax',{op:'log',song_id:S.id,channel:S.ch.id,sent_to:to,sent_at:D.today,follow_up:body.querySelector('#spp-fu').value,notes:body.querySelector('#spp-nt').value}).then(function(r){if(r.ok){location.href=location.pathname+'?sp_page=pitch&p_ok='+encodeURIComponent('Pitch logged. We will remind you to follow up.')}else{lg.disabled=false;lm.textContent=r.error||'Could not save.'}})}
}
function collect(){var o={};body.querySelectorAll('input[data-k]').forEach(function(i){o[i.getAttribute('data-k')]=i.value});['bio','mood','contact'].forEach(function(k){var e=body.querySelector('#spp-'+k);if(e)o[k]=e.value});return o}
function saveKit(then){var o=collect();if(!Object.keys(o).length){then();return}o.op='kit';o.song_id=S.id;nextB.disabled=true;api('songpress_pitch_ajax',o).then(function(r){nextB.disabled=false;if(r.ok){S.info=r}then()}).catch(function(){nextB.disabled=false;then()})}
function show(){
 stepEls.forEach(function(e,i){e.className=i+1===S.step?'on':(i+1<S.step?'done':'')});
 backB.style.visibility=S.step>1?'visible':'hidden';nextB.hidden=S.step===1||S.step===4;nextB.textContent=S.step===3?'Next: choose where to send':'Next';
 [step1,step2,step3,step4][S.step-1]();body.scrollTop=0
}
nextB.onclick=function(){if(S.step===2||S.step===3){saveKit(function(){S.step++;show()})}else if(S.step<4){S.step++;show()}};
backB.onclick=function(){if(S.step>1){var go=function(){S.step--;show()};if(S.step===2||S.step===3)saveKit(go);else go()}};
document.getElementById('spp-x').onclick=close;M.onclick=function(e){if(e.target===M)close()};document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!M.hidden)close()});
document.getElementById('spp-open').onclick=function(){open(0)};
body.addEventListener('change',function(e){var i=e.target;if(!i.getAttribute||!i.getAttribute('data-up')||!i.files[0])return;var key=i.getAttribute('data-up'),row=i.closest('.spp-file'),m=row.querySelector('.spp-msg');m.textContent='Uploading '+i.files[0].name+'…';var f=new FormData();f.append('action','songpress_pitch_upload');f.append('kind',D.files[key].kind);f.append('file',i.files[0]);post(f).then(function(r){if(r.ok){row.querySelector('input[data-k]').value=r.url;m.textContent='Added to your Media Library: '+r.name}else m.textContent=r.error||'Upload failed.'}).catch(function(){m.textContent='Upload failed.'});i.value=''});
body.addEventListener('click',function(e){var b=e.target.closest('[data-find]');if(!b)return;var key=b.getAttribute('data-find'),row=b.closest('.spp-file'),box=row.querySelector('.spp-find');box.hidden=false;box.innerHTML='<input type="search" placeholder="Search your Media Library by name…"><ul></ul>';var inp=box.querySelector('input'),ul=box.querySelector('ul'),tm;function run(){api('songpress_pitch_media',{kind:D.files[key].kind,q:inp.value}).then(function(r){ul.innerHTML=(r.items||[]).map(function(x,n){return '<li><button type="button" data-pick="'+n+'">'+h(x.title)+' <span class="spe-help">'+h(x.type)+'</span></button></li>'}).join('')||'<li class="spe-help">Nothing found. Try Upload instead.</li>';ul.onclick=function(ev){var p=ev.target.closest('[data-pick]');if(!p)return;row.querySelector('input[data-k]').value=r.items[+p.getAttribute('data-pick')].url;box.hidden=true}})}inp.oninput=function(){clearTimeout(tm);tm=setTimeout(run,250)};run();inp.focus()});
if(D.autoopen&&D.start){open(D.start)}
})();
JS;
	}
}
