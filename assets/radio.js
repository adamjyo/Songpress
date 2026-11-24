/* SongPress Radio: plays a playlist one work after another, and saves list changes through admin-ajax. */
(function () {
  'use strict';
  var root = document.querySelector('.sp-radio');
  if (!root) return;
  var ajax = root.getAttribute('data-ajax'), nonce = root.getAttribute('data-n');
  function $(s, r) { return (r || root).querySelector(s); }
  function all(s, r) { return [].slice.call((r || root).querySelectorAll(s)); }
  function say(t, bad) { var m = $('.sp-rad-msg'); if (m) { m.textContent = t || ''; m.className = 'sp-rad-msg' + (bad ? ' err' : ''); } }

  function post(data) {
    var fd = new FormData(); fd.append('action', 'songpress_radio'); fd.append('n', nonce);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.success) throw new Error((j && j.data && j.data.msg) || 'Could not save that.');
      return j.data;
    });
  }
  function fail(e) { say(e.message || 'Could not save that.', true); }

  /* ---------- the lists ---------- */
  var nw = $('.sp-rad-new');
  if (nw) nw.addEventListener('submit', function (e) {
    e.preventDefault();
    post({ op: 'create', name: nw.elements.name.value }).then(function (d) {
      var u = new URL(location.href); u.searchParams.set('sp_radio', '1'); u.searchParams.set('sp_pl', d.id); location.href = u.toString();
    }, fail);
  });

  var ed = $('.sp-rad-edit'), pid = ed ? ed.getAttribute('data-id') : '';
  function reload() { location.reload(); }
  if (ed) {
    var rn = $('.sp-rad-rename', ed);
    rn.addEventListener('submit', function (e) { e.preventDefault(); post({ op: 'rename', id: pid, name: rn.elements.name.value }).then(reload, fail); });
    $('.share', ed).addEventListener('change', function () { post({ op: 'share', id: pid, on: this.checked ? 1 : 0 }).then(function () { say('Saved.'); }, fail); });
    var del = $('.del', ed), armed = 0, label = del.textContent;
    del.addEventListener('click', function () {
      if (!armed) { armed = 1; del.textContent = del.getAttribute('data-arm'); setTimeout(function () { armed = 0; del.textContent = label; }, 4000); return; }
      post({ op: 'delete', id: pid }).then(function () { var u = new URL(location.href); u.searchParams.delete('sp_pl'); u.searchParams.delete('sp_q'); location.href = u.toString(); }, fail);
    });
    all('.add', ed).forEach(function (b) { b.addEventListener('click', function () { b.disabled = true; post({ op: 'add', id: pid, song: b.getAttribute('data-song') }).then(reload, function (e) { b.disabled = false; fail(e); }); }); });
    all('.sp-rad-tracks li').forEach(function (li) {
      var id = li.getAttribute('data-id');
      all('.mv', li).forEach(function (b) { b.addEventListener('click', function () { post({ op: 'move', id: pid, song: id, dir: b.getAttribute('data-d') }).then(reload, fail); }); });
      var rm = $('.rm', li); if (rm) rm.addEventListener('click', function () { post({ op: 'remove', id: pid, song: id }).then(reload, fail); });
    });
  }

  /* ---------- the player ---------- */
  var P = $('.sp-rad-player'); if (!P) return;
  var au = $('audio', P), rows = all('.sp-rad-tracks li'), cur = -1, order = [], pos = 0, shuf = P.getAttribute('data-shuffle') === '1';
  var bPP = $('.pp', P), bSh = $('.sh', P), seek = $('.seek', P), tm = $('.time', P);
  var ICON_PLAY = bPP.innerHTML, ICON_PAUSE = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 4v16M17 4v16"/></svg>';

  function mix(first) { // a play order: in list order, or shuffled with the chosen work first
    order = rows.map(function (_, i) { return i; });
    if (shuf) { for (var i = order.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)), t = order[i]; order[i] = order[j]; order[j] = t; } if (first != null) { order.splice(order.indexOf(first), 1); order.unshift(first); } }
    pos = first != null ? order.indexOf(first) : 0;
  }
  function fmt(t) { t = Math.floor(t || 0); return Math.floor(t / 60) + ':' + ('0' + (t % 60)).slice(-2); }
  function show(i) {
    rows.forEach(function (r, k) { r.classList.toggle('on', k === i); });
    var r = rows[i]; if (!r) return;
    $('.t', P).textContent = r.getAttribute('data-title'); $('.s', P).textContent = r.getAttribute('data-by');
    var art = r.getAttribute('data-art'), a = $('.art', P); a.innerHTML = ''; if (art) { var im = new Image(); im.src = art; im.alt = ''; a.appendChild(im); } else a.textContent = '♫';
    if ('mediaSession' in navigator && window.MediaMetadata) navigator.mediaSession.metadata = new MediaMetadata({ title: r.getAttribute('data-title'), artist: r.getAttribute('data-by'), artwork: art ? [{ src: art }] : [] });
  }
  function play(i) {
    if (!rows[i]) return; cur = i; au.src = rows[i].getAttribute('data-src'); show(i);
    var p = au.play(); if (p && p.catch) p.catch(function () { say('Tap play to start.', true); });
  }
  function next(d) {
    if (!rows.length) return; if (!order.length) mix(null);
    pos = (pos + (d || 1) + order.length) % order.length; play(order[pos]);
  }
  rows.forEach(function (r, i) { $('.go', r).addEventListener('click', function () { if (i === cur) { au.paused ? au.play() : au.pause(); return; } mix(i); play(i); }); });
  bPP.addEventListener('click', function () { if (cur < 0) { mix(shuf ? null : 0); next(0); return; } au.paused ? au.play() : au.pause(); });
  $('.nx', P).addEventListener('click', function () { next(1); });
  $('.pv', P).addEventListener('click', function () { if (au.currentTime > 4) { au.currentTime = 0; return; } next(-1); });
  bSh.addEventListener('click', function () { shuf = !shuf; bSh.setAttribute('aria-pressed', shuf ? 'true' : 'false'); mix(cur >= 0 ? cur : null); });
  au.addEventListener('play', function () { bPP.innerHTML = ICON_PAUSE; bPP.setAttribute('aria-label', 'Pause'); if ('mediaSession' in navigator) navigator.mediaSession.playbackState = 'playing'; });
  au.addEventListener('pause', function () { bPP.innerHTML = ICON_PLAY; bPP.setAttribute('aria-label', 'Play'); if ('mediaSession' in navigator) navigator.mediaSession.playbackState = 'paused'; });
  au.addEventListener('ended', function () { next(1); });
  au.addEventListener('error', function () { if (cur >= 0) { say('Could not play ' + rows[cur].getAttribute('data-title') + '. Skipping.', true); setTimeout(function () { next(1); }, 800); } });
  au.addEventListener('timeupdate', function () { if (au.duration) seek.value = Math.round(au.currentTime / au.duration * 1000); tm.textContent = fmt(au.currentTime) + ' / ' + fmt(au.duration); });
  seek.addEventListener('input', function () { if (au.duration) au.currentTime = au.duration * seek.value / 1000; });
  if ('mediaSession' in navigator) {
    navigator.mediaSession.setActionHandler('nexttrack', function () { next(1); });
    navigator.mediaSession.setActionHandler('previoustrack', function () { next(-1); });
  }
  mix(null);
}());
