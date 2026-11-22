<?php
defined( 'ABSPATH' ) || exit;

/** Where to pitch a song: groups of channels with cost, timing advice and a link. */
return array (
  'playlists' => 
  array (
    'label' => 'Streaming playlists',
    'tip' => 'Start with the free ones. Pick curators who play your genre, and skip any list that asks you to pay for streams.',
    'items' => 
    array (
      1 => 
      array (
        'id' => 'apple',
        'name' => 'Apple Music Pitch',
        'cost' => 'Free',
        'blurb' => 'Editorial playlists, radio, and genre pages. Track must already be delivered and unreleased. Aim for 10+ days before release.',
        'url' => 'https://artists.apple.com/',
        'cta' => 'Open Apple Music for Artists',
      ),
      2 => 
      array (
        'id' => 'dailyplaylists',
        'name' => 'DailyPlaylists',
        'cost' => 'Free (30/week)',
        'blurb' => 'User and curator playlists. Zero-budget volume. Quality varies — skip any list that asks you to buy streams.',
        'url' => 'https://dailyplaylists.com/',
        'cta' => 'Open DailyPlaylists',
      ),
      3 => 
      array (
        'id' => 'submithub',
        'name' => 'SubmitHub',
        'cost' => 'Free credits + paid',
        'blurb' => 'Independent playlist curators and blogs. Use free Standard / Hot or Not to find active curators in your genre, then pitch those people.',
        'url' => 'https://www.submithub.com/',
        'cta' => 'Open SubmitHub',
      ),
      4 => 
      array (
        'id' => 'soundplate',
        'name' => 'Soundplate',
        'cost' => 'Free',
        'blurb' => 'Directory of independent Spotify playlists with a Submit button on each listing. Follow-to-submit. Stronger in electronic, gospel, and niche lists. Verify follower quality.',
        'url' => 'https://soundplate.com/submit-music-spotify-playlists',
        'cta' => 'Browse Soundplate lists',
      ),
      5 => 
      array (
        'id' => 'groover',
        'name' => 'Groover',
        'cost' => '~€2 per contact',
        'blurb' => 'Playlists, blogs, radio, bookers, some A&R and sync. Written reply in 7 days or credits back. Best after the free editorial pitches are in.',
        'url' => 'https://groover.co/',
        'cta' => 'Open Groover',
      ),
      6 => 
      array (
        'id' => 'musosoup',
        'name' => 'Musosoup',
        'cost' => 'Free to submit',
        'blurb' => 'You upload; they screen. Pay a campaign fee only if approved. Curators then offer playlist, blog, YouTube, or radio coverage. UK/EU leaning.',
        'url' => 'https://musosoup.com/',
        'cta' => 'Submit to Musosoup',
      ),
      7 => 
      array (
        'id' => 'amazon',
        'name' => 'Amazon Music for Artists',
        'cost' => 'Free',
        'blurb' => 'Claim the artist profile after DistroKid delivery. Use it for Amazon and Alexa playlist consideration and to watch where the song lands.',
        'url' => 'https://artists.amazon.com/',
        'cta' => 'Open Amazon for Artists',
      ),
      8 => 
      array (
        'id' => 'playlistpal',
        'name' => 'PlaylistPal',
        'cost' => '2 free / day',
        'blurb' => 'Small free daily cap with guaranteed feedback, then pay per extra submission. Use to test fit before spending on Groover/SubmitHub Premium.',
        'url' => 'https://playlistpal.io/',
        'cta' => 'Open PlaylistPal',
      ),
    ),
  ),
  'terrestrial' => 
  array (
    'label' => 'Terrestrial AM / FM',
    'tip' => 'Send the music director a stream link and a WAV download, plus a one-sheet. Never send a streaming link alone.',
    'items' => 
    array (
      0 => 
      array (
        'id' => 'airplaydirect',
        'name' => 'AirPlay Direct',
        'cost' => 'Free to join (upload fees later)',
        'blurb' => 'The actual terrestrial radio delivery box. Stations download broadcast-quality WAV + one-sheet. This is how independents service college, community, and some commercial FM without a plugger. Upload WAV, not a Spotify link.',
        'url' => 'https://www.airplaydirect.com/',
        'cta' => 'Open AirPlay Direct',
      ),
      1 => 
      array (
        'id' => 'radiolocator',
        'name' => 'Radio-Locator',
        'cost' => 'Free',
        'blurb' => 'Find AM/FM stations by city or zip. Filter format (AAA, country, gospel, college). Then pitch the music director by name with the radio email — WAV download, never a stream link.',
        'url' => 'https://radio-locator.com/',
        'cta' => 'Find local AM/FM',
      ),
      2 => 
      array (
        'id' => 'fcc-fm',
        'name' => 'FCC FM Query',
        'cost' => 'Free',
        'blurb' => 'Official US license database. Search your city for licensed FM, LPFM, and educational stations. Use call letters + Radio-Locator to get the MD email.',
        'url' => 'https://www.fcc.gov/media/radio/fm-query',
        'cta' => 'Open FCC FM Query',
      ),
      3 => 
      array (
        'id' => 'plaympe',
        'name' => 'Play MPE (Caster)',
        'cost' => 'Paid servicing',
        'blurb' => 'Industry standard for commercial FM, satellite, and some public radio. Labels use this. Only after AirPlay Direct + college FM if you want paid commercial servicing.',
        'url' => 'https://www.plaympe.com/',
        'cta' => 'Open Play MPE',
      ),
      4 => 
      array (
        'id' => 'soundexchange',
        'name' => 'SoundExchange',
        'cost' => 'Free to register',
        'blurb' => 'US AM/FM pays writers (ASCAP/BMI), not the master. HD, webcast, and SiriusXM do pay the recording. Register the ISRC so those spins get collected.',
        'url' => 'https://www.soundexchange.com/',
        'cta' => 'Register at SoundExchange',
      ),
    ),
  ),
  'radio' => 
  array (
    'label' => 'College, community & internet radio',
    'tip' => 'Send a stream link and a download link, plus a one-sheet. Flag explicit songs (they cannot air in the daytime). Do not follow up for two weeks and never pester the DJ on air.',
    'items' => 
    array (
      0 => 
      array (
        'id' => 'submithub-radio',
        'name' => 'SubmitHub Radio',
        'cost' => 'Free / credits',
        'blurb' => 'College, internet, and specialty radio programmers who list themselves on SubmitHub. Filter the directory by Radio.',
        'url' => 'https://www.submithub.com/discover?filter=radio',
        'cta' => 'Browse radio curators',
      ),
      1 => 
      array (
        'id' => 'radioairplay',
        'name' => 'RadioAirplay',
        'cost' => 'Free tier',
        'blurb' => 'College and indie radio submissions. Start with the free lane; paid boosts are optional.',
        'url' => 'https://www.radioairplay.com/',
        'cta' => 'Open RadioAirplay',
      ),
      2 => 
      array (
        'id' => 'prx',
        'name' => 'PRX Exchange',
        'cost' => 'Free to browse',
        'blurb' => 'Public-radio programming exchange. Better for finished features/interviews than cold singles, but useful contacts.',
        'url' => 'https://exchange.prx.org/',
        'cta' => 'Open PRX Exchange',
      ),
      3 => 
      array (
        'id' => 'bbc-introducing',
        'name' => 'BBC Introducing',
        'cost' => 'Free (UK)',
        'blurb' => 'Best free radio door for UK-based writers/artists. Upload via Introducing; regional shows and BBC radio actually listen. Needs a UK tie.',
        'url' => 'https://www.bbc.co.uk/introducing',
        'cta' => 'Open BBC Introducing',
      ),
      4 => 
      array (
        'id' => 'amazing-radio',
        'name' => 'Amazing Radio',
        'cost' => 'Free to submit',
        'blurb' => 'Indie-only internet radio (UK + US). Built to play unsigned and self-released music. Direct artist submissions.',
        'url' => 'https://amazingradio.com/',
        'cta' => 'Open Amazing Radio',
      ),
      5 => 
      array (
        'id' => 'rotation',
        'name' => 'Rotation',
        'cost' => 'Free',
        'blurb' => 'Directory of US college, community, HBCU, and indie stations that are accepting right now, with each station’s rules. No paid placements.',
        'url' => 'https://getinrotation.com/',
        'cta' => 'Find stations on Rotation',
      ),
      6 => 
      array (
        'id' => 'spinitron',
        'name' => 'Spinitron',
        'cost' => 'Free',
        'blurb' => 'Playlist system for hundreds of college/community stations. After you pitch, search your artist name to see if you got spins.',
        'url' => 'https://spinitron.com/',
        'cta' => 'Search Spinitron',
      ),
      7 => 
      array (
        'id' => 'pandora-amp',
        'name' => 'Pandora AMP',
        'cost' => 'Free',
        'blurb' => 'Artist Marketing Platform. Pitch already-released tracks into Pandora’s catalog/radio. US-heavy streaming radio, not FM.',
        'url' => 'https://amp.pandora.com/',
        'cta' => 'Open Pandora AMP',
      ),
      8 => 
      array (
        'id' => 'nacc',
        'name' => 'NACC college charts',
        'cost' => 'Free to read',
        'blurb' => 'North American College & Community radio charts. See which stations report your genre, then pitch those music directors with the email copy.',
        'url' => 'https://naccchart.com/',
        'cta' => 'Open NACC',
      ),
    ),
  ),
  'christian' => 
  array (
    'label' => 'Christian / worship',
    'tip' => 'Register the song with CCLI first so churches can report it. Lead with the song\'s story and lyrics.',
    'items' => 
    array (
      0 => 
      array (
        'id' => 'ccli',
        'name' => 'CCLI SongSelect',
        'cost' => 'Free to register',
        'blurb' => 'How churches report your song. Register the work (title, writers, publisher) before street date so CCLI royalties can match. Lead sheets already live in this catalog.',
        'url' => 'https://us.ccli.com/',
        'cta' => 'Open CCLI',
      ),
      1 => 
      array (
        'id' => 'praisecharts',
        'name' => 'PraiseCharts',
        'cost' => 'Apply (seasonal)',
        'blurb' => 'Church charts and Emerging Artist program. Churches buy charts; you keep the copyright. Apply Jan–Feb or May–Sep.',
        'url' => 'https://www.praisecharts.com/ea-terms',
        'cta' => 'PraiseCharts Emerging Artist',
      ),
      2 => 
      array (
        'id' => 'nrt',
        'name' => 'NewReleaseToday',
        'cost' => 'Free',
        'blurb' => 'The main Christian new-music desk. Reviews, playlists, and church discovery. Pitch as worship/CCM/gospel with a CCLI number if you have one.',
        'url' => 'https://www.newreleasetoday.com/',
        'cta' => 'Open NewReleaseToday',
      ),
      3 => 
      array (
        'id' => 'jfh',
        'name' => 'Jesusfreakhideout',
        'cost' => 'Free',
        'blurb' => 'Long-running Christian indie reviews. Send promo with WAV/MP3, one-sheet, and lyrics. Not a pay-to-play blog.',
        'url' => 'https://www.jesusfreakhideout.com/jd/promo.asp',
        'cta' => 'JFH promo',
      ),
      4 => 
      array (
        'id' => 'worshiptogether',
        'name' => 'Worship Together',
        'cost' => 'Free to browse',
        'blurb' => 'Capitol CMG church resource. Charts and resources for congregational songs. Higher bar; still the page worship leaders actually use.',
        'url' => 'https://www.worshiptogether.com/',
        'cta' => 'Open Worship Together',
      ),
      5 => 
      array (
        'id' => 'multitracks',
        'name' => 'MultiTracks / Loop Community',
        'cost' => 'Apply',
        'blurb' => 'Stems for church bands. Apply once the song is being sung, not on demo day.',
        'url' => 'https://www.multitracks.com/',
        'cta' => 'Open MultiTracks',
      ),
      6 => 
      array (
        'id' => 'christianbeat',
        'name' => 'The Christian Beat',
        'cost' => 'Free',
        'blurb' => 'Christian news and reviews. Short pitch + stream link. Good for CCM/pop worship, not a substitute for CCLI.',
        'url' => 'https://www.thechristianbeat.org/',
        'cta' => 'Open The Christian Beat',
      ),
    ),
  ),
  'sync' => 
  array (
    'label' => 'Sync (film, TV, ads, games)',
    'tip' => 'Libraries want WAV (48 kHz / 24-bit), plus a clean version if explicit, an instrumental, and stems. Have the writer splits agreed in writing and no uncleared samples.',
    'items' => 
    array (
      0 => 
      array (
        'id' => 'songtradr',
        'name' => 'Songtradr',
        'cost' => 'Free account',
        'blurb' => 'Non-exclusive marketplace. Upload, tag mood/genre, set your own license types. Supervisors browse pre-cleared catalogs.',
        'url' => 'https://www.songtradr.com/',
        'cta' => 'Open Songtradr',
      ),
      1 => 
      array (
        'id' => 'pond5',
        'name' => 'Pond5',
        'cost' => 'Free to upload',
        'blurb' => 'Stock music library. Strong for production cues. Tag thoroughly (mood, instruments, BPM).',
        'url' => 'https://www.pond5.com/sell',
        'cta' => 'Sell on Pond5',
      ),
      2 => 
      array (
        'id' => 'musicbed',
        'name' => 'Musicbed',
        'cost' => 'Free to apply',
        'blurb' => 'Curated. Higher bar, better fees. Apply with your strongest, fully cleared masters and instrumentals.',
        'url' => 'https://www.musicbed.com/artists',
        'cta' => 'Apply to Musicbed',
      ),
      3 => 
      array (
        'id' => 'youtube-audio',
        'name' => 'YouTube Audio Library / DistroKid Social',
        'cost' => 'Free with DistroKid pack',
        'blurb' => 'If the work is already on DistroKid, the Social Media Pack can put instrumentals in YouTube Creator Music. No extra site to join.',
        'url' => 'https://distrokid.com/',
        'cta' => 'Open DistroKid',
      ),
    ),
  ),
);
