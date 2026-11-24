/* SongPress player banner: a card that slides down from the top while a song plays from the Works list or a work page.
   Art, title and artist, our logo, and back / pause / forward. Swipe it up to tuck it away. */
(function () {
  'use strict';
  var C = window.SP_BAN || {}, I = C.i || {};
  var bar = null, au = null, list = [], idx = -1, btn = null, own = null, single = false, current = {};

  function mk(tag, cls) { var e = document.createElement(tag); if (cls) e.className = cls; return e; }
  var SVG = {
    prev: '<svg viewBox="0 0 48 32" width="44" height="30" fill="currentColor" aria-hidden="true"><path d="M23 4L5 16l18 12zM44 4L26 16l18 12z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/></svg>',
    next: '<svg viewBox="0 0 48 32" width="44" height="30" fill="currentColor" aria-hidden="true"><path d="M25 4l18 12L25 28zM4 4l18 12L4 28z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/></svg>',
    play: '<svg viewBox="0 0 24 32" width="30" height="36" fill="currentColor" aria-hidden="true"><path d="M5 3l17 13L5 29z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/></svg>',
    pause: '<svg viewBox="0 0 24 32" width="30" height="36" fill="currentColor" aria-hidden="true"><rect x="3" y="3" width="6.5" height="26" rx="2"/><rect x="14.5" y="3" width="6.5" height="26" rx="2"/></svg>'
  };
  function build() {
    if (bar) return;
    bar = mk('div', 'sp-ban'); bar.setAttribute('role', 'region'); bar.setAttribute('aria-label', I.player || 'Player'); bar.hidden = true;
    bar.innerHTML = '<div class="bn-top"><span class="bn-art"></span><span class="bn-tx"><b class="bn-t"></b><span class="bn-a"></span></span><img class="bn-logo" alt="" src="' + (C.logo || '') + '"></div><hr class="bn-hr"><div class="bn-ctl"><button type="button" class="bn-prev"></button><button type="button" class="bn-pp"></button><button type="button" class="bn-next"></button></div><button type="button" class="bn-hide">' + (I.hide || 'Hide player') + '</button>';
    document.body.appendChild(bar);
    bar.querySelector('.bn-prev').innerHTML = SVG.prev; bar.querySelector('.bn-next').innerHTML = SVG.next;
    bar.querySelector('.bn-pp').addEventListener('click', function () { if (!au) return; if (au.paused) { var p = au.play(); if (p && p.catch) p.catch(function () {}); } else au.pause(); });
    bar.querySelector('.bn-prev').addEventListener('click', function () { move(-1); });
    bar.querySelector('.bn-next').addEventListener('click', function () { move(1); });
    bar.querySelector('.bn-hide').addEventListener('click', hide);
    var y0 = null; bar.addEventListener('touchstart', function (e) { y0 = e.touches[0].clientY; }, { passive: true });
    bar.addEventListener('touchend', function (e) { if (y0 != null && e.changedTouches[0].clientY - y0 < -28) hide(); y0 = null; }, { passive: true });
  }
  function hide() { if (bar) { bar.classList.remove('in'); setTimeout(function () { if (bar && !bar.classList.contains('in')) bar.hidden = true; }, 300); } }
  function showBar() { build(); bar.hidden = false; requestAnimationFrame(function () { requestAnimationFrame(function () { bar.classList.add('in'); }); }); }
  function fill(m) {
    current = m; build();
    bar.querySelector('.bn-t').textContent = m.title || ''; bar.querySelector('.bn-a').textContent = m.artist || '';
    var a = bar.querySelector('.bn-art'); a.innerHTML = '';
    if (m.art) { var im = document.createElement('img'); im.src = m.art; im.alt = ''; a.appendChild(im); } else { a.textContent = '♫'; }
    var pv = bar.querySelector('.bn-prev'), nx = bar.querySelector('.bn-next');
    pv.setAttribute('aria-label', single ? (I.back15 || 'Back 15 seconds') : (I.prev || 'Previous')); nx.setAttribute('aria-label', single ? (I.fwd15 || 'Forward 15 seconds') : (I.next || 'Next'));
    if ('mediaSession' in navigator && window.MediaMetadata) navigator.mediaSession.metadata = new MediaMetadata({ title: m.title || '', artist: m.artist || '', artwork: m.art ? [{ src: m.art }] : [] });
  }
  function paint() {
    if (!bar) return;
    var playing = au && !au.paused && !au.ended;
    var pp = bar.querySelector('.bn-pp'); pp.innerHTML = playing ? SVG.pause : SVG.play; pp.setAttribute('aria-label', playing ? (I.pause || 'Pause') : (I.play || 'Play'));
    [].forEach.call(document.querySelectorAll('.songpress-play.on'), function (b) { if (b !== btn || !playing) b.classList.remove('on'); });
    if (btn && playing) btn.classList.add('on');
  }
  function use(m) { if (window.SPRX && window.SPRX.use) window.SPRX.use('play', m.id || 0, m.src || 'list'); }

  function move(d) {
    if (single) { if (au) au.currentTime = Math.max(0, Math.min((au.duration || 1e9), au.currentTime + d * 15)); return; }
    var n = idx + d; if (n < 0) { if (au) au.currentTime = 0; return; } if (n >= list.length) { if (au) { au.pause(); } return; }
    start(n, true);
  }
  function start(n, log) {
    idx = n; var it = list[n]; btn = it.btn; fill(it);
    au.src = it.url; var p = au.play(); if (p && p.catch) p.catch(function () {});
    if (log) use({ id: it.id, src: 'list' });
    showBar();
  }
  function ownAudio() {
    if (own) return own;
    own = new Audio(); own.preload = 'none';
    own.addEventListener('play', function () { paint(); });
    own.addEventListener('pause', function () { paint(); });
    own.addEventListener('ended', function () { if (!single) move(1); else paint(); });
    own.addEventListener('error', function () { if (!single && idx >= 0) setTimeout(function () { move(1); }, 500); });
    return own;
  }
  if ('mediaSession' in navigator) {
    var h = function (n, f) { try { navigator.mediaSession.setActionHandler(n, f); } catch (e) {} };
    h('play', function () { if (au) au.play(); }); h('pause', function () { if (au) au.pause(); }); h('nexttrack', function () { move(1); }); h('previoustrack', function () { move(-1); });
  }

  /* ---------- the Works list: every play button joins one queue, in list order ---------- */
  function collect() {
    return [].map.call(document.querySelectorAll('.songpress-play[data-src]'), function (b) {
      var li = b.closest('li') || document, ti = li.querySelector('.songpress-title'), ar = li.querySelector('.songpress-bandicon'), im = li.querySelector('img.songpress-thumb');
      var id = li.getAttribute && li.getAttribute('data-rx-song'); if (!id) { var ck = li.querySelector && li.querySelector('.songpress-pick'); id = ck ? ck.value : 0; }
      return { btn: b, url: b.getAttribute('data-src'), title: ti ? ti.textContent.trim() : '', artist: ar ? (ar.getAttribute('title') || ar.textContent).trim() : '', art: im ? im.getAttribute('src') : '', id: +id || 0 };
    });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.songpress-play[data-src]'); if (!b) return;
    e.preventDefault();
    if (au && au !== own) { try { au.pause(); } catch (x) {} }
    au = ownAudio(); single = false;
    if (btn === b && own.src) { if (own.paused) { var p = own.play(); if (p && p.catch) p.catch(function () {}); showBar(); } else own.pause(); return; }
    list = collect(); var n = -1; list.forEach(function (it, i) { if (it.btn === b) n = i; });
    if (n < 0) return;
    start(n, true);
  });

  /* ---------- a work page: the page's own audio element feeds the banner ---------- */
  var pa = document.querySelector('audio.songpress-audio');
  if (pa) {
    pa.addEventListener('play', function () {
      if (own && !own.paused) own.pause();
      au = pa; single = true; btn = null;
      var h2 = document.querySelector('.songpress-song h2'), bd = document.querySelector('.songpress-song .songpress-band'), cv = document.querySelector('.songpress-song .songpress-cover img, .songpress-song img.songpress-cover');
      fill({ title: h2 ? h2.textContent.trim() : document.title, artist: bd ? bd.textContent.trim() : '', art: cv ? cv.getAttribute('src') : '' });
      showBar(); paint();
    });
    pa.addEventListener('pause', paint); pa.addEventListener('ended', paint);
  }
})();
