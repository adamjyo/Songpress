/* SongPress Transcriber: the analysis engine. No DOM here, so it runs in a browser and under node.
   audio samples -> chroma -> key, tempo, chord timeline;  chord timeline + lyric line times -> ChordPro text. */
(function (root) {
  'use strict';

  var FLAT = ['C', 'Db', 'D', 'Eb', 'E', 'F', 'Gb', 'G', 'Ab', 'A', 'Bb', 'B'];
  var SHARP = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
  var FLAT_KEYS = { 5: 1, 10: 1, 3: 1, 8: 1, 1: 1, 6: 1 }; // major tonics that read in flats: F Bb Eb Ab Db Gb
  var TYPES = [
    { id: '', iv: [0, 4, 7], seventh: false },
    { id: 'm', iv: [0, 3, 7], seventh: false },
    { id: '7', iv: [0, 4, 7, 10], seventh: true },
    { id: 'maj7', iv: [0, 4, 7, 11], seventh: true },
    { id: 'm7', iv: [0, 3, 7, 10], seventh: true }
  ];
  var MAJ_PROFILE = [6.35, 2.23, 3.48, 2.33, 4.38, 4.09, 2.52, 5.19, 2.39, 3.66, 2.29, 2.88];
  var MIN_PROFILE = [6.33, 2.68, 3.52, 5.38, 2.60, 3.53, 2.54, 4.75, 3.98, 2.69, 3.34, 3.17];

  /* ---------- signal ---------- */

  /** Lower the sample rate: average over each output step (a cheap low-pass), then interpolate. */
  function resample(x, srIn, srOut) {
    if (srIn === srOut) return x;
    var ratio = srIn / srOut, n = Math.floor(x.length / ratio), y = new Float32Array(n), w = Math.max(1, Math.round(ratio));
    for (var i = 0; i < n; i++) {
      var c = Math.floor(i * ratio), s = 0, k = 0;
      for (var j = 0; j < w && c + j < x.length; j++) { s += x[c + j]; k++; }
      y[i] = k ? s / k : 0;
    }
    return y;
  }

  function fft(re, im) { // in place, radix-2
    var n = re.length, i, j, k, m;
    for (i = 1, j = 0; i < n; i++) {
      var bit = n >> 1;
      for (; j & bit; bit >>= 1) j ^= bit;
      j ^= bit;
      if (i < j) { var tr = re[i]; re[i] = re[j]; re[j] = tr; var ti = im[i]; im[i] = im[j]; im[j] = ti; }
    }
    for (var len = 2; len <= n; len <<= 1) {
      var ang = -2 * Math.PI / len, wr = Math.cos(ang), wi = Math.sin(ang), half = len >> 1;
      for (i = 0; i < n; i += len) {
        var cr = 1, ci = 0;
        for (k = 0; k < half; k++) {
          var a = i + k, b = a + half;
          var xr = re[b] * cr - im[b] * ci, xi = re[b] * ci + im[b] * cr;
          re[b] = re[a] - xr; im[b] = im[a] - xi; re[a] += xr; im[a] += xi;
          m = cr * wr - ci * wi; ci = cr * wi + ci * wr; cr = m;
        }
      }
    }
  }

  /** 12-bin pitch-class profile for every hop, plus loudness and an onset curve (for tempo). */
  function chromagram(x, sr, opt) {
    opt = opt || {};
    var N = opt.fft || 4096, hop = opt.hop || 1024, lo = opt.lo || 100, hi = opt.hi || 2000;
    var win = new Float32Array(N), re = new Float32Array(N), im = new Float32Array(N), i;
    for (i = 0; i < N; i++) win[i] = 0.5 - 0.5 * Math.cos(2 * Math.PI * i / (N - 1));
    var bins = [], b0 = Math.ceil(lo * N / sr), b1 = Math.min(N / 2 - 1, Math.floor(hi * N / sr));
    for (var b = b0; b <= b1; b++) {
      var midi = 69 + 12 * Math.log(b * sr / N / 440) / Math.LN2, r = Math.round(midi), d = Math.abs(midi - r);
      var w = Math.max(0, 1 - d * 2.2);
      if (w > 0) bins.push([b, ((r % 12) + 12) % 12, w * (1 / (1 + (b * sr / N - lo) / 800))]); // low notes count a little more
    }
    var of0 = Math.ceil(60 * N / sr), of1 = Math.min(N / 2 - 1, Math.floor(4000 * N / sr));
    var frames = Math.max(0, Math.floor((x.length - N) / hop) + 1);
    var chroma = new Array(frames), energy = new Float32Array(frames), onset = new Float32Array(frames), prev = new Float32Array(of1 + 1);
    for (var f = 0; f < frames; f++) {
      var off = f * hop, e = 0;
      for (i = 0; i < N; i++) { var v = x[off + i] * win[i]; re[i] = v; im[i] = 0; e += x[off + i] * x[off + i]; }
      fft(re, im);
      var c = new Float32Array(12), j, mag;
      for (j = 0; j < bins.length; j++) { mag = Math.sqrt(re[bins[j][0]] * re[bins[j][0]] + im[bins[j][0]] * im[bins[j][0]]); c[bins[j][1]] += Math.sqrt(mag) * bins[j][2]; }
      var s = 0; for (j = 0; j < 12; j++) s += c[j];
      if (s > 0) for (j = 0; j < 12; j++) c[j] /= s;
      chroma[f] = c; energy[f] = Math.sqrt(e / N);
      var fl = 0;
      for (j = of0; j <= of1; j++) { mag = Math.log(1 + 40 * Math.sqrt(re[j] * re[j] + im[j] * im[j])); var dd = mag - prev[j]; if (dd > 0) fl += dd; prev[j] = mag; }
      onset[f] = fl;
    }
    return { chroma: chroma, energy: energy, onset: onset, fps: sr / hop, hop: hop / sr, duration: x.length / sr };
  }

  /* ---------- tempo ---------- */

  function tempo(onset, fps) {
    var n = onset.length, i, m = 0;
    if (n < fps * 6) return { bpm: 0, confidence: 0 };
    for (i = 0; i < n; i++) m += onset[i]; m /= n;
    var o = new Float32Array(n); for (i = 0; i < n; i++) o[i] = Math.max(0, onset[i] - m);
    var best = 0, bestBpm = 0, sum = 0, cnt = 0, bpm;
    for (bpm = 60; bpm <= 180; bpm += 0.5) {
      var lag = fps * 60 / bpm, l0 = Math.floor(lag), fr = lag - l0, s = 0;
      for (i = 0; i + l0 + 1 < n; i++) s += o[i] * (o[i + l0] * (1 - fr) + o[i + l0 + 1] * fr);
      // the second multiple (a bar of two beats) supports the same tempo; it also settles the octave
      var l2 = Math.floor(lag * 2), s2 = 0;
      for (i = 0; i + l2 < n; i++) s2 += o[i] * o[i + l2];
      s = (s + 0.5 * s2) / (n - l0);
      var prior = Math.exp(-0.5 * Math.pow(Math.log(bpm / 105) / Math.LN2 / 0.9, 2)); // people play around 105
      s *= prior;
      sum += s; cnt++;
      if (s > best) { best = s; bestBpm = bpm; }
    }
    var mean = cnt ? sum / cnt : 0;
    return { bpm: bestBpm, confidence: mean > 0 ? Math.min(1, Math.max(0, (best / mean - 1) / 3)) : 0 };
  }

  /* ---------- key and chords ---------- */

  function rotate(arr, k) { var o = new Array(12); for (var i = 0; i < 12; i++) o[(i + k) % 12] = arr[i]; return o; }
  function corr(a, b) {
    var ma = 0, mb = 0, i; for (i = 0; i < 12; i++) { ma += a[i]; mb += b[i]; } ma /= 12; mb /= 12;
    var n = 0, da = 0, db = 0; for (i = 0; i < 12; i++) { n += (a[i] - ma) * (b[i] - mb); da += (a[i] - ma) * (a[i] - ma); db += (b[i] - mb) * (b[i] - mb); }
    return da > 0 && db > 0 ? n / Math.sqrt(da * db) : 0;
  }

  /** Krumhansl-Schmuckler: {tonic 0-11, minor bool, score}. */
  function estimateKey(chroma, energy) {
    var tot = new Array(12).fill(0), mx = 0, f, i;
    for (f = 0; f < energy.length; f++) if (energy[f] > mx) mx = energy[f];
    for (f = 0; f < chroma.length; f++) { if (energy[f] < mx * 0.05) continue; for (i = 0; i < 12; i++) tot[i] += chroma[f][i]; }
    var best = { tonic: 0, minor: false, score: -2 };
    for (var t = 0; t < 12; t++) {
      var a = corr(tot, rotate(MAJ_PROFILE, t)), b = corr(tot, rotate(MIN_PROFILE, t));
      if (a > best.score) best = { tonic: t, minor: false, score: a };
      if (b > best.score) best = { tonic: t, minor: true, score: b };
    }
    return best;
  }

  function useFlats(key) { var major = key.minor ? (key.tonic + 3) % 12 : key.tonic; return !!FLAT_KEYS[major]; }
  function noteName(pc, flats) { return (flats ? FLAT : SHARP)[((pc % 12) + 12) % 12]; }
  function keyName(key) { return noteName(key.tonic, useFlats(key)) + (key.minor ? 'm' : ''); }
  function chordName(state, flats) { return noteName(state.root, flats) + TYPES[state.type].id; }

  /** Which chords belong to the key (triads and their sevenths), as a set of "root:type". */
  function diatonic(key) {
    var maj = key.minor ? (key.tonic + 3) % 12 : key.tonic, set = {};
    var deg = [[0, 0, 3], [2, 1, 4], [4, 1, 4], [5, 0, 3], [7, 0, 2], [9, 1, 4]]; // semitones, triad type, seventh type
    deg.forEach(function (d) { set[((maj + d[0]) % 12) + ':' + d[1]] = 1; set[((maj + d[0]) % 12) + ':' + d[2]] = 1; });
    if (key.minor) { set[((key.tonic + 7) % 12) + ':0'] = 1; set[((key.tonic + 7) % 12) + ':2'] = 1; }
    return set;
  }

  function templates(vocab) {
    var list = [];
    for (var r = 0; r < 12; r++) {
      for (var t = 0; t < TYPES.length; t++) {
        if (TYPES[t].seventh && vocab !== 'sevenths') continue;
        var v = new Float32Array(12), norm = 0, i;
        TYPES[t].iv.forEach(function (iv, k) { v[(r + iv) % 12] += k === 3 ? 0.7 : 1; });
        for (i = 0; i < 12; i++) norm += v[i] * v[i];
        norm = Math.sqrt(norm); for (i = 0; i < 12; i++) v[i] /= norm;
        list.push({ root: r, type: t, vec: v });
      }
    }
    return list;
  }

  /** The chord timeline: [{t0, t1, chord, root, type}] and the key, using a Viterbi pass so chords hold steady. */
  function chords(an, opt) {
    opt = opt || {};
    var vocab = opt.vocab === 'sevenths' ? 'sevenths' : 'simple';
    var step = Math.max(1, Math.round((opt.step || 0.19) / an.hop)), nf = Math.floor(an.chroma.length / step);
    var key = opt.key || estimateKey(an.chroma, an.energy), flats = useFlats(key), dia = diatonic(key);
    var T = templates(vocab), S = T.length, f, s, i, j;
    var mx = 0; for (f = 0; f < an.energy.length; f++) if (an.energy[f] > mx) mx = an.energy[f];
    var X = [], quiet = [];
    for (f = 0; f < nf; f++) {
      var c = new Float32Array(12), e = 0;
      for (i = 0; i < step; i++) { var fr = an.chroma[f * step + i]; for (j = 0; j < 12; j++) c[j] += fr[j]; e += an.energy[f * step + i]; }
      var norm = 0; for (j = 0; j < 12; j++) norm += c[j] * c[j]; norm = Math.sqrt(norm);
      if (norm > 0) for (j = 0; j < 12; j++) c[j] /= norm;
      X.push(c); quiet.push(e / step < mx * 0.04);
    }
    var beta = opt.beta || 9, change = opt.change || 2.6, keyBonus = opt.keyBonus == null ? 0.035 : opt.keyBonus, sevPenalty = opt.sevenths ? 0 : 0.03;
    var score = new Array(nf), back = new Array(nf), prevS = new Float32Array(S + 1), curS = new Float32Array(S + 1);
    var emit = function (f, k) { // k === S is "no chord"
      if (k === S) return quiet[f] ? 0.5 * beta : -Infinity;
      if (quiet[f]) return -Infinity;
      var v = T[k].vec, d = 0; for (var q = 0; q < 12; q++) d += v[q] * X[f][q];
      var bonus = dia[T[k].root + ':' + T[k].type] ? keyBonus : 0;
      return beta * (d + bonus - (TYPES[T[k].type].seventh ? sevPenalty : 0));
    };
    for (f = 0; f < nf; f++) {
      var bk = new Int16Array(S + 1);
      if (f === 0) { for (s = 0; s <= S; s++) curS[s] = emit(0, s); }
      else {
        var bi = 0, bv = -Infinity;
        for (s = 0; s <= S; s++) if (prevS[s] > bv) { bv = prevS[s]; bi = s; }
        for (s = 0; s <= S; s++) {
          var stay = prevS[s], go = bv - change;
          if (stay >= go) { curS[s] = stay + emit(f, s); bk[s] = s; } else { curS[s] = go + emit(f, s); bk[s] = bi; }
        }
      }
      back[f] = bk;
      for (s = 0; s <= S; s++) prevS[s] = curS[s];
    }
    var path = new Int16Array(nf), bs = 0, bv2 = -Infinity;
    for (s = 0; s <= S; s++) if (prevS[s] > bv2) { bv2 = prevS[s]; bs = s; }
    for (f = nf - 1; f >= 0; f--) { path[f] = bs; bs = back[f][bs]; }
    var segs = [], hopS = step * an.hop;
    for (f = 0; f < nf; f++) {
      if (path[f] === S) continue;
      var st = T[path[f]], last = segs[segs.length - 1];
      if (last && last.root === st.root && last.type === st.type && Math.abs(last.t1 - f * hopS) < 1e-6) last.t1 = (f + 1) * hopS;
      else segs.push({ t0: f * hopS, t1: (f + 1) * hopS, root: st.root, type: st.type });
    }
    // a blip shorter than the shortest sensible chord joins the chord before it
    var minDur = opt.minDur || 0.45, out = [];
    segs.forEach(function (g) {
      var p = out[out.length - 1];
      if (p && g.t1 - g.t0 < minDur && g.t0 - p.t1 < 0.01) { p.t1 = g.t1; return; }
      if (p && p.root === g.root && p.type === g.type && g.t0 - p.t1 < 0.01) { p.t1 = g.t1; return; }
      out.push(g);
    });
    out.forEach(function (g) { g.chord = chordName(g, flats); });
    return { segs: out, key: key, keyName: keyName(key), flats: flats };
  }

  function analyze(samples, sr, opt) {
    opt = opt || {};
    var target = 11025, x = resample(samples, sr, target), an = chromagram(x, target);
    var tp = tempo(an.onset, an.fps), ch = chords(an, opt);
    return { chords: ch.segs, key: ch.key, keyName: ch.keyName, flats: ch.flats, bpm: Math.round(tp.bpm), tempoConfidence: tp.confidence, duration: an.duration, hop: an.hop };
  }


  /* ---------- aligning what a speech service heard to the lyrics you gave ---------- */

  function norm(w) { return String(w).toLowerCase().replace(/[\u2019']/g, '').replace(/[^a-z0-9\u00c0-\u024f]+/g, ''); }

  function sim(a, b) { // 1 for the same word, down to 0
    if (a === b) return 1;
    if (!a || !b) return 0;
    var m = a.length, n = b.length, d = [], i, j;
    for (i = 0; i <= m; i++) { d[i] = [i]; }
    for (j = 1; j <= n; j++) d[0][j] = j;
    for (i = 1; i <= m; i++) for (j = 1; j <= n; j++) d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
    return 1 - d[m][n] / Math.max(m, n);
  }

  /** lines: parseLyrics output. heard: [{word, start, end}]. Returns {lines: [{t0, words: [{i, t}]}] (one per sung line), matched: 0-1}. */
  function alignWords(lines, heard) {
    var toks = [], per = [], li = -1;
    lines.forEach(function (l) {
      if (l.kind !== 'line') return;
      li++; per.push([]);
      var re = /\S+/g, m;
      while ((m = re.exec(l.text))) { var t = { line: li, i: m.index, n: norm(m[0]), t: null }; toks.push(t); per[li].push(t); }
    });
    var A = toks, B = (heard || []).filter(function (h) { return norm(h.word); }), n = A.length, m2 = B.length, i, j;
    if (!n || !m2) return { lines: per.map(function () { return { t0: null, words: [] }; }), matched: 0 };
    var GAP = -0.35, W = m2 + 1, sc = new Float32Array((n + 1) * W), tb = new Uint8Array((n + 1) * W);
    for (i = 1; i <= n; i++) { sc[i * W] = i * GAP; tb[i * W] = 1; }
    for (j = 1; j <= m2; j++) { sc[j] = j * GAP; tb[j] = 2; }
    var bn = B.map(function (h) { return norm(h.word); });
    for (i = 1; i <= n; i++) for (j = 1; j <= m2; j++) {
      var s = sim(A[i - 1].n, bn[j - 1]), diag = sc[(i - 1) * W + j - 1] + (s >= 0.6 ? s : -0.5), up = sc[(i - 1) * W + j] + GAP, left = sc[i * W + j - 1] + GAP, best = diag, dir = 0;
      if (up > best) { best = up; dir = 1; }
      if (left > best) { best = left; dir = 2; }
      sc[i * W + j] = best; tb[i * W + j] = dir;
    }
    var hit = 0; i = n; j = m2;
    while (i > 0 || j > 0) {
      var d = i === 0 ? 2 : j === 0 ? 1 : tb[i * W + j];
      if (d === 0) { if (sim(A[i - 1].n, bn[j - 1]) >= 0.6) { A[i - 1].t = B[j - 1].start; A[i - 1].end = B[j - 1].end; hit++; } i--; j--; }
      else if (d === 1) i--; else j--;
    }
    // words the service missed get a time between their neighbours, in proportion to their length
    var k = 0;
    while (k < n) {
      if (A[k].t != null) { k++; continue; }
      var a = k; while (k < n && A[k].t == null) k++;
      var tA = a > 0 ? A[a - 1].end != null ? A[a - 1].end : A[a - 1].t : null, tB = k < n ? A[k].t : null;
      if (tA == null && tB == null) continue;
      if (tA == null) tA = Math.max(0, tB - 0.35 * (k - a));
      if (tB == null) tB = tA + 0.35 * (k - a);
      for (var q = a; q < k; q++) A[q].t = tA + (tB - tA) * ((q - a + 0.5) / (k - a));
    }
    var prev = 0; A.forEach(function (t) { if (t.t < prev) t.t = prev; prev = t.t; });
    return { lines: per.map(function (ws) { return { t0: ws.length ? ws[0].t : null, words: ws.map(function (w) { return { i: w.i, t: w.t }; }) }; }), matched: hit / n };
  }

  /* ---------- lyrics + chords -> ChordPro ---------- */

  var LABEL = /^\s*[\[(]?\s*(verse|chorus|bridge|intro|outro|pre-?chorus|refrain|tag|interlude|instrumental|solo|hook|ending)\b[^\]):]*[\])]?\s*:?\s*$/i;

  /** Split pasted lyrics into lines. Section labels become comments; a line is "timed" when it holds words to sing. */
  function parseLyrics(text) {
    return String(text || '').replace(/\r/g, '').split('\n').map(function (l) {
      var raw = l.replace(/\[[^\]]*\]/g, function (m) { return LABEL.test(l) ? m : ''; });
      var t = l.trim();
      if (!t) return { kind: 'blank', text: '' };
      if (LABEL.test(t)) return { kind: 'label', text: t.replace(/^[\[(]\s*|\s*[\])]$|:\s*$/g, '').trim() };
      if (/^\{.*\}$/.test(t)) return { kind: 'label', text: '' };
      return { kind: 'line', text: l.replace(/\[[^\]]*\]/g, '').replace(/\s+$/, '').replace(/^\s+/, '') };
    });
  }

  function wordStarts(s) { var a = [0], i; for (i = 1; i < s.length; i++) if (s[i] !== ' ' && s[i - 1] === ' ') a.push(i); return a; }

  function chordAt(segs, t) {
    for (var i = 0; i < segs.length; i++) if (t >= segs[i].t0 && t < segs[i].t1) return segs[i];
    return null;
  }

  /** Put chords into one line sung from t0 to t1. */
  function lineWithChords(text, t0, t1, segs, words) {
    var ev = [], first = chordAt(segs, t0 + 0.25) || chordAt(segs, t0);
    if (first) ev.push({ t: t0, chord: first.chord });
    segs.forEach(function (g) { if (g.t0 > t0 + 0.25 && g.t0 < t1 - 0.15 && (!ev.length || ev[ev.length - 1].chord !== g.chord)) ev.push({ t: g.t0, chord: g.chord }); });
    if (!ev.length) return text;
    var starts = wordStarts(text), used = {}, ins = [], span = Math.max(0.1, t1 - t0), minI = 0;
    ev.forEach(function (e, k) {
      var idx = 0;
      if (k > 0 && words && words.length) { // exact: the word being sung when the chord changes
        for (var w = 0; w < words.length; w++) if (words[w].t <= e.t + 0.12) idx = words[w].i;
        if (idx < minI) idx = minI;
      } else if (k > 0) {
        var want = ((e.t - t0) / span) * text.length, bd = Infinity;
        starts.forEach(function (s) { if (s >= minI && Math.abs(s - want) < bd) { bd = Math.abs(s - want); idx = s; } });
        if (idx < minI) idx = minI;
      }
      if (k > 0 && used[idx] !== undefined && starts.length) { // no free word: the next free start, else stack on the same spot
        var nx = starts.filter(function (s) { return s > idx; })[0];
        if (nx !== undefined) idx = nx;
      }
      used[idx] = 1; ins.push({ idx: idx, chord: e.chord });
      minI = idx;
    });
    var out = '', last = 0;
    ins.forEach(function (n) { out += text.slice(last, n.idx) + '[' + n.chord + ']'; last = n.idx; });
    return out + text.slice(last);
  }

  function chordRow(list) { return list.map(function (c) { return '[' + c + ']'; }).join(' '); }

  /** The whole sheet. o: {title, artist, key, bpm, lines (parseLyrics), times (one per kind==='line', in seconds), segs, duration}. */
  function buildChordPro(o) {
    var out = [], lines = o.lines || [], segs = o.segs || [];
    if (o.title) out.push('{title: ' + o.title + '}');
    if (o.artist) out.push('{artist: ' + o.artist + '}');
    if (o.key) out.push('{key: ' + o.key + '}');
    if (o.bpm) out.push('{tempo: ' + o.bpm + '}');
    out.push('{time: 4/4}', '');
    var timed = lines.filter(function (l) { return l.kind === 'line'; });
    var times = (o.times || []).slice(), words = null;
    if (o.align) { times = o.align.map(function (a) { return a.t0; }); words = o.align.map(function (a) { return a.words; }); }
    if (!timed.length || times.length < timed.length) { // nothing to align: the chords on their own, four to a row
      var names = []; segs.forEach(function (g) { names.push(g.chord); });
      if (!timed.length) {
        for (var i = 0; i < names.length; i += 4) out.push(chordRow(names.slice(i, i + 4)));
        return out.join('\n').replace(/\n+$/, '') + '\n';
      }
    }
    var firstT = times.length ? times[0] : 0, lastEnd;
    var pre = segs.filter(function (g) { return g.t1 <= firstT - 0.3 && g.chord; }).map(function (g) { return g.chord; });
    if (pre.length) out.push('{comment: Intro}', chordRow(pre), '');
    var k = 0, avg = 3;
    if (times.length > 1) avg = Math.max(1.5, (times[times.length - 1] - times[0]) / (times.length - 1));
    lines.forEach(function (l) {
      if (l.kind === 'blank') { if (out[out.length - 1] !== '') out.push(''); return; }
      if (l.kind === 'label') { if (l.text) out.push('{comment: ' + l.text + '}'); return; }
      var t0 = times[k], t1 = k + 1 < times.length ? times[k + 1] : Math.min(o.duration || t0 + avg, t0 + avg * 1.2);
      if (t0 == null) { out.push(l.text); k++; return; }
      lastEnd = t1;
      out.push(lineWithChords(l.text, t0, Math.max(t1, t0 + 0.5), segs, words ? words[k] : null));
      k++;
    });
    var post = segs.filter(function (g) { return lastEnd != null && g.t0 >= lastEnd + 0.3; }).map(function (g) { return g.chord; });
    var dedup = post.filter(function (c, i) { return i === 0 || c !== post[i - 1]; });
    if (dedup.length >= 2) out.push('', '{comment: Outro}', chordRow(dedup.slice(0, 12)));
    return out.join('\n').replace(/\n{3,}/g, '\n\n').replace(/\n+$/, '') + '\n';
  }

  /** Spread the lines evenly between two times: a rough start when nobody tapped. */
  function spread(count, t0, t1) { var a = [], d = count > 0 ? (t1 - t0) / count : 0; for (var i = 0; i < count; i++) a.push(t0 + i * d); return a; }

  var api = { alignWords: alignWords, resample: resample, fft: fft, chromagram: chromagram, tempo: tempo, estimateKey: estimateKey, chords: chords, analyze: analyze, parseLyrics: parseLyrics, buildChordPro: buildChordPro, lineWithChords: lineWithChords, spread: spread, keyName: keyName, noteName: noteName };
  if (typeof module !== 'undefined' && module.exports) module.exports = api; else root.SPTranscribe = api;
}(typeof self !== 'undefined' ? self : this));
