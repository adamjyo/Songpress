<?php
defined( 'ABSPATH' ) || exit;

/**
 * Front-end screens for logged-in users, meant for phones:
 *   [songpress_catalog]  the list (art on the left, authors under the title) and the song view
 *   [songpress_profile]  your own works only
 * Nothing here shows money. Writers only ever see works they are credited on.
 */
class Songpress_Frontend {

	public static function init() {
		add_shortcode( 'songpress_catalog', array( __CLASS__, 'catalog' ) );
		add_shortcode( 'songpress_profile', array( __CLASS__, 'profile' ) );
		add_action( 'template_redirect', array( __CLASS__, 'shared_page' ) );
		add_action( 'template_redirect', array( __CLASS__, 'app_page' ), 6 );
		add_action( 'wp_login_failed', array( __CLASS__, 'login_failed' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets() {
		wp_register_style( 'songpress', SONGPRESS_URL . 'assets/songpress.css', array(), SONGPRESS_VERSION );
	}

	/** The page that holds a SongPress screen. The page the app was last opened on wins, so a page you made yourself (like /desktop) is used before the ones SongPress made. */
	public static function main_id( $which ) {
		$seen = (int) get_option( 'songpress_app_' . $which );
		if ( $seen && Songpress_Install::page_ok( $seen ) ) {
			return $seen;
		}
		return (int) get_option( 'songpress_page_' . $which );
	}

	private static function app_code( $post ) {
		if ( ! $post || empty( $post->post_content ) ) {
			return '';
		}
		foreach ( array( 'catalog', 'profile' ) as $w ) {
			if ( has_shortcode( $post->post_content, 'songpress_' . $w ) ) {
				return $w;
			}
		}
		return '';
	}

	/** A failed login on the SongPress login screen comes back to it with a message, instead of the WordPress login page. */
	public static function login_failed() {
		if ( empty( $_POST['sp_app'] ) || empty( $_POST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- core handles the login; this only chooses where to send the failure.
			return;
		}
		$to = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ), '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $to ) {
			wp_safe_redirect( add_query_arg( 'sp_login', 'failed', remove_query_arg( 'sp_login', $to ) ) );
			exit;
		}
	}

	/** Any page that holds [songpress_catalog] or [songpress_profile] is shown as the SongPress app on its own, without the theme around it. */
	public static function app_page() {
		if ( is_admin() || ! is_singular( 'page' ) || ! empty( $_GET['sp_share'] ) ) {
			return;
		}
		$post = get_queried_object();
		$which = self::app_code( $post );
		if ( '' === $which ) {
			return;
		}
		if ( (int) get_option( 'songpress_app_' . $which ) !== (int) $post->ID ) {
			update_option( 'songpress_app_' . $which, (int) $post->ID );
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: text/html; charset=utf-8' );
		$css  = esc_url( SONGPRESS_URL . 'assets/songpress.css?ver=' . SONGPRESS_VERSION );
		$here = get_permalink( $post );
		echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( 'SongPress' ) . '</title><link rel="icon" href="' . esc_url( SONGPRESS_URL . 'assets/icon.svg' ) . '" type="image/svg+xml"><link rel="stylesheet" href="' . $css . '"></head><body class="sp-body">'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( ! is_user_logged_in() ) {
			echo self::login_screen( $here ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		} else {
			echo self::shell( $which, $here ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</body></html>';
		exit;
	}

	private static function login_screen( $here ) {
		$failed = isset( $_GET['sp_login'] ) && 'failed' === $_GET['sp_login'];
		$o  = '<div class="sp-login"><main>';
		$o .= '<img class="sp-login-logo" src="' . esc_url( SONGPRESS_URL . 'assets/stacked-amber.svg' ) . '" alt="SongPress, a song catalog manager">';
		$o .= '<section class="sp-card"><h1>' . esc_html__( 'Log in', 'songpress' ) . '</h1><p class="sp-sub">' . esc_html__( 'Your songs, releases and earnings in one place.', 'songpress' ) . '</p>';
		if ( $failed ) {
			$o .= '<p class="sp-err" role="alert">' . esc_html__( 'That username or password did not work. Try again, or reset your password.', 'songpress' ) . '</p>';
		}
		$o .= '<form method="post" action="' . esc_url( site_url( 'wp-login.php', 'login_post' ) ) . '">';
		$o .= '<label for="sp-u">' . esc_html__( 'Username or email', 'songpress' ) . '</label><input id="sp-u" name="log" type="text" autocomplete="username" autocapitalize="none" required>';
		$o .= '<label for="sp-p">' . esc_html__( 'Password', 'songpress' ) . '</label><input id="sp-p" name="pwd" type="password" autocomplete="current-password" required>';
		$o .= '<input type="hidden" name="rememberme" value="forever"><input type="hidden" name="sp_app" value="1"><input type="hidden" name="redirect_to" value="' . esc_url( remove_query_arg( 'sp_login', $here ) ) . '">';
		$o .= '<button type="submit">' . esc_html__( 'Log in', 'songpress' ) . '</button></form>';
		$o .= '<a class="sp-forgot" href="' . esc_url( wp_lostpassword_url( $here ) ) . '">' . esc_html__( 'Forgot your password?', 'songpress' ) . '</a></section>';
		$o .= wp_kses_post( songpress_public_donate_link() ) . '</main></div>';
		return $o;
	}

	private static function nav_item( $href, $label, $icon, $on ) {
		return '<a href="' . esc_url( $href ) . '"' . ( $on ? ' aria-current="page" class="on"' : '' ) . '>' . $icon . esc_html( $label ) . '</a>';
	}

	private static function shell( $which, $here ) {
		$svg = function ( $d ) {
			return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">' . $d . '</svg>';
		};
		$songs   = $svg( '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>' );
		$me      = $svg( '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>' );
		$people  = $svg( '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3 3-5 6-5s6 2 6 5M17 11a3 3 0 100-6M21 20c0-2-1.5-3.6-4-4.4"/>' );
		$manage  = $svg( '<path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z"/>' );
		$out     = ' <a href="' . esc_url( wp_logout_url( $here ) ) . '">' . $svg( '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>' ) . esc_html__( 'Log out', 'songpress' ) . '</a>';
		$cat_url = self::main_id( 'catalog' ) ? get_permalink( self::main_id( 'catalog' ) ) : $here;
		$pro_url = self::main_id( 'profile' ) ? get_permalink( self::main_id( 'profile' ) ) : $here;
		$nav     = self::nav_item( $cat_url, __( 'Songs', 'songpress' ), $songs, 'catalog' === $which ) . self::nav_item( $pro_url, __( 'Profile', 'songpress' ), $me, 'profile' === $which );
		if ( Songpress_Caps::can( 'members' ) ) {
			$nav .= '<span class="sp-navgap"></span>' . self::nav_item( admin_url( 'admin.php?page=songpress-people' ), __( 'People', 'songpress' ), $people, false );
			$nav .= self::nav_item( admin_url( 'admin.php?page=songpress' ), __( 'Manage', 'songpress' ), $manage, false );
		}
		$body = do_shortcode( 'catalog' === $which ? '[songpress_catalog]' : '[songpress_profile]' );
		return '<div class="sp-shell"><nav class="sp-side" aria-label="SongPress"><div class="sp-side-logo"><img src="' . esc_url( SONGPRESS_URL . 'assets/icon.svg' ) . '" width="36" height="36" alt="SongPress"></div>' . $nav . '<span class="sp-grow"></span>' . $out . '</nav><main class="sp-main">' . $body . '</main></div>';
	}

	private static function gate() {
		if ( ! is_user_logged_in() ) {
			return '<div class="sp-app"><p class="songpress-note">' . sprintf(
				/* translators: %s: login link */
				esc_html__( 'Please %s to see your songs.', 'songpress' ),
				'<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'log in', 'songpress' ) . '</a>'
			) . '</p></div>';
		}
		if ( ! Songpress_Caps::can_view() ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'Your account cannot see the catalog.', 'songpress' ) . '</p></div>';
		}
		return '';
	}

	private static function art( $s, $class ) {
		$src = Songpress_Media::art_url( $s, 'medium' );
		if ( '' === $src ) {
			return '<span class="' . esc_attr( $class ) . ' songpress-noart" aria-hidden="true">&#9835;</span>';
		}
		return '<img class="' . esc_attr( $class ) . '" src="' . esc_url( $src ) . '" alt="" loading="lazy">';
	}

	public static function catalog( $atts = array() ) {
		if ( $msg = self::gate() ) {
			return $msg;
		}
		wp_enqueue_style( 'songpress' );
		$uid = get_current_user_id();
		if ( isset( $_GET['sp_song'] ) ) {
			return self::song_view( absint( $_GET['sp_song'] ), $uid ) . wp_kses_post( songpress_public_donate_link() );
		}
		$q      = isset( $_GET['sp_q'] ) ? sanitize_text_field( wp_unslash( $_GET['sp_q'] ) ) : '';
		$status = isset( $_GET['sp_status'] ) ? sanitize_key( wp_unslash( $_GET['sp_status'] ) ) : '';
		$status = isset( Songpress_Songs::STATUSES[ $status ] ) ? $status : '';
		$order  = isset( $_GET['sp_sort'] ) ? sanitize_key( wp_unslash( $_GET['sp_sort'] ) ) : '';
		$order  = in_array( $order, array( 'serial', 'title', 'genre', 'writer', 'artist', 'status' ), true ) ? $order : '';
		$dir    = ( isset( $_GET['sp_dir'] ) && 'desc' === $_GET['sp_dir'] ) ? 'desc' : 'asc';
		$res    = Songpress_Songs::query( array( 'search' => $q, 'status' => $status, 'per_page' => 40, 'order' => $order, 'dir' => $dir, 'user_id' => Songpress_Caps::can( 'view_all' ) ? 0 : $uid ) );
		$out    = '<div class="songpress sp-app">' . self::tabs( 'catalog', 'top' ) . self::head( __( 'Songs', 'songpress' ) );
		$add    = Songpress_Caps::can( 'create' ) ? '<a class="songpress-add" href="' . esc_url( admin_url( 'admin.php?page=songpress-song' ) ) . '" aria-label="' . esc_attr__( 'Add a song', 'songpress' ) . '" title="' . esc_attr__( 'Add a song', 'songpress' ) . '">+</a>' : '';
		$out   .= '<div class="songpress-searchbar"><form method="get" class="songpress-search"><input type="search" name="sp_q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Search title or serial', 'songpress' ) . '" aria-label="' . esc_attr__( 'Search songs', 'songpress' ) . '"></form>' . $add . '</div>';
		$out .= '<div class="songpress-colhead" role="row"><span class="songpress-ch-play"></span><span class="songpress-ch-main">';
		foreach ( array( 'serial' => __( 'No.', 'songpress' ), 'title' => __( 'Title', 'songpress' ), 'genre' => __( 'Genre', 'songpress' ), 'writer' => __( 'Writers', 'songpress' ), 'artist' => __( 'Band', 'songpress' ), 'status' => __( 'Status', 'songpress' ) ) as $k => $label ) {
			$on   = ( $k === $order );
			$next = ( $on && 'asc' === $dir ) ? 'desc' : 'asc';
			$out .= '<a class="songpress-ch-' . esc_attr( $k ) . ( $on ? ' on' : '' ) . '" href="' . esc_url( add_query_arg( array( 'sp_sort' => $k, 'sp_dir' => $next ) ) ) . '"' . ( $on ? ' aria-sort="' . ( 'asc' === $dir ? 'ascending' : 'descending' ) . '"' : '' ) . '>' . esc_html( $label ) . ( $on ? ( 'asc' === $dir ? ' &#9650;' : ' &#9660;' ) : '' ) . '</a>';
		}
		$out .= '</span></div>';
		$out .= '<ul class="songpress-list">';
		foreach ( $res['rows'] as $s ) {
			$link  = esc_url( add_query_arg( 'sp_song', (int) $s->id ) );
			$audio = Songpress_Media::audio_url( $s );
			$src   = Songpress_Media::art_url( $s, 'medium' );
			$art   = '' !== $src ? '<img class="songpress-thumb" src="' . esc_url( $src ) . '" alt="" loading="lazy">' : '<span class="songpress-thumb songpress-noart" aria-hidden="true">&#9835;</span>';
			if ( '' !== $audio ) {
				$art = '<button type="button" class="songpress-play" data-src="' . esc_url( $audio ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: song title */ __( 'Play %s', 'songpress' ), $s->title ) ) . '">' . $art . '<span class="songpress-playicon" aria-hidden="true"></span></button>';
			} else {
				$art = '<span class="songpress-play songpress-play-off">' . $art . '</span>';
			}
			$faces = '';
			$seen  = array();
			foreach ( Songpress_Songs::credits( (int) $s->id ) as $c ) {
				$k = Songpress_Profiles::key( $c->name );
				if ( 'writer' !== $c->role || '' === $k || isset( $seen[ $k ] ) ) {
					continue;
				}
				$seen[ $k ] = 1;
				$faces     .= '<span class="songpress-icon" title="' . esc_attr( $c->name ) . '">' . Songpress_Profiles::chip( $c->name, 100, 'person' ) . '</span>';
			}
			$band = '' !== trim( (string) $s->artist ) ? '<span class="songpress-icon songpress-bandicon" title="' . esc_attr( $s->artist ) . '">' . Songpress_Profiles::chip( $s->artist, 100, 'band' ) . '</span>' : '';
			$out .= '<li class="songpress-row">' . $art . '<a class="songpress-rowlink" href="' . $link . '">'
				. '<span class="songpress-serial">' . esc_html( $s->serial ) . '</span>'
				. '<span class="songpress-title">' . esc_html( Songpress_Songs::display_title( $s ) ) . '</span>'
				. '<span class="songpress-genre">' . esc_html( $s->genre ) . '</span>'
				. '<span class="songpress-icons songpress-writers">' . $faces . '</span>'
				. '<span class="songpress-icons">' . $band . '</span>'
				. '<span class="songpress-sub">' . esc_html( Songpress_Songs::STATUSES[ $s->status ] ?? $s->status ) . '</span>'
				. '</a></li>';
		}
		if ( ! $res['rows'] ) {
			$out .= '<li class="songpress-empty">' . esc_html__( 'No songs found.', 'songpress' ) . '</li>';
		}
		return $out . '</ul><script>(function(){var a=null,b=null;document.addEventListener(\'click\',function(e){var x=e.target.closest&&e.target.closest(\'.songpress-play[data-src]\');if(!x)return;e.preventDefault();if(b===x&&a&&!a.paused){a.pause();return;}if(a){a.pause();}if(b){b.classList.remove(\'on\');}b=x;a=new Audio(x.getAttribute(\'data-src\'));a.addEventListener(\'play\',function(){x.classList.add(\'on\');});a.addEventListener(\'pause\',function(){x.classList.remove(\'on\');});a.addEventListener(\'ended\',function(){x.classList.remove(\'on\');});a.play();});})();</script>' . wp_kses_post( songpress_public_donate_link() ) . self::tabs( 'catalog' ) . '</div>';
	}

	/** The logo and page title at the top of every phone screen. */
	private static function head( $title ) {
		return '<div class="songpress-head"><img src="' . esc_url( SONGPRESS_URL . 'assets/icon.svg' ) . '" alt="SongPress"><h2>' . esc_html( $title ) . '</h2></div>';
	}

	/** Songs / Profile tabs along the bottom, linking to the two pages SongPress made. */
	private static function tabs( $on, $pos = 'bottom' ) {
		$c = self::main_id( 'catalog' );
		$p = self::main_id( 'profile' );
		if ( ! $c || ! $p || ! Songpress_Install::page_ok( $c ) || ! Songpress_Install::page_ok( $p ) ) {
			return '';
		}
		return '<nav class="songpress-tabs songpress-tabs-' . $pos . '" aria-label="SongPress"><a class="' . ( 'catalog' === $on ? 'on' : '' ) . '" href="' . esc_url( get_permalink( $c ) ) . '">' . esc_html__( 'Songs', 'songpress' ) . '</a><a class="' . ( 'profile' === $on ? 'on' : '' ) . '" href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html__( 'Profile', 'songpress' ) . '</a></nav>';
	}

	private static function song_view( $id, $uid ) {
		if ( ! Songpress_Caps::can_see_song( $id ) ) {
			return '<p class="songpress-note">' . esc_html__( 'That song is not available to you.', 'songpress' ) . '</p>';
		}
		$s = Songpress_Songs::get( $id );
		if ( ! $s ) {
			return '<p class="songpress-note">' . esc_html__( 'That song does not exist.', 'songpress' ) . '</p>';
		}
		return '<div class="songpress sp-app">' . self::tabs( 'catalog', 'top' ) . self::song_body( $s, $uid, '<a class="songpress-back" href="' . esc_url( remove_query_arg( 'sp_song' ) ) . '">&lsaquo; ' . esc_html__( 'Songs', 'songpress' ) . '</a>' ) . self::tabs( 'catalog' ) . '</div>';
	}

	/** The song itself: cover, audio, title, writers, chart. Shared by the logged-in view and the public share page. */
	private static function song_body( $s, $uid, $top = '' ) {
		$id = (int) $s->id;
		list( $kind, $text ) = Songpress_Songs::best_chart( $s );
		$labels = array( 'chordpro' => 'ChordPro', 'chords_lyrics' => __( 'Chords and lyrics', 'songpress' ), 'lyrics' => __( 'Lyrics', 'songpress' ), 'none' => '' );
		$out  = '<article class="songpress-song">' . $top . '<div class="songpress-cols"><div class="songpress-side">';
		$out .= '<div class="songpress-hero">' . self::art( $s, 'songpress-cover' );
		$audio = Songpress_Media::audio_url( $s );
		if ( '' !== $audio ) {
			$out .= '<audio class="songpress-audio" controls preload="none" src="' . esc_url( $audio ) . '"></audio>';
		}
		$out .= '</div><p class="songpress-sub"><span class="songpress-serial">' . esc_html( $s->serial ) . '</span> ' . esc_html( Songpress_Songs::STATUSES[ $s->status ] ?? $s->status ) . '</p>';
		$out .= '<h2>' . esc_html( Songpress_Songs::display_title( $s ) ) . '</h2><p class="songpress-by">' . esc_html( Songpress_Songs::authors_line( $id, $uid ) ) . '</p>';
		if ( '' !== trim( (string) $s->artist ) ) {
			$out .= '<p class="songpress-band">' . Songpress_Profiles::chip( $s->artist, 28, 'band' ) . esc_html( $s->artist ) . '</p>'; // the performing act, never matched against people
		}
		$seen = array();
		$out .= '<p class="songpress-faces">';
		foreach ( Songpress_Songs::credits( $id ) as $c ) {
			$k = Songpress_Profiles::key( $c->name );
			if ( '' === $k || isset( $seen[ $k ] ) || ! in_array( $c->role, array( 'writer', 'performer', 'producer' ), true ) ) {
				continue;
			}
			$seen[ $k ] = 1;
			$out       .= '<span class="songpress-face" style="display:inline-block;margin:0 10px 6px 0">' . Songpress_Profiles::chip( $c->name, 28, 'person' ) . esc_html( $c->name ) . '</span>';
		}
		$out .= '</p></div><div class="songpress-main">';
		if ( 'none' !== $kind ) {
			$out .= '<p class="songpress-chartlabel">' . esc_html( $labels[ $kind ] ) . '</p>' . ( 'chordpro' === $kind ? wp_kses_post( Songpress_ChordPro::render( $text ) ) : '<pre class="songpress-chart">' . esc_html( $text ) . '</pre>' );
		}
		return $out . '</div></div></article>';
	}

	/** A song's public share link: /?sp_share=TOKEN. No login. Shows the song only: no splits, no codes, no notes, no money. */
	public static function shared_page() {
		if ( empty( $_GET['sp_share'] ) ) {
			return;
		}
		$s = Songpress_Songs::by_token( sanitize_text_field( wp_unslash( $_GET['sp_share'] ) ) );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( ! $s ) {
			status_header( 404 );
		}
		echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">';
		echo '<title>' . esc_html( $s ? $s->title : __( 'Song not found', 'songpress' ) ) . '</title>';
		if ( $s ) {
			echo self::share_meta( $s ); // phpcs:ignore WordPress.Security.EscapeOutput -- every value is escaped inside.
		}
		echo '<link rel="stylesheet" href="' . esc_url( SONGPRESS_URL . 'assets/songpress.css?ver=' . SONGPRESS_VERSION ) . '">';
		echo '<style>body{margin:0;background:#0f1012;color:#e9eaec}.songpress-share{max-width:560px;margin:0 auto;padding:16px}.songpress-share .sp-app{border-radius:0;padding:0 16px 8px}.songpress-sharelogo{display:block;width:44px;height:44px;margin:4px 0 12px}</style></head><body><main class="songpress-share">';
		echo '<img class="songpress-sharelogo" src="' . esc_url( SONGPRESS_URL . 'assets/seal-amber.svg' ) . '" alt="SongPress">';
		if ( $s ) {
			echo '<div class="sp-app">' . self::song_body( $s, 0 ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		} else {
			echo '<p>' . esc_html__( 'This link is no longer active.', 'songpress' ) . '</p>';
		}
		echo '<div class="sp-app" style="padding-top:0">' . wp_kses_post( songpress_public_donate_link() ) . '</div></main></body></html>';
		exit;
	}

	/** What a messaging app shows when the link is pasted: the cover art and the song's name (Open Graph and Twitter card tags). */
	private static function share_meta( $s ) {
		$img = Songpress_Media::art_url( $s, 'large' );
		$by   = Songpress_Songs::authors_line( (int) $s->id, 0 );
		$url  = add_query_arg( 'sp_share', $s->share_token, home_url( '/' ) );
		$tags = array(
			'og:type'      => 'music.song',
			'og:site_name' => get_bloginfo( 'name' ),
			'og:title'     => $s->title,
			'og:url'       => $url,
		);
		if ( '' !== $by ) {
			$tags['og:description'] = $by;
		}
		if ( '' !== $img ) {
			$tags['og:image']        = $img;
			$tags['og:image:alt']    = $s->title;
			$tags['twitter:card']    = 'summary_large_image';
		} else {
			$tags['twitter:card'] = 'summary';
		}
		$tags['twitter:title'] = $s->title;
		$out = '';
		foreach ( $tags as $k => $v ) {
			$out .= '<meta ' . ( 0 === strpos( $k, 'twitter:' ) ? 'name' : 'property' ) . '="' . esc_attr( $k ) . '" content="' . esc_attr( 'og:image' === $k || 'og:url' === $k ? esc_url_raw( $v ) : $v ) . '">';
		}
		return $out;
	}

	public static function profile( $atts = array() ) {
		if ( $msg = self::gate() ) {
			return $msg;
		}
		wp_enqueue_style( 'songpress' );
		$u   = wp_get_current_user();
		$res = Songpress_Songs::query( array( 'per_page' => 100, 'user_id' => $u->ID ) );
		$rel = 0;
		foreach ( $res['rows'] as $s ) {
			if ( 'released' === $s->status ) {
				$rel++;
			}
		}
		$ini = '';
		foreach ( array_slice( preg_split( '/\s+/', trim( $u->display_name ) ), 0, 2 ) as $w ) {
			$ini .= function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $w, 0, 1 ) ) : strtoupper( substr( $w, 0, 1 ) );
		}
		$pf   = Songpress_Profiles::find( $u->display_name, 'person' );
		$face = $pf ? Songpress_Profiles::photo_url( $pf ) : '';
		$out  = '<div class="songpress songpress-profile sp-app">' . self::tabs( 'profile', 'top' ) . '<div class="songpress-profile-head"><div class="songpress-avatar" aria-hidden="true">' . ( '' !== $face ? '<img src="' . esc_url( $face ) . '" alt="">' : esc_html( $ini ) ) . '</div><div><h2>' . esc_html( $u->display_name ) . '</h2>';
		$out .= '<p class="songpress-sub">' . esc_html( sprintf( /* translators: %d: number of songs */ _n( 'Credited on %d song', 'Credited on %d songs', $res['total'], 'songpress' ), $res['total'] ) ) . '</p></div></div>';
		$out .= '<ul class="songpress-stats"><li>' . esc_html__( 'Songs', 'songpress' ) . '<strong>' . (int) $res['total'] . '</strong></li><li>' . esc_html__( 'Released', 'songpress' ) . '<strong>' . (int) $rel . '</strong></li></ul>';
		$out .= '<p class="songpress-sub">' . esc_html__( 'Earnings and streams will appear here when that feature is added.', 'songpress' ) . '</p>';
		$out .= wp_kses_post( songpress_public_donate_link() ) . self::tabs( 'profile' ) . '</div>';
		return $out;
	}
}
