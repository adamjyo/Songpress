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
		add_action( 'wp_ajax_songpress_art_lib', array( __CLASS__, 'ajax_lib' ) );
		add_action( 'wp_ajax_songpress_art_lookup', array( __CLASS__, 'ajax_lookup' ) );
		add_action( 'wp_ajax_songpress_art_rename', array( __CLASS__, 'ajax_rename' ) );
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
			'flip'    => empty( $get( 'flip', 0 ) ) ? 0 : 1,
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

	/**
	 * Bring the old studio's saved layers (band tags, album labels, text) across as Elements, so they can be added to any art
	 * and edited. Ones already brought across (by their old id) are left alone. Returns how many were added.
	 */
	public static function import_layers_pdo( PDO $pdo ) {
		$q = $pdo->prepare( "SELECT 1 FROM sqlite_master WHERE type='table' AND name = 'art_layers'" );
		$q->execute();
		if ( ! $q->fetchColumn() ) {
			return 0;
		}
		$lib  = self::lib_get();
		$have = array();
		foreach ( $lib['el'] as $it ) {
			if ( ! empty( $it['key'] ) ) {
				$have[ $it['key'] ] = true;
			}
		}
		$n = 0;
		foreach ( $pdo->query( 'SELECT id, name, kind, src, meta_json FROM art_layers ORDER BY id' ) as $r ) {
			$key = 'old:' . (int) $r['id'];
			if ( isset( $have[ $key ] ) ) {
				continue;
			}
			$meta = json_decode( (string) $r['meta_json'], true );
			$item = self::norm_lib_item( 'el', array( 'key' => $key, 'name' => (string) $r['name'], 'layer' => array( 'type' => 'text' === $r['kind'] ? 'text' : 'image', 'name' => (string) $r['name'], 'src' => (string) $r['src'], 'meta' => is_array( $meta ) ? $meta : array() ) ) );
			if ( ! $item ) {
				continue;
			}
			$item['id'] = strtolower( wp_generate_password( 10, false ) );
			$lib['el'][] = $item;
			$n++;
		}
		if ( $n ) {
			update_option( 'songpress_art_lib', array( 'el' => array_slice( $lib['el'], 0, self::LIB_MAX ), 'tpl' => $lib['tpl'], 'logo' => $lib['logo'] ), false );
		}
		return $n;
	}

	/** The band logos made for the studio (uploaded here, separate from the pictures on People). Name and address of each. */
	public static function logos() {
		$out = array();
		foreach ( self::lib_get()['logo'] as $it ) {
			if ( ! empty( $it['url'] ) ) {
				$out[] = array( 'name' => (string) ( $it['name'] ?? 'Logo' ), 'url' => (string) $it['url'] );
			}
		}
		return $out;
	}

	/** Bring the old studio's projects across. Projects already here (by their old id) are left alone. */
	public static function import_pdo( PDO $pdo ) {
		global $wpdb;
		self::import_layers_pdo( $pdo );
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
		self::save_thumb( $id, wp_unslash( $_POST['thumb'] ?? '' ) );
		wp_send_json_success( array( 'id' => $id, 'song_id' => $sid, 'preview_url' => $row['preview_url'] ?? '', 'cover' => $cover, 'url' => add_query_arg( array( 'sp_page' => 'art', 'art' => $id ), Songpress_Editor::list_url() ) ) );
	}

	/* ---------- saved elements and templates, project thumbnails, serial lookup ---------- */

	const LIB_MAX = 200;

	/** Pure: keep a saved element or template to what the studio can draw. An element is one layer; a template is a list of layers. */
	public static function norm_lib_item( $kind, $item ) {
		if ( ! is_array( $item ) ) {
			return null;
		}
		$name = mb_substr( sanitize_text_field( (string) ( $item['name'] ?? '' ) ), 0, 80 );
		$id   = preg_match( '/^[a-z0-9]{6,20}$/', (string) ( $item['id'] ?? '' ) ) ? (string) $item['id'] : '';
		$key  = preg_match( '/^[a-z]+:[0-9]{1,10}$/', (string) ( $item['key'] ?? '' ) ) ? (string) $item['key'] : '';
		if ( 'logo' === $kind ) {
			$u = esc_url_raw( (string) ( $item['url'] ?? '' ) );
			return ! preg_match( '#^https?://#i', $u ) ? null : array( 'id' => $id, 'key' => $key, 'name' => '' === $name ? 'Logo' : $name, 'url' => $u );
		}
		if ( 'tpl' === $kind ) {
			$ls = array_values( array_filter( self::norm_layers( $item['layers'] ?? array() ), function ( $l ) {
				return 'canvas' !== $l['type'];
			} ) );
			return $ls ? array( 'id' => $id, 'name' => '' === $name ? 'Template' : $name, 'layers' => $ls ) : null;
		}
		$l = self::norm_layer( $item['layer'] ?? array() );
		if ( ! $l || 'canvas' === $l['type'] || ( 'image' === $l['type'] && '' === $l['src'] ) ) {
			return null;
		}
		return array( 'id' => $id, 'key' => $key, 'name' => '' === $name ? ( '' !== $l['name'] ? $l['name'] : 'Element' ) : $name, 'layer' => $l );
	}

	private static function lib_get() {
		$o = get_option( 'songpress_art_lib', array() );
		$o = is_array( $o ) ? $o : array();
		return array( 'el' => array_values( (array) ( $o['el'] ?? array() ) ), 'tpl' => array_values( (array) ( $o['tpl'] ?? array() ) ), 'logo' => array_values( (array) ( $o['logo'] ?? array() ) ) );
	}

	/** One handler for the two libraries: op list, save, rename, delete; kind el (a layer to reuse) or tpl (an overlay layout to reuse). */
	public static function ajax_lib() {
		check_ajax_referer( 'songpress_art', 'nonce' );
		if ( ! Songpress_Caps::can( 'art' ) ) {
			wp_send_json_error( array( 'message' => __( 'Only Managers can use the Art studio.', 'songpress' ) ), 403 );
		}
		$kind = in_array( $_POST['kind'] ?? '', array( 'tpl', 'logo' ), true ) ? $_POST['kind'] : 'el';
		$op   = sanitize_key( $_POST['op'] ?? 'list' );
		$lib  = self::lib_get();
		$id   = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) ( $_POST['item'] ?? '' ) ) );
		if ( 'save' === $op ) {
			$raw  = json_decode( wp_unslash( $_POST['data'] ?? '' ), true );
			$item = self::norm_lib_item( $kind, $raw );
			if ( ! $item ) {
				wp_send_json_error( array( 'message' => __( 'Nothing to save. Pick a picture, text or shape layer first.', 'songpress' ) ), 400 );
			}
			$item['id'] = strtolower( wp_generate_password( 10, false ) );
			array_unshift( $lib[ $kind ], $item );
			$lib[ $kind ] = array_slice( $lib[ $kind ], 0, self::LIB_MAX );
		} elseif ( 'rename' === $op || 'delete' === $op ) {
			$name = mb_substr( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ), 0, 80 );
			$out  = array();
			foreach ( $lib[ $kind ] as $it ) {
				if ( ( $it['id'] ?? '' ) === $id ) {
					if ( 'delete' === $op ) {
						continue;
					}
					if ( '' !== $name ) {
						$it['name'] = $name;
					}
				}
				$out[] = $it;
			}
			$lib[ $kind ] = $out;
		}
		if ( 'list' !== $op ) {
			update_option( 'songpress_art_lib', $lib, false );
		}
		wp_send_json_success( array( 'el' => $lib['el'], 'tpl' => $lib['tpl'], 'logo' => $lib['logo'] ) );
	}

	/** Rename a project from the list on the left. */
	public static function ajax_rename() {
		check_ajax_referer( 'songpress_art', 'nonce' );
		if ( ! Songpress_Caps::can( 'art' ) ) {
			wp_send_json_error( array( 'message' => __( 'Only Managers can use the Art studio.', 'songpress' ) ), 403 );
		}
		global $wpdb;
		$id   = absint( $_POST['id'] ?? 0 );
		$name = mb_substr( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ), 0, 191 );
		if ( ! $id || '' === $name || ! self::get( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not rename that.', 'songpress' ) ), 400 );
		}
		$wpdb->update( self::t(), array( 'name' => $name ), array( 'id' => $id ) );
		wp_send_json_success( array( 'name' => $name ) );
	}

	/** What the studio needs to know about a serial: its title, band and the cover it has now (shown before the cover is replaced). */
	public static function ajax_lookup() {
		check_ajax_referer( 'songpress_art', 'nonce' );
		if ( ! Songpress_Caps::can( 'art' ) ) {
			wp_send_json_error( array( 'message' => __( 'Only Managers can use the Art studio.', 'songpress' ) ), 403 );
		}
		global $wpdb;
		$ser = Songpress_Journal::serial_in( sanitize_text_field( wp_unslash( $_POST['serial'] ?? '' ) ) );
		$row = '' === $ser ? null : $wpdb->get_row( $wpdb->prepare( 'SELECT id, serial, title, artist, art_id, art_url FROM ' . Songpress_Install::table( 'songs' ) . ' WHERE serial = %s', $ser ) ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'No work has that serial.', 'songpress' ) ), 404 );
		}
		$old = $row->art_id ? (string) wp_get_attachment_image_url( (int) $row->art_id, 'medium' ) : '';
		wp_send_json_success( array( 'serial' => $row->serial, 'title' => $row->title, 'band' => (string) $row->artist, 'old' => '' !== $old ? $old : (string) $row->art_url ) );
	}

	/** A small picture of each project, kept for the list on the left. */
	private static function thumbs() {
		$t = get_option( 'songpress_art_thumbs', array() );
		return is_array( $t ) ? $t : array();
	}

	public static function thumb_for( $id ) {
		$t = self::thumbs();
		return isset( $t[ (int) $id ] ) ? (string) $t[ (int) $id ] : '';
	}

	private static function save_thumb( $id, $data ) {
		if ( ! is_string( $data ) || ! preg_match( '#^data:image/jpeg;base64,[A-Za-z0-9+/=]+$#', $data ) || strlen( $data ) > 40000 ) {
			return;
		}
		$t          = self::thumbs();
		$t[ (int) $id ] = $data;
		update_option( 'songpress_art_thumbs', $t, false );
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
		if ( ! isset( $_GET['art'] ) || 'new' === $_GET['art'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return self::render_editor( 0 );
		}
		return self::render_editor( absint( $_GET['art'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	private static function render_editor( $id ) {
		$p = $id ? self::get( $id ) : null;
		if ( $id && ! $p ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'That art project does not exist.', 'songpress' ) . '</p></div>';
		}
		$serial = '';
		$from   = ( ! $p && isset( $_GET['from'] ) ) ? self::get( absint( $_GET['from'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification
		$sid    = $p ? (int) $p->song_id : ( $from ? (int) $from->song_id : ( isset( $_GET['song'] ) ? absint( $_GET['song'] ) : 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $from && ! empty( $_GET['to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$to_ser = Songpress_Journal::serial_in( sanitize_text_field( wp_unslash( $_GET['to'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$to_id  = '' === $to_ser ? 0 : (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT id FROM ' . Songpress_Install::table( 'songs' ) . ' WHERE serial = %s', $to_ser ) ); // phpcs:ignore WordPress.DB
			if ( $to_id ) {
				$sid = $to_id; // The new art belongs to the work it is being added to.
			}
		}
		if ( $sid && ( $s = Songpress_Songs::get( $sid ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$serial = $s->serial;
		}
		$src    = $p ? $p : $from;
		$layers = $src ? self::norm_layers( json_decode( (string) $src->layers, true ) ) : array(
			array_merge( self::norm_layer( array( 'type' => 'canvas', 'name' => 'Canvas', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100 ) ), array( 'color' => '#14161a' ) ),
		);
		$cfg  = array(
			'id'     => (int) ( $p ? $p->id : 0 ),
			'name'   => $p ? $p->name : ( $from ? ( ( isset( $s ) && $s && (int) $sid !== (int) $from->song_id && '' !== (string) $s->title ) ? $s->title : sprintf( /* translators: %s: art project name */ __( '%s (new)', 'songpress' ), $from->name ) ) : ( $s->title ?? '' ) ),
			'serial' => $serial,
			'layers' => $layers,
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'nonce'  => wp_create_nonce( 'songpress_art' ),
			'upload' => wp_create_nonce( 'songpress_upload' ),
			'list'   => add_query_arg( array( 'sp_page' => 'art' ), Songpress_Editor::list_url() ),
			'ai'     => '' !== self::xai_key(),
			'prompt' => (string) ( $s->art_prompt ?? '' ),
			'logos'  => self::logos(),
		);
		$tr = function ( $t ) {
			return esc_html__( $t, 'songpress' ); // phpcs:ignore WordPress.WP.I18n
		};
		$tool = function ( $k, $label, $svg ) {
			return '<button type="button" data-tool="' . $k . '" title="' . esc_attr( $label ) . '" aria-label="' . esc_attr( $label ) . '"><svg viewBox="0 0 16 16" aria-hidden="true">' . $svg . '</svg></button>';
		};
		$st = 'fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"';
		$o  = '<div class="songpress sp-app sp-editor sp-art"><div class="spa-app" id="spa-app">';
		// left: projects, elements, templates
		$o .= '<aside class="spa-projects"><div class="spa-head"><h3>' . $tr( 'Projects' ) . '</h3><a class="spa-plus" href="' . esc_url( add_query_arg( array( 'sp_page' => 'art', 'art' => 'new' ), Songpress_Editor::list_url() ) ) . '" title="' . esc_attr__( 'New art', 'songpress' ) . '">+</a></div><ul class="spa-plist" id="spa-plist">';
		foreach ( self::all() as $r ) {
			$th = '' !== (string) $r->preview_url ? (string) $r->preview_url : self::thumb_for( $r->id );
			$o .= '<li data-id="' . (int) $r->id . '" data-song="' . (int) $r->song_id . '" data-serial="' . esc_attr( (string) $r->serial ) . '" data-edit="' . ( (int) $r->song_id ? esc_url( Songpress_Editor::edit_url( (int) $r->song_id ) ) : '' ) . '"' . ( $p && (int) $p->id === (int) $r->id ? ' class="on"' : '' ) . '><a href="' . esc_url( add_query_arg( array( 'sp_page' => 'art', 'art' => (int) $r->id ), Songpress_Editor::list_url() ) ) . '"><span class="spa-pt">' . ( '' !== $th ? '<img src="' . esc_url( $th ) . '" alt="" loading="lazy">' : '' ) . '</span><span class="spa-pn">' . esc_html( $r->name ) . ( '' !== (string) $r->serial ? '<small>' . esc_html( $r->serial ) . '</small>' : '' ) . '</span></a></li>';
		}
		$o .= '</ul>';
		$o .= '<div class="spa-head"><h3>' . $tr( 'Elements' ) . '</h3><button type="button" class="spa-plus" id="spa-elsave" title="' . esc_attr__( 'Save the selected layer to reuse on other art', 'songpress' ) . '">+</button></div><div class="spa-els" id="spa-els"></div>';
		$o .= '<div class="spa-head"><h3>' . $tr( 'Band logos' ) . '</h3><label class="spa-plus" title="' . esc_attr__( 'Upload band logos for the art (several at once; you can also drop them on this list)', 'songpress' ) . '">+<input type="file" id="spa-logofile" accept="image/*" multiple hidden></label></div><div class="spa-els spa-logos" id="spa-logos"></div>';
		$o .= '<div class="spa-head"><h3>' . $tr( 'Templates' ) . '</h3><button type="button" class="spa-plus" id="spa-tplsave" title="' . esc_attr__( 'Save the layers on top of the canvas as a template', 'songpress' ) . '">+</button></div><ul class="spa-tpls" id="spa-tpls"></ul></aside>';
		// middle
		$o .= '<div class="spa-mid"><div class="spa-bar"><label class="spe-field"><span>' . $tr( 'Name' ) . '</span><input id="spa-name"></label></div>';
		$o .= '<div class="spa-opts" id="spa-opts"><b id="spa-opt-title">' . $tr( 'Move' ) . '</b><span class="spa-hint" id="spa-hint">' . $tr( 'Drag a layer on the square to move it. The corner handle scales it.' ) . '</span><span class="spa-ink" id="spa-ink"><label class="spa-tl">' . $tr( 'Size' ) . '<input type="range" id="spa-bsize" min="1" max="200" value="24"></label><label class="spa-tl">' . $tr( 'Flow' ) . '<input type="range" id="spa-bflow" min="5" max="100" value="100"></label><input type="color" id="spa-bcolor" value="#ffffff" title="' . esc_attr__( 'Colour', 'songpress' ) . '"><input type="color" id="spa-bcolor2" value="#000000" title="' . esc_attr__( 'Second colour (gradient)', 'songpress' ) . '"><label class="spa-tl"><input type="checkbox" id="spa-outline"> ' . $tr( 'Outline only' ) . '</label></span></div>';
		$o .= '<div class="spa-stage"><div class="spa-tools" id="spa-tools">'
			. $tool( 'move', 'Move', '<path d="M8 1.5v13M1.5 8h13M8 1.5L6 3.5M8 1.5l2 2M8 14.5l-2-2M8 14.5l2-2M1.5 8l2-2M1.5 8l2 2M14.5 8l-2-2M14.5 8l-2 2" ' . $st . '/>' )
			. $tool( 'brush', 'Draw', '<path d="M2 14l4-1 8-8-3-3-8 8z" ' . $st . '/>' )
			. $tool( 'eraser', 'Eraser', '<path d="M3 11l5-7 5 4-4 6H6z" ' . $st . '/>' )
			. $tool( 'bucket', 'Fill', '<path d="M3 8l5-5 5 5-5 5zM13 11c1 1.5 1 2.5 0 3" ' . $st . '/>' )
			. $tool( 'gradient', 'Gradient', '<rect x="2.5" y="2.5" width="11" height="11" ' . $st . '/><path d="M2.5 8h11M2.5 5.5h11M2.5 10.5h11" ' . $st . ' opacity=".5"/>' )
			. $tool( 'rect', 'Rectangle', '<rect x="2.5" y="4.5" width="11" height="7" ' . $st . '/>' )
			. $tool( 'ellipse', 'Ellipse', '<ellipse cx="8" cy="8" rx="5.5" ry="4" ' . $st . '/>' )
			. $tool( 'marquee', 'Select', '<rect x="2.5" y="2.5" width="11" height="11" stroke-dasharray="2 2" ' . $st . '/>' )
			. $tool( 'eyedrop', 'Pick colour', '<path d="M10.2 1.8l4 4-1.2 1.2-1-1-5.6 5.6-2.2.4.4-2.2 5.6-5.6-1-1z" ' . $st . '/>' )
			. '<button type="button" id="spa-addtext" title="' . esc_attr__( 'Add text', 'songpress' ) . '" aria-label="' . esc_attr__( 'Add text', 'songpress' ) . '"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3 3h10M8 3v10" ' . $st . '/></svg></button>'
			. '<label class="spa-upl" title="' . esc_attr__( 'Upload a picture', 'songpress' ) . '"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 12V4M5 7l3-3 3 3M3 13h10" ' . $st . '/></svg><input type="file" id="spa-file" accept="image/*" multiple hidden></label>'
			. '<button type="button" id="spa-aibtn" title="' . esc_attr__( 'Make a picture with AI', 'songpress' ) . '" aria-label="' . esc_attr__( 'Make a picture with AI', 'songpress' ) . '"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 1.5l1 3.2 3.2 1L9 6.8 8 10 7 6.8 3.8 5.7 7 4.7zM12.5 9.5l.6 1.6 1.6.6-1.6.6-.6 1.6-.6-1.6-1.6-.6 1.6-.6z" ' . $st . '/></svg></button>'
			. '<button type="button" id="spa-grid" title="' . esc_attr__( 'Grid', 'songpress' ) . '" aria-label="' . esc_attr__( 'Grid', 'songpress' ) . '"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2.5 2.5h11v11h-11zM2.5 6.5h11M2.5 10.5h11M6.5 2.5v11M10.5 2.5v11" ' . $st . '/></svg></button>'
			. '<button type="button" id="spa-undo" title="' . esc_attr__( 'Undo', 'songpress' ) . '" aria-label="' . esc_attr__( 'Undo', 'songpress' ) . '"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M6 4L2.5 7.5 6 11M3 7.5h6.5a3.5 3.5 0 010 7H8" ' . $st . '/></svg></button>'
			. '<button type="button" id="spa-redo" title="' . esc_attr__( 'Redo', 'songpress' ) . '" aria-label="' . esc_attr__( 'Redo', 'songpress' ) . '"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M10 4l3.5 3.5L10 11M13 7.5H6.5a3.5 3.5 0 000 7H8" ' . $st . '/></svg></button>'
			. '<button type="button" id="spa-flip" title="' . esc_attr__( 'Flip the selected layer', 'songpress' ) . '" aria-label="' . esc_attr__( 'Flip', 'songpress' ) . '"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 2v12M5 5L2 8l3 3M11 5l3 3-3 3" ' . $st . '/></svg></button>'
			. '</div><div class="spa-wrap"><div class="spa-square" id="spa-square"><canvas id="spa-canvas" width="1000" height="1000"></canvas><div class="spa-gridov" id="spa-gridov" hidden></div><div class="spa-safeov" id="spa-safeov" hidden></div><div id="spa-sel" hidden><i id="spa-handle"></i></div></div></div></div>';
		$o .= '<p class="spe-help" id="spa-msg"></p>';
		$o .= '<div class="spa-helpers"><button type="button" class="spe-btn" id="spa-addtitle" title="' . esc_attr__( 'Add the work title and band as text layers', 'songpress' ) . '">' . $tr( 'Add title and band' ) . '</button><button type="button" class="spe-btn" id="spa-safe" aria-pressed="false" title="' . esc_attr__( 'Show where platforms crop or round the cover', 'songpress' ) . '">' . $tr( 'Safe area' ) . '</button><button type="button" class="spe-btn" id="spa-small" aria-pressed="false" title="' . esc_attr__( 'See the cover at streaming thumbnail sizes', 'songpress' ) . '">' . $tr( 'Small sizes' ) . '</button><button type="button" class="spe-btn" id="spa-keys" title="' . esc_attr__( 'Keyboard shortcuts', 'songpress' ) . '">?</button><span class="spa-smallprev" id="spa-smallprev" hidden><canvas id="spa-sp64" width="64" height="64"></canvas><canvas id="spa-sp120" width="120" height="120"></canvas><span>64 and 120 px</span></span><span class="spa-keyhelp" id="spa-keyhelp" hidden>' . $tr( 'Undo Ctrl/Cmd+Z. Redo Ctrl/Cmd+Shift+Z. Delete removes the selected layer. Arrow keys nudge it (Shift for more).' ) . '</span></div>';
		$o .= '<div class="spa-apply"><label class="spe-field"><span>' . $tr( 'Serial' ) . '</span><input id="spa-serial" inputmode="numeric" maxlength="12" placeholder="2008-02"></label><label class="spe-field"><span>' . $tr( 'Type' ) . '</span><select id="spa-type"><option value="image/png">PNG</option><option value="image/jpeg">JPEG</option><option value="image/webp">WebP</option></select></label><label class="spe-field"><span>' . $tr( 'Size' ) . '</span><select id="spa-size"><option value="1000">1000 px</option><option value="2000">2000 px</option><option value="3000" selected>3000 px</option></select></label><button type="button" class="spe-save" id="spa-cover">' . $tr( 'Apply to serial' ) . '</button><button type="button" class="spe-btn" id="spa-down">' . $tr( 'Download' ) . '</button><button type="button" class="spe-btn" id="spa-save">' . $tr( 'Save project' ) . '</button>';
		if ( $p ) {
			$o .= '<form method="post" class="spa-del" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_art_delete', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_art_delete"><input type="hidden" name="art_id" value="' . (int) $p->id . '"><input type="hidden" name="sp_return" value="' . esc_attr( Songpress_Editor::list_url() ) . '"><button class="spe-btn danger" type="submit">' . $tr( 'Delete project' ) . '</button></form>';
		}
		$o .= '</div><div class="spa-confirm" id="spa-confirm" hidden><img id="spa-confirm-old" alt=""><p id="spa-confirm-text"></p><button type="button" class="spe-save" id="spa-confirm-yes">' . $tr( 'Replace cover' ) . '</button> <button type="button" class="spe-btn" id="spa-confirm-no">' . $tr( 'Cancel' ) . '</button></div></div>';
		// right: layers and the layer's own settings
		$o .= '<aside class="spa-right"><div class="spa-head"><h3>' . $tr( 'Layers' ) . '</h3><span><button type="button" class="spa-plus" id="spa-ladd" title="' . esc_attr__( 'New layer', 'songpress' ) . '">+</button></span></div><div class="spa-addmenu" id="spa-addmenu" hidden><button type="button" class="spe-btn" data-add="text">' . $tr( 'Text' ) . '</button><button type="button" class="spe-btn" data-add="rect">' . $tr( 'Box' ) . '</button><button type="button" class="spe-btn" data-add="ellipse">' . $tr( 'Circle' ) . '</button><button type="button" class="spe-btn" id="spa-newdraw">' . $tr( 'Drawing' ) . '</button></div><ul id="spa-layers" class="spa-layers"></ul><div id="spa-props" class="spa-props"></div></aside>';
		$o .= '</div>';
		// generate window
		$o .= '<div class="spa-ai" id="spa-ai" hidden><div class="spa-head"><h3>' . $tr( 'Generate' ) . '</h3><button type="button" class="spa-plus" id="spa-aiclose" aria-label="' . esc_attr__( 'Close', 'songpress' ) . '">×</button></div><p class="spe-help">' . $tr( 'This sends your description to xAI, a separate service. The four pictures come back here; drag one onto the square (or press it) to put it on the back layer. They are also kept in your Media Library.' ) . '</p><div class="spa-aikey" id="spa-aikey"><label class="spe-field"><span>' . esc_html__( 'Your xAI key (kept on this site, never shown again)', 'songpress' ) . '</span><input type="password" id="spa-key" autocomplete="off" placeholder="xai-…"></label><button type="button" class="spe-btn" id="spa-keysave">' . esc_html__( 'Save key', 'songpress' ) . '</button> <button type="button" class="spe-btn danger" id="spa-keyclear">' . esc_html__( 'Remove key', 'songpress' ) . '</button></div><label class="spe-field"><span>' . esc_html__( 'Describe the picture', 'songpress' ) . '</span><textarea id="spa-prompt" rows="3"></textarea></label><label class="spe-check"><input type="checkbox" id="spa-aicover" checked> ' . esc_html__( 'Album cover (square, no lettering)', 'songpress' ) . '</label> <button type="button" class="spe-save" id="spa-aigo">' . esc_html__( 'Make four', 'songpress' ) . '</button><p class="spe-help" id="spa-aimsg"></p><div class="spa-aigrid" id="spa-aigrid"></div></div>';
		$o .= '<script type="application/json" id="spa-cfg">' . wp_json_encode( $cfg, JSON_HEX_TAG | JSON_HEX_AMP ) . '</script><script>' . self::js() . '</script></div>';
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
function paint(ctx,S,list){
 var px=S/REF;
 (list||L).forEach(function(l){
  if(!l.visible)return;var x=l.x/100*S,y=l.y/100*S,w=l.w/100*S,h=l.h/100*S;
  ctx.save();ctx.globalAlpha=l.opacity;
  if(l.rotate){ctx.translate(x+w/2,y+h/2);ctx.rotate(l.rotate*Math.PI/180);ctx.translate(-(x+w/2),-(y+h/2))}
  if(l.flip){ctx.translate(x+w/2,0);ctx.scale(-1,1);ctx.translate(-(x+w/2),0)}
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
function draw(){var ctx=cv.getContext('2d');ctx.clearRect(0,0,1000,1000);paint(ctx,1000);place();smalls()}
function smalls(){if($('spa-smallprev').hidden)return;[64,120].forEach(function(n){var c=$('spa-sp'+n),x=c.getContext('2d');x.clearRect(0,0,n,n);x.drawImage(cv,0,0,n,n)})}
function place(){var l=L[sel];if(!l||l.type==='canvas'){box.hidden=true;return}box.hidden=false;box.style.left=l.x+'%';box.style.top=l.y+'%';box.style.width=l.w+'%';box.style.height=l.h+'%';box.style.transform=l.rotate?'rotate('+l.rotate+'deg)':''}
function blank(o){return Object.assign({type:'image',name:'',src:'',x:0,y:0,w:100,h:100,crop:{x:0,y:0,w:1,h:1},visible:1,opacity:1,rotate:0,text:'',font:'',size:32,color:'#ffffff',bold:0,italic:0,caps:0,align:'center',valign:'middle',shadow:0,shape:'rect',flow:'',flip:0},o||{})}
function thumb(l){var c=document.createElement('canvas');c.width=c.height=36;var x=c.getContext('2d');x.fillStyle='#2a2d33';x.fillRect(0,0,36,36);x.fillStyle='#3a3e46';for(var i=0;i<6;i++)for(var j=0;j<6;j++)if((i+j)%2)x.fillRect(i*6,j*6,6,6);
 var t=Object.assign({},l,{visible:1,opacity:1,rotate:0});if(l.type==='canvas'){t.x=0;t.y=0;t.w=100;t.h=100}else{var m=Math.max(l.w,l.h,1),k=100/m;t.x=(100-l.w*k)/2;t.y=(100-l.h*k)/2;t.w=l.w*k;t.h=l.h*k;t.shadow=0}
 try{paint(x,36,[t])}catch(e){}return c}
function lname(l){return l.type==='text'&&l.text?l.text.split('\n')[0]:(l.name||(l.type==='canvas'?'Canvas':l.type))}
var dragI=-1;
function rail(){
 var ul=$('spa-layers');ul.innerHTML='';
 for(var i=L.length-1;i>=0;i--){(function(i){var l=L[i],li=document.createElement('li');li.className=(i===sel?'on':'')+(l.visible?'':' off');li.draggable=l.type!=='canvas';
  var th=document.createElement('span');th.className='spa-lt';th.appendChild(thumb(l));
  var nm=document.createElement('span');nm.className='spa-nm';nm.textContent=lname(l);nm.title='Double-click to rename';
  var eye=document.createElement('button');eye.type='button';eye.className='spa-eye';eye.title=l.visible?'Hide':'Show';eye.innerHTML=l.visible?'<svg viewBox="0 0 16 16"><path d="M1 8s2.5-4.5 7-4.5S15 8 15 8s-2.5 4.5-7 4.5S1 8 1 8z" fill="none" stroke="currentColor" stroke-width="1.3"/><circle cx="8" cy="8" r="2" fill="currentColor"/></svg>':'<svg viewBox="0 0 16 16"><path d="M1 8s2.5-4.5 7-4.5S15 8 15 8s-2.5 4.5-7 4.5S1 8 1 8zM2 14L14 2" fill="none" stroke="currentColor" stroke-width="1.3"/></svg>';
  var dl=document.createElement('button');dl.type='button';dl.className='spa-x';dl.title='Delete this layer';dl.setAttribute('aria-label','Delete layer');dl.innerHTML='<svg viewBox="0 0 16 16"><path d="M3.5 3.5l9 9M12.5 3.5l-9 9" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
  li.appendChild(th);li.appendChild(nm);li.appendChild(eye);if(l.type!=='canvas')li.appendChild(dl);
  eye.onclick=function(e){e.stopPropagation();snap();l.visible=l.visible?0:1;dirty=true;all()};
  dl.onclick=function(e){e.stopPropagation();snap();L.splice(i,1);sel=Math.max(0,Math.min(sel,L.length-1));dirty=true;all()};
  li.onclick=function(){sel=i;all()};
  nm.ondblclick=function(e){e.stopPropagation();var v=window.prompt('Layer name',lname(l));if(v&&v.trim()){snap();l.name=v.trim().slice(0,80);if(l.type==='text'&&false)l.text=l.text;rail()}};
  li.ondragstart=function(e){dragI=i;e.dataTransfer.effectAllowed='move';try{e.dataTransfer.setData('text/plain','layer')}catch(x){}};
  li.ondragover=function(e){if(dragI>=0&&i>0){e.preventDefault();li.classList.add('ov')}};li.ondragleave=function(){li.classList.remove('ov')};
  li.ondrop=function(e){e.preventDefault();li.classList.remove('ov');if(dragI<0||dragI===i||i<1)return;snap();var m=L.splice(dragI,1)[0];L.splice(i,0,m);sel=i;dragI=-1;dirty=true;all()};
  ul.appendChild(li)})(i)}
}
var FONTS=[['Rubik Dirt','Aa'],['Frijole','Aa'],['Klee One','あア'],['Noto Sans JP','あア'],['Noto Serif JP','あア'],['Zen Antique','あア'],['Alfa Slab One','Aa'],['Playfair Display','Aa'],['Bebas Neue','Aa'],['Oswald','Aa'],['Pacifico','Aa'],['Lobster','Aa'],['Special Elite','Aa'],['Permanent Marker','Aa'],['Georgia','Aa'],['Impact','Aa'],['Arial','Aa'],['Courier New','Aa'],['system-ui','Aa']],fontMenuLoaded=false;
function loadMenuFonts(){if(fontMenuLoaded)return;fontMenuLoaded=true;var fam=FONTS.filter(function(f){return !SYS.test(f[0])&&f[0]!=='system-ui'}).map(function(f){return 'family='+encodeURIComponent(f[0]).replace(/%20/g,'+')}).join('&');var k=document.createElement('link');k.rel='stylesheet';k.href='https://fonts.googleapis.com/css2?'+fam+'&display=swap';document.head.appendChild(k)}
function fld(label,key,type,extra){var l=L[sel];return '<label class="spe-field"><span>'+label+'</span><input data-k="'+key+'" type="'+(type||'number')+'" '+(extra||'')+' value="'+esc(l[key]==null?'':l[key])+'"></label>'}
function props(){
 var l=L[sel],p=$('spa-props');if(!l){p.innerHTML='';return}
 var h='',pos='<details class="spa-pos"><summary>Position and size</summary><div class="spa-grid">'+fld('X %','x','number','step="0.5"')+fld('Y %','y','number','step="0.5"')+fld('Width %','w','number','step="0.5"')+fld('Height %','h','number','step="0.5"')+fld('Opacity','opacity','number','min="0" max="1" step="0.05"')+fld('Rotate °','rotate','number','step="1"')+'</div></details>';
 if(l.type==='canvas'){h='<h4>Canvas</h4>'+fld('Colour','color','color')}
 else if(l.type==='text'){
  h='<h4>Text</h4><label class="spe-field"><textarea id="spa-text" data-k="text" rows="3">'+esc(l.text)+'</textarea></label>'
   +'<div class="spa-fontpick"><button type="button" class="spa-fontbtn" id="spa-fontbtn" aria-haspopup="listbox" style="font-family:'+fontCss(l.font||'sans-serif')+'">'+esc(l.font||'Font')+'</button><div class="spa-fontmenu" id="spa-fontmenu" hidden role="listbox">'+FONTS.map(function(f){return '<button type="button" role="option" data-font="'+esc(f[0])+'"><span class="spa-fs" style="font-family:'+fontCss(f[0])+'">'+f[1]+'</span><span>'+esc(f[0])+'</span></button>'}).join('')+'</div></div>'
   +'<div class="spa-grid">'+fld('Size','size','number','min="4" step="1"')+fld('Colour','color','color')+'</div>'
   +'<div class="spa-fmt"><button type="button" data-t="bold" class="'+(l.bold?'on':'')+'" title="Bold"><b>B</b></button><button type="button" data-t="italic" class="'+(l.italic?'on':'')+'" title="Italic"><i>I</i></button><button type="button" data-t="caps" class="'+(l.caps?'on':'')+'" title="All caps"><svg viewBox="0 0 24 16" width="22" height="14"><path d="M2.2 14L6 2.2 9.8 14M3.6 9.6h4.8M14.2 14L18 2.2 21.8 14M15.6 9.6h4.8" fill="none" stroke="currentColor" stroke-width="1.6"/></svg></button></div>'
   +'<label class="spe-field"><span>Shadow</span><input data-k="shadow" type="range" min="0" max="1" step="0.05" value="'+l.shadow+'"></label>'
   +'<div class="spa-grid"><label class="spe-field"><span>Align</span><select data-k="align"><option>left</option><option>center</option><option>right</option></select></label><label class="spe-field"><span>Vertical</span><select data-k="valign"><option>top</option><option>middle</option><option>bottom</option></select></label><label class="spe-field"><span>Direction</span><select data-k="flow"><option value="">Horizontal</option><option value="stack">Stack</option><option value="turn">Turn</option></select></label></div>'+pos}
 else{h='<h4>'+esc(lname(l))+'</h4>';if(l.type==='shape')h+=fld('Colour','color','color');if(l.type==='image')h+='<p class="spe-help">Drag on the square to move; the corner handle scales it.</p>';h+=pos}
 p.innerHTML=h;
 p.querySelectorAll('[data-k]').forEach(function(e){var k=e.getAttribute('data-k');if(e.tagName==='SELECT'){e.value=l[k]}
  var fresh=true;e.addEventListener('focus',function(){fresh=true});
  e.addEventListener('input',function(){if(fresh){snap();fresh=false}var v=e.type==='number'||e.type==='range'?parseFloat(e.value):e.value;if((e.type==='number'||e.type==='range')&&isNaN(v))return;l[k]=v;dirty=true;if(k==='text'){var nm=document.querySelector('#spa-layers li.on .spa-nm');if(nm)nm.textContent=lname(l)}draw()})});
 p.querySelectorAll('.spa-fmt [data-t]').forEach(function(b){b.onclick=function(){snap();var k=b.getAttribute('data-t');l[k]=l[k]?0:1;b.classList.toggle('on',!!l[k]);dirty=true;draw()}});
 var fb=$('spa-fontbtn'),fm=$('spa-fontmenu');
 if(fb){fb.onclick=function(e){e.stopPropagation();loadMenuFonts();fm.hidden=!fm.hidden};fm.onclick=function(e){var b=e.target.closest('[data-font]');if(!b)return;snap();l.font=b.getAttribute('data-font');fm.hidden=true;dirty=true;fb.textContent=l.font;fb.style.fontFamily=fontCss(l.font);needFonts();draw()}}
}
function all(){draw();rail();props();needFonts();upd()}
document.addEventListener('click',function(e){var fm=$('spa-fontmenu');if(fm&&!fm.hidden&&!e.target.closest('.spa-fontpick'))fm.hidden=true;var am=$('spa-addmenu');if(am&&!am.hidden&&!e.target.closest('#spa-addmenu')&&e.target.id!=='spa-ladd')am.hidden=true});
$('spa-ladd').onclick=function(e){e.stopPropagation();$('spa-addmenu').hidden=!$('spa-addmenu').hidden};
function addLayer(n){snap();var l=blank(n);L.push(l);sel=L.length-1;dirty=true;all();return l}
function addKind(t){$('spa-addmenu').hidden=true;if(t==='text'){addLayer({type:'text',name:'Text',x:10,y:40,w:80,h:18,text:'Your words',font:'Impact',size:60,color:'#ffffff'});var tx=$('spa-text');if(tx){tx.focus();tx.select()}}else addLayer({type:'shape',name:t==='rect'?'Box':'Circle',x:30,y:30,w:40,h:40,color:'#f5a623',shape:t==='ellipse'?'ellipse':'rect'})}
document.querySelectorAll('[data-add]').forEach(function(b){b.onclick=function(){addKind(b.getAttribute('data-add'))}});
$('spa-addtext').onclick=function(){setTool('move');addKind('text')};
function addPicture(url,name,asBack){var im=new Image();im.onload=function(){var ar=im.naturalWidth/im.naturalHeight,w=asBack?100:50,h=w/ar;snap();var l=blank({type:'image',name:name||'Picture',src:url,x:asBack?0:25,y:asBack?0:25,w:w,h:h});if(asBack){if(h<100){l.h=100;l.w=100*ar;l.x=(100-l.w)/2}else{l.y=(100-h)/2}L.splice(1,0,l);sel=1}else{L.push(l);sel=L.length-1}dirty=true;say('');all()};im.src=url}
function uploadPics(files){Array.prototype.slice.call(files).reduce(function(pr,f){return pr.then(function(){say('Uploading '+f.name+'…');var fd=new FormData();fd.append('action','songpress_upload');fd.append('nonce',C.upload);fd.append('file',f);
 return fetch(C.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){if(!j.success){say((j.data&&j.data.message)||'Upload failed');return}addPicture(j.data.url,f.name.replace(/\.[^.]+$/,''))})})},Promise.resolve()).catch(function(){say('Upload failed')})}
$('spa-file').onchange=function(e){uploadPics(e.target.files);e.target.value=''};
(function(){var s=$('spa-square');['dragenter','dragover'].forEach(function(n){s.addEventListener(n,function(e){if(e.dataTransfer&&(e.dataTransfer.files&&e.dataTransfer.files.length||[].indexOf.call(e.dataTransfer.types||[],'application/x-spa-pick')>-1||[].indexOf.call(e.dataTransfer.types||[],'Files')>-1)){e.preventDefault();s.classList.add('over')}})});
 s.addEventListener('dragleave',function(){s.classList.remove('over')});
 s.addEventListener('drop',function(e){s.classList.remove('over');var pk=e.dataTransfer&&e.dataTransfer.getData&&e.dataTransfer.getData('application/x-spa-pick');if(pk){e.preventDefault();claimPick(pk);return}if(e.dataTransfer&&e.dataTransfer.files&&e.dataTransfer.files.length){e.preventDefault();uploadPics(e.dataTransfer.files)}})})();
document.addEventListener('paste',function(e){var d=e.clipboardData;if(!d||!d.files||!d.files.length||/INPUT|TEXTAREA|SELECT/.test((e.target.tagName||'')))return;e.preventDefault();uploadPics(d.files)});
/* draw tools */
function clone(l){var c=JSON.parse(JSON.stringify(l,function(k,v){return k.charAt(0)==='_'?undefined:v}));if(l._bm){c._bm=cvCopy(l._bm);c._dirty=true;c.src=''}return c}
function cvCopy(b){var c=document.createElement('canvas');c.width=b.width;c.height=b.height;c.getContext('2d').drawImage(b,0,0);return c}
var tool='move',undos=[],snaps=[],redos=[],order=[],stroke=null,BM=1600;
function pushU(u){undos.push(u);if(undos.length>6)undos.shift();order.push('d')}
function copyL(a){return a.map(function(l){return Object.assign({},l,{crop:Object.assign({},l.crop)})})}
function snap(){snaps.push({L:copyL(L),sel:sel});if(snaps.length>60)snaps.shift();order.push('s');redos.length=0;upd()}
function upd(){$('spa-undo').disabled=!order.length;$('spa-redo').disabled=!redos.length}
function plain(l){return l&&l.type==='image'&&!l.rotate&&l.crop.x===0&&l.crop.y===0&&l.crop.w===1&&l.crop.h===1}
function mkBitmap(l,done){
 if(l._bm){done(true);return}
 if(!l.src){var c=document.createElement('canvas');c.width=c.height=BM;l._bm=c;l._dirty=true;done(true);return}
 var r=image(l.src,function(){});var go=function(){if(r.tainted){say('That picture is hosted elsewhere, so it cannot be drawn on. Upload it here first.');done(false);return}
  var n=r.img,k=Math.min(1,BM/Math.max(n.naturalWidth,n.naturalHeight)),c=document.createElement('canvas');c.width=Math.max(1,Math.round(n.naturalWidth*k));c.height=Math.max(1,Math.round(n.naturalHeight*k));c.getContext('2d').drawImage(n,0,0,c.width,c.height);l._bm=c;done(true)};
 if(r.ok)go();else{var t=setInterval(function(){if(imgs[l.src]&&imgs[l.src].ok){clearInterval(t);r=imgs[l.src];go()}},100);setTimeout(function(){clearInterval(t)},8000)}}
function newDrawing(){L.push({type:'image',name:'Drawing',src:'',x:0,y:0,w:100,h:100,crop:{x:0,y:0,w:1,h:1},visible:1,opacity:1,rotate:0,text:'',font:'',size:32,color:'#ffffff',bold:0,italic:0,caps:0,align:'center',valign:'middle',shadow:0,shape:'rect'});sel=L.length-1;mkBitmap(L[sel],function(){});dirty=true;all();return L[sel]}
var TIPS={move:['Move','Drag a layer on the square to move it. The corner handle scales it.'],brush:['Draw','Paint on the selected picture or drawing layer. Press + Drawing to start a new transparent layer.'],eraser:['Eraser','Rub out parts of the selected picture or drawing layer.'],bucket:['Fill','Click an area to fill it with the colour.'],gradient:['Gradient','Drag across the layer from one colour to the other.'],rect:['Rectangle','Drag out a box on the selected picture or drawing layer.'],ellipse:['Ellipse','Drag out an ellipse on the selected picture or drawing layer.'],marquee:['Select','Drag to choose an area; drawing then stays inside it. Delete clears it.'],eyedrop:['Pick colour','Click the square to take a colour from it.']};
function setTool(t){tool=t;document.querySelectorAll('[data-tool]').forEach(function(b){b.classList.toggle('on',b.getAttribute('data-tool')===t)});sq.style.touchAction=t==='move'?'':'none';sq.style.cursor=t==='move'?'':'crosshair';
 var tp=TIPS[t]||TIPS.move;$('spa-opt-title').textContent=tp[0];$('spa-hint').textContent=tp[1];$('spa-ink').hidden=t==='move';
 if(t!=='move'&&t!=='eyedrop'){var l=L[sel];if(!l||l.type!=='image'){newDrawing()}else if(!plain(l)){say('Set Rotate to 0 on this picture to draw on it, or press + Drawing.')}else{say('')}}else say('')}
document.querySelectorAll('[data-tool]').forEach(function(b){b.onclick=function(){setTool(b.getAttribute('data-tool'))}});
$('spa-newdraw').onclick=function(){newDrawing();if(tool==='move')setTool('brush')};
function lp(e,l){var r=sq.getBoundingClientRect(),px=(e.clientX-r.left)/r.width*100,py=(e.clientY-r.top)/r.height*100;return{x:(px-l.x)/l.w*l._bm.width,y:(py-l.y)/l.h*l._bm.height,k:l._bm.width/(l.w/100*1000)}}
function seg(c,a,b,wd){c.lineWidth=wd;c.lineCap=c.lineJoin='round';c.beginPath();c.moveTo(a.x,a.y);c.lineTo(b.x+0.01,b.y);c.stroke()}
function drawStart(e){if(tool==='eyedrop'){eyedrop(e);return false}var l=L[sel];if(!l||l.type!=='image'||!plain(l)){say('Pick a picture or press + Drawing first.');return false}
 if(!l._bm){mkBitmap(l,function(ok){if(ok)all()});return true}
 if(tool==='bucket'){flood(l,lp(e,l));return false}
 if(SHAPET[tool]){var a=lp(e,l);pushU({l:l,c:cvCopy(l._bm),d:!!l._dirty});stroke={l:l,t:tool,a:a,fl:parseInt($('spa-bflow').value,10)/100};if(tool!=='marquee'){var st=document.createElement('canvas');st.width=l._bm.width;st.height=l._bm.height;l._st=st;l._sa=stroke.fl}else msel=null;e.preventDefault();return true}
 pushU({l:l,c:cvCopy(l._bm),d:!!l._dirty});
 var er=tool==='eraser',fl=parseInt($('spa-bflow').value,10)/100;
 stroke={l:l,er:er,fl:fl,last:lp(e,l),pen:e.pointerType==='pen'};
 if(!er){var st=document.createElement('canvas');st.width=l._bm.width;st.height=l._bm.height;l._st=st;l._sa=fl}
 drawMove(e,true);e.preventDefault();return true}
function drawMove(e,first){if(!stroke)return;if(stroke.t){shapePreview(stroke.a,lp(e,stroke.l));return}var l=stroke.l,p=lp(e,l),pr=stroke.pen&&e.pressure?Math.max(0.15,e.pressure*1.6):1,wd=Math.max(1,parseInt($('spa-bsize').value,10)*p.k*pr),c;
 if(stroke.er){c=l._bm.getContext('2d');c.save();clipTo(c,l);c.globalCompositeOperation='destination-out';c.globalAlpha=stroke.fl;c.strokeStyle='#000';seg(c,stroke.last,p,wd);c.restore()}
 else{c=l._st.getContext('2d');c.save();clipTo(c,l);c.strokeStyle=$('spa-bcolor').value;seg(c,stroke.last,p,wd);c.restore()}
 stroke.last=p;l._dirty=true;dirty=true;draw()}
function drawEnd(){if(!stroke)return;var l=stroke.l;if(stroke.t==='marquee'){stroke=null;if(msel&&(msel.w<3||msel.h<3)){msel=null}showSel();return}if(l._st){if(stroke.t)l._dirty=true;var c=l._bm.getContext('2d');c.save();c.globalAlpha=stroke.fl;clipTo(c,l);c.drawImage(l._st,0,0);c.restore();l._st=null}stroke=null;draw()}
function undo(){var k=order.pop();if(!k){say('Nothing to undo.');return}
 if(k==='d'){var u=undos.pop();if(u&&L.indexOf(u.l)>=0){var c=u.l._bm.getContext('2d');c.save();c.globalCompositeOperation='copy';c.drawImage(u.c,0,0);c.restore();u.l._dirty=true}draw();upd();return}
 var sn=snaps.pop();if(!sn){upd();return}redos.push({L:copyL(L),sel:sel});L=sn.L;sel=Math.min(sel,L.length-1);dirty=true;all();upd()}
function redo(){var r=redos.pop();if(!r)return;snaps.push({L:copyL(L),sel:sel});order.push('s');L=r.L;sel=Math.min(sel,L.length-1);dirty=true;all();upd()}
$('spa-undo').onclick=undo;$('spa-redo').onclick=redo;

/* more tools: fill, gradient, shapes, select, eyedropper */
var DRAWT={brush:1,eraser:1},SHAPET={gradient:1,rect:1,ellipse:1,marquee:1},msel=null,mbox=document.createElement('div');
mbox.style.cssText='position:absolute;border:1px dashed #fff;outline:1px dashed #000;pointer-events:none;display:none';sq.appendChild(mbox);
function showSel(){var l=msel&&msel.l;if(!l||L.indexOf(l)<0||!l._bm){mbox.style.display='none';return}mbox.style.display='block';mbox.style.left=(l.x+msel.x/l._bm.width*l.w)+'%';mbox.style.top=(l.y+msel.y/l._bm.height*l.h)+'%';mbox.style.width=(msel.w/l._bm.width*l.w)+'%';mbox.style.height=(msel.h/l._bm.height*l.h)+'%'}
function clipTo(c,l){if(msel&&msel.l===l){c.beginPath();c.rect(msel.x,msel.y,msel.w,msel.h);c.clip()}}
function hex(h){return[parseInt(h.substr(1,2),16),parseInt(h.substr(3,2),16),parseInt(h.substr(5,2),16)]}
function flood(l,pt){var b=l._bm,c=b.getContext('2d'),W=b.width,H=b.height,sx=Math.floor(pt.x),sy=Math.floor(pt.y);if(sx<0||sy<0||sx>=W||sy>=H)return;
 var x0=0,y0=0,x1=W,y1=H;if(msel&&msel.l===l){x0=Math.max(0,Math.floor(msel.x));y0=Math.max(0,Math.floor(msel.y));x1=Math.min(W,Math.ceil(msel.x+msel.w));y1=Math.min(H,Math.ceil(msel.y+msel.h));if(sx<x0||sx>=x1||sy<y0||sy>=y1)return}
 pushU({l:l,c:cvCopy(b),d:!!l._dirty});
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
document.addEventListener('keydown',function(e){if((e.key==='Delete'||e.key==='Backspace')&&msel&&tool==='marquee'&&!/INPUT|TEXTAREA|SELECT/.test((e.target.tagName||''))){var l=msel.l;if(!l._bm)return;pushU({l:l,c:cvCopy(l._bm),d:!!l._dirty});var c=l._bm.getContext('2d');c.clearRect(msel.x,msel.y,msel.w,msel.h);l._dirty=true;dirty=true;draw();e.preventDefault()}});
/* AI pictures */
var ai=$('spa-ai');
function aiOpen(){ai.hidden=false;$('spa-aikey').hidden=!!C.ai;if(!$('spa-prompt').value)$('spa-prompt').value=C.prompt||$('spa-name').value||''}
$('spa-aibtn').onclick=function(){if(ai.hidden)aiOpen();else ai.hidden=true};
$('spa-aiclose').onclick=function(){ai.hidden=true};
function aimsg(t){$('spa-aimsg').textContent=t||''}
function post(action,data){var fd=new FormData();fd.append('action',action);fd.append('nonce',C.nonce);for(var k in data)fd.append(k,data[k]);return fetch(C.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()})}
$('spa-keysave').onclick=function(){var v=$('spa-key').value.trim();if(!v){aimsg('Paste your key first.');return}post('songpress_art_key',{key:v}).then(function(j){if(j.success){C.ai=true;$('spa-key').value='';$('spa-aikey').hidden=true;aimsg('Key saved.')}else aimsg((j.data&&j.data.message)||'Could not save the key')})};
$('spa-keyclear').onclick=function(){post('songpress_art_key',{key:''}).then(function(){C.ai=false;aimsg('Key removed.')})};
function claimPick(u){aimsg('Adding to the back layer…');return post('songpress_art_claim',{src:u}).then(function(r){if(!r.success){aimsg((r.data&&r.data.message)||'Could not add it');return}aimsg('Added to the back layer.');addPicture(r.data.url,'AI picture',true)}).catch(function(){aimsg('Could not add it')})}
$('spa-aigo').onclick=function(){if(!C.ai){$('spa-aikey').hidden=false;aimsg('Add your xAI key first.');return}var g=$('spa-aigrid');g.innerHTML='';aimsg('Making four pictures… this can take a minute.');$('spa-aigo').disabled=true;
 post('songpress_art_generate',{prompt:$('spa-prompt').value,cover:$('spa-aicover').checked?1:0}).then(function(j){$('spa-aigo').disabled=false;if(!j.success){aimsg((j.data&&j.data.message)||'Nothing came back');return}aimsg('Drag one onto the square, or press it, to put it on the back layer.');
  j.data.images.forEach(function(u){var b=document.createElement('button');b.type='button';b.className='spa-aipic';b.draggable=true;var im=document.createElement('img');im.src=u;im.alt='';b.appendChild(im);
   b.addEventListener('dragstart',function(e){e.dataTransfer.setData('application/x-spa-pick',u);e.dataTransfer.effectAllowed='copy'});
   b.onclick=function(){b.disabled=true;claimPick(u).then(function(){b.disabled=false})};
   g.appendChild(b)})}).catch(function(){$('spa-aigo').disabled=false;aimsg('The request failed. Try again.')})};
/* drag to move, corner to resize */
var hitc=document.createElement('canvas');hitc.width=hitc.height=200;
function inBox(l,p){return p.x>=l.x&&p.x<=l.x+l.w&&p.y>=l.y&&p.y<=l.y+l.h}
function hitAt(l,p){if(!inBox(l,p))return false;if(l.type!=='image')return true;try{var x=hitc.getContext('2d');x.clearRect(0,0,200,200);paint(x,200,[Object.assign({},l,{visible:1,opacity:1})]);var d=x.getImageData(Math.max(0,Math.min(199,Math.floor(p.x*2))),Math.max(0,Math.min(199,Math.floor(p.y*2))),1,1).data;return d[3]>12}catch(err){return true}}
var ted=null;
function editText(i){var l=L[i];if(!l||l.type!=='text')return;sel=i;all();closeEd();var ta=document.createElement('textarea');ta.className='spa-inline';ta.value=l.text;ta.style.left=l.x+'%';ta.style.top=l.y+'%';ta.style.width=Math.max(l.w,12)+'%';ta.style.height=Math.max(l.h,8)+'%';ta.style.fontFamily=fontCss(l.font||'sans-serif');ta.style.textAlign=l.align;sq.appendChild(ta);ted=ta;var fresh=true;
 ta.addEventListener('input',function(){if(fresh){snap();fresh=false}l.text=ta.value;dirty=true;draw();var pt2=$('spa-text');if(pt2)pt2.value=ta.value;var nm=document.querySelector('#spa-layers li.on .spa-nm');if(nm)nm.textContent=lname(l)});
 ta.addEventListener('keydown',function(e){e.stopPropagation();if(e.key==='Escape'||(e.key==='Enter'&&(e.metaKey||e.ctrlKey))){closeEd()}});ta.addEventListener('blur',closeEd);ta.addEventListener('pointerdown',function(e){e.stopPropagation()});ta.focus();ta.select()}
function closeEd(){if(ted){var t=ted;ted=null;if(t.parentNode)t.parentNode.removeChild(t)}}
sq.addEventListener('dblclick',function(e){if(tool!=='move')return;var p=pt(e);for(var i=L.length-1;i>=0;i--){var l=L[i];if(l.type==='text'&&l.visible&&inBox(l,p)){e.preventDefault();editText(i);return}}});
var drag=null;
function pt(e){var r=sq.getBoundingClientRect();return{x:(e.clientX-r.left)/r.width*100,y:(e.clientY-r.top)/r.height*100}}
sq.addEventListener('pointerdown',function(e){if(tool!=='move'){if(drawStart(e)){sq.setPointerCapture(e.pointerId)}return}var p=pt(e);
 if(e.target===hd){snap();drag={m:'size',p:p,l:L[sel],o:{w:L[sel].w,h:L[sel].h}};sq.setPointerCapture(e.pointerId);e.preventDefault();return}
 var hit=-1,cur=L[sel];if(cur&&cur.type!=='canvas'&&cur.visible&&!e.altKey&&hitAt(cur,p))hit=sel;
 if(hit<0){for(var i=L.length-1;i>=0;i--){var l=L[i];if(l.type==='canvas'||!l.visible)continue;if(e.altKey&&i===sel)continue;if(hitAt(l,p)){hit=i;break}}}
 if(hit>=0){snap();sel=hit;drag={m:'move',p:p,l:L[sel],o:{x:L[sel].x,y:L[sel].y}};sq.setPointerCapture(e.pointerId);rail();props();draw()}
});
sq.addEventListener('pointermove',function(e){if(stroke){drawMove(e);return}if(!drag)return;var p=pt(e),dx=p.x-drag.p.x,dy=p.y-drag.p.y,l=drag.l;
 if(drag.m==='move'){l.x=Math.round((drag.o.x+dx)*10)/10;l.y=Math.round((drag.o.y+dy)*10)/10}else{l.w=Math.max(1,Math.round((drag.o.w+dx)*10)/10);l.h=Math.max(1,Math.round((drag.o.h+dy)*10)/10)}
 dirty=true;draw()});
function end(){if(stroke){drawEnd();return}if(drag){drag=null;props()}}sq.addEventListener('pointerup',end);sq.addEventListener('pointercancel',end);
/* output */
function render(size){var c=document.createElement('canvas');c.width=c.height=size;paint(c.getContext('2d'),size);return c}
function mime(){return $('spa-type').value||'image/png'}
function ext(){var m=mime();return m==='image/jpeg'?'.jpg':(m==='image/webp'?'.webp':'.png')}
function blob(size,type,q){return new Promise(function(res,rej){var c=render(size);try{c.toBlob(function(b){b?res(b):rej(new Error('x'))},type||'image/png',q||0.92)}catch(e){rej(e)}})}
function thumbData(){try{var c=document.createElement('canvas');c.width=c.height=120;c.getContext('2d').drawImage(cv,0,0,120,120);return c.toDataURL('image/jpeg',0.7)}catch(e){return ''}}
function fname(){var s=($('spa-serial').value||'').trim(),n=($('spa-name').value||'cover').trim();return (s&&n.indexOf(s)<0?s+' ':'')+n}
function save(cover){
 var fd=new FormData();fd.append('action','songpress_art_save');fd.append('nonce',C.nonce);fd.append('id',id);fd.append('name',$('spa-name').value);fd.append('serial',$('spa-serial').value);fd.append('layers',JSON.stringify(L,function(k,v){return k.charAt(0)==='_'?undefined:v}));fd.append('thumb',thumbData());
 say(cover?'Rendering the cover…':'Saving…');
 var go=function(b){if(b){fd.append('file',b,'cover-'+Date.now()+ext());if(cover)fd.append('set_cover','1')}
  fetch(C.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){if(!j.success){say((j.data&&j.data.message)||'Could not save');return}
   id=j.data.id;dirty=false;say(cover?(j.data.cover?'Saved, and set as the work\'s cover.':'Saved. Add the work\'s serial to set it as the cover.'):'Saved.');if(!C.id){C.id=id;history.replaceState(null,'',j.data.url)}refreshList(j.data.id)}).catch(function(){say('Could not save')})};
 var w=Math.min(3000,parseInt($('spa-size').value,10)||3000);
 blob(cover?w:1000,cover?mime():'image/png').then(go,function(){say('A picture on this project could not be copied into the cover (it is hosted elsewhere). Save the project without the cover, or upload that picture here.')});
}
function flush(){var jobs=L.filter(function(l){return l._bm&&l._dirty});if(!jobs.length)return Promise.resolve();say('Saving your drawing…');
 return jobs.reduce(function(p,l){return p.then(function(){return new Promise(function(res,rej){try{l._bm.toBlob(function(b){if(!b){rej(new Error('x'));return}var fd=new FormData();fd.append('action','songpress_upload');fd.append('nonce',C.upload);fd.append('file',b,'drawing-'+Date.now()+'.png');
  fetch(C.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){if(!j.success){rej(new Error((j.data&&j.data.message)||'Upload failed'));return}l.src=j.data.url;l._dirty=false;res()},rej)},'image/png')}catch(e){rej(e)}})})},Promise.resolve())}
function go(c){flush().then(function(){save(c)},function(e){say('Could not save the drawing: '+(e&&e.message||''))})}
$('spa-save').onclick=function(){go(false)};
$('spa-cover').onclick=function(){var s=$('spa-serial').value.trim();if(!s){say('Type the work\'s serial first (for example 2008-02).');return}
 post('songpress_art_lookup',{serial:s}).then(function(j){if(!j.success){say((j.data&&j.data.message)||'No work has that serial.');return}
  $('spa-serial').value=j.data.serial;var cf=$('spa-confirm');$('spa-confirm-old').hidden=!j.data.old;if(j.data.old)$('spa-confirm-old').src=j.data.old;
  $('spa-confirm-text').textContent=(j.data.old?'Replace the cover of ':'Set the cover of ')+j.data.serial+' '+j.data.title+(j.data.old?'? The picture on the left is the one it has now.':'?');cf.hidden=false;cf.scrollIntoView({block:'nearest'})})};
$('spa-confirm-no').onclick=function(){$('spa-confirm').hidden=true};
$('spa-confirm-yes').onclick=function(){$('spa-confirm').hidden=true;go(true)};
$('spa-down').onclick=function(){blob(parseInt($('spa-size').value,10)||3000,mime()).then(function(b){var a=document.createElement('a');a.href=URL.createObjectURL(b);a.download=fname().replace(/[\\/:*?"<>|]+/g,'-')+ext();document.body.appendChild(a);a.click();setTimeout(function(){URL.revokeObjectURL(a.href);a.remove()},500)},function(){say('A picture on this project is hosted elsewhere and cannot be exported.')})};
window.addEventListener('beforeunload',function(e){if(dirty){e.preventDefault();e.returnValue=''}});
/* helpers: grid, safe area, small sizes, shortcuts, flip, title and band */
function tog(id,fn){var b=$(id);b.onclick=function(){var on=b.getAttribute('aria-pressed')!=='true';b.setAttribute('aria-pressed',on?'true':'false');b.classList.toggle('on',on);fn(on)}}
$('spa-grid').onclick=function(){var g=$('spa-gridov');g.hidden=!g.hidden;this.classList.toggle('on',!g.hidden)};
tog('spa-safe',function(on){$('spa-safeov').hidden=!on});
tog('spa-small',function(on){$('spa-smallprev').hidden=!on;smalls()});
$('spa-keys').onclick=function(){$('spa-keyhelp').hidden=!$('spa-keyhelp').hidden};
$('spa-flip').onclick=function(){var l=L[sel];if(!l||l.type==='canvas'){say('Pick a layer to flip.');return}snap();l.flip=l.flip?0:1;dirty=true;draw();rail()};
$('spa-addtitle').onclick=function(){var s=$('spa-serial').value.trim(),go2=function(t,b){snap();var n=0;if(t){L.push(blank({type:'text',name:'Title',x:6,y:6,w:88,h:22,text:t,font:'Impact',size:90,color:'#ffffff',valign:'top',shadow:0.6}));n++}if(b){L.push(blank({type:'text',name:'Band',x:6,y:80,w:88,h:14,text:b,font:'Impact',size:48,color:'#ffffff',valign:'bottom',shadow:0.6}));n++}if(n){sel=L.length-1;dirty=true;all()}if(b&&LIB.logo){var m=LIB.logo.filter(function(g){return g.name.toLowerCase()===String(b).toLowerCase()})[0];if(m)addLogo(m)}};
 if(s){post('songpress_art_lookup',{serial:s}).then(function(j){if(j.success)go2(j.data.title,j.data.band);else go2($('spa-name').value,'')})}else go2($('spa-name').value,'')};
document.addEventListener('keydown',function(e){var tg=(e.target.tagName||'');if(/INPUT|TEXTAREA|SELECT/.test(tg)||e.target.isContentEditable)return;
 var m=e.metaKey||e.ctrlKey;if(m&&(e.key==='z'||e.key==='Z')){e.preventDefault();if(e.shiftKey)redo();else undo();return}if(m&&(e.key==='y')){e.preventDefault();redo();return}
 var l=L[sel];if(!l||l.type==='canvas'||tool!=='move')return;
 if(e.key==='Delete'||e.key==='Backspace'){e.preventDefault();snap();L.splice(sel,1);sel=Math.max(0,Math.min(sel,L.length-1));dirty=true;all();return}
 var st=e.shiftKey?2:0.5,mv={ArrowLeft:[-st,0],ArrowRight:[st,0],ArrowUp:[0,-st],ArrowDown:[0,st]}[e.key];if(mv){e.preventDefault();snap();l.x=Math.round((l.x+mv[0])*10)/10;l.y=Math.round((l.y+mv[1])*10)/10;dirty=true;draw();props()}});
/* projects on the left */
function refreshList(cur){document.querySelectorAll('#spa-plist li').forEach(function(li){li.classList.toggle('on',+li.getAttribute('data-id')===cur)});var has=document.querySelector('#spa-plist li[data-id="'+cur+'"]');if(!has){var li=document.createElement('li');li.setAttribute('data-id',cur);li.className='on';li.innerHTML='<a href="'+esc(location.href)+'"><span class="spa-pt"></span><span class="spa-pn">x</span></a>';$('spa-plist').insertBefore(li,$('spa-plist').firstChild);has=li}
 var t=thumbData(),sp=has.querySelector('.spa-pt');if(t)sp.innerHTML='<img alt="" src="'+t+'">';var pn=has.querySelector('.spa-pn');if(pn&&pn.firstChild)pn.firstChild.nodeValue=$('spa-name').value||'Untitled art'}
$('spa-plist').addEventListener('click',function(e){var a=e.target.closest('a');if(!a)return;var li=a.closest('li');if(li&&+li.getAttribute('data-id')===id)return;e.preventDefault();askOpen(li,a.href)});
function askOpen(li,href){var old=document.getElementById('spa-ask');if(old)old.remove();var nm=li.querySelector('.spa-pn').firstChild.nodeValue,ser=li.getAttribute('data-serial')||'',ed=li.getAttribute('data-edit')||'',pid=li.getAttribute('data-id');
 var d=document.createElement('div');d.id='spa-ask';d.className='spa-ask';d.innerHTML='<div class="spa-askbox" role="dialog"><h4></h4><p></p><div class="spa-askbtns"></div></div>';document.body.appendChild(d);
 d.querySelector('h4').textContent=nm;var cur=($('spa-serial').value||'').trim(),tgt=cur||ser;d.querySelector('p').textContent='Is this a new project? A new one starts as a copy and is added to '+(tgt?'the work '+tgt:'no work yet')+'. Or update the old one.';
 var bx=d.querySelector('.spa-askbtns');
 function btn(t,cls,fn){var b=document.createElement('button');b.type='button';b.className=cls;b.textContent=t;b.onclick=fn;bx.appendChild(b)}
 function leave(u){if(dirty&&!window.confirm('This art has changes that are not saved. Leave without saving?'))return;dirty=false;location.href=u}
 btn('New project'+(tgt?' for '+tgt:''),'spe-save',function(){leave(C.list+(C.list.indexOf('?')<0?'?':'&')+'art=new&from='+pid+(cur?'&to='+encodeURIComponent(cur):''))});
 btn('Update the old one','spe-btn',function(){leave(href)});
 if(ed)btn('Open its work in the Works editor','spe-btn',function(){leave(ed)});
 btn('Cancel','spe-btn',function(){d.remove()});
 d.addEventListener('click',function(e){if(e.target===d)d.remove()});document.addEventListener('keydown',function k(e){if(e.key==='Escape'){d.remove();document.removeEventListener('keydown',k)}})}
$('spa-plist').addEventListener('dblclick',function(e){var li=e.target.closest('li');if(!li||!e.target.closest('.spa-pn'))return;e.preventDefault();var pid=+li.getAttribute('data-id'),cur=li.querySelector('.spa-pn').firstChild.nodeValue,v=window.prompt('Rename this art',cur);if(!v||!v.trim()||v===cur)return;post('songpress_art_rename',{id:pid,name:v.trim()}).then(function(j){if(j.success){li.querySelector('.spa-pn').firstChild.nodeValue=j.data.name;if(pid===id)$('spa-name').value=j.data.name}else say((j.data&&j.data.message)||'Could not rename')})});
/* elements and templates */
var LIB={el:[],tpl:[],logo:[]};
function libCall(kind,op,extra){return post('songpress_art_lib',Object.assign({kind:kind,op:op},extra||{})).then(function(j){if(j.success){LIB=j.data;libDraw()}else say((j.data&&j.data.message)||'Could not do that');return j})}
function libDraw(){logoDraw();var e=$('spa-els');e.innerHTML='';if(!LIB.el.length)e.innerHTML='<p class="spe-help">Press + to keep the selected layer here.</p>';
 LIB.el.forEach(function(it){var b=document.createElement('div');b.className='spa-el';b.title=it.name+' (click to add, double-click to rename, × to remove)';var th=thumb(blank(it.layer));var wrap=document.createElement('button');wrap.type='button';wrap.appendChild(th);var x=document.createElement('i');x.textContent='×';b.appendChild(wrap);b.appendChild(x);
  wrap.onclick=function(){snap();var l=blank(JSON.parse(JSON.stringify(it.layer)));l.x=Math.min(l.x+3,90);L.push(l);sel=L.length-1;dirty=true;all()};
  wrap.ondblclick=function(){var v=window.prompt('Name',it.name);if(v&&v.trim())libCall('el','rename',{item:it.id,name:v.trim()})};
  x.onclick=function(ev){ev.stopPropagation();if(window.confirm('Remove "'+it.name+'" from Elements?'))libCall('el','delete',{item:it.id})};e.appendChild(b)});
 var t=$('spa-tpls');t.innerHTML='';if(!LIB.tpl.length)t.innerHTML='<li class="spe-help">Press + to keep the layers above the canvas as a template.</li>';
 LIB.tpl.forEach(function(it){var li=document.createElement('li');li.innerHTML='<button type="button"></button><i>×</i>';var b=li.firstChild;b.textContent=it.name+' ('+it.layers.length+')';
  b.onclick=function(){snap();it.layers.forEach(function(l){L.push(blank(JSON.parse(JSON.stringify(l))))});sel=L.length-1;dirty=true;all()};
  b.ondblclick=function(){var v=window.prompt('Name',it.name);if(v&&v.trim())libCall('tpl','rename',{item:it.id,name:v.trim()})};
  li.lastChild.onclick=function(){if(window.confirm('Remove template "'+it.name+'"?'))libCall('tpl','delete',{item:it.id})};t.appendChild(li)})}
function clean(o){return JSON.parse(JSON.stringify(o,function(k,v){return k.charAt(0)==='_'?undefined:v}))}
$('spa-elsave').onclick=function(){var l=L[sel];if(!l||l.type==='canvas'){say('Pick a layer first, then press + to keep it.');return}
 flush().then(function(){libCall('el','save',{data:JSON.stringify({name:lname(l),layer:clean(l)})}).then(function(j){if(j&&j.success)say('Kept in Elements.')})},function(e){say('Could not keep it: '+(e&&e.message||''))})};
$('spa-tplsave').onclick=function(){var ls=L.filter(function(l){return l.type!=='canvas'});if(!ls.length){say('Add layers above the canvas first.');return}var v=window.prompt('Name this template',($('spa-name').value||'Template'));if(!v||!v.trim())return;
 flush().then(function(){libCall('tpl','save',{data:JSON.stringify({name:v.trim(),layers:clean(L.filter(function(l){return l.type!=='canvas'}))})}).then(function(j){if(j&&j.success)say('Template saved.')})},function(e){say('Could not save it: '+(e&&e.message||''))})};
function addLogo(lg){var im=new Image();im.onload=function(){var ar=im.naturalWidth/im.naturalHeight,w=ar>=1?34:20,h=w/ar;if(h>34){h=34;w=h*ar}snap();var l=blank({type:'image',name:lg.name+' logo',src:lg.url,x:3,y:Math.max(0,100-h-3),w:w,h:h});L.push(l);sel=L.length-1;dirty=true;all()};im.onerror=function(){say('That logo could not be loaded.')};im.src=lg.url}
function logoDraw(){var e=$('spa-logos');e.innerHTML='';var ls=LIB.logo||[];if(!ls.length){e.innerHTML='<p class="spe-help">Press + or drop logo pictures here. These are only for the art, separate from the pictures on People.</p>';return}
 ls.forEach(function(lg){var b=document.createElement('div');b.className='spa-el';b.title=lg.name+' (click to add as an editable layer, double-click to rename, × to remove)';var btn=document.createElement('button');btn.type='button';var im=document.createElement('img');im.src=lg.url;im.alt=lg.name;im.loading='lazy';btn.appendChild(im);var x=document.createElement('i');x.textContent='×';b.appendChild(btn);b.appendChild(x);
  btn.onclick=function(){addLogo(lg)};btn.ondblclick=function(){var v=window.prompt('Name',lg.name);if(v&&v.trim())libCall('logo','rename',{item:lg.id,name:v.trim()})};
  x.onclick=function(ev){ev.stopPropagation();if(window.confirm('Remove "'+lg.name+'" from Band logos? Art already using it keeps it.'))libCall('logo','delete',{item:lg.id})};e.appendChild(b)})}
function uploadLogos(files){var list=Array.prototype.slice.call(files).filter(function(f){return /^image\//.test(f.type)});if(!list.length){say('Choose picture files.');return}
 list.reduce(function(pr,f){return pr.then(function(){say('Uploading '+f.name+'…');var fd=new FormData();fd.append('action','songpress_upload');fd.append('nonce',C.upload);fd.append('file',f);
  return fetch(C.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){if(!j.success){say((j.data&&j.data.message)||'Upload failed');return}
   return libCall('logo','save',{data:JSON.stringify({name:f.name.replace(/\.[^.]+$/,'').replace(/[-_]+/g,' ').trim()||'Logo',url:j.data.url})})})})},Promise.resolve()).then(function(){say('Logos saved.')},function(){say('Upload failed')})}
$('spa-logofile').onchange=function(e){uploadLogos(e.target.files);e.target.value=''};
(function(){var e=$('spa-logos');['dragenter','dragover'].forEach(function(n){e.addEventListener(n,function(ev){if(ev.dataTransfer&&[].indexOf.call(ev.dataTransfer.types||[],'Files')>-1){ev.preventDefault();e.classList.add('over')}})});e.addEventListener('dragleave',function(){e.classList.remove('over')});e.addEventListener('drop',function(ev){e.classList.remove('over');if(ev.dataTransfer&&ev.dataTransfer.files&&ev.dataTransfer.files.length){ev.preventDefault();uploadLogos(ev.dataTransfer.files)}})})();
libCall('el','list');
setTool('move');all();
})();
JS;
	}
}
