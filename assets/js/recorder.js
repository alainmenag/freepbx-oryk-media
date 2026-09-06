/*
 * recorder.js -- browser microphone capture, the first half of views/edit.php.
 *
 * All encoding happens here. The mic is captured as Float32 at whatever rate
 * the audio hardware runs at, resampled by an OfflineAudioContext to a
 * telephony rate, and written out as 16-bit mono PCM WAV -- a format Asterisk
 * plays natively, so the server side never has to shell out to sox or ffmpeg.
 *
 * getUserMedia only exists on a secure origin. On a plain http:// admin page
 * there is no microphone to be had and no flag we can set from here; the page
 * says so rather than failing quietly.
 *
 * What becomes of a finished take -- the player, the name, Save, Download --
 * is not this file's business: it hands the encoded WAV to editor.js and
 * stops. Text to speech hands over exactly the same way, from the other tab.
 */
(function (window, document) {
	'use strict';

	var CFG = (window.OrykMedia && window.OrykMedia.config) || {};
	var S = window.OrykMedia && window.OrykMedia.shared;
	var E = window.OrykMedia && window.OrykMedia.editor;

	var MAX_SECONDS = 20 * 60;
	var TICK_MS = 50;        // timer repaint interval; the clock shows hundredths
	var ZERO = '00:00:00:00';

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

	/** Size the canvas to its box and hand back a cleared 2d context. */
	function scopeContext() {
		var canvas = el.scope;
		var width = canvas.clientWidth || 600;

		if (canvas.width !== width) {
			canvas.width = width;
		}

		var g = canvas.getContext('2d');

		g.clearRect(0, 0, width, canvas.height);

		return g;
	}

	/** Flat line plus a prompt, shown whenever the input is not live. */
	function drawIdle() {
		if (!el.scope) {
			return;
		}

		var g = scopeContext();
		var width = el.scope.width;
		var mid = el.scope.height / 2;

		g.lineWidth = 1.5;
		g.strokeStyle = '#dce1e7';
		g.beginPath();
		g.moveTo(0, mid);
		g.lineTo(width, mid);
		g.stroke();
	}

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

		var g = scopeContext();
		var width = el.scope.width;
		var height = el.scope.height;

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

		drawIdle();
	}

	function live() {
		return !!(stream && stream.active);
	}

	/**
	 * Run the scope on an open mic, without recording. Idempotent, so every
	 * trigger can just call it. Record then reuses the mic — openMic()
	 * short-circuits on an active stream.
	 *
	 * Failures stay quiet: this runs unprompted, and the only reason it fails
	 * is a mic that is not available, which Record reports properly when the
	 * user actually asks for one.
	 */
	function armScope() {
		if (recording || opening || live()) {
			return;
		}

		opening = true;

		openMic().then(function () {
			opening = false;

			// Permission is not a gesture. Autoplay policy can leave the context
			// suspended, which would draw a live-looking but silent flat line —
			// back off and leave the prompt up, since a click resumes it.
			if (!ctx || ctx.state !== 'running') {
				closeMic();
				drawIdle();
				return;
			}

			scopeLoop.start();
		}).catch(function () {
			opening = false;
			closeMic();
			drawIdle();
		});
	}

	/**
	 * getUserMedia needs permission, not a gesture — so once the mic is granted
	 * the scope can run on its own. Watch the grant and arm on it; browsers
	 * without the permissions API fall back to the click.
	 */
	function armWhenPermitted() {
		if (!navigator.permissions || !navigator.permissions.query) {
			return;
		}

		navigator.permissions.query({ name: 'microphone' }).then(function (status) {
			if (status.state === 'granted') {
				armScope();
			}

			status.onchange = function () {
				if (status.state === 'granted') {
					armScope();
				}
			};
		}).catch(function () {
			/* 'microphone' is not queryable here; the click path still arms */
		});
	}

	function paintTimer() {
		var value = S.clock(elapsedBefore + (recording && !paused ? (Date.now() - startedAt) / 1000 : 0));

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
		E.clearTake();

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
		// The output rate belongs to the editor, not to this panel: text to
		// speech encodes to the same one, and a take carries the rate it was
		// actually made at.
		var rate = E.rate();

		return resample(flatten(chunks, frames), ctx.sampleRate, rate).then(function (samples) {
			return {
				blob: encodeWav(samples, rate),
				seconds: samples.length / rate,
				rate: rate
			};
		});
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
		E.clearTake();
		resetTimer();

		if (!frames) {
			fail('Nothing was captured.');
			return;
		}

		E.status('Encoding…');

		buildWav().then(function (take) {
			chunks = [];

			// Done with it: the editor owns the take from here, and this panel
			// keeps nothing but the microphone.
			E.setTake(take.blob, take, true);
		}).catch(function (error) {
			E.status('');
			fail(error.message || 'Could not encode the recording.');
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
		E.status('Encoding…');

		buildWav().then(function (take) {
			monitoring = false;

			if (!recording || !paused) {
				return;
			}

			// Not saveable: this is the take so far, and it is about to carry
			// on. The editor plays it and refuses to file it.
			E.setTake(take.blob, take, false);
		}).catch(function (error) {
			monitoring = false;
			E.status('');

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
			E.clearTake();
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
	/* Wiring                                                              */
	/* ------------------------------------------------------------------ */

	var booted = false;

	function init() {
		if (booted || !S || !E) {
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
			record: $('orykMediaRecord'),
			pause: $('orykMediaPause'),
			timer: $('orykMediaTimer'),
			meter: $('orykMediaMeterFill'),
			scope: $('orykMediaScope')
		};

		// Discarding the current take is the editor's button, but the clock
		// belongs to this panel, so it is reset from here.
		E.onDiscard(resetTimer);

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
		drawIdle();

		el.scope.addEventListener('click', armScope);

		armWhenPermitted();

		el.record.addEventListener('click', function () {
			if (recording) {
				stop();
			} else {
				start();
			}
		});

		el.pause.addEventListener('click', togglePause);

		// Switching input device mid-session means reopening the mic.
		el.device.addEventListener('change', function () {
			if (recording) {
				return;
			}

			stopDrawing();
			closeMic();
			armScope();       // follow the selection instead of holding the old mic
		});

	}

	/* ------------------------------------------------------------------ */
	/* What the rest of the editor needs from this panel                   */
	/* ------------------------------------------------------------------ */

	/*
	 * redraw() is for the tab switch: the scope canvas sizes itself from its
	 * box, and a box inside a hidden panel has no width.
	 *
	 * isRecording() is for the unload warning: a take still being recorded is
	 * worth warning about even though nothing has been encoded yet.
	 */
	window.OrykMedia = window.OrykMedia || {};
	window.OrykMedia.recorder = {
		isRecording: function () {
			return recording;
		},
		redraw: function () {
			if (el.scope) {
				drawIdle();
			}
		}
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
