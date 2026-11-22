<?php
defined( 'ABSPATH' ) || exit;

/**
 * Name check for songs, bands, labels and publishers. While you type a name it warns if the same or a very similar one is
 * already in your catalog, and for the names of acts it offers quick searches (Google, USPTO trademarks, Spotify, Bandcamp)
 * so you can see whether somebody else already uses it. It is a prompt to look, not a legal clearance.
 */
class Songpress_Names {

	public static function init() {
		add_action( 'admin_post_songpress_name_check', array( __CLASS__, 'handle_check' ) );
	}

	/** Pure: a name reduced for comparing: no case, accents, spaces, punctuation or leading "the". */
	public static function normalize( $s ) {
		$s = strtolower( remove_accents( (string) $s ) );
		$s = preg_replace( '/^\s*(the|a|an)\s+/', '', $s );
		return preg_replace( '/[^a-z0-9]+/', '', $s );
	}

	/** Pure: 'same', 'close' or '' for two names. Close means a typo or two apart, on names long enough for that to mean something. */
	public static function compare( $a, $b ) {
		$x = self::normalize( $a );
		$y = self::normalize( $b );
		if ( '' === $x || '' === $y ) {
			return '';
		}
		if ( $x === $y ) {
			return 'same';
		}
		$len = max( strlen( $x ), strlen( $y ) );
		if ( $len < 5 || abs( strlen( $x ) - strlen( $y ) ) > 2 ) {
			return '';
		}
		$max = $len < 10 ? 1 : 2;
		return levenshtein( substr( $x, 0, 250 ), substr( $y, 0, 250 ) ) <= $max ? 'close' : '';
	}

	/**
	 * Names already in the catalog that match. $kind is song, band, label, publisher or artist.
	 * $exclude is what to leave out: for a song its family id, for the rest the id being edited.
	 * Returns up to six of array( name, level, where, url ), the same ones first.
	 */
	public static function matches( $kind, $name, $exclude = 0 ) {
		global $wpdb;
		$found = array();
		$name  = trim( (string) $name );
		if ( self::normalize( $name ) === '' ) {
			return $found;
		}
		$add = function ( $cand, $where, $url = '' ) use ( &$found, $name ) {
			$lvl = self::compare( $name, $cand );
			if ( '' !== $lvl ) {
				$found[] = array( 'name' => (string) $cand, 'level' => $lvl, 'where' => $where, 'url' => $url );
			}
		};
		if ( 'song' === $kind ) {
			$seen = array();
			foreach ( (array) $wpdb->get_results( 'SELECT id, family_id, serial, title FROM ' . Songpress_Install::table( 'songs' ) . ' WHERE version_no = 0' ) as $r ) { // phpcs:ignore WordPress.DB
				if ( (int) $r->family_id === (int) $exclude || isset( $seen[ $r->family_id ] ) ) {
					continue;
				}
				$seen[ $r->family_id ] = 1;
				$add( $r->title, 'song ' . $r->serial, Songpress_Editor::edit_url( (int) $r->id ) );
			}
		} else {
			$pk = 'artist' === $kind ? 'band' : $kind;
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, name FROM ' . Songpress_Install::table( 'profiles' ) . ' WHERE kind = %s', $pk ) ) as $r ) { // phpcs:ignore WordPress.DB
				if ( 'artist' !== $kind && (int) $r->id === (int) $exclude ) {
					continue;
				}
				$add( $r->name, $pk );
			}
			if ( in_array( $kind, array( 'band', 'artist', 'label' ), true ) ) {
				foreach ( (array) $wpdb->get_results( 'SELECT id, name FROM ' . Songpress_Install::table( 'artists' ) ) as $r ) { // phpcs:ignore WordPress.DB
					if ( 'artist' === $kind && (int) $r->id === (int) $exclude ) {
						continue;
					}
					$add( $r->name, 'artist' );
				}
			}
		}
		usort( $found, function ( $a, $b ) { return strcmp( $a['level'], $b['level'] ) * -1; } ); // 'same' sorts before 'close'
		return array_slice( $found, 0, 6 );
	}

	public static function handle_check() {
		if ( ! is_user_logged_in() ) {
			wp_send_json( array( 'matches' => array() ), 403 );
		}
		check_ajax_referer( 'songpress_name_check', '_wpnonce' );
		$kind = sanitize_key( wp_unslash( $_GET['kind'] ?? '' ) );
		if ( ! in_array( $kind, array( 'song', 'band', 'label', 'publisher', 'artist' ), true ) ) {
			wp_send_json( array( 'matches' => array() ) );
		}
		wp_send_json( array( 'matches' => self::matches( $kind, sanitize_text_field( wp_unslash( $_GET['name'] ?? '' ) ), absint( $_GET['exclude'] ?? 0 ) ) ) );
	}

	/** Attributes for an input so the page script watches it. */
	public static function attrs( $kind, $exclude = 0 ) {
		return ' data-sp-namecheck="' . esc_attr( $kind ) . '" data-sp-exclude="' . (int) $exclude . '"';
	}

	/** The script, printed once per page. Put it right after the first watched input. */
	public static function script() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		$cfg  = wp_json_encode( array( 'url' => admin_url( 'admin-post.php' ), 'nonce' => wp_create_nonce( 'songpress_name_check' ) ), JSON_HEX_TAG | JSON_HEX_AMP );
		return '<script>(function(){var C=' . $cfg . ';' . <<<'JS'
function box(i){var b=i.nextElementSibling;if(!b||!b.classList.contains('sp-namecheck')){b=document.createElement('div');b.className='sp-namecheck';b.setAttribute('aria-live','polite');i.parentNode.insertBefore(b,i.nextSibling)}return b}
function esc(s){return String(s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function links(n){var e=encodeURIComponent;return '<span class="sp-nc-links">Look it up: '
 +'<a target="_blank" rel="noopener" href="https://www.google.com/search?q='+e('"'+n+'" trademark')+'">Google</a> · '
 +'<a target="_blank" rel="noopener" href="https://tmsearch.uspto.gov/search/search-information" data-copy="'+esc(n)+'">USPTO trademarks (copies the name)</a> · '
 +'<a target="_blank" rel="noopener" href="https://open.spotify.com/search/'+e(n)+'">Spotify</a> · '
 +'<a target="_blank" rel="noopener" href="https://bandcamp.com/search?q='+e(n)+'">Bandcamp</a></span>'}
function watch(i){
 var kind=i.getAttribute('data-sp-namecheck'),ex=i.getAttribute('data-sp-exclude')||'0',t=null,seq=0,b=box(i),act=kind!=='song';
 function run(){
  var n=i.value.trim();
  if(n.length<3){b.innerHTML='';return}
  var my=++seq;
  fetch(C.url+'?action=songpress_name_check&_wpnonce='+C.nonce+'&kind='+kind+'&exclude='+ex+'&name='+encodeURIComponent(n),{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(d){
   if(my!==seq)return;var h='';
   (d.matches||[]).forEach(function(m){h+='<div class="sp-nc-'+m.level+'">'+(m.level==='same'?'Already here: ':'Looks close to: ')+(m.url?'<a href="'+esc(m.url)+'">'+esc(m.name)+'</a>':esc(m.name))+' <small>('+esc(m.where)+')</small></div>'});
   b.innerHTML=h+(act?links(n):'')}).catch(function(){b.innerHTML=act?links(n):''})}
 i.addEventListener('input',function(){clearTimeout(t);t=setTimeout(run,450)});
 if(i.value.trim().length>=3&&act){b.innerHTML=links(i.value.trim())}
}
document.addEventListener('click',function(e){var a=e.target.closest&&e.target.closest('[data-copy]');if(a&&navigator.clipboard){navigator.clipboard.writeText(a.getAttribute('data-copy')).catch(function(){})}});
Array.prototype.forEach.call(document.querySelectorAll('[data-sp-namecheck]'),watch);
JS
. '})();</script>';
	}
}
