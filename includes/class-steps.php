<?php
/**
 * Previous / Next buttons for the works editor.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Songpress_Steps {

	/**
	 * Previous / Next along the works list the person came from: the same search, status filter and sort the list had
	 * (they ride along in the address). Opened with no list behind it, it walks every work in the default order.
	 */
	public static function nav( $id, $list_url ) {
		if ( ! $id ) {
			return '';
		}
		$g = function ( $k ) {
			return isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		};
		$q      = $g( 'sp_q' );
		$status = sanitize_key( $g( 'sp_status' ) );
		$status = isset( Songpress_Songs::statuses()[ $status ] ) ? $status : '';
		$order  = sanitize_key( $g( 'sp_sort' ) );
		$order  = in_array( $order, array( 'serial', 'title', 'genre', 'writer', 'artist', 'label', 'status' ), true ) ? $order : '';
		$dir    = 'desc' === $g( 'sp_dir' ) ? 'desc' : 'asc';
		$res    = Songpress_Songs::query( array( 'search' => $q, 'status' => $status, 'order' => $order, 'dir' => $dir, 'ids_only' => 1, 'user_id' => Songpress_Caps::can( 'view_all' ) ? 0 : get_current_user_id() ) );
		$ids    = $res['ids'];
		$at     = array_search( (int) $id, $ids, true );
		if ( false === $at ) {
			return ''; // this song is not in that list (a new search, or it was edited out of the filter)
		}
		$keep = array_filter( array( 'sp_q' => $q, 'sp_status' => $status, 'sp_sort' => $order, 'sp_dir' => 'desc' === $dir ? 'desc' : '', 'sp_n' => $g( 'sp_n' ), 'sp_tab' => sanitize_key( $g( 'sp_tab' ) ) ), function ( $v ) {
			return '' !== $v;
		} );
		$link = function ( $to, $cls, $icon, $word ) use ( $keep, $list_url ) {
			if ( ! $to ) {
				return '<span class="spe-ib off ' . $cls . '" title="' . esc_attr( $word ) . '">' . $icon . '</span>';
			}
			$t = Songpress_Songs::get( $to );
			return '<a class="spe-ib spe-step ' . $cls . '" href="' . esc_url( add_query_arg( array_merge( array( 'sp_song' => $to ), $keep ), $list_url ) ) . '" title="' . esc_attr( $word . ( $t ? ': ' . $t->serial . ' ' . $t->title : '' ) ) . '" aria-label="' . esc_attr( $word ) . '">' . $icon . '</a>';
		};
		$n = count( $ids );
		return '<nav class="spe-stepnav" aria-label="' . esc_attr__( 'Move through the works list', 'songpress' ) . '">'
			. $link( $ids[ $at - 1 ] ?? 0, 'prev', '<svg class="spe-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>', __( 'Previous', 'songpress' ) )
			. '<span class="spe-steppos">' . esc_html( sprintf( /* translators: 1: position, 2: how many works in the list */ __( '%1$d of %2$d', 'songpress' ), $at + 1, $n ) ) . ( '' !== $q ? ' <em>' . esc_html( sprintf( /* translators: %s: search words */ __( 'for "%s"', 'songpress' ), $q ) ) . '</em>' : '' ) . '</span>'
			. $link( $ids[ $at + 1 ] ?? 0, 'next', '<svg class="spe-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>', __( 'Next', 'songpress' ) )
			. '</nav><script>(function(){var f=document.querySelector(".spe-form"),d=false;if(!f)return;f.addEventListener("input",function(){d=true});f.addEventListener("submit",function(){d=false});document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest(".spe-stepnav a");if(a&&d&&!window.confirm("' . esc_js( __( 'You have changes that are not saved. Leave without saving?', 'songpress' ) ) . '"))e.preventDefault()})}());</script>';
	}
}
