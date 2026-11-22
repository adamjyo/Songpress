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
			$o .= '<p class="sp-err" role="alert">' . esc_html__( 'That username or password did not work. Check them and try again.', 'songpress' ) . '</p>';
		}
		$o .= '<form method="post" action="' . esc_url( site_url( 'wp-login.php', 'login_post' ) ) . '">';
		$o .= '<label for="sp-u">' . esc_html__( 'Username', 'songpress' ) . '</label><input id="sp-u" name="log" type="text" value="' . esc_attr( isset( $_GET['sp_user'] ) ? sanitize_user( wp_unslash( $_GET['sp_user'] ) ) : '' ) . '" autocomplete="username" autocapitalize="none" required>';
		$o .= '<label for="sp-p">' . esc_html__( 'Password', 'songpress' ) . '</label><input id="sp-p" name="pwd" type="password" autocomplete="current-password" required>';
		$o .= '<input type="hidden" name="rememberme" value="forever"><input type="hidden" name="sp_app" value="1"><input type="hidden" name="redirect_to" value="' . esc_url( remove_query_arg( 'sp_login', $here ) ) . '">';
		$o .= '<button type="submit">' . esc_html__( 'Log in', 'songpress' ) . '</button></form>';
		$o .= '<p class="sp-forgot">' . esc_html__( 'Forgot your password? Ask the person who invited you to set a new one.', 'songpress' ) . '</p></section>';
		$o .= '<p class="sp-forgot">' . sprintf( /* translators: %s: link to the ChordPro tool */ esc_html__( 'Looking for a chord-sheet program like Songpress (Skeed / Lallulli)? Use the %s, free, no login.', 'songpress' ), '<a href="' . esc_url( Songpress_ChordTool::url() ) . '">' . esc_html__( 'web ChordPro tool', 'songpress' ) . '</a>' ) . '</p>';
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
		$page    = isset( $_GET['sp_page'] ) ? sanitize_key( wp_unslash( $_GET['sp_page'] ) ) : '';
		$in_app  = '' === $page;
		$nav     = self::nav_item( $cat_url, __( 'Songs', 'songpress' ), $songs, 'catalog' === $which && $in_app ) . self::nav_item( $pro_url, __( 'Profile', 'songpress' ), $me, 'profile' === $which );
		if ( ! wp_is_mobile() ) {
			$nav .= '<a href="' . esc_url( Songpress_ChordTool::url() ) . '" target="_blank" rel="noopener">' . $svg( '<path d="M9 18V5l12-2v13"/><path d="M3 21h18"/>' ) . esc_html__( 'ChordPro tool', 'songpress' ) . '</a>';
		}
		$mk = function ( $slug, $label, $icon, $cap ) use ( $cat_url, $page ) {
			$on = $page === $slug || ( 'settings' === $slug && in_array( $page, array( 'lists', 'backups', 'health' ), true ) );
			return Songpress_Caps::can( $cap ) ? self::nav_item( add_query_arg( 'sp_page', $slug, $cat_url ), $label, $icon, $on ) : '';
		};
		if ( Songpress_Caps::can( 'money' ) && ! wp_is_mobile() ) {
			$nav .= '<span class="sp-navgap"></span>' . $mk( 'money', __( 'Money', 'songpress' ), $svg( '<circle cx="12" cy="12" r="9"/><path d="M14.5 9a2.5 2 0 00-2.5-1.5c-1.5 0-2.5.8-2.5 2s1 1.7 2.5 2 2.5.8 2.5 2-1 2-2.5 2A2.5 2 0 019.5 15M12 6v1.5M12 16.5V18"/>' ), 'money' ) . $mk( 'stats', __( 'Stats', 'songpress' ), $svg( '<path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/>' ), 'money' );
		}
		if ( Songpress_Caps::can( 'registrations' ) && ! wp_is_mobile() ) {
			$nav .= $mk( 'registrations', __( 'Registrations', 'songpress' ), $svg( '<path d="M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7zM9 12l2 2 4-4"/>' ), 'registrations' );
		}
		if ( Songpress_Caps::can( 'agreements' ) && ! wp_is_mobile() ) {
			$nav .= $mk( 'agreements', __( 'Agreements', 'songpress' ), $svg( '<path d="M6 3h9l4 4v14H6zM14 3v5h5M9 13h7M9 17h5"/>' ), 'agreements' );
		}
		if ( Songpress_Caps::can( 'art' ) && ! wp_is_mobile() ) {
			$nav .= $mk( 'art', __( 'Art studio', 'songpress' ), $svg( '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/>' ), 'art' );
		}
		if ( Songpress_Caps::can( 'sessions' ) && ! wp_is_mobile() ) {
			$nav .= $mk( 'sessions', __( 'Sessions', 'songpress' ), $svg( '<path d="M4 5h16v11H9l-5 4zM8 9h8M8 12h5"/>' ), 'sessions' );
		}
		if ( Songpress_Caps::can( 'journal' ) && ! wp_is_mobile() ) {
			$nav .= $mk( 'notes', __( 'Notes', 'songpress' ), $svg( '<path d="M6 3h12a1 1 0 011 1v16a1 1 0 01-1 1H6zM6 3v18M10 8h5M10 12h5"/>' ), 'journal' );
		}
		if ( Songpress_Caps::can( 'members' ) ) {
			$cur = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : 'person';
			$nav .= '<span class="sp-navgap"></span>' . self::nav_item( add_query_arg( array( 'sp_page' => 'people', 'kind' => 'person' ), $cat_url ), __( 'People', 'songpress' ), $people, 'people' === $page && 'person' === $cur );
			$kn = function ( $kind, $label ) use ( $cat_url, $page, $people, $cur ) {
				return self::nav_item( add_query_arg( array( 'sp_page' => 'people', 'kind' => $kind ), $cat_url ), $label, $people, 'people' === $page && $cur === $kind );
			};
			$nav .= $kn( 'band', __( 'Bands', 'songpress' ) ) . $kn( 'label', __( 'Labels', 'songpress' ) ) . $kn( 'publisher', __( 'Publishers', 'songpress' ) );
			$nav .= $mk( 'artists', __( 'Artists', 'songpress' ), $svg( '<path d="M12 3l2.6 5.6 6.1.7-4.5 4.2 1.2 6L12 16.5 6.6 19.5l1.2-6L3.3 9.3l6.1-.7z"/>' ), 'artists' );
			$nav .= $mk( 'activity', __( 'Activity log', 'songpress' ), $svg( '<path d="M3 12h4l3-8 4 16 3-8h4"/>' ), 'activity' );
			$nav .= $mk( 'uploads', __( 'Upload / Download', 'songpress' ), $svg( '<path d="M12 3v12M7 10l5 5 5-5M4 20h16"/>' ), 'import' );
			$nav .= $mk( 'link', __( 'Link writers', 'songpress' ), $svg( '<path d="M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1M14 10a4 4 0 00-5.7 0l-3 3A4 4 0 0011 18.7l1-1"/>' ), 'link' );
			$nav .= $mk( 'settings', __( 'Settings', 'songpress' ), $manage, 'settings' );
			if ( Songpress_Caps::can( 'export' ) ) {
				$nav .= self::nav_item( wp_nonce_url( admin_url( 'admin-post.php?action=songpress_export' ), 'songpress_export' ), __( 'Export CSV', 'songpress' ), $svg( '<path d="M12 15V3M7 8l5-5 5 5M4 20h16"/>' ), false );
			}
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
		$desktop = ! wp_is_mobile(); // phones and tablets only view; managing and editing is for the desktop app
		if ( $desktop && isset( $_GET['sp_page'] ) ) {
			return Songpress_Screens::render( sanitize_key( wp_unslash( $_GET['sp_page'] ) ) );
		}
		if ( $desktop && isset( $_GET['sp_members'] ) ) {
			return Songpress_Persons::render();
		}
		if ( $desktop && isset( $_GET['sp_edit'] ) ) {
			$e = sanitize_text_field( wp_unslash( $_GET['sp_edit'] ) );
			return Songpress_Editor::render( 'new' === $e ? 0 : absint( $e ) );
		}
		wp_enqueue_style( 'songpress' );
		$uid = get_current_user_id();
		if ( $desktop && isset( $_GET['sp_song'] ) && empty( $_GET['sp_view'] ) && Songpress_Caps::can_edit_song( absint( $_GET['sp_song'] ) ) ) {
			return Songpress_Editor::render( absint( $_GET['sp_song'] ) ); // people who can edit a song open it in the editor
		}
		if ( isset( $_GET['sp_song'] ) ) {
			return self::song_view( absint( $_GET['sp_song'] ), $uid ) . wp_kses_post( songpress_public_donate_link() );
		}
		$q      = isset( $_GET['sp_q'] ) ? sanitize_text_field( wp_unslash( $_GET['sp_q'] ) ) : '';
		$status = isset( $_GET['sp_status'] ) ? sanitize_key( wp_unslash( $_GET['sp_status'] ) ) : '';
		$status = isset( Songpress_Songs::statuses()[ $status ] ) ? $status : '';
		$order  = isset( $_GET['sp_sort'] ) ? sanitize_key( wp_unslash( $_GET['sp_sort'] ) ) : '';
		$order  = in_array( $order, array( 'serial', 'title', 'genre', 'writer', 'artist', 'label', 'status' ), true ) ? $order : '';
		$dir    = ( isset( $_GET['sp_dir'] ) && 'desc' === $_GET['sp_dir'] ) ? 'desc' : 'asc';
		$res    = Songpress_Songs::query( array( 'search' => $q, 'status' => $status, 'per_page' => 40, 'order' => $order, 'dir' => $dir, 'user_id' => Songpress_Caps::can( 'view_all' ) ? 0 : $uid ) );
		$out    = '<div class="songpress sp-app">' . self::tabs( 'catalog', 'top' ) . self::head( __( 'Songs', 'songpress' ) );
		$add    = ( $desktop && Songpress_Caps::can( 'create' ) ) ? '<a class="songpress-add" href="' . esc_url( Songpress_Editor::edit_url( 0 ) ) . '" aria-label="' . esc_attr__( 'Add a song', 'songpress' ) . '" title="' . esc_attr__( 'Add a song', 'songpress' ) . '">+</a>' : '';
		$out   .= '<div class="songpress-searchbar"><form method="get" class="songpress-search"><input type="search" name="sp_q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Search title or serial', 'songpress' ) . '" aria-label="' . esc_attr__( 'Search songs', 'songpress' ) . '"></form>' . $add . '</div>';
		$out .= '<div class="songpress-colhead" role="row"><span class="songpress-ch-play"></span><span class="songpress-ch-main">';
		foreach ( array( 'serial' => __( 'No.', 'songpress' ), 'title' => __( 'Title', 'songpress' ), 'genre' => __( 'Genre', 'songpress' ), 'writer' => __( 'Writers', 'songpress' ), 'artist' => __( 'Band', 'songpress' ), 'label' => __( 'Label', 'songpress' ), 'status' => __( 'Status', 'songpress' ) ) as $k => $label ) {
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
			$faces  = '';
			$lfaces = '';
			$seen   = array();
			foreach ( Songpress_Songs::credits( (int) $s->id ) as $c ) {
				$k = Songpress_Profiles::key( $c->name );
				if ( 'label' === $c->role && '' !== $k && ! isset( $seen[ 'l:' . $k ] ) ) {
					$seen[ 'l:' . $k ] = 1;
					$lfaces           .= '<span class="songpress-icon" title="' . esc_attr( $c->name ) . '">' . Songpress_Profiles::chip( $c->name, 75, 'label' ) . '</span>';
					continue;
				}
				if ( 'writer' !== $c->role || '' === $k || isset( $seen[ $k ] ) ) {
					continue;
				}
				$seen[ $k ] = 1;
				$faces     .= '<span class="songpress-icon" title="' . esc_attr( $c->name ) . '">' . Songpress_Profiles::chip( $c->name, 75, 'person' ) . '</span>';
			}
			$band = '' !== trim( (string) $s->artist ) ? '<span class="songpress-icon songpress-bandicon" title="' . esc_attr( $s->artist ) . '">' . Songpress_Profiles::chip( $s->artist, 75, 'band' ) . '</span>' : '';
			$out .= '<li class="songpress-row">' . $art . '<a class="songpress-rowlink" href="' . $link . '">'
				. '<span class="songpress-serial">' . esc_html( $s->serial ) . '</span>'
				. '<span class="songpress-title">' . esc_html( Songpress_Songs::display_title( $s ) ) . '</span>'
				. '<span class="songpress-genre">' . esc_html( $s->genre ) . '</span>'
				. '<span class="songpress-icons songpress-writers">' . $faces . '</span>'
				. '<span class="songpress-icons">' . $band . '</span>'
				. '<span class="songpress-icons songpress-labels">' . $lfaces . '</span>'
				. '<span class="songpress-sub">' . esc_html( Songpress_Songs::statuses()[ $s->status ] ?? $s->status ) . '</span>'
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
		$edit = ( ! wp_is_mobile() && Songpress_Caps::can_edit_song( $id ) ) ? '<a class="songpress-editlink" href="' . esc_url( Songpress_Editor::edit_url( $id ) ) . '">' . esc_html__( 'Edit', 'songpress' ) . '</a>' : '';
		return '<div class="songpress sp-app">' . self::tabs( 'catalog', 'top' ) . self::song_body( $s, $uid, $edit . '<a class="songpress-back" href="' . esc_url( remove_query_arg( 'sp_song' ) ) . '">&lsaquo; ' . esc_html__( 'Songs', 'songpress' ) . '</a>' ) . self::tabs( 'catalog' ) . '</div>';
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
		$out .= '</div><p class="songpress-sub"><span class="songpress-serial">' . esc_html( $s->serial ) . '</span> ' . esc_html( Songpress_Songs::statuses()[ $s->status ] ?? $s->status ) . '</p>';
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
			// the lyric process: any level can be shown; a level nobody typed falls back to the highest one, stepped down
			$lv   = Songpress_Songs::lyric_levels( $s );
			$want = isset( $_GET['sp_level'] ) ? sanitize_key( wp_unslash( $_GET['sp_level'] ) ) : $kind; // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! isset( $lv[ $want ] ) || '' === $lv[ $want ][0] ) {
				$want = $kind;
			}
			$text = $lv[ $want ][0];
			$out .= '<p class="songpress-chartlabel">' . esc_html( $labels[ $want ] );
			foreach ( $labels as $lk => $ll ) {
				if ( 'none' !== $lk && $lk !== $want && '' !== $lv[ $lk ][0] ) {
					$out .= ' &middot; <a href="' . esc_url( add_query_arg( 'sp_level', $lk ) ) . '">' . esc_html( $ll ) . '</a>';
				}
			}
			$out .= '</p>' . ( 'chordpro' === $want ? wp_kses_post( Songpress_ChordPro::render( $text ) ) : '<pre class="songpress-chart">' . esc_html( $text ) . '</pre>' );
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
		$out .= Songpress_Money::my_earnings_html( $u->display_name );
		$out .= wp_kses_post( songpress_public_donate_link() ) . self::tabs( 'profile' ) . '</div>';
		return $out;
	}
}
