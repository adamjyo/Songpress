=== SongPress ===
Contributors: adamjyoo
Tags: music, songs, songwriting, catalog, lyrics
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.63.86
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

= 0.63.86 =
* Fix: the Settings buttons in the app (Check GitHub now, save settings, create pages and others) did nothing and dropped you on the homepage, because their handlers were only loaded inside wp-admin. They now load for the app's own form address too.

= 0.63.85 =
* Settings screens in the app: buttons such as Check GitHub now return to the app instead of the site homepage.

= 0.63.84 =
* Works list: the total number of works in the system shows after "Show 100 works".

= 0.63.83 =
* The phone test now also reaches the phones of whoever gets alerts, and names the person and device for each answer.

= 0.63.82 =
* Settings > Notifications: separate test buttons for your computer and your phone, plus the alert test. A device is told apart as phone or computer once its bell has been turned off and on again.

= 0.63.81 =
* The notification test now lists every device it tried with the push service's answer, so a device that refuses can be seen.

= 0.63.80 =
* The notification test now names the devices it reached (for example iPhone, Home Screen app, or Mac, Safari), so you can see where it went. Turn the bell off and on again on a device to give it a name.

= 0.63.79 =
* iPhone directions now start from the menu icon at the left end of Safari's address bar, as on current iOS.

= 0.63.78 =
* Add to Home Screen directions now match current iOS Safari: three dots, Share, Add to Home Screen, keep Open as Web App on.

= 0.63.77 =
* On phones, an amber bar at the top of the app offers Add to Home Screen (with steps for iPhone and Android). It disappears once SongPress runs as a Home Screen app, and can be dismissed for a week.

= 0.63.76 =
* Turning on notifications now shows a clear step-by-step panel: how to add SongPress to the iPhone Home Screen, where to allow notifications in the phone's settings, and what to check if nothing appears.

= 0.63.75 =
* The notification test buttons (Settings and People) work in place and show the answer beside the button instead of loading the homepage.

= 0.63.74 =
* Notifications: Done (and Mark all done) now update the list in place, without reloading the page.

= 0.63.73 =
* On a computer, the blocked-notifications message now says how to allow them in the browser, and a failed turn-on shows the real reason.

= 0.63.72 =
* The app's Works list (and the work page when there is no release title) shows the genre, not the style.

= 0.63.71 =
* The phone menu is gone. The notifications bell now sits to the right of your name on the Works list.
* New Notifications section in the desktop rail with a count badge for ones not dealt with. Mark each done, or all at once. The badge updates live, and alerts are pushed to adam.page's phone.

= 0.63.70 =
* A version with no release title yet now shows the work's name (in grey italics) with its style, on the phone cards and at the top of the work, instead of "No release title".

= 0.63.69 =
* Phones: a menu button (bottom right) opens the full menu as a drawer, so Settings, People and Log out can be reached in the app on a phone.
* The notifications bell now sits to the right of your name in the menu: a bell with a slash when off, solid amber when on. Tap it to turn notifications on or off.

= 0.63.68 =
* Settings: a Notifications box with "Send a test to my phone" and "Send a test alert". It shows whether the signing key is ready and how many phones are on, and when a test does not arrive it says why (no phone turned on, key missing, or what the phone's push service answered).

= 0.63.67 =
* People: "Alerts to your phone". Choose who gets a phone notification when something happens that you would otherwise only see on the desktop (someone forgot their password, or chose one from a link). Until you choose, alerts go to adam.page, or to every Manager if there is no such login. A "Send a test alert" button checks it.

= 0.63.66 =
* Money: each person's card now scrolls sideways through the quarters (oldest on the left, opening at the newest). The labels on the left and the All time column on the right stay in view. Tap a Paid or Left amount to record or edit a payment, as before.

= 0.63.65 =
* Login page: "Forgot my password". The person enters their username or email; every Manager is notified on their phone and sees a "Forgot their password" box at the top of People (plus a count on the People menu item) with Send a password link, Email it and Dismiss.
* Password links are made for one person only and the page greets them by name. The link comes ready to paste into a text message ("Hi Abby, here is your personal SongPress link...").
* People: Send a notification now lists everyone who has notifications on, says who has not, and each person's page has its own Send box.

= 0.63.64 =
* The app can be saved to a phone's Home Screen and send notifications (Android, and iPhone with iOS 16.4 or later). Each person turns them on from the bell in the app menu; a test is sent when they do. People are told when a payment is recorded for them.
* People: "Send a notification" lets a Manager message one person or everyone who turned notifications on.

= 0.63.63 =
* People: send a password link. On a person's page, "Send a password link" makes a one-time link (copy it, or email it when they have an address). It opens a page where they choose their own password and signs them in; the same link works when someone forgets their password. It lapses after a week.
* People: "Bring old accounts across" brings the old plugin's usernames and passwords over, so earlier users can sign in. Writers and contributors become Writers, members become Viewers.
* The old addresses work: /desktop and /app both open the app (pages are made, or taken over if the old plugin left them), and old ?swp_view= links land on the matching screen.

= 0.63.62 =
* Settings > Earnings: shows how earnings are worked out and lets you change the numbers. Set the default performer share and whether bands pay out less their performers, recalculate DistroKid splits, and check any person song by song, with each performer share editable in place. Changes go in the activity log.

= 0.63.61 =
* Money: a new Bands section under the persons. Each band shows its DistroKid money by quarter, less the performers' shares, with a Pays to box. Whoever you pick sees that money on their card as Band. Payees from the old catalog are brought across.

= 0.63.60 =
* Activity log: every payment recorded, edited (what changed, old to new) or removed, and every expense added or removed, is now written to the log with the person, quarter, amount and date.

= 0.63.59 =
* Money: each person card now lists every quarter down the page (newest first) in a box that scrolls up and down, with a sticky heading and an All time row. Click a Paid amount to edit or remove that payment; click Left or an empty Paid to record one for that quarter. The quarters picker now sets the chart.

= 0.63.58 =
* Money: edit a payment from its history (pencil icon): change the quarter, amount (negative for a correction), date and note. Moving it to a quarter that already has a payment for that person folds the two together.

= 0.63.57 =
* Money: old payment lines are brought across from the rows already stored when you update (no new upload needed), so Paid and Left on the person blocks fill in.
* Money: a Quarters picker on the person blocks and the chart: last 8, any single year, or every quarter.

= 0.63.56 =
* Works list: the sort you chose is remembered with your account, so the list, Back to Works and Previous / Next in the editor all use it. Click the active heading a third time to go back to the default order.

= 0.63.55 =
* Works editor: a sticky top bar with Back to Works, Previous and Next, Share, Delete (asks first) and Save, all as icons. The old Save bar at the bottom is gone.

= 0.63.54 =
* Works editor: Save stays on the work (and keeps the list search for Previous and Next).
* Rail items remember where you were: leave Works or Notes and clicking it again returns to the work or note you had open, with its search. Clicking the item you are already on starts it over. Notes keep the open note and search in the address.

= 0.63.53 =
* Works editor: Previous and Next buttons at the top, with where you are in the list ("3 of 41"). They follow the list you came from, including its search, status filter and sort. Unsaved changes ask before you leave.

= 0.63.52 =
* Money: DistroKid statements now credit each performer their performer share (25% when none is stored, as the old catalog did) instead of dividing the whole statement among them; quarters credited the old way are worked out again once on update.
* Money: the old catalog "Q4 correction" payment lines now come across (as corrections on that year Q4).

= 0.63.51 =
* Money: payment lines from the old catalog (paid_entry) now come across as paid, so what each person is owed is no longer overstated. Loading the catalog again also fixes lines imported earlier as unpaid.

= 0.63.50 =
* Money chart is now one bar per quarter: income above the zero line (kept part in stream colors, the part that went out in translucent grey, total on top), payouts and expenses below it, with the net shown when a quarter ran at a loss.

= 0.63.49 =
* Money chart now shows money out too: each quarter has a second column of payouts and expenses beside the income column.

= 0.63.48 =
* Money: all earnings by quarter is now a stacked column chart, each income stream a part of the column, with a legend, totals on the columns, hover amounts and a table view.

= 0.63.47 =
* Money: the all-earnings-by-quarter table at the top uses the same layout as each person's block (quarters across, streams down, a Total row).

= 0.63.46 =
* Money: income and accounting are one section, a block per person: streams by quarter, Total with paid checks, Paid and Left rows, a Record a payment button and history, a summary strip and a find box.

= 0.63.45 =
* Money: income table lists each person on a header row, their streams beneath, then a Total row (with the paid checks). Accounting is now a summary strip plus a card per person with a progress bar, what is owed, a Record a payment button and the payment history.

= 0.63.44 =
* Money: the income table now shows each person's quarters broken down by income stream (DistroKid, MLC, ASCAP, CCLI, Band) by default, with a Totals only switch.

= 0.63.43 =
* Money: each person's photo (or initials) sits to the left of their name in the income and accounting tables.

= 0.63.42 =
* Money: tidier layout for the Pay forms, and the paid check no longer shows its hidden label as visible text.

= 0.63.41 =
* Money: in the income table a green check marks each person and quarter that has been paid in full; an amber check means partly paid (hover for the amounts).

= 0.63.40 =
* Bands: a "Paid to (payee)" field on each band's page. That band's DistroKid earnings (every song released under the band) now show in Money as a Band income stream for the payee, in the income table, in Accounting (owed to date), and in the payee's own earnings.

= 0.63.39 =
* Money (was Stats) on the desktop menu: an income table with every person by quarter across all streams (switch to see each stream), and an Accounting table showing earned, paid and owed to date per person, with a Pay form that records a payment against any quarter and a payment history you can undo.

= 0.63.38 =
* Every SongPress button, upload and download that used wp-admin/admin-post.php now goes through the site's own address instead (some hosts block admin-post.php for front-end sign-ins, so uploads such as DistroKid statements quietly did nothing). Same handlers and permission checks.

= 0.63.37 =
* Activity log: note changes are recorded with what moved (title/date/place/etc. old → new, characters added and removed); a stretch of autosaves folds into one entry. Browser note imports are logged too.

= 0.63.36 =
* Notes: no Save button; notes save as you type (about a second after you pause, and when you leave the page). Typing during a save is never lost.

= 0.63.35 =
* Notes: the list loads a slim version of each note (preview and tags); a note's full text loads when you open it, body search and export ask the server. The page is less than half the size.

= 0.63.34 =
* Notes: saving, deleting and pinning now go through admin-ajax (sites that block admin-post.php could not delete); delete is one click; select mode has Delete selected and Select all shown.

= 0.63.33 =
* Notes: the list shows the newest 150 and loads more on request, so thousands of notes no longer freeze the page.

= 0.63.32 =
* Notes: the screen no longer runs out of memory with thousands of notes (it reads and packs them in small slices).

= 0.63.31 =
* Notes import: "Continue now" and the server-folder import no longer use admin-post.php (blocked on some sites); they run through admin-ajax with a live progress bar and carry on by themselves.

= 0.63.30 =
* Notes import: Bear links to other notes and people (bear://…) are cleaned down to their words, and a key of ? or tempo of ?bpm is left blank.

= 0.63.29 =
* Notes import from a folder on the server: put the notes (or a zip) in the incoming folder shown on the Notes upload panel with FTP or the host's file manager, and press Import. No browser upload involved.

= 0.63.28 =
* Notes import: choose one zip of all the notes. A zip is one file for the browser, so there is no limit on how many files it can open at once, and the server reads it 150 notes at a time.

= 0.63.27 =
* Notes import: every file is read into the browser first (retrying a few times for files that only download when opened, such as ones kept in iCloud or Dropbox), so unreadable files are named up front instead of failing mid-import.

= 0.63.26 =
* Notes import: if the server stops on a note, the Notes upload panel names that file.

= 0.63.25 =
* Notes import: the rest between batches of 200 is 2 seconds.

= 0.63.24 =
* Notes import: a file the server refuses as an upload is sent again as plain text in an ordinary form field, which gets past upload filters and firewalls; only a file that fails both ways is skipped and named.

= 0.63.23 =
* Notes import: the files go in batches of 200, with a 30 second rest between batches and the batch number on the progress bar.

= 0.63.22 =
* Notes import: a file the server will not take no longer stops the import. A batch that fails is resent one file at a time; the file at fault is skipped and named at the end. Pictures embedded in a note's text (which can make one note several megabytes) are removed before sending.

= 0.63.21 =
* Notes import: when a batch is refused, the message now shows what the server actually answered.

= 0.63.20 =
* Notes import: sends 10 files at a time, pauses 10 seconds after every 100 files, and if the server stumbles it waits and tries that batch again (up to 4 times) before stopping.

= 0.63.19 =
* Notes import: the browser now sends the files 20 at a time (or 4 MB at a time) and shows a progress bar, so thousands of files never meet the server's limit on files or size per upload. A batch that fails says where it stopped; notes already added are kept.

= 0.63.18 =
* Notes import: if the server stops an import (a blank page), the reason and the server's limits are shown on the Notes upload panel next time; an upload over the server's size limit now says so; the panel shows the largest upload this server takes.

= 0.63.17 =
* Notes import: big imports (thousands of notes) no longer run in one request. The files are staged on disk and imported in short steps with a progress bar and a Cancel button; a note that fails is skipped instead of stopping the import, and non-UTF-8 files are converted.

= 0.63.16 =
* Notes: the Position line (here, clear and the coordinates) is hidden; the map stays.

= 0.63.15 =
* Notes: a small calendar above the search. Days that have notes carry a dot; click a day to show only that day's notes, click it again to clear.

= 0.63.14 =
* Notes: the export button above the list opens a menu with the open or selected notes as .md, all as .md, .bear or .textbundle, and the Days, Map and ChordPro tool pages.

= 0.63.13 =
* Notes: the page is locked in place and only the columns scroll (tags, list, note, details).

= 0.63.12 =
* Notes: the Days, Map, ChordPro tool and export links at the bottom of the details panel are gone; the location map is shown there instead, and clicking it sets the note's position. Fixes the map, which the audio change in 0.63.11 had broken.

= 0.63.11 =
* Notes: an audio link now takes two rows, not three or more. The long address is kept out of the text (it is saved with the note as before) and the pencil on the player changes it.

= 0.63.10 =
* Notes: the audio block shows the name above a slimmer player.

= 0.63.9 =
* Notes: an audio link shows its player right where the link is, covering the link text. Press the pencil on the player (or click into the link) to edit the link.

= 0.63.8 =
* Notes: audio players sit at the top of the note, where they are always in view (they used to sit below the whole text).

= 0.63.7 =
* Notes: audio links get a player even when the link is split over two lines, and any link to an mp3, wav, m4a, ogg or flac file becomes a player.

= 0.63.6 =
* Updates: the check reads the version from GitHub's API first (fresh within a minute of a push) and falls back to the raw file, which GitHub can serve up to five minutes late.

= 0.63.5 =
* Notes: the audio box drops in at the cursor, and the audio is inserted there.

= 0.63.4 =
* Notes: the audio button opens a box. Drop audio files on it to add them to WordPress media, or click it to search the Media Library or upload. Inserted audio links show a player under the note and in preview.

= 0.63.3 =
* Works list: the length dropdown sits in front of the search. Notices such as Deleted 5 songs pop in and fade away. Deleting no longer asks for confirmation.

= 0.63.2 =
* Works list: choose how many works to show, 100 to 1000 in steps of 100 (replaces the All registrations and All agreements buttons, which stay in the sidebar).

= 0.63.1 =
* Works list: bulk edit is off until you press Bulk edit (remembered), and its Delete selected button stays hidden until you tick Show delete.

= 0.63.0 =
* Works list: tick works (or the box at the top for all) and a bar appears to set their Status, Genre, Style, Band or Themes in one go, or delete them (Managers). Works you cannot edit are skipped, and so are fields that do not apply to an original work.

= 0.62.1 =
* Delete: a deleted work's serial number is never given to a new work. Expenses, activity and pitches are kept but unlinked, its pitch kit and (for a whole work) its registrations go with it, and the audit log files it under Works. A version's button now says Delete this version.

= 0.62.0 =
* Works can be deleted. In a work's editor, under Versions, Managers get a Delete section with a confirm box. Deleting an original work also deletes its versions; deleting a version removes only that version. Earnings already paid stay on the books.

= 0.61.4 =
* Updates: nothing is remembered. Every look at GitHub is a fresh one, and opening Plugins or Updates always makes one.

= 0.61.3 =
* Updates: opening Plugins or Updates always checks GitHub afresh (at most once a minute); SongPress also checks every hour and installs a newer version by itself. A Settings checkbox turns automatic installing off.

= 0.61.2 =
* Loading a whole database file now also fills the screens (notes, statements, payouts, art, agreements, registrations, photos). A new button fills them from rows already stored, with no new upload.

= 0.61.1 =
* Uploading audio no longer also saves the picture embedded in the file as a second Media Library item. Only the audio and its name are kept.

= 0.61.0 =
* Settings: a tool to load a whole old database (a SQLite file or an .sql dump). Every table and row is stored in SongPress's own database, even where no screen uses it yet. Works in short steps that continue by itself, and skips rows already stored.

= 0.60.0 =
* Upload / Download: one catalog upload does it all. After the songs are in, the same file also brings the photos and bios, statements, notes, ratings, art, agreements and registrations. The separate photos and catalog options are gone.

= 0.59.3 =
* Profile: each song shows what the signed-in person has earned from it, not the song's total.

= 0.59.2 =
* Works list: shorter search hint so it fits on a phone.

= 0.59.1 =
* Search: every song search (works list, admin list, pitch picker) finds writers, performers, artist, title, serial number, lyrics, style and theme.

= 0.59.0 =
* Profile: a quarterly income chart under the Works and Released cards, and a list of the songs the person wrote or performed with what each has made on DistroKid.
* Shared songs stay readable without logging in; the works list and profile still need a login.

= 0.58.0 =
* Work view: ChordPro, Chords and lyrics and Lyrics are tabs that switch the chart in place; a share icon sits at the far right of the top bar.

= 0.57.1 =
* Work view: nothing is shown under the chart any more (the donate link no longer follows it).

= 0.57.0 =
* Work view: the audio player runs the full width of the screen under the cover art and title. Only an original work shows the work name; a version shows its release title. The authors are one row of icons under the artist (writers only, no names, swipe sideways if there are many), and the credits block under the chart is gone.

= 0.56.3 =
* The work view has the cover art on the left, with the title, artist and audio player to its right.

= 0.56.2 =
* Phone works list: the cover art is on the left of each card and is a player (a play symbol shows on works with audio). Tapping anywhere else on the card opens the work.

= 0.56.1 =
* Phone works list: only an original work shows the work name. A version shows its release title, or "No release title" if it has none.

= 0.56.0 =
* Phones: the works list is a stack of cards. Each card has the cover art on the right, and on the left the work number, then the release title (the work title if there is none), then the artist, with the style at the far right of the artist line.

= 0.55.0 =
* The work view has the title, the artist and the audio player on the left and the cover art in the top right corner. Under it comes the best chart the work has: ChordPro, then chords and lyrics, then lyrics (the other levels stay one tap away). Credits move below the chart.

= 0.54.0 =
* Phones: the top of the page is the person's picture and name, and tapping it opens the profile (which has a "Works" link back). Under it comes the search box and the works list, with no column headings, no "Works" title and no Works/Profile tabs along the bottom.

= 0.53.1 =
* Phones: the Share button stays on the title row, the cover art box is a sensible size, and writer rows give the name its own room with IPI on a second line.

= 0.53.0 =
* A Share button replaces "View song" at the top right of the work editor. It opens a popup with the work's public link: copy it, or send it with your device's share sheet where one exists. The link shows the song only, never splits, codes, notes or money.

= 0.52.4 =
* The "View song" link is removed from the work editor.

= 0.52.3 =
* The Art and audio card and the Basics card are the same height. On the original work, Other titles grows to fill the space.

= 0.52.2 =
* The empty audio box shows an empty player instead of a description. Click it, or drop a file on it, to add audio.

= 0.52.1 =
* A "Cover art tool" button sits to the right of the Cover art label and opens the Art studio for that work in a new tab.

= 0.52.0 =
* Cover art is a drop box: drop an image on it or click it to pick one. Audio is a player box: drop a file on it or click it to choose from the WordPress Media Library. The upload buttons and link boxes are gone.

= 0.51.0 =
* Cover art and an audio player now sit at the top left, beside Basics, on every work, the original work included. On a phone they stack above Basics.

= 0.50.0 =
* The Share section is gone. Every song is shareable: each one has its public link from the moment it is made, and songs that had none get one on update. The link shows the song only, never splits, codes, notes or money.

= 0.49.0 =
* The Lists screen is gone. Statuses are the built-in set. The genre dropdown offers the built-in genres plus every genre typed on any work: a new one appears as soon as a work has it and disappears when no work does. Nothing to maintain.

= 0.48.4 =
* History now records when a new version is created (on the song it came from and on the version itself), when a release is scheduled (a future release date is set), and when a song is marked Released.
* Dropdowns are the same height, width and text size as the other fields.
* A version shows four writer rows, one performer and one producer unless more are filled in.

= 0.48.3 =
* The Status and Genre dropdowns now also offer the options from the old work editor (Starter, Scrapped, Scratch, Studio Demo, Pre Production, Production, Post Production, Taken Down, Outside; and Folk, Country, Pop, Rock, Americana, R&B, Hip-Hop, Gospel, Jazz, Electronic, Soundtrack, Other). No other dropdown changed.

= 0.48.2 =
* The heading of a version shows the work title again, not the release title. The release title stays in its own field.

= 0.48.1 =
* The date fields in the work editor (written on, copyright date, release date, produced on) open a calendar picker. A date that is old free text rather than YYYY-MM-DD stays a text box so it is never blanked.

= 0.48.0 =
* On a version, the top field is now "Release title" (the name of that release, often different from the work title). The work title itself is set on the original work and is the same on every version. The heading shows the release title when there is one.
* Works are never archived: every work is active. The archive buttons, notices and Archived tab are gone, and any work marked archived becomes active once, on update.

= 0.47.3 =
* Every version now carries the original work's title. A title a version had of its own moves to its Release title when that is empty, so no name is lost. A hint under the title on a version says renaming changes every version.
* Fix: the one-time catch-ups from 0.43.1 (clear copied per-version fields) only ran when the plugin was activated, not when it updated. They now run on update, together with the title change.

= 0.47.2 =
* DistroKid and UPC numbers now belong to versions, like the ISRC. They show on versions (tinted), not on the original work; they are not copied to new versions or synced between versions.

= 0.47.1 =
* The work editor no longer has the row of buttons (Registrations, Agreements, Pitch, Art studio, ChordPro tool) under the title. The serial number (NNNN-VV) is now the same size as the title and sits in line with it.

= 0.47.0 =
* On a version, the fields that belong to that version only (status, writing stage, release, art and audio, performers, producers, DistroKid and ISRC, links, style, prompts, private notes) are tinted blue, with a small key at the top. Shared fields keep the normal colour.

= 0.46.4 =
* Choosing a writer from the People list also fills in their picture (or initials), next to their IPI.
* The ChordPro tool now opens with whatever is in the ChordPro box every time, not only the first time. Before, opening it a second time without reloading the page showed the earlier text.

= 0.46.3 =
* DistroKid numbers are no longer shared between versions. In the old catalog 93 works have a different DistroKid number on each version, and syncing would have overwritten them. The original work can still hold its own DistroKid number; each version keeps its own, like the ISRC. Everything else shared stays shared.

= 0.46.2 =
* Publishing numbers now also list Copyright no., Work ID and UPC. A second number of the same kind gets its own field labelled "Alt" (Alt 2 for a third). Writers start with four rows and sit closer together.

= 0.46.1 =
* Publishing numbers are always listed, empty or not: ISWC, ASCAP, BMI, MLC, CCLI and DistroKid (plus the ISRC on versions). No more "Add a code". Any other code already saved is kept.

= 0.46.0 =
* Writer names are a pick list from the People table (you can still type a new name). Choosing a person fills in their IPI. Writer, IPI and share sit on one line, two writers to a row when the page is wide enough.

= 0.45.1 =
* Writers are laid out two to a row, each with name, share and IPI. The Account box is gone; a writer who was already linked to an account stays linked when you save.

= 0.45.0 =
* New Publishing card right under People and splits: publishing numbers (ISWC, ASCAP, BMI, MLC, CCLI, DistroKid and so on), then publishers and labels. Links now have their own card on versions only.
* On the original work, the Release card lists the released versions, each linked, with a short summary (date, artist, album, genre, key, tempo).

= 0.44.4 =
* The left rail stays put while the page scrolls, even when the theme wraps the page in something that would carry it away (a transform or similar): if the rail moves, the app lifts itself out of that wrapper.

= 0.44.3 =
* The original work's Codes and links card now opens by default, with the publishing numbers (ASCAP, BMI, MLC, CCLI, ISWC, DistroKid, UPC and others). All of them are shared by every version except the ISRC. A DistroKid number found on a version moves up to the original once.

= 0.44.2 =
* Artist is now chosen on each version, in the Release card next to the performing artist or band. The original work no longer shows it.

= 0.44.1 =
* The original work's People card now says its people are shared by every version.

= 0.44.0 =
* The original work (NNNN) now holds only the shared details: title, other titles, written on, copyright date, lyrics, chords, ChordPro, story, themes, writers, publishers, labels and ISWC/CCLI/MLC codes. Its editor no longer shows release, audio, art, style, prompts, notes, performers, producers or links.
* Saving any of those shared details, on the original or on a version, copies them to every other version of the song. Each version keeps its own per-version fields. History records "Shared details updated".

= 0.43.1 =
* One-time catch-up for existing versions: a per-version field that is still identical to the original work (style, prompts, notes, release details, audio, art and so on) is cleared; anything edited in the version is kept. Versions with no labels get the original's labels. Runs once on update.

= 0.43.0 =
* New versions now share only: title, other titles, written on, copyright date, lyrics, chords, ChordPro, story, themes, writers, publishers, labels and ISWC/CCLI/MLC codes. Status, writing stage, artist, release details, audio, art, style, prompts, private notes, performers, producers, ISRC/DistroKid codes, links and the pitch kit start fresh for each version.

= 0.42.0 =
* Work editor: Release is now its own open card after People and splits, holding everything that belongs to one version (release title and date, album, band, genre, key, tempo, time signature, format, produced on). A new version now starts with those, plus audio and art, empty; the title, lyrics, chords, story and writers carry over.

= 0.41.0 =
* Work editor simplified to one scrolling page of cards, like the old catalog: Basics, People and splits, Art and audio and Lyrics and chords are open; Release, Codes and links, Story and notes and Versions and sharing fold away until you click them. The side tabs are gone and the tools (Registrations, Agreements, Pitch, Art studio, ChordPro) sit in one row under the title.

= 0.40.1 =
* "Songs" is now "Works" everywhere (list heading, tabs, back links, Activity). The Works list has All registrations and All agreements buttons.

= 0.40.0 =
* Pitch: the rail shows a count of follow-ups that are due. A band reuses its bio across songs. A logged pitch can be changed to a different place or recipient. Logged pitches appear in the Activity log.

= 0.39.0 =
* Pitch: paid routes are back (Play MPE, Groover and others), and every place carries a badge: green Free, amber Free + paid, red Paid. Free ones are listed first.

= 0.38.3 =
* Pitch: every route is now one an independent can use directly. Removed Play MPE (label servicing), Pandora AMP and the DistroKid/YouTube entry (need an existing account).

= 0.38.2 =
* Pitch: removed Apple Music Pitch and Amazon Music for Artists (they need an artist profile you already have access to).

= 0.38.1 =
* Pitch: removed Spotify for Artists, since only artists who already have access can use it.

= 0.38.0 =
* Pitch rebuilt around a "Pitch a song" button. A four-step popup walks you through it: pick the work, add the files (upload a WAV, bio or photo, or search the Media Library) and bio, check publisher and rights, then pick where to send it and get the exact words to paste. The page itself now tracks your pitches, follow-ups and outcomes. Plain-language help on every step, drawn from Spotify, college radio and sync-library guidelines.

= 0.37.0 =
* New Pitch page in the rail: where to pitch (radio, playlists, worship, sync), ready-to-send radio, Spotify and email copy plus a one-sheet built from each work, and a pitch log with follow-up dates and outcomes. A Pitch button is also in the work editor.

= 0.36.3 =
* Rail: the subtitle fits on one line.

= 0.36.2 =
* Rail: the logo has the title "SongPress" and the subtitle "A songwriting and publishing" beside it.

= 0.36.1 =
* "Update now" in the WordPress settings updates in place and shows "Updated to version X" right there, instead of taking you to the plugin update screen. If WordPress cannot do it that way (for example it needs FTP details), the message says why and links to the usual update screen.

= 0.36.0 =
* Signing in now opens the works list, whichever app page you were on (a link to something specific, such as one song or a screen, still opens that).

= 0.35.7 =
* Rail: the page you are on is shown in the accent colour instead of a box around it.

= 0.35.6 =
* Rail: fixed a bug where the rail was only as tall as its links, so Settings and Log out did not reach the bottom. It is now exactly the height of the window.

= 0.35.5 =
* Rail: it is now the whole left edge of the window, full height, fixed in place while the page scrolls, with no box or shadow around it.

= 0.35.4 =
* Rail: Settings and Log out stay pinned to the bottom of the window (the rail now fits the window exactly, including under the WordPress toolbar).

= 0.35.3 =
* Rail: the extra gaps between links are gone.

= 0.35.2 =
* Rail: Settings sits at the bottom, just above Log out.

= 0.35.1 =
* "Money" is now called "Stats" in the rail, on its page and in the activity log.

= 0.35.0 =
* Settings now holds Activity log, Upload / Download and Export CSV as tabs beside Lists, Backups and Health. Their rail entries are gone.

= 0.34.0 =
* People now holds Bands, Labels, Publishers, Artists and Link writers as sections along the top. Their separate rail entries are gone.

= 0.33.0 =
* Every writer becomes a person with a login automatically. Writers from an import, or typed into a song, get their account without any button: a background top-up runs after an import, and checks about once an hour that no writer is left without one. The Create accounts button is gone; the Link writers tab now just reports progress and still lets you link a writer to an account you already have.

= 0.32.0 =
* Link writers is now a tab on the People screen instead of its own rail entry.

= 0.31.1 =
* The Rights entry is gone from the rail; Registrations and Agreements are reached from the buttons at the top of a work's editor.

= 0.31.0 =
* A work's editor has Registrations and Agreements buttons at the top: Registrations opens just that work's registrations; Agreements opens that work's agreements with the new-agreement form already filled in for it. Both have a "Show all" link. The Rights entry stays in the rail.

= 0.30.0 =
* Rail: Registrations and Agreements share one entry, "Rights", with the two as tabs.

= 0.29.0 =
* Sessions and Art studio are out of the rail (nothing is deleted; sessions are paused). The Art studio opens from an "Art studio" button at the top of a work's editor: its latest art project, or a new one for that work.

= 0.28.0 =
* The ChordPro tool is no longer in the rail; open it from the "ChordPro tool" button at the top of a work's editor (it loads that work's ChordPro and saves back to it). The editor's back link now reads "Works".

= 0.27.1 =
* Rail: the top link shows the signed-in person's name instead of "Profile".

= 0.27.0 =
* Rail: Profile is first, with your own picture beside it; "Songs" is now "Works", with Notes directly under it.

= 0.26.0 =
* Lists, Backups and Health now live inside Settings as tabs, so the rail is shorter. Their addresses and everything they do are unchanged.

= 0.25.0 =
* Notes: earlier versions (a copy of the note is kept before changes, at most every five minutes, newest 30; Restore puts it back in the editor); a position for each note, set on a small map by clicking, with "here" for your current location; author chips that filter the list, with the known authors suggested as you type; the image and audio buttons upload from your computer into the Media Library (Shift-click to paste an address instead); a Focus button (Esc to leave) that hides everything but the note; a link to the ChordPro tool.

= 0.24.0 =
* Art studio: Fill (bucket), Gradient, Rectangle, Ellipse (filled or outline), Select (drag a box; Delete clears it and later strokes stay inside it) and Pick colour join the Brush and Eraser. All work on drawing or picture layers and can be undone.

= 0.23.0 =
* Art studio: Brush and Eraser (size, flow, colour, undo, pen pressure) draw on a picture layer or on a new "+ Drawing" layer; drawings are saved to your Media Library when you save the project. "Make a picture with AI" sends your description (prefilled from the song's art prompt) to xAI with the key you paste in (stored on the site, never shown again), shows four pictures, and the one you click is added to the Media Library and the art.

= 0.22.0 =
* Backups (Managers): every SongPress table is saved to one compressed file in a private folder. One is taken for you before the risky things (loading the old catalog, uploading a statement, tidying or renaming logins, importing notes, deleting a session, agreement or artwork, starting fresh), at most every ten minutes, and you can "Back up now" by hand. Download any of them, or restore one; restoring first saves how things are now so it can be undone. The newest 15 are kept. The activity log and song history are never rewound.
* Health (Managers): checks that the plugin and database are up to date, every table exists, the server has what SongPress needs (zip, SQLite driver, compression, memory, upload size, a writable private folder), the pages exist, GitHub updates work, and when the last backup was. Fatal errors from the plugin are noted (last ten) with where they happened.

= 0.20.0 =
* Notes export for Bear: "Notes as Bear TextBundles" (Upload / Download, and "All for Bear (.textbundle)" on the Notes page) saves each note as a .textbundle folder in a zip, ready for Bear's File > Import. Each file carries the note's created time so Bear's "keep original creation and modification date" import option can use it. Importing that zip back into SongPress works too.

= 0.19.0 =
* Activity log (Managers only): a new rail page showing who did what and when across the app: song changes, notes, people and logins, money, sessions, uploads and downloads, agreements, registrations, art and sign-ins. Filter by area or person, search, page through, and download as CSV. Entries are never edited or removed.

= 0.15.0 =
* Notes looks and works like the old notes app again: a tag column (#'s, All, Pinned, Archived), a dated list with search, a large title and writing area with the floating toolbar (bold, italic, heading, list, link, ChordPro block, image, audio, preview, pin, archive, delete), and a details panel (date, created, location, authors, key, tempo, kind, songs mentioned, add a tag). Notes autosave. The + ↓ ↑ ☑ ↕ ▤ buttons add, import, export as .md, select several, sort and hide the tag column. Days and Map stay as links in the details panel.

= 0.14.0 =
* Notes: the "Created" date and time of a note can be changed when you edit it (the "Date" field is still the day the note is about). Notes loaded from the old catalog now keep their original creation time.
* Notes export as .bear: "Notes as Bear files" (and "Export all as .bear" on the Notes page) saves each note in the Bear layout (title, authors, "key | tempo | date", then the note), zipped. They import straight back.

= 0.13.0 =
* Notes import: on Upload / Download, "Notes" takes one or more .bear, .md or .txt files, a zip of them, or an old notes .json export. A Bear note reads its title, authors and "key | tempo | date" line; .md files can carry front matter (title, date, kind, pinned, key, tempo, authors, place). Files already loaded are skipped, and a note naming one song with ^0378 is linked to it.
* Notes export: "Notes as Markdown files" downloads every note as a .md file in a zip (front matter keeps the key, tempo, authors and place, so they import straight back). Both are linked from the top of the Notes page.

= 0.12.0 =
* The lyric process: every work has three levels, ChordPro, then chords and lyrics, then lyrics. A level left blank falls back to the highest level that is filled in, stepped down (ChordPro becomes chords over the words, then plain lyrics). The song editor shows what each blank level is using, and a song page can switch between the levels.
* Clicking into the ChordPro box in the song editor opens the ChordPro tool in a popup, loaded with that song's chart. "Use in song" puts the result back; "Type in the song form instead" lets you type directly. The tool still never touches the database.

= 0.11.0 =
* New ChordPro tool, a standalone page that anyone can open without logging in (yoursite/?sp_chordpro=1), linked from the rail and from the login screen. Write or paste ChordPro and see the chart with chords above the words. Transpose up or down (sharps or flats), turn chords-over-words text into ChordPro or back, insert verse/chorus/bridge sections, see guitar chord diagrams, set size and columns, open and save .pro, .txt and .html files, and print. It works in the browser only and never touches the catalog database. It also stands in for the desktop Songpress chord-sheet program as a web page, and the login screen points people looking for that program to it.

= 0.10.0 =
* Notes keeps the old notes app's other views: Days (what you wrote, worked on and released each day, with links to the songs, from the old catalog's activity history) and Map (notes that have a position, on an OpenStreetMap map).

= 0.9.0 =
* Journal is now Notes, the old S&P Catalog notes app: dated notes with kind, title, place, key, tempo and authors, pinning, archiving and search (authors too). The old notes load from the catalog file with their key, tempo, authors and map position, including notes loaded earlier.
* Write ^0378 or #0378-02 in a note to link the song. A note that names exactly one song is linked to it; opening a note lists the songs it mentions.
* Downloads: "Notes" replaces "Work journal" and includes the new fields.

= 0.8.0 =
* New Sessions in the rail: writing sessions with a date, time, place, the people in them (any number, with IPI and share), notes, lyrics, an audio link and a chat. A session can point at a song by serial.
* Managers see every session; a writer sees the sessions they are in. Chat and editing are for the desktop app.
* "Load the catalog file" on Upload / Download also brings across the old sessions, their writers and their chat (lyric changes read plainly).

= 0.7.2 =
* Tidy logins also replaces a leftover account from the old app that holds the plain login (it has no SongPress class), so abby.russ2 becomes abby.russ even then.

= 0.7.1 =
* People shows a "Tidy logins" button when logins end in a number (abby.russ2). It renames them to the plain first.last, or merges them into the plain account when it is the same person. Different people with the same name, and administrators, are left alone.
* New writers whose plain login already belongs to the same person now use that account instead of getting a number.

= 0.7.0 =
* Members and People are now one: a single People screen (and rail item). A person is a profile and a login together: photo, bio and IPI, plus class and password. People without a login can be given one; logins show on the list.
* Each person has an earnings report: all time and per source (ASCAP, MLC, DistroKid as a performer, CCLI), per quarter, and top songs. The list shows what each person has earned.
* Rename a person and their login together (first.last), with credits and earnings following the new name. Open your own person, check the name, and save to change adamjyo to adam.page; you will sign in again with the new login and the same password.
* DistroKid statements now go to the song's performers; ASCAP, MLC and CCLI go to the writers by their splits.
* Bands, Labels and Publishers keep their own lists.

= 0.6.0 =
* New Upload / Download page in the rail replaces every upload form in the app. Pick what you are uploading from a dropdown and its tool appears: Works, the old catalog file, photos and bios, the DistroKid stats page, and statements from DistroKid, MLC, ASCAP and CCLI (CSV or TSV; CCLI PDFs too where the server has pdftotext).
* Statements are matched to songs by ISRC, then title, saved per song, quarter and source (replacing what that source held for the quarter), and each writer's share is worked out from the credits. Lines with no matching song are reported, not lost silently.
* Downloads: pick a table (catalog, credits, earnings, earnings by person, payouts, expenses, streams, store figures, journal, ratings, registrations, agreements) and save it as a CSV.
* The Money, Stats and People screens now link to this page instead of carrying their own upload forms.

= 0.5.0 =
* New Registrations in the rail (Managers, desktop): for every work, where it stands at MLC, ASCAP, BMI, CCLI and for its ISWC. Green when the work has the code, gold when you mark it in progress (with the day it started, a reference number and a note), red when not filed.
* Filters: In progress, Waiting 30+ days, Released but not filed, All works. Marking never changes the song.
* "Load money" also brings across the old catalog's in-progress chips (about 380 on your file). The old catalog did not keep the dates they started, so those show no day count until you add one.

= 0.4.0 =
* New Agreements in the rail (Managers, desktop): cowriter split confirmations and publishing agreements, written from the song's writers, IPIs, splits, publisher, ISWC and copyright number.
* Every signer gets a private link to copy and send; they open it without logging in, type their name and tick a box. Nobody is emailed. A signature, once made, never changes. A printable page shows who signed and when.
* "Load money" also brings your old agreements across; their old signing and print links keep working.

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
