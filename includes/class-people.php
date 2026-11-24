<?php
defined( 'ABSPATH' ) || exit;

/**
 * Every writer gets a WordPress login, named firstname.lastname, with the Viewer class. Accounts made here have no
 * email and a random password, so nobody can sign in until a Manager adds their email and they choose a password
 * ("Lost your password?"). The same name always maps to the same account, so one person has one login across songs.
 */
class Songpress_People {
	const KEY_META = 'songpress_person_key';

	const CRON = 'songpress_people_topup';

	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'topup' ) );
		add_action( 'init', array( __CLASS__, 'watch' ) );
	}

	/** Keeps going in the background until every writer has an account: a batch now, another a minute later while any remain. */
	public static function topup() {
		if ( ! self::ready() ) {
			return;
		}
		$r = self::backfill_all( 20 );
		if ( $r['remaining'] > 0 && ( $r['created'] + $r['linked'] ) > 0 ) {
			self::schedule( 60 );
		}
	}

	public static function schedule( $in = 30 ) {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + (int) $in, self::CRON );
		}
	}

	/** Once an hour at most, notice writers without an account and start the background top-up. */
	public static function watch() {
		if ( ! self::ready() || get_transient( 'songpress_people_ok' ) ) {
			return;
		}
		set_transient( 'songpress_people_ok', 1, HOUR_IN_SECONDS );
		if ( self::unlinked_names( 1 ) ) {
			self::schedule( 5 );
		}
	}

	/** Accounts are made only once the first import has finished, or once a Manager chose to start fresh. */
	public static function ready() {
		return '1' === get_option( 'songpress_accounts_ready', '0' );
	}

	/** "Mary-Jane O'Neil Jr." becomes "maryjane.oneil". "Cher" stays "cher". "Lennon, John" becomes "john.lennon". Pure. */
	public static function username_for( $name ) {
		$n = trim( (string) $name );
		if ( '' === $n ) {
			return '';
		}
		if ( function_exists( 'remove_accents' ) ) {
			$n = remove_accents( $n );
		} elseif ( function_exists( 'iconv' ) ) {
			$n = (string) @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $n );
		}
		$n = strtolower( $n );
		if ( false !== strpos( $n, ',' ) ) { // "Last, First"
			list( $last, $first ) = array_map( 'trim', explode( ',', $n, 2 ) );
			$n = $first . ' ' . $last;
		}
		$tokens = array();
		foreach ( preg_split( '/\s+/', $n ) as $t ) {
			$t = preg_replace( '/[^a-z0-9]/', '', $t );
			if ( '' !== $t && ! in_array( $t, array( 'jr', 'sr', 'ii', 'iii', 'iv' ), true ) ) {
				$tokens[] = $t;
			}
		}
		if ( ! $tokens ) {
			return '';
		}
		$login = 1 === count( $tokens ) ? $tokens[0] : $tokens[0] . '.' . $tokens[ count( $tokens ) - 1 ];
		return substr( $login, 0, 56 );
	}

	/** What identifies a person across songs: the lowercase letters and digits of the name. Pure. */
	public static function key_for( $name ) {
		return strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $name ) );
	}

	/** An unused login built from $base and a suffix of letters only ("adam.page-old", "adam.page-oldb", ...). */
	public static function free_login( $base, $suffix = 'old' ) {
		$try = $base . '-' . $suffix;
		foreach ( range( 'b', 'z' ) as $l ) {
			if ( ! username_exists( $try ) ) {
				return $try;
			}
			$try = $base . '-' . $suffix . $l;
		}
		return $try;
	}

	/** Rename an old-system account out of the way so its login can be reused. */
	public static function step_aside( $user_id ) {
		global $wpdb;
		$u = get_userdata( (int) $user_id );
		if ( ! $u ) {
			return '';
		}
		$free = self::free_login( $u->user_login );
		$wpdb->update( $wpdb->users, array( 'user_login' => $free, 'user_nicename' => sanitize_title( $free ) ), array( 'ID' => (int) $user_id ) );
		clean_user_cache( (int) $user_id );
		return $free;
	}

	/** The account for this name: the one made for it earlier, or a new Viewer. 0 when none could be made. */
	public static function ensure_user( $name, &$created = null ) {
		$name  = trim( (string) $name );
		$key   = self::key_for( $name );
		$login = self::username_for( $name );
		if ( '' === $key || '' === $login ) {
			return 0;
		}
		$found = get_users( array( 'meta_key' => self::KEY_META, 'meta_value' => $key, 'number' => 1, 'fields' => 'ID' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $found ) {
			return (int) $found[0];
		}
		// An account already named for this very person is theirs: use it rather than make "name2".
		$same = get_user_by( 'login', $login );
		if ( $same && self::key_for( $same->display_name ) === $key ) {
			update_user_meta( $same->ID, self::KEY_META, $key );
			return (int) $same->ID;
		}
		// A login never ends in a number. An account already holding it that is not an admin, is not this person's and has no SongPress class
		// is a leftover from the old system: it steps aside (renamed name-old) and the login goes to this person.
		$try  = $login;
		$held = get_user_by( 'login', $login );
		if ( $held ) {
			if ( ! Songpress_Caps::is_wp_admin( $held->ID ) && '' === (string) get_user_meta( $held->ID, Songpress_Caps::META, true ) ) {
				self::step_aside( $held->ID );
			} else { // a live account of someone else: fall back to a letter suffix, never a number
				$try = self::free_login( $login, 'x' );
			}
		}
		$parts = preg_split( '/\s+/', $name );
		$id    = wp_insert_user( array(
			'user_login'   => $try,
			'user_pass'    => wp_generate_password( 32 ),
			'display_name' => $name,
			'first_name'   => count( $parts ) > 1 ? $parts[0] : $name,
			'last_name'    => count( $parts ) > 1 ? end( $parts ) : '',
			'role'         => 'subscriber',
		) );
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		update_user_meta( $id, self::KEY_META, $key );
		Songpress_Caps::assign( $id, 'viewer' );
		$created = (int) $created + 1;
		return (int) $id;
	}

	/** Fill in user_id on writer rows that have none, making accounts as needed. Used when a song is saved. */
	public static function link_rows( array $rows ) {
		foreach ( $rows as $k => $r ) {
			if ( 'writer' === ( $r['role'] ?? '' ) && empty( $r['user_id'] ) && '' !== trim( (string) ( $r['name'] ?? '' ) ) ) {
				$uid = self::ensure_user( $r['name'] );
				if ( $uid ) {
					$rows[ $k ]['user_id'] = $uid;
				}
			}
		}
		return $rows;
	}

	/** Every writer name in the catalog that has no account yet. */
	public static function unlinked_names( $limit = 0 ) {
		global $wpdb;
		$c   = Songpress_Install::table( 'credits' );
		$sql = "SELECT DISTINCT name FROM {$c} WHERE role = 'writer' AND user_id IS NULL AND name <> '' ORDER BY name" . ( $limit ? ' LIMIT ' . (int) $limit : '' );
		return $wpdb->get_col( $sql );
	}

	/** One batch of "make everyone a user". Returns array( created, linked, remaining ). */
	public static function backfill( $limit = 100 ) {
		global $wpdb;
		$c       = Songpress_Install::table( 'credits' );
		$created = 0;
		$linked  = 0;
		foreach ( self::unlinked_names( $limit ) as $name ) {
			$uid = self::ensure_user( $name, $created );
			if ( $uid ) {
				$linked += (int) $wpdb->query( $wpdb->prepare( "UPDATE {$c} SET user_id = %d WHERE role = 'writer' AND user_id IS NULL AND name = %s", $uid, $name ) );
			} else {
				// no usable login from this name: leave it unlinked and out of the next batch's way
				$wpdb->query( $wpdb->prepare( "UPDATE {$c} SET user_id = 0 WHERE role = 'writer' AND user_id IS NULL AND name = %s", $name ) );
			}
		}
		$wpdb->query( "UPDATE {$c} SET user_id = NULL WHERE user_id = 0" );
		return array( 'created' => $created, 'linked' => $linked, 'remaining' => count( self::unlinked_names() ) );
	}

	/** Make accounts for every unlinked writer, in batches, until done or the time budget runs out. */
	public static function backfill_all( $seconds = 20 ) {
		$start = time();
		$total = array( 'created' => 0, 'linked' => 0, 'remaining' => 0 );
		do {
			$r = self::backfill( 100 );
			$total['created'] += $r['created'];
			$total['linked']  += $r['linked'];
			$total['remaining'] = $r['remaining'];
		} while ( $r['remaining'] > 0 && ( $r['created'] + $r['linked'] ) > 0 && ( time() - $start ) < $seconds );
		return $total;
	}
}
