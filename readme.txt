=== SongPress ===
Contributors: adamjyoo
Tags: music, songs, songwriting, catalog, lyrics
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.3.1
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

= 0.3.1 =
* Writers, Bands, Labels and Publishers are now separate lists, each with its own entry in the left rail. They never appear together. A writer can also be an author and a band can also be an artist, but each profile belongs to one list.
* New Labels list, and a Labels group on the song editor for the label a song is released on.

= 0.3.0 =
* New Art studio in the rail (Managers, desktop): layered cover art on a square canvas. Add text, boxes, circles and pictures; drag to move, drag the corner to resize; reorder, hide, duplicate and delete layers; fonts load from Google Fonts by name.
* "Save and make cover" renders the square (up to 3000 px), adds it to the Media Library and sets it as the song's art. "Download PNG" saves a copy.
* "Load money" also brings your 25 old art projects across with their layers and previews, including vertical (stacked) text.

= 0.2.2 =
* Stats now opens with the chart from the old plugin: stacked by store or service, streams or income, per day, week, month and quarter. Income comes from DistroKid statements (monthly and quarterly); daily and weekly come from the daily stream estimates.
* Stats has the DistroKid stats-page importer: choose the HTML you save from DistroKid and the daily streams are added or updated. Days it does not cover are kept.

= 0.2.1 =
* New Journal in the rail (Managers, desktop): dated notes with search, pin, archive and edit; a title that starts with a serial links the entry to its song.
* "Load money" on the Money page now also brings across your old work journal (1,600+ entries) and song ratings. Entries already here are kept.
* Stats shows the top rated songs.

= 0.2.0 =
* New Money and Stats in the rail (Managers, desktop): earnings by quarter and source (DistroKid, MLC, ASCAP, CCLI), top songs, payouts with paid dates, expenses, plays by month, streams per day, income by store.
* Load money once from your old catalog file (Money, bottom of page). Payouts and expenses you enter are never replaced by a later load.
* Writers see My earnings on their Profile.

= 0.1.25 =
* Phones and tablets only view songs; adding and editing is for the desktop app. The song editor shows every section on desktop.
* The left rail now has every tool: Members, People, Artists, Import, Link writers, Settings and Export CSV, all opening inside the app instead of WordPress admin.

= 0.1.24 =
* Opening a song opens the editor (for people who can edit it). A View song link shows the read-only page.

= 0.1.23 =
* Members moved into the SongPress app and no email is used anywhere for logins: a Manager creates the login (firstname.lastname), sets or changes the password, and copies a ready-made invitation with a link, username and password. The login screen asks for a username only and has no email password reset. SongPress never sends email to members.

= 0.1.22 =
* Song editor now lives in the SongPress app (+ button, and an Edit button on every song). Phones get a short form: title, status, cover art, audio, lyrics, writers. Wide screens get every section as a tab: basics (genre, stage, key, tempo, time signature, other titles), people and splits, art and audio, lyrics and chords, release, codes and links, story and notes, and versions, sharing, archive and history.

= 0.1.21 =
* Faster updates: GitHub is re-checked every 10 minutes (was 12 hours), and opening the Plugins or Updates screen re-checks when that has run out.

= 0.1.20 =
* Album art and icons are 75px (56px on phones).

= 0.1.19 =
* Album art and writer/band icons are all the same size: 100px (56px on phones).

= 0.1.18 =
* Update check bypasses GitHub's 5-minute file cache so new versions show up right away.

= 0.1.17 =
* Song list fills the whole width, serial numbers like 1862-01 no longer wrap, and versions with no title of their own show the original work's title.

= 0.1.16 =
* People page: "Load photos from catalog file" fills in photos and bios for people, bands and publishers from your old catalog.sqlite without touching songs. Band bios now come from the bio column.

= 0.1.15 =
* Column headers (No., Title, Genre, Writers, Band, Status) sort the whole list; click again to reverse.

= 0.1.14 =
* Each song row: album art that plays the linked audio, number, title, genre, writer icons, band icon, status.

= 0.1.13 =
* Removed the status buttons under the search; added a + button at the right end of the search bar to add a song.

= 0.1.12 =
* Songs are a plain full-width list (serial, title, artist, status), not cards.

= 0.1.11 =
* The page holding the catalog now opens as its own full-screen SongPress app (sidebar, no theme header or footer).
* Logged-out visitors see the SongPress login screen; a wrong password returns to it with a message.

= 0.1.10 =
* Sturdier layout in themes that fight it: the logo sits beside the title, content uses the full width of the panel, and the Songs and Profile tabs move to the top in wide boxes.

= 0.1.9 =
* Layout follows the width of the column the page puts it in (not the browser window), so it no longer cramps inside a narrow theme column.
* Theme-proofing: no underlines, no monospace fonts, no bullets, and the logo stays beside the title.

= 0.1.8 =
* Use /desktop also frees the address from a Media Library file that holds it, clears WordPress's old-address redirect, and says so if it still cannot get the address.

= 0.1.7 =
* Use /desktop now frees the address when a trashed page is holding it, and names the page when a live one is.

= 0.1.6 =
* Settings shows each SongPress page's real address and offers one click to put it at /desktop or /my-songs.

= 0.1.5 =
* Updates are found on GitHub without publishing a release: a higher version number in songpress.php on the main branch counts as an update. Published releases still work.

= 0.1.4 =
* The main page now lives at /desktop (an existing /catalog page created by SongPress is moved there once).

= 0.1.3 =
* Desktop layout: wide catalog grid, song page with cover on the left and chart on the right, tabs along the top. Phones keep the single column.

= 0.1.2 =
* Front end restyled to the designed dark look with the amber accent. It no longer picks up white text from the site theme.
* Phone catalog has status filters, the logo header and Songs and Profile tabs.

= 0.1.1 =
* Fixes a critical error on activation (pages are now created after WordPress has started).
* Settings has an update panel: installed and latest version, Check GitHub now, Update now.
* Multiple artists (bands and labels), with people, band and publisher photos and profiles.

= 0.1.0 =
* First milestone: tables, serial numbers, roles, catalog screens, import with dry run, phone catalog and profile.
