/* Notes import: sends the chosen files to the server a few at a time, pauses every 100, and works around files the server will not take. */
(function () {
	var form = document.querySelector('form input[value=songpress_notes_import]');
	if (!form || !window.fetch) { return; }
	form = form.form;
	var inp = form.querySelector('input[type=file]'), box = document.getElementById('spu-nprog'), bar = document.getElementById('spu-nbar'),
		tx = document.getElementById('spu-ntxt'), btn = form.querySelector('button'), cfg = JSON.parse(form.getAttribute('data-cfg') || '{}');
	var unreadable = [], TEXT = /\.(bear|md|txt|markdown)$/i, BIG = 400000, MAX = 3000000, BATCH_FILES = 10, BATCH_BYTES = 3000000, GROUP = 200, REST = 2;

	function strip(s) { /* embedded pictures make a note huge; they cannot be kept in a note anyway */
		return s.replace(/\(data:[a-z]+\/[^;)]+;base64,[A-Za-z0-9+\/=\s]{200,}\)/g, '(picture removed)')
			.replace(/data:[a-z]+\/[^;"' )]+;base64,[A-Za-z0-9+\/=]{200,}/g, 'picture-removed');
	}
	function readOnce(file) { return /\.zip$/i.test(file.name) ? file.arrayBuffer() : file.text(); }
	function readRetry(file, left) { /* a file kept in iCloud or Dropbox may only download when it is opened; asking again a few seconds later usually works */
		return readOnce(file).catch(function (er) {
			if (left <= 0) { throw er; }
			tx.textContent = 'Waiting for ' + file.name + ' to be available on this computer…';
			return new Promise(function (ok) { setTimeout(ok, 3000); }).then(function () { return readRetry(file, left - 1); });
		});
	}
	function prep(file) { /* reads the whole file into memory now, so a file that cannot be read is known before anything is sent. Resolves to a File, or null */
		if (!/\.(bear|md|txt|markdown|json|zip)$/i.test(file.name)) { return Promise.resolve(null); }
		return readRetry(file, 5).then(function (data) {
			if (/\.zip$/i.test(file.name)) { return new File([data], file.name, { type: 'application/zip' }); }
			var s = file.size > BIG ? strip(data) : data, f = new File([s], file.name, { type: 'text/plain' });
			return f.size > MAX ? null : f;
		}).catch(function () { unreadable.push(file.name); return null; });
	}
	function wait(sec, msg, then) {
		var n = sec; tx.textContent = msg + ' (' + n + 's)';
		var t = setInterval(function () { n--; if (n <= 0) { clearInterval(t); then(); return; } tx.textContent = msg + ' (' + n + 's)'; }, 1000);
	}
	function send(files, zipFrom) {
		var fd = new FormData();
		if (zipFrom) { fd.append('zip_from', zipFrom); }
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

	function b64(str) { /* text to base64, any characters */
		var bytes = new TextEncoder().encode(str), bin = '', i;
		for (i = 0; i < bytes.length; i += 8192) { bin += String.fromCharCode.apply(null, bytes.subarray(i, i + 8192)); }
		return btoa(bin);
	}
	function sendText(file) { /* the same note as a plain form field, in base64, in case the upload itself is what the server refuses */
		return file.text().then(function (t) {
			var body = new URLSearchParams();
			body.set('action', 'songpress_notes_text'); body.set('nonce', cfg.nonce); body.set('name', file.name); body.set('b64', b64(t));
			return fetch(cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) {
				return r.text().then(function (x) {
					var j = null;
					try { j = JSON.parse(x); } catch (e) { /* not JSON */ }
					if (!j || !j.ok) { throw new Error('HTTP ' + r.status + ', ' + (x.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 100) || 'nothing')); }
					return j;
				});
			});
		});
	}
	form.addEventListener('submit', function (e) {
		var fl = Array.prototype.slice.call(inp.files || []);
		if (!fl.length) { return; }
		e.preventDefault();
		box.hidden = false; btn.disabled = true; tx.textContent = 'Reading the files…';
		Promise.all(fl.map(prep)).then(function (ready) {
			var failed = unreadable.map(function (n) { return n + ' (this computer could not read it: if it is in iCloud or Dropbox, copy it to a folder on this Mac first)'; }), units = [], cur = [], sz = 0;
			ready.forEach(function (f, i) {
				if (!f) { if (unreadable.indexOf(fl[i].name) < 0 && /\.(bear|md|txt|markdown|json|zip)$/i.test(fl[i].name)) { failed.push(fl[i].name + ' (over 3 MB even without pictures)'); } return; }
				var isZip = /\.zip$/i.test(f.name);
				if (cur.length && (isZip || cur.length >= BATCH_FILES || sz + f.size > BATCH_BYTES)) { units.push(cur); cur = []; sz = 0; }
				cur.push(f); sz += f.size;
				if (isZip) { units.push(cur); cur = []; sz = 0; }
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
				send(unit, unit.zipFrom || 0).then(function (j) {
					tries = 0; added += j.added; skip += j.skip;
					if (j.zip && j.zip.next) { /* a zip is read a slice at a time */
						unit.zipFrom = j.zip.next; tx.textContent = unit[0].name + ': ' + j.zip.next + ' of ' + j.zip.total + ' notes read · ' + added + ' added'; next(); return;
					}
					sent += unit.length; units.shift(); progress(); next();
				}).catch(function (er) {
					if (unit.length > 1) { /* find the file that is the trouble: send them one at a time */
						units.shift(); unit.forEach(function (f, i) { units.splice(i, 0, [f]); }); tries = 0; next(); return;
					}
					if (/\.zip$/i.test(unit[0].name)) { failed.push(unit[0].name + ' (' + er.message + ')'); units.shift(); tries = 0; progress(); next(); return; }
					/* one file the server will not take as an upload: send its text as a plain form field instead */
					sendText(unit[0]).then(function (j) {
						added += j.added; skip += j.skip; sent += 1; units.shift(); tries = 0; progress(); next();
					}).catch(function (e2) {
						tries++;
						if (tries <= 1) { wait(10, 'Trouble with ' + unit[0].name + ' (' + e2.message + ') Trying once more', next); return; }
						failed.push(unit[0].name + ' (' + e2.message + ')'); units.shift(); tries = 0; progress(); next();
					});
				});
			}
			progress(); next();
		}).catch(function (er) { btn.disabled = false; tx.textContent = 'Could not read the files: ' + er.message; });
	});
}());
