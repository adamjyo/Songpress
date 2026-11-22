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
  artist_id bigint(20) unsigned NOT NULL DEFAULT 0,
  archived_at datetime NULL,
  share_token varchar(32) NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY archived_at (archived_at),
  KEY artist_id (artist_id),
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

-- An artist is a band, a solo act or a record label. One site can hold many. Serial numbers are shared across all of them.
-- settings is JSON: default writer and publisher, logo, accent color, default art, links. New settings need no schema change.
CREATE TABLE {prefix}songpress_artists (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL,
  slug varchar(100) NOT NULL,
  settings longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) {charset};

-- A face for a name: photo, bio and contact details for a person, a band or a publisher.
-- Matched to credits by name (lowercased, spaces collapsed). Photos are Media Library attachments.
CREATE TABLE {prefix}songpress_profiles (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(12) NOT NULL DEFAULT 'person',
  name varchar(190) NOT NULL,
  name_key varchar(190) NOT NULL,
  aliases text,
  photo_id bigint(20) unsigned NOT NULL DEFAULT 0,
  photo_url text,
  bio text,
  email varchar(190) NOT NULL DEFAULT '',
  phone varchar(64) NOT NULL DEFAULT '',
  website text,
  ipi varchar(32) NOT NULL DEFAULT '',
  pro varchar(64) NOT NULL DEFAULT '',
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY kind_key (kind,name_key)
) {charset};

-- Money. Earnings per song per quarter and source (distrokid, mlc, ascap, ccli), as reported by the
-- statements that were imported. Rows are replaced when money is imported again.
CREATE TABLE {prefix}songpress_earnings (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  song_id bigint(20) unsigned NOT NULL,
  quarter varchar(8) NOT NULL,
  src varchar(12) NOT NULL,
  amount decimal(16,6) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY song_quarter_src (song_id,quarter,src),
  KEY quarter (quarter)
) {charset};

-- What each person earned per song, quarter and source (their share of the statements).
CREATE TABLE {prefix}songpress_person_earnings (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  person varchar(191) NOT NULL,
  person_key varchar(191) NOT NULL,
  work_title varchar(255) NOT NULL DEFAULT '',
  song_id bigint(20) unsigned NOT NULL DEFAULT 0,
  quarter varchar(8) NOT NULL,
  src varchar(12) NOT NULL,
  amount decimal(16,6) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY person_key (person_key),
  KEY quarter (quarter),
  KEY song_id (song_id)
) {charset};

-- Payouts: what was paid to whom for a quarter, and when. Entered by hand, never replaced by an import.
CREATE TABLE {prefix}songpress_payments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  pay_year smallint(5) unsigned NOT NULL,
  pay_quarter tinyint(3) unsigned NOT NULL,
  kind varchar(12) NOT NULL DEFAULT 'writer',
  party varchar(191) NOT NULL,
  party_key varchar(191) NOT NULL,
  amount decimal(14,2) NOT NULL DEFAULT 0,
  paid tinyint(1) NOT NULL DEFAULT 0,
  paid_on date NULL,
  notes text NULL,
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY pay_key (pay_year,pay_quarter,kind,party_key)
) {charset};

-- Streams per day and service (estimates), and streams and income per month, store and title.
CREATE TABLE {prefix}songpress_streams (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  day date NOT NULL,
  service varchar(48) NOT NULL,
  streams int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY day_service (day,service)
) {charset};

CREATE TABLE {prefix}songpress_stats (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  row_key char(32) NOT NULL,
  month char(7) NOT NULL,
  store varchar(64) NOT NULL DEFAULT '',
  title varchar(255) NOT NULL DEFAULT '',
  isrc varchar(24) NOT NULL DEFAULT '',
  song_id bigint(20) unsigned NOT NULL DEFAULT 0,
  units bigint(20) NOT NULL DEFAULT 0,
  earnings decimal(16,6) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY row_key (row_key),
  KEY month (month),
  KEY song_id (song_id)
) {charset};

CREATE TABLE {prefix}songpress_expenses (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  spent_on date NOT NULL,
  payee varchar(191) NOT NULL DEFAULT '',
  category varchar(64) NOT NULL DEFAULT '',
  amount decimal(12,2) NOT NULL DEFAULT 0,
  song_id bigint(20) unsigned NOT NULL DEFAULT 0,
  person varchar(191) NOT NULL DEFAULT '',
  notes text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY spent_on (spent_on)
) {charset};

-- Work journal: dated notes about the work, optionally tied to a song. Entered by hand; the old journal can be loaded once.
CREATE TABLE {prefix}songpress_journal (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  log_date date NOT NULL,
  kind varchar(16) NOT NULL DEFAULT 'day',
  title varchar(255) NOT NULL DEFAULT '',
  notes longtext NULL,
  place varchar(191) NOT NULL DEFAULT '',
  song_id bigint(20) unsigned NOT NULL DEFAULT 0,
  pinned tinyint(1) NOT NULL DEFAULT 0,
  archived tinyint(1) NOT NULL DEFAULT 0,
  source_key varchar(64) NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_key (source_key),
  KEY log_date (log_date),
  KEY song_id (song_id)
) {charset};

-- Star ratings for songs (1 to 5), one per voter.
CREATE TABLE {prefix}songpress_ratings (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  song_id bigint(20) unsigned NOT NULL,
  voter varchar(64) NOT NULL,
  stars tinyint(3) unsigned NOT NULL,
  created_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY song_voter (song_id,voter)
) {charset};

-- Art studio: layered cover-art projects. Layers are kept as one JSON list (images, text, shapes, a canvas colour).
CREATE TABLE {prefix}songpress_art (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  song_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(191) NOT NULL DEFAULT '',
  layers longtext NULL,
  preview_id bigint(20) unsigned NOT NULL DEFAULT 0,
  preview_url varchar(500) NOT NULL DEFAULT '',
  source_key varchar(64) NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_key (source_key),
  KEY song_id (song_id)
) {charset};
