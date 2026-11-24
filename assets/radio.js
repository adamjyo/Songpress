/* SongPress Radio. One page holds every playlist, so the music keeps playing while you move between lists and the Now Playing page.
   Lists: All releases, Recent releases, Favorites (built in), then yours and the ones shared with you.
   Edits (rename, share, add, remove, reorder, delete) save through admin-ajax. */
(function () {
  'use strict';
  var C = window.SP_RADIO, root = document.getElementById('sp-rd');
  if (!C || !root) return;
  if (window.SPRD_DESTROY) { try { window.SPRD_DESTROY(); } catch (e) {} } // a Radio page opened in place of an earlier one: that one lets go first
  var alive = true;
  var I = C.i, T = C.tracks, ST = C.st, L = C.lists;
  var IOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

  /* ---------- small helpers ---------- */
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  var P = {
    radio: '<circle cx="12" cy="12" r="2"/><path d="M8 8a5.7 5.7 0 000 8M16 8a5.7 5.7 0 010 8M5 5a10 10 0 000 14M19 5a10 10 0 010 14"/>',
    heart: '<path d="M20.8 5.6a5.2 5.2 0 00-7.4 0L12 7l-1.4-1.4a5.2 5.2 0 00-7.4 7.4L12 21.8l8.8-8.8a5.2 5.2 0 000-7.4z"/>',
    star: '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
    plus: '<path d="M12 5v14M5 12h14"/>', minus: '<circle cx="12" cy="12" r="9"/><path d="M8 12h8"/>',
    play: '<path d="M7 4l13 8-13 8z"/>', pause: '<path d="M6 4h4v16H6zM14 4h4v16h-4z"/>',
    next: '<path d="M5 5l10 7-10 7zM17 5h2v14h-2z"/>', prev: '<path d="M19 5L9 12l10 7zM7 5H5v14h2z"/>',
    shuffle: '<path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/>',
    repeat: '<path d="M17 2l4 4-4 4M3 11V9a3 3 0 013-3h15M7 22l-4-4 4-4M21 13v2a3 3 0 01-3 3H3"/>',
    queue: '<path d="M4 6h12M4 12h12M4 18h8M18 15V5l3 1"/><circle cx="16" cy="18" r="2"/>',
    back: '<path d="M15 5l-7 7 7 7"/>', down: '<path d="M6 9l6 6 6-6"/>', more: '<circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/>',
    grip: '<path d="M4 8h16M4 12h16M4 16h16"/>', clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>', chev: '<path d="M9 5l7 7-7 7"/>',
    vol0: '<path d="M4 9v6h4l5 4V5L8 9z"/>', vol1: '<path d="M4 9v6h4l5 4V5L8 9zM16 8a5 5 0 010 8M18.5 5a9 9 0 010 14"/>'
  };
  function ico(name, size, fill) {
    var s = document.createElement('span'); s.className = 'ic';
    s.innerHTML = '<svg width="' + (size || 22) + '" height="' + (size || 22) + '" viewBox="0 0 24 24" fill="' + (fill || 'none') + '" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + P[name] + '</svg>';
    return s;
  }
  function btn(cls, label, iconName, size, fill) { var b = el('button', cls); b.type = 'button'; b.setAttribute('aria-label', label); if (iconName) b.appendChild(ico(iconName, size, fill)); return b; }
  function fmt(t) { t = Math.max(0, Math.floor(t || 0)); return Math.floor(t / 60) + ':' + ('0' + (t % 60)).slice(-2); }
  function hue(id) { return (id * 47) % 360; }
  function post(action, data, nonce) {
    var fd = new FormData(); fd.append('action', action); fd.append('n', nonce);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.success) throw new Error((j && j.data && j.data.msg) || I.err);
      return j.data;
    });
  }
  function radioPost(d) { return post('songpress_radio', d, C.n); }
  function logUse(kind, id, detail) { try { post('songpress_use', { kind: kind, song: id || 0, detail: detail || '' }, C.un).catch(function () {}); } catch (e) {} }
  function toast(t) { var e = el('div', 'sp-rx-toast', t); e.setAttribute('role', 'status'); document.body.appendChild(e); setTimeout(function () { e.classList.add('out'); setTimeout(function () { e.remove(); }, 400); }, 2200); }
  function byK(k) { for (var i = 0; i < L.length; i++) if (L[i].k === k) return L[i]; return null; }
  function st(id) { return ST[id] || (ST[id] = { avg: 0, n: 0, mine: 0, fav: 0 }); }
  function count(l) { return l.ids.length === 1 ? I.song1 : l.ids.length + ' ' + I.songs; }

  /* ---------- playback ---------- */
  var au = window.SPAU; if (!au) { au = window.SPAU = new Audio(); au.preload = 'none'; } // never touch preload on an element that is already playing: iOS can stall it // one audio element for the whole app, so a song carries on across pages
  var Q = { base: [], order: [], pos: -1, key: '', shuffle: false, repeat: 'off' };
  function curId() { return Q.pos >= 0 ? Q.order[Q.pos] : 0; }
  function build(first) {
    Q.order = Q.base.slice();
    if (Q.shuffle) {
      for (var i = Q.order.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)), t = Q.order[i]; Q.order[i] = Q.order[j]; Q.order[j] = t; }
      if (first) { Q.order.splice(Q.order.indexOf(first), 1); Q.order.unshift(first); }
      Q.pos = first ? 0 : Q.pos;
    } else if (first) Q.pos = Q.order.indexOf(first);
  }
  function startList(l, firstId, shuf) {
    Q.base = l.ids.filter(function (id) { return T[id] && T[id].s; }); Q.key = l.k; Q.shuffle = shuf;
    if (!Q.base.length) return;
    var f = firstId || (shuf ? Q.base[Math.floor(Math.random() * Q.base.length)] : Q.base[0]);
    build(f); if (!Q.shuffle) Q.pos = Q.order.indexOf(f);
    load(Q.pos);
  }
  function load(pos) {
    var id = Q.order[pos]; if (!T[id]) return;
    Q.pos = pos; claim(); au.src = T[id].s;
    var p = au.play(); if (p && p.catch) p.catch(function () { toast(I.tap); });
    logUse('play', id, 'radio');
    meta(); ui(true);
  }
  function step(dir, auto) {
    var n = Q.order.length; if (!n) return;
    if (dir < 0 && au.currentTime > 4) { au.currentTime = 0; return; }
    var np = Q.pos + dir;
    if (np >= n) { if (Q.repeat === 'all' || !auto) np = 0; else { window.SPUSERPAUSE = Date.now(); au.pause(); au.currentTime = 0; ui(); return; } }
    if (np < 0) np = n - 1;
    load(np);
  }
  function toggle() { if (Q.pos < 0) return; if (au.paused) { var p = au.play(); if (p && p.catch) p.catch(function () {}); } else { window.SPUSERPAUSE = Date.now(); au.pause(); } }
  function setShuffle(on) { Q.shuffle = on; var id = curId(); if (Q.pos >= 0) { if (on) build(id); else { Q.order = Q.base.slice(); Q.pos = Q.order.indexOf(id); } } ui(); }
  function cycleRepeat() { Q.repeat = Q.repeat === 'off' ? 'all' : (Q.repeat === 'all' ? 'one' : 'off'); ui(); }
  au.addEventListener('ended', function () { if (!alive || window.SPCTL !== 'radio') return; if (Q.repeat === 'one') { au.currentTime = 0; var p = au.play(); if (p && p.catch) p.catch(function () {}); } else step(1, true); });
  au.addEventListener('error', function () { if (alive && window.SPCTL === 'radio' && Q.pos >= 0) { toast(I.skip); setTimeout(function () { step(1, true); }, 700); } });
  ['play', 'pause'].forEach(function (n) { au.addEventListener(n, function () { if (!alive) return; if ('mediaSession' in navigator) navigator.mediaSession.playbackState = n === 'play' ? 'playing' : 'paused'; ui(); }); });
  au.addEventListener('timeupdate', function () { if (alive) tick(); }); au.addEventListener('loadedmetadata', function () { if (alive) tick(); });
  function meta() {
    if (!('mediaSession' in navigator) || !window.MediaMetadata) return;
    var t = T[curId()]; if (!t) return;
    navigator.mediaSession.metadata = new MediaMetadata({ title: t.t, artist: t.b, artwork: t.a ? [{ src: t.a }] : [] });
  }
  function bindMS() {
    if (!('mediaSession' in navigator)) return;
    var ms = navigator.mediaSession;
    [['play', toggle], ['pause', toggle], ['nexttrack', function () { step(1); }], ['previoustrack', function () { step(-1); }],
     ['seekto', function (d) { if (d && d.seekTime != null) au.currentTime = d.seekTime; }]].forEach(function (a) { try { ms.setActionHandler(a[0], a[1]); } catch (e) {} });
  }
  bindMS();
  function claim() { window.SPCTL = 'radio'; if (window.SPBAN) window.SPBAN.hide(); bindMS(); } // Radio is the one playing now: the Works bar steps aside

  /* ---------- views ---------- */
  var view = { name: 'index', k: '', edit: false };
  var main = el('div', 'sp-rd-main'), mini = el('div', 'sp-rd-mini'), np = el('div', 'sp-rd-np');
  mini.hidden = true; np.hidden = true;
  /* the mini player lives at the top and tucks itself away a few seconds after it appears or is touched; the little tab brings it back */
  var awayT = 0, pull = el('button', 'sp-rd-pull'); pull.type = 'button'; pull.hidden = true; pull.setAttribute('aria-label', I.open || 'Player');
  pull.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
  function away() { if (!alive || mini.hidden) return; mini.classList.add('away'); pull.hidden = false; }
  function peek() { mini.classList.remove('away'); pull.hidden = true; clearTimeout(awayT); awayT = setTimeout(away, 4000); }
  pull.addEventListener('click', peek); mini.addEventListener('pointerdown', function () { clearTimeout(awayT); awayT = setTimeout(away, 4000); }, true);
  var wasPlaying = null;
  root.appendChild(main); document.body.appendChild(mini); document.body.appendChild(pull); document.body.appendChild(np); // the mini player lives on the page, so it stays when you leave Radio
  window.SPRD_DESTROY = function () {
    alive = false; window.SPRD_SNAP = Q.pos >= 0 ? { key: Q.key, base: Q.base, order: Q.order, pos: Q.pos, shuffle: Q.shuffle, repeat: Q.repeat } : null;
    clearTimeout(awayT); try { mini.remove(); pull.remove(); np.remove(); } catch (e) {} document.documentElement.classList.remove('sp-np-open', 'sp-rd-has-mini');
  };
  window.SPRD_YIELD = function () { if (!alive) return; Q = { base: [], order: [], pos: -1, key: '', shuffle: false, repeat: 'off' }; hideNP(); ui(); }; // a track was started on the Works list

  function cover(ids, big) {
    var c = el('span', 'sp-rd-cover' + (big ? ' big' : '')); c.setAttribute('aria-hidden', 'true');
    for (var i = 0; i < 4; i++) {
      var id = ids[i % Math.max(1, ids.length)], t = ids.length ? T[id] : null, cell;
      if (ids.length && ids.length < 4 && i >= ids.length) { cell = el('i'); cell.style.background = 'hsl(' + hue(ids[0] + i) + ' 28% 26%)'; }
      else if (t && t.a) { cell = document.createElement('img'); cell.src = t.a; cell.alt = ''; cell.loading = 'lazy'; }
      else { cell = el('i'); cell.style.background = id ? 'hsl(' + hue(id) + ' 30% 28%)' : '#262830'; }
      c.appendChild(cell);
    }
    return c;
  }
  function art(id, cls) {
    var t = T[id], a = el('span', cls || 'sp-rd-art');
    if (t && t.a) { var im = document.createElement('img'); im.src = t.a; im.alt = ''; im.loading = 'lazy'; a.appendChild(im); }
    else { a.textContent = '\u266b'; a.style.background = 'hsl(' + hue(id) + ' 30% 28%)'; }
    return a;
  }

  var VK = 'sp_rd_view';
  function keep() { try { if (view.name === 'list' && !view.edit) localStorage.setItem(VK, view.k); else if (view.name === 'index') localStorage.removeItem(VK); } catch (e) {} }
  function go(v) { view = v; keep(); draw(); window.scrollTo(0, 0); }
  try { var kv = localStorage.getItem(VK); if (kv) view = { name: 'list', k: kv, edit: false }; } catch (e) {}
  document.addEventListener('click', function (e) { if (!alive) return; var a = e.target.closest && e.target.closest('.songpress-tabs a.on'); if (a) { try { localStorage.removeItem(VK); } catch (x) {} } });
  function draw() {
    main.innerHTML = '';
    if (view.name === 'list' && byK(view.k)) drawList(byK(view.k)); else { view = { name: 'index', k: '', edit: false }; drawIndex(); }
  }

  function lrow(l, subtitle, iconName) {
    var b = el('button', 'sp-rd-lrow'); b.type = 'button';
    if (iconName) { var i = el('span', 'sp-rd-lico'); i.appendChild(ico(iconName, 24, iconName === 'heart' ? 'currentColor' : 'none')); b.appendChild(i); } else b.appendChild(cover(l.ids));
    var tx = el('span', 'tx'); tx.appendChild(el('b', '', l.name)); tx.appendChild(el('small', '', subtitle)); b.appendChild(tx); b.appendChild(ico('chev', 18));
    b.addEventListener('click', function () { go({ name: 'list', k: l.k, edit: false }); });
    return b;
  }
  function drawIndex() {
    var h = el('div', 'sp-rd-head'); h.appendChild(el('h2', '', I.radio));
    var add = btn('sp-rd-circle', I.newpl, 'plus'); h.appendChild(add); main.appendChild(h);
    var nf = el('form', 'sp-rd-new'); nf.hidden = true;
    var inp = el('input'); inp.type = 'text'; inp.maxLength = C.max; inp.placeholder = I.name; inp.setAttribute('aria-label', I.newpl);
    var ok = el('button', 'sp-rd-pri', I.create); ok.type = 'submit'; nf.appendChild(inp); nf.appendChild(ok); main.appendChild(nf);
    add.addEventListener('click', function () { nf.hidden = !nf.hidden; if (!nf.hidden) inp.focus(); });
    nf.addEventListener('submit', function (e) {
      e.preventDefault(); var name = inp.value.trim(); if (!name) return; ok.disabled = true;
      radioPost({ op: 'create', name: name }).then(function (d) {
        var l = { k: 'p' + d.id, id: d.id, name: name, shared: 0, mine: true, by: '', edit: true, ids: [] }; L.push(l); go({ name: 'list', k: l.k, edit: true });
      }).catch(function (er) { ok.disabled = false; toast(er.message); });
    });
    var sec = el('div', 'sp-rd-sec'); sec.appendChild(el('h3', '', I.builtin));
    [['all', I.allsub, 'radio'], ['new', I.newsub, 'clock'], ['fav', I.favsub, 'heart']].forEach(function (a) { var l = byK(a[0]); sec.appendChild(lrow(l, count(l) + ' \u00b7 ' + a[1], a[2])); });
    main.appendChild(sec);
    var mine = L.filter(function (l) { return l.id && l.mine; }), oth = L.filter(function (l) { return l.id && !l.mine; });
    if (mine.length) { var s2 = el('div', 'sp-rd-sec'); s2.appendChild(el('h3', '', I.mine)); mine.forEach(function (l) { s2.appendChild(lrow(l, count(l) + (l.shared ? ' \u00b7 ' + I.sharedtag : ''))); }); main.appendChild(s2); }
    if (oth.length) { var s3 = el('div', 'sp-rd-sec'); s3.appendChild(el('h3', '', I.shared)); oth.forEach(function (l) { s3.appendChild(lrow(l, count(l) + (l.by ? ' \u00b7 ' + I.by + ' ' + l.by : ''))); }); main.appendChild(s3); }
  }

  function trackRow(l, id, i) {
    var r = el('div', 'sp-rd-trk'); r.setAttribute('data-id', id);
    var go = el('button', 'sp-rd-go'); go.type = 'button'; go.setAttribute('aria-label', I.play + ' ' + T[id].t);
    go.appendChild(art(id)); var tx = el('span', 'tx');
    if (T[id].n) tx.appendChild(el('em', 'sn', T[id].n));
    var tl = el('span', 'tl'); tl.appendChild(el('b', '', T[id].t || 'Untitled')); if (T[id].g) tl.appendChild(el('i', 'gn', '- ' + T[id].g)); tx.appendChild(tl);
    if (T[id].b) tx.appendChild(el('small', '', T[id].b)); go.appendChild(tx);
    var eq = el('span', 'sp-rd-eq'); eq.setAttribute('aria-hidden', 'true'); eq.innerHTML = '<i></i><i></i><i></i>'; go.appendChild(eq);
    if (st(id).fav) { var hh = el('span', 'sp-rd-h', '\u2665'); hh.setAttribute('aria-label', I.fav); go.appendChild(hh); }
    go.addEventListener('click', function () {
      if (Q.key === l.k && curId() === id && Q.pos >= 0) { toggle(); return; }
      startList(l, id, Q.shuffle && Q.key === l.k);
    });
    r.appendChild(go); return r;
  }
  function drawList(l) {
    var top = el('div', 'sp-rd-bar'), bk = el('button', 'sp-rd-back'); bk.type = 'button'; bk.appendChild(ico('back', 18)); bk.appendChild(document.createTextNode(I.radio));
    bk.addEventListener('click', function () { go({ name: 'index' }); }); top.appendChild(bk);
    var canEdit = !!(l.id && l.edit);
    if (canEdit) { var eb = el('button', 'sp-rd-pill' + (view.edit ? ' pri' : ''), view.edit ? I.done : I.edit); eb.type = 'button'; eb.addEventListener('click', function () { view.edit = !view.edit; draw(); }); top.appendChild(eb); }
    main.appendChild(top);
    var hd = el('div', 'sp-rd-lhead');
    if (l.b === 'fav') { var fi = el('span', 'sp-rd-cover big heartbox'); fi.appendChild(ico('heart', 44, 'currentColor')); hd.appendChild(fi); } else if (l.b === 'new') { var ni = el('span', 'sp-rd-cover big heartbox'); ni.appendChild(ico('clock', 44)); hd.appendChild(ni); } else hd.appendChild(cover(l.ids, true));
    var ht = el('div', 'sp-rd-lt');
    if (view.edit) {
      var nm = el('input', 'sp-rd-name'); nm.type = 'text'; nm.value = l.name; nm.maxLength = C.max; nm.setAttribute('aria-label', I.name);
      nm.addEventListener('change', function () { var v = nm.value.trim(); if (!v) { nm.value = l.name; return; } radioPost({ op: 'rename', id: l.id, name: v }).then(function () { l.name = v; }).catch(function (e) { toast(e.message); }); });
      ht.appendChild(nm);
    } else ht.appendChild(el('h2', '', l.name));
    ht.appendChild(el('p', '', count(l) + (l.shared ? ' \u00b7 ' + I.sharedtag : '') + (l.by ? ' \u00b7 ' + I.by + ' ' + l.by : ''))); hd.appendChild(ht); main.appendChild(hd);
    if (view.edit) {
      var sw = el('label', 'sp-rd-share'); var cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = !!l.shared; sw.appendChild(cb); sw.appendChild(el('span', '', I.share));
      cb.addEventListener('change', function () { radioPost({ op: 'share', id: l.id, on: cb.checked ? 1 : 0 }).then(function () { l.shared = cb.checked ? 1 : 0; }).catch(function (e) { cb.checked = !cb.checked; toast(e.message); }); });
      main.appendChild(sw);
    } else {
      var ac = el('div', 'sp-rd-acts');
      var pb = el('button', 'sp-rd-pri'); pb.type = 'button'; pb.appendChild(ico('play', 18, 'currentColor')); pb.appendChild(document.createTextNode(I.play));
      var sb = el('button', 'sp-rd-sec2'); sb.type = 'button'; sb.appendChild(ico('shuffle', 18)); sb.appendChild(document.createTextNode(I.shuffle));
      pb.addEventListener('click', function () { startList(l, 0, false); }); sb.addEventListener('click', function () { startList(l, 0, true); });
      ac.appendChild(pb); ac.appendChild(sb); main.appendChild(ac);
    }
    var ids = l.ids.filter(function (id) { return T[id]; });
    var box = el('div', 'sp-rd-tracks' + (view.edit ? ' editing' : ''));
    if (!ids.length) box.appendChild(el('p', 'sp-rd-empty', view.edit ? I.emptyedit : (l.b === 'fav' ? I.emptyfav : I.empty)));
    ids.forEach(function (id, i) {
      var r = trackRow(l, id, i);
      if (view.edit) {
        var rm = btn('sp-rd-rm', I.remove + ' ' + T[id].t, 'minus'); r.insertBefore(rm, r.firstChild);
        rm.addEventListener('click', function () { radioPost({ op: 'remove', id: l.id, song: id }).then(function () { l.ids = l.ids.filter(function (x) { return x !== id; }); draw(); }).catch(function (e) { toast(e.message); }); });
        var hg = btn('sp-rd-grip', I.drag + ': ' + T[id].t, 'grip'); r.appendChild(hg); dragger(hg, r, box, l);
      }
      box.appendChild(r);
    });
    main.appendChild(box);
    if (view.edit) {
      main.appendChild(picker(l));
      var dl = el('button', 'sp-rd-danger', I.del); dl.type = 'button'; var armed = 0;
      dl.addEventListener('click', function () {
        if (!armed) { armed = 1; dl.textContent = I.delarm; setTimeout(function () { armed = 0; dl.textContent = I.del; }, 4000); return; }
        radioPost({ op: 'delete', id: l.id }).then(function () { L.splice(L.indexOf(l), 1); if (Q.key === l.k) { window.SPUSERPAUSE = Date.now(); au.pause(); Q = { base: [], order: [], pos: -1, key: '', shuffle: false, repeat: Q.repeat }; ui(); } go({ name: 'index' }); }).catch(function (e) { toast(e.message); });
      });
      main.appendChild(dl);
    }
    ui();
  }

  function dragger(handle, row, box, l) {
    var dragging = false;
    function ids() { return [].map.call(box.querySelectorAll('.sp-rd-trk'), function (r) { return +r.getAttribute('data-id'); }); }
    function save() { var o = ids(); if (o.join() === l.ids.join()) return; l.ids = o; radioPost({ op: 'order', id: l.id, ids: o.join(',') }).catch(function (e) { toast(e.message); }); }
    handle.addEventListener('pointerdown', function (e) { e.preventDefault(); dragging = true; try { handle.setPointerCapture(e.pointerId); } catch (x) {} row.classList.add('drag'); });
    handle.addEventListener('pointermove', function (e) {
      if (!dragging) return;
      var sibs = [].filter.call(box.querySelectorAll('.sp-rd-trk'), function (r) { return r !== row; }), before = null;
      for (var i = 0; i < sibs.length; i++) { var b = sibs[i].getBoundingClientRect(); if (e.clientY < b.top + b.height / 2) { before = sibs[i]; break; } }
      if (before) { if (row.nextSibling !== before) box.insertBefore(row, before); } else if (box.lastChild !== row) box.appendChild(row);
    });
    function up() { if (!dragging) return; dragging = false; row.classList.remove('drag'); save(); }
    handle.addEventListener('pointerup', up); handle.addEventListener('pointercancel', up);
    handle.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return; e.preventDefault();
      var sib = e.key === 'ArrowUp' ? row.previousElementSibling : row.nextElementSibling; if (!sib) return;
      if (e.key === 'ArrowUp') box.insertBefore(row, sib); else box.insertBefore(sib, row);
      handle.focus(); save();
    });
  }

  function picker(l) {
    var w = el('div', 'sp-rd-pick'); w.appendChild(el('h3', '', I.addworks));
    var q = el('input'); q.type = 'search'; q.placeholder = I.search; q.setAttribute('aria-label', I.search); w.appendChild(q);
    var ul = el('div', 'sp-rd-plist'); w.appendChild(ul);
    function fill() {
      ul.innerHTML = ''; var s = q.value.trim().toLowerCase(), n = 0;
      byK('all').ids.forEach(function (id) {
        if (n >= 60 || l.ids.indexOf(id) >= 0) return; var t = T[id];
        if (s && (t.t + ' ' + t.b).toLowerCase().indexOf(s) < 0) return;
        n++; var r = el('div', 'sp-rd-prow'); r.appendChild(art(id)); var tx = el('span', 'tx'); tx.appendChild(el('b', '', t.t)); tx.appendChild(el('small', '', t.b)); r.appendChild(tx);
        var a = btn('sp-rd-add', I.addworks + ': ' + t.t, 'plus');
        a.addEventListener('click', function () { a.disabled = true; radioPost({ op: 'add', id: l.id, song: id }).then(function () { l.ids.push(id); var y = window.scrollY; draw(); window.scrollTo(0, y); }).catch(function (e) { a.disabled = false; toast(e.message); }); });
        r.appendChild(a); ul.appendChild(r);
      });
      if (!n) ul.appendChild(el('p', 'sp-rd-empty', I.nomore));
    }
    q.addEventListener('input', fill); fill(); return w;
  }

  /* ---------- mini player ---------- */
  var mArt = el('span', 'sp-rd-mart'), mT = el('span', 'tx'), mPP = btn('sp-rd-mbtn', I.play, 'play', 24, 'currentColor'), mNx = btn('sp-rd-mbtn', I.next, 'next', 24, 'currentColor'), mBar = el('span', 'sp-rd-mbar'); mBar.innerHTML = '<i></i>';
  var mOpen = el('button', 'sp-rd-mopen'); mOpen.type = 'button'; mOpen.setAttribute('aria-label', I.open); mOpen.appendChild(mArt); mOpen.appendChild(mT);
  mini.appendChild(mOpen); mini.appendChild(mPP); mini.appendChild(mNx); mini.appendChild(mBar);
  mOpen.addEventListener('click', openNP); mPP.addEventListener('click', toggle); mNx.addEventListener('click', function () { step(1); });

  /* ---------- now playing ---------- */
  var nHandle = btn('sp-np-handle', I.close, 'down', 26), nArt = el('div', 'sp-np-art'), nT = el('b', 't'), nB = el('span', 'b');
  var nHeart = btn('sp-np-circ', I.fav, 'heart', 22), nStar = btn('sp-np-circ', I.rate, 'star', 22), nMore = btn('sp-np-circ', I.more, 'more', 22, 'currentColor');
  var seek = el('input', 'sp-np-seek'); seek.type = 'range'; seek.min = 0; seek.max = 1000; seek.value = 0; seek.setAttribute('aria-label', I.pos);
  var tE = el('span', '', '0:00'), tR = el('span', '', '-0:00');
  var cPrev = btn('sp-np-ctl', I.prev, 'prev', 44, 'currentColor'), cPP = btn('sp-np-ctl big', I.play, 'play', 60, 'currentColor'), cNext = btn('sp-np-ctl', I.next, 'next', 44, 'currentColor');
  var vol = el('input', 'sp-np-vol'); vol.type = 'range'; vol.min = 0; vol.max = 100; vol.value = 100; vol.setAttribute('aria-label', I.vol);
  var bSh = el('button', 'sp-np-opt'), bRp = el('button', 'sp-np-opt'), bQ = el('button', 'sp-np-opt'); [bSh, bRp, bQ].forEach(function (b) { b.type = 'button'; });
  var qBox = el('div', 'sp-np-queue'); qBox.hidden = true;
  (function () {
    var wrap = el('div', 'sp-np-in');
    var hd = el('div', 'sp-np-top'); hd.appendChild(nHandle); wrap.appendChild(hd);
    wrap.appendChild(nArt);
    var ti = el('div', 'sp-np-ti'), tx = el('div', 'tx'); tx.appendChild(nT); tx.appendChild(nB); ti.appendChild(tx); ti.appendChild(nHeart); ti.appendChild(nStar); ti.appendChild(nMore); wrap.appendChild(ti);
    var sk = el('div', 'sp-np-sk'); sk.appendChild(seek); var tm = el('div', 'tm'); tm.appendChild(tE); tm.appendChild(tR); sk.appendChild(tm); wrap.appendChild(sk);
    var cs = el('div', 'sp-np-cs'); cs.appendChild(cPrev); cs.appendChild(cPP); cs.appendChild(cNext); wrap.appendChild(cs);
    if (!IOS) { var vr = el('div', 'sp-np-vr'); vr.appendChild(ico('vol0', 18, 'currentColor')); vr.appendChild(vol); vr.appendChild(ico('vol1', 22)); wrap.appendChild(vr); }
    var op = el('div', 'sp-np-opts'); op.appendChild(bSh); op.appendChild(bRp); op.appendChild(bQ); wrap.appendChild(op);
    np.appendChild(wrap); np.appendChild(qBox);
  })();
  function optFill(b, iconName, label, on) { b.innerHTML = ''; var c = el('span', 'c' + (on ? ' on' : '')); c.appendChild(ico(iconName, 22)); b.appendChild(c); b.appendChild(el('small', '', label)); b.setAttribute('aria-pressed', on ? 'true' : 'false'); b.setAttribute('aria-label', label); }
  /* the full-screen player is a layer over the Radio page: it gets its own history step, so a back swipe (or the system back) closes it and lands on Radio, not on the view before */
  var npPushed = false;
  function hideNP() { np.hidden = true; qBox.hidden = true; document.documentElement.classList.remove('sp-np-open'); }
  function openNP() {
    if (Q.pos < 0) return; np.hidden = false; document.documentElement.classList.add('sp-np-open'); ui(true); nHandle.focus();
    if (!npPushed) { try { history.pushState({ spnp: 1 }, '', location.href); npPushed = true; } catch (e) {} }
  }
  function closeNP() { hideNP(); if (npPushed) { npPushed = false; try { history.back(); } catch (e) {} } }
  window.addEventListener('popstate', function () { if (alive && !np.hidden) { npPushed = false; hideNP(); } });
  (function () { // a swipe in from the left edge closes the player too
    var s = null;
    document.addEventListener('touchstart', function (e) { s = null; if (!alive || np.hidden || e.touches.length !== 1) return; var p = e.touches[0]; if (p.clientX <= 36) s = { x: p.clientX, y: p.clientY, h: false, dx: 0 }; }, { passive: true });
    document.addEventListener('touchmove', function (e) {
      if (!s) return; var p = e.touches[0], dx = p.clientX - s.x, dy = p.clientY - s.y;
      if (!s.h) { if (Math.abs(dy) > 14 && Math.abs(dy) > Math.abs(dx)) { s = null; return; } if (dx > 10 && dx > Math.abs(dy) * 1.5) s.h = true; else return; }
      s.dx = dx; np.style.transition = 'none'; np.style.transform = 'translateX(' + Math.max(0, Math.min(dx, 160)) + 'px)'; e.preventDefault();
    }, { passive: false });
    function end(go) { if (!s) return; var d = s.dx, h = s.h; s = null; np.style.transition = 'transform .18s'; np.style.transform = ''; if (go && h && d > 90) closeNP(); }
    document.addEventListener('touchend', function () { end(true); }); document.addEventListener('touchcancel', function () { end(false); });
  })();
  nHandle.addEventListener('click', closeNP);
  // going to another screen (a bottom tab, a link) leaves the full-screen player behind; the music carries on
  function leaveNP() { if (!alive || np.hidden) return; npPushed = false; hideNP(); }
  document.addEventListener('click', function (e) { if (alive && !np.hidden && e.target.closest && e.target.closest('.songpress-tabs a, .songpress-userbar a')) leaveNP(); }, true);
  document.addEventListener('sp:swapped', leaveNP);
  document.addEventListener('keydown', function (e) { if (alive && e.key === 'Escape' && !np.hidden && !document.querySelector('.sp-rx-wrap')) closeNP(); });
  cPP.addEventListener('click', toggle); cPrev.addEventListener('click', function () { step(-1); }); cNext.addEventListener('click', function () { step(1); });
  bSh.addEventListener('click', function () { setShuffle(!Q.shuffle); }); bRp.addEventListener('click', cycleRepeat);
  bQ.addEventListener('click', function () { qBox.hidden = !qBox.hidden; if (!qBox.hidden) drawQueue(); });
  vol.addEventListener('input', function () { au.volume = vol.value / 100; });
  var seeking = false;
  seek.addEventListener('input', function () { seeking = true; if (au.duration) { tE.textContent = fmt(au.duration * seek.value / 1000); tR.textContent = '-' + fmt(au.duration - au.duration * seek.value / 1000); } });
  seek.addEventListener('change', function () { if (au.duration) au.currentTime = au.duration * seek.value / 1000; seeking = false; });
  function mt(id) { var s = st(id); return { id: id, title: T[id].t, fav: s.fav, avg: s.avg, n: s.n, mine: s.mine, share: T[id].sh }; }
  nHeart.addEventListener('click', function () { var id = curId(); if (id) setFav(id, !st(id).fav); });
  nStar.addEventListener('click', function () { var id = curId(); if (id && window.SPRX) window.SPRX.open(mt(id), 'rate'); });
  nMore.addEventListener('click', function () { var id = curId(); if (id && window.SPRX) window.SPRX.open(mt(id), 'menu'); });
  function setFav(id, on) {
    st(id).fav = on ? 1 : 0; syncFav(id, on); ui();
    post('songpress_react', { op: 'fav', song: id, on: on ? 1 : 0 }, C.rn).catch(function (e) { st(id).fav = on ? 0 : 1; syncFav(id, !on); ui(); toast(e.message); });
  }
  function syncFav(id, on) {
    var f = byK('fav'), i = f.ids.indexOf(id);
    if (on && i < 0) f.ids.unshift(id); else if (!on && i >= 0) f.ids.splice(i, 1);
    if (view.name === 'list' && view.k === 'fav') { var y = window.scrollY; draw(); window.scrollTo(0, y); } else if (view.name === 'index') draw();
  }
  document.addEventListener('sprx:change', function (e) { if (!alive) return; // the sheet (rate, favorite) changed a work
    var d = e.detail || {}, id = +d.id; if (!id || !T[id]) return;
    var s = st(id), was = s.fav; s.avg = d.avg; s.n = d.n; s.mine = d.mine; s.fav = d.fav ? 1 : 0;
    if (was !== s.fav) syncFav(id, !!s.fav);
    ui();
  });
  function drawQueue() {
    qBox.innerHTML = ''; var h = el('div', 'qh'); h.appendChild(el('b', '', I.queue)); var x = btn('sp-np-qx', I.close, 'down', 22); x.addEventListener('click', function () { qBox.hidden = true; }); h.appendChild(x); qBox.appendChild(h);
    Q.order.forEach(function (id, i) {
      var r = el('button', 'qi' + (i === Q.pos ? ' on' : '')); r.type = 'button'; r.appendChild(art(id)); var tx = el('span', 'tx'); tx.appendChild(el('b', '', T[id].t || 'Untitled')); tx.appendChild(el('small', '', T[id].b)); r.appendChild(tx);
      r.addEventListener('click', function () { load(i); drawQueue(); }); qBox.appendChild(r);
    });
    var on = qBox.querySelector('.on'); if (on && on.scrollIntoView) on.scrollIntoView({ block: 'center' });
  }

  /* ---------- tint ---------- */
  var tinted = 0;
  function tint(id) {
    var t = T[id], base = 'hsl(' + hue(id) + ' 38% 30%)';
    np.style.setProperty('--tint', base);
    if (!t || !t.a) return;
    var im = new Image(); im.crossOrigin = 'anonymous';
    im.onload = function () {
      if (curId() !== id) return;
      try { var c = document.createElement('canvas'); c.width = c.height = 1; var x = c.getContext('2d'); x.drawImage(im, 0, 0, 1, 1); var d = x.getImageData(0, 0, 1, 1).data;
        np.style.setProperty('--tint', 'rgb(' + Math.round(d[0] * .62) + ',' + Math.round(d[1] * .62) + ',' + Math.round(d[2] * .62) + ')'); } catch (e) {}
    };
    im.src = t.a;
  }

  /* ---------- keep every screen in step with the player ---------- */
  var shownId = 0;
  function tick() {
    var d = au.duration || 0, c = au.currentTime || 0, p = d ? Math.round(c / d * 1000) : 0;
    if (!seeking) { seek.value = p; tE.textContent = fmt(c); tR.textContent = '-' + fmt(d - c); }
    seek.style.setProperty('--p', (p / 10) + '%');
    var b = mBar.firstChild; if (b) b.style.width = (p / 10) + '%';
  }
  function ui(force) {
    var id = curId(), t = id ? T[id] : null, playing = !au.paused && !!id;
    mini.hidden = !t; document.documentElement.classList.toggle('sp-rd-has-mini', !!t);
    if (!t) { pull.hidden = true; clearTimeout(awayT); } else if (wasPlaying !== playing || shownId !== id) { peek(); } wasPlaying = playing;
    if (t) {
      if (force || shownId !== id) {
        shownId = id; mArt.innerHTML = ''; mArt.appendChild(art(id)); mT.innerHTML = ''; mT.appendChild(el('b', '', t.t)); mT.appendChild(el('small', '', t.b));
        nArt.innerHTML = ''; nArt.appendChild(art(id, 'sp-np-pic')); nT.textContent = t.t; nB.textContent = t.b; tint(id); meta();
        if (!qBox.hidden) drawQueue();
      }
      var s = st(id);
      nHeart.classList.toggle('on', !!s.fav); nHeart.setAttribute('aria-pressed', s.fav ? 'true' : 'false'); nHeart.setAttribute('aria-label', s.fav ? I.unfav : I.fav); nHeart.querySelector('svg').setAttribute('fill', s.fav ? 'currentColor' : 'none');
      nStar.classList.toggle('on', !!s.mine); nStar.querySelector('svg').setAttribute('fill', s.mine ? 'currentColor' : 'none');
    }
    [[mPP, 'play', 24], [cPP, 'play', 60]].forEach(function (a) { var b = a[0]; b.innerHTML = ''; b.appendChild(ico(playing ? 'pause' : 'play', a[2], 'currentColor')); b.setAttribute('aria-label', playing ? I.pause : I.play); });
    optFill(bSh, 'shuffle', Q.shuffle ? I.shon : I.shoff, Q.shuffle);
    optFill(bRp, 'repeat', Q.repeat === 'one' ? I.rpone : (Q.repeat === 'all' ? I.rpall : I.rpoff), Q.repeat !== 'off');
    if (Q.repeat === 'one') { var one = el('em', '', '1'); bRp.firstChild.appendChild(one); }
    optFill(bQ, 'queue', I.queue, !qBox.hidden);
    [].forEach.call(main.querySelectorAll('.sp-rd-trk'), function (r) { var on = Q.key === (view.k || '') && +r.getAttribute('data-id') === id; r.classList.toggle('on', on && !!id); r.classList.toggle('playing', on && playing); });
    tick();
  }

  window.addEventListener('beforeunload', function () { try { au.pause(); } catch (e) {} });
  window.addEventListener('pagehide', function () { try { window.SPUSERPAUSE = Date.now(); au.pause(); if ('mediaSession' in navigator) { navigator.mediaSession.playbackState = 'none'; navigator.mediaSession.metadata = null; } } catch (e) {} });
  draw(); ui();

  /* a song that was playing on the Works list or a work page carries on here, on the Now Playing page */
  (function () {
    function abs(u) { try { return new URL(u, location.href).href; } catch (e) { return u; } }
    var cur = au.currentSrc || au.src, snap = window.SPRD_SNAP; window.SPRD_SNAP = null;
    if (cur && !au.ended) { // the same audio element is already playing a track: carry on with it, no restart
      try { sessionStorage.removeItem('sp_np'); } catch (e) {} // a live track beats the saved one: never restart it
      var lid = 0; Object.keys(T).forEach(function (k) { if (!lid && T[k].s && abs(T[k].s) === abs(cur)) lid = +k; });
      if (lid) {
        if (snap && window.SPCTL === 'radio' && snap.order.indexOf(lid) > -1) { Q.base = snap.base; Q.order = snap.order; Q.pos = snap.order.indexOf(lid); Q.key = snap.key; Q.shuffle = snap.shuffle; Q.repeat = snap.repeat; }
        else { var al = byK('all') || L[0]; if (al) { Q.base = al.ids.filter(function (x) { return T[x] && T[x].s; }); Q.key = al.k; Q.shuffle = false; build(lid); Q.pos = Q.order.indexOf(lid); } }
        if (Q.pos >= 0) { claim(); meta(); ui(true); if (!snap) openNP(); return; }
      }
      return; // a track that is not in Radio's lists keeps playing as it is
    }
    var d = null; try { d = JSON.parse(sessionStorage.getItem('sp_np') || 'null'); sessionStorage.removeItem('sp_np'); } catch (e) {}
    if (!d || !d.url || Date.now() - d.ts > 120000) return;
    var id = 0; if (d.id && T[d.id] && T[d.id].s) id = +d.id;
    if (!id) Object.keys(T).forEach(function (k) { if (!id && T[k].s && abs(T[k].s) === abs(d.url)) id = +k; });
    if (!id) return;
    var l = byK('all') || L[0]; if (!l) return;
    Q.base = l.ids.filter(function (x) { return T[x] && T[x].s; }); Q.key = l.k; Q.shuffle = false; build(id); Q.pos = Q.order.indexOf(id);
    if (Q.pos < 0) return;
    var at = (d.t || 0) + (d.playing ? Math.min(20, (Date.now() - d.ts) / 1000) : 0);
    claim(); au.src = T[id].s;
    au.addEventListener('loadedmetadata', function f() { au.removeEventListener('loadedmetadata', f); try { au.currentTime = at; } catch (e) {} });
    if (d.playing) { var pr = au.play(); if (pr && pr.catch) pr.catch(function () { toast(I.tap); }); } else au.load();
    meta(); ui(true); openNP();
  }());
}());
