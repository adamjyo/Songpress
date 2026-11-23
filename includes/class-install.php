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
		// DistroKid was crediting the whole statement among performers; redo those quarters with performer shares, once.
		if ( '1' !== get_option( 'songpress_dk_shares' ) ) {
			Songpress_Uploads::rebuild_dk_people();
			update_option( 'songpress_dk_shares', '1' );
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
		if ( ! get_option( 'songpress_pages_done' ) ) {
			self::create_pages();
		}
		self::move_main_page();
	}

	/** The two front-end pages. Only created when missing, never overwritten, and only by someone who may publish pages. */
	public static function pages() {
		return array(
			'songpress_page_catalog' => array( 'title' => __( 'SongPress', 'songpress' ), 'slug' => 'desktop', 'code' => '[songpress_catalog]' ),
			'songpress_page_profile' => array( 'title' => __( 'My songs', 'songpress' ), 'slug' => 'my-songs', 'code' => '[songpress_profile]' ),
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
		return array( 'families', 'songs', 'credits', 'identifiers', 'links', 'history', 'artists', 'profiles', 'earnings', 'person_earnings', 'payments', 'streams', 'stats', 'legacy_rows', 'expenses', 'journal', 'ratings', 'art', 'agreements', 'signers', 'registrations', 'sessions', 'session_people', 'session_chat', 'activity', 'audit', 'note_versions', 'pitches', 'pitch_kits' );
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
