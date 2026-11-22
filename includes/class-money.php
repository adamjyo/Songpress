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
		foreach ( array( 'import', 'pay', 'expense_add', 'expense_delete' ) as $a ) {
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
		$out = array( 'earnings' => 0, 'person' => 0, 'payments' => 0, 'streams' => 0, 'stats' => 0, 'journal' => 0, 'ratings' => 0, 'error' => '' );
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
		return $out;
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
		self::go( 'money', array( 'm_ok' => sprintf( /* translators: 1: statement lines, 2: people lines, 3: payouts, 4: stream days, 5: store lines, 6: journal entries, 7: ratings */ __( 'Loaded %1$d earnings lines, %2$d per-person lines, %3$d new payouts, %4$d days of streams, %5$d store lines, %6$d journal entries and %7$d ratings.', 'songpress' ), $r['earnings'], $r['person'], $r['payments'], $r['streams'], $r['stats'], $r['journal'], $r['ratings'] ) ) );
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
		$o .= '<h3>' . esc_html__( 'Load money from the old catalog file', 'songpress' ) . '</h3><p class="spe-help">' . esc_html__( 'Choose catalog.sqlite. The work journal and song ratings come across too (entries already here are kept). Statements, per-person earnings, streams and store figures are replaced by what the file holds. Payouts and expenses you entered here are kept. Songs must already be imported.', 'songpress' ) . '</p>';
		$o .= str_replace( '<form ', '<form enctype="multipart/form-data" ', self::form_open( 'songpress_money_import', 'spm-imp' ) ) . '<input type="file" name="catalog" accept=".sqlite,.db,.sqlite3" required> <button class="spe-save" type="submit">' . esc_html__( 'Load money', 'songpress' ) . '</button></form></div>';
		return $o;
	}

	private static function render_stats() {
		$months = self::months();
		$o      = '<div class="songpress sp-app sp-editor sp-money"><div class="spe-top"><h2>' . esc_html__( 'Stats', 'songpress' ) . '</h2></div>';
		if ( ! $months && ! self::daily( 1 ) ) {
			return $o . '<p class="spe-help">' . esc_html__( 'No stats loaded yet. Open Money and load your old catalog file.', 'songpress' ) . '</p></div>';
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
