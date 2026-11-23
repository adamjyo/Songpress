<?php
defined( 'ABSPATH' ) || exit;

/**
 * The ChordPro tool: a standalone page anyone can open (no login), at /?sp_chordpro=1. It edits and shows ChordPro
 * charts, transposes, converts chords-over-lyrics, draws guitar chord diagrams and saves files. It never reads or writes
 * the database: everything stays in the visitor's browser. It also stands in for the desktop Songpress chord-sheet
 * program (Skeed / Lallulli), recreated here as a web page.
 */
class Songpress_ChordTool {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'page' ), 1 );
	}

	/** A reference of the ChordPro directives ("shortcodes") for the bottom of a ChordPro box. Click one to put it in the box at the cursor. */
	public static function reference_html( $target ) {
		$groups = array(
			__( 'About the song', 'songpress' ) => array(
				array( '{title: }', '{t: }', __( 'Song title', 'songpress' ) ),
				array( '{subtitle: }', '{st: }', __( 'Writers or a second line under the title', 'songpress' ) ),
				array( '{artist: }', '', __( 'Performing artist', 'songpress' ) ),
				array( '{composer: }', '', __( 'Composer (shows on the sheet)', 'songpress' ) ),
				array( '{lyricist: }', '', __( 'Lyricist (shows on the sheet)', 'songpress' ) ),
				array( '{key: }', '', __( 'Key, for example B or F#m', 'songpress' ) ),
				array( '{tempo: }', '', __( 'Beats per minute', 'songpress' ) ),
				array( '{time: }', '', __( 'Time signature, for example 4/4', 'songpress' ) ),
				array( '{capo: }', '', __( 'Capo fret', 'songpress' ) ),
				array( '{copyright: }', '', __( 'Copyright line for the foot of the sheet', 'songpress' ) ),
				array( '{ccli: }', '', __( 'CCLI song number (SongPress; the work\'s own number is used if you leave it out)', 'songpress' ) ),
				array( '{iswc: }', '', __( 'ISWC (SongPress; used when there is no CCLI number)', 'songpress' ) ),
			),
			__( 'Sections', 'songpress' ) => array(
				array( '{start_of_verse}', '{sov}', __( 'Begin a verse. Add a label: {start_of_verse: Verse 1}', 'songpress' ) ),
				array( '{end_of_verse}', '{eov}', __( 'End the verse', 'songpress' ) ),
				array( '{start_of_chorus}', '{soc}', __( 'Begin a chorus', 'songpress' ) ),
				array( '{end_of_chorus}', '{eoc}', __( 'End the chorus', 'songpress' ) ),
				array( '{start_of_bridge}', '{sob}', __( 'Begin a bridge', 'songpress' ) ),
				array( '{end_of_bridge}', '{eob}', __( 'End the bridge', 'songpress' ) ),
				array( '{start_of_intro}', '', __( 'Also: instrumental, outro, tag, prechorus (end with {end_of_intro} and so on)', 'songpress' ) ),
				array( '{start_of_tab}', '{sot}', __( 'Tab or monospaced block (end with {end_of_tab} or {eot})', 'songpress' ) ),
			),
			__( 'Layout', 'songpress' ) => array(
				array( '{column_break}', '{colb}', __( 'Start the next column (a second one starts a new page)', 'songpress' ) ),
				array( '{new_page}', '{np}', __( 'Start a new page', 'songpress' ) ),
			),
			__( 'Notes and chords', 'songpress' ) => array(
				array( '{comment: }', '{c: }', __( 'A note on the sheet', 'songpress' ) ),
				array( '{comment_italic: }', '{ci: }', __( 'A note in italics', 'songpress' ) ),
				array( '[G]', '', __( 'A chord, typed right before the word it lands on: [G]Amazing [C]grace', 'songpress' ) ),
				array( '[G/B]', '', __( 'A slash chord with a different bass note', 'songpress' ) ),
				array( '# a note', '', __( 'A line starting with # is ignored (your own reminder)', 'songpress' ) ),
			),
		);
		$o = '<details class="sp-cpref"><summary>' . esc_html__( 'ChordPro reference', 'songpress' ) . '</summary><p class="sp-cpref-hint">' . esc_html__( 'Tap one to put it in the box at the cursor.', 'songpress' ) . '</p>';
		foreach ( $groups as $name => $rows ) {
			$o .= '<h4>' . esc_html( $name ) . '</h4><dl>';
			foreach ( $rows as $r ) {
				$o .= '<dt><button type="button" class="sp-cpref-ins" data-ins="' . esc_attr( $r[0] ) . '">' . esc_html( $r[0] ) . '</button>' . ( '' !== $r[1] ? ' <button type="button" class="sp-cpref-ins sp-cpref-short" data-ins="' . esc_attr( $r[1] ) . '">' . esc_html( $r[1] ) . '</button>' : '' ) . '</dt><dd>' . esc_html( $r[2] ) . '</dd>';
			}
			$o .= '</dl>';
		}
		return $o . '</details><script>(function(){var t=document.getElementById(' . wp_json_encode( $target ) . '),r=document.currentScript.previousElementSibling;if(!t||!r)return;r.addEventListener("click",function(e){var b=e.target.closest("[data-ins]");if(!b)return;var v=b.getAttribute("data-ins"),a=t.selectionStart||0,z=t.selectionEnd||0,pre=t.value.slice(0,a),line=a>0&&pre.charAt(pre.length-1)!=="\n"&&/^[{#]/.test(v);var ins=(line?"\n":"")+v+(/^[{]/.test(v)&&v.indexOf(": }")<0?"\n":"");t.value=pre+ins+t.value.slice(z);var c=pre.length+ins.length-(v.indexOf(": }")>-1?1:0);t.focus();t.setSelectionRange(c,c);t.dispatchEvent(new Event("input",{bubbles:true}))})})()</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped parts and a fixed script.
	}

	/** Sheet settings (Settings > Chord sheet) and, when opened from a work, that work's numbers. Nothing private: the page is public. */
	public static function sheet_config() {
		$o   = wp_parse_args( (array) get_option( 'songpress_sheet', array() ), array( 'publisher' => '', 'logo_id' => 0 ) );
		$cfg = array(
			'publisher' => (string) $o['publisher'],
			'logo'      => $o['logo_id'] ? (string) wp_get_attachment_url( (int) $o['logo_id'] ) : '',
			'ccli'      => '',
			'iswc'      => '',
		);
		$id  = isset( $_GET['song'] ) ? absint( $_GET['song'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$tok = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$ids = array();
		$s   = null;
		if ( '' !== $tok && ! $id ) {
			// a shared link: the chart and header only, never the codes
			$s = Songpress_Songs::by_token( $tok );
		} elseif ( $id && is_user_logged_in() && Songpress_Caps::can_see_song( $id ) ) {
			$s   = Songpress_Songs::get( $id );
			$ids = array( $id );
			if ( $s && (int) $s->family_id ) {
				global $wpdb;
				$root = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Songpress_Install::table( 'songs' ) . ' WHERE family_id = %d AND version_no = 0 LIMIT 1', (int) $s->family_id ) );
				if ( $root && $root !== $id ) {
					$ids[] = $root; // codes entered on the original work apply to every version
				}
			}
		}
		if ( $s ) {
			$cfg['chart'] = (string) $s->chordpro;
			$cfg['title'] = (string) Songpress_Songs::display_title( $s );
			$cfg['by']    = str_replace( ', ', ' | ', (string) Songpress_Songs::authors_line( (int) $s->id, 0 ) );
			$cfg['key']   = (string) $s->musical_key;
			$cfg['tempo'] = (string) $s->tempo;
			$cfg['time']  = (string) $s->time_signature;
		}
		if ( $ids ) {
			foreach ( $ids as $sid ) {
				foreach ( (array) Songpress_Songs::identifiers( $sid ) as $r ) {
					$k = strtolower( (string) $r->kind );
					if ( ( 'ccli' === $k || 'iswc' === $k ) && '' === $cfg[ $k ] && '' !== trim( (string) $r->value ) ) {
						$cfg[ $k ] = trim( (string) $r->value );
					}
				}
			}
		}
		return $cfg;
	}

	public static function url() {
		return add_query_arg( 'sp_chordpro', '1', home_url( '/' ) );
	}

	public static function page() {
		if ( ! isset( $_GET['sp_chordpro'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		$css   = self::css();
		$embed = isset( $_GET['embed'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$sheetview = isset( $_GET['sheet'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$b   = function ( $act, $label, $cls = '' ) {
			return '<button type="button" class="ct-b ' . $cls . '" data-ct="' . esc_attr( $act ) . '">' . esc_html( $label ) . '</button>';
		};
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html__( 'ChordPro tool', 'songpress' ) . ' · ' . esc_html( get_bloginfo( 'name' ) ) . '</title><style id="ct-printcss">' . $css . '</style></head><body' . ( $embed ? ' class="ct-embed" data-embed="1"' : '' ) . ( $sheetview ? ' class="ct-sheetview" data-sheetview="1"' : '' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $sheetview ) {
			echo ( isset( $_GET['nobar'] ) ? '' : '<div class="ct-sv-bar"><button type="button" class="ct-b ct-go" id="ct-sheet-print">' . esc_html__( 'Print / Save PDF', 'songpress' ) . '</button><span class="ct-note">' . esc_html__( 'The sheet follows the ChordPro text above. Set the publisher line and logo in Settings > Chord sheet.', 'songpress' ) . '</span></div>' ) . '<div id="ct-sheetbox"><div id="ct-sheet"></div></div>';
		}
		echo '<header class="ct-top"><img src="' . esc_url( SONGPRESS_URL . 'assets/seal-amber.svg' ) . '" alt="" width="34" height="34"><h1>' . esc_html__( 'ChordPro tool', 'songpress' ) . '</h1><span class="ct-note">' . esc_html__( 'Works in your browser only. Nothing is saved on the server and it never touches the catalog.', 'songpress' ) . '</span></header>';
		if ( $embed ) {
			echo '<div class="ct-bar ct-usebar"><button type="button" class="ct-b ct-go" data-ct="usesong">' . esc_html__( 'Use in song', 'songpress' ) . '</button><button type="button" class="ct-b" data-ct="cancelsong">' . esc_html__( 'Cancel', 'songpress' ) . '</button><button type="button" class="ct-b" data-ct="direct">' . esc_html__( 'Type in the song form instead', 'songpress' ) . '</button><span class="ct-note">' . esc_html__( 'Your ChordPro text goes into the song when you press Use in song.', 'songpress' ) . '</span></div>';
		}
		echo '<div class="ct-bar">';
		echo $b( 'down', '− Transpose' ) . $b( 'up', 'Transpose +' ) . '<label><input type="checkbox" id="ct-flats"> ' . esc_html__( 'flats', 'songpress' ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<span class="ct-sep"></span>' . $b( 'chord', '[ ] Chord' ) . $b( 'verse', 'Verse' ) . $b( 'chorus', 'Chorus' ) . $b( 'bridge', 'Bridge' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<span class="ct-sep"></span>' . $b( 'convert', 'Chords over words → ChordPro' ) . $b( 'plain', 'Show as chords over words' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<span class="ct-sep"></span><label class="ct-b">' . esc_html__( 'Open file', 'songpress' ) . '<input type="file" id="ct-open" accept=".pro,.cho,.chordpro,.crd,.txt" hidden></label>' . $b( 'save', 'Save .pro' ) . $b( 'savetxt', 'Save .txt' ) . $b( 'savehtml', 'Save .html' ) . $b( 'print', 'Print / Save PDF' ) . $b( 'copy', 'Copy' ) . $b( 'sample', 'Example' ) . $b( 'clear', 'Clear' ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div><div class="ct-bar ct-sub"><label><input type="checkbox" id="ct-showdiag" checked> ' . esc_html__( 'Guitar chord diagrams', 'songpress' ) . '</label><label>' . esc_html__( 'Size', 'songpress' ) . ' <input type="range" id="ct-size" min="12" max="28" value="17"></label><label>' . esc_html__( 'Columns', 'songpress' ) . ' <select id="ct-cols"><option>1</option><option>2</option><option>3</option></select></label></div>';
		echo '<main class="ct-main"><section class="ct-edit"><textarea id="ct-text" spellcheck="false" aria-label="' . esc_attr__( 'ChordPro text', 'songpress' ) . '"></textarea><p class="ct-help">' . esc_html__( 'Write chords in square brackets before the word they fall on: [G]Walking down to the [D]water. Directives go in braces: {title: Name}, {key: G}, {start_of_chorus} … {end_of_chorus}, {comment: Slowly}.', 'songpress' ) . '</p>' . self::reference_html( 'ct-text' ) . '</section>';
		echo '<section class="ct-view"><div id="ct-preview" class="chart"></div><div id="ct-diagrams" class="ct-diag"></div></section></main>';
		echo '<div id="ct-plainbox" class="ct-modal" hidden><div><textarea id="ct-plain" readonly rows="18"></textarea><p>' . $b( 'closeplain', 'Close' ) . '</p></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<footer class="ct-foot">' . wp_kses_post( songpress_public_donate_link() ) . '</footer>';
		echo '<script>window.SP_SHEET=' . wp_json_encode( self::sheet_config() ) . ';</script>'; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON.
		echo '<script src="' . esc_url( SONGPRESS_URL . 'assets/chordtool.js?v=' . SONGPRESS_VERSION ) . '"></script></body></html>';
		exit;
	}

	private static function css() {
		return ':root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#141118;color:#ece7f0;font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}'
			. '.ct-top{display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid #2e2836;flex-wrap:wrap}.ct-top h1{margin:0;font-size:20px}.ct-note{color:#9a93a6;font-size:13px}'
			. '.ct-bar{display:flex;gap:6px;flex-wrap:wrap;align-items:center;padding:8px 18px}.ct-bar label{font-size:13px;color:#cfc8da}.ct-sep{width:1px;height:22px;background:#2e2836;margin:0 4px}'
			. '.ct-b{background:#221d29;color:#ece7f0;border:1px solid #3a3342;border-radius:8px;padding:6px 10px;font:inherit;font-size:13px;cursor:pointer}.ct-b:hover{border-color:#f5c04a;color:#f5c04a}'
			. '.ct-main{display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:8px 18px 24px}@media(max-width:860px){.ct-main{grid-template-columns:1fr}}'
			. '#ct-text{width:100%;min-height:62vh;background:#100e13;color:#ece7f0;border:1px solid #3a3342;border-radius:10px;padding:12px;font:14px/1.5 ui-monospace,Menlo,Consolas,monospace;resize:vertical}.ct-help{color:#9a93a6;font-size:12.5px}'
			. '.chart{background:#100e13;border:1px solid #2e2836;border-radius:10px;padding:16px 20px;font-size:17px;min-height:200px}.chart h1{margin:.1em 0;font-size:1.5em;color:#f5c04a}.chart .sub{margin:0 0 .6em;color:#b9b2c6}.meta{display:inline-block;margin:0 10px 8px 0;padding:1px 8px;border:1px solid #3a3342;border-radius:99px;font-size:.75em;color:#b9b2c6}'
			. '.cl{display:flex;flex-wrap:wrap;margin:0;align-items:flex-end}.cb{display:inline-flex;flex-direction:column;white-space:pre}.cb i{font-style:normal;font-weight:700;color:#f5c04a;font-size:.88em;min-height:1.3em}.cb b{font-weight:400}.gap{height:.7em}'
			. '.sec{margin:.6em 0;padding-left:.7em;border-left:3px solid #3a3342}.sec.chorus{border-left-color:#f5c04a;background:rgba(245,192,74,.05)}.sec h4{margin:.2em 0;font-size:.72em;letter-spacing:.08em;text-transform:uppercase;color:#9a93a6}.comment{margin:.5em 0;color:#9a93a6;font-style:italic}.tab{font:14px ui-monospace,Menlo,monospace;color:#ece7f0}'
			. '.ct-diag{display:flex;flex-wrap:wrap;gap:10px;margin-top:12px}.ct-diag figure{margin:0;background:#100e13;border:1px solid #2e2836;border-radius:8px;padding:4px}.ds{stroke:#6a6275;stroke-width:1}.ds.nut{stroke:#ece7f0;stroke-width:2}.dd{fill:#f5c04a}.do{fill:none;stroke:#ece7f0}.dx{fill:#9a93a6;font-size:9px}.dn{fill:#ece7f0;font-size:11px;font-weight:700}'
			. '.ct-modal{position:fixed;inset:0;background:rgba(0,0,0,.7);display:flex;align-items:center;justify-content:center;padding:18px}.ct-modal[hidden]{display:none}.ct-modal>div{background:#1b1721;border:1px solid #3a3342;border-radius:12px;padding:14px;width:min(720px,100%)}.ct-modal textarea{width:100%;background:#100e13;color:#ece7f0;border:1px solid #3a3342;border-radius:8px;font:14px ui-monospace,Menlo,monospace;padding:10px}'
			. '.ct-go{background:#f5c04a;color:#1b1406;border-color:#f5c04a;font-weight:700}.ct-embed .ct-top,.ct-embed .ct-foot{display:none}.ct-embed #ct-text{min-height:56vh}'
			. '.ct-foot{text-align:center;padding:12px;font-size:13px}.ct-foot a{color:#f5c04a}'
			. '.sp-cpref{margin:10px 0;font-size:13px}.sp-cpref summary{cursor:pointer;font-weight:600}.sp-cpref h4{margin:12px 0 4px;font-size:12px;text-transform:uppercase;letter-spacing:.06em;opacity:.7}.sp-cpref dl{margin:0}.sp-cpref dt{margin-top:6px}.sp-cpref dd{margin:2px 0 0;opacity:.8}.sp-cpref-hint{margin:6px 0;opacity:.7}.sp-cpref-ins{font:12px ui-monospace,Menlo,monospace;padding:3px 7px;border-radius:6px;border:1px solid #f5a52466;background:transparent;color:inherit;cursor:pointer}.sp-cpref-short{opacity:.8}#ct-sheet{display:none}#ct-sheet .sh-head{position:relative;padding-right:150pt}#ct-sheet .sh-logo{position:absolute;right:0;top:0;max-width:140pt;max-height:44pt;object-fit:contain}#ct-sheet .sh-head h1{font-size:19pt;margin:0 0 3pt;font-weight:700;color:#000}#ct-sheet .sh-by{margin:0 0 2pt;font-size:8.5pt;color:#333}#ct-sheet .sh-mt{margin:0 0 14pt;font-size:8.5pt;font-weight:700;color:#000}#ct-sheet .sh-cols{display:grid;grid-template-columns:1fr 1fr;column-gap:36pt;align-items:start}#ct-sheet .sh-col{min-width:0}#ct-sheet .sh-cols.sh-next{break-before:page;page-break-before:always;padding-top:0}#ct-sheet .sh-sec.sh-break{break-after:column}#ct-sheet .sh-sec{display:block;break-inside:avoid;margin:0 0 16pt}#ct-sheet .sh-sec h4{margin:0 0 4pt;font-size:8.5pt;font-weight:700;letter-spacing:0;color:#000}#ct-sheet .cl{display:flex;flex-wrap:wrap;align-items:flex-end;margin:0}#ct-sheet .cb{display:inline-flex;flex-direction:column;white-space:pre}#ct-sheet .cb i{font-style:normal;font-weight:700;color:#000;font-size:9.5pt;min-height:1.2em}#ct-sheet .cb i{padding-right:.55em}#ct-sheet .cb b{font-weight:400;font-size:9.5pt;color:#000}#ct-sheet .gap{height:5pt}#ct-sheet .sh-note{margin:2pt 0;font-size:8.5pt;font-style:italic}#ct-sheet .sh-foot{text-align:center;font-size:7.5pt;font-weight:700;color:#000}.ct-sheetview>*:not(.ct-sv-bar):not(#ct-sheetbox):not(script){display:none!important}.ct-sheetview{background:#2a2d33}.ct-sv-bar{display:flex;gap:10px;align-items:center;padding:8px 10px;flex-wrap:wrap}#ct-sheetbox{position:relative;margin:0 8px 12px;overflow:hidden}.ct-sheetview #ct-sheet{display:block;position:absolute;left:0;top:0;width:816px;min-height:1056px;box-sizing:border-box;padding:48px 58px 72px;background:#fff;color:#000;font:11pt/1.25 Arial,Helvetica,sans-serif;transform-origin:0 0;box-shadow:0 2px 12px rgba(0,0,0,.5)}.ct-sheetview #ct-sheet .sh-foot{position:absolute;left:0;right:0;bottom:22px}@media screen{body.ct-narrow #ct-sheet{position:relative!important;width:auto!important;min-height:0!important;padding:18px 16px 56px!important}body.ct-narrow #ct-sheet .sh-cols{column-count:1}body.ct-narrow #ct-sheet .sh-head{padding-right:0}body.ct-narrow #ct-sheet .sh-logo{position:static;display:block;margin:0 0 8px;max-height:40px}body.ct-narrow #ct-sheet .sh-foot{position:static!important;margin-top:18px}}@media print{body.ct-printing.ct-sheetview>#ct-sheetbox{display:block!important}.ct-sheetview{background:#fff}.ct-sv-bar{display:none!important}.ct-sheetview #ct-sheetbox{margin:0;height:auto!important;overflow:visible}.ct-sheetview #ct-sheet{position:static;width:auto;min-height:0;padding:0 0 .5in;box-shadow:none;transform:none!important}.ct-sheetview #ct-sheet .sh-foot{position:fixed;bottom:0}}@media print{@page{size:letter;margin:.5in .6in}body.ct-printing>*:not(#ct-sheet){display:none!important}body.ct-printing{background:#fff;color:#000;font:11pt/1.25 Arial,Helvetica,sans-serif}body.ct-printing #ct-sheet{display:block;color:#000;font-family:Arial,Helvetica,sans-serif;padding-bottom:.5in}#ct-sheet .sh-foot{position:fixed;left:0;right:0;bottom:0;text-align:center;font-size:7.5pt;font-weight:700;color:#000}}@media print{body{background:#fff;color:#000}.ct-top,.ct-bar,.ct-edit,.ct-foot,.ct-modal{display:none!important}.ct-main{display:block;padding:0}.chart{background:#fff;color:#000;border:0}.chart h1,.cb i{color:#000}.sec.chorus{background:none;border-left-color:#000}.ct-diag figure{background:#fff;border-color:#999}.ds{stroke:#444}.dd{fill:#000}.dn,.dx{fill:#000}.do{stroke:#000}}';
	}
}
