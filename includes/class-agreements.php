<?php
defined( 'ABSPATH' ) || exit;

/**
 * Agreements: a cowriter split confirmation or a publishing agreement for one song. Every signer gets a
 * private link they open without logging in, type their name and tick a box. Nobody is emailed: a Manager
 * copies each link and sends it. Links made by the old plugin (?swp_sign= and ?swp_agreement=) keep working here.
 */
class Songpress_Agreements {

	const KINDS = array( 'split' => 'Cowriter split confirmation', 'publishing' => 'Publishing agreement' );

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'public_pages' ), 1 );
		add_action( 'admin_post_songpress_agreement_create', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_songpress_agreement_delete', array( __CLASS__, 'handle_delete' ) );
	}

	private static function t( $n = 'agreements' ) {
		return Songpress_Install::table( $n );
	}

	/* ---------- text (pure) ---------- */

	private static function pct( $s ) {
		$s = trim( (string) $s );
		return '' === $s ? 'n/a' : ( false === strpos( $s, '%' ) ? $s . '%' : $s );
	}

	/**
	 * The agreement text. $f has: serial, title, alt, company, publisher, publisher_ipi, iswc, copyright,
	 * writers (name, ipi, share), date. Pure, so it can be tested.
	 */
	public static function build_body( $kind, array $f ) {
		$pub   = '' !== trim( (string) ( $f['publisher'] ?? '' ) ) ? $f['publisher'] : $f['company'];
		$lines = array( 'publishing' === $kind ? 'PUBLISHING AGREEMENT' : 'COWRITER SPLIT CONFIRMATION', '' );
		$lines[] = 'Catalog: ' . $f['serial'];
		$lines[] = 'Title: ' . $f['title'];
		if ( ! empty( $f['alt'] ) ) {
			$lines[] = 'Alternate titles: ' . $f['alt'];
		}
		$lines[] = 'Publisher of record: ' . $pub . ( ! empty( $f['publisher_ipi'] ) ? ' (IPI ' . $f['publisher_ipi'] . ')' : '' );
		if ( ! empty( $f['iswc'] ) ) {
			$lines[] = 'ISWC: ' . $f['iswc'];
		}
		if ( ! empty( $f['copyright'] ) ) {
			$lines[] = 'Copyright registration: ' . $f['copyright'];
		}
		$lines[] = '';
		$lines[] = 'Writers and composition ownership (must total 100%):';
		foreach ( $f['writers'] as $w ) {
			$lines[] = '- ' . $w['name'] . ( ! empty( $w['ipi'] ) ? ' IPI ' . $w['ipi'] : '' ) . ' — writer share ' . self::pct( $w['share'] ?? '' );
		}
		$lines[] = '';
		if ( 'publishing' === $kind ) {
			$lines[] = 'Grant. Each writer grants to ' . $pub . ' the publisher’s share of the composition described above, for the purpose of registering, licensing, and collecting on the work.';
			$lines[] = 'Writer share. Each writer keeps their writer’s share at their PRO. This grant is of the publisher’s share only unless a later contract says otherwise.';
			$lines[] = 'Registration and collection. The publisher may register the work with PROs and mechanical societies and collect the publisher’s share according to the splits above.';
			$lines[] = 'Accountings. The publisher will account to the writers for publisher income on this work at least annually, and will not recoup costs from a writer’s writer-share unless a separate signed deal says so.';
			$lines[] = 'No work made for hire. This agreement does not treat the composition as a work made for hire.';
			$lines[] = 'Not exclusive worldwide in perpetuity unless the parties initial a separate schedule. Default term is administration of this work until a written termination.';
			$lines[] = 'This is an organizational publishing grant generated from the catalog. It is not a substitute for counsel. Local law controls.';
		} else {
			$lines[] = 'The undersigned confirm that they are the writers of this musical work and that writer ownership of the composition is as listed above.';
			$lines[] = 'Each writer authorizes registration of the work with performing-right and mechanical societies under these names, IPIs, and splits. No writer will register a conflicting split.';
			$lines[] = 'Performance and mechanical royalties follow the registered splits. No writer collects another writer’s share.';
			$lines[] = 'This confirmation does not assign copyright to a publisher. A separate publishing agreement is required to grant a publisher share, administration rights, or an exclusive term.';
			$lines[] = 'This record is for organization. It is not a substitute for counsel or a society contract.';
		}
		$lines[] = 'Company: ' . $f['company'];
		$lines[] = 'Created: ' . $f['date'];
		return implode( "\n", $lines );
	}

	/** Signers for a song: every writer, and for a publishing agreement every publisher too. */
	public static function signers_for( $kind, array $credits ) {
		$out  = array();
		$seen = array();
		foreach ( $credits as $c ) {
			$role = $c->role ?? '';
			if ( 'writer' !== $role && ! ( 'publishing' === $kind && 'publisher' === $role ) ) {
				continue;
			}
			$key = $role . '|' . strtolower( trim( (string) $c->name ) );
			if ( '' === trim( (string) $c->name ) || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array( 'name' => trim( (string) $c->name ), 'role' => $role, 'share' => ( null !== $c->share && '' !== (string) $c->share ) ? rtrim( rtrim( number_format( (float) $c->share, 4, '.', '' ), '0' ), '.' ) : '' );
		}
		return $out;
	}

	private static function token() {
		return wp_generate_password( 24, false );
	}

	/* ---------- create / read ---------- */

	public static function create( $song_id, $kind ) {
		global $wpdb;
		$song = Songpress_Songs::get( (int) $song_id );
		if ( ! $song ) {
			return 0;
		}
		$kind    = isset( self::KINDS[ $kind ] ) ? $kind : 'split';
		$credits = Songpress_Songs::credits( (int) $song->id );
		$ids     = array();
		foreach ( Songpress_Songs::identifiers( (int) $song->id ) as $i ) {
			$ids[ $i->kind ] = $i->value;
		}
		$writers = array();
		foreach ( $credits as $c ) {
			if ( 'writer' === $c->role && '' !== trim( (string) $c->name ) ) {
				$writers[] = array( 'name' => $c->name, 'ipi' => $c->ipi, 'share' => ( null !== $c->share && '' !== (string) $c->share ) ? rtrim( rtrim( number_format( (float) $c->share, 4, '.', '' ), '0' ), '.' ) : '' );
			}
		}
		$pub = '';
		$pip = '';
		foreach ( $credits as $c ) {
			if ( 'publisher' === $c->role && '' !== trim( (string) $c->name ) ) {
				$pub = $c->name;
				$pip = $c->ipi;
				break;
			}
		}
		$body = self::build_body( $kind, array(
			'serial' => $song->serial, 'title' => $song->title, 'alt' => $song->alt_titles ?? '', 'company' => get_bloginfo( 'name' ),
			'publisher' => $pub, 'publisher_ipi' => $pip, 'iswc' => $ids['iswc'] ?? '', 'copyright' => $ids['copyright'] ?? '',
			'writers' => $writers, 'date' => gmdate( 'Y-m-d' ),
		) );
		$wpdb->insert( self::t(), array(
			'song_id' => (int) $song->id, 'serial' => $song->serial, 'kind' => $kind, 'title' => $song->title, 'body' => $body,
			'print_token' => wp_generate_password( 20, false ), 'user_id' => get_current_user_id(), 'created_at' => current_time( 'mysql' ),
		) );
		$id = (int) $wpdb->insert_id;
		foreach ( self::signers_for( $kind, $credits ) as $s ) {
			$wpdb->insert( self::t( 'signers' ), array( 'agreement_id' => $id, 'name' => $s['name'], 'role' => $s['role'], 'share' => $s['share'], 'token' => self::token() ) );
		}
		return $id;
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	public static function signers( $id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'signers' ) . ' WHERE agreement_id = %d ORDER BY id', (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	public static function all() {
		global $wpdb;
		$a = self::t();
		$s = self::t( 'signers' );
		return $wpdb->get_results( "SELECT a.id, a.serial, a.title, a.kind, a.created_at, (SELECT COUNT(*) FROM {$s} x WHERE x.agreement_id = a.id) AS total, (SELECT COUNT(*) FROM {$s} x WHERE x.agreement_id = a.id AND x.signed_at IS NOT NULL) AS signed FROM {$a} a ORDER BY a.id DESC LIMIT 500" ); // phpcs:ignore WordPress.DB
	}

	public static function sign_url( $token ) {
		return add_query_arg( 'sp_sign', $token, home_url( '/' ) );
	}

	public static function print_url( $token ) {
		return add_query_arg( 'sp_agreement', $token, home_url( '/' ) );
	}

	/** Record a signature. Returns '' on success or a message. A signature, once made, is never changed. */
	public static function sign( $token, $typed_name, $agreed, $ip ) {
		global $wpdb;
		$typed_name = trim( (string) $typed_name );
		if ( '' === $typed_name || ! $agreed ) {
			return 'Type your name and tick the box to sign.';
		}
		$t = self::t( 'signers' );
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET signed_as = %s, signed_at = %s, ip = %s WHERE token = %s AND signed_at IS NULL", mb_substr( $typed_name, 0, 191 ), gmdate( 'Y-m-d H:i:s' ), substr( (string) $ip, 0, 64 ), (string) $token ) ); // phpcs:ignore WordPress.DB
		return $n ? '' : 'This was already signed.';
	}

	/* ---------- import ---------- */

	public static function import_pdo( PDO $pdo ) {
		global $wpdb;
		$has = function ( $table ) use ( $pdo ) {
			$q = $pdo->prepare( "SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?" );
			$q->execute( array( $table ) );
			return (bool) $q->fetchColumn();
		};
		if ( ! $has( 'publishing_agreements' ) ) {
			return 0;
		}
		$songs = Songpress_Install::table( 'songs' );
		$by    = array();
		foreach ( $wpdb->get_results( "SELECT id, serial FROM {$songs}" ) as $r ) { // phpcs:ignore WordPress.DB
			$by[ $r->serial ] = (int) $r->id;
		}
		$have = array_flip( $wpdb->get_col( 'SELECT source_key FROM ' . self::t() . ' WHERE source_key IS NOT NULL' ) ); // phpcs:ignore WordPress.DB
		$n    = 0;
		foreach ( $pdo->query( 'SELECT * FROM publishing_agreements' ) as $a ) {
			$key = 'old:' . (int) $a['id'];
			if ( isset( $have[ $key ] ) ) {
				continue;
			}
			$ser   = Songpress_Journal::serial_in( (string) ( $a['serial'] ?: $a['catalog_stem'] ) );
			$print = preg_replace( '/[^A-Za-z0-9]/', '', (string) $a['print_token'] );
			$wpdb->insert( self::t(), array(
				'song_id' => $by[ $ser ] ?? 0, 'serial' => $ser, 'kind' => 'publishing' === $a['kind'] ? 'publishing' : 'split', 'title' => mb_substr( (string) $a['title'], 0, 255 ),
				'body' => (string) $a['body'], 'print_token' => '' !== $print ? substr( $print, 0, 40 ) : wp_generate_password( 20, false ), 'source_key' => $key, 'user_id' => 0,
				'created_at' => substr( str_replace( 'T', ' ', (string) $a['created_at'] ), 0, 19 ) ?: current_time( 'mysql' ),
			) );
			$aid = (int) $wpdb->insert_id;
			if ( $has( 'agreement_signers' ) ) {
				$q = $pdo->prepare( 'SELECT * FROM agreement_signers WHERE agreement_id = ?' );
				$q->execute( array( (int) $a['id'] ) );
				foreach ( $q->fetchAll( PDO::FETCH_ASSOC ) as $s ) {
					$tok = preg_replace( '/[^A-Za-z0-9]/', '', (string) $s['token'] );
					$wpdb->insert( self::t( 'signers' ), array(
						'agreement_id' => $aid, 'name' => mb_substr( (string) $s['writer_name'], 0, 191 ), 'role' => 'writer', 'share' => substr( (string) $s['share'], 0, 32 ),
						'token' => '' !== $tok ? substr( $tok, 0, 40 ) : self::token(),
						'signed_as' => ( 'signed' === $s['status'] ) ? (string) $s['signed_as'] : null,
						'signed_at' => ( 'signed' === $s['status'] && $s['signed_at'] ) ? substr( str_replace( 'T', ' ', (string) $s['signed_at'] ), 0, 19 ) : null,
						'ip'        => (string) $s['ip'],
					) );
				}
			}
			$n++;
		}
		return $n;
	}

	/* ---------- handlers ---------- */

	private static function guard( $nonce ) {
		check_admin_referer( $nonce );
		if ( ! Songpress_Caps::can( 'agreements' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
	}

	private static function go( $args = array() ) {
		$ret = isset( $_POST['sp_return'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['sp_return'] ) ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked by guard().
		wp_safe_redirect( add_query_arg( array_merge( array( 'sp_page' => 'agreements' ), $args ), '' !== $ret ? $ret : Songpress_Editor::list_url() ) );
		exit;
	}

	public static function handle_create() {
		self::guard( 'songpress_agreement_create' );
		global $wpdb;
		$ser = Songpress_Journal::serial_in( sanitize_text_field( wp_unslash( $_POST['serial'] ?? '' ) ) );
		$sid = '' === $ser ? 0 : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Songpress_Install::table( 'songs' ) . ' WHERE serial = %s', $ser ) ); // phpcs:ignore WordPress.DB
		if ( ! $sid ) {
			self::go( array( 'g_err' => __( 'No song has that serial number.', 'songpress' ) ) );
		}
		$id = self::create( $sid, sanitize_key( wp_unslash( $_POST['kind'] ?? 'split' ) ) );
		self::go( array( 'ag' => $id ) );
	}

	public static function handle_delete() {
		self::guard( 'songpress_agreement_delete' );
		global $wpdb;
		$id = absint( $_POST['agreement_id'] ?? 0 );
		$wpdb->delete( self::t( 'signers' ), array( 'agreement_id' => $id ) );
		$wpdb->delete( self::t(), array( 'id' => $id ) );
		self::go( array( 'g_ok' => __( 'Agreement removed.', 'songpress' ) ) );
	}

	/* ---------- public pages (no login) ---------- */

	private static function page( $title, $inner, $paper = false ) {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html( $title ) . '</title><style>';
		echo ':root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#0e0f12;color:#e8e6e1;font:16px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:20px}';
		echo '.sp-doc{max-width:680px;margin:0 auto;padding:6px 0 40px}h1{font-size:24px;margin:.2em 0 .4em}h2{font-size:18px;margin:1.4em 0 .4em}pre{white-space:pre-wrap;word-wrap:break-word;font:15px/1.6 Georgia,serif;background:#16181c;border:1px solid #2a2d33;border-radius:12px;padding:18px}';
		echo '.sp-ok{color:#7ee2a0}.sp-bad{color:#ff8a8f}.sp-sig{font:italic 30px Georgia,serif;border-bottom:1px solid #666;padding:4px 0;margin:8px 0}.sp-meta{color:#9aa0a8;font-size:13px}';
		echo 'label{display:block;margin:12px 0}input[type=text]{width:100%;font:inherit;padding:12px;border-radius:10px;border:1px solid #2a2d33;background:#16181c;color:inherit}button{font:inherit;font-weight:600;padding:12px 22px;border-radius:12px;border:0;background:#f5a623;color:#16110a;cursor:pointer}';
		echo '.sp-row{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #2a2d33;padding:12px 0}';
		if ( $paper ) {
			echo '@media print{body{background:#fff;color:#000;padding:0}pre{background:#fff;color:#000;border:0}.sp-noprint{display:none}.sp-sig{color:#000;border-color:#000}.sp-meta{color:#444}.sp-ok{color:#060}}';
		}
		echo '</style></head><body><div class="sp-doc">' . $inner . '</div></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		exit;
	}

	public static function public_pages() {
		$sign  = $_GET['sp_sign'] ?? ( $_GET['swp_sign'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$print = $_GET['sp_agreement'] ?? ( $_GET['swp_agreement'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( '' === $sign && '' === $print ) {
			return;
		}
		global $wpdb;
		$a  = self::t();
		$sg = self::t( 'signers' );
		if ( '' !== $print ) {
			$ag = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$a} WHERE print_token = %s", sanitize_text_field( wp_unslash( $print ) ) ) ); // phpcs:ignore WordPress.DB
			if ( ! $ag ) {
				self::page( 'Agreement', '<h1>Agreement not found</h1>' );
			}
			$o = self::render_print( $ag );
			self::page( 'Agreement', $o, true );
		}
		$token = sanitize_text_field( wp_unslash( $sign ) );
		$s     = $wpdb->get_row( $wpdb->prepare( "SELECT s.*, a.body, a.title, a.serial, a.kind, a.print_token FROM {$sg} s JOIN {$a} a ON a.id = s.agreement_id WHERE s.token = %s", $token ) ); // phpcs:ignore WordPress.DB
		if ( ! $s ) {
			self::page( 'Sign', '<h1>This signing link is not valid</h1><p>Ask the person who sent it for a new link.</p>' );
		}
		$err = '';
		$just = false;
		if ( ! empty( $_POST['sp_do_sign'] ) && ! $s->signed_at ) { // phpcs:ignore WordPress.Security.NonceVerification -- a public signing link: the secret token is the credential.
			$err = self::sign( $token, sanitize_text_field( wp_unslash( $_POST['signed_as'] ?? '' ) ), ! empty( $_POST['agree'] ), isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
			$s    = $wpdb->get_row( $wpdb->prepare( "SELECT s.*, a.body, a.title, a.serial, a.kind, a.print_token FROM {$sg} s JOIN {$a} a ON a.id = s.agreement_id WHERE s.token = %s", $token ) ); // phpcs:ignore WordPress.DB
			$just = '' === $err;
		}
		$o  = '<p class="sp-meta">' . esc_html( get_bloginfo( 'name' ) ) . '</p><h1>' . esc_html( ( 'publishing' === $s->kind ? 'Publishing agreement' : 'Cowriter split confirmation' ) . ': ' . $s->title ) . '</h1>';
		$o .= '<p>You are signing as <strong>' . esc_html( $s->name ) . '</strong>.</p><pre>' . esc_html( preg_replace( '/^\s*(COWRITER SPLIT CONFIRMATION|PUBLISHING AGREEMENT)\s*\n+/i', '', (string) $s->body ) ) . '</pre>';
		if ( $s->signed_at ) {
			$o .= '<p><span class="sp-ok">Signed</span>' . ( $just ? ' Thank you. Your signature is recorded.' : '' ) . '</p><div class="sp-sig">' . esc_html( $s->signed_as ) . '</div><p class="sp-meta">Signed as ' . esc_html( $s->name ) . ' on ' . esc_html( gmdate( 'F j, Y \a\t g:i a', strtotime( $s->signed_at . ' UTC' ) ) ) . ' UTC</p>';
			$o .= '<p><a style="color:#f5a623" href="' . esc_url( self::print_url( $s->print_token ) ) . '">View the printable agreement</a></p>';
		} else {
			if ( '' !== $err ) {
				$o .= '<p class="sp-bad">' . esc_html( $err ) . '</p>';
			}
			$o .= '<form method="post"><input type="hidden" name="sp_do_sign" value="1"><label><input type="checkbox" name="agree" value="1"> ' . esc_html( 'publishing' === $s->kind ? 'I agree to this publishing agreement' : 'I confirm the writers and splits above are correct' ) . '</label>';
			$o .= '<label>Type your full legal name<input type="text" name="signed_as" required autocomplete="name" placeholder="Your full legal name"></label><button type="submit">Sign</button></form>';
		}
		self::page( 'Sign: ' . $s->title, $o );
	}

	private static function render_print( $ag ) {
		$body = preg_replace( '/^\s*(COWRITER SPLIT CONFIRMATION|PUBLISHING AGREEMENT)\s*\n+/i', '', (string) $ag->body );
		$o    = '<p class="sp-meta">' . esc_html( get_bloginfo( 'name' ) ) . '</p><h1>' . esc_html( self::KINDS[ $ag->kind ] ?? 'Agreement' ) . '</h1><p class="sp-meta">' . esc_html( ( $ag->serial ? 'Reference ' . $ag->serial . '  ·  ' : '' ) . 'Created ' . gmdate( 'F j, Y', strtotime( $ag->created_at . ' UTC' ) ) ) . '</p><pre>' . esc_html( $body ) . '</pre><h2>Signatures</h2>';
		foreach ( self::signers( (int) $ag->id ) as $s ) {
			$o .= '<div class="sp-row"><div><div class="sp-sig">' . ( $s->signed_at ? esc_html( $s->signed_as ) : '&nbsp;' ) . '</div><strong>' . esc_html( $s->name ) . '</strong> ' . ( $s->signed_at ? '<span class="sp-ok">Signed</span> <span class="sp-meta">' . esc_html( gmdate( 'M j, Y, g:i a', strtotime( $s->signed_at . ' UTC' ) ) ) . ' UTC</span>' : '<span class="sp-meta">Pending</span>' ) . '</div></div>';
		}
		return $o . '<p class="sp-meta">Printed ' . esc_html( gmdate( 'F j, Y, g:i a' ) ) . ' UTC. Signature records on file include the time and IP address of each signing.</p><p class="sp-noprint"><button type="button" onclick="window.print()">Print or save as PDF</button></p>';
	}

	/* ---------- the manager's screen ---------- */

	public static function render() {
		if ( ! Songpress_Caps::can( 'agreements' ) ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'Only Managers can open this screen.', 'songpress' ) . '</p></div>';
		}
		$base = Songpress_Editor::list_url();
		$o    = '<div class="songpress sp-app sp-editor sp-agree"><div class="spe-top"><h2>' . esc_html__( 'Agreements', 'songpress' ) . '</h2></div>';
		if ( ! empty( $_GET['g_ok'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<p class="spe-note ok">' . esc_html( sanitize_text_field( wp_unslash( $_GET['g_ok'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( ! empty( $_GET['g_err'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<p class="spe-note bad" role="alert">' . esc_html( sanitize_text_field( wp_unslash( $_GET['g_err'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( isset( $_GET['ag'] ) && ( $ag = self::get( absint( $_GET['ag'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification, WordPress.CodeAnalysis.AssignmentInCondition
			return $o . self::render_one( $ag ) . '</div>';
		}
		$o .= '<form method="post" class="spag-new" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_agreement_create', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_agreement_create"><input type="hidden" name="sp_return" value="' . esc_attr( $base ) . '"><div class="spe-grid">';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Song serial', 'songpress' ) . '</span><input name="serial" placeholder="0378" required' . ( isset( $_GET['song'] ) ? ' value="' . esc_attr( (string) ( Songpress_Songs::get( absint( $_GET['song'] ) )->serial ?? '' ) ) . '"' : '' ) . '></label>'; // phpcs:ignore WordPress.Security.NonceVerification
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Kind', 'songpress' ) . '</span><select name="kind"><option value="split">' . esc_html__( 'Cowriter split confirmation', 'songpress' ) . '</option><option value="publishing">' . esc_html__( 'Publishing agreement', 'songpress' ) . '</option></select></label></div>';
		$o .= '<p class="spe-help">' . esc_html__( 'The text is built from the song: its writers, IPIs and splits, its publisher, ISWC and copyright number. Each signer gets a link to copy and send; nobody is emailed.', 'songpress' ) . '</p><button class="spe-save" type="submit">' . esc_html__( 'Create agreement', 'songpress' ) . '</button></form>';
		$rows = self::all();
		$only = isset( $_GET['song'] ) ? Songpress_Songs::get( absint( $_GET['song'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $only ) {
			$base4 = substr( (string) $only->serial, 0, 4 );
			$rows  = array_values( array_filter( (array) $rows, function ( $r ) use ( $base4 ) {
				return 0 === strpos( (string) $r->serial, $base4 );
			} ) );
		}
		$o   .= '<h3>' . ( $only ? esc_html( sprintf( /* translators: %s: song title */ __( 'Agreements for %s', 'songpress' ), $only->title ) ) . ' <a class="spe-btn" href="' . esc_url( add_query_arg( 'sp_page', 'agreements', $base ) ) . '">' . esc_html__( 'Show all', 'songpress' ) . '</a>' : esc_html__( 'All agreements', 'songpress' ) ) . '</h3>';
		if ( ! $rows ) {
			$o .= '<p class="spe-help">' . esc_html__( 'None yet.', 'songpress' ) . '</p>';
		}
		$o .= '<ul class="spag-list">';
		foreach ( $rows as $r ) {
			$done = (int) $r->total > 0 && (int) $r->signed === (int) $r->total;
			$o   .= '<li><a href="' . esc_url( add_query_arg( array( 'sp_page' => 'agreements', 'ag' => (int) $r->id ), $base ) ) . '"><b>' . esc_html( $r->serial . ' ' . $r->title ) . '</b></a><span class="spe-help"> ' . esc_html( ( self::KINDS[ $r->kind ] ?? '' ) . ' · ' . substr( (string) $r->created_at, 0, 10 ) ) . '</span><span class="spag-st' . ( $done ? ' done' : '' ) . '">' . esc_html( (int) $r->signed . ' / ' . (int) $r->total . ' ' . __( 'signed', 'songpress' ) ) . '</span></li>';
		}
		return $o . '</ul></div>';
	}

	private static function render_one( $ag ) {
		$base = Songpress_Editor::list_url();
		$o    = '<p><a class="spe-btn" href="' . esc_url( add_query_arg( 'sp_page', 'agreements', $base ) ) . '">&lsaquo; ' . esc_html__( 'All agreements', 'songpress' ) . '</a></p><h3>' . esc_html( $ag->serial . ' ' . $ag->title ) . '</h3><p class="spe-help">' . esc_html( self::KINDS[ $ag->kind ] ?? '' ) . '</p>';
		$o   .= '<h4>' . esc_html__( 'Signing links', 'songpress' ) . '</h4><ul class="spag-list">';
		foreach ( self::signers( (int) $ag->id ) as $s ) {
			$url = self::sign_url( $s->token );
			$o  .= '<li><b>' . esc_html( $s->name ) . '</b>' . ( '' !== $s->share ? ' <span class="spe-help">' . esc_html( $s->share . '%' ) . '</span>' : '' );
			if ( $s->signed_at ) {
				$o .= '<span class="spag-st done">' . esc_html( sprintf( /* translators: 1: typed name, 2: date */ __( 'Signed as %1$s on %2$s', 'songpress' ), $s->signed_as, substr( $s->signed_at, 0, 10 ) ) ) . '</span>';
			} else {
				$o .= '<span class="spag-st">' . esc_html__( 'Waiting', 'songpress' ) . '</span><br><input class="spag-link" readonly value="' . esc_attr( $url ) . '" onfocus="this.select()"> <button type="button" class="spe-btn spag-copy" data-url="' . esc_attr( $url ) . '">' . esc_html__( 'Copy link', 'songpress' ) . '</button>';
			}
			$o .= '</li>';
		}
		$o .= '</ul><p><a class="spe-btn" target="_blank" rel="noopener" href="' . esc_url( self::print_url( $ag->print_token ) ) . '">' . esc_html__( 'Open printable agreement', 'songpress' ) . '</a></p><pre class="spag-body">' . esc_html( $ag->body ) . '</pre>';
		$o .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_agreement_delete', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_agreement_delete"><input type="hidden" name="agreement_id" value="' . (int) $ag->id . '"><input type="hidden" name="sp_return" value="' . esc_attr( $base ) . '"><button class="spe-btn danger" type="submit">' . esc_html__( 'Delete this agreement', 'songpress' ) . '</button></form>';
		$o .= '<script>document.addEventListener("click",function(e){var b=e.target.closest(".spag-copy");if(!b)return;var u=b.getAttribute("data-url"),i=b.previousElementSibling;function ok(){b.textContent="Copied"}function fb(){i.select();try{document.execCommand("copy");ok()}catch(x){}}if(navigator.clipboard){navigator.clipboard.writeText(u).then(ok,fb)}else{fb()}});</script>';
		return $o;
	}
}
