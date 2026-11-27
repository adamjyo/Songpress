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

	/** Every income stream a person can have: the statement sources, plus what a band they are paid for earns. */
	const STREAMS = array(
		'distrokid' => 'DistroKid',
		'mlc'       => 'MLC',
		'ascap'     => 'ASCAP',
		'ccli'      => 'CCLI',
		'band'      => 'Band',
	);

	const RULES_OPT = 'songpress_earn_rules';

	/** The numbers behind earnings, set under Settings > Earnings: performer share when a song sets none (percent), and whether bands pay out less their performers. */
	public static function rules() {
		$o = (array) get_option( self::RULES_OPT, array() );
		return array(
			'performer_default'    => isset( $o['performer_default'] ) ? max( 0.0, min( 100.0, (float) $o['performer_default'] ) ) : 25.0,
			'band_less_performers' => ! isset( $o['band_less_performers'] ) || ! empty( $o['band_less_performers'] ),
		);
	}

	const SRC_CODES = array( 'D' => 'distrokid', 'M' => 'mlc', 'A' => 'ascap', 'C' => 'ccli' );

	public static function init() {
		add_action( 'wp_ajax_songpress_money_pay_csv', array( __CLASS__, 'handle_pay_csv' ) ); // admin-post.php is blocked on some hosts
		foreach ( array( 'import', 'pay', 'payment', 'payment_update', 'payment_delete', 'expense_add', 'expense_delete', 'band_payee', 'stats_import' ) as $a ) {
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
	public static function batch( $table, array $cols, array $rows, $verb = 'REPLACE' ) {
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
	public static function lookups() {
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
		$out = array( 'earnings' => 0, 'person' => 0, 'payments' => 0, 'streams' => 0, 'stats' => 0, 'journal' => 0, 'ratings' => 0, 'art' => 0, 'agreements' => 0, 'registrations' => 0, 'sessions' => 0, 'error' => '' );
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
			$out['payments'] += self::import_payments(
				$has( 'paid_entry' ) ? $pdo->query( 'SELECT y, q, party, kind, amount, notes, updated_at FROM paid_entry' )->fetchAll( PDO::FETCH_ASSOC ) : array(),
				$has( 'pay_board' ) ? $pdo->query( 'SELECT y, q, kind, party, paid, paid_on FROM pay_board' )->fetchAll( PDO::FETCH_ASSOC ) : array()
			);
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
		$out['agreements'] = Songpress_Agreements::import_pdo( $pdo );
		$out['registrations'] = Songpress_Registrations::import_pdo( $pdo );
		$out['sessions'] = Songpress_Sessions::import_pdo( $pdo );
		return $out;
	}


	/**
	 * Read the stats page saved from DistroKid. The page carries its numbers as JSON in a script tag
	 * (id="combinedStatsData"): a list of services and a daily series. Returns rows of day, service, streams,
	 * or a string describing what is wrong with the file. Pure.
	 */
	/** Names for DistroKid's service keys, used when a saved page does not list them under "dsps". */
	const SERVICE_NAMES = array( 'spotify' => 'Spotify', 'apple' => 'Apple Music', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'amazon' => 'Amazon Music', 'tidal' => 'Tidal', 'deezer' => 'Deezer', 'pandora' => 'Pandora', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'snapchat' => 'Snapchat' );

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
				$rows[] = array( (string) $point['category'], mb_substr( $labels[ $key ] ?? ( self::SERVICE_NAMES[ strtolower( (string) $key ) ] ?? ucfirst( (string) $key ) ), 0, 48 ), (int) $value );
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

	/**
	 * Bring the old catalog's payment lines across: paid_entry (money actually paid) and pay_board (paid flags). Safe to run again.
	 * Returns how many new payment rows were made.
	 */
	public static function import_payments( array $entries, array $boards ) {
		global $wpdb;
		$count = 0;
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
		if ( $entries ) {
			// Every paid_entry line is money that was actually paid out, so it comes across as paid (a zero line has nothing to count and waits for the pay board).
			foreach ( $entries as $r ) {
				if ( 5 === (int) $r['q'] ) { // the old "Q4 correction" line (a plain amount, often negative) belongs to that year's Q4
					$r['q']     = 4;
					$r['notes'] = trim( 'Correction ' . $r['notes'] );
				}
				$amt  = (float) $r['amount'];
				$on   = 0.0 !== $amt ? substr( (string) $r['updated_at'], 0, 10 ) : '';
				$kind = $r['kind'] ?: 'writer';
				$count += $put( (int) $r['y'], (int) $r['q'], $kind, $r['party'], $amt, 0.0 !== $amt, $on, $r['notes'] );
				if ( 0.0 !== $amt ) {
					// an earlier load left these as unpaid, untouched: mark them paid now (an amount changed here is left alone)
					$wpdb->query( $wpdb->prepare( "UPDATE {$p} SET paid = 1, paid_on = %s WHERE pay_year = %d AND pay_quarter = %d AND kind = %s AND party_key = %s AND paid = 0 AND ABS(amount - %d / 100) < 0.005", $on ?: null, (int) $r['y'], (int) $r['q'], $kind, self::person_key( $r['party'] ), (int) round( $amt * 100 ) ) ); // phpcs:ignore WordPress.DB
				}
			}
		}
		if ( $boards ) {
			foreach ( $boards as $r ) {
				$key = self::person_key( $r['party'] );
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$p} WHERE pay_year = %d AND pay_quarter = %d AND kind = %s AND party_key = %s", (int) $r['y'], (int) $r['q'], $r['kind'] ?: 'writer', $key ) ); // phpcs:ignore WordPress.DB
				if ( $row ) {
					if ( (int) $r['paid'] ) {
						// the old board only flagged "paid"; when no amount was recorded, the amount is what they earned that quarter
						$pe = self::t( 'person_earnings' );
						$wpdb->query( $wpdb->prepare( "UPDATE {$p} SET amount = COALESCE((SELECT SUM(amount) FROM {$pe} WHERE person_key = %s AND quarter = %s), 0) WHERE id = %d AND paid = 0 AND amount = 0", $key, (int) $r['y'] . '-Q' . (int) $r['q'], (int) $row->id ) ); // phpcs:ignore WordPress.DB
						$wpdb->query( $wpdb->prepare( "UPDATE {$p} SET paid = 1, paid_on = %s WHERE id = %d AND paid = 0", substr( (string) $r['paid_on'], 0, 10 ), (int) $row->id ) ); // phpcs:ignore WordPress.DB
						$wpdb->query( $wpdb->prepare( "UPDATE {$p} SET paid_on = %s WHERE id = %d AND paid = 1 AND paid_on IS NULL", substr( (string) $r['paid_on'], 0, 10 ), (int) $row->id ) ); // phpcs:ignore WordPress.DB
					}
				} else {
					$count += $put( (int) $r['y'], (int) $r['q'], $r['kind'] ?: 'writer', $r['party'], 0, (int) $r['paid'], (string) $r['paid_on'], '' );
				}
			}
		}
		return $count;
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
		$t    = self::t( 'person_earnings' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT quarter, src, SUM(amount) AS total FROM {$t} WHERE person_key = %s GROUP BY quarter, src ORDER BY quarter DESC", $key ) ); // phpcs:ignore WordPress.DB
		foreach ( self::band_rows( $key ) as $b ) {
			$rows[] = (object) array( 'quarter' => $b->quarter, 'src' => 'band', 'total' => $b->total );
		}
		usort( $rows, function ( $a, $b ) {
			return strcmp( $b->quarter, $a->quarter );
		} );
		return $rows;
	}

	/** The part of a song's DistroKid money that goes to its performers (their shares, 25% each when none is set). Bands keep the rest. */
	private static $cuts = array();

	public static function performer_cut( $song_id ) {
		$cache = &self::$cuts;
		$r  = self::rules();
		$id = (int) $song_id . '|' . $r['performer_default'] . '|' . (int) $r['band_less_performers'];
		if ( isset( $cache[ $id ] ) ) {
			return $cache[ $id ];
		}
		$sum = 0.0;
		$def = $r['performer_default'] / 100;
		if ( ! $r['band_less_performers'] ) {
			return $cache[ $id ] = 0.0;
		}
		foreach ( Songpress_Songs::credits( (int) $song_id ) as $c ) {
			if ( 'performer' === $c->role && '' !== trim( (string) $c->name ) ) {
				$sum += null === $c->share || '' === $c->share ? $def : max( 0.0, (float) $c->share / 100 );
			}
		}
		return $cache[ $id ] = min( 1.0, $sum );
	}

	/**
	 * Every band's DistroKid money by quarter, with what is left for the band after its performers' shares.
	 * Returns key => array( name, payee, rows => quarter => array( gross, performers, net ) ). Bands are matched by name or alias.
	 */
	public static function band_detail() {
		global $wpdb;
		self::$cuts = array();
		$pt   = self::t( 'profiles' );
		$map  = array();
		$info = array();
		foreach ( (array) $wpdb->get_results( "SELECT name, name_key, aliases, payee FROM {$pt} WHERE kind = 'band'" ) as $b ) { // phpcs:ignore WordPress.DB
			$info[ $b->name_key ] = array( 'name' => $b->name, 'payee' => trim( (string) $b->payee ), 'rows' => array() );
			$map[ $b->name_key ]  = $b->name_key;
			foreach ( preg_split( '/[,\n]+/', (string) $b->aliases ) as $al ) {
				$ak = Songpress_Profiles::key( $al );
				if ( '' !== $ak ) {
					$map[ $ak ] = $b->name_key;
				}
			}
		}
		$e = self::t( 'earnings' );
		$s = self::t( 'songs' );
		foreach ( (array) $wpdb->get_results( "SELECT e.song_id AS song_id, s.artist AS artist, e.quarter AS quarter, SUM(e.amount) AS total FROM {$e} e JOIN {$s} s ON s.id = e.song_id WHERE e.src = 'distrokid' AND s.artist <> '' GROUP BY e.song_id, s.artist, e.quarter" ) as $r ) { // phpcs:ignore WordPress.DB
			$ak = Songpress_Profiles::key( $r->artist );
			$k  = $map[ $ak ] ?? $ak;
			if ( ! isset( $info[ $k ] ) ) {
				$info[ $k ] = array( 'name' => trim( $r->artist ), 'payee' => '', 'rows' => array() );
			}
			$gross = (float) $r->total;
			$perf  = round( $gross * self::performer_cut( $r->song_id ), 6 );
			$cell  = $info[ $k ]['rows'][ $r->quarter ] ?? array( 'gross' => 0.0, 'performers' => 0.0, 'net' => 0.0 );
			$cell['gross']      += $gross;
			$cell['performers'] += $perf;
			$cell['net']        += $gross - $perf;
			$info[ $k ]['rows'][ $r->quarter ] = $cell;
		}
		foreach ( $info as $k => $v ) {
			if ( ! $v['rows'] ) {
				unset( $info[ $k ] );
			} else {
				krsort( $info[ $k ]['rows'] );
			}
		}
		uasort( $info, function ( $x, $y ) {
			return strcmp( strtolower( $x['name'] ), strtolower( $y['name'] ) );
		} );
		return $info;
	}

	/**
	 * What the bands a person is paid for have earned after their performers' shares: rows of person_key, person, quarter, src ('band') and total.
	 * Limited to one person when $only is a person key.
	 */
	public static function band_rows( $only = '' ) {
		$out = array();
		foreach ( self::band_detail() as $b ) {
			$k = '' === $b['payee'] ? '' : self::person_key( $b['payee'] );
			if ( '' === $k || ( '' !== $only && $k !== $only ) ) {
				continue;
			}
			foreach ( $b['rows'] as $q => $c ) {
				$id = $k . '|' . $q;
				if ( ! isset( $out[ $id ] ) ) {
					$out[ $id ] = (object) array( 'person_key' => $k, 'person' => $b['payee'], 'quarter' => $q, 'src' => 'band', 'total' => 0.0 );
				}
				$out[ $id ]->total += $c['net'];
			}
		}
		return array_values( $out );
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
			self::go( 'uploads', array( 'type' => 'catalog', 'm_err' => __( 'No file was received.', 'songpress' ) ) );
		}
		if ( ! class_exists( 'PDO' ) || ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) {
			self::go( 'uploads', array( 'type' => 'catalog', 'm_err' => __( 'This server cannot read SQLite files (pdo_sqlite is off).', 'songpress' ) ) );
		}
		$r = self::import_sqlite( $_FILES['catalog']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- an uploaded temp path, opened read-only by PDO.
		if ( '' !== $r['error'] ) {
			self::go( 'uploads', array( 'type' => 'catalog', 'm_err' => $r['error'] ) );
		}
		self::go( 'uploads', array( 'type' => 'catalog', 'm_ok' => sprintf( /* translators: 1: statement lines, 2: people lines, 3: payouts, 4: stream days, 5: store lines, 6: journal entries, 7: ratings, 8: art projects, 9: agreements, 10: registrations */ __( 'Loaded %1$d earnings lines, %2$d per-person lines, %3$d new payouts, %4$d days of streams, %5$d store lines, %6$d journal entries, %7$d ratings, %8$d art projects, %9$d agreements and %10$d registrations in progress.', 'songpress' ), $r['earnings'], $r['person'], $r['payments'], $r['streams'], $r['stats'], $r['journal'], $r['ratings'], $r['art'], $r['agreements'], $r['registrations'] ) . ' ' . sprintf( /* translators: %d: sessions */ __( '%d writing sessions came across.', 'songpress' ), $r['sessions'] ) ) );
	}


	public static function handle_stats_import() {
		self::guard( 'songpress_money_stats_import' );
		if ( empty( $_FILES['stats']['tmp_name'] ) || ! is_uploaded_file( $_FILES['stats']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::go( 'uploads', array( 'type' => 'dk_stats', 'm_err' => __( 'No file was received.', 'songpress' ) ) );
		}
		$html = file_get_contents( $_FILES['stats']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.WP.AlternativeFunctions -- an uploaded temp file.
		$r    = self::import_stats_html( (string) $html );
		if ( '' !== $r['error'] ) {
			self::go( 'uploads', array( 'type' => 'dk_stats', 'm_err' => $r['error'] ) );
		}
		self::go( 'uploads', array( 'type' => 'dk_stats', 'm_ok' => sprintf( /* translators: 1: days, 2: day-and-service lines */ __( 'Saved %1$d days of streams (%2$d lines).', 'songpress' ), $r['days'], $r['rows'] ) ) );
	}

	/**
	 * Payments from a spreadsheet: a CSV with person, quarter (2025 Q3), amount, paid_on (optional), notes (optional).
	 * A payment that is already there is left alone; a missing one is added as paid; one that is there with a different amount is reported, not changed.
	 */
	public static function handle_pay_csv() {
		self::guard( 'songpress_money_pay_csv' );
		global $wpdb;
		if ( empty( $_FILES['paycsv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['paycsv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::go( 'uploads', array( 'type' => 'payments_csv', 'm_err' => __( 'No file was received.', 'songpress' ) ) );
		}
		$raw = (string) file_get_contents( $_FILES['paycsv']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.WP.AlternativeFunctions
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		$ln  = preg_split( '/\r\n|\r|\n/', $raw );
		$hd  = array_map( function ( $h ) {
			return strtolower( trim( $h ) );
		}, str_getcsv( (string) array_shift( $ln ) ) );
		$ix  = array_flip( $hd );
		if ( ! isset( $ix['person'], $ix['quarter'], $ix['amount'] ) ) {
			self::go( 'uploads', array( 'type' => 'payments_csv', 'm_err' => __( 'The first row must have the columns person, quarter and amount.', 'songpress' ) ) );
		}
		$p     = self::t( 'payments' );
		$added = 0;
		$same  = 0;
		$diff  = array();
		$skip  = 0;
		foreach ( $ln as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$c      = str_getcsv( $line );
			$party  = sanitize_text_field( $c[ $ix['person'] ] ?? '' );
			$q      = self::quarter( $c[ $ix['quarter'] ] ?? '' );
			$amount = round( (float) str_replace( array( '$', ',' ), '', (string) ( $c[ $ix['amount'] ] ?? 0 ) ), 2 );
			$key    = self::person_key( $party );
			if ( '' === $key || '' === $q || $amount <= 0 ) {
				$skip++;
				continue;
			}
			$on   = isset( $ix['paid_on'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $c[ $ix['paid_on'] ] ?? '' ) ) ? $c[ $ix['paid_on'] ] : null;
			$note = isset( $ix['notes'] ) ? sanitize_text_field( $c[ $ix['notes'] ] ?? '' ) : '';
			$add  = isset( $ix['add'] ) && in_array( strtolower( trim( (string) ( $c[ $ix['add'] ] ?? '' ) ) ), array( '1', 'yes', 'y', 'true' ), true );
			$y    = (int) substr( $q, 0, 4 );
			$qn   = (int) substr( $q, -1 );
			$have = $wpdb->get_row( $wpdb->prepare( "SELECT id, amount, paid, notes FROM {$p} WHERE pay_year = %d AND pay_quarter = %d AND kind = 'writer' AND party_key = %s", $y, $qn, $key ) ); // phpcs:ignore WordPress.DB
			if ( ! $have ) {
				$wpdb->insert( $p, array( 'pay_year' => $y, 'pay_quarter' => $qn, 'kind' => 'writer', 'party' => $party, 'party_key' => $key, 'amount' => $amount, 'paid' => 1, 'paid_on' => $on, 'notes' => $note, 'updated_at' => current_time( 'mysql' ) ) );
				$added++;
			} elseif ( '' !== $note && false !== strpos( (string) $have->notes, $note ) ) { // this very line was added before
				$same++;
			} elseif ( ! empty( $add ) ) { // an "add" line joins what is already paid that quarter, with its own note
				$wpdb->update( $p, array( 'amount' => round( (float) $have->amount + $amount, 2 ), 'paid' => 1, 'paid_on' => $on ?: null, 'notes' => ( '' !== trim( (string) $have->notes ) ? trim( (string) $have->notes ) . "\n" : '' ) . $note, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $have->id ) );
				$added++;
			} elseif ( abs( (float) $have->amount - $amount ) < 0.005 && (int) $have->paid ) {
				$same++;
			} elseif ( ! (int) $have->paid && abs( (float) $have->amount ) < 0.005 ) { // an empty, unpaid line: fill it in
				$wpdb->update( $p, array( 'amount' => $amount, 'paid' => 1, 'paid_on' => $on, 'notes' => $note, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $have->id ) );
				$added++;
			} else {
				$diff[] = $party . ' ' . $q . ': system ' . self::money( $have->amount ) . ( (int) $have->paid ? '' : ' (unpaid)' ) . ', sheet ' . self::money( $amount );
			}
		}
		Songpress_Audit::log( 'money', 'Imported payments from a spreadsheet', '', array( 'added' => $added, 'already there' => $same, 'different' => count( $diff ) ) );
		$msg = sprintf( /* translators: 1: added 2: already there 3: different */ __( 'Added %1$d payments. %2$d were already there.', 'songpress' ), $added, $same );
		if ( $diff ) {
			$msg .= ' ' . sprintf( /* translators: %d: count */ __( '%d differ from the system and were not changed:', 'songpress' ), count( $diff ) ) . ' ' . implode( '; ', array_slice( $diff, 0, 12 ) ) . ( count( $diff ) > 12 ? '…' : '' );
		}
		if ( $skip ) {
			$msg .= ' ' . sprintf( /* translators: %d: count */ __( '%d lines were skipped (no matching person, quarter or amount).', 'songpress' ), $skip );
		}
		self::go( 'uploads', array( 'type' => 'payments_csv', 'm_ok' => $msg ) );
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

	/** Income per person for every quarter, summed over all streams (or split by stream when asked). Rows: person, quarter, src, total. */
	public static function person_grid() {
		global $wpdb;
		$t = self::t( 'person_earnings' );
		$rows = (array) $wpdb->get_results( "SELECT person_key, MIN(person) AS person, quarter, src, SUM(amount) AS total FROM {$t} GROUP BY person_key, quarter, src" ); // phpcs:ignore WordPress.DB
		return array_merge( $rows, self::band_rows() );
	}

	/** What each person has been paid so far, by person key; and the payments themselves. */
	public static function paid_by_person() {
		global $wpdb;
		$t   = self::t( 'payments' );
		$out = array();
		foreach ( $wpdb->get_results( "SELECT party_key, SUM(amount) AS total FROM {$t} WHERE paid = 1 AND kind = 'writer' GROUP BY party_key" ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ $r->party_key ] = (float) $r->total;
		}
		return $out;
	}

	/** What has been paid to each person toward each quarter: person key => quarter => amount. */
	public static function paid_cells() {
		global $wpdb;
		$t   = self::t( 'payments' );
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT party_key, pay_year, pay_quarter, SUM(amount) AS total FROM {$t} WHERE paid = 1 AND kind = 'writer' GROUP BY party_key, pay_year, pay_quarter" ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ $r->party_key ][ $r->pay_year . '-Q' . $r->pay_quarter ] = (float) $r->total;
		}
		return $out;
	}

	public static function payments_of( $key ) {
		global $wpdb;
		$t = self::t( 'payments' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE paid = 1 AND kind = 'writer' AND party_key = %s ORDER BY pay_year DESC, pay_quarter DESC", $key ) ); // phpcs:ignore WordPress.DB
	}

	/** Money that went out, by quarter: payouts (paid payments, counted toward the quarter chosen) and expenses (by the date spent). quarter => array( 'pay' => x, 'exp' => y ). */
	public static function outflow_by_quarter() {
		global $wpdb;
		$out = array();
		$pt  = self::t( 'payments' );
		foreach ( (array) $wpdb->get_results( "SELECT pay_year, pay_quarter, SUM(amount) AS total FROM {$pt} WHERE paid = 1 GROUP BY pay_year, pay_quarter" ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ (int) $r->pay_year . '-Q' . (int) $r->pay_quarter ]['pay'] = (float) $r->total;
		}
		$et = self::t( 'expenses' );
		foreach ( (array) $wpdb->get_results( "SELECT spent_on, amount FROM {$et}" ) as $r ) { // phpcs:ignore WordPress.DB
			if ( preg_match( '/^(\d{4})-(\d{2})/', (string) $r->spent_on, $m ) ) {
				$q = (int) $m[1] . '-Q' . (int) ceil( (int) $m[2] / 3 );
				$out[ $q ]['exp'] = ( $out[ $q ]['exp'] ?? 0 ) + (float) $r->amount;
			}
		}
		return $out;
	}

	/** Stacked columns, one per quarter, split by income stream. Plain HTML and CSS, with a table view under it. */
	private static function quarter_chart( array $grid, array $cols, array $outq = array() ) {
		$streams = array();
		foreach ( self::SOURCES as $sk => $label ) {
			foreach ( $grid as $bys ) {
				if ( ! empty( $bys[ $sk ] ) ) {
					$streams[ $sk ] = $label;
					break;
				}
			}
		}
		$max = 0;
		$mout = 0;
		$hasout = false;
		foreach ( $cols as $q ) {
			$max  = max( $max, array_sum( $grid[ $q ] ?? array() ) );
			$out  = ( $outq[ $q ]['pay'] ?? 0 ) + ( $outq[ $q ]['exp'] ?? 0 );
			$mout = max( $mout, $out );
			if ( $out > 0 ) {
				$hasout = true;
			}
		}
		if ( $max <= 0 && $mout <= 0 ) {
			return '';
		}
		$outs = array(
			'pay' => array( 's5', __( 'Payouts', 'songpress' ) ),
			'exp' => array( 's6', __( 'Expenses', 'songpress' ) ),
		);
		$big  = max( $max, $mout );
		$pw   = pow( 10, floor( log10( $big ) ) );
		$f    = $big / $pw;
		$step = ( $f <= 1 ? 0.25 : ( $f <= 2 ? 0.5 : ( $f <= 5 ? 1 : 2 ) ) ) * $pw;
		$top  = ceil( $max / $step ) * $step;
		$bot  = $mout > 0 ? ceil( ( $mout * 1.15 ) / $step ) * $step : 0; // depth of the part under the zero line, with room for its label
		$rng  = max( $top + $bot, $step );
		$zero = 100 * $bot / $rng; // zero line, % up from the bottom
		$pc   = function ( $v ) use ( $rng ) {
			return round( 100 * $v / $rng, 2 );
		};
		$fmt  = function ( $v ) {
			$n = abs( $v );
			$t = $n >= 10000 ? '$' . round( $n / 1000 ) . 'K' : ( $n >= 1000 && 0 === (int) $n % 1000 ? '$' . ( (int) $n / 1000 ) . 'K' : '$' . number_format( $n, $n < 100 && $n != floor( $n ) ? 2 : 0 ) );
			return $v < 0 ? '−' . $t : $t;
		};
		$o  = '<section class="spm-block spm-chart" role="group" aria-label="' . esc_attr__( 'Money in and out by quarter', 'songpress' ) . '"><ul class="spm-legend">';
		$i  = 0;
		foreach ( $streams as $sk => $label ) {
			$o .= '<li><i class="spm-sw s' . ( ++$i ) . '"></i>' . esc_html( $label ) . '</li>';
		}
		if ( $hasout ) {
			$o .= '<li><i class="spm-sw s0"></i>' . esc_html__( 'Income that went out', 'songpress' ) . '</li>';
			foreach ( $outs as $ok => $od ) {
				$o .= '<li><i class="spm-sw ' . $od[0] . '"></i>' . esc_html( $od[1] ) . ' (' . esc_html__( 'below zero', 'songpress' ) . ')</li>';
			}
		}
		$o .= '</ul><div class="spm-plot"><div class="spm-yaxis">';
		for ( $t = -$bot; $t <= $top + 0.001; $t += $step ) {
			$o .= '<span style="bottom:' . $pc( $t + $bot ) . '%">' . esc_html( $fmt( $t ) ) . '</span>';
		}
		$o .= '</div><div class="spm-cols">';
		for ( $t = -$bot; $t <= $top + 0.001; $t += $step ) {
			$o .= '<i class="spm-grid' . ( abs( $t ) < 0.001 ? ' spm-zero' : '' ) . '" style="bottom:' . $pc( $t + $bot ) . '%"></i>';
		}
		foreach ( $cols as $q ) {
			$tot  = array_sum( $grid[ $q ] ?? array() );
			$pay  = $outq[ $q ]['pay'] ?? 0;
			$exp  = $outq[ $q ]['exp'] ?? 0;
			$out  = $pay + $exp;
			$net  = $tot - $out;
			$keep = max( 0, $net ); // the coloured part: income that was kept
			$gone = $tot - $keep;  // the grey part: income that went out
			$o   .= '<div class="spm-col" data-q="' . esc_attr( $q ) . '">';
			if ( $tot > 0 ) {
				$o .= '<div class="spm-stack spm-up" style="bottom:' . $zero . '%;height:' . $pc( $tot ) . '%"><b class="spm-cap">' . esc_html( $fmt( $tot ) ) . '</b>';
				$i = 0;
				foreach ( $streams as $sk => $label ) {
					++$i;
					$v = $grid[ $q ][ $sk ] ?? 0;
					if ( $v > 0 && $keep > 0 ) {
						$share = $v * $keep / $tot;
						$o    .= '<span class="spm-seg s' . $i . '" style="flex:' . round( $share, 2 ) . ' 1 0" data-tip="' . esc_attr( $q . ' · ' . $label . ': ' . self::money( $v ) . ' ' . sprintf( __( '(%s kept)', 'songpress' ), self::money( $share ) ) ) . '"></span>';
					}
				}
				if ( $gone > 0.004 ) {
					$o .= '<span class="spm-seg s0" style="flex:' . round( $gone, 2 ) . ' 1 0" data-tip="' . esc_attr( $q . ' · ' . sprintf( __( 'Income %1$s, %2$s of it went out', 'songpress' ), self::money( $tot ), self::money( min( $gone, $out ) ) ) ) . '"></span>';
				}
				$o .= '</div>';
			}
			if ( $out > 0 ) {
				$o .= '<div class="spm-stack spm-dn" style="top:' . round( 100 - $zero, 2 ) . '%;height:' . $pc( $out ) . '%">';
				foreach ( $outs as $ok => $od ) {
					$v = $outq[ $q ][ $ok ] ?? 0;
					if ( $v > 0 ) {
						$o .= '<span class="spm-seg ' . $od[0] . '" style="flex:' . round( $v, 2 ) . ' 1 0" data-tip="' . esc_attr( $q . ' · ' . $od[1] . ': ' . self::money( $v ) ) . '"></span>';
					}
				}
				$o .= '<b class="spm-cap spm-capn ' . ( $net < 0 ? 'spm-loss' : '' ) . '">' . esc_html( ( $net < 0 ? __( 'Net ', 'songpress' ) : '' ) . ( $net < 0 ? $fmt( $net ) : '' ) ) . '</b></div>';
			}
			$o .= '<em>' . esc_html( $q ) . '</em></div>';
		}
		$o .= '</div></div><details class="spm-tv"><summary>' . esc_html__( 'Table view', 'songpress' ) . '</summary><div class="spm-scroll"><table class="spm-table spm-income"><thead><tr><th></th>';
		foreach ( $cols as $q ) {
			$o .= '<th class="n">' . esc_html( $q ) . '</th>';
		}
		$o .= '<th class="n">' . esc_html__( 'All time', 'songpress' ) . '</th></tr></thead><tbody>';
		foreach ( $streams as $sk => $label ) {
			$at = 0;
			foreach ( $grid as $bys ) {
				$at += $bys[ $sk ] ?? 0;
			}
			$o .= '<tr class="spm-sub"><td>' . esc_html( $label ) . '</td>';
			foreach ( $cols as $q ) {
				$v  = $grid[ $q ][ $sk ] ?? 0;
				$o .= '<td class="n">' . ( $v ? esc_html( self::money( $v ) ) : '' ) . '</td>';
			}
			$o .= '<td class="n">' . esc_html( self::money( $at ) ) . '</td></tr>';
		}
		$o .= '<tr class="spm-tot"><td>' . esc_html__( 'Total income', 'songpress' ) . '</td>';
		foreach ( $cols as $q ) {
			$o .= '<td class="n">' . esc_html( self::money( array_sum( $grid[ $q ] ?? array() ) ) ) . '</td>';
		}
		$o .= '<td class="n"><b>' . esc_html( self::money( self::earned_total() ) ) . '</b></td></tr>';
		foreach ( $outs as $ok => $od ) {
			$at = 0;
			foreach ( $outq as $v ) {
				$at += $v[ $ok ] ?? 0;
			}
			$o .= '<tr class="spm-sub"><td>' . esc_html( $od[1] ) . '</td>';
			foreach ( $cols as $q ) {
				$v  = $outq[ $q ][ $ok ] ?? 0;
				$o .= '<td class="n">' . ( $v ? esc_html( self::money( $v ) ) : '' ) . '</td>';
			}
			$o .= '<td class="n">' . esc_html( self::money( $at ) ) . '</td></tr>';
		}
		$o .= '<tr class="spm-tot"><td>' . esc_html__( 'Net (income − out)', 'songpress' ) . '</td>';
		$allnet = self::earned_total();
		foreach ( $outq as $v ) {
			$allnet -= ( $v['pay'] ?? 0 ) + ( $v['exp'] ?? 0 );
		}
		foreach ( $cols as $q ) {
			$o .= '<td class="n">' . esc_html( self::money( array_sum( $grid[ $q ] ?? array() ) - ( $outq[ $q ]['pay'] ?? 0 ) - ( $outq[ $q ]['exp'] ?? 0 ) ) ) . '</td>';
		}
		$o .= '<td class="n"><b>' . esc_html( self::money( $allnet ) ) . '</b></td></tr></tbody></table></div></details><div class="spm-tip" hidden></div></section>';
		$o .= '<script>(function(){var c=document.querySelector(".spm-chart");if(!c)return;var t=c.querySelector(".spm-tip");c.addEventListener("mousemove",function(e){var s=e.target.closest("[data-tip]");if(!s){t.hidden=true;return}t.textContent=s.getAttribute("data-tip");t.hidden=false;var r=c.getBoundingClientRect();t.style.left=Math.min(e.clientX-r.left+12,r.width-t.offsetWidth-8)+"px";t.style.top=(e.clientY-r.top-34)+"px"});c.addEventListener("mouseleave",function(){t.hidden=true})}());</script>';
		return $o;
	}

	/**
	 * Which quarters the Money page shows, oldest to newest: the last 8 by default, one chosen year (qy), or every quarter (allq).
	 */
	private static function quarter_cols( array $qs ) {
		$qs = array_values( array_unique( $qs ) );
		rsort( $qs );
		$y  = isset( $_GET['qy'] ) ? absint( $_GET['qy'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $y >= 2000 && $y <= 2100 ) {
			return array( $y . '-Q1', $y . '-Q2', $y . '-Q3', $y . '-Q4' );
		}
		return array_reverse( ! empty( $_GET['allq'] ) ? $qs : array_slice( $qs, 0, 8 ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/** The "which quarters" picker: last 8, each year with money in it, or every quarter. Keeps the other view choices. */
	private static function quarter_picker( array $qs, $totals ) {
		$years = array();
		foreach ( $qs as $q ) {
			$years[ (int) substr( $q, 0, 4 ) ] = 1;
		}
		krsort( $years );
		$y   = isset( $_GET['qy'] ) ? absint( $_GET['qy'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$all = ! empty( $_GET['allq'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$o   = '<form method="get" class="spm-qpick" action="' . esc_url( Songpress_Editor::list_url() ) . '"><input type="hidden" name="sp_page" value="money">' . ( $totals ? '<input type="hidden" name="totals" value="1">' : '' )
			. '<label>' . esc_html__( 'Quarters', 'songpress' ) . ' <select name="qv" onchange="var v=this.value,f=this.form;f.querySelectorAll(\'[data-q]\').forEach(function(x){x.remove()});if(v===\'all\'){f.insertAdjacentHTML(\'beforeend\',\'<input type=hidden name=allq value=1 data-q>\')}else if(v!==\'8\'){f.insertAdjacentHTML(\'beforeend\',\'<input type=hidden name=qy value=\'+v+\' data-q>\')}f.querySelector(\'[name=qv]\').disabled=true;f.submit()">'
			. '<option value="8"' . selected( ! $all && ! $y, true, false ) . '>' . esc_html__( 'Last 8', 'songpress' ) . '</option>';
		foreach ( array_keys( $years ) as $yy ) {
			$o .= '<option value="' . (int) $yy . '"' . selected( $y, $yy, false ) . '>' . (int) $yy . '</option>';
		}
		return $o . '<option value="all"' . selected( $all && ! $y, true, false ) . '>' . esc_html__( 'Every quarter', 'songpress' ) . '</option></select></label></form>';
	}

	/**
	 * Income and accounting together: one block per person. The header carries what they are owed; below it come their quarters by income stream,
	 * a Total row (green check = paid in full, amber = partly), what was Paid and what is Left each quarter, then the Record a payment button and history.
	 */
	private static function people_html( array $qs, $sel ) {
		$rows = self::person_grid();
		if ( ! $rows ) {
			return '';
		}
		$totals = ! empty( $_GET['totals'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$cols   = self::quarter_cols( $qs ); // oldest to newest, left to right
		$grid   = array();
		$names  = array();
		$earned = array();
		foreach ( $rows as $r ) {
			$names[ $r->person_key ]                          = $r->person;
			$grid[ $r->person_key ][ $r->quarter ][ $r->src ] = ( $grid[ $r->person_key ][ $r->quarter ][ $r->src ] ?? 0 ) + (float) $r->total;
			$earned[ $r->person_key ]                         = ( $earned[ $r->person_key ] ?? 0 ) + (float) $r->total;
		}
		$paid  = self::paid_by_person();
		$paidc = self::paid_cells();
		foreach ( $paid as $k => $v ) {
			if ( ! isset( $earned[ $k ] ) ) {
				$earned[ $k ] = 0;
				$names[ $k ]  = $k;
				$grid[ $k ]   = array();
			}
		}
		$owed = array();
		foreach ( $earned as $k => $v ) {
			$owed[ $k ] = round( $v - ( $paid[ $k ] ?? 0 ), 2 );
		}
		arsort( $owed );
		$qopts = $qs;
		$cur   = self::quarter( gmdate( 'Y' ) . ' Q' . (int) ceil( (int) gmdate( 'n' ) / 3 ) );
		if ( $cur && ! in_array( $cur, $qopts, true ) ) {
			array_unshift( $qopts, $cur );
		}
		$te = array_sum( $earned );
		$tp = array_sum( $paid );
		$to = array_sum( $owed );
		$base = Songpress_Editor::list_url();
		$o  = '<h3>' . esc_html__( 'Income and accounting', 'songpress' ) . '</h3>';
		$o .= '<ul class="spm-sum"><li><span>' . esc_html__( 'Earned to date', 'songpress' ) . '</span><b>' . esc_html( self::money( $te ) ) . '</b></li><li><span>' . esc_html__( 'Paid', 'songpress' ) . '</span><b>' . esc_html( self::money( $tp ) ) . '</b></li><li class="spm-due"><span>' . esc_html__( 'Still owed', 'songpress' ) . '</span><b>' . esc_html( self::money( $to ) ) . '</b></li></ul>';
		$keep = array_filter( array( 'allq' => ! empty( $_GET['allq'] ) ? '1' : '', 'qy' => isset( $_GET['qy'] ) ? absint( $_GET['qy'] ) : '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$o .= '<div class="spe-help spm-tools"><input type="search" class="spm-find" placeholder="' . esc_attr__( 'Find a person', 'songpress' ) . '" aria-label="' . esc_attr__( 'Find a person', 'songpress' ) . '"> '
			. '<a href="' . esc_url( add_query_arg( array_merge( array( 'sp_page' => 'money', 'totals' => $totals ? '' : '1' ), $keep ), $base ) ) . '">' . esc_html( $totals ? __( 'Break down by stream', 'songpress' ) : __( 'Totals only', 'songpress' ) ) . '</a> · '
			. '<span class="spm-ok">&#10003;</span> ' . esc_html__( 'paid in full', 'songpress' ) . ' <span class="spm-part">&#10003;</span> ' . esc_html__( 'partly paid', 'songpress' ) . '</div>';
		$o   .= '<div class="spm-blocks">';
		$dlgq = array();
		foreach ( $owed as $k => $ow ) {
			$pd   = $paid[ $k ] ?? 0;
			$pct  = $earned[ $k ] > 0 ? max( 0, min( 100, (int) round( 100 * $pd / $earned[ $k ] ) ) ) : 0;
			$even = $ow <= 0.005;
			$o   .= '<section class="spm-block' . ( $even ? ' settled' : '' ) . '" data-name="' . esc_attr( strtolower( $names[ $k ] ) ) . '"><header class="spm-bh">' . Songpress_Profiles::chip( $names[ $k ], 36, 'person' ) . '<div class="spm-pn"><b>' . esc_html( $names[ $k ] ) . '</b><small>' . esc_html( sprintf( /* translators: 1: earned, 2: paid */ __( '%1$s earned · %2$s paid', 'songpress' ), self::money( $earned[ $k ] ), self::money( $pd ) ) ) . '</small></div>';
			$o   .= '<div class="spm-bar2" title="' . esc_attr( $pct . '%' ) . '"><i style="width:' . (int) $pct . '%"></i></div><div class="spm-owe"><small>' . esc_html( $even ? __( 'Settled', 'songpress' ) : __( 'Owed', 'songpress' ) ) . '</small><b>' . esc_html( self::money( $ow ) ) . '</b></div></header>';
			// One row per quarter, newest first, in a box that scrolls up and down; Paid and Left open the payment form for that quarter.
			$pq = array_unique( array_merge( array_keys( $grid[ $k ] ), array_keys( $paidc[ $k ] ?? array() ) ) );
			sort( $pq );
			$rowsq = array();
			foreach ( (array) self::payments_of( $k ) as $h ) {
				$rowsq[ $h->pay_year . '-Q' . $h->pay_quarter ] = $h;
			}
			$sc = array();
			if ( ! $totals ) {
				foreach ( self::STREAMS as $sk => $label ) {
					foreach ( $grid[ $k ] as $bys ) {
						if ( ! empty( $bys[ $sk ] ) ) {
							$sc[ $sk ] = $label;
							break;
						}
					}
				}
			}
			// Quarters run left to right, oldest to newest, in a strip that scrolls sideways (it opens at the newest). The labels on the left and the all-time column on the right stay put.
			$first = '';
			$cols  = array();
			$o    .= '<div class="spm-hscroll"><table class="spm-table spm-income spm-qcols"><thead><tr><th>' . esc_html__( 'Quarter', 'songpress' ) . '</th>';
			foreach ( $pq as $q ) {
				$dlgq[ $q ] = 1;
				$v    = isset( $grid[ $k ][ $q ] ) ? array_sum( $grid[ $k ][ $q ] ) : 0;
				$p    = $paidc[ $k ][ $q ] ?? 0;
				$left = round( $v - $p, 2 );
				if ( '' === $first && $left > 0.005 ) {
					$first = $q;
				}
				$h    = $rowsq[ $q ] ?? null;
				$mark = '';
				if ( 0.0 !== (float) $p ) {
					$full = $v > 0 && $p + 0.005 >= $v;
					$mark = ' <span class="' . ( $full ? 'spm-ok' : 'spm-part' ) . '" title="' . esc_attr( sprintf( /* translators: 1: amount paid, 2: amount earned */ __( 'Paid %1$s of %2$s', 'songpress' ), self::money( $p ), self::money( $v ) ) ) . '">&#10003;</span>';
				}
				$data = ' data-person="' . esc_attr( $names[ $k ] ) . '" data-q="' . esc_attr( $q ) . '" data-left="' . esc_attr( number_format( max( 0, $left ), 2, '.', '' ) ) . '"'
					. ( $h ? ' data-id="' . (int) $h->id . '" data-amt="' . esc_attr( number_format( (float) $h->amount, 2, '.', '' ) ) . '" data-on="' . esc_attr( (string) $h->paid_on ) . '" data-notes="' . esc_attr( (string) $h->notes ) . '"' : '' );
				$cols[ $q ] = array( 'v' => $v, 'p' => $p, 'left' => $left, 'h' => $h, 'mark' => $mark, 'data' => $data );
				$o         .= '<th class="n">' . esc_html( $q ) . '</th>';
			}
			$o .= '<th class="n spm-allt">' . esc_html__( 'All time', 'songpress' ) . '</th></tr></thead><tbody>';
			foreach ( $sc as $sk => $label ) {
				$at = 0;
				$r  = '<tr><th scope="row">' . esc_html( $label ) . '</th>';
				foreach ( $pq as $q ) {
					$sv  = $grid[ $k ][ $q ][ $sk ] ?? 0;
					$at += $sv;
					$r  .= '<td class="n">' . ( $sv ? esc_html( self::money( $sv ) ) : '' ) . '</td>';
				}
				$o .= $r . '<td class="n spm-allt">' . esc_html( self::money( $at ) ) . '</td></tr>';
			}
			$o .= '<tr class="spm-tot"><th scope="row">' . esc_html__( 'Total', 'songpress' ) . '</th>';
			foreach ( $pq as $q ) {
				$o .= '<td class="n"><b>' . ( $cols[ $q ]['v'] ? esc_html( self::money( $cols[ $q ]['v'] ) ) : '' ) . '</b></td>';
			}
			$o .= '<td class="n spm-allt"><b>' . esc_html( self::money( $earned[ $k ] ) ) . '</b></td></tr><tr><th scope="row">' . esc_html__( 'Paid', 'songpress' ) . '</th>';
			foreach ( $pq as $q ) {
				$c  = $cols[ $q ];
				$o .= '<td class="n"><button type="button" class="spm-pcell" title="' . esc_attr( $c['h'] ? __( 'Edit this payment', 'songpress' ) : __( 'Record a payment for this quarter', 'songpress' ) ) . '"' . $c['data'] . '>' . ( 0.0 !== (float) $c['p'] ? esc_html( self::money( $c['p'] ) ) . $c['mark'] : '&mdash;' ) . '</button></td>';
			}
			$o .= '<td class="n spm-allt">' . esc_html( self::money( $pd ) ) . '</td></tr><tr><th scope="row">' . esc_html__( 'Left', 'songpress' ) . '</th>';
			foreach ( $pq as $q ) {
				$c  = $cols[ $q ];
				$o .= '<td class="n spm-lc"><button type="button" class="spm-pcell' . ( $c['left'] > 0.005 ? ' due' : '' ) . '" title="' . esc_attr__( 'Record a payment for this quarter', 'songpress' ) . '"' . str_replace( ' data-id=', ' data-xid=', $c['data'] ) . '>' . ( $c['left'] > 0.005 ? esc_html( self::money( $c['left'] ) ) : '&mdash;' ) . '</button></td>';
			}
			$o .= '<td class="n spm-allt"><b>' . esc_html( self::money( max( 0, $ow ) ) ) . '</b></td></tr></tbody></table></div>';
			$def = '' !== $first ? $first : ( '' !== $sel ? $sel : ( $qopts ? $qopts[0] : '' ) );
			$o  .= '<div class="spm-foot"><details class="spm-paybox"><summary>' . esc_html__( 'Record a payment', 'songpress' ) . '</summary>'
				. self::form_open( 'songpress_money_payment', 'spm-pay' ) . '<input type="hidden" name="person" value="' . esc_attr( $names[ $k ] ) . '"><label><span>' . esc_html__( 'Amount', 'songpress' ) . '</span><input class="spm-amt" name="amount" inputmode="decimal" value="' . esc_attr( $ow > 0 ? number_format( $ow, 2, '.', '' ) : '' ) . '" placeholder="0.00" required></label>'
				. '<label><span>' . esc_html__( 'For quarter', 'songpress' ) . '</span><select name="quarter">';
			foreach ( $qopts as $q ) {
				$o .= '<option value="' . esc_attr( $q ) . '"' . selected( $q, $def, false ) . '>' . esc_html( $q ) . '</option>';
			}
			$o .= '</select></label><label><span>' . esc_html__( 'Date paid', 'songpress' ) . '</span><input type="date" name="paid_on" value="' . esc_attr( gmdate( 'Y-m-d' ) ) . '"></label><label class="grow"><span>' . esc_html__( 'Note', 'songpress' ) . '</span><input name="notes"></label><button class="spe-save" type="submit">' . esc_html__( 'Pay', 'songpress' ) . '</button></form></details>';
			$hist = self::payments_of( $k );
			if ( $hist ) {
				$o .= '<details class="spm-hist"><summary>' . esc_html( sprintf( /* translators: %d: number of payments */ _n( '%d payment', '%d payments', count( $hist ), 'songpress' ), count( $hist ) ) ) . '</summary><ul>';
				foreach ( $hist as $h ) {
					$q  = $h->pay_year . '-Q' . $h->pay_quarter;
					$qs_edit = $qopts;
					if ( ! in_array( $q, $qs_edit, true ) ) {
						$qs_edit[] = $q;
						rsort( $qs_edit );
					}
					$qo = '';
					foreach ( $qs_edit as $qq ) {
						$qo .= '<option value="' . esc_attr( $qq ) . '"' . selected( $qq, $q, false ) . '>' . esc_html( $qq ) . '</option>';
					}
					$o .= '<li><span>' . esc_html( $q . ' · ' . ( $h->paid_on ?: '' ) ) . '</span> <b>' . esc_html( self::money( $h->amount ) ) . '</b>' . ( $h->notes ? ' <small>' . esc_html( preg_replace( '/\s+/', ' ', trim( (string) $h->notes ) ) ) . '</small>' : '' ) . ' '
						. '<details class="spm-edit"><summary title="' . esc_attr__( 'Edit this payment', 'songpress' ) . '" aria-label="' . esc_attr__( 'Edit this payment', 'songpress' ) . '"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16zM13.5 6.5l4 4"/></svg></summary>'
						. self::form_open( 'songpress_money_payment_update', 'spm-editform' ) . '<input type="hidden" name="id" value="' . (int) $h->id . '">'
						. '<label><span>' . esc_html__( 'Quarter', 'songpress' ) . '</span><select name="quarter">' . $qo . '</select></label>'
						. '<label><span>' . esc_html__( 'Amount', 'songpress' ) . '</span><input name="amount" inputmode="decimal" value="' . esc_attr( number_format( (float) $h->amount, 2, '.', '' ) ) . '"></label>'
						. '<label><span>' . esc_html__( 'Date paid', 'songpress' ) . '</span><input type="date" name="paid_on" value="' . esc_attr( (string) $h->paid_on ) . '"></label>'
						. '<label class="grow"><span>' . esc_html__( 'Note', 'songpress' ) . '</span><textarea name="notes" rows="2">' . esc_textarea( (string) $h->notes ) . '</textarea></label>'
						. '<button class="spe-save" type="submit">' . esc_html__( 'Save', 'songpress' ) . '</button></form></details> '
						. self::form_open( 'songpress_money_payment_delete', 'spm-del' ) . '<input type="hidden" name="id" value="' . (int) $h->id . '"><button type="submit" class="spe-btn" title="' . esc_attr__( 'Remove this payment', 'songpress' ) . '">&times;</button></form></li>';
				}
				$o .= '</ul></details>';
			}
			$o .= '</div></section>';
		}
		$dq = array_values( array_unique( array_merge( $qopts, array_keys( $dlgq ) ) ) );
		rsort( $dq );
		$qo = '';
		foreach ( $dq as $q ) {
			$qo .= '<option value="' . esc_attr( $q ) . '">' . esc_html( $q ) . '</option>';
		}
		$fields = '<label><span>' . esc_html__( 'Quarter', 'songpress' ) . '</span><select name="quarter">' . $qo . '</select></label><label><span>' . esc_html__( 'Amount', 'songpress' ) . '</span><input name="amount" inputmode="decimal" required></label><label><span>' . esc_html__( 'Date paid', 'songpress' ) . '</span><input type="date" name="paid_on"></label><label class="grow"><span>' . esc_html__( 'Note', 'songpress' ) . '</span><textarea name="notes" rows="2"></textarea></label>';
		$o .= '<dialog class="spm-dlg" id="spm-dlg"><h3 id="spm-dlg-h"></h3>'
			. self::form_open( 'songpress_money_payment', 'spm-dlgform' ) . '<input type="hidden" name="person" value="">' . $fields . '<div class="spm-dlgbar"><button class="spe-save" type="submit">' . esc_html__( 'Record payment', 'songpress' ) . '</button><button type="button" class="spe-btn" data-close="1">' . esc_html__( 'Cancel', 'songpress' ) . '</button></div></form>'
			. self::form_open( 'songpress_money_payment_update', 'spm-dlgform' ) . '<input type="hidden" name="id" value="">' . $fields . '<div class="spm-dlgbar"><button class="spe-save" type="submit">' . esc_html__( 'Save changes', 'songpress' ) . '</button><button type="button" class="spe-btn" data-close="1">' . esc_html__( 'Cancel', 'songpress' ) . '</button></div></form>'
			. self::form_open( 'songpress_money_payment_delete', 'spm-dlgdel' ) . '<input type="hidden" name="id" value=""><button class="spe-btn danger" type="submit">' . esc_html__( 'Remove this payment', 'songpress' ) . '</button></form></dialog>'
			. '<script>(function(){var d=document.getElementById("spm-dlg");if(!d)return;var F=d.querySelectorAll("form"),add=F[0],ed=F[1],del=F[2],h=document.getElementById("spm-dlg-h"),today=new Date().toISOString().slice(0,10);'
			. 'function set(f,n,v){var e=f.elements[n];if(e)e.value=v}'
			. 'document.addEventListener("click",function(e){var b=e.target.closest(".spm-pcell");if(b){var D=b.dataset,edit=!!D.id;add.hidden=edit;ed.hidden=!edit;del.hidden=!edit;'
			. 'h.textContent=(edit?"' . esc_js( __( 'Edit payment', 'songpress' ) ) . ' · ":"' . esc_js( __( 'Record a payment', 'songpress' ) ) . ' · ")+D.person+" · "+D.q;'
			. 'if(edit){set(ed,"id",D.id);set(ed,"quarter",D.q);set(ed,"amount",D.amt);set(ed,"paid_on",D.on||today);set(ed,"notes",D.notes||"");set(del,"id",D.id)}'
			. 'else{set(add,"person",D.person);set(add,"quarter",D.q);set(add,"amount",+D.left>0?D.left:"");set(add,"paid_on",today);set(add,"notes","")}'
			. 'if(d.showModal)d.showModal();else d.setAttribute("open","");return}'
			. 'if(e.target.closest("[data-close]")||e.target===d)d.close()})}());</script>';
		return $o . '</div><script>(function(){function e(){Array.prototype.forEach.call(document.querySelectorAll(".spm-hscroll"),function(d){d.scrollLeft=d.scrollWidth})}e();window.addEventListener("load",e)})()</script><script>(function(){var f=document.querySelector(".spm-find");if(!f)return;f.addEventListener("input",function(){var q=f.value.trim().toLowerCase();document.querySelectorAll(".spm-block").forEach(function(b){b.hidden=q&&b.getAttribute("data-name").indexOf(q)<0})})}());</script>';
	}

	/** Record a payment to one person against a quarter. A quarter holds one running row per person: more payments in it are added on, and a planned (unpaid) amount is replaced. */
	public static function handle_payment() {
		self::guard( 'songpress_money_payment' );
		global $wpdb;
		$in     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- cleaned below.
		$q      = self::quarter( $in['quarter'] ?? '' );
		$party  = sanitize_text_field( $in['person'] ?? '' );
		$key    = self::person_key( $party );
		$amount = round( (float) str_replace( array( '$', ',' ), '', (string) ( $in['amount'] ?? 0 ) ), 2 );
		if ( '' === $q || '' === $key || $amount <= 0 ) {
			self::go( 'money', array( 'm_err' => __( 'Choose a person, a quarter and an amount above zero.', 'songpress' ) ) );
		}
		$year = (int) substr( $q, 0, 4 );
		$qn   = (int) substr( $q, -1 );
		$on   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $in['paid_on'] ?? '' ) ) ? $in['paid_on'] : gmdate( 'Y-m-d' );
		$note = sanitize_text_field( $in['notes'] ?? '' );
		$line = $on . ': ' . number_format( $amount, 2, '.', '' ) . ( '' !== $note ? ' ' . $note : '' );
		$p    = self::t( 'payments' );
		$have = $wpdb->get_row( $wpdb->prepare( "SELECT id, amount, paid, notes FROM {$p} WHERE pay_year = %d AND pay_quarter = %d AND kind = 'writer' AND party_key = %s", $year, $qn, $key ) ); // phpcs:ignore WordPress.DB
		if ( $have ) {
			$was = (int) $have->paid;
			$wpdb->update( $p, array(
				'amount'     => $was ? round( (float) $have->amount + $amount, 2 ) : $amount,
				'paid'       => 1,
				'paid_on'    => $on,
				'notes'      => ( $was && '' !== trim( (string) $have->notes ) ? trim( (string) $have->notes ) . "\n" : '' ) . $line,
				'updated_at' => current_time( 'mysql' ),
			), array( 'id' => (int) $have->id ) );
		} else {
			$wpdb->insert( $p, array( 'pay_year' => $year, 'pay_quarter' => $qn, 'kind' => 'writer', 'party' => $party, 'party_key' => $key, 'amount' => $amount, 'paid' => 1, 'paid_on' => $on, 'notes' => $line, 'updated_at' => current_time( 'mysql' ) ) );
		}
		$after = (float) $wpdb->get_var( $wpdb->prepare( "SELECT amount FROM {$p} WHERE pay_year = %d AND pay_quarter = %d AND kind = 'writer' AND party_key = %s", $year, $qn, $key ) ); // phpcs:ignore WordPress.DB
		Songpress_Audit::log( 'money', 'Recorded a payment', $party, array_filter( array( 'quarter' => $q, 'amount' => self::money( $amount ), 'paid on' => $on, 'note' => $note, 'paid that quarter now' => self::money( $after ) ), 'strlen' ) );
		$who = class_exists( 'Songpress_Push' ) ? Songpress_Push::user_for_name( $party ) : 0;
		if ( $who ) {
			Songpress_Push::notify( $who, __( 'Payment recorded', 'songpress' ), sprintf( /* translators: 1: amount, 2: quarter */ __( '%1$s was recorded as paid to you for %2$s.', 'songpress' ), self::money( $amount ), $q ) );
		}
		self::go( 'money', array( 'm_ok' => sprintf( /* translators: 1: amount, 2: person, 3: quarter */ __( 'Recorded %1$s paid to %2$s for %3$s.', 'songpress' ), self::money( $amount ), $party, $q ) ) );
	}

	public static function handle_payment_update() {
		self::guard( 'songpress_money_payment_update' );
		global $wpdb;
		$in  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- cleaned below.
		$id  = absint( $in['id'] ?? 0 );
		$p   = self::t( 'payments' );
		$row = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p} WHERE id = %d", $id ) ) : null; // phpcs:ignore WordPress.DB
		$q   = self::quarter( $in['quarter'] ?? '' );
		$amt = round( (float) str_replace( array( '$', ',' ), '', (string) ( $in['amount'] ?? 0 ) ), 2 );
		if ( ! $row || '' === $q || 0.0 === $amt ) {
			self::go( 'money', array( 'm_err' => __( 'Choose a quarter and an amount (it can be negative for a correction, but not zero; use remove for that).', 'songpress' ) ) );
		}
		$year = (int) substr( $q, 0, 4 );
		$qn   = (int) substr( $q, -1 );
		$on   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $in['paid_on'] ?? '' ) ) ? $in['paid_on'] : ( $row->paid_on ?: gmdate( 'Y-m-d' ) );
		$note = sanitize_textarea_field( $in['notes'] ?? '' );
		$other = ( $year !== (int) $row->pay_year || $qn !== (int) $row->pay_quarter )
			? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p} WHERE pay_year = %d AND pay_quarter = %d AND kind = %s AND party_key = %s AND id <> %d", $year, $qn, $row->kind, $row->party_key, $id ) ) // phpcs:ignore WordPress.DB
			: null;
		if ( $other ) { // that quarter already has its running row for this person: fold this payment into it
			$wpdb->update( $p, array(
				'amount'     => round( (float) $other->amount + $amt, 2 ),
				'paid'       => 1,
				'paid_on'    => $on,
				'notes'      => trim( ( '' !== trim( (string) $other->notes ) ? trim( (string) $other->notes ) . "\n" : '' ) . $note ),
				'updated_at' => current_time( 'mysql' ),
			), array( 'id' => (int) $other->id ) );
			$wpdb->delete( $p, array( 'id' => $id ) );
		} else {
			$wpdb->update( $p, array( 'pay_year' => $year, 'pay_quarter' => $qn, 'amount' => $amt, 'paid' => 1, 'paid_on' => $on, 'notes' => $note, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
		}
		$was = $row->pay_year . '-Q' . $row->pay_quarter;
		$d   = array( 'payment' => $id );
		if ( $was !== $q ) {
			$d['quarter'] = array( $was, $q );
		}
		if ( abs( (float) $row->amount - $amt ) > 0.004 ) {
			$d['amount'] = array( self::money( $row->amount ), self::money( $amt ) );
		}
		if ( (string) $row->paid_on !== (string) $on ) {
			$d['paid on'] = array( (string) $row->paid_on ?: '-', $on );
		}
		if ( trim( (string) $row->notes ) !== trim( $note ) ) {
			$d['note'] = array( mb_substr( trim( (string) $row->notes ), 0, 60 ) ?: '-', mb_substr( trim( $note ), 0, 60 ) ?: '-' );
		}
		if ( $other ) {
			$d['folded into'] = 'the payment already in ' . $q;
		}
		Songpress_Audit::log( 'money', 'Edited a payment', $row->party, $d );
		self::go( 'money', array( 'm_ok' => sprintf( /* translators: 1: person, 2: amount, 3: quarter */ __( 'Updated the payment to %1$s: %2$s for %3$s.', 'songpress' ), $row->party, self::money( $amt ), $q ) ) );
	}

	public static function handle_payment_delete() {
		self::guard( 'songpress_money_payment_delete' );
		global $wpdb;
		$id = absint( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- checked by guard().
		if ( $id ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'payments' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
			$wpdb->delete( self::t( 'payments' ), array( 'id' => $id ) );
			if ( $row ) {
				Songpress_Audit::log( 'money', 'Removed a payment', $row->party, array_filter( array( 'quarter' => $row->pay_year . '-Q' . $row->pay_quarter, 'amount' => self::money( $row->amount ), 'paid on' => (string) $row->paid_on, 'note' => mb_substr( trim( (string) $row->notes ), 0, 80 ) ), 'strlen' ) );
			}
		}
		self::go( 'money', array( 'm_ok' => __( 'Payment removed.', 'songpress' ) ) );
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
		Songpress_Audit::log( 'money', 'Added an expense', sanitize_text_field( $in['payee'] ?? '' ), array_filter( array( 'amount' => self::money( $amt ), 'category' => sanitize_text_field( $in['category'] ?? '' ), 'spent on' => $on ), 'strlen' ) );
		self::go( 'money', array( 'm_ok' => __( 'Expense added.', 'songpress' ) ) );
	}

	public static function handle_expense_delete() {
		self::guard( 'songpress_money_expense_delete' );
		global $wpdb;
		$eid = absint( $_POST['expense_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- checked by guard().
		$row = $eid ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'expenses' ) . ' WHERE id = %d', $eid ) ) : null; // phpcs:ignore WordPress.DB
		$wpdb->delete( self::t( 'expenses' ), array( 'id' => $eid ) );
		if ( $row ) {
			Songpress_Audit::log( 'money', 'Removed an expense', (string) $row->payee, array_filter( array( 'amount' => self::money( $row->amount ), 'category' => (string) $row->category, 'spent on' => (string) $row->spent_on ), 'strlen' ) );
		}
		self::go( 'money', array( 'm_ok' => __( 'Expense removed.', 'songpress' ) ) );
	}

	public static function handle_band_payee() {
		self::guard( 'songpress_money_band_payee' );
		$in    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- cleaned below.
		$name  = sanitize_text_field( $in['band'] ?? '' );
		$payee = sanitize_text_field( $in['payee'] ?? '' );
		if ( '' === Songpress_Profiles::key( $name ) ) {
			self::go( 'money', array( 'm_err' => __( 'Choose a band.', 'songpress' ) ) );
		}
		global $wpdb;
		$t   = self::t( 'profiles' );
		$old = (string) $wpdb->get_var( $wpdb->prepare( "SELECT payee FROM {$t} WHERE kind = 'band' AND name_key = %s", Songpress_Profiles::key( $name ) ) ); // phpcs:ignore WordPress.DB
		Songpress_Profiles::upsert( 'band', $name, array( 'payee' => $payee ) );
		if ( $old !== $payee ) {
			Songpress_Audit::log( 'money', 'Changed a band payee', $name, array( 'payee' => array( '' !== $old ? $old : 'nobody', '' !== $payee ? $payee : 'nobody' ) ) );
		}
		self::go( 'money', array( 'm_ok' => '' === $payee ? sprintf( /* translators: %s: band */ __( '%s no longer pays anyone.', 'songpress' ), $name ) : sprintf( /* translators: 1: band, 2: person */ __( '%1$s now pays %2$s.', 'songpress' ), $name, $payee ) ) );
	}

	/** The Bands section under the persons: each band's DistroKid money by quarter, less its performers, and who is paid it. */
	private static function bands_html() {
		$bands = self::band_detail();
		if ( ! $bands ) {
			return '';
		}
		global $wpdb;
		$people = array();
		foreach ( (array) $wpdb->get_col( 'SELECT DISTINCT person FROM ' . self::t( 'person_earnings' ) . " WHERE person <> ''" ) as $n ) { // phpcs:ignore WordPress.DB
			$people[ self::person_key( $n ) ] = $n;
		}
		foreach ( (array) $wpdb->get_col( 'SELECT name FROM ' . self::t( 'profiles' ) . " WHERE kind = 'person'" ) as $n ) { // phpcs:ignore WordPress.DB
			$people[ self::person_key( $n ) ] = $people[ self::person_key( $n ) ] ?? $n;
		}
		asort( $people );
		$dl = '<datalist id="spm-people">';
		foreach ( $people as $n ) {
			$dl .= '<option value="' . esc_attr( $n ) . '">';
		}
		$dl .= '</datalist>';
		$o  = '<h3>' . esc_html__( 'Bands', 'songpress' ) . '</h3><p class="spe-help">' . esc_html__( 'DistroKid money for each band by quarter. The performers on a song take their share first; what is left goes to the person you pick, and shows on their card as Band.', 'songpress' ) . '</p>' . $dl . '<div class="spm-bands">';
		foreach ( $bands as $b ) {
			$gross = $perf = $net = 0.0;
			foreach ( $b['rows'] as $c ) {
				$gross += $c['gross'];
				$perf  += $c['performers'];
				$net   += $c['net'];
			}
			$o .= '<section class="spm-band"><header><b>' . esc_html( $b['name'] ) . '</b><small>' . esc_html( sprintf( /* translators: 1: statement total, 2: left for the band */ __( '%1$s earned · %2$s after performers', 'songpress' ), self::money( $gross ), self::money( $net ) ) ) . '</small></header>'
				. self::form_open( 'songpress_money_band_payee', 'spm-payee' ) . '<input type="hidden" name="band" value="' . esc_attr( $b['name'] ) . '"><label><span>' . esc_html__( 'Pays to', 'songpress' ) . '</span><input name="payee" list="spm-people" value="' . esc_attr( $b['payee'] ) . '" placeholder="' . esc_attr__( 'nobody yet', 'songpress' ) . '"></label><button class="spe-save" type="submit">' . esc_html__( 'Save', 'songpress' ) . '</button></form>'
				. '<div class="spm-vscroll"><table class="spm-bq"><thead><tr><th>' . esc_html__( 'Quarter', 'songpress' ) . '</th><th>' . esc_html__( 'Earned', 'songpress' ) . '</th><th>' . esc_html__( 'Performers', 'songpress' ) . '</th><th>' . esc_html__( 'To payee', 'songpress' ) . '</th></tr></thead><tbody>';
			foreach ( $b['rows'] as $q => $c ) {
				$o .= '<tr><td>' . esc_html( $q ) . '</td><td>' . esc_html( self::money( $c['gross'] ) ) . '</td><td>' . esc_html( self::money( $c['performers'] ) ) . '</td><td><b>' . esc_html( self::money( $c['net'] ) ) . '</b></td></tr>';
			}
			$o .= '</tbody><tfoot><tr><td>' . esc_html__( 'All time', 'songpress' ) . '</td><td>' . esc_html( self::money( $gross ) ) . '</td><td>' . esc_html( self::money( $perf ) ) . '</td><td><b>' . esc_html( self::money( $net ) ) . '</b></td></tr></tfoot></table></div></section>';
		}
		return $o . '</div>';
	}

	/* ---------- screens ---------- */

	public static function form_open( $action, $class = '' ) {
		return '<form method="post" class="' . esc_attr( $class ) . '" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( $action, '_wpnonce', true, false ) . '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="sp_return" value="' . esc_attr( Songpress_Editor::list_url() ) . '">';
	}

	public static function notes() {
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

	public static function bars( array $items ) {
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

		// all earnings by quarter: a stacked column per quarter, each income stream a part of the column
		$grid = array();
		foreach ( self::by_quarter() as $r ) {
			$grid[ $r->quarter ][ $r->src ] = (float) $r->total;
		}
		$outq = self::outflow_by_quarter();
		if ( $grid || $outq ) {
			$qall = array_unique( array_merge( $qs, array_keys( $outq ) ) );
			$cols = self::quarter_cols( $qall );
			$o   .= '<h3>' . esc_html__( 'Money in and out by quarter', 'songpress' ) . '</h3>' . self::quarter_picker( $qall, false ) . self::quarter_chart( $grid, $cols, $outq );
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

		$o .= self::people_html( $qs, $sel );
		$o .= self::bands_html();

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

		$o .= '<p class="spe-help"><a href="' . esc_url( Songpress_Screens::url( 'uploads' ) ) . '">' . esc_html__( 'Load statements and the old catalog file on the Upload / Download page.', 'songpress' ) . '</a></p>';
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
		$o     .= '<p class="spe-help"><a href="' . esc_url( Songpress_Screens::url( 'uploads', array( 'type' => 'dk_stats' ) ) ) . '">' . esc_html__( 'Import the DistroKid stats page on the Upload / Download page.', 'songpress' ) . '</a></p>';
		if ( ! $months && ! self::daily( 1 ) ) {
			return $o . Songpress_Usage::panel() . '</div>';
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
		return $o . Songpress_Usage::panel() . '</div>';
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

	/** A column chart of what one person earned each quarter, oldest on the left. Empty when nothing is recorded for them. */
	public static function quarterly_html( $display_name, $max_q = 12 ) {
		$key = self::person_key( $display_name );
		if ( '' === $key ) {
			return '';
		}
		$by = array();
		foreach ( self::my_rows( $key ) as $r ) {
			if ( 'band' === $r->src ) { // the writer's share only: what a band they are paid for earns is not theirs as a writer
				continue;
			}
			$by[ $r->quarter ] = ( $by[ $r->quarter ] ?? 0 ) + (float) $r->total;
		}
		if ( ! $by ) {
			return '';
		}
		ksort( $by );
		$by  = array_slice( $by, -$max_q, null, true );
		$top = max( $by );
		$n   = count( $by );
		$w   = 100 / max( 1, $n );
		$svg = '<svg class="songpress-qchart" viewBox="0 0 100 52" preserveAspectRatio="none" role="img" aria-label="' . esc_attr__( 'Income by quarter', 'songpress' ) . '">';
		$lab = '<div class="songpress-qlabs">';
		$i   = 0;
		foreach ( $by as $q => $v ) {
			$h    = $top > 0 ? max( 0.6, round( 48 * $v / $top, 2 ) ) : 0.6;
			$svg .= '<rect x="' . round( $i * $w + $w * 0.14, 2 ) . '" y="' . round( 50 - $h, 2 ) . '" width="' . round( $w * 0.72, 2 ) . '" height="' . $h . '" rx="0.8"><title>' . esc_html( $q . ': ' . self::money( $v ) ) . '</title></rect>';
			$lab .= '<span>' . esc_html( preg_replace( '/^\d{4}-Q(\d)$/', 'Q$1', $q ) ) . ( ( 0 === $i || str_ends_with( $q, 'Q1' ) ) ? '<small>' . esc_html( '’' . substr( $q, 2, 2 ) ) . '</small>' : '<small>&nbsp;</small>' ) . '</span>';
			$i++;
		}
		$all = array_sum( $by );
		return '<h3>' . esc_html__( 'Quarterly income', 'songpress' ) . '</h3><div class="songpress-qwrap"><p class="songpress-qtotal"><strong>' . esc_html( self::money( $all ) ) . '</strong> ' . esc_html( sprintf( /* translators: %d: number of quarters */ _n( 'in %d quarter', 'over %d quarters', $n, 'songpress' ), $n ) ) . '</p>' . $svg . '</svg>' . $lab . '</div></div>';
	}

	/** What one person has earned from each song, all sources: song id => total, and normalised title => total as a fallback. */
	public static function my_song_totals( $display_name ) {
		global $wpdb;
		$key = self::person_key( $display_name );
		$out = array( 'id' => array(), 'title' => array() );
		if ( '' === $key ) {
			return $out;
		}
		$t = self::t( 'person_earnings' );
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT song_id, work_title, SUM(amount) AS total FROM {$t} WHERE person_key = %s GROUP BY song_id, work_title", $key ) ) as $r ) { // phpcs:ignore WordPress.DB
			if ( (int) $r->song_id ) {
				$out['id'][ (int) $r->song_id ] = ( $out['id'][ (int) $r->song_id ] ?? 0 ) + (float) $r->total;
			}
			$k                  = self::norm_title( $r->work_title );
			$out['title'][ $k ] = ( $out['title'][ $k ] ?? 0 ) + (float) $r->total;
		}
		return $out;
	}

	/** DistroKid earnings per song (song id => total). */
	public static function song_dk( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$t   = self::t( 'earnings' );
		$in  = implode( ',', $ids );
		$out = array();
		foreach ( $wpdb->get_results( "SELECT song_id, SUM(amount) AS total FROM {$t} WHERE src = 'distrokid' AND song_id IN ({$in}) GROUP BY song_id" ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ (int) $r->song_id ] = (float) $r->total;
		}
		return $out;
	}
}
