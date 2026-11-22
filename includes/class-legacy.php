<?php
defined( 'ABSPATH' ) || exit;

/**
 * Loads a whole old database (a SQLite file, or an .sql dump) into SongPress's own database, every table and
 * every row, as JSON in one table. This keeps information the screens do not use yet, so nothing is lost.
 * It runs in short steps that continue on their own, so a big file does not time out. Safe to run again:
 * rows already stored are skipped.
 */
class Songpress_Legacy {

	const STATE  = 'songpress_legacy_state';
	const BATCH  = 500;
	const BUDGET = 15; // seconds of work per step

	/** SQL verb for the insert. MySQL says INSERT IGNORE; the SQLite stand-in used in tests says INSERT OR IGNORE. */
	public static $verb = 'INSERT IGNORE';

	public static function init() {
		add_action( 'admin_post_songpress_legacy_upload', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_songpress_legacy_step', array( __CLASS__, 'handle_step' ) );
		add_action( 'admin_post_songpress_legacy_cancel', array( __CLASS__, 'handle_cancel' ) );
	}

	private static function table() {
		return Songpress_Install::table( 'legacy_rows' );
	}

	/* ---------- pure helpers ---------- */

	/** One row as JSON. Text that is not valid UTF-8 and binary values become "base64:...". */
	public static function row_json( array $row ) {
		foreach ( $row as $k => $v ) {
			if ( is_resource( $v ) ) {
				$v = stream_get_contents( $v );
			}
			if ( is_string( $v ) && ! preg_match( '//u', $v ) ) {
				$row[ $k ] = 'base64:' . base64_encode( $v );
			} else {
				$row[ $k ] = $v;
			}
		}
		$j = json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
		return false === $j ? '{}' : $j;
	}

	public static function table_list( PDO $pdo ) {
		$out = array();
		foreach ( $pdo->query( "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name" ) as $r ) {
			$n = (int) $pdo->query( 'SELECT COUNT(*) FROM "' . str_replace( '"', '""', $r['name'] ) . '"' )->fetchColumn();
			$out[ $r['name'] ] = array( 'total' => $n, 'done' => 0, 'last' => 0 );
		}
		return $out;
	}

	/**
	 * Store the next rows of one table. Returns the number of rows read; 0 means the table is finished.
	 * $st is the table's progress: done (rows read) and last (the last rowid read).
	 */
	public static function copy_batch( PDO $pdo, $name, array &$st, $limit = self::BATCH ) {
		global $wpdb;
		$q    = '"' . str_replace( '"', '""', $name ) . '"';
		$rows = array();
		try {
			$stmt = $pdo->prepare( "SELECT rowid AS __sp_rowid, * FROM {$q} WHERE rowid > ? ORDER BY rowid LIMIT " . (int) $limit );
			$stmt->execute( array( (int) $st['last'] ) );
			$rows = $stmt->fetchAll( PDO::FETCH_ASSOC );
		} catch ( Exception $e ) { // a table without a rowid: read it by position instead
			$stmt = $pdo->prepare( "SELECT * FROM {$q} LIMIT " . (int) $limit . ' OFFSET ' . (int) $st['done'] );
			$stmt->execute();
			$rows = $stmt->fetchAll( PDO::FETCH_ASSOC );
		}
		if ( ! $rows ) {
			return 0;
		}
		$now  = current_time( 'mysql' );
		$vals = array();
		foreach ( $rows as $r ) {
			if ( isset( $r['__sp_rowid'] ) ) {
				$st['last'] = (int) $r['__sp_rowid'];
				unset( $r['__sp_rowid'] );
			}
			$j      = self::row_json( $r );
			$vals[] = array( substr( $name, 0, 64 ), md5( $j ), $j, $now );
		}
		Songpress_Money::batch( self::table(), array( 'source_table', 'row_key', 'row_data', 'imported_at' ), $vals, self::$verb );
		$st['done'] += count( $rows );
		return count( $rows );
	}

	/** Work through the tables until the time budget runs out. Returns true when everything is stored. */
	public static function run_step( PDO $pdo, array &$tables, $budget = self::BUDGET ) {
		$stop = microtime( true ) + $budget;
		foreach ( $tables as $name => &$st ) {
			while ( $st['done'] < $st['total'] || 0 === $st['total'] ) {
				if ( 0 === $st['total'] ) {
					break;
				}
				if ( 0 === self::copy_batch( $pdo, $name, $st ) ) {
					$st['done'] = $st['total'];
					break;
				}
				if ( microtime( true ) > $stop ) {
					return false;
				}
			}
		}
		unset( $st );
		return true;
	}

	/** What is stored so far: source table => rows. */
	public static function stored() {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT source_table, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY source_table ORDER BY source_table' ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ $r->source_table ] = (int) $r->n;
		}
		return $out;
	}

	/** Turn an .sql dump into a SQLite file. The dump has to be SQLite-style SQL (what .dump writes). */
	public static function sql_to_sqlite( $sql_path, $dest ) {
		$pdo = new PDO( 'sqlite:' . $dest, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
		$sql = (string) file_get_contents( $sql_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$pdo->exec( $sql );
		return true;
	}

	/* ---------- screens ---------- */

	private static function open( $path ) {
		return new PDO( 'sqlite:' . $path, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
	}

	private static function back( $args = array() ) {
		$ret = isset( $_REQUEST['sp_return'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_REQUEST['sp_return'] ) ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- every caller checked its nonce.
		$to  = '' !== $ret ? $ret : admin_url( 'admin.php?page=songpress-settings' );
		wp_safe_redirect( add_query_arg( $args, remove_query_arg( array( 'legacy_err', 'legacy_ok', 'legacy_go' ), $to ) ) );
		exit;
	}

	private static function guard( $action ) {
		check_admin_referer( $action );
		if ( ! Songpress_Caps::can( 'import' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
	}

	public static function handle_upload() {
		self::guard( 'songpress_legacy_upload' );
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			self::back( array( 'legacy_err' => rawurlencode( __( 'This server\'s PHP does not have SQLite support (pdo_sqlite). Ask your host to enable it.', 'songpress' ) ) ) );
		}
		$f = isset( $_FILES['legacy'] ) ? $_FILES['legacy'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked below.
		if ( empty( $f['tmp_name'] ) || ! empty( $f['error'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
			self::back( array( 'legacy_err' => rawurlencode( __( 'The upload failed. The file may be larger than your server allows (see upload_max_filesize).', 'songpress' ) ) ) );
		}
		$dir = Songpress_Import::private_dir();
		if ( ! $dir ) {
			self::back( array( 'legacy_err' => rawurlencode( __( 'SongPress could not create a private folder for the file.', 'songpress' ) ) ) );
		}
		$dest = $dir . '/legacy-' . wp_generate_password( 16, false, false ) . '.sqlite';
		$head = (string) file_get_contents( $f['tmp_name'], false, null, 0, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( 0 === strpos( $head, "SQLite format 3\0" ) ) {
			if ( ! move_uploaded_file( $f['tmp_name'], $dest ) ) {
				self::back( array( 'legacy_err' => rawurlencode( __( 'The file could not be saved.', 'songpress' ) ) ) );
			}
		} else {
			try {
				self::sql_to_sqlite( $f['tmp_name'], $dest );
			} catch ( Exception $e ) {
				@unlink( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
				self::back( array( 'legacy_err' => rawurlencode( sprintf( /* translators: %s: reason */ __( 'That file is not a SQLite database or a SQLite-style .sql dump: %s', 'songpress' ), $e->getMessage() ) ) ) );
			}
		}
		try {
			$tables = self::table_list( self::open( $dest ) );
		} catch ( Exception $e ) {
			@unlink( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			self::back( array( 'legacy_err' => rawurlencode( $e->getMessage() ) ) );
		}
		update_option( self::STATE, array( 'path' => $dest, 'name' => sanitize_file_name( (string) ( $f['name'] ?? '' ) ), 'tables' => $tables, 'at' => time() ), false );
		self::back( array( 'legacy_go' => 1 ) );
	}

	public static function handle_step() {
		self::guard( 'songpress_legacy_step' );
		$st = get_option( self::STATE );
		if ( ! is_array( $st ) || empty( $st['path'] ) || ! file_exists( $st['path'] ) ) {
			self::back( array( 'legacy_err' => rawurlencode( __( 'There is no file waiting. Choose it again.', 'songpress' ) ) ) );
		}
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		try {
			$done = self::run_step( self::open( $st['path'] ), $st['tables'] );
		} catch ( Exception $e ) {
			self::back( array( 'legacy_err' => rawurlencode( $e->getMessage() ) ) );
		}
		if ( $done ) {
			@unlink( $st['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			update_option( self::STATE, array( 'finished' => time(), 'name' => $st['name'], 'tables' => $st['tables'] ), false );
			Songpress_Audit::log( 'settings', 'Loaded a whole database file', (string) $st['name'] );
			self::back( array( 'legacy_ok' => 1 ) );
		}
		update_option( self::STATE, $st, false );
		self::back( array( 'legacy_go' => 1 ) );
	}

	public static function handle_cancel() {
		self::guard( 'songpress_legacy_cancel' );
		$st = get_option( self::STATE );
		if ( is_array( $st ) && ! empty( $st['path'] ) ) {
			@unlink( $st['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
		delete_option( self::STATE );
		self::back();
	}

	/** The box shown in the settings pane. */
	public static function box( $return = '' ) {
		if ( ! Songpress_Caps::can( 'import' ) ) {
			return '';
		}
		$st  = get_option( self::STATE );
		$ret = '' !== $return ? $return : admin_url( 'admin.php?page=songpress-settings' );
		$o   = '<h2>' . esc_html__( 'Load a whole database file', 'songpress' ) . '</h2>';
		$o  .= '<p class="sp-help spe-help">' . esc_html__( 'Choose a SQLite file (.sqlite, .db) or an .sql dump. Every table and every row is copied into SongPress\'s own database, even the ones SongPress does not use yet, so nothing is lost. It works in short steps and carries on by itself. Run it again with a newer file: rows already stored are skipped.', 'songpress' ) . '</p>';
		if ( isset( $_GET['legacy_err'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['legacy_err'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( is_array( $st ) && ! empty( $st['path'] ) ) { // a file is being loaded
			$total = 0;
			$done  = 0;
			foreach ( $st['tables'] as $t ) {
				$total += (int) $t['total'];
				$done  += (int) $t['done'];
			}
			$next = wp_nonce_url( add_query_arg( array( 'action' => 'songpress_legacy_step', 'sp_return' => rawurlencode( $ret ) ), admin_url( 'admin-post.php' ) ), 'songpress_legacy_step' );
			$o   .= '<p><strong>' . esc_html( sprintf( /* translators: 1: rows stored, 2: rows in the file */ __( 'Loading… %1$s of %2$s rows.', 'songpress' ), number_format_i18n( $done ), number_format_i18n( $total ) ) ) . '</strong> ' . esc_html__( 'Keep this page open; it continues by itself.', 'songpress' ) . '</p>';
			$o   .= '<p><a class="button button-primary spe-btn" href="' . esc_url( $next ) . '">' . esc_html__( 'Continue now', 'songpress' ) . '</a> ';
			$o   .= '<a class="button spe-btn" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'songpress_legacy_cancel', 'sp_return' => rawurlencode( $ret ) ), admin_url( 'admin-post.php' ) ), 'songpress_legacy_cancel' ) ) . '">' . esc_html__( 'Cancel', 'songpress' ) . '</a></p>';
			if ( isset( $_GET['legacy_go'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$o .= '<script>setTimeout(function(){location.href=' . wp_json_encode( $next ) . ';},400);</script>';
			}
			return $o;
		}
		if ( isset( $_GET['legacy_ok'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<div class="notice notice-success"><p>' . esc_html__( 'The whole file is stored.', 'songpress' ) . '</p></div>';
		}
		$o .= '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_legacy_upload', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_legacy_upload"><input type="hidden" name="sp_return" value="' . esc_attr( $ret ) . '">';
		$o .= '<input type="file" name="legacy" accept=".sqlite,.db,.sqlite3,.sql" required> <button class="button button-primary spe-save" type="submit">' . esc_html__( 'Load everything', 'songpress' ) . '</button></form>';
		$have = self::stored();
		if ( $have ) {
			$o .= '<details><summary>' . esc_html( sprintf( /* translators: %d: number of tables */ _n( '%d table stored', '%d tables stored', count( $have ), 'songpress' ), count( $have ) ) ) . '</summary><ul>';
			foreach ( $have as $t => $n ) {
				$o .= '<li><code>' . esc_html( $t ) . '</code> ' . esc_html( number_format_i18n( $n ) ) . '</li>';
			}
			$o .= '</ul></details>';
		}
		return $o;
	}
}
