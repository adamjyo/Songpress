<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Deleting the plugin keeps the catalog unless the owner switched this on in SongPress > Settings.
if ( '1' !== get_option( 'songpress_delete_on_uninstall' ) ) {
	return;
}
global $wpdb;
foreach ( array( 'history', 'credits', 'identifiers', 'links', 'songs', 'families' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}songpress_{$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL
}
delete_option( 'songpress_db_version' );
delete_option( 'songpress_delete_on_uninstall' );
delete_option( 'songpress_public_donate' );
delete_option( 'songpress_donate_prompt' );
delete_option( 'songpress_classes_migrated' );
delete_option( 'songpress_classes_v2' );
delete_option( 'songpress_accounts_ready' );
delete_option( 'songpress_pages_done' ); // the pages themselves are left in place: they are yours
delete_option( 'songpress_page_catalog' );
delete_option( 'songpress_page_profile' );
delete_option( 'songpress_github_updates' );
delete_option( 'songpress_github_repo' );
delete_transient( 'songpress_github_release' );
delete_metadata( 'user', 0, 'songpress_class', '', true );
remove_role( 'songpress_writer' ); // left over from versions before SongPress had its own classes
foreach ( array( 'administrator', 'editor' ) as $name ) {
	$role = get_role( $name );
	if ( $role ) {
		$role->remove_cap( 'songpress_manage' );
		$role->remove_cap( 'songpress_view' );
	}
}
