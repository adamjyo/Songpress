<?php
defined( 'ABSPATH' ) || exit;

/**
 * Backups and rollback. A snapshot is every SongPress table saved to one compressed file in a folder the web server will not
 * serve. One is taken automatically before the risky things (loading the old catalog, statements, tidying logins, deleting),
 * at most every ten minutes, and you can take one by hand. Restoring one first takes a snapshot of how things are now.
 * The activity log and song history are never rewound: they are the record of what happened, including the restore.
 */
class Songpress_Backup {

	const KEEP     = 15;
	const SKIP     = array( 'audit', 'history' );
	const RISKY    = array( 'songpress_statement', 'songpress_notes_import', 'songpress_person_tidy', 'songpress_person_rename', 'songpress_money_pay_csv', 'songpress_session_delete', 'songpress_agreement_delete', 'songpress_art_delete', 'songpress_import_run', 'songpress_profiles_load', 'songpress_make_accounts', 'songpress_link_writers', 'songpress_start_fresh' );
	const THROTTLE = 600;

	public static function init() {
		add_action( 'check_admin_referer', array( __CLASS__, 'before_risky' ), 5, 2 );
		add_action( 'admin_post_songpress_backup_make', array( __CLASS__, 'handle_make' ) );
		add_action( 'admin_post_songpress_backup_restore', array( __CLASS__, 'handle_restore' ) );
		add_action( 'admin_post_songpress_backup_download', array( __CLASS__, 'handle_download' ) );
	}

	/** Pure: does this admin-post action change a lot at once? */
	public static function is_risky( $action ) {
		$action = (string) $action;
		return in_array( $action, self::RISKY, true ) || 0 === strpos( $action, 'songpress_money_' );
	}

	public static function before_risky( $action, $result ) {
		$act = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- only decides whether to take a backup.
		if ( ! $result || ! self::is_risky( $act ) || ! is_user_logged_in() ) {
			return;
		}
		$last = (int) get_option( 'songpress_last_auto_backup', 0 );
		if ( time() - $last < self::THROTTLE ) {
			return;
		}
		update_option( 'songpress_last_auto_backup', time() );
		self::snapshot( 'before ' . str_replace( 'songpress_', '', $act ) );
	}

	public static function dir() {
		$base = Songpress_Import::private_dir();
		if ( '' === $base ) {
			return '';
		}
		$d = $base . '/backups';
		return ( is_dir( $d ) || wp_mkdir_p( $d ) ) ? $d : '';
	}

	public static function tables() {
		return array_values( array_diff( Songpress_Install::table_names(), self::SKIP ) );
	}

	/** Take a snapshot. Returns the file name, or '' if it could not be written. */
	public static function snapshot( $reason ) {
		global $wpdb;
		$dir = self::dir();
		if ( '' === $dir || ! function_exists( 'gzencode' ) ) {
			return '';
		}
		$data   = array( 'v' => 1, 'plugin' => SONGPRESS_VERSION, 'db' => SONGPRESS_DB_VERSION, 'created' => gmdate( 'Y-m-d H:i:s' ), 'reason' => (string) $reason, 'tables' => array() );
		$counts = array();
		foreach ( self::tables() as $t ) {
			$rows = $wpdb->get_results( 'SELECT * FROM ' . Songpress_Install::table( $t ) ); // phpcs:ignore WordPress.DB
			$cols = $rows ? array_keys( (array) $rows[0] ) : array();
			$vals = array();
			foreach ( $rows as $r ) {
				$vals[] = array_values( (array) $r );
			}
			$data['tables'][ $t ] = array( 'cols' => $cols, 'rows' => $vals );
			$counts[ $t ]         = count( $vals );
		}
		$slug = substr( trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $reason ) ), '-' ), 0, 40 );
		$name = 'backup-' . gmdate( 'Ymd-His' ) . ( '' !== $slug ? '-' . $slug : '' ) . '.json.gz';
		$n    = 2;
		$base = $name;
		while ( file_exists( $dir . '/' . $name ) ) {
			$name = str_replace( '.json.gz', '-' . $n++ . '.json.gz', $base );
		}
		if ( false === file_put_contents( $dir . '/' . $name, gzencode( wp_json_encode( $data ), 6 ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return '';
		}
		file_put_contents( $dir . '/' . $name . '.meta', wp_json_encode( array( 'created' => $data['created'], 'reason' => (string) $reason, 'plugin' => SONGPRESS_VERSION, 'db' => SONGPRESS_DB_VERSION, 'counts' => $counts ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		self::prune();
		return $name;
	}

	/** Pure: file names, newest first, that fall outside the newest $keep. */
	public static function to_prune( array $names, $keep ) {
		rsort( $names );
		return array_slice( $names, (int) $keep );
	}

	private static function prune() {
		$dir = self::dir();
		foreach ( self::to_prune( array_map( function ( $b ) { return $b['file']; }, self::all() ), self::KEEP ) as $f ) {
			@unlink( $dir . '/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			@unlink( $dir . '/' . $f . '.meta' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
	}

	public static function valid_name( $n ) {
		return (bool) preg_match( '/^backup-\d{8}-\d{6}[a-z0-9-]*\.json\.gz$/', (string) $n );
	}

	/** Every backup, newest first: array( file, created, reason, plugin, db, counts, size ). */
	public static function all() {
		$dir = self::dir();
		$out = array();
		if ( '' === $dir ) {
			return $out;
		}
		foreach ( (array) glob( $dir . '/backup-*.json.gz' ) as $p ) {
			$f = basename( $p );
			if ( ! self::valid_name( $f ) ) {
				continue;
			}
			$m     = is_file( $p . '.meta' ) ? json_decode( (string) file_get_contents( $p . '.meta' ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$out[] = array(
				'file'    => $f,
				'created' => (string) ( $m['created'] ?? '' ),
				'reason'  => (string) ( $m['reason'] ?? '' ),
				'plugin'  => (string) ( $m['plugin'] ?? '' ),
				'db'      => (string) ( $m['db'] ?? '' ),
				'counts'  => (array) ( $m['counts'] ?? array() ),
				'size'    => (int) filesize( $p ),
			);
		}
		usort( $out, function ( $a, $b ) { return strcmp( $b['file'], $a['file'] ); } );
		return $out;
	}

	/** Put a backup back. Returns array( ok, message ). Takes a snapshot of the present first. */
	public static function restore( $file ) {
		global $wpdb;
		$dir = self::dir();
		if ( ! self::valid_name( $file ) || '' === $dir || ! is_file( $dir . '/' . $file ) ) {
			return array( false, __( 'That backup was not found.', 'songpress' ) );
		}
		$raw  = function_exists( 'gzdecode' ) ? gzdecode( (string) file_get_contents( $dir . '/' . $file ) ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$data = $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || empty( $data['tables'] ) ) {
			return array( false, __( 'That backup could not be read.', 'songpress' ) );
		}
		if ( (string) ( $data['db'] ?? '' ) !== (string) SONGPRESS_DB_VERSION ) {
			return array( false, sprintf( /* translators: 1: backup database version, 2: this site's */ __( 'That backup was made on database version %1$s and this site is on %2$s, so it cannot be put back safely.', 'songpress' ), (string) ( $data['db'] ?? '?' ), SONGPRESS_DB_VERSION ) );
		}
		$safe = self::snapshot( 'before restoring ' . $file );
		if ( '' === $safe ) {
			return array( false, __( 'Could not save how things are now first, so nothing was changed.', 'songpress' ) );
		}
		$n = 0;
		foreach ( self::tables() as $t ) {
			if ( ! isset( $data['tables'][ $t ] ) ) {
				continue;
			}
			$tn = Songpress_Install::table( $t );
			$wpdb->query( "DELETE FROM {$tn}" ); // phpcs:ignore WordPress.DB
			$cols = $data['tables'][ $t ]['cols'];
			$rows = $data['tables'][ $t ]['rows'];
			if ( $cols && $rows ) {
				$n += self::insert_rows( $tn, $cols, $rows );
			}
		}
		Songpress_Audit::log( 'other', 'Restored a backup', $file, array( 'rows' => $n, 'saved_first' => $safe ) );
		return array( true, sprintf( /* translators: 1: rows, 2: backup taken first */ __( 'Restored %1$d rows. How things were just before is saved as %2$s.', 'songpress' ), $n, $safe ) );
	}

	/** Put rows back exactly as saved, keeping NULL as NULL (a blank is not the same: share links are unique). */
	private static function insert_rows( $table, array $cols, array $rows ) {
		global $wpdb;
		$n = 0;
		foreach ( array_chunk( $rows, 300 ) as $chunk ) {
			$vals = array();
			foreach ( $chunk as $r ) {
				$parts = array();
				foreach ( $r as $v ) {
					$parts[] = null === $v ? 'NULL' : $wpdb->prepare( '%s', (string) $v );
				}
				$vals[] = '(' . implode( ',', $parts ) . ')';
			}
			$wpdb->query( 'REPLACE INTO ' . $table . ' (' . implode( ',', $cols ) . ') VALUES ' . implode( ',', $vals ) ); // phpcs:ignore WordPress.DB -- our table and column names; values prepared above.
			$n += count( $chunk );
		}
		return $n;
	}

	/* ---------- handlers ---------- */

	private static function guard( $nonce ) {
		check_admin_referer( $nonce );
		if ( ! Songpress_Caps::can( 'settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
	}

	private static function back( $args ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'sp_page' => 'backups' ), $args ), Songpress_Editor::list_url() ) );
		exit;
	}

	public static function handle_make() {
		self::guard( 'songpress_backup_make' );
		$f = self::snapshot( 'by hand' );
		Songpress_Audit::log( 'other', 'Made a backup', $f );
		self::back( '' !== $f ? array( 'b_ok' => rawurlencode( __( 'Backup saved.', 'songpress' ) ) ) : array( 'b_err' => rawurlencode( __( 'Could not save a backup. Check that the uploads folder is writable.', 'songpress' ) ) ) );
	}

	public static function handle_restore() {
		self::guard( 'songpress_backup_restore' );
		if ( empty( $_POST['sure'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::back( array( 'b_err' => rawurlencode( __( 'Tick the box to confirm the restore.', 'songpress' ) ) ) );
		}
		list( $ok, $msg ) = self::restore( sanitize_text_field( wp_unslash( $_POST['file'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		self::back( array( $ok ? 'b_ok' : 'b_err' => rawurlencode( $msg ) ) );
	}

	public static function handle_download() {
		self::guard( 'songpress_backup_download' );
		$f   = sanitize_text_field( wp_unslash( $_GET['file'] ?? '' ) );
		$dir = self::dir();
		if ( ! self::valid_name( $f ) || '' === $dir || ! is_file( $dir . '/' . $f ) ) {
			wp_die( esc_html__( 'That backup was not found.', 'songpress' ), '', 404 );
		}
		nocache_headers();
		header( 'Content-Type: application/gzip' );
		header( 'Content-Disposition: attachment; filename="' . $f . '"' );
		readfile( $dir . '/' . $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ---------- screen ---------- */

	public static function render() {
		if ( ! Songpress_Caps::can( 'settings' ) ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'Only Managers can open this screen.', 'songpress' ) . '</p></div>';
		}
		$o = '<div class="songpress sp-app sp-editor sp-backups"><div class="spe-top"><h2>' . esc_html__( 'Backups', 'songpress' ) . '</h2></div>';
		if ( ! empty( $_GET['b_ok'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<p class="spe-note ok">' . esc_html( sanitize_text_field( wp_unslash( $_GET['b_ok'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( ! empty( $_GET['b_err'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<p class="spe-note bad" role="alert">' . esc_html( sanitize_text_field( wp_unslash( $_GET['b_err'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		$o .= '<p class="spe-help">' . esc_html( sprintf( /* translators: %d: how many backups are kept */ __( 'A backup is taken for you before the risky things (loading the old catalog, statements, tidying logins, deleting), at most every ten minutes. The newest %d are kept. Restoring a backup first saves how things are now, so a restore can be undone. The activity log and song history are never rewound.', 'songpress' ), self::KEEP ) ) . '</p>';
		$o .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_backup_make', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_backup_make"><button class="spe-save" type="submit">' . esc_html__( 'Back up now', 'songpress' ) . '</button></form>';
		$all = self::all();
		if ( ! $all ) {
			return $o . '<p class="spe-help">' . esc_html__( 'No backups yet.', 'songpress' ) . '</p></div>';
		}
		$o .= '<ul class="spa-list">';
		foreach ( $all as $b ) {
			$songs = (int) ( $b['counts']['songs'] ?? 0 );
			$o    .= '<li><time>' . esc_html( $b['created'] ? get_date_from_gmt( $b['created'], 'Y-m-d H:i' ) : $b['file'] ) . '</time> <b>' . esc_html( '' !== $b['reason'] ? $b['reason'] : __( 'backup', 'songpress' ) ) . '</b> <span class="spa-area">' . esc_html( size_format( $b['size'] ) ) . '</span><small>' . esc_html( sprintf( /* translators: 1: songs, 2: version */ __( '%1$d songs · plugin %2$s', 'songpress' ), $songs, $b['plugin'] ) ) . '</small>';
			$o    .= ' <a class="spe-btn" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=songpress_backup_download&file=' . rawurlencode( $b['file'] ) ), 'songpress_backup_download' ) ) . '">' . esc_html__( 'Download', 'songpress' ) . '</a>';
			$o    .= '<form method="post" class="spj-flag" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_backup_restore', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_backup_restore"><input type="hidden" name="file" value="' . esc_attr( $b['file'] ) . '"><label><input type="checkbox" name="sure" value="1"> ' . esc_html__( 'I am sure', 'songpress' ) . '</label> <button class="spe-btn" type="submit">' . esc_html__( 'Restore this', 'songpress' ) . '</button></form></li>';
		}
		return $o . '</ul></div>';
	}
}
