<?php
defined( 'ABSPATH' ) || exit;

/**
 * Upload / Download: the one page in the app where files go in and out. Uploads pick a tool from a
 * dropdown (works, the old catalog file, photos, DistroKid stats and sales, MLC, ASCAP, CCLI);
 * downloads pick a table and save it as a CSV. Managers only.
 */
class Songpress_Uploads {

	/** Statement sources that read a table of lines: label, accepted files, hint. */
	const STATEMENTS = array(
		'distrokid' => array( 'DistroKid sales', '.csv,.tsv,.txt', 'The sales report from DistroKid (CSV or TSV): sale month, store, title, ISRC, quantity, earnings.' ),
		'mlc'       => array( 'MLC', '.csv,.tsv,.txt', 'A royalty detail export from The MLC (TSV or CSV). The quarter comes from the distribution date unless you choose one.' ),
		'ascap'     => array( 'ASCAP', '.csv,.tsv,.txt', 'An ASCAP royalty export (CSV). Needs a work title and dollars; the quarter comes from the file or the one you choose.' ),
		'ccli'      => array( 'CCLI', '.csv,.tsv,.txt,.pdf', 'A CCLI statement as CSV, or the PDF owner statement (the server must have pdftotext). Choose the quarter it covers.' ),
	);

	const COLS = array(
		'title'  => array( 'work_title', 'work_primary_title', 'song_title', 'track_title', 'song', 'title', 'work_name', 'work' ),
		'isrc'   => array( 'isrc' ),
		'units'  => array( 'quantity', 'units', 'streams', 'usage_count' ),
		'store'  => array( 'store', 'service', 'dsp', 'platform' ),
		'when'   => array( 'quarter', 'col', 'sale_month', 'distribution_date', 'reporting_date', 'period', 'date', 'month' ),
		'amount' => array(
			'distrokid' => array( 'earnings_usd', 'earnings', 'amount' ),
			'mlc'       => array( 'distributed_amount', 'publisher_royalty_amount', 'royalty_amount', 'amount' ),
			'ascap'     => array( 'dollars', 'amount', 'royalties', 'total_dollars' ),
			'ccli'      => array( 'earnings', 'amount', 'royalty' ),
		),
	);

	/** Tables people can save as CSV: label, permission, SQL (the {t} tokens are table names). */
	const DOWNLOADS = array(
		'catalog'       => array( 'The whole catalog', 'export', '' ),
		'credits'       => array( 'Credits (every writer, performer, publisher)', 'export', 'SELECT s.serial, s.title, c.role, c.name, c.ipi, c.share FROM {credits} c LEFT JOIN {songs} s ON s.id = c.song_id ORDER BY s.serial, c.role, c.position' ),
		'earnings'      => array( 'Earnings by song, quarter and source', 'money', 'SELECT s.serial, s.title, e.quarter, e.src, e.amount FROM {earnings} e LEFT JOIN {songs} s ON s.id = e.song_id ORDER BY e.quarter DESC, e.src, s.serial' ),
		'person'        => array( 'Earnings by person', 'money', 'SELECT p.person, p.work_title, s.serial, p.quarter, p.src, p.amount FROM {person_earnings} p LEFT JOIN {songs} s ON s.id = p.song_id ORDER BY p.quarter DESC, p.person' ),
		'payments'      => array( 'Payouts', 'money', 'SELECT pay_year, pay_quarter, kind, party, amount, paid, paid_on, notes FROM {payments} ORDER BY pay_year DESC, pay_quarter DESC, party' ),
		'expenses'      => array( 'Expenses', 'money', 'SELECT spent_on, payee, category, amount, person, notes FROM {expenses} ORDER BY spent_on DESC' ),
		'streams'       => array( 'Daily streams', 'money', 'SELECT day, service, streams FROM {streams} ORDER BY day DESC, service' ),
		'stats'         => array( 'DistroKid store figures by month', 'money', 'SELECT month, store, title, isrc, units, earnings FROM {stats} ORDER BY month DESC, store' ),
		'journal'       => array( 'Notes', 'journal', 'SELECT log_date, kind, title, notes, place, song_key, tempo, authors, latitude, longitude, pinned, archived FROM {journal} ORDER BY log_date DESC' ),
		'ratings'       => array( 'Song ratings', 'journal', 'SELECT s.serial, s.title, r.voter, r.stars, r.created_at FROM {ratings} r LEFT JOIN {songs} s ON s.id = r.song_id ORDER BY s.serial' ),
		'registrations' => array( 'Registrations', 'registrations', 'SELECT f.family_no, r.society, r.in_progress, r.started_on, r.reference, r.note, r.updated_at FROM {registrations} r LEFT JOIN {families} f ON f.id = r.family_id ORDER BY f.family_no, r.society' ),
		'agreements'    => array( 'Agreements', 'agreements', 'SELECT serial, kind, title, created_at FROM {agreements} ORDER BY created_at DESC' ),
	);

	public static function init() {
		add_action( 'admin_post_songpress_statement', array( __CLASS__, 'handle_statement' ) );
		add_action( 'admin_post_songpress_download', array( __CLASS__, 'handle_download' ) );
	}

	/* ---------- parsing (pure) ---------- */

	public static function head( $h ) {
		return trim( preg_replace( '/[^a-z0-9]+/', '_', strtolower( (string) $h ) ), '_' );
	}

	/** Text of a CSV or TSV into rows keyed by normalized header names. */
	public static function table( $text ) {
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $text );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$first = strtok( $text, "\n" );
		$delim = substr_count( (string) $first, "\t" ) > substr_count( (string) $first, ',' ) ? "\t" : ',';
		$rows  = array();
		$keys  = null;
		foreach ( explode( "\n", $text ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells = str_getcsv( $line, $delim, '"', '' );
			if ( null === $keys ) {
				$keys = array_map( array( __CLASS__, 'head' ), $cells );
				continue;
			}
			$row = array();
			foreach ( $keys as $i => $k ) {
				if ( '' !== $k && ! isset( $row[ $k ] ) ) {
					$row[ $k ] = isset( $cells[ $i ] ) ? trim( $cells[ $i ] ) : '';
				}
			}
			$rows[] = $row;
		}
		return $rows;
	}

	private static function pick( array $row, array $names ) {
		foreach ( $names as $n ) {
			if ( isset( $row[ $n ] ) && '' !== $row[ $n ] ) {
				return $row[ $n ];
			}
		}
		return '';
	}

	public static function amount( $v ) {
		$v = trim( (string) $v );
		$neg = '' !== $v && ( '-' === $v[0] || '(' === $v[0] );
		$n = (float) preg_replace( '/[^0-9.]/', '', str_replace( ',', '', $v ) );
		return $neg ? -$n : $n;
	}

	/** Any quarter-ish or date-ish value to "2026-Q2" ('' when it is neither). */
	public static function quarter_of( $v ) {
		$v = trim( (string) $v );
		$q = Songpress_Money::quarter( $v );
		if ( '' !== $q ) {
			return $q;
		}
		if ( preg_match( '/^(\d{2})\s*Q([1-4])$/i', $v, $m ) ) {
			return '20' . $m[1] . '-Q' . $m[2];
		}
		if ( preg_match( '/^(\d{4})-(\d{1,2})/', $v, $m ) && (int) $m[2] >= 1 && (int) $m[2] <= 12 ) {
			return $m[1] . '-Q' . (int) ceil( (int) $m[2] / 3 );
		}
		if ( preg_match( '/^(\d{1,2})\/\d{1,2}\/(\d{4})/', $v, $m ) && (int) $m[1] <= 12 ) {
			return $m[2] . '-Q' . (int) ceil( (int) $m[1] / 3 );
		}
		if ( preg_match( '/[A-Za-z]{3}/', $v ) && ( $t = strtotime( $v ) ) ) {
			return gmdate( 'Y', $t ) . '-Q' . (int) ceil( (int) gmdate( 'n', $t ) / 3 );
		}
		return '';
	}

	/** "2026-03" for date-ish values, '' otherwise. */
	public static function month_of( $v ) {
		$v = trim( (string) $v );
		if ( preg_match( '/^(\d{4})-(\d{2})/', $v, $m ) ) {
			return $m[1] . '-' . $m[2];
		}
		if ( preg_match( '/^(\d{1,2})\/\d{1,2}\/(\d{4})/', $v, $m ) && (int) $m[1] <= 12 ) {
			return $m[2] . '-' . sprintf( '%02d', $m[1] );
		}
		if ( preg_match( '/[A-Za-z]{3}/', $v ) && ( $t = strtotime( $v ) ) ) {
			return gmdate( 'Y-m', $t );
		}
		return '';
	}

	/** Text of a CCLI PDF (from pdftotext -layout) into lines of title and amount. */
	public static function pdf_lines( $text ) {
		$out = array();
		foreach ( explode( "\n", (string) $text ) as $line ) {
			if ( preg_match( '/^\s*(.{3,}?)\s{2,}.*?\$?\s*(-?[\d,]+\.\d{2})\s*$/', $line, $m ) && ! preg_match( '/^(total|page|statement|subtotal)/i', trim( $m[1] ) ) ) {
				$out[] = array( 'title' => trim( $m[1] ), 'amount' => self::amount( $m[2] ) );
			}
		}
		return $out;
	}

	/**
	 * Statement text into lines: title, isrc, amount, quarter, month, store, units.
	 * $quarter ("2026-Q2" or '') overrides what the file says. Returns array( lines, error ).
	 */
	public static function statement( $src, array $rows, $quarter = '' ) {
		$amt = self::COLS['amount'][ $src ] ?? array( 'amount' );
		$out = array();
		foreach ( $rows as $r ) {
			$title = self::pick( $r, self::COLS['title'] );
			$isrc  = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', self::pick( $r, self::COLS['isrc'] ) ) );
			$val   = self::pick( $r, $amt );
			if ( '' === $val || ( '' === $title && '' === $isrc ) ) {
				continue;
			}
			$when = '';
			foreach ( self::COLS['when'] as $c ) {
				if ( ! empty( $r[ $c ] ) && '' !== self::quarter_of( $r[ $c ] ) ) {
					$when = $r[ $c ];
					break;
				}
			}
			if ( '' === $when && ! empty( $r['year'] ) && ! empty( $r['q'] ) ) {
				$when = (int) $r['year'] . ' Q' . (int) preg_replace( '/\D/', '', $r['q'] );
			}
			$q = '' !== $quarter ? $quarter : self::quarter_of( $when );
			if ( '' === $q ) {
				continue;
			}
			$out[] = array(
				'title' => $title, 'isrc' => $isrc, 'amount' => self::amount( $val ), 'quarter' => $q,
				'month' => self::month_of( $when ), 'store' => self::pick( $r, self::COLS['store'] ), 'units' => (int) self::amount( self::pick( $r, self::COLS['units'] ) ),
			);
		}
		return $out;
	}

	/* ---------- writing ---------- */

	/**
	 * Save statement lines as earnings for their quarters, replacing what that source held for those quarters.
	 * Lines whose song cannot be found are counted, not saved. Returns lines, songs, quarters, unmatched, unmatched_amount.
	 */
	public static function apply( $src, array $lines ) {
		global $wpdb;
		$res = array( 'lines' => count( $lines ), 'songs' => 0, 'quarters' => array(), 'unmatched' => 0, 'unmatched_amount' => 0.0, 'people' => 0 );
		list( $isr, $tit ) = Songpress_Money::lookups();
		$sum  = array();
		$stat = array();
		foreach ( $lines as $l ) {
			$id = ( '' !== $l['isrc'] && isset( $isr[ $l['isrc'] ] ) ) ? $isr[ $l['isrc'] ] : ( $tit[ Songpress_Money::norm_title( $l['title'] ) ] ?? 0 );
			if ( ! $id ) {
				++$res['unmatched'];
				$res['unmatched_amount'] += $l['amount'];
			} else {
				$k         = $id . '|' . $l['quarter'];
				$sum[ $k ] = ( $sum[ $k ] ?? 0 ) + $l['amount'];
			}
			if ( 'distrokid' === $src && '' !== $l['month'] ) {
				$key = md5( $l['month'] . '|' . $l['store'] . '|' . $l['title'] . '|' . ( '' !== $l['isrc'] ? $l['isrc'] : '' ) );
				if ( ! isset( $stat[ $key ] ) ) {
					$stat[ $key ] = array( $key, $l['month'], $l['store'], $l['title'], $l['isrc'], (int) $id, 0, 0.0 );
				}
				$stat[ $key ][6] += $l['units'];
				$stat[ $key ][7] += $l['amount'];
			}
		}
		$qs = array();
		foreach ( $lines as $l ) {
			$qs[ $l['quarter'] ] = true;
		}
		$e = Songpress_Install::table( 'earnings' );
		$p = Songpress_Install::table( 'person_earnings' );
		foreach ( array_keys( $qs ) as $q ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$e} WHERE quarter = %s AND src = %s", $q, $src ) ); // phpcs:ignore WordPress.DB
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p} WHERE quarter = %s AND src = %s", $q, $src ) ); // phpcs:ignore WordPress.DB
		}
		$rows = array();
		$ppl  = array();
		foreach ( $sum as $k => $amt ) {
			list( $id, $q ) = explode( '|', $k );
			$rows[]         = array( (int) $id, $q, $src, round( $amt, 6 ) );
			$writers        = array();
			foreach ( Songpress_Songs::credits( (int) $id ) as $c ) {
				if ( ( 'distrokid' === $src ? 'performer' : 'writer' ) === $c->role && '' !== trim( $c->name ) ) {
					$writers[] = $c;
				}
			}
			if ( ! $writers ) {
				continue;
			}
			$total = 0.0;
			foreach ( $writers as $w ) {
				$total += null === $w->share ? 0 : (float) $w->share;
			}
			$title = (string) ( Songpress_Songs::get( (int) $id )->title ?? '' );
			foreach ( $writers as $w ) {
				$part  = $total > 0 ? ( null === $w->share ? 0 : (float) $w->share ) / $total : 1 / count( $writers );
				$ppl[] = array( trim( $w->name ), Songpress_Money::person_key( $w->name ), $title, (int) $id, $q, $src, round( $amt * $part, 6 ) );
			}
		}
		$res['songs']    = Songpress_Money::batch( $e, array( 'song_id', 'quarter', 'src', 'amount' ), $rows );
		$res['people']   = Songpress_Money::batch( $p, array( 'person', 'person_key', 'work_title', 'song_id', 'quarter', 'src', 'amount' ), $ppl, 'INSERT' );
		$res['quarters'] = array_keys( $qs );
		sort( $res['quarters'] );
		if ( $stat ) {
			Songpress_Money::batch( Songpress_Install::table( 'stats' ), array( 'row_key', 'month', 'store', 'title', 'isrc', 'song_id', 'units', 'earnings' ), array_values( $stat ) );
		}
		return $res;
	}

	/* ---------- handlers ---------- */

	private static function back( $args ) {
		$ret  = isset( $_POST['sp_return'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['sp_return'] ) ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked by the handler.
		$base = '' !== $ret ? $ret : Songpress_Editor::list_url();
		wp_safe_redirect( add_query_arg( array_merge( array( 'sp_page' => 'uploads' ), $args ), $base ) );
		exit;
	}

	public static function handle_statement() {
		check_admin_referer( 'songpress_statement' );
		if ( ! Songpress_Caps::can( 'money' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		$src  = isset( $_POST['src'] ) ? sanitize_key( wp_unslash( $_POST['src'] ) ) : '';
		$args = array( 'type' => $src );
		if ( ! isset( self::STATEMENTS[ $src ] ) ) {
			self::back( array( 'm_err' => __( 'Choose which statement this is.', 'songpress' ) ) );
		}
		$qin = isset( $_POST['quarter'] ) ? sanitize_text_field( wp_unslash( $_POST['quarter'] ) ) : '';
		$q   = Songpress_Money::quarter( $qin );
		if ( '' !== $qin && '' === $q ) {
			self::back( $args + array( 'm_err' => __( 'Write the quarter like 2026 Q2.', 'songpress' ) ) );
		}
		if ( empty( $_FILES['file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::back( $args + array( 'm_err' => __( 'No file was received.', 'songpress' ) ) );
		}
		$name = isset( $_FILES['file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['file']['name'] ) ) : '';
		$path = $_FILES['file']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( preg_match( '/\.pdf$/i', $name ) ) {
			if ( '' === $q ) {
				self::back( $args + array( 'm_err' => __( 'Choose the quarter this statement covers.', 'songpress' ) ) );
			}
			$bin = function_exists( 'shell_exec' ) && ! in_array( 'shell_exec', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true ) ? trim( (string) shell_exec( 'command -v pdftotext 2>/dev/null' ) ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			if ( '' === $bin ) {
				self::back( $args + array( 'm_err' => __( 'This server cannot read PDFs (no pdftotext). Save the statement as a CSV and upload that.', 'songpress' ) ) );
			}
			$text  = (string) shell_exec( escapeshellarg( $bin ) . ' -layout ' . escapeshellarg( $path ) . ' - 2>/dev/null' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			$lines = array();
			foreach ( self::pdf_lines( $text ) as $l ) {
				$lines[] = array( 'title' => $l['title'], 'isrc' => '', 'amount' => $l['amount'], 'quarter' => $q, 'month' => '', 'store' => '', 'units' => 0 );
			}
		} else {
			$text  = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- an uploaded temp file.
			$lines = self::statement( $src, self::table( $text ), $q );
		}
		if ( ! $lines ) {
			self::back( $args + array( 'm_err' => __( 'No statement lines were found. The file needs a song title (or ISRC), an amount, and a date or a chosen quarter.', 'songpress' ) ) );
		}
		$r = self::apply( $src, $lines );
		self::back( $args + array( 'm_ok' => sprintf(
			/* translators: 1: lines read, 2: songs saved, 3: quarters, 4: lines with no matching song, 5: their total */
			__( 'Read %1$d lines. Saved %2$d songs for %3$s. %4$d lines had no matching song (%5$s) and were left out.', 'songpress' ),
			$r['lines'], $r['songs'], implode( ', ', $r['quarters'] ), $r['unmatched'], Songpress_Money::money( $r['unmatched_amount'] )
		) ) );
	}

	public static function handle_download() {
		check_admin_referer( 'songpress_download' );
		$key = isset( $_GET['table'] ) ? sanitize_key( wp_unslash( $_GET['table'] ) ) : '';
		if ( ! isset( self::DOWNLOADS[ $key ] ) || ! Songpress_Caps::can( self::DOWNLOADS[ $key ][1] ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		if ( 'catalog' === $key ) {
			wp_safe_redirect( wp_nonce_url( admin_url( 'admin-post.php?action=songpress_export' ), 'songpress_export' ) );
			exit;
		}
		global $wpdb;
		$rows = $wpdb->get_results( self::sql( $key ), ARRAY_A ); // phpcs:ignore WordPress.DB -- the SQL is a fixed string from DOWNLOADS.
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="songpress-' . $key . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		if ( $rows ) {
			fputcsv( $out, array_keys( $rows[0] ), ',', '"', '' );
			foreach ( $rows as $r ) {
				fputcsv( $out, array_values( $r ), ',', '"', '' );
			}
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/** The SQL of one download with its table names filled in. */
	public static function sql( $key ) {
		$sql = self::DOWNLOADS[ $key ][2];
		return preg_replace_callback( '/\{(\w+)\}/', function ( $m ) {
			return Songpress_Install::table( $m[1] );
		}, $sql );
	}

	/* ---------- screen ---------- */

	/** Upload tools in dropdown order: slug => label. */
	public static function types() {
		$t = array(
			'works'    => __( 'Works (songs from the old catalog, one batch at a time)', 'songpress' ),
			'catalog'  => __( 'Old catalog file (money, notes, art, agreements, registrations)', 'songpress' ),
			'photos'   => __( 'Photos and bios (people, bands, publishers)', 'songpress' ),
			'dk_stats' => __( 'DistroKid stats page (daily streams)', 'songpress' ),
		);
		foreach ( self::STATEMENTS as $k => $s ) {
			$t[ $k ] = $s[0] . ' ' . __( 'statement', 'songpress' );
		}
		return $t;
	}

	public static function render( $type = '' ) {
		if ( ! Songpress_Caps::can( 'import' ) ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'Only Managers can open this screen.', 'songpress' ) . '</p></div>';
		}
		$types = self::types();
		if ( '' === $type && isset( $_GET['type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$type = sanitize_key( wp_unslash( $_GET['type'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( ! isset( $types[ $type ] ) ) {
			$type = '';
		}
		$ret = esc_attr( Songpress_Editor::list_url() );
		$o   = '<div class="songpress sp-app sp-editor sp-money spu"><div class="spe-top"><h2>' . esc_html__( 'Upload / Download', 'songpress' ) . '</h2></div>' . Songpress_Money::notes();
		$o  .= '<h3>' . esc_html__( 'Upload', 'songpress' ) . '</h3><p class="spe-help">' . esc_html__( 'Choose what you are uploading and its tool appears.', 'songpress' ) . '</p>';
		$o  .= '<select id="spu-type" class="spu-sel"><option value="">' . esc_html__( 'Choose an upload…', 'songpress' ) . '</option>';
		foreach ( $types as $k => $label ) {
			$o .= '<option value="' . esc_attr( $k ) . '"' . selected( $type, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$o .= '</select>';

		$form = function ( $action, $nonce, $field, $accept, $btn, $extra = '' ) use ( $ret ) {
			return '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( $nonce, '_wpnonce', true, false )
				. '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="sp_return" value="' . $ret . '">' . $extra
				. '<input type="file" name="' . esc_attr( $field ) . '" accept="' . esc_attr( $accept ) . '" required> <button class="spe-save" type="submit">' . esc_html( $btn ) . '</button></form>';
		};
		$panel = function ( $k, $html ) use ( $type ) {
			return '<div class="spu-panel" data-t="' . esc_attr( $k ) . '"' . ( $type === $k ? '' : ' hidden' ) . '>' . $html . '</div>';
		};

		if ( Songpress_Caps::can( 'import' ) ) {
			$o .= $panel( 'works', '<p class="spe-help">' . esc_html__( 'Your old catalog.sqlite. You get a report first and nothing is written until you confirm. Run it again to bring the next batch.', 'songpress' ) . '</p>' . ( 'works' === $type ? Songpress_Screens::legacy( 'import' ) : '<p class="spe-help">' . esc_html__( 'Choose this upload to open the tool.', 'songpress' ) . '</p>' ) );
			$o .= $panel( 'photos', '<p class="spe-help">' . esc_html__( 'Choose catalog.sqlite to fill in photos and bios for people, bands and publishers. Photos you changed by hand are kept.', 'songpress' ) . '</p>' . $form( 'songpress_profiles_load', 'songpress_profiles_load', 'catalog', '.sqlite,.db,.sqlite3', __( 'Load photos', 'songpress' ) ) );
		}
		if ( Songpress_Caps::can( 'money' ) ) {
			$o .= $panel( 'catalog', '<p class="spe-help">' . esc_html__( 'Choose catalog.sqlite. Statements, payouts, streams, the notes, ratings, art projects, agreements and registrations come across (entries already here are kept).', 'songpress' ) . '</p>' . $form( 'songpress_money_import', 'songpress_money_import', 'catalog', '.sqlite,.db,.sqlite3', __( 'Load the catalog file', 'songpress' ) ) );
			$o .= $panel( 'dk_stats', '<p class="spe-help">' . esc_html__( 'In DistroKid, open your Stats page and save it as an HTML file (Save Page As). Days it covers are updated; other days are kept.', 'songpress' ) . '</p>' . $form( 'songpress_money_stats_import', 'songpress_money_stats_import', 'stats', '.html,.htm,text/html', __( 'Import daily stats', 'songpress' ) ) );
			foreach ( self::STATEMENTS as $k => $s ) {
				$extra = '<input type="hidden" name="src" value="' . esc_attr( $k ) . '"><label class="spu-q">' . esc_html__( 'Quarter (optional, like 2026 Q2)', 'songpress' ) . ' <input type="text" name="quarter" placeholder="2026 Q2" size="9"></label> ';
				$o    .= $panel( $k, '<p class="spe-help">' . esc_html( $s[2] ) . ' ' . esc_html__( 'Lines are matched to your songs by ISRC, then by title. For each quarter in the file, this replaces what this source held for that quarter. Writers share ASCAP, MLC and CCLI by their credit splits; DistroKid goes to the performers.', 'songpress' ) . '</p>' . $form( 'songpress_statement', 'songpress_statement', 'file', $s[1], __( 'Load statement', 'songpress' ), $extra ) );
			}
		}

		$o .= '<h3>' . esc_html__( 'Download', 'songpress' ) . '</h3><p class="spe-help">' . esc_html__( 'Choose a table and save it as a CSV for a spreadsheet.', 'songpress' ) . '</p>';
		$o .= '<form method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="spu-dl"><input type="hidden" name="action" value="songpress_download">' . wp_nonce_field( 'songpress_download', '_wpnonce', true, false ) . '<select name="table" class="spu-sel" required><option value="">' . esc_html__( 'Choose a table…', 'songpress' ) . '</option>';
		foreach ( self::DOWNLOADS as $k => $d ) {
			if ( Songpress_Caps::can( $d[1] ) ) {
				$o .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $d[0] ) . '</option>';
			}
		}
		$o .= '</select> <button class="spe-save" type="submit">' . esc_html__( 'Download CSV', 'songpress' ) . '</button></form>';
		$o .= '<script>(function(){var s=document.getElementById("spu-type");if(!s)return;function f(){document.querySelectorAll(".spu-panel").forEach(function(p){p.hidden=p.getAttribute("data-t")!==s.value;});}s.addEventListener("change",f);f();})();</script></div>';
		return $o;
	}
}
