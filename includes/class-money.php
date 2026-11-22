<?php
defined( 'ABSPATH' ) || exit;

/**
 * Money and Stats, inside the SongPress app.
 *
 * Earnings come from the statements already imported from the old S&P Catalog file (DistroKid, MLC, ASCAP,
 * CCLI). The imported figures are replaced each time the file is loaded again; payouts and expenses are
 * entered here by hand and are never replaced by an import. Chord tools and lyrics never touch these tables.
 */
class Songpress_Money {

	const SOURCES = array(
		'distrokid' => 'DistroKid',
		'mlc'       => 'MLC',
		'ascap'     => 'ASCAP',
		'ccli'      => 'CCLI',
	);

	const SRC_CODES = array( 'D' => 'distrokid', 'M' => 'mlc', 'A' => 'ascap', 'C' => 'ccli' );

	public static function init() {
		foreach ( array( 'import', 'pay', 'expense_add', 'expense_delete', 'stats_import' ) as $a ) {
			add_action( 'admin_post_songpress_money_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	/* ---------- pure helpers ---------- */

	/** "2026 Q2" becomes "2026-Q2". Anything else comes back empty. */
	public static function quarter( $q ) {
		if ( preg_match( '/^\s*(\d{4})\s*[-\s]?\s*Q([1-4])\s*$/i', (string) $q, $m ) ) {
			return $m[1] . '-Q' . $m[2];
		}
		return '';
	}

	/** "2026-07-01" becomes "2026-07". */
	public static function month( $d ) {
		return preg_match( '/^(\d{4})-(\d{2})/', (string) $d, $m ) ? $m[1] . '-' . $m[2] : '';
	}

	public static function src( $code ) {
		$code = trim( (string) $code );
		if ( isset( self::SRC_CODES[ strtoupper( $code ) ] ) ) {
			return self::SRC_CODES[ strtoupper( $code ) ];
		}
		$low = strtolower( $code );
		return isset( self::SOURCES[ $low ] ) ? $low : '';
	}

	public static function norm_title( $t ) {
		return strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $t ) );
	}

	public static function money( $n, $dp = 2 ) {
		return '$' . number_format( (float) $n, $dp );
	}

	public static function person_key( $name ) {
		return Songpress_People::key_for( $name );
	}

	/* ---------- import ---------- */

	private static function t( $name ) {
		return Songpress_Install::table( $name );
	}

	/** Insert rows in batches. $cols are column names; $rows are arrays of values in that order. */
	private static function batch( $table, array $cols, array $rows, $verb = 'REPLACE' ) {
		global $wpdb;
		$n = 0;
		foreach ( array_chunk( $rows, 400 ) as $chunk ) {
			$vals = array();
			foreach ( $chunk as $r ) {
				$parts = array();
				foreach ( $r as $v ) {
					$parts[] = is_int( $v ) || is_float( $v ) ? ( is_float( $v ) ? rtrim( rtrim( sprintf( '%.8F', $v ), '0' ), '.' ) : (string) $v ) : $wpdb->prepare( '%s', (string) $v );
				}
				$vals[] = '(' . implode( ',', $parts ) . ')';
			}
			$wpdb->query( $verb . ' INTO ' . $table . ' (' . implode( ',', $cols ) . ') VALUES ' . implode( ',', $vals ) ); // phpcs:ignore WordPress.DB -- table names are ours; values are prepared above.
			$n += count( $chunk );
		}
		return $n;
	}

	/** Old catalog song id (legacy_id) to the SongPress song id. */
	private static function legacy_map() {
		global $wpdb;
		$s   = Songpress_Install::table( 'songs' );
		$map = array();
		foreach ( $wpdb->get_results( "SELECT id, legacy_id FROM {$s} WHERE legacy_id IS NOT NULL AND legacy_id > 0" ) as $r ) { // phpcs:ignore WordPress.DB
			$map[ (int) $r->legacy_id ] = (int) $r->id;
		}
		return $map;
	}

	/** ISRC and normalized title to song id, so statement lines find their song. */
	private static function lookups() {
		global $wpdb;
		$s   = Songpress_Install::table( 'songs' );
		$i   = Songpress_Install::table( 'identifiers' );
		$isr = array();
		foreach ( $wpdb->get_results( "SELECT song_id, value FROM {$i} WHERE kind = 'isrc'" ) as $r ) { // phpcs:ignore WordPress.DB
			$isr[ strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $r->value ) ) ] = (int) $r->song_id;
		}
		$tit = array();
		foreach ( $wpdb->get_results( "SELECT id, title FROM {$s} ORDER BY id DESC" ) as $r ) { // phpcs:ignore WordPress.DB
			$tit[ self::norm_title( $r->title ) ] = (int) $r->id;
		}
		return array( $isr, $tit );
	}

	/**
	 * Bring money across from the old catalog file. Statements, per-person earnings, streams and store figures
	 * are replaced by what the file holds; payouts already entered here are kept.
	 */
	public static function import_sqlite( $path ) {
		global $wpdb;
		$out = array( 'earnings' => 0, 'person' => 0, 'payments' => 0, 'streams' => 0, 'stats' => 0, 'journal' => 0, 'ratings' => 0, 'art' => 0, 'error' => '' );
		try {
			$pdo = new PDO( 'sqlite:' . $path, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
		} catch ( Exception $e ) {
			$out['error'] = $e->getMessage();
			return $out;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions
		}
		$has = function ( $table ) use ( $pdo ) {
			$q = $pdo->prepare( "SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?" );
			$q->execute( array( $table ) );
			return (bool) $q->fetchColumn();
		};
		$map = self::legacy_map();
		list( $isr, $tit ) = self::lookups();

		if ( $has( 'royalty_work_quarter' ) ) {
			$sum = array();
			foreach ( $pdo->query( 'SELECT work_id, quarter, src, amount FROM royalty_work_quarter' ) as $r ) {
				$q = self::quarter( $r['quarter'] );
				$s = self::src( $r['src'] );
				$id = $map[ (int) $r['work_id'] ] ?? 0;
				if ( '' === $q || '' === $s || ! $id ) {
					continue;
				}
				$k         = $id . '|' . $q . '|' . $s;
				$sum[ $k ] = ( $sum[ $k ] ?? 0 ) + (float) $r['amount'];
			}
			$rows = array();
			foreach ( $sum as $k => $amt ) {
				list( $id, $q, $s ) = explode( '|', $k );
				$rows[]             = array( (int) $id, $q, $s, (float) $amt );
			}
			$wpdb->query( 'DELETE FROM ' . self::t( 'earnings' ) ); // phpcs:ignore WordPress.DB
			$out['earnings'] = self::batch( self::t( 'earnings' ), array( 'song_id', 'quarter', 'src', 'amount' ), $rows );
		}

		if ( $has( 'royalty_person_quarter' ) ) {
			$by = array();
			foreach ( $pdo->query( 'SELECT person, work_title, quarter, src, amount FROM royalty_person_quarter' ) as $r ) {
				$q   = self::quarter( $r['quarter'] );
				$s   = self::src( $r['src'] );
				$key = self::person_key( $r['person'] );
				if ( '' === $q || '' === $s || '' === $key ) {
					continue;
				}
				$k = $key . '|' . $q . '|' . $s . '|' . self::norm_title( $r['work_title'] );
				if ( ! isset( $by[ $k ] ) ) {
					$by[ $k ] = array( trim( $r['person'] ), $key, trim( $r['work_title'] ), $tit[ self::norm_title( $r['work_title'] ) ] ?? 0, $q, $s, 0.0 );
				}
				$by[ $k ][6] += (float) $r['amount'];
			}
			$wpdb->query( 'DELETE FROM ' . self::t( 'person_earnings' ) ); // phpcs:ignore WordPress.DB
			$out['person'] = self::batch( self::t( 'person_earnings' ), array( 'person', 'person_key', 'work_title', 'song_id', 'quarter', 'src', 'amount' ), array_values( $by ), 'INSERT' );
		}

		if ( $has( 'paid_entry' ) || $has( 'pay_board' ) ) {
			$p = self::t( 'payments' );
			$put = function ( $y, $q, $kind, $party, $amount, $paid, $on, $notes ) use ( $wpdb, $p ) {
				$key = self::person_key( $party );
				if ( '' === $key || $y < 2000 || $q < 1 || $q > 4 ) {
					return 0;
				}
				$have = $wpdb->get_row( $wpdb->prepare( "SELECT id, paid FROM {$p} WHERE pay_year = %d AND pay_quarter = %d AND kind = %s AND party_key = %s", $y, $q, $kind, $key ) ); // phpcs:ignore WordPress.DB
				if ( $have ) {
					return 0; // keep what was already entered here
				}
				$wpdb->insert( $p, array(
					'pay_year' => $y, 'pay_quarter' => $q, 'kind' => $kind, 'party' => trim( $party ), 'party_key' => $key,
					'amount' => (float) $amount, 'paid' => $paid ? 1 : 0, 'paid_on' => $on ? substr( $on, 0, 10 ) : null,
					'notes' => (string) $notes, 'updated_at' => current_time( 'mysql' ),
				) );
				return 1;
			};
			if ( $has( 'paid_entry' ) ) {
				foreach ( $pdo->query( 'SELECT y, q, party, kind, amount, notes FROM paid_entry' ) as $r ) {
					$out['payments'] += $put( (int) $r['y'], (int) $r['q'], $r['kind'] ?: 'writer', $r['party'], $r['amount'], false, '', $r['notes'] );
				}
			}
			if ( $has( 'pay_board' ) ) {
				foreach ( $pdo->query( 'SELECT y, q, kind, party, paid, paid_on FROM pay_board' ) as $r ) {
					$key = self::person_key( $r['party'] );
					$row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$p} WHERE pay_year = %d AND pay_quarter = %d AND kind = %s AND party_key = %s", (int) $r['y'], (int) $r['q'], $r['kind'] ?: 'writer', $key ) ); // phpcs:ignore WordPress.DB
					if ( $row ) {
						if ( (int) $r['paid'] ) {
							// the old board only flagged "paid"; when no amount was recorded, the amount is what they earned that quarter
							$pe = self::t( 'person_earnings' );
							$wpdb->query( $wpdb->prepare( "UPDATE {$p} SET amount = COALESCE((SELECT SUM(amount) FROM {$pe} WHERE person_key = %s AND quarter = %s), 0) WHERE id = %d AND paid = 0 AND amount = 0", $key, (int) $r['y'] . '-Q' . (int) $r['q'], (int) $row->id ) ); // phpcs:ignore WordPress.DB
							$wpdb->query( $wpdb->prepare( "UPDATE {$p} SET paid = 1, paid_on = %s WHERE id = %d AND paid = 0", substr( (string) $r['paid_on'], 0, 10 ), (int) $row->id ) ); // phpcs:ignore WordPress.DB
						}
					} else {
						$out['payments'] += $put( (int) $r['y'], (int) $r['q'], $r['kind'] ?: 'writer', $r['party'], 0, (int) $r['paid'], (string) $r['paid_on'], '' );
					}
				}
			}
		}

		if ( $has( 'dk_daily_estimates' ) ) {
			$rows = array();
			foreach ( $pdo->query( 'SELECT day, service, streams FROM dk_daily_estimates' ) as $r ) {
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $r['day'] ) ) {
					$rows[] = array( substr( $r['day'], 0, 10 ), (string) $r['service'], (int) $r['streams'] );
				}
			}
			$wpdb->query( 'DELETE FROM ' . self::t( 'streams' ) ); // phpcs:ignore WordPress.DB
			$out['streams'] = self::batch( self::t( 'streams' ), array( 'day', 'service', 'streams' ), $rows );
		}

		if ( $has( 'distrokid' ) ) {
			$agg = array();
			foreach ( $pdo->query( 'SELECT sale_month, store, title, isrc, SUM(quantity) AS u, SUM(earnings_usd) AS e FROM distrokid GROUP BY sale_month, store, title, isrc' ) as $r ) {
				$m = self::month( $r['sale_month'] );
				if ( '' === $m ) {
					continue;
				}
				$code = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $r['isrc'] ) );
				$sid  = ( '' !== $code && isset( $isr[ $code ] ) ) ? $isr[ $code ] : ( $tit[ self::norm_title( $r['title'] ) ] ?? 0 );
				$key  = md5( $m . '|' . $r['store'] . '|' . $r['title'] . '|' . $code );
				if ( isset( $agg[ $key ] ) ) {
					$agg[ $key ][6] += (int) $r['u'];
					$agg[ $key ][7] += (float) $r['e'];
				} else {
					$agg[ $key ] = array( $key, $m, mb_substr( (string) $r['store'], 0, 64 ), mb_substr( (string) $r['title'], 0, 255 ), substr( $code, 0, 24 ), (int) $sid, (int) $r['u'], (float) $r['e'] );
				}
			}
			$wpdb->query( 'DELETE FROM ' . self::t( 'stats' ) ); // phpcs:ignore WordPress.DB
			$out['stats'] = self::batch( self::t( 'stats' ), array( 'row_key', 'month', 'store', 'title', 'isrc', 'song_id', 'units', 'earnings' ), array_values( $agg ) );
		}
		$j = Songpress_Journal::import_pdo( $pdo );
		$out['journal'] = $j['journal'];
		$out['ratings'] = $j['ratings'];
		$out['art']     = Songpress_Art::import_pdo( $pdo );
		return $out;
	}


	/**
	 * Read the stats page saved from DistroKid. The page carries its numbers as JSON in a script tag
	 * (id="combinedStatsData"): a list of services and a daily series. Returns rows of day, service, streams,
	 * or a string describing what is wrong with the file. Pure.
	 */
	public static function parse_stats_html( $html ) {
		if ( ! preg_match( '/id="combinedStatsData"[^>]*>\s*(.*?)\s*<\/script>/s', (string) $html, $m ) ) {
			return 'That file has no DistroKid daily stats. Save the stats page from DistroKid as HTML and choose that file.';
		}
		$data = json_decode( html_entity_decode( $m[1] ), true );
		if ( ! is_array( $data ) || empty( $data['series']['daily'] ) || ! is_array( $data['series']['daily'] ) ) {
			return 'That file has no daily series.';
		}
		$labels = array();
		foreach ( ( isset( $data['dsps'] ) && is_array( $data['dsps'] ) ) ? $data['dsps'] : array() as $dsp ) {
			if ( is_array( $dsp ) && ! empty( $dsp['key'] ) ) {
				$labels[ (string) $dsp['key'] ] = isset( $dsp['label'] ) ? trim( (string) $dsp['label'] ) : (string) $dsp['key'];
			}
		}
		$rows = array();
		foreach ( $data['series']['daily'] as $point ) {
			if ( ! is_array( $point ) || empty( $point['category'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $point['category'] ) ) {
				continue;
			}
			foreach ( $point as $key => $value ) {
				if ( 'category' === $key || ! is_numeric( $value ) ) {
					continue;
				}
				$rows[] = array( (string) $point['category'], mb_substr( $labels[ $key ] ?? ucfirst( (string) $key ), 0, 48 ), (int) $value );
			}
		}
		return $rows ? $rows : 'The daily series was empty.';
	}

	/** Save parsed days. A day already here is updated; days the new file does not cover are kept. */
	public static function import_stats_html( $html ) {
		$rows = self::parse_stats_html( $html );
		if ( is_string( $rows ) ) {
			return array( 'error' => $rows );
		}
		$days = array();
		foreach ( $rows as $r ) {
			$days[ $r[0] ] = true;
		}
		self::batch( self::t( 'streams' ), array( 'day', 'service', 'streams' ), $rows );
		return array( 'error' => '', 'days' => count( $days ), 'rows' => count( $rows ) );
	}

	/* ---------- queries ---------- */

	public static function quarters() {
		global $wpdb;
		$t = self::t( 'earnings' );
		return $wpdb->get_col( "SELECT DISTINCT quarter FROM {$t} ORDER BY quarter DESC" ); // phpcs:ignore WordPress.DB
	}

	/** Rows of quarter, src, total. */
	public static function by_quarter() {
		global $wpdb;
		$t = self::t( 'earnings' );
		return $wpdb->get_results( "SELECT quarter, src, SUM(amount) AS total FROM {$t} GROUP BY quarter, src ORDER BY quarter DESC" ); // phpcs:ignore WordPress.DB
	}

	public static function top_songs( $limit = 15 ) {
		global $wpdb;
		$e = self::t( 'earnings' );
		$s = self::t( 'songs' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT s.id, s.serial, s.title, SUM(e.amount) AS total FROM {$e} e JOIN {$s} s ON s.id = e.song_id GROUP BY s.id, s.serial, s.title ORDER BY total DESC LIMIT %d", (int) $limit ) ); // phpcs:ignore WordPress.DB
	}

	/** What each person earned in a quarter. */
	public static function owed( $quarter ) {
		global $wpdb;
		$t = self::t( 'person_earnings' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT person_key, MIN(person) AS person, SUM(amount) AS total FROM {$t} WHERE quarter = %s GROUP BY person_key ORDER BY total DESC", $quarter ) ); // phpcs:ignore WordPress.DB
	}

	public static function payments_for( $year, $q ) {
		global $wpdb;
		$t   = self::t( 'payments' );
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE pay_year = %d AND pay_quarter = %d", (int) $year, (int) $q ) ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ $r->kind . '|' . $r->party_key ] = $r;
		}
		return $out;
	}

	public static function expenses( $limit = 200 ) {
		global $wpdb;
		$t = self::t( 'expenses' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY spent_on DESC, id DESC LIMIT %d", (int) $limit ) ); // phpcs:ignore WordPress.DB
	}

	public static function expense_total() {
		global $wpdb;
		$t = self::t( 'expenses' );
		return (float) $wpdb->get_var( "SELECT SUM(amount) FROM {$t}" ); // phpcs:ignore WordPress.DB
	}

	public static function earned_total() {
		global $wpdb;
		$t = self::t( 'earnings' );
		return (float) $wpdb->get_var( "SELECT SUM(amount) FROM {$t}" ); // phpcs:ignore WordPress.DB
	}

	public static function paid_total() {
		global $wpdb;
		$t = self::t( 'payments' );
		return (float) $wpdb->get_var( "SELECT SUM(amount) FROM {$t} WHERE paid = 1" ); // phpcs:ignore WordPress.DB
	}

	/** Earnings of one person (by name key), newest quarter first. */
	public static function my_rows( $key ) {
		global $wpdb;
		$t = self::t( 'person_earnings' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT quarter, src, SUM(amount) AS total FROM {$t} WHERE person_key = %s GROUP BY quarter, src ORDER BY quarter DESC", $key ) ); // phpcs:ignore WordPress.DB
	}

	public static function my_songs( $key, $limit = 10 ) {
		global $wpdb;
		$t = self::t( 'person_earnings' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT MIN(work_title) AS work_title, MAX(song_id) AS song_id, SUM(amount) AS total FROM {$t} WHERE person_key = %s GROUP BY work_title ORDER BY total DESC LIMIT %d", $key, (int) $limit ) ); // phpcs:ignore WordPress.DB
	}


	/** Everything the charts need: income and plays per month and store, streams per day and service. */
	public static function chart_payload() {
		global $wpdb;
		$t   = self::t( 'stats' );
		$top = $wpdb->get_col( "SELECT store FROM {$t} GROUP BY store ORDER BY SUM(units) DESC LIMIT 9" ); // phpcs:ignore WordPress.DB
		$m   = array();
		foreach ( $wpdb->get_results( "SELECT month, store, SUM(units) AS u, SUM(earnings) AS e FROM {$t} GROUP BY month, store ORDER BY month" ) as $r ) { // phpcs:ignore WordPress.DB
			$name = in_array( $r->store, $top, true ) ? ( '' === $r->store ? 'Unknown' : $r->store ) : 'Other';
			$k    = $r->month . '|' . $name;
			if ( ! isset( $m[ $k ] ) ) {
				$m[ $k ] = array( $r->month, $name, 0, 0.0 );
			}
			$m[ $k ][2] += (int) $r->u;
			$m[ $k ][3] += (float) $r->e;
		}
		$d  = self::t( 'streams' );
		$dd = array();
		foreach ( $wpdb->get_results( "SELECT day, service, streams FROM {$d} ORDER BY day" ) as $r ) { // phpcs:ignore WordPress.DB
			$dd[] = array( $r->day, $r->service, (int) $r->streams );
		}
		foreach ( $m as &$x ) {
			$x[3] = round( $x[3], 4 );
		}
		return array( 'statements' => array_values( $m ), 'daily' => $dd );
	}

	public static function months() {
		global $wpdb;
		$t = self::t( 'stats' );
		return $wpdb->get_results( "SELECT month, SUM(units) AS units, SUM(earnings) AS earnings FROM {$t} GROUP BY month ORDER BY month DESC LIMIT 24" ); // phpcs:ignore WordPress.DB
	}

	public static function stores() {
		global $wpdb;
		$t = self::t( 'stats' );
		return $wpdb->get_results( "SELECT store, SUM(units) AS units, SUM(earnings) AS earnings FROM {$t} GROUP BY store ORDER BY earnings DESC LIMIT 12" ); // phpcs:ignore WordPress.DB
	}

	public static function top_streamed( $limit = 15 ) {
		global $wpdb;
		$t = self::t( 'stats' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT MIN(title) AS title, MAX(song_id) AS song_id, SUM(units) AS units, SUM(earnings) AS earnings FROM {$t} GROUP BY isrc, title ORDER BY units DESC LIMIT %d", (int) $limit ) ); // phpcs:ignore WordPress.DB
	}

	public static function daily( $days = 30 ) {
		global $wpdb;
		$t = self::t( 'streams' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(streams) AS streams FROM {$t} GROUP BY day ORDER BY day DESC LIMIT %d", (int) $days ) ); // phpcs:ignore WordPress.DB
	}

	public static function services() {
		global $wpdb;
		$t = self::t( 'streams' );
		return $wpdb->get_results( "SELECT service, SUM(streams) AS streams FROM {$t} GROUP BY service ORDER BY streams DESC" ); // phpcs:ignore WordPress.DB
	}

	/* ---------- handlers ---------- */

	private static function guard( $nonce ) {
		check_admin_referer( $nonce );
		if ( ! Songpress_Caps::can( 'money' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
	}

	private static function go( $slug, $args = array() ) {
		$ret = isset( $_POST['sp_return'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['sp_return'] ) ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked by guard().
		$base = '' !== $ret ? $ret : Songpress_Editor::list_url();
		wp_safe_redirect( add_query_arg( array_merge( array( 'sp_page' => $slug ), $args ), $base ) );
		exit;
	}

	public static function handle_import() {
		self::guard( 'songpress_money_import' );
		if ( empty( $_FILES['catalog']['tmp_name'] ) || ! is_uploaded_file( $_FILES['catalog']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::go( 'money', array( 'm_err' => __( 'No file was received.', 'songpress' ) ) );
		}
		if ( ! class_exists( 'PDO' ) || ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) {
			self::go( 'money', array( 'm_err' => __( 'This server cannot read SQLite files (pdo_sqlite is off).', 'songpress' ) ) );
		}
		$r = self::import_sqlite( $_FILES['catalog']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- an uploaded temp path, opened read-only by PDO.
		if ( '' !== $r['error'] ) {
			self::go( 'money', array( 'm_err' => $r['error'] ) );
		}
		self::go( 'money', array( 'm_ok' => sprintf( /* translators: 1: statement lines, 2: people lines, 3: payouts, 4: stream days, 5: store lines, 6: journal entries, 7: ratings, 8: art projects */ __( 'Loaded %1$d earnings lines, %2$d per-person lines, %3$d new payouts, %4$d days of streams, %5$d store lines, %6$d journal entries, %7$d ratings and %8$d art projects.', 'songpress' ), $r['earnings'], $r['person'], $r['payments'], $r['streams'], $r['stats'], $r['journal'], $r['ratings'], $r['art'] ) ) );
	}


	public static function handle_stats_import() {
		self::guard( 'songpress_money_stats_import' );
		if ( empty( $_FILES['stats']['tmp_name'] ) || ! is_uploaded_file( $_FILES['stats']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::go( 'stats', array( 'm_err' => __( 'No file was received.', 'songpress' ) ) );
		}
		$html = file_get_contents( $_FILES['stats']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.WP.AlternativeFunctions -- an uploaded temp file.
		$r    = self::import_stats_html( (string) $html );
		if ( '' !== $r['error'] ) {
			self::go( 'stats', array( 'm_err' => $r['error'] ) );
		}
		self::go( 'stats', array( 'm_ok' => sprintf( /* translators: 1: days, 2: day-and-service lines */ __( 'Saved %1$d days of streams (%2$d lines).', 'songpress' ), $r['days'], $r['rows'] ) ) );
	}

	public static function handle_pay() {
		self::guard( 'songpress_money_pay' );
		global $wpdb;
		$in    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- cleaned below.
		$q     = self::quarter( $in['quarter'] ?? '' );
		$party = sanitize_text_field( $in['party'] ?? '' );
		$key   = self::person_key( $party );
		if ( '' === $q || '' === $key ) {
			self::go( 'money' );
		}
		$year = (int) substr( $q, 0, 4 );
		$qn   = (int) substr( $q, -1 );
		$p    = self::t( 'payments' );
		$paid = ! empty( $in['paid'] ) ? 1 : 0;
		$on   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $in['paid_on'] ?? '' ) ) ? $in['paid_on'] : ( $paid ? gmdate( 'Y-m-d' ) : null );
		$row  = array(
			'amount'     => round( (float) str_replace( array( '$', ',' ), '', (string) ( $in['amount'] ?? 0 ) ), 2 ),
			'paid'       => $paid,
			'paid_on'    => $paid ? $on : null,
			'notes'      => sanitize_textarea_field( $in['notes'] ?? '' ),
			'updated_at' => current_time( 'mysql' ),
		);
		$have = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p} WHERE pay_year = %d AND pay_quarter = %d AND kind = 'writer' AND party_key = %s", $year, $qn, $key ) ); // phpcs:ignore WordPress.DB
		if ( $have ) {
			$wpdb->update( $p, $row, array( 'id' => (int) $have ) );
		} else {
			$wpdb->insert( $p, array_merge( $row, array( 'pay_year' => $year, 'pay_quarter' => $qn, 'kind' => 'writer', 'party' => $party, 'party_key' => $key ) ) );
		}
		self::go( 'money', array( 'q' => $q, 'm_ok' => sprintf( /* translators: %s: person */ __( 'Saved the payout for %s.', 'songpress' ), $party ) ) );
	}

	public static function handle_expense_add() {
		self::guard( 'songpress_money_expense_add' );
		global $wpdb;
		$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- cleaned below.
		$on = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $in['spent_on'] ?? '' ) ) ? $in['spent_on'] : gmdate( 'Y-m-d' );
		$amt = round( (float) str_replace( array( '$', ',' ), '', (string) ( $in['amount'] ?? 0 ) ), 2 );
		if ( $amt <= 0 ) {
			self::go( 'money', array( 'm_err' => __( 'Enter an amount above zero.', 'songpress' ) ) );
		}
		$wpdb->insert( self::t( 'expenses' ), array(
			'spent_on'   => $on,
			'payee'      => sanitize_text_field( $in['payee'] ?? '' ),
			'category'   => sanitize_text_field( $in['category'] ?? '' ),
			'amount'     => $amt,
			'song_id'    => absint( $in['song_id'] ?? 0 ),
			'person'     => sanitize_text_field( $in['person'] ?? '' ),
			'notes'      => sanitize_textarea_field( $in['notes'] ?? '' ),
			'created_at' => current_time( 'mysql' ),
		) );
		self::go( 'money', array( 'm_ok' => __( 'Expense added.', 'songpress' ) ) );
	}

	public static function handle_expense_delete() {
		self::guard( 'songpress_money_expense_delete' );
		global $wpdb;
		$wpdb->delete( self::t( 'expenses' ), array( 'id' => absint( $_POST['expense_id'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked by guard().
		self::go( 'money', array( 'm_ok' => __( 'Expense removed.', 'songpress' ) ) );
	}

	/* ---------- screens ---------- */

	private static function form_open( $action, $class = '' ) {
		return '<form method="post" class="' . esc_attr( $class ) . '" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( $action, '_wpnonce', true, false ) . '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="sp_return" value="' . esc_attr( Songpress_Editor::list_url() ) . '">';
	}

	private static function notes() {
		$o = '';
		if ( ! empty( $_GET['m_ok'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<p class="spe-note ok">' . esc_html( sanitize_text_field( wp_unslash( $_GET['m_ok'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( ! empty( $_GET['m_err'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<p class="spe-note bad" role="alert">' . esc_html( sanitize_text_field( wp_unslash( $_GET['m_err'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		return $o;
	}

	private static function song_link( $id, $label ) {
		return $id ? '<a href="' . esc_url( Songpress_Editor::edit_url( (int) $id ) ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label );
	}

	private static function bars( array $items ) {
		$max = 0;
		foreach ( $items as $i ) {
			$max = max( $max, (float) $i[1] );
		}
		$o = '<ul class="spm-bars">';
		foreach ( $items as $i ) {
			$w  = $max > 0 ? round( 100 * (float) $i[1] / $max, 1 ) : 0;
			$o .= '<li><span class="spm-lab">' . esc_html( $i[0] ) . '</span><span class="spm-bar"><i style="width:' . esc_attr( $w ) . '%"></i></span><span class="spm-val">' . esc_html( $i[2] ) . '</span></li>';
		}
		return $o . '</ul>';
	}

	public static function render( $slug ) {
		if ( ! Songpress_Caps::can( 'money' ) ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'Only Managers can open this screen.', 'songpress' ) . '</p></div>';
		}
		return 'stats' === $slug ? self::render_stats() : self::render_money();
	}

	private static function render_money() {
		$qs  = self::quarters();
		$sel = isset( $_GET['q'] ) ? self::quarter( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $sel || ! in_array( $sel, $qs, true ) ) {
			$sel = $qs ? $qs[0] : '';
			foreach ( $qs as $q ) { // open on the newest quarter that has per-person earnings to pay out
				if ( self::owed( $q ) ) {
					$sel = $q;
					break;
				}
			}
		}
		$earned = self::earned_total();
		$spent  = self::expense_total();
		$paid   = self::paid_total();
		$o      = '<div class="songpress sp-app sp-editor sp-money"><div class="spe-top"><h2>' . esc_html__( 'Money', 'songpress' ) . '</h2></div>' . self::notes();
		if ( ! $qs ) {
			$o .= '<p class="spe-help">' . esc_html__( 'No earnings loaded yet. Load your old catalog file at the bottom of this page.', 'songpress' ) . '</p>';
		}
		$o .= '<ul class="songpress-stats spm-cards"><li>' . esc_html__( 'Earned, all time', 'songpress' ) . '<strong>' . esc_html( self::money( $earned ) ) . '</strong></li><li>' . esc_html__( 'Paid out', 'songpress' ) . '<strong>' . esc_html( self::money( $paid ) ) . '</strong></li><li>' . esc_html__( 'Expenses', 'songpress' ) . '<strong>' . esc_html( self::money( $spent ) ) . '</strong></li></ul>';

		// by quarter
		$grid = array();
		foreach ( self::by_quarter() as $r ) {
			$grid[ $r->quarter ][ $r->src ] = (float) $r->total;
		}
		if ( $grid ) {
			$o .= '<h3>' . esc_html__( 'By quarter', 'songpress' ) . '</h3><div class="spm-scroll"><table class="spm-table"><thead><tr><th>' . esc_html__( 'Quarter', 'songpress' ) . '</th>';
			foreach ( self::SOURCES as $label ) {
				$o .= '<th class="n">' . esc_html( $label ) . '</th>';
			}
			$o .= '<th class="n">' . esc_html__( 'Total', 'songpress' ) . '</th></tr></thead><tbody>';
			foreach ( $grid as $q => $row ) {
				$o .= '<tr><td><a href="' . esc_url( add_query_arg( array( 'sp_page' => 'money', 'q' => $q ), Songpress_Editor::list_url() ) ) . '">' . esc_html( $q ) . '</a></td>';
				foreach ( array_keys( self::SOURCES ) as $s ) {
					$o .= '<td class="n">' . ( isset( $row[ $s ] ) ? esc_html( self::money( $row[ $s ] ) ) : '' ) . '</td>';
				}
				$o .= '<td class="n"><b>' . esc_html( self::money( array_sum( $row ) ) ) . '</b></td></tr>';
			}
			$o .= '</tbody></table></div>';
		}

		// top songs
		$top = self::top_songs( 12 );
		if ( $top ) {
			$items = array();
			foreach ( $top as $r ) {
				$items[] = array( $r->serial . '  ' . $r->title, (float) $r->total, self::money( $r->total ) );
			}
			$o .= '<h3>' . esc_html__( 'Top earning songs', 'songpress' ) . '</h3>' . self::bars( $items );
		}

		// payouts
		if ( '' !== $sel ) {
			$year = (int) substr( $sel, 0, 4 );
			$qn   = (int) substr( $sel, -1 );
			$have = self::payments_for( $year, $qn );
			$o   .= '<h3>' . esc_html( sprintf( /* translators: %s: quarter such as 2026-Q2 */ __( 'Payouts for %s', 'songpress' ), $sel ) ) . '</h3><form method="get" class="spm-qpick"><input type="hidden" name="sp_page" value="money"><select name="q" onchange="this.form.submit()">';
			foreach ( $qs as $q ) {
				$o .= '<option value="' . esc_attr( $q ) . '"' . selected( $q, $sel, false ) . '>' . esc_html( $q ) . '</option>';
			}
			$o .= '</select></form><div class="spm-scroll"><table class="spm-table"><thead><tr><th>' . esc_html__( 'Person', 'songpress' ) . '</th><th class="n">' . esc_html__( 'Earned', 'songpress' ) . '</th><th>' . esc_html__( 'Pay', 'songpress' ) . '</th></tr></thead><tbody>';
			foreach ( self::owed( $sel ) as $r ) {
				$p  = $have[ 'writer|' . $r->person_key ] ?? null;
				$o .= '<tr><td>' . esc_html( $r->person ) . '</td><td class="n">' . esc_html( self::money( $r->total ) ) . '</td><td>' . self::form_open( 'songpress_money_pay', 'spm-pay' ) . '<input type="hidden" name="quarter" value="' . esc_attr( $sel ) . '"><input type="hidden" name="party" value="' . esc_attr( $r->person ) . '">';
				$o .= '<input class="spm-amt" name="amount" inputmode="decimal" value="' . esc_attr( $p ? number_format( (float) $p->amount, 2, '.', '' ) : number_format( (float) $r->total, 2, '.', '' ) ) . '" aria-label="' . esc_attr__( 'Amount paid', 'songpress' ) . '">';
				$o .= '<label class="spm-chk"><input type="checkbox" name="paid" value="1"' . checked( $p && (int) $p->paid, true, false ) . '> ' . esc_html__( 'Paid', 'songpress' ) . '</label><input type="date" name="paid_on" value="' . esc_attr( $p && $p->paid_on ? $p->paid_on : '' ) . '" aria-label="' . esc_attr__( 'Paid on', 'songpress' ) . '"><button class="spe-btn" type="submit">' . esc_html__( 'Save', 'songpress' ) . '</button></form></td></tr>';
			}
			$o .= '</tbody></table></div>';
		}

		// expenses
		$o .= '<h3>' . esc_html__( 'Expenses', 'songpress' ) . '</h3>' . self::form_open( 'songpress_money_expense_add', 'spm-exp' ) . '<div class="spe-grid">';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Date', 'songpress' ) . '</span><input type="date" name="spent_on" value="' . esc_attr( gmdate( 'Y-m-d' ) ) . '"></label>';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Paid to', 'songpress' ) . '</span><input name="payee"></label>';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Category', 'songpress' ) . '</span><input name="category" placeholder="' . esc_attr__( 'studio, mastering, art…', 'songpress' ) . '"></label>';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Amount', 'songpress' ) . '</span><input name="amount" inputmode="decimal" required></label></div>';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Notes', 'songpress' ) . '</span><input name="notes"></label><button class="spe-save" type="submit">' . esc_html__( 'Add expense', 'songpress' ) . '</button></form>';
		$ex = self::expenses( 100 );
		if ( $ex ) {
			$o .= '<div class="spm-scroll"><table class="spm-table"><tbody>';
			foreach ( $ex as $e ) {
				$o .= '<tr><td>' . esc_html( $e->spent_on ) . '</td><td>' . esc_html( $e->payee ) . '</td><td>' . esc_html( $e->category ) . '</td><td class="n">' . esc_html( self::money( $e->amount ) ) . '</td><td>' . self::form_open( 'songpress_money_expense_delete', 'spm-del' ) . '<input type="hidden" name="expense_id" value="' . (int) $e->id . '"><button class="spe-btn danger" type="submit">' . esc_html__( 'Remove', 'songpress' ) . '</button></form></td></tr>';
			}
			$o .= '</tbody></table></div>';
		}

		// import
		$o .= '<h3>' . esc_html__( 'Load money from the old catalog file', 'songpress' ) . '</h3><p class="spe-help">' . esc_html__( 'Choose catalog.sqlite. The work journal, song ratings and art projects come across too (entries already here are kept). Statements, per-person earnings, streams and store figures are replaced by what the file holds. Payouts and expenses you entered here are kept. Songs must already be imported.', 'songpress' ) . '</p>';
		$o .= str_replace( '<form ', '<form enctype="multipart/form-data" ', self::form_open( 'songpress_money_import', 'spm-imp' ) ) . '<input type="file" name="catalog" accept=".sqlite,.db,.sqlite3" required> <button class="spe-save" type="submit">' . esc_html__( 'Load money', 'songpress' ) . '</button></form></div>';
		return $o;
	}


	/** The chart for streams and income per day, week, month and quarter, with the stats import beside it. */
	private static function chart_html() {
		$data = self::chart_payload();
		$o    = '<div class="spc" id="spc"><div class="spc-bar">';
		$o   .= '<div class="spc-seg" data-k="source"><button type="button" data-v="statements" class="on">' . esc_html__( 'DistroKid statements', 'songpress' ) . '</button><button type="button" data-v="daily">' . esc_html__( 'Daily estimates', 'songpress' ) . '</button></div>';
		$o   .= '<div class="spc-seg" data-k="mode"><button type="button" data-v="streams" class="on">' . esc_html__( 'Streams', 'songpress' ) . '</button><button type="button" data-v="income">' . esc_html__( 'Income', 'songpress' ) . '</button></div>';
		$o   .= '<div class="spc-seg" data-k="grain"><button type="button" data-v="day">' . esc_html__( 'Day', 'songpress' ) . '</button><button type="button" data-v="week">' . esc_html__( 'Week', 'songpress' ) . '</button><button type="button" data-v="month" class="on">' . esc_html__( 'Month', 'songpress' ) . '</button><button type="button" data-v="quarter">' . esc_html__( 'Quarter', 'songpress' ) . '</button></div>';
		$o   .= '<label class="spc-all"><input type="checkbox" id="spc-all"> ' . esc_html__( 'Whole history', 'songpress' ) . '</label></div>';
		$o   .= '<p class="spe-help" id="spc-note"></p><div class="spc-toggles" id="spc-toggles"></div><div class="spc-plot" id="spc-plot"></div><div class="spc-read" id="spc-read" aria-live="polite"></div></div>';
		$o   .= '<script type="application/json" id="spc-data">' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';
		$o   .= '<script>' . self::chart_js() . '</script>';
		return $o;
	}

	private static function chart_js() {
		return <<<'JS'
(function(){
var root=document.getElementById('spc');if(!root)return;
var D={statements:[],daily:[]};try{D=JSON.parse(document.getElementById('spc-data').textContent)}catch(e){}
var S={source:D.statements.length||!D.daily.length?'statements':'daily',mode:'streams',grain:'month',all:false,off:{}};
if(S.source==='daily'){S.grain='day'}
var COL=['#f5a623','#4fb3ff','#62d68b','#e377c2','#b48cf2','#ffd166','#ff7b72','#4dd0c8','#9aa4b2','#c7a27c'];
var plot=document.getElementById('spc-plot'),tog=document.getElementById('spc-toggles'),note=document.getElementById('spc-note'),read=document.getElementById('spc-read');
var RECENT={day:60,week:52,month:36,quarter:20};
function pad(n){return (n<10?'0':'')+n}
function key(day,g){
 var y=+day.slice(0,4),m=+day.slice(5,7),d=+day.slice(8,10);
 if(g==='day')return day;
 if(g==='month')return day.slice(0,7);
 if(g==='quarter')return y+'-Q'+(Math.floor((m-1)/3)+1);
 var t=new Date(Date.UTC(y,m-1,d));var wd=(t.getUTCDay()+6)%7;t=new Date(t.getTime()-wd*864e5);
 return t.getUTCFullYear()+'-'+pad(t.getUTCMonth()+1)+'-'+pad(t.getUTCDate());
}
function rows(){
 var out=[];
 if(S.source==='statements'){D.statements.forEach(function(r){out.push([r[0]+'-01',r[1],S.mode==='income'?r[3]:r[2]])})}
 else{D.daily.forEach(function(r){out.push([r[0],r[1],r[2]])})}
 return out;
}
function fmt(v){if(S.mode==='income'){return '$'+v.toLocaleString(undefined,{minimumFractionDigits:v<100?2:0,maximumFractionDigits:v<100?2:0})}return Math.round(v).toLocaleString()}
function nice(max){if(max<=0)return 1;var p=Math.pow(10,Math.floor(Math.log10(max))),f=max/p;var n=f<=1?1:f<=2?2:f<=5?5:10;return n*p}
function svg(tag,a,inner){var s='<'+tag;for(var k in a)s+=' '+k+'="'+String(a[k]).replace(/"/g,'&quot;')+'"';return s+'>'+(inner||'')+'</'+tag+'>'}
function draw(){
 var grains=S.source==='daily'?['day','week','month','quarter']:['month','quarter'];
 if(grains.indexOf(S.grain)<0)S.grain=S.source==='daily'?'day':'month';
 var hasIncome=S.source==='statements';
 if(!hasIncome&&S.mode==='income')S.mode='streams';
 root.querySelectorAll('.spc-seg').forEach(function(seg){var k=seg.getAttribute('data-k');seg.querySelectorAll('button').forEach(function(b){var v=b.getAttribute('data-v');var ok=true;
  if(k==='grain')ok=grains.indexOf(v)>=0;if(k==='mode'&&v==='income')ok=hasIncome;b.hidden=!ok;b.classList.toggle('on',S[k]===v)})});
 var all=rows(),agg={},series={},order=[];
 all.forEach(function(r){var b=key(r[0],S.grain);agg[b]=agg[b]||{};agg[b][r[1]]=(agg[b][r[1]]||0)+r[2];series[r[1]]=(series[r[1]]||0)+r[2]});
 var names=Object.keys(series).sort(function(a,b){return series[b]-series[a]});
 var buckets=Object.keys(agg).sort();
 if(!buckets.length){plot.innerHTML='';tog.innerHTML='';read.textContent='';note.textContent=S.source==='daily'?'No daily stats yet. Import the DistroKid stats page below.':'No statements loaded yet. Load your catalog file on the Money page.';return}
 var lim=RECENT[S.grain];if(!S.all&&buckets.length>lim)buckets=buckets.slice(-lim);
 note.textContent=(S.source==='statements'?'Plays and sales reported on DistroKid statements, by month. ':'Daily stream estimates from the DistroKid stats page (no income is reported daily). ')+buckets.length+' '+S.grain+(buckets.length===1?'':'s')+', '+buckets[0]+' to '+buckets[buckets.length-1]+'.';
 tog.innerHTML='';
 names.forEach(function(n,i){var b=document.createElement('button');b.type='button';b.className='spc-chip'+(S.off[n]?' off':'');b.innerHTML='<i style="background:'+COL[i%COL.length]+'"></i>'+n.replace(/</g,'&lt;');b.onclick=function(){S.off[n]=!S.off[n];draw()};tog.appendChild(b)});
 var live=names.filter(function(n){return !S.off[n]});
 var tot=buckets.map(function(b){return live.reduce(function(s,n){return s+((agg[b]||{})[n]||0)},0)});
 var max=nice(Math.max.apply(null,tot.concat([0])));
 var W=900,H=300,L=58,R=8,T=10,B=34,iw=W-L-R,ih=H-T-B,bw=iw/buckets.length;
 var g='';
 for(var i=0;i<=4;i++){var v=max*i/4,y=T+ih-ih*i/4;g+=svg('line',{x1:L,x2:W-R,y1:y,y2:y,class:'spc-grid'})+svg('text',{x:L-6,y:y+4,'text-anchor':'end',class:'spc-ax'},fmt(v))}
 var every=Math.ceil(buckets.length/Math.max(4,Math.floor(iw/92)));
 buckets.forEach(function(b,bi){
  var x=L+bi*bw,y0=T+ih,part=agg[b]||{},label=b;
  live.forEach(function(n){var v=part[n]||0;if(!v)return;var h=ih*v/max;y0-=h;var ci=names.indexOf(n);g+=svg('rect',{x:x+bw*.12,y:y0,width:Math.max(1,bw*.76),height:h,fill:COL[ci%COL.length],'data-b':bi},'')});
  g+=svg('rect',{x:x,y:T,width:bw,height:ih,fill:'transparent',class:'spc-hit','data-b':bi});
  if(bi%every===0)g+=svg('text',{x:x+bw/2,y:H-12,'text-anchor':'middle',class:'spc-ax'},label.length>8&&S.grain==='day'?label.slice(5):label);
 });
 plot.innerHTML='<svg viewBox="0 0 '+W+' '+H+'" role="img" aria-label="'+S.mode+' by '+S.grain+'" preserveAspectRatio="xMidYMid meet">'+g+'</svg>';
 function show(bi){var b=buckets[bi],part=agg[b]||{};var t=live.reduce(function(s,n){return s+(part[n]||0)},0);
  read.innerHTML='<b>'+b+'</b> · '+fmt(t)+' '+(S.mode==='income'?'':'streams')+live.filter(function(n){return part[n]}).map(function(n){return ' · <span style="color:'+COL[names.indexOf(n)%COL.length]+'">'+n.replace(/</g,'&lt;')+' '+fmt(part[n])+'</span>'}).join('')}
 plot.querySelectorAll('.spc-hit').forEach(function(r){r.addEventListener('mouseenter',function(){show(+r.getAttribute('data-b'))});r.addEventListener('click',function(){show(+r.getAttribute('data-b'))})});
 show(buckets.length-1);
}
root.querySelectorAll('.spc-seg').forEach(function(seg){seg.addEventListener('click',function(e){var b=e.target.closest('button');if(!b)return;var k=seg.getAttribute('data-k');S[k]=b.getAttribute('data-v');if(k==='source'){S.off={};S.grain=S.source==='daily'?'day':'month'}draw()})});
document.getElementById('spc-all').addEventListener('change',function(e){S.all=e.target.checked;draw()});
draw();
})();
JS;
	}

	private static function render_stats() {
		$months = self::months();
		$o      = '<div class="songpress sp-app sp-editor sp-money"><div class="spe-top"><h2>' . esc_html__( 'Stats', 'songpress' ) . '</h2></div>' . self::notes();
		$o     .= self::chart_html();
		$o     .= '<h3>' . esc_html__( 'Import the DistroKid stats page', 'songpress' ) . '</h3><p class="spe-help">' . esc_html__( 'In DistroKid, open your Stats page and save it as an HTML file (Save Page As). Choose that file here. Days it covers are updated; days it does not cover are kept.', 'songpress' ) . '</p>';
		$o     .= str_replace( '<form ', '<form enctype="multipart/form-data" ', self::form_open( 'songpress_money_stats_import', 'spm-imp' ) ) . '<input type="file" name="stats" accept=".html,.htm,text/html" required> <button class="spe-save" type="submit">' . esc_html__( 'Import daily stats', 'songpress' ) . '</button></form>';
		if ( ! $months && ! self::daily( 1 ) ) {
			return $o . '</div>';
		}
		if ( $months ) {
			$items = array();
			foreach ( array_reverse( array_slice( $months, 0, 18 ) ) as $m ) {
				$items[] = array( $m->month, (float) $m->units, number_format( (float) $m->units ) . ' · ' . self::money( $m->earnings ) );
			}
			$o .= '<h3>' . esc_html__( 'Plays and sales by month', 'songpress' ) . '</h3>' . self::bars( $items );
		}
		$daily = self::daily( 30 );
		if ( $daily ) {
			$items = array();
			foreach ( array_reverse( $daily ) as $d ) {
				$items[] = array( substr( $d->day, 5 ), (float) $d->streams, number_format( (float) $d->streams ) );
			}
			$o .= '<h3>' . esc_html__( 'Streams per day, latest 30 days', 'songpress' ) . '</h3>' . self::bars( $items );
		}
		$sv = self::services();
		if ( $sv ) {
			$items = array();
			foreach ( $sv as $s ) {
				$items[] = array( $s->service, (float) $s->streams, number_format( (float) $s->streams ) );
			}
			$o .= '<h3>' . esc_html__( 'Streams by service', 'songpress' ) . '</h3>' . self::bars( $items );
		}
		$st = self::stores();
		if ( $st ) {
			$items = array();
			foreach ( $st as $s ) {
				$items[] = array( $s->store, (float) $s->earnings, self::money( $s->earnings ) . ' · ' . number_format( (float) $s->units ) );
			}
			$o .= '<h3>' . esc_html__( 'Income by store', 'songpress' ) . '</h3>' . self::bars( $items );
		}
		$tr = Songpress_Journal::top_rated( 10 );
		if ( $tr ) {
			$items = array();
			foreach ( $tr as $r ) {
				$items[] = array( $r->serial . '  ' . $r->title, (float) $r->avg_stars, number_format( (float) $r->avg_stars, 1 ) . ' ★ · ' . (int) $r->votes );
			}
			$o .= '<h3>' . esc_html__( 'Top rated songs', 'songpress' ) . '</h3>' . self::bars( $items );
		}
		$ts = self::top_streamed( 15 );
		if ( $ts ) {
			$o .= '<h3>' . esc_html__( 'Most played songs', 'songpress' ) . '</h3><div class="spm-scroll"><table class="spm-table"><thead><tr><th>' . esc_html__( 'Song', 'songpress' ) . '</th><th class="n">' . esc_html__( 'Plays', 'songpress' ) . '</th><th class="n">' . esc_html__( 'Income', 'songpress' ) . '</th></tr></thead><tbody>';
			foreach ( $ts as $r ) {
				$o .= '<tr><td>' . self::song_link( $r->song_id, $r->title ) . '</td><td class="n">' . esc_html( number_format( (float) $r->units ) ) . '</td><td class="n">' . esc_html( self::money( $r->earnings ) ) . '</td></tr>';
			}
			$o .= '</tbody></table></div>';
		}
		return $o . '</div>';
	}

	/** The writer's own earnings, shown on their Profile. Empty when nothing is recorded for them. */
	public static function my_earnings_html( $display_name ) {
		$key = self::person_key( $display_name );
		if ( '' === $key ) {
			return '';
		}
		$rows = self::my_rows( $key );
		if ( ! $rows ) {
			return '';
		}
		$by = array();
		$all = 0.0;
		foreach ( $rows as $r ) {
			$by[ $r->quarter ] = ( $by[ $r->quarter ] ?? 0 ) + (float) $r->total;
			$all              += (float) $r->total;
		}
		$o = '<h3>' . esc_html__( 'My earnings', 'songpress' ) . '</h3><ul class="songpress-stats"><li>' . esc_html__( 'All time', 'songpress' ) . '<strong>' . esc_html( self::money( $all ) ) . '</strong></li>';
		$latest = array_key_first( $by );
		$o .= '<li>' . esc_html( $latest ) . '<strong>' . esc_html( self::money( $by[ $latest ] ) ) . '</strong></li></ul>';
		$items = array();
		foreach ( array_slice( $by, 0, 8, true ) as $q => $v ) {
			$items[] = array( $q, $v, self::money( $v ) );
		}
		$o .= self::bars( array_reverse( $items ) );
		$songs = self::my_songs( $key, 8 );
		if ( $songs ) {
			$items = array();
			foreach ( $songs as $s ) {
				$items[] = array( $s->work_title, (float) $s->total, self::money( $s->total ) );
			}
			$o .= '<h3>' . esc_html__( 'My top songs', 'songpress' ) . '</h3>' . self::bars( $items );
		}
		return $o;
	}
}
