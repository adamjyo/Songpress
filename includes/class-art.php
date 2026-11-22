<?php
defined( 'ABSPATH' ) || exit;

/**
 * The Art studio, inside the SongPress app: layered cover art. A project is a list of layers (a canvas colour,
 * pictures, text, shapes) placed in percent of a square canvas. The picture is drawn in the browser; saving
 * keeps the layers, and "Make cover" renders the square, adds it to the Media Library and sets it as the song's art.
 * Managers only, on the desktop app.
 */
class Songpress_Art {

	const MAX_LAYERS = 80;
	const TYPES      = array( 'canvas', 'image', 'text', 'shape' );

	public static function init() {
		add_action( 'wp_ajax_songpress_art_save', array( __CLASS__, 'ajax_save' ) );
		add_action( 'admin_post_songpress_art_delete', array( __CLASS__, 'handle_delete' ) );
	}

	private static function t() {
		return Songpress_Install::table( 'art' );
	}

	/* ---------- layers (pure) ---------- */

	private static function num( $v, $min, $max, $d = 0 ) {
		return is_numeric( $v ) ? (float) max( $min, min( $max, round( (float) $v, 4 ) ) ) : (float) $d;
	}

	private static function color( $c, $d = '#000000' ) {
		$c = trim( (string) $c );
		return preg_match( '/^#[0-9a-fA-F]{3,8}$/', $c ) ? $c : $d;
	}

	/** One layer, from either the old studio's shape (settings under "meta") or ours. Always returns the flat, checked shape. */
	public static function norm_layer( $l ) {
		if ( ! is_array( $l ) ) {
			return null;
		}
		$type = in_array( $l['type'] ?? '', self::TYPES, true ) ? $l['type'] : '';
		if ( '' === $type ) {
			return null;
		}
		$m    = ( isset( $l['meta'] ) && is_array( $l['meta'] ) ) ? $l['meta'] : array();
		$get  = function ( $k, $d = '' ) use ( $l, $m ) {
			return $l[ $k ] ?? ( $m[ $k ] ?? $d );
		};
		$crop = ( isset( $l['crop'] ) && is_array( $l['crop'] ) ) ? $l['crop'] : array();
		$src  = esc_url_raw( (string) ( $l['src'] ?? '' ) );
		return array(
			'type'    => $type,
			'name'    => mb_substr( sanitize_text_field( (string) ( $l['name'] ?? ucfirst( $type ) ) ), 0, 80 ),
			'src'     => $src,
			'x'       => self::num( $get( 'x', 0 ), -400, 500, 0 ),
			'y'       => self::num( $get( 'y', 0 ), -400, 500, 0 ),
			'w'       => self::num( $get( 'w', 100 ), 0, 800, 100 ),
			'h'       => self::num( $get( 'h', 100 ), 0, 800, 100 ),
			'crop'    => array( 'x' => self::num( $crop['x'] ?? 0, 0, 1, 0 ), 'y' => self::num( $crop['y'] ?? 0, 0, 1, 0 ), 'w' => self::num( $crop['w'] ?? 1, 0.001, 1, 1 ), 'h' => self::num( $crop['h'] ?? 1, 0.001, 1, 1 ) ),
			'visible' => isset( $l['visible'] ) ? ( empty( $l['visible'] ) ? 0 : 1 ) : 1,
			'opacity' => self::num( $get( 'opacity', 1 ), 0, 1, 1 ),
			'rotate'  => self::num( $get( 'rotate', 0 ), -360, 360, 0 ),
			'text'    => mb_substr( sanitize_textarea_field( (string) $get( 'text', '' ) ), 0, 500 ),
			'font'    => mb_substr( sanitize_text_field( (string) $get( 'font', '' ) ), 0, 64 ),
			'size'    => self::num( $get( 'size', 32 ), 4, 400, 32 ),
			'color'   => self::color( $get( 'color', '#000000' ), 'canvas' === $type ? '#000000' : '#ffffff' ),
			'bold'    => empty( $get( 'bold', 0 ) ) ? 0 : 1,
			'italic'  => empty( $get( 'italic', 0 ) ) ? 0 : 1,
			'caps'    => empty( $get( 'caps', 0 ) ) ? 0 : 1,
			'align'   => in_array( $get( 'align', 'center' ), array( 'left', 'center', 'right' ), true ) ? $get( 'align', 'center' ) : 'center',
			'valign'  => in_array( $get( 'valign', 'middle' ), array( 'top', 'middle', 'bottom' ), true ) ? $get( 'valign', 'middle' ) : 'middle',
			'shadow'  => self::num( $get( 'shadow', 0 ), 0, 1, 0 ),
			'shape'   => 'ellipse' === $get( 'shape', '' ) ? 'ellipse' : 'rect',
			'flow'    => in_array( $get( 'flow', '' ), array( 'stack', 'turn' ), true ) ? $get( 'flow', '' ) : '',
		);
	}

	public static function norm_layers( $list ) {
		$out    = array();
		$canvas = array();
		foreach ( is_array( $list ) ? $list : array() as $l ) {
			$n = self::norm_layer( $l );
			if ( ! $n || ( 'image' === $n['type'] && '' === $n['src'] ) ) {
				continue;
			}
			// the old studio saved its canvas colour as the last row; it is the bottom of the stack
			if ( 'canvas' === $n['type'] ) {
				$canvas = array( $n );
			} else {
				$out[] = $n;
			}
		}
		return array_slice( array_merge( $canvas, $out ), 0, self::MAX_LAYERS );
	}

	/* ---------- import ---------- */

	/** Bring the old studio's projects across. Projects already here (by their old id) are left alone. */
	public static function import_pdo( PDO $pdo ) {
		global $wpdb;
		$q = $pdo->prepare( "SELECT 1 FROM sqlite_master WHERE type='table' AND name = 'art_projects'" );
		$q->execute();
		if ( ! $q->fetchColumn() ) {
			return 0;
		}
		$songs = Songpress_Install::table( 'songs' );
		$byser = array();
		foreach ( $wpdb->get_results( "SELECT id, serial FROM {$songs}" ) as $r ) { // phpcs:ignore WordPress.DB
			$byser[ $r->serial ] = (int) $r->id;
		}
		$t    = self::t();
		$have = array_flip( $wpdb->get_col( "SELECT source_key FROM {$t} WHERE source_key IS NOT NULL" ) ); // phpcs:ignore WordPress.DB
		$n    = 0;
		foreach ( $pdo->query( 'SELECT id, name, layers_json, preview, updated_at, serial FROM art_projects' ) as $r ) {
			$key = 'old:' . (int) $r['id'];
			if ( isset( $have[ $key ] ) ) {
				continue;
			}
			$layers = self::norm_layers( json_decode( (string) $r['layers_json'], true ) );
			$ser    = Songpress_Journal::serial_in( (string) ( $r['serial'] ?: $r['name'] ) );
			$when   = preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $r['updated_at'] ) ? substr( str_replace( 'T', ' ', $r['updated_at'] ), 0, 19 ) : current_time( 'mysql' );
			$wpdb->insert( $t, array(
				'song_id'     => ( '' !== $ser && isset( $byser[ $ser ] ) ) ? $byser[ $ser ] : 0,
				'name'        => mb_substr( (string) $r['name'], 0, 191 ),
				'layers'      => wp_json_encode( $layers ),
				'preview_url' => esc_url_raw( (string) $r['preview'] ),
				'source_key'  => $key,
				'user_id'     => 0,
				'created_at'  => $when,
				'updated_at'  => $when,
			) );
			$n++;
		}
		return $n;
	}

	/* ---------- data ---------- */

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	public static function all() {
		global $wpdb;
		$t = self::t();
		$s = Songpress_Install::table( 'songs' );
		return $wpdb->get_results( "SELECT a.id, a.name, a.song_id, a.preview_url, a.updated_at, s.serial FROM {$t} a LEFT JOIN {$s} s ON s.id = a.song_id ORDER BY a.updated_at DESC, a.id DESC LIMIT 500" ); // phpcs:ignore WordPress.DB
	}

	/* ---------- handlers ---------- */

	public static function ajax_save() {
		check_ajax_referer( 'songpress_art', 'nonce' );
		if ( ! Songpress_Caps::can( 'art' ) ) {
			wp_send_json_error( array( 'message' => __( 'Only Managers can use the Art studio.', 'songpress' ) ), 403 );
		}
		global $wpdb;
		$id     = absint( $_POST['id'] ?? 0 );
		$name   = mb_substr( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ), 0, 191 );
		$layers = self::norm_layers( json_decode( wp_unslash( $_POST['layers'] ?? '[]' ), true ) );
		$ser    = Songpress_Journal::serial_in( sanitize_text_field( wp_unslash( $_POST['serial'] ?? '' ) ) );
		$sid    = '' === $ser ? 0 : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Songpress_Install::table( 'songs' ) . ' WHERE serial = %s', $ser ) ); // phpcs:ignore WordPress.DB
		$row    = array( 'song_id' => $sid, 'name' => '' === $name ? __( 'Untitled art', 'songpress' ) : $name, 'layers' => wp_json_encode( $layers ), 'updated_at' => current_time( 'mysql' ) );
		$cover  = false;
		if ( ! empty( $_FILES['file']['tmp_name'] ) ) {
			if ( ! Songpress_Caps::can( 'upload' ) ) {
				wp_send_json_error( array( 'message' => __( 'You are not allowed to upload files.', 'songpress' ) ), 403 );
			}
			$check = wp_check_filetype_and_ext( $_FILES['file']['tmp_name'], $_FILES['file']['name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( 0 !== strpos( (string) $check['type'], 'image/' ) ) {
				wp_send_json_error( array( 'message' => __( 'The picture could not be read.', 'songpress' ) ), 415 );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			$att = media_handle_upload( 'file', 0 );
			if ( is_wp_error( $att ) ) {
				wp_send_json_error( array( 'message' => $att->get_error_message() ), 400 );
			}
			$row['preview_id']  = (int) $att;
			$row['preview_url'] = (string) wp_get_attachment_url( $att );
			if ( $sid && ! empty( $_POST['set_cover'] ) && Songpress_Caps::can_edit_song( $sid ) ) {
				Songpress_Songs::update( $sid, array( 'art_id' => (int) $att ) );
				$cover = true;
			}
		}
		if ( $id && self::get( $id ) ) {
			$wpdb->update( self::t(), $row, array( 'id' => $id ) );
		} else {
			$wpdb->insert( self::t(), array_merge( $row, array( 'user_id' => get_current_user_id(), 'created_at' => current_time( 'mysql' ) ) ) );
			$id = (int) $wpdb->insert_id;
		}
		wp_send_json_success( array( 'id' => $id, 'song_id' => $sid, 'preview_url' => $row['preview_url'] ?? '', 'cover' => $cover, 'url' => add_query_arg( array( 'sp_page' => 'art', 'art' => $id ), Songpress_Editor::list_url() ) ) );
	}

	public static function handle_delete() {
		check_admin_referer( 'songpress_art_delete' );
		if ( ! Songpress_Caps::can( 'art' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		global $wpdb;
		$wpdb->delete( self::t(), array( 'id' => absint( $_POST['art_id'] ?? 0 ) ) );
		$ret = isset( $_POST['sp_return'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['sp_return'] ) ), '' ) : '';
		wp_safe_redirect( add_query_arg( array( 'sp_page' => 'art', 'a_ok' => rawurlencode( __( 'Project removed.', 'songpress' ) ) ), '' !== $ret ? $ret : Songpress_Editor::list_url() ) );
		exit;
	}

	/* ---------- screens ---------- */

	public static function render() {
		if ( ! Songpress_Caps::can( 'art' ) ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'Only Managers can open this screen.', 'songpress' ) . '</p></div>';
		}
		if ( isset( $_GET['art'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return self::render_editor( 'new' === $_GET['art'] ? 0 : absint( $_GET['art'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		$base = Songpress_Editor::list_url();
		$o    = '<div class="songpress sp-app sp-editor sp-art"><div class="spe-top"><h2>' . esc_html__( 'Art studio', 'songpress' ) . '</h2> <a class="spe-btn" href="' . esc_url( add_query_arg( array( 'sp_page' => 'art', 'art' => 'new' ), $base ) ) . '">+ ' . esc_html__( 'New art', 'songpress' ) . '</a></div>';
		if ( ! empty( $_GET['a_ok'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<p class="spe-note ok">' . esc_html( sanitize_text_field( wp_unslash( $_GET['a_ok'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		$rows = self::all();
		if ( ! $rows ) {
			$o .= '<p class="spe-help">' . esc_html__( 'No art yet. Start a new one, or load your old catalog file on the Money page to bring your projects across.', 'songpress' ) . '</p>';
		}
		$o .= '<ul class="spa-list">';
		foreach ( $rows as $r ) {
			$o .= '<li><a class="spa-thumb" href="' . esc_url( add_query_arg( array( 'sp_page' => 'art', 'art' => (int) $r->id ), $base ) ) . '">' . ( '' !== $r->preview_url ? '<img src="' . esc_url( $r->preview_url ) . '" alt="" loading="lazy">' : '' ) . '</a><div><a href="' . esc_url( add_query_arg( array( 'sp_page' => 'art', 'art' => (int) $r->id ), $base ) ) . '"><b>' . esc_html( $r->name ) . '</b></a><br><span class="spe-help">' . esc_html( ( $r->serial ? $r->serial . ' · ' : '' ) . substr( (string) $r->updated_at, 0, 10 ) ) . '</span></div></li>';
		}
		return $o . '</ul></div>';
	}

	private static function render_editor( $id ) {
		$p = $id ? self::get( $id ) : null;
		if ( $id && ! $p ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'That art project does not exist.', 'songpress' ) . '</p></div>';
		}
		$serial = '';
		$sid    = $p ? (int) $p->song_id : ( isset( $_GET['song'] ) ? absint( $_GET['song'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $sid && ( $s = Songpress_Songs::get( $sid ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$serial = $s->serial;
		}
		$layers = $p ? self::norm_layers( json_decode( (string) $p->layers, true ) ) : array(
			array_merge( self::norm_layer( array( 'type' => 'canvas', 'name' => 'Canvas', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100 ) ), array( 'color' => '#14161a' ) ),
		);
		$cfg  = array(
			'id'     => (int) ( $p ? $p->id : 0 ),
			'name'   => $p ? $p->name : ( $s->title ?? '' ),
			'serial' => $serial,
			'layers' => $layers,
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'nonce'  => wp_create_nonce( 'songpress_art' ),
			'upload' => wp_create_nonce( 'songpress_upload' ),
			'list'   => add_query_arg( 'sp_page', 'art', Songpress_Editor::list_url() ),
		);
		$o  = '<div class="songpress sp-app sp-editor sp-art"><div class="spe-top"><h2>' . esc_html__( 'Art studio', 'songpress' ) . '</h2> <a class="spe-btn" href="' . esc_url( $cfg['list'] ) . '">' . esc_html__( 'All art', 'songpress' ) . '</a></div>';
		$o .= '<div class="spa-bar"><label class="spe-field"><span>' . esc_html__( 'Name', 'songpress' ) . '</span><input id="spa-name"></label><label class="spe-field"><span>' . esc_html__( 'Song serial (to set its cover)', 'songpress' ) . '</span><input id="spa-serial" placeholder="0378"></label><label class="spe-field"><span>' . esc_html__( 'Export size', 'songpress' ) . '</span><select id="spa-size"><option value="1000">1000 px</option><option value="2000">2000 px</option><option value="3000" selected>3000 px</option></select></label></div>';
		$o .= '<div class="spa-main"><div class="spa-stage"><div class="spa-square" id="spa-square"><canvas id="spa-canvas" width="1000" height="1000"></canvas><div id="spa-sel" hidden><i id="spa-handle"></i></div></div><p class="spe-help" id="spa-msg"></p></div>';
		$o .= '<div class="spa-side"><div class="spa-add"><button type="button" class="spe-btn" data-add="text">+ ' . esc_html__( 'Text', 'songpress' ) . '</button><button type="button" class="spe-btn" data-add="rect">+ ' . esc_html__( 'Box', 'songpress' ) . '</button><button type="button" class="spe-btn" data-add="ellipse">+ ' . esc_html__( 'Circle', 'songpress' ) . '</button><label class="spe-btn">+ ' . esc_html__( 'Picture', 'songpress' ) . '<input type="file" id="spa-file" accept="image/*" hidden></label></div><ul id="spa-layers" class="spa-layers"></ul><div id="spa-props" class="spa-props"></div></div></div>';
		$o .= '<div class="spa-actions"><button type="button" class="spe-save" id="spa-save">' . esc_html__( 'Save project', 'songpress' ) . '</button> <button type="button" class="spe-btn" id="spa-cover">' . esc_html__( 'Save and make cover', 'songpress' ) . '</button> <button type="button" class="spe-btn" id="spa-down">' . esc_html__( 'Download PNG', 'songpress' ) . '</button>';
		if ( $p ) {
			$o .= ' <form method="post" class="spa-del" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_art_delete', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_art_delete"><input type="hidden" name="art_id" value="' . (int) $p->id . '"><input type="hidden" name="sp_return" value="' . esc_attr( Songpress_Editor::list_url() ) . '"><button class="spe-btn danger" type="submit">' . esc_html__( 'Delete project', 'songpress' ) . '</button></form>';
		}
		$o .= '</div><script type="application/json" id="spa-cfg">' . wp_json_encode( $cfg, JSON_HEX_TAG | JSON_HEX_AMP ) . '</script><script>' . self::js() . '</script></div>';
		return $o;
	}

	private static function js() {
		return <<<'JS'
(function(){
var C=JSON.parse(document.getElementById('spa-cfg').textContent);
var L=C.layers,sel=L.length>1?L.length-1:0,id=C.id,imgs={},REF=400,dirty=false;
var cv=document.getElementById('spa-canvas'),sq=document.getElementById('spa-square'),box=document.getElementById('spa-sel'),hd=document.getElementById('spa-handle'),msg=document.getElementById('spa-msg');
var SYS=/^(sans-serif|serif|system-ui|monospace|cursive|fantasy|impact|arial|helvetica|georgia|times new roman|verdana|courier new|tahoma|trebuchet ms)$/i;
function $(i){return document.getElementById(i)}
$('spa-name').value=C.name||'';$('spa-serial').value=C.serial||'';
function say(t){msg.textContent=t||''}
function esc(s){return String(s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
function fontCss(n){n=n||'sans-serif';return SYS.test(n)&&/^(sans-serif|serif|system-ui|monospace|cursive|fantasy)$/i.test(n)?n:'"'+n.replace(/"/g,'')+'"'}
var fontsAsked={};
function needFonts(){L.forEach(function(l){if(l.type!=='text'||!l.font||SYS.test(l.font)||fontsAsked[l.font])return;fontsAsked[l.font]=1;var k=document.createElement('link');k.rel='stylesheet';k.href='https://fonts.googleapis.com/css2?family='+encodeURIComponent(l.font).replace(/%20/g,'+')+'&display=swap';document.head.appendChild(k);if(document.fonts&&document.fonts.load){document.fonts.load('16px "'+l.font+'"').then(function(){draw()},function(){})}})}
function image(src,cb){if(imgs[src]){return imgs[src]}var im=new Image();im.crossOrigin='anonymous';imgs[src]={img:im,ok:false};im.onload=function(){imgs[src].ok=true;cb&&cb()};im.onerror=function(){var i2=new Image();i2.onload=function(){imgs[src]={img:i2,ok:true,tainted:true};cb&&cb()};i2.src=src};im.src=src;return imgs[src]}
function paint(ctx,S){
 var px=S/REF;
 L.forEach(function(l){
  if(!l.visible)return;var x=l.x/100*S,y=l.y/100*S,w=l.w/100*S,h=l.h/100*S;
  ctx.save();ctx.globalAlpha=l.opacity;
  if(l.rotate){ctx.translate(x+w/2,y+h/2);ctx.rotate(l.rotate*Math.PI/180);ctx.translate(-(x+w/2),-(y+h/2))}
  if(l.type==='canvas'){ctx.fillStyle=l.color||'#000';ctx.fillRect(0,0,S,S)}
  else if(l.type==='image'&&l.src){var r=image(l.src,draw);if(r.ok){var n=r.img,c=l.crop;ctx.drawImage(n,c.x*n.naturalWidth,c.y*n.naturalHeight,c.w*n.naturalWidth,c.h*n.naturalHeight,x,y,w,h)}}
  else if(l.type==='shape'){ctx.fillStyle=l.color||'#fff';if(l.shape==='ellipse'){ctx.beginPath();ctx.ellipse(x+w/2,y+h/2,Math.max(1,w/2),Math.max(1,h/2),0,0,Math.PI*2);ctx.fill()}else{ctx.fillRect(x,y,w,h)}}
  else if(l.type==='text'){
   ctx.fillStyle=l.color||'#fff';ctx.textAlign=l.align;ctx.textBaseline=l.valign==='top'?'top':(l.valign==='bottom'?'bottom':'middle');
   var fs=Math.max(8,l.size*px);ctx.font=(l.italic?'italic ':'')+(l.bold?'700 ':'400 ')+fs+'px '+fontCss(l.font);
   if(l.shadow){ctx.shadowColor='rgba(0,0,0,'+(0.35+l.shadow*0.55)+')';ctx.shadowBlur=(2+l.shadow*14)*px;ctx.shadowOffsetY=(1+l.shadow*3)*px}
   var t=l.caps?String(l.text).toUpperCase():String(l.text),lines=t.split('\n'),step=fs*1.15,tx=l.align==='left'?x:(l.align==='right'?x+w:x+w/2);
   var total=step*lines.length,top=l.valign==='top'?y:(l.valign==='bottom'?y+h-total:y+(h-total)/2);
   if(l.flow==='stack'){var colW=fs*1.15,tw=Math.max(colW,lines.length*colW),ox=l.align==='left'?x+colW/2:(l.align==='right'?x+w-tw+colW/2:x+(w-tw)/2+colW/2);ctx.textAlign='center';ctx.textBaseline='middle';
    lines.forEach(function(col,ci){var chars=Array.from(col),th=Math.max(step,chars.length*step),sy=l.valign==='top'?y+step/2:(l.valign==='bottom'?y+h-th+step/2:y+(h-th)/2+step/2);chars.forEach(function(ch,n){ctx.fillText(ch,ox+ci*colW,sy+n*step)})})}
   else if(l.flow==='turn'){ctx.translate(x+w/2,y+h/2);ctx.rotate(Math.PI/2);ctx.textAlign=l.align;ctx.textBaseline='middle';ctx.fillText(t,0,0)}
   else lines.forEach(function(s,i){var ty=l.valign==='top'?top+i*step:(l.valign==='bottom'?top+i*step+step:top+i*step+step/2);ctx.fillText(s,tx,ty)});
  }
  ctx.restore();
 });
}
function draw(){var ctx=cv.getContext('2d');ctx.clearRect(0,0,1000,1000);paint(ctx,1000);place()}
function place(){var l=L[sel];if(!l||l.type==='canvas'){box.hidden=true;return}box.hidden=false;box.style.left=l.x+'%';box.style.top=l.y+'%';box.style.width=l.w+'%';box.style.height=l.h+'%';box.style.transform=l.rotate?'rotate('+l.rotate+'deg)':''}
function rail(){
 var ul=$('spa-layers');ul.innerHTML='';
 for(var i=L.length-1;i>=0;i--){(function(i){var l=L[i],li=document.createElement('li');li.className=i===sel?'on':'';
  li.innerHTML='<button type="button" class="spa-eye" title="Show or hide">'+(l.visible?'●':'○')+'</button><span class="spa-nm">'+esc(l.type==='text'&&l.text?l.text.split('\n')[0]:l.name||l.type)+'</span><button type="button" data-a="up" title="Forward">↑</button><button type="button" data-a="dn" title="Back">↓</button><button type="button" data-a="dup" title="Duplicate">⧉</button><button type="button" data-a="del" title="Delete">✕</button>';
  li.onclick=function(e){var a=e.target.getAttribute('data-a');
   if(e.target.classList.contains('spa-eye')){l.visible=l.visible?0:1;dirty=true}
   else if(a==='up'&&i<L.length-1){L.splice(i+1,0,L.splice(i,1)[0]);sel=i+1;dirty=true}
   else if(a==='dn'&&i>0){L.splice(i-1,0,L.splice(i,1)[0]);sel=i-1;dirty=true}
   else if(a==='dup'){var c=JSON.parse(JSON.stringify(l));c.x+=3;c.y+=3;L.splice(i+1,0,c);sel=i+1;dirty=true}
   else if(a==='del'){if(L.length>0){L.splice(i,1);sel=Math.max(0,Math.min(sel,L.length-1));dirty=true}}
   else{sel=i}
   all()};
  ul.appendChild(li)})(i)}
}
function fld(label,key,type,extra){var l=L[sel];return '<label class="spe-field"><span>'+label+'</span><input data-k="'+key+'" type="'+(type||'number')+'" '+(extra||'')+' value="'+esc(l[key]==null?'':l[key])+'"></label>'}
function props(){
 var l=L[sel],p=$('spa-props');if(!l){p.innerHTML='';return}
 var h='';
 if(l.type==='canvas'){h=fld('Canvas colour','color','color')}
 else{
  h='<div class="spa-grid">'+fld('X %','x','number','step="0.5"')+fld('Y %','y','number','step="0.5"')+fld('Width %','w','number','step="0.5"')+fld('Height %','h','number','step="0.5"')+fld('Opacity','opacity','number','min="0" max="1" step="0.05"')+fld('Rotate °','rotate','number','step="1"')+'</div>';
  if(l.type==='text'){h+='<label class="spe-field"><span>Text</span><textarea data-k="text" rows="3">'+esc(l.text)+'</textarea></label><div class="spa-grid">'+fld('Font','font','text','list="spa-fonts" placeholder="Impact, Rubik Dirt…"')+fld('Size','size','number','min="4" step="1"')+fld('Colour','color','color')+fld('Shadow','shadow','number','min="0" max="1" step="0.1"')+'</div><div class="spa-grid"><label class="spe-field"><span>Align</span><select data-k="align"><option>left</option><option>center</option><option>right</option></select></label><label class="spe-field"><span>Direction</span><select data-k="flow"><option value="">across</option><option value="stack">stacked letters</option><option value="turn">turned</option></select></label><label class="spe-field"><span>Vertical</span><select data-k="valign"><option>top</option><option>middle</option><option>bottom</option></select></label></div><p class="spa-chk"><label><input type="checkbox" data-k="bold"> Bold</label> <label><input type="checkbox" data-k="italic"> Italic</label> <label><input type="checkbox" data-k="caps"> Capitals</label></p><datalist id="spa-fonts"><option>Impact<option>Arial<option>Georgia<option>Rubik Dirt<option>Playfair Display<option>Bebas Neue<option>Oswald<option>Pacifico<option>Lobster<option>Special Elite<option>Permanent Marker</datalist>'}
  if(l.type==='shape'){h+=fld('Colour','color','color')}
  if(l.type==='image'){h+='<p class="spe-help">Picture layers are stretched to the box. Crop is kept from the old studio.</p>'}
 }
 p.innerHTML=h;
 p.querySelectorAll('[data-k]').forEach(function(e){var k=e.getAttribute('data-k');if(e.type==='checkbox'){e.checked=!!l[k]}else if(e.tagName==='SELECT'){e.value=l[k]}
  e.addEventListener('input',function(){var v=e.type==='checkbox'?(e.checked?1:0):(e.type==='number'?parseFloat(e.value):e.value);if(e.type==='number'&&isNaN(v))return;l[k]=v;dirty=true;if(k==='font')needFonts();draw();var nm=document.querySelector('#spa-layers li.on .spa-nm');if(k==='text'&&nm)nm.textContent=String(v).split('\n')[0]})});
}
function all(){draw();rail();props();needFonts()}
document.querySelectorAll('[data-add]').forEach(function(b){b.onclick=function(){var t=b.getAttribute('data-add');
 var n=t==='text'?{type:'text',name:'Text',x:10,y:40,w:80,h:18,text:'Your words',font:'Impact',size:60,color:'#ffffff',align:'center',valign:'middle'}:{type:'shape',name:t==='rect'?'Box':'Circle',x:30,y:30,w:40,h:40,color:'#f5a623',shape:t==='ellipse'?'ellipse':'rect'};
 L.push(Object.assign({src:'',crop:{x:0,y:0,w:1,h:1},visible:1,opacity:1,rotate:0,text:'',font:'',size:32,color:'#ffffff',bold:0,italic:0,caps:0,align:'center',valign:'middle',shadow:0,shape:'rect'},n));sel=L.length-1;dirty=true;all()}});
$('spa-file').onchange=function(e){var f=e.target.files[0];if(!f)return;say('Uploading…');var fd=new FormData();fd.append('action','songpress_upload');fd.append('nonce',C.upload);fd.append('file',f);
 fetch(C.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){if(!j.success){say((j.data&&j.data.message)||'Upload failed');return}
  var im=new Image();im.onload=function(){var ar=im.naturalWidth/im.naturalHeight,w=50,h=w/ar;L.push({type:'image',name:f.name.replace(/\.[^.]+$/,''),src:j.data.url,x:25,y:25,w:w,h:h,crop:{x:0,y:0,w:1,h:1},visible:1,opacity:1,rotate:0,text:'',font:'',size:32,color:'#ffffff',bold:0,italic:0,caps:0,align:'center',valign:'middle',shadow:0,shape:'rect'});sel=L.length-1;dirty=true;say('');all()};im.src=j.data.url}).catch(function(){say('Upload failed')});e.target.value=''};
/* drag to move, corner to resize */
var drag=null;
function pt(e){var r=sq.getBoundingClientRect();return{x:(e.clientX-r.left)/r.width*100,y:(e.clientY-r.top)/r.height*100}}
sq.addEventListener('pointerdown',function(e){var p=pt(e);
 if(e.target===hd){drag={m:'size',p:p,l:L[sel],o:{w:L[sel].w,h:L[sel].h}};sq.setPointerCapture(e.pointerId);e.preventDefault();return}
 var hit=-1;for(var i=L.length-1;i>=0;i--){var l=L[i];if(l.type==='canvas'||!l.visible)continue;if(p.x>=l.x&&p.x<=l.x+l.w&&p.y>=l.y&&p.y<=l.y+l.h){hit=i;break}}
 if(hit>=0){sel=hit;drag={m:'move',p:p,l:L[sel],o:{x:L[sel].x,y:L[sel].y}};sq.setPointerCapture(e.pointerId);rail();props();draw()}
});
sq.addEventListener('pointermove',function(e){if(!drag)return;var p=pt(e),dx=p.x-drag.p.x,dy=p.y-drag.p.y,l=drag.l;
 if(drag.m==='move'){l.x=Math.round((drag.o.x+dx)*10)/10;l.y=Math.round((drag.o.y+dy)*10)/10}else{l.w=Math.max(1,Math.round((drag.o.w+dx)*10)/10);l.h=Math.max(1,Math.round((drag.o.h+dy)*10)/10)}
 dirty=true;draw()});
function end(){if(drag){drag=null;props()}}sq.addEventListener('pointerup',end);sq.addEventListener('pointercancel',end);
/* output */
function render(size){var c=document.createElement('canvas');c.width=c.height=size;paint(c.getContext('2d'),size);return c}
function blob(size){return new Promise(function(res,rej){var c=render(size);try{c.toBlob(function(b){b?res(b):rej(new Error('x'))},'image/png')}catch(e){rej(e)}})}
function save(cover){
 var fd=new FormData();fd.append('action','songpress_art_save');fd.append('nonce',C.nonce);fd.append('id',id);fd.append('name',$('spa-name').value);fd.append('serial',$('spa-serial').value);fd.append('layers',JSON.stringify(L));
 say(cover?'Rendering the cover…':'Saving…');
 var go=function(b){if(b){fd.append('file',b,'cover-'+Date.now()+'.png');if(cover)fd.append('set_cover','1')}
  fetch(C.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){if(!j.success){say((j.data&&j.data.message)||'Could not save');return}
   id=j.data.id;dirty=false;say(cover?(j.data.cover?'Saved, and set as the song\'s cover.':'Saved. Add the song\'s serial to set it as the cover.'):'Saved.');if(!C.id){C.id=id;history.replaceState(null,'',j.data.url)}}).catch(function(){say('Could not save')})};
 var w=Math.min(3000,parseInt($('spa-size').value,10)||3000);
 blob(cover?w:1000).then(go,function(){say('A picture on this project could not be copied into the cover (it is hosted elsewhere). Save the project without the cover, or upload that picture here.')});
}
$('spa-save').onclick=function(){save(false)};
$('spa-cover').onclick=function(){save(true)};
$('spa-down').onclick=function(){blob(parseInt($('spa-size').value,10)||3000).then(function(b){var a=document.createElement('a');a.href=URL.createObjectURL(b);a.download=(($('spa-name').value||'cover').replace(/[^\w.-]+/g,'-'))+'.png';document.body.appendChild(a);a.click();setTimeout(function(){URL.revokeObjectURL(a.href);a.remove()},500)},function(){say('A picture on this project is hosted elsewhere and cannot be exported.')})};
window.addEventListener('beforeunload',function(e){if(dirty){e.preventDefault();e.returnValue=''}});
all();
})();
JS;
	}
}
