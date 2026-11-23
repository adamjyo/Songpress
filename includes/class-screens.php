<?php
defined( 'ABSPATH' ) || exit;

/**
 * The management screens (People, Artists, Import, Link writers, Settings) shown inside the SongPress app.
 * They reuse the existing screen code and are re-dressed here: forms come back to the app, and links
 * that pointed at WordPress admin point at the app instead.
 */
class Songpress_Screens {

	const MAP = array(
		'people'   => array( 'page_people', 'members' ),
		'artists'  => array( 'page_artists', 'artists' ),
		'import'   => array( 'page_import', 'import' ),
		'link'     => array( 'page_link_writers', 'link' ),
		'settings' => array( 'page_settings', 'settings' ),
	);

	public static function url( $slug, $args = array() ) {
		return add_query_arg( array_merge( array( 'sp_page' => $slug ), $args ), Songpress_Editor::list_url() );
	}

	/** Where a form or link that used to go to the admin should go in the app. */
	public static function app_url( $page, $args ) {
		if ( 'songpress' === $page ) {
			return Songpress_Editor::list_url();
		}
		if ( 'songpress-song' === $page ) {
			return Songpress_Editor::edit_url( isset( $args['song'] ) ? (int) $args['song'] : 0 );
		}
		if ( 'songpress-members' === $page ) {
			return Songpress_Persons::url();
		}
		$slug = preg_replace( '/^songpress-/', '', $page );
		unset( $args['page'] );
		return self::url( $slug, $args );
	}

	const SETTINGS_TABS = array( 'settings', 'backups', 'health', 'earnings', 'activity', 'uploads', 'import' );

	/** The tab strip shared by Settings, Backups, Health, Activity log and Upload / Download (plus the CSV export). */
	public static function settings_tabs( $active ) {
		$active = 'import' === $active ? 'uploads' : $active;
		$items  = array(
			'settings' => array( __( 'Settings', 'songpress' ), 'settings' ),
			'backups'  => array( __( 'Backups', 'songpress' ), 'settings' ),
			'health'   => array( __( 'Health', 'songpress' ), 'settings' ),
			'earnings' => array( __( 'Earnings', 'songpress' ), 'settings' ),
			'activity' => array( __( 'Activity log', 'songpress' ), 'activity' ),
			'uploads'  => array( __( 'Upload / Download', 'songpress' ), 'import' ),
		);
		$o = '<nav class="sp-tabs" aria-label="' . esc_attr__( 'Settings sections', 'songpress' ) . '">';
		foreach ( $items as $k => $v ) {
			if ( Songpress_Caps::can( $v[1] ) ) {
				$o .= '<a href="' . esc_url( self::url( $k ) ) . '"' . ( $k === $active ? ' class="on" aria-current="page"' : '' ) . '>' . esc_html( $v[0] ) . '</a>';
			}
		}
		if ( Songpress_Caps::can( 'export' ) ) {
			$o .= '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=songpress_export' ), 'songpress_export' ) ) . '">' . esc_html__( 'Export CSV', 'songpress' ) . '</a>';
		}
		return $o . '</nav>';
	}

	/** Registrations and Agreements share one rail entry, "Rights". */
	public static function rights_tabs( $active ) {
		$o = '<nav class="sp-tabs" aria-label="' . esc_attr__( 'Rights', 'songpress' ) . '">';
		foreach ( array( 'registrations' => array( __( 'Registrations', 'songpress' ), 'registrations' ), 'agreements' => array( __( 'Agreements', 'songpress' ), 'agreements' ) ) as $k => $v ) {
			if ( Songpress_Caps::can( $v[1] ) ) {
				$o .= '<a href="' . esc_url( self::url( $k ) ) . '"' . ( $k === $active ? ' class="on" aria-current="page"' : '' ) . '>' . esc_html( $v[0] ) . '</a>';
			}
		}
		return $o . '</nav>';
	}

	/** People, Bands, Labels, Publishers, Artists and Link writers share one rail entry, as sections. */
	public static function people_tabs( $active ) {
		$items = array(
			'person'    => array( __( 'People', 'songpress' ), self::url( 'people', array( 'kind' => 'person' ) ), 'members' ),
			'band'      => array( __( 'Bands', 'songpress' ), self::url( 'people', array( 'kind' => 'band' ) ), 'members' ),
			'label'     => array( __( 'Labels', 'songpress' ), self::url( 'people', array( 'kind' => 'label' ) ), 'members' ),
			'publisher' => array( __( 'Publishers', 'songpress' ), self::url( 'people', array( 'kind' => 'publisher' ) ), 'members' ),
			'artists'   => array( __( 'Artists', 'songpress' ), self::url( 'artists' ), 'artists' ),
			'link'      => array( __( 'Link writers', 'songpress' ), self::url( 'link' ), 'link' ),
		);
		$o = '<nav class="sp-tabs" aria-label="' . esc_attr__( 'People', 'songpress' ) . '">';
		foreach ( $items as $k => $v ) {
			if ( Songpress_Caps::can( $v[2] ) ) {
				$o .= '<a href="' . esc_url( $v[1] ) . '"' . ( $k === $active ? ' class="on" aria-current="page"' : '' ) . '>' . esc_html( $v[0] ) . '</a>';
			}
		}
		return $o . '</nav>';
	}

	public static function render( $slug ) {
		if ( in_array( $slug, array( 'link', 'artists', 'people' ), true ) ) {
			$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : 'person'; // phpcs:ignore WordPress.Security.NonceVerification
			$act  = 'link' === $slug ? 'link' : ( 'artists' === $slug ? 'artists' : ( in_array( $kind, array( 'band', 'label', 'publisher' ), true ) ? $kind : 'person' ) );
			return self::people_tabs( $act ) . self::render_one( $slug );
		}
		if ( in_array( $slug, array( 'registrations', 'agreements' ), true ) && Songpress_Caps::can( $slug ) ) {
			return self::rights_tabs( $slug ) . self::render_one( $slug );
		}
		if ( in_array( $slug, self::SETTINGS_TABS, true ) ) {
			return self::settings_tabs( $slug ) . self::render_one( $slug ) . ( 'settings' === $slug && Songpress_Caps::can( 'settings' ) ? Songpress_Push::test_box() : '' );
		}
		return self::render_one( $slug );
	}

	private static function render_one( $slug ) {
		if ( 'people' === $slug && ( empty( $_GET['kind'] ) || 'person' === $_GET['kind'] ) && empty( $_GET['profile'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return Songpress_Persons::render();
		}
		if ( 'uploads' === $slug ) {
			return Songpress_Uploads::render();
		}
		if ( 'import' === $slug ) {
			return Songpress_Uploads::render( 'works' );
		}
		if ( 'registrations' === $slug ) {
			return Songpress_Registrations::render();
		}
		if ( 'agreements' === $slug ) {
			return Songpress_Agreements::render();
		}
		if ( 'sessions' === $slug ) {
			return Songpress_Sessions::render();
		}
		if ( 'art' === $slug ) {
			return Songpress_Art::render();
		}
		if ( 'backups' === $slug ) {
			return Songpress_Backup::render();
		}
		if ( 'health' === $slug ) {
			return Songpress_Health::render();
		}
		if ( 'earnings' === $slug ) {
			return Songpress_Earnings::render();
		}
		if ( 'activity' === $slug ) {
			return Songpress_Audit::render();
		}
		if ( 'journal' === $slug || 'notes' === $slug ) {
			return Songpress_Journal::render();
		}
		if ( 'pitch' === $slug ) {
			return Songpress_Pitch::render();
		}
		if ( in_array( $slug, array( 'money', 'stats' ), true ) ) {
			return Songpress_Money::render( $slug );
		}
		if ( ! isset( self::MAP[ $slug ] ) ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'That screen does not exist.', 'songpress' ) . '</p></div>';
		}
		return self::legacy( $slug );
	}

	/** An old admin screen, re-dressed for the app. */
	public static function legacy( $slug ) {
		list( $fn, $cap ) = self::MAP[ $slug ];
		if ( ! Songpress_Caps::can( $cap ) ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'Only Managers can open this screen.', 'songpress' ) . '</p></div>';
		}
		foreach ( array( 'template.php', 'plugin.php', 'misc.php', 'file.php' ) as $f ) {
			if ( file_exists( ABSPATH . 'wp-admin/includes/' . $f ) ) {
				require_once ABSPATH . 'wp-admin/includes/' . $f;
			}
		}
		Songpress_Media::$front = true;
		$level = ob_get_level();
		ob_start();
		try {
			Songpress_Admin::$fn();
			$h = ob_get_clean();
		} catch ( Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			return '<div class="sp-app"><p class="spe-note bad">' . esc_html( sprintf( /* translators: %s: error message */ __( 'This screen hit a problem: %s', 'songpress' ), $e->getMessage() ) ) . '</p></div>';
		}
		$base = esc_attr( Songpress_Editor::list_url() );
		$h    = preg_replace( '~(<form\b[^>]*admin-post\.php[^>]*>)~i', '$1<input type="hidden" name="sp_return" value="' . $base . '">', $h );
		$h    = preg_replace_callback( '~href="([^"]*admin\.php\?page=(songpress[a-z-]*)[^"]*)"~i', function ( $m ) {
			$url = html_entity_decode( $m[1] );
			$q   = array();
			wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
			return 'href="' . esc_url( self::app_url( $m[2], $q ) ) . '"';
		}, $h );
		return '<div class="sp-app sp-wp songpress-wrap">' . $h . '</div>';
	}
}
