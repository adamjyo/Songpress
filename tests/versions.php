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
function get_current_user_id() { return 7; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_unslash( $s ) { return $s; }
function absint( $n ) { return abs( (int) $n ); }
$GLOBALS['opts'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $s ) ), '-' ); }
function get_bloginfo( $k = '' ) { return 'Test Site'; }

class FakeWpdb {
	public $prefix = 'wp_', $insert_id = 0; public $pdo;
	function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' ); $this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_families (id INTEGER PRIMARY KEY AUTOINCREMENT, family_no INT UNIQUE, created_at TEXT)' );
		$cols = 'family_id INT, version_no INT, revision INT DEFAULT 0, serial TEXT UNIQUE, title TEXT, alt_titles TEXT, status TEXT, stage TEXT, writing_date TEXT, musical_key TEXT, tempo TEXT, time_signature TEXT, genre TEXT, artist TEXT, album TEXT, release_title TEXT, release_date TEXT, lyrics TEXT, chords_lyrics TEXT, chordpro TEXT, story TEXT, notes TEXT, audio_url TEXT, art_id INT, audio_id INT, art_url TEXT, owner_id INT, artist_id INT DEFAULT 0, archived_at TEXT, share_token TEXT UNIQUE, created_at TEXT, updated_at TEXT';
		$this->pdo->exec( "CREATE TABLE wp_songpress_songs (id INTEGER PRIMARY KEY AUTOINCREMENT, $cols)" );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_credits (id INTEGER PRIMARY KEY AUTOINCREMENT, song_id INT, role TEXT, name TEXT, ipi TEXT, share REAL, user_id INT, position INT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_identifiers (id INTEGER PRIMARY KEY AUTOINCREMENT, song_id INT, kind TEXT, value TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_history (id INTEGER PRIMARY KEY AUTOINCREMENT, song_id INT, user_id INT, action TEXT, detail TEXT, created_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_songpress_artists (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, slug TEXT UNIQUE, settings TEXT, created_at TEXT)' );
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
require __DIR__ . '/../includes/class-media.php';
require __DIR__ . '/../includes/class-songs.php';

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

echo $fail ? "\n$fail of $n failed\n" : "All $n checks passed\n";
exit( $fail ? 1 : 0 );
