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
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets() {
		wp_register_style( 'songpress', SONGPRESS_URL . 'assets/songpress.css', array(), SONGPRESS_VERSION );
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
		$res    = Songpress_Songs::query( array( 'search' => $q, 'status' => $status, 'per_page' => 40, 'user_id' => Songpress_Caps::can( 'view_all' ) ? 0 : $uid ) );
		$out    = '<div class="songpress sp-app">' . self::tabs( 'catalog', 'top' ) . self::head( __( 'Songs', 'songpress' ) );
		$out   .= '<form method="get" class="songpress-search"><input type="search" name="sp_q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Search title or serial', 'songpress' ) . '" aria-label="' . esc_attr__( 'Search songs', 'songpress' ) . '">' . ( '' !== $status ? '<input type="hidden" name="sp_status" value="' . esc_attr( $status ) . '">' : '' ) . '</form>';
		$out   .= '<div class="songpress-chips"><a class="' . ( '' === $status ? 'on' : '' ) . '" href="' . esc_url( remove_query_arg( 'sp_status' ) ) . '">' . esc_html__( 'All', 'songpress' ) . '</a>';
		foreach ( Songpress_Songs::STATUSES as $k => $label ) {
			$out .= '<a class="' . ( $k === $status ? 'on' : '' ) . '" href="' . esc_url( add_query_arg( 'sp_status', $k ) ) . '">' . esc_html( $label ) . '</a>';
		}
		$out .= '</div><ul class="songpress-list">';
		foreach ( $res['rows'] as $s ) {
			$by   = Songpress_Songs::authors_line( (int) $s->id, $uid );
			$link = esc_url( add_query_arg( 'sp_song', (int) $s->id ) );
			$out .= '<li><a class="songpress-row" href="' . $link . '">' . self::art( $s, 'songpress-thumb' ) . '<span class="songpress-meta"><span class="songpress-serial">' . esc_html( $s->serial ) . '</span><span class="songpress-title">' . esc_html( $s->title ) . '</span>'
				. ( '' !== $by ? '<span class="songpress-by">' . esc_html( $by ) . '</span>' : '' )
				. '<span class="songpress-sub">' . esc_html( Songpress_Songs::STATUSES[ $s->status ] ?? $s->status ) . '</span></span><span class="songpress-chev" aria-hidden="true">&rsaquo;</span></a></li>';
		}
		if ( ! $res['rows'] ) {
			$out .= '<li class="songpress-empty">' . esc_html__( 'No songs found.', 'songpress' ) . '</li>';
		}
		return $out . '</ul>' . wp_kses_post( songpress_public_donate_link() ) . self::tabs( 'catalog' ) . '</div>';
	}

	/** The logo and page title at the top of every phone screen. */
	private static function head( $title ) {
		return '<div class="songpress-head"><img src="' . esc_url( SONGPRESS_URL . 'assets/icon.svg' ) . '" alt="SongPress"><h2>' . esc_html( $title ) . '</h2></div>';
	}

	/** Songs / Profile tabs along the bottom, linking to the two pages SongPress made. */
	private static function tabs( $on, $pos = 'bottom' ) {
		$c = (int) get_option( 'songpress_page_catalog' );
		$p = (int) get_option( 'songpress_page_profile' );
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
		$out .= '<h2>' . esc_html( $s->title ) . '</h2><p class="songpress-by">' . esc_html( Songpress_Songs::authors_line( $id, $uid ) ) . '</p>';
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
