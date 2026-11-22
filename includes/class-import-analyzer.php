<?php
defined( 'ABSPATH' ) || defined( 'SONGPRESS_TESTING' ) || exit;

/**
 * Reads song_catalog rows from the old S&P Catalog SQLite file and turns them into SongPress records.
 * Pure PHP: no WordPress calls, so it can be tested on its own and run as a dry run that writes nothing.
 */
class Songpress_Import_Analyzer {

	const IDENTIFIERS = array(
		'mlc' => 'mlc', 'iswc' => 'iswc', 'work_id' => 'work_id', 'ccli' => 'ccli', 'bmi' => 'bmi',
		'copyright' => 'copyright_number', 'isrc' => 'isrc', 'distrokid' => 'distrokid',
	);

	const LINKS = array(
		'spotify' => 'spotify_link', 'apple' => 'apple_music_link', 'youtube' => 'youtube_link',
		'ytmusic' => 'youtube_music_link', 'amazon' => 'amazon_music_link', 'tidal' => 'tidal_link', 'deezer' => 'deezer_link',
	);

	/** Old columns that SongPress does not import in this version, and why. Shown in the dry-run report. */
	const SKIPPED = array(
		'dk_streams'     => 'streaming totals belong to the Money feature',
		'dk_income'      => 'earnings belong to the Money feature',
		'total_earnings' => 'earnings belong to the Money feature',
		'streams'        => 'streaming totals belong to the Money feature',
		'sound_exchange' => 'royalty data belongs to the Money feature',
		'rating_avg'     => 'ratings are not part of the first version',
		'rating_count'   => 'ratings are not part of the first version',
		'progress'       => 'replaced by the status and the sync-ready score',
		'writers'        => 'rebuilt from the individual writer columns',
	);

	/** Map one old row to a SongPress record. @return array{song:array,credits:array,identifiers:array,links:array,serial:?array} */
	public static function map_row( array $r ) {
		$g = function ( $k ) use ( $r ) {
			return isset( $r[ $k ] ) ? trim( (string) $r[ $k ] ) : '';
		};
		$serial = Songpress_Serial::parse( $g( 'serial_number' ) );

		$song = array(
			'title'          => $g( 'song_title' ),
			'alt_titles'     => $g( 'alternate_titles' ),
			'status'         => self::map_status( $g( 'status' ) ),
			'stage'          => $g( 'writing_stage' ),
			'writing_date'   => $g( 'writing_date' ),
			'musical_key'    => $g( 'key' ),
			'tempo'          => $g( 'tempo' ),
			'time_signature' => $g( 'time_signature' ),
			'genre'          => $g( 'genre' ),
			'artist'         => $g( 'artist' ),
			'album'          => $g( 'album' ),
			'release_title'  => $g( 'release_title' ),
			'release_date'   => $g( 'release_date' ),
			'copyright_date' => $g( 'copyright_date' ),
			'production_date' => $g( 'production_date' ),
			'format'         => $g( 'format' ),
			'style'          => $g( 'style' ),
			'themes'         => $g( 'themes' ),
			'ai_prompt'      => $g( 'ai_prompt' ),
			'art_prompt'     => $g( 'art_prompt' ),
			'lyrics'         => $g( 'lyrics' ),
			'chords_lyrics'  => $g( 'lyrics_chords' ),
			'chordpro'       => $g( 'lyrics_prochord' ),
			'story'          => $g( 'story' ),
			'notes'          => $g( 'notes' ),
			'audio_url'      => $g( 'audio' ),
			'art_url'        => $g( 'art_link' ),
			'legacy_id'      => isset( $r['id'] ) ? (int) $r['id'] : null,
			'legacy_outside_serial' => $g( 'outside_serial' ),
		);

		// The old file has writer1..writer10 columns. Read however many numbered columns this file has,
		// so a file with writer14_name works too. A SongPress song itself has no limit.
		$credits = array();
		foreach ( array( 'writer' => 'writer', 'publisher' => 'publisher', 'performer' => 'performer', 'producer' => 'producer' ) as $prefix => $role ) {
			foreach ( self::numbers( $r, $prefix ) as $n ) {
				if ( '' === $g( "{$prefix}{$n}_name" ) ) {
					continue;
				}
				$credits[] = array(
					'role'  => $role,
					'name'  => $g( "{$prefix}{$n}_name" ),
					'ipi'   => $g( "{$prefix}{$n}_ipi" ),
					'share' => in_array( $role, array( 'performer' ), true ) ? null : self::num( $g( "{$prefix}{$n}_share" ) ),
				);
			}
		}

		$ids = array();
		foreach ( self::IDENTIFIERS as $kind => $col ) {
			if ( '' !== $g( $col ) ) {
				$ids[] = array( 'kind' => $kind, 'value' => $g( $col ) );
			}
		}
		$links = array();
		foreach ( self::LINKS as $kind => $col ) {
			if ( '' !== $g( $col ) ) {
				$links[] = array( 'kind' => $kind, 'url' => $g( $col ) );
			}
		}
		return array( 'song' => $song, 'credits' => $credits, 'identifiers' => $ids, 'links' => $links, 'serial' => $serial );
	}

	/** Numbers N for which the row has a column like "writerN_name", in order. */
	public static function numbers( array $r, $prefix ) {
		$out = array();
		foreach ( array_keys( $r ) as $k ) {
			if ( preg_match( '/^' . preg_quote( $prefix, '/' ) . '(\d+)_(?:name|ipi|share)$/', $k, $m ) ) {
				$out[ (int) $m[1] ] = (int) $m[1];
			}
		}
		ksort( $out );
		return array_values( $out );
	}

	/** Release information: it belongs to versions, not to the original work. */
	public static function has_release( array $m ) {
		$s = $m['song'];
		foreach ( array( 'artist', 'album', 'release_title', 'release_date' ) as $f ) {
			if ( '' !== $s[ $f ] ) {
				return true;
			}
		}
		foreach ( $m['identifiers'] as $i ) {
			if ( in_array( $i['kind'], array( 'isrc', 'distrokid' ), true ) ) {
				return true;
			}
		}
		return ! empty( $m['links'] );
	}

	/** Old status text is free-form. Anything unrecognized becomes "idea" and is counted in the report. */
	public static function map_status( $s ) {
		$k = strtolower( trim( (string) $s ) );
		$map = array(
			'idea' => 'idea', 'writing' => 'writing', 'in progress' => 'writing', 'draft' => 'writing',
			'finished' => 'finished', 'complete' => 'finished', 'completed' => 'finished', 'done' => 'finished',
			'recorded' => 'recorded', 'released' => 'released', 'live' => 'released', 'retired' => 'retired', 'archived' => 'retired',
		);
		return $map[ $k ] ?? ( '' === $k ? 'idea' : '' );
	}

	/** Accepts "50", "50%", "33.33". Anything else is null. */
	public static function num( $v ) {
		$v = rtrim( trim( (string) $v ), '%' );
		return ( '' !== $v && is_numeric( $v ) ) ? (float) $v : null;
	}

	/**
	 * Dry run over an iterable of old rows.
	 *
	 * @return array Report: counts, problems with example serials, and writer names to match to users.
	 */
	public static function analyze( $rows ) {
		$rep = array(
			'rows' => 0, 'families' => 0, 'versions' => 0,
			'no_title' => 0, 'bad_serial' => array(), 'duplicate_serial' => array(),
			'unknown_status' => array(), 'version_gaps' => array(), 'family_without_v0' => array(),
			'writer_share_off' => 0, 'original_with_release' => array(), 'songs_without_writers' => 0,
			'writers' => array(), 'skipped_columns' => self::SKIPPED,
		);
		$seen = array();
		$fam  = array();
		foreach ( $rows as $r ) {
			$rep['rows']++;
			$m = self::map_row( $r );
			if ( '' === $m['song']['title'] ) {
				$rep['no_title']++;
			}
			if ( ! $m['serial'] ) {
				$rep['bad_serial'][] = (string) ( $r['serial_number'] ?? '' );
			} else {
				$norm = Songpress_Serial::format( $m['serial']['family'], $m['serial']['version'] );
				if ( isset( $seen[ $norm ] ) ) {
					$rep['duplicate_serial'][] = $norm;
				} else {
					$seen[ $norm ] = true;
					$fam[ $m['serial']['family'] ][] = $m['serial']['version'];
				}
			}
			if ( '' === $m['song']['status'] ) {
				$raw = trim( (string) ( $r['status'] ?? '' ) );
				$rep['unknown_status'][ $raw ] = ( $rep['unknown_status'][ $raw ] ?? 0 ) + 1;
			}
			if ( $m['serial'] && 0 === $m['serial']['version'] && self::has_release( $m ) ) {
				$rep['original_with_release'][] = Songpress_Serial::format( $m['serial']['family'], 0 );
			}
			$sum = 0.0;
			$has = false;
			$any = false;
			foreach ( $m['credits'] as $c ) {
				if ( 'writer' !== $c['role'] ) {
					continue;
				}
				$any = true;
				$rep['writers'][ $c['name'] ] = ( $rep['writers'][ $c['name'] ] ?? 0 ) + 1;
				if ( null !== $c['share'] ) {
					$sum += $c['share'];
					$has  = true;
				}
			}
			if ( ! $any ) {
				$rep['songs_without_writers']++;
			}
			if ( $has && abs( $sum - 100.0 ) > 0.05 ) {
				$rep['writer_share_off']++;
			}
		}
		$rep['families'] = count( $fam );
		$rep['versions'] = array_sum( array_map( 'count', $fam ) );
		foreach ( $fam as $no => $vers ) {
			sort( $vers );
			if ( 0 !== $vers[0] ) {
				$rep['family_without_v0'][] = Songpress_Serial::format( $no, 0 );
			}
			for ( $i = 1; $i < count( $vers ); $i++ ) {
				if ( $vers[ $i ] - $vers[ $i - 1 ] > 1 ) {
					$rep['version_gaps'][] = Songpress_Serial::format( $no, $vers[ $i - 1 ] ) . ' to ' . Songpress_Serial::format( $no, $vers[ $i ] );
				}
			}
		}
		arsort( $rep['writers'] );
		$rep['duplicate_serial'] = array_values( array_unique( $rep['duplicate_serial'] ) );
		return $rep;
	}

	/** Stream rows from the old SQLite file without loading everything. */
	public static function read_sqlite( $path ) {
		$pdo = new PDO( 'sqlite:' . $path, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
		$q   = $pdo->query( 'SELECT * FROM song_catalog ORDER BY id' );
		while ( $row = $q->fetch( PDO::FETCH_ASSOC ) ) {
			yield $row;
		}
	}
}
