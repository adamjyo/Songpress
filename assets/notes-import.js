/* Notes import: sends the chosen files to the server a few at a time, pauses every 100, and works around files the server will not take. */
(function () {
	var form = document.querySelector('form input[value=songpress_notes_import]');
	if (!form || !window.fetch) { return; }
	form = form.form;
	var inp = form.querySelector('input[type=file]'), box = document.getElementById('spu-nprog'), bar = document.getElementById('spu-nbar'),
		tx = document.getElementById('spu-ntxt'), btn = form.querySelector('button'), cfg = JSON.parse(form.getAttribute('data-cfg') || '{}');
	var TEXT = /\.(bear|md|txt|markdown)$/i, BIG = 400000, MAX = 3000000, BATCH_FILES = 10, BATCH_BYTES = 3000000, GROUP = 200, REST = 30;

	function strip(s) { /* embedded pictures make a note huge; they cannot be kept in a note anyway */
		return s.replace(/\(data:[a-z]+\/[^;)]+;base64,[A-Za-z0-9+\/=\s]{200,}\)/g, '(picture removed)')
			.replace(/data:[a-z]+\/[^;"' )]+;base64,[A-Za-z0-9+\/=]{200,}/g, 'picture-removed');
	}
	function prep(file) { /* resolves to a File, or null when it is too big to send */
		if (TEXT.test(file.name) && file.size > BIG) {
			return file.text().then(function (t) {
				var s = strip(t), f = new File([s], file.name, { type: 'text/plain' });
				return f.size > MAX ? null : f;
			});
		}
		return Promise.resolve(file.size > MAX && !/\.zip$/i.test(file.name) ? null : file);
	}
	function wait(sec, msg, then) {
		var n = sec; tx.textContent = msg + ' (' + n + 's)';
		var t = setInterval(function () { n--; if (n <= 0) { clearInterval(t); then(); return; } tx.textContent = msg + ' (' + n + 's)'; }, 1000);
	}
	function send(files) {
		var fd = new FormData();
		fd.append('action', 'songpress_notes_chunk'); fd.append('nonce', cfg.nonce);
		files.forEach(function (x) { fd.append('files[]', x, x.name); });
		return fetch(cfg.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
			return r.text().then(function (t) {
				var j = null;
				try { j = JSON.parse(t); } catch (e) { /* not JSON */ }
				if (!j || !j.ok) {
					var err = new Error((j && j.error || 'The server refused a batch.') + ' Server said: HTTP ' + r.status + ', ' + (t.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 140) || 'nothing') + '.');
					err.status = r.status; throw err;
				}
				return j;
			});
		});
	}

	form.addEventListener('submit', function (e) {
		var fl = Array.prototype.slice.call(inp.files || []);
		if (!fl.length) { return; }
		e.preventDefault();
		box.hidden = false; btn.disabled = true; tx.textContent = 'Reading the files…';
		Promise.all(fl.map(prep)).then(function (ready) {
			var failed = [], units = [], cur = [], sz = 0;
			ready.forEach(function (f, i) {
				if (!f) { failed.push(fl[i].name + ' (over 3 MB even without pictures)'); return; }
				if (cur.length && (cur.length >= BATCH_FILES || sz + f.size > BATCH_BYTES)) { units.push(cur); cur = []; sz = 0; }
				cur.push(f); sz += f.size;
			});
			if (cur.length) { units.push(cur); }
			var total = ready.length, sent = 0, added = 0, skip = 0, lastBreak = 0, tries = 0;
			function finish() {
				var u = new URL(location.href), msg = 'Added ' + added + ' notes. ' + skip + ' were empty or already here.';
				if (failed.length) { msg += ' ' + failed.length + ' files could not be sent: ' + failed.slice(0, 8).join(', ') + (failed.length > 8 ? ', and more' : '') + '.'; }
				u.searchParams.set('m_ok', msg); u.searchParams.set('sp_page', 'uploads'); u.searchParams.set('type', 'notes_files');
				location.href = u.toString();
			}
			function progress() { bar.style.width = Math.floor(100 * (sent + failed.length) / Math.max(1, fl.length)) + '%'; tx.textContent = 'Batch ' + (Math.floor((sent + failed.length) / GROUP) + 1) + ' of ' + Math.ceil(total / GROUP) + ' · sent ' + sent + ' of ' + total + ' files · ' + added + ' notes added' + (failed.length ? ' · ' + failed.length + ' skipped' : ''); }
			function next() {
				if (!units.length) { finish(); return; }
				if (sent + failed.length - lastBreak >= GROUP) { lastBreak = sent + failed.length; wait(REST, 'Batch ' + Math.floor(lastBreak / GROUP) + ' of ' + Math.ceil(total / GROUP) + ' done: ' + sent + ' of ' + total + ' files sent, ' + added + ' notes added. Resting before the next ' + GROUP + '', next); return; }
				var unit = units[0];
				send(unit).then(function (j) {
					tries = 0; added += j.added; skip += j.skip; sent += unit.length; units.shift(); progress(); next();
				}).catch(function (er) {
					if (unit.length > 1) { /* find the file that is the trouble: send them one at a time */
						units.shift(); unit.forEach(function (f, i) { units.splice(i, 0, [f]); }); tries = 0; next(); return;
					}
					tries++;
					if (tries <= 1) { wait(10, 'Trouble with ' + unit[0].name + ' (' + er.message + ') Trying once more', next); return; }
					/* one file the server will not take: note its name and go on with the rest */
					failed.push(unit[0].name); units.shift(); tries = 0; progress(); next();
				});
			}
			progress(); next();
		}).catch(function (er) { btn.disabled = false; tx.textContent = 'Could not read the files: ' + er.message; });
	});
}());
