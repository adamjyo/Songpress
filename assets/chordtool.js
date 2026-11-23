/* SongPress ChordPro tool. Runs only in this browser: it reads and writes no database and sends nothing anywhere. */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var ed = $('ct-text'), pv = $('ct-preview'), diag = $('ct-diagrams');
  var SHARP = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
  var FLAT = ['C', 'Db', 'D', 'Eb', 'E', 'F', 'Gb', 'G', 'Ab', 'A', 'Bb', 'B'];
  var IDX = { C: 0, 'C#': 1, Db: 1, D: 2, 'D#': 3, Eb: 3, E: 4, F: 5, 'F#': 6, Gb: 6, G: 7, 'G#': 8, Ab: 8, A: 9, 'A#': 10, Bb: 10, B: 11 };
  var useFlats = false;
  var TR = { n: 0, fl: false }; /* sheet view: semitones to transpose by, and whether to spell with flats */
  (function () { var q = /[?&]tr=(-?\d+)/.exec(location.search); if (q) TR.n = parseInt(q[1], 10) || 0; TR.fl = /[?&]fl=1/.test(location.search); }());

  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  /* chords: root, quality, optional bass */
  function parseChord(c) {
    var m = /^([A-G][#b]?)([^\/]*)(?:\/([A-G][#b]?))?$/.exec(c.trim());
    return m ? { root: m[1], q: m[2], bass: m[3] || '' } : null;
  }
  function shift(note, n) {
    var i = (IDX[note] + n) % 12; if (i < 0) i += 12;
    return (useFlats ? FLAT : SHARP)[i];
  }
  function transChord(c, n) {
    var p = parseChord(c);
    if (!p) return c;
    return shift(p.root, n) + p.q + (p.bass ? '/' + shift(p.bass, n) : '');
  }
  function transText(t, n) {
    return t.replace(/\[([^\]]+)\]/g, function (a, c) { return '[' + transChord(c, n) + ']'; })
      .replace(/(\{\s*(?:key|k)\s*:\s*)([A-G][#b]?)(m?)/gi, function (a, h, r, mi) { return h + shift(r, n) + mi; });
  }

  /* render */
  function renderLine(line) {
    var segs = [], re = /\[([^\]]*)\]/g, last = 0, m, chord = '';
    while ((m = re.exec(line))) {
      segs.push([chord, line.slice(last, m.index)]);
      chord = m[1]; last = re.lastIndex;
    }
    segs.push([chord, line.slice(last)]);
    if (segs.length === 2 && segs[0][0] === '' && segs[0][1] === '' && segs[1][0] === '') return '<div class="cl"><span class="cb"><i></i><b>' + esc(segs[1][1]) + '</b></span></div>';
    return '<div class="cl">' + segs.filter(function (s) { return s[0] !== '' || s[1] !== ''; }).map(function (s) {
      return '<span class="cb"><i>' + (s[0] ? esc(s[0]) : '&nbsp;') + '</i><b>' + (s[1] === '' ? '&nbsp;' : esc(s[1])) + '</b></span>';
    }).join('') + '</div>';
  }
  var EXTRA = /^start_of_(intro|instrumental|outro|tag|prechorus|pre_chorus|ending|interlude|refrain|part)$/;
  var SEC = { start_of_chorus: 'Chorus', soc: 'Chorus', start_of_verse: 'Verse', sov: 'Verse', start_of_bridge: 'Bridge', sob: 'Bridge' };
  function render(text) {
    var out = [], tab = false, open = false;
    text.split(/\r\n|\r|\n/).forEach(function (line) {
      if (tab) { if (/^\s*\{\s*(end_of_tab|eot)\s*\}/i.test(line)) { tab = false; out.push('</pre>'); } else out.push(esc(line) + '\n'); return; }
      if (/^\s*#/.test(line)) return;
      var d = /^\s*\{\s*([a-z_]+)\s*(?::\s*(.*?))?\s*\}\s*$/i.exec(line);
      if (d) {
        var k = d[1].toLowerCase(), a = d[2] || '';
        if (k === 'title' || k === 't') out.push('<h1>' + esc(a) + '</h1>');
        else if (k === 'subtitle' || k === 'st' || k === 'artist') out.push('<p class="sub">' + esc(a) + '</p>');
        else if (k === 'key' || k === 'capo' || k === 'tempo' || k === 'time') out.push('<span class="meta">' + esc(k[0].toUpperCase() + k.slice(1)) + ': ' + esc(a) + '</span> ');
        else if (k === 'comment' || k === 'c' || k === 'comment_italic' || k === 'ci') out.push('<p class="comment">' + esc(a) + '</p>');
        else if (SEC[k] || EXTRA.test(k)) { if (!SEC[k]) SEC[k] = k.replace(/^start_of_/, '').replace(/_/g, ' ').replace(/^./, function (c) { return c.toUpperCase(); }); if (open) out.push('</section>'); open = true; out.push('<section class="sec ' + SEC[k].toLowerCase() + '"><h4>' + esc(a || SEC[k]) + '</h4>'); }
        else if (/^(end_of_|eo)/.test(k) && k !== 'end_of_tab' && k !== 'eot') { if (open) { out.push('</section>'); open = false; } }
        else if (k === 'start_of_tab' || k === 'sot') { tab = true; out.push('<pre class="tab">'); }
        return;
      }
      if (line.trim() === '') { out.push('<div class="gap"></div>'); return; }
      out.push(renderLine(line));
    });
    if (tab) out.push('</pre>');
    if (open) out.push('</section>');
    return out.join('');
  }


  /* SongSelect-style printable sheet: title block, key | tempo | time, two columns, numbered sections, chords bold over the words, CCLI line at the foot */
  function buildSheet(text) {
    var meta = { title: '', by: [], key: '', tempo: '', time: '', ccli: '', iswc: '', copy: '' }, secs = [], cur = null, counts = {}, loose = [];
    function open(label) { cur = { label: label, lines: [] }; secs.push(cur); }
    function add(html) { (cur || (open(''), cur)).lines.push(html); }
    text.split(/\r\n|\r|\n/).forEach(function (line) {
      if (/^\s*#/.test(line)) return;
      var d = /^\s*\{\s*([a-z_]+)\s*(?::\s*(.*?))?\s*\}\s*$/i.exec(line);
      if (d) {
        var k = d[1].toLowerCase(), a = (d[2] || '').trim();
        if (k === 'title' || k === 't') meta.title = a;
        else if (/^(subtitle|st|artist|composer|lyricist|author|writer|writers|words|music)$/.test(k)) { if (a) meta.by.push(a); }
        else if (k === 'key') meta.key = a;
        else if (k === 'tempo') meta.tempo = a;
        else if (k === 'time') meta.time = a;
        else if (k === 'ccli' || k === 'ccli_song') meta.ccli = a.replace(/^#\s*/, '');
        else if (k === 'iswc') meta.iswc = a;
        else if (k === 'copyright') meta.copy = a;
        else if (/^(comment|c|comment_italic|ci)$/.test(k)) add('<p class="sh-note">' + esc(a) + '</p>');
        else if (/^start_of_/.test(k) && k !== 'start_of_tab') {
          var kind = k.replace(/^start_of_/, '').replace(/_/g, ' ');
          if (kind === 'chorus' || kind === 'soc') kind = 'chorus';
          var label = a;
          if (!label) { counts[kind] = (counts[kind] || 0) + 1; label = kind.replace(/^./, function (c) { return c.toUpperCase(); }) + (/^(verse|chorus|bridge|prechorus|pre chorus|tag|refrain|part)$/.test(kind) ? ' ' + counts[kind] : ''); }
          open(label);
        } else if (/^(sov|soc|sob)$/.test(k)) { var kk = { sov: 'verse', soc: 'chorus', sob: 'bridge' }[k]; counts[kk] = (counts[kk] || 0) + 1; open(a || kk.replace(/^./, function (c) { return c.toUpperCase(); }) + ' ' + counts[kk]); }
        else if (/^(end_of_|eo)/.test(k)) cur = null;
        return;
      }
      if (line.trim() === '') { if (cur && !cur.lines.length) return; if (!cur) return; add('<div class="gap"></div>'); return; }
      add(renderLine(line));
    });
    var C = window.SP_SHEET || {};
    if (!meta.title && C.title) meta.title = C.title;
    if (!meta.by.length && C.by) meta.by = [C.by];
    if (!meta.key && C.key) { var km = /^([A-G][#b]?)(.*)$/.exec(C.key); meta.key = TR.n && km ? shift(km[1], TR.n) + km[2] : C.key; }
    if (!meta.tempo && C.tempo) meta.tempo = C.tempo;
    if (!meta.time && C.time) meta.time = C.time;
    var firstC = 0, by = meta.by.join(' | '), mt = [];
    if (meta.key) mt.push('Key - ' + meta.key);
    if (meta.tempo) mt.push('Tempo - ' + meta.tempo);
    if (meta.time) mt.push('Time - ' + meta.time);
    var num = (meta.ccli || C.ccli) ? 'CCLI Song # ' + (meta.ccli || C.ccli) : ((meta.iswc || C.iswc) ? 'ISWC ' + (meta.iswc || C.iswc) : '');
    var foot = [];
    if (num) foot.push(esc(num));
    if (C.publisher) C.publisher.split(/\r?\n/).forEach(function (l) { if (l.trim()) foot.push(esc(l.trim())); });
    if (meta.copy) foot.push(esc(meta.copy));
    var h = '<header class="sh-head">' + (C.logo ? '<img class="sh-logo" src="' + esc(C.logo) + '" alt="">' : '') + '<h1>' + esc(meta.title || 'Untitled') + '</h1>' + (by ? '<p class="sh-by">' + esc(by) + '</p>' : '') + (mt.length ? '<p class="sh-mt">' + esc(mt.join(' | ')) + '</p>' : '') + '</header><div class="sh-cols">';
    secs.forEach(function (sc, i) {
      h += '<section class="sh-sec"' + (!firstC && /^chorus/i.test(sc.label) && (firstC = i + 1) ? ' data-chorus="1"' : '') + '>' + (sc.label ? '<h4>' + esc(sc.label.toUpperCase()) + '</h4>' : '') + sc.lines.join('') + '</section>';
    });
    h += '</div><footer class="sh-foot">' + foot.join('<br>') + '</footer>';
    return h;
  }

  /* Two columns of whole sections, laid out by hand so no browser can split a section and strand a line or two in column two. Page one: the second column starts after the first chorus when everything fits on the page, otherwise the most even split. A longer song fills column one, then column two, then the next page. */
  function layoutSheet(sh) {
    var cols = sh.querySelector('.sh-cols');
    if (!cols) return;
    var secs = [].slice.call(cols.querySelectorAll('.sh-sec'));
    if (!secs.length) return;
    var old = sh.style.cssText, hidden = sh.offsetHeight === 0;
    if (hidden) sh.style.cssText = 'display:block;position:absolute;left:-9999px;top:0;width:816px;padding:48px 58px 72px;box-sizing:border-box;transform:none';
    var wrap = document.createElement('div'); wrap.className = 'sh-pages';
    var A = document.createElement('div'); A.className = 'sh-col';
    var G = document.createElement('div'); G.className = 'sh-cols'; G.appendChild(A);
    var B0 = document.createElement('div'); B0.className = 'sh-col'; G.appendChild(B0);
    cols.parentNode.replaceChild(wrap, cols); wrap.appendChild(G);
    secs.forEach(function (x) { A.appendChild(x); });
    var hs = secs.map(function (x) { return x.offsetHeight + 21; });
    var head = sh.querySelector('.sh-head'), avail1 = 900 - (head ? head.offsetHeight + 20 : 0), availN = 900;
    var ch = -1; secs.forEach(function (x, i) { if (ch < 0 && x.getAttribute('data-chorus')) ch = i; });
    var total = hs.reduce(function (a, b) { return a + b; }, 0);
    function range(i, j) { var t = 0; for (var k = i; k < j; k++) t += hs[k]; return t; }
    var plan = []; /* pages: [[colA indexes], [colB indexes]] */
    var fitsOne = false, pick = -1;
    if (secs.length > 1) {
      if (ch > -1 && ch < secs.length - 1 && range(0, ch + 1) <= avail1 && range(ch + 1, secs.length) <= avail1) pick = ch + 1;
      else { var bm = 1e9; for (var k = 1; k < secs.length; k++) { var m = Math.max(range(0, k), range(k, secs.length)); if (m <= avail1 && m < bm) { bm = m; pick = k; } } }
    }
    if (pick > -1) { plan.push([range0(0, pick), range0(pick, secs.length)]); }
    else {
      var i = 0, first = true;
      while (i < secs.length) {
        var cap = first ? avail1 : availN, pg = [[], []], c = 0, used = 0;
        while (i < secs.length) {
          if (used + hs[i] > cap && pg[c].length) { if (c === 1) break; c = 1; used = 0; continue; }
          pg[c].push(i); used += hs[i]; i++;
        }
        plan.push(pg); first = false;
      }
    }
    function range0(i, j) { var r = []; for (var k = i; k < j; k++) r.push(k); return r; }
    wrap.innerHTML = '';
    plan.forEach(function (pg, pi) {
      var g = document.createElement('div'); g.className = 'sh-cols' + (pi ? ' sh-next' : '');
      pg.forEach(function (ixs) { var c = document.createElement('div'); c.className = 'sh-col'; ixs.forEach(function (k) { c.appendChild(secs[k]); }); g.appendChild(c); });
      wrap.appendChild(g);
    });
    if (hidden) sh.style.cssText = old;
  }
  function printSheet() {
    var box = document.getElementById('ct-sheet');
    if (!box) { box = document.createElement('div'); box.id = 'ct-sheet'; document.body.appendChild(box); }
    box.innerHTML = buildSheet(ed.value);
    layoutSheet(box);
    document.body.classList.add('ct-printing');
    var done = function () { document.body.classList.remove('ct-printing'); window.removeEventListener('afterprint', done); };
    window.addEventListener('afterprint', done);
    window.print();
  }

  /* chords over lyrics to ChordPro */
  function isChordLine(l) {
    var t = l.trim().split(/\s+/).filter(Boolean);
    return t.length > 0 && t.every(function (x) { return /^[A-G][#b]?(m|maj|min|dim|aug|sus|add|M)?\d*(sus\d|add\d|\+|°|ø)?(\/[A-G][#b]?)?$/.test(x); });
  }
  function convert(text) {
    var lines = text.split(/\r\n|\r|\n/), out = [];
    for (var i = 0; i < lines.length; i++) {
      if (isChordLine(lines[i]) && i + 1 < lines.length && !isChordLine(lines[i + 1]) && lines[i + 1].trim() !== '') {
        var ch = lines[i], ly = lines[i + 1], pos = [], re = /\S+/g, m;
        while ((m = re.exec(ch))) pos.push([m.index, m[0]]);
        var res = '', last = 0;
        pos.forEach(function (p) {
          var at = Math.min(p[0], ly.length);
          res += ly.slice(last, at) + '[' + p[1] + ']'; last = at;
        });
        out.push(res + ly.slice(last)); i++;
      } else if (isChordLine(lines[i])) {
        out.push(lines[i].trim().split(/\s+/).map(function (c) { return '[' + c + ']'; }).join(' '));
      } else out.push(lines[i]);
    }
    return out.join('\n');
  }
  /* ChordPro to chords over lyrics (plain text) */
  function toPlain(text) {
    var out = [];
    text.split(/\r\n|\r|\n/).forEach(function (line) {
      if (/^\s*#/.test(line)) return;
      var d = /^\s*\{\s*([a-z_]+)\s*(?::\s*(.*?))?\s*\}\s*$/i.exec(line);
      if (d) { var k = d[1].toLowerCase(); if (k === 'title' || k === 't') out.push(d[2] || ''); else if (k === 'comment' || k === 'c') out.push('[' + (d[2] || '') + ']'); else if (SEC[k]) out.push('[' + (d[2] || SEC[k]) + ']'); return; }
      var re = /\[([^\]]*)\]/g, last = 0, m, chords = '', words = '';
      while ((m = re.exec(line))) {
        words += line.slice(last, m.index); last = re.lastIndex;
        while (chords.length < words.length) chords += ' ';
        chords += m[1] + ' ';
      }
      words += line.slice(last);
      if (chords.trim()) out.push(chords.replace(/\s+$/, ''));
      out.push(words);
    });
    return out.join('\n');
  }

  /* guitar chord diagrams: frets low E to high e, -1 muted, 0 open; base fret 1 */
  var G = { C: [-1, 3, 2, 0, 1, 0], D: [-1, -1, 0, 2, 3, 2], E: [0, 2, 2, 1, 0, 0], F: [1, 3, 3, 2, 1, 1], G: [3, 2, 0, 0, 0, 3], A: [-1, 0, 2, 2, 2, 0], B: [-1, 2, 4, 4, 4, 2],
    Am: [-1, 0, 2, 2, 1, 0], Bm: [-1, 2, 4, 4, 3, 2], Cm: [-1, 3, 5, 5, 4, 3], Dm: [-1, -1, 0, 2, 3, 1], Em: [0, 2, 2, 0, 0, 0], Fm: [1, 3, 3, 1, 1, 1], Gm: [3, 5, 5, 3, 3, 3],
    A7: [-1, 0, 2, 0, 2, 0], B7: [-1, 2, 1, 2, 0, 2], C7: [-1, 3, 2, 3, 1, 0], D7: [-1, -1, 0, 2, 1, 2], E7: [0, 2, 0, 1, 0, 0], G7: [3, 2, 0, 0, 0, 1],
    Cmaj7: [-1, 3, 2, 0, 0, 0], Fmaj7: [-1, -1, 3, 2, 1, 0], Am7: [-1, 0, 2, 0, 1, 0], Dm7: [-1, -1, 0, 2, 1, 1], Em7: [0, 2, 0, 0, 0, 0],
    Asus4: [-1, 0, 2, 2, 3, 0], Dsus4: [-1, -1, 0, 2, 3, 3], Dsus2: [-1, -1, 0, 2, 3, 0], Asus2: [-1, 0, 2, 2, 0, 0], Gsus4: [3, 3, 0, 0, 1, 3], Cadd9: [-1, 3, 2, 0, 3, 0], Bb: [-1, 1, 3, 3, 3, 1] };
  var ENH = { 'C#': 'Db', 'D#': 'Eb', 'F#': 'Gb', 'G#': 'Ab', 'A#': 'Bb' };
  function diagram(name, f) {
    var s = '<svg viewBox="0 0 60 78" width="60" height="78"><text x="30" y="11" text-anchor="middle" class="dn">' + esc(name) + '</text>';
    for (var i = 0; i < 6; i++) s += '<line x1="' + (10 + i * 8) + '" y1="20" x2="' + (10 + i * 8) + '" y2="70" class="ds"/>';
    for (var j = 0; j <= 5; j++) s += '<line x1="10" y1="' + (20 + j * 10) + '" x2="50" y2="' + (20 + j * 10) + '" class="ds' + (j === 0 ? ' nut' : '') + '"/>';
    f.forEach(function (v, i) {
      var x = 10 + i * 8;
      if (v < 0) s += '<text x="' + x + '" y="17" text-anchor="middle" class="dx">x</text>';
      else if (v === 0) s += '<circle cx="' + x + '" cy="15" r="2.4" class="do"/>';
      else s += '<circle cx="' + x + '" cy="' + (15 + v * 10) + '" r="3.6" class="dd"/>';
    });
    return s + '</svg>';
  }
  function chordsIn(text) {
    var seen = {}, list = [], re = /\[([^\]]+)\]/g, m;
    while ((m = re.exec(text))) { if (!seen[m[1]]) { seen[m[1]] = 1; list.push(m[1]); } }
    return list;
  }
  function lookup(c) {
    var p = parseChord(c); if (!p) return null;
    var r = ENH[p.root] || p.root, q = p.q.replace('min', 'm').replace('maj7', 'maj7');
    return G[r + q] || null;
  }

  function update() {
    var t = ed.value;
    pv.innerHTML = render(t);
    var cs = chordsIn(t), h = '';
    cs.forEach(function (c) { var f = lookup(c); if (f) h += '<figure>' + diagram(c, f) + '</figure>'; });
    diag.innerHTML = $('ct-showdiag').checked ? h : '';
    if (document.body.getAttribute('data-embed') !== '1') { try { localStorage.setItem('songpress_chordtool', t); } catch (e) {} }
  }

  /* actions */
  function setText(t) { ed.value = t; update(); }
  function insert(s) {
    var a = ed.selectionStart, b = ed.selectionEnd;
    ed.value = ed.value.slice(0, a) + s + ed.value.slice(b);
    ed.selectionStart = ed.selectionEnd = a + s.length; ed.focus(); update();
  }
  function download(name, text, type) {
    var b = new Blob([text], { type: type || 'text/plain' }), a = document.createElement('a');
    a.href = URL.createObjectURL(b); a.download = name; document.body.appendChild(a); a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  }
  function titleOf() { var m = /\{\s*(?:title|t)\s*:\s*(.*?)\s*\}/i.exec(ed.value); return (m ? m[1] : 'song').replace(/[^\w\- ]+/g, '').trim() || 'song'; }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-ct]'); if (!b) return;
    var a = b.getAttribute('data-ct');
    if (a === 'up') setText(transText(ed.value, 1));
    else if (a === 'down') setText(transText(ed.value, -1));
    else if (a === 'convert') setText(convert(ed.value));
    else if (a === 'plain') { $('ct-plain').value = toPlain(ed.value); $('ct-plainbox').hidden = false; }
    else if (a === 'closeplain') $('ct-plainbox').hidden = true;
    else if (a === 'save') download(titleOf() + '.pro', ed.value);
    else if (a === 'savetxt') download(titleOf() + '.txt', toPlain(ed.value));
    else if (a === 'savehtml') download(titleOf() + '.html', '<!doctype html><meta charset="utf-8"><title>' + esc(titleOf()) + '</title><style>' + document.getElementById('ct-printcss').textContent + '</style><div class="chart">' + pv.innerHTML + '</div>', 'text/html');
    else if (a === 'print') printSheet();
    else if (a === 'clear') { if (!ed.value || window.confirm('Clear the chart?')) setText(''); }
    else if (a === 'sample') setText(SAMPLE);
    else if (a === 'chorus') insert('\n{start_of_chorus}\n\n{end_of_chorus}\n');
    else if (a === 'verse') insert('\n{start_of_verse}\n\n{end_of_verse}\n');
    else if (a === 'bridge') insert('\n{start_of_bridge}\n\n{end_of_bridge}\n');
    else if (a === 'chord') insert('[]');
    else if (a === 'copy') { var t = ed.value; if (navigator.clipboard) navigator.clipboard.writeText(t).catch(function () { ed.select(); }); else ed.select(); }
  });
  $('ct-open').addEventListener('change', function () {
    var f = this.files[0]; if (!f) return;
    var r = new FileReader(); r.onload = function () { setText(String(r.result)); }; r.readAsText(f); this.value = '';
  });
  $('ct-flats').addEventListener('change', function () { useFlats = this.checked; });
  $('ct-showdiag').addEventListener('change', update);
  $('ct-size').addEventListener('input', function () { pv.style.fontSize = this.value + 'px'; });
  $('ct-cols').addEventListener('change', function () { pv.style.columnCount = this.value; });
  ed.addEventListener('input', update);

  var SAMPLE = '{title: Harbor Lights}\n{artist: Example}\n{key: G}\n\n{start_of_verse}\n[G]Walking down to the [D]water at night\n[Em]Every window [C]glowing gold\n{end_of_verse}\n\n{start_of_chorus}\n[C]Harbor lights, [G]take me home\n[D]Harbor lights, [G]I am not alone\n{end_of_chorus}\n';
  var embedded = document.body.getAttribute('data-embed') === '1';
  var saved = '';
  if (!embedded) { try { saved = localStorage.getItem('songpress_chordtool') || ''; } catch (e) {} }
  ed.value = embedded ? '' : (saved || SAMPLE);
  update();
  if (embedded) {
    /* inside the song editor: take the text it sends, give it back only on Use in song */
    window.addEventListener('message', function (e) {
      if (e.origin !== location.origin || !e.data || e.data.type !== 'ct-load') return;
      ed.value = String(e.data.text || ''); update(); ed.focus();
    });
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-ct]'); if (!b) return;
      var a = b.getAttribute('data-ct'), p = window.parent;
      if (a === 'usesong') p.postMessage({ type: 'ct-save', text: ed.value }, location.origin);
      else if (a === 'cancelsong') p.postMessage({ type: 'ct-cancel' }, location.origin);
      else if (a === 'direct') p.postMessage({ type: 'ct-direct' }, location.origin);
    });
    window.parent.postMessage({ type: 'ct-ready' }, location.origin);
  }
  /* sheet view: the printable sheet shown on screen, fed by the work editor */
  if (document.body.getAttribute('data-sheetview') === '1') {
    var sh = document.getElementById('ct-sheet');
    if (!sh) { sh = document.createElement('div'); sh.id = 'ct-sheet'; document.body.appendChild(sh); }
    var fit = function () {
      var vw = document.documentElement.clientWidth, narrow = false; /* always the standard letter page, two columns */
      document.body.classList.toggle('ct-narrow', narrow);
      var sc = narrow ? 1 : Math.min(1, (vw - 16) / 816);
      sh.style.transform = narrow ? 'none' : 'scale(' + sc + ')';
      var hh = Math.ceil(sh.offsetHeight * sc);
      document.getElementById('ct-sheetbox').style.height = hh + 'px';
      window.parent.postMessage({ spSheetH: hh + 16 }, location.origin);
    };
    var paint = function (t) { ed.value = t; useFlats = TR.fl; sh.innerHTML = buildSheet(TR.n ? transText(t, TR.n) : t); layoutSheet(sh); fit(); };
    window.addEventListener('message', function (e) {
      if (e.origin !== location.origin || !e.data) return;
      if (typeof e.data.spTranspose === 'number') { TR.n = e.data.spTranspose; TR.fl = !!e.data.spFlats; paint(ed.value); return; }
      if (typeof e.data.spChart !== 'string') return;
      paint(e.data.spChart);
    });
    window.addEventListener('resize', fit);
    var pb = document.getElementById('ct-sheet-print');
    if (pb) pb.addEventListener('click', function () { printSheet(); });
    var auto = window.SP_SHEET && typeof window.SP_SHEET.chart === 'string';
    paint(auto ? window.SP_SHEET.chart : '');
    if (auto && /[?&]print=1/.test(location.search)) setTimeout(printSheet, 400);
    window.parent.postMessage({ spSheetReady: 1 }, location.origin);
  }
})();
