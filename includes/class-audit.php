<?php
defined( 'ABSPATH' ) || exit;

/**
 * The activity log: one plain record of who did what and when, anywhere in the app. Managers only.
 * Entries are written when a SongPress form or action succeeds (it hooks the nonce check), when someone signs in,
 * and whenever a song's own history is written. Nothing here is edited or deleted by the plugin.
 */
class Songpress_Audit {

	const PER_PAGE = 50;

	/** admin-post action => array( area, what was done ). */
	const ACTIONS = array(
		'songpress_agreement_create'  => array( 'agreements', 'Created an agreement' ),
		'songpress_agreement_delete'  => array( 'agreements', 'Deleted an agreement' ),
		'songpress_art_delete'        => array( 'art', 'Deleted artwork' ),
		'songpress_download'          => array( 'uploads', 'Downloaded a file' ),
		'songpress_journal_save'      => array( 'notes', 'Saved a note' ),
		'songpress_journal_flag'      => array( 'notes', 'Pinned or archived a note' ),
		'songpress_health_clear'      => array( 'other', 'Cleared the error list' ),
		'songpress_lists_save'        => array( 'other', 'Changed the lists' ),
		'songpress_registration_undated' => array( 'registrations', 'Set a start date on undated registrations' ),
		'songpress_member_add'        => array( 'people', 'Added a person' ),
		'songpress_member_update'     => array( 'people', 'Changed a person' ),
		'songpress_notes_import'      => array( 'uploads', 'Imported notes' ),
		'songpress_person_rename'     => array( 'people', 'Renamed a person and their login' ),
		'songpress_person_tidy'       => array( 'people', 'Tidied logins' ),
		'songpress_registration_save' => array( 'registrations', 'Saved a registration' ),
		'songpress_session_chat'      => array( 'sessions', 'Sent a session message' ),
		'songpress_session_delete'    => array( 'sessions', 'Deleted a session' ),
		'songpress_session_save'      => array( 'sessions', 'Saved a session' ),
		'songpress_statement'         => array( 'uploads', 'Uploaded a statement' ),
	);

	/** Actions that log themselves in full (so the plain request entry is skipped). */
	const DETAILED = array( 'songpress_money_payment', 'songpress_money_payment_update', 'songpress_money_payment_delete', 'songpress_money_expense_add', 'songpress_money_expense_delete','songpress_money_band_payee' );

	/** Posted fields worth keeping beside an entry (never passwords or tokens). */
	const KEEP = array( 'entry_id', 'song_id', 'session_id', 'id', 'title', 'name', 'person', 'login', 'type', 'table', 'src', 'quarter', 'kind', 'flag', 'op' );

	public static function areas() {
		return array(
			'songs'         => __( 'Works', 'songpress' ),
			'notes'         => __( 'Notes', 'songpress' ),
			'people'        => __( 'People', 'songpress' ),
			'money'         => __( 'Money', 'songpress' ),
			'pitch'         => __( 'Pitch', 'songpress' ),
			'sessions'      => __( 'Sessions', 'songpress' ),
			'art'           => __( 'Art', 'songpress' ),
			'agreements'    => __( 'Agreements', 'songpress' ),
			'registrations' => __( 'Registrations', 'songpress' ),
			'uploads'       => __( 'Upload / Download', 'songpress' ),
			'login'         => __( 'Sign-ins', 'songpress' ),
			'other'         => __( 'Other', 'songpress' ),
		);
	}

	public static function init() {
		add_action( 'check_admin_referer', array( __CLASS__, 'on_referer' ), 10, 2 );
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
	}

	/** Write one entry. Safe to call from anywhere. */
	public static function log( $area, $action, $subject = '', array $detail = array(), $user_id = null ) {
		global $wpdb;
		if ( ! $wpdb ) {
			return;
		}
		$wpdb->insert( Songpress_Install::table( 'audit' ), array(
			'user_id'    => null === $user_id ? (int) get_current_user_id() : (int) $user_id,
			'area'       => substr( (string) $area, 0, 16 ),
			'action'     => mb_substr( (string) $action, 0, 48 ),
			'subject'    => mb_substr( (string) $subject, 0, 255 ),
			'detail'     => $detail ? wp_json_encode( $detail ) : '',
			'created_at' => current_time( 'mysql', true ),
		) );
	}

	/**
	 * Log a change to one thing, folding quick repeats into the one entry (a note that saves as you type would otherwise fill the log).
	 * $changes holds only what moved: field => array( old, new ), or 'added' / 'removed' character counts that are summed. A repeat within $window seconds by the same person
	 * keeps the first old value, takes the newest new value, adds up the counts and counts the saves.
	 */
	public static function log_change( $area, array $actions, $subject, $key, $id, array $changes, $window = 900 ) {
		global $wpdb;
		if ( ! $wpdb || ! $changes ) {
			return;
		}
		$t    = Songpress_Install::table( 'audit' );
		$uid  = (int) get_current_user_id();
		$like = $wpdb->esc_like( '{"' . $key . '":' . (int) $id . ',' ) . '%';
		$ph   = implode( ',', array_fill( 0, count( $actions ), '%s' ) );
		$last = $wpdb->get_row( $wpdb->prepare( "SELECT id, action, detail, created_at FROM {$t} WHERE user_id = %d AND area = %s AND action IN ({$ph}) AND detail LIKE %s ORDER BY id DESC LIMIT 1", array_merge( array( $uid, $area ), $actions, array( $like ) ) ) ); // phpcs:ignore WordPress.DB
		$now  = time();
		if ( $last && ( $now - (int) strtotime( $last->created_at . ' UTC' ) ) <= $window ) {
			$d = json_decode( (string) $last->detail, true );
			$d = is_array( $d ) ? $d : array( $key => (int) $id );
			foreach ( $changes as $k => $v ) {
				if ( 'added' === $k || 'removed' === $k ) {
					$d[ $k ] = (int) ( $d[ $k ] ?? 0 ) + (int) $v;
				} elseif ( isset( $d[ $k ] ) && is_array( $d[ $k ] ) && is_array( $v ) ) {
					$d[ $k ] = array( $d[ $k ][0], $v[1] );
				} else {
					$d[ $k ] = $v;
				}
			}
			$d['saves'] = (int) ( $d['saves'] ?? 1 ) + 1;
			$wpdb->update( $t, array( 'detail' => wp_json_encode( $d ), 'subject' => mb_substr( (string) $subject, 0, 255 ), 'created_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $last->id ) );
			return;
		}
		self::log( $area, $actions[0], $subject, array( $key => (int) $id ) + $changes );
	}

	/** Pure: turn a request into array( area, action ) for a SongPress admin-post action, or null if it is not one. */
	public static function classify( $action ) {
		$action = (string) $action;
		if ( 0 !== strpos( $action, 'songpress_' ) || 'songpress_journal_ajax' === $action ) {
			return null;
		}
		if ( in_array( $action, self::DETAILED, true ) ) {
			return null; // these write their own entry, with the amounts and what changed
		}
		if ( isset( self::ACTIONS[ $action ] ) ) {
			return self::ACTIONS[ $action ];
		}
		if ( 0 === strpos( $action, 'songpress_pitch_' ) ) {
			return array( 'pitch', ucfirst( str_replace( '_', ' ', substr( $action, 16 ) ) ) . ' (pitch)' );
		}
		if ( 0 === strpos( $action, 'songpress_money_' ) ) {
			return array( 'money', ucfirst( str_replace( '_', ' ', substr( $action, 16 ) ) ) );
		}
		return array( 'other', ucfirst( str_replace( '_', ' ', substr( $action, 10 ) ) ) );
	}

	/** Pure: the few request fields that identify what was touched. */
	public static function pick( array $req ) {
		$out = array();
		foreach ( self::KEEP as $k ) {
			if ( isset( $req[ $k ] ) && is_scalar( $req[ $k ] ) && '' !== (string) $req[ $k ] ) {
				$out[ $k ] = mb_substr( (string) $req[ $k ], 0, 120 );
			}
		}
		return $out;
	}

	/** Fires after a nonce check; $result is 1 or 2 when it passed. Only successful SongPress actions are recorded. */
	public static function on_referer( $action, $result ) {
		static $seen = array();
		if ( ! $result || ! is_user_logged_in() ) {
			return;
		}
		$act = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- this runs after the nonce check.
		$cls = self::classify( $act );
		if ( ! $cls || isset( $seen[ $act ] ) ) {
			return;
		}
		$seen[ $act ] = true;
		$req          = self::pick( wp_unslash( $_REQUEST ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$subject      = $req['title'] ?? $req['name'] ?? $req['person'] ?? $req['login'] ?? $req['type'] ?? $req['table'] ?? '';
		self::log( $cls[0], $cls[1], $subject, $req );
	}

	public static function on_login( $user_login, $user ) {
		self::log( 'login', 'Signed in', $user_login, array(), isset( $user->ID ) ? (int) $user->ID : 0 );
	}

	/* ---------- reading ---------- */

	public static function rows( $area, $user_id, $q, $page ) {
		global $wpdb;
		$t     = Songpress_Install::table( 'audit' );
		$where = array( '1=1' );
		$args  = array();
		if ( '' !== $area ) {
			$where[] = 'area = %s';
			$args[]  = $area;
		}
		if ( $user_id ) {
			$where[] = 'user_id = %d';
			$args[]  = (int) $user_id;
		}
		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '(subject LIKE %s OR action LIKE %s OR detail LIKE %s)';
			array_push( $args, $like, $like, $like );
		}
		$w     = implode( ' AND ', $where );
		$count = "SELECT COUNT(*) FROM {$t} WHERE {$w}";
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count, $args ) ) : $wpdb->get_var( $count ) ); // phpcs:ignore WordPress.DB
		$sql   = "SELECT * FROM {$t} WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( self::PER_PAGE, max( 0, $page - 1 ) * self::PER_PAGE ) ) ) ); // phpcs:ignore WordPress.DB
		return array( 'rows' => $rows, 'total' => $total );
	}

	/** Pure: a short readable line for what a detail blob holds. */
	public static function describe_detail( $json ) {
		$d = json_decode( (string) $json, true );
		if ( ! is_array( $d ) ) {
			return '';
		}
		$bits = array();
		foreach ( $d as $k => $v ) {
			if ( is_array( $v ) ) {
				$v = isset( $v[1] ) ? ( $v[0] . ' → ' . $v[1] ) : wp_json_encode( $v );
			}
			$bits[] = $k . ': ' . $v;
		}
		return implode( ' · ', $bits );
	}

	public static function render() {
		if ( ! Songpress_Caps::can( 'activity' ) ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'Only Managers can open this screen.', 'songpress' ) . '</p></div>';
		}
		global $wpdb;
		$area = isset( $_GET['aarea'] ) ? sanitize_key( wp_unslash( $_GET['aarea'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$area = isset( self::areas()[ $area ] ) ? $area : '';
		$uid  = isset( $_GET['auser'] ) ? absint( $_GET['auser'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$q    = isset( $_GET['aq'] ) ? sanitize_text_field( wp_unslash( $_GET['aq'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$page = isset( $_GET['ap'] ) ? max( 1, absint( $_GET['ap'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$res  = self::rows( $area, $uid, $q, $page );
		$base = Songpress_Editor::list_url();
		$o    = '<div class="songpress sp-app sp-editor sp-audit"><div class="spe-top"><h2>' . esc_html__( 'Activity log', 'songpress' ) . '</h2></div>';
		$o   .= '<p class="spe-help">' . esc_html__( 'Who did what, and when, across the whole app. Only Managers can see this. Entries are never changed or removed.', 'songpress' ) . '</p>';
		$o   .= '<form method="get" class="spj-find"><input type="hidden" name="sp_page" value="activity"><select name="aarea" onchange="this.form.submit()"><option value="">' . esc_html__( 'Everything', 'songpress' ) . '</option>';
		foreach ( self::areas() as $k => $l ) {
			$o .= '<option value="' . esc_attr( $k ) . '"' . selected( $area, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		$o .= '</select><select name="auser" onchange="this.form.submit()"><option value="0">' . esc_html__( 'Everyone', 'songpress' ) . '</option>';
		foreach ( (array) $wpdb->get_col( 'SELECT DISTINCT user_id FROM ' . Songpress_Install::table( 'audit' ) . ' ORDER BY user_id' ) as $u ) { // phpcs:ignore WordPress.DB
			$ud = $u ? get_userdata( (int) $u ) : false;
			$o .= '<option value="' . (int) $u . '"' . selected( $uid, (int) $u, false ) . '>' . esc_html( $ud ? $ud->display_name : __( 'System', 'songpress' ) ) . '</option>';
		}
		$o .= '</select><input type="search" name="aq" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Search', 'songpress' ) . '"><button class="spe-btn" type="submit">' . esc_html__( 'Search', 'songpress' ) . '</button> <a class="spe-btn" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=songpress_download&table=audit' ), 'songpress_download' ) ) . '">' . esc_html__( 'Download as CSV', 'songpress' ) . '</a></form>';
		$o .= '<p class="spe-help">' . esc_html( sprintf( /* translators: %d: number of entries */ _n( '%d entry', '%d entries', $res['total'], 'songpress' ), $res['total'] ) ) . '</p>';
		if ( ! $res['rows'] ) {
			return $o . '<p class="spe-help">' . esc_html__( 'Nothing recorded yet.', 'songpress' ) . '</p></div>';
		}
		$areas = self::areas();
		$o    .= '<ul class="spa-list">';
		foreach ( $res['rows'] as $r ) {
			$ud  = (int) $r->user_id ? get_userdata( (int) $r->user_id ) : false;
			$det = self::describe_detail( $r->detail );
			$o  .= '<li><time>' . esc_html( get_date_from_gmt( $r->created_at, 'Y-m-d H:i' ) ) . '</time> <b>' . esc_html( $ud ? $ud->display_name : __( 'System', 'songpress' ) ) . '</b> ' . esc_html( $r->action ) . ( '' !== $r->subject ? ' <span class="spa-sub">' . esc_html( $r->subject ) . '</span>' : '' ) . ' <span class="spa-area">' . esc_html( $areas[ $r->area ] ?? $r->area ) . '</span>' . ( '' !== $det ? '<small>' . esc_html( $det ) . '</small>' : '' ) . '</li>';
		}
		$o    .= '</ul>';
		$pages = (int) ceil( $res['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			$arg = array( 'sp_page' => 'activity', 'aarea' => $area, 'auser' => $uid, 'aq' => $q );
			$o  .= '<p class="spj-pages">';
			if ( $page > 1 ) {
				$o .= '<a class="spe-btn" href="' . esc_url( add_query_arg( array_merge( $arg, array( 'ap' => $page - 1 ) ), $base ) ) . '">' . esc_html__( 'Newer', 'songpress' ) . '</a> ';
			}
			$o .= esc_html( $page . ' / ' . $pages );
			if ( $page < $pages ) {
				$o .= ' <a class="spe-btn" href="' . esc_url( add_query_arg( array_merge( $arg, array( 'ap' => $page + 1 ) ), $base ) ) . '">' . esc_html__( 'Older', 'songpress' ) . '</a>';
			}
			$o .= '</p>';
		}
		return $o . '</div>';
	}
}
