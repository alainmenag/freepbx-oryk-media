/*
 * recorder.js -- browser microphone capture for the Oryk Media module.
 *
 * All encoding happens here. The mic is captured as Float32 at whatever rate
 * the audio hardware runs at, resampled by an OfflineAudioContext to a
 * telephony rate, and written out as 16-bit mono PCM WAV -- a format Asterisk
 * plays natively, so the server side never has to shell out to sox or ffmpeg.
 *
 * getUserMedia only exists on a secure origin. On a plain http:// admin page
 * there is no microphone to be had and no flag we can set from here; the page
 * says so rather than failing quietly.
 */
(function (window, document) {
	'use strict';

	var CFG = (window.OrykMedia && window.OrykMedia.config) || {};
	var MAX_SECONDS = 20 * 60;
	var TICK_MS = 50;        // timer repaint interval; the clock shows hundredths
	var ZERO = '00:00:00:00';
	var AJAX = 'ajax.php';
	var MODULE = 'oryk_media';

	var el = {};
	var ctx = null;          // AudioContext, kept alive between takes
	var stream = null;       // MediaStream from getUserMedia
	var source = null;
	var analyser = null;
	var capture = null;      // AudioWorkletNode or ScriptProcessorNode
	var sink = null;         // muted gain, only so ScriptProcessor is pulled

	var chunks = [];         // Float32Array pieces at ctx.sampleRate
	var frames = 0;
	var recording = false;
	var paused = false;
	var startedAt = 0;
	var elapsedBefore = 0;
	var lastClock = '';
	var opening = false;

	var takeBlob = null;     // encoded WAV of the finished take, null until stop
	var previewUrl = null;   // object URL behind the player: take or paused monitor
	var monitoring = false;

	/* ------------------------------------------------------------------ */
	/* Small helpers                                                       */
	/* ------------------------------------------------------------------ */

	function $(id) {
		return document.getElementById(id);
	}

	function show(node, on) {
		if (node) {
			node.classList.toggle('hidden', !on);
		}
	}

	function text(node, value) {
		if (node) {
			node.textContent = value;
		}
	}

	/**
	 * A repeating loop that can actually be stopped. Cancelling the handle is
	 * not enough — it only ever points at the most recent run, so a second
	 * loop would leave one behind that nothing could reach. Each run carries
	 * its generation; stop() bumps it and strays retire on their next wake.
	 * Body returns false to stop.
	 */
	function makeLoop(schedule, cancel, body) {
		var gen = 0;
		var handle = null;

		function stop() {
			gen++;

			if (handle !== null) {
				cancel(handle);
				handle = null;
			}
		}

		function run(mine) {
			if (mine !== gen) {
				return;
			}

			handle = null;

			if (body() === false) {
				return;
			}

			handle = schedule(function () {
				run(mine);
			});
		}

		return {
			start: function () {
				stop();
				run(gen);
			},
			stop: stop
		};
	}

	function setButton(node, label, icon) {
		if (!node) {
			return;
		}

		node.querySelector('span').textContent = label;
		node.querySelector('i').className = 'fa fa-' + icon;
	}

	function fail(message) {
		text(el.error, message);
		show(el.error, true);
	}

	function clearError() {
		show(el.error, false);
	}

	function clock(seconds) {
		var cs = Math.floor((seconds || 0) * 100);
		var h = Math.floor(cs / 360000);
		var m = Math.floor((cs % 360000) / 6000);
		var s = Math.floor((cs % 6000) / 100);

		return pad2(h) + ':' + pad2(m) + ':' + pad2(s) + ':' + pad2(cs % 100);
	}

	function pad2(n) {
		return (n < 10 ? '0' : '') + n;
	}

	function bytes(n) {
		if (!n) {
			return '0 B';
		}
		if (n < 1024) {
			return n + ' B';
		}
		if (n < 1048576) {
			return (n / 1024).toFixed(1) + ' KB';
		}

		return (n / 1048576).toFixed(1) + ' MB';
	}

	function when(unixSeconds) {
		if (!unixSeconds) {
			return '';
		}

		return new Date(unixSeconds * 1000).toLocaleString();
	}

	function escapeHtml(value) {
		return String(value).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function toast(message, kind) {
		if (typeof window.fpbxToast === 'function') {
			window.fpbxToast(message, kind === 'error' ? 'Error' : '', kind || 'success');
		}
	}

	/* ------------------------------------------------------------------ */
	/* WAV encoding                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Concatenate the captured pieces into one Float32Array.
	 */
	function flatten(pieces, total) {
		var out = new Float32Array(total);
		var at = 0;

		for (var i = 0; i < pieces.length; i++) {
			out.set(pieces[i], at);
			at += pieces[i].length;
		}

		return out;
	}

	/**
	 * Resample to the target rate.
	 *
	 * OfflineAudioContext does a proper job of this; a naive index-stepping
	 * resample aliases badly on speech, which is exactly the content here.
	 */
	function resample(samples, fromRate, toRate) {
		if (fromRate === toRate) {
			return Promise.resolve(samples);
		}

		var length = Math.max(1, Math.round(samples.length * toRate / fromRate));
		var OfflineCtx = window.OfflineAudioContext || window.webkitOfflineAudioContext;

		if (!OfflineCtx) {
			return Promise.reject(new Error('This browser cannot resample audio.'));
		}

		var offline;

		try {
			offline = new OfflineCtx(1, length, toRate);
		} catch (e) {
			// Safari historically refused sample rates below 22050 here.
			return Promise.reject(new Error('This browser will not render audio at ' + toRate + ' Hz.'));
		}

		var buffer = offline.createBuffer(1, samples.length, fromRate);
		buffer.getChannelData(0).set(samples);

		var node = offline.createBufferSource();
		node.buffer = buffer;
		node.connect(offline.destination);
		node.start(0);

		return offline.startRendering().then(function (rendered) {
			return rendered.getChannelData(0);
		});
	}

	/**
	 * 16-bit mono PCM WAV.
	 */
	function encodeWav(samples, rate) {
		var buffer = new ArrayBuffer(44 + samples.length * 2);
		var view = new DataView(buffer);

		function ascii(offset, value) {
			for (var i = 0; i < value.length; i++) {
				view.setUint8(offset + i, value.charCodeAt(i));
			}
		}

		ascii(0, 'RIFF');
		view.setUint32(4, 36 + samples.length * 2, true);
		ascii(8, 'WAVE');
		ascii(12, 'fmt ');
		view.setUint32(16, 16, true);        // PCM chunk size
		view.setUint16(20, 1, true);         // format: PCM
		view.setUint16(22, 1, true);         // channels: mono
		view.setUint32(24, rate, true);
		view.setUint32(28, rate * 2, true);  // byte rate
		view.setUint16(32, 2, true);         // block align
		view.setUint16(34, 16, true);        // bits per sample
		ascii(36, 'data');
		view.setUint32(40, samples.length * 2, true);

		var at = 44;

		for (var i = 0; i < samples.length; i++) {
			var s = samples[i];

			s = s > 1 ? 1 : (s < -1 ? -1 : s);
			view.setInt16(at, s < 0 ? s * 0x8000 : s * 0x7fff, true);
			at += 2;
		}

		return new Blob([view], { type: 'audio/wav' });
	}

	/* ------------------------------------------------------------------ */
	/* Capture graph                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * An AudioWorklet that forwards every input block to the main thread.
	 * Delivered as a blob so the module stays a single file.
	 */
	var WORKLET_SOURCE = [
		'class OrykTap extends AudioWorkletProcessor {',
		'  process (inputs) {',
		'    const input = inputs[0];',
		'    if (input && input[0]) {',
		'      this.port.postMessage(new Float32Array(input[0]));',
		'    }',
		'    return true;',
		'  }',
		'}',
		'registerProcessor("oryk-tap", OrykTap);'
	].join('\n');

	function makeContext() {
		var Ctx = window.AudioContext || window.webkitAudioContext;

		if (!Ctx) {
			throw new Error('This browser has no Web Audio support.');
		}

		if (!ctx || ctx.state === 'closed') {
			ctx = new Ctx();
		}

		return ctx.state === 'suspended' ? ctx.resume().then(function () { return ctx; }) : Promise.resolve(ctx);
	}

	function collect(block) {
		if (!recording || paused || !block || !block.length) {
			return;
		}

		chunks.push(block);
		frames += block.length;

		if (frames / ctx.sampleRate >= MAX_SECONDS) {
			stop();
		}
	}

	function buildCapture() {
		// AudioWorklet runs on the audio thread and does not glitch under load,
		// which ScriptProcessor very much does. Fall back only if it is absent.
		if (ctx.audioWorklet && window.Blob && window.URL && window.URL.createObjectURL) {
			var url = URL.createObjectURL(new Blob([WORKLET_SOURCE], { type: 'application/javascript' }));

			return ctx.audioWorklet.addModule(url).then(function () {
				URL.revokeObjectURL(url);

				var node = new AudioWorkletNode(ctx, 'oryk-tap', {
					numberOfInputs: 1,
					numberOfOutputs: 1,
					outputChannelCount: [1]
				});

				node.port.onmessage = function (event) {
					collect(event.data);
				};

				return node;
			});
		}

		var legacy = ctx.createScriptProcessor(4096, 1, 1);

		legacy.onaudioprocess = function (event) {
			collect(new Float32Array(event.inputBuffer.getChannelData(0)));
		};

		return Promise.resolve(legacy);
	}

	function openMic() {
		if (stream && stream.active) {
			return Promise.resolve();
		}

		var deviceId = el.device && el.device.value;
		var audio = {
			channelCount: 1,
			echoCancellation: true,
			noiseSuppression: true,
			autoGainControl: true
		};

		if (deviceId) {
			audio.deviceId = { exact: deviceId };
		}

		return navigator.mediaDevices.getUserMedia({ audio: audio, video: false })
			.then(function (media) {
				stream = media;

				return makeContext();
			})
			.then(function () {
				source = ctx.createMediaStreamSource(stream);
				analyser = ctx.createAnalyser();
				analyser.fftSize = 2048;
				analyser.smoothingTimeConstant = 0.6;
				source.connect(analyser);

				return buildCapture();
			})
			.then(function (node) {
				capture = node;
				source.connect(capture);

				// ScriptProcessor only fires while something pulls it. Route
				// through a silent gain so nothing is heard back.
				sink = ctx.createGain();
				sink.gain.value = 0;
				capture.connect(sink);
				sink.connect(ctx.destination);

				return listDevices();
			});
	}

	function closeMic() {
		try {
			if (capture) {
				capture.disconnect();
				if (capture.port) {
					capture.port.onmessage = null;
				}
				capture.onaudioprocess = null;
			}
			if (sink) {
				sink.disconnect();
			}
			if (source) {
				source.disconnect();
			}
			if (stream) {
				stream.getTracks().forEach(function (track) {
					track.stop();
				});
			}
		} catch (e) {
			/* teardown is best effort */
		}

		capture = sink = source = analyser = stream = null;
	}

	/* ------------------------------------------------------------------ */
	/* Meter and scope                                                     */
	/* ------------------------------------------------------------------ */

	function drawFrame() {
		if (!analyser || !el.scope) {
			return;
		}

		var samples = new Uint8Array(analyser.fftSize);
		analyser.getByteTimeDomainData(samples);

		var peak = 0;
		var sum = 0;

		for (var i = 0; i < samples.length; i++) {
			var v = (samples[i] - 128) / 128;

			sum += v * v;
			peak = Math.max(peak, Math.abs(v));
		}

		var rms = Math.sqrt(sum / samples.length);

		if (el.meter) {
			el.meter.style.width = Math.min(100, Math.round(rms * 240)) + '%';
			el.meter.classList.toggle('is-hot', peak > 0.97);
		}

		var canvas = el.scope;
		var width = canvas.clientWidth || 600;

		if (canvas.width !== width) {
			canvas.width = width;
		}

		var g = canvas.getContext('2d');
		var height = canvas.height;

		g.clearRect(0, 0, width, height);
		g.lineWidth = 1.5;
		g.strokeStyle = recording && !paused ? '#e81f64' : '#9aa4b1';
		g.beginPath();

		var step = width / samples.length;

		for (var j = 0; j < samples.length; j++) {
			var y = (samples[j] / 128) * (height / 2);

			if (j === 0) {
				g.moveTo(0, y);
			} else {
				g.lineTo(j * step, y);
			}
		}

		g.stroke();
	}

	var scopeLoop = makeLoop(
		function (fn) { return window.requestAnimationFrame(fn); },
		function (id) { window.cancelAnimationFrame(id); },
		drawFrame
	);

	function stopDrawing() {
		scopeLoop.stop();

		if (el.meter) {
			el.meter.style.width = '0%';
			el.meter.classList.remove('is-hot');
		}
	}

	function paintTimer() {
		var value = clock(elapsedBefore + (recording && !paused ? (Date.now() - startedAt) / 1000 : 0));

		if (value !== lastClock) {
			lastClock = value;
			text(el.timer, value);
		}
	}

	var timerLoop = makeLoop(
		function (fn) { return window.setTimeout(fn, TICK_MS); },
		function (id) { window.clearTimeout(id); },
		function () {
			if (!recording || paused) {
				return false;
			}

			paintTimer();
		}
	);

	function resetTimer() {
		timerLoop.stop();
		elapsedBefore = 0;
		lastClock = ZERO;
		text(el.timer, ZERO);
	}

	/* ------------------------------------------------------------------ */
	/* Recording lifecycle                                                 */
	/* ------------------------------------------------------------------ */

	function start() {
		if (recording || opening) {
			return;
		}

		opening = true;
		clearError();
		discardTake();

		openMic().then(function () {
			opening = false;
			chunks = [];
			frames = 0;
			paused = false;
			recording = true;
			elapsedBefore = 0;
			startedAt = Date.now();

			el.record.classList.remove('btn-danger');
			el.record.classList.add('btn-default');
			setButton(el.record, 'Stop', 'stop');
			show(el.pause, true);

			scopeLoop.start();
			lastClock = '';
			timerLoop.start();
		}).catch(function (error) {
			opening = false;
			recording = false;
			paused = false;
			timerLoop.stop();
			stopDrawing();
			fail(micMessage(error));
		});
	}

	function micMessage(error) {
		var name = error && error.name;

		if (name === 'NotAllowedError' || name === 'SecurityError') {
			return 'Microphone access was blocked. Allow it for this site and try again.';
		}
		if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
			return 'No microphone was found.';
		}
		if (name === 'NotReadableError') {
			return 'The microphone is in use by another application.';
		}

		return (error && error.message) || 'Could not open the microphone.';
	}

	/**
	 * Encode whatever is in `chunks` at the selected rate. Non-destructive:
	 * flatten() copies, so a paused take can be auditioned and then resumed.
	 */
	function buildWav() {
		var rate = parseInt(el.rate.value, 10) || 8000;

		return resample(flatten(chunks, frames), ctx.sampleRate, rate).then(function (samples) {
			var blob = encodeWav(samples, rate);

			return {
				blob: blob,
				url: URL.createObjectURL(blob),
				seconds: samples.length / rate,
				rate: rate
			};
		});
	}

	function showPreview(url, label, saveable) {
		releasePreview();
		previewUrl = url;

		el.preview.src = url;
		text(el.state, label);
		show(el.saveForm, saveable);
		show(el.review, true);
	}

	function releasePreview() {
		if (previewUrl) {
			URL.revokeObjectURL(previewUrl);
			previewUrl = null;
		}
	}

	function clearPreview() {
		if (!el.preview.paused) {
			el.preview.pause();
		}

		el.preview.removeAttribute('src');
		releasePreview();
		show(el.review, false);
	}

	function stop() {
		if (!recording) {
			return;
		}

		if (!paused) {
			elapsedBefore += (Date.now() - startedAt) / 1000;
		}

		recording = false;
		paused = false;

		el.record.classList.add('btn-danger');
		el.record.classList.remove('btn-default');
		setButton(el.record, 'Record', 'circle');
		show(el.pause, false);
		setButton(el.pause, 'Pause', 'pause');

		stopDrawing();
		closeMic();
		clearPreview();
		resetTimer();

		el.state.className = 'oryk-state';
		text(el.state, 'Encoding');

		if (!frames) {
			fail('Nothing was captured.');
			text(el.state, 'Ready');
			return;
		}

		buildWav().then(function (take) {
			chunks = [];
			takeBlob = take.blob;

			showPreview(take.url, clock(take.seconds) + ' · ' + bytes(take.blob.size) +
				' · ' + (take.rate / 1000) + ' kHz', true);
			el.name.focus();
		}).catch(function (error) {
			fail(error.message || 'Could not encode the recording.');
			text(el.state, 'Ready');
		});
	}

	/**
	 * Paused playback: audition the take so far, then resume into the same one.
	 * The encode is async, so Stop or Resume can land mid-flight — check we are
	 * still paused before showing it, or it would overwrite the finished take.
	 */
	function monitor() {
		if (monitoring || !frames) {
			return;
		}

		monitoring = true;
		text(el.state, 'Encoding');

		buildWav().then(function (take) {
			monitoring = false;

			if (!recording || !paused) {
				URL.revokeObjectURL(take.url);
				return;
			}

			showPreview(take.url, clock(take.seconds) + ' so far · paused', false);
		}).catch(function (error) {
			monitoring = false;

			if (recording && paused) {
				fail(error.message || 'Could not build the preview.');
			}
		});
	}

	function togglePause() {
		if (!recording) {
			return;
		}

		if (paused) {
			paused = false;
			startedAt = Date.now();
			setButton(el.pause, 'Pause', 'pause');
			clearPreview();
			timerLoop.start();
		} else {
			paused = true;
			elapsedBefore += (Date.now() - startedAt) / 1000;
			setButton(el.pause, 'Resume', 'play');
			timerLoop.stop();
			paintTimer();
			monitor();
		}
	}

	function discardTake() {
		takeBlob = null;
		clearPreview();
		show(el.saveForm, true);
		text(el.saveState, '');
		resetTimer();
		text(el.state, 'Ready');
	}

	/* ------------------------------------------------------------------ */
	/* Devices                                                             */
	/* ------------------------------------------------------------------ */

	function listDevices() {
		if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
			return Promise.resolve();
		}

		return navigator.mediaDevices.enumerateDevices().then(function (devices) {
			var chosen = el.device.value;

			el.device.innerHTML = '<option value="">Default input</option>';

			devices.filter(function (d) {
				return d.kind === 'audioinput';
			}).forEach(function (d, i) {
				var option = document.createElement('option');

				option.value = d.deviceId;
				// Labels are blank until the user has granted access once.
				option.textContent = d.label || ('Microphone ' + (i + 1));
				el.device.appendChild(option);
			});

			el.device.value = chosen;
		}).catch(function () {
			/* enumeration is a nicety */
		});
	}

	/* ------------------------------------------------------------------ */
	/* Server                                                              */
	/* ------------------------------------------------------------------ */

	function post(data) {
		return new Promise(function (resolve, reject) {
			var xhr = new XMLHttpRequest();

			xhr.open('POST', AJAX, true);
			xhr.responseType = 'json';

			xhr.onload = function () {
				var body = xhr.response;

				if (typeof body === 'string') {
					try {
						body = JSON.parse(body);
					} catch (e) {
						body = null;
					}
				}

				if (!body) {
					reject(new Error('The server sent back something unreadable.'));
					return;
				}

				resolve(body);
			};

			xhr.onerror = function () {
				reject(new Error('Could not reach the server.'));
			};

			xhr.send(data);
		});
	}

	function save(overwrite) {
		var name = (el.name.value || '').trim();

		if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/.test(name)) {
			text(el.saveState, 'Name must be letters, numbers, dot, dash or underscore.');
			el.saveState.className = 'oryk-save-state is-bad';
			return;
		}

		if (!takeBlob) {
			return;
		}

		if (takeBlob.size > CFG.maxBytes) {
			text(el.saveState, 'Recording is too large to upload.');
			el.saveState.className = 'oryk-save-state is-bad';
			return;
		}

		var form = new FormData();

		form.append('module', MODULE);
		form.append('command', 'save');
		form.append('name', name);
		form.append('overwrite', overwrite ? 'true' : 'false');
		form.append('audio', takeBlob, name + '.wav');

		el.save.disabled = true;
		text(el.saveState, 'Saving…');
		el.saveState.className = 'oryk-save-state';

		post(form).then(function (res) {
			el.save.disabled = false;

			if (res.status) {
				text(el.saveState, res.message || 'Saved');
				el.saveState.className = 'oryk-save-state is-good';
				render(res.recordings || []);
				toast(res.message || 'Saved', 'success');
				discardTake();
				return;
			}

			if (res.exists) {
				el.saveState.className = 'oryk-save-state is-bad';
				el.saveState.innerHTML = escapeHtml(res.message) +
					' <button type="button" class="btn btn-xs btn-warning" data-oryk-overwrite="1">Overwrite</button>';
				return;
			}

			text(el.saveState, res.message || 'Could not save.');
			el.saveState.className = 'oryk-save-state is-bad';
		}).catch(function (error) {
			el.save.disabled = false;
			text(el.saveState, error.message);
			el.saveState.className = 'oryk-save-state is-bad';
		});
	}

	function remove(name, force) {
		var form = new FormData();

		form.append('module', MODULE);
		form.append('command', 'delete');
		form.append('name', name);
		form.append('force', force ? 'true' : 'false');

		post(form).then(function (res) {
			if (res.status) {
				render(res.recordings || []);
				toast(res.message || 'Deleted', 'success');
				return;
			}

			var row = el.list.querySelector('[data-oryk-row="' + name + '"] .oryk-row-note');

			if (row) {
				row.innerHTML = escapeHtml(res.message || 'Could not delete.') +
					(res.inUse ? ' <button type="button" class="btn btn-xs btn-danger" data-oryk-force="' + escapeHtml(name) + '">Delete anyway</button>' : '');
			}

			toast(res.message || 'Could not delete', 'error');
		}).catch(function (error) {
			toast(error.message, 'error');
		});
	}

	/* ------------------------------------------------------------------ */
	/* Saved list                                                          */
	/* ------------------------------------------------------------------ */

	function render(recordings) {
		if (!el.list) {
			return;
		}

		if (!recordings.length) {
			el.list.innerHTML = '<tr><td colspan="5" class="text-muted">No recordings yet.</td></tr>';
			return;
		}

		el.list.innerHTML = recordings.map(function (r) {
			var playUrl = AJAX + '?module=' + MODULE + '&command=play&name=' + encodeURIComponent(r.name);

			return '<tr data-oryk-row="' + escapeHtml(r.name) + '">' +
				'<td><code>custom/' + escapeHtml(r.name) + '</code>' +
				'<div class="oryk-row-note"></div></td>' +
				'<td>' + escapeHtml(r.formats.join(', ')) + '</td>' +
				'<td>' + bytes(r.bytes) + '</td>' +
				'<td>' + escapeHtml(when(r.modified)) + '</td>' +
				'<td class="text-right oryk-row-actions">' +
				(r.playable ? '<audio controls preload="none" src="' + playUrl + '"></audio>' : '<span class="text-muted">not previewable</span>') +
				' <button type="button" class="btn btn-xs btn-default" data-oryk-delete="' + escapeHtml(r.name) + '">' +
				'<i class="fa fa-trash"></i></button>' +
				'</td></tr>';
		}).join('');
	}

	/* ------------------------------------------------------------------ */
	/* Wiring                                                              */
	/* ------------------------------------------------------------------ */

	var booted = false;

	function init() {
		if (booted) {
			return;
		}

		// FreePBX can swap a module page in without a full document load, which
		// re-executes this file. That gives a second copy of the script its own
		// closure — its own `recording`, `startedAt`, its own loops — bound to the
		// same buttons, so one click drives two recorders writing different
		// times into the same element. A closure-local flag cannot see the
		// other copy, so the claim is staked on the node itself.
		var anchor = $('orykMediaRecord');

		if (!anchor || anchor.getAttribute('data-oryk-bound') === '1') {
			return;
		}

		anchor.setAttribute('data-oryk-bound', '1');
		booted = true;

		el = {
			insecure: $('orykMediaInsecure'),
			error: $('orykMediaError'),
			device: $('orykMediaDevice'),
			rate: $('orykMediaRate'),
			record: $('orykMediaRecord'),
			pause: $('orykMediaPause'),
			timer: $('orykMediaTimer'),
			state: $('orykMediaState'),
			meter: $('orykMediaMeterFill'),
			scope: $('orykMediaScope'),
			review: $('orykMediaReview'),
			saveForm: $('orykMediaSaveForm'),
			preview: $('orykMediaPreview'),
			name: $('orykMediaName'),
			save: $('orykMediaSave'),
			download: $('orykMediaDownload'),
			discard: $('orykMediaDiscard'),
			saveState: $('orykMediaSaveState'),
			list: $('orykMediaList')
		};

		if (!el.record) {
			return;
		}

		render(CFG.recordings || []);

		var supported = window.isSecureContext !== false &&
			navigator.mediaDevices &&
			navigator.mediaDevices.getUserMedia;

		if (!supported) {
			show(el.insecure, true);
			el.record.disabled = true;
			return;
		}

		if (!CFG.writable) {
			el.record.disabled = false; // recording still works; saving will not
		}

		listDevices();

		el.record.addEventListener('click', function () {
			if (recording) {
				stop();
			} else {
				start();
			}
		});

		el.pause.addEventListener('click', togglePause);
		el.discard.addEventListener('click', discardTake);
		el.save.addEventListener('click', function () {
			save(false);
		});

		el.download.addEventListener('click', function () {
			if (!takeBlob || !previewUrl) {
				return;
			}

			var name = (el.name.value || 'recording').trim() || 'recording';
			var a = document.createElement('a');

			a.href = previewUrl;
			a.download = name + '.wav';
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
		});

		// Switching input device mid-session means reopening the mic.
		el.device.addEventListener('change', function () {
			if (!recording) {
				closeMic();
			}
		});

		el.saveState.addEventListener('click', function (event) {
			if (event.target.getAttribute('data-oryk-overwrite')) {
				save(true);
			}
		});

		el.list.addEventListener('click', function (event) {
			var button = event.target.closest('[data-oryk-delete], [data-oryk-force]');

			if (!button) {
				return;
			}

			var force = button.getAttribute('data-oryk-force');

			if (force) {
				remove(force, true);
				return;
			}

			var name = button.getAttribute('data-oryk-delete');

			// Two-step confirm in place of a modal: the second click commits.
			if (button.getAttribute('data-oryk-armed')) {
				remove(name, false);
				return;
			}

			button.setAttribute('data-oryk-armed', '1');
			button.className = 'btn btn-xs btn-danger';
			button.innerHTML = 'Really delete?';

			window.setTimeout(function () {
				if (button.parentNode) {
					button.removeAttribute('data-oryk-armed');
					button.className = 'btn btn-xs btn-default';
					button.innerHTML = '<i class="fa fa-trash"></i>';
				}
			}, 4000);
		});

		window.addEventListener('beforeunload', function (event) {
			if (recording || takeBlob) {
				event.preventDefault();
				event.returnValue = '';
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
