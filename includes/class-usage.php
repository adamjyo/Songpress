<?php
/**
 * Usage log: what people do in the phone app, so the owner can see how often it is used and for what.
 *
 * Plays, ratings, favorites, playlists, shares and the tutorial are written here. The totals and the latest
 * activity are shown at the bottom of the Stats screen. Nothing here is sent anywhere.
 *
 * @package SongPress
 */

defined( 'ABSPATH' ) || exit;

class Songpress_Usage {

	/** What the browser may report itself. Ratings, favorites and playlists are written by the server when they happen. */
	const FROM_BROWSER = array( 'play', 'share', 'menu', 'tour_start', 'tour_done', 'tour_skip' );

	public static function init() {
		add_action( 'wp_ajax_songpress_use', array( __CLASS__, 'ajax' ) );
	}

	public static function labels() {
		return array(
			'play'         => __( 'Plays', 'songpress' ),
			'fav'          => __( 'Favorites added', 'songpress' ),
			'unfav'        => __( 'Favorites removed', 'songpress' ),
			'rate'         => __( 'Ratings', 'songpress' ),
			'playlist_add' => __( 'Added to a playlist', 'songpress' ),
			'playlist_new' => __( 'Playlists made', 'songpress' ),
			'share'        => __( 'Shares', 'songpress' ),
			'menu'         => __( 'Menu opened (swipe or hold)', 'songpress' ),
			'tour_start'   => __( 'Tutorial started', 'songpress' ),
			'tour_done'    => __( 'Tutorial finished', 'songpress' ),
			'tour_skip'    => __( 'Tutorial closed early', 'songpress' ),
		);
	}

	public static function log( $kind, $song_id = 0, $detail = '', $user_id = null ) {
		global $wpdb;
		if ( ! isset( self::labels()[ $kind ] ) ) {
			return;
		}
		$wpdb->insert( Songpress_Install::table( 'usage' ), array(
			'created_at' => current_time( 'mysql' ),
			'user_id'    => null === $user_id ? get_current_user_id() : (int) $user_id,
			'kind'       => $kind,
			'song_id'    => (int) $song_id,
			'detail'     => mb_substr( sanitize_text_field( (string) $detail ), 0, 60 ),
			'device'     => wp_is_mobile() ? 'phone' : 'desktop',
		) );
	}

	public static function ajax() {
		check_ajax_referer( 'songpress_use', 'n' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array(), 403 );
		}
		$kind = sanitize_key( wp_unslash( $_POST['kind'] ?? '' ) );
		if ( ! in_array( $kind, self::FROM_BROWSER, true ) ) {
			wp_send_json_error( array(), 400 );
		}
		$sid = absint( $_POST['song'] ?? 0 );
		if ( $sid && ! Songpress_Caps::can_see_song( $sid ) ) {
			$sid = 0;
		}
		if ( 0 === strpos( $kind, 'tour_' ) ) {
			Songpress_Tour::mark_seen(); // shown once per person, not on every open
		}
		self::log( $kind, $sid, wp_unslash( $_POST['detail'] ?? '' ) );
		wp_send_json_success();
	}

	/** Counts for the last 7 days, 30 days and all time, by kind. */
	public static function totals() {
		global $wpdb;
		$t   = Songpress_Install::table( 'usage' );
		$out = array();
		foreach ( array( 7, 30, 0 ) as $days ) {
			$w = $days ? $wpdb->prepare( 'WHERE created_at >= %s', gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - $days * DAY_IN_SECONDS ) ) : '';
			foreach ( (array) $wpdb->get_results( "SELECT kind, COUNT(*) AS n FROM {$t} {$w} GROUP BY kind" ) as $r ) { // phpcs:ignore WordPress.DB
				$out[ $r->kind ][ $days ] = (int) $r->n;
			}
		}
		return $out;
	}

	/** The panel at the bottom of Stats. */
	public static function panel() {
		global $wpdb;
		$t   = Songpress_Install::table( 'usage' );
		$st  = Songpress_Install::table( 'songs' );
		$o   = '<h3>' . esc_html__( 'How the phone app is used', 'songpress' ) . '</h3>';
		$tot = self::totals();
		if ( ! $tot ) {
			return $o . '<p class="songpress-note">' . esc_html__( 'Nothing recorded yet. Plays, ratings, favorites and playlists appear here as people use the app.', 'songpress' ) . '</p>';
		}
		$o .= '<div class="spm-scroll"><table class="spm-table"><thead><tr><th>' . esc_html__( 'What', 'songpress' ) . '</th><th class="n">' . esc_html__( '7 days', 'songpress' ) . '</th><th class="n">' . esc_html__( '30 days', 'songpress' ) . '</th><th class="n">' . esc_html__( 'All time', 'songpress' ) . '</th></tr></thead><tbody>';
		foreach ( self::labels() as $k => $label ) {
			if ( ! isset( $tot[ $k ] ) ) {
				continue;
			}
			$o .= '<tr><td>' . esc_html( $label ) . '</td><td class="n">' . (int) ( $tot[ $k ][7] ?? 0 ) . '</td><td class="n">' . (int) ( $tot[ $k ][30] ?? 0 ) . '</td><td class="n">' . (int) ( $tot[ $k ][0] ?? 0 ) . '</td></tr>';
		}
		$o .= '</tbody></table></div>';
		// where plays start from
		$src = (array) $wpdb->get_results( "SELECT detail, COUNT(*) AS n FROM {$t} WHERE kind = 'play' GROUP BY detail ORDER BY n DESC" ); // phpcs:ignore WordPress.DB
		if ( $src ) {
			$names = array( 'list' => __( 'Works list', 'songpress' ), 'work' => __( 'Work page', 'songpress' ), 'radio' => __( 'Radio', 'songpress' ), 'share' => __( 'Public link', 'songpress' ) );
			$bits  = array();
			foreach ( $src as $r ) {
				$bits[] = esc_html( ( $names[ $r->detail ] ?? ( '' !== $r->detail ? $r->detail : __( 'Unknown', 'songpress' ) ) ) . ': ' . (int) $r->n );
			}
			$o .= '<p class="spe-help">' . esc_html__( 'Plays started from', 'songpress' ) . ' &mdash; ' . implode( ' &middot; ', $bits ) . '</p>';
		}
		$top = (array) $wpdb->get_results( "SELECT u.song_id, s.serial, s.title, COUNT(*) AS n FROM {$t} u JOIN {$st} s ON s.id = u.song_id WHERE u.kind = 'play' GROUP BY u.song_id, s.serial, s.title ORDER BY n DESC, s.title ASC LIMIT 10" ); // phpcs:ignore WordPress.DB
		if ( $top ) {
			$o .= '<h3>' . esc_html__( 'Most played in the app', 'songpress' ) . '</h3><div class="spm-scroll"><table class="spm-table"><tbody>';
			foreach ( $top as $r ) {
				$o .= '<tr><td>' . esc_html( trim( $r->serial . '  ' . $r->title ) ) . '</td><td class="n">' . (int) $r->n . '</td></tr>';
			}
			$o .= '</tbody></table></div>';
		}
		$rec = (array) $wpdb->get_results( "SELECT u.*, s.title, s.serial FROM {$t} u LEFT JOIN {$st} s ON s.id = u.song_id ORDER BY u.id DESC LIMIT 40" ); // phpcs:ignore WordPress.DB
		$o  .= '<h3>' . esc_html__( 'Latest activity', 'songpress' ) . '</h3><div class="spm-scroll"><table class="spm-table"><thead><tr><th>' . esc_html__( 'When', 'songpress' ) . '</th><th>' . esc_html__( 'Who', 'songpress' ) . '</th><th>' . esc_html__( 'What', 'songpress' ) . '</th><th>' . esc_html__( 'Song', 'songpress' ) . '</th><th>' . esc_html__( 'Device', 'songpress' ) . '</th></tr></thead><tbody>';
		foreach ( $rec as $r ) {
			$who = (int) $r->user_id ? (string) get_the_author_meta( 'display_name', (int) $r->user_id ) : __( 'Visitor', 'songpress' );
			$lab = self::labels()[ $r->kind ] ?? $r->kind;
			$o  .= '<tr><td>' . esc_html( $r->created_at ) . '</td><td>' . esc_html( $who ) . '</td><td>' . esc_html( $lab . ( '' !== $r->detail ? ' (' . $r->detail . ')' : '' ) ) . '</td><td>' . esc_html( $r->title ? trim( $r->serial . '  ' . $r->title ) : '' ) . '</td><td>' . esc_html( $r->device ) . '</td></tr>';
		}
		return $o . '</tbody></table></div>';
	}
}
