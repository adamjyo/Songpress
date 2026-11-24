/* SongPress Transcriber: the panel above the ChordPro editor. Needs transcriber-core.js and window.SP_TR. */
(function () {
  'use strict';
  var T = window.SPTranscribe, C = window.SP_TR || {};
  if (!T) return;
  function $(id) { return document.getElementById(id); }
  var ed = $('ct-text'), file = $('tr-file'), go = $('tr-go'), msg = $('tr-msg'), prog = $('tr-prog'), lyr = $('tr-lyrics');
  var audio = $('tr-audio'), found = $('tr-found'), chips = $('tr-chips'), sum = $('tr-sum'), tapBox = $('tr-tap'), linesBox = $('tr-lines');
  var S = { file: null, segs: [], key: '', bpm: 0, duration: 0, heard: null, matched: null, taps: [], url: '' };

  function say(t, cls) { msg.textContent = t || ''; msg.className = 'msg' + (cls ? ' ' + cls : ''); }
  function bar(v) { prog.hidden = v == null; if (v != null) prog.value = v; }
  function fmt(t) { var m = Math.floor(t / 60), s = Math.floor(t % 60); return m + ':' + (s < 10 ? '0' : '') + s; }

  /* ---------- the recording ---------- */

  function decode(buf) {
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return Promise.reject(new Error('This browser cannot read audio.'));
    var ctx = new AC();
    return new Promise(function (ok, no) { var p = ctx.decodeAudioData(buf, ok, no); if (p && p.then) p.then(ok, no); })
      .then(function (ab) {
        var n = ab.length, ch = ab.numberOfChannels, mono = new Float32Array(n), c, i;
        for (c = 0; c < ch; c++) { var d = ab.getChannelData(c); for (i = 0; i < n; i++) mono[i] += d[i] / ch; }
        var sr = ab.sampleRate; try { ctx.close(); } catch (e) {}
        return { samples: mono, sr: sr };
      });
  }

  /** A small mono 16 kHz WAV: plenty for speech, and a four minute song stays near 8 MB. */
  function wav16(samples, sr) {
    var x = T.resample(samples, sr, 16000), n = x.length, buf = new ArrayBuffer(44 + n * 2), v = new DataView(buf), i;
    function str(o, s) { for (var k = 0; k < s.length; k++) v.setUint8(o + k, s.charCodeAt(k)); }
    str(0, 'RIFF'); v.setUint32(4, 36 + n * 2, true); str(8, 'WAVE'); str(12, 'fmt '); v.setUint32(16, 16, true); v.setUint16(20, 1, true); v.setUint16(22, 1, true);
    v.setUint32(24, 16000, true); v.setUint32(28, 32000, true); v.setUint16(32, 2, true); v.setUint16(34, 16, true); str(36, 'data'); v.setUint32(40, n * 2, true);
    var peak = 0; for (i = 0; i < n; i++) if (Math.abs(x[i]) > peak) peak = Math.abs(x[i]);
    var g = peak > 0 ? 0.9 / peak : 1; // even out a quiet recording
    for (i = 0; i < n; i++) v.setInt16(44 + i * 2, Math.max(-1, Math.min(1, x[i] * g)) * 32767, true);
    return new Blob([buf], { type: 'audio/wav' });
  }

  /** The chord work, in a worker so the page stays alive. */
  function analyze(samples, sr, opt) {
    var tag = document.querySelector('script[src*="transcriber-core.js"]');
    if (!tag || !window.Worker) return Promise.resolve(T.analyze(samples, sr, opt));
    return fetch(tag.src).then(function (r) { return r.text(); }).then(function (src) {
      return new Promise(function (ok, no) {
        var code = src + ';self.onmessage=function(e){try{var r=SPTranscribe.analyze(e.data.s,e.data.sr,e.data.opt);postMessage({r:r})}catch(x){postMessage({e:String(x)})}};';
        var w = new Worker(URL.createObjectURL(new Blob([code], { type: 'text/javascript' })));
        w.onmessage = function (e) { w.terminate(); e.data.e ? no(new Error(e.data.e)) : ok(e.data.r); };
        w.onerror = function (e) { w.terminate(); no(new Error(e.message || 'worker')); };
        w.postMessage({ s: samples, sr: sr, opt: opt });
      });
    }).catch(function () { return T.analyze(samples, sr, opt); }); // no worker: do it here
  }

  function heardWords(wavBlob, lyricsText) {
    var fd = new FormData();
    fd.append('action', 'songpress_tr_align'); fd.append('n', C.nonce);
    fd.append('audio', wavBlob, 'recording.wav'); fd.append('lang', ($('tr-lang').value || '').trim());
    fd.append('prompt', lyricsText.replace(/\s+/g, ' ').trim().slice(0, 700));
    return fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (t) {
      var j; try { j = JSON.parse(t); } catch (e) { throw new Error('The server did not answer properly. The recording may be larger than it allows.'); }
      if (!j.success) { var er = new Error((j.data && j.data.msg) || 'Failed'); er.nokey = j.data && (j.data.nokey || j.data.badkey); throw er; }
      return j.data.words;
    });
  }

  /* ---------- building the chart ---------- */

  function lineObjs() { return T.parseLyrics(lyr.value); }
  function timedCount(l) { return l.filter(function (x) { return x.kind === 'line'; }).length; }

  function build() {
    var lines = lineObjs(), o = { title: $('tr-title').value.trim(), artist: $('tr-artist').value.trim(), key: S.key, bpm: S.bpm, lines: lines, segs: S.segs, duration: S.duration };
    var n = timedCount(lines), how = '';
    if (n && S.heard) {
      var al = T.alignWords(lines, S.heard); S.matched = al.matched; o.align = al.lines.filter(function (a) { return a.t0 != null; });
      if (o.align.length < n) { o.align = null; }
      how = 'words';
    }
    if (!o.align && n && S.taps.length) {
      var d = parseFloat(($('tr-delay') || {}).value); o.times = S.taps.map(function (t) { return Math.max(0, t - (isNaN(d) ? 0.2 : d)); }); how = 'taps';
    }
    if (!o.align && !o.times && n && S.segs.length) { // nothing timed the words: spread the lines over the recording so the chords still land inside them
      var st = S.segs[0].t0 || 0, en = S.duration || S.segs[S.segs.length - 1].t1;
      o.times = T.spread(n, st, en); how = 'spread';
    }
    var pro = T.buildChordPro(o);
    ed.value = pro; ed.dispatchEvent(new Event('input', { bubbles: true }));
    var bits = ['Key ' + S.key];
    if (S.bpm) bits.push('about ' + S.bpm + ' BPM');
    bits.push(S.segs.length + ' chord changes');
    if (how === 'words') bits.push(Math.round((S.matched || 0) * 100) + '% of the lyric words were found in the singing');
    else if (how === 'taps') bits.push('lined up from your taps');
    else if (how === 'spread') bits.push('chords placed roughly: the lines were spread evenly over the recording. Add the word timing key, or tap the lines in, to place them exactly');
    else if (n) bits.push('lyrics not lined up yet: use the timing service or tap them in');
    sum.textContent = bits.join(' · ');
    if (how === 'words' && S.matched < 0.4) say('Few lyric words were found in the recording. Check the lyrics match what is sung, or line them up by tapping.', 'err');
    return pro;
  }

  function drawChips() {
    chips.innerHTML = '';
    S.segs.forEach(function (g, i) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'ct-chip'; b.textContent = g.chord; b.title = fmt(g.t0);
      b.addEventListener('click', function () { audio.currentTime = Math.max(0, g.t0 - 0.2); audio.play(); });
      b.addEventListener('dblclick', function () { var v = window.prompt('Chord at ' + fmt(g.t0), g.chord); if (v && v.trim()) { g.chord = v.trim(); drawChips(); } });
      var w = document.createElement('span'); w.textContent = ' '; chips.appendChild(b);
    });
  }

  audio.addEventListener('timeupdate', function () {
    var t = audio.currentTime, k = -1;
    S.segs.forEach(function (g, i) { if (t >= g.t0) k = i; });
    [].forEach.call(chips.children, function (c, i) { c.classList.toggle('on', i === k); });
  });

  /* ---------- the run ---------- */

  file.addEventListener('change', function () { S.file = file.files[0] || null; go.disabled = !S.file; if (S.file && !$('tr-title').value) $('tr-title').value = S.file.name.replace(/\.[a-z0-9]+$/i, '').replace(/[_]+/g, ' '); });

  go.addEventListener('click', function () {
    if (!S.file) return;
    go.disabled = true; found.hidden = true; S.heard = null; S.taps = []; bar(2); say('Reading the recording…');
    var lyrics = lyr.value, wantWords = timedCount(lineObjs()) > 0, dec;
    S.file.arrayBuffer().then(decode).then(function (d) {
      dec = d; S.duration = d.samples.length / d.sr;
      if (S.duration > 13 * 60) throw new Error('That recording is over 13 minutes. Cut it down to one song.');
      bar(15); say('Listening for chords…');
      var a = analyze(d.samples, d.sr, { vocab: $('tr-vocab').value });
      var timing = Promise.resolve(null);
      if (wantWords) {
        if (!C.can) timing = Promise.reject(Object.assign(new Error('Log in to have the words timed automatically.'), { soft: true }));
        else if (!C.hasKey) timing = Promise.reject(Object.assign(new Error('Word timing needs a key. A manager adds the free key in Settings (Word timing); the note under "Word timing service" below says how.'), { soft: true, nokey: true }));
        else timing = heardWords(wav16(d.samples, d.sr), lyrics);
      }
      return Promise.all([a, timing.then(function (w) { return { w: w }; }, function (e) { return { err: e }; })]);
    }).then(function (r) {
      var res = r[0], t = r[1];
      S.segs = res.chords; S.key = res.keyName; S.bpm = res.tempoConfidence > 0.15 ? res.bpm : 0;
      if (t.w) S.heard = t.w;
      if (t.err) { say(t.err.message, t.err.soft ? '' : 'err'); if (t.err.nokey) $('tr-svc').open = true; }
      if (S.url) URL.revokeObjectURL(S.url);
      S.url = URL.createObjectURL(S.file); audio.src = S.url;
      found.hidden = false; drawChips(); build();
      tapBox.hidden = !(wantWords && !S.heard) ; if (!tapBox.hidden) { tapBox.open = true; drawLines(); }
      if (!t.err) say(S.heard ? 'Done. Check the chart on the right and fix anything that is off.' : 'Done. The chords are in the editor below.', 'ok');
      bar(null);
    }).catch(function (e) { say(e.message || String(e), 'err'); bar(null); }).then(function () { go.disabled = !S.file; });
  });

  $('tr-build').addEventListener('click', function () { build(); });

  /* ---------- tapping the lines in (when there is no timing service) ---------- */

  function drawLines() {
    var ls = lineObjs().filter(function (l) { return l.kind === 'line'; });
    linesBox.innerHTML = '';
    ls.forEach(function (l, i) {
      var d = document.createElement('div'), b = document.createElement('b'); b.textContent = S.taps[i] != null ? fmt(S.taps[i]) : '—:—';
      d.appendChild(b); d.appendChild(document.createTextNode(l.text)); if (i === S.taps.length) d.className = 'cur';
      d.addEventListener('click', function () { if (S.taps[i] != null) { audio.currentTime = Math.max(0, S.taps[i] - 0.5); audio.play(); } });
      linesBox.appendChild(d);
    });
    var cur = linesBox.querySelector('.cur'); if (cur && cur.scrollIntoView) cur.scrollIntoView({ block: 'nearest' });
    return ls.length;
  }
  function tap() {
    var n = timedCount(lineObjs()); if (S.taps.length >= n) return;
    S.taps.push(audio.currentTime); drawLines(); if (S.taps.length === n) build();
  }
  $('tr-tapbtn').addEventListener('click', tap);
  $('tr-undo').addEventListener('click', function () { S.taps.pop(); drawLines(); });
  $('tr-reset').addEventListener('click', function () { S.taps = []; drawLines(); });
  document.addEventListener('keydown', function (e) {
    if (e.code !== 'Space' || tapBox.hidden || !tapBox.open) return;
    var t = e.target && e.target.tagName; if (t === 'TEXTAREA' || t === 'INPUT' || t === 'SELECT' || t === 'BUTTON') return;
    e.preventDefault(); tap();
  });
  var dl = document.createElement('label'); dl.innerHTML = '<small>Tap delay (s)</small> <input type="text" id="tr-delay" value="0.2" size="3" class="ct-b">';
  $('tr-reset').parentNode.appendChild(dl);

  /* ---------- the service box ---------- */

  function drawService() {
    var b = $('tr-svcbox'); b.innerHTML = '';
    function p(h) { var d = document.createElement('p'); d.className = 'msg'; d.innerHTML = h; b.appendChild(d); }
    p('Word timing uses the free <a href="https://console.groq.com/docs/speech-to-text" target="_blank" rel="noopener">Groq</a> speech service (Whisper). Only a small mono copy of the recording is sent, through this site. Nothing is kept.');
    if (!C.can) { p('<a href="' + C.login + '">Log in</a> to use it.'); return; }
    if (C.hasKey) { p('A key is saved for this site.'); return; }
    p(C.settings ? 'No key is saved yet. Add the free key in <a href="' + C.settings + '">Settings</a> (Word timing).' : 'No key is saved yet. Ask a manager to add it in Settings.');
  }
  drawService();

  /* ---------- inside the work editor: take its text and give the chart back ---------- */

  window.addEventListener('message', function (e) {
    if (e.origin !== location.origin || !e.data || e.data.type !== 'ct-load') return;
    var t = String(e.data.text || ''), m = /\{\s*title\s*:\s*([^}]*)\}/i.exec(t);
    if (m && !$('tr-title').value) $('tr-title').value = m[1].trim();
    if (!lyr.value.trim() && t.trim()) {
      lyr.value = t.replace(/\{\s*(title|t|subtitle|st|artist|key|tempo|time|capo|ccli|iswc|copyright)\b[^}]*\}/ig, '').replace(/\{\s*(?:comment|c)\s*:\s*([^}]*)\}/ig, '$1:').replace(/\{[^}]*\}/g, '').replace(/\[[^\]]*\]/g, '').replace(/\n{3,}/g, '\n\n').trim();
    }
  });
}());
