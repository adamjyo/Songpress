<?php
// Standalone tests for the pure classes. Run: php tests/run.php
define( 'SONGPRESS_TESTING', true );
require __DIR__ . '/../includes/class-serial.php';
require __DIR__ . '/../includes/class-import-analyzer.php';

$fail = 0; $n = 0;
function check( $name, $got, $want ) {
	global $fail, $n; $n++;
	if ( $got !== $want ) { $fail++; echo "FAIL $name\n  got:  " . var_export( $got, true ) . "\n  want: " . var_export( $want, true ) . "\n"; }
}

// serial
check( 'parse basic', Songpress_Serial::parse( '0012-03' ), array( 'family' => 12, 'version' => 3 ) );
check( 'parse short', Songpress_Serial::parse( '12-3' ), array( 'family' => 12, 'version' => 3 ) );
check( 'parse spaces', Songpress_Serial::parse( ' 0012 - 03 ' ), array( 'family' => 12, 'version' => 3 ) );
check( 'parse junk', Songpress_Serial::parse( 'ABC-01' ), null );
check( 'parse empty', Songpress_Serial::parse( '' ), null );
check( 'parse 3 parts', Songpress_Serial::parse( '1-2-3' ), null );
check( 'format original', Songpress_Serial::format( 7, 0 ), '0007' );
check( 'format version', Songpress_Serial::format( 7, 1 ), '0007-01' );
check( 'format big', Songpress_Serial::format( 2514, 12 ), '2514-12' );
check( 'normalize 7-0', Songpress_Serial::normalize( '7-0' ), '0007' );
check( 'normalize 0012-00', Songpress_Serial::normalize( '0012-00' ), '0012' );
check( 'normalize bare', Songpress_Serial::normalize( '12' ), '0012' );
check( 'normalize version', Songpress_Serial::normalize( '12-3' ), '0012-03' );
check( 'parse bare original', Songpress_Serial::parse( '0012' ), array( 'family' => 12, 'version' => 0 ) );
check( 'parse -00 is original', Songpress_Serial::parse( '0012-00' ), array( 'family' => 12, 'version' => 0 ) );

// status mapping
check( 'status released', Songpress_Import_Analyzer::map_status( 'Released' ), 'released' );
check( 'status empty', Songpress_Import_Analyzer::map_status( '' ), 'idea' );
check( 'status unknown', Songpress_Import_Analyzer::map_status( 'Shelved??' ), '' );
check( 'num pct', Songpress_Import_Analyzer::num( '33.5%' ), 33.5 );
check( 'num junk', Songpress_Import_Analyzer::num( 'half' ), null );

// sqlite dry run: a tiny catalog that has every kind of problem
$file = tempnam( sys_get_temp_dir(), 'sp' );
$pdo = new PDO( 'sqlite:' . $file );
$pdo->exec( 'CREATE TABLE song_catalog (id INTEGER PRIMARY KEY, serial_number TEXT, song_title TEXT, status TEXT, writer1_name TEXT, writer1_share TEXT, writer2_name TEXT, writer2_share TEXT, isrc TEXT, spotify_link TEXT, lyrics TEXT, lyrics_prochord TEXT, total_earnings TEXT)' );
$ins = $pdo->prepare( 'INSERT INTO song_catalog (serial_number,song_title,status,writer1_name,writer1_share,writer2_name,writer2_share,isrc,spotify_link,lyrics,lyrics_prochord,total_earnings) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)' );
foreach ( array(
	array( '0001-00', 'Harbor Lights', 'Released', 'Writer One', '50', 'Writer Two', '50', 'USX000000001', 'https://open.spotify.com/x', 'la la', '[Am]la', '12.50' ),
	array( '0001-01', 'Harbor Lights (acoustic)', 'Recorded', 'Writer One', '50', 'Writer Two', '50', '', '', '', '', '' ),
	array( '0001-03', 'Harbor Lights (live)', 'Draft', 'Writer One', '100', '', '', '', '', '', '', '' ),
	array( '0002-01', 'Orphan Version', 'idea', 'Writer Two', '40', '', '', '', '', '', '', '' ),
	array( '0003-00', '', 'Shelved??', '', '', '', '', '', '', '', '', '' ),
	array( '0004', 'Bare Serial Original', 'idea', 'Writer One', '100', '', '', '', '', '', '', '' ),
	array( 'XYZ', 'Bad Serial', 'idea', 'Writer One', '100', '', '', '', '', '', '', '' ),
	array( '0001-00', 'Duplicate', 'idea', 'Writer One', '100', '', '', '', '', '', '', '' ),
) as $r ) { $ins->execute( $r ); }

$rep = Songpress_Import_Analyzer::analyze( Songpress_Import_Analyzer::read_sqlite( $file ) );
check( 'rows', $rep['rows'], 8 );
check( 'families', $rep['families'], 4 );
check( 'versions', $rep['versions'], 6 );
check( 'no_title', $rep['no_title'], 1 );
check( 'bad_serial', $rep['bad_serial'], array( 'XYZ' ) );
check( 'duplicate', $rep['duplicate_serial'], array( '0001' ) );
check( 'unknown status', $rep['unknown_status'], array( 'Shelved??' => 1 ) );
check( 'gap', $rep['version_gaps'], array( '0001-01 to 0001-03' ) );
check( 'no v0', $rep['family_without_v0'], array( '0002' ) );
check( 'original with release', $rep['original_with_release'], array( '0001' ) );
check( 'share off', $rep['writer_share_off'], 1 );            // 0002-01 sums to 40
check( 'no writers', $rep['songs_without_writers'], 1 );      // 0003-00
check( 'top writer', array_key_first( $rep['writers'] ), 'Writer One' );

$m = Songpress_Import_Analyzer::map_row( $pdo->query( 'SELECT * FROM song_catalog WHERE id=1' )->fetch( PDO::FETCH_ASSOC ) );
check( 'map title', $m['song']['title'], 'Harbor Lights' );
check( 'map chordpro', $m['song']['chordpro'], '[Am]la' );
check( 'map writers', count( $m['credits'] ), 2 );
check( 'map ident', $m['identifiers'], array( array( 'kind' => 'isrc', 'value' => 'USX000000001' ) ) );
check( 'map link', $m['links'][0]['kind'], 'spotify' );
check( 'earnings not imported', isset( $m['song']['total_earnings'] ), false );
unlink( $file );

// More authors than the old file's 10 columns: the mapper reads every numbered column it is given.
$wide = array( 'serial_number' => '0100-00', 'song_title' => 'Big Group Song' );
for ( $i = 1; $i <= 14; $i++ ) { $wide[ "writer{$i}_name" ] = "Writer $i"; $wide[ "writer{$i}_share" ] = $i <= 13 ? '7' : '9'; }
$wide['performer6_name'] = 'Sixth Performer';
$mw = Songpress_Import_Analyzer::map_row( $wide );
$writers = array_filter( $mw['credits'], function ( $c ) { return 'writer' === $c['role']; } );
check( '14 writers mapped', count( $writers ), 14 );
check( 'writer 14 name', array_values( $writers )[13]['name'], 'Writer 14' );
check( 'performer 6', array_values( array_filter( $mw['credits'], function ( $c ) { return 'performer' === $c['role']; } ) )[0]['name'], 'Sixth Performer' );
check( 'numbers helper', Songpress_Import_Analyzer::numbers( array( 'writer2_name' => 'x', 'writer10_ipi' => 'y', 'writer1_share' => 1, 'writers' => 'z' ), 'writer' ), array( 1, 2, 10 ) );
// SongPress.SQL parsing
define( 'ABSPATH', '/x/' );
require __DIR__ . '/../includes/class-install.php';
$st = Songpress_Install::statements( file_get_contents( __DIR__ . '/../SongPress.SQL' ), 'wp_', 'DEFAULT CHARSET=utf8mb4' );
check( 'sql statements', count( $st ), 14 );
check( 'sql prefix', strpos( $st[2], 'CREATE TABLE wp_songpress_credits' ) === 0, true );
check( 'sql no placeholder', strpos( implode( '', $st ), '{' ), false );

// ChordPro rendering
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/x/' ); }
require_once __DIR__ . '/../includes/class-chordpro.php';
$cp = Songpress_ChordPro::render( "{title: My Song}\n{key: G}\n# hidden\n{soc}\n[G]Hello [C]world\n{eoc}\nplain line\n[Am]\n<b>x</b>" );
check( 'chordpro title', false !== strpos( $cp, '<h3 class="sp-cp-title">My Song</h3>' ), true );
check( 'chordpro chord above words', false !== strpos( $cp, '<span class="sp-cp-ch">C</span><span class="sp-cp-ly">world</span>' ), true );
check( 'chordpro chorus wraps', false !== strpos( $cp, '<div class="sp-cp-chorus">' ), true );
check( 'chordpro comment hidden', false === strpos( $cp, 'hidden' ), true );
check( 'chordpro chord with no words', false !== strpos( $cp, '<span class="sp-cp-ch">Am</span><span class="sp-cp-ly">&nbsp;</span>' ), true );
check( 'chordpro escapes html', false === strpos( $cp, '<b>' ), true );
check( 'chordpro balanced divs', substr_count( $cp, '<div' ), substr_count( $cp, '</div>' ) );

require_once __DIR__ . '/../includes/class-media.php';
check( 'image is cover art', Songpress_Media::field_for_mime( 'image/jpeg' ), 'art' );
check( 'audio is audio', Songpress_Media::field_for_mime( 'audio/mpeg' ), 'audio' );
check( 'svg refused', Songpress_Media::field_for_mime( 'image/svg+xml' ), '' );
check( 'pdf refused', Songpress_Media::field_for_mime( 'application/pdf' ), '' );
check( 'video refused', Songpress_Media::field_for_mime( 'video/mp4' ), '' );

// member classes: Manager, Writer, Viewer
require_once __DIR__ . '/../includes/class-caps.php';
$P = function ( $c, $p ) { return in_array( $p, Songpress_Caps::perms_for( $c ), true ); };
check( 'exactly three classes', array_keys( Songpress_Caps::CLASSES ), array( 'manager', 'writer', 'viewer' ) );
check( 'every class has permissions defined', array_diff( array_keys( Songpress_Caps::CLASSES ), array_keys( Songpress_Caps::PERMS ) ), array() );
check( 'manager has every permission anyone has', array_diff( array_merge( Songpress_Caps::perms_for( 'writer' ), Songpress_Caps::perms_for( 'viewer' ) ), Songpress_Caps::perms_for( 'manager' ) ), array() );
check( 'only manager runs members, import, settings, export, archive, create', array_filter( array( 'writer', 'viewer' ), fn( $c ) => $P( $c, 'members' ) || $P( $c, 'import' ) || $P( $c, 'settings' ) || $P( $c, 'export' ) || $P( $c, 'archive' ) || $P( $c, 'create' ) ), array() );
check( 'writer sees all', $P( 'writer', 'view_all' ), true );
check( 'writer edits and shares only own', array( $P( 'writer', 'edit_own' ), $P( 'writer', 'edit_any' ), $P( 'writer', 'share_own' ), $P( 'writer', 'share_any' ) ), array( true, false, true, false ) );
check( 'writer can upload', $P( 'writer', 'upload' ), true );
check( 'viewer sees all, changes nothing', array( $P( 'viewer', 'view_all' ), $P( 'viewer', 'edit_own' ), $P( 'viewer', 'edit_any' ), $P( 'viewer', 'share_own' ), $P( 'viewer', 'upload' ) ), array( true, false, false, false, false ) );
check( 'manager edits and shares any', array( $P( 'manager', 'edit_any' ), $P( 'manager', 'share_any' ) ), array( true, true ) );
check( 'unknown class has nothing', Songpress_Caps::perms_for( 'bogus' ), array() );
check( 'old class names are gone', array( isset( Songpress_Caps::CLASSES['owner'] ), isset( Songpress_Caps::CLASSES['editor'] ) ), array( false, false ) );

// GitHub updater (pure parts)
if ( ! defined( 'SONGPRESS_VERSION' ) ) { define( 'SONGPRESS_VERSION', '0.1.0' ); }
require_once __DIR__ . '/../includes/class-updater.php';
check( 'newer tag', Songpress_Updater::is_newer( 'v0.2.0', '0.1.0' ), true );
check( 'same version is not newer', Songpress_Updater::is_newer( 'v0.1.0', '0.1.0' ), false );
check( 'older is not newer', Songpress_Updater::is_newer( '0.1.0', '0.1.1' ), false );
check( 'header version parsed', Songpress_Updater::parse_version( "<?php\n/**\n * Plugin Name: X\n * Version:           0.1.5\n */" ), '0.1.5' );
check( 'no version line', Songpress_Updater::parse_version( '<?php echo 1;' ), '' );
check( 'branch wins when newer', Songpress_Updater::pick( array( 'version' => '0.1.4' ), array( 'version' => '0.1.5' ) )['version'], '0.1.5' );
check( 'release wins when newer', Songpress_Updater::pick( array( 'version' => '0.2.0' ), array( 'version' => '0.1.5' ) )['version'], '0.2.0' );
check( 'branch alone', Songpress_Updater::pick( null, array( 'version' => '0.1.5' ) )['version'], '0.1.5' );
check( 'neither', Songpress_Updater::pick( null, null ), null );
check( 'double digit compares as a number', Songpress_Updater::is_newer( '0.10.0', '0.9.0' ), true );
check( 'asset zip preferred', Songpress_Updater::package_url( array( 'zipball_url' => 'z', 'assets' => array( array( 'name' => 'other.txt', 'browser_download_url' => 'x' ), array( 'name' => 'songpress-0.2.0.zip', 'browser_download_url' => 'https://g/a.zip' ) ) ) ), 'https://g/a.zip' );
check( 'snapshot used without asset', Songpress_Updater::package_url( array( 'zipball_url' => 'https://g/z' ) ), 'https://g/z' );
check( 'repo name valid', array( Songpress_Updater::valid_repo( 'adamjyo/Songpress' ), Songpress_Updater::valid_repo( 'a/b/c' ), Songpress_Updater::valid_repo( 'x; rm' ) ), array( true, false, false ) );

// writer accounts: usernames
require_once __DIR__ . '/../includes/class-people.php';
check( 'first.last', Songpress_People::username_for( 'John Lennon' ), 'john.lennon' );
check( 'middle name dropped', Songpress_People::username_for( 'Paul James McCartney' ), 'paul.mccartney' );
check( 'suffix dropped', Songpress_People::username_for( 'Martin Luther King Jr.' ), 'martin.king' );
check( 'last, first order', Songpress_People::username_for( 'Lennon, John' ), 'john.lennon' );
check( 'one name', Songpress_People::username_for( 'Cher' ), 'cher' );
check( 'apostrophe and hyphen', Songpress_People::username_for( "Mary-Jane O'Neil" ), 'maryjane.oneil' );
check( 'accents', Songpress_People::username_for( 'José Álvarez' ), 'jose.alvarez' );
check( 'case and spacing', Songpress_People::username_for( '  ADAM   yoo ' ), 'adam.yoo' );
check( 'nothing usable', Songpress_People::username_for( '???' ), '' );
check( 'same person, same key', Songpress_People::key_for( 'Adam  Yoo' ) === Songpress_People::key_for( 'adam yoo' ), true );
check( 'different people, different key', Songpress_People::key_for( 'Adam Yoo' ) === Songpress_People::key_for( 'Adam Young' ), false );

echo $fail ? "\n$fail of $n failed\n" : "All $n checks passed\n";
exit( $fail ? 1 : 0 );
