<?php
defined( 'ABSPATH' ) || exit;

/**
 * Turns ChordPro text into HTML with chords above the words. It only reads text: it never touches the database.
 * Each chord and the words after it sit in one small block, so lines wrap cleanly on a phone.
 */
class Songpress_ChordPro {
	private static function h( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}

	public static function render( $text ) {
		$out     = '';
		$section = '';
		$tab     = false;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			if ( $tab && ! preg_match( '/^\s*\{\s*(end_of_tab|eot)\s*\}\s*$/i', $line ) ) {
				$out .= self::h( $line ) . "\n";
				continue;
			}
			if ( preg_match( '/^\s*#/', $line ) ) {
				continue;
			}
			if ( preg_match( '/^\s*\{\s*([a-z_]+)\s*(?::\s*(.*?))?\s*\}\s*$/i', $line, $m ) ) {
				$d   = strtolower( $m[1] );
				$arg = isset( $m[2] ) ? $m[2] : '';
				switch ( $d ) {
					case 'title':
					case 't':
						$out .= '<h3 class="sp-cp-title">' . self::h( $arg ) . '</h3>';
						break;
					case 'subtitle':
					case 'st':
					case 'artist':
						$out .= '<p class="sp-cp-sub">' . self::h( $arg ) . '</p>';
						break;
					case 'key':
					case 'capo':
					case 'tempo':
					case 'time':
						$out .= '<span class="sp-cp-tag">' . self::h( ucfirst( $d ) . ' ' . $arg ) . '</span> ';
						break;
					case 'comment':
					case 'c':
					case 'comment_italic':
					case 'ci':
						$out .= '<p class="sp-cp-comment">' . self::h( $arg ) . '</p>';
						break;
					case 'start_of_chorus':
					case 'soc':
					case 'start_of_verse':
					case 'sov':
					case 'start_of_bridge':
					case 'sob':
						$kind    = in_array( $d, array( 'start_of_chorus', 'soc' ), true ) ? 'chorus' : ( in_array( $d, array( 'start_of_bridge', 'sob' ), true ) ? 'bridge' : 'verse' );
						$out    .= ( $section ? '</div>' : '' ) . '<div class="sp-cp-' . $kind . '">' . ( '' !== $arg ? '<p class="sp-cp-label">' . self::h( $arg ) . '</p>' : '' );
						$section = $kind;
						break;
					case 'end_of_chorus':
					case 'eoc':
					case 'end_of_verse':
					case 'eov':
					case 'end_of_bridge':
					case 'eob':
						$out    .= $section ? '</div>' : '';
						$section = '';
						break;
					case 'start_of_tab':
					case 'sot':
						$out .= '<pre class="sp-cp-tab">';
						$tab  = true;
						break;
					case 'end_of_tab':
					case 'eot':
						$out .= '</pre>';
						$tab  = false;
						break;
				}
				continue;
			}
			if ( '' === trim( $line ) ) {
				$out .= '<div class="sp-cp-gap"></div>';
				continue;
			}
			$out .= self::lyric_line( $line );
		}
		if ( $tab ) {
			$out .= '</pre>';
		}
		if ( $section ) {
			$out .= '</div>';
		}
		return '<div class="sp-cp">' . $out . '</div>';
	}

	private static function lyric_line( $line ) {
		if ( false === strpos( $line, '[' ) ) {
			return '<div class="sp-cp-line"><span class="sp-cp-seg"><span class="sp-cp-ly">' . self::h( $line ) . '</span></span></div>';
		}
		$parts = preg_split( '/\[([^\]]*)\]/', $line, -1, PREG_SPLIT_DELIM_CAPTURE );
		$html  = '';
		// $parts: text, chord, text, chord, text ...
		if ( '' !== $parts[0] ) {
			$html .= '<span class="sp-cp-seg"><span class="sp-cp-ch">&nbsp;</span><span class="sp-cp-ly">' . self::h( $parts[0] ) . '</span></span>';
		}
		for ( $i = 1; $i < count( $parts ); $i += 2 ) {
			$words = $parts[ $i + 1 ] ?? '';
			$html .= '<span class="sp-cp-seg"><span class="sp-cp-ch">' . self::h( $parts[ $i ] ) . '</span><span class="sp-cp-ly">' . ( '' === $words ? '&nbsp;' : self::h( $words ) ) . '</span></span>';
		}
		return '<div class="sp-cp-line">' . $html . '</div>';
	}
}
