<?php
defined( 'ABSPATH' ) || exit;

/**
 * The Art studio, inside the SongPress app: layered cover art. A project is a list of layers (a canvas colour,
 * pictures, text, shapes) placed in percent of a square canvas. The picture is drawn in the browser; saving
 * keeps the layers, and "Make cover" renders the square, adds it to the Media Library and sets it as the song's art.
 * Managers only, on the desktop app.
 */
class Songpress_Art {

	const MAX_LAYERS = 80;
	const TYPES      = array( 'canvas', 'image', 'text', 'shape' );

	public static function init() {
		add_action( 'wp_ajax_songpress_art_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'admin_post_songpress_art_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'wp_ajax_songpress_art_key', array( __CLASS__, 'ajax_key' ) );
		add_action( 'wp_ajax_songpress_art_generate', array( __CLASS__, 'ajax_generate' ) );
		add_action( 'wp_ajax_songpress_art_claim', array( __CLASS__, 'ajax_claim' ) );
	}

	private static function t() {
		return Songpress_Install::table( 'art' );
	}

	/* ---------- layers (pure) ---------- */

	private static function num( $v, $min, $max, $d = 0 ) {
		return is_numeric( $v ) ? (float) max( $min, min( $max, round( (float) $v, 4 ) ) ) : (float) $d;
	}

	private static function color( $c, $d = '#000000' ) {
		$c = trim( (string) $c );
		return preg_match( '/^#[0-9a-fA-F]{3,8}$/', $c ) ? $c : $d;
	}

	/** One layer, from either the old studio's shape (settings under "meta") or ours. Always returns the flat, checked shape. */
	public static function norm_layer( $l ) {
		if ( ! is_array( $l ) ) {
			return null;
		}
		$type = in_array( $l['type'] ?? '', self::TYPES, true ) ? $l['type'] : '';
		if ( '' === $type ) {
			return null;
		}
		$m    = ( isset( $l['meta'] ) && is_array( $l['meta'] ) ) ? $l['meta'] : array();
		$get  = function ( $k, $d = '' ) use ( $l, $m ) {
			return $l[ $k ] ?? ( $m[ $k ] ?? $d );
		};
		$crop = ( isset( $l['crop'] ) && is_array( $l['crop'] ) ) ? $l['crop'] : array();
		$src  = esc_url_raw( (string) ( $l['src'] ?? '' ) );
		return array(
			'type'    => $type,
			'name'    => mb_substr( sanitize_text_field( (string) ( $l['name'] ?? ucfirst( $type ) ) ), 0, 80 ),
			'src'     => $src,
			'x'       => self::num( $get( 'x', 0 ), -400, 500, 0 ),
			'y'       => self::num( $get( 'y', 0 ), -400, 500, 0 ),
			'w'       => self::num( $get( 'w', 100 ), 0, 800, 100 ),
			'h'       => self::num( $get( 'h', 100 ), 0, 800, 100 ),
			'crop'    => array( 'x' => self::num( $crop['x'] ?? 0, 0, 1, 0 ), 'y' => self::num( $crop['y'] ?? 0, 0, 1, 0 ), 'w' => self::num( $crop['w'] ?? 1, 0.001, 1, 1 ), 'h' => self::num( $crop['h'] ?? 1, 0.001, 1, 1 ) ),
			'visible' => isset( $l['visible'] ) ? ( empty( $l['visible'] ) ? 0 : 1 ) : 1,
			'opacity' => self::num( $get( 'opacity', 1 ), 0, 1, 1 ),
			'rotate'  => self::num( $get( 'rotate', 0 ), -360, 360, 0 ),
			'text'    => mb_substr( sanitize_textarea_field( (string) $get( 'text', '' ) ), 0, 500 ),
			'font'    => mb_substr( sanitize_text_field( (string) $get( 'font', '' ) ), 0, 64 ),
			'size'    => self::num( $get( 'size', 32 ), 4, 400, 32 ),
			'color'   => self::color( $get( 'color', '#000000' ), 'canvas' === $type ? '#000000' : '#ffffff' ),
			'bold'    => empty( $get( 'bold', 0 ) ) ? 0 : 1,
			'italic'  => empty( $get( 'italic', 0 ) ) ? 0 : 1,
			'caps'    => empty( $get( 'caps', 0 ) ) ? 0 : 1,
			'align'   => in_array( $get( 'align', 'center' ), array( 'left', 'center', 'right' ), true ) ? $get( 'align', 'center' ) : 'center',
			'valign'  => in_array( $get( 'valign', 'middle' ), array( 'top', 'middle', 'bottom' ), true ) ? $get( 'valign', 'middle' ) : 'middle',
			'shadow'  => self::num( $get( 'shadow', 0 ), 0, 1, 0 ),
			'shape'   => 'ellipse' === $get( 'shape', '' ) ? 'ellipse' : 'rect',
			'flow'    => in_array( $get( 'flow', '' ), array( 'stack', 'turn' ), true ) ? $get( 'flow', '' ) : '',
		);
	}

	public static function norm_layers( $list ) {
		$out    = array();
		$canvas = array();
		foreach ( is_array( $list ) ? $list : array() as $l ) {
			$n = self::norm_layer( $l );
			if ( ! $n || ( 'image' === $n['type'] && '' === $n['src'] ) ) {
				continue;
			}
			// the old studio saved its canvas colour as the last row; it is the bottom of the stack
			if ( 'canvas' === $n['type'] ) {
				$canvas = array( $n );
			} else {
				$out[] = $n;
			}
		}
		return array_slice( array_merge( $canvas, $out ), 0, self::MAX_LAYERS );
	}

	/* ---------- import ---------- */

	/** Bring the old studio's projects across. Projects already here (by their old id) are left alone. */
	public static function import_pdo( PDO $pdo ) {
		global $wpdb;
		$q = $pdo->prepare( "SELECT 1 FROM sqlite_master WHERE type='table' AND name = 'art_projects'" );
		$q->execute();
		if ( ! $q->fetchColumn() ) {
			return 0;
		}
		$songs = Songpress_Install::table( 'songs' );
		$byser = array();
		foreach ( $wpdb->get_results( "SELECT id, serial FROM {$songs}" ) as $r ) { // phpcs:ignore WordPress.DB
			$byser[ $r->serial ] = (int) $r->id;
		}
		$t    = self::t();
		$have = array_flip( $wpdb->get_col( "SELECT source_key FROM {$t} WHERE source_key IS NOT NULL" ) ); // phpcs:ignore WordPress.DB
		$n    = 0;
		foreach ( $pdo->query( 'SELECT id, name, layers_json, preview, updated_at, serial FROM art_projects' ) as $r ) {
			$key = 'old:' . (int) $r['id'];
			if ( isset( $have[ $key ] ) ) {
				continue;
			}
			$layers = self::norm_layers( json_decode( (string) $r['layers_json'], true ) );
			$ser    = Songpress_Journal::serial_in( (string) ( $r['serial'] ?: $r['name'] ) );
			$when   = preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $r['updated_at'] ) ? substr( str_replace( 'T', ' ', $r['updated_at'] ), 0, 19 ) : current_time( 'mysql' );
			$wpdb->insert( $t, array(
				'song_id'     => ( '' !== $ser && isset( $byser[ $ser ] ) ) ? $byser[ $ser ] : 0,
				'name'        => mb_substr( (string) $r['name'], 0, 191 ),
				'layers'      => wp_json_encode( $layers ),
				'preview_url' => esc_url_raw( (string) $r['preview'] ),
				'source_key'  => $key,
				'user_id'     => 0,
				'created_at'  => $when,
				'updated_at'  => $when,
			) );
			$n++;
		}
		return $n;
	}

	/* ---------- data ---------- */

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	public static function all() {
		global $wpdb;
		$t = self::t();
		$s = Songpress_Install::table( 'songs' );
		return $wpdb->get_results( "SELECT a.id, a.name, a.song_id, a.preview_url, a.updated_at, s.serial FROM {$t} a LEFT JOIN {$s} s ON s.id = a.song_id ORDER BY a.updated_at DESC, a.id DESC LIMIT 500" ); // phpcs:ignore WordPress.DB
	}

	/* ---------- handlers ---------- */

	public static function ajax_save() {
		check_ajax_referer( 'songpress_art', 'nonce' );
		if ( ! Songpress_Caps::can( 'art' ) ) {
			wp_send_json_error( array( 'message' => __( 'Only Managers can use the Art studio.', 'songpress' ) ), 403 );
		}
		global $wpdb;
		$id     = absint( $_POST['id'] ?? 0 );
		$name   = mb_substr( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ), 0, 191 );
		$layers = self::norm_layers( json_decode( wp_unslash( $_POST['layers'] ?? '[]' ), true ) );
		$ser    = Songpress_Journal::serial_in( sanitize_text_field( wp_unslash( $_POST['serial'] ?? '' ) ) );
		$sid    = '' === $ser ? 0 : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Songpress_Install::table( 'songs' ) . ' WHERE serial = %s', $ser ) ); // phpcs:ignore WordPress.DB
		$row    = array( 'song_id' => $sid, 'name' => '' === $name ? __( 'Untitled art', 'songpress' ) : $name, 'layers' => wp_json_encode( $layers ), 'updated_at' => current_time( 'mysql' ) );
		$cover  = false;
		if ( ! empty( $_FILES['file']['tmp_name'] ) ) {
			if ( ! Songpress_Caps::can( 'upload' ) ) {
				wp_send_json_error( array( 'message' => __( 'You are not allowed to upload files.', 'songpress' ) ), 403 );
			}
			$check = wp_check_filetype_and_ext( $_FILES['file']['tmp_name'], $_FILES['file']['name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( 0 !== strpos( (string) $check['type'], 'image/' ) ) {
				wp_send_json_error( array( 'message' => __( 'The picture could not be read.', 'songpress' ) ), 415 );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			$att = media_handle_upload( 'file', 0 );
			if ( is_wp_error( $att ) ) {
				wp_send_json_error( array( 'message' => $att->get_error_message() ), 400 );
			}
			$row['preview_id']  = (int) $att;
			$row['preview_url'] = (string) wp_get_attachment_url( $att );
			if ( $sid && ! empty( $_POST['set_cover'] ) && Songpress_Caps::can_edit_song( $sid ) ) {
				Songpress_Songs::update( $sid, array( 'art_id' => (int) $att ) );
				$cover = true;
			}
		}
		if ( $id && self::get( $id ) ) {
			$wpdb->update( self::t(), $row, array( 'id' => $id ) );
		} else {
			$wpdb->insert( self::t(), array_merge( $row, array( 'user_id' => get_current_user_id(), 'created_at' => current_time( 'mysql' ) ) ) );
			$id = (int) $wpdb->insert_id;
		}
		wp_send_json_success( array( 'id' => $id, 'song_id' => $sid, 'preview_url' => $row['preview_url'] ?? '', 'cover' => $cover, 'url' => add_query_arg( array( 'sp_page' => 'art', 'art' => $id ), Songpress_Editor::list_url() ) ) );
	}

	/* ---------- AI pictures (xAI) ---------- */

	const XAI_URL = 'https://api.x.ai/v1/images/generations';

	/** Pull the picture addresses out of an xAI response body. URLs stay URLs; base64 becomes a data: address. */
	public static function parse_images( $body ) {
		$j   = is_array( $body ) ? $body : json_decode( (string) $body, true );
		$out = array();
		if ( ! is_array( $j ) || empty( $j['data'] ) || ! is_array( $j['data'] ) ) {
			return $out;
		}
		foreach ( $j['data'] as $d ) {
			if ( ! is_array( $d ) ) {
				continue;
			}
			if ( ! empty( $d['url'] ) && 0 === strpos( (string) $d['url'], 'https://' ) ) {
				$out[] = (string) $d['url'];
			} elseif ( ! empty( $d['b64_json'] ) && preg_match( '/^[A-Za-z0-9+\/=\s]+$/', (string) $d['b64_json'] ) ) {
				$out[] = 'data:image/png;base64,' . preg_replace( '/\s+/', '', (string) $d['b64_json'] );
			}
		}
		return $out;
	}

	/** The request bodies to try, newest model first. */
	public static function xai_bodies( $prompt, $n = 4 ) {
		return array(
			array( 'model' => 'grok-imagine-image-2.0', 'prompt' => $prompt, 'n' => $n, 'aspect_ratio' => '1:1' ),
			array( 'model' => 'grok-imagine-image', 'prompt' => $prompt, 'n' => $n, 'size' => '1024x1024' ),
		);
	}

	private static function xai_key() {
		return trim( (string) get_option( 'songpress_xai_key', '' ) );
	}

	public static function ajax_key() {
		check_ajax_referer( 'songpress_art', 'nonce' );
		if ( ! Songpress_Caps::can( 'art' ) ) {
			wp_send_json_error( array( 'message' => __( 'Only Managers can do this.', 'songpress' ) ), 403 );
		}
		$k = preg_replace( '/[^A-Za-z0-9_\-.]/', '', (string) wp_unslash( $_POST['key'] ?? '' ) );
		if ( '' === $k ) {
			delete_option( 'songpress_xai_key' );
		} else {
			update_option( 'songpress_xai_key', $k, false );
		}
		wp_send_json_success( array( 'has' => '' !== $k ) );
	}

	public static function ajax_generate() {
		check_ajax_referer( 'songpress_art', 'nonce' );
		if ( ! Songpress_Caps::can( 'art' ) ) {
			wp_send_json_error( array( 'message' => __( 'Only Managers can use the Art studio.', 'songpress' ) ), 403 );
		}
		$key = self::xai_key();
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Add your xAI key first.', 'songpress' ) ), 400 );
		}
		$prompt = mb_substr( sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) ), 0, 2000 );
		if ( '' === trim( $prompt ) ) {
			wp_send_json_error( array( 'message' => __( 'Describe the picture first.', 'songpress' ) ), 400 );
		}
		if ( ! empty( $_POST['cover'] ) ) {
			$prompt .= ', square 1:1 album cover artwork, no text, no letters, no watermark, no border, full bleed';
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 150 ); // phpcs:ignore
		}
		$err = '';
		$img = array();
		foreach ( self::xai_bodies( $prompt ) as $body ) {
			$r = wp_remote_post( self::XAI_URL, array( 'timeout' => 140, 'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ) );
			if ( is_wp_error( $r ) ) {
				$err = $r->get_error_message();
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			$img  = self::parse_images( wp_remote_retrieve_body( $r ) );
			if ( $code < 300 && $img ) {
				break;
			}
			$j   = json_decode( wp_remote_retrieve_body( $r ), true );
			$err = is_array( $j ) ? (string) ( $j['error']['message'] ?? $j['error'] ?? $j['message'] ?? '' ) : '';
			$err = '' === $err ? sprintf( 'xAI answered %d.', $code ) : $err;
			if ( 401 === $code || 403 === $code ) {
				break;
			}
		}
		if ( ! $img ) {
			wp_send_json_error( array( 'message' => '' === $err ? __( 'No picture came back.', 'songpress' ) : $err ), 502 );
		}
		$ok = array();
		foreach ( $img as $u ) {
			if ( 0 === strpos( $u, 'https://' ) ) {
				$ok[] = md5( $u );
			}
		}
		set_transient( 'songpress_xai_' . get_current_user_id(), $ok, HOUR_IN_SECONDS );
		wp_send_json_success( array( 'images' => $img ) );
	}

	/** Bring a chosen picture into the Media Library and return its address. */
	public static function ajax_claim() {
		check_ajax_referer( 'songpress_art', 'nonce' );
		if ( ! Songpress_Caps::can( 'art' ) || ! Songpress_Caps::can( 'upload' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'songpress' ) ), 403 );
		}
		$src = (string) wp_unslash( $_POST['src'] ?? '' );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$tmp = '';
		if ( preg_match( '#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $src, $m ) ) {
			$bin = base64_decode( $m[1], true ); // phpcs:ignore
			$tmp = wp_tempnam( 'songpress.png' );
			if ( false === $bin || ! $tmp || false === file_put_contents( $tmp, $bin ) ) { // phpcs:ignore
				wp_send_json_error( array( 'message' => __( 'The picture could not be read.', 'songpress' ) ), 400 );
			}
		} else {
			$allowed = (array) get_transient( 'songpress_xai_' . get_current_user_id() );
			if ( 0 !== strpos( $src, 'https://' ) || ! in_array( md5( $src ), $allowed, true ) ) {
				wp_send_json_error( array( 'message' => __( 'That picture is no longer available. Generate again.', 'songpress' ) ), 400 );
			}
			$tmp = download_url( $src, 60 );
			if ( is_wp_error( $tmp ) ) {
				wp_send_json_error( array( 'message' => $tmp->get_error_message() ), 502 );
			}
		}
		$info = @getimagesize( $tmp ); // phpcs:ignore
		if ( ! $info || 0 !== strpos( (string) $info['mime'], 'image/' ) ) {
			@unlink( $tmp ); // phpcs:ignore
			wp_send_json_error( array( 'message' => __( 'The picture could not be read.', 'songpress' ) ), 415 );
		}
		$ext = 'image/jpeg' === $info['mime'] ? 'jpg' : ( 'image/webp' === $info['mime'] ? 'webp' : 'png' );
		$att = media_handle_sideload( array( 'name' => 'songpress-ai-' . gmdate( 'Ymd-His' ) . '.' . $ext, 'tmp_name' => $tmp ), 0 );
		if ( is_wp_error( $att ) ) {
			@unlink( $tmp ); // phpcs:ignore
			wp_send_json_error( array( 'message' => $att->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'url' => (string) wp_get_attachment_url( $att ), 'id' => (int) $att ) );
	}

	public static function handle_delete() {
		check_admin_referer( 'songpress_art_delete' );
		if ( ! Songpress_Caps::can( 'art' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		global $wpdb;
		$wpdb->delete( self::t(), array( 'id' => absint( $_POST['art_id'] ?? 0 ) ) );
		$ret = isset( $_POST['sp_return'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['sp_return'] ) ), '' ) : '';
		wp_safe_redirect( add_query_arg( array( 'sp_page' => 'art', 'a_ok' => rawurlencode( __( 'Project removed.', 'songpress' ) ) ), '' !== $ret ? $ret : Songpress_Editor::list_url() ) );
		exit;
	}

	/* ---------- screens ---------- */

	public static function render() {
		if ( ! Songpress_Caps::can( 'art' ) ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'Only Managers can open this screen.', 'songpress' ) . '</p></div>';
		}
		if ( isset( $_GET['art'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return self::render_editor( 'new' === $_GET['art'] ? 0 : absint( $_GET['art'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		$base = Songpress_Editor::list_url();
		$o    = '<div class="songpress sp-app sp-editor sp-art"><div class="spe-top"><h2>' . esc_html__( 'Art studio', 'songpress' ) . '</h2> <a class="spe-btn" href="' . esc_url( add_query_arg( array( 'sp_page' => 'art', 'art' => 'new' ), $base ) ) . '">+ ' . esc_html__( 'New art', 'songpress' ) . '</a></div>';
		if ( ! empty( $_GET['a_ok'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<p class="spe-note ok">' . esc_html( sanitize_text_field( wp_unslash( $_GET['a_ok'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		$rows = self::all();
		if ( ! $rows ) {
			$o .= '<p class="spe-help">' . esc_html__( 'No art yet. Start a new one, or load your old catalog file on the Money page to bring your projects across.', 'songpress' ) . '</p>';
		}
		$o .= '<ul class="spa-list">';
		foreach ( $rows as $r ) {
			$o .= '<li><a class="spa-thumb" href="' . esc_url( add_query_arg( array( 'sp_page' => 'art', 'art' => (int) $r->id ), $base ) ) . '">' . ( '' !== $r->preview_url ? '<img src="' . esc_url( $r->preview_url ) . '" alt="" loading="lazy">' : '' ) . '</a><div><a href="' . esc_url( add_query_arg( array( 'sp_page' => 'art', 'art' => (int) $r->id ), $base ) ) . '"><b>' . esc_html( $r->name ) . '</b></a><br><span class="spe-help">' . esc_html( ( $r->serial ? $r->serial . ' · ' : '' ) . substr( (string) $r->updated_at, 0, 10 ) ) . '</span></div></li>';
		}
		return $o . '</ul></div>';
	}

	private static function render_editor( $id ) {
		$p = $id ? self::get( $id ) : null;
		if ( $id && ! $p ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'That art project does not exist.', 'songpress' ) . '</p></div>';
		}
		$serial = '';
		$sid    = $p ? (int) $p->song_id : ( isset( $_GET['song'] ) ? absint( $_GET['song'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $sid && ( $s = Songpress_Songs::get( $sid ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$serial = $s->serial;
		}
		$layers = $p ? self::norm_layers( json_decode( (string) $p->layers, true ) ) : array(
			array_merge( self::norm_layer( array( 'type' => 'canvas', 'name' => 'Canvas', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100 ) ), array( 'color' => '#14161a' ) ),
		);
		$cfg  = array(
			'id'     => (int) ( $p ? $p->id : 0 ),
			'name'   => $p ? $p->name : ( $s->title ?? '' ),
			'serial' => $serial,
			'layers' => $layers,
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'nonce'  => wp_create_nonce( 'songpress_art' ),
			'upload' => wp_create_nonce( 'songpress_upload' ),
			'list'   => add_query_arg( 'sp_page', 'art', Songpress_Editor::list_url() ),
			'ai'     => '' !== self::xai_key(),
			'prompt' => (string) ( $s->art_prompt ?? '' ),
		);
		$o  = '<div class="songpress sp-app sp-editor sp-art"><div class="spe-top"><h2>' . esc_html__( 'Art studio', 'songpress' ) . '</h2> <a class="spe-btn" href="' . esc_url( $cfg['list'] ) . '">' . esc_html__( 'All art', 'songpress' ) . '</a></div>';
		$o .= '<div class="spa-bar"><label class="spe-field"><span>' . esc_html__( 'Name', 'songpress' ) . '</span><input id="spa-name"></label><label class="spe-field"><span>' . esc_html__( 'Song serial (to set its cover)', 'songpress' ) . '</span><input id="spa-serial" placeholder="0378"></label><label class="spe-field"><span>' . esc_html__( 'Export size', 'songpress' ) . '</span><select id="spa-size"><option value="1000">1000 px</option><option value="2000">2000 px</option><option value="3000" selected>3000 px</option></select></label></div>';
		$o .= '<div class="spa-tools" id="spa-tools"><button type="button" class="spe-btn on" data-tool="move">' . esc_html__( 'Move', 'songpress' ) . '</button><button type="button" class="spe-btn" data-tool="brush">' . esc_html__( 'Brush', 'songpress' ) . '</button><button type="button" class="spe-btn" data-tool="eraser">' . esc_html__( 'Eraser', 'songpress' ) . '</button><button type="button" class="spe-btn" data-tool="bucket">' . esc_html__( 'Fill', 'songpress' ) . '</button><button type="button" class="spe-btn" data-tool="gradient">' . esc_html__( 'Gradient', 'songpress' ) . '</button><button type="button" class="spe-btn" data-tool="rect">' . esc_html__( 'Rectangle', 'songpress' ) . '</button><button type="button" class="spe-btn" data-tool="ellipse">' . esc_html__( 'Ellipse', 'songpress' ) . '</button><button type="button" class="spe-btn" data-tool="marquee">' . esc_html__( 'Select', 'songpress' ) . '</button><button type="button" class="spe-btn" data-tool="eyedrop">' . esc_html__( 'Pick colour', 'songpress' ) . '</button><label class="spa-tl">' . esc_html__( 'Size', 'songpress' ) . '<input type="range" id="spa-bsize" min="1" max="200" value="24"></label><label class="spa-tl">' . esc_html__( 'Flow', 'songpress' ) . '<input type="range" id="spa-bflow" min="5" max="100" value="100"></label><input type="color" id="spa-bcolor" value="#ffffff" title="' . esc_attr__( 'Colour', 'songpress' ) . '"><input type="color" id="spa-bcolor2" value="#000000" title="' . esc_attr__( 'Second colour (gradient)', 'songpress' ) . '"><label class="spa-tl"><input type="checkbox" id="spa-outline"> ' . esc_html__( 'Outline only', 'songpress' ) . '</label><button type="button" class="spe-btn" id="spa-undo">' . esc_html__( 'Undo', 'songpress' ) . '</button><button type="button" class="spe-btn" id="spa-newdraw">+ ' . esc_html__( 'Drawing', 'songpress' ) . '</button><button type="button" class="spe-btn" id="spa-aibtn">✦ ' . esc_html__( 'Make a picture with AI', 'songpress' ) . '</button></div>';
		$o .= '<div class="spa-ai" id="spa-ai" hidden><div class="spa-aikey" id="spa-aikey"><label class="spe-field"><span>' . esc_html__( 'Your xAI key (kept on this site, never shown again)', 'songpress' ) . '</span><input type="password" id="spa-key" autocomplete="off" placeholder="xai-…"></label><button type="button" class="spe-btn" id="spa-keysave">' . esc_html__( 'Save key', 'songpress' ) . '</button> <button type="button" class="spe-btn danger" id="spa-keyclear">' . esc_html__( 'Remove key', 'songpress' ) . '</button></div><label class="spe-field"><span>' . esc_html__( 'Describe the picture', 'songpress' ) . '</span><textarea id="spa-prompt" rows="3"></textarea></label><label class="spe-check"><input type="checkbox" id="spa-aicover" checked> ' . esc_html__( 'Album cover (square, no lettering)', 'songpress' ) . '</label> <button type="button" class="spe-save" id="spa-aigo">' . esc_html__( 'Make four', 'songpress' ) . '</button><p class="spe-help" id="spa-aimsg"></p><div class="spa-aigrid" id="spa-aigrid"></div></div>';
		$o .= '<div class="spa-main"><div class="spa-stage"><div class="spa-square" id="spa-square"><canvas id="spa-canvas" width="1000" height="1000"></canvas><div id="spa-sel" hidden><i id="spa-handle"></i></div></div><p class="spe-help" id="spa-msg"></p></div>';
		$o .= '<div class="spa-side"><div class="spa-add"><button type="button" class="spe-btn" data-add="text">+ ' . esc_html__( 'Text', 'songpress' ) . '</button><button type="button" class="spe-btn" data-add="rect">+ ' . esc_html__( 'Box', 'songpress' ) . '</button><button type="button" class="spe-btn" data-add="ellipse">+ ' . esc_html__( 'Circle', 'songpress' ) . '</button><label class="spe-btn">+ ' . esc_html__( 'Picture', 'songpress' ) . '<input type="file" id="spa-file" accept="image/*" hidden></label></div><ul id="spa-layers" class="spa-layers"></ul><div id="spa-props" class="spa-props"></div></div></div>';
		$o .= '<div class="spa-actions"><button type="button" class="spe-save" id="spa-save">' . esc_html__( 'Save project', 'songpress' ) . '</button> <button type="button" class="spe-btn" id="spa-cover">' . esc_html__( 'Save and make cover', 'songpress' ) . '</button> <button type="button" class="spe-btn" id="spa-down">' . esc_html__( 'Download PNG', 'songpress' ) . '</button>';
		if ( $p ) {
			$o .= ' <form method="post" class="spa-del" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_art_delete', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_art_delete"><input type="hidden" name="art_id" value="' . (int) $p->id . '"><input type="hidden" name="sp_return" value="' . esc_attr( Songpress_Editor::list_url() ) . '"><button class="spe-btn danger" type="submit">' . esc_html__( 'Delete project', 'songpress' ) . '</button></form>';
		}
		$o .= '</div><script type="application/json" id="spa-cfg">' . wp_json_encode( $cfg, JSON_HEX_TAG | JSON_HEX_AMP ) . '</script><script>' . self::js() . '</script></div>';
		return $o;
	}

	private static function js() {
		return <<<'JS'
(function(){
var C=JSON.parse(document.getElementById('spa-cfg').textContent);
var L=C.layers,sel=L.length>1?L.length-1:0,id=C.id,imgs={},REF=400,dirty=false;
var cv=document.getElementById('spa-canvas'),sq=document.getElementById('spa-square'),box=document.getElementById('spa-sel'),hd=document.getElementById('spa-handle'),msg=document.getElementById('spa-msg');
var SYS=/^(sans-serif|serif|system-ui|monospace|cursive|fantasy|impact|arial|helvetica|georgia|times new roman|verdana|courier new|tahoma|trebuchet ms)$/i;
function $(i){return document.getElementById(i)}
$('spa-name').value=C.name||'';$('spa-serial').value=C.serial||'';
function say(t){msg.textContent=t||''}
function esc(s){return String(s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function fontCss(n){n=n||'sans-serif';return SYS.test(n)&&/^(sans-serif|serif|system-ui|monospace|cursive|fantasy)$/i.test(n)?n:'"'+n.replace(/"/g,'')+'"'}
var fontsAsked={};
function needFonts(){L.forEach(function(l){if(l.type!=='text'||!l.font||SYS.test(l.font)||fontsAsked[l.font])return;fontsAsked[l.font]=1;var k=document.createElement('link');k.rel='stylesheet';k.href='https://fonts.googleapis.com/css2?family='+encodeURIComponent(l.font).replace(/%20/g,'+')+'&display=swap';document.head.appendChild(k);if(document.fonts&&document.fonts.load){document.fonts.load('16px "'+l.font+'"').then(function(){draw()},function(){})}})}
function image(src,cb){if(imgs[src]){return imgs[src]}var im=new Image();im.crossOrigin='anonymous';imgs[src]={img:im,ok:false};im.onload=function(){imgs[src].ok=true;cb&&cb()};im.onerror=function(){var i2=new Image();i2.onload=function(){imgs[src]={img:i2,ok:true,tainted:true};cb&&cb()};i2.src=src};im.src=src;return imgs[src]}
function paint(ctx,S){
 var px=S/REF;
 L.forEach(function(l){
  if(!l.visible)return;var x=l.x/100*S,y=l.y/100*S,w=l.w/100*S,h=l.h/100*S;
  ctx.save();ctx.globalAlpha=l.opacity;
  if(l.rotate){ctx.translate(x+w/2,y+h/2);ctx.rotate(l.rotate*Math.PI/180);ctx.translate(-(x+w/2),-(y+h/2))}
  if(l.type==='canvas'){ctx.fillStyle=l.color||'#000';ctx.fillRect(0,0,S,S)}
  else if(l.type==='image'&&l._bm){ctx.drawImage(l._bm,0,0,l._bm.width,l._bm.height,x,y,w,h);if(l._st){ctx.globalAlpha=l.opacity*l._sa;ctx.drawImage(l._st,0,0,l._bm.width,l._bm.height,x,y,w,h)}}
  else if(l.type==='image'&&l.src){var r=image(l.src,draw);if(r.ok){var n=r.img,c=l.crop;ctx.drawImage(n,c.x*n.naturalWidth,c.y*n.naturalHeight,c.w*n.naturalWidth,c.h*n.naturalHeight,x,y,w,h)}}
  else if(l.type==='shape'){ctx.fillStyle=l.color||'#fff';if(l.shape==='ellipse'){ctx.beginPath();ctx.ellipse(x+w/2,y+h/2,Math.max(1,w/2),Math.max(1,h/2),0,0,Math.PI*2);ctx.fill()}else{ctx.fillRect(x,y,w,h)}}
  else if(l.type==='text'){
   ctx.fillStyle=l.color||'#fff';ctx.textAlign=l.align;ctx.textBaseline=l.valign==='top'?'top':(l.valign==='bottom'?'bottom':'middle');
   var fs=Math.max(8,l.size*px);ctx.font=(l.italic?'italic ':'')+(l.bold?'700 ':'400 ')+fs+'px '+fontCss(l.font);
   if(l.shadow){ctx.shadowColor='rgba(0,0,0,'+(0.35+l.shadow*0.55)+')';ctx.shadowBlur=(2+l.shadow*14)*px;ctx.shadowOffsetY=(1+l.shadow*3)*px}
   var t=l.caps?String(l.text).toUpperCase():String(l.text),lines=t.split('\n'),step=fs*1.15,tx=l.align==='left'?x:(l.align==='right'?x+w:x+w/2);
   var total=step*lines.length,top=l.valign==='top'?y:(l.valign==='bottom'?y+h-total:y+(h-total)/2);
   if(l.flow==='stack'){var colW=fs*1.15,tw=Math.max(colW,lines.length*colW),ox=l.align==='left'?x+colW/2:(l.align==='right'?x+w-tw+colW/2:x+(w-tw)/2+colW/2);ctx.textAlign='center';ctx.textBaseline='middle';
    lines.forEach(function(col,ci){var chars=Array.from(col),th=Math.max(step,chars.length*step),sy=l.valign==='top'?y+step/2:(l.valign==='bottom'?y+h-th+step/2:y+(h-th)/2+step/2);chars.forEach(function(ch,n){ctx.fillText(ch,ox+ci*colW,sy+n*step)})})}
   else if(l.flow==='turn'){ctx.translate(x+w/2,y+h/2);ctx.rotate(Math.PI/2);ctx.textAlign=l.align;ctx.textBaseline='middle';ctx.fillText(t,0,0)}
   else lines.forEach(function(s,i){var ty=l.valign==='top'?top+i*step:(l.valign==='bottom'?top+i*step+step:top+i*step+step/2);ctx.fillText(s,tx,ty)});
  }
  ctx.restore();
 });
}
function draw(){var ctx=cv.getContext('2d');ctx.clearRect(0,0,1000,1000);paint(ctx,1000);place()}
function place(){var l=L[sel];if(!l||l.type==='canvas'){box.hidden=true;return}box.hidden=false;box.style.left=l.x+'%';box.style.top=l.y+'%';box.style.width=l.w+'%';box.style.height=l.h+'%';box.style.transform=l.rotate?'rotate('+l.rotate+'deg)':''}
function rail(){
 var ul=$('spa-layers');ul.innerHTML='';
 for(var i=L.length-1;i>=0;i--){(function(i){var l=L[i],li=document.createElement('li');li.className=i===sel?'on':'';
  li.innerHTML='<button type="button" class="spa-eye" title="Show or hide">'+(l.visible?'●':'○')+'</button><span class="spa-nm">'+esc(l.type==='text'&&l.text?l.text.split('\n')[0]:l.name||l.type)+'</span><button type="button" data-a="up" title="Forward">↑</button><button type="button" data-a="dn" title="Back">↓</button><button type="button" data-a="dup" title="Duplicate">⧉</button><button type="button" data-a="del" title="Delete">✕</button>';
  li.onclick=function(e){var a=e.target.getAttribute('data-a');
   if(e.target.classList.contains('spa-eye')){l.visible=l.visible?0:1;dirty=true}
   else if(a==='up'&&i<L.length-1){L.splice(i+1,0,L.splice(i,1)[0]);sel=i+1;dirty=true}
   else if(a==='dn'&&i>0){L.splice(i-1,0,L.splice(i,1)[0]);sel=i-1;dirty=true}
   else if(a==='dup'){var c=clone(l);c.x+=3;c.y+=3;L.splice(i+1,0,c);sel=i+1;dirty=true}
   else if(a==='del'){if(L.length>0){L.splice(i,1);sel=Math.max(0,Math.min(sel,L.length-1));dirty=true}}
   else{sel=i}
   all()};
  ul.appendChild(li)})(i)}
}
function fld(label,key,type,extra){var l=L[sel];return '<label class="spe-field"><span>'+label+'</span><input data-k="'+key+'" type="'+(type||'number')+'" '+(extra||'')+' value="'+esc(l[key]==null?'':l[key])+'"></label>'}
function props(){
 var l=L[sel],p=$('spa-props');if(!l){p.innerHTML='';return}
 var h='';
 if(l.type==='canvas'){h=fld('Canvas colour','color','color')}
 else{
  h='<div class="spa-grid">'+fld('X %','x','number','step="0.5"')+fld('Y %','y','number','step="0.5"')+fld('Width %','w','number','step="0.5"')+fld('Height %','h','number','step="0.5"')+fld('Opacity','opacity','number','min="0" max="1" step="0.05"')+fld('Rotate °','rotate','number','step="1"')+'</div>';
  if(l.type==='text'){h+='<label class="spe-field"><span>Text</span><textarea data-k="text" rows="3">'+esc(l.text)+'</textarea></label><div class="spa-grid">'+fld('Font','font','text','list="spa-fonts" placeholder="Impact, Rubik Dirt…"')+fld('Size','size','number','min="4" step="1"')+fld('Colour','color','color')+fld('Shadow','shadow','number','min="0" max="1" step="0.1"')+'</div><div class="spa-grid"><label class="spe-field"><span>Align</span><select data-k="align"><option>left</option><option>center</option><option>right</option></select></label><label class="spe-field"><span>Direction</span><select data-k="flow"><option value="">across</option><option value="stack">stacked letters</option><option value="turn">turned</option></select></label><label class="spe-field"><span>Vertical</span><select data-k="valign"><option>top</option><option>middle</option><option>bottom</option></select></label></div><p class="spa-chk"><label><input type="checkbox" data-k="bold"> Bold</label> <label><input type="checkbox" data-k="italic"> Italic</label> <label><input type="checkbox" data-k="caps"> Capitals</label></p><datalist id="spa-fonts"><option>Impact<option>Arial<option>Georgia<option>Rubik Dirt<option>Playfair Display<option>Bebas Neue<option>Oswald<option>Pacifico<option>Lobster<option>Special Elite<option>Permanent Marker</datalist>'}
  if(l.type==='shape'){h+=fld('Colour','color','color')}
  if(l.type==='image'){h+='<p class="spe-help">Picture layers are stretched to the box. Crop is kept from the old studio.</p>'}
 }
 p.innerHTML=h;
 p.querySelectorAll('[data-k]').forEach(function(e){var k=e.getAttribute('data-k');if(e.type==='checkbox'){e.checked=!!l[k]}else if(e.tagName==='SELECT'){e.value=l[k]}
  e.addEventListener('input',function(){var v=e.type==='checkbox'?(e.checked?1:0):(e.type==='number'?parseFloat(e.value):e.value);if(e.type==='number'&&isNaN(v))return;l[k]=v;dirty=true;if(k==='font')needFonts();draw();var nm=document.querySelector('#spa-layers li.on .spa-nm');if(k==='text'&&nm)nm.textContent=String(v).split('\n')[0]})});
}
function all(){draw();rail();props();needFonts()}
document.querySelectorAll('[data-add]').forEach(function(b){b.onclick=function(){var t=b.getAttribute('data-add');
 var n=t==='text'?{type:'text',name:'Text',x:10,y:40,w:80,h:18,text:'Your words',font:'Impact',size:60,color:'#ffffff',align:'center',valign:'middle'}:{type:'shape',name:t==='rect'?'Box':'Circle',x:30,y:30,w:40,h:40,color:'#f5a623',shape:t==='ellipse'?'ellipse':'rect'};
 L.push(Object.assign({src:'',crop:{x:0,y:0,w:1,h:1},visible:1,opacity:1,rotate:0,text:'',font:'',size:32,color:'#ffffff',bold:0,italic:0,caps:0,align:'center',valign:'middle',shadow:0,shape:'rect'},n));sel=L.length-1;dirty=true;all()}});
$('spa-file').onchange=function(e){var f=e.target.files[0];if(!f)return;say('Uploading…');var fd=new FormData();fd.append('action','songpress_upload');fd.append('nonce',C.upload);fd.append('file',f);
 fetch(C.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){if(!j.success){say((j.data&&j.data.message)||'Upload failed');return}
  var im=new Image();im.onload=function(){var ar=im.naturalWidth/im.naturalHeight,w=50,h=w/ar;L.push({type:'image',name:f.name.replace(/\.[^.]+$/,''),src:j.data.url,x:25,y:25,w:w,h:h,crop:{x:0,y:0,w:1,h:1},visible:1,opacity:1,rotate:0,text:'',font:'',size:32,color:'#ffffff',bold:0,italic:0,caps:0,align:'center',valign:'middle',shadow:0,shape:'rect'});sel=L.length-1;dirty=true;say('');all()};im.src=j.data.url}).catch(function(){say('Upload failed')});e.target.value=''};

/* draw tools */
function clone(l){var c=JSON.parse(JSON.stringify(l,function(k,v){return k.charAt(0)==='_'?undefined:v}));if(l._bm){c._bm=cvCopy(l._bm);c._dirty=true;c.src=''}return c}
function cvCopy(b){var c=document.createElement('canvas');c.width=b.width;c.height=b.height;c.getContext('2d').drawImage(b,0,0);return c}
var tool='move',undos=[],stroke=null,BM=1600;
function plain(l){return l&&l.type==='image'&&!l.rotate&&l.crop.x===0&&l.crop.y===0&&l.crop.w===1&&l.crop.h===1}
function mkBitmap(l,done){
 if(l._bm){done(true);return}
 if(!l.src){var c=document.createElement('canvas');c.width=c.height=BM;l._bm=c;l._dirty=true;done(true);return}
 var r=image(l.src,function(){});var go=function(){if(r.tainted){say('That picture is hosted elsewhere, so it cannot be drawn on. Upload it here first.');done(false);return}
  var n=r.img,k=Math.min(1,BM/Math.max(n.naturalWidth,n.naturalHeight)),c=document.createElement('canvas');c.width=Math.max(1,Math.round(n.naturalWidth*k));c.height=Math.max(1,Math.round(n.naturalHeight*k));c.getContext('2d').drawImage(n,0,0,c.width,c.height);l._bm=c;done(true)};
 if(r.ok)go();else{var t=setInterval(function(){if(imgs[l.src]&&imgs[l.src].ok){clearInterval(t);r=imgs[l.src];go()}},100);setTimeout(function(){clearInterval(t)},8000)}}
function newDrawing(){L.push({type:'image',name:'Drawing',src:'',x:0,y:0,w:100,h:100,crop:{x:0,y:0,w:1,h:1},visible:1,opacity:1,rotate:0,text:'',font:'',size:32,color:'#ffffff',bold:0,italic:0,caps:0,align:'center',valign:'middle',shadow:0,shape:'rect'});sel=L.length-1;mkBitmap(L[sel],function(){});dirty=true;all();return L[sel]}
function setTool(t){tool=t;document.querySelectorAll('[data-tool]').forEach(function(b){b.classList.toggle('on',b.getAttribute('data-tool')===t)});sq.style.touchAction=t==='move'?'':'none';sq.style.cursor=t==='move'?'':'crosshair';if(t!=='marquee'&&t!=='move'&&msel&&false)msel=null;
 if(t!=='move'){var l=L[sel];if(!l||l.type!=='image'){newDrawing()}else if(!plain(l)){say('Set Rotate to 0 on this picture to draw on it, or press + Drawing.')}else{say('')}}else say('')}
document.querySelectorAll('[data-tool]').forEach(function(b){b.onclick=function(){setTool(b.getAttribute('data-tool'))}});
$('spa-newdraw').onclick=function(){newDrawing();if(tool==='move')setTool('brush')};
function lp(e,l){var r=sq.getBoundingClientRect(),px=(e.clientX-r.left)/r.width*100,py=(e.clientY-r.top)/r.height*100;return{x:(px-l.x)/l.w*l._bm.width,y:(py-l.y)/l.h*l._bm.height,k:l._bm.width/(l.w/100*1000)}}
function seg(c,a,b,wd){c.lineWidth=wd;c.lineCap=c.lineJoin='round';c.beginPath();c.moveTo(a.x,a.y);c.lineTo(b.x+0.01,b.y);c.stroke()}
function drawStart(e){if(tool==='eyedrop'){eyedrop(e);return false}var l=L[sel];if(!l||l.type!=='image'||!plain(l)){say('Pick a picture or press + Drawing first.');return false}
 if(!l._bm){mkBitmap(l,function(ok){if(ok)all()});return true}
 if(tool==='bucket'){flood(l,lp(e,l));return false}
 if(SHAPET[tool]){var a=lp(e,l);undos.push({l:l,c:cvCopy(l._bm),d:!!l._dirty});if(undos.length>6)undos.shift();stroke={l:l,t:tool,a:a,fl:parseInt($('spa-bflow').value,10)/100};if(tool!=='marquee'){var st=document.createElement('canvas');st.width=l._bm.width;st.height=l._bm.height;l._st=st;l._sa=stroke.fl}else msel=null;e.preventDefault();return true}
 undos.push({l:l,c:cvCopy(l._bm),d:!!l._dirty});if(undos.length>6)undos.shift();
 var er=tool==='eraser',fl=parseInt($('spa-bflow').value,10)/100;
 stroke={l:l,er:er,fl:fl,last:lp(e,l),pen:e.pointerType==='pen'};
 if(!er){var st=document.createElement('canvas');st.width=l._bm.width;st.height=l._bm.height;l._st=st;l._sa=fl}
 drawMove(e,true);e.preventDefault();return true}
function drawMove(e,first){if(!stroke)return;if(stroke.t){shapePreview(stroke.a,lp(e,stroke.l));return}var l=stroke.l,p=lp(e,l),pr=stroke.pen&&e.pressure?Math.max(0.15,e.pressure*1.6):1,wd=Math.max(1,parseInt($('spa-bsize').value,10)*p.k*pr),c;
 if(stroke.er){c=l._bm.getContext('2d');c.save();clipTo(c,l);c.globalCompositeOperation='destination-out';c.globalAlpha=stroke.fl;c.strokeStyle='#000';seg(c,stroke.last,p,wd);c.restore()}
 else{c=l._st.getContext('2d');c.save();clipTo(c,l);c.strokeStyle=$('spa-bcolor').value;seg(c,stroke.last,p,wd);c.restore()}
 stroke.last=p;l._dirty=true;dirty=true;draw()}
function drawEnd(){if(!stroke)return;var l=stroke.l;if(stroke.t==='marquee'){stroke=null;if(msel&&(msel.w<3||msel.h<3)){msel=null}showSel();return}if(l._st){if(stroke.t)l._dirty=true;var c=l._bm.getContext('2d');c.save();c.globalAlpha=stroke.fl;clipTo(c,l);c.drawImage(l._st,0,0);c.restore();l._st=null}stroke=null;draw()}
$('spa-undo').onclick=function(){var u=undos.pop();if(!u){say('Nothing to undo.');return}if(L.indexOf(u.l)<0){return}var c=u.l._bm.getContext('2d');c.save();c.globalCompositeOperation='copy';c.drawImage(u.c,0,0);c.restore();u.l._dirty=true;draw()};

/* more tools: fill, gradient, shapes, select, eyedropper */
var DRAWT={brush:1,eraser:1},SHAPET={gradient:1,rect:1,ellipse:1,marquee:1},msel=null,mbox=document.createElement('div');
mbox.style.cssText='position:absolute;border:1px dashed #fff;outline:1px dashed #000;pointer-events:none;display:none';sq.appendChild(mbox);
function showSel(){var l=msel&&msel.l;if(!l||L.indexOf(l)<0||!l._bm){mbox.style.display='none';return}mbox.style.display='block';mbox.style.left=(l.x+msel.x/l._bm.width*l.w)+'%';mbox.style.top=(l.y+msel.y/l._bm.height*l.h)+'%';mbox.style.width=(msel.w/l._bm.width*l.w)+'%';mbox.style.height=(msel.h/l._bm.height*l.h)+'%'}
function clipTo(c,l){if(msel&&msel.l===l){c.beginPath();c.rect(msel.x,msel.y,msel.w,msel.h);c.clip()}}
function hex(h){return[parseInt(h.substr(1,2),16),parseInt(h.substr(3,2),16),parseInt(h.substr(5,2),16)]}
function flood(l,pt){var b=l._bm,c=b.getContext('2d'),W=b.width,H=b.height,sx=Math.floor(pt.x),sy=Math.floor(pt.y);if(sx<0||sy<0||sx>=W||sy>=H)return;
 var x0=0,y0=0,x1=W,y1=H;if(msel&&msel.l===l){x0=Math.max(0,Math.floor(msel.x));y0=Math.max(0,Math.floor(msel.y));x1=Math.min(W,Math.ceil(msel.x+msel.w));y1=Math.min(H,Math.ceil(msel.y+msel.h));if(sx<x0||sx>=x1||sy<y0||sy>=y1)return}
 undos.push({l:l,c:cvCopy(b),d:!!l._dirty});if(undos.length>6)undos.shift();
 var im=c.getImageData(0,0,W,H),d=im.data,i0=(sy*W+sx)*4,t=[d[i0],d[i0+1],d[i0+2],d[i0+3]],col=hex($('spa-bcolor').value),a=parseInt($('spa-bflow').value,10)/100,TOL=40;
 function same(i){return Math.abs(d[i]-t[0])+Math.abs(d[i+1]-t[1])+Math.abs(d[i+2]-t[2])+Math.abs(d[i+3]-t[3])<=TOL*2}
 var seen=new Uint8Array(W*H),st=[sx,sy];
 while(st.length){var y=st.pop(),x=st.pop();if(x<x0||x>=x1||y<y0||y>=y1||seen[y*W+x])continue;var i=(y*W+x)*4;if(!same(i))continue;seen[y*W+x]=1;
  d[i]=Math.round(d[i]*(1-a)+col[0]*a);d[i+1]=Math.round(d[i+1]*(1-a)+col[1]*a);d[i+2]=Math.round(d[i+2]*(1-a)+col[2]*a);d[i+3]=Math.round(d[i+3]*(1-a)+255*a);
  st.push(x+1,y,x-1,y,x,y+1,x,y-1)}
 c.putImageData(im,0,0);l._dirty=true;dirty=true;draw()}
function eyedrop(e){var r=sq.getBoundingClientRect(),x=Math.floor((e.clientX-r.left)/r.width*1000),y=Math.floor((e.clientY-r.top)/r.height*1000);if(x<0||y<0||x>999||y>999)return;try{var d=cv.getContext('2d').getImageData(x,y,1,1).data;$('spa-bcolor').value='#'+[d[0],d[1],d[2]].map(function(v){return('0'+v.toString(16)).slice(-2)}).join('');say('Colour picked.')}catch(err){say('Could not read that colour.')}}
function shapePreview(a,b){var s=stroke,l=s.l;if(s.t==='marquee'){msel={l:l,x:Math.min(a.x,b.x),y:Math.min(a.y,b.y),w:Math.abs(b.x-a.x),h:Math.abs(b.y-a.y)};showSel();return}
 var c=l._st.getContext('2d');c.clearRect(0,0,l._st.width,l._st.height);c.save();clipTo(c,l);var col=$('spa-bcolor').value;
 if(s.t==='gradient'){var g=c.createLinearGradient(a.x,a.y,b.x,b.y);g.addColorStop(0,col);g.addColorStop(1,$('spa-bcolor2').value);c.fillStyle=g;c.fillRect(0,0,l._st.width,l._st.height)}
 else{var x=Math.min(a.x,b.x),y=Math.min(a.y,b.y),w=Math.abs(b.x-a.x),h=Math.abs(b.y-a.y);c.beginPath();if(s.t==='rect')c.rect(x,y,w,h);else c.ellipse(x+w/2,y+h/2,Math.max(0.5,w/2),Math.max(0.5,h/2),0,0,Math.PI*2);
  if($('spa-outline').checked){c.strokeStyle=col;c.lineWidth=Math.max(1,parseInt($('spa-bsize').value,10)*l._bm.width/(l.w/100*1000)/4);c.stroke()}else{c.fillStyle=col;c.fill()}}
 c.restore();draw()}
document.addEventListener('keydown',function(e){if((e.key==='Delete'||e.key==='Backspace')&&msel&&tool==='marquee'&&!/INPUT|TEXTAREA|SELECT/.test((e.target.tagName||''))){var l=msel.l;if(!l._bm)return;undos.push({l:l,c:cvCopy(l._bm),d:!!l._dirty});var c=l._bm.getContext('2d');c.clearRect(msel.x,msel.y,msel.w,msel.h);l._dirty=true;dirty=true;draw();e.preventDefault()}});
/* AI pictures */
var ai=$('spa-ai');$('spa-aibtn').onclick=function(){ai.hidden=!ai.hidden;$('spa-aikey').hidden=!!C.ai;if(!$('spa-prompt').value)$('spa-prompt').value=C.prompt||$('spa-name').value||''};
function aimsg(t){$('spa-aimsg').textContent=t||''}
function post(action,data){var fd=new FormData();fd.append('action',action);fd.append('nonce',C.nonce);for(var k in data)fd.append(k,data[k]);return fetch(C.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()})}
$('spa-keysave').onclick=function(){var v=$('spa-key').value.trim();if(!v){aimsg('Paste your key first.');return}post('songpress_art_key',{key:v}).then(function(j){if(j.success){C.ai=true;$('spa-key').value='';$('spa-aikey').hidden=true;aimsg('Key saved.')}else aimsg((j.data&&j.data.message)||'Could not save the key')})};
$('spa-keyclear').onclick=function(){post('songpress_art_key',{key:''}).then(function(){C.ai=false;aimsg('Key removed.')})};
$('spa-aigo').onclick=function(){if(!C.ai){$('spa-aikey').hidden=false;aimsg('Add your xAI key first.');return}var g=$('spa-aigrid');g.innerHTML='';aimsg('Making four pictures… this can take a minute.');$('spa-aigo').disabled=true;
 post('songpress_art_generate',{prompt:$('spa-prompt').value,cover:$('spa-aicover').checked?1:0}).then(function(j){$('spa-aigo').disabled=false;if(!j.success){aimsg((j.data&&j.data.message)||'Nothing came back');return}aimsg('Click one to add it to the art (it is also kept in your Media Library).');
  j.data.images.forEach(function(u){var b=document.createElement('button');b.type='button';b.className='spa-aipic';var im=document.createElement('img');im.src=u;im.alt='';b.appendChild(im);
   b.onclick=function(){b.disabled=true;aimsg('Adding…');post('songpress_art_claim',{src:u}).then(function(r){if(!r.success){b.disabled=false;aimsg((r.data&&r.data.message)||'Could not add it');return}aimsg('Added.');
    var pic=new Image();pic.onload=function(){L.push({type:'image',name:'AI picture',src:r.data.url,x:0,y:0,w:100,h:100*pic.naturalHeight/pic.naturalWidth,crop:{x:0,y:0,w:1,h:1},visible:1,opacity:1,rotate:0,text:'',font:'',size:32,color:'#ffffff',bold:0,italic:0,caps:0,align:'center',valign:'middle',shadow:0,shape:'rect'});sel=L.length-1;dirty=true;all()};pic.src=r.data.url})};
   g.appendChild(b)})}).catch(function(){$('spa-aigo').disabled=false;aimsg('The request failed. Try again.')})};
/* drag to move, corner to resize */
var drag=null;
function pt(e){var r=sq.getBoundingClientRect();return{x:(e.clientX-r.left)/r.width*100,y:(e.clientY-r.top)/r.height*100}}
sq.addEventListener('pointerdown',function(e){if(tool!=='move'){if(drawStart(e)){sq.setPointerCapture(e.pointerId)}return}var p=pt(e);
 if(e.target===hd){drag={m:'size',p:p,l:L[sel],o:{w:L[sel].w,h:L[sel].h}};sq.setPointerCapture(e.pointerId);e.preventDefault();return}
 var hit=-1;for(var i=L.length-1;i>=0;i--){var l=L[i];if(l.type==='canvas'||!l.visible)continue;if(p.x>=l.x&&p.x<=l.x+l.w&&p.y>=l.y&&p.y<=l.y+l.h){hit=i;break}}
 if(hit>=0){sel=hit;drag={m:'move',p:p,l:L[sel],o:{x:L[sel].x,y:L[sel].y}};sq.setPointerCapture(e.pointerId);rail();props();draw()}
});
sq.addEventListener('pointermove',function(e){if(stroke){drawMove(e);return}if(!drag)return;var p=pt(e),dx=p.x-drag.p.x,dy=p.y-drag.p.y,l=drag.l;
 if(drag.m==='move'){l.x=Math.round((drag.o.x+dx)*10)/10;l.y=Math.round((drag.o.y+dy)*10)/10}else{l.w=Math.max(1,Math.round((drag.o.w+dx)*10)/10);l.h=Math.max(1,Math.round((drag.o.h+dy)*10)/10)}
 dirty=true;draw()});
function end(){if(stroke){drawEnd();return}if(drag){drag=null;props()}}sq.addEventListener('pointerup',end);sq.addEventListener('pointercancel',end);
/* output */
function render(size){var c=document.createElement('canvas');c.width=c.height=size;paint(c.getContext('2d'),size);return c}
function blob(size){return new Promise(function(res,rej){var c=render(size);try{c.toBlob(function(b){b?res(b):rej(new Error('x'))},'image/png')}catch(e){rej(e)}})}
function save(cover){
 var fd=new FormData();fd.append('action','songpress_art_save');fd.append('nonce',C.nonce);fd.append('id',id);fd.append('name',$('spa-name').value);fd.append('serial',$('spa-serial').value);fd.append('layers',JSON.stringify(L,function(k,v){return k.charAt(0)==='_'?undefined:v}));
 say(cover?'Rendering the cover…':'Saving…');
 var go=function(b){if(b){fd.append('file',b,'cover-'+Date.now()+'.png');if(cover)fd.append('set_cover','1')}
  fetch(C.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){if(!j.success){say((j.data&&j.data.message)||'Could not save');return}
   id=j.data.id;dirty=false;say(cover?(j.data.cover?'Saved, and set as the song\'s cover.':'Saved. Add the song\'s serial to set it as the cover.'):'Saved.');if(!C.id){C.id=id;history.replaceState(null,'',j.data.url)}}).catch(function(){say('Could not save')})};
 var w=Math.min(3000,parseInt($('spa-size').value,10)||3000);
 blob(cover?w:1000).then(go,function(){say('A picture on this project could not be copied into the cover (it is hosted elsewhere). Save the project without the cover, or upload that picture here.')});
}
function flush(){var jobs=L.filter(function(l){return l._bm&&l._dirty});if(!jobs.length)return Promise.resolve();say('Saving your drawing…');
 return jobs.reduce(function(p,l){return p.then(function(){return new Promise(function(res,rej){try{l._bm.toBlob(function(b){if(!b){rej(new Error('x'));return}var fd=new FormData();fd.append('action','songpress_upload');fd.append('nonce',C.upload);fd.append('file',b,'drawing-'+Date.now()+'.png');
  fetch(C.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){if(!j.success){rej(new Error((j.data&&j.data.message)||'Upload failed'));return}l.src=j.data.url;l._dirty=false;res()},rej)},'image/png')}catch(e){rej(e)}})})},Promise.resolve())}
function go(c){flush().then(function(){save(c)},function(e){say('Could not save the drawing: '+(e&&e.message||''))})}
$('spa-save').onclick=function(){go(false)};
$('spa-cover').onclick=function(){go(true)};
$('spa-down').onclick=function(){blob(parseInt($('spa-size').value,10)||3000).then(function(b){var a=document.createElement('a');a.href=URL.createObjectURL(b);a.download=(($('spa-name').value||'cover').replace(/[^\w.-]+/g,'-'))+'.png';document.body.appendChild(a);a.click();setTimeout(function(){URL.revokeObjectURL(a.href);a.remove()},500)},function(){say('A picture on this project is hosted elsewhere and cannot be exported.')})};
window.addEventListener('beforeunload',function(e){if(dirty){e.preventDefault();e.returnValue=''}});
all();
})();
JS;
	}
}
