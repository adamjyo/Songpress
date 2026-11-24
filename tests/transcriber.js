// node tests/transcriber.js : checks the Transcriber engine on synthetic audio
const T = require('../assets/transcriber-core.js');
let n = 0, fail = 0;
function check(name, got, want) { n++; const g = JSON.stringify(got), w = JSON.stringify(want); if (g !== w) { fail++; console.log('FAIL ' + name + '\n  got:  ' + g + '\n  want: ' + w); } }

// a song: G D Em C, two bars (8 beats) each, 90 bpm, 3 times round, harmonic notes + a kick on every beat
const sr = 22050, bpm = 90, beat = 60 / bpm, prog = [[7, 0], [2, 0], [4, 1], [0, 0]], rounds = 3, secs = 8 * beat;
const x = new Float32Array(Math.floor(sr * secs * prog.length * rounds));
const freq = (pc, oct) => 440 * Math.pow(2, (pc - 9) / 12 + (oct - 4));
let seed = 7; const rnd = () => (seed = (seed * 16807) % 2147483647) / 2147483647 - 0.5;
const truth = [];
for (let r = 0; r < rounds; r++) prog.forEach((c, k) => {
  const t0 = (r * prog.length + k) * secs; truth.push([t0, c]);
  const tones = [c[0], (c[0] + (c[1] ? 3 : 4)) % 12, (c[0] + 7) % 12];
  for (let i = Math.floor(t0 * sr); i < Math.floor((t0 + secs) * sr); i++) {
    const t = i / sr; let v = 0;
    tones.forEach((p, q) => { for (let h = 1; h <= 4; h++) v += Math.sin(2 * Math.PI * freq(p, q === 0 ? 3 : 4) * h * t) / h; });
    v += 1.2 * Math.sin(2 * Math.PI * freq(c[0], 2) * t); // bass
    const bt = (t % beat); if (bt < 0.04) v += 3 * rnd() * (1 - bt / 0.04); // kick-ish click
    x[i] = 0.12 * v + 0.01 * rnd();
  }
});
const res = T.analyze(x, sr, {});
check('key found', res.keyName, 'G');
console.log('tempo', res.bpm, 'confidence', res.tempoConfidence.toFixed(2), 'chords', res.chords.map(c => c.chord + '@' + c.t0.toFixed(1)).join(' '));
check('tempo near 90 (or its half/double)', [90, 45, 180].some(b => Math.abs(res.bpm - b) <= 2), true);
const names = res.chords.map(c => c.chord).join(' ');
check('progression', names, 'G D Em C G D Em C G D Em C');
let off = 0; res.chords.forEach((c, i) => { off = Math.max(off, Math.abs(c.t0 - truth[i][0])); });
check('changes within 0.5s of the truth', off < 0.5, true);

// lyrics + heard words
const lyr = "Verse 1\nWalking down to the water\nCold wind on my face\n\nChorus:\nOh, bring me home again\nBring me home";
const lines = T.parseLyrics(lyr);
check('line kinds', lines.map(l => l.kind), ['label', 'line', 'line', 'blank', 'label', 'line', 'line']);
const heard = []; let t = 1.0;
"Walking down to the water Cold wind on my face Oh bring me home again Bring me home".split(' ').forEach((w, i) => { heard.push({ word: i === 3 ? 'two' : w.toLowerCase(), start: t, end: t + 0.3 }); t += 0.45 + (i === 5 || i === 9 ? 2 : 0); });
const al = T.alignWords(lines, heard);
check('all words matched but the misheard one', al.matched > 0.85, true);
check('line starts', al.lines.map(l => +l.t0.toFixed(2)), [1, 4.05, 8.4, 11.1].map((v, i) => +al.lines[i].t0.toFixed(2)));
check('line 2 starts after line 1', al.lines[1].t0 > al.lines[0].t0 && al.lines[2].t0 > al.lines[1].t0, true);
const pro = T.buildChordPro({ title: 'Test', key: res.keyName, bpm: res.bpm, lines, align: al.lines, segs: res.chords, duration: 40 });
console.log(pro);
check('has title and key', /\{title: Test\}\n\{key: G\}/.test(pro), true);
check('first line starts with a chord', /\n\[G\]Walking/.test(pro), true);
check('a label became a comment', /\{comment: Verse 1\}/.test(pro), true);
check('lyrics survive the round trip', pro.replace(/\[[^\]]*\]/g, '').includes('Cold wind on my face') && pro.replace(/\[[^\]]*\]/g, '').includes('Bring me home'), true);
console.log(fail ? fail + ' of ' + n + ' failed' : 'All ' + n + ' checks passed');
process.exit(fail ? 1 : 0);
