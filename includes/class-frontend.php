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
	/** The old plugin lived at /desktop and /app. When no page holds those addresses, they still open the app. Returns the address, or ''. */
	public static function legacy_url() {
		$req  = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path = trim( substr( $req, strlen( rtrim( $home, '/' ) ) ), '/' );
		return in_array( strtolower( $path ), array( 'desktop', 'app' ), true ) ? home_url( '/' . strtolower( $path ) . '/' ) : '';
	}

	/** Links from the old plugin (?swp_view=people and the like) go to the matching screen here. */
	public static function legacy_screen( $view ) {
		$map = array( 'people' => 'people', 'pay' => 'money', 'accounting' => 'money', 'royalties' => 'money', 'stats' => 'stats', 'sessions' => 'sessions', 'notes' => 'notes', 'journal' => 'notes', 'art' => 'art', 'pitch' => 'pitch', 'agreements' => 'agreements', 'registrations' => 'registrations', 'bands' => 'people' );
		return $map[ strtolower( (string) $view ) ] ?? '';
	}

	public static function app_page() {
		if ( is_admin() || ! empty( $_GET['sp_share'] ) ) {
			return;
		}
		$legacy = '';
		if ( is_singular( 'page' ) ) {
			$post  = get_queried_object();
			$which = self::app_code( $post );
		} elseif ( is_404() && '' !== self::legacy_url() ) {
			$post   = null;
			$which  = 'catalog';
			$legacy = self::legacy_url();
			status_header( 200 );
		} else {
			return;
		}
		if ( '' === $which ) {
			return;
		}
		if ( isset( $_GET['swp_view'] ) && ! isset( $_GET['sp_page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- only picks a screen.
			$to = self::legacy_screen( sanitize_key( wp_unslash( $_GET['swp_view'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( '' !== $to ? add_query_arg( 'sp_page', $to, remove_query_arg( 'swp_view' ) ) : remove_query_arg( 'swp_view' ) );
			exit;
		}
		if ( $post && (int) get_option( 'songpress_app_' . $which ) !== (int) $post->ID ) {
			update_option( 'songpress_app_' . $which, (int) $post->ID );
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: text/html; charset=utf-8' );
		$css  = esc_url( SONGPRESS_URL . 'assets/songpress.css?ver=' . SONGPRESS_VERSION );
		$here = $post ? get_permalink( $post ) : $legacy;
		echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( 'SongPress' ) . '</title><link rel="icon" href="' . esc_url( SONGPRESS_URL . 'assets/icon.svg' ) . '" type="image/svg+xml"><link rel="stylesheet" href="' . $css . '">' . Songpress_Push::head() . '</head><body class="sp-body">'; // phpcs:ignore WordPress.Security.EscapeOutput
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
		// Signing in lands on the works list, unless the visitor came by a link to something specific.
		$dest = $here;
		$args = $_GET; // phpcs:ignore WordPress.Security.NonceVerification
		unset( $args['sp_login'], $args['sp_user'] );
		if ( ! $args && self::main_id( 'catalog' ) ) {
			$dest = get_permalink( self::main_id( 'catalog' ) );
		}
		$o  = '<div class="sp-login"><main>';
		$o .= '<img class="sp-login-logo" src="' . esc_url( SONGPRESS_URL . 'assets/stacked-amber.svg' ) . '" alt="SongPress, a song catalog manager">';
		$o .= '<section class="sp-card"><h1>' . esc_html__( 'Log in', 'songpress' ) . '</h1><p class="sp-sub">' . esc_html__( 'Your songs, releases and earnings in one place.', 'songpress' ) . '</p>';
		if ( $failed ) {
			$o .= '<p class="sp-err" role="alert">' . esc_html__( 'That username or password did not work. Check them and try again.', 'songpress' ) . '</p>';
		}
		if ( isset( $_GET['sp_login'] ) && 'expired' === $_GET['sp_login'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<p class="sp-err" role="alert">' . esc_html__( 'That link has already been used or has lapsed. Ask for a new one.', 'songpress' ) . '</p>';
		}
		$o .= '<form method="post" action="' . esc_url( site_url( 'wp-login.php', 'login_post' ) ) . '">';
		$o .= '<label for="sp-u">' . esc_html__( 'Username', 'songpress' ) . '</label><input id="sp-u" name="log" type="text" value="' . esc_attr( isset( $_GET['sp_user'] ) ? sanitize_user( wp_unslash( $_GET['sp_user'] ) ) : '' ) . '" autocomplete="username" autocapitalize="none" required>';
		$o .= '<label for="sp-p">' . esc_html__( 'Password', 'songpress' ) . '</label><input id="sp-p" name="pwd" type="password" autocomplete="current-password" required>';
		$o .= '<input type="hidden" name="rememberme" value="forever"><input type="hidden" name="sp_app" value="1"><input type="hidden" name="redirect_to" value="' . esc_url( remove_query_arg( 'sp_login', $dest ) ) . '">';
		$o .= '<button type="submit">' . esc_html__( 'Log in', 'songpress' ) . '</button></form>';
		if ( isset( $_GET['sp_login'] ) && 'asked' === $_GET['sp_login'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<p class="sp-sub" role="status">' . esc_html__( 'Thanks. The people who run this have been told, and will send you a link to choose a new password.', 'songpress' ) . '</p>';
		}
		$o .= '<details class="sp-forgot"><summary>' . esc_html__( 'Forgot my password', 'songpress' ) . '</summary><form method="post" action="' . esc_url( remove_query_arg( array( 'sp_login', 'sp_forgot' ) ) ) . '"><input type="hidden" name="sp_forgot" value="1"><label for="sp-who">' . esc_html__( 'Your username or email', 'songpress' ) . '</label><input id="sp-who" name="sp_who" type="text" autocomplete="username" autocapitalize="none" value="' . esc_attr( isset( $_GET['sp_user'] ) ? sanitize_user( wp_unslash( $_GET['sp_user'] ) ) : '' ) . '" required><button type="submit">' . esc_html__( 'Tell the admin', 'songpress' ) . '</button></form></details></section>'; // phpcs:ignore WordPress.Security.NonceVerification
		$o .= '<p class="sp-forgot">' . sprintf( /* translators: %s: link to the ChordPro tool */ esc_html__( 'Looking for a chord-sheet program like Songpress (Skeed / Lallulli)? Use the %s, free, no login.', 'songpress' ), '<a href="' . esc_url( Songpress_ChordTool::url() ) . '">' . esc_html__( 'web ChordPro tool', 'songpress' ) . '</a>' ) . '</p>';
		$o .= wp_kses_post( songpress_public_donate_link() ) . '</main></div>';
		return $o;
	}

	private static function nav_item( $href, $label, $icon, $on, $badge = 0, $badge_title = '' ) {
		return '<a href="' . esc_url( $href ) . '"' . ( $on ? ' aria-current="page" class="on"' : '' ) . '>' . $icon . esc_html( $label ) . ( $badge > 0 ? ' <span class="sp-badge" title="' . esc_attr( '' !== $badge_title ? $badge_title : __( 'Follow-ups due', 'songpress' ) ) . '">' . (int) $badge . '</span>' : '' ) . '</a>';
	}

	/**
	 * Each rail item remembers where you were in it. Leaving a section files its address (search, open work, open note…);
	 * clicking another section's item goes back to where you left it, and clicking the section you are already in starts it over.
	 */
	private static function nav_memory() {
		return '<script>(function(){var s=document.currentScript,n=s&&s.closest("nav");if(!n)return;var K="spnav:' . (int) get_current_user_id() . ':",on=n.querySelector("a.on");'
			. 'function key(a){var u=new URL(a.href);return(u.searchParams.get("sp_page")||"")+(u.searchParams.get("sp_radio")?"|radio":"")+"|"+u.pathname}'
			. 'function clean(h){var u=new URL(h);["sp_deleted","sp_bulk","sp_bskip","sp_err","sp_saved","m_ok","m_err","nj_go"].forEach(function(k){u.searchParams.delete(k)});return u.toString()}'
			. 'function save(){if(!on)return;try{localStorage.setItem(K+key(on),clean(location.href))}catch(e){}}'
			. 'save();window.addEventListener("pagehide",function(){if(document.contains(n))save()});'
			. 'n.addEventListener("click",function(e){var a=e.target.closest("a[href]");if(!a||a===on||e.button||e.metaKey||e.ctrlKey||e.shiftKey||/action=logout/.test(a.href))return;save();try{var t=localStorage.getItem(K+key(a));if(t&&new URL(t).origin===location.origin){e.preventDefault();if(window.SPGO)window.SPGO(t);else location.href=t}}catch(x){}})}());</script>';
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
		$in_app  = '' === $page && ! isset( $_GET['sp_radio'] );
		$avatar  = get_avatar_url( get_current_user_id(), array( 'size' => 36 ) );
		$cu      = wp_get_current_user();
		$who     = '' !== trim( (string) $cu->display_name ) ? $cu->display_name : __( 'Profile', 'songpress' );
		$face    = $avatar ? '<img class="sp-me" src="' . esc_url( $avatar ) . '" width="22" height="22" alt="">' : $me;
		$nav     = '<div class="sp-who">' . self::nav_item( $pro_url, $who, $face, 'profile' === $which ) . Songpress_Push::item() . '</div>' . self::nav_item( $cat_url, __( 'Works', 'songpress' ), $songs, 'catalog' === $which && $in_app );
		if ( wp_is_mobile() ) {
			$nav .= self::nav_item( add_query_arg( 'sp_radio', '1', $cat_url ), __( 'Radio', 'songpress' ), $svg( '<circle cx="12" cy="12" r="2"/><path d="M8 8a5.7 5.7 0 000 8M16 8a5.7 5.7 0 010 8M5 5a10 10 0 000 14M19 5a10 10 0 010 14"/>' ), 'catalog' === $which && isset( $_GET['sp_radio'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		$mk = function ( $slug, $label, $icon, $cap, $badge = 0 ) use ( $cat_url, $page ) {
			$on = $page === $slug || ( 'settings' === $slug && in_array( $page, array( 'backups', 'health', 'earnings', 'activity', 'uploads', 'import' ), true ) ) || ( 'registrations' === $slug && 'agreements' === $page ) || ( 'agreements' === $slug && 'registrations' === $page );
			return Songpress_Caps::can( $cap ) ? self::nav_item( add_query_arg( 'sp_page', $slug, $cat_url ), $label, $icon, $on, $badge ) : '';
		};
		if ( Songpress_Caps::can( 'journal' ) && ! wp_is_mobile() ) {
			$nav .= $mk( 'notes', __( 'Notes', 'songpress' ), $svg( '<path d="M6 3h12a1 1 0 011 1v16a1 1 0 01-1 1H6zM6 3v18M10 8h5M10 12h5"/>' ), 'journal' );
		}
		if ( Songpress_Caps::can( 'pitch' ) && ! wp_is_mobile() ) {
			$nav .= $mk( 'pitch', __( 'Pitch', 'songpress' ), $svg( '<path d="M3 11v2a1 1 0 001 1h2l5 4V6L6 10H4a1 1 0 00-1 1zM15 9a4 4 0 010 6M18 6.5a8 8 0 010 11"/>' ), 'pitch', Songpress_Pitch::due_count() );
		}
		if ( Songpress_Caps::can( 'money' ) && ! wp_is_mobile() ) {
			$nav .= $mk( 'money', __( 'Money', 'songpress' ), $svg( '<circle cx="12" cy="12" r="9"/><path d="M14.5 9a2.5 2 0 00-2.5-1.5c-1.5 0-2.5.8-2.5 2s1 1.7 2.5 2 2.5.8 2.5 2-1 2-2.5 2A2.5 2 0 019.5 15M12 6v1.5M12 16.5V18"/>' ), 'money' ) . $mk( 'stats', __( 'Stats', 'songpress' ), $svg( '<path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/>' ), 'money' );
		}
		if ( Songpress_Transcriber::can_use() ) {
			$wave = $svg( '<path d="M3 12h2M7 8v8M11 4v16M15 8v8M19 10v4"/>' );
			// on the desktop it opens inside the app, under the rail; a phone has no screens, so it goes to the page itself
			$nav .= wp_is_mobile() ? self::nav_item( Songpress_Transcriber::url(), __( 'Transcriber', 'songpress' ), $wave, false ) : $mk( 'transcriber', __( 'Transcriber', 'songpress' ), $wave, 'edit_own' );
		}
		if ( Songpress_Caps::can( 'members' ) ) {
			$cur = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : 'person';
			$nav .= self::nav_item( add_query_arg( array( 'sp_page' => 'people', 'kind' => 'person' ), $cat_url ), __( 'People', 'songpress' ), $people, 'people' === $page || 'link' === $page || 'artists' === $page, count( Songpress_Access::asks() ), __( 'Asked for a new password', 'songpress' ) );
		}
		$nav .= self::nav_item( add_query_arg( 'sp_page', 'notifications', $cat_url ), __( 'Notifications', 'songpress' ), $svg( '<path d="M6 8a6 6 0 0112 0c0 7 3 8 3 8H3s3-1 3-8M10 20a2 2 0 004 0"/>' ), 'notifications' === $page, Songpress_Push::unread( get_current_user_id() ), __( 'Not dealt with yet', 'songpress' ) );
		$nav .= self::nav_memory();
		$bot  = $mk( 'settings', __( 'Settings', 'songpress' ), $manage, 'settings' );
		$body = do_shortcode( 'catalog' === $which ? '[songpress_catalog]' : '[songpress_profile]' );
		return Songpress_Push::install_banner() . '<div class="sp-shell"><nav class="sp-side" aria-label="SongPress"><div class="sp-side-logo"><img src="' . esc_url( SONGPRESS_URL . 'assets/icon.svg' ) . '" width="36" height="36" alt=""><span class="sp-side-name"><b>SongPress</b><small>' . esc_html__( 'A songwriting and publishing', 'songpress' ) . '</small></span></div>' . $nav . '<span class="sp-grow"></span>' . $bot . $out . '</nav><main class="sp-main">' . $body . '</main></div>' . Songpress_Push::script_tag() . Songpress_Push::badge_script() . '<script>(function(){var s=document.querySelector(".sp-shell > .sp-side");if(!s){return;}var sh=s.parentNode,moved=false;function check(){if(moved||window.innerWidth<760||window.pageYOffset<40){return;}var b=document.getElementById("wpadminbar"),t=b&&getComputedStyle(b).position==="fixed"?b.offsetHeight:0;if(Math.abs(s.getBoundingClientRect().top-t)>2){moved=true;document.body.appendChild(sh);window.removeEventListener("scroll",check);}}window.addEventListener("scroll",check,{passive:true});})();</script>';
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
		if ( ! $desktop && isset( $_GET['sp_radio'] ) ) { // Radio (phone only): playlists, on the desktop and on the phone
			wp_enqueue_style( 'songpress' );
			return '<div class="songpress sp-app">' . ( $desktop ? '' : self::userbar( 'catalog' ) ) . Songpress_Radio::render() . self::tabs( 'radio' ) . '</div>';
		}
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
			return self::song_view( absint( $_GET['sp_song'] ), $uid ); // nothing under the chart
		}
		$q      = isset( $_GET['sp_q'] ) ? sanitize_text_field( wp_unslash( $_GET['sp_q'] ) ) : '';
		$status = isset( $_GET['sp_status'] ) ? sanitize_key( wp_unslash( $_GET['sp_status'] ) ) : '';
		$status = isset( Songpress_Songs::statuses()[ $status ] ) ? $status : '';
		list( $order, $dir ) = Songpress_Steps::sort(); // the heading last clicked is remembered
		$len    = isset( $_GET['sp_n'] ) ? absint( $_GET['sp_n'] ) : 100;
		$len    = ( $len >= 100 && $len <= 1000 && 0 === $len % 100 ) ? $len : 100;
		$res    = Songpress_Songs::query( array( 'search' => $q, 'status' => $status, 'per_page' => $len, 'order' => $order, 'dir' => $dir, 'user_id' => Songpress_Caps::can( 'view_all' ) ? 0 : $uid ) );
		$out    = '<div class="songpress sp-app">' . ( $desktop ? self::tabs( 'catalog', 'top' ) . self::head( __( 'Works', 'songpress' ) ) : self::userbar( 'catalog' ) );
		if ( $desktop && isset( $_GET['sp_deleted'] ) ) {
			$out .= '<p class="songpress-toast" role="status">' . esc_html( sprintf( /* translators: %d: number of songs */ _n( 'Deleted %d song.', 'Deleted %d songs.', absint( $_GET['sp_deleted'] ), 'songpress' ), absint( $_GET['sp_deleted'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( $desktop && isset( $_GET['sp_bulk'] ) ) {
			$out .= '<p class="songpress-toast" role="status">' . esc_html( sprintf( /* translators: 1: songs changed, 2: songs skipped */ __( 'Updated %1$d songs. Skipped %2$d (not allowed, or the field does not apply to an original work).', 'songpress' ), absint( $_GET['sp_bulk'] ), absint( $_GET['sp_bskip'] ?? 0 ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( $desktop && isset( $_GET['sp_err'] ) ) {
			$out .= '<p class="songpress-toast" role="status">' . esc_html( sanitize_text_field( wp_unslash( $_GET['sp_err'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}

		$out   .= '<script>(function(){var t=document.querySelectorAll(".songpress-toast");if(!t.length)return;try{var u=new URL(location.href);["sp_deleted","sp_bulk","sp_bskip","sp_err"].forEach(function(k){u.searchParams.delete(k);});history.replaceState(null,"",u.toString());}catch(e){}t.forEach(function(n){setTimeout(function(){n.classList.add("out");setTimeout(function(){n.remove();},500);},4000);});})();</script>';
		$lenbox = '';
		if ( $desktop ) {
			$ov = '<form method="get" class="songpress-len"><label>' . esc_html__( 'Show', 'songpress' ) . ' <select name="sp_n" onchange="this.form.submit()">';
			for ( $i = 100; $i <= 1000; $i += 100 ) {
				$ov .= '<option value="' . $i . '"' . selected( $len, $i, false ) . '>' . $i . '</option>';
			}
			$all = Songpress_Songs::query( array( 'per_page' => 1, 'user_id' => Songpress_Caps::can( 'view_all' ) ? 0 : $uid ) );
			$ov .= '</select> ' . esc_html__( 'works', 'songpress' ) . ' <span class="songpress-lencount">' . esc_html( sprintf( /* translators: %s: number of works */ __( '(%s in the system)', 'songpress' ), number_format_i18n( (int) $all['total'] ) ) ) . '</span></label>';
			foreach ( array( 'sp_q' => $q, 'sp_status' => $status, 'sp_sort' => $order, 'sp_dir' => $dir ) as $k => $v ) {
				if ( '' !== $v ) {
					$ov .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
				}
			}
			$ov .= '</form>';
			$lenbox = $ov;
		}
		$exp    = $desktop ? '<a class="songpress-export" target="_blank" rel="noopener" href="' . esc_url( Songpress_Export::url( 'list', array_filter( array( 'sp_q' => $q, 'sp_status' => $status, 'sp_sort' => $order, 'sp_dir' => $dir ), 'strlen' ) ) ) . '" title="' . esc_attr__( 'Export this list as a PDF', 'songpress' ) . '">' . esc_html__( 'Export catalog', 'songpress' ) . '</a>' : '';
		$add    = ( $desktop && Songpress_Caps::can( 'create' ) ) ? '<a class="songpress-add" href="' . esc_url( Songpress_Editor::edit_url( 0 ) ) . '" aria-label="' . esc_attr__( 'Add a song', 'songpress' ) . '" title="' . esc_attr__( 'Add a song', 'songpress' ) . '">+</a>' : '';
		$out   .= '<div class="songpress-searchbar">' . $lenbox . '<form method="get" class="songpress-search">' . ( $desktop && 100 !== $len ? '<input type="hidden" name="sp_n" value="' . (int) $len . '">' : '' ) . '<input type="search" name="sp_q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Search songs, people, lyrics, style', 'songpress' ) . '" aria-label="' . esc_attr__( 'Search songs', 'songpress' ) . '"></form>' . ( $desktop ? '<div class="songpress-addcol">' . $add . $exp . '</div>' : '' ) . '</div>';
		if ( ! $desktop ) { // phone: the list follows the search box as you type
			$out .= '<script>(function(){if(window.__spLive)return;window.__spLive=1;var t=0,seq=0;document.addEventListener("input",function(e){var i=e.target;if(!i||i.name!=="sp_q"||!i.closest||!i.closest(".songpress-search"))return;clearTimeout(t);t=setTimeout(function(){run(i)},260)});'
				. 'function run(i){var u=new URL(location.href),n=++seq,q=i.value.trim();if(q)u.searchParams.set("sp_q",q);else u.searchParams.delete("sp_q");u.searchParams.delete("sp_song");fetch(u.href,{credentials:"same-origin"}).then(function(r){return r.ok?r.text():Promise.reject()}).then(function(h){if(n!==seq)return;var d=new DOMParser().parseFromString(h,"text/html"),nl=d.querySelector(".songpress-list"),ol=document.querySelector(".songpress-list");if(!nl||!ol)return;ol.parentNode.replaceChild(nl,ol);try{history.replaceState(history.state,"",u.href)}catch(x){}if(window.SPRX&&SPRX.rescan)SPRX.rescan();document.dispatchEvent(new Event("sp:swapped"))}).catch(function(){})}'
				. 'document.addEventListener("submit",function(e){var f=e.target;if(f&&f.classList&&f.classList.contains("songpress-search")){e.preventDefault();e.stopImmediatePropagation();var i=f.querySelector("[name=sp_q]");clearTimeout(t);run(i);i.blur()}},true)})()</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- a fixed script.
		}
		$bulk = $desktop && ( Songpress_Caps::can( 'edit_any' ) || Songpress_Caps::can( 'edit_own' ) );
		if ( $bulk ) {
			$out .= self::bulk_open();
		}
		if ( $desktop ) { // phones show the list with no column headings
			$regs = Songpress_Caps::can( 'registrations' );
			$out .= '<div class="songpress-colhead" role="row">' . ( $bulk ? '<input type="checkbox" class="songpress-pickall" aria-label="' . esc_attr__( 'Select all', 'songpress' ) . '">' : '' ) . '<span class="songpress-ch-play"></span><span class="songpress-ch-main">';
			foreach ( array( 'serial' => __( 'No.', 'songpress' ), 'title' => __( 'Title', 'songpress' ), 'genre' => __( 'Genre', 'songpress' ), 'writer' => __( 'Writers', 'songpress' ), 'artist' => __( 'Band', 'songpress' ), 'regs' => __( 'Registered', 'songpress' ) ) as $k => $label ) {
				if ( 'regs' === $k ) { // not sortable: the registry chips, for those who manage registrations
					$out .= '<span class="songpress-ch-regs">' . esc_html( $regs ? $label : __( 'Status', 'songpress' ) ) . '</span>';
					continue;
				}
				$on   = ( $k === $order );
				$next = ( $on && 'asc' === $dir ) ? 'desc' : 'asc';
				$out .= '<a class="songpress-ch-' . esc_attr( $k ) . ( $on ? ' on' : '' ) . '" href="' . esc_url( add_query_arg( ( $on && 'desc' === $dir ) ? array( 'sp_sort' => '', 'sp_dir' => '' ) : array( 'sp_sort' => $k, 'sp_dir' => $next ) ) ) . '"' . ( $on ? ' aria-sort="' . ( 'asc' === $dir ? 'ascending' : 'descending' ) . '"' : '' ) . '>' . esc_html( $label ) . ( $on ? ( 'asc' === $dir ? ' &#9650;' : ' &#9660;' ) : '' ) . '</a>';
			}
			$out .= '</span></div>';
		}
		$rx = $desktop ? array() : Songpress_Reactions::bulk( wp_list_pluck( $res['rows'], 'id' ) );
		$isrc = array(); // songs on this page that have an ISRC: the desktop list tags them Released
		if ( $desktop && $res['rows'] ) {
			global $wpdb;
			$ids  = array_map( 'intval', wp_list_pluck( $res['rows'], 'id' ) );
			$ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$isrc = array_flip( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT song_id FROM ' . Songpress_Install::table( 'identifiers' ) . " WHERE kind = 'isrc' AND TRIM(value) <> '' AND song_id IN ({$ph})", $ids ) ) ) ); // phpcs:ignore WordPress.DB
		}
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
			if ( ! $desktop ) { // phones: a card with the cover art on the right; number, release title and artist on the left, genre at the far right
				$rel   = 0 === (int) $s->version_no ? $s->title : $s->release_title; // only an original work shows the work name; a version shows its release title
				if ( 'starter' === strtolower( trim( (string) $s->stage ) ) ) {
					$rel = __( 'Starter', 'songpress' );
				}
				$none  = ( 0 !== (int) $s->version_no && '' === trim( (string) $rel ) );
				if ( $none ) {
					$rel = Songpress_Songs::display_title( $s ); // no release title yet: show the work name (its genre is on the card too)
				}
				$r     = $rx[ (int) $s->id ];
				$out  .= '<li class="songpress-card"' . Songpress_Reactions::attrs( $s, $r ) . '>' . $art . '<a class="songpress-cardlink" href="' . $link . '"><span class="songpress-cardtext"><span class="songpress-serial">' . esc_html( $s->serial ) . '</span><span class="songpress-title' . ( $none ? ' songpress-notitle' : '' ) . '">' . esc_html( $rel ) . '</span><span class="songpress-cardline"><span class="songpress-cardartist">' . esc_html( $s->artist ) . '</span>' . ( '' !== trim( (string) $s->genre ) ? '<span class="songpress-cardstyle">' . esc_html( $s->genre ) . '</span>' : '' ) . '</span></span></a>' . ( $r['fav'] ? '<span class="sp-rx-heart" aria-label="' . esc_attr__( 'Favorite', 'songpress' ) . '">&#9829;</span>' : '<span class="sp-rx-heart" hidden>&#9829;</span>' ) . Songpress_Reactions::tray() . '</li>';
				continue;
			}
			$out .= '<li class="songpress-row">' . ( $bulk ? '<input type="checkbox" class="songpress-pick" name="ids[]" value="' . (int) $s->id . '" aria-label="' . esc_attr( sprintf( /* translators: %s: song title */ __( 'Select %s', 'songpress' ), $s->title ) ) . '">' : '' ) . $art . '<a class="songpress-rowlink" href="' . $link . '">'
				. '<span class="songpress-serial">' . esc_html( $s->serial ) . '</span>'
				. '<span class="songpress-title">' . esc_html( Songpress_Songs::list_title( $s ) ) . ( isset( $isrc[ (int) $s->id ] ) ? '<small class="songpress-tag">' . esc_html__( 'Released', 'songpress' ) . '</small>' : '' ) . '</span>'
				. '<span class="songpress-genre">' . esc_html( $s->genre ) . '</span>'
				. '<span class="songpress-icons songpress-writers">' . $faces . '</span>'
				. '<span class="songpress-icons">' . $band . '</span>'
				. ( $desktop ? '<span class="songpress-regs">' . Songpress_Registrations::strip( $s, $regs ) . '</span>' : '<span class="songpress-icons songpress-labels">' . $lfaces . '</span>' )
				. '</a></li>';
		}
		if ( ! $res['rows'] ) {
			$out .= '<li class="songpress-empty">' . esc_html__( 'No songs found.', 'songpress' ) . '</li>';
		}
		return $out . '</ul>' . ( $bulk ? '</form>' : '' ) . Songpress_Reactions::boot() . Songpress_Reactions::banner() . wp_kses_post( songpress_public_donate_link() ) . self::tabs( 'catalog' ) . '</div>';
	}

	/**
	 * The top of every phone screen: the person's picture and name, which open their profile. On the profile itself it is a way back
	 * to the works. The profile shows only when this is tapped.
	 */
	private static function userbar( $on, $share_id = 0, $back = '' ) {
		$c = self::main_id( 'catalog' );
		$p = self::main_id( 'profile' );
		if ( 'profile' === $on && '' === $back ) {
			$back = $c ? get_permalink( $c ) : home_url( '/' );
		}
		$u    = wp_get_current_user();
		$name = '' !== trim( (string) $u->display_name ) ? $u->display_name : __( 'Profile', 'songpress' );
		$pf   = Songpress_Profiles::find( $u->display_name, 'person' );
		$face = $pf ? Songpress_Profiles::photo_url( $pf ) : '';
		if ( '' === $face ) {
			$face = (string) get_avatar_url( $u->ID, array( 'size' => 72 ) );
		}
		$pic = '' !== $face ? '<img class="songpress-userpic" src="' . esc_url( $face ) . '" width="36" height="36" alt="">' : '<span class="songpress-userpic songpress-userpic-ini" aria-hidden="true">' . esc_html( function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $name, 0, 1 ) ) : strtoupper( substr( $name, 0, 1 ) ) ) . '</span>';
		$arrow = '' !== $back ? self::swipe_back() : ''; // no back button: a swipe in from the left edge goes back (the bar carries where to)
		return '<nav class="songpress-userbar"' . ( '' !== $back ? ' data-back="' . esc_url( $back ) . '"' : '' ) . ' aria-label="SongPress">' . $arrow . '<span class="songpress-userl"><a class="songpress-userlink" href="' . esc_url( $p ? get_permalink( $p ) : '#' ) . '">' . $pic . '<span class="songpress-username">' . esc_html( $name ) . '</span></a></span>' . Songpress_Push::item() . ( $share_id ? self::share_icon( $share_id ) : '' ) . '</nav>';
	}

	/** The swipe-in-from-the-left-edge gesture that replaces the back button on a phone. Bound once; it reads the target from the bar on the current page. */
	private static function swipe_back() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		return '<script>(function(){if(window.__spSwipe)return;window.__spSwipe=1;var s=null;function app(){return document.querySelector(".songpress.sp-app")}function tgt(){var b=document.querySelector(".songpress-userbar[data-back]");return b?b.getAttribute("data-back"):""}'
			. 'document.addEventListener("touchstart",function(e){s=null;if(e.touches.length!==1||!tgt())return;var t=e.touches[0];if(t.clientX<22||t.clientX>44)return;if(e.target.closest&&e.target.closest("input,textarea,select,iframe"))return;s={x:t.clientX,y:t.clientY,m:""}},{passive:true});'
			. 'document.addEventListener("touchmove",function(e){if(!s)return;var t=e.touches[0],dx=t.clientX-s.x,dy=t.clientY-s.y;if(!s.m){if(Math.abs(dy)>14&&Math.abs(dy)>Math.abs(dx)){s=null;return}if(dx>10&&dx>Math.abs(dy)*1.5)s.m="h";else return}var a=app();if(a){a.style.transition="none";a.style.transform="translateX("+Math.max(0,Math.min(dx,140))+"px)";a.style.opacity=String(1-Math.min(dx,200)/500)}s.dx=dx;e.preventDefault()},{passive:false});'
			. 'function reset(){var a=app();if(a){a.style.transition="transform .18s,opacity .18s";a.style.transform="";a.style.opacity="";setTimeout(function(){a.style.transition=""},220)}}'
			. 'document.addEventListener("touchend",function(){if(!s||s.m!=="h"){s=null;return}var go=s.dx>90,u=tgt();s=null;reset();if(go&&u){var a=app(),l=document.createElement("a");l.href=u;a.appendChild(l);l.click();l.remove()}});'
			. 'document.addEventListener("touchcancel",function(){s=null;reset()})})()</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- a fixed script.
	}

	/** A share icon for the phone top bar: opens a popup with the song's public link. */
	private static function share_icon( $id ) {
		$s = Songpress_Songs::get( $id );
		if ( ! $s ) {
			return '';
		}
		$link  = add_query_arg( 'sp_share', Songpress_Songs::token_for( (int) $id ), home_url( '/' ) );
		$title = Songpress_Songs::display_title( $s );
		$o     = '<button type="button" class="songpress-sharebtn" id="sp-sh-open" aria-label="' . esc_attr__( 'Share', 'songpress' ) . '"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3M7 8l5-5 5 5M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/></svg></button>';
		$o    .= '<dialog class="spe-dialog" id="sp-sh-dlg"><form method="dialog"><h3>' . esc_html( sprintf( /* translators: %s: song title */ __( 'Share %s', 'songpress' ), $title ) ) . '</h3>';
		$o    .= '<input type="text" readonly id="sp-sh-link" value="' . esc_attr( $link ) . '" onfocus="this.select()" data-title="' . esc_attr( $title ) . '">';
		$o    .= '<div class="spe-dlgbar"><button type="button" class="spe-save" id="sp-sh-copy">' . esc_html__( 'Copy link', 'songpress' ) . '</button><a class="spe-btn" target="_blank" rel="noopener" href="' . esc_url( Songpress_Export::url( 'work', array( 'song' => (int) $id ) ) ) . '">' . esc_html__( 'Export PDF', 'songpress' ) . '</a>' . ( '' !== trim( (string) $s->chordpro ) ? '<a class="spe-btn songpress-print" target="_blank" rel="noopener" href="' . esc_url( Songpress_ChordTool::url() . '&sheet=1&song=' . (int) $id ) . '">' . esc_html__( 'Print chord sheet', 'songpress' ) . '</a>' : '' ) . '<button type="button" class="spe-btn" id="sp-sh-send" hidden>' . esc_html__( 'Share with an app', 'songpress' ) . '</button><button class="spe-link" value="close">' . esc_html__( 'Close', 'songpress' ) . '</button></div></form></dialog>';
		$o    .= '<script>(function(){var d=document.getElementById("sp-sh-dlg"),o=document.getElementById("sp-sh-open"),i=document.getElementById("sp-sh-link"),c=document.getElementById("sp-sh-copy"),s=document.getElementById("sp-sh-send");if(!d||!o)return;o.addEventListener("click",function(){if(d.showModal){d.showModal();}else{d.setAttribute("open","");}i.select();});c.addEventListener("click",function(){i.select();var done=function(){c.textContent="Copied";setTimeout(function(){c.textContent="Copy link";},1800);};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(i.value).then(done,function(){document.execCommand("copy");done();});}else{document.execCommand("copy");done();}});if(navigator.share){s.hidden=false;s.addEventListener("click",function(){navigator.share({title:i.getAttribute("data-title"),url:i.value}).catch(function(){});});}d.addEventListener("click",function(e){if(e.target===d){d.close();}});})();</script>';
		return $o;
	}

	/** The form around the list and its bar of bulk actions, which shows when something is ticked. */
	private static function bulk_open() {
		$o  = '<form method="post" id="songpress-bulk" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_bulk', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_bulk"><input type="hidden" name="sp_return" value="' . esc_url( Songpress_Editor::list_url() ) . '">';
		$o .= '<button type="button" class="songpress-bulktoggle" aria-pressed="false">' . esc_html__( 'Bulk edit', 'songpress' ) . '</button>';
		$o .= '<div class="songpress-bulkbar" hidden><b class="songpress-bulkcount"></b> ';
		$o .= '<select name="field" class="songpress-bulkfield"><option value="status">' . esc_html__( 'Status', 'songpress' ) . '</option><option value="genre">' . esc_html__( 'Genre', 'songpress' ) . '</option><option value="style">' . esc_html__( 'Style', 'songpress' ) . '</option><option value="artist">' . esc_html__( 'Band', 'songpress' ) . '</option><option value="themes">' . esc_html__( 'Themes', 'songpress' ) . '</option></select> ';
		$o .= '<select name="value_status" class="songpress-bulkstatus">';
		foreach ( Songpress_Songs::statuses() as $k => $l ) {
			$o .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>';
		}
		$o .= '</select><input type="text" name="value_text" class="songpress-bulktext" list="songpress-bulkgenres" hidden placeholder="' . esc_attr__( 'New value', 'songpress' ) . '"><datalist id="songpress-bulkgenres">';
		foreach ( Songpress_Songs::genres() as $l ) {
			$o .= '<option value="' . esc_attr( $l ) . '">';
		}
		$o .= '</datalist> <button type="submit" name="do" value="set" class="spe-save">' . esc_html__( 'Apply to selected', 'songpress' ) . '</button>' . ' <button type="button" class="spe-save songpress-bulkexport" data-url="' . esc_url( Songpress_Export::url( 'list' ) ) . '">' . esc_html__( 'Export selected PDF', 'songpress' ) . '</button>';
		$o .= '<script>(function(){var b=document.querySelector(".songpress-bulkexport");if(!b)return;b.addEventListener("click",function(){var ids=[].map.call(document.querySelectorAll(".songpress-pick:checked"),function(c){return c.value});if(!ids.length)return;window.open(b.getAttribute("data-url")+"&ids="+ids.join(","),"_blank")})})()</script>';
		if ( Songpress_Caps::can( 'delete' ) ) {
			$o .= ' <label class="songpress-bulkshowdel"><input type="checkbox" class="songpress-bulkdelon"> ' . esc_html__( 'Show delete', 'songpress' ) . '</label>';
			$o .= ' <button type="submit" name="do" value="delete" hidden class="spe-btn danger songpress-bulkdel"">' . esc_html__( 'Delete selected', 'songpress' ) . '</button>';
		}
		$o .= '</div><script>(function(){var f=document.getElementById("songpress-bulk");if(!f)return;var bar=f.querySelector(".songpress-bulkbar"),all=f.querySelector(".songpress-pickall"),fld=f.querySelector(".songpress-bulkfield"),st=f.querySelector(".songpress-bulkstatus"),tx=f.querySelector(".songpress-bulktext"),n=f.querySelector(".songpress-bulkcount");'
			. 'function boxes(){return f.querySelectorAll(".songpress-pick");}'
			. 'function sync(){var c=0;boxes().forEach(function(b){if(b.checked)c++;});bar.hidden=c===0;n.textContent=c+" selected";if(all){all.checked=c>0&&c===boxes().length;}}'
			. 'function mode(){var s=fld.value==="status";st.hidden=!s;st.disabled=!s;tx.hidden=s;tx.disabled=s;tx.setAttribute("list",fld.value==="genre"?"songpress-bulkgenres":"");}'
			. 'f.addEventListener("change",function(e){if(e.target===all){boxes().forEach(function(b){b.checked=all.checked;});}if(e.target===fld){mode();}sync();});'
			. 'f.addEventListener("click",function(e){var b=e.target.closest("[data-confirm]");if(b&&!window.confirm(b.getAttribute("data-confirm"))){e.preventDefault();}});'
			. 'var K="songpress_bulk_on",D="songpress_bulk_del",tg=f.querySelector(".songpress-bulktoggle"),dc=f.querySelector(".songpress-bulkdelon"),db=f.querySelector(".songpress-bulkdel");'
			. 'function rd(k){try{return localStorage.getItem(k)==="1";}catch(x){return false;}}function wr(k,v){try{localStorage.setItem(k,v?"1":"0");}catch(x){}}'
			. 'function on(v){f.classList.toggle("sp-bulk-on",v);tg.setAttribute("aria-pressed",v?"true":"false");if(!v){boxes().forEach(function(b){b.checked=false;});if(all){all.checked=false;}}sync();}'
			. 'function dl(v){if(dc){dc.checked=v;}if(db){db.hidden=!v;db.disabled=!v;}}'
			. 'tg.addEventListener("click",function(){var v=!f.classList.contains("sp-bulk-on");wr(K,v);on(v);});'
			. 'if(dc){dc.addEventListener("change",function(){wr(D,dc.checked);dl(dc.checked);});}'
			. 'mode();on(rd(K));dl(rd(D));})();</script>';
		return $o;
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
		$ic = function ( $d ) {
			return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
		};
		$items = array(
			'catalog' => array( get_permalink( $c ), __( 'Works', 'songpress' ), $ic( '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>' ) ),
			'radio'   => array( add_query_arg( 'sp_radio', '1', get_permalink( $c ) ), __( 'Radio', 'songpress' ), $ic( '<circle cx="12" cy="12" r="2"/><path d="M8 8a5.7 5.7 0 000 8M16 8a5.7 5.7 0 010 8M5 5a10 10 0 000 14M19 5a10 10 0 010 14"/>' ) ),
			'profile' => array( get_permalink( $p ), __( 'Profile', 'songpress' ), $ic( '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>' ) ),
		);
		$o = '<nav class="songpress-tabs songpress-tabs-' . $pos . ( wp_is_mobile() && 'bottom' === $pos ? ' songpress-tabs-fixed' : '' ) . '" aria-label="SongPress">';
		foreach ( $items as $k => $it ) {
			$o .= '<a class="' . ( $k === $on ? 'on' : '' ) . '"' . ( $k === $on ? ' aria-current="page"' : '' ) . ' href="' . esc_url( $it[0] ) . '">' . $it[2] . '<span>' . esc_html( $it[1] ) . '</span></a>';
		}
		// Help sits last, at the far right; it opens the tour over whatever you were looking at
		$o .= '<button type="button" class="songpress-tabhelp" data-sp-tour>' . $ic( '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 015 .5c0 1.7-2.5 2-2.5 3.5M12 17.2v.1"/>' ) . '<span>' . esc_html__( 'Help', 'songpress' ) . '</span></button>';
		return $o . ( 'bottom' === $pos ? self::nav_memory() : '' ) . '</nav>' . Songpress_Tour::boot();
	}

	/** A "back" link for phone pages that sit one level down: goes where you came from, else to Works. */
	private static function back_link() {
		$c = self::main_id( 'catalog' );
		return '<a class="songpress-back" href="' . esc_url( $c ? get_permalink( $c ) : home_url( '/' ) ) . '" onclick="if(history.length>1&&document.referrer.indexOf(location.origin)===0){history.back();return false}">&lsaquo; ' . esc_html__( 'Back', 'songpress' ) . '</a>';
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
		$edit .= wp_is_mobile() ? '' : '<a class="songpress-editlink songpress-exportlink" target="_blank" rel="noopener" href="' . esc_url( Songpress_Export::url( 'work', array( 'song' => (int) $id ) ) ) . '">' . esc_html__( 'Export PDF', 'songpress' ) . '</a>';
		return '<div class="songpress sp-app">' . ( wp_is_mobile() ? self::userbar( 'catalog', $id, remove_query_arg( 'sp_song' ) ) : self::tabs( 'catalog', 'top' ) ) . self::song_body( $s, $uid, $edit . ( wp_is_mobile() ? '' : '<a class="songpress-back" href="' . esc_url( remove_query_arg( 'sp_song' ) ) . '">&lsaquo; ' . esc_html__( 'Works', 'songpress' ) . '</a>' ) ) . self::tabs( 'catalog' ) . '</div>';
	}

	/** The ChordPro level as the printable chord sheet (the PDF layout), with print and share icons above it. */
	private static function sheet_panel( $s, $uid ) {
		$tool  = Songpress_ChordTool::url() . '&sheet=1';
		$q     = $uid ? '&song=' . (int) $s->id : '&t=' . rawurlencode( (string) $s->share_token );
		$share = '' !== (string) $s->share_token ? $tool . '&t=' . rawurlencode( (string) $s->share_token ) : '';
		$ico   = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
		$key   = trim( (string) $s->musical_key );
		$o     = '<div class="songpress-sheetbar"><div class="songpress-tr" role="group" aria-label="' . esc_attr__( 'Transpose', 'songpress' ) . '"><button type="button" class="songpress-sheetbtn" data-tr="-1" aria-label="' . esc_attr__( 'Down a half step', 'songpress' ) . '">&minus;</button><span class="songpress-trkey" data-key="' . esc_attr( $key ) . '"><small>' . esc_html__( 'Transpose', 'songpress' ) . '</small><b>' . esc_html( '' !== $key ? $key : '0' ) . '</b></span><button type="button" class="songpress-sheetbtn" data-tr="1" aria-label="' . esc_attr__( 'Up a half step', 'songpress' ) . '">+</button><button type="button" class="songpress-sheetbtn songpress-trfl" data-fl="1" aria-pressed="false" title="' . esc_attr__( 'Spell chords with sharps or flats', 'songpress' ) . '">&sharp;</button><button type="button" class="songpress-sheetbtn songpress-trreset" data-tr="0" hidden aria-label="' . esc_attr__( 'Back to the original key', 'songpress' ) . '">&#8634;</button></div>';
		if ( '' !== $share ) {
			$o .= '<button type="button" class="songpress-sheetbtn songpress-sharebtn" data-share="' . esc_url( $share ) . '" data-title="' . esc_attr( Songpress_Songs::display_title( $s ) ) . '" title="' . esc_attr__( 'Share the chord sheet', 'songpress' ) . '" aria-label="' . esc_attr__( 'Share the chord sheet', 'songpress' ) . '">' . $ico . '<path d="M12 3v12M8 7l4-4 4 4M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7"/></svg></button>';
		}
		$o .= '</div><iframe class="songpress-sheetframe" title="' . esc_attr__( 'Chord sheet', 'songpress' ) . '" src="' . esc_url( $tool . $q . '&nobar=1' ) . '" loading="lazy"></iframe>';
		$o .= '<script>(function(){var r=document.currentScript.parentNode,f=r.querySelector("iframe"),sb=r.querySelector("[data-share]"),pr=document.querySelectorAll(".songpress-print"),kb=r.querySelector(".songpress-trkey b"),k0=r.querySelector(".songpress-trkey").getAttribute("data-key"),rs=r.querySelector(".songpress-trreset"),fb=r.querySelector(".songpress-trfl"),n=0,fl=false,P0=pr.length?pr[0].getAttribute("href"):"",S0=sb?sb.getAttribute("data-share"):"";'
			. 'var SH=["C","C#","D","D#","E","F","F#","G","G#","A","A#","B"],FL=["C","Db","D","Eb","E","F","Gb","G","Ab","A","Bb","B"],IX={C:0,"C#":1,Db:1,D:2,"D#":3,Eb:3,E:4,F:5,"F#":6,Gb:6,G:7,"G#":8,Ab:8,A:9,"A#":10,Bb:10,B:11};'
			. 'function post(){if(f.contentWindow)f.contentWindow.postMessage({spTranspose:n,spFlats:fl},location.origin)}'
			. 'function ui(){var q=n?"&tr="+n+(fl?"&fl=1":""):"";for(var z=0;z<pr.length;z++)pr[z].setAttribute("href",P0+q);if(sb)sb.setAttribute("data-share",S0+q);rs.hidden=!n;var m=/^([A-G][#b]?)(.*)$/.exec(k0||"");kb.textContent=m?((fl?FL:SH)[((IX[m[1]]+n)%12+12)%12]+m[2]):(n?(n>0?"+":"")+n:"0");fb.textContent=fl?"\u266d":"\u266f";fb.setAttribute("aria-pressed",fl?"true":"false")}'
			. 'r.addEventListener("click",function(e){var t=e.target.closest("[data-tr]"),g=e.target.closest(".songpress-trfl");if(t){var v=parseInt(t.getAttribute("data-tr"),10);n=v?((n+v)%12+12)%12:0;if(n>6)n-=12;ui();post()}else if(g){fl=!fl;ui();post()}});'
			. 'window.addEventListener("message",function(e){if(e.origin!==location.origin||!e.data||e.source!==f.contentWindow)return;if(e.data.spSheetH)f.style.height=e.data.spSheetH+"px";if(e.data.spSheetReady&&(n||fl))post()});'
			. 'if(sb)sb.addEventListener("click",function(){var u=sb.getAttribute("data-share"),t=sb.getAttribute("data-title");if(navigator.share){navigator.share({title:t,url:u}).catch(function(){})}else if(navigator.clipboard){navigator.clipboard.writeText(u).then(function(){sb.title="Link copied"})}else{window.prompt("",u)}});ui()})()</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped parts and a fixed script.
		return $o;
	}

	/** The song itself: cover, audio, title, writers, chart. Shared by the logged-in view and the public share page. */
	private static function song_body( $s, $uid, $top = '' ) {
		$id = (int) $s->id;
		list( $kind, $text ) = Songpress_Songs::best_chart( $s );
		$labels = array( 'chordpro' => 'ChordPro', 'chords_lyrics' => __( 'Chords and lyrics', 'songpress' ), 'lyrics' => __( 'Lyrics', 'songpress' ), 'none' => '' );
		// Header: cover art on the left; beside it the title, the artist and the authors as one row of icons. The player runs the full width underneath.
		$is_root = 0 === (int) $s->version_no;
		$name    = $is_root ? $s->title : $s->release_title; // only an original work shows the work name; a version shows its release title
		$audio = Songpress_Media::audio_url( $s );
		$cover = self::art( $s, 'songpress-cover' );
		$tap   = wp_is_mobile() && '' !== $audio; // on a phone the radio bar plays the work: the cover is the play button, so there is no separate player
		if ( $tap ) {
			$cover = '<button type="button" class="songpress-play songpress-coverplay" data-src="' . esc_url( $audio ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: song title */ __( 'Play %s', 'songpress' ), Songpress_Songs::display_title( $s ) ) ) . '">' . $cover . '<span class="songpress-playicon" aria-hidden="true"></span></button>';
		}
		$out  = '<article class="songpress-song" data-sp-id="' . $id . '">' . $top . '<header class="songpress-top"><div class="songpress-card2">' . $cover . '<div class="songpress-topl">';
		$out .= '<p class="songpress-sub"><span class="songpress-serial">' . esc_html( $s->serial ) . '</span> ' . esc_html( Songpress_Songs::effective_status( $s ) ) . '</p>';
		if ( '' !== trim( (string) $name ) ) {
			$out .= '<h2>' . esc_html( $name ) . '</h2>';
		} elseif ( '' !== trim( (string) Songpress_Songs::display_title( $s ) ) ) {
			$out .= '<h2 class="songpress-notitle">' . esc_html( Songpress_Songs::display_title( $s ) ) . '</h2>'; // a version with no release title yet: the work name
		}
		$under = '' !== trim( (string) $s->artist ) ? $s->artist : $s->genre; // a band, when there is one, replaces the genre
		if ( '' !== trim( (string) $under ) ) {
			$out .= '<p class="songpress-sub songpress-genre-line">' . esc_html( $under ) . '</p>';
		}
		$out .= '</div>'; // the text column; the card stays open for the credits row
		// Under the card, centred: the band, the writers and the performers, each on its own labelled row.
		$names = function ( $role ) use ( $id ) {
			$seen = array();
			foreach ( Songpress_Songs::credits( $id ) as $c ) {
				$k = Songpress_Profiles::key( $c->name );
				if ( $role === $c->role && '' !== $k && ! isset( $seen[ $k ] ) ) {
					$seen[ $k ] = $c->name;
				}
			}
			return array_values( $seen );
		};
		$wr   = $names( 'writer' );
		$pf   = $names( 'performer' );
		$all  = array();
		foreach ( array_merge( $wr, $pf ) as $n ) {
			$all[ Songpress_Profiles::key( $n ) ] = $n;
		}
		$cg = '';
		if ( $all ) {
			$cg .= '<div class="songpress-avs">';
			foreach ( array_slice( array_values( $all ), 0, 4 ) as $n ) {
				$cg .= '<span title="' . esc_attr( $n ) . '">' . Songpress_Profiles::chip( $n, 34, 'person' ) . '</span>';
			}
			$cg .= '</div><div class="songpress-crl">';
			if ( $wr ) {
				$cg .= '<div><b>' . esc_html__( 'Written', 'songpress' ) . '</b> ' . esc_html( implode( ', ', $wr ) ) . '</div>';
			}
			if ( $pf ) {
				$cg .= '<div><b>' . esc_html__( 'Performed', 'songpress' ) . '</b> ' . esc_html( implode( ', ', $pf ) ) . '</div>';
			}
			$cg .= '</div>';
		}
		// Under the card: the credits, the streaming links (centred), the player, then the favourite / rate / playlist buttons.
		$out .= ( '' === $cg ? '' : '<div class="songpress-credits">' . $cg . '</div>' ) . '</div>'; // closes the card
		$out .= Songpress_Editor::stream_links_html( Songpress_Songs::links( $id ) );
		if ( '' !== $audio && ! $tap ) {
			$out .= '<audio class="songpress-audio" controls preload="none" src="' . esc_url( $audio ) . '"></audio>';
		}
		$out .= Songpress_Reactions::bar( $s, $uid ? '' : (string) $s->share_token );
		$out .= '</header><div class="songpress-main">';
		if ( 'none' !== $kind ) {
			// the lyric process: any level can be shown; a level nobody typed falls back to the highest one, stepped down
			$lv   = Songpress_Songs::lyric_levels( $s );
			$want = isset( $_GET['sp_level'] ) ? sanitize_key( wp_unslash( $_GET['sp_level'] ) ) : $kind; // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! isset( $lv[ $want ] ) || '' === $lv[ $want ][0] ) {
				$want = $kind;
			}
			// every level that has text is a tab; tapping one swaps the chart without leaving the page
			$many = count( array_filter( array_column( $lv, 0 ) ) ) > 1;
			$out .= '<div class="songpress-tabs" role="tablist"' . ( $many ? '' : ' hidden' ) . '>';
			$pan  = '';
			foreach ( $labels as $lk => $ll ) {
				if ( 'none' === $lk || '' === $lv[ $lk ][0] ) {
					continue;
				}
				$on   = $lk === $want;
				$out .= '<button type="button" role="tab" class="songpress-tab' . ( $on ? ' on' : '' ) . '" data-lv="' . esc_attr( $lk ) . '" aria-selected="' . ( $on ? 'true' : 'false' ) . '">' . esc_html( $ll ) . '</button>';
				$pan .= '<div class="songpress-panel" data-lv="' . esc_attr( $lk ) . '"' . ( $on ? '' : ' hidden' ) . '>' . ( 'chordpro' === $lk ? self::sheet_panel( $s, $uid ) : '<pre class="songpress-chart">' . esc_html( $lv[ $lk ][0] ) . '</pre>' ) . '</div>';
			}
			$out .= '</div>' . $pan . '<script>(function(){var m=document.currentScript.parentNode,t=m.querySelectorAll(".songpress-tab");t.forEach(function(b){b.addEventListener("click",function(){t.forEach(function(x){var o=x===b;x.classList.toggle("on",o);x.setAttribute("aria-selected",o?"true":"false");});m.querySelectorAll(".songpress-panel").forEach(function(p){p.hidden=p.getAttribute("data-lv")!==b.getAttribute("data-lv");});});});})();</script>';
		}
		return $out . '</div></article>';
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
		$rows = Songpress_Songs::credited_rows( $u->ID, $u->display_name ); // the releases: every version, and an original that has none
		$orig = array();
		foreach ( Songpress_Songs::credited_rows( $u->ID, $u->display_name, true ) as $r ) { // works: one per family
			$fk          = (int) $r->family_id ? (int) $r->family_id : -(int) $r->id; // one per work (the NNNN of NNNN-VV), whether or not its original row exists
			$orig[ $fk ] = 1;
		}
		$today = current_time( 'Y-m-d' );
		$rows  = array_values( array_filter( $rows, function ( $s ) use ( $today ) { // the list is releases only: released by status, or dated on/before today
			$d = trim( (string) $s->release_date );
			return 'released' === $s->status || ( '' !== $d && $d <= $today );
		} ) );
		$res   = array( 'rows' => $rows, 'total' => count( $rows ) );
		$rel   = count( $rows );
		$ini = '';
		foreach ( array_slice( preg_split( '/\s+/', trim( $u->display_name ) ), 0, 2 ) as $w ) {
			$ini .= function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $w, 0, 1 ) ) : strtoupper( substr( $w, 0, 1 ) );
		}
		$pf   = Songpress_Profiles::find( $u->display_name, 'person' );
		$face = $pf ? Songpress_Profiles::photo_url( $pf ) : '';
		$out  = '<div class="songpress songpress-profile sp-app">' . ( wp_is_mobile() ? self::userbar( 'profile' ) : self::tabs( 'profile', 'top' ) ) . '<div class="songpress-profile-head"><div class="songpress-avatar" aria-hidden="true">' . ( '' !== $face ? '<img src="' . esc_url( $face ) . '" alt="">' : esc_html( $ini ) ) . '</div><div><h2>' . esc_html( $u->display_name ) . '</h2>';
		$out .= '<p class="songpress-sub">' . esc_html( sprintf( /* translators: %d: number of songs */ _n( 'Credited on %d work', 'Credited on %d works', count( $orig ), 'songpress' ), count( $orig ) ) ) . '</p></div></div>';
		$out .= '<ul class="songpress-stats"><li>' . esc_html__( 'Works', 'songpress' ) . '<strong>' . count( $orig ) . '</strong></li><li>' . esc_html__( 'Releases', 'songpress' ) . '<strong>' . (int) $rel . '</strong></li></ul>';
		if ( wp_is_mobile() ) {
			$out .= '<p class="songpress-mycat"><a class="songpress-mycatbtn" target="_blank" rel="noopener" href="' . esc_url( Songpress_Export::url( 'list', array( 'mine' => 1 ) ) ) . '">' . esc_html__( 'Export my Catalog', 'songpress' ) . '</a></p>';
		}
		$out .= Songpress_Profiles::avatar_tool( $u ) . Songpress_Profiles::mine_form( $u );
		$out .= Songpress_Money::quarterly_html( $u->display_name );
		// the songs this person wrote or performed, each with what it has made on DistroKid
		$mine = array();
		$role = array();
		foreach ( $res['rows'] as $s ) {
			foreach ( Songpress_Songs::credits( (int) $s->id ) as $c ) {
				if ( in_array( $c->role, array( 'writer', 'performer' ), true ) && ( (int) $c->user_id === (int) $u->ID || Songpress_Profiles::key( $c->name ) === Songpress_Profiles::key( $u->display_name ) ) ) {
					$role[ (int) $s->id ][ $c->role ] = 1;
				}
			}
			if ( ! empty( $role[ (int) $s->id ] ) ) {
				$mine[] = $s;
			}
		}
		if ( $mine ) {
			$dk   = Songpress_Money::my_song_totals( $u->display_name );
			$cat  = self::main_id( 'catalog' );
			$base = $cat ? get_permalink( $cat ) : home_url( '/' );
			$out .= '<h3>' . esc_html__( 'Releases', 'songpress' ) . '</h3>' . ( wp_is_mobile() ? '<input type="search" class="songpress-mysearch" placeholder="' . esc_attr__( 'Search your works', 'songpress' ) . '" aria-label="' . esc_attr__( 'Search your works', 'songpress' ) . '" autocomplete="off"><p class="songpress-note songpress-mynone" hidden>' . esc_html__( 'No works match.', 'songpress' ) . '</p>' : '' ) . '<ul class="songpress-mysongs">';
			foreach ( $mine as $s ) {
				$id    = (int) $s->id;
				$r     = array();
				foreach ( array( 'writer' => __( 'Writer', 'songpress' ), 'performer' => __( 'Performer', 'songpress' ) ) as $k => $l ) {
					if ( isset( $role[ $id ][ $k ] ) ) {
						$r[] = $l;
					}
				}
				$title = Songpress_Songs::display_title( $s );
				$amt   = $dk['id'][ $id ] ?? ( $dk['title'][ Songpress_Money::norm_title( $s->title ) ] ?? 0 );
				$out  .= '<li><a href="' . esc_url( add_query_arg( 'sp_song', $id, $base ) ) . '"><span class="songpress-mytitle"><span class="songpress-serial">' . esc_html( $s->serial ) . '</span> ' . esc_html( $title ) . '</span><span class="songpress-myrole">' . esc_html( implode( ' · ', $r ) ) . '</span></a><span class="songpress-mydk">' . ( $amt ? esc_html( Songpress_Money::money( $amt ) ) : '&ndash;' ) . '</span></li>';
			}
			$out .= '</ul>' . ( wp_is_mobile() ? '<script>(function(){var i=document.querySelector(".songpress-mysearch"),l=document.querySelector(".songpress-mysongs"),n=document.querySelector(".songpress-mynone");if(!i||!l)return;i.addEventListener("input",function(){var q=i.value.toLowerCase().trim(),c=0;[].forEach.call(l.children,function(li){var m=!q||li.textContent.toLowerCase().indexOf(q)>-1;li.hidden=!m;if(m)c++});n.hidden=c>0})})()</script>' : '' ) . '<p class="songpress-note">' . esc_html__( 'Amounts are what you have earned from each song.', 'songpress' ) . '</p>';
		}
		$out .= ( wp_is_mobile() ? Songpress_Reactions::banner() : '' ) . wp_kses_post( songpress_public_donate_link() ) . self::tabs( 'profile' ) . '</div>'; // the player script is on every phone screen, so a tap on a tab never reloads the app
		return $out;
	}
}
