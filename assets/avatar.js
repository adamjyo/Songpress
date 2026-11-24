/* Profile picture maker: upload a photo, remove its background, put it in a colored circle above its name (first name and last initial by default; it can be changed). */
(function () {
  var root = document.querySelector('.sp-avatar-tool');
  if (!root || !window.SP_AV) { return; }
  var C = window.SP_AV, T = C.t || {};
  var W = 742, H = 750, R = 271, S = W, cv = root.querySelector('canvas'), cx = cv.getContext('2d');
  var msg = root.querySelector('.sp-av-msg'), zoom = root.querySelector('.sp-av-zoom');
  var st = { img: null, cut: null, useCut: false, z: 1, ox: 0, oy: 0, color: '' };
  cv.width = W; cv.height = H;
  var accent = (getComputedStyle(root).getPropertyValue('--sp-accent') || '').trim() || '#e9a847';
  if (!/^#[0-9a-f]{6}$/i.test(accent)) { accent = '#e9a847'; }
  st.color = accent;
  var nameIn = root.querySelector('.sp-av-name'), picker = root.querySelector('.sp-av-color'), sw = root.querySelector('.sp-av-sw');
  nameIn.value = C.label || '';
  picker.value = st.color;
  function say(t) { msg.textContent = t || ''; }
  var dk = root.querySelector('.sp-av-dark');
  function shade() { // the picked color, darkened by the slider
    var n = parseInt(st.color.slice(1), 16), f = 1 - (parseInt(dk.value, 10) || 0) / 100;
    return 'rgb(' + Math.round((n >> 16) * f) + ',' + Math.round(((n >> 8) & 255) * f) + ',' + Math.round((n & 255) * f) + ')';
  }
  function draw() {
    var col = shade(), mx = W / 2, my = 12 + R;
    cx.clearRect(0, 0, W, H);
    cx.fillStyle = '#1c1c1e';
    cx.fillRect(0, 0, W, H);
    cx.save();
    cx.beginPath();
    cx.arc(mx, my, R, 0, Math.PI * 2);
    cx.fillStyle = col;
    cx.fill();
    cx.clip();
    var im = st.useCut && st.cut ? st.cut : st.img;
    if (im) {
      var w = im.naturalWidth || im.width, h = im.naturalHeight || im.height;
      var k = Math.max(2 * R / w, 2 * R / h) * st.z;
      var dw = w * k, dh = h * k;
      cx.drawImage(im, mx - dw / 2 + st.ox, my - dh / 2 + st.oy, dw, dh);
    }
    cx.restore();
    // the name under the circle, in the circle's color
    var label = (nameIn.value || '').trim().toUpperCase();
    if (label) {
      var fs = 92;
      cx.fillStyle = col;
      cx.textAlign = 'center';
      cx.textBaseline = 'middle';
      do { cx.font = '900 ' + fs + 'px "Arial Black", Arial, sans-serif'; fs -= 2; } while (fs > 28 && cx.measureText(label).width > W - 48);
      cx.fillText(label, mx, my + R + 52);
    }
  }
  function load(src, done) {
    var im = new Image();
    im.onload = function () { done(im); };
    im.onerror = function () { say(T.bad); };
    im.src = src;
  }

  var file = root.querySelector('.sp-av-file');
  file.addEventListener('change', function () {
    var f = file.files && file.files[0];
    if (!f) { return; }
    var url = URL.createObjectURL(f);
    load(url, function (im) {
      // keep it light for phones
      var m = 1600, k = Math.min(1, m / Math.max(im.naturalWidth, im.naturalHeight));
      if (k < 1) {
        var c = document.createElement('canvas');
        c.width = Math.round(im.naturalWidth * k); c.height = Math.round(im.naturalHeight * k);
        c.getContext('2d').drawImage(im, 0, 0, c.width, c.height);
        load(c.toDataURL('image/jpeg', 0.92), function (sm) { st.img = sm; st.cut = null; st.useCut = false; st.z = 1; st.ox = st.oy = 0; zoom.value = 1; ready(); });
      } else { st.img = im; st.cut = null; st.useCut = false; st.z = 1; st.ox = st.oy = 0; zoom.value = 1; ready(); }
    });
  });
  function ready() {
    root.classList.add('has-photo');
    root.querySelector('.sp-av-cut').textContent = T.cut;
    say('');
    draw();
  }

  zoom.addEventListener('input', function () { st.z = parseFloat(zoom.value) || 1; draw(); });
  for (var i = 0; i < 24; i++) {
    (function (n) {
      var col = 'hsl(' + Math.round(n * 360 / 24) + ',85%,58%)', b = document.createElement('button');
      b.type = 'button'; b.style.background = col; b.setAttribute('aria-label', col);
      b.addEventListener('click', function () {
        var t = document.createElement('canvas').getContext('2d'); t.fillStyle = col; // browser turns the hsl into hex
        st.color = t.fillStyle; picker.value = st.color; draw();
      });
      sw.appendChild(b);
    })(i);
  }
  picker.addEventListener('input', function () { st.color = picker.value; draw(); });
  nameIn.addEventListener('input', draw);
  dk.addEventListener('input', draw);

  // drag to move the photo inside the frame
  var drag = null;
  cv.addEventListener('pointerdown', function (e) { if (!st.img) { return; } drag = { x: e.clientX, y: e.clientY, ox: st.ox, oy: st.oy }; cv.setPointerCapture(e.pointerId); });
  cv.addEventListener('pointermove', function (e) {
    if (!drag) { return; }
    var r = cv.getBoundingClientRect(), s = W / r.width;
    st.ox = drag.ox + (e.clientX - drag.x) * s;
    st.oy = drag.oy + (e.clientY - drag.y) * s;
    draw();
  });
  function end() { drag = null; }
  cv.addEventListener('pointerup', end);
  cv.addEventListener('pointercancel', end);

  // remove the background, in the browser (the photo is not sent anywhere for this)
  var cutBtn = root.querySelector('.sp-av-cut');
  var lib = null;
  cutBtn.addEventListener('click', function () {
    if (!st.img) { say(T.pick || 'Choose a photo first.'); return; }
    if (st.cut) { st.useCut = !st.useCut; cutBtn.textContent = st.useCut ? T.keep : T.cut; draw(); return; }
    cutBtn.disabled = true;
    say(T.work);
    var fail = function (e) { cutBtn.disabled = false; say((T.fail || 'Could not remove the background.') + (e && e.message ? ' (' + e.message + ')' : '')); };
    var run = function (mod) {
      var c = document.createElement('canvas');
      c.width = st.img.naturalWidth; c.height = st.img.naturalHeight;
      c.getContext('2d').drawImage(st.img, 0, 0);
      c.toBlob(function (b) {
        var go = function (cfg, again) {
          cfg.progress = function (key, cur, tot) { if (tot) { say(T.work + ' ' + Math.round(100 * cur / tot) + '%'); } };
          var fn = mod.removeBackground || (mod.default && mod.default.removeBackground) || mod.default;
          Promise.resolve(fn(b, cfg)).then(function (out) {
            load(URL.createObjectURL(out), function (im) { st.cut = im; st.useCut = true; cutBtn.disabled = false; cutBtn.textContent = T.keep; say(''); draw(); });
          }).catch(function (e) { if (again) { say(T.work); go({ device: 'cpu', model: 'isnet_quint8' }, false); } else { fail(e); } });
        };
        go({}, true);
      }, 'image/png');
    };
    if (lib) { run(lib); return; }
    import(C.lib).then(function (m) { lib = m; run(m); }).catch(fail);
  });

  root.querySelector('.sp-av-save').addEventListener('click', function () {
    if (!st.img) { say(T.pick); return; }
    var btn = this; btn.disabled = true; say(T.saving);
    cv.toBlob(function (b) {
      var fd = new FormData();
      fd.append('action', 'songpress_my_photo');
      fd.append('n', C.n);
      if (C.person) { fd.append('person', C.person); }
      fd.append('file', b, 'profile.png');
      fetch(C.ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (j) {
        btn.disabled = false;
        if (j && j.success) { say(T.saved); setTimeout(function () { location.reload(); }, 600); } else { say((j && j.data && j.data.msg) || T.fail2); }
      }).catch(function () { btn.disabled = false; say(T.fail2); });
    }, 'image/png');
  });

  draw();
})();
