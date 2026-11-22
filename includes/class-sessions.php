<?php
defined( 'ABSPATH' ) || exit;

/**
 * Writing sessions, inside the SongPress app: a dated get-together with its people, notes, lyrics, an audio link
 * and a chat. Managers see every session; a writer sees the sessions they are in. The desktop app edits and chats;
 * the old S&P Catalog sessions and their chat can be loaded once from its file.
 */
class Songpress_Sessions {

	public static function init() {
		add_action( 'admin_post_songpress_session_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_songpress_session_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_songpress_session_chat', array( __CLASS__, 'handle_chat' ) );
	}

	private static function t( $n = 'sessions' ) {
		return Songpress_Install::table( $n );
	}

	/* ---------- pure helpers ---------- */

	/** Lines like "Adam Page | 123456789 | 50" into people. Blank lines are skipped. */
	public static function parse_people( $text ) {
		$out = array();
		foreach ( preg_split( '/\r?\n/', (string) $text ) as $line ) {
			$p = array_map( 'trim', explode( '|', $line ) );
			if ( '' === $p[0] ) {
				continue;
			}
			$out[] = array( 'name' => $p[0], 'ipi' => $p[1] ?? '', 'share' => $p[2] ?? '' );
		}
		return $out;
	}

	public static function people_text( array $people ) {
		$lines = array();
		foreach ( $people as $p ) {
			$lines[] = rtrim( $p->name . ' | ' . $p->ipi . ' | ' . $p->share, ' |' );
		}
		return implode( "\n", $lines );
	}

	/** A chat line for display. Old lyric edits were saved as {"from":"...","to":"..."}. */
	public static function chat_text( $kind, $body ) {
		if ( 'lyric' === $kind ) {
			$j = json_decode( (string) $body, true );
			if ( is_array( $j ) ) {
				$from = (string) ( $j['from'] ?? '' );
				$to   = (string) ( $j['to'] ?? '' );
				return '' === $from ? 'Lyric: ' . $to : 'Lyric changed: "' . $from . '" to "' . $to . '"';
			}
		}
		return (string) $body;
	}

	/* ---------- access ---------- */

	public static function can_see( $sid, $user_id = 0 ) {
		global $wpdb;
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( Songpress_Caps::can( 'edit_any', $user_id ) ) {
			return true;
		}
		$s = $wpdb->get_row( $wpdb->prepare( 'SELECT owner_user_id FROM ' . self::t() . ' WHERE id = %d', $sid ) ); // phpcs:ignore WordPress.DB
		if ( ! $s ) {
			return false;
		}
		if ( (int) $s->owner_user_id === (int) $user_id ) {
			return true;
		}
		$u   = get_userdata( $user_id );
		$key = $u ? Songpress_People::key_for( $u->display_name ) : '';
		$n   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'session_people' ) . ' WHERE session_id = %d AND ( user_id = %d OR person_key = %s )', $sid, $user_id, $key ) ); // phpcs:ignore WordPress.DB
		return $n > 0;
	}

	public static function people( $sid ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'session_people' ) . ' WHERE session_id = %d ORDER BY id', $sid ) ); // phpcs:ignore WordPress.DB
	}

	public static function chat( $sid ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'session_chat' ) . ' WHERE session_id = %d ORDER BY created_at, id', $sid ) ); // phpcs:ignore WordPress.DB
	}

	private static function set_people( $sid, array $people ) {
		global $wpdb;
		$wpdb->delete( self::t( 'session_people' ), array( 'session_id' => $sid ) );
		$seen = array();
		foreach ( $people as $p ) {
			$k = Songpress_People::key_for( $p['name'] );
			if ( '' === $k || isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$uid        = 0;
			$found      = get_users( array( 'meta_key' => Songpress_People::KEY_META, 'meta_value' => $k, 'number' => 1, 'fields' => 'ID' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			if ( $found ) {
				$uid = (int) $found[0];
			}
			$wpdb->insert( self::t( 'session_people' ), array( 'session_id' => $sid, 'name' => $p['name'], 'person_key' => $k, 'user_id' => $uid, 'ipi' => $p['ipi'], 'share' => $p['share'] ) );
		}
	}

	/* ---------- import ---------- */

	/** Old writing sessions, their writers and chat. Safe to run again: sessions and chat lines already here are kept. */
	public static function import_pdo( PDO $pdo ) {
		global $wpdb;
		$has = $pdo->query( "SELECT 1 FROM sqlite_master WHERE type='table' AND name='writing_sessions'" )->fetchColumn();
		if ( ! $has ) {
			return 0;
		}
		$n   = 0;
		$tbl = Songpress_Install::table( 'songs' );
		$cols = array();
		foreach ( $pdo->query( 'PRAGMA table_info(writing_sessions)' ) as $c ) {
			$cols[ $c['name'] ] = true;
		}
		foreach ( $pdo->query( 'SELECT * FROM writing_sessions ORDER BY id' ) as $r ) {
			$key = 'old:' . (int) $r['id'];
			$sid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t() . ' WHERE source_key = %s', $key ) ); // phpcs:ignore WordPress.DB
			if ( ! $sid ) {
				$serial  = trim( (string) ( $r['work_serial'] ?? '' ) );
				$song_id = '' === $serial ? 0 : (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tbl} WHERE serial = %s", $serial ) ); // phpcs:ignore WordPress.DB
				$wpdb->insert( self::t(), array(
					'session_date' => preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $r['session_date'] ) ? substr( $r['session_date'], 0, 10 ) : gmdate( 'Y-m-d' ),
					'session_time' => substr( (string) ( $r['session_time'] ?? '' ), 0, 8 ),
					'title' => (string) $r['title'], 'song_id' => $song_id, 'location' => (string) $r['location'],
					'notes' => (string) $r['notes'], 'lyrics' => (string) $r['lyrics'], 'audio_url' => (string) ( $r['audio_url'] ?? '' ),
					'owner_user_id' => 0, 'source_key' => $key,
					'created_at' => $r['created_at'] ? (string) $r['created_at'] : current_time( 'mysql' ), 'updated_at' => $r['updated_at'] ? (string) $r['updated_at'] : current_time( 'mysql' ),
				) );
				$sid = (int) $wpdb->insert_id;
				++$n;
				$ppl = array();
				for ( $i = 1; $i <= 10; $i++ ) {
					if ( ! empty( $cols[ 'writer_name_' . $i ] ) && '' !== trim( (string) $r[ 'writer_name_' . $i ] ) ) {
						$ppl[] = array( 'name' => trim( $r[ 'writer_name_' . $i ] ), 'ipi' => trim( (string) $r[ 'writer_ipi_' . $i ] ), 'share' => trim( (string) $r[ 'writer_share_' . $i ] ) );
					}
				}
				foreach ( preg_split( '/\s*,\s*/', (string) $r['writers'] ) as $w ) {
					$ppl[] = array( 'name' => $w, 'ipi' => '', 'share' => '' );
				}
				$mem = $pdo->prepare( 'SELECT name FROM writing_session_members WHERE session_id = ?' );
				$mem->execute( array( (int) $r['id'] ) );
				foreach ( $mem->fetchAll( PDO::FETCH_COLUMN ) as $w ) {
					$ppl[] = array( 'name' => (string) $w, 'ipi' => '', 'share' => '' );
				}
				self::set_people( $sid, $ppl );
			}
			$ch = $pdo->prepare( 'SELECT * FROM writing_session_chat WHERE session_id = ? ORDER BY id' );
			$ch->execute( array( (int) $r['id'] ) );
			foreach ( $ch->fetchAll( PDO::FETCH_ASSOC ) as $c ) {
				$ck = 'old:' . (int) $c['id'];
				if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'session_chat' ) . ' WHERE source_key = %s', $ck ) ) ) { // phpcs:ignore WordPress.DB
					continue;
				}
				$wpdb->insert( self::t( 'session_chat' ), array( 'session_id' => $sid, 'author' => (string) $c['author'], 'kind' => in_array( $c['kind'], array( 'text', 'lyric', 'audio' ), true ) ? $c['kind'] : 'text', 'body' => (string) $c['body'], 'audio_url' => (string) $c['audio_url'], 'source_key' => $ck, 'created_at' => (string) $c['created_at'] ) );
			}
		}
		return $n;
	}

	/* ---------- handlers ---------- */

	private static function guard( $nonce ) {
		check_admin_referer( $nonce );
		if ( ! Songpress_Caps::can( 'sessions' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
	}

	private static function go( $args = array() ) {
		$ret  = isset( $_POST['sp_return'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['sp_return'] ) ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked by guard().
		$base = '' !== $ret ? $ret : Songpress_Editor::list_url();
		wp_safe_redirect( add_query_arg( array_merge( array( 'sp_page' => 'sessions' ), $args ), $base ) );
		exit;
	}

	public static function handle_save() {
		self::guard( 'songpress_session_save' );
		global $wpdb;
		$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- cleaned below.
		$id = absint( $in['session_id'] ?? 0 );
		if ( $id && ! self::can_see( $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		$ser = trim( (string) ( $in['serial'] ?? '' ) );
		$sid = '' === $ser ? 0 : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Songpress_Install::table( 'songs' ) . ' WHERE serial = %s', $ser ) ); // phpcs:ignore WordPress.DB
		$row = array(
			'session_date' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $in['session_date'] ?? '' ) ) ? $in['session_date'] : gmdate( 'Y-m-d' ),
			'session_time' => preg_match( '/^\d{1,2}:\d{2}$/', (string) ( $in['session_time'] ?? '' ) ) ? $in['session_time'] : '',
			'title'        => sanitize_text_field( $in['title'] ?? '' ),
			'song_id'      => $sid,
			'location'     => sanitize_text_field( $in['location'] ?? '' ),
			'notes'        => sanitize_textarea_field( $in['notes'] ?? '' ),
			'lyrics'       => sanitize_textarea_field( $in['lyrics'] ?? '' ),
			'audio_url'    => esc_url_raw( $in['audio_url'] ?? '' ),
			'updated_at'   => current_time( 'mysql' ),
		);
		if ( $id ) {
			$wpdb->update( self::t(), $row, array( 'id' => $id ) );
		} else {
			$wpdb->insert( self::t(), array_merge( $row, array( 'owner_user_id' => get_current_user_id(), 'created_at' => current_time( 'mysql' ) ) ) );
			$id = (int) $wpdb->insert_id;
		}
		$ppl = self::parse_people( $in['people'] ?? '' );
		$me  = get_userdata( get_current_user_id() );
		if ( ! $ppl && $me ) {
			$ppl[] = array( 'name' => $me->display_name, 'ipi' => '', 'share' => '' );
		}
		self::set_people( $id, $ppl );
		self::go( array( 'session' => $id, 's_ok' => __( 'Session saved.', 'songpress' ) ) );
	}

	public static function handle_delete() {
		self::guard( 'songpress_session_delete' );
		global $wpdb;
		$id = absint( $_POST['session_id'] ?? 0 );
		if ( $id && Songpress_Caps::can( 'edit_any' ) ) {
			$wpdb->delete( self::t(), array( 'id' => $id ) );
			$wpdb->delete( self::t( 'session_people' ), array( 'session_id' => $id ) );
			$wpdb->delete( self::t( 'session_chat' ), array( 'session_id' => $id ) );
		}
		self::go( array( 's_ok' => __( 'Session deleted.', 'songpress' ) ) );
	}

	public static function handle_chat() {
		self::guard( 'songpress_session_chat' );
		global $wpdb;
		$id   = absint( $_POST['session_id'] ?? 0 );
		$body = trim( sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) ) );
		if ( $id && '' !== $body && self::can_see( $id ) ) {
			$u = get_userdata( get_current_user_id() );
			$wpdb->insert( self::t( 'session_chat' ), array( 'session_id' => $id, 'author' => $u ? $u->display_name : '', 'user_id' => get_current_user_id(), 'kind' => 'text', 'body' => $body, 'created_at' => current_time( 'mysql' ) ) );
		}
		self::go( array( 'session' => $id ) );
	}

	/* ---------- screens ---------- */

	public static function render() {
		if ( ! Songpress_Caps::can( 'sessions' ) ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'You do not have access to sessions.', 'songpress' ) . '</p></div>';
		}
		$o = '<div class="songpress sp-app sp-editor sp-money spss"><div class="spe-top"><h2>' . esc_html__( 'Writing sessions', 'songpress' ) . '</h2></div>';
		if ( ! empty( $_GET['s_ok'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<p class="spe-note ok">' . esc_html( sanitize_text_field( wp_unslash( $_GET['s_ok'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		$one = isset( $_GET['session'] ) ? sanitize_text_field( wp_unslash( $_GET['session'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return $o . ( '' !== $one ? self::render_one( 'new' === $one ? 0 : absint( $one ) ) : self::render_list() ) . '</div>';
	}

	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'sp_page' => 'sessions' ), $args ), Songpress_Editor::list_url() );
	}

	private static function render_list() {
		global $wpdb;
		$q     = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$where = '1=1';
		$args  = array();
		if ( '' !== $q ) {
			$like   = '%' . $wpdb->esc_like( $q ) . '%';
			$where .= ' AND ( s.title LIKE %s OR s.notes LIKE %s OR s.lyrics LIKE %s OR s.location LIKE %s )';
			array_push( $args, $like, $like, $like, $like );
		}
		$sql = 'SELECT s.*, so.serial FROM ' . self::t() . ' s LEFT JOIN ' . Songpress_Install::table( 'songs' ) . " so ON so.id = s.song_id WHERE {$where} ORDER BY s.session_date DESC, s.id DESC LIMIT 300";
		$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore WordPress.DB
		$o   = '<p><a class="spe-save" href="' . esc_url( self::url( array( 'session' => 'new' ) ) ) . '">' . esc_html__( 'New session', 'songpress' ) . '</a></p>';
		$o  .= '<form method="get" class="spe-search"><input type="hidden" name="sp_page" value="sessions"><input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Search sessions', 'songpress' ) . '"> <button class="spe-btn" type="submit">' . esc_html__( 'Search', 'songpress' ) . '</button></form>';
		$shown = 0;
		$tb    = '<div class="spm-scroll"><table class="spm-table"><thead><tr><th>' . esc_html__( 'Date', 'songpress' ) . '</th><th>' . esc_html__( 'Session', 'songpress' ) . '</th><th>' . esc_html__( 'Song', 'songpress' ) . '</th><th>' . esc_html__( 'With', 'songpress' ) . '</th><th class="n">' . esc_html__( 'Chat', 'songpress' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			if ( ! self::can_see( (int) $r->id ) ) {
				continue;
			}
			++$shown;
			$names = array();
			foreach ( self::people( (int) $r->id ) as $p ) {
				$names[] = $p->name;
			}
			$cn  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'session_chat' ) . ' WHERE session_id = %d', $r->id ) ); // phpcs:ignore WordPress.DB
			$tb .= '<tr><td>' . esc_html( $r->session_date ) . '</td><td><a href="' . esc_url( self::url( array( 'session' => (int) $r->id ) ) ) . '">' . esc_html( '' !== $r->title ? $r->title : __( '(untitled)', 'songpress' ) ) . '</a></td><td>' . esc_html( (string) $r->serial ) . '</td><td>' . esc_html( implode( ', ', $names ) ) . '</td><td class="n">' . (int) $cn . '</td></tr>';
		}
		return $o . ( $shown ? $tb . '</tbody></table></div>' : '<p class="spe-help">' . esc_html__( 'No sessions yet.', 'songpress' ) . '</p>' );
	}

	private static function render_one( $id ) {
		global $wpdb;
		$s = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', $id ) ) : null; // phpcs:ignore WordPress.DB
		if ( $id && ( ! $s || ! self::can_see( $id ) ) ) {
			return '<p class="spe-note bad">' . esc_html__( 'That session was not found.', 'songpress' ) . '</p>';
		}
		$serial = '';
		if ( $s && (int) $s->song_id ) {
			$serial = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT serial FROM ' . Songpress_Install::table( 'songs' ) . ' WHERE id = %d', $s->song_id ) ); // phpcs:ignore WordPress.DB
		}
		$v = function ( $k, $d = '' ) use ( $s ) {
			return $s ? (string) $s->$k : $d;
		};
		$f = function ( $label, $html ) {
			return '<label class="spe-field"><span>' . esc_html( $label ) . '</span>' . $html . '</label>';
		};
		$o  = '<p><a href="' . esc_url( self::url() ) . '">&lsaquo; ' . esc_html__( 'All sessions', 'songpress' ) . '</a></p>';
		$o .= Songpress_Money::form_open( 'songpress_session_save', 'spss-form' ) . '<input type="hidden" name="session_id" value="' . (int) $id . '">';
		$o .= $f( __( 'Title', 'songpress' ), '<input name="title" value="' . esc_attr( $v( 'title' ) ) . '">' );
		$o .= $f( __( 'Date', 'songpress' ), '<input type="date" name="session_date" value="' . esc_attr( $v( 'session_date', gmdate( 'Y-m-d' ) ) ) . '">' ) . $f( __( 'Time', 'songpress' ), '<input type="time" name="session_time" value="' . esc_attr( $v( 'session_time' ) ) . '">' );
		$o .= $f( __( 'Where', 'songpress' ), '<input name="location" value="' . esc_attr( $v( 'location' ) ) . '">' ) . $f( __( 'Song serial (optional)', 'songpress' ), '<input name="serial" size="10" placeholder="0378" value="' . esc_attr( $serial ) . '">' );
		$o .= $f( __( 'People (one per line: Name | IPI | share)', 'songpress' ), '<textarea name="people" rows="4">' . esc_textarea( $s ? self::people_text( self::people( $id ) ) : '' ) . '</textarea>' );
		$o .= $f( __( 'Notes', 'songpress' ), '<textarea name="notes" rows="5">' . esc_textarea( $v( 'notes' ) ) . '</textarea>' ) . $f( __( 'Lyrics', 'songpress' ), '<textarea name="lyrics" rows="8">' . esc_textarea( $v( 'lyrics' ) ) . '</textarea>' );
		$o .= $f( __( 'Audio link (Media Library or any link)', 'songpress' ), '<input type="url" name="audio_url" value="' . esc_attr( $v( 'audio_url' ) ) . '">' );
		$o .= '<button class="spe-save" type="submit">' . esc_html__( 'Save session', 'songpress' ) . '</button></form>';
		if ( $s && '' !== $s->audio_url ) {
			$o .= '<audio controls preload="none" src="' . esc_url( $s->audio_url ) . '" style="width:100%;margin:12px 0"></audio>';
		}
		if ( $s ) {
			$o .= '<h3>' . esc_html__( 'Chat', 'songpress' ) . '</h3><div class="spss-chat">';
			foreach ( self::chat( $id ) as $c ) {
				$o .= '<p class="spss-msg"><b>' . esc_html( $c->author ) . '</b> <small>' . esc_html( $c->created_at ) . '</small><br>' . nl2br( esc_html( self::chat_text( $c->kind, $c->body ) ) ) . ( '' !== $c->audio_url ? '<br><audio controls preload="none" src="' . esc_url( $c->audio_url ) . '"></audio>' : '' ) . '</p>';
			}
			$o .= '</div>' . Songpress_Money::form_open( 'songpress_session_chat', 'spss-say' ) . '<input type="hidden" name="session_id" value="' . (int) $id . '"><textarea name="body" rows="2" required placeholder="' . esc_attr__( 'Say something', 'songpress' ) . '"></textarea> <button class="spe-save" type="submit">' . esc_html__( 'Send', 'songpress' ) . '</button></form>';
			if ( Songpress_Caps::can( 'edit_any' ) ) {
				$o .= Songpress_Money::form_open( 'songpress_session_delete', 'spss-del' ) . '<input type="hidden" name="session_id" value="' . (int) $id . '"><button class="spe-btn" type="submit" onclick="return confirm(\'' . esc_js( __( 'Delete this session and its chat?', 'songpress' ) ) . '\')">' . esc_html__( 'Delete session', 'songpress' ) . '</button></form>';
			}
		}
		return $o;
	}
}
