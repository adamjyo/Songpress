<?php
/**
 * Settings > Earnings: the rules that turn statements into what each person earned, shown and editable.
 * Rules live in one option (see Songpress_Money::rules()). A person's trace lists every song behind their total; DistroKid shares can be edited right there.
 */
defined( 'ABSPATH' ) || exit;

class Songpress_Earnings {

	public static function init() {
		foreach ( array( 'rules', 'share', 'rebuild' ) as $a ) {
			add_action( 'admin_post_songpress_earnings_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	private static function guard( $nonce ) {
		check_admin_referer( $nonce );
		if ( ! Songpress_Caps::can( 'settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
	}

	private static function go( $args = array() ) {
		$ret  = isset( $_POST['sp_return'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['sp_return'] ) ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked by guard().
		$base = '' !== $ret ? $ret : Songpress_Editor::list_url();
		wp_safe_redirect( add_query_arg( array_merge( array( 'sp_page' => 'earnings' ), $args ), $base ) );
		exit;
	}

	/* ---------- handlers ---------- */

	public static function handle_rules() {
		self::guard( 'songpress_earnings_rules' );
		$in  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- cleaned below.
		$old = Songpress_Money::rules();
		$new = array(
			'performer_default'    => max( 0, min( 100, round( (float) str_replace( '%', '', (string) ( $in['performer_default'] ?? 25 ) ), 2 ) ) ),
			'band_less_performers' => ! empty( $in['band_less_performers'] ) ? 1 : 0,
		);
		update_option( Songpress_Money::RULES_OPT, $new );
		$d = array();
		foreach ( array( 'performer_default' => 'performer share when none is set', 'band_less_performers' => 'band money less performers' ) as $k => $label ) {
			if ( (float) $old[ $k ] !== (float) $new[ $k ] ) {
				$d[ $label ] = array( $old[ $k ], $new[ $k ] );
			}
		}
		if ( $d ) {
			Songpress_Audit::log( 'money', 'Changed the earnings rules', '', $d );
		}
		self::go( array( 'm_ok' => __( 'Earnings rules saved. Band money uses them now; use Recalculate to apply them to DistroKid splits already stored.', 'songpress' ) ) );
	}

	public static function handle_share() {
		self::guard( 'songpress_earnings_share' );
		global $wpdb;
		$in    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- cleaned below.
		$song  = absint( $in['song_id'] ?? 0 );
		$who   = sanitize_text_field( $in['person'] ?? '' );
		$key   = Songpress_Money::person_key( $who );
		$raw   = trim( str_replace( '%', '', (string) ( $in['share'] ?? '' ) ) );
		$share = '' === $raw ? null : max( 0, min( 100, round( (float) $raw, 2 ) ) );
		$t     = Songpress_Install::table( 'credits' );
		$row   = null;
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE song_id = %d AND role = 'performer'", $song ) ) as $c ) { // phpcs:ignore WordPress.DB
			if ( Songpress_Money::person_key( $c->name ) === $key ) {
				$row = $c;
				break;
			}
		}
		if ( ! $song || ! $row ) {
			self::go( array( 'who' => $who, 'm_err' => __( 'That performer is not on the song any more.', 'songpress' ) ) );
		}
		$wpdb->update( $t, array( 'share' => $share ), array( 'id' => (int) $row->id ) );
		$n = Songpress_Uploads::rebuild_song_dk( $song );
		Songpress_Audit::log( 'money', 'Changed a performer share', $who, array( 'song' => (string) ( Songpress_Songs::get( $song )->title ?? $song ), 'share' => array( null === $row->share || '' === $row->share ? 'default' : $row->share . '%', null === $share ? 'default' : $share . '%' ), 'quarters redone' => $n ) );
		self::go( array( 'who' => $who, 'm_ok' => sprintf( /* translators: 1: person, 2: number of quarters */ __( 'Saved. %1$s’s DistroKid money for that song was worked out again for %2$d quarters.', 'songpress' ), $who, $n ) ) );
	}

	public static function handle_rebuild() {
		self::guard( 'songpress_earnings_rebuild' );
		$n = Songpress_Uploads::rebuild_all_people();
		Songpress_Audit::log( 'money', 'Recalculated splits', '', array( 'works' => $n ) );
		self::go( array( 'm_ok' => sprintf( /* translators: %d: songs */ _n( 'Worked out money again for %d work, every source and release.', 'Worked out money again for %d works, every source and release.', $n, 'songpress' ), $n ) ) );
	}

	/* ---------- the trace ---------- */

	/**
	 * Everything behind one person's total: rows of song, serial, src, quarters, earned; for DistroKid also the statement amount, the share applied and the stored credit.
	 * Plus band lines. Returns array( lines, bands, earned, paid ).
	 */
	public static function trace( $key ) {
		global $wpdb;
		$p     = Songpress_Install::table( 'person_earnings' );
		$e     = Songpress_Install::table( 'earnings' );
		$c     = Songpress_Install::table( 'credits' );
		$lines = array();
		$earn  = 0.0;
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT MIN(work_title) AS title, song_id, src, COUNT(DISTINCT quarter) AS quarters, SUM(amount) AS total FROM {$p} WHERE person_key = %s GROUP BY song_id, work_title, src", $key ) ) as $r ) { // phpcs:ignore WordPress.DB
			$line = array( 'title' => (string) $r->title, 'song_id' => (int) $r->song_id, 'src' => $r->src, 'quarters' => (int) $r->quarters, 'total' => (float) $r->total, 'statement' => null, 'credit' => null );
			if ( 'distrokid' === $r->src && $r->song_id > 0 ) {
				$st                = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(amount) FROM {$e} WHERE src = 'distrokid' AND song_id = %d AND quarter IN (SELECT DISTINCT quarter FROM {$p} WHERE person_key = %s AND song_id = %d AND src = 'distrokid')", $r->song_id, $key, $r->song_id ) ); // phpcs:ignore WordPress.DB
				$line['statement'] = $st;
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT name, share FROM {$c} WHERE song_id = %d AND role = 'performer'", $r->song_id ) ) as $cr ) { // phpcs:ignore WordPress.DB
					if ( Songpress_Money::person_key( $cr->name ) === $key ) {
						$line['credit'] = array( 'name' => $cr->name, 'share' => $cr->share );
					}
				}
			}
			$earn   += $line['total'];
			$lines[] = $line;
		}
		usort( $lines, function ( $a, $b ) {
			return $b['total'] <=> $a['total'];
		} );
		$bands = array();
		foreach ( Songpress_Money::band_detail() as $b ) {
			if ( '' === $b['payee'] || Songpress_Money::person_key( $b['payee'] ) !== $key ) {
				continue;
			}
			$g = $pf = $n = 0.0;
			foreach ( $b['rows'] as $cell ) {
				$g  += $cell['gross'];
				$pf += $cell['performers'];
				$n  += $cell['net'];
			}
			$bands[] = array( 'name' => $b['name'], 'gross' => $g, 'performers' => $pf, 'net' => $n );
			$earn   += $n;
		}
		$paid = Songpress_Money::paid_by_person();
		return array( 'lines' => $lines, 'bands' => $bands, 'earned' => $earn, 'paid' => (float) ( $paid[ $key ] ?? 0 ) );
	}

	/* ---------- screen ---------- */

	public static function render() {
		if ( ! Songpress_Caps::can( 'settings' ) ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'Only Managers can open this screen.', 'songpress' ) . '</p></div>';
		}
		$r    = Songpress_Money::rules();
		$f    = function ( $action, $class = '' ) {
			return Songpress_Money::form_open( $action, $class ) ;
		};
		$o    = '<div class="sp-app spm-earn"><h2>' . esc_html__( 'Earnings', 'songpress' ) . '</h2>' . Songpress_Money::notes();
		$o   .= '<p class="spe-help">' . esc_html__( 'How the money on each person’s card is worked out, and the numbers you can change.', 'songpress' ) . '</p>';
		$o   .= '<h3>' . esc_html__( 'Rules', 'songpress' ) . '</h3>' . $f( 'songpress_earnings_rules', 'spm-rules' ) . '<div class="spe-grid">'
			. '<label class="spe-field"><span>' . esc_html__( 'Performer share when a song does not set one (%)', 'songpress' ) . '</span><input name="performer_default" inputmode="decimal" value="' . esc_attr( rtrim( rtrim( number_format( (float) $r['performer_default'], 2, '.', '' ), '0' ), '.' ) ) . '"></label>'
			. '<label class="spe-field spe-check"><input type="checkbox" name="band_less_performers" value="1"' . ( $r['band_less_performers'] ? ' checked' : '' ) . '> <span>' . esc_html__( 'A band’s payee gets the band’s DistroKid money less its performers’ shares', 'songpress' ) . '</span></label></div>'
			. '<button class="spe-save" type="submit">' . esc_html__( 'Save rules', 'songpress' ) . '</button></form>';
		$o   .= '<h3>' . esc_html__( 'The calculations', 'songpress' ) . '</h3><ol class="spm-how">'
			. '<li>' . esc_html__( 'MLC, ASCAP and CCLI: each person earns what the statement credits them. Nothing is calculated.', 'songpress' ) . '</li>'
			. '<li>' . esc_html( sprintf( /* translators: %s: percent */ __( 'DistroKid: a performer earns the statement amount for a song × their performer share (%s%% when the song sets none). If the shares add to more than 100%% they are scaled down.', 'songpress' ), rtrim( rtrim( number_format( (float) $r['performer_default'], 2, '.', '' ), '0' ), '.' ) ) ) . '</li>'
			. '<li>' . esc_html( $r['band_less_performers'] ? __( 'Bands: the band’s DistroKid money less its performers’ shares goes to the band’s payee, shown as Band.', 'songpress' ) : __( 'Bands: all of the band’s DistroKid money goes to the band’s payee, shown as Band.', 'songpress' ) ) . '</li>'
			. '<li>' . esc_html__( 'Paid: payments you record add up per person. A negative payment, such as a correction, lowers what counts as paid.', 'songpress' ) . '</li>'
			. '<li>' . esc_html__( 'Owed = earned − paid.', 'songpress' ) . '</li></ol>'
			. $f( 'songpress_earnings_rebuild', 'spm-rules' ) . '<p class="spe-help">' . esc_html__( 'Work out every work’s money again for its writers and performers (DistroKid, ASCAP, MLC and CCLI, every release) with the shares as they are now. Works with nobody credited are left alone.', 'songpress' ) . '</p><button class="spe-btn" type="submit">' . esc_html__( 'Recalculate DistroKid splits', 'songpress' ) . '</button></form>';

		// people for the picker
		global $wpdb;
		$names = array();
		foreach ( (array) $wpdb->get_col( 'SELECT DISTINCT person FROM ' . Songpress_Install::table( 'person_earnings' ) . " WHERE person <> ''" ) as $n ) { // phpcs:ignore WordPress.DB
			$names[ Songpress_Money::person_key( $n ) ] = $n;
		}
		foreach ( Songpress_Money::band_rows() as $b ) {
			$names[ $b->person_key ] = $names[ $b->person_key ] ?? $b->person;
		}
		asort( $names );
		$who = sanitize_text_field( wp_unslash( $_GET['who'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$o  .= '<h3>' . esc_html__( 'Check a person', 'songpress' ) . '</h3><form method="get" class="spm-pick" action="' . esc_url( Songpress_Editor::list_url() ) . '"><input type="hidden" name="sp_page" value="earnings"><input name="who" list="spm-earn-people" value="' . esc_attr( $who ) . '" placeholder="' . esc_attr__( 'Pick a person', 'songpress' ) . '" autocomplete="off"><datalist id="spm-earn-people">';
		foreach ( $names as $n ) {
			$o .= '<option value="' . esc_attr( $n ) . '">';
		}
		$o .= '</datalist> <button class="spe-save" type="submit">' . esc_html__( 'Show', 'songpress' ) . '</button></form>';
		$key = Songpress_Money::person_key( $who );
		if ( '' !== $key ) {
			$o .= self::trace_html( $who, $key );
		}
		return $o . '</div>';
	}

	private static function trace_html( $who, $key ) {
		$t = self::trace( $key );
		if ( ! $t['lines'] && ! $t['bands'] ) {
			return '<p class="spe-help">' . esc_html( sprintf( /* translators: %s: person */ __( 'Nothing earned by %s yet.', 'songpress' ), $who ) ) . '</p>';
		}
		$o = '<ul class="spm-sum"><li><span>' . esc_html__( 'Earned', 'songpress' ) . '</span><b>' . esc_html( Songpress_Money::money( $t['earned'] ) ) . '</b></li><li><span>' . esc_html__( 'Paid', 'songpress' ) . '</span><b>' . esc_html( Songpress_Money::money( $t['paid'] ) ) . '</b></li><li class="spm-due"><span>' . esc_html__( 'Owed', 'songpress' ) . '</span><b>' . esc_html( Songpress_Money::money( $t['earned'] - $t['paid'] ) ) . '</b></li></ul>';
		$o .= '<div class="spm-vscroll spm-trace"><table class="spm-bq"><thead><tr><th>' . esc_html__( 'Song', 'songpress' ) . '</th><th>' . esc_html__( 'From', 'songpress' ) . '</th><th>' . esc_html__( 'Statement', 'songpress' ) . '</th><th>' . esc_html__( 'Share', 'songpress' ) . '</th><th>' . esc_html__( 'Earned', 'songpress' ) . '</th></tr></thead><tbody>';
		foreach ( $t['lines'] as $l ) {
			$share = '<span class="spe-help">' . esc_html__( 'on the statement', 'songpress' ) . '</span>';
			$stmt  = '';
			if ( null !== $l['statement'] ) {
				$stmt  = esc_html( Songpress_Money::money( $l['statement'] ) );
				$cr    = $l['credit'];
				$share = Songpress_Money::form_open( 'songpress_earnings_share', 'spm-sharef' ) . '<input type="hidden" name="song_id" value="' . (int) $l['song_id'] . '"><input type="hidden" name="person" value="' . esc_attr( $who ) . '">'
					. '<input name="share" inputmode="decimal" size="4" value="' . esc_attr( $cr && null !== $cr['share'] && '' !== $cr['share'] ? (string) (float) $cr['share'] : '' ) . '" placeholder="' . esc_attr( rtrim( rtrim( number_format( (float) Songpress_Money::rules()['performer_default'], 2, '.', '' ), '0' ), '.' ) ) . '" aria-label="' . esc_attr__( 'Performer share in percent', 'songpress' ) . '">%'
					. ' <button class="spe-save" type="submit">' . esc_html__( 'Save', 'songpress' ) . '</button></form>';
			}
			$o .= '<tr><td>' . esc_html( $l['title'] ) . ' <small>' . esc_html( sprintf( /* translators: %d: quarters */ _n( '%d quarter', '%d quarters', $l['quarters'], 'songpress' ), $l['quarters'] ) ) . '</small></td><td>' . esc_html( Songpress_Money::src( $l['src'] ) ) . '</td><td>' . $stmt . '</td><td>' . $share . '</td><td><b>' . esc_html( Songpress_Money::money( $l['total'] ) ) . '</b></td></tr>';
		}
		foreach ( $t['bands'] as $b ) {
			$o .= '<tr><td>' . esc_html( $b['name'] ) . ' <small>' . esc_html__( 'band', 'songpress' ) . '</small></td><td>' . esc_html__( 'Band', 'songpress' ) . '</td><td>' . esc_html( Songpress_Money::money( $b['gross'] ) ) . '</td><td><span class="spe-help">' . esc_html( sprintf( /* translators: %s: amount */ __( 'less performers %s', 'songpress' ), Songpress_Money::money( $b['performers'] ) ) ) . '</span></td><td><b>' . esc_html( Songpress_Money::money( $b['net'] ) ) . '</b></td></tr>';
		}
		return $o . '</tbody></table></div><p class="spe-help">' . esc_html__( 'A blank share uses the default above. Saving a share works that song’s DistroKid money out again for every quarter. Band payees are set under Bands in Money.', 'songpress' ) . '</p>';
	}
}
