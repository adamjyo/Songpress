<?php
/**
 * Radio: playlists of works that have audio, played one after another.
 *
 * A playlist belongs to one person. They can share it so everyone who can see the works can play it. There is also
 * "Everything", a shuffled playlist of every work you can see that has audio. Playing is done in the browser with one
 * audio element; the list, the edits and the sharing are saved through admin-ajax (admin-post is blocked on some hosts).
 *
 * @package SongPress
 */

defined( 'ABSPATH' ) || exit;

class Songpress_Radio {

	const NAME_MAX = 120;
	const ALL_MAX  = 300;

	public static function init() {
		add_action( 'wp_ajax_songpress_radio', array( __CLASS__, 'ajax' ) );
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'sp_radio' => 1 ), $args ), Songpress_Editor::list_url() );
	}

	/* ---------- data ---------- */

	private static function t( $n ) {
		return Songpress_Install::table( $n );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'playlists' ) . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	public static function visible( $pl ) {
		return $pl && ( (int) $pl->user_id === get_current_user_id() || (int) $pl->shared );
	}

	public static function can_edit( $pl ) {
		return $pl && ( (int) $pl->user_id === get_current_user_id() || Songpress_Caps::can( 'settings' ) );
	}

	/** Mine first, then the ones other people shared. */
	public static function lists() {
		global $wpdb;
		$uid = get_current_user_id();
		return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT p.*, (SELECT COUNT(*) FROM ' . self::t( 'playlist_songs' ) . ' x WHERE x.playlist_id = p.id) AS n FROM ' . self::t( 'playlists' ) . ' p WHERE p.user_id = %d OR p.shared = 1 ORDER BY (p.user_id = %d) DESC, p.name ASC',
			$uid,
			$uid
		) );
	}

	/** Songs in a playlist, in order, that this person may see and that have audio. */
	public static function tracks( $id ) {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT song_id FROM ' . self::t( 'playlist_songs' ) . ' WHERE playlist_id = %d ORDER BY position ASC, id ASC', (int) $id ) ) as $sid ) { // phpcs:ignore WordPress.DB
			$s = Songpress_Songs::get( (int) $sid );
			if ( $s && Songpress_Caps::can_see_song( (int) $s->id ) && '' !== Songpress_Media::audio_url( $s ) ) {
				$out[] = $s;
			}
		}
		return $out;
	}

	/** Every work this person can see that has audio. */
	public static function everything() {
		$res = Songpress_Songs::query( array( 'per_page' => self::ALL_MAX, 'order' => 'title', 'dir' => 'asc', 'user_id' => Songpress_Caps::can( 'view_all' ) ? 0 : get_current_user_id() ) );
		$out = array();
		foreach ( $res['rows'] as $s ) {
			if ( '' !== Songpress_Media::audio_url( $s ) ) {
				$out[] = $s;
			}
		}
		return $out;
	}

	private static function by( $s ) {
		foreach ( Songpress_Songs::credits( (int) $s->id ) as $c ) {
			if ( in_array( $c->role, array( 'band', 'artist' ), true ) && '' !== trim( (string) $c->name ) ) {
				return (string) $c->name;
			}
		}
		return '';
	}

	/* ---------- changes (admin-ajax) ---------- */

	public static function ajax() {
		check_ajax_referer( 'songpress_radio', 'n' );
		if ( ! is_user_logged_in() || ! Songpress_Caps::can_view() ) {
			wp_send_json_error( array( 'msg' => __( 'Not allowed.', 'songpress' ) ), 403 );
		}
		global $wpdb;
		$op   = sanitize_key( wp_unslash( $_POST['op'] ?? '' ) );
		$id   = absint( $_POST['id'] ?? 0 );
		$sid  = absint( $_POST['song'] ?? 0 );
		$pl   = $id ? self::get( $id ) : null;
		$pt   = self::t( 'playlists' );
		$st   = self::t( 'playlist_songs' );
		if ( 'create' === $op ) {
			$name = mb_substr( trim( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ) ), 0, self::NAME_MAX );
			if ( '' === $name ) {
				wp_send_json_error( array( 'msg' => __( 'Give the playlist a name.', 'songpress' ) ) );
			}
			$wpdb->insert( $pt, array( 'user_id' => get_current_user_id(), 'name' => $name, 'shared' => 0, 'created_at' => current_time( 'mysql' ) ) );
			wp_send_json_success( array( 'id' => (int) $wpdb->insert_id ) );
		}
		if ( ! $pl || ! self::can_edit( $pl ) ) {
			wp_send_json_error( array( 'msg' => __( 'That playlist is not yours to change.', 'songpress' ) ), 403 );
		}
		switch ( $op ) {
			case 'rename':
				$name = mb_substr( trim( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ) ), 0, self::NAME_MAX );
				if ( '' === $name ) {
					wp_send_json_error( array( 'msg' => __( 'Give the playlist a name.', 'songpress' ) ) );
				}
				$wpdb->update( $pt, array( 'name' => $name ), array( 'id' => $id ) );
				break;
			case 'share':
				$wpdb->update( $pt, array( 'shared' => empty( $_POST['on'] ) ? 0 : 1 ), array( 'id' => $id ) );
				break;
			case 'delete':
				$wpdb->delete( $st, array( 'playlist_id' => $id ) );
				$wpdb->delete( $pt, array( 'id' => $id ) );
				break;
			case 'add':
				$s = $sid ? Songpress_Songs::get( $sid ) : null;
				if ( ! $s || ! Songpress_Caps::can_see_song( $sid ) || '' === Songpress_Media::audio_url( $s ) ) {
					wp_send_json_error( array( 'msg' => __( 'That work has no audio you can play.', 'songpress' ) ) );
				}
				$pos = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(position), 0) FROM {$st} WHERE playlist_id = %d", $id ) ) + 1; // phpcs:ignore WordPress.DB
				$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$st} (playlist_id, song_id, position) VALUES (%d, %d, %d)", $id, $sid, $pos ) ); // phpcs:ignore WordPress.DB
				break;
			case 'remove':
				$wpdb->delete( $st, array( 'playlist_id' => $id, 'song_id' => $sid ) );
				break;
			case 'move':
				$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT song_id FROM {$st} WHERE playlist_id = %d ORDER BY position ASC, id ASC", $id ) ) ); // phpcs:ignore WordPress.DB
				$i   = array_search( $sid, $ids, true );
				$j   = false === $i ? false : $i + ( empty( $_POST['dir'] ) || (int) $_POST['dir'] < 0 ? -1 : 1 );
				if ( false !== $i && isset( $ids[ $j ] ) ) {
					$tmp = $ids[ $i ]; $ids[ $i ] = $ids[ $j ]; $ids[ $j ] = $tmp;
					foreach ( $ids as $k => $one ) {
						$wpdb->update( $st, array( 'position' => $k + 1 ), array( 'playlist_id' => $id, 'song_id' => $one ) );
					}
				}
				break;
			default:
				wp_send_json_error( array( 'msg' => __( 'Unknown action.', 'songpress' ) ) );
		}
		wp_send_json_success( array( 'ok' => 1 ) );
	}

	/* ---------- screens ---------- */

	private static function ico( $d ) {
		return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
	}

	public static function render() {
		$pid = isset( $_GET['sp_pl'] ) ? sanitize_text_field( wp_unslash( $_GET['sp_pl'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$o   = '<div class="sp-radio" data-n="' . esc_attr( wp_create_nonce( 'songpress_radio' ) ) . '" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '">';
		if ( 'all' === $pid ) {
			$o .= self::playlist_view( null, self::everything(), true );
		} elseif ( '' !== $pid ) {
			$pl = self::get( absint( $pid ) );
			if ( ! self::visible( $pl ) ) {
				$o .= '<p class="songpress-note">' . esc_html__( 'That playlist is not available.', 'songpress' ) . '</p><p><a class="sp-rad-btn" href="' . esc_url( self::url() ) . '">&lsaquo; ' . esc_html__( 'Radio', 'songpress' ) . '</a></p>';
			} else {
				$o .= self::playlist_view( $pl, self::tracks( $pl->id ), false );
			}
		} else {
			$o .= self::index();
		}
		return $o . '</div>' . self::script();
	}

	private static function index() {
		$o  = '<h2 class="sp-rad-h">' . esc_html__( 'Radio', 'songpress' ) . '</h2>';
		$o .= '<ul class="sp-rad-lists"><li><a href="' . esc_url( self::url( array( 'sp_pl' => 'all' ) ) ) . '"><span class="ico">' . self::ico( '<circle cx="12" cy="12" r="2"/><path d="M8 8a5.7 5.7 0 000 8M16 8a5.7 5.7 0 010 8M5 5a10 10 0 000 14M19 5a10 10 0 010 14"/>' ) . '</span><span><b>' . esc_html__( 'Everything', 'songpress' ) . '</b><small>' . esc_html__( 'Every work with audio, shuffled', 'songpress' ) . '</small></span></a></li>';
		foreach ( self::lists() as $p ) {
			$mine = (int) $p->user_id === get_current_user_id();
			$who  = $mine ? '' : ' · ' . esc_html( (string) get_the_author_meta( 'display_name', (int) $p->user_id ) );
			$o   .= '<li><a href="' . esc_url( self::url( array( 'sp_pl' => (int) $p->id ) ) ) . '"><span class="ico">' . self::ico( '<path d="M4 6h12M4 12h12M4 18h8M18 15V5l3 1"/><circle cx="16" cy="18" r="2"/>' ) . '</span><span><b>' . esc_html( $p->name ) . '</b><small>' . esc_html( sprintf( /* translators: %d: number of works */ _n( '%d work', '%d works', (int) $p->n, 'songpress' ), (int) $p->n ) ) . ( (int) $p->shared && $mine ? ' · ' . esc_html__( 'shared', 'songpress' ) : '' ) . $who . '</small></span></a></li>';
		}
		$o .= '</ul>';
		$o .= '<form class="sp-rad-new" data-op="create"><input type="text" name="name" maxlength="' . self::NAME_MAX . '" placeholder="' . esc_attr__( 'New playlist name', 'songpress' ) . '" aria-label="' . esc_attr__( 'New playlist name', 'songpress' ) . '"><button type="submit" class="sp-rad-btn pri">' . esc_html__( 'Make playlist', 'songpress' ) . '</button></form><p class="sp-rad-msg" aria-live="polite"></p>';
		return $o;
	}

	private static function playlist_view( $pl, array $tracks, $shuffle ) {
		$edit = $pl && self::can_edit( $pl );
		$name = $pl ? $pl->name : __( 'Everything', 'songpress' );
		$o    = '<p class="sp-rad-back"><a href="' . esc_url( self::url() ) . '">&lsaquo; ' . esc_html__( 'Radio', 'songpress' ) . '</a></p><h2 class="sp-rad-h">' . esc_html( $name ) . '</h2>';
		$o   .= '<div class="sp-rad-player" data-shuffle="' . ( $shuffle ? '1' : '0' ) . '"><div class="np"><span class="art">&#9835;</span><span class="tx"><b class="t">' . esc_html__( 'Pick a work to play', 'songpress' ) . '</b><small class="s"></small></span></div>'
			. '<div class="bar"><input type="range" class="seek" min="0" max="1000" value="0" aria-label="' . esc_attr__( 'Position', 'songpress' ) . '"><span class="time">0:00</span></div>'
			. '<div class="ctl"><button type="button" class="sh" aria-label="' . esc_attr__( 'Shuffle', 'songpress' ) . '" aria-pressed="' . ( $shuffle ? 'true' : 'false' ) . '">' . self::ico( '<path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/>' ) . '</button><button type="button" class="pv" aria-label="' . esc_attr__( 'Previous', 'songpress' ) . '">' . self::ico( '<path d="M6 5v14M19 5l-9 7 9 7z"/>' ) . '</button><button type="button" class="pp" aria-label="' . esc_attr__( 'Play', 'songpress' ) . '">' . self::ico( '<path d="M7 4l13 8-13 8z"/>' ) . '</button><button type="button" class="nx" aria-label="' . esc_attr__( 'Next', 'songpress' ) . '">' . self::ico( '<path d="M18 5v14M5 5l9 7-9 7z"/>' ) . '</button></div><audio preload="none"></audio></div>';
		if ( ! $tracks ) {
			$o .= '<p class="songpress-note">' . esc_html( $edit ? __( 'No works here yet. Add some below.', 'songpress' ) : __( 'Nothing to play here yet.', 'songpress' ) ) . '</p>';
		}
		$o .= '<ol class="sp-rad-tracks">';
		$last = count( $tracks ) - 1;
		foreach ( $tracks as $i => $s ) {
			$art = Songpress_Media::art_url( $s, 'thumbnail' );
			$o  .= '<li data-src="' . esc_url( Songpress_Media::audio_url( $s ) ) . '" data-title="' . esc_attr( Songpress_Songs::display_title( $s ) ) . '" data-by="' . esc_attr( self::by( $s ) ) . '" data-art="' . esc_url( $art ) . '" data-id="' . (int) $s->id . '"><button type="button" class="go">' . ( '' !== $art ? '<img src="' . esc_url( $art ) . '" alt="" loading="lazy">' : '<span class="noart">&#9835;</span>' ) . '<span class="tx"><b>' . esc_html( Songpress_Songs::display_title( $s ) ) . '</b><small>' . esc_html( self::by( $s ) ) . '</small></span></button>';
			if ( $edit ) {
				$o .= '<span class="ed"><button type="button" class="mv" data-d="-1" aria-label="' . esc_attr__( 'Move up', 'songpress' ) . '"' . ( 0 === $i ? ' disabled' : '' ) . '>&uarr;</button><button type="button" class="mv" data-d="1" aria-label="' . esc_attr__( 'Move down', 'songpress' ) . '"' . ( $i === $last ? ' disabled' : '' ) . '>&darr;</button><button type="button" class="rm" aria-label="' . esc_attr__( 'Remove from playlist', 'songpress' ) . '">&times;</button></span>';
			}
			$o .= '</li>';
		}
		$o .= '</ol><p class="sp-rad-msg" aria-live="polite"></p>';
		if ( $edit ) {
			$o .= '<div class="sp-rad-edit" data-id="' . (int) $pl->id . '"><h3>' . esc_html__( 'This playlist', 'songpress' ) . '</h3>'
				. '<form class="sp-rad-rename" data-op="rename"><input type="text" name="name" maxlength="' . self::NAME_MAX . '" value="' . esc_attr( $pl->name ) . '" aria-label="' . esc_attr__( 'Name', 'songpress' ) . '"><button class="sp-rad-btn" type="submit">' . esc_html__( 'Rename', 'songpress' ) . '</button></form>'
				. '<label class="sp-rad-share"><input type="checkbox" class="share"' . ( (int) $pl->shared ? ' checked' : '' ) . '> ' . esc_html__( 'Share with everyone who can see these works', 'songpress' ) . '</label>'
				. '<h3>' . esc_html__( 'Add works', 'songpress' ) . '</h3>' . self::picker( $pl )
				. '<p><button type="button" class="sp-rad-btn danger del" data-arm="' . esc_attr__( 'Tap again to delete the playlist', 'songpress' ) . '">' . esc_html__( 'Delete playlist', 'songpress' ) . '</button></p></div>';
		}
		return $o;
	}

	/** A search box and a short list of works with audio that are not in the playlist yet. */
	private static function picker( $pl ) {
		global $wpdb;
		$q   = isset( $_GET['sp_q'] ) ? sanitize_text_field( wp_unslash( $_GET['sp_q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$res = Songpress_Songs::query( array( 'search' => $q, 'per_page' => 60, 'order' => 'title', 'dir' => 'asc', 'user_id' => Songpress_Caps::can( 'view_all' ) ? 0 : get_current_user_id() ) );
		$in  = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT song_id FROM ' . self::t( 'playlist_songs' ) . ' WHERE playlist_id = %d', (int) $pl->id ) ) ); // phpcs:ignore WordPress.DB
		$o   = '<form method="get" class="sp-rad-find"><input type="hidden" name="sp_radio" value="1"><input type="hidden" name="sp_pl" value="' . (int) $pl->id . '"><input type="search" name="sp_q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Search works', 'songpress' ) . '" aria-label="' . esc_attr__( 'Search works', 'songpress' ) . '"><button class="sp-rad-btn" type="submit">' . esc_html__( 'Search', 'songpress' ) . '</button></form><ul class="sp-rad-pick">';
		$n   = 0;
		foreach ( $res['rows'] as $s ) {
			if ( in_array( (int) $s->id, $in, true ) || '' === Songpress_Media::audio_url( $s ) ) {
				continue;
			}
			$o .= '<li><span class="tx"><b>' . esc_html( Songpress_Songs::display_title( $s ) ) . '</b><small>' . esc_html( self::by( $s ) ) . '</small></span><button type="button" class="sp-rad-btn add" data-song="' . (int) $s->id . '" aria-label="' . esc_attr( sprintf( /* translators: %s: title */ __( 'Add %s', 'songpress' ), $s->title ) ) . '">+</button></li>';
			++$n;
		}
		return $o . '</ul>' . ( 0 === $n ? '<p class="songpress-note">' . esc_html__( 'No more works with audio match.', 'songpress' ) . '</p>' : '' );
	}

	/** The player and the buttons. Printed once. */
	private static function script() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		return '<script src="' . esc_url( SONGPRESS_URL . 'assets/radio.js?v=' . SONGPRESS_VERSION ) . '"></script>';
	}
}
