<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registrations: for every work, where it stands at MLC, ASCAP, BMI, CCLI and for its ISWC.
 * Registered (green) when the work has the registry's code. In progress (gold) when a Manager marked it, with the
 * day it was started so waiting ones can be followed up. Otherwise not filed (red). Marking never changes the song.
 */
class Songpress_Registrations {

	const SOCIETIES = array(
		'mlc'   => array( 'MLC', array( 'mlc' ) ),
		'ascap' => array( 'ASCAP', array( 'ascap', 'work_id' ) ),
		'bmi'   => array( 'BMI', array( 'bmi' ) ),
		'ccli'  => array( 'CCLI', array( 'ccli' ) ),
		'iswc'  => array( 'ISWC', array( 'iswc' ) ),
	);

	public static function init() {
		add_action( 'admin_post_songpress_registration_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_songpress_registration_undated', array( __CLASS__, 'handle_undated' ) );
	}

	private static function t() {
		return Songpress_Install::table( 'registrations' );
	}

	/** red, gold or green. Pure. */
	public static function state( $has_code, $in_progress ) {
		return $has_code ? 'green' : ( $in_progress ? 'gold' : 'red' );
	}

	/** Whole days from a YYYY-MM-DD date to $now (a timestamp). Pure. */
	public static function days_since( $date, $now ) {
		$ts = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ? strtotime( $date . ' 00:00:00 UTC' ) : 0;
		return $ts ? max( 0, (int) floor( ( $now - $ts ) / 86400 ) ) : null;
	}

	/**
	 * One row per work family: its first song, every code any version has, the latest release date, and what is marked.
	 * Returns objects with ->family_id ->id ->serial ->title ->release ->codes (kind => value) ->marks (society => row).
	 */
	public static function works() {
		global $wpdb;
		$s  = Songpress_Install::table( 'songs' );
		$i  = Songpress_Install::table( 'identifiers' );
		$fam = array();
		foreach ( $wpdb->get_results( "SELECT id, family_id, version_no, serial, title, release_date, archived_at, ( chordpro IS NOT NULL AND TRIM(chordpro) <> '' ) AS has_cp FROM {$s} ORDER BY family_id, version_no" ) as $r ) { // phpcs:ignore WordPress.DB
			$f = (int) $r->family_id;
			if ( ! isset( $fam[ $f ] ) ) {
				$fam[ $f ] = (object) array( 'family_id' => $f, 'id' => 0, 'serial' => '', 'title' => '', 'release' => '', 'codes' => array(), 'marks' => array(), 'ids' => array(), 'has_cp' => false );
			}
			$fam[ $f ]->ids[ (int) $r->id ] = true; // archived versions still count for codes
			if ( empty( $r->archived_at ) ) {
				if ( ! $fam[ $f ]->id ) { // the first live version stands for the work
					$fam[ $f ]->id     = (int) $r->id;
					$fam[ $f ]->serial = $r->serial;
					$fam[ $f ]->title  = $r->title;
				}
				if ( (int) $r->has_cp ) {
					$fam[ $f ]->has_cp = true;
				}
				if ( '' !== (string) $r->release_date && (string) $r->release_date > $fam[ $f ]->release ) {
					$fam[ $f ]->release = (string) $r->release_date;
				}
			}
		}
		$fam = array_filter( $fam, function ( $w ) {
			return (bool) $w->id;
		} );
		$owner = array();
		foreach ( $fam as $f => $w ) {
			foreach ( array_keys( $w->ids ) as $sid ) {
				$owner[ $sid ] = $f;
			}
		}
		foreach ( $wpdb->get_results( "SELECT song_id, kind, value FROM {$i}" ) as $r ) { // phpcs:ignore WordPress.DB
			$f = $owner[ (int) $r->song_id ] ?? 0;
			if ( $f && '' !== trim( (string) $r->value ) && ! isset( $fam[ $f ]->codes[ $r->kind ] ) ) {
				$fam[ $f ]->codes[ $r->kind ] = trim( $r->value );
			}
		}
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . self::t() ) as $r ) { // phpcs:ignore WordPress.DB
			if ( isset( $fam[ (int) $r->family_id ] ) ) {
				$fam[ (int) $r->family_id ]->marks[ $r->society ] = $r;
			}
		}
		return array_values( $fam );
	}

	/** The two plain facts shown beside the registries: released (any version has a release date) and ChordPro (any version has a chord sheet). Green or red only. */
	public static function facts( $w ) {
		return array(
			'released' => array( __( 'Released', 'songpress' ), '' !== (string) $w->release ),
			'chordpro' => array( 'ChordPro', ! empty( $w->has_cp ) ),
		);
	}

	/** What a work looks like at one society: array(state, code, reference, days, note, started). Pure given a work. */
	public static function status( $w, $key, $now ) {
		$code = '';
		foreach ( self::SOCIETIES[ $key ][1] as $k ) {
			if ( isset( $w->codes[ $k ] ) ) {
				$code = $w->codes[ $k ];
				break;
			}
		}
		$m = $w->marks[ $key ] ?? null;
		return array(
			'state'   => self::state( '' !== $code, $m && (int) $m->in_progress ),
			'code'    => $code,
			'ref'     => $m ? (string) $m->reference : '',
			'note'    => $m ? (string) $m->note : '',
			'started' => ( $m && $m->started_on ) ? (string) $m->started_on : '',
			'days'    => ( $m && (int) $m->in_progress && $m->started_on ) ? self::days_since( $m->started_on, $now ) : null,
		);
	}

	/** Mark or clear one registry for a work family. Blank fields clear. Never touches the song. */
	public static function mark( $family_id, $key, $in_progress, $started, $reference, $note ) {
		global $wpdb;
		if ( ! isset( self::SOCIETIES[ $key ] ) ) {
			return;
		}
		$started = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $started ) ? $started : null;
		if ( $in_progress && ! $started ) {
			$started = gmdate( 'Y-m-d' );
		}
		$row = array( 'in_progress' => $in_progress ? 1 : 0, 'started_on' => $in_progress ? $started : null, 'reference' => mb_substr( trim( (string) $reference ), 0, 191 ), 'note' => (string) $note, 'updated_at' => current_time( 'mysql' ), 'updated_by' => get_current_user_id() );
		$t   = self::t();
		$id  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE family_id = %d AND society = %s", (int) $family_id, $key ) ); // phpcs:ignore WordPress.DB
		if ( $id ) {
			$wpdb->update( $t, $row, array( 'id' => (int) $id ) );
		} elseif ( $row['in_progress'] || '' !== $row['reference'] || '' !== trim( $row['note'] ) ) {
			$wpdb->insert( $t, array_merge( $row, array( 'family_id' => (int) $family_id, 'society' => $key ) ) );
		}
	}

	/* ---------- import ---------- */

	/** The old catalog's gold ("in progress") chips. Dates were not kept, so they start undated. */
	public static function import_pdo( PDO $pdo ) {
		global $wpdb;
		try {
			$rows = $pdo->query( "SELECT id, progress FROM song_catalog WHERE progress IS NOT NULL AND progress != ''" )->fetchAll( PDO::FETCH_ASSOC );
		} catch ( Exception $e ) {
			return 0;
		}
		$s   = Songpress_Install::table( 'songs' );
		$fam = array();
		foreach ( $wpdb->get_results( "SELECT family_id, legacy_id FROM {$s} WHERE legacy_id IS NOT NULL AND legacy_id > 0" ) as $r ) { // phpcs:ignore WordPress.DB
			$fam[ (int) $r->legacy_id ] = (int) $r->family_id;
		}
		$t = self::t();
		$n = 0;
		foreach ( $rows as $r ) {
			$f = $fam[ (int) $r['id'] ] ?? 0;
			$p = json_decode( (string) $r['progress'], true );
			if ( ! $f || ! is_array( $p ) ) {
				continue;
			}
			foreach ( $p as $key => $on ) {
				if ( ! $on || ! isset( self::SOCIETIES[ $key ] ) ) {
					continue;
				}
				if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE family_id = %d AND society = %s", $f, $key ) ) ) { // phpcs:ignore WordPress.DB
					continue; // already marked here; leave it
				}
				$wpdb->insert( $t, array( 'family_id' => $f, 'society' => $key, 'in_progress' => 1, 'started_on' => null, 'reference' => '', 'note' => '', 'updated_at' => current_time( 'mysql' ), 'updated_by' => 0 ) );
				$n++;
			}
		}
		return $n;
	}

	/** How many in-progress registrations have no start date (the old catalog did not keep them). */
	public static function undated_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t() . ' WHERE in_progress = 1 AND started_on IS NULL' ); // phpcs:ignore WordPress.DB
	}

	/** Give every undated in-progress registration one start date. Dated ones are never touched. Returns how many. */
	public static function date_undated( $date ) {
		global $wpdb;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
			return 0;
		}
		$n = self::undated_count();
		if ( $n ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . ' SET started_on = %s WHERE in_progress = 1 AND started_on IS NULL', $date ) ); // phpcs:ignore WordPress.DB
		}
		return $n;
	}

	public static function handle_undated() {
		check_admin_referer( 'songpress_registration_undated' );
		if ( ! Songpress_Caps::can( 'registrations' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		$n = self::date_undated( sanitize_text_field( wp_unslash( $_POST['started_on'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		wp_safe_redirect( add_query_arg( array( 'sp_page' => 'registrations', 'rf' => 'progress', 'r_ok' => rawurlencode( sprintf( /* translators: %d: number of registrations */ _n( 'Set the start date on %d registration.', 'Set the start date on %d registrations.', $n, 'songpress' ), $n ) ) ), Songpress_Editor::list_url() ) );
		exit;
	}

	/* ---------- handler ---------- */

	public static function handle_save() {
		check_admin_referer( 'songpress_registration_save' );
		if ( ! Songpress_Caps::can( 'registrations' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		$in  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- cleaned below.
		$fam = absint( $in['family_id'] ?? 0 );
		foreach ( array_keys( self::SOCIETIES ) as $k ) {
			self::mark( $fam, $k, ! empty( $in[ 'prog_' . $k ] ), sanitize_text_field( $in[ 'start_' . $k ] ?? '' ), sanitize_text_field( $in[ 'ref_' . $k ] ?? '' ), sanitize_textarea_field( $in[ 'note_' . $k ] ?? '' ) );
		}
		$ret = isset( $in['sp_return'] ) ? wp_validate_redirect( esc_url_raw( $in['sp_return'] ), '' ) : '';
		wp_safe_redirect( add_query_arg( array( 'sp_page' => 'registrations', 'r_ok' => rawurlencode( __( 'Saved.', 'songpress' ) ), 'rf' => sanitize_key( $in['rf'] ?? 'all' ) ), '' !== $ret ? $ret : Songpress_Editor::list_url() ) );
		exit;
	}

	/* ---------- screen ---------- */

	public static function render() {
		if ( ! Songpress_Caps::can( 'registrations' ) ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'Only Managers can open this screen.', 'songpress' ) . '</p></div>';
		}
		$now   = time();
		$base  = Songpress_Editor::list_url();
		$works = self::works();
		$rf    = isset( $_GET['rf'] ) ? sanitize_key( wp_unslash( $_GET['rf'] ) ) : 'progress'; // phpcs:ignore WordPress.Security.NonceVerification
		$rf    = in_array( $rf, array( 'progress', 'waiting', 'unfiled', 'all' ), true ) ? $rf : 'progress';
		$one   = isset( $_GET['work'] ) ? absint( $_GET['work'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $one ) {
			$rf    = 'all';
			$works = array_values( array_filter( $works, function ( $w ) use ( $one ) {
				return isset( $w->ids[ $one ] );
			} ) );
		}
		$tot   = array();
		foreach ( self::SOCIETIES as $k => $d ) {
			$tot[ $k ] = array( 'green' => 0, 'gold' => 0, 'red' => 0 );
		}
		$ftot = array( 'released' => array( 0, 0 ), 'chordpro' => array( 0, 0 ) );
		$rows = array();
		foreach ( $works as $w ) {
			foreach ( self::facts( $w ) as $fk => $fv ) {
				$ftot[ $fk ][ $fv[1] ? 0 : 1 ]++;
			}
			$st    = array();
			$gold  = false;
			$wait  = false;
			$unf   = false;
			foreach ( array_keys( self::SOCIETIES ) as $k ) {
				$st[ $k ] = self::status( $w, $k, $now );
				$tot[ $k ][ $st[ $k ]['state'] ]++;
				if ( 'gold' === $st[ $k ]['state'] ) {
					$gold = true;
					if ( null !== $st[ $k ]['days'] && $st[ $k ]['days'] >= 30 ) {
						$wait = true;
					}
				}
			}
			// released a while ago and no PRO (ASCAP or BMI) and no MLC even started
			$days = $w->release ? self::days_since( substr( $w->release, 0, 10 ), $now ) : null;
			if ( null !== $days && $days >= 14 && $days <= 365 && 'red' === $st['mlc']['state'] && 'red' === $st['ascap']['state'] && 'red' === $st['bmi']['state'] ) {
				$unf = true;
			}
			if ( 'all' === $rf || ( 'progress' === $rf && $gold ) || ( 'waiting' === $rf && $wait ) || ( 'unfiled' === $rf && $unf ) ) {
				$rows[] = array( $w, $st );
			}
		}
		$o = '<div class="songpress sp-app sp-editor sp-reg"><div class="spe-top"><h2>' . esc_html__( 'Registrations', 'songpress' ) . '</h2></div>';
		if ( ! empty( $_GET['r_ok'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<p class="spe-note ok">' . esc_html( sanitize_text_field( wp_unslash( $_GET['r_ok'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		$undated = self::undated_count();
		if ( $undated ) {
			$o .= '<form method="post" class="spr-undated" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_registration_undated', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_registration_undated"><p class="spe-help">' . esc_html( sprintf( /* translators: %d: number of registrations */ _n( '%d in-progress registration has no start date, so it never shows as waiting.', '%d in-progress registrations have no start date, so they never show as waiting.', $undated, 'songpress' ), $undated ) ) . '</p><label>' . esc_html__( 'Started on', 'songpress' ) . ' <input type="date" name="started_on" value="' . esc_attr( gmdate( 'Y-m-d' ) ) . '"></label> <button class="spe-btn" type="submit">' . esc_html__( 'Set for all undated', 'songpress' ) . '</button> <small class="spe-help">' . esc_html__( 'Dates you already entered are not changed. You can still set each one by hand below.', 'songpress' ) . '</small></form>';
		}
		if ( $one ) {
			$o .= '<p class="spe-help">' . esc_html__( 'Showing one work.', 'songpress' ) . ' <a class="spe-btn" href="' . esc_url( add_query_arg( 'sp_page', 'registrations', $base ) ) . '">' . esc_html__( 'Show all', 'songpress' ) . '</a></p>';
		}
		$o .= '<ul class="songpress-stats spr-tot">';
		foreach ( self::SOCIETIES as $k => $d ) {
			$o .= '<li>' . esc_html( $d[0] ) . '<strong><span class="spr-g">' . (int) $tot[ $k ]['green'] . '</span> · <span class="spr-y">' . (int) $tot[ $k ]['gold'] . '</span> · <span class="spr-r">' . (int) $tot[ $k ]['red'] . '</span></strong></li>';
		}
		foreach ( array( 'released' => __( 'Released', 'songpress' ), 'chordpro' => 'ChordPro' ) as $fk => $fl ) {
			$o .= '<li>' . esc_html( $fl ) . '<strong><span class="spr-g">' . (int) $ftot[ $fk ][0] . '</span> · <span class="spr-r">' . (int) $ftot[ $fk ][1] . '</span></strong></li>';
		}
		$o .= '</ul><p class="spe-help">' . esc_html__( 'Green: the work has the registry\'s code. Gold: you marked it in progress. Red: not filed. Released and ChordPro are green when any version of the work has a release date or a chord sheet, red when none does. The code on the song is never changed from here.', 'songpress' ) . '</p><p class="spr-tabs">';
		foreach ( array( 'progress' => __( 'In progress', 'songpress' ), 'waiting' => __( 'Waiting 30+ days', 'songpress' ), 'unfiled' => __( 'Released, not filed', 'songpress' ), 'all' => __( 'All works', 'songpress' ) ) as $k => $l ) {
			$o .= '<a class="spe-btn' . ( $k === $rf ? ' on' : '' ) . '" href="' . esc_url( add_query_arg( array( 'sp_page' => 'registrations', 'rf' => $k ), $base ) ) . '">' . esc_html( $l ) . '</a> ';
		}
		$o .= '</p>';
		if ( ! $rows ) {
			$o .= '<p class="spe-help">' . esc_html__( 'Nothing here.', 'songpress' ) . '</p>';
		}
		foreach ( array_slice( $rows, 0, 300 ) as $r ) {
			list( $w, $st ) = $r;
			$o .= '<details class="spr-row"' . ( $one ? ' open' : '' ) . '><summary><span class="spr-name"><b>' . esc_html( $w->serial ) . '</b> ' . esc_html( $w->title ) . '</span><span class="spr-chips">';
			foreach ( self::SOCIETIES as $k => $d ) {
				$s  = $st[ $k ];
				$o .= '<span class="spr-chip ' . esc_attr( $s['state'] ) . '" title="' . esc_attr( $s['code'] ) . '">' . esc_html( $d[0] ) . ( null !== $s['days'] ? ' ' . (int) $s['days'] . 'd' : '' ) . '</span>';
			}
			foreach ( self::facts( $w ) as $fv ) {
				$o .= '<span class="spr-chip ' . ( $fv[1] ? 'green' : 'red' ) . '">' . esc_html( $fv[0] ) . '</span>';
			}
			$o .= '</span></summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_registration_save', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_registration_save"><input type="hidden" name="family_id" value="' . (int) $w->family_id . '"><input type="hidden" name="rf" value="' . esc_attr( $rf ) . '"><input type="hidden" name="sp_return" value="' . esc_attr( $base ) . '">';
			foreach ( self::SOCIETIES as $k => $d ) {
				$s  = $st[ $k ];
				$o .= '<div class="spr-edit"><b>' . esc_html( $d[0] ) . '</b> ';
				if ( '' !== $s['code'] ) {
					$o .= '<span class="spr-chip green">' . esc_html( $s['code'] ) . '</span> ';
				}
				$o .= '<label><input type="checkbox" name="prog_' . esc_attr( $k ) . '" value="1"' . checked( 'gold' === $s['state'], true, false ) . '> ' . esc_html__( 'In progress', 'songpress' ) . '</label> <input type="date" name="start_' . esc_attr( $k ) . '" value="' . esc_attr( $s['started'] ) . '" aria-label="' . esc_attr__( 'Started on', 'songpress' ) . '"> <input name="ref_' . esc_attr( $k ) . '" value="' . esc_attr( $s['ref'] ) . '" placeholder="' . esc_attr__( 'Reference number', 'songpress' ) . '"> <input name="note_' . esc_attr( $k ) . '" value="' . esc_attr( $s['note'] ) . '" placeholder="' . esc_attr__( 'Note', 'songpress' ) . '"></div>';
			}
			$o .= '<button class="spe-save" type="submit">' . esc_html__( 'Save', 'songpress' ) . '</button> <a class="spe-btn" href="' . esc_url( Songpress_Editor::edit_url( (int) $w->id ) ) . '">' . esc_html__( 'Open song', 'songpress' ) . '</a></form></details>';
		}
		return $o . '</div>';
	}
}
