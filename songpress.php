<?php
/**
 * Plugin Name:       SongPress
 * Plugin URI:        https://github.com/adamjyo/Songpress
 * GitHub Plugin URI: adamjyo/Songpress
 * Description:       A song catalog manager. Keep every song and every version under one serial number, share your catalog, and see your own works.
 * Version:           0.40.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Adam Yoo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       songpress
 *
 * SongPress works with WordPress. It is not made or endorsed by WordPress or by the author of the
 * unrelated "Songpress" chord-typesetting program.
 */

defined( 'ABSPATH' ) || exit;

define( 'SONGPRESS_VERSION', '0.40.1' );
define( 'SONGPRESS_DB_VERSION', '21' );
define( 'SONGPRESS_FILE', __FILE__ );
define( 'SONGPRESS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SONGPRESS_URL', plugin_dir_url( __FILE__ ) );
define( 'SONGPRESS_COFFEE_URL', 'https://www.buymeacoffee.com/adamjyoo' );

require_once SONGPRESS_DIR . 'includes/class-serial.php';
require_once SONGPRESS_DIR . 'includes/class-install.php';
require_once SONGPRESS_DIR . 'includes/class-history.php';
require_once SONGPRESS_DIR . 'includes/class-audit.php';
require_once SONGPRESS_DIR . 'includes/class-lists.php';
require_once SONGPRESS_DIR . 'includes/class-names.php';
require_once SONGPRESS_DIR . 'includes/class-backup.php';
require_once SONGPRESS_DIR . 'includes/class-health.php';
require_once SONGPRESS_DIR . 'includes/class-chordpro.php';
require_once SONGPRESS_DIR . 'includes/class-media.php';
require_once SONGPRESS_DIR . 'includes/class-updater.php';
require_once SONGPRESS_DIR . 'includes/class-caps.php';
require_once SONGPRESS_DIR . 'includes/class-artists.php';
require_once SONGPRESS_DIR . 'includes/class-profiles.php';
require_once SONGPRESS_DIR . 'includes/class-songs.php';
require_once SONGPRESS_DIR . 'includes/class-people.php';
require_once SONGPRESS_DIR . 'includes/class-import-analyzer.php';
require_once SONGPRESS_DIR . 'includes/class-import.php';
require_once SONGPRESS_DIR . 'includes/class-editor.php';
require_once SONGPRESS_DIR . 'includes/class-members.php';
require_once SONGPRESS_DIR . 'includes/class-money.php';
require_once SONGPRESS_DIR . 'includes/class-pitch.php';
require_once SONGPRESS_DIR . 'includes/class-journal.php';
require_once SONGPRESS_DIR . 'includes/class-art.php';
require_once SONGPRESS_DIR . 'includes/class-agreements.php';
require_once SONGPRESS_DIR . 'includes/class-registrations.php';
require_once SONGPRESS_DIR . 'includes/class-uploads.php';
require_once SONGPRESS_DIR . 'includes/class-persons.php';
require_once SONGPRESS_DIR . 'includes/class-sessions.php';
require_once SONGPRESS_DIR . 'includes/class-chordtool.php';
require_once SONGPRESS_DIR . 'includes/class-screens.php';
require_once SONGPRESS_DIR . 'includes/class-frontend.php';
require_once SONGPRESS_DIR . 'admin/class-admin.php';

register_activation_hook( __FILE__, array( 'Songpress_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Songpress_Install', 'deactivate' ) );

// Database upgrades and page creation need WordPress fully started (rewrite rules, translations), so they wait for init.
add_action( 'init', array( 'Songpress_Install', 'maybe_upgrade' ), 1 );

add_action( 'plugins_loaded', function () {
	add_filter( 'user_has_cap', array( 'Songpress_Caps', 'filter_user_caps' ), 10, 4 );
	Songpress_Frontend::init();
		Songpress_Members::init();
		Songpress_Money::init();
		Songpress_Pitch::init();
		Songpress_Journal::init();
		Songpress_Art::init();
		Songpress_Agreements::init();
		Songpress_Registrations::init();
		Songpress_Uploads::init();
		Songpress_Persons::init();
		Songpress_Sessions::init();
		Songpress_ChordTool::init();
		Songpress_Audit::init();
		Songpress_Lists::init();
		Songpress_Names::init();
		Songpress_Backup::init();
		Songpress_Health::init();
		Songpress_People::init();
	Songpress_Updater::init();
	if ( is_admin() ) {
		Songpress_Admin::init();
	}
} );

/** Donate link for SongPress's own admin screens. */
function songpress_donate_link() {
	return '<p class="songpress-donate"><a href="' . esc_url( SONGPRESS_COFFEE_URL ) . '" target="_blank" rel="noopener noreferrer">'
		. esc_html__( 'Donate: buy me a cup of coffee', 'songpress' ) . '</a></p>';
}

/** Donate link for public pages. Empty unless the site owner turned it on in SongPress > Settings. */
function songpress_public_donate_link() {
	return '1' === get_option( 'songpress_public_donate', '0' ) ? songpress_donate_link() : '';
}
