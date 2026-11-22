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
	public static function list_url() {
		return remove_query_arg( array( 'sp_edit', 'sp_saved', 'sp_err', 'sp_noacct', 'sp_shares', 'sp_dropped', 'sp_archived', 'sp_tab', 'sp_song', 'sp_view', 'sp_page', 'sp_members', 'saved', 'err', 'done', 'skipped', 'accounts', 'left', 'nomedia', 'profile', 'kind', 'loaded', 'b', 'p', 'loaderr', 'artist', 'checked', 'person', 'type', 'm_ok', 'm_err' ) );
	}

	public static function edit_url( $id ) {
		return add_query_arg( 'sp_edit', $id ? (int) $id : 'new', self::list_url() );
	}

	private static function f( $song, $name ) {
		return $song ? (string) $song->$name : '';
	}

	private static function input( $name, $label, $song, $extra = '', $class = '' ) {
		return '<label class="spe-field ' . esc_attr( $class ) . '"><span>' . esc_html( $label ) . '</span><input name="' . esc_attr( $name ) . '" value="' . esc_attr( self::f( $song, $name ) ) . '" ' . $extra . '></label>';
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
		$o       .= '<div class="spe-top"><a class="spe-back" href="' . esc_url( self::list_url() ) . '">&lsaquo; ' . esc_html__( 'Works', 'songpress' ) . '</a><h2>' . ( $song ? '<span class="songpress-serial">' . esc_html( $song->serial ) . '</span> ' . esc_html( $title ) : esc_html__( 'Add a song', 'songpress' ) ) . '</h2>' . ( $song ? '<a class="spe-viewlink" href="' . esc_url( add_query_arg( array( 'sp_song' => $id, 'sp_view' => 1 ), self::list_url() ) ) . '">' . esc_html__( 'View song', 'songpress' ) . '</a>' : '' ) . '<div class="spe-tools">' . ( $song && ! wp_is_mobile() && Songpress_Caps::can( 'registrations' ) ? '<a class="spe-btn spe-toplink" href="' . esc_url( add_query_arg( array( 'sp_page' => 'registrations', 'work' => $id ), self::list_url() ) ) . '">' . esc_html__( 'Registrations', 'songpress' ) . '</a>' : '' ) . ( $song && ! wp_is_mobile() && Songpress_Caps::can( 'agreements' ) ? '<a class="spe-btn spe-toplink" href="' . esc_url( add_query_arg( array( 'sp_page' => 'agreements', 'song' => $id ), self::list_url() ) ) . '">' . esc_html__( 'Agreements', 'songpress' ) . '</a>' : '' ) . ( $song && ! wp_is_mobile() && Songpress_Caps::can( 'pitch' ) ? '<a class="spe-btn spe-toplink" href="' . esc_url( add_query_arg( array( 'sp_page' => 'pitch', 'song' => $id, 'wizard' => 1 ), self::list_url() ) ) . '">' . esc_html__( 'Pitch', 'songpress' ) . '</a>' : '' ) . ( $song && ! wp_is_mobile() && Songpress_Caps::can( 'art' ) ? '<a class="spe-btn spe-arttop" href="' . esc_url( self::art_url( $id ) ) . '">' . esc_html__( 'Art studio', 'songpress' ) . '</a>' : '' ) . ( wp_is_mobile() ? '' : '<button type="button" class="spe-btn spe-cptop" id="spe-cp-top">' . esc_html__( 'ChordPro tool', 'songpress' ) . '</button>' ) . '</div>' . '</div>';
		$o       .= self::notices();
		if ( $song && $song->archived_at ) {
			$o .= '<p class="spe-note warn">' . esc_html__( 'This song is archived. It is hidden from the catalog and from public pages, and its serial number stays reserved. Restore it from the Versions and sharing section.', 'songpress' ) . '</p>';
		}

		$on = isset( $_GET['sp_tab'] ) ? sanitize_key( wp_unslash( $_GET['sp_tab'] ) ) : 'basics'; // phpcs:ignore WordPress.Security.NonceVerification
		$o .= '<div class="spe-layout"><div class="spe-panels" data-on="' . esc_attr( $on ) . '">';

		$o .= '<form method="post" class="spe-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_save', '_wpnonce', true, false );
		$o .= '<input type="hidden" name="action" value="songpress_save"><input type="hidden" name="song_id" value="' . $id . '"><input type="hidden" name="revision" value="' . (int) ( $song->revision ?? 0 ) . '"><input type="hidden" name="sp_return" value="' . esc_url( self::list_url() ) . '">';

		/* Basics */
		$statuses = Songpress_Songs::statuses_for( $version );
		if ( $song && isset( Songpress_Songs::statuses()[ $song->status ] ) ) {
			$statuses[ $song->status ] = Songpress_Songs::statuses()[ $song->status ];
		}
		$opts = '';
		foreach ( $statuses as $k => $label ) {
			$opts .= '<option value="' . esc_attr( $k ) . '"' . selected( self::f( $song, 'status' ) ?: 'idea', $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$cur_artist  = $song ? (int) $song->artist_id : ( isset( $_GET['sp_artist'] ) ? absint( $_GET['sp_artist'] ) : Songpress_Artists::default_id() );
		$all_artists = Songpress_Artists::all();
		$artist_html = '<input type="hidden" name="artist_id" value="' . (int) $cur_artist . '">';
		if ( count( $all_artists ) > 1 ) {
			$ao = '';
			foreach ( $all_artists as $a ) {
				$ao .= '<option value="' . (int) $a->id . '"' . selected( $cur_artist, (int) $a->id, false ) . '>' . esc_html( $a->name ) . '</option>';
			}
			$artist_html = Songpress_Caps::can( 'create' ) ? '<label class="spe-field"><span>' . esc_html__( 'Artist', 'songpress' ) . '</span><select name="artist_id">' . $ao . '</select></label>' : $artist_html;
		}
		$o .= '<section class="spe-panel simple" data-panel="basics"><h3>' . esc_html__( 'Basics', 'songpress' ) . '</h3><div class="spe-grid">';
		$o .= '<label class="spe-field wide"><span>' . esc_html__( 'Title', 'songpress' ) . '</span><input name="title" value="' . esc_attr( self::f( $song, 'title' ) ) . '" required' . Songpress_Names::attrs( 'song', $song ? (int) $song->family_id : 0 ) . '></label>' . Songpress_Names::script();
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Status', 'songpress' ) . '</span><select name="status">' . $opts . '</select></label>' . $artist_html;
		$o .= self::input( 'stage', __( 'Writing stage', 'songpress' ), $song, '', 'adv' );
		$o .= self::input( 'writing_date', __( 'Written on', 'songpress' ), $song, 'placeholder="YYYY-MM-DD"', 'adv' );
		$o .= self::input( 'copyright_date', __( 'Copyright date', 'songpress' ), $song, 'placeholder="YYYY-MM-DD"', 'adv' );
		$o .= '<label class="spe-field wide adv"><span>' . esc_html__( 'Other titles', 'songpress' ) . '</span><textarea name="alt_titles" rows="2">' . esc_textarea( self::f( $song, 'alt_titles' ) ) . '</textarea></label>';
		$o .= '</div>';
		if ( ! $song ) {
			$o .= '<p class="spe-help">' . esc_html__( 'The serial number is assigned for you. The original work is just four digits, like 0012. Each version after it adds two more, like 0012-01.', 'songpress' ) . '</p>';
		}
		$o .= '</section>';

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
		$o .= '<section class="spe-panel simple" data-panel="people"><h3>' . esc_html__( 'People and splits', 'songpress' ) . '</h3><p class="spe-help adv">' . esc_html__( 'Writer shares are the splits for this version and should add up to 100. Everyone listed belongs to this version only. Type a username or email in Account to let that person see this song on their own profile. Add as many people as the song has.', 'songpress' ) . '</p>';
		$o .= self::credit_group( 'writer', __( 'Writers', 'songpress' ), __( 'Add writer', 'songpress' ), $by_role['writer'], 2, true, true, true );
		$o .= self::credit_group( 'publisher', __( 'Publishers', 'songpress' ), __( 'Add publisher', 'songpress' ), $by_role['publisher'], 1, false, true, false );
		$o .= self::credit_group( 'label', __( 'Labels', 'songpress' ), __( 'Add label', 'songpress' ), $by_role['label'], 0, false, false, false );
		$o .= self::credit_group( 'performer', __( 'Performers', 'songpress' ), __( 'Add performer', 'songpress' ), $by_role['performer'], 1, false, false, false );
		$o .= self::credit_group( 'producer', __( 'Producers', 'songpress' ), __( 'Add producer', 'songpress' ), $by_role['producer'], 1, false, true, false );
		$o .= '</section>';

		/* Release: everything that belongs to one recording or release, so only versions have it. */
		$legacy = $song && 0 === $version && ( '' !== self::f( $song, 'artist' ) . self::f( $song, 'album' ) . self::f( $song, 'release_title' ) . self::f( $song, 'release_date' ) );
		$rel    = '<section class="spe-panel simple" data-panel="release"><h3>' . esc_html__( 'Release', 'songpress' ) . '</h3>';
		if ( $song && ( $version > 0 || $legacy ) ) {
			$rel .= '<p class="spe-help">' . esc_html__( 'These belong to this version only: a new version gets its own release name, band, key, tempo and genre. ISRC and DistroKid numbers go under Codes and links.', 'songpress' ) . '</p>';
			if ( $legacy ) {
				$rel .= '<p class="spe-note warn">' . esc_html__( 'This is the original work, which should not have release information. It came from the old catalog. Add a version and enter it there.', 'songpress' ) . '</p>';
			}
			$rel .= '<div class="spe-grid">' . self::input( 'release_title', __( 'Release title', 'songpress' ), $song ) . self::input( 'release_date', __( 'Release date', 'songpress' ), $song, 'placeholder="YYYY-MM-DD"' ) . self::input( 'album', __( 'Album', 'songpress' ), $song ) . self::input( 'artist', __( 'Performing artist or band', 'songpress' ), $song );
			$rel .= self::input( 'genre', __( 'Genre', 'songpress' ), $song, 'list="spe-genres"' );
			$rel .= '<datalist id="spe-genres">';
			foreach ( Songpress_Songs::genres() as $gn ) {
				$rel .= '<option value="' . esc_attr( $gn ) . '">';
			}
			$rel .= '</datalist>';
			$rel .= self::input( 'musical_key', __( 'Key', 'songpress' ), $song ) . self::input( 'tempo', __( 'Tempo (BPM)', 'songpress' ), $song ) . self::input( 'time_signature', __( 'Time signature', 'songpress' ), $song ) . self::input( 'format', __( 'Format', 'songpress' ), $song ) . self::input( 'production_date', __( 'Produced on', 'songpress' ), $song, 'placeholder="YYYY-MM-DD"' ) . '</div>';
		} else {
			$rel .= '<p class="spe-help">' . esc_html__( 'Release details (release name, band, album, genre, key, tempo, dates) belong to versions. Add a version when this song is recorded or released.', 'songpress' ) . '</p>';
		}
		$rel .= '</section>';

		$o .= $rel;

		/* Art and audio */
		$o .= '<section class="spe-panel simple" data-panel="media"><h3>' . esc_html__( 'Art and audio', 'songpress' ) . '</h3><p class="spe-help">' . esc_html__( 'Drag an image or an audio file anywhere onto this page, or use the buttons. Files are saved to the WordPress Media Library.', 'songpress' ) . '</p><p class="spe-drop" id="spe-msg" aria-live="polite"></p><div class="spe-grid">';
		$o .= self::media_box( 'art', __( 'Cover art', 'songpress' ), 'image/*', (int) self::f( $song, 'art_id' ), self::f( $song, 'art_url' ) );
		$o .= self::media_box( 'audio', __( 'Audio', 'songpress' ), 'audio/*', (int) self::f( $song, 'audio_id' ), self::f( $song, 'audio_url' ) );
		$o .= '</div></section>';

		/* Lyrics and chords */
		$o .= '<section class="spe-panel simple" data-panel="chart"><h3>' . esc_html__( 'Lyrics and chords', 'songpress' ) . '</h3><p class="spe-help adv">' . esc_html__( 'Each work has three levels: ChordPro, then chords and lyrics, then lyrics. A level you leave blank falls back to the highest level that is filled in, with the chords taken off. Phones show the highest level.', 'songpress' ) . '</p>';
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
		$o .= '<label class="spe-field adv"><span>ChordPro</span><textarea class="spe-chart" name="chordpro" id="spe-chordpro" rows="14" data-tool="' . esc_url( Songpress_ChordTool::url() . '&embed=1' ) . '">' . esc_textarea( self::f( $song, 'chordpro' ) ) . '</textarea><small class="spe-help">' . esc_html__( 'Click in the box to open the ChordPro tool. Use the button below to type here instead.', 'songpress' ) . '</small><button type="button" class="spe-btn" id="spe-cp-open">' . esc_html__( 'Open the ChordPro tool', 'songpress' ) . '</button></label>' . self::chordpro_popup() . '</section>';

		/* Codes and links */
		$o .= '<section class="spe-panel adv" data-panel="codes"><h3>' . esc_html__( 'Codes and links', 'songpress' ) . '</h3><p class="spe-help">' . esc_html__( 'Add any codes this song has (ISWC, CCLI, MLC, ISRC and so on) and any links (streaming, store, video). There is no limit. ISRC and DistroKid codes belong on versions.', 'songpress' ) . '</p>';
		$o .= self::pair_group( 'codes', __( 'Codes', 'songpress' ), __( 'Add a code', 'songpress' ), $song ? Songpress_Songs::identifiers( $id ) : array(), 'kind', 'value', Songpress_Songs::CODE_KINDS, __( 'code', 'songpress' ) );
		$o .= self::pair_group( 'links', __( 'Links', 'songpress' ), __( 'Add a link', 'songpress' ), $song ? Songpress_Songs::links( $id ) : array(), 'kind', 'url', Songpress_Songs::LINK_KINDS, 'https://' );
		$o .= '</section>';

		/* Story and notes */
		$o .= '<section class="spe-panel adv" data-panel="story"><h3>' . esc_html__( 'Story and notes', 'songpress' ) . '</h3>';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'The story behind the song', 'songpress' ) . '</span><textarea name="story" rows="8">' . esc_textarea( self::f( $song, 'story' ) ) . '</textarea></label>';
		$o .= '<div class="spe-grid">' . self::input( 'style', __( 'Style', 'songpress' ), $song ) . self::input( 'themes', __( 'Themes', 'songpress' ), $song ) . '</div>';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'AI prompt (lyrics or music)', 'songpress' ) . '</span><textarea name="ai_prompt" rows="3">' . esc_textarea( self::f( $song, 'ai_prompt' ) ) . '</textarea></label>';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Art prompt', 'songpress' ) . '</span><textarea name="art_prompt" rows="3">' . esc_textarea( self::f( $song, 'art_prompt' ) ) . '</textarea></label>';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Private notes', 'songpress' ) . '</span><textarea name="notes" rows="6">' . esc_textarea( self::f( $song, 'notes' ) ) . '</textarea></label></section>';

		$o .= '<div class="spe-bar"><button type="submit" class="spe-save">' . esc_html( $song ? __( 'Save', 'songpress' ) : __( 'Create song', 'songpress' ) ) . '</button><a href="' . esc_url( self::list_url() ) . '">' . esc_html__( 'Cancel', 'songpress' ) . '</a><span class="sp-total" aria-live="polite"></span></div>';
		$o .= '</form>';

		if ( $song ) {
			$o .= self::versions_panel( $song );
		}
		$o .= '</div></div>' . self::script() . '</div>';
		return $o;
	}

	private static function notices() {
		$o = '';
		if ( isset( $_GET['sp_saved'] ) ) {
			$o .= '<p class="spe-note ok">' . esc_html__( 'Saved.', 'songpress' ) . '</p>';
		}
		if ( isset( $_GET['sp_dropped'] ) ) {
			$o .= '<p class="spe-note warn">' . esc_html__( 'ISRC and DistroKid codes were not saved because this is the original work. Add a version and enter them there.', 'songpress' ) . '</p>';
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

	private static function chordpro_popup() {
		$o  = '<div id="spe-cp-modal" class="spe-cpm" hidden><div class="spe-cpm-in"><iframe id="spe-cp-frame" title="ChordPro tool"></iframe></div></div>';
		$o .= '<script>(function(){var ed=document.getElementById("spe-chordpro");if(!ed)return;var box=document.getElementById("spe-cp-modal"),fr=document.getElementById("spe-cp-frame"),direct=false,ready=false,pending=null;'
			. 'function send(){if(ready&&fr.contentWindow){fr.contentWindow.postMessage({type:"ct-load",text:ed.value},location.origin);}}'
			. 'function open(){if(!box.hidden)return;box.hidden=false;ready=false;if(!fr.getAttribute("src")){fr.setAttribute("src",ed.getAttribute("data-tool"));}else{send();}}'
			. 'function close(){box.hidden=true;ed.blur();}'
			. 'ed.addEventListener("focus",function(){if(!direct)open();});ed.addEventListener("blur",function(){direct=false;});'
			. 'document.getElementById("spe-cp-open").addEventListener("click",open);var tp=document.getElementById("spe-cp-top");if(tp)tp.addEventListener("click",open);'
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
		$o     = '<div class="spe-group' . ( $simple ? '' : ' adv' ) . '" data-role="' . esc_attr( $role ) . '" data-next="' . (int) $count . '"><h4>' . esc_html( $title ) . '</h4>';
		$o    .= '<div class="spe-head"><span>' . esc_html__( 'Name', 'songpress' ) . '</span><span class="adv">IPI</span>' . ( $with_share ? '<span>' . esc_html__( 'Share %', 'songpress' ) . '</span>' : '<span></span>' ) . ( $with_user ? '<span class="adv">' . esc_html__( 'Account', 'songpress' ) . '</span>' : '<span class="adv"></span>' ) . '<span></span></div><div class="spe-rows">';
		for ( $i = 0; $i < $count; $i++ ) {
			$o .= self::credit_row( $role, (string) $i, $existing[ $i ] ?? null, $with_user, $with_share );
		}
		$o .= '</div><template>' . self::credit_row( $role, '__I__', null, $with_user, $with_share ) . '</template>';
		$o .= '<button type="button" class="spe-add">+ ' . esc_html( $add_label ) . '</button></div>';
		return $o;
	}

	private static function credit_row( $role, $i, $c, $with_user, $with_share ) {
		$n    = 'credits[' . $role . '][' . $i . ']';
		$name = (string) ( $c->name ?? '' );
		$h    = '<div class="spe-credit"><span class="spe-who">' . ( '' !== trim( $name ) ? Songpress_Profiles::chip( $name, 28, Songpress_Profiles::kind_for_role( $role ) ) : '' ) . '<input name="' . esc_attr( $n ) . '[name]" value="' . esc_attr( $name ) . '" placeholder="' . esc_attr__( 'Name', 'songpress' ) . '"></span>';
		$h   .= '<input class="adv" name="' . esc_attr( $n ) . '[ipi]" value="' . esc_attr( $c->ipi ?? '' ) . '" placeholder="IPI">';
		if ( $with_share ) {
			$share = ( isset( $c->share ) && null !== $c->share ) ? rtrim( rtrim( (string) $c->share, '0' ), '.' ) : '';
			$h    .= '<input name="' . esc_attr( $n ) . '[share]" value="' . esc_attr( $share ) . '" inputmode="decimal" placeholder="%">';
		} else {
			$h .= '<span></span>';
		}
		if ( $with_user ) {
			$login = '';
			if ( ! empty( $c->user_id ) ) {
				$u     = get_userdata( (int) $c->user_id );
				$login = $u ? $u->user_login : '';
			}
			$h .= '<input class="adv" name="' . esc_attr( $n ) . '[account]" value="' . esc_attr( $login ) . '" placeholder="' . esc_attr__( 'username or email', 'songpress' ) . '">';
		} else {
			$h .= '<span class="adv"></span>';
		}
		return $h . '<button type="button" class="spe-x" aria-label="' . esc_attr__( 'Remove this row', 'songpress' ) . '">&times;</button></div>';
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

	private static function media_box( $field, $label, $accept, $id, $url ) {
		$is_img = 'art' === $field;
		$src    = $id ? ( $is_img ? (string) wp_get_attachment_image_url( $id, 'medium' ) : (string) wp_get_attachment_url( $id ) ) : '';
		if ( '' === $src && '' !== $url ) {
			$src = $url;
		}
		$o  = '<div class="spe-media" data-field="' . esc_attr( $field ) . '"><span class="spe-label">' . esc_html( $label ) . '</span>';
		$o .= '<input type="hidden" name="' . esc_attr( $field ) . '_id" value="' . (int) $id . '"><input type="hidden" class="spe-clr" name="' . esc_attr( $field ) . '_clear" value="0">';
		$o .= '<div class="spe-prev">' . ( '' !== $src ? ( $is_img ? '<img src="' . esc_url( $src ) . '" alt="">' : '<audio controls preload="none" src="' . esc_url( $src ) . '"></audio>' ) : '' ) . '</div>';
		$o .= '<label class="spe-btn">' . esc_html( $is_img ? __( 'Upload image', 'songpress' ) : __( 'Upload audio', 'songpress' ) ) . '<input type="file" accept="' . esc_attr( $accept ) . '" hidden></label> <button type="button" class="spe-link spe-rm"' . ( '' === $src ? ' hidden' : '' ) . '>' . esc_html__( 'Remove', 'songpress' ) . '</button>';
		$o .= '<label class="spe-field adv"><span>' . esc_html__( 'or paste a link', 'songpress' ) . '</span><input name="' . esc_attr( $field ) . '_url" value="' . esc_attr( $url ) . '" placeholder="https://"></label></div>';
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

	private static function versions_panel( $song ) {
		$id = (int) $song->id;
		$o  = '<section class="spe-panel adv" data-panel="versions"><h3>' . esc_html__( 'Versions and sharing', 'songpress' ) . '</h3>';
		$res = Songpress_Songs::query( array( 'family_id' => (int) $song->family_id, 'per_page' => 100, 'archived' => 'any' ) );
		$o  .= '<h4>' . esc_html__( 'This song and its versions', 'songpress' ) . '</h4><ul class="spe-versions">';
		foreach ( $res['rows'] as $v ) {
			$o .= '<li><a href="' . esc_url( self::edit_url( $v->id ) ) . '"><span class="songpress-serial">' . esc_html( $v->serial ) . '</span> ' . esc_html( Songpress_Songs::display_title( $v ) ) . '</a>' . ( $v->archived_at ? ' <em>' . esc_html__( '(archived)', 'songpress' ) . '</em>' : '' ) . ( (int) $v->id === $id ? ' <em>' . esc_html__( '(this one)', 'songpress' ) . '</em>' : '' ) . '</li>';
		}
		$o .= '</ul>';
		if ( Songpress_Caps::can( 'create' ) ) {
			$o .= '<p class="spe-help">' . esc_html__( 'A version is a new recording, arrangement or release of this song. It gets the next number, like 0012-01. Writers and splits start as a copy of this one. Performers and producers start empty. Nothing you change on a version affects the original or the other versions.', 'songpress' ) . '</p>';
			$o .= self::post_form( 'songpress_new_version', 'songpress_new_version', $id, array(), __( 'Add a version', 'songpress' ) );
		}
		if ( Songpress_Caps::can_share_song( $id ) ) {
			$on   = ! empty( $song->share_token );
			$link = $on ? add_query_arg( 'sp_share', $song->share_token, home_url( '/' ) ) : '';
			$o   .= '<h4>' . esc_html__( 'Share', 'songpress' ) . '</h4>';
			if ( ! empty( $song->archived_at ) ) {
				$o .= '<p class="spe-help">' . esc_html__( 'Archived songs cannot be shared.', 'songpress' ) . '</p>';
			} else {
				$o .= '<p class="spe-help">' . esc_html__( 'Anyone with the link can read this song, with its cover, audio and chart. They do not need to log in. They never see splits, codes, notes or money. Turn it off, or make a new link, to stop an old one working.', 'songpress' ) . '</p>';
				if ( $on ) {
					$o .= '<p class="spe-sharebox"><input type="text" readonly id="spe-share" value="' . esc_attr( $link ) . '" onfocus="this.select()"> <button type="button" class="spe-btn" id="spe-copy">' . esc_html__( 'Copy link', 'songpress' ) . '</button></p>';
				}
				foreach ( $on ? array( 'new' => __( 'Make a new link', 'songpress' ), 'off' => __( 'Turn sharing off', 'songpress' ) ) : array( 'on' => __( 'Turn sharing on', 'songpress' ) ) as $do => $label ) {
					$o .= self::post_form( 'songpress_share', 'songpress_share', $id, array( 'do' => $do ), $label );
				}
			}
		}
		if ( Songpress_Caps::can( 'archive' ) ) {
			$archived = ! empty( $song->archived_at );
			$o       .= '<h4>' . esc_html__( 'Archive', 'songpress' ) . '</h4>';
			if ( ! $archived ) {
				$o .= '<p class="spe-help">' . ( 0 === (int) $song->version_no ? esc_html__( 'Archiving the original work also archives all of its versions. Nothing is deleted, and the serial numbers stay reserved.', 'songpress' ) : esc_html__( 'Archiving hides this version. Nothing is deleted, and its serial number stays reserved.', 'songpress' ) ) . '</p>';
			}
			$o .= self::post_form( 'songpress_archive', 'songpress_archive', $id, array( 'do' => $archived ? 'restore' : 'archive' ), $archived ? __( 'Restore this song', 'songpress' ) : __( 'Archive this song', 'songpress' ), $archived ? 'spe-btn' : 'spe-btn danger' );
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
var rm=e.target.closest(".spe-rm");if(rm){var b=rm.closest(".spe-media");b.querySelector("input[type=hidden]").value="0";b.querySelector(".spe-clr").value="1";var u=b.querySelector("input[name$='_url']");if(u)u.value="";b.querySelector(".spe-prev").innerHTML="";rm.hidden=true;return;}
var c=e.target.closest("#spe-copy");if(c){var i=document.getElementById("spe-share");i.select();try{navigator.clipboard.writeText(i.value);c.textContent="Copied";}catch(err){document.execCommand("copy");}}
});
function msg(t){var m=document.getElementById("spe-msg");if(m)m.textContent=t;}
function show(d){var b=root.querySelector(".spe-media[data-field="+d.field+"]");if(!b)return;b.querySelector("input[type=hidden]").value=d.id;b.querySelector(".spe-clr").value="0";var p=b.querySelector(".spe-prev");p.innerHTML="";if(d.field==="art"&&d.thumb){var im=document.createElement("img");im.src=d.thumb;im.alt="";p.appendChild(im);}else{p.textContent=d.name||"Uploaded";}b.querySelector(".spe-rm").hidden=false;msg("Added "+(d.name||"file")+". Press Save to keep it.");}
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
