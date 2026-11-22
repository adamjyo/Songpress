<?php
defined( 'ABSPATH' ) || exit;

/**
 * Lists the Manager can edit: the statuses a song can have, and the genres offered as suggestions.
 * Statuses are written one per line as "key | Label". Change the label to rename a status without touching the songs
 * that use it; add a line to add a status; remove a line to retire one (songs that still have it keep it).
 */
class Songpress_Lists {

	public static function init() {
		add_action( 'admin_post_songpress_lists_save', array( __CLASS__, 'handle_save' ) );
	}

	/** Pure: "key | Label" lines to key => label. A line with no bar becomes a new status named from its label. */
	public static function parse_statuses( $text ) {
		$out = array();
		foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( false !== strpos( $line, '|' ) ) {
				list( $k, $label ) = array_map( 'trim', explode( '|', $line, 2 ) );
			} else {
				$k     = $line;
				$label = $line;
			}
			$k = substr( preg_replace( '/[^a-z0-9_]+/', '_', strtolower( $k ) ), 0, 24 );
			$k = trim( $k, '_' );
			if ( '' === $k ) {
				continue;
			}
			$label = '' !== $label ? mb_substr( sanitize_text_field( $label ), 0, 40 ) : ucfirst( $k );
			if ( ! isset( $out[ $k ] ) ) {
				$out[ $k ] = $label;
			}
		}
		return $out;
	}

	/** Pure: one genre per line, no blanks or repeats. */
	public static function parse_genres( $text ) {
		$out = array();
		foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
			$line = mb_substr( sanitize_text_field( $line ), 0, 64 );
			if ( '' !== $line && ! in_array( strtolower( $line ), array_map( 'strtolower', $out ), true ) ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	public static function handle_save() {
		check_admin_referer( 'songpress_lists_save' );
		if ( ! Songpress_Caps::can( 'settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'songpress' ), '', 403 );
		}
		$st = self::parse_statuses( wp_unslash( $_POST['statuses'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- cleaned in parse_statuses().
		$gn = self::parse_genres( wp_unslash( $_POST['genres'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( ! empty( $_POST['reset_statuses'] ) || ! $st ) { // phpcs:ignore WordPress.Security.NonceVerification
			delete_option( 'songpress_statuses' );
		} else {
			update_option( 'songpress_statuses', $st );
		}
		update_option( 'songpress_genres', $gn );
		wp_safe_redirect( add_query_arg( array( 'sp_page' => 'lists', 'l_ok' => rawurlencode( __( 'Lists saved.', 'songpress' ) ) ), Songpress_Editor::list_url() ) );
		exit;
	}

	/** How many songs use each status key, so retiring one is a choice made with the numbers in view. */
	public static function usage() {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . Songpress_Install::table( 'songs' ) . ' GROUP BY status' ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ $r->status ] = (int) $r->n;
		}
		return $out;
	}

	public static function render() {
		if ( ! Songpress_Caps::can( 'settings' ) ) {
			return '<div class="sp-app"><p class="songpress-note">' . esc_html__( 'Only Managers can open this screen.', 'songpress' ) . '</p></div>';
		}
		$use  = self::usage();
		$st   = '';
		foreach ( Songpress_Songs::statuses() as $k => $l ) {
			$st .= $k . ' | ' . $l . "\n";
		}
		$o  = '<div class="songpress sp-app sp-editor sp-lists"><div class="spe-top"><h2>' . esc_html__( 'Lists', 'songpress' ) . '</h2></div>';
		if ( ! empty( $_GET['l_ok'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o .= '<p class="spe-note ok">' . esc_html( sanitize_text_field( wp_unslash( $_GET['l_ok'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		$o .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'songpress_lists_save', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="songpress_lists_save">';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Statuses (one per line: key | Label)', 'songpress' ) . '</span><textarea name="statuses" rows="8">' . esc_textarea( $st ) . '</textarea></label>';
		$o .= '<p class="spe-help">' . esc_html__( 'Change the part after the bar to rename a status. Songs keep their status either way. A line with no bar adds a new one. Songs now: ', 'songpress' );
		$bits = array();
		foreach ( $use as $k => $n ) {
			$bits[] = $k . ' ' . $n;
		}
		$o .= esc_html( implode( ' · ', $bits ) ) . '</p>';
		$o .= '<label class="spe-field"><span>' . esc_html__( 'Genres (one per line, offered as suggestions)', 'songpress' ) . '</span><textarea name="genres" rows="8">' . esc_textarea( implode( "\n", Songpress_Songs::genres() ) ) . '</textarea></label>';
		$o .= '<p><button class="spe-save" type="submit">' . esc_html__( 'Save lists', 'songpress' ) . '</button> <label><input type="checkbox" name="reset_statuses" value="1"> ' . esc_html__( 'Put the statuses back to the built-in six', 'songpress' ) . '</label></p></form></div>';
		return $o;
	}
}
