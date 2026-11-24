<?php
/**
 * The Transcriber: a page that listens to a recording and writes ChordPro.
 *
 * It is the ChordPro tool with an audio panel on top (see Songpress_ChordTool::render). The chords, key and tempo are worked
 * out in the browser (assets/transcriber-core.js). The words' timing comes from Groq's free Whisper service: the browser
 * sends a small mono copy of the recording here, this class forwards it with the site's own key, and hands the timed words
 * back. The key never reaches the browser. Nothing is stored.
 *
 * @package SongPress
 */

defined( 'ABSPATH' ) || exit;

class Songpress_Transcriber {

	const KEY      = 'songpress_groq_key';
	const PAGE     = 'songpress_page_transcriber';
	const ENDPOINT = 'https://api.groq.com/openai/v1/audio/transcriptions';
	const MODEL    = 'whisper-large-v3';
	const MAX      = 25000000; // the free plan takes files up to 25 MB

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 1 );
		add_action( 'init', array( __CLASS__, 'ensure_page' ), 20 );
		add_shortcode( 'songpress_transcriber', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_ajax_songpress_tr_align', array( __CLASS__, 'ajax_align' ) );
		add_action( 'wp_ajax_songpress_tr_key', array( __CLASS__, 'ajax_key' ) );
		add_action( 'wp_ajax_songpress_tr_works', array( __CLASS__, 'ajax_works' ) );
		add_action( 'wp_ajax_songpress_tr_put', array( __CLASS__, 'ajax_put' ) );
	}

	/** The page's address: the WordPress page called Transcriber, or the built-in address while it does not exist. */
	public static function url( $args = array() ) {
		$id   = (int) get_option( self::PAGE );
		$base = Songpress_Install::page_ok( $id ) ? get_permalink( $id ) : add_query_arg( 'sp_transcriber', '1', home_url( '/' ) );
		return $args ? add_query_arg( $args, $base ) : $base;
	}

	/** Make the page once, for someone who may publish pages. Never overwrites a page that is already there. */
	public static function ensure_page() {
		if ( Songpress_Install::page_ok( get_option( self::PAGE ) ) || '1' === get_option( 'songpress_tr_page_tried' ) || ! current_user_can( 'publish_pages' ) ) {
			return;
		}
		update_option( 'songpress_tr_page_tried', '1' );
		$old = get_page_by_path( 'transcriber' );
		if ( $old && 'trash' !== $old->post_status ) {
			update_option( self::PAGE, (int) $old->ID );
			return;
		}
		$id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => __( 'Transcriber', 'songpress' ),
			'post_name'    => 'transcriber',
			'post_content' => '<!-- wp:shortcode -->[songpress_transcriber]<!-- /wp:shortcode -->',
		), true );
		if ( ! is_wp_error( $id ) && $id ) {
			update_option( self::PAGE, (int) $id );
		}
	}

	/** Show the tool, by itself, on the Transcriber page. */
	public static function serve() {
		$id = (int) get_option( self::PAGE );
		if ( isset( $_GET['sp_transcriber'] ) || ( $id && is_page( $id ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			Songpress_ChordTool::render( true );
			exit;
		}
	}

	/** The Transcriber inside the app, under the rail: the page itself in a frame that fills the screen. */
	public static function frame() {
		if ( ! self::can_use() ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'Your account cannot use the Transcriber.', 'songpress' ) . '</p></div>';
		}
		return '<div class="sp-app sp-trframe"><iframe src="' . esc_url( self::url( array( 'frame' => 1 ) ) ) . '" title="' . esc_attr__( 'Transcriber', 'songpress' ) . '" allow="autoplay"></iframe></div>';
	}

	public static function shortcode() {
		return '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open the Transcriber', 'songpress' ) . '</a></p>';
	}

	/* ---------- the key ---------- */

	public static function has_key() {
		return '' !== (string) get_option( self::KEY, '' );
	}

	public static function can_use() {
		return is_user_logged_in() && Songpress_Caps::can( 'edit_own' );
	}

	public static function config() {
		return array(
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'nonce'  => wp_create_nonce( 'songpress_tr' ),
			'can'    => self::can_use(),
			'hasKey' => self::has_key(),
			'settings' => Songpress_Caps::can( 'settings' ) ? Songpress_Screens::url( 'settings' ) : '',
			'login'  => wp_login_url( self::url() ),
			'song'   => self::preset(),
		);
	}

	/** A work named in the address (?song=ID) that this person may change: {id, title}, else null. */
	public static function preset() {
		$id = isset( $_GET['song'] ) ? absint( $_GET['song'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $id || ! is_user_logged_in() || ! Songpress_Caps::can_edit_song( $id ) ) {
			return null;
		}
		$s = Songpress_Songs::get( $id );
		return $s ? array( 'id' => (int) $s->id, 'title' => (string) Songpress_Songs::display_title( $s ) ) : null;
	}

	/** Works this person may change whose title matches the search (at most 30). */
	public static function ajax_works() {
		check_ajax_referer( 'songpress_tr', 'n' );
		if ( ! self::can_use() ) {
			wp_send_json_error( array( 'msg' => __( 'Log in to choose a work.', 'songpress' ) ), 403 );
		}
		$q    = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
		$args = array( 'search' => $q, 'per_page' => 30, 'page' => 1 );
		if ( ! Songpress_Caps::can( 'edit_any' ) ) {
			$args['user_id'] = get_current_user_id();
		}
		$res  = Songpress_Songs::query( $args );
		$out  = array();
		foreach ( (array) $res['rows'] as $r ) {
			if ( Songpress_Caps::can_edit_song( (int) $r->id ) ) {
				$out[] = array( 'id' => (int) $r->id, 'title' => (string) Songpress_Songs::display_title( $r ), 'has' => '' !== trim( (string) $r->chordpro ) );
			}
		}
		wp_send_json_success( array( 'works' => $out ) );
	}

	/** Save the chart into a work's ChordPro. A work that already has one is only replaced when asked. */
	public static function ajax_put() {
		check_ajax_referer( 'songpress_tr', 'n' );
		$id = absint( $_POST['song'] ?? 0 );
		if ( ! self::can_use() || ! $id || ! Songpress_Caps::can_edit_song( $id ) ) {
			wp_send_json_error( array( 'msg' => __( 'You cannot change that work.', 'songpress' ) ), 403 );
		}
		$song = Songpress_Songs::get( $id );
		$text = trim( str_replace( "\r", '', (string) wp_unslash( $_POST['chordpro'] ?? '' ) ) );
		if ( ! $song || '' === $text ) {
			wp_send_json_error( array( 'msg' => __( 'There is no chart to save yet. Press Listen and write the chart first.', 'songpress' ) ) );
		}
		if ( '' !== trim( (string) $song->chordpro ) && empty( $_POST['replace'] ) ) {
			wp_send_json_error( array( 'msg' => __( 'That work already has a ChordPro chart.', 'songpress' ), 'exists' => true ) );
		}
		Songpress_Songs::update( $id, array( 'chordpro' => $text . "\n" ) );
		Songpress_Audit::log( 'tools', 'Put a transcribed chart in a work', (string) $song->title, array( 'song' => $id ) );
		wp_send_json_success( array( 'title' => (string) Songpress_Songs::display_title( $song ), 'url' => Songpress_Editor::edit_url( $id ) ) );
	}

	public static function ajax_key() {
		check_ajax_referer( 'songpress_tr', 'n' );
		if ( ! is_user_logged_in() || ! Songpress_Caps::can( 'settings' ) ) {
			wp_send_json_error( array( 'msg' => __( 'Only a manager can set the key.', 'songpress' ) ), 403 );
		}
		$k = trim( sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) ) );
		if ( '' !== $k && ! preg_match( '/^[A-Za-z0-9_\-]{20,200}$/', $k ) ) {
			wp_send_json_error( array( 'msg' => __( 'That does not look like a key. It starts with gsk_ and is about 56 characters.', 'songpress' ) ) );
		}
		'' === $k ? delete_option( self::KEY ) : update_option( self::KEY, $k, false );
		wp_send_json_success( array( 'hasKey' => '' !== $k ) );
	}

	/* ---------- word timing ---------- */

	/** Pure: the multipart body for the service, so it can be tested. */
	public static function multipart( $boundary, array $fields, $filename, $bytes ) {
		$b = '';
		foreach ( $fields as $f ) {
			$b .= '--' . $boundary . "\r\n" . 'Content-Disposition: form-data; name="' . $f[0] . '"' . "\r\n\r\n" . $f[1] . "\r\n";
		}
		return $b . '--' . $boundary . "\r\n" . 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\nContent-Type: audio/wav\r\n\r\n" . $bytes . "\r\n--" . $boundary . "--\r\n";
	}

	/** Pure: the words and times out of the service's answer, or null. */
	public static function words_from( $json ) {
		$d = is_array( $json ) ? $json : json_decode( (string) $json, true );
		if ( ! is_array( $d ) || empty( $d['words'] ) || ! is_array( $d['words'] ) ) {
			return null;
		}
		$out = array();
		foreach ( $d['words'] as $w ) {
			if ( isset( $w['word'], $w['start'], $w['end'] ) ) {
				$out[] = array( 'word' => (string) $w['word'], 'start' => round( (float) $w['start'], 3 ), 'end' => round( (float) $w['end'], 3 ) );
			}
		}
		return $out;
	}

	public static function ajax_align() {
		check_ajax_referer( 'songpress_tr', 'n' );
		if ( ! self::can_use() ) {
			wp_send_json_error( array( 'msg' => __( 'Log in to use word timing.', 'songpress' ) ), 403 );
		}
		$key = (string) get_option( self::KEY, '' );
		if ( '' === $key ) {
			wp_send_json_error( array( 'msg' => __( 'No key is set yet.', 'songpress' ), 'nokey' => true ) );
		}
		if ( empty( $_FILES['audio']['tmp_name'] ) || ! is_uploaded_file( $_FILES['audio']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_send_json_error( array( 'msg' => __( 'The recording did not arrive. It may be larger than the server allows.', 'songpress' ) ) );
		}
		$size = (int) $_FILES['audio']['size']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( $size > self::MAX ) {
			wp_send_json_error( array( 'msg' => __( 'That recording is too long for the free service (about 13 minutes at most).', 'songpress' ) ) );
		}
		$bytes = file_get_contents( $_FILES['audio']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.WP.AlternativeFunctions
		$prompt = mb_substr( sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) ), 0, 700 );
		$lang   = preg_replace( '/[^a-z]/', '', strtolower( (string) ( $_POST['lang'] ?? '' ) ) );
		$fields = array( array( 'model', self::MODEL ), array( 'response_format', 'verbose_json' ), array( 'timestamp_granularities[]', 'word' ), array( 'temperature', '0' ) );
		if ( '' !== $prompt ) {
			$fields[] = array( 'prompt', $prompt );
		}
		if ( '' !== $lang && strlen( $lang ) <= 3 ) {
			$fields[] = array( 'language', $lang );
		}
		$boundary = 'sp' . wp_generate_password( 24, false );
		$res      = wp_remote_post( self::ENDPOINT, array(
			'timeout' => 150,
			'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
			'body'    => self::multipart( $boundary, $fields, 'recording.wav', $bytes ),
		) );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'msg' => sprintf( /* translators: %s: error */ __( 'Could not reach the timing service: %s', 'songpress' ), $res->get_error_message() ) ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = wp_remote_retrieve_body( $res );
		if ( 401 === $code || 403 === $code ) {
			wp_send_json_error( array( 'msg' => __( 'The service refused the key. A manager can paste a new one on this page.', 'songpress' ), 'badkey' => true ) );
		}
		if ( 429 === $code ) {
			wp_send_json_error( array( 'msg' => __( 'The free service is at its limit for now. Try again in a few minutes (the free plan allows about two hours of audio an hour).', 'songpress' ) ) );
		}
		$words = 200 === $code ? self::words_from( $body ) : null;
		if ( null === $words ) {
			$d   = json_decode( $body, true );
			$why = is_array( $d ) && ! empty( $d['error']['message'] ) ? (string) $d['error']['message'] : 'HTTP ' . $code;
			wp_send_json_error( array( 'msg' => sprintf( /* translators: %s: reason */ __( 'The timing service could not use that recording: %s', 'songpress' ), $why ) ) );
		}
		Songpress_Audit::log( 'tools', 'Timed the words of a recording', '', array( 'words' => count( $words ) ) );
		wp_send_json_success( array( 'words' => $words ) );
	}

	/* ---------- the panel above the editor ---------- */

	public static function panel() {
		$o  = '<section class="ct-tr" id="ct-tr">';
		$o .= '<h2>' . esc_html__( '1. The recording', 'songpress' ) . '</h2>';
		$o .= '<div class="row"><input type="file" id="tr-file" accept="audio/*,.mp3,.m4a,.wav,.aac,.flac,.ogg"><button type="button" class="ct-b ct-go" id="tr-go" disabled>' . esc_html__( 'Listen and write the chart', 'songpress' ) . '</button><label><small>' . esc_html__( 'Chords', 'songpress' ) . '</small> <select id="tr-vocab" class="ct-b"><option value="simple">' . esc_html__( 'Plain (major, minor)', 'songpress' ) . '</option><option value="sevenths">' . esc_html__( 'With 7ths', 'songpress' ) . '</option></select></label></div>';
		$o .= '<h2>' . esc_html__( '2. The lyrics', 'songpress' ) . '</h2>';
		$o .= '<div class="row"><input type="text" id="tr-title" placeholder="' . esc_attr__( 'Title', 'songpress' ) . '" size="26"><input type="text" id="tr-artist" placeholder="' . esc_attr__( 'Artist (optional)', 'songpress' ) . '" size="22"><input type="text" id="tr-lang" placeholder="en" size="3" title="' . esc_attr__( 'Language code of the lyrics', 'songpress' ) . '" value="en"></div>';
		$o .= '<textarea id="tr-lyrics" spellcheck="false" placeholder="' . esc_attr__( "Paste the lyrics here, one line per sung line. Blank lines and labels like \"Chorus:\" are kept.", 'songpress' ) . '"></textarea>';
		$o .= '<div class="row"><span class="msg" id="tr-msg"></span><progress id="tr-prog" max="100" value="0" hidden></progress></div>';
		$o .= '<div id="tr-found" hidden><h2>' . esc_html__( '3. What it heard', 'songpress' ) . '</h2><div class="msg" id="tr-sum"></div><audio id="tr-audio" controls></audio><div class="ct-chips" id="tr-chips" hidden></div><div class="row"><button type="button" class="ct-b ct-go" id="tr-build">' . esc_html__( 'Write the chart', 'songpress' ) . '</button><label><small>' . esc_html__( 'Chart is rebuilt into the editor below', 'songpress' ) . '</small></label></div></div>';
		$o .= '<details id="tr-tap" hidden><summary>' . esc_html__( 'Line up the lyrics by tapping instead', 'songpress' ) . '</summary><p class="msg">' . esc_html__( 'Play the recording and press the big button (or the space bar) the moment each line starts.', 'songpress' ) . '</p><div class="ct-lines" id="tr-lines"></div><div class="row"><button type="button" class="ct-b ct-go" id="tr-tapbtn">' . esc_html__( 'Next line starts now', 'songpress' ) . '</button><button type="button" class="ct-b" id="tr-undo">' . esc_html__( 'Undo', 'songpress' ) . '</button><button type="button" class="ct-b" id="tr-reset">' . esc_html__( 'Start over', 'songpress' ) . '</button></div></details>';
		$o .= '<h2>' . esc_html__( '4. Put the chart in a work', 'songpress' ) . '</h2><div class="row"><input type="search" id="tr-wq" placeholder="' . esc_attr__( 'Find a work by title…', 'songpress' ) . '" size="26" autocomplete="off"><select id="tr-work" class="ct-b" aria-label="' . esc_attr__( 'Work', 'songpress' ) . '"><option value="">' . esc_html__( 'Choose a work', 'songpress' ) . '</option></select><button type="button" class="ct-b ct-go" id="tr-put" disabled>' . esc_html__( 'Put the chart in this work', 'songpress' ) . '</button></div><div class="row"><span class="msg" id="tr-putmsg" aria-live="polite"></span></div>';
		return $o . '</section>';
	}
}
