<?php
// Checks that versions keep their own people and splits, using a SQLite stand-in for $wpdb.
// Run: php tests/versions.php   (the real plugin runs on MySQL in WordPress; this checks the logic and the SQL shape)
define( 'ABSPATH', '/x/' );
class WP_Error { public $code, $msg; function __construct( $c = '', $m = '' ) { $this->code = $c; $this->msg = $m; } function get_error_message() { return $this->msg; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function __( $s ) { return $s; }
function current_time() { return '2026-01-01 00:00:00'; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_textarea_field( $s ) { return (string) $s; }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); } }
function esc_url_raw( $s ) { return (string) $s; }
function wp_generate_password( $n ) { return substr( str_repeat( md5( uniqid( '', true ) ), 2 ), 0, $n ); }
function wp_get_attachment_url( $id ) { return 'https://site.test/uploads/audio-' . (int) $id . '.mp3'; }
function wp_get_attachment_image_url( $id, $size = 'medium' ) { return 'https://site.test/uploads/art-' . (int) $id . '-' . $size . '.jpg'; }
function attachment_url_to_postid( $u ) { return preg_match( '#/uploads/(?:art|audio)-(\d+)\.#', $u, $m ) ? (int) $m[1] : 0; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s ) { return htmlspecialchars( (string) $s ); }
function esc_attr__( $s ) { return htmlspecialchars( (string) $s ); }
function number_format_i18n( $n ) { return number_format( $n ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s ); }
function esc_url( $s ) { return (string) $s; }
function get_current_user_id() { return 7; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_unslash( $s ) { return $s; }
function absint( $n ) { return abs( (int) $n ); }
$GLOBALS['opts'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $s ) ), '-' ); }
function home_url( $p = '' ) { return 'https://site.test' . $p; }
function get_bloginfo( $k = '' ) { return 'Test Site'; }

class FakeWpdb {
	public $prefix = 'wp_', $insert_id = 0; public $pdo;
	function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' ); $this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_families (id INTEGER PRIMARY KEY AUTOINCREMENT, family_no INT UNIQUE, created_at TEXT)' );
		$cols = 'family_id INT, version_no INT, revision INT DEFAULT 0, serial TEXT UNIQUE, legacy_id INT, title TEXT, alt_titles TEXT, status TEXT, stage TEXT, writing_date TEXT, musical_key TEXT, tempo TEXT, time_signature TEXT, genre TEXT, artist TEXT, album TEXT, release_title TEXT, release_date TEXT, copyright_date TEXT, production_date TEXT, format TEXT, style TEXT, themes TEXT, ai_prompt TEXT, art_prompt TEXT, lyrics TEXT, chords_lyrics TEXT, chordpro TEXT, story TEXT, notes TEXT, audio_url TEXT, art_id INT, audio_id INT, art_url TEXT, owner_id INT, artist_id INT DEFAULT 0, archived_at TEXT, share_token TEXT UNIQUE, created_at TEXT, updated_at TEXT';
		$this->pdo->exec( "CREATE TABLE wp_songpress_songs (id INTEGER PRIMARY KEY AUTOINCREMENT, $cols)" );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_credits (id INTEGER PRIMARY KEY AUTOINCREMENT, song_id INT, role TEXT, name TEXT, ipi TEXT, share REAL, user_id INT, position INT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_identifiers (id INTEGER PRIMARY KEY AUTOINCREMENT, song_id INT, kind TEXT, value TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_history (id INTEGER PRIMARY KEY AUTOINCREMENT, song_id INT, user_id INT, action TEXT, detail TEXT, created_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_artists (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, slug TEXT UNIQUE, settings TEXT, created_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT, name TEXT, name_key TEXT, aliases TEXT, photo_id INT DEFAULT 0, photo_url TEXT, bio TEXT, email TEXT, phone TEXT, website TEXT, ipi TEXT, pro TEXT, updated_at TEXT, UNIQUE(kind,name_key))' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_earnings (id INTEGER PRIMARY KEY AUTOINCREMENT, song_id INT, quarter TEXT, src TEXT, amount REAL, UNIQUE(song_id,quarter,src))' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_person_earnings (id INTEGER PRIMARY KEY AUTOINCREMENT, person TEXT, person_key TEXT, work_title TEXT, song_id INT, quarter TEXT, src TEXT, amount REAL)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_payments (id INTEGER PRIMARY KEY AUTOINCREMENT, pay_year INT, pay_quarter INT, kind TEXT, party TEXT, party_key TEXT, amount REAL, paid INT, paid_on TEXT, notes TEXT, updated_at TEXT, UNIQUE(pay_year,pay_quarter,kind,party_key))' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_streams (id INTEGER PRIMARY KEY AUTOINCREMENT, day TEXT, service TEXT, streams INT, UNIQUE(day,service))' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_stats (id INTEGER PRIMARY KEY AUTOINCREMENT, row_key TEXT UNIQUE, month TEXT, store TEXT, title TEXT, isrc TEXT, song_id INT, units INT, earnings REAL)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_expenses (id INTEGER PRIMARY KEY AUTOINCREMENT, spent_on TEXT, payee TEXT, category TEXT, amount REAL, song_id INT, person TEXT, notes TEXT, created_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_journal (id INTEGER PRIMARY KEY AUTOINCREMENT, log_date TEXT, kind TEXT, title TEXT, notes TEXT, place TEXT, song_id INT DEFAULT 0, pinned INT DEFAULT 0, archived INT DEFAULT 0, song_key TEXT DEFAULT \'\', tempo TEXT DEFAULT \'\', authors TEXT DEFAULT \'\', latitude TEXT DEFAULT \'\', longitude TEXT DEFAULT \'\', source_key TEXT UNIQUE, user_id INT, created_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_ratings (id INTEGER PRIMARY KEY AUTOINCREMENT, song_id INT, voter TEXT, stars INT, created_at TEXT, UNIQUE(song_id,voter))' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_art (id INTEGER PRIMARY KEY AUTOINCREMENT, song_id INT DEFAULT 0, name TEXT, layers TEXT, preview_id INT DEFAULT 0, preview_url TEXT, source_key TEXT UNIQUE, user_id INT, created_at TEXT, updated_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_agreements (id INTEGER PRIMARY KEY AUTOINCREMENT, song_id INT, serial TEXT, kind TEXT, title TEXT, body TEXT, print_token TEXT UNIQUE, source_key TEXT UNIQUE, user_id INT, created_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_signers (id INTEGER PRIMARY KEY AUTOINCREMENT, agreement_id INT, name TEXT, role TEXT, share TEXT, token TEXT UNIQUE, signed_as TEXT, signed_at TEXT, ip TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_activity (id INTEGER PRIMARY KEY AUTOINCREMENT, day TEXT, kind TEXT, song_id INT DEFAULT 0, serial TEXT, title TEXT, detail TEXT, source_key TEXT DEFAULT \'\', created_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, session_date TEXT, session_time TEXT, title TEXT, song_id INT DEFAULT 0, location TEXT, notes TEXT, lyrics TEXT, audio_url TEXT, owner_user_id INT DEFAULT 0, source_key TEXT DEFAULT \'\', created_at TEXT, updated_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_session_people (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INT, name TEXT, person_key TEXT, user_id INT DEFAULT 0, ipi TEXT DEFAULT \'\', share TEXT DEFAULT \'\')' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_session_chat (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INT, author TEXT, user_id INT DEFAULT 0, kind TEXT DEFAULT \'text\', body TEXT, audio_url TEXT, source_key TEXT DEFAULT \'\', created_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_note_versions (id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INT, title TEXT DEFAULT \'\', notes TEXT, user_id INT DEFAULT 0, saved_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_audit (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT DEFAULT 0, area TEXT, action TEXT, subject TEXT DEFAULT \'\', detail TEXT, created_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_registrations (id INTEGER PRIMARY KEY AUTOINCREMENT, family_id INT, society TEXT, in_progress INT DEFAULT 0, started_on TEXT, reference TEXT, note TEXT, updated_at TEXT, updated_by INT, UNIQUE(family_id,society))' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_pitches (id INTEGER PRIMARY KEY AUTOINCREMENT, song_id INT, channel TEXT, sent_to TEXT, sent_at TEXT, follow_up TEXT DEFAULT \'\', outcome TEXT DEFAULT \'open\', notes TEXT, user_id INT DEFAULT 0, created_at TEXT, updated_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_pitch_kits (song_id INTEGER PRIMARY KEY, wav_url TEXT, clean_url TEXT, instr_url TEXT, stems_url TEXT, photo_url TEXT, bio TEXT, bio_url TEXT, mood TEXT DEFAULT \'\', contact TEXT DEFAULT \'\', explicit INT DEFAULT 0, updated_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_links (id INTEGER PRIMARY KEY AUTOINCREMENT, song_id INT, kind TEXT, url TEXT)' );
	}
	function prepare( $sql, ...$a ) {
		if ( 1 === count( $a ) && is_array( $a[0] ) ) { $a = $a[0]; }
		$i = 0; return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $a ) { $v = $a[ $i++ ]; return '%d' === $m[0] ? (int) $v : $this->pdo->quote( (string) $v ); }, $sql );
	}
	function esc_like( $s ) { return $s; }
	function get_var( $sql ) { if ( false !== strpos( $sql, 'GET_LOCK' ) ) { return 1; } $r = $this->pdo->query( $sql )->fetchColumn(); return false === $r ? null : $r; }
	function get_row( $sql, $out = 'OBJECT' ) { return $this->pdo->query( $sql )->fetch( 'ARRAY_A' === $out ? PDO::FETCH_ASSOC : PDO::FETCH_OBJ ) ?: null; }
	function get_col( $sql ) { return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_COLUMN ); }
	function get_results( $sql ) { return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_OBJ ); }
	function query( $sql ) { if ( false !== strpos( $sql, 'RELEASE_LOCK' ) ) { return 1; } return $this->pdo->exec( $sql ); }
	function insert( $t, $d ) { $k = array_keys( $d ); $st = $this->pdo->prepare( "INSERT INTO $t (" . implode( ',', $k ) . ') VALUES (' . implode( ',', array_fill( 0, count( $k ), '?' ) ) . ')' ); $st->execute( array_values( $d ) ); $this->insert_id = (int) $this->pdo->lastInsertId(); return 1; }
	function update( $t, $d, $w ) { $set = implode( ',', array_map( function ( $k ) { return "$k=?"; }, array_keys( $d ) ) ); $wh = implode( ' AND ', array_map( function ( $k ) { return "$k=?"; }, array_keys( $w ) ) ); $st = $this->pdo->prepare( "UPDATE $t SET $set WHERE $wh" ); $st->execute( array_merge( array_values( $d ), array_values( $w ) ) ); return $st->rowCount(); }
	function delete( $t, $w ) { $wh = implode( ' AND ', array_map( function ( $k ) { return "$k=?"; }, array_keys( $w ) ) ); $st = $this->pdo->prepare( "DELETE FROM $t WHERE $wh" ); $st->execute( array_values( $w ) ); return $st->rowCount(); }
}
$wpdb = new FakeWpdb();
require __DIR__ . '/../includes/class-serial.php';
require __DIR__ . '/../includes/class-install.php';
require __DIR__ . '/../includes/class-history.php';
require __DIR__ . '/../includes/class-audit.php';
if ( ! defined( 'SONGPRESS_VERSION' ) ) { define( 'SONGPRESS_VERSION', '9.9.9' ); }
if ( ! defined( 'SONGPRESS_DB_VERSION' ) ) { define( 'SONGPRESS_DB_VERSION', '18' ); }
if ( ! defined( 'SONGPRESS_DIR' ) ) { define( 'SONGPRESS_DIR', dirname( __DIR__ ) . '/' ); }
$GLOBALS['sp_tmp'] = sys_get_temp_dir() . '/sp-test-' . getmypid();
if ( ! function_exists( 'wp_upload_dir' ) ) { function wp_upload_dir() { return array( 'basedir' => $GLOBALS['sp_tmp'], 'error' => false ); } }
if ( ! function_exists( 'trailingslashit' ) ) { function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; } }
if ( ! function_exists( 'wp_mkdir_p' ) ) { function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); } }
if ( ! function_exists( 'wp_normalize_path' ) ) { function wp_normalize_path( $p ) { return str_replace( '\\', '/', (string) $p ); } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); } }
require_once __DIR__ . '/../includes/class-import.php';
if ( ! function_exists( 'remove_accents' ) ) { function remove_accents( $s ) { return strtr( (string) $s, array( 'é' => 'e', 'è' => 'e', 'á' => 'a', 'ñ' => 'n', 'ç' => 'c', 'ü' => 'u', 'ö' => 'o' ) ); } }
if ( ! class_exists( 'Songpress_Editor', false ) ) { class Songpress_Editor { public static function edit_url( $id ) { return 'https://site.test/edit/' . (int) $id; } } }
require __DIR__ . '/../includes/class-names.php';
require __DIR__ . '/../includes/class-backup.php';
require __DIR__ . '/../includes/class-health.php';
if ( ! defined( 'SONGPRESS_TESTING' ) ) { define( 'SONGPRESS_TESTING', 1 ); }
require_once __DIR__ . '/../includes/class-import-analyzer.php';
require __DIR__ . '/../includes/class-artists.php';
require __DIR__ . '/../includes/class-profiles.php';
require __DIR__ . '/../includes/class-media.php';
require __DIR__ . '/../includes/class-chordpro.php';
require __DIR__ . '/../includes/class-songs.php';
require __DIR__ . '/../includes/class-people.php';
require __DIR__ . '/../includes/class-money.php';
require __DIR__ . '/../includes/class-pitch.php';
require __DIR__ . '/../includes/class-journal.php';
require __DIR__ . '/../includes/class-art.php';
require __DIR__ . '/../includes/class-agreements.php';
require __DIR__ . '/../includes/class-registrations.php';
require __DIR__ . '/../includes/class-uploads.php';
require __DIR__ . '/../includes/class-persons.php';
require __DIR__ . '/../includes/class-sessions.php';

$fail = 0; $n = 0;
function check( $name, $got, $want ) { global $fail, $n; $n++; if ( $got !== $want ) { $fail++; echo "FAIL $name\n  got:  " . var_export( $got, true ) . "\n  want: " . var_export( $want, true ) . "\n"; } }
function people( $id, $role ) { $o = array(); foreach ( Songpress_Songs::credits( $id ) as $c ) { if ( $c->role === $role ) { $o[] = $c->name . ':' . ( null === $c->share ? '-' : (float) $c->share ); } } return $o; }
function wp_ids( $id ) { $o = array(); foreach ( Songpress_Songs::identifiers( $id ) as $i ) { $o[] = $i->kind; } sort( $o ); return $o; }
function put( $table, $row ) { global $wpdb; $wpdb->insert( $wpdb->prefix . 'songpress_' . $table, $row ); }

// original work 0001 with 3 writers, a performer, and codes
$v0 = Songpress_Songs::create( array( 'title' => 'Harbor Lights', 'status' => 'writing', 'lyrics' => 'la' ), 1 );
check( 'original serial', Songpress_Songs::get( $v0 )->serial, '0001' );
Songpress_Songs::replace_credits( $v0, array(
	array( 'role' => 'writer', 'name' => 'A', 'share' => '50' ), array( 'role' => 'writer', 'name' => 'B', 'share' => '30' ), array( 'role' => 'writer', 'name' => 'C', 'share' => '20' ),
	array( 'role' => 'performer', 'name' => 'Singer One' ), array( 'role' => 'producer', 'name' => 'Producer One', 'share' => '10' ),
) );
put( 'identifiers', array( 'song_id' => $v0, 'kind' => 'iswc', 'value' => 'T-123' ) );
put( 'identifiers', array( 'song_id' => $v0, 'kind' => 'isrc', 'value' => 'USX1' ) );
put( 'links', array( 'song_id' => $v0, 'kind' => 'spotify', 'url' => 'https://x' ) );

// version 01
$v1 = Songpress_Songs::create_version( $v0, 1 );
check( 'v1 serial', Songpress_Songs::get( $v1 )->serial, '0001-01' );
check( 'v1 writers copied', people( $v1, 'writer' ), array( 'A:50', 'B:30', 'C:20' ) );
check( 'v1 performers start empty', people( $v1, 'performer' ), array() );
check( 'v1 producers start empty', people( $v1, 'producer' ), array() );
$ids1 = array_map( function ( $r ) { return $r->kind; }, $GLOBALS['wpdb']->get_results( "SELECT kind FROM wp_songpress_identifiers WHERE song_id = $v1" ) );
check( 'v1 keeps iswc, not isrc', $ids1, array( 'iswc' ) );
check( 'v1 no streaming links', count( $GLOBALS['wpdb']->get_results( "SELECT 1 FROM wp_songpress_links WHERE song_id = $v1" ) ), 0 );
check( 'v1 status writing', Songpress_Songs::get( $v1 )->status, 'writing' );

// change v1: new splits, 11 more writers (14 total), a performer and two producers
$w = array( array( 'role' => 'writer', 'name' => 'A', 'share' => '40' ), array( 'role' => 'writer', 'name' => 'B', 'share' => '30' ), array( 'role' => 'writer', 'name' => 'C', 'share' => '10' ) );
for ( $i = 4; $i <= 14; $i++ ) { $w[] = array( 'role' => 'writer', 'name' => "W$i", 'share' => '1.8182' ); }
Songpress_Songs::replace_credits( $v1, array_merge( $w, array(
	array( 'role' => 'performer', 'name' => 'Singer Two' ), array( 'role' => 'producer', 'name' => 'P2', 'share' => '5' ), array( 'role' => 'producer', 'name' => 'P3', 'share' => '5' ),
) ) );
check( 'v1 now 14 writers', count( people( $v1, 'writer' ) ), 14 );
check( 'v1 performer', people( $v1, 'performer' ), array( 'Singer Two:-' ) );
check( 'ORIGINAL unchanged writers', people( $v0, 'writer' ), array( 'A:50', 'B:30', 'C:20' ) );
check( 'ORIGINAL unchanged performer', people( $v0, 'performer' ), array( 'Singer One:-' ) );
check( 'ORIGINAL unchanged producer', people( $v0, 'producer' ), array( 'Producer One:10' ) );

// version 02 made from v1 copies v1's 14 writers; changing v2 leaves v1 and the original alone
$v2 = Songpress_Songs::create_version( $v1, 1 );
check( 'v2 serial', Songpress_Songs::get( $v2 )->serial, '0001-02' );
check( 'v2 copies v1 writers', count( people( $v2, 'writer' ) ), 14 );
check( 'v2 splits from v1', people( $v2, 'writer' )[0], 'A:40' );
Songpress_Songs::replace_credits( $v2, array( array( 'role' => 'writer', 'name' => 'A', 'share' => '100' ), array( 'role' => 'performer', 'name' => 'Live Band' ) ) );
check( 'v2 changed', people( $v2, 'writer' ), array( 'A:100' ) );
check( 'v1 untouched by v2', count( people( $v1, 'writer' ) ), 14 );
check( 'v1 split untouched', people( $v1, 'writer' )[0], 'A:40' );
check( 'original untouched by v2', people( $v0, 'writer' )[0], 'A:50' );

// a different song gets the next family number
$s2 = Songpress_Songs::create( array( 'title' => 'Second Song' ), 1 );
check( 'second family serial', Songpress_Songs::get( $s2 )->serial, '0002' );
check( 'unique serials', count( $GLOBALS['wpdb']->get_results( 'SELECT DISTINCT serial FROM wp_songpress_songs' ) ), 4 );

// release fields are not carried to a new version
$GLOBALS['wpdb']->update( 'wp_songpress_songs', array( 'album' => 'Album X', 'release_date' => '2026-02-02', 'artist' => 'The Band' ), array( 'id' => $v1 ) );
$v3 = Songpress_Songs::create_version( $v1, 1 );
check( 'v3 no album', Songpress_Songs::get( $v3 )->album, '' );
check( 'v3 no release date', Songpress_Songs::get( $v3 )->release_date, '' );
$g3 = Songpress_Songs::get( $v3 );
check( 'a new version starts with its own band, key, tempo, genre, art and audio empty', array( $g3->artist, $g3->musical_key, $g3->tempo, $g3->genre, $g3->audio_url, (int) $g3->art_id ), array( '', '', '', '', '', 0 ) );
check( 'a new version keeps the shared title', $g3->title, Songpress_Songs::get( $v1 )->title );
check( 'original cannot be released', isset( Songpress_Songs::statuses_for( 0 )['released'] ), false );
check( 'version can be released', isset( Songpress_Songs::statuses_for( 1 )['released'] ), true );

// edit conflict: every update bumps the revision, so a stale form can be detected
$rev0 = (int) Songpress_Songs::get( $v3 )->revision;
Songpress_Songs::update( $v3, array( 'title' => 'Edited once' ) );
$rev1 = (int) Songpress_Songs::get( $v3 )->revision;
Songpress_Songs::update( $v3, array( 'title' => 'Edited twice' ) );
check( 'revision bumps on update', $rev1, $rev0 + 1 );
check( 'revision bumps again', (int) Songpress_Songs::get( $v3 )->revision, $rev0 + 2 );
check( 'stale form is detected', $rev0 !== (int) Songpress_Songs::get( $v3 )->revision, true );

// codes and links editor
$dr = Songpress_Songs::replace_identifiers( $v0, array( array( 'kind' => 'ISWC', 'value' => 'T-123' ), array( 'kind' => 'isrc', 'value' => 'US123' ), array( 'kind' => '', 'value' => 'x' ) ), 0 );
check( 'original drops isrc', $dr, 1 );
check( 'original keeps iswc', array_map( fn( $r ) => $r->kind . ':' . $r->value, Songpress_Songs::identifiers( $v0 ) ), array( 'iswc:T-123' ) );
check( 'version keeps isrc', Songpress_Songs::replace_identifiers( $v1, array( array( 'kind' => 'isrc', 'value' => 'US123' ) ), 1 ), 0 );
Songpress_Songs::replace_links( $v1, array( array( 'kind' => 'spotify', 'url' => 'https://x.test/a' ), array( 'kind' => '', 'url' => '' ), array( 'kind' => '', 'url' => 'https://x.test/b' ) ) );
check( 'links saved', array_map( fn( $r ) => $r->kind . ' ' . $r->url, Songpress_Songs::links( $v1 ) ), array( 'spotify https://x.test/a', 'other https://x.test/b' ) );
check( 'original links untouched', count( Songpress_Songs::links( $v0 ) ), 1 );

// archive and history
$q = function ( $a ) { return array_map( fn( $r ) => $r->id, Songpress_Songs::query( $a )['rows'] ); };
$before = count( $q( array() ) );
check( 'works are never archived: archive does nothing', array( Songpress_Songs::archive( $v3 ), in_array( $v3, $q( array() ) ) ), array( false, true ) );
$wpdb->query( "UPDATE wp_songpress_songs SET archived_at = '2026-01-01 00:00:00' WHERE id = " . (int) $v1 );
check( 'one-time clean-up makes archived works active again', array( Songpress_Songs::unarchive_all(), in_array( $v1, $q( array() ) ), Songpress_Songs::count_archived() ), array( 1, true, 0 ) );
$nv = Songpress_Songs::create_version( $v0, 1 );
check( 'new version gets next number', Songpress_Songs::get( $nv )->serial, '0001-04' );
$h = array_map( fn( $r ) => $r->action, Songpress_History::for_song( $v3 ) );
check( 'history has created or version', in_array( 'version', $h ), true );
check( 'history has edits', in_array( 'edited', $h ), true );
$e = array_values( array_filter( Songpress_History::for_song( $v3 ), fn( $r ) => 'edited' === $r->action ) )[0];
check( 'edit describes the change', Songpress_History::describe( $e ), 'Title: Edited once → Edited twice' );
Songpress_Songs::update( $v3, array( 'lyrics' => 'secret words' ) );
$e = Songpress_History::for_song( $v3, 1 )[0];
check( 'long text not copied into history', false !== strpos( $e->detail, 'secret' ), false );
check( 'history records the user', (int) $e->user_id, 7 );

// export
Songpress_Songs::update( $v1, array( 'notes' => '=HYPERLINK("x")', 'title' => 'Plain' ) );
$row = Songpress_Songs::export_row( Songpress_Songs::get( $v1 ) );
$hdr = Songpress_Songs::export_header();
check( 'export columns match', count( $row ), count( $hdr ) );
check( 'export serial', $row[0], '0001-01' );
check( 'export writers with splits', false !== strpos( $row[ array_search( 'writers', $hdr ) ], 'A 40%' ), true );
check( 'export neutralizes formulas', $row[ array_search( 'notes', $hdr ) ], "'=HYPERLINK(\"x\")" );
check( 'export has links', false !== strpos( $row[ array_search( 'links', $hdr ) ], 'https://x.test/a' ), true );

// sharing
$wpdb->query( "UPDATE wp_songpress_songs SET share_token = NULL WHERE id = $v1" );
check( 'tokens are filled for songs without one', Songpress_Songs::ensure_tokens() >= 1, true );
$tok = $wpdb->get_var( "SELECT share_token FROM wp_songpress_songs WHERE id = $v1" );
check( 'token finds song', (int) Songpress_Songs::by_token( $tok )->id, $v1 );
check( 'bad token finds nothing', Songpress_Songs::by_token( 'short' ), null );
$wpdb->query( "UPDATE wp_songpress_songs SET share_token = NULL WHERE id = $v1" );
check( 'token_for makes a token when a song has none, and keeps it', array( strlen( Songpress_Songs::token_for( $v1 ) ), Songpress_Songs::token_for( $v1 ) === Songpress_Songs::token_for( $v1 ) ), array( 24, true ) );
check( 'every song has a token', (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_songpress_songs WHERE share_token IS NULL OR share_token = ''" ), 0 );
$nv0 = Songpress_Songs::create_version( $v1, 1 );
check( 'a new song or version is shareable at once', strlen( (string) $wpdb->get_var( "SELECT share_token FROM wp_songpress_songs WHERE id = " . (int) $nv0 ) ), 24 );

// media library
check( 'url to library id', Songpress_Media::id_from_url( 'https://site.test/uploads/art-42.jpg' ), 42 );
check( 'resized url finds original', Songpress_Media::id_from_url( 'https://site.test/uploads/art-42-300x300.jpg' ), 42 );
check( 'outside url is not in library', Songpress_Media::id_from_url( 'https://elsewhere.test/a.mp3' ), 0 );
$san = Songpress_Songs::sanitize( array( 'art_id' => '42', 'audio_id' => '0' ) );
check( 'sanitize keeps ids', array( $san['art_id'], $san['audio_id'] ), array( 42, null ) );
$ms = (object) array( 'art_id' => 7, 'art_url' => 'https://old.test/x.jpg', 'audio_id' => null, 'audio_url' => 'https://old.test/x.mp3' );
check( 'library art wins', Songpress_Media::art_url( $ms, 'large' ), 'https://site.test/uploads/art-7-large.jpg' );
check( 'outside audio is the fallback', Songpress_Media::audio_url( $ms ), 'https://old.test/x.mp3' );


// artists
$a2 = Songpress_Artists::save( 0, 'Second Band', array( 'writer_name' => 'Sam Writer', 'accent' => '#112233' ) );
check( 'artist saved', (bool) $a2, true );
$sid = Songpress_Songs::create( array( 'title' => 'Band Two Song', 'artist_id' => $a2 ), 1 );
check( 'song keeps its artist', (int) Songpress_Songs::get( $sid )->artist_id, (int) $a2 );
$q = Songpress_Songs::query( array( 'artist_id' => $a2 ) );
check( 'artist filter', count( $q['rows'] ?? $q ), 1 );
check( 'artist with songs cannot be deleted', Songpress_Artists::delete( $a2 ), false );

// profiles: photos come across from the old database as Media Library links
$old = tempnam( sys_get_temp_dir(), 'sp' );
$o   = new PDO( 'sqlite:' . $old );
$o->exec( "CREATE TABLE people (id INTEGER PRIMARY KEY, name TEXT, name_key TEXT, aliases TEXT, photo TEXT, bio TEXT, email TEXT, phone TEXT, ipi TEXT, pro TEXT)" );
$o->exec( "INSERT INTO people (name,aliases,photo,bio,email) VALUES ('Ann  Smith','Annie S','https://site.test/uploads/art-41.jpg','Singer','a@x.com'),('No Photo','','','','')" );
$o->exec( "CREATE TABLE bands (id INTEGER PRIMARY KEY, name TEXT, name_key TEXT, icon_url TEXT, notes TEXT)" );
$o->exec( "INSERT INTO bands (name,icon_url) VALUES ('The Lamps','https://elsewhere.test/lamps.png')" );
$o->exec( "CREATE TABLE publishers (id INTEGER PRIMARY KEY, name TEXT, photo TEXT, phone TEXT, email TEXT, website TEXT)" );
$o->exec( "INSERT INTO publishers (name,photo,website) VALUES ('Pub Co','/wp-content/uploads/art-9.jpg','https://pub.test')" );
$r = Songpress_Profiles::import_sqlite( $old );
check( 'profiles imported', $r, array( 'people' => 2, 'bands' => 1, 'publishers' => 1 ) );
$ann = Songpress_Profiles::find( 'ann smith', 'person' );
check( 'name matched ignoring spacing and case', $ann->name, 'Ann Smith' );
check( 'alias matches too', Songpress_Profiles::find( 'ANNIE S', 'person' )->id, $ann->id );
check( 'photo link became a media id', (int) $ann->photo_id, 41 );
check( 'photo url resolves from the media id', Songpress_Profiles::photo_url( $ann ), 'https://site.test/uploads/art-41-thumbnail.jpg' );
check( 'outside photo kept as a link', Songpress_Profiles::find( 'the lamps', 'band' )->photo_url, 'https://elsewhere.test/lamps.png' );
check( 'root-relative photo made absolute and matched', (int) Songpress_Profiles::find( 'pub co', 'publisher' )->photo_id, 9 );
Songpress_Profiles::upsert( 'person', 'Ann Smith', array( 'bio' => 'Edited by hand' ) );
Songpress_Profiles::import_sqlite( $old );
global $wpdb;
check( 'rerun keeps hand edits', $wpdb->get_var( 'SELECT bio FROM wp_songpress_profiles WHERE name_key = \'ann smith\'' ), 'Edited by hand' );
check( 'rerun adds no duplicates', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_songpress_profiles' ), 4 );
Songpress_Profiles::reset_cache();
check( 'chip falls back to initials', false !== strpos( Songpress_Profiles::chip( 'Zed Nobody' ), '>ZN<' ), true );
// a band and a person with the same name stay separate
$p1 = Songpress_Profiles::upsert( 'person', 'Echo', array( 'bio' => 'the person', 'photo_url' => 'https://site.test/uploads/art-71.jpg' ) );
$p2 = Songpress_Profiles::upsert( 'band', 'Echo', array( 'bio' => 'the band', 'photo_url' => 'https://site.test/uploads/art-72.jpg' ) );
check( 'same name makes two profiles', $p1 !== $p2, true );
check( 'person lookup gets the person', Songpress_Profiles::find( 'Echo', 'person' )->bio, 'the person' );
check( 'band lookup gets the band', Songpress_Profiles::find( 'echo', 'band' )->bio, 'the band' );
check( 'publisher lookup does not fall back to either', Songpress_Profiles::find( 'Echo', 'publisher' ), null );
check( 'a person-only name is not found as a band', Songpress_Profiles::find( 'Ann Smith', 'band' ), null );
check( 'band chip uses the band photo', false !== strpos( Songpress_Profiles::chip( 'Echo', 28, 'band' ), 'art-72' ), true );
check( 'person chip uses the person photo', false !== strpos( Songpress_Profiles::chip( 'Echo', 28, 'person' ), 'art-71' ), true );
check( 'roles map to kinds', array( Songpress_Profiles::kind_for_role( 'writer' ), Songpress_Profiles::kind_for_role( 'performer' ), Songpress_Profiles::kind_for_role( 'publisher' ), Songpress_Profiles::kind_for_role( 'artist' ) ), array( 'person', 'person', 'publisher', 'band' ) );
unlink( $old );
$sa = Songpress_Songs::query( array( 'order' => 'title', 'dir' => 'asc', 'per_page' => 50 ) );
$sd = Songpress_Songs::query( array( 'order' => 'title', 'dir' => 'desc', 'per_page' => 50 ) );
$ta = array_map( function ( $r ) { return $r->title; }, $sa['rows'] );
$td = array_map( function ( $r ) { return $r->title; }, $sd['rows'] );
check( 'sort by title ascending', $ta, array_values( $ta ) === $ta && $ta === (function ( $x ) { sort( $x ); return $x; })( $ta ) ? $ta : 'not sorted' );
check( 'sort descending is the reverse', array_reverse( $ta ), $td );
$sw = Songpress_Songs::query( array( 'order' => 'writer', 'per_page' => 50 ) );
check( 'sort by writer runs', is_array( $sw['rows'] ), true );
$sx = Songpress_Songs::query( array( 'order' => 'bad; DROP', 'per_page' => 50 ) );
check( 'unknown sort column is ignored', $sx['total'], $sa['total'] );

// money: import a small catalog file, then check totals, matching and that a second import is safe
$GLOBALS['wpdb']->update( 'wp_songpress_songs', array( 'legacy_id' => 11 ), array( 'id' => $v0 ) );
$GLOBALS['wpdb']->update( 'wp_songpress_songs', array( 'legacy_id' => 12 ), array( 'id' => $s2 ) );
$mf  = tempnam( sys_get_temp_dir(), 'spm' );
$mp  = new PDO( 'sqlite:' . $mf );
$mp->exec( 'CREATE TABLE royalty_work_quarter (work_id INT, quarter TEXT, src TEXT, amount REAL)' );
$mp->exec( "INSERT INTO royalty_work_quarter VALUES (11,'2026 Q2','D',10.5),(11,'2026 Q2','D',1.5),(11,'2026 Q2','M',2),(12,'2026 Q1','A',4),(99,'2026 Q1','A',50),(11,'junk','D',1)" );
$mp->exec( 'CREATE TABLE royalty_person_quarter (person TEXT, work_title TEXT, quarter TEXT, src TEXT, amount REAL)' );
$mp->exec( "INSERT INTO royalty_person_quarter VALUES ('Adam Page','Harbor Lights','2026 Q2','D',6),('Adam Page','Harbor Lights','2026 Q2','D',2),('Mack Butts','Harbor Lights','2026 Q2','M',1),('Adam Page','Second Song','2026 Q1','A',4)" );
$mp->exec( 'CREATE TABLE paid_entry (id INT, y INT, q INT, party TEXT, kind TEXT, amount REAL, notes TEXT, updated_at TEXT)' );
$mp->exec( "INSERT INTO paid_entry VALUES (1,2026,2,'Adam Page','writer',8,'x',''),(2,2026,2,'Mack Butts','writer',1,'','')" );
$mp->exec( 'CREATE TABLE pay_board (id INT, y INT, q INT, kind TEXT, party TEXT, paid INT, paid_on TEXT)' );
$mp->exec( "INSERT INTO pay_board VALUES (1,2026,2,'writer','Adam Page',1,'2026-09-05'),(2,2026,1,'writer','Late Person',0,NULL)" );
$mp->exec( 'CREATE TABLE dk_daily_estimates (day TEXT, service TEXT, streams INT)' );
$mp->exec( "INSERT INTO dk_daily_estimates VALUES ('2026-01-01','Spotify',100),('2026-01-01','Apple Music',50),('2026-01-02','Spotify',70)" );
$mp->exec( 'CREATE TABLE distrokid (sale_month TEXT, store TEXT, title TEXT, isrc TEXT, quantity INT, earnings_usd REAL)' );
$mp->exec( "INSERT INTO distrokid VALUES ('2026-07-01','Spotify','Harbor Lights','USX1',100,0.4),('2026-07-01','Spotify','Harbor Lights','USX1',50,0.2),('2026-07-01','Apple','second song','',10,0.1),('2026-07-01','Apple','Unknown','',5,0.05)" );
$mp->exec( 'CREATE TABLE work_log (id INT, log_date TEXT, notes TEXT, kind TEXT, title TEXT, pinned INT, archived INT, place TEXT)' );
$mp->exec( "INSERT INTO work_log VALUES (1,'2026-10-26','worked on archives','day','',0,0,''),(2,'2007-08-04','verse','note','0001 Harbor Lights',1,0,'Church'),(3,'bad','x','day','',0,0,'')" );
$mp->exec( 'CREATE TABLE song_ratings (id INT, song_id INT, voter TEXT, stars INT, created_at TEXT)' );
$mp->exec( "INSERT INTO song_ratings VALUES (1,11,'a',5,'2026-09-01T23:25:46+00:00'),(2,11,'b',4,''),(3,99,'a',5,''),(4,11,'c',9,'')" );
$mp->exec( 'CREATE TABLE art_projects (id INT, name TEXT, layers_json TEXT, preview TEXT, updated_at TEXT, serial TEXT)' );
$mp->exec( "INSERT INTO art_projects VALUES (5,'0001 Harbor Lights art','" . str_replace( "'", "''", json_encode( array(
	array( 'type' => 'canvas', 'name' => 'Canvas', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'meta' => array( 'color' => '#123456' ) ),
	array( 'type' => 'image', 'name' => 'Pic', 'src' => 'https://site.test/uploads/p.png', 'x' => 1.5, 'y' => 2, 'w' => 50, 'h' => 40, 'crop' => array( 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1 ), 'visible' => 1, 'opacity' => 0.5, 'meta' => array() ),
	array( 'type' => 'text', 'name' => 'Text', 'x' => 5, 'y' => 70, 'w' => 40, 'h' => 8, 'meta' => array( 'text' => 'HELLO', 'font' => 'Impact', 'size' => 14, 'color' => '#ff0000', 'bold' => true, 'align' => 'left', 'rotate' => 5 ) ),
	array( 'type' => 'paint', 'name' => 'Unknown kind' ),
	array( 'type' => 'text', 'x' => 9999, 'meta' => array( 'color' => 'javascript:evil', 'size' => 99999 ) ),
) ) ) . "','https://site.test/uploads/prev.jpg','2026-11-18 23:28:39','0001'),(6,'No serial',"."'[]'".",'','2026-11-01 10:00:00','')" );
$mp->exec( 'CREATE TABLE song_catalog (id INT, progress TEXT)' );
$mp->exec( "INSERT INTO song_catalog VALUES (11,'{\"mlc\":1,\"ascap\":1,\"iswc\":1,\"bogus\":1}'),(12,''),(99,'{\"mlc\":1}')" );
$mp->exec( 'CREATE TABLE publishing_agreements (id INT, song_id INT, serial TEXT, title TEXT, body TEXT, status TEXT, kind TEXT, created_at TEXT, print_token TEXT, catalog_stem TEXT)' );
$mp->exec( "INSERT INTO publishing_agreements VALUES (1,11,'0001','Harbor Lights','COWRITER SPLIT CONFIRMATION\n\nbody','sent','split','2026-09-20T04:27:59+00:00','OLDPRINT123','0001')" );
$mp->exec( 'CREATE TABLE agreement_signers (id INT, agreement_id INT, writer_name TEXT, email TEXT, share TEXT, token TEXT, signed_at TEXT, signed_as TEXT, ip TEXT, status TEXT)' );
$mp->exec( "INSERT INTO agreement_signers VALUES (1,1,'A','', '50','OLDTOKENa',NULL,NULL,NULL,'pending'),(2,1,'B','','50','OLDTOKENb','2026-09-21T10:00:00+00:00','Bee Name','1.2.3.4','signed')" );
unset( $mp );
$r = Songpress_Money::import_sqlite( $mf );
check( 'money import earnings lines', $r['earnings'], 3 );
check( 'money import person lines', $r['person'], 3 );
check( 'money import streams', $r['streams'], 3 );
check( 'money import store lines', $r['stats'], 3 );
check( 'money import payouts (Adam, Mack, Late Person)', $r['payments'], 3 );
check( 'earned total skips unknown work and bad quarter', round( Songpress_Money::earned_total(), 2 ), 18.0 );
check( 'quarters newest first', Songpress_Money::quarters(), array( '2026-Q2', '2026-Q1' ) );
check( 'top song is Harbor Lights', Songpress_Money::top_songs( 5 )[0]->title, 'Harbor Lights' );
$ow = Songpress_Money::owed( '2026-Q2' );
check( 'owed Adam', array( $ow[0]->person, round( (float) $ow[0]->total, 2 ) ), array( 'Adam Page', 8.0 ) );
$pp = Songpress_Money::payments_for( 2026, 2 );
check( 'Adam marked paid from the board', array( (int) $pp['writer|adampage']->paid, $pp['writer|adampage']->paid_on, round( (float) $pp['writer|adampage']->amount, 2 ) ), array( 1, '2026-09-05', 8.0 ) );
check( 'paid total', round( Songpress_Money::paid_total(), 2 ), 8.0 );
$mn = Songpress_Money::months();
check( 'stats by month', array( $mn[0]->month, (int) $mn[0]->units ), array( '2026-07', 165 ) );
$ts = Songpress_Money::top_streamed( 5 );
check( 'ISRC matched statement lines to the song', (int) $ts[0]->song_id, $v0 );
check( 'title matched when there is no ISRC', in_array( $s2, array_map( function ( $x ) { return (int) $x->song_id; }, $ts ), true ), true );
check( 'daily streams newest first', array( Songpress_Money::daily( 5 )[0]->day, (int) Songpress_Money::daily( 5 )[0]->streams ), array( '2026-01-02', 70 ) );
// edit a payout by hand, import again: the hand edit stays, the statements are replaced not doubled
$GLOBALS['wpdb']->update( 'wp_songpress_payments', array( 'amount' => 9.99 ), array( 'party_key' => 'mackbutts' ) );
$r2 = Songpress_Money::import_sqlite( $mf );
check( 'second import adds no payouts', $r2['payments'], 0 );
check( 'second import does not double earnings', round( Songpress_Money::earned_total(), 2 ), 18.0 );
check( 'hand-edited payout survives import', round( (float) Songpress_Money::payments_for( 2026, 2 )['writer|mackbutts']->amount, 2 ), 9.99 );
check( 'my earnings found by name', strpos( Songpress_Money::my_earnings_html( 'Adam Page' ), '$12.00' ) !== false, true );
check( 'no earnings, no section', Songpress_Money::my_earnings_html( 'Nobody Here' ), '' );
check( 'journal import', array( $r['journal'], $r['ratings'] ), array( 2, 2 ) );
check( 'journal entry links its song by serial', (int) $GLOBALS['wpdb']->get_var( "SELECT song_id FROM wp_songpress_journal WHERE title LIKE '0001%'" ), $v0 );
$GLOBALS['wpdb']->update( 'wp_songpress_journal', array( 'notes' => 'my edit' ), array( 'source_key' => 'old:1' ) );
check( 'second import adds no journal entries', $r2['journal'], 0 );
check( 'journal edit survives import', $GLOBALS['wpdb']->get_var( "SELECT notes FROM wp_songpress_journal WHERE source_key = 'old:1'" ), 'my edit' );
$js = Songpress_Journal::search( 'my edit', 'live', 1 );
check( 'journal search', array( $js['total'], count( $js['rows'] ) ), array( 1, 1 ) );
check( 'pinned entries come first', Songpress_Journal::search( '', 'live', 1 )['rows'][0]->title, '0001 Harbor Lights' );
check( 'top rated average', array( round( (float) Songpress_Journal::top_rated( 3 )[0]->avg_stars, 1 ), (int) Songpress_Journal::top_rated( 3 )[0]->votes ), array( 4.5, 2 ) );
check( 'serial parsed from a journal title', array( Songpress_Journal::serial_in( '#0258-01 The Lord' ), Songpress_Journal::serial_in( 'hello' ) ), array( '0258-01', '' ) );
check( 'art projects imported', $r['art'], 2 );
$ap = $GLOBALS['wpdb']->get_row( "SELECT * FROM wp_songpress_art WHERE source_key = 'old:5'" );
$al = json_decode( $ap->layers, true );
check( 'art project linked to its song by serial', (int) $ap->song_id, $v0 );
check( 'art keeps layers, drops unknown kinds', array_map( function ( $l ) { return $l['type']; }, $al ), array( 'canvas', 'image', 'text', 'text' ) );
check( 'art canvas colour read from old meta', $al[0]['color'], '#123456' );
check( 'art text settings read from old meta', array( $al[2]['text'], $al[2]['font'], $al[2]['size'], $al[2]['color'], $al[2]['bold'], $al[2]['align'], $al[2]['rotate'] ), array( 'HELLO', 'Impact', 14, '#ff0000', 1, 'left', 5 ) );
check( 'art layer values are clamped and colours checked', array( $al[3]['x'], $al[3]['size'], $al[3]['color'] ), array( 500, 400, '#ffffff' ) );
check( 'art preview link kept', $ap->preview_url, 'https://site.test/uploads/prev.jpg' );
check( 'art second import adds nothing', $r2['art'], 0 );
check( 'label role maps to its own kind', Songpress_Profiles::kind_for_role( 'label' ), 'label' );
Songpress_Profiles::upsert( 'label', 'Redbeard Records', array() );
Songpress_Profiles::upsert( 'person', 'Redbeard Records', array() );
$kinds = array_map( function ( $r ) { return $r->kind; }, $GLOBALS['wpdb']->get_results( "SELECT kind FROM wp_songpress_profiles WHERE name = 'Redbeard Records' ORDER BY kind" ) );
check( 'a label and a person with one name stay separate', $kinds, array( 'label', 'person' ) );
Songpress_Songs::replace_credits( $s2, array( array( 'role' => 'writer', 'name' => 'W' ), array( 'role' => 'label', 'name' => 'Redbeard Records' ) ) );
check( 'label credit is saved with the song', people( $s2, 'label' ), array( 'Redbeard Records:-' ) );
check( 'old agreement imported with its signers', array( $r['agreements'], (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_songpress_signers WHERE token LIKE 'OLDTOKEN%'" ), (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_songpress_signers WHERE signed_at IS NOT NULL" ) ), array( 1, 2, 1 ) );
check( 'old links keep their tokens', $GLOBALS['wpdb']->get_var( "SELECT print_token FROM wp_songpress_agreements WHERE source_key = 'old:1'" ), 'OLDPRINT123' );
check( 'second import adds no agreements', $r2['agreements'], 0 );
$ag = Songpress_Agreements::create( $v0, 'split' );
$sg = Songpress_Agreements::signers( $ag );
check( 'agreement signers are the writers', array_map( function ( $x ) { return $x->name . ':' . $x->share; }, $sg ), array( 'A:50', 'B:30', 'C:20' ) );
check( 'agreement text lists writers and splits', array( strpos( Songpress_Agreements::get( $ag )->body, '- A — writer share 50%' ) !== false, strpos( Songpress_Agreements::get( $ag )->body, 'ISWC: T-123' ) !== false ), array( true, true ) );
$agp = Songpress_Agreements::create( $v0, 'publishing' );
check( 'publishing agreement text has the grant', strpos( Songpress_Agreements::get( $agp )->body, 'Grant. Each writer grants' ) !== false, true );
check( 'signing needs a name and the box', array( Songpress_Agreements::sign( $sg[0]->token, '', true, '1.1.1.1' ) !== '', Songpress_Agreements::sign( $sg[0]->token, 'Aaa', false, '1.1.1.1' ) !== '' ), array( true, true ) );
check( 'signing works', Songpress_Agreements::sign( $sg[0]->token, 'A Writer', true, '1.1.1.1' ), '' );
check( 'a signature is never changed', array( Songpress_Agreements::sign( $sg[0]->token, 'Someone Else', true, '2.2.2.2' ) !== '', Songpress_Agreements::signers( $ag )[0]->signed_as ), array( true, 'A Writer' ) );
check( 'unknown token is refused', Songpress_Agreements::sign( 'nope', 'X', true, '' ) !== '', true );
check( 'list counts signers', array_map( function ( $r ) { return $r->signed . '/' . $r->total; }, array_slice( Songpress_Agreements::all(), 0, 2 ) ), array( '0/' . count( Songpress_Agreements::signers( $agp ) ), '1/3' ) );
check( 'registrations: old in-progress chips imported (unknown society and unknown song skipped)', $r['registrations'], 3 );
check( 'registrations: second import adds nothing', $r2['registrations'], 0 );
check( 'registration state rules', array( Songpress_Registrations::state( true, true ), Songpress_Registrations::state( false, true ), Songpress_Registrations::state( false, false ) ), array( 'green', 'gold', 'red' ) );
check( 'days since', array( Songpress_Registrations::days_since( '2026-01-01', strtotime( '2026-01-31 12:00:00 UTC' ) ), Songpress_Registrations::days_since( '', 0 ) ), array( 30, null ) );
$rw = null;
foreach ( Songpress_Registrations::works() as $w ) { if ( $w->family_id === 1 ) { $rw = $w; } }
check( 'work gathers codes from any version of its family', isset( $rw->codes['isrc'] ) || isset( $rw->codes['iswc'] ), true );
$now = time();
check( 'ISWC is green because the work has the code', Songpress_Registrations::status( $rw, 'iswc', $now )['state'], 'green' );
check( 'MLC is gold from the old chip, undated', array( Songpress_Registrations::status( $rw, 'mlc', $now )['state'], Songpress_Registrations::status( $rw, 'mlc', $now )['days'] ), array( 'gold', null ) );
check( 'BMI is red', Songpress_Registrations::status( $rw, 'bmi', $now )['state'], 'red' );
Songpress_Registrations::mark( $rw->family_id, 'bmi', true, '2026-01-01', 'REF-9', 'sent form' );
$rw2 = null;
foreach ( Songpress_Registrations::works() as $w ) { if ( $w->family_id === 1 ) { $rw2 = $w; } }
$b = Songpress_Registrations::status( $rw2, 'bmi', $now );
check( 'marking in progress keeps date, reference and note', array( $b['state'], $b['started'], $b['ref'], $b['note'] ), array( 'gold', '2026-01-01', 'REF-9', 'sent form' ) );
Songpress_Registrations::mark( $rw->family_id, 'bmi', false, '', 'REF-9', '' );
$rw3 = null;
foreach ( Songpress_Registrations::works() as $w ) { if ( $w->family_id === 1 ) { $rw3 = $w; } }
check( 'clearing goes back to red, reference kept', array( Songpress_Registrations::status( $rw3, 'bmi', $now )['state'], Songpress_Registrations::status( $rw3, 'bmi', $now )['ref'] ), array( 'red', 'REF-9' ) );
check( 'quarter parsing', array( Songpress_Money::quarter( '2026 Q2' ), Songpress_Money::quarter( '2026-q3' ), Songpress_Money::quarter( 'x' ) ), array( '2026-Q2', '2026-Q3', '' ) );
unlink( $mf );

// DistroKid stats page import
$sh = '<html><body><script type="application/json" id="combinedStatsData">{"dsps":[{"key":"spotify","label":"Spotify"},{"key":"apple","label":"Apple Music"}],"series":{"daily":[{"category":"2026-02-01","spotify":10,"apple":4},{"category":"2026-02-02","spotify":7,"apple":0},{"category":"bad","spotify":1}]}}</script></body></html>';
$pr = Songpress_Money::parse_stats_html( $sh );
check( 'stats page parses to day/service rows', $pr, array( array( '2026-02-01', 'Spotify', 10 ), array( '2026-02-01', 'Apple Music', 4 ), array( '2026-02-02', 'Spotify', 7 ), array( '2026-02-02', 'Apple Music', 0 ) ) );
check( 'page with no stats gives a message', is_string( Songpress_Money::parse_stats_html( '<html></html>' ) ), true );
$ri = Songpress_Money::import_stats_html( $sh );
check( 'stats import counts', array( $ri['days'], $ri['rows'] ), array( 2, 4 ) );
$sh2 = str_replace( '"spotify":10', '"spotify":99', $sh );
Songpress_Money::import_stats_html( $sh2 );
check( 'stats import updates a day, no duplicates', array( (int) $GLOBALS['wpdb']->get_var( "SELECT streams FROM wp_songpress_streams WHERE day='2026-02-01' AND service='Spotify'" ), (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_songpress_streams WHERE day='2026-02-01'" ) ), array( 99, 2 ) );
check( 'earlier days kept by a later import', (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_songpress_streams WHERE day='2026-01-01'" ) > 0, true );
$cp = Songpress_Money::chart_payload();
check( 'chart payload has statements and days', array( count( $cp['statements'] ) > 0, count( $cp['daily'] ) > 0 ), array( true, true ) );

if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
// Uploads: statement parsing and applying
$tb = Songpress_Uploads::table( "Work Title\tDistribution Date\tDistributed Amount\n\"Harbor Lights\"\t2026-05-14\t$12.50\nHarbor Lights\t2026-06-01\t$2.50\nNo Such Song\t2026-05-01\t$4.00\n" );
$ln = Songpress_Uploads::statement( 'mlc', $tb );
check( 'mlc statement lines read with quarter from date', array( count( $ln ), $ln[0]['quarter'], $ln[0]['amount'] ), array( 3, '2026-Q2', 12.5 ) );
check( 'chosen quarter overrides the file', Songpress_Uploads::statement( 'mlc', $tb, '2025-Q4' )[0]['quarter'], '2025-Q4' );
check( 'quarter_of formats', array( Songpress_Uploads::quarter_of( '23Q1' ), Songpress_Uploads::quarter_of( '2026-11-03' ), Songpress_Uploads::quarter_of( '3/4/2026' ), Songpress_Uploads::quarter_of( 'zzz' ) ), array( '2023-Q1', '2026-Q4', '2026-Q1', '' ) );
check( 'amount parsing', array( Songpress_Uploads::amount( '$1,234.50' ), Songpress_Uploads::amount( '(3.00)' ) ), array( 1234.5, -3.0 ) );
check( 'ccli pdf lines', Songpress_Uploads::pdf_lines( "Song Name Here      12345   \$8.25\nTotal    \$8.25\n" ), array( array( 'title' => 'Song Name Here', 'amount' => 8.25 ) ) );
$ap = Songpress_Uploads::apply( 'mlc', $ln );
check( 'apply saves matched songs and reports the rest', array( $ap['songs'], $ap['unmatched'], $ap['quarters'] ), array( 1, 1, array( '2026-Q2' ) ) );
check( 'matched lines for one song and quarter are summed', (float) $GLOBALS['wpdb']->get_var( "SELECT amount FROM wp_songpress_earnings WHERE quarter='2026-Q2' AND src='mlc'" ), 15.0 );
Songpress_Uploads::apply( 'mlc', $ln );
check( 'applying again replaces, never doubles', (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_songpress_earnings WHERE quarter='2026-Q2' AND src='mlc'" ), 1 );
check( 'download sql names real tables', strpos( Songpress_Uploads::sql( 'earnings' ), 'wp_songpress_earnings' ) !== false, true );
$bad = 0;
foreach ( array_keys( Songpress_Uploads::DOWNLOADS ) as $k ) {
	if ( in_array( $k, array( 'catalog', 'notes_md', 'notes_bear', 'notes_textbundle' ), true ) ) { continue; }
	if ( false === $GLOBALS['wpdb']->get_results( Songpress_Uploads::sql( $k ), ARRAY_A ) ) { ++$bad; }
}
check( 'every download query runs', $bad, 0 );

if ( ! function_exists( 'sanitize_user' ) ) { function sanitize_user( $s ) { return preg_replace( '/[^a-z0-9._@ -]/i', '', $s ); } }
// People: names, logins, report
check( 'split name', array( Songpress_Persons::split_name( 'Adam Page' ), Songpress_Persons::split_name( 'Mary Ann Lee' ) ), array( array( 'Adam', 'Page' ), array( 'Mary Ann', 'Lee' ) ) );
check( 'login is first.last', Songpress_Persons::login_for( 'Adam', 'Page' ), 'adam.page' );
$cw = $GLOBALS['wpdb']->get_var( "SELECT song_id FROM wp_songpress_earnings WHERE src='mlc' LIMIT 1" );
$GLOBALS['wpdb']->query( "INSERT INTO wp_songpress_person_earnings (person,person_key,work_title,song_id,quarter,src,amount) VALUES ('Zed Tester','zedtester','Harbor Lights',$cw,'2026-Q2','mlc',5),('Zed Tester','zedtester','Harbor Lights',$cw,'2026-Q2','distrokid',3),('Zed Tester','zedtester','Harbor Lights',$cw,'2026-Q1','ascap',2)" );
$rp = Songpress_Persons::report( 'zed tester' );
check( 'person report totals by source', array( $rp['total'], $rp['sources']['mlc'], $rp['sources']['distrokid'], $rp['sources']['ascap'], $rp['sources']['ccli'] ), array( 10.0, 5.0, 3.0, 2.0, 0.0 ) );
check( 'person report quarters newest first', array_keys( $rp['quarters'] ), array( '2026-Q2', '2026-Q1' ) );
check( 'earned total for a person', Songpress_Persons::total_for( 'Zed Tester' ), 10.0 );

$pl = Songpress_Persons::tidy_plan( array(
	1 => array( 'login' => 'abby.russ2', 'display' => 'Abby Russ', 'admin' => false ),
	2 => array( 'login' => 'bob.lee', 'display' => 'Bob Lee', 'admin' => false, 'classed' => true ),
	3 => array( 'login' => 'bob.lee2', 'display' => 'Bob Lee', 'admin' => false ),
	4 => array( 'login' => 'cy.wu', 'display' => 'Cy Wu', 'admin' => false, 'classed' => true ),
	7 => array( 'login' => 'di.fox', 'display' => 'D Fox old', 'admin' => false ),
	8 => array( 'login' => 'di.fox2', 'display' => 'Di Fox', 'admin' => false, 'classed' => true ),
	5 => array( 'login' => 'cy.wu2', 'display' => 'Cy Wu Jr', 'admin' => false, 'classed' => true ),
	6 => array( 'login' => 'sam.kim2', 'display' => 'Sam Kim', 'admin' => true ),
) );
check( 'tidy: rename when free, merge same person, leave different person and admins', $pl, array( array( 'rename', 1, 'abby.russ', 0 ), array( 'merge', 3, 'bob.lee', 2 ), array( 'replace', 8, 'di.fox', 7 ) ) );

// Writing sessions
if ( ! function_exists( 'get_users' ) ) { function get_users( $a = array() ) { return array(); } }
check( 'people lines parse', Songpress_Sessions::parse_people( "Adam Page | 123 | 50\n\n  Bob\n" ), array( array( 'name' => 'Adam Page', 'ipi' => '123', 'share' => '50' ), array( 'name' => 'Bob', 'ipi' => '', 'share' => '' ) ) );
check( 'lyric chat line reads plainly', array( Songpress_Sessions::chat_text( 'lyric', '{"from":"","to":"i love to sing"}' ), Songpress_Sessions::chat_text( 'lyric', '{"from":"a","to":"b"}' ), Songpress_Sessions::chat_text( 'text', 'hi' ) ), array( 'Lyric: i love to sing', 'Lyric changed: "a" to "b"', 'hi' ) );
$sf = tempnam( sys_get_temp_dir(), 'ws' );
$sp = new PDO( 'sqlite:' . $sf );
$sp->exec( "CREATE TABLE writing_sessions (id INTEGER PRIMARY KEY, session_date TEXT, title TEXT, serial_number TEXT, writers TEXT, location TEXT, notes TEXT, lyrics TEXT, audio_url TEXT, created_at TEXT, updated_at TEXT, writer_name_1 TEXT, writer_ipi_1 TEXT, writer_share_1 TEXT, session_time TEXT, work_serial TEXT)" );
$sp->exec( "INSERT INTO writing_sessions VALUES (9,'2026-09-27','Test 1','x','Adam Page','Starbucks','n','l','http://a/b.mp3','2026-09-27 05:50:26','2026-10-27 04:58:32','Adam Page','111','50','05:50','" . $GLOBALS['wpdb']->get_var( "SELECT serial FROM wp_songpress_songs ORDER BY id LIMIT 1" ) . "')" );
$sp->exec( "CREATE TABLE writing_session_members (id INTEGER PRIMARY KEY, session_id INT, actor_id INT, name TEXT, created_at TEXT)" );
$sp->exec( "INSERT INTO writing_session_members VALUES (1,9,5,'bob','x'),(2,9,6,'Adam Page','x')" );
$sp->exec( "CREATE TABLE writing_session_chat (id INTEGER PRIMARY KEY, session_id INT, author TEXT, body TEXT, kind TEXT, audio_url TEXT, created_at TEXT)" );
$sp->exec( "INSERT INTO writing_session_chat VALUES (4,9,'Adam Page','oh hey','text','','2026-09-27 06:01:08'),(5,9,'Adam Page','{\"from\":\"\",\"to\":\"x\"}','lyric','','2026-09-27 07:13:03')" );
check( 'sessions import counts', Songpress_Sessions::import_pdo( $sp ), 1 );
check( 'sessions import is repeatable', Songpress_Sessions::import_pdo( $sp ), 0 );
check( 'session people merged without duplicates', array_map( function ( $p ) { return $p->name; }, Songpress_Sessions::people( 1 ) ), array( 'Adam Page', 'bob' ) );
check( 'session chat kept once each, linked to the song', array( count( Songpress_Sessions::chat( 1 ) ), (int) $GLOBALS['wpdb']->get_var( "SELECT song_id FROM wp_songpress_sessions WHERE id = 1" ) > 0 ), array( 2, true ) );
unlink( $sf );

// Notes: serial links, key/tempo/authors
check( 'serials in a note', Songpress_Journal::serials_in( 'worked on ^0378-00 and #0258-01, ^0378 again, #12' ), array( '0378', '0258-01' ) );
check( 'a note naming one song links to it, several or none do not', array( Songpress_Journal::sole_song( 'see ^0378', array( '0378' => 5 ) ), Songpress_Journal::sole_song( '^0378 ^0258-01', array( '0378' => 5, '0258-01' => 6 ) ), Songpress_Journal::sole_song( 'nothing', array( '0378' => 5 ) ) ), array( 5, 0, 0 ) );
$nf = tempnam( sys_get_temp_dir(), 'nt' );
$np = new PDO( 'sqlite:' . $nf );
$np->exec( "CREATE TABLE work_log (id INTEGER PRIMARY KEY, log_date TEXT, notes TEXT, created_at TEXT, source_key TEXT, kind TEXT, title TEXT, pinned INT, latitude TEXT, longitude TEXT, place TEXT, song_key TEXT, song_tempo TEXT, authors TEXT, archived INT)" );
$np->exec( "INSERT INTO work_log VALUES (9001,'2007-08-04','Adam Page lyric','2007-08-04',NULL,NULL,'',0,'30.1','-97.2','Austin','G','120bpm','Adam Page',0)" );
$nr = Songpress_Journal::import_pdo( $np );
$ne = $GLOBALS['wpdb']->get_row( "SELECT * FROM wp_songpress_journal WHERE source_key='old:9001'" );
check( 'note import keeps key, tempo, authors and place', array( $nr['journal'], $ne->song_key, $ne->tempo, $ne->authors, $ne->latitude, $ne->place ), array( 1, 'G', '120bpm', 'Adam Page', '30.1', 'Austin' ) );
$GLOBALS['wpdb']->query( "UPDATE wp_songpress_journal SET song_key='', tempo='' WHERE source_key='old:9001'" );
$nr2 = Songpress_Journal::import_pdo( $np );
$ne2 = $GLOBALS['wpdb']->get_row( "SELECT * FROM wp_songpress_journal WHERE source_key='old:9001'" );
check( 'second load adds none but fills blanks', array( $nr2['journal'], $ne2->song_key, $ne2->tempo ), array( 0, 'G', '120bpm' ) );
unlink( $nf );

$np2 = new PDO( 'sqlite::memory:' );
$np2->exec( "CREATE TABLE work_activity (id INTEGER PRIMARY KEY, work_id INT, serial_number TEXT, song_title TEXT, day TEXT, kind TEXT, detail TEXT, created_at TEXT)" );
$np2->exec( "INSERT INTO work_activity VALUES (1,2002,'1578-00','One Third','2026-11-05','worked','','2026-11-05 00:21:21'),(2,2003,'1578-06','One Third','2026-11-05','wrote','','2026-11-05 00:22:11')" );
$na = Songpress_Journal::import_pdo( $np2 );
check( 'activity import and repeat', array( $na['activity'], Songpress_Journal::import_pdo( $np2 )['activity'] ), array( 2, 0 ) );
check( 'activity serials normalized', $GLOBALS['wpdb']->get_col( "SELECT serial FROM wp_songpress_activity ORDER BY id" ), array( '1578', '1578-06' ) );

// Lyric process: ChordPro, chords and lyrics, lyrics
$cpx = "{title: T}\n{start_of_verse}\n[G]Walking down to the [D]water\n{end_of_verse}\n{start_of_chorus}\n[C]Harbor lights\n{end_of_chorus}";
check( 'chordpro steps down to chords over words', Songpress_ChordPro::to_chords_lyrics( $cpx ), "T\n[Verse]\nG                   D\nWalking down to the water\n[Chorus]\nC\nHarbor lights" );
check( 'chordpro steps down to plain lyrics', Songpress_ChordPro::to_lyrics( $cpx ), "Walking down to the water\n\nHarbor lights" );
check( 'chords over words steps down to lyrics', Songpress_ChordPro::chords_lyrics_to_lyrics( "[Verse]\nG     D\nWalking down\n\nAm\nOh well" ), "Walking down\n\nOh well" );
$lv = Songpress_Songs::lyric_levels( (object) array( 'chordpro' => $cpx, 'chords_lyrics' => '', 'lyrics' => '' ) );
check( 'only ChordPro: lower levels fall back to it', array( $lv['chords_lyrics'][1], $lv['lyrics'][1], $lv['lyrics'][0] ), array( 'chordpro', 'chordpro', "Walking down to the water\n\nHarbor lights" ) );
$lv2 = Songpress_Songs::lyric_levels( (object) array( 'chordpro' => '', 'chords_lyrics' => "G\nHello there", 'lyrics' => '' ) );
check( 'chords and lyrics: lyrics fall back to it, ChordPro stays empty', array( $lv2['lyrics'][0], $lv2['lyrics'][1], $lv2['chordpro'][1] ), array( 'Hello there', 'chords_lyrics', '' ) );
$lv3 = Songpress_Songs::lyric_levels( (object) array( 'chordpro' => $cpx, 'chords_lyrics' => '', 'lyrics' => 'my own words' ) );
check( 'a level that was typed is kept', array( $lv3['lyrics'][0], $lv3['lyrics'][1] ), array( 'my own words', 'own' ) );
check( 'nothing at all', Songpress_Songs::lyric_levels( (object) array( 'chordpro' => '', 'chords_lyrics' => '', 'lyrics' => '' ) )['lyrics'], array( '', '' ) );

// Note files
$bn = Songpress_Journal::parse_note_file( 'wine.bear', "# Wine and Bread\nAdam Page\nG | 90bpm | 8/04/2007\n\nVerse 1\nyou said this wine" );
check( 'bear note parsed', array( $bn['title'], $bn['authors'], $bn['song_key'], $bn['tempo'], $bn['date'], $bn['notes'] ), array( 'Wine and Bread', 'Adam Page', 'G', '90bpm', '2007-08-04', "\nVerse 1\nyou said this wine" ) );
$fm = Songpress_Journal::parse_note_file( 'x.md', "---\ntitle: Idea\ndate: 2026-01-02\nkind: idea\npinned: true\nkey: Am\ntempo: 100\n---\nhello ^0378" );
check( 'front matter note parsed', array( $fm['title'], $fm['date'], $fm['kind'], $fm['pinned'], $fm['song_key'], $fm['notes'] ), array( 'Idea', '2026-01-02', 'idea', 1, 'Am', 'hello ^0378' ) );
$pl = Songpress_Journal::parse_note_file( 'My Thought.txt', "# Heading\nbody here" );
$pl2 = Songpress_Journal::parse_note_file( 'My Thought.txt', "just words" );
check( 'plain notes take the heading or the file name', array( $pl['title'], $pl['notes'], $pl2['title'], $pl2['notes'] ), array( 'Heading', 'body here', 'My Thought', 'just words' ) );
check( 'adding a note once, then again', array( Songpress_Journal::add_note( $bn, 'file:t1' ), Songpress_Journal::add_note( $bn, 'file:t1' ) ), array( true, false ) );
$row = $GLOBALS['wpdb']->get_row( "SELECT * FROM wp_songpress_journal WHERE source_key='file:t1'" );
check( 'bear note stored with key, tempo, authors', array( $row->log_date, $row->song_key, $row->tempo, $row->authors ), array( '2007-08-04', 'G', '90bpm', 'Adam Page' ) );
$md = Songpress_Journal::note_to_md( $row );
$back = Songpress_Journal::parse_note_file( 'a.md', $md );
check( 'md export round-trips', array( $back['title'], $back['date'], $back['song_key'], $back['tempo'], $back['authors'] ), array( 'Wine and Bread', '2007-08-04', 'G', '90bpm', 'Adam Page' ) );

$bear = Songpress_Journal::note_to_bear( $row );
$bb = Songpress_Journal::parse_note_file( 'a.bear', $bear );
check( 'bear export round-trips', array( $bb['title'], $bb['authors'], $bb['song_key'], $bb['tempo'], $bb['date'] ), array( 'Wine and Bread', 'Adam Page', 'G', '90bpm', '2007-08-04' ) );
$nb = $row; $nb->authors = ''; $nb->song_key = ''; $nb->tempo = '';
$bb2 = Songpress_Journal::parse_note_file( 'b.bear', Songpress_Journal::note_to_bear( $nb ) );
check( 'bear export without authors still reads back as bear', array( $bb2['title'], $bb2['date'], trim( $bb2['notes'] ) ), array( 'Wine and Bread', '2007-08-04', trim( $row->notes ) ) );

// Notes screen actions
$r1 = Songpress_Journal::ajax_op( array( 'op' => 'save', 'title' => 'Screen note', 'notes' => "hello #idea\nand ^0378", 'log_date' => '2026-05-06', 'song_key' => 'D', 'tempo' => '88', 'authors' => 'Adam Page', 'created_at' => '2026-05-06T10:30', 'kind' => 'note' ) );
check( 'notes screen: save new', array( $r1['ok'], $r1['note']['title'], $r1['note']['key'], $r1['note']['created'], $r1['note']['date'] ), array( true, 'Screen note', 'D', '2026-05-06T10:30', '2026-05-06' ) );
$nid = $r1['note']['id'];
$r2 = Songpress_Journal::ajax_op( array( 'op' => 'save', 'entry_id' => $nid, 'title' => 'Screen note 2', 'notes' => 'changed', 'log_date' => '2026-05-07' ) );
check( 'notes screen: save existing', array( $r2['note']['id'], $r2['note']['title'], $r2['note']['date'] ), array( $nid, 'Screen note 2', '2026-05-07' ) );
check( 'notes screen: empty save refused', Songpress_Journal::ajax_op( array( 'op' => 'save' ) )['ok'], false );
check( 'notes screen: pin then archive', array( Songpress_Journal::ajax_op( array( 'op' => 'flag', 'entry_id' => $nid, 'flag' => 'pinned' ) )['note']['pinned'], Songpress_Journal::ajax_op( array( 'op' => 'flag', 'entry_id' => $nid, 'flag' => 'archived' ) )['note']['archived'] ), array( 1, 1 ) );
check( 'notes screen: unknown action', Songpress_Journal::ajax_op( array( 'op' => 'zzz' ) )['ok'], false );
check( 'notes screen: delete', array( Songpress_Journal::ajax_op( array( 'op' => 'delete', 'entry_id' => $nid ) )['deleted'], $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Songpress_Install::table( 'journal' ) . ' WHERE id = ' . (int) $nid ) ), array( $nid, 0 ) );

// Activity log
check( 'audit: known action', Songpress_Audit::classify( 'songpress_session_save' ), array( 'sessions', 'Saved a session' ) );
check( 'audit: money action by prefix', Songpress_Audit::classify( 'songpress_money_import' ), array( 'money', 'Import' ) );
check( 'audit: unknown songpress action', Songpress_Audit::classify( 'songpress_zip_zap' ), array( 'other', 'Zip zap' ) );
check( 'audit: ajax and foreign actions skipped', array( Songpress_Audit::classify( 'songpress_journal_ajax' ), Songpress_Audit::classify( 'save-post' ) ), array( null, null ) );
check( 'audit: only identifying fields kept', Songpress_Audit::pick( array( 'title' => 'T', 'password' => 'x', 'pass1' => 'y', 'entry_id' => '4', 'empty' => '' ) ), array( 'entry_id' => '4', 'title' => 'T' ) );
check( 'audit: detail read back', Songpress_Audit::describe_detail( '{"entry_id":"4","title":["a","b"]}' ), 'entry_id: 4 · title: a → b' );
$before = Songpress_Audit::rows( '', 0, '', 1 )['total'];
Songpress_Audit::log( 'people', 'Renamed', 'Zed', array( 'login' => 'zed' ), 7 );
$ar = Songpress_Audit::rows( 'people', 7, 'Zed', 1 );
check( 'audit: log then find by area, user and search', array( $ar['total'], $ar['rows'][0]->action, (int) $ar['rows'][0]->user_id ), array( 1, 'Renamed', 7 ) );
check( 'audit: total grew by one', Songpress_Audit::rows( '', 0, '', 1 )['total'], $before + 1 );
$an = Songpress_Audit::rows( 'notes', 0, '', 1 );
check( 'audit: note actions from the Notes screen were recorded', $an['total'] >= 4, true );

// Labels in the export, search and sort
$lb = Songpress_Songs::create( array( 'title' => 'Label Test Song', 'status' => 'writing', 'lyrics' => 'la' ), 1 );
Songpress_Songs::replace_credits( $lb, array( array( 'role' => 'writer', 'name' => 'Lab Writer', 'share' => '100' ), array( 'role' => 'label', 'name' => 'Zebra Sound Co', 'share' => '' ) ) );
$lh = Songpress_Songs::export_header();
$lr = Songpress_Songs::export_row( Songpress_Songs::get( $lb ) );
check( 'export has a labels column after publishers', array( $lh[ array_search( 'labels', $lh ) - 1 ], $lr[ array_search( 'labels', $lh ) ] ), array( 'publishers', 'Zebra Sound Co' ) );
check( 'export columns still match', count( $lr ), count( $lh ) );
$ls = Songpress_Songs::query( array( 'search' => 'Zebra Sou', 'archived' => 'any' ) );
check( 'search finds songs by label', array_map( function ( $r ) { return (int) $r->id; }, $ls['rows'] ), array( $lb ) );
$lo = Songpress_Songs::query( array( 'order' => 'label', 'dir' => 'desc', 'archived' => 'any', 'per_page' => 100 ) );
check( 'sort by label puts the labelled song first when descending', (int) $lo['rows'][0]->id, $lb );

// Old song fields that now have a home
$mm = Songpress_Import_Analyzer::map_row( array( 'id' => 900, 'serial_number' => '0900', 'song_title' => 'Old Fields', 'copyright_date' => '2020-01-02', 'production_date' => '2026-04-07', 'format' => 'Single', 'style' => 'Folk', 'themes' => 'home; road', 'ai_prompt' => 'warm folk', 'art_prompt' => 'a lighthouse' ) );
check( 'importer reads the old extra fields', array( $mm['song']['copyright_date'], $mm['song']['production_date'], $mm['song']['format'], $mm['song']['style'], $mm['song']['themes'], $mm['song']['ai_prompt'], $mm['song']['art_prompt'] ), array( '2020-01-02', '2026-04-07', 'Single', 'Folk', 'home; road', 'warm folk', 'a lighthouse' ) );
$of = Songpress_Songs::create( array( 'title' => 'Old Fields Song', 'status' => 'writing', 'lyrics' => 'la', 'style' => 'Typed style' ), 1 );
$wpdb->update( 'wp_songpress_songs', array( 'legacy_id' => 9001 ), array( 'id' => $of ) );
check( 'backfill fills blanks only', array( Songpress_Songs::fill_blank( 9001, array( 'style' => 'Folk', 'themes' => 'home', 'production_date' => '2026-04-07', 'format' => '' ) ), Songpress_Songs::get( $of )->style, Songpress_Songs::get( $of )->themes, Songpress_Songs::get( $of )->production_date ), array( 1, 'Typed style', 'home', '2026-04-07' ) );
check( 'backfill twice changes nothing', Songpress_Songs::fill_blank( 9001, array( 'style' => 'Folk', 'themes' => 'home', 'production_date' => '2026-04-07' ) ), 0 );
Songpress_Songs::update( $of, array( 'ai_prompt' => "line one\nline two", 'copyright_date' => '2019-05-05' ) );
check( 'prompt keeps its line breaks', Songpress_Songs::get( $of )->ai_prompt, "line one\nline two" );
$ov = Songpress_Songs::create_version( $of, 1 );
check( 'new version keeps copyright but starts without its own style and production date', array( Songpress_Songs::get( $ov )->style, Songpress_Songs::get( $ov )->copyright_date, Songpress_Songs::get( $ov )->production_date ), array( '', '2019-05-05', '' ) );
$ro = Songpress_Songs::create( array( 'title' => 'Rule Song', 'status' => 'writing', 'style' => 'S', 'tempo' => '120', 'notes' => 'n', 'lyrics' => 'x' ), 1 );
$rv = Songpress_Songs::create_version( $ro, 1 );
Songpress_Songs::update( $rv, array( 'style' => 'S', 'tempo' => '99', 'notes' => 'n' ) );
$rn = Songpress_Songs::apply_version_rules();
$rg = Songpress_Songs::get( $rv );
check( 'catch-up clears untouched copies, keeps edits and shared fields', array( $rn >= 1, $rg->style, $rg->tempo, $rg->notes, $rg->lyrics, Songpress_Songs::get( $ro )->style ), array( true, '', '99', '', 'x', 'S' ) );
check( 'catch-up twice changes nothing', Songpress_Songs::apply_version_rules(), 0 );
$sa = Songpress_Songs::create( array( 'title' => 'Sync Song', 'status' => 'writing', 'lyrics' => 'one', 'themes' => 'a', 'tempo' => '100' ), 1 );
Songpress_Songs::replace_credits( $sa, array( array( 'role' => 'writer', 'name' => 'Ann', 'share' => 100 ), array( 'role' => 'performer', 'name' => 'Pat' ) ) );
$sb = Songpress_Songs::create_version( $sa, 1 );
Songpress_Songs::replace_credits( $sb, array( array( 'role' => 'writer', 'name' => 'Ann', 'share' => 100 ), array( 'role' => 'performer', 'name' => 'Vee' ) ) );
Songpress_Songs::update( $sb, array( 'tempo' => '77' ) );
Songpress_Songs::update( $sa, array( 'title' => 'Sync Song II', 'lyrics' => 'two' ) );
Songpress_Songs::replace_credits( $sa, array( array( 'role' => 'writer', 'name' => 'Ann', 'share' => 60 ), array( 'role' => 'writer', 'name' => 'Bo', 'share' => 40 ), array( 'role' => 'label', 'name' => 'Lab' ) ) );
$sn = Songpress_Songs::sync_shared( $sa );
$sg = Songpress_Songs::get( $sb );
$sw = array(); $sp = array();
foreach ( Songpress_Songs::credits( $sb ) as $c ) { if ( 'performer' === $c->role ) { $sp[] = $c->name; } else { $sw[] = $c->role . ':' . $c->name; } }
sort( $sw );
check( 'editing the original copies shared fields to versions, keeps their own', array( $sn, $sg->title, $sg->lyrics, $sg->tempo, $sw, $sp ), array( 1, 'Sync Song II', 'two', '77', array( 'label:Lab', 'writer:Ann', 'writer:Bo' ), array( 'Vee' ) ) );
check( 'sync twice changes nothing', Songpress_Songs::sync_shared( $sa ), 0 );
Songpress_Songs::update( $sb, array( 'story' => 'from version' ) );
Songpress_Songs::sync_shared( $sb );
check( 'editing a version also updates the original', Songpress_Songs::get( $sa )->story, 'from version' );
$da = Songpress_Songs::create( array( 'title' => 'Code Song', 'status' => 'writing' ), 1 );
$db_ = Songpress_Songs::create_version( $da, 1 );
Songpress_Songs::replace_identifiers( $db_, array( array( 'kind' => 'distrokid', 'value' => 'DK9' ), array( 'kind' => 'isrc', 'value' => 'US9' ) ), 1 );
Songpress_Songs::replace_identifiers( $da, array( array( 'kind' => 'ascap', 'value' => 'A1' ), array( 'kind' => 'distrokid', 'value' => 'ROOTDK' ), array( 'kind' => 'upc', 'value' => 'U1' ) ), 0 );
Songpress_Songs::sync_shared( $da );
$dkv = array(); foreach ( Songpress_Songs::identifiers( $db_ ) as $i ) { $dkv[ $i->kind ] = $i->value; } ksort( $dkv );
check( 'ASCAP is shared; DistroKid, UPC and ISRC stay on the version and are dropped from the original', array( wp_ids( $da ), $dkv ), array( array( 'ascap' ), array( 'ascap' => 'A1', 'distrokid' => 'DK9', 'isrc' => 'US9' ) ) );
$ta = Songpress_Songs::create( array( 'title' => 'Root Name', 'status' => 'writing' ), 1 );
$tb = Songpress_Songs::create_version( $ta, 1 );
$tc = Songpress_Songs::create_version( $ta, 1 );
Songpress_Songs::update( $tb, array( 'title' => 'Root Name (Acoustic)' ) );
Songpress_Songs::update( $tc, array( 'title' => 'Other', 'release_title' => 'Kept Release' ) );
$tn = Songpress_Songs::unify_titles();
check( 'versions take the original title; their own name moves to the release title when empty', array( $tn >= 2, Songpress_Songs::get( $tb )->title, Songpress_Songs::get( $tb )->release_title, Songpress_Songs::get( $tc )->title, Songpress_Songs::get( $tc )->release_title ), array( true, 'Root Name', 'Root Name (Acoustic)', 'Root Name', 'Kept Release' ) );
check( 'unify twice changes nothing', Songpress_Songs::unify_titles(), 0 );
$oh = Songpress_Songs::export_header(); $or = Songpress_Songs::export_row( Songpress_Songs::get( $of ) );
check( 'export has the extra fields', array( $or[ array_search( 'themes', $oh ) ], $or[ array_search( 'copyright_date', $oh ) ], count( $or ) === count( $oh ) ), array( 'home', '2019-05-05', true ) );

// Statuses and genres come from the built-in options and from what works carry. There is no Lists screen.
check( 'statuses are the built-in list with the old editor options', array_keys( Songpress_Songs::statuses() ), array( 'idea', 'writing', 'finished', 'recorded', 'released', 'retired', 'starter', 'scrapped', 'scratch', 'studio_demo', 'pre_production', 'production', 'post_production', 'taken_down', 'outside' ) );
$cs  = Songpress_Songs::sanitize( array( 'status' => 'writing' ) );
$cs2 = Songpress_Songs::sanitize( array( 'status' => 'nonsense' ) );
check( 'an unknown status falls back to idea on save, a known one stays', array( $cs['status'], $cs2['status'] ), array( 'writing', 'idea' ) );
check( 'merge_options adds new values, ignores case repeats and blanks', Songpress_Songs::merge_options( array( 'Folk', 'Pop' ), array( 'folk', 'Surf Rock', '', 'surf rock', ' Ska ' ) ), array( 'Folk', 'Pop', 'Surf Rock', 'Ska' ) );
check( 'genres start as the old editor list', Songpress_Songs::genres(), Songpress_Songs::GENRES );
$gid = Songpress_Songs::update( $v3, array( 'genre' => 'Surf Rock' ) );
check( 'a genre typed on a work joins the list', in_array( 'Surf Rock', Songpress_Songs::genres(), true ), true );
Songpress_Songs::update( $v3, array( 'genre' => '' ) );
check( 'it leaves the list when no work has it', in_array( 'Surf Rock', Songpress_Songs::genres(), true ), false );

// Registration start dates
$rf = Songpress_Songs::get( $of )->family_id;
$u0 = Songpress_Registrations::undated_count();
Songpress_Registrations::mark( $rf, 'mlc', true, '2026-01-05', '', '' );
$wpdb->insert( 'wp_songpress_registrations', array( 'family_id' => $rf, 'society' => 'ascap', 'in_progress' => 1, 'started_on' => null, 'reference' => '', 'note' => '', 'updated_at' => '2026-01-01 00:00:00', 'updated_by' => 0 ) );
check( 'one more undated in-progress registration counted', Songpress_Registrations::undated_count(), $u0 + 1 );
check( 'bad date changes nothing', array( Songpress_Registrations::date_undated( 'soon' ), Songpress_Registrations::undated_count() ), array( 0, $u0 + 1 ) );
check( 'dating undated fills it', array( Songpress_Registrations::date_undated( '2026-02-02' ), Songpress_Registrations::undated_count() ), array( $u0 + 1, 0 ) );
check( 'a date you entered is kept', $wpdb->get_var( "SELECT started_on FROM wp_songpress_registrations WHERE family_id = $rf AND society = 'mlc'" ), '2026-01-05' );

// Bear TextBundle
$fresh = clone $row; $fresh->authors = 'Adam Page'; $fresh->song_key = 'G'; $fresh->tempo = '90bpm';
$tb = Songpress_Journal::note_to_textbundle( $fresh );
$ti = json_decode( $tb['info'], true );
check( 'textbundle info.json', array( $ti['version'], $ti['type'] ), array( 2, 'net.daringfireball.markdown' ) );
$tbr = Songpress_Journal::parse_note_file( 'x.md', $tb['text'] );
check( 'textbundle text reads back', array( $tbr['title'], $tbr['authors'], $tbr['song_key'], $tbr['date'] ), array( 'Wine and Bread', 'Adam Page', 'G', '2007-08-04' ) );
$un = clone $row; $un->title = ''; $un->kind = 'day';
check( 'untitled note is named after its date', strtok( Songpress_Journal::note_to_textbundle( $un )['text'], "\n" ), '# Day 2007-08-04' );

// Backups and health
check( 'risky actions', array( Songpress_Backup::is_risky( 'songpress_statement' ), Songpress_Backup::is_risky( 'songpress_money_import' ), Songpress_Backup::is_risky( 'songpress_journal_save' ) ), array( true, true, false ) );
check( 'backup names are checked', array( Songpress_Backup::valid_name( 'backup-20261122-010203-before-x.json.gz' ), Songpress_Backup::valid_name( '../etc/passwd' ), Songpress_Backup::valid_name( 'backup-1.json.gz' ) ), array( true, false, false ) );
check( 'prune keeps the newest', Songpress_Backup::to_prune( array( 'backup-20260101-000000.json.gz', 'backup-20260301-000000.json.gz', 'backup-20260201-000000.json.gz' ), 2 ), array( 'backup-20260101-000000.json.gz' ) );
check( 'health sizes', array( Songpress_Health::bytes( '128M' ), Songpress_Health::bytes( '2G' ), Songpress_Health::bytes( '512k' ), Songpress_Health::bytes( '' ) ), array( 134217728, 2147483648, 524288, 0 ) );
check( 'health only keeps our own fatal errors', array( Songpress_Health::is_ours( array( 'type' => E_ERROR, 'file' => SONGPRESS_DIR . 'includes/x.php' ), SONGPRESS_DIR ), Songpress_Health::is_ours( array( 'type' => E_WARNING, 'file' => SONGPRESS_DIR . 'includes/x.php' ), SONGPRESS_DIR ), Songpress_Health::is_ours( array( 'type' => E_ERROR, 'file' => '/other/plugin.php' ), SONGPRESS_DIR ), Songpress_Health::is_ours( null, SONGPRESS_DIR ) ), array( true, false, false, false ) );
$songs_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_songpress_songs' );
$cred_before  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_songpress_credits' );
$null_before  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_songpress_songs WHERE share_token IS NULL' );
$bf = Songpress_Backup::snapshot( 'Test snapshot' );
check( 'a snapshot is written and listed', array( '' !== $bf, Songpress_Backup::all()[0]['file'] === $bf, Songpress_Backup::all()[0]['counts']['songs'] ), array( true, true, $songs_before ) );
$wpdb->query( 'DELETE FROM wp_songpress_songs' );
$wpdb->query( 'DELETE FROM wp_songpress_credits' );
$audit_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_songpress_audit' );
$rr = Songpress_Backup::restore( $bf );
check( 'restore puts the songs and credits back', array( $rr[0], (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_songpress_songs' ), (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_songpress_credits' ) ), array( true, $songs_before, $cred_before ) );
check( 'restore keeps NULL as NULL', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_songpress_songs WHERE share_token IS NULL' ), $null_before );
check( 'restore saved how things were first, and the log grew', array( count( Songpress_Backup::all() ) >= 2, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_songpress_audit' ) > $audit_before ), array( true, true ) );
$bad = Songpress_Backup::dir() . '/backup-20200101-000000-old.json.gz';
file_put_contents( $bad, gzencode( json_encode( array( 'v' => 1, 'db' => '1', 'tables' => array( 'songs' => array( 'cols' => array(), 'rows' => array() ) ) ) ) ) );
check( 'a backup from another database version is refused', array( Songpress_Backup::restore( 'backup-20200101-000000-old.json.gz' )[0], (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_songpress_songs' ) ), array( false, $songs_before ) );
check( 'a missing backup is refused', Songpress_Backup::restore( 'backup-20200101-000001-none.json.gz' )[0], false );

// Name check
check( 'names: normalize drops case, the, accents and punctuation', array( Songpress_Names::normalize( 'The Café-Band!' ), Songpress_Names::normalize( '  A  Song ' ) ), array( 'cafeband', 'song' ) );
check( 'names: same, close, different', array( Songpress_Names::compare( 'The Redbeards', 'redbeards' ), Songpress_Names::compare( 'Redbeards', 'Redbeerds' ), Songpress_Names::compare( 'Redbeards', 'Bluebeards' ), Songpress_Names::compare( 'Echo', 'Ecko' ) ), array( 'same', 'close', '', '' ) );
check( 'names: blank never matches', Songpress_Names::compare( '', '' ), '' );
Songpress_Profiles::upsert( 'band', 'The Zephyr Hounds', array() );
Songpress_Profiles::upsert( 'publisher', 'Zephyr Hounds Music', array() );
$nm = Songpress_Names::matches( 'band', 'zephyr hounds' );
check( 'band check finds the same band, not the publisher', array( count( $nm ), $nm[0]['name'], $nm[0]['level'], $nm[0]['where'] ), array( 1, 'The Zephyr Hounds', 'same', 'band' ) );
$nm2 = Songpress_Names::matches( 'band', 'The Zephyr Houndz' );
check( 'band check finds a near miss', array( count( $nm2 ), $nm2[0]['level'] ), array( 1, 'close' ) );
$ex = (int) $wpdb->get_var( "SELECT id FROM wp_songpress_profiles WHERE name = 'The Zephyr Hounds'" );
check( 'editing a band does not match itself', Songpress_Names::matches( 'band', 'The Zephyr Hounds', $ex ), array() );
$sm = Songpress_Names::matches( 'song', 'harbor lights' );
check( 'song check finds an existing title', array( count( $sm ) >= 1, $sm[0]['level'] ), array( true, 'same' ) );
$fam = (int) $wpdb->get_var( "SELECT family_id FROM wp_songpress_songs WHERE title = 'Harbor Lights' AND version_no = 0 LIMIT 1" );
check( 'editing a song does not match its own family', count( array_filter( Songpress_Names::matches( 'song', 'Harbor Lights', $fam ), function ( $m ) { return 'same' === $m['level'] && false !== strpos( $m['where'], '0001' ); } ) ), 0 );
check( 'too short a name matches nothing', Songpress_Names::matches( 'song', '' ), array() );

$pi = Songpress_Art::parse_images( '{"data":[{"url":"https://x.ai/a.png"},{"b64_json":"QUJD"},{"url":"http://bad/x.png"},{"b64_json":"<script>"},"x"]}' );
check( 'AI reply: https url and base64 kept, junk dropped', $pi, array( 'https://x.ai/a.png', 'data:image/png;base64,QUJD' ) );
check( 'AI reply: garbage gives nothing', Songpress_Art::parse_images( 'nope' ), array() );
$xb = Songpress_Art::xai_bodies( 'a cat', 4 );
check( 'AI request tries two models, four pictures', array( count( $xb ), $xb[0]['n'], $xb[1]['model'] ), array( 2, 4, 'grok-imagine-image' ) );

list( $nv, $nerr ) = Songpress_Journal::persist( array( 'title' => 'Versioned', 'notes' => 'first draft', 'log_date' => '2026-01-01', 'lat' => '36.1627', 'lng' => '-86.7816' ) );
$nrow = $wpdb->get_row( 'SELECT * FROM ' . Songpress_Install::table( 'journal' ) . ' WHERE id = ' . (int) $nv );
check( 'note keeps a position', array( Songpress_Journal::shape( $nrow )['lat'], Songpress_Journal::shape( $nrow )['lng'] ), array( '36.1627', '-86.7816' ) );
Songpress_Journal::persist( array( 'entry_id' => $nv, 'title' => 'Versioned', 'notes' => 'second draft', 'lat' => '999', 'lng' => '5' ) );
$vs = Songpress_Journal::ajax_op( array( 'op' => 'versions', 'entry_id' => $nv ) )['versions'];
check( 'first edit keeps the old text', array( count( $vs ), $vs[0]['notes'] ), array( 1, 'first draft' ) );
check( 'a bad position is cleared', Songpress_Journal::shape( $wpdb->get_row( 'SELECT * FROM ' . Songpress_Install::table( 'journal' ) . ' WHERE id = ' . (int) $nv ) )['lat'], '' );
Songpress_Journal::persist( array( 'entry_id' => $nv, 'title' => 'Versioned', 'notes' => 'third draft' ) );
check( 'a quick second edit does not add another copy', count( Songpress_Journal::ajax_op( array( 'op' => 'versions', 'entry_id' => $nv ) )['versions'] ), 1 );
$wpdb->query( 'UPDATE ' . Songpress_Install::table( 'note_versions' ) . " SET saved_at = '2025-12-31 00:00:00'" );
Songpress_Journal::persist( array( 'entry_id' => $nv, 'title' => 'Versioned', 'notes' => 'fourth draft' ) );
check( 'after the gap another copy is kept', count( Songpress_Journal::ajax_op( array( 'op' => 'versions', 'entry_id' => $nv ) )['versions'] ), 2 );
Songpress_Journal::ajax_op( array( 'op' => 'delete', 'entry_id' => $nv ) );
check( 'deleting a note deletes its versions', count( Songpress_Journal::ajax_op( array( 'op' => 'versions', 'entry_id' => $nv ) )['versions'] ), 0 );

$pf = array( 'title' => 'Glass Houses', 'serial' => '0001', 'band' => 'Red Beard', 'writers' => array( array( 'name' => 'Ann', 'share' => '50' ), array( 'name' => 'Bo', 'share' => '50' ) ), 'publishers' => array( array( 'name' => 'Red Beard Music', 'share' => '100', 'ipi' => '' ) ), 'genre' => 'Americana', 'key' => 'G', 'tempo' => '92', 'isrc' => '', 'iswc' => '', 'pro' => 'BMI 123', 'audio' => 'https://x.test/a.mp3', 'art' => '', 'about' => "<p>Written after   the flood.</p>", 'status' => '', 'release' => '', 'wav' => 'https://x.test/a.wav', 'clean' => '', 'instr' => '', 'stems' => '', 'photo' => '', 'bio_url' => '', 'bio' => 'A Nashville band.', 'mood' => 'warm', 'contact' => 'me@x.test', 'explicit' => 0 );
check( 'radio copy names the song, band, story, bio and downloads', array( strpos( Songpress_Pitch::radio_copy( $pf ), '"Glass Houses" by Red Beard' ) !== false, strpos( Songpress_Pitch::radio_copy( $pf ), 'Written after the flood.' ) !== false, strpos( Songpress_Pitch::radio_copy( $pf ), 'Download (WAV): https://x.test/a.wav' ) !== false, strpos( Songpress_Pitch::radio_copy( $pf ), 'A Nashville band.' ) !== false ), array( true, true, true, true ) );
check( 'one-sheet lists writers, publisher and contact', array( strpos( Songpress_Pitch::onesheet( $pf ), 'Ann (50%); Bo (50%)' ) !== false, strpos( Songpress_Pitch::onesheet( $pf ), 'Publisher: Red Beard Music (100%)' ) !== false, strpos( Songpress_Pitch::onesheet( $pf ), 'Contact: me@x.test' ) !== false ), array( true, true, true ) );
$rd = Songpress_Pitch::readiness( $pf );
check( 'readiness flags missing art and ISRC, passes the WAV', array( $rd[0][0], $rd[2][0], $rd[9][0], $rd[7][0] ), array( true, false, false, true ) );
check( 'an explicit song needs a clean version', end( Songpress_Pitch::readiness( array_merge( $pf, array( 'explicit' => 1 ) ) ) )[1], 'Clean version' );
check( 'every missing item explains itself', count( array_filter( $rd, function ( $r ) { return '' === $r[3]; } ) ), 0 );
check( 'channels carry a price badge, free first', array( Songpress_Pitch::channel_groups()[0]['items'][0]['tier'], end( Songpress_Pitch::channel_groups()[0]['items'] )['tier'] ), array( 'free', 'paid' ) );
check( 'draft bio uses the band and genre', strpos( Songpress_Pitch::draft_bio( $pf ), 'Red Beard makes Americana' ) === 0, true );
check( 'channels pick the right words', array( Songpress_Pitch::copy_key( 'submithub', 'playlists' ), Songpress_Pitch::copy_key( 'x', 'radio' ), Songpress_Pitch::copy_key( 'x', 'sync' ), Songpress_Pitch::copy_key( 'x', 'christian' ) ), array( 'email', 'radio', 'onesheet', 'email' ) );
Songpress_Pitch::save_kit( 1, array( 'wav' => 'https://x.test/m.wav', 'bio' => 'Hello band', 'contact' => 'me' ) );
Songpress_Pitch::save_kit( 1, array( 'mood' => 'warm', 'explicit_set' => 1, 'explicit' => 1 ) );
$kk = Songpress_Pitch::kit( 1 );
check( 'kit saves only what was sent', array( $kk['wav'], $kk['bio'], $kk['mood'], $kk['explicit'] ), array( 'https://x.test/m.wav', 'Hello band', 'warm', 1 ) );
check( 'the last bio is offered to the next work', Songpress_Pitch::last_bio(), 'Hello band' );
$wpdb->query( "UPDATE " . Songpress_Install::table( 'songs' ) . " SET artist = 'Red Beard' WHERE id = 1" );
check( 'a band reuses its own bio', array( Songpress_Pitch::band_bio( 'Red Beard' ), Songpress_Pitch::band_bio( 'Nobody' ), Songpress_Pitch::band_bio( '' ) ), array( 'Hello band', '', '' ) );
check( 'outcome falls back to open', Songpress_Pitch::outcome( 'bogus' ), 'open' );
check( 'a pitch needs a work and a destination', array( Songpress_Pitch::add( array( 'song_id' => 0, 'sent_to' => 'WXYZ' ) ), Songpress_Pitch::add( array( 'song_id' => 1 ) ) ), array( 0, 0 ) );
$pid = Songpress_Pitch::add( array( 'song_id' => 1, 'channel' => 'spotify', 'sent_to' => 'WXYZ', 'sent_at' => '2026-01-01', 'follow_up' => '2026-01-10' ) );
check( 'pitch is logged', $pid > 0, true );
check( 'follow-up shows when due', array( count( Songpress_Pitch::rows( 'due', 0, '2026-01-09' ) ), count( Songpress_Pitch::rows( 'due', 0, '2026-01-10' ) ) ), array( 0, 1 ) );
Songpress_Pitch::update( $pid, array( 'outcome' => 'open', 'follow_up' => '2026-01-10', 'sent_to' => 'KEXP', 'channel' => 'nacc', 'notes' => '' ) );
check( 'a pitch can be re-pointed', array( Songpress_Pitch::rows( 'all' )[0]->sent_to, Songpress_Pitch::rows( 'all' )[0]->channel ), array( 'KEXP', 'nacc' ) );
Songpress_Pitch::update( $pid, array( 'outcome' => 'placed', 'follow_up' => '', 'notes' => 'added' ) );
check( 'a placed pitch is no longer due or waiting', array( count( Songpress_Pitch::rows( 'due', 0, '2026-02-01' ) ), count( Songpress_Pitch::rows( 'open' ) ), count( Songpress_Pitch::rows( 'all' ) ) ), array( 0, 0, 1 ) );
Songpress_Pitch::delete( $pid );
check( 'a pitch can be removed', count( Songpress_Pitch::rows( 'all' ) ), 0 );

// History: version made, scheduled, released
$hm = array_map( fn( $r ) => $r->action, Songpress_History::for_song( $v1, 50 ) );
check( 'history notes a new version on the work it came from', in_array( 'version_made', $hm, true ), true );
Songpress_Songs::update( $v3, array( 'release_date' => '2030-05-05' ) );
Songpress_Songs::update( $v3, array( 'status' => 'released' ) );
$hr = Songpress_History::for_song( $v3, 50 );
$ha = array_map( fn( $r ) => $r->action, $hr );
check( 'history notes scheduled and released', array( in_array( 'scheduled', $ha, true ), in_array( 'released', $ha, true ) ), array( true, true ) );
$sch = array_values( array_filter( $hr, fn( $r ) => 'scheduled' === $r->action ) )[0];
check( 'scheduled line reads with the date', Songpress_History::describe( $sch ), 'Scheduled for release on 2030-05-05' );

exec( 'rm -rf ' . escapeshellarg( $GLOBALS['sp_tmp'] ) );
echo $fail ? "\n$fail of $n failed\n" : "All $n checks passed\n";
exit( $fail ? 1 : 0 );
