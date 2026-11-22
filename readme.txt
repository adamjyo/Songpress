=== SongPress ===
Contributors: adamjyoo
Tags: music, songs, songwriting, catalog, lyrics
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A song catalog manager. Every song and every version under one serial number, a phone catalog, and a profile for each writer.

== Description ==

SongPress keeps your songs in one place. Each song gets a serial number. The original work is four digits, like 0012. Each version adds two more, like 0012-01. Release information lives on versions, not on the original.

* Catalog with search, status and versions
* Writers and splits, linked to user accounts
* Phone catalog and song view with audio and chords (shortcodes `[songpress_catalog]` and `[songpress_profile]`)
* Writers only see the songs they are credited on
* Import from the S&P Catalog SQLite file, with a dry-run report first

SongPress works with WordPress. It is not made or endorsed by WordPress, and it is unrelated to the "Songpress" chord-typesetting program by Luca Allulli (Skeed).

== Installation ==

1. Upload the plugin and activate it.
2. Open SongPress > Import to bring in an existing catalog, or SongPress > Add song.
3. Put `[songpress_catalog]` on a page for the phone catalog.

== Changelog ==

= 0.1.1 =
* Fixes a critical error on activation (pages are now created after WordPress has started).
* Settings has an update panel: installed and latest version, Check GitHub now, Update now.
* Multiple artists (bands and labels), with people, band and publisher photos and profiles.

= 0.1.0 =
* First milestone: tables, serial numbers, roles, catalog screens, import with dry run, phone catalog and profile.
