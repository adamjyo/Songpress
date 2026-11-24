<?php
defined( 'ABSPATH' ) || exit;

/**
 * The song editor, inside the SongPress front-end app (no WordPress admin screens).
 * Phones get the short form: title, status, cover art, audio, lyrics, writers.
 * Wide screens get every section as a tab. Saving reuses the same handlers as the admin form
 * (admin-post.php), which send you back here when the form carries sp_return.
 */
class Songpress_Editor {

	/** Address of the list, without any editor or message parameters. */
	/** Where a button's action should send you back to. While a form is being handled the request is the site's own door (the home page), so the address of the app is taken from the form (sp_return), the page it was sent from, or the app page itself, never from the door. */
	private static function return_base() {
		$post = isset( $_REQUEST['songpress_post'] ) || ( isset( $_REQUEST['action'] ) && 0 === strpos( sanitize_key( wp_unslash( $_REQUEST['action'] ) ), 'songpress_' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $post ) {
			return false;
		}
		$ret = isset( $_REQUEST['sp_return'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_REQUEST['sp_return'] ) ), '' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $ret ) {
			$ref = wp_get_referer();
			$ret = $ref ? wp_validate_redirect( $ref, '' ) : '';
		}
		if ( '' === $ret && class_exists( 'Songpress_Frontend' ) ) {
			$id  = Songpress_Frontend::main_id( 'catalog' );
			$ret = $id ? (string) get_permalink( $id ) : '';
		}
		return '' !== $ret ? $ret : home_url( '/' );
	}

	public static function list_url() {
		return remove_query_arg( array( 'songpress_post', 'action', '_wpnonce', 'sp_edit', 'sp_saved', 'sp_err', 'sp_noacct', 'sp_shares', 'sp_dropped', 'sp_archived', 'sp_tab', 'sp_song', 'sp_view', 'sp_page', 'sp_members', 'saved', 'err', 'done', 'skipped', 'accounts', 'left', 'nomedia', 'profile', 'kind', 'loaded', 'b', 'p', 'loaderr', 'artist', 'checked', 'person', 'type', 'm_ok', 'm_err' ), self::return_base() );
	}

	public static function edit_url( $id ) {
		return add_query_arg( 'sp_edit', $id ? (int) $id : 'new', self::list_url() );
	}

	private static function f( $song, $name ) {
		return $song ? (string) $song->$name : '';
	}

	private static function input( $name, $label, $song, $extra = '', $class = '' ) {
		// Date fields get the browser's calendar picker. A value that is not a plain YYYY-MM-DD (old free text) stays a text box, so nothing is blanked.
		if ( false !== strpos( $extra, 'placeholder="YYYY-MM-DD"' ) ) {
			$v = (string) self::f( $song, $name );
			if ( '' === $v || preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
				return '<label class="spe-field ' . esc_attr( $class ) . '"><span>' . esc_html( $label ) . '</span><input type="date" name="' . esc_attr( $name ) . '" value="' . esc_attr( $v ) . '"></label>';
			}
		}
		return '<label class="spe-field ' . esc_attr( $class ) . '"><span>' . esc_html( $label ) . '</span><input name="' . esc_attr( $name ) . '" value="' . esc_attr( self::f( $song, $name ) ) . '" ' . $extra . '></label>';
	}

	/** Small line icons for the editor's top bar. */
	private static function ico( $name ) {
		$d = array(
			'back'   => '<path d="M15 18l-6-6 6-6"/>',
			'save'   => '<path d="M5 12l5 5L20 7"/>',
			'share'  => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 10.5l6.8-4M8.6 13.5l6.8 4"/>',
			'trash'  => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
		);
		return '<svg class="spe-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( $d[ $name ] ?? '' ) . '</svg>';
	}

	public static function render( $id ) {
		$id   = (int) $id;
		$song = $id ? Songpress_Songs::get( $id ) : null;
		if ( $id && ! $song ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'That song was not found.', 'songpress' ) . '</p></div>';
		}
		if ( $id ? ! Songpress_Caps::can_edit_song( $id ) : ! Songpress_Caps::can( 'create' ) ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'You do not have permission to edit this song.', 'songpress' ) . '</p></div>';
		}
		$version  = $song ? (int) $song->version_no : 0;
		$here     = self::edit_url( $id );
		$title    = $song ? Songpress_Songs::display_title( $song ) : '';
		$o        = '<div class="songpress sp-app sp-editor" id="spe">';
		$del = '';
		if ( $song && Songpress_Caps::can( 'delete' ) ) {
			$many = count( Songpress_Songs::delete_ids( $id ) );
			$ask  = 0 === (int) $song->version_no && $many > 1
				? sprintf( /* translators: %d: number of songs */ __( 'Delete this work and its %d versions? This cannot be undone.', 'songpress' ), $many - 1 )
				: __( 'Delete this song? This cannot be undone.', 'songpress' );
			$lab  = 0 === (int) $song->version_no ? __( 'Delete this work', 'songpress' ) : __( 'Delete this version', 'songpress' );
			$del  = '<form method="post" class="spe-topdel" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="' . esc_attr( "var b=this.querySelector('button');if(b.dataset.armed==='1')return true;b.dataset.armed='1';b.classList.add('armed');b.querySelector('.spe-delt').hidden=false;setTimeout(function(){b.dataset.armed='';b.classList.remove('armed');b.querySelector('.spe-delt').hidden=true},4000);return false" ) . '">' . wp_nonce_field( 'songpress_delete', '_wpnonce', true, false )
				. '<input type="hidden" name="action" value="songpress_delete"><input type="hidden" name="song_id" value="' . (int) $id . '"><input type="hidden" name="sp_return" value="' . esc_url( self::list_url() ) . '">'
				. '<button type="submit" class="spe-ib danger" title="' . esc_attr( $lab ) . '" aria-label="' . esc_attr( $lab ) . '">' . self::ico( 'trash' ) . '<span class="spe-delt" hidden>' . esc_html( $many > 1 && 0 === (int) $song->version_no ? sprintf( /* translators: %d: number of versions */ __( 'Click again to delete this work and its %d versions', 'songpress' ), $many - 1 ) : __( 'Click again to delete', 'songpress' ) ) . '</span></button></form>';
		}
		$o       .= '<div class="spe-top"><a class="spe-back spe-ib" href="' . esc_url( self::list_url() ) . '" title="' . esc_attr__( 'Back to Works', 'songpress' ) . '">' . self::ico( 'back' ) . '<span>' . esc_html__( 'Works', 'songpress' ) . '</span></a>' . Songpress_Steps::nav( $id, self::list_url() ) . '<h2>' . ( $song ? '<span class="songpress-serial">' . esc_html( $song->serial ) . '</span> ' . esc_html( $title ) : esc_html__( 'Add a song', 'songpress' ) ) . '</h2><span class="sp-total" aria-live="polite"></span>' . ( $song ? self::share_popup( $song ) : '' ) . $del
			. '<span id="spe-updated" class="spe-updated" role="status" aria-live="polite" hidden></span></div>';
		$o       .= self::notices();
		if ( Songpress_Songs::ARCHIVING && $song && $song->archived_at ) {
			$o .= '<p class="spe-note warn">' . esc_html__( 'This song is archived. It is hidden from the catalog and from public pages, and its serial number stays reserved. Restore it from the Versions and sharing section.', 'songpress' ) . '</p>';
		}

		$on = isset( $_GET['sp_tab'] ) ? sanitize_key( wp_unslash( $_GET['sp_tab'] ) ) : 'basics'; // phpcs:ignore WordPress.Security.NonceVerification
		$o .= '<div class="spe-layout"><div class="spe-panels" data-on="' . esc_attr( $on ) . '">';

		$o .= '<form method="post" class="spe-form" id="spe-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_save', '_wpnonce', true, false );
		$o .= '<input type="hidden" name="action" value="songpress_save"><input type="hidden" name="song_id" value="' . $id . '"><input type="hidden" name="revision" value="' . (int) ( $song->revision ?? 0 ) . '"><input type="hidden" name="sp_return" value="' . esc_url( remove_query_arg( array( 'sp_saved', 'sp_err', 'sp_dropped', 'sp_noacct', 'sp_shares', 'sp_deleted' ) ) ) . '">'; // saving stays on this work, with the list search still riding along

		if ( function_exists( 'wp_enqueue_media' ) && Songpress_Caps::can( 'upload' ) ) {
			wp_enqueue_media(); // the audio box opens the WordPress Media Library
		}

		/* Basics */
		$cur_artist  = $song ? (int) $song->artist_id : ( isset( $_GET['sp_artist'] ) ? absint( $_GET['sp_artist'] ) : Songpress_Artists::default_id() );
		$all_artists = Songpress_Artists::all();
		$artist_hidden = '<input type="hidden" name="artist_id" value="' . (int) $cur_artist . '">';
		$artist_html   = $artist_hidden;
		// The original work holds only what every version shares. Everything else is entered on a version.
		$root = 0 === $version && ! ( $song && '' !== self::f( $song, 'artist' ) . self::f( $song, 'album' ) . self::f( $song, 'release_title' ) . self::f( $song, 'release_date' ) );
		// Cover art and an audio player sit beside Basics on every work, the original included.
		$media  = '<section class="spe-panel simple' . ( $root ? '' : ' spe-own' ) . '" data-panel="media"><h3>' . esc_html__( 'Art and audio', 'songpress' ) . '</h3><p class="spe-drop" id="spe-msg" aria-live="polite"></p><div class="spe-grid">';
		$media .= self::media_box( 'art', __( 'Cover art', 'songpress' ), 'image/*', (int) self::f( $song, 'art_id' ), self::f( $song, 'art_url' ), ( $song && Songpress_Caps::can( 'upload' ) ) ? self::art_url( (int) $song->id ) : '' );
		$media .= '<div class="spe-audcol">' . self::stream_widget() . self::media_box( 'audio', __( 'Audio', 'songpress' ), 'audio/*', (int) self::f( $song, 'audio_id' ), self::f( $song, 'audio_url' ) ) . '</div>';
		$media .= '</div></section>';
		$o .= '<div class="spe-hero">' . $media;
		$o .= '<section class="spe-panel simple" data-panel="basics"><h3 class="spe-h3row">' . esc_html__( 'Basics', 'songpress' ) . ( $root ? '' : '<span class="spe-legend"><i></i>' . esc_html__( 'Tinted fields belong to this version only. Everything else is shared with every version of the song.', 'songpress' ) . '</span>' ) . '</h3><div class="spe-grid">';
		if ( $root || ! $song ) {
			$o .= '<label class="spe-field wide"><span>' . esc_html__( 'Title', 'songpress' ) . '</span><input name="title" value="' . esc_attr( self::f( $song, 'title' ) ) . '" required' . Songpress_Names::attrs( 'song', $song ? (int) $song->family_id : 0 ) . '></label>' . Songpress_Names::script();
		} else {
			// A version is named by its release title. The work's title is the original's, the same on every version.
			$o .= '<input type="hidden" name="title" value="' . esc_attr( self::f( $song, 'title' ) ) . '">';
			$o .= '<label class="spe-field wide spe-own"><span>' . esc_html__( 'Release title', 'songpress' ) . '</span><input name="release_title" value="' . esc_attr( self::f( $song, 'release_title' ) ) . '" placeholder="' . esc_attr( self::f( $song, 'title' ) ) . '"><small class="spe-help">' . esc_html( sprintf( /* translators: %s: the work title */ __( 'The name of this release, which is often different from the work title. The work title, "%s", is set on the original work.', 'songpress' ), self::f( $song, 'title' ) ) ) . '</small></label>';
		}
		$o .= ( $root ? $artist_hidden : '' );
			$cur_stage = $song ? Songpress_Songs::effective_status( $song ) : 'Idea';
			$so        = '<option value=""></option>';
			foreach ( Songpress_Songs::status_choices( $version, array( $cur_stage ) ) as $st ) {
				$so .= '<option value="' . esc_attr( $st ) . '"' . selected( strtolower( $cur_stage ), strtolower( $st ), false ) . '>' . esc_html( $st ) . '</option>';
			}
			$o .= '<label class="spe-field' . ( $root ? '' : ' spe-own' ) . '"><span>' . esc_html__( 'Status', 'songpress' ) . '</span><select name="stage" id="spe-stage">' . $so . '</select></label>';
		$o .= '<script>(function(){var s=document.getElementById("spe-stage"),t=document.querySelector("input[name=title]");if(!s||!t)return;function f(){t.required=s.value.toLowerCase()!=="starter"}s.addEventListener("change",f);f()})()</script>';
		$o .= self::input( 'writing_date', __( 'Written on', 'songpress' ), $song, 'placeholder="YYYY-MM-DD"', 'adv' );
		$o .= self::input( 'copyright_date', __( 'Copyright date', 'songpress' ), $song, 'placeholder="YYYY-MM-DD"', 'adv' );
		$o .= '<label class="spe-field wide adv"><span>' . esc_html__( 'Other titles', 'songpress' ) . '</span><textarea name="alt_titles" rows="2">' . esc_textarea( self::f( $song, 'alt_titles' ) ) . '</textarea></label>';
		$o .= '</div>';
		if ( ! $song ) {
			$o .= '<p class="spe-help">' . esc_html__( 'The serial number is assigned for you. The original work is just four digits, like 0012. Each version after it adds two more, like 0012-01.', 'songpress' ) . '</p>';
		}
		$o .= '</section></div>';

		/* People */
		$by_role = array( 'writer' => array(), 'publisher' => array(), 'label' => array(), 'performer' => array(), 'producer' => array() );
		foreach ( $song ? Songpress_Songs::credits( $id ) : array() as $c ) {
			if ( isset( $by_role[ $c->role ] ) ) {
				$by_role[ $c->role ][] = $c;
			}
		}
		if ( ! $song ) {
			foreach ( Songpress_Artists::starting_credits( $cur_artist ) as $role => $rows ) {
				$by_role[ $role ] = $rows;
			}
		}
		$o .= '<section class="spe-panel simple" data-panel="people"><h3>' . esc_html__( 'People and splits', 'songpress' ) . '</h3>';
		$o .= self::credit_group( 'writer', __( 'Writers', 'songpress' ), __( 'Add writer', 'songpress' ), $by_role['writer'], 4, true, true, true );
		if ( ! $root ) {
			$o .= '<div class="spe-own">' . self::credit_group( 'performer', __( 'Performers', 'songpress' ), __( 'Add performer', 'songpress' ), $by_role['performer'], 1, false, true, false );
			$o .= self::credit_group( 'producer', __( 'Producers', 'songpress' ), __( 'Add producer', 'songpress' ), $by_role['producer'], 1, false, true, false ) . '</div>';
		}
		$o .= '</section>';

		/* Publishing: the numbers, then who publishes and who releases it. Shared by every version except the ISRC. */
		$o .= '<section class="spe-panel simple" data-panel="publishing"><h3>' . esc_html__( 'Publishing', 'songpress' ) . '</h3>';
		$o .= self::code_fields( $song ? Songpress_Songs::identifiers( $id ) : array(), $root );
		$o .= self::credit_group( 'publisher', __( 'Publishers', 'songpress' ), __( 'Add publisher', 'songpress' ), $by_role['publisher'], 1, false, true, true );
		$o .= '</section>';

		/* Release: everything that belongs to one recording or release, so only versions have it. */
		$legacy = $song && 0 === $version && ( '' !== self::f( $song, 'artist' ) . self::f( $song, 'album' ) . self::f( $song, 'release_title' ) . self::f( $song, 'release_date' ) );
		$rel    = '<section class="spe-panel simple' . ( $root ? '' : ' spe-own' ) . '" data-panel="release"><h3 class="spe-relh">' . esc_html__( 'Release', 'songpress' ) . ( ( $song && Songpress_Caps::can( 'create' ) ) ? ' <button type="submit" form="spe-nv-form" class="spe-nvbtn" title="' . esc_attr__( 'Create a new version of this work', 'songpress' ) . '">+ ' . esc_html__( 'Create a version', 'songpress' ) . '</button>' : '' ) . '</h3>';
		if ( $song && ( $version > 0 || $legacy ) ) {
			$rel .= '<p class="spe-help">' . esc_html__( 'These belong to this version only: a new version gets its own release name, band, key, tempo and genre. The ISRC is here; the DistroKid and UPC numbers go in Publishing.', 'songpress' ) . '</p>';
			if ( $legacy ) {
				$rel .= '<p class="spe-note warn">' . esc_html__( 'This is the original work, which should not have release information. It came from the old catalog. Add a version and enter it there.', 'songpress' ) . '</p>';
			}
			$rel .= '<div class="spe-grid">' . $artist_html . self::input( 'release_date', __( 'Release date', 'songpress' ), $song, 'placeholder="YYYY-MM-DD"' ) . self::input( 'album', __( 'Album', 'songpress' ), $song ) . self::artist_select( $song );
			$rel .= self::input( 'genre', __( 'Genre', 'songpress' ), $song, 'list="spe-genres"' );
			$rel .= '<datalist id="spe-genres">';
			foreach ( Songpress_Songs::genres() as $gn ) {
				$rel .= '<option value="' . esc_attr( $gn ) . '">';
			}
			$rel .= '</datalist>';
			$rel .= self::input( 'musical_key', __( 'Key', 'songpress' ), $song ) . self::input( 'tempo', __( 'Tempo (BPM)', 'songpress' ), $song ) . self::input( 'time_signature', __( 'Time signature', 'songpress' ), $song ) . self::input( 'format', __( 'Format', 'songpress' ), $song ) . self::input( 'production_date', __( 'Produced on', 'songpress' ), $song, 'placeholder="YYYY-MM-DD"' ) . self::isrc_fields( $song ? Songpress_Songs::identifiers( $id ) : array() ) . '</div>';
		} else {
			$rel .= self::released_list( $song );
		}
		$rel .= '</section>';

		$o .= $rel;

		/* Lyrics and chords */
		$o .= '<section class="spe-panel simple" data-panel="chart"><h3>' . esc_html__( 'Lyrics and chords', 'songpress' ) . '</h3>';
		$lv  = $song ? Songpress_Songs::lyric_levels( $song ) : array();
		$hint = function ( $level ) use ( $lv ) {
			if ( empty( $lv[ $level ] ) || 'own' === $lv[ $level ][1] || '' === $lv[ $level ][1] ) {
				return '';
			}
			$names = array( 'chordpro' => 'ChordPro', 'chords_lyrics' => __( 'Chords and lyrics', 'songpress' ) );
			return '<small class="spe-help">' . esc_html( sprintf( /* translators: %s: the level used */ __( 'Blank: using %s, stepped down. What you see greyed is what is used.', 'songpress' ), $names[ $lv[ $level ][1] ] ) ) . '</small>';
		};
		$ph = function ( $level ) use ( $lv ) {
			return ! empty( $lv[ $level ] ) && 'own' !== $lv[ $level ][1] && '' !== $lv[ $level ][0] ? ' placeholder="' . esc_attr( $lv[ $level ][0] ) . '"' : '';
		};
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Lyrics', 'songpress' ) . '</span><textarea class="spe-chart" name="lyrics" rows="14"' . $ph( 'lyrics' ) . '>' . esc_textarea( self::f( $song, 'lyrics' ) ) . '</textarea>' . $hint( 'lyrics' ) . '</label>';
		$o .= '<label class="spe-field adv"><span>' . esc_html__( 'Chords and lyrics', 'songpress' ) . '</span><textarea class="spe-chart" name="chords_lyrics" rows="14"' . $ph( 'chords_lyrics' ) . '>' . esc_textarea( self::f( $song, 'chords_lyrics' ) ) . '</textarea>' . $hint( 'chords_lyrics' ) . '</label>';
		$o .= '<label class="spe-field adv"><span class="spe-lbrow">ChordPro <button type="button" class="spe-toolico" id="spe-cp-open" title="' . esc_attr__( 'Open the ChordPro tool', 'songpress' ) . '" aria-label="' . esc_attr__( 'Open the ChordPro tool', 'songpress' ) . '"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></button> <button type="button" class="spe-toolico" id="spe-tr-open" title="' . esc_attr__( 'Open the Transcriber: listen to a recording and write the chords', 'songpress' ) . '" aria-label="' . esc_attr__( 'Open the Transcriber', 'songpress' ) . '"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12h2M7 8v8M11 4v16M15 8v8M19 10v4M21 12h0"/></svg></button></span><textarea class="spe-chart" name="chordpro" id="spe-chordpro" rows="14" data-tool="' . esc_url( Songpress_ChordTool::url() . '&embed=1' . ( $song ? '&song=' . (int) $song->id : '' ) ) . '" data-tr="' . esc_url( Songpress_Transcriber::url( array( 'embed' => 1 ) ) ) . '">' . esc_textarea( self::f( $song, 'chordpro' ) ) . '</textarea></label>' . Songpress_ChordTool::reference_html( 'spe-chordpro' ) . self::sheet_preview( $song ) . self::chordpro_popup() . '</section>';

		/* Links: streaming, store and video links belong to one version. */
		if ( ! $root ) {
			$o .= '<section class="spe-panel adv spe-own" data-panel="codes"><h3>' . esc_html__( 'Links', 'songpress' ) . '</h3><p class="spe-help">' . esc_html__( 'Streaming, store and video links for this version. There is no limit.', 'songpress' ) . '</p>';
			$o .= self::pair_group( 'links', __( 'Links', 'songpress' ), __( 'Add a link', 'songpress' ), $song ? Songpress_Songs::links( $id ) : array(), 'kind', 'url', Songpress_Songs::LINK_KINDS, 'https://' );
			$o .= self::dk_paste() . '</section>';
		}

		/* Story and notes */
		$o .= '<section class="spe-panel adv" data-panel="story"><h3>' . esc_html__( 'Story and notes', 'songpress' ) . '</h3>';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'The story behind the song', 'songpress' ) . '</span><textarea name="story" rows="8">' . esc_textarea( self::f( $song, 'story' ) ) . '</textarea></label>';
		$o .= '<div class="spe-grid">' . ( $root ? '' : self::input( 'style', __( 'Style', 'songpress' ), $song, '', 'spe-own' ) ) . self::input( 'themes', __( 'Themes', 'songpress' ), $song ) . '</div>';
		if ( ! $root ) {
		$o .= '<label class="spe-field spe-own"><span>' . esc_html__( 'AI prompt (lyrics or music)', 'songpress' ) . '</span><textarea name="ai_prompt" rows="3">' . esc_textarea( self::f( $song, 'ai_prompt' ) ) . '</textarea></label>';
		$o .= '<label class="spe-field spe-own"><span>' . esc_html__( 'Art prompt', 'songpress' ) . '</span><textarea name="art_prompt" rows="3">' . esc_textarea( self::f( $song, 'art_prompt' ) ) . '</textarea></label>';
		$o .= '<label class="spe-field spe-own"><span>' . esc_html__( 'Private notes', 'songpress' ) . '</span><textarea name="notes" rows="6">' . esc_textarea( self::f( $song, 'notes' ) ) . '</textarea></label>';
		}
		$o .= '</section>';

		$o .= '</form>';
		if ( $song && Songpress_Caps::can( 'create' ) ) { // the Create a version button in the Release heading submits this separate form
			$o .= '<form method="post" id="spe-nv-form" hidden action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_new_version', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_new_version"><input type="hidden" name="song_id" value="' . (int) $id . '"><input type="hidden" name="sp_return" value="' . esc_url( self::list_url() ) . '"></form>';
		}

		if ( $song ) {
			$o .= self::versions_panel( $song );
		}
		$o .= '</div></div>' . self::autosave_script() . self::script() . self::player_script() . '</div>';
		return $o;
	}

	private static function notices() {
		$o = '';
		if ( isset( $_GET['sp_saved'] ) ) {
			$o .= '<p class="spe-note ok">' . esc_html__( 'Saved.', 'songpress' ) . '</p>';
		}
		if ( isset( $_GET['sp_dropped'] ) ) {
			$o .= '<p class="spe-note warn">' . esc_html__( 'The ISRC, DistroKid and UPC numbers were not saved because this is the original work. Add a version and enter them there.', 'songpress' ) . '</p>';
		}
		if ( isset( $_GET['sp_noacct'] ) ) {
			/* translators: %s: names */
			$o .= '<p class="spe-note warn">' . esc_html( sprintf( __( 'No account was found for: %s. Those writers were saved without a link.', 'songpress' ), sanitize_text_field( wp_unslash( $_GET['sp_noacct'] ) ) ) ) . '</p>';
		}
		if ( isset( $_GET['sp_shares'] ) ) {
			/* translators: %s: total percent */
			$o .= '<p class="spe-note warn">' . esc_html( sprintf( __( 'Saved, but the writer shares add up to %s%%, not 100.', 'songpress' ), sanitize_text_field( wp_unslash( $_GET['sp_shares'] ) ) ) ) . '</p>';
		}
		if ( isset( $_GET['sp_err'] ) ) {
			$o .= '<p class="spe-note bad" role="alert">' . esc_html( sanitize_text_field( wp_unslash( $_GET['sp_err'] ) ) ) . '</p>';
		}
		return $o;
	}

	/** The popup that holds the ChordPro tool (same page as the public tool, in a frame). Text goes in and out by message; nothing is sent anywhere. */
	/** The Art studio for a work: its latest project if it has one, otherwise a new one for it. */
	public static function art_url( $song_id ) {
		global $wpdb;
		$pid  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Songpress_Install::table( 'art' ) . ' WHERE song_id = %d ORDER BY updated_at DESC, id DESC LIMIT 1', (int) $song_id ) ); // phpcs:ignore WordPress.DB
		$base = self::list_url();
		return $pid ? add_query_arg( array( 'sp_page' => 'art', 'art' => $pid ), $base ) : add_query_arg( array( 'sp_page' => 'art', 'art' => 'new', 'song' => (int) $song_id ), $base );
	}

	/** The Share button and its popup: the public link for this work (no login, never shows splits, codes, notes or money), to copy or send through the device's share sheet. */
	private static function share_popup( $song ) {
		$link  = add_query_arg( 'sp_share', Songpress_Songs::token_for( (int) $song->id ), home_url( '/' ) );
		$title = Songpress_Songs::display_title( $song );
		$o     = '<button type="button" class="spe-ib spe-sharebtn" id="spe-share-open" title="' . esc_attr__( 'Share', 'songpress' ) . '" aria-label="' . esc_attr__( 'Share', 'songpress' ) . '">' . self::ico( 'share' ) . '</button>';
		$o    .= '<dialog class="spe-dialog" id="spe-share-dlg" aria-labelledby="spe-share-h"><form method="dialog"><h3 id="spe-share-h">' . esc_html( sprintf( /* translators: %s: song title */ __( 'Share %s', 'songpress' ), $title ) ) . '</h3>';
		$o    .= '<p class="spe-help">' . esc_html__( 'Anyone with this link can read the song, with its cover, audio and chart. They do not need to log in. They never see splits, codes, notes or money.', 'songpress' ) . '</p>';
		$o    .= '<input type="text" readonly id="spe-share-link" value="' . esc_attr( $link ) . '" onfocus="this.select()" data-title="' . esc_attr( $title ) . '">';
		$o    .= '<div class="spe-dlgbar"><button type="button" class="spe-save" id="spe-share-copy">' . esc_html__( 'Copy link', 'songpress' ) . '</button><button type="button" class="spe-btn" id="spe-share-send" hidden>' . esc_html__( 'Share with an app', 'songpress' ) . '</button><button class="spe-link" value="close">' . esc_html__( 'Close', 'songpress' ) . '</button></div></form></dialog>';
		$o    .= '<script>(function(){var d=document.getElementById("spe-share-dlg"),o=document.getElementById("spe-share-open"),i=document.getElementById("spe-share-link"),c=document.getElementById("spe-share-copy"),s=document.getElementById("spe-share-send");if(!d||!o)return;o.addEventListener("click",function(){if(d.showModal){d.showModal();}else{d.setAttribute("open","");}i.select();});c.addEventListener("click",function(){i.select();var done=function(){c.textContent="Copied";setTimeout(function(){c.textContent="Copy link";},1800);};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(i.value).then(done,function(){document.execCommand("copy");done();});}else{document.execCommand("copy");done();}});if(navigator.share){s.hidden=false;s.addEventListener("click",function(){navigator.share({title:i.getAttribute("data-title"),url:i.value}).catch(function(){});});}d.addEventListener("click",function(e){if(e.target===d){d.close();}});})();</script>';
		return $o;
	}

	private static function chordpro_popup() {
		$o  = '<div id="spe-cp-modal" class="spe-cpm" hidden><div class="spe-cpm-in"><iframe id="spe-cp-frame" title="ChordPro tool"></iframe></div></div>';
		$o .= '<script>(function(){var ed=document.getElementById("spe-chordpro");if(!ed)return;var box=document.getElementById("spe-cp-modal"),fr=document.getElementById("spe-cp-frame"),direct=false,ready=false,pending=null;'
			. 'function send(){if(ready&&fr.contentWindow){fr.contentWindow.postMessage({type:"ct-load",text:ed.value},location.origin);}}'
			. 'function open(u){u=(typeof u==="string"&&u)?u:ed.getAttribute("data-tool");if(!box.hidden)return;box.hidden=false;if(fr.getAttribute("src")!==u){ready=false;fr.setAttribute("src",u);}else{send();}}'
			. 'function close(){box.hidden=true;ed.blur();}'
			. 'ed.addEventListener("focus",function(){if(!direct)open();});ed.addEventListener("blur",function(){direct=false;});'
			. 'document.getElementById("spe-cp-open").addEventListener("click",open);var tp=document.getElementById("spe-cp-top");if(tp)tp.addEventListener("click",open);var tr=document.getElementById("spe-tr-open");if(tr)tr.addEventListener("click",function(){open(ed.getAttribute("data-tr"));});'
			. 'window.addEventListener("message",function(e){if(e.origin!==location.origin||!e.data)return;var d=e.data;'
			. 'if(d.type==="ct-ready"){ready=true;send();}'
			. 'else if(d.type==="ct-save"){ed.value=String(d.text||"");ed.dispatchEvent(new Event("input",{bubbles:true}));close();}'
			. 'else if(d.type==="ct-cancel"){close();}'
			. 'else if(d.type==="ct-direct"){direct=true;box.hidden=true;ed.focus();}});'
			. 'document.addEventListener("keydown",function(e){if(e.key==="Escape"&&!box.hidden)close();});})();</script>';
		return $o;
	}

	/* ---------- credits ---------- */

	private static function credit_group( $role, $title, $add_label, array $existing, $min, $with_user, $with_share, $simple ) {
		$count = max( $min, count( $existing ) );
		$o     = in_array( $role, array( 'writer', 'publisher', 'performer' ), true ) ? self::people_list() : '';
		$o    .= '<div class="spe-group' . ( $simple ? '' : ' adv' ) . ( $with_user ? ' spe-two' : '' ) . '" data-role="' . esc_attr( $role ) . '" data-next="' . (int) $count . '"><h4>' . esc_html( $title ) . '</h4>';
		$o    .= '<div class="spe-head' . ( $with_user ? ' spe-nohead' : '' ) . '"><span>' . esc_html__( 'Name', 'songpress' ) . '</span><span class="adv">IPI</span>' . ( $with_share ? '<span>' . esc_html__( 'Share %', 'songpress' ) . '</span>' : '<span></span>' ) . ( $with_user ? '<span class="adv">' . esc_html__( 'Account', 'songpress' ) . '</span>' : '<span class="adv"></span>' ) . '<span></span></div><div class="spe-rows">';
		for ( $i = 0; $i < $count; $i++ ) {
			$o .= self::credit_row( $role, (string) $i, $existing[ $i ] ?? null, $with_user, $with_share );
		}
		$o .= '</div><template>' . self::credit_row( $role, '__I__', null, $with_user, $with_share ) . '</template>';
		$o .= '<button type="button" class="spe-add">+ ' . esc_html( $add_label ) . '</button></div>';
		return $o;
	}

	/** Performing artist or band: a drop-down of the bands (People > Bands) and artists already set up. A value a work already has stays in the list. */
	private static function artist_select( $song ) {
		global $wpdb;
		$cur   = trim( (string) self::f( $song, 'artist' ) );
		$names = array();
		foreach ( (array) $wpdb->get_col( 'SELECT name FROM ' . Songpress_Install::table( 'profiles' ) . " WHERE kind = 'band' ORDER BY name" ) as $n ) { // phpcs:ignore WordPress.DB
			$names[] = (string) $n;
		}
		foreach ( Songpress_Artists::all() as $a ) {
			$names[] = (string) $a->name;
		}
		$names = Songpress_Songs::merge_options( array(), array_merge( $names, array( $cur ) ) );
		natcasesort( $names );
		$o = '<option value=""></option>';
		foreach ( $names as $n ) {
			$o .= '<option value="' . esc_attr( $n ) . '"' . selected( strtolower( $cur ), strtolower( $n ), false ) . '>' . esc_html( $n ) . '</option>';
		}
		$chips = array();
		foreach ( $names as $n ) {
			$chips[ $n ] = Songpress_Profiles::chip( $n, 28, 'band' );
		}
		return '<label class="spe-field"><span>' . esc_html__( 'Performing artist or band', 'songpress' ) . '</span><span class="spe-artsel"><span class="spe-artchip" aria-hidden="true">' . ( '' !== $cur ? $chips[ $cur ] ?? Songpress_Profiles::chip( $cur, 28, 'band' ) : '' ) . '</span><select name="artist" id="spe-artist">' . $o . '</select></span></label>'
			. '<script>(function(){var s=document.getElementById("spe-artist"),c=s&&s.parentNode.querySelector(".spe-artchip"),M=' . wp_json_encode( $chips ) . ';if(!s||!c)return;s.addEventListener("change",function(){c.innerHTML=M[s.value]||""})})()</script>';
	}

	/** The ISRC belongs to one recording, so it sits with the Release details. A second value gets its own field labelled "Alt". */
	private static function isrc_fields( $existing ) {
		$vals = array();
		foreach ( $existing as $c ) {
			if ( 'isrc' === strtolower( (string) $c->kind ) ) {
				$vals[] = (string) $c->value;
			}
		}
		if ( ! $vals ) {
			$vals = array( '' );
		}
		$o = '';
		foreach ( $vals as $n => $v ) {
			$o .= '<label class="spe-field spe-own"><span>' . esc_html( 0 === $n ? 'ISRC' : 'ISRC Alt' . ( $n > 1 ? ' ' . $n : '' ) ) . '</span><input type="hidden" name="codes[isrc' . (int) $n . '][kind]" value="isrc"><input name="codes[isrc' . (int) $n . '][value]" value="' . esc_attr( $v ) . '"></label>';
		}
		return $o;
	}

	/** The publishing numbers, always listed, empty or not. A second value of the same kind gets its own field labelled "Alt". */
	private static function code_fields( $existing, $root ) {
		$shown = array( 'iswc' => 'ISWC', 'ascap' => 'ASCAP', 'bmi' => 'BMI', 'mlc' => 'MLC', 'ccli' => 'CCLI', 'copyright' => __( 'Copyright no.', 'songpress' ), 'work_id' => __( 'Work ID', 'songpress' ) );
		if ( ! $root ) { // these belong to one recording or release, so only a version has them
			$shown['distrokid'] = 'DistroKid';
			$shown['upc']       = 'UPC';
		}
		$by = array();
		foreach ( $existing as $c ) {
			$by[ strtolower( (string) $c->kind ) ][] = (string) $c->value;
		}
		$fields = array();
		foreach ( $shown as $k => $label ) {
			$vals = $by[ $k ] ?? array( '' );
			foreach ( $vals as $n => $v ) {
				$fields[] = array( $k, 0 === $n ? $label : $label . ' Alt' . ( $n > 1 ? ' ' . $n : '' ), $v );
			}
			unset( $by[ $k ] );
		}
		$o = '<div class="spe-codes">';
		$i = 0;
		foreach ( $fields as $f ) {
			$o .= '<label class="spe-field' . ( ! $root && in_array( $f[0], array( 'distrokid', 'upc', 'isrc' ), true ) ? ' spe-own' : '' ) . '"><span>' . esc_html( $f[1] ) . '</span><input type="hidden" name="codes[' . $i . '][kind]" value="' . esc_attr( $f[0] ) . '"><input name="codes[' . $i . '][value]" value="' . esc_attr( $f[2] ) . '"></label>';
			++$i;
		}
		$o .= '</div>';
		if ( ! $root ) {
			unset( $by['isrc'] ); // on a version the ISRC is entered under Release (isrc_fields), so it is not repeated here
		}
		foreach ( $by as $k => $vals ) { // a kind this form has no field for (an old import, or an ISRC on the original): kept, not shown
			foreach ( $vals as $v ) {
				$o .= '<input type="hidden" name="codes[' . $i . '][kind]" value="' . esc_attr( $k ) . '"><input type="hidden" name="codes[' . $i . '][value]" value="' . esc_attr( $v ) . '">';
				++$i;
			}
		}
		return $o;
	}

	/** The People table as a pick list for writers. Choosing a name fills in that person's IPI. */
	private static $pick_done = false;
	private static $pick_cache = array();

	/** Profiles of one kind for the credit drop-downs: name => array( name, ipi ). */
	private static function pick_profiles( $kind ) {
		if ( ! isset( self::$pick_cache[ $kind ] ) ) {
			global $wpdb;
			$t   = Songpress_Install::table( 'profiles' );
			$out = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT name, ipi FROM {$t} WHERE kind = %s ORDER BY name", $kind ) ) as $r ) {
				$out[ $r->name ] = array( $r->name, (string) $r->ipi );
			}
			uksort( $out, 'strnatcasecmp' );
			self::$pick_cache[ $kind ] = $out;
		}
		return self::$pick_cache[ $kind ];
	}

	/** Script for the writer, publisher and performer drop-downs; printed once per page. */
	private static function people_list() {
		if ( self::$pick_done ) {
			return '';
		}
		self::$pick_done = true;
		$map = array();
		foreach ( array( 'person', 'publisher' ) as $k ) {
			foreach ( self::pick_profiles( $k ) as $n => $r ) {
				$map[ $k ][ $n ] = array( $r[1], Songpress_Profiles::chip( $n, 28, $k ) );
			}
		}
		return '<script>(function(){var M=' . wp_json_encode( $map ) . ';document.addEventListener("change",function(e){var s=e.target;if(!s.matches||!s.matches("select.spe-pick")){return;}var w=s.closest(".spe-who"),t=w.querySelector("input.spe-pickname"),f=w.querySelector(".sp-face"),c=s.closest(".spe-credit"),p=c&&c.querySelector("input[name$=\"[ipi]\"]");if(f){f.remove();}if(s.value==="__new"){t.hidden=false;t.value="";t.focus();return;}t.hidden=true;t.value=s.value;var v=(M[s.dataset.kind]||{})[s.value];if(!v){return;}s.insertAdjacentHTML("beforebegin",v[1]);if(p&&v[0]){p.value=v[0];}});})();</script>';
	}

	private static function credit_row( $role, $i, $c, $with_user, $with_share ) {
		$n    = 'credits[' . $role . '][' . $i . ']';
		$name = (string) ( $c->name ?? '' );
		$kind = Songpress_Profiles::kind_for_role( $role );
		$pick = in_array( $role, array( 'writer', 'publisher', 'performer' ), true );
		$h    = '<div class="spe-credit"><span class="spe-who">' . ( '' !== trim( $name ) ? Songpress_Profiles::chip( $name, 28, $kind ) : '' );
		if ( $pick ) {
			$list = self::pick_profiles( $kind );
			$h   .= '<select class="spe-pick" data-kind="' . esc_attr( $kind ) . '"><option value="">' . esc_html__( 'Choose…', 'songpress' ) . '</option>';
			$seen = false;
			foreach ( $list as $pn => $r ) {
				$on   = 0 === strcasecmp( $pn, trim( $name ) );
				$seen = $seen || $on;
				$h   .= '<option value="' . esc_attr( $pn ) . '"' . ( $on ? ' selected' : '' ) . '>' . esc_html( $pn ) . '</option>';
			}
			if ( '' !== trim( $name ) && ! $seen ) {
				$h .= '<option value="' . esc_attr( $name ) . '" selected>' . esc_html( $name ) . '</option>';
			}
			$h .= '<option value="__new">' . esc_html__( '+ Someone new…', 'songpress' ) . '</option></select>';
			$h .= '<input class="spe-pickname" hidden name="' . esc_attr( $n ) . '[name]" value="' . esc_attr( $name ) . '" placeholder="' . esc_attr__( 'Name', 'songpress' ) . '"></span>';
		} else {
			$h .= '<input name="' . esc_attr( $n ) . '[name]" value="' . esc_attr( $name ) . '" placeholder="' . esc_attr__( 'Name', 'songpress' ) . '"></span>';
		}
		$h   .= '<input class="' . ( $with_user ? '' : 'adv' ) . '" name="' . esc_attr( $n ) . '[ipi]" value="' . esc_attr( $c->ipi ?? '' ) . '" placeholder="IPI">';
		if ( $with_share ) {
			$share = ( isset( $c->share ) && null !== $c->share ) ? rtrim( rtrim( (string) $c->share, '0' ), '.' ) : '';
			$h    .= '<input name="' . esc_attr( $n ) . '[share]" value="' . esc_attr( $share ) . '" inputmode="decimal" placeholder="' . esc_attr( 'performer' === $role ? rtrim( rtrim( number_format( (float) Songpress_Money::rules()['performer_default'], 2, '.', '' ), '0' ), '.' ) . '%' : '%' ) . '" title="' . esc_attr( 'performer' === $role ? __( 'Performer share of this recording. Leave blank for the default set in Settings > Earnings.', 'songpress' ) : '' ) . '">';
		} else {
			$h .= '<span></span>';
		}
		if ( $with_user ) {
			// The account link is not shown; it is kept as it is so saving never unlinks a writer.
			$h .= ! empty( $c->user_id ) ? '<input type="hidden" name="' . esc_attr( $n ) . '[user_id]" value="' . (int) $c->user_id . '">' : '';
		} else {
			$h .= '<span class="adv"></span>';
		}
		return $h . '<button type="button" class="spe-x" aria-label="' . esc_attr__( 'Remove this row', 'songpress' ) . '">&times;</button></div>';
	}

	/** Paste the text DistroKid shows after a release (HyperFollow) and the streaming links in it are added to this version. Runs in the browser; nothing is sent until the work saves. */
	const LOGO_BASE = 'https://www.google.com/s2/favicons?sz=128&domain=';

	/** The streaming icons live in the Media Library once imported (option songpress_stream_icons: key => attachment id). */
	public static function stream_domains() {
		return array( 'spotify' => 'spotify.com', 'apple' => 'music.apple.com', 'itunes' => 'itunes.apple.com', 'amazon' => 'music.amazon.com', 'youtube' => 'youtube.com', 'tidal' => 'tidal.com', 'deezer' => 'deezer.com', 'pandora' => 'pandora.com', 'tiktok' => 'tiktok.com', 'bandcamp' => 'bandcamp.com', 'soundcloud' => 'soundcloud.com' );
	}

	/** Copy each service's icon into the Media Library, once. Returns the key => attachment id map. */
	public static function import_stream_icons() {
		$have = (array) get_option( 'songpress_stream_icons', array() );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		foreach ( self::stream_domains() as $k => $d ) {
			if ( ! empty( $have[ $k ] ) && wp_get_attachment_url( (int) $have[ $k ] ) ) {
				continue;
			}
			$tmp = download_url( self::LOGO_BASE . $d, 20 );
			if ( is_wp_error( $tmp ) ) {
				continue;
			}
			$id = media_handle_sideload( array( 'name' => 'songpress-streaming-' . $k . '.png', 'tmp_name' => $tmp ), 0, 'SongPress streaming icon: ' . $k );
			if ( is_wp_error( $id ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $tmp );
				continue;
			}
			$have[ $k ] = (int) $id;
		}
		update_option( 'songpress_stream_icons', $have, false );
		return $have;
	}

	/** key => icon url: the Media Library copy, imported on first use; Google's address only while that has not worked. */
	public static function stream_icon_urls() {
		static $out = null;
		if ( null !== $out ) {
			return $out;
		}
		$have = (array) get_option( 'songpress_stream_icons', array() );
		$all  = true;
		foreach ( self::stream_domains() as $k => $d ) {
			if ( empty( $have[ $k ] ) || ! wp_get_attachment_url( (int) $have[ $k ] ) ) {
				$all = false;
			}
		}
		if ( ! $all && is_user_logged_in() && current_user_can( 'upload_files' ) && ! get_transient( 'songpress_icons_try' ) ) {
			set_transient( 'songpress_icons_try', 1, 6 * HOUR_IN_SECONDS );
			$have = self::import_stream_icons();
		}
		$out = array();
		foreach ( self::stream_domains() as $k => $d ) {
			$u         = ! empty( $have[ $k ] ) ? wp_get_attachment_url( (int) $have[ $k ] ) : '';
			$out[ $k ] = $u ? $u : self::LOGO_BASE . $d;
		}
		return $out;
	}

	/** Streaming icons for a list of links (kind, url), as plain anchors. Empty when there is nothing to open. */
	public static function stream_links_html( $links ) {
		$names = array( 'spotify' => 'Spotify', 'apple' => 'Apple Music', 'itunes' => 'iTunes', 'amazon' => 'Amazon Music', 'youtube' => 'YouTube', 'tidal' => 'Tidal', 'deezer' => 'Deezer', 'pandora' => 'Pandora', 'tiktok' => 'TikTok', 'bandcamp' => 'Bandcamp', 'soundcloud' => 'SoundCloud' );
		$cols  = array( 'spotify' => '#1db954', 'apple' => '#fa2d48', 'itunes' => '#b455f0', 'amazon' => '#00a8e1', 'youtube' => '#ff0000', 'tidal' => '#111111', 'deezer' => '#a238ff', 'pandora' => '#3668ff', 'tiktok' => '#111111', 'bandcamp' => '#1da0c3', 'soundcloud' => '#ff5500' );
		$icon  = self::stream_icon_urls();
		$o     = '';
		$seen  = array();
		foreach ( (array) $links as $l ) {
			$k = strtolower( trim( (string) $l->kind ) );
			$u = trim( (string) $l->url );
			if ( ! isset( $names[ $k ] ) || isset( $seen[ $k ] ) || ! preg_match( '#^https?://#i', $u ) ) {
				continue;
			}
			$seen[ $k ] = 1;
			/* translators: %s: streaming service */
			$t  = sprintf( __( 'Open on %s', 'songpress' ), $names[ $k ] );
			$o .= '<a class="spe-st" href="' . esc_url( $u ) . '" target="_blank" rel="noopener" style="background:' . esc_attr( $cols[ $k ] ) . '" title="' . esc_attr( $t ) . '" aria-label="' . esc_attr( $t ) . '"><img src="' . esc_url( $icon[ $k ] ) . '" alt="" width="28" height="28" loading="lazy" onerror="this.outerHTML=\'<b>' . esc_attr( $names[ $k ][0] ) . '</b>\'"></a>';
		}
		return '' === $o ? '' : '<div class="spe-streams" aria-label="' . esc_attr__( 'Listen on', 'songpress' ) . '">' . $o . '</div>';
	}

	/** The printable chord sheet, shown under the ChordPro box and kept in step with it. */
	private static function sheet_preview( $song ) {
		$src = Songpress_ChordTool::url() . '&sheet=1' . ( $song ? '&song=' . (int) $song->id : '' );
		return '<details class="spe-sheet adv" open><summary>' . esc_html__( 'Chord sheet (PDF)', 'songpress' ) . '</summary><iframe id="spe-sheet-frame" title="' . esc_attr__( 'Chord sheet preview', 'songpress' ) . '" data-src="' . esc_url( $src ) . '" loading="lazy"></iframe></details>'
			. '<script>(function(){var d=document.querySelector(".spe-sheet"),f=document.getElementById("spe-sheet-frame"),t=document.getElementById("spe-chordpro");if(!d||!f||!t)return;var ready=false,tm;'
			. 'function send(){if(ready&&f.contentWindow)f.contentWindow.postMessage({spChart:t.value},location.origin)}'
			. 'function load(){if(!f.getAttribute("src"))f.setAttribute("src",f.getAttribute("data-src"))}'
			. 'window.addEventListener("message",function(e){if(e.origin!==location.origin||!e.data||!e.data.spSheetReady)return;ready=true;send()});'
			. 't.addEventListener("input",function(){clearTimeout(tm);tm=setTimeout(send,350)});'
			. 'd.addEventListener("toggle",function(){if(d.open)load()});if(d.open)load();})()</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped parts and a fixed script.
	}

	/** Streaming icons above the player, built from this version's Links rows and kept current as they change. Tapping one opens that service (its app, on a phone). */
	private static function stream_widget() {
		$names = array( 'spotify' => 'Spotify', 'apple' => 'Apple Music', 'itunes' => 'iTunes', 'amazon' => 'Amazon Music', 'youtube' => 'YouTube', 'tidal' => 'Tidal', 'deezer' => 'Deezer', 'pandora' => 'Pandora', 'tiktok' => 'TikTok', 'bandcamp' => 'Bandcamp', 'soundcloud' => 'SoundCloud' );
		$cols  = array( 'spotify' => '#1db954', 'apple' => '#fa2d48', 'itunes' => '#b455f0', 'amazon' => '#00a8e1', 'youtube' => '#ff0000', 'tidal' => '#111111', 'deezer' => '#a238ff', 'pandora' => '#3668ff', 'tiktok' => '#111111', 'bandcamp' => '#1da0c3', 'soundcloud' => '#ff5500' );
		
		$cfg   = wp_json_encode( array( 'names' => $names, 'cols' => $cols, 'icons' => self::stream_icon_urls(), 'open' => __( 'Open on %s', 'songpress' ), 'none' => __( 'Streaming icons appear here once this version has links.', 'songpress' ) ) );
		return '<div class="spe-streams" id="spe-streams" aria-label="' . esc_attr__( 'Listen on', 'songpress' ) . '"></div><script>(function(){var C=' . $cfg . ',box=document.getElementById("spe-streams"),g;'
			. 'function esc(t){return String(t).replace(/[&<>"]/g,function(c){return{"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;"}[c]})}'
			. 'function draw(){var h="",seen={};Array.prototype.forEach.call(g.querySelectorAll(".spe-rows .spe-pair"),function(r){var i=r.querySelectorAll("input"),k=(i[0].value||"").toLowerCase().trim(),u=(i[1].value||"").trim();if(!C.names[k]||!/^https?:\/\//i.test(u)||seen[k])return;seen[k]=1;var n=C.names[k];h+="<a class=\"spe-st\" href=\""+esc(u)+"\" target=\"_blank\" rel=\"noopener\" style=\"background:"+C.cols[k]+"\" title=\""+esc(C.open.replace("%s",n))+"\" aria-label=\""+esc(C.open.replace("%s",n))+"\">"+"<img src=\""+C.icons[k]+"\" alt=\"\" width=\"28\" height=\"28\" onerror=\"this.outerHTML=\'<b>"+esc(n.charAt(0))+"</b>\'\">"+"</a>"});box.innerHTML=h||"<span class=\\"spe-help\\">"+esc(C.none)+"</span>";box.hidden=false}'
			. 'function init(){g=document.querySelector(".spe-pairs[data-group=links]");if(!box)return;if(!g){box.innerHTML="<span class=\\"spe-help\\">"+esc(C.none)+"</span>";return}g.addEventListener("input",draw);g.addEventListener("click",function(){setTimeout(draw,0)});draw()}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init)}else{init()}})()</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON and a fixed script.
	}

	private static function dk_paste() {
		$cfg = wp_json_encode( array(
			'none'  => __( 'No streaming links found in that text.', 'songpress' ),
			'added' => __( 'Added %d link(s). They save with the work.', 'songpress' ),
			'dupes' => __( '%d already here were skipped.', 'songpress' ),
			'nolink' => __( 'That text has the names (Spotify, Apple Music…) but no web addresses. Copy again from the page itself, or paste the addresses.', 'songpress' ),
		) );
		return '<details class="spe-dk"><summary>' . esc_html__( 'Paste links from DistroKid', 'songpress' ) . '</summary><p class="spe-help">' . esc_html__( 'Copy the DistroKid HyperFollow page (or any text with links in it), paste it here and the streaming links are added to this version. Pasting keeps the links behind the names.', 'songpress' ) . '</p><textarea id="spe-dk-text" rows="6" placeholder="[Spotify](https://open.spotify.com/album/...)"></textarea><p><button type="button" class="spe-btn" id="spe-dk-go">' . esc_html__( 'Add the links', 'songpress' ) . '</button> <span id="spe-dk-msg" class="spe-help" role="status"></span></p></details>'
			. '<script>(function(){var C=' . $cfg . ',go=document.getElementById("spe-dk-go"),ta=document.getElementById("spe-dk-text"),msg=document.getElementById("spe-dk-msg"),g=document.querySelector(".spe-pairs[data-group=links]");if(!go||!g)return;'
			. 'var HOSTS=[["open.spotify.com","spotify"],["spotify.com","spotify"],["music.apple.com","apple"],["itunes.apple.com","itunes"],["amazon.","amazon"],["music.youtube.com","youtube"],["youtube.com","youtube"],["youtu.be","youtube"],["tidal.com","tidal"],["deezer.com","deezer"],["pandora.com","pandora"],["tiktok.com","tiktok"],["bandcamp.com","bandcamp"],["soundcloud.com","soundcloud"]];'
			. 'function kindOf(label,url){var l=(label||"").toLowerCase().trim();if(/^apple\s*music$/.test(l))return"apple";if(l==="itunes")return"itunes";if(/^amazon/.test(l))return"amazon";if(/^youtube/.test(l))return"youtube";if(/^spotify$/.test(l))return"spotify";if(/^tidal$/.test(l))return"tidal";if(/^deezer$/.test(l))return"deezer";if(/^pandora$/.test(l))return"pandora";if(/^tik\s*tok$/.test(l))return"tiktok";if(/^bandcamp$/.test(l))return"bandcamp";if(/^soundcloud$/.test(l))return"soundcloud";var h="";try{h=new URL(url).hostname}catch(e){}for(var i=0;i<HOSTS.length;i++){if(h.indexOf(HOSTS[i][0])>-1)return HOSTS[i][1]}return l.replace(/[^a-z0-9]+/g,"_").replace(/^_|_$/g,"").slice(0,16)||"other"}'
			. 'function skip(label,url){return /distrokid\.com/i.test(url)||/only you can see|merch/i.test(label||"")}'
			. 'function parse(t){var out=[],seen={},m,re=/\[([^\]]*)\]\((https?:\/\/[^)\s]+)\)/g;while((m=re.exec(t))){if(skip(m[1],m[2])||seen[m[2]])continue;seen[m[2]]=1;out.push([kindOf(m[1],m[2]),m[2]])}var rest=t.replace(re," "),re2=/https?:\/\/[^\s<>)\]]+/g;while((m=re2.exec(rest))){var u=m[0].replace(/[.,;]+$/,"");if(skip("",u)||seen[u])continue;seen[u]=1;out.push([kindOf("",u),u])}return out}'
			. 'ta.addEventListener("paste",function(e){var cd=e.clipboardData,h=cd&&cd.getData("text/html");if(!h)return;var d=new DOMParser().parseFromString(h,"text/html"),a=d.querySelectorAll("a[href]"),o=[];Array.prototype.forEach.call(a,function(x){var u=x.getAttribute("href")||"";if(/^https?:/i.test(u))o.push("["+(x.textContent||"").replace(/[\\[\\]]/g," ").trim()+"]("+u+")")});if(!o.length)return;e.preventDefault();ta.value=(ta.value?ta.value+"\\n":"")+o.join("\\n")});'
			. 'function rows(){return Array.prototype.slice.call(g.querySelectorAll(".spe-rows .spe-pair"))}'
			. 'function fire(el){el.dispatchEvent(new Event("input",{bubbles:true}))}'
			. 'go.addEventListener("click",function(){var found=parse(ta.value||"");if(!found.length){msg.textContent=/spotify|apple music|amazon|itunes|youtube/i.test(ta.value||"")?C.nolink:C.none;return}var have={};rows().forEach(function(r){var v=r.querySelector("input[name$=\"[value]\"]");if(v&&v.value)have[v.value.trim()]=1});var added=0,dup=0;found.forEach(function(f){if(have[f[1]]){dup++;return}var r=rows().filter(function(x){var i=x.querySelectorAll("input");return !i[0].value&&!i[1].value})[0];if(!r){g.querySelector(".spe-add").click();var all=rows();r=all[all.length-1]}var i=r.querySelectorAll("input");i[0].value=f[0];i[1].value=f[1];fire(i[0]);fire(i[1]);have[f[1]]=1;added++});msg.textContent=C.added.replace("%d",added)+(dup?" "+C.dupes.replace("%d",dup):"");if(added)ta.value=""});'
			. '})()</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON and a fixed script.
	}

	private static function pair_group( $group, $title, $add_label, array $existing, $k_field, $v_field, array $kinds, $placeholder ) {
		$line = function ( $i, $k, $v ) use ( $group, $placeholder ) {
			$n = $group . '[' . $i . ']';
			return '<div class="spe-pair"><input name="' . esc_attr( $n ) . '[kind]" list="spe-kinds-' . esc_attr( $group ) . '" value="' . esc_attr( $k ) . '" placeholder="' . esc_attr__( 'type', 'songpress' ) . '"><input name="' . esc_attr( $n ) . '[value]" value="' . esc_attr( $v ) . '" placeholder="' . esc_attr( $placeholder ) . '"><button type="button" class="spe-x" aria-label="' . esc_attr__( 'Remove this row', 'songpress' ) . '">&times;</button></div>';
		};
		$o = '<div class="spe-group spe-pairs" data-group="' . esc_attr( $group ) . '" data-next="' . (int) max( 1, count( $existing ) ) . '"><h4>' . esc_html( $title ) . '</h4><datalist id="spe-kinds-' . esc_attr( $group ) . '">';
		foreach ( $kinds as $k ) {
			$o .= '<option value="' . esc_attr( $k ) . '">';
		}
		$o .= '</datalist><div class="spe-rows">';
		$i  = 0;
		foreach ( $existing as $e ) {
			$o .= $line( $i++, $e->$k_field, $e->$v_field );
		}
		if ( ! $existing ) {
			$o .= $line( 0, '', '' );
		}
		return $o . '</div><template>' . $line( '__I__', '', '' ) . '</template><button type="button" class="spe-add">+ ' . esc_html( $add_label ) . '</button></div>';
	}

	/* ---------- media ---------- */

	/**
	 * One drop target. Cover art: drop an image on it or click it to pick one from the computer. Audio: a player; drop a file
	 * on it or click it to choose from the WordPress Media Library. No upload buttons and no link boxes.
	 */
	private static function media_box( $field, $label, $accept, $id, $url, $tool_url = '' ) {
		$is_img = 'art' === $field;
		$src    = $id ? ( $is_img ? (string) wp_get_attachment_image_url( $id, 'medium' ) : (string) wp_get_attachment_url( $id ) ) : '';
		if ( '' === $src && '' !== $url ) {
			$src = $url;
		}
		$ph  = $is_img ? __( 'Drop cover art here, or click to choose an image', 'songpress' ) : __( 'Drop an audio file here, or click to choose from the Media Library', 'songpress' );
		$o   = '<div class="spe-media" data-field="' . esc_attr( $field ) . '" role="group" aria-label="' . esc_attr( $label ) . '"><div class="spe-mhead">' . ( '' !== $tool_url ? '<a class="spe-toolico" href="' . esc_url( $tool_url ) . '" target="_blank" rel="noopener" title="' . esc_attr__( 'Cover art tool', 'songpress' ) . '" aria-label="' . esc_attr__( 'Cover art tool', 'songpress' ) . '"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="13.5" cy="6.5" r=".6" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".6" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".6" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".6" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.9 0 1.65-.75 1.65-1.7 0-.44-.18-.83-.44-1.12-.29-.29-.44-.65-.44-1.13a1.64 1.64 0 011.67-1.67h2c3.05 0 5.55-2.5 5.55-5.55C21.97 6.01 17.46 2 12 2z"/></svg></a>' : '' ) . '</div>';
		$o  .= '<input type="hidden" name="' . esc_attr( $field ) . '_id" value="' . (int) $id . '"><input type="hidden" class="spe-clr" name="' . esc_attr( $field ) . '_clear" value="0"><input type="hidden" name="' . esc_attr( $field ) . '_url" value="' . esc_attr( $url ) . '">';
		$rml = $is_img ? __( 'Remove the cover art', 'songpress' ) : __( 'Remove the audio', 'songpress' );
		$o  .= '<div class="spe-mrow ' . ( $is_img ? 'art' : 'audio' ) . '"><button type="button" class="spe-rm spe-ib danger" title="' . esc_attr( $rml ) . '" aria-label="' . esc_attr( $rml ) . '"' . ( '' === $src ? ' hidden' : '' ) . '>' . self::ico( 'trash' ) . '</button>';
		$o  .= '<div class="spe-prev' . ( $is_img ? ' spe-dz' : ' spe-player' ) . '" data-ph="' . esc_attr( $ph ) . '" title="' . esc_attr( $ph ) . '" tabindex="0" role="button">' . ( '' !== $src ? ( $is_img ? '<img src="' . esc_url( $src ) . '" alt="">' : '<audio preload="metadata" src="' . esc_url( $src ) . '"></audio>' ) : '' ) . '</div></div>';
		$o  .= '<input type="file" class="spe-pick" accept="' . esc_attr( $accept ) . '" hidden></div>';
		return $o;
	}

	/* ---------- versions, sharing, archive ---------- */

	private static function post_form( $action, $nonce, $id, $fields, $button, $class = 'spe-btn' ) {
		$o = '<form method="post" class="spe-inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( $nonce, '_wpnonce', true, false );
		$o .= '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="song_id" value="' . (int) $id . '"><input type="hidden" name="sp_return" value="' . esc_url( self::list_url() ) . '">';
		foreach ( $fields as $k => $v ) {
			$o .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		return $o . '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $button ) . '</button></form>';
	}

	/** On the original work: the versions that have been released, each linked, with a short line of what it is. */
	private static function released_list( $song ) {
		if ( ! $song ) {
			return '';
		}
		$res = Songpress_Songs::query( array( 'family_id' => (int) $song->family_id, 'per_page' => 100 ) );
		$li  = '';
		foreach ( $res['rows'] as $v ) {
			if ( (int) $v->version_no < 1 ) {
				continue;
			}
			if ( 'released' !== $v->status && '' === $v->release_title . $v->release_date ) {
				continue;
			}
			$bits = array_filter( array( $v->release_date, $v->artist, $v->album, $v->genre, $v->musical_key, '' !== (string) $v->tempo ? $v->tempo . ' BPM' : '', $v->format ), 'strlen' );
			$li  .= '<li><a href="' . esc_url( self::edit_url( $v->id ) ) . '"><span class="songpress-serial">' . esc_html( $v->serial ) . '</span> <b>' . esc_html( '' !== (string) $v->release_title ? $v->release_title : Songpress_Songs::display_title( $v ) ) . '</b></a>' . ( $bits ? '<small class="spe-help">' . esc_html( implode( ' · ', $bits ) ) . '</small>' : '' ) . '</li>';
		}
		if ( '' === $li ) {
			return '';
		}
		return '<h4>' . esc_html__( 'Released versions', 'songpress' ) . '</h4><ul class="spe-versions spe-released">' . $li . '</ul>';
	}

	private static function versions_panel( $song ) {
		$id = (int) $song->id;
		$o  = '<section class="spe-panel adv" data-panel="versions"><h3>' . esc_html__( 'Versions', 'songpress' ) . '</h3>';
		$res = Songpress_Songs::query( array( 'family_id' => (int) $song->family_id, 'per_page' => 100, 'archived' => 'any' ) );
		$o  .= '<h4>' . esc_html__( 'This song and its versions', 'songpress' ) . '</h4><ul class="spe-versions">';
		foreach ( $res['rows'] as $v ) {
			$o .= '<li><a href="' . esc_url( self::edit_url( $v->id ) ) . '"><span class="songpress-serial">' . esc_html( $v->serial ) . '</span> ' . esc_html( Songpress_Songs::display_title( $v ) ) . '</a>' . ( $v->archived_at ? ' <em>' . esc_html__( '(archived)', 'songpress' ) . '</em>' : '' ) . ( (int) $v->id === $id ? ' <em>' . esc_html__( '(this one)', 'songpress' ) . '</em>' : '' ) . '</li>';
		}
		$o .= '</ul>';
		if ( Songpress_Caps::can( 'create' ) ) {
			$o .= '<p class="spe-help">' . esc_html__( 'A version is a new recording, arrangement or release of this song. It gets the next number, like 0012-01. Writers and splits start as a copy of this one. Performers and producers start empty. Nothing you change on a version affects the original or the other versions.', 'songpress' ) . '</p>';
			$o .= self::post_form( 'songpress_new_version', 'songpress_new_version', $id, array(), __( 'Add a version', 'songpress' ) );
			$o .= '<p class="spe-help">' . esc_html__( 'Duplicate version makes a full copy of this one as the next version: release details, art, audio and everyone credited. The ISRC, UPC and streaming links are left off.', 'songpress' ) . '</p>' . self::post_form( 'songpress_dup_version', 'songpress_dup_version', $id, array(), __( 'Duplicate version', 'songpress' ) );
		}
		if ( Songpress_Caps::can( 'edit_any' ) ) {
			$o .= '<h4>' . esc_html__( 'Serial number', 'songpress' ) . '</h4><p class="spe-help">' . esc_html__( 'A work is 0012 and its versions are 0012-01, 0012-02. Change the number to move this song: give a version a plain number like 0300 to make it a work of its own, or 0012-04 to make it a version of 0012.', 'songpress' ) . '</p>';
			$o .= '<form method="post" class="spe-inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_set_serial', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_set_serial"><input type="hidden" name="song_id" value="' . (int) $id . '"><input type="hidden" name="sp_return" value="' . esc_url( self::list_url() ) . '"><input type="text" name="serial" value="' . esc_attr( $song->serial ) . '" size="10" aria-label="' . esc_attr__( 'Serial number', 'songpress' ) . '"> <button type="submit" class="spe-btn">' . esc_html__( 'Change serial number', 'songpress' ) . '</button></form>';
		}
		if ( Songpress_Songs::ARCHIVING && Songpress_Caps::can( 'archive' ) ) {
			$archived = ! empty( $song->archived_at );
			$o       .= '<h4>' . esc_html__( 'Archive', 'songpress' ) . '</h4>';
			if ( ! $archived ) {
				$o .= '<p class="spe-help">' . ( 0 === (int) $song->version_no ? esc_html__( 'Archiving the original work also archives all of its versions. Nothing is deleted, and the serial numbers stay reserved.', 'songpress' ) : esc_html__( 'Archiving hides this version. Nothing is deleted, and its serial number stays reserved.', 'songpress' ) ) . '</p>';
			}
			$o .= self::post_form( 'songpress_archive', 'songpress_archive', $id, array( 'do' => $archived ? 'restore' : 'archive' ), $archived ? __( 'Restore this song', 'songpress' ) : __( 'Archive this song', 'songpress' ), $archived ? 'spe-btn' : 'spe-btn danger' );
		}
		if ( Songpress_Caps::can( 'delete' ) ) {
			$many = count( Songpress_Songs::delete_ids( $id ) );
			$o   .= '<h4>' . esc_html__( 'Delete', 'songpress' ) . '</h4><p class="spe-help">' . esc_html( 0 === (int) $song->version_no && $many > 1
				? sprintf( /* translators: %d: number of songs */ __( 'Deleting this original work also deletes its %d versions. This cannot be undone.', 'songpress' ), $many - 1 )
				: __( 'This removes the song, its credits, codes, links, history and earnings. This cannot be undone.', 'songpress' ) ) . ' ' . esc_html__( 'Files in the Media Library stay, and the serial number is never given to another work.', 'songpress' ) . '</p>';
			$o   .= '<form method="post" class="spe-inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_delete', '_wpnonce', true, false )
				. '<input type="hidden" name="action" value="songpress_delete"><input type="hidden" name="song_id" value="' . (int) $id . '"><input type="hidden" name="sp_return" value="' . esc_url( self::list_url() ) . '">'
				. '<button type="submit" class="spe-btn danger">' . esc_html( 0 === (int) $song->version_no ? __( 'Delete this work', 'songpress' ) : __( 'Delete this version', 'songpress' ) ) . '</button></form>';
		}
		$hist = Songpress_History::for_song( $id );
		if ( $hist ) {
			$o .= '<h4>' . esc_html__( 'History', 'songpress' ) . '</h4><ul class="spe-history">';
			foreach ( array_slice( $hist, 0, 30 ) as $r ) {
				$u  = $r->user_id ? get_userdata( (int) $r->user_id ) : false;
				$o .= '<li><time>' . esc_html( get_date_from_gmt( $r->created_at, 'Y-m-d H:i' ) ) . '</time> <b>' . esc_html( $u ? $u->display_name : __( 'System', 'songpress' ) ) . '</b> ' . esc_html( Songpress_History::describe( $r ) ) . '</li>';
			}
			$o .= '</ul>';
		}
		return $o . '</section>';
	}

	/* ---------- behaviour ---------- */

	/** Saves the work as you type: no Save button. A quiet "Updated" shows where the button was for three seconds after each save. */
	private static function autosave_script() {
		$cfg = wp_json_encode( array(
			'ajax'    => admin_url( 'admin-ajax.php' ),
			'updated' => __( 'Updated', 'songpress' ),
			'saving'  => __( 'Saving…', 'songpress' ),
			'failed'  => __( 'Not saved:', 'songpress' ),
		) );
		return '<script>(function(){var C=' . $cfg . ',f=document.getElementById("spe-form"),u=document.getElementById("spe-updated");if(!f||!u||!window.fetch||!window.FormData)return;var btn=document.querySelector(".spe-topsave");if(btn)btn.hidden=true;'
			. 'function snap(){var p=[];new FormData(f).forEach(function(v,k){if(typeof v==="string"&&k!=="revision"&&k!=="song_id"&&k!=="_wpnonce"&&k!=="action"&&k!=="sp_return")p.push(k+"="+v)});return p.join("&")}'
			. 'var last=snap(),bad=null,busy=false,again=false,t=null,hide=null;'
			. 'function show(txt,bad){u.hidden=false;u.className="spe-updated"+(bad?" bad":"");u.textContent=txt;clearTimeout(hide);if(!bad&&txt===C.updated){hide=setTimeout(function(){u.hidden=true},3000)}}'
			. 'function put(n,v){var e=f.elements[n];if(e)e.value=v}'
			. 'function save(){if(busy){again=true;return}var now=snap();if(now===last)return;busy=true;show(C.saving,false);var d=new FormData(f);d.set("action","songpress_autosave");'
			. 'fetch(C.ajax,{method:"POST",body:d,credentials:"same-origin"}).then(function(r){return r.json()}).then(function(j){busy=false;if(j&&j.success){last=now;bad=null;put("revision",j.data.revision);if(j.data.id&&!+f.elements.song_id.value){put("song_id",j.data.id);try{var l=new URL(location.href);l.searchParams.set("sp_edit",j.data.id);history.replaceState(null,"",l.toString())}catch(e){}}show(C.updated,false)}else{bad=now;show(C.failed+" "+((j&&j.data&&j.data.msg)||""),true)}if(again){again=false;save()}}).catch(function(){busy=false;bad=now;show(C.failed,true)})}'
			. 'function soon(){clearTimeout(t);t=setTimeout(save,1200)}'
			. 'f.addEventListener("input",soon);f.addEventListener("change",soon);f.addEventListener("click",function(e){if(e.target.closest("button"))soon()});'
			. 'f.addEventListener("submit",function(e){e.preventDefault();save()});'
			. 'setInterval(function(){var n=snap();if(!busy&&n!==last&&n!==bad)soon()},2000);'
			. 'window.addEventListener("pagehide",function(){if(snap()!==last&&navigator.sendBeacon){var d=new FormData(f);d.set("action","songpress_autosave");navigator.sendBeacon(C.ajax,d)}});'
			. '})()</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON and a fixed script.
	}

	/** The audio player: play and pause, a playhead you can drag, and the length. Built from the plain audio element so it looks the same in every browser. */
	private static function player_script() {
		$cfg = wp_json_encode( array( 'play' => __( 'Play', 'songpress' ), 'pause' => __( 'Pause', 'songpress' ), 'head' => __( 'Playhead', 'songpress' ) ) );
		return '<script>(function(){var C=' . $cfg . ';var P=\'<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>\',Q=\'<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>\';'
			. 'function fmt(s){if(!isFinite(s)||s<0)s=0;var m=Math.floor(s/60),x=Math.floor(s%60);return m+":"+(x<10?"0":"")+x}'
			. 'function mk(p){var au=p.querySelector("audio");if(!au||p.querySelector(".spe-ap"))return;au.controls=false;au.preload="metadata";var w=document.createElement("div");w.className="spe-ap";w.innerHTML=\'<button type="button" class="spe-ap-b"></button><input type="range" class="spe-ap-r" min="0" max="1000" value="0" step="1"><span class="spe-ap-t">0:00 / 0:00</span>\';p.appendChild(w);'
			. 'var b=w.querySelector(".spe-ap-b"),r=w.querySelector(".spe-ap-r"),t=w.querySelector(".spe-ap-t"),drag=false;r.setAttribute("aria-label",C.head);'
			. 'function ico(){var pl=!au.paused;b.innerHTML=pl?Q:P;b.setAttribute("aria-label",pl?C.pause:C.play)}function upd(){var d=au.duration;t.textContent=fmt(au.currentTime)+" / "+fmt(d);if(!drag&&isFinite(d)&&d>0)r.value=Math.round(au.currentTime/d*1000)}'
			. 'ico();b.addEventListener("click",function(e){e.stopPropagation();if(au.paused){au.play()}else{au.pause()}});'
			. 'au.addEventListener("play",ico);au.addEventListener("pause",ico);au.addEventListener("ended",function(){ico();upd()});au.addEventListener("timeupdate",upd);au.addEventListener("loadedmetadata",upd);au.addEventListener("durationchange",upd);'
			. 'r.addEventListener("input",function(e){e.stopPropagation();drag=true;var d=au.duration;if(isFinite(d)&&d>0){au.currentTime=r.value/1000*d;t.textContent=fmt(au.currentTime)+" / "+fmt(d)}});r.addEventListener("change",function(e){e.stopPropagation();drag=false});r.addEventListener("click",function(e){e.stopPropagation()});upd()}'
			. 'window.speAP=mk;document.querySelectorAll(".spe-player").forEach(mk)})()</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON and a fixed script.
	}

	private static function script() {
		$cfg = wp_json_encode( array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'songpress_upload' ) ) );
		$js  = <<<'JS'
(function(){
var root=document.getElementById("spe");if(!root)return;
var panels=root.querySelector(".spe-panels"),want=panels.getAttribute("data-on")||"";
root.querySelectorAll(".spe-panel.adv").forEach(function(p){if(p.getAttribute("data-panel")!==want)p.classList.add("closed");var h=p.querySelector("h3");if(h)h.addEventListener("click",function(){p.classList.toggle("closed");});});
function total(){var g=root.querySelector(".spe-group[data-role=writer]"),o=root.querySelector(".sp-total");if(!g||!o)return;var s=0,any=false;g.querySelectorAll("input[name$='[share]']").forEach(function(i){var v=parseFloat(i.value);if(!isNaN(v)){s+=v;any=true;}});o.textContent=any?"Writer shares: "+(Math.round(s*100)/100)+"%"+(Math.abs(s-100)>0.05?" (should be 100)":""):"";}
root.addEventListener("input",function(e){if(e.target.name&&/\[share\]$/.test(e.target.name))total();});
root.addEventListener("click",function(e){
var a=e.target.closest(".spe-add");
if(a){var g=a.closest(".spe-group"),t=g.querySelector("template"),n=parseInt(g.getAttribute("data-next")||"0",10);var d=document.createElement("div");d.innerHTML=t.innerHTML.replace(/__I__/g,n);g.querySelector(".spe-rows").appendChild(d.firstElementChild);g.setAttribute("data-next",n+1);var f=g.querySelector(".spe-rows").lastElementChild.querySelector("input");if(f)f.focus();return;}
var x=e.target.closest(".spe-x");if(x){var r=x.parentNode;r.parentNode.removeChild(r);total();return;}
var rm=e.target.closest(".spe-rm");if(rm){var b=rm.closest(".spe-media");b.querySelector("input[type=hidden]").value="0";b.querySelector(".spe-clr").value="1";var u=b.querySelector("input[name$='_url']");if(u)u.value="";b.querySelector(".spe-prev").innerHTML="";rm.hidden=true;var ch=b.querySelector(".spe-change");if(ch)ch.hidden=true;return;}
var c=e.target.closest("#spe-copy");if(c){var i=document.getElementById("spe-share");i.select();try{navigator.clipboard.writeText(i.value);c.textContent="Copied";}catch(err){document.execCommand("copy");}}
});
function msg(t){var m=document.getElementById("spe-msg");if(m)m.textContent=t;}
function show(d){var b=root.querySelector(".spe-media[data-field="+d.field+"]");if(!b)return;b.querySelector("input[type=hidden]").value=d.id;b.querySelector(".spe-clr").value="0";var u=b.querySelector("input[name$='_url']");if(u)u.value="";var p=b.querySelector(".spe-prev");p.innerHTML="";if(d.field==="art"&&d.thumb){var im=document.createElement("img");im.src=d.thumb;im.alt="";p.appendChild(im);}else if(d.field==="audio"&&d.url){var au=document.createElement("audio");au.preload="metadata";au.src=d.url;p.appendChild(au);if(window.speAP)window.speAP(p);var ch=b.querySelector(".spe-change");if(ch)ch.hidden=false;}else{p.textContent=d.name||"Uploaded";}b.querySelector(".spe-rm").hidden=false;msg("Added "+(d.name||"file")+".");}
function lib(b){if(!window.wp||!wp.media){b.querySelector(".spe-pick").click();return;}var fr=wp.media({title:"Choose audio",library:{type:"audio"},multiple:false});fr.on("select",function(){var a=fr.state().get("selection").first().toJSON();show({field:"audio",id:a.id,url:a.url,name:a.filename||a.title});});fr.open();}
root.addEventListener("click",function(e){var pv=e.target.closest(".spe-prev");if(pv){var b=pv.closest(".spe-media");if(!b)return;if(b.getAttribute("data-field")==="art"){b.querySelector(".spe-pick").click();}else{var au=pv.querySelector("audio");if(!au||au.classList.contains("spe-empty")){e.preventDefault();lib(b);}}return;}var c=e.target.closest(".spe-change");if(c){lib(c.closest(".spe-media"));}});
function send(file){var fd=new FormData();fd.append("action","songpress_upload");fd.append("nonce",CFG.nonce);fd.append("file",file);msg("Uploading "+file.name+"...");return fetch(CFG.url,{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){if(j&&j.success){show(j.data);}else{msg((j&&j.data&&j.data.message)||"That file could not be added.");}}).catch(function(){msg("Upload failed.");});}
root.addEventListener("change",function(e){var i=e.target;if(i.type==="file"&&i.files&&i.files[0]){send(i.files[0]);i.value="";}});
var n=0;function files(e){return e.dataTransfer&&e.dataTransfer.types&&[].indexOf.call(e.dataTransfer.types,"Files")>-1;}
root.addEventListener("dragenter",function(e){if(files(e)){n++;root.classList.add("dropping");}});
root.addEventListener("dragleave",function(e){if(files(e)){n=Math.max(0,n-1);if(!n)root.classList.remove("dropping");}});
root.addEventListener("dragover",function(e){if(files(e))e.preventDefault();});
root.addEventListener("drop",function(e){if(!files(e))return;e.preventDefault();n=0;root.classList.remove("dropping");var l=[].slice.call(e.dataTransfer.files),p=Promise.resolve();l.forEach(function(f){p=p.then(function(){return send(f);});});});
total();
})();
JS;
		return '<script>var CFG=' . $cfg . ';' . $js . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON from wp_json_encode and a fixed script.
	}
}
