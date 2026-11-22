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
		$cols = 'family_id INT, version_no INT, revision INT DEFAULT 0, serial TEXT UNIQUE, legacy_id INT, title TEXT, alt_titles TEXT, status TEXT, stage TEXT, writing_date TEXT, musical_key TEXT, tempo TEXT, time_signature TEXT, genre TEXT, artist TEXT, album TEXT, release_title TEXT, release_date TEXT, lyrics TEXT, chords_lyrics TEXT, chordpro TEXT, story TEXT, notes TEXT, audio_url TEXT, art_id INT, audio_id INT, art_url TEXT, owner_id INT, artist_id INT DEFAULT 0, archived_at TEXT, share_token TEXT UNIQUE, created_at TEXT, updated_at TEXT';
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
		$this->pdo->exec( 'CREATE TABLE wp_songpress_journal (id INTEGER PRIMARY KEY AUTOINCREMENT, log_date TEXT, kind TEXT, title TEXT, notes TEXT, place TEXT, song_id INT DEFAULT 0, pinned INT DEFAULT 0, archived INT DEFAULT 0, source_key TEXT UNIQUE, user_id INT, created_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_ratings (id INTEGER PRIMARY KEY AUTOINCREMENT, song_id INT, voter TEXT, stars INT, created_at TEXT, UNIQUE(song_id,voter))' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_art (id INTEGER PRIMARY KEY AUTOINCREMENT, song_id INT DEFAULT 0, name TEXT, layers TEXT, preview_id INT DEFAULT 0, preview_url TEXT, source_key TEXT UNIQUE, user_id INT, created_at TEXT, updated_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_agreements (id INTEGER PRIMARY KEY AUTOINCREMENT, song_id INT, serial TEXT, kind TEXT, title TEXT, body TEXT, print_token TEXT UNIQUE, source_key TEXT UNIQUE, user_id INT, created_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_signers (id INTEGER PRIMARY KEY AUTOINCREMENT, agreement_id INT, name TEXT, role TEXT, share TEXT, token TEXT UNIQUE, signed_as TEXT, signed_at TEXT, ip TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_registrations (id INTEGER PRIMARY KEY AUTOINCREMENT, family_id INT, society TEXT, in_progress INT DEFAULT 0, started_on TEXT, reference TEXT, note TEXT, updated_at TEXT, updated_by INT, UNIQUE(family_id,society))' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_links (id INTEGER PRIMARY KEY AUTOINCREMENT, song_id INT, kind TEXT, url TEXT)' );
	}
	function prepare( $sql, ...$a ) {
		if ( 1 === count( $a ) && is_array( $a[0] ) ) { $a = $a[0]; }
		$i = 0; return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $a ) { $v = $a[ $i++ ]; return '%d' === $m[0] ? (int) $v : $this->pdo->quote( (string) $v ); }, $sql );
	}
	function esc_like( $s ) { return $s; }
	function get_var( $sql ) { if ( false !== strpos( $sql, 'GET_LOCK' ) ) { return 1; } $r = $this->pdo->query( $sql )->fetchColumn(); return false === $r ? null : $r; }
	function get_row( $sql ) { return $this->pdo->query( $sql )->fetch( PDO::FETCH_OBJ ) ?: null; }
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
require __DIR__ . '/../includes/class-artists.php';
require __DIR__ . '/../includes/class-profiles.php';
require __DIR__ . '/../includes/class-media.php';
require __DIR__ . '/../includes/class-songs.php';
require __DIR__ . '/../includes/class-people.php';
require __DIR__ . '/../includes/class-money.php';
require __DIR__ . '/../includes/class-journal.php';
require __DIR__ . '/../includes/class-art.php';
require __DIR__ . '/../includes/class-agreements.php';
require __DIR__ . '/../includes/class-registrations.php';
require __DIR__ . '/../includes/class-uploads.php';
require __DIR__ . '/../includes/class-persons.php';

$fail = 0; $n = 0;
function check( $name, $got, $want ) { global $fail, $n; $n++; if ( $got !== $want ) { $fail++; echo "FAIL $name\n  got:  " . var_export( $got, true ) . "\n  want: " . var_export( $want, true ) . "\n"; } }
function people( $id, $role ) { $o = array(); foreach ( Songpress_Songs::credits( $id ) as $c ) { if ( $c->role === $role ) { $o[] = $c->name . ':' . ( null === $c->share ? '-' : (float) $c->share ); } } return $o; }
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
check( 'v3 keeps artist', Songpress_Songs::get( $v3 )->artist, 'The Band' );
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
check( 'archive version only', Songpress_Songs::archive( $v3 ), 1 );
check( 'archived hidden', in_array( $v3, $q( array() ) ), false );
check( 'archived listed when asked', in_array( $v3, $q( array( 'archived' => 1 ) ) ), true );
check( 'family list can include archived', in_array( $v3, $q( array( 'family_id' => Songpress_Songs::get( $v3 )->family_id, 'archived' => 'any' ) ) ), true );
Songpress_Songs::restore( $v3 );
check( 'restored shows again', in_array( $v3, $q( array() ) ), true );
check( 'archive original takes its versions', Songpress_Songs::archive( $v0 ), 4 );
check( 'nothing of that family left', in_array( $v1, $q( array() ) ), false );
$nv = Songpress_Songs::create_version( $v0, 1 );
check( 'new version gets next number', Songpress_Songs::get( $nv )->serial, '0001-04' );
$h = array_map( fn( $r ) => $r->action, Songpress_History::for_song( $v3 ) );
check( 'history has created or version', in_array( 'version', $h ), true );
check( 'history has edits', in_array( 'edited', $h ), true );
check( 'history has archive and restore', in_array( 'archived', $h ) && in_array( 'restored', $h ), true );
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
Songpress_Songs::restore( $v1 ); // the family was archived by an earlier check
$tok = Songpress_Songs::share_set( $v1, true );
check( 'token shape', (bool) preg_match( '/^[A-Za-z0-9]{24}$/', $tok ), true );
check( 'token finds song', (int) Songpress_Songs::by_token( $tok )->id, $v1 );
check( 'bad token finds nothing', Songpress_Songs::by_token( 'short' ), null );
$tok2 = Songpress_Songs::share_set( $v1, true );
check( 'new link kills old link', Songpress_Songs::by_token( $tok ), null );
check( 'new link works', (int) Songpress_Songs::by_token( $tok2 )->id, $v1 );
Songpress_Songs::archive( $v1 );
check( 'archived song not served', Songpress_Songs::by_token( $tok2 ), null );
Songpress_Songs::restore( $v1 );
Songpress_Songs::share_set( $v1, false );
check( 'sharing off', Songpress_Songs::by_token( $tok2 ), null );

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
	if ( 'catalog' === $k ) { continue; }
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
	2 => array( 'login' => 'bob.lee', 'display' => 'Bob Lee', 'admin' => false ),
	3 => array( 'login' => 'bob.lee2', 'display' => 'Bob Lee', 'admin' => false ),
	4 => array( 'login' => 'cy.wu', 'display' => 'Cy Wu', 'admin' => false ),
	5 => array( 'login' => 'cy.wu2', 'display' => 'Cy Wu Jr', 'admin' => false ),
	6 => array( 'login' => 'sam.kim2', 'display' => 'Sam Kim', 'admin' => true ),
) );
check( 'tidy: rename when free, merge same person, leave different person and admins', $pl, array( array( 'rename', 1, 'abby.russ', 0 ), array( 'merge', 3, 'bob.lee', 2 ) ) );

echo $fail ? "\n$fail of $n failed\n" : "All $n checks passed\n";
exit( $fail ? 1 : 0 );
