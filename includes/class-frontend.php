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
		$o .= '<form method="post" action="' . esc_url( site_url( 'wp-login.php', 'login_post' ) ) . '">';
		$o .= '<label for="sp-u">' . esc_html__( 'Username', 'songpress' ) . '</label><input id="sp-u" name="log" type="text" value="' . esc_attr( isset( $_GET['sp_user'] ) ? sanitize_user( wp_unslash( $_GET['sp_user'] ) ) : '' ) . '" autocomplete="username" autocapitalize="none" required>';
		$o .= '<label for="sp-p">' . esc_html__( 'Password', 'songpress' ) . '</label><input id="sp-p" name="pwd" type="password" autocomplete="current-password" required>';
		$o .= '<input type="hidden" name="rememberme" value="forever"><input type="hidden" name="sp_app" value="1"><input type="hidden" name="redirect_to" value="' . esc_url( remove_query_arg( 'sp_login', $dest ) ) . '">';
		$o .= '<button type="submit">' . esc_html__( 'Log in', 'songpress' ) . '</button></form>';
		$o .= '<p class="sp-forgot">' . esc_html__( 'Forgot your password? Ask the person who invited you to set a new one.', 'songpress' ) . '</p></section>';
		$o .= '<p class="sp-forgot">' . sprintf( /* translators: %s: link to the ChordPro tool */ esc_html__( 'Looking for a chord-sheet program like Songpress (Skeed / Lallulli)? Use the %s, free, no login.', 'songpress' ), '<a href="' . esc_url( Songpress_ChordTool::url() ) . '">' . esc_html__( 'web ChordPro tool', 'songpress' ) . '</a>' ) . '</p>';
		$o .= wp_kses_post( songpress_public_donate_link() ) . '</main></div>';
		return $o;
	}

	private static function nav_item( $href, $label, $icon, $on, $badge = 0 ) {
		return '<a href="' . esc_url( $href ) . '"' . ( $on ? ' aria-current="page" class="on"' : '' ) . '>' . $icon . esc_html( $label ) . ( $badge > 0 ? ' <span class="sp-badge" title="' . esc_attr__( 'Follow-ups due', 'songpress' ) . '">' . (int) $badge . '</span>' : '' ) . '</a>';
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
		$avatar  = get_avatar_url( get_current_user_id(), array( 'size' => 36 ) );
		$cu      = wp_get_current_user();
		$who     = '' !== trim( (string) $cu->display_name ) ? $cu->display_name : __( 'Profile', 'songpress' );
		$face    = $avatar ? '<img class="sp-me" src="' . esc_url( $avatar ) . '" width="22" height="22" alt="">' : $me;
		$nav     = self::nav_item( $pro_url, $who, $face, 'profile' === $which ) . self::nav_item( $cat_url, __( 'Works', 'songpress' ), $songs, 'catalog' === $which && $in_app );
		$mk = function ( $slug, $label, $icon, $cap, $badge = 0 ) use ( $cat_url, $page ) {
			$on = $page === $slug || ( 'settings' === $slug && in_array( $page, array( 'backups', 'health', 'activity', 'uploads', 'import' ), true ) ) || ( 'registrations' === $slug && 'agreements' === $page ) || ( 'agreements' === $slug && 'registrations' === $page );
			return Songpress_Caps::can( $cap ) ? self::nav_item( add_query_arg( 'sp_page', $slug, $cat_url ), $label, $icon, $on, $badge ) : '';
		};
		if ( Songpress_Caps::can( 'journal' ) && ! wp_is_mobile() ) {
			$nav .= $mk( 'notes', __( 'Notes', 'songpress' ), $svg( '<path d="M6 3h12a1 1 0 011 1v16a1 1 0 01-1 1H6zM6 3v18M10 8h5M10 12h5"/>' ), 'journal' );
		}
		if ( Songpress_Caps::can( 'pitch' ) && ! wp_is_mobile() ) {
			$nav .= $mk( 'pitch', __( 'Pitch', 'songpress' ), $svg( '<path d="M3 11v2a1 1 0 001 1h2l5 4V6L6 10H4a1 1 0 00-1 1zM15 9a4 4 0 010 6M18 6.5a8 8 0 010 11"/>' ), 'pitch', Songpress_Pitch::due_count() );
		}
		if ( Songpress_Caps::can( 'money' ) && ! wp_is_mobile() ) {
			$nav .= $mk( 'money', __( 'Stats', 'songpress' ), $svg( '<circle cx="12" cy="12" r="9"/><path d="M14.5 9a2.5 2 0 00-2.5-1.5c-1.5 0-2.5.8-2.5 2s1 1.7 2.5 2 2.5.8 2.5 2-1 2-2.5 2A2.5 2 0 019.5 15M12 6v1.5M12 16.5V18"/>' ), 'money' ) . $mk( 'stats', __( 'Stats', 'songpress' ), $svg( '<path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/>' ), 'money' );
		}
		if ( Songpress_Caps::can( 'members' ) ) {
			$cur = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : 'person';
			$nav .= self::nav_item( add_query_arg( array( 'sp_page' => 'people', 'kind' => 'person' ), $cat_url ), __( 'People', 'songpress' ), $people, 'people' === $page || 'link' === $page || 'artists' === $page );
		}
		$bot  = $mk( 'settings', __( 'Settings', 'songpress' ), $manage, 'settings' );
		$body = do_shortcode( 'catalog' === $which ? '[songpress_catalog]' : '[songpress_profile]' );
		return '<div class="sp-shell"><nav class="sp-side" aria-label="SongPress"><div class="sp-side-logo"><img src="' . esc_url( SONGPRESS_URL . 'assets/icon.svg' ) . '" width="36" height="36" alt=""><span class="sp-side-name"><b>SongPress</b><small>' . esc_html__( 'A songwriting and publishing', 'songpress' ) . '</small></span></div>' . $nav . '<span class="sp-grow"></span>' . $bot . $out . '</nav><main class="sp-main">' . $body . '</main></div>' . '<script>(function(){var s=document.querySelector(".sp-shell > .sp-side");if(!s){return;}var sh=s.parentNode,moved=false;function check(){if(moved||window.innerWidth<760||window.pageYOffset<40){return;}var b=document.getElementById("wpadminbar"),t=b&&getComputedStyle(b).position==="fixed"?b.offsetHeight:0;if(Math.abs(s.getBoundingClientRect().top-t)>2){moved=true;document.body.appendChild(sh);window.removeEventListener("scroll",check);}}window.addEventListener("scroll",check,{passive:true});})();</script>';
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
			return self::song_view( absint( $_GET['sp_song'] ), $uid ); // nothing under the chart
		}
		$q      = isset( $_GET['sp_q'] ) ? sanitize_text_field( wp_unslash( $_GET['sp_q'] ) ) : '';
		$status = isset( $_GET['sp_status'] ) ? sanitize_key( wp_unslash( $_GET['sp_status'] ) ) : '';
		$status = isset( Songpress_Songs::statuses()[ $status ] ) ? $status : '';
		$order  = isset( $_GET['sp_sort'] ) ? sanitize_key( wp_unslash( $_GET['sp_sort'] ) ) : '';
		$order  = in_array( $order, array( 'serial', 'title', 'genre', 'writer', 'artist', 'label', 'status' ), true ) ? $order : '';
		$dir    = ( isset( $_GET['sp_dir'] ) && 'desc' === $_GET['sp_dir'] ) ? 'desc' : 'asc';
		$res    = Songpress_Songs::query( array( 'search' => $q, 'status' => $status, 'per_page' => 40, 'order' => $order, 'dir' => $dir, 'user_id' => Songpress_Caps::can( 'view_all' ) ? 0 : $uid ) );
		$out    = '<div class="songpress sp-app">' . ( $desktop ? self::tabs( 'catalog', 'top' ) . self::head( __( 'Works', 'songpress' ) ) : self::userbar( 'catalog' ) );
		if ( $desktop && isset( $_GET['sp_deleted'] ) ) {
			$out .= '<p class="songpress-note">' . esc_html( sprintf( /* translators: %d: number of songs */ _n( 'Deleted %d song.', 'Deleted %d songs.', absint( $_GET['sp_deleted'] ), 'songpress' ), absint( $_GET['sp_deleted'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( $desktop && isset( $_GET['sp_bulk'] ) ) {
			$out .= '<p class="songpress-note">' . esc_html( sprintf( /* translators: 1: songs changed, 2: songs skipped */ __( 'Updated %1$d songs. Skipped %2$d (not allowed, or the field does not apply to an original work).', 'songpress' ), absint( $_GET['sp_bulk'] ), absint( $_GET['sp_bskip'] ?? 0 ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( $desktop && isset( $_GET['sp_err'] ) ) {
			$out .= '<p class="songpress-note">' . esc_html( sanitize_text_field( wp_unslash( $_GET['sp_err'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( $desktop ) {
			$ov = '';
			foreach ( array( 'registrations' => __( 'All registrations', 'songpress' ), 'agreements' => __( 'All agreements', 'songpress' ) ) as $slug => $label ) {
				if ( Songpress_Caps::can( $slug ) ) {
					$ov .= '<a class="spe-btn" href="' . esc_url( add_query_arg( 'sp_page', $slug, Songpress_Editor::list_url() ) ) . '">' . esc_html( $label ) . '</a> ';
				}
			}
			$out .= '' !== $ov ? '<p class="songpress-ov">' . $ov . '</p>' : '';
		}
		$add    = ( $desktop && Songpress_Caps::can( 'create' ) ) ? '<a class="songpress-add" href="' . esc_url( Songpress_Editor::edit_url( 0 ) ) . '" aria-label="' . esc_attr__( 'Add a song', 'songpress' ) . '" title="' . esc_attr__( 'Add a song', 'songpress' ) . '">+</a>' : '';
		$out   .= '<div class="songpress-searchbar"><form method="get" class="songpress-search"><input type="search" name="sp_q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Search songs, people, lyrics, style', 'songpress' ) . '" aria-label="' . esc_attr__( 'Search songs', 'songpress' ) . '"></form>' . $add . '</div>';
		$bulk = $desktop && ( Songpress_Caps::can( 'edit_any' ) || Songpress_Caps::can( 'edit_own' ) );
		if ( $bulk ) {
			$out .= self::bulk_open();
		}
		if ( $desktop ) { // phones show the list with no column headings
			$out .= '<div class="songpress-colhead" role="row">' . ( $bulk ? '<input type="checkbox" class="songpress-pickall" aria-label="' . esc_attr__( 'Select all', 'songpress' ) . '">' : '' ) . '<span class="songpress-ch-play"></span><span class="songpress-ch-main">';
			foreach ( array( 'serial' => __( 'No.', 'songpress' ), 'title' => __( 'Title', 'songpress' ), 'genre' => __( 'Genre', 'songpress' ), 'writer' => __( 'Writers', 'songpress' ), 'artist' => __( 'Band', 'songpress' ), 'label' => __( 'Label', 'songpress' ), 'status' => __( 'Status', 'songpress' ) ) as $k => $label ) {
				$on   = ( $k === $order );
				$next = ( $on && 'asc' === $dir ) ? 'desc' : 'asc';
				$out .= '<a class="songpress-ch-' . esc_attr( $k ) . ( $on ? ' on' : '' ) . '" href="' . esc_url( add_query_arg( array( 'sp_sort' => $k, 'sp_dir' => $next ) ) ) . '"' . ( $on ? ' aria-sort="' . ( 'asc' === $dir ? 'ascending' : 'descending' ) . '"' : '' ) . '>' . esc_html( $label ) . ( $on ? ( 'asc' === $dir ? ' &#9650;' : ' &#9660;' ) : '' ) . '</a>';
			}
			$out .= '</span></div>';
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
			if ( ! $desktop ) { // phones: a card with the cover art on the right; number, release title and artist on the left, style at the far right
				$rel   = 0 === (int) $s->version_no ? $s->title : $s->release_title; // only an original work shows the work name; a version shows its release title
				$none  = ( 0 !== (int) $s->version_no && '' === trim( (string) $rel ) );
				$out  .= '<li class="songpress-card">' . $art . '<a class="songpress-cardlink" href="' . $link . '"><span class="songpress-cardtext"><span class="songpress-serial">' . esc_html( $s->serial ) . '</span><span class="songpress-title' . ( $none ? ' songpress-notitle' : '' ) . '">' . esc_html( $none ? __( 'No release title', 'songpress' ) : $rel ) . '</span><span class="songpress-cardline"><span class="songpress-cardartist">' . esc_html( $s->artist ) . '</span>' . ( '' !== trim( (string) $s->style ) ? '<span class="songpress-cardstyle">' . esc_html( $s->style ) . '</span>' : '' ) . '</span></span></a></li>';
				continue;
			}
			$out .= '<li class="songpress-row">' . ( $bulk ? '<input type="checkbox" class="songpress-pick" name="ids[]" value="' . (int) $s->id . '" aria-label="' . esc_attr( sprintf( /* translators: %s: song title */ __( 'Select %s', 'songpress' ), $s->title ) ) . '">' : '' ) . $art . '<a class="songpress-rowlink" href="' . $link . '">'
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
		return $out . '</ul>' . ( $bulk ? '</form>' : '' ) . '<script>(function(){var a=null,b=null;document.addEventListener(\'click\',function(e){var x=e.target.closest&&e.target.closest(\'.songpress-play[data-src]\');if(!x)return;e.preventDefault();if(b===x&&a&&!a.paused){a.pause();return;}if(a){a.pause();}if(b){b.classList.remove(\'on\');}b=x;a=new Audio(x.getAttribute(\'data-src\'));a.addEventListener(\'play\',function(){x.classList.add(\'on\');});a.addEventListener(\'pause\',function(){x.classList.remove(\'on\');});a.addEventListener(\'ended\',function(){x.classList.remove(\'on\');});a.play();});})();</script>' . wp_kses_post( songpress_public_donate_link() ) . ( $desktop ? self::tabs( 'catalog' ) : '' ) . '</div>';
	}

	/**
	 * The top of every phone screen: the person's picture and name, which open their profile. On the profile itself it is a way back
	 * to the works. The profile shows only when this is tapped.
	 */
	private static function userbar( $on, $share_id = 0 ) {
		$c = self::main_id( 'catalog' );
		$p = self::main_id( 'profile' );
		if ( 'profile' === $on ) {
			return '<nav class="songpress-userbar" aria-label="SongPress"><a class="songpress-userlink" href="' . esc_url( $c ? get_permalink( $c ) : home_url( '/' ) ) . '">&lsaquo; ' . esc_html__( 'Works', 'songpress' ) . '</a></nav>';
		}
		$u    = wp_get_current_user();
		$name = '' !== trim( (string) $u->display_name ) ? $u->display_name : __( 'Profile', 'songpress' );
		$pf   = Songpress_Profiles::find( $u->display_name, 'person' );
		$face = $pf ? Songpress_Profiles::photo_url( $pf ) : '';
		if ( '' === $face ) {
			$face = (string) get_avatar_url( $u->ID, array( 'size' => 72 ) );
		}
		$pic = '' !== $face ? '<img class="songpress-userpic" src="' . esc_url( $face ) . '" width="36" height="36" alt="">' : '<span class="songpress-userpic songpress-userpic-ini" aria-hidden="true">' . esc_html( function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $name, 0, 1 ) ) : strtoupper( substr( $name, 0, 1 ) ) ) . '</span>';
		return '<nav class="songpress-userbar" aria-label="SongPress"><a class="songpress-userlink" href="' . esc_url( $p ? get_permalink( $p ) : '#' ) . '">' . $pic . '<span class="songpress-username">' . esc_html( $name ) . '</span></a>' . ( $share_id ? self::share_icon( $share_id ) : '' ) . '</nav>';
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
		$o    .= '<div class="spe-dlgbar"><button type="button" class="spe-save" id="sp-sh-copy">' . esc_html__( 'Copy link', 'songpress' ) . '</button><button type="button" class="spe-btn" id="sp-sh-send" hidden>' . esc_html__( 'Share with an app', 'songpress' ) . '</button><button class="spe-link" value="close">' . esc_html__( 'Close', 'songpress' ) . '</button></div></form></dialog>';
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
		$o .= '</datalist> <button type="submit" name="do" value="set" class="spe-save">' . esc_html__( 'Apply to selected', 'songpress' ) . '</button>';
		if ( Songpress_Caps::can( 'delete' ) ) {
			$o .= ' <label class="songpress-bulkshowdel"><input type="checkbox" class="songpress-bulkdelon"> ' . esc_html__( 'Show delete', 'songpress' ) . '</label>';
			$o .= ' <button type="submit" name="do" value="delete" hidden class="spe-btn danger songpress-bulkdel" data-confirm="' . esc_attr__( 'Delete the selected works for good? An original work takes its versions with it. This cannot be undone.', 'songpress' ) . '">' . esc_html__( 'Delete selected', 'songpress' ) . '</button>';
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
		return '<nav class="songpress-tabs songpress-tabs-' . $pos . '" aria-label="SongPress"><a class="' . ( 'catalog' === $on ? 'on' : '' ) . '" href="' . esc_url( get_permalink( $c ) ) . '">' . esc_html__( 'Works', 'songpress' ) . '</a><a class="' . ( 'profile' === $on ? 'on' : '' ) . '" href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html__( 'Profile', 'songpress' ) . '</a></nav>';
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
		return '<div class="songpress sp-app">' . ( wp_is_mobile() ? self::userbar( 'catalog', $id ) : self::tabs( 'catalog', 'top' ) ) . self::song_body( $s, $uid, $edit . '<a class="songpress-back" href="' . esc_url( remove_query_arg( 'sp_song' ) ) . '">&lsaquo; ' . esc_html__( 'Works', 'songpress' ) . '</a>' ) . ( wp_is_mobile() ? '' : self::tabs( 'catalog' ) ) . '</div>';
	}

	/** The song itself: cover, audio, title, writers, chart. Shared by the logged-in view and the public share page. */
	private static function song_body( $s, $uid, $top = '' ) {
		$id = (int) $s->id;
		list( $kind, $text ) = Songpress_Songs::best_chart( $s );
		$labels = array( 'chordpro' => 'ChordPro', 'chords_lyrics' => __( 'Chords and lyrics', 'songpress' ), 'lyrics' => __( 'Lyrics', 'songpress' ), 'none' => '' );
		// Header: cover art on the left; beside it the title, the artist and the authors as one row of icons. The player runs the full width underneath.
		$is_root = 0 === (int) $s->version_no;
		$name    = $is_root ? $s->title : $s->release_title; // only an original work shows the work name; a version shows its release title
		$out  = '<article class="songpress-song">' . $top . '<header class="songpress-top">' . self::art( $s, 'songpress-cover' ) . '<div class="songpress-topl">';
		$out .= '<p class="songpress-sub"><span class="songpress-serial">' . esc_html( $s->serial ) . '</span> ' . esc_html( Songpress_Songs::statuses()[ $s->status ] ?? $s->status ) . '</p>';
		if ( '' !== trim( (string) $name ) ) {
			$out .= '<h2>' . esc_html( $name ) . '</h2>';
		}
		if ( '' !== trim( (string) $s->artist ) ) {
			$out .= '<p class="songpress-band">' . Songpress_Profiles::chip( $s->artist, 28, 'band' ) . esc_html( $s->artist ) . '</p>'; // the performing act, never matched against people
		}
		$seen  = array();
		$icons = '';
		foreach ( Songpress_Songs::credits( $id ) as $c ) {
			$k = Songpress_Profiles::key( $c->name );
			if ( 'writer' !== $c->role || '' === $k || isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = 1;
			$icons     .= '<span class="songpress-icon" title="' . esc_attr( $c->name ) . '">' . Songpress_Profiles::chip( $c->name, 32, 'person' ) . '</span>';
		}
		if ( '' !== $icons ) {
			$out .= '<p class="songpress-authors" aria-label="' . esc_attr__( 'Authors', 'songpress' ) . '">' . $icons . '</p>';
		}
		$out  .= '</div>';
		$audio = Songpress_Media::audio_url( $s );
		if ( '' !== $audio ) {
			$out .= '<audio class="songpress-audio" controls preload="none" src="' . esc_url( $audio ) . '"></audio>';
		}
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
				$pan .= '<div class="songpress-panel" data-lv="' . esc_attr( $lk ) . '"' . ( $on ? '' : ' hidden' ) . '>' . ( 'chordpro' === $lk ? wp_kses_post( Songpress_ChordPro::render( $lv[ $lk ][0] ) ) : '<pre class="songpress-chart">' . esc_html( $lv[ $lk ][0] ) . '</pre>' ) . '</div>';
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
		$out  = '<div class="songpress songpress-profile sp-app">' . ( wp_is_mobile() ? self::userbar( 'profile' ) : self::tabs( 'profile', 'top' ) ) . '<div class="songpress-profile-head"><div class="songpress-avatar" aria-hidden="true">' . ( '' !== $face ? '<img src="' . esc_url( $face ) . '" alt="">' : esc_html( $ini ) ) . '</div><div><h2>' . esc_html( $u->display_name ) . '</h2>';
		$out .= '<p class="songpress-sub">' . esc_html( sprintf( /* translators: %d: number of songs */ _n( 'Credited on %d song', 'Credited on %d songs', $res['total'], 'songpress' ), $res['total'] ) ) . '</p></div></div>';
		$out .= '<ul class="songpress-stats"><li>' . esc_html__( 'Works', 'songpress' ) . '<strong>' . (int) $res['total'] . '</strong></li><li>' . esc_html__( 'Released', 'songpress' ) . '<strong>' . (int) $rel . '</strong></li></ul>';
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
			$out .= '<h3>' . esc_html__( 'Songs', 'songpress' ) . '</h3><ul class="songpress-mysongs">';
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
				$out  .= '<li><a href="' . esc_url( add_query_arg( 'sp_song', $id, $base ) ) . '"><span class="songpress-mytitle">' . esc_html( $title ) . '</span><span class="songpress-myrole">' . esc_html( implode( ' · ', $r ) ) . '</span></a><span class="songpress-mydk">' . ( $amt ? esc_html( Songpress_Money::money( $amt ) ) : '&ndash;' ) . '</span></li>';
			}
			$out .= '</ul><p class="songpress-note">' . esc_html__( 'Amounts are what you have earned from each song.', 'songpress' ) . '</p>';
		}
		$out .= wp_kses_post( songpress_public_donate_link() ) . ( wp_is_mobile() ? '' : self::tabs( 'profile' ) ) . '</div>';
		return $out;
	}
}
