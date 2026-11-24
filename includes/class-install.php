<?php
defined( 'ABSPATH' ) || exit;

class Songpress_Install {

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'songpress_' . $name;
	}

	public static function activate() {
		self::create_tables();
		Songpress_Caps::migrate_old_roles();
			Songpress_Caps::migrate_class_names();
		add_option( 'songpress_delete_on_uninstall', '0' );
		add_option( 'songpress_public_donate', '0' );
		// Writer accounts start once the catalog is ready: right away on a clean install, or after the first import when songs already exist.
		add_option( 'songpress_accounts_ready', self::has_songs() ? '0' : '1' );
		add_option( 'songpress_donate_prompt', 'pending' ); // asked once, on the first visit to a SongPress screen
		update_option( 'songpress_db_version', SONGPRESS_DB_VERSION );
		if ( '43' !== get_option( 'songpress_version_rules' ) ) {
			Songpress_Songs::apply_version_rules();
			update_option( 'songpress_version_rules', '43' );
		}
		self::ensure_artist();
		self::create_pages();
	}

	/** There is always at least one artist, and every song has one. Existing songs go to the first artist. */
	public static function ensure_artist() {
		global $wpdb;
		$id = Songpress_Artists::default_id();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'songs' ) . ' SET artist_id = %d WHERE artist_id = 0', $id ) );
	}

	public static function has_songs() {
		global $wpdb;
		return (bool) $wpdb->get_var( 'SELECT 1 FROM ' . self::table( 'songs' ) . ' LIMIT 1' );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( Songpress_Updater::CRON );
		// Data, roles and settings are kept. Removal only happens in uninstall.php, and only if the owner asked for it.
	}

	public static function maybe_upgrade() {
		if ( get_option( 'songpress_db_version' ) !== SONGPRESS_DB_VERSION ) {
			self::create_tables();
			Songpress_Caps::migrate_old_roles();
			Songpress_Caps::migrate_class_names();
			update_option( 'songpress_db_version', SONGPRESS_DB_VERSION );
		}
		// One-time catch-ups for songs that already exist. They run on update, not only on activation.
		if ( '43' !== get_option( 'songpress_version_rules' ) ) {
			Songpress_Songs::apply_version_rules();
			update_option( 'songpress_version_rules', '43' );
		}
		// The Type field (Raw, Studio, AI, Live): add its column to existing sites, once. The database version stays the same so older backups can still be restored.
		if ( '1' !== get_option( 'songpress_type_col' ) ) {
			global $wpdb;
			$tb = self::table( 'songs' );
			if ( ! $wpdb->get_var( "SHOW COLUMNS FROM {$tb} LIKE 'work_type'" ) ) { // phpcs:ignore WordPress.DB
				$wpdb->query( "ALTER TABLE {$tb} ADD COLUMN work_type varchar(16) NOT NULL DEFAULT '' AFTER stage" ); // phpcs:ignore WordPress.DB
			}
			update_option( 'songpress_type_col', '1' );
		}
		// Versions that were already turned into works of their own (serial changed to a plain number): make them look like works, once.
		if ( '1' !== get_option( 'songpress_roots_fixed3' ) ) {
			global $wpdb;
			$tb = self::table( 'songs' );
			$hs = self::table( 'history' );
			foreach ( (array) $wpdb->get_col( "SELECT DISTINCT h.song_id FROM {$hs} h JOIN {$tb} s ON s.id = h.song_id WHERE h.action IN ('serial_changed','made_root') AND s.version_no = 0" ) as $sid ) { // phpcs:ignore WordPress.DB
				Songpress_Songs::become_root( (int) $sid );
			}
			update_option( 'songpress_roots_fixed3', '1' );
		}
		// Time signatures a spreadsheet turned into dates (3/4 became March 4) are put back, once.
		if ( '1' !== get_option( 'songpress_timesig_fixed' ) ) {
			global $wpdb;
			$tb = self::table( 'songs' );
			$fixed = 0;
			$left  = array();
			foreach ( (array) $wpdb->get_results( "SELECT id, time_signature FROM {$tb} WHERE time_signature <> ''" ) as $r ) { // phpcs:ignore WordPress.DB
				$to = Songpress_Songs::time_from_date( $r->time_signature );
				if ( null !== $to && $to !== $r->time_signature ) {
					$wpdb->update( $tb, array( 'time_signature' => $to ), array( 'id' => (int) $r->id ) );
					++$fixed;
				} elseif ( ! in_array( $r->time_signature, Songpress_Songs::TIME_SIGNATURES, true ) ) {
					$left[ $r->time_signature ] = 1;
				}
			}
			update_option( 'songpress_timesig_fixed', '1' );
			if ( class_exists( 'Songpress_Audit' ) ) {
				Songpress_Audit::log( 'catalog', 'Fixed time signatures', $fixed . ' put back from dates', array( 'after' => implode( ', ', array_slice( array_keys( $left ), 0, 30 ) ) ) );
			}
		}
		// Record label credits were removed on request: delete every label credit, once.
		if ( '1' !== get_option( 'songpress_labels_removed' ) ) {
			global $wpdb;
			$cr = self::table( 'credits' );
			$n  = (int) $wpdb->query( "DELETE FROM {$cr} WHERE role = 'label'" ); // phpcs:ignore WordPress.DB
			update_option( 'songpress_labels_removed', '1' );
			if ( class_exists( 'Songpress_Audit' ) && method_exists( 'Songpress_Audit', 'log' ) ) {
				Songpress_Audit::log( 'catalog', 'Removed label credits', $n . ' deleted' );
			}
		}
		// Old payment lines from the stored catalog come across as paid, once, with no new upload.
		if ( '1' !== get_option( 'songpress_paid_fix' ) ) {
			Songpress_Legacy::payments_from_stored();
			update_option( 'songpress_paid_fix', '1' );
		}
		// DistroKid was crediting the whole statement among performers; redo those quarters with performer shares, once.
		if ( '1' !== get_option( 'songpress_dk_shares' ) ) {
			Songpress_Uploads::rebuild_dk_people();
			update_option( 'songpress_dk_shares', '1' );
		}
		// Nikki Sanders and Nikki Braby are the same person; the name kept is Braby. Once.
		if ( '1' !== get_option( 'songpress_merge_nikki' ) && class_exists( 'Songpress_Persons' ) ) {
			Songpress_Persons::merge_into( 'Nikki Sanders', 'Nikki Braby' );
			update_option( 'songpress_merge_nikki', '1' );
		}
		// The Lists screen is gone: statuses and genres now come from the built-in options and from what works carry.
		delete_option( 'songpress_statuses' );
		delete_option( 'songpress_genres' );
		if ( '49' !== get_option( 'songpress_tokens' ) ) {
			Songpress_Songs::ensure_tokens();
			update_option( 'songpress_tokens', '49' );
		}
		if ( '48' !== get_option( 'songpress_unarchive' ) ) {
			Songpress_Songs::unarchive_all();
			update_option( 'songpress_unarchive', '48' );
		}
		if ( '47' !== get_option( 'songpress_titles' ) ) {
			Songpress_Songs::unify_titles();
			update_option( 'songpress_titles', '47' );
		}
		self::ensure_artist();
		if ( ! get_option( 'songpress_pages_done' ) || '1' !== get_option( 'songpress_pages_v2' ) ) {
			self::create_pages();
			update_option( 'songpress_pages_v2', '1' );
		}
		self::move_main_page();
	}

	/** The two front-end pages. Only created when missing, never overwritten, and only by someone who may publish pages. */
	public static function pages() {
		return array(
			'songpress_page_catalog' => array( 'title' => __( 'SongPress', 'songpress' ), 'slug' => 'desktop', 'code' => '[songpress_catalog]' ),
			'songpress_page_profile' => array( 'title' => __( 'My songs', 'songpress' ), 'slug' => 'my-songs', 'code' => '[songpress_profile]' ),
			'songpress_page_transcriber' => array( 'title' => __( 'Transcriber', 'songpress' ), 'slug' => 'transcriber', 'code' => '[songpress_transcriber]' ),
			'songpress_page_app'     => array( 'title' => __( 'SongPress', 'songpress' ), 'slug' => 'app', 'code' => '[songpress_catalog]' ),
		);
	}

	/** The main page used to be made at /catalog. Move it to /desktop once, unless that address is taken or the owner already renamed it. */
	public static function move_main_page() {
		$id = (int) get_option( 'songpress_page_catalog' );
		if ( ! current_user_can( 'edit_pages' ) || ! self::page_ok( $id ) || '1' === get_option( 'songpress_main_moved' ) ) {
			return;
		}
		update_option( 'songpress_main_moved', '1' );
		$p = get_post( $id );
		if ( $p && 'catalog' === $p->post_name && ! get_page_by_path( 'desktop' ) ) {
			wp_update_post( array( 'ID' => $id, 'post_name' => 'desktop', 'post_title' => 'SongPress' === $p->post_title || 'Catalog' === $p->post_title ? __( 'SongPress', 'songpress' ) : $p->post_title ) );
		}
	}

	public static function page_ok( $id ) {
		$p = $id ? get_post( (int) $id ) : null;
		return $p && 'page' === $p->post_type && 'trash' !== $p->post_status;
	}

	/** @return int number of pages created */
	public static function create_pages() {
		if ( ! current_user_can( 'publish_pages' ) ) {
			return 0;
		}
		$made = 0;
		foreach ( self::pages() as $opt => $def ) {
			if ( self::page_ok( get_option( $opt ) ) ) {
				continue;
			}
			// A page at the old plugin's address (/desktop, /app) is taken over, so the links people already have keep working.
			$old = get_page_by_path( $def['slug'] );
			if ( $old && 'trash' !== $old->post_status ) {
				if ( false === strpos( (string) $old->post_content, '[songpress_' ) ) {
					wp_update_post( array( 'ID' => $old->ID, 'post_status' => 'publish', 'post_content' => '<!-- wp:shortcode -->' . $def['code'] . '<!-- /wp:shortcode -->' ) );
				}
				update_option( $opt, (int) $old->ID );
				++$made;
				continue;
			}
			$id = wp_insert_post( array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $def['title'],
				'post_name'    => $def['slug'],
				'post_content' => '<!-- wp:shortcode -->' . $def['code'] . '<!-- /wp:shortcode -->',
			), true );
			if ( ! is_wp_error( $id ) && $id ) {
				update_option( $opt, (int) $id );
				++$made;
			}
		}
		update_option( 'songpress_pages_done', '1' );
		return $made;
	}

	public static function table_names() {
		return array( 'families', 'songs', 'credits', 'identifiers', 'links', 'history', 'artists', 'profiles', 'earnings', 'person_earnings', 'payments', 'streams', 'stats', 'legacy_rows', 'expenses', 'journal', 'ratings', 'favorites', 'usage', 'playlists', 'playlist_songs', 'art', 'agreements', 'signers', 'registrations', 'sessions', 'session_people', 'session_chat', 'activity', 'audit', 'note_versions', 'pitches', 'pitch_kits' );
	}

	/** Where the schema lives. Edit SongPress.SQL, not this file, to change the database. */
	public static function sql_file() {
		return SONGPRESS_DIR . 'SongPress.SQL';
	}

	/** Split SongPress.SQL into statements, with the placeholders filled in. */
	public static function statements( $sql, $prefix, $charset ) {
		$sql = preg_replace( '/^\s*--.*$/m', '', $sql );
		$sql = str_replace( array( '{prefix}', '{charset}' ), array( $prefix, $charset ), $sql );
		$out = array();
		foreach ( preg_split( '/;\s*(?:\n|$)/', $sql ) as $stmt ) {
			$stmt = trim( $stmt );
			if ( '' !== $stmt ) {
				$out[] = $stmt . ';';
			}
		}
		return $out;
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$sql = file_get_contents( self::sql_file() );
		if ( false === $sql ) {
			return;
		}
		foreach ( self::statements( $sql, $wpdb->prefix, $wpdb->get_charset_collate() ) as $stmt ) {
			dbDelta( $stmt );
		}
	}
}
