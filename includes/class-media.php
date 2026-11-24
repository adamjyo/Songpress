<?php
defined( 'ABSPATH' ) || exit;

/**
 * All cover art and audio live in the WordPress Media Library. A song stores the attachment id (art_id, audio_id),
 * so renaming or moving the file in the library never breaks it. The old URL columns are kept only as a fallback,
 * for links that could not be matched to a library item.
 */
class Songpress_Media {
	/** Library item id for a URL, or 0 when the URL is not in this site's Media Library. */
	public static function id_from_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return 0;
		}
		$id = (int) attachment_url_to_postid( $url );
		if ( ! $id ) {
			// A resized copy such as photo-300x300.jpg is not found directly: try the original name.
			$full = preg_replace( '/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', $url );
			$id   = $full !== $url ? (int) attachment_url_to_postid( $full ) : 0;
		}
		return $id;
	}

	public static function art_url( $s, $size = 'medium' ) {
		if ( ! empty( $s->art_id ) ) {
			$u = (string) wp_get_attachment_image_url( (int) $s->art_id, $size );
			if ( '' !== $u ) {
				return $u;
			}
		}
		return (string) $s->art_url;
	}

	public static function audio_url( $s ) {
		if ( ! empty( $s->audio_id ) ) {
			$u = (string) wp_get_attachment_url( (int) $s->audio_id );
			if ( '' !== $u ) {
				return $u;
			}
		}
		return (string) $s->audio_url;
	}

	/** Which field a file belongs in, from its type: images are cover art, audio is the audio file. Anything else is refused. */
	public static function field_for_mime( $mime ) {
		$mime = strtolower( (string) $mime );
		if ( 0 === strpos( $mime, 'image/' ) && 'image/svg+xml' !== $mime ) {
			return 'art';
		}
		if ( 0 === strpos( $mime, 'audio/' ) ) {
			return 'audio';
		}
		return '';
	}

	/** WordPress would save the picture embedded in an audio file as a second Media Library item. Leave it out: only the audio and its name are kept. Pure. */
	public static function drop_embedded_art( $metadata ) {
		if ( is_array( $metadata ) ) {
			unset( $metadata['image'] );
		}
		return $metadata;
	}

	/** Like media_handle_upload( $field, 0 ), but an audio file's embedded cover picture is not turned into its own attachment. */
	public static function upload_without_cover( $field ) {
		add_filter( 'wp_read_audio_metadata', array( __CLASS__, 'drop_embedded_art' ), 99 );
		$id = media_handle_upload( $field, 0 );
		remove_filter( 'wp_read_audio_metadata', array( __CLASS__, 'drop_embedded_art' ), 99 );
		return $id;
	}

	/** Ajax: a dropped file is saved into the Media Library like any upload, and its id comes back for the form. */
	public static function ajax_upload() {
		check_ajax_referer( 'songpress_upload', 'nonce' );
		Songpress_Uploads::watch( 'file upload' ); // a fatal is written down and shown on the Upload / Download page
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_raise_memory_limit( 'image' );
		if ( ! Songpress_Caps::can( 'upload' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to upload files.', 'songpress' ) ), 403 );
		}
		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file arrived.', 'songpress' ) ), 400 );
		}
		$check = wp_check_filetype_and_ext( $_FILES['file']['tmp_name'], $_FILES['file']['name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$field = self::field_for_mime( $check['type'] ?: '' );
		if ( '' === $field ) {
			wp_send_json_error( array( 'message' => __( 'Only images (cover art) and audio files can be added here.', 'songpress' ) ), 415 );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$id = self::upload_without_cover( 'file' );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ), 400 );
		}
		wp_send_json_success( array(
			'field' => $field,
			'id'    => (int) $id,
			'thumb' => 'art' === $field ? (string) wp_get_attachment_image_url( $id, 'thumbnail' ) : '',
			'url'   => (string) wp_get_attachment_url( $id ),
			'name'  => wp_basename( (string) wp_get_attachment_url( $id ) ),
		) );
	}

	/** The picker row for the song editor: a button that opens the Media Library, a preview and a clear button. */
	/** True while a management screen is drawn inside the front-end app: pickers upload instead of opening the Media Library window. */
	public static $front = false;

	public static function picker( $field, $label, $type, $id, $url ) {
		$src = $id ? ( 'image' === $type ? (string) wp_get_attachment_image_url( (int) $id, 'thumbnail' ) : (string) wp_get_attachment_url( (int) $id ) ) : '';
		$h   = '<div class="sp-media" data-type="' . esc_attr( $type ) . '" data-field="' . esc_attr( $field ) . '">';
		$h  .= '<input type="hidden" name="' . esc_attr( $field ) . '_id" value="' . (int) $id . '"><input type="hidden" class="sp-media-clearflag" name="' . esc_attr( $field ) . '_clear" value="0">';
		$h  .= '<div class="sp-media-preview">' . ( $id && 'image' === $type && '' !== $src ? '<img src="' . esc_url( $src ) . '" alt="" style="max-width:120px;height:auto;display:block;margin-bottom:6px">' : ( $id && '' !== $src ? esc_html( wp_basename( $src ) ) : '' ) ) . '</div>';
		$pick = self::$front
			? '<label class="button spe-btn">' . esc_html__( 'Upload', 'songpress' ) . '<input type="file" accept="' . ( 'image' === $type ? 'image/*' : 'audio/*' ) . '" hidden></label>'
			: '<button type="button" class="button sp-media-pick">' . esc_html( $label ) . '</button>';
		$h  .= $pick . ' <button type="button" class="button-link sp-media-clear"' . ( $id ? '' : ' hidden' ) . '>' . esc_html__( 'Remove', 'songpress' ) . '</button>';
		if ( ! $id && '' !== (string) $url ) {
			$h .= '<p class="sp-help">' . esc_html__( 'Linked from outside the Media Library:', 'songpress' ) . ' ' . esc_html( $url ) . '</p>';
		}
		return $h . '</div>';
	}

	public static function picker_script() {
		if ( self::$front ) {
			$cfg = wp_json_encode( array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'songpress_upload' ) ) );
			echo '<script>(function(){var CFG=' . $cfg . ';' // phpcs:ignore WordPress.Security.EscapeOutput -- json from wp_json_encode.
				. 'document.addEventListener("change",function(e){var i=e.target;if(i.type!=="file"){return;}var b=i.closest(".sp-media");if(!b||!i.files[0]){return;}var fd=new FormData();fd.append("action","songpress_upload");fd.append("nonce",CFG.nonce);fd.append("file",i.files[0]);fetch(CFG.url,{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){if(j&&j.success){b.querySelector("input[type=hidden]").value=j.data.id;b.querySelector(".sp-media-clearflag").value="0";var p=b.querySelector(".sp-media-preview");p.innerHTML="";if(j.data.thumb){var im=document.createElement("img");im.src=j.data.thumb;im.alt="";p.appendChild(im);}else{p.textContent=j.data.name||"Uploaded";}var c=b.querySelector(".sp-media-clear");if(c){c.hidden=false;}}else{alert((j&&j.data&&j.data.message)||"That file could not be added.");}});i.value="";});'
				. 'document.addEventListener("click",function(e){var c=e.target.closest(".sp-media-clear");if(!c){return;}var b=c.closest(".sp-media");b.querySelector("input[type=hidden]").value="0";b.querySelector(".sp-media-clearflag").value="1";b.querySelector(".sp-media-preview").innerHTML="";c.hidden=true;});})();</script>';
			return;
		}
		$cfg = wp_json_encode( array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'songpress_upload' ) ) );
		echo '<style>.sp-dropping{outline:3px dashed #f5a524;outline-offset:4px}.sp-drop-msg{margin:6px 0;color:#646970}</style>';
		echo '<script>(function(){var CFG=' . $cfg . ';' // phpcs:ignore WordPress.Security.EscapeOutput -- json from wp_json_encode.
			. 'function box(f){return document.querySelector(".sp-media[data-field="+f+"]");}'
			. 'function show(b,d){b.querySelector("input[type=hidden]").value=d.id;b.querySelector(".sp-media-clearflag").value="0";var p=b.querySelector(".sp-media-preview");p.innerHTML="";if(d.thumb){var im=document.createElement("img");im.src=d.thumb;im.alt="";im.style.cssText="max-width:120px;height:auto;display:block;margin-bottom:6px";p.appendChild(im);}else{p.textContent=d.name;}b.querySelector(".sp-media-clear").hidden=false;}'
			. 'function msg(t){var m=document.getElementById("sp-drop-msg");if(m){m.textContent=t;}}'
			. 'function send(file){var fd=new FormData();fd.append("action","songpress_upload");fd.append("nonce",CFG.nonce);fd.append("file",file);msg("Uploading "+file.name+"...");return fetch(CFG.url,{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){if(!j.success){msg(file.name+": "+(j.data&&j.data.message?j.data.message:"upload failed"));return;}var b=box(j.data.field)||(j.data.field==="art"?document.querySelector(".sp-media[data-type=image]"):null);if(b){show(b,j.data);msg(file.name+" saved to the Media Library. Remember to Save the song.");}}).catch(function(){msg(file.name+": upload failed");});}'
			. 'var form=document.querySelector(".songpress-wrap form");'
			. 'if(form){var n=0;form.addEventListener("dragenter",function(e){if(e.dataTransfer&&e.dataTransfer.types&&[].indexOf.call(e.dataTransfer.types,"Files")>-1){n++;form.classList.add("sp-dropping");}});form.addEventListener("dragleave",function(){n=Math.max(0,n-1);if(!n){form.classList.remove("sp-dropping");}});form.addEventListener("dragover",function(e){if(e.dataTransfer&&e.dataTransfer.types&&[].indexOf.call(e.dataTransfer.types,"Files")>-1){e.preventDefault();}});form.addEventListener("drop",function(e){if(!e.dataTransfer||!e.dataTransfer.files.length){return;}e.preventDefault();n=0;form.classList.remove("sp-dropping");var d=form.querySelector("details.sp-media-section");if(d){d.open=true;}var q=Promise.resolve();[].forEach.call(e.dataTransfer.files,function(f){q=q.then(function(){return send(f);});});});}'
			. 'document.addEventListener("click",function(e){var bx=e.target.closest(".sp-media");if(!bx){return;}var id=bx.querySelector("input[type=hidden]"),prev=bx.querySelector(".sp-media-preview"),clr=bx.querySelector(".sp-media-clear");if(e.target.closest(".sp-media-clear")){id.value="";bx.querySelector(".sp-media-clearflag").value="1";prev.innerHTML="";clr.hidden=true;return;}if(e.target.closest(".sp-media-pick")){if(!window.wp||!wp.media){return;}var t=bx.getAttribute("data-type");var fr=wp.media({title:t==="image"?"Choose cover art":"Choose audio",library:{type:t==="image"?"image":"audio"},multiple:false});fr.on("select",function(){var a=fr.state().get("selection").first().toJSON();show(bx,{id:a.id,thumb:t==="image"?(a.sizes&&a.sizes.thumbnail?a.sizes.thumbnail.url:a.url):"",name:a.filename||a.title});});fr.open();}});})();</script>';
	}
}
