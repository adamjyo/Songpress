<?php
defined( 'ABSPATH' ) || exit;

/** Photos, bios and contact details for people, bands and publishers, matched to credits by name. */
class Songpress_Profiles {

	const KINDS = array( 'person', 'band', 'publisher' );

	public static function key( $name ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $name ) ) );
	}

	/** The old catalog stored photo links in several shapes; return a full URL or ''. */
	public static function clean_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		} elseif ( '/' === $url[0] && function_exists( 'home_url' ) ) {
			$url = home_url( $url );
		}
		return preg_match( '#^https?://#i', $url ) ? $url : '';
	}

	/** Insert or update one profile. Blank incoming fields never wipe what is already saved. */
	public static function upsert( $kind, $name, array $f ) {
		global $wpdb;
		$kind = in_array( $kind, self::KINDS, true ) ? $kind : 'person';
		$name = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( $name ) ) );
		$key  = self::key( $name );
		if ( '' === $key ) {
			return 0;
		}
		$t   = Songpress_Install::table( 'profiles' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE kind = %s AND name_key = %s", $kind, $key ) );
		$in  = array();
		foreach ( array( 'aliases', 'bio', 'email', 'phone', 'website', 'ipi', 'pro' ) as $k ) {
			if ( isset( $f[ $k ] ) && '' !== trim( (string) $f[ $k ] ) ) {
				$in[ $k ] = 'bio' === $k || 'aliases' === $k ? sanitize_textarea_field( $f[ $k ] ) : ( 'website' === $k ? esc_url_raw( $f[ $k ] ) : sanitize_text_field( $f[ $k ] ) );
			}
		}
		if ( ! empty( $f['photo_id'] ) ) {
			$in['photo_id']  = absint( $f['photo_id'] );
			$in['photo_url'] = '';
		} elseif ( ! empty( $f['photo_url'] ) ) {
			$url = self::clean_url( $f['photo_url'] );
			if ( '' !== $url ) {
				$id              = Songpress_Media::id_from_url( $url );
				$in['photo_id']  = $id;
				$in['photo_url'] = $id ? '' : $url;
			}
		}
		$in['updated_at'] = current_time( 'mysql' );
		if ( $row ) {
			// when importing, keep what the user already filled in
			if ( ! empty( $f['keep'] ) ) {
				foreach ( $in as $k => $v ) {
					if ( 'updated_at' !== $k && ! empty( $row->$k ) ) {
						unset( $in[ $k ] );
					}
				}
			}
			$wpdb->update( $t, $in, array( 'id' => (int) $row->id ) );
			return (int) $row->id;
		}
		$wpdb->insert( $t, array_merge( array( 'kind' => $kind, 'name' => $name, 'name_key' => $key ), $in ) );
		return (int) $wpdb->insert_id;
	}

	/** Every profile, keyed by name_key and every alias, as one map for fast lookup on a page. */
	public static function map() {
		global $wpdb;
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}
		$map = array();
		$t   = Songpress_Install::table( 'profiles' );
		foreach ( (array) $wpdb->get_results( "SELECT * FROM {$t} ORDER BY kind = 'person' DESC, id" ) as $r ) { // people win when a name is also a band
			$map[ $r->name_key ] = $r;
			foreach ( preg_split( '/[,|\n]+/', (string) $r->aliases ) as $a ) {
				$k = self::key( $a );
				if ( '' !== $k && ! isset( $map[ $k ] ) ) {
					$map[ $k ] = $r;
				}
			}
		}
		return $map;
	}

	public static function reset_cache() {
		// the map is rebuilt per request, so nothing to clear outside tests
	}

	public static function find( $name ) {
		$m = self::map();
		$k = self::key( $name );
		return isset( $m[ $k ] ) ? $m[ $k ] : null;
	}

	public static function photo_url( $profile, $size = 'thumbnail' ) {
		if ( ! $profile ) {
			return '';
		}
		if ( (int) $profile->photo_id ) {
			$u = wp_get_attachment_image_url( (int) $profile->photo_id, $size );
			if ( $u ) {
				return (string) $u;
			}
		}
		return (string) $profile->photo_url;
	}

	/** A small round photo, or initials when there is no photo. */
	public static function chip( $name, $px = 28 ) {
		$name = (string) $name;
		$p    = self::find( $name );
		$src  = self::photo_url( $p );
		$st   = 'width:' . (int) $px . 'px;height:' . (int) $px . 'px;border-radius:50%;object-fit:cover;vertical-align:middle;margin-right:6px';
		if ( '' !== $src ) {
			return '<img class="sp-face" src="' . esc_url( $src ) . '" alt="" loading="lazy" style="' . esc_attr( $st ) . '">';
		}
		$ini = '';
		foreach ( array_slice( preg_split( '/\s+/', trim( $name ) ), 0, 2 ) as $w ) {
			$ini .= function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $w, 0, 1 ) ) : strtoupper( substr( $w, 0, 1 ) );
		}
		return '<span class="sp-face sp-face-ini" aria-hidden="true" style="' . esc_attr( $st . ';display:inline-flex;align-items:center;justify-content:center;background:#e8e2d4;color:#5a4a1f;font-size:' . round( $px * 0.4 ) . 'px;font-weight:600' ) . '">' . esc_html( $ini ) . '</span>';
	}

	/** Bring photos, bios and contact details across from the old S&P Catalog database. Safe to run again. */
	public static function import_sqlite( $path ) {
		$out = array( 'people' => 0, 'bands' => 0, 'publishers' => 0 );
		try {
			$pdo = new PDO( 'sqlite:' . $path, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
		} catch ( Exception $e ) {
			return $out;
		}
		$has = function ( $table ) use ( $pdo ) {
			$q = $pdo->prepare( "SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?" );
			$q->execute( array( $table ) );
			return (bool) $q->fetchColumn();
		};
		$all = function ( $table ) use ( $pdo ) {
			try {
				return $pdo->query( 'SELECT * FROM ' . $table )->fetchAll( PDO::FETCH_ASSOC ); // table name is a literal from this file
			} catch ( Exception $e ) {
				return array();
			}
		};
		$pick = function ( $r, $keys ) {
			foreach ( $keys as $k ) {
				if ( isset( $r[ $k ] ) && '' !== trim( (string) $r[ $k ] ) && '#N/A' !== trim( (string) $r[ $k ] ) ) {
					return trim( (string) $r[ $k ] );
				}
			}
			return '';
		};
		if ( $has( 'people' ) ) {
			foreach ( $all( 'people' ) as $r ) {
				if ( '' === $pick( $r, array( 'name' ) ) ) {
					continue;
				}
				if ( self::upsert( 'person', $r['name'], array(
					'aliases'   => $pick( $r, array( 'aliases' ) ),
					'photo_url' => $pick( $r, array( 'photo', 'link' ) ),
					'bio'       => $pick( $r, array( 'bio' ) ),
					'email'     => $pick( $r, array( 'email' ) ),
					'phone'     => $pick( $r, array( 'phone' ) ),
					'ipi'       => $pick( $r, array( 'ipi' ) ),
					'pro'       => $pick( $r, array( 'pro' ) ),
					'keep'      => true,
				) ) ) {
					$out['people']++;
				}
			}
		}
		if ( $has( 'bands' ) ) {
			foreach ( $all( 'bands' ) as $r ) {
				if ( '' === $pick( $r, array( 'name' ) ) ) {
					continue;
				}
				if ( self::upsert( 'band', $r['name'], array( 'photo_url' => $pick( $r, array( 'icon_url' ) ), 'bio' => $pick( $r, array( 'notes' ) ), 'keep' => true ) ) ) {
					$out['bands']++;
				}
			}
		}
		if ( $has( 'publishers' ) ) {
			foreach ( $all( 'publishers' ) as $r ) {
				if ( '' === $pick( $r, array( 'name' ) ) ) {
					continue;
				}
				if ( self::upsert( 'publisher', $r['name'], array(
					'photo_url' => $pick( $r, array( 'photo' ) ),
					'email'     => $pick( $r, array( 'email' ) ),
					'phone'     => $pick( $r, array( 'phone' ) ),
					'website'   => $pick( $r, array( 'website' ) ),
					'keep'      => true,
				) ) ) {
					$out['publishers']++;
				}
			}
		}
		return $out;
	}
}
