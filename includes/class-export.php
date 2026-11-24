<?php
/**
 * Printable exports: one work as a page (title, writers and splits, publishers, codes, lyrics, releases), and the works list as a table.
 * Both open as a clean page with a Print / Save PDF button, the same way the chord sheet does, so no PDF library is needed.
 */
defined( 'ABSPATH' ) || defined( 'SONGPRESS_TESTING' ) || exit;

class Songpress_Export {

	const LIMIT = 500;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'page' ), 1 );
	}

	/** The address of an export: kind 'work' (with song) or 'list' (with the current filters, and ids if some were ticked). */
	public static function url( $kind, array $args = array() ) {
		$args['sp_export'] = $kind;
		$args['_wpnonce']  = wp_create_nonce( 'songpress_export' );
		return add_query_arg( $args, home_url( '/' ) );
	}

	public static function page() {
		if ( ! isset( $_GET['sp_export'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! is_user_logged_in() || ! Songpress_Caps::can_view() || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'songpress_export' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		if ( 'work' === sanitize_key( wp_unslash( $_GET['sp_export'] ) ) ) {
			$ids = array( absint( $_GET['song'] ?? 0 ) );
			echo self::shell( self::works_html( $ids ), 'portrait', __( 'Work', 'songpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		} else {
			echo self::shell( self::list_html(), 'landscape', __( 'Works list', 'songpress' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		exit;
	}

	/** Lyrics for a song: the lyrics level, else chords and lyrics, else the ChordPro text with its chords and directives taken off. */
	public static function lyrics_of( $s ) {
		foreach ( array( 'lyrics', 'chords_lyrics' ) as $f ) {
			if ( '' !== trim( (string) $s->$f ) ) {
				return trim( (string) $s->$f );
			}
		}
		$cp = (string) $s->chordpro;
		if ( '' === trim( $cp ) ) {
			return '';
		}
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $cp ) as $line ) {
			$t = trim( $line );
			if ( '' !== $t && '#' === $t[0] ) {
				continue;
			}
			if ( preg_match( '/^\{\s*(?:c|comment|soc|start_of_chorus|sov|start_of_verse|sob|start_of_bridge)\b[^}:]*:?\s*([^}]*)\}$/i', $t, $m ) ) {
				if ( '' !== trim( $m[1] ) ) {
					$out[] = '[' . trim( $m[1] ) . ']';
				}
				continue;
			}
			if ( preg_match( '/^\{.*\}$/', $t ) ) {
				continue;
			}
			$out[] = preg_replace( '/\[[^\]]*\]/', '', $line );
		}
		return trim( implode( "\n", $out ) );
	}

	private static function credit_lines( $song_id, $role ) {
		$o = array();
		foreach ( Songpress_Songs::credits( (int) $song_id ) as $c ) {
			if ( $c->role !== $role || '' === trim( (string) $c->name ) ) {
				continue;
			}
			$share = ( null !== $c->share && '' !== (string) $c->share ) ? rtrim( rtrim( number_format( (float) $c->share, 4, '.', '' ), '0' ), '.' ) . '%' : '';
			$o[]   = array( (string) $c->name, $share );
		}
		return $o;
	}

	private static function code( $song_id, $kind ) {
		foreach ( Songpress_Songs::identifiers( (int) $song_id ) as $i ) {
			if ( strtolower( $i->kind ) === $kind && '' !== trim( (string) $i->value ) ) {
				return (string) $i->value;
			}
		}
		return '';
	}

	/** The family root of a song (or the song itself when there is none). */
	private static function root_of( $s ) {
		global $wpdb;
		if ( 0 === (int) $s->version_no ) {
			return $s;
		}
		$t = Songpress_Install::table( 'songs' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE family_id = %d AND version_no = 0", (int) $s->family_id ) );
		return $r ? $r : $s;
	}

	/** One printable page per work. */
	public static function works_html( array $ids ) {
		global $wpdb;
		$t   = Songpress_Install::table( 'songs' );
		$out = '';
		$seen = array();
		foreach ( $ids as $id ) {
			if ( ! $id || ! Songpress_Caps::can_see_song( (int) $id ) ) {
				continue;
			}
			$s = Songpress_Songs::get( (int) $id );
			if ( ! $s ) {
				continue;
			}
			$r = self::root_of( $s );
			if ( isset( $seen[ (int) $r->id ] ) ) {
				continue;
			}
			$seen[ (int) $r->id ] = 1;
			$out .= self::work_page( $r, (int) $s->id );
		}
		return '' === $out ? '<p>' . esc_html__( 'Nothing to show.', 'songpress' ) . '</p>' : $out;
	}

	private static function work_page( $r, $asked_id ) {
		global $wpdb;
		$t     = Songpress_Install::table( 'songs' );
		$meta  = array_filter( array( trim( (string) $r->genre ), '' !== trim( (string) $r->musical_key ) ? sprintf( /* translators: %s: musical key */ __( 'Key of %s', 'songpress' ), trim( (string) $r->musical_key ) ) : '', trim( (string) $r->time_signature ), '' !== trim( (string) $r->tempo ) ? trim( (string) $r->tempo ) . ' BPM' : '', trim( (string) ( $r->work_type ?? '' ) ) ), 'strlen' );
		$status = Songpress_Songs::effective_status( $r );
		$o  = '<section class="work"><header><div class="no">' . esc_html( $r->serial ) . '</div><div class="hd"><h1>' . esc_html( Songpress_Songs::display_title( $r ) ) . ( '' !== $status ? '<span class="tag">' . esc_html( $status ) . '</span>' : '' ) . '</h1><div class="meta">' . esc_html( implode( ' · ', $meta ) ) . '</div></div></header>';
		$o .= '<div class="cols"><aside>';
		$w  = self::credit_lines( $r->id, 'writer' );
		$o .= '<p class="lab">' . esc_html__( 'Writers and splits', 'songpress' ) . '</p>';
		if ( $w ) {
			$o .= '<table class="kv">';
			foreach ( $w as $c ) {
				$o .= '<tr><td>' . esc_html( $c[0] ) . '</td><td class="r">' . esc_html( $c[1] ) . '</td></tr>';
			}
			$o .= '</table>';
		} else {
			$o .= '<p class="mut">&mdash;</p>';
		}
		$pub = self::credit_lines( $r->id, 'publisher' );
		$o  .= '<p class="lab">' . esc_html__( 'Publishers', 'songpress' ) . '</p>';
		if ( $pub ) {
			$o .= '<table class="kv">';
			foreach ( $pub as $c ) {
				$o .= '<tr><td>' . esc_html( $c[0] ) . '</td><td class="r">' . esc_html( $c[1] ) . '</td></tr>';
			}
			$o .= '</table>';
		} else {
			$o .= '<p class="mut">&mdash;</p>';
		}
		$codes = '';
		foreach ( Songpress_Songs::identifiers( (int) $r->id ) as $i ) {
			if ( in_array( strtolower( $i->kind ), array( 'isrc', 'upc', 'distrokid' ), true ) || '' === trim( (string) $i->value ) ) {
				continue; // these belong to a release
			}
			$codes .= '<tr><td class="mut">' . esc_html( strtoupper( $i->kind ) ) . '</td><td>' . esc_html( $i->value ) . '</td></tr>';
		}
		$o .= '<p class="lab">' . esc_html__( 'Codes', 'songpress' ) . '</p>' . ( '' !== $codes ? '<table class="kv">' . $codes . '</table>' : '<p class="mut">&mdash;</p>' );
		$o .= '</aside><div class="main"><p class="lab">' . esc_html__( 'Lyrics', 'songpress' ) . '</p>';
		$ly = self::lyrics_of( $r );
		if ( '' === $ly ) { // the original may be empty while a version holds the words
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE family_id = %d AND version_no > 0 ORDER BY version_no", (int) $r->family_id ) ) as $v ) {
				$ly = self::lyrics_of( $v );
				if ( '' !== $ly ) {
					break;
				}
			}
		}
		$o .= '' !== $ly ? '<div class="ly">' . nl2br( esc_html( $ly ) ) . '</div>' : '<p class="mut">&mdash;</p>';
		$o .= '</div></div>';
		// Releases: every released version (an ISRC, or a Released status).
		$rows = '';
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE family_id = %d AND version_no > 0 ORDER BY version_no", (int) $r->family_id ) ) as $v ) {
			$isrc = self::code( $v->id, 'isrc' );
			if ( '' === $isrc && 'released' !== strtolower( (string) $v->status ) && 'released' !== strtolower( trim( (string) $v->stage ) ) ) {
				continue;
			}
			$rows .= '<tr' . ( (int) $v->id === $asked_id ? ' class="me"' : '' ) . '><td>' . esc_html( $v->serial ) . '</td><td>' . esc_html( '' !== trim( (string) $v->release_title ) ? $v->release_title : Songpress_Songs::display_title( $v ) ) . '</td><td>' . esc_html( (string) ( $v->work_type ?? '' ) ) . '</td><td>' . esc_html( $v->artist ) . '</td><td>' . esc_html( $v->album ) . '</td><td>' . esc_html( $v->release_date ) . '</td><td>' . esc_html( $isrc ) . '</td><td>' . esc_html( self::code( $v->id, 'upc' ) ) . '</td></tr>';
		}
		$o .= '<div class="rel"><p class="lab">' . esc_html__( 'Releases', 'songpress' ) . '</p>';
		$o .= '' !== $rows ? '<table class="grid"><thead><tr><th>' . esc_html__( 'No.', 'songpress' ) . '</th><th>' . esc_html__( 'Release', 'songpress' ) . '</th><th>' . esc_html__( 'Type', 'songpress' ) . '</th><th>' . esc_html__( 'Band', 'songpress' ) . '</th><th>' . esc_html__( 'Album', 'songpress' ) . '</th><th>' . esc_html__( 'Date', 'songpress' ) . '</th><th>ISRC</th><th>UPC</th></tr></thead><tbody>' . $rows . '</tbody></table>' : '<p class="mut">' . esc_html__( 'No releases yet.', 'songpress' ) . '</p>';
		return $o . '</div></section>';
	}

	/** The works list as a table: the songs the person is looking at (same search, status and sort), or the ticked ones. */
	public static function list_html() {
		$uid    = get_current_user_id();
		$q      = isset( $_GET['sp_q'] ) ? sanitize_text_field( wp_unslash( $_GET['sp_q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$status = isset( $_GET['sp_status'] ) ? sanitize_key( wp_unslash( $_GET['sp_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$status = isset( Songpress_Songs::statuses()[ $status ] ) ? $status : '';
		list( $order, $dir ) = Songpress_Steps::sort();
		$ids = array();
		if ( ! empty( $_GET['ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$ids = array_slice( array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) ) ), 0, self::LIMIT );
		}
		global $wpdb;
		$t    = Songpress_Install::table( 'songs' );
		$rows = array();
		$mine = isset( $_GET['mine'] ); // phpcs:ignore WordPress.Security.NonceVerification -- the page checks its nonce; this only picks the person's own works.
		if ( $mine ) { // everything the signed-in person is credited on, whatever else the address says
			$u    = wp_get_current_user();
			$rows = Songpress_Songs::credited_rows( (int) $u->ID, (string) $u->display_name, true );
			usort( $rows, function ( $a, $b ) {
				$d = (int) $a->family_id <=> (int) $b->family_id;
				return 0 !== $d ? $d : (int) $a->version_no <=> (int) $b->version_no;
			} );
			$rows  = array_slice( $rows, 0, self::LIMIT );
			$total = count( $rows );
		} elseif ( $ids ) {
			$ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE id IN ({$ph}) ORDER BY CAST(TRIM(serial) AS UNSIGNED), version_no", $ids ) ); // phpcs:ignore WordPress.DB
			$rows = array_values( array_filter( $rows, function ( $s ) {
				return Songpress_Caps::can_see_song( (int) $s->id );
			} ) );
			$total = count( $rows );
		} else {
			$res   = Songpress_Songs::query( array( 'search' => $q, 'status' => $status, 'per_page' => self::LIMIT, 'order' => $order, 'dir' => $dir, 'user_id' => Songpress_Caps::can( 'view_all' ) ? 0 : $uid ) );
			$rows  = $res['rows'];
			$total = (int) $res['total'];
		}
		$who = $mine ? wp_get_current_user()->display_name : '';
		$o  = '<header class="lh"><div><h1>' . esc_html( $mine && '' !== $who ? $who : get_bloginfo( 'name' ) ) . '</h1><div class="meta">' . esc_html( sprintf( /* translators: 1: songs shown, 2: songs in the list, 3: date */ ( $mine ? __( 'My catalog · %1$s of %2$s works · exported %3$s', 'songpress' ) : __( 'Works list · %1$s of %2$s works · exported %3$s', 'songpress' ) ), number_format_i18n( count( $rows ) ), number_format_i18n( $total ), wp_date( get_option( 'date_format' ) ) ) ) . '</div></div></header>';
		$o .= '<table class="grid list"><thead><tr><th>' . esc_html__( 'No.', 'songpress' ) . '</th><th>' . esc_html__( 'Title', 'songpress' ) . '</th><th>' . esc_html__( 'Writers', 'songpress' ) . '</th><th>' . esc_html__( 'Publishers', 'songpress' ) . '</th><th>' . esc_html__( 'Band and album', 'songpress' ) . '</th><th>' . esc_html__( 'Release date', 'songpress' ) . '</th><th>' . esc_html__( 'Codes', 'songpress' ) . '</th><th>' . esc_html__( 'Status', 'songpress' ) . '</th></tr></thead><tbody>';
		$people = function ( $song_id, $role ) {
			$l = array();
			foreach ( self::credit_lines( $song_id, $role ) as $c ) {
				$l[] = esc_html( $c[0] ) . ( '' !== $c[1] ? ' <span class="mut">' . esc_html( $c[1] ) . '</span>' : '' );
			}
			return implode( '<br>', $l );
		};
		foreach ( $rows as $s ) {
			$title = '' !== trim( (string) $s->release_title ) ? $s->release_title : Songpress_Songs::display_title( $s );
			$sub   = implode( ' · ', array_filter( array( trim( (string) $s->genre ), trim( (string) ( $s->work_type ?? '' ) ), trim( (string) $s->musical_key ) ? sprintf( /* translators: %s: musical key */ __( 'Key of %s', 'songpress' ), trim( (string) $s->musical_key ) ) : '', trim( (string) $s->time_signature ) ), 'strlen' ) );
			$codes = array();
			foreach ( Songpress_Songs::identifiers( (int) $s->id ) as $i ) {
				if ( '' !== trim( (string) $i->value ) ) {
					$codes[] = '<span class="mut">' . esc_html( strtoupper( $i->kind ) ) . '</span> ' . esc_html( $i->value );
				}
			}
			$band = esc_html( $s->artist ) . ( '' !== trim( (string) $s->album ) ? ( '' !== trim( (string) $s->artist ) ? '<br>' : '' ) . '<span class="mut">' . esc_html( $s->album ) . '</span>' : '' );
			$o .= '<tr><td class="num">' . esc_html( $s->serial ) . '</td><td><span class="b">' . esc_html( $title ) . '</span>' . ( '' !== $sub ? '<br><span class="mut">' . esc_html( $sub ) . '</span>' : '' ) . '</td><td>' . $people( $s->id, 'writer' ) . '</td><td>' . $people( $s->id, 'publisher' ) . '</td><td>' . $band . '</td><td class="num">' . esc_html( $s->release_date ) . '</td><td class="codes">' . implode( '<br>', $codes ) . '</td><td>' . esc_html( Songpress_Songs::effective_status( $s ) ) . '</td></tr>';
		}
		$o .= '</tbody></table>';
		if ( $total > count( $rows ) && ! $ids && ! $mine ) {
			$o .= '<p class="mut note">' . esc_html( sprintf( /* translators: %d: number of works */ __( 'Showing the first %d works. Narrow the list with a search or tick songs to export the rest.', 'songpress' ), self::LIMIT ) ) . '</p>';
		}
		return $o;
	}

	private static function shell( $body, $orient, $title ) {
		$css = '*{box-sizing:border-box}body{margin:0;font:12px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;color:#1d1d1f;background:#f2f2f0}'
			. '.bar{position:sticky;top:0;background:#1d1d1f;color:#fff;padding:10px 20px;display:flex;gap:14px;align-items:center}.bar button{font:inherit;font-weight:600;padding:7px 16px;border:0;border-radius:6px;background:#f5a524;color:#1d1d1f;cursor:pointer}.bar span{opacity:.7}'
			. '.sheet{background:#fff;margin:18px auto;padding:36px 44px;max-width:' . ( 'landscape' === $orient ? '1100px' : '820px' ) . ';box-shadow:0 1px 6px rgba(0,0,0,.15)}'
			. '.work{page-break-after:always;break-after:page;margin-bottom:30px}.work:last-child{page-break-after:auto;break-after:auto}'
			. 'header{display:flex;justify-content:space-between;align-items:baseline;gap:20px;border-bottom:2px solid #1d1d1f;padding-bottom:10px}.no{font-size:11px;color:#777;font-variant-numeric:tabular-nums;width:60px}.hd{flex:1}'
			. 'h1{font-size:22px;margin:0;letter-spacing:-.01em}.meta{color:#777;font-size:11px;margin-top:3px}.tag{display:inline-block;font-size:8px;text-transform:uppercase;letter-spacing:.05em;border:1px solid #d28a14;color:#b36f00;border-radius:99px;padding:0 7px;margin-left:8px;vertical-align:middle}'
			. '.cols{display:grid;grid-template-columns:200px 1fr;gap:30px;margin-top:20px}.lab{font-size:8.5px;text-transform:uppercase;letter-spacing:.08em;color:#999;margin:14px 0 5px}.cols aside .lab:first-child,.main .lab:first-child{margin-top:0}'
			. 'table{border-collapse:collapse;width:100%}.kv td{padding:2px 0;vertical-align:top}.r{text-align:right}.mut{color:#777}.ly{font-size:12px;line-height:1.65}'
			. '.rel{margin-top:26px;border-top:1px solid #e6e6e6;padding-top:6px}.grid{font-size:10.5px}.grid th{text-align:left;font-size:8.5px;text-transform:uppercase;letter-spacing:.06em;color:#888;font-weight:500;border-bottom:1px solid #ddd;padding:5px 6px}.grid td{padding:5px 6px;vertical-align:top}.grid tbody tr:nth-child(even){background:#f6f6f4}.grid tr.me td{font-weight:600}'
			. '.list{margin-top:12px}.list td{font-size:10px}.codes{font-variant-numeric:tabular-nums;white-space:nowrap}.num{color:#666;font-variant-numeric:tabular-nums;white-space:nowrap}.b{font-weight:600}.note{margin-top:12px}'
			. '@media(max-width:700px){.sheet{padding:16px;overflow-x:auto}.cols{grid-template-columns:1fr}}@page{size:' . ( 'landscape' === $orient ? 'letter landscape' : 'letter' ) . ';margin:14mm}@media print{body{background:#fff}.bar{display:none}.sheet{margin:0;padding:0;box-shadow:none;max-width:none}.grid thead{display:table-header-group}tr{break-inside:avoid}}';
		return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html( $title . ' · ' . get_bloginfo( 'name' ) ) . '</title><style>' . $css . '</style></head><body>'
			. '<div class="bar"><button type="button" onclick="window.print()">' . esc_html__( 'Print / Save PDF', 'songpress' ) . '</button><span>' . esc_html__( 'Choose Save as PDF in the print window.', 'songpress' ) . '</span></div><div class="sheet">' . $body . '</div></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above and in the builders.
	}
}
