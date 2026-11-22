<?php
defined( 'ABSPATH' ) || exit;

/**
 * Updates SongPress from GitHub releases, so it appears under Dashboard > Updates like any other plugin.
 * It asks GitHub for the latest release of the repository chosen in SongPress > Settings, at most twice a day.
 * Only the request itself goes out (no site data is sent). Turn it off in Settings. Copies of SongPress that come
 * from WordPress.org must not use this: define SONGPRESS_NO_GITHUB_UPDATES as true in that build.
 */
class Songpress_Updater {
	const DEFAULT_REPO = 'adamjyo/Songpress';
	const CACHE        = 'songpress_github_release';
	const BRANCH       = 'main';

	public static function init() {
		if ( defined( 'SONGPRESS_NO_GITHUB_UPDATES' ) && SONGPRESS_NO_GITHUB_UPDATES ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget' ) );
	}

	public static function enabled() {
		return '0' !== get_option( 'songpress_github_updates', '1' );
	}

	public static function repo() {
		$r = (string) get_option( 'songpress_github_repo', self::DEFAULT_REPO );
		return self::valid_repo( $r ) ? $r : self::DEFAULT_REPO;
	}

	public static function valid_repo( $r ) {
		return (bool) preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', (string) $r );
	}

	/** Is the release tag (like v0.2.0) newer than the installed version? Pure. */
	public static function is_newer( $tag, $current ) {
		return version_compare( ltrim( (string) $tag, "vV" ), ltrim( (string) $current, "vV" ), '>' );
	}

	/** The zip to install: an attached songpress-*.zip asset if the release has one, else the repository snapshot. Pure. */
	public static function package_url( array $release ) {
		foreach ( (array) ( $release['assets'] ?? array() ) as $a ) {
			if ( isset( $a['name'], $a['browser_download_url'] ) && preg_match( '/^songpress.*\.zip$/i', $a['name'] ) ) {
				return (string) $a['browser_download_url'];
			}
		}
		return (string) ( $release['zipball_url'] ?? '' );
	}

	/** The Version line from a plugin file's header. Pure. */
	public static function parse_version( $php ) {
		return preg_match( '/^[ \t\/*#@]*Version:\s*([0-9][0-9A-Za-z.\-+]*)/mi', (string) $php, $m ) ? $m[1] : '';
	}

	/** The newer of the latest release and the version written on the main branch. Pure. */
	public static function pick( $release, $branch ) {
		if ( $release && $branch ) {
			return version_compare( $branch['version'], $release['version'], '>' ) ? $branch : $release;
		}
		return $release ? $release : $branch;
	}

	/** The version in songpress.php on the main branch, so a push with a higher version number is an update without anyone publishing a release. */
	private static function from_branch( $headers ) {
		$repo = self::repo();
		$res  = wp_remote_get( 'https://raw.githubusercontent.com/' . $repo . '/' . self::BRANCH . '/songpress.php?nocache=' . time(), array( 'timeout' => 8, 'headers' => $headers ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$v = self::parse_version( wp_remote_retrieve_body( $res ) );
		if ( '' === $v ) {
			return null;
		}
		return array(
			'version' => $v,
			'package' => 'https://github.com/' . $repo . '/archive/refs/heads/' . self::BRANCH . '.zip',
			'url'     => 'https://github.com/' . $repo . '/commits/' . self::BRANCH,
			'notes'   => '',
			'date'    => '',
		);
	}

	/** The latest published release, cached for 12 hours. Drafts and pre-releases are ignored. */
	public static function latest( $force = false ) {
		$cached = get_transient( self::CACHE );
		if ( ! $force && is_array( $cached ) ) {
			return $cached ?: null;
		}
		$headers = array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'SongPress/' . SONGPRESS_VERSION );
		if ( defined( 'SONGPRESS_GITHUB_TOKEN' ) && SONGPRESS_GITHUB_TOKEN ) { // for a private repository
			$headers['Authorization'] = 'Bearer ' . SONGPRESS_GITHUB_TOKEN;
		}
		$res  = wp_remote_get( 'https://api.github.com/repos/' . self::repo() . '/releases/latest', array( 'timeout' => 8, 'headers' => $headers ) );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$body = 200 === $code ? json_decode( wp_remote_retrieve_body( $res ), true ) : null;
		$release = null;
		if ( is_array( $body ) && ! empty( $body['tag_name'] ) && empty( $body['draft'] ) && empty( $body['prerelease'] ) ) {
			$release = array(
				'version' => ltrim( $body['tag_name'], 'vV' ),
				'package' => self::package_url( $body ),
				'url'     => (string) ( $body['html_url'] ?? '' ),
				'notes'   => (string) ( $body['body'] ?? '' ),
				'date'    => (string) ( $body['published_at'] ?? '' ),
			);
		}
		$best = self::pick( $release, self::from_branch( $headers ) );
		if ( ! $best ) {
			set_transient( self::CACHE, array(), HOUR_IN_SECONDS ); // nothing usable: try again in an hour
			return null;
		}
		set_transient( self::CACHE, $best, 12 * HOUR_IN_SECONDS );
		return $best;
	}

	public static function inject( $transient ) {
		if ( ! is_object( $transient ) || ! self::enabled() ) {
			return $transient;
		}
		$rel = self::latest();
		if ( $rel && '' !== $rel['package'] && self::is_newer( $rel['version'], SONGPRESS_VERSION ) ) {
			$transient->response[ plugin_basename( SONGPRESS_FILE ) ] = (object) array(
				'slug'        => 'songpress',
				'plugin'      => plugin_basename( SONGPRESS_FILE ),
				'new_version' => $rel['version'],
				'url'         => $rel['url'],
				'package'     => $rel['package'],
			);
		}
		return $transient;
	}

	/** The "View details" popup on the Plugins screen. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'songpress' !== $args->slug || ! self::enabled() ) {
			return $result;
		}
		$rel = self::latest();
		if ( ! $rel ) {
			return $result;
		}
		return (object) array(
			'name'          => 'SongPress',
			'slug'          => 'songpress',
			'version'       => $rel['version'],
			'author'        => 'Adam Yoo',
			'homepage'      => $rel['url'],
			'download_link' => $rel['package'],
			'last_updated'  => $rel['date'],
			'sections'      => array( 'changelog' => wp_kses_post( wpautop( esc_html( $rel['notes'] ) ) ) ),
		);
	}

	/** GitHub snapshot zips unpack into a folder like adamjyo-Songpress-1a2b3c. Rename it so WordPress replaces the right plugin. */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( empty( $hook_extra['plugin'] ) || plugin_basename( SONGPRESS_FILE ) !== $hook_extra['plugin'] ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . 'songpress/';
		if ( trailingslashit( $source ) === $want ) {
			return $source;
		}
		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $want ), true ) ) {
			return $want;
		}
		return $source;
	}

	public static function forget() {
		delete_transient( self::CACHE );
	}
}
