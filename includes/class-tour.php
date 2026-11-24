<?php
/**
 * Tour: a short animated walk-through of the phone app.
 *
 * It opens by itself the first time someone uses the app (and once after an update that adds it), and any time from
 * the Help tab. Closing it returns to the screen underneath. The browser reports start, finish and early close to the
 * usage log; the "seen" mark is kept per person, so it shows once, not on every open.
 *
 * @package SongPress
 */

defined( 'ABSPATH' ) || exit;

class Songpress_Tour {

	const SEEN = 'songpress_tour_seen';

	/** Should it open by itself? Only for a person with a login, on a phone, who has not seen it. */
	public static function auto() {
		return is_user_logged_in() && wp_is_mobile() && ! get_user_meta( get_current_user_id(), self::SEEN, true );
	}

	public static function mark_seen() {
		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), self::SEEN, time() );
		}
	}

	public static function slides() {
		return array(
			array( 'welcome', __( 'Welcome to SongPress', 'songpress' ), __( 'Everything is along the bottom: Works, Radio, Help and Profile. This short tour shows you around. Open it again any time from Help.', 'songpress' ) ),
			array( 'search', __( 'Find a song', 'songpress' ), __( 'Type in the search box at the top of Works. The list narrows as you type: titles, people, lyrics, even style.', 'songpress' ) ),
			array( 'play', __( 'Tap the art to play', 'songpress' ), __( 'Tap the picture to start the song. Tap the name of the song to open its page, with lyrics, chords and links.', 'songpress' ) ),
			array( 'swipe', __( 'Swipe for more', 'songpress' ), __( 'Swipe a song to the left to favorite it, rate it, add it to a playlist or share it.', 'songpress' ) ),
			array( 'hold', __( 'Or press and hold', 'songpress' ), __( 'Keep your finger on a song for half a second and the same menu pops up.', 'songpress' ) ),
			array( 'rate', __( 'Stars and hearts', 'songpress' ), __( 'Tap a star to rate a song. Tap the same star again to take your rating back. A heart marks a favorite, and your hearts collect in a playlist called Favorites.', 'songpress' ) ),
			array( 'radio', __( 'Radio and playlists', 'songpress' ), __( 'Radio plays songs one after another. All releases, Recent releases and Favorites are always there, and you can make playlists of your own.', 'songpress' ) ),
			array( 'player', __( 'The player', 'songpress' ), __( 'Open the player for shuffle, repeat and Up next, plus a heart and a star for the song that is playing. The music keeps going while you move around Radio.', 'songpress' ) ),
			array( 'home', __( 'Put it on your home screen', 'songpress' ), __( 'In Safari tap the Share button, choose Add to Home Screen, then Add. After that, open SongPress from its icon like any other app.', 'songpress' ) ),
			array( 'alerts', __( 'Turn on alerts', 'songpress' ), __( 'Open Profile, switch on Alerts to your phone and tap Allow. On an iPhone this only works from the home screen icon.', 'songpress' ) ),
		);
	}

	/** The settings and the script, printed once per page. */
	public static function boot() {
		static $done = false;
		if ( $done || ! is_user_logged_in() ) {
			return '';
		}
		$done = true;
		$cfg  = array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'un'    => wp_create_nonce( 'songpress_use' ),
			'auto'  => self::auto(),
			'slides' => array_map( function ( $s ) {
				return array( 'k' => $s[0], 't' => $s[1], 'd' => $s[2] );
			}, self::slides() ),
			'i'     => array(
				'tour' => __( 'SongPress tour', 'songpress' ), 'prev' => __( 'Previous', 'songpress' ), 'next' => __( 'Next', 'songpress' ), 'done' => __( 'Done', 'songpress' ), 'close' => __( 'Close the tour', 'songpress' ),
				'step' => __( 'Step %1 of %2', 'songpress' ),
			),
		);
		return '<script>window.SP_TOUR=' . wp_json_encode( $cfg ) . ';</script><script src="' . esc_url( SONGPRESS_URL . 'assets/tour.js?v=' . SONGPRESS_VERSION ) . '" defer></script>';
	}
}
