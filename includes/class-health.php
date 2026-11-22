<?php
defined( 'ABSPATH' ) || exit;

/**
 * A health page for Managers: is the plugin installed properly, do the tables exist, can the server do what SongPress
 * needs, and what went wrong lately. Plugin errors are noted as they happen (the last ten are kept).
 */
class Songpress_Health {

	const MAX_ERRORS = 10;

	public static function init() {
		add_action( 'admin_post_songpress_health_clear', array( __CLASS__, 'handle_clear' ) );
		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
	}

	/** Pure: was this PHP error one of ours and bad enough to keep? */
	public static function is_ours( $err, $dir ) {
		return is_array( $err ) && in_array( (int) ( $err['type'] ?? 0 ), array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true )
			&& isset( $err['file'] ) && 0 === strpos( wp_normalize_path( (string) $err['file'] ), wp_normalize_path( (string) $dir ) );
	}

	public static function on_shutdown() {
		$e = error_get_last();
		if ( ! self::is_ours( $e, SONGPRESS_DIR ) ) {
			return;
		}
		self::note( (string) $e['message'], str_replace( wp_normalize_path( SONGPRESS_DIR ), '', wp_normalize_path( (string) $e['file'] ) ) . ':' . (int) ( $e['line'] ?? 0 ) );
	}

	public static function note( $message, $where = '' ) {
		$log = get_option( 'songpress_errors', array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift( $log, array( 'at' => gmdate( 'Y-m-d H:i:s' ), 'message' => mb_substr( wp_strip_all_tags( (string) $message ), 0, 240 ), 'where' => (string) $where, 'version' => SONGPRESS_VERSION ) );
		update_option( 'songpress_errors', array_slice( $log, 0, self::MAX_ERRORS ), false );
	}

	public static function handle_clear() {
		check_admin_referer( 'songpress_health_clear' );
		if ( ! Songpress_Caps::can( 'settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		delete_option( 'songpress_errors' );
		wp_safe_redirect( add_query_arg( 'sp_page', 'health', Songpress_Editor::list_url() ) );
		exit;
	}

	/** Pure: turn a PHP size like 64M into bytes. */
	public static function bytes( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return 0;
		}
		$n = (float) $v;
		switch ( strtolower( substr( $v, -1 ) ) ) {
			case 'g':
				$n *= 1024;
				// no break
			case 'm':
				$n *= 1024;
				// no break
			case 'k':
				$n *= 1024;
		}
		return (int) $n;
	}

	/** The checks: list of array( group, name, state ok|warn|bad, detail ). */
	public static function checks() {
		global $wpdb;
		$c   = array();
		$add = function ( $g, $n, $s, $d = '' ) use ( &$c ) {
			$c[] = array( $g, $n, $s, $d );
		};
		$dbv = (string) get_option( 'songpress_db_version', '' );
		$add( 'Plugin', 'Version', 'ok', SONGPRESS_VERSION );
		$add( 'Plugin', 'Database version', $dbv === (string) SONGPRESS_DB_VERSION ? 'ok' : 'bad', $dbv . ( $dbv === (string) SONGPRESS_DB_VERSION ? '' : ' (expected ' . SONGPRESS_DB_VERSION . '; deactivate and reactivate the plugin)' ) );
		$missing = array();
		$rows    = 0;
		foreach ( Songpress_Install::table_names() as $t ) {
			$tn = Songpress_Install::table( $t );
			if ( $tn !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tn ) ) ) {
				$missing[] = $t;
			} else {
				$rows += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tn}" ); // phpcs:ignore WordPress.DB
			}
		}
		$add( 'Database', 'Tables', $missing ? 'bad' : 'ok', $missing ? 'Missing: ' . implode( ', ', $missing ) : count( Songpress_Install::table_names() ) . ' tables, ' . number_format_i18n( $rows ) . ' rows' );
		$add( 'Database', 'Songs', 'ok', number_format_i18n( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Songpress_Install::table( 'songs' ) ) ) ); // phpcs:ignore WordPress.DB
		$php = PHP_VERSION;
		$add( 'Server', 'PHP', version_compare( $php, '7.4', '>=' ) ? 'ok' : 'bad', $php );
		foreach ( array( 'ZipArchive' => 'Notes zips and the catalog zip export', 'PDO' => 'Reading the old catalog file' ) as $cls => $why ) {
			$add( 'Server', $cls, class_exists( $cls ) ? 'ok' : 'warn', class_exists( $cls ) ? 'available' : 'missing: ' . $why );
		}
		$add( 'Server', 'SQLite driver (old catalog file)', ( class_exists( 'PDO' ) && in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) ? 'ok' : 'warn', ( class_exists( 'PDO' ) && in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) ? 'available' : 'pdo_sqlite is off, so the old catalog file cannot be loaded here' );
		$add( 'Server', 'Compression (backups)', function_exists( 'gzencode' ) ? 'ok' : 'bad', function_exists( 'gzencode' ) ? 'available' : 'zlib is missing, so backups cannot be saved' );
		$mem = self::bytes( ini_get( 'memory_limit' ) );
		$add( 'Server', 'Memory limit', ( -1 === (int) ini_get( 'memory_limit' ) || $mem >= 128 * 1048576 ) ? 'ok' : 'warn', (string) ini_get( 'memory_limit' ) );
		$up = min( self::bytes( ini_get( 'upload_max_filesize' ) ), self::bytes( ini_get( 'post_max_size' ) ) );
		$add( 'Server', 'Biggest upload', $up >= 32 * 1048576 ? 'ok' : 'warn', size_format( $up ) . ( $up >= 32 * 1048576 ? '' : ' (the old catalog file can be larger than this)' ) );
		$dir = Songpress_Backup::dir();
		$add( 'Storage', 'Private folder (backups, temporary files)', ( '' !== $dir && is_writable( $dir ) ) ? 'ok' : 'bad', '' !== $dir ? 'writable' : 'cannot be created in the uploads folder' );
		$bk = Songpress_Backup::all();
		if ( ! $bk ) {
			$add( 'Storage', 'Latest backup', 'warn', 'none yet' );
		} else {
			$age = time() - strtotime( $bk[0]['created'] . ' UTC' );
			$add( 'Storage', 'Latest backup', $age < 14 * DAY_IN_SECONDS ? 'ok' : 'warn', human_time_diff( time() - $age ) . ' ago' );
		}
		foreach ( array( 'catalog' => 'Songs page', 'profile' => 'Profile page' ) as $k => $label ) {
			$id = Songpress_Frontend::main_id( $k );
			$add( 'Pages', $label, ( $id && Songpress_Install::page_ok( $id ) ) ? 'ok' : 'warn', ( $id && Songpress_Install::page_ok( $id ) ) ? 'found' : 'missing or changed' );
		}
		$up = Songpress_Updater::enabled() ? Songpress_Updater::latest() : null;
		$add( 'Updates', 'GitHub updates', Songpress_Updater::enabled() ? ( $up ? 'ok' : 'warn' ) : 'warn', Songpress_Updater::enabled() ? ( $up ? 'newest on GitHub: ' . $up['version'] : 'could not reach GitHub just now' ) : 'switched off' );
		$errs = get_option( 'songpress_errors', array() );
		$add( 'Errors', 'Recent plugin errors', empty( $errs ) ? 'ok' : 'bad', empty( $errs ) ? 'none' : count( $errs ) . ' (listed below)' );
		return $c;
	}

	public static function render() {
		if ( ! Songpress_Caps::can( 'settings' ) ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'Only Managers can open this screen.', 'songpress' ) . '</p></div>';
		}
		$o     = '<div class="songpress sp-app sp-editor sp-health"><div class="spe-top"><h2>' . esc_html__( 'Health', 'songpress' ) . '</h2></div>';
		$group = '';
		foreach ( self::checks() as $c ) {
			if ( $c[0] !== $group ) {
				$o    .= ( '' !== $group ? '</ul>' : '' ) . '<h3>' . esc_html( $c[0] ) . '</h3><ul class="sph-list">';
				$group = $c[0];
			}
			$o .= '<li class="sph-' . esc_attr( $c[2] ) . '"><span class="sph-dot" aria-hidden="true"></span><b>' . esc_html( $c[1] ) . '</b> <span class="sph-d">' . esc_html( $c[3] ) . '</span></li>';
		}
		$o   .= '</ul>';
		$errs = get_option( 'songpress_errors', array() );
		if ( ! empty( $errs ) ) {
			$o .= '<h3>' . esc_html__( 'Recent plugin errors', 'songpress' ) . '</h3><ul class="spa-list">';
			foreach ( $errs as $e ) {
				$o .= '<li><time>' . esc_html( $e['at'] ) . ' UTC</time> ' . esc_html( $e['message'] ) . '<small>' . esc_html( $e['where'] . ' · v' . $e['version'] ) . '</small></li>';
			}
			$o .= '</ul><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_health_clear', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_health_clear"><button class="spe-btn" type="submit">' . esc_html__( 'Clear the list', 'songpress' ) . '</button></form>';
		}
		return $o . '</div>';
	}
}
