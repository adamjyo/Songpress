/* SongPress tour: an animated walk-through of the phone app. Opens by itself once per person, and from the Help tab. */
(function () {
  'use strict';
  var C = window.SP_TOUR;
  if (!C || !C.slides || !C.slides.length) return;
  var I = C.i, S = C.slides, n = S.length;

  /* ---------- the pictures: small animated scenes ---------- */
  function row(cls, name, c, extra) {
    return '<div class="tr-row ' + (cls || '') + '"><i class="tr-art" style="--c:' + (c || '#4a6b3b') + '">' + (extra || '') + '</i><span><b>' + name + '</b><u>Redbeard and Company</u></span></div>';
  }
  var ICON = {
    heart: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 5.6a5.2 5.2 0 00-7.4 0L12 7l-1.4-1.4a5.2 5.2 0 00-7.4 7.4L12 21.8l8.8-8.8a5.2 5.2 0 000-7.4z"/></svg>',
    star: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/></svg>',
    list: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h12M4 12h12M4 18h8M18 15V5l3 1"/><circle cx="16" cy="18" r="2"/></svg>',
    share: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v7a1 1 0 001 1h14a1 1 0 001-1v-7M16 6l-4-4-4 4M12 2v14"/></svg>',
    works: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>',
    radio: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="2"/><path d="M8 8a5.7 5.7 0 000 8M16 8a5.7 5.7 0 010 8M5 5a10 10 0 000 14M19 5a10 10 0 010 14"/></svg>',
    help: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 015 .5c0 1.7-2.5 2-2.5 3.5M12 17.2v.1"/></svg>',
    user: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>',
    shuffle: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/></svg>',
    repeat: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 2l4 4-4 4M3 11V9a3 3 0 013-3h15M7 22l-4-4 4-4M21 13v2a3 3 0 01-3 3H3"/></svg>',
    bell: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0112 0c0 7 3 8 3 8H3s3-1 3-8M10 20a2 2 0 004 0"/></svg>'
  };
  var tabs = function (on) { return '<div class="tr-tabs">' + [['works', 'Works'], ['radio', 'Radio'], ['help', 'Help'], ['user', 'Profile']].map(function (t) { return '<span class="' + (on === t[0] ? 'on' : '') + '">' + ICON[t[0]] + '<small>' + t[1] + '</small></span>'; }).join('') + '</div>'; };
  var SCENE = {
    welcome: function () { return '<div class="tr-st s-welcome"><div class="tr-logo">SongPress</div>' + row('', 'Bring Me Home', '#4a6b3b') + row('', 'Cold Wind', '#6b3b3b') + tabs('') + '</div>'; },
    search: function () { return '<div class="tr-st s-search"><div class="tr-field"><span class="tr-type">mari</span></div>' + row('a', 'Walking Down to the Water', '#3b4a6b') + row('b', 'Marigold', '#3b6b63') + row('a', 'Cold Wind', '#6b3b3b') + '</div>'; },
    play: function () { return '<div class="tr-st s-play">' + row('r1', 'Bring Me Home', '#4a6b3b', '<em>&#9654;</em><s><i></i><i></i><i></i></s>') + row('', 'Cold Wind', '#6b3b3b') + '<i class="tr-f"></i><span class="tr-tip t1">Plays</span><span class="tr-tip t2">Opens the work</span></div>'; },
    swipe: function () { return '<div class="tr-st s-swipe"><div class="tr-sw">' + row('', 'Bring Me Home', '#4a6b3b') + '<span class="tr-tray">' + ICON.heart + ICON.star + ICON.list + ICON.share + '</span></div>' + row('', 'Cold Wind', '#6b3b3b') + '<i class="tr-f"></i></div>'; },
    hold: function () { return '<div class="tr-st s-hold">' + row('r1', 'Bring Me Home', '#4a6b3b') + row('', 'Cold Wind', '#6b3b3b') + '<i class="tr-f"></i><span class="tr-ring"></span><div class="tr-menu"><b>' + ICON.share + 'Share</b><b>' + ICON.heart + 'Favorite</b><b>' + ICON.star + 'Rate</b><b>' + ICON.list + 'Add to playlist</b></div></div>'; },
    rate: function () { return '<div class="tr-st s-rate"><div class="tr-stars">' + [1, 2, 3, 4, 5].map(function (k) { return '<i class="k' + k + '">' + ICON.star.replace('width="16" height="16"', 'width="30" height="30"') + '</i>'; }).join('') + '</div><div class="tr-heart">' + ICON.heart.replace('width="16" height="16"', 'width="40" height="40"') + '</div><span class="tr-cap">Rate with a star</span><span class="tr-cap c2">Favorite with a heart</span><i class="tr-f"></i></div>'; },
    radio: function () { return '<div class="tr-st s-radio"><div class="tr-h">Radio</div><div class="tr-lr l1">' + ICON.radio + '<b>Everything</b></div><div class="tr-lr l2">' + ICON.star.replace('<path', '<path').replace('star', 'star') + '<b>Recent releases</b></div><div class="tr-lr l3">' + ICON.heart + '<b>Favorites</b></div><div class="tr-lr l4"><i class="tr-mos"></i><b>Sunday set</b></div>' + tabs('radio') + '</div>'; },
    player: function () { return '<div class="tr-st s-player"><i class="tr-pic"></i><b class="tr-pt">Bring Me Home</b><div class="tr-pb"><i></i></div><div class="tr-pc"><span class="sh">' + ICON.shuffle + '</span><span class="pp">&#9654;</span><span class="rp">' + ICON.repeat + '</span></div></div>'; },
    home: function () { return '<div class="tr-st s-home"><div class="tr-grid"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><b class="app">SP</b></div><div class="tr-sheet"><span>Share</span><span class="add">Add to Home Screen</span></div><i class="tr-f"></i></div>'; },
    alerts: function () { return '<div class="tr-st s-alerts"><div class="tr-h">Profile</div><div class="tr-tg"><span class="bell">' + ICON.bell + '</span><b>Alerts to your phone</b><span class="sw"><i></i></span></div><div class="tr-allow">Allow</div><i class="tr-f"></i></div>'; }
  };

  /* ---------- styles ---------- */
  var css = [
    '.sp-tour{position:fixed;inset:0;z-index:99995;background:#0f1012;color:#eceef1;font-family:Inter,system-ui,-apple-system,sans-serif;display:flex;flex-direction:column;animation:tr-in .25s ease}',
    '.sp-tour[hidden]{display:none}.sp-tour *{box-sizing:border-box}@keyframes tr-in{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}',
    '.sp-tour button{font:inherit;color:inherit;cursor:pointer}',
    '.sp-tour-x{position:absolute;right:max(10px,env(safe-area-inset-right,0px));top:calc(8px + env(safe-area-inset-top,0px));width:48px;height:48px;border:0;border-radius:50%;background:#1b1d22;color:#eceef1;display:flex;align-items:center;justify-content:center;z-index:2;padding:0}',
    '.sp-tour-body{flex:1;min-height:0;display:flex;align-items:center;justify-content:center;padding:calc(64px + env(safe-area-inset-top,0px)) 20px 8px;overflow:hidden}',
    '.sp-tour-slide{width:100%;max-width:420px;text-align:center;display:none}.sp-tour-slide.on{display:block;animation:tr-slide .35s ease}.sp-tour-slide.back.on{animation-name:tr-slideb}',
    '@keyframes tr-slide{from{opacity:0;transform:translateX(40px)}to{opacity:1;transform:none}}@keyframes tr-slideb{from{opacity:0;transform:translateX(-40px)}to{opacity:1;transform:none}}',
    '.sp-tour-slide h2{font-family:"Space Grotesk",Inter,system-ui,sans-serif;font-size:26px;letter-spacing:-.02em;margin:22px 0 10px;font-weight:700;color:#eceef1}',
    '.sp-tour-slide p{margin:0 auto;max-width:34ch;font-size:16px;line-height:1.5;color:#b4b8c0}',
    '.sp-tour-foot{padding:10px 20px calc(18px + env(safe-area-inset-bottom,0px));display:flex;flex-direction:column;gap:14px;max-width:460px;width:100%;margin:0 auto}',
    '.sp-tour-dots{display:flex;justify-content:center;gap:7px}.sp-tour-dots i{width:8px;height:8px;border-radius:50%;background:#3a3d45;transition:all .25s}.sp-tour-dots i.on{width:22px;border-radius:4px;background:#f5a524}',
    '.sp-tour-btns{display:flex;gap:12px}.sp-tour-btns button{flex:1;min-height:52px;border-radius:26px;font-weight:700;font-size:16px;border:1px solid #2e3138;background:#17181c}',
    '.sp-tour-btns .nx{background:#f5a524;border-color:#f5a524;color:#1b1406!important}.sp-tour-btns .pv[disabled]{opacity:.35;cursor:default}',
    /* the scene */
    '.tr-st{position:relative;width:292px;height:208px;margin:0 auto;border:1px solid #26282d;border-radius:20px;background:#0b0c0e;overflow:hidden;padding:10px;text-align:left}',
    '.tr-row{display:flex;align-items:center;gap:8px;height:44px;margin-bottom:7px;padding:5px 8px;border:1px solid #26282d;border-radius:11px;background:#17181c;position:relative}',
    '.tr-art{width:32px;height:32px;border-radius:7px;background:linear-gradient(135deg,var(--c),#1b1d22);flex:none;position:relative;display:flex;align-items:center;justify-content:center;overflow:hidden}',
    '.tr-row b{display:block;font-size:11.5px;font-weight:600;color:#eceef1;white-space:nowrap}.tr-row u{display:block;font-size:9.5px;color:#9a9ea6;text-decoration:none;white-space:nowrap}',
    '.tr-f{position:absolute;left:0;top:0;width:28px;height:28px;margin:-14px 0 0 -14px;border-radius:50%;background:rgba(245,165,36,.4);border:2px solid #f5a524;pointer-events:none;z-index:5;opacity:0}',
    '.tr-tabs{position:absolute;left:0;right:0;bottom:0;height:48px;display:flex;border-top:1px solid #26282d;background:#141518}.tr-tabs span{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1px;color:#8d9199}.tr-tabs small{font-size:8.5px}.tr-tabs .on{color:#f5a524}',
    /* welcome */
    '.s-welcome .tr-logo{font-weight:700;font-size:15px;margin:2px 2px 10px;color:#f5a524}.s-welcome .tr-tabs span{animation:tr-pulse 4s infinite}.s-welcome .tr-tabs span:nth-child(2){animation-delay:.5s}.s-welcome .tr-tabs span:nth-child(3){animation-delay:1s}.s-welcome .tr-tabs span:nth-child(4){animation-delay:1.5s}',
    '@keyframes tr-pulse{0%,18%,100%{color:#8d9199;transform:none}6%,12%{color:#f5a524;transform:translateY(-4px) scale(1.15)}}',
    /* search */
    '.s-search .tr-field{height:36px;border:1px solid #f5a524;border-radius:10px;background:#17181c;margin-bottom:9px;display:flex;align-items:center;padding:0 12px;font-size:13px}',
    '.s-search .tr-type{display:inline-block;overflow:hidden;white-space:nowrap;width:0;border-right:2px solid #f5a524;animation:tr-typing 4.5s steps(4,end) infinite}@keyframes tr-typing{0%{width:0}35%,90%{width:4.1ch}100%{width:0}}',
    '.s-search .tr-row.a{animation:tr-hide 4.5s infinite}.s-search .tr-row.b{animation:tr-glow 4.5s infinite}@keyframes tr-hide{0%,30%{opacity:1;max-height:44px}45%,90%{opacity:.12;transform:scale(.97)}100%{opacity:1}}@keyframes tr-glow{0%,30%{border-color:#26282d}45%,90%{border-color:#f5a524;background:#1d1c19}100%{border-color:#26282d}}',
    /* play */
    '.s-play .r1 .tr-art em{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.45);font-style:normal;color:#fff;font-size:12px;animation:tr-p1 5s infinite}.s-play .r1 .tr-art s{position:absolute;left:0;right:0;bottom:4px;display:flex;justify-content:center;gap:2px;opacity:0;animation:tr-p2 5s infinite}.s-play .r1 .tr-art s i{width:3px;height:10px;background:#f5a524;border-radius:1px;animation:tr-eq .6s infinite alternate}.s-play .r1 .tr-art s i:nth-child(2){animation-delay:.2s}.s-play .r1 .tr-art s i:nth-child(3){animation-delay:.4s}@keyframes tr-eq{from{height:3px}to{height:11px}}',
    '@keyframes tr-p1{0%,14%{opacity:.35}18%,45%{opacity:1}55%,100%{opacity:.35}}@keyframes tr-p2{0%,16%{opacity:0}20%,50%{opacity:1}60%,100%{opacity:0}}',
    '.s-play .tr-f{animation:tr-pf 5s infinite}@keyframes tr-pf{0%{opacity:0;transform:translate(34px,48px)}8%{opacity:1;transform:translate(34px,48px)}14%{transform:translate(34px,48px) scale(.8)}18%{transform:translate(34px,48px) scale(1)}45%{opacity:1;transform:translate(34px,48px)}58%{transform:translate(120px,48px)}66%{transform:translate(120px,48px) scale(.8)}72%{opacity:1;transform:translate(120px,48px)}90%{opacity:0;transform:translate(120px,48px)}100%{opacity:0}}',
    '.s-play .r1{animation:tr-p3 5s infinite}@keyframes tr-p3{0%,64%{border-color:#26282d}70%,88%{border-color:#f5a524;background:#1d1c19}100%{border-color:#26282d}}',
    '.tr-tip{position:absolute;font-size:10px;font-weight:700;padding:3px 8px;border-radius:9px;background:#f5a524;color:#1b1406;opacity:0}.s-play .t1{left:30px;top:68px;animation:tr-tp1 5s infinite}.s-play .t2{left:90px;top:68px;animation:tr-tp2 5s infinite}@keyframes tr-tp1{0%,18%{opacity:0}24%,44%{opacity:1}52%,100%{opacity:0}}@keyframes tr-tp2{0%,68%{opacity:0}74%,90%{opacity:1}98%,100%{opacity:0}}',
    /* swipe */
    '.s-swipe .tr-sw{position:relative;margin-bottom:7px;border-radius:11px;overflow:hidden}.s-swipe .tr-sw .tr-row{margin:0;animation:tr-sw 4.5s infinite;background:#17181c;z-index:1;position:relative}',
    '.s-swipe .tr-tray{position:absolute;right:0;top:0;bottom:0;width:140px;display:flex;background:#262830;color:#eceef1}.s-swipe .tr-tray svg{margin:auto;flex:1}',
    '@keyframes tr-sw{0%,18%{transform:none}38%,78%{transform:translateX(-140px)}92%,100%{transform:none}}',
    '.s-swipe .tr-f{animation:tr-sf 4.5s infinite}@keyframes tr-sf{0%,8%{opacity:0;transform:translate(250px,32px)}14%{opacity:1;transform:translate(250px,32px)}38%{opacity:1;transform:translate(110px,32px)}50%{opacity:0;transform:translate(110px,32px)}100%{opacity:0}}',
    /* hold */
    '.s-hold .tr-f{left:70px;top:32px;animation:tr-hf 4.5s infinite}@keyframes tr-hf{0%,8%{opacity:0}14%,70%{opacity:1}85%,100%{opacity:0}}',
    '.s-hold .tr-ring{position:absolute;left:56px;top:18px;width:28px;height:28px;border-radius:50%;border:3px solid #f5a524;opacity:0;animation:tr-hr 4.5s infinite}@keyframes tr-hr{0%,10%{opacity:0;transform:scale(.4)}14%{opacity:.9;transform:scale(.6)}40%{opacity:.9;transform:scale(1.8)}48%,100%{opacity:0;transform:scale(2.2)}}',
    '.s-hold .r1{animation:tr-hl 4.5s infinite}@keyframes tr-hl{0%,12%{transform:none;border-color:#26282d}40%,80%{transform:scale(1.03);border-color:#f5a524;background:#1d1c19}95%,100%{transform:none;border-color:#26282d}}',
    '.s-hold .tr-menu{position:absolute;left:12px;top:62px;width:156px;padding:4px;border-radius:12px;background:#1b1d22;border:1px solid #2e3138;box-shadow:0 12px 30px rgba(0,0,0,.6);opacity:0;transform:translateY(-8px) scale(.9);transform-origin:top left;animation:tr-hm 4.5s infinite}.s-hold .tr-menu b{display:flex;align-items:center;gap:8px;height:27px;padding:0 8px;font-size:11px;font-weight:500;color:#eceef1}@keyframes tr-hm{0%,40%{opacity:0;transform:translateY(-8px) scale(.9)}48%,82%{opacity:1;transform:none}92%,100%{opacity:0}}',
    /* rate */
    '.s-rate{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px}.s-rate .tr-stars{display:flex;gap:6px;color:#5a5e68}.s-rate .tr-stars i{display:block;font-style:normal;animation:tr-star 5s infinite}.s-rate .tr-stars svg{display:block}',
    '.s-rate .k2{animation-delay:.18s!important}.s-rate .k3{animation-delay:.36s!important}.s-rate .k4{animation-delay:.54s!important}.s-rate .k5{animation-delay:.72s!important}',
    '@keyframes tr-star{0%,8%{color:#5a5e68;transform:none}14%{color:#f5a524;transform:scale(1.35)}20%,50%{color:#f5a524;transform:none}60%,100%{color:#5a5e68}}.s-rate .tr-stars i:nth-child(5){animation-name:tr-star5}@keyframes tr-star5{0%,100%{color:#5a5e68}}',
    '.s-rate .tr-heart{color:#5a5e68;animation:tr-heart 5s infinite}@keyframes tr-heart{0%,55%{color:#5a5e68;transform:none}62%{color:#f5a524;transform:scale(1.4)}70%,92%{color:#f5a524;transform:none}100%{color:#5a5e68}}.s-rate .tr-heart svg{fill:currentColor;display:block}',
    '.s-rate .tr-cap{font-size:11px;font-weight:700;color:#f5a524;animation:tr-c1 5s infinite;opacity:0;position:absolute;bottom:14px}.s-rate .c2{animation-name:tr-c2}@keyframes tr-c1{0%,10%{opacity:0}18%,48%{opacity:1}56%,100%{opacity:0}}@keyframes tr-c2{0%,58%{opacity:0}66%,90%{opacity:1}98%,100%{opacity:0}}',
    /* radio */
    '.s-radio .tr-h{font-weight:700;font-size:16px;margin:0 2px 8px}.s-radio .tr-lr{display:flex;align-items:center;gap:9px;height:34px;margin-bottom:6px;padding:0 10px;border:1px solid #26282d;border-radius:10px;background:#17181c;font-size:11.5px;color:#f5a524;opacity:0;transform:translateX(24px);animation:tr-lr 5s infinite}.s-radio .tr-lr b{color:#eceef1;font-weight:600}',
    '.s-radio .l2{animation-delay:.35s}.s-radio .l3{animation-delay:.7s}.s-radio .l4{animation-delay:1.05s}.s-radio .tr-mos{width:16px;height:16px;border-radius:4px;background:conic-gradient(#4a6b3b 0 25%,#3b4a6b 0 50%,#6b3b3b 0 75%,#6b5a3b 0)}',
    '@keyframes tr-lr{0%{opacity:0;transform:translateX(24px)}12%,80%{opacity:1;transform:none}95%,100%{opacity:0}}',
    /* player */
    '.s-player{display:flex;flex-direction:column;align-items:center;background:linear-gradient(180deg,#355a2c,#101113)}.s-player .tr-pic{width:96px;height:96px;margin-top:2px;border-radius:12px;background:linear-gradient(135deg,#5d8449,#26441f);box-shadow:0 12px 30px rgba(0,0,0,.45)}.s-player .tr-pt{font-size:13px;margin-top:8px;color:#fff}',
    '.s-player .tr-pb{width:80%;height:4px;border-radius:2px;background:rgba(255,255,255,.25);margin-top:10px}.s-player .tr-pb i{display:block;height:4px;border-radius:2px;background:#fff;animation:tr-pb 6s linear infinite}@keyframes tr-pb{from{width:6%}to{width:94%}}',
    '.s-player .tr-pc{display:flex;gap:22px;align-items:center;margin-top:12px;color:#fff}.s-player .tr-pc span{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center}.s-player .pp{font-size:20px}.s-player .sh{animation:tr-on 5s infinite}.s-player .rp{animation:tr-on 5s infinite 1.6s}@keyframes tr-on{0%,10%{background:transparent}18%,40%{background:#fff;color:#16240f}55%,100%{background:transparent}}',
    /* home screen */
    '.s-home{background:linear-gradient(180deg,#202a3a,#0b0c0e)}.s-home .tr-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;padding:10px 6px}.s-home .tr-grid i,.s-home .tr-grid b{height:40px;border-radius:10px;background:#2b3345;display:block}.s-home .app{display:flex!important;align-items:center;justify-content:center;background:#f5a524!important;color:#1b1406;font-size:12px;opacity:0;transform:scale(.4);animation:tr-app 6s infinite}',
    '@keyframes tr-app{0%,55%{opacity:0;transform:scale(.4)}65%{opacity:1;transform:scale(1.15)}72%,92%{opacity:1;transform:none}100%{opacity:0}}',
    '.s-home .tr-sheet{position:absolute;left:14px;right:14px;bottom:10px;border-radius:14px;background:#1b1d22;border:1px solid #2e3138;padding:6px;display:flex;flex-direction:column;gap:4px;transform:translateY(120%);animation:tr-sh 6s infinite}.s-home .tr-sheet span{height:30px;display:flex;align-items:center;padding:0 10px;font-size:11px;border-radius:8px;color:#eceef1}.s-home .tr-sheet .add{background:#262830;animation:tr-add 6s infinite}',
    '@keyframes tr-sh{0%,12%{transform:translateY(120%)}22%,52%{transform:none}62%,100%{transform:translateY(120%)}}@keyframes tr-add{0%,38%{outline:0}44%,52%{outline:2px solid #f5a524}60%,100%{outline:0}}',
    '.s-home .tr-f{animation:tr-hf2 6s infinite}@keyframes tr-hf2{0%,26%{opacity:0;transform:translate(150px,176px)}32%{opacity:1;transform:translate(150px,172px)}46%{opacity:1;transform:translate(150px,172px) scale(.8)}54%{opacity:0;transform:translate(150px,172px)}100%{opacity:0}}',
    /* alerts */
    '.s-alerts .tr-h{font-weight:700;font-size:16px;margin:0 2px 12px}.s-alerts .tr-tg{display:flex;align-items:center;gap:10px;height:46px;padding:0 12px;border:1px solid #26282d;border-radius:12px;background:#17181c;font-size:12px}.s-alerts .tr-tg b{flex:1;font-weight:600}.s-alerts .bell{color:#f5a524;animation:tr-bell 5s infinite;transform-origin:50% 0}',
    '@keyframes tr-bell{0%,40%{transform:none}46%{transform:rotate(14deg)}52%{transform:rotate(-12deg)}58%{transform:rotate(8deg)}64%,100%{transform:none}}',
    '.s-alerts .sw{width:38px;height:22px;border-radius:11px;background:#3a3d45;position:relative;animation:tr-sw2 5s infinite}.s-alerts .sw i{position:absolute;left:3px;top:3px;width:16px;height:16px;border-radius:50%;background:#fff;animation:tr-sw3 5s infinite}',
    '@keyframes tr-sw2{0%,18%{background:#3a3d45}26%,92%{background:#f5a524}100%{background:#3a3d45}}@keyframes tr-sw3{0%,18%{transform:none}26%,92%{transform:translateX(16px)}100%{transform:none}}',
    '.s-alerts .tr-allow{margin:16px auto 0;width:150px;text-align:center;padding:10px 0;border-radius:12px;background:#2c2f37;font-size:13px;font-weight:700;color:#4da3ff;opacity:0;animation:tr-allow 5s infinite}@keyframes tr-allow{0%,34%{opacity:0;transform:translateY(8px)}44%,80%{opacity:1;transform:none}92%,100%{opacity:0}}',
    '.s-alerts .tr-f{animation:tr-af 5s infinite}@keyframes tr-af{0%,12%{opacity:0;transform:translate(250px,62px)}18%{opacity:1;transform:translate(250px,66px)}30%{opacity:1;transform:translate(250px,66px) scale(.8)}36%{transform:translate(150px,148px)}44%{opacity:1;transform:translate(150px,148px) scale(.8)}52%,100%{opacity:0;transform:translate(150px,148px)}}',
    '@media (prefers-reduced-motion:reduce){.tr-st *,.sp-tour-slide.on{animation:none!important}.tr-lr,.tr-menu,.tr-tray{opacity:1!important;transform:none!important}.s-home .app{opacity:1!important;transform:none!important}.s-home .tr-sheet{transform:none!important}.tr-f{display:none}}',
    '@media (max-height:640px){.tr-st{transform:scale(.82);margin:-14px auto}.sp-tour-slide h2{margin-top:8px}}'
  ].join('\n');
  var sty = document.createElement('style'); sty.textContent = css; document.head.appendChild(sty);

  /* ---------- the dialog ---------- */
  var box = null, idx = 0, opener = null;
  function use(kind, detail) {
    try {
      var fd = new FormData(); fd.append('action', 'songpress_use'); fd.append('n', C.un); fd.append('kind', kind); fd.append('detail', detail || '');
      fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).catch(function () {});
    } catch (e) {}
  }
  function mk(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
  function show(i, back) {
    idx = Math.max(0, Math.min(n - 1, i));
    [].forEach.call(box.querySelectorAll('.sp-tour-slide'), function (s, k) { s.classList.toggle('on', k === idx); s.classList.toggle('back', !!back); });
    [].forEach.call(box.querySelectorAll('.sp-tour-dots i'), function (d, k) { d.classList.toggle('on', k === idx); });
    var pv = box.querySelector('.pv'), nx = box.querySelector('.nx');
    pv.disabled = idx === 0; nx.textContent = idx === n - 1 ? I.done : I.next;
    box.querySelector('.sp-tour-live').textContent = I.step.replace('%1', idx + 1).replace('%2', n) + '. ' + S[idx].t;
  }
  function close(finished) {
    if (!box) return;
    use(finished ? 'tour_done' : 'tour_skip', finished ? '' : 'step ' + (idx + 1) + ' of ' + n);
    box.remove(); box = null; document.documentElement.style.overflow = ''; document.removeEventListener('keydown', onKey);
    if (opener && opener.focus) { try { opener.focus(); } catch (e) {} }
  }
  function onKey(e) {
    if (e.key === 'Escape') close(false); else if (e.key === 'ArrowRight') { if (idx < n - 1) show(idx + 1); } else if (e.key === 'ArrowLeft') { if (idx > 0) show(idx - 1, true); }
  }
  function open(auto) {
    if (box) return;
    opener = document.activeElement;
    box = mk('div', 'sp-tour'); box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true'); box.setAttribute('aria-label', I.tour);
    var x = mk('button', 'sp-tour-x', '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>'); x.type = 'button'; x.setAttribute('aria-label', I.close); x.addEventListener('click', function () { close(false); });
    var body = mk('div', 'sp-tour-body');
    S.forEach(function (s) {
      var sl = mk('section', 'sp-tour-slide'); sl.innerHTML = (SCENE[s.k] ? SCENE[s.k]() : '');
      var h = mk('h2'); h.textContent = s.t; var p = mk('p'); p.textContent = s.d; sl.appendChild(h); sl.appendChild(p); body.appendChild(sl);
    });
    var foot = mk('div', 'sp-tour-foot'), dots = mk('div', 'sp-tour-dots'); dots.setAttribute('aria-hidden', 'true'); S.forEach(function () { dots.appendChild(mk('i')); });
    var bt = mk('div', 'sp-tour-btns'), pv = mk('button', 'pv'), nx = mk('button', 'nx'); pv.type = nx.type = 'button'; pv.textContent = I.prev;
    pv.addEventListener('click', function () { show(idx - 1, true); }); nx.addEventListener('click', function () { if (idx === n - 1) close(true); else show(idx + 1); });
    bt.appendChild(pv); bt.appendChild(nx); foot.appendChild(dots); foot.appendChild(bt);
    var live = mk('div', 'sp-tour-live'); live.setAttribute('aria-live', 'polite'); live.style.cssText = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)';
    box.appendChild(x); box.appendChild(body); box.appendChild(foot); box.appendChild(live); document.body.appendChild(box);
    document.documentElement.style.overflow = 'hidden'; document.addEventListener('keydown', onKey);
    var t0 = 0; box.addEventListener('touchstart', function (e) { t0 = e.touches[0].clientX; }, { passive: true });
    box.addEventListener('touchend', function (e) { var d = e.changedTouches[0].clientX - t0; if (Math.abs(d) > 60) { if (d < 0 && idx < n - 1) show(idx + 1); else if (d > 0 && idx > 0) show(idx - 1, true); } }, { passive: true });
    show(0); nx.focus(); use('tour_start', auto ? 'first time' : 'help');
  }
  document.addEventListener('click', function (e) { var b = e.target.closest && e.target.closest('[data-sp-tour]'); if (b) { e.preventDefault(); open(false); } });
  if (C.auto) setTimeout(function () { open(true); }, 700);
})();
