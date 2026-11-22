<?php
defined( 'ABSPATH' ) || exit;

class Songpress_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_songpress_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_songpress_new_version', array( __CLASS__, 'handle_new_version' ) );
		add_action( 'admin_post_songpress_import_check', array( __CLASS__, 'handle_import_check' ) );
		add_action( 'admin_post_songpress_import_run', array( __CLASS__, 'handle_import_run' ) );
		add_action( 'admin_post_songpress_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_songpress_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'wp_ajax_songpress_upload', array( 'Songpress_Media', 'ajax_upload' ) );
		add_action( 'admin_post_songpress_members', array( __CLASS__, 'handle_members' ) );
		add_action( 'admin_post_songpress_start_fresh', array( __CLASS__, 'handle_start_fresh' ) );
		add_action( 'admin_post_songpress_make_accounts', array( __CLASS__, 'handle_make_accounts' ) );
		add_action( 'admin_post_songpress_pages', array( __CLASS__, 'handle_pages' ) );
		add_action( 'admin_post_songpress_share', array( __CLASS__, 'handle_share' ) );
		add_action( 'admin_post_songpress_archive', array( __CLASS__, 'handle_archive' ) );
		add_action( 'admin_post_songpress_link_writers', array( __CLASS__, 'handle_link_writers' ) );
		add_action( 'admin_post_songpress_donate_answer', array( __CLASS__, 'handle_donate_answer' ) );
		add_action( 'admin_footer', array( __CLASS__, 'donate_prompt' ) );
		add_action( 'admin_head', array( __CLASS__, 'css' ) );
	}

	public static function menu() {
		if ( ! Songpress_Caps::can_view() ) {
			return; // people without a SongPress class do not see the plugin at all
		}
		add_menu_page( 'SongPress', 'SongPress', 'read', 'songpress', array( __CLASS__, 'page_catalog' ), 'dashicons-format-audio', 26 );
		add_submenu_page( 'songpress', __( 'Catalog', 'songpress' ), __( 'Catalog', 'songpress' ), 'read', 'songpress', array( __CLASS__, 'page_catalog' ) );
		foreach ( array(
			array( 'create', __( 'Add song', 'songpress' ), 'songpress-song', 'page_song' ),
			array( 'import', __( 'Import', 'songpress' ), 'songpress-import', 'page_import' ),
			array( 'link', __( 'Link writers', 'songpress' ), 'songpress-link', 'page_link_writers' ),
			array( 'members', __( 'Members', 'songpress' ), 'songpress-members', 'page_members' ),
			array( 'settings', __( 'Settings', 'songpress' ), 'songpress-settings', 'page_settings' ),
		) as $m ) {
			if ( Songpress_Caps::can( $m[0] ) ) {
				add_submenu_page( 'songpress', $m[1], $m[1], 'read', $m[2], array( __CLASS__, $m[3] ) );
			}
		}
	}

	public static function css() {
		echo '<style>.songpress-wrap details{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:10px 14px;margin:10px 0;max-width:880px}.songpress-wrap summary{font-weight:600;cursor:pointer}.songpress-wrap .sp-help{color:#646970;margin:6px 0}.songpress-wrap textarea.sp-chart{width:100%;min-height:220px;font-family:monospace}.songpress-wrap .sp-serial{font-family:monospace;font-weight:600}.songpress-donate{margin:28px 0 8px;text-align:center}.songpress-donate a{text-decoration:none;font-weight:600;letter-spacing:.04em}@media print{.songpress-donate{display:none}}</style>';
	}

	private static function url( $page, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	private static function back( $page, $args = array() ) {
		wp_safe_redirect( self::url( $page, $args ) );
		exit;
	}

	/* ---------- catalog ---------- */

	public static function page_catalog() {
		if ( ! Songpress_Caps::can_view() ) {
			wp_die( esc_html__( 'You do not have permission to see the catalog.', 'songpress' ) );
		}
		$all    = Songpress_Caps::can( 'view_all' );
		$q      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$status = isset( Songpress_Songs::STATUSES[ $status ] ) ? $status : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$arch   = Songpress_Caps::can( 'archive' ) && ! empty( $_GET['archived'] ) ? 1 : 0;
		$res    = Songpress_Songs::query( array(
			'search' => $q, 'status' => $status, 'page' => $paged, 'per_page' => 25, 'archived' => $arch,
			'user_id' => $all ? 0 : get_current_user_id(),
		) );
		$pages = max( 1, (int) ceil( $res['total'] / 25 ) );
		echo '<div class="wrap songpress-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Catalog', 'songpress' ) . '</h1>';
		if ( Songpress_Caps::can( 'create' ) ) {
			echo ' <a class="page-title-action" href="' . esc_url( self::url( 'songpress-song' ) ) . '">' . esc_html__( 'Add song', 'songpress' ) . '</a>';
		}
		if ( Songpress_Caps::can( 'export' ) ) {
			echo ' <a class="page-title-action" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=songpress_export' ), 'songpress_export' ) ) . '">' . esc_html__( 'Export CSV', 'songpress' ) . '</a>';
		}
		echo '<hr class="wp-header-end">';
		if ( ! $all ) {
			echo '<p class="sp-help">' . esc_html__( 'You see the songs you are credited on.', 'songpress' ) . '</p>';
		}
		if ( Songpress_Caps::can( 'archive' ) ) {
			$n = Songpress_Songs::count_archived();
			echo '<ul class="subsubsub"><li><a href="' . esc_url( self::url( 'songpress' ) ) . '"' . ( $arch ? '' : ' class="current"' ) . '>' . esc_html__( 'Active', 'songpress' ) . '</a> | </li><li><a href="' . esc_url( self::url( 'songpress', array( 'archived' => 1 ) ) ) . '"' . ( $arch ? ' class="current"' : '' ) . '>' . esc_html__( 'Archived', 'songpress' ) . ' (' . (int) $n . ')</a></li></ul><br class="clear">';
		}
		if ( ! $res['total'] && '' === $q && '' === $status && ! $arch && Songpress_Caps::can( 'create' ) ) {
			echo '<div class="songpress-welcome" style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px 22px;margin:14px 0;max-width:720px"><h2 style="margin-top:0">' . esc_html__( 'Welcome to SongPress', 'songpress' ) . '</h2>';
			echo '<p>' . esc_html__( 'Your catalog is empty. Start with a song, or bring in an existing catalog.', 'songpress' ) . '</p><p>';
			echo '<a class="button button-primary" href="' . esc_url( self::url( 'songpress-song' ) ) . '">' . esc_html__( 'Add your first song', 'songpress' ) . '</a> ';
			if ( Songpress_Caps::can( 'import' ) ) {
				echo '<a class="button" href="' . esc_url( self::url( 'songpress-import' ) ) . '">' . esc_html__( 'Import an S&P Catalog file', 'songpress' ) . '</a> ';
			}
			if ( Songpress_Caps::can( 'members' ) ) {
				echo '<a class="button" href="' . esc_url( self::url( 'songpress-members' ) ) . '">' . esc_html__( 'Set up members', 'songpress' ) . '</a> ';
			}
			echo '</p><p class="sp-help">' . esc_html__( 'The Catalog and My songs pages for phones were already created for you. Find them in SongPress > Settings.', 'songpress' ) . '</p></div>';
		}
		echo '<form method="get"><input type="hidden" name="page" value="songpress">' . ( $arch ? '<input type="hidden" name="archived" value="1">' : '' ) . '<p class="search-box" style="float:none;margin:8px 0">';
		echo '<input type="search" name="s" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Title, serial or artist', 'songpress' ) . '"> ';
		echo '<select name="status"><option value="">' . esc_html__( 'All statuses', 'songpress' ) . '</option>';
		foreach ( Songpress_Songs::STATUSES as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $status, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <button class="button">' . esc_html__( 'Search', 'songpress' ) . '</button></p></form>';

		echo '<table class="widefat striped"><thead><tr><th style="width:90px">' . esc_html__( 'Serial', 'songpress' ) . '</th><th>' . esc_html__( 'Song', 'songpress' ) . '</th><th>' . esc_html__( 'Status', 'songpress' ) . '</th></tr></thead><tbody>';
		if ( ! $res['rows'] ) {
			echo '<tr><td colspan="3">' . esc_html__( 'No songs yet.', 'songpress' ) . '</td></tr>';
		}
		foreach ( $res['rows'] as $s ) {
			$link  = self::url( Songpress_Caps::can_edit_song( (int) $s->id ) ? 'songpress-song' : 'songpress', array( 'song' => (int) $s->id ) );
			$by    = Songpress_Songs::authors_line( (int) $s->id, get_current_user_id() );
			echo '<tr><td class="sp-serial">' . esc_html( $s->serial ) . '</td><td><a href="' . esc_url( $link ) . '"><strong>' . esc_html( '' !== $s->title ? $s->title : __( '(untitled)', 'songpress' ) ) . '</strong></a>';
			if ( '' !== $by ) {
				echo '<br><span class="sp-help">' . esc_html( $by ) . '</span>';
			}
			echo '</td><td>' . esc_html( Songpress_Songs::STATUSES[ $s->status ] ?? $s->status ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array(
			'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages,
			'prev_text' => '&lsaquo;', 'next_text' => '&rsaquo;',
		) ) ) . '</div></div>';
		echo wp_kses_post( songpress_donate_link() ) . '</div>';

		// A writer who clicked a song sees a read-only view.
		if ( isset( $_GET['song'] ) && ! Songpress_Caps::can_edit_song( absint( $_GET['song'] ) ) ) {
			self::read_only( absint( $_GET['song'] ) );
		}
	}

	private static function read_only( $id ) {
		if ( ! Songpress_Caps::can_see_song( $id ) ) {
			return;
		}
		$s = Songpress_Songs::get( $id );
		if ( ! $s ) {
			return;
		}
		list( $kind, $text ) = Songpress_Songs::best_chart( $s );
		echo '<div class="wrap songpress-wrap"><h2>' . esc_html( $s->serial . '  ' . $s->title ) . '</h2>';
		echo '<p class="sp-help">' . esc_html( Songpress_Songs::authors_line( $id, get_current_user_id() ) ) . '</p>';
		echo '<p class="sp-help">' . esc_html( array( 'chordpro' => 'ChordPro', 'chords_lyrics' => 'Chords and lyrics', 'lyrics' => 'Lyrics', 'none' => 'No lyrics yet' )[ $kind ] ) . '</p>';
		echo '<div style="background:#fff;padding:12px;border:1px solid #dcdcde;max-width:880px">' . ( 'chordpro' === $kind ? wp_kses_post( Songpress_ChordPro::render( $text ) ) : '<pre style="white-space:pre-wrap;margin:0">' . esc_html( $text ) . '</pre>' ) . '</div></div>';
	}

	/* ---------- song form ---------- */

	public static function page_song() {
		$want = isset( $_GET['song'] ) ? absint( $_GET['song'] ) : 0;
		if ( $want ? ! Songpress_Caps::can_edit_song( $want ) : ! Songpress_Caps::can( 'create' ) ) {
			wp_die( esc_html__( 'You do not have permission to edit this song.', 'songpress' ) );
		}
		wp_enqueue_media();
		$id   = isset( $_GET['song'] ) ? absint( $_GET['song'] ) : 0;
		$song = $id ? Songpress_Songs::get( $id ) : null;
		$g    = function ( $f ) use ( $song ) {
			return $song ? (string) $song->$f : '';
		};
		echo '<div class="wrap songpress-wrap"><h1>' . ( $song ? esc_html( $song->serial . '  ' . $song->title ) : esc_html__( 'Add song', 'songpress' ) ) . '</h1>';
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'songpress' ) . '</p></div>';
		}
		if ( isset( $_GET['dropped'] ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'ISRC and DistroKid codes were not saved because this is the original work. Add a version and enter them there.', 'songpress' ) . '</p></div>';
		}
		if ( isset( $_GET['noacct'] ) ) {
			/* translators: %s: names */
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'No account was found for: %s. Those writers were saved without a link.', 'songpress' ), sanitize_text_field( wp_unslash( $_GET['noacct'] ) ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['shares'] ) ) {
			/* translators: %s: total percent */
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'Saved, but the writer shares add up to %s%%, not 100.', 'songpress' ), sanitize_text_field( wp_unslash( $_GET['shares'] ) ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['err'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['err'] ) ) ) . '</p></div>';
		}
		echo '<p class="sp-help">' . esc_html__( 'The serial number is assigned for you. The original work is just four digits, like 0012. Each version after it adds two more, like 0012-01. The original has no release information: add a version for a recording or a release.', 'songpress' ) . '</p>';
		if ( $song && $song->archived_at ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This song is archived. It is hidden from the catalog and from public pages, and its serial number stays reserved. Restore it to bring it back.', 'songpress' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'songpress_save' );
		echo '<input type="hidden" name="action" value="songpress_save"><input type="hidden" name="song_id" value="' . (int) $id . '"><input type="hidden" name="revision" value="' . (int) ( $song->revision ?? 0 ) . '">';

		echo '<details open><summary>' . esc_html__( 'The basics', 'songpress' ) . '</summary><table class="form-table" role="presentation">';
		self::row( 'title', __( 'Title', 'songpress' ), '<input class="regular-text" name="title" value="' . esc_attr( $g( 'title' ) ) . '" required>' );
		$opts     = '';
		$version  = $song ? (int) $song->version_no : 0;
		$statuses = Songpress_Songs::statuses_for( $version );
		if ( $song && isset( Songpress_Songs::STATUSES[ $song->status ] ) ) {
			$statuses[ $song->status ] = Songpress_Songs::STATUSES[ $song->status ]; // keep an imported value instead of silently changing it
		}
		foreach ( $statuses as $k => $label ) {
			$opts .= '<option value="' . esc_attr( $k ) . '"' . selected( $g( 'status' ) ?: 'idea', $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		self::row( 'status', __( 'Status', 'songpress' ), '<select name="status">' . $opts . '</select>' );
		self::row( 'musical_key', __( 'Key', 'songpress' ), '<input name="musical_key" size="6" value="' . esc_attr( $g( 'musical_key' ) ) . '"> ' . esc_html__( 'Tempo', 'songpress' ) . ' <input name="tempo" size="5" value="' . esc_attr( $g( 'tempo' ) ) . '">' );
		echo '</table></details>';

		echo '<details><summary>' . esc_html__( 'Lyrics and chart', 'songpress' ) . '</summary>';
		echo '<p class="sp-help">' . esc_html__( 'Phones show the first one you fill in, in this order: ChordPro, then chords and lyrics, then lyrics only.', 'songpress' ) . '</p>';
		foreach ( array( 'chordpro' => __( 'ChordPro', 'songpress' ), 'chords_lyrics' => __( 'Chords and lyrics', 'songpress' ), 'lyrics' => __( 'Lyrics only', 'songpress' ) ) as $f => $label ) {
			echo '<p><label><strong>' . esc_html( $label ) . '</strong><br><textarea class="sp-chart" name="' . esc_attr( $f ) . '">' . esc_textarea( $g( $f ) ) . '</textarea></label></p>';
		}
		echo '</details>';

		echo '<details open><summary>' . esc_html__( 'People', 'songpress' ) . '</summary>';
		echo '<p class="sp-help">' . esc_html__( 'Writer shares are the splits for this version and should add up to 100. Everyone listed here belongs to this version only. Type a username or email in Account to let that person see this song on their own profile. Add as many people as the song has.', 'songpress' ) . '</p>';
		$by_role = array( 'writer' => array(), 'publisher' => array(), 'performer' => array(), 'producer' => array() );
		foreach ( $song ? Songpress_Songs::credits( $id ) : array() as $c ) {
			if ( isset( $by_role[ $c->role ] ) ) {
				$by_role[ $c->role ][] = $c;
			}
		}
		self::credit_group( 'writer', __( 'Writers', 'songpress' ), __( 'Add writer', 'songpress' ), $by_role['writer'], 2, true, true );
		self::credit_group( 'publisher', __( 'Publishers', 'songpress' ), __( 'Add publisher', 'songpress' ), $by_role['publisher'], 1, false, true );
		self::credit_group( 'performer', __( 'Performers', 'songpress' ), __( 'Add performer', 'songpress' ), $by_role['performer'], 1, false, false );
		self::credit_group( 'producer', __( 'Producers', 'songpress' ), __( 'Add producer', 'songpress' ), $by_role['producer'], 1, false, true );
		echo '</details>';
		self::credit_script();
		Songpress_Media::picker_script();

		echo '<details class="sp-media-section"><summary>' . esc_html__( 'Audio and cover art', 'songpress' ) . '</summary>';
		echo '<p class="sp-help">' . esc_html__( 'Drag an image or an audio file anywhere onto this form. It is saved to the WordPress Media Library and attached here. You can also choose a file that is already in the library.', 'songpress' ) . '</p><p class="sp-drop-msg" id="sp-drop-msg" role="status" aria-live="polite"></p><table class="form-table" role="presentation">';
		self::row( 'audio_id', __( 'Audio file', 'songpress' ), Songpress_Media::picker( 'audio', __( 'Choose audio from the Media Library', 'songpress' ), 'audio', (int) $g( 'audio_id' ), $g( 'audio_url' ) ) );
		self::row( 'art_id', __( 'Cover art', 'songpress' ), Songpress_Media::picker( 'art', __( 'Choose cover art from the Media Library', 'songpress' ), 'image', (int) $g( 'art_id' ), $g( 'art_url' ) ) );
		echo '</table></details>';

		// Release information belongs to versions. The original work has none, unless an import brought some in.
		$legacy_release = $song && 0 === $version && ( '' !== $g( 'artist' ) . $g( 'album' ) . $g( 'release_title' ) . $g( 'release_date' ) );
		if ( $song && ( $version > 0 || $legacy_release ) ) {
			echo '<details' . ( $legacy_release ? ' open' : '' ) . '><summary>' . esc_html__( 'Release', 'songpress' ) . '</summary>';
			if ( $legacy_release ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This is the original work, which should not have release information. It came from the old catalog. Add a version and enter it there.', 'songpress' ) . '</p></div>';
			}
			echo '<table class="form-table" role="presentation">';
			self::row( 'artist', __( 'Artist', 'songpress' ), '<input class="regular-text" name="artist" value="' . esc_attr( $g( 'artist' ) ) . '">' );
			self::row( 'album', __( 'Album', 'songpress' ), '<input class="regular-text" name="album" value="' . esc_attr( $g( 'album' ) ) . '">' );
			self::row( 'release_title', __( 'Release title', 'songpress' ), '<input class="regular-text" name="release_title" value="' . esc_attr( $g( 'release_title' ) ) . '">' );
			self::row( 'release_date', __( 'Release date', 'songpress' ), '<input name="release_date" value="' . esc_attr( $g( 'release_date' ) ) . '">' );
			echo '</table></details>';
		} else {
			echo '<p class="sp-help">' . esc_html__( 'Release details (artist, album, release date) appear on versions. Use Add a version when this song is recorded or released.', 'songpress' ) . '</p>';
		}

		echo '<details><summary>' . esc_html__( 'Codes and links', 'songpress' ) . '</summary>';
		echo '<p class="sp-help">' . esc_html__( 'Add any codes this song has (ISWC, CCLI, MLC, ISRC and so on) and any links (streaming, store, video). There is no limit. ISRC and DistroKid codes belong on versions.', 'songpress' ) . '</p>';
		self::pair_group( 'codes', __( 'Codes', 'songpress' ), __( 'Add a code', 'songpress' ), $song ? Songpress_Songs::identifiers( $id ) : array(), 'kind', 'value', Songpress_Songs::CODE_KINDS, __( 'code', 'songpress' ) );
		self::pair_group( 'links', __( 'Links', 'songpress' ), __( 'Add a link', 'songpress' ), $song ? Songpress_Songs::links( $id ) : array(), 'kind', 'url', Songpress_Songs::LINK_KINDS, __( 'https://', 'songpress' ) );
		echo '</details>';
		self::pair_script();

		echo '<details><summary>' . esc_html__( 'Notes', 'songpress' ) . '</summary><p><textarea class="sp-chart" name="notes" style="min-height:100px">' . esc_textarea( $g( 'notes' ) ) . '</textarea></p></details>';
		submit_button( $song ? __( 'Save', 'songpress' ) : __( 'Create song', 'songpress' ) );
		echo '</form>';

		if ( $song ) {
			if ( Songpress_Caps::can( 'create' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:6px">';
			wp_nonce_field( 'songpress_new_version' );
			echo '<input type="hidden" name="action" value="songpress_new_version"><input type="hidden" name="song_id" value="' . (int) $id . '">';
			echo '<p class="sp-help">' . esc_html__( 'A version is a new recording, arrangement or release of this song. It gets the next number, like 0012-01. Writers and splits start as a copy of this one, and you can add writers or change splits freely. Performers and producers start empty. Nothing you change on a version affects the original or the other versions.', 'songpress' ) . '</p>';
			submit_button( __( 'Add a version', 'songpress' ), 'secondary' );
			echo '</form>';
			}
			self::versions( (int) $song->family_id, $id );
			if ( Songpress_Caps::can_share_song( $id ) ) {
				self::share_box( $song );
			}
			if ( Songpress_Caps::can( 'archive' ) ) {
				self::archive_form( $song );
			}
			self::history_panel( $id );
		}
		echo wp_kses_post( songpress_donate_link() ) . '</div>';
	}

	public static function handle_start_fresh() {
		check_admin_referer( 'songpress_start_fresh' );
		if ( ! Songpress_Caps::can( 'import' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		update_option( 'songpress_accounts_ready', '1' );
		self::back( 'songpress-import' );
	}

	/** One block of people (writers, performers or producers) that starts with $min rows and grows one at a time. */
	private static function credit_group( $role, $title, $add_label, array $existing, $min, $with_user, $with_share ) {
		$count = max( $min, count( $existing ) );
		echo '<h4 style="margin:14px 0 6px">' . esc_html( $title ) . '</h4>';
		echo '<table class="widefat sp-credits" data-role="' . esc_attr( $role ) . '" data-next="' . (int) $count . '"><thead><tr><th>' . esc_html__( 'Name', 'songpress' ) . '</th><th>IPI</th>';
		echo $with_share ? '<th>' . esc_html__( 'Share %', 'songpress' ) . '</th>' : '';
		echo $with_user ? '<th>' . esc_html__( 'Account', 'songpress' ) . '</th>' : '';
		echo '<th></th></tr></thead><tbody>';
		for ( $i = 0; $i < $count; $i++ ) {
			echo self::credit_row( $role, (string) $i, $existing[ $i ] ?? null, $with_user, $with_share ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside credit_row.
		}
		echo '</tbody></table>';
		echo '<template id="sp-tpl-' . esc_attr( $role ) . '">' . self::credit_row( $role, '__I__', null, $with_user, $with_share ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p><button type="button" class="button sp-add" data-role="' . esc_attr( $role ) . '">+ ' . esc_html( $add_label ) . '</button>';
		if ( $with_share && 'writer' === $role ) {
			echo ' <span class="sp-total" aria-live="polite"></span>';
		}
		echo '</p>';
	}

	private static function credit_row( $role, $i, $c, $with_user, $with_share ) {
		$n = 'credits[' . $role . '][' . $i . ']';
		$h = '<tr><td><input name="' . esc_attr( $n ) . '[name]" value="' . esc_attr( $c->name ?? '' ) . '"></td>';
		$h .= '<td><input name="' . esc_attr( $n ) . '[ipi]" size="12" value="' . esc_attr( $c->ipi ?? '' ) . '"></td>';
		if ( $with_share ) {
			$share = ( isset( $c->share ) && null !== $c->share ) ? rtrim( rtrim( (string) $c->share, '0' ), '.' ) : '';
			$h .= '<td><input name="' . esc_attr( $n ) . '[share]" size="6" value="' . esc_attr( $share ) . '"></td>';
		}
		if ( $with_user ) {
			$login = '';
			if ( ! empty( $c->user_id ) ) {
				$u     = get_userdata( (int) $c->user_id );
				$login = $u ? $u->user_login : '';
			}
			$h .= '<td><input name="' . esc_attr( $n ) . '[account]" size="14" placeholder="' . esc_attr__( 'username or email', 'songpress' ) . '" value="' . esc_attr( $login ) . '"></td>';
		}
		return $h . '<td><button type="button" class="button-link sp-remove" aria-label="' . esc_attr__( 'Remove this row', 'songpress' ) . '">&times;</button></td></tr>';
	}

	/** Add and remove rows without a page reload. Empty rows are dropped when saving. */
	private static function credit_script() {
		echo '<script>(function(){function total(){var t=document.querySelector(".sp-credits[data-role=writer]"),o=document.querySelector(".sp-total");if(!t||!o)return;var s=0,any=false;t.querySelectorAll("input[name$=\"[share]\"]").forEach(function(i){var v=parseFloat(i.value);if(!isNaN(v)){s+=v;any=true;}});s=Math.round(s*10000)/10000;if(!any){o.textContent="";return;}o.textContent="Writer shares total: "+s+"%"+(Math.abs(s-100)>0.05?" (should be 100)":"");o.style.color=Math.abs(s-100)>0.05?"#b32d2e":"#007017";}document.addEventListener("input",function(e){if(e.target.matches&&e.target.matches("input[name$=\"[share]\"]")){total();}});document.addEventListener("click",function(e){var b=e.target.closest(".sp-add");if(b){var r=b.getAttribute("data-role"),t=document.querySelector(".sp-credits[data-role="+r+"]"),n=parseInt(t.getAttribute("data-next"),10);t.setAttribute("data-next",n+1);var tpl=document.getElementById("sp-tpl-"+r).innerHTML.replace(/__I__/g,n);t.querySelector("tbody").insertAdjacentHTML("beforeend",tpl);var f=t.querySelector("tbody tr:last-child input");if(f){f.focus();}return;}var x=e.target.closest(".sp-remove");if(x){var tr=x.closest("tr");var tb=tr.parentNode;if(tb.children.length>1){tr.remove();}else{tr.querySelectorAll("input").forEach(function(i){i.value="";});}total();}});total();})();</script>';
	}

	/** A growing list of two-field rows (kind + value) for codes or links. */
	private static function pair_group( $group, $title, $add_label, array $existing, $k_field, $v_field, array $kinds, $placeholder ) {
		$line = function ( $i, $k, $v ) use ( $group, $kinds, $placeholder ) {
			$n = $group . '[' . $i . ']';
			return '<tr><td><input name="' . esc_attr( $n ) . '[kind]" list="sp-kinds-' . esc_attr( $group ) . '" size="12" value="' . esc_attr( $k ) . '"></td><td><input name="' . esc_attr( $n ) . '[value]" class="regular-text" placeholder="' . esc_attr( $placeholder ) . '" value="' . esc_attr( $v ) . '"></td><td><button type="button" class="button-link sp-pair-remove" aria-label="' . esc_attr__( 'Remove', 'songpress' ) . '">&times;</button></td></tr>';
		};
		echo '<h4>' . esc_html( $title ) . '</h4><datalist id="sp-kinds-' . esc_attr( $group ) . '">';
		foreach ( $kinds as $k ) {
			echo '<option value="' . esc_attr( $k ) . '">';
		}
		echo '</datalist><table class="sp-pairs" data-group="' . esc_attr( $group ) . '" data-next="' . (int) max( 1, count( $existing ) ) . '"><tbody>';
		$i = 0;
		foreach ( $existing as $e ) {
			echo $line( $i++, $e->$k_field, $e->$v_field ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside the closure.
		}
		if ( ! $existing ) {
			echo $line( 0, '', '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table><template id="sp-pair-tpl-' . esc_attr( $group ) . '">' . $line( '__I__', '', '' ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p><button type="button" class="button sp-pair-add" data-group="' . esc_attr( $group ) . '">' . esc_html( $add_label ) . '</button></p>';
	}

	private static function pair_script() {
		echo '<script>(function(){document.addEventListener("click",function(e){var a=e.target.closest(".sp-pair-add");if(a){var g=a.getAttribute("data-group"),t=document.querySelector(".sp-pairs[data-group="+g+"]"),n=parseInt(t.getAttribute("data-next"),10);t.setAttribute("data-next",n+1);t.querySelector("tbody").insertAdjacentHTML("beforeend",document.getElementById("sp-pair-tpl-"+g).innerHTML.replace(/__I__/g,n));var f=t.querySelector("tbody tr:last-child input");if(f){f.focus();}return;}var x=e.target.closest(".sp-pair-remove");if(x){var tr=x.closest("tr"),tb=tr.parentNode;if(tb.children.length>1){tr.remove();}else{tr.querySelectorAll("input").forEach(function(i){i.value="";});}}});})();</script>';
	}

	private static function row( $name, $label, $html_field ) {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>' . $html_field . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- fields built with esc_attr above.
	}

	private static function share_box( $song ) {
		$on   = ! empty( $song->share_token );
		$link = $on ? add_query_arg( 'sp_share', $song->share_token, home_url( '/' ) ) : '';
		echo '<h3 style="margin-top:18px">' . esc_html__( 'Share', 'songpress' ) . '</h3>';
		if ( ! empty( $song->archived_at ) ) {
			echo '<p class="sp-help">' . esc_html__( 'Archived songs cannot be shared.', 'songpress' ) . '</p>';
			return;
		}
		echo '<p class="sp-help">' . esc_html__( 'Anyone with the link can read this song, with its cover, audio and chart. They do not need to log in. They never see splits, codes, notes or money. Turn it off, or make a new link, to stop an old link working.', 'songpress' ) . '</p>';
		if ( $on ) {
			echo '<p><input type="text" readonly class="large-text" id="sp-share-link" value="' . esc_attr( $link ) . '" onfocus="this.select()"></p>';
			echo '<p><button type="button" class="button" id="sp-share-copy">' . esc_html__( 'Copy link', 'songpress' ) . '</button> <button type="button" class="button" id="sp-share-send" hidden>' . esc_html__( 'Share…', 'songpress' ) . '</button></p>';
			echo '<script>(function(){var i=document.getElementById("sp-share-link"),c=document.getElementById("sp-share-copy"),s=document.getElementById("sp-share-send");c.addEventListener("click",function(){i.select();try{navigator.clipboard.writeText(i.value).then(function(){c.textContent="Copied";});}catch(e){document.execCommand("copy");c.textContent="Copied";}});if(navigator.share){s.hidden=false;s.addEventListener("click",function(){navigator.share({title:document.title,url:i.value}).catch(function(){});});}})();</script>';
		}
		foreach ( $on ? array( 'new' => __( 'Make a new link', 'songpress' ), 'off' => __( 'Turn sharing off', 'songpress' ) ) : array( 'on' => __( 'Turn sharing on', 'songpress' ) ) as $do => $label ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
			wp_nonce_field( 'songpress_share' );
			echo '<input type="hidden" name="action" value="songpress_share"><input type="hidden" name="song_id" value="' . (int) $song->id . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
			submit_button( $label, 'on' === $do ? 'primary' : 'secondary', 'submit', false );
			echo '</form>';
		}
	}

	public static function handle_share() {
		check_admin_referer( 'songpress_share' );
		$id   = absint( $_POST['song_id'] ?? 0 );
		if ( ! Songpress_Caps::can_share_song( $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		$do   = sanitize_key( $_POST['do'] ?? '' );
		$song = Songpress_Songs::get( $id );
		if ( $song && empty( $song->archived_at ) && in_array( $do, array( 'on', 'new', 'off' ), true ) ) {
			Songpress_Songs::share_set( $id, 'off' !== $do );
		}
		self::back( 'songpress-song', array( 'song' => $id, 'saved' => 1 ) );
	}

	private static function archive_form( $song ) {
		$archived = ! empty( $song->archived_at );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:14px">';
		wp_nonce_field( 'songpress_archive' );
		echo '<input type="hidden" name="action" value="songpress_archive"><input type="hidden" name="song_id" value="' . (int) $song->id . '"><input type="hidden" name="do" value="' . ( $archived ? 'restore' : 'archive' ) . '">';
		if ( ! $archived ) {
			echo '<p class="sp-help">' . ( 0 === (int) $song->version_no
				? esc_html__( 'Archiving the original work also archives all of its versions. Nothing is deleted, and the serial numbers stay reserved.', 'songpress' )
				: esc_html__( 'Archiving hides this version. Nothing is deleted, and its serial number stays reserved.', 'songpress' ) ) . '</p>';
		}
		submit_button( $archived ? __( 'Restore this song', 'songpress' ) : __( 'Archive this song', 'songpress' ), $archived ? 'primary' : 'delete', 'submit', false );
		echo '</form>';
	}

	private static function history_panel( $id ) {
		$rows = Songpress_History::for_song( $id );
		echo '<details style="margin-top:18px"><summary>' . esc_html__( 'History', 'songpress' ) . ' (' . count( $rows ) . ')</summary>';
		if ( ! $rows ) {
			echo '<p class="sp-help">' . esc_html__( 'No changes recorded yet.', 'songpress' ) . '</p></details>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th style="width:150px">' . esc_html__( 'When', 'songpress' ) . '</th><th style="width:140px">' . esc_html__( 'Who', 'songpress' ) . '</th><th>' . esc_html__( 'What', 'songpress' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$u = $r->user_id ? get_userdata( (int) $r->user_id ) : false;
			echo '<tr><td>' . esc_html( get_date_from_gmt( $r->created_at, 'Y-m-d H:i' ) ) . '</td><td>' . esc_html( $u ? $u->display_name : __( 'System', 'songpress' ) ) . '</td><td>' . esc_html( Songpress_History::describe( $r ) ) . '</td></tr>';
		}
		echo '</tbody></table></details>';
	}

	/** The whole catalog as a CSV, one row per song or version, archived songs included and marked. */
	public static function handle_export() {
		check_admin_referer( 'songpress_export' );
		if ( ! Songpress_Caps::can( 'export' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="songpress-catalog-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // so spreadsheets read accents correctly
		fputcsv( $out, Songpress_Songs::export_header() );
		$page = 1;
		do {
			$res = Songpress_Songs::query( array( 'archived' => 'any', 'page' => $page, 'per_page' => 100 ) );
			foreach ( $res['rows'] as $s ) {
				fputcsv( $out, Songpress_Songs::export_row( $s ) );
			}
			++$page;
		} while ( $res['rows'] && ( $page - 1 ) * 100 < $res['total'] );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public static function handle_archive() {
		check_admin_referer( 'songpress_archive' );
		if ( ! Songpress_Caps::can( 'archive' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		$id = absint( $_POST['song_id'] ?? 0 );
		if ( isset( $_POST['do'] ) && 'restore' === $_POST['do'] ) {
			Songpress_Songs::restore( $id );
			self::back( 'songpress-song', array( 'song' => $id, 'saved' => 1 ) );
		}
		Songpress_Songs::archive( $id );
		self::back( 'songpress', array( 'archived' => 1 ) );
	}

	private static function versions( $family_id, $current ) {
		$res = Songpress_Songs::query( array( 'family_id' => $family_id, 'per_page' => 100, 'archived' => 'any' ) );
		if ( count( $res['rows'] ) < 2 ) {
			return;
		}
		echo '<h3>' . esc_html__( 'This song and its versions', 'songpress' ) . '</h3><ul>';
		foreach ( $res['rows'] as $v ) {
			echo '<li><a href="' . esc_url( self::url( 'songpress-song', array( 'song' => (int) $v->id ) ) ) . '"><span class="sp-serial">' . esc_html( $v->serial ) . '</span> ' . esc_html( $v->title ) . '</a>' . ( $v->archived_at ? ' ' . esc_html__( '(archived)', 'songpress' ) : '' ) . ( (int) $v->id === $current ? ' ' . esc_html__( '(this one)', 'songpress' ) : '' ) . '</li>';
		}
		echo '</ul>';
	}

	public static function handle_save() {
		check_admin_referer( 'songpress_save' );
		$id   = isset( $_POST['song_id'] ) ? absint( $_POST['song_id'] ) : 0;
		if ( $id ? ! Songpress_Caps::can_edit_song( $id ) : ! Songpress_Caps::can( 'create' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		if ( $id ) {
			$current = Songpress_Songs::get( $id );
			if ( $current && (int) $current->revision !== absint( $_POST['revision'] ?? 0 ) ) {
				self::back( 'songpress-song', array( 'song' => $id, 'err' => rawurlencode( __( 'Someone else saved this song while you had it open, so your changes were not saved. This page now shows their version. Make your changes again if you still need them.', 'songpress' ) ) ) );
			}
		}
		$data = Songpress_Songs::sanitize( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field inside.
		// Removing a library item also forgets the old outside link, so Remove really removes it.
		if ( ! empty( $_POST['art_clear'] ) && '1' === $_POST['art_clear'] && empty( $data['art_id'] ) ) {
			$data['art_url'] = '';
		}
		if ( ! empty( $_POST['audio_clear'] ) && '1' === $_POST['audio_clear'] && empty( $data['audio_id'] ) ) {
			$data['audio_url'] = '';
		}
		if ( ! isset( $data['title'] ) || '' === $data['title'] ) {
			self::back( 'songpress-song', array( 'song' => $id, 'err' => rawurlencode( __( 'A title is required.', 'songpress' ) ) ) );
		}
		if ( $id ) {
			Songpress_Songs::update( $id, $data );
		} else {
			$id = Songpress_Songs::create( $data, get_current_user_id() );
			if ( is_wp_error( $id ) ) {
				self::back( 'songpress-song', array( 'err' => rawurlencode( $id->get_error_message() ) ) );
			}
		}
		$rows    = array();
		$unknown = array();
		$posted = isset( $_POST['credits'] ) && is_array( $_POST['credits'] ) ? wp_unslash( $_POST['credits'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each field sanitized in replace_credits().
		foreach ( array( 'writer', 'publisher', 'performer', 'producer' ) as $role ) {
			foreach ( ( isset( $posted[ $role ] ) && is_array( $posted[ $role ] ) ) ? $posted[ $role ] : array() as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				$uid = 0;
				$acc = trim( (string) ( $r['account'] ?? '' ) );
				if ( '' !== $acc ) {
					$u = false !== strpos( $acc, '@' ) ? get_user_by( 'email', $acc ) : get_user_by( 'login', $acc );
					if ( $u ) {
						$uid = (int) $u->ID;
					} else {
						$unknown[] = $acc;
					}
				}
				$rows[] = array(
					'role'    => $role,
					'name'    => $r['name'] ?? '',
					'ipi'     => $r['ipi'] ?? '',
					'share'   => $r['share'] ?? '',
					'user_id' => $uid,
				);
			}
		}
		Songpress_Songs::replace_credits( $id, $rows );
		$ver     = (int) ( Songpress_Songs::get( $id )->version_no ?? 0 );
		$codes   = isset( $_POST['codes'] ) && is_array( $_POST['codes'] ) ? array_values( array_filter( wp_unslash( $_POST['codes'] ), 'is_array' ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in replace_identifiers().
		$dropped = Songpress_Songs::replace_identifiers( $id, $codes, $ver );
		$links   = isset( $_POST['links'] ) && is_array( $_POST['links'] ) ? array_values( array_filter( wp_unslash( $_POST['links'] ), 'is_array' ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in replace_links().
		foreach ( $links as &$l ) {
			$l = array( 'kind' => $l['kind'] ?? '', 'url' => $l['value'] ?? '' );
		}
		unset( $l );
		Songpress_Songs::replace_links( $id, $links );
		$sum = 0.0;
		$any = false;
		foreach ( $rows as $r ) {
			if ( 'writer' === $r['role'] && '' !== (string) $r['share'] && is_numeric( $r['share'] ) ) {
				$sum += (float) $r['share'];
				$any  = true;
			}
		}
		$args = array( 'song' => $id, 'saved' => 1 );
		if ( $dropped ) {
			$args['dropped'] = $dropped;
		}
		if ( $unknown ) {
			$args['noacct'] = rawurlencode( implode( ', ', array_map( 'sanitize_text_field', $unknown ) ) );
		}
		if ( $any && abs( $sum - 100.0 ) > 0.05 ) {
			$args['shares'] = rtrim( rtrim( number_format( $sum, 4, '.', '' ), '0' ), '.' );
		}
		self::back( 'songpress-song', $args );
	}

	public static function handle_new_version() {
		check_admin_referer( 'songpress_new_version' );
		if ( ! Songpress_Caps::can( 'create' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		$new = Songpress_Songs::create_version( absint( $_POST['song_id'] ?? 0 ), get_current_user_id() );
		if ( is_wp_error( $new ) ) {
			self::back( 'songpress-song', array( 'song' => absint( $_POST['song_id'] ?? 0 ), 'err' => rawurlencode( $new->get_error_message() ) ) );
		}
		self::back( 'songpress-song', array( 'song' => $new, 'saved' => 1 ) );
	}

	/* ---------- import ---------- */

	public static function page_import() {
		if ( ! Songpress_Caps::can( 'import' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ) );
		}
		$key = 'songpress_import_' . get_current_user_id();
		$tmp = get_transient( $key );
		echo '<div class="wrap songpress-wrap"><h1>' . esc_html__( 'Import from S&P Catalog', 'songpress' ) . '</h1>';
		echo '<p class="sp-help">' . esc_html__( 'Choose your catalog.sqlite file. First you get a report of what would happen. Nothing is written until you confirm. You can run the import more than once: songs already imported are skipped.', 'songpress' ) . '</p>';
		if ( ! class_exists( 'PDO' ) || ! in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'This server does not have the PHP SQLite driver (pdo_sqlite), so it cannot read the file. Ask your host to enable it, or run the import on a computer that has it.', 'songpress' ) . '</p></div></div>';
			return;
		}
		if ( ! empty( $_GET['nomedia'] ) ) {
			/* translators: %d: number of links */
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( '%d cover art or audio links did not match a Media Library item. They were kept as outside links and still work. Re-upload them to the Media Library and choose them on the song to move them in.', 'songpress' ), absint( $_GET['nomedia'] ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['done'] ) ) {
			printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html( sprintf( /* translators: 1: imported 2: skipped */ __( 'Imported %1$d songs, skipped %2$d already there. Run it again to continue if there are more.', 'songpress' ), absint( $_GET['done'] ), absint( $_GET['skipped'] ?? 0 ) ) ) );
		}
		if ( isset( $_GET['accounts'] ) ) {
			/* translators: 1: accounts created 2: writers still without one */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Created %1$d new writer accounts (Viewer class, no email yet). %2$d writers still need one: open SongPress > Link writers to finish.', 'songpress' ), absint( $_GET['accounts'] ), absint( $_GET['left'] ?? 0 ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['err'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['err'] ) ) ) . '</p></div>';
		}
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'songpress_import_check' );
		echo '<input type="hidden" name="action" value="songpress_import_check"><input type="file" name="catalog" accept=".sqlite,.db,.sqlite3" required> ';
		submit_button( __( 'Check the file (dry run)', 'songpress' ), 'primary', 'submit', false );
		echo '</form>';

		if ( is_array( $tmp ) && file_exists( $tmp['path'] ) ) {
			self::report( $tmp['report'] );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'songpress_import_run' );
			echo '<input type="hidden" name="action" value="songpress_import_run">';
			submit_button( __( 'Import now (up to 500 songs at a time)', 'songpress' ), 'primary', 'submit', false );
			echo '</form>';
		}
		if ( ! Songpress_People::ready() ) {
			echo '<h2 style="margin-top:24px">' . esc_html__( 'Starting from nothing?', 'songpress' ) . '</h2>';
			echo '<p class="sp-help">' . esc_html__( 'This catalog already has songs. Writer accounts are made after your first import. If you are not importing, start fresh and each writer you add will get an account.', 'songpress' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'songpress_start_fresh' );
			echo '<input type="hidden" name="action" value="songpress_start_fresh">';
			submit_button( __( 'Start fresh, without importing', 'songpress' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo wp_kses_post( songpress_donate_link() ) . '</div>';
	}

	private static function report( array $r ) {
		$li = function ( $label, $n, $note = '' ) {
			echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( (string) $n ) . ( $note ? ' <span class="sp-help">' . esc_html( $note ) . '</span>' : '' ) . '</li>';
		};
		echo '<h2>' . esc_html__( 'What the file contains', 'songpress' ) . '</h2><ul>';
		$li( __( 'Songs and versions', 'songpress' ), $r['rows'] );
		$li( __( 'Song families', 'songpress' ), $r['families'] );
		$li( __( 'Rows with no title', 'songpress' ), $r['no_title'] );
		$li( __( 'Songs with no writer listed', 'songpress' ), $r['songs_without_writers'] );
		$li( __( 'Songs whose writer shares do not add up to 100', 'songpress' ), $r['writer_share_off'] );
		echo '</ul>';
		$problems = array(
			__( 'Not a serial number (will be skipped)', 'songpress' ) => $r['bad_serial'],
			__( 'Serial used twice (only the first is kept)', 'songpress' ) => $r['duplicate_serial'],
			__( 'Skipped version numbers', 'songpress' )               => $r['version_gaps'],
			__( 'Family has no original work', 'songpress' )          => $r['family_without_v0'],
			__( 'Original works that carry release information (kept, and flagged in the editor)', 'songpress' ) => $r['original_with_release'],
		);
		foreach ( $problems as $label => $list ) {
			if ( $list ) {
				echo '<p><strong>' . esc_html( $label ) . ' (' . count( $list ) . '):</strong> ' . esc_html( implode( ', ', array_slice( $list, 0, 20 ) ) ) . ( count( $list ) > 20 ? ' ...' : '' ) . '</p>';
			}
		}
		if ( $r['unknown_status'] ) {
			$bits = array();
			foreach ( $r['unknown_status'] as $k => $n ) {
				$bits[] = ( '' === $k ? '(blank)' : $k ) . ' x' . $n;
			}
			echo '<p><strong>' . esc_html__( 'Unrecognized status (imported as Idea)', 'songpress' ) . ':</strong> ' . esc_html( implode( ', ', $bits ) ) . '</p>';
		}
		$top = array_slice( $r['writers'], 0, 60, true );
		$wl  = array();
		foreach ( $top as $wn => $wc ) {
			$wl[] = $wn . ' (' . $wc . ')';
		}
		echo '<details><summary>' . esc_html__( 'Writers found', 'songpress' ) . ' (' . count( $r['writers'] ) . ')</summary><p class="sp-help">' . esc_html__( 'Writers are not linked to accounts automatically. After the import, open SongPress > Link writers to confirm each link.', 'songpress' ) . '</p><p>' . esc_html( implode( ', ', $wl ) ) . '</p></details>';
		echo '<details><summary>' . esc_html__( 'Not imported in this version', 'songpress' ) . '</summary><ul>';
		foreach ( $r['skipped_columns'] as $col => $why ) {
			echo '<li><code>' . esc_html( $col ) . '</code> ' . esc_html( $why ) . '</li>';
		}
		echo '</ul></details>';
	}

	public static function handle_import_check() {
		check_admin_referer( 'songpress_import_check' );
		if ( ! Songpress_Caps::can( 'import' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		if ( empty( $_FILES['catalog']['tmp_name'] ) ) {
			self::back( 'songpress-import', array( 'err' => rawurlencode( __( 'Choose a file first.', 'songpress' ) ) ) );
		}
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			self::back( 'songpress-import', array( 'err' => rawurlencode( __( 'This server\'s PHP does not have SQLite support (pdo_sqlite), which the import needs. Ask your host to enable it.', 'songpress' ) ) ) );
		}
		// Not wp_handle_upload: WordPress refuses database file types, and its uploads folder is public.
		// The file is checked by its own header, then kept in a private folder.
		$f = $_FILES['catalog']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked below.
		if ( ! empty( $f['error'] ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
			self::back( 'songpress-import', array( 'err' => rawurlencode( __( 'The upload failed. The file may be larger than your server allows (see upload_max_filesize).', 'songpress' ) ) ) );
		}
		$head = (string) file_get_contents( $f['tmp_name'], false, null, 0, 16 );
		if ( 0 !== strpos( $head, "SQLite format 3\0" ) ) {
			self::back( 'songpress-import', array( 'err' => rawurlencode( __( 'That is not a SQLite database file. Choose the catalog.sqlite file from the old S&P Catalog.', 'songpress' ) ) ) );
		}
		$dir = Songpress_Import::private_dir();
		if ( ! $dir ) {
			self::back( 'songpress-import', array( 'err' => rawurlencode( __( 'SongPress could not create a private folder for the import file.', 'songpress' ) ) ) );
		}
		$dest = $dir . '/import-' . wp_generate_password( 16, false, false ) . '.sqlite';
		if ( ! move_uploaded_file( $f['tmp_name'], $dest ) ) {
			self::back( 'songpress-import', array( 'err' => rawurlencode( __( 'The file could not be saved.', 'songpress' ) ) ) );
		}
		$up = array( 'file' => $dest );
		try {
			$report = Songpress_Import_Analyzer::analyze( Songpress_Import_Analyzer::read_sqlite( $up['file'] ) );
		} catch ( Exception $e ) {
			@unlink( $up['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			self::back( 'songpress-import', array( 'err' => rawurlencode( __( 'That file is not an S&P Catalog database (no song_catalog table).', 'songpress' ) ) ) );
		}
		set_transient( 'songpress_import_' . get_current_user_id(), array( 'path' => $up['file'], 'report' => $report ), HOUR_IN_SECONDS );
		self::back( 'songpress-import' );
	}

	public static function handle_import_run() {
		check_admin_referer( 'songpress_import_run' );
		if ( ! Songpress_Caps::can( 'import' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		$tmp = get_transient( 'songpress_import_' . get_current_user_id() );
		if ( ! is_array( $tmp ) || ! file_exists( $tmp['path'] ) ) {
			self::back( 'songpress-import', array( 'err' => rawurlencode( __( 'The checked file has expired. Choose it again.', 'songpress' ) ) ) );
		}
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$out = Songpress_Import::run( $tmp['path'], 500 );
		$people = null;
		if ( $out['imported'] < 500 ) { // nothing left to import: remove the private copy
			@unlink( $tmp['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			delete_transient( 'songpress_import_' . get_current_user_id() );
			// The first import is done: from now on writers get accounts, and the imported writers get theirs now.
			update_option( 'songpress_accounts_ready', '1' );
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$people = Songpress_People::backfill_all( 20 );
		}
		self::back( 'songpress-import', $people ? array( 'accounts' => $people['created'], 'left' => $people['remaining'], 'done' => $out['imported'], 'skipped' => $out['skipped'], 'nomedia' => (int) ( $out['media_unmatched'] ?? 0 ) ) : array( 'done' => $out['imported'], 'skipped' => $out['skipped'], 'nomedia' => (int) ( $out['media_unmatched'] ?? 0 ) ) );
	}

	/* ---------- members ---------- */

	private static function find_user( $who ) {
		$who = trim( (string) $who );
		if ( '' === $who ) {
			return null;
		}
		$u = false !== strpos( $who, '@' ) ? get_user_by( 'email', $who ) : get_user_by( 'login', $who );
		return $u ?: null;
	}

	public static function page_members() {
		if ( ! Songpress_Caps::can( 'members' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ) );
		}
		echo '<div class="wrap songpress-wrap"><h1>' . esc_html__( 'Members', 'songpress' ) . '</h1>';
		echo '<p class="sp-help">' . esc_html__( 'SongPress has its own classes, separate from WordPress roles. A person only sees SongPress if you give them a class here. WordPress administrators are always Managers and cannot be changed.', 'songpress' ) . '</p>';
		foreach ( array( 'saved' => 'notice-success', 'err' => 'notice-error' ) as $k => $cls ) {
			if ( isset( $_GET[ $k ] ) ) {
				echo '<div class="notice ' . esc_attr( $cls ) . '"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) ) . '</p></div>';
			}
		}
		echo '<table class="widefat striped" style="max-width:880px"><thead><tr><th>' . esc_html__( 'Class', 'songpress' ) . '</th><th>' . esc_html__( 'What it can do', 'songpress' ) . '</th></tr></thead><tbody>';
		foreach ( Songpress_Caps::CLASSES as $c ) {
			echo '<tr><td><strong>' . esc_html( $c[0] ) . '</strong></td><td>' . esc_html( $c[1] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$ids = array_map( 'intval', get_users( array( 'meta_key' => Songpress_Caps::META, 'meta_compare' => 'EXISTS', 'fields' => 'ID' ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( get_users( array( 'capability' => 'manage_options', 'fields' => 'ID' ) ) as $admin_id ) {
			$ids[] = (int) $admin_id;
		}
		$ids = array_values( array_unique( $ids ) );
		echo '<h2>' . esc_html__( 'People', 'songpress' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'songpress_members' );
		echo '<input type="hidden" name="action" value="songpress_members"><input type="hidden" name="do" value="update">';
		echo '<table class="widefat striped" style="max-width:880px"><thead><tr><th>' . esc_html__( 'Person', 'songpress' ) . '</th><th>' . esc_html__( 'Class', 'songpress' ) . '</th></tr></thead><tbody>';
		foreach ( $ids as $uid ) {
			$u = get_userdata( $uid );
			if ( ! $u ) {
				continue;
			}
			$class = Songpress_Caps::class_of( $uid );
			echo '<tr><td>' . esc_html( $u->display_name ) . ' <span class="sp-help">' . esc_html( $u->user_login . ' · ' . $u->user_email ) . '</span></td><td>';
			if ( Songpress_Caps::is_wp_admin( $uid ) ) {
				echo '<strong>' . esc_html__( 'Manager', 'songpress' ) . '</strong> <span class="sp-help">' . esc_html__( '(WordPress administrator)', 'songpress' ) . '</span>';
			} else {
				if ( '' === (string) $u->user_email ) {
					echo '<input type="email" name="email[' . (int) $uid . ']" size="24" placeholder="' . esc_attr__( 'add email so they can sign in', 'songpress' ) . '"> ';
				}
				echo '<select name="class[' . (int) $uid . ']"><option value="">' . esc_html__( 'No access (remove)', 'songpress' ) . '</option>';
				foreach ( Songpress_Caps::assignable() as $k => $c ) {
					echo '<option value="' . esc_attr( $k ) . '"' . selected( $class, $k, false ) . '>' . esc_html( $c[0] ) . '</option>';
				}
				echo '</select>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'songpress' ), 'primary', 'submit', true );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Add someone who already has a login', 'songpress' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'songpress_members' );
		echo '<input type="hidden" name="action" value="songpress_members"><input type="hidden" name="do" value="add">';
		echo '<p><input name="who" size="30" placeholder="' . esc_attr__( 'username or email', 'songpress' ) . '" required> ' . self::class_select( 'class_new', 'viewer' ) . ' ';
		submit_button( __( 'Add', 'songpress' ), 'secondary', 'submit', false );
		echo '</p></form>';

		echo '<h2>' . esc_html__( 'Invite a new person', 'songpress' ) . '</h2>';
		echo '<p class="sp-help">' . esc_html__( 'Creates a login for them and emails a link to choose a password. They get no WordPress powers beyond reading, only the SongPress class you pick.', 'songpress' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'songpress_members' );
		echo '<input type="hidden" name="action" value="songpress_members"><input type="hidden" name="do" value="invite">';
		echo '<p><input name="name" size="22" placeholder="' . esc_attr__( 'Name', 'songpress' ) . '" required> <input type="email" name="email" size="28" placeholder="' . esc_attr__( 'Email', 'songpress' ) . '" required> ' . self::class_select( 'class_new', 'viewer' ) . ' ';
		submit_button( __( 'Send invitation', 'songpress' ), 'secondary', 'submit', false );
		echo '</p></form>' . wp_kses_post( songpress_donate_link() ) . '</div>';
	}

	private static function class_select( $name, $selected ) {
		$h = '<select name="' . esc_attr( $name ) . '">';
		foreach ( Songpress_Caps::assignable() as $k => $c ) {
			$h .= '<option value="' . esc_attr( $k ) . '"' . selected( $selected, $k, false ) . '>' . esc_html( $c[0] ) . '</option>';
		}
		return $h . '</select>';
	}

	public static function handle_members() {
		check_admin_referer( 'songpress_members' );
		if ( ! Songpress_Caps::can( 'members' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		$do    = sanitize_key( $_POST['do'] ?? '' );
		$valid = array_keys( Songpress_Caps::assignable() );
		if ( 'update' === $do ) {
			$n = 0;
			$classes = isset( $_POST['class'] ) && is_array( $_POST['class'] ) ? wp_unslash( $_POST['class'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value checked against the class list.
			foreach ( $classes as $uid => $class ) {
				$uid   = absint( $uid );
				$class = sanitize_key( $class );
				if ( ! $uid || Songpress_Caps::is_wp_admin( $uid ) || ( '' !== $class && ! in_array( $class, $valid, true ) ) ) {
					continue;
				}
				if ( Songpress_Caps::class_of( $uid ) !== $class ) {
					Songpress_Caps::assign( $uid, $class );
					++$n;
				}
			}
			$emails = isset( $_POST['email'] ) && is_array( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitize_email below.
			foreach ( $emails as $uid => $email ) {
				$uid   = absint( $uid );
				$email = sanitize_email( $email );
				if ( $uid && is_email( $email ) && ! email_exists( $email ) && '' === (string) get_userdata( $uid )->user_email ) {
					wp_update_user( array( 'ID' => $uid, 'user_email' => $email ) );
					++$n;
				}
			}
			self::back( 'songpress-members', array( 'saved' => rawurlencode( sprintf( /* translators: %d: people changed */ _n( '%d person changed.', '%d people changed.', $n, 'songpress' ), $n ) ) ) );
		}
		$class = sanitize_key( $_POST['class_new'] ?? '' );
		if ( ! in_array( $class, $valid, true ) ) {
			self::back( 'songpress-members', array( 'err' => rawurlencode( __( 'Choose a class.', 'songpress' ) ) ) );
		}
		if ( 'add' === $do ) {
			$u = self::find_user( sanitize_text_field( wp_unslash( $_POST['who'] ?? '' ) ) );
			if ( ! $u ) {
				self::back( 'songpress-members', array( 'err' => rawurlencode( __( 'No login with that username or email. Use Invite a new person instead.', 'songpress' ) ) ) );
			}
			if ( Songpress_Caps::is_wp_admin( $u->ID ) ) {
				self::back( 'songpress-members', array( 'err' => rawurlencode( __( 'That person is a WordPress administrator, so they are already a Manager.', 'songpress' ) ) ) );
			}
			Songpress_Caps::assign( $u->ID, $class );
			self::back( 'songpress-members', array( 'saved' => rawurlencode( sprintf( /* translators: %s: name */ __( '%s added.', 'songpress' ), $u->display_name ) ) ) );
		}
		if ( 'invite' === $do ) {
			$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			$name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
			if ( ! is_email( $email ) || '' === $name ) {
				self::back( 'songpress-members', array( 'err' => rawurlencode( __( 'Enter a name and a valid email.', 'songpress' ) ) ) );
			}
			$existing = get_user_by( 'email', $email );
			if ( $existing ) {
				self::back( 'songpress-members', array( 'err' => rawurlencode( __( 'That email already has a login. Use "Add someone who already has a login".', 'songpress' ) ) ) );
			}
			$login = sanitize_user( strstr( $email, '@', true ), true );
			$base  = '' !== $login ? $login : 'member';
			for ( $i = 2; username_exists( $login ); $i++ ) {
				$login = $base . $i;
			}
			$uid = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'display_name' => $name, 'user_pass' => wp_generate_password( 24 ), 'role' => 'subscriber' ) );
			if ( is_wp_error( $uid ) ) {
				self::back( 'songpress-members', array( 'err' => rawurlencode( $uid->get_error_message() ) ) );
			}
			Songpress_Caps::assign( $uid, $class );
			wp_new_user_notification( $uid, null, 'user' );
			self::back( 'songpress-members', array( 'saved' => rawurlencode( sprintf( /* translators: %s: email */ __( 'Invitation sent to %s.', 'songpress' ), $email ) ) ) );
		}
		self::back( 'songpress-members' );
	}

	/* ---------- link writers ---------- */

	/** Writer names that are not linked to an account yet, with how many credits each has. */
	private static function unlinked_writers( $limit = 200 ) {
		global $wpdb;
		$c = Songpress_Install::table( 'credits' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT name, COUNT(*) AS n FROM {$c} WHERE role = 'writer' AND user_id IS NULL AND name <> '' GROUP BY name ORDER BY n DESC, name LIMIT %d", $limit ) );
	}

	public static function page_link_writers() {
		if ( ! Songpress_Caps::can( 'link' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ) );
		}
		echo '<div class="wrap songpress-wrap"><h1>' . esc_html__( 'Link writers to accounts', 'songpress' ) . '</h1>';
		echo '<p class="sp-help">' . esc_html__( 'A linked writer can see the songs they are credited on, and nothing else. Nothing is linked until you tick the box on its row. Type the account yourself: nothing is matched by name.', 'songpress' ) . '</p>';
		if ( isset( $_GET['linked'] ) ) {
			/* translators: %d: number of credits linked */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Linked %d credits.', 'songpress' ), absint( $_GET['linked'] ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['missed'] ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( /* translators: %s: names */ __( 'No account was found for: %s', 'songpress' ), sanitize_text_field( wp_unslash( $_GET['missed'] ) ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['made'] ) ) {
			/* translators: 1: accounts created, 2: credits linked, 3: writers still without an account */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Created %1$d accounts and linked %2$d credits. %3$d writers still have no account.', 'songpress' ), absint( $_GET['made'] ), absint( $_GET['credits'] ?? 0 ), absint( $_GET['left'] ?? 0 ) ) ) . '</p></div>';
		}
		$todo = ( Songpress_Caps::can( 'members' ) && Songpress_People::ready() ) ? count( Songpress_People::unlinked_names() ) : 0;
		echo '<h2>' . esc_html__( 'Give every writer an account', 'songpress' ) . '</h2>';
		echo '<p class="sp-help">' . esc_html__( 'Each writer gets a login named firstname.lastname with the Viewer class. These accounts have no email and a random password, so nobody can sign in until you add their email (SongPress > Members) and they use "Lost your password?". The same name always gets the same account. New writers added to a song get one automatically.', 'songpress' ) . '</p>';
		if ( $todo ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'songpress_make_accounts' );
			echo '<input type="hidden" name="action" value="songpress_make_accounts">';
			/* translators: %d: number of writers */
			submit_button( sprintf( _n( 'Create an account for %d writer (up to 100 at a time)', 'Create accounts for %d writers (up to 100 at a time)', $todo, 'songpress' ), $todo ), 'primary', 'submit', false );
			echo '</form>';
		} elseif ( ! Songpress_People::ready() ) {
			echo '<p>' . esc_html__( 'Accounts are made after your first import. Import your old catalog, or choose "Start fresh" on the Import screen.', 'songpress' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Every writer already has an account.', 'songpress' ) . '</p>';
		}
		echo '<h2 style="margin-top:24px">' . esc_html__( 'Link a writer to an account that already exists', 'songpress' ) . '</h2>';
		$rows = self::unlinked_writers();
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Every writer is linked, or there are no writers yet.', 'songpress' ) . '</p></div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'songpress_link_writers' );
		echo '<input type="hidden" name="action" value="songpress_link_writers">';
		echo '<table class="widefat striped"><thead><tr><th style="width:60px">' . esc_html__( 'Link', 'songpress' ) . '</th><th>' . esc_html__( 'Writer', 'songpress' ) . '</th><th>' . esc_html__( 'Credits', 'songpress' ) . '</th><th>' . esc_html__( 'Account (username or email)', 'songpress' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $i => $r ) {
			$s = null; // existing accounts are never guessed from a name
			echo '<tr><td><input type="checkbox" name="link[' . (int) $i . '][on]" value="1" aria-label="' . esc_attr__( 'Link this writer', 'songpress' ) . '"></td>';
			echo '<td>' . esc_html( $r->name ) . '<input type="hidden" name="link[' . (int) $i . '][name]" value="' . esc_attr( $r->name ) . '"></td><td>' . (int) $r->n . '</td>';
			echo '<td><input name="link[' . (int) $i . '][account]" size="26" value="' . esc_attr( $s ? $s->user_login : '' ) . '" placeholder="' . esc_attr__( 'username or email', 'songpress' ) . '">' . ( $s ? ' <span class="sp-help">' . esc_html__( 'suggested', 'songpress' ) . '</span>' : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Link the ticked writers', 'songpress' ) );
		echo '</form>' . wp_kses_post( songpress_donate_link() ) . '</div>';
	}

	public static function handle_make_accounts() {
		check_admin_referer( 'songpress_make_accounts' );
		if ( ! Songpress_Caps::can( 'members' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$r = Songpress_People::backfill( 100 );
		self::back( 'songpress-link', array( 'made' => $r['created'], 'credits' => $r['linked'], 'left' => $r['remaining'] ) );
	}

	public static function handle_link_writers() {
		check_admin_referer( 'songpress_link_writers' );
		if ( ! Songpress_Caps::can( 'link' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		global $wpdb;
		$c      = Songpress_Install::table( 'credits' );
		$linked = 0;
		$missed = array();
		$posted = isset( $_POST['link'] ) && is_array( $_POST['link'] ) ? wp_unslash( $_POST['link'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized below.
		foreach ( $posted as $row ) {
			if ( empty( $row['on'] ) ) {
				continue;
			}
			$name = sanitize_text_field( $row['name'] ?? '' );
			$acc  = trim( sanitize_text_field( $row['account'] ?? '' ) );
			$u    = '' === $acc ? false : ( false !== strpos( $acc, '@' ) ? get_user_by( 'email', $acc ) : get_user_by( 'login', $acc ) );
			if ( ! $u || '' === $name ) {
				$missed[] = '' !== $acc ? $acc : $name;
				continue;
			}
			$linked += (int) $wpdb->query( $wpdb->prepare( "UPDATE {$c} SET user_id = %d WHERE role = 'writer' AND user_id IS NULL AND name = %s", $u->ID, $name ) );
		}
		$args = array( 'linked' => $linked );
		if ( $missed ) {
			$args['missed'] = rawurlencode( implode( ', ', $missed ) );
		}
		self::back( 'songpress-link', $args );
	}

	/* ---------- first-use donate prompt ---------- */

	/** One polite question, once, on SongPress screens. The answer is saved and it never shows again. */
	public static function donate_prompt() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'songpress' ) || ! Songpress_Caps::can( 'settings' ) || 'pending' !== get_option( 'songpress_donate_prompt' ) ) {
			return;
		}
		$action = esc_url( admin_url( 'admin-post.php' ) );
		echo '<div id="sp-donate-modal" role="dialog" aria-modal="true" aria-labelledby="sp-donate-title" style="position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100000;display:flex;align-items:center;justify-content:center"><div style="background:#fff;max-width:440px;margin:16px;padding:24px;border-radius:10px;box-shadow:0 10px 40px rgba(0,0,0,.4)">';
		echo '<h2 id="sp-donate-title" style="margin-top:0">' . esc_html__( 'Help keep SongPress free', 'songpress' ) . '</h2>';
		echo '<p>' . esc_html__( 'SongPress is a free plugin. If it is useful to you, you can support it by showing a small "Donate: buy me a cup of coffee" link at the bottom of your public SongPress pages. It is your choice, and you can change it any time in SongPress > Settings.', 'songpress' ) . '</p>';
		echo '<form method="post" action="' . $action . '" style="display:flex;gap:8px;flex-wrap:wrap">'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		wp_nonce_field( 'songpress_donate_answer' );
		echo '<input type="hidden" name="action" value="songpress_donate_answer"><button class="button button-primary" name="answer" value="yes">' . esc_html__( 'Yes, show the link', 'songpress' ) . '</button><button class="button" name="answer" value="no">' . esc_html__( 'No thanks', 'songpress' ) . '</button></form></div></div>';
	}

	public static function handle_donate_answer() {
		check_admin_referer( 'songpress_donate_answer' );
		if ( ! Songpress_Caps::can( 'settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		update_option( 'songpress_public_donate', ( isset( $_POST['answer'] ) && 'yes' === $_POST['answer'] ) ? '1' : '0' );
		update_option( 'songpress_donate_prompt', 'done' );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : self::url( 'songpress' ) );
		exit;
	}

	/* ---------- settings ---------- */

	public static function page_settings() {
		if ( ! Songpress_Caps::can( 'settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ) );
		}
		echo '<div class="wrap songpress-wrap"><h1>' . esc_html__( 'SongPress settings', 'songpress' ) . '</h1>';
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'songpress' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'songpress_settings' );
		echo '<input type="hidden" name="action" value="songpress_settings"><p><label><input type="checkbox" name="delete_on_uninstall" value="1"' . checked( get_option( 'songpress_delete_on_uninstall' ), '1', false ) . '> ' . esc_html__( 'Delete all SongPress data when the plugin is deleted', 'songpress' ) . '</label></p>';
		echo '<p class="sp-help">' . esc_html__( 'Off by default. Leave it off to keep your catalog if you remove the plugin by accident.', 'songpress' ) . '</p>';
		echo '<p><label><input type="checkbox" name="public_donate" value="1"' . checked( get_option( 'songpress_public_donate' ), '1', false ) . '> ' . esc_html__( 'Show a small "Donate: buy me a cup of coffee" link at the bottom of public SongPress pages', 'songpress' ) . '</label></p>';
		echo '<p class="sp-help">' . esc_html__( 'SongPress is free. This link helps support it. It is off unless you turn it on, and it never appears on your public pages without your say.', 'songpress' ) . '</p>';
		echo '<h2>' . esc_html__( 'Updates', 'songpress' ) . '</h2>';
		echo '<p><label><input type="checkbox" name="github_updates" value="1"' . checked( Songpress_Updater::enabled(), true, false ) . '> ' . esc_html__( 'Get SongPress updates from GitHub', 'songpress' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'GitHub repository', 'songpress' ) . ' <input name="github_repo" size="28" value="' . esc_attr( Songpress_Updater::repo() ) . '" placeholder="owner/repository"></label></p>';
		echo '<p class="sp-help">' . esc_html__( 'WordPress asks GitHub twice a day whether there is a newer release. Nothing about your site is sent. New versions show up under Dashboard > Updates.', 'songpress' ) . '</p>';
		submit_button();
		echo '</form>';

		echo '<h2>' . esc_html__( 'Front-end pages', 'songpress' ) . '</h2><table class="widefat striped" style="max-width:640px"><tbody>';
		foreach ( Songpress_Install::pages() as $opt => $def ) {
			$pid = (int) get_option( $opt );
			echo '<tr><td>' . esc_html( $def['title'] ) . ' <code>' . esc_html( $def['code'] ) . '</code></td><td>';
			echo Songpress_Install::page_ok( $pid ) ? '<a href="' . esc_url( get_permalink( $pid ) ) . '">' . esc_html__( 'View', 'songpress' ) . '</a> · <a href="' . esc_url( get_edit_post_link( $pid ) ) . '">' . esc_html__( 'Edit', 'songpress' ) . '</a>' : esc_html__( 'Missing', 'songpress' );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px">';
		wp_nonce_field( 'songpress_pages' );
		echo '<input type="hidden" name="action" value="songpress_pages">';
		submit_button( __( 'Create any missing pages', 'songpress' ), 'secondary', 'submit', false );
		echo '</form>' . wp_kses_post( songpress_donate_link() ) . '</div>';
	}

	public static function handle_pages() {
		check_admin_referer( 'songpress_pages' );
		if ( ! Songpress_Caps::can( 'settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		Songpress_Install::create_pages();
		self::back( 'songpress-settings', array( 'saved' => 1 ) );
	}

	public static function handle_settings() {
		check_admin_referer( 'songpress_settings' );
		if ( ! Songpress_Caps::can( 'settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		update_option( 'songpress_delete_on_uninstall', empty( $_POST['delete_on_uninstall'] ) ? '0' : '1' );
		update_option( 'songpress_public_donate', empty( $_POST['public_donate'] ) ? '0' : '1' );
		update_option( 'songpress_github_updates', empty( $_POST['github_updates'] ) ? '0' : '1' );
		$repo = sanitize_text_field( wp_unslash( $_POST['github_repo'] ?? '' ) );
		if ( Songpress_Updater::valid_repo( $repo ) ) {
			update_option( 'songpress_github_repo', $repo );
		}
		Songpress_Updater::forget();
		self::back( 'songpress-settings', array( 'saved' => 1 ) );
	}
}
