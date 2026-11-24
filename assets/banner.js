/* SongPress player banner: a card that slides down from the top while a song plays from the Works list or a work page.
   Art, title and artist, our logo, and back / pause / forward. Swipe it up to tuck it away. */
(function () {
  'use strict';
  var C = window.SP_BAN || {}, I = C.i || {};
  var bar = null, pull = null, hideT = 0, au = null, list = [], idx = -1, btn = null, own = null, single = false, current = {};

  function mk(tag, cls) { var e = document.createElement(tag); if (cls) e.className = cls; return e; }
  var SVG = {
    prev: '<svg viewBox="0 0 48 32" width="44" height="30" fill="currentColor" aria-hidden="true"><path d="M23 4L5 16l18 12zM44 4L26 16l18 12z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/></svg>',
    next: '<svg viewBox="0 0 48 32" width="44" height="30" fill="currentColor" aria-hidden="true"><path d="M25 4l18 12L25 28zM4 4l18 12L4 28z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/></svg>',
    play: '<svg viewBox="0 0 24 32" width="30" height="36" fill="currentColor" aria-hidden="true"><path d="M5 3l17 13L5 29z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/></svg>',
    pause: '<svg viewBox="0 0 24 32" width="30" height="36" fill="currentColor" aria-hidden="true"><rect x="3" y="3" width="6.5" height="26" rx="2"/><rect x="14.5" y="3" width="6.5" height="26" rx="2"/></svg>'
  };
  function accent(el) {
    var c = '', a = document.querySelector('.sp-app');
    try { c = getComputedStyle(a || document.body).getPropertyValue('--sp-accent').trim(); } catch (e) {}
    if (!/^#[0-9a-f]{6}$/i.test(c)) c = '#f5a524';
    var r = parseInt(c.substr(1, 2), 16), g = parseInt(c.substr(3, 2), 16), b = parseInt(c.substr(5, 2), 16);
    el.style.setProperty('--bn', c); el.classList.toggle('lt', (0.299 * r + 0.587 * g + 0.114 * b) > 150);
  }
  function build() {
    if (bar) return;
    bar = mk('div', 'sp-ban'); bar.setAttribute('role', 'region'); bar.setAttribute('aria-label', I.player || 'Player'); bar.hidden = true;
    bar.innerHTML = '<div class="bn-top"><span class="bn-art"></span><span class="bn-tx"><b class="bn-t"></b><span class="bn-a"></span></span><img class="bn-logo" alt="" aria-hidden="true" src="' + (C.logo || '') + '"></div><hr class="bn-hr"><div class="bn-ctl"><button type="button" class="bn-prev"></button><button type="button" class="bn-pp"></button><button type="button" class="bn-next"></button></div><button type="button" class="bn-hide">' + (I.hide || 'Hide player') + '</button>';
    accent(bar); document.body.appendChild(bar);
    ['touchstart', 'click'].forEach(function (n) { bar.addEventListener(n, function () { if (bar.classList.contains('in')) arm(); }, { passive: true }); });
    bar.querySelector('.bn-prev').innerHTML = SVG.prev; bar.querySelector('.bn-next').innerHTML = SVG.next;
    bar.querySelector('.bn-pp').addEventListener('click', function () { if (!au) return; if (au.paused) { var p = au.play(); if (p && p.catch) p.catch(function () {}); } else { window.SPUSERPAUSE = Date.now(); au.pause(); } });
    bar.querySelector('.bn-prev').addEventListener('click', function () { move(-1); });
    bar.querySelector('.bn-next').addEventListener('click', function () { move(1); });
    bar.querySelector('.bn-hide').addEventListener('click', hide);
    var y0 = null; bar.addEventListener('touchstart', function (e) { y0 = e.touches[0].clientY; }, { passive: true });
    bar.addEventListener('touchend', function (e) { if (y0 != null && e.changedTouches[0].clientY - y0 < -28) hide(); y0 = null; }, { passive: true });
  }
  function arm() { clearTimeout(hideT); hideT = setTimeout(hide, 4500); }
  function pullBtn() {
    if (pull) return pull;
    pull = mk('button', 'sp-ban-pull'); pull.type = 'button'; pull.hidden = true; pull.setAttribute('aria-label', I.show || 'Show player');
    pull.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
    pull.addEventListener('click', showBar); document.body.appendChild(pull); return pull;
  }
  function hide() {
    clearTimeout(hideT);
    if (!bar) return;
    bar.classList.remove('in'); var pb = pullBtn(); pb.style.setProperty('--bn', bar.style.getPropertyValue('--bn')); pb.classList.toggle('lt', bar.classList.contains('lt')); pb.hidden = false;
    setTimeout(function () { if (bar && !bar.classList.contains('in')) bar.hidden = true; }, 300);
  }
  function showBar() { if (C.quiet) return; build(); if (pull) pull.hidden = true; bar.hidden = false; requestAnimationFrame(function () { requestAnimationFrame(function () { bar.classList.add('in'); }); }); arm(); }
  function fill(m) {
    current = m; if (C.quiet) { if ('mediaSession' in navigator && window.MediaMetadata) navigator.mediaSession.metadata = new MediaMetadata({ title: m.title || '', artist: m.artist || '', artwork: m.art ? [{ src: m.art }] : [] }); return; } build();
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
    window.SPCTL = 'banner'; if (window.SPRD_YIELD) window.SPRD_YIELD(); bindMS();
    idx = n; var it = list[n]; btn = it.btn; fill(it);
    au.src = it.url; var p = au.play(); if (p && p.catch) p.catch(function () {});
    if (log) use({ id: it.id, src: 'list' });
    showBar();
  }
  function ownAudio() {
    if (own) return own;
    own = window.SPAU; if (!own) { own = window.SPAU = new Audio(); own.preload = 'none'; } // shared with Radio, so a song carries on across pages
    own.addEventListener('play', function () { paint(); });
    // moving around the app must never stop the music: a pause that nobody asked for, just after a page change, is undone
    own.addEventListener('pause', function () { if (!own.ended && Date.now() - (window.SPNAVAT || 0) < 4000 && Date.now() - (window.SPUSERPAUSE || 0) > 1500) { var p = own.play(); if (p && p.catch) p.catch(function () {}); } });
    own.addEventListener('pause', function () { paint(); });
    own.addEventListener('ended', function () { if (window.SPCTL === 'radio') return; if (!single) move(1); else paint(); });
    own.addEventListener('error', function () { if (window.SPCTL !== 'radio' && !single && idx >= 0) setTimeout(function () { move(1); }, 500); });
    return own;
  }
  /* leaving the page (to Radio, say): remember what was playing so Radio can carry on with it */
  function remember() {
    try {
      if (!au || !au.src || au.ended || !current.title) return;
      var id = current.id || 0;
      sessionStorage.setItem('sp_np', JSON.stringify({ url: au.currentSrc || au.src, id: id, t: au.currentTime || 0, playing: !au.paused, ts: Date.now() }));
    } catch (e) {}
  }
  window.addEventListener('pagehide', remember);
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') remember(); });

  function bindMS() {
    if (!('mediaSession' in navigator)) return;
    var h = function (n, f) { try { navigator.mediaSession.setActionHandler(n, f); } catch (e) {} };
    h('play', function () { if (au) au.play(); }); h('pause', function () { window.SPUSERPAUSE = Date.now(); if (au) au.pause(); }); h('nexttrack', function () { move(1); }); h('previoustrack', function () { move(-1); });
  }
  bindMS();
  window.SPBAN = { hide: function () { if (bar) { bar.hidden = true; bar.classList.remove('in'); } if (pull) pull.hidden = true; } };

  /* ---------- the Works list: every play button joins one queue, in list order ---------- */
  function collect() {
    return [].map.call(document.querySelectorAll('.songpress-play[data-src]'), function (b) {
      var li = b.closest('li') || b.closest('.songpress-song') || document, ti = li.querySelector('.songpress-title') || li.querySelector('h2'), ar = li.querySelector('.songpress-bandicon') || li.querySelector('.songpress-genre-line'), im = li.querySelector('img.songpress-thumb') || li.querySelector('img.songpress-cover');
      var id = li.getAttribute && (li.getAttribute('data-rx-song') || li.getAttribute('data-sp-id')); if (!id) { var ck = li.querySelector && li.querySelector('.songpress-pick'); id = ck ? ck.value : 0; }
      return { btn: b, url: b.getAttribute('data-src'), title: ti ? ti.textContent.trim() : '', artist: ar ? (ar.getAttribute('title') || ar.textContent).trim() : '', art: im ? im.getAttribute('src') : '', id: +id || 0 };
    });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.songpress-play[data-src]'); if (!b) return;
    e.preventDefault();
    if (au && au !== own) { try { au.pause(); } catch (x) {} }
    au = ownAudio(); single = false;
    if (btn === b && own.src) { if (own.paused) { var p = own.play(); if (p && p.catch) p.catch(function () {}); showBar(); } else { window.SPUSERPAUSE = Date.now(); own.pause(); } return; }
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

  /* ---------- the player keeps playing while you move around the app (phone) ----------
     Links and GET forms inside the app swap the page body in place instead of reloading, so the audio element is never torn down.
     A new track replaces the old one; Radio and anything outside the app load normally. */
  if (!C.quiet && window.fetch && window.DOMParser && history.pushState) {
    var lastKey = location.pathname + location.search, busy = false;
    var appEl = function (d) { return d.querySelector('.songpress.sp-app'); };
    var playingNow = function () { return true; }; // always: every move inside the app stays in this one page, so the browser's own back swipe is a popstate and a track started later is never cut off
    var ok = function (u) {
      if (u.origin !== location.origin || /wp-admin|wp-login|wp-json|\.(pdf|zip|csv|png|jpe?g|mp3)(\?|$)/i.test(u.pathname + u.search)) return false;
      return true;
    };
    var relink = function () {
      if (!au || !au.src || window.SPCTL === 'radio') return;
      var nl = collect(), cur = au.currentSrc || au.src, n = -1;
      nl.forEach(function (it, i) { if (it.url && (it.url === cur || cur.indexOf(it.url) > -1)) n = i; });
      if (n >= 0) { list = nl; idx = n; btn = nl[n].btn; single = false; } else { btn = null; }
      paint();
    };
    var swap = function (html, url, push) {
      var d = new DOMParser().parseFromString(html, 'text/html'), ne = appEl(d), oe = appEl(document);
      if (!ne || !oe) return false;
      oe.parentNode.replaceChild(ne, oe);
      if (d.title) document.title = d.title;
      if (push) history.pushState({ sp: 1 }, '', url);
      lastKey = location.pathname + location.search;
      [].forEach.call(ne.querySelectorAll('script'), function (o) {
        var src = o.getAttribute('src');
        if (src && !/radio\.js/.test(src) && [].some.call(document.scripts, function (x) { return x.src && x.src.split('?')[0] === o.src.split('?')[0]; })) { o.remove(); return; }
        var n = document.createElement('script');
        if (src) { n.src = src; n.defer = true; } else { n.textContent = o.textContent; }
        o.parentNode.replaceChild(n, o);
      });
      if (push) window.scrollTo(0, 0);
      if (window.SPRX && window.SPRX.rescan) window.SPRX.rescan();
      relink();
      window.SPNAVAT = Date.now();
      document.dispatchEvent(new Event('sp:swapped'));
      return true;
    };
    var go = function (url, push, again) {
      if (busy) return; busy = true; window.SPNAVAT = Date.now();
      fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'songpress' } }).then(function (r) { return r.ok ? r.text() : Promise.reject(); }).then(function (h) {
        busy = false; if (!swap(h, url, push)) { if (au && !au.paused) return; location.href = url; } // a full reload would cut the music: only when nothing is playing
      }).catch(function () { busy = false; if (au && !au.paused && !again) setTimeout(function () { go(url, push, true); }, 700); else if (!au || au.paused) location.href = url; });
    };
    document.addEventListener('click', function (e) {
      if (e.defaultPrevented || e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || !playingNow()) return;
      var a = e.target.closest && e.target.closest('.songpress.sp-app a[href]'); if (!a || a.target || a.hasAttribute('download') || a.getAttribute('onclick')) return;
      var u; try { u = new URL(a.href, location.href); } catch (x) { return; }
      if (!ok(u) || (u.pathname + u.search === location.pathname + location.search)) return;
      e.preventDefault(); go(u.href, true);
    });
    document.addEventListener('submit', function (e) {
      var f = e.target; if (e.defaultPrevented || !playingNow() || !f.closest || !f.closest('.songpress.sp-app') || (f.method || 'get').toLowerCase() !== 'get' || f.target) return;
      var u = new URL(f.action || location.href, location.href); if (!ok(u)) return;
      var q = new URLSearchParams(new FormData(f)); q.forEach(function (v, k) { u.searchParams.set(k, v); });
      e.preventDefault(); go(u.href, true);
    });
    window.addEventListener('popstate', function () {
      if (location.pathname + location.search === lastKey) return;
      if (playingNow() && ok(new URL(location.href))) go(location.href, false); else location.reload();
    });
  }
  document.addEventListener('sp:swapped', function () { if (typeof relink === 'function') relink(); });
  /* quitting or closing the app stops the music and clears the lock-screen player; just switching away (home, lock) keeps playing */
  window.addEventListener('pagehide', function () {
    try {
      window.SPUSERPAUSE = Date.now();
      if (window.SPAU) window.SPAU.pause();
      if (au && au !== window.SPAU) au.pause();
      if ('mediaSession' in navigator) { navigator.mediaSession.playbackState = 'none'; navigator.mediaSession.metadata = null; }
    } catch (e) {}
  });
  /* phone: the music plays only while the app is on screen. Going home, switching apps or locking the phone stops it and clears the lock-screen player. */
  if (!C.quiet) {
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState !== 'hidden') return;
      try {
        window.SPUSERPAUSE = Date.now();
        if (window.SPAU) window.SPAU.pause();
        if (au && au !== window.SPAU) au.pause();
        if ('mediaSession' in navigator) { navigator.mediaSession.playbackState = 'none'; navigator.mediaSession.metadata = null; }
      } catch (e) {}
    });
  }
})();
