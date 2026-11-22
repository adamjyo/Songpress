# SongPress

A song catalog manager for WordPress. Every song and every version under one serial number, a phone catalog, sharing links, and a profile for each writer.

- Originals are `0012`. Versions add two digits: `0012-01`, `0012-02`. Release information lives on versions.
- Each version has its own writers, performers, producers and splits.
- Three SongPress classes, separate from WordPress roles: Manager, Writer, Viewer. WordPress administrators are always Managers.
- Cover art and audio come from the WordPress Media Library, and files can be dragged onto the song editor.
- Share a song with a link that previews with its cover art and title in Messages.
- ChordPro charts render with chords above the words.
- Archive instead of delete, a history of changes, CSV export.
- Import from the old S&P Catalog SQLite file, with a dry-run report first.
- Updates itself from GitHub releases (can be turned off in SongPress > Settings).

## Install

Download `songpress-X.Y.Z.zip` from the latest [release](../../releases/latest), then in WordPress go to Plugins > Add New Plugin > Upload Plugin. SongPress creates a Catalog page and a My songs page when you activate it.

## Releasing a new version

1. Change the version in `songpress.php` (the `Version:` header and `SONGPRESS_VERSION`) and `readme.txt` (`Stable tag`).
2. Commit, then tag and push: `git tag v0.2.0 && git push origin v0.2.0`.
3. GitHub Actions checks the tag matches the plugin version, runs the tests, builds `songpress-0.2.0.zip` and publishes the release. Sites running SongPress then show the update under Dashboard > Updates within about 12 hours.

## Tests

```
php tests/run.php        # pure classes
php tests/versions.php   # versions, history, sharing, export, media (SQLite stand-in for the database)
```

## License

GPL-2.0-or-later. SongPress is not made or endorsed by WordPress, and is unrelated to the "Songpress" chord-typesetting program by Luca Allulli (Skeed).
