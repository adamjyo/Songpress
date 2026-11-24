/* SongPress reactions: favorite, rate, add to a playlist and share. Opens from a swipe to the left on a phone row,
   from press and hold, and from the row of buttons on a work page. Saves through admin-ajax. */
(function () {
  'use strict';
  var C = window.SP_RX;
  if (!C) return;
  var I = C.i || {};
  var TRAY = 256; // px: four buttons of 64

  /* ---------- helpers ---------- */
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function svg(d, fill) { var s = document.createElement('span'); s.className = 'ico'; s.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="' + (fill || 'none') + '" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + d + '</svg>'; return s; }
  var D = {
    share: '<path d="M4 12v7a1 1 0 001 1h14a1 1 0 001-1v-7M16 6l-4-4-4 4M12 2v14"/>',
    heart: '<path d="M20.8 5.6a5.2 5.2 0 00-7.4 0L12 7l-1.4-1.4a5.2 5.2 0 00-7.4 7.4L12 21.8l8.8-8.8a5.2 5.2 0 000-7.4z"/>',
    star: '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
    list: '<path d="M4 6h12M4 12h12M4 18h8M18 15V5l3 1"/><circle cx="16" cy="18" r="2"/>',
    plus: '<path d="M12 5v14M5 12h14"/>', tick: '<path d="M5 12l5 5 9-10"/>', back: '<path d="M15 5l-7 7 7 7"/>'
  };
  function visitor() {
    var k = 'sp_voter', v = '';
    try { v = localStorage.getItem(k) || ''; } catch (e) {}
    if (!/^[a-z0-9]{16,40}$/.test(v)) {
      v = ''; var a = 'abcdefghijklmnopqrstuvwxyz0123456789';
      for (var i = 0; i < 24; i++) v += a.charAt(Math.floor(Math.random() * a.length));
      try { localStorage.setItem(k, v); } catch (e) {}
    }
    return v;
  }
  function post(action, data, nonce) {
    var fd = new FormData(); fd.append('action', action); fd.append('n', nonce);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.success) throw new Error((j && j.data && j.data.msg) || I.err);
      return j.data;
    });
  }
  function react(op, m, extra) {
    var d = { op: op, song: m.id };
    if (!C['in']) { d.v = visitor(); d.t = m.t || C.t || ''; }
    Object.keys(extra || {}).forEach(function (k) { d[k] = extra[k]; });
    return post('songpress_react', d, C.n);
  }
  function votes(avg, n) {
    if (n >= C.min) return (I.avg || '').replace('%1', (+avg).toFixed(1)).replace('%2', n);
    return n === 1 ? I.votes1 : I.votes0;
  }

  /* ---------- the work an element stands for ---------- */
  function host(node) { return node.closest ? node.closest('[data-rx-song]') : null; }
  function meta(h) {
    return { h: h, id: +h.getAttribute('data-rx-song'), title: h.getAttribute('data-rx-title') || '', fav: +h.getAttribute('data-rx-fav') === 1,
      avg: +h.getAttribute('data-rx-avg') || 0, n: +h.getAttribute('data-rx-n') || 0, mine: +h.getAttribute('data-rx-mine') || 0,
      share: h.getAttribute('data-rx-share') || '', t: h.getAttribute('data-rx-t') || '' };
  }
  function store(m) {
    var h = m.h;
    h.setAttribute('data-rx-fav', m.fav ? 1 : 0); h.setAttribute('data-rx-avg', m.avg); h.setAttribute('data-rx-n', m.n); h.setAttribute('data-rx-mine', m.mine);
    paint(h, m);
  }
  function paint(h, m) {
    var hs = h.querySelector('.sp-rx-heart'); if (hs) hs.hidden = !m.fav;
    [].forEach.call(h.querySelectorAll('[data-rx="fav"]'), function (b) {
      b.classList.toggle('on', m.fav); b.setAttribute('aria-pressed', m.fav ? 'true' : 'false');
      var ic = b.querySelector('svg'); if (ic) ic.setAttribute('fill', m.fav ? 'currentColor' : 'none');
    });
    [].forEach.call(h.querySelectorAll('.sp-rx-bar [data-rx="rate"] .l'), function (l) {
      l.textContent = m.mine ? (I.yours + ': ' + m.mine) : (m.n >= C.min ? I.rate + ' \u00b7 ' + (+m.avg).toFixed(1) : I.rate);
    });
    [].forEach.call(h.querySelectorAll('[data-rx="rate"]'), function (b) { b.classList.toggle('on', !!m.mine); var ic = b.querySelector('svg'); if (ic) ic.setAttribute('fill', m.mine ? 'currentColor' : 'none'); });
  }
  [].forEach.call(document.querySelectorAll('.sp-rx-bar'), function (b) { paint(b, meta(b)); });

  /* ---------- actions ---------- */
  function toast(t) {
    var e = el('div', 'sp-rx-toast', t); e.setAttribute('role', 'status'); document.body.appendChild(e);
    setTimeout(function () { e.classList.add('out'); setTimeout(function () { e.remove(); }, 400); }, 1800);
  }
  function doShare(m) {
    var url = m.share || location.href;
    if (navigator.share) { navigator.share({ title: m.title, url: url }).catch(function () {}); return; }
    if (navigator.clipboard) { navigator.clipboard.writeText(url).then(function () { toast(I.copied); }, function () { window.prompt('', url); }); return; }
    window.prompt('', url);
  }
  function doFav(m) {
    m.fav = !m.fav; store(m);
    react('fav', m, { on: m.fav ? 1 : 0 }).catch(function (e) { m.fav = !m.fav; store(m); toast(e.message); });
  }
  function run(act, node) {
    var h = host(node); if (!h) return;
    var m = meta(h);
    if (act === 'share') doShare(m);
    else if (act === 'fav') doFav(m);
    else if (act === 'rate') sheet(m, 'rate');
    else if (act === 'list') sheet(m, 'list');
  }

  /* ---------- the sheet ---------- */
  var S = null;
  function closeSheet() { if (S) { S.remove(); S = null; document.removeEventListener('keydown', onKey); } }
  function onKey(e) { if (e.key === 'Escape') closeSheet(); }
  function sheet(m, view) {
    closeSheet();
    var back = el('div', 'sp-rx-back'); back.addEventListener('click', closeSheet);
    var box = el('div', 'sp-rx-sheet'); box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true'); box.setAttribute('aria-label', m.title);
    S = el('div', 'sp-rx-wrap'); S.appendChild(back); S.appendChild(box); document.body.appendChild(S);
    document.addEventListener('keydown', onKey);
    var grip = el('div', 'sp-rx-grip'); grip.setAttribute('aria-hidden', 'true');
    function head(t, sub, goBack) {
      box.innerHTML = ''; box.appendChild(grip);
      var h = el('div', 'sp-rx-sh');
      if (goBack) { var bb = el('button', 'sp-rx-bk'); bb.type = 'button'; bb.setAttribute('aria-label', I.close); bb.appendChild(svg(D.back)); bb.addEventListener('click', function () { draw('menu'); }); h.appendChild(bb); }
      var tx = el('div', 'sp-rx-ht'); tx.appendChild(el('b', '', t)); if (sub) tx.appendChild(el('small', '', sub)); h.appendChild(tx); box.appendChild(h);
    }
    function row(icon, label, right, fn, on) {
      var b = el('button', 'sp-rx-it' + (on ? ' on' : '')); b.type = 'button'; b.appendChild(icon); b.appendChild(el('span', 'lb', label));
      if (right) b.appendChild(el('span', 'rt', right)); b.addEventListener('click', fn); box.appendChild(b); return b;
    }
    function draw(v) {
      if (v === 'rate') return drawRate();
      if (v === 'list') return drawList();
      head(m.title, '');
      if (C['in']) {
        row(svg(D.heart, m.fav ? 'currentColor' : 'none'), m.fav ? I.unfav : I.fav, '', function () { doFav(m); closeSheet(); }, m.fav);
      }
      row(svg(D.star, m.mine ? 'currentColor' : 'none'), I.rate, m.mine ? String(m.mine) : (m.n >= C.min ? (+m.avg).toFixed(1) : ''), function () { draw('rate'); }, !!m.mine);
      if (C['in']) row(svg(D.list), I.list, '', function () { draw('list'); });
      if (m.share) row(svg(D.share), I.share, '', function () { closeSheet(); doShare(m); });
    }
    function drawRate() {
      head(I.rate, m.title, true);
      var g = el('div', 'sp-rx-stars'); g.setAttribute('role', 'radiogroup'); g.setAttribute('aria-label', I.yours);
      var info = el('p', 'sp-rx-info'), sub = el('p', 'sp-rx-sub', I.clear);
      function show() {
        [].forEach.call(g.children, function (b, i) { var on = i < m.mine; b.setAttribute('aria-checked', i + 1 === m.mine ? 'true' : 'false'); b.classList.toggle('on', on); b.querySelector('svg').setAttribute('fill', on ? 'currentColor' : 'none'); });
        info.textContent = (m.mine ? I.yours + ': ' + m.mine + ' \u00b7 ' : '') + votes(m.avg, m.n);
      }
      [1, 2, 3, 4, 5].forEach(function (k) {
        var b = el('button', 'sp-rx-star'); b.type = 'button'; b.setAttribute('role', 'radio'); b.setAttribute('aria-label', k + (k === 1 ? ' star' : ' stars')); b.appendChild(svg(D.star));
        b.addEventListener('click', function () {
          var want = (m.mine === k) ? 0 : k, old = { mine: m.mine, avg: m.avg, n: m.n };
          m.mine = want; show();
          react('rate', m, { stars: want }).then(function (d) { m.avg = d.avg; m.n = d.n; m.mine = d.mine; store(m); show(); }).catch(function (e) { m.mine = old.mine; show(); toast(e.message); });
        });
        g.appendChild(b);
      });
      box.appendChild(g); box.appendChild(info); box.appendChild(sub); show();
    }
    function drawList() {
      head(I.list, m.title, true);
      var wrap = el('div', 'sp-rx-lists'); box.appendChild(wrap);
      var msg = el('p', 'sp-rx-sub'); box.appendChild(msg);
      wrap.appendChild(el('p', 'sp-rx-sub', '…'));
      react('lists', m).then(function (d) {
        wrap.innerHTML = '';
        if (!d.audio) { msg.textContent = I.noaudio; }
        function mk(l) {
          var b = el('button', 'sp-rx-it' + (l.has ? ' on' : '')); b.type = 'button'; b.setAttribute('aria-pressed', l.has ? 'true' : 'false');
          b.appendChild(svg(D.list)); var t = el('span', 'lb'); t.appendChild(el('b', '', l.name)); t.appendChild(el('small', '', l.n + ' ' + I.works)); b.appendChild(t);
          var tk = svg(D.tick); tk.className = 'ico tk'; tk.hidden = !l.has; b.appendChild(tk);
          b.disabled = !d.audio;
          b.addEventListener('click', function () {
            var on = !l.has; l.has = on; l.n += on ? 1 : -1; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); tk.hidden = !on; t.lastChild.textContent = l.n + ' ' + I.works;
            post('songpress_radio', { op: on ? 'add' : 'remove', id: l.id, song: m.id }, C.radioN).catch(function (e) {
              l.has = !on; l.n += on ? -1 : 1; b.classList.toggle('on', !on); tk.hidden = on; t.lastChild.textContent = l.n + ' ' + I.works; msg.textContent = e.message;
            });
          });
          return b;
        }
        d.lists.forEach(function (l) { wrap.appendChild(mk(l)); });
        if (!d.lists.length) wrap.appendChild(el('p', 'sp-rx-sub', I.none));
        var f = el('form', 'sp-rx-new'), inp = el('input'); inp.type = 'text'; inp.maxLength = 120; inp.placeholder = I['new']; inp.setAttribute('aria-label', I.name);
        var go = el('button', 'sp-rx-go', I.create); go.type = 'submit'; f.appendChild(inp); f.appendChild(go); wrap.appendChild(f);
        f.addEventListener('submit', function (e) {
          e.preventDefault(); if (!inp.value.trim()) return;
          post('songpress_radio', { op: 'create', name: inp.value }, C.radioN).then(function (r) {
            var l = { id: r.id, name: inp.value.trim(), n: 0, has: false };
            wrap.insertBefore(mk(l), f); inp.value = '';
          }).catch(function (er) { msg.textContent = er.message; });
        });
      }).catch(function (e) { wrap.innerHTML = ''; msg.textContent = e.message; });
    }
    draw(view || 'menu');
  }

  /* ---------- taps on buttons: the tray and the work page bar ---------- */
  document.addEventListener('click', function (e) {
    if (holding || suppressUntil > Date.now()) { e.preventDefault(); e.stopPropagation(); return; } // the click that follows a swipe or a hold
    var b = e.target.closest && e.target.closest('[data-rx]');
    if (!b) { closeRows(null); return; }
    e.preventDefault();
    run(b.getAttribute('data-rx'), b);
    if (b.closest('.sp-rx-tray')) closeRows(null);
  }, true);

  /* ---------- swipe to the left, and press and hold, on a phone row ---------- */
  var suppressUntil = 0, holding = false, openRow = null;
  function setX(li, x, anim) { li.classList.toggle('drag', !anim); li.style.setProperty('--sx', x + 'px'); }
  function closeRows(except) { if (openRow && openRow !== except) { setX(openRow, 0, true); openRow.classList.remove('sp-rx-open'); openRow = null; } }
  var list = document.querySelector('.songpress-list');
  if (list && list.querySelector('.songpress-card')) {
    list.classList.add('sp-rx-on');
    try { if (!localStorage.getItem('sp_rx_hint')) { var hn = el('p', 'sp-rx-hint', I.hint); list.parentNode.insertBefore(hn, list); } } catch (x) {}
    var st = null, timer = 0;
    list.addEventListener('contextmenu', function (e) { if (e.target.closest('.songpress-card')) e.preventDefault(); });
    list.addEventListener('touchstart', function (e) {
      var li = e.target.closest('.songpress-card'); if (!li || e.touches.length !== 1) return;
      if (e.target.closest('.sp-rx-tray')) return;
      closeRows(li);
      var t = e.touches[0];
      st = { li: li, x: t.clientX, y: t.clientY, base: li === openRow ? -TRAY : 0, mode: '', dx: 0 };
      clearTimeout(timer);
      timer = setTimeout(function () {
        if (st && !st.mode) { st.mode = 'hold'; holding = true; if (navigator.vibrate) { try { navigator.vibrate(12); } catch (x) {} } sheet(meta(st.li), 'menu'); }
      }, 550);
    }, { passive: true });
    list.addEventListener('touchmove', function (e) {
      if (!st) return;
      var t = e.touches[0], dx = t.clientX - st.x, dy = t.clientY - st.y;
      if (!st.mode) {
        if (Math.abs(dx) > 10 && Math.abs(dx) > Math.abs(dy) * 1.4) st.mode = 'swipe';
        else if (Math.abs(dy) > 10) { st.mode = 'scroll'; clearTimeout(timer); }
        else return;
        if (st.mode === 'swipe') clearTimeout(timer);
      }
      if (st.mode !== 'swipe') return;
      e.preventDefault();
      st.dx = dx; setX(st.li, Math.max(-TRAY - 24, Math.min(0, st.base + dx)), false);
    }, { passive: false });
    function end() {
      clearTimeout(timer);
      if (holding) { holding = false; suppressUntil = Date.now() + 450; }
      if (!st) return;
      if (st.mode === 'swipe') {
        var x = Math.min(0, st.base + st.dx), open = x < -TRAY / 2;
        setX(st.li, open ? -TRAY : 0, true); st.li.classList.toggle('sp-rx-open', open); openRow = open ? st.li : null;
        suppressUntil = Date.now() + 350;
        try { localStorage.setItem('sp_rx_hint', '1'); } catch (x2) {} var h = document.querySelector('.sp-rx-hint'); if (h) h.remove();
      }
      st = null;
    }
    list.addEventListener('touchend', end); list.addEventListener('touchcancel', end);
  }
})();
