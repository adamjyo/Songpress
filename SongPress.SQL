-- SongPress.SQL
-- The SongPress database library. The plugin reads this file on activation and on every update, and
-- WordPress's dbDelta() adds anything missing, so a new column here reaches every site without losing data.
--
-- Placeholders: {prefix} is the WordPress table prefix, {charset} the site's character set.
-- Format rules for dbDelta: one field per line, two spaces after PRIMARY KEY, statements end with a semicolon.
--
-- There is no limit on authors, performers, producers or publishers. They are rows in songpress_credits,
-- not columns, so a song with 14 writers just has 14 rows. Adding a person never changes the tables.

CREATE TABLE {prefix}songpress_families (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  family_no int(10) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY family_no (family_no)
) {charset};

-- serial is 0012 for the original work (version_no 0) and 0012-01, 0012-02... for its versions.
-- Release information (artist, album, release date, ISRC, links) belongs to versions, not to the original.
CREATE TABLE {prefix}songpress_songs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  family_id bigint(20) unsigned NOT NULL,
  version_no smallint(5) unsigned NOT NULL DEFAULT 0,
  serial varchar(16) NOT NULL,
  title varchar(255) NOT NULL DEFAULT '',
  alt_titles text NULL,
  status varchar(24) NOT NULL DEFAULT 'idea',
  stage varchar(40) NOT NULL DEFAULT '',
  writing_date varchar(32) NOT NULL DEFAULT '',
  musical_key varchar(16) NOT NULL DEFAULT '',
  tempo varchar(16) NOT NULL DEFAULT '',
  time_signature varchar(16) NOT NULL DEFAULT '',
  genre varchar(64) NOT NULL DEFAULT '',
  artist varchar(191) NOT NULL DEFAULT '',
  album varchar(191) NOT NULL DEFAULT '',
  release_title varchar(191) NOT NULL DEFAULT '',
  release_date varchar(32) NOT NULL DEFAULT '',
  lyrics longtext NULL,
  chords_lyrics longtext NULL,
  chordpro longtext NULL,
  story longtext NULL,
  notes longtext NULL,
  audio_url text NULL,
  art_id bigint(20) unsigned NULL,
  audio_id bigint(20) unsigned NULL,
  art_url text NULL,
  owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
  revision int(10) unsigned NOT NULL DEFAULT 0,
  legacy_id bigint(20) unsigned NULL,
  legacy_outside_serial varchar(64) NOT NULL DEFAULT '',
  archived_at datetime NULL,
  share_token varchar(32) NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY archived_at (archived_at),
  UNIQUE KEY share_token (share_token),
  UNIQUE KEY serial (serial),
  UNIQUE KEY family_version (family_id,version_no),
  KEY title (title(100)),
  KEY status (status),
  KEY owner_id (owner_id),
  KEY legacy_id (legacy_id)
) {charset};

-- One row per person per role, any number of rows per song. role is writer, publisher, performer or producer.
-- position keeps the order they were entered in. user_id links a writer to a WordPress account.
CREATE TABLE {prefix}songpress_credits (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  song_id bigint(20) unsigned NOT NULL,
  role varchar(16) NOT NULL,
  name varchar(191) NOT NULL DEFAULT '',
  ipi varchar(32) NOT NULL DEFAULT '',
  share decimal(7,4) NULL,
  user_id bigint(20) unsigned NULL,
  position smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY song_id (song_id),
  KEY user_id (user_id),
  KEY name (name(100))
) {charset};

-- ISRC, ISWC, CCLI, MLC and other codes: any number per song.
CREATE TABLE {prefix}songpress_identifiers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  song_id bigint(20) unsigned NOT NULL,
  kind varchar(16) NOT NULL,
  value varchar(64) NOT NULL,
  PRIMARY KEY  (id),
  KEY song_id (song_id),
  KEY kind_value (kind,value)
) {charset};

-- Streaming and other links: any number per song.
CREATE TABLE {prefix}songpress_links (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  song_id bigint(20) unsigned NOT NULL,
  kind varchar(16) NOT NULL,
  url text NOT NULL,
  PRIMARY KEY  (id),
  KEY song_id (song_id)
) {charset};

-- A record of what changed on a song, who changed it and when. Rows are only ever added.
-- Songs are archived, never deleted, so serial numbers are never reused.
CREATE TABLE {prefix}songpress_history (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  song_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(24) NOT NULL,
  detail text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY song_id (song_id)
) {charset};
