<?php
/**
 * Reactions: star ratings, favorites, and the menu that opens on a work (swipe a row to the left, or press and hold).
 *
 * Anyone can rate a work, including a visitor with no login on a public share page. A visitor is told apart by a random
 * id kept in their own browser, so it is one vote per browser, not a lock. Favorites and playlists need a login.
 * Everything is saved through admin-ajax (admin-post is blocked on some hosts).
 *
 * @package SongPress
 */

defined( 'ABSPATH' ) || exit;

class Songpress_Reactions {

	/** A visitor may change ratings this many times an hour from one address. */
	const VISITOR_LIMIT = 60;
	/** Show the average only once this many people have voted. */
	const MIN_VOTES = 2;

	public static function init() {
		add_action( 'wp_ajax_songpress_react', array( __CLASS__, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_songpress_react', array( __CLASS__, 'ajax' ) );
	}

	private static function t( $n ) {
		return Songpress_Install::table( $n );
	}

	/** Who is voting: "u:ID" for a person with a login, "v:TOKEN" for a visitor, '' if neither. */
	public static function voter( $visitor = '' ) {
		if ( is_user_logged_in() ) {
			return 'u:' . get_current_user_id();
		}
		$visitor = strtolower( (string) $visitor );
		return preg_match( '/^[a-z0-9]{16,40}$/', $visitor ) ? 'v:' . $visitor : '';
	}

	/* ---------- reading ---------- */

	/** One work: average, number of votes, my stars, whether I favorited it. */
	public static function one( $song_id, $voter = '' ) {
		$b = self::bulk( array( (int) $song_id ), $voter );
		return $b[ (int) $song_id ];
	}

	/** Many works at once: array( id => array( avg, n, mine, fav ) ). */
	public static function bulk( array $ids, $voter = '' ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = array( 'avg' => 0.0, 'n' => 0, 'mine' => 0, 'fav' => 0 );
		}
		if ( ! $ids ) {
			return $out;
		}
		$in = implode( ',', $ids ); // ints only
		foreach ( (array) $wpdb->get_results( 'SELECT song_id, AVG(stars) AS a, COUNT(*) AS n FROM ' . self::t( 'ratings' ) . " WHERE song_id IN ({$in}) GROUP BY song_id" ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ (int) $r->song_id ]['avg'] = round( (float) $r->a, 1 );
			$out[ (int) $r->song_id ]['n']   = (int) $r->n;
		}
		$voter = '' !== $voter ? $voter : self::voter();
		if ( '' !== $voter ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT song_id, stars FROM ' . self::t( 'ratings' ) . " WHERE voter = %s AND song_id IN ({$in})", $voter ) ) as $r ) { // phpcs:ignore WordPress.DB
				$out[ (int) $r->song_id ]['mine'] = (int) $r->stars;
			}
		}
		if ( is_user_logged_in() ) {
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT song_id FROM ' . self::t( 'favorites' ) . " WHERE user_id = %d AND song_id IN ({$in})", get_current_user_id() ) ) as $sid ) { // phpcs:ignore WordPress.DB
				$out[ (int) $sid ]['fav'] = 1;
			}
		}
		return $out;
	}

	/** Songs this person favorited, newest first (ids). */
	public static function favorite_ids() {
		global $wpdb;
		if ( ! is_user_logged_in() ) {
			return array();
		}
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT song_id FROM ' . self::t( 'favorites' ) . ' WHERE user_id = %d ORDER BY id DESC', get_current_user_id() ) ) ); // phpcs:ignore WordPress.DB
	}

	/** "4.3 from 7 votes", or "" when too few votes to show an average. */
	public static function avg_text( $avg, $n ) {
		if ( $n >= self::MIN_VOTES ) {
			/* translators: 1: average stars, 2: number of votes */
			return sprintf( __( 'Average %1$s from %2$d votes', 'songpress' ), number_format_i18n( $avg, 1 ), $n );
		}
		if ( 1 === $n ) {
			return __( '1 vote so far', 'songpress' );
		}
		return __( 'No votes yet', 'songpress' );
	}

	/* ---------- writing (admin-ajax) ---------- */

	private static function limited() {
		$k = 'sp_rx_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore
		$n = (int) get_transient( $k );
		if ( $n >= self::VISITOR_LIMIT ) {
			return true;
		}
		set_transient( $k, $n + 1, HOUR_IN_SECONDS );
		return false;
	}

	public static function ajax() {
		check_ajax_referer( 'songpress_react', 'n' );
		$op  = sanitize_key( wp_unslash( $_POST['op'] ?? '' ) );
		$sid = absint( $_POST['song'] ?? 0 );
		$s   = $sid ? Songpress_Songs::get( $sid ) : null;
		if ( ! $s ) {
			wp_send_json_error( array( 'msg' => __( 'That work was not found.', 'songpress' ) ), 404 );
		}
		$in = is_user_logged_in();
		if ( $in ) {
			if ( ! Songpress_Caps::can_see_song( $sid ) ) {
				wp_send_json_error( array( 'msg' => __( 'Not allowed.', 'songpress' ) ), 403 );
			}
		} else { // a visitor may only touch the work whose public link they are on
			$tok = sanitize_text_field( wp_unslash( $_POST['t'] ?? '' ) );
			$w   = '' !== $tok ? Songpress_Songs::by_token( $tok ) : null;
			if ( ! $w || (int) $w->id !== $sid || 'rate' !== $op ) {
				wp_send_json_error( array( 'msg' => __( 'Log in to do that.', 'songpress' ) ), 403 );
			}
			if ( self::limited() ) {
				wp_send_json_error( array( 'msg' => __( 'Too many changes. Try again later.', 'songpress' ) ), 429 );
			}
		}
		global $wpdb;
		$voter = self::voter( sanitize_text_field( wp_unslash( $_POST['v'] ?? '' ) ) );
		switch ( $op ) {
			case 'rate':
				if ( '' === $voter ) {
					wp_send_json_error( array( 'msg' => __( 'Could not tell who is voting.', 'songpress' ) ), 400 );
				}
				$stars = max( 0, min( 5, (int) ( $_POST['stars'] ?? 0 ) ) );
				$rt    = self::t( 'ratings' );
				if ( 0 === $stars ) {
					$wpdb->delete( $rt, array( 'song_id' => $sid, 'voter' => $voter ) );
				} else {
					$wpdb->query( $wpdb->prepare( "REPLACE INTO {$rt} (song_id, voter, stars, created_at) VALUES (%d, %s, %d, %s)", $sid, $voter, $stars, current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB
					Songpress_Usage::log( 'rate', $sid, $stars . ( $in ? '' : ' visitor' ) );
				}
				break;
			case 'fav':
				$ft = self::t( 'favorites' );
				$on = ! empty( $_POST['on'] );
				if ( $on ) {
					$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$ft} (user_id, song_id, created_at) VALUES (%d, %d, %s)", get_current_user_id(), $sid, current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB
					Songpress_Usage::log( 'fav', $sid );
				} else {
					Songpress_Usage::log( 'unfav', $sid );
					$wpdb->delete( $ft, array( 'user_id' => get_current_user_id(), 'song_id' => $sid ) );
				}
				break;
			case 'lists': // my playlists, and whether this work is in each
				$pt = self::t( 'playlists' );
				$st = self::t( 'playlist_songs' );
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.id, p.name, (SELECT COUNT(*) FROM {$st} x WHERE x.playlist_id = p.id) AS n, (SELECT COUNT(*) FROM {$st} y WHERE y.playlist_id = p.id AND y.song_id = %d) AS has FROM {$pt} p WHERE p.user_id = %d ORDER BY p.name ASC", $sid, get_current_user_id() ) ); // phpcs:ignore WordPress.DB
				$out = array();
				foreach ( (array) $rows as $r ) {
					$out[] = array( 'id' => (int) $r->id, 'name' => (string) $r->name, 'n' => (int) $r->n, 'has' => (int) $r->has > 0 );
				}
				wp_send_json_success( array( 'lists' => $out, 'audio' => '' !== Songpress_Media::audio_url( $s ) ) );
				break;
		}
		$one = self::one( $sid, $voter );
		$one['text'] = self::avg_text( $one['avg'], $one['n'] );
		wp_send_json_success( $one );
	}

	/* ---------- markup ---------- */

	private static function ico( $d, $fill = 'none' ) {
		return '<svg width="22" height="22" viewBox="0 0 24 24" fill="' . $fill . '" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
	}

	const I_SHARE = '<path d="M4 12v7a1 1 0 001 1h14a1 1 0 001-1v-7M16 6l-4-4-4 4M12 2v14"/>';
	const I_HEART = '<path d="M20.8 5.6a5.2 5.2 0 00-7.4 0L12 7l-1.4-1.4a5.2 5.2 0 00-7.4 7.4L12 21.8l8.8-8.8a5.2 5.2 0 000-7.4z"/>';
	const I_STAR  = '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>';
	const I_LIST  = '<path d="M4 6h12M4 12h12M4 18h8M18 15V5l3 1"/><circle cx="16" cy="18" r="2"/>';

	/** The data a work carries so the menu knows what it is acting on. */
	public static function attrs( $s, array $r, $share = true ) {
		$url = ( $share && '' !== (string) $s->share_token ) ? add_query_arg( 'sp_share', $s->share_token, home_url( '/' ) ) : '';
		return ' data-rx-song="' . (int) $s->id . '" data-rx-title="' . esc_attr( Songpress_Songs::display_title( $s ) ) . '" data-rx-fav="' . (int) $r['fav'] . '" data-rx-avg="' . esc_attr( (string) $r['avg'] ) . '" data-rx-n="' . (int) $r['n'] . '" data-rx-mine="' . (int) $r['mine'] . '" data-rx-share="' . esc_url( $url ) . '"';
	}

	/** The swipe tray at the right edge of a phone row. */
	public static function tray() {
		$b = function ( $act, $label, $svg ) {
			return '<button type="button" class="sp-rx-t" data-rx="' . $act . '" aria-label="' . esc_attr( $label ) . '">' . $svg . '<span>' . esc_html( $label ) . '</span></button>';
		};
		return '<span class="sp-rx-tray" aria-hidden="true">' . $b( 'fav', __( 'Favorite', 'songpress' ), self::ico( self::I_HEART ) ) . $b( 'rate', __( 'Rate', 'songpress' ), self::ico( self::I_STAR ) ) . $b( 'list', __( 'Playlist', 'songpress' ), self::ico( self::I_LIST ) ) . $b( 'share', __( 'Share', 'songpress' ), self::ico( self::I_SHARE ) ) . '</span>';
	}

	/** The row of actions on a work page, above the streaming links. A visitor gets Share and Rate only. */
	public static function bar( $s, $token = '' ) {
		$in  = is_user_logged_in();
		$r   = self::one( (int) $s->id );
		$b   = function ( $act, $label, $svg ) {
			return '<button type="button" class="sp-rx-b" data-rx="' . $act . '">' . $svg . '<span class="l">' . esc_html( $label ) . '</span></button>';
		};
		$o   = '<div class="sp-rx-bar"' . self::attrs( $s, $r ) . ( $in ? '' : ' data-rx-t="' . esc_attr( $token ) . '"' ) . ' role="group" aria-label="' . esc_attr__( 'Actions', 'songpress' ) . '">';
		if ( ! $in ) { // signed-in people have Share in the top corner; a visitor on a shared link has no top bar
			$o .= $b( 'share', __( 'Share', 'songpress' ), self::ico( self::I_SHARE ) );
		}
		if ( $in ) {
			$o .= $b( 'fav', __( 'Favorite', 'songpress' ), self::ico( self::I_HEART ) );
		}
		$o  .= $b( 'rate', __( 'Rate', 'songpress' ), self::ico( self::I_STAR ) );
		if ( $in ) {
			$o .= $b( 'list', __( 'Playlist', 'songpress' ), self::ico( self::I_LIST ) );
		}
		return $o . '</div>' . self::boot( $in ? '' : $token ) . self::banner();
	}

	/** The player banner that slides down when a song plays from the Works list or a work page. */
	public static function banner() {
		static $done = false;
		if ( $done || ! wp_is_mobile() ) { // phone app only
			return '';
		}
		$done = true;
		$cfg  = array(
			'logo' => SONGPRESS_URL . 'assets/icon-sp.svg',
			'i'    => array( 'player' => __( 'Player', 'songpress' ), 'prev' => __( 'Previous', 'songpress' ), 'next' => __( 'Next', 'songpress' ), 'play' => __( 'Play', 'songpress' ), 'pause' => __( 'Pause', 'songpress' ), 'hide' => __( 'Hide player', 'songpress' ), 'show' => __( 'Show player', 'songpress' ), 'back15' => __( 'Back 15 seconds', 'songpress' ), 'fwd15' => __( 'Forward 15 seconds', 'songpress' ) ),
		);
		return '<script>window.SP_BAN=' . wp_json_encode( $cfg ) . ';</script><script src="' . esc_url( SONGPRESS_URL . 'assets/banner.js?v=' . SONGPRESS_VERSION ) . '" defer></script>';
	}

	/** The settings and the script, printed once per page. */
	public static function boot( $token = '' ) {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		$cfg  = array(
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'n'      => wp_create_nonce( 'songpress_react' ),
			'un'     => is_user_logged_in() ? wp_create_nonce( 'songpress_use' ) : '',
			'radioN' => is_user_logged_in() ? wp_create_nonce( 'songpress_radio' ) : '',
			'in'     => is_user_logged_in(),
			'min'    => self::MIN_VOTES,
			't'      => (string) $token,
			'i'      => array(
				'share' => __( 'Share', 'songpress' ), 'fav' => __( 'Favorite', 'songpress' ), 'unfav' => __( 'Remove favorite', 'songpress' ), 'rate' => __( 'Rate', 'songpress' ), 'list' => __( 'Add to playlist', 'songpress' ),
				'yours' => __( 'Your rating', 'songpress' ), 'clear' => __( 'Tap your star again to clear it', 'songpress' ), 'new' => __( 'New playlist', 'songpress' ), 'name' => __( 'Playlist name', 'songpress' ), 'create' => __( 'Create', 'songpress' ), 'none' => __( 'No playlists yet.', 'songpress' ), 'noaudio' => __( 'This work has no audio, so it cannot go in a playlist.', 'songpress' ), 'copied' => __( 'Link copied', 'songpress' ), 'close' => __( 'Close', 'songpress' ), 'works' => __( 'works', 'songpress' ), 'err' => __( 'Could not save that.', 'songpress' ),
				'votes0' => __( 'No votes yet', 'songpress' ), 'votes1' => __( '1 vote so far', 'songpress' ), 'avg' => __( 'Average %1 from %2 votes', 'songpress' ), 'hint' => __( 'Swipe a song to the left for more', 'songpress' ),
			),
		);
		return '<script>window.SP_RX=' . wp_json_encode( $cfg ) . ';</script><script src="' . esc_url( SONGPRESS_URL . 'assets/reactions.js?v=' . SONGPRESS_VERSION ) . '" defer></script>';
	}
}
