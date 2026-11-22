<?php
defined( 'ABSPATH' ) || exit;

/** Writes mapped rows into the SongPress tables. Safe to run twice: rows already imported (same legacy id) are skipped. */
class Songpress_Import {

	/**
	 * @param string $path  SQLite file from S&P Catalog.
	 * @param int    $limit Max rows to import this call (0 = all). Call again to continue.
	 * @return array{imported:int,skipped:int,errors:array}
	 */
	/** A folder inside uploads that the web server will not serve, for the temporary copy of the old database. */
	public static function private_dir() {
		$u = wp_upload_dir();
		if ( ! empty( $u['error'] ) ) {
			return '';
		}
		$dir = trailingslashit( $u['basedir'] ) . 'songpress-private';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	public static function run( $path, $limit = 0 ) {
		global $wpdb;
		$songs = Songpress_Install::table( 'songs' );
		$fam   = Songpress_Install::table( 'families' );
		$out   = array( 'imported' => 0, 'skipped' => 0, 'errors' => array() );

		foreach ( Songpress_Import_Analyzer::read_sqlite( $path ) as $row ) {
			if ( $limit && $out['imported'] >= $limit ) {
				break;
			}
			$m = Songpress_Import_Analyzer::map_row( $row );
			if ( ! $m['serial'] ) {
				$out['errors'][] = sprintf( 'Row %d: "%s" is not a serial number.', (int) ( $row['id'] ?? 0 ), (string) ( $row['serial_number'] ?? '' ) );
				continue;
			}
			$legacy = (int) $m['song']['legacy_id'];
			if ( $legacy && $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$songs} WHERE legacy_id = %d", $legacy ) ) ) {
				$out['skipped']++;
				continue;
			}
			$serial = Songpress_Serial::format( $m['serial']['family'], $m['serial']['version'] );
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$songs} WHERE serial = %s", $serial ) ) ) {
				$out['errors'][] = "{$serial} already exists, skipped.";
				continue;
			}
			$family_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$fam} WHERE family_no = %d", $m['serial']['family'] ) );
			if ( ! $family_id ) {
				$wpdb->insert( $fam, array( 'family_no' => $m['serial']['family'], 'created_at' => current_time( 'mysql', true ) ) );
				$family_id = (int) $wpdb->insert_id;
			}
			$now  = current_time( 'mysql', true );
			$data = $m['song'];
			if ( '' === $data['status'] ) {
				$data['status'] = 'idea';
			}
			$data = array_merge( $data, array(
				'family_id' => $family_id, 'version_no' => $m['serial']['version'], 'serial' => $serial,
				'owner_id' => 0, 'created_at' => $now, 'updated_at' => $now,
			) );
			// The old catalog stored links to the WordPress Media Library. Use the library item when the link matches one.
			$data['art_id']   = Songpress_Media::id_from_url( $data['art_url'] ?? '' ) ?: null;
			$data['audio_id'] = Songpress_Media::id_from_url( $data['audio_url'] ?? '' ) ?: null;
			if ( ! empty( $data['art_url'] ) && ! $data['art_id'] ) {
				$out['media_unmatched'] = ( $out['media_unmatched'] ?? 0 ) + 1;
			}
			if ( ! empty( $data['audio_url'] ) && ! $data['audio_id'] ) {
				$out['media_unmatched'] = ( $out['media_unmatched'] ?? 0 ) + 1;
			}
			if ( ! $wpdb->insert( $songs, $data ) ) {
				$out['errors'][] = "{$serial}: could not be saved.";
				continue;
			}
			$song_id = (int) $wpdb->insert_id;
			Songpress_History::log( $song_id, 'imported' );
			// Writers are never linked to accounts here. Use SongPress > Link writers to confirm each link.
			Songpress_Songs::replace_credits( $song_id, $m['credits'] );
			foreach ( $m['identifiers'] as $i ) {
				$wpdb->insert( Songpress_Install::table( 'identifiers' ), array( 'song_id' => $song_id, 'kind' => $i['kind'], 'value' => substr( $i['value'], 0, 64 ) ) );
			}
			foreach ( $m['links'] as $l ) {
				$wpdb->insert( Songpress_Install::table( 'links' ), array( 'song_id' => $song_id, 'kind' => $l['kind'], 'url' => esc_url_raw( $l['url'] ) ) );
			}
			$out['imported']++;
		}
		return $out;
	}
}
