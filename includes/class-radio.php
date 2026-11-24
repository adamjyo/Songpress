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

	/** Every released work this person can see that has audio, newest release first. */
	public static function everything() {
		$res   = Songpress_Songs::query( array( 'per_page' => self::ALL_MAX, 'order' => 'release', 'dir' => 'desc', 'user_id' => Songpress_Caps::can( 'view_all' ) ? 0 : get_current_user_id() ) );
		$today = current_time( 'Y-m-d' );
		$out   = array();
		foreach ( $res['rows'] as $s ) {
			$d = trim( (string) $s->release_date );
			if ( '' !== $d && $d <= $today && '' !== Songpress_Media::audio_url( $s ) ) { // released works only, newest first
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
			Songpress_Usage::log( 'playlist_new' );
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
				Songpress_Usage::log( 'playlist_add', $sid );
				break;
			case 'remove':
				$wpdb->delete( $st, array( 'playlist_id' => $id, 'song_id' => $sid ) );
				break;
			case 'order': // the whole order at once, after a drag
				$ids = array_values( array_unique( array_filter( array_map( 'intval', explode( ',', (string) wp_unslash( $_POST['ids'] ?? '' ) ) ) ) ) );
				$own = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT song_id FROM {$st} WHERE playlist_id = %d", $id ) ) ); // phpcs:ignore WordPress.DB
				foreach ( array_values( array_intersect( $ids, $own ) ) as $k => $one ) {
					$wpdb->update( $st, array( 'position' => $k + 1 ), array( 'playlist_id' => $id, 'song_id' => $one ) );
				}
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

	/* ---------- built-in playlists ---------- */

	/** The 25 most recently released works this person can see that have audio. */
	public static function recent() {
		$res = Songpress_Songs::query( array( 'per_page' => 300, 'order' => 'release', 'dir' => 'desc', 'user_id' => Songpress_Caps::can( 'view_all' ) ? 0 : get_current_user_id() ) );
		$today = current_time( 'Y-m-d' );
		$out   = array();
		foreach ( $res['rows'] as $s ) {
			$d = trim( (string) $s->release_date );
			if ( '' === $d || $d > $today || '' === Songpress_Media::audio_url( $s ) ) {
				continue;
			}
			$out[] = $s;
			if ( count( $out ) >= 25 ) {
				break;
			}
		}
		return $out;
	}

	/** The works this person favorited that they can still play, newest favorite first. */
	public static function favorites() {
		$out = array();
		foreach ( Songpress_Reactions::favorite_ids() as $sid ) {
			$s = Songpress_Songs::get( $sid );
			if ( $s && Songpress_Caps::can_see_song( $sid ) && '' !== Songpress_Media::audio_url( $s ) ) {
				$out[] = $s;
			}
		}
		return $out;
	}

	/* ---------- the screen ---------- */

	/** One page holds every list, so the music keeps playing while you move between lists and the player. */
	public static function render() {
		$me     = get_current_user_id();
		$tracks = array();
		$add    = function ( $s ) use ( &$tracks ) {
			$id = (int) $s->id;
			if ( ! isset( $tracks[ $id ] ) ) {
				$tok = (string) $s->share_token;
				$tracks[ $id ] = array(
					't'  => Songpress_Songs::display_title( $s ),
					'n'  => (string) $s->serial,
					'g'  => (string) $s->genre,
					'b'  => self::by( $s ),
					'a'  => Songpress_Media::art_url( $s, 'medium' ),
					's'  => Songpress_Media::audio_url( $s ),
					'sh' => '' !== $tok ? add_query_arg( 'sp_share', $tok, home_url( '/' ) ) : '',
					'u'  => add_query_arg( 'sp_song', $id, Songpress_Editor::list_url() ),
				);
			}
			return $id;
		};
		$ids   = function ( array $rows ) use ( $add ) {
			return array_map( $add, $rows );
		};
		$lists   = array();
		$lists[] = array( 'k' => 'all', 'name' => __( 'All releases', 'songpress' ), 'ids' => $ids( self::everything() ), 'b' => 'all' );
		$lists[] = array( 'k' => 'new', 'name' => __( 'Recent releases', 'songpress' ), 'ids' => $ids( self::recent() ), 'b' => 'new' );
		$lists[] = array( 'k' => 'fav', 'name' => __( 'Favorites', 'songpress' ), 'ids' => $ids( self::favorites() ), 'b' => 'fav' );
		foreach ( self::lists() as $p ) {
			$lists[] = array(
				'k'      => 'p' . (int) $p->id,
				'id'     => (int) $p->id,
				'name'   => (string) $p->name,
				'shared' => (int) $p->shared,
				'mine'   => (int) $p->user_id === $me,
				'by'     => (int) $p->user_id === $me ? '' : (string) get_the_author_meta( 'display_name', (int) $p->user_id ),
				'edit'   => self::can_edit( $p ),
				'ids'    => $ids( self::tracks( $p->id ) ),
			);
		}
		$state = Songpress_Reactions::bulk( array_keys( $tracks ) );
		$cfg   = array(
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'n'      => wp_create_nonce( 'songpress_radio' ),
			'un'     => wp_create_nonce( 'songpress_use' ),
			'rn'     => wp_create_nonce( 'songpress_react' ),
			'tracks' => (object) $tracks,
			'st'     => (object) $state,
			'lists'  => $lists,
			'max'    => self::NAME_MAX,
			'i'      => self::strings(),
		);
		return '<div class="sp-rd" id="sp-rd"><noscript><p class="songpress-note">' . esc_html__( 'Radio needs JavaScript.', 'songpress' ) . '</p></noscript></div><script>window.SP_RADIO=' . wp_json_encode( $cfg ) . ';</script>' . Songpress_Reactions::boot() . '<script src="' . esc_url( SONGPRESS_URL . 'assets/radio.js?v=' . SONGPRESS_VERSION ) . '" defer></script>';
	}

	private static function strings() {
		return array(
			'radio' => __( 'Radio', 'songpress' ), 'builtin' => __( 'Playlists', 'songpress' ), 'mine' => __( 'My playlists', 'songpress' ), 'shared' => __( 'Shared with you', 'songpress' ), 'sharedtag' => __( 'Shared', 'songpress' ),
			'songs' => __( 'songs', 'songpress' ), 'song1' => __( '1 song', 'songpress' ), 'by' => __( 'by', 'songpress' ), 'play' => __( 'Play', 'songpress' ), 'pause' => __( 'Pause', 'songpress' ), 'shuffle' => __( 'Shuffle', 'songpress' ), 'edit' => __( 'Edit', 'songpress' ), 'done' => __( 'Done', 'songpress' ),
			'newpl' => __( 'New playlist', 'songpress' ), 'name' => __( 'Name', 'songpress' ), 'create' => __( 'Create', 'songpress' ), 'share' => __( 'Share with everyone who can see these works', 'songpress' ),
			'remove' => __( 'Remove', 'songpress' ), 'drag' => __( 'Drag to reorder', 'songpress' ), 'addworks' => __( 'Add works', 'songpress' ), 'search' => __( 'Search works', 'songpress' ), 'nomore' => __( 'No more works with audio match.', 'songpress' ),
			'del' => __( 'Delete playlist', 'songpress' ), 'delarm' => __( 'Tap again to delete the playlist', 'songpress' ), 'empty' => __( 'Nothing here yet.', 'songpress' ), 'emptyfav' => __( 'Tap the heart on a song and it lands here.', 'songpress' ), 'emptyedit' => __( 'No works yet. Add some below.', 'songpress' ),
			'allsub' => __( 'Every released song', 'songpress' ), 'newsub' => __( 'The 25 newest releases', 'songpress' ), 'favsub' => __( 'Songs you hearted', 'songpress' ),
			'prev' => __( 'Previous', 'songpress' ), 'next' => __( 'Next', 'songpress' ), 'close' => __( 'Close player', 'songpress' ), 'open' => __( 'Open player', 'songpress' ),
			'fav' => __( 'Favorite', 'songpress' ), 'unfav' => __( 'Remove favorite', 'songpress' ), 'rate' => __( 'Rate', 'songpress' ), 'more' => __( 'More', 'songpress' ), 'vol' => __( 'Volume', 'songpress' ), 'pos' => __( 'Position', 'songpress' ),
			'shon' => __( 'Shuffle on', 'songpress' ), 'shoff' => __( 'Shuffle off', 'songpress' ), 'rpoff' => __( 'Repeat off', 'songpress' ), 'rpall' => __( 'Repeat all', 'songpress' ), 'rpone' => __( 'Repeat one', 'songpress' ), 'queue' => __( 'Up next', 'songpress' ), 'nothing' => __( 'Nothing playing', 'songpress' ),
			'err' => __( 'Could not save that.', 'songpress' ), 'skip' => __( 'Could not play that one. Skipping.', 'songpress' ), 'tap' => __( 'Tap play to start.', 'songpress' ), 'back' => __( 'Back', 'songpress' ),
		);
	}
}
